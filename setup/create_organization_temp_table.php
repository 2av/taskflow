<?php
/**
 * Create Organization Temporary Table and OTP System
 * 
 * This script creates:
 * 1. organization_temp table for pending registrations
 * 2. Updates organizations table to include account_status field
 * 3. Creates OTP system for email verification
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

echo "Creating organization temporary table and OTP system...\n\n";

try {
    $conn = getDBConnection();
    
    if (!$conn) {
        die("Error: Could not connect to database\n");
    }
    
    // Step 1: Create organization_temp table
    echo "Step 1: Creating organization_temp table...\n";
    $sql_temp = "CREATE TABLE IF NOT EXISTS organization_temp (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        email VARCHAR(100) NOT NULL,
        otp_code VARCHAR(6) NOT NULL,
        otp_expires_at DATETIME NOT NULL,
        account_status ENUM('PENDING_VERIFICATION', 'VERIFIED', 'COMPLETED') DEFAULT 'PENDING_VERIFICATION',
        email_verified TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_otp (otp_code),
        INDEX idx_status (account_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if ($conn->query($sql_temp)) {
        echo "✓ organization_temp table created successfully\n\n";
    } else {
        echo "✗ Error creating organization_temp table: " . $conn->error . "\n\n";
        exit(1);
    }
    
    // Step 2: Add account_status to organizations table if it doesn't exist
    echo "Step 2: Adding account_status column to organizations table...\n";
    $check_column = $conn->query("SHOW COLUMNS FROM organizations LIKE 'account_status'");
    if ($check_column->num_rows == 0) {
        $alter_sql = "ALTER TABLE organizations ADD COLUMN account_status ENUM('PENDING_VERIFICATION', 'VERIFIED', 'INACTIVE', 'ACTIVE') DEFAULT 'INACTIVE' AFTER status";
        if ($conn->query($alter_sql)) {
            echo "✓ account_status column added\n\n";
        } else {
            echo "⚠ Warning: Could not add account_status column: " . $conn->error . "\n\n";
        }
    } else {
        echo "✓ account_status column already exists\n\n";
    }
    
    // Step 3: Add email_verified to organizations table if it doesn't exist
    echo "Step 3: Adding email_verified column to organizations table...\n";
    $check_column = $conn->query("SHOW COLUMNS FROM organizations LIKE 'email_verified'");
    if ($check_column->num_rows == 0) {
        $alter_sql = "ALTER TABLE organizations ADD COLUMN email_verified TINYINT(1) DEFAULT 0 AFTER email";
        if ($conn->query($alter_sql)) {
            echo "✓ email_verified column added\n\n";
        } else {
            echo "⚠ Warning: Could not add email_verified column: " . $conn->error . "\n\n";
        }
    } else {
        echo "✓ email_verified column already exists\n\n";
    }
    
    // Step 4: Add additional organization fields if they don't exist
    echo "Step 4: Adding additional organization detail fields...\n";
    $additional_fields = [
        'phone' => "VARCHAR(50)",
        'address' => "TEXT",
        'city' => "VARCHAR(100)",
        'state' => "VARCHAR(100)",
        'country' => "VARCHAR(100)",
        'postal_code' => "VARCHAR(20)",
        'industry' => "VARCHAR(100)",
        'website' => "VARCHAR(255)"
    ];
    
    foreach ($additional_fields as $field => $type) {
        $check_column = $conn->query("SHOW COLUMNS FROM organizations LIKE '$field'");
        if ($check_column->num_rows == 0) {
            $alter_sql = "ALTER TABLE organizations ADD COLUMN $field $type";
            if ($conn->query($alter_sql)) {
                echo "✓ $field column added\n";
            } else {
                echo "⚠ Warning: Could not add $field column: " . $conn->error . "\n";
            }
        } else {
            echo "✓ $field column already exists\n";
        }
    }
    echo "\n";
    
    echo "✓ Migration completed successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Update register_organization.php to use organization_temp table\n";
    echo "2. Create verify_otp.php for email verification\n";
    echo "3. Create complete_organization.php for organization details completion\n";
    echo "4. Update set_password.php to activate accounts\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

$conn->close();
?>
