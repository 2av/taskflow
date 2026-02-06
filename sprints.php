<?php
require_once 'config/config.php';
requireLogin();
requireActiveSubscription();
requireProjectManager(); // Same as projects - PM, Admin, Super Admin

$page_title = 'Sprints'; // Updated later with project name
$conn = getDBConnection();
$message = '';
$error = '';

// Get projects (same logic as projects.php)
if (isSuperAdmin()) {
    $projects = $conn->query("
        SELECT p.*, (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) as task_count
        FROM projects p ORDER BY p.name
    ")->fetch_all(MYSQLI_ASSOC);
} else if (isOrgAdmin()) {
    $org_id = getOrganizationId();
    $stmt = $conn->prepare("
        SELECT p.*, (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) as task_count
        FROM projects p WHERE p.organization_id = ? ORDER BY p.name
    ");
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT DISTINCT p.*, (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) as task_count
        FROM projects p
        LEFT JOIN project_users pu ON p.id = pu.project_id
        WHERE p.project_manager_id = ? OR p.created_by = ? OR pu.user_id = ?
        ORDER BY p.name
    ");
    $stmt->bind_param("iii", $user_id, $user_id, $user_id);
    $stmt->execute();
    $projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Selected project: dashboard/session first, then URL, then first project (same as tasks)
if (isset($_GET['project_id']) && $_GET['project_id'] !== '') {
    $pid = intval($_GET['project_id']);
    foreach ($projects as $p) {
        if ((int)$p['id'] === $pid) {
            $_SESSION['selected_project_id'] = $pid;
            break;
        }
    }
}
$selected_project_id = null;
if (isset($_SESSION['selected_project_id']) && !empty($_SESSION['selected_project_id'])) {
    $sid = (int)$_SESSION['selected_project_id'];
    foreach ($projects as $p) {
        if ((int)$p['id'] === $sid) {
            $selected_project_id = $sid;
            break;
        }
    }
}
if ($selected_project_id === null && isset($_GET['project_id']) && $_GET['project_id'] !== '') {
    $pid = intval($_GET['project_id']);
    foreach ($projects as $p) {
        if ((int)$p['id'] === $pid) {
            $selected_project_id = $pid;
            break;
        }
    }
}
if ($selected_project_id === null && !empty($projects)) {
    $selected_project_id = (int)$projects[0]['id'];
}
// Selected project name for title
$selected_project_name = null;
if ($selected_project_id) {
    foreach ($projects as $p) {
        if ((int)$p['id'] === $selected_project_id) {
            $selected_project_name = $p['name'];
            break;
        }
    }
}

// Create sprint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_sprint'])) {
    $project_id = intval($_POST['project_id']);
    $name = trim($_POST['name'] ?? '');
    $goal = trim($_POST['goal'] ?? '');
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $created_by = $_SESSION['user_id'];

    if (empty($name)) {
        $error = 'Sprint name is required';
    } else {
        $stmt = $conn->prepare("INSERT INTO sprints (project_id, name, goal, start_date, end_date, status, created_by) VALUES (?, ?, ?, ?, ?, 'planning', ?)");
        $stmt->bind_param("issssi", $project_id, $name, $goal, $start_date, $end_date, $created_by);
        if ($stmt->execute()) {
            $message = 'Sprint created successfully';
            $selected_project_id = $project_id;
            header('Location: sprints?project_id=' . $project_id . '&created=1');
            exit();
        } else {
            $error = 'Error creating sprint: ' . $conn->error;
        }
        $stmt->close();
    }
}

// Update sprint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_sprint'])) {
    $sprint_id = intval($_POST['sprint_id']);
    $name = trim($_POST['name'] ?? '');
    $goal = trim($_POST['goal'] ?? '');
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $status = $_POST['status'] ?? 'planning';

    if (empty($name)) {
        $error = 'Sprint name is required';
    } else {
        $stmt = $conn->prepare("UPDATE sprints SET name = ?, goal = ?, start_date = ?, end_date = ?, status = ? WHERE id = ?");
        $stmt->bind_param("sssssi", $name, $goal, $start_date, $end_date, $status, $sprint_id);
        if ($stmt->execute()) {
            $sprint_row = $conn->query("SELECT project_id FROM sprints WHERE id = $sprint_id")->fetch_assoc();
            $message = 'Sprint updated successfully';
            header('Location: sprints?project_id=' . ($sprint_row['project_id'] ?? '') . '&updated=1');
            exit();
        } else {
            $error = 'Error updating sprint: ' . $conn->error;
        }
        $stmt->close();
    }
}

// Quick update sprint status (from table dropdown)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_sprint_status'])) {
    $sprint_id = intval($_POST['sprint_id']);
    $new_status = $_POST['status'] ?? '';
    $valid_statuses = ['planning', 'active', 'completed', 'closed'];
    if ($sprint_id && in_array($new_status, $valid_statuses)) {
        $sprint_row = $conn->query("SELECT project_id FROM sprints WHERE id = $sprint_id")->fetch_assoc();
        if ($sprint_row) {
            $stmt = $conn->prepare("UPDATE sprints SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $new_status, $sprint_id);
            if ($stmt->execute()) {
                $stmt->close();
                $message = 'Sprint status updated to ' . ucfirst($new_status);
                header('Location: sprints?project_id=' . (int)$sprint_row['project_id'] . '&updated=1');
                exit();
            }
            $stmt->close();
        }
    }
}

// Delete sprint
if (isset($_GET['delete_sprint'])) {
    $sprint_id = intval($_GET['delete_sprint']);
    $sprint_row = $conn->query("SELECT project_id FROM sprints WHERE id = $sprint_id")->fetch_assoc();
    $conn->query("UPDATE tasks SET sprint_id = NULL WHERE sprint_id = $sprint_id");
    $conn->query("DELETE FROM sprints WHERE id = $sprint_id");
    $message = 'Sprint deleted';
    header('Location: sprints?project_id=' . ($sprint_row['project_id'] ?? '') . '&deleted=1');
    exit();
}

// Assign task to sprint (AJAX or POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_task_sprint'])) {
    $task_id = intval($_POST['task_id']);
    $sprint_id = !empty($_POST['sprint_id']) ? intval($_POST['sprint_id']) : null;
    $conn->prepare("UPDATE tasks SET sprint_id = ? WHERE id = ?")->bind_param("ii", $sprint_id, $task_id)->execute();
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        $conn->close();
        exit();
    }
    $message = 'Task updated';
    $ref = $_POST['ref'] ?? 'sprints';
    header('Location: ' . ($ref === 'backlog' ? 'sprints?project_id=' . intval($_POST['project_id']) . '&sprint_id=' . intval($_POST['sprint_id']) . '#backlog' : 'sprints?project_id=' . intval($_POST['project_id'] ?? '')));
    exit();
}

