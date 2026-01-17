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

// Handle organization update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_organization'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Validate
    if (empty($name)) {
        $error = 'Organization name is required';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format';
    } else {
        // Handle logo upload
        $logo_url = $organization['logo'] ?? null;
        
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            if (function_exists('uploadImageFromFile')) {
                $upload_result = uploadImageFromFile($_FILES['logo'], 'taskflow/organizations');
                
                if ($upload_result['success']) {
                    // Delete old logo from Cloudinary if exists
                    if ($logo_url && function_exists('deleteFromCloudinary')) {
                        if (strpos($logo_url, 'cloudinary.com') !== false) {
                            preg_match('/\/([^\/]+)\/([^\/]+)\.(jpg|png|gif|webp)$/i', $logo_url, $matches);
                            if (isset($matches[1]) && isset($matches[2])) {
                                $public_id = $matches[1] . '/' . $matches[2];
                                deleteFromCloudinary($public_id);
                            }
                        }
                    }
                    $logo_url = $upload_result['url'];
                } else {
                    $error = $upload_result['error'] ?? 'Failed to upload logo';
                }
            } else {
                // Fallback to local upload
                $upload_dir = __DIR__ . '/uploads/organizations/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                $new_filename = 'org_' . $organization_id . '_' . time() . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
                    $logo_url = 'uploads/organizations/' . $new_filename;
                } else {
                    $error = 'Failed to upload logo';
                }
            }
        }
        
        if (empty($error)) {
            // Check if logo column exists
            $check_logo = $conn->query("SHOW COLUMNS FROM organizations LIKE 'logo'");
            if ($check_logo->num_rows == 0) {
                $conn->query("ALTER TABLE organizations ADD COLUMN logo VARCHAR(500) NULL AFTER name");
            }
            
            // Update organization
            if ($logo_url) {
                $update_stmt = $conn->prepare("UPDATE organizations SET name = ?, email = ?, phone = ?, address = ?, logo = ? WHERE id = ?");
                $update_stmt->bind_param("sssssi", $name, $email, $phone, $address, $logo_url, $organization_id);
            } else {
                $update_stmt = $conn->prepare("UPDATE organizations SET name = ?, email = ?, phone = ?, address = ? WHERE id = ?");
                $update_stmt->bind_param("ssssi", $name, $email, $phone, $address, $organization_id);
            }
            
            if ($update_stmt->execute()) {
                $message = 'Organization updated successfully';
                
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
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <div class="org-header">
        <div class="org-logo-section">
            <div class="org-logo" id="orgLogo">
                <?php if (!empty($organization['logo'])): ?>
                    <img src="<?php echo htmlspecialchars($organization['logo']); ?>" alt="Organization Logo">
                <?php else: ?>
                    <div class="org-logo-placeholder">
                        <i class="fas fa-building"></i>
                    </div>
                <?php endif; ?>
            </div>
            <label for="logoInput" class="org-logo-upload" title="Change Logo">
                <i class="fas fa-camera"></i>
                <input type="file" id="logoInput" name="logo" accept="image/*" onchange="previewLogo(this)">
            </label>
        </div>
        <div class="org-info-header">
            <h1><?php echo htmlspecialchars($organization['name']); ?></h1>
            <p>Organization Details</p>
        </div>
    </div>
    
    <form method="POST" action="" enctype="multipart/form-data">
        <div class="form-section">
            <h2>Organization Information</h2>
            
            <div class="form-group">
                <label for="name">Organization Name *</label>
                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($organization['name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($organization['email'] ?? ''); ?>">
                <small>Optional</small>
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
        const reader = new FileReader();
        reader.onload = function(e) {
            const logo = document.getElementById('orgLogo');
            logo.innerHTML = '<img src="' + e.target.result + '" alt="Organization Logo">';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php include 'includes/footer.php'; ?>
