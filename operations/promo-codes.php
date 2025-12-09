<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config-payment-api.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

// Check if user is logged in and has staff access
if (!isset($_SESSION['user']['id'])) {
    header('Location: /login.php');
    exit;
}

$user_role = $_SESSION['user']['role'] ?? '';
$staff_roles = ['administrator', 'admin', 'support_consultant', 'accountant'];
$is_admin = in_array(strtolower($user_role), array_map('strtolower', $staff_roles));

if (!$is_admin) {
    header('Location: /dashboard.php?error=' . urlencode('Access denied'));
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_promo':
                createPromoCode($_POST);
                break;
            case 'toggle_status':
                togglePromoStatus($_POST['promo_id']);
                break;
            case 'delete_promo':
                deletePromoCode($_POST['promo_id']);
                break;
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query
$where_conditions = [];
$params = [];

if ($status_filter !== 'all') {
    if ($status_filter === 'active') {
        $where_conditions[] = "active = TRUE AND valid_until >= NOW()";
    } elseif ($status_filter === 'expired') {
        $where_conditions[] = "valid_until < NOW()";
    } elseif ($status_filter === 'disabled') {
        $where_conditions[] = "active = FALSE";
    }
}

if ($type_filter !== 'all') {
    $where_conditions[] = "discount_type = ?";
    $params[] = $type_filter;
}

if ($search) {
    $where_conditions[] = "(code LIKE ? OR name LIKE ? OR description LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

try {
    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM promo_codes {$where_sql}";
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($params);
    $total_count = $stmt->fetch()['total'];
    
    // Get promo codes
    $query = "SELECT pc.*, u.username as created_by_name,
                     (SELECT COUNT(*) FROM promo_code_usage pcu WHERE pcu.promo_code_id = pc.id) as usage_count
              FROM promo_codes pc 
              LEFT JOIN users u ON pc.created_by = u.id
              {$where_sql}
              ORDER BY pc.created_at DESC 
              LIMIT {$per_page} OFFSET {$offset}";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $promo_codes = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Promo codes query error: " . $e->getMessage());
    $promo_codes = [];
    $total_count = 0;
}

function createPromoCode($data) {
    global $pdo;
    
    try {
        // Validate input
        $code = strtoupper(trim($data['code']));
        $name = trim($data['name']);
        $description = trim($data['description'] ?? '');
        $discount_type = $data['discount_type'];
        $discount_value = floatval($data['discount_value']);
        $valid_from = $data['valid_from'];
        $valid_until = $data['valid_until'];
        
        // Optional fields
        $max_discount = !empty($data['max_discount_amount']) ? floatval($data['max_discount_amount']) : null;
        $min_order = floatval($data['minimum_order_amount'] ?? 0);
        $usage_limit = !empty($data['usage_limit']) ? intval($data['usage_limit']) : null;
        $usage_limit_per_customer = intval($data['usage_limit_per_customer'] ?? 1);
        $applicable_to = $data['applicable_to'] ?? 'all';
        $currency = $data['currency'] ?? 'GBP';
        
        // Recurring settings
        $is_recurring = isset($data['is_recurring']) ? 1 : 0;
        $recurring_months = $is_recurring && !empty($data['recurring_months']) ? intval($data['recurring_months']) : null;
        $recurring_billing_cycles = $data['recurring_billing_cycles'] ?? 'all';
        
        $stmt = $pdo->prepare("
            INSERT INTO promo_codes (
                code, name, description, discount_type, discount_value, max_discount_amount, 
                minimum_order_amount, usage_limit, usage_limit_per_customer, valid_from, 
                valid_until, applicable_to, currency, is_recurring, recurring_months, 
                recurring_billing_cycles, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $code, $name, $description, $discount_type, $discount_value, $max_discount,
            $min_order, $usage_limit, $usage_limit_per_customer, $valid_from,
            $valid_until, $applicable_to, $currency, $is_recurring, $recurring_months,
            $recurring_billing_cycles, $_SESSION['user']['id']
        ]);
        
        $_SESSION['success'] = "Promo code '{$code}' created successfully!";
        
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Duplicate entry
            $_SESSION['error'] = "Promo code already exists!";
        } else {
            error_log("Create promo code error: " . $e->getMessage());
            $_SESSION['error'] = "Error creating promo code: " . $e->getMessage();
        }
    }
    
    header('Location: /operations/promo-codes.php');
    exit;
}

function togglePromoStatus($promo_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("UPDATE promo_codes SET active = NOT active WHERE id = ?");
        $stmt->execute([$promo_id]);
        $_SESSION['success'] = "Promo code status updated!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error updating promo code status.";
    }
    
    header('Location: /operations/promo-codes.php');
    exit;
}

