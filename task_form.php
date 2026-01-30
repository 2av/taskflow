<?php
require_once 'config/config.php';
requireLogin();
requireActiveSubscription();

$page_title = 'Add New Task';
$conn = getDBConnection();
$message = '';
$error = '';
$edit_task = null;
$task_id = null;

// Get organization-specific statuses
$organization_id = isSuperAdmin() ? null : getOrganizationId();
$statuses = getStatuses($organization_id);

// Check if editing
if (isset($_GET['edit'])) {
    $task_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $edit_task = $result->fetch_assoc();
        $page_title = 'Edit Task';
    } else {
        header('Location: tasks');
        exit();
    }
    $stmt->close();
}

// Get projects list (filtered by user assignment/creation)
if (isSuperAdmin()) {
    $projects = $conn->query("SELECT * FROM projects ORDER BY name")->fetch_all(MYSQLI_ASSOC);
} else if (isOrgAdmin()) {
    // Org Admin sees all projects in their organization
    $org_id = getOrganizationId();
    if ($org_id) {
        $stmt = $conn->prepare("SELECT * FROM projects WHERE organization_id = ? ORDER BY name");
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $projects = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $projects = [];
    }
} else {
    // Project Manager and Team Members: only projects they're assigned to, created, or manage
    $user_id = $_SESSION['user_id'];
    $org_id = getOrganizationId();
    if ($org_id) {
        $projects_query = "
            SELECT DISTINCT p.* 
            FROM projects p
            LEFT JOIN project_users pu ON p.id = pu.project_id
            WHERE (p.project_manager_id = ? OR p.created_by = ? OR pu.user_id = ?)
            AND p.organization_id = ?
            ORDER BY p.name
        ";
        $stmt = $conn->prepare($projects_query);
        $stmt->bind_param("iiii", $user_id, $user_id, $user_id, $org_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $projects = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $projects = [];
    }
}

// Get users list for assignee (excluding deleted users)
if (isSuperAdmin()) {
    $users = $conn->query("SELECT id, full_name FROM users WHERE status = 'active' AND deleted = 0 ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
} else {
    $org_id = getOrganizationId();
    if ($org_id) {
        $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE organization_id = ? AND status = 'active' AND deleted = 0 ORDER BY full_name");
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $users = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $users = [];
    }
}

// Handle task creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_task'])) {
    $project_id = intval($_POST['project_id']);
    $title = trim($_POST['title']);
    $description = trim($_POST['description'] ?? '');
    $type = $_POST['type'];
    $priority = $_POST['priority'];
    $assignee_id = !empty($_POST['assignee_id']) ? intval($_POST['assignee_id']) : null;
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $sprint_id = !empty($_POST['sprint_id']) ? intval($_POST['sprint_id']) : null;
    $created_by = $_SESSION['user_id'];
    
    if (empty($title) || empty($project_id)) {
        $error = 'Title and project are required';
    } else {
        // Verify project belongs to user's organization (unless super admin)
        if (!isSuperAdmin()) {
            $org_id = getOrganizationId();
            $stmt = $conn->prepare("SELECT id FROM projects WHERE id = ? AND organization_id = ?");
            $stmt->bind_param("ii", $project_id, $org_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows == 0) {
                $error = 'Invalid project selected';
                $stmt->close();
            } else {
                $stmt->close();
            }
        }
        
        if (empty($error)) {
            // Generate task ID - include project_id to ensure uniqueness across organizations
            $project_info = $conn->query("SELECT name, organization_id FROM projects WHERE id = $project_id")->fetch_assoc();
            $project_code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $project_info['name']), 0, 3));
            $task_num = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE project_id = $project_id")->fetch_assoc()['count'] + 1;
            // Include project_id in task_id to ensure uniqueness: PROJ-PROJECTID-TASKNUM
            $task_id_str = $project_code . '-' . $project_id . '-' . $task_num;
            
            // Get default status_id (use is_default_task if set, otherwise first status)
            $default_status = null;
            foreach ($statuses as $status) {
                if ($status['is_default_task'] ?? 0) {
                    $default_status = $status;
                    break;
                }
            }
            if (!$default_status && !empty($statuses)) {
                $default_status = $statuses[0];
            }
            $status_id = $default_status ? $default_status['id'] : null;
            $status_name = $default_status ? $default_status['name'] : 'To Do';
            
            $chk_sprint = $conn->query("SHOW COLUMNS FROM tasks LIKE 'sprint_id'");
            if ($chk_sprint && $chk_sprint->num_rows > 0) {
                $stmt = $conn->prepare("INSERT INTO tasks (task_id, project_id, sprint_id, title, description, type, priority, status_id, status, assignee_id, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("siissssissi", $task_id_str, $project_id, $sprint_id, $title, $description, $type, $priority, $status_id, $status_name, $assignee_id, $due_date, $created_by);
            } else {
                $stmt = $conn->prepare("INSERT INTO tasks (task_id, project_id, title, description, type, priority, status_id, status, assignee_id, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sissssissi", $task_id_str, $project_id, $title, $description, $type, $priority, $status_id, $status_name, $assignee_id, $due_date, $created_by);
            }
            
            if ($stmt->execute()) {
                $task_insert_id = $conn->insert_id;
                
                // Log activity
                $action = "Task created";
                $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action) VALUES (?, ?, ?)");
                $stmt2->bind_param("iis", $task_insert_id, $created_by, $action);
                $stmt2->execute();
                
                // Invalidate dashboard cache when task is created
                invalidateDashboardCache();
                
                header('Location: task_view?id=' . $task_insert_id);
                exit();
            } else {
                $error = 'Error creating task: ' . $conn->error;
            }
        }
    }
}