// Get sprints for selected project
$sprints = [];
if ($selected_project_id) {
    $stmt = $conn->prepare("
        SELECT s.*, u.full_name as creator_name,
               (SELECT COUNT(*) FROM tasks t WHERE t.sprint_id = s.id) as task_count
        FROM sprints s
        LEFT JOIN users u ON s.created_by = u.id
        WHERE s.project_id = ?
        ORDER BY s.start_date DESC, s.created_at DESC
    ");
    $stmt->bind_param("i", $selected_project_id);
    $stmt->execute();
    $sprints = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$edit_sprint = null;
if (isset($_GET['edit_sprint'])) {
    $edit_id = intval($_GET['edit_sprint']);
    $edit_sprint = $conn->query("SELECT * FROM sprints WHERE id = $edit_id")->fetch_assoc();
}

// Backlog: tasks in sprint + project tasks not in any sprint (for add to sprint)
$sprint_backlog_id = isset($_GET['sprint_id']) ? intval($_GET['sprint_id']) : null;
$backlog_tasks = [];
$backlog_available = [];
if ($sprint_backlog_id && $selected_project_id) {
    $stmt = $conn->prepare("
        SELECT t.id, t.task_id, t.title, t.status, t.priority, t.assignee_id, t.due_date,
               u.full_name as assignee_name
        FROM tasks t
        LEFT JOIN users u ON t.assignee_id = u.id
        WHERE t.sprint_id = ? AND t.project_id = ?
        ORDER BY t.created_at ASC
    ");
    $stmt->bind_param("ii", $sprint_backlog_id, $selected_project_id);
    $stmt->execute();
    $backlog_tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT t.id, t.task_id, t.title, t.status
        FROM tasks t
        WHERE t.project_id = ? AND (t.sprint_id IS NULL OR t.sprint_id = 0)
        ORDER BY t.created_at DESC
        LIMIT 100
    ");
    $stmt->bind_param("i", $selected_project_id);
    $stmt->execute();
    $backlog_available = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$current_sprint = null;
if ($sprint_backlog_id) {
    $current_sprint = $conn->query("SELECT * FROM sprints WHERE id = $sprint_backlog_id")->fetch_assoc();
}

if (isset($_GET['created'])) $message = 'Sprint created successfully';
if (isset($_GET['updated'])) $message = 'Sprint updated successfully';
if (isset($_GET['deleted'])) $message = 'Sprint deleted';

$conn->close();
$page_title = $selected_project_name ? 'Sprints (' . $selected_project_name . ')' : 'Sprints';
include 'includes/header.php';
?>

<div class="projects-page-container">
    <div class="projects-header">
        <div>
            <h1 class="projects-title">Sprints<?php echo $selected_project_name ? ' (' . htmlspecialchars($selected_project_name) . ')' : ''; ?></h1>
            <p class="projects-subtitle">Create sprints and organize tasks by iteration</p>
        </div>
        <button type="button" class="add-project-btn modal-trigger" data-modal="sprintModal" title="Create Sprint" id="btnCreateSprint">
            <i class="fas fa-plus"></i>
            <span>Create Sprint</span>
        </button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success" style="background: #d1fae5; border: 1px solid #10b981; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error" style="background: #fee2e2; border: 1px solid #ef4444; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- Sprints table -->
    <div class="tasks-table-container">
        <table class="tasks-table">
            <thead>
                <tr>
                    <th>Sprint</th>
                    <th>Goal</th>
                    <th>Dates</th>
                    <th>Status</th>
                    <th style="text-align: center;">Tasks</th>
                    <th style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sprints)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 48px 16px; color: var(--text-muted);">
                            <i class="fas fa-running" style="font-size: 48px; opacity: 0.3; margin-bottom: 12px; display: block;"></i>
                            <p style="margin: 0;">No sprints yet</p>
                            <p style="margin: 8px 0 0 0; font-size: 12px;">Create a sprint to organize tasks by iteration</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sprints as $s): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($s['name']); ?></strong>
                            </td>
                            <td>
                                <?php echo htmlspecialchars(mb_substr($s['goal'] ?? '', 0, 80)); ?><?php echo mb_strlen($s['goal'] ?? '') > 80 ? '…' : ''; ?>
                            </td>
                            <td>
                                <?php
                                if ($s['start_date'] || $s['end_date']) {
                                    echo htmlspecialchars(formatDate($s['start_date'] ?? '') . ' – ' . formatDate($s['end_date'] ?? ''));
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td>
                                <form method="POST" action="sprints" style="display: inline-block; margin: 0;" id="statusForm_<?php echo (int)$s['id']; ?>">
                                    <input type="hidden" name="update_sprint_status" value="1">
                                    <input type="hidden" name="sprint_id" value="<?php echo (int)$s['id']; ?>">
                                    <select name="status" onchange="this.form.submit();" style="padding: 4px 24px 4px 8px; border: 1px solid var(--border-color); font-size: 12px; font-weight: 500; cursor: pointer; background: white; color: var(--text-primary); appearance: none; background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 12 12\'%3E%3Cpath fill=\'%23333333\' d=\'M6 9L1 4h10z\'/%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 8px center; min-width: 100px;">
                                        <option value="planning" <?php echo ($s['status'] ?? '') === 'planning' ? 'selected' : ''; ?>>Planning</option>
                                        <option value="active" <?php echo ($s['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="completed" <?php echo ($s['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="closed" <?php echo ($s['status'] ?? '') === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                    </select>
                                </form>
                            </td>
                            <td style="text-align: center;">
                                <a href="sprints?project_id=<?php echo (int)$s['project_id']; ?>&sprint_id=<?php echo (int)$s['id']; ?>#backlog" class="btn btn-sm btn-primary" title="Sprint backlog">
                                    <?php echo (int)$s['task_count']; ?> tasks
                                </a>
                            </td>
                            <td style="text-align: center;">
                                <a href="sprints?project_id=<?php echo (int)$s['project_id']; ?>&edit_sprint=<?php echo (int)$s['id']; ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                                <a href="sprints?project_id=<?php echo (int)$s['project_id']; ?>&delete_sprint=<?php echo (int)$s['id']; ?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Delete this sprint? Tasks will be moved to backlog.');"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Sprint Backlog (when sprint_id in URL) -->
    <?php if ($current_sprint && $sprint_backlog_id): ?>
        <div id="backlog" style="margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--border-color);">
            <h2 style="margin-bottom: 16px;">Sprint backlog: <?php echo htmlspecialchars($current_sprint['name']); ?></h2>
            <div style="display: flex; gap: 24px; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 300px;">
                    <h3 style="margin-bottom: 12px;">Tasks in this sprint</h3>
                    <ul style="list-style: none; padding: 0;">
                        <?php if (empty($backlog_tasks)): ?>
                            <li style="padding: 12px; background: #f8f9fa; border-radius: 8px; color: var(--text-muted);">No tasks in this sprint</li>
                        <?php else: ?>
                            <?php foreach ($backlog_tasks as $t): ?>
                                <li style="padding: 10px 12px; margin-bottom: 6px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
                                    <a href="task_view?id=<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['task_id']); ?> – <?php echo htmlspecialchars($t['title']); ?></a>
                                    <form method="post" style="margin: 0;" onsubmit="return confirm('Remove from sprint?');">
                                        <input type="hidden" name="assign_task_sprint" value="1">
                                        <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">
                                        <input type="hidden" name="sprint_id" value="">
                                        <input type="hidden" name="project_id" value="<?php echo (int)$selected_project_id; ?>">
                                        <input type="hidden" name="ref" value="backlog">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Remove from sprint"><i class="fas fa-times"></i></button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
                <div style="flex: 1; min-width: 300px;">
                    <h3 style="margin-bottom: 12px;">Add from backlog</h3>
                    <?php if (empty($backlog_available)): ?>
                        <p style="color: var(--text-muted);">No unassigned tasks in this project</p>
                    <?php else: ?>
                        <ul style="list-style: none; padding: 0;">
                            <?php foreach ($backlog_available as $t): ?>
                                <li style="padding: 10px 12px; margin-bottom: 6px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
                                    <a href="task_view?id=<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['task_id']); ?> – <?php echo htmlspecialchars($t['title']); ?></a>
                                    <form method="post" style="margin: 0;">
                                        <input type="hidden" name="assign_task_sprint" value="1">
                                        <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">
                                        <input type="hidden" name="sprint_id" value="<?php echo (int)$sprint_backlog_id; ?>">
                                        <input type="hidden" name="project_id" value="<?php echo (int)$selected_project_id; ?>">
                                        <input type="hidden" name="ref" value="backlog">
                                        <button type="submit" class="btn btn-sm btn-primary" title="Add to sprint"><i class="fas fa-plus"></i></button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Create / Edit Sprint Modal -->
<div id="sprintModal" class="modal<?php echo $edit_sprint ? ' show' : ''; ?>">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><?php echo $edit_sprint ? 'Edit Sprint' : 'Create Sprint'; ?></h2>
            <span class="close">&times;</span>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="<?php echo $edit_sprint ? 'update_sprint' : 'create_sprint'; ?>" value="1">
            <?php if ($edit_sprint): ?>
                <input type="hidden" name="sprint_id" value="<?php echo (int)$edit_sprint['id']; ?>">
            <?php endif; ?>
            <input type="hidden" name="project_id" value="<?php echo (int)($edit_sprint['project_id'] ?? $selected_project_id); ?>">
            <div class="form-group">
                <label for="sprint_name">Sprint name *</label>
                <input type="text" id="sprint_name" name="name" required value="<?php echo htmlspecialchars($edit_sprint['name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="sprint_goal">Goal</label>
                <textarea id="sprint_goal" name="goal" rows="3"><?php echo htmlspecialchars($edit_sprint['goal'] ?? ''); ?></textarea>
            </div>
            <div class="form-group">
                <label for="sprint_start">Start date</label>
                <input type="date" id="sprint_start" name="start_date" value="<?php echo htmlspecialchars($edit_sprint['start_date'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="sprint_end">End date</label>
                <input type="date" id="sprint_end" name="end_date" value="<?php echo htmlspecialchars($edit_sprint['end_date'] ?? ''); ?>">
            </div>
            <?php if ($edit_sprint): ?>
                <div class="form-group">
                    <label for="sprint_status">Status</label>
                    <select id="sprint_status" name="status">
                        <option value="planning" <?php echo ($edit_sprint['status'] ?? '') === 'planning' ? 'selected' : ''; ?>>Planning</option>
                        <option value="active" <?php echo ($edit_sprint['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="completed" <?php echo ($edit_sprint['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="closed" <?php echo ($edit_sprint['status'] ?? '') === 'closed' ? 'selected' : ''; ?>>Closed</option>
                    </select>
                </div>
            <?php endif; ?>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><?php echo $edit_sprint ? 'Update' : 'Create'; ?> Sprint</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.modal-trigger[data-modal="sprintModal"]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('sprintModal').classList.add('show');
    });
});
document.querySelectorAll('#sprintModal .close, #sprintModal .close-modal').forEach(function(el) {
    el.addEventListener('click', function() {
        document.getElementById('sprintModal').classList.remove('show');
        <?php if ($edit_sprint): ?>window.location.href = 'sprints?project_id=<?php echo (int)$selected_project_id; ?>';<?php endif; ?>
    });
});
</script>

<?php include 'includes/footer.php'; ?>
