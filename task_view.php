<?php
require_once 'config/config.php';
requireLogin();
requireActiveSubscription();

$page_title = 'Task Details';

$conn = getDBConnection();
$message = '';
$error = '';

function renderRichText($content) {
    if (empty($content)) {
        return '';
    }
    
    $allowed_tags = '<p><br><br/><strong><b><em><i><u><ol><ul><li><span><div><blockquote><a><h1><h2><h3><h4><h5><h6><code>';
    $sanitized = strip_tags($content, $allowed_tags);
    
    // Remove inline event handlers to prevent XSS
    $sanitized = preg_replace('/ on\w+="[^"]*"/i', '', $sanitized);
    $sanitized = preg_replace("/ on\w+='[^']*'/i", '', $sanitized);
    
    // Prevent javascript: URLs
    $sanitized = preg_replace_callback('/href=("|\')(.*?)\1/i', function ($matches) {
        $url = trim($matches[2]);
        if (stripos($url, 'javascript:') === 0) {
            return 'href="#"';
        }
        return 'href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
    }, $sanitized);
    
    return $sanitized;
}

// Helper function to generate initials from full name
function getInitials($full_name) {
    $full_name = trim($full_name);
    $words = explode(' ', $full_name);
    $words = array_filter($words, function($word) { return !empty(trim($word)); });
    $words = array_values($words); // Re-index array
    
    if (count($words) >= 2) {
        // Has space: first char of first word + first char of last word
        return strtoupper(substr($words[0], 0, 1) . substr($words[count($words) - 1], 0, 1));
    } else {
        // No space: first 2 characters
        return strtoupper(substr($full_name, 0, 2));
    }
}

// Helper function to format file size
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

// Helper function to get file icon
function getFileIcon($mime_type, $filename) {
    if (strpos($mime_type, 'image') !== false) {
        return 'fa-image';
    } elseif (strpos($mime_type, 'pdf') !== false) {
        return 'fa-file-pdf';
    } elseif (strpos($mime_type, 'word') !== false || strpos($filename, '.doc') !== false) {
        return 'fa-file-word';
    } elseif (strpos($mime_type, 'excel') !== false || strpos($filename, '.xls') !== false) {
        return 'fa-file-excel';
    } elseif (strpos($mime_type, 'zip') !== false || strpos($filename, '.zip') !== false || strpos($filename, '.rar') !== false) {
        return 'fa-file-archive';
    } else {
        return 'fa-file';
    }
}

// Get task ID
$task_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($task_id == 0) {
    header('Location: tasks');
    exit();
}

// Check if in description edit mode
$edit_description_mode = isset($_GET['edit_description']) && $_GET['edit_description'] == '1';

// Get task details
$task = $conn->query("
    SELECT t.*, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN users u ON t.assignee_id = u.id
    LEFT JOIN users u2 ON t.created_by = u2.id
    WHERE t.id = $task_id
")->fetch_assoc();

if (!$task) {
    header('Location: tasks');
    exit();
}

// Check permission for team members
if (!isAdmin() && !isProjectManager()) {
    $user_id = $_SESSION['user_id'];
    $is_assigned = $task['assignee_id'] == $user_id;
    $is_in_project = $conn->query("SELECT COUNT(*) as count FROM project_users WHERE project_id = {$task['project_id']} AND user_id = $user_id")->fetch_assoc()['count'] > 0;
    
    if (!$is_assigned && !$is_in_project) {
        header('Location: tasks');
        exit();
    }
}

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_comment'])) {
    $comment = trim($_POST['comment']);
    $user_id = $_SESSION['user_id'];
    $parent_id = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
    
    if (empty($comment)) {
        $error = 'Comment cannot be empty';
    } else {
        // Check if parent_id column exists, if not use NULL
        $check_column = $conn->query("SHOW COLUMNS FROM task_comments LIKE 'parent_id'");
        if ($check_column->num_rows == 0) {
            // Add parent_id column if it doesn't exist
            $conn->query("ALTER TABLE task_comments ADD COLUMN parent_id INT NULL AFTER id, ADD FOREIGN KEY (parent_id) REFERENCES task_comments(id) ON DELETE CASCADE");
        }
        
        // Validate parent_id if provided - ensure it belongs to the same task
        if ($parent_id) {
            $validate_stmt = $conn->prepare("SELECT id FROM task_comments WHERE id = ? AND task_id = ?");
            $validate_stmt->bind_param("ii", $parent_id, $task_id);
            $validate_stmt->execute();
            $validate_result = $validate_stmt->get_result();
            
            if ($validate_result->num_rows == 0) {
                $error = 'Invalid parent comment';
                $parent_id = null; // Reset to null if invalid
            }
            $validate_stmt->close();
        }
        
        if (empty($error)) {
            if ($parent_id) {
                $stmt = $conn->prepare("INSERT INTO task_comments (task_id, user_id, comment, parent_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iisi", $task_id, $user_id, $comment, $parent_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO task_comments (task_id, user_id, comment, parent_id) VALUES (?, ?, ?, NULL)");
        $stmt->bind_param("iis", $task_id, $user_id, $comment);
            }
        
        if ($stmt->execute()) {
            $message = 'Comment added successfully';
                // Refresh page to show new comment
                header('Location: task_view?id=' . $task_id);
                exit();
        } else {
            $error = 'Error adding comment';
            }
        }
    }
}

// Handle quick status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['status'];
    // Map 'Closed' to 'Done' if database ENUM still uses 'Done' (temporary until migration)
    // After running migration script, remove this mapping
    // if ($new_status == 'Closed') $new_status = 'Done';
    $old_status = $task['status'];
    $user_id = $_SESSION['user_id'];
    
    $stmt = $conn->prepare("UPDATE tasks SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $task_id);
    
    if ($stmt->execute()) {
        // Log activity
        $action = "Status changed";
        $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
        $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_status, $new_status);
        $stmt2->execute();
        
        $message = 'Status updated successfully';
        // Refresh task data
        $task = $conn->query("
            SELECT t.*, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u ON t.assignee_id = u.id
            LEFT JOIN users u2 ON t.created_by = u2.id
            WHERE t.id = $task_id
        ")->fetch_assoc();
    } else {
        $error = 'Error updating status';
    }
}

// Handle assignee update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_assignee'])) {
    $new_assignee_id = !empty($_POST['assignee_id']) ? intval($_POST['assignee_id']) : null;
    $old_assignee_id = $task['assignee_id'];
    $user_id = $_SESSION['user_id'];
    
    // Get old assignee name
    $old_assignee_name = $task['assignee_name'] ?: 'Unassigned';
    
    // Get new assignee name
    $new_assignee_name = 'Unassigned';
    if ($new_assignee_id) {
        $assignee_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
        $assignee_stmt->bind_param("i", $new_assignee_id);
        $assignee_stmt->execute();
        $assignee_result = $assignee_stmt->get_result();
        if ($assignee_row = $assignee_result->fetch_assoc()) {
            $new_assignee_name = $assignee_row['full_name'];
        }
        $assignee_stmt->close();
    }
    
    $stmt = $conn->prepare("UPDATE tasks SET assignee_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_assignee_id, $task_id);
    
    if ($stmt->execute()) {
        // Log activity
        $action = "Assignee changed";
        $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
        $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_assignee_name, $new_assignee_name);
        $stmt2->execute();
        
        $message = 'Assignee updated successfully';
        // Refresh task data
        $task = $conn->query("
            SELECT t.*, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u ON t.assignee_id = u.id
            LEFT JOIN users u2 ON t.created_by = u2.id
            WHERE t.id = $task_id
        ")->fetch_assoc();
    } else {
        $error = 'Error updating assignee';
    }
}

// Handle priority update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_priority'])) {
    $new_priority = $_POST['priority'];
    $old_priority = $task['priority'];
    $user_id = $_SESSION['user_id'];
    
    $stmt = $conn->prepare("UPDATE tasks SET priority = ? WHERE id = ?");
    $stmt->bind_param("si", $new_priority, $task_id);
    
    if ($stmt->execute()) {
        // Log activity
        $action = "Priority changed";
        $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
        $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_priority, $new_priority);
        $stmt2->execute();
        
        $message = 'Priority updated successfully';
        // Refresh task data
        $task = $conn->query("
            SELECT t.*, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u ON t.assignee_id = u.id
            LEFT JOIN users u2 ON t.created_by = u2.id
            WHERE t.id = $task_id
        ")->fetch_assoc();
    } else {
        $error = 'Error updating priority';
    }
}

