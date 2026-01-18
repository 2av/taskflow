<?php
require_once 'config/config.php';
requireLogin();
requireActiveSubscription();

$conn = getDBConnection();
$message = '';
$error = '';

// Get organization-specific statuses
$organization_id = isSuperAdmin() ? null : getOrganizationId();
$statuses = getStatuses($organization_id);
$status_names = array_column($statuses, 'name');

// Handle task creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_task'])) {
    $project_id = intval($_POST['project_id']);
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $type = $_POST['type'];
    $priority = $_POST['priority'];
    $assignee_id = !empty($_POST['assignee_id']) ? intval($_POST['assignee_id']) : null;
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
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
        $task_id = $project_code . '-' . $project_id . '-' . $task_num;
        
        // Get default status_id (first status from organization's statuses)
        $org_statuses = getStatuses($organization_id ?? null);
        $default_status = !empty($org_statuses) ? $org_statuses[0] : null;
        $status_id = $default_status ? $default_status['id'] : null;
        $status_name = $default_status ? $default_status['name'] : 'To Do';
        
        // Handle NULL values properly for assignee_id and due_date
        // For mysqli, we need to use a different approach when values can be NULL
        if ($assignee_id === null && $due_date === null) {
            // 9 parameters: task_id(s), project_id(i), title(s), description(s), type(s), priority(s), status_id(i), status_name(s), created_by(i)
            $stmt = $conn->prepare("INSERT INTO tasks (task_id, project_id, title, description, type, priority, status_id, status, assignee_id, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?)");
            $stmt->bind_param("sissssisi", $task_id, $project_id, $title, $description, $type, $priority, $status_id, $status_name, $created_by);
        } elseif ($assignee_id === null) {
            // 10 parameters: task_id(s), project_id(i), title(s), description(s), type(s), priority(s), status_id(i), status_name(s), due_date(s), created_by(i)
            $stmt = $conn->prepare("INSERT INTO tasks (task_id, project_id, title, description, type, priority, status_id, status, assignee_id, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)");
            $stmt->bind_param("sissssissi", $task_id, $project_id, $title, $description, $type, $priority, $status_id, $status_name, $due_date, $created_by);
        } elseif ($due_date === null) {
            // 10 parameters: task_id(s), project_id(i), title(s), description(s), type(s), priority(s), status_id(i), status_name(s), assignee_id(i), created_by(i)
            $stmt = $conn->prepare("INSERT INTO tasks (task_id, project_id, title, description, type, priority, status_id, status, assignee_id, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)");
            $stmt->bind_param("sissssissi", $task_id, $project_id, $title, $description, $type, $priority, $status_id, $status_name, $assignee_id, $created_by);
        } else {
            // 11 parameters: task_id(s), project_id(i), title(s), description(s), type(s), priority(s), status_id(i), status_name(s), assignee_id(i), due_date(s), created_by(i)
            $stmt = $conn->prepare("INSERT INTO tasks (task_id, project_id, title, description, type, priority, status_id, status, assignee_id, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sissssissii", $task_id, $project_id, $title, $description, $type, $priority, $status_id, $status_name, $assignee_id, $due_date, $created_by);
        }
        
        if ($stmt->execute()) {
            $task_insert_id = $conn->insert_id;
            
            // Log activity
            $action = "Task created";
            $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action) VALUES (?, ?, ?)");
            $stmt2->bind_param("iis", $task_insert_id, $created_by, $action);
            $stmt2->execute();
            
            $message = 'Task created successfully';
        } else {
            $error = 'Error creating task: ' . $conn->error;
            }
        }
    }
}

// Handle quick status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status_quick'])) {
    $task_id = intval($_POST['task_id']);
    $new_status_id = intval($_POST['status_id']); // Now using status_id
    $user_id = $_SESSION['user_id'];
    
    // Validate status_id exists and belongs to this organization
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
        $old_task->close();
        
        if ($old_task_data) {
            $old_status_id = $old_task_data['status_id'];
            $old_status_name = $old_task_data['status_name'] ?? 'Unknown';
            
            // Get new status name for logging
            $new_status_query = $conn->prepare("SELECT name FROM statuses WHERE id = ?");
            $new_status_query->bind_param("i", $new_status_id);
            $new_status_query->execute();
            $new_status_result = $new_status_query->get_result();
            $new_status_data = $new_status_result->fetch_assoc();
            $new_status_name = $new_status_data['name'] ?? 'Unknown';
            $new_status_query->close();
            
            // Update status_id (and status name for backward compatibility)
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
            } else {
                $error = 'Error updating status: ' . $conn->error;
                error_log("Status update SQL error: " . $conn->error);
            }
            $stmt->close();
        } else {
            $error = 'Task not found';
        }
    }
}

// Handle task update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_task'])) {
    $task_id = intval($_POST['task_id']);
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $type = $_POST['type'];
    $priority = $_POST['priority'];
    $status_id = intval($_POST['status_id']); // Now using status_id
    $assignee_id = !empty($_POST['assignee_id']) ? intval($_POST['assignee_id']) : null;
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    
    // Get status name for backward compatibility
    $status_name_query = $conn->prepare("SELECT name FROM statuses WHERE id = ?");
    $status_name_query->bind_param("i", $status_id);
    $status_name_query->execute();
    $status_name_result = $status_name_query->get_result();
    $status_name = $status_name_result->fetch_assoc()['name'] ?? 'Unknown';
    $status_name_query->close();
    
    // Get old values for logging
    $old_task = $conn->query("SELECT * FROM tasks WHERE id = $task_id")->fetch_assoc();
    
    $stmt = $conn->prepare("UPDATE tasks SET title=?, description=?, type=?, priority=?, status_id=?, status=?, assignee_id=?, due_date=? WHERE id=?");
    $stmt->bind_param("ssssissi", $title, $description, $type, $priority, $status_id, $status_name, $assignee_id, $due_date, $task_id);
    
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
        
        $message = 'Task updated successfully';
    } else {
        $error = 'Error updating task';
    }
}

// Handle task deletion
if (isset($_GET['delete'])) {
    $task_id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM tasks WHERE id = ?");
    $stmt->bind_param("i", $task_id);
    if ($stmt->execute()) {
        $message = 'Task deleted successfully';
    } else {
        $error = 'Error deleting task';
    }
}

// Handle project filter from dashboard card click or manual filter
// If project_id is in URL (from dashboard or manual filter), store it in session
if (isset($_GET['project_id']) && !empty($_GET['project_id'])) {
    $project_id_from_get = is_array($_GET['project_id']) ? array_map('intval', $_GET['project_id']) : [intval($_GET['project_id'])];
    $project_id_from_get = array_filter($project_id_from_get);
    if (!empty($project_id_from_get)) {
        // Store the first project_id (for single selection from dashboard)
        $_SESSION['selected_project_id'] = $project_id_from_get[0];
    } else {
        // If project_id is empty, clear session
        unset($_SESSION['selected_project_id']);
    }
} elseif (isset($_GET['clear_project']) || (isset($_GET['search']) && !isset($_GET['project_id']))) {
    // If user manually changes search without project_id, or explicitly clears, remove session
    // Note: Status filter clicks should preserve project selection
    if (!isset($_GET['status'])) {
        unset($_SESSION['selected_project_id']);
    }
}

// Get filter values (support multiple selections)
// Use URL project_id if present, otherwise use session project_id from dashboard, otherwise empty
if (isset($_GET['project_id']) && !empty($_GET['project_id'])) {
    $filter_project = is_array($_GET['project_id']) ? array_map('intval', $_GET['project_id']) : [intval($_GET['project_id'])];
    // Store in session for persistence
    $_SESSION['selected_project_id'] = $filter_project[0];
} elseif (isset($_SESSION['selected_project_id']) && !empty($_SESSION['selected_project_id'])) {
    // Use session project_id if no URL parameter (persist from dashboard click)
    $filter_project = [intval($_SESSION['selected_project_id'])];
} else {
    $filter_project = [];
}
$filter_project = array_filter($filter_project); // Remove zeros and empty values

// Get project name if project is selected (after filter is determined)
$selected_project_name = null;
if (!empty($filter_project)) {
    $project_id_for_name = $filter_project[0];
    $stmt = $conn->prepare("SELECT name FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id_for_name);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $selected_project_name = $row['name'];
    }
    $stmt->close();
}

$page_title = $selected_project_name ? 'Tasks (' . htmlspecialchars($selected_project_name) . ')' : 'Tasks';

$filter_status = isset($_GET['status']) ? (is_array($_GET['status']) ? $_GET['status'] : [$_GET['status']]) : [];
$filter_status = array_filter($filter_status); // Remove empty values

$filter_priority = isset($_GET['priority']) ? (is_array($_GET['priority']) ? $_GET['priority'] : [$_GET['priority']]) : [];
$filter_priority = array_filter($filter_priority); // Remove empty values

// Get assignee filter from URL
$filter_assignee = [];
if (isset($_GET['assignee_id']) && !empty($_GET['assignee_id'])) {
    // assignee_id is in URL and not empty - use it
    $filter_assignee = is_array($_GET['assignee_id']) ? array_map('intval', $_GET['assignee_id']) : [intval($_GET['assignee_id'])];
    // Keep 0 for unassigned filter, but filter out empty values
    $filter_assignee = array_filter($filter_assignee, function($val) { return $val !== '' && $val !== null; });
}
// Removed auto-select assignee filter - show all tasks by default

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$where_conditions = [];
$query_params = [];
$query_types = '';

// Initialize query_params array if empty (needed for pagination)
if (empty($query_params)) {
    $query_params = [];
}

if (!empty($filter_project)) {
    $placeholders = implode(',', array_fill(0, count($filter_project), '?'));
    $where_conditions[] = "t.project_id IN ($placeholders)";
    $query_params = array_merge($query_params, $filter_project);
    $query_types .= str_repeat('i', count($filter_project));
}

