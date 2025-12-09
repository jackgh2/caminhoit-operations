<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/DiscordNotifications.php';

// Access control (Staff and Admin only)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'staff'])) {
    header('Location: /login.php');
    exit;
}

// Get supported currencies and default currency
$supportedCurrencies = [];
$defaultCurrency = 'GBP';
$exchangeRates = [];
$vatSettings = [];

if (class_exists('ConfigManager')) {
    $supportedCurrencies = ConfigManager::getSupportedCurrencies();
    $defaultCurrency = ConfigManager::get('business.default_currency', 'GBP');
    $exchangeRates = ConfigManager::getExchangeRates();
    
    // Get VAT settings for each currency
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
}

// Handle order creation (Draft)
if (isset($_POST['create_order'])) {
    $company_id = (int)$_POST['company_id'];
    $order_type = $_POST['order_type'];
    $billing_cycle = $_POST['billing_cycle'];
    $start_date = $_POST['start_date'];
    $notes = trim($_POST['notes']);
    $items = json_decode($_POST['order_items'], true);
    $order_currency = $_POST['order_currency'] ?? $defaultCurrency;
    $place_immediately = isset($_POST['place_immediately']);

    if (empty($items)) {
        $error = "Please add at least one item to the order.";
    } else {
        try {
            $pdo->beginTransaction();

            // Generate order number
            $order_number = 'ORD-' . date('Y') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);

            // Calculate totals in the order currency
            $subtotal = 0;
            $total_setup_fees = 0;
            foreach ($items as $item) {
                $subtotal += $item['line_total'];
                $total_setup_fees += $item['setup_fee'] * $item['quantity'];
            }

            // Apply VAT rate based on currency settings
            $vat_rate = 0;
            $vat_enabled = false;
            if (class_exists('ConfigManager') && $vatSettings['enabled']) {
                $currencyVatSettings = $vatSettings['currency_settings'][$order_currency] ?? ['enabled' => false, 'rate' => 0];
                if ($currencyVatSettings['enabled']) {
                    $vat_rate = $currencyVatSettings['rate'];
                    $vat_enabled = true;
                }
            }

            $tax_amount = $vat_enabled ? ($subtotal * $vat_rate) : 0;
            $total_amount = $subtotal + $total_setup_fees + $tax_amount;

            // Determine initial status
            $initial_status = $place_immediately ? 'pending_payment' : 'draft';
            $payment_status = 'unpaid';
            $placed_at = $place_immediately ? 'NOW()' : 'NULL';

            // Convert boolean to integer for database compatibility
            $vat_enabled_int = $vat_enabled ? 1 : 0;

            // Create order
            $stmt = $pdo->prepare("INSERT INTO orders (order_number, company_id, staff_id, status, payment_status, order_type, subtotal, tax_amount, total_amount, currency, customer_currency, vat_rate, vat_enabled, notes, billing_cycle, start_date, placed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, $placed_at)");
            $stmt->execute([$order_number, $company_id, $_SESSION['user']['id'], $initial_status, $payment_status, $order_type, $subtotal, $tax_amount, $total_amount, $order_currency, $order_currency, $vat_rate, $vat_enabled_int, $notes, $billing_cycle, $start_date]);

            $order_id = $pdo->lastInsertId();

            // Add order items (already in correct currency)
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
                    $total_amount / $conversion_rate, // Original amount in base currency
                    $total_amount,
                    'order',
                    $order_id,
                    $_SESSION['user']['id']
                );
            }

            // Log status change
            $status_note = $place_immediately ? 'Order created and placed immediately' : 'Order created as draft';
            $status_note .= " (Currency: $order_currency)";
            if ($vat_enabled) {
                $status_note .= " (VAT: " . ($vat_rate * 100) . "%)";
            } else {
                $status_note .= " (VAT: Not applicable)";
            }
            
            $stmt = $pdo->prepare("INSERT INTO order_status_history (order_id, status_to, changed_by, notes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$order_id, $initial_status, $_SESSION['user']['id'], $status_note]);

            $pdo->commit();

            // Send Discord notification for staff-created orders
            $discord = new DiscordNotifications($pdo);
            $discord->notifyStaffOrderCreated($order_id);

            // Auto-create invoice for the order if it was placed immediately
            if ($place_immediately) {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/order-invoice-automation.php';
                $invoice_id = autoCreateInvoiceFromOrder($order_id, $pdo);
                if ($invoice_id) {
                    // Send Discord notification for invoice creation
                    $discord->notifyInvoiceCreated($invoice_id);
                    error_log("Auto-created invoice ID {$invoice_id} for order {$order_id}");
                }
            }

            $success_message = $place_immediately ? 'order_created_and_placed' : 'order_created';
            header("Location: view-order.php?id=$order_id&success=$success_message");
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error creating order: " . $e->getMessage();
        }
    }
}

