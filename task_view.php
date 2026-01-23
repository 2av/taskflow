<?php
require_once 'config/config.php';
requireLogin();
requireActiveSubscription();

$page_title = 'Task Details';

$conn = getDBConnection();
$message = '';
$error = '';

// Get organization-specific statuses
$organization_id = isSuperAdmin() ? null : getOrganizationId();
$statuses = getStatuses($organization_id);

function renderRichText($content) {
    if (empty($content)) {
        return '';
    }
    
    $allowed_tags = '<p><br><br/><strong><b><em><i><u><ol><ul><li><span><div><blockquote><a><h1><h2><h3><h4><h5><h6><code><img>';
    $sanitized = strip_tags($content, $allowed_tags);
    
    // Remove inline event handlers to prevent XSS
    $sanitized = preg_replace('/ on\w+="[^"]*"/i', '', $sanitized);
    $sanitized = preg_replace("/ on\w+='[^']*'/i", '', $sanitized);
    
    // Prevent javascript: URLs in links
    $sanitized = preg_replace_callback('/href=("|\')(.*?)\1/i', function ($matches) {
        $url = trim($matches[2]);
        if (stripos($url, 'javascript:') === 0) {
            return 'href="#"';
        }
        return 'href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
    }, $sanitized);
    
    // Sanitize image src URLs to prevent XSS
    $sanitized = preg_replace_callback('/<img([^>]*)>/i', function ($matches) {
        $attrs = $matches[1];
        // Extract and sanitize src attribute
        if (preg_match('/src=("|\')(.*?)\1/i', $attrs, $src_match)) {
            $src = trim($src_match[2]);
            // Prevent javascript: URLs
            if (stripos($src, 'javascript:') === 0) {
                $attrs = preg_replace('/src=("|\')(.*?)\1/i', 'src="#"', $attrs);
            } else {
                $safe_src = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
                $attrs = preg_replace('/src=("|\')(.*?)\1/i', 'src="' . $safe_src . '"', $attrs);
            }
        }
        // Remove any dangerous attributes
        $attrs = preg_replace('/on\w+=/i', '', $attrs);
        return '<img' . $attrs . ' style="max-width: 100%; height: auto;">';
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

// Handle get time log for editing
if (isset($_GET['get_timelog'])) {
    $timelog_id = intval($_GET['get_timelog']);
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM time_logs WHERE id = ?");
    $stmt->bind_param("i", $timelog_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $timelog = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    header('Content-Type: application/json');
    if ($timelog) {
        echo json_encode(['success' => true, 'timelog' => $timelog]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Time log not found']);
    }
    exit();
}

// Check if in description edit mode
$edit_description_mode = isset($_GET['edit_description']) && $_GET['edit_description'] == '1';

// Get task details
$task = $conn->query("
    SELECT t.*, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name,
           s.name as status_name, s.color as status_color
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN users u ON t.assignee_id = u.id
    LEFT JOIN users u2 ON t.created_by = u2.id
    LEFT JOIN statuses s ON t.status_id = s.id
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

// Handle task update (all fields at once)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_task'])) {
    $new_title = trim($_POST['title'] ?? $task['title']);
    $new_description = trim($_POST['description'] ?? $task['description'] ?? '');
    $new_type = $_POST['type'] ?? $task['type'];
    $new_priority = $_POST['priority'] ?? $task['priority'];
    $new_status_id = intval($_POST['status_id'] ?? $task['status_id'] ?? 0);
    $new_assignee_id = !empty($_POST['assignee_id']) ? intval($_POST['assignee_id']) : null;
    $new_due_date = !empty($_POST['due_date']) ? trim($_POST['due_date']) : null;
    $user_id = $_SESSION['user_id'];
    
    // Validate
    if (empty($new_title)) {
        $error = 'Title cannot be empty';
    } else {
        // Get status name for backward compatibility
        $status_name = $task['status'] ?? 'Unknown';
        if ($new_status_id) {
            $status_name_query = $conn->prepare("SELECT name FROM statuses WHERE id = ?");
            $status_name_query->bind_param("i", $new_status_id);
            $status_name_query->execute();
            $status_name_result = $status_name_query->get_result();
            $status_name = $status_name_result->fetch_assoc()['name'] ?? 'Unknown';
            $status_name_query->close();
        }
        
        // Get old values for logging
        $old_task = $conn->query("SELECT * FROM tasks WHERE id = $task_id")->fetch_assoc();
        
        // Handle NULL due_date and assignee_id properly
        // Parameters order: title(s), description(s), type(s), priority(s), status_id(i), status(s), [assignee_id(i)], [due_date(s)], task_id(i)
        
        if (($new_due_date === null || $new_due_date === '') && $new_assignee_id === null) {
            // Both NULL: title, description, type, priority, status_id, status, task_id = 7 params
            $stmt = $conn->prepare("UPDATE tasks SET title=?, description=?, type=?, priority=?, status_id=?, status=?, assignee_id=NULL, due_date=NULL WHERE id=?");
            $stmt->bind_param("ssssisi", $new_title, $new_description, $new_type, $new_priority, $new_status_id, $status_name, $task_id);
        } elseif ($new_due_date === null || $new_due_date === '') {
            // Only due_date is NULL: title, description, type, priority, status_id, status, assignee_id, task_id = 8 params
            $stmt = $conn->prepare("UPDATE tasks SET title=?, description=?, type=?, priority=?, status_id=?, status=?, assignee_id=?, due_date=NULL WHERE id=?");
            $stmt->bind_param("ssssisii", $new_title, $new_description, $new_type, $new_priority, $new_status_id, $status_name, $new_assignee_id, $task_id);
        } elseif ($new_assignee_id === null) {
            // Only assignee_id is NULL: title, description, type, priority, status_id, status, due_date, task_id = 8 params
            $stmt = $conn->prepare("UPDATE tasks SET title=?, description=?, type=?, priority=?, status_id=?, status=?, assignee_id=NULL, due_date=? WHERE id=?");
            $stmt->bind_param("ssssissi", $new_title, $new_description, $new_type, $new_priority, $new_status_id, $status_name, $new_due_date, $task_id);
        } else {
            // Both have values: 9 parameters
            // Order: title(s), description(s), type(s), priority(s), status_id(i), status(s), assignee_id(i), due_date(s), task_id(i)
            // Type string should be: "ssssissii" where position 7=i (assignee_id), position 8=s (due_date)
            $stmt = $conn->prepare("UPDATE tasks SET title=?, description=?, type=?, priority=?, status_id=?, status=?, assignee_id=?, due_date=? WHERE id=?");
            // Parameters: title(s), desc(s), type(s), priority(s), status_id(i), status(s), assignee_id(i), due_date(s), id(i)
            // Type string: s-s-s-s-i-s-i-s-i = "ssssissii" (9 chars: positions 1-6=ssssis, 7=i for assignee_id, 8=s for due_date, 9=i for task_id)
            // Correct order: title(s), desc(s), type(s), priority(s), status_id(i), status(s), assignee_id(i), due_date(s), task_id(i)
            // Fix: assignee_id is integer (i), due_date is string (s)
            // Type string must be: s-s-s-s-i-s-i-s-i (9 chars: title,desc,type,priority,status_id,status,assignee_id,due_date,task_id)
            // Current wrong: "ssssissii" has pos7=s(pos7 should be i), pos8=i(pos8 should be s)
            // Correct: "ssssissii" where pos7=i, pos8=s
            $typeString = "ssssis" . "i" . "s" . "i"; // Explicitly construct: ssssis + i(assignee) + s(due_date) + i(task_id)
            $stmt->bind_param($typeString, $new_title, $new_description, $new_type, $new_priority, $new_status_id, $status_name, $new_assignee_id, $new_due_date, $task_id);
        }
        
        if ($stmt->execute()) {
            // Log changes
            if ($old_task['title'] != $new_title) {
                $action = "Title changed";
                $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
                $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_task['title'], $new_title);
                $stmt2->execute();
                $stmt2->close();
            }
            
            if ($old_task['description'] != $new_description) {
                $action = "Description changed";
                $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action) VALUES (?, ?, ?)");
                $stmt2->bind_param("iis", $task_id, $user_id, $action);
                $stmt2->execute();
                $stmt2->close();
            }
            
            if ($old_task['type'] != $new_type) {
                $action = "Type changed";
                $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
                $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_task['type'], $new_type);
                $stmt2->execute();
                $stmt2->close();
            }
            
            if ($old_task['status_id'] != $new_status_id) {
                $old_status_name = $old_task['status'] ?? 'Unknown';
                $action = "Status changed";
                $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
                $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_status_name, $status_name);
                $stmt2->execute();
                $stmt2->close();
            }
            
            if ($old_task['priority'] != $new_priority) {
                $action = "Priority changed";
                $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
                $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_task['priority'], $new_priority);
                $stmt2->execute();
                $stmt2->close();
            }
            
            if ($old_task['assignee_id'] != $new_assignee_id) {
                $action = "Assignee changed";
                $old_name = $old_task['assignee_id'] ? $conn->query("SELECT full_name FROM users WHERE id = " . $old_task['assignee_id'])->fetch_assoc()['full_name'] : 'Unassigned';
                $new_name = $new_assignee_id ? $conn->query("SELECT full_name FROM users WHERE id = $new_assignee_id")->fetch_assoc()['full_name'] : 'Unassigned';
                $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
                $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_name, $new_name);
                $stmt2->execute();
                $stmt2->close();
            }
            
            if ($old_task['due_date'] != $new_due_date) {
                $old_due_date_display = $old_task['due_date'] ? formatDate($old_task['due_date']) : 'No due date';
                $new_due_date_display = $new_due_date ? formatDate($new_due_date) : 'No due date';
                $action = "Due date changed";
                $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
                $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_due_date_display, $new_due_date_display);
                $stmt2->execute();
                $stmt2->close();
            }
            
            $message = 'Task updated successfully';
            // Refresh task data
            $task = $conn->query("
                SELECT t.*, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name,
                       s.name as status_name, s.color as status_color
                FROM tasks t
                LEFT JOIN projects p ON t.project_id = p.id
                LEFT JOIN users u ON t.assignee_id = u.id
                LEFT JOIN users u2 ON t.created_by = u2.id
                LEFT JOIN statuses s ON t.status_id = s.id
                WHERE t.id = $task_id
            ")->fetch_assoc();
        } else {
            $error = 'Error updating task: ' . $conn->error;
        }
        $stmt->close();
    }
}

// Handle time log submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_timelog'])) {
    $log_date = trim($_POST['log_date'] ?? '');
    $hours = intval($_POST['hours'] ?? 0);
    $minutes = intval($_POST['minutes'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $user_id = $_SESSION['user_id'];
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    $error = '';
    
    if (empty($log_date)) {
        $error = 'Date is required';
    } elseif ($hours < 0 || $hours > 23) {
        $error = 'Hours must be between 0 and 23';
    } elseif ($minutes < 0 || $minutes > 59) {
        $error = 'Minutes must be between 0 and 59';
    } elseif ($hours == 0 && $minutes == 0) {
        $error = 'At least one hour or minute must be entered';
    }
    
    if (!empty($error)) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $error
            ]);
            exit();
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO time_logs (task_id, user_id, log_date, hours, minutes, description) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisiss", $task_id, $user_id, $log_date, $hours, $minutes, $description);
        
        if ($stmt->execute()) {
            $timelog_id = $conn->insert_id;
            $message = 'Time log added successfully';
            
            // Log activity
            $action = "Time log added: {$hours}h {$minutes}m";
            $activity_stmt = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action) VALUES (?, ?, ?)");
            $activity_stmt->bind_param("iis", $task_id, $user_id, $action);
            $activity_stmt->execute();
            $activity_stmt->close();
            
            // If AJAX request, return JSON response
            if ($is_ajax) {
                // Get the newly added time log with user details
                $new_timelog_stmt = $conn->prepare("
                    SELECT tl.*, u.full_name, u.email
                    FROM time_logs tl
                    JOIN users u ON tl.user_id = u.id
                    WHERE tl.id = ?
                ");
                $new_timelog_stmt->bind_param("i", $timelog_id);
                $new_timelog_stmt->execute();
                $new_timelog_result = $new_timelog_stmt->get_result();
                $new_timelog = $new_timelog_result->fetch_assoc();
                $new_timelog_stmt->close();
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'timelog' => $new_timelog
                ]);
                exit();
            }
        } else {
            $error = 'Error adding time log: ' . $conn->error;
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error' => $error
                ]);
                exit();
            }
        }
        $stmt->close();
    }
}