// Handle task update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_task'])) {
    $task_id = intval($_POST['task_id']);
    $title = trim($_POST['title']);
    $description = trim($_POST['description'] ?? '');
    $type = $_POST['type'];
    $priority = $_POST['priority'];
    $status_id = intval($_POST['status_id']); // Now using status_id
    $assignee_id = !empty($_POST['assignee_id']) ? intval($_POST['assignee_id']) : null;
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $sprint_id = isset($_POST['sprint_id']) && $_POST['sprint_id'] !== '' ? intval($_POST['sprint_id']) : null;
    
    // Get status name for backward compatibility
    $status_name_query = $conn->prepare("SELECT name FROM statuses WHERE id = ?");
    $status_name_query->bind_param("i", $status_id);
    $status_name_query->execute();
    $status_name_result = $status_name_query->get_result();
    $status_name = $status_name_result->fetch_assoc()['name'] ?? 'Unknown';
    $status_name_query->close();
    
    // Get old values for logging
    $old_task = $conn->query("SELECT * FROM tasks WHERE id = $task_id")->fetch_assoc();
    
    $chk_sprint = $conn->query("SHOW COLUMNS FROM tasks LIKE 'sprint_id'");
    if ($chk_sprint && $chk_sprint->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE tasks SET title=?, description=?, type=?, priority=?, status_id=?, status=?, assignee_id=?, due_date=?, sprint_id=? WHERE id=?");
        $stmt->bind_param("ssssissii", $title, $description, $type, $priority, $status_id, $status_name, $assignee_id, $due_date, $sprint_id, $task_id);
    } else {
        $stmt = $conn->prepare("UPDATE tasks SET title=?, description=?, type=?, priority=?, status_id=?, status=?, assignee_id=?, due_date=? WHERE id=?");
        $stmt->bind_param("ssssissi", $title, $description, $type, $priority, $status_id, $status_name, $assignee_id, $due_date, $task_id);
    }
    
    if ($stmt->execute()) {
        // Log changes
        $user_id = $_SESSION['user_id'];
        
        if ($old_task['status_id'] != $status_id) {
            $old_status_name = $old_task['status'] ?? 'Unknown';
            $action = "Status changed";
            $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
            $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_status_name, $status_name);
            $stmt2->execute();
        }
        
        if ($old_task['assignee_id'] != $assignee_id) {
            $action = "Assignee changed";
            $old_name = $old_task['assignee_id'] ? $conn->query("SELECT full_name FROM users WHERE id = " . $old_task['assignee_id'])->fetch_assoc()['full_name'] : 'Unassigned';
            $new_name = $assignee_id ? $conn->query("SELECT full_name FROM users WHERE id = $assignee_id")->fetch_assoc()['full_name'] : 'Unassigned';
            $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
            $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_name, $new_name);
            $stmt2->execute();
        }
        
        if ($old_task['priority'] != $priority) {
            $action = "Priority changed";
            $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
            $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_task['priority'], $priority);
            $stmt2->execute();
        }
        
        // Invalidate dashboard cache when task is updated
        invalidateDashboardCache();
        
        header('Location: task_view?id=' . $task_id);
        exit();
    } else {
        $error = 'Error updating task';
    }
}

// Get selected project from session (if coming from dashboard)
$selected_project_id = null;
$is_project_locked = false;
$locked_project_name = '';

