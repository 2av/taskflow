<?php
require_once 'config/config.php';
requireAgPrimeTechAdmin();

$page_title = 'Organizations';

$conn = getDBConnection();
$message = '';
$error = '';

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$subscription_filter = isset($_GET['subscription_status']) ? $_GET['subscription_status'] : '';
$expired_filter = isset($_GET['expired']) ? $_GET['expired'] : '';

// Build query with filters
$where_conditions = [];
$params = [];
$param_types = '';

if (!empty($search)) {
    $where_conditions[] = "(o.name LIKE ? OR o.email LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'ss';
}

if (!empty($status_filter)) {
    $where_conditions[] = "o.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

if (!empty($subscription_filter)) {
    $where_conditions[] = "o.subscription_status = ?";
    $params[] = $subscription_filter;
    $param_types .= 's';
}

// Filter by expired status
if ($expired_filter === 'yes') {
    $today = date('Y-m-d');
    $where_conditions[] = "(o.subscription_status = 'expired' OR (o.subscription_status = 'trial' AND o.trial_end_date < ?) OR (o.subscription_status = 'active' AND o.subscription_end_date < ?))";
    $params[] = $today;
    $params[] = $today;
    $param_types .= 'ss';
} elseif ($expired_filter === 'no') {
    $today = date('Y-m-d');
    $where_conditions[] = "o.subscription_status != 'expired' AND (o.subscription_status != 'trial' OR o.trial_end_date >= ?) AND (o.subscription_status != 'active' OR o.subscription_end_date >= ?)";
    $params[] = $today;
    $params[] = $today;
    $param_types .= 'ss';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get organizations with user count
$query = "SELECT o.*, 
         COUNT(DISTINCT u.id) as user_count,
         COUNT(DISTINCT p.id) as project_count
         FROM organizations o
         LEFT JOIN users u ON o.id = u.organization_id
         LEFT JOIN projects p ON o.id = p.organization_id
         {$where_clause}
         GROUP BY o.id
         ORDER BY o.created_at DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $organizations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $result = $conn->query($query);
    $organizations = $result->fetch_all(MYSQLI_ASSOC);
}

// Calculate expiration status for each organization
$today = date('Y-m-d');
foreach ($organizations as &$org) {
    $is_expired = false;
    $expiry_date = null;
    $days_remaining = null;
    
    if ($org['subscription_status'] == 'trial') {
        $expiry_date = $org['trial_end_date'];
        if ($expiry_date && $expiry_date < $today) {
            $is_expired = true;
        } elseif ($expiry_date) {
            $days_remaining = (strtotime($expiry_date) - strtotime($today)) / 86400;
        }
    } elseif ($org['subscription_status'] == 'active') {
        $expiry_date = $org['subscription_end_date'];
        if ($expiry_date && $expiry_date < $today) {
            $is_expired = true;
        } elseif ($expiry_date) {
            $days_remaining = (strtotime($expiry_date) - strtotime($today)) / 86400;
        }
    } elseif ($org['subscription_status'] == 'expired') {
        $is_expired = true;
    }
    
    $org['is_expired'] = $is_expired;
    $org['expiry_date'] = $expiry_date;
    $org['days_remaining'] = $days_remaining !== null ? max(0, intval($days_remaining)) : null;
}

$conn->close();

include 'includes/header.php';
?>

<div style="width: 100%; padding: 20px;">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
        <div>
            <h1 class="text-3xl font-semibold text-gray-900" style="font-size: 28px; font-weight: 600; color: #1e293b; margin: 0;">Organizations</h1>
            <p class="text-gray-500 mt-1" style="color: #64748b; margin-top: 4px; font-size: 14px;">Manage all organizations and their subscriptions</p>
        </div>
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

    <!-- Filters -->
    <div class="card" style="background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <form method="GET" action="" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
            <div>
                <label for="search" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: #475569;">Search</label>
                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Name or email..." 
                       style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
            </div>
            
            <div>
                <label for="status" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: #475569;">Status</label>
                <select id="status" name="status" style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                    <option value="">All Statuses</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            
            <div>
                <label for="subscription_status" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: #475569;">Subscription</label>
                <select id="subscription_status" name="subscription_status" style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                    <option value="">All Subscriptions</option>
                    <option value="trial" <?php echo $subscription_filter === 'trial' ? 'selected' : ''; ?>>Trial</option>
                    <option value="active" <?php echo $subscription_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="expired" <?php echo $subscription_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                    <option value="cancelled" <?php echo $subscription_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            
            <div>
                <label for="expired" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: #475569;">Expired</label>
                <select id="expired" name="expired" style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                    <option value="">All</option>
                    <option value="yes" <?php echo $expired_filter === 'yes' ? 'selected' : ''; ?>>Expired Only</option>
                    <option value="no" <?php echo $expired_filter === 'no' ? 'selected' : ''; ?>>Not Expired</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary" style="padding: 8px 16px; background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%); color: white; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; white-space: nowrap;">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="organizations" class="btn btn-secondary" style="padding: 8px 16px; background: #e2e8f0; color: #475569; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; white-space: nowrap;">
                    <i class="fas fa-times"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Organizations Table -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Organization</th>
                    <th>Contact</th>
                    <th>Status</th>
                    <th>Subscription</th>
                    <th>Expiry Date</th>
                    <th>Days Left</th>
                    <th>Users</th>
                    <th>Projects</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($organizations)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px 20px; color: #94a3b8;">
                            <i class="fas fa-building" style="font-size: 48px; margin-bottom: 12px; opacity: 0.3; display: block;"></i>
                            <p style="font-size: 16px; margin: 0;">No organizations found</p>
                            <p style="font-size: 14px; margin-top: 8px; color: #cbd5e1;"><?php echo !empty($search) || !empty($status_filter) || !empty($subscription_filter) || !empty($expired_filter) ? 'Try adjusting your filters' : 'No organizations registered yet'; ?></p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($organizations as $org): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 600; color: #1e293b; font-size: 15px; margin-bottom: 4px;">
                                    <?php echo htmlspecialchars($org['name']); ?>
                                </div>
                                <?php if (!empty($org['subscription_plan'])): ?>
                                    <div style="font-size: 12px; color: #64748b;">
                                        <i class="fas fa-tag" style="margin-right: 4px;"></i>
                                        <?php echo htmlspecialchars($org['subscription_plan']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($org['email'])): ?>
                                    <div style="color: #64748b; font-size: 13px; margin-bottom: 4px;">
                                        <i class="fas fa-envelope" style="margin-right: 6px; color: #94a3b8;"></i>
                                        <?php echo htmlspecialchars($org['email']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($org['phone'])): ?>
                                    <div style="color: #64748b; font-size: 13px;">
                                        <i class="fas fa-phone" style="margin-right: 6px; color: #94a3b8;"></i>
                                        <?php echo htmlspecialchars($org['phone']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $status = $org['status'];
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
                            <td>
                                <?php 
                                $sub_status = $org['subscription_status'];
                                $sub_colors = [
                                    'trial' => ['bg' => '#dbeafe', 'text' => '#1e40af', 'border' => '#3b82f6'],
                                    'active' => ['bg' => '#d1fae5', 'text' => '#065f46', 'border' => '#10b981'],
                                    'expired' => ['bg' => '#fee2e2', 'text' => '#991b1b', 'border' => '#ef4444'],
                                    'cancelled' => ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#9ca3af']
                                ];
                                $sub_color = $sub_colors[$sub_status] ?? ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#9ca3af'];
                                ?>
                                <span class="badge" style="display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 500; background: <?php echo $sub_color['bg']; ?>; color: <?php echo $sub_color['text']; ?>; border: 1px solid <?php echo $sub_color['border']; ?>;">
                                    <?php echo ucfirst($sub_status); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($org['expiry_date']): ?>
                                    <div style="color: <?php echo $org['is_expired'] ? '#ef4444' : '#64748b'; ?>; font-size: 13px; font-weight: <?php echo $org['is_expired'] ? '600' : '400'; ?>;">
                                        <i class="fas fa-calendar-alt" style="margin-right: 6px; color: #94a3b8;"></i>
                                        <?php echo formatDate($org['expiry_date']); ?>
                                        <?php if ($org['is_expired']): ?>
                                            <i class="fas fa-exclamation-triangle" style="margin-left: 6px; color: #ef4444;"></i>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #cbd5e1; font-style: italic;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($org['days_remaining'] !== null): ?>
                                    <div style="color: <?php echo $org['days_remaining'] < 30 ? '#ef4444' : ($org['days_remaining'] < 60 ? '#f59e0b' : '#10b981'); ?>; font-size: 13px; font-weight: 600;">
                                        <?php echo $org['days_remaining']; ?> days
                                    </div>
                                <?php elseif ($org['is_expired']): ?>
                                    <span style="color: #ef4444; font-weight: 600; font-size: 13px;">Expired</span>
                                <?php else: ?>
                                    <span style="color: #cbd5e1; font-style: italic;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center; color: #64748b;">
                                <span style="font-weight: 600;"><?php echo intval($org['user_count']); ?></span>
                            </td>
                            <td style="text-align: center; color: #64748b;">
                                <span style="font-weight: 600;"><?php echo intval($org['project_count']); ?></span>
                            </td>
                            <td style="color: #64748b; font-size: 13px;">
                                <i class="fas fa-calendar-alt" style="margin-right: 6px; color: #94a3b8;"></i>
                                <?php echo formatDate($org['created_at']); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
