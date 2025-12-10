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
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>
    <style>

        /* Dashboard Cards Enhancement */
        .dashboard-overlap-section {
            margin-top: -100px;
            position: relative;
            z-index: 10;
        }

        .dashboard-stat-box {
            background: white;
            border: none;
            border-radius: var(--border-radius);
            padding: 2rem 1.5rem;
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
            height: 100%;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }

        .dashboard-stat-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }
        
        .dashboard-stat-box:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
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
        
        .stat-number.primary { color: #667eea; }
        .stat-number.success { color: #11998e; }
        .stat-number.warning { color: #f093fb; }
        .stat-number.danger { color: #ff6b6b; }
        .stat-number.info { color: #74b9ff; }
        
        .stat-label {
            color: #6b7280;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .dashboard-box {
            background: white;
            border: none;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .dashboard-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .dashboard-box:hover {
            transform: translateY(-2px);
            box-shadow: var(--card-shadow-hover);
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            transition: var(--transition);
            text-decoration: none;
            color: inherit;
            cursor: pointer;
        }

        .activity-item:hover {
            background: #f1f5f9;
            transform: translateX(5px);
            text-decoration: none;
            color: inherit;
            border-color: #667eea;
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
        
        .activity-icon.ticket { background: var(--primary-gradient); }
        .activity-icon.order { background: var(--success-gradient); }
        .activity-icon.invoice { background: var(--warning-gradient); }
        
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
            transition: var(--transition);
        }
        
        .license-card:hover {
            background: #f8fafc;
            transform: translateX(3px);
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

        .empty-state-icon {
            font-size: 3rem;
            color: #d1d5db;
        }

        /* Enhanced Quick Actions */
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
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 12px;
            color: white;
            text-decoration: none;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }
        
        .quick-action-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }

        /* Stats Grid Layouts */
        .customer-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

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

            .dashboard-hero-title {
                font-size: 2.5rem;
            }

            .dashboard-hero-actions {
                flex-direction: column;
                align-items: center;
            }
        }

        @media (max-width: 768px) {
            .customer-stats,
            .admin-stats {
                grid-template-columns: 1fr;
            }

            .dashboard-hero-title {
                font-size: 2rem;
            }
        }

        /* Enhanced Button Styles */
        .btn-custom-primary {
            background: var(--primary-gradient);
            border: none;
            color: white;
            transition: var(--transition);
            border-radius: 8px;
        }

        .btn-custom-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .btn-outline-primary {
            border-color: #667eea;
            color: #667eea;
            transition: var(--transition);
        }

        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            border-color: transparent;
            color: white;
        }

        /* DARK MODE STYLES */
        :root.dark,
        :root.dark body {
            background: #0f172a !important;
            color: #e2e8f0 !important;
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

        :root.dark .dashboard-hero-content,
        :root.dark .dashboard-hero-title,
        :root.dark .dashboard-hero-subtitle {
            color: white !important;
            opacity: 1 !important;
            visibility: visible !important;
            z-index: 1 !important;
            position: relative !important;
        }

        /* Typography */
        :root.dark h1, :root.dark h2, :root.dark h3, :root.dark h4, :root.dark h5, :root.dark h6 { color: white !important; }
        :root.dark .text-muted { color: #94a3b8 !important; }
        :root.dark small { color: #94a3b8 !important; }

        /* Dashboard stat boxes */
        :root.dark .dashboard-stat-box {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .dashboard-stat-box:hover {
            background: #1e293b !important;
            border-color: #8b5cf6 !important;
        }

        :root.dark .stat-number {
            color: #a78bfa !important;
        }

        :root.dark .stat-number.primary { color: #a78bfa !important; }
        :root.dark .stat-number.success { color: #34d399 !important; }
        :root.dark .stat-number.warning { color: #fbbf24 !important; }
        :root.dark .stat-number.danger { color: #f87171 !important; }
        :root.dark .stat-number.info { color: #60a5fa !important; }

        :root.dark .stat-label {
            color: #94a3b8 !important;
        }

        /* Dashboard boxes */
        :root.dark .dashboard-box {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        /* Activity items */
        :root.dark .activity-item {
            background: #0f172a !important;
            border-color: #334155 !important;
            text-decoration: none !important;
            color: inherit !important;
        }

        :root.dark .activity-item:hover {
            background: #1e293b !important;
            border-color: #8b5cf6 !important;
            text-decoration: none !important;
            color: inherit !important;
        }

        :root.dark .activity-title {
            color: #f1f5f9 !important;
        }

        :root.dark .activity-meta {
            color: #94a3b8 !important;
        }

        /* License cards */
        :root.dark .license-card {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .license-card:hover {
            background: #1e293b !important;
        }

        :root.dark .license-card strong {
            color: #f1f5f9 !important;
        }

        :root.dark .license-status.active {
            background: rgba(52, 211, 153, 0.2) !important;
            color: #34d399 !important;
        }

        /* Buttons */
        :root.dark .btn-outline-primary {
            border-color: #8b5cf6 !important;
            color: #a78bfa !important;
        }

        :root.dark .btn-outline-primary:hover {
            background: var(--primary-gradient) !important;
            border-color: #8b5cf6 !important;
            color: white !important;
        }

        :root.dark .btn-custom-primary {
            background: var(--primary-gradient) !important;
            color: white !important;
        }

        /* Quick actions */
        :root.dark .quick-action-btn {
            background: rgba(139, 92, 246, 0.15) !important;
            border-color: #8b5cf6 !important;
        }

        :root.dark .quick-action-btn:hover {
            background: rgba(139, 92, 246, 0.25) !important;
        }

        /* Empty states */
        :root.dark .empty-state-icon {
            color: #475569 !important;
        }

        /* Alert */
        :root.dark .alert {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        /* Activity Status Badges - Dark Mode */
        :root.dark .activity-status {
            background: transparent !important;
            border: 1px solid !important;
        }

        /* Status-specific dark mode colors with better visibility */
        :root.dark .activity-item .activity-status[style*="#d1fae5"] {
            background: linear-gradient(135deg, #065f46 0%, #047857 100%) !important;
            color: #a7f3d0 !important;
            border-color: #10b981 !important;
        }

        :root.dark .activity-item .activity-status[style*="#fef3c7"] {
            background: linear-gradient(135deg, #92400e 0%, #b45309 100%) !important;
            color: #fde68a !important;
            border-color: #f59e0b !important;
        }

        :root.dark .activity-item .activity-status[style*="#f3f4f6"] {
            background: linear-gradient(135deg, #334155 0%, #475569 100%) !important;
            color: #cbd5e1 !important;
            border-color: #64748b !important;
        }

    </style>

<!-- Hero Section - Using Theme -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="dashboard-hero-content">
            <h1 class="dashboard-hero-title">
                <i class="bi bi-speedometer2 me-2"></i>
                Welcome back, <?= $username; ?>!
            </h1>
            <p class="dashboard-hero-subtitle">
                Here's your comprehensive dashboard overview with real-time insights and quick access to essential features.
            </p>
            <div class="dashboard-hero-actions">
                <a href="/members/raise-ticket.php" class="btn c-btn-primary">
                    <i class="bi bi-plus-circle me-1"></i>
                    Raise Ticket
                </a>
                <?php if (in_array($role, ['account_manager', 'administrator'])): ?>
                    <a href="/members/create-order.php" class="btn c-btn-ghost">
                        <i class="bi bi-cart-plus me-1"></i>
                        Place Order
                    </a>
                <?php endif; ?>
                <a href="/members/view-invoice.php" class="btn c-btn-ghost">
                    <i class="bi bi-receipt me-1"></i>
                    View Invoices
                </a>
                <a href="/members/account.php" class="btn c-btn-ghost">
                    <i class="bi bi-person-gear me-1"></i>
                    My Account
                </a>
            </div>
        </div>
    </div>
</header>

<!-- Main Dashboard Content -->
<div class="py-5">
    <?php if ($role === 'administrator'): ?>
        <!-- ADMIN DASHBOARD - All 5 cards in one row -->
        <section class="dashboard-overlap-section">
            <div class="container">
                <div class="admin-stats fade-in">
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
                <div class="customer-stats fade-in">
                    <a href="/members/view-invoice.php" class="dashboard-stat-box">
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
                <div class="row justify-content-center fade-in">
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
                <div class="row justify-content-center fade-in">
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
                <div class="dashboard-box fade-in">
                    <h3 class="mb-4">
                        <i class="bi bi-clock-history me-2"></i>Recent Activity
                    </h3>
                    
                    <?php if (!empty($dashboard_data['recent_activity'])): ?>
                        <?php foreach (array_slice($dashboard_data['recent_activity'], 0, 8) as $activity): ?>
                            <?php
                                // Staff roles get /operations/ links, regular users get /members/ links
                                $staff_roles = ['administrator', 'support_consultant', 'accountant'];
                                $is_staff_user = in_array($role, $staff_roles);

                                // Build the link URL based on type and role
                                if ($activity['type'] === 'ticket') {
                                    $activity_url = $is_staff_user
                                        ? '/operations/view-ticket.php?id=' . $activity['id']
                                        : '/members/view-ticket.php?id=' . $activity['id'];
                                } else if ($activity['type'] === 'order') {
                                    $activity_url = $is_staff_user
                                        ? '/operations/view-order.php?id=' . $activity['id']
                                        : '/members/view-order.php?id=' . $activity['id'];
                                } else {
                                    $activity_url = '#';
                                }
                            ?>
                            <a href="<?= $activity_url ?>" class="activity-item">
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
                            </a>
                        <?php endforeach; ?>
                        
                        <div class="text-center mt-3">
                            <a href="<?= $role === 'administrator' ? '/operations/dashboard.php' : '/members/activity.php' ?>" class="btn btn-outline-primary btn-sm">
                                View All Activity
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-clock-history empty-state-icon"></i>
                            <p class="text-muted mt-3">No recent activity found</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Services/Licenses -->
            <div class="col-lg-4">
                <div class="dashboard-box fade-in">
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
                            <i class="bi bi-gear empty-state-icon"></i>
                            <p class="text-muted mt-3 mb-3">No active services found</p>
                            <?php if (in_array($role, ['account_manager', 'administrator'])): ?>
                                <a href="/members/create-order.php" class="btn btn-custom-primary btn-sm">Request Services</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($role === 'administrator'): ?>
            <!-- Admin Controls Box -->
            <div class="dashboard-box fade-in">
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
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
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

    // Enhanced card animations
    const statBoxes = document.querySelectorAll('.dashboard-stat-box');
    statBoxes.forEach((box, index) => {
        box.style.opacity = '0';
        box.style.transform = 'translateY(30px)';
        
        setTimeout(() => {
            box.style.transition = 'all 0.6s ease';
            box.style.opacity = '1';
            box.style.transform = 'translateY(0)';
        }, index * 100);
    });

    // Smooth scrolling for hero buttons
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

    // Activity items now use CSS hover effects via anchor tags
    // No JavaScript hover manipulation needed

    // Stats counter animation
    const statsNumbers = document.querySelectorAll('.stat-number');
    statsNumbers.forEach(stat => {
        const finalValue = parseInt(stat.textContent.replace(/,/g, ''));
        if (finalValue > 0 && finalValue < 10000) {
            let currentValue = 0;
            const increment = finalValue / 50;
            const timer = setInterval(() => {
                currentValue += increment;
                if (currentValue >= finalValue) {
                    stat.textContent = finalValue.toLocaleString();
                    clearInterval(timer);
                } else {
                    stat.textContent = Math.floor(currentValue).toLocaleString();
                }
            }, 30);
        }
    });

    console.log('Dashboard enhanced with purple theme loaded successfully');
});
</script>



<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
