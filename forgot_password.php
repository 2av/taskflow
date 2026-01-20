<?php
require_once 'config/config.php';
require_once 'config/email.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        $conn = getDBConnection();
        
        // Normalize email
        $email_normalized = strtolower(trim($email));
        
        // Check if user exists with this email (active or inactive, excluding deleted users)
        $stmt = $conn->prepare("SELECT u.*, o.name as org_name, o.id as org_id FROM users u LEFT JOIN organizations o ON u.organization_id = o.id WHERE u.email = ? AND u.deleted = 0");
        $stmt->bind_param("s", $email_normalized);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if ($user) {
            // User exists - check if active or inactive
            if ($user['status'] == 'active') {
                // Active user - send password reset link
                $token = bin2hex(random_bytes(32));
                $token_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Create password_reset_tokens table if it doesn't exist
                $conn->query("CREATE TABLE IF NOT EXISTS password_reset_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    token VARCHAR(64) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_token (token),
                    INDEX idx_email (email),
                    INDEX idx_user_id (user_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )");
                
                // IMPORTANT: Invalidate any existing unused tokens for this user when requesting a new reset
                // This ensures only the latest password reset link is valid
                $stmt = $conn->prepare("UPDATE password_reset_tokens SET used = 1 WHERE user_id = ? AND used = 0");
                $stmt->bind_param("i", $user['id']);
                $stmt->execute();
                
                // Insert new token
                $stmt = $conn->prepare("INSERT INTO password_reset_tokens (user_id, email, token, expires_at) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isss", $user['id'], $email_normalized, $token, $token_expiry);
                
                if ($stmt->execute()) {
                    // Send password reset email
                    $org_name = $user['org_name'] ?? 'Task Flow System';
                    if (sendPasswordResetEmail($email_normalized, $org_name, $token)) {
                        $success = 'Password reset link has been sent to your email address. Please check your inbox.';
                    } else {
                        $error = 'Could not send password reset email. Please try again later.';
                    }
                } else {
                    $error = 'Error generating reset token. Please try again.';
                }
            } else {
                // Inactive user - send password setup link (they didn't complete initial setup)
                $token = bin2hex(random_bytes(32));
                $token_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
                
                // Create password_tokens table if it doesn't exist
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
                
                // Invalidate any existing tokens for this organization
                $stmt = $conn->prepare("UPDATE password_tokens SET used = 1 WHERE organization_id = ? AND used = 0");
                $stmt->bind_param("i", $user['organization_id']);
                $stmt->execute();
                
                // Insert new token
                $stmt = $conn->prepare("INSERT INTO password_tokens (email, organization_id, token, expires_at) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("siss", $email_normalized, $user['organization_id'], $token, $token_expiry);
                
                if ($stmt->execute()) {
                    // Send password setup email
                    $org_name = $user['org_name'] ?? 'Task Flow System';
                    if (sendPasswordSetupEmail($email_normalized, $org_name, $token)) {
                        $success = 'Password setup link has been sent to your email address. Please check your inbox to complete your account setup.';
                    } else {
                        $error = 'Could not send password setup email. Please try again later.';
                    }
                } else {
                    $error = 'Error generating setup token. Please try again.';
                }
            }
        } else {
            // User doesn't exist - check if organization exists
            $stmt = $conn->prepare("SELECT id, name, account_status FROM organizations WHERE email = ?");
            $stmt->bind_param("s", $email_normalized);
            $stmt->execute();
            $result = $stmt->get_result();
            $org = $result->fetch_assoc();
            
            if ($org && ($org['account_status'] == 'VERIFIED' || $org['account_status'] == 'ACTIVE')) {
                // Organization exists but user doesn't - create user and send setup link
                // Get Admin role ID
                $stmt = $conn->prepare("SELECT id FROM roles WHERE name = 'Admin'");
                $stmt->execute();
                $result = $stmt->get_result();
                $admin_role = $result->fetch_assoc();
                
                if ($admin_role) {
                    // Create user with temporary password
                    $temp_password = bin2hex(random_bytes(32));
                    $hashed_temp_password = password_hash($temp_password, PASSWORD_DEFAULT);
                    $temp_full_name = $org['name'] . ' Admin';
                    
                    $stmt = $conn->prepare("INSERT INTO users (email, password, full_name, role_id, organization_id, status) VALUES (?, ?, ?, ?, ?, 'inactive')");
                    $stmt->bind_param("sssii", $email_normalized, $hashed_temp_password, $temp_full_name, $admin_role['id'], $org['id']);
                    
                    if ($stmt->execute()) {
                        $user_id = $conn->insert_id;
                        
                        // Generate password setup token
                        $token = bin2hex(random_bytes(32));
                        $token_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
                        
                        // Create password_tokens table if it doesn't exist
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
                        
                        // Insert token
                        $stmt = $conn->prepare("INSERT INTO password_tokens (email, organization_id, token, expires_at) VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("siss", $email_normalized, $org['id'], $token, $token_expiry);
                        $stmt->execute();
                        
                        // Send password setup email
                        if (sendPasswordSetupEmail($email_normalized, $org['name'], $token)) {
                            $success = 'Password setup link has been sent to your email address. Please check your inbox to complete your account setup.';
                        } else {
                            $error = 'Could not send password setup email. Please try again later.';
                        }
                    } else {
                        $error = 'Error creating user account. Please contact support.';
                    }
                } else {
                    $error = 'System error. Please contact support.';
                }
            } else {
                // Nothing exists
                $error = 'No account found with this email address. Please check your email or register a new account.';
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
    <title>Forgot Password - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box max-w-500">
            <h1>Forgot Password</h1>
            <p class="subtitle">Enter your email address to receive a password reset link</p>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="index" class="btn btn-primary">Back to Login</a>
                </div>
            <?php else: ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" required autofocus 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                               placeholder="Enter your email address">
                        <small class="text-helper-small">We'll send you a link to reset your password</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block" title="Send Reset Link">
                        <i class="fas fa-paper-plane"></i> Send Reset Link
                    </button>
                </form>
                
                <div style="text-align: center; margin-top: 20px;">
                    <a href="index" class="link-primary">
                        <i class="fas fa-arrow-left"></i> Back to Login
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
