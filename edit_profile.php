<?php
require_once 'config/config.php';
requireLogin();
requireActiveSubscription();

$page_title = 'Edit Profile';

$conn = getDBConnection();
$message = '';
$error = '';

// Get current user data
$user_id = $_SESSION['user_id'];
$user_query = $conn->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user = $user_result->fetch_assoc();
$user_query->close();

if (!$user) {
    header('Location: dashboard');
    exit();
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    
    // Validate
    if (empty($full_name)) {
        $error = 'Full name is required';
    } elseif (empty($email)) {
        $error = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format';
    } else {
        // Check if email is already taken by another user
        $email_check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $email_check->bind_param("si", $email, $user_id);
        $email_check->execute();
        $email_result = $email_check->get_result();
        
        if ($email_result->num_rows > 0) {
            $error = 'Email is already taken by another user';
        } else {
            // Handle profile picture upload
            $profile_picture_url = $user['profile_picture'] ?? null;
            
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                if (function_exists('uploadImageFromFile')) {
                    $upload_result = uploadImageFromFile($_FILES['profile_picture'], 'taskflow/profiles');
                    
                    if ($upload_result['success']) {
                        // Delete old profile picture from Cloudinary if exists
                        if ($profile_picture_url && function_exists('deleteFromCloudinary')) {
                            // Extract public_id from URL if it's a Cloudinary URL
                            if (strpos($profile_picture_url, 'cloudinary.com') !== false) {
                                preg_match('/\/([^\/]+)\/([^\/]+)\.(jpg|png|gif|webp)$/i', $profile_picture_url, $matches);
                                if (isset($matches[1]) && isset($matches[2])) {
                                    $public_id = $matches[1] . '/' . $matches[2];
                                    deleteFromCloudinary($public_id);
                                }
                            }
                        }
                        $profile_picture_url = $upload_result['url'];
                    } else {
                        $error = $upload_result['error'] ?? 'Failed to upload profile picture';
                    }
                } else {
                    // Fallback to local upload if Cloudinary not configured
                    $upload_dir = __DIR__ . '/uploads/profiles/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                    $new_filename = 'profile_' . $user_id . '_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                        $profile_picture_url = 'uploads/profiles/' . $new_filename;
                    } else {
                        $error = 'Failed to upload profile picture';
                    }
                }
            }
            
            if (empty($error)) {
                // Check if profile_picture column exists, if not add it
                $check_column = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
                if ($check_column->num_rows == 0) {
                    $conn->query("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(500) NULL AFTER email");
                }
                
                // Check if phone column exists
                $check_phone = $conn->query("SHOW COLUMNS FROM users LIKE 'phone'");
                if ($check_phone->num_rows == 0) {
                    $conn->query("ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL AFTER email");
                }
                
                // Check if bio column exists
                $check_bio = $conn->query("SHOW COLUMNS FROM users LIKE 'bio'");
                if ($check_bio->num_rows == 0) {
                    $conn->query("ALTER TABLE users ADD COLUMN bio TEXT NULL AFTER phone");
                }
                
                // Update user profile
                if ($profile_picture_url) {
                    $update_stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, bio = ?, profile_picture = ? WHERE id = ?");
                    $update_stmt->bind_param("sssssi", $full_name, $email, $phone, $bio, $profile_picture_url, $user_id);
                } else {
                    $update_stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, bio = ? WHERE id = ?");
                    $update_stmt->bind_param("ssssi", $full_name, $email, $phone, $bio, $user_id);
                }
                
                if ($update_stmt->execute()) {
                    // Update session
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['email'] = $email;
                    
                    $message = 'Profile updated successfully';
                    
                    // Refresh user data
                    $user_query = $conn->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
                    $user_query->bind_param("i", $user_id);
                    $user_query->execute();
                    $user_result = $user_query->get_result();
                    $user = $user_result->fetch_assoc();
                    $user_query->close();
                } else {
                    $error = 'Error updating profile: ' . $conn->error;
                }
                $update_stmt->close();
            }
        }
        $email_check->close();
    }
}

include 'includes/header.php';
?>

<style>
.profile-edit-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 24px;
}

.profile-header {
    display: flex;
    align-items: center;
    gap: 24px;
    margin-bottom: 32px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--border-color);
}