// Handle due date update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_due_date'])) {
    $new_due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $old_due_date = $task['due_date'];
    $user_id = $_SESSION['user_id'];
    
    // Format dates for display in activity log
    $old_due_date_display = $old_due_date ? formatDate($old_due_date) : 'No due date';
    $new_due_date_display = $new_due_date ? formatDate($new_due_date) : 'No due date';
    
    $stmt = $conn->prepare("UPDATE tasks SET due_date = ? WHERE id = ?");
    $stmt->bind_param("si", $new_due_date, $task_id);
    
    if ($stmt->execute()) {
        // Log activity
        $action = "Due date changed";
        $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
        $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_due_date_display, $new_due_date_display);
        $stmt2->execute();
        
        $message = 'Due date updated successfully';
        // Refresh task data
        $task = $conn->query("
            SELECT t.*, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u ON t.assignee_id = u.id
            LEFT JOIN users u2 ON t.created_by = u2.id
            WHERE t.id = $task_id
        ")->fetch_assoc();
    } else {
        $error = 'Error updating due date';
    }
}

// Handle "Assign to Me" action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_to_me'])) {
    $user_id = $_SESSION['user_id'];
    $old_assignee_id = $task['assignee_id'];
    
    // Get old assignee name
    $old_assignee_name = $task['assignee_name'] ?: 'Unassigned';
    
    // Get current user name
    $user_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $new_assignee_name = $user_result->fetch_assoc()['full_name'];
    $user_stmt->close();
    
    $stmt = $conn->prepare("UPDATE tasks SET assignee_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $user_id, $task_id);
    
    if ($stmt->execute()) {
        // Log activity
        $action = "Assignee changed";
        $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
        $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_assignee_name, $new_assignee_name);
        $stmt2->execute();
        
        $message = 'Task assigned to you successfully';
        // Refresh task data
        $task = $conn->query("
            SELECT t.*, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u ON t.assignee_id = u.id
            LEFT JOIN users u2 ON t.created_by = u2.id
            WHERE t.id = $task_id
        ")->fetch_assoc();
    } else {
        $error = 'Error assigning task';
    }
}

// Handle title update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_title'])) {
    $old_title = $task['title'] ?? '';
    $new_title = trim($_POST['title']);
    $user_id = $_SESSION['user_id'];
    
    if (empty($new_title)) {
        $error = 'Title cannot be empty';
    } else {
        $stmt = $conn->prepare("UPDATE tasks SET title = ? WHERE id = ?");
        $stmt->bind_param("si", $new_title, $task_id);
        
        if ($stmt->execute()) {
            // Log title change
            if ($old_title != $new_title) {
                $action = "Title changed";
                $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
                $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_title, $new_title);
                $stmt2->execute();
            }
            
            $message = 'Title updated successfully';
            // Refresh task data
            $task = $conn->query("
                SELECT t.*, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name
                FROM tasks t
                LEFT JOIN projects p ON t.project_id = p.id
                LEFT JOIN users u ON t.assignee_id = u.id
                LEFT JOIN users u2 ON t.created_by = u2.id
                WHERE t.id = $task_id
            ")->fetch_assoc();
        } else {
            $error = 'Error updating title: ' . $conn->error;
        }
        $stmt->close();
    }
}

// Handle description update only
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_description'])) {
    $old_description = $task['description'] ?? '';
    $new_description = trim($_POST['description']);
    $user_id = $_SESSION['user_id'];
    
    $stmt = $conn->prepare("UPDATE tasks SET description = ? WHERE id = ?");
    $stmt->bind_param("si", $new_description, $task_id);
    
    if ($stmt->execute()) {
        // Log description change
        if ($old_description != $new_description) {
            $action = "Description changed";
            $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action) VALUES (?, ?, ?)");
            $stmt2->bind_param("iis", $task_id, $user_id, $action);
            $stmt2->execute();
        }
        
        $message = 'Description updated successfully';
        // Refresh task data
        $task = $conn->query("
            SELECT t.*, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u ON t.assignee_id = u.id
            LEFT JOIN users u2 ON t.created_by = u2.id
            WHERE t.id = $task_id
        ")->fetch_assoc();
        
        // Exit edit mode after successful update
        header('Location: task_view?id=' . $task_id);
        exit();
    } else {
        $error = 'Error updating description: ' . $conn->error;
    }
    $stmt->close();
}

// Check if parent_id column exists
$check_column = $conn->query("SHOW COLUMNS FROM task_comments LIKE 'parent_id'");
$has_parent_id = $check_column->num_rows > 0;

