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

// Get statistics
$total_tasks = count($tasks);
$overdue_tasks = 0;
$status_counts = ['To Do' => 0, 'In Progress' => 0, 'Done' => 0];

foreach ($tasks as $task) {
    if ($task['due_date'] && strtotime($task['due_date']) < time() && $task['status'] != 'Done') {
        $overdue_tasks++;
    }
    if (isset($status_counts[$task['status']])) {
        $status_counts[$task['status']]++;
    }
}

// Check if this is an AJAX request
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    // Return JSON response for AJAX
    header('Content-Type: application/json');
    
    $tasks_html = '';
    if (empty($tasks)) {
        $tasks_html = '<tr><td colspan="8" style="text-align: center; color: #999;">No tasks assigned to you</td></tr>';
    } else {
        foreach ($tasks as $task) {
            $due_date = formatDate($task['due_date']);
            $due_date_html = $due_date;
            $row_style = '';
            if ($task['due_date'] && strtotime($task['due_date']) < time() && $task['status'] != 'Done') {
                $due_date_html = '<span style="color: #e74c3c; font-weight: 600;">' . $due_date . ' ⚠</span>';
                $row_style = 'style="background-color: #fff5f5;"';
            }
            
            $tasks_html .= '<tr ' . $row_style . '>';
            $tasks_html .= '<td><strong>' . htmlspecialchars($task['task_id']) . '</strong></td>';
            $tasks_html .= '<td>' . htmlspecialchars($task['title']) . '</td>';
            $tasks_html .= '<td>' . htmlspecialchars($task['project_name'] ?? '-') . '</td>';
            $tasks_html .= '<td>' . htmlspecialchars($task['type']) . '</td>';
            $tasks_html .= '<td><span class="badge priority-' . strtolower($task['priority']) . '">' . htmlspecialchars($task['priority']) . '</span></td>';
            $tasks_html .= '<td>';
            $tasks_html .= '<form method="POST" action="" style="display: inline-block;">';
            $tasks_html .= '<input type="hidden" name="task_id" value="' . $task['id'] . '">';
            $tasks_html .= '<select name="status" onchange="this.form.submit()" style="padding: 4px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px;">';
            $tasks_html .= '<option value="To Do"' . ($task['status'] == 'To Do' ? ' selected' : '') . '>To Do</option>';
            $tasks_html .= '<option value="In Progress"' . ($task['status'] == 'In Progress' ? ' selected' : '') . '>In Progress</option>';
            $tasks_html .= '<option value="Done"' . ($task['status'] == 'Done' ? ' selected' : '') . '>Done</option>';
            $tasks_html .= '</select>';
            $tasks_html .= '<input type="hidden" name="update_status" value="1">';
            $tasks_html .= '</form>';
            $tasks_html .= '</td>';
            $tasks_html .= '<td>' . $due_date_html . '</td>';
            $tasks_html .= '<td><a href="task_view.php?id=' . $task['id'] . '" class="btn btn-sm btn-primary" title="View"><i class="fas fa-eye"></i></a></td>';
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

<div class="page-header">
    <h1 class="page-title">Assigned to Me</h1>
    <button class="btn btn-secondary" id="toggleFiltersBtn" title="Toggle Filters"><i class="fas fa-filter"></i></button>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $total_tasks; ?></div>
        <div class="stat-label">Total Tasks</div>
    </div>
    <div class="stat-card danger">
        <div class="stat-value"><?php echo $overdue_tasks; ?></div>
        <div class="stat-label">Overdue Tasks</div>
    </div>
    <div class="stat-card warning">
        <div class="stat-value"><?php echo $status_counts['To Do']; ?></div>
        <div class="stat-label">To Do</div>
    </div>
    <div class="stat-card info">
        <div class="stat-value"><?php echo $status_counts['In Progress']; ?></div>
        <div class="stat-label">In Progress</div>
    </div>
    <div class="stat-card success">
        <div class="stat-value"><?php echo $status_counts['Done']; ?></div>
        <div class="stat-label">Done</div>
    </div>
</div>

<!-- Search and Filters -->
<div class="search-filters" id="searchFilters" style="display: none;">
    <form method="GET" action="" id="filterForm">
        <div class="filter-row" style="grid-template-columns: 2fr 1fr 1fr;">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" id="searchInput" placeholder="Search by title..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label>Status</label>
                <?php 
                $status_options = ['To Do' => 'To Do', 'In Progress' => 'In Progress', 'Done' => 'Done'];
                echo renderCustomMultiselect('status', $status_options, $filter_status);
                ?>
            </div>
            <div class="filter-group">
                <label>Priority</label>
                <?php 
                $priority_options = ['Low' => 'Low', 'Medium' => 'Medium', 'High' => 'High'];
                echo renderCustomMultiselect('priority', $priority_options, $filter_priority);
                ?>
            </div>
        </div>
    </form>
</div>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Task ID</th>
                <th>Title</th>
                <th>Project</th>
                <th>Type</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Due Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tasks)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; color: #999;">No tasks assigned to you</td>
                </tr>
            <?php else: ?>
                <?php foreach ($tasks as $task): ?>
                    <tr style="<?php echo ($task['due_date'] && strtotime($task['due_date']) < time() && $task['status'] != 'Done') ? 'background-color: #fff5f5;' : ''; ?>">
                        <td><strong><?php echo htmlspecialchars($task['task_id']); ?></strong></td>
                        <td><?php echo htmlspecialchars($task['title']); ?></td>
                        <td><?php echo htmlspecialchars($task['project_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($task['type']); ?></td>
                        <td>
                            <span class="badge priority-<?php echo strtolower($task['priority']); ?>">
                                <?php echo htmlspecialchars($task['priority']); ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" action="" style="display: inline-block;">
                                <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                <select name="status" onchange="this.form.submit()" style="padding: 4px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px;">
                                    <option value="To Do" <?php echo $task['status'] == 'To Do' ? 'selected' : ''; ?>>To Do</option>
                                    <option value="In Progress" <?php echo $task['status'] == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Done" <?php echo $task['status'] == 'Done' ? 'selected' : ''; ?>>Done</option>
                                </select>
                                <input type="hidden" name="update_status" value="1">
                            </form>
                        </td>
                        <td>
                            <?php 
                            $due_date = formatDate($task['due_date']);
                            if ($task['due_date'] && strtotime($task['due_date']) < time() && $task['status'] != 'Done') {
                                echo '<span style="color: #e74c3c; font-weight: 600;">' . $due_date . ' ⚠</span>';
                            } else {
                                echo $due_date;
                            }
                            ?>
                        </td>
                        <td>
                            <a href="task_view.php?id=<?php echo $task['id']; ?>" class="btn btn-sm btn-primary" title="View"><i class="fas fa-eye"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include 'includes/footer.php'; ?>
