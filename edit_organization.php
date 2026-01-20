<?php
require_once 'config/config.php';
requireLogin();
requireActiveSubscription();

// Only organization admins can edit organization
if (!isOrgAdmin() && !isSuperAdmin()) {
    header('Location: dashboard');
    exit();
}

$page_title = 'Edit Organization';

$conn = getDBConnection();
$message = '';
$error = '';

$organization_id = getOrganizationId();
if (!$organization_id && !isSuperAdmin()) {
    header('Location: dashboard');
    exit();
}

// Get organization data
$org_query = $conn->prepare("SELECT * FROM organizations WHERE id = ?");
$org_query->bind_param("i", $organization_id);
$org_query->execute();
$org_result = $org_query->get_result();
$organization = $org_result->fetch_assoc();
$org_query->close();

if (!$organization) {
    header('Location: dashboard');
    exit();
}

// Check if email already exists
$email_exists = !empty($organization['email']);

// Handle organization update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_organization'])) {
    $name = trim($_POST['name']);
    // If email already exists, keep the existing one; otherwise use submitted value
    $email = $email_exists ? $organization['email'] : trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Validate
    if (empty($name)) {
        $error = 'Organization name is required';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format';
    } else {
        // Handle logo upload
        $logo_filename = $organization['logo'] ?? null;
        
        // Check if logo file was uploaded
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            // Use local upload system
            if (function_exists('uploadImageLocal')) {
                $upload_result = uploadImageLocal($_FILES['logo'], 'organization', 204800); // 200KB max
                
                if ($upload_result['success']) {
                    // Delete old logo if exists
                    if ($logo_filename && function_exists('deleteImageLocal')) {
                        deleteImageLocal($logo_filename, 'organization');
                    }
                    // Store only GUID filename in database
                    $logo_filename = $upload_result['filename'];
                } else {
                    $error = $upload_result['error'] ?? 'Failed to upload logo';
                }
            } else {
                $error = 'Upload system not available';
            }
        } elseif (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            // Handle upload errors
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds 200KB limit',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds 200KB limit',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
            ];
            $error = $error_messages[$_FILES['logo']['error']] ?? 'Failed to upload logo';
        }
        
        if (empty($error)) {
            // Check if logo column exists
            $check_logo = $conn->query("SHOW COLUMNS FROM organizations LIKE 'logo'");
            if ($check_logo->num_rows == 0) {
                $conn->query("ALTER TABLE organizations ADD COLUMN logo VARCHAR(500) NULL AFTER name");
            }
            
            // Update organization (store only GUID filename, not full URL)
            // Always update logo field - if no new logo, keep existing one
            $update_stmt = $conn->prepare("UPDATE organizations SET name = ?, email = ?, phone = ?, address = ?, logo = ? WHERE id = ?");
            $update_stmt->bind_param("sssssi", $name, $email, $phone, $address, $logo_filename, $organization_id);
            
            if ($update_stmt->execute()) {
                $message = 'Organization updated successfully';
                if ($logo_filename) {
                    $message .= '. Logo uploaded: ' . $logo_filename;
                }
                
                // Refresh organization data
                $org_query = $conn->prepare("SELECT * FROM organizations WHERE id = ?");
                $org_query->bind_param("i", $organization_id);
                $org_query->execute();
                $org_result = $org_query->get_result();
                $organization = $org_result->fetch_assoc();
                $org_query->close();
            } else {
                $error = 'Error updating organization: ' . $conn->error;
            }
            $update_stmt->close();
        }
    }
}

include 'includes/header.php';
?>

<style>
.organization-edit-container {
    max-width: 900px;
    margin: 0 auto;
    padding: 24px;
}

.org-header {
    display: flex;
    align-items: center;
    gap: 24px;
    margin-bottom: 32px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--border-color);
}

.org-logo-section {
    position: relative;
}

