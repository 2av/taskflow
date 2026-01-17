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

/**
 * Simple SMTP Email Sender with Authentication
 */
function sendEmail($to, $subject, $message, $is_html = true) {
    try {
        // Create socket connection
        $socket = @fsockopen('ssl://' . SMTP_HOST, SMTP_PORT, $errno, $errstr, 30);
        
        if (!$socket) {
            error_log("SMTP Connection failed: $errstr ($errno)");
            return false;
        }
        
        // Read server greeting
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '220') {
            fclose($socket);
            return false;
        }
        
        // Send EHLO
        fputs($socket, "EHLO " . SMTP_HOST . "\r\n");
        $response = fgets($socket, 515);
        
        // Start TLS if needed (for port 587)
        // For port 465 with SSL, we're already encrypted
        
        // Authenticate
        fputs($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 515);
        
        if (substr($response, 0, 3) == '334') {
            fputs($socket, base64_encode(SMTP_USERNAME) . "\r\n");
            $response = fgets($socket, 515);
            
            if (substr($response, 0, 3) == '334') {
                fputs($socket, base64_encode(SMTP_PASSWORD) . "\r\n");
                $response = fgets($socket, 515);
                
                if (substr($response, 0, 3) != '235') {
                    fclose($socket);
                    error_log("SMTP Authentication failed: $response");
                    return false;
                }
            } else {
                fclose($socket);
                return false;
            }
        }
        
        // Set sender
        fputs($socket, "MAIL FROM: <" . SMTP_FROM_EMAIL . ">\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '250') {
            fclose($socket);
            return false;
        }
        
        // Set recipient
        fputs($socket, "RCPT TO: <" . $to . ">\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '250') {
            fclose($socket);
            return false;
        }
        
        // Send data
        fputs($socket, "DATA\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != '354') {
            fclose($socket);
            return false;
        }
        
        // Build email headers
        $headers = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
        $headers .= "Reply-To: " . SMTP_FROM_EMAIL . "\r\n";
        $headers .= "To: <" . $to . ">\r\n";
        $headers .= "Subject: " . $subject . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        
        if ($is_html) {
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        }
        
        $headers .= "\r\n";
        
        // Send headers and message
        fputs($socket, $headers . $message . "\r\n.\r\n");
        $response = fgets($socket, 515);
        
        if (substr($response, 0, 3) != '250') {
            fclose($socket);
            error_log("SMTP Send failed: $response");
            return false;
        }
        
        // Quit
        fputs($socket, "QUIT\r\n");
        fclose($socket);
        
        return true;
    } catch (Exception $e) {
        error_log("SMTP Error: " . $e->getMessage());
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
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { background: #f8fafc; padding: 30px; border-radius: 0 0 8px 8px; }
            .button { display: inline-block; background: #14b8a6; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; margin: 20px 0; }
            .footer { text-align: center; margin-top: 20px; color: #64748b; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Welcome to Task Flow System</h1>
            </div>
            <div class='content'>
                <h2>Hello!</h2>
                <p>Your organization <strong>{$organization_name}</strong> has been successfully registered.</p>
                <p>Please set your password by clicking the button below:</p>
                <p style='text-align: center;'>
                    <a href='{$setup_url}' class='button'>Set Password</a>
                </p>
                <p>Or copy and paste this link into your browser:</p>
                <p style='word-break: break-all; color: #14b8a6;'>{$setup_url}</p>
                <p><strong>Note:</strong> This link will expire in 24 hours.</p>
                <p>If you did not request this, please ignore this email.</p>
            </div>
            <div class='footer'>
                <p>This is an automated email from Task Flow System.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($email, $subject, $message, true);
}
?>
