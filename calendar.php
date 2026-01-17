<?php
require_once 'config/config.php';
requireLogin();
requireActiveSubscription();

$page_title = 'Calendar';

$conn = getDBConnection();

// Get current month/year from URL or use current
$current_month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$current_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Validate month and year
if ($current_month < 1 || $current_month > 12) {
    $current_month = date('n');
}
if ($current_year < 2020 || $current_year > 2100) {
    $current_year = date('Y');
}

// Calculate previous and next month/year
$prev_month = $current_month - 1;
$prev_year = $current_year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year--;
}

$next_month = $current_month + 1;
$next_year = $current_year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year++;
}

// Get first day of month and number of days
$first_day = mktime(0, 0, 0, $current_month, 1, $current_year);
$days_in_month = date('t', $first_day);
$day_of_week = date('w', $first_day); // 0 = Sunday, 6 = Saturday

// Build query for tasks with due dates in current month
$where_conditions = [];
$query_params = [];
$query_types = '';

// Date range for current month
$start_date = date('Y-m-01', $first_day);
$end_date = date('Y-m-t', $first_day);

$where_conditions[] = "t.due_date >= ? AND t.due_date <= ?";
$query_params[] = $start_date;
$query_params[] = $end_date;
$query_types .= 'ss';

// Role-based filtering
if (isSuperAdmin()) {
    // Super Admin sees all tasks
} else if (isOrgAdmin()) {
    $org_id = getOrganizationId();
    $where_conditions[] = "p.organization_id = ?";
    $query_params[] = $org_id;
    $query_types .= 'i';
} else if (!isProjectManager()) {
    // Team members only see their assigned tasks
    $user_id = $_SESSION['user_id'];
    $where_conditions[] = "(t.assignee_id = $user_id OR t.project_id IN (SELECT project_id FROM project_users WHERE user_id = $user_id))";
} else {
    // Project Manager sees tasks in their organization
    $org_id = getOrganizationId();
    if ($org_id) {
        $where_conditions[] = "p.organization_id = ?";
        $query_params[] = $org_id;
        $query_types .= 'i';
    }
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get tasks for the month
$query = "
    SELECT t.*, p.name as project_name, u.full_name as assignee_name,
           DATE(t.due_date) as task_date
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN users u ON t.assignee_id = u.id
    $where_clause
    ORDER BY t.due_date ASC, t.priority DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param($query_types, ...$query_params);
$stmt->execute();
$result = $stmt->get_result();
$tasks = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Group tasks by date
$tasks_by_date = [];
foreach ($tasks as $task) {
    $date = $task['task_date'];
    if (!isset($tasks_by_date[$date])) {
        $tasks_by_date[$date] = [];
    }
    $tasks_by_date[$date][] = $task;
}

$conn->close();

// Helper function to get status color
function getStatusColor($status) {
    $status_lower = strtolower(str_replace(' ', '-', $status));
    $colors = [
        'done' => '#10b981',
        'in-progress' => '#3b82f6',
        'to-do' => '#6b7280'
    ];
    return $colors[$status_lower] ?? '#6b7280';
}

// Helper function to get priority color
function getPriorityColor($priority) {
    $priority_lower = strtolower($priority);
    $colors = [
        'high' => '#ef4444',
        'medium' => '#f59e0b',
        'low' => '#10b981'
    ];
    return $colors[$priority_lower] ?? '#6b7280';
}

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
                    Calendar
                </h1>
                <p class="text-gray-500 text-sm mt-1">View tasks by due date</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="calendar?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" 
               class="bg-white hover:bg-gray-50 text-gray-700 px-3 py-2 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center shadow-sm hover:shadow-md" 
               title="Previous Month">
                <i class="fas fa-chevron-left"></i>
            </a>
            <a href="calendar?month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>" 
               class="bg-teal-600 hover:bg-teal-700 text-white px-3 py-2 rounded-lg font-medium transition-colors duration-200 shadow-sm hover:shadow-md" 
               title="Current Month">
                Today
            </a>
            <a href="calendar?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" 
               class="bg-white hover:bg-gray-50 text-gray-700 px-3 py-2 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center shadow-sm hover:shadow-md" 
               title="Next Month">
                <i class="fas fa-chevron-right"></i>
            </a>
        </div>
    </div>

    <!-- Month/Year Display -->
    <div class="mb-6 text-center">
        <h2 class="text-2xl font-bold text-gray-900">
            <?php echo date('F Y', $first_day); ?>
        </h2>
    </div>

    <!-- Calendar Grid -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="grid grid-cols-7 gap-px bg-gray-200">
            <!-- Day Headers -->
            <?php 
            $day_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            foreach ($day_names as $day): 
            ?>
                <div class="bg-gradient-to-r from-teal-600 to-teal-700 text-white p-3 text-center font-semibold text-sm">
                    <?php echo $day; ?>
                </div>
            <?php endforeach; ?>

            <!-- Calendar Days -->
            <?php
            // Fill empty cells before first day
            for ($i = 0; $i < $day_of_week; $i++):
            ?>
                <div class="bg-gray-50 min-h-[120px] p-2"></div>
            <?php endfor; ?>

            <!-- Days of the month -->
            <?php for ($day = 1; $day <= $days_in_month; $day++): 
                $current_date = date('Y-m-d', mktime(0, 0, 0, $current_month, $day, $current_year));
                $is_today = ($current_date == date('Y-m-d'));
                $day_tasks = isset($tasks_by_date[$current_date]) ? $tasks_by_date[$current_date] : [];
                $is_past = ($current_date < date('Y-m-d') && !$is_today);
            ?>
                <div class="bg-white min-h-[120px] p-2 border-b border-r border-gray-100 <?php echo $is_today ? 'bg-teal-50 border-teal-300' : ''; ?> <?php echo $is_past ? 'opacity-60' : ''; ?>">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-semibold <?php echo $is_today ? 'text-teal-700 bg-teal-200 rounded-full w-7 h-7 flex items-center justify-center' : 'text-gray-700'; ?>">
                            <?php echo $day; ?>
                        </span>
                        <?php if (count($day_tasks) > 0): ?>
                            <span class="text-xs font-medium text-gray-600 bg-gray-200 px-2 py-0.5 rounded-full">
                                <?php echo count($day_tasks); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="space-y-1">
                        <?php foreach (array_slice($day_tasks, 0, 3) as $task): 
                            $status_color = getStatusColor($task['status']);
                            $priority_color = getPriorityColor($task['priority']);
                            $is_overdue = ($task['due_date'] < date('Y-m-d') && $task['status'] != 'Done');
                        ?>
                            <a href="task_view?id=<?php echo $task['id']; ?>" 
                               class="block text-xs p-1.5 rounded border-l-2 hover:shadow-sm transition-all"
                               style="border-left-color: <?php echo $priority_color; ?>; background: <?php echo $is_overdue ? '#fef2f2' : '#f9fafb'; ?>;"
                               title="<?php echo htmlspecialchars($task['task_id'] . ': ' . $task['title']); ?>">
                                <div class="flex items-center gap-1 mb-0.5">
                                    <span class="font-semibold text-gray-900 truncate" style="font-size: 10px;">
                                        <?php echo htmlspecialchars($task['task_id']); ?>
                                    </span>
                                    <span class="w-1.5 h-1.5 rounded-full" style="background: <?php echo $status_color; ?>;"></span>
                                </div>
                                <div class="text-gray-600 truncate" style="font-size: 9px;">
                                    <?php echo htmlspecialchars(strlen($task['title']) > 20 ? substr($task['title'], 0, 20) . '...' : $task['title']); ?>
                                </div>
                                <?php if ($is_overdue): ?>
                                    <div class="text-red-600 text-xs mt-0.5">
                                        <i class="fas fa-exclamation-triangle" style="font-size: 8px;"></i> Overdue
                                    </div>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                        <?php if (count($day_tasks) > 3): ?>
                            <div class="text-xs text-gray-500 text-center pt-1">
                                +<?php echo count($day_tasks) - 3; ?> more
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endfor; ?>

            <!-- Fill remaining cells -->
            <?php
            $total_cells = $day_of_week + $days_in_month;
            $remaining_cells = 7 - ($total_cells % 7);
            if ($remaining_cells < 7) {
                for ($i = 0; $i < $remaining_cells; $i++):
            ?>
                <div class="bg-gray-50 min-h-[120px] p-2"></div>
            <?php 
                endfor;
            }
            ?>
        </div>
    </div>

    <!-- Legend -->
    <div class="mt-6 bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <h3 class="text-sm font-semibold text-gray-900 mb-3">Legend</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <p class="text-xs font-medium text-gray-700 mb-2">Priority</p>
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-3 h-3 rounded-full" style="background: #ef4444;"></span>
                    <span>High</span>
                    <span class="w-3 h-3 rounded-full ml-2" style="background: #f59e0b;"></span>
                    <span>Medium</span>
                    <span class="w-3 h-3 rounded-full ml-2" style="background: #10b981;"></span>
                    <span>Low</span>
                </div>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-700 mb-2">Status</p>
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-2 h-2 rounded-full" style="background: #10b981;"></span>
                    <span>Done</span>
                    <span class="w-2 h-2 rounded-full ml-2" style="background: #3b82f6;"></span>
                    <span>In Progress</span>
                    <span class="w-2 h-2 rounded-full ml-2" style="background: #6b7280;"></span>
                    <span>To Do</span>
                </div>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-700 mb-2">Notes</p>
                <div class="text-xs text-gray-600">
                    <span class="bg-teal-50 px-2 py-1 rounded">Today</span>
                    <span class="ml-2 text-red-600"><i class="fas fa-exclamation-triangle"></i> Overdue</span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