if (!empty($filter_status)) {
    // Filter by status_id - convert status names to IDs
    $status_ids = [];
    foreach ($filter_status as $status_name) {
        $status_info = getStatusByName($status_name, $organization_id);
        if ($status_info) {
            $status_ids[] = $status_info['id'];
        }
    }
    if (!empty($status_ids)) {
        $placeholders = implode(',', array_fill(0, count($status_ids), '?'));
        $where_conditions[] = "t.status_id IN ($placeholders)";
        $query_params = array_merge($query_params, $status_ids);
        $query_types .= str_repeat('i', count($status_ids));
    }
}

if (!empty($filter_priority)) {
    $placeholders = implode(',', array_fill(0, count($filter_priority), '?'));
    $where_conditions[] = "t.priority IN ($placeholders)";
    $query_params = array_merge($query_params, $filter_priority);
    $query_types .= str_repeat('s', count($filter_priority));
}

if (!empty($filter_assignee)) {
    // Handle unassigned (0) separately
    if (in_array(0, $filter_assignee)) {
        $filter_assignee_without_zero = array_filter($filter_assignee, function($val) { return $val != 0; });
        if (!empty($filter_assignee_without_zero)) {
            $placeholders = implode(',', array_fill(0, count($filter_assignee_without_zero), '?'));
            $where_conditions[] = "(t.assignee_id IN ($placeholders) OR t.assignee_id IS NULL)";
            $query_params = array_merge($query_params, $filter_assignee_without_zero);
            $query_types .= str_repeat('i', count($filter_assignee_without_zero));
        } else {
            $where_conditions[] = "t.assignee_id IS NULL";
        }
    } else {
    $placeholders = implode(',', array_fill(0, count($filter_assignee), '?'));
    $where_conditions[] = "t.assignee_id IN ($placeholders)";
    $query_params = array_merge($query_params, $filter_assignee);
    $query_types .= str_repeat('i', count($filter_assignee));
    }
}

if (!empty($search)) {
    // Search in both title and task_id
    $where_conditions[] = "(t.title LIKE ? OR t.task_id LIKE ?)";
    $query_params[] = "%$search%";
    $query_params[] = "%$search%";
    $query_types .= 'ss';
}

