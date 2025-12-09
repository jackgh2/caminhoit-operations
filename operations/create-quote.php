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

// Get VAT settings from config page - Fixed VAT logic
$vatSettings = [];
try {
    $stmt = $pdo->query("SELECT * FROM config WHERE category IN ('tax', 'business')");
    $configData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $config = [];
    foreach ($configData as $row) {
        $config[$row['setting_key']] = $row['setting_value'];
    }
    
    // Check if VAT is registered
    $vatRegistered = ($config['vat_registered'] ?? 'no') === 'yes';
    
    // Parse VAT settings
    $vatSettings = [
        'enabled' => $vatRegistered,
        'default_rate' => floatval($config['default_vat_rate'] ?? 0.20),
        'currency_settings' => []
    ];
    
    // Get currency-specific VAT settings - only if VAT is registered
    $supportedCurrencies = ['GBP', 'USD', 'EUR', 'CAD', 'AUD'];
    foreach ($supportedCurrencies as $currency) {
        // Only enable VAT for a currency if business is VAT registered AND currency VAT is enabled
        $currencyVatEnabled = $vatRegistered && ($config["vat_enabled_{$currency}"] ?? 'no') === 'yes';
        
        $vatSettings['currency_settings'][$currency] = [
            'enabled' => $currencyVatEnabled,
            'rate' => floatval($config["vat_rate_{$currency}"] ?? 0.20)
        ];
    }
    
} catch (Exception $e) {
    // Fallback VAT settings - Default to NOT VAT registered
    $vatSettings = [
        'enabled' => false,
        'default_rate' => 0.20,
        'currency_settings' => [
            'GBP' => ['enabled' => false, 'rate' => 0.20],
            'USD' => ['enabled' => false, 'rate' => 0.00],
            'EUR' => ['enabled' => false, 'rate' => 0.20],
            'CAD' => ['enabled' => false, 'rate' => 0.00],
            'AUD' => ['enabled' => false, 'rate' => 0.00]
        ]
    ];
}

// Get supported currencies and exchange rates from config
$supportedCurrencies = [];
$defaultCurrency = 'GBP';
$exchangeRates = [];
try {
    $stmt = $pdo->query("SELECT * FROM config WHERE category = 'currency'");
    $currencyConfig = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($currencyConfig as $row) {
        if ($row['setting_key'] === 'default_currency') {
            $defaultCurrency = $row['setting_value'];
        } elseif (strpos($row['setting_key'], '_symbol') !== false) {
            $currency = str_replace('_symbol', '', $row['setting_key']);
            $supportedCurrencies[$currency]['symbol'] = $row['setting_value'];
        } elseif (strpos($row['setting_key'], '_name') !== false) {
            $currency = str_replace('_name', '', $row['setting_key']);
            $supportedCurrencies[$currency]['name'] = $row['setting_value'];
        } elseif (strpos($row['setting_key'], '_rate') !== false) {
            $currency = str_replace('_rate', '', $row['setting_key']);
            $exchangeRates[$currency] = floatval($row['setting_value']);
        }
    }
    
    // Ensure default currency has rate of 1.0
    if (!isset($exchangeRates[$defaultCurrency])) {
        $exchangeRates[$defaultCurrency] = 1.0;
    }
    
} catch (Exception $e) {
    // Fallback currencies and rates
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
}

