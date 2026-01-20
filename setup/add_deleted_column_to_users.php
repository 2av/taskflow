<?php
/**
 * Migration script to add deleted column to users table for soft delete functionality
 * Run this script once to add the deleted column
 */

require_once __DIR__ . '/../config/database.php';

$conn = getDBConnection();

// Check if column already exists
$check_column = $conn->query("SHOW COLUMNS FROM users LIKE 'deleted'");
if ($check_column->num_rows == 0) {
    // Add deleted column
    $sql = "ALTER TABLE users ADD COLUMN deleted TINYINT(1) DEFAULT 0 NOT NULL AFTER status";
    
    if ($conn->query($sql)) {
        echo "Successfully added 'deleted' column to users table.\n";
        echo "Soft delete functionality is now enabled.\n";
    } else {
        echo "Error adding column: " . $conn->error . "\n";
    }
} else {
    echo "Column 'deleted' already exists in users table.\n";
}

$conn->close();
?>
