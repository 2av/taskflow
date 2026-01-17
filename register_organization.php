<?php
require_once 'config/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard');
    exit();
}

$error = '';
$success = '';

require_once 'config/email.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $org_name = trim($_POST['org_name'] ?? '');
    $org_email = trim($_POST['org_email'] ?? '');
    $trial_period = 12; // Default 12 months
    
    // Validation
    if (empty($org_name) || empty($org_email)) {
        $error = 'Please fill in all required fields';
    } elseif (!filter_var($org_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        $conn = getDBConnection();
        
        // Check if organization name already exists
        $stmt = $conn->prepare("SELECT id FROM organizations WHERE name = ?");
        $stmt->bind_param("s", $org_name);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'Organization name already exists';
        } else {
            // Check if email already exists
            $stmt = $conn->prepare("SELECT id FROM organizations WHERE email = ?");
            $stmt->bind_param("s", $org_email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = 'Email address already registered';
            } else {
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    // Calculate trial dates
                    $trial_start = date('Y-m-d');
                    $trial_end = date('Y-m-d', strtotime("+$trial_period months"));
                    
                    // Create organization with trial period
                    $stmt = $conn->prepare("INSERT INTO organizations (name, email, subscription_status, trial_start_date, trial_end_date, subscription_plan) VALUES (?, ?, 'trial', ?, ?, 'Free Trial')");
                    $stmt->bind_param("ssss", $org_name, $org_email, $trial_start, $trial_end);
                    $stmt->execute();
                    $organization_id = $conn->insert_id;
                    
                    // Create subscription record for trial
                    $stmt = $conn->prepare("INSERT INTO subscriptions (organization_id, plan_name, plan_duration, start_date, end_date, amount, status, payment_status) VALUES (?, 'Free Trial', ?, ?, ?, 0.00, 'active', 'paid')");
                    $stmt->bind_param("iiss", $organization_id, $trial_period, $trial_start, $trial_end);
                    $stmt->execute();
                    
                    // Generate password reset token
                    $token = bin2hex(random_bytes(32));
                    $token_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    
                    // Store token in database (create password_tokens table if not exists)
                    $conn->query("CREATE TABLE IF NOT EXISTS password_tokens (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        email VARCHAR(255) NOT NULL,
                        organization_id INT NOT NULL,
                        token VARCHAR(64) NOT NULL,
                        expires_at DATETIME NOT NULL,
                        used TINYINT(1) DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_token (token),
                        INDEX idx_email (email)
                    )");
                    
                    $stmt = $conn->prepare("INSERT INTO password_tokens (email, organization_id, token, expires_at) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("siss", $org_email, $organization_id, $token, $token_expiry);
                    $stmt->execute();
                    
                    // Send password setup email
                    if (sendPasswordSetupEmail($org_email, $org_name, $token)) {
                        $conn->commit();
                        $success = 'Organization registered successfully! Please check your email to set your password.';
                    } else {
                        // Even if email fails, organization is created
                        $conn->commit();
                        $error = 'Organization created but email could not be sent. Please contact support.';
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
                    <a href="index" class="btn btn-primary">Go to Login</a>
                </div>
            <?php else: ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="org_name">Organization Name *</label>
                        <input type="text" id="org_name" name="org_name" required autofocus 
                               value="<?php echo htmlspecialchars($_POST['org_name'] ?? ''); ?>"
                               placeholder="Enter your organization name">
                    </div>
                    
                    <div class="form-group">
                        <label for="org_email">Email Address *</label>
                        <input type="email" id="org_email" name="org_email" required 
                               value="<?php echo htmlspecialchars($_POST['org_email'] ?? ''); ?>"
                               placeholder="Enter your email address">
                        <small style="color: #666;">We'll send you a password setup link to this email</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block" title="Register Organization">
                        <i class="fas fa-building"></i> Register Organization
                    </button>
                </form>
                
                <div style="text-align: center; margin-top: 20px;">
                    <a href="index" style="color: #667eea; text-decoration: none;">Already have an account? Login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
