<?php
require_once '../config/database.php';
require_once '../config/config.php';

$connection_status = '';
$connection_error = '';
$is_connected = false;
$db_info = [];
$message = '';
$error = '';

// Check database connection
try {
    $conn = getDBConnection();
    
    if ($conn && $conn->connect_error === null) {
        $is_connected = true;
        $connection_status = 'Database Connected Successfully!';
        
        // Get database info
        $db_info['host'] = DB_HOST;
        $db_info['database'] = DB_NAME;
        $db_info['user'] = DB_USER;
        
        // Test query to verify database is working
        $result = $conn->query("SELECT VERSION() as version");
        if ($result) {
            $row = $result->fetch_assoc();
            $db_info['mysql_version'] = $row['version'];
        }
        
        $conn->close();
    } else {
        $is_connected = false;
        $connection_error = 'Failed to connect to database';
    }
} catch (Exception $e) {
    $is_connected = false;
    $connection_error = 'Connection Error: ' . $e->getMessage();
}

// Handle table creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_tables'])) {
    try {
        $conn = getDBConnection();
        
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
        
        // Create subscriptions table for subscription history
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
        
        // Create roles table
        $sql_roles = "CREATE TABLE IF NOT EXISTS roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $conn->query($sql_roles);
        
        // Create users table
        $sql_users = "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            email VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            role_id INT NOT NULL,
            organization_id INT,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (role_id) REFERENCES roles(id),
            FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
        )";
        $conn->query($sql_users);
        
        // Create projects table
        $sql_projects = "CREATE TABLE IF NOT EXISTS projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            description TEXT,
            status ENUM('Active', 'On Hold', 'Completed') DEFAULT 'Active',
            organization_id INT NOT NULL,
            project_manager_id INT,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
            FOREIGN KEY (project_manager_id) REFERENCES users(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        )";
        $conn->query($sql_projects);
        
        // Create project_users table (many-to-many relationship)
        $sql_project_users = "CREATE TABLE IF NOT EXISTS project_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            user_id INT NOT NULL,
            added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_project_user (project_id, user_id)
        )";
        $conn->query($sql_project_users);
        
        // Create tasks table
        $sql_tasks = "CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id VARCHAR(50) NOT NULL UNIQUE,
            project_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            type ENUM('Task', 'Bug', 'Improvement') DEFAULT 'Task',
            priority ENUM('Low', 'Medium', 'High') DEFAULT 'Medium',
            status ENUM('To Do', 'In Progress', 'Done') DEFAULT 'To Do',
            assignee_id INT,
            due_date DATE,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )";
        $conn->query($sql_tasks);
        
        // Create task_comments table
        $sql_comments = "CREATE TABLE IF NOT EXISTS task_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            user_id INT NOT NULL,
            comment TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )";
        $conn->query($sql_comments);
        
        // Create activity_logs table
        $sql_logs = "CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            old_value VARCHAR(255),
            new_value VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )";
        $conn->query($sql_logs);
        
        // Insert default roles
        $roles = [
            ['Super Admin', 'Site owner with full system access'],
            ['Admin', 'Organization admin with full access to their organization'],
            ['Project Manager', 'Manage projects and tasks'],
            ['Team Member', 'Work on assigned tasks']
        ];
        
        foreach ($roles as $role) {
            $stmt = $conn->prepare("INSERT IGNORE INTO roles (name, description) VALUES (?, ?)");
            $stmt->bind_param("ss", $role[0], $role[1]);
            $stmt->execute();
        }
        
        // Create default super admin user (password: admin123)
        $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
        $admin_email = 'admin@taskflow.local';
        $admin_username = 'admin';
        $admin_name = 'System Administrator';
        
        $stmt = $conn->prepare("SELECT id FROM roles WHERE name = 'Super Admin'");
        $stmt->execute();
        $result = $stmt->get_result();
        $admin_role = $result->fetch_assoc();
        
        if ($admin_role) {
            $stmt = $conn->prepare("INSERT IGNORE INTO users (username, email, password, full_name, role_id, organization_id) VALUES (?, ?, ?, ?, ?, NULL)");
            $stmt->bind_param("ssssi", $admin_username, $admin_email, $admin_password, $admin_name, $admin_role['id']);
            $stmt->execute();
        }
        
        // Create default organization "AG Prime Tech" with 1 year free trial
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
        $message = "Database tables created successfully!<br><br><strong>Super Admin:</strong><br>Username: admin<br>Password: admin123<br><br><strong>AG Prime Tech Organization Admin:</strong><br>Username: agprime<br>Password: agprime123<br><br>Default organization 'AG Prime Tech' created with 1 year free trial.";
        
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup - Task Flow System</title>
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
        
        .setup-container {
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
        
        .status-box {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
        }
        
        .status-success {
            background: #d4edda;
            color: #155724;
            border: 2px solid #28a745;
        }
        
        .status-error {
            background: #f8d7da;
            color: #721c24;
            border: 2px solid #dc3545;
        }
        
        .status-icon {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .status-message {
            font-size: 16px;
            font-weight: bold;
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
        
        .info-box p {
            margin: 5px 0;
            color: #555;
            font-size: 13px;
        }
        
        .info-box strong {
            color: #333;
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
        
        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
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
    <div class="setup-container">
        <h1>Database Setup</h1>
        <p class="subtitle">Task Flow System</p>
        
        <!-- Connection Status -->
        <?php if ($is_connected): ?>
            <div class="status-box status-success">
                <div class="status-icon">✓</div>
                <div class="status-message"><?php echo htmlspecialchars($connection_status); ?></div>
            </div>
        <?php else: ?>
            <div class="status-box status-error">
                <div class="status-icon">✗</div>
                <div class="status-message">Database Connection Failed</div>
                <?php if ($connection_error): ?>
                    <div style="margin-top: 10px; font-size: 14px;">
                        <?php echo htmlspecialchars($connection_error); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
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
        
        <?php if ($is_connected && !empty($db_info)): ?>
            <div class="info-box">
                <h3>Connection Details:</h3>
                <p><strong>Host:</strong> <?php echo htmlspecialchars($db_info['host']); ?></p>
                <p><strong>Database:</strong> <?php echo htmlspecialchars($db_info['database']); ?></p>
                <p><strong>User:</strong> <?php echo htmlspecialchars($db_info['user']); ?></p>
                <?php if (isset($db_info['mysql_version'])): ?>
                    <p><strong>MySQL Version:</strong> <?php echo htmlspecialchars($db_info['mysql_version']); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <h3>What will be created:</h3>
            <ul>
                <li>Database: <?php echo htmlspecialchars(DB_NAME); ?></li>
                <li>Tables: organizations, subscriptions, roles, users, projects, project_users, tasks, task_comments, activity_logs</li>
                <li>Default roles: Super Admin, Admin, Project Manager, Team Member</li>
                <li>Default super admin user (username: admin, password: admin123)</li>
                <li>Default organization: AG Prime Tech (with 1 year free trial)</li>
                <li>Subscription management with free trial periods (6 or 12 months)</li>
            </ul>
        </div>
        
        <div class="warning">
            <strong>Note:</strong> Make sure your MySQL server is running and the database credentials in config/database.php are correct.
        </div>
        
        <form method="POST" action="">
            <button type="submit" name="create_tables" class="btn" <?php echo !$is_connected ? 'disabled' : ''; ?> title="Create All Tables Automatically">
                <i class="fas fa-database"></i>
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
