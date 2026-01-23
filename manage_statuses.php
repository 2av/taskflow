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

// Check and add new columns if they don't exist
$check_columns = $conn->query("SHOW COLUMNS FROM statuses LIKE 'is_default_filter'");
if ($check_columns->num_rows == 0) {
    $conn->query("ALTER TABLE statuses ADD COLUMN is_default_filter TINYINT(1) DEFAULT 0 AFTER is_default");
}
$check_columns = $conn->query("SHOW COLUMNS FROM statuses LIKE 'is_default_task'");
if ($check_columns->num_rows == 0) {
    $conn->query("ALTER TABLE statuses ADD COLUMN is_default_task TINYINT(1) DEFAULT 0 AFTER is_default_filter");
}

// Handle swap order
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['swap_order'])) {
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    $status_id_1 = intval($_POST['status_id_1']);
    $status_id_2 = intval($_POST['status_id_2']);
    
    // Get current orders
    $get_orders = $conn->prepare("SELECT id, display_order FROM statuses WHERE id IN (?, ?) AND organization_id " . ($organization_id ? "= ?" : "IS NULL"));
    if ($organization_id) {
        $get_orders->bind_param("iii", $status_id_1, $status_id_2, $organization_id);
    } else {
        $get_orders->bind_param("ii", $status_id_1, $status_id_2);
    }
    $get_orders->execute();
    $orders_result = $get_orders->get_result();
    $orders = [];
    while ($row = $orders_result->fetch_assoc()) {
        $orders[$row['id']] = $row['display_order'];
    }
    $get_orders->close();
    
    if (count($orders) == 2) {
        // Swap orders
        $order_1 = $orders[$status_id_1];
        $order_2 = $orders[$status_id_2];
        
        $update_1 = $conn->prepare("UPDATE statuses SET display_order = ? WHERE id = ?");
        $update_1->bind_param("ii", $order_2, $status_id_1);
        $update_1->execute();
        $update_1->close();
        
        $update_2 = $conn->prepare("UPDATE statuses SET display_order = ? WHERE id = ?");
        $update_2->bind_param("ii", $order_1, $status_id_2);
        $update_2->execute();
        $update_2->close();
        
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Status order updated successfully']);
            exit();
        }
        $message = 'Status order updated successfully';
    } else {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid status IDs for swapping']);
            exit();
        }
        $error = 'Invalid status IDs for swapping';
    }
}

// Handle default filter status update (multiple selection)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_default_filter'])) {
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    // Get selected status IDs (can be array or single value)
    $selected_ids = [];
    if (isset($_POST['status_ids']) && is_array($_POST['status_ids'])) {
        $selected_ids = array_map('intval', $_POST['status_ids']);
    } elseif (isset($_POST['status_id'])) {
        $selected_ids = [intval($_POST['status_id'])];
    }
    
    // First, unset all default filters for this organization
    $unset = $conn->prepare("UPDATE statuses SET is_default_filter = 0 WHERE organization_id " . ($organization_id ? "= ?" : "IS NULL"));
    if ($organization_id) {
        $unset->bind_param("i", $organization_id);
    }
    $unset->execute();
    $unset->close();
    
    // Then set the selected ones
    if (!empty($selected_ids)) {
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        $set_query = "UPDATE statuses SET is_default_filter = 1 WHERE id IN ($placeholders) AND organization_id " . ($organization_id ? "= ?" : "IS NULL");
        $set = $conn->prepare($set_query);
        
        $params = $selected_ids;
        if ($organization_id) {
            $params[] = $organization_id;
        }
        $types = str_repeat('i', count($selected_ids)) . ($organization_id ? 'i' : '');
        $set->bind_param($types, ...$params);
        
        if ($set->execute()) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Default filter statuses updated successfully', 'selected_ids' => $selected_ids]);
                exit();
            }
            $message = 'Default filter statuses updated successfully';
        } else {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Error updating default filter statuses']);
                exit();
            }
            $error = 'Error updating default filter statuses';
        }
        $set->close();
    } else {
        // No selection - just unset all (which we already did)
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Default filter statuses cleared']);
            exit();
        }
        $message = 'Default filter statuses cleared';
    }
}

// Handle default task status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_default_task'])) {
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    $status_id = intval($_POST['status_id']);
    
    // First, unset all default task statuses for this organization
    $unset = $conn->prepare("UPDATE statuses SET is_default_task = 0 WHERE organization_id " . ($organization_id ? "= ?" : "IS NULL"));
    if ($organization_id) {
        $unset->bind_param("i", $organization_id);
    }
    $unset->execute();
    $unset->close();
    
    // Then set the selected one
    $set = $conn->prepare("UPDATE statuses SET is_default_task = 1 WHERE id = ? AND organization_id " . ($organization_id ? "= ?" : "IS NULL"));
    if ($organization_id) {
        $set->bind_param("ii", $status_id, $organization_id);
    } else {
        $set->bind_param("i", $status_id);
    }
    if ($set->execute()) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Default task status updated successfully']);
            exit();
        }
        $message = 'Default task status updated successfully';
    } else {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Error updating default task status']);
            exit();
        }
        $error = 'Error updating default task status';
    }
    $set->close();
}

