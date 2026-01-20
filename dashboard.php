<?php
require_once 'config/config.php';
require_once 'config/email.php';
requireLogin();
requireActiveSubscription();

$page_title = 'Dashboard';

$conn = getDBConnection();
$message = '';
$error = '';

// Get organization-specific statuses
$organization_id = isSuperAdmin() ? null : getOrganizationId();
$statuses = getStatuses($organization_id);

// Handle project creation from dashboard - Only admins can create projects
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_project'])) {
    // Check if user is admin (Super Admin, Org Admin, or Admin role)
    if (!isSuperAdmin() && !isOrgAdmin() && !isAdmin()) {
        $error = 'Only administrators can create projects. Please contact support or request project assignment.';
    } else {
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'Active';
        $project_manager_id = !empty($_POST['project_manager_id']) ? intval($_POST['project_manager_id']) : null;
        $created_by = $_SESSION['user_id'];
        $organization_id = getOrganizationId();
        
        if (empty($name)) {
            $error = 'Project name is required';
        } elseif (!$organization_id && !isSuperAdmin()) {
            $error = 'Organization not found';
        } else {
            // Handle NULL project_manager_id properly in mysqli
            if ($project_manager_id === null) {
                $stmt = $conn->prepare("INSERT INTO projects (name, description, status, organization_id, project_manager_id, created_by) VALUES (?, ?, ?, ?, NULL, ?)");
                $stmt->bind_param("sssii", $name, $description, $status, $organization_id, $created_by);
            } else {
                $stmt = $conn->prepare("INSERT INTO projects (name, description, status, organization_id, project_manager_id, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssiii", $name, $description, $status, $organization_id, $project_manager_id, $created_by);
            }
            
            if ($stmt->execute()) {
                $project_id = $conn->insert_id;
                
                // Add team members if selected
                $added_members = [];
                if (!empty($_POST['team_members']) && is_array($_POST['team_members'])) {
                    foreach ($_POST['team_members'] as $user_id) {
                        $user_id = intval($user_id);
                        $added_members[] = $user_id;
                        $stmt2 = $conn->prepare("INSERT INTO project_users (project_id, user_id) VALUES (?, ?)");
                        $stmt2->bind_param("ii", $project_id, $user_id);
                        $stmt2->execute();
                    }
                }
                
                // Automatically add creator to project_users if they're not the PM and not already added
                if ($project_manager_id != $created_by && !in_array($created_by, $added_members)) {
                    $stmt3 = $conn->prepare("INSERT INTO project_users (project_id, user_id) VALUES (?, ?)");
                    $stmt3->bind_param("ii", $project_id, $created_by);
                    $stmt3->execute();
                }
                
                // Refresh the page to show the new project
                header('Location: dashboard?project_created=1');
                exit();
            } else {
                $error = 'Error creating project: ' . $conn->error;
            }
        }
    }
}

// Handle project assignment request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_project_assignment'])) {
    $user_id = $_SESSION['user_id'];
    $user_email = $_SESSION['email'] ?? '';
    $user_name = $_SESSION['full_name'] ?? $_SESSION['email'] ?? 'User';
    $organization_id = getOrganizationId();
    
    // Get organization name
    $org_name = 'Task Flow System';
    if ($organization_id) {
        $org_stmt = $conn->prepare("SELECT name FROM organizations WHERE id = ?");
        $org_stmt->bind_param("i", $organization_id);
        $org_stmt->execute();
        $org_result = $org_stmt->get_result();
        if ($org_row = $org_result->fetch_assoc()) {
            $org_name = $org_row['name'];
        }
        $org_stmt->close();
    }
    
    // Get all admins in the organization (Super Admin, Org Admin, or Admin role)
    if ($organization_id) {
        $admin_query = "
            SELECT DISTINCT u.email, u.full_name, r.name as role_name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.organization_id = ? 
            AND u.status = 'active'
            AND (r.name = 'Super Admin' OR r.name = 'Admin')
            ORDER BY u.full_name
        ";
        $admin_stmt = $conn->prepare($admin_query);
        $admin_stmt->bind_param("i", $organization_id);
    } else {
        // For Super Admin or users without organization, get all Super Admins
        $admin_query = "
            SELECT DISTINCT u.email, u.full_name, r.name as role_name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE r.name = 'Super Admin'
            AND u.status = 'active'
            ORDER BY u.full_name
        ";
        $admin_stmt = $conn->prepare($admin_query);
    }
    
    $admin_stmt->execute();
    $admin_result = $admin_stmt->get_result();
    $admins = $admin_result->fetch_all(MYSQLI_ASSOC);
    $admin_stmt->close();
    
    if (!empty($admins)) {
        // Send email to all admins
        $emails_sent = 0;
        foreach ($admins as $admin) {
            if (sendProjectAssignmentRequestEmail($admin['email'], $admin['full_name'] ?? $admin['email'], $user_name, $user_email, $org_name)) {
                $emails_sent++;
            }
        }
        
        if ($emails_sent > 0) {
            $message = 'Your project assignment request has been sent to ' . $emails_sent . ' administrator(s). They will review your request and assign you to a project soon.';
        } else {
            $error = 'Could not send project assignment request. Please contact support directly.';
        }
    } else {
        $error = 'No administrators found. Please contact support directly.';
    }
}

// Check for success message
if (isset($_GET['project_created'])) {
    $message = 'Project created successfully';
}

// Helper function to format time ago
function timeAgo($datetime) {
    if (empty($datetime)) {
        return 'Never';
    }
    
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ($mins == 1 ? ' min' : ' mins') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ($hours == 1 ? ' hr' : ' hrs') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ($days == 1 ? ' day' : ' days') . ' ago';
    } elseif ($diff < 2592000) {
        $weeks = floor($diff / 604800);
        return $weeks . ($weeks == 1 ? ' week' : ' weeks') . ' ago';
    } elseif ($diff < 31536000) {
        $months = floor($diff / 2592000);
        return $months . ($months == 1 ? ' month' : ' months') . ' ago';
    } else {
        $years = floor($diff / 31536000);
        return $years . ($years == 1 ? ' year' : ' years') . ' ago';
    }
}

// Build dynamic status count SQL using status_id
$status_count_cases = [];
foreach ($statuses as $status) {
    $status_id = $status['id'];
    $status_key = strtolower(str_replace(' ', '_', $status['name']));
    $status_count_cases[] = "SUM(CASE WHEN t.status_id = $status_id THEN 1 ELSE 0 END) as {$status_key}_count";
}
$status_count_sql = implode(",\n               ", $status_count_cases);

