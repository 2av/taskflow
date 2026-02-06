<?php
// Renders one task row for tasks table. Expects in scope: $task, $row_id_prefix (string), $statuses, $organization_id, $sprints_by_project, $users, $tasks_has_sprint
$task_status_id = $task['status_id'] ?? null;
$task_status = $task['status'] ?? 'To Do';
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
$status_class = 'table-status-pending';
if (stripos($task_status, 'progress') !== false || stripos($task_status, 'active') !== false) {
    $status_class = 'table-status-active';
} elseif (stripos($task_status, 'done') !== false || stripos($task_status, 'closed') !== false || stripos($task_status, 'complete') !== false) {
    $status_class = 'table-status-closed';
}
$priority_lower = strtolower($task['priority'] ?? '');
$priority_class = 'priority-low';
if ($priority_lower == 'high') {
    $priority_class = 'priority-high';
} elseif ($priority_lower == 'medium') {
    $priority_class = 'priority-medium';
}
$due_date_display = '—';
if (!empty($task['due_date'])) {
    $due_date_display = date('d-m-Y', strtotime($task['due_date']));
}
$assignee_initials = '';
if (!empty($task['assignee_name'])) {
    $assignee_initials = getInitials($task['assignee_name']);
}
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
<tr data-task-id="<?php echo $task['id']; ?>" style="cursor: pointer; transition: background-color 0.2s;" class="task-row">
    <td>
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
    <?php if ($tasks_has_sprint): ?>
    <td style="text-align: center;" onclick="event.stopPropagation();">
        <?php
        $task_project_id = (int)$task['project_id'];
        $task_sprints = $sprints_by_project[$task_project_id] ?? [];
        ?>
        <?php if (!empty($task_sprints)): ?>
            <form method="POST" action="" style="display: inline-block; margin: 0;" id="<?php echo htmlspecialchars($row_id_prefix); ?>sprintForm_<?php echo $task['id']; ?>" onsubmit="return updateTaskSprintQuick(<?php echo $task['id']; ?>, this);">
                <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                <input type="hidden" name="update_sprint_quick" value="1">
                <select name="sprint_id"
                        onchange="this.form.submit();"
                        style="padding: 4px 24px 4px 8px; border: 1px solid var(--border-color); font-size: 12px; cursor: pointer; background: white; color: var(--text-primary); appearance: none; background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 12 12\'%3E%3Cpath fill=\'%23333333\' d=\'M6 9L1 4h10z\'/%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 8px center; min-width: 120px;">
                    <option value="">Backlog</option>
                    <?php foreach ($task_sprints as $spr): ?>
                        <option value="<?php echo (int)$spr['id']; ?>" <?php echo (isset($task['sprint_id']) && (int)$task['sprint_id'] === (int)$spr['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($spr['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php else: ?>
            <?php if (!empty($task['sprint_name'])): ?>
                <a href="sprints?project_id=<?php echo (int)$task['project_id']; ?>&sprint_id=<?php echo (int)$task['sprint_id']; ?>#backlog" class="table-status-badge table-status-active" style="text-decoration: none;"><?php echo htmlspecialchars($task['sprint_name']); ?></a>
            <?php else: ?>
                <span style="color: var(--text-muted);">—</span>
            <?php endif; ?>
        <?php endif; ?>
    </td>
    <?php endif; ?>
    <td style="text-align: center;" onclick="event.stopPropagation();">
        <form method="POST" action="" style="display: inline-block; margin: 0;" id="<?php echo htmlspecialchars($row_id_prefix); ?>statusForm_<?php echo $task['id']; ?>" onsubmit="return updateTaskStatusQuick(<?php echo $task['id']; ?>, this);">
            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
            <input type="hidden" name="update_status_quick" value="1">
            <select name="status_id"
                    onchange="this.form.submit();"
                    onclick="event.stopPropagation();"
                    style="padding: 4px 24px 4px 8px; border: 1px solid var(--border-color);  font-size: 12px; font-weight: 500; cursor: pointer; background: white; color: var(--text-primary); appearance: none; background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 12 12\'%3E%3Cpath fill=\'%23333333\' d=\'M6 9L1 4h10z\'/%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 8px center; min-width: 110px;">
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
    <td style="text-align: center;" onclick="event.stopPropagation();">
        <form method="POST" action="" style="display: inline-block; margin: 0;" id="<?php echo htmlspecialchars($row_id_prefix); ?>assigneeForm_<?php echo $task['id']; ?>" onsubmit="return updateTaskAssigneeQuick(<?php echo $task['id']; ?>, this);">
            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
            <input type="hidden" name="update_assignee_quick" value="1">
            <select name="assignee_id"
                    onchange="this.form.submit();"
                    onclick="event.stopPropagation();"
                    style="padding: 4px 24px 4px 8px; border: 1px solid var(--border-color); font-size: 12px; cursor: pointer; background: white; color: var(--text-primary); appearance: none; background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 12 12\'%3E%3Cpath fill=\'%23333333\' d=\'M6 9L1 4h10z\'/%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 8px center; min-width: 130px;">
                <option value=""><?php echo $assignee_initials ? htmlspecialchars($task['assignee_name']) : 'Unassigned'; ?></option>
                <?php if (!empty($users)): ?>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo (!empty($task['assignee_id']) && $task['assignee_id'] == $user['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </form>
    </td>
</tr>
