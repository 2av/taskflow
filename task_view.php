<?php
require_once 'config/config.php';
requireLogin();
requireActiveSubscription();

$page_title = 'Task Details';

$conn = getDBConnection();
$message = '';
$error = '';

// Get task ID
$task_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($task_id == 0) {
    header('Location: tasks.php');
    exit();
}

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
    header('Location: tasks.php');
    exit();
}

// Check permission for team members
if (!isAdmin() && !isProjectManager()) {
    $user_id = $_SESSION['user_id'];
    $is_assigned = $task['assignee_id'] == $user_id;
    $is_in_project = $conn->query("SELECT COUNT(*) as count FROM project_users WHERE project_id = {$task['project_id']} AND user_id = $user_id")->fetch_assoc()['count'] > 0;
    
    if (!$is_assigned && !$is_in_project) {
        header('Location: tasks.php');
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
                header('Location: task_view.php?id=' . $task_id);
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
    font-size: 28px;
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
    grid-template-columns: 1fr 380px;
    gap: 24px;
    margin-bottom: 24px;
}

@media (max-width: 1024px) {
    .task-main-content {
        grid-template-columns: 1fr;
    }
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
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.task-info-card {
    background: #ffffff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
}

.task-info-card h4 {
    font-size: 16px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 16px 0;
    padding-bottom: 12px;
    border-bottom: 2px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f1f5f9;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #64748b;
    font-size: 14px;
}

.info-value {
    color: #1e293b;
    font-size: 14px;
    text-align: right;
    flex: 1;
    margin-left: 16px;
}

.info-value a {
    color: #14b8a6;
    text-decoration: none;
    font-weight: 600;
    transition: color 0.2s;
}

.info-value a:hover {
    color: #0d9488;
    text-decoration: underline;
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

<div class="task-view-container">
    <!-- Page Header -->
    <div class="page-header" style="margin-bottom: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="tasks.php" class="btn btn-secondary" title="Back to Tasks" style="padding: 10px 12px; min-width: auto; display: inline-flex; align-items: center; justify-content: center;">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h1 class="page-title" style="margin: 0;">Task Details</h1>
            </div>
    </div>
</div>

<?php if ($message): ?>
        <div class="alert alert-success" style="margin-bottom: 20px;"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom: 20px;"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

    <!-- Task Header Section -->
    <div class="task-header-section">
        <div class="task-header-top">
            <div class="task-title-section">
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px;">
                    <span class="task-id-badge">
                        <i class="fas fa-hashtag"></i>
                        <?php echo htmlspecialchars($task['task_id']); ?>
                    </span>
                    <span class="task-type-badge" style="background: <?php echo $type_color; ?>20; color: <?php echo $type_color; ?>;">
                        <i class="fas <?php echo $type_icon; ?>"></i>
                        <?php echo htmlspecialchars($task['type']); ?>
                    </span>
                    <span class="badge priority-<?php echo strtolower($task['priority']); ?>" style="font-size: 13px;">
                        <?php echo htmlspecialchars($task['priority']); ?>
                    </span>
                    <span class="badge status-<?php echo strtolower(str_replace(' ', '-', $task['status'])); ?>" style="font-size: 13px;">
                        <?php echo htmlspecialchars($task['status']); ?>
                    </span>
                </div>
                <h2 class="task-title"><?php echo htmlspecialchars($task['title']); ?></h2>
            </div>
            <div class="task-actions">
                <?php if ($task['assignee_id'] != $_SESSION['user_id']): ?>
                    <form method="POST" action="" style="display: inline-block;">
                        <input type="hidden" name="assign_to_me" value="1">
                        <button type="submit" class="btn btn-primary" title="Assign to Me" style="padding: 10px 12px; min-width: auto; display: inline-flex; align-items: center; justify-content: center; margin-right: 8px;">
                            <i class="fas fa-user-plus"></i>
                        </button>
                    </form>
                <?php endif; ?>
                <?php if (isAdmin() || isProjectManager() || $task['assignee_id'] == $_SESSION['user_id']): ?>
                    <a href="tasks.php?edit=<?php echo $task['id']; ?>" class="btn btn-warning" title="Edit Task" style="padding: 10px 12px; min-width: auto; display: inline-flex; align-items: center; justify-content: center;">
                        <i class="fas fa-edit"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="task-main-content">
        <!-- Left Column: Task Details & Description -->
        <div class="task-details-card">
            <h3>
                <i class="fas fa-info-circle" style="color: #14b8a6;"></i>
                Description
            </h3>
            <div class="task-description">
                <?php echo nl2br(htmlspecialchars($task['description'] ?? 'No description provided')); ?>
            </div>
        </div>

        <!-- Right Column: Task Information Sidebar -->
        <div class="task-sidebar">
            <!-- Task Information Card -->
            <div class="task-info-card">
                <h4>
                    <i class="fas fa-clipboard-list" style="color: #14b8a6;"></i>
                    Task Information
                </h4>
                
                <div class="info-row">
                    <span class="info-label">Project:</span>
                    <span class="info-value">
                        <a href="tasks.php?project_id=<?php echo $task['project_id']; ?>">
                            <i class="fas fa-folder" style="margin-right: 4px;"></i>
                            <?php echo htmlspecialchars($task['project_name']); ?>
                        </a>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Assignee:</span>
                    <span class="info-value">
                        <form method="POST" action="" style="display: inline-flex; align-items: center; gap: 8px;">
                            <select name="assignee_id" onchange="this.form.submit()" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; background: #ffffff; color: #1e293b; min-width: 150px;">
                                <option value="">Unassigned</option>
                                <?php foreach ($users_list as $user): ?>
                                    <option value="<?php echo $user['id']; ?>" <?php echo ($task['assignee_id'] == $user['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="update_assignee" value="1">
                        </form>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Due Date:</span>
                    <span class="info-value">
                        <form method="POST" action="" style="display: inline-flex; align-items: center; gap: 8px;">
                            <input type="date" name="due_date" value="<?php echo $task['due_date'] ? date('Y-m-d', strtotime($task['due_date'])) : ''; ?>" onchange="this.form.submit()" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; background: #ffffff; color: #1e293b;">
                            <input type="hidden" name="update_due_date" value="1">
                            <?php 
                            if ($task['due_date'] && strtotime($task['due_date']) < time() && $task['status'] != 'Done'):
                            ?>
                                <span class="overdue-badge" style="font-size: 12px;">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Overdue
                                </span>
                            <?php endif; ?>
                        </form>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Created By:</span>
                    <span class="info-value">
                        <i class="fas fa-user-plus" style="margin-right: 4px; color: #64748b;"></i>
                        <?php echo htmlspecialchars($task['creator_name']); ?>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Created:</span>
                    <span class="info-value">
                        <i class="fas fa-clock" style="margin-right: 4px; color: #64748b;"></i>
                        <?php echo formatDateTime($task['created_at']); ?>
                    </span>
            </div>

                <div class="info-row">
                    <span class="info-label">Last Updated:</span>
                    <span class="info-value">
                        <i class="fas fa-sync-alt" style="margin-right: 4px; color: #64748b;"></i>
                        <?php echo formatDateTime($task['updated_at']); ?>
                    </span>
        </div>
        
                <!-- Quick Status Update -->
        <?php if (isAdmin() || isProjectManager() || $task['assignee_id'] == $_SESSION['user_id']): ?>
                    <div class="status-update-form">
                        <h5><i class="fas fa-edit"></i> Quick Status Update</h5>
                        <form method="POST" action="">
                            <select name="status" required>
                        <option value="To Do" <?php echo $task['status'] == 'To Do' ? 'selected' : ''; ?>>To Do</option>
                        <option value="In Progress" <?php echo $task['status'] == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="Done" <?php echo $task['status'] == 'Done' ? 'selected' : ''; ?>>Done</option>
                    </select>
                            <button type="submit" name="update_status" class="btn btn-primary" title="Update Status">
                                <i class="fas fa-save"></i>
                            </button>
                </form>
            </div>
        <?php endif; ?>
            </div>
    </div>
</div>

<!-- Comments Section -->
<div class="comments-section">
        <h3>
            <i class="fas fa-comments" style="color: #14b8a6;"></i>
            Comments (<?php echo count($comments); ?>)
        </h3>
    
        <!-- Comment Form -->
        <div class="comment-form" style="position: relative;">
            <form method="POST" action="" id="commentForm">
                <textarea 
                    id="comment" 
                    name="comment" 
                    required 
                    maxlength="1000"
                    placeholder="Write your comment here... Use @ to mention someone"
                    oninput="updateCommentCounter(this); handleMention(this)"
                    onkeydown="handleMentionKeydown(event, this)"
                ></textarea>
                <div id="mention-autocomplete" class="mention-autocomplete"></div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px; gap: 12px;">
                    <span id="comment-counter" style="font-size: 10px; color: #94a3b8;">0 / 1000</span>
                    <button type="submit" name="add_comment" class="btn btn-primary" style="padding: 6px 14px; font-size: 12px; font-weight: 500; border-radius: 5px; display: inline-flex; align-items: center; gap: 5px;">
                        <i class="fas fa-paper-plane"></i> Send
                    </button>
                </div>
                <input type="hidden" name="parent_id" id="parent_id" value="">
            </form>
        </div>
    
        <!-- Comments List -->
        <div class="comments-list">
        <?php if (empty($comments)): ?>
                <div class="no-comments" style="text-align: center; padding: 30px; color: #94a3b8; font-size: 13px;">
                    <i class="fas fa-comment-slash" style="font-size: 24px; margin-bottom: 8px; opacity: 0.3;"></i>
                    <p>No comments yet. Start the discussion!</p>
                </div>
        <?php else: ?>
            <?php foreach ($comments as $comment): 
                // Get initials for avatar
                $name_parts = explode(' ', $comment['user_name']);
                $initials = '';
                if (count($name_parts) >= 2) {
                    $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts) - 1], 0, 1));
                } else {
                    $initials = strtoupper(substr($comment['user_name'], 0, 2));
                }
                
                // Process mentions in comment text
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
                                <span class="comment-date"><?php echo formatDateTime($comment['created_at']); ?></span>
                            </div>
                            <div class="comment-text">
                                <?php echo nl2br($comment_text); ?>
                            </div>
                            <div class="comment-actions">
                                <button type="button" class="comment-reply-btn" onclick="showReplyForm(<?php echo $comment['id']; ?>)">
                                    <i class="fas fa-reply"></i> Reply
                                </button>
                            </div>
                            
                            <!-- Reply Form -->
                            <div class="reply-form" id="reply-form-<?php echo $comment['id']; ?>">
                                <form method="POST" action="" id="reply-form-<?php echo $comment['id']; ?>-form">
                                    <textarea 
                                        name="comment" 
                                        required 
                                        maxlength="1000"
                                        placeholder="Write a reply..."
                                        oninput="updateCommentCounter(this); handleMention(this)"
                                        onkeydown="handleMentionKeydown(event, this)"
                                    ></textarea>
                                    <div id="mention-autocomplete-reply-<?php echo $comment['id']; ?>" class="mention-autocomplete"></div>
                                    <input type="hidden" name="parent_id" id="parent_id_<?php echo $comment['id']; ?>" value="<?php echo intval($comment['id']); ?>">
                                    <div class="reply-form-actions">
                                        <button type="button" class="btn btn-secondary" onclick="hideReplyForm(<?php echo $comment['id']; ?>)" style="padding: 4px 10px; font-size: 11px;">Cancel</button>
                                        <button type="submit" name="add_comment" class="btn btn-primary" style="padding: 4px 10px; font-size: 11px;">
                                            <i class="fas fa-paper-plane"></i> Reply
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Replies -->
                            <?php if (!empty($comment['replies'])): ?>
                                <?php foreach ($comment['replies'] as $reply): 
                                    $reply_name_parts = explode(' ', $reply['user_name']);
                                    $reply_initials = '';
                                    if (count($reply_name_parts) >= 2) {
                                        $reply_initials = strtoupper(substr($reply_name_parts[0], 0, 1) . substr($reply_name_parts[count($reply_name_parts) - 1], 0, 1));
                                    } else {
                                        $reply_initials = strtoupper(substr($reply['user_name'], 0, 2));
                                    }
                                    $reply_text = htmlspecialchars($reply['comment']);
                                    $reply_text = preg_replace('/@(\w+)/', '<span class="mention">@$1</span>', $reply_text);
                                ?>
                                    <div class="comment-item comment-reply">
                                        <div class="comment-avatar">
                                            <?php echo htmlspecialchars($reply_initials); ?>
                                        </div>
                                        <div class="comment-content">
                                            <div class="comment-header">
                                                <span class="comment-author"><?php echo htmlspecialchars($reply['user_name']); ?></span>
                                                <span class="comment-date"><?php echo formatDateTime($reply['created_at']); ?></span>
                                            </div>
                                            <div class="comment-text">
                                                <?php echo nl2br($reply_text); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

    <!-- Activity Log Section -->
    <div class="activity-section">
        <h3>
            <i class="fas fa-history" style="color: #14b8a6;"></i>
            Activity Log (<?php echo count($activities); ?>)
        </h3>
        
        <div class="activity-list">
        <?php if (empty($activities)): ?>
                <div class="no-activity">
                    <i class="fas fa-history" style="font-size: 32px; margin-bottom: 12px; opacity: 0.3;"></i>
                    <p>No activity recorded yet.</p>
                </div>
        <?php else: ?>
            <?php foreach ($activities as $activity): ?>
                    <div class="activity-item">
                        <div>
                    <strong><?php echo htmlspecialchars($activity['user_name']); ?></strong> 
                    <?php echo htmlspecialchars($activity['action']); ?>
                    <?php if ($activity['old_value'] && $activity['new_value']): ?>
                        from <strong><?php echo htmlspecialchars($activity['old_value']); ?></strong> 
                        to <strong><?php echo htmlspecialchars($activity['new_value']); ?></strong>
                    <?php endif; ?>
                        </div>
                        <span class="activity-date">
                            <i class="fas fa-clock" style="margin-right: 4px;"></i>
                        <?php echo formatDateTime($activity['created_at']); ?>
                    </span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>
</div>

<script>
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

<?php include 'includes/footer.php'; ?>