// Handle time log update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_timelog'])) {
    $timelog_id = intval($_POST['timelog_id'] ?? 0);
    $log_date = trim($_POST['log_date'] ?? '');
    $hours = intval($_POST['hours'] ?? 0);
    $minutes = intval($_POST['minutes'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $user_id = $_SESSION['user_id'];
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    $error = '';
    
    // Get old time log data for activity log
    $old_log_stmt = $conn->prepare("SELECT log_date, hours, minutes, description FROM time_logs WHERE id = ?");
    $old_log_stmt->bind_param("i", $timelog_id);
    $old_log_stmt->execute();
    $old_log_result = $old_log_stmt->get_result();
    $old_log = $old_log_result->fetch_assoc();
    $old_log_stmt->close();
    
    if (empty($log_date)) {
        $error = 'Date is required';
    } elseif ($hours < 0 || $hours > 23) {
        $error = 'Hours must be between 0 and 23';
    } elseif ($minutes < 0 || $minutes > 59) {
        $error = 'Minutes must be between 0 and 59';
    } elseif ($hours == 0 && $minutes == 0) {
        $error = 'At least one hour or minute must be entered';
    }
    
    if (!empty($error)) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit();
        }
    } else {
        $stmt = $conn->prepare("UPDATE time_logs SET log_date = ?, hours = ?, minutes = ?, description = ? WHERE id = ?");
        $stmt->bind_param("siisi", $log_date, $hours, $minutes, $description, $timelog_id);
        
        if ($stmt->execute()) {
            // Log activity
            $old_time = ($old_log['hours'] ?? 0) . 'h ' . ($old_log['minutes'] ?? 0) . 'm';
            $new_time = $hours . 'h ' . $minutes . 'm';
            $action = "Time log updated: {$old_time} → {$new_time}";
            $activity_stmt = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
            $old_value = $old_time . ' on ' . ($old_log['log_date'] ?? '');
            $new_value = $new_time . ' on ' . $log_date;
            $activity_stmt->bind_param("iisss", $task_id, $user_id, $action, $old_value, $new_value);
            $activity_stmt->execute();
            $activity_stmt->close();
            
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Time log updated successfully']);
                exit();
            }
        } else {
            $error = 'Error updating time log: ' . $conn->error;
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $error]);
                exit();
            }
        }
        $stmt->close();
    }
}

// Handle time log deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_timelog'])) {
    $timelog_id = intval($_POST['timelog_id'] ?? 0);
    $user_id = $_SESSION['user_id'];
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    // Get old time log data for activity log
    $old_log_stmt = $conn->prepare("SELECT hours, minutes, log_date FROM time_logs WHERE id = ?");
    $old_log_stmt->bind_param("i", $timelog_id);
    $old_log_stmt->execute();
    $old_log_result = $old_log_stmt->get_result();
    $old_log = $old_log_result->fetch_assoc();
    $old_log_stmt->close();
    
    $stmt = $conn->prepare("DELETE FROM time_logs WHERE id = ?");
    $stmt->bind_param("i", $timelog_id);
    
    if ($stmt->execute()) {
        // Log activity
        $old_time = ($old_log['hours'] ?? 0) . 'h ' . ($old_log['minutes'] ?? 0) . 'm';
        $action = "Time log deleted: {$old_time} on " . ($old_log['log_date'] ?? '');
        $activity_stmt = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value) VALUES (?, ?, ?, ?)");
        $old_value = $old_time . ' on ' . ($old_log['log_date'] ?? '');
        $activity_stmt->bind_param("iiss", $task_id, $user_id, $action, $old_value);
        $activity_stmt->execute();
        $activity_stmt->close();
        
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Time log deleted successfully']);
            exit();
        }
    } else {
        $error = 'Error deleting time log: ' . $conn->error;
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit();
        }
    }
    $stmt->close();
}

// Handle comment edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_comment'])) {
    $comment_id = intval($_POST['comment_id'] ?? 0);
    $new_comment = trim($_POST['comment'] ?? '');
    $user_id = $_SESSION['user_id'];
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    // Get old comment for activity log
    $old_comment_stmt = $conn->prepare("SELECT task_id, user_id, comment FROM task_comments WHERE id = ?");
    $old_comment_stmt->bind_param("i", $comment_id);
    $old_comment_stmt->execute();
    $old_comment_result = $old_comment_stmt->get_result();
    $old_comment = $old_comment_result->fetch_assoc();
    $old_comment_stmt->close();

    // Validate ownership / permission and same task
    $can_edit_comment = false;
    if ($old_comment && intval($old_comment['task_id'] ?? 0) === $task_id) {
        $owner_id = intval($old_comment['user_id'] ?? 0);
        $can_edit_comment = ($owner_id === intval($_SESSION['user_id'] ?? 0)) || isSuperAdmin() || isOrgAdmin();
    }
    if (!$can_edit_comment) {
        $error = 'You do not have permission to edit this comment';
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit();
        }
    }
    
    if (empty($new_comment)) {
        $error = 'Comment cannot be empty';
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit();
        }
    } else {
        $stmt = $conn->prepare("UPDATE task_comments SET comment = ? WHERE id = ?");
        $stmt->bind_param("si", $new_comment, $comment_id);
        
        if ($stmt->execute()) {
            // Log activity
            $action = "Comment edited";
            $activity_stmt = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
            $old_value = substr($old_comment['comment'] ?? '', 0, 255);
            $new_value = substr($new_comment, 0, 255);
            $activity_stmt->bind_param("iisss", $task_id, $user_id, $action, $old_value, $new_value);
            $activity_stmt->execute();
            $activity_stmt->close();
            
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Comment updated successfully', 'comment' => $new_comment]);
                exit();
            }
        } else {
            $error = 'Error updating comment: ' . $conn->error;
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $error]);
                exit();
            }
        }
        $stmt->close();
    }
}

// Handle comment deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_comment'])) {
    $comment_id = intval($_POST['comment_id'] ?? 0);
    $user_id = $_SESSION['user_id'];
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

    // Fetch comment for permission + activity log
    $get_stmt = $conn->prepare("SELECT task_id, user_id, comment FROM task_comments WHERE id = ?");
    $get_stmt->bind_param("i", $comment_id);
    $get_stmt->execute();
    $get_res = $get_stmt->get_result();
    $row = $get_res->fetch_assoc();
    $get_stmt->close();

    $can_delete = false;
    if ($row && intval($row['task_id'] ?? 0) === $task_id) {
        $owner_id = intval($row['user_id'] ?? 0);
        $can_delete = ($owner_id === intval($_SESSION['user_id'] ?? 0)) || isSuperAdmin() || isOrgAdmin();
    }

    if (!$can_delete) {
        $error = 'You do not have permission to delete this comment';
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit();
        }
    } else {
        $del_stmt = $conn->prepare("DELETE FROM task_comments WHERE id = ?");
        $del_stmt->bind_param("i", $comment_id);

        if ($del_stmt->execute()) {
            // Log activity
            $action = "Comment deleted";
            $activity_stmt = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value) VALUES (?, ?, ?, ?)");
            $old_value = substr($row['comment'] ?? '', 0, 255);
            $activity_stmt->bind_param("iiss", $task_id, $user_id, $action, $old_value);
            $activity_stmt->execute();
            $activity_stmt->close();

            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Comment deleted successfully']);
                exit();
            }
        } else {
            $error = 'Error deleting comment: ' . $conn->error;
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $error]);
                exit();
            }
        }
        $del_stmt->close();
    }
}

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_comment'])) {
    $comment = trim($_POST['comment']);
    $user_id = $_SESSION['user_id'];
    $parent_id = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    $error = '';
    
    if (empty($comment)) {
        $error = 'Comment cannot be empty';
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $error
            ]);
            exit();
        }
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
                $comment_id = $conn->insert_id;
                $message = 'Comment added successfully';
                
                // If AJAX request, return JSON response
                if ($is_ajax) {
                    // Get the newly added comment with user details
                    $new_comment_stmt = $conn->prepare("
                        SELECT c.*, u.full_name as user_name, u.email, u.id as user_table_id
                        FROM task_comments c
                        JOIN users u ON c.user_id = u.id
                        WHERE c.id = ?
                    ");
                    $new_comment_stmt->bind_param("i", $comment_id);
                    $new_comment_stmt->execute();
                    $new_comment = $new_comment_stmt->get_result()->fetch_assoc();
                    $new_comment_stmt->close();
                    
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => $message,
                        'comment' => $new_comment
                    ]);
                    exit();
                } else {
                    // Regular form submission - redirect
                    header('Location: task_view?id=' . $task_id);
                    exit();
                }
            } else {
                $error = 'Error adding comment';
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'error' => $error
                    ]);
                    exit();
                }
            }
            $stmt->close();
        } else {
            // Handle error (invalid parent_id or other validation errors)
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error' => $error
                ]);
                exit();
            }
        }
    }
}