// Get comments with replies (nested structure)
if ($has_parent_id) {
    $comments_stmt = $conn->prepare("
        SELECT c.*, u.full_name as user_name, u.username, u.id as user_table_id
        FROM task_comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.task_id = ? AND (c.parent_id IS NULL OR c.parent_id = 0)
        ORDER BY c.created_at ASC
    ");
    $comments_stmt->bind_param("i", $task_id);
    $comments_stmt->execute();
    $comments = $comments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $comments_stmt->close();

    // Get replies for each comment
    foreach ($comments as &$comment) {
        $comment_id = intval($comment['id']);
        $replies_stmt = $conn->prepare("
            SELECT c.*, u.full_name as user_name, u.username, u.id as user_table_id
            FROM task_comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.parent_id = ? AND c.task_id = ?
            ORDER BY c.created_at ASC
        ");
        $replies_stmt->bind_param("ii", $comment_id, $task_id);
        $replies_stmt->execute();
        $comment['replies'] = $replies_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $replies_stmt->close();
    }
} else {
    // If parent_id doesn't exist, get all comments as top-level
    $comments_query = "
        SELECT c.*, u.full_name as user_name, u.username, u.id as user_table_id
    FROM task_comments c
    JOIN users u ON c.user_id = u.id
    WHERE c.task_id = $task_id
    ORDER BY c.created_at ASC
    ";
    $comments = $conn->query($comments_query)->fetch_all(MYSQLI_ASSOC);
    foreach ($comments as &$comment) {
        $comment['replies'] = [];
    }
}

// Get activity logs
$activities = $conn->query("
    SELECT a.*, u.full_name as user_name
    FROM activity_logs a
    JOIN users u ON a.user_id = u.id
    WHERE a.task_id = $task_id
    ORDER BY a.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Get users for assignee dropdown and @mentions (filtered by organization)
if (isSuperAdmin()) {
    $users_list = $conn->query("SELECT id, full_name, username FROM users WHERE status = 'active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
} else {
    $org_id = getOrganizationId();
    if ($org_id) {
        $stmt = $conn->prepare("SELECT id, full_name, username FROM users WHERE organization_id = ? AND status = 'active' ORDER BY full_name");
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $users_list = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $users_list = [];
    }
}


// Get task type icon
$type_icon = '';
$type_color = '';
switch ($task['type']) {
    case 'Task':
        $type_icon = 'fa-tasks';
        $type_color = '#14b8a6';
        break;
    case 'Bug':
        $type_icon = 'fa-bug';
        $type_color = '#ef4444';
        break;
    case 'Improvement':
        $type_icon = 'fa-lightbulb';
        $type_color = '#f97316';
        break;
}

// Get attachments if table exists
$attachments = [];
$attachments_table_exists = false;
$attachments_check_conn = getDBConnection();
$table_check = $attachments_check_conn->query("SHOW TABLES LIKE 'task_attachments'");
if ($table_check && $table_check->num_rows > 0) {
    $attachments_table_exists = true;
    $attachments_query = $attachments_check_conn->prepare("SELECT id, filename, file_path, file_size, mime_type, created_at FROM task_attachments WHERE task_id = ? ORDER BY created_at DESC");
    if ($attachments_query) {
        $attachments_query->bind_param("i", $task_id);
        $attachments_query->execute();
        $attachments_result = $attachments_query->get_result();
        $attachments = $attachments_result->fetch_all(MYSQLI_ASSOC);
        $attachments_query->close();
    }
}
$attachments_check_conn->close();

$conn->close();

include 'includes/header.php';
?>

<style>
.task-view-container {
    width: 100%;
    margin: 0;
    padding: 15px;
}

.task-header-section {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
}

.task-header-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 16px;
}

.task-title-section {
    flex: 1;
    min-width: 300px;
}

.task-id-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #f1f5f9;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    margin-bottom: 12px;
}

.task-title {
    font-size: 22px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 12px 0;
    line-height: 1.3;
}

.task-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    margin-right: 8px;
}

.task-actions {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}

.task-main-content {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 24px;
    margin-bottom: 24px;
    align-items: start;
}

.task-main-left {
    grid-column: 1;
    min-width: 0;
    overflow: hidden;
}

@media (max-width: 1200px) {
    .task-main-content {
        grid-template-columns: 1fr 300px;
        gap: 20px;
    }
}

@media (max-width: 1024px) {
    .task-main-content {
        grid-template-columns: 1fr;
        display: flex;
        flex-direction: column;
    }
    
    .task-main-left {
        grid-column: 1;
        order: 1;
        width: 100%;
    }
    
    .task-sidebar {
        grid-column: 1;
        order: 2;
        position: relative;
        top: 0;
        max-height: none;
        margin-top: 24px;
        padding-top: 0;
        width: 100%;
        min-width: auto;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 16px;
    }
}

@media (max-width: 768px) {
    .task-main-content {
        gap: 16px;
        display: flex;
        flex-direction: column;
    }
    
    .task-main-left {
        order: 1;
    }
    
    .task-sidebar {
        order: 2;
        grid-template-columns: 1fr;
        margin-top: 24px;
    }
    
    .tab-navigation {
        flex-wrap: wrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .tab-btn {
        padding: 10px 16px;
        font-size: 13px;
        white-space: nowrap;
    }
    
    .task-info-card {
        padding: 16px;
    }
    
    .info-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
        padding: 10px 0;
    }
    
    .info-label {
        font-size: 13px;
        min-width: auto;
    }
    
    .info-value {
        text-align: left;
        margin-left: 0;
        width: 100%;
        justify-content: flex-start;
    }
    
    .info-value form {
        width: 100%;
    }
    
    .info-value select,
    .info-value input[type="date"] {
        width: 100%;
        min-width: auto;
    }
    
    .task-details-card {
        padding: 16px;
    }
}

.tab-navigation {
    display: flex;
    gap: 0;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 24px;
}

.tab-btn {
    padding: 12px 20px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: #64748b;
    font-weight: 500;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: -2px;
}

.tab-btn:hover {
    color: #14b8a6;
    background: #f8fafc;
}

.tab-btn.active {
    border-bottom: 2px solid #14b8a6;
    color: #14b8a6;
    font-weight: 600;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.task-details-card {
    background: #ffffff;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
}

.task-details-card h3 {
    font-size: 18px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 20px 0;
    padding-bottom: 12px;
    border-bottom: 2px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.task-description {
    color: #475569;
    line-height: 1.8;
    font-size: 15px;
    margin-bottom: 24px;
}

.task-description:empty::before {
    content: "No description provided";
    color: #94a3b8;
    font-style: italic;
}

.task-sidebar {
    grid-column: 2;
    display: flex !important;
    flex-direction: column;
    gap: 16px;
    position: sticky;
    top: 20px;
    align-self: start;
   
    overflow-y: auto;
    width: 100%;
    min-width: 340px;
    visibility: visible !important;
    padding-top: 48px;
    -ms-overflow-style: none;  /* IE and Edge */
    scrollbar-width: none;  /* Firefox */
}

/* Hide scrollbar for Chrome, Safari and Opera */
.task-sidebar::-webkit-scrollbar {
    display: none;
}

.task-info-card {
    background: #ffffff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
    width: 100%;
    margin-bottom: 0;
}

.task-info-card h4 {
    font-size: 15px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 16px 0;
    padding-bottom: 12px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 12px 0;
    border-bottom: 1px solid #f1f5f9;
    gap: 16px;
    width: 100%;
    flex-direction: row;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #64748b;
    font-size: 13px;
    width: 110px;
    flex-shrink: 0;
    display: block;
    line-height: 1.5;
    padding-top: 2px;
}

.info-value {
    color: #1e293b;
    font-size: 13px;
    text-align: right;
    flex: 1;
    margin-left: 0;
    word-break: break-word;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    min-width: 0;
    box-sizing: border-box;
    line-height: 1.5;
}

.info-value > * {
    display: inline-flex;
    align-items: center;
    flex-wrap: wrap;
}

.info-value form {
    display: flex !important;
    align-items: center;
    width: 100%;
    justify-content: flex-end;
    margin-left: 0;
    max-width: 100%;
    flex-wrap: wrap;
    gap: 8px;
}

.info-value form select {
    width: auto;
    min-width: 150px;
    max-width: 200px;
}

.info-value form input[type="date"] {
    width: auto;
    min-width: 140px;
}

.info-value a {
    color: #14b8a6;
    text-decoration: none;
    font-weight: 500;
    transition: color 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.info-value a:hover {
    color: #0d9488;
    text-decoration: underline;
}

.info-value select,
.info-value input[type="date"] {
    max-width: 100%;
}

.overdue-badge {
    color: #ef4444;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 6px;
}

.status-update-form {
    background: #f8fafc;
    padding: 16px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    margin-top: 16px;
}

.status-update-form h5 {
    font-size: 14px;
    font-weight: 600;
    color: #475569;
    margin: 0 0 12px 0;
}

.status-update-form form {
    display: flex;
    gap: 10px;
    align-items: center;
}

.status-update-form select {
    flex: 1;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 14px;
    background: #ffffff;
    color: #1e293b;
}

.comments-section {
    background: #ffffff;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
    margin-bottom: 24px;
}

.comments-section h3 {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 12px 0;
    padding-bottom: 8px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.comment-form {
    background: #f8fafc;
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 12px;
    border: 1px solid #e2e8f0;
    position: relative;
}

.comment-form textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 5px;
    font-size: 12px;
    font-family: inherit;
    resize: vertical;
    min-height: 70px;
    margin-bottom: 8px;
    transition: all 0.2s ease;
    background: #ffffff;
}

.comment-form textarea:focus {
    outline: none;
    border-color: #14b8a6;
    box-shadow: 0 0 0 4px rgba(20, 184, 166, 0.1);
    background: #ffffff;
}

.comments-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-height: 500px;
    overflow-y: auto;
    padding: 8px 4px;
}

.comments-list::-webkit-scrollbar {
    width: 4px;
}

.comments-list::-webkit-scrollbar-track {
    background: #f1f5f9;
}

.comments-list::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 2px;
}

.comment-item {
    display: flex;
    gap: 8px;
    padding: 8px 10px;
    background: #ffffff;
    border-radius: 6px;
    transition: all 0.15s ease;
    border: 1px solid #e2e8f0;
}

.comment-item:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
}

.comment-reply {
    margin-left: 40px;
    margin-top: 4px;
    padding: 6px 8px;
    background: #f8fafc;
    border-left: 2px solid #14b8a6;
}

.comment-avatar {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%);
    color: white;
    border-radius: 50%;
    font-size: 11px;
    font-weight: 600;
}

.comment-content {
    flex: 1;
    min-width: 0;
}

.comment-header {
    display: flex;
    align-items: baseline;
    gap: 6px;
    margin-bottom: 4px;
    flex-wrap: wrap;
}

.comment-author {
    font-weight: 600;
    color: #1e293b;
    font-size: 12px;
}

.comment-date {
    color: #94a3b8;
    font-size: 10px;
}

.comment-text {
    color: #475569;
    line-height: 1.4;
    font-size: 12px;
    word-wrap: break-word;
}

.comment-text .mention {
    background: #e0f2fe;
    color: #0369a1;
    padding: 2px 4px;
    border-radius: 3px;
    font-weight: 500;
    cursor: pointer;
}

.comment-actions {
    display: flex;
    gap: 8px;
    margin-top: 4px;
    padding-top: 4px;
}

.comment-reply-btn {
    background: none;
    border: none;
    color: #64748b;
    font-size: 11px;
    cursor: pointer;
    padding: 2px 4px;
    border-radius: 3px;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 4px;
}

.comment-reply-btn:hover {
    background: #f1f5f9;
    color: #14b8a6;
}

.reply-form {
    margin-top: 8px;
    margin-left: 36px;
    padding: 8px;
    background: #f8fafc;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    display: none;
}

.reply-form.active {
    display: block;
}

.reply-form textarea {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    font-size: 12px;
    min-height: 60px;
    resize: vertical;
    margin-bottom: 6px;
}

.reply-form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 6px;
}

.mention-autocomplete {
    position: absolute;
    background: white;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    min-width: 200px;
}

.mention-autocomplete.active {
    display: block;
}