// Handle status creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_status'])) {
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    $name = trim($_POST['name']);
    $color = trim($_POST['color'] ?? '#6c757d');
    
    if (empty($name)) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Status name is required']);
            exit();
        }
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
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Status with this name already exists']);
                exit();
            }
            $error = 'Status with this name already exists';
        } else {
            // Get the last display_order for this organization and add 1
            $max_order_query = "SELECT COALESCE(MAX(display_order), 0) as max_order FROM statuses WHERE organization_id " . ($organization_id ? "= ?" : "IS NULL");
            $max_order_stmt = $conn->prepare($max_order_query);
            if ($organization_id) {
                $max_order_stmt->bind_param("i", $organization_id);
            }
            $max_order_stmt->execute();
            $max_order_result = $max_order_stmt->get_result();
            $max_order_row = $max_order_result->fetch_assoc();
            $display_order = ($max_order_row['max_order'] ?? 0) + 1;
            $max_order_stmt->close();
            
            $stmt = $conn->prepare("INSERT INTO statuses (organization_id, name, display_order, is_default, is_default_filter, is_default_task, color) VALUES (?, ?, ?, 0, 0, 0, ?)");
            $stmt->bind_param("isis", $organization_id, $name, $display_order, $color);
            
            if ($stmt->execute()) {
                $new_status_id = $conn->insert_id;
                // Get the newly created status
                $get_status = $conn->prepare("SELECT * FROM statuses WHERE id = ?");
                $get_status->bind_param("i", $new_status_id);
                $get_status->execute();
                $status_result = $get_status->get_result();
                $new_status = $status_result->fetch_assoc();
                $get_status->close();
                
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Status created successfully', 'status' => $new_status]);
                    exit();
                }
                $message = 'Status created successfully';
            } else {
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => 'Error creating status: ' . $conn->error]);
                    exit();
                }
                $error = 'Error creating status: ' . $conn->error;
            }
            $stmt->close();
        }
        $check->close();
    }
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    $status_id = intval($_POST['status_id']);
    $name = trim($_POST['name']);
    $color = trim($_POST['color'] ?? '#6c757d');
    
    if (empty($name)) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Status name is required']);
            exit();
        }
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
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Status not found or access denied']);
                exit();
            }
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
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => 'Status with this name already exists']);
                    exit();
                }
                $error = 'Status with this name already exists';
            } else {
                // Don't update display_order, only name and color
                $stmt = $conn->prepare("UPDATE statuses SET name = ?, color = ? WHERE id = ?");
                $stmt->bind_param("ssi", $name, $color, $status_id);
                
                if ($stmt->execute()) {
                    // Get updated status
                    $get_status = $conn->prepare("SELECT * FROM statuses WHERE id = ?");
                    $get_status->bind_param("i", $status_id);
                    $get_status->execute();
                    $status_result = $get_status->get_result();
                    $updated_status = $status_result->fetch_assoc();
                    $get_status->close();
                    
                    if ($is_ajax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'message' => 'Status updated successfully', 'status' => $updated_status]);
                        exit();
                    }
                    $message = 'Status updated successfully';
                } else {
                    if ($is_ajax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'error' => 'Error updating status: ' . $conn->error]);
                        exit();
                    }
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
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_status'])) {
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    $status_id = intval($_POST['status_id']);
    
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
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Status not found or access denied']);
            exit();
        }
        $error = 'Status not found or access denied';
    } elseif ($status['is_default']) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Cannot delete default status. You can only rename it.']);
            exit();
        }
        $error = 'Cannot delete default status. You can only rename it.';
    } else {
        // Check if any tasks are using this status
        $task_check = $conn->prepare("SELECT COUNT(*) as count FROM tasks WHERE status_id = ?");
        $task_check->bind_param("i", $status_id);
        $task_check->execute();
        $task_result = $task_check->get_result();
        $task_count = $task_result->fetch_assoc()['count'];
        
        if ($task_count > 0) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => "Cannot delete status. $task_count task(s) are using this status. Please change their status first."]);
                exit();
            }
            $error = "Cannot delete status. $task_count task(s) are using this status. Please change their status first.";
        } else {
            $stmt = $conn->prepare("DELETE FROM statuses WHERE id = ?");
            $stmt->bind_param("i", $status_id);
            
            if ($stmt->execute()) {
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Status deleted successfully', 'status_id' => $status_id]);
                    exit();
                }
                $message = 'Status deleted successfully';
            } else {
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => 'Error deleting status: ' . $conn->error]);
                    exit();
                }
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