// Role-based filtering
if (isSuperAdmin()) {
    // Super Admin sees all tasks - no additional filter needed
} else if (isOrgAdmin()) {
    // Organization Admin sees all tasks in their organization
    $org_id = getOrganizationId();
    $where_conditions[] = "p.organization_id = ?";
    $query_params[] = $org_id;
    $query_types .= 'i';
} else if (!isProjectManager()) {
    // Team members only see their assigned tasks or tasks in their projects
    $user_id = $_SESSION['user_id'];
    // Don't add this condition if assignee filter is already set
    if (empty($filter_assignee)) {
        $where_conditions[] = "(t.assignee_id = $user_id OR t.project_id IN (SELECT project_id FROM project_users WHERE user_id = $user_id))";
    } else {
        // If assignee filter is set, still need to check project access
        $where_conditions[] = "t.project_id IN (SELECT project_id FROM project_users WHERE user_id = $user_id)";
    }
} else {
    // Project Manager sees tasks in their assigned projects
    $user_id = $_SESSION['user_id'];
    $org_id = getOrganizationId();
    if ($org_id) {
        $where_conditions[] = "p.organization_id = ?";
        $query_params[] = $org_id;
        $query_types .= 'i';
    }
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Pagination
$items_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

// Get total count for pagination
$count_query = "
    SELECT COUNT(*) as total
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN users u ON t.assignee_id = u.id
    LEFT JOIN users u2 ON t.created_by = u2.id
    $where_clause
";

if (!empty($query_params)) {
    $count_stmt = $conn->prepare($count_query);
    if ($count_stmt) {
        $count_stmt->bind_param($query_types, ...$query_params);
        $count_stmt->execute();
        $total_items = $count_stmt->get_result()->fetch_assoc()['total'];
    } else {
        $total_items = 0;
    }
} else {
    $total_items = $conn->query($count_query)->fetch_assoc()['total'];
}

$total_pages = ceil($total_items / $items_per_page);

// Get tasks with pagination
$query = "
    SELECT t.*, t.task_id, t.type, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name,
           COALESCE(s.name, t.status, 'To Do') as status,
           t.status_id
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN statuses s ON t.status_id = s.id
    LEFT JOIN users u ON t.assignee_id = u.id
    LEFT JOIN users u2 ON t.created_by = u2.id
    $where_clause
    ORDER BY t.created_at DESC
    LIMIT ? OFFSET ?
";

// Always add pagination parameters
if (empty($query_params)) {
    $query_params = [];
    $query_types = '';
}
$query_params[] = $items_per_page;
$query_params[] = $offset;
$query_types .= 'ii';

    // Execute query (always has LIMIT and OFFSET parameters)
    $stmt = $conn->prepare($query);
    if ($stmt) {
        if (!empty($query_params) && !empty($query_types)) {
            $stmt->bind_param($query_types, ...$query_params);
        }
        $stmt->execute();
        $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $tasks = [];
        error_log("Tasks query prepare failed: " . $conn->error);
        error_log("Query: " . $query);
        error_log("Query types: " . $query_types);
        error_log("Query params count: " . count($query_params));
    }

// Get task statistics based on current filters (excluding status and assignee filters)
// Status counts should show all statuses, not filtered by status
$stats_where_conditions = [];
$stats_params = [];
$stats_types = '';

// Include project filter
if (!empty($filter_project)) {
    $placeholders = implode(',', array_fill(0, count($filter_project), '?'));
    $stats_where_conditions[] = "t.project_id IN ($placeholders)";
    $stats_params = array_merge($stats_params, $filter_project);
    $stats_types .= str_repeat('i', count($filter_project));
}

// Include search filter
if (!empty($search)) {
    $stats_where_conditions[] = "(t.title LIKE ? OR t.task_id LIKE ?)";
    $stats_params[] = "%$search%";
    $stats_params[] = "%$search%";
    $stats_types .= 'ss';
}

// Role-based filtering for stats
if (isSuperAdmin()) {
    // Super Admin sees all tasks - no additional filter needed
} else if (isOrgAdmin()) {
    // Organization Admin sees all tasks in their organization
    $org_id = getOrganizationId();
    $stats_where_conditions[] = "p.organization_id = ?";
    $stats_params[] = $org_id;
    $stats_types .= 'i';
} else if (!isProjectManager()) {
    // Team members only see their assigned tasks or tasks in their projects
    $user_id = $_SESSION['user_id'];
    $stats_where_conditions[] = "(t.assignee_id = $user_id OR t.project_id IN (SELECT project_id FROM project_users WHERE user_id = $user_id))";
} else {
    // Project Manager sees tasks in their assigned projects
    $user_id = $_SESSION['user_id'];
    $org_id = getOrganizationId();
    if ($org_id) {
        $stats_where_conditions[] = "p.organization_id = ?";
        $stats_params[] = $org_id;
        $stats_types .= 'i';
    }
}

$stats_where_clause = !empty($stats_where_conditions) ? "WHERE " . implode(" AND ", $stats_where_conditions) : "";

// Build dynamic status counts based on organization's statuses (using status_id)
$status_count_cases = [];
foreach ($statuses as $status) {
    $status_id = $status['id'];
    $status_key = strtolower(str_replace(' ', '_', $status['name']));
    $status_count_cases[] = "SUM(CASE WHEN t.status_id = $status_id THEN 1 ELSE 0 END) as {$status_key}_count";
}

$stats_query = "
    SELECT 
        COUNT(*) as total_tasks,
        " . implode(",\n        ", $status_count_cases) . "
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN users u ON t.assignee_id = u.id
    LEFT JOIN users u2 ON t.created_by = u2.id
    $stats_where_clause
";

    if (!empty($stats_params)) {
        $stats_stmt = $conn->prepare($stats_query);
        if ($stats_stmt) {
            $stats_stmt->bind_param($stats_types, ...$stats_params);
            $stats_stmt->execute();
            $task_stats = $stats_stmt->get_result()->fetch_assoc();
        } else {
            $task_stats = ['total_tasks' => 0, 'todo_count' => 0, 'inprogress_count' => 0, 'done_count' => 0];
        }
    } else {
        $task_stats = $conn->query($stats_query)->fetch_assoc();
    }

// Get projects for filter (filtered by organization)
if (isSuperAdmin()) {
    $projects = $conn->query("SELECT * FROM projects ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$users = $conn->query("SELECT * FROM users WHERE status = 'active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
} else {
    $org_id = getOrganizationId();
    $stmt = $conn->prepare("SELECT * FROM projects WHERE organization_id = ? ORDER BY name");
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $projects = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT * FROM users WHERE organization_id = ? AND status = 'active' ORDER BY full_name");
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get assignee statistics (users who have tasks assigned in current project/org)
// Exclude status and assignee filters so all assignees are shown
$assignee_stats = [];

// Build the query with proper filtering
$assignee_where = [];
$assignee_params = [];
$assignee_types = '';

// Build where clause for assignee stats (excluding status and assignee filters)
if (!empty($filter_project)) {
    $placeholders = implode(',', array_fill(0, count($filter_project), '?'));
    $assignee_where[] = "t.project_id IN ($placeholders)";
    $assignee_params = array_merge($assignee_params, $filter_project);
    $assignee_types .= str_repeat('i', count($filter_project));
}

// Don't include status filter - show all assignees regardless of status
// Don't include assignee filter - show all assignees

if (!empty($search)) {
    $assignee_where[] = "(t.title LIKE ? OR t.task_id LIKE ?)";
    $assignee_params[] = "%$search%";
    $assignee_params[] = "%$search%";
    $assignee_types .= 'ss';
}

// Role-based filtering for assignee stats
if (isSuperAdmin()) {
    // Super Admin sees all assignees - no additional filter needed
} elseif (isOrgAdmin()) {
    $org_id = getOrganizationId();
    if ($org_id) {
        $assignee_where[] = "p.organization_id = ?";
        $assignee_params[] = $org_id;
        $assignee_types .= 'i';
    }
} elseif (isProjectManager()) {
    // Project Manager sees assignees in their organization
    $org_id = getOrganizationId();
    if ($org_id) {
        $assignee_where[] = "p.organization_id = ?";
        $assignee_params[] = $org_id;
        $assignee_types .= 'i';
    }
} else {
    // Team members only see their assigned tasks or tasks in their projects
    $user_id = $_SESSION['user_id'];
    // Use inline SQL for team members since it doesn't need parameters
    $assignee_where[] = "(t.assignee_id = $user_id OR t.project_id IN (SELECT project_id FROM project_users WHERE user_id = $user_id))";
}

// Build the complete query
$assignee_stats_query = "
    SELECT 
        u.id,
        u.full_name,
        COUNT(t.id) as task_count
    FROM users u
    INNER JOIN tasks t ON u.id = t.assignee_id
    LEFT JOIN projects p ON t.project_id = p.id
";

if (!empty($assignee_where)) {
    $assignee_stats_query .= " WHERE " . implode(" AND ", $assignee_where);
}

$assignee_stats_query .= " GROUP BY u.id, u.full_name HAVING task_count > 0 ORDER BY u.full_name";

// Debug: Log the query (remove in production)
// error_log("Assignee Stats Query: " . $assignee_stats_query);
// error_log("Assignee Params: " . print_r($assignee_params, true));
// error_log("Assignee Types: " . $assignee_types);

// Execute the query
try {
    if (!empty($assignee_params) && !empty($assignee_types)) {
        // Use prepared statement when we have parameters
        $assignee_stmt = $conn->prepare($assignee_stats_query);
        if ($assignee_stmt) {
            $assignee_stmt->bind_param($assignee_types, ...$assignee_params);
            $assignee_stmt->execute();
            $assignee_result = $assignee_stmt->get_result();
            $assignee_stats = $assignee_result->fetch_all(MYSQLI_ASSOC);
            $assignee_stmt->close();
        } else {
            // Log error for debugging
            error_log("Assignee stats query prepare failed: " . $conn->error);
            $assignee_stats = [];
        }
    } else {
        // Use direct query when there are no parameters (or only inline SQL conditions)
        $result = $conn->query($assignee_stats_query);
        if ($result) {
            $assignee_stats = $result->fetch_all(MYSQLI_ASSOC);
        } else {
            // Log error for debugging
            error_log("Assignee stats query failed: " . $conn->error);
            error_log("Query was: " . $assignee_stats_query);
            $assignee_stats = [];
        }
    }
} catch (Exception $e) {
    error_log("Assignee stats query exception: " . $e->getMessage());
    $assignee_stats = [];
}

// Add current user to assignee stats if not already present
$current_user_id = $_SESSION['user_id'];
$current_user_in_stats = false;
foreach ($assignee_stats as $stat) {
    if ($stat['id'] == $current_user_id) {
        $current_user_in_stats = true;
        break;
    }
}

if (!$current_user_in_stats) {
    // Get current user info
    $user_stmt = $conn->prepare("SELECT id, full_name FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $current_user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_row = $user_result->fetch_assoc()) {
        // Add with task_count 0 (they'll still appear in filter)
        $user_row['task_count'] = 0;
        $assignee_stats[] = $user_row;
        // Sort by full_name to maintain alphabetical order
        usort($assignee_stats, function($a, $b) {
            return strcmp($a['full_name'], $b['full_name']);
        });
    }
    $user_stmt->close();
}

// Get unassigned tasks count (before closing connection)
$unassigned_where = [];
$unassigned_params = [];
$unassigned_types = '';

if (!empty($filter_project)) {
    $placeholders = implode(',', array_fill(0, count($filter_project), '?'));
    $unassigned_where[] = "t.project_id IN ($placeholders)";
    $unassigned_params = array_merge($unassigned_params, $filter_project);
    $unassigned_types .= str_repeat('i', count($filter_project));
}

if (!empty($search)) {
    $unassigned_where[] = "(t.title LIKE ? OR t.task_id LIKE ?)";
    $unassigned_params[] = "%$search%";
    $unassigned_params[] = "%$search%";
    $unassigned_types .= 'ss';
}

// Role-based filtering for unassigned
if (isSuperAdmin()) {
    // Super Admin sees all tasks
} elseif (isOrgAdmin()) {
    $org_id = getOrganizationId();
    if ($org_id) {
        $unassigned_where[] = "p.organization_id = ?";
        $unassigned_params[] = $org_id;
        $unassigned_types .= 'i';
    }
} elseif (isProjectManager()) {
    $org_id = getOrganizationId();
    if ($org_id) {
        $unassigned_where[] = "p.organization_id = ?";
        $unassigned_params[] = $org_id;
        $unassigned_types .= 'i';
    }
} else {
    // Team members only see unassigned tasks in their projects
    $user_id = $_SESSION['user_id'];
    $unassigned_where[] = "t.project_id IN (SELECT project_id FROM project_users WHERE user_id = $user_id)";
}

$unassigned_where[] = "t.assignee_id IS NULL";
$unassigned_where_clause = !empty($unassigned_where) ? "WHERE " . implode(" AND ", $unassigned_where) : "";

$unassigned_query = "
    SELECT COUNT(*) as count
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    $unassigned_where_clause
";

$unassigned_count = 0;
if (!empty($unassigned_params) && !empty($unassigned_types)) {
    $unassigned_stmt = $conn->prepare($unassigned_query);
    if ($unassigned_stmt) {
        $unassigned_stmt->bind_param($unassigned_types, ...$unassigned_params);
        $unassigned_stmt->execute();
        $unassigned_result = $unassigned_stmt->get_result();
        $unassigned_count = $unassigned_result->fetch_assoc()['count'];
        $unassigned_stmt->close();
    }
} else {
    $unassigned_result = $conn->query($unassigned_query);
    $unassigned_count = $unassigned_result ? $unassigned_result->fetch_assoc()['count'] : 0;
}

// Debug: Check if there are tasks with assignees (temporary - remove after debugging)
// Uncomment the lines below to enable debug output
/*
$test_query = "SELECT COUNT(*) as total FROM tasks WHERE assignee_id IS NOT NULL";
$test_result = $conn->query($test_query);
if ($test_result) {
    $test_data = $test_result->fetch_assoc();
    error_log("DEBUG: Total tasks with assignees: " . $test_data['total']);
    error_log("DEBUG: Assignee stats query: " . $assignee_stats_query);
    error_log("DEBUG: Assignee stats count: " . count($assignee_stats));
}
*/

// Helper function to generate initials from full name
// If space exists: first char of first word + first char of last word
// If no space: first 2 characters
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

// Helper function to generate pagination HTML
function generatePagination($current_page, $total_pages, $get_params = []) {
    if ($total_pages <= 1) {
        return '';
    }
    
    $html = '';
    
    // Previous button
    if ($current_page > 1) {
        $prev_params = $get_params;
        $prev_params['page'] = $current_page - 1;
        $prev_url = '?' . http_build_query($prev_params);
        $html .= '<a href="' . htmlspecialchars($prev_url) . '" class="pagination-btn" data-page="' . ($current_page - 1) . '"><i class="fas fa-chevron-left"></i></a>';
    } else {
        $html .= '<span class="pagination-btn disabled"><i class="fas fa-chevron-left"></i></span>';
    }
    
    // Page numbers
    $start_page = max(1, $current_page - 1);
    $end_page = min($total_pages, $current_page + 1);
    
    if ($start_page > 1) {
        $first_params = $get_params;
        $first_params['page'] = 1;
        $first_url = '?' . http_build_query($first_params);
        $html .= '<a href="' . htmlspecialchars($first_url) . '" class="pagination-btn" data-page="1">1</a>';
        if ($start_page > 2) {
            $html .= '<span style="padding: 0 8px; color: var(--text-muted);">...</span>';
        }
    }
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $current_page) {
            $html .= '<span class="pagination-btn active">' . $i . '</span>';
        } else {
            $page_params = $get_params;
            $page_params['page'] = $i;
            $page_url = '?' . http_build_query($page_params);
            $html .= '<a href="' . htmlspecialchars($page_url) . '" class="pagination-btn" data-page="' . $i . '">' . $i . '</a>';
        }
    }
    
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
            $html .= '<span style="padding: 0 8px; color: var(--text-muted);">...</span>';
        }
        $last_params = $get_params;
        $last_params['page'] = $total_pages;
        $last_url = '?' . http_build_query($last_params);
        $html .= '<a href="' . htmlspecialchars($last_url) . '" class="pagination-btn" data-page="' . $total_pages . '">' . $total_pages . '</a>';
    }
    
    // Next button
    if ($current_page < $total_pages) {
        $next_params = $get_params;
        $next_params['page'] = $current_page + 1;
        $next_url = '?' . http_build_query($next_params);
        $html .= '<a href="' . htmlspecialchars($next_url) . '" class="pagination-btn" data-page="' . ($current_page + 1) . '"><i class="fas fa-chevron-right"></i></a>';
    } else {
        $html .= '<span class="pagination-btn disabled"><i class="fas fa-chevron-right"></i></span>';
    }
    
    return $html;
}