.mention-item {
    padding: 8px 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: background 0.2s;
}

.mention-item:hover,
.mention-item.selected {
    background: #f1f5f9;
}

.mention-item-avatar {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 600;
}

.mention-item-name {
    font-size: 13px;
    color: #1e293b;
    font-weight: 500;
}

.no-comments {
    text-align: center;
    padding: 40px 20px;
    color: #94a3b8;
    font-style: italic;
}

.activity-section {
    background: #ffffff;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
}

.activity-section h3 {
    font-size: 18px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 20px 0;
    padding-bottom: 12px;
    border-bottom: 2px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.activity-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.activity-item {
    padding: 12px 16px;
    background: #f8fafc;
    border-radius: 6px;
    border-left: 3px solid #cbd5e1;
    font-size: 14px;
    color: #475569;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

.activity-item strong {
    color: #1e293b;
}

.activity-date {
    color: #94a3b8;
    font-size: 12px;
}

.no-activity {
    text-align: center;
    padding: 40px 20px;
    color: #94a3b8;
    font-style: italic;
}
</style>

<style>
.task-view-page {
    background: var(--page-bg);
    min-height: calc(100vh - 60px);
    padding: 0;
}

.task-breadcrumb {
    padding: 12px 24px;
    background: var(--card-bg);
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--text-secondary);
}

.task-breadcrumb a {
    color: var(--blue);
    text-decoration: none;
}

.task-breadcrumb a:hover {
    text-decoration: underline;
}

.task-breadcrumb-separator {
    color: var(--text-muted);
}

.task-header-new {
    background: var(--card-bg);
    padding: 24px;
    border-bottom: 1px solid var(--border-color);
}

.task-title-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    gap: 16px;
}

.task-title-main {
    font-size: 24px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
    flex: 1;
}

.task-header-actions {
    display: flex;
    gap: 8px;
}

.task-action-btn {
    padding: 8px 16px;
    border: 1px solid var(--border-color);
    background: var(--card-bg);
    color: var(--text-primary);
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    transition: all 0.2s ease;
}

.task-action-btn:hover {
    background: var(--page-bg);
    border-color: var(--blue);
    color: var(--blue);
}

.task-action-btn.delete-btn:hover {
    background: var(--failed-bg);
    border-color: var(--chart-red);
    color: var(--chart-red);
}

.task-metadata-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding: 12px 0;
}

.task-metadata-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
}

.metadata-status-active {
    background: var(--chart-green);
    color: white;
}

.metadata-status-pending {
    background: var(--chart-yellow);
    color: var(--text-primary);
}

.metadata-status-failed {
    background: var(--chart-red);
    color: white;
}

.metadata-status-closed {
    background: var(--chart-gray);
    color: white;
}

.metadata-priority-high {
    background: var(--chart-yellow);
    color: var(--text-primary);
}

.metadata-priority-medium {
    background: var(--chart-red);
    color: white;
}

.metadata-priority-low {
    background: var(--chart-gray);
    color: var(--text-primary);
}

.metadata-badge-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}

.task-content-layout {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 24px;
    padding: 24px;
    max-width: 1400px;
    margin: 0 auto;
}

.task-details-sidebar {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.task-details-card-new {
    background: var(--card-bg);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    box-shadow: 0 1px 3px var(--shadow);
    padding: 20px;
}

.task-details-card-new h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 16px 0;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-color);
}

.detail-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid var(--border-color);
}

.detail-item:last-child {
    border-bottom: none;
}

.detail-item-icon {
    color: var(--text-muted);
    font-size: 16px;
    margin-top: 2px;
    flex-shrink: 0;
}

.detail-item-content {
    flex: 1;
    min-width: 0;
}

.detail-item-label {
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    margin-bottom: 4px;
}

.detail-item-value {
    font-size: 14px;
    color: var(--text-primary);
    font-weight: 400;
}

.assignee-display {
    display: flex;
    align-items: center;
    gap: 10px;
}

.assignee-avatar-large {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--blue-light);
    color: var(--blue-dark);
    font-size: 14px;
    font-weight: 600;
    flex-shrink: 0;
}

.assignee-info {
    flex: 1;
    min-width: 0;
}

.assignee-name {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    margin-bottom: 2px;
}

.assignee-role {
    font-size: 12px;
    color: var(--text-secondary);
}

.subtasks-section {
    margin-top: 8px;
}

.subtask-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
}

.subtask-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: var(--blue);
    flex-shrink: 0;
}

.subtask-text {
    font-size: 14px;
    color: var(--text-primary);
    flex: 1;
}

.subtask-text.completed {
    text-decoration: line-through;
    color: var(--text-muted);
}

.add-subtask-btn {
    margin-top: 8px;
    padding: 6px 12px;
    background: none;
    border: 1px dashed var(--border-color);
    color: var(--text-secondary);
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}

.add-subtask-btn:hover {
    border-color: var(--blue);
    color: var(--blue);
    background: var(--blue-light);
}

.task-main-content-new {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.task-description-section {
    background: var(--card-bg);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    box-shadow: 0 1px 3px var(--shadow);
    padding: 24px;
}

.task-description-text {
    font-size: 15px;
    line-height: 1.7;
    color: var(--text-primary);
    margin-bottom: 24px;
}

.nested-tabs {
    display: flex;
    gap: 0;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.nested-tab-btn {
    padding: 10px 16px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: var(--text-secondary);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    margin-bottom: -1px;
}

.nested-tab-btn:hover {
    color: var(--text-primary);
}

.nested-tab-btn.active {
    border-bottom-color: var(--blue);
    color: var(--blue);
    font-weight: 600;
}

.nested-tab-content {
    display: none;
}

.nested-tab-content.active {
    display: block;
}

.attachments-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
}

.attachment-card {
    background: var(--page-bg);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    overflow: hidden;
    cursor: pointer;
    transition: all 0.2s ease;
}

.attachment-card:hover {
    border-color: var(--blue);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.attachment-preview {
    width: 100%;
    height: 120px;
    background: var(--border-color);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    font-size: 32px;
}

.attachment-info {
    padding: 12px;
}

.attachment-name {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-primary);
    margin-bottom: 4px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.attachment-meta {
    font-size: 11px;
    color: var(--text-secondary);
}

.comments-activity-section {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-top: 24px;
}

.comments-panel {
    background: var(--card-bg);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    box-shadow: 0 1px 3px var(--shadow);
    padding: 20px;
}

.comments-panel-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-color);
}

.comments-panel-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.comment-input-area {
    margin-bottom: 20px;
}

.comment-textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
    resize: vertical;
    min-height: 80px;
    margin-bottom: 8px;
}

.comment-actions-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.comment-attach-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: var(--page-bg);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    color: var(--text-secondary);
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.comment-attach-btn:hover {
    border-color: var(--blue);
    color: var(--blue);
}

.comment-avatars {
    display: flex;
    align-items: center;
    gap: -8px;
}

.comment-avatar-small {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    border: 2px solid var(--card-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--blue-light);
    color: var(--blue-dark);
    font-size: 10px;
    font-weight: 600;
    margin-left: -8px;
}

.comment-avatar-small:first-child {
    margin-left: 0;
}

.activity-panel {
    background: var(--card-bg);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    box-shadow: 0 1px 3px var(--shadow);
    padding: 20px;
}

.activity-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-color);
}

.activity-panel-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.activity-header-actions {
    display: flex;
    gap: 8px;
}

.activity-header-icon {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s ease;
}

.activity-header-icon:hover {
    border-color: var(--blue);
    color: var(--blue);
}

.activity-filter {
    padding: 6px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--card-bg);
    color: var(--text-primary);
    font-size: 13px;
    cursor: pointer;
}

.activity-item-new {
    display: flex;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid var(--border-color);
}

.activity-item-new:last-child {
    border-bottom: none;
}

.activity-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--blue-light);
    color: var(--blue-dark);
    font-size: 12px;
    font-weight: 600;
    flex-shrink: 0;
}

.activity-content {
    flex: 1;
    min-width: 0;
}

