<?php
if (!isset($page_title)) {
    $page_title = 'Dashboard';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <div class="nav-brand">
                <a href="dashboard.php">
                    <?php echo SITE_NAME; ?>
                    <?php 
                    // Show organization name in brackets if user belongs to an organization
                    if (!empty($_SESSION['organization_id']) && !isSuperAdmin()) {
                        $conn = getDBConnection();
                        $org_stmt = $conn->prepare("SELECT name FROM organizations WHERE id = ?");
                        $org_stmt->bind_param("i", $_SESSION['organization_id']);
                        $org_stmt->execute();
                        $org_result = $org_stmt->get_result();
                        if ($org_row = $org_result->fetch_assoc()) {
                            echo ' <span style="font-size: 0.7em; font-weight: normal; opacity: 0.7;">(' . htmlspecialchars($org_row['name']) . ')</span>';
                        }
                        $org_stmt->close();
                        $conn->close();
                    }
                    ?>
                </a>
            </div>
            <div class="nav-menu" id="navMenu">
                <a href="dashboard.php" class="nav-link" title="Dashboard"><i class="fas fa-home nav-icon"></i><span class="nav-text">Dashboard</span></a>
                <?php if (isSuperAdmin() || isOrgAdmin() || isProjectManager()): ?>
                    <a href="projects.php" class="nav-link" title="Projects"><i class="fas fa-folder nav-icon"></i><span class="nav-text">Projects</span></a>
                <?php endif; ?>
                <?php if (isSuperAdmin() || isOrgAdmin()): ?>
                    <a href="users.php" class="nav-link" title="Users"><i class="fas fa-users nav-icon"></i><span class="nav-text">Users</span></a>
                <?php endif; ?>
            </div>
            <!-- Profile Circle with Dropdown -->
            <div class="relative" id="profileDropdown">
                <?php 
                // Get user initials for avatar
                $full_name = $_SESSION['full_name'];
                $name_parts = explode(' ', $full_name);
                $initials = '';
                if (count($name_parts) >= 2) {
                    $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts) - 1], 0, 1));
                } else {
                    $initials = strtoupper(substr($full_name, 0, 2));
                }
                
                // Get organization name if exists
                $org_name = '';
                if (!empty($_SESSION['organization_id'])) {
                    $conn = getDBConnection();
                    $org_stmt = $conn->prepare("SELECT name FROM organizations WHERE id = ?");
                    $org_stmt->bind_param("i", $_SESSION['organization_id']);
                    $org_stmt->execute();
                    $org_result = $org_stmt->get_result();
                    if ($org_row = $org_result->fetch_assoc()) {
                        $org_name = $org_row['name'];
                    }
                    $org_stmt->close();
                    $conn->close();
                }
                ?>
                <button class="profile-circle-btn" id="profileBtn" onclick="toggleProfileDropdown()">
                    <span class="profile-initials"><?php echo htmlspecialchars($initials); ?></span>
                </button>
                
                <!-- Dropdown Menu -->
                <div class="profile-dropdown" id="profileDropdownMenu">
                    <div class="profile-dropdown-header">
                        <div class="profile-dropdown-avatar">
                            <span class="profile-initials-large"><?php echo htmlspecialchars($initials); ?></span>
                        </div>
                        <div class="profile-dropdown-info">
                            <div class="profile-dropdown-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div class="profile-dropdown-role"><?php echo htmlspecialchars($_SESSION['role_name']); ?></div>
                            <?php if (!empty($org_name)): ?>
                                <div class="profile-dropdown-org"><?php echo htmlspecialchars($org_name); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="profile-dropdown-divider"></div>
                    <div class="profile-dropdown-menu">
                        <?php if (isOrgAdmin() && !empty($_SESSION['organization_id'])): ?>
                            <a href="subscription.php" class="profile-dropdown-item">
                                <i class="fas fa-credit-card"></i>
                                <span>Subscription</span>
                            </a>
                        <?php endif; ?>
                        <a href="change_password.php" class="profile-dropdown-item">
                            <i class="fas fa-key"></i>
                            <span>Change Password</span>
                        </a>
                        <div class="profile-dropdown-divider"></div>
                        <a href="logout.php" class="profile-dropdown-item profile-dropdown-item-danger">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    
    <div class="main-container">
        <div class="content-wrapper">
