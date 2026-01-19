<?php
/**
 * User Added by Organization Email Template
 * 
 * @param string $organization_name
 * @param string $user_name
 * @param string $reset_url
 * @return string HTML email content
 */
function getUserAddedEmail($organization_name, $user_name, $reset_url) {
    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <meta http-equiv='X-UA-Compatible' content='IE=edge'>
        <title>Welcome to {$organization_name}</title>
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
                                <p style='margin: 10px 0 0 0; color: #ffffff; font-size: 16px; font-weight: 400;'>Welcome to {$organization_name}</p>
                            </td>
                        </tr>
                        
                        <!-- Content -->
                        <tr>
                            <td style='padding: 40px 30px;'>
                                <h2 style='margin: 0 0 20px 0; color: #1e293b; font-size: 24px; font-weight: 600; line-height: 1.3;'>You've Been Added to {$organization_name}</h2>
                                
                                <p style='margin: 0 0 20px 0; color: #475569; font-size: 16px; line-height: 1.6;'>
                                    Hello <strong style='color: #0f766e;'>{$user_name}</strong>,
                                </p>
                                
                                <p style='margin: 0 0 20px 0; color: #475569; font-size: 16px; line-height: 1.6;'>
                                    You have been added as a team member to <strong style='color: #0f766e;'>{$organization_name}</strong> on Task Flow System. Your account has been created and you can now set up your password to get started.
                                </p>
                                
                                <p style='margin: 0 0 30px 0; color: #475569; font-size: 16px; line-height: 1.6;'>
                                    Click the button below to set your password and activate your account:
                                </p>
                                
                                <!-- Button -->
                                <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%' style='margin: 30px 0;'>
                                    <tr>
                                        <td align='center' style='padding: 0;'>
                                            <!--[if mso]>
                                            <v:roundrect xmlns:v='urn:schemas-microsoft-com:vml' xmlns:w='urn:schemas-microsoft-com:office:word' href='{$reset_url}' style='height:50px;v-text-anchor:middle;width:200px;' arcsize='8%' stroke='f' fillcolor='#14b8a6'>
                                                <w:anchorlock/>
                                                <center style='color:#ffffff;font-family:Arial,sans-serif;font-size:16px;font-weight:600;'>Set Your Password</center>
                                            </v:roundrect>
                                            <![endif]-->
                                            <!--[if !mso]><!-->
                                            <table role='presentation' cellspacing='0' cellpadding='0' border='0'>
                                                <tr>
                                                    <td align='center' style='background-color: #14b8a6; border-radius: 6px; padding: 16px 40px;'>
                                                        <a href='{$reset_url}' style='display: inline-block; color: #ffffff; text-decoration: none; font-size: 16px; font-weight: 600; text-align: center;'>
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
                                                <a href='{$reset_url}' style='color: #14b8a6; text-decoration: none;'>{$reset_url}</a>
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <!-- Important Notice -->
                                <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%' style='margin: 30px 0;'>
                                    <tr>
                                        <td style='background-color: #dbeafe; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 4px;'>
                                            <p style='margin: 0; color: #1e40af; font-size: 14px; line-height: 1.6;'>
                                                <strong style='display: block; margin-bottom: 5px;'>ℹ️ Important:</strong>
                                                This password setup link will expire in <strong>24 hours</strong> for security reasons. If you didn't expect this invitation, please contact your organization administrator.
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <p style='margin: 30px 0 0 0; color: #64748b; font-size: 14px; line-height: 1.6;'>
                                    Once you set your password, you'll be able to log in and start using Task Flow System.
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
