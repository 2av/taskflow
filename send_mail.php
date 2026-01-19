<?php
/**
 * Email Sending Functionality using PHPMailer
 * 
 * This file provides a simple interface for sending emails via SMTP
 * using PHPMailer library.
 */

// Load PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Check if vendor/autoload.php exists (Composer installation)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Fallback: Try to load PHPMailer manually if not using Composer
    $phpmailer_path = __DIR__ . '/vendor/phpmailer/phpmailer/src';
    if (file_exists($phpmailer_path . '/PHPMailer.php')) {
        require_once $phpmailer_path . '/PHPMailer.php';
        require_once $phpmailer_path . '/SMTP.php';
        require_once $phpmailer_path . '/Exception.php';
    } else {
        die('PHPMailer is not installed. Please run: composer require phpmailer/phpmailer');
    }
}

// SMTP Configuration Constants
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'agprimetech.com');
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 465);
}
if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', 'no-reply@agprimetech.com');
}
if (!defined('SMTP_PASSWORD')) {
    define('SMTP_PASSWORD', 'HanumanJi@2025');
}
if (!defined('SMTP_ENCRYPTION')) {
    define('SMTP_ENCRYPTION', 'ssl'); // ssl or tls
}
if (!defined('SMTP_FROM_EMAIL')) {
    define('SMTP_FROM_EMAIL', 'no-reply@agprimetech.com');
}
if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', 'AG Prime Tech');
}

/**
 * Send Email using PHPMailer
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject line
 * @param string $body Email body content (HTML or plain text)
 * @param string|null $fromEmail Sender email address (optional, uses SMTP_USERNAME if not provided)
 * @param string|null $fromName Sender display name (optional, uses SMTP_FROM_NAME if not provided)
 * @param bool $isHTML Whether body is HTML (true) or plain text (false)
 * @return array Returns array with 'success' (bool) and 'message' (string)
 */
function sendMail($to, $subject, $body, $fromEmail = null, $fromName = null, $isHTML = true) {
    try {
        // Validate email address
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Invalid recipient email address'
            ];
        }
        
        // Create PHPMailer instance
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port = SMTP_PORT;
        
        // Enable verbose debug output (uncomment for debugging)
        // $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        
        // Character set
        $mail->CharSet = 'UTF-8';
        
        // Recipients
        $mail->setFrom($fromEmail ?: SMTP_FROM_EMAIL, $fromName ?: SMTP_FROM_NAME);
        $mail->addAddress($to);
        
        // Content
        $mail->isHTML($isHTML);
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        // If HTML, also set plain text alternative
        if ($isHTML) {
            $mail->AltBody = strip_tags($body);
        }
        
        // Send email
        $mail->send();
        
        return [
            'success' => true,
            'message' => 'Email sent successfully'
        ];
        
    } catch (Exception $e) {
        // Log error for debugging
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        
        return [
            'success' => false,
            'message' => 'Email could not be sent. Error: ' . $mail->ErrorInfo
        ];
    }
}
?>