// Get companies with currency information
$stmt = $pdo->query("SELECT id, name, preferred_currency, currency_override FROM companies ORDER BY name ASC");
$companies = $stmt->fetchAll();

// Get products
$stmt = $pdo->query("SELECT p.*, c.name as category_name FROM products p JOIN service_categories c ON p.category_id = c.id WHERE p.is_active = 1 ORDER BY c.sort_order ASC, p.name ASC");
$products = $stmt->fetchAll();

// Get bundles
$stmt = $pdo->query("SELECT * FROM service_bundles WHERE is_active = 1 ORDER BY name ASC");
$bundles = $stmt->fetchAll();

$page_title = "Create Order | CaminhoIT";
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php';

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="dashboard-hero-content">
            <h1 class="dashboard-hero-title">
                <i class="bi bi-cart-plus me-2"></i>
                Create New Order
            </h1>
            <p class="dashboard-hero-subtitle">
                Create and process new customer orders
            </p>
            <div class="dashboard-hero-actions">
                <a href="/operations/orders.php" class="btn c-btn-ghost">
                    <i class="bi bi-arrow-left me-1"></i>
                    Back to Orders
                </a>
            </div>
        </div>
    </div>
</header>
?>
    <style>
        :root {
            --primary-color: #4F46E5;
            --success-color: #10B981;
            --warning-color: #F59E0B;
            --danger-color: #EF4444;
            --info-color: #06B6D4;
        }

        body {
            background-color: #f8fafc;
            padding-top: 80px;
        }

        .navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15) !important;
            position: fixed !important;
            top: 0 !important;
            z-index: 1030 !important;
        }

        .main-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .page-header {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .catalog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .product-card {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .product-card:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-color);
        }

        .product-card.selected {
            border-color: var(--primary-color);
            background: #f0f9ff;
        }

        .currency-converted {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: var(--info-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
        }

        .order-items {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 2rem;
        }

        .order-item {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e2e8f0;
        }

        .order-summary {
            background: white;
            border: 2px solid var(--primary-color);
            border-radius: 12px;
            padding: 2rem;
            position: sticky;
            top: 100px;
        }

        .currency-info {
            background: #f0f9ff;
            border: 1px solid #3b82f6;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .currency-badge {
            background: var(--primary-color);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .currency-badge.default {
            background: #6b7280;
        }

        .vat-info {
            background: #f0fdf4;
            border: 1px solid #10b981;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .vat-info.disabled {
            background: #f3f4f6;
            border-color: #6b7280;
            color: #6b7280;
        }

        .vat-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .vat-row.hidden {
            display: none;
        }

        .form-control, .form-select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0.75rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: #3f37c9;
        }

        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-outline-primary:hover {
            background: var(--primary-color);
            color: white;
        }

        .alert {
            border: none;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .nav-tabs {
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 1rem;
        }

        .nav-tabs .nav-link {
            border: none;
            padding: 1rem 1.5rem;
            font-weight: 500;
            color: #6b7280;
        }

        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            background: none;
        }

        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-primary { background: #dbeafe; color: #1e40af; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-featured { background: #fce7f3; color: #be185d; }

        .workflow-indicator {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .workflow-steps {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
            color: #6b7280;
        }

        .workflow-step {
            text-align: center;
            flex: 1;
        }

        .workflow-step.active {
            color: var(--primary-color);
            font-weight: 600;
        }

        .workflow-arrow {
            color: #d1d5db;
            margin: 0 0.5rem;
        }
    </style>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-plus-circle me-3"></i>Create New Order</h1>
                <p class="text-muted mb-0">Create a new order for a company</p>
            </div>
            <div>
                <a href="orders.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Orders
                </a>
            </div>
        </div>
    </div>

    <!-- Error Messages -->
    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i><?= $error ?>
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
                                    <option value="new">New Service</option>
                                    <option value="upgrade">Upgrade</option>
                                    <option value="addon">Add-on</option>
                                    <option value="renewal">Renewal</option>
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
                                    <option value="monthly">Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="annually">Annually</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Start Date *</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes about this order"></textarea>
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
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-cart" style="font-size: 2rem;"></i>
                            <p class="mt-2">No items added yet. Select products or bundles from above.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Currency Information -->
                <div class="currency-info" id="currencyInfo" style="display: none;">
                    <h6 class="mb-2"><i class="bi bi-currency-exchange me-2"></i>Currency Information</h6>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>Order Currency:</span>
                        <span class="currency-badge" id="orderCurrencyBadge"><?= $defaultCurrency ?></span>
                    </div>
                    <small class="text-muted d-block" id="currencyNote">
                        Using system default currency
                    </small>
                    <div class="mt-2" id="exchangeRateInfo" style="display: none;">
                        <small class="text-muted">
                            Exchange Rate: 1 <?= $defaultCurrency ?> = <span id="exchangeRateValue">1.00</span> <span id="targetCurrencyCode"><?= $defaultCurrency ?></span>
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
                        <div class="workflow-step active">
                            <i class="bi bi-pencil-square d-block mb-1"></i>
                            <small>Draft</small>
                        </div>
                        <div class="workflow-arrow">→</div>
                        <div class="workflow-step">
                            <i class="bi bi-send d-block mb-1"></i>
                            <small>Placed</small>
                        </div>
                        <div class="workflow-arrow">→</div>
                        <div class="workflow-step">
                            <i class="bi bi-credit-card d-block mb-1"></i>
                            <small>Payment</small>
                        </div>
                        <div class="workflow-arrow">→</div>
                        <div class="workflow-step">
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
                        <strong>Draft orders</strong> are saved but not processed until placed. <strong>Placed orders</strong> enter the sales pipeline immediately.
                    </div>
                    
                    <input type="hidden" name="order_items" id="orderItemsInput">
                    <input type="hidden" name="order_currency" id="orderCurrencyInput" value="<?= $defaultCurrency ?>">
                    <div class="d-grid gap-2">
                        <button type="submit" name="create_order" class="btn btn-outline-primary">
                            <i class="bi bi-save me-2"></i>Save as Draft
                        </button>
                        <button type="submit" name="create_order" class="btn btn-primary" onclick="document.querySelector('input[name=place_immediately]').value='1'">
                            <i class="bi bi-cart-check me-2"></i>Create & Place Order
                        </button>
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
const supportedCurrencies = <?= json_encode($supportedCurrencies) ?>;
const defaultCurrency = '<?= $defaultCurrency ?>';
const exchangeRates = <?= json_encode($exchangeRates) ?>;
const vatSettings = <?= json_encode($vatSettings) ?>;
let orderItems = [];
let currentCurrency = defaultCurrency;
let currentCurrencySymbol = '£';
let currentExchangeRate = 1.0;
let currentVatRate = 0.20;
let vatEnabled = true;

function updateCompanyCurrency() {
    const companySelect = document.querySelector('select[name="company_id"]');
    const selectedOption = companySelect.options[companySelect.selectedIndex];
    const currencyInfo = document.getElementById('currencyInfo');
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
        
        currencyInfo.style.display = 'block';
        updateVatSettings();
        
        // Convert existing cart items to new currency
        if (previousCurrency !== currentCurrency && orderItems.length > 0) {
            console.log(`Converting cart from ${previousCurrency} to ${currentCurrency}`);
            convertExistingCartItems(previousCurrency, currentCurrency, previousExchangeRate, currentExchangeRate);
        }
        
        updateCurrencyDisplay();
    } else {
        currencyInfo.style.display = 'none';
        currentCurrency = defaultCurrency;
        currentCurrencySymbol = '£';
        currentExchangeRate = 1.0;
        updateVatSettings();
        updateCurrencyDisplay();
    }
    
    // Update hidden input
    document.getElementById('orderCurrencyInput').value = currentCurrency;
}

function convertExistingCartItems(fromCurrency, toCurrency, fromRate, toRate) {
    if (orderItems.length === 0) return;
    
    console.log(`Converting ${orderItems.length} cart items from ${fromCurrency} to ${toCurrency}`);
    console.log(`From rate: ${fromRate}, To rate: ${toRate}`);
    
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
                // GBP to other currency
                conversionFactor = toRate;
            } else if (fromCurrency !== defaultCurrency && toCurrency === defaultCurrency) {
                // Other currency to GBP
                conversionFactor = 1 / fromRate;
            } else if (fromCurrency !== defaultCurrency && toCurrency !== defaultCurrency) {
                // Other currency to other currency (via GBP)
                conversionFactor = toRate / fromRate;
            } else {
                // Same currency
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

function updateVatSettings() {
    const vatInfo = document.getElementById('vatInfo');
    const vatStatus = document.getElementById('vatStatus');
    const vatNote = document.getElementById('vatNote');
    const vatRow = document.getElementById('vatRow');
    const vatPercent = document.getElementById('vatPercent');
    
    if (vatSettings.enabled && vatSettings.currency_settings[currentCurrency]) {
        const currencyVat = vatSettings.currency_settings[currentCurrency];
        
        if (currencyVat.enabled) {
            vatEnabled = true;
            currentVatRate = currencyVat.rate;
            
            vatInfo.className = 'vat-info';
            vatStatus.textContent = `Enabled (${(currencyVat.rate * 100).toFixed(0)}%)`;
            vatNote.textContent = 'VAT will be applied to this order';
            vatRow.classList.remove('hidden');
            vatPercent.textContent = (currencyVat.rate * 100).toFixed(0);
        } else {
            vatEnabled = false;
            currentVatRate = 0;
            
            vatInfo.className = 'vat-info disabled';
            vatStatus.textContent = 'Not applicable';
            vatNote.textContent = `VAT is not applicable for ${currentCurrency} orders`;
            vatRow.classList.add('hidden');
        }
    } else {
        vatEnabled = false;
        currentVatRate = 0;
        
        vatInfo.className = 'vat-info disabled';
        vatStatus.textContent = 'Disabled';
        vatNote.textContent = 'VAT is not enabled for this currency';
        vatRow.classList.add('hidden');
    }
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
                base_price: parseFloat(item.base_price), // Store base price for conversion
                base_setup_fee: parseFloat(item.setup_fee || 0) // Store base setup fee
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
                base_price: parseFloat(item.bundle_price), // Store base price for conversion
                base_setup_fee: 0
            });
        }
    }
    
    updateOrderDisplay();
}

