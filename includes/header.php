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
    <script>
        // Mobile Profile Dropdown Toggle
        function toggleMobileProfileDropdown() {
            const dropdown = document.getElementById('mobileProfileDropdown');
            const backdrop = document.getElementById('mobileProfileBackdrop');
            dropdown.classList.toggle('show');
            if (backdrop) {
                backdrop.classList.toggle('show');
            }
        }
        
        // Close mobile dropdown when clicking backdrop
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('mobileProfileDropdown');
            const backdrop = document.getElementById('mobileProfileBackdrop');
            const profileBtn = document.getElementById('mobileProfileBtn');
            
            if (dropdown && backdrop && profileBtn && 
                !dropdown.contains(event.target) && 
                !profileBtn.contains(event.target) &&
                backdrop.contains(event.target)) {
                dropdown.classList.remove('show');
                backdrop.classList.remove('show');
            }
        });
        
        // Highlight active mobile nav link
        document.addEventListener('DOMContentLoaded', function() {
            const currentPath = window.location.pathname;
            const mobileNavLinks = document.querySelectorAll('.mobile-nav-link');
            
            mobileNavLinks.forEach(link => {
                if (link.getAttribute('href')) {
                    const linkPath = link.getAttribute('href');
                    if (currentPath.includes(linkPath) || (currentPath === '/' && linkPath === 'dashboard')) {
                        link.classList.add('active');
                    }
                }
            });
        });
    </script>
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <div class="nav-brand">
                <a href="dashboard" class="nav-brand-link">
                    <?php 
                    // Get organization logo and name
                    $org_logo = null;
                    $org_name = null;
                    $show_logo = false;
                    
                    if (!empty($_SESSION['organization_id']) && !isSuperAdmin()) {
                        $conn = getDBConnection();
                        $org_stmt = $conn->prepare("SELECT name, logo FROM organizations WHERE id = ?");
                        $org_stmt->bind_param("i", $_SESSION['organization_id']);
                        $org_stmt->execute();
                        $org_result = $org_stmt->get_result();
                        if ($org_row = $org_result->fetch_assoc()) {
                            $org_name = $org_row['name'];
                            $org_logo = $org_row['logo'] ?? null;
                            $show_logo = true;
                        }
                        $org_stmt->close();
                        $conn->close();
                    }
                    
                    // Display logo if available, otherwise show default company logo
                    if ($show_logo) {
                        if (!empty($org_logo)) {
                            $logo_url = getImageUrl($org_logo, 'organization');
                            echo '<img src="' . htmlspecialchars($logo_url) . '" alt="' . htmlspecialchars($org_name ?? 'Organization') . '" class="nav-brand-logo" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'flex\';">';
                            echo '<div class="nav-brand-logo-default" style="display: none;"><i class="fas fa-building"></i></div>';
                        } else {
                            // Show default company logo if no logo uploaded
                            echo '<div class="nav-brand-logo-default"><i class="fas fa-building"></i></div>';
                        }
                    } else {
                        // For Super Admin or users without organization, show default logo
                        echo '<div class="nav-brand-logo-default"><i class="fas fa-building"></i></div>';
                    }
                    ?>
                    <span class="nav-brand-text">
                        <?php 
                        if ($org_name) {
                            echo htmlspecialchars($org_name);
                        } else {
                            echo SITE_NAME;
                        }
                        ?>
                    </span>
                </a>
            </div>
            <div class="nav-menu" id="navMenu">
                <a href="dashboard" class="nav-link" title="Dashboard"><i class="fas fa-home nav-icon"></i><span class="nav-text">Dashboard</span></a>
                <?php if (isSuperAdmin() || isOrgAdmin() || isProjectManager()): ?>
                    <a href="projects" class="nav-link" title="Projects"><i class="fas fa-folder nav-icon"></i><span class="nav-text">Projects</span></a>
                <?php endif; ?>
                <a href="tasks" class="nav-link" title="Tasks"><i class="fas fa-tasks nav-icon"></i><span class="nav-text">Tasks</span></a>
                <a href="calendar" class="nav-link" title="Calendar"><i class="fas fa-calendar-alt nav-icon"></i><span class="nav-text">Calendar</span></a>
                <?php if (isSuperAdmin() || isOrgAdmin() || isProjectManager()): ?>
                    <a href="reports" class="nav-link" title="Reports"><i class="fas fa-chart-bar nav-icon"></i><span class="nav-text">Reports</span></a>
                <?php endif; ?>
                <?php if (isSuperAdmin() || isOrgAdmin()): ?>
                    <a href="users" class="nav-link" title="Users"><i class="fas fa-users nav-icon"></i><span class="nav-text">Users</span></a>
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
                
                // Get user profile picture
                $profile_picture = null;
                $conn = getDBConnection();
                $user_stmt = $conn->prepare("SELECT profile_picture FROM users WHERE id = ?");
                $user_stmt->bind_param("i", $_SESSION['user_id']);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                if ($user_row = $user_result->fetch_assoc()) {
                    $profile_picture = $user_row['profile_picture'];
                }
                $user_stmt->close();
                
                // Get organization name if exists
                $org_name = '';
                if (!empty($_SESSION['organization_id'])) {
                    $org_stmt = $conn->prepare("SELECT name FROM organizations WHERE id = ?");
                    $org_stmt->bind_param("i", $_SESSION['organization_id']);
                    $org_stmt->execute();
                    $org_result = $org_stmt->get_result();
                    if ($org_row = $org_result->fetch_assoc()) {
                        $org_name = $org_row['name'];
                    }
                    $org_stmt->close();
                }
                $conn->close();
                
                // Get profile picture URL
                $profile_picture_url = !empty($profile_picture) ? getImageUrl($profile_picture, 'profile') : null;
                ?>
                <button class="profile-circle-btn" id="profileBtn" onclick="toggleProfileDropdown()">
                    <?php if ($profile_picture_url): ?>
                        <img src="<?php echo htmlspecialchars($profile_picture_url); ?>" 
                             alt="<?php echo htmlspecialchars($full_name); ?>" 
                             class="profile-picture-img"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <span class="profile-initials" style="display: none;"><?php echo htmlspecialchars($initials); ?></span>
                    <?php else: ?>
                        <span class="profile-initials"><?php echo htmlspecialchars($initials); ?></span>
                    <?php endif; ?>
                </button>
                
                <!-- Dropdown Menu -->
                <div class="profile-dropdown" id="profileDropdownMenu">
                    <div class="profile-dropdown-header">
                        <div class="profile-dropdown-avatar">
                            <?php if ($profile_picture_url): ?>
                                <img src="<?php echo htmlspecialchars($profile_picture_url); ?>" 
                                     alt="<?php echo htmlspecialchars($full_name); ?>" 
                                     class="profile-picture-large"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <span class="profile-initials-large" style="display: none;"><?php echo htmlspecialchars($initials); ?></span>
                            <?php else: ?>
                                <span class="profile-initials-large"><?php echo htmlspecialchars($initials); ?></span>
                            <?php endif; ?>
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
                        <a href="edit_profile" class="profile-dropdown-item">
                            <i class="fas fa-user-edit"></i>
                            <span>Edit Profile</span>
                        </a>
                        <?php if (isOrgAdmin() && !empty($_SESSION['organization_id'])): ?>
                            <a href="edit_organization" class="profile-dropdown-item">
                                <i class="fas fa-building"></i>
                                <span>Edit Organization</span>
                            </a>
                            <a href="manage_statuses" class="profile-dropdown-item">
                                <i class="fas fa-tags"></i>
                                <span>Manage Statuses</span>
                            </a>
                            <a href="subscription" class="profile-dropdown-item">
                                <i class="fas fa-credit-card"></i>
                                <span>Subscription</span>
                            </a>
                        <?php endif; ?>
                        <a href="change_password" class="profile-dropdown-item">
                            <i class="fas fa-key"></i>
                            <span>Change Password</span>
                        </a>
                        <div class="profile-dropdown-divider"></div>
                        <a href="logout" class="profile-dropdown-item profile-dropdown-item-danger">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Mobile Bottom Navigation -->
    <nav class="mobile-bottom-nav">
        <a href="dashboard" class="mobile-nav-link" title="Dashboard">
            <i class="fas fa-home"></i>
            <span>Dashboard</span>
        </a>
        <?php if (isSuperAdmin() || isOrgAdmin() || isProjectManager()): ?>
            <a href="projects" class="mobile-nav-link" title="Projects">
                <i class="fas fa-folder"></i>
                <span>Projects</span>
            </a>
        <?php endif; ?>
        <a href="tasks" class="mobile-nav-link" title="Tasks">
            <i class="fas fa-tasks"></i>
            <span>Tasks</span>
        </a>
        
        <button class="mobile-nav-link mobile-nav-profile" id="mobileProfileBtn" onclick="toggleMobileProfileDropdown()">
            <i class="fas fa-user-circle"></i>
            <span>Profile</span>
        </button>
    </nav>
    
    <!-- Mobile Profile Dropdown Backdrop -->
    <div class="mobile-profile-backdrop" id="mobileProfileBackdrop" onclick="toggleMobileProfileDropdown()"></div>
    
    <!-- Mobile Profile Dropdown -->
    <div class="mobile-profile-dropdown" id="mobileProfileDropdown">
        <div class="mobile-profile-header">
            <div class="mobile-profile-avatar">
                <?php if ($profile_picture_url): ?>
                    <img src="<?php echo htmlspecialchars($profile_picture_url); ?>" 
                         alt="<?php echo htmlspecialchars($full_name); ?>" 
                         class="mobile-profile-picture"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <span style="display: none;"><?php echo htmlspecialchars($initials); ?></span>
                <?php else: ?>
                    <span><?php echo htmlspecialchars($initials); ?></span>
                <?php endif; ?>
            </div>
            <div class="mobile-profile-info">
                <div class="mobile-profile-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                <div class="mobile-profile-role"><?php echo htmlspecialchars($_SESSION['role_name']); ?></div>
            </div>
            <button class="mobile-profile-close" onclick="toggleMobileProfileDropdown()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="mobile-profile-menu">
            <a href="edit_profile" class="mobile-profile-item">
                <i class="fas fa-user-edit"></i>
                <span>Edit Profile</span>
            </a>
            <?php if (isOrgAdmin() && !empty($_SESSION['organization_id'])): ?>
                <a href="edit_organization" class="mobile-profile-item">
                    <i class="fas fa-building"></i>
                    <span>Edit Organization</span>
                </a>
                <a href="manage_statuses" class="mobile-profile-item">
                    <i class="fas fa-tags"></i>
                    <span>Manage Statuses</span>
                </a>
                <a href="subscription" class="mobile-profile-item">
                    <i class="fas fa-credit-card"></i>
                    <span>Subscription</span>
                </a>
            <?php endif; ?>
            <a href="change_password" class="mobile-profile-item">
                <i class="fas fa-key"></i>
                <span>Change Password</span>
            </a>
            <a href="logout" class="mobile-profile-item mobile-profile-item-danger">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
    
    <div class="main-container">
        <div class="content-wrapper">