function deletePromoCode($promo_id) {
    global $pdo;
    
    try {
        // Check if promo code has been used
        $stmt = $pdo->prepare("SELECT COUNT(*) as usage_count FROM promo_code_usage WHERE promo_code_id = ?");
        $stmt->execute([$promo_id]);
        $usage_count = $stmt->fetch()['usage_count'];
        
        if ($usage_count > 0) {
            $_SESSION['error'] = "Cannot delete promo code that has been used. Disable it instead.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM promo_codes WHERE id = ?");
            $stmt->execute([$promo_id]);
            $_SESSION['success'] = "Promo code deleted successfully!";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error deleting promo code.";
    }
    
    header('Location: /operations/promo-codes.php');
    exit;
}

function formatCurrency($amount, $currency = 'GBP') {
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
    $symbol = $symbols[$currency] ?? $currency . ' ';
    return $symbol . number_format($amount, 2);
}

function getStatusBadge($promo) {
    if (!$promo['active']) {
        return '<span class="badge bg-secondary">Disabled</span>';
    } elseif (strtotime($promo['valid_until']) < time()) {
        return '<span class="badge bg-warning">Expired</span>';
    } elseif ($promo['usage_limit'] && $promo['usage_count'] >= $promo['usage_limit']) {
        return '<span class="badge bg-danger">Limit Reached</span>';
    } else {
        return '<span class="badge bg-success">Active</span>';
    }
}

$total_pages = ceil($total_count / $per_page);
$page_title = "Promo Code Management | CaminhoIT Operations";
?>
<?php include $_SERVER'['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>