.activity-text {
    font-size: 13px;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.activity-time {
    font-size: 11px;
    color: var(--text-muted);
}

.activity-preview {
    margin-top: 8px;
    width: 100%;
    max-width: 200px;
    height: 100px;
    background: var(--page-bg);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    font-size: 24px;
}

@media (max-width: 1024px) {
    .task-content-layout {
        grid-template-columns: 1fr;
    }
    
    .comments-activity-section {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="task-view-page">
    <!-- Breadcrumb Navigation -->
    <div class="task-breadcrumb">
        <a href="tasks">Tasks</a>
        <span class="task-breadcrumb-separator">/</span>
        <span><?php echo htmlspecialchars($task['title']); ?></span>
    </div>
    
    <!-- Task Header -->
    <div class="task-header-new">
       
        
        <!-- Metadata Bar -->
        <div class="task-metadata-bar">
            <?php 
            $task_status_display = normalizeStatusForDisplay($task['status'] ?? 'To Do');
            $status_lower = strtolower(str_replace(' ', '-', $task_status_display));
            $status_class = 'metadata-status-pending';
            if (strpos($status_lower, 'to-do') !== false || strpos($status_lower, 'todo') !== false) {
                $status_class = 'metadata-status-pending';
            } elseif (strpos($status_lower, 'in-progress') !== false || strpos($status_lower, 'inprogress') !== false) {
                $status_class = 'metadata-status-active';
            } elseif (strpos($status_lower, 'closed') !== false) {
                $status_class = 'metadata-status-closed';
            }
            
            $priority_lower = strtolower($task['priority'] ?? '');
            $priority_class = 'metadata-priority-low';
            if ($priority_lower == 'high') {
                $priority_class = 'metadata-priority-high';
            } elseif ($priority_lower == 'medium') {
                $priority_class = 'metadata-priority-medium';
            }
            ?>
            <span class="task-metadata-badge <?php echo $status_class; ?>">
                <span class="metadata-badge-dot"></span>
                <?php echo htmlspecialchars($task_status_display); ?>
            </span>
            <span class="task-metadata-badge <?php echo $priority_class; ?>">
                <span class="metadata-badge-dot"></span>
                <?php echo htmlspecialchars($task['priority'] ?? 'Low'); ?> Priority
            </span>
            <select name="priority_quick" 
                    onchange="updateTaskPriority(<?php echo $task['id']; ?>, this.value)"
                    style="padding: 6px 32px 6px 12px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--card-bg); color: var(--text-primary); font-size: 13px; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 12 12\'%3E%3Cpath fill=\'%236B7280\' d=\'M6 9L1 4h10z\'/%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 12px center;">
                <option value="Low" <?php echo ($priority_lower == 'low') ? 'selected' : ''; ?>>Low</option>
                <option value="Medium" <?php echo ($priority_lower == 'medium') ? 'selected' : ''; ?>>Medium</option>
                <option value="High" <?php echo ($priority_lower == 'high') ? 'selected' : ''; ?>>High</option>
            </select>
            <span style="font-size: 13px; color: var(--text-secondary);">
                <i class="fas fa-calendar" style="margin-right: 6px; color: var(--chart-yellow);"></i>
                Due Date: <?php echo $task['due_date'] ? date('d-m-Y', strtotime($task['due_date'])) : 'No due date'; ?>
            </span>
        </div>
        <div class="task-title-header" style="display: flex; align-items: center; gap: 12px; position: relative;">
            <div id="task-title-display" style="flex: 1;">
                <h1 class="task-title-main" id="task-title-text"><?php echo htmlspecialchars($task['title']); ?></h1>
            </div>
            <div id="task-title-edit" style="display: none; flex: 1;">
                <form method="POST" action="" id="titleEditForm" style="display: flex; align-items: center; gap: 8px;">
                    <input type="text" name="title" id="task-title-input" value="<?php echo htmlspecialchars($task['title']); ?>" 
                           style="flex: 1; padding: 8px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 24px; font-weight: 600; font-family: inherit;"
                           required>
                    <button type="submit" name="update_title" value="1" 
                            style="padding: 8px 16px; background: var(--blue); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">
                        <i class="fas fa-check"></i> Save
                    </button>
                    <button type="button" onclick="cancelTitleEdit()" 
                            style="padding: 8px 16px; background: var(--text-muted); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </form>
            </div>
            <button type="button" onclick="editTitle()" 
                    style="background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 8px; border-radius: 4px; transition: all 0.2s;"
                    onmouseover="this.style.background='var(--blue-light)'; this.style.color='var(--blue)'"
                    onmouseout="this.style.background='none'; this.style.color='var(--text-muted)'"
                    title="Edit Title">
                <i class="fas fa-edit" style="font-size: 16px;"></i>
            </button>
        </div>
    </div>

<?php if ($message): ?>
        <div class="alert alert-success" style="margin-bottom: 20px;"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom: 20px;"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

    <!-- Main Content Layout -->
    <div class="task-content-layout">
        <!-- Left Sidebar: Task Details -->
        <div class="task-details-sidebar">
            <!-- Due Date Card -->
            <div class="task-details-card-new">
                <h4>Due Date</h4>
                <div class="detail-item">
                    <i class="fas fa-calendar detail-item-icon" style="color: var(--chart-yellow);"></i>
                    <div class="detail-item-content">
                        <div class="detail-item-value">
                            <?php echo $task['due_date'] ? date('d-m-Y', strtotime($task['due_date'])) : 'No due date'; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Assignee Card -->
            <div class="task-details-card-new">
                <h4>Assignee</h4>
                <div class="detail-item">
                    <?php if ($task['assignee_name']): 
                        $assignee_initials = getInitials($task['assignee_name']);
                        // Get user role
                        $assignee_role = 'Team Member';
                        if ($task['assignee_id']) {
                            $role_conn = getDBConnection();
                            $role_query = $role_conn->query("SELECT r.name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = {$task['assignee_id']}");
                            if ($role_query && $role_row = $role_query->fetch_assoc()) {
                                $assignee_role = $role_row['name'];
                            }
                            $role_conn->close();
                        }
                    ?>
                        <div class="assignee-display" style="width: 100%;">
                            <div class="assignee-avatar-large">
                                <?php echo htmlspecialchars($assignee_initials); ?>
                            </div>
                            <div class="assignee-info">
                                <div class="assignee-name"><?php echo htmlspecialchars($task['assignee_name']); ?></div>
                                <div class="assignee-role"><?php echo htmlspecialchars($assignee_role); ?></div>
                            </div>
                        </div>
                        <?php if (isAdmin() || isProjectManager()): ?>
                            <form method="POST" action="" style="margin-top: 8px;">
                                <select name="assignee_id" onchange="this.form.submit()" 
                                        style="width: 100%; padding: 6px 10px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 13px; background: var(--card-bg); color: var(--text-primary);">
                                    <option value="">Change Assignee</option>
                                    <?php foreach ($users_list as $user): ?>
                                        <option value="<?php echo $user['id']; ?>" <?php echo ($task['assignee_id'] == $user['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="update_assignee" value="1">
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="detail-item-value" style="color: var(--text-muted);">
                            <i class="fas fa-user-slash" style="margin-right: 6px;"></i>
                            Unassigned
                        </div>
                        <?php if (isAdmin() || isProjectManager()): ?>
                            <form method="POST" action="" style="margin-top: 8px;">
                                <select name="assignee_id" onchange="this.form.submit()" 
                                        style="width: 100%; padding: 6px 10px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 13px; background: var(--card-bg); color: var(--text-primary);">
                                    <option value="">Assign to...</option>
                                    <?php foreach ($users_list as $user): ?>
                                        <option value="<?php echo $user['id']; ?>">
                                            <?php echo htmlspecialchars($user['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="update_assignee" value="1">
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Subtasks Card -->
            <div class="task-details-card-new">
                <h4>Subtasks</h4>
                <div class="subtasks-section">
                    <?php 
                    // Check if subtasks table exists and fetch real subtasks
                    $subtasks_conn = getDBConnection();
                    $subtasks_table_exists = false;
                    $subtasks = [];
                    
                    // Check if table exists
                    $table_check = $subtasks_conn->query("SHOW TABLES LIKE 'task_subtasks'");
                    if ($table_check && $table_check->num_rows > 0) {
                        $subtasks_table_exists = true;
                        $subtasks_query = $subtasks_conn->prepare("SELECT id, title, completed FROM task_subtasks WHERE task_id = ? ORDER BY created_at ASC");
                        if ($subtasks_query) {
                            $subtasks_query->bind_param("i", $task_id);
                            $subtasks_query->execute();
                            $subtasks_result = $subtasks_query->get_result();
                            $subtasks = $subtasks_result->fetch_all(MYSQLI_ASSOC);
                            $subtasks_query->close();
                        }
                    }
                    $subtasks_conn->close();
                    ?>
                    <?php if (empty($subtasks)): ?>
                        <div style="padding: 16px 0; text-align: center; color: var(--text-muted); font-size: 13px;">
                            <i class="fas fa-tasks" style="font-size: 24px; margin-bottom: 8px; opacity: 0.3;"></i>
                            <p style="margin: 0;">No subtasks yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($subtasks as $subtask): ?>
                            <div class="subtask-item">
                                <input type="checkbox" class="subtask-checkbox" <?php echo !empty($subtask['completed']) ? 'checked' : ''; ?> 
                                       onchange="updateSubtask(<?php echo $subtask['id']; ?>, this.checked)">
                                <span class="subtask-text <?php echo !empty($subtask['completed']) ? 'completed' : ''; ?>">
                                    <?php echo htmlspecialchars($subtask['title']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (isAdmin() || isProjectManager() || $task['assignee_id'] == $_SESSION['user_id']): ?>
                        <button class="add-subtask-btn" onclick="addNewSubtask()">
                            <i class="fas fa-plus"></i>
                            <span>Add subtask</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Main Content -->
        <div class="task-main-content-new">
            <!-- Main Tabs -->
            <div style="background: var(--card-bg); border-radius: 10px; border: 1px solid var(--border-color); box-shadow: 0 1px 3px var(--shadow);">
                <div class="tab-navigation" style="padding: 0 24px; margin: 0;">
                    <button class="tab-btn active" data-tab="description" onclick="switchMainTab('description')" style="padding: 12px 20px; background: none; border: none; border-bottom: 2px solid var(--blue); color: var(--blue); font-weight: 600; font-size: 14px; cursor: pointer; margin-bottom: -2px;">
                        Description
                    </button>
                    <button class="tab-btn" data-tab="activity" onclick="switchMainTab('activity')" style="padding: 12px 20px; background: none; border: none; border-bottom: 2px solid transparent; color: var(--text-secondary); font-weight: 500; font-size: 14px; cursor: pointer; margin-bottom: -2px;">
                        Activity (<?php echo count($activities); ?>)
                    </button>
                    <button class="tab-btn" data-tab="attachments" onclick="switchMainTab('attachments')" style="padding: 12px 20px; background: none; border: none; border-bottom: 2px solid transparent; color: var(--text-secondary); font-weight: 500; font-size: 14px; cursor: pointer; margin-bottom: -2px;">
                        Attachments
                    </button>
                    <button class="tab-btn" data-tab="timelog" onclick="switchMainTab('timelog')" style="padding: 12px 20px; background: none; border: none; border-bottom: 2px solid transparent; color: var(--text-secondary); font-weight: 500; font-size: 14px; cursor: pointer; margin-bottom: -2px;">
                        Time Log
                    </button>
                </div>
                
                <!-- Tab Content: Description -->
                <div id="main-tab-description" class="tab-content active" style="padding: 24px;">
                    <div class="task-description-section" style="position: relative;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                            <h3 style="font-size: 16px; font-weight: 600; color: var(--text-primary); margin: 0;">Description</h3>
                            <button type="button" onclick="editDescription()" 
                                    style="background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 6px 12px; border-radius: 4px; transition: all 0.2s; display: flex; align-items: center; gap: 6px; font-size: 14px;"
                                    onmouseover="this.style.background='var(--blue-light)'; this.style.color='var(--blue)'"
                                    onmouseout="this.style.background='none'; this.style.color='var(--text-muted)'"
                                    id="edit-description-btn">
                                <i class="fas fa-edit" style="font-size: 14px;"></i>
                                <span>Edit</span>
                            </button>
                        </div>
                        <div id="task-description-display" class="task-description-text">
                            <?php
                            $description = trim($task['description'] ?? '');
                            if ($description === '') {
                                echo '<p style="color: var(--text-muted); font-style: italic;">No description provided</p>';
                            } else {
                                echo renderRichText($description);
                            }
                            ?>
                        </div>
                        <div id="task-description-edit" style="display: none;">
                            <form method="POST" action="" id="descriptionEditForm">
                                <textarea name="description" id="task-description-editor" 
                                          style="width: 100%; min-height: 300px; padding: 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; font-family: inherit; resize: vertical;">
                                    <?php echo htmlspecialchars($task['description'] ?? ''); ?>
                                </textarea>
                                <div style="display: flex; gap: 8px; margin-top: 12px; justify-content: flex-end;">
                                    <button type="submit" name="update_description" value="1" 
                                            style="padding: 10px 20px; background: var(--blue); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">
                                        <i class="fas fa-check"></i> Save
                                    </button>
                                    <button type="button" onclick="cancelDescriptionEdit()" 
                                            style="padding: 10px 20px; background: var(--text-muted); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Tab Content: Activity -->
                <div id="main-tab-activity" class="tab-content" style="display: none; padding: 24px;">
                    <div class="activity-section">
                        <div class="activity-list">
                            <?php if (empty($activities)): ?>
                                <div class="no-activity">
                                    <i class="fas fa-history" style="font-size: 32px; margin-bottom: 12px; opacity: 0.3;"></i>
                                    <p>No activity recorded yet.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($activities as $activity): 
                                    $activity_initials = getInitials($activity['user_name']);
                                ?>
                                    <div class="activity-item-new">
                                        <div class="activity-avatar">
                                            <?php echo htmlspecialchars($activity_initials); ?>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-text">
                                                <strong><?php echo htmlspecialchars($activity['user_name']); ?></strong> 
                                                <?php echo htmlspecialchars($activity['action']); ?>
                                                <?php if ($activity['old_value'] && $activity['new_value']): ?>
                                                    from <strong><?php echo htmlspecialchars($activity['old_value']); ?></strong> 
                                                    to <strong><?php echo htmlspecialchars($activity['new_value']); ?></strong>
                                                <?php endif; ?>
                                            </div>
                                            <div class="activity-time">
                                                <?php echo formatDateTime($activity['created_at']); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Tab Content: Attachments -->
                <div id="main-tab-attachments" class="tab-content" style="display: none; padding: 24px;">
                    <?php 
                    // Reuse attachments data from nested tab
                    if (empty($attachments)): 
                    ?>
                        <div style="padding: 40px 20px; text-align: center; color: var(--text-muted);">
                            <i class="fas fa-paperclip" style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;"></i>
                            <p style="margin: 0; font-size: 16px;">No attachments</p>
                        </div>
                    <?php else: ?>
                        <div class="attachments-grid">
                            <?php foreach ($attachments as $attachment): 
                                $file_icon = getFileIcon($attachment['mime_type'] ?? '', $attachment['filename']);
                                $file_size = formatFileSize($attachment['file_size'] ?? 0);
                                $file_date = date('d-m-Y, H:i A', strtotime($attachment['created_at']));
                            ?>
                                <div class="attachment-card" onclick="window.open('<?php echo htmlspecialchars($attachment['file_path']); ?>', '_blank');">
                                    <div class="attachment-preview">
                                        <i class="fas <?php echo $file_icon; ?>"></i>
                                    </div>
                                    <div class="attachment-info">
                                        <div class="attachment-name" title="<?php echo htmlspecialchars($attachment['filename']); ?>">
                                            <?php echo htmlspecialchars($attachment['filename']); ?>
                                        </div>
                                        <div class="attachment-meta">
                                            <?php echo $file_date; ?> | <?php echo $file_size; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Tab Content: Time Log -->
                <div id="main-tab-timelog" class="tab-content" style="display: none; padding: 24px;">
                    <p style="color: var(--text-muted);">No time logged</p>
                </div>
            </div>

            <!-- Comments and Activity Section -->
            <div class="comments-activity-section">
                <!-- Left: Comments Panel -->
                <div class="comments-panel">
                    <div class="comments-panel-header">
                        <div class="nested-tabs" style="border: none; margin: 0; padding: 0;">
                        Comments   
                        </div>
                    </div>
                    
                    <!-- Comment Input -->
                    <div class="comment-input-area">
                        <form method="POST" action="" id="commentForm">
                            <textarea 
                                id="comment" 
                                name="comment" 
                                required 
                                maxlength="1000"
                                placeholder="Write a comment..."
                                class="comment-textarea"
                                oninput="updateCommentCounter(this); handleMention(this)"
                                onkeydown="handleMentionKeydown(event, this)"
                            ></textarea>
                            <div id="mention-autocomplete" class="mention-autocomplete"></div>
                            <div class="comment-actions-bar">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <button type="button" class="comment-attach-btn">
                                        <i class="fas fa-paperclip"></i>
                                        <span>Attach File</span>
                                    </button>
                                    <div class="comment-avatars">
                                        <?php 
                                        // Show recent commenters avatars
                                        $recent_commenters = array_slice($comments, 0, 3);
                                        foreach ($recent_commenters as $commenter):
                                            $commenter_initials = getInitials($commenter['user_name']);
                                        ?>
                                            <div class="comment-avatar-small" title="<?php echo htmlspecialchars($commenter['user_name']); ?>">
                                                <?php echo htmlspecialchars($commenter_initials); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <button type="submit" name="add_comment" class="task-action-btn" style="background: var(--blue); color: white; border: none;">
                                    <i class="fas fa-paper-plane"></i>
                                    <span>Send</span>
                                </button>
                            </div>
                            <input type="hidden" name="parent_id" id="parent_id" value="">
                        </form>
                    </div>
                    
                    <!-- Recent Comments -->
                    <div class="comments-list" style="max-height: 400px; overflow-y: auto;">
                        <?php if (empty($comments)): ?>
                            <div class="no-comments" style="text-align: center; padding: 30px; color: var(--text-muted); font-size: 13px;">
                                <i class="fas fa-comment-slash" style="font-size: 24px; margin-bottom: 8px; opacity: 0.3;"></i>
                                <p>No comments yet. Start the discussion!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach (array_slice($comments, 0, 5) as $comment): 
                                $name_parts = explode(' ', $comment['user_name']);
                                $initials = '';
                                if (count($name_parts) >= 2) {
                                    $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts) - 1], 0, 1));
                                } else {
                                    $initials = strtoupper(substr($comment['user_name'], 0, 2));
                                }
                                
                                $comment_text = htmlspecialchars($comment['comment']);
                                $comment_text = preg_replace('/@(\w+)/', '<span class="mention">@$1</span>', $comment_text);
                            ?>
                                <div class="comment-item" id="comment-<?php echo $comment['id']; ?>">
                                    <div class="comment-avatar">
                                        <?php echo htmlspecialchars($initials); ?>
                                    </div>
                                    <div class="comment-content">
                                        <div class="comment-header">
                                            <span class="comment-author"><?php echo htmlspecialchars($comment['user_name']); ?></span>
                                            <span class="comment-date"><?php echo date('d-m-Y H:i A', strtotime($comment['created_at'])); ?></span>
                                        </div>
                                        <div class="comment-text">
                                            <?php echo nl2br($comment_text); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                </div>
                
               
            </div>
        </div>
    </div>
</div>

<script>
// Main Tab switching function
function switchMainTab(tabName) {
    // Hide all main tab contents
    document.querySelectorAll('#main-tab-description, #main-tab-activity, #main-tab-attachments, #main-tab-timelog').forEach(content => {
        content.style.display = 'none';
    });
    
    // Remove active class from all main tab buttons
    document.querySelectorAll('.tab-navigation .tab-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.style.borderBottom = '2px solid transparent';
        btn.style.color = 'var(--text-secondary)';
        btn.style.fontWeight = '500';
    });
    
    // Show selected tab content
    const selectedTab = document.getElementById('main-tab-' + tabName);
    if (selectedTab) {
        selectedTab.style.display = 'block';
    }
    
    // Add active class to selected tab button
    const selectedBtn = document.querySelector('[data-tab="' + tabName + '"]');
    if (selectedBtn) {
        selectedBtn.classList.add('active');
        selectedBtn.style.borderBottom = '2px solid var(--blue)';
        selectedBtn.style.color = 'var(--blue)';
        selectedBtn.style.fontWeight = '600';
    }
}

// Nested Tab switching function
function switchNestedTab(tabName) {
    // Hide all nested tab contents
    document.querySelectorAll('#nested-desc, #nested-activity, #nested-attachments, #nested-timelog').forEach(content => {
        content.style.display = 'none';
    });
    
    // Remove active class from all nested tab buttons
    document.querySelectorAll('.nested-tabs .nested-tab-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.style.borderBottom = '2px solid transparent';
        btn.style.color = 'var(--text-secondary)';
        btn.style.fontWeight = '500';
    });
    
    // Show selected tab content
    const selectedTab = document.getElementById(tabName);
    if (selectedTab) {
        selectedTab.style.display = 'block';
    }
    
    // Add active class to selected tab button
    const selectedBtn = event.target.closest('.nested-tab-btn');
    if (selectedBtn) {
        selectedBtn.classList.add('active');
        selectedBtn.style.borderBottom = '2px solid var(--blue)';
        selectedBtn.style.color = 'var(--blue)';
        selectedBtn.style.fontWeight = '600';
    }
}

// Comments Tab switching function
function switchCommentsTab(tabName) {
    // Hide all comment tab contents
    document.querySelectorAll('#comments-activity, #comments-attachments, #comments-timelog').forEach(content => {
        if (content) content.style.display = 'none';
    });
    
    // Remove active class from all comment tab buttons
    document.querySelectorAll('.comments-panel-header .nested-tab-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.style.borderBottom = '2px solid transparent';
        btn.style.color = 'var(--text-secondary)';
        btn.style.fontWeight = '500';
    });
    
    // Show selected tab content
    const selectedTab = document.getElementById(tabName);
    if (selectedTab) {
        selectedTab.style.display = 'block';
    }
    
    // Add active class to selected tab button
    const selectedBtn = event.target.closest('.nested-tab-btn');
    if (selectedBtn) {
        selectedBtn.classList.add('active');
        selectedBtn.style.borderBottom = '2px solid var(--blue)';
        selectedBtn.style.color = 'var(--blue)';
        selectedBtn.style.fontWeight = '600';
    }
}

// Update task priority
function updateTaskPriority(taskId, priority) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    const updateInput = document.createElement('input');
    updateInput.type = 'hidden';
    updateInput.name = 'update_priority';
    updateInput.value = '1';
    form.appendChild(updateInput);
    const priorityInput = document.createElement('input');
    priorityInput.type = 'hidden';
    priorityInput.name = 'priority';
    priorityInput.value = priority;
    form.appendChild(priorityInput);
    document.body.appendChild(form);
    form.submit();
}