if ($edit_task) {
    $selected_project_id = $edit_task['project_id'];
} elseif (isset($_SESSION['selected_project_id']) && !empty($_SESSION['selected_project_id'])) {
    $selected_project_id = intval($_SESSION['selected_project_id']);
    $is_project_locked = true;
    // Get project name
    foreach ($projects as $project) {
        if ($project['id'] == $selected_project_id) {
            $locked_project_name = $project['name'];
            break;
        }
    }
}

// Sprints for selected project (create/edit)
$form_sprints = [];
if ($selected_project_id) {
    $chk_sprint_col = $conn->query("SHOW COLUMNS FROM tasks LIKE 'sprint_id'");
    if ($chk_sprint_col && $chk_sprint_col->num_rows > 0) {
        $stmt_s = $conn->prepare("SELECT id, name FROM sprints WHERE project_id = ? ORDER BY start_date DESC, name");
        if ($stmt_s) {
            $stmt_s->bind_param("i", $selected_project_id);
            $stmt_s->execute();
            $form_sprints = $stmt_s->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_s->close();
        }
    }
}

$conn->close();

include 'includes/header.php';
?>

<style>
.task-form-container {
    width: 100%;
    margin: 0;
    padding: 15px;
}

.task-form-header {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
}

.task-form-content {
    background: #ffffff;
    border-radius: 12px;
    padding: 32px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
    max-width: 900px;
    margin: 0 auto;
}

.form-group {
    margin-bottom: 24px;
}

.form-group label {
    display: block;
    font-weight: 600;
    color: #1e293b;
    font-size: 14px;
    margin-bottom: 8px;
}

.form-group label .required {
    color: #ef4444;
    margin-left: 4px;
}

.form-group input[type="text"],
.form-group input[type="date"],
.form-group select {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 14px;
    background: #ffffff;
    color: #1e293b;
    transition: all 0.2s;
}

.form-group input[type="text"]:focus,
.form-group input[type="date"]:focus,
.form-group select:focus {
    outline: none;
    border-color: #14b8a6;
    box-shadow: 0 0 0 4px rgba(20, 184, 166, 0.1);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 32px;
    padding-top: 24px;
    border-top: 1px solid #e2e8f0;
}

.btn {
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: none;
    text-decoration: none;
}

.btn-primary {
    background: #14b8a6;
    color: white;
}

.btn-primary:hover {
    background: #0d9488;
    box-shadow: 0 4px 12px rgba(20, 184, 166, 0.3);
}

.btn-secondary {
    background: #f1f5f9;
    color: #475569;
}

.btn-secondary:hover {
    background: #e2e8f0;
}

.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 14px;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #6ee7b7;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

/* CKEditor styling */
.ck-editor__editable {
    min-height: 200px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
}
</style>

