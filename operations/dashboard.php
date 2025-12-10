<?php
// Disable error display in production, log errors instead
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Access control (Staff and Admin only)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'staff', 'support_user', 'support_technician', 'accountant'])) {
    header('Location: /login.php');
    exit;
}

$user = $_SESSION['user'];

// Get time period from query parameter with validation
$allowed_periods = ['today', '7d', '30d', '90d', 'year'];
$period = isset($_GET['period']) && in_array($_GET['period'], $allowed_periods, true) ? $_GET['period'] : '30d';
$startDate = null;
$endDate = date('Y-m-d 23:59:59');

switch ($period) {
    case 'today':
        $startDate = date('Y-m-d 00:00:00');
        break;
    case '7d':
        $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
        break;
    case '30d':
        $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
        break;
    case '90d':
        $startDate = date('Y-m-d 00:00:00', strtotime('-90 days'));
        break;
    case 'year':
        $startDate = date('Y-m-d 00:00:00', strtotime('-1 year'));
        break;
    default:
        $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
}

// Fetch live data - Support Tickets
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_tickets,
            SUM(CASE WHEN status = 'Open' THEN 1 ELSE 0 END) as open_tickets,
            SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_tickets,
            SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as closed_tickets,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_tickets,
            SUM(CASE WHEN priority = 'High' THEN 1 ELSE 0 END) as high_priority_tickets
        FROM support_tickets
        WHERE created_at >= ?
    ");
    $stmt->execute([$startDate]);
    $ticket_stats = $stmt->fetch();
} catch (Exception $e) {
    $ticket_stats = [
        'total_tickets' => 0,
        'open_tickets' => 0,
        'in_progress_tickets' => 0,
        'closed_tickets' => 0,
        'pending_tickets' => 0,
        'high_priority_tickets' => 0
    ];
}

// Fetch Orders Data
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_orders,
            SUM(CASE WHEN status = 'pending_payment' THEN 1 ELSE 0 END) as pending_orders,
            SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as total_revenue
        FROM orders
        WHERE created_at >= ?
    ");
    $stmt->execute([$startDate]);
    $order_stats = $stmt->fetch();
} catch (Exception $e) {
    $order_stats = [
        'total_orders' => 0,
        'completed_orders' => 0,
        'processing_orders' => 0,
        'pending_orders' => 0,
        'total_revenue' => 0
    ];
}

// Fetch Invoices Data
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_invoices,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_invoices,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_invoices,
            SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_invoices,
            SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END) as total_paid
        FROM invoices
        WHERE created_at >= ?
    ");
    $stmt->execute([$startDate]);
    $invoice_stats = $stmt->fetch();
} catch (Exception $e) {
    $invoice_stats = [
        'total_invoices' => 0,
        'paid_invoices' => 0,
        'pending_invoices' => 0,
        'overdue_invoices' => 0,
        'total_paid' => 0
    ];
}

// Fetch User Statistics
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as new_users
        FROM users
    ");
    $stmt->execute([$startDate]);
    $user_stats = $stmt->fetch();
} catch (Exception $e) {
    $user_stats = [
        'total_users' => 0,
        'new_users' => 0
    ];
}

