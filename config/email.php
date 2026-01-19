<?php
/**
 * Email Configuration
 */
define('SMTP_HOST', 'agprimetech.com');
define('SMTP_PORT', 465);
define('SMTP_USERNAME', 'no-reply@agprimetech.com');
define('SMTP_PASSWORD', 'HanumanJi@2025');
define('SMTP_FROM_EMAIL', 'no-reply@agprimetech.com');
define('SMTP_FROM_NAME', 'Task Flow System');
define('SMTP_SECURE', 'ssl'); // ssl or tls
define('SMTP_ENCRYPTION', 'ssl'); // Alias for SMTP_SECURE

// Load PHPMailer autoloader
$vendor_autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendor_autoload)) {
    require_once $vendor_autoload;
} else {
    // Fallback: Try to load PHPMailer manually if not using Composer
    $phpmailer_path = __DIR__ . '/../vendor/phpmailer/phpmailer/src';
    if (file_exists($phpmailer_path . '/PHPMailer.php')) {
        require_once $phpmailer_path . '/PHPMailer.php';
        require_once $phpmailer_path . '/SMTP.php';
        require_once $phpmailer_path . '/Exception.php';
    } else {
        error_log('PHPMailer is not installed. Please run: composer require phpmailer/phpmailer');
    }
}

/**
 * Send Email using PHPMailer (Upgraded from raw socket implementation)
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject line
 * @param string $message Email body content (HTML or plain text)
 * @param bool $is_html Whether body is HTML (true) or plain text (false)
 * @return bool Returns true on success, false on failure
 */
function sendEmail($to, $subject, $message, $is_html = true) {
    try {
        // Validate email address
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("Invalid recipient email address: $to");
            return false;
        }
        
        // Check if PHPMailer is available
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log('PHPMailer class not found. Please install PHPMailer.');
            return false;
        }
        
        // Create PHPMailer instance (using fully qualified class name)
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port = SMTP_PORT;
        
        // Enable verbose debug output (uncomment for debugging)
        // $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
        
        // Character set
        $mail->CharSet = 'UTF-8';
        
        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);
        $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        
        // Content
        $mail->isHTML($is_html);
        $mail->Subject = $subject;
        $mail->Body = $message;
        
        // If HTML, also set plain text alternative
        if ($is_html) {
            $mail->AltBody = strip_tags($message);
        }
        
        // Send email
        $mail->send();
        
        return true;
        
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        // Log error for debugging
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Load email templates
 */
function loadEmailTemplates() {
    $templates_dir = __DIR__ . '/../emails/templates/';
    if (file_exists($templates_dir . 'otp_verification.php')) {
        require_once $templates_dir . 'otp_verification.php';
    }
    if (file_exists($templates_dir . 'password_setup.php')) {
        require_once $templates_dir . 'password_setup.php';
    }
    if (file_exists($templates_dir . 'account_activated.php')) {
        require_once $templates_dir . 'account_activated.php';
    }
    if (file_exists($templates_dir . 'password_reset.php')) {
        require_once $templates_dir . 'password_reset.php';
    }
    if (file_exists($templates_dir . 'user_added.php')) {
        require_once $templates_dir . 'user_added.php';
    }
    if (file_exists($templates_dir . 'project_assignment_request.php')) {
        require_once $templates_dir . 'project_assignment_request.php';
    }
}

// Load email templates
loadEmailTemplates();

/**
 * Generate OTP Code
 * @return string 6-digit OTP code
 */
function generateOTP() {
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Build URL with proper path normalization
 */
function buildUrl($path, $params = []) {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $host = $_SERVER['HTTP_HOST'];
    $script_path = dirname($_SERVER['PHP_SELF']);
    // Normalize path separators (convert backslashes to forward slashes)
    $script_path = str_replace('\\', '/', $script_path);
    $script_path = rtrim($script_path, '/');
    if (empty($script_path) || $script_path === '/') {
        $script_path = '';
    }
    $url = $protocol . "://" . $host . $script_path . "/" . ltrim($path, '/');
    // Remove any double slashes (except after protocol)
    $url = preg_replace('#([^:])//+#', '$1/', $url);
    
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    
    return $url;
}

/**
 * Send OTP Verification Email
 */
function sendOTPVerificationEmail($email, $organization_name, $otp_code) {
    if (!function_exists('getOTPVerificationEmail')) {
        error_log('OTP verification email template not found');
        return false;
    }
    
    $subject = "Verify Your Email - " . $organization_name;
    $message = getOTPVerificationEmail($organization_name, $otp_code);
    
    return sendEmail($email, $subject, $message, true);
}

/**
 * Send Password Setup Email
 */
function sendPasswordSetupEmail($email, $organization_name, $token) {
    if (!function_exists('getPasswordSetupEmail')) {
        error_log('Password setup email template not found');
        return false;
    }
    
    $setup_url = buildUrl('set_password.php', ['token' => $token]);
    $subject = "Set Your Password - " . $organization_name;
    $message = getPasswordSetupEmail($organization_name, $setup_url);
    
    return sendEmail($email, $subject, $message, true);
}

/**
 * Send Account Activated Email
 */
function sendAccountActivatedEmail($email, $organization_name) {
    if (!function_exists('getAccountActivatedEmail')) {
        error_log('Account activated email template not found');
        return false;
    }
    
    $login_url = buildUrl('index.php');
    $subject = "Account Activated - " . $organization_name;
    $message = getAccountActivatedEmail($organization_name, $login_url);
    
    return sendEmail($email, $subject, $message, true);
}

/**
 * Send Password Reset Email
 */
function sendPasswordResetEmail($email, $organization_name, $token) {
    if (!function_exists('getPasswordResetEmail')) {
        error_log('Password reset email template not found');
        return false;
    }
    
    $reset_url = buildUrl('reset_password', ['token' => $token]);
    $subject = "Reset Your Password - " . $organization_name;
    $message = getPasswordResetEmail($organization_name, $reset_url);
    
    return sendEmail($email, $subject, $message, true);
}

/**
 * Send User Added by Organization Email
 */
function sendUserAddedEmail($email, $organization_name, $user_name, $token) {
    if (!function_exists('getUserAddedEmail')) {
        error_log('User added email template not found');
        return false;
    }
    
    $reset_url = buildUrl('reset_password', ['token' => $token]);
    $subject = "Welcome to " . $organization_name . " - Set Your Password";
    $message = getUserAddedEmail($organization_name, $user_name, $reset_url);
    
    return sendEmail($email, $subject, $message, true);
}

/**
 * Send Project Assignment Request Email to Admin
 */
function sendProjectAssignmentRequestEmail($admin_email, $admin_name, $requester_name, $requester_email, $organization_name) {
    if (!function_exists('getProjectAssignmentRequestEmail')) {
        error_log('Project assignment request email template not found');
        return false;
    }
    
    $dashboard_url = buildUrl('dashboard');
    $subject = "Project Assignment Request - " . $organization_name;
    $message = getProjectAssignmentRequestEmail($admin_name, $requester_name, $requester_email, $organization_name, $dashboard_url);
    
    return sendEmail($admin_email, $subject, $message, true);
}
?>
