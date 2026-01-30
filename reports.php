<?php
require_once 'config/config.php';
requireLogin();
requireActiveSubscription();

// Only allow Super Admin, Org Admin, and Project Manager
if (!isSuperAdmin() && !isOrgAdmin() && !isProjectManager()) {
    header('Location: dashboard');
    exit();
}

$page_title = 'Reports';

$conn = getDBConnection();

// Get allowed projects (same logic as dashboard) for selected project scope
$projects_list = [];
$user_id = $_SESSION['user_id'] ?? null;
if (isSuperAdmin()) {
    $res = $conn->query("SELECT id, name FROM projects ORDER BY name");
    if ($res) $projects_list = $res->fetch_all(MYSQLI_ASSOC);
} else if (isOrgAdmin()) {
    $org_id = getOrganizationId();
    $stmt = $conn->prepare("SELECT p.id, p.name FROM projects p LEFT JOIN project_users pu ON p.id = pu.project_id WHERE p.organization_id = ? AND (p.created_by = ? OR pu.user_id = ?) GROUP BY p.id, p.name ORDER BY p.name");
    if ($stmt) {
        $stmt->bind_param("iii", $org_id, $user_id, $user_id);
        $stmt->execute();
        $projects_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} else if (isProjectManager()) {
    $stmt = $conn->prepare("SELECT p.id, p.name FROM projects p LEFT JOIN project_users pu ON p.id = pu.project_id WHERE p.project_manager_id = ? OR p.created_by = ? OR pu.user_id = ? GROUP BY p.id, p.name ORDER BY p.name");
    if ($stmt) {
        $stmt->bind_param("iii", $user_id, $user_id, $user_id);
        $stmt->execute();
        $projects_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}
$allowed_project_ids = array_column($projects_list, 'id');

// Selected project: URL (and persist to session), then session, then DB (last_dashboard_project_id), then first allowed
$selected_project_id = null;
if (isset($_GET['project_id'])) {
    $pid = intval($_GET['project_id']);
    if (in_array($pid, $allowed_project_ids)) {
        $selected_project_id = $pid;
        $_SESSION['selected_project_id'] = $pid;
        if ($user_id) {
            $st = $conn->prepare("UPDATE users SET last_dashboard_project_id = ? WHERE id = ?");
            if ($st) { $st->bind_param("ii", $pid, $user_id); $st->execute(); $st->close(); }
        }
    }
}
if ($selected_project_id === null && isset($_SESSION['selected_project_id']) && in_array((int)$_SESSION['selected_project_id'], $allowed_project_ids)) {
    $selected_project_id = (int)$_SESSION['selected_project_id'];
}
if ($selected_project_id === null && $user_id) {
    $st = $conn->prepare("SELECT last_dashboard_project_id FROM users WHERE id = ?");
    if ($st) {
        $st->bind_param("i", $user_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        $from_db = isset($row['last_dashboard_project_id']) ? (int)$row['last_dashboard_project_id'] : null;
        if ($from_db && in_array($from_db, $allowed_project_ids)) {
            $selected_project_id = $from_db;
            $_SESSION['selected_project_id'] = $from_db;
        }
    }
}
if ($selected_project_id === null && !empty($allowed_project_ids)) {
    $selected_project_id = (int)$allowed_project_ids[0];
    $_SESSION['selected_project_id'] = $selected_project_id;
}
$selected_project_name = '';
foreach ($projects_list as $p) {
    if ((int)$p['id'] === (int)$selected_project_id) { $selected_project_name = $p['name']; break; }
}

// Organization for dynamic statuses: use selected project's org, or current user's org (or null for Super Admin)
$report_org_id = null;
if ($selected_project_id) {
    $pr = $conn->prepare("SELECT organization_id FROM projects WHERE id = ?");
    $pr->bind_param("i", $selected_project_id);
    $pr->execute();
    $row = $pr->get_result()->fetch_assoc();
    if ($row && isset($row['organization_id'])) $report_org_id = $row['organization_id'];
    $pr->close();
}
if ($report_org_id === null && !isSuperAdmin()) {
    $report_org_id = getOrganizationId();
}
$report_statuses = getStatuses($report_org_id);
if (empty($report_statuses)) {
    $report_statuses = [['name' => 'To Do', 'color' => '#6b7280'], ['name' => 'In Progress', 'color' => '#3b82f6'], ['name' => 'Done', 'color' => '#10b981']];
}
// Build status count SQL inline (avoids syntax issues with dynamic status names)
$status_count_parts = [];
foreach ($report_statuses as $s) {
    $name = isset($s['name']) ? $s['name'] : '';
    $key = strtolower(str_replace(' ', '_', preg_replace('/[^a-zA-Z0-9_\s]/', '', $name)));
    if ($key === '') {
        $key = 'status';
    }
    $escaped = $conn->real_escape_string($name);
    $status_count_parts[] = "SUM(CASE WHEN t.`status` = '" . $escaped . "' THEN 1 ELSE 0 END) AS `" . $key . "_count`";
}
$status_count_sql = implode(', ', $status_count_parts);
if ($status_count_sql === '') {
    $status_count_sql = "0 AS _placeholder_count";
}
// Overdue: due_date passed and status is not a completed type
$overdue_sql = "SUM(CASE WHEN t.`due_date` < CURDATE() AND (t.`status` IS NULL OR (t.`status` NOT LIKE '%done%' AND t.`status` NOT LIKE '%closed%' AND t.`status` NOT LIKE '%complete%')) THEN 1 ELSE 0 END) AS overdue_count";
$completed_sql = "SUM(CASE WHEN t.`status` LIKE '%done%' OR t.`status` LIKE '%closed%' OR t.`status` LIKE '%complete%' THEN 1 ELSE 0 END) AS completed_count";

// Get date range from URL or default to last 30 days
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Validate dates
if (!strtotime($start_date) || !strtotime($end_date)) {
    $start_date = date('Y-m-d', strtotime('-30 days'));
    $end_date = date('Y-m-d');
}

// Build base where clause for role-based filtering + selected project only
$where_conditions = [];
$query_params = [];
$query_types = '';

// Role-based filtering
if (isSuperAdmin()) {
    // Super Admin sees all tasks (scoped by project below)
} else if (isOrgAdmin()) {
    $org_id = getOrganizationId();
    $where_conditions[] = "p.organization_id = ?";
    $query_params[] = $org_id;
    $query_types .= 'i';
} else {
    // Project Manager sees tasks in their organization
    $org_id = getOrganizationId();
    if ($org_id) {
        $where_conditions[] = "p.organization_id = ?";
        $query_params[] = $org_id;
        $query_types .= 'i';
    }
}

// Reports show only the selected project from dashboard
if ($selected_project_id) {
    $where_conditions[] = "t.project_id = ?";
    $query_params[] = $selected_project_id;
    $query_types .= 'i';
}

// Overall Statistics (dynamic status counts)
$stats_query = "SELECT COUNT(*) as total_tasks, " .
    $status_count_sql . ", " .
    $completed_sql . ", " .
    "SUM(CASE WHEN t.`priority` = 'High' THEN 1 ELSE 0 END) as `high_priority`, " .
    "SUM(CASE WHEN t.`priority` = 'Medium' THEN 1 ELSE 0 END) as `medium_priority`, " .
    "SUM(CASE WHEN t.`priority` = 'Low' THEN 1 ELSE 0 END) as `low_priority`, " .
    $overdue_sql . ", " .
    "SUM(CASE WHEN t.`type` = 'Bug' THEN 1 ELSE 0 END) as bug_count, " .
    "SUM(CASE WHEN t.`type` = 'Task' THEN 1 ELSE 0 END) as task_count, " .
    "SUM(CASE WHEN t.`type` = 'Improvement' THEN 1 ELSE 0 END) as improvement_count " .
    "FROM tasks t " .
    "LEFT JOIN projects p ON t.project_id = p.id";

if (!empty($where_conditions)) {
    $stats_query .= " WHERE " . implode(" AND ", $where_conditions);
}

$default_stats = ['total_tasks' => 0, 'completed_count' => 0, 'high_priority' => 0, 'medium_priority' => 0, 'low_priority' => 0, 'overdue_count' => 0, 'bug_count' => 0, 'task_count' => 0, 'improvement_count' => 0];
foreach ($report_statuses as $s) {
    $key = strtolower(str_replace(' ', '_', $s['name']));
    $default_stats[$key . '_count'] = 0;
}

if (!empty($query_params)) {
    $stats_stmt = $conn->prepare($stats_query);
    if ($stats_stmt) {
        $stats_stmt->bind_param($query_types, ...$query_params);
        $stats_stmt->execute();
        $stats_result = $stats_stmt->get_result();
        $overall_stats = $stats_result->fetch_assoc();
        $stats_stmt->close();
        if (!$overall_stats) $overall_stats = $default_stats;
        foreach ($default_stats as $k => $v) {
            if (!array_key_exists($k, $overall_stats)) $overall_stats[$k] = $v;
        }
    } else {
        $overall_stats = $default_stats;
    }
} else {
    $stats_result = $conn->query($stats_query);
    if ($stats_result) {
        $overall_stats = $stats_result->fetch_assoc();
        if (!$overall_stats) $overall_stats = $default_stats;
        foreach ($default_stats as $k => $v) {
            if (!array_key_exists($k, $overall_stats)) $overall_stats[$k] = $v;
        }
    } else {
        $overall_stats = $default_stats;
    }
}

// Tasks by Project (only selected project when set)
$projects_where = [];
$projects_params = [];
$projects_types = '';

if (isSuperAdmin()) {
    // Super Admin
} else if (isOrgAdmin()) {
    $org_id = getOrganizationId();
    $projects_where[] = "p.organization_id = ?";
    $projects_params[] = $org_id;
    $projects_types .= 'i';
} else {
    $org_id = getOrganizationId();
    if ($org_id) {
        $projects_where[] = "p.organization_id = ?";
        $projects_params[] = $org_id;
        $projects_types .= 'i';
    }
}
if ($selected_project_id) {
    $projects_where[] = "p.id = ?";
    $projects_params[] = $selected_project_id;
    $projects_types .= 'i';
}
// Date filter: only count tasks created in date range
$projects_where[] = "DATE(t.created_at) >= ?";
$projects_where[] = "DATE(t.created_at) <= ?";
$projects_params[] = $start_date;
$projects_params[] = $end_date;
$projects_types .= 'ss';

$projects_where_clause = !empty($projects_where) ? "WHERE " . implode(" AND ", $projects_where) : "";

$projects_query = "
    SELECT 
        p.id,
        p.name as project_name,
        COUNT(t.id) as task_count,
        $status_count_sql,
        $completed_sql
    FROM projects p
    LEFT JOIN tasks t ON p.id = t.project_id
    $projects_where_clause
    GROUP BY p.id, p.name
    HAVING task_count > 0
    ORDER BY task_count DESC
    LIMIT 10
";

if (!empty($projects_params)) {
    $projects_stmt = $conn->prepare($projects_query);
    $projects_stmt->bind_param($projects_types, ...$projects_params);
    $projects_stmt->execute();
    $projects_result = $projects_stmt->get_result();
    $projects_stats = $projects_result->fetch_all(MYSQLI_ASSOC);
    $projects_stmt->close();
} else {
    $projects_result = $conn->query($projects_query);
    $projects_stats = $projects_result->fetch_all(MYSQLI_ASSOC);
}

// Tasks by Assignee (only selected project when set)
$assignees_where = [];
$assignees_params = [];
$assignees_types = '';

if (isSuperAdmin()) {
    // Super Admin
} else if (isOrgAdmin()) {
    $org_id = getOrganizationId();
    $assignees_where[] = "p.organization_id = ?";
    $assignees_params[] = $org_id;
    $assignees_types .= 'i';
} else {
    $org_id = getOrganizationId();
    if ($org_id) {
        $assignees_where[] = "p.organization_id = ?";
        $assignees_params[] = $org_id;
        $assignees_types .= 'i';
    }
}
if ($selected_project_id) {
    $assignees_where[] = "t.project_id = ?";
    $assignees_params[] = $selected_project_id;
    $assignees_types .= 'i';
}
// Date filter: only count tasks created in date range
$assignees_where[] = "DATE(t.created_at) >= ?";
$assignees_where[] = "DATE(t.created_at) <= ?";
$assignees_params[] = $start_date;
$assignees_params[] = $end_date;
$assignees_types .= 'ss';

$assignees_where_clause = !empty($assignees_where) ? "WHERE " . implode(" AND ", $assignees_where) : "";

$assignees_query = "
    SELECT 
        u.id,
        u.full_name,
        COUNT(t.id) as task_count,
        $status_count_sql,
        $overdue_sql
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assignee_id
    LEFT JOIN projects p ON t.project_id = p.id
    $assignees_where_clause
    GROUP BY u.id, u.full_name
    HAVING task_count > 0
    ORDER BY task_count DESC
    LIMIT 10
";

if (!empty($assignees_params)) {
    $assignees_stmt = $conn->prepare($assignees_query);
    $assignees_stmt->bind_param($assignees_types, ...$assignees_params);
    $assignees_stmt->execute();
    $assignees_result = $assignees_stmt->get_result();
    $assignees_stats = $assignees_result->fetch_all(MYSQLI_ASSOC);
    $assignees_stmt->close();
} else {
    $assignees_result = $conn->query($assignees_query);
    $assignees_stats = $assignees_result->fetch_all(MYSQLI_ASSOC);
}

// Tasks created in date range (date filter already in $where_conditions)
$date_range_where = $where_conditions;
$date_range_params = $query_params;
$date_range_types = $query_types;

$date_range_query = "
    SELECT 
        DATE(t.created_at) as date,
        COUNT(*) as task_count,
        $completed_sql
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    WHERE " . implode(" AND ", $date_range_where) . "
    GROUP BY DATE(t.created_at)
    ORDER BY date ASC
";

$date_range_stmt = $conn->prepare($date_range_query);
$date_range_stmt->bind_param($date_range_types, ...$date_range_params);
$date_range_stmt->execute();
$date_range_result = $date_range_stmt->get_result();
$date_range_stats = $date_range_result->fetch_all(MYSQLI_ASSOC);
$date_range_stmt->close();

// CSV download: filtered tasks (same filters as report + date range)
if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    $csv_where = array_merge($where_conditions, ["DATE(t.created_at) >= ?", "DATE(t.created_at) <= ?"]);
    $csv_params = array_merge($query_params, [$start_date, $end_date]);
    $csv_types = $query_types . 'ss';
    $csv_query = "
        SELECT t.task_id, t.title, p.name AS project_name, t.type, t.priority, t.status,
               u.full_name AS assignee_name, t.due_date, t.created_at
        FROM tasks t
        LEFT JOIN projects p ON t.project_id = p.id
        LEFT JOIN users u ON t.assignee_id = u.id
        WHERE " . implode(" AND ", $csv_where) . "
        ORDER BY t.created_at DESC
    ";
    $csv_stmt = $conn->prepare($csv_query);
    if ($csv_stmt) {
        $csv_stmt->bind_param($csv_types, ...$csv_params);
        $csv_stmt->execute();
        $csv_result = $csv_stmt->get_result();
        $filename = 'tasks_report_' . ($selected_project_id ? 'project_' . $selected_project_id . '_' : '') . $start_date . '_' . $end_date . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        fputcsv($out, ['Task ID', 'Title', 'Project', 'Type', 'Priority', 'Status', 'Assignee', 'Due Date', 'Created At']);
        while ($row = $csv_result->fetch_assoc()) {
            fputcsv($out, [
                $row['task_id'] ?? '',
                $row['title'] ?? '',
                $row['project_name'] ?? '',
                $row['type'] ?? '',
                $row['priority'] ?? '',
                $row['status'] ?? '',
                $row['assignee_name'] ?? '',
                $row['due_date'] ?? '',
                $row['created_at'] ?? ''
            ]);
        }
        fclose($out);
        $csv_stmt->close();
    }
    $conn->close();
    exit;
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
            <div>
                <h1 class="text-2xl md:text-3xl font-semibold text-gray-900">
                    Reports & Analytics
                </h1>
                <p class="text-gray-500 text-sm mt-1">Task statistics and insights</p>
            </div>
        </div>
    </div>

    <?php if (empty($projects_list)): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 text-amber-800">
        <p class="font-medium">No project available</p>
        <p class="text-sm mt-1">Select a project on the dashboard to view reports, or ask an admin to assign you to a project.</p>
        <a href="dashboard" class="inline-block mt-2 text-teal-600 hover:text-teal-700 font-medium">Go to Dashboard</a>
    </div>
    <?php else: ?>

    <!-- Date Range & Project Filter -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
        <form method="GET" action="" class="flex items-center gap-4 flex-wrap" id="reports-filter-form">
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium text-gray-700">Project:</label>
                <select name="project_id" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500" onchange="this.form.submit()">
                    <?php foreach ($projects_list as $p): ?>
                    <option value="<?php echo (int)$p['id']; ?>" <?php echo ((int)$p['id'] === (int)$selected_project_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium text-gray-700">Start Date:</label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" 
                       class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium text-gray-700">End Date:</label>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" 
                       class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <button type="submit" class="bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded-lg font-medium transition-colors duration-200 shadow-sm hover:shadow-md">
                <i class="fas fa-filter"></i> Apply Filter
            </button>
            <a href="reports?project_id=<?php echo (int)$selected_project_id; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&download=csv" 
               class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg font-medium transition-colors duration-200 shadow-sm hover:shadow-md inline-flex items-center gap-2">
                <i class="fas fa-download"></i> Download CSV
            </a>
        </form>
    </div>

    <!-- Overall Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Total Tasks</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo intval($overall_stats['total_tasks']); ?></p>
                </div>
                <div class="bg-teal-100 rounded-full p-3">
                    <i class="fas fa-tasks text-teal-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Completed</p>
                    <p class="text-3xl font-bold text-green-600 mt-2"><?php echo intval($overall_stats['completed_count'] ?? 0); ?></p>
                    <p class="text-xs text-gray-500 mt-1">
                        <?php echo $overall_stats['total_tasks'] > 0 ? round((($overall_stats['completed_count'] ?? 0) / $overall_stats['total_tasks']) * 100, 1) : 0; ?>% completion rate
                    </p>
                </div>
                <div class="bg-green-100 rounded-full p-3">
                    <i class="fas fa-check-circle text-green-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">In Progress</p>
                    <p class="text-3xl font-bold text-blue-600 mt-2"><?php echo intval($overall_stats['in_progress_count'] ?? ($overall_stats['total_tasks'] - ($overall_stats['completed_count'] ?? 0))); ?></p>
                </div>
                <div class="bg-blue-100 rounded-full p-3">
                    <i class="fas fa-spinner text-blue-600 text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Overdue</p>
                    <p class="text-3xl font-bold text-red-600 mt-2"><?php echo intval($overall_stats['overdue_count']); ?></p>
                </div>
                <div class="bg-red-100 rounded-full p-3">
                    <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Breakdown -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Tasks by Status (dynamic statuses) -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Tasks by Status</h3>
            <div class="space-y-4">
                <?php
                $total = intval($overall_stats['total_tasks']);
                foreach ($report_statuses as $status):
                    $status_key = strtolower(str_replace(' ', '_', $status['name']));
                    $count = intval($overall_stats[$status_key . '_count'] ?? 0);
                    $percentage = $total > 0 ? ($count / $total) * 100 : 0;
                    $color = !empty($status['color']) ? $status['color'] : '#6c757d';
                ?>
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full shrink-0" style="background: <?php echo htmlspecialchars($color); ?>;"></span>
                                <span class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($status['name']); ?></span>
                            </div>
                            <span class="text-sm font-semibold text-gray-900"><?php echo $count; ?></span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="h-2 rounded-full" style="background: <?php echo htmlspecialchars($color); ?>; width: <?php echo $percentage; ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Tasks by Priority -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Tasks by Priority</h3>
            <div class="space-y-4">
                <?php
                $priorities = [
                    ['name' => 'High', 'count' => intval($overall_stats['high_priority']), 'color' => '#ef4444', 'icon' => 'fa-exclamation-circle'],
                    ['name' => 'Medium', 'count' => intval($overall_stats['medium_priority']), 'color' => '#f59e0b', 'icon' => 'fa-exclamation-triangle'],
                    ['name' => 'Low', 'count' => intval($overall_stats['low_priority']), 'color' => '#10b981', 'icon' => 'fa-check-circle']
                ];
                foreach ($priorities as $priority):
                    $percentage = $total > 0 ? ($priority['count'] / $total) * 100 : 0;
                ?>
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <i class="fas <?php echo $priority['icon']; ?>" style="color: <?php echo $priority['color']; ?>;"></i>
                                <span class="text-sm font-medium text-gray-700"><?php echo $priority['name']; ?></span>
                            </div>
                            <span class="text-sm font-semibold text-gray-900"><?php echo $priority['count']; ?></span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="h-2 rounded-full" style="background: <?php echo $priority['color']; ?>; width: <?php echo $percentage; ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Tasks by Project (dynamic status columns) -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Tasks by Project</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-200">
                        <th class="text-left py-3 px-4 text-sm font-semibold text-gray-700">Project</th>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700">Total</th>
                        <?php foreach ($report_statuses as $st): ?>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700"><?php echo htmlspecialchars($st['name']); ?></th>
                        <?php endforeach; ?>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700">Progress</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $status_col_count = count($report_statuses);
                    $tasks_by_project_colspan = 2 + $status_col_count + 1;
                    if (empty($projects_stats)): ?>
                        <tr>
                            <td colspan="<?php echo $tasks_by_project_colspan; ?>" class="text-center py-8 text-gray-500">No project data available</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($projects_stats as $project):
                            $completed = intval($project['completed_count'] ?? 0);
                            $progress = $project['task_count'] > 0 ? ($completed / $project['task_count']) * 100 : 0;
                        ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50">
                                <td class="py-3 px-4">
                                    <a href="tasks?project_id=<?php echo $project['id']; ?>" class="text-sm font-medium text-teal-600 hover:text-teal-700">
                                        <?php echo htmlspecialchars($project['project_name']); ?>
                                    </a>
                                </td>
                                <td class="text-center py-3 px-4 text-sm text-gray-900 font-semibold"><?php echo $project['task_count']; ?></td>
                                <?php foreach ($report_statuses as $st):
                                    $sk = strtolower(str_replace(' ', '_', $st['name']));
                                    $val = intval($project[$sk . '_count'] ?? 0);
                                ?>
                                <td class="text-center py-3 px-4 text-sm text-gray-600"><?php echo $val; ?></td>
                                <?php endforeach; ?>
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 bg-gray-200 rounded-full h-2">
                                            <div class="bg-teal-600 h-2 rounded-full" style="width: <?php echo $progress; ?>%;"></div>
                                        </div>
                                        <span class="text-xs text-gray-600 w-12 text-right"><?php echo round($progress, 0); ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tasks by Assignee (dynamic status columns) -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Tasks by Assignee</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-200">
                        <th class="text-left py-3 px-4 text-sm font-semibold text-gray-700">Assignee</th>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700">Total</th>
                        <?php foreach ($report_statuses as $st): ?>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700"><?php echo htmlspecialchars($st['name']); ?></th>
                        <?php endforeach; ?>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700">Overdue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $assignee_colspan = 2 + count($report_statuses) + 1;
                    if (empty($assignees_stats)): ?>
                        <tr>
                            <td colspan="<?php echo $assignee_colspan; ?>" class="text-center py-8 text-gray-500">No assignee data available</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($assignees_stats as $assignee): ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50">
                                <td class="py-3 px-4 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($assignee['full_name']); ?></td>
                                <td class="text-center py-3 px-4 text-sm text-gray-900 font-semibold"><?php echo $assignee['task_count']; ?></td>
                                <?php foreach ($report_statuses as $st):
                                    $sk = strtolower(str_replace(' ', '_', $st['name']));
                                    $val = intval($assignee[$sk . '_count'] ?? 0);
                                ?>
                                <td class="text-center py-3 px-4 text-sm text-gray-600"><?php echo $val; ?></td>
                                <?php endforeach; ?>
                                <td class="text-center py-3 px-4">
                                    <?php if (($assignee['overdue_count'] ?? 0) > 0): ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                                            <?php echo $assignee['overdue_count']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-sm text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tasks by Type -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Tasks by Type</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-bug text-red-600"></i>
                        <span class="text-sm font-medium text-gray-700">Bugs</span>
                    </div>
                    <span class="text-lg font-bold text-gray-900"><?php echo intval($overall_stats['bug_count']); ?></span>
                </div>
            </div>
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-tasks text-teal-600"></i>
                        <span class="text-sm font-medium text-gray-700">Tasks</span>
                    </div>
                    <span class="text-lg font-bold text-gray-900"><?php echo intval($overall_stats['task_count']); ?></span>
                </div>
            </div>
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-lightbulb text-yellow-600"></i>
                        <span class="text-sm font-medium text-gray-700">Improvements</span>
                    </div>
                    <span class="text-lg font-bold text-gray-900"><?php echo intval($overall_stats['improvement_count']); ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
