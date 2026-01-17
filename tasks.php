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
        $tasks_html = '<tr><td colspan="6" style="text-align: center; color: #999;">No tasks found</td></tr>';
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
            } elseif ($task['status'] == 'Done') {
                $due_date_style = $due_date_styles['normal'];
                $due_date_display = formatDate($due_date);
            } elseif (strtotime($due_date) < time()) {
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
            $tasks_html .= '<td class="px-6 py-4 whitespace-nowrap" style="cursor: pointer;" onclick="window.location.href=\'task_view?id=' . $task['id'] . '\'">';
            $tasks_html .= '<div class="flex items-center gap-2">';
            $tasks_html .= '<span class="font-semibold text-gray-900">' . htmlspecialchars($task['task_id']) . '</span>';
            $tasks_html .= '<i class="fas ' . $type_icon . ' text-sm" style="color: ' . $type_color . ';" title="' . htmlspecialchars($task['type']) . '"></i>';
            $tasks_html .= '</div>';
            $tasks_html .= '</td>';
            $tasks_html .= '<td class="px-6 py-4" style="cursor: pointer;" onclick="window.location.href=\'task_view?id=' . $task['id'] . '\'">';
            $tasks_html .= '<div class="text-sm text-gray-900 font-medium">' . htmlspecialchars($task['title']) . '</div>';
            $tasks_html .= '</td>';
            $tasks_html .= '<td class="px-6 py-4 whitespace-nowrap text-center">';
            $tasks_html .= '<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full ' . $priority_style['bg'] . ' ' . $priority_style['text'] . '">';
            $tasks_html .= '<i class="fas ' . $priority_style['icon'] . ' ' . $priority_style['icon_color'] . ' text-xs"></i>';
            $tasks_html .= htmlspecialchars($task['priority']);
            $tasks_html .= '</span>';
            $tasks_html .= '</td>';
            $tasks_html .= '<td class="px-6 py-4 whitespace-nowrap text-center">';
            $tasks_html .= '<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full ' . $status_style['bg'] . ' ' . $status_style['text'] . '">';
            $tasks_html .= '<i class="fas ' . $status_style['icon'] . ' ' . $status_style['icon_color'] . ' text-xs"></i>';
            $tasks_html .= htmlspecialchars($task['status']);
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

<div style="width: 100%; padding: 15px;">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="dashboard" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center shadow-sm hover:shadow-md" title="Back to Dashboard" style="min-width: 40px; min-height: 40px;">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1 class="text-2xl md:text-3xl font-semibold text-gray-900 page-title">
                Tasks<?php echo $selected_project_name ? ' <span class="text-gray-500 font-normal">(' . htmlspecialchars($selected_project_name) . ')</span>' : ''; ?>
            </h1>
        </div>
    <?php if (isAdmin() || isProjectManager()): ?>
            <a href="task_form" class="bg-teal-600 hover:bg-teal-700 text-white px-3 py-2 md:px-4 md:py-2 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center gap-2 shadow-md hover:shadow-lg add-task-btn" title="Add New Task" style="min-width: 44px; min-height: 44px;">
                <i class="fas fa-plus"></i>
                <span class="add-task-text hidden md:inline">Add New Task</span>
            </a>
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
            $filter_url = 'tasks?';
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
                    $all_assignees_url = 'tasks';
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
                    
                    $filter_url = 'tasks?';
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
                    
                    $filter_url = 'tasks?';
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
        <div class="flex-1 max-w-md relative" style="position: relative;">
            <input type="text" name="search" id="searchInput" 
                   placeholder="Search by Task ID (e.g., 12/25, CAR-1) or Title..." 
                   value="<?php echo htmlspecialchars($search); ?>"
                   class="w-full px-4 py-2.5 pl-10 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 outline-none transition-all"
                   autocomplete="off">
            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
            <?php if (!empty($search)): ?>
                <button type="button" id="clearSearchBtn" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors" title="Clear Search">
                    <i class="fas fa-times"></i>
                </button>
            <?php endif; ?>
            <!-- Search Suggestions Dropdown -->
            <div id="searchSuggestions" class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto hidden" style="top: 100%;">
                <div id="suggestionsList" class="py-1"></div>
        </div>
    </div>
</div>

    <!-- Tasks Table -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead style="background-color: #1e293b;">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-white uppercase tracking-wider">Task ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-white uppercase tracking-wider">Title</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-white uppercase tracking-wider" style="width: 120px;">Priority</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-white uppercase tracking-wider" style="width: 140px;">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-white uppercase tracking-wider" style="width: 100px;">Assignee</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-white uppercase tracking-wider" style="width: 150px;">Due Date</th>
            </tr>
        </thead>
                <tbody id="tasksTableBody" class="bg-white divide-y divide-gray-200">
            <?php if (empty($tasks)): ?>
                <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-gray-500">
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
                            <tr class="hover:bg-blue-50 transition-colors cursor-pointer" style="transition: background-color 0.2s ease;">
                                <td class="px-6 py-4 whitespace-nowrap" style="cursor: pointer;" onclick="window.location.href='task_view?id=<?php echo $task['id']; ?>'">
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($task['task_id']); ?></span>
                                        <i class="fas <?php echo $type_icon; ?> text-sm" style="color: <?php echo $type_color; ?>;" title="<?php echo htmlspecialchars($task['type']); ?>"></i>
                                    </div>
                        </td>
                                <td class="px-6 py-4" style="cursor: pointer;" onclick="window.location.href='task_view?id=<?php echo $task['id']; ?>'">
                                    <div class="text-sm text-gray-900 font-medium"><?php echo htmlspecialchars($task['title']); ?></div>
                        </td>
                            <?php 
                                        // Priority badge
                                        $priority_lower = strtolower($task['priority']);
                                        $priority_colors = [
                                            'high' => ['bg' => 'bg-red-100', 'text' => 'text-red-800', 'icon' => 'fa-exclamation-circle', 'icon_color' => 'text-red-600'],
                                            'medium' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800', 'icon' => 'fa-exclamation-triangle', 'icon_color' => 'text-yellow-600'],
                                            'low' => ['bg' => 'bg-green-100', 'text' => 'text-green-800', 'icon' => 'fa-check-circle', 'icon_color' => 'text-green-600']
                                        ];
                                        $priority_style = $priority_colors[$priority_lower] ?? $priority_colors['low'];
                                        
                                        // Status badge
                                        $status_lower = strtolower(str_replace(' ', '-', $task['status']));
                                        $status_colors = [
                                            'done' => ['bg' => 'bg-green-100', 'text' => 'text-green-800', 'icon' => 'fa-check-circle', 'icon_color' => 'text-green-600'],
                                            'in-progress' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-800', 'icon' => 'fa-spinner', 'icon_color' => 'text-blue-600'],
                                            'to-do' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-800', 'icon' => 'fa-clock', 'icon_color' => 'text-gray-600']
                                        ];
                                        $status_style = $status_colors[$status_lower] ?? $status_colors['to-do'];
                                        
                                        // Assignee badge
                                        $assignee_name = $task['assignee_name'] ?? null;
                                        $assignee_colors = [
                                            'assigned' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-800', 'icon' => 'fa-user', 'icon_color' => 'text-blue-600'],
                                            'unassigned' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-800', 'icon' => 'fa-user-slash', 'icon_color' => 'text-gray-600']
                                        ];
                                        $assignee_style = $assignee_name ? $assignee_colors['assigned'] : $assignee_colors['unassigned'];
                                        
                                        // Due date badge
                                        $due_date = $task['due_date'];
                                        $due_date_colors = [
                                            'overdue' => ['bg' => 'bg-red-100', 'text' => 'text-red-800', 'icon' => 'fa-exclamation-triangle', 'icon_color' => 'text-red-600'],
                                            'upcoming' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800', 'icon' => 'fa-calendar-alt', 'icon_color' => 'text-yellow-600'],
                                            'normal' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-800', 'icon' => 'fa-calendar', 'icon_color' => 'text-gray-600'],
                                            'nodate' => ['bg' => 'bg-gray-50', 'text' => 'text-gray-500', 'icon' => 'fa-calendar-times', 'icon_color' => 'text-gray-400']
                                        ];
                                        
                                        if (!$due_date) {
                                            $due_date_style = $due_date_colors['nodate'];
                                            $due_date_display = 'No due date';
                                        } elseif ($task['status'] == 'Done') {
                                            $due_date_style = $due_date_colors['normal'];
                                            $due_date_display = formatDate($due_date);
                                        } elseif (strtotime($due_date) < time()) {
                                            $due_date_style = $due_date_colors['overdue'];
                                            $due_date_display = formatDate($due_date);
                                        } elseif (strtotime($due_date) <= strtotime('+3 days')) {
                                            $due_date_style = $due_date_colors['upcoming'];
                                            $due_date_display = formatDate($due_date);
                            } else {
                                            $due_date_style = $due_date_colors['normal'];
                                            $due_date_display = formatDate($due_date);
                            }
                            ?>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full <?php echo $priority_style['bg'] . ' ' . $priority_style['text']; ?>">
                                        <i class="fas <?php echo $priority_style['icon']; ?> <?php echo $priority_style['icon_color']; ?> text-xs"></i>
                                        <?php echo htmlspecialchars($task['priority']); ?>
                                    </span>
                        </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full <?php echo $status_style['bg'] . ' ' . $status_style['text']; ?>">
                                        <i class="fas <?php echo $status_style['icon']; ?> <?php echo $status_style['icon_color']; ?> text-xs"></i>
                                        <?php echo htmlspecialchars($task['status']); ?>
                                    </span>
                        </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <?php if ($assignee_name): ?>
                                        <?php $assignee_initials = getInitials($assignee_name); ?>
                                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-700 border border-indigo-300" 
                                              title="<?php echo htmlspecialchars($assignee_name); ?>"
                                              style="min-width: 32px; min-height: 32px;">
                                            <?php echo htmlspecialchars($assignee_initials); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-full text-xs font-semibold bg-gray-100 text-gray-600 border border-gray-300" 
                                              title="Unassigned"
                                              style="min-width: 32px; min-height: 32px;">
                                            <i class="fas fa-user-slash" style="font-size: 10px;"></i>
                                        </span>
                            <?php endif; ?>
                        </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full <?php echo $due_date_style['bg'] . ' ' . $due_date_style['text']; ?>">
                                        <i class="fas <?php echo $due_date_style['icon']; ?> <?php echo $due_date_style['icon_color']; ?> text-xs"></i>
                                        <?php echo htmlspecialchars($due_date_display); ?>
                                    </span>
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
            
<!-- Task form is now in task_form.php - Modal removed and commented out -->
<!--
<div id="taskModal" class="modal" style="display: none;">
    ... old modal code removed ...
            </div>
-->
<!-- Modal scripts removed - using task_form.php instead -->

    <script>
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
    </script>

<?php include 'includes/footer.php'; ?>
