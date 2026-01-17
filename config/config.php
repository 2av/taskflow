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

// Helper function to get statuses for an organization
function getStatuses($organization_id = null) {
    if ($organization_id === null) {
        $organization_id = getOrganizationId();
    }
    
    $conn = getDBConnection();
    
    // Get organization-specific statuses, or global defaults if none exist
    $query = "SELECT * FROM statuses WHERE organization_id " . ($organization_id ? "= ?" : "IS NULL") . " ORDER BY display_order ASC, name ASC";
    $stmt = $conn->prepare($query);
    if ($organization_id) {
        $stmt->bind_param("i", $organization_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $statuses = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // If no organization-specific statuses, get global defaults
    if (empty($statuses) && $organization_id) {
        $query = "SELECT * FROM statuses WHERE organization_id IS NULL ORDER BY display_order ASC, name ASC";
        $result = $conn->query($query);
        $statuses = $result->fetch_all(MYSQLI_ASSOC);
    }
    
    $conn->close();
    return $statuses;
}

// Helper function to build status count SQL for queries
function buildStatusCountSQL($statuses, $prefix = '') {
    $cases = [];
    foreach ($statuses as $status) {
        $status_name = $status['name'];
        $status_key = strtolower(str_replace(' ', '_', $status_name));
        $escaped_name = addslashes($status_name);
        $cases[] = "SUM(CASE WHEN {$prefix}status = '{$escaped_name}' THEN 1 ELSE 0 END) as {$status_key}_count";
    }
    return implode(",\n               ", $cases);
}

// Helper function to get status by name for an organization
function getStatusByName($status_name, $organization_id = null) {
    if ($organization_id === null) {
        $organization_id = getOrganizationId();
    }
    
    $conn = getDBConnection();
    
    // Try organization-specific first
    if ($organization_id) {
        $stmt = $conn->prepare("SELECT * FROM statuses WHERE organization_id = ? AND name = ?");
        $stmt->bind_param("is", $organization_id, $status_name);
        $stmt->execute();
        $result = $stmt->get_result();
        $status = $result->fetch_assoc();
        $stmt->close();
        
        if ($status) {
            $conn->close();
            return $status;
        }
    }
    
    // Fallback to global defaults
    $stmt = $conn->prepare("SELECT * FROM statuses WHERE organization_id IS NULL AND name = ?");
    $stmt->bind_param("s", $status_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $status = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    return $status;
}

// Helper function to normalize status for display (backward compatibility)
function normalizeStatusForDisplay($status) {
    if ($status == 'Closed') {
        return 'Done';
    }
    return $status;
}

// Helper function to normalize status for database (backward compatibility)
function normalizeStatusForDatabase($status) {
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

// Load upload system (local storage)
if (file_exists(__DIR__ . '/upload.php')) {
    require_once __DIR__ . '/upload.php';
}

/**
 * Get image URL from filename (GUID) - Helper function available everywhere
 * Uses local storage only
 * 
 * @param string $filename GUID filename from database
 * @param string $type Type: 'profile' or 'organization'
 * @return string|null Full image URL or null if empty
 */
function getImageUrl($filename, $type = 'profile') {
    if (empty($filename)) {
        return null;
    }
    
    // If it's already a full URL, return as is (backward compatibility)
    if (filter_var($filename, FILTER_VALIDATE_URL)) {
        return $filename;
    }
    
    // Use local storage helper
    if (function_exists('getImageUrlLocal')) {
        return getImageUrlLocal($filename, $type);
    }
    
    // Fallback to local path
    $local_folder = $type === 'organization' ? 'uploads/organizations' : 'uploads/profiles';
    return $local_folder . '/' . $filename;
}
?>
