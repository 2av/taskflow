<?php
require_once 'config/config.php';
requireLogin();
requireActiveSubscription();

$conn = getDBConnection();
$message = '';
$error = '';

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
        // Generate task ID
        $project_code = $conn->query("SELECT name FROM projects WHERE id = $project_id")->fetch_assoc()['name'];
        $project_code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $project_code), 0, 3));
        $task_num = $conn->query("SELECT COUNT(*) as count FROM tasks WHERE project_id = $project_id")->fetch_assoc()['count'] + 1;
        $task_id = $project_code . '-' . $task_num;
        
        $stmt = $conn->prepare("INSERT INTO tasks (task_id, project_id, title, description, type, priority, assignee_id, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sissssssi", $task_id, $project_id, $title, $description, $type, $priority, $assignee_id, $due_date, $created_by);
        
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

// Handle task update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_task'])) {
    $task_id = intval($_POST['task_id']);
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $type = $_POST['type'];
    $priority = $_POST['priority'];
    $status = $_POST['status'];
    $assignee_id = !empty($_POST['assignee_id']) ? intval($_POST['assignee_id']) : null;
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    
    // Get old values for logging
    $old_task = $conn->query("SELECT * FROM tasks WHERE id = $task_id")->fetch_assoc();
    
    $stmt = $conn->prepare("UPDATE tasks SET title=?, description=?, type=?, priority=?, status=?, assignee_id=?, due_date=? WHERE id=?");
    $stmt->bind_param("sssssssi", $title, $description, $type, $priority, $status, $assignee_id, $due_date, $task_id);
    
    if ($stmt->execute()) {
        // Log changes
        $user_id = $_SESSION['user_id'];
        
        if ($old_task['status'] != $status) {
            $action = "Status changed";
            $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
            $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_task['status'], $status);
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
// Use URL project_id if present, otherwise use session project_id, otherwise empty
if (isset($_GET['project_id']) && !empty($_GET['project_id'])) {
    $filter_project = is_array($_GET['project_id']) ? array_map('intval', $_GET['project_id']) : [intval($_GET['project_id'])];
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

$page_title = $selected_project_name ? 'Task Management (' . htmlspecialchars($selected_project_name) . ')' : 'Task Management';

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
} elseif (!isset($_GET['assignee_id'])) {
    // assignee_id is NOT in URL at all - this could be:
    // 1. First page load (no filters) - auto-select current user for Admins/Org Admins
    // 2. Reset button clicked (other filters present) - don't auto-select
    $current_user_id = $_SESSION['user_id'];
    // Only auto-select on first load when no other filters are present
    $has_other_filters = isset($_GET['project_id']) || isset($_GET['status']) || isset($_GET['search']);
    if (!$has_other_filters && (isAdmin() || isOrgAdmin())) {
        $filter_assignee = [$current_user_id];
    }
    // If has other filters, $filter_assignee stays empty (reset was clicked)
}

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
    $placeholders = implode(',', array_fill(0, count($filter_status), '?'));
    $where_conditions[] = "t.status IN ($placeholders)";
    $query_params = array_merge($query_params, $filter_status);
    $query_types .= str_repeat('s', count($filter_status));
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
    $where_conditions[] = "(t.assignee_id = $user_id OR t.project_id IN (SELECT project_id FROM project_users WHERE user_id = $user_id))";
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
    SELECT t.*, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN users u ON t.assignee_id = u.id
    LEFT JOIN users u2 ON t.created_by = u2.id
    $where_clause
    ORDER BY t.created_at DESC
    LIMIT ? OFFSET ?
";

// Always add pagination parameters
if (empty($query_params)) {
    $query_params = [];
}
$query_params[] = $items_per_page;
$query_params[] = $offset;
$query_types .= 'ii';

$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param($query_types, ...$query_params);
    $stmt->execute();
    $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $tasks = [];
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

$stats_query = "
    SELECT 
        COUNT(*) as total_tasks,
        SUM(CASE WHEN t.status = 'To Do' THEN 1 ELSE 0 END) as todo_count,
        SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as inprogress_count,
        SUM(CASE WHEN t.status = 'Done' THEN 1 ELSE 0 END) as done_count
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
    
    $html = '<div class="pagination">';
    
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
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);
    
    if ($start_page > 1) {
        $first_params = $get_params;
        $first_params['page'] = 1;
        $first_url = '?' . http_build_query($first_params);
        $html .= '<a href="' . htmlspecialchars($first_url) . '" class="pagination-btn" data-page="1">1</a>';
        if ($start_page > 2) {
            $html .= '<span class="pagination-ellipsis">...</span>';
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
            $html .= '<span class="pagination-ellipsis">...</span>';
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
    
    $html .= '</div>';
    return $html;
}

// Helper function to generate custom multiselect HTML
function renderCustomMultiselect($name, $options, $selected_values = [], $searchable = false) {
    static $counter = 0;
    $counter++;
    $unique_id = $name . '_' . $counter;
    
    $html = '<div class="custom-multiselect">';
    $html .= '<div class="custom-multiselect-display">';
    $html .= '<span class="placeholder">Select...</span>';
    $html .= '<span class="selected-count" style="display: none;">0 selected</span>';
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

// Get task for editing
$edit_task = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $result = $conn->query("SELECT * FROM tasks WHERE id = $edit_id");
    $edit_task = $result->fetch_assoc();
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
            $due_date = formatDate($task['due_date']);
            $due_date_html = $due_date;
            if ($task['due_date'] && strtotime($task['due_date']) < time() && $task['status'] != 'Done') {
                $due_date_html = '<span style="color: #e74c3c;">' . $due_date . '</span>';
            }
            
            $edit_link = '';
            if (isAdmin() || isProjectManager() || $task['assignee_id'] == $_SESSION['user_id']) {
                $edit_link = '<a href="?edit=' . $task['id'] . '" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a> ';
            }
            
            $delete_link = '';
            if (isAdmin() || isProjectManager()) {
                $delete_link = '<a href="?delete=' . $task['id'] . '" class="btn btn-sm btn-danger btn-delete" title="Delete"><i class="fas fa-trash"></i></a>';
            }
            
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
            
            $tasks_html .= '<tr style="cursor: pointer;" onclick="window.location.href=\'task_view.php?id=' . $task['id'] . '\'">';
            $tasks_html .= '<td><strong>' . htmlspecialchars($task['task_id']) . '</strong> <span style="margin-left: 8px; color: ' . $type_color . ';" title="' . htmlspecialchars($task['type']) . '"><i class="fas ' . $type_icon . '"></i></span></td>';
            $tasks_html .= '<td>' . htmlspecialchars($task['title']) . '</td>';
            $tasks_html .= '<td><span class="badge priority-' . strtolower($task['priority']) . '">' . htmlspecialchars($task['priority']) . '</span></td>';
            $tasks_html .= '<td><span class="badge status-' . strtolower(str_replace(' ', '-', $task['status'])) . '">' . htmlspecialchars($task['status']) . '</span></td>';
            $tasks_html .= '<td>' . htmlspecialchars($task['assignee_name'] ?? 'Unassigned') . '</td>';
            $tasks_html .= '<td>' . $due_date_html . '</td>';
            $tasks_html .= '<td onclick="event.stopPropagation();"><a href="task_view.php?id=' . $task['id'] . '" class="btn btn-sm btn-primary" title="View" onclick="event.stopPropagation();"><i class="fas fa-eye"></i></a> ' . $edit_link . $delete_link . '</td>';
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
        'Done' => '#28a745'
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
        $filter_url = 'tasks.php?';
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
        $ajax_all_assignees_url = 'tasks.php?';
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
            
            $filter_url = 'tasks.php?';
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
            
            $filter_url = 'tasks.php?';
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

<div style="width: 100%; padding: 15px;">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="dashboard.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center shadow-sm hover:shadow-md" title="Back to Dashboard" style="min-width: 40px; min-height: 40px;">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1 class="text-3xl font-semibold text-gray-900">
                Task Management<?php echo $selected_project_name ? ' <span class="text-gray-500 font-normal">(' . htmlspecialchars($selected_project_name) . ')</span>' : ''; ?>
            </h1>
        </div>
        <?php if (isAdmin() || isProjectManager()): ?>
            <button class="modal-trigger bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded-lg font-medium transition-colors duration-200 flex items-center gap-2 shadow-md hover:shadow-lg" data-modal="taskModal" title="Add New Task">
                <i class="fas fa-plus"></i>
                <span>Add New Task</span>
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

    <!-- Task Management Filter -->
    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6">
        <div class="flex items-center gap-2 flex-wrap">
                <?php 
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
            'Done' => '#28a745'
        ];
        
        $status_icons = [
            'All' => 'fa-tasks',
            'To Do' => 'fa-clock',
            'In Progress' => 'fa-spinner',
            'Done' => 'fa-check-circle'
        ];
        
        foreach ($status_counts as $status => $count):
            $is_active = (empty($filter_status) && $status == 'All') || (!empty($filter_status) && in_array($status, $filter_status));
            $filter_url = 'tasks.php?';
            $url_params = [];
            // Preserve project filter if exists
            if (!empty($filter_project)) {
                $url_params['project_id'] = $filter_project[0];
            } elseif (isset($_SESSION['selected_project_id']) && !empty($_SESSION['selected_project_id'])) {
                $url_params['project_id'] = $_SESSION['selected_project_id'];
            }
            // Set status filter (or remove if All is selected)
            if ($status != 'All') {
                $url_params['status'] = $status;
            }
            // Preserve assignee filter if exists (all selected assignees)
            if (!empty($filter_assignee)) {
                $url_params['assignee_id'] = $filter_assignee;
            }
            // Preserve search if exists
            if (!empty($search)) {
                $url_params['search'] = $search;
            }
            $filter_url .= http_build_query($url_params);
        ?>
            <a href="<?php echo htmlspecialchars($filter_url); ?>" 
               class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-semibold transition-all duration-200 <?php echo $is_active ? 'text-white shadow-md' : 'bg-white text-gray-700 hover:text-white border-2'; ?>"
               style="background: <?php echo $is_active ? $status_colors[$status] : '#fff'; ?>; border-color: <?php echo $status_colors[$status]; ?>;"
               onmouseover="this.style.background='<?php echo $status_colors[$status]; ?>'; this.style.color='#fff';"
               onmouseout="this.style.background='<?php echo $is_active ? $status_colors[$status] : '#fff'; ?>'; this.style.color='<?php echo $is_active ? '#fff' : '#333'; ?>';"
               title="Filter by <?php echo htmlspecialchars($status); ?>">
                <i class="fas <?php echo $status_icons[$status]; ?>"></i>
                <span><?php echo htmlspecialchars($status); ?></span>
                <span class="px-2 py-0.5 rounded-full text-xs font-bold <?php echo $is_active ? 'bg-white bg-opacity-30' : ''; ?>" style="background: <?php echo $is_active ? 'rgba(255,255,255,0.3)' : $status_colors[$status]; ?>; color: #fff;">
                    <?php echo $count; ?>
                </span>
            </a>
        <?php endforeach; ?>
        
                <?php 
        // Show assignee badges and unassigned
        // Note: $unassigned_count is already calculated above before connection was closed
        if (!empty($assignee_stats) || $unassigned_count > 0): ?>
            <div class="flex items-center gap-2 flex-wrap justify-end"> |
                <!-- All Assignees Filter (Reset) -->
                <?php 
                $has_assignee_filter = !empty($filter_assignee);
                $all_assignees_url = 'tasks.php?';
                $all_assignees_params = [];
                // Preserve project filter if exists
                if (!empty($filter_project)) {
                    $all_assignees_params['project_id'] = $filter_project[0];
                } elseif (isset($_SESSION['selected_project_id']) && !empty($_SESSION['selected_project_id'])) {
                    $all_assignees_params['project_id'] = $_SESSION['selected_project_id'];
                }
                // Preserve status filter if exists
                if (!empty($filter_status)) {
                    $all_assignees_params['status'] = is_array($filter_status) ? $filter_status : $filter_status;
                }
                // Preserve search if exists
                if (!empty($search)) {
                    $all_assignees_params['search'] = $search;
                }
                // Explicitly clear assignee_id from session when reset is clicked
                // Don't include assignee_id in URL params - this will clear the filter
                $all_assignees_url .= http_build_query($all_assignees_params);
                // Add a parameter to clear the session project selection if needed
                if (empty($all_assignees_params)) {
                    $all_assignees_url = 'tasks.php';
                }
                ?>
            <a href="<?php echo htmlspecialchars($all_assignees_url); ?>" 
               class="inline-flex items-center justify-center w-10 h-10 rounded-full text-sm font-semibold transition-all duration-200 <?php echo !$has_assignee_filter ? 'bg-indigo-600 text-white shadow-md' : 'bg-white text-gray-700 hover:bg-indigo-600 hover:text-white border-2 border-indigo-600'; ?>"
               title="Reset Assignee Filter (Clear All Selections)"
               style="min-width: 40px; min-height: 40px;">
                <i class="fas fa-redo"></i>
            </a>
                
                <!-- Unassigned Filter -->
                <?php if ($unassigned_count > 0): 
                    $is_unassigned_active = !empty($filter_assignee) && in_array(0, $filter_assignee);
                    
                    // Toggle logic: if active, remove it; if not active, add it
                    $new_filter_assignee = $filter_assignee;
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
                    
                    $filter_url = 'tasks.php?';
                    $url_params = [];
                    if (!empty($filter_project)) {
                        $url_params['project_id'] = $filter_project[0];
                    } elseif (isset($_SESSION['selected_project_id']) && !empty($_SESSION['selected_project_id'])) {
                        $url_params['project_id'] = $_SESSION['selected_project_id'];
                    }
                    if (!empty($filter_status)) {
                        $url_params['status'] = is_array($filter_status) ? $filter_status : $filter_status;
                    }
                    // Set assignee filter (multiple selections)
                    if (!empty($new_filter_assignee)) {
                        $url_params['assignee_id'] = $new_filter_assignee;
                    }
                    if (!empty($search)) {
                        $url_params['search'] = $search;
                    }
                    $filter_url .= http_build_query($url_params);
                ?>
                    <a href="<?php echo htmlspecialchars($filter_url); ?>" 
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-semibold transition-all duration-200 <?php echo $is_unassigned_active ? 'bg-indigo-600 text-white shadow-md' : 'bg-white text-gray-700 hover:bg-indigo-600 hover:text-white border-2 border-indigo-600'; ?>"
                       title="Filter by Unassigned">
                        <i class="fas fa-user-slash"></i>
                        <span>Unassigned</span>
                    </a>
                <?php endif; ?>
                
                <?php foreach ($assignee_stats as $assignee): 
                    $initials = getInitials($assignee['full_name']);
                    $is_active = !empty($filter_assignee) && in_array($assignee['id'], $filter_assignee);
                    
                    // Toggle logic: if active, remove it; if not active, add it
                    $new_filter_assignee = $filter_assignee;
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
                    
                    $filter_url = 'tasks.php?';
                    $url_params = [];
                    // Preserve project filter if exists
                    if (!empty($filter_project)) {
                        $url_params['project_id'] = $filter_project[0];
                    } elseif (isset($_SESSION['selected_project_id']) && !empty($_SESSION['selected_project_id'])) {
                        $url_params['project_id'] = $_SESSION['selected_project_id'];
                    }
                    // Preserve status filter if exists
                    if (!empty($filter_status)) {
                        $url_params['status'] = is_array($filter_status) ? $filter_status : $filter_status;
                    }
                    // Set assignee filter (multiple selections)
                    if (!empty($new_filter_assignee)) {
                        $url_params['assignee_id'] = $new_filter_assignee;
                    }
                    // Preserve search if exists
                    if (!empty($search)) {
                        $url_params['search'] = $search;
                    }
                    $filter_url .= http_build_query($url_params);
                ?>
                <a href="<?php echo htmlspecialchars($filter_url); ?>" 
                   class="inline-flex items-center justify-center w-10 h-10 rounded-full text-sm font-semibold transition-all duration-200 <?php echo $is_active ? 'bg-indigo-600 text-white shadow-md ring-2 ring-indigo-300' : 'bg-white text-gray-700 hover:bg-indigo-600 hover:text-white border-2 border-indigo-600'; ?>"
                   title="<?php echo htmlspecialchars($assignee['full_name']); ?>"
                   style="min-width: 40px; min-height: 40px;">
                    <span><?php echo htmlspecialchars($initials); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
</div>

    <!-- Search -->
    <div class="mb-6">
        <form method="GET" action="" class="flex gap-3 items-center">
            <div class="flex-1 max-w-md relative">
                <input type="text" name="search" id="searchInput" 
                       placeholder="Search by Task ID or Title..." 
                       value="<?php echo htmlspecialchars($search); ?>"
                       class="w-full px-4 py-2.5 pl-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 outline-none transition-all">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
        </div>
            <?php if (!empty($filter_project)): ?>
                <input type="hidden" name="project_id" value="<?php echo $filter_project[0]; ?>">
            <?php endif; ?>
            <?php if (!empty($filter_status)): ?>
                <?php foreach ($filter_status as $status): ?>
                    <input type="hidden" name="status[]" value="<?php echo htmlspecialchars($status); ?>">
                <?php endforeach; ?>
            <?php endif; ?>
            <button type="submit" class="bg-teal-600 hover:bg-teal-700 text-white px-5 py-2.5 rounded-lg font-medium transition-colors duration-200 flex items-center gap-2 shadow-md hover:shadow-lg" title="Search">
                <i class="fas fa-search"></i>
                <span>Search</span>
            </button>
            <?php if (!empty($search)): ?>
                <a href="tasks.php<?php 
                    $clear_params = [];
                    if (!empty($filter_project)) $clear_params['project_id'] = $filter_project[0];
                    if (!empty($filter_status)) $clear_params['status'] = is_array($filter_status) ? $filter_status : [$filter_status];
                    echo !empty($clear_params) ? '?' . http_build_query($clear_params) : ''; 
                ?>" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-5 py-2.5 rounded-lg font-medium transition-colors duration-200 flex items-center gap-2" title="Clear Search">
                    <i class="fas fa-times"></i>
                    <span>Clear</span>
                </a>
            <?php endif; ?>
        </form>
</div>

    <!-- Tasks Table -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Task ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Priority</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assignee</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Due Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
                <tbody id="tasksTableBody" class="bg-white divide-y divide-gray-200">
            <?php if (empty($tasks)): ?>
                <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-tasks text-4xl text-gray-300 mb-3 block"></i>
                                <p>No tasks found</p>
                            </td>
                </tr>
            <?php else: ?>
                <?php foreach ($tasks as $task): ?>
                            <?php
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
                            ?>
                            <tr class="hover:bg-gray-50 transition-colors cursor-pointer" onclick="window.location.href='task_view.php?id=<?php echo $task['id']; ?>'" style="cursor: pointer;">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($task['task_id']); ?></span>
                                        <i class="fas <?php echo $type_icon; ?> text-sm" style="color: <?php echo $type_color; ?>;" title="<?php echo htmlspecialchars($task['type']); ?>"></i>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($task['title']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2.5 py-1 text-xs font-medium rounded-full 
                                        <?php 
                                        echo strtolower($task['priority']) == 'high' ? 'bg-red-100 text-red-800' : 
                                            (strtolower($task['priority']) == 'medium' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800');
                                        ?>">
                                <?php echo htmlspecialchars($task['priority']); ?>
                            </span>
                        </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2.5 py-1 text-xs font-medium rounded-full 
                                        <?php 
                                        $status_lower = strtolower(str_replace(' ', '-', $task['status']));
                                        echo $status_lower == 'done' ? 'bg-green-100 text-green-800' : 
                                            ($status_lower == 'in-progress' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800');
                                        ?>">
                                <?php echo htmlspecialchars($task['status']); ?>
                            </span>
                        </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($task['assignee_name'] ?? 'Unassigned'); ?>
                        </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm <?php echo ($task['due_date'] && strtotime($task['due_date']) < time() && $task['status'] != 'Done') ? 'text-red-600 font-semibold' : 'text-gray-500'; ?>">
                                    <?php echo formatDate($task['due_date']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium" onclick="event.stopPropagation();">
                                    <div class="flex items-center gap-2">
                                        <a href="task_view.php?id=<?php echo $task['id']; ?>" class="text-teal-600 hover:text-teal-900 transition-colors" title="View" onclick="event.stopPropagation();">
                                            <i class="fas fa-eye"></i>
                                        </a>
                            <?php if (isAdmin() || isProjectManager() || $task['assignee_id'] == $_SESSION['user_id']): ?>
                                            <a href="?edit=<?php echo $task['id']; ?>" class="text-yellow-600 hover:text-yellow-900 transition-colors" title="Edit" onclick="event.stopPropagation();">
                                                <i class="fas fa-edit"></i>
                                            </a>
                            <?php endif; ?>
                            <?php if (isAdmin() || isProjectManager()): ?>
                                            <a href="?delete=<?php echo $task['id']; ?>" class="text-red-600 hover:text-red-900 transition-colors btn-delete" title="Delete" onclick="event.stopPropagation();">
                                                <i class="fas fa-trash"></i>
                                            </a>
                            <?php endif; ?>
                                    </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
        </div>
</div>

<!-- Pagination -->
<?php 
$pagination_html = generatePagination($current_page, $total_pages, $_GET);
if ($pagination_html): 
?>
        <div id="paginationContainer" class="mt-6 flex justify-center items-center gap-3 flex-wrap">
        <?php echo $pagination_html; ?>
            <span class="text-gray-600 text-sm ml-4">
            Showing <?php echo count($tasks); ?> of <?php echo $total_items; ?> tasks
        </span>
    </div>
<?php endif; ?>
</div>

<!-- Add/Edit Task Modal -->
<div id="taskModal" class="modal">
    <div class="modal-content bg-white rounded-lg shadow-2xl max-w-2xl w-full mx-auto my-8 relative">
        <?php 
        // Get selected project from session (if coming from dashboard) or from edit_task
        $selected_project_id = null;
        $is_project_locked = false;
        $locked_project_name = '';
        
        if ($edit_task) {
            $selected_project_id = $edit_task['project_id'];
        } elseif (isset($_SESSION['selected_project_id']) && !empty($_SESSION['selected_project_id'])) {
            $selected_project_id = intval($_SESSION['selected_project_id']);
            $is_project_locked = true; // Lock it if coming from dashboard
            // Get project name
            foreach ($projects as $project) {
                if ($project['id'] == $selected_project_id) {
                    $locked_project_name = $project['name'];
                    break;
                }
            }
        }
        ?>
        
        <!-- Modal Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-bold text-teal-700">
                <?php echo $edit_task ? 'Edit Task' : 'Add New Task'; ?>
                <?php if ($is_project_locked && !$edit_task): ?>
                    <span class="text-base font-medium text-gray-500 ml-2">
                        (<?php echo htmlspecialchars($locked_project_name); ?>)
                    </span>
                <?php endif; ?>
            </h2>
            <button type="button" class="close text-gray-400 hover:text-gray-600 text-2xl font-bold leading-none transition-colors duration-200">
                &times;
            </button>
        </div>
        
        <!-- Modal Body -->
        <form method="POST" action="" class="px-6 py-4">
            <?php if ($edit_task): ?>
                <input type="hidden" name="task_id" value="<?php echo $edit_task['id']; ?>">
                <input type="hidden" name="update_task" value="1">
            <?php else: ?>
                <input type="hidden" name="create_task" value="1">
            <?php endif; ?>
            
            <!-- Project Field -->
            <div class="mb-4" <?php echo ($is_project_locked && !$edit_task) ? 'style="display: none;"' : ''; ?>>
                <label for="project_id" class="block text-sm font-semibold text-gray-700 mb-2">
                    Project <span class="text-red-500">*</span>
                </label>
                <select id="project_id" name="project_id" required 
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 outline-none transition-all duration-200 bg-white">
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
            <div class="mb-4">
                <label for="title" class="block text-sm font-semibold text-gray-700 mb-2">
                    Title <span class="text-red-500">*</span>
                </label>
                <input type="text" id="title" name="title" required 
                       value="<?php echo htmlspecialchars($edit_task['title'] ?? ''); ?>"
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 outline-none transition-all duration-200">
            </div>
            
            <!-- Description Field -->
            <div class="mb-4">
                <label for="description" class="block text-sm font-semibold text-gray-700 mb-2">
                    Description
                </label>
                <textarea id="description" name="description" rows="4"
                          class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 outline-none transition-all duration-200 resize-y"><?php echo htmlspecialchars($edit_task['description'] ?? ''); ?></textarea>
            </div>
            
            <!-- Type and Priority Row -->
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label for="type" class="block text-sm font-semibold text-gray-700 mb-2">
                        Type <span class="text-red-500">*</span>
                    </label>
                    <select id="type" name="type" required
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 outline-none transition-all duration-200 bg-white">
                        <option value="Task" <?php echo ($edit_task && $edit_task['type'] == 'Task') ? 'selected' : ''; ?>>Task</option>
                        <option value="Bug" <?php echo ($edit_task && $edit_task['type'] == 'Bug') ? 'selected' : ''; ?>>Bug</option>
                        <option value="Improvement" <?php echo ($edit_task && $edit_task['type'] == 'Improvement') ? 'selected' : ''; ?>>Improvement</option>
                    </select>
                </div>
                
                <div>
                    <label for="priority" class="block text-sm font-semibold text-gray-700 mb-2">
                        Priority <span class="text-red-500">*</span>
                    </label>
                    <select id="priority" name="priority" required
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 outline-none transition-all duration-200 bg-white">
                        <option value="Low" <?php echo ($edit_task && $edit_task['priority'] == 'Low') ? 'selected' : ''; ?>>Low</option>
                        <option value="Medium" <?php echo ($edit_task && $edit_task['priority'] == 'Medium') ? 'selected' : ''; ?>>Medium</option>
                        <option value="High" <?php echo ($edit_task && $edit_task['priority'] == 'High') ? 'selected' : ''; ?>>High</option>
                    </select>
                </div>
            </div>
            
            <!-- Status Field (Edit Mode Only) -->
            <?php if ($edit_task): ?>
                <div class="mb-4">
                    <label for="status" class="block text-sm font-semibold text-gray-700 mb-2">
                        Status <span class="text-red-500">*</span>
                    </label>
                    <select id="status" name="status" required
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 outline-none transition-all duration-200 bg-white">
                        <option value="To Do" <?php echo $edit_task['status'] == 'To Do' ? 'selected' : ''; ?>>To Do</option>
                        <option value="In Progress" <?php echo $edit_task['status'] == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="Done" <?php echo $edit_task['status'] == 'Done' ? 'selected' : ''; ?>>Done</option>
                    </select>
                </div>
            <?php endif; ?>
            
            <!-- Assignee and Due Date Row -->
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div>
                    <label for="assignee_id" class="block text-sm font-semibold text-gray-700 mb-2">
                        Assignee
                    </label>
                    <select id="assignee_id" name="assignee_id"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 outline-none transition-all duration-200 bg-white">
                        <option value="">Unassigned</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" 
                                    <?php echo ($edit_task && $edit_task['assignee_id'] == $user['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="due_date" class="block text-sm font-semibold text-gray-700 mb-2">
                        Due Date
                    </label>
                    <input type="date" id="due_date" name="due_date" 
                           value="<?php echo $edit_task['due_date'] ?? ''; ?>"
                           class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 outline-none transition-all duration-200">
                </div>
            </div>
            
            <!-- Modal Footer with Buttons -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200 mt-6">
                <button type="button" class="close px-5 py-2.5 bg-gray-100 text-gray-700 rounded-lg font-medium hover:bg-gray-200 transition-colors duration-200 flex items-center gap-2" title="Cancel">
                    <i class="fas fa-times"></i>
                    <span>Cancel</span>
                </button>
                <button type="submit" class="px-5 py-2.5 bg-teal-600 text-white rounded-lg font-medium hover:bg-teal-700 transition-colors duration-200 flex items-center gap-2 shadow-md hover:shadow-lg" title="<?php echo $edit_task ? 'Update Task' : 'Create Task'; ?>">
                    <i class="fas fa-<?php echo $edit_task ? 'save' : 'plus'; ?>"></i>
                    <span><?php echo $edit_task ? 'Update Task' : 'Create Task'; ?></span>
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($edit_task): ?>
    <script>
        $(document).ready(function() {
            $('#taskModal').show();
        });
    </script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
