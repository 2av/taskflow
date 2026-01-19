<?php
/**
 * Project Assignment Request Email Template
 * 
 * @param string $admin_name
 * @param string $requester_name
 * @param string $requester_email
 * @param string $organization_name
 * @param string $dashboard_url
 * @return string HTML email content
 */
function getProjectAssignmentRequestEmail($admin_name, $requester_name, $requester_email, $organization_name, $dashboard_url = '') {
    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <meta http-equiv='X-UA-Compatible' content='IE=edge'>
        <title>Project Assignment Request - {$organization_name}</title>
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
                                <p style='margin: 10px 0 0 0; color: #ffffff; font-size: 16px; font-weight: 400;'>Project Assignment Request</p>
                            </td>
                        </tr>
                        
                        <!-- Content -->
                        <tr>
                            <td style='padding: 40px 30px;'>
                                <h2 style='margin: 0 0 20px 0; color: #1e293b; font-size: 24px; font-weight: 600; line-height: 1.3;'>Project Assignment Request</h2>
                                
                                <p style='margin: 0 0 20px 0; color: #475569; font-size: 16px; line-height: 1.6;'>
                                    Hello <strong style='color: #0f766e;'>{$admin_name}</strong>,
                                </p>
                                
                                <p style='margin: 0 0 20px 0; color: #475569; font-size: 16px; line-height: 1.6;'>
                                    A user from <strong style='color: #0f766e;'>{$organization_name}</strong> has requested to be assigned to a project.
                                </p>
                                
                                <!-- User Details Box -->
                                <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%' style='margin: 30px 0; background-color: #f8fafc; border-left: 4px solid #14b8a6; padding: 20px; border-radius: 4px;'>
                                    <tr>
                                        <td>
                                            <p style='margin: 0 0 10px 0; color: #64748b; font-size: 14px; font-weight: 500;'>Requesting User:</p>
                                            <p style='margin: 0 0 8px 0; color: #1e293b; font-size: 16px; font-weight: 600;'>
                                                <i class='fas fa-user' style='margin-right: 8px; color: #14b8a6;'></i>
                                                {$requester_name}
                                            </p>
                                            <p style='margin: 0; color: #475569; font-size: 14px;'>
                                                <i class='fas fa-envelope' style='margin-right: 8px; color: #94a3b8;'></i>
                                                <a href='mailto:{$requester_email}' style='color: #14b8a6; text-decoration: none;'>{$requester_email}</a>
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <p style='margin: 30px 0 20px 0; color: #475569; font-size: 16px; line-height: 1.6;'>
                                    Please log in to Task Flow System and assign this user to an appropriate project.
                                </p>
                                
                                
                                <!-- Important Notice -->
                                <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%' style='margin: 30px 0;'>
                                    <tr>
                                        <td style='background-color: #dbeafe; border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 4px;'>
                                            <p style='margin: 0; color: #1e40af; font-size: 14px; line-height: 1.6;'>
                                                <strong style='display: block; margin-bottom: 5px;'>ℹ️ Note:</strong>
                                                You can assign users to projects from the Projects or Users section in the dashboard.
                                            </p>
                                        </td>
                                    </tr>
                                </table>
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
