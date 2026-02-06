<?php
/**
 * Creates task cost schema: total_cost on tasks + task_cost_transactions table.
 * Run once or let task_view.php create them automatically when missing.
 */
require_once __DIR__ . '/../config/database.php';

$conn = getDBConnection();

// Add total_cost column to tasks if missing
$chk = $conn->query("SHOW COLUMNS FROM tasks LIKE 'total_cost'");
if ($chk && $chk->num_rows === 0) {
    $conn->query("ALTER TABLE tasks ADD COLUMN total_cost DECIMAL(12,2) DEFAULT NULL COMMENT 'Total task cost'");
}

// Create task_cost_transactions table
$conn->query("
CREATE TABLE IF NOT EXISTS task_cost_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    type ENUM('payment','expense','transfer_in') NOT NULL COMMENT 'payment=amount received, expense=generic, transfer_in=from another task',
    amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    source_task_id INT DEFAULT NULL COMMENT 'For transfer_in: the task the amount came from',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (source_task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
)
");

$conn->close();
echo "Task cost schema ready.\n";
