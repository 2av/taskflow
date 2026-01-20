<?php
require_once 'config/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard');
    exit();
}

$error = '';
$success = '';
$email = $_POST['email'] ?? $_SESSION['pending_verification_email'] ?? $_GET['email'] ?? '';
$otp_verified = false;
$show_org_form = false;
$org_details_submitted = false; // Track if org details were successfully submitted

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify_otp'])) {
    $otp_code = trim($_POST['otp_code'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    if (empty($otp_code) || empty($email)) {
        $error = 'Please enter the OTP code and email address';
    } elseif (strlen($otp_code) != 6 || !is_numeric($otp_code)) {
        $error = 'OTP code must be 6 digits';
    } else {
        $conn = getDBConnection();
        
        // Normalize email (lowercase, trim)
        $email_normalized = strtolower(trim($email));
        // Ensure OTP is 6 digits with leading zeros (remove any spaces)
        $otp_code_normalized = str_pad(preg_replace('/[^0-9]/', '', $otp_code), 6, '0', STR_PAD_LEFT);
        
        // Verify OTP - only get PENDING_VERIFICATION records, order by most recent first
        $stmt = $conn->prepare("SELECT * FROM organization_temp WHERE LOWER(TRIM(email)) = ? AND account_status = 'PENDING_VERIFICATION' ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("s", $email_normalized);
        $stmt->execute();
        $result = $stmt->get_result();
        $temp_org = $result->fetch_assoc();
        
        if (!$temp_org) {
            // Check if there's a VERIFIED record - if so, they might have already completed verification
            $check_stmt = $conn->prepare("SELECT id, account_status FROM organization_temp WHERE LOWER(TRIM(email)) = ? AND account_status = 'VERIFIED' LIMIT 1");
            $check_stmt->bind_param("s", $email_normalized);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $verified_record = $check_result->fetch_assoc();
            
            if ($verified_record) {
                // Check if organization exists in main table
                $org_check = $conn->prepare("SELECT id FROM organizations WHERE LOWER(TRIM(email)) = ? AND account_status IN ('VERIFIED', 'ACTIVE') LIMIT 1");
                $org_check->bind_param("s", $email_normalized);
                $org_check->execute();
                $org_result = $org_check->get_result();
                
                if ($org_result->num_rows > 0) {
                    $error = 'This email has already been verified. Please login or use forgot password if you need to reset your password.';
                } else {
                    // VERIFIED in temp but not in main - allow re-registration
                    $error = 'No pending verification found. Please register again to receive a new OTP code.';
                }
            } else {
                $error = 'Email not found in our records. Please check your email address or register again.';
            }
        } elseif (strtotime($temp_org['otp_expires_at']) < time()) {
            $error = 'OTP code has expired. Please request a new one.';
        } else {
            // Compare OTP codes - ensure both are 6 digits with leading zeros
            $db_otp = str_pad(trim($temp_org['otp_code']), 6, '0', STR_PAD_LEFT);
            $submitted_otp = str_pad($otp_code_normalized, 6, '0', STR_PAD_LEFT);
            
            if ($db_otp !== $submitted_otp) {
                // For debugging - uncomment to see what's being compared
                // $error = "Invalid OTP code. DB: '{$db_otp}' (len: " . strlen($db_otp) . "), Submitted: '{$submitted_otp}' (len: " . strlen($submitted_otp) . ")";
                $error = 'Invalid OTP code. Please check the code and try again.';
            } else {
                // OTP is valid - mark as verified but DON'T create organization yet
                try {
                    // Update temp record status to VERIFIED
                    $stmt = $conn->prepare("UPDATE organization_temp SET account_status = 'VERIFIED' WHERE id = ?");
                    $stmt->bind_param("i", $temp_org['id']);
                    $stmt->execute();
                    
                    // Store verified email in session for organization details form
                    $_SESSION['verified_email'] = $email_normalized;
                    $_SESSION['verified_temp_id'] = $temp_org['id'];
                    
                    // Set flag to show organization form
                    $show_org_form = true;
                    $success = 'Email verified successfully! Please complete your organization details.';
                } catch (Exception $e) {
                    $error = 'Error verifying OTP: ' . $e->getMessage();
                }
            }
        }
        
        $conn->close();
    }
}

// Handle organization details submission (after OTP verification)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_org_details'])) {
    $org_name = trim($_POST['org_name'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $verified_email = $_SESSION['verified_email'] ?? '';
    $temp_id = $_SESSION['verified_temp_id'] ?? null;
    
    if (empty($org_name)) {
        $error = 'Organization name is required';
    } elseif (empty($verified_email) || !$temp_id) {
        $error = 'Please verify your email first';
    } else {
        $conn = getDBConnection();
        
        // Check if organization name already exists
        $stmt = $conn->prepare("SELECT id FROM organizations WHERE name = ? AND (account_status = 'ACTIVE' OR account_status = 'VERIFIED')");
        $stmt->bind_param("s", $org_name);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'Organization name already exists. Please choose a different name.';
                } else {
            try {
                $conn->begin_transaction();
                
                // Get verified temp record
                $stmt = $conn->prepare("SELECT * FROM organization_temp WHERE id = ? AND account_status = 'VERIFIED'");
                $stmt->bind_param("i", $temp_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $temp_org = $result->fetch_assoc();
                
                if (!$temp_org) {
                    $error = 'Verification record not found. Please start over.';
                } else {
                    // Check if username column exists in organizations table (should be removed)
                    $check_username = $conn->query("SHOW COLUMNS FROM organizations LIKE 'username'");
                    if ($check_username->num_rows > 0) {
                        $error = 'Database configuration error: Please run the migration script (setup/remove_username_from_organizations.php) to remove the username column from the organizations table.';
                        $conn->rollback();
                    } else {
                        // Calculate trial dates
                        $trial_period = 12; // Default 12 months
                        $trial_start = date('Y-m-d');
                        $trial_end = date('Y-m-d', strtotime("+$trial_period months"));
                        
                        // Create organization (without username column)
                        $stmt = $conn->prepare("INSERT INTO organizations (name, email, website, subscription_status, trial_start_date, trial_end_date, subscription_plan, account_status, email_verified) VALUES (?, ?, ?, 'trial', ?, ?, 'Free Trial', 'VERIFIED', 1)");
                        $website_value = !empty($website) ? $website : null;
                        $stmt->bind_param("sssss", $org_name, $temp_org['email'], $website_value, $trial_start, $trial_end);
                        $stmt->execute();
                        $organization_id = $conn->insert_id;
                        
                        // Create subscription record
                        $stmt = $conn->prepare("INSERT INTO subscriptions (organization_id, plan_name, plan_duration, start_date, end_date, amount, status, payment_status) VALUES (?, 'Free Trial', ?, ?, ?, 0.00, 'active', 'paid')");
                        $stmt->bind_param("iiss", $organization_id, $trial_period, $trial_start, $trial_end);
                        $stmt->execute();
                        
                        // Get Admin role ID
                        $stmt = $conn->prepare("SELECT id FROM roles WHERE name = 'Admin'");
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $admin_role = $result->fetch_assoc();
                        
                        if ($admin_role) {
                            // Create admin user with temporary password (without username)
                            $temp_password = bin2hex(random_bytes(32));
                            $hashed_temp_password = password_hash($temp_password, PASSWORD_DEFAULT);
                            $temp_full_name = $org_name . ' Admin';
                            
                            // Check if user already exists (excluding deleted users)
                            $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND deleted = 0");
                            $check_stmt->bind_param("s", $temp_org['email']);
                            $check_stmt->execute();
                            $check_result = $check_stmt->get_result();
                            
                            if ($check_result->num_rows == 0) {
                                // Insert user without username column
                                $stmt = $conn->prepare("INSERT INTO users (email, password, full_name, role_id, organization_id, status) VALUES (?, ?, ?, ?, ?, 'inactive')");
                                $stmt->bind_param("sssii", $temp_org['email'], $hashed_temp_password, $temp_full_name, $admin_role['id'], $organization_id);
                                $stmt->execute();
                            }
                        }
                        
                        // Generate password setup token
                        require_once 'config/email.php';
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
                        
                        $stmt = $conn->prepare("INSERT INTO password_tokens (email, organization_id, token, expires_at) VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("siss", $temp_org['email'], $organization_id, $token, $token_expiry);
                        $stmt->execute();
                        
                        $conn->commit();
                        
                        // Send password setup email
                        if (sendPasswordSetupEmail($temp_org['email'], $org_name, $token)) {
                            // Clear session
                            unset($_SESSION['verified_email']);
                            unset($_SESSION['verified_temp_id']);
                            unset($_SESSION['pending_verification_email']);
                            
                            $show_org_form = false; // Hide form after success
                            $org_details_submitted = true; // Mark as successfully submitted
                            $success = 'Organization registered successfully! Please check your email to set your password.';
                        } else {
                            $error = 'Organization created but email could not be sent. Please contact support.';
                        }
                    }
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = $e->getMessage();
                
                // Check if error is related to username column
                if (strpos($error_message, 'username') !== false) {
                    $error = 'Database configuration error: Please run the migration script to remove username column from organizations table. Error: ' . $error_message;
                } else {
                    $error = 'Error creating organization: ' . $error_message;
                }
            }
        }
        
        $conn->close();
    }
}

// Check if OTP is already verified (show organization form)
// Only show form if email is verified AND temp record status is VERIFIED
// BUT don't show if org details were already submitted
if (isset($_SESSION['verified_email']) && !$org_details_submitted) {
    $conn = getDBConnection();
    $verified_email_check = $_SESSION['verified_email'];
    
    // Verify that the temp record actually has VERIFIED status
    $stmt = $conn->prepare("SELECT id, account_status FROM organization_temp WHERE email = ? AND account_status = 'VERIFIED' LIMIT 1");
    $stmt->bind_param("s", $verified_email_check);
    $stmt->execute();
    $result = $stmt->get_result();
    $verified_record = $result->fetch_assoc();
    
    if ($verified_record) {
        // Only show form if temp record is actually VERIFIED
        $show_org_form = true;
        $email = $verified_email_check;
    } else {
        // Clear invalid session
        unset($_SESSION['verified_email']);
        unset($_SESSION['verified_temp_id']);
    }
    
    $conn->close();
}

// Handle resend OTP
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['resend_otp'])) {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address';
    } else {
        $conn = getDBConnection();
        
        // Check if email exists in temp table
        $stmt = $conn->prepare("SELECT * FROM organization_temp WHERE email = ? AND account_status = 'PENDING_VERIFICATION'");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $temp_org = $result->fetch_assoc();
        
        if ($temp_org) {
            // Generate new OTP
            require_once 'config/email.php';
            $otp_code = generateOTP();
            $otp_expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            // Update OTP in database
            $stmt = $conn->prepare("UPDATE organization_temp SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
            $stmt->bind_param("ssi", $otp_code, $otp_expires_at, $temp_org['id']);
            $stmt->execute();
            
            // Send new OTP email
            if (sendOTPVerificationEmail($email, $temp_org['name'], $otp_code)) {
                $success = 'A new OTP code has been sent to your email address.';
            } else {
                $error = 'Could not send OTP email. Please try again later.';
            }
        } else {
            $error = 'Email not found or already verified.';
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
    <title>Verify Email - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box" style="max-width: <?php echo ($show_org_form && !$org_details_submitted) ? '600px' : '500px'; ?>;">
            <?php if ($org_details_submitted): ?>
                <h1>Registration Complete!</h1>
                <p class="subtitle">Your organization has been successfully registered</p>
                
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                
                <div style="text-align: center; margin-top: 20px;">
                    <a href="index" class="btn btn-primary">Go to Login</a>
                </div>
            <?php elseif ($show_org_form): ?>
                <h1>Complete Your Registration</h1>
                <p class="subtitle">Email verified! Please provide your organization details</p>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <input type="hidden" name="submit_org_details" value="1">
                    
                    <div class="form-group">
                        <label for="org_name">Organization Name *</label>
                        <input type="text" id="org_name" name="org_name" required autofocus 
                               value="<?php echo htmlspecialchars($_POST['org_name'] ?? ''); ?>"
                               placeholder="Enter your organization name">
                    </div>
                    
                    <div class="form-group">
                        <label for="website">Website</label>
                        <input type="url" id="website" name="website" 
                               value="<?php echo htmlspecialchars($_POST['website'] ?? ''); ?>"
                               placeholder="https://example.com">
                        <small style="color: #666;">Optional</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block" title="Continue">
                        <i class="fas fa-arrow-right"></i> Continue
                    </button>
                </form>
            <?php else: ?>
                <h1>Verify Your Email</h1>
                <p class="subtitle">Enter the 6-digit code sent to your email</p>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" required 
                               value="<?php echo htmlspecialchars($email); ?>"
                               placeholder="Enter your email address">
                    </div>
                    
                    <div class="form-group">
                        <label for="otp_code">OTP Code *</label>
                        <input type="text" id="otp_code" name="otp_code" required 
                               maxlength="6" pattern="[0-9]{6}"
                               placeholder="Enter 6-digit code"
                               style="text-align: center; font-size: 24px; letter-spacing: 8px; font-family: monospace;">
                        <small style="color: #666;">Check your email for the verification code</small>
                    </div>
                    
                    <button type="submit" name="verify_otp" class="btn btn-primary btn-block" title="Verify OTP">
                        <i class="fas fa-check-circle"></i> Verify Email
                    </button>
                </form>
                
                <form method="POST" action="" style="margin-top: 15px;">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    <button type="submit" name="resend_otp" class="btn btn-secondary btn-block" title="Resend OTP">
                        <i class="fas fa-redo"></i> Resend OTP Code
                    </button>
                </form>
            <?php endif; ?>
            
            <div style="text-align: center; margin-top: 20px;">
                <a href="register_organization" style="color: #667eea; text-decoration: none;">Back to Registration</a>
            </div>
        </div>
    </div>
</body>
</html>
