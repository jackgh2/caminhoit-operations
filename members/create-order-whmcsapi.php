<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/whmcs-config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /login.php');
    exit;
}

// Check if user has access (administrator or account_manager only)
if (!in_array($user['role'], ['administrator', 'account_manager'])) {
    header('Location: /members/dashboard.php');
    exit;
}

$user_id = $user['id'];

// Function to get system config value
function getSystemConfig($pdo, $key, $default = null) {
    try {
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        
        if ($result !== false) {
            if ($result === 'true') return true;
            if ($result === 'false') return false;
            if (is_numeric($result)) {
                return strpos($result, '.') !== false ? (float)$result : (int)$result;
            }
            return $result;
        }
        return $default;
    } catch (Exception $e) {
        error_log("Failed to get system config for key '$key': " . $e->getMessage());
        return $default;
    }
}

// Get VAT settings from system_config table
$vatRegistered = getSystemConfig($pdo, 'tax.vat_registered', false);
$defaultVatRate = getSystemConfig($pdo, 'tax.default_vat_rate', 0.20);

if ($vatRegistered && $defaultVatRate == 0) {
    $defaultVatRate = 0.20;
}

// Get supported currencies and default currency
$supportedCurrencies = [
    'GBP' => ['symbol' => '£', 'name' => 'British Pound'],
    'USD' => ['symbol' => '$', 'name' => 'US Dollar'],
    'EUR' => ['symbol' => '€', 'name' => 'Euro'],
    'CAD' => ['symbol' => 'C$', 'name' => 'Canadian Dollar'],
    'AUD' => ['symbol' => 'A$', 'name' => 'Australian Dollar']
];

$defaultCurrency = getSystemConfig($pdo, 'business.default_currency', 'GBP');

$exchangeRates = [
    'GBP' => 1.0,
    'USD' => 1.27,
    'EUR' => 1.17,
    'CAD' => 1.71,
    'AUD' => 1.90
];

// Build VAT settings
$vatSettings = [
    'enabled' => $vatRegistered,
    'default_rate' => $defaultVatRate,
    'currency_settings' => []
];

foreach ($supportedCurrencies as $currencyCode => $currencyInfo) {
    if ($vatRegistered) {
        if (in_array($currencyCode, ['GBP', 'EUR'])) {
            $rate = $currencyCode === 'EUR' ? 0.21 : $defaultVatRate;
            $vatSettings['currency_settings'][$currencyCode] = [
                'enabled' => true,
                'rate' => $rate
            ];
        } else {
            $vatSettings['currency_settings'][$currencyCode] = [
                'enabled' => false,
                'rate' => 0.00
            ];
        }
    } else {
        $vatSettings['currency_settings'][$currencyCode] = [
            'enabled' => false,
            'rate' => 0.00
        ];
    }
}

// Handle pre-selected WHMCS product from service catalog
$preSelectedWhmcsProduct = null;
if (isset($_GET['whmcs_product_id']) && isset($_GET['service_request'])) {
    $whmcsProductId = (int)$_GET['whmcs_product_id'];
    $quantity = (int)($_GET['service_quantity'] ?? 1);
    
    try {
        $whmcsProducts = $whmcsApi->getProducts();
        foreach ($whmcsProducts as $product) {
            if ($product['id'] == $whmcsProductId) {
                $preSelectedWhmcsProduct = [
                    'product' => $product,
                    'quantity' => $quantity
                ];
                break;
            }
        }
        
        if ($preSelectedWhmcsProduct) {
            error_log("Pre-selected WHMCS product: " . $preSelectedWhmcsProduct['product']['name'] . " (ID: $whmcsProductId, Qty: $quantity)");
        }
    } catch (Exception $e) {
        error_log("Error loading pre-selected WHMCS product: " . $e->getMessage());
    }
}