// Fetch Recent Activity - Tickets
try {
    $stmt = $pdo->prepare("
        SELECT t.id, t.subject, t.status, t.priority, t.created_at, u.username
        FROM support_tickets t
        LEFT JOIN users u ON t.user_id = u.id
        ORDER BY t.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_tickets = $stmt->fetchAll();
} catch (Exception $e) {
    $recent_tickets = [];
}

// Fetch Daily Ticket Trend for Chart
try {
    $stmt = $pdo->prepare("
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM support_tickets
        WHERE created_at >= ?
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $stmt->execute([$startDate]);
    $daily_tickets = $stmt->fetchAll();
} catch (Exception $e) {
    $daily_tickets = [];
}

// Fetch Daily Order Trend for Chart
try {
    $stmt = $pdo->prepare("
        SELECT DATE(created_at) as date, COUNT(*) as count, SUM(total_amount) as revenue
        FROM orders
        WHERE created_at >= ? AND payment_status = 'paid'
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $stmt->execute([$startDate]);
    $daily_orders = $stmt->fetchAll();
} catch (Exception $e) {
    $daily_orders = [];
}

// Ticket Status Distribution for Pie Chart
try {
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count
        FROM support_tickets
        WHERE created_at >= ?
        GROUP BY status
    ");
    $stmt->execute([$startDate]);
    $ticket_status_dist = $stmt->fetchAll();
} catch (Exception $e) {
    $ticket_status_dist = [];
}

// Top Performing Categories
try {
    $stmt = $pdo->prepare("
        SELECT g.name, COUNT(t.id) as ticket_count
        FROM support_ticket_groups g
        LEFT JOIN support_tickets t ON g.id = t.group_id AND t.created_at >= ?
        GROUP BY g.id, g.name
        HAVING ticket_count > 0
        ORDER BY ticket_count DESC
        LIMIT 5
    ");
    $stmt->execute([$startDate]);
    $top_categories = $stmt->fetchAll();
} catch (Exception $e) {
    $top_categories = [];
}

$page_title = "Operations Dashboard | CaminhoIT";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">

<style>
    :root {
        --primary-color: #4F46E5;
        --primary-hover: #3F37C9;
        --success-color: #10B981;
        --warning-color: #F59E0B;
        --danger-color: #EF4444;
        --info-color: #06B6D4;
        --light-gray: #F8FAFC;
        --border-color: #E2E8F0;
        --text-muted: #64748B;
        --card-bg: #ffffff;
        --text-color: #1e293b;
        --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    /* Dark Mode Variables */
    :root.dark {
        --light-gray: #0f172a;
        --card-bg: #1e293b;
        --border-color: #334155;
        --text-color: #e2e8f0;
        --text-muted: #94a3b8;
        --shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background-color: var(--light-gray);
        color: var(--text-color);
        padding-top: 80px;
        transition: background-color 0.3s, color 0.3s;
    }

    /* Navbar */
    .navbar {
        background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        padding: 0.75rem 0;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1030;
    }

    .navbar-brand {
        font-family: 'Space Grotesk', sans-serif;
        font-weight: 700;
        font-size: 1.5rem;
        color: white !important;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .navbar-brand img {
        height: 35px;
    }

    .navbar .nav-link {
        color: white !important;
        font-weight: 500;
        transition: all 0.2s;
        padding: 0.5rem 1rem !important;
    }

    .navbar .nav-link:hover {
        color: #e0e7ff !important;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 8px;
    }

    .navbar .dropdown-menu {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .navbar .dropdown-item {
        color: var(--text-color);
    }

    .navbar .dropdown-item:hover {
        background: var(--light-gray);
    }

    :root.dark .navbar .dropdown-menu {
        background: #1e293b;
    }

    /* Hero Section */
    .hero-section {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 3rem 0;
        margin-bottom: 2rem;
        border-radius: 0 0 24px 24px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .hero-section h1 {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .hero-section p {
        font-size: 1.125rem;
        opacity: 0.9;
    }

    /* Main Container */
    .main-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 1rem 2rem;
    }

    /* Period Selector */
    .period-selector {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 2rem;
        background: var(--card-bg);
        padding: 0.5rem;
        border-radius: 12px;
        box-shadow: var(--shadow);
        flex-wrap: wrap;
    }

    .period-btn {
        padding: 0.5rem 1rem;
        border: none;
        background: transparent;
        color: var(--text-muted);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
        font-weight: 500;
        text-decoration: none;
        display: inline-block;
    }

    .period-btn:hover {
        background: var(--light-gray);
        color: var(--primary-color);
        text-decoration: none;
    }

    .period-btn.active {
        background: var(--primary-color);
        color: white;
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background: var(--card-bg);
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: var(--shadow);
        transition: transform 0.2s, box-shadow 0.2s;
        border: 1px solid var(--border-color);
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }

    .stat-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1rem;
    }

    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
    }

    .stat-icon.primary { background: rgba(79, 70, 229, 0.1); color: var(--primary-color); }
    .stat-icon.success { background: rgba(16, 185, 129, 0.1); color: var(--success-color); }
    .stat-icon.warning { background: rgba(245, 158, 11, 0.1); color: var(--warning-color); }
    .stat-icon.danger { background: rgba(239, 68, 68, 0.1); color: var(--danger-color); }
    .stat-icon.info { background: rgba(6, 182, 212, 0.1); color: var(--info-color); }

    .stat-title {
        font-size: 0.875rem;
        color: var(--text-muted);
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        color: var(--text-color);
        margin-bottom: 0.5rem;
    }

    .stat-change {
        font-size: 0.875rem;
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }

    .stat-change.positive { color: var(--success-color); }
    .stat-change.negative { color: var(--danger-color); }

    /* Charts Grid */
    .charts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .chart-card {
        background: var(--card-bg);
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: var(--shadow);
        border: 1px solid var(--border-color);
    }

    .chart-card h3 {
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: var(--text-color);
    }

    .chart-container {
        position: relative;
        height: 300px;
    }

    /* Activity Section */
    .activity-section {
        background: var(--card-bg);
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: var(--shadow);
        border: 1px solid var(--border-color);
        margin-bottom: 2rem;
    }

    .activity-section h3 {
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: var(--text-color);
    }

    .activity-list {
        list-style: none;
    }

    .activity-item {
        padding: 1rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        justify-content: space-between;
        transition: background 0.2s;
    }

    .activity-item:last-child {
        border-bottom: none;
    }

    .activity-item:hover {
        background: var(--light-gray);
        border-radius: 8px;
    }

    .activity-content {
        flex: 1;
    }

    .activity-title {
        font-weight: 600;
        color: var(--text-color);
        margin-bottom: 0.25rem;
    }

    .activity-meta {
        font-size: 0.875rem;
        color: var(--text-muted);
    }

    .activity-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .badge-open { background: rgba(6, 182, 212, 0.1); color: var(--info-color); }
    .badge-closed { background: rgba(16, 185, 129, 0.1); color: var(--success-color); }
    .badge-pending { background: rgba(245, 158, 11, 0.1); color: var(--warning-color); }
    .badge-high { background: rgba(239, 68, 68, 0.1); color: var(--danger-color); }
    .badge-medium { background: rgba(245, 158, 11, 0.1); color: var(--warning-color); }
    .badge-low { background: rgba(16, 185, 129, 0.1); color: var(--success-color); }

    /* Dark Mode Toggle */
    .theme-toggle {
        position: fixed;
        bottom: 2rem;
        right: 2rem;
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: var(--primary-color);
        color: white;
        border: none;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        transition: all 0.3s;
        z-index: 1000;
    }

    .theme-toggle:hover {
        transform: scale(1.1);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
    }

    /* Refresh Button */
    .refresh-btn {
        position: fixed;
        bottom: 2rem;
        right: 5rem;
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: var(--success-color);
        color: white;
        border: none;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        transition: all 0.3s;
        z-index: 1000;
    }

    .refresh-btn:hover {
        transform: scale(1.1) rotate(180deg);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .hero-section h1 {
            font-size: 1.75rem;
        }

        .charts-grid {
            grid-template-columns: 1fr;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .theme-toggle, .refresh-btn {
            width: 48px;
            height: 48px;
            font-size: 20px;
        }

        .refresh-btn {
            right: 4rem;
        }
    }

    /* Footer */
    .footer {
        background: var(--card-bg);
        border-top: 1px solid var(--border-color);
        padding: 2rem 0;
        margin-top: 4rem;
        text-align: center;
        color: var(--text-muted);
    }

    .footer p {
        margin: 0.5rem 0;
    }

    .footer a {
        color: var(--primary-color);
        text-decoration: none;
    }

    .footer a:hover {
        text-decoration: underline;
    }
</style>
</head>
<body>

<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
        <a class="navbar-brand" href="/">
            <img src="/assets/logo.png" alt="CaminhoIT" onerror="this.style.display='none'">
            <span>CAMINHO<span style="color: #60a5fa;">IT</span></span>
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item">
                    <a class="nav-link" href="/operations/dashboard.php">Dashboard</a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="ticketsDropdown" role="button" data-bs-toggle="dropdown">
                        Tickets
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="/operations/staff-tickets.php">All Tickets</a></li>
                        <li><a class="dropdown-item" href="/operations/staff-analytics.php">Analytics</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="ordersDropdown" role="button" data-bs-toggle="dropdown">
                        Orders
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="/operations/orders.php">All Orders</a></li>
                        <li><a class="dropdown-item" href="/operations/quotes.php">Quotes</a></li>
                        <li><a class="dropdown-item" href="/operations/invoices.php">Invoices</a></li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/members/dashboard.php">My Account</a>
                </li>
                <li class="nav-item">
                    <span class="nav-link">👤 <?= htmlspecialchars($user['username']) ?></span>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Hero Section -->
<div class="hero-section">
    <div class="main-container">
        <h1>📊 Operations Dashboard</h1>
        <p>Real-time insights into your business operations</p>
    </div>
</div>

<!-- Main Container -->
<div class="main-container">
    <!-- Period Selector -->
    <div class="period-selector">
        <a href="?period=today" class="period-btn <?= $period === 'today' ? 'active' : '' ?>">Today</a>
        <a href="?period=7d" class="period-btn <?= $period === '7d' ? 'active' : '' ?>">7 Days</a>
        <a href="?period=30d" class="period-btn <?= $period === '30d' ? 'active' : '' ?>">30 Days</a>
        <a href="?period=90d" class="period-btn <?= $period === '90d' ? 'active' : '' ?>">90 Days</a>
        <a href="?period=year" class="period-btn <?= $period === 'year' ? 'active' : '' ?>">Year</a>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <!-- Tickets -->
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-icon primary">🎫</span>
            </div>
            <div class="stat-title">Support Tickets</div>
            <div class="stat-value"><?= number_format($ticket_stats['total_tickets']) ?></div>
            <div class="stat-change">
                <span><?= number_format($ticket_stats['open_tickets']) ?> Open</span>
                <span style="margin-left: 1rem"><?= number_format($ticket_stats['closed_tickets']) ?> Closed</span>
            </div>
        </div>

        <!-- High Priority Tickets -->
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-icon danger">⚠️</span>
            </div>
            <div class="stat-title">High Priority</div>
            <div class="stat-value"><?= number_format($ticket_stats['high_priority_tickets']) ?></div>
            <div class="stat-change">
                <span><?= number_format($ticket_stats['pending_tickets']) ?> Pending</span>
            </div>
        </div>

        <!-- Orders -->
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-icon info">📦</span>
            </div>
            <div class="stat-title">Orders</div>
            <div class="stat-value"><?= number_format($order_stats['total_orders']) ?></div>
            <div class="stat-change">
                <span><?= number_format($order_stats['completed_orders']) ?> Completed</span>
                <span style="margin-left: 1rem"><?= number_format($order_stats['processing_orders']) ?> Processing</span>
            </div>
        </div>

        <!-- Revenue -->
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-icon success">💰</span>
            </div>
            <div class="stat-title">Revenue</div>
            <div class="stat-value">£<?= number_format($order_stats['total_revenue'], 2) ?></div>
            <div class="stat-change positive">
                <span>From paid orders</span>
            </div>
        </div>

        <!-- Invoices -->
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-icon warning">📄</span>
            </div>
            <div class="stat-title">Invoices</div>
            <div class="stat-value"><?= number_format($invoice_stats['total_invoices']) ?></div>
            <div class="stat-change">
                <span><?= number_format($invoice_stats['paid_invoices']) ?> Paid</span>
                <span style="margin-left: 1rem"><?= number_format($invoice_stats['pending_invoices']) ?> Pending</span>
            </div>
        </div>

        <!-- Users -->
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-icon primary">👥</span>
            </div>
            <div class="stat-title">Total Users</div>
            <div class="stat-value"><?= number_format($user_stats['total_users']) ?></div>
            <div class="stat-change positive">
                <span>+<?= number_format($user_stats['new_users']) ?> new</span>
            </div>
        </div>
    </div>

    <!-- Charts Grid -->
    <div class="charts-grid">
        <!-- Ticket Trend Chart -->
        <div class="chart-card">
            <h3>📈 Ticket Trend</h3>
            <div class="chart-container">
                <canvas id="ticketTrendChart"></canvas>
            </div>
        </div>

        <!-- Order Revenue Chart -->
        <div class="chart-card">
            <h3>💵 Order Revenue</h3>
            <div class="chart-container">
                <canvas id="orderRevenueChart"></canvas>
            </div>
        </div>

        <!-- Ticket Status Distribution -->
        <div class="chart-card">
            <h3>🎯 Ticket Status</h3>
            <div class="chart-container">
                <canvas id="ticketStatusChart"></canvas>
            </div>
        </div>

        <!-- Top Categories -->
        <div class="chart-card">
            <h3>🏆 Top Categories</h3>
            <div class="chart-container">
                <canvas id="topCategoriesChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="activity-section">
        <h3>🔔 Recent Tickets</h3>
        <ul class="activity-list">
            <?php if (empty($recent_tickets)): ?>
                <li class="activity-item">
                    <div class="activity-content">
                        <div class="activity-title">No recent tickets</div>
                        <div class="activity-meta">Check back later for updates</div>
                    </div>
                </li>
            <?php else: ?>
                <?php foreach ($recent_tickets as $ticket): ?>
                    <li class="activity-item">
                        <div class="activity-content">
                            <div class="activity-title"><?= htmlspecialchars($ticket['subject']) ?></div>
                            <div class="activity-meta">
                                By <?= htmlspecialchars($ticket['username'] ?? 'Unknown') ?> • 
                                <?= date('M d, Y H:i', strtotime($ticket['created_at'])) ?>
                            </div>
                        </div>
                        <div>
                            <span class="activity-badge badge-<?= strtolower($ticket['status']) ?>"><?= htmlspecialchars($ticket['status']) ?></span>
                            <?php if ($ticket['priority']): ?>
                                <span class="activity-badge badge-<?= strtolower($ticket['priority']) ?>" style="margin-left: 0.5rem"><?= htmlspecialchars($ticket['priority']) ?></span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>
</div>

<!-- Footer -->
<footer class="footer">
    <div class="main-container">
        <p>&copy; <?= date('Y') ?> CaminhoIT. All rights reserved.</p>
        <p>
            <a href="/privacy-policy.php">Privacy Policy</a> | 
            <a href="/terms-of-service.php">Terms of Service</a>
        </p>
    </div>
</footer>

<!-- Theme Toggle Button -->
<button class="theme-toggle" id="themeToggle" title="Toggle Dark Mode">
    <i data-lucide="moon" id="themeIcon"></i>
</button>

<!-- Refresh Button -->
<button class="refresh-btn" id="refreshBtn" title="Refresh Data">
    <i data-lucide="refresh-cw"></i>
</button>

<script>
// Initialize Lucide icons
lucide.createIcons();

// Theme Toggle
const themeToggle = document.getElementById('themeToggle');
const themeIcon = document.getElementById('themeIcon');
const root = document.documentElement;

// Check for saved theme preference or default to light mode
const currentTheme = localStorage.getItem('theme') || 'light';
if (currentTheme === 'dark') {
    root.classList.add('dark');
    themeIcon.setAttribute('data-lucide', 'sun');
}

themeToggle.addEventListener('click', () => {
    root.classList.toggle('dark');
    const isDark = root.classList.contains('dark');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    themeIcon.setAttribute('data-lucide', isDark ? 'sun' : 'moon');
    lucide.createIcons();
});

// Refresh Button
document.getElementById('refreshBtn').addEventListener('click', () => {
    location.reload();
});

// Chart.js Configuration
const chartColors = {
    primary: '#4F46E5',
    success: '#10B981',
    warning: '#F59E0B',
    danger: '#EF4444',
    info: '#06B6D4',
    purple: '#764ba2',
    pink: '#ec4899'
};

// Ticket Trend Chart
const ticketTrendData = <?= json_encode($daily_tickets) ?>;
const ticketDates = ticketTrendData.map(d => d.date);
const ticketCounts = ticketTrendData.map(d => parseInt(d.count));

const ticketTrendCtx = document.getElementById('ticketTrendChart').getContext('2d');
new Chart(ticketTrendCtx, {
    type: 'line',
    data: {
        labels: ticketDates,
        datasets: [{
            label: 'Tickets',
            data: ticketCounts,
            borderColor: chartColors.primary,
            backgroundColor: 'rgba(79, 70, 229, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    precision: 0
                }
            }
        }
    }
});

// Order Revenue Chart
const orderRevenueData = <?= json_encode($daily_orders) ?>;
const orderDates = orderRevenueData.map(d => d.date);
const orderRevenue = orderRevenueData.map(d => parseFloat(d.revenue || 0));

const orderRevenueCtx = document.getElementById('orderRevenueChart').getContext('2d');
new Chart(orderRevenueCtx, {
    type: 'bar',
    data: {
        labels: orderDates,
        datasets: [{
            label: 'Revenue (£)',
            data: orderRevenue,
            backgroundColor: chartColors.success,
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

// Ticket Status Chart
const ticketStatusData = <?= json_encode($ticket_status_dist) ?>;
const statusLabels = ticketStatusData.map(d => d.status);
const statusCounts = ticketStatusData.map(d => parseInt(d.count));

const ticketStatusCtx = document.getElementById('ticketStatusChart').getContext('2d');
new Chart(ticketStatusCtx, {
    type: 'doughnut',
    data: {
        labels: statusLabels,
        datasets: [{
            data: statusCounts,
            backgroundColor: [
                chartColors.info,
                chartColors.warning,
                chartColors.success,
                chartColors.danger,
                chartColors.purple
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Top Categories Chart
const topCategoriesData = <?= json_encode($top_categories) ?>;
const categoryNames = topCategoriesData.map(d => d.name);
const categoryCounts = topCategoriesData.map(d => parseInt(d.ticket_count));

const topCategoriesCtx = document.getElementById('topCategoriesChart').getContext('2d');
new Chart(topCategoriesCtx, {
    type: 'bar',
    data: {
        labels: categoryNames,
        datasets: [{
            label: 'Tickets',
            data: categoryCounts,
            backgroundColor: chartColors.purple,
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            x: {
                beginAtZero: true,
                ticks: {
                    precision: 0
                }
            }
        }
    }
});

// Auto-refresh every 5 minutes using AJAX to avoid full page reload
// Note: Full page reload is intentional here to ensure all data is fresh
// For a more sophisticated approach, implement AJAX polling for individual metrics
setInterval(() => {
    // In future enhancement, replace with AJAX calls to update data without page reload
    location.reload();
}, 300000);
</script>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