function updateOrderDisplay() {
    const itemsList = document.getElementById('orderItemsList');
    
    if (orderItems.length === 0) {
        itemsList.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="bi bi-cart" style="font-size: 2rem;"></i>
                <p class="mt-2">No items added yet. Select products or bundles from above.</p>
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
    
    document.getElementById('itemCount').textContent = itemCount;
    document.getElementById('subtotal').innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${subtotal.toFixed(2)}`;
    document.getElementById('setupFees').innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${setupFees.toFixed(2)}`;
    document.getElementById('vat').innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${vat.toFixed(2)}`;
    document.getElementById('total').innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${total.toFixed(2)}`;
    
    // Update hidden input
    document.getElementById('orderItemsInput').value = JSON.stringify(orderItems);
}

// Set default start date to today
document.addEventListener('DOMContentLoaded', function() {
    const startDateInput = document.querySelector('input[name="start_date"]');
    if (startDateInput) {
        startDateInput.value = new Date().toISOString().split('T')[0];
    }
    
    // Initialize VAT settings
    updateVatSettings();
});

// Form validation
document.getElementById('orderForm').addEventListener('submit', function(e) {
    if (orderItems.length === 0) {
        e.preventDefault();
        alert('Please add at least one item to the order.');
        return false;
    }

    // Update the hidden input one final time
    document.getElementById('orderItemsInput').value = JSON.stringify(orderItems);
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>