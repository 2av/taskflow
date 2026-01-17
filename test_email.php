<?php
/**
 * Email Test Script
 * 
 * This file tests the email functionality for organization registration.
 * Replace 'your-email@example.com' with your actual email address to test.
 */

require_once 'config/config.php';
require_once 'config/email.php';

// Test email address - CHANGE THIS TO YOUR EMAIL
$test_email = 'your-email@example.com'; // Replace with your email

echo "<h2>Testing Email Functionality</h2>";
echo "<p>Attempting to send test email...</p>";

// Test 1: Basic email sending
echo "<h3>Test 1: Basic Email Sending</h3>";
$result = sendEmail(
    $test_email,
    'Test Email from Task Flow System',
    '<h1>Test Email</h1><p>This is a test email to verify PHPMailer integration is working correctly.</p>',
    true
);

if ($result) {
    echo "<p style='color: green;'>✓ Email sent successfully!</p>";
    echo "<p>Check your inbox at: <strong>$test_email</strong></p>";
} else {
    echo "<p style='color: red;'>✗ Email failed to send.</p>";
    echo "<p>Check PHP error logs for details.</p>";
}

// Test 2: Password setup email (organization registration)
echo "<hr>";
echo "<h3>Test 2: Organization Registration Email</h3>";
$test_token = bin2hex(random_bytes(32));
$result2 = sendPasswordSetupEmail(
    $test_email,
    'Test Organization',
    $test_token
);

if ($result2) {
    echo "<p style='color: green;'>✓ Organization registration email sent successfully!</p>";
    echo "<p>Check your inbox at: <strong>$test_email</strong></p>";
} else {
    echo "<p style='color: red;'>✗ Organization registration email failed to send.</p>";
    echo "<p>Check PHP error logs for details.</p>";
}

echo "<hr>";
echo "<p><strong>Note:</strong> If emails are not received, check:</p>";
echo "<ul>";
echo "<li>SMTP credentials in config/email.php</li>";
echo "<li>PHP error logs</li>";
echo "<li>Spam/junk folder</li>";
echo "<li>Firewall settings (port 465)</li>";
echo "</ul>";
?>
