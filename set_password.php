<?php
require_once 'config/config.php';
require_once 'config/email.php';

$error = '';
$success = '';
$token = $_POST['token'] ?? $_GET['token'] ?? '';
$field_errors = []; // Track which fields have errors

if (empty($token)) {
    $error = 'Invalid or missing token';
} else {
    $conn = getDBConnection();
    
    // Check token validity
    $stmt = $conn->prepare("SELECT pt.*, o.name as org_name FROM password_tokens pt 
                           JOIN organizations o ON pt.organization_id = o.id 
                           WHERE pt.token = ? AND pt.used = 0 AND pt.expires_at > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $token_data = $result->fetch_assoc();
    
    if (!$token_data) {
        $error = 'Invalid or expired token. Please request a new password setup link.';
    } else {
        // Handle password setup
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $username = trim($_POST['username'] ?? '');
            $full_name = trim($_POST['full_name'] ?? '');
            
            if (empty($password) || empty($confirm_password) || empty($username) || empty($full_name)) {
                $error = 'Please fill in all fields';
                if (empty($full_name)) $field_errors['full_name'] = true;
                if (empty($username)) $field_errors['username'] = true;
                if (empty($password)) $field_errors['password'] = true;
                if (empty($confirm_password)) $field_errors['confirm_password'] = true;
            } elseif ($password !== $confirm_password) {
                $error = 'Passwords do not match';
                $field_errors['password'] = true;
                $field_errors['confirm_password'] = true;
            } elseif (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters long';
                $field_errors['password'] = true;
                $field_errors['confirm_password'] = true;
            } else {
                // Check if username already exists
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $error = 'Username already exists. Please choose a different username.';
                    $field_errors['username'] = true;
                } else {
                    // Get Admin role ID
                    $stmt = $conn->prepare("SELECT id FROM roles WHERE name = 'Admin'");
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $admin_role = $result->fetch_assoc();
                    
                    if ($admin_role) {
                        // Create admin user
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, role_id, organization_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssii", $username, $token_data['email'], $hashed_password, $full_name, $admin_role['id'], $token_data['organization_id']);
                        
                        if ($stmt->execute()) {
                            // Mark token as used
                            $stmt = $conn->prepare("UPDATE password_tokens SET used = 1 WHERE token = ?");
                            $stmt->bind_param("s", $token);
                            $stmt->execute();
                            
                            $success = 'Password set successfully! You can now login.';
                        } else {
                            $error = 'Error creating account: ' . $conn->error;
                        }
                    } else {
                        $error = 'Admin role not found';
                    }
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
    <title>Set Password - <?php echo SITE_NAME; ?></title>
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
            <h1>Set Your Password</h1>
            <?php if (isset($token_data)): ?>
                <p class="subtitle">Welcome to <strong><?php echo htmlspecialchars($token_data['org_name']); ?></strong></p>
            <?php else: ?>
                <p class="subtitle">Complete your account setup</p>
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
                        <label for="full_name">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" required autofocus 
                               value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                               placeholder="Enter your full name"
                               class="<?php echo isset($field_errors['full_name']) ? 'error-field' : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="username">Username *</label>
                        <input type="text" id="username" name="username" required 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                               placeholder="Choose a username"
                               class="<?php echo isset($field_errors['username']) ? 'error-field' : ''; ?>">
                        <small style="color: #666;">This will be your login username</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" required minlength="6"
                               placeholder="Enter password" autocomplete="new-password"
                               class="<?php echo isset($field_errors['password']) ? 'error-field' : ''; ?>">
                        <small style="color: #666;">Minimum 6 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="6"
                               placeholder="Confirm your password" autocomplete="new-password"
                               class="<?php echo isset($field_errors['confirm_password']) ? 'error-field' : ''; ?>">
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block" title="Set Password">
                        <i class="fas fa-key"></i> Set Password
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
