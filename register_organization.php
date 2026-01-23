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
    $org_email = trim($_POST['org_email'] ?? '');
    
    // Validation
    if (empty($org_email)) {
        $error = 'Please enter your email address';
    } elseif (!filter_var($org_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        $conn = getDBConnection();
        
        // Normalize email to lowercase for consistency
        $org_email_normalized = strtolower(trim($org_email));
        
        // Check if email already exists in organizations table
        $stmt = $conn->prepare("SELECT id, account_status, email_verified FROM organizations WHERE email = ?");
        $stmt->bind_param("s", $org_email_normalized);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing_org = $result->fetch_assoc();
        
        if ($existing_org) {
            // If organization exists and is verified/active, show error
            if ($existing_org['account_status'] == 'ACTIVE' || ($existing_org['account_status'] == 'VERIFIED' && $existing_org['email_verified'] == 1)) {
                $error = 'Email address already registered and verified. Please login or use forgot password.';
            } else {
                // Organization exists but not verified - allow re-registration
                // Delete ALL old temp records for this email
                $stmt = $conn->prepare("DELETE FROM organization_temp WHERE email = ?");
                $stmt->bind_param("s", $org_email_normalized);
                $stmt->execute();
            }
        } else {
            // Email doesn't exist in organizations table - clean up any old temp records
            $stmt = $conn->prepare("DELETE FROM organization_temp WHERE email = ?");
            $stmt->bind_param("s", $org_email_normalized);
            $stmt->execute();
        }
        
        // Proceed with registration (either new or re-registration)
        if (empty($error)) {
            try {
                // Generate OTP
                $otp_code = generateOTP();
                $otp_expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                
                // Store in temporary table (without organization name yet)
                $stmt = $conn->prepare("INSERT INTO organization_temp (name, email, otp_code, otp_expires_at, account_status) VALUES (?, ?, ?, ?, 'PENDING_VERIFICATION')");
                $temp_name = 'Pending Registration'; // Temporary name, will be updated after OTP verification
                $stmt->bind_param("ssss", $temp_name, $org_email_normalized, $otp_code, $otp_expires_at);
                
                if ($stmt->execute()) {
                    // Send OTP email
                    if (sendOTPVerificationEmail($org_email_normalized, 'Your Organization', $otp_code)) {
                        // Store email in session and redirect directly to OTP page (no email in URL for security)
                        $_SESSION['pending_verification_email'] = $org_email_normalized;
                        header('Location: verify_otp');
                        exit();
                    } else {
                        $error = 'Registration successful but email could not be sent. Please contact support.';
                    }
                } else {
                    $error = 'Error creating registration: ' . $conn->error;
                }
            } catch (Exception $e) {
                $error = 'Error creating registration: ' . $e->getMessage();
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
        <div class="login-box max-w-600">
            <h1>Register Your Organization</h1>
            <p class="subtitle">Enter your email to get started</p>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if (!$error): ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="org_email">Email Address *</label>
                        <input type="email" id="org_email" name="org_email" required autofocus 
                               value="<?php echo htmlspecialchars($_POST['org_email'] ?? ''); ?>"
                               placeholder="Enter your email address">
                        <small style="color: #666;">We'll send you a verification code (OTP) to this email</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block" title="Continue">
                        <i class="fas fa-arrow-right"></i> Continue
                    </button>
                </form>
                
                <div class="text-center mt-20">
                    <a href="index" class="link-primary">Already have an account? Login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
