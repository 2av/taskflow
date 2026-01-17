<?php
require_once 'config/config.php';
requireLogin();

$page_title = 'Assigned to Me';

$conn = getDBConnection();
$message = '';
$error = '';

// Get current user ID
$user_id = $_SESSION['user_id'];

// Handle quick status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $task_id = intval($_POST['task_id']);
    $new_status = $_POST['status'];
    
    // Verify task is assigned to current user
    $check_task = $conn->query("SELECT * FROM tasks WHERE id = $task_id AND assignee_id = $user_id")->fetch_assoc();
    
    if ($check_task) {
        $old_status = $check_task['status'];
        
        $stmt = $conn->prepare("UPDATE tasks SET status = ? WHERE id = ? AND assignee_id = ?");
        $stmt->bind_param("sii", $new_status, $task_id, $user_id);
        
        if ($stmt->execute()) {
            // Log activity
            $action = "Status changed";
            $stmt2 = $conn->prepare("INSERT INTO activity_logs (task_id, user_id, action, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
            $stmt2->bind_param("iisss", $task_id, $user_id, $action, $old_status, $new_status);
            $stmt2->execute();
            
            $message = 'Status updated successfully';
        } else {
            $error = 'Error updating status';
        }
    } else {
        $error = 'Task not found or not assigned to you';
    }
}

// Get filter values (support multiple selections)
$filter_status = isset($_GET['status']) ? (is_array($_GET['status']) ? $_GET['status'] : [$_GET['status']]) : [];
$filter_status = array_filter($filter_status); // Remove empty values

$filter_priority = isset($_GET['priority']) ? (is_array($_GET['priority']) ? $_GET['priority'] : [$_GET['priority']]) : [];
$filter_priority = array_filter($filter_priority); // Remove empty values

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query for tasks assigned to current user
$where_conditions = ["t.assignee_id = $user_id"];
$query_params = [];
$query_types = '';

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

if (!empty($search)) {
    $where_conditions[] = "t.title LIKE ?";
    $query_params[] = "%$search%";
    $query_types .= 's';
}

// Get task statistics BEFORE filtering (for badge counts)
$stats_query = "
    SELECT 
        COUNT(*) as total_tasks,
        SUM(CASE WHEN t.status = 'To Do' THEN 1 ELSE 0 END) as todo_count,
        SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as inprogress_count,
        SUM(CASE WHEN t.status = 'Done' THEN 1 ELSE 0 END) as done_count
    FROM tasks t
    WHERE t.assignee_id = $user_id
";
$stats_result = $conn->query($stats_query);
$task_stats = $stats_result->fetch_assoc();
$stats_result->free();

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get tasks assigned to current user
$query = "
    SELECT t.*, p.name as project_name, u.full_name as assignee_name, u2.full_name as creator_name
    FROM tasks t
    LEFT JOIN projects p ON t.project_id = p.id
    LEFT JOIN users u ON t.assignee_id = u.id
    LEFT JOIN users u2 ON t.created_by = u2.id
    $where_clause
    ORDER BY 
        CASE 
            WHEN t.status = 'Done' THEN 3
            WHEN t.due_date < CURDATE() AND t.status != 'Done' THEN 1
            ELSE 2
        END,
        t.due_date ASC,
        t.created_at DESC
";

