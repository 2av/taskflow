<?php
require_once 'config/config.php';
requireLogin();
requireActiveSubscription();

// Only Organization Admins and Super Admins can manage statuses
if (!isOrgAdmin() && !isSuperAdmin()) {
    header('Location: dashboard');
    exit();
}

$page_title = 'Manage Statuses';

$conn = getDBConnection();
$message = '';
$error = '';

// Get organization ID
$organization_id = isSuperAdmin() ? null : getOrganizationId();

// Handle status creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_status'])) {
    $name = trim($_POST['name']);
    $color = trim($_POST['color'] ?? '#6c757d');
    $display_order = intval($_POST['display_order'] ?? 0);
    
    if (empty($name)) {
        $error = 'Status name is required';
    } else {
        // Check if status already exists for this organization
        $check = $conn->prepare("SELECT id FROM statuses WHERE organization_id " . ($organization_id ? "= ?" : "IS NULL") . " AND name = ?");
        if ($organization_id) {
            $check->bind_param("is", $organization_id, $name);
        } else {
            $check->bind_param("s", $name);
        }
        $check->execute();
        $result = $check->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'Status with this name already exists';
        } else {
            $stmt = $conn->prepare("INSERT INTO statuses (organization_id, name, display_order, is_default, color) VALUES (?, ?, ?, 0, ?)");
            $stmt->bind_param("isis", $organization_id, $name, $display_order, $color);
            
            if ($stmt->execute()) {
                $message = 'Status created successfully';
            } else {
                $error = 'Error creating status: ' . $conn->error;
            }
            $stmt->close();
        }
        $check->close();
    }
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $status_id = intval($_POST['status_id']);
    $name = trim($_POST['name']);
    $color = trim($_POST['color'] ?? '#6c757d');
    $display_order = intval($_POST['display_order'] ?? 0);
    
    if (empty($name)) {
        $error = 'Status name is required';
    } else {
        // Verify status belongs to this organization
        $verify = $conn->prepare("SELECT id FROM statuses WHERE id = ? AND organization_id " . ($organization_id ? "= ?" : "IS NULL"));
        if ($organization_id) {
            $verify->bind_param("ii", $status_id, $organization_id);
        } else {
            $verify->bind_param("i", $status_id);
        }
        $verify->execute();
        $result = $verify->get_result();
        
        if ($result->num_rows == 0) {
            $error = 'Status not found or access denied';
        } else {
            // Check if name conflicts with another status
            $check = $conn->prepare("SELECT id FROM statuses WHERE id != ? AND organization_id " . ($organization_id ? "= ?" : "IS NULL") . " AND name = ?");
            if ($organization_id) {
                $check->bind_param("iis", $status_id, $organization_id, $name);
            } else {
                $check->bind_param("is", $status_id, $name);
            }
            $check->execute();
            $check_result = $check->get_result();
            
            if ($check_result->num_rows > 0) {
                $error = 'Status with this name already exists';
            } else {
                $stmt = $conn->prepare("UPDATE statuses SET name = ?, color = ?, display_order = ? WHERE id = ?");
                $stmt->bind_param("ssii", $name, $color, $display_order, $status_id);
                
                if ($stmt->execute()) {
                    $message = 'Status updated successfully';
                } else {
                    $error = 'Error updating status: ' . $conn->error;
                }
                $stmt->close();
            }
            $check->close();
        }
        $verify->close();
    }
}

// Handle status deletion
if (isset($_GET['delete'])) {
    $status_id = intval($_GET['delete']);
    
    // Verify status belongs to this organization and is not a default status
    $verify = $conn->prepare("SELECT id, is_default FROM statuses WHERE id = ? AND organization_id " . ($organization_id ? "= ?" : "IS NULL"));
    if ($organization_id) {
        $verify->bind_param("ii", $status_id, $organization_id);
    } else {
        $verify->bind_param("i", $status_id);
    }
    $verify->execute();
    $result = $verify->get_result();
    $status = $result->fetch_assoc();
    
    if (!$status) {
        $error = 'Status not found or access denied';
    } elseif ($status['is_default']) {
        $error = 'Cannot delete default status. You can only rename it.';
    } else {
        // Check if any tasks are using this status
        $task_check = $conn->prepare("SELECT COUNT(*) as count FROM tasks WHERE status = (SELECT name FROM statuses WHERE id = ?)");
        $task_check->bind_param("i", $status_id);
        $task_check->execute();
        $task_result = $task_check->get_result();
        $task_count = $task_result->fetch_assoc()['count'];
        
        if ($task_count > 0) {
            $error = "Cannot delete status. $task_count task(s) are using this status. Please change their status first.";
        } else {
            $stmt = $conn->prepare("DELETE FROM statuses WHERE id = ?");
            $stmt->bind_param("i", $status_id);
            
            if ($stmt->execute()) {
                $message = 'Status deleted successfully';
            } else {
                $error = 'Error deleting status: ' . $conn->error;
            }
            $stmt->close();
        }
        $task_check->close();
    }
    $verify->close();
}

// Get all statuses for this organization
$statuses_query = "SELECT * FROM statuses WHERE organization_id " . ($organization_id ? "= ?" : "IS NULL") . " ORDER BY display_order ASC, name ASC";
$stmt = $conn->prepare($statuses_query);
if ($organization_id) {
    $stmt->bind_param("i", $organization_id);
}
$stmt->execute();
$statuses_result = $stmt->get_result();
$statuses = $statuses_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();

include 'includes/header.php';
?>

<style>
.status-management-container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 24px;
}

.status-form-section {
    background: var(--card-bg);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    padding: 24px;
    margin-bottom: 24px;
}