// Function to generate quote number
function generateQuoteNumber($pdo) {
    $year = date('Y');
    $month = date('m');
    
    // Get the last quote number for this month
    $stmt = $pdo->prepare("SELECT quote_number FROM quotes WHERE quote_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute(["QUO-$year-$month-%"]);
    $lastQuote = $stmt->fetchColumn();
    
    if ($lastQuote) {
        $lastNumber = (int)substr($lastQuote, -4);
        $newNumber = $lastNumber + 1;
    } else {
        $newNumber = 1;
    }
    
    return "QUO-$year-$month-" . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Generate quote number
        $quote_number = generateQuoteNumber($pdo);
        
        // Get form data
        $company_id = (int)$_POST['company_id'];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $currency = $_POST['currency'];
        $valid_until = $_POST['valid_until'] ?: null;
        $terms_conditions = trim($_POST['terms_conditions']);
        $notes = trim($_POST['notes']);
        
        // Determine VAT settings for this currency - FIXED: Ensure proper integer conversion
        $vat_enabled = $vatSettings['currency_settings'][$currency]['enabled'] ?? false;
        $vat_rate = $vatSettings['currency_settings'][$currency]['rate'] ?? 0.0;
        
        // Convert boolean to integer for database storage
        $vat_enabled_int = $vat_enabled ? 1 : 0;
        
        // Ensure vat_rate is a proper float
        $vat_rate_float = floatval($vat_rate);
        
        // Calculate totals
        $subtotal = 0;
        $items = $_POST['items'] ?? [];
        
        foreach ($items as $item) {
            if (!empty($item['name']) && !empty($item['quantity']) && !empty($item['unit_price'])) {
                $line_total = floatval($item['quantity']) * floatval($item['unit_price']);
                $subtotal += $line_total;
            }
        }
        
        $tax_amount = $vat_enabled ? $subtotal * $vat_rate_float : 0;
        $total_amount = $subtotal + $tax_amount;
        
        // Insert quote with proper integer/float values
        $stmt = $pdo->prepare("INSERT INTO quotes (quote_number, company_id, staff_id, title, description, currency, subtotal, tax_amount, total_amount, vat_enabled, vat_rate, valid_until, terms_conditions, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $quote_number,
            $company_id,
            $_SESSION['user']['id'],
            $title,
            $description,
            $currency,
            floatval($subtotal),
            floatval($tax_amount),
            floatval($total_amount),
            $vat_enabled_int,  // Now properly converted to integer
            $vat_rate_float,   // Now properly converted to float
            $valid_until,
            $terms_conditions,
            $notes
        ]);
        
        $quote_id = $pdo->lastInsertId();
        
        // Insert quote items
        foreach ($items as $item) {
            if (!empty($item['name']) && !empty($item['quantity']) && !empty($item['unit_price'])) {
                $quantity = floatval($item['quantity']);
                $unit_price = floatval($item['unit_price']);
                $setup_fee = floatval($item['setup_fee'] ?? 0);
                $line_total = $quantity * $unit_price;
                
                $stmt = $pdo->prepare("INSERT INTO quote_items (quote_id, name, description, quantity, unit_price, setup_fee, billing_cycle, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $quote_id,
                    $item['name'],
                    $item['description'] ?? '',
                    $quantity,
                    $unit_price,
                    $setup_fee,
                    $item['billing_cycle'] ?? 'one_time',
                    $line_total
                ]);
            }
        }
        
        // Log initial status
        $stmt = $pdo->prepare("INSERT INTO quote_status_history (quote_id, status_to, changed_by, notes) VALUES (?, 'draft', ?, 'Quote created')");
        $stmt->execute([$quote_id, $_SESSION['user']['id']]);
        
        $pdo->commit();
        
        // Redirect to view quote
        header("Location: view-quote.php?id=$quote_id&success=" . urlencode("Quote created successfully!"));
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error creating quote: " . $e->getMessage();
        // Add debug info
        error_log("Quote creation error: " . $e->getMessage());
        error_log("VAT enabled value: " . var_export($vat_enabled ?? 'undefined', true));
        error_log("VAT rate value: " . var_export($vat_rate ?? 'undefined', true));
    }
}

// Get companies for dropdown
$stmt = $pdo->query("SELECT id, name, preferred_currency FROM companies ORDER BY name ASC");
$companies = $stmt->fetchAll();