// Helper function to generate custom multiselect HTML
function renderCustomMultiselect($name, $options, $selected_values = [], $searchable = false) {
    static $counter = 0;
    $counter++;
    $unique_id = $name . '_' . $counter;
    
    // Get selected labels for display
    $selected_labels = [];
    foreach ($selected_values as $val) {
        if (isset($options[$val])) {
            $selected_labels[] = $options[$val];
        }
    }
    $display_text = empty($selected_labels) ? 'Select...' : (count($selected_labels) == 1 ? $selected_labels[0] : count($selected_labels) . ' selected');
    
    $html = '<div class="custom-multiselect">';
    $html .= '<div class="custom-multiselect-display">';
    if (empty($selected_labels)) {
        $html .= '<span class="placeholder">Select...</span>';
        $html .= '<span class="selected-count" style="display: none;"></span>';
    } else {
        $html .= '<span class="placeholder" style="display: none;">Select...</span>';
        $html .= '<span class="selected-count">' . htmlspecialchars($display_text) . '</span>';
    }
    $html .= '<span class="arrow">▼</span>';
    $html .= '</div>';
    $html .= '<div class="custom-multiselect-dropdown">';
    
    if ($searchable && count($options) > 5) {
        $html .= '<div class="custom-multiselect-search">';
        $html .= '<input type="text" placeholder="Search...">';
        $html .= '</div>';
    }
    
    foreach ($options as $value => $label) {
        $checked = in_array($value, $selected_values) ? 'checked' : '';
        $option_id = $unique_id . '_' . md5($value);
        $html .= '<div class="custom-multiselect-option">';
        $html .= '<input type="checkbox" name="' . htmlspecialchars($name) . '[]" value="' . htmlspecialchars($value) . '" id="' . $option_id . '" ' . $checked . '>';
        $html .= '<label for="' . $option_id . '">' . htmlspecialchars($label) . '</label>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

// Task editing is now handled in task_form.php
// Redirect edit requests to task_form.php
if (isset($_GET['edit'])) {
    header('Location: task_form?edit=' . intval($_GET['edit']));
    exit();
}
$edit_task = null; // Keep for backward compatibility with modal code (commented out)

// Check if this is an AJAX request for search suggestions
if (isset($_GET['ajax_suggestions']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    $suggestions_query = isset($_GET['search_suggestions']) ? trim($_GET['search_suggestions']) : '';
    
    if (empty($suggestions_query)) {
        echo json_encode(['success' => false, 'suggestions' => []]);
        $conn->close();
        exit();
    }
    
    // Build suggestion query with same filters as main query
    $suggestions_where = [];
    $suggestions_params = [];
    $suggestions_types = '';
    
    // Apply same filters as main query
    if (!empty($filter_project)) {
        $placeholders = implode(',', array_fill(0, count($filter_project), '?'));
        $suggestions_where[] = "t.project_id IN ($placeholders)";
        $suggestions_params = array_merge($suggestions_params, $filter_project);
        $suggestions_types .= str_repeat('i', count($filter_project));
    }
    
    // Role-based filtering for suggestions
    if (isSuperAdmin()) {
        // Super Admin sees all tasks
    } else if (isOrgAdmin()) {
        $org_id = getOrganizationId();
        $suggestions_where[] = "p.organization_id = ?";
        $suggestions_params[] = $org_id;
        $suggestions_types .= 'i';
    } else if (!isProjectManager()) {
        $user_id = $_SESSION['user_id'];
        $suggestions_where[] = "(t.assignee_id = $user_id OR t.project_id IN (SELECT project_id FROM project_users WHERE user_id = $user_id))";
    } else {
        $user_id = $_SESSION['user_id'];
        $org_id = getOrganizationId();
        if ($org_id) {
            $suggestions_where[] = "p.organization_id = ?";
            $suggestions_params[] = $org_id;
            $suggestions_types .= 'i';
        }
    }
    
    // Add search condition
    $suggestions_where[] = "(t.title LIKE ? OR t.task_id LIKE ?)";
    $suggestions_params[] = "%$suggestions_query%";
    $suggestions_params[] = "%$suggestions_query%";
    $suggestions_types .= 'ss';
    
    $suggestions_where_clause = !empty($suggestions_where) ? "WHERE " . implode(" AND ", $suggestions_where) : "";
    
    $suggestions_sql = "
        SELECT DISTINCT t.task_id, t.title, t.id
        FROM tasks t
        LEFT JOIN projects p ON t.project_id = p.id
        $suggestions_where_clause
        ORDER BY 
            CASE 
                WHEN t.task_id LIKE ? THEN 1
                WHEN t.task_id LIKE ? THEN 2
                WHEN t.title LIKE ? THEN 3
                ELSE 4
            END,
            t.created_at DESC
        LIMIT 10
    ";
    
    // Add ordering parameters
    $suggestions_params[] = "$suggestions_query%";
    $suggestions_params[] = "%$suggestions_query%";
    $suggestions_params[] = "$suggestions_query%";
    $suggestions_types .= 'sss';
    
    $suggestions_stmt = $conn->prepare($suggestions_sql);
    if ($suggestions_stmt) {
        $suggestions_stmt->bind_param($suggestions_types, ...$suggestions_params);
        $suggestions_stmt->execute();
        $suggestions_result = $suggestions_stmt->get_result();
        $suggestions = [];
        while ($row = $suggestions_result->fetch_assoc()) {
            $suggestions[] = [
                'task_id' => $row['task_id'],
                'title' => $row['title'],
                'id' => $row['id']
            ];
        }
        $suggestions_stmt->close();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'suggestions' => $suggestions]);
        $conn->close();
        exit();
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'suggestions' => []]);
        $conn->close();
        exit();
    }
}