<div class="task-form-container">
    <!-- Page Header -->
    <div class="task-form-header">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="tasks" class="btn btn-secondary" title="Back to Tasks" style="padding: 10px 12px; min-width: auto;">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h1 class="page-title" style="margin: 0; font-size: 28px; font-weight: 700; color: #1e293b;">
                    <?php echo $edit_task ? 'Edit Task' : 'Add New Task'; ?>
                    <?php if ($is_project_locked && !$edit_task): ?>
                        <span style="font-size: 18px; font-weight: 500; color: #64748b; margin-left: 8px;">
                            (<?php echo htmlspecialchars($locked_project_name); ?>)
                        </span>
                    <?php endif; ?>
                </h1>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Task Form -->
    <div class="task-form-content">
        <form method="POST" action="" id="taskForm">
            <?php if ($edit_task): ?>
                <input type="hidden" name="task_id" value="<?php echo $edit_task['id']; ?>">
                <input type="hidden" name="update_task" value="1">
            <?php else: ?>
                <input type="hidden" name="create_task" value="1">
            <?php endif; ?>
            
            <!-- Project Field -->
            <div class="form-group" <?php echo ($is_project_locked && !$edit_task) ? 'style="display: none;"' : ''; ?>>
                <label for="project_id">
                    Project <span class="required">*</span>
                </label>
                <select id="project_id" name="project_id" required>
                    <option value="">Select Project</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?php echo $project['id']; ?>" 
                                <?php echo ($selected_project_id && $selected_project_id == $project['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($project['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($is_project_locked && !$edit_task): ?>
                    <input type="hidden" name="project_id" value="<?php echo $selected_project_id; ?>">
                <?php endif; ?>
            </div>
            
            <!-- Title Field -->
            <div class="form-group">
                <label for="title">
                    Title <span class="required">*</span>
                </label>
                <input type="text" id="title" name="title" required 
                       value="<?php echo htmlspecialchars($edit_task['title'] ?? ''); ?>"
                       placeholder="Enter task title">
            </div>
            
            <!-- Description Field with CKEditor -->
            <div class="form-group">
                <label for="description">
                    Description
                </label>
                <textarea id="description" name="description" rows="6"><?php echo htmlspecialchars($edit_task['description'] ?? ''); ?></textarea>
            </div>
            
            <!-- Type and Priority Row -->
            <div class="form-row">
                <div class="form-group">
                    <label for="type">
                        Type <span class="required">*</span>
                    </label>
                    <select id="type" name="type" required>
                        <option value="Task" <?php echo ($edit_task && $edit_task['type'] == 'Task') ? 'selected' : ''; ?>>Task</option>
                        <option value="Bug" <?php echo ($edit_task && $edit_task['type'] == 'Bug') ? 'selected' : ''; ?>>Bug</option>
                        <option value="Improvement" <?php echo ($edit_task && $edit_task['type'] == 'Improvement') ? 'selected' : ''; ?>>Improvement</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="priority">
                        Priority <span class="required">*</span>
                    </label>
                    <select id="priority" name="priority" required>
                        <option value="Low" <?php echo ($edit_task && $edit_task['priority'] == 'Low') ? 'selected' : ''; ?>>Low</option>
                        <option value="Medium" <?php echo ($edit_task && $edit_task['priority'] == 'Medium') ? 'selected' : ''; ?>>Medium</option>
                        <option value="High" <?php echo ($edit_task && $edit_task['priority'] == 'High') ? 'selected' : ''; ?>>High</option>
                    </select>
                </div>
            </div>
            
            <!-- Status Field (Edit Mode Only) -->
            <?php if ($edit_task): ?>
                <div class="form-group">
                    <label for="status">
                        Status <span class="required">*</span>
                    </label>
                    <select id="status_id" name="status_id" required>
                        <?php foreach ($statuses as $status_option): ?>
                            <option value="<?php echo $status_option['id']; ?>" <?php echo (isset($edit_task['status_id']) && $edit_task['status_id'] == $status_option['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status_option['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            
            <!-- Assignee and Due Date Row -->
            <div class="form-row">
                <div class="form-group">
                    <label for="assignee_id">
                        Assignee
                    </label>
                    <select id="assignee_id" name="assignee_id">
                        <option value="">Unassigned</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" 
                                    <?php echo ($edit_task && $edit_task['assignee_id'] == $user['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="due_date">
                        Due Date
                    </label>
                    <input type="date" id="due_date" name="due_date" 
                           value="<?php echo $edit_task && $edit_task['due_date'] ? date('Y-m-d', strtotime($edit_task['due_date'])) : ''; ?>">
                </div>
            </div>
            
            <?php if (!empty($form_sprints)): ?>
            <div class="form-group">
                <label for="sprint_id">Sprint</label>
                <select id="sprint_id" name="sprint_id">
                    <option value="">No sprint (backlog)</option>
                    <?php foreach ($form_sprints as $spr): ?>
                        <option value="<?php echo (int)$spr['id']; ?>" <?php echo ($edit_task && isset($edit_task['sprint_id']) && $edit_task['sprint_id'] == $spr['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($spr['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <!-- Form Actions -->
            <div class="form-actions">
                <a href="tasks" class="btn btn-secondary">
                    <i class="fas fa-times"></i>
                    Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-<?php echo $edit_task ? 'save' : 'plus'; ?>"></i>
                    <?php echo $edit_task ? 'Update Task' : 'Create Task'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- CKEditor CDN -->
<script src="https://cdn.ckeditor.com/ckeditor5/41.0.0/classic/ckeditor.js"></script>

<script>
// Initialize CKEditor
let editor;

ClassicEditor
    .create(document.querySelector('#description'), {
        toolbar: {
            items: [
                'heading',
                '|',
                'bold',
                'italic',
                'link',
                'bulletedList',
                'numberedList',
                '|',
                'outdent',
                'indent',
                '|',
                'blockQuote',
                'insertTable',
                '|',
                'undo',
                'redo'
            ]
        },
        language: 'en',
        heading: {
            options: [
                { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                { model: 'heading1', view: 'h1', title: 'Heading 1', class: 'ck-heading_heading1' },
                { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' }
            ]
        }
    })
    .then(instance => {
        editor = instance;
    })
    .catch(error => {
        console.error('Error initializing CKEditor:', error);
    });

// Update textarea before form submission
document.getElementById('taskForm').addEventListener('submit', function(e) {
    if (editor) {
        editor.updateSourceElement();
    }
});
</script>

<?php include 'includes/footer.php'; ?>
