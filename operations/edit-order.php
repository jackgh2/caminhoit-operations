<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Access control (Staff and Admin only)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'staff'])) {
    header('Location: /login.php');
    exit;
}

$order_id = (int)($_GET['id'] ?? 0);

if (!$order_id) {
    header('Location: orders.php');
    exit;
}

// Get order details
$stmt = $pdo->prepare("SELECT o.*, c.name as company_name, c.preferred_currency, c.currency_override FROM orders o JOIN companies c ON o.company_id = c.id WHERE o.id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: orders.php?error=order_not_found');
    exit;
}

// Allow editing of all orders (removed status restriction)
// Previously only 'draft' and 'placed' orders could be edited
// Now any order can be edited for maximum flexibility

// Define all possible order statuses
$order_statuses = [
    'draft' => 'Draft',
    'placed' => 'Placed',
    'pending_payment' => 'Pending Payment',
    'paid' => 'Paid',
    'processing' => 'Processing',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'refunded' => 'Refunded',
    'on_hold' => 'On Hold',
    'failed' => 'Failed'
];

// Get supported currencies and default currency
$supportedCurrencies = [];
$defaultCurrency = 'GBP';
$exchangeRates = [];
$vatSettings = [];

if (class_exists('ConfigManager')) {
    $supportedCurrencies = ConfigManager::getSupportedCurrencies();
    $defaultCurrency = ConfigManager::get('business.default_currency', 'GBP');
    $exchangeRates = ConfigManager::getExchangeRates();
    
    // Get VAT settings from system config
    $vatSettings = [
        'enabled' => ConfigManager::isVatRegistered(),
        'default_rate' => ConfigManager::get('tax.default_vat_rate', 0.20),
        'currency_settings' => ConfigManager::get('tax.currency_vat_settings', [
            'GBP' => ['enabled' => true, 'rate' => 0.20],
            'USD' => ['enabled' => false, 'rate' => 0.00],
            'EUR' => ['enabled' => true, 'rate' => 0.20],
            'CAD' => ['enabled' => false, 'rate' => 0.00],
            'AUD' => ['enabled' => false, 'rate' => 0.00]
        ])
    ];
} else {
    // Fallback when ConfigManager is not available
    $supportedCurrencies = [
        'GBP' => ['symbol' => '£', 'name' => 'British Pound'],
        'USD' => ['symbol' => '$', 'name' => 'US Dollar'],
        'EUR' => ['symbol' => '€', 'name' => 'Euro'],
        'CAD' => ['symbol' => 'C$', 'name' => 'Canadian Dollar'],
        'AUD' => ['symbol' => 'A$', 'name' => 'Australian Dollar']
    ];
    $exchangeRates = [
        'GBP' => 1.0,
        'USD' => 1.27,
        'EUR' => 1.16,
        'CAD' => 1.71,
        'AUD' => 1.91
    ];
    
    // Fallback VAT settings - check system_config table directly
    try {
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'tax.vat_enabled'");
        $stmt->execute();
        $vat_enabled_config = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'tax.default_vat_rate'");
        $stmt->execute();
        $vat_rate_config = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'tax.currency_vat_settings'");
        $stmt->execute();
        $currency_vat_config = $stmt->fetchColumn();
        
        $vatSettings = [
            'enabled' => $vat_enabled_config === '1' || $vat_enabled_config === 'true',
            'default_rate' => $vat_rate_config ? (float)$vat_rate_config : 0.20,
            'currency_settings' => $currency_vat_config ? json_decode($currency_vat_config, true) : [
                'GBP' => ['enabled' => true, 'rate' => 0.20],
                'USD' => ['enabled' => false, 'rate' => 0.00],
                'EUR' => ['enabled' => true, 'rate' => 0.20],
                'CAD' => ['enabled' => false, 'rate' => 0.00],
                'AUD' => ['enabled' => false, 'rate' => 0.00]
            ]
        ];
    } catch (PDOException $e) {
        // If system_config table doesn't exist, use defaults
        $vatSettings = [
            'enabled' => false,
            'default_rate' => 0.00,
            'currency_settings' => [
                'GBP' => ['enabled' => false, 'rate' => 0.00],
                'USD' => ['enabled' => false, 'rate' => 0.00],
                'EUR' => ['enabled' => false, 'rate' => 0.00],
                'CAD' => ['enabled' => false, 'rate' => 0.00],
                'AUD' => ['enabled' => false, 'rate' => 0.00]
            ]
        ];
    }
}

