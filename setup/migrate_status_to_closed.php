<?php
/**
 * Migration Script: Update task status from 'Done' to 'Closed'
 * 
 * This script updates:
 * 1. All existing 'Done' status tasks to 'Closed'
 * 2. The database ENUM to use 'Closed' instead of 'Done'
 * 
 * Run this script once to migrate your database.
 */

require_once '../config/database.php';
require_once '../config/config.php';

echo "Starting migration: Update task status from 'Done' to 'Closed'...\n\n";

try {
    $conn = getDBConnection();
    
    if (!$conn) {
        die("Error: Could not connect to database\n");
    }
    
    // Step 1: Update all existing 'Done' status to 'Closed'
    echo "Step 1: Updating existing tasks with 'Done' status to 'Closed'...\n";
    $update_result = $conn->query("UPDATE tasks SET status = 'Closed' WHERE status = 'Done'");
    if ($update_result) {
        $affected = $conn->affected_rows;
        echo "✓ Updated $affected tasks from 'Done' to 'Closed'\n\n";
    } else {
        echo "✗ Error updating tasks: " . $conn->error . "\n\n";
    }
    
    // Step 2: Modify the ENUM column to use 'Closed' instead of 'Done'
    echo "Step 2: Updating database ENUM to use 'Closed' instead of 'Done'...\n";
    
    // First, check current ENUM values
    $enum_check = $conn->query("SHOW COLUMNS FROM tasks WHERE Field = 'status'");
    if ($enum_check && $row = $enum_check->fetch_assoc()) {
        $type = $row['Type'];
        echo "Current status column type: $type\n";
        
        // Modify the ENUM
        $alter_query = "ALTER TABLE tasks MODIFY COLUMN status ENUM('To Do', 'In Progress', 'Closed') DEFAULT 'To Do'";
        $alter_result = $conn->query($alter_query);
        
        if ($alter_result) {
            echo "✓ Successfully updated status ENUM to ('To Do', 'In Progress', 'Closed')\n\n";
        } else {
            echo "✗ Error updating ENUM: " . $conn->error . "\n";
            echo "Note: You may need to manually update the ENUM using:\n";
            echo "ALTER TABLE tasks MODIFY COLUMN status ENUM('To Do', 'In Progress', 'Closed') DEFAULT 'To Do';\n\n";
        }
    } else {
        echo "✗ Could not read status column information\n\n";
    }
    
    // Step 3: Update activity logs that reference 'Done'
    echo "Step 3: Updating activity logs that reference 'Done' status...\n";
    $log_update = $conn->query("UPDATE activity_logs SET old_value = 'Closed' WHERE old_value = 'Done' AND action = 'Status changed'");
    $log_update2 = $conn->query("UPDATE activity_logs SET new_value = 'Closed' WHERE new_value = 'Done' AND action = 'Status changed'");
    if ($log_update && $log_update2) {
        $affected1 = $conn->affected_rows;
        $conn->query("UPDATE activity_logs SET new_value = 'Closed' WHERE new_value = 'Done' AND action = 'Status changed'");
        $affected2 = $conn->affected_rows;
        echo "✓ Updated activity logs (old_value: $affected1 rows, new_value: $affected2 rows)\n\n";
    }
    
    echo "Migration completed successfully!\n";
    echo "All tasks and database schema have been updated to use 'Closed' instead of 'Done'.\n";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