// Handle order creation - WHMCS ONLY with company mapping
if (isset($_POST['create_order'])) {
    error_log("=== WHMCS-ONLY ORDER CREATION STARTED at 2025-08-04 23:01:07 by jackbetherxi ===");
    
    $company_id = (int)$_POST['company_id'];
    $order_type = $_POST['order_type'] ?? '';
    $billing_cycle = $_POST['billing_cycle'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $order_currency = $_POST['order_currency'] ?? $defaultCurrency;
    $place_immediately = ($_POST['place_immediately'] ?? '0') === '1';
    
    // Parse order items
    $items_json = $_POST['order_items'] ?? '';
    $items = [];
    if (!empty($items_json)) {
        $items = json_decode($items_json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = "Invalid order items data: " . json_last_error_msg();
        }
    }

    // Validation
    if (empty($items)) {
        $error = "Please add at least one WHMCS service to your order.";
    } elseif (empty($company_id)) {
        $error = "Please select a company for this order.";
    } elseif (empty($order_type)) {
        $error = "Please select an order type.";
    } elseif (empty($billing_cycle)) {
        $error = "Please select a billing cycle.";
    } elseif (empty($start_date)) {
        $error = "Please select a service start date.";
    } else {
        try {
            $pdo->beginTransaction();

            // Generate order number
            $order_number = 'ORD-' . date('Y') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);

            // Calculate totals
            $subtotal = 0;
            $total_setup_fees = 0;
            foreach ($items as $item) {
                $subtotal += floatval($item['line_total'] ?? 0);
                $total_setup_fees += floatval($item['setup_fee'] ?? 0) * intval($item['quantity'] ?? 1);
            }

            // VAT calculation
            $vat_rate = 0.0;
            $vat_enabled = false;
            
            if ($vatSettings['enabled'] && isset($vatSettings['currency_settings'][$order_currency])) {
                $currencyVat = $vatSettings['currency_settings'][$order_currency];
                if ($currencyVat['enabled']) {
                    $vat_rate = (float)$currencyVat['rate'];
                    $vat_enabled = true;
                }
            }
            
            $tax_amount = $vat_enabled ? ($subtotal * $vat_rate) : 0.0;
            $total_amount = $subtotal + $total_setup_fees + $tax_amount;

            // Get company WHMCS customer mapping
            $stmt = $pdo->prepare("SELECT whmcs_customer_id, whmcs_customer_email, name FROM companies WHERE id = ?");
            $stmt->execute([$company_id]);
            $company = $stmt->fetch();
            
            if (!$company) {
                throw new Exception("Company not found");
            }
            
            $whmcs_customer_id = $company['whmcs_customer_id'] ?? null;
            $whmcs_customer_email = $company['whmcs_customer_email'] ?? null;

            // Determine status
            $initial_status = $place_immediately ? 'placed' : 'draft';
            $payment_status = 'unpaid';
            $placed_at = $place_immediately ? date('Y-m-d H:i:s') : null;
            $vat_enabled_int = $vat_enabled ? 1 : 0;

            // Insert order - WHMCS ONLY with company mapping
            $sql = "INSERT INTO orders (
                order_number, company_id, staff_id, status, payment_status, order_type, 
                subtotal, tax_amount, total_amount, currency, vat_rate, vat_enabled, 
                notes, billing_cycle, start_date, placed_at, has_whmcs_products, 
                whmcs_customer_id, whmcs_customer_email, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $params = [
                $order_number, $company_id, $user_id, $initial_status, $payment_status,
                $order_type, (float)$subtotal, (float)$tax_amount, (float)$total_amount, 
                $order_currency, (float)$vat_rate, $vat_enabled_int, $notes, 
                $billing_cycle, $start_date, $placed_at, 1, // has_whmcs_products = TRUE
                $whmcs_customer_id, $whmcs_customer_email
            ];
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($params);
            
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception("Failed to insert order: " . $errorInfo[2]);
            }

            $order_id = $pdo->lastInsertId();

            // Add order items - WHMCS ONLY
            foreach ($items as $item) {
                $whmcsMetadata = json_encode([
                    'whmcs_product_id' => $item['whmcs_product_id'],
                    'category_id' => $item['category_id'] ?? null,
                    'category_name' => $item['category_name'] ?? null,
                    'product_type' => $item['product_type'] ?? 'other',
                    'pay_type' => $item['pay_type'] ?? 'recurring',
                    'base_price_gbp' => $item['base_price'] ?? 0,
                    'base_setup_fee_gbp' => $item['base_setup_fee'] ?? 0,
                    'exchange_rate_used' => $item['exchange_rate'] ?? 1.0,
                    'order_currency' => $order_currency,
                    'order_timestamp' => '2025-08-04 23:01:07',
                    'ordered_by' => 'jackbetherxi',
                    'company_name' => $company['name']
                ]);
                
                $sql_item = "INSERT INTO order_items (
                    order_id, whmcs_product_id, product_source, item_type, name, description, 
                    quantity, unit_price, setup_fee, line_total, billing_cycle, currency, 
                    whmcs_metadata, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                $item_params = [
                    $order_id,
                    $item['whmcs_product_id'],
                    'whmcs',
                    'product',
                    $item['name'] ?? '',
                    $item['description'] ?? '',
                    (int)($item['quantity'] ?? 1),
                    (float)($item['unit_price'] ?? 0),
                    (float)($item['setup_fee'] ?? 0),
                    (float)($item['line_total'] ?? 0),
                    $item['billing_cycle'] ?? 'monthly',
                    $order_currency,
                    $whmcsMetadata
                ];
                
                $stmt_item = $pdo->prepare($sql_item);
                $stmt_item->execute($item_params);
            }

            // Add status history
            $status_note = $place_immediately ? 'WHMCS order created and placed by jackbetherxi' : 'WHMCS order created as draft by jackbetherxi';
            $status_note .= " at 2025-08-04 23:01:07 (Currency: $order_currency, VAT: " . ($vat_enabled ? 'enabled' : 'disabled') . ")";
            $status_note .= " - Company: " . $company['name'];
            
            if ($whmcs_customer_id) {
                $status_note .= " - Mapped to WHMCS Customer ID: $whmcs_customer_id";
            } else {
                $status_note .= " - No WHMCS customer mapping (CMS-only company)";
            }
            
            $stmt_history = $pdo->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by, notes, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt_history->execute([$order_id, NULL, $initial_status, $user_id, $status_note]);

            $pdo->commit();

            $success_message = $place_immediately ? 
                "WHMCS Order #$order_number created and submitted successfully! Company: " . $company['name'] : 
                "WHMCS Order #$order_number saved as draft successfully! Company: " . $company['name'];
            
            header("Location: create-order.php?success=" . urlencode($success_message));
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error creating WHMCS order: " . $e->getMessage();
            error_log("WHMCS order creation error at 2025-08-04 23:01:07: " . $e->getMessage());
        }
    }
}

// Get companies with WHMCS customer mapping
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.name, 
               COALESCE(c.preferred_currency, ?) as preferred_currency, 
               COALESCE(c.currency_override, 0) as currency_override,
               c.whmcs_customer_id,
               c.whmcs_customer_email,
               CASE 
                   WHEN u.company_id = c.id THEN 'Primary'
                   ELSE 'Multi-Company'
               END as relationship_type,
               CASE 
                   WHEN c.whmcs_customer_id IS NOT NULL THEN 'Mapped'
                   ELSE 'CMS-Only'
               END as whmcs_status
        FROM companies c
        JOIN users u ON (u.company_id = c.id OR u.id IN (
            SELECT cu.user_id FROM company_users cu WHERE cu.company_id = c.id
        ))
        WHERE u.id = ? AND c.is_active = 1
        ORDER BY c.whmcs_customer_id IS NOT NULL DESC, relationship_type ASC, c.name ASC
    ");
    $stmt->execute([$defaultCurrency, $user_id]);
    $companies = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Failed to fetch companies: " . $e->getMessage());
    $companies = [];
}

// Get WHMCS products ONLY
$whmcsProducts = [];
$whmcsCategories = [];
$whmcsConnected = false;

try {
    $connectionTest = $whmcsApi->testConnection();
    if ($connectionTest['success']) {
        $whmcsConnected = true;
        $whmcsProducts = $whmcsApi->getProducts();
        $whmcsCategories = $whmcsApi->getProductGroups();
        
        error_log("WHMCS-Only Integration at 2025-08-04 23:01:07: Loaded " . count($whmcsProducts) . " products and " . count($whmcsCategories) . " categories");
    } else {
        error_log("WHMCS Integration failed at 2025-08-04 23:01:07: " . $connectionTest['message']);
    }
} catch (Exception $e) {
    error_log("WHMCS Integration error at 2025-08-04 23:01:07: " . $e->getMessage());
}