// Check if this is an AJAX request
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    // Return JSON response for AJAX
    header('Content-Type: application/json');
    
    $tasks_html = '';
    if (empty($tasks)) {
        $tasks_html = '<tr><td colspan="7" style="text-align: center; color: #999;">No tasks found</td></tr>';
    } else {
        foreach ($tasks as $task) {
            // Get icon for task type
            $type_icon = 'fa-tasks';
            $type_color = '#14b8a6';
            if ($task['type'] == 'Bug') {
                $type_icon = 'fa-bug';
                $type_color = '#e74c3c';
            } elseif ($task['type'] == 'Improvement') {
                $type_icon = 'fa-lightbulb';
                $type_color = '#f39c12';
            }
            
            // Priority and Status styles for AJAX
            $priority_lower = strtolower($task['priority']);
            $priority_styles = [
                'high' => ['bg' => 'bg-red-100', 'text' => 'text-red-800', 'icon' => 'fa-exclamation-circle', 'icon_color' => 'text-red-600'],
                'medium' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800', 'icon' => 'fa-exclamation-triangle', 'icon_color' => 'text-yellow-600'],
                'low' => ['bg' => 'bg-green-100', 'text' => 'text-green-800', 'icon' => 'fa-check-circle', 'icon_color' => 'text-green-600']
            ];
            $priority_style = $priority_styles[$priority_lower] ?? $priority_styles['low'];
            
            $status_lower = strtolower(str_replace(' ', '-', $task['status']));
            $status_styles = [
                'done' => ['bg' => 'bg-green-100', 'text' => 'text-green-800', 'icon' => 'fa-check-circle', 'icon_color' => 'text-green-600'],
                'in-progress' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-800', 'icon' => 'fa-spinner', 'icon_color' => 'text-blue-600'],
                'to-do' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-800', 'icon' => 'fa-clock', 'icon_color' => 'text-gray-600']
            ];
            $status_style = $status_styles[$status_lower] ?? $status_styles['to-do'];
            
            // Assignee badge
            $assignee_name = $task['assignee_name'] ?? null;
            $assignee_styles = [
                'assigned' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-800', 'icon' => 'fa-user', 'icon_color' => 'text-blue-600'],
                'unassigned' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-800', 'icon' => 'fa-user-slash', 'icon_color' => 'text-gray-600']
            ];
            $assignee_style = $assignee_name ? $assignee_styles['assigned'] : $assignee_styles['unassigned'];
            
            // Due date badge
            $due_date = $task['due_date'];
            $due_date_styles = [
                'overdue' => ['bg' => 'bg-red-100', 'text' => 'text-red-800', 'icon' => 'fa-exclamation-triangle', 'icon_color' => 'text-red-600'],
                'upcoming' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800', 'icon' => 'fa-calendar-alt', 'icon_color' => 'text-yellow-600'],
                'normal' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-800', 'icon' => 'fa-calendar', 'icon_color' => 'text-gray-600'],
                'nodate' => ['bg' => 'bg-gray-50', 'text' => 'text-gray-500', 'icon' => 'fa-calendar-times', 'icon_color' => 'text-gray-400']
            ];
            
            if (!$due_date) {
                $due_date_style = $due_date_styles['nodate'];
                $due_date_display = 'No due date';
            } elseif ($task['status'] == 'Closed') {
                $due_date_style = $due_date_styles['normal'];
                $due_date_display = formatDate($due_date);
            } elseif (strtotime($due_date) < time() && $task['status'] != 'Closed') {
                $due_date_style = $due_date_styles['overdue'];
                $due_date_display = formatDate($due_date);
            } elseif (strtotime($due_date) <= strtotime('+3 days')) {
                $due_date_style = $due_date_styles['upcoming'];
                $due_date_display = formatDate($due_date);
            } else {
                $due_date_style = $due_date_styles['normal'];
                $due_date_display = formatDate($due_date);
            }
            
            $tasks_html .= '<tr class="hover:bg-blue-50 transition-colors cursor-pointer" style="transition: background-color 0.2s ease;">';
            // Task ID / Type column
            $tasks_html .= '<td class="px-6 py-4 whitespace-nowrap" style="cursor: pointer;" onclick="window.location.href=\'task_view?id=' . $task['id'] . '\'">';
            $tasks_html .= '<div style="display: flex; gap: 8px; align-items: center;">';
            $tasks_html .= '<span style="font-size: 12px; font-weight: 600; color: var(--text-primary);">' . htmlspecialchars($task['task_id'] ?? '—') . '</span>';
            $tasks_html .= '<i class="fas ' . $type_icon . '" style="font-size: 12px; color: ' . $type_color . '; display: inline-block; padding: 4px 0px;" title="' . htmlspecialchars($task['type'] ?? 'Task') . '"></i>';
            $tasks_html .= '</div>';
            $tasks_html .= '</td>';
            // Task title column
            $tasks_html .= '<td class="px-6 py-4" style="cursor: pointer;" onclick="window.location.href=\'task_view?id=' . $task['id'] . '\'">';
            $tasks_html .= '<div class="text-sm text-gray-900 font-medium">' . htmlspecialchars($task['title']) . '</div>';
            $tasks_html .= '</td>';
            // Project column
            $tasks_html .= '<td class="px-6 py-4 whitespace-nowrap text-center">';
            $tasks_html .= htmlspecialchars($task['project_name'] ?? '—');
            $tasks_html .= '</td>';
            // Status column
            $tasks_html .= '<td class="px-6 py-4 whitespace-nowrap text-center">';
            $tasks_html .= '<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full ' . $status_style['bg'] . ' ' . $status_style['text'] . '">';
            $tasks_html .= '<i class="fas ' . $status_style['icon'] . ' ' . $status_style['icon_color'] . ' text-xs"></i>';
            $tasks_html .= htmlspecialchars(normalizeStatusForDisplay($task['status'] ?? 'To Do'));
            $tasks_html .= '</span>';
            $tasks_html .= '</td>';
            // Priority column
            $tasks_html .= '<td class="px-6 py-4 whitespace-nowrap text-center">';
            $tasks_html .= '<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full ' . $priority_style['bg'] . ' ' . $priority_style['text'] . '">';
            $tasks_html .= '<i class="fas ' . $priority_style['icon'] . ' ' . $priority_style['icon_color'] . ' text-xs"></i>';
            $tasks_html .= htmlspecialchars($task['priority']);
            $tasks_html .= '</span>';
            $tasks_html .= '</td>';
            $tasks_html .= '<td class="px-6 py-4 whitespace-nowrap text-center">';
            if ($assignee_name) {
                $assignee_initials = getInitials($assignee_name);
                $tasks_html .= '<span class="inline-flex items-center justify-center w-8 h-8 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-700 border border-indigo-300" ';
                $tasks_html .= 'title="' . htmlspecialchars($assignee_name) . '" ';
                $tasks_html .= 'style="min-width: 32px; min-height: 32px;">';
                $tasks_html .= htmlspecialchars($assignee_initials);
                $tasks_html .= '</span>';
            } else {
                $tasks_html .= '<span class="inline-flex items-center justify-center w-8 h-8 rounded-full text-xs font-semibold bg-gray-100 text-gray-600 border border-gray-300" ';
                $tasks_html .= 'title="Unassigned" ';
                $tasks_html .= 'style="min-width: 32px; min-height: 32px;">';
                $tasks_html .= '<i class="fas fa-user-slash" style="font-size: 10px;"></i>';
                $tasks_html .= '</span>';
            }
            $tasks_html .= '</td>';
            $tasks_html .= '<td class="px-6 py-4 whitespace-nowrap text-center">';
            $tasks_html .= '<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full ' . $due_date_style['bg'] . ' ' . $due_date_style['text'] . '">';
            $tasks_html .= '<i class="fas ' . $due_date_style['icon'] . ' ' . $due_date_style['icon_color'] . ' text-xs"></i>';
            $tasks_html .= htmlspecialchars($due_date_display);
            $tasks_html .= '</span>';
            $tasks_html .= '</td>';
            $tasks_html .= '</tr>';
        }
    }
    
    // Generate pagination HTML
    $pagination_html = generatePagination($current_page, $total_pages, $_GET);
    
    // Generate filter badges HTML for AJAX
    $status_counts = [
        'All' => intval($task_stats['total_tasks']),
        'To Do' => intval($task_stats['todo_count']),
        'In Progress' => intval($task_stats['inprogress_count']),
        'Done' => intval($task_stats['done_count'])
    ];
    
    $status_colors = [
        'All' => '#6c757d',
        'To Do' => '#ffc107',
        'In Progress' => '#17a2b8',
        'Closed' => '#6c757d'
    ];
    
    $status_icons = [
        'All' => 'fa-tasks',
        'To Do' => 'fa-clock',
        'In Progress' => 'fa-spinner',
        'Done' => 'fa-check-circle'
    ];
    
    $current_filter_status = isset($_GET['status']) ? (is_array($_GET['status']) ? $_GET['status'] : [$_GET['status']]) : [];
    $current_filter_status = array_filter($current_filter_status);
    
    $stats_html = '<div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e2e8f0;">';
      $stats_html .= '<div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">';
    
    foreach ($status_counts as $status => $count) {
        $is_active = (empty($current_filter_status) && $status == 'All') || (!empty($current_filter_status) && in_array($status, $current_filter_status));
        $filter_url = 'tasks?';
        $url_params = $_GET;
        unset($url_params['status']);
        unset($url_params['page']);
        if ($status != 'All') {
            $url_params['status'] = $status;
        }
        $filter_url .= http_build_query($url_params);
        
        $stats_html .= '<a href="' . htmlspecialchars($filter_url) . '" ';
        $stats_html .= 'style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; background: ' . ($is_active ? $status_colors[$status] : '#fff') . '; color: ' . ($is_active ? '#fff' : '#333') . '; border: 2px solid ' . $status_colors[$status] . '; border-radius: 20px; text-decoration: none; font-weight: 600; font-size: 13px; transition: all 0.3s; cursor: pointer;" ';
        $stats_html .= 'onmouseover="this.style.background=\'' . $status_colors[$status] . '\'; this.style.color=\'#fff\';" ';
        $stats_html .= 'onmouseout="this.style.background=\'' . ($is_active ? $status_colors[$status] : '#fff') . '\'; this.style.color=\'' . ($is_active ? '#fff' : '#333') . '\';" ';
        $stats_html .= 'title="Filter by ' . htmlspecialchars($status) . '">';
        $stats_html .= '<i class="fas ' . $status_icons[$status] . '"></i>';
        $stats_html .= '<span>' . htmlspecialchars($status) . '</span>';
        $stats_html .= '<span style="background: ' . ($is_active ? 'rgba(255,255,255,0.3)' : $status_colors[$status]) . '; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700;">' . $count . '</span>';
        $stats_html .= '</a>';
    }
    
    // Add assignee badges and unassigned for AJAX response
    if (!empty($assignee_stats) || $unassigned_count > 0) {
        $stats_html .= '<div style="width: 100%; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0;"></div>';
        $current_filter_assignee = isset($_GET['assignee_id']) ? (is_array($_GET['assignee_id']) ? array_map('intval', $_GET['assignee_id']) : [intval($_GET['assignee_id'])]) : [];
        $current_filter_assignee = array_filter($current_filter_assignee, function($val) { return $val !== '' && $val !== null; });
        
        // Auto-select current user if no assignee filter is set (for AJAX - only for Admins and Org Admins)
        $ajax_current_user_id = $_SESSION['user_id'];
        if (empty($current_filter_assignee) && !isset($_GET['assignee_id']) && (isAdmin() || isOrgAdmin())) {
            $current_filter_assignee = [$ajax_current_user_id];
        }
        
        // All Assignees filter badge for AJAX
        $ajax_has_assignee_filter = !empty($current_filter_assignee);
        $ajax_all_assignees_url = 'tasks?';
        $ajax_all_assignees_params = $_GET;
        unset($ajax_all_assignees_params['assignee_id']);
        unset($ajax_all_assignees_params['page']);
        $ajax_all_assignees_url .= http_build_query($ajax_all_assignees_params);
        
        $stats_html .= '<a href="' . htmlspecialchars($ajax_all_assignees_url) . '" ';
        $stats_html .= 'style="display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; min-width: 40px; min-height: 40px; border-radius: 50%; background: ' . (!$ajax_has_assignee_filter ? '#667eea' : '#fff') . '; color: ' . (!$ajax_has_assignee_filter ? '#fff' : '#333') . '; border: 2px solid #667eea; text-decoration: none; font-weight: 600; font-size: 13px; transition: all 0.3s; cursor: pointer;" ';
        $stats_html .= 'onmouseover="this.style.background=\'#667eea\'; this.style.color=\'#fff\';" ';
        $stats_html .= 'onmouseout="this.style.background=\'' . (!$ajax_has_assignee_filter ? '#667eea' : '#fff') . '\'; this.style.color=\'' . (!$ajax_has_assignee_filter ? '#fff' : '#333') . '\';" ';
        $stats_html .= 'title="Reset Assignee Filter (Clear All Selections)">';
        $stats_html .= '<i class="fas fa-redo"></i>';
        $stats_html .= '</a>';
        
        // Unassigned filter badge
        if ($unassigned_count > 0) {
            $is_unassigned_active = !empty($current_filter_assignee) && in_array(0, $current_filter_assignee);
            
            // Toggle logic: if active, remove it; if not active, add it
            $new_filter_assignee = $current_filter_assignee;
            if ($is_unassigned_active) {
                // Remove from filter
                $new_filter_assignee = array_values(array_filter($new_filter_assignee, function($val) {
                    return $val != 0;
                }));
            } else {
                // Add to filter
                $new_filter_assignee[] = 0;
                $new_filter_assignee = array_values(array_unique($new_filter_assignee));
            }
            
            $filter_url = 'tasks?';
            $url_params = $_GET;
            unset($url_params['assignee_id']);
            unset($url_params['page']);
            if (!empty($new_filter_assignee)) {
                $url_params['assignee_id'] = $new_filter_assignee;
            }
            $filter_url .= http_build_query($url_params);
            
            $stats_html .= '<a href="' . htmlspecialchars($filter_url) . '" ';
            $stats_html .= 'style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; background: ' . ($is_unassigned_active ? '#667eea' : '#fff') . '; color: ' . ($is_unassigned_active ? '#fff' : '#333') . '; border: 2px solid #667eea; border-radius: 20px; text-decoration: none; font-weight: 600; font-size: 13px; transition: all 0.3s; cursor: pointer;" ';
            $stats_html .= 'onmouseover="this.style.background=\'#667eea\'; this.style.color=\'#fff\';" ';
            $stats_html .= 'onmouseout="this.style.background=\'' . ($is_unassigned_active ? '#667eea' : '#fff') . '\'; this.style.color=\'' . ($is_unassigned_active ? '#fff' : '#333') . '\';" ';
            $stats_html .= 'title="Filter by Unassigned">';
            $stats_html .= '<i class="fas fa-user-slash"></i>';
            $stats_html .= '<span>Unassigned</span>';
            $stats_html .= '</a>';
        }
        
        foreach ($assignee_stats as $assignee) {
            $initials = getInitials($assignee['full_name']);
            $is_active = !empty($current_filter_assignee) && in_array($assignee['id'], $current_filter_assignee);
            
            // Toggle logic: if active, remove it; if not active, add it
            $new_filter_assignee = $current_filter_assignee;
            if ($is_active) {
                // Remove from filter
                $new_filter_assignee = array_values(array_filter($new_filter_assignee, function($val) use ($assignee) {
                    return $val != $assignee['id'];
                }));
            } else {
                // Add to filter
                $new_filter_assignee[] = $assignee['id'];
                $new_filter_assignee = array_values(array_unique($new_filter_assignee));
            }
            
            $filter_url = 'tasks?';
            $url_params = $_GET;
            unset($url_params['assignee_id']);
            unset($url_params['page']);
            if (!empty($new_filter_assignee)) {
                $url_params['assignee_id'] = $new_filter_assignee;
            }
            $filter_url .= http_build_query($url_params);
            
            $stats_html .= '<a href="' . htmlspecialchars($filter_url) . '" ';
            $stats_html .= 'style="display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; min-width: 40px; min-height: 40px; border-radius: 50%; background: ' . ($is_active ? '#667eea' : '#fff') . '; color: ' . ($is_active ? '#fff' : '#333') . '; border: 2px solid #667eea; text-decoration: none; font-weight: 600; font-size: 13px; transition: all 0.3s; cursor: pointer;' . ($is_active ? ' box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);' : '') . '" ';
            $stats_html .= 'onmouseover="this.style.background=\'#667eea\'; this.style.color=\'#fff\';" ';
            $stats_html .= 'onmouseout="this.style.background=\'' . ($is_active ? '#667eea' : '#fff') . '\'; this.style.color=\'' . ($is_active ? '#fff' : '#333') . '\';" ';
            $stats_html .= 'title="' . htmlspecialchars($assignee['full_name']) . '">';
            $stats_html .= '<span>' . htmlspecialchars($initials) . '</span>';
            $stats_html .= '</a>';
        }
    }
    
    $stats_html .= '</div></div>';
    
    echo json_encode([
        'success' => true, 
        'html' => $tasks_html, 
        'count' => count($tasks),
        'pagination' => $pagination_html,
        'stats' => $stats_html,
        'total_items' => $total_items,
        'current_page' => $current_page,
        'total_pages' => $total_pages
    ]);
    $conn->close();
    exit();
}

