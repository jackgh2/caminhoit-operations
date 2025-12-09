<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;

if (!$user) {
    header('Location: /login.php');
    exit;
}

$role        = $user['role'] ?? 'public';
$username    = htmlspecialchars($user['username']);
$company_id  = $user['company_id'] ?? null;
$user_id     = $user['id'] ?? null;

// Get supported currencies and default currency
$defaultCurrency = 'GBP';
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

// Check for ConfigManager class
if (class_exists('ConfigManager')) {
    $supportedCurrencies = ConfigManager::getSupportedCurrencies();
    $defaultCurrency = ConfigManager::get('business.default_currency', 'GBP');
    $exchangeRates = ConfigManager::getExchangeRates();
}

$defaultCurrencySymbol = $supportedCurrencies[$defaultCurrency]['symbol'] ?? '£';

// Initialize dashboard data
$dashboard_data = [
    'invoices' => ['outstanding' => 0, 'paid_recent' => 0, 'overdue' => 0, 'total_value' => 0],
    'orders' => ['pending' => 0, 'processing' => 0, 'completed_recent' => 0, 'total_value' => 0],
    'tickets' => ['awaiting_response' => 0, 'open' => 0, 'assigned_to_me' => 0, 'unassigned' => 0, 'awaiting_customer_reply' => 0, 'awaiting_member_reply' => 0],
    'licenses' => [],
    'recent_activity' => []
];

