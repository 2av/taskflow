<?php
require_once 'config/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard');
    exit();
}

$error = '';
$success = '';
$organization_id = $_SESSION['verified_organization_id'] ?? null;
$organization_name = $_SESSION['verified_organization_name'] ?? '';
$organization_email = $_SESSION['verified_organization_email'] ?? '';

if (!$organization_id) {
    header('Location: register_organization');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $postal_code = trim($_POST['postal_code'] ?? '');
    $industry = trim($_POST['industry'] ?? '');
    $website = trim($_POST['website'] ?? '');
    
    $conn = getDBConnection();
    
    try {
        // Update organization details
        $stmt = $conn->prepare("UPDATE organizations SET phone = ?, address = ?, city = ?, state = ?, country = ?, postal_code = ?, industry = ?, website = ? WHERE id = ?");
        $stmt->bind_param("ssssssssi", $phone, $address, $city, $state, $country, $postal_code, $industry, $website, $organization_id);
        
        if ($stmt->execute()) {
            // Generate password setup token
            require_once 'config/email.php';
            $token = bin2hex(random_bytes(32));
            $token_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            // Store token in database
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
            $stmt->bind_param("siss", $organization_email, $organization_id, $token, $token_expiry);
            $stmt->execute();
            
            // Send password setup email
            if (sendPasswordSetupEmail($organization_email, $organization_name, $token)) {
                // Clear session
                unset($_SESSION['verified_organization_id']);
                unset($_SESSION['verified_organization_name']);
                unset($_SESSION['verified_organization_email']);
                
                $success = 'Organization details saved successfully! Please check your email to set your password.';
            } else {
                $error = 'Details saved but email could not be sent. Please contact support.';
            }
        } else {
            $error = 'Error updating organization details: ' . $conn->error;
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
    
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Organization Details - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box" style="max-width: 700px;">
            <h1>Complete Organization Details</h1>
            <p class="subtitle">Welcome to <strong><?php echo htmlspecialchars($organization_name); ?></strong></p>
            <p style="color: #666; font-size: 14px; margin-bottom: 20px;">Please provide additional information about your organization (all fields are optional)</p>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="index" class="btn btn-primary">Go to Login</a>
                </div>
            <?php else: ?>
                <form method="POST" action="">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label for="phone">Phone</label>
                            <input type="tel" id="phone" name="phone" 
                                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                   placeholder="Enter phone number">
                        </div>
                        
                        <div class="form-group">
                            <label for="industry">Industry</label>
                            <input type="text" id="industry" name="industry" 
                                   value="<?php echo htmlspecialchars($_POST['industry'] ?? ''); ?>"
                                   placeholder="Enter industry">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="address">Address</label>
                        <input type="text" id="address" name="address" 
                               value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>"
                               placeholder="Enter street address">
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label for="city">City</label>
                            <input type="text" id="city" name="city" 
                                   value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>"
                                   placeholder="Enter city">
                        </div>
                        
                        <div class="form-group">
                            <label for="state">State/Province</label>
                            <input type="text" id="state" name="state" 
                                   value="<?php echo htmlspecialchars($_POST['state'] ?? ''); ?>"
                                   placeholder="Enter state">
                        </div>
                        
                        <div class="form-group">
                            <label for="postal_code">Postal Code</label>
                            <input type="text" id="postal_code" name="postal_code" 
                                   value="<?php echo htmlspecialchars($_POST['postal_code'] ?? ''); ?>"
                                   placeholder="Enter postal code">
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label for="country">Country</label>
                            <input type="text" id="country" name="country" 
                                   value="<?php echo htmlspecialchars($_POST['country'] ?? ''); ?>"
                                   placeholder="Enter country">
                        </div>
                        
                        <div class="form-group">
                            <label for="website">Website</label>
                            <input type="url" id="website" name="website" 
                                   value="<?php echo htmlspecialchars($_POST['website'] ?? ''); ?>"
                                   placeholder="https://example.com">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block" title="Continue">
                        <i class="fas fa-arrow-right"></i> Continue to Password Setup
                    </button>
                </form>
                
                <div style="text-align: center; margin-top: 20px;">
                    <a href="index" style="color: #667eea; text-decoration: none;">Skip and go to Login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