$conn->close();

include 'includes/header.php';
?>

<style>
.tasks-page-container {
    padding: 24px;
    background: var(--page-bg);
    min-height: calc(100vh - 60px);
}

.tasks-overview-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
}

.tasks-overview-title {
    font-size: 20px;
    font-weight: 500;
    color: var(--text-primary);
    margin: 0;
}

.tasks-summary-stats {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}

.stat-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    text-decoration: none;
    transition: all 0.2s ease;
}

.stat-badge:hover {
    border-color: var(--blue);
    background: var(--blue-light);
}

.stat-badge.active {
    background: var(--blue);
    color: white;
    border-color: var(--blue);
}

.stat-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.stat-dot.active { background: var(--chart-green); }
.stat-dot.pending { background: var(--chart-yellow); }
.stat-dot.failed { background: var(--chart-red); }
.stat-dot.closed { background: var(--chart-gray); }

.add-task-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: var(--blue);
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s ease;
}

.add-task-btn:hover {
    background: var(--blue-dark);
}

.tasks-filters-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.search-input-wrapper {
    flex: 1;
    min-width: 250px;
    position: relative;
}

.search-input {
    width: 100%;
    padding: 10px 40px 10px 40px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 14px;
    background: var(--card-bg);
    color: var(--text-primary);
    transition: all 0.2s ease;
}

.search-input:focus {
    outline: none;
    border-color: var(--blue);
    box-shadow: 0 0 0 3px var(--blue-light);
}

.search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 14px;
}

.filter-dropdown {
    padding: 10px 36px 10px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 14px;
    background: var(--card-bg);
    color: var(--text-primary);
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236B7280' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    transition: all 0.2s ease;
    min-width: 120px;
}

.filter-dropdown:hover {
    border-color: var(--blue);
}

.filter-dropdown:focus {
    outline: none;
    border-color: var(--blue);
    box-shadow: 0 0 0 3px var(--blue-light);
}

.tasks-table-container {
    background: var(--card-bg);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    box-shadow: 0 1px 3px var(--shadow);
    overflow: hidden;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.tasks-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

.tasks-table thead {
    background: var(--text-primary);
}

.tasks-table th {
    padding: 12px 16px;
    text-align: left;
    font-size: 12px;
    font-weight: 500;
    color: white;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}


.tasks-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background-color 0.2s ease;
}

.tasks-table tbody tr:hover {
    background: var(--blue-light);
}

.tasks-table tbody tr:last-child {
    border-bottom: none;
}

.tasks-table td {
    padding: 16px;
    font-size: 14px;
    color: var(--text-primary);
}

.task-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: var(--blue);
}

.task-name-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.task-name-text {
    font-weight: 400;
    color: var(--text-primary);
}

.task-project {
    color: var(--text-secondary);
    font-size: 13px;
}

.table-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
}

.table-status-badge .badge-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
}

.table-status-active {
    background: var(--chart-green);
    color: white;
}

.table-status-active .badge-dot {
    background: white;
}

.table-status-pending {
    background: var(--chart-yellow);
    color: var(--text-primary);
}

.table-status-pending .badge-dot {
    background: var(--text-primary);
}

.table-status-failed {
    background: var(--chart-red);
    color: white;
}

.table-status-failed .badge-dot {
    background: white;
}

.table-status-closed {
    background: var(--closed-bg);
    color: var(--closed-text);
}

.table-status-closed .badge-dot {
    background: var(--closed-text);
}

.table-priority-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
}

.priority-high {
    background: var(--chart-yellow);
    color: var(--text-primary);
}

.priority-medium {
    background: var(--chart-red);
    color: white;
}

.priority-low {
    background: var(--chart-gray);
    color: var(--text-primary);
}

.assignee-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    background: var(--blue-light);
    color: var(--blue-dark);
}

.due-date-text {
    font-size: 13px;
    color: var(--text-primary);
}

.pagination-container {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid var(--border-color);
}

.pagination-info {
    font-size: 13px;
    color: var(--text-secondary);
}

.pagination-controls {
    display: flex;
    align-items: center;
    gap: 8px;
}

.pagination-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--card-bg);
    color: var(--text-primary);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
    cursor: pointer;
}

.pagination-btn:hover:not(.disabled):not(.active) {
    border-color: var(--blue);
    color: var(--blue);
}

.pagination-btn.active {
    background: var(--blue);
    color: white;
    border-color: var(--blue);
}

.pagination-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Mobile Responsive Styles */
@media (max-width: 768px) {
    .tasks-page-container {
        padding: 16px;
    }
    
    .tasks-table-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        width: 100%;
    }
    
    .tasks-table {
        min-width: 700px;
    }
    
    .tasks-table th,
    .tasks-table td {
        padding: 12px 8px;
        font-size: 13px;
    }
    
    .tasks-filters-bar {
        flex-direction: column;
        gap: 8px;
    }
    
    .search-input-wrapper {
        min-width: 100%;
    }
    
    .tasks-overview-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    
    .tasks-summary-stats {
        flex-wrap: wrap;
        gap: 8px;
    }
    
    .stat-badge {
        font-size: 12px;
        padding: 6px 12px;
    }
}
</style>

<div class="tasks-page-container">
    <!-- Tasks Overview Header -->
    <div class="tasks-overview-header">
        <h2 class="tasks-overview-title">Tasks Overview</h2>
        <?php if (isAdmin() || isProjectManager()): ?>
            <button type="button" class="add-task-btn" onclick="openAddTaskModal()">
                <i class="fas fa-plus"></i>
                <span>Add Task</span>
            </button>
        <?php endif; ?>
    </div>

<?php if ($message): ?>
        <div class="mb-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg flex items-center gap-2">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
<?php endif; ?>

<?php if ($error): ?>
        <div class="mb-4 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg flex items-center gap-2">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
