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

// Get date range from URL or default to last 30 days
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Validate dates
if (!strtotime($start_date) || !strtotime($end_date)) {
    $start_date = date('Y-m-d', strtotime('-30 days'));
    $end_date = date('Y-m-d');
}

// Build base where clause for role-based filtering
$where_conditions = [];
$query_params = [];
$query_types = '';

// Role-based filtering
if (isSuperAdmin()) {
    // Super Admin sees all tasks
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

// Overall Statistics
$stats_query = "SELECT COUNT(*) as total_tasks, " .
    "SUM(CASE WHEN t.status = 'To Do' THEN 1 ELSE 0 END) as todo_count, " .
    "SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as inprogress_count, " .
    "SUM(CASE WHEN t.status = 'Done' THEN 1 ELSE 0 END) as done_count, " .
    "SUM(CASE WHEN t.`priority` = 'High' THEN 1 ELSE 0 END) as `high_priority`, " .
    "SUM(CASE WHEN t.`priority` = 'Medium' THEN 1 ELSE 0 END) as `medium_priority`, " .
    "SUM(CASE WHEN t.`priority` = 'Low' THEN 1 ELSE 0 END) as `low_priority`, " .
    "SUM(CASE WHEN t.due_date < CURDATE() AND t.status != 'Done' THEN 1 ELSE 0 END) as overdue_count, " .
    "SUM(CASE WHEN t.type = 'Bug' THEN 1 ELSE 0 END) as bug_count, " .
    "SUM(CASE WHEN t.type = 'Task' THEN 1 ELSE 0 END) as task_count, " .
    "SUM(CASE WHEN t.type = 'Improvement' THEN 1 ELSE 0 END) as improvement_count " .
    "FROM tasks t " .
    "LEFT JOIN projects p ON t.project_id = p.id";

if (!empty($where_conditions)) {
    $stats_query .= " WHERE " . implode(" AND ", $where_conditions);
}

if (!empty($query_params)) {
    $stats_stmt = $conn->prepare($stats_query);
    if ($stats_stmt) {
        $stats_stmt->bind_param($query_types, ...$query_params);
        $stats_stmt->execute();
        $stats_result = $stats_stmt->get_result();
        $overall_stats = $stats_result->fetch_assoc();
        $stats_stmt->close();
    } else {
        $overall_stats = ['total_tasks' => 0, 'todo_count' => 0, 'inprogress_count' => 0, 'done_count' => 0, 
                         'high_priority' => 0, 'medium_priority' => 0, 'low_priority' => 0, 
                         'overdue_count' => 0, 'bug_count' => 0, 'task_count' => 0, 'improvement_count' => 0];
    }
} else {
    $stats_result = $conn->query($stats_query);
    if ($stats_result) {
        $overall_stats = $stats_result->fetch_assoc();
    } else {
        $overall_stats = ['total_tasks' => 0, 'todo_count' => 0, 'inprogress_count' => 0, 'done_count' => 0, 
                         'high_priority' => 0, 'medium_priority' => 0, 'low_priority' => 0, 
                         'overdue_count' => 0, 'bug_count' => 0, 'task_count' => 0, 'improvement_count' => 0];
    }
}

// Tasks by Project
$projects_where = [];
$projects_params = [];
$projects_types = '';

if (isSuperAdmin()) {
    // Super Admin sees all projects
} else if (isOrgAdmin()) {
    $org_id = getOrganizationId();
    $projects_where[] = "p.organization_id = ?";
    $projects_params[] = $org_id;
    $projects_types .= 'i';
} else {
    // Project Manager sees projects in their organization
    $org_id = getOrganizationId();
    if ($org_id) {
        $projects_where[] = "p.organization_id = ?";
        $projects_params[] = $org_id;
        $projects_types .= 'i';
    }
}

$projects_where_clause = !empty($projects_where) ? "WHERE " . implode(" AND ", $projects_where) : "";

$projects_query = "
    SELECT 
        p.id,
        p.name as project_name,
        COUNT(t.id) as task_count,
        SUM(CASE WHEN t.status = 'Done' THEN 1 ELSE 0 END) as done_count,
        SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as inprogress_count,
        SUM(CASE WHEN t.status = 'To Do' THEN 1 ELSE 0 END) as todo_count
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

// Tasks by Assignee
$assignees_where = [];
$assignees_params = [];
$assignees_types = '';

if (isSuperAdmin()) {
    // Super Admin sees all assignees
} else if (isOrgAdmin()) {
    $org_id = getOrganizationId();
    $assignees_where[] = "p.organization_id = ?";
    $assignees_params[] = $org_id;
    $assignees_types .= 'i';
} else {
    // Project Manager sees assignees in their organization
    $org_id = getOrganizationId();
    if ($org_id) {
        $assignees_where[] = "p.organization_id = ?";
        $assignees_params[] = $org_id;
        $assignees_types .= 'i';
    }
}

$assignees_where_clause = !empty($assignees_where) ? "WHERE " . implode(" AND ", $assignees_where) : "";

$assignees_query = "
    SELECT 
        u.id,
        u.full_name,
        COUNT(t.id) as task_count,
        SUM(CASE WHEN t.status = 'Done' THEN 1 ELSE 0 END) as done_count,
        SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as inprogress_count,
        SUM(CASE WHEN t.status = 'To Do' THEN 1 ELSE 0 END) as todo_count,
        SUM(CASE WHEN t.due_date < CURDATE() AND t.status != 'Done' THEN 1 ELSE 0 END) as overdue_count
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

// Tasks created in date range
$date_range_where = array_merge($where_conditions, ["DATE(t.created_at) >= ?", "DATE(t.created_at) <= ?"]);
$date_range_params = array_merge($query_params, [$start_date, $end_date]);
$date_range_types = $query_types . 'ss';

$date_range_query = "
    SELECT 
        DATE(t.created_at) as date,
        COUNT(*) as task_count,
        SUM(CASE WHEN t.status = 'Done' THEN 1 ELSE 0 END) as done_count
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

    <!-- Date Range Filter -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
        <form method="GET" action="" class="flex items-center gap-4 flex-wrap">
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
                    <p class="text-3xl font-bold text-green-600 mt-2"><?php echo intval($overall_stats['done_count']); ?></p>
                    <p class="text-xs text-gray-500 mt-1">
                        <?php echo $overall_stats['total_tasks'] > 0 ? round(($overall_stats['done_count'] / $overall_stats['total_tasks']) * 100, 1) : 0; ?>% completion rate
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
                    <p class="text-3xl font-bold text-blue-600 mt-2"><?php echo intval($overall_stats['inprogress_count']); ?></p>
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
        <!-- Tasks by Status -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Tasks by Status</h3>
            <div class="space-y-4">
                <?php
                $statuses = [
                    ['name' => 'To Do', 'count' => intval($overall_stats['todo_count']), 'color' => '#6b7280', 'icon' => 'fa-clock'],
                    ['name' => 'In Progress', 'count' => intval($overall_stats['inprogress_count']), 'color' => '#3b82f6', 'icon' => 'fa-spinner'],
                    ['name' => 'Done', 'count' => intval($overall_stats['done_count']), 'color' => '#10b981', 'icon' => 'fa-check-circle']
                ];
                $total = $overall_stats['total_tasks'];
                foreach ($statuses as $status):
                    $percentage = $total > 0 ? ($status['count'] / $total) * 100 : 0;
                ?>
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <i class="fas <?php echo $status['icon']; ?>" style="color: <?php echo $status['color']; ?>;"></i>
                                <span class="text-sm font-medium text-gray-700"><?php echo $status['name']; ?></span>
                            </div>
                            <span class="text-sm font-semibold text-gray-900"><?php echo $status['count']; ?></span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="h-2 rounded-full" style="background: <?php echo $status['color']; ?>; width: <?php echo $percentage; ?>%;"></div>
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

    <!-- Tasks by Project -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Tasks by Project</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-200">
                        <th class="text-left py-3 px-4 text-sm font-semibold text-gray-700">Project</th>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700">Total</th>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700">To Do</th>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700">In Progress</th>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700">Done</th>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700">Progress</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($projects_stats)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-8 text-gray-500">No project data available</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($projects_stats as $project): 
                            $progress = $project['task_count'] > 0 ? ($project['done_count'] / $project['task_count']) * 100 : 0;
                        ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50">
                                <td class="py-3 px-4">
                                    <a href="tasks?project_id=<?php echo $project['id']; ?>" class="text-sm font-medium text-teal-600 hover:text-teal-700">
                                        <?php echo htmlspecialchars($project['project_name']); ?>
                                    </a>
                                </td>
                                <td class="text-center py-3 px-4 text-sm text-gray-900 font-semibold"><?php echo $project['task_count']; ?></td>
                                <td class="text-center py-3 px-4 text-sm text-gray-600"><?php echo $project['todo_count']; ?></td>
                                <td class="text-center py-3 px-4 text-sm text-gray-600"><?php echo $project['inprogress_count']; ?></td>
                                <td class="text-center py-3 px-4 text-sm text-green-600 font-semibold"><?php echo $project['done_count']; ?></td>
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

    <!-- Tasks by Assignee -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Tasks by Assignee</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-200">
                        <th class="text-left py-3 px-4 text-sm font-semibold text-gray-700">Assignee</th>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700">Total</th>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700">To Do</th>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700">In Progress</th>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700">Done</th>
                        <th class="text-center py-3 px-4 text-sm font-semibold text-gray-700">Overdue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assignees_stats)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-8 text-gray-500">No assignee data available</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($assignees_stats as $assignee): ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50">
                                <td class="py-3 px-4 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($assignee['full_name']); ?></td>
                                <td class="text-center py-3 px-4 text-sm text-gray-900 font-semibold"><?php echo $assignee['task_count']; ?></td>
                                <td class="text-center py-3 px-4 text-sm text-gray-600"><?php echo $assignee['todo_count']; ?></td>
                                <td class="text-center py-3 px-4 text-sm text-gray-600"><?php echo $assignee['inprogress_count']; ?></td>
                                <td class="text-center py-3 px-4 text-sm text-green-600 font-semibold"><?php echo $assignee['done_count']; ?></td>
                                <td class="text-center py-3 px-4">
                                    <?php if ($assignee['overdue_count'] > 0): ?>
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
</div>

<?php include 'includes/footer.php'; ?>
