<?php
/**
 * Create Statuses Table
 * 
 * This script creates a statuses table that allows organizations to:
 * - Have default statuses (To Do, In Progress, Done)
 * - Add custom statuses
 * - Rename default statuses (organization-specific)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

echo "Creating statuses table...\n\n";

try {
    $conn = getDBConnection();
    
    if (!$conn) {
        die("Error: Could not connect to database\n");
    }
    
    // Create statuses table
    $sql_statuses = "CREATE TABLE IF NOT EXISTS statuses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        organization_id INT NULL,
        name VARCHAR(100) NOT NULL,
        display_order INT DEFAULT 0,
        is_default TINYINT(1) DEFAULT 0,
        color VARCHAR(50) DEFAULT '#6c757d',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        UNIQUE KEY unique_org_status (organization_id, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if ($conn->query($sql_statuses)) {
        echo "✓ Statuses table created successfully\n\n";
    } else {
        echo "✗ Error creating statuses table: " . $conn->error . "\n\n";
        exit(1);
    }
    
    // Populate default statuses for all existing organizations
    echo "Populating default statuses for existing organizations...\n";
    
    $orgs = $conn->query("SELECT id FROM organizations");
    $default_statuses = [
        ['name' => 'To Do', 'display_order' => 1, 'color' => '#ffc107'],
        ['name' => 'In Progress', 'display_order' => 2, 'color' => '#17a2b8'],
        ['name' => 'Done', 'display_order' => 3, 'color' => '#6c757d']
    ];
    
    $inserted = 0;
    while ($org = $orgs->fetch_assoc()) {
        $org_id = $org['id'];
        
        foreach ($default_statuses as $status) {
            // Check if status already exists
            $check = $conn->prepare("SELECT id FROM statuses WHERE organization_id = ? AND name = ?");
            $check->bind_param("is", $org_id, $status['name']);
            $check->execute();
            $result = $check->get_result();
            
            if ($result->num_rows == 0) {
                $stmt = $conn->prepare("INSERT INTO statuses (organization_id, name, display_order, is_default, color) VALUES (?, ?, ?, 1, ?)");
                $stmt->bind_param("isis", $org_id, $status['name'], $status['display_order'], $status['color']);
                if ($stmt->execute()) {
                    $inserted++;
                }
                $stmt->close();
            }
            $check->close();
        }
    }
    
    echo "✓ Inserted $inserted default statuses for organizations\n\n";
    
    // Also create global default statuses (for Super Admin or organizations without custom statuses)
    echo "Creating global default statuses...\n";
    foreach ($default_statuses as $status) {
        $check = $conn->prepare("SELECT id FROM statuses WHERE organization_id IS NULL AND name = ?");
        $check->bind_param("s", $status['name']);
        $check->execute();
        $result = $check->get_result();
        
        if ($result->num_rows == 0) {
            $stmt = $conn->prepare("INSERT INTO statuses (organization_id, name, display_order, is_default, color) VALUES (NULL, ?, ?, 1, ?)");
            $stmt->bind_param("sis", $status['name'], $status['display_order'], $status['color']);
            $stmt->execute();
            $stmt->close();
        }
        $check->close();
    }
    
    echo "✓ Global default statuses created\n\n";
    
    echo "Statuses table setup completed successfully!\n";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
