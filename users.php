<?php
require_once 'config/config.php';
require_once 'config/email.php';
requireAdmin();
requireActiveSubscription();

$page_title = 'User Management';

$conn = getDBConnection();
$message = '';
$error = '';

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_user'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'] ?? '';
    $full_name = trim($_POST['full_name']);
    $role_id = intval($_POST['role_id']);
    $status = $_POST['status'];
    $organization_id = isSuperAdmin() ? (isset($_POST['organization_id']) && !empty($_POST['organization_id']) ? intval($_POST['organization_id']) : null) : getOrganizationId();
    
    if (empty($email) || empty($full_name)) {
        $error = 'Email and full name are required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif (!$organization_id && !isSuperAdmin()) {
        $error = 'Organization not found';
    } else {
        // Check if email already exists (excluding deleted users)
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND deleted = 0");
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error = 'Email address already exists';
        } else {
            // If password is provided, use it; otherwise create temporary password and send reset link
            $password_provided = !empty($password);
            
            if ($password_provided) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            } else {
                // Generate temporary password (user will set their own via email link)
                $temp_password = bin2hex(random_bytes(32));
                $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
                // Set status to inactive if no password provided
                $status = 'inactive';
            }
            
            if ($organization_id) {
                $stmt = $conn->prepare("INSERT INTO users (email, password, full_name, role_id, organization_id, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssiis", $email, $hashed_password, $full_name, $role_id, $organization_id, $status);
            } else {
                // Super Admin creating user without organization
                $stmt = $conn->prepare("INSERT INTO users (email, password, full_name, role_id, organization_id, status) VALUES (?, ?, ?, ?, NULL, ?)");
                $stmt->bind_param("sssis", $email, $hashed_password, $full_name, $role_id, $status);
            }
            
            if ($stmt->execute()) {
                $user_id = $conn->insert_id;
                
                // If password was not provided, create reset token and send email
                if (!$password_provided && $organization_id) {
                    // Get organization name
                    $org_stmt = $conn->prepare("SELECT name FROM organizations WHERE id = ?");
                    $org_stmt->bind_param("i", $organization_id);
                    $org_stmt->execute();
                    $org_result = $org_stmt->get_result();
                    $org_data = $org_result->fetch_assoc();
                    $org_name = $org_data['name'] ?? 'Task Flow System';
                    
                    // Create password_reset_tokens table if it doesn't exist
                    $conn->query("CREATE TABLE IF NOT EXISTS password_reset_tokens (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        email VARCHAR(255) NOT NULL,
                        token VARCHAR(64) NOT NULL,
                        expires_at DATETIME NOT NULL,
                        used TINYINT(1) DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_token (token),
                        INDEX idx_email (email),
                        INDEX idx_user_id (user_id),
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )");
                    
                    // Generate password reset token
                    $token = bin2hex(random_bytes(32));
                    $token_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    
                    // Insert token
                    $token_stmt = $conn->prepare("INSERT INTO password_reset_tokens (user_id, email, token, expires_at) VALUES (?, ?, ?, ?)");
                    $token_stmt->bind_param("isss", $user_id, $email, $token, $token_expiry);
                    
                    if ($token_stmt->execute()) {
                        // Send user added email with password reset link
                        if (sendUserAddedEmail($email, $org_name, $full_name, $token)) {
                            $message = 'User created successfully. An email with password setup instructions has been sent to the user.';
                        } else {
                            $message = 'User created successfully, but email could not be sent. Please contact the user directly.';
                        }
                    } else {
                        $message = 'User created successfully, but password reset token could not be generated.';
                    }
                } else {
                    $message = 'User created successfully';
                }
            } else {
                $error = 'Error creating user: ' . $conn->error;
            }
        }
    }
}

// Handle user update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_user'])) {
    $user_id = intval($_POST['user_id']);
    $email = trim($_POST['email']);
    $full_name = trim($_POST['full_name']);
    $role_id = intval($_POST['role_id']);
    $status = $_POST['status'];
    $organization_id = isSuperAdmin() ? (isset($_POST['organization_id']) && !empty($_POST['organization_id']) ? intval($_POST['organization_id']) : null) : getOrganizationId();
    
    if (empty($email) || empty($full_name)) {
        $error = 'Email and full name are required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        // Check if email already exists for another user (excluding deleted users)
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? AND deleted = 0");
        $check_stmt->bind_param("si", $email, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error = 'Email address already exists for another user';
        } else {
            if (!empty($_POST['password'])) {
                $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                if ($organization_id) {
                    $stmt = $conn->prepare("UPDATE users SET email=?, password=?, full_name=?, role_id=?, organization_id=?, status=? WHERE id=?");
                    $stmt->bind_param("sssiisi", $email, $hashed_password, $full_name, $role_id, $organization_id, $status, $user_id);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET email=?, password=?, full_name=?, role_id=?, organization_id=NULL, status=? WHERE id=?");
                    $stmt->bind_param("sssisi", $email, $hashed_password, $full_name, $role_id, $status, $user_id);
                }
            } else {
                if ($organization_id) {
                    $stmt = $conn->prepare("UPDATE users SET email=?, full_name=?, role_id=?, organization_id=?, status=? WHERE id=?");
                    $stmt->bind_param("ssiisi", $email, $full_name, $role_id, $organization_id, $status, $user_id);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET email=?, full_name=?, role_id=?, organization_id=NULL, status=? WHERE id=?");
                    $stmt->bind_param("ssisi", $email, $full_name, $role_id, $status, $user_id);
                }
            }
            
            if ($stmt->execute()) {
                $message = 'User updated successfully';
            } else {
                $error = 'Error updating user: ' . $conn->error;
            }
        }
    }
}

