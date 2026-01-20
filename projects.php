<?php
require_once 'config/config.php';
requireProjectManager();
requireActiveSubscription();

$page_title = 'Project Management';

$conn = getDBConnection();
$message = '';
$error = '';

// Handle project creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_project'])) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $status = $_POST['status'];
    $project_manager_id = !empty($_POST['project_manager_id']) ? intval($_POST['project_manager_id']) : null;
    $created_by = $_SESSION['user_id'];
    $organization_id = getOrganizationId();
    
    if (empty($name)) {
        $error = 'Project name is required';
    } elseif (!$organization_id && !isSuperAdmin()) {
        $error = 'Organization not found';
    } else {
        // Handle NULL project_manager_id properly in mysqli
        if ($project_manager_id === null) {
            $stmt = $conn->prepare("INSERT INTO projects (name, description, status, organization_id, project_manager_id, created_by) VALUES (?, ?, ?, ?, NULL, ?)");
            $stmt->bind_param("sssii", $name, $description, $status, $organization_id, $created_by);
        } else {
            $stmt = $conn->prepare("INSERT INTO projects (name, description, status, organization_id, project_manager_id, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssiii", $name, $description, $status, $organization_id, $project_manager_id, $created_by);
        }
        
        if ($stmt->execute()) {
            $project_id = $conn->insert_id;
            
            // Add team members if selected
            $added_members = [];
            if (!empty($_POST['team_members']) && is_array($_POST['team_members'])) {
                foreach ($_POST['team_members'] as $user_id) {
                    $user_id = intval($user_id);
                    $added_members[] = $user_id;
                    $stmt2 = $conn->prepare("INSERT INTO project_users (project_id, user_id) VALUES (?, ?)");
                    $stmt2->bind_param("ii", $project_id, $user_id);
                    $stmt2->execute();
                }
            }
            
            // Automatically add creator to project_users if they're not the PM and not already added
            if ($project_manager_id != $created_by && !in_array($created_by, $added_members)) {
                $stmt3 = $conn->prepare("INSERT INTO project_users (project_id, user_id) VALUES (?, ?)");
                $stmt3->bind_param("ii", $project_id, $created_by);
                $stmt3->execute();
            }
            
            $message = 'Project created successfully';
            // Redirect to show success message
            header('Location: projects?created=1');
            exit();
        } else {
            $error = 'Error creating project: ' . $conn->error;
        }
    }
}

// Handle project update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_project'])) {
    $project_id = intval($_POST['project_id']);
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $status = $_POST['status'];
    $project_manager_id = !empty($_POST['project_manager_id']) ? intval($_POST['project_manager_id']) : null;
    
    // Handle NULL project_manager_id properly in mysqli
    if ($project_manager_id === null) {
        $stmt = $conn->prepare("UPDATE projects SET name=?, description=?, status=?, project_manager_id=NULL WHERE id=?");
        $stmt->bind_param("sssi", $name, $description, $status, $project_id);
    } else {
        $stmt = $conn->prepare("UPDATE projects SET name=?, description=?, status=?, project_manager_id=? WHERE id=?");
        $stmt->bind_param("sssii", $name, $description, $status, $project_manager_id, $project_id);
    }
    
    if ($stmt->execute()) {
        // Update team members
        $conn->query("DELETE FROM project_users WHERE project_id = $project_id");
        if (!empty($_POST['team_members']) && is_array($_POST['team_members'])) {
            foreach ($_POST['team_members'] as $user_id) {
                $user_id = intval($user_id);
                $stmt2 = $conn->prepare("INSERT INTO project_users (project_id, user_id) VALUES (?, ?)");
                $stmt2->bind_param("ii", $project_id, $user_id);
                $stmt2->execute();
            }
        }
        $message = 'Project updated successfully';
        // Redirect to close modal and show success message
        header('Location: projects?updated=1');
        exit();
    } else {
        $error = 'Error updating project';
    }
}

// Handle project deletion
if (isset($_GET['delete'])) {
    $project_id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
    if ($stmt->execute()) {
        $message = 'Project deleted successfully';
    } else {
        $error = 'Error deleting project';
    }
}