if (!empty($query_params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($query_types, ...$query_params);
    $stmt->execute();
    $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $tasks = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
}

// Helper function to get initials
function getInitials($full_name) {
    $full_name = trim($full_name);
    $words = explode(' ', $full_name);
    $words = array_filter($words, function($word) { return !empty(trim($word)); });
    $words = array_values($words);
    
    if (count($words) >= 2) {
        return strtoupper(substr($words[0], 0, 1) . substr($words[count($words) - 1], 0, 1));
    } else {
        return strtoupper(substr($full_name, 0, 2));
    }
}

// Check if this is an AJAX request
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    // Return JSON response for AJAX
    header('Content-Type: application/json');
    
    $tasks_html = '';
    if (empty($tasks)) {
        $tasks_html = '<tr><td colspan="7" class="px-6 py-12 text-center text-gray-500"><i class="fas fa-tasks text-4xl text-gray-300 mb-3 block"></i><p>No tasks assigned to you</p></td></tr>';
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
            
            $tasks_html .= '<tr class="hover:bg-blue-50 transition-colors" style="transition: background-color 0.2s ease;">';
            $tasks_html .= '<td class="px-6 py-4 whitespace-nowrap" style="cursor: pointer;" onclick="window.location.href=\'task_view?id=' . $task['id'] . '\'">';
            $tasks_html .= '<div class="flex items-center gap-2">';
            $tasks_html .= '<span class="font-semibold text-gray-900">' . htmlspecialchars($task['task_id']) . '</span>';
            $tasks_html .= '<i class="fas ' . $type_icon . ' text-sm" style="color: ' . $type_color . ';" title="' . htmlspecialchars($task['type']) . '"></i>';
            $tasks_html .= '</div></td>';
            $tasks_html .= '<td class="px-6 py-4" style="cursor: pointer;" onclick="window.location.href=\'task_view?id=' . $task['id'] . '\'">';
            $tasks_html .= '<div class="text-sm text-gray-900 font-medium">' . htmlspecialchars($task['title']) . '</div></td>';
            $tasks_html .= '<td class="px-6 py-4"><div class="text-sm text-gray-600">' . htmlspecialchars($task['project_name'] ?? '-') . '</div></td>';
            $tasks_html .= '<td class="px-6 py-4 whitespace-nowrap text-center">';
            $tasks_html .= '<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full ' . $priority_style['bg'] . ' ' . $priority_style['text'] . '">';
            $tasks_html .= '<i class="fas ' . $priority_style['icon'] . ' ' . $priority_style['icon_color'] . ' text-xs"></i>';
            $tasks_html .= htmlspecialchars($task['priority']) . '</span></td>';
            $tasks_html .= '<td class="px-6 py-4 whitespace-nowrap text-center">';
            $tasks_html .= '<form method="POST" action="" style="display: inline-block; margin: 0;">';
            $tasks_html .= '<input type="hidden" name="task_id" value="' . $task['id'] . '">';
            $tasks_html .= '<select name="status" onchange="this.form.submit()" class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full ' . $status_style['bg'] . ' ' . $status_style['text'] . ' border-0 cursor-pointer" style="background: ' . ($status_style['bg'] == 'bg-green-100' ? '#d1fae5' : ($status_style['bg'] == 'bg-blue-100' ? '#dbeafe' : '#f3f4f6')) . '; color: ' . ($status_style['text'] == 'text-green-800' ? '#065f46' : ($status_style['text'] == 'text-blue-800' ? '#1e40af' : '#374151')) . '; padding: 4px 12px; font-size: 12px;">';
            $tasks_html .= '<option value="To Do"' . ($task['status'] == 'To Do' ? ' selected' : '') . '>To Do</option>';
            $tasks_html .= '<option value="In Progress"' . ($task['status'] == 'In Progress' ? ' selected' : '') . '>In Progress</option>';
            $tasks_html .= '<option value="Done"' . ($task['status'] == 'Done' ? ' selected' : '') . '>Done</option>';
            $tasks_html .= '</select>';
            $tasks_html .= '<input type="hidden" name="update_status" value="1"></form></td>';
            $tasks_html .= '<td class="px-6 py-4 whitespace-nowrap text-center">';
            $tasks_html .= '<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full ' . $due_date_style['bg'] . ' ' . $due_date_style['text'] . '">';
            $tasks_html .= '<i class="fas ' . $due_date_style['icon'] . ' ' . $due_date_style['icon_color'] . ' text-xs"></i>';
            $tasks_html .= htmlspecialchars($due_date_display) . '</span></td>';
            $tasks_html .= '<td class="px-6 py-4 whitespace-nowrap text-center">';
            $tasks_html .= '<a href="task_view?id=' . $task['id'] . '" class="btn btn-sm btn-primary" title="View" style="padding: 6px 10px; background: #14b8a6; color: white; border: none; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;">';
            $tasks_html .= '<i class="fas fa-eye"></i></a></td>';
            $tasks_html .= '</tr>';
        }
    }
    
    echo json_encode(['success' => true, 'html' => $tasks_html, 'count' => count($tasks)]);
    $conn->close();
    exit();
}