// Get projects with task statistics and last activity
if (isSuperAdmin()) {
    // Super Admin sees all projects
    $projects_query = "
        SELECT p.*, 
               COUNT(t.id) as total_tasks,
               {$status_count_sql},
               (SELECT MAX(al.created_at) 
                FROM activity_logs al 
                JOIN tasks t2 ON al.task_id = t2.id 
                WHERE t2.project_id = p.id) as last_activity
        FROM projects p
        LEFT JOIN tasks t ON p.id = t.project_id
        GROUP BY p.id
        ORDER BY p.name
    ";
    $projects = $conn->query($projects_query)->fetch_all(MYSQLI_ASSOC);
} else if (isOrgAdmin()) {
    // Organization Admin sees all projects in their organization
    $org_id = getOrganizationId();
    $projects_query = "
        SELECT p.*, 
               COUNT(t.id) as total_tasks,
               {$status_count_sql},
               (SELECT MAX(al.created_at) 
                FROM activity_logs al 
                JOIN tasks t2 ON al.task_id = t2.id 
                WHERE t2.project_id = p.id) as last_activity
        FROM projects p
        LEFT JOIN tasks t ON p.id = t.project_id
        WHERE p.organization_id = ?
        GROUP BY p.id
        ORDER BY p.name
    ";
    $stmt = $conn->prepare($projects_query);
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $projects = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else if (isProjectManager()) {
    // PM sees assigned projects
    $user_id = $_SESSION['user_id'];
    $projects_query = "
        SELECT DISTINCT p.*, 
               COUNT(t.id) as total_tasks,
               {$status_count_sql},
               (SELECT MAX(al.created_at) 
                FROM activity_logs al 
                JOIN tasks t2 ON al.task_id = t2.id 
                WHERE t2.project_id = p.id) as last_activity
        FROM projects p
        LEFT JOIN tasks t ON p.id = t.project_id
        LEFT JOIN project_users pu ON p.id = pu.project_id
        WHERE p.project_manager_id = $user_id OR p.created_by = $user_id OR pu.user_id = $user_id
        GROUP BY p.id
        ORDER BY p.name
    ";
    $projects = $conn->query($projects_query)->fetch_all(MYSQLI_ASSOC);
} else {
    // Team Member sees projects they're assigned to
    $user_id = $_SESSION['user_id'];
    $projects_query = "
        SELECT DISTINCT p.*, 
               COUNT(t.id) as total_tasks,
               {$status_count_sql},
               (SELECT MAX(al.created_at) 
                FROM activity_logs al 
                JOIN tasks t2 ON al.task_id = t2.id 
                WHERE t2.project_id = p.id) as last_activity
        FROM projects p
        LEFT JOIN tasks t ON p.id = t.project_id
        LEFT JOIN project_users pu ON p.id = pu.project_id
        WHERE pu.user_id = $user_id
        GROUP BY p.id
        ORDER BY p.name
    ";
    $projects = $conn->query($projects_query)->fetch_all(MYSQLI_ASSOC);
}

// Get selected project (default: first project)
// Check URL first, then session, then default to first project
if (isset($_GET['project_id'])) {
    $selected_project_id = intval($_GET['project_id']);
    $_SESSION['selected_project_id'] = $selected_project_id; // Store in session
} elseif (isset($_SESSION['selected_project_id']) && !empty($_SESSION['selected_project_id'])) {
    $selected_project_id = intval($_SESSION['selected_project_id']);
} else {
    $selected_project_id = !empty($projects) ? $projects[0]['id'] : null;
    if ($selected_project_id) {
        $_SESSION['selected_project_id'] = $selected_project_id; // Store default selection
    }
}

// Get tasks for selected project
$tasks = [];
$selected_project = null;
if ($selected_project_id) {
    // Find selected project
    foreach ($projects as $proj) {
        if ($proj['id'] == $selected_project_id) {
            $selected_project = $proj;
            break;
        }
    }
    
    if ($selected_project) {
        // Build tasks query with role-based filtering
        $tasks_query = "
            SELECT t.*, 
                   u.full_name as assignee_name,
                   u2.full_name as creator_name,
                   p.name as project_name
            FROM tasks t
            LEFT JOIN users u ON t.assignee_id = u.id
            LEFT JOIN users u2 ON t.created_by = u2.id
            LEFT JOIN projects p ON t.project_id = p.id
            WHERE t.project_id = ?
        ";
        
        $query_params = [$selected_project_id];
        $query_types = 'i';
        
        // Role-based filtering
        if (isSuperAdmin()) {
            // Super Admin sees all tasks - no additional filter
        } else if (isOrgAdmin()) {
            // Organization Admin sees all tasks in their organization
            $org_id = getOrganizationId();
            $tasks_query .= " AND p.organization_id = ?";
            $query_params[] = $org_id;
            $query_types .= 'i';
        } else if (!isProjectManager()) {
            // Team members only see their assigned tasks
            $user_id = $_SESSION['user_id'];
            $tasks_query .= " AND t.assignee_id = ?";
            $query_params[] = $user_id;
            $query_types .= 'i';
        } else {
            // Project Manager sees all tasks in their projects
            $org_id = getOrganizationId();
            if ($org_id) {
                $tasks_query .= " AND p.organization_id = ?";
                $query_params[] = $org_id;
                $query_types .= 'i';
            }
        }
        
        $tasks_query .= " ORDER BY t.created_at DESC LIMIT 50";
        
        $stmt = $conn->prepare($tasks_query);
        if ($stmt) {
            $stmt->bind_param($query_types, ...$query_params);
            $stmt->execute();
            $result = $stmt->get_result();
            $tasks = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
}

// Get upcoming deadlines (tasks with due dates in next 7 days)
$upcoming_deadlines = [];
if ($selected_project_id) {
    $upcoming_query = "
        SELECT t.*, u.full_name as assignee_name, s.name as status_name, s.color as status_color
        FROM tasks t
        LEFT JOIN users u ON t.assignee_id = u.id
        LEFT JOIN statuses s ON t.status_id = s.id
        WHERE t.project_id = ? 
        AND t.due_date IS NOT NULL 
        AND t.due_date >= CURDATE() 
        AND t.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND (s.name != 'Done' OR s.name IS NULL OR t.status_id IS NULL)
        ORDER BY t.due_date ASC
        LIMIT 10
    ";
    $stmt = $conn->prepare($upcoming_query);
    if ($stmt) {
        $stmt->bind_param("i", $selected_project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $upcoming_deadlines = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Get recent tasks (last 10 created/updated)
$recent_tasks = [];
if ($selected_project_id) {
    $recent_query = "
        SELECT t.*, u.full_name as assignee_name, u2.full_name as creator_name, 
               s.name as status_name, s.color as status_color,
               GREATEST(t.created_at, COALESCE(t.updated_at, t.created_at)) as last_modified
        FROM tasks t
        LEFT JOIN users u ON t.assignee_id = u.id
        LEFT JOIN users u2 ON t.created_by = u2.id
        LEFT JOIN statuses s ON t.status_id = s.id
        WHERE t.project_id = ?
        ORDER BY last_modified DESC
        LIMIT 10
    ";
    $stmt = $conn->prepare($recent_query);
    if ($stmt) {
        $stmt->bind_param("i", $selected_project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $recent_tasks = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Get team members for the project
$team_members = [];
if ($selected_project_id) {
    $team_query = "
        SELECT DISTINCT u.id, u.full_name, u.email, u.status,
               CASE WHEN p.project_manager_id = u.id THEN 'Project Manager' 
                    WHEN pu.user_id IS NOT NULL THEN 'Team Member'
                    ELSE 'Other' END as role_in_project
        FROM users u
        LEFT JOIN projects p ON p.id = ? AND p.project_manager_id = u.id
        LEFT JOIN project_users pu ON pu.project_id = ? AND pu.user_id = u.id
        WHERE (p.project_manager_id = u.id OR pu.user_id IS NOT NULL)
        AND u.organization_id = (SELECT organization_id FROM projects WHERE id = ?)
        AND u.deleted = 0
        ORDER BY 
            CASE WHEN p.project_manager_id = u.id THEN 1 ELSE 2 END,
            u.full_name ASC
    ";
    $stmt = $conn->prepare($team_query);
    if ($stmt) {
        $stmt->bind_param("iii", $selected_project_id, $selected_project_id, $selected_project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $team_members = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Initialize variables that will be calculated later
$overall_total = 0;
$project_status_counts = [];
$project_completion = 0;

// Get last activity with user information
$last_activity = null;
if ($selected_project_id) {
    // Get last activity for selected project
    $last_activity_query = "
        SELECT al.*, u.full_name as user_name, u.email, t.title as task_title, t.id as task_id, p.name as project_name
        FROM activity_logs al
        JOIN users u ON al.user_id = u.id
        JOIN tasks t ON al.task_id = t.id
        LEFT JOIN projects p ON t.project_id = p.id
        WHERE t.project_id = ?
        ORDER BY al.created_at DESC
        LIMIT 1
    ";
    $stmt = $conn->prepare($last_activity_query);
    if ($stmt) {
        $stmt->bind_param("i", $selected_project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $last_activity = $result->fetch_assoc();
        $stmt->close();
    }
} else {
    // Get last activity across all accessible projects
    if (isSuperAdmin()) {
        $last_activity_query = "
            SELECT al.*, u.full_name as user_name, u.email, t.title as task_title, t.id as task_id, p.name as project_name
            FROM activity_logs al
            JOIN users u ON al.user_id = u.id
            JOIN tasks t ON al.task_id = t.id
            LEFT JOIN projects p ON t.project_id = p.id
            ORDER BY al.created_at DESC
            LIMIT 1
        ";
        $result = $conn->query($last_activity_query);
        if ($result) {
            $last_activity = $result->fetch_assoc();
        }
    } else if (isOrgAdmin()) {
        $org_id = getOrganizationId();
        $last_activity_query = "
            SELECT al.*, u.full_name as user_name, u.email, t.title as task_title, t.id as task_id, p.name as project_name
            FROM activity_logs al
            JOIN users u ON al.user_id = u.id
            JOIN tasks t ON al.task_id = t.id
            LEFT JOIN projects p ON t.project_id = p.id
            WHERE p.organization_id = ?
            ORDER BY al.created_at DESC
            LIMIT 1
        ";
        $stmt = $conn->prepare($last_activity_query);
        if ($stmt) {
            $stmt->bind_param("i", $org_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $last_activity = $result->fetch_assoc();
            $stmt->close();
        }
    } else if (isProjectManager()) {
        $user_id = $_SESSION['user_id'];
        $last_activity_query = "
            SELECT al.*, u.full_name as user_name, u.email, t.title as task_title, t.id as task_id, p.name as project_name
            FROM activity_logs al
            JOIN users u ON al.user_id = u.id
            JOIN tasks t ON al.task_id = t.id
            LEFT JOIN projects p ON t.project_id = p.id
            JOIN project_users pu ON p.id = pu.project_id
            WHERE pu.user_id = ?
            ORDER BY al.created_at DESC
            LIMIT 1
        ";
        $stmt = $conn->prepare($last_activity_query);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $last_activity = $result->fetch_assoc();
            $stmt->close();
        }
    } else {
        // Team member - only their assigned tasks
        $user_id = $_SESSION['user_id'];
        $last_activity_query = "
            SELECT al.*, u.full_name as user_name, u.email, t.title as task_title, t.id as task_id, p.name as project_name
            FROM activity_logs al
            JOIN users u ON al.user_id = u.id
            JOIN tasks t ON al.task_id = t.id
            LEFT JOIN projects p ON t.project_id = p.id
            WHERE t.assignee_id = ?
            ORDER BY al.created_at DESC
            LIMIT 1
        ";
        $stmt = $conn->prepare($last_activity_query);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $last_activity = $result->fetch_assoc();
            $stmt->close();
        }
    }
}

// Get users for project manager and team members (needed for project modal)
$pm_users = [];
$all_users_for_project = [];
if (isSuperAdmin()) {
    $pm_users = $conn->query("SELECT u.id, u.full_name FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name IN ('Admin', 'Project Manager', 'Super Admin') AND u.status = 'active' AND u.deleted = 0 ORDER BY u.full_name")->fetch_all(MYSQLI_ASSOC);
    $all_users_for_project = $conn->query("SELECT id, full_name FROM users WHERE status = 'active' AND deleted = 0 ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
} else {
    $org_id = getOrganizationId();
    if ($org_id) {
        $stmt = $conn->prepare("SELECT u.id, u.full_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.organization_id = ? AND r.name IN ('Admin', 'Project Manager') AND u.status = 'active' AND u.deleted = 0 ORDER BY u.full_name");
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $pm_users = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE organization_id = ? AND status = 'active' AND deleted = 0 ORDER BY full_name");
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $all_users_for_project = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

include 'includes/header.php';
?>

<style>
.dashboard-layout {
    display: flex;
    gap: 0;
    min-height: calc(100vh - 60px);
    align-items: stretch;
}

.dashboard-sidebar {
    width: 280px;
    background: var(--sidebar-bg);
    border-right: 1px solid var(--border-color);
    overflow-y: auto;
    flex-shrink: 0;
}

.sidebar-header {
    padding: 16px;
    border-bottom: 1px solid var(--border-color);
}

.sidebar-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 4px 0;
}

.sidebar-subtitle {
    font-size: 12px;
    font-weight: 400;
    color: var(--text-secondary);
    margin: 0;
}

.projects-list {
    padding: 8px;
}

.project-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    border-radius: 0px;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    color: var(--text-primary);
    background: var(--page-bg);
    border: 1px solid var(--border-color);
    margin-bottom: 5px;
}

.project-item:hover {
    background: var(--blue-light);
}

.project-item.active {
    background: var(--blue-light);
    color: var(--blue-dark);
    border-color: var(--blue);
}

.project-item-left {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    min-width: 0;
}

.project-item-icon {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.project-item.active .project-item-icon {
    background: var(--blue);
}

.project-item-name {
    font-size: 14px;
    font-weight: 400;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--sidebar-text);
}

.project-item.active .project-item-name {
    font-weight: 500;
}

.project-item-count {
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    padding: 2px 8px;
    background: var(--page-bg);
    border-radius: 12px;
    flex-shrink: 0;
}

.project-item.active .project-item-count {
    background: var(--blue);
    color: white;
}

.dashboard-main {
    flex: 1;
    overflow-y: auto;
    background: var(--page-bg);
    padding: 0px 24px;
    display: flex;
    flex-direction: column;
    min-height: 100%;
}

.dashboard-header {
    margin-bottom: 24px;
}

.dashboard-title {
    font-size: 24px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 8px 0;
    line-height: 1.2;
}

.dashboard-subtitle {
    font-size: 14px;
    font-weight: 400;
    color: var(--text-secondary);
    margin: 0;
}

.project-card {
    background: var(--card-bg);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    box-shadow: 0 1px 3px var(--shadow);
    padding: 0;
    transition: all 0.2s ease;
    text-decoration: none;
    display: block;
    color: inherit;
    overflow: hidden;
}

.project-card:hover {
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.08);
    border-color: var(--blue-light);
    transform: translateY(-2px);
}

.project-card-header {
    padding: 20px;
    border-bottom: 1px solid var(--border-color);
}

.project-card-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
    gap: 12px;
}

.project-card-title {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    min-width: 0;
}

.project-card-icon {
    color: var(--blue);
    font-size: 18px;
    flex-shrink: 0;
}

.project-card-name {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.project-status-badge {
    padding: 4px 10px;

    font-size: 11px;
    font-weight: 500;
    white-space: nowrap;
    flex-shrink: 0;
}

.project-status-active {
    background-color: var(--active-bg);
    color: var(--active-text);
}

.project-status-pending {
    background-color: var(--pending-bg);
    color: var(--pending-text);
}

.project-status-closed {
    background-color: var(--closed-bg);
    color: var(--closed-text);
}

.project-last-activity {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 8px;
}

.project-last-activity i {
    font-size: 11px;
}

.project-card-body {
    padding: 20px;
}

.project-stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}

.project-stat-box {
    background: var(--page-bg);
    border-radius: 8px;
    padding: 16px;
    text-align: center;
    border: 1px solid var(--border-color);
}

.project-stat-value {
    font-size: 24px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 4px 0;
    line-height: 1.2;
}

.project-stat-label {
    font-size: 11px;
    font-weight: 400;
    color: var(--text-secondary);
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-total .project-stat-value { color: var(--text-primary); }
.stat-todo .project-stat-value { color: var(--chart-yellow); }
.stat-inprogress .project-stat-value { color: var(--blue); }
.stat-done .project-stat-value { color: var(--chart-green); }

.project-progress-section {
    padding-top: 20px;
    border-top: 1px solid var(--border-color);
}

.project-progress-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}

.project-progress-label {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
    margin: 0;
}

.project-progress-percent {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.project-progress-bar {
    width: 100%;
    height: 6px;
    background-color: var(--border-color);
    border-radius: 3px;
    overflow: hidden;
}

.project-progress-fill {
    height: 100%;
    background-color: var(--blue);
    border-radius: 3px;
    transition: width 0.3s ease;
}

.empty-state {
    background: var(--card-bg);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    box-shadow: 0 1px 3px var(--shadow);
    padding: 64px 24px;
    text-align: center;
}

.empty-state-icon {
    font-size: 64px;
    color: var(--text-muted);
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state-text {
    font-size: 16px;
    font-weight: 400;
    color: var(--text-secondary);
    margin: 0;
}

/* Mobile Project Selector - Hidden on Desktop */
.mobile-project-selector {
    display: none;
}

/* Mobile Responsive Styles */
@media (max-width: 768px) {
    /* Show mobile project selector on mobile */
    .mobile-project-selector {
        display: block !important;
        margin-bottom: 16px;
        background: var(--card-bg);
        border-radius: 10px;
        border: 1px solid var(--border-color);
        padding: 8px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }
    
    .mobile-project-select {
        width: 100%;
        padding: 12px 16px;
        padding-right: 40px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--card-bg);
        color: var(--text-primary);
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        appearance: none;
        background-image: url('data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'14\' height=\'14\' viewBox=\'0 0 14 14\'%3E%3Cpath fill=\'%233B82F6\' d=\'M7 10L2 5h10z\'/%3E%3C/svg%3E');
        background-repeat: no-repeat;
        background-position: right 14px center;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
    }
    
    .mobile-project-select:hover {
        border-color: var(--blue);
        box-shadow: 0 2px 6px rgba(59, 130, 246, 0.15);
    }
    
    .mobile-project-select:focus {
        outline: none;
        border-color: var(--blue);
        box-shadow: 0 0 0 3px var(--blue-light), 0 2px 8px rgba(59, 130, 246, 0.2);
        transform: translateY(-1px);
    }
    
    .dashboard-layout {
        flex-direction: column;
        min-height: calc(100vh - 60px);
    }
    
    .dashboard-additional-sections {
        grid-template-columns: 1fr !important;
    }
    
    .dashboard-sidebar {
        width: 100%;
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease;
        border-right: none;
        border-bottom: 1px solid var(--border-color);
    }
    
    .dashboard-sidebar.mobile-open {
        max-height: 400px;
        overflow-y: auto;
    }
    
    .sidebar-header {
        padding: 12px 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .sidebar-title {
        font-size: 14px;
    }
    
    .sidebar-subtitle {
        font-size: 11px;
    }
    
    .projects-list {
        padding: 8px;
    }
    
    .project-item {
        padding: 10px;
        margin-bottom: 4px;
    }
    
    .project-item-name {
        font-size: 13px;
    }
    
    .project-item-count {
        font-size: 11px;
        padding: 2px 6px;
    }
    
    .dashboard-main {
        padding: 16px;
        width: 100%;
    }
    
    .dashboard-header {
        margin-bottom: 16px;
    }
    
    .dashboard-title {
        font-size: 20px;
    }
    
    .dashboard-subtitle {
        font-size: 13px;
    }
    
    .premium-card {
        padding: 16px;
        margin-bottom: 16px;
    }
    
    /* Projects Overview Card Mobile */
    .premium-card > div:first-child {
        flex-direction: column;
        align-items: flex-start !important;
        gap: 12px;
    }
    
    .premium-card > div:first-child > div:last-child {
        width: 100%;
        flex-direction: column;
        gap: 8px;
    }
    
    .premium-card > div:first-child > div:last-child {
        width: 100%;
        flex-direction: row !important;
        justify-content: space-between !important;
        gap: 8px;
    }
    
    .premium-card > div:first-child > div:last-child button,
    .premium-card > div:first-child > div:last-child a {
        flex: 1;
        justify-content: center;
        padding: 10px 16px;
        font-size: 14px;
        min-height: 44px;
    }
    
    /* Hide ellipsis button on mobile */
    .btn-more-options {
        display: none !important;
    }
    
    /* Header sections with flex space-between */
    .premium-card > div[style*="justify-content: space-between"] {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 12px;
    }
    
    /* Status Badges Mobile - Keep Full Appearance with Count Below */
    .status-badges-container {
        flex-direction: row !important;
        gap: 8px !important;
        margin-bottom: 16px !important;
    }
    
    .status-badge {
        flex: 1;
        min-width: 0;
        padding: 10px 12px !important;
        font-size: 13px !important;
        flex-direction: column !important;
        align-items: center !important;
        gap: 4px !important;
    }
    
    .status-badge > div {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .status-badge i {
        flex-shrink: 0;
        font-size: 10px;
    }
    
    .status-badge span:not(.status-count) {
        display: inline !important;
        font-size: 12px;
    }
    
    .status-badge .status-count {
        font-weight: 700;
        font-size: 16px !important;
        margin-top: 2px;
    }
    
    /* Charts Grid Mobile */
    .charts-grid-layout {
        grid-template-columns: 1fr !important;
        gap: 20px !important;
    }
    
    .premium-card > div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
        gap: 20px !important;
    }
    
    /* Task Distribution Mobile */
    .premium-card h3 {
        font-size: 12px;
        margin-bottom: 12px;
    }
    
    /* Project Stats Grid Mobile */
    .project-stats-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 8px !important;
    }
    
    .project-stat-box {
        padding: 12px;
    }
    
    .project-stat-value {
        font-size: 20px;
    }
    
    .project-stat-label {
        font-size: 10px;
    }
    
    /* Project Cards Mobile */
    .project-card {
        margin-bottom: 12px;
    }
    
    .project-card-header {
        padding: 16px;
    }
    
    .project-card-title-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .project-card-body {
        padding: 16px;
    }
    
    /* Last Activity Card Mobile */
    .premium-card .premium-card {
        margin-bottom: 16px;
    }
    
    /* Charts Mobile */
    .premium-card > div[style*="height: 200px"] {
        height: 180px !important;
        padding: 12px !important;
    }
    
    /* Add Task Modal Mobile */
    .modal-content {
        width: 95% !important;
        max-width: 95% !important;
        margin: 10px auto !important;
        max-height: 90vh;
        overflow-y: auto;
    }
    
    .modal-header {
        padding: 16px !important;
    }
    
    .modal-body {
        padding: 16px !important;
    }
    
    .modal-footer {
        padding: 12px 16px !important;
        flex-direction: column;
        gap: 8px;
    }
    
    .modal-footer button {
        width: 100%;
    }
    
    /* Add Project Modal Mobile */
    #addProjectModal .modal-content {
        width: 95% !important;
        max-width: 95% !important;
    }
    
    /* Empty State Mobile */
    .empty-state {
        padding: 40px 20px;
    }
    
    .empty-state-icon {
        font-size: 48px;
        margin-bottom: 16px;
    }
    
    .empty-state-text {
        font-size: 14px;
    }
    
    /* Mobile Sidebar Toggle Button */
    .mobile-sidebar-toggle {
        display: none;
        position: fixed;
        bottom: 100px;
        right: 16px;
        width: 48px;
        height: 48px;
        background: var(--blue);
        color: white;
        border: none;
        border-radius: 50%;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        cursor: pointer;
        z-index: 1000;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }
    
    .mobile-sidebar-toggle.show {
        display: flex;
    }
}

@media (max-width: 480px) {
    .dashboard-main {
        padding: 12px;
    }
    
    .premium-card {
        padding: 12px;
    }
    
    .dashboard-title {
        font-size: 18px;
    }
    
    .project-stats-grid {
        grid-template-columns: 1fr !important;
    }
    
    .project-card-header,
    .project-card-body {
        padding: 12px;
    }
    
    .premium-card > div:first-child > div:first-child h2 {
        font-size: 16px;
    }
    
    .premium-card > div:first-child > div:first-child span {
        font-size: 12px;
    }
    
    .mobile-sidebar-toggle {
        bottom: 90px;
        right: 12px;
        width: 44px;
        height: 44px;
        font-size: 18px;
    }
    
    /* Smaller text on very small screens */
    .premium-card h2,
    .premium-card h3 {
        font-size: 14px;
    }
    
    .project-stat-value {
        font-size: 18px;
    }
}
</style>

<div class="dashboard-layout">
    <!-- Mobile Sidebar Toggle Button -->
    <button class="mobile-sidebar-toggle d-none" onclick="toggleMobileSidebar()">
        <i class="fas fa-folder"></i>
    </button>
    
    <!-- Left Sidebar -->
    <div class="dashboard-sidebar" id="dashboardSidebar">
        <div class="sidebar-header">
            <h2 class="sidebar-title">Projects</h2>
            <p class="sidebar-subtitle"><?php echo count($projects); ?> total</p>
        </div>
        
        <div class="projects-list">
            <?php if (empty($projects)): ?>
                <div style="padding: 20px; text-align: center; color: var(--text-muted);">
                    <p style="font-size: 14px; margin: 0;">No projects found</p>
                </div>
            <?php else: ?>
                    <?php foreach ($projects as $project): ?>
                    <?php 
                    $is_active = $selected_project_id == $project['id'];
                    $total_tasks = intval($project['total_tasks']);
                    
                    // Determine dot color based on project status
                    $status_lower = strtolower($project['status'] ?? '');
                    $dot_color = 'var(--chart-green)'; // default green
                    if (strpos($status_lower, 'pending') !== false || strpos($status_lower, 'pend') !== false) {
                        $dot_color = 'var(--chart-yellow)';
                    } elseif (strpos($status_lower, 'closed') !== false || strpos($status_lower, 'completed') !== false) {
                        $dot_color = 'var(--chart-gray)';
                    }
                    ?>
                    <a href="dashboard?project_id=<?php echo $project['id']; ?>" 
                       class="project-item <?php echo $is_active ? 'active' : ''; ?>"
                       onclick="sessionStorage.setItem('selectedProjectId', <?php echo $project['id']; ?>);">
                        <div class="project-item-left">
                            <div class="project-item-icon" style="background: <?php echo $dot_color; ?>;"></div>
                            <span class="project-item-name"><?php echo htmlspecialchars($project['name']); ?></span>
                        </div>
                        <span class="project-item-count"><?php echo $total_tasks; ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Main Content Area -->
    <div class="dashboard-main">
        <!-- Mobile Project Selector Dropdown -->
        <?php if (!empty($projects)): ?>
        <div class="mobile-project-selector">
            <select id="mobileProjectSelect" onchange="changeProjectMobile(this.value)" class="mobile-project-select">
                <option value="">Select a project...</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?php echo $project['id']; ?>" <?php echo ($selected_project_id == $project['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($project['name']); ?> (<?php echo intval($project['total_tasks']); ?> tasks)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        
        <?php if (empty($projects)): ?>
            <!-- Empty State: No Projects -->
            <div class="premium-card empty-state mt-24">
                <?php if (isSuperAdmin() || isOrgAdmin() || isAdmin()): ?>
                    <!-- Admin View: Can create projects -->
                    <i class="fas fa-folder-plus empty-state-icon"></i>
                    <h2 class="empty-state-title">No Projects Yet</h2>
                    <p class="empty-state-text">
                        Get started by creating your first project. Projects help you organize and track your tasks efficiently.
                    </p>
                    <button type="button" onclick="openAddProjectModal()" class="btn-action">
                        <i class="fas fa-plus"></i>
                        <span>Add Your First Project</span>
                    </button>
                <?php else: ?>
                    <!-- Non-Admin View: Request project assignment -->
                    <i class="fas fa-folder-open empty-state-icon"></i>
                    <h2 class="empty-state-title">No Projects Assigned</h2>
                    <p class="empty-state-text mb-24">
                        You don't have any projects assigned yet. Request project assignment from your administrator to get started.
                    </p>
                    <form method="POST" action="" class="d-inline-block">
                        <input type="hidden" name="request_project_assignment" value="1">
                        <button type="submit" class="btn-action">
                            <i class="fas fa-paper-plane"></i>
                            <span>Request Project Assignment</span>
                        </button>
                    </form>
                    <p class="empty-state-text-small">
                        Or contact support at <a href="mailto:support@agprimetech.com" class="link-primary">support@agprimetech.com</a>
                    </p>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <?php 
        // Calculate statistics for selected project only - use dynamic statuses
        $project_status_counts = [];
        if ($selected_project) {
            foreach ($statuses as $status) {
                $status_key = strtolower(str_replace(' ', '_', $status['name']));
                $project_status_counts[$status['name']] = intval($selected_project[$status_key . '_count'] ?? 0);
            }
            $overall_total = intval($selected_project['total_tasks'] ?? 0);
        } else {
            // If no project selected, show overall stats
            foreach ($statuses as $status) {
                $status_key = strtolower(str_replace(' ', '_', $status['name']));
                $count = 0;
                foreach ($projects as $proj) {
                    $count += intval($proj[$status_key . '_count'] ?? 0);
                }
                $project_status_counts[$status['name']] = $count;
            }
            $overall_total = array_sum($project_status_counts);
        }
        
        // Calculate project completion percentage (after $overall_total is set)
        if ($overall_total > 0) {
            // Find "Done" or "Completed" status
            $done_count = 0;
            foreach ($statuses as $status) {
                $status_name_lower = strtolower($status['name']);
                if (strpos($status_name_lower, 'done') !== false || 
                    strpos($status_name_lower, 'complete') !== false ||
                    strpos($status_name_lower, 'closed') !== false) {
                    $done_count += $project_status_counts[$status['name']] ?? 0;
                }
            }
            $project_completion = round(($done_count / $overall_total) * 100, 1);
        } else {
            $project_completion = 0;
        }
        ?>
        
        <!-- Projects Overview Card -->
        <div class="premium-card" style="margin-bottom: 24px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <h2 style="font-size: 18px; font-weight: 600; color: var(--text-primary); margin: 0;">Projects</h2>
                    <?php if ($selected_project): ?>
                        <span style="font-size: 14px; color: var(--text-secondary); font-weight: 400;">
                            / <span style="color: var(--blue); font-weight: 500;"><?php echo htmlspecialchars($selected_project['name']); ?></span>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="dashboard-action-buttons" style="display: flex; gap: 8px; align-items: center;">
                    <button type="button" onclick="openAddTaskModal()" class="btn-add-task" 
                            style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: var(--chart-green); color: white; text-decoration: none; font-size: 14px; font-weight: 500; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border: none; cursor: pointer;">
                        <i class="fas fa-plus"></i>
                        <span class="btn-text">Add Task</span>
                    </button>
                    <?php if ($selected_project_id): ?>
                        <a href="tasks?project_id=<?php echo $selected_project_id; ?>" class="btn-view-tasks"
                           class="btn-action-sm">
                            <i class="fas fa-tasks"></i>
                            <span class="btn-text">View Tasks</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
              
            
            <!-- Unified Charts Layout -->
            <div class="charts-grid-layout" style="display: grid; grid-template-columns: 1.2fr 1fr 1.5fr; gap: 24px; margin-top: 24px; align-items: start;">
             <!-- Center: Donut Chart -->
             <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <h3 style="font-size: 13px; font-weight: 600; color: var(--text-secondary); margin: 0 0 20px 0; text-transform: uppercase; letter-spacing: 0.5px;">Overall Progress</h3>
                    <div style="display: flex; align-items: center; justify-content: center;">
                        <!-- Donut Chart -->
                        <div style="position: relative; display: inline-block;">
                            <svg width="160" height="160" viewBox="0 0 160 160" style="transform: rotate(-90deg);">
                                <?php 
                                // Build donut chart dynamically from all statuses
                                $radius = 60;
                                $circumference = 2 * M_PI * $radius;
                                $stroke_width = 20;
                                $start_offset = 0;
                                
                                // Calculate data for each status
                                $chart_data = [];
                                foreach ($statuses as $chart_status):
                                    $status_name = $chart_status['name'];
                                    $status_count = $project_status_counts[$status_name] ?? 0;
                                    if ($status_count > 0 && $overall_total > 0):
                                        $status_percent = ($status_count / $overall_total);
                                        $status_dash = $circumference * $status_percent;
                                        $chart_data[] = [
                                            'name' => $status_name,
                                            'count' => $status_count,
                                            'percent' => $status_percent,
                                            'dash' => $status_dash,
                                            'offset' => $start_offset,
                                            'color' => $chart_status['color']
                                        ];
                                        $start_offset -= $status_dash;
                                    endif;
                                endforeach;
                                
                                // Draw background circle
                                ?>
                                <circle cx="80" cy="80" r="<?php echo $radius; ?>" fill="none" stroke="#E5E7EB" stroke-width="<?php echo $stroke_width; ?>"/>
                                
                                <?php
                                // Draw donut segments
                                foreach ($chart_data as $index => $segment):
                                    $status_color = ltrim($segment['color'], '#');
                                ?>
                                <circle cx="80" cy="80" r="<?php echo $radius; ?>" 
                                        fill="none" 
                                        stroke="#<?php echo htmlspecialchars($status_color); ?>" 
                                        stroke-width="<?php echo $stroke_width; ?>"
                                        stroke-dasharray="<?php echo $segment['dash']; ?> <?php echo $circumference; ?>"
                                        stroke-dashoffset="<?php echo $segment['offset']; ?>"
                                        stroke-linecap="round"
                                        style="cursor: pointer; transition: opacity 0.2s, stroke-width 0.2s;"
                                        data-status="<?php echo htmlspecialchars($segment['name']); ?>"
                                        data-count="<?php echo $segment['count']; ?>"
                                        data-percent="<?php echo round($segment['percent'] * 100, 1); ?>"
                                        onmouseover="this.style.opacity='0.8'; this.style.strokeWidth='<?php echo $stroke_width + 2; ?>'; showTooltip(event, '<?php echo htmlspecialchars($segment['name']); ?>', <?php echo $segment['count']; ?>, <?php echo round($segment['percent'] * 100, 1); ?>)"
                                        onmouseout="this.style.opacity='1'; this.style.strokeWidth='<?php echo $stroke_width; ?>'; hideTooltip()"/>
                                <?php endforeach; ?>
                            </svg>
                            <div class="position-absolute" style="top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; pointer-events: none;">
                                <div class="text-3xl font-bold text-primary" style="line-height: 1.2;"><?php echo $overall_total; ?></div>
                                <div class="text-xs text-secondary tracking-tight" style="text-transform: uppercase; margin-top: 4px;">Total Tasks</div>
                            </div>
                        </div>
                    </div>
                    
                </div>
                
                <script>
                // Tooltip element (will be created dynamically)
                let statusTooltip = null;
                
                function showTooltip(event, name, count, percent) {
                    if (!statusTooltip) {
                        statusTooltip = document.createElement('div');
                        statusTooltip.id = 'statusTooltip';
                        statusTooltip.style.cssText = 'position: fixed; background: rgba(0, 0, 0, 0.9); color: white; padding: 10px 14px; border-radius: 8px; font-size: 12px; pointer-events: none; z-index: 10000; display: none; box-shadow: 0 4px 12px rgba(0,0,0,0.4); white-space: nowrap;';
                        statusTooltip.innerHTML = '<div style="font-weight: 600; margin-bottom: 4px;" id="tooltipName"></div><div style="font-size: 11px; opacity: 0.9;" id="tooltipDetails"></div>';
                        document.body.appendChild(statusTooltip);
                    }
                    
                    const tooltipName = document.getElementById('tooltipName');
                    const tooltipDetails = document.getElementById('tooltipDetails');
                    
                    if (tooltipName) tooltipName.textContent = name;
                    if (tooltipDetails) tooltipDetails.textContent = count + ' tasks (' + percent + '%)';
                    
                    statusTooltip.style.display = 'block';
                    updateTooltipPosition(event);
                }
                
                function hideTooltip() {
                    if (statusTooltip) {
                        statusTooltip.style.display = 'none';
                    }
                }
                
                function updateTooltipPosition(event) {
                    if (!statusTooltip) return;
                    const x = event.clientX + 15;
                    const y = event.clientY + 15;
                    statusTooltip.style.left = x + 'px';
                    statusTooltip.style.top = y + 'px';
                }
                
                // Update tooltip position on mouse move
                document.addEventListener('mousemove', function(e) {
                    if (statusTooltip && statusTooltip.style.display === 'block') {
                        updateTooltipPosition(e);
                    }
                });
                </script>    
            <!-- Left: Progress Bars + Bar Chart Combined -->
            <div>
                    <h3 class="chart-title mb-16">Task Distribution</h3>
                    <!-- Progress Bars -->
                    <div style="margin-bottom: 24px;">
                        <?php foreach ($statuses as $status): 
                            $status_name = $status['name'];
                            $status_count = $project_status_counts[$status_name] ?? 0;
                            $status_color = $status['color'];
                            $status_percentage = $overall_total > 0 ? ($status_count / $overall_total * 100) : 0;
                            $show_text_inside = $status_percentage >= 15;
                        ?>
                        <div style="margin-bottom: 12px;">
                            <div style="position: relative; height: 25px; background: #E5E7EB;  overflow: hidden;">
                                <div style="position: relative; height: 100%; background: <?php echo htmlspecialchars($status_color); ?>; width: <?php echo $status_percentage; ?>%; transition: width 0.3s ease; <?php echo $show_text_inside ? 'display: flex; align-items: center; padding: 0 12px;' : ''; ?>">
                                    <?php if ($show_text_inside): ?>
                                        <span style="font-size: 10px; font-weight: 500; color: white; white-space: nowrap;">
                                            <?php echo htmlspecialchars($status_name); ?> (<?php echo $status_count; ?>)
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$show_text_inside): ?>
                                    <!-- Show text outside if bar is too narrow -->
                                    <div style="position: absolute; top: 50%; left: 12px; transform: translateY(-50%); font-size: 12px; font-weight: 600; color: var(--text-primary); pointer-events: none; z-index: 1;">
                                        <?php echo htmlspecialchars($status_name); ?> (<?php echo $status_count; ?>)
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
               
                
                <!-- Right: Area Line Chart - Task Trends -->
                <div>
                    <h3 style="font-size: 13px; font-weight: 600; color: var(--text-secondary); margin: 0 0 16px 0; text-transform: uppercase; letter-spacing: 0.5px;">Task Trends (7 Days)</h3>
                    <div style="height: 200px; position: relative; background: var(--page-bg); border-radius: 8px; padding: 16px; border: 1px solid var(--border-color);">
                        <?php 
                        // Get task counts for last 7 days for selected project only
                        $chart_conn = getDBConnection();
                        $chart_data = [];
                        $chart_labels = [];
                        for ($i = 6; $i >= 0; $i--) {
                            $date = date('Y-m-d', strtotime("-$i days"));
                            $chart_labels[] = date('M d', strtotime($date));
                            $date_start = $date . ' 00:00:00';
                            $date_end = $date . ' 23:59:59';
                            
                            $count_query = "SELECT COUNT(*) as count FROM tasks t";
                            $count_where = ["t.created_at BETWEEN '$date_start' AND '$date_end'"];
                            
                            // Filter by selected project if one is selected
                            if ($selected_project_id) {
                                $count_where[] = "t.project_id = $selected_project_id";
                            } else {
                                // Apply role-based filtering if no project selected
                                if (!isSuperAdmin()) {
                                    $org_id = getOrganizationId();
                                    if ($org_id) {
                                        $count_query .= " LEFT JOIN projects p ON t.project_id = p.id";
                                        $count_where[] = "p.organization_id = $org_id";
                                    }
                                }
                            }
                            
                            if (!empty($count_where)) {
                                $count_query .= " WHERE " . implode(" AND ", $count_where);
                            }
                            
                            $count_result = $chart_conn->query($count_query);
                            $chart_data[] = $count_result ? $count_result->fetch_assoc()['count'] : 0;
                        }
                        $chart_conn->close();
                        
                        $max_value = max($chart_data) > 0 ? max($chart_data) : 1;
                        $area_points = "M 0,160 ";
                        $line_points = "M ";
                        $chart_width = 100;
                        $chart_height = 140;
                        
                        for ($i = 0; $i < 7; $i++) {
                            $x = ($i / 6) * $chart_width;
                            $y = $chart_height - (($chart_data[$i] / $max_value) * ($chart_height - 20));
                            if ($i == 0) {
                                $line_points .= "$x,$y ";
                                $area_points .= "$x,$y ";
                            } else {
                                $line_points .= "L $x,$y ";
                                $area_points .= "L $x,$y ";
                            }
                        }
                        $area_points .= "L $chart_width,$chart_height L 0,$chart_height Z";
                        ?>
                        <svg width="100%" height="100%" viewBox="0 0 <?php echo $chart_width + 20; ?> <?php echo $chart_height + 20; ?>" preserveAspectRatio="none" style="overflow: visible;">
                            <defs>
                                <linearGradient id="areaGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                                    <stop offset="0%" style="stop-color:var(--blue);stop-opacity:0.25" />
                                    <stop offset="100%" style="stop-color:var(--blue);stop-opacity:0" />
                                </linearGradient>
                            </defs>
                            <path d="<?php echo $area_points; ?>" fill="url(#areaGradient)" />
                            <path d="<?php echo $line_points; ?>" fill="none" stroke="var(--blue)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <?php for ($i = 0; $i < 7; $i++): 
                                $x = ($i / 6) * $chart_width;
                                $y = $chart_height - (($chart_data[$i] / $max_value) * ($chart_height - 20));
                            ?>
                                <circle cx="<?php echo $x; ?>" cy="<?php echo $y; ?>" r="3" fill="var(--blue)" stroke="white" stroke-width="2"/>
                            <?php endfor; ?>
                        </svg>
                        <div style="position: absolute; bottom: 0; left: 0; right: 0; display: flex; justify-content: space-between; padding: 8px 0; font-size: 10px; color: var(--text-muted);">
                            <?php foreach ($chart_labels as $label): ?>
                                <span><?php echo $label; ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Additional Dashboard Sections -->
        <?php if ($selected_project_id): ?>
        <div class="dashboard-additional-sections d-grid" style="grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 24px;">
            <!-- Left Column: Upcoming Deadlines -->
            <div style="display: flex; flex-direction: column; gap: 24px;">
                <!-- Upcoming Deadlines -->
                <div class="premium-card">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                        <h3 style="font-size: 16px; font-weight: 600; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-calendar-alt" style="color: var(--chart-red); font-size: 14px;"></i>
                            <span>Upcoming Deadlines</span>
                        </h3>
                        <span style="font-size: 12px; color: var(--text-secondary);">Next 7 days</span>
                    </div>
                    <?php if (empty($upcoming_deadlines)): ?>
                        <div style="text-align: center; padding: 32px 16px; color: var(--text-muted);">
                            <i class="fas fa-check-circle" style="font-size: 32px; margin-bottom: 8px; opacity: 0.5;"></i>
                            <p style="font-size: 14px; margin: 0;">No upcoming deadlines</p>
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
                                   style="display: flex; align-items: center; gap: 12px; padding: 12px; background: var(--page-bg); border-radius: 8px; border: 1px solid var(--border-color); text-decoration: none; transition: all 0.2s;"
                                   onmouseover="this.style.borderColor='var(--blue)'; this.style.boxShadow='0 2px 8px rgba(59, 130, 246, 0.1)';"
                                   onmouseout="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';">
                                    <div style="min-width: 60px; text-align: center; padding: 8px; background: <?php echo $urgency_color; ?>15; border-radius: 6px; border: 1px solid <?php echo $urgency_color; ?>40;">
                                        <div style="font-size: 18px; font-weight: 700; color: <?php echo $urgency_color; ?>; line-height: 1;">
                                            <?php echo date('d', $due_date); ?>
                                        </div>
                                        <div style="font-size: 10px; color: var(--text-secondary); text-transform: uppercase; margin-top: 2px;">
                                            <?php echo date('M', $due_date); ?>
                                        </div>
                                    </div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px; line-height: 1.3;">
                                            <?php echo htmlspecialchars($deadline['title']); ?>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12px; color: var(--text-secondary);">
                                            <?php if ($deadline['assignee_name']): ?>
                                                <span><i class="fas fa-user" style="font-size: 10px;"></i> <?php echo htmlspecialchars($deadline['assignee_name']); ?></span>
                                            <?php endif; ?>
                                            <?php if ($deadline['status_name']): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 4px;">
                                                    <span style="width: 8px; height: 8px; background: <?php echo htmlspecialchars($deadline['status_color'] ?? '#6b7280'); ?>; border-radius: 50%;"></span>
                                                    <?php echo htmlspecialchars($deadline['status_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="font-size: 11px; font-weight: 600; color: <?php echo $urgency_color; ?>; white-space: nowrap;">
                                        <?php echo $urgency_text; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Right Column: Project Completion -->
            <div style="display: flex; flex-direction: column; gap: 24px;">
                <!-- Project Completion Percentage -->
                <div class="premium-card">
                    <div style="margin-bottom: 16px;">
                        <h3 style="font-size: 16px; font-weight: 600; color: var(--text-primary); margin: 0 0 8px 0; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-chart-pie" style="color: var(--chart-green); font-size: 14px;"></i>
                            <span>Project Completion</span>
                        </h3>
                        <p style="font-size: 12px; color: var(--text-secondary); margin: 0;">Overall progress indicator</p>
                    </div>
                    <div style="display: flex; flex-direction: column; align-items: center; padding: 24px;">
                        <div style="position: relative; width: 160px; height: 160px; margin-bottom: 20px;">
                            <svg width="160" height="160" viewBox="0 0 160 160" style="transform: rotate(-90deg);">
                                <circle cx="80" cy="80" r="70" fill="none" stroke="#E5E7EB" stroke-width="12"/>
                                <circle cx="80" cy="80" r="70" fill="none" 
                                        stroke="var(--chart-green)" 
                                        stroke-width="12" 
                                        stroke-dasharray="<?php echo 2 * M_PI * 70 * ($project_completion / 100); ?> <?php echo 2 * M_PI * 70; ?>" 
                                        stroke-dashoffset="0"
                                        stroke-linecap="round"/>
                            </svg>
                            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                                <div style="font-size: 36px; font-weight: 700; color: var(--text-primary); line-height: 1.2;">
                                    <?php echo $project_completion; ?>%
                                </div>
                                <div style="font-size: 12px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px;">
                                    Complete
                                </div>
                            </div>
                        </div>
                        <div style="width: 100%; text-align: center; padding-top: 16px; border-top: 1px solid var(--border-color);">
                            <div style="display: flex; justify-content: space-around; gap: 16px;">
                                <div style="text-align: center;">
                                    <div style="font-size: 20px; font-weight: 700; color: var(--text-primary);"><?php echo $overall_total; ?></div>
                                    <div style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px;">Total Tasks</div>
                                </div>
                                <div style="text-align: center;">
                                    <?php 
                                    $done_count = 0;
                                    foreach ($statuses as $status) {
                                        $status_name_lower = strtolower($status['name']);
                                        if (strpos($status_name_lower, 'done') !== false || 
                                            strpos($status_name_lower, 'complete') !== false ||
                                            strpos($status_name_lower, 'closed') !== false) {
                                            $done_count += $project_status_counts[$status['name']] ?? 0;
                                        }
                                    }
                                    ?>
                                    <div style="font-size: 20px; font-weight: 700; color: var(--chart-green);"><?php echo $done_count; ?></div>
                                    <div style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px;">Completed</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!$selected_project && !empty($projects)): ?>
            <!-- Empty State: No Project Selected -->
            <div class="premium-card" style="margin-top: 24px; text-align: center; padding: 48px 24px;">
                <i class="fas fa-folder-open" style="font-size: 48px; color: var(--text-muted); margin-bottom: 16px; opacity: 0.5;"></i>
                <h3 style="font-size: 18px; font-weight: 600; color: var(--text-primary); margin: 0 0 8px 0;">Select a Project</h3>
                <p style="font-size: 14px; color: var(--text-secondary); margin: 0;">Choose a project from the left sidebar to view its details and statistics</p>
            </div>
        <?php endif; ?>
        
        <!-- Last Activity Section -->
        <?php if (!empty($projects) && $last_activity): ?>
            <div class="premium-card" style="margin-top: 24px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                    <h3 style="font-size: 16px; font-weight: 600; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-clock" style="color: var(--blue); font-size: 14px;"></i>
                        <span>Last Activity</span>
                    </h3>
                </div>
                <div style="display: flex; align-items: flex-start; gap: 12px; padding: 16px; background: var(--page-bg); border-radius: 8px; border: 1px solid var(--border-color);">
                    <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%); display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: white; font-weight: 600; font-size: 14px;">
                        <?php 
                        $user_initials = '';
                        if (!empty($last_activity['user_name'])) {
                            $name_parts = explode(' ', $last_activity['user_name']);
                            if (count($name_parts) >= 2) {
                                $user_initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts) - 1], 0, 1));
                            } else {
                                $user_initials = strtoupper(substr($last_activity['user_name'], 0, 2));
                            }
                        }
                        echo htmlspecialchars($user_initials);
                        ?>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap;">
                            <span style="font-size: 14px; font-weight: 600; color: var(--text-primary);">
                                <?php echo htmlspecialchars($last_activity['user_name'] ?? 'Unknown User'); ?>
                            </span>
                            <span style="font-size: 13px; color: var(--text-secondary);">
                                <?php 
                                $action_text = $last_activity['action'] ?? 'performed an action';
                                if (!empty($last_activity['old_value']) && !empty($last_activity['new_value'])) {
                                    $action_text .= ' from "' . htmlspecialchars($last_activity['old_value']) . '" to "' . htmlspecialchars($last_activity['new_value']) . '"';
                                }
                                echo htmlspecialchars($action_text);
                                ?>
                            </span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px; flex-wrap: wrap;">
                            <?php if (!empty($last_activity['task_title'])): ?>
                                <a href="task_view?id=<?php echo $last_activity['task_id']; ?>" 
                                   style="font-size: 13px; color: var(--blue); text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 4px;">
                                    <i class="fas fa-tasks" style="font-size: 11px;"></i>
                                    <span><?php echo htmlspecialchars($last_activity['task_title']); ?></span>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($last_activity['project_name']) && !$selected_project): ?>
                                <span style="font-size: 12px; color: var(--text-muted);">•</span>
                                <span style="font-size: 12px; color: var(--text-secondary);">
                                    <i class="fas fa-folder" style="font-size: 10px; margin-right: 4px;"></i>
                                    <?php echo htmlspecialchars($last_activity['project_name']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-muted);">
                            <i class="fas fa-clock" style="font-size: 10px;"></i>
                            <span><?php echo timeAgo($last_activity['created_at'] ?? ''); ?></span>
                            <span style="margin: 0 4px;">•</span>
                            <span><?php echo !empty($last_activity['created_at']) ? date('M d, Y h:i A', strtotime($last_activity['created_at'])) : ''; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Motivational Quotes Section -->
        <?php if (!empty($projects)): ?>
        <div class="premium-card" style="margin-top: 24px;">
            <div style="display: flex; align-items: center; gap: 16px; padding: 20px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i class="fas fa-lightbulb" style="color: white; font-size: 20px;"></i>
                </div>
                <div style="flex: 1;">
                    <?php 
                    // Array of motivational quotes
                    $quotes = [
                        [
                            'text' => 'The way to get started is to quit talking and begin doing.',
                            'author' => 'Walt Disney'
                        ],
                        [
                            'text' => 'Productivity is never an accident. It is always the result of a commitment to excellence, intelligent planning, and focused effort.',
                            'author' => 'Paul J. Meyer'
                        ],
                        [
                            'text' => 'Success is the sum of small efforts repeated day in and day out.',
                            'author' => 'Robert Collier'
                        ],
                        [
                            'text' => 'The secret of getting ahead is getting started.',
                            'author' => 'Mark Twain'
                        ],
                        [
                            'text' => 'You don\'t have to be great to start, but you have to start to be great.',
                            'author' => 'Zig Ziglar'
                        ],
                        [
                            'text' => 'Focus on being productive instead of busy.',
                            'author' => 'Tim Ferriss'
                        ],
                        [
                            'text' => 'The future depends on what you do today.',
                            'author' => 'Mahatma Gandhi'
                        ],
                        [
                            'text' => 'Done is better than perfect.',
                            'author' => 'Sheryl Sandberg'
                        ]
                    ];
                    
                    // Select a quote based on day of year for consistency (changes daily)
                    $quote_index = (intval(date('z')) + intval(date('Y'))) % count($quotes);
                    $selected_quote = $quotes[$quote_index];
                    ?>
                    <div style="font-size: 15px; font-weight: 500; color: var(--text-primary); line-height: 1.6; margin-bottom: 6px; font-style: italic;">
                        "<?php echo htmlspecialchars($selected_quote['text']); ?>"
                    </div>
                    <div style="font-size: 12px; color: var(--text-secondary); font-weight: 500;">
                        — <?php echo htmlspecialchars($selected_quote['author']); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; // End of projects check for motivational quotes ?>
        <?php endif; // End of main if (empty($projects)) check ?>
    </div>
</div>

<!-- Add Task Modal -->
<div id="addTaskModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);">
    <div class="modal-content" style="background-color: var(--card-bg); margin: 5% auto; padding: 0; border: 1px solid var(--border-color); border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div class="modal-header" style="display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid var(--border-color);">
            <h2 style="margin: 0; font-size: 20px; font-weight: 600; color: var(--text-primary);">Add New Task</h2>
            <button class="close" onclick="closeAddTaskModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted); padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">&times;</button>
        </div>
        <form method="POST" action="tasks" id="addTaskForm">
            <input type="hidden" name="create_task" value="1">
            <div class="modal-body" style="padding: 24px;">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Name <span style="color: var(--chart-red);">*</span></label>
                    <input type="text" name="title" required 
                           style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; box-sizing: border-box;"
                           placeholder="Enter task name">
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Description</label>
                    <textarea name="description" rows="4" 
                              style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; resize: vertical; box-sizing: border-box; font-family: inherit;"
                              placeholder="Enter task description"></textarea>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Type</label>
                    <select name="type" required 
                            style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; background: white; box-sizing: border-box;">
                        <option value="Task">Task</option>
                        <option value="Bug">Bug</option>
                        <option value="Improvement">Improvement</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Project <span style="color: var(--chart-red);">*</span></label>
                    <select name="project_id" required 
                            style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; background: white; box-sizing: border-box;">
                        <option value="">Select Project</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?php echo $proj['id']; ?>" <?php echo ($selected_project_id == $proj['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($proj['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Priority</label>
                    <select name="priority" 
                            style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; background: white; box-sizing: border-box;">
                        <option value="Low">Low</option>
                        <option value="Medium" selected>Medium</option>
                        <option value="High">High</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Assignee</label>
                    <select name="assignee_id" 
                            style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; background: white; box-sizing: border-box;">
                        <option value="">Unassigned</option>
                        <?php foreach ($users_list as $user): ?>
                            <option value="<?php echo $user['id']; ?>">
                                <?php echo htmlspecialchars($user['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Due Date</label>
                    <input type="date" name="due_date" 
                           style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; box-sizing: border-box;">
                </div>
                
                <input type="hidden" name="status" value="To Do">
            </div>
            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" onclick="closeAddTaskModal()" 
                        style="padding: 10px 20px; border: 1px solid var(--border-color); background: white; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; color: var(--text-primary); transition: all 0.2s;">
                    Cancel
                </button>
                <button type="submit" 
                        style="padding: 10px 20px; border: none; background: var(--blue); color: white; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.2s;">
                    Create Task
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Add Task Modal Functions
function openAddTaskModal() {
    const modal = document.getElementById('addTaskModal');
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
    } else {
        console.error('Add Task Modal not found');
    }
}

function closeAddTaskModal() {
    const modal = document.getElementById('addTaskModal');
    const form = document.getElementById('addTaskForm');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = ''; // Restore scrolling
    }
    if (form) {
        form.reset();
        // Re-select the project if it was pre-selected
        <?php if ($selected_project_id): ?>
        const projectSelect = form.querySelector('select[name="project_id"]');
        if (projectSelect) {
            projectSelect.value = '<?php echo $selected_project_id; ?>';
        }
        <?php endif; ?>
    }
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('addTaskModal');
    if (modal && event.target == modal) {
        closeAddTaskModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modal = document.getElementById('addTaskModal');
        if (modal && modal.style.display === 'block') {
            closeAddTaskModal();
        }
        const projectModal = document.getElementById('addProjectModal');
        if (projectModal && projectModal.style.display === 'block') {
            closeAddProjectModal();
        }
    }
});
</script>

<!-- Add Project Modal - Only for admins -->
<?php if (isSuperAdmin() || isOrgAdmin() || isAdmin()): ?>
<div id="addProjectModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);">
    <div class="modal-content" style="background-color: var(--card-bg); margin: 5% auto; padding: 0; border: 1px solid var(--border-color); border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div class="modal-header" style="display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid var(--border-color);">
            <h2 style="margin: 0; font-size: 20px; font-weight: 600; color: var(--text-primary);">Add New Project</h2>
            <button class="close" onclick="closeAddProjectModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted); padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">&times;</button>
        </div>
        <form method="POST" action="" id="addProjectForm">
            <input type="hidden" name="create_project" value="1">
            <div class="modal-body" style="padding: 24px;">
                <?php if ($error): ?>
                    <div style="padding: 12px; background: #fee2e2; border: 1px solid #fecaca; border-radius: 6px; color: #991b1b; margin-bottom: 20px; font-size: 14px;">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Project Name <span style="color: var(--chart-red);">*</span></label>
                    <input type="text" name="name" required 
                           style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; box-sizing: border-box;"
                           placeholder="Enter project name">
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Description</label>
                    <textarea name="description" rows="4" 
                              style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; resize: vertical; box-sizing: border-box; font-family: inherit;"
                              placeholder="Enter project description"></textarea>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Status <span style="color: var(--chart-red);">*</span></label>
                    <select name="status" required 
                            style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; background: white; box-sizing: border-box;">
                        <option value="Active" selected>Active</option>
                        <option value="On Hold">On Hold</option>
                        <option value="Completed">Completed</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Project Manager</label>
                    <select name="project_manager_id" 
                            style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; background: white; box-sizing: border-box;">
                        <option value="">None</option>
                        <?php foreach ($pm_users as $user): ?>
                            <option value="<?php echo $user['id']; ?>">
                                <?php echo htmlspecialchars($user['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-primary); font-size: 14px;">Team Members</label>
                    <select name="team_members[]" multiple 
                            style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; background: white; box-sizing: border-box; height: 120px;">
                        <?php foreach ($all_users_for_project as $user): ?>
                            <option value="<?php echo $user['id']; ?>">
                                <?php echo htmlspecialchars($user['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: var(--text-muted); font-size: 12px; margin-top: 4px; display: block;">Hold Ctrl/Cmd to select multiple</small>
                </div>
            </div>
            <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" onclick="closeAddProjectModal()" 
                        style="padding: 10px 20px; border: 1px solid var(--border-color); background: white; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; color: var(--text-primary); transition: all 0.2s;">
                    Cancel
                </button>
                <button type="submit" 
                        style="padding: 10px 20px; border: none; background: var(--blue); color: white; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.2s;">
                    Create Project
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Add Project Modal Functions - Only for admins
function openAddProjectModal() {
    <?php if (!isSuperAdmin() && !isOrgAdmin() && !isAdmin()): ?>
        alert('Only administrators can create projects. Please request project assignment or contact support.');
        return;
    <?php endif; ?>
    const modal = document.getElementById('addProjectModal');
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
    } else {
        console.error('Add Project Modal not found');
    }
}

function closeAddProjectModal() {
    const modal = document.getElementById('addProjectModal');
    const form = document.getElementById('addProjectForm');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = ''; // Restore scrolling
    }
    if (form) {
        form.reset();
    }
}

// Close project modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('addProjectModal');
    if (modal && event.target == modal) {
        closeAddProjectModal();
    }
});

// Mobile Sidebar Toggle
function toggleMobileSidebar() {
    const sidebar = document.getElementById('dashboardSidebar');
    if (sidebar) {
        sidebar.classList.toggle('mobile-open');
    }
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('dashboardSidebar');
    const toggleBtn = document.querySelector('.mobile-sidebar-toggle');
    
    if (window.innerWidth <= 768 && sidebar && toggleBtn) {
        if (!sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
            sidebar.classList.remove('mobile-open');
        }
    }
});

// Show/hide mobile sidebar toggle button based on screen size
function handleMobileSidebarToggle() {
    const toggleBtn = document.querySelector('.mobile-sidebar-toggle');
    if (toggleBtn) {
        if (window.innerWidth <= 768) {
            toggleBtn.classList.add('show');
        } else {
            toggleBtn.classList.remove('show');
            const sidebar = document.getElementById('dashboardSidebar');
            if (sidebar) {
                sidebar.classList.remove('mobile-open');
            }
        }
    }
}

// Handle window resize
window.addEventListener('resize', handleMobileSidebarToggle);
handleMobileSidebarToggle();

// Mobile Project Selector Change Handler
function changeProjectMobile(projectId) {
    if (projectId) {
        window.location.href = 'dashboard?project_id=' + projectId;
    } else {
        window.location.href = 'dashboard';
    }
}
</script>

<?php 
// Close connection after all queries are done
if (isset($conn) && $conn) {
    try {
        // Only close if connection is still valid
        if (is_object($conn) && method_exists($conn, 'close')) {
            $conn->close();
        }
    } catch (Exception $e) {
        // Connection already closed or error occurred, ignore silently
    } catch (Error $e) {
        // Handle PHP 7+ Error exceptions (like "mysqli object is already closed")
        // Ignore silently
    }
}
include 'includes/footer.php'; 
?>
