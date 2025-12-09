<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

// Check if user is logged in - FIXED to use correct session structure
if (!isset($_SESSION['user']['id'])) {
    header('Location: /login.php');
    exit;
}

// Get user data from correct session structure
$user_id = $_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'] ?? 'customer';
$username = $_SESSION['user']['username'] ?? 'Unknown';
$user_email = $_SESSION['user']['email'] ?? '';

// Updated role checking - include all staff roles that should have access
$staff_roles = ['administrator', 'admin', 'staff', 'accountant', 'support consultant', 'account manager'];
$is_staff = in_array(strtolower($user_role), array_map('strtolower', $staff_roles));

// Get order ID from URL
$order_id = intval($_GET['id'] ?? 0);

if (!$order_id) {
    header('Location: /members/orders.php?error=' . urlencode('Invalid order ID'));
    exit;
}

// Check if subscription tables exist
$subscription_tables_exist = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'client_subscriptions'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->query("SHOW COLUMNS FROM client_subscriptions LIKE 'order_id'");
        $subscription_tables_exist = $stmt->rowCount() > 0;
    }
} catch (PDOException $e) {
    error_log("Subscription table check error: " . $e->getMessage());
    $subscription_tables_exist = false;
}

// Simplified order query to avoid database errors
try {
    $base_select = "SELECT o.*, c.name as company_name, c.phone as company_phone, c.address as company_address,
        c.preferred_currency, c.currency_override";

    $base_from = " FROM orders o
        JOIN companies c ON o.company_id = c.id";

    if ($is_staff) {
        // Staff can see all orders
        $query = $base_select . $base_from . " WHERE o.id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$order_id]);
    } else {
        // Get user's company info first
        $stmt = $pdo->prepare("SELECT company_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_info = $stmt->fetch();
        $user_company_id = $user_info['company_id'] ?? null;
        
        // Customers can only see orders from their company
        $where_conditions = ["o.id = ?"];
        $params = [$order_id];
        
        if ($user_company_id) {
            $where_conditions[] = "o.company_id = ?";
            $params[] = $user_company_id;
        } else {
            // If no company_id, deny access
            header('Location: /members/orders.php?error=' . urlencode('Access denied'));
            exit;
        }
        
        $query = $base_select . $base_from . " WHERE " . implode(" AND ", $where_conditions);
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
    }
} catch (PDOException $e) {
    error_log("Order query error: " . $e->getMessage());
    header('Location: /members/orders.php?error=' . urlencode('Database error: ' . $e->getMessage()));
    exit;
}

$order = $stmt->fetch();

if (!$order) {
    header('Location: /members/orders.php?error=' . urlencode('Order not found or access denied'));
    exit;
}

// Get person who placed the order (prioritize created_by, then staff_id)
$order_placed_by = 'System';
$order_placed_by_email = '';
$order_placed_by_role = 'system';

try {
    // Try created_by first (person who actually placed the order)
    if (!empty($order['created_by'])) {
        $stmt = $pdo->prepare("SELECT username, email, role FROM users WHERE id = ?");
        $stmt->execute([$order['created_by']]);
        $created_user = $stmt->fetch();
        if ($created_user) {
            $order_placed_by = $created_user['username'];
            $order_placed_by_email = $created_user['email'];
            $order_placed_by_role = $created_user['role'];
        }
    }
    
    // If no created_by, fall back to staff_id
    if ($order_placed_by === 'System' && !empty($order['staff_id'])) {
        $stmt = $pdo->prepare("SELECT username, email, role FROM users WHERE id = ?");
        $stmt->execute([$order['staff_id']]);
        $staff_user = $stmt->fetch();
        if ($staff_user) {
            $order_placed_by = $staff_user['username'];
            $order_placed_by_email = $staff_user['email'];
            $order_placed_by_role = $staff_user['role'];
        }
    }
} catch (PDOException $e) {
    error_log("Order placed by query error: " . $e->getMessage());
    // Continue with default values
}

// Get order items
try {
    $stmt = $pdo->prepare("SELECT oi.*, p.name as product_name, b.name as bundle_name
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        LEFT JOIN service_bundles b ON oi.bundle_id = b.id
        WHERE oi.order_id = ?
        ORDER BY oi.created_at ASC");
    $stmt->execute([$order_id]);
    $order_items = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Order items query error: " . $e->getMessage());
    $order_items = [];
}

// Calculate next payment due date with CORRECTED logic
$next_due_date = null;
$has_recurring_items = false;

if (!empty($order_items)) {
    // Check if we have any recurring items
    foreach ($order_items as $item) {
        if (in_array($item['billing_cycle'], ['monthly', 'quarterly', 'annually'])) {
            $has_recurring_items = true;
            break;
        }
    }
    
    if ($has_recurring_items) {
        // If order is not paid yet, next payment due = invoice due date (placed_at or created_at)
        if ($order['payment_status'] !== 'paid') {
            if (!empty($order['placed_at'])) {
                $next_due_date = strtotime($order['placed_at']);
            } elseif (!empty($order['created_at'])) {
                $next_due_date = strtotime($order['created_at']);
            }
        } else {
            // If order is paid, calculate the next recurring payment
            // First try to get from subscription table
            if ($subscription_tables_exist) {
                try {
                    $stmt = $pdo->prepare("SELECT MIN(next_billing_date) as next_date FROM client_subscriptions cs 
                        JOIN order_items oi ON cs.order_item_id = oi.id 
                        WHERE oi.order_id = ? AND cs.next_billing_date IS NOT NULL");
                    $stmt->execute([$order_id]);
                    $result = $stmt->fetch();
                    
                    if ($result && !empty($result['next_date'])) {
                        $next_due_date = strtotime($result['next_date']);
                    }
                } catch (PDOException $e) {
                    error_log("Subscription next billing query error: " . $e->getMessage());
                }
            }
            
            // If no subscription data, calculate based on payment date and billing cycle
            if (!$next_due_date && !empty($order['payment_date'])) {
                $payment_date = strtotime($order['payment_date']);
                
                // Find the most frequent billing cycle to use for calculation
                $billing_cycles = [];
                foreach ($order_items as $item) {
                    if (in_array($item['billing_cycle'], ['monthly', 'quarterly', 'annually'])) {
                        $billing_cycles[] = $item['billing_cycle'];
                    }
                }
                
                if (!empty($billing_cycles)) {
                    $primary_cycle = array_count_values($billing_cycles);
                    $primary_cycle = array_keys($primary_cycle, max($primary_cycle))[0];
                    
                    switch ($primary_cycle) {
                        case 'monthly':
                            $next_due_date = strtotime('+1 month', $payment_date);
                            break;
                        case 'quarterly':
                            $next_due_date = strtotime('+3 months', $payment_date);
                            break;
                        case 'annually':
                            $next_due_date = strtotime('+1 year', $payment_date);
                            break;
                    }
                }
            }
        }
    }
}

// Use order totals from database
$subtotal = $order['subtotal'] ?? 0;
$tax_amount = $order['tax_amount'] ?? 0;
$discount_amount = $order['discount_amount'] ?? 0;
$total = $order['total_amount'] ?? ($subtotal + $tax_amount - $discount_amount);

// Determine currency
$currency = $order['customer_currency'] ?? $order['currency'] ?? $order['preferred_currency'] ?? $order['currency_override'] ?? 'GBP';

// Format currency function
function formatCurrency($amount, $currency = 'GBP') {
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
    $symbol = $symbols[$currency] ?? $currency . ' ';
    return $symbol . number_format($amount, 2);
}

// Set page title
$page_title = "Order #" . $order['order_number'] . " | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>
    <style>

        /* Enhanced Cards */
        .enhanced-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            border: none;
            overflow: hidden;
            position: relative;
            margin-bottom: 2rem;
        }

        .enhanced-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--card-shadow-hover);
        }

        .enhanced-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .card-header-enhanced {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 1px solid rgba(102, 126, 234, 0.1);
            padding: 1.5rem;
            border-radius: var(--border-radius) var(--border-radius) 0 0 !important;
        }

        .card-title-enhanced {
            font-size: 1.25rem;
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-body-enhanced {
            padding: 1.5rem;
        }

        /* FIXED: Continuous gradient across the entire header row */
        .table-enhanced {
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            border: none !important;
            margin-bottom: 0 !important;
        }

        /* Apply gradient to the ENTIRE header row as one unit */
        .table-enhanced thead {
            background: var(--primary-gradient) !important;
            background-image: var(--primary-gradient) !important;
        }

        .table-enhanced thead tr {
            background: transparent !important;
            background-image: none !important;
        }

        .table-enhanced thead th {
            background: transparent !important;
            background-image: none !important;
            color: white !important;
            font-weight: 600 !important;
            border: none !important;
            padding: 1rem !important;
            position: relative !important;
            text-shadow: 0 1px 3px rgba(0,0,0,0.3) !important;
        }

        .table-enhanced tbody td {
            padding: 1rem !important;
            border-color: rgba(0,0,0,0.05) !important;
            vertical-align: middle !important;
            background: white !important;
            border-top: 1px solid rgba(0,0,0,0.05) !important;
        }

        .table-enhanced tbody tr:hover td {
            background: rgba(102, 126, 234, 0.05) !important;
        }

        .table-enhanced tfoot th {
            background: rgba(102, 126, 234, 0.1) !important;
            border: none !important;
            padding: 1rem !important;
            font-weight: 600 !important;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.875rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-badge.success { background: var(--success-gradient); color: white; }
        .status-badge.warning { background: var(--warning-gradient); color: white; }
        .status-badge.info { background: var(--info-gradient); color: white; }
        .status-badge.danger { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%); color: white; }
        .status-badge.secondary { background: var(--dark-gradient); color: white; }
        .status-badge.primary { background: var(--primary-gradient); color: white; }

        /* Enhanced Buttons */
        .btn-enhanced {
            border-radius: 50px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            position: relative;
            overflow: hidden;
        }

        .btn-enhanced::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            transition: all 0.3s ease;
            transform: translate(-50%, -50%);
        }

        .btn-enhanced:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-enhanced span {
            position: relative;
            z-index: 1;
        }

        .btn-primary-enhanced {
            background: var(--primary-gradient);
            color: white;
        }

        .btn-outline-enhanced {
            border: 2px solid #667eea;
            color: #667eea;
            background: transparent;
        }

        .btn-outline-enhanced:hover {
            background: var(--primary-gradient);
            color: white;
            border-color: transparent;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .dropdown-menu-enhanced {
            border: none;
            box-shadow: var(--card-shadow);
            border-radius: var(--border-radius);
            padding: 0.5rem 0;
            margin-top: 0.5rem;
        }

        .dropdown-item-enhanced {
            padding: 0.75rem 1.5rem;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .dropdown-item-enhanced:hover {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
        }

        /* Info Cards */
        .info-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
        }

        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--card-shadow-hover);
        }

        .info-label {
            font-size: 0.875rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-value {
            font-size: 1.125rem;
            font-weight: 600;
            color: #2c3e50;
        }

        /* DARK MODE STYLES */
        :root.dark,
        :root.dark body {
            background: #0f172a !important;
            color: #e2e8f0 !important;
        }

        /* Hero dark mode */
        :root.dark .hero {
            background: transparent !important;
        }

        :root.dark .hero-gradient {
            opacity: 1 !important;
            display: block !important;
            visibility: visible !important;
            z-index: 0 !important;
        }

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

        :root.dark .vieworder-hero-content h1,
        :root.dark .vieworder-hero-content p {
            color: white !important;
            position: relative;
            z-index: 2;
        }

        /* Order container */
        :root.dark .order-container {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .order-header {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .order-header h1,
        :root.dark .order-header h2,
        :root.dark .order-header h3,
        :root.dark .order-header p,
        :root.dark .order-header strong {
            color: #e2e8f0 !important;
        }

        :root.dark .order-body {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .order-body h4,
        :root.dark .order-body h5,
        :root.dark .order-body h6,
        :root.dark .order-body p,
        :root.dark .order-body strong {
            color: #e2e8f0 !important;
        }

        /* Info sections */
        :root.dark .info-section {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .info-label {
            color: #94a3b8 !important;
        }

        :root.dark .info-value {
            color: #e2e8f0 !important;
        }

        /* Tables */
        :root.dark .table,
        :root.dark .items-table {
            color: #e2e8f0 !important;
        }

        :root.dark .table thead,
        :root.dark .items-table thead {
            background: #0f172a !important;
        }

        :root.dark .table thead th,
        :root.dark .items-table thead th {
            background: #0f172a !important;
            color: #cbd5e1 !important;
            border-color: #334155 !important;
        }

        :root.dark .table tbody,
        :root.dark .items-table tbody {
            background: #1e293b !important;
        }

        :root.dark .table tbody tr,
        :root.dark .items-table tbody tr {
            background: #1e293b !important;
        }

        :root.dark .table tbody td,
        :root.dark .items-table tbody td {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .table tbody tr:hover,
        :root.dark .items-table tbody tr:hover {
            background: rgba(139, 92, 246, 0.1) !important;
        }

        /* Cards and sections */
        :root.dark .card {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .card-header {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .card-body {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark small {
            color: #94a3b8 !important;
        }

        :root.dark h1, :root.dark h2, :root.dark h3, :root.dark h4, :root.dark h5, :root.dark h6 {
            color: #f1f5f9 !important;
        }

        /* All order boxes and sections */
        :root.dark .order-ref,
        :root.dark .order-details,
        :root.dark .order-summary,
        :root.dark .order-info,
        :root.dark .customer-info,
        :root.dark .billing-info,
        :root.dark .shipping-info,
        :root.dark .payment-info,
        :root.dark .status-box,
        :root.dark .actions-box,
        :root.dark .timeline-box,
        :root.dark .info-box,
        :root.dark .summary-box,
        :root.dark .details-box,
        :root.dark .section-box {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        /* Section headers */
        :root.dark .section-header,
        :root.dark .box-header {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        /* Total rows and summary */
        :root.dark .total-row,
        :root.dark .summary-row {
            background: #0f172a !important;
            color: #e2e8f0 !important;
            border-color: #334155 !important;
        }

        :root.dark .total-row span,
        :root.dark .summary-row span {
            color: #e2e8f0 !important;
        }

        :root.dark .total-row:last-child {
            background: rgba(139, 92, 246, 0.2) !important;
            color: #a78bfa !important;
        }

        :root.dark .total-row:last-child span {
            color: #a78bfa !important;
        }

        /* Status badges and buttons */
        :root.dark .status-badge,
        :root.dark .badge {
            background: #334155 !important;
            color: #cbd5e1 !important;
        }

        :root.dark .btn-outline-primary {
            border-color: #8b5cf6 !important;
            color: #a78bfa !important;
        }

        :root.dark .btn-outline-primary:hover {
            background: var(--primary-gradient) !important;
            color: white !important;
        }

        :root.dark .btn-outline-secondary {
            border-color: #475569 !important;
            color: #94a3b8 !important;
        }

        :root.dark .btn-outline-secondary:hover {
            background: #475569 !important;
            color: white !important;
        }

        /* Lists */
        :root.dark .list-group {
            background: #1e293b !important;
        }

        :root.dark .list-group-item {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .list-group-item:hover {
            background: rgba(139, 92, 246, 0.1) !important;
        }

        /* Enhanced cards - Order Status & Information, Payment & Billing, Order Items, Company Information */
        :root.dark .enhanced-card {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .card-header-enhanced {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .card-title-enhanced {
            color: #f1f5f9 !important;
        }

        :root.dark .card-body-enhanced {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .card-body-enhanced h6,
        :root.dark .card-body-enhanced strong {
            color: #f1f5f9 !important;
        }

        :root.dark .card-body-enhanced p {
            color: #cbd5e1 !important;
        }

        :root.dark .card-body-enhanced a {
            color: #a78bfa !important;
        }

        :root.dark .card-body-enhanced a:hover {
            color: #c4b5fd !important;
        }

        :root.dark .card-body-enhanced code {
            background: #0f172a !important;
            color: #a78bfa !important;
            border: 1px solid #334155 !important;
        }

        /* Info cards - the stat boxes */
        :root.dark .info-card {
            background: #1e293b !important;
            border: 1px solid #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .info-card:hover {
            background: rgba(139, 92, 246, 0.1) !important;
            border-color: #8b5cf6 !important;
        }

        /* Table enhanced - Order Items table */
        :root.dark .table-enhanced {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .table-enhanced thead {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%) !important;
        }

        :root.dark .table-enhanced thead th {
            color: white !important;
            border-color: transparent !important;
        }

        :root.dark .table-enhanced tbody {
            background: #1e293b !important;
        }

        :root.dark .table-enhanced tbody tr {
            background: #1e293b !important;
        }

        :root.dark .table-enhanced tbody td {
            background: #1e293b !important;
            color: #e2e8f0 !important;
            border-color: #334155 !important;
        }

        :root.dark .table-enhanced tbody tr:hover td {
            background: rgba(139, 92, 246, 0.15) !important;
        }

        :root.dark .table-enhanced tfoot {
            background: #0f172a !important;
        }

        :root.dark .table-enhanced tfoot th {
            background: #0f172a !important;
            color: #e2e8f0 !important;
            border-color: #334155 !important;
        }

        :root.dark .table-enhanced tfoot tr.table-primary th {
            background: rgba(139, 92, 246, 0.2) !important;
            color: #a78bfa !important;
        }

        /* CUTE PRINT STYLES */
        @media print {
            /* Hide non-printable elements */
            .no-print,
            header.hero,
            .breadcrumb,
            .btn,
            button,
            nav,
            .alert,
            footer,
            .vieworder-hero-actions {
                display: none !important;
            }

            /* Page setup for single page */
            @page {
                size: A4;
                margin: 15mm;
            }

            body {
                background: white !important;
                color: #000 !important;
                font-size: 11pt;
                line-height: 1.4;
                margin: 0;
                padding: 0;
            }

            .container {
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            /* Order container - compact and cute */
            .enhanced-card {
                box-shadow: none !important;
                border: 2px solid #667eea !important;
                border-radius: 8px !important;
                padding: 15px !important;
                background: white !important;
                margin-bottom: 15px !important;
                page-break-inside: avoid;
            }

            /* Cute header with gradient */
            .card-header-enhanced {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
                color: white !important;
                padding: 12px 15px !important;
                margin: -15px -15px 15px -15px !important;
                border-radius: 6px 6px 0 0 !important;
            }

            .card-header-enhanced h5,
            .card-header-enhanced .card-title-enhanced {
                color: white !important;
                font-size: 13pt !important;
                margin: 0 !important;
            }

            .card-header-enhanced i {
                color: white !important;
            }

            /* Card body */
            .card-body-enhanced {
                background: white !important;
                padding: 10px !important;
                font-size: 9.5pt !important;
            }

            .card-body-enhanced h6 {
                color: #667eea !important;
                font-size: 9.5pt !important;
                font-weight: 700 !important;
                text-transform: uppercase !important;
                border-bottom: 1px solid #667eea !important;
                padding-bottom: 3px !important;
                margin-bottom: 8px !important;
            }

            .card-body-enhanced p {
                font-size: 9pt !important;
                line-height: 1.5 !important;
                margin-bottom: 5px !important;
            }

            .card-body-enhanced strong {
                color: #374151 !important;
                font-weight: 600 !important;
            }

            /* Info cards - status boxes */
            .info-card {
                background: #f9fafb !important;
                border: 1px solid #e5e7eb !important;
                padding: 10px !important;
                border-radius: 6px !important;
                text-align: center !important;
            }

            .info-card h6 {
                font-size: 8.5pt !important;
                color: #6b7280 !important;
                margin-bottom: 5px !important;
            }

            .info-card .h3 {
                font-size: 14pt !important;
                color: #667eea !important;
                font-weight: 700 !important;
            }

            /* Order items table */
            .table-enhanced {
                width: 100% !important;
                border-collapse: collapse !important;
                font-size: 9pt !important;
                margin: 10px 0 !important;
            }

            .table-enhanced thead {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            }

            .table-enhanced thead th {
                padding: 8px 10px !important;
                color: white !important;
                font-weight: 700 !important;
                text-transform: uppercase !important;
                font-size: 8.5pt !important;
                border: none !important;
            }

            .table-enhanced tbody td {
                padding: 8px 10px !important;
                border-bottom: 1px solid #e5e7eb !important;
                vertical-align: top !important;
            }

            .table-enhanced tbody tr:last-child td {
                border-bottom: none !important;
            }

            /* Table footer - totals */
            .table-enhanced tfoot {
                background: #f9fafb !important;
            }

            .table-enhanced tfoot th {
                padding: 8px 10px !important;
                font-size: 9.5pt !important;
                border-top: 1px solid #e5e7eb !important;
            }

            .table-enhanced tfoot tr.table-primary th {
                background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%) !important;
                border-top: 2px solid #667eea !important;
                font-weight: 700 !important;
                font-size: 11pt !important;
                color: #667eea !important;
                padding: 10px !important;
            }

            /* Status badges */
            .badge {
                padding: 3px 10px !important;
                border-radius: 12px !important;
                font-size: 8pt !important;
                font-weight: 600 !important;
                border: 1px solid currentColor !important;
            }

            .badge.bg-success {
                background: #10b981 !important;
                color: white !important;
            }

            .badge.bg-warning {
                background: #f59e0b !important;
                color: white !important;
            }

            .badge.bg-info {
                background: #3b82f6 !important;
                color: white !important;
            }

            /* Two column layout for company and payment info */
            .row {
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 15px !important;
            }

            .col-md-6 {
                flex: 0 0 48% !important;
                max-width: 48% !important;
            }

            .col-md-4 {
                flex: 0 0 30% !important;
                max-width: 30% !important;
            }

            .col-md-12 {
                flex: 0 0 100% !important;
                max-width: 100% !important;
            }

            /* Remove shadows and transitions */
            * {
                box-shadow: none !important;
                transition: none !important;
                animation: none !important;
            }

            /* Prevent page breaks in cards */
            .enhanced-card,
            .info-card {
                page-break-inside: avoid !important;
            }

            /* Payment reference code */
            code {
                background: #f3f4f6 !important;
                padding: 2px 6px !important;
                border-radius: 3px !important;
                font-size: 9pt !important;
                border: 1px solid #e5e7eb !important;
            }

            /* List groups */
            .list-group-item {
                border: 1px solid #e5e7eb !important;
                padding: 8px 12px !important;
                font-size: 9pt !important;
            }

            /* Compact spacing for print */
            h5 {
                margin-top: 10px !important;
                margin-bottom: 8px !important;
            }

            p {
                margin-bottom: 5px !important;
            }
        }

    </style>

<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="vieworder-hero-content">
            <h1 class="vieworder-hero-title">
                <i class="bi bi-receipt me-3"></i>
                Order #<?= htmlspecialchars($order['order_number']) ?>
            </h1>
            <p class="vieworder-hero-subtitle">
                <?= htmlspecialchars($order['company_name']) ?> • Viewed by <?= htmlspecialchars($username) ?>
            </p>
            <div class="vieworder-hero-actions">
                <a href="#content" class="btn c-btn-primary">
                    <i class="bi bi-arrow-down"></i>
                    View Details
                </a>
                <a href="/members/orders.php" class="btn c-btn-ghost">
                    <i class="bi bi-arrow-left"></i>
                    Back to Orders
                </a>
                <button onclick="window.print()" class="btn c-btn-ghost">
                    <i class="bi bi-printer"></i>
                    Print Order
                </button>
                <?php if ($is_staff): ?>
                <a href="/staff/edit-order.php?id=<?= $order['id'] ?>" class="btn c-btn-ghost">
                    <i class="bi bi-pencil"></i>
                    Edit Order
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>

<div class="container py-5 content-overlap" id="content">
    
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="breadcrumb-enhanced fade-in">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="/members/orders.php">Orders</a></li>
            <li class="breadcrumb-item active" aria-current="page">Order #<?= htmlspecialchars($order['order_number']) ?></li>
        </ol>
    </nav>

    <!-- Order Status Section -->
    <div class="enhanced-card fade-in">
        <div class="card-header-enhanced">
            <h5 class="card-title-enhanced">
                <i class="bi bi-info-circle"></i>
                Order Status & Information
            </h5>
        </div>
        <div class="card-body-enhanced">
            <div class="row g-4">
                <div class="col-md-3">
                    <div class="info-card text-center">
                        <div class="info-label">
                            <i class="bi bi-flag"></i>
                            Order Status
                        </div>
                        <div class="info-value">
                            <span class="status-badge <?= 
                                $order['status'] === 'completed' ? 'success' :
                                ($order['status'] === 'cancelled' ? 'danger' :
                                ($order['status'] === 'pending_payment' ? 'warning' : 
                                ($order['status'] === 'paid' ? 'info' :
                                ($order['status'] === 'draft' ? 'secondary' : 'primary'))))
                            ?>">
                                <i class="bi bi-<?= 
                                    $order['status'] === 'completed' ? 'check-circle' :
                                    ($order['status'] === 'cancelled' ? 'x-circle' :
                                    ($order['status'] === 'pending_payment' ? 'clock' :
                                    ($order['status'] === 'paid' ? 'credit-card' : 'info-circle')))
                                ?>"></i>
                                <?= ucwords(str_replace('_', ' ', $order['status'])) ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="info-card text-center">
                        <div class="info-label">
                            <i class="bi bi-credit-card"></i>
                            Payment Status
                        </div>
                        <div class="info-value">
                            <span class="status-badge <?= 
                                $order['payment_status'] === 'paid' ? 'success' :
                                ($order['payment_status'] === 'failed' ? 'danger' :
                                ($order['payment_status'] === 'pending' ? 'warning' : 'secondary'))
                            ?>">
                                <i class="bi bi-<?= 
                                    $order['payment_status'] === 'paid' ? 'check-circle' :
                                    ($order['payment_status'] === 'failed' ? 'x-circle' :
                                    ($order['payment_status'] === 'pending' ? 'clock' : 'credit-card'))
                                ?>"></i>
                                <?= ucfirst($order['payment_status']) ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="info-card text-center">
                        <div class="info-label">
                            <i class="bi bi-tag"></i>
                            Order Type
                        </div>
                        <div class="info-value">
                            <span class="status-badge <?= 
                                $order['order_type'] === 'new' ? 'primary' :
                                ($order['order_type'] === 'renewal' ? 'success' :
                                ($order['order_type'] === 'upgrade' ? 'info' : 'warning'))
                            ?>">
                                <i class="bi bi-<?= 
                                    $order['order_type'] === 'new' ? 'plus-circle' :
                                    ($order['order_type'] === 'renewal' ? 'arrow-clockwise' :
                                    ($order['order_type'] === 'upgrade' ? 'arrow-up-circle' : 'box'))
                                ?>"></i>
                                <?= ucfirst($order['order_type']) ?> Order
                            </span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="info-card text-center">
                        <div class="info-label">
                            <i class="bi bi-currency-pound"></i>
                            Total Amount
                        </div>
                        <div class="info-value">
                            <?= formatCurrency($total, $currency) ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Additional Info Row -->
            <div class="row g-4 mt-2">
                <div class="col-md-3">
                    <div class="info-label">
                        <i class="bi bi-calendar-plus"></i>
                        Created Date
                    </div>
                    <div class="info-value">
                        <?= date('M d, Y g:i A', strtotime($order['created_at'])) ?>
                    </div>
                </div>
                <?php if ($order['placed_at']): ?>
                <div class="col-md-3">
                    <div class="info-label">
                        <i class="bi bi-cart-check"></i>
                        Placed Date
                    </div>
                    <div class="info-value">
                        <?= date('M d, Y g:i A', strtotime($order['placed_at'])) ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($order['payment_date']): ?>
                <div class="col-md-3">
                    <div class="info-label">
                        <i class="bi bi-credit-card-2-front"></i>
                        Payment Date
                    </div>
                    <div class="info-value">
                        <?= date('M d, Y g:i A', strtotime($order['payment_date'])) ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <div class="info-label">
                        <i class="bi bi-person-circle"></i>
                        Order Placed By
                    </div>
                    <div class="info-value">
                        <?= htmlspecialchars($order_placed_by) ?>
                        <?php if ($order_placed_by_role !== 'system'): ?>
                        <br><small class="text-muted"><?= ucfirst($order_placed_by_role) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($next_due_date): ?>
                <div class="col-md-3">
                    <div class="info-label">
                        <i class="bi bi-calendar-event"></i>
                        <?= $order['payment_status'] === 'paid' ? 'Next Payment Due' : 'Payment Due' ?>
                    </div>
                    <div class="info-value">
                        <?= date('M d, Y', $next_due_date) ?>
                        <br><small class="text-muted"><?= date('l, F j, Y', $next_due_date) ?></small>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Company Information -->
        <div class="col-lg-6">
            <div class="enhanced-card fade-in">
                <div class="card-header-enhanced">
                    <h5 class="card-title-enhanced">
                        <i class="bi bi-building"></i>
                        Company Information
                    </h5>
                </div>
                <div class="card-body-enhanced">
                    <h6 class="mb-3"><?= htmlspecialchars($order['company_name']) ?></h6>
                    <?php if ($order['company_phone']): ?>
                    <p class="mb-2">
                        <i class="bi bi-telephone text-muted me-2"></i> 
                        <?= htmlspecialchars($order['company_phone']) ?>
                    </p>
                    <?php endif; ?>
                    <?php if ($order['company_address']): ?>
                    <p class="mb-2">
                        <i class="bi bi-geo-alt text-muted me-2"></i> 
                        <?= nl2br(htmlspecialchars($order['company_address'])) ?>
                    </p>
                    <?php endif; ?>
                    <p class="mb-0">
                        <i class="bi bi-currency-exchange text-muted me-2"></i> 
                        Currency: <strong><?= htmlspecialchars($currency) ?></strong>
                        <?php if ($order['vat_enabled']): ?>
                        <span class="ms-2 text-muted">
                            (VAT: <?= number_format($order['vat_rate'] * 100, 2) ?>%)
                        </span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Payment Information -->
        <div class="col-lg-6">
            <div class="enhanced-card fade-in">
                <div class="card-header-enhanced">
                    <h5 class="card-title-enhanced">
                        <i class="bi bi-credit-card"></i>
                        Payment & Billing Information
                    </h5>
                </div>
                <div class="card-body-enhanced">
                    <?php if ($order_placed_by_email): ?>
                    <p class="mb-3">
                        <strong>Contact Email:</strong><br>
                        <i class="bi bi-envelope text-muted me-2"></i> 
                        <a href="mailto:<?= htmlspecialchars($order_placed_by_email) ?>" class="text-decoration-none">
                            <?= htmlspecialchars($order_placed_by_email) ?>
                        </a>
                    </p>
                    <?php endif; ?>
                    
                    <?php if ($order['payment_reference']): ?>
                    <p class="mb-2">
                        <strong>Payment Reference:</strong><br>
                        <code><?= htmlspecialchars($order['payment_reference']) ?></code>
                    </p>
                    <?php endif; ?>
                    
                    <?php if ($order['billing_cycle']): ?>
                    <p class="mb-0">
                        <strong>Billing Cycle:</strong> 
                        <span class="status-badge success"><?= ucfirst($order['billing_cycle']) ?></span>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Order Items with simplified table (no subscription columns) -->
    <div class="enhanced-card fade-in">
        <div class="card-header-enhanced">
            <h5 class="card-title-enhanced">
                <i class="bi bi-list-ul"></i>
                Order Items
            </h5>
        </div>
        <div class="card-body-enhanced">
            <?php if (empty($order_items)): ?>
            <div class="text-center py-4">
                <i class="bi bi-inbox display-1 text-muted"></i>
                <p class="text-muted mt-3">No order items found.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-enhanced mb-0">
                    <thead>
                        <tr>
                            <th>Product/Service</th>
                            <th>Type</th>
                            <th>Billing Cycle</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order_items as $item): ?>
                        <?php 
                        $item_total = $item['quantity'] * $item['unit_price'];
                        $product_name = $item['product_name'] ?: $item['bundle_name'] ?: 'Unknown Product';
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($product_name) ?></strong>
                                <?php if (isset($item['description']) && $item['description']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($item['description']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $item['product_name'] ? 'primary' : 'info' ?>">
                                    <i class="bi bi-<?= $item['product_name'] ? 'box' : 'collection' ?>"></i>
                                    <?= $item['product_name'] ? 'Product' : 'Bundle' ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?= 
                                    $item['billing_cycle'] === 'one_time' ? 'secondary' : 'success'
                                ?>">
                                    <i class="bi bi-<?= $item['billing_cycle'] === 'one_time' ? 'clock' : 'arrow-repeat' ?>"></i>
                                    <?= ucfirst(str_replace('_', ' ', $item['billing_cycle'])) ?>
                                </span>
                            </td>
                            <td><?= number_format($item['quantity']) ?></td>
                            <td><?= formatCurrency($item['unit_price'], $currency) ?></td>
                            <td><strong><?= formatCurrency($item_total, $currency) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="5">Subtotal</th>
                            <th><?= formatCurrency($subtotal, $currency) ?></th>
                        </tr>
                        <?php if ($discount_amount > 0): ?>
                        <tr>
                            <th colspan="5">Discount</th>
                            <th class="text-success">-<?= formatCurrency($discount_amount, $currency) ?></th>
                        </tr>
                        <?php endif; ?>
                        <?php if ($tax_amount > 0): ?>
                        <tr>
                            <th colspan="5">
                                VAT <?php if ($order['vat_enabled']): ?>(<?= number_format($order['vat_rate'] * 100, 2) ?>%)<?php endif; ?>
                            </th>
                            <th><?= formatCurrency($tax_amount, $currency) ?></th>
                        </tr>
                        <?php endif; ?>
                        <tr class="table-primary">
                            <th colspan="5">Total</th>
                            <th><?= formatCurrency($total, $currency) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Order Notes -->
    <?php if ($order['notes'] || ($is_staff && $order['internal_notes'])): ?>
    <div class="row">
        <?php if ($order['notes']): ?>
        <div class="col-lg-6">
            <div class="enhanced-card fade-in">
                <div class="card-header-enhanced">
                    <h5 class="card-title-enhanced">
                        <i class="bi bi-sticky"></i>
                        Order Notes
                    </h5>
                </div>
                <div class="card-body-enhanced">
                    <?= nl2br(htmlspecialchars($order['notes'])) ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($is_staff && $order['internal_notes']): ?>
        <div class="col-lg-6">
            <div class="enhanced-card fade-in">
                <div class="card-header-enhanced">
                    <h5 class="card-title-enhanced">
                        <i class="bi bi-shield-lock"></i>
                        Internal Notes
                    </h5>
                </div>
                <div class="card-body-enhanced">
                    <?= nl2br(htmlspecialchars($order['internal_notes'])) ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<script>
// Enhanced functionality
document.addEventListener('DOMContentLoaded', function() {
    // Force ONE continuous gradient on the entire table header
    const tableHeaders = document.querySelectorAll('.table-enhanced thead');
    tableHeaders.forEach(thead => {
        thead.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
        thead.style.backgroundImage = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
    });

    // Make sure individual cells are transparent
    const headerCells = document.querySelectorAll('.table-enhanced thead th');
    headerCells.forEach(th => {
        th.style.background = 'transparent';
        th.style.backgroundImage = 'none';
        th.style.color = 'white';
        th.style.border = 'none';
        th.style.textShadow = '0 1px 3px rgba(0,0,0,0.3)';
    });

    // Intersection Observer for fade-in animations
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
            }
        });
    }, observerOptions);

    // Observe all fade-in elements
    document.querySelectorAll('.fade-in').forEach(el => {
        observer.observe(el);
    });

    // Smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Enhanced button click effects
    document.querySelectorAll('.btn-enhanced').forEach(btn => {
        btn.addEventListener('click', function(e) {
            const ripple = document.createElement('span');
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;
            
            ripple.style.cssText = `
                position: absolute;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.6);
                width: ${size}px;
                height: ${size}px;
                left: ${x}px;
                top: ${y}px;
                transform: scale(0);
                animation: ripple 0.6s linear;
                pointer-events: none;
                z-index: 1;
            `;
            
            this.style.position = 'relative';
            this.appendChild(ripple);
            
            setTimeout(() => {
                ripple.remove();
            }, 600);
        });
    });
});

// Add ripple animation CSS
const rippleStyle = document.createElement('style');
rippleStyle.textContent = `
    @keyframes ripple {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }
`;
document.head.appendChild(rippleStyle);
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