// Handle order update
if (isset($_POST['update_order'])) {
    $company_id = (int)$_POST['company_id'];
    $order_type = $_POST['order_type'];
    $billing_cycle = $_POST['billing_cycle'];
    $start_date = $_POST['start_date'];
    $notes = trim($_POST['notes']);
    $items = json_decode($_POST['order_items'], true);
    $order_currency = $_POST['order_currency'] ?? $defaultCurrency;
    $new_status = $_POST['order_status'] ?? $order['status']; // Allow status change
    $place_immediately = isset($_POST['place_immediately']);

    if (empty($items)) {
        $error = "Please add at least one item to the order.";
    } else {
        try {
            $pdo->beginTransaction();

            // Calculate totals in the order currency
            $subtotal = 0;
            $total_setup_fees = 0;
            foreach ($items as $item) {
                $subtotal += $item['line_total'];
                $total_setup_fees += $item['setup_fee'] * $item['quantity'];
            }

            // Apply VAT rate based on system configuration
            $vat_rate = 0.0;
            $vat_enabled = false;
            
            // Check if VAT is enabled globally
            if (isset($vatSettings['enabled']) && $vatSettings['enabled']) {
                // Check if VAT is enabled for this specific currency
                $currencyVatSettings = $vatSettings['currency_settings'][$order_currency] ?? ['enabled' => false, 'rate' => 0];
                if (isset($currencyVatSettings['enabled']) && $currencyVatSettings['enabled']) {
                    $vat_rate = (float)($currencyVatSettings['rate'] ?? 0);
                    $vat_enabled = true;
                }
            }

            // Convert boolean to integer for database
            $vat_enabled_int = $vat_enabled ? 1 : 0;

            // Calculate tax amount
            $tax_amount = $vat_enabled ? ($subtotal * $vat_rate) : 0.0;
            $total_amount = $subtotal + $total_setup_fees + $tax_amount;

            // Handle placed_at timestamp based on status changes
            $placed_at_update = '';
            if ($new_status === 'placed' && $order['status'] !== 'placed' && !$order['placed_at']) {
                $placed_at_update = ', placed_at = NOW()';
            } elseif ($place_immediately && $order['status'] === 'draft') {
                $new_status = 'placed';
                $placed_at_update = ', placed_at = NOW()';
            }

            // Update order with currency information and new status
            $stmt = $pdo->prepare("UPDATE orders SET company_id = ?, order_type = ?, subtotal = ?, tax_amount = ?, total_amount = ?, currency = ?, customer_currency = ?, vat_rate = ?, vat_enabled = ?, notes = ?, billing_cycle = ?, start_date = ?, status = ?, updated_at = NOW() $placed_at_update WHERE id = ?");
            $stmt->execute([
                $company_id, 
                $order_type, 
                $subtotal, 
                $tax_amount, 
                $total_amount, 
                $order_currency, 
                $order_currency, 
                $vat_rate, 
                $vat_enabled_int,
                $notes, 
                $billing_cycle, 
                $start_date, 
                $new_status, 
                $order_id
            ]);

            // Delete existing order items
            $stmt = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
            $stmt->execute([$order_id]);

            // Add updated order items with currency
            foreach ($items as $item) {
                $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, bundle_id, item_type, name, description, quantity, unit_price, setup_fee, line_total, billing_cycle, currency) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $order_id,
                    $item['product_id'] ?? null,
                    $item['bundle_id'] ?? null,
                    $item['item_type'],
                    $item['name'],
                    $item['description'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['setup_fee'],
                    $item['line_total'],
                    $item['billing_cycle'],
                    $order_currency
                ]);
            }

            // Log currency conversion if applied
            if (class_exists('ConfigManager') && $order_currency !== $defaultCurrency) {
                $conversion_rate = $exchangeRates[$order_currency] ?? 1;
                ConfigManager::logCurrencyConversion(
                    $defaultCurrency,
                    $order_currency,
                    $conversion_rate,
                    $total_amount / $conversion_rate,
                    $total_amount,
                    'order_update',
                    $order_id,
                    $_SESSION['user']['id']
                );
            }

            // Log status change if status was changed
            if ($new_status !== $order['status']) {
                $stmt = $pdo->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by, notes) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$order_id, $order['status'], $new_status, $_SESSION['user']['id'], 'Status changed during order update']);
            }

            // Log status change if order was placed via button
            if ($place_immediately && $order['status'] === 'draft') {
                $stmt = $pdo->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by, notes) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$order_id, 'draft', 'placed', $_SESSION['user']['id'], 'Order updated and placed']);
            }

            // Log order update
            $status_note = 'Order updated (Currency: ' . $order_currency . ')';
            if ($vat_enabled) {
                $status_note .= ' (VAT: ' . ($vat_rate * 100) . '%)';
            } else {
                $status_note .= ' (VAT: Disabled)';
            }
            if ($new_status !== $order['status']) {
                $status_note .= ' (Status: ' . $order['status'] . ' → ' . $new_status . ')';
            }
            
            $stmt = $pdo->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$order_id, $order['status'], $new_status, $_SESSION['user']['id'], $status_note]);

            $pdo->commit();

            $success_message = ($place_immediately && $order['status'] === 'draft') ? 'order_updated_and_placed' : 'order_updated';
            header("Location: view-order.php?id=$order_id&success=$success_message");
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error updating order: " . $e->getMessage();
        }
    }
}