// Get projects
if (isSuperAdmin()) {
    $projects = $conn->query("
        SELECT p.*, u.full_name as pm_name, u2.full_name as creator_name, o.name as org_name,
               (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) as task_count
        FROM projects p
        LEFT JOIN users u ON p.project_manager_id = u.id
        LEFT JOIN users u2 ON p.created_by = u2.id
        LEFT JOIN organizations o ON p.organization_id = o.id
        ORDER BY p.created_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
} else if (isOrgAdmin()) {
    // Organization Admin sees all projects in their organization
    $org_id = getOrganizationId();
    $stmt = $conn->prepare("
        SELECT p.*, u.full_name as pm_name, u2.full_name as creator_name,
               (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) as task_count
        FROM projects p
        LEFT JOIN users u ON p.project_manager_id = u.id
        LEFT JOIN users u2 ON p.created_by = u2.id
        WHERE p.organization_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $projects = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    // PM can only see assigned projects
    $user_id = intval($_SESSION['user_id']);
    $stmt = $conn->prepare("
        SELECT DISTINCT p.*, u.full_name as pm_name, u2.full_name as creator_name,
               (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) as task_count
        FROM projects p
        LEFT JOIN users u ON p.project_manager_id = u.id
        LEFT JOIN users u2 ON p.created_by = u2.id
        LEFT JOIN project_users pu ON p.id = pu.project_id
        WHERE p.project_manager_id = ? OR p.created_by = ? OR pu.user_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->bind_param("iii", $user_id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $projects = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get users for dropdowns (filtered by organization, excluding deleted users)
if (isSuperAdmin()) {
    $all_users = $conn->query("SELECT * FROM users WHERE status = 'active' AND deleted = 0 ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
    $pm_users = $conn->query("SELECT * FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name IN ('Admin', 'Project Manager', 'Super Admin') AND u.status = 'active' AND u.deleted = 0 ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
} else {
    $org_id = getOrganizationId();
    $stmt = $conn->prepare("SELECT * FROM users WHERE organization_id = ? AND status = 'active' AND deleted = 0 ORDER BY full_name");
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $all_users = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT u.* FROM users u JOIN roles r ON u.role_id = r.id WHERE u.organization_id = ? AND r.name IN ('Admin', 'Project Manager') AND u.status = 'active' AND u.deleted = 0 ORDER BY u.full_name");
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $pm_users = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get project for editing
$edit_project = null;
$edit_team_members = [];
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $result = $conn->query("SELECT * FROM projects WHERE id = $edit_id");
    $edit_project = $result->fetch_assoc();
    
    if ($edit_project) {
        $team_result = $conn->query("SELECT user_id FROM project_users WHERE project_id = $edit_id");
        while ($row = $team_result->fetch_assoc()) {
            $edit_team_members[] = $row['user_id'];
        }
    }
}

$conn->close();

// Check for success messages from redirect
if (isset($_GET['created'])) {
    $message = 'Project created successfully';
}
if (isset($_GET['updated'])) {
    $message = 'Project updated successfully';
}

include 'includes/header.php';
?>

<div style="width: 100%; padding: 20px;">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
        <div>
            <h1 class="text-3xl font-semibold text-gray-900" style="font-size: 28px; font-weight: 600; color: #1e293b; margin: 0;">Projects</h1>
            <p class="text-gray-500 mt-1" style="color: #64748b; margin-top: 4px; font-size: 14px;">Manage and track all your projects</p>
        </div>
        <button class="btn btn-primary modal-trigger" data-modal="projectModal" title="Add New Project" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%); color: white; border: none; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 4px rgba(20, 184, 166, 0.3);">
            <i class="fas fa-plus"></i>
            <span>Add Project</span>
        </button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success" style="background: #d1fae5; border: 1px solid #10b981; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error" style="background: #fee2e2; border: 1px solid #ef4444; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Projects Table -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Project Manager</th>
                    <th style="text-align: center;">Tasks</th>
                    <th>Created</th>
                    <th style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($projects)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px 20px; color: #94a3b8;">
                            <i class="fas fa-folder-open" style="font-size: 48px; margin-bottom: 12px; opacity: 0.3; display: block;"></i>
                            <p style="font-size: 16px; margin: 0;">No projects found</p>
                            <p style="font-size: 14px; margin-top: 8px; color: #cbd5e1;">Create your first project to get started</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($projects as $project): ?>
                        <tr>
                            <td>
                                <strong style="color: #1e293b; font-size: 15px;"><?php echo htmlspecialchars($project['name']); ?></strong>
                            </td>
                            <td style="color: #64748b; max-width: 300px;">
                                <?php 
                                $desc = htmlspecialchars($project['description'] ?? '');
                                if (!empty($desc)) {
                                    echo strlen($desc) > 60 ? substr($desc, 0, 60) . '...' : $desc;
                                } else {
                                    echo '<span style="color: #cbd5e1; font-style: italic;">No description</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php 
                                $status = htmlspecialchars($project['status']);
                                $status_class = strtolower(str_replace(' ', '-', $status));
                                $status_colors = [
                                    'active' => ['bg' => '#d1fae5', 'text' => '#065f46', 'border' => '#10b981'],
                                    'on-hold' => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#f59e0b'],
                                    'completed' => ['bg' => '#dbeafe', 'text' => '#1e40af', 'border' => '#3b82f6']
                                ];
                                $color = $status_colors[$status_class] ?? ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#9ca3af'];
                                ?>
                                <span class="badge" style="display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 500; background: <?php echo $color['bg']; ?>; color: <?php echo $color['text']; ?>; border: 1px solid <?php echo $color['border']; ?>;">
                                    <?php echo $status; ?>
                                </span>
                            </td>
                            <td style="color: #475569;">
                                <?php 
                                if (!empty($project['pm_name'])) {
                                    echo htmlspecialchars($project['pm_name']);
                                } else {
                                    echo '<span style="color: #cbd5e1; font-style: italic;">Unassigned</span>';
                                }
                                ?>
                            </td>
                            <td style="text-align: center;">
                                <span style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; background: #f0fdfa; color: #14b8a6; font-weight: 600; font-size: 13px; border: 2px solid #ccfbf1;">
                                    <?php echo $project['task_count']; ?>
                                </span>
                            </td>
                            <td style="color: #64748b; font-size: 13px;">
                                <i class="fas fa-calendar-alt" style="margin-right: 6px; color: #94a3b8;"></i>
                                <?php echo formatDate($project['created_at']); ?>
                            </td>
                            <td style="text-align: center;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <a href="tasks?project_id=<?php echo $project['id']; ?>" class="btn btn-sm btn-primary" title="View Tasks" style="padding: 6px 10px; background: #14b8a6; color: white; border: none;  text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="?edit=<?php echo $project['id']; ?>" class="btn btn-sm btn-warning" title="Edit" style="padding: 6px 10px; background: #f59e0b; color: white; border: none;  text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?delete=<?php echo $project['id']; ?>" class="btn btn-sm btn-danger btn-delete" title="Delete" style="padding: 6px 10px; background: #ef4444; color: white; border: none;  text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Project Modal -->
<div id="projectModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><?php echo $edit_project ? 'Edit Project' : 'Add New Project'; ?></h2>
            <span class="close">&times;</span>
        </div>
        <form method="POST" action="">
            <?php if ($edit_project): ?>
                <input type="hidden" name="project_id" value="<?php echo $edit_project['id']; ?>">
                <input type="hidden" name="update_project" value="1">
            <?php else: ?>
                <input type="hidden" name="create_project" value="1">
            <?php endif; ?>
            
            <div class="form-group">
                <label for="name">Project Name *</label>
                <input type="text" id="name" name="name" required 
                       value="<?php echo htmlspecialchars($edit_project['name'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="4"><?php echo htmlspecialchars($edit_project['description'] ?? ''); ?></textarea>
            </div>
            
            <div class="form-group">
                <label for="status">Status *</label>
                <select id="status" name="status" required>
                    <option value="Active" <?php echo ($edit_project && $edit_project['status'] == 'Active') ? 'selected' : ''; ?>>Active</option>
                    <option value="On Hold" <?php echo ($edit_project && $edit_project['status'] == 'On Hold') ? 'selected' : ''; ?>>On Hold</option>
                    <option value="Completed" <?php echo ($edit_project && $edit_project['status'] == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="project_manager_id">Project Manager</label>
                <select id="project_manager_id" name="project_manager_id">
                    <option value="">None</option>
                    <?php foreach ($pm_users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" 
                                <?php echo ($edit_project && $edit_project['project_manager_id'] == $user['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="team_members">Team Members</label>
                <select id="team_members" name="team_members[]" multiple style="height: 150px;">
                    <?php foreach ($all_users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" 
                                <?php echo (in_array($user['id'], $edit_team_members)) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color: #666;">Hold Ctrl/Cmd to select multiple</small>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" class="btn btn-primary" title="<?php echo $edit_project ? 'Update Project' : 'Create Project'; ?>"><i class="fas fa-<?php echo $edit_project ? 'save' : 'plus'; ?>"></i></button>
                <button type="button" class="btn btn-secondary close" title="Cancel"><i class="fas fa-times"></i></button>
            </div>
        </form>
    </div>
</div>

<?php if ($edit_project): ?>
    <script>
        $(document).ready(function() {
            $('#projectModal').show();
        });
    </script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