.profile-avatar-section {
    position: relative;
}

.profile-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 48px;
    font-weight: 600;
    overflow: hidden;
    border: 4px solid var(--card-bg);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.profile-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-avatar-upload {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 36px;
    height: 36px;
    background: var(--blue);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    cursor: pointer;
    border: 3px solid var(--card-bg);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    transition: all 0.2s;
}

.profile-avatar-upload:hover {
    background: var(--blue-dark);
    transform: scale(1.1);
}

.profile-avatar-upload input {
    display: none;
}

.profile-info-header h1 {
    font-size: 24px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 4px 0;
}

.profile-info-header p {
    font-size: 14px;
    color: var(--text-secondary);
    margin: 0;
}

.form-section {
    background: var(--card-bg);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    padding: 24px;
    margin-bottom: 24px;
}

.form-section h2 {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 20px 0;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-color);
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    margin-bottom: 8px;
}

.form-group input,
.form-group textarea {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
    background: var(--page-bg);
    color: var(--text-primary);
    transition: all 0.2s;
}

.form-group input:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--blue);
    box-shadow: 0 0 0 3px var(--blue-light);
}

.form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.form-group small {
    display: block;
    margin-top: 6px;
    font-size: 12px;
    color: var(--text-muted);
}

.form-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 24px;
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary {
    background: var(--blue);
    color: white;
}

.btn-primary:hover {
    background: var(--blue-dark);
}

.btn-secondary {
    background: var(--page-bg);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}

.btn-secondary:hover {
    background: var(--border-color);
}

@media (max-width: 768px) {
    .profile-edit-container {
        padding: 16px;
    }
    
    .profile-header {
        flex-direction: column;
        text-align: center;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="profile-edit-container">
    <?php if ($message): ?>
        <div class="alert alert-success" style="margin-bottom: 20px; padding: 12px 16px; background: #DCFCE7; color: #166534; border-radius: 6px;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom: 20px; padding: 12px 16px; background: #FEE2E2; color: #991B1B; border-radius: 6px;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <div class="profile-header">
        <div class="profile-avatar-section">
            <div class="profile-avatar" id="profileAvatar">
                <?php if (!empty($user['profile_picture'])): ?>
                    <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Picture">
                <?php else: ?>
                    <?php 
                    $name_parts = explode(' ', $user['full_name']);
                    $initials = '';
                    if (count($name_parts) >= 2) {
                        $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts) - 1], 0, 1));
                    } else {
                        $initials = strtoupper(substr($user['full_name'], 0, 2));
                    }
                    echo htmlspecialchars($initials);
                    ?>
                <?php endif; ?>
            </div>
            <label for="profilePictureInput" class="profile-avatar-upload" title="Change Profile Picture">
                <i class="fas fa-camera"></i>
                <input type="file" id="profilePictureInput" name="profile_picture" accept="image/*" onchange="previewProfilePicture(this)">
            </label>
        </div>
        <div class="profile-info-header">
            <h1><?php echo htmlspecialchars($user['full_name']); ?></h1>
            <p><?php echo htmlspecialchars($user['role_name']); ?></p>
        </div>
    </div>
    
    <form method="POST" action="" enctype="multipart/form-data">
        <div class="form-section">
            <h2>Personal Information</h2>
            
            <div class="form-group">
                <label for="full_name">Full Name *</label>
                <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                <small>Optional</small>
            </div>
            
            <div class="form-group">
                <label for="bio">Bio</label>
                <textarea id="bio" name="bio" placeholder="Tell us about yourself..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                <small>Optional. A brief description about yourself.</small>
            </div>
        </div>
        
        <div class="form-actions">
            <a href="dashboard" class="btn btn-secondary">
                <i class="fas fa-times"></i>
                Cancel
            </a>
            <button type="submit" name="update_profile" value="1" class="btn btn-primary">
                <i class="fas fa-save"></i>
                Save Changes
            </button>
        </div>
    </form>
</div>

<script>
function previewProfilePicture(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const avatar = document.getElementById('profileAvatar');
            avatar.innerHTML = '<img src="' + e.target.result + '" alt="Profile Picture">';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php include 'includes/footer.php'; ?>