// Handle AJAX request for statuses list only
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: text/html');
    // Output only the statuses list HTML
    if (empty($statuses)) {
        echo '<p style="text-align: center; color: var(--text-muted); padding: 40px;">No statuses found. Add your first status above.</p>';
    } else {
        echo '<div id="statuses_container">';
        foreach ($statuses as $index => $status) {
            echo '<div class="status-item" data-status-id="' . $status['id'] . '" data-order="' . $status['display_order'] . '">';
            echo '<div class="status-color" style="background: ' . htmlspecialchars($status['color']) . ';"></div>';
            echo '<div class="status-info">';
            echo '<div class="status-name">';
            echo htmlspecialchars($status['name']);
            echo '<div class="status-badges">';
            if ($status['is_default'] ?? 0) echo '<span class="status-badge default">Default</span>';
            if ($status['is_default_filter'] ?? 0) echo '<span class="status-badge filter">Default Filter</span>';
            if ($status['is_default_task'] ?? 0) echo '<span class="status-badge task">Default Task</span>';
            echo '</div></div>';
            echo '<div class="status-meta">Order: ' . $status['display_order'] . '</div>';
            echo '</div>';
            echo '<div class="status-actions">';
            if ($index > 0) {
                echo '<button type="button" class="btn-icon" onclick="swapStatus(' . $statuses[$index-1]['id'] . ', ' . $status['id'] . ')" title="Move Up"><i class="fas fa-arrow-up"></i></button>';
            }
            if ($index < count($statuses) - 1) {
                echo '<button type="button" class="btn-icon" onclick="swapStatus(' . $status['id'] . ', ' . $statuses[$index+1]['id'] . ')" title="Move Down"><i class="fas fa-arrow-down"></i></button>';
            }
            echo '<button type="button" class="btn-icon" onclick="editStatus(' . $status['id'] . ', \'' . htmlspecialchars($status['name'], ENT_QUOTES) . '\', \'' . htmlspecialchars($status['color']) . '\')" title="Edit"><i class="fas fa-edit"></i></button>';
            if (!($status['is_default'] ?? 0)) {
                echo '<button type="button" class="btn-icon danger" onclick="deleteStatus(' . $status['id'] . ')" title="Delete"><i class="fas fa-trash"></i></button>';
            }
            echo '</div></div>';
        }
        echo '</div>';
    }
    exit();
}

// Predefined color palette
$color_palette = [
    ['#ffc107', 'Amber'],
    ['#17a2b8', 'Cyan'],
    ['#6c757d', 'Gray'],
    ['#28a745', 'Green'],
    ['#dc3545', 'Red'],
    ['#007bff', 'Blue'],
    ['#6610f2', 'Purple'],
    ['#e83e8c', 'Pink'],
    ['#fd7e14', 'Orange'],
    ['#20c997', 'Teal'],
    ['#ff6b6b', 'Coral'],
    ['#4ecdc4', 'Turquoise'],
    ['#45b7d1', 'Sky Blue'],
    ['#f9ca24', 'Yellow'],
    ['#6c5ce7', 'Lavender'],
    ['#a29bfe', 'Light Purple']
];

// Helper function to generate custom multiselect HTML
function renderCustomMultiselect($name, $options, $selected_values = [], $searchable = false) {
    static $counter = 0;
    $counter++;
    $unique_id = $name . '_' . $counter;
    
    // Get selected labels for display
    $selected_labels = [];
    foreach ($selected_values as $val) {
        if (isset($options[$val])) {
            $selected_labels[] = $options[$val];
        }
    }
    $display_text = empty($selected_labels) ? 'Select...' : (count($selected_labels) == 1 ? $selected_labels[0] : count($selected_labels) . ' selected');
    
    $html = '<div class="custom-multiselect" id="' . $unique_id . '">';
    $html .= '<div class="custom-multiselect-display">';
    if (empty($selected_labels)) {
        $html .= '<span class="placeholder">Select...</span>';
        $html .= '<span class="selected-count" style="display: none;"></span>';
    } else {
        $html .= '<span class="placeholder" style="display: none;">Select...</span>';
        $html .= '<span class="selected-count">' . htmlspecialchars($display_text) . '</span>';
    }
    $html .= '<span class="arrow">▼</span>';
    $html .= '</div>';
    $html .= '<div class="custom-multiselect-dropdown">';
    
    if ($searchable && count($options) > 5) {
        $html .= '<div class="custom-multiselect-search">';
        $html .= '<input type="text" placeholder="Search...">';
        $html .= '</div>';
    }
    
    foreach ($options as $value => $label) {
        $checked = in_array($value, $selected_values) ? 'checked' : '';
        $option_id = $unique_id . '_' . md5($value);
        $html .= '<div class="custom-multiselect-option">';
        $html .= '<input type="checkbox" name="' . htmlspecialchars($name) . '[]" value="' . htmlspecialchars($value) . '" id="' . $option_id . '" ' . $checked . '>';
        $html .= '<label for="' . $option_id . '">' . htmlspecialchars($label) . '</label>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

$conn->close();

include 'includes/header.php';
?>

<style>
.status-management-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 24px;
}

.page-header {
    margin-bottom: 32px;
}

.page-title {
    font-size: 28px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}

.page-subtitle {
    font-size: 14px;
    color: var(--text-secondary);
    margin: 0;
}

.config-section {
    background: var(--card-bg);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    overflow: visible;
    position: relative;
}

.config-section h2 {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 20px 0;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 8px;
}

.config-section h2 i {
    color: var(--blue);
}

.config-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.config-item {
    padding: 16px;
    background: var(--page-bg);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    position: relative;
    overflow: visible;
}

.config-item label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
    margin-bottom: 8px;
}

.config-item select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 14px;
    background: var(--card-bg);
    color: var(--text-primary);
    cursor: pointer;
}

.config-item select:focus {
    outline: none;
    border-color: var(--blue);
    box-shadow: 0 0 0 3px var(--blue-light);
}

.status-form-section {
    background: var(--card-bg);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    overflow: visible;
}

.status-form-section h2 {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 20px 0;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 8px;
}

.status-form-section h2 i {
    color: var(--blue);
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
    grid-template-columns: 1fr auto 100px;
    gap: 20px;
    align-items: start;
}

