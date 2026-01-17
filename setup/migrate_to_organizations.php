<?php
require_once '../config/database.php';
require_once '../config/config.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['migrate'])) {
    try {
        $conn = getDBConnection();
        
        // Check if organizations table exists
        $result = $conn->query("SHOW TABLES LIKE 'organizations'");
        if ($result->num_rows == 0) {
            // Create organizations table
            $sql_organizations = "CREATE TABLE IF NOT EXISTS organizations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                email VARCHAR(100),
                phone VARCHAR(50),
                address TEXT,
                status ENUM('active', 'inactive') DEFAULT 'active',
                subscription_status ENUM('trial', 'active', 'expired', 'cancelled') DEFAULT 'trial',
                trial_start_date DATE,
                trial_end_date DATE,
                subscription_start_date DATE,
                subscription_end_date DATE,
                subscription_plan VARCHAR(50),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            $conn->query($sql_organizations);
        } else {
            // Check if subscription columns exist
            $result = $conn->query("SHOW COLUMNS FROM organizations LIKE 'subscription_status'");
            if ($result->num_rows == 0) {
                $conn->query("ALTER TABLE organizations 
                    ADD COLUMN subscription_status ENUM('trial', 'active', 'expired', 'cancelled') DEFAULT 'trial' AFTER status,
                    ADD COLUMN trial_start_date DATE AFTER subscription_status,
                    ADD COLUMN trial_end_date DATE AFTER trial_start_date,
                    ADD COLUMN subscription_start_date DATE AFTER trial_end_date,
                    ADD COLUMN subscription_end_date DATE AFTER subscription_start_date,
                    ADD COLUMN subscription_plan VARCHAR(50) AFTER subscription_end_date");
            }
        }
        
        // Check if subscriptions table exists
        $result = $conn->query("SHOW TABLES LIKE 'subscriptions'");
        if ($result->num_rows == 0) {
            // Create subscriptions table
            $sql_subscriptions = "CREATE TABLE IF NOT EXISTS subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                plan_name VARCHAR(50) NOT NULL,
                plan_duration INT NOT NULL COMMENT 'Duration in months',
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                amount DECIMAL(10,2),
                status ENUM('active', 'expired', 'cancelled') DEFAULT 'active',
                payment_status ENUM('pending', 'paid', 'failed') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
            )";
            $conn->query($sql_subscriptions);
        }
        
        // Check if organization_id column exists in users table
        $result = $conn->query("SHOW COLUMNS FROM users LIKE 'organization_id'");
        if ($result->num_rows == 0) {
            // Add organization_id to users table
            $conn->query("ALTER TABLE users ADD COLUMN organization_id INT NULL AFTER role_id");
            $conn->query("ALTER TABLE users ADD FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL");
        }
        
        // Check if organization_id column exists in projects table
        $result = $conn->query("SHOW COLUMNS FROM projects LIKE 'organization_id'");
        if ($result->num_rows == 0) {
            // Add organization_id to projects table
            $conn->query("ALTER TABLE projects ADD COLUMN organization_id INT NULL AFTER status");
            $conn->query("ALTER TABLE projects ADD FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE");
        }
        
        // Check if Super Admin role exists
        $result = $conn->query("SELECT id FROM roles WHERE name = 'Super Admin'");
        if ($result->num_rows == 0) {
            // Add Super Admin role
            $conn->query("INSERT INTO roles (name, description) VALUES ('Super Admin', 'Site owner with full system access')");
        }
        
        // Update existing admin users to Super Admin if they don't have organization
        $conn->query("UPDATE users u JOIN roles r ON u.role_id = r.id SET u.role_id = (SELECT id FROM roles WHERE name = 'Super Admin') WHERE r.name = 'Admin' AND u.organization_id IS NULL");
        
        // Create default organization "AG Prime Tech" with 1 year free trial if it doesn't exist
        $org_name = 'AG Prime Tech';
        $org_check = $conn->prepare("SELECT id FROM organizations WHERE name = ?");
        $org_check->bind_param("s", $org_name);
        $org_check->execute();
        $org_result = $org_check->get_result();
        
        if ($org_result->num_rows == 0) {
            // Calculate 1 year trial dates
            $trial_start = date('Y-m-d');
            $trial_end = date('Y-m-d', strtotime('+12 months'));
            
            // Create organization
            $stmt = $conn->prepare("INSERT INTO organizations (name, subscription_status, trial_start_date, trial_end_date, subscription_plan) VALUES (?, 'trial', ?, ?, 'Free Trial - 1 Year')");
            $stmt->bind_param("sss", $org_name, $trial_start, $trial_end);
            $stmt->execute();
            $org_id = $conn->insert_id;
            
            // Create subscription record for trial
            $stmt = $conn->prepare("INSERT INTO subscriptions (organization_id, plan_name, plan_duration, start_date, end_date, amount, status, payment_status) VALUES (?, 'Free Trial - 1 Year', 12, ?, ?, 0.00, 'active', 'paid')");
            $stmt->bind_param("iss", $org_id, $trial_start, $trial_end);
            $stmt->execute();
            
            // Create default admin user for AG Prime Tech
            $admin_role_check = $conn->prepare("SELECT id FROM roles WHERE name = 'Admin'");
            $admin_role_check->execute();
            $admin_role_result = $admin_role_check->get_result();
            $admin_role_data = $admin_role_result->fetch_assoc();
            
            if ($admin_role_data) {
                $ag_admin_username = 'agprime';
                $ag_admin_email = 'admin@agprimetech.com';
                $ag_admin_password = password_hash('agprime123', PASSWORD_DEFAULT);
                $ag_admin_name = 'AG Prime Tech Admin';
                
                $stmt = $conn->prepare("INSERT IGNORE INTO users (username, email, password, full_name, role_id, organization_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssii", $ag_admin_username, $ag_admin_email, $ag_admin_password, $ag_admin_name, $admin_role_data['id'], $org_id);
                $stmt->execute();
            }
            $admin_role_check->close();
        }
        $org_check->close();
        
        $conn->close();
        $message = "Migration completed successfully! Organizations support has been added to your database.<br><br><strong>AG Prime Tech Organization Admin:</strong><br>Username: agprime<br>Password: agprime123<br><br>Default organization 'AG Prime Tech' created with 1 year free trial.";
        
    } catch (Exception $e) {
        $error = "Migration error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migrate to Organizations - Task Flow System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .migrate-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            padding: 40px;
            max-width: 600px;
            width: 100%;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            text-align: center;
        }
        
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
        }
        
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .info-box h3 {
            color: #333;
            margin-bottom: 10px;
        }
        
        .info-box ul {
            margin-left: 20px;
            color: #555;
        }
        
        .info-box li {
            margin: 5px 0;
        }
        
        .btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 15px 40px;
            font-size: 16px;
            border-radius: 5px;
            cursor: pointer;
            width: 100%;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: #5568d3;
        }
        
        .warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="migrate-container">
        <h1>Migrate to Organizations</h1>
        <p class="subtitle">Add multi-tenant organization support to existing database</p>
        
        <?php if ($message): ?>
            <div class="message success">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <h3>What will be migrated:</h3>
            <ul>
                <li>Create organizations table (with subscription fields)</li>
                <li>Create subscriptions table</li>
                <li>Add organization_id column to users table</li>
                <li>Add organization_id column to projects table</li>
                <li>Add Super Admin role</li>
                <li>Update existing admin users without organization to Super Admin</li>
                <li>Create default organization: AG Prime Tech (with 1 year free trial)</li>
            </ul>
        </div>
        
        <div class="warning">
            <strong>Warning:</strong> This migration will modify your database structure. Make sure you have a backup before proceeding.
        </div>
        
        <form method="POST" action="">
            <button type="submit" name="migrate" class="btn" title="Run Migration">
                <i class="fas fa-database"></i> Run Migration
            </button>
        </form>
        
        <?php if ($message): ?>
            <div style="margin-top: 20px; text-align: center;">
                <a href="../index" style="color: #667eea; text-decoration: none;">Go to Login Page →</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