// Handle quick status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $new_status_id = intval($_POST['status_id']); // Now using status_id
    $user_id = $_SESSION['user_id'];
    
    // Validate status_id against organization's statuses
    $org_statuses = getStatuses($organization_id);
    $valid_status_ids = array_column($org_statuses, 'id');
    
    if (!in_array($new_status_id, $valid_status_ids)) {
        $error = 'Invalid status ID: ' . htmlspecialchars($new_status_id);
    } else {
        // Get old status info
        $old_task = $conn->prepare("SELECT t.status_id, s.name as status_name FROM tasks t LEFT JOIN statuses s ON t.status_id = s.id WHERE t.id = ?");
        $old_task->bind_param("i", $task_id);
        $old_task->execute();
        $old_result = $old_task->get_result();
        $old_task_data = $old_result->fetch_assoc();
        $old_status_id = $old_task_data['status_id'] ?? null;
        $old_status_name = $old_task_data['status_name'] ?? $task['status'] ?? 'Unknown';
        $old_task->close();
        
        // Get new status name
        $new_status_query = $conn->prepare("SELECT name FROM statuses WHERE id = ?");
        $new_status_query->bind_param("i", $new_status_id);
        $new_status_query->execute();
        $new_status_result = $new_status_query->get_result();
        $new_status_data = $new_status_result->fetch_assoc();
        $new_status_name = $new_status_data['name'] ?? 'Unknown';
        $new_status_query->close();
        
        // Update status_id and status name (for backward compatibility)
        $stmt = $conn->prepare("UPDATE tasks SET status_id = ?, status = ? WHERE id = ?");
        $stmt->bind_param("isi", $new_status_id, $new_status_name, $task_id);
        
        if ($stmt->execute()) {
            // Log activity
            if ($old_status_id != $new_status_id) {
                $action = "Status changed";
                $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
                $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_status_name, $new_status_name);
                $stmt2->execute();
                $stmt2->close();
            }
            
            $message = 'Status updated successfully';
            // Refresh task data
            $task = $conn->query("
                SELECT t.*, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name,
                       s.name as status_name, s.color as status_color
                FROM tasks t
                LEFT JOIN projects p ON t.project_id = p.id
                LEFT JOIN users u ON t.assignee_id = u.id
                LEFT JOIN users u2 ON t.created_by = u2.id
                LEFT JOIN statuses s ON t.status_id = s.id
                WHERE t.id = $task_id
            ")->fetch_assoc();
        } else {
            $error = 'Error updating status: ' . $conn->error;
        }
        $stmt->close();
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
    $new_due_date = !empty($_POST['due_date']) ? trim($_POST['due_date']) : null;
    $old_due_date = $task['due_date'];
    $user_id = $_SESSION['user_id'];
    
    // Format dates for display in activity log
    $old_due_date_display = $old_due_date ? formatDate($old_due_date) : 'No due date';
    $new_due_date_display = $new_due_date ? formatDate($new_due_date) : 'No due date';
    
    // Handle NULL value properly for MySQL
    if ($new_due_date === null || $new_due_date === '') {
        $stmt = $conn->prepare("UPDATE tasks SET due_date = NULL WHERE id = ?");
        $stmt->bind_param("i", $task_id);
    } else {
        $stmt = $conn->prepare("UPDATE tasks SET due_date = ? WHERE id = ?");
        $stmt->bind_param("si", $new_due_date, $task_id);
    }
    
    if ($stmt->execute()) {
        // Log activity only if date actually changed
        if ($old_due_date_display != $new_due_date_display) {
            $action = "Due date changed";
            $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
            $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_due_date_display, $new_due_date_display);
            $stmt2->execute();
            $stmt2->close();
        }
        
        $message = 'Due date updated successfully';
        // Refresh task data
        $task = $conn->query("
            SELECT t.*, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name,
                   s.name as status_name, s.color as status_color
            FROM tasks t
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u ON t.assignee_id = u.id
            LEFT JOIN users u2 ON t.created_by = u2.id
            LEFT JOIN statuses s ON t.status_id = s.id
            WHERE t.id = $task_id
        ")->fetch_assoc();
    } else {
        $error = 'Error updating due date: ' . $conn->error;
    }
    $stmt->close();
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
                SELECT t.*, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name,
                       s.name as status_name, s.color as status_color
                FROM tasks t
                LEFT JOIN projects p ON t.project_id = p.id
                LEFT JOIN users u ON t.assignee_id = u.id
                LEFT JOIN users u2 ON t.created_by = u2.id
                LEFT JOIN statuses s ON t.status_id = s.id
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
        SELECT DISTINCT c.id, c.task_id, c.user_id, c.comment, c.parent_id, c.created_at,
               u.full_name as user_name, u.email, u.id as user_table_id
        FROM task_comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.task_id = ? AND (c.parent_id IS NULL OR c.parent_id = 0)
        ORDER BY c.created_at DESC
    ");
    $comments_stmt->bind_param("i", $task_id);
    $comments_stmt->execute();
    $comments_raw = $comments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $comments_stmt->close();

    // Remove duplicates by comment ID
    $comments = [];
    $seen_ids = [];
    foreach ($comments_raw as $comment) {
        $comment_id = intval($comment['id']);
        if (!in_array($comment_id, $seen_ids)) {
            $comments[] = $comment;
            $seen_ids[] = $comment_id;
        }
    }

    // Get replies for each comment
    foreach ($comments as &$comment) {
        $comment_id = intval($comment['id']);
        $replies_stmt = $conn->prepare("
            SELECT DISTINCT c.id, c.task_id, c.user_id, c.comment, c.parent_id, c.created_at,
                   u.full_name as user_name, u.email, u.id as user_table_id
            FROM task_comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.parent_id = ? AND c.task_id = ?
            ORDER BY c.created_at ASC
        ");
        $replies_stmt->bind_param("ii", $comment_id, $task_id);
        $replies_stmt->execute();
        $replies_raw = $replies_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $replies_stmt->close();
        
        // Remove duplicate replies by ID
        $replies = [];
        $seen_reply_ids = [];
        foreach ($replies_raw as $reply) {
            $reply_id = intval($reply['id']);
            if (!in_array($reply_id, $seen_reply_ids)) {
                $replies[] = $reply;
                $seen_reply_ids[] = $reply_id;
            }
        }
        $comment['replies'] = $replies;
    }
} else {
    // If parent_id doesn't exist, get all comments as top-level
    $comments_query = "
        SELECT DISTINCT c.id, c.task_id, c.user_id, c.comment, c.created_at,
               u.full_name as user_name, u.email, u.id as user_table_id
        FROM task_comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.task_id = $task_id
        ORDER BY c.created_at ASC
    ";
    $comments_raw = $conn->query($comments_query)->fetch_all(MYSQLI_ASSOC);
    
    // Remove duplicates by comment ID
    $comments = [];
    $seen_ids = [];
    foreach ($comments_raw as $comment) {
        $comment_id = intval($comment['id']);
        if (!in_array($comment_id, $seen_ids)) {
            $comment['replies'] = [];
            $comments[] = $comment;
            $seen_ids[] = $comment_id;
        }
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

// Get all users involved in this ticket (assignee, creator, commenters, activity creators)
$involved_users = [];
$involved_user_ids = [];

// Add assignee
if (!empty($task['assignee_id'])) {
    $involved_user_ids[] = intval($task['assignee_id']);
}

// Add creator
if (!empty($task['created_by'])) {
    $involved_user_ids[] = intval($task['created_by']);
}

// Add commenters
foreach ($comments as $comment) {
    if (!empty($comment['user_id']) || !empty($comment['user_table_id'])) {
        $user_id = intval($comment['user_id'] ?? $comment['user_table_id']);
        if (!in_array($user_id, $involved_user_ids)) {
            $involved_user_ids[] = $user_id;
        }
    }
    // Add reply commenters
    if (!empty($comment['replies'])) {
        foreach ($comment['replies'] as $reply) {
            if (!empty($reply['user_id']) || !empty($reply['user_table_id'])) {
                $user_id = intval($reply['user_id'] ?? $reply['user_table_id']);
                if (!in_array($user_id, $involved_user_ids)) {
                    $involved_user_ids[] = $user_id;
                }
            }
        }
    }
}

// Add activity creators
foreach ($activities as $activity) {
    if (!empty($activity['user_id'])) {
        $user_id = intval($activity['user_id']);
        if (!in_array($user_id, $involved_user_ids)) {
            $involved_user_ids[] = $user_id;
        }
    }
}

// Get user details for all involved users
if (!empty($involved_user_ids)) {
    $placeholders = implode(',', array_fill(0, count($involved_user_ids), '?'));
    $involved_query = $conn->prepare("
        SELECT id, full_name, email 
        FROM users 
        WHERE id IN ($placeholders) AND deleted = 0
        ORDER BY full_name
    ");
    $involved_query->bind_param(str_repeat('i', count($involved_user_ids)), ...$involved_user_ids);
    $involved_query->execute();
    $involved_users = $involved_query->get_result()->fetch_all(MYSQLI_ASSOC);
    $involved_query->close();
}

// Get users for assignee dropdown and @mentions (filtered by organization, excluding deleted users)
if (isSuperAdmin()) {
    $users_list = $conn->query("SELECT id, full_name, email FROM users WHERE status = 'active' AND deleted = 0 ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
} else {
    $org_id = getOrganizationId();
    if ($org_id) {
        $stmt = $conn->prepare("SELECT id, full_name, email FROM users WHERE organization_id = ? AND status = 'active' AND deleted = 0 ORDER BY full_name");
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

// Get time logs
$time_logs = [];
$total_time_minutes = 0;
$time_logs_check_conn = getDBConnection();
$time_logs_query = $time_logs_check_conn->prepare("SELECT tl.id, tl.task_id, tl.user_id, tl.log_date, tl.hours, tl.minutes, tl.description, tl.created_at, u.full_name, u.email FROM time_logs tl LEFT JOIN users u ON tl.user_id = u.id WHERE tl.task_id = ? ORDER BY tl.log_date DESC, tl.created_at DESC");
if ($time_logs_query) {
    $time_logs_query->bind_param("i", $task_id);
    $time_logs_query->execute();
    $time_logs_result = $time_logs_query->get_result();
    $time_logs = $time_logs_result->fetch_all(MYSQLI_ASSOC);
    $time_logs_query->close();
    
    // Calculate total time
    foreach ($time_logs as $log) {
        $total_time_minutes += ($log['hours'] * 60) + $log['minutes'];
    }
}
$time_logs_check_conn->close();

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
@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes slideOut {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(100%);
        opacity: 0;
    }
}
</style>

<style>
.task-view-page {
    background: var(--page-bg);
    min-height: calc(100vh - 60px);
    padding: 0;
    margin: 0;
    width: 100%;
    max-width: 100%;
}


.task-breadcrumb {
    padding: 12px 16px;
    background: var(--card-bg);
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--text-secondary);
    margin: 0;
    width: 100%;
    box-sizing: border-box;
}

/* Override content-wrapper padding for task view */
body .content-wrapper:has(.task-view-page) {
    padding-left: 0 !important;
    padding-right: 0 !important;
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
    grid-template-columns: 1fr 320px;
    gap: 16px;
    padding: 16px 0;
    max-width: 100%;
    margin: 0;
    width: 100%;
    box-sizing: border-box;
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
    grid-template-columns: 1fr;
    gap: 24px;
    margin-top: 24px;
}

.comments-panel {
    background: var(--card-bg);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    box-shadow: 0 1px 3px var(--shadow);
    padding: 24px;
    width: 100%;
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
    padding: 16px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
    resize: vertical;
    min-height: 150px;
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

    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    font-size: 24px;
}

@media (max-width: 1024px) {
    .task-content-layout {
        grid-template-columns: 1fr;
        padding: 16px 0;
    }
    
    .comments-activity-section {
        grid-template-columns: 1fr;
    }
}
@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes slideOut {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(100%);
        opacity: 0;
    }
}
</style>

<div class="task-view-page">
    <!-- Breadcrumb Navigation -->
    <div class="task-breadcrumb">
        <a href="tasks">Tasks</a>
        <span class="task-breadcrumb-separator">/</span>
        <span>#<?php echo htmlspecialchars($task['task_id']); ?></span>
        
        <?php 
        // Get status info for breadcrumb
        $task_status_id = $task['status_id'] ?? null;
        $task_status = $task['status'] ?? 'To Do';
        
        if ($task_status_id) {
            $status_info = null;
            foreach ($statuses as $s) {
                if ($s['id'] == $task_status_id) {
                    $status_info = $s;
                    break;
                }
            }
        } else {
            $status_info = getStatusByName($task_status, $organization_id);
        }
        $status_color = $status_info['color'] ?? '#6c757d';
        
        // Get priority info
        $priority_lower = strtolower($task['priority'] ?? 'low');
        $priority_icon = 'fa-circle';
        $priority_color = '#9CA3AF'; // gray for low
        if ($priority_lower == 'high') {
            $priority_icon = 'fa-exclamation-circle';
            $priority_color = '#EF4444'; // red
        } elseif ($priority_lower == 'medium') {
            $priority_icon = 'fa-exclamation-triangle';
            $priority_color = '#F59E0B'; // yellow/orange
        }
        
        // Get assignee initials
        $assignee_initials = '';
        if ($task['assignee_name']) {
            $assignee_initials = getInitials($task['assignee_name']);
        }
        ?>
        
        <!-- Type Icon -->
        <span style="margin-left: 12px; display: inline-flex; align-items: center; gap: 6px;">
            <i class="fas <?php echo $type_icon; ?>" style="color: <?php echo $type_color; ?>; font-size: 14px;" title="<?php echo htmlspecialchars($task['type']); ?>"></i>
        </span>
        
        <!-- Status Circle -->
        <span style="margin-left: 8px; display: inline-flex; align-items: center; gap: 6px;">
            <span style="width: 10px; height: 10px; border-radius: 50%; background: <?php echo htmlspecialchars($status_color); ?>; display: inline-block;" title="<?php echo htmlspecialchars($task_status); ?>"></span>
        </span>
        
        <!-- Priority Icon -->
        <span style="margin-left: 8px; display: inline-flex; align-items: center; gap: 6px;">
            <i class="fas <?php echo $priority_icon; ?>" style="color: <?php echo $priority_color; ?>; font-size: 14px;" title="<?php echo htmlspecialchars(ucfirst($priority_lower)) . ' Priority'; ?>"></i>
        </span>
        
        <!-- Assignee Icon/Initials -->
        <?php if ($assignee_initials): ?>
            <span style="margin-left: 8px; display: inline-flex; align-items: center; gap: 6px;">
                <span style="width: 32px; height: 32px; border-radius: 50%; background: var(--blue-light); color: var(--blue-dark); display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; border: 2px solid #ffffff; box-shadow: 0 0 0 1px rgba(0,0,0,0.1);" title="<?php echo htmlspecialchars($task['assignee_name']); ?>">
                    <?php echo htmlspecialchars($assignee_initials); ?>
                </span>
            </span>
        <?php else: ?>
            <span style="margin-left: 8px; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fas fa-user-slash" style="color: var(--text-muted); font-size: 14px;" title="Unassigned"></i>
            </span>
        <?php endif; ?>
        
        <!-- All Involved Users (Creator, Commenters, Activity Creators - Assignee already shown above) -->
        <?php 
        // Filter out assignee from involved users since it's already shown
        $assignee_id = !empty($task['assignee_id']) ? intval($task['assignee_id']) : null;
        $other_involved = array_filter($involved_users, function($user) use ($assignee_id) {
            return intval($user['id']) !== $assignee_id;
        });
        
        if (!empty($other_involved)): 
            $displayed_count = 0;
            $max_display = 5; // Show max 5 avatars, rest as count
            $total_others = count($other_involved);
        ?>
            <span style="margin-left: 12px; display: inline-flex; align-items: center; gap: 4px; flex-wrap: wrap;">
                <?php foreach ($other_involved as $involved_user): 
                    if ($displayed_count >= $max_display) break;
                    
                    $user_initials = getInitials($involved_user['full_name']);
                    $displayed_count++;
                ?>
                    <span style="width: 24px; height: 24px; border-radius: 50%; background: var(--gray-100); color: var(--text-secondary); display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; border: 2px solid #ffffff; margin-left: -4px; cursor: pointer; transition: transform 0.2s; position: relative; box-shadow: 0 0 0 1px rgba(0,0,0,0.1);" 
                          title="<?php echo htmlspecialchars($involved_user['full_name']); ?>"
                          onmouseover="this.style.transform='scale(1.15)'; this.style.zIndex='10';"
                          onmouseout="this.style.transform='scale(1)'; this.style.zIndex='1';">
                        <?php echo htmlspecialchars($user_initials); ?>
                    </span>
                <?php endforeach; ?>
                
                <?php if ($total_others > $max_display): ?>
                    <span style="width: 24px; height: 24px; border-radius: 50%; background: var(--gray-200); color: var(--text-secondary); display: inline-flex; align-items: center; justify-content: center; font-size: 9px; font-weight: 600; border: 2px solid #ffffff; margin-left: -4px; cursor: pointer; position: relative; box-shadow: 0 0 0 1px rgba(0,0,0,0.1);" 
                          title="<?php echo ($total_others - $max_display); ?> more people involved">
                        +<?php echo ($total_others - $max_display); ?>
                    </span>
                <?php endif; ?>
            </span>
        <?php endif; ?>
        
        <!-- Save Button (disabled by default, enabled when changes detected) -->
        <button type="button" id="save-task-btn" onclick="saveAllChanges()" 
                style="display: inline-flex; margin-left: 16px; padding: 6px 16px; background: var(--text-muted); color: white; border: none;  font-size: 13px; font-weight: 500; cursor: not-allowed; align-items: center; gap: 6px; opacity: 0.6;"
                disabled
                title="No changes to save">
            <i class="fas fa-save"></i>
            <span>Save</span>
        </button>
    </div>
    
    <!-- Task Header -->
    <div class="task-header-new">
       
        
        <!-- Metadata Bar -->
  
        <div class="task-title-header" style="display: flex; align-items: center; gap: 12px; position: relative;">
            <div id="task-title-display" style="flex: 1; cursor: pointer;" onclick="editTitle()" title="Click to edit">
                <h1 class="task-title-main" id="task-title-text" style="margin: 0;"><?php echo htmlspecialchars($task['title']); ?></h1>
            </div>
            <div id="task-title-edit" style="display: none; flex: 1;">
                <input type="text" id="task-title-input" value="<?php echo htmlspecialchars($task['title']); ?>" 
                       onblur="cancelTitleEdit()" onkeydown="handleTitleKeydown(event)"
                       style="width: 100%; padding: 8px 12px; border: 1px solid var(--border-color);  font-size: 24px; font-weight: 600; font-family: inherit;"
                       required>
            </div>
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
        <!-- Left Column: Main Content -->
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
                        Attachments (<?php 
                        $attachment_count = count($attachments);
                        // Also count images in description
                        $description = $task['description'] ?? '';
                        if (!empty($description)) {
                            preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $description, $img_matches);
                            $image_count = count($img_matches[1] ?? []);
                            $attachment_count += $image_count;
                        }
                        echo $attachment_count; 
                        ?>)
                    </button>
                    <button class="tab-btn" data-tab="timelog" onclick="switchMainTab('timelog')" style="padding: 12px 20px; background: none; border: none; border-bottom: 2px solid transparent; color: var(--text-secondary); font-weight: 500; font-size: 14px; cursor: pointer; margin-bottom: -2px;">
                        Time Log <?php 
                        if ($total_time_minutes > 0) {
                            $total_hours = floor($total_time_minutes / 60);
                            $total_mins = $total_time_minutes % 60;
                            echo '(' . $total_hours . 'h';
                            if ($total_mins > 0) {
                                echo ' ' . $total_mins . 'm';
                            }
                            echo ')';
                        }
                        ?>
                    </button>
                </div>
                
                <!-- Tab Content: Description -->
                <div id="main-tab-description" class="tab-content active" style="padding: 24px;">
                    <div class="task-description-section" style="position: relative;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                            <h3 style="font-size: 16px; font-weight: 600; color: var(--text-primary); margin: 0;">Description</h3>
                        </div>
                        <div id="task-description-display" class="task-description-text" onclick="editDescription()" style="cursor: pointer; min-height: 100px; padding: 12px; border: 1px dashed transparent;  transition: all 0.2s;" 
                             onmouseover="this.style.borderColor='var(--border-color)'; this.style.background='var(--page-bg)'"
                             onmouseout="this.style.borderColor='transparent'; this.style.background='transparent'"
                             title="Click to edit">
                            <?php
                            $description = trim($task['description'] ?? '');
                            if ($description === '') {
                                echo '<p style="color: var(--text-muted); font-style: italic; margin: 0;">Click to add description</p>';
                            } else {
                                echo renderRichText($description);
                            }
                            ?>
                        </div>
                        <div id="task-description-edit" style="display: none;">
                            <div id="task-description-editor" style="min-height: 400px; background: white; border: 1px solid var(--border-color); border-radius: 4px;"></div>
                        </div>
                        <style>
                        #task-description-editor .ql-editor {
                            min-height: 400px;
                            font-size: 14px;
                            line-height: 1.6;
                            padding: 12px;
                        }
                        #task-description-editor .ql-container {
                            font-family: inherit;
                        }
                        #task-description-editor .ql-editor:focus {
                            outline: 2px solid var(--blue);
                            outline-offset: -2px;
                        }
                        </style>
                    </div>
                </div>
                
                <!-- Tab Content: Activity -->
                <div id="main-tab-activity" class="tab-content" style="display: none; padding: 24px;">
                    <div class="activity-panel">
                        <div class="activity-panel-header">
                            <h3 class="activity-panel-title">Activity Log</h3>
                        </div>
                        <div class="activity-list">
                            <?php if (empty($activities)): ?>
                                <div style="text-align: center; padding: 30px; color: var(--text-muted); font-size: 13px;">
                                    <i class="fas fa-history" style="font-size: 24px; margin-bottom: 8px; opacity: 0.3;"></i>
                                    <p>No activity yet</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($activities as $activity): ?>
                                    <div class="activity-item-new">
                                        <div class="activity-avatar">
                                            <?php
                                            $activity_user_name = $activity['user_name'] ?? 'Unknown';
                                            $activity_initials = getInitials($activity_user_name);
                                            ?>
                                            <span><?php echo htmlspecialchars($activity_initials); ?></span>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-header">
                                                <span class="activity-user"><?php echo htmlspecialchars($activity_user_name); ?></span>
                                                <span class="activity-action"><?php echo htmlspecialchars($activity['action']); ?></span>
                                                <span class="activity-time"><?php echo formatDate($activity['created_at']); ?></span>
                                            </div>
                                            <?php if (!empty($activity['old_value']) || !empty($activity['new_value'])): ?>
                                                <div class="activity-details">
                                                    <?php if (!empty($activity['old_value'])): ?>
                                                        <span class="activity-old"><?php echo htmlspecialchars($activity['old_value']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($activity['old_value']) && !empty($activity['new_value'])): ?>
                                                        <i class="fas fa-arrow-right" style="margin: 0 8px; color: var(--text-muted);"></i>
                                                    <?php endif; ?>
                                                    <?php if (!empty($activity['new_value'])): ?>
                                                        <span class="activity-new"><?php echo htmlspecialchars($activity['new_value']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Tab Content: Attachments -->
                <div id="main-tab-attachments" class="tab-content" style="display: none; padding: 24px;">
                    <div class="attachments-panel">
                        <div class="attachments-panel-header">
                            <h3 class="attachments-panel-title">Attachments</h3>
                        </div>
                        <div class="attachments-list">
                            <?php 
                            // Extract images from description
                            $description_images = [];
                            $description = $task['description'] ?? '';
                            if (!empty($description)) {
                                preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $description, $img_matches);
                                if (!empty($img_matches[1])) {
                                    foreach ($img_matches[1] as $img_src) {
                                        // Only include images from task_attachments folder
                                        if (strpos($img_src, 'uploads/task_attachments/') !== false || strpos($img_src, 'task_attachments/') !== false) {
                                            $filename = basename($img_src);
                                            // Ensure proper path format
                                            $file_path = $img_src;
                                            // If it's a relative path without leading slash, add it
                                            if (strpos($file_path, 'http') !== 0 && strpos($file_path, '/') !== 0) {
                                                $file_path = '/' . ltrim($file_path, '/');
                                            }
                                            // Get file size if file exists
                                            $full_path = __DIR__ . '/../' . ltrim($file_path, '/');
                                            $file_size = file_exists($full_path) ? filesize($full_path) : 0;
                                            
                                            $description_images[] = [
                                                'filename' => $filename,
                                                'file_path' => $file_path,
                                                'file_size' => $file_size,
                                                'mime_type' => 'image/' . (pathinfo($filename, PATHINFO_EXTENSION) === 'png' ? 'png' : (pathinfo($filename, PATHINFO_EXTENSION) === 'gif' ? 'gif' : (pathinfo($filename, PATHINFO_EXTENSION) === 'webp' ? 'webp' : 'jpeg'))),
                                                'is_from_description' => true
                                            ];
                                        }
                                    }
                                }
                            }
                            
                            // Combine table attachments and description images
                            $all_attachments = array_merge($attachments, $description_images);
                            
                            if (empty($all_attachments)): ?>
                                <div style="text-align: center; padding: 30px; color: var(--text-muted); font-size: 13px;">
                                    <i class="fas fa-paperclip" style="font-size: 24px; margin-bottom: 8px; opacity: 0.3;"></i>
                                    <p>No attachments yet</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($all_attachments as $attachment): ?>
                                    <?php 
                                    // Ensure file_path is properly formatted
                                    $file_url = $attachment['file_path'] ?? '';
                                    // If it's a relative path, ensure it starts with /
                                    if (!empty($file_url) && strpos($file_url, 'http') !== 0 && strpos($file_url, '/') !== 0) {
                                        $file_url = '/' . ltrim($file_url, '/');
                                    }
                                    // If file_path is empty, try to construct from filename
                                    if (empty($file_url) && !empty($attachment['filename'])) {
                                        $file_url = 'uploads/task_attachments/' . $attachment['filename'];
                                    }
                                    ?>
                                    <div class="attachment-item" style="display: flex; align-items: center; padding: 12px; border-bottom: 1px solid var(--border-color); transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='var(--page-bg)'" onmouseout="this.style.backgroundColor='transparent'">
                                        <i class="fas <?php echo getFileIcon($attachment['mime_type'] ?? 'application/octet-stream', $attachment['filename'] ?? ''); ?>" style="margin-right: 10px; color: var(--text-secondary); font-size: 16px; width: 20px; flex-shrink: 0;"></i>
                                        <a href="<?php echo htmlspecialchars($file_url); ?>" target="_blank" rel="noopener noreferrer" style="color: var(--blue); text-decoration: none; flex: 1; font-size: 14px; word-break: break-word; display: flex; align-items: center;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
                                            <span><?php echo htmlspecialchars($attachment['filename'] ?? 'Unknown'); ?></span>
                                            <?php if (isset($attachment['is_from_description']) && $attachment['is_from_description']): ?>
                                                <span style="color: var(--text-muted); font-size: 11px; margin-left: 6px; font-style: italic;">(from description)</span>
                                            <?php endif; ?>
                                        </a>
                                        <?php if (!empty($attachment['file_size'])): ?>
                                            <span style="color: var(--text-muted); font-size: 12px; margin-left: 12px; white-space: nowrap; flex-shrink: 0;">
                                                <?php echo formatFileSize($attachment['file_size']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Tab Content: Time Log -->
                <div id="main-tab-timelog" class="tab-content" style="display: none; padding: 24px;">
                    <div class="timelog-panel">
                        <div class="timelog-panel-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <div>
                                <h3 class="timelog-panel-title" style="margin: 0; font-size: 18px; font-weight: 600; color: var(--text-primary);">Time Log</h3>
                                <?php if ($total_time_minutes > 0): ?>
                                    <p style="margin: 4px 0 0 0; font-size: 13px; color: var(--text-muted);">
                                        Total: <?php 
                                        $total_hours = floor($total_time_minutes / 60);
                                        $total_mins = $total_time_minutes % 60;
                                        echo $total_hours . 'h ' . $total_mins . 'm';
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <button onclick="openAddTimeLogModal()" style="background: var(--blue); color: white; border: none; padding: 8px 16px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='#2563eb'" onmouseout="this.style.backgroundColor='var(--blue)'">
                                <i class="fas fa-plus"></i> Add Time
                            </button>
                        </div>
                        
                        <!-- Add Time Log Form (Modal) -->
                        <div id="add-timelog-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
                            <div style="background: white; border-radius: 12px; padding: 24px; width: 90%; max-width: 500px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                    <h3 style="margin: 0; font-size: 20px; font-weight: 600; color: var(--text-primary);">Add Time Log</h3>
                                    <button onclick="closeAddTimeLogModal()" style="background: none; border: none; font-size: 24px; color: var(--text-muted); cursor: pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 4px;" onmouseover="this.style.backgroundColor='var(--page-bg)'" onmouseout="this.style.backgroundColor='transparent'">&times;</button>
                                </div>
                                <form id="add-timelog-form" onsubmit="addTimeLog(event)">
                                    <div style="margin-bottom: 16px;">
                                        <label style="display: block; margin-bottom: 6px; font-size: 14px; font-weight: 500; color: var(--text-primary);">Date *</label>
                                        <input type="date" id="timelog-date" name="log_date" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; box-sizing: border-box;" value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                                        <div>
                                            <label style="display: block; margin-bottom: 6px; font-size: 14px; font-weight: 500; color: var(--text-primary);">Hours *</label>
                                            <input type="number" id="timelog-hours" name="hours" min="0" max="23" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; box-sizing: border-box;" value="0">
                                        </div>
                                        <div>
                                            <label style="display: block; margin-bottom: 6px; font-size: 14px; font-weight: 500; color: var(--text-primary);">Minutes *</label>
                                            <input type="number" id="timelog-minutes" name="minutes" min="0" max="59" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; box-sizing: border-box;" value="0">
                                        </div>
                                    </div>
                                    <div style="margin-bottom: 20px;">
                                        <label style="display: block; margin-bottom: 6px; font-size: 14px; font-weight: 500; color: var(--text-primary);">Description</label>
                                        <textarea id="timelog-description" name="description" rows="3" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; box-sizing: border-box; resize: vertical;" placeholder="What did you work on?"></textarea>
                                    </div>
                                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                                        <button type="button" onclick="closeAddTimeLogModal()" style="background: var(--page-bg); color: var(--text-primary); border: 1px solid var(--border-color); padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer;">Cancel</button>
                                        <button type="submit" style="background: var(--blue); color: white; border: none; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer;">Add Time</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <div class="timelog-list">
                            <?php if (empty($time_logs)): ?>
                                <div style="text-align: center; padding: 30px; color: var(--text-muted); font-size: 13px;">
                                    <i class="fas fa-clock" style="font-size: 24px; margin-bottom: 8px; opacity: 0.3;"></i>
                                    <p>No time logs yet. Click "Add Time" to log your work.</p>
                                </div>
                            <?php else: ?>
                                <table style="width: 100%; border-collapse: collapse; margin-top: 12px;">
                                    <thead>
                                        <tr style="background: var(--page-bg); border-bottom: 2px solid var(--border-color);">
                                            <th style="padding: 12px; text-align: left; font-size: 13px; font-weight: 600; color: var(--text-primary);">Date</th>
                                            <th style="padding: 12px; text-align: left; font-size: 13px; font-weight: 600; color: var(--text-primary);">Time</th>
                                            <th style="padding: 12px; text-align: left; font-size: 13px; font-weight: 600; color: var(--text-primary);">Description</th>
                                            <th style="padding: 12px; text-align: left; font-size: 13px; font-weight: 600; color: var(--text-primary);">User</th>
                                            <th style="padding: 12px; text-align: left; font-size: 13px; font-weight: 600; color: var(--text-primary);">Created</th>
                                            <th style="padding: 12px; text-align: center; font-size: 13px; font-weight: 600; color: var(--text-primary);">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($time_logs as $log): 
                                            $can_edit = ($log['user_id'] == $_SESSION['user_id']) || isSuperAdmin() || isOrgAdmin();
                                        ?>
                                            <tr id="timelog-row-<?php echo $log['id']; ?>" style="border-bottom: 1px solid var(--border-color); transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='var(--page-bg)'" onmouseout="this.style.backgroundColor='transparent'">
                                                <td style="padding: 12px; font-size: 14px; color: var(--text-primary);">
                                                    <?php echo date('M d, Y', strtotime($log['log_date'])); ?>
                                                </td>
                                                <td style="padding: 12px; font-size: 14px; color: var(--blue); font-weight: 600;">
                                                    <?php echo $log['hours']; ?>h <?php echo $log['minutes']; ?>m
                                                </td>
                                                <td style="padding: 12px; font-size: 14px; color: var(--text-secondary); max-width: 300px;">
                                                    <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($log['description'] ?? ''); ?>">
                                                        <?php echo htmlspecialchars($log['description'] ?? '-'); ?>
                                                    </div>
                                                </td>
                                                <td style="padding: 12px; font-size: 13px; color: var(--text-secondary);">
                                                    <?php 
                                                    $user_name = $log['full_name'] ?? $log['email'] ?? 'Unknown';
                                                    echo htmlspecialchars($user_name);
                                                    ?>
                                                </td>
                                                <td style="padding: 12px; font-size: 13px; color: var(--text-muted);">
                                                    <?php echo date('M d, Y g:i A', strtotime($log['created_at'])); ?>
                                                </td>
                                                <td style="padding: 12px; text-align: center;">
                                                    <?php if ($can_edit): ?>
                                                        <button onclick="editTimeLog(<?php echo $log['id']; ?>)" style="background: var(--blue); color: white; border: none; padding: 6px 12px; border-radius: 4px; font-size: 12px; cursor: pointer; margin-right: 6px;" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button onclick="deleteTimeLog(<?php echo $log['id']; ?>)" style="background: #ef4444; color: white; border: none; padding: 6px 12px; border-radius: 4px; font-size: 12px; cursor: pointer;" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <span style="color: var(--text-muted); font-size: 12px;">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Edit Time Log Modal -->
                        <div id="edit-timelog-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
                            <div style="background: white; border-radius: 12px; padding: 24px; width: 90%; max-width: 500px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                    <h3 style="margin: 0; font-size: 20px; font-weight: 600; color: var(--text-primary);">Edit Time Log</h3>
                                    <button onclick="closeEditTimeLogModal()" style="background: none; border: none; font-size: 24px; color: var(--text-muted); cursor: pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 4px;" onmouseover="this.style.backgroundColor='var(--page-bg)'" onmouseout="this.style.backgroundColor='transparent'">&times;</button>
                                </div>
                                <form id="edit-timelog-form" onsubmit="updateTimeLog(event)">
                                    <input type="hidden" id="edit-timelog-id" name="timelog_id">
                                    <div style="margin-bottom: 16px;">
                                        <label style="display: block; margin-bottom: 6px; font-size: 14px; font-weight: 500; color: var(--text-primary);">Date *</label>
                                        <input type="date" id="edit-timelog-date" name="log_date" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; box-sizing: border-box;">
                                    </div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                                        <div>
                                            <label style="display: block; margin-bottom: 6px; font-size: 14px; font-weight: 500; color: var(--text-primary);">Hours *</label>
                                            <input type="number" id="edit-timelog-hours" name="hours" min="0" max="23" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; box-sizing: border-box;">
                                        </div>
                                        <div>
                                            <label style="display: block; margin-bottom: 6px; font-size: 14px; font-weight: 500; color: var(--text-primary);">Minutes *</label>
                                            <input type="number" id="edit-timelog-minutes" name="minutes" min="0" max="59" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; box-sizing: border-box;">
                                        </div>
                                    </div>
                                    <div style="margin-bottom: 20px;">
                                        <label style="display: block; margin-bottom: 6px; font-size: 14px; font-weight: 500; color: var(--text-primary);">Description</label>
                                        <textarea id="edit-timelog-description" name="description" rows="3" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; box-sizing: border-box; resize: vertical;" placeholder="What did you work on?"></textarea>
                                    </div>
                                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                                        <button type="button" onclick="closeEditTimeLogModal()" style="background: var(--page-bg); color: var(--text-primary); border: 1px solid var(--border-color); padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer;">Cancel</button>
                                        <button type="submit" style="background: var(--blue); color: white; border: none; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer;">Update Time</button>
                                    </div>
                                </form>
                            </div>
                        </div>
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
                                    <div class="comment-avatars" id="comment-avatars">
                                        <?php 
                                        // Get unique commenters (by user_id) to show avatars
                                        $unique_commenters = [];
                                        $seen_user_ids = [];
                                        foreach ($comments as $commenter) {
                                            $user_id = isset($commenter['user_id']) ? $commenter['user_id'] : (isset($commenter['user_table_id']) ? $commenter['user_table_id'] : null);
                                            if ($user_id && !in_array($user_id, $seen_user_ids)) {
                                                $unique_commenters[] = $commenter;
                                                $seen_user_ids[] = $user_id;
                                            }
                                        }
                                        // Show up to 3 unique commenters
                                        $recent_commenters = array_slice($unique_commenters, 0, 3);
                                        foreach ($recent_commenters as $commenter):
                                            $commenter_initials = getInitials($commenter['user_name']);
                                        ?>
                                            <div class="comment-avatar-small" title="<?php echo htmlspecialchars($commenter['user_name']); ?>">
                                                <?php echo htmlspecialchars($commenter_initials); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <button type="submit" name="add_comment" id="comment-submit-btn" class="task-action-btn" style="background: var(--blue); color: white; border: none;">
                                    <i class="fas fa-paper-plane"></i>
                                    <span>Send</span>
                                </button>
                            </div>
                            <input type="hidden" name="parent_id" id="parent_id" value="">
                            <input type="hidden" name="task_id" value="<?php echo $task_id; ?>">
                        </form>
                    </div>
                    
                    <!-- Recent Comments -->
                    <div class="comments-list" id="comments-list" style="max-height: 600px; overflow-y: auto;">
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
                                    <div class="comment-content" style="flex: 1;">
                                        <div class="comment-header" style="display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <span class="comment-author"><?php echo htmlspecialchars($comment['user_name']); ?></span>
                                                <span class="comment-date"><?php echo date('d-m-Y H:i A', strtotime($comment['created_at'])); ?></span>
                                            </div>
                                            <?php 
                                            $can_edit_comment = ($comment['user_id'] == $_SESSION['user_id']) || isSuperAdmin() || isOrgAdmin();
                                            if ($can_edit_comment): 
                                            ?>
                                                <div style="display: flex; gap: 8px;">
                                                    <button onclick="editComment(<?php echo $comment['id']; ?>)" style="background: none; border: none; color: var(--blue); cursor: pointer; font-size: 12px; padding: 4px 8px;" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button onclick="deleteComment(<?php echo $comment['id']; ?>)" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 12px; padding: 4px 8px;" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="comment-text" id="comment-text-<?php echo $comment['id']; ?>">
                                            <?php echo nl2br($comment_text); ?>
                                        </div>
                                        <div id="comment-edit-<?php echo $comment['id']; ?>" style="display: none;">
                                            <textarea id="comment-edit-text-<?php echo $comment['id']; ?>" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px; min-height: 80px; resize: vertical;"><?php echo htmlspecialchars($comment['comment']); ?></textarea>
                                            <div style="display: flex; gap: 8px; margin-top: 8px;">
                                                <button onclick="saveComment(<?php echo $comment['id']; ?>)" style="background: var(--blue); color: white; border: none; padding: 6px 12px; border-radius: 4px; font-size: 12px; cursor: pointer;">Save</button>
                                                <button onclick="cancelEditComment(<?php echo $comment['id']; ?>)" style="background: var(--page-bg); color: var(--text-primary); border: 1px solid var(--border-color); padding: 6px 12px; border-radius: 4px; font-size: 12px; cursor: pointer;">Cancel</button>
                                            </div>
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
              <!-- Right Sidebar: Task Details -->
        <div class="task-details-sidebar">
            <!-- Task Details Card: Type, Status, Priority, Due Date, Assignee -->
            <form method="POST" action="" id="taskUpdateForm">
                <input type="hidden" name="update_task" value="1">
                <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                
                <div class="task-details-card-new">
                    <?php 
                    // Get status info for styling using status_id
                    $task_status_id = $task['status_id'] ?? null;
                    $task_status = $task['status'] ?? 'To Do';
                    
                    // Get status info by ID if available, otherwise by name
                    if ($task_status_id) {
                        $status_info = null;
                        foreach ($statuses as $s) {
                            if ($s['id'] == $task_status_id) {
                                $status_info = $s;
                                break;
                            }
                        }
                    } else {
                        $status_info = getStatusByName($task_status, $organization_id);
                    }
                    $status_color = $status_info['color'] ?? '#6c757d';
                    
                    $priority_lower = strtolower($task['priority'] ?? '');
                    ?>
                    
                    <!-- Type -->
                    <div class="detail-item" style="display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border-color);">
                        <label style="font-weight: 600; color: var(--text-primary); font-size: 13px; margin: 0; white-space: nowrap; min-width: 100px;">
                            <i class="fas <?php echo $type_icon; ?>" style="color: <?php echo $type_color; ?>; margin-right: 6px;"></i>Type
                        </label>
                        <select name="type" id="task-type" onchange="markChanged()"
                                style="flex: 1; padding: 6px 10px; border: 1px solid var(--border-color);  background: var(--card-bg); color: var(--text-primary); font-size: 13px; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 12 12\'%3E%3Cpath fill=\'%236B7280\' d=\'M6 9L1 4h10z\'/%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 12px center;">
                            <option value="Task" <?php echo ($task['type'] == 'Task') ? 'selected' : ''; ?>>Task</option>
                            <option value="Bug" <?php echo ($task['type'] == 'Bug') ? 'selected' : ''; ?>>Bug</option>
                            <option value="Improvement" <?php echo ($task['type'] == 'Improvement') ? 'selected' : ''; ?>>Improvement</option>
                        </select>
                    </div>
                    
                    <!-- Status -->
                    <div class="detail-item" style="display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border-color);">
                        <label style="font-weight: 600; color: var(--text-primary); font-size: 13px; margin: 0; white-space: nowrap; min-width: 100px;">
                            <i class="fas fa-flag" style="color: <?php echo htmlspecialchars($status_color); ?>; margin-right: 6px;"></i>Status
                        </label>
                        <select name="status_id" id="task-status" onchange="updateStatusColor(); markChanged();"
                                style="flex: 1; padding: 6px 10px; border: 1px solid var(--border-color);  background: <?php echo htmlspecialchars($status_color); ?>; color: white; font-size: 13px; font-weight: 500; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 12 12\'%3E%3Cpath fill=\'%23ffffff\' d=\'M6 9L1 4h10z\'/%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 12px center;">
                            <?php foreach ($statuses as $status_option): ?>
                                <option value="<?php echo $status_option['id']; ?>" data-color="<?php echo htmlspecialchars($status_option['color'] ?? '#6c757d'); ?>" <?php echo ($task_status_id == $status_option['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($status_option['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Priority -->
                    <div class="detail-item" style="display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border-color);">
                        <label style="font-weight: 600; color: var(--text-primary); font-size: 13px; margin: 0; white-space: nowrap; min-width: 100px;">
                            <i class="fas fa-exclamation-circle" style="color: var(--chart-yellow); margin-right: 6px;"></i>Priority
                        </label>
                        <select name="priority" id="task-priority" onchange="markChanged()"
                                style="flex: 1; padding: 6px 10px; border: 1px solid var(--border-color);  background: var(--card-bg); color: var(--text-primary); font-size: 13px; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 12 12\'%3E%3Cpath fill=\'%236B7280\' d=\'M6 9L1 4h10z\'/%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 12px center;">
                            <option value="Low" <?php echo ($priority_lower == 'low') ? 'selected' : ''; ?>>Low</option>
                            <option value="Medium" <?php echo ($priority_lower == 'medium') ? 'selected' : ''; ?>>Medium</option>
                            <option value="High" <?php echo ($priority_lower == 'high') ? 'selected' : ''; ?>>High</option>
                        </select>
                    </div>
                    
                    <!-- Due Date -->
                    <div class="detail-item" style="display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border-color);">
                        <label style="font-weight: 600; color: var(--text-primary); font-size: 13px; margin: 0; white-space: nowrap; min-width: 100px;">
                            <i class="fas fa-calendar" style="color: var(--chart-yellow); margin-right: 6px;"></i>Due Date
                        </label>
                        <?php if (isAdmin() || isProjectManager() || $task['assignee_id'] == $_SESSION['user_id']): ?>
                            <div style="display: flex; align-items: center; gap: 8px; flex: 1;">
                                <input type="date" name="due_date" id="task-due-date" onchange="markChanged()"
                                       value="<?php echo $task['due_date'] ? date('Y-m-d', strtotime($task['due_date'])) : ''; ?>" 
                                       style="flex: 1; padding: 6px 10px; border: 1px solid var(--border-color);  font-size: 13px; background: var(--card-bg); color: var(--text-primary);">
                                <?php if ($task['due_date']): ?>
                                    <button type="button" onclick="clearDueDateField()" 
                                            style="padding: 4px 8px; background: var(--text-muted); color: white; border: none; border-radius: 4px; font-size: 11px; cursor: pointer; white-space: nowrap;"
                                            title="Clear Due Date">
                                        <i class="fas fa-times"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span style="color: var(--text-secondary); font-size: 13px; flex: 1;">
                                <?php echo $task['due_date'] ? date('d-m-Y', strtotime($task['due_date'])) : 'No due date'; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Assignee -->
                    <div class="detail-item" style="display: flex; align-items: center; gap: 12px; padding: 12px 0;">
                        <label style="font-weight: 600; color: var(--text-primary); font-size: 13px; margin: 0; white-space: nowrap; min-width: 100px;">
                            <i class="fas fa-user" style="color: var(--blue); margin-right: 6px;"></i>Assignee
                        </label>
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
                            <?php if (isAdmin() || isProjectManager()): ?>
                                <select name="assignee_id" id="task-assignee" onchange="markChanged()"
                                        style="flex: 1; padding: 6px 10px; border: 1px solid var(--border-color);  font-size: 13px; background: var(--card-bg); color: var(--text-primary);">
                                    <option value="">Change Assignee</option>
                                    <?php foreach ($users_list as $user): ?>
                                        <option value="<?php echo $user['id']; ?>" <?php echo ($task['assignee_id'] == $user['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <div style="flex: 1; display: flex; align-items: center; gap: 10px;">
                                    <div class="assignee-avatar-large">
                                        <?php echo htmlspecialchars($assignee_initials); ?>
                                    </div>
                                    <div class="assignee-info">
                                        <div class="assignee-name"><?php echo htmlspecialchars($task['assignee_name']); ?></div>
                                        <div class="assignee-role"><?php echo htmlspecialchars($assignee_role); ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if (isAdmin() || isProjectManager()): ?>
                                <select name="assignee_id" id="task-assignee" onchange="markChanged()"
                                        style="flex: 1; padding: 6px 10px; border: 1px solid var(--border-color);  font-size: 13px; background: var(--card-bg); color: var(--text-primary);">
                                    <option value="">Assign to...</option>
                                    <?php foreach ($users_list as $user): ?>
                                        <option value="<?php echo $user['id']; ?>">
                                            <?php echo htmlspecialchars($user['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-size: 13px; flex: 1;">
                                    <i class="fas fa-user-slash" style="margin-right: 6px;"></i>Unassigned
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
            
           
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
    
    // Save active tab to localStorage
    const taskId = new URLSearchParams(window.location.search).get('id');
    if (taskId) {
        localStorage.setItem('task_view_active_tab_' + taskId, tabName);
    }
}

// Restore active tab on page load
document.addEventListener('DOMContentLoaded', function() {
    const taskId = new URLSearchParams(window.location.search).get('id');
    if (taskId) {
        const savedTab = localStorage.getItem('task_view_active_tab_' + taskId);
        if (savedTab) {
            switchMainTab(savedTab);
        }
    }
});

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

// Update task priority (now just marks as changed, doesn't submit)
function updateTaskPriority(taskId, priority) {
    const prioritySelect = document.getElementById('task-priority');
    if (prioritySelect) {
        prioritySelect.value = priority;
        markChanged();
    }
}

// Clear due date
function clearDueDate() {
    if (confirm('Are you sure you want to clear the due date?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        const updateInput = document.createElement('input');
        updateInput.type = 'hidden';
        updateInput.name = 'update_due_date';
        updateInput.value = '1';
        form.appendChild(updateInput);
        const dueDateInput = document.createElement('input');
        dueDateInput.type = 'hidden';
        dueDateInput.name = 'due_date';
        dueDateInput.value = '';
        form.appendChild(dueDateInput);
        document.body.appendChild(form);
        form.submit();
    }
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
        (user.email && user.email.toLowerCase().includes(searchTerm))
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

<!-- Quill.js Rich Text Editor -->
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script>
// Quill editor instance for description
let descriptionEditor = null;

// Image upload handler for Quill
async function uploadImage(file) {
    return new Promise((resolve, reject) => {
        // Validate file size (max 5MB)
        if (file.size > 5 * 1024 * 1024) {
            reject('File size exceeds 5MB limit');
            return;
        }
        
        const formData = new FormData();
        formData.append('upload', file);
        formData.append('type', 'task_attachment');
        formData.append('task_id', <?php echo $task_id; ?>);

        fetch('upload.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(result => {
            if (result.url) {
                resolve(result.url);
            } else {
                reject(result.error?.message || 'Upload failed');
            }
        })
        .catch(error => {
            reject('Upload failed: ' + error.message);
        });
    });
}

// Store original values
let originalValues = {
    title: '',
    description: '',
    type: '',
    status_id: '',
    priority: '',
    assignee_id: '',
    due_date: ''
};

// Initialize original values on page load
document.addEventListener('DOMContentLoaded', function() {
    const titleText = document.getElementById('task-title-text');
    const descriptionDisplay = document.getElementById('task-description-display');
    const typeSelect = document.getElementById('task-type');
    const statusSelect = document.getElementById('task-status');
    const prioritySelect = document.getElementById('task-priority');
    const assigneeSelect = document.getElementById('task-assignee');
    const dueDateInput = document.getElementById('task-due-date');
    
    if (titleText) originalValues.title = titleText.textContent.trim();
    if (descriptionDisplay) {
        const descText = descriptionDisplay.textContent.trim();
        originalValues.description = descText === 'Click to add description' ? '' : descText;
    }
    if (typeSelect) originalValues.type = typeSelect.value;
    if (statusSelect) originalValues.status_id = statusSelect.value;
    if (prioritySelect) originalValues.priority = prioritySelect.value;
    if (assigneeSelect) originalValues.assignee_id = assigneeSelect ? assigneeSelect.value : '';
    if (dueDateInput) originalValues.due_date = dueDateInput.value;
    
    // Add change listeners
    if (typeSelect) typeSelect.addEventListener('change', checkForChanges);
    if (statusSelect) statusSelect.addEventListener('change', function() { updateStatusColor(); checkForChanges(); });
    if (prioritySelect) prioritySelect.addEventListener('change', checkForChanges);
    if (assigneeSelect) assigneeSelect.addEventListener('change', checkForChanges);
    if (dueDateInput) dueDateInput.addEventListener('change', checkForChanges);
    
    // Add input listeners for title
    const titleInput = document.getElementById('task-title-input');
    if (titleInput) {
        titleInput.addEventListener('input', checkForChanges);
    }
});

// Mark that changes have been made
function markChanged() {
    const saveBtn = document.getElementById('save-task-btn');
    if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.style.background = 'var(--blue)';
        saveBtn.style.cursor = 'pointer';
        saveBtn.style.opacity = '1';
        saveBtn.title = 'Save all changes';
    }
}

// Check if values have actually changed
function checkForChanges() {
    const saveBtn = document.getElementById('save-task-btn');
    if (!saveBtn) return;
    
    // Get current values
    const titleInput = document.getElementById('task-title-input');
    const titleDisplay = document.getElementById('task-title-text');
    const currentTitle = titleInput && window.getComputedStyle(titleInput.parentElement).display !== 'none' 
        ? titleInput.value.trim() 
        : (titleDisplay ? titleDisplay.textContent.trim() : '');
    
    const descEditor = document.getElementById('task-description-editor');
    const descDisplay = document.getElementById('task-description-display');
    let currentDesc = '';
    if (descriptionEditor && window.getComputedStyle(document.getElementById('task-description-edit')).display !== 'none') {
        currentDesc = descriptionEditor.root.innerHTML.trim();
    } else if (descDisplay) {
        const currentHtml = descDisplay.innerHTML.trim();
        if (currentHtml && !currentHtml.includes('Click to add description')) {
            currentDesc = currentHtml;
        }
    }
    
    const typeSelect = document.getElementById('task-type');
    const statusSelect = document.getElementById('task-status');
    const prioritySelect = document.getElementById('task-priority');
    const assigneeSelect = document.getElementById('task-assignee');
    const dueDateInput = document.getElementById('task-due-date');
    
    // Compare with original values
    const hasChanges = 
        (currentTitle !== originalValues.title) ||
        (currentDesc !== originalValues.description) ||
        (typeSelect && typeSelect.value !== originalValues.type) ||
        (statusSelect && statusSelect.value !== originalValues.status_id) ||
        (prioritySelect && prioritySelect.value !== originalValues.priority) ||
        (assigneeSelect && assigneeSelect.value !== originalValues.assignee_id) ||
        (dueDateInput && dueDateInput.value !== originalValues.due_date);
    
    if (hasChanges) {
        markChanged();
    } else {
        saveBtn.disabled = true;
        saveBtn.style.background = 'var(--text-muted)';
        saveBtn.style.cursor = 'not-allowed';
        saveBtn.style.opacity = '0.6';
        saveBtn.title = 'No changes to save';
    }
}

// Update status color when status changes
function updateStatusColor() {
    const statusSelect = document.getElementById('task-status');
    if (statusSelect) {
        const selectedOption = statusSelect.options[statusSelect.selectedIndex];
        const color = selectedOption.getAttribute('data-color') || '#6c757d';
        statusSelect.style.background = color;
    }
}

// Save all changes
function saveAllChanges() {
    const form = document.getElementById('taskUpdateForm');
    if (!form) return;
    
    // Get title value
    const titleInput = document.getElementById('task-title-input');
    const titleDisplay = document.getElementById('task-title-text');
    let titleValue = '';
    
    if (titleInput && window.getComputedStyle(titleInput.parentElement).display !== 'none') {
        // Title is in edit mode
        titleValue = titleInput.value.trim();
    } else if (titleDisplay) {
        // Title is in display mode, use current value
        titleValue = titleDisplay.textContent.trim();
    }
    
    if (titleValue) {
        const titleField = document.createElement('input');
        titleField.type = 'hidden';
        titleField.name = 'title';
        titleField.value = titleValue;
        form.appendChild(titleField);
    }
    
    // Get description value
    const descEditor = document.getElementById('task-description-editor');
    const descDisplay = document.getElementById('task-description-display');
    let descValue = '';
    
    if (descEditor && window.getComputedStyle(descEditor.parentElement).display !== 'none') {
        // Description is in edit mode
        if (descriptionEditor) {
            // Use Quill editor data
            descValue = descriptionEditor.root.innerHTML.trim();
        } else {
            descValue = descEditor.innerHTML.trim();
        }
    } else if (descDisplay) {
        // Description is in display mode, extract HTML content
        descValue = descDisplay.innerHTML.trim();
        if (descValue.includes('Click to add description')) descValue = '';
    }
    
    const descField = document.createElement('input');
    descField.type = 'hidden';
    descField.name = 'description';
    descField.value = descValue;
    form.appendChild(descField);
    
    form.submit();
}

// Edit Title Function
function editTitle() {
    const displayDiv = document.getElementById('task-title-display');
    const editDiv = document.getElementById('task-title-edit');
    const titleInput = document.getElementById('task-title-input');
    const titleText = document.getElementById('task-title-text');
    
    if (displayDiv && editDiv && titleInput && titleText) {
        displayDiv.style.display = 'none';
        editDiv.style.display = 'block';
        titleInput.value = titleText.textContent.trim();
        titleInput.focus();
        titleInput.select();
        markChanged();
    }
}

// Handle title keydown
function handleTitleKeydown(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        cancelTitleEdit();
        // Only save if there are actual changes
        const saveBtn = document.getElementById('save-task-btn');
        if (saveBtn && !saveBtn.disabled) {
            saveAllChanges();
        }
    } else if (event.key === 'Escape') {
        cancelTitleEdit();
    } else {
        // Check for changes on any key input
        setTimeout(checkForChanges, 50);
    }
}

// Cancel Title Edit
function cancelTitleEdit() {
    const displayDiv = document.getElementById('task-title-display');
    const editDiv = document.getElementById('task-title-edit');
    const titleInput = document.getElementById('task-title-input');
    const titleText = document.getElementById('task-title-text');
    
    if (displayDiv && editDiv && titleInput && titleText) {
        // Don't reset value, keep changes for save button
        displayDiv.style.display = 'block';
        editDiv.style.display = 'none';
        // Update display text if changed
        if (titleInput.value !== titleText.textContent.trim()) {
            titleText.textContent = titleInput.value;
        }
        // Check for changes after canceling
        setTimeout(checkForChanges, 100);
    }
}

// Edit Description Function
function editDescription() {
    const displayDiv = document.getElementById('task-description-display');
    const editDiv = document.getElementById('task-description-edit');
    const editorDiv = document.getElementById('task-description-editor');
    
    if (displayDiv && editDiv && editorDiv) {
        displayDiv.style.display = 'none';
        editDiv.style.display = 'block';
        
        // Get current description HTML content
        let descContent = '';
        const displayHtml = displayDiv.innerHTML.trim();
        if (displayHtml !== '' && !displayHtml.includes('Click to add description')) {
            // Extract HTML content from display - get inner content only
            // Clone the div to get clean HTML without wrapper styles
            const tempDiv = displayDiv.cloneNode(true);
            // Remove any wrapper paragraph with styles if it's just the placeholder
            const placeholderP = tempDiv.querySelector('p[style*="text-muted"]');
            if (placeholderP && placeholderP.textContent.includes('Click to add description')) {
                descContent = '';
            } else {
                descContent = tempDiv.innerHTML;
            }
        } else {
            descContent = '';
        }
        
        // Initialize Quill editor if not already initialized
        if (!descriptionEditor) {
            // Configure Quill toolbar with image and file support
            const toolbarOptions = [
                [{ 'header': [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                [{ 'color': [] }, { 'background': [] }],
                [{ 'align': [] }],
                ['link', 'image'],
                ['clean']
            ];
            
            // Clear the div before initializing Quill
            editorDiv.innerHTML = '';
            
            try {
                descriptionEditor = new Quill(editorDiv, {
                    theme: 'snow',
                    modules: {
                        toolbar: toolbarOptions
                    },
                    placeholder: 'Add description...'
                });
                
                // Set content
                if (descContent) {
                    descriptionEditor.root.innerHTML = descContent;
                }
                
                // Set minimum height and ensure editor is focusable
                setTimeout(() => {
                    const qlEditor = editorDiv.querySelector('.ql-editor');
                    if (qlEditor) {
                        qlEditor.style.minHeight = '400px';
                        qlEditor.setAttribute('contenteditable', 'true');
                        qlEditor.tabIndex = 0;
                        // Focus the editor
                        qlEditor.focus();
                    }
                }, 100);
            } catch (error) {
                console.error('Error initializing Quill editor:', error);
                // Fallback to textarea if Quill fails
                editorDiv.innerHTML = '<textarea style="width: 100%; min-height: 400px; padding: 12px; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 14px; resize: vertical;">' + (descContent || '') + '</textarea>';
                const textarea = editorDiv.querySelector('textarea');
                if (textarea) {
                    textarea.focus();
                }
            }
            
            // Handle image upload on paste or toolbar click
            const toolbar = descriptionEditor.getModule('toolbar');
            toolbar.addHandler('image', function() {
                const input = document.createElement('input');
                input.setAttribute('type', 'file');
                input.setAttribute('accept', 'image/*');
                input.click();
                
                input.onchange = async () => {
                    const file = input.files[0];
                    if (file) {
                        const range = descriptionEditor.getSelection(true);
                        try {
                            const url = await uploadImage(file);
                            descriptionEditor.insertEmbed(range.index, 'image', url);
                            descriptionEditor.setSelection(range.index + 1);
                        } catch (error) {
                            console.error('Image upload failed:', error);
                            alert('Failed to upload image: ' + error);
                        }
                    }
                };
            });
            
            // Handle paste events for images from clipboard
            descriptionEditor.clipboard.addMatcher(Node.ELEMENT_NODE, (node, delta) => {
                if (node.tagName === 'IMG') {
                    const src = node.getAttribute('src');
                    if (src && src.startsWith('data:')) {
                        // Convert data URL to blob and upload
                        fetch(src)
                            .then(res => res.blob())
                            .then(blob => {
                                const file = new File([blob], 'pasted-image.png', { type: 'image/png' });
                                uploadImage(file).then(url => {
                                    let range = descriptionEditor.getSelection(true);
                                    if (!range) {
                                        const length = descriptionEditor.getLength();
                                        range = { index: length - 1, length: 0 };
                                    }
                                    descriptionEditor.deleteText(range.index, 1);
                                    descriptionEditor.insertEmbed(range.index, 'image', url);
                                    descriptionEditor.setSelection(range.index + 1);
                                }).catch(err => console.error('Upload failed:', err));
                            });
                    }
                }
                return delta;
            });
            
            // Handle direct clipboard paste (for images copied from browser/file system)
            descriptionEditor.root.addEventListener('paste', async function(e) {
                const items = e.clipboardData.items;
                
                for (let i = 0; i < items.length; i++) {
                    const item = items[i];
                    
                    // Check if the pasted item is an image
                    if (item.type.indexOf('image') !== -1) {
                        e.preventDefault();
                        
                        const file = item.getAsFile();
                        if (file) {
                            try {
                                // Get current selection or use end of document
                                let range = descriptionEditor.getSelection(true);
                                if (!range) {
                                    // If no selection, insert at the end
                                    const length = descriptionEditor.getLength();
                                    range = { index: length - 1, length: 0 };
                                }
                                
                                // Upload the image
                                const url = await uploadImage(file);
                                
                                // Insert the image at the current cursor position
                                descriptionEditor.insertEmbed(range.index, 'image', url);
                                descriptionEditor.setSelection(range.index + 1);
                            } catch (error) {
                                console.error('Image paste upload failed:', error);
                                alert('Failed to upload pasted image: ' + error);
                            }
                        }
                        break;
                    }
                }
            });
            
            // Listen for changes
            descriptionEditor.on('text-change', function() {
                checkForChanges();
            });
        } else {
            // Editor already exists, just update the content
            if (descContent) {
                descriptionEditor.root.innerHTML = descContent;
            }
            // Focus the editor
            const qlEditor = editorDiv.querySelector('.ql-editor');
            if (qlEditor) {
                qlEditor.focus();
            }
        }
        
        markChanged();
    }
}

// Handle description keydown (for CKEditor)
function handleDescriptionKeydown(event) {
    if (event.key === 'Escape') {
        cancelDescriptionEdit();
    }
    // Ctrl+Enter or Cmd+Enter to save
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();
        cancelDescriptionEdit();
        // Only save if there are actual changes
        const saveBtn = document.getElementById('save-task-btn');
        if (saveBtn && !saveBtn.disabled) {
            saveAllChanges();
        }
    }
}

// Cancel Description Edit
function cancelDescriptionEdit() {
    const displayDiv = document.getElementById('task-description-display');
    const editDiv = document.getElementById('task-description-edit');
    
    if (displayDiv && editDiv) {
        // Get current content from CKEditor if it exists
        let newValue = '';
        if (descriptionEditor) {
            newValue = descriptionEditor.getData().trim();
        }
        
        const currentText = displayDiv.textContent.trim();
        const currentTextClean = currentText === 'Click to add description' ? '' : currentText;
        const currentHtml = displayDiv.innerHTML.trim();
        
        displayDiv.style.display = 'block';
        editDiv.style.display = 'none';
        
        // Update display if changed (compare HTML content)
        if (newValue !== currentHtml && newValue !== currentTextClean) {
            if (newValue) {
                displayDiv.innerHTML = newValue;
            } else {
                displayDiv.innerHTML = '<p style="color: var(--text-muted); font-style: italic; margin: 0;">Click to add description</p>';
            }
        }
        // Check for changes after canceling
        setTimeout(checkForChanges, 100);
    }
}

// Clear due date field
function clearDueDateField() {
    const dueDateInput = document.getElementById('task-due-date');
    if (dueDateInput) {
        dueDateInput.value = '';
        checkForChanges();
    }
}

// Handle comment form submission via AJAX
document.addEventListener('DOMContentLoaded', function() {
    const commentForm = document.getElementById('commentForm');
    const commentTextarea = document.getElementById('comment');
    const commentsList = document.getElementById('comments-list');
    const commentAvatars = document.getElementById('comment-avatars');
    const submitBtn = document.getElementById('comment-submit-btn');
    const CURRENT_USER_ID = <?php echo intval($_SESSION['user_id'] ?? 0); ?>;
    const IS_COMMENT_ADMIN = <?php echo (isSuperAdmin() || isOrgAdmin()) ? 'true' : 'false'; ?>;
    
    if (commentForm && submitBtn) {
        commentForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const commentText = commentTextarea.value.trim();
            if (!commentText) {
                return;
            }
            
            // Disable submit button
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Sending...</span>';
            
            // Prepare form data
            const formData = new FormData(commentForm);
            formData.append('add_comment', '1');
            
            // Get task ID from hidden input or URL
            const taskId = document.querySelector('input[name="task_id"]')?.value || new URLSearchParams(window.location.search).get('id');
            
            // Send AJAX request
            fetch('task_view.php?id=' + taskId, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Clear textarea
                    commentTextarea.value = '';
                    
                    // Add new comment to the list
                    addCommentToDOM(data.comment);
                    
                    // Update comment avatars
                    updateCommentAvatars();
                    
                    // Show success message (optional)
                    showCommentMessage('Comment added successfully', 'success');
                } else {
                    // Show error message
                    showCommentMessage(data.error || 'Error adding comment', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showCommentMessage('Error adding comment. Please try again.', 'error');
            })
            .finally(() => {
                // Re-enable submit button
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> <span>Send</span>';
            });
        });
    }
    
    // Function to add comment to DOM
    function addCommentToDOM(comment) {
        // Remove "no comments" message if exists
        const noComments = commentsList.querySelector('.no-comments');
        if (noComments) {
            noComments.remove();
        }
        
        // Generate initials
        const nameParts = comment.user_name.split(' ');
        let initials = '';
        if (nameParts.length >= 2) {
            initials = (nameParts[0].charAt(0) + nameParts[nameParts.length - 1].charAt(0)).toUpperCase();
        } else {
            initials = comment.user_name.substring(0, 2).toUpperCase();
        }
        
        // Format comment text with mentions
        let commentText = escapeHtml(comment.comment);
        commentText = commentText.replace(/@(\w+)/g, '<span class="mention">@$1</span>');
        commentText = commentText.replace(/\n/g, '<br>');
        
        // Format date
        const commentDate = new Date(comment.created_at);
        const formattedDate = formatDate(commentDate);
        
        // Create comment HTML
        const commentUserId = parseInt(comment.user_id || comment.user_table_id || 0, 10);
        const canEdit = (commentUserId === CURRENT_USER_ID) || IS_COMMENT_ADMIN;
        const actionsHtml = canEdit ? `
            <div style="display:flex; gap:8px;">
                <button onclick="editComment(${comment.id})" style="background:none; border:none; color: var(--blue); cursor:pointer; font-size:12px; padding:4px 8px;" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
                <button onclick="deleteComment(${comment.id})" style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:12px; padding:4px 8px;" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        ` : '';

        const commentHTML = `
            <div class="comment-item" id="comment-${comment.id}">
                <div class="comment-avatar">
                    ${initials}
                </div>
                <div class="comment-content" style="flex: 1;">
                    <div class="comment-header" style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <span class="comment-author">${escapeHtml(comment.user_name)}</span>
                            <span class="comment-date">${formattedDate}</span>
                        </div>
                        ${actionsHtml}
                    </div>
                    <div class="comment-text" id="comment-text-${comment.id}">
                        ${commentText}
                    </div>
                    <div id="comment-edit-${comment.id}" style="display:none;">
                        <textarea id="comment-edit-text-${comment.id}" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:4px; font-size:14px; min-height:80px; resize:vertical;">${escapeHtml(comment.comment || '')}</textarea>
                        <div style="display:flex; gap:8px; margin-top:8px;">
                            <button onclick="saveComment(${comment.id})" style="background: var(--blue); color:white; border:none; padding:6px 12px; border-radius:4px; font-size:12px; cursor:pointer;">Save</button>
                            <button onclick="cancelEditComment(${comment.id})" style="background: var(--page-bg); color: var(--text-primary); border: 1px solid var(--border-color); padding:6px 12px; border-radius:4px; font-size:12px; cursor:pointer;">Cancel</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Insert at the beginning of comments list (latest on top)
        commentsList.insertAdjacentHTML('afterbegin', commentHTML);
        
        // Scroll to top to show new comment
        const newComment = document.getElementById('comment-' + comment.id);
        if (newComment) {
            // Scroll to top of comments list to show the new comment
            commentsList.scrollTop = 0;
            // Add highlight effect
            newComment.style.backgroundColor = '#e0f2fe';
            setTimeout(() => {
                newComment.style.transition = 'background-color 0.5s';
                newComment.style.backgroundColor = '#ffffff';
            }, 1000);
        }
    }
    
    // Function to update comment avatars
    function updateCommentAvatars() {
        const commentsList = document.getElementById('comments-list');
        const avatarContainer = document.getElementById('comment-avatars');
        if (!commentsList || !avatarContainer) return;
        
        // Get all comment items
        const commentItems = commentsList.querySelectorAll('.comment-item');
        const uniqueCommenters = new Map();
        
        // Extract unique commenters from existing comments
        commentItems.forEach(commentItem => {
            const authorElement = commentItem.querySelector('.comment-author');
            if (authorElement) {
                const userName = authorElement.textContent.trim();
                const commentId = commentItem.id.replace('comment-', '');
                
                // Use user name as key to ensure uniqueness
                if (!uniqueCommenters.has(userName)) {
                    // Generate initials
                    const nameParts = userName.split(' ');
                    let initials = '';
                    if (nameParts.length >= 2) {
                        initials = (nameParts[0].charAt(0) + nameParts[nameParts.length - 1].charAt(0)).toUpperCase();
                    } else {
                        initials = userName.substring(0, 2).toUpperCase();
                    }
                    
                    uniqueCommenters.set(userName, {
                        name: userName,
                        initials: initials
                    });
                }
            }
        });
        
        // Clear existing avatars
        avatarContainer.innerHTML = '';
        
        // Add unique avatars (max 3)
        const uniqueArray = Array.from(uniqueCommenters.values()).slice(0, 3);
        uniqueArray.forEach(commenter => {
            const avatarDiv = document.createElement('div');
            avatarDiv.className = 'comment-avatar-small';
            avatarDiv.title = commenter.name;
            avatarDiv.textContent = commenter.initials;
            avatarContainer.appendChild(avatarDiv);
        });
    }
    
    // Helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Helper function to format date
    function formatDate(date) {
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        const ampm = date.getHours() >= 12 ? 'PM' : 'AM';
        const displayHours = date.getHours() % 12 || 12;
        return `${day}-${month}-${year} ${displayHours}:${minutes} ${ampm}`;
    }
    
    // Function to show comment message
    function showCommentMessage(message, type) {
        // Remove existing message if any
        const existingMsg = document.querySelector('.comment-message');
        if (existingMsg) {
            existingMsg.remove();
        }
        
        // Create message element
        const msgDiv = document.createElement('div');
        msgDiv.className = 'comment-message';
        msgDiv.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            background: ${type === 'success' ? '#10b981' : '#ef4444'};
            color: white;
            border-radius: 6px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            z-index: 10000;
            font-size: 14px;
            animation: slideIn 0.3s ease;
        `;
        msgDiv.textContent = message;
        document.body.appendChild(msgDiv);
        
        // Remove after 3 seconds
        setTimeout(() => {
            msgDiv.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => msgDiv.remove(), 300);
        }, 3000);
    }
});

// Time Log Modal Functions (Global scope for onclick handlers)
function openAddTimeLogModal() {
    const modal = document.getElementById('add-timelog-modal');
    if (modal) {
        modal.style.display = 'flex';
        // Set today's date as default
        const today = new Date().toISOString().split('T')[0];
        const dateInput = document.getElementById('timelog-date');
        if (dateInput) {
            dateInput.value = today;
        }
    }
}

function closeAddTimeLogModal() {
    const modal = document.getElementById('add-timelog-modal');
    if (modal) {
        modal.style.display = 'none';
        // Reset form
        const form = document.getElementById('add-timelog-form');
        if (form) {
            form.reset();
            const dateInput = document.getElementById('timelog-date');
            if (dateInput) {
                dateInput.value = new Date().toISOString().split('T')[0];
            }
        }
    }
}

// Close modal when clicking outside
document.addEventListener('click', function(e) {
    const modal = document.getElementById('add-timelog-modal');
    if (modal && e.target === modal) {
        closeAddTimeLogModal();
    }
});

// Add Time Log Function
async function addTimeLog(event) {
    event.preventDefault();
    
    const form = document.getElementById('add-timelog-form');
    if (!form) return;
    
    const submitBtn = form.querySelector('button[type="submit"]');
    if (!submitBtn) return;
    
    const originalText = submitBtn.innerHTML;
    
    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
    
    // Prepare form data
    const formData = new FormData(form);
    formData.append('add_timelog', '1');
    
    // Get task ID from URL
    const taskId = new URLSearchParams(window.location.search).get('id');
    
    try {
        const response = await fetch('task_view.php?id=' + taskId, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Close modal
            closeAddTimeLogModal();
            
            // Reload page to show new time log
            window.location.reload();
        } else {
            alert(data.error || 'Error adding time log');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error adding time log. Please try again.');
    } finally {
        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    }
}

// Edit Time Log Functions
function editTimeLog(timelogId) {
    // Fetch time log data
    const taskId = new URLSearchParams(window.location.search).get('id');
    fetch(`task_view.php?id=${taskId}&get_timelog=${timelogId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit-timelog-id').value = data.timelog.id;
                document.getElementById('edit-timelog-date').value = data.timelog.log_date;
                document.getElementById('edit-timelog-hours').value = data.timelog.hours;
                document.getElementById('edit-timelog-minutes').value = data.timelog.minutes;
                document.getElementById('edit-timelog-description').value = data.timelog.description || '';
                document.getElementById('edit-timelog-modal').style.display = 'flex';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading time log');
        });
}

function closeEditTimeLogModal() {
    document.getElementById('edit-timelog-modal').style.display = 'none';
    document.getElementById('edit-timelog-form').reset();
}

async function updateTimeLog(event) {
    event.preventDefault();
    
    const form = document.getElementById('edit-timelog-form');
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    
    const formData = new FormData(form);
    formData.append('update_timelog', '1');
    
    const taskId = new URLSearchParams(window.location.search).get('id');
    
    try {
        const response = await fetch('task_view.php?id=' + taskId, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            closeEditTimeLogModal();
            window.location.reload();
        } else {
            alert(data.error || 'Error updating time log');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error updating time log. Please try again.');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    }
}

async function deleteTimeLog(timelogId) {
    if (!confirm('Are you sure you want to delete this time log?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('delete_timelog', '1');
    formData.append('timelog_id', timelogId);
    
    const taskId = new URLSearchParams(window.location.search).get('id');
    
    try {
        const response = await fetch('task_view.php?id=' + taskId, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.error || 'Error deleting time log');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error deleting time log. Please try again.');
    }
}

// Comment Edit Functions
function editComment(commentId) {
    const commentText = document.getElementById('comment-text-' + commentId);
    const commentEdit = document.getElementById('comment-edit-' + commentId);
    
    if (commentText && commentEdit) {
        commentText.style.display = 'none';
        commentEdit.style.display = 'block';
    }
}

function cancelEditComment(commentId) {
    const commentText = document.getElementById('comment-text-' + commentId);
    const commentEdit = document.getElementById('comment-edit-' + commentId);
    
    if (commentText && commentEdit) {
        commentText.style.display = 'block';
        commentEdit.style.display = 'none';
    }
}

async function saveComment(commentId) {
    const textarea = document.getElementById('comment-edit-text-' + commentId);
    const newComment = textarea.value.trim();
    
    if (!newComment) {
        alert('Comment cannot be empty');
        return;
    }
    
    const formData = new FormData();
    formData.append('edit_comment', '1');
    formData.append('comment_id', commentId);
    formData.append('comment', newComment);
    
    const taskId = new URLSearchParams(window.location.search).get('id');
    
    try {
        const response = await fetch('task_view.php?id=' + taskId, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Update comment text
            const commentText = document.getElementById('comment-text-' + commentId);
            let formattedText = escapeHtml(newComment);
            formattedText = formattedText.replace(/@(\w+)/g, '<span class="mention">@$1</span>');
            formattedText = formattedText.replace(/\n/g, '<br>');
            commentText.innerHTML = formattedText;
            
            cancelEditComment(commentId);
        } else {
            alert(data.error || 'Error updating comment');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error updating comment. Please try again.');
    }
}

async function deleteComment(commentId) {
    if (!confirm('Are you sure you want to delete this comment?')) {
        return;
    }

    const formData = new FormData();
    formData.append('delete_comment', '1');
    formData.append('comment_id', commentId);

    const taskId = new URLSearchParams(window.location.search).get('id');

    try {
        const response = await fetch('task_view.php?id=' + taskId, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            const el = document.getElementById('comment-' + commentId);
            if (el) el.remove();

            const list = document.getElementById('comments-list');
            if (list && list.querySelectorAll('.comment-item').length === 0) {
                list.innerHTML = `
                    <div class="no-comments" style="text-align: center; padding: 30px; color: var(--text-muted); font-size: 13px;">
                        <i class="fas fa-comment-slash" style="font-size: 24px; margin-bottom: 8px; opacity: 0.3;"></i>
                        <p>No comments yet. Start the discussion!</p>
                    </div>
                `;
            }

            // refresh avatars
            if (typeof updateCommentAvatars === 'function') {
                updateCommentAvatars();
            }
        } else {
            alert(data.error || 'Error deleting comment');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error deleting comment. Please try again.');
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php include 'includes/footer.php'; ?>