.org-logo {
    width: 150px;
    height: 150px;
    border-radius: 12px;
    background: var(--page-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.org-logo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.org-logo-placeholder {
    color: var(--text-muted);
    font-size: 48px;
}

.org-logo-upload {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 40px;
    height: 40px;
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

.org-logo-upload:hover {
    background: var(--blue-dark);
    transform: scale(1.1);
}

.org-logo-upload input {
    display: none;
}

.org-info-header h1 {
    font-size: 28px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}

.org-info-header p {
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

.form-group input[readonly] {
    background: var(--border-color) !important;
    cursor: not-allowed;
    opacity: 0.7;
}

.form-group input[readonly]:focus {
    border-color: var(--border-color);
    box-shadow: none;
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
    .organization-edit-container {
        padding: 16px;
    }
    
    .org-header {
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

<div class="organization-edit-container">
    <?php if ($message): ?>
        <div class="alert alert-success" style="margin-bottom: 20px; padding: 12px 16px; background: #DCFCE7; color: #166534; border-radius: 6px;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom: 20px; padding: 12px 16px; background: #FEE2E2; color: #991B1B; border-radius: 6px;">
            <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            <?php if (isset($_POST['update_organization']) && isset($_FILES['logo'])): ?>
                <br><small style="margin-top: 8px; display: block;">
                    File: <?php echo htmlspecialchars($_FILES['logo']['name']); ?><br>
                    Size: <?php echo number_format($_FILES['logo']['size'] / 1024, 2); ?> KB<br>
                    Type: <?php echo htmlspecialchars($_FILES['logo']['type']); ?><br>
                    Error Code: <?php echo $_FILES['logo']['error']; ?>
                </small>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    
    <form method="POST" action="" enctype="multipart/form-data">
        <div class="org-header">
            <div class="org-logo-section">
                <div class="org-logo" id="orgLogo">
                    <?php if (!empty($organization['logo'])): ?>
                        <?php 
                        // Get full URL from GUID filename (local storage)
                        $logo_url = getImageUrl($organization['logo'], 'organization');
                        ?>
                        <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Organization Logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="org-logo-placeholder" style="display: none;">
                            <i class="fas fa-building"></i>
                        </div>
                    <?php else: ?>
                        <div class="org-logo-placeholder">
                            <i class="fas fa-building"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <label for="logoInput" class="org-logo-upload" title="Upload/Change Logo">
                    <i class="fas fa-camera"></i>
                    <input type="file" id="logoInput" name="logo" accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewLogo(this)">
                </label>
            </div>
            <div class="org-info-header">
                <h1><?php echo htmlspecialchars($organization['name']); ?></h1>
                <p>Organization Details</p>
            </div>
        </div>
        <div class="form-section">
            <h2>Organization Information</h2>
            
            <div class="form-group">
                <label for="name">Organization Name *</label>
                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($organization['name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" 
                       value="<?php echo htmlspecialchars($organization['email'] ?? ''); ?>" 
                       <?php if ($email_exists): ?>readonly style="background: var(--border-color); cursor: not-allowed;"<?php endif; ?>>
                <?php if ($email_exists): ?>
                    <small style="color: var(--text-muted);">
                        <i class="fas fa-lock" style="font-size: 10px;"></i> Email is set and cannot be changed
                    </small>
                <?php else: ?>
                    <small>Optional</small>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($organization['phone'] ?? ''); ?>">
                <small>Optional</small>
            </div>
            
            <div class="form-group">
                <label for="address">Address</label>
                <textarea id="address" name="address" placeholder="Organization address..."><?php echo htmlspecialchars($organization['address'] ?? ''); ?></textarea>
                <small>Optional</small>
            </div>
            
            <div class="form-group">
                <label>Organization Logo</label>
                <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: var(--page-bg); border-radius: 6px; border: 1px solid var(--border-color);">
                    <i class="fas fa-info-circle" style="color: var(--text-muted);"></i>
                    <small style="margin: 0; color: var(--text-secondary);">
                        Click the <i class="fas fa-camera" style="margin: 0 4px;"></i> icon on the logo above to upload. Maximum file size: 200KB. Allowed formats: JPEG, PNG, GIF, WebP
                    </small>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <a href="dashboard" class="btn btn-secondary">
                <i class="fas fa-times"></i>
                Cancel
            </a>
            <button type="submit" name="update_organization" value="1" class="btn btn-primary">
                <i class="fas fa-save"></i>
                Save Changes
            </button>
        </div>
    </form>
</div>

<script>
function previewLogo(input) {
    if (input.files && input.files[0]) {
        // Validate file size (200KB = 204800 bytes)
        const maxSize = 204800;
        if (input.files[0].size > maxSize) {
            alert('File size exceeds 200KB limit. Your file is ' + Math.round(input.files[0].size / 1024) + 'KB.');
            input.value = ''; // Clear the input
            return;
        }
        
        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(input.files[0].type)) {
            alert('Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed.');
            input.value = ''; // Clear the input
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            const logo = document.getElementById('orgLogo');
            // Preserve the structure but update the image
            const existingImg = logo.querySelector('img');
            if (existingImg) {
                existingImg.src = e.target.result;
                existingImg.style.display = 'block';
                // Hide placeholder if exists
                const placeholder = logo.querySelector('.org-logo-placeholder');
                if (placeholder) placeholder.style.display = 'none';
            } else {
                // No existing image, create new structure
                const placeholder = logo.querySelector('.org-logo-placeholder');
                if (placeholder) {
                    placeholder.style.display = 'none';
                }
                logo.innerHTML = '<img src="' + e.target.result + '" alt="Organization Logo" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'flex\';">' +
                    '<div class="org-logo-placeholder" style="display: none;"><i class="fas fa-building"></i></div>';
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php include 'includes/footer.php'; ?>
