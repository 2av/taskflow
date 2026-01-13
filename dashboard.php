<?php
require_once 'config/config.php';
requireLogin();
requireActiveSubscription();

$page_title = 'Dashboard';

$conn = getDBConnection();

// Get projects with task statistics
if (isSuperAdmin()) {
    // Super Admin sees all projects
    $projects_query = "
        SELECT p.*, 
               COUNT(t.id) as total_tasks,
               SUM(CASE WHEN t.status = 'To Do' THEN 1 ELSE 0 END) as todo_count,
               SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as inprogress_count,
               SUM(CASE WHEN t.status = 'Done' THEN 1 ELSE 0 END) as done_count
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
               SUM(CASE WHEN t.status = 'To Do' THEN 1 ELSE 0 END) as todo_count,
               SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as inprogress_count,
               SUM(CASE WHEN t.status = 'Done' THEN 1 ELSE 0 END) as done_count
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
               SUM(CASE WHEN t.status = 'To Do' THEN 1 ELSE 0 END) as todo_count,
               SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as inprogress_count,
               SUM(CASE WHEN t.status = 'Done' THEN 1 ELSE 0 END) as done_count
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
               SUM(CASE WHEN t.status = 'To Do' THEN 1 ELSE 0 END) as todo_count,
               SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as inprogress_count,
               SUM(CASE WHEN t.status = 'Done' THEN 1 ELSE 0 END) as done_count
        FROM projects p
        LEFT JOIN tasks t ON p.id = t.project_id
        LEFT JOIN project_users pu ON p.id = pu.project_id
        WHERE pu.user_id = $user_id
        GROUP BY p.id
        ORDER BY p.name
    ";
    $projects = $conn->query($projects_query)->fetch_all(MYSQLI_ASSOC);
}

$conn->close();

include 'includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Dashboard</h1>
            <p class="mt-1 text-sm text-gray-500">Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</p>
        </div>
    </div>

    <!-- Projects Grid -->
    <?php if (empty($projects)): ?>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-12 text-center">
            <i class="fas fa-folder-open text-6xl text-gray-300 mb-4"></i>
            <p class="text-gray-500 text-lg">No projects found</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($projects as $project): ?>
                <?php 
                $total = intval($project['total_tasks']);
                $done = intval($project['done_count']);
                $todo = intval($project['todo_count']);
                $inprogress = intval($project['inprogress_count']);
                $progress_percent = $total > 0 ? round(($done / $total) * 100) : 0;
                ?>
                <a href="tasks.php?project_id=<?php echo $project['id']; ?>" class="block group">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 hover:shadow-lg hover:border-teal-300 transition-all duration-300 h-full flex flex-col overflow-hidden">
                        <!-- Card Header -->
                        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                            <div class="flex items-center gap-3 flex-1 min-w-0">
                                <i class="fas fa-folder-open text-teal-600 text-xl flex-shrink-0"></i>
                                <h3 class="text-lg font-semibold text-gray-900 truncate group-hover:text-teal-600 transition-colors">
                                    <?php echo htmlspecialchars($project['name']); ?>
                                </h3>
                            </div>
                            <span class="px-2.5 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800 flex-shrink-0 ml-2">
                                <?php echo htmlspecialchars($project['status']); ?>
                            </span>
                        </div>
                        
                        <!-- Card Body -->
                        <div class="px-6 py-5 flex-1 flex flex-col">
                            <!-- Stats Grid -->
                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div class="bg-gray-50 rounded-lg p-3 text-center">
                                    <div class="text-2xl font-bold text-gray-900"><?php echo $total; ?></div>
                                    <div class="text-xs text-gray-500 mt-1">Total Tasks</div>
                                </div>
                                <div class="bg-yellow-50 rounded-lg p-3 text-center">
                                    <div class="text-2xl font-bold text-yellow-600"><?php echo $todo; ?></div>
                                    <div class="text-xs text-gray-500 mt-1">To Do</div>
                                </div>
                                <div class="bg-blue-50 rounded-lg p-3 text-center">
                                    <div class="text-2xl font-bold text-blue-600"><?php echo $inprogress; ?></div>
                                    <div class="text-xs text-gray-500 mt-1">In Progress</div>
                                </div>
                                <div class="bg-green-50 rounded-lg p-3 text-center">
                                    <div class="text-2xl font-bold text-green-600"><?php echo $done; ?></div>
                                    <div class="text-xs text-gray-500 mt-1">Done</div>
                                </div>
                            </div>
                            
                            <!-- Progress Bar -->
                            <?php if ($total > 0): ?>
                                <div class="mt-auto pt-4 border-t border-gray-100">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-sm font-medium text-gray-700">Progress</span>
                                        <span class="text-sm font-semibold text-gray-900"><?php echo $progress_percent; ?>%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
                                        <div class="bg-gradient-to-r from-teal-500 to-teal-600 h-2.5 rounded-full transition-all duration-500" style="width: <?php echo $progress_percent; ?>%"></div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="mt-auto pt-4 border-t border-gray-100">
                                    <p class="text-sm text-gray-400 text-center">No tasks yet</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