// Update subtask
function updateSubtask(subtaskId, completed) {
    // You can implement AJAX call here to update subtask
    console.log('Updating subtask', subtaskId, completed);
    // For now, just update the UI
    const checkbox = event.target;
    const text = checkbox.nextElementSibling;
    if (completed) {
        text.classList.add('completed');
    } else {
        text.classList.remove('completed');
    }
}

// Add new subtask
function addNewSubtask() {
    const title = prompt('Enter subtask title:');
    if (title && title.trim()) {
        // You can implement AJAX call here to add subtask
        console.log('Adding subtask:', title);
        // For now, just show a message
        alert('Subtask functionality will be implemented. Title: ' + title);
    }
}

// Helper function to generate initials from full name
function getInitials(full_name) {
    const name = full_name.trim();
    const words = name.split(' ').filter(word => word.trim() !== '');
    
    if (words.length >= 2) {
        return (words[0][0] + words[words.length - 1][0]).toUpperCase();
    } else {
        return name.substring(0, 2).toUpperCase();
    }
}

// Users data for @mentions
const usersData = <?php echo json_encode($users_list); ?>;

let mentionAutocomplete = null;
let mentionStartPos = -1;
let selectedMentionIndex = -1;

function updateCommentCounter(textarea) {
    const counter = textarea.closest('form').querySelector('[id*="comment-counter"]') || document.getElementById('comment-counter');
    if (!counter) return;
    
    const currentLength = textarea.value.length;
    const maxLength = textarea.getAttribute('maxlength');
    counter.textContent = currentLength + ' / ' + maxLength;
    
    if (currentLength > maxLength * 0.9) {
        counter.style.color = '#ef4444';
    } else if (currentLength > maxLength * 0.75) {
        counter.style.color = '#f59e0b';
    } else {
        counter.style.color = '#94a3b8';
    }
}