// Handle user deletion (soft delete)
if (isset($_GET['delete'])) {
    $user_id = intval($_GET['delete']);
    if ($user_id != $_SESSION['user_id']) {
        // Soft delete: set deleted = 1 instead of actually deleting the record
        $stmt = $conn->prepare("UPDATE users SET deleted = 1, status = 'inactive' WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $message = 'User deleted successfully';
        } else {
            $error = 'Error deleting user';
        }
    } else {
        $error = 'You cannot delete your own account';
    }
}

// Get all users (filtered by organization)
if (isSuperAdmin()) {
    $users = $conn->query("
        SELECT u.*, r.name as role_name, o.name as org_name
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        LEFT JOIN organizations o ON u.organization_id = o.id
        ORDER BY u.created_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
} else {
    $org_id = getOrganizationId();
    $stmt = $conn->prepare("
        SELECT u.*, r.name as role_name
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        WHERE u.organization_id = ?
        ORDER BY u.created_at DESC
    ");
    $stmt->bind_param("i", $org_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get roles (filter out Super Admin for org admins)
if (isSuperAdmin()) {
    $roles = $conn->query("SELECT * FROM roles ORDER BY name")->fetch_all(MYSQLI_ASSOC);
} else {
    $roles = $conn->query("SELECT * FROM roles WHERE name != 'Super Admin' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
}

// Get organizations for super admin
$organizations = [];
if (isSuperAdmin()) {
    $organizations = $conn->query("SELECT * FROM organizations ORDER BY name")->fetch_all(MYSQLI_ASSOC);
}

// Get user for editing (excluding deleted users)
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND deleted = 0");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_user = $result->fetch_assoc();
    $stmt->close();
}

$conn->close();

include 'includes/header.php';
?>

<div style="width: 100%; padding: 20px;">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
        <div>
            <h1 class="text-3xl font-semibold text-gray-900" style="font-size: 28px; font-weight: 600; color: #1e293b; margin: 0;">Users</h1>
            <p class="text-gray-500 mt-1" style="color: #64748b; margin-top: 4px; font-size: 14px;">Manage user accounts and permissions</p>
        </div>
        <button class="btn btn-primary modal-trigger" data-modal="userModal" title="Add New User" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%); color: white; border: none; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 4px rgba(20, 184, 166, 0.3);">
            <i class="fas fa-plus"></i>
            <span>Add User</span>
        </button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success" style="background: #d1fae5; border: 1px solid #10b981; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error" style="background: #fee2e2; border: 1px solid #ef4444; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Users Table -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <?php if (isSuperAdmin()): ?>
                        <th>Organization</th>
                    <?php endif; ?>
                    <th>Status</th>
                    <th>Created</th>
                    <th style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="<?php echo isSuperAdmin() ? '8' : '7'; ?>" style="text-align: center; padding: 40px 20px; color: #94a3b8;">
                            <i class="fas fa-users" style="font-size: 48px; margin-bottom: 12px; opacity: 0.3; display: block;"></i>
                            <p style="font-size: 16px; margin: 0;">No users found</p>
                            <p style="font-size: 14px; margin-top: 8px; color: #cbd5e1;">Create your first user to get started</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td style="color: #64748b;">
                                <i class="fas fa-envelope" style="margin-right: 6px; color: #94a3b8;"></i>
                                <strong style="color: #1e293b; font-size: 15px;"><?php echo htmlspecialchars($user['email']); ?></strong>
                            </td>
                            <td style="color: #475569;">
                                <?php echo htmlspecialchars($user['full_name']); ?>
                            </td>
                            <td>
                                <?php 
                                $role_name = htmlspecialchars($user['role_name']);
                                $role_colors = [
                                    'Super Admin' => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#f59e0b'],
                                    'Org Admin' => ['bg' => '#dbeafe', 'text' => '#1e40af', 'border' => '#3b82f6'],
                                    'Admin' => ['bg' => '#e0e7ff', 'text' => '#3730a3', 'border' => '#6366f1'],
                                    'Project Manager' => ['bg' => '#d1fae5', 'text' => '#065f46', 'border' => '#10b981'],
                                    'Team Member' => ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#9ca3af']
                                ];
                                $role_color = $role_colors[$role_name] ?? ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#9ca3af'];
                                ?>
                                <span class="badge" style="display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 500; background: <?php echo $role_color['bg']; ?>; color: <?php echo $role_color['text']; ?>; border: 1px solid <?php echo $role_color['border']; ?>;">
                                    <?php echo $role_name; ?>
                                </span>
                            </td>
                            <?php if (isSuperAdmin()): ?>
                                <td style="color: #64748b;">
                                    <?php 
                                    if (!empty($user['org_name'])) {
                                        echo htmlspecialchars($user['org_name']);
                                    } else {
                                        echo '<span style="color: #cbd5e1; font-style: italic;">No organization</span>';
                                    }
                                    ?>
                                </td>
                            <?php endif; ?>
                            <td>
                                <?php 
                                $status = $user['status'];
                                $status_colors = [
                                    'active' => ['bg' => '#d1fae5', 'text' => '#065f46', 'border' => '#10b981'],
                                    'inactive' => ['bg' => '#fee2e2', 'text' => '#991b1b', 'border' => '#ef4444']
                                ];
                                $status_color = $status_colors[$status] ?? ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#9ca3af'];
                                ?>
                                <span class="badge" style="display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 500; background: <?php echo $status_color['bg']; ?>; color: <?php echo $status_color['text']; ?>; border: 1px solid <?php echo $status_color['border']; ?>;">
                                    <i class="fas fa-<?php echo $status == 'active' ? 'check-circle' : 'times-circle'; ?>" style="margin-right: 4px; font-size: 10px;"></i>
                                    <?php echo ucfirst($status); ?>
                                </span>
                            </td>
                            <td style="color: #64748b; font-size: 13px;">
                                <i class="fas fa-calendar-alt" style="margin-right: 6px; color: #94a3b8;"></i>
                                <?php echo formatDate($user['created_at']); ?>
                            </td>
                            <td style="text-align: center;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <a href="?edit=<?php echo $user['id']; ?>" class="btn btn-sm btn-warning" title="Edit" style="padding: 6px 10px; background: #f59e0b; color: white; border: none; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <a href="?delete=<?php echo $user['id']; ?>" class="btn btn-sm btn-danger btn-delete" title="Delete" style="padding: 6px 10px; background: #ef4444; color: white; border: none; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div id="userModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title"><?php echo $edit_user ? 'Edit User' : 'Add New User'; ?></h2>
            <span class="close">&times;</span>
        </div>
        <form method="POST" action="">
            <?php if ($edit_user): ?>
                <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                <input type="hidden" name="update_user" value="1">
            <?php else: ?>
                <input type="hidden" name="create_user" value="1">
            <?php endif; ?>
            
            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" required 
                       value="<?php echo htmlspecialchars($edit_user['email'] ?? ''); ?>">
            </div>
            
            <?php if ($edit_user): ?>
                <div class="form-group">
                    <label for="password">Password (leave blank to keep current)</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="password" name="password">
                        <button type="button" class="password-toggle-icon" onclick="togglePassword('password')" title="Show/Hide Password">
                            <i class="fas fa-eye" id="password-toggle-icon"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label for="full_name">Full Name *</label>
                <input type="text" id="full_name" name="full_name" required 
                       value="<?php echo htmlspecialchars($edit_user['full_name'] ?? ''); ?>">
            </div>
            
            <?php if (isSuperAdmin()): ?>
                <div class="form-group">
                    <label for="organization_id">Organization</label>
                    <select id="organization_id" name="organization_id">
                        <option value="">None (Super Admin)</option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?php echo $org['id']; ?>" 
                                    <?php echo ($edit_user && $edit_user['organization_id'] == $org['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($org['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label for="role_id">Role *</label>
                <select id="role_id" name="role_id" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id']; ?>" 
                                <?php echo ($edit_user && $edit_user['role_id'] == $role['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($role['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="status">Status *</label>
                <select id="status" name="status" required>
                    <option value="active" <?php echo ($edit_user && $edit_user['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo ($edit_user && $edit_user['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" class="btn btn-primary" title="<?php echo $edit_user ? 'Update User' : 'Create User'; ?>"><i class="fas fa-<?php echo $edit_user ? 'save' : 'plus'; ?>"></i></button>
                <button type="button" class="btn btn-secondary close" title="Cancel"><i class="fas fa-times"></i></button>
            </div>
        </form>
    </div>
</div>

<?php if ($edit_user): ?>
    <script>
        $(document).ready(function() {
            $('#userModal').show();
        });
    </script>
<?php endif; ?>

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
</script>

<?php include 'includes/footer.php'; ?>
