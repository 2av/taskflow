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
            header('Location: projects.php?created=1');
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
        header('Location: projects.php?updated=1');
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

// Get users for dropdowns (filtered by organization)
if (isSuperAdmin()) {
    $all_users = $conn->query("SELECT * FROM users WHERE status = 'active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
    $pm_users = $conn->query("SELECT * FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name IN ('Admin', 'Project Manager', 'Super Admin') AND u.status = 'active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
} else {
    $org_id = getOrganizationId();
    $stmt = $conn->prepare("SELECT * FROM users WHERE organization_id = ? AND status = 'active' ORDER BY full_name");
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $all_users = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT u.* FROM users u JOIN roles r ON u.role_id = r.id WHERE u.organization_id = ? AND r.name IN ('Admin', 'Project Manager') AND u.status = 'active' ORDER BY u.full_name");
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

<div class="page-header">
    <h1 class="page-title">Project Management</h1>
    <button class="btn btn-primary modal-trigger" data-modal="projectModal" title="Add New Project"><i class="fas fa-plus"></i></button>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Description</th>
                <th>Status</th>
                <th>Project Manager</th>
                <th>Tasks</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($projects)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; color: #999;">No projects found</td>
                </tr>
            <?php else: ?>
                <?php foreach ($projects as $project): ?>
                    <tr>
                        <td><?php echo $project['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($project['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars(substr($project['description'], 0, 50)) . (strlen($project['description']) > 50 ? '...' : ''); ?></td>
                        <td>
                            <span class="badge badge-info"><?php echo htmlspecialchars($project['status']); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars($project['pm_name'] ?? '-'); ?></td>
                        <td><?php echo $project['task_count']; ?></td>
                        <td><?php echo formatDate($project['created_at']); ?></td>
                        <td>
                            <a href="tasks.php?project_id=<?php echo $project['id']; ?>" class="btn btn-sm btn-primary" title="View Tasks"><i class="fas fa-eye"></i></a>
                            <a href="?edit=<?php echo $project['id']; ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                            <a href="?delete=<?php echo $project['id']; ?>" class="btn btn-sm btn-danger btn-delete" title="Delete"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
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
