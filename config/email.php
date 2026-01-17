<?php
/**
 * Email Configuration
 */
define('SMTP_HOST', 'ayodhyakashiyatra.com');
define('SMTP_PORT', 465);
define('SMTP_USERNAME', 'no-reply@ayodhyakashiyatra.com');
define('SMTP_PASSWORD', 'HanumanJi@2025');
define('SMTP_FROM_EMAIL', 'no-reply@ayodhyakashiyatra.com');
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
 * Send Password Setup Email
 */
function sendPasswordSetupEmail($email, $organization_name, $token) {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $host = $_SERVER['HTTP_HOST'];
    $script_path = dirname($_SERVER['PHP_SELF']);
    $script_path = rtrim($script_path, '/');
    if (empty($script_path) || $script_path === '/') {
        $script_path = '';
    }
    $setup_url = $protocol . "://" . $host . $script_path . "/set_password.php?token=" . urlencode($token);
    
    $subject = "Set Your Password - " . $organization_name;
    
    $message = "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <meta http-equiv='X-UA-Compatible' content='IE=edge'>
        <title>Set Your Password</title>
        <!--[if mso]>
        <style type='text/css'>
            body, table, td {font-family: Arial, sans-serif !important;}
        </style>
        <![endif]-->
    </head>
    <body style='margin: 0; padding: 0; background-color: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif;'>
        <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%' style='background-color: #f4f6f9;'>
            <tr>
                <td style='padding: 40px 20px;'>
                    <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%' style='max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
                        <!-- Header -->
                        <tr>
                            <td style='background-color: #14b8a6; padding: 40px 30px; text-align: center; border-radius: 8px 8px 0 0;'>
                                <h1 style='margin: 0; color: #ffffff; font-size: 28px; font-weight: 600; letter-spacing: -0.5px;'>Task Flow System</h1>
                                <p style='margin: 10px 0 0 0; color: #ffffff; font-size: 16px; font-weight: 400;'>Welcome to Your Organization</p>
                            </td>
                        </tr>
                        
                        <!-- Content -->
                        <tr>
                            <td style='padding: 40px 30px;'>
                                <h2 style='margin: 0 0 20px 0; color: #1e293b; font-size: 24px; font-weight: 600; line-height: 1.3;'>Hello!</h2>
                                
                                <p style='margin: 0 0 20px 0; color: #475569; font-size: 16px; line-height: 1.6;'>
                                    Your organization <strong style='color: #0f766e;'>{$organization_name}</strong> has been successfully registered with Task Flow System.
                                </p>
                                
                                <p style='margin: 0 0 30px 0; color: #475569; font-size: 16px; line-height: 1.6;'>
                                    To complete your account setup, please set your password by clicking the button below:
                                </p>
                                
                                <!-- Button -->
                                <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%' style='margin: 30px 0;'>
                                    <tr>
                                        <td align='center' style='padding: 0;'>
                                            <!--[if mso]>
                                            <v:roundrect xmlns:v='urn:schemas-microsoft-com:vml' xmlns:w='urn:schemas-microsoft-com:office:word' href='{$setup_url}' style='height:50px;v-text-anchor:middle;width:200px;' arcsize='8%' stroke='f' fillcolor='#14b8a6'>
                                                <w:anchorlock/>
                                                <center style='color:#ffffff;font-family:Arial,sans-serif;font-size:16px;font-weight:600;'>Set Your Password</center>
                                            </v:roundrect>
                                            <![endif]-->
                                            <!--[if !mso]><!-->
                                            <table role='presentation' cellspacing='0' cellpadding='0' border='0'>
                                                <tr>
                                                    <td align='center' style='background-color: #14b8a6; border-radius: 6px; padding: 16px 40px;'>
                                                        <a href='{$setup_url}' style='display: inline-block; color: #ffffff; text-decoration: none; font-size: 16px; font-weight: 600; text-align: center;'>
                                                            Set Your Password
                                                        </a>
                                                    </td>
                                                </tr>
                                            </table>
                                            <!--<![endif]-->
                                        </td>
                                    </tr>
                                </table>
                                
                                <!-- Alternative Link Section -->
                                <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%' style='margin: 30px 0;'>
                                    <tr>
                                        <td style='background-color: #f8fafc; border-left: 4px solid #14b8a6; padding: 20px; border-radius: 4px;'>
                                            <p style='margin: 0 0 10px 0; color: #64748b; font-size: 14px; font-weight: 500;'>Can't click the button?</p>
                                            <p style='margin: 0; color: #475569; font-size: 13px; line-height: 1.6; word-break: break-all;'>
                                                Copy and paste this link into your browser:<br>
                                                <a href='{$setup_url}' style='color: #14b8a6; text-decoration: none;'>{$setup_url}</a>
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <!-- Important Notice -->
                                <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%' style='margin: 30px 0;'>
                                    <tr>
                                        <td style='background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 16px 20px; border-radius: 4px;'>
                                            <p style='margin: 0; color: #92400e; font-size: 14px; line-height: 1.6;'>
                                                <strong style='display: block; margin-bottom: 5px;'>⏰ Important:</strong>
                                                This password setup link will expire in <strong>24 hours</strong> for security reasons. Please complete your account setup as soon as possible.
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <p style='margin: 30px 0 0 0; color: #64748b; font-size: 14px; line-height: 1.6;'>
                                    If you did not request this email or if you have any questions, please contact our support team immediately.
                                </p>
                            </td>
                        </tr>
                        
                        <!-- Footer -->
                        <tr>
                            <td style='background-color: #f8fafc; padding: 30px; text-align: center; border-radius: 0 0 8px 8px; border-top: 1px solid #e2e8f0;'>
                                <p style='margin: 0 0 10px 0; color: #64748b; font-size: 13px; line-height: 1.6;'>
                                    This is an automated email from <strong style='color: #0f766e;'>Task Flow System</strong>
                                </p>
                                <p style='margin: 0; color: #94a3b8; font-size: 12px; line-height: 1.6;'>
                                    Please do not reply to this email. This mailbox is not monitored.
                                </p>
                                <p style='margin: 15px 0 0 0; color: #94a3b8; font-size: 11px; line-height: 1.6;'>
                                    © " . date('Y') . " Task Flow System. All rights reserved.
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- Bottom Spacing -->
                    <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%' style='max-width: 600px; margin: 20px auto 0;'>
                        <tr>
                            <td style='text-align: center; padding: 20px 0;'>
                                <p style='margin: 0; color: #94a3b8; font-size: 12px; line-height: 1.6;'>
                                    Need help? Contact us at <a href='mailto:support@agprimetech.com' style='color: #14b8a6; text-decoration: none;'>support@agprimetech.com</a>
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    ";
    
    return sendEmail($email, $subject, $message, true);
}
?>