$conn->close();

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
                My Tasks
            </h1>
        </div>
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

    <!-- Task Status Filters -->
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
                $filter_url = 'my_tasks?';
                $url_params = [];
                // Set status filter (or remove if All is selected)
                if ($status != 'All') {
                    $url_params['status'] = $status;
                }
                // Preserve priority filter if exists
                if (!empty($filter_priority)) {
                    $url_params['priority'] = $filter_priority;
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-white uppercase tracking-wider">Project</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-white uppercase tracking-wider" style="width: 120px;">Priority</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-white uppercase tracking-wider" style="width: 140px;">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-white uppercase tracking-wider" style="width: 150px;">Due Date</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-white uppercase tracking-wider" style="width: 80px;">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($tasks)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-tasks text-4xl text-gray-300 mb-3 block"></i>
                                <p>No tasks assigned to you</p>
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
                            <tr class="hover:bg-blue-50 transition-colors" style="transition: background-color 0.2s ease;">
                                <td class="px-6 py-4 whitespace-nowrap" style="cursor: pointer;" onclick="window.location.href='task_view?id=<?php echo $task['id']; ?>'">
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($task['task_id']); ?></span>
                                        <i class="fas <?php echo $type_icon; ?> text-sm" style="color: <?php echo $type_color; ?>;" title="<?php echo htmlspecialchars($task['type']); ?>"></i>
                                    </div>
                                </td>
                                <td class="px-6 py-4" style="cursor: pointer;" onclick="window.location.href='task_view?id=<?php echo $task['id']; ?>'">
                                    <div class="text-sm text-gray-900 font-medium"><?php echo htmlspecialchars($task['title']); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-600"><?php echo htmlspecialchars($task['project_name'] ?? '-'); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full <?php echo $priority_style['bg'] . ' ' . $priority_style['text']; ?>">
                                        <i class="fas <?php echo $priority_style['icon']; ?> <?php echo $priority_style['icon_color']; ?> text-xs"></i>
                                        <?php echo htmlspecialchars($task['priority']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <form method="POST" action="" style="display: inline-block; margin: 0;">
                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                        <select name="status" onchange="this.form.submit()" 
                                                style="background: <?php echo $status_style['bg'] == 'bg-green-100' ? '#d1fae5' : ($status_style['bg'] == 'bg-blue-100' ? '#dbeafe' : '#f3f4f6'); ?>; color: <?php echo $status_style['text'] == 'text-green-800' ? '#065f46' : ($status_style['text'] == 'text-blue-800' ? '#1e40af' : '#374151'); ?>; padding: 4px 12px; font-size: 12px; border: none; border-radius: 9999px; cursor: pointer; font-weight: 500; outline: none;">
                                            <option value="To Do" <?php echo $task['status'] == 'To Do' ? 'selected' : ''; ?>>To Do</option>
                                            <option value="In Progress" <?php echo $task['status'] == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="Done" <?php echo $task['status'] == 'Done' ? 'selected' : ''; ?>>Done</option>
                                        </select>
                                        <input type="hidden" name="update_status" value="1">
                                    </form>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full <?php echo $due_date_style['bg'] . ' ' . $due_date_style['text']; ?>">
                                        <i class="fas <?php echo $due_date_style['icon']; ?> <?php echo $due_date_style['icon_color']; ?> text-xs"></i>
                                        <?php echo htmlspecialchars($due_date_display); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <a href="task_view?id=<?php echo $task['id']; ?>" class="btn btn-sm btn-primary" title="View" style="padding: 6px 10px; background: #14b8a6; color: white; border: none; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
