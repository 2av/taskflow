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
$token = $_POST['token'] ?? $_GET['token'] ?? '';
$field_errors = [];

if (empty($token)) {
    $error = 'Invalid or missing reset token';
} else {
    $conn = getDBConnection();
    
    // Check token validity - try password_reset_tokens first (for active users)
    $stmt = $conn->prepare("SELECT prt.*, u.email, u.full_name, u.status as user_status, o.name as org_name, o.id as org_id
                           FROM password_reset_tokens prt 
                           JOIN users u ON prt.user_id = u.id 
                           LEFT JOIN organizations o ON u.organization_id = o.id
                           WHERE prt.token = ? AND prt.used = 0 AND prt.expires_at > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $token_data = $result->fetch_assoc();
    $token_type = 'reset'; // Default to reset token
    
    // If not found in reset tokens, check password_tokens (for inactive users/setup)
    if (!$token_data) {
        $stmt = $conn->prepare("SELECT pt.*, o.name as org_name, o.id as org_id, u.id as user_id, u.status as user_status, u.full_name, u.email
                               FROM password_tokens pt 
                               JOIN organizations o ON pt.organization_id = o.id
                               LEFT JOIN users u ON pt.email = u.email AND u.organization_id = o.id
                               WHERE pt.token = ? AND pt.used = 0 AND pt.expires_at > NOW()");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $token_data = $result->fetch_assoc();
        $token_type = 'setup'; // This is a setup token
    }
    
    if (!$token_data) {
        $error = 'Invalid or expired token. Please request a new password reset link.';
    } else {
        // Handle password reset/setup
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $full_name = trim($_POST['full_name'] ?? '');
            
            // For setup tokens, full_name is required if not already set
            $is_setup = (isset($token_type) && $token_type == 'setup');
            $require_full_name = $is_setup && (empty($token_data['full_name']) || $token_data['full_name'] == ($token_data['org_name'] . ' Admin'));
            
            if (empty($password) || empty($confirm_password) || ($require_full_name && empty($full_name))) {
                $error = 'Please fill in all fields';
                if (empty($password)) $field_errors['password'] = true;
                if (empty($confirm_password)) $field_errors['confirm_password'] = true;
                if ($require_full_name && empty($full_name)) $field_errors['full_name'] = true;
            } elseif ($password !== $confirm_password) {
                $error = 'Passwords do not match';
                $field_errors['password'] = true;
                $field_errors['confirm_password'] = true;
            } elseif (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters long';
                $field_errors['password'] = true;
                $field_errors['confirm_password'] = true;
            } else {
                // Update password and activate user if inactive
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Determine user_id based on token type
                $user_id = null;
                if (isset($token_type) && $token_type == 'reset') {
                    $user_id = $token_data['user_id'];
                } else {
                    // Setup token - user_id might be in token_data or we need to find it
                    $user_id = $token_data['user_id'] ?? null;
                    if (!$user_id && isset($token_data['email'])) {
                        // Find user by email and organization
                        $find_user = $conn->prepare("SELECT id FROM users WHERE email = ? AND organization_id = ?");
                        $find_user->bind_param("si", $token_data['email'], $token_data['org_id']);
                        $find_user->execute();
                        $user_result = $find_user->get_result();
                        $user_row = $user_result->fetch_assoc();
                        $user_id = $user_row['id'] ?? null;
                    }
                }
                
                if ($user_id) {
                    // Update existing user
                    if ($require_full_name && !empty($full_name)) {
                        // Update password and full_name
                        $stmt = $conn->prepare("UPDATE users SET password = ?, full_name = ?, status = 'active' WHERE id = ?");
                        $stmt->bind_param("ssi", $hashed_password, $full_name, $user_id);
                    } else {
                        // Update password only
                        $stmt = $conn->prepare("UPDATE users SET password = ?, status = 'active' WHERE id = ?");
                        $stmt->bind_param("si", $hashed_password, $user_id);
                    }
                } else {
                    // User doesn't exist - create new user (shouldn't happen, but safety)
                    // Get Admin role
                    $role_stmt = $conn->prepare("SELECT id FROM roles WHERE name = 'Admin'");
                    $role_stmt->execute();
                    $role_result = $role_stmt->get_result();
                    $admin_role = $role_result->fetch_assoc();
                    
                    if ($admin_role) {
                        $temp_full_name = $token_data['full_name'] ?? ($token_data['org_name'] . ' Admin');
                        $stmt = $conn->prepare("INSERT INTO users (email, password, full_name, role_id, organization_id, status) VALUES (?, ?, ?, ?, ?, 'active')");
                        $stmt->bind_param("sssii", $token_data['email'], $hashed_password, $temp_full_name, $admin_role['id'], $token_data['org_id']);
                    } else {
                        $error = 'System error. Please contact support.';
                        $user_id = null;
                    }
                }
                
                if ($user_id || ($stmt && $stmt->execute())) {
                    if (!$user_id) {
                        $user_id = $conn->insert_id;
                    }
                    
                    // Mark token as used based on type
                    if ($token_type == 'reset') {
                        $stmt = $conn->prepare("UPDATE password_reset_tokens SET used = 1 WHERE token = ?");
                    } else {
                        $stmt = $conn->prepare("UPDATE password_tokens SET used = 1 WHERE token = ?");
                    }
                    $stmt->bind_param("s", $token);
                    $stmt->execute();
                    
                    // Activate organization if it's not active
                    $stmt = $conn->prepare("UPDATE organizations SET account_status = 'ACTIVE', status = 'active' WHERE id = ? AND account_status != 'ACTIVE'");
                    $stmt->bind_param("i", $token_data['org_id']);
                    $stmt->execute();
                    
                    // Send account activated email if this was a setup (inactive user)
                    if ($token_type == 'setup' && ($token_data['user_status'] ?? 'inactive') == 'inactive') {
                        require_once 'config/email.php';
                        sendAccountActivatedEmail($token_data['email'], $token_data['org_name']);
                    }
                    
                    $success = 'Password ' . ($token_type == 'setup' ? 'set' : 'reset') . ' successfully! Your account has been activated. Redirecting to login in 3 seconds...';
                    
                    // Redirect after 3 seconds
                    header("Refresh: 3; url=index");
                } else {
                    $error = 'Error ' . ($token_type == 'setup' ? 'setting' : 'resetting') . ' password: ' . $conn->error;
                }
            }
        }
    }
    
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .form-group input.error-field {
            border: 2px solid #ef4444 !important;
            border-color: #ef4444 !important;
            background-color: #fef2f2;
        }
        .form-group input.error-field:focus {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
            outline: none;
        }
    </style>
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box" style="max-width: 500px;">
            <h1><?php echo (isset($token_type) && $token_type == 'setup') ? 'Set Your Password' : 'Reset Your Password'; ?></h1>
            <?php if (isset($token_data)): ?>
                <?php if (isset($token_type) && $token_type == 'setup'): ?>
                    <p class="subtitle">Welcome! Complete your account setup by setting your password</p>
                <?php else: ?>
                    <p class="subtitle">Hello <?php echo htmlspecialchars($token_data['full_name'] ?? 'User'); ?>, enter your new password</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="subtitle">Enter your new password</p>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="index" class="btn btn-primary">Go to Login</a>
                </div>
            <?php elseif (isset($token_data)): ?>
                <form method="POST" action="">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    
                    <div class="form-group">
                        <label for="email_display">Email Address</label>
                        <input type="email" id="email_display" name="email_display" 
                               value="<?php echo htmlspecialchars($token_data['email']); ?>" 
                               disabled
                               style="background-color: #f3f4f6; cursor: not-allowed;">
                    </div>
                    
                    <?php if (isset($token_type) && $token_type == 'setup' && empty($token_data['full_name'])): ?>
                        <div class="form-group">
                            <label for="full_name">Full Name *</label>
                            <input type="text" id="full_name" name="full_name" required 
                                   value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                                   placeholder="Enter your full name"
                                   class="<?php echo isset($field_errors['full_name']) ? 'error-field' : ''; ?>">
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="password">New Password *</label>
                        <input type="password" id="password" name="password" required minlength="6"
                               placeholder="Enter new password" autocomplete="new-password"
                               class="<?php echo isset($field_errors['password']) ? 'error-field' : ''; ?>">
                        <small style="color: #666;">Minimum 6 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="6"
                               placeholder="Confirm your new password" autocomplete="new-password"
                               class="<?php echo isset($field_errors['confirm_password']) ? 'error-field' : ''; ?>">
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block" title="Reset Password">
                        <i class="fas fa-key"></i> Reset Password
                    </button>
                </form>
            <?php endif; ?>
            
            <div style="text-align: center; margin-top: 20px;">
                <a href="index" style="color: #667eea; text-decoration: none;">Back to Login</a>
            </div>
        </div>
    </div>
</body>
</html>