// Get existing order items
$stmt = $pdo->prepare("SELECT oi.*, p.name as product_name, b.name as bundle_name FROM order_items oi LEFT JOIN products p ON oi.product_id = p.id LEFT JOIN service_bundles b ON oi.bundle_id = b.id WHERE oi.order_id = ?");
$stmt->execute([$order_id]);
$existing_items = $stmt->fetchAll();

// Get companies with currency information
$stmt = $pdo->query("SELECT id, name, preferred_currency, currency_override FROM companies ORDER BY name ASC");
$companies = $stmt->fetchAll();

// Get products
$stmt = $pdo->query("SELECT p.*, c.name as category_name FROM products p JOIN service_categories c ON p.category_id = c.id WHERE p.is_active = 1 ORDER BY c.sort_order ASC, p.name ASC");
$products = $stmt->fetchAll();

// Get bundles
$stmt = $pdo->query("SELECT * FROM service_bundles WHERE is_active = 1 ORDER BY name ASC");
$bundles = $stmt->fetchAll();

$page_title = "Edit Order #" . $order['order_number'] . " | CaminhoIT";
?>
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

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="orders.php">Orders</a></li>
                <li class="breadcrumb-item"><a href="view-order.php?id=<?= $order['id'] ?>">Order #<?= htmlspecialchars($order['order_number']) ?></a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-pencil-square me-3"></i>Edit Order #<?= htmlspecialchars($order['order_number']) ?></h1>
                <p class="text-muted mb-0">
                    For <?= htmlspecialchars($order['company_name']) ?> • 
                    <span class="status-badge status-<?= $order['status'] ?>">
                        <?= ucfirst(str_replace('_', ' ', $order['status'])) ?>
                    </span>
                </p>
            </div>
            <div>
                <a href="view-order.php?id=<?= $order['id'] ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Order
                </a>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i><?= $error ?>
        </div>
    <?php endif; ?>

    <!-- Status-specific alerts -->
    <?php if ($order['status'] === 'draft'): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Draft Order:</strong> This order has not been placed yet. You can modify all details and place it when ready.
        </div>
    <?php elseif ($order['status'] === 'completed'): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            <strong>Completed Order:</strong> This order has been completed. Changes should be made with caution.
        </div>
    <?php elseif ($order['status'] === 'cancelled'): ?>
        <div class="alert alert-danger">
            <i class="bi bi-x-circle me-2"></i>
            <strong>Cancelled Order:</strong> This order has been cancelled. You can still edit it if needed.
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Active Order:</strong> This order is currently <?= ucfirst(str_replace('_', ' ', $order['status'])) ?>. Changes may affect processing.
        </div>
    <?php endif; ?>

    <form method="POST" id="orderForm">
        <div class="row">
            <div class="col-md-8">
                <!-- Order Details -->
                <div class="form-card">
                    <h5 class="mb-3">Order Details</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Company *</label>
                                <select name="company_id" class="form-select" required onchange="updateCompanyCurrency()">
                                    <option value="">Select Company</option>
                                    <?php foreach ($companies as $company): ?>
                                        <option value="<?= $company['id'] ?>" 
                                                <?= $company['id'] == $order['company_id'] ? 'selected' : '' ?>
                                                data-currency="<?= $company['preferred_currency'] ?>"
                                                data-currency-override="<?= $company['currency_override'] ?>">
                                            <?= htmlspecialchars($company['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Order Type *</label>
                                <select name="order_type" class="form-select" required>
                                    <option value="">Select Type</option>
                                    <option value="new" <?= $order['order_type'] === 'new' ? 'selected' : '' ?>>New Service</option>
                                    <option value="upgrade" <?= $order['order_type'] === 'upgrade' ? 'selected' : '' ?>>Upgrade</option>
                                    <option value="addon" <?= $order['order_type'] === 'addon' ? 'selected' : '' ?>>Add-on</option>
                                    <option value="renewal" <?= $order['order_type'] === 'renewal' ? 'selected' : '' ?>>Renewal</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Billing Cycle *</label>
                                <select name="billing_cycle" class="form-select" required>
                                    <option value="">Select Cycle</option>
                                    <option value="monthly" <?= $order['billing_cycle'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                    <option value="quarterly" <?= $order['billing_cycle'] === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                                    <option value="annually" <?= $order['billing_cycle'] === 'annually' ? 'selected' : '' ?>>Annually</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Start Date *</label>
                                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($order['start_date']) ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes about this order"><?= htmlspecialchars($order['notes']) ?></textarea>
                    </div>
                </div>

                <!-- Order Status Section -->
                <div class="form-card">
                    <h5 class="mb-3">Order Status</h5>
                    <div class="status-section">
                        <h6><i class="bi bi-flag me-2"></i>Current Status Management</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Order Status *</label>
                                    <select name="order_status" class="form-select" required onchange="updateStatusWarning()">
                                        <?php foreach ($order_statuses as $status_key => $status_name): ?>
                                            <option value="<?= $status_key ?>" <?= $order['status'] === $status_key ? 'selected' : '' ?>>
                                                <?= $status_name ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Current Status</label>
                                    <div class="form-control bg-light">
                                        <span class="status-badge status-<?= $order['status'] ?>">
                                            <?= $order_statuses[$order['status']] ?? ucfirst(str_replace('_', ' ', $order['status'])) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div id="statusWarning" class="alert alert-warning" style="display: none;">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Status Change Warning:</strong> <span id="statusWarningText"></span>
                        </div>
                    </div>
                </div>

                <!-- Service Catalog -->
                <div class="form-card">
                    <h5 class="mb-3">Add Services</h5>
                    
                    <ul class="nav nav-tabs" id="catalogTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="products-tab" data-bs-toggle="tab" data-bs-target="#products" type="button" role="tab">
                                <i class="bi bi-box me-2"></i>Products
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="bundles-tab" data-bs-toggle="tab" data-bs-target="#bundles" type="button" role="tab">
                                <i class="bi bi-collection me-2"></i>Bundles
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="catalogTabsContent">
                        <!-- Products Tab -->
                        <div class="tab-pane fade show active" id="products" role="tabpanel">
                            <div class="catalog-grid">
                                <?php foreach ($products as $product): ?>
                                    <div class="product-card" onclick="selectProduct(<?= $product['id'] ?>, 'product')"
                                         data-base-price="<?= $product['base_price'] ?>" 
                                         data-setup-fee="<?= $product['setup_fee'] ?? 0 ?>">
                                        <div class="currency-converted" style="display: none;">Converted</div>
                                        <h6><?= htmlspecialchars($product['name']) ?></h6>
                                        <p class="text-muted small mb-2"><?= htmlspecialchars($product['short_description']) ?></p>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="badge badge-primary"><?= htmlspecialchars($product['category_name']) ?></span>
                                                <?php if ($product['is_featured']): ?>
                                                    <span class="badge badge-featured">Featured</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-end">
                                                <strong><span class="currency-symbol">£</span><span class="price-amount"><?= number_format($product['base_price'], 2) ?></span></strong>
                                                <small class="text-muted d-block">/<?= str_replace('_', ' ', $product['unit_type']) ?></small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Bundles Tab -->
                        <div class="tab-pane fade" id="bundles" role="tabpanel">
                            <div class="catalog-grid">
                                <?php foreach ($bundles as $bundle): ?>
                                    <div class="product-card" onclick="selectProduct(<?= $bundle['id'] ?>, 'bundle')"
                                         data-base-price="<?= $bundle['bundle_price'] ?>" 
                                         data-setup-fee="0">
                                        <div class="currency-converted" style="display: none;">Converted</div>
                                        <h6><?= htmlspecialchars($bundle['name']) ?></h6>
                                        <p class="text-muted small mb-2"><?= htmlspecialchars($bundle['short_description']) ?></p>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="badge badge-warning"><?= htmlspecialchars($bundle['target_audience']) ?></span>
                                                <?php if ($bundle['is_featured']): ?>
                                                    <span class="badge badge-featured">Featured</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-end">
                                                <strong><span class="currency-symbol">£</span><span class="price-amount"><?= number_format($bundle['bundle_price'], 2) ?></span></strong>
                                                <small class="text-muted d-block">/<?= $bundle['billing_cycle'] ?></small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Items -->
                <div class="order-items">
                    <h5 class="mb-3">Order Items</h5>
                    <div id="orderItemsList">
                        <!-- Items will be populated by JavaScript -->
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Currency Information -->
                <div class="currency-info" id="currencyInfo">
                    <h6 class="mb-2"><i class="bi bi-currency-exchange me-2"></i>Currency Information</h6>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>Order Currency:</span>
                        <span class="currency-badge" id="orderCurrencyBadge"><?= $order['currency'] ?? $defaultCurrency ?></span>
                    </div>
                    <small class="text-muted d-block" id="currencyNote">
                        Current order currency
                    </small>
                    <div class="mt-2" id="exchangeRateInfo" style="display: none;">
                        <small class="text-muted">
                            Exchange Rate: 1 <?= $defaultCurrency ?> = <span id="exchangeRateValue">1.00</span> <span id="targetCurrencyCode"><?= $order['currency'] ?? $defaultCurrency ?></span>
                        </small>
                    </div>
                </div>

                <!-- VAT Information -->
                <div class="vat-info" id="vatInfo">
                    <div class="d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-receipt me-2"></i>VAT Status:</span>
                        <span id="vatStatus">Enabled (20%)</span>
                    </div>
                    <small class="text-muted mt-1 d-block" id="vatNote">
                        VAT will be applied to this order
                    </small>
                </div>

                <!-- Workflow Indicator -->
                <div class="workflow-indicator">
                    <h6 class="mb-2">Order Workflow</h6>
                    <div class="workflow-steps">
                        <div class="workflow-step <?= in_array($order['status'], ['draft']) ? 'active' : '' ?>">
                            <i class="bi bi-pencil-square d-block mb-1"></i>
                            <small>Draft</small>
                        </div>
                        <div class="workflow-arrow">→</div>
                        <div class="workflow-step <?= in_array($order['status'], ['placed', 'pending_payment']) ? 'active' : '' ?>">
                            <i class="bi bi-send d-block mb-1"></i>
                            <small>Processing</small>
                        </div>
                        <div class="workflow-arrow">→</div>
                        <div class="workflow-step <?= in_array($order['status'], ['paid', 'processing']) ? 'active' : '' ?>">
                            <i class="bi bi-credit-card d-block mb-1"></i>
                            <small>Payment</small>
                        </div>
                        <div class="workflow-arrow">→</div>
                        <div class="workflow-step <?= in_array($order['status'], ['completed']) ? 'active' : '' ?>">
                            <i class="bi bi-check-circle d-block mb-1"></i>
                            <small>Complete</small>
                        </div>
                    </div>
                </div>

                <!-- Order Summary -->
                <div class="order-summary">
                    <h5 class="mb-3">Order Summary</h5>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Items:</span>
                        <span id="itemCount">0</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal:</span>
                        <span id="subtotal"><span class="currency-symbol">£</span>0.00</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Setup Fees:</span>
                        <span id="setupFees"><span class="currency-symbol">£</span>0.00</span>
                    </div>
                    <div class="vat-row" id="vatRow">
                        <span>VAT (<span id="vatPercent">20</span>%):</span>
                        <span id="vat"><span class="currency-symbol">£</span>0.00</span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between mb-3">
                        <strong>Total:</strong>
                        <strong id="total"><span class="currency-symbol">£</span>0.00</strong>
                    </div>
                    
                    <div class="alert alert-info small mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        Changes will be saved to this order and status will be updated if changed.
                    </div>
                    
                    <input type="hidden" name="order_items" id="orderItemsInput">
                    <input type="hidden" name="order_currency" id="orderCurrencyInput" value="<?= $order['currency'] ?? $defaultCurrency ?>">
                    <div class="d-grid gap-2">
                        <button type="submit" name="update_order" class="btn btn-outline-primary">
                            <i class="bi bi-save me-2"></i>Update Order
                        </button>
                        <?php if ($order['status'] === 'draft'): ?>
                            <button type="submit" name="update_order" class="btn btn-primary" onclick="document.querySelector('input[name=place_immediately]').value='1'">
                                <i class="bi bi-cart-check me-2"></i>Update & Place Order
                            </button>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="place_immediately" value="0">
                </div>
            </div>
        </div>
    </form>
</div>

<script>
const products = <?= json_encode($products) ?>;
const bundles = <?= json_encode($bundles) ?>;
const existingItems = <?= json_encode($existing_items) ?>;
const supportedCurrencies = <?= json_encode($supportedCurrencies) ?>;
const defaultCurrency = '<?= $defaultCurrency ?>';
const exchangeRates = <?= json_encode($exchangeRates) ?>;
const vatSettings = <?= json_encode($vatSettings) ?>;
const initialOrderCurrency = '<?= $order['currency'] ?? $defaultCurrency ?>';
const currentOrderStatus = '<?= $order['status'] ?>';
let orderItems = [];
let currentCurrency = initialOrderCurrency;
let currentCurrencySymbol = supportedCurrencies[currentCurrency] ? supportedCurrencies[currentCurrency].symbol : '€';
let currentExchangeRate = exchangeRates[currentCurrency] || 1.0;
let currentVatRate = 0.20;
let vatEnabled = false; // Default to false

// Debug logging
console.log('Debug - Existing items:', existingItems);
console.log('Debug - VAT Settings:', vatSettings);
console.log('Debug - Current Currency:', currentCurrency);

// Status change warnings
const statusWarnings = {
    'draft': 'Setting status to Draft will mark this order as not yet placed.',
    'placed': 'Setting status to Placed will mark this order as ready for processing.',
    'pending_payment': 'Setting status to Pending Payment indicates payment is expected.',
    'paid': 'Setting status to Paid indicates payment has been received.',
    'processing': 'Setting status to Processing indicates work has begun.',
    'completed': 'Setting status to Completed indicates the order is finished.',
    'cancelled': 'Setting status to Cancelled will mark this order as cancelled.',
    'refunded': 'Setting status to Refunded indicates payment has been returned.',
    'on_hold': 'Setting status to On Hold will pause order processing.',
    'failed': 'Setting status to Failed indicates the order could not be completed.'
};

function updateStatusWarning() {
    const statusSelect = document.querySelector('select[name="order_status"]');
    const selectedStatus = statusSelect.value;
    const warningDiv = document.getElementById('statusWarning');
    const warningText = document.getElementById('statusWarningText');
    
    if (selectedStatus !== currentOrderStatus) {
        warningDiv.style.display = 'block';
        warningText.textContent = statusWarnings[selectedStatus] || 'Status will be changed.';
    } else {
        warningDiv.style.display = 'none';
    }
}

// Initialize with existing items - FIXED VERSION
function initializeExistingItems() {
    console.log('Initializing existing items...', existingItems);
    
    // Clear existing items first
    orderItems = [];
    
    // Load existing items from database
    if (existingItems && existingItems.length > 0) {
        existingItems.forEach(item => {
            console.log('Adding item:', item);
            orderItems.push({
                product_id: item.product_id,
                bundle_id: item.bundle_id,
                item_type: item.item_type,
                name: item.name,
                description: item.description,
                quantity: parseInt(item.quantity),
                unit_price: parseFloat(item.unit_price),
                setup_fee: parseFloat(item.setup_fee || 0),
                line_total: parseFloat(item.line_total),
                billing_cycle: item.billing_cycle,
                base_price: parseFloat(item.unit_price), // Store current price as base for conversion
                base_setup_fee: parseFloat(item.setup_fee || 0)
            });
        });
    }
    
    // Initialize currency display
    updateCurrencyDisplay();
    updateVatSettings();
    updateOrderDisplay();
    
    console.log('Initialized with', orderItems.length, 'items');
}

function updateCompanyCurrency() {
    const companySelect = document.querySelector('select[name="company_id"]');
    const selectedOption = companySelect.options[companySelect.selectedIndex];
    const currencyBadge = document.getElementById('orderCurrencyBadge');
    const currencyNote = document.getElementById('currencyNote');
    const exchangeRateInfo = document.getElementById('exchangeRateInfo');
    const exchangeRateValue = document.getElementById('exchangeRateValue');
    const targetCurrencyCode = document.getElementById('targetCurrencyCode');
    
    // Store the previous currency for conversion
    const previousCurrency = currentCurrency;
    const previousExchangeRate = currentExchangeRate;
    
    if (selectedOption.value) {
        const companyCurrency = selectedOption.dataset.currency;
        const currencyOverride = selectedOption.dataset.currencyOverride === '1';
        
        if (currencyOverride && companyCurrency && supportedCurrencies[companyCurrency]) {
            currentCurrency = companyCurrency;
            currentCurrencySymbol = supportedCurrencies[companyCurrency].symbol;
            currentExchangeRate = exchangeRates[companyCurrency] || 1.0;
            
            currencyBadge.textContent = companyCurrency;
            currencyBadge.className = 'currency-badge';
            currencyNote.textContent = `Using company's preferred currency (${supportedCurrencies[companyCurrency].name})`;
            
            // Show exchange rate info if converting
            if (companyCurrency !== defaultCurrency) {
                exchangeRateInfo.style.display = 'block';
                exchangeRateValue.textContent = currentExchangeRate.toFixed(4);
                targetCurrencyCode.textContent = companyCurrency;
            } else {
                exchangeRateInfo.style.display = 'none';
            }
        } else {
            currentCurrency = defaultCurrency;
            currentCurrencySymbol = supportedCurrencies[defaultCurrency] ? supportedCurrencies[defaultCurrency].symbol : '£';
            currentExchangeRate = 1.0;
            
            currencyBadge.textContent = defaultCurrency;
            currencyBadge.className = 'currency-badge default';
            currencyNote.textContent = 'Using system default currency';
            exchangeRateInfo.style.display = 'none';
        }
        
        updateVatSettings();
        
        // Convert existing cart items to new currency
        if (previousCurrency !== currentCurrency && orderItems.length > 0) {
            console.log(`Converting cart from ${previousCurrency} to ${currentCurrency}`);
            convertExistingCartItems(previousCurrency, currentCurrency, previousExchangeRate, currentExchangeRate);
        }
        
        updateCurrencyDisplay();
    }
    
    // Update hidden input
    document.getElementById('orderCurrencyInput').value = currentCurrency;
}

function convertExistingCartItems(fromCurrency, toCurrency, fromRate, toRate) {
    if (orderItems.length === 0) return;
    
    console.log(`Converting ${orderItems.length} cart items from ${fromCurrency} to ${toCurrency}`);
    
    orderItems.forEach((item, index) => {
        // Use base prices if available for accurate conversion
        if (item.base_price !== undefined && item.base_setup_fee !== undefined) {
            // Convert from base currency to target currency
            if (toCurrency === defaultCurrency) {
                item.unit_price = item.base_price;
                item.setup_fee = item.base_setup_fee;
            } else {
                item.unit_price = item.base_price * toRate;
                item.setup_fee = item.base_setup_fee * toRate;
            }
        } else {
            // Fallback to rate-based conversion
            let conversionFactor;
            
            if (fromCurrency === defaultCurrency && toCurrency !== defaultCurrency) {
                conversionFactor = toRate;
            } else if (fromCurrency !== defaultCurrency && toCurrency === defaultCurrency) {
                conversionFactor = 1 / fromRate;
            } else if (fromCurrency !== defaultCurrency && toCurrency !== defaultCurrency) {
                conversionFactor = toRate / fromRate;
            } else {
                conversionFactor = 1;
            }
            
            item.unit_price = item.unit_price * conversionFactor;
            item.setup_fee = item.setup_fee * conversionFactor;
        }
        
        // Recalculate line total
        item.line_total = item.unit_price * item.quantity;
        
        console.log(`Converted ${item.name}: ${item.unit_price.toFixed(2)} ${toCurrency}`);
    });
    
    // Update the display
    updateOrderDisplay();
}

// FIXED VAT SETTINGS FUNCTION
function updateVatSettings() {
    const vatInfo = document.getElementById('vatInfo');
    const vatStatus = document.getElementById('vatStatus');
    const vatNote = document.getElementById('vatNote');
    const vatRow = document.getElementById('vatRow');
    const vatPercent = document.getElementById('vatPercent');
    
    console.log('Updating VAT settings for currency:', currentCurrency);
    console.log('VAT Settings:', vatSettings);
    
    // Reset defaults
    vatEnabled = false;
    currentVatRate = 0;
    
    // Check if VAT is enabled globally first
    if (vatSettings && vatSettings.enabled) {
        console.log('VAT is globally enabled');
        
        // Check if VAT is enabled for this specific currency
        if (vatSettings.currency_settings && vatSettings.currency_settings[currentCurrency]) {
            const currencyVat = vatSettings.currency_settings[currentCurrency];
            console.log('Currency VAT settings:', currencyVat);
            
            if (currencyVat.enabled) {
                vatEnabled = true;
                currentVatRate = parseFloat(currencyVat.rate) || 0;
                
                vatInfo.className = 'vat-info';
                vatStatus.textContent = `Enabled (${(currentVatRate * 100).toFixed(0)}%)`;
                vatNote.textContent = 'VAT will be applied to this order';
                vatRow.classList.remove('hidden');
                vatPercent.textContent = (currentVatRate * 100).toFixed(0);
                
                console.log('VAT enabled for', currentCurrency, 'at rate', currentVatRate);
            } else {
                vatInfo.className = 'vat-info disabled';
                vatStatus.textContent = 'Not applicable';
                vatNote.textContent = `VAT is not applicable for ${currentCurrency} orders`;
                vatRow.classList.add('hidden');
                
                console.log('VAT disabled for', currentCurrency);
            }
        } else {
            vatInfo.className = 'vat-info disabled';
            vatStatus.textContent = 'Not configured';
            vatNote.textContent = `VAT settings not configured for ${currentCurrency}`;
            vatRow.classList.add('hidden');
            
            console.log('No VAT settings for', currentCurrency);
        }
    } else {
        vatInfo.className = 'vat-info disabled';
        vatStatus.textContent = 'Disabled';
        vatNote.textContent = 'VAT is globally disabled';
        vatRow.classList.add('hidden');
        
        console.log('VAT is globally disabled');
    }
    
    // Update the summary after VAT settings change
    updateSummary();
}

function updateCurrencyDisplay() {
    // Update all currency symbols on the page
    const currencySymbols = document.querySelectorAll('.currency-symbol');
    currencySymbols.forEach(symbol => {
        symbol.textContent = currentCurrencySymbol;
    });
    
    // Update product and bundle prices with conversion
    updateProductPrices();
    
    // Update order display
    updateOrderDisplay();
}

function updateProductPrices() {
    const productCards = document.querySelectorAll('.product-card');
    productCards.forEach(card => {
        const priceElement = card.querySelector('.price-amount');
        const convertedBadge = card.querySelector('.currency-converted');
        
        if (priceElement) {
            const basePrice = parseFloat(card.dataset.basePrice);
            const convertedPrice = basePrice * currentExchangeRate;
            
            priceElement.textContent = convertedPrice.toFixed(2);
            
            // Show/hide conversion badge
            if (currentCurrency !== defaultCurrency) {
                convertedBadge.style.display = 'block';
            } else {
                convertedBadge.style.display = 'none';
            }
        }
    });
}

function selectProduct(id, type) {
    let item;
    
    if (type === 'product') {
        item = products.find(p => p.id == id);
        if (!item) return;
        
        // Check if already in order
        const existingIndex = orderItems.findIndex(oi => oi.product_id == id && oi.item_type === 'product');
        if (existingIndex >= 0) {
            orderItems[existingIndex].quantity++;
            orderItems[existingIndex].line_total = orderItems[existingIndex].unit_price * orderItems[existingIndex].quantity;
        } else {
            // Convert prices to current currency
            const convertedPrice = parseFloat(item.base_price) * currentExchangeRate;
            const convertedSetupFee = parseFloat(item.setup_fee || 0) * currentExchangeRate;
            
            orderItems.push({
                product_id: id,
                bundle_id: null,
                item_type: 'product',
                name: item.name,
                description: item.short_description,
                quantity: 1,
                unit_price: convertedPrice,
                setup_fee: convertedSetupFee,
                line_total: convertedPrice,
                billing_cycle: item.billing_cycle,
                base_price: parseFloat(item.base_price),
                base_setup_fee: parseFloat(item.setup_fee || 0)
            });
        }
    } else if (type === 'bundle') {
        item = bundles.find(b => b.id == id);
        if (!item) return;
        
        // Check if already in order
        const existingIndex = orderItems.findIndex(oi => oi.bundle_id == id && oi.item_type === 'bundle');
        if (existingIndex >= 0) {
            orderItems[existingIndex].quantity++;
            orderItems[existingIndex].line_total = orderItems[existingIndex].unit_price * orderItems[existingIndex].quantity;
        } else {
            // Convert prices to current currency
            const convertedPrice = parseFloat(item.bundle_price) * currentExchangeRate;
            
            orderItems.push({
                product_id: null,
                bundle_id: id,
                item_type: 'bundle',
                name: item.name,
                description: item.short_description,
                quantity: 1,
                unit_price: convertedPrice,
                setup_fee: 0,
                line_total: convertedPrice,
                billing_cycle: item.billing_cycle,
                base_price: parseFloat(item.bundle_price),
                base_setup_fee: 0
            });
        }
    }
    
    updateOrderDisplay();
}

function updateOrderDisplay() {
    const itemsList = document.getElementById('orderItemsList');
    
    console.log('Updating order display with', orderItems.length, 'items');
    
    if (orderItems.length === 0) {
        itemsList.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="bi bi-cart" style="font-size: 2rem;"></i>
                <p class="mt-2">No items in order. Select products or bundles from above.</p>
            </div>
        `;
    } else {
        itemsList.innerHTML = orderItems.map((item, index) => `
            <div class="order-item">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <h6 class="mb-1">${item.name}</h6>
                        <p class="text-muted small mb-2">${item.description}</p>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="changeQuantity(${index}, -1)">-</button>
                            <span class="mx-2">Qty: ${item.quantity}</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="changeQuantity(${index}, 1)">+</button>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="h6 mb-1">${currentCurrencySymbol}${(item.unit_price * item.quantity).toFixed(2)}</div>
                        <small class="text-muted">${currentCurrencySymbol}${item.unit_price.toFixed(2)} each</small>
                        ${item.setup_fee > 0 ? `<br><small class="text-muted">Setup: ${currentCurrencySymbol}${(item.setup_fee * item.quantity).toFixed(2)}</small>` : ''}
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(${index})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `).join('');
    }
    
    updateSummary();
}

function changeQuantity(index, change) {
    orderItems[index].quantity += change;
    if (orderItems[index].quantity <= 0) {
        orderItems.splice(index, 1);
    } else {
        orderItems[index].line_total = orderItems[index].unit_price * orderItems[index].quantity;
    }
    updateOrderDisplay();
}

function removeItem(index) {
    orderItems.splice(index, 1);
    updateOrderDisplay();
}

function updateSummary() {
    const itemCount = orderItems.reduce((sum, item) => sum + item.quantity, 0);
    const subtotal = orderItems.reduce((sum, item) => sum + (item.unit_price * item.quantity), 0);
    const setupFees = orderItems.reduce((sum, item) => sum + (item.setup_fee * item.quantity), 0);
    const vat = vatEnabled ? (subtotal * currentVatRate) : 0;
    const total = subtotal + setupFees + vat;
    
    console.log('Summary update:', {
        itemCount,
        subtotal,
        setupFees,
        vat,
        total,
        vatEnabled,
        currentVatRate
    });
    
    document.getElementById('itemCount').textContent = itemCount;
    document.getElementById('subtotal').innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${subtotal.toFixed(2)}`;
    document.getElementById('setupFees').innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${setupFees.toFixed(2)}`;
    document.getElementById('vat').innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${vat.toFixed(2)}`;
    document.getElementById('total').innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${total.toFixed(2)}`;
    
    // Update hidden input
    document.getElementById('orderItemsInput').value = JSON.stringify(orderItems);
}

// Form validation
document.getElementById('orderForm').addEventListener('submit', function(e) {
    if (orderItems.length === 0) {
        e.preventDefault();
        alert('Please add at least one item to the order.');
        return false;
    }
    
    // Update the hidden input one final time
    document.getElementById('orderItemsInput').value = JSON.stringify(orderItems);
    
    // Check if status is being changed
    const statusSelect = document.querySelector('select[name="order_status"]');
    const selectedStatus = statusSelect.value;
    
    if (selectedStatus !== currentOrderStatus) {
        const statusName = statusSelect.options[statusSelect.selectedIndex].text;
        if (!confirm(`Are you sure you want to change the order status to "${statusName}"?`)) {
            e.preventDefault();
            return false;
        }
    }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing...');
    initializeExistingItems();
    updateStatusWarning();
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>
