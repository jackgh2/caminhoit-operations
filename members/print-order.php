<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

// Access control (Administrator and Account Manager only)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'account_manager'])) {
    header('Location: /login.php');
    exit;
}

$user_id = $_SESSION['user']['id'];
$order_id = (int)($_GET['id'] ?? 0);

if (!$order_id) {
    header('Location: orders.php?error=' . urlencode('Order not found'));
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
    
    $vatSettings = [
        'enabled' => ConfigManager::isVatRegistered(),
        'default_rate' => ConfigManager::get('tax.default_vat_rate', 0.20),
        'currency_settings' => ConfigManager::get('tax.currency_vat_settings', [
            'GBP' => ['enabled' => true, 'rate' => 0.20],
            'USD' => ['enabled' => false, 'rate' => 0.00],
            'EUR' => ['enabled' => true, 'rate' => 0.21],
            'CAD' => ['enabled' => false, 'rate' => 0.00],
            'AUD' => ['enabled' => false, 'rate' => 0.00]
        ])
    ];
} else {
    // Fallback
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
        'EUR' => 1.17,
        'CAD' => 1.71,
        'AUD' => 1.91
    ];
    
    $vatSettings = [
        'enabled' => true,
        'default_rate' => 0.20,
        'currency_settings' => [
            'GBP' => ['enabled' => true, 'rate' => 0.20],
            'USD' => ['enabled' => false, 'rate' => 0.00],
            'EUR' => ['enabled' => true, 'rate' => 0.21],
            'CAD' => ['enabled' => false, 'rate' => 0.00],
            'AUD' => ['enabled' => false, 'rate' => 0.00]
        ]
    ];
}

