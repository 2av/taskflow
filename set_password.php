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
            
            if (empty($password) || empty($confirm_password)) {
                $error = 'Please fill in all fields';
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
                // Check if user already exists (should exist from organization verification, excluding deleted users)
                $stmt = $conn->prepare("SELECT id, status FROM users WHERE email = ? AND organization_id = ? AND deleted = 0");
                $stmt->bind_param("si", $token_data['email'], $token_data['organization_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                $existing_user = $result->fetch_assoc();
                
                if ($existing_user) {
                    // Update existing user with new password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET password = ?, status = 'active' WHERE id = ?");
                    $stmt->bind_param("si", $hashed_password, $existing_user['id']);
                    
                    if ($stmt->execute()) {
                        // Mark token as used
                        $stmt = $conn->prepare("UPDATE password_tokens SET used = 1 WHERE token = ?");
                        $stmt->bind_param("s", $token);
                        $stmt->execute();
                        
                        // Activate organization
                        $stmt = $conn->prepare("UPDATE organizations SET account_status = 'ACTIVE', status = 'active' WHERE id = ?");
                        $stmt->bind_param("i", $token_data['organization_id']);
                        $stmt->execute();
                        
                        // Send account activated email
                        require_once 'config/email.php';
                        sendAccountActivatedEmail($token_data['email'], $token_data['org_name']);
                        
                        $success = 'Password set successfully! Your account has been activated. Redirecting to login in 5 seconds...';
                        
                        // Redirect after 5 seconds
                        header("Refresh: 5; url=index");
                    } else {
                        $error = 'Error updating password: ' . $conn->error;
                    }
                } else {
                    // User doesn't exist - create new user (fallback for old registrations)
                    // Get Admin role ID
                    $stmt = $conn->prepare("SELECT id FROM roles WHERE name = 'Admin'");
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $admin_role = $result->fetch_assoc();
                    
                    if ($admin_role) {
                        // Create admin user
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $temp_full_name = $token_data['org_name'] . ' Admin';
                        $stmt = $conn->prepare("INSERT INTO users (email, password, full_name, role_id, organization_id, status) VALUES (?, ?, ?, ?, ?, 'active')");
                        $stmt->bind_param("sssii", $token_data['email'], $hashed_password, $temp_full_name, $admin_role['id'], $token_data['organization_id']);
                        
                        if ($stmt->execute()) {
                            // Mark token as used
                            $stmt = $conn->prepare("UPDATE password_tokens SET used = 1 WHERE token = ?");
                            $stmt->bind_param("s", $token);
                            $stmt->execute();
                            
                            // Activate organization
                            $stmt = $conn->prepare("UPDATE organizations SET account_status = 'ACTIVE', status = 'active' WHERE id = ?");
                            $stmt->bind_param("i", $token_data['organization_id']);
                            $stmt->execute();
                            
                            // Send account activated email
                            require_once 'config/email.php';
                            sendAccountActivatedEmail($token_data['email'], $token_data['org_name']);
                            
                            $success = 'Password set successfully! Your account has been activated. Redirecting to login in 5 seconds...';
                            
                            // Redirect after 5 seconds
                            header("Refresh: 5; url=index");
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
        <div class="login-box max-w-500">
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
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                    <div id="countdown" style="margin-top: 10px; font-size: 14px; color: #059669;"></div>
                </div>
                <div class="text-center mt-20">
                    <a href="index" class="btn btn-primary">Go to Login Now</a>
                </div>
                <script>
                    let countdown = 5;
                    const countdownEl = document.getElementById('countdown');
                    const interval = setInterval(() => {
                        countdown--;
                        if (countdown > 0) {
                            countdownEl.textContent = `Redirecting in ${countdown} second${countdown !== 1 ? 's' : ''}...`;
                        } else {
                            countdownEl.textContent = 'Redirecting now...';
                            clearInterval(interval);
                        }
                    }, 1000);
                </script>
            <?php elseif (isset($token_data)): ?>
                <form method="POST" action="">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="password" name="password" required minlength="6" autofocus
                                   placeholder="Enter password" autocomplete="new-password"
                                   class="<?php echo isset($field_errors['password']) ? 'error-field' : ''; ?>">
                            <button type="button" class="password-toggle-icon" onclick="togglePassword('password')" title="Show/Hide Password">
                                <i class="fas fa-eye" id="password-toggle-icon"></i>
                            </button>
                        </div>
                        <small style="color: #666;">Minimum 6 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password *</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="6"
                                   placeholder="Confirm your password" autocomplete="new-password"
                                   class="<?php echo isset($field_errors['confirm_password']) ? 'error-field' : ''; ?>">
                            <button type="button" class="password-toggle-icon" onclick="togglePassword('confirm_password')" title="Show/Hide Password">
                                <i class="fas fa-eye" id="confirm_password-toggle-icon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block" title="Set Password">
                        <i class="fas fa-key"></i> Set Password
                    </button>
                </form>
            <?php endif; ?>
            
            <div class="text-center mt-20">
                <a href="index" class="link-primary">Back to Login</a>
            </div>
        </div>
    </div>
    
    <script>
    function togglePassword(inputId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(inputId + '-toggle-icon');
        
        if (input && icon) {
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    }
    </script>
</body>
</html>