.form-group {
    min-width: 0;
    overflow: visible;
}

.color-palette {
    display: grid;
    grid-template-columns: repeat(8, 1fr);
    gap: 6px;
    margin-top: 8px;
    width: 100%;
    max-width: 256px;
    box-sizing: border-box;
    padding: 0;
}

.color-option {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: 2px solid var(--border-color);
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
    box-sizing: border-box;
    flex-shrink: 0;
}

.color-option:hover {
    transform: scale(1.1);
    border-color: var(--blue);
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

.color-option.selected {
    border-color: var(--blue);
    border-width: 3px;
    box-shadow: 0 0 0 2px var(--blue-light);
}

.color-option.selected::after {
    content: '✓';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: white;
    font-weight: bold;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
}

.statuses-list {
    background: var(--card-bg);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.statuses-list h2 {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 20px 0;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 8px;
}

.statuses-list h2 i {
    color: var(--blue);
}

.status-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    margin-bottom: 12px;
    background: var(--page-bg);
    transition: all 0.2s;
}

.status-item:hover {
    border-color: var(--blue);
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.status-item.dragging {
    opacity: 0.5;
}

.status-color {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    border: 2px solid var(--border-color);
    flex-shrink: 0;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.status-info {
    flex: 1;
    min-width: 0;
}

.status-name {
    font-weight: 600;
    font-size: 15px;
    color: var(--text-primary);
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.status-badges {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.status-badge {
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 12px;
    font-weight: 500;
    background: var(--blue-light);
    color: var(--blue);
}

.status-badge.default {
    background: #FEF3C7;
    color: #92400E;
}

.status-badge.filter {
    background: #DBEAFE;
    color: #1E40AF;
}

.status-badge.task {
    background: #D1FAE5;
    color: #065F46;
}

.status-meta {
    font-size: 12px;
    color: var(--text-secondary);
    margin-top: 4px;
}

.status-actions {
    display: flex;
    gap: 8px;
    align-items: center;
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
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-primary:hover {
    background: var(--blue-dark);
}

.alert {
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.alert-success {
    background: #DCFCE7;
    color: #166534;
    border: 1px solid #86EFAC;
}

.alert-error {
    background: #FEE2E2;
    color: #991B1B;
    border: 1px solid #FCA5A5;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .config-grid {
        grid-template-columns: 1fr;
    }
    
    .color-palette {
        grid-template-columns: repeat(4, 1fr);
        max-width: 100%;
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

@media (max-width: 1024px) {
    .form-row {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .color-palette {
        max-width: 100%;
    }
}

/* Custom Multi-Select Dropdown Styles */
.custom-multiselect {
    position: relative;
    width: 100%;
    min-width: 140px;
}

.custom-multiselect-display {
    padding: 10px 36px 10px 12px;
    border: 1px solid var(--border-color);
    background: var(--page-bg);
    cursor: pointer;
    font-size: 14px;
    color: var(--text-primary);
    transition: all 0.2s ease;
    display: flex;
    justify-content: space-between;
    align-items: center;
    min-height: 38px;
    width: 100%;
    box-sizing: border-box;
    border-radius: 6px;
}

.custom-multiselect-display:hover {
    border-color: var(--blue);
}

.custom-multiselect-display.active {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px var(--blue-light);
}

.custom-multiselect-display .placeholder {
    color: var(--text-secondary);
}

.custom-multiselect-display .selected-count {
    color: var(--blue);
    font-weight: 500;
    font-size: 13px;
}

.custom-multiselect-display .arrow {
    color: var(--text-secondary);
    font-size: 12px;
    transition: transform 0.3s;
    position: absolute;
    right: 12px;
}

.custom-multiselect-display.active .arrow {
    transform: rotate(180deg);
}

.custom-multiselect-dropdown {
    position: absolute;
    top: calc(100% + 5px);
    left: 0;
    right: 0;
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 10000;
    max-height: 250px;
    overflow-y: auto;
    overflow-x: hidden;
    display: none;
    margin-top: 0;
    width: 100%;
    box-sizing: border-box;
    min-width: 200px;
    border-radius: 6px;
}

.custom-multiselect-dropdown::-webkit-scrollbar {
    width: 6px;
}

.custom-multiselect-dropdown::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

.custom-multiselect-dropdown::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 4px;
}

.custom-multiselect-dropdown.show {
    display: block !important;
}

.custom-multiselect-option {
    padding: 10px 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    transition: background 0.2s;
    border-bottom: 1px solid #f0f0f0;
}

.custom-multiselect-option:last-child {
    border-bottom: none;
}

.custom-multiselect-option:hover {
    background: var(--page-bg);
}

.custom-multiselect-option input[type="checkbox"] {
    margin-right: 10px;
    cursor: pointer;
    width: 18px;
    height: 18px;
    accent-color: var(--blue);
}

.custom-multiselect-option label {
    cursor: pointer;
    margin: 0;
    flex: 1;
    font-size: 14px;
    color: var(--text-primary);
}
</style>

<div class="status-management-container">
    <div class="page-header">
        <h1 class="page-title">Manage Statuses</h1>
        <p class="page-subtitle">Configure status colors, order, and default settings for your organization</p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- Statuses Table -->
    <div class="statuses-list">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="font-size: 18px; font-weight: 600; color: var(--text-primary); margin: 0; padding-bottom: 12px; border-bottom: 2px solid var(--border-color); display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-list"></i> Statuses (<?php echo count($statuses); ?>)
            </h2>
            <button type="button" class="btn-primary" onclick="openAddStatusModal()">
                <i class="fas fa-plus"></i> Add Status
            </button>
        </div>
        
        <?php if (empty($statuses)): ?>
            <p style="text-align: center; color: var(--text-muted); padding: 40px;">No statuses found. Add your first status above.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="statuses-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: var(--page-bg); border-bottom: 2px solid var(--border-color);">
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: var(--text-primary); font-size: 13px; width: 60px;">Color</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600; color: var(--text-primary); font-size: 13px;">Name</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: var(--text-primary); font-size: 13px; width: 80px;">Order</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: var(--text-primary); font-size: 13px; width: 150px;">Default Filter</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: var(--text-primary); font-size: 13px; width: 150px;">Default Task</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; color: var(--text-primary); font-size: 13px; width: 200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="statuses_container">
                        <?php foreach ($statuses as $index => $status): ?>
                            <tr class="status-row" data-status-id="<?php echo $status['id']; ?>" data-order="<?php echo $status['display_order']; ?>" style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 12px;">
                                    <div style="width: 40px; height: 40px; border-radius: 6px; border: 2px solid var(--border-color); background: <?php echo htmlspecialchars($status['color']); ?>; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"></div>
                                </td>
                                <td style="padding: 12px;">
                                    <div style="font-weight: 600; font-size: 14px; color: var(--text-primary);">
                                        <?php echo htmlspecialchars($status['name']); ?>
                                        <?php if ($status['is_default'] ?? 0): ?>
                                            <span class="status-badge default" style="margin-left: 8px;">Default</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="padding: 12px; text-align: center;">
                                    <div style="display: flex; align-items: center; justify-content: center; gap: 4px;">
                                        <?php if ($index > 0): ?>
                                            <button type="button" class="btn-icon" onclick="swapStatus(<?php echo $statuses[$index-1]['id']; ?>, <?php echo $status['id']; ?>)" title="Move Up" style="width: 28px; height: 28px; padding: 0;">
                                                <i class="fas fa-arrow-up" style="font-size: 12px;"></i>
                                            </button>
                                        <?php else: ?>
                                            <span style="width: 28px;"></span>
                                        <?php endif; ?>
                                        <span style="font-size: 13px; color: var(--text-secondary); min-width: 30px; display: inline-block;"><?php echo $status['display_order']; ?></span>
                                        <?php if ($index < count($statuses) - 1): ?>
                                            <button type="button" class="btn-icon" onclick="swapStatus(<?php echo $status['id']; ?>, <?php echo $statuses[$index+1]['id']; ?>)" title="Move Down" style="width: 28px; height: 28px; padding: 0;">
                                                <i class="fas fa-arrow-down" style="font-size: 12px;"></i>
                                            </button>
                                        <?php else: ?>
                                            <span style="width: 28px;"></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="padding: 12px; text-align: center;">
                                    <input type="checkbox" 
                                           class="default-filter-checkbox" 
                                           data-status-id="<?php echo $status['id']; ?>"
                                           <?php echo ($status['is_default_filter'] ?? 0) ? 'checked' : ''; ?>
                                           style="width: 18px; height: 18px; cursor: pointer;">
                                </td>
                                <td style="padding: 12px; text-align: center;">
                                    <input type="radio" 
                                           name="default_task_status" 
                                           class="default-task-radio" 
                                           data-status-id="<?php echo $status['id']; ?>"
                                           value="<?php echo $status['id']; ?>"
                                           <?php echo ($status['is_default_task'] ?? 0) ? 'checked' : ''; ?>
                                           style="width: 18px; height: 18px; cursor: pointer;">
                                </td>
                                <td style="padding: 12px; text-align: center;">
                                    <div style="display: flex; gap: 6px; justify-content: center; align-items: center;">
                                        <button type="button" class="btn-icon" onclick="editStatus(<?php echo $status['id']; ?>, '<?php echo htmlspecialchars($status['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($status['color']); ?>')" title="Edit" style="width: 32px; height: 32px; padding: 0;">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if (!($status['is_default'] ?? 0)): ?>
                                            <button type="button" class="btn-icon danger" onclick="deleteStatus(<?php echo $status['id']; ?>)" title="Delete" style="width: 32px; height: 32px;">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 20px; display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" class="btn-primary" onclick="saveDefaultFilter()">
                    <i class="fas fa-save"></i> Save Default Filter
                </button>
                <button type="button" class="btn-primary" onclick="saveDefaultTask()">
                    <i class="fas fa-save"></i> Save Default Task
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Status Modal -->
<div id="addStatusModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: var(--card-bg); border-radius: 12px; padding: 24px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="font-size: 20px; font-weight: 600; color: var(--text-primary); margin: 0;">Add New Status</h2>
            <button onclick="closeAddStatusModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        <form method="POST" action="" id="createStatusForm" onsubmit="return createStatus(event)">
            <input type="hidden" name="create_status" value="1">
            <div class="form-group">
                <label for="name">Status Name *</label>
                <input type="text" id="name" name="name" required placeholder="e.g., On Hold, Review, Blocked" style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; background: var(--page-bg); color: var(--text-primary);">
            </div>
            <div class="form-group">
                <label for="add_color">Color *</label>
                <div style="position: relative; display: flex; align-items: center; gap: 8px;">
                    <span id="add_color_indicator" 
                          style="width: 24px; height: 24px; border-radius: 4px; border: 2px solid var(--border-color); background: #6c757d; flex-shrink: 0; display: inline-block; box-shadow: 0 1px 3px rgba(0,0,0,0.1);"></span>
                    <select id="add_color" name="color" required onchange="updateAddColorIndicator(this)" style="flex: 1; padding: 10px 12px 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; background: var(--page-bg); color: var(--text-primary); cursor: pointer;">
                        <?php foreach ($color_palette as $color_data): ?>
                            <option value="<?php echo htmlspecialchars($color_data[0]); ?>" 
                                    data-color="<?php echo htmlspecialchars($color_data[0]); ?>"
                                    style="background-color: <?php echo htmlspecialchars($color_data[0]); ?>; color: <?php 
                                        // Calculate if color is light or dark for text contrast
                                        $hex = str_replace('#', '', $color_data[0]);
                                        $r = hexdec(substr($hex, 0, 2));
                                        $g = hexdec(substr($hex, 2, 2));
                                        $b = hexdec(substr($hex, 4, 2));
                                        $brightness = ($r * 299 + $g * 587 + $b * 114) / 1000;
                                        echo $brightness > 186 ? '#000000' : '#ffffff';
                                    ?>;"
                                    <?php echo $color_data[0] == '#6c757d' ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($color_data[1]); ?> (<?php echo htmlspecialchars($color_data[0]); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" onclick="closeAddStatusModal()" style="padding: 10px 20px; border: 1px solid var(--border-color); background: white; border-radius: 6px; cursor: pointer;">Cancel</button>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-plus"></i> Add Status
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Status Modal -->
<div id="editStatusModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: var(--card-bg); border-radius: 12px; padding: 24px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="font-size: 20px; font-weight: 600; color: var(--text-primary); margin: 0;">Edit Status</h2>
            <button onclick="closeEditModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        <form method="POST" action="" id="editStatusForm" onsubmit="return updateStatus(event)">
            <input type="hidden" name="update_status" value="1">
            <input type="hidden" name="status_id" id="edit_status_id">
            <div class="form-group">
                <label for="edit_name">Status Name *</label>
                <input type="text" id="edit_name" name="name" required style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; background: var(--page-bg); color: var(--text-primary);">
            </div>
            <div class="form-group">
                <label for="edit_color">Color *</label>
                <div style="position: relative; display: flex; align-items: center; gap: 8px;">
                    <span id="edit_color_indicator" 
                          style="width: 24px; height: 24px; border-radius: 4px; border: 2px solid var(--border-color); background: #6c757d; flex-shrink: 0; display: inline-block; box-shadow: 0 1px 3px rgba(0,0,0,0.1);"></span>
                    <select id="edit_color" name="color" required onchange="updateEditColorIndicator(this)" style="flex: 1; padding: 10px 12px 10px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; background: var(--page-bg); color: var(--text-primary); cursor: pointer;">
                        <?php foreach ($color_palette as $color_data): ?>
                            <option value="<?php echo htmlspecialchars($color_data[0]); ?>"
                                    data-color="<?php echo htmlspecialchars($color_data[0]); ?>"
                                    style="background-color: <?php echo htmlspecialchars($color_data[0]); ?>; color: <?php 
                                        // Calculate if color is light or dark for text contrast
                                        $hex = str_replace('#', '', $color_data[0]);
                                        $r = hexdec(substr($hex, 0, 2));
                                        $g = hexdec(substr($hex, 2, 2));
                                        $b = hexdec(substr($hex, 4, 2));
                                        $brightness = ($r * 299 + $g * 587 + $b * 114) / 1000;
                                        echo $brightness > 186 ? '#000000' : '#ffffff';
                                    ?>;">
                                <?php echo htmlspecialchars($color_data[1]); ?> (<?php echo htmlspecialchars($color_data[0]); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
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
// Add Status Modal Functions
function openAddStatusModal() {
    const modal = document.getElementById('addStatusModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        // Initialize color indicator
        const addColorSelect = document.getElementById('add_color');
        if (addColorSelect) {
            updateAddColorIndicator(addColorSelect);
        }
    }
}

function closeAddStatusModal() {
    const modal = document.getElementById('addStatusModal');
    const form = document.getElementById('createStatusForm');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
    if (form) {
        form.reset();
        // Reset color indicator to default
        const colorIndicator = document.getElementById('add_color_indicator');
        if (colorIndicator) {
            colorIndicator.style.background = '#6c757d';
        }
    }
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const addModal = document.getElementById('addStatusModal');
    const editModal = document.getElementById('editStatusModal');
    if (addModal && event.target == addModal) {
        closeAddStatusModal();
    }
    if (editModal && event.target == editModal) {
        closeEditModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const addModal = document.getElementById('addStatusModal');
        const editModal = document.getElementById('editStatusModal');
        if (addModal && addModal.style.display === 'flex') {
            closeAddStatusModal();
        }
        if (editModal && editModal.style.display === 'flex') {
            closeEditModal();
        }
    }
});

// Save Default Filter (from table checkboxes)
function saveDefaultFilter() {
    const checkboxes = document.querySelectorAll('.default-filter-checkbox:checked');
    const selectedIds = Array.from(checkboxes).map(cb => parseInt(cb.getAttribute('data-status-id')));
    
    showMessage('Updating...', 'info');
    
    const formData = new FormData();
    formData.append('set_default_filter', '1');
    selectedIds.forEach(id => {
        formData.append('status_ids[]', id);
    });
    
    fetch('manage_statuses.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message, 'success');
            // Reload page to show updated checkboxes
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showMessage(data.error, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Error updating default filter statuses', 'error');
    });
}

// Save Default Task (from table radio buttons)
function saveDefaultTask() {
    const radio = document.querySelector('input[name="default_task_status"]:checked');
    if (!radio) {
        showMessage('Please select a default task status', 'error');
        return;
    }
    
    const statusId = parseInt(radio.value);
    
    showMessage('Updating...', 'info');
    
    const formData = new FormData();
    formData.append('set_default_task', '1');
    formData.append('status_id', statusId);
    
    fetch('manage_statuses.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message, 'success');
            // Reload page to show updated radio buttons
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showMessage(data.error, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Error updating default task status', 'error');
    });
}

// Old multiselect code removed - we now use table format with checkboxes/radio buttons

function setDefaultTask(statusId) {
    if (!statusId) return;
    showMessage('Updating...', 'info');
    
    const formData = new FormData();
    formData.append('set_default_task', '1');
    formData.append('status_id', statusId);
    
    fetch('manage_statuses.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message, 'success');
            // Reload page to show updated radio buttons
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showMessage(data.error, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Error updating default task status', 'error');
    });
}

function swapStatus(id1, id2) {
    if (!confirm('Swap the order of these two statuses?')) return;
    
    const formData = new FormData();
    formData.append('swap_order', '1');
    formData.append('status_id_1', id1);
    formData.append('status_id_2', id2);
    
    fetch('manage_statuses.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message, 'success');
            // Reload page to show updated order
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showMessage(data.error, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Error swapping status order', 'error');
    });
}

function deleteStatus(statusId) {
    if (!confirm('Are you sure you want to delete this status?')) return;
    
    const formData = new FormData();
    formData.append('delete_status', '1');
    formData.append('status_id', statusId);
    
    fetch('manage_statuses.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message, 'success');
            // Reload page to show updated table
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showMessage(data.error, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Error deleting status', 'error');
    });
}

function removeStatusFromDropdowns(statusId) {
    // Remove from default filter dropdown
    const filterSelect = document.getElementById('default_filter_status');
    if (filterSelect) {
        const option = filterSelect.querySelector(`option[value="${statusId}"]`);
        if (option) option.remove();
    }
    
    // Remove from default task dropdown
    const taskSelect = document.getElementById('default_task_status');
    if (taskSelect) {
        const option = taskSelect.querySelector(`option[value="${statusId}"]`);
        if (option) option.remove();
    }
}

function updateDefaultFilterBadges(selectedIds) {
    // Remove all filter badges
    document.querySelectorAll('.status-badge.filter').forEach(badge => badge.remove());
    
    // Add badge to selected statuses
    if (Array.isArray(selectedIds)) {
        selectedIds.forEach(statusId => {
            const statusItem = document.querySelector(`[data-status-id="${statusId}"]`);
            if (statusItem) {
                const badgesContainer = statusItem.querySelector('.status-badges');
                if (badgesContainer) {
                    const filterBadge = document.createElement('span');
                    filterBadge.className = 'status-badge filter';
                    filterBadge.textContent = 'Default Filter';
                    badgesContainer.appendChild(filterBadge);
                }
            }
        });
    }
}

function updateDefaultTaskBadges(selectedId) {
    // Remove all task badges
    document.querySelectorAll('.status-badge.task').forEach(badge => badge.remove());
    // Add badge to selected status
    const statusItem = document.querySelector(`[data-status-id="${selectedId}"]`);
    if (statusItem) {
        const badgesContainer = statusItem.querySelector('.status-badges');
        if (badgesContainer) {
            const taskBadge = document.createElement('span');
            taskBadge.className = 'status-badge task';
            taskBadge.textContent = 'Default Task';
            badgesContainer.appendChild(taskBadge);
        }
    }
}

function updateStatusCount() {
    const count = document.querySelectorAll('.status-item').length;
    const countElement = document.querySelector('.statuses-list h2');
    if (countElement) {
        countElement.innerHTML = '<i class="fas fa-list"></i> Existing Statuses (' + count + ')';
    }
}

function showMessage(message, type) {
    // Remove existing alerts
    document.querySelectorAll('.alert').forEach(alert => alert.remove());
    
    const alert = document.createElement('div');
    alert.className = 'alert alert-' + (type === 'success' ? 'success' : type === 'error' ? 'error' : 'info');
    alert.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle') + '"></i> ' + message;
    
    const container = document.querySelector('.status-management-container');
    if (container) {
        container.insertBefore(alert, container.firstChild.nextSibling);
    }
    
    // Auto remove after 3 seconds
    if (type !== 'info') {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.3s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, 3000);
    }
}

function editStatus(id, name, color) {
    document.getElementById('edit_status_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_color').value = color;
    
    // Update color indicator
    const editColorIndicator = document.getElementById('edit_color_indicator');
    if (editColorIndicator) {
        editColorIndicator.style.background = color;
    }
    
    document.getElementById('editStatusModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// Update color indicator for Add Status modal
function updateAddColorIndicator(selectElement) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const color = selectedOption ? selectedOption.getAttribute('data-color') : null;
    const colorIndicator = document.getElementById('add_color_indicator');
    if (colorIndicator && color) {
        colorIndicator.style.background = color;
    }
}

// Update color indicator for Edit Status modal
function updateEditColorIndicator(selectElement) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const color = selectedOption ? selectedOption.getAttribute('data-color') : null;
    const colorIndicator = document.getElementById('edit_color_indicator');
    if (colorIndicator && color) {
        colorIndicator.style.background = color;
    }
}

function closeEditModal() {
    const modal = document.getElementById('editStatusModal');
    const form = document.getElementById('editStatusForm');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
    if (form) {
        form.reset();
    }
}

function createStatus(event) {
    event.preventDefault();
    
    const form = document.getElementById('createStatusForm');
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
    
    fetch('manage_statuses.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message, 'success');
            closeAddStatusModal();
            // Reload page to show new status in table
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showMessage(data.error, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Error creating status', 'error');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
    
    return false;
}

function updateStatus(event) {
    event.preventDefault();
    
    const form = document.getElementById('editStatusForm');
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    fetch('manage_statuses.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message, 'success');
            closeEditModal();
            // Reload page to show updated status in table
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showMessage(data.error, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Error updating status', 'error');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
    
    return false;
}

function addStatusToDOM(status) {
    const container = document.getElementById('statuses_container');
    if (!container) return;
    
    const statusItem = document.createElement('div');
    statusItem.className = 'status-item';
    statusItem.setAttribute('data-status-id', status.id);
    statusItem.setAttribute('data-order', status.display_order);
    
    let badgesHTML = '';
    if (status.is_default) badgesHTML += '<span class="status-badge default">Default</span>';
    if (status.is_default_filter) badgesHTML += '<span class="status-badge filter">Default Filter</span>';
    if (status.is_default_task) badgesHTML += '<span class="status-badge task">Default Task</span>';
    
    statusItem.innerHTML = `
        <div class="status-color" style="background: ${escapeHtml(status.color)};"></div>
        <div class="status-info">
            <div class="status-name">
                ${escapeHtml(status.name)}
                <div class="status-badges">${badgesHTML}</div>
            </div>
            <div class="status-meta">Order: ${status.display_order}</div>
        </div>
        <div class="status-actions">
            <button type="button" class="btn-icon" onclick="editStatus(${status.id}, '${escapeHtml(status.name).replace(/'/g, "\\'")}', '${status.color}')" title="Edit">
                <i class="fas fa-edit"></i>
            </button>
            ${!status.is_default ? `<button type="button" class="btn-icon danger" onclick="deleteStatus(${status.id})" title="Delete"><i class="fas fa-trash"></i></button>` : ''}
        </div>
    `;
    
    container.appendChild(statusItem);
    
    // Update dropdowns
    updateStatusDropdowns(status);
    
    // Highlight new status
    statusItem.style.backgroundColor = '#e0f2fe';
    setTimeout(() => {
        statusItem.style.transition = 'background-color 0.5s';
        statusItem.style.backgroundColor = '';
    }, 1000);
}

function updateStatusDropdowns(status) {
    // Add to default filter dropdown
    const filterSelect = document.getElementById('default_filter_status');
    if (filterSelect) {
        const option = document.createElement('option');
        option.value = status.id;
        option.textContent = status.name;
        if (status.is_default_filter) option.selected = true;
        filterSelect.appendChild(option);
    }
    
    // Add to default task dropdown
    const taskSelect = document.getElementById('default_task_status');
    if (taskSelect) {
        const option = document.createElement('option');
        option.value = status.id;
        option.textContent = status.name;
        if (status.is_default_task) option.selected = true;
        taskSelect.appendChild(option);
    }
}

function updateStatusInDOM(status) {
    const statusItem = document.querySelector(`[data-status-id="${status.id}"]`);
    if (!statusItem) return;
    
    // Update color
    const colorDiv = statusItem.querySelector('.status-color');
    if (colorDiv) colorDiv.style.background = status.color;
    
    // Update name
    const nameDiv = statusItem.querySelector('.status-name');
    if (nameDiv) {
        nameDiv.innerHTML = `
            ${escapeHtml(status.name)}
            <div class="status-badges">
                ${status.is_default ? '<span class="status-badge default">Default</span>' : ''}
                ${status.is_default_filter ? '<span class="status-badge filter">Default Filter</span>' : ''}
                ${status.is_default_task ? '<span class="status-badge task">Default Task</span>' : ''}
            </div>
        `;
    }
    
    // Update order
    const metaDiv = statusItem.querySelector('.status-meta');
    if (metaDiv) metaDiv.textContent = `Order: ${status.display_order}`;
    
    // Update order attribute
    statusItem.setAttribute('data-order', status.display_order);
    
    // Highlight updated status
    statusItem.style.backgroundColor = '#e0f2fe';
    setTimeout(() => {
        statusItem.style.transition = 'background-color 0.5s';
        statusItem.style.backgroundColor = '';
    }, 1000);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php include 'includes/footer.php'; ?>
