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

$quote_id = (int)($_GET['id'] ?? 0);

if (!$quote_id) {
    header('Location: quotes.php?error=invalid_quote_id');
    exit;
}

// Get supported currencies
$supportedCurrencies = ConfigManager::getSupportedCurrencies();

// Get companies for dropdown
$stmt = $pdo->query("SELECT id, name FROM companies ORDER BY name ASC");
$companies = $stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quote'])) {
    try {
        $pdo->beginTransaction();
        
        // Validate and sanitize input
        $company_id = (int)$_POST['company_id'];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $currency = $_POST['currency'];
        $valid_until = $_POST['valid_until'] ?: null;
        $vat_enabled = isset($_POST['vat_enabled']) ? 1 : 0;
        $vat_rate = $vat_enabled ? (float)$_POST['vat_rate'] / 100 : 0; // Convert percentage to decimal
        $terms_conditions = trim($_POST['terms_conditions']);
        $notes = trim($_POST['notes']);
        
        // Validate required fields
        if (!$company_id || !$title || !$currency) {
            throw new Exception("Please fill in all required fields.");
        }
        
        if (!isset($supportedCurrencies[$currency])) {
            throw new Exception("Invalid currency selected.");
        }
        
        // Process quote items
        $items = [];
        $subtotal = 0;
        
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (empty($item['name']) || $item['quantity'] <= 0 || $item['unit_price'] < 0) {
                    continue; // Skip invalid items
                }
                
                $quantity = (int)$item['quantity'];
                $unit_price = (float)$item['unit_price'];
                $setup_fee = (float)($item['setup_fee'] ?? 0);
                $line_total = ($quantity * $unit_price) + $setup_fee;
                $subtotal += $line_total;
                
                $items[] = [
                    'id' => (int)($item['id'] ?? 0),
                    'name' => trim($item['name']),
                    'description' => trim($item['description'] ?? ''),
                    'quantity' => $quantity,
                    'unit_price' => $unit_price,
                    'setup_fee' => $setup_fee,
                    'billing_cycle' => $item['billing_cycle'],
                    'line_total' => $line_total
                ];
            }
        }
        
        if (empty($items)) {
            throw new Exception("Please add at least one quote item.");
        }
        
        // Calculate tax and total
        $tax_amount = $vat_enabled ? ($subtotal * $vat_rate) : 0;
        $total_amount = $subtotal + $tax_amount;
        
        // Update quote
        $stmt = $pdo->prepare("UPDATE quotes SET 
            company_id = ?, title = ?, description = ?, currency = ?, 
            valid_until = ?, vat_enabled = ?, vat_rate = ?, 
            subtotal = ?, tax_amount = ?, total_amount = ?,
            terms_conditions = ?, notes = ?, updated_at = NOW()
            WHERE id = ?");
        
        $stmt->execute([
            $company_id, $title, $description, $currency,
            $valid_until, $vat_enabled, $vat_rate,
            $subtotal, $tax_amount, $total_amount,
            $terms_conditions, $notes, $quote_id
        ]);
        
        // Delete existing quote items
        $stmt = $pdo->prepare("DELETE FROM quote_items WHERE quote_id = ?");
        $stmt->execute([$quote_id]);
        
        // Insert new quote items
        $stmt = $pdo->prepare("INSERT INTO quote_items 
            (quote_id, name, description, quantity, unit_price, setup_fee, billing_cycle, line_total) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($items as $item) {
            $stmt->execute([
                $quote_id, $item['name'], $item['description'], 
                $item['quantity'], $item['unit_price'], $item['setup_fee'],
                $item['billing_cycle'], $item['line_total']
            ]);
        }
        
        $pdo->commit();
        $success = "Quote updated successfully!";
        
        // Redirect to view quote
        header("Location: view-quote.php?id=$quote_id&success=" . urlencode($success));
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Get quote details
$stmt = $pdo->prepare("SELECT q.*, c.name as company_name 
    FROM quotes q
    JOIN companies c ON q.company_id = c.id
    WHERE q.id = ?");
$stmt->execute([$quote_id]);
$quote = $stmt->fetch();

if (!$quote) {
    header('Location: quotes.php?error=quote_not_found');
    exit;
}

// Get quote items
$stmt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id ASC");
$stmt->execute([$quote_id]);
$quote_items = $stmt->fetchAll();

// Get currency information
$quote_currency = $quote['currency'] ?? 'GBP';
$currency_symbol = $supportedCurrencies[$quote_currency]['symbol'] ?? '£';

// Get business information
$business_details = [
    'company_name' => ConfigManager::get('business.company_name', 'CaminhoIT'),
    'company_address' => ConfigManager::get('business.company_address', ''),
    'company_phone' => ConfigManager::get('business.company_phone', ''),
    'company_email' => ConfigManager::get('business.company_email', ''),
    'company_website' => ConfigManager::get('business.company_website', '')
];

// Get current exchange rates from database
$dbRates = ConfigManager::getExchangeRates();
$baseCurrency = ConfigManager::get('business.default_currency', 'GBP');

$page_title = "Edit Quote #" . $quote['quote_number'] . " | " . $business_details['company_name'];
?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

    <style>
        :root {
            --primary-color: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary-color: #64748b;
            --success-color: #059669;
            --warning-color: #d97706;
            --danger-color: #dc2626;
            --info-color: #0891b2;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
        }

        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
        }

        body {
            background-color: #f8fafc;
            color: var(--gray-800);
            font-size: 14px;
            line-height: 1.6;
            padding-top: 80px;
        }

        .navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%) !important;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06) !important;
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
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--gray-200);
        }

        .form-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            margin-bottom: 2rem;
            border: 1px solid var(--gray-200);
        }

        .form-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }

        .form-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: repeating-linear-gradient(
                45deg,
                transparent,
                transparent 10px,
                rgba(255, 255, 255, 0.05) 10px,
                rgba(255, 255, 255, 0.05) 20px
            );
        }

        .form-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .form-section {
            padding: 2rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .form-section:last-child {
            border-bottom: none;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid var(--gray-200);
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .btn {
            border-radius: 8px;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            transition: all 0.2s ease;
            border: 2px solid transparent;
        }

        .btn-primary {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(37, 99, 235, 0.3);
        }

        .btn-outline-primary {
            border-color: var(--primary-color);
            color: var(--primary-color);
            background: white;
        }

        .btn-outline-primary:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success-color);
            border-color: var(--success-color);
        }

        .btn-danger {
            background: var(--danger-color);
            border-color: var(--danger-color);
        }

        .items-table {
            border: none;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }

        .items-table th {
            background: linear-gradient(135deg, var(--gray-100) 0%, var(--gray-50) 100%);
            border: none;
            font-weight: 700;
            color: var(--gray-700);
            padding: 1rem 0.75rem;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .items-table td {
            padding: 1rem 0.75rem;
            border: none;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
        }

        .items-table tbody tr:last-child td {
            border-bottom: none;
        }

        .items-table tbody tr:hover {
            background: rgba(37, 99, 235, 0.02);
        }

        .quote-totals {
            background: var(--gray-50);
            border-radius: 12px;
            padding: 2rem;
            margin-top: 2rem;
            border-left: 4px solid var(--primary-color);
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .totals-row:last-child {
            border-bottom: none;
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--primary-color);
            border-top: 2px solid var(--primary-color);
            padding-top: 1rem;
        }

        .alert {
            border: none;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-weight: 500;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-left-color: var(--success-color);
        }

        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-left-color: var(--danger-color);
        }

        .alert-info {
            background: #f0f9ff;
            color: #0369a1;
            border-left-color: var(--info-color);
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
            font-weight: 600;
        }

        .breadcrumb-item a:hover {
            text-decoration: underline;
        }

        .input-group-text {
            background: var(--gray-100);
            border: 2px solid var(--gray-200);
            border-right: none;
            color: var(--gray-600);
            font-weight: 600;
        }

        .input-group .form-control {
            border-left: none;
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .currency-display {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .conversion-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--success-color);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
        }

        @media (max-width: 768px) {
            .form-section {
                padding: 1rem;
            }
            
            .items-table {
                font-size: 0.875rem;
            }
            
            .items-table th,
            .items-table td {
                padding: 0.75rem 0.5rem;
            }
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
                <li class="breadcrumb-item"><a href="view-quote.php?id=<?= $quote['id'] ?>">Quote #<?= htmlspecialchars($quote['quote_number']) ?></a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-pencil-square me-3"></i>Edit Quote #<?= htmlspecialchars($quote['quote_number']) ?></h1>
                <p class="text-muted mb-0">
                    Modify quote details, items, and pricing
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="view-quote.php?id=<?= $quote['id'] ?>" class="btn btn-outline-primary">
                    <i class="bi bi-eye me-2"></i>View Quote
                </a>
                <a href="quotes.php" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Quotes
                </a>
            </div>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success)): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Edit Quote Form -->
    <form method="POST" class="form-container" id="editQuoteForm">
        <div class="form-header">
            <h2><i class="bi bi-file-earmark-text"></i>Quote Information</h2>
        </div>

        <!-- Basic Information -->
        <div class="form-section">
            <h3 class="section-title"><i class="bi bi-info-circle"></i>Basic Information</h3>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="company_id" class="form-label">Company *</label>
                    <select name="company_id" id="company_id" class="form-select" required>
                        <option value="">Select Company</option>
                        <?php foreach ($companies as $company): ?>
                            <option value="<?= $company['id'] ?>" <?= $company['id'] == $quote['company_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($company['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="title" class="form-label">Quote Title *</label>
                    <input type="text" name="title" id="title" class="form-control" 
                           value="<?= htmlspecialchars($quote['title']) ?>" required>
                </div>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea name="description" id="description" class="form-control" rows="4" 
                          placeholder="Describe the project or services..."><?= htmlspecialchars($quote['description']) ?></textarea>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="currency" class="form-label">Currency *</label>
                    <select name="currency" id="currency" class="form-select" required onchange="updateCurrencySymbol()">
                        <?php foreach ($supportedCurrencies as $code => $currency): ?>
                            <option value="<?= $code ?>" <?= $code === $quote_currency ? 'selected' : '' ?>>
                                <?= $currency['symbol'] ?> <?= $code ?> (<?= $currency['name'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4 mb-3">
                    <label for="valid_until" class="form-label">Valid Until</label>
                    <input type="date" name="valid_until" id="valid_until" class="form-control" 
                           value="<?= $quote['valid_until'] ?>">
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Current Currency</label>
                    <div class="currency-display position-relative" id="currentCurrency">
                        <i class="bi bi-currency-exchange"></i>
                        <span id="currencyText"><?= $currency_symbol ?> <?= $quote_currency ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- VAT Settings -->
        <div class="form-section">
            <h3 class="section-title"><i class="bi bi-calculator"></i>VAT Settings</h3>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="vat_enabled" 
                               id="vat_enabled" <?= $quote['vat_enabled'] ? 'checked' : '' ?> 
                               onchange="toggleVATRate()">
                        <label class="form-check-label" for="vat_enabled">
                            Enable VAT
                        </label>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="vat_rate" class="form-label">VAT Rate (%)</label>
                    <div class="input-group">
                        <input type="number" name="vat_rate" id="vat_rate" class="form-control" 
                               step="0.01" min="0" max="100" 
                               value="<?= number_format($quote['vat_rate'] * 100, 2) ?>"
                               <?= !$quote['vat_enabled'] ? 'disabled' : '' ?> 
                               onchange="calculateTotals()">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quote Items -->
        <div class="form-section">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="section-title mb-0"><i class="bi bi-list-ul"></i>Quote Items</h3>
                <button type="button" class="btn btn-success" onclick="addQuoteItem()">
                    <i class="bi bi-plus-circle me-2"></i>Add Item
                </button>
            </div>

            <div class="table-responsive">
                <table class="table items-table" id="itemsTable">
                    <thead>
                        <tr>
                            <th>Item Name *</th>
                            <th>Description</th>
                            <th>Qty *</th>
                            <th>Unit Price *</th>
                            <th>Setup Fee</th>
                            <th>Billing</th>
                            <th>Total</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="itemsTableBody">
                        <?php foreach ($quote_items as $index => $item): ?>
                            <tr data-index="<?= $index ?>">
                                <td>
                                    <input type="hidden" name="items[<?= $index ?>][id]" value="<?= $item['id'] ?>">
                                    <input type="text" name="items[<?= $index ?>][name]" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars($item['name']) ?>" required onchange="calculateTotals()">
                                </td>
                                <td>
                                    <textarea name="items[<?= $index ?>][description]" class="form-control form-control-sm" 
                                              rows="2" onchange="calculateTotals()"><?= htmlspecialchars($item['description']) ?></textarea>
                                </td>
                                <td>
                                    <input type="number" name="items[<?= $index ?>][quantity]" class="form-control form-control-sm" 
                                           value="<?= $item['quantity'] ?>" min="1" required onchange="calculateTotals()">
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text currency-symbol"><?= $currency_symbol ?></span>
                                        <input type="number" name="items[<?= $index ?>][unit_price]" class="form-control" 
                                               value="<?= number_format($item['unit_price'], 2) ?>" step="0.01" min="0" required onchange="calculateTotals()">
                                    </div>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text currency-symbol"><?= $currency_symbol ?></span>
                                        <input type="number" name="items[<?= $index ?>][setup_fee]" class="form-control" 
                                               value="<?= number_format($item['setup_fee'], 2) ?>" step="0.01" min="0" onchange="calculateTotals()">
                                    </div>
                                </td>
                                <td>
                                    <select name="items[<?= $index ?>][billing_cycle]" class="form-select form-select-sm" onchange="calculateTotals()">
                                        <option value="one_time" <?= $item['billing_cycle'] === 'one_time' ? 'selected' : '' ?>>One Time</option>
                                        <option value="monthly" <?= $item['billing_cycle'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                        <option value="quarterly" <?= $item['billing_cycle'] === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                                        <option value="yearly" <?= $item['billing_cycle'] === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                                    </select>
                                </td>
                                <td>
                                    <strong class="text-primary item-total"><?= $currency_symbol ?><?= number_format($item['line_total'], 2) ?></strong>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="removeQuoteItem(<?= $index ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Quote Totals -->
            <div class="quote-totals">
                <div class="totals-row">
                    <span>Subtotal:</span>
                    <strong id="subtotalDisplay"><?= $currency_symbol ?><?= number_format($quote['subtotal'], 2) ?></strong>
                </div>
                <div class="totals-row" id="vatRow" style="<?= !$quote['vat_enabled'] ? 'display: none;' : '' ?>">
                    <span>VAT (<span id="vatRateDisplay"><?= number_format($quote['vat_rate'] * 100, 1) ?></span>%):</span>
                    <strong id="vatAmountDisplay"><?= $currency_symbol ?><?= number_format($quote['tax_amount'], 2) ?></strong>
                </div>
                <div class="totals-row">
                    <span>Total Amount:</span>
                    <strong id="totalDisplay"><?= $currency_symbol ?><?= number_format($quote['total_amount'], 2) ?></strong>
                </div>
            </div>
        </div>

        <!-- Additional Information -->
        <div class="form-section">
            <h3 class="section-title"><i class="bi bi-file-text"></i>Additional Information</h3>
            
            <div class="mb-3">
                <label for="terms_conditions" class="form-label">Terms & Conditions</label>
                <textarea name="terms_conditions" id="terms_conditions" class="form-control" rows="4" 
                          placeholder="Enter terms and conditions..."><?= htmlspecialchars($quote['terms_conditions']) ?></textarea>
            </div>

            <div class="mb-3">
                <label for="notes" class="form-label">Internal Notes</label>
                <textarea name="notes" id="notes" class="form-control" rows="3" 
                          placeholder="Internal notes (not visible to client)..."><?= htmlspecialchars($quote['notes']) ?></textarea>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="form-section">
            <div class="d-flex justify-content-between">
                <div>
                    <a href="view-quote.php?id=<?= $quote['id'] ?>" class="btn btn-outline-primary">
                        <i class="bi bi-x-circle me-2"></i>Cancel
                    </a>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary" onclick="previewQuote()">
                        <i class="bi bi-eye me-2"></i>Preview Changes
                    </button>
                    <button type="submit" name="update_quote" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>Update Quote
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
let itemIndex = <?= count($quote_items) ?>;
const supportedCurrencies = <?= json_encode($supportedCurrencies) ?>;

// Initialize exchange rates from PHP (database)
let exchangeRates = <?= json_encode($dbRates ?: []) ?>;
const baseCurrency = '<?= $baseCurrency ?>';
let currentCurrency = '<?= $quote_currency ?>';

console.log('Exchange rates loaded from database:', exchangeRates);
console.log('Base currency:', baseCurrency);
console.log('Current quote currency:', currentCurrency);

function updateCurrencySymbol() {
    const currencySelect = document.getElementById('currency');
    const newCurrency = currencySelect.value;
    const currencyInfo = supportedCurrencies[newCurrency];
    
    if (currencyInfo && newCurrency !== currentCurrency) {
        const symbol = currencyInfo.symbol;
        
        // Show conversion confirmation
        const shouldConvert = confirm(
            `Do you want to convert all prices from ${currentCurrency} to ${newCurrency}?\n\n` +
            `This will automatically update all item prices using current exchange rates from your database.\n\n` +
            `Click OK to convert, or Cancel to just change the currency symbol.`
        );
        
        if (shouldConvert) {
            convertAllPrices(currentCurrency, newCurrency);
        }
        
        // Update currency display
        document.getElementById('currencyText').textContent = symbol + ' ' + newCurrency;
        
        // Update all currency symbols in the table
        document.querySelectorAll('.currency-symbol').forEach(span => {
            span.textContent = symbol;
        });
        
        // Update current currency
        currentCurrency = newCurrency;
        
        calculateTotals();
    }
}

function convertAllPrices(fromCurrency, toCurrency) {
    // Get conversion rate from database
    const rate = getConversionRate(fromCurrency, toCurrency);
    
    if (rate === null) {
        alert(`Sorry, conversion rate from ${fromCurrency} to ${toCurrency} is not available in the database.`);
        return;
    }
    
    // Convert all unit prices
    document.querySelectorAll('input[name*="[unit_price]"]').forEach(input => {
        const currentValue = parseFloat(input.value) || 0;
        const convertedValue = currentValue * rate;
        input.value = convertedValue.toFixed(2);
    });
    
    // Convert all setup fees
    document.querySelectorAll('input[name*="[setup_fee]"]').forEach(input => {
        const currentValue = parseFloat(input.value) || 0;
        const convertedValue = currentValue * rate;
        input.value = convertedValue.toFixed(2);
    });
    
    // Show conversion info
    showConversionInfo(fromCurrency, toCurrency, rate);
    
    // Recalculate totals
    calculateTotals();
}

function getConversionRate(fromCurrency, toCurrency) {
    if (fromCurrency === toCurrency) return 1;
    
    console.log(`Converting from ${fromCurrency} to ${toCurrency}`);
    console.log('Available rates:', exchangeRates);
    
    // If converting from base currency
    if (fromCurrency === baseCurrency && exchangeRates[toCurrency]) {
        const rate = parseFloat(exchangeRates[toCurrency]);
        console.log(`Rate from base (${baseCurrency}) to ${toCurrency}:`, rate);
        return rate;
    }
    
    // If converting to base currency
    if (toCurrency === baseCurrency && exchangeRates[fromCurrency]) {
        const rate = 1 / parseFloat(exchangeRates[fromCurrency]);
        console.log(`Rate from ${fromCurrency} to base (${baseCurrency}):`, rate);
        return rate;
    }
    
    // Cross-currency conversion (via base currency)
    if (exchangeRates[fromCurrency] && exchangeRates[toCurrency]) {
        const fromRate = parseFloat(exchangeRates[fromCurrency]);
        const toRate = parseFloat(exchangeRates[toCurrency]);
        const rate = toRate / fromRate;
        console.log(`Cross-currency rate from ${fromCurrency} to ${toCurrency}:`, rate);
        return rate;
    }
    
    console.log('No conversion rate found');
    return null;
}

function showConversionInfo(fromCurrency, toCurrency, rate) {
    // Create and show conversion info banner
    const banner = document.createElement('div');
    banner.className = 'alert alert-info alert-dismissible fade show';
    banner.innerHTML = `
        <i class="bi bi-info-circle me-2"></i>
        <strong>Currency Converted!</strong> 
        All prices have been converted from ${fromCurrency} to ${toCurrency} using current database rate: 1 ${fromCurrency} = ${rate.toFixed(4)} ${toCurrency}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // Insert banner after page header
    const pageHeader = document.querySelector('.page-header');
    pageHeader.insertAdjacentElement('afterend', banner);
    
    // Auto-remove banner after 5 seconds
    setTimeout(() => {
        if (banner.parentNode) {
            banner.remove();
        }
    }, 5000);
}

function toggleVATRate() {
    const vatEnabled = document.getElementById('vat_enabled').checked;
    const vatRateInput = document.getElementById('vat_rate');
    const vatRow = document.getElementById('vatRow');
    
    vatRateInput.disabled = !vatEnabled;
    vatRow.style.display = vatEnabled ? 'flex' : 'none';
    
    if (!vatEnabled) {
        vatRateInput.value = '0.00';
    }
    
    calculateTotals();
}

function addQuoteItem() {
    const tbody = document.getElementById('itemsTableBody');
    const currencySelect = document.getElementById('currency');
    const selectedCurrency = currencySelect.value;
    const currencySymbol = supportedCurrencies[selectedCurrency]?.symbol || '£';
    
    const row = document.createElement('tr');
    row.setAttribute('data-index', itemIndex);
    row.innerHTML = `
        <td>
            <input type="text" name="items[${itemIndex}][name]" class="form-control form-control-sm" 
                   required onchange="calculateTotals()">
        </td>
        <td>
            <textarea name="items[${itemIndex}][description]" class="form-control form-control-sm" 
                      rows="2" onchange="calculateTotals()"></textarea>
        </td>
        <td>
            <input type="number" name="items[${itemIndex}][quantity]" class="form-control form-control-sm" 
                   value="1" min="1" required onchange="calculateTotals()">
        </td>
        <td>
            <div class="input-group input-group-sm">
                <span class="input-group-text currency-symbol">${currencySymbol}</span>
                <input type="number" name="items[${itemIndex}][unit_price]" class="form-control" 
                       value="0.00" step="0.01" min="0" required onchange="calculateTotals()">
            </div>
        </td>
        <td>
            <div class="input-group input-group-sm">
                <span class="input-group-text currency-symbol">${currencySymbol}</span>
                <input type="number" name="items[${itemIndex}][setup_fee]" class="form-control" 
                       value="0.00" step="0.01" min="0" onchange="calculateTotals()">
            </div>
        </td>
        <td>
            <select name="items[${itemIndex}][billing_cycle]" class="form-select form-select-sm" onchange="calculateTotals()">
                <option value="one_time">One Time</option>
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="yearly">Yearly</option>
            </select>
        </td>
        <td>
            <strong class="text-primary item-total">${currencySymbol}0.00</strong>
        </td>
        <td>
            <button type="button" class="btn btn-danger btn-sm" onclick="removeQuoteItem(${itemIndex})">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(row);
    itemIndex++;
    calculateTotals();
}

function removeQuoteItem(index) {
    if (confirm('Are you sure you want to remove this item?')) {
        const row = document.querySelector(`tr[data-index="${index}"]`);
        if (row) {
            row.remove();
            calculateTotals();
        }
    }
}

function calculateTotals() {
    const currencySelect = document.getElementById('currency');
    const selectedCurrency = currencySelect.value;
    const currencySymbol = supportedCurrencies[selectedCurrency]?.symbol || '£';
    
    let subtotal = 0;
    
    // Calculate each item total and update display
    document.querySelectorAll('#itemsTableBody tr').forEach(row => {
        const quantityInput = row.querySelector('input[name*="[quantity]"]');
        const unitPriceInput = row.querySelector('input[name*="[unit_price]"]');
        const setupFeeInput = row.querySelector('input[name*="[setup_fee]"]');
        const totalDisplay = row.querySelector('.item-total');
        
        if (quantityInput && unitPriceInput && totalDisplay) {
            const quantity = parseInt(quantityInput.value) || 0;
            const unitPrice = parseFloat(unitPriceInput.value) || 0;
            const setupFee = parseFloat(setupFeeInput?.value || 0);
            const itemTotal = (quantity * unitPrice) + setupFee;
            
            subtotal += itemTotal;
            totalDisplay.textContent = currencySymbol + itemTotal.toFixed(2);
        }
    });
    
    // Calculate VAT
    const vatEnabled = document.getElementById('vat_enabled').checked;
    const vatRate = vatEnabled ? (parseFloat(document.getElementById('vat_rate').value) || 0) / 100 : 0;
    const vatAmount = subtotal * vatRate;
    const totalAmount = subtotal + vatAmount;
    
    // Update displays
    document.getElementById('subtotalDisplay').textContent = currencySymbol + subtotal.toFixed(2);
    document.getElementById('vatRateDisplay').textContent = (vatRate * 100).toFixed(1);
    document.getElementById('vatAmountDisplay').textContent = currencySymbol + vatAmount.toFixed(2);
    document.getElementById('totalDisplay').textContent = currencySymbol + totalAmount.toFixed(2);
}

function previewQuote() {
    // Simple preview - could be enhanced with a modal
    const form = document.getElementById('editQuoteForm');
    const formData = new FormData(form);
    
    let preview = 'Quote Preview:\n\n';
    preview += 'Title: ' + formData.get('title') + '\n';
    preview += 'Currency: ' + formData.get('currency') + '\n';
    preview += 'VAT Enabled: ' + (formData.get('vat_enabled') ? 'Yes' : 'No') + '\n';
    
    if (formData.get('vat_enabled')) {
        preview += 'VAT Rate: ' + formData.get('vat_rate') + '%\n';
    }
    
    preview += '\nItems:\n';
    let itemCount = 0;
    for (let [key, value] of formData.entries()) {
        if (key.includes('[name]') && value.trim()) {
            itemCount++;
            preview += '- ' + value + '\n';
        }
    }
    
    preview += '\nTotal Items: ' + itemCount;
    
    alert(preview);
}

// Form validation
document.getElementById('editQuoteForm').addEventListener('submit', function(e) {
    let hasItems = false;
    document.querySelectorAll('input[name*="[name]"]').forEach(input => {
        if (input.value.trim()) {
            hasItems = true;
        }
    });
    
    if (!hasItems) {
        e.preventDefault();
        alert('Please add at least one quote item.');
        return false;
    }
    
    // Additional validation can be added here
    return true;
});

// Initialize totals calculation on page load
document.addEventListener('DOMContentLoaded', function() {
    calculateTotals();
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>