function handleMention(textarea) {
    const value = textarea.value;
    const cursorPos = textarea.selectionStart;
    const textBeforeCursor = value.substring(0, cursorPos);
    const lastAtIndex = textBeforeCursor.lastIndexOf('@');
    
    if (lastAtIndex !== -1) {
        const textAfterAt = textBeforeCursor.substring(lastAtIndex + 1);
        // Check if we're still typing a mention (no space after @)
        if (!textAfterAt.includes(' ') && !textAfterAt.includes('\n')) {
            mentionStartPos = lastAtIndex;
            const searchTerm = textAfterAt.toLowerCase();
            showMentionAutocomplete(textarea, searchTerm, lastAtIndex);
            return;
        }
    }
    
    hideMentionAutocomplete(textarea);
}

function showMentionAutocomplete(textarea, searchTerm, position) {
    const filteredUsers = usersData.filter(user => 
        user.full_name.toLowerCase().includes(searchTerm) || 
        (user.username && user.username.toLowerCase().includes(searchTerm))
    );
    
    if (filteredUsers.length === 0) {
        hideMentionAutocomplete(textarea);
        return;
    }
    
    const form = textarea.closest('form');
    let autocomplete = form.querySelector('.mention-autocomplete');
    if (!autocomplete) {
        autocomplete = document.createElement('div');
        autocomplete.className = 'mention-autocomplete';
        form.appendChild(autocomplete);
    }
    
    autocomplete.innerHTML = '';
    selectedMentionIndex = -1;
    
    filteredUsers.forEach((user, index) => {
        const item = document.createElement('div');
        item.className = 'mention-item' + (index === 0 ? ' selected' : '');
        
        const nameParts = user.full_name.split(' ');
        const initials = nameParts.length >= 2 
            ? (nameParts[0][0] + nameParts[nameParts.length - 1][0]).toUpperCase()
            : user.full_name.substring(0, 2).toUpperCase();
        
        item.innerHTML = `
            <div class="mention-item-avatar">${initials}</div>
            <div class="mention-item-name">${user.full_name}</div>
        `;
        
        item.onclick = () => insertMention(textarea, user, position);
        autocomplete.appendChild(item);
    });
    
    autocomplete.classList.add('active');
    positionAutocomplete(textarea, autocomplete);
}

