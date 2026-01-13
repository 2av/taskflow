<?php
// Environment Configuration
// Automatically detects environment based on URL
// Local URLs (localhost, 127.0.0.1, .local, .test) = development
// All other URLs = production

// Automatically detect environment based on URL
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$is_local = (
    $host === 'localhost' ||
    $host === '127.0.0.1' ||
    strpos($host, 'localhost:') === 0 ||
    strpos($host, '127.0.0.1:') === 0 ||
    strpos($host, '.local') !== false ||
    strpos($host, '.test') !== false ||
    filter_var($host, FILTER_VALIDATE_IP) !== false && in_array($host, ['127.0.0.1', '::1'])
);

$environment = $is_local ? 'development' : 'production';

// Database Configuration
if ($environment === 'production') {
    define('DB_HOST', 'localhost:3306');
    define('DB_USER', 'ayodhya5_taskflow');
    define('DB_PASS', '_349Obq6f');
    define('DB_NAME', 'ayodhya5_taskflow');
} else {
    // Development Database Settings (XAMPP default)
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'taskflow');
}

// Create connection
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Create database if it doesn't exist
    $sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
    $conn->query($sql);
    
    // Select database
    $conn->select_db(DB_NAME);
    
    return $conn;
}
?>