// Get products for quick add - FIXED to use base_price
$products = [];
try {
    $stmt = $pdo->query("SELECT p.id, p.name, p.base_price, p.setup_fee, p.billing_cycle, p.short_description, p.unit_type, c.name as category_name
        FROM products p 
        JOIN service_categories c ON p.category_id = c.id 
        WHERE p.is_active = 1 
        ORDER BY c.sort_order ASC, p.name ASC");
    $products = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Error loading products: " . $e->getMessage());
    $products = [];
}

// Get service bundles for quick add - FIXED to use bundle_price
$bundles = [];
try {
    $stmt = $pdo->query("SELECT id, name, bundle_price as price, 0 as setup_fee, billing_cycle, short_description, target_audience as description
        FROM service_bundles 
        WHERE is_active = 1 
        ORDER BY name ASC");
    $bundles = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Error loading bundles: " . $e->getMessage());
    $bundles = [];
}

$page_title = "Create Quote | CaminhoIT";
?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

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

        .form-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
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

        .items-table {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
        }

        .items-table th {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
            color: #374151;
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

        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }

        .currency-info {
            background: #f0f9ff;
            border: 1px solid #e0e7ff;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
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

        .quote-summary {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1rem;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-row.total {
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--primary-color);
        }

        .breadcrumb {
            background: none;
            padding: 0;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .breadcrumb-item a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .breadcrumb-item a:hover {
            text-decoration: underline;
        }

        .item-row {
            border-bottom: 1px solid #f3f4f6;
        }

        .item-row:last-child {
            border-bottom: none;
        }

        .remove-item {
            color: #dc2626;
            cursor: pointer;
            font-size: 1.2rem;
        }

        .remove-item:hover {
            color: #b91c1c;
        }

        .quick-add-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .table-info {
            background: #f0f9ff;
            border: 1px solid #e0e7ff;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .vat-status {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .vat-enabled {
            background: #d1fae5;
            color: #065f46;
        }

        .vat-disabled {
            background: #f3f4f6;
            color: #6b7280;
        }

        .success-info {
            background: #d1fae5;
            border: 1px solid #10b981;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .currency-conversion-info {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .original-price {
            color: #6b7280;
            font-size: 0.8rem;
            text-decoration: line-through;
        }

        .converted-price {
            color: #059669;
            font-weight: 600;
        }

        .debug-info {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="quotes.php">Quotes</a></li>
                <li class="breadcrumb-item active">Create Quote</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-file-plus me-3"></i>Create New Quote</h1>
                <p class="text-muted mb-0">Create a professional quote for your client</p>
            </div>
        </div>
    </div>

    <!-- Error Messages -->
    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Debug Info -->
    <div class="debug-info">
        <i class="bi bi-info-circle me-2"></i>
        <strong>Debug:</strong> VAT database error fixed - now properly converting boolean values to integers (0/1) for database storage.
    </div>

    <!-- VAT Settings Info -->
    <div class="table-info">
        <i class="bi bi-info-circle me-2"></i>
        <strong>VAT Settings:</strong>
        <?php if ($vatSettings['enabled']): ?>
            <span class="vat-status vat-enabled">VAT Registered</span>
            Default Rate: <?= ($vatSettings['default_rate'] * 100) ?>%
        <?php else: ?>
            <span class="vat-status vat-disabled">Not VAT Registered</span>
        <?php endif; ?>
        - Settings are loaded from your configuration page.
    </div>

    <!-- Currency Conversion Info -->
    <div class="currency-conversion-info">
        <i class="bi bi-currency-exchange me-2"></i>
        <strong>Currency Conversion:</strong>
        Prices are stored in <?= $supportedCurrencies[$defaultCurrency]['symbol'] ?> <?= $defaultCurrency ?> and automatically converted to your selected currency.
        <span id="exchange_rate_display">Current rate: 1.00</span>
    </div>

    <form method="POST" id="quoteForm">
        <!-- Quote Details -->
        <div class="form-section">
            <h5 class="section-title">Quote Details</h5>
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Company *</label>
                        <select name="company_id" class="form-select" required id="company_select">
                            <option value="">Select Company</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= $company['id'] ?>" data-currency="<?= $company['preferred_currency'] ?>">
                                    <?= htmlspecialchars($company['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Currency *</label>
                        <select name="currency" class="form-select" required id="currency_select">
                            <?php foreach ($supportedCurrencies as $code => $currency): ?>
                                <option value="<?= $code ?>" <?= $code === $defaultCurrency ? 'selected' : '' ?>>
                                    <?= $currency['symbol'] ?> <?= $code ?> - <?= $currency['name'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-8">
                    <div class="mb-3">
                        <label class="form-label">Quote Title *</label>
                        <input type="text" name="title" class="form-control" required placeholder="e.g., IT Support Services Quote">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label">Valid Until</label>
                        <input type="date" name="valid_until" class="form-control" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Brief description of the quote..."></textarea>
            </div>
            
            <!-- Currency & VAT Info -->
            <div class="currency-info">
                <div class="row">
                    <div class="col-md-6">
                        <strong>Selected Currency:</strong> <span id="selected_currency_display"><?= $supportedCurrencies[$defaultCurrency]['symbol'] ?> <?= $defaultCurrency ?></span>
                    </div>
                    <div class="col-md-6">
                        <div class="vat-info" id="vat_info">
                            <strong>VAT:</strong> <span id="vat_status">Checking...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quote Items -->
        <div class="form-section">
            <h5 class="section-title">Quote Items</h5>
            
            <!-- Quick Add Section -->
            <?php if (!empty($products) || !empty($bundles)): ?>
                <div class="quick-add-section">
                    <h6>Quick Add</h6>
                    <div class="row">
                        <?php if (!empty($products)): ?>
                            <div class="col-md-6">
                                <label class="form-label">Products</label>
                                <select class="form-select" id="product_select">
                                    <option value="">Select Product</option>
                                    <?php foreach ($products as $product): ?>
                                        <option value="<?= $product['id'] ?>" 
                                                data-name="<?= htmlspecialchars($product['name']) ?>"
                                                data-price="<?= $product['base_price'] ?>"
                                                data-setup="<?= $product['setup_fee'] ?>"
                                                data-billing="<?= $product['billing_cycle'] ?>"
                                                data-description="<?= htmlspecialchars($product['short_description']) ?>">
                                            <?= htmlspecialchars($product['name']) ?> (<?= htmlspecialchars($product['category_name']) ?>) - 
                                            <span class="original-price"><?= $supportedCurrencies[$defaultCurrency]['symbol'] ?><?= number_format($product['base_price'], 2) ?></span>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($bundles)): ?>
                            <div class="col-md-6">
                                <label class="form-label">Service Bundles</label>
                                <select class="form-select" id="bundle_select">
                                    <option value="">Select Service Bundle</option>
                                    <?php foreach ($bundles as $bundle): ?>
                                        <option value="<?= $bundle['id'] ?>" 
                                                data-name="<?= htmlspecialchars($bundle['name']) ?>"
                                                data-price="<?= $bundle['price'] ?>"
                                                data-setup="<?= $bundle['setup_fee'] ?>"
                                                data-billing="<?= $bundle['billing_cycle'] ?>"
                                                data-description="<?= htmlspecialchars($bundle['description']) ?>">
                                            <?= htmlspecialchars($bundle['name']) ?> - 
                                            <span class="original-price"><?= $supportedCurrencies[$defaultCurrency]['symbol'] ?><?= number_format($bundle['price'], 2) ?></span>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Note:</strong> No products or service bundles found. You can still add items manually below.
                </div>
            <?php endif; ?>
            
            <div class="table-responsive">
                <table class="table items-table" id="items_table">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Description</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Setup Fee</th>
                            <th>Billing Cycle</th>
                            <th>Line Total</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="items_tbody">
                        <tr class="item-row">
                            <td>
                                <input type="text" name="items[0][name]" class="form-control" placeholder="Item name" required>
                            </td>
                            <td>
                                <input type="text" name="items[0][description]" class="form-control" placeholder="Description">
                            </td>
                            <td>
                                <input type="number" name="items[0][quantity]" class="form-control quantity" min="1" value="1" required>
                            </td>
                            <td>
                                <input type="number" name="items[0][unit_price]" class="form-control unit-price" step="0.01" min="0" required>
                            </td>
                            <td>
                                <input type="number" name="items[0][setup_fee]" class="form-control setup-fee" step="0.01" min="0" value="0">
                            </td>
                            <td>
                                <select name="items[0][billing_cycle]" class="form-select">
                                    <option value="one_time">One Time</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="semi_annually">Semi-Annually</option>
                                    <option value="annually">Annually</option>
                                </select>
                            </td>
                            <td>
                                <span class="line-total">£0.00</span>
                            </td>
                            <td>
                                <i class="bi bi-trash remove-item" onclick="removeItem(this)"></i>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <button type="button" class="btn btn-outline-primary" onclick="addItem()">
                <i class="bi bi-plus-circle me-2"></i>Add Item
            </button>
            
            <!-- Quote Summary -->
            <div class="quote-summary">
                <div class="summary-row">
                    <span>Subtotal:</span>
                    <span id="subtotal_display">£0.00</span>
                </div>
                <div class="summary-row" id="tax_row" style="display: none;">
                    <span>VAT (<span id="vat_rate_display">0</span>%):</span>
                    <span id="tax_display">£0.00</span>
                </div>
                <div class="summary-row total">
                    <span>Total:</span>
                    <span id="total_display">£0.00</span>
                </div>
            </div>
        </div>

        <!-- Additional Information -->
        <div class="form-section">
            <h5 class="section-title">Additional Information</h5>
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Terms & Conditions</label>
                        <textarea name="terms_conditions" class="form-control" rows="4" placeholder="Enter terms and conditions..."></textarea>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Internal Notes</label>
                        <textarea name="notes" class="form-control" rows="4" placeholder="Internal notes (not visible to client)..."></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="form-section">
            <div class="d-flex justify-content-between">
                <a href="quotes.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Quotes
                </a>
                <div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>Create Quote
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
// Pass PHP data to JavaScript
const supportedCurrencies = <?= json_encode($supportedCurrencies) ?>;
const vatSettings = <?= json_encode($vatSettings) ?>;
const exchangeRates = <?= json_encode($exchangeRates) ?>;
const defaultCurrency = '<?= $defaultCurrency ?>';
const products = <?= json_encode($products) ?>;
const bundles = <?= json_encode($bundles) ?>;

let itemCounter = 1;
let currentCurrency = defaultCurrency;
let currentVatRate = 0;
let vatEnabled = false;
let currentExchangeRate = 1.0;

// Debug logging
console.log('VAT Settings:', vatSettings);
console.log('Exchange Rates:', exchangeRates);
console.log('Products:', products);
console.log('Bundles:', bundles);

// Function to convert price from default currency to selected currency
function convertPrice(price, fromCurrency = defaultCurrency, toCurrency = currentCurrency) {
    if (fromCurrency === toCurrency) return price;
    
    // Convert to GBP first if needed
    let gbpPrice = price;
    if (fromCurrency !== defaultCurrency) {
        gbpPrice = price / exchangeRates[fromCurrency];
    }
    
    // Convert from GBP to target currency
    return gbpPrice * exchangeRates[toCurrency];
}

// Function to update product/bundle prices in dropdowns
function updateDropdownPrices() {
    const productSelect = document.getElementById('product_select');
    const bundleSelect = document.getElementById('bundle_select');
    
    if (productSelect) {
        Array.from(productSelect.options).forEach(option => {
            if (option.value) {
                const originalPrice = parseFloat(option.dataset.price);
                const convertedPrice = convertPrice(originalPrice);
                const currencySymbol = supportedCurrencies[currentCurrency].symbol;
                
                // Update the option text with converted price
                const nameMatch = option.textContent.match(/^(.+?) \(/);
                const categoryMatch = option.textContent.match(/\((.+?)\)/);
                
                if (nameMatch && categoryMatch) {
                    const name = nameMatch[1];
                    const category = categoryMatch[1];
                    option.textContent = `${name} (${category}) - ${currencySymbol}${convertedPrice.toFixed(2)}`;
                }
            }
        });
    }
    
    if (bundleSelect) {
        Array.from(bundleSelect.options).forEach(option => {
            if (option.value) {
                const originalPrice = parseFloat(option.dataset.price);
                const convertedPrice = convertPrice(originalPrice);
                const currencySymbol = supportedCurrencies[currentCurrency].symbol;
                
                // Update the option text with converted price
                const nameMatch = option.textContent.match(/^(.+?) -/);
                if (nameMatch) {
                    const name = nameMatch[1];
                    option.textContent = `${name} - ${currencySymbol}${convertedPrice.toFixed(2)}`;
                }
            }
        });
    }
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    updateCurrencyDisplay();
    updateVatInfo();
    updateExchangeRateDisplay();
    updateDropdownPrices();
    calculateTotals();
    
    // Add event listeners
    document.getElementById('currency_select').addEventListener('change', handleCurrencyChange);
    document.getElementById('company_select').addEventListener('change', handleCompanyChange);
    
    // Add quick add listeners if elements exist
    const productSelect = document.getElementById('product_select');
    const bundleSelect = document.getElementById('bundle_select');
    
    if (productSelect) {
        productSelect.addEventListener('change', handleProductSelect);
    }
    
    if (bundleSelect) {
        bundleSelect.addEventListener('change', handleBundleSelect);
    }
    
    // Add listeners for calculation
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('quantity') || e.target.classList.contains('unit-price') || e.target.classList.contains('setup-fee')) {
            calculateTotals();
        }
    });
});

function handleCurrencyChange() {
    currentCurrency = document.getElementById('currency_select').value;
    currentExchangeRate = exchangeRates[currentCurrency] || 1.0;
    updateCurrencyDisplay();
    updateVatInfo();
    updateExchangeRateDisplay();
    updateDropdownPrices();
    calculateTotals();
}

function handleCompanyChange() {
    const companySelect = document.getElementById('company_select');
    const selectedOption = companySelect.options[companySelect.selectedIndex];
    const preferredCurrency = selectedOption.dataset.currency;
    
    if (preferredCurrency && supportedCurrencies[preferredCurrency]) {
        document.getElementById('currency_select').value = preferredCurrency;
        handleCurrencyChange();
    }
}

function handleProductSelect() {
    const productSelect = document.getElementById('product_select');
    const selectedOption = productSelect.options[productSelect.selectedIndex];
    
    if (selectedOption.value) {
        console.log('Selected product:', selectedOption.dataset);
        
        // Convert prices from GBP to selected currency
        const originalPrice = parseFloat(selectedOption.dataset.price);
        const originalSetup = parseFloat(selectedOption.dataset.setup);
        const convertedPrice = convertPrice(originalPrice);
        const convertedSetup = convertPrice(originalSetup);
        
        // Add item with converted prices
        addItemWithData({
            name: selectedOption.dataset.name,
            description: selectedOption.dataset.description,
            price: convertedPrice.toFixed(2),
            setup: convertedSetup.toFixed(2),
            billing: selectedOption.dataset.billing
        });
        productSelect.value = '';
    }
}

function handleBundleSelect() {
    const bundleSelect = document.getElementById('bundle_select');
    const selectedOption = bundleSelect.options[bundleSelect.selectedIndex];
    
    if (selectedOption.value) {
        console.log('Selected bundle:', selectedOption.dataset);
        
        // Convert prices from GBP to selected currency
        const originalPrice = parseFloat(selectedOption.dataset.price);
        const originalSetup = parseFloat(selectedOption.dataset.setup);
        const convertedPrice = convertPrice(originalPrice);
        const convertedSetup = convertPrice(originalSetup);
        
        // Add item with converted prices
        addItemWithData({
            name: selectedOption.dataset.name,
            description: selectedOption.dataset.description,
            price: convertedPrice.toFixed(2),
            setup: convertedSetup.toFixed(2),
            billing: selectedOption.dataset.billing
        });
        bundleSelect.value = '';
    }
}

function updateCurrencyDisplay() {
    const currency = supportedCurrencies[currentCurrency];
    document.getElementById('selected_currency_display').textContent = currency.symbol + ' ' + currentCurrency;
}

function updateExchangeRateDisplay() {
    const rateDisplay = document.getElementById('exchange_rate_display');
    if (currentCurrency === defaultCurrency) {
        rateDisplay.textContent = 'Base currency';
    } else {
        rateDisplay.textContent = `Current rate: 1 ${defaultCurrency} = ${currentExchangeRate.toFixed(4)} ${currentCurrency}`;
    }
}

function updateVatInfo() {
    const vatInfoDiv = document.getElementById('vat_info');
    const vatStatusSpan = document.getElementById('vat_status');
    
    console.log('Checking VAT for currency:', currentCurrency);
    console.log('VAT settings for currency:', vatSettings.currency_settings[currentCurrency]);
    
    if (vatSettings.currency_settings[currentCurrency]?.enabled) {
        vatEnabled = true;
        currentVatRate = vatSettings.currency_settings[currentCurrency].rate;
        vatInfoDiv.className = 'vat-info';
        vatStatusSpan.textContent = `Enabled (${(currentVatRate * 100).toFixed(1)}%)`;
        console.log('VAT enabled for', currentCurrency, 'at rate', currentVatRate);
    } else {
        vatEnabled = false;
        currentVatRate = 0;
        vatInfoDiv.className = 'vat-info disabled';
        vatStatusSpan.textContent = `Not applicable for ${currentCurrency}`;
        console.log('VAT disabled for', currentCurrency);
    }
}

function addItem() {
    const tbody = document.getElementById('items_tbody');
    const row = document.createElement('tr');
    row.className = 'item-row';
    
    row.innerHTML = `
        <td>
            <input type="text" name="items[${itemCounter}][name]" class="form-control" placeholder="Item name" required>
        </td>
        <td>
            <input type="text" name="items[${itemCounter}][description]" class="form-control" placeholder="Description">
        </td>
        <td>
            <input type="number" name="items[${itemCounter}][quantity]" class="form-control quantity" min="1" value="1" required>
        </td>
        <td>
            <input type="number" name="items[${itemCounter}][unit_price]" class="form-control unit-price" step="0.01" min="0" required>
        </td>
        <td>
            <input type="number" name="items[${itemCounter}][setup_fee]" class="form-control setup-fee" step="0.01" min="0" value="0">
        </td>
        <td>
            <select name="items[${itemCounter}][billing_cycle]" class="form-select">
                <option value="one_time">One Time</option>
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="semi_annually">Semi-Annually</option>
                <option value="annually">Annually</option>
            </select>
        </td>
        <td>
            <span class="line-total">${supportedCurrencies[currentCurrency].symbol}0.00</span>
        </td>
        <td>
            <i class="bi bi-trash remove-item" onclick="removeItem(this)"></i>
        </td>
    `;
    
    tbody.appendChild(row);
    itemCounter++;
}

function addItemWithData(data) {
    const tbody = document.getElementById('items_tbody');
    const row = document.createElement('tr');
    row.className = 'item-row';
    
    console.log('Adding item with converted data:', data);
    
    row.innerHTML = `
        <td>
            <input type="text" name="items[${itemCounter}][name]" class="form-control" value="${data.name}" required>
        </td>
        <td>
            <input type="text" name="items[${itemCounter}][description]" class="form-control" value="${data.description || ''}" placeholder="Description">
        </td>
        <td>
            <input type="number" name="items[${itemCounter}][quantity]" class="form-control quantity" min="1" value="1" required>
        </td>
        <td>
            <input type="number" name="items[${itemCounter}][unit_price]" class="form-control unit-price" step="0.01" min="0" value="${data.price}" required>
        </td>
        <td>
            <input type="number" name="items[${itemCounter}][setup_fee]" class="form-control setup-fee" step="0.01" min="0" value="${data.setup}">
        </td>
        <td>
            <select name="items[${itemCounter}][billing_cycle]" class="form-select">
                <option value="one_time" ${data.billing === 'one_time' ? 'selected' : ''}>One Time</option>
                <option value="monthly" ${data.billing === 'monthly' ? 'selected' : ''}>Monthly</option>
                <option value="quarterly" ${data.billing === 'quarterly' ? 'selected' : ''}>Quarterly</option>
                <option value="semi_annually" ${data.billing === 'semi_annually' ? 'selected' : ''}>Semi-Annually</option>
                <option value="annually" ${data.billing === 'annually' ? 'selected' : ''}>Annually</option>
            </select>
        </td>
        <td>
            <span class="line-total">${supportedCurrencies[currentCurrency].symbol}${parseFloat(data.price).toFixed(2)}</span>
        </td>
        <td>
            <i class="bi bi-trash remove-item" onclick="removeItem(this)"></i>
        </td>
    `;
    
    tbody.appendChild(row);
    itemCounter++;
    calculateTotals();
}

function removeItem(element) {
    const row = element.closest('tr');
    const tbody = document.getElementById('items_tbody');
    
    if (tbody.children.length > 1) {
        row.remove();
        calculateTotals();
    } else {
        alert('At least one item is required.');
    }
}

function calculateTotals() {
    const rows = document.querySelectorAll('#items_tbody tr');
    let subtotal = 0;
    
    rows.forEach(row => {
        const quantity = parseFloat(row.querySelector('.quantity')?.value || 0);
        const unitPrice = parseFloat(row.querySelector('.unit-price')?.value || 0);
        const lineTotal = quantity * unitPrice;
        
        const lineTotalSpan = row.querySelector('.line-total');
        if (lineTotalSpan) {
            lineTotalSpan.textContent = supportedCurrencies[currentCurrency].symbol + lineTotal.toFixed(2);
        }
        
        subtotal += lineTotal;
    });
    
    const taxAmount = vatEnabled ? subtotal * currentVatRate : 0;
    const totalAmount = subtotal + taxAmount;
    
    console.log('Calculating totals:', { subtotal, taxAmount, totalAmount, vatEnabled, currentVatRate });
    
    // Update display
    document.getElementById('subtotal_display').textContent = supportedCurrencies[currentCurrency].symbol + subtotal.toFixed(2);
    document.getElementById('tax_display').textContent = supportedCurrencies[currentCurrency].symbol + taxAmount.toFixed(2);
    document.getElementById('total_display').textContent = supportedCurrencies[currentCurrency].symbol + totalAmount.toFixed(2);
    document.getElementById('vat_rate_display').textContent = (currentVatRate * 100).toFixed(1);
    
    // Show/hide tax row
    const taxRow = document.getElementById('tax_row');
    if (vatEnabled && taxAmount > 0) {
        taxRow.style.display = 'flex';
    } else {
        taxRow.style.display = 'none';
    }
}

console.log('Quote creation page loaded successfully with currency conversion and VAT fix');
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>