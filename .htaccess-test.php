<?php
/**
 * .htaccess Test File
 * 
 * This file helps diagnose .htaccess issues on your server.
 * Access it via: https://taskflow.ayodhyakashiyatra.com/.htaccess-test.php
 * 
 * After testing, DELETE this file for security!
 */

echo "<h1>.htaccess Configuration Test</h1>";

// Test 1: Check if mod_rewrite is enabled
if (function_exists('apache_get_modules')) {
    $modules = apache_get_modules();
    $mod_rewrite_enabled = in_array('mod_rewrite', $modules);
    echo "<h2>Test 1: mod_rewrite Module</h2>";
    echo $mod_rewrite_enabled ? 
        "<p style='color: green;'>✓ mod_rewrite is ENABLED</p>" : 
        "<p style='color: red;'>✗ mod_rewrite is DISABLED - Contact your hosting provider to enable it</p>";
} else {
    echo "<h2>Test 1: mod_rewrite Module</h2>";
    echo "<p style='color: orange;'>⚠ Cannot check mod_rewrite status (function not available)</p>";
}

// Test 2: Check .htaccess file exists
echo "<h2>Test 2: .htaccess File</h2>";
if (file_exists('.htaccess')) {
    echo "<p style='color: green;'>✓ .htaccess file exists</p>";
    echo "<pre>" . htmlspecialchars(file_get_contents('.htaccess')) . "</pre>";
} else {
    echo "<p style='color: red;'>✗ .htaccess file NOT FOUND</p>";
}

// Test 3: Check server software
echo "<h2>Test 3: Server Information</h2>";
echo "<p><strong>Server Software:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "</p>";
echo "<p><strong>Document Root:</strong> " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "</p>";
echo "<p><strong>Script Name:</strong> " . ($_SERVER['SCRIPT_NAME'] ?? 'Unknown') . "</p>";
echo "<p><strong>Request URI:</strong> " . ($_SERVER['REQUEST_URI'] ?? 'Unknown') . "</p>";

// Test 4: Check if rewrite is working
echo "<h2>Test 4: URL Rewrite Test</h2>";
echo "<p>Try accessing these URLs:</p>";
echo "<ul>";
echo "<li><a href='dashboard'>dashboard</a> (should work if rewrite is working)</li>";
echo "<li><a href='dashboard.php'>dashboard.php</a> (should also work)</li>";
echo "<li><a href='tasks?project_id=1'>tasks?project_id=1</a> (should work with query string)</li>";
echo "</ul>";

// Test 5: Check file permissions
echo "<h2>Test 5: File Permissions</h2>";
$htaccess_perms = fileperms('.htaccess');
echo "<p><strong>.htaccess permissions:</strong> " . substr(sprintf('%o', $htaccess_perms), -4) . "</p>";
echo "<p style='color: " . ($htaccess_perms & 0444 ? 'green' : 'orange') . ";'>";
echo ($htaccess_perms & 0444) ? "✓ Readable" : "⚠ May not be readable";
echo "</p>";

echo "<hr>";
echo "<p><strong style='color: red;'>IMPORTANT: Delete this file after testing for security!</strong></p>";
?>