<?php endif; ?>

    <!-- Task Summary Statistics -->
    <div class="tasks-summary-stats">
        <?php 
        // Build filter URLs
        function buildFilterUrl($params) {
            $url_params = [];
            if (!empty($params['project_id'])) {
                $url_params['project_id'] = $params['project_id'];
            }
            if (!empty($params['status'])) {
                $url_params['status'] = $params['status'];
            }
            if (!empty($params['assignee_id'])) {
                $url_params['assignee_id'] = $params['assignee_id'];
            }
            if (!empty($params['search'])) {
                $url_params['search'] = $params['search'];
            }
            return 'tasks?' . http_build_query($url_params);
        }
        
        $base_params = [
            'project_id' => !empty($filter_project) ? $filter_project[0] : null,
            'assignee_id' => $filter_assignee,
            'search' => $search
        ];
        
        // Display status badges dynamically
        foreach ($statuses as $status):
            $status_name = $status['name'];
            $status_key = strtolower(str_replace(' ', '_', $status_name));
            $status_count = intval($task_stats[$status_key . '_count'] ?? 0);
            $is_active = !empty($filter_status) && in_array($status_name, $filter_status);
            
            // Determine dot class based on status name
            $dot_class = 'pending';
            if (stripos($status_name, 'progress') !== false || stripos($status_name, 'active') !== false) {
                $dot_class = 'active';
            } elseif (stripos($status_name, 'done') !== false || stripos($status_name, 'closed') !== false || stripos($status_name, 'complete') !== false) {
                $dot_class = 'closed';
            }
        ?>
        <a href="<?php echo htmlspecialchars(buildFilterUrl(array_merge($base_params, ['status' => $status_name]))); ?>" 
           class="stat-badge <?php echo $is_active ? 'active' : ''; ?>"
           style="<?php echo $is_active ? 'background: ' . htmlspecialchars($status['color']) . '; color: white; border-color: ' . htmlspecialchars($status['color']) . ';' : ''; ?>">
            <span class="stat-dot <?php echo $dot_class; ?>" style="background: <?php echo htmlspecialchars($status['color']); ?>;"></span>
            <span><?php echo htmlspecialchars($status_name); ?></span>
            <span><?php echo $status_count; ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Search and Filter Bar -->
    <form method="GET" action="tasks" class="tasks-filters-bar" id="tasksFilterForm">
        <div class="search-input-wrapper">
            <input type="text" name="search" id="searchInput" 
                   placeholder="Search tasks..." 
                   value="<?php echo htmlspecialchars($search); ?>"
                   class="search-input"
                   autocomplete="off">
            <i class="fas fa-search search-icon"></i>
            <?php if (!empty($search)): ?>
                <button type="button" id="clearSearchBtn" 
                        onclick="document.getElementById('searchInput').value=''; document.getElementById('tasksFilterForm').submit();"
                        style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 4px;">
                    <i class="fas fa-times"></i>
                </button>
            <?php endif; ?>
            <!-- Search Suggestions Dropdown -->
            <div id="searchSuggestions" class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto hidden" style="top: 100%; left: 0;">
                <div id="suggestionsList" class="py-1"></div>
            </div>
        </div>
        
        <?php 
        // Prepare options for custom multiselects (exclude '0' from actual values)
        $project_options = [];
        foreach ($projects as $proj) {
            $project_options[$proj['id']] = $proj['name'];
        }
        
        // Build status options from database
        $status_options = [];
        foreach ($statuses as $status) {
            $status_options[$status['name']] = $status['name'];
        }
        
        $priority_options = [
            'High' => 'High',
            'Medium' => 'Medium',
            'Low' => 'Low'
        ];
        ?>
        
        <div style="position: relative; min-width: 140px;">
            <?php echo renderCustomMultiselect('project_id', $project_options, $filter_project, true); ?>
        </div>
        <div style="position: relative; min-width: 140px;">
            <?php echo renderCustomMultiselect('status', $status_options, $filter_status, false); ?>
        </div>
        <div style="position: relative; min-width: 140px;">
            <?php echo renderCustomMultiselect('priority', $priority_options, $filter_priority, false); ?>
        </div>
        
        <!-- Preserve assignee filter if exists -->
        <?php if (!empty($filter_assignee)): ?>
            <?php foreach ($filter_assignee as $assignee_id): ?>
                <input type="hidden" name="assignee_id[]" value="<?php echo $assignee_id; ?>">
            <?php endforeach; ?>
        <?php endif; ?>
    </form>
    

    <!-- Tasks Table -->
    <div class="tasks-table-container">
        <table class="tasks-table">
            <thead>
                <tr>
                    <th>Task ID / Type</th>
                    <th>Task</th>
                    <th>Project</th>
                    <th style="text-align: center;">Status</th>
                    <th style="text-align: center;">Priority</th>
                    <th style="text-align: center;">Due Date</th>
                    <th style="text-align: center;">Assignee</th>
                </tr>
            </thead>
            <tbody id="tasksTableBody">
                <?php 
                // Debug: Check if tasks array exists and has data
                if (!isset($tasks) || empty($tasks)): 
                ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 48px 16px; color: var(--text-muted);">
                            <i class="fas fa-tasks" style="font-size: 48px; opacity: 0.3; margin-bottom: 12px; display: block;"></i>
                            <p style="margin: 0; font-size: 14px;">No tasks found</p>
                            <?php if (!empty($filter_project)): ?>
                                <p style="margin: 8px 0 0 0; font-size: 12px; color: var(--text-secondary);">
                                    Filtered by project<?php echo count($filter_project) > 1 ? 's' : ''; ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tasks as $task): ?>
                        <?php 
                        // Get status info from database using status_id
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
                        $status_lower = strtolower(str_replace(' ', '-', $task_status));
                        $status_class = 'table-status-pending';
                        if (stripos($task_status, 'progress') !== false || stripos($task_status, 'active') !== false) {
                            $status_class = 'table-status-active';
                        } elseif (stripos($task_status, 'done') !== false || stripos($task_status, 'closed') !== false || stripos($task_status, 'complete') !== false) {
                            $status_class = 'table-status-closed';
                        }
                        
                        // Priority badge class
                        $priority_lower = strtolower($task['priority'] ?? '');
                        $priority_class = 'priority-low';
                        if ($priority_lower == 'high') {
                            $priority_class = 'priority-high';
                        } elseif ($priority_lower == 'medium') {
                            $priority_class = 'priority-medium';
                        }
                        
                        // Due date formatting
                        $due_date_display = '—';
                        if (!empty($task['due_date'])) {
                            $due_date_display = date('d-m-Y', strtotime($task['due_date']));
                        }
                        
                        // Assignee initials
                        $assignee_initials = '';
                        if (!empty($task['assignee_name'])) {
                            $assignee_initials = getInitials($task['assignee_name']);
                        }
                        ?>
                        <tr data-task-id="<?php echo $task['id']; ?>" style="cursor: pointer; transition: background-color 0.2s;" class="task-row">
                            <td>
                                <?php
                                // Get icon for task type
                                $task_type = $task['type'] ?? 'Task';
                                $type_icon = 'fa-tasks';
                                $type_color = '#14b8a6';
                                if ($task_type == 'Bug') {
                                    $type_icon = 'fa-bug';
                                    $type_color = '#e74c3c';
                                } elseif ($task_type == 'Improvement') {
                                    $type_icon = 'fa-lightbulb';
                                    $type_color = '#f39c12';
                                }
                                ?>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <span style="font-size: 12px; font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($task['task_id'] ?? '—'); ?></span>
                                    <i class="fas <?php echo $type_icon; ?>" style="font-size: 12px; color: <?php echo $type_color; ?>; display: inline-block; padding: 4px 0px;" title="<?php echo htmlspecialchars($task_type); ?>"></i>
                                </div>
                            </td>
                            <td>
                                <div class="task-name-cell">
                                    <span class="task-name-text"><?php echo htmlspecialchars($task['title']); ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="task-project"><?php echo htmlspecialchars($task['project_name'] ?? '—'); ?></span>
                            </td>
                            <td style="text-align: center;" onclick="event.stopPropagation();">
                                <form method="POST" action="" style="display: inline-block; margin: 0;" id="statusForm_<?php echo $task['id']; ?>" onsubmit="return updateTaskStatusQuick(<?php echo $task['id']; ?>, this);">
                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                    <input type="hidden" name="update_status_quick" value="1">
                                    <select name="status_id" 
                                            onchange="this.form.submit();" 
                                            onclick="event.stopPropagation();"
                                            style="padding: 4px 24px 4px 8px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer; background: <?php echo htmlspecialchars($status_color); ?>; color: white; appearance: none; background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 12 12\'%3E%3Cpath fill=\'%23ffffff\' d=\'M6 9L1 4h10z\'/%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 8px center; min-width: 110px;">
                                        <?php foreach ($statuses as $status_option): ?>
                                            <option value="<?php echo $status_option['id']; ?>" <?php echo ($task_status_id == $status_option['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($status_option['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td style="text-align: center;">
                                <span class="table-priority-badge <?php echo $priority_class; ?>">
                                    <?php echo htmlspecialchars($task['priority'] ?? 'Low'); ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <span class="due-date-text"><?php echo $due_date_display; ?></span>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($assignee_initials): ?>
                                    <div class="assignee-avatar" title="<?php echo htmlspecialchars($task['assignee_name']); ?>">
                                        <?php echo htmlspecialchars($assignee_initials); ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-size: 12px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="pagination-container">
        <div class="pagination-info">
            Showing <?php echo min(($current_page - 1) * $items_per_page + 1, $total_items); ?> to <?php echo min($current_page * $items_per_page, $total_items); ?> of <?php echo $total_items; ?> tasks
        </div>
        <div class="pagination-controls">
            <?php 
            $pagination_html = generatePagination($current_page, $total_pages, $_GET);
            if ($pagination_html): 
                echo $pagination_html;
            endif; 
            ?>
        </div>
    </div>
</div>

<!-- Add Task Modal -->
<div id="addTaskModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2 style="margin: 0; font-size: 20px; font-weight: 600; color: var(--text-primary);">Add New Task</h2>
            <button class="close" onclick="closeAddTaskModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        <form method="POST" action="tasks" id="addTaskForm">
            <input type="hidden" name="create_task" value="1">
            <div class="modal-body" style="padding: 24px;">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Name <span style="color: var(--chart-red);">*</span></label>
                    <input type="text" name="title" required 
                           style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px;"
                           placeholder="Enter task name">
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Description</label>
                    <textarea name="description" rows="4" 
                              style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; resize: vertical;"
                              placeholder="Enter task description"></textarea>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Type</label>
                    <select name="type" required 
                            style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; background: white;">
                        <option value="Task">Task</option>
                        <option value="Bug">Bug</option>
                        <option value="Improvement">Improvement</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Project</label>
                    <select name="project_id" required 
                            style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; background: white;">
                        <option value="">Select Project</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?php echo $proj['id']; ?>" <?php echo (!empty($filter_project) && in_array($proj['id'], $filter_project)) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($proj['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <input type="hidden" name="status_id" value="<?php echo !empty($statuses) ? $statuses[0]['id'] : ''; ?>">
                <input type="hidden" name="priority" value="Low">
            </div>
            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" onclick="closeAddTaskModal()" 
                        style="padding: 10px 20px; border: 1px solid var(--border-color); background: white; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; color: var(--text-primary);">
                    Cancel
                </button>
                <button type="submit" 
                        style="padding: 10px 20px; border: none; background: var(--blue); color: white; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">
                    Create Task
                </button>
            </div>
        </form>
    </div>
</div>

    <script>
// Add Task Modal Functions
function openAddTaskModal() {
    const modal = document.getElementById('addTaskModal');
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
    } else {
        console.error('Add Task Modal not found');
    }
}

function closeAddTaskModal() {
    const modal = document.getElementById('addTaskModal');
    const form = document.getElementById('addTaskForm');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = ''; // Restore scrolling
    }
    if (form) {
        form.reset();
    }
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('addTaskModal');
    if (modal && event.target == modal) {
        closeAddTaskModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modal = document.getElementById('addTaskModal');
        if (modal && modal.style.display === 'block') {
            closeAddTaskModal();
        }
    }
});

