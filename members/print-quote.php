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
$quote_id = (int)($_GET['id'] ?? 0);

if (!$quote_id) {
    header('Location: quotes.php?error=' . urlencode('Quote not found'));
    exit;
}

// Get supported currencies - FIXED to use system_config table
$supportedCurrencies = [];
try {
    $stmt = $pdo->query("SELECT config_key, config_value FROM system_config WHERE category = 'currency' AND config_key = 'currency.supported_currencies'");
    $currencyRow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($currencyRow) {
        $supportedCurrencies = json_decode($currencyRow['config_value'], true);
    }
    
    if (empty($supportedCurrencies)) {
        throw new Exception("No currency data found");
    }
} catch (Exception $e) {
    $supportedCurrencies = [
        'GBP' => ['symbol' => '£', 'name' => 'British Pound'],
        'USD' => ['symbol' => '$', 'name' => 'US Dollar'],
        'EUR' => ['symbol' => '€', 'name' => 'Euro'],
        'CAD' => ['symbol' => 'C$', 'name' => 'Canadian Dollar'],
        'AUD' => ['symbol' => 'A$', 'name' => 'Australian Dollar']
    ];
}

// Get quote details with access control - NOW INCLUDES ALL COMPANY FIELDS
$stmt = $pdo->prepare("SELECT q.*, 
    c.name as company_name, 
    c.phone as company_phone, 
    c.address as company_address,
    c.contact_email as company_email,
    c.website as company_website,
    c.industry as company_industry,
    c.preferred_currency,
    u.username as staff_name, 
    u.email as staff_email
    FROM quotes q
    JOIN companies c ON q.company_id = c.id
    JOIN users u ON q.staff_id = u.id
    WHERE q.id = ? AND (
        q.company_id = (SELECT company_id FROM users WHERE id = ?) 
        OR q.company_id IN (SELECT company_id FROM company_users WHERE user_id = ?)
    )");
$stmt->execute([$quote_id, $user_id, $user_id]);
$quote = $stmt->fetch();

if (!$quote) {
    header('Location: quotes.php?error=' . urlencode('Quote not found or access denied'));
    exit;
}

// Function to clean placeholder text
function cleanPlaceholderText($value, $placeholders = ['Phone no', 'Address', 'Website', 'Email']) {
    if (empty($value) || in_array(trim($value), $placeholders)) {
        return null;
    }
    return trim($value);
}

// Clean company data
$company_phone = cleanPlaceholderText($quote['company_phone']);
$company_address = cleanPlaceholderText($quote['company_address']);  
$company_email = cleanPlaceholderText($quote['company_email']);
$company_website = cleanPlaceholderText($quote['company_website']);

// Get quote items
$stmt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id ASC");
$stmt->execute([$quote_id]);
$quote_items = $stmt->fetchAll();

// Calculate totals
$setup_fees_total = array_sum(array_map(function($item) { 
    return ($item['setup_fee'] ?? 0) * $item['quantity']; 
}, $quote_items));

// Get currency information
$quote_currency = $quote['currency'] ?? 'GBP';
$currency_symbol = $supportedCurrencies[$quote_currency]['symbol'] ?? '£';
$currency_name = $supportedCurrencies[$quote_currency]['name'] ?? $quote_currency;

// Get business information from system_config
$business_info = [];
try {
    $stmt = $pdo->query("SELECT config_key, config_value FROM system_config WHERE category = 'business'");
    $business_config = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($business_config as $row) {
        $key = str_replace('business.', '', $row['config_key']);
        $business_info[$key] = $row['config_value'];
    }
} catch (Exception $e) {
    error_log("Error loading business config: " . $e->getMessage());
}

// Set business details using the correct keys from your database
$business_details = [
    'company_name' => $business_info['company_name'] ?? 'CaminhoIT',
    'company_address' => $business_info['company_address'] ?? '82A James Carter Road, Mildenhall, United Kingdom, IP28 7DE',
    'company_phone' => $business_info['company_phone'] ?? '',
    'company_email' => $business_info['company_email'] ?? 'support@caminhoit.com',
    'company_website' => $business_info['company_website'] ?? 'www.caminhoit.com'
];

$page_title = "Print Quote #" . $quote['quote_number'] . " | " . $business_details['company_name'];
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
        /* CUTE & COMPACT PRINT STYLES */
        @page {
            size: A4;
            margin: 15mm;
        }

        body {
            background: white;
            color: #000;
            font-family: 'Arial', sans-serif;
            line-height: 1.4;
            font-size: 10pt;
            margin: 0;
            padding: 0;
        }

        .print-container {
            max-width: 100%;
            margin: 0;
            padding: 20px;
            background: white;
            border: 2px solid #667eea;
            border-radius: 8px;
        }

        /* Cute purple gradient header */
        .quote-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            margin: -20px -20px 15px -20px;
            border-radius: 6px 6px 0 0;
        }

        .company-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .company-logo .logo {
            width: 45px;
            height: 45px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20pt;
            font-weight: bold;
        }

        .company-info h1 {
            color: white;
            margin: 0;
            font-size: 16pt;
            font-weight: bold;
        }

        .company-info p {
            margin: 0;
            font-size: 9pt;
            opacity: 0.9;
        }

        .quote-details {
            text-align: right;
        }

        .quote-number {
            font-size: 18pt;
            font-weight: bold;
            color: white;
            margin-bottom: 5px;
        }

        .quote-date {
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 5px;
            font-size: 9pt;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 8pt;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
            background: rgba(255, 255, 255, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }

        /* Two column layout */
        .billing-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 15px;
        }

        .billing-info h3 {
            color: #667eea;
            margin-bottom: 8px;
            font-size: 10pt;
            font-weight: 700;
            text-transform: uppercase;
            border-bottom: 2px solid #667eea;
            padding-bottom: 4px;
        }

        .billing-info p {
            margin: 3px 0;
            color: #374151;
            font-size: 9pt;
            line-height: 1.5;
        }

        .contact-info {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #e5e7eb;
        }

        .contact-info .label {
            font-weight: 600;
            color: #6b7280;
            display: inline-block;
            min-width: 50px;
            font-size: 8.5pt;
        }

        .quote-title-section {
            margin-bottom: 12px;
            text-align: center;
        }

        .quote-title-section h2 {
            color: #667eea;
            margin: 0;
            font-size: 13pt;
        }

        .quote-description {
            background: #fffbeb;
            border-left: 4px solid #f59e0b;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 12px;
            font-size: 9pt;
        }

        .quote-description h4 {
            color: #f59e0b;
            margin: 0 0 8px 0;
            font-size: 10pt;
        }

        .quote-description p {
            margin: 0;
            line-height: 1.5;
        }

        .currency-info {
            background: #f0fdf4;
            border-left: 4px solid #10b981;
            padding: 8px 12px;
            border-radius: 4px;
            margin-bottom: 12px;
            font-size: 8.5pt;
        }

        .currency-info h6 {
            margin: 0 0 5px 0;
            font-size: 9pt;
            color: #10b981;
        }

        /* Compact table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0;
            font-size: 9pt;
        }

        .items-table th {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 8px 10px;
            text-align: left;
            font-weight: 600;
            font-size: 8.5pt;
        }

        .items-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        .items-table tr:last-child td {
            border-bottom: none;
        }

        .items-table tr:nth-child(even) {
            background: #f9fafb;
        }

        .item-name {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 3px;
        }

        .item-description {
            color: #6b7280;
            font-size: 8pt;
            line-height: 1.4;
        }

        .quantity {
            text-align: center;
            font-weight: 600;
        }

        .price {
            text-align: right;
            font-weight: 600;
        }

        /* Compact totals */
        .totals-section {
            max-width: 350px;
            margin-left: auto;
            margin-bottom: 15px;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
            background: #f9fafb;
            border: 2px solid #667eea;
            border-radius: 8px;
            overflow: hidden;
        }

        .totals-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 9.5pt;
        }

        .totals-table .total-row {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #667eea;
            font-weight: bold;
            font-size: 11pt;
            border-top: 2px solid #667eea;
        }

        .totals-table .total-row td {
            border-bottom: none;
            padding: 10px 12px;
        }

        /* Compact footer */
        .footer-section {
            margin-top: 15px;
            padding-top: 12px;
            border-top: 2px solid #e5e7eb;
            font-size: 8pt;
        }

        .terms {
            margin-bottom: 12px;
        }

        .terms h4 {
            color: #667eea;
            margin-bottom: 8px;
            font-size: 10pt;
        }

        .terms p {
            line-height: 1.5;
            color: #374151;
        }

        .business-footer {
            text-align: center;
            padding: 12px;
            background: #f9fafb;
            border-radius: 8px;
            margin-top: 12px;
            font-size: 8pt;
        }

        .business-footer small {
            font-size: 7.5pt;
            color: #6b7280;
        }

        /* Billing cycle badge */
        .billing-cycle-badge {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 7.5pt;
            font-weight: 600;
            display: inline-block;
        }

        .no-print {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }

        /* Print styles - keep it cute! */
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
                padding: 20px;
                border: 2px solid #667eea;
            }

            /* Prevent page breaks in critical areas */
            .quote-header,
            .billing-section,
            .totals-section,
            .footer-section {
                page-break-inside: avoid !important;
            }

            .items-table thead {
                display: table-header-group;
            }

            /* Ensure colors print correctly */
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .quote-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
                color: white !important;
            }

            .items-table th {
                background: linear-gradient(135deg, #667eea, #764ba2) !important;
                color: white !important;
            }

            .totals-table .total-row {
                background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%) !important;
                color: #667eea !important;
            }
        }

        @media (max-width: 768px) {
            .print-container {
                padding: 15px;
            }

            .quote-header {
                flex-direction: column;
                gap: 15px;
                padding: 12px 15px;
            }

            .quote-details {
                text-align: left;
            }

            .billing-section {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .items-table {
                font-size: 8pt;
            }

            .items-table th,
            .items-table td {
                padding: 6px 8px;
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
            <a href="view-quote.php?id=<?= $quote['id'] ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-2"></i>Back to Quote
            </a>
        </div>
    </div>

    <div class="print-container">
        <!-- Quote Header -->
        <div class="quote-header">
            <div class="company-logo">
                <div class="logo"><?= strtoupper(substr($business_details['company_name'], 0, 1)) ?></div>
                <div class="company-info">
                    <h1><?= htmlspecialchars($business_details['company_name']) ?></h1>
                    <p class="mb-0">Professional IT Services</p>
                </div>
            </div>
            <div class="quote-details">
                <div class="quote-number">Quote #<?= htmlspecialchars($quote['quote_number']) ?></div>
                <div class="quote-date">Date: <?= date('d M Y', strtotime($quote['created_at'])) ?></div>
                <?php if ($quote['valid_until']): ?>
                    <div class="quote-date">Valid Until: <?= date('d M Y', strtotime($quote['valid_until'])) ?></div>
                <?php endif; ?>
                <div class="mt-2">
                    <span class="status-badge status-<?= $quote['status'] ?>">
                        <?= strtoupper($quote['status']) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Billing Information -->
        <div class="billing-section">
            <div class="billing-info">
                <h3>From:</h3>
                <p><strong><?= htmlspecialchars($business_details['company_name']) ?></strong></p>
                <p><?= nl2br(htmlspecialchars($business_details['company_address'])) ?></p>
                
                <div class="contact-info">
                    <?php if ($business_details['company_phone']): ?>
                        <p><span class="label">Phone:</span> <?= htmlspecialchars($business_details['company_phone']) ?></p>
                    <?php endif; ?>
                    <p><span class="label">Email:</span> <?= htmlspecialchars($business_details['company_email']) ?></p>
                    <p><span class="label">Web:</span> <?= htmlspecialchars($business_details['company_website']) ?></p>
                </div>
            </div>
            <div class="billing-info">
                <h3>Quote For:</h3>
                <p><strong><?= htmlspecialchars($quote['company_name']) ?></strong></p>
                
                <?php if ($company_address): ?>
                    <p><?= nl2br(htmlspecialchars($company_address)) ?></p>
                <?php else: ?>
                    <p class="text-muted">Address not provided</p>
                <?php endif; ?>
                
                <div class="contact-info">
                    <?php if ($company_phone): ?>
                        <p><span class="label">Phone:</span> <?= htmlspecialchars($company_phone) ?></p>
                    <?php else: ?>
                        <p><span class="label">Phone:</span> <span class="text-muted">Not provided</span></p>
                    <?php endif; ?>
                    
                    <?php if ($company_email): ?>
                        <p><span class="label">Email:</span> <?= htmlspecialchars($company_email) ?></p>
                    <?php else: ?>
                        <p><span class="label">Email:</span> <span class="text-muted">Not provided</span></p>
                    <?php endif; ?>
                    
                    <?php if ($company_website): ?>
                        <p><span class="label">Website:</span> <?= htmlspecialchars($company_website) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quote Title and Description -->
        <?php if ($quote['title']): ?>
            <div class="quote-title-section">
                <h2><?= htmlspecialchars($quote['title']) ?></h2>
            </div>
        <?php endif; ?>

        <?php if ($quote['description']): ?>
            <div class="quote-description">
                <h4 style="color: var(--primary-color); margin-bottom: 1rem;">
                    <i class="bi bi-file-text me-2"></i>Project Description
                </h4>
                <p><?= nl2br(htmlspecialchars($quote['description'])) ?></p>
            </div>
        <?php endif; ?>

        <!-- Currency Information -->
        <div class="currency-info">
            <h6 class="mb-2"><i class="bi bi-currency-exchange me-2"></i>Currency Information</h6>
            <div class="row">
                <div class="col-md-6">
                    <strong>Quote Currency:</strong> <?= $currency_symbol ?> <?= $quote_currency ?> (<?= $currency_name ?>)
                </div>
                <div class="col-md-6">
                    <strong>All prices quoted in <?= $quote_currency ?></strong>
                </div>
            </div>
        </div>

        <!-- Quote Items -->
        <h3 style="color: #667eea; margin-bottom: 10px; font-size: 11pt; font-weight: 700; text-transform: uppercase; border-bottom: 2px solid #667eea; padding-bottom: 4px;">
            <i class="bi bi-list-ul me-2"></i>Itemized Quote
        </h3>
        
        <?php if (!empty($quote_items)): ?>
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 30%;">Item</th>
                        <th style="width: 25%;">Description</th>
                        <th style="width: 10%; text-align: center;">Qty</th>
                        <th style="width: 12%; text-align: right;">Unit Price</th>
                        <th style="width: 10%; text-align: right;">Setup Fee</th>
                        <th style="width: 8%; text-align: center;">Billing</th>
                        <th style="width: 15%; text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quote_items as $item): ?>
                        <tr>
                            <td>
                                <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                            </td>
                            <td>
                                <?php if ($item['description']): ?>
                                    <div class="item-description"><?= htmlspecialchars($item['description']) ?></div>
                                <?php else: ?>
                                    <span class="text-muted">No description</span>
                                <?php endif; ?>
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
                            <td style="text-align: center;">
                                <span style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 3px 8px; border-radius: 10px; font-size: 7.5pt; font-weight: 600;">
                                    <?= ucfirst(str_replace('_', ' ', $item['billing_cycle'])) ?>
                                </span>
                            </td>
                            <td class="price">
                                <?= $currency_symbol ?><?= number_format($item['line_total'] + (($item['setup_fee'] ?? 0) * $item['quantity']), 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="text-center py-4">
                <p class="text-muted">No items in this quote</p>
            </div>
        <?php endif; ?>

        <!-- Quote Summary -->
        <div class="totals-section">
            <table class="totals-table">
                <tr>
                    <td>Subtotal:</td>
                    <td style="text-align: right;"><?= $currency_symbol ?><?= number_format($quote['subtotal'], 2) ?></td>
                </tr>
                <?php if ($setup_fees_total > 0): ?>
                    <tr>
                        <td>Total Setup Fees:</td>
                        <td style="text-align: right;"><?= $currency_symbol ?><?= number_format($setup_fees_total, 2) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($quote['vat_enabled'] && $quote['tax_amount'] > 0): ?>
                    <tr>
                        <td>VAT (<?= number_format($quote['vat_rate'] * 100, 1) ?>%):</td>
                        <td style="text-align: right;"><?= $currency_symbol ?><?= number_format($quote['tax_amount'], 2) ?></td>
                    </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td><strong>Total Amount:</strong></td>
                    <td style="text-align: right;"><strong><?= $currency_symbol ?><?= number_format($quote['total_amount'], 2) ?></strong></td>
                </tr>
            </table>
        </div>

        <!-- Terms & Conditions -->
        <div class="footer-section">
            <div class="terms">
                <h4>Terms & Conditions</h4>
                <?php if ($quote['terms_conditions']): ?>
                    <p><?= nl2br(htmlspecialchars($quote['terms_conditions'])) ?></p>
                <?php else: ?>
                    <p>
                        1. This quote is valid for 30 days from the date shown above unless otherwise specified.<br>
                        2. All prices are subject to VAT where applicable.<br>
                        3. Setup fees are one-time charges applied when services are first provisioned.<br>
                        4. Recurring charges are billed in advance according to the billing cycle specified.<br>
                        5. Payment terms are Net 30 days from invoice date.<br>
                        6. All work is subject to our standard Terms of Service.
                    </p>
                <?php endif; ?>
            </div>

            <div class="business-footer">
                <p>
                    <strong><?= htmlspecialchars($business_details['company_name']) ?></strong><br>
                    <?php if ($business_details['company_phone']): ?>
                        <?= htmlspecialchars($business_details['company_phone']) ?> |
                    <?php endif; ?>
                    <?= htmlspecialchars($business_details['company_email']) ?> | <?= htmlspecialchars($business_details['company_website']) ?>
                </p>
                <p class="mt-2 mb-0">
                    <small>
                        This quote was generated on <?= date('d M Y \a\t H:i T') ?> |
                        Quote ID: <?= $quote['id'] ?> |
                        Generated for: <?= htmlspecialchars($_SESSION['user']['username']) ?>
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

    // Handle after print
    window.addEventListener('afterprint', function() {
        console.log('Quote printed: <?= $quote["quote_number"] ?>');
    });
    </script>
</body>
</html>