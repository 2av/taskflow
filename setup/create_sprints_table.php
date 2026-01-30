<?php
/**
 * Create Sprints Table and Add sprint_id to Tasks
 *
 * This script:
 * 1. Creates sprints table (project_id, name, goal, start_date, end_date, status, created_by)
 * 2. Adds sprint_id column to tasks table (nullable, FK to sprints)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

echo "Creating sprints feature...\n\n";

try {
    $conn = getDBConnection();

    if (!$conn) {
        die("Error: Could not connect to database\n");
    }

    // Step 1: Create sprints table
    echo "Step 1: Creating sprints table...\n";
    $sql_sprints = "CREATE TABLE IF NOT EXISTS sprints (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        name VARCHAR(200) NOT NULL,
        goal TEXT,
        start_date DATE,
        end_date DATE,
        status ENUM('planning', 'active', 'completed', 'closed') DEFAULT 'planning',
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )";
    if ($conn->query($sql_sprints)) {
        echo "✓ sprints table created\n\n";
    } else {
        echo "✗ Error creating sprints table: " . $conn->error . "\n\n";
        exit(1);
    }

    // Step 2: Add sprint_id to tasks if not exists
    echo "Step 2: Adding sprint_id column to tasks table...\n";
    $check_column = $conn->query("SHOW COLUMNS FROM tasks LIKE 'sprint_id'");
    if ($check_column->num_rows == 0) {
        $alter_sql = "ALTER TABLE tasks ADD COLUMN sprint_id INT NULL AFTER project_id";
        if ($conn->query($alter_sql)) {
            echo "✓ sprint_id column added\n\n";
        } else {
            echo "✗ Error adding sprint_id: " . $conn->error . "\n\n";
            exit(1);
        }
    } else {
        echo "✓ sprint_id column already exists\n\n";
    }

    // Step 3: Add foreign key from tasks.sprint_id to sprints.id
    echo "Step 3: Adding foreign key constraint...\n";
    $fk_check = $conn->query("
        SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'sprint_id'
    ");
    if ($fk_check && $fk_check->num_rows == 0) {
        $fk_sql = "ALTER TABLE tasks ADD CONSTRAINT fk_task_sprint FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE SET NULL";
        if ($conn->query($fk_sql)) {
            echo "✓ Foreign key constraint added\n\n";
        } else {
            echo "⚠ Foreign key may already exist or error: " . $conn->error . "\n\n";
        }
    } else {
        echo "✓ Foreign key constraint already exists\n\n";
    }

    $conn->close();
    echo "Sprint feature setup complete.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
