<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Configuration
require_once __DIR__ . '/database.php';

// Environment Configuration
// Change to 'production' when deploying to live server
define('ENVIRONMENT', 'development'); // Options: 'development' or 'production'

// Site Configuration
if (ENVIRONMENT === 'production') {
    // Production Settings
    define('SITE_NAME', 'Task Flow System'); // Change to your actual site name
    define('SITE_URL', 'https://taskflow.ayodhyakashiyatra.com'); // Change to your actual domain
} else {
    // Development Settings
    define('SITE_NAME', 'Task Flow System');
    define('SITE_URL', 'http://localhost/jira');
}

// Timezone
// Change to your server's timezone (e.g., 'America/New_York', 'Asia/Dubai', 'Europe/London')
date_default_timezone_set('UTC');

// Error Reporting (disable in production)
if (ENVIRONMENT === 'production') {
    error_reporting(0);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Helper Functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUserRole() {
    return $_SESSION['role_name'] ?? null;
}

function getOrganizationId() {
    return $_SESSION['organization_id'] ?? null;
}

function isSuperAdmin() {
    return getUserRole() === 'Super Admin';
}

function isAdmin() {
    return getUserRole() === 'Admin';
}

function isOrgAdmin() {
    return isAdmin() && !isSuperAdmin();
}

function isProjectManager() {
    return getUserRole() === 'Project Manager';
}

function isTeamMember() {
    return getUserRole() === 'Team Member';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../index');
        exit();
    }
}

function requireAdmin() {
    requireLogin();
    if (!isSuperAdmin() && !isOrgAdmin()) {
        header('Location: ../dashboard');
        exit();
    }
}

function requireProjectManager() {
    requireLogin();
    if (!isSuperAdmin() && !isOrgAdmin() && !isProjectManager()) {
        header('Location: ../dashboard');
        exit();
    }
}

// Helper function to normalize status for display (maps 'Done' to 'Closed' for backward compatibility)
function normalizeStatusForDisplay($status) {
    if ($status == 'Done') {
        return 'Closed';
    }
    return $status;
}

// Helper function to normalize status for database (maps 'Closed' to 'Done' if database still uses 'Done')
function normalizeStatusForDatabase($status) {
    // For now, we'll use 'Closed' directly. If database ENUM still has 'Done', 
    // uncomment the line below after running the migration script
    // if ($status == 'Closed') return 'Done';
    return $status;
}

function formatDate($date) {
    if (empty($date)) return '-';
    return date('M d, Y', strtotime($date));
}

function formatDateTime($datetime) {
    if (empty($datetime)) return '-';
    return date('M d, Y h:i A', strtotime($datetime));
}

function checkSubscriptionStatus($organization_id = null) {
    if (isSuperAdmin()) {
        return ['status' => 'active', 'message' => 'Super Admin - Full Access'];
    }
    
    if (!$organization_id) {
        $organization_id = getOrganizationId();
    }
    
    if (!$organization_id) {
        return ['status' => 'expired', 'message' => 'No organization found'];
    }
    
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT subscription_status, trial_end_date, subscription_end_date FROM organizations WHERE id = ?");
    $stmt->bind_param("i", $organization_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $org = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    if (!$org) {
        return ['status' => 'expired', 'message' => 'Organization not found'];
    }
    
    $today = date('Y-m-d');
    $status = $org['subscription_status'];
    
    // Check if trial has expired
    if ($status == 'trial' && $org['trial_end_date'] && $org['trial_end_date'] < $today) {
        // Update status to expired
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE organizations SET subscription_status = 'expired' WHERE id = ?");
        $stmt->bind_param("i", $organization_id);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        return ['status' => 'expired', 'message' => 'Trial period expired. Please subscribe to continue.', 'expiry_date' => $org['trial_end_date']];
    }
    
    // Check if subscription has expired
    if ($status == 'active' && $org['subscription_end_date'] && $org['subscription_end_date'] < $today) {
        // Update status to expired
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE organizations SET subscription_status = 'expired' WHERE id = ?");
        $stmt->bind_param("i", $organization_id);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        return ['status' => 'expired', 'message' => 'Subscription expired. Please renew to continue.', 'expiry_date' => $org['subscription_end_date']];
    }
    
    if ($status == 'expired') {
        return ['status' => 'expired', 'message' => 'Subscription expired. Please subscribe to continue.'];
    }
    
    if ($status == 'cancelled') {
        return ['status' => 'cancelled', 'message' => 'Subscription cancelled. Please reactivate to continue.'];
    }
    
    // Active or trial
    $expiry_date = $status == 'trial' ? $org['trial_end_date'] : $org['subscription_end_date'];
    $days_left = $expiry_date ? (strtotime($expiry_date) - strtotime($today)) / 86400 : 0;
    
    return [
        'status' => $status,
        'message' => $status == 'trial' ? "Trial period active. {$days_left} days remaining." : "Subscription active. {$days_left} days remaining.",
        'expiry_date' => $expiry_date,
        'days_left' => $days_left
    ];
}

function requireActiveSubscription() {
    requireLogin();
    
    if (isSuperAdmin()) {
        return; // Super admin always has access
    }
    
    $subscription = checkSubscriptionStatus();
    
    if ($subscription['status'] == 'expired' || $subscription['status'] == 'cancelled') {
        header('Location: subscription?expired=1');
        exit();
    }
}

// Load Cloudinary configuration if available
if (file_exists(__DIR__ . '/cloudinary.php')) {
    require_once __DIR__ . '/cloudinary.php';
}
?>