// Get order details with access control - using only existing columns
$stmt = $pdo->prepare("SELECT o.*, c.name as company_name, c.phone as company_phone, 
    c.address as company_address, c.preferred_currency, c.currency_override,
    COALESCE(u.username, 'System') as staff_name, u.email as staff_email
    FROM orders o
    JOIN companies c ON o.company_id = c.id
    LEFT JOIN users u ON o.staff_id = u.id
    WHERE o.id = ? AND (
        o.company_id = (SELECT company_id FROM users WHERE id = ?) 
        OR o.company_id IN (SELECT company_id FROM company_users WHERE user_id = ?)
    )");
$stmt->execute([$order_id, $user_id, $user_id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: orders.php?error=' . urlencode('Order not found or access denied'));
    exit;
}

// Get order items
$stmt = $pdo->prepare("SELECT oi.*, p.name as product_name, b.name as bundle_name
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.id
    LEFT JOIN service_bundles b ON oi.bundle_id = b.id
    WHERE oi.order_id = ?
    ORDER BY oi.created_at ASC");
$stmt->execute([$order_id]);
$order_items = $stmt->fetchAll();

// Calculate totals
$setup_fees_total = array_sum(array_map(function($item) { 
    return ($item['setup_fee'] ?? 0) * $item['quantity']; 
}, $order_items));

// Get currency information
$order_currency = $order['currency'] ?? $defaultCurrency;
$currency_symbol = $supportedCurrencies[$order_currency]['symbol'] ?? '£';
$currency_name = $supportedCurrencies[$order_currency]['name'] ?? $order_currency;

// Get VAT information
$vat_enabled = $order['vat_enabled'] ?? false;
$vat_rate = $order['vat_rate'] ?? 0.20;
$vat_percentage = ($vat_rate * 100);

// Get exchange rate information
$exchange_rate = 1.0;
$is_converted = false;
if ($order_currency !== $defaultCurrency) {
    $exchange_rate = $exchangeRates[$order_currency] ?? 1.0;
    $is_converted = true;
}

// Get business information from ConfigManager if available, otherwise use defaults
$business_name = "CaminhoIT";
$business_address = "82A James Carter Road\nMildenhall, United Kingdom\nIP28 7DE";
$business_phone = "";
$business_email = "support@caminhoit.com";
$business_website = "www.caminhoit.com";

// Try to get actual business info from ConfigManager
if (class_exists('ConfigManager')) {
    $business_name = ConfigManager::get('business.company_name', 'CaminhoIT');
    $business_address = ConfigManager::get('business.company_address', $business_address);
    $business_phone = ConfigManager::get('business.company_phone', '');
    $business_email = ConfigManager::get('business.company_email', 'support@caminhoit.com');
    $business_website = ConfigManager::get('business.website', 'www.caminhoit.com');
}

$page_title = "Print Order #" . $order['order_number'] . " | CaminhoIT";
?>
<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #4F46E5;
            --success-color: #10B981;
            --warning-color: #F59E0B;
            --danger-color: #EF4444;
            --info-color: #06B6D4;
        }

        body {
            background: white;
            color: #333;
            font-family: 'Arial', sans-serif;
            line-height: 1.6;
        }

        .print-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
            background: white;
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 3rem;
            padding-bottom: 2rem;
            border-bottom: 3px solid var(--primary-color);
        }

        .company-logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .company-logo .logo {
            width: 60px;
            height: 60px;
            background: var(--primary-color);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            font-weight: bold;
        }

        .company-info h1 {
            color: var(--primary-color);
            margin: 0;
            font-size: 2rem;
            font-weight: bold;
        }

        .invoice-details {
            text-align: right;
        }

        .invoice-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .invoice-date {
            color: #666;
            margin-bottom: 0.5rem;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }

        .status-draft { background: #f3f4f6; color: #374151; }
        .status-placed { background: #dbeafe; color: #1e40af; }
        .status-pending_payment { background: #fef3c7; color: #92400e; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-processing { background: #e0e7ff; color: #3730a3; }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }

        .billing-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            margin-bottom: 3rem;
        }

        .billing-info h3 {
            color: var(--primary-color);
            margin-bottom: 1rem;
            font-size: 1.25rem;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 0.5rem;
        }

        .billing-info p {
            margin: 0.25rem 0;
            color: #555;
        }

        .order-details-section {
            margin-bottom: 3rem;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: #374151;
        }

        .detail-value {
            color: #6b7280;
        }

        .currency-info {
            background: linear-gradient(135deg, #f0f9ff 0%, #ffffff 100%);
            border: 1px solid #3b82f6;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 2rem;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .items-table th {
            background: var(--primary-color);
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
        }

        .items-table td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .items-table tr:last-child td {
            border-bottom: none;
        }

        .items-table tr:nth-child(even) {
            background: #f8fafc;
        }

        .item-name {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.25rem;
        }

        .item-description {
            color: #6b7280;
            font-size: 0.875rem;
        }

        .quantity {
            text-align: center;
            font-weight: 600;
        }

        .price {
            text-align: right;
            font-weight: 600;
        }

        .totals-section {
            max-width: 400px;
            margin-left: auto;
            margin-bottom: 3rem;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .totals-table .total-row {
            background: var(--primary-color);
            color: white;
            font-weight: bold;
            font-size: 1.1rem;
        }

        .totals-table .total-row td {
            border-bottom: none;
        }

        .footer-section {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 2px solid #e2e8f0;
            color: #6b7280;
            font-size: 0.875rem;
        }

        .terms {
            margin-bottom: 2rem;
        }

        .terms h4 {
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        .business-footer {
            text-align: center;
            padding: 2rem;
            background: #f8fafc;
            border-radius: 8px;
            margin-top: 2rem;
        }

        .no-print {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }

        /* Print styles */
        @media print {
            body {
                margin: 0;
                padding: 0;
                background: white !important;
            }
            
            .no-print {
                display: none !important;
            }
            
            .print-container {
                max-width: none;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }
            
            .invoice-header {
                break-inside: avoid;
            }
            
            .billing-section {
                break-inside: avoid;
            }
            
            .items-table {
                break-inside: auto;
            }
            
            .items-table thead {
                display: table-header-group;
            }
            
            .totals-section {
                break-inside: avoid;
            }
            
            .footer-section {
                break-inside: avoid;
            }
            
            /* Ensure colors print correctly */
            * {
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            
            h1, h2, h3, h4, h5, h6 {
                color: #4F46E5 !important;
            }
            
            .items-table th {
                background: #4F46E5 !important;
                color: white !important;
            }
            
            .status-badge {
                border: 1px solid #ccc !important;
            }
        }

        @media (max-width: 768px) {
            .print-container {
                padding: 1rem;
            }
            
            .invoice-header {
                flex-direction: column;
                gap: 2rem;
            }
            
            .invoice-details {
                text-align: left;
            }
            
            .billing-section {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .items-table {
                font-size: 0.875rem;
            }
            
            .items-table th,
            .items-table td {
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Print/Download Controls -->
    <div class="no-print">
        <div class="btn-group">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer me-2"></i>Print
            </button>
            <a href="view-order.php?id=<?= $order['id'] ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-2"></i>Back to Order
            </a>
        </div>
    </div>

    <div class="print-container">
        <!-- Invoice Header -->
        <div class="invoice-header">
            <div class="company-logo">
                <div class="logo">C</div>
                <div class="company-info">
                    <h1><?= htmlspecialchars($business_name) ?></h1>
                    <p class="mb-0">Professional IT Services</p>
                </div>
            </div>
            <div class="invoice-details">
                <div class="invoice-number">Order #<?= htmlspecialchars($order['order_number']) ?></div>
                <div class="invoice-date">Date: <?= date('d M Y', strtotime($order['created_at'])) ?></div>
                <div class="mt-2">
                    <span class="status-badge status-<?= $order['status'] ?>">
                        <?= strtoupper(str_replace('_', ' ', $order['status'])) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Billing Information -->
        <div class="billing-section">
            <div class="billing-info">
                <h3>From:</h3>
                <p><strong><?= htmlspecialchars($business_name) ?></strong></p>
                <p><?= nl2br(htmlspecialchars($business_address)) ?></p>
                <?php if ($business_phone): ?>
                    <p>Phone: <?= htmlspecialchars($business_phone) ?></p>
                <?php endif; ?>
                <p>Email: <?= htmlspecialchars($business_email) ?></p>
                <p>Web: <?= htmlspecialchars($business_website) ?></p>
            </div>
            <div class="billing-info">
                <h3>Bill To:</h3>
                <p><strong><?= htmlspecialchars($order['company_name']) ?></strong></p>
                <?php if ($order['company_address']): ?>
                    <p><?= nl2br(htmlspecialchars($order['company_address'])) ?></p>
                <?php endif; ?>
                <?php if ($order['company_phone']): ?>
                    <p>Phone: <?= htmlspecialchars($order['company_phone']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Order Details -->
        <div class="order-details-section">
            <h3 style="color: var(--primary-color); margin-bottom: 1rem;">Order Details</h3>
            <div class="details-grid">
                <div>
                    <div class="detail-item">
                        <span class="detail-label">Order Type:</span>
                        <span class="detail-value"><?= ucfirst(str_replace('_', ' ', $order['order_type'])) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Billing Cycle:</span>
                        <span class="detail-value"><?= ucfirst($order['billing_cycle']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Service Start:</span>
                        <span class="detail-value"><?= date('d M Y', strtotime($order['start_date'])) ?></span>
                    </div>
                </div>
                <div>
                    <div class="detail-item">
                        <span class="detail-label">Created By:</span>
                        <span class="detail-value"><?= htmlspecialchars($order['staff_name']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Order Date:</span>
                        <span class="detail-value"><?= date('d M Y H:i', strtotime($order['created_at'])) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Currency:</span>
                        <span class="detail-value"><?= $currency_symbol ?> <?= $order_currency ?></span>
                    </div>
                </div>
            </div>

            <!-- Currency Information -->
            <?php if ($is_converted || $vat_enabled): ?>
                <div class="currency-info">
                    <h6 style="margin-bottom: 1rem; color: var(--primary-color);">
                        <i class="bi bi-info-circle me-2"></i>Pricing Information
                    </h6>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Order Currency:</strong> <?= $currency_name ?> (<?= $order_currency ?>)<br>
                            <?php if ($is_converted): ?>
                                <strong>Exchange Rate:</strong> 1 <?= $defaultCurrency ?> = <?= number_format($exchange_rate, 4) ?> <?= $order_currency ?>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <strong>VAT Status:</strong> 
                            <?php if ($vat_enabled): ?>
                                Applied (<?= number_format($vat_percentage, 1) ?>%)
                            <?php else: ?>
                                Not applicable
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Order Items -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 50%;">Item</th>
                    <th style="width: 15%; text-align: center;">Quantity</th>
                    <th style="width: 15%; text-align: right;">Unit Price</th>
                    <th style="width: 10%; text-align: right;">Setup Fee</th>
                    <th style="width: 15%; text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($order_items as $item): ?>
                    <tr>
                        <td>
                            <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                            <?php if ($item['description']): ?>
                                <div class="item-description"><?= htmlspecialchars($item['description']) ?></div>
                            <?php endif; ?>
                            <small class="text-muted">
                                <?= ucfirst($item['billing_cycle']) ?> billing
                                <?php if ($is_converted): ?>
                                    • Currency converted
                                <?php endif; ?>
                            </small>
                        </td>
                        <td class="quantity"><?= $item['quantity'] ?></td>
                        <td class="price"><?= $currency_symbol ?><?= number_format($item['unit_price'], 2) ?></td>
                        <td class="price">
                            <?php if (($item['setup_fee'] ?? 0) > 0): ?>
                                <?= $currency_symbol ?><?= number_format($item['setup_fee'], 2) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td class="price">
                            <?= $currency_symbol ?><?= number_format($item['line_total'] + (($item['setup_fee'] ?? 0) * $item['quantity']), 2) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals-section">
            <table class="totals-table">
                <tr>
                    <td>Subtotal:</td>
                    <td style="text-align: right;"><?= $currency_symbol ?><?= number_format($order['subtotal'], 2) ?></td>
                </tr>
                <?php if ($setup_fees_total > 0): ?>
                    <tr>
                        <td>Setup Fees:</td>
                        <td style="text-align: right;"><?= $currency_symbol ?><?= number_format($setup_fees_total, 2) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($vat_enabled): ?>
                    <tr>
                        <td>VAT (<?= number_format($vat_percentage, 1) ?>%):</td>
                        <td style="text-align: right;"><?= $currency_symbol ?><?= number_format($order['tax_amount'], 2) ?></td>
                    </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td><strong>Total Amount:</strong></td>
                    <td style="text-align: right;"><strong><?= $currency_symbol ?><?= number_format($order['total_amount'], 2) ?></strong></td>
                </tr>
            </table>

            <!-- Currency Conversion Summary -->
            <?php if ($is_converted): ?>
                <div class="mt-3 p-3 bg-light rounded">
                    <small>
                        <strong>Currency Conversion Summary:</strong><br>
                        Base Amount: <?= $supportedCurrencies[$defaultCurrency]['symbol'] ?><?= number_format($order['total_amount'] / $exchange_rate, 2) ?> <?= $defaultCurrency ?><br>
                        Converted Amount: <?= $currency_symbol ?><?= number_format($order['total_amount'], 2) ?> <?= $order_currency ?><br>
                        Exchange Rate: <?= number_format($exchange_rate, 4) ?>
                    </small>
                </div>
            <?php endif; ?>
        </div>

        <!-- Order Notes -->
        <?php if ($order['notes']): ?>
            <div class="order-notes" style="margin-bottom: 3rem;">
                <h4 style="color: var(--primary-color); margin-bottom: 1rem;">Order Notes</h4>
                <div style="background: #f8fafc; padding: 1rem; border-radius: 8px; border-left: 4px solid var(--primary-color);">
                    <?= nl2br(htmlspecialchars($order['notes'])) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Footer Section -->
        <div class="footer-section">
            <div class="terms">
                <h4>Terms & Conditions</h4>
                <p>
                    1. Payment is due within 30 days of invoice date unless otherwise agreed.<br>
                    2. All services are provided subject to our standard Terms of Service available at <?= htmlspecialchars($business_website) ?><br>
                    3. Setup fees are non-refundable once services have been provisioned.<br>
                    4. Monthly/recurring charges are billed in advance and are non-refundable.<br>
                    5. Currency conversion rates are applied at the time of order creation.
                </p>
            </div>

            <div class="business-footer">
                <p>
                    <strong><?= htmlspecialchars($business_name) ?></strong><br>
                    <?php if ($business_phone): ?>
                        <?= htmlspecialchars($business_phone) ?> |
                    <?php endif; ?>
                    <?= htmlspecialchars($business_email) ?> | <?= htmlspecialchars($business_website) ?>
                </p>
                <p class="mt-2 mb-0">
                    <small>
                        This document was generated on <?= date('d M Y \a\t H:i T') ?> |
                        Order ID: <?= $order['id'] ?> |
                        Generated by: <?= htmlspecialchars($_SESSION['user']['username']) ?>
                    </small>
                </p>
            </div>
        </div>
    </div>

    <script>
    // Auto-print functionality
    document.addEventListener('DOMContentLoaded', function() {
        // Check if auto-print is requested
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('auto_print') === '1') {
            setTimeout(() => {
                window.print();
            }, 500);
        }
    });

    // Print button functionality
    function printOrder() {
        window.print();
    }

    // Handle after print
    window.addEventListener('afterprint', function() {
        console.log('Order printed: <?= $order["order_number"] ?>');
    });
    </script>
</body>
</html>