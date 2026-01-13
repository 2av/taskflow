<?php
require_once 'config/config.php';
requireAdmin();
requireActiveSubscription();

$page_title = 'User Management';

$conn = getDBConnection();
$message = '';
$error = '';

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_user'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $full_name = trim($_POST['full_name']);
    $role_id = intval($_POST['role_id']);
    $status = $_POST['status'];
    $organization_id = isSuperAdmin() ? (isset($_POST['organization_id']) && !empty($_POST['organization_id']) ? intval($_POST['organization_id']) : null) : getOrganizationId();
    
    if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
        $error = 'All fields are required';
    } elseif (!$organization_id && !isSuperAdmin()) {
        $error = 'Organization not found';
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        if ($organization_id) {
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, role_id, organization_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssiis", $username, $email, $hashed_password, $full_name, $role_id, $organization_id, $status);
        } else {
            // Super Admin creating user without organization
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, role_id, organization_id, status) VALUES (?, ?, ?, ?, ?, NULL, ?)");
            $stmt->bind_param("ssssis", $username, $email, $hashed_password, $full_name, $role_id, $status);
        }
        
        if ($stmt->execute()) {
            $message = 'User created successfully';
        } else {
            $error = 'Error creating user: ' . $conn->error;
        }
    }
}

// Handle user update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_user'])) {
    $user_id = intval($_POST['user_id']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $full_name = trim($_POST['full_name']);
    $role_id = intval($_POST['role_id']);
    $status = $_POST['status'];
    $organization_id = isSuperAdmin() ? (isset($_POST['organization_id']) && !empty($_POST['organization_id']) ? intval($_POST['organization_id']) : null) : getOrganizationId();
    
    if (!empty($_POST['password'])) {
        $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        if ($organization_id) {
            $stmt = $conn->prepare("UPDATE users SET username=?, email=?, password=?, full_name=?, role_id=?, organization_id=?, status=? WHERE id=?");
            $stmt->bind_param("ssssiisi", $username, $email, $hashed_password, $full_name, $role_id, $organization_id, $status, $user_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET username=?, email=?, password=?, full_name=?, role_id=?, organization_id=NULL, status=? WHERE id=?");
            $stmt->bind_param("ssssisi", $username, $email, $hashed_password, $full_name, $role_id, $status, $user_id);
        }
    } else {
        if ($organization_id) {
            $stmt = $conn->prepare("UPDATE users SET username=?, email=?, full_name=?, role_id=?, organization_id=?, status=? WHERE id=?");
            $stmt->bind_param("sssiisi", $username, $email, $full_name, $role_id, $organization_id, $status, $user_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET username=?, email=?, full_name=?, role_id=?, organization_id=NULL, status=? WHERE id=?");
            $stmt->bind_param("sssisi", $username, $email, $full_name, $role_id, $status, $user_id);
        }
    }
    
    if ($stmt->execute()) {
        $message = 'User updated successfully';
    } else {
        $error = 'Error updating user: ' . $conn->error;
    }
}

// Handle user deletion
if (isset($_GET['delete'])) {
    $user_id = intval($_GET['delete']);
    if ($user_id != $_SESSION['user_id']) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
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

// Get user for editing
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $result = $conn->query("SELECT * FROM users WHERE id = $edit_id");
    $edit_user = $result->fetch_assoc();
}

$conn->close();

include 'includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">User Management</h1>
    <button class="btn btn-primary modal-trigger" data-modal="userModal" title="Add New User"><i class="fas fa-plus"></i></button>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Email</th>
                <th>Full Name</th>
                <th>Role</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo $user['id']; ?></td>
                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($user['role_name']); ?></td>
                    <?php if (isSuperAdmin()): ?>
                        <td><?php echo htmlspecialchars($user['org_name'] ?? '-'); ?></td>
                    <?php endif; ?>
                    <td>
                        <span class="badge <?php echo $user['status'] == 'active' ? 'badge-success' : 'badge-secondary'; ?>">
                            <?php echo ucfirst($user['status']); ?>
                        </span>
                    </td>
                    <td><?php echo formatDate($user['created_at']); ?></td>
                    <td>
                        <a href="?edit=<?php echo $user['id']; ?>" class="btn btn-sm btn-primary" title="Edit"><i class="fas fa-edit"></i></a>
                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                            <a href="?delete=<?php echo $user['id']; ?>" class="btn btn-sm btn-danger btn-delete" title="Delete"><i class="fas fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
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
                <label for="username">Username *</label>
                <input type="text" id="username" name="username" required 
                       value="<?php echo htmlspecialchars($edit_user['username'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" required 
                       value="<?php echo htmlspecialchars($edit_user['email'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="password">Password <?php echo $edit_user ? '(leave blank to keep current)' : '*'; ?></label>
                <input type="password" id="password" name="password" <?php echo $edit_user ? '' : 'required'; ?>>
            </div>
            
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

<?php include 'includes/footer.php'; ?>