function positionAutocomplete(textarea, autocomplete) {
    const rect = textarea.getBoundingClientRect();
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
    autocomplete.style.top = (rect.bottom + scrollTop) + 'px';
    autocomplete.style.left = rect.left + 'px';
}

function hideMentionAutocomplete(textarea) {
    const form = textarea.closest('form');
    const autocomplete = form.querySelector('.mention-autocomplete');
    if (autocomplete) {
        autocomplete.classList.remove('active');
    }
    selectedMentionIndex = -1;
}

function insertMention(textarea, user, startPos) {
    const value = textarea.value;
    const cursorPos = textarea.selectionStart;
    const textBefore = value.substring(0, startPos);
    const textAfter = value.substring(cursorPos);
    const newValue = textBefore + '@' + user.full_name + ' ' + textAfter;
    
    textarea.value = newValue;
    const newCursorPos = startPos + user.full_name.length + 2;
    textarea.setSelectionRange(newCursorPos, newCursorPos);
    textarea.focus();
    
    hideMentionAutocomplete(textarea);
    updateCommentCounter(textarea);
}

function handleMentionKeydown(event, textarea) {
    const autocomplete = textarea.closest('form').querySelector('.mention-autocomplete');
    if (!autocomplete || !autocomplete.classList.contains('active')) return;
    
    const items = autocomplete.querySelectorAll('.mention-item');
    if (items.length === 0) return;
    
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        selectedMentionIndex = Math.min(selectedMentionIndex + 1, items.length - 1);
        updateMentionSelection(items);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        selectedMentionIndex = Math.max(selectedMentionIndex - 1, -1);
        updateMentionSelection(items);
    } else if (event.key === 'Enter' || event.key === 'Tab') {
        if (selectedMentionIndex >= 0 && selectedMentionIndex < items.length) {
            event.preventDefault();
            items[selectedMentionIndex].click();
        }
    } else if (event.key === 'Escape') {
        hideMentionAutocomplete(textarea);
    }
}

function updateMentionSelection(items) {
    items.forEach((item, index) => {
        if (index === selectedMentionIndex) {
            item.classList.add('selected');
            item.scrollIntoView({ block: 'nearest' });
        } else {
            item.classList.remove('selected');
        }
    });
}

function showReplyForm(commentId) {
    const replyForm = document.getElementById('reply-form-' + commentId);
    if (replyForm) {
        replyForm.classList.add('active');
        const textarea = replyForm.querySelector('textarea');
        if (textarea) {
            textarea.focus();
        }
    }
}

function hideReplyForm(commentId) {
    const replyForm = document.getElementById('reply-form-' + commentId);
    if (replyForm) {
        replyForm.classList.remove('active');
        const textarea = replyForm.querySelector('textarea');
        if (textarea) {
            textarea.value = '';
        }
    }
}

// Initialize counter on page load
document.addEventListener('DOMContentLoaded', function() {
    const commentTextarea = document.getElementById('comment');
    if (commentTextarea) {
        updateCommentCounter(commentTextarea);
    }
    
    // Close autocomplete when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.mention-autocomplete') && !e.target.closest('textarea')) {
            document.querySelectorAll('.mention-autocomplete').forEach(el => {
                el.classList.remove('active');
            });
        }
    });
});
</script>

<script src="https://cdn.ckeditor.com/ckeditor5/41.3.1/classic/ckeditor.js"></script>
<script>
// CKEditor instance for description
let descriptionEditor = null;

// Edit Title Function
function editTitle() {
    const displayDiv = document.getElementById('task-title-display');
    const editDiv = document.getElementById('task-title-edit');
    const titleInput = document.getElementById('task-title-input');
    
    if (displayDiv && editDiv) {
        displayDiv.style.display = 'none';
        editDiv.style.display = 'flex';
        titleInput.focus();
        titleInput.select();
    }
}

// Cancel Title Edit
function cancelTitleEdit() {
    const displayDiv = document.getElementById('task-title-display');
    const editDiv = document.getElementById('task-title-edit');
    const titleInput = document.getElementById('task-title-input');
    
    if (displayDiv && editDiv) {
        // Reset to original value
        titleInput.value = document.getElementById('task-title-text').textContent.trim();
        displayDiv.style.display = 'block';
        editDiv.style.display = 'none';
    }
}

// Edit Description Function
function editDescription() {
    const displayDiv = document.getElementById('task-description-display');
    const editDiv = document.getElementById('task-description-edit');
    const editBtn = document.getElementById('edit-description-btn');
    const editorTextarea = document.getElementById('task-description-editor');
    
    if (displayDiv && editDiv) {
        displayDiv.style.display = 'none';
        editDiv.style.display = 'block';
        editBtn.style.display = 'none';
        
        // Initialize CKEditor if not already initialized
        if (!descriptionEditor && editorTextarea) {
            ClassicEditor
                .create(editorTextarea, {
                    toolbar: ['heading', '|', 'bold', 'italic', 'underline', '|', 'bulletedList', 'numberedList', '|', 'link', 'blockQuote', '|', 'undo', 'redo'],
                    heading: {
                        options: [
                            { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                            { model: 'heading1', view: 'h1', title: 'Heading 1', class: 'ck-heading_heading1' },
                            { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                            { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' }
                        ]
                    }
                })
                .then(editor => {
                    descriptionEditor = editor;
                })
                .catch(error => {
                    console.error('Error initializing CKEditor:', error);
                });
        }
    }
}

// Cancel Description Edit
function cancelDescriptionEdit() {
    const displayDiv = document.getElementById('task-description-display');
    const editDiv = document.getElementById('task-description-edit');
    const editBtn = document.getElementById('edit-description-btn');
    
    if (displayDiv && editDiv) {
        displayDiv.style.display = 'block';
        editDiv.style.display = 'none';
        editBtn.style.display = 'flex';
        
        // Destroy CKEditor instance if it exists
        if (descriptionEditor) {
            descriptionEditor.destroy()
                .then(() => {
                    descriptionEditor = null;
                })
                .catch(error => {
                    console.error('Error destroying CKEditor:', error);
                });
        }
    }
}

// Handle form submission for description - get data from CKEditor
document.addEventListener('DOMContentLoaded', function() {
    const descriptionForm = document.getElementById('descriptionEditForm');
    if (descriptionForm) {
        descriptionForm.addEventListener('submit', function(e) {
            if (descriptionEditor) {
                // Update textarea with CKEditor content before form submission
                const editorData = descriptionEditor.getData();
                document.getElementById('task-description-editor').value = editorData;
            }
        });
    }
});
</script>

<?php include 'includes/footer.php'; ?>
