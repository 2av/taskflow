<?php
require_once 'config/config.php';
requireLogin();

$page_title = 'Change Password';

$conn = getDBConnection();
$message = '';
$error = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $user_id = $_SESSION['user_id'];
    
    // Validation
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = 'All fields are required';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New password and confirm password do not match';
    } elseif (strlen($new_password) < 6) {
        $error = 'New password must be at least 6 characters long';
    } else {
        // Verify current password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($current_password, $user['password'])) {
                // Check if new password is different from current
                if (password_verify($new_password, $user['password'])) {
                    $error = 'New password must be different from current password';
                } else {
                    // Update password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->bind_param("si", $hashed_password, $user_id);
                    
                    if ($stmt->execute()) {
                        $message = 'Password changed successfully!';
                        // Clear form
                        $_POST = [];
                    } else {
                        $error = 'Error updating password. Please try again.';
                    }
                }
            } else {
                $error = 'Current password is incorrect';
            }
        } else {
            $error = 'User not found';
        }
    }
}

$conn->close();

include 'includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Change Password</h1>
    <a href="dashboard" class="btn btn-secondary" title="Back to Dashboard"><i class="fas fa-arrow-left"></i></a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <div class="card-header">Update Your Password</div>
    <div class="card-body">
        <form method="POST" action="">
            <div class="form-group">
                <label for="current_password">Current Password *</label>
                <div class="password-input-wrapper">
                    <input type="password" id="current_password" name="current_password" required autofocus>
                    <button type="button" class="password-toggle-icon" onclick="togglePassword('current_password')" title="Show/Hide Password">
                        <i class="fas fa-eye" id="current_password-toggle-icon"></i>
                    </button>
                </div>
            </div>
            
            <div class="form-group">
                <label for="new_password">New Password *</label>
                <div class="password-input-wrapper">
                    <input type="password" id="new_password" name="new_password" required minlength="6">
                    <button type="button" class="password-toggle-icon" onclick="togglePassword('new_password')" title="Show/Hide Password">
                        <i class="fas fa-eye" id="new_password-toggle-icon"></i>
                    </button>
                </div>
                <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                    Password must be at least 6 characters long
                </small>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm New Password *</label>
                <div class="password-input-wrapper">
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                    <button type="button" class="password-toggle-icon" onclick="togglePassword('confirm_password')" title="Show/Hide Password">
                        <i class="fas fa-eye" id="confirm_password-toggle-icon"></i>
                    </button>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 25px;">
                <button type="submit" name="change_password" class="btn btn-primary" title="Change Password"><i class="fas fa-key"></i></button>
                <a href="dashboard" class="btn btn-secondary" title="Cancel"><i class="fas fa-times"></i></a>
            </div>
        </form>
    </div>
</div>

<script>
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(inputId + '-toggle-icon');
    
    if (input && icon) {
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
}

$(document).ready(function() {
    // Password match validation
    $('#confirm_password').on('keyup', function() {
        var newPassword = $('#new_password').val();
        var confirmPassword = $(this).val();
        
        if (confirmPassword.length > 0) {
            if (newPassword !== confirmPassword) {
                $(this).css('border-color', '#e74c3c');
                if ($(this).closest('.password-input-wrapper').next('.password-match').length === 0) {
                    $(this).closest('.password-input-wrapper').after('<small class="password-match" style="color: #e74c3c; display: block; margin-top: 5px;">Passwords do not match</small>');
                }
            } else {
                $(this).css('border-color', '#27ae60');
                $(this).closest('.password-input-wrapper').next('.password-match').remove();
            }
        } else {
            $(this).css('border-color', '#ddd');
            $(this).closest('.password-input-wrapper').next('.password-match').remove();
        }
    });
    
    $('#new_password').on('keyup', function() {
        $('#confirm_password').trigger('keyup');
    });
});
</script>

<?php include 'includes/footer.php'; ?>
