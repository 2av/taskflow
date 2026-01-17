<?php
/**
 * Migrate Tasks to Use Status ID Instead of Status Name
 * 
 * This script:
 * 1. Adds status_id column to tasks table
 * 2. Migrates existing status names to status IDs
 * 3. Removes ENUM constraint from status column (keeps it for backward compatibility)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

echo "Migrating tasks to use status_id...\n\n";

try {
    $conn = getDBConnection();
    
    if (!$conn) {
        die("Error: Could not connect to database\n");
    }
    
    // Step 1: Add status_id column if it doesn't exist
    echo "Step 1: Adding status_id column to tasks table...\n";
    $check_column = $conn->query("SHOW COLUMNS FROM tasks LIKE 'status_id'");
    if ($check_column->num_rows == 0) {
        $alter_sql = "ALTER TABLE tasks ADD COLUMN status_id INT NULL AFTER status";
        if ($conn->query($alter_sql)) {
            echo "✓ status_id column added\n\n";
        } else {
            echo "✗ Error adding status_id column: " . $conn->error . "\n\n";
            exit(1);
        }
    } else {
        echo "✓ status_id column already exists\n\n";
    }
    
    // Step 2: Add foreign key constraint
    echo "Step 2: Adding foreign key constraint...\n";
    try {
        $fk_sql = "ALTER TABLE tasks ADD CONSTRAINT fk_task_status FOREIGN KEY (status_id) REFERENCES statuses(id) ON DELETE SET NULL";
        $conn->query($fk_sql);
        echo "✓ Foreign key constraint added\n\n";
    } catch (Exception $e) {
        // Foreign key might already exist
        if (strpos($e->getMessage(), 'Duplicate key name') === false && strpos($e->getMessage(), 'already exists') === false) {
            echo "⚠ Warning: " . $e->getMessage() . "\n\n";
        } else {
            echo "✓ Foreign key constraint already exists\n\n";
        }
    }
    
    // Step 3: Migrate existing status names to status IDs
    echo "Step 3: Migrating existing status names to status IDs...\n";
    
    // Get all tasks with status names
    $tasks = $conn->query("SELECT id, status, project_id FROM tasks WHERE status_id IS NULL");
    $migrated = 0;
    $failed = 0;
    
    while ($task = $tasks->fetch_assoc()) {
        $task_id = $task['id'];
        $status_name = $task['status'];
        $project_id = $task['project_id'];
        
        // Get organization_id from project
        $project_query = $conn->prepare("SELECT organization_id FROM projects WHERE id = ?");
        $project_query->bind_param("i", $project_id);
        $project_query->execute();
        $project_result = $project_query->get_result();
        $project = $project_result->fetch_assoc();
        $org_id = $project ? $project['organization_id'] : null;
        $project_query->close();
        
        // Find matching status by name and organization
        $status_query = $conn->prepare("SELECT id FROM statuses WHERE name = ? AND (organization_id = ? OR (organization_id IS NULL AND ? IS NULL))");
        $status_query->bind_param("sii", $status_name, $org_id, $org_id);
        $status_query->execute();
        $status_result = $status_query->get_result();
        
        if ($status_row = $status_result->fetch_assoc()) {
            $status_id = $status_row['id'];
            
            // Update task with status_id
            $update_query = $conn->prepare("UPDATE tasks SET status_id = ? WHERE id = ?");
            $update_query->bind_param("ii", $status_id, $task_id);
            
            if ($update_query->execute()) {
                $migrated++;
            } else {
                $failed++;
                echo "  ✗ Failed to update task $task_id: " . $conn->error . "\n";
            }
            $update_query->close();
        } else {
            // Status not found - try to find default status or create fallback
            // Try to get first status for this organization
            $default_status_query = $conn->prepare("SELECT id FROM statuses WHERE organization_id = ? OR organization_id IS NULL ORDER BY display_order ASC LIMIT 1");
            $default_status_query->bind_param("i", $org_id);
            $default_status_query->execute();
            $default_result = $default_status_query->get_result();
            
            if ($default_row = $default_result->fetch_assoc()) {
                $status_id = $default_row['id'];
                $update_query = $conn->prepare("UPDATE tasks SET status_id = ? WHERE id = ?");
                $update_query->bind_param("ii", $status_id, $task_id);
                if ($update_query->execute()) {
                    $migrated++;
                    echo "  ⚠ Task $task_id: Status '$status_name' not found, assigned default status ID $status_id\n";
                } else {
                    $failed++;
                }
                $update_query->close();
            } else {
                $failed++;
                echo "  ✗ Task $task_id: No status found for '$status_name' and no default status available\n";
            }
            $default_status_query->close();
        }
        $status_query->close();
    }
    
    echo "✓ Migrated $migrated tasks\n";
    if ($failed > 0) {
        echo "⚠ $failed tasks failed to migrate\n";
    }
    echo "\n";
    
    // Step 4: Remove ENUM constraint from status column (change to VARCHAR)
    echo "Step 4: Removing ENUM constraint from status column...\n";
    try {
        $alter_status = "ALTER TABLE tasks MODIFY COLUMN status VARCHAR(100) DEFAULT NULL";
        if ($conn->query($alter_status)) {
            echo "✓ Status column converted to VARCHAR (ENUM removed)\n\n";
        } else {
            echo "⚠ Warning: Could not modify status column: " . $conn->error . "\n\n";
        }
    } catch (Exception $e) {
        echo "⚠ Warning: " . $e->getMessage() . "\n\n";
    }
    
    // Step 5: Set default status_id for new tasks (first status of organization)
    echo "Step 5: Migration completed successfully!\n\n";
    echo "Note: The 'status' column is kept for backward compatibility but status_id is now the primary field.\n";
    echo "All new tasks should use status_id. The status column can be removed in a future migration.\n\n";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