.status-form-section h2 {
    font-size: 20px;
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
.form-group select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 14px;
    background: var(--page-bg);
    color: var(--text-primary);
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: var(--blue);
    box-shadow: 0 0 0 3px var(--blue-light);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 150px 100px;
    gap: 12px;
    align-items: end;
}

.statuses-list {
    background: var(--card-bg);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    padding: 24px;
}

.status-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    margin-bottom: 12px;
    background: var(--page-bg);
}

.status-color {
    width: 40px;
    height: 40px;
    border-radius: 6px;
    border: 2px solid var(--border-color);
    flex-shrink: 0;
}

.status-info {
    flex: 1;
}

.status-name {
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.status-meta {
    font-size: 12px;
    color: var(--text-muted);
}

.status-actions {
    display: flex;
    gap: 8px;
}

.btn-icon {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--card-bg);
    color: var(--text-primary);
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}

.btn-icon:hover {
    background: var(--blue-light);
    border-color: var(--blue);
    color: var(--blue);
}

.btn-icon.danger:hover {
    background: #FEE2E2;
    border-color: #DC2626;
    color: #DC2626;
}

.btn-primary {
    padding: 10px 20px;
    background: var(--blue);
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-primary:hover {
    background: var(--blue-dark);
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .status-item {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .status-actions {
        width: 100%;
        justify-content: flex-end;
    }
}
</style>

<div class="status-management-container">
    <h1 style="font-size: 24px; font-weight: 600; color: var(--text-primary); margin-bottom: 24px;">Manage Statuses</h1>
    
    <?php if ($message): ?>
        <div style="margin-bottom: 20px; padding: 12px 16px; background: #DCFCE7; color: #166534; border-radius: 6px;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div style="margin-bottom: 20px; padding: 12px 16px; background: #FEE2E2; color: #991B1B; border-radius: 6px;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- Add New Status Form -->
    <div class="status-form-section">
        <h2>Add New Status</h2>
        <form method="POST" action="">
            <input type="hidden" name="create_status" value="1">
            <div class="form-row">
                <div class="form-group">
                    <label for="name">Status Name *</label>
                    <input type="text" id="name" name="name" required placeholder="e.g., On Hold, Review">
                </div>
                <div class="form-group">
                    <label for="color">Color</label>
                    <input type="color" id="color" name="color" value="#6c757d">
                </div>
                <div class="form-group">
                    <label for="display_order">Order</label>
                    <input type="number" id="display_order" name="display_order" value="0" min="0">
                </div>
            </div>
            <button type="submit" class="btn-primary">
                <i class="fas fa-plus"></i> Add Status
            </button>
        </form>
    </div>
    
    <!-- Existing Statuses List -->
    <div class="statuses-list">
        <h2 style="font-size: 20px; font-weight: 600; color: var(--text-primary); margin: 0 0 20px 0; padding-bottom: 12px; border-bottom: 1px solid var(--border-color);">Existing Statuses</h2>
        
        <?php if (empty($statuses)): ?>
            <p style="text-align: center; color: var(--text-muted); padding: 40px;">No statuses found. Add your first status above.</p>
        <?php else: ?>
            <?php foreach ($statuses as $status): ?>
                <div class="status-item">
                    <div class="status-color" style="background: <?php echo htmlspecialchars($status['color']); ?>;"></div>
                    <div class="status-info">
                        <div class="status-name">
                            <?php echo htmlspecialchars($status['name']); ?>
                            <?php if ($status['is_default']): ?>
                                <span style="font-size: 11px; color: var(--text-muted); margin-left: 8px;">(Default)</span>
                            <?php endif; ?>
                        </div>
                        <div class="status-meta">
                            Order: <?php echo $status['display_order']; ?> | 
                            Color: <?php echo htmlspecialchars($status['color']); ?>
                        </div>
                    </div>
                    <div class="status-actions">
                        <button type="button" class="btn-icon" onclick="editStatus(<?php echo $status['id']; ?>, '<?php echo htmlspecialchars($status['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($status['color']); ?>', <?php echo $status['display_order']; ?>)">
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php if (!$status['is_default']): ?>
                            <a href="?delete=<?php echo $status['id']; ?>" class="btn-icon danger" onclick="return confirm('Are you sure you want to delete this status?')">
                                <i class="fas fa-trash"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Status Modal -->
<div id="editStatusModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: var(--card-bg); border-radius: 10px; padding: 24px; max-width: 500px; width: 90%;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="font-size: 20px; font-weight: 600; color: var(--text-primary); margin: 0;">Edit Status</h2>
            <button onclick="closeEditModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        <form method="POST" action="" id="editStatusForm">
            <input type="hidden" name="update_status" value="1">
            <input type="hidden" name="status_id" id="edit_status_id">
            <div class="form-group">
                <label for="edit_name">Status Name *</label>
                <input type="text" id="edit_name" name="name" required>
            </div>
            <div class="form-row" style="grid-template-columns: 1fr 150px;">
                <div class="form-group">
                    <label for="edit_color">Color</label>
                    <input type="color" id="edit_color" name="color">
                </div>
                <div class="form-group">
                    <label for="edit_display_order">Order</label>
                    <input type="number" id="edit_display_order" name="display_order" min="0">
                </div>
            </div>
            <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" onclick="closeEditModal()" style="padding: 10px 20px; border: 1px solid var(--border-color); background: white; border-radius: 6px; cursor: pointer;">Cancel</button>
                <button type="submit" class="btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function editStatus(id, name, color, order) {
    document.getElementById('edit_status_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_color').value = color;
    document.getElementById('edit_display_order').value = order;
    document.getElementById('editStatusModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editStatusModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('editStatusModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});
</script>

<?php include 'includes/footer.php'; ?>
