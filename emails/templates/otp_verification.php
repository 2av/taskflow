<?php
/**
 * OTP Verification Email Template
 * 
 * @param string $organization_name
 * @param string $otp_code
 * @return string HTML email content
 */
function getOTPVerificationEmail($organization_name, $otp_code) {
    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <meta http-equiv='X-UA-Compatible' content='IE=edge'>
        <title>Verify Your Email</title>
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
                                <p style='margin: 10px 0 0 0; color: #ffffff; font-size: 16px; font-weight: 400;'>Email Verification</p>
                            </td>
                        </tr>
                        
                        <!-- Content -->
                        <tr>
                            <td style='padding: 40px 30px;'>
                                <h2 style='margin: 0 0 20px 0; color: #1e293b; font-size: 24px; font-weight: 600; line-height: 1.3;'>Hello!</h2>
                                
                                <p style='margin: 0 0 20px 0; color: #475569; font-size: 16px; line-height: 1.6;'>
                                    Thank you for registering <strong style='color: #0f766e;'>{$organization_name}</strong> with Task Flow System.
                                </p>
                                
                                <p style='margin: 0 0 30px 0; color: #475569; font-size: 16px; line-height: 1.6;'>
                                    To verify your email address, please use the following One-Time Password (OTP):
                                </p>
                                
                                <!-- OTP Code Box -->
                                <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%' style='margin: 30px 0;'>
                                    <tr>
                                        <td align='center' style='padding: 0;'>
                                            <div style='background-color: #f8fafc; border: 2px dashed #14b8a6; border-radius: 8px; padding: 30px; text-align: center;'>
                                                <p style='margin: 0 0 10px 0; color: #64748b; font-size: 14px; font-weight: 500; text-transform: uppercase; letter-spacing: 1px;'>Your Verification Code</p>
                                                <p style='margin: 0; color: #14b8a6; font-size: 36px; font-weight: 700; letter-spacing: 8px; font-family: monospace;'>{$otp_code}</p>
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                                
                                <p style='margin: 20px 0 0 0; color: #475569; font-size: 16px; line-height: 1.6;'>
                                    Enter this code on the verification page to complete your email verification.
                                </p>
                                
                                <!-- Important Notice -->
                                <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%' style='margin: 30px 0;'>
                                    <tr>
                                        <td style='background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 16px 20px; border-radius: 4px;'>
                                            <p style='margin: 0; color: #92400e; font-size: 14px; line-height: 1.6;'>
                                                <strong style='display: block; margin-bottom: 5px;'>⏰ Important:</strong>
                                                This OTP code will expire in <strong>15 minutes</strong> for security reasons. Please verify your email as soon as possible.
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <p style='margin: 30px 0 0 0; color: #64748b; font-size: 14px; line-height: 1.6;'>
                                    If you did not request this email, please ignore it or contact our support team.
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
}
?>
