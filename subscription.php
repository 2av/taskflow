<?php
require_once 'config/config.php';
requireLogin();

$page_title = 'Subscription Management';

$conn = getDBConnection();
$message = '';
$error = '';

// Get organization subscription info
$organization_id = getOrganizationId();
$subscription_info = null;
$subscription_history = [];

if ($organization_id) {
    $stmt = $conn->prepare("SELECT * FROM organizations WHERE id = ?");
    $stmt->bind_param("i", $organization_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $subscription_info = $result->fetch_assoc();
    $stmt->close();
    
    // Get subscription history
    $stmt = $conn->prepare("SELECT * FROM subscriptions WHERE organization_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $organization_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $subscription_history = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Handle subscription activation (for super admin)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['activate_subscription']) && isSuperAdmin()) {
    $org_id = intval($_POST['organization_id']);
    $plan_name = trim($_POST['plan_name']);
    $plan_duration = intval($_POST['plan_duration']);
    $amount = floatval($_POST['amount']);
    
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d', strtotime("+$plan_duration months"));
    
    try {
        $conn->begin_transaction();
        
        // Create subscription record
        $stmt = $conn->prepare("INSERT INTO subscriptions (organization_id, plan_name, plan_duration, start_date, end_date, amount, status, payment_status) VALUES (?, ?, ?, ?, ?, ?, 'active', 'paid')");
        $stmt->bind_param("isisds", $org_id, $plan_name, $plan_duration, $start_date, $end_date, $amount);
        $stmt->execute();
        
        // Update organization subscription status
        $stmt = $conn->prepare("UPDATE organizations SET subscription_status = 'active', subscription_start_date = ?, subscription_end_date = ?, subscription_plan = ? WHERE id = ?");
        $stmt->bind_param("sssi", $start_date, $end_date, $plan_name, $org_id);
        $stmt->execute();
        
        $conn->commit();
        $message = 'Subscription activated successfully!';
        
        // Refresh subscription info
        $stmt = $conn->prepare("SELECT * FROM organizations WHERE id = ?");
        $stmt->bind_param("i", $org_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $subscription_info = $result->fetch_assoc();
        $stmt->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = 'Error activating subscription: ' . $e->getMessage();
    }
}

$subscription_status = checkSubscriptionStatus($organization_id);

$conn->close();

include 'includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Subscription Management</h1>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if (isset($_GET['expired'])): ?>
    <div class="alert alert-error" style="font-size: 18px; padding: 20px;">
        <strong><i class="fas fa-exclamation-triangle"></i> Subscription Expired!</strong><br>
        Your subscription has expired. Please contact the administrator to renew your subscription.
    </div>
<?php endif; ?>

<?php if ($subscription_info): ?>
    <div class="card" style="margin-bottom: 30px;">
        <h2 style="margin-bottom: 20px; color: #333;">Current Subscription Status</h2>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="color: #666; font-size: 14px;">Status</label>
                <div style="font-size: 18px; font-weight: bold; margin-top: 5px;">
                    <?php
                    $status = $subscription_info['subscription_status'];
                    $status_class = $status == 'active' ? 'badge-success' : ($status == 'trial' ? 'badge-info' : 'badge-danger');
                    ?>
                    <span class="badge <?php echo $status_class; ?>">
                        <?php echo ucfirst($status); ?>
                    </span>
                </div>
            </div>
            
            <div>
                <label style="color: #666; font-size: 14px;">Plan</label>
                <div style="font-size: 18px; font-weight: bold; margin-top: 5px;">
                    <?php echo htmlspecialchars($subscription_info['subscription_plan'] ?? 'Free Trial'); ?>
                </div>
            </div>
            
            <?php if ($subscription_info['subscription_status'] == 'trial'): ?>
                <div>
                    <label style="color: #666; font-size: 14px;">Trial End Date</label>
                    <div style="font-size: 18px; font-weight: bold; margin-top: 5px;">
                        <?php echo formatDate($subscription_info['trial_end_date']); ?>
                    </div>
                </div>
                <div>
                    <label style="color: #666; font-size: 14px;">Days Remaining</label>
                    <div style="font-size: 18px; font-weight: bold; margin-top: 5px; color: <?php echo $subscription_status['days_left'] < 30 ? '#e74c3c' : '#27ae60'; ?>;">
                        <?php echo max(0, intval($subscription_status['days_left'])); ?> days
                    </div>
                </div>
            <?php elseif ($subscription_info['subscription_status'] == 'active'): ?>
                <div>
                    <label style="color: #666; font-size: 14px;">Subscription End Date</label>
                    <div style="font-size: 18px; font-weight: bold; margin-top: 5px;">
                        <?php echo formatDate($subscription_info['subscription_end_date']); ?>
                    </div>
                </div>
                <div>
                    <label style="color: #666; font-size: 14px;">Days Remaining</label>
                    <div style="font-size: 18px; font-weight: bold; margin-top: 5px; color: <?php echo $subscription_status['days_left'] < 30 ? '#e74c3c' : '#27ae60'; ?>;">
                        <?php echo max(0, intval($subscription_status['days_left'])); ?> days
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin-top: 20px;">
            <strong>Note:</strong> <?php echo htmlspecialchars($subscription_status['message']); ?>
        </div>
    </div>
<?php endif; ?>

<?php if (isSuperAdmin()): ?>
    <!-- Super Admin: Activate Subscription for Organizations -->
    <div class="card" style="margin-bottom: 30px;">
        <h2 style="margin-bottom: 20px; color: #333;">Activate Subscription</h2>
        <form method="POST" action="">
            <input type="hidden" name="activate_subscription" value="1">
            
            <div class="form-group">
                <label for="organization_id">Organization</label>
                <select id="organization_id" name="organization_id" required>
                    <option value="">Select Organization</option>
                    <?php
                    $conn = getDBConnection();
                    $orgs = $conn->query("SELECT * FROM organizations ORDER BY name")->fetch_all(MYSQLI_ASSOC);
                    $conn->close();
                    foreach ($orgs as $org):
                    ?>
                        <option value="<?php echo $org['id']; ?>"><?php echo htmlspecialchars($org['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="plan_name">Plan Name *</label>
                <input type="text" id="plan_name" name="plan_name" required placeholder="e.g., Basic, Pro, Enterprise">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="plan_duration">Duration (Months) *</label>
                    <input type="number" id="plan_duration" name="plan_duration" required min="1" value="12">
                </div>
                
                <div class="form-group">
                    <label for="amount">Amount ($) *</label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0" required value="0.00">
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Activate Subscription</button>
        </form>
    </div>
<?php endif; ?>

<!-- Subscription History -->
<?php if (!empty($subscription_history)): ?>
    <div class="card">
        <h2 style="margin-bottom: 20px; color: #333;">Subscription History</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Duration</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Payment Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subscription_history as $sub): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($sub['plan_name']); ?></td>
                            <td><?php echo $sub['plan_duration']; ?> months</td>
                            <td><?php echo formatDate($sub['start_date']); ?></td>
                            <td><?php echo formatDate($sub['end_date']); ?></td>
                            <td>$<?php echo number_format($sub['amount'], 2); ?></td>
                            <td>
                                <span class="badge <?php echo $sub['status'] == 'active' ? 'badge-success' : 'badge-secondary'; ?>">
                                    <?php echo ucfirst($sub['status']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo $sub['payment_status'] == 'paid' ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo ucfirst($sub['payment_status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
