<?php
require_once 'config/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $org_name = trim($_POST['org_name'] ?? '');
    $org_email = trim($_POST['org_email'] ?? '');
    $org_phone = trim($_POST['org_phone'] ?? '');
    $org_address = trim($_POST['org_address'] ?? '');
    $trial_period = isset($_POST['trial_period']) ? intval($_POST['trial_period']) : 12; // Default 12 months
    
    // Admin user details
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    
    // Validation
    if (empty($org_name) || empty($username) || empty($email) || empty($password) || empty($full_name)) {
        $error = 'Please fill in all required fields';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long';
    } else {
        $conn = getDBConnection();
        
        // Check if username or email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'Username or email already exists';
        } else {
            // Check if organization name already exists
            $stmt = $conn->prepare("SELECT id FROM organizations WHERE name = ?");
            $stmt->bind_param("s", $org_name);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = 'Organization name already exists';
            } else {
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    // Calculate trial dates
                    $trial_start = date('Y-m-d');
                    $trial_end = date('Y-m-d', strtotime("+$trial_period months"));
                    
                    // Create organization with trial period
                    $stmt = $conn->prepare("INSERT INTO organizations (name, email, phone, address, subscription_status, trial_start_date, trial_end_date, subscription_plan) VALUES (?, ?, ?, ?, 'trial', ?, ?, 'Free Trial')");
                    $stmt->bind_param("ssssss", $org_name, $org_email, $org_phone, $org_address, $trial_start, $trial_end);
                    $stmt->execute();
                    $organization_id = $conn->insert_id;
                    
                    // Create subscription record for trial
                    $stmt = $conn->prepare("INSERT INTO subscriptions (organization_id, plan_name, plan_duration, start_date, end_date, amount, status, payment_status) VALUES (?, 'Free Trial', ?, ?, ?, 0.00, 'active', 'paid')");
                    $stmt->bind_param("iiss", $organization_id, $trial_period, $trial_start, $trial_end);
                    $stmt->execute();
                    
                    // Get Admin role ID
                    $stmt = $conn->prepare("SELECT id FROM roles WHERE name = 'Admin'");
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $admin_role = $result->fetch_assoc();
                    
                    if ($admin_role) {
                        // Create admin user for the organization
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, role_id, organization_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssii", $username, $email, $hashed_password, $full_name, $admin_role['id'], $organization_id);
                        $stmt->execute();
                        
                        $conn->commit();
                        $success = 'Organization registered successfully! You can now login.';
                    } else {
                        throw new Exception('Admin role not found');
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = 'Error creating organization: ' . $e->getMessage();
                }
            }
        }
        
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Organization - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box" style="max-width: 600px;">
            <h1>Register Your Organization</h1>
            <p class="subtitle">Create your company account and become the admin</p>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="index.php" class="btn btn-primary">Go to Login</a>
                </div>
            <?php else: ?>
                <form method="POST" action="">
                    <h3 style="margin: 20px 0 15px 0; color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px;">Organization Details</h3>
                    
                    <div class="form-group">
                        <label for="org_name">Organization Name *</label>
                        <input type="text" id="org_name" name="org_name" required autofocus 
                               value="<?php echo htmlspecialchars($_POST['org_name'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="org_email">Organization Email</label>
                        <input type="email" id="org_email" name="org_email" 
                               value="<?php echo htmlspecialchars($_POST['org_email'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="org_phone">Organization Phone</label>
                        <input type="text" id="org_phone" name="org_phone" 
                               value="<?php echo htmlspecialchars($_POST['org_phone'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="org_address">Organization Address</label>
                        <textarea id="org_address" name="org_address" rows="3"><?php echo htmlspecialchars($_POST['org_address'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="trial_period">Free Trial Period *</label>
                        <select id="trial_period" name="trial_period" required>
                            <option value="6" <?php echo (isset($_POST['trial_period']) && $_POST['trial_period'] == '6') ? 'selected' : ''; ?>>6 Months Free</option>
                            <option value="12" <?php echo (!isset($_POST['trial_period']) || $_POST['trial_period'] == '12') ? 'selected' : ''; ?>>12 Months Free (1 Year)</option>
                        </select>
                        <small style="color: #666;">Select your free trial period. After this period, you'll need to subscribe.</small>
                    </div>
                    
                    <h3 style="margin: 30px 0 15px 0; color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px;">Your Admin Account</h3>
                    
                    <div class="form-group">
                        <label for="full_name">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" required 
                               value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="username">Username *</label>
                        <input type="text" id="username" name="username" required 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" required minlength="6">
                        <small style="color: #666;">Minimum 6 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block" title="Register Organization"><i class="fas fa-building"></i> Register Organization</button>
                </form>
                
                <div style="text-align: center; margin-top: 20px;">
                    <a href="index.php" style="color: #667eea; text-decoration: none;">Already have an account? Login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
