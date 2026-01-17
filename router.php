<?php
/**
 * PHP Router - Fallback Solution for IIS without URL Rewrite Module
 * 
 * This file handles clean URLs when web.config URL Rewrite is not available.
 * 
 * HOW TO USE:
 * 1. Rename this file to index.php (backup your existing index.php first!)
 * 2. Update the $routes array below with your pages
 * 3. Access URLs like: /dashboard, /tasks?project_id=1, etc.
 * 
 * IMPORTANT: This is a fallback. If URL Rewrite module works, use web.config instead.
 */

// Get the requested path
$request_uri = $_SERVER['REQUEST_URI'];
$request_path = parse_url($request_uri, PHP_URL_PATH);
$query_string = parse_url($request_uri, PHP_URL_QUERY);

// Remove leading slash
$request_path = ltrim($request_path, '/');

// If empty, default to dashboard
if (empty($request_path) || $request_path === '/') {
    $request_path = 'dashboard';
}

// Remove query string from path for file checking
$path_without_query = $request_path;
if (strpos($path_without_query, '?') !== false) {
    $path_without_query = substr($path_without_query, 0, strpos($path_without_query, '?'));
}

// Check if .php file exists
$php_file = $path_without_query . '.php';

if (file_exists($php_file)) {
    // Include the PHP file
    $_SERVER['SCRIPT_NAME'] = '/' . $php_file;
    $_SERVER['PHP_SELF'] = '/' . $php_file;
    
    // Preserve query string
    if (!empty($query_string)) {
        parse_str($query_string, $_GET);
    }
    
    // Include the actual PHP file
    require $php_file;
    exit;
} else {
    // File not found - show 404
    http_response_code(404);
    die('404 - File not found: ' . htmlspecialchars($request_path));
}
?>
