<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

// Access control - allow all relevant roles
$allowed_roles = ['customer', 'administrator', 'support_agent', 'account_manager', 'accountant', 'support_consultant'];
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], $allowed_roles)) {
    header('Location: /login.php');
    exit;
}

// Define staff roles vs customer roles
$staff_roles = ['administrator', 'support_agent', 'account_manager', 'accountant', 'support_consultant'];
$is_staff = in_array($_SESSION['user']['role'], $staff_roles);

// Get user's accessible companies
$customer_id = $_SESSION['user']['id'];
$accessible_companies = [];

// Debug: Log the user info
error_log("=== CREATE ORDER DEBUG ===");
error_log("User ID: " . $customer_id);
error_log("User Role: " . $_SESSION['user']['role']);
error_log("Is Staff: " . ($is_staff ? 'Yes' : 'No'));

try {
    // Use same logic as user management page - get primary company first
    $companies = [];
    
    // Get user's primary company (if any)
    $stmt = $pdo->prepare("SELECT c.id, c.name, c.preferred_currency, c.currency_override 
                          FROM companies c 
                          JOIN users u ON c.id = u.company_id 
                          WHERE u.id = ?");
    $stmt->execute([$customer_id]);
    $primary_company = $stmt->fetch();
    
    if ($primary_company) {
        $companies[$primary_company['id']] = $primary_company;
        error_log("Primary Company: " . $primary_company['name'] . " (ID: " . $primary_company['id'] . ")");
    } else {
        error_log("No primary company found");
    }
    
    // Get multi-company access from company_users table (same as user management)
    $stmt = $pdo->prepare("SELECT c.id, c.name, c.preferred_currency, c.currency_override 
                          FROM companies c 
                          JOIN company_users cu ON c.id = cu.company_id 
                          WHERE cu.user_id = ?");
    $stmt->execute([$customer_id]);
    $multi_companies = $stmt->fetchAll();
    
    error_log("Multi-company query returned: " . count($multi_companies) . " results");
    
    foreach ($multi_companies as $company) {
        $companies[$company['id']] = $company;
        error_log("Multi Company: " . $company['name'] . " (ID: " . $company['id'] . ")");
    }
    
    // Convert to indexed array and sort
    $accessible_companies = array_values($companies);
    usort($accessible_companies, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
    
    error_log("Total Accessible Companies: " . count($accessible_companies));
    foreach ($accessible_companies as $company) {
        error_log("- " . $company['name'] . " (ID: " . $company['id'] . ")");
    }
    
} catch (PDOException $e) {
    error_log("Error fetching companies: " . $e->getMessage());
    $accessible_companies = [];
}

if (empty($accessible_companies)) {
    if ($is_staff) {
        $error = "Your account is not associated with any companies. Please contact an administrator to assign you to companies.";
        error_log("ERROR: Staff user has no company access");
    } else {
        $error = "Your account is not associated with any companies. Please contact support.";
        error_log("ERROR: Customer user has no company access");
    }
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

// Handle order creation
if (isset($_POST['create_order']) && !isset($error)) {
    $selected_company_id = (int)$_POST['company_id'];
    $billing_cycle = $_POST['billing_cycle'];
    $start_date = $_POST['start_date'];
    $notes = trim($_POST['notes']);
    $items = json_decode($_POST['order_items'], true);
    $order_currency = $_POST['order_currency'] ?? $defaultCurrency;
    $place_order = isset($_POST['place_order']); // Check if user wants to place order immediately

    // Validate company access
    $company_allowed = false;
    foreach ($accessible_companies as $company) {
        if ($company['id'] == $selected_company_id) {
            $company_allowed = true;
            break;
        }
    }

    if (!$company_allowed) {
        $error = "You don't have permission to create orders for the selected company.";
    } elseif (empty($items)) {
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

            // Determine order status based on user role and action
            if ($is_staff) {
                // Staff users - orders can go directly to pending payment when placed
                if ($place_order) {
                    $initial_status = 'pending_payment';
                    $payment_status = 'unpaid';
                    $placed_at = date('Y-m-d H:i:s');
                } else {
                    $initial_status = 'draft';
                    $payment_status = 'unpaid';
                    $placed_at = null;
                }
            } else {
                // Customer users - orders need approval first
                if ($place_order) {
                    $initial_status = 'pending_approval';
                    $payment_status = 'unpaid';
                    $placed_at = date('Y-m-d H:i:s');
                } else {
                    $initial_status = 'draft';
                    $payment_status = 'unpaid';
                    $placed_at = null;
                }
            }

            // Convert boolean to integer for database compatibility
            $vat_enabled_int = $vat_enabled ? 1 : 0;

            // Create order - use customer_id for customers, staff_id for staff
            if (!$is_staff) {
                $stmt = $pdo->prepare("INSERT INTO orders (order_number, company_id, customer_id, status, payment_status, order_type, subtotal, tax_amount, total_amount, currency, customer_currency, vat_rate, vat_enabled, notes, billing_cycle, start_date, placed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$order_number, $selected_company_id, $customer_id, $initial_status, $payment_status, 'new', $subtotal, $tax_amount, $total_amount, $order_currency, $order_currency, $vat_rate, $vat_enabled_int, $notes, $billing_cycle, $start_date, $placed_at]);
            } else {
                // Staff creating order - use staff_id
                $stmt = $pdo->prepare("INSERT INTO orders (order_number, company_id, staff_id, status, payment_status, order_type, subtotal, tax_amount, total_amount, currency, customer_currency, vat_rate, vat_enabled, notes, billing_cycle, start_date, placed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$order_number, $selected_company_id, $customer_id, $initial_status, $payment_status, 'new', $subtotal, $tax_amount, $total_amount, $order_currency, $order_currency, $vat_rate, $vat_enabled_int, $notes, $billing_cycle, $start_date, $placed_at]);
            }

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
                    $total_amount / $conversion_rate,
                    $total_amount,
                    'order',
                    $order_id,
                    $customer_id
                );
            }

            // Log status change
            $user_type = $is_staff ? 'staff (' . $_SESSION['user']['role'] . ')' : 'customer';
            if ($place_order) {
                if ($is_staff) {
                    $status_note = "Order created and placed by $user_type - awaiting payment (Currency: $order_currency)";
                } else {
                    $status_note = "Order created and submitted by $user_type - awaiting approval (Currency: $order_currency)";
                }
            } else {
                $status_note = "Order created as draft by $user_type (Currency: $order_currency)";
            }
            
            if ($vat_enabled) {
                $status_note .= " (VAT: " . ($vat_rate * 100) . "%)";
            } else {
                $status_note .= " (VAT: Not applicable)";
            }
            
            // Add subscription information to notes
            $subscription_info = [];
            foreach ($items as $item) {
                if ($item['billing_cycle'] !== 'one_time') {
                    $subscription_info[] = $item['name'] . " (" . ucfirst($item['billing_cycle']) . " billing)";
                }
            }
            if (!empty($subscription_info)) {
                $status_note .= " | Will create subscriptions: " . implode(', ', $subscription_info);
            }
            
            $stmt = $pdo->prepare("INSERT INTO order_status_history (order_id, status_to, changed_by, notes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$order_id, $initial_status, $customer_id, $status_note]);

            $pdo->commit();

            // Set success message based on action and user type
            if ($place_order) {
                if ($is_staff) {
                    $success_message = 'order_placed_pending_payment';
                } else {
                    $success_message = 'order_submitted_pending_approval';
                }
            } else {
                $success_message = 'order_created_draft';
            }

            // Always redirect to members view for this customer interface
            header("Location: /members/view-order.php?id=$order_id&success=$success_message");
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error creating order: " . $e->getMessage();
        }
    }
}

// Get products with subscription information
$stmt = $pdo->query("SELECT p.*, c.name as category_name,
    CASE 
        WHEN p.billing_cycle IN ('monthly', 'quarterly', 'annually') THEN 'recurring'
        WHEN p.billing_cycle = 'one_time' THEN 'one_time'
        ELSE 'recurring'
    END as subscription_type
    FROM products p 
    JOIN service_categories c ON p.category_id = c.id 
    WHERE p.is_active = 1 
    ORDER BY c.sort_order ASC, p.name ASC");
$products = $stmt->fetchAll();

// Get bundles with subscription information
$stmt = $pdo->query("SELECT *,
    CASE 
        WHEN billing_cycle IN ('monthly', 'quarterly', 'annually') THEN 'recurring'
        WHEN billing_cycle = 'one_time' THEN 'one_time'
        ELSE 'recurring'
    END as subscription_type
    FROM service_bundles 
    WHERE is_active = 1 
    ORDER BY name ASC");
$bundles = $stmt->fetchAll();

$page_title = "Create Order | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --card-shadow-hover: 0 20px 40px rgba(0, 0, 0, 0.15);
            --border-radius: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --primary-color: #4F46E5;
            --success-color: #10B981;
            --warning-color: #F59E0B;
            --danger-color: #EF4444;
            --info-color: #06B6D4;
        }

        body {
            background-color: #f8fafc;
        }

        /* Hero Section Styles */
        .create-order-hero-content {
            text-align: center;
            padding: 4rem 0;
            position: relative;
            z-index: 2;
        }

        .create-order-hero-title {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
            color: white;
        }

        .create-order-hero-subtitle {
            font-size: 1.25rem;
            margin-bottom: 1.5rem;
            opacity: 0.9;
            color: white;
        }

        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
            position: relative;
            z-index: 10;
            margin-top: -60px;
        }

        .breadcrumb-enhanced {
            background: white;
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 1.25rem 1.75rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
        }

        .breadcrumb-enhanced::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary-gradient);
        }

        .breadcrumb-enhanced .breadcrumb {
            margin-bottom: 0;
        }

        .alert {
            border-radius: var(--border-radius);
            padding: 1.25rem 1.5rem;
            margin-bottom: 2rem;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }

        .alert-info {
            background: linear-gradient(135deg, #e0f2fe 0%, #dbeafe 100%);
            color: #1e40af;
            border-left: 4px solid #3b82f6;
        }

        .alert-danger {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .form-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            position: relative;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            border: 1px solid #e2e8f0;
        }

        .form-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .form-card h4, .form-card h5 {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }

        .template-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .form-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .catalog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .product-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow);
            border-color: #667eea;
        }

        .product-card.selected {
            border-color: #667eea;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .currency-converted {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            background: var(--info-gradient);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .subscription-badge {
            position: absolute;
            top: 0.75rem;
            left: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            color: white;
        }

        .subscription-badge.recurring {
            background: var(--success-gradient);
        }

        .subscription-badge.one-time {
            background: var(--warning-gradient);
        }

        .order-items {
            background: #f8fafc;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .order-item {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .order-summary {
            background: white;
            border-radius: var(--border-radius);
            padding: 0;
            position: sticky;
            top: 100px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        .order-summary::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .order-summary h5 {
            background: var(--primary-gradient);
            color: white;
            margin: 0 !important;
            padding: 1.25rem 2rem !important;
            font-weight: 600;
        }

        .order-summary > div:not(:first-child) {
            padding: 0 2rem;
        }

        .order-summary > div:nth-child(2) {
            padding-top: 1.5rem;
        }

        .order-summary > div:last-child {
            padding-bottom: 2rem;
        }

        .order-summary > .alert {
            margin: 0 2rem 1.5rem 2rem !important;
        }

        .order-summary hr {
            margin: 1rem 2rem;
        }

        .currency-info {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 1px solid #3b82f6;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .currency-badge {
            background: var(--primary-gradient);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .currency-badge.default {
            background: #6b7280;
        }

        .subscription-info {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #10b981;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .vat-info {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #10b981;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .vat-info.disabled {
            background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
            border-color: #6b7280;
            color: #6b7280;
        }

        .nav-tabs {
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 0;
            margin-left: -2rem;
            margin-right: -2rem;
            padding-left: 2rem;
            padding-right: 2rem;
            background: #f8fafc;
        }

        .nav-tabs .nav-link {
            border: none;
            padding: 1rem 1.5rem;
            font-weight: 500;
            color: #6b7280;
            transition: var(--transition);
        }

        .nav-tabs .nav-link:hover {
            color: #667eea;
            background: rgba(102, 126, 234, 0.05);
        }

        .nav-tabs .nav-link.active {
            color: #667eea;
            background: white;
            border-bottom: 3px solid #667eea;
        }

        .tab-content {
            padding: 2rem 0;
        }

        .tab-pane {
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-primary { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1e40af; }
        .badge-success { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #065f46; }
        .badge-warning { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #92400e; }
        .badge-featured { background: linear-gradient(135deg, #fce7f3 0%, #fbcfe8 100%); color: #be185d; }
        .badge-recurring { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #065f46; }
        .badge-one-time { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #92400e; }

        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .form-text {
            margin-top: 0.5rem;
            color: #6b7280;
        }

        .form-control, .form-select {
            border: 2px solid #e5e7eb;
            border-radius: var(--border-radius);
            padding: 0.75rem 1rem;
            transition: var(--transition);
            font-size: 0.95rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            outline: none;
        }

        .mb-3 {
            margin-bottom: 1.5rem !important;
        }

        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 25px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-outline-primary {
            background: transparent;
            color: #667eea;
            border: 2px solid #667eea;
            padding: 0.75rem 2rem;
            border-radius: 25px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            color: white;
            transform: translateY(-2px);
        }

        .alert {
            border: none;
            border-radius: var(--border-radius);
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
        }

        .alert-danger {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .alert-warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
            border-left: 4px solid #f59e0b;
        }

        .alert-info {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
            border-left: 4px solid #3b82f6;
        }

        .company-info {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 2px solid #3b82f6;
            border-radius: var(--border-radius);
            padding: 1.75rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .company-info::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary-gradient);
        }

        .company-info h6 {
            color: #1e40af;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .company-info p {
            margin-bottom: 0;
            color: #1e40af;
        }

        .workflow-indicator {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
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
            position: relative;
        }

        .workflow-step.active {
            color: #667eea;
            font-weight: 600;
        }

        .workflow-step.active .step-icon {
            background: var(--primary-gradient);
            color: white;
        }

        .step-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-size: 1rem;
        }

        .workflow-arrow {
            color: #d1d5db;
            margin: 0 1rem;
            font-size: 1.2rem;
        }

        .fade-in {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.6s ease;
        }

        .fade-in.visible {
            opacity: 1;
            transform: translateY(0);
        }

        @media (max-width: 768px) {
            .hero-title-enhanced { font-size: 2rem; }
            .catalog-grid { grid-template-columns: 1fr; }
            .workflow-steps {
                flex-direction: column;
                gap: 1rem;
            }
            .workflow-arrow {
                transform: rotate(90deg);
            }
        }

        /* DARK MODE STYLES */
        html.dark {
            background: #0f172a;
            color: #e2e8f0;
        }

        html.dark body {
            background: #0f172a;
            color: #e2e8f0;
        }

        /* FORCE purple hero gradient to show in dark mode - SAME as light mode */
        :root.dark .hero {
            background: transparent !important;
        }

        :root.dark .hero-gradient {
            /* Don't override the background - keep it the same as light mode! */
            opacity: 1 !important;
            display: block !important;
            visibility: visible !important;
            z-index: 0 !important;
        }

        /* Beautiful fade at bottom of hero in dark mode */
        :root.dark .hero::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 150px;
            background: linear-gradient(
                to bottom,
                rgba(15, 23, 42, 0) 0%,
                rgba(15, 23, 42, 0.7) 50%,
                #0f172a 100%
            ) !important;
            pointer-events: none;
            z-index: 1;
        }

        :root.dark .create-order-hero-title,
        :root.dark .create-order-hero-subtitle {
            color: white !important;
            position: relative;
            z-index: 2;
        }

        html.dark .breadcrumb-enhanced {
            background: #1e293b;
            border-color: #334155;
            color: #e2e8f0;
        }

        html.dark .breadcrumb-item {
            color: #94a3b8;
        }

        html.dark .breadcrumb-item.active {
            color: #e2e8f0;
        }

        html.dark .breadcrumb-item a {
            color: #a78bfa;
        }

        html.dark .alert-info {
            background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
            color: #dbeafe;
        }

        html.dark .alert-danger {
            background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 100%);
            color: #fecaca;
        }

        html.dark .form-card {
            background: #1e293b;
            border-color: #334155;
        }

        html.dark .form-card h4,
        html.dark .form-card h5 {
            color: #a78bfa;
        }

        html.dark .form-label {
            color: #cbd5e1;
        }

        html.dark .form-control,
        html.dark .form-select {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }

        html.dark .form-control:focus,
        html.dark .form-select:focus {
            background: #0f172a;
            border-color: #8b5cf6;
            color: #e2e8f0;
        }

        html.dark .form-text {
            color: #94a3b8;
        }

        html.dark .company-info {
            background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
            border-color: #3b82f6;
        }

        html.dark .company-info h6,
        html.dark .company-info p {
            color: #dbeafe;
        }

        html.dark .nav-tabs {
            background: #0f172a;
            border-color: #334155;
        }

        html.dark .nav-tabs .nav-link {
            color: #94a3b8;
        }

        html.dark .nav-tabs .nav-link:hover {
            color: #a78bfa;
            background: rgba(139, 92, 246, 0.1);
        }

        html.dark .nav-tabs .nav-link.active {
            background: #1e293b;
            color: #a78bfa;
        }

        html.dark .product-card {
            background: #1e293b;
            border-color: #334155;
            color: #e2e8f0;
        }

        html.dark .product-card:hover {
            border-color: #8b5cf6;
        }

        html.dark .product-card.selected {
            background: linear-gradient(135deg, #1e3a5f 0%, #1e293b 100%);
            border-color: #8b5cf6;
        }

        html.dark .product-card h6 {
            color: #f1f5f9;
        }

        html.dark .product-card .text-muted {
            color: #94a3b8 !important;
        }

        /* Fix empty state text visibility in dark mode */
        html.dark .text-muted {
            color: #94a3b8 !important;
        }

        html.dark #orderItemsList p,
        html.dark #orderItemsList small {
            color: #94a3b8 !important;
        }

        html.dark .order-summary {
            background: #1e293b;
            border-color: #334155;
        }

        html.dark .order-summary h5 {
            background: var(--primary-gradient);
            color: white;
        }

        html.dark .currency-info {
            background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
            border-color: #3b82f6;
            color: #dbeafe;
        }

        html.dark .subscription-info,
        html.dark .vat-info {
            background: linear-gradient(135deg, #064e3b 0%, #065f46 100%);
            border-color: #10b981;
            color: #d1fae5;
        }

        html.dark .vat-info.disabled {
            background: linear-gradient(135deg, #1f2937 0%, #374151 100%);
            border-color: #6b7280;
            color: #9ca3af;
        }

        html.dark .workflow-indicator {
            background: #1e293b;
            border-color: #334155;
        }

        html.dark .workflow-steps {
            color: #94a3b8;
        }

        html.dark .step-icon {
            background: #334155;
            color: #94a3b8;
        }

        html.dark .workflow-step.active {
            color: #a78bfa;
        }

        html.dark .workflow-arrow {
            color: #475569;
        }

        html.dark .table {
            color: #e2e8f0;
        }

        html.dark .table tbody td {
            border-color: #334155;
        }

        html.dark small {
            color: #94a3b8;
        }

        /* Alert info box (Save as Draft info) */
        :root.dark .alert-info {
            background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%) !important;
            border-color: #3b82f6 !important;
            color: #dbeafe !important;
        }

        :root.dark .alert-info strong {
            color: #bfdbfe !important;
        }

        /* Order items container */
        :root.dark .order-items {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        /* Individual order item */
        :root.dark .order-item {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .order-item:hover {
            border-color: #8b5cf6 !important;
        }

        /* Order item text */
        :root.dark .order-item h6,
        :root.dark .order-item strong {
            color: #f1f5f9 !important;
        }

        /* Enhanced padding for order summary */
        .order-summary > div:nth-child(2) {
            padding-top: 1.5rem !important;
        }

        .order-summary > div {
            padding-left: 2rem !important;
            padding-right: 2rem !important;
        }

        .order-summary > div:last-child {
            padding-bottom: 2rem !important;
        }
    </style>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="create-order-hero-content">
            <h1 class="create-order-hero-title">
                <i class="bi bi-cart-plus me-3"></i>
                Create New Order
            </h1>
            <p class="create-order-hero-subtitle">
                Select services and create an order for your company. Recurring services will automatically become subscriptions for license management.
            </p>
        </div>
    </div>
</header>

<div class="main-container">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="breadcrumb-enhanced fade-in">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/members/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="/members/orders.php">Orders</a></li>
            <li class="breadcrumb-item active" aria-current="page">Create Order</li>
        </ol>
    </nav>

    <!-- Staff Notice -->
    <?php if ($is_staff): ?>
        <div class="alert alert-info fade-in">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Staff Access:</strong> As a <?= ucwords(str_replace('_', ' ', $_SESSION['user']['role'])) ?>, your orders can be placed directly for payment processing.
        </div>
    <?php endif; ?>

    <!-- Error Messages -->
    <?php if (isset($error)): ?>
        <div class="alert alert-danger fade-in">
            <i class="bi bi-exclamation-triangle me-2"></i><?= $error ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($accessible_companies)): ?>
    <form method="POST" id="orderForm">
        <div class="row">
            <div class="col-lg-8">
                <!-- Company Selection -->
                <div class="form-card fade-in">
                    <h5 class="mb-4">
                        <i class="bi bi-building me-2"></i>
                        Company Selection
                    </h5>
                    
                    <?php if (count($accessible_companies) === 1): ?>
                        <!-- Single company - show info and use hidden input -->
                        <div class="company-info">
                            <h6 class="mb-2">Ordering for: <?= htmlspecialchars($accessible_companies[0]['name']) ?></h6>
                            <p class="text-muted mb-0">
                                This order will be created for the selected company.
                            </p>
                        </div>
                        <input type="hidden" name="company_id" value="<?= $accessible_companies[0]['id'] ?>">
                    <?php else: ?>
                        <!-- Multiple companies - show dropdown -->
                        <div class="mb-3">
                            <label class="form-label">Select Company *</label>
                            <select name="company_id" class="form-select" required onchange="updateCompanyCurrency()">
                                <option value="">Choose Company</option>
                                <?php foreach ($accessible_companies as $company): ?>
                                    <option value="<?= $company['id'] ?>" 
                                            data-currency="<?= $company['preferred_currency'] ?>"
                                            data-currency-override="<?= $company['currency_override'] ?>">
                                        <?= htmlspecialchars($company['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Select which company you're ordering for
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Order Details -->
                <div class="form-card fade-in">
                    <h5 class="mb-4">
                        <i class="bi bi-clipboard-data me-2"></i>
                        Order Details
                    </h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Billing Cycle *</label>
                                <select name="billing_cycle" class="form-select" required>
                                    <option value="">Select Cycle</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="annually">Annually</option>
                                    <option value="one_time">One-time Purchase</option>
                                </select>
                                <div class="form-text">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle me-1"></i>
                                        Monthly/Quarterly/Annual orders become recurring subscriptions. One-time purchases do not renew automatically.
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Preferred Start Date *</label>
                                <input type="date" name="start_date" class="form-control" required>
                                <div class="form-text">
                                    <small class="text-muted">For recurring services, this is when your subscription begins</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Additional Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Any specific requirements or questions..."></textarea>
                    </div>
                </div>

                <!-- Service Catalog -->
                <div class="form-card fade-in">
                    <h5 class="mb-4">
                        <i class="bi bi-grid-3x3-gap me-2"></i>
                        Select Services
                    </h5>
                    
                    <ul class="nav nav-tabs" id="catalogTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="products-tab" data-bs-toggle="tab" data-bs-target="#products" type="button" role="tab">
                                <i class="bi bi-box me-2"></i>Products (<?= count($products) ?>)
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="bundles-tab" data-bs-toggle="tab" data-bs-target="#bundles" type="button" role="tab">
                                <i class="bi bi-collection me-2"></i>Service Bundles (<?= count($bundles) ?>)
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="catalogTabsContent">
                        <!-- Products Tab -->
                        <div class="tab-pane fade show active" id="products" role="tabpanel">
                            <?php if (count($products) > 0): ?>
                                <div class="catalog-grid">
                                    <?php foreach ($products as $product): ?>
                                        <div class="product-card" onclick="selectProduct(<?= $product['id'] ?>, 'product')" 
                                             data-base-price="<?= $product['base_price'] ?>" 
                                             data-setup-fee="<?= $product['setup_fee'] ?? 0 ?>"
                                             data-billing-cycle="<?= $product['billing_cycle'] ?>"
                                             data-subscription-type="<?= $product['subscription_type'] ?>">
                                            <div class="currency-converted" style="display: none;">Converted</div>
                                            <div class="subscription-badge <?= $product['subscription_type'] ?>">
                                                <?= $product['subscription_type'] === 'recurring' ? 'Subscription' : 'One-time' ?>
                                            </div>
                                            <h6 class="mb-2 mt-3"><?= htmlspecialchars($product['name']) ?></h6>
                                            <p class="text-muted small mb-3"><?= htmlspecialchars($product['short_description']) ?></p>
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div>
                                                    <span class="badge badge-primary"><?= htmlspecialchars($product['category_name']) ?></span>
                                                    <span class="badge badge-<?= $product['subscription_type'] === 'recurring' ? 'recurring' : 'one-time' ?>">
                                                        <?= ucfirst(str_replace('_', ' ', $product['billing_cycle'])) ?>
                                                    </span>
                                                    <?php if ($product['is_featured']): ?>
                                                        <span class="badge badge-featured">Featured</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="text-center">
                                                <div class="h5 mb-1">
                                                    <span class="currency-symbol">£</span><span class="price-amount"><?= number_format($product['base_price'], 2) ?></span>
                                                </div>
                                                <small class="text-muted">
                                                    /<?= str_replace('_', ' ', $product['unit_type']) ?>
                                                    <?php if ($product['subscription_type'] === 'recurring'): ?>
                                                        per <?= str_replace('_', ' ', $product['billing_cycle']) ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-box" style="font-size: 3rem; opacity: 0.3;"></i>
                                    <p class="mt-3 mb-0">No products available</p>
                                    <small class="text-muted">Contact an administrator to add products</small>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Bundles Tab -->
                        <div class="tab-pane fade" id="bundles" role="tabpanel">
                            <?php if (count($bundles) > 0): ?>
                                <div class="catalog-grid">
                                    <?php foreach ($bundles as $bundle): ?>
                                        <div class="product-card" onclick="selectProduct(<?= $bundle['id'] ?>, 'bundle')"
                                             data-base-price="<?= $bundle['bundle_price'] ?>" 
                                             data-setup-fee="0"
                                             data-billing-cycle="<?= $bundle['billing_cycle'] ?>"
                                             data-subscription-type="<?= $bundle['subscription_type'] ?>">
                                            <div class="currency-converted" style="display: none;">Converted</div>
                                            <div class="subscription-badge <?= $bundle['subscription_type'] ?>">
                                                <?= $bundle['subscription_type'] === 'recurring' ? 'Subscription' : 'One-time' ?>
                                            </div>
                                            <h6 class="mb-2 mt-3"><?= htmlspecialchars($bundle['name']) ?></h6>
                                            <p class="text-muted small mb-3"><?= htmlspecialchars($bundle['short_description']) ?></p>
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div>
                                                    <span class="badge badge-warning"><?= htmlspecialchars($bundle['target_audience']) ?></span>
                                                    <span class="badge badge-<?= $bundle['subscription_type'] === 'recurring' ? 'recurring' : 'one-time' ?>">
                                                        <?= ucfirst($bundle['billing_cycle']) ?>
                                                    </span>
                                                    <?php if ($bundle['is_featured']): ?>
                                                        <span class="badge badge-featured">Featured</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="text-center">
                                                <div class="h5 mb-1">
                                                    <span class="currency-symbol">£</span><span class="price-amount"><?= number_format($bundle['bundle_price'], 2) ?></span>
                                                </div>
                                                <small class="text-muted">
                                                    <?php if ($bundle['subscription_type'] === 'recurring'): ?>
                                                        per <?= $bundle['billing_cycle'] ?>
                                                    <?php else: ?>
                                                        one-time
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-collection" style="font-size: 3rem; opacity: 0.3;"></i>
                                    <p class="mt-3 mb-0">No service bundles available</p>
                                    <small class="text-muted">Contact an administrator to add service bundles</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Order Items -->
                <div class="order-items fade-in">
                    <h5 class="mb-3">
                        <i class="bi bi-cart me-2"></i>
                        Your Order
                    </h5>
                    <div id="orderItemsList">
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-cart" style="font-size: 3rem; opacity: 0.3;"></i>
                            <p class="mt-3 mb-0">No items selected yet</p>
                            <small class="text-muted">Choose products or bundles from above to get started</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <!-- Currency Information -->
                <div class="currency-info fade-in" id="currencyInfo" style="display: none;">
                    <h6 class="mb-3">
                        <i class="bi bi-currency-exchange me-2"></i>
                        Currency Information
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
                            Exchange Rate: 1 <?= $defaultCurrency ?> = <span id="exchangeRateValue">1.00</span> <span id="targetCurrencyCode"><?= $defaultCurrency ?></span>
                        </small>
                    </div>
                </div>

                <!-- Subscription Information -->
                <div class="subscription-info fade-in" id="subscriptionInfo" style="display: none;">
                    <h6 class="mb-3">
                        <i class="bi bi-arrow-repeat me-2"></i>
                        Subscription Management
                    </h6>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>Will Create Subscriptions:</span>
                        <span id="subscriptionCount">0</span>
                    </div>
                    <small class="text-muted d-block" id="subscriptionNote">
                        Recurring services will automatically become managed subscriptions for license assignment.
                    </small>
                </div>

                <!-- VAT Information -->
                <div class="vat-info fade-in" id="vatInfo">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span><i class="bi bi-receipt me-2"></i>Tax Information:</span>
                        <span id="vatStatus">Calculating...</span>
                    </div>
                    <small class="text-muted d-block" id="vatNote">
                        Tax rates applied based on location
                    </small>
                </div>

                <!-- Workflow Indicator -->
                <div class="workflow-indicator fade-in">
                    <h6 class="mb-3">Order Process</h6>
                    <div class="workflow-steps">
                        <div class="workflow-step active">
                            <div class="step-icon">
                                <i class="bi bi-pencil"></i>
                            </div>
                            <small>Create</small>
                        </div>
                        <div class="workflow-arrow">→</div>
                        <?php if ($is_staff): ?>
                            <div class="workflow-step">
                                <div class="step-icon">
                                    <i class="bi bi-credit-card"></i>
                                </div>
                                <small>Payment</small>
                            </div>
                        <?php else: ?>
                            <div class="workflow-step">
                                <div class="step-icon">
                                    <i class="bi bi-person-check"></i>
                                </div>
                                <small>Approval</small>
                            </div>
                            <div class="workflow-arrow">→</div>
                            <div class="workflow-step">
                                <div class="step-icon">
                                    <i class="bi bi-credit-card"></i>
                                </div>
                                <small>Payment</small>
                            </div>
                        <?php endif; ?>
                        <div class="workflow-arrow">→</div>
                        <div class="workflow-step">
                            <div class="step-icon">
                                <i class="bi bi-arrow-repeat"></i>
                            </div>
                            <small>Subscriptions</small>
                        </div>
                    </div>
                </div>

                <!-- Order Summary -->
                <div class="order-summary fade-in">
                    <h5 class="mb-3">
                        <i class="bi bi-receipt-cutoff me-2"></i>
                        Order Summary
                    </h5>
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
                    <div class="d-flex justify-content-between mb-2" id="vatRow" style="display: none;">
                        <span>VAT (<span id="vatPercent">20</span>%):</span>
                        <span id="vat"><span class="currency-symbol">£</span>0.00</span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between mb-4">
                        <strong>Total:</strong>
                        <strong id="total"><span class="currency-symbol">£</span>0.00</strong>
                    </div>
                    
                    <div class="alert alert-info small mb-4">
                        <i class="bi bi-info-circle me-2"></i>
                        <?php if ($is_staff): ?>
                            <strong>Save as Draft:</strong> Save your order to complete later.<br>
                            <strong>Place Order:</strong> Submit directly for payment processing and subscription creation.
                        <?php else: ?>
                            <strong>Save as Draft:</strong> Save your order to complete later.<br>
                            <strong>Submit Order:</strong> Send for Account Manager approval and subscription setup.
                        <?php endif; ?>
                    </div>
                    
                    <input type="hidden" name="order_items" id="orderItemsInput">
                    <input type="hidden" name="order_currency" id="orderCurrencyInput" value="<?= $defaultCurrency ?>">
                    
                    <div class="d-grid gap-2">
                        <button type="submit" name="create_order" class="btn btn-outline-primary">
                            <i class="bi bi-save me-2"></i>Save as Draft
                        </button>
                        <button type="submit" name="create_order" class="btn btn-primary" onclick="document.querySelector('input[name=place_order]').value='1'">
                            <?php if ($is_staff): ?>
                                <i class="bi bi-credit-card me-2"></i>Place Order
                            <?php else: ?>
                                <i class="bi bi-send me-2"></i>Submit for Approval
                            <?php endif; ?>
                        </button>
                    </div>
                    <input type="hidden" name="place_order" value="0">
                    
                    <div class="text-center mt-3">
                        <small class="text-muted">
                            <i class="bi bi-shield-check me-1"></i>
                            Secure order processing with automatic subscription management
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// JavaScript variables with proper null checks
const products = <?= json_encode($products) ?>;
const bundles = <?= json_encode($bundles) ?>;
const supportedCurrencies = <?= json_encode($supportedCurrencies) ?>;
const defaultCurrency = '<?= $defaultCurrency ?>';
const exchangeRates = <?= json_encode($exchangeRates) ?>;
const vatSettings = <?= json_encode($vatSettings) ?>;
const accessibleCompanies = <?= json_encode($accessible_companies) ?>;
const isStaff = <?= $is_staff ? 'true' : 'false' ?>;

let orderItems = [];
let currentCurrency = defaultCurrency;
let currentCurrencySymbol = supportedCurrencies[defaultCurrency] ? supportedCurrencies[defaultCurrency].symbol : '£';
let currentExchangeRate = 1.0;
let currentVatRate = 0.20;
let vatEnabled = true;

// Initialize on page load with proper null checks
document.addEventListener('DOMContentLoaded', function() {
    const startDateInput = document.querySelector('input[name="start_date"]');
    if (startDateInput) {
        // Set default to 7 days from now
        const defaultDate = new Date();
        defaultDate.setDate(defaultDate.getDate() + 7);
        startDateInput.value = defaultDate.toISOString().split('T')[0];
    }
    
    // Initialize for single company
    if (accessibleCompanies.length === 1) {
        const company = accessibleCompanies[0];
        if (company.currency_override && company.preferred_currency && supportedCurrencies[company.preferred_currency]) {
            currentCurrency = company.preferred_currency;
            currentCurrencySymbol = supportedCurrencies[company.preferred_currency].symbol;
            currentExchangeRate = exchangeRates[company.preferred_currency] || 1.0;
            
            const orderCurrencyInput = document.getElementById('orderCurrencyInput');
            if (orderCurrencyInput) {
                orderCurrencyInput.value = currentCurrency;
            }
            
            const currencyInfo = document.getElementById('currencyInfo');
            const currencyBadge = document.getElementById('orderCurrencyBadge');
            const currencyNote = document.getElementById('currencyNote');
            
            if (currencyBadge) {
                currencyBadge.textContent = currentCurrency;
                currencyBadge.className = 'currency-badge';
            }
            if (currencyNote) {
                currencyNote.textContent = `Using company's preferred currency (${supportedCurrencies[currentCurrency].name})`;
            }
            if (currencyInfo) {
                currencyInfo.style.display = 'block';
            }
        }
    }
    
    updateVatSettings();
    updateCurrencyDisplay();
    updateSubscriptionInfo();
    
    // Fade in animations
    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

    document.querySelectorAll('.fade-in').forEach(el => {
        observer.observe(el);
    });
});

function updateCompanyCurrency() {
    const companySelect = document.querySelector('select[name="company_id"]');
    if (!companySelect) return;
    
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
            
            if (currencyBadge) {
                currencyBadge.textContent = companyCurrency;
                currencyBadge.className = 'currency-badge';
            }
            if (currencyNote) {
                currencyNote.textContent = `Using company's preferred currency (${supportedCurrencies[companyCurrency].name})`;
            }
            
            // Show exchange rate info if converting
            if (companyCurrency !== defaultCurrency) {
                if (exchangeRateInfo) exchangeRateInfo.style.display = 'block';
                if (exchangeRateValue) exchangeRateValue.textContent = currentExchangeRate.toFixed(4);
                if (targetCurrencyCode) targetCurrencyCode.textContent = companyCurrency;
            } else {
                if (exchangeRateInfo) exchangeRateInfo.style.display = 'none';
            }
        } else {
            currentCurrency = defaultCurrency;
            currentCurrencySymbol = supportedCurrencies[defaultCurrency] ? supportedCurrencies[defaultCurrency].symbol : '£';
            currentExchangeRate = 1.0;
            
            if (currencyBadge) {
                currencyBadge.textContent = defaultCurrency;
                currencyBadge.className = 'currency-badge default';
            }
            if (currencyNote) {
                currencyNote.textContent = 'Using system default currency';
            }
            if (exchangeRateInfo) exchangeRateInfo.style.display = 'none';
        }
        
        if (currencyInfo) currencyInfo.style.display = 'block';
        updateVatSettings();
        
        // Convert existing cart items to new currency
        if (previousCurrency !== currentCurrency && orderItems.length > 0) {
            convertExistingCartItems(previousCurrency, currentCurrency, previousExchangeRate, currentExchangeRate);
        }
        
        updateCurrencyDisplay();
    } else {
        if (currencyInfo) currencyInfo.style.display = 'none';
        currentCurrency = defaultCurrency;
        currentCurrencySymbol = '£';
        currentExchangeRate = 1.0;
        updateVatSettings();
        updateCurrencyDisplay();
    }
    
    // Update hidden input
    const orderCurrencyInput = document.getElementById('orderCurrencyInput');
    if (orderCurrencyInput) {
        orderCurrencyInput.value = currentCurrency;
    }
}

function convertExistingCartItems(fromCurrency, toCurrency, fromRate, toRate) {
    if (orderItems.length === 0) return;
    
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
            
            if (vatInfo) vatInfo.className = 'vat-info';
            if (vatStatus) vatStatus.textContent = `${(currencyVat.rate * 100).toFixed(0)}% VAT`;
            if (vatNote) vatNote.textContent = 'VAT will be applied to applicable items';
            if (vatRow) vatRow.style.display = 'flex';
            if (vatPercent) vatPercent.textContent = (currencyVat.rate * 100).toFixed(0);
        } else {
            vatEnabled = false;
            currentVatRate = 0;
            
            if (vatInfo) vatInfo.className = 'vat-info disabled';
            if (vatStatus) vatStatus.textContent = 'No VAT';
            if (vatNote) vatNote.textContent = `VAT not applicable for ${currentCurrency} orders`;
            if (vatRow) vatRow.style.display = 'none';
        }
    } else {
        vatEnabled = false;
        currentVatRate = 0;
        
        if (vatInfo) vatInfo.className = 'vat-info disabled';
        if (vatStatus) vatStatus.textContent = 'No VAT';
        if (vatNote) vatNote.textContent = 'VAT not enabled for this currency';
        if (vatRow) vatRow.style.display = 'none';
    }
}

function updateCurrencyDisplay() {
    const currencySymbols = document.querySelectorAll('.currency-symbol');
    currencySymbols.forEach(symbol => {
        symbol.textContent = currentCurrencySymbol;
    });
    
    updateProductPrices();
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
            
            if (currentCurrency !== defaultCurrency && convertedBadge) {
                convertedBadge.style.display = 'block';
            } else if (convertedBadge) {
                convertedBadge.style.display = 'none';
            }
        }
    });
}

function updateSubscriptionInfo() {
    const subscriptionInfo = document.getElementById('subscriptionInfo');
    const subscriptionCount = document.getElementById('subscriptionCount');
    const subscriptionNote = document.getElementById('subscriptionNote');
    
    // Count recurring items
    const recurringItems = orderItems.filter(item => 
        item.billing_cycle && !['one_time', 'usage'].includes(item.billing_cycle)
    );
    
    if (recurringItems.length > 0) {
        if (subscriptionInfo) subscriptionInfo.style.display = 'block';
        if (subscriptionCount) subscriptionCount.textContent = recurringItems.length;
        if (subscriptionNote) {
            subscriptionNote.textContent = `${recurringItems.length} recurring service${recurringItems.length !== 1 ? 's' : ''} will become managed subscriptions for license assignment.`;
        }
    } else {
        if (subscriptionInfo) subscriptionInfo.style.display = 'none';
    }
}

function selectProduct(id, type) {
    let item;
    
    if (type === 'product') {
        item = products.find(p => p.id == id);
        if (!item) return;
        
        const existingIndex = orderItems.findIndex(oi => oi.product_id == id && oi.item_type === 'product');
        if (existingIndex >= 0) {
            orderItems[existingIndex].quantity++;
            orderItems[existingIndex].line_total = orderItems[existingIndex].unit_price * orderItems[existingIndex].quantity;
        } else {
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
                subscription_type: item.subscription_type,
                base_price: parseFloat(item.base_price),
                base_setup_fee: parseFloat(item.setup_fee || 0)
            });
        }
    } else if (type === 'bundle') {
        item = bundles.find(b => b.id == id);
        if (!item) return;
        
        const existingIndex = orderItems.findIndex(oi => oi.bundle_id == id && oi.item_type === 'bundle');
        if (existingIndex >= 0) {
            orderItems[existingIndex].quantity++;
            orderItems[existingIndex].line_total = orderItems[existingIndex].unit_price * orderItems[existingIndex].quantity;
        } else {
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
                subscription_type: item.subscription_type,
                base_price: parseFloat(item.bundle_price),
                base_setup_fee: 0
            });
        }
    }
    
    updateOrderDisplay();
    updateSubscriptionInfo();
}