try {
    // Get role-specific data
    if ($role === 'administrator') {
        // ADMIN VIEW - System-wide statistics
        
        // Invoice counts (system-wide)
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(CASE WHEN status IN ('pending', 'sent', 'draft') THEN 1 END) as outstanding,
                    COUNT(CASE WHEN status = 'paid' AND paid_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as paid_recent,
                    COUNT(CASE WHEN status IN ('pending', 'sent') AND due_date < NOW() THEN 1 END) as overdue,
                    SUM(CASE WHEN status IN ('pending', 'sent', 'draft') THEN total_amount ELSE 0 END) as outstanding_value
                FROM invoices
            ");
            $stmt->execute();
            $invoice_data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($invoice_data) {
                $dashboard_data['invoices'] = $invoice_data;
            }
        } catch (Exception $e) {
            error_log("Dashboard: Invoices table not found or error: " . $e->getMessage());
        }

        // Order counts (system-wide)
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(CASE WHEN status IN ('draft', 'placed', 'pending_payment') THEN 1 END) as pending,
                    COUNT(CASE WHEN status IN ('processing', 'paid') THEN 1 END) as processing,
                    COUNT(CASE WHEN status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as completed_recent,
                    SUM(CASE WHEN status NOT IN ('cancelled') THEN total_amount ELSE 0 END) as total_value
                FROM orders
            ");
            $stmt->execute();
            $order_data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($order_data) {
                $dashboard_data['orders'] = $order_data;
            }
        } catch (Exception $e) {
            error_log("Dashboard: Orders query failed: " . $e->getMessage());
        }

        // Ticket counts (admin - system-wide)
        $ticket_queries = [
            'unassigned' => "SELECT COUNT(*) FROM support_tickets WHERE assigned_to IS NULL AND LOWER(status) NOT IN ('closed')",
            'pending_reply' => "
                SELECT COUNT(DISTINCT t.id) 
                FROM support_tickets t
                LEFT JOIN (
                    SELECT str.ticket_id, str.user_id, str.created_at,
                           ROW_NUMBER() OVER (PARTITION BY str.ticket_id ORDER BY str.created_at DESC) as rn
                    FROM support_ticket_replies str
                ) latest_reply ON t.id = latest_reply.ticket_id AND latest_reply.rn = 1
                LEFT JOIN users reply_user ON latest_reply.user_id = reply_user.id
                WHERE LOWER(t.status) NOT IN ('closed')
                AND t.assigned_to = ?
                AND (
                    (latest_reply.user_id IS NOT NULL AND reply_user.role NOT IN ('administrator', 'support_user'))
                    OR (latest_reply.user_id IS NULL AND t.user_id IS NOT NULL)
                )
            ",
            'open' => "SELECT COUNT(*) FROM support_tickets WHERE LOWER(status) NOT IN ('closed')",
            'assigned_to_me' => "SELECT COUNT(*) FROM support_tickets WHERE assigned_to = ? AND LOWER(status) NOT IN ('closed')",
            'awaiting_member_reply' => "SELECT COUNT(*) FROM support_tickets WHERE LOWER(status) = 'awaiting member reply'"
        ];

        foreach ($ticket_queries as $key => $query) {
            $stmt = $pdo->prepare($query);
            if (in_array($key, ['assigned_to_me', 'pending_reply'])) {
                $stmt->execute([$user_id]);
            } else {
                $stmt->execute();
            }
            $dashboard_data['tickets'][$key] = $stmt->fetchColumn() ?: 0;
        }

        // FIXED: Admin licenses query to match customer query structure
        try {
            $stmt = $pdo->prepare("
                SELECT pa.*, 
                       cs.quantity as total_quantity,
                       cs.unit_price,
                       cs.start_date,
                       cs.end_date,
                       cs.status as subscription_status,
                       p.name as product_name,
                       p.unit_type,
                       p.description as product_description,
                       b.name as bundle_name,
                       b.description as bundle_description,
                       c.id as company_id,
                       c.name as company_name
                FROM product_assignments pa
                JOIN client_subscriptions cs ON pa.subscription_id = cs.id
                JOIN companies c ON cs.company_id = c.id
                LEFT JOIN products p ON cs.product_id = p.id
                LEFT JOIN service_bundles b ON cs.bundle_id = b.id
                WHERE cs.status = 'active'
                AND pa.status = 'assigned'
                ORDER BY pa.assigned_at DESC
                LIMIT 10
            ");
            $stmt->execute();
            $dashboard_data['licenses'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("Dashboard: Product assignments table not found: " . $e->getMessage());
            $dashboard_data['licenses'] = [];
        }

    } elseif (in_array($role, ['account_manager', 'supported_user', 'public'])) {
        // CUSTOMER VIEW - User-specific data
        
        // Invoice counts
        try {
            if ($company_id) {
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(CASE WHEN status IN ('pending', 'sent', 'draft') THEN 1 END) as outstanding,
                        COUNT(CASE WHEN status = 'paid' AND paid_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as paid_recent,
                        COUNT(CASE WHEN status IN ('pending', 'sent') AND due_date < NOW() THEN 1 END) as overdue,
                        SUM(CASE WHEN status IN ('pending', 'sent', 'draft') THEN total_amount ELSE 0 END) as outstanding_value
                    FROM invoices 
                    WHERE company_id = ?
                ");
                $stmt->execute([$company_id]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(CASE WHEN status IN ('pending', 'sent', 'draft') THEN 1 END) as outstanding,
                        COUNT(CASE WHEN status = 'paid' AND paid_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as paid_recent,
                        COUNT(CASE WHEN status IN ('pending', 'sent') AND due_date < NOW() THEN 1 END) as overdue,
                        SUM(CASE WHEN status IN ('pending', 'sent', 'draft') THEN total_amount ELSE 0 END) as outstanding_value
                    FROM invoices 
                    WHERE user_id = ? OR created_by = ?
                ");
                $stmt->execute([$user_id, $user_id]);
            }
            $invoice_data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($invoice_data) {
                $dashboard_data['invoices'] = $invoice_data;
            }
        } catch (Exception $e) {
            error_log("Dashboard: Invoices query failed: " . $e->getMessage());
        }

        // Order counts
        try {
            if ($company_id) {
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(CASE WHEN status IN ('draft', 'placed', 'pending_payment') THEN 1 END) as pending,
                        COUNT(CASE WHEN status IN ('processing', 'paid') THEN 1 END) as processing,
                        COUNT(CASE WHEN status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as completed_recent,
                        SUM(CASE WHEN status NOT IN ('cancelled') THEN total_amount ELSE 0 END) as total_value
                    FROM orders 
                    WHERE (company_id = ? OR company_id IN (SELECT company_id FROM company_users WHERE user_id = ?))
                ");
                $stmt->execute([$company_id, $user_id]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(CASE WHEN status IN ('draft', 'placed', 'pending_payment') THEN 1 END) as pending,
                        COUNT(CASE WHEN status IN ('processing', 'paid') THEN 1 END) as processing,
                        COUNT(CASE WHEN status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as completed_recent,
                        SUM(CASE WHEN status NOT IN ('cancelled') THEN total_amount ELSE 0 END) as total_value
                    FROM orders 
                    WHERE staff_id = ? OR created_by = ?
                ");
                $stmt->execute([$user_id, $user_id]);
            }
            $order_data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($order_data) {
                $dashboard_data['orders'] = $order_data;
            }
        } catch (Exception $e) {
            error_log("Dashboard: Orders query failed: " . $e->getMessage());
        }

        // Customer ticket counts
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(CASE WHEN LOWER(status) NOT IN ('closed') THEN 1 END) as open,
                COUNT(CASE WHEN LOWER(status) IN ('open', 'in progress') 
                    AND assigned_to IS NOT NULL THEN 1 END) as awaiting_customer_reply,
                COUNT(CASE WHEN LOWER(status) = 'awaiting member reply' THEN 1 END) as awaiting_member_reply
            FROM support_tickets 
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $ticket_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($ticket_data) {
            $dashboard_data['tickets']['open'] = $ticket_data['open'] ?: 0;
            $dashboard_data['tickets']['awaiting_customer_reply'] = $ticket_data['awaiting_customer_reply'] ?: 0;
            $dashboard_data['tickets']['awaiting_member_reply'] = $ticket_data['awaiting_member_reply'] ?: 0;
        }

        // FIXED: Customer licenses query to match the services page query
        try {
            $stmt = $pdo->prepare("
                SELECT pa.*, 
                       cs.quantity as total_quantity,
                       cs.unit_price,
                       cs.start_date,
                       cs.end_date,
                       cs.status as subscription_status,
                       p.name as product_name,
                       p.unit_type,
                       p.description as product_description,
                       b.name as bundle_name,
                       b.description as bundle_description,
                       c.id as company_id,
                       c.name as company_name
                FROM product_assignments pa
                JOIN client_subscriptions cs ON pa.subscription_id = cs.id
                JOIN companies c ON cs.company_id = c.id
                LEFT JOIN products p ON cs.product_id = p.id
                LEFT JOIN service_bundles b ON cs.bundle_id = b.id
                WHERE pa.user_id = ?
                AND cs.status = 'active'
                AND pa.status = 'assigned'
                ORDER BY pa.assigned_at DESC
            ");
            $stmt->execute([$user_id]);
            $dashboard_data['licenses'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("Dashboard: Customer licenses query failed: " . $e->getMessage());
            $dashboard_data['licenses'] = [];
        }

    } elseif ($role === 'support_consultant') {
        // SUPPORT CONSULTANT VIEW
        
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(CASE WHEN LOWER(status) NOT IN ('closed') THEN 1 END) as assigned_to_me,
                COUNT(CASE WHEN LOWER(status) IN ('open', 'in progress') THEN 1 END) as awaiting_response
            FROM support_tickets 
            WHERE assigned_to = ?
        ");
        $stmt->execute([$user_id]);
        $ticket_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($ticket_data) {
            $dashboard_data['tickets']['assigned_to_me'] = $ticket_data['assigned_to_me'] ?: 0;
            $dashboard_data['tickets']['awaiting_response'] = $ticket_data['awaiting_response'] ?: 0;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE assigned_to IS NULL AND LOWER(status) NOT IN ('closed')");
        $stmt->execute();
        $dashboard_data['tickets']['unassigned'] = $stmt->fetchColumn() ?: 0;

    } elseif ($role === 'accountant') {
        // ACCOUNTANT VIEW
        
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(CASE WHEN status IN ('pending', 'sent', 'draft') THEN 1 END) as outstanding,
                    COUNT(CASE WHEN status = 'paid' AND paid_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as paid_recent,
                    COUNT(CASE WHEN status IN ('pending', 'sent') AND due_date < NOW() THEN 1 END) as overdue,
                    SUM(CASE WHEN status IN ('pending', 'sent', 'draft') THEN total_amount ELSE 0 END) as outstanding_value,
                    SUM(CASE WHEN status = 'paid' AND paid_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN total_amount ELSE 0 END) as paid_recent_value
                FROM invoices
            ");
            $stmt->execute();
            $invoice_data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($invoice_data) {
                $dashboard_data['invoices'] = $invoice_data;
            }
        } catch (Exception $e) {
            error_log("Dashboard: Accountant invoices query failed: " . $e->getMessage());
        }
    }

    // Get recent activity for all roles
    try {
        $recent_activity = [];
        
        // Recent tickets
        if ($role === 'administrator') {
            $stmt = $pdo->prepare("
                SELECT 'ticket' as type, id, subject as title, status, created_at as activity_date
                FROM support_tickets 
                ORDER BY created_at DESC 
                LIMIT 5
            ");
            $stmt->execute();
        } else {
            // For customers, show their own tickets
            $stmt = $pdo->prepare("
                SELECT 'ticket' as type, id, subject as title, status, created_at as activity_date
                FROM support_tickets 
                WHERE user_id = ?
                ORDER BY created_at DESC 
                LIMIT 5
            ");
            $stmt->execute([$user_id]);
        }
        $recent_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Recent orders
        try {
            if ($role === 'administrator') {
                $stmt = $pdo->prepare("
                    SELECT 'order' as type, id, order_number as title, status, created_at as activity_date
                    FROM orders 
                    ORDER BY created_at DESC 
                    LIMIT 5
                ");
                $stmt->execute();
            } else {
                if ($company_id) {
                    $stmt = $pdo->prepare("
                        SELECT 'order' as type, id, order_number as title, status, created_at as activity_date
                        FROM orders 
                        WHERE (company_id = ? OR company_id IN (SELECT company_id FROM company_users WHERE user_id = ?))
                        ORDER BY created_at DESC 
                        LIMIT 5
                    ");
                    $stmt->execute([$company_id, $user_id]);
                } else {
                    $stmt = $pdo->prepare("
                        SELECT 'order' as type, id, order_number as title, status, created_at as activity_date
                        FROM orders 
                        WHERE staff_id = ? OR created_by = ?
                        ORDER BY created_at DESC 
                        LIMIT 5
                    ");
                    $stmt->execute([$user_id, $user_id]);
                }
            }
            $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $recent_orders = [];
            error_log("Dashboard: Recent orders query failed: " . $e->getMessage());
        }
        
        // Merge and sort by date
        $recent_activity = array_merge($recent_tickets ?: [], $recent_orders ?: []);
        usort($recent_activity, function($a, $b) {
            return strtotime($b['activity_date']) - strtotime($a['activity_date']);
        });
        
        $dashboard_data['recent_activity'] = array_slice($recent_activity, 0, 10);
        
    } catch (Exception $e) {
        error_log("Dashboard: Recent activity query failed: " . $e->getMessage());
        $dashboard_data['recent_activity'] = [];
    }

} catch (Exception $e) {
    error_log("Dashboard data error: " . $e->getMessage());
}

$page_title = "Dashboard | CaminhoIT";
include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php';
?>

<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?? 'CaminhoIT'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .dashboard-stat-box {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 2rem 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
            height: 100%;
        }
        
        .dashboard-stat-box:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            text-decoration: none;
            color: inherit;
        }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-number.primary { color: #4F46E5; }
        .stat-number.success { color: #10B981; }
        .stat-number.warning { color: #F59E0B; }
        .stat-number.danger { color: #EF4444; }
        .stat-number.info { color: #06B6D4; }
        
        .stat-label {
            color: #6b7280;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .dashboard-box {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            color: white;
        }
        
        .activity-icon.ticket { background: #4F46E5; }
        .activity-icon.order { background: #10B981; }
        .activity-icon.invoice { background: #F59E0B; }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.25rem;
        }
        
        .activity-meta {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .activity-status {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .license-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .license-card:hover {
            background: #f8fafc;
        }
        
        .license-status {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .license-status.active {
            background: #d1fae5;
            color: #065f46;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .quick-action-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .quick-action-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
        }

        /* Updated grid to accommodate 5 cards for customers */
        .customer-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        /* Admin stats to show 5 cards in one row */
        .admin-stats {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media (min-width: 1200px) {
            .customer-stats {
                grid-template-columns: repeat(5, 1fr);
            }
            .admin-stats {
                grid-template-columns: repeat(5, 1fr);
            }
        }

        @media (max-width: 992px) {
            .admin-stats {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .customer-stats,
            .admin-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
<!-- Hero Section -->
<header class="hero dashboard-hero">
    <div class="container hero-content">
        <h1 class="hero-title">Welcome back, <?= $username; ?>!</h1>
        <p class="hero-subtitle">Here's what's happening with your account</p>
        
        <!-- Quick Actions in Hero -->
        <div class="quick-actions">
            <a href="/members/raise-ticket.php" class="quick-action-btn">
                <i class="bi bi-plus-circle"></i>
                <span>Raise Ticket</span>
            </a>
            <?php if (in_array($role, ['account_manager', 'administrator'])): ?>
                <a href="/members/create-order.php" class="quick-action-btn">
                    <i class="bi bi-cart-plus"></i>
                    <span>Place Order</span>
                </a>
            <?php endif; ?>
            <a href="/members/view-invoices.php" class="quick-action-btn">
                <i class="bi bi-receipt"></i>
                <span>View Invoices</span>
            </a>
            <a href="/members/account.php" class="quick-action-btn">
                <i class="bi bi-person-gear"></i>
                <span>My Account</span>
            </a>
        </div>
    </div>
</header>

<!-- Main Dashboard Content -->
<section class="py-5">
    <?php if ($role === 'administrator'): ?>
        <!-- ADMIN DASHBOARD - All 5 cards in one row -->
        <section class="dashboard-overlap-section">
            <div class="container">
                <div class="admin-stats">
                    <a href="/operations/invoices.php?status=outstanding" class="dashboard-stat-box">
                        <div class="stat-icon text-warning">💰</div>
                        <div class="stat-number warning"><?= number_format($dashboard_data['invoices']['outstanding']); ?></div>
                        <div class="stat-label">Outstanding Invoices</div>
                        <?php if ($dashboard_data['invoices']['outstanding_value'] > 0): ?>
                            <small class="text-muted"><?= $defaultCurrencySymbol ?><?= number_format($dashboard_data['invoices']['outstanding_value'], 0); ?></small>
                        <?php endif; ?>
                    </a>
                    
                    <a href="/operations/orders.php?status=pending" class="dashboard-stat-box">
                        <div class="stat-icon text-info">📦</div>
                        <div class="stat-number info"><?= number_format($dashboard_data['orders']['pending']); ?></div>
                        <div class="stat-label">Pending Orders</div>
                    </a>
                    
                    <a href="/operations/staff-tickets.php?status=pending_reply" class="dashboard-stat-box">
                        <div class="stat-icon text-danger">🎫</div>
                        <div class="stat-number danger"><?= number_format($dashboard_data['tickets']['pending_reply']); ?></div>
                        <div class="stat-label">Awaiting Response</div>
                    </a>
                    
                    <a href="/operations/staff-tickets.php?status=unassigned" class="dashboard-stat-box">
                        <div class="stat-icon text-primary">🚩</div>
                        <div class="stat-number primary"><?= number_format($dashboard_data['tickets']['unassigned']); ?></div>
                        <div class="stat-label">Unassigned Tickets</div>
                    </a>
                    
                    <a href="/operations/staff-tickets.php?status=all" class="dashboard-stat-box">
                        <div class="stat-icon text-success">🎫</div>
                        <div class="stat-number success"><?= number_format($dashboard_data['tickets']['open']); ?></div>
                        <div class="stat-label">Total Open Tickets</div>
                    </a>
                </div>
            </div>
        </section>

    <?php elseif (in_array($role, ['account_manager', 'supported_user', 'public'])): ?>
        <!-- CUSTOMER DASHBOARD -->
        <section class="dashboard-overlap-section">
            <div class="container">
                <div class="customer-stats">
                    <a href="/members/view-invoices.php" class="dashboard-stat-box">
                        <div class="stat-icon text-warning">💰</div>
                        <div class="stat-number warning"><?= number_format($dashboard_data['invoices']['outstanding']); ?></div>
                        <div class="stat-label">Outstanding Invoices</div>
                        <?php if ($dashboard_data['invoices']['outstanding_value'] > 0): ?>
                            <small class="text-muted"><?= $defaultCurrencySymbol ?><?= number_format($dashboard_data['invoices']['outstanding_value'], 0); ?></small>
                        <?php endif; ?>
                    </a>
                    
                    <a href="/members/orders.php" class="dashboard-stat-box">
                        <div class="stat-icon text-info">📦</div>
                        <div class="stat-number info"><?= number_format($dashboard_data['orders']['pending']); ?></div>
                        <div class="stat-label">Pending Orders</div>
                    </a>
                    
                    <a href="/members/my-ticket.php" class="dashboard-stat-box">
                        <div class="stat-icon text-primary">🎫</div>
                        <div class="stat-number primary"><?= number_format($dashboard_data['tickets']['open']); ?></div>
                        <div class="stat-label">Open Tickets</div>
                    </a>
                    
                    <a href="/members/my-ticket.php?status=awaiting_member_reply" class="dashboard-stat-box">
                        <div class="stat-icon text-info">⏱️</div>
                        <div class="stat-number info"><?= number_format($dashboard_data['tickets']['awaiting_member_reply']); ?></div>
                        <div class="stat-label">Awaiting Member Reply</div>
                    </a>
                    
                    <a href="/members/my-services.php" class="dashboard-stat-box">
                        <div class="stat-icon text-success">🔑</div>
                        <div class="stat-number success"><?= count($dashboard_data['licenses']); ?></div>
                        <div class="stat-label">Active Services</div>
                    </a>
                </div>
            </div>
        </section>

    <?php elseif ($role === 'support_consultant'): ?>
        <!-- SUPPORT CONSULTANT DASHBOARD -->
        <section class="dashboard-overlap-section">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-md-4 mb-4">
                        <a href="/operations/staff-tickets.php?status=assigned_to_me" class="dashboard-stat-box">
                            <div class="stat-icon text-primary">👤</div>
                            <div class="stat-number primary"><?= number_format($dashboard_data['tickets']['assigned_to_me']); ?></div>
                            <div class="stat-label">Assigned to Me</div>
                        </a>
                    </div>
                    <div class="col-md-4 mb-4">
                        <a href="/operations/staff-tickets.php?status=unassigned" class="dashboard-stat-box">
                            <div class="stat-icon text-warning">❓</div>
                            <div class="stat-number warning"><?= number_format($dashboard_data['tickets']['unassigned']); ?></div>
                            <div class="stat-label">Unassigned</div>
                        </a>
                    </div>
                    <div class="col-md-4 mb-4">
                        <a href="/operations/staff-tickets.php?status=pending_reply" class="dashboard-stat-box">
                            <div class="stat-icon text-danger">⏰</div>
                            <div class="stat-number danger"><?= number_format($dashboard_data['tickets']['awaiting_response']); ?></div>
                            <div class="stat-label">Awaiting Response</div>
                        </a>
                    </div>
                </div>
            </div>
        </section>

    <?php elseif ($role === 'accountant'): ?>
        <!-- ACCOUNTANT DASHBOARD -->
        <section class="dashboard-overlap-section">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-md-3 mb-4">
                        <a href="/operations/invoices.php?status=outstanding" class="dashboard-stat-box">
                            <div class="stat-icon text-danger">💰</div>
                            <div class="stat-number danger"><?= number_format($dashboard_data['invoices']['outstanding']); ?></div>
                            <div class="stat-label">Outstanding</div>
                        </a>
                    </div>
                    <div class="col-md-3 mb-4">
                        <a href="/operations/invoices.php?status=overdue" class="dashboard-stat-box">
                            <div class="stat-icon text-warning">⚠️</div>
                            <div class="stat-number warning"><?= number_format($dashboard_data['invoices']['overdue']); ?></div>
                            <div class="stat-label">Overdue</div>
                        </a>
                    </div>
                    <div class="col-md-3 mb-4">
                        <a href="/operations/invoices.php?status=paid" class="dashboard-stat-box">
                            <div class="stat-icon text-success">✅</div>
                            <div class="stat-number success"><?= number_format($dashboard_data['invoices']['paid_recent']); ?></div>
                            <div class="stat-label">Paid (30 Days)</div>
                        </a>
                    </div>
                    <div class="col-md-3 mb-4">
                        <div class="dashboard-stat-box">
                            <div class="stat-icon text-info">💸</div>
                            <div class="stat-number info"><?= $defaultCurrencySymbol ?><?= number_format($dashboard_data['invoices']['outstanding_value'] ?? 0, 0); ?></div>
                            <div class="stat-label">Outstanding Value</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <div class="container">
        <div class="row">
            <!-- Recent Activity -->
            <div class="col-lg-8">
                <div class="dashboard-box">
                    <h3 class="mb-4">
                        <i class="bi bi-clock-history me-2"></i>Recent Activity
                    </h3>
                    
                    <?php if (!empty($dashboard_data['recent_activity'])): ?>
                        <?php foreach (array_slice($dashboard_data['recent_activity'], 0, 8) as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon <?= $activity['type']; ?>">
                                    <i class="bi bi-<?= $activity['type'] === 'ticket' ? 'headset' : 'cart' ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">
                                        <?= $activity['type'] === 'ticket' ? 'Ticket' : 'Order' ?>: <?= htmlspecialchars($activity['title']); ?>
                                    </div>
                                    <div class="activity-meta">
                                        <span class="activity-status" style="background: <?= 
                                            $activity['status'] === 'completed' || strtolower($activity['status']) === 'closed' ? '#d1fae5' : 
                                            (in_array(strtolower($activity['status']), ['pending', 'open', 'in progress', 'awaiting member reply']) ? '#fef3c7' : '#f3f4f6') 
                                        ?>; color: <?= 
                                            $activity['status'] === 'completed' || strtolower($activity['status']) === 'closed' ? '#065f46' : 
                                            (in_array(strtolower($activity['status']), ['pending', 'open', 'in progress', 'awaiting member reply']) ? '#92400e' : '#374151') 
                                        ?>;"><?= ucfirst($activity['status']); ?></span>
                                        <span class="ms-2"><?= date('M j, Y g:i A', strtotime($activity['activity_date'])); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="text-center mt-3">
                            <a href="<?= $role === 'administrator' ? '/operations/dashboard.php' : '/members/activity.php' ?>" class="btn btn-outline-primary btn-sm">
                                View All Activity
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-clock-history" style="font-size: 3rem; color: #d1d5db;"></i>
                            <p class="text-muted mt-3">No recent activity found</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Services/Licenses -->
            <div class="col-lg-4">
                <div class="dashboard-box">
                    <h3 class="mb-4">
                        <i class="bi bi-gear me-2"></i>
                        <?= ($role === 'administrator') ? 'Recent Assignments' : 'Your Services'; ?>
                    </h3>
                    
                    <?php if (!empty($dashboard_data['licenses'])): ?>
                        <?php foreach (array_slice($dashboard_data['licenses'], 0, 8) as $license): ?>
                            <div class="license-card">
                                <div>
                                    <strong><?= htmlspecialchars($license['product_name'] ?: $license['bundle_name']); ?></strong>
                                    <?php if ($role === 'administrator' && isset($license['company_name'])): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($license['company_name']); ?></small>
                                    <?php endif; ?>
                                    <?php if (isset($license['product_description']) && $license['product_description']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars(substr($license['product_description'], 0, 50)); ?><?= strlen($license['product_description']) > 50 ? '...' : ''; ?></small>
                                    <?php endif; ?>
                                </div>
                                <span class="license-status active">Active</span>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if (count($dashboard_data['licenses']) > 8): ?>
                            <div class="text-center mt-3">
                                <a href="/members/my-services.php" class="btn btn-outline-primary btn-sm">
                                    View All (<?= count($dashboard_data['licenses']); ?> total)
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-gear" style="font-size: 3rem; color: #d1d5db;"></i>
                            <p class="text-muted mt-3 mb-3">No active services found</p>
                            <?php if (in_array($role, ['account_manager', 'administrator'])): ?>
                                <a href="/members/create-order.php" class="btn btn-primary btn-sm">Request Services</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($role === 'administrator'): ?>
            <!-- Admin Controls Box -->
            <div class="dashboard-box">
                <h3 class="mb-4"><i class="bi bi-tools me-2"></i>Admin Controls</h3>
                <div class="d-flex gap-3 flex-wrap justify-content-center">
                    <a href="/operations/manage-companies.php" class="btn btn-custom-primary">
                        <i class="bi bi-building me-2"></i>Manage Companies
                    </a>
                    <a href="/operations/manage-users.php" class="btn btn-custom-primary">
                        <i class="bi bi-people me-2"></i>Manage Users
                    </a>
                    <a href="/operations/service-catalog.php" class="btn btn-custom-primary">
                        <i class="bi bi-list-ul me-2"></i>Service Catalog
                    </a>
                    <a href="/operations/staff-analytics.php" class="btn btn-custom-primary">
                        <i class="bi bi-graph-up me-2"></i>Analytics
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- CRITICAL FIX: Load Bootstrap FIRST, then force initialize dropdowns -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// IMMEDIATE bootstrap dropdown initialization - run as soon as DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('Dashboard loaded successfully');
    console.log('Bootstrap version:', typeof bootstrap !== 'undefined' ? bootstrap.Tooltip.VERSION : 'Not loaded');
    
    // Test dropdown functionality
    const dropdowns = document.querySelectorAll('.dropdown-toggle');
    console.log('Found dropdowns:', dropdowns.length);
    
    dropdowns.forEach((dropdown, index) => {
        console.log(`Dropdown ${index}:`, dropdown.id || dropdown.className);
        
        // Manual click event to test
        dropdown.addEventListener('click', function(e) {
            console.log('Dropdown clicked:', this);
            console.log('Event:', e);
            
            // Force show dropdown if Bootstrap isn't working
            const menu = this.nextElementSibling;
            if (menu && menu.classList.contains('dropdown-menu')) {
                console.log('Found dropdown menu:', menu);
                if (menu.style.display === 'block') {
                    menu.style.display = 'none';
                    this.setAttribute('aria-expanded', 'false');
                } else {
                    // Hide all other dropdowns first
                    document.querySelectorAll('.dropdown-menu').forEach(m => {
                        m.style.display = 'none';
                    });
                    document.querySelectorAll('.dropdown-toggle').forEach(t => {
                        t.setAttribute('aria-expanded', 'false');
                    });
                    
                    // Show this dropdown
                    menu.style.display = 'block';
                    this.setAttribute('aria-expanded', 'true');
                }
            }
        });
    });
    
    // FORCE re-initialize Bootstrap dropdowns
    setTimeout(function() {
        try {
            // Initialize each dropdown manually
            dropdowns.forEach(function(dropdownToggle) {
                if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
                    try {
                        const dropdownInstance = new bootstrap.Dropdown(dropdownToggle);
                        console.log('Bootstrap dropdown initialized for:', dropdownToggle.id);
                    } catch (e) {
                        console.error('Failed to initialize dropdown:', e);
                    }
                }
            });
        } catch (e) {
            console.error('Bootstrap dropdown initialization failed:', e);
        }
    }, 200);
    
    // Click outside to close dropdowns
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                menu.style.display = 'none';
            });
            document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
                toggle.setAttribute('aria-expanded', 'false');
            });
        }
    });
    
    // Delayed animations
    setTimeout(() => {
        const statBoxes = document.querySelectorAll('.dashboard-stat-box');
        statBoxes.forEach((box, index) => {
            box.style.opacity = '0';
            box.style.transition = 'opacity 0.5s ease';
            setTimeout(() => {
                box.style.opacity = '1';
            }, index * 50);
        });
    }, 1000);
});

// Manual test function
function forceTestDropdown(id) {
    const dropdown = document.getElementById(id);
    if (dropdown) {
        dropdown.click();
        console.log('Force clicked:', id);
    }
}

// Test all dropdowns
function testAllDropdowns() {
    console.log('=== TESTING ALL DROPDOWNS ===');
    const dropdowns = ['supportDropdown', 'langDropdown', 'userDropdown'];
    dropdowns.forEach(id => {
        console.log('Testing:', id);
        forceTestDropdown(id);
        setTimeout(() => forceTestDropdown(id), 1000); // Close it
    });
}

console.log('Call testAllDropdowns() to test manually');
</script>

</body>
</html>