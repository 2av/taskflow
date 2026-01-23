<?php
/**
 * Dashboard Cache Management
 * Handles caching of dashboard data in session to reduce database calls
 */

// Cache expiration time in seconds (5 minutes)
define('DASHBOARD_CACHE_EXPIRY', 300);

/**
 * Get cached dashboard data or fetch fresh if expired/missing
 * 
 * @param string $cache_key Cache key (e.g., 'dashboard_projects', 'dashboard_stats')
 * @param callable $fetch_callback Function to fetch fresh data if cache is stale
 * @return mixed Cached data or fresh data from callback
 */
function getCachedDashboardData($cache_key, $fetch_callback) {
    // Initialize cache array if not exists
    if (!isset($_SESSION['dashboard_cache'])) {
        $_SESSION['dashboard_cache'] = [];
    }
    
    $cache = $_SESSION['dashboard_cache'];
    $now = time();
    
    // Check if cache exists and is still valid
    if (isset($cache[$cache_key]) && isset($cache[$cache_key]['timestamp'])) {
        $cache_age = $now - $cache[$cache_key]['timestamp'];
        
        // If cache is still fresh, return cached data
        if ($cache_age < DASHBOARD_CACHE_EXPIRY) {
            return $cache[$cache_key]['data'];
        }
    }
    
    // Cache is expired or doesn't exist, fetch fresh data
    $fresh_data = call_user_func($fetch_callback);
    
    // Store in cache with timestamp
    $_SESSION['dashboard_cache'][$cache_key] = [
        'data' => $fresh_data,
        'timestamp' => $now
    ];
    
    return $fresh_data;
}

/**
 * Invalidate dashboard cache
 * Call this when tasks/projects are created, updated, or deleted
 * 
 * @param string|null $cache_key Specific cache key to invalidate, or null to clear all
 */
function invalidateDashboardCache($cache_key = null) {
    if (!isset($_SESSION['dashboard_cache'])) {
        return;
    }
    
    if ($cache_key === null) {
        // Clear all dashboard cache
        $_SESSION['dashboard_cache'] = [];
    } else {
        // Clear specific cache key
        if (isset($_SESSION['dashboard_cache'][$cache_key])) {
            unset($_SESSION['dashboard_cache'][$cache_key]);
        }
    }
}

/**
 * Force refresh dashboard cache (ignore expiry)
 * 
 * @param string $cache_key Cache key to refresh
 * @param callable $fetch_callback Function to fetch fresh data
 * @return mixed Fresh data
 */
function refreshDashboardCache($cache_key, $fetch_callback) {
    // Remove existing cache
    invalidateDashboardCache($cache_key);
    
    // Fetch and cache fresh data
    return getCachedDashboardData($cache_key, $fetch_callback);
}

/**
 * Check if cache is stale
 * 
 * @param string $cache_key Cache key to check
 * @return bool True if cache is stale or doesn't exist
 */
function isDashboardCacheStale($cache_key) {
    if (!isset($_SESSION['dashboard_cache'][$cache_key])) {
        return true;
    }
    
    $cache = $_SESSION['dashboard_cache'][$cache_key];
    if (!isset($cache['timestamp'])) {
        return true;
    }
    
    $cache_age = time() - $cache['timestamp'];
    return $cache_age >= DASHBOARD_CACHE_EXPIRY;
}