<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
        --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        --border-radius: 12px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    body {
        background: #F8FAFC;
    }

    .container {
        max-width: 1400px;
    }

    .card, .box, .panel {
        background: white;
        border-radius: var(--border-radius);
        padding: 1.5rem;
        box-shadow: var(--card-shadow);
        margin-bottom: 1.5rem;
    }

    .btn-primary {
        background: var(--primary-gradient);
        border: none;
        color: white;
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        transition: var(--transition);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
    }

    table.table {
        background: white;
        border-radius: var(--border-radius);
        overflow: hidden;
    }

    .table thead {
        background: #F8FAFC;
    }

    .badge {
        padding: 0.375rem 0.75rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .modal {
        z-index: 1050;
    }

    .modal-content {
        border-radius: var(--border-radius);
    }
</style>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="dashboard-hero-content">
            <h1 class="dashboard-hero-title">
                <i class="bi bi-tags me-2"></i>
                Promo Code Management
            </h1>
            <p class="dashboard-hero-subtitle">
                Create and manage discount codes, recurring promotions, and customer incentives
            </p>
            <div class="dashboard-hero-actions">
                <button class="btn c-btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                    <i class="bi bi-plus-circle me-1"></i>
                    Create Promo Code
                </button>
            </div>
        </div>
    </div>
</header>

<div class="container py-5" style="margin-top: -80px; position: relative; z-index: 10;">
    
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="breadcrumb-enhanced">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="/operations/">Operations</a></li>
            <li class="breadcrumb-item active" aria-current="page">Promo Codes</li>
        </ol>
    </nav>

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle me-2"></i>
        <?= htmlspecialchars($_SESSION['success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success']); endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?= htmlspecialchars($_SESSION['error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error']); endif; ?>

    <!-- Filters and Actions -->
    <div class="enhanced-card">
        <div class="card-body-enhanced">
            <div class="row align-items-end">
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-control-enhanced" onchange="filterPromoCodes()">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="expired" <?= $status_filter === 'expired' ? 'selected' : '' ?>>Expired</option>
                        <option value="disabled" <?= $status_filter === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select class="form-control-enhanced" onchange="filterPromoCodes()">
                        <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All Types</option>
                        <option value="percentage" <?= $type_filter === 'percentage' ? 'selected' : '' ?>>Percentage</option>
                        <option value="fixed_amount" <?= $type_filter === 'fixed_amount' ? 'selected' : '' ?>>Fixed Amount</option>
                        <option value="free_shipping" <?= $type_filter === 'free_shipping' ? 'selected' : '' ?>>Free Shipping</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control-enhanced" placeholder="Code, name, or description" value="<?= htmlspecialchars($search) ?>" onchange="filterPromoCodes()">
                </div>
                <div class="col-md-4 text-end">
                    <button type="button" class="btn btn-success-enhanced btn-enhanced" data-bs-toggle="modal" data-bs-target="#createPromoModal">
                        <i class="bi bi-plus"></i>
                        Create Promo Code
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Promo Codes Table -->
    <div class="enhanced-card">
        <div class="card-header-enhanced">
            <h5 class="card-title-enhanced">
                <i class="bi bi-tags"></i>
                Promo Codes (<?= number_format($total_count) ?> total)
            </h5>
        </div>
        <div class="card-body-enhanced p-0">
            <?php if (empty($promo_codes)): ?>
            <div class="text-center py-5">
                <i class="bi bi-tag display-1 text-muted"></i>
                <p class="text-muted mt-3">No promo codes found.</p>
                <button type="button" class="btn btn-primary-enhanced btn-enhanced" data-bs-toggle="modal" data-bs-target="#createPromoModal">
                    <i class="bi bi-plus"></i>
                    Create First Promo Code
                </button>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-enhanced mb-0">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name & Type</th>
                            <th>Discount</th>
                            <th>Usage</th>
                            <th>Valid Period</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($promo_codes as $promo): ?>
                        <tr>
                            <td>
                                <strong class="text-primary"><?= htmlspecialchars($promo['code']) ?></strong>
                                <?php if ($promo['is_recurring']): ?>
                                <br><small class="badge bg-info">Recurring (<?= $promo['recurring_months'] ?> months)</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($promo['name']) ?></strong>
                                <br><small class="text-muted"><?= ucfirst(str_replace('_', ' ', $promo['discount_type'])) ?></small>
                                <?php if ($promo['description']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($promo['description']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($promo['discount_type'] === 'percentage'): ?>
                                    <strong><?= $promo['discount_value'] ?>%</strong>
                                    <?php if ($promo['max_discount_amount']): ?>
                                    <br><small class="text-muted">Max: <?= formatCurrency($promo['max_discount_amount'], $promo['currency']) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <strong><?= formatCurrency($promo['discount_value'], $promo['currency']) ?></strong>
                                <?php endif; ?>
                                
                                <?php if ($promo['minimum_order_amount'] > 0): ?>
                                <br><small class="text-muted">Min order: <?= formatCurrency($promo['minimum_order_amount'], $promo['currency']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= number_format($promo['usage_count']) ?></strong>
                                <?php if ($promo['usage_limit']): ?>
                                / <?= number_format($promo['usage_limit']) ?>
                                <?php else: ?>
                                / ∞
                                <?php endif; ?>
                                
                                <br><small class="text-muted">
                                    <?= ucfirst(str_replace('_', ' ', $promo['applicable_to'])) ?>
                                </small>
                            </td>
                            <td>
                                <strong><?= date('d/m/Y', strtotime($promo['valid_from'])) ?></strong><br>
                                to<br>
                                <strong><?= date('d/m/Y', strtotime($promo['valid_until'])) ?></strong>
                                
                                <?php if (strtotime($promo['valid_until']) < time()): ?>
                                <br><small class="text-danger">Expired</small>
                                <?php endif; ?>
                            </td>
                            <td><?= getStatusBadge($promo) ?></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-primary" onclick="viewPromoDetails(<?= $promo['id'] ?>)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="promo_id" value="<?= $promo['id'] ?>">
                                        <button type="submit" class="btn btn-outline-<?= $promo['active'] ? 'warning' : 'success' ?>" 
                                                title="<?= $promo['active'] ? 'Disable' : 'Enable' ?>">
                                            <i class="bi bi-<?= $promo['active'] ? 'pause' : 'play' ?>"></i>
                                        </button>
                                    </form>
                                    <?php if ($promo['usage_count'] == 0): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this promo code?')">
                                        <input type="hidden" name="action" value="delete_promo">
                                        <input type="hidden" name="promo_id" value="<?= $promo['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav aria-label="Promo codes pagination" class="mt-4">
        <ul class="pagination justify-content-center">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<!-- Create Promo Code Modal -->
<div class="modal fade" id="createPromoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle me-2"></i>
                    Create New Promo Code
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_promo">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Promo Code *</label>
                            <input type="text" name="code" class="form-control-enhanced" required 
                                   placeholder="SAVE20" style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Display Name *</label>
                            <input type="text" name="name" class="form-control-enhanced" required 
                                   placeholder="20% Summer Discount">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control-enhanced" rows="2" 
                                      placeholder="Brief description of the discount"></textarea>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Discount Type *</label>
                            <select name="discount_type" class="form-control-enhanced" required onchange="toggleDiscountFields()">
                                <option value="percentage">Percentage</option>
                                <option value="fixed_amount">Fixed Amount</option>
                                <option value="free_shipping">Free Shipping</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Discount Value *</label>
                            <input type="number" name="discount_value" class="form-control-enhanced" 
                                   step="0.01" min="0" required placeholder="20">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Currency</label>
                            <select name="currency" class="form-control-enhanced">
                                <option value="GBP">GBP (£)</option>
                                <option value="EUR">EUR (€)</option>
                                <option value="USD">USD ($)</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Maximum Discount Amount</label>
                            <input type="number" name="max_discount_amount" class="form-control-enhanced" 
                                   step="0.01" min="0" placeholder="Optional cap">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Minimum Order Amount</label>
                            <input type="number" name="minimum_order_amount" class="form-control-enhanced" 
                                   step="0.01" min="0" value="0" placeholder="0">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Valid From *</label>
                            <input type="datetime-local" name="valid_from" class="form-control-enhanced" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Valid Until *</label>
                            <input type="datetime-local" name="valid_until" class="form-control-enhanced" required>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Total Usage Limit</label>
                            <input type="number" name="usage_limit" class="form-control-enhanced" 
                                   min="1" placeholder="Unlimited">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Per Customer Limit</label>
                            <input type="number" name="usage_limit_per_customer" class="form-control-enhanced" 
                                   min="1" value="1" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Applicable To</label>
                            <select name="applicable_to" class="form-control-enhanced">
                                <option value="all">All Customers</option>
                                <option value="new_customers">New Customers Only</option>
                                <option value="existing_customers">Existing Customers Only</option>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <div class="form-check">
                                <input type="checkbox" name="is_recurring" class="form-check-input" 
                                       onchange="toggleRecurringFields()">
                                <label class="form-check-label">
                                    <strong>Recurring Discount</strong> (applies to multiple billing cycles)
                                </label>
                            </div>
                        </div>
                        
                        <div id="recurring-fields" style="display: none;">
                            <div class="col-md-6">
                                <label class="form-label">Number of Months</label>
                                <input type="number" name="recurring_months" class="form-control-enhanced" 
                                       min="1" placeholder="3">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Apply To Billing Cycles</label>
                                <select name="recurring_billing_cycles" class="form-control-enhanced">
                                    <option value="all">All Billing Cycles</option>
                                    <option value="monthly">Monthly Only</option>
                                    <option value="quarterly">Quarterly Only</option>
                                    <option value="annually">Annual Only</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success-enhanced btn-enhanced">
                        <i class="bi bi-check"></i>
                        Create Promo Code
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleRecurringFields() {
    const checkbox = document.querySelector('input[name="is_recurring"]');
    const fields = document.getElementById('recurring-fields');
    
    if (checkbox.checked) {
        fields.style.display = 'block';
        fields.querySelectorAll('input').forEach(input => input.required = true);
    } else {
        fields.style.display = 'none';
        fields.querySelectorAll('input').forEach(input => input.required = false);
    }
}

function toggleDiscountFields() {
    const type = document.querySelector('select[name="discount_type"]').value;
    const valueField = document.querySelector('input[name="discount_value"]');
    const maxField = document.querySelector('input[name="max_discount_amount"]');
    
    if (type === 'percentage') {
        valueField.placeholder = '20';
        valueField.max = '100';
        maxField.parentElement.style.display = 'block';
    } else if (type === 'fixed_amount') {
        valueField.placeholder = '50';
        valueField.removeAttribute('max');
        maxField.parentElement.style.display = 'none';
    } else if (type === 'free_shipping') {
        valueField.value = '0';
        valueField.style.display = 'none';
        maxField.parentElement.style.display = 'none';
    }
}

function filterPromoCodes() {
    const params = new URLSearchParams(window.location.search);
    
    params.set('status', document.querySelector('select[onchange="filterPromoCodes()"]').value);
    params.set('type', document.querySelectorAll('select[onchange="filterPromoCodes()"]')[1].value);
    params.set('search', document.querySelector('input[onchange="filterPromoCodes()"]').value);
    params.set('page', '1');
    
    window.location.href = '?' + params.toString();
}

// Set default dates
document.addEventListener('DOMContentLoaded', function() {
    const now = new Date();
    const oneYear = new Date();
    oneYear.setFullYear(oneYear.getFullYear() + 1);
    
    const formatDate = (date) => {
        return date.toISOString().slice(0, 16);
    };
    
    document.querySelector('input[name="valid_from"]').value = formatDate(now);
    document.querySelector('input[name="valid_until"]').value = formatDate(oneYear);
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>