// Real-time search with suggestions
let searchTimeout;
let currentSuggestions = [];
let selectedSuggestionIndex = -1;

const searchInput = document.getElementById('searchInput');
const searchSuggestions = document.getElementById('searchSuggestions');
const suggestionsList = document.getElementById('suggestionsList');
const clearSearchBtn = document.getElementById('clearSearchBtn');

// Get current URL parameters (excluding search)
function getCurrentParams() {
    const urlParams = new URLSearchParams(window.location.search);
    const params = {};
    
    // Preserve existing filters
    if (urlParams.has('project_id')) {
        params.project_id = urlParams.get('project_id');
    }
    if (urlParams.has('status')) {
        params.status = urlParams.getAll('status');
    }
    if (urlParams.has('assignee_id')) {
        params.assignee_id = urlParams.getAll('assignee_id');
    }
    
    return params;
}

// Build URL with search
function buildSearchUrl(searchTerm) {
    const params = getCurrentParams();
    if (searchTerm && searchTerm.trim()) {
        params.search = searchTerm.trim();
    }
    const queryString = new URLSearchParams();
    Object.keys(params).forEach(key => {
        if (Array.isArray(params[key])) {
            params[key].forEach(val => queryString.append(key, val));
        } else {
            queryString.append(key, params[key]);
        }
    });
    return 'tasks' + (queryString.toString() ? '?' + queryString.toString() : '');
}

// Fetch search suggestions
function fetchSuggestions(query) {
    if (!query || query.length < 1) {
        hideSuggestions();
        return;
    }
    
    const params = getCurrentParams();
    params.search_suggestions = query;
    
    fetch('tasks?' + new URLSearchParams(params).toString() + '&ajax_suggestions=1', {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.suggestions) {
            currentSuggestions = data.suggestions;
            displaySuggestions(data.suggestions);
        } else {
            hideSuggestions();
        }
    })
    .catch(error => {
        console.error('Search error:', error);
        hideSuggestions();
    });
}

// Display suggestions
function displaySuggestions(suggestions) {
    if (!suggestions || suggestions.length === 0) {
        hideSuggestions();
        return;
    }
    
    suggestionsList.innerHTML = '';
    selectedSuggestionIndex = -1;
    
    suggestions.forEach((suggestion, index) => {
        const item = document.createElement('div');
        item.className = 'px-4 py-2 hover:bg-teal-50 cursor-pointer flex items-center justify-between transition-colors';
        item.dataset.index = index;
        
        const leftDiv = document.createElement('div');
        leftDiv.className = 'flex items-center gap-3';
        
        const icon = document.createElement('i');
        icon.className = 'fas fa-tasks text-teal-600';
        
        const textDiv = document.createElement('div');
        const taskId = document.createElement('div');
        taskId.className = 'font-semibold text-gray-900 text-sm';
        taskId.textContent = suggestion.task_id;
        
        const title = document.createElement('div');
        title.className = 'text-xs text-gray-500 truncate';
        title.textContent = suggestion.title;
        title.style.maxWidth = '300px';
        
        textDiv.appendChild(taskId);
        textDiv.appendChild(title);
        
        leftDiv.appendChild(icon);
        leftDiv.appendChild(textDiv);
        
        item.appendChild(leftDiv);
        
        item.addEventListener('click', () => {
            selectSuggestion(suggestion);
        });
        
        item.addEventListener('mouseenter', () => {
            selectedSuggestionIndex = index;
            updateSuggestionHighlight();
        });
        
        suggestionsList.appendChild(item);
    });
    
    searchSuggestions.classList.remove('hidden');
}

// Update suggestion highlight
function updateSuggestionHighlight() {
    const items = suggestionsList.querySelectorAll('div[data-index]');
    items.forEach((item, index) => {
        if (index === selectedSuggestionIndex) {
            item.classList.add('bg-teal-50');
        } else {
            item.classList.remove('bg-teal-50');
        }
    });
}

// Hide suggestions
function hideSuggestions() {
    searchSuggestions.classList.add('hidden');
    currentSuggestions = [];
    selectedSuggestionIndex = -1;
}

// Select a suggestion
function selectSuggestion(suggestion) {
    searchInput.value = suggestion.task_id;
    hideSuggestions();
    // Navigate to search results
    window.location.href = buildSearchUrl(suggestion.task_id);
}

// Real-time search input handler
if (searchInput) {
    searchInput.addEventListener('input', function(e) {
        const query = e.target.value.trim();
        
        // Clear timeout if exists
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }
        
        // Show suggestions after 300ms delay
        if (query.length >= 1) {
            searchTimeout = setTimeout(() => {
                fetchSuggestions(query);
            }, 300);
        } else {
            hideSuggestions();
            // If search is cleared and we have filters, reload without search
            if (!query && (window.location.search.includes('project_id') || window.location.search.includes('status') || window.location.search.includes('assignee_id'))) {
                // Don't auto-reload, just clear suggestions
                hideSuggestions();
            } else if (!query) {
                // No filters and no search, go to base tasks page
                window.location.href = 'tasks';
            }
        }
    });

    // Handle Enter key
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const query = e.target.value.trim();
            if (query) {
                hideSuggestions();
                window.location.href = buildSearchUrl(query);
            }
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (currentSuggestions.length > 0) {
                selectedSuggestionIndex = Math.min(selectedSuggestionIndex + 1, currentSuggestions.length - 1);
                updateSuggestionHighlight();
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (currentSuggestions.length > 0) {
                selectedSuggestionIndex = Math.max(selectedSuggestionIndex - 1, -1);
                updateSuggestionHighlight();
            }
        } else if (e.key === 'Escape') {
            hideSuggestions();
            searchInput.blur();
        }
        
        // Handle Enter key on selected suggestion
        if (e.key === 'Enter' && selectedSuggestionIndex >= 0 && currentSuggestions[selectedSuggestionIndex]) {
            e.preventDefault();
            selectSuggestion(currentSuggestions[selectedSuggestionIndex]);
        }
    });
}

// Clear search button
if (clearSearchBtn) {
    clearSearchBtn.addEventListener('click', function() {
        searchInput.value = '';
        hideSuggestions();
        window.location.href = buildSearchUrl('');
    });
}

// Hide suggestions when clicking outside
document.addEventListener('click', function(e) {
    if (searchInput && searchSuggestions && !searchInput.contains(e.target) && !searchSuggestions.contains(e.target)) {
        hideSuggestions();
    }
});

// Handle row click - navigate to task view unless clicking on interactive elements
document.addEventListener('DOMContentLoaded', function() {
    // Add click handlers to all task rows
    const taskRows = document.querySelectorAll('.task-row');
    taskRows.forEach(function(row) {
        row.addEventListener('click', function(event) {
            // Don't navigate if clicking on checkbox, select, button, or form elements
            const target = event.target;
            const tagName = target.tagName;
            
            // Check if clicking directly on interactive elements
            if (tagName === 'INPUT' || 
                tagName === 'SELECT' || 
                tagName === 'BUTTON' ||
                tagName === 'FORM' ||
                tagName === 'A') {
                event.stopPropagation();
                return false;
            }
            
            // Check if clicking inside a form, select, or button
            const form = target.closest('form');
            const select = target.closest('select');
            const button = target.closest('button');
            
            if (form || select || button) {
                event.stopPropagation();
                return false;
            }
            
            // Get task ID from data attribute
            const taskId = row.getAttribute('data-task-id');
            if (taskId) {
                // Navigate to task view
                window.location.href = 'task_view?id=' + taskId;
            }
        });
    });
});

// Quick status update function
function updateTaskStatusQuick(taskId, form) {
    if (!form) {
        form = document.getElementById('statusForm_' + taskId);
    }
    
    if (form) {
        // Create a FormData object
        const formData = new FormData(form);
        
        // Submit via fetch to avoid page reload
        fetch('tasks', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (response.ok) {
                // Reload the page to show updated status
                window.location.reload();
            } else {
                alert('Error updating status');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error updating status');
        });
    }
    return false; // Prevent default form submission
}
    </script>

<?php include 'includes/footer.php'; ?>
