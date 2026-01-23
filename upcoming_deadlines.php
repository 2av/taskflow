<?php
require_once 'config/config.php';
requireLogin();
requireActiveSubscription();

$page_title = 'Upcoming Deadlines';

$conn = getDBConnection();
$message = '';
$error = '';

// Get selected project
$selected_project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;
$selected_project = null;

if ($selected_project_id) {
    $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->bind_param("i", $selected_project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $selected_project = $result->fetch_assoc();
    $stmt->close();
}

// Get organization-specific statuses
$organization_id = isSuperAdmin() ? null : getOrganizationId();
$statuses = getStatuses($organization_id);

// Get all upcoming deadlines (tasks with due dates in next 7 days)
$upcoming_deadlines = [];

if ($selected_project_id) {
    // Get deadlines for specific project
    $upcoming_query = "
        SELECT t.*, u.full_name as assignee_name, s.name as status_name, s.color as status_color,
               p.name as project_name
        FROM tasks t
        LEFT JOIN users u ON t.assignee_id = u.id
        LEFT JOIN statuses s ON t.status_id = s.id
        LEFT JOIN projects p ON t.project_id = p.id
        WHERE t.project_id = ? 
        AND t.due_date IS NOT NULL 
        AND t.due_date >= CURDATE() 
        AND t.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND (s.name != 'Done' OR s.name IS NULL OR t.status_id IS NULL)
        ORDER BY t.due_date ASC
    ";
    $stmt = $conn->prepare($upcoming_query);
    if ($stmt) {
        $stmt->bind_param("i", $selected_project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $upcoming_deadlines = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} else {
    // Get deadlines for all projects user has access to
    $upcoming_query = "
        SELECT t.*, u.full_name as assignee_name, s.name as status_name, s.color as status_color,
               p.name as project_name
        FROM tasks t
        LEFT JOIN users u ON t.assignee_id = u.id
        LEFT JOIN statuses s ON t.status_id = s.id
        LEFT JOIN projects p ON t.project_id = p.id
        WHERE t.due_date IS NOT NULL 
        AND t.due_date >= CURDATE() 
        AND t.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND (s.name != 'Done' OR s.name IS NULL OR t.status_id IS NULL)
    ";
    
    $where_conditions = [];
    $query_params = [];
    $query_types = '';
    
    // Apply role-based filtering
    if (isSuperAdmin()) {
        // Super Admin sees all tasks - no additional filter needed
    } else if (isOrgAdmin()) {
        // Organization Admin sees all tasks in their organization
        $org_id = getOrganizationId();
        if ($org_id) {
            $where_conditions[] = "p.organization_id = ?";
            $query_params[] = $org_id;
            $query_types .= 'i';
        }
    } else if (isProjectManager()) {
        // Project Manager sees tasks in their assigned projects
        $user_id = $_SESSION['user_id'];
        $where_conditions[] = "t.project_id IN (SELECT project_id FROM project_users WHERE user_id = ?)";
        $query_params[] = $user_id;
        $query_types .= 'i';
    } else {
        // Team members only see their assigned tasks
        $user_id = $_SESSION['user_id'];
        $where_conditions[] = "t.assignee_id = ?";
        $query_params[] = $user_id;
        $query_types .= 'i';
    }
    
    if (!empty($where_conditions)) {
        $upcoming_query .= " AND " . implode(" AND ", $where_conditions);
    }
    
    $upcoming_query .= " ORDER BY t.due_date ASC";
    
    $stmt = $conn->prepare($upcoming_query);
    if ($stmt) {
        if (!empty($query_params)) {
            $stmt->bind_param($query_types, ...$query_params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $upcoming_deadlines = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

$conn->close();

include 'includes/header.php';
?>

<div class="tasks-page-container">
    <!-- Page Header -->
    <div class="tasks-overview-header">
        <div style="display: flex; align-items: center; gap: 12px;">
            <a href="dashboard<?php echo $selected_project_id ? '?project_id=' . $selected_project_id : ''; ?>" 
               class="btn btn-secondary" 
               title="Back to Dashboard" 
               style="padding: 10px 12px; min-width: auto;">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="tasks-overview-title">Upcoming Deadlines</h1>
                <p style="font-size: 14px; color: var(--text-secondary); margin: 4px 0 0 0;">
                    Next 7 days<?php echo $selected_project ? ' - ' . htmlspecialchars($selected_project['name']) : ''; ?>
                </p>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success" style="background: #d1fae5; border: 1px solid #10b981; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error" style="background: #fee2e2; border: 1px solid #ef4444; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Deadlines List -->
    <div class="premium-card" style="margin-top: 24px;">
        <?php if (empty($upcoming_deadlines)): ?>
            <div style="text-align: center; padding: 64px 24px; color: var(--text-muted);">
                <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                <h3 style="font-size: 18px; font-weight: 600; color: var(--text-primary); margin: 0 0 8px 0;">No Upcoming Deadlines</h3>
                <p style="font-size: 14px; margin: 0;">You're all caught up! No tasks due in the next 7 days.</p>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($upcoming_deadlines as $deadline): 
                    $due_date = strtotime($deadline['due_date']);
                    $days_left = ceil(($due_date - time()) / 86400);
                    $is_overdue = $days_left < 0;
                    $is_today = $days_left == 0;
                    $is_tomorrow = $days_left == 1;
                    
                    $urgency_color = $is_overdue ? 'var(--chart-red)' : ($is_today ? 'var(--chart-yellow)' : 'var(--chart-green)');
                    $urgency_text = $is_overdue ? abs($days_left) . ' days overdue' : ($is_today ? 'Due today' : ($is_tomorrow ? 'Due tomorrow' : $days_left . ' days left'));
                ?>
                    <a href="task_view?id=<?php echo $deadline['id']; ?>" 
                       style="display: flex; align-items: center; gap: 12px; padding: 16px; background: var(--page-bg); border-radius: 8px; border: 1px solid var(--border-color); text-decoration: none; transition: all 0.2s;"
                       onmouseover="this.style.borderColor='var(--blue)'; this.style.boxShadow='0 2px 8px rgba(59, 130, 246, 0.1)'; this.style.transform='translateY(-2px)';"
                       onmouseout="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none'; this.style.transform='translateY(0)';">
                        <div style="min-width: 70px; text-align: center; padding: 12px; background: <?php echo $urgency_color; ?>15; border-radius: 8px; border: 1px solid <?php echo $urgency_color; ?>40;">
                            <div style="font-size: 20px; font-weight: 700; color: <?php echo $urgency_color; ?>; line-height: 1;">
                                <?php echo date('d', $due_date); ?>
                            </div>
                            <div style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-top: 4px; font-weight: 500;">
                                <?php echo date('M', $due_date); ?>
                            </div>
                        </div>
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-size: 15px; font-weight: 600; color: var(--text-primary); margin-bottom: 6px; line-height: 1.3;">
                                <?php echo htmlspecialchars($deadline['title']); ?>
                            </div>
                            <?php if (!$selected_project && !empty($deadline['project_name'])): ?>
                                <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 6px; display: flex; align-items: center; gap: 4px;">
                                    <i class="fas fa-folder" style="font-size: 10px;"></i>
                                    <span><?php echo htmlspecialchars($deadline['project_name']); ?></span>
                                </div>
                            <?php endif; ?>
                            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 12px; color: var(--text-secondary);">
                                <?php if ($deadline['assignee_name']): ?>
                                    <span style="display: inline-flex; align-items: center; gap: 4px;">
                                        <i class="fas fa-user" style="font-size: 10px;"></i>
                                        <?php echo htmlspecialchars($deadline['assignee_name']); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($deadline['status_name']): ?>
                                    <span style="display: inline-flex; align-items: center; gap: 4px;">
                                        <span style="width: 8px; height: 8px; background: <?php echo htmlspecialchars($deadline['status_color'] ?? '#6b7280'); ?>; border-radius: 50%;"></span>
                                        <?php echo htmlspecialchars($deadline['status_name']); ?>
                                    </span>
                                <?php endif; ?>
                                <span style="display: inline-flex; align-items: center; gap: 4px;">
                                    <i class="fas fa-tag" style="font-size: 10px;"></i>
                                    <?php echo htmlspecialchars($deadline['task_id'] ?? 'N/A'); ?>
                                </span>
                            </div>
                        </div>
                        <div style="font-size: 12px; font-weight: 600; color: <?php echo $urgency_color; ?>; white-space: nowrap; padding: 8px 12px; background: <?php echo $urgency_color; ?>10; border-radius: 6px;">
                            <?php echo $urgency_text; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