function updateOrderDisplay() {
    const itemsList = document.getElementById('orderItemsList');
    if (!itemsList) return;
    
    if (orderItems.length === 0) {
        itemsList.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="bi bi-cart" style="font-size: 3rem; opacity: 0.3;"></i>
                <p class="mt-3 mb-0">No items selected yet</p>
                <small class="text-muted">Choose products or bundles from above to get started</small>
            </div>
        `;
    } else {
        itemsList.innerHTML = orderItems.map((item, index) => `
            <div class="order-item">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <h6 class="mb-0">${item.name}</h6>
                            <span class="badge badge-${item.subscription_type === 'recurring' ? 'recurring' : 'one-time'}">
                                ${item.subscription_type === 'recurring' ? 'Subscription' : 'One-time'}
                            </span>
                        </div>
                        <p class="text-muted small mb-3">${item.description}</p>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="changeQuantity(${index}, -1)">
                                <i class="bi bi-dash"></i>
                            </button>
                            <span class="mx-2 fw-semibold">Qty: ${item.quantity}</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="changeQuantity(${index}, 1)">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="h6 mb-1">${currentCurrencySymbol}${(item.unit_price * item.quantity).toFixed(2)}</div>
                        <small class="text-muted">
                            ${currentCurrencySymbol}${item.unit_price.toFixed(2)} each
                            ${item.subscription_type === 'recurring' ? ` per ${item.billing_cycle}` : ''}
                        </small>
                        ${item.setup_fee > 0 ? `<br><small class="text-muted">Setup: ${currentCurrencySymbol}${(item.setup_fee * item.quantity).toFixed(2)}</small>` : ''}
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-outline-danger rounded-pill" onclick="removeItem(${index})">
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
    updateSubscriptionInfo();
}

function removeItem(index) {
    orderItems.splice(index, 1);
    updateOrderDisplay();
    updateSubscriptionInfo();
}

function updateSummary() {
    const itemCount = orderItems.reduce((sum, item) => sum + item.quantity, 0);
    const subtotal = orderItems.reduce((sum, item) => sum + (item.unit_price * item.quantity), 0);
    const setupFees = orderItems.reduce((sum, item) => sum + (item.setup_fee * item.quantity), 0);
    const vat = vatEnabled ? (subtotal * currentVatRate) : 0;
    const total = subtotal + setupFees + vat;
    
    const itemCountEl = document.getElementById('itemCount');
    const subtotalEl = document.getElementById('subtotal');
    const setupFeesEl = document.getElementById('setupFees');
    const vatEl = document.getElementById('vat');
    const totalEl = document.getElementById('total');
    const orderItemsInput = document.getElementById('orderItemsInput');
    
    if (itemCountEl) itemCountEl.textContent = itemCount;
    if (subtotalEl) subtotalEl.innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${subtotal.toFixed(2)}`;
    if (setupFeesEl) setupFeesEl.innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${setupFees.toFixed(2)}`;
    if (vatEl) vatEl.innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${vat.toFixed(2)}`;
    if (totalEl) totalEl.innerHTML = `<span class="currency-symbol">${currentCurrencySymbol}</span>${total.toFixed(2)}`;
    if (orderItemsInput) orderItemsInput.value = JSON.stringify(orderItems);
}

// Form validation with null check
const orderForm = document.getElementById('orderForm');
if (orderForm) {
    orderForm.addEventListener('submit', function(e) {
        if (orderItems.length === 0) {
            e.preventDefault();
            alert('Please add at least one item to your order.');
            return false;
        }
        
        const orderItemsInput = document.getElementById('orderItemsInput');
        if (orderItemsInput) {
            orderItemsInput.value = JSON.stringify(orderItems);
        }
    });
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>