$page_title = "Create WHMCS Order | CaminhoIT";
?>
<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Create and manage WHMCS orders for CaminhoIT services">
    
    <!-- CSS Links -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <?php if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/assets/styles.css')): ?>
        <link rel="stylesheet" href="/assets/styles.css">
    <?php endif; ?>
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --dark-gradient: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            --whmcs-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --card-shadow-hover: 0 20px 40px rgba(0, 0, 0, 0.15);
            --border-radius: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Enhanced Hero Section */
        .hero-enhanced {
            background: var(--whmcs-gradient);
            position: relative;
            overflow: hidden;
            padding: 4rem 0 6rem 0;
            display: flex;
            align-items: center;
        }

        .hero-enhanced::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 100" fill="white" opacity="0.1"><polygon points="0,100 1000,100 1000,20"/></svg>');
            background-size: cover;
            background-position: bottom;
        }

        .hero-content-enhanced {
            position: relative;
            z-index: 2;
            text-align: center;
        }

        .hero-title-enhanced {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
            line-height: 1.2;
        }

        .hero-subtitle-enhanced {
            font-size: 1.125rem;
            margin-bottom: 1.5rem;
            opacity: 0.9;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Main Content Container */
        .main-content {
            background: #f8fafc;
            position: relative;
            z-index: 10;
            min-height: 100vh;
            border-radius: 2rem 2rem 0 0;
            margin-top: -2rem;
            padding: 3rem 0;
        }

        /* Enhanced Cards */
        .enhanced-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            border: none;
            overflow: hidden;
            position: relative;
        }

        .enhanced-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--whmcs-gradient);
        }

        /* WHMCS Product Catalog */
        .catalog-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }

        .catalog-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--whmcs-gradient);
        }

        .catalog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        /* WHMCS Product Card */
        .product-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            border: 2px solid transparent;
            border-left: 4px solid #667eea;
            min-height: 200px;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
            border-color: #667eea;
        }

        .product-card.selected {
            border-color: #667eea;
            background: linear-gradient(135deg, #f0f9ff 0%, #ffffff 100%);
            transform: scale(1.02);
        }

        .product-card.pre-selected {
            background: linear-gradient(135deg, #fffbeb 0%, #ffffff 100%) !important;
            border: 2px solid #f59e0b !important;
            animation: pulse-highlight 2s ease-in-out;
        }

        @keyframes pulse-highlight {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
            50% { transform: scale(1.02); box-shadow: 0 0 0 10px rgba(245, 158, 11, 0); }
        }

        /* Source Badge */
        .source-badge {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            color: white;
            background: var(--whmcs-gradient);
            z-index: 5;
        }

        /* Currency Converted Badge */
        .currency-converted {
            position: absolute;
            bottom: 0.75rem;
            right: 0.75rem;
            background: var(--info-gradient);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
            z-index: 5;
        }

        /* WHMCS Status */
        .whmcs-status {
            background: linear-gradient(135deg, #f0f9ff 0%, #ffffff 100%);
            border: 1px solid #3b82f6;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .whmcs-status::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--whmcs-gradient);
        }

        .whmcs-status.error {
            background: linear-gradient(135deg, #fef2f2 0%, #ffffff 100%);
            border-color: #ef4444;
        }

        .whmcs-status.error::before {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        /* Company Info */
        .company-info {
            background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%);
            border: 1px solid #10b981;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .company-info::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--success-gradient);
        }

        .company-info.no-mapping {
            background: linear-gradient(135deg, #fffbeb 0%, #ffffff 100%);
            border-color: #f59e0b;
        }

        .company-info.no-mapping::before {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        /* Order Items */
        .order-items {
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
        }

        .order-item {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: var(--transition);
            border-left: 4px solid #667eea;
        }

        /* Order Summary */
        .order-summary {
            background: white !important;
            border: 2px solid #667eea !important;
            border-radius: var(--border-radius);
            padding: 2rem;
            position: sticky;
            top: 100px;
            box-shadow: var(--card-shadow);
        }

        /* Currency Info */
        .currency-info {
            background: linear-gradient(135deg, #f0f9ff 0%, #ffffff 100%);
            border: 1px solid #3b82f6;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .currency-info::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--info-gradient);
        }

        .currency-badge {
            background: var(--primary-gradient);
            color: white;
            padding: 0.375rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        /* Enhanced Buttons */
        .btn-enhanced {
            border-radius: 50px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            position: relative;
            overflow: hidden;
        }

        /* Badges */
        .badge-whmcs {
            background: var(--whmcs-gradient);
            color: white;
            border-radius: 20px;
            padding: 0.5rem 1rem;
        }

        .badge-primary { 
            background: var(--primary-gradient); 
            color: white; 
            border-radius: 20px;
            padding: 0.5rem 1rem;
        }

        .badge-mapped {
            background: var(--success-gradient);
            color: white;
            border-radius: 20px;
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
        }

        .badge-cms-only {
            background: var(--warning-gradient);
            color: white;
            border-radius: 20px;
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
        }

        /* Empty State */
        .empty-cart {
            text-align: center;
            color: #6b7280;
            padding: 4rem 2rem;
            border-radius: var(--border-radius);
            background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%);
            border: 2px dashed #d1d5db;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero-title-enhanced {
                font-size: 2.25rem;
            }
            
            .main-content {
                margin-top: -1rem;
                border-radius: 1rem 1rem 0 0;
                padding: 2rem 0;
            }
            
            .catalog-grid {
                grid-template-columns: 1fr;
            }
            
            .order-summary {
                position: static;
                margin-top: 2rem;
            }
        }
    </style>
</head>
<body>

<?php 
// Navigation
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php')) {
    include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php';
} elseif (file_exists($_SERVER['DOCUMENT_ROOT'] . '/includes/nav.php')) {
    include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav.php';
} else {
    echo '<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
            <div class="container">
                <a class="navbar-brand" href="/">CaminhoIT</a>
                <div class="navbar-nav ms-auto">
                    <a class="nav-link" href="/dashboard.php">Dashboard</a>
                    <a class="nav-link" href="/logout.php">Logout</a>
                </div>
            </div>
          </nav>';
}
?>

<!-- Hero Section -->
<header class="hero-enhanced">
    <div class="container">
        <div class="hero-content-enhanced">
            <h1 class="hero-title-enhanced text-white">
                <i class="bi bi-link-45deg me-3"></i>
                Create WHMCS Order
            </h1>
            <p class="hero-subtitle-enhanced text-white">
                Place orders for WHMCS products with automatic currency conversion and company-to-customer mapping
            </p>
        </div>
    </div>
</header>

<!-- Main Content -->
<div class="main-content">
    <div class="container">

        <!-- Messages -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success mb-4">
                <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($_GET['success']) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger mb-4">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Pre-selected Product Alert -->
        <?php if ($preSelectedWhmcsProduct): ?>
            <div class="alert alert-info mb-4">
                <i class="bi bi-cart-plus-fill me-2"></i>
                <strong>Service Request:</strong> WHMCS product "<?= htmlspecialchars($preSelectedWhmcsProduct['product']['name']) ?>" 
                has been pre-selected (Quantity: <?= $preSelectedWhmcsProduct['quantity'] ?>). Complete the order details below.
            </div>
        <?php endif; ?>

        <!-- WHMCS Status -->
        <div class="whmcs-status <?= !$whmcsConnected ? 'error' : '' ?>">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-1 fw-bold">
                        <i class="bi bi-link-45deg me-2"></i>WHMCS Integration Status
                    </h6>
                    <small class="text-muted">
                        <?php if ($whmcsConnected): ?>
                            Connected at 2025-08-04 23:01:07 - <?= count($whmcsProducts) ?> products available from <?= count($whmcsCategories) ?> categories
                        <?php else: ?>
                            Not connected - Unable to load WHMCS products
                        <?php endif; ?>
                    </small>
                </div>
                <span class="badge <?= $whmcsConnected ? 'bg-success' : 'bg-danger' ?>">
                    <?= $whmcsConnected ? 'Connected' : 'Offline' ?>
                </span>
            </div>
        </div>

        <form method="POST" id="orderForm">
            <div class="row">
                <div class="col-lg-8">
                    <!-- Order Details -->
                    <div class="enhanced-card mb-4">
                        <div class="card-header bg-transparent border-0 p-4 pb-0">
                            <h5 class="mb-0">
                                <i class="bi bi-clipboard-data me-2" style="color: #667eea;"></i>
                                Order Details
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Company <span class="text-danger">*</span></label>
                                        <select name="company_id" class="form-select" required onchange="updateCompanyCurrency()">
                                            <option value="">Select Company</option>
                                            <?php foreach ($companies as $company): ?>
                                                <option value="<?= $company['id'] ?>" 
                                                        data-currency="<?= htmlspecialchars($company['preferred_currency']) ?>"
                                                        data-currency-override="<?= $company['currency_override'] ?>"
                                                        data-whmcs-customer="<?= htmlspecialchars($company['whmcs_customer_id'] ?? '') ?>"
                                                        data-whmcs-email="<?= htmlspecialchars($company['whmcs_customer_email'] ?? '') ?>"
                                                        data-company-name="<?= htmlspecialchars($company['name']) ?>"
                                                        data-whmcs-status="<?= $company['whmcs_status'] ?>">
                                                    <?= htmlspecialchars($company['name']) ?>
                                                    <?php if ($company['whmcs_customer_id']): ?>
                                                        <span class="text-success">(WHMCS: <?= $company['whmcs_customer_id'] ?>)</span>
                                                    <?php else: ?>
                                                        <span class="text-warning">(CMS-Only)</span>
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Order Type <span class="text-danger">*</span></label>
                                        <select name="order_type" class="form-select" required>
                                            <option value="">Select Type</option>
                                            <option value="new" <?= ($preSelectedWhmcsProduct ? 'selected' : '') ?>>New Service</option>
                                            <option value="upgrade">Service Upgrade</option>
                                            <option value="addon">Additional Service</option>
                                            <option value="renewal">Service Renewal</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Billing Cycle <span class="text-danger">*</span></label>
                                        <select name="billing_cycle" class="form-select" required>
                                            <option value="">Select Cycle</option>
                                            <option value="monthly" selected>Monthly</option>
                                            <option value="quarterly">Quarterly (3 months)</option>
                                            <option value="annually">Annually (12 months)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Service Start Date <span class="text-danger">*</span></label>
                                        <input type="date" name="start_date" class="form-control" required>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Order Notes</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Any special requirements or notes about this WHMCS order..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- WHMCS Products ONLY -->
                    <?php if ($whmcsConnected && !empty($whmcsProducts)): ?>
                    <div class="catalog-section">
                        <div class="catalog-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="mb-1 fw-bold">
                                        <i class="bi bi-link-45deg me-2" style="color: #667eea;"></i>
                                        WHMCS Products
                                    </h5>
                                    <small class="text-muted">All products sourced from WHMCS with real-time pricing and currency conversion</small>
                                </div>
                                <span class="badge badge-whmcs"><?= count($whmcsProducts) ?> Products</span>
                            </div>
                        </div>
                        <div class="catalog-grid">
                            <?php foreach ($whmcsProducts as $product): ?>
                                <div class="product-card <?= ($preSelectedWhmcsProduct && $preSelectedWhmcsProduct['product']['id'] == $product['id']) ? 'pre-selected' : '' ?>" 
                                     onclick="selectProduct(<?= $product['id'] ?>, 'whmcs')" 
                                     data-product-id="<?= $product['id'] ?>"
                                     data-base-price="<?= $product['base_price'] ?>" 
                                     data-setup-fee="<?= $product['setup_fee'] ?? 0 ?>">
                                    
                                    <div class="source-badge">
                                        <i class="bi bi-link-45deg me-1"></i>WHMCS
                                    </div>
                                    
                                    <div class="currency-converted" style="display: none;">
                                        <i class="bi bi-arrow-repeat me-1"></i>Converted
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-start mb-3" style="margin-top: 2rem;">
                                        <h6 class="mb-0 fw-bold"><?= htmlspecialchars($product['name']) ?></h6>
                                        <div class="text-end">
                                            <div class="h5 text-primary mb-0 fw-bold">
                                                <span class="currency-symbol">£</span><span class="price-amount"><?= number_format($product['base_price'], 2) ?></span>
                                            </div>
                                            <small class="text-muted">/<?= str_replace('_', ' ', $product['unit_type'] ?? 'unit') ?></small>
                                        </div>
                                    </div>
                                    
                                    <p class="text-muted small mb-3"><?= htmlspecialchars($product['short_description'] ?: 'Available from WHMCS') ?></p>
                                    
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        <span class="badge badge-primary"><?= htmlspecialchars($product['category_name']) ?></span>
                                        <span class="badge badge-whmcs">WHMCS</span>
                                    </div>
                                    
                                    <?php if (($product['setup_fee'] ?? 0) > 0): ?>
                                        <div class="text-muted small">
                                            <i class="bi bi-gear me-1"></i>
                                            Setup fee: <span class="currency-symbol">£</span><span class="setup-fee-amount"><?= number_format($product['setup_fee'], 2) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- No WHMCS Products -->
                    <div class="enhanced-card">
                        <div class="card-body text-center py-5">
                            <i class="bi bi-exclamation-triangle text-warning" style="font-size: 4rem;"></i>
                            <h5 class="mt-3 text-muted">No WHMCS Products Available</h5>
                            <p class="text-muted mb-0">
                                WHMCS is not connected or no products are configured. Please check the integration status.
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Order Items -->
                    <div class="order-items">
                        <h5 class="mb-3 fw-bold">
                            <i class="bi bi-cart-fill me-2" style="color: #667eea;"></i>
                            WHMCS Order Items
                        </h5>
                        <div id="orderItemsList">
                            <div class="empty-cart">
                                <i class="bi bi-cart3"></i>
                                <h6 class="fw-bold">No WHMCS products added yet</h6>
                                <p class="mb-0">Select WHMCS products from above to build your order.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- Company Info -->
                    <div class="company-info" id="companyInfo" style="display: none;">
                        <h6 class="mb-2 fw-bold">
                            <i class="bi bi-building me-2"></i>Company Details
                        </h6>
                        <div class="small">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong>Name:</strong> 
                                <span id="companyName">-</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong>WHMCS Status:</strong> 
                                <span id="whmcsStatusBadge"></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong>Customer ID:</strong> 
                                <span id="whmcsCustomer">-</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <strong>Currency:</strong> 
                                <span id="companyCurrency">-</span>
                            </div>
                        </div>
                    </div>

                    <!-- Currency Information -->
                    <div class="currency-info" id="currencyInfo" style="display: none;">
                        <h6 class="mb-3 fw-bold">
                            <i class="bi bi-currency-exchange me-2"></i>Currency Information
                        </h6>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span>Order Currency:</span>
                            <span class="currency-badge" id="orderCurrencyBadge"><?= $defaultCurrency ?></span>
                        </div>
                        <small class="text-muted d-block" id="currencyNote">
                            Using system default currency
                        </small>
                        <div class="mt-2" id="exchangeRateInfo" style="display: none;">
                            <small class="text-muted">
                                <i class="bi bi-arrow-left-right me-1"></i>
                                Exchange Rate: 1 <?= $defaultCurrency ?> = <span id="exchangeRateValue">1.00</span> <span id="targetCurrencyCode"><?= $defaultCurrency ?></span>
                            </small>
                        </div>
                    </div>

                    <!-- Order Summary -->
                    <div class="order-summary">
                        <h5 class="mb-3 fw-bold">
                            <i class="bi bi-calculator me-2"></i>Order Summary
                        </h5>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span>Items:</span>
                            <span id="itemCount" class="fw-semibold">0</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal:</span>
                            <span id="subtotal" class="fw-semibold"><span class="currency-symbol">£</span>0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Setup Fees:</span>
                            <span id="setupFees" class="fw-semibold"><span class="currency-symbol">£</span>0.00</span>
                        </div>
                        <div class="vat-row" id="vatRow" style="<?= !$vatRegistered ? 'display: none;' : '' ?>">
                            <div class="d-flex justify-content-between mb-2">
                                <span>VAT (<span id="vatPercent"><?= ($defaultVatRate * 100) ?></span>%):</span>
                                <span id="vat" class="fw-semibold"><span class="currency-symbol">£</span>0.00</span>
                            </div>
                        </div>
                        <hr class="my-3">
                        <div class="d-flex justify-content-between mb-4">
                            <strong class="h6">Total:</strong>
                            <strong class="h6" id="total"><span class="currency-symbol">£</span>0.00</strong>
                        </div>
                        
                        <input type="hidden" name="order_items" id="orderItemsInput">
                        <input type="hidden" name="order_currency" id="orderCurrencyInput" value="<?= $defaultCurrency ?>">
                        
                        <div class="d-grid gap-3">
                            <button type="submit" name="create_order" class="btn btn-outline-primary btn-enhanced" onclick="setOrderAction('draft')">
                                <span>
                                    <i class="bi bi-save me-2"></i>Save as Draft
                                </span>
                            </button>
                            <button type="submit" name="create_order" class="btn btn-primary btn-enhanced" onclick="setOrderAction('place')">
                                <span>
                                    <i class="bi bi-send me-2"></i>Submit Order
                                </span>
                            </button>
                        </div>
                        <input type="hidden" name="place_immediately" id="placeImmediatelyInput" value="0">
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// WHMCS-ONLY Configuration at 2025-08-04 23:01:07 by jackbetherxi
const whmcsProducts = <?= json_encode($whmcsProducts) ?>;
const supportedCurrencies = <?= json_encode($supportedCurrencies) ?>;
const defaultCurrency = '<?= $defaultCurrency ?>';
const exchangeRates = <?= json_encode($exchangeRates) ?>;
const vatSettings = <?= json_encode($vatSettings) ?>;
const whmcsConnected = <?= $whmcsConnected ? 'true' : 'false' ?>;
const preSelectedProduct = <?= $preSelectedWhmcsProduct ? json_encode($preSelectedWhmcsProduct) : 'null' ?>;

let orderItems = [];
let currentCurrency = defaultCurrency;
let currentCurrencySymbol = '£';
let currentExchangeRate = 1.0;
let currentVatRate = <?= $defaultVatRate ?>;
let vatEnabled = <?= $vatRegistered ? 'true' : 'false' ?>;

console.log('WHMCS-Only Order System Initialized at 2025-08-04 23:01:07 by jackbetherxi:', {
    whmcsProductsCount: whmcsProducts.length,
    whmcsConnected,
    defaultCurrency,
    preSelectedProduct: !!preSelectedProduct
});

// Function to set order action
function setOrderAction(action) {
    document.getElementById('placeImmediatelyInput').value = action === 'place' ? '1' : '0';
    console.log('Order action set to:', action, 'at 2025-08-04 23:01:07');
}

// FIXED: Currency update function with IMMEDIATE triggers
function updateCompanyCurrency() {
    console.log('updateCompanyCurrency() called at 2025-08-04 23:01:07');
    
    const companySelect = document.querySelector('select[name="company_id"]');
    const selectedOption = companySelect.options[companySelect.selectedIndex];
    
    const currencyInfo = document.getElementById('currencyInfo');
    const companyInfo = document.getElementById('companyInfo');
    const currencyBadge = document.getElementById('orderCurrencyBadge');
    const currencyNote = document.getElementById('currencyNote');
    const exchangeRateInfo = document.getElementById('exchangeRateInfo');
    
    if (selectedOption && selectedOption.value) {
        // Update company info
        const companyName = selectedOption.dataset.companyName;
        const whmcsCustomer = selectedOption.dataset.whmcsCustomer || '';
        const whmcsEmail = selectedOption.dataset.whmcsEmail || '';
        const whmcsStatus = selectedOption.dataset.whmcsStatus;
        
        document.getElementById('companyName').textContent = companyName;
        
        // Update WHMCS status badge
        const statusBadge = document.getElementById('whmcsStatusBadge');
        if (whmcsStatus === 'Mapped') {
            statusBadge.innerHTML = '<span class="badge badge-mapped">Mapped</span>';
            document.getElementById('whmcsCustomer').textContent = whmcsCustomer + (whmcsEmail ? ` (${whmcsEmail})` : '');
            companyInfo.className = 'company-info';
        } else {
            statusBadge.innerHTML = '<span class="badge badge-cms-only">CMS-Only</span>';
            document.getElementById('whmcsCustomer').textContent = 'Not mapped to WHMCS';
            companyInfo.className = 'company-info no-mapping';
        }
        
        companyInfo.style.display = 'block';
        
        // FIXED: Update currency with immediate effect
        const companyCurrency = selectedOption.dataset.currency;
        const currencyOverride = selectedOption.dataset.currencyOverride === '1';
        
        console.log('Company currency data:', {
            companyCurrency,
            currencyOverride,
            availableCurrencies: Object.keys(supportedCurrencies)
        });
        
        if (currencyOverride && companyCurrency && supportedCurrencies[companyCurrency]) {
            currentCurrency = companyCurrency;
            currentCurrencySymbol = supportedCurrencies[companyCurrency].symbol;
            currentExchangeRate = exchangeRates[companyCurrency] || 1.0;
            
            currencyBadge.textContent = companyCurrency;
            currencyNote.textContent = `Using ${companyName}'s preferred currency`;
            
            if (companyCurrency !== defaultCurrency) {
                exchangeRateInfo.style.display = 'block';
                document.getElementById('exchangeRateValue').textContent = currentExchangeRate.toFixed(4);
                document.getElementById('targetCurrencyCode').textContent = companyCurrency;
            } else {
                exchangeRateInfo.style.display = 'none';
            }
            
            console.log('Currency updated to company preference:', {
                currency: currentCurrency,
                symbol: currentCurrencySymbol,
                rate: currentExchangeRate
            });
        } else {
            currentCurrency = defaultCurrency;
            currentCurrencySymbol = supportedCurrencies[defaultCurrency].symbol;
            currentExchangeRate = 1.0;
            
            currencyBadge.textContent = defaultCurrency;
            currencyNote.textContent = 'Using system default currency';
            exchangeRateInfo.style.display = 'none';
            
            console.log('Currency reset to default:', defaultCurrency);
        }
        
        document.getElementById('companyCurrency').textContent = currentCurrency;
        currencyInfo.style.display = 'block';
        
        // IMMEDIATE currency display update - THIS IS THE FIX
        setTimeout(() => {
            updateCurrencyDisplay();
        }, 50); // Small delay to ensure DOM is ready
        
    } else {
        currencyInfo.style.display = 'none';
        companyInfo.style.display = 'none';
        
        // Reset to defaults
        currentCurrency = defaultCurrency;
        currentCurrencySymbol = '£';
        currentExchangeRate = 1.0;
        setTimeout(() => {
            updateCurrencyDisplay();
        }, 50);
    }
    
    document.getElementById('orderCurrencyInput').value = currentCurrency;
    console.log('Final currency state:', {
        currency: currentCurrency,
        symbol: currentCurrencySymbol,
        rate: currentExchangeRate,
        timestamp: '2025-08-04 23:01:07'
    });
}

// FIXED: Immediate currency display update
function updateCurrencyDisplay() {
    console.log('updateCurrencyDisplay() called - updating to:', currentCurrency, currentCurrencySymbol);
    
    // Update all currency symbols IMMEDIATELY
    const currencySymbols = document.querySelectorAll('.currency-symbol');
    currencySymbols.forEach(symbol => {
        symbol.textContent = currentCurrencySymbol;
    });
    
    // Update product prices with conversion - IMMEDIATE
    const productCards = document.querySelectorAll('.product-card');
    let updatedCount = 0;
    
    productCards.forEach(card => {
        const priceElement = card.querySelector('.price-amount');
        const setupFeeElement = card.querySelector('.setup-fee-amount');
        const convertedBadge = card.querySelector('.currency-converted');
        
        if (priceElement) {
            const basePrice = parseFloat(card.dataset.basePrice);
            const convertedPrice = basePrice * currentExchangeRate;
            priceElement.textContent = convertedPrice.toFixed(2);
            updatedCount++;
            
            if (setupFeeElement) {
                const baseSetupFee = parseFloat(card.dataset.setupFee);
                const convertedSetupFee = baseSetupFee * currentExchangeRate;
                setupFeeElement.textContent = convertedSetupFee.toFixed(2);
            }
            
            // Show conversion badge if currency changed
            if (currentCurrency !== defaultCurrency) {
                convertedBadge.style.display = 'block';
            } else {
                convertedBadge.style.display = 'none';
            }
        }
    });
    
    console.log(`Updated ${updatedCount} product prices to ${currentCurrency} at 2025-08-04 23:01:07`);
    
    updateOrderDisplay();
}

// WHMCS product selection
function selectProduct(id, source) {
    if (source !== 'whmcs') {
        console.error('Only WHMCS products are supported');
        return;
    }
    
    const item = whmcsProducts.find(p => p.id == id);
    if (!item) {
        console.error('WHMCS product not found:', id);
        return;
    }
    
    // Check if already in cart
    const existingIndex = orderItems.findIndex(oi => 
        oi.whmcs_product_id == id && oi.product_source === 'whmcs'
    );
    
    if (existingIndex >= 0) {
        orderItems[existingIndex].quantity++;
        orderItems[existingIndex].line_total = orderItems[existingIndex].unit_price * orderItems[existingIndex].quantity;
    } else {
        const convertedPrice = parseFloat(item.base_price) * currentExchangeRate;
        const convertedSetupFee = parseFloat(item.setup_fee || 0) * currentExchangeRate;
        
        orderItems.push({
            whmcs_product_id: id,
            product_source: 'whmcs',
            item_type: 'product',
            name: item.name,
            description: item.short_description || item.description || '',
            quantity: 1,
            unit_price: convertedPrice,
            setup_fee: convertedSetupFee,
            line_total: convertedPrice,
            billing_cycle: item.billing_cycle || 'monthly',
            base_price: parseFloat(item.base_price),
            base_setup_fee: parseFloat(item.setup_fee || 0),
            exchange_rate: currentExchangeRate,
            category_id: item.category_id,
            category_name: item.category_name,
            product_type: item.product_type || 'other',
            pay_type: item.pay_type || 'recurring'
        });
    }
    
    console.log('WHMCS product selected at 2025-08-04 23:01:07:', item.name, 'Total items:', orderItems.length);
    updateOrderDisplay();
}

function updateOrderDisplay() {
    const itemsList = document.getElementById('orderItemsList');
    
    if (orderItems.length === 0) {
        itemsList.innerHTML = `
            <div class="empty-cart">
                <i class="bi bi-cart3"></i>
                <h6 class="fw-bold">No WHMCS products added yet</h6>
                <p class="mb-0">Select WHMCS products from above to build your order.</p>
            </div>
        `;
    } else {
        itemsList.innerHTML = orderItems.map((item, index) => `
            <div class="order-item">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center mb-2">
                            <h6 class="mb-0 fw-bold me-2">${escapeHtml(item.name)}</h6>
                            <span class="badge badge-whmcs small">
                                <i class="bi bi-link-45deg me-1"></i>WHMCS
                            </span>
                        </div>
                        <p class="text-muted small mb-3">${escapeHtml(item.description || 'WHMCS Product')}</p>
                        <div class="d-flex align-items-center gap-3">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="changeQuantity(${index}, -1)">
                                <i class="bi bi-dash"></i>
                            </button>
                            <span class="fw-semibold">Qty: ${item.quantity}</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="changeQuantity(${index}, 1)">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="h6 mb-1 fw-bold text-primary">${currentCurrencySymbol}${(item.unit_price * item.quantity).toFixed(2)}</div>
                        <small class="text-muted d-block">${currentCurrencySymbol}${item.unit_price.toFixed(2)} each</small>
                        ${item.setup_fee > 0 ? `
                            <small class="text-muted d-block">
                                Setup: ${currentCurrencySymbol}${(item.setup_fee * item.quantity).toFixed(2)}
                            </small>
                        ` : ''}
                        <small class="text-info d-block">
                            <i class="bi bi-info-circle me-1"></i>WHMCS ID: ${item.whmcs_product_id}
                        </small>
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(${index})">
                                <i class="bi bi-trash me-1"></i> Remove
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
    if (index < 0 || index >= orderItems.length) return;
    
    orderItems[index].quantity += change;
    if (orderItems[index].quantity <= 0) {
        orderItems.splice(index, 1);
    } else {
        orderItems[index].line_total = orderItems[index].unit_price * orderItems[index].quantity;
    }
    updateOrderDisplay();
    console.log('Quantity changed at 2025-08-04 23:18:22 by jackbetherxi');
}

function removeItem(index) {
    if (index < 0 || index >= orderItems.length) return;
    
    const item = orderItems[index];
    if (confirm(`Remove "${item.name}" from your WHMCS order?`)) {
        orderItems.splice(index, 1);
        updateOrderDisplay();
        console.log('Item removed at 2025-08-04 23:18:22 by jackbetherxi:', item.name);
    }
}

function updateSummary() {
    const itemCount = orderItems.reduce((sum, item) => sum + item.quantity, 0);
    const subtotal = orderItems.reduce((sum, item) => sum + (item.unit_price * item.quantity), 0);
    const setupFees = orderItems.reduce((sum, item) => sum + (item.setup_fee * item.quantity), 0);
    const vat = vatEnabled ? (subtotal * currentVatRate) : 0;
    const total = subtotal + setupFees + vat;
    
    // Update display with current currency
    document.getElementById('itemCount').textContent = itemCount;
    document.getElementById('subtotal').innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${subtotal.toFixed(2)}`;
    document.getElementById('setupFees').innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${setupFees.toFixed(2)}`;
    document.getElementById('vat').innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${vat.toFixed(2)}`;
    document.getElementById('total').innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${total.toFixed(2)}`;
    
    // Update form input
    document.getElementById('orderItemsInput').value = JSON.stringify(orderItems);
    
    // Show/hide VAT
    document.getElementById('vatRow').style.display = vatEnabled && subtotal > 0 ? '' : 'none';
    
    console.log('Summary updated at 2025-08-04 23:18:22:', {
        currency: currentCurrency,
        itemCount,
        subtotal: subtotal.toFixed(2),
        total: total.toFixed(2)
    });
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    };
    return text ? text.replace(/[&<>"']/g, function(m) { return map[m]; }) : '';
}

// Auto-add pre-selected WHMCS product
function addPreSelectedProduct() {
    if (preSelectedProduct && whmcsConnected) {
        console.log('Auto-adding pre-selected WHMCS product at 2025-08-04 23:18:22 by jackbetherxi:', preSelectedProduct);
        
        // Add the product multiple times based on quantity
        for (let i = 0; i < preSelectedProduct.quantity; i++) {
            selectProduct(preSelectedProduct.product.id, 'whmcs');
        }
        
        // Show success message
        const alertHtml = `
            <div class="alert alert-success alert-dismissible fade show dynamic-alert" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <strong>Product Added:</strong> ${escapeHtml(preSelectedProduct.product.name)} 
                (Qty: ${preSelectedProduct.quantity}) has been added to your WHMCS order.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        
        const container = document.querySelector('.container');
        const firstChild = container.firstElementChild;
        firstChild.insertAdjacentHTML('afterend', alertHtml);
        
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            const alert = document.querySelector('.dynamic-alert');
            if (alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 5000);
    }
}

// Initialize page functionality
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM Content Loaded - Initializing WHMCS-only order system at 2025-08-04 23:18:22 by jackbetherxi...');
    
    // Set default start date to today
    const startDateInput = document.querySelector('input[name="start_date"]');
    if (startDateInput) {
        const today = new Date();
        const formattedDate = today.getFullYear() + '-' + 
                             String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                             String(today.getDate()).padStart(2, '0');
        startDateInput.value = formattedDate;
        console.log('Set default start date to:', formattedDate);
    }
    
    // Initialize currency display
    updateCurrencyDisplay();
    
    // Add pre-selected product if coming from service catalog
    addPreSelectedProduct();
    
    // Add visual feedback for product card clicks
    document.querySelectorAll('.product-card').forEach(card => {
        card.addEventListener('click', function() {
            this.classList.add('selected');
            setTimeout(() => {
                this.classList.remove('selected');
            }, 300);
        });
    });
    
    console.log('WHMCS-only order system initialization complete at 2025-08-04 23:18:22');
    console.log('Statistics:', {
        whmcsProductsLoaded: whmcsProducts.length,
        whmcsConnected: whmcsConnected,
        preSelectedProduct: !!preSelectedProduct,
        currentUser: 'jackbetherxi',
        timestamp: '2025-08-04 23:18:22'
    });
});

// Enhanced form submission with WHMCS-only validation
document.getElementById('orderForm').addEventListener('submit', function(e) {
    console.log('=== WHMCS-ONLY FORM SUBMISSION at 2025-08-04 23:18:22 by jackbetherxi ===');
    console.log('Order items:', orderItems);
    console.log('All items are WHMCS products:', orderItems.every(item => item.product_source === 'whmcs'));
    
    // Validate that items exist
    if (orderItems.length === 0) {
        e.preventDefault();
        console.log('Validation failed: No WHMCS items at 2025-08-04 23:18:22');
        alert('Please add at least one WHMCS service to your order.');
        return false;
    }
    
    // Validate all items are WHMCS products
    const nonWhmcsItems = orderItems.filter(item => item.product_source !== 'whmcs');
    if (nonWhmcsItems.length > 0) {
        e.preventDefault();
        console.log('Validation failed: Non-WHMCS items found at 2025-08-04 23:18:22:', nonWhmcsItems);
        alert('Only WHMCS products are allowed in this order system.');
        return false;
    }
    
    // Validate company selection
    const companySelect = document.querySelector('select[name="company_id"]');
    if (!companySelect.value) {
        e.preventDefault();
        console.log('Validation failed: No company selected at 2025-08-04 23:18:22');
        alert('Please select a company for this WHMCS order.');
        companySelect.focus();
        return false;
    }
    
    // Validate other required fields
    const orderTypeSelect = document.querySelector('select[name="order_type"]');
    if (!orderTypeSelect.value) {
        e.preventDefault();
        alert('Please select an order type.');
        orderTypeSelect.focus();
        return false;
    }
    
    const billingCycleSelect = document.querySelector('select[name="billing_cycle"]');
    if (!billingCycleSelect.value) {
        e.preventDefault();
        alert('Please select a billing cycle.');
        billingCycleSelect.focus();
        return false;
    }
    
    const startDateInput = document.querySelector('input[name="start_date"]');
    if (!startDateInput.value) {
        e.preventDefault();
        alert('Please select a service start date.');
        startDateInput.focus();
        return false;
    }
    
    // Update hidden inputs before submission
    const orderItemsInput = document.getElementById('orderItemsInput');
    const orderCurrencyInput = document.getElementById('orderCurrencyInput');
    
    orderItemsInput.value = JSON.stringify(orderItems);
    orderCurrencyInput.value = currentCurrency;
    
    console.log('Updated hidden inputs at 2025-08-04 23:18:22:');
    console.log('order_items:', orderItemsInput.value);
    console.log('order_currency:', orderCurrencyInput.value);
    console.log('place_immediately:', document.getElementById('placeImmediatelyInput').value);
    
    console.log('WHMCS-only form validation passed at 2025-08-04 23:18:22, submitting by jackbetherxi...');
    
    // Show loading state
    const submitButtons = this.querySelectorAll('button[type="submit"]');
    submitButtons.forEach(btn => {
        btn.disabled = true;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<span><i class="bi bi-hourglass-split me-2"></i>Processing WHMCS Order...</span>';
        
        // Reset button after 10 seconds (failsafe)
        setTimeout(() => {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }, 10000);
    });
    
    return true;
});

// Global error handler
window.addEventListener('error', function(e) {
    console.error('JavaScript error in WHMCS-only system at 2025-08-04 23:18:22:', e.error);
    console.error('Error details:', {
        message: e.message,
        filename: e.filename,
        lineno: e.lineno,
        colno: e.colno,
        timestamp: '2025-08-04 23:18:22',
        user: 'jackbetherxi'
    });
});

console.log('WHMCS-only order creation system fully loaded at 2025-08-04 23:18:22 by jackbetherxi');
</script>

<?php 
// Footer
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
} else {
    echo '<footer class="bg-dark text-white text-center py-3 mt-5">
            <div class="container">
                <p>&copy; 2025 CaminhoIT. All rights reserved. WHMCS Integration Active.</p>
            </div>
          </footer>';
}
?>
    
</body>
</html>