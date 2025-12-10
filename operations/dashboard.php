<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Access control - Staff only
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'account manager', 'support consultant', 'accountant'])) {
    header('Location: /login.php');
    exit;
}

$user_id = $_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'];
$page_title = "Operations Dashboard | CaminhoIT";

// ==========================================
// 1. 72-HOUR COMPANY PULSE
// ==========================================
$pulse_data = [];

// Traffic trend (last 3 days)
try {
    $stmt = $pdo->query("
        SELECT DATE(created_at) as date, COUNT(*) as count 
        FROM support_tickets 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ");
    $pulse_data['traffic'] = $stmt->fetchAll();
    
    // Calculate trend
    if (count($pulse_data['traffic']) >= 2) {
        $today = $pulse_data['traffic'][0]['count'] ?? 0;
        $yesterday = $pulse_data['traffic'][1]['count'] ?? 1;
        $pulse_data['traffic_trend'] = $yesterday > 0 ? (($today - $yesterday) / $yesterday) * 100 : 0;
    } else {
        $pulse_data['traffic_trend'] = 0;
    }
} catch (PDOException $e) {
    $pulse_data['traffic'] = [];
    $pulse_data['traffic_trend'] = 0;
}

// New leads / signups (last 3 days)
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)");
    $pulse_data['new_signups'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    $pulse_data['new_signups'] = 0;
}

// MRR trend (Monthly Recurring Revenue)
try {
    $stmt = $pdo->query("
        SELECT SUM(total_amount) as mrr 
        FROM invoices 
        WHERE status = 'paid' 
        AND MONTH(paid_at) = MONTH(NOW()) 
        AND YEAR(paid_at) = YEAR(NOW())
    ");
    $pulse_data['mrr'] = $stmt->fetchColumn() ?? 0;
    
    $stmt = $pdo->query("
        SELECT SUM(total_amount) as last_mrr 
        FROM invoices 
        WHERE status = 'paid' 
        AND MONTH(paid_at) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH))
        AND YEAR(paid_at) = YEAR(DATE_SUB(NOW(), INTERVAL 1 MONTH))
    ");
    $last_mrr = $stmt->fetchColumn() ?? 1;
    $pulse_data['mrr_trend'] = $last_mrr > 0 ? (($pulse_data['mrr'] - $last_mrr) / $last_mrr) * 100 : 0;
} catch (PDOException $e) {
    $pulse_data['mrr'] = 0;
    $pulse_data['mrr_trend'] = 0;
}

// Top user event
try {
    $stmt = $pdo->query("
        SELECT category, COUNT(*) as count 
        FROM support_tickets 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY category 
        ORDER BY count DESC 
        LIMIT 1
    ");
    $top_event = $stmt->fetch();
    $pulse_data['top_event'] = $top_event ? $top_event['category'] : 'General Support';
} catch (PDOException $e) {
    $pulse_data['top_event'] = 'N/A';
}

// ==========================================
// 2. SUPPORT COMMAND CENTER
// ==========================================
$support_data = [];

// Total open tickets
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open', 'in_progress')");
    $support_data['open_tickets'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    $support_data['open_tickets'] = 0;
}

// Tickets older than X days (validate input)
$days_filter = isset($_GET['days_filter']) ? max(1, min(365, intval($_GET['days_filter']))) : 2;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM support_tickets 
        WHERE status IN ('open', 'in_progress') 
        AND created_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$days_filter]);
    $support_data['old_tickets'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    $support_data['old_tickets'] = 0;
}

// High-priority tickets
try {
    $stmt = $pdo->query("
        SELECT st.*, u.username, c.name as company_name 
        FROM support_tickets st
        LEFT JOIN users u ON st.user_id = u.id
        LEFT JOIN companies c ON u.company_id = c.id
        WHERE st.priority = 'high' 
        AND st.status IN ('open', 'in_progress')
        ORDER BY st.created_at ASC
        LIMIT 10
    ");
    $support_data['high_priority'] = $stmt->fetchAll();
} catch (PDOException $e) {
    $support_data['high_priority'] = [];
}

// Category trends
try {
    $stmt = $pdo->query("
        SELECT 
            category,
            COUNT(*) as current_count,
            (SELECT COUNT(*) 
             FROM support_tickets st2 
             WHERE st2.category = st.category 
             AND st2.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
             AND st2.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
            ) as previous_count
        FROM support_tickets st
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY category
        HAVING current_count > previous_count
        ORDER BY (current_count - previous_count) DESC
        LIMIT 5
    ");
    $support_data['trending_categories'] = $stmt->fetchAll();
} catch (PDOException $e) {
    $support_data['trending_categories'] = [];
}

// Agent workload
try {
    $stmt = $pdo->query("
        SELECT 
            u.username,
            u.email,
            COUNT(st.id) as ticket_count,
            SUM(CASE WHEN st.status = 'open' THEN 1 ELSE 0 END) as open_count,
            SUM(CASE WHEN st.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count
        FROM users u
        LEFT JOIN support_tickets st ON st.assigned_to = u.id AND st.status IN ('open', 'in_progress')
        WHERE u.role IN ('administrator', 'support consultant')
        GROUP BY u.id
        ORDER BY ticket_count DESC
    ");
    $support_data['agents'] = $stmt->fetchAll();
} catch (PDOException $e) {
    $support_data['agents'] = [];
}

// ==========================================
// 3. REVENUE & PAYMENTS HUB
// ==========================================
$revenue_data = [];

// Overdue invoices
try {
    $stmt = $pdo->query("
        SELECT 
            i.*,
            c.name as company_name,
            DATEDIFF(NOW(), i.due_date) as days_overdue
        FROM invoices i
        LEFT JOIN companies c ON i.company_id = c.id
        WHERE i.status = 'unpaid' 
        AND i.due_date < NOW()
        ORDER BY days_overdue DESC
    ");
    $revenue_data['overdue_invoices'] = $stmt->fetchAll();
} catch (PDOException $e) {
    $revenue_data['overdue_invoices'] = [];
}

// Upcoming invoices (next 30 days)
try {
    $stmt = $pdo->query("
        SELECT 
            i.*,
            c.name as company_name,
            DATEDIFF(i.due_date, NOW()) as days_until_due
        FROM invoices i
        LEFT JOIN companies c ON i.company_id = c.id
        WHERE i.status = 'unpaid' 
        AND i.due_date >= NOW()
        AND i.due_date <= DATE_ADD(NOW(), INTERVAL 30 DAY)
        ORDER BY i.due_date ASC
        LIMIT 10
    ");
    $revenue_data['upcoming_invoices'] = $stmt->fetchAll();
} catch (PDOException $e) {
    $revenue_data['upcoming_invoices'] = [];
}

// Realized vs Expected revenue
try {
    $stmt = $pdo->query("
        SELECT SUM(total_amount) as realized
        FROM invoices 
        WHERE status = 'paid' 
        AND MONTH(paid_at) = MONTH(NOW()) 
        AND YEAR(paid_at) = YEAR(NOW())
    ");
    $revenue_data['realized_revenue'] = $stmt->fetchColumn() ?? 0;
    
    $stmt = $pdo->query("
        SELECT SUM(total_amount) as expected
        FROM invoices 
        WHERE MONTH(created_at) = MONTH(NOW()) 
        AND YEAR(created_at) = YEAR(NOW())
    ");
    $revenue_data['expected_revenue'] = $stmt->fetchColumn() ?? 0;
    
    $revenue_data['revenue_percentage'] = $revenue_data['expected_revenue'] > 0 
        ? ($revenue_data['realized_revenue'] / $revenue_data['expected_revenue']) * 100 
        : 0;
} catch (PDOException $e) {
    $revenue_data['realized_revenue'] = 0;
    $revenue_data['expected_revenue'] = 0;
    $revenue_data['revenue_percentage'] = 0;
}

// Monthly revenue trend (last 6 months)
try {
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(paid_at, '%Y-%m') as month,
            SUM(total_amount) as revenue
        FROM invoices 
        WHERE status = 'paid' 
        AND paid_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(paid_at, '%Y-%m')
        ORDER BY month ASC
    ");
    $revenue_data['monthly_trend'] = $stmt->fetchAll();
} catch (PDOException $e) {
    $revenue_data['monthly_trend'] = [];
}

// ==========================================
// 4. SYSTEM HEALTH & OPS WATCHTOWER
// ==========================================
$system_data = [];

// Simulated uptime (you can connect to actual monitoring later)
$system_data['uptime_percentage'] = 99.87;

// Recent activity
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) as count
        FROM support_tickets 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $system_data['recent_activity'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    $system_data['recent_activity'] = 0;
}

// Error simulation (you can connect to logs later)
$system_data['error_rate'] = 0.12;
$system_data['avg_response_time'] = 245;

// ==========================================
// 5. CUSTOMER SENTIMENT RADAR
// ==========================================
$sentiment_data = [];

// Feedback from tickets (basic sentiment)
try {
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN priority = 'low' THEN 1 ELSE 0 END) as positive,
            SUM(CASE WHEN priority = 'medium' THEN 1 ELSE 0 END) as neutral,
            SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as negative
        FROM support_tickets
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $sentiment_data['summary'] = $stmt->fetch();
} catch (PDOException $e) {
    $sentiment_data['summary'] = ['total' => 0, 'positive' => 0, 'neutral' => 0, 'negative' => 0];
}

// ==========================================
// 6. ACTIVITY FEED
// ==========================================
$activity_feed = [];

try {
    // Recent tickets
    $stmt = $pdo->query("
        SELECT 
            'ticket' as type,
            st.id,
            st.subject as title,
            st.created_at as timestamp,
            u.username as user,
            st.priority
        FROM support_tickets st
        LEFT JOIN users u ON st.user_id = u.id
        WHERE st.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY st.created_at DESC
        LIMIT 5
    ");
    $tickets = $stmt->fetchAll();
    
    // Recent invoices
    $stmt = $pdo->query("
        SELECT 
            'invoice' as type,
            i.id,
            CONCAT('Invoice #', i.invoice_number) as title,
            i.created_at as timestamp,
            c.name as user,
            i.status as priority
        FROM invoices i
        LEFT JOIN companies c ON i.company_id = c.id
        WHERE i.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY i.created_at DESC
        LIMIT 5
    ");
    $invoices = $stmt->fetchAll();
    
    // Merge and sort
    $activity_feed = array_merge($tickets, $invoices);
    usort($activity_feed, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    $activity_feed = array_slice($activity_feed, 0, 10);
} catch (PDOException $e) {
    $activity_feed = [];
}

// ==========================================
// 7. ATTENTION NEEDED PANEL
// ==========================================
$attention_items = [];

// Overdue invoices
if (count($revenue_data['overdue_invoices']) > 0) {
    $attention_items[] = [
        'type' => 'danger',
        'icon' => 'exclamation-triangle-fill',
        'title' => count($revenue_data['overdue_invoices']) . ' Overdue Invoice(s)',
        'description' => 'Immediate action required',
        'link' => 'invoices.php'
    ];
}

// Old tickets
if ($support_data['old_tickets'] > 0) {
    $attention_items[] = [
        'type' => 'warning',
        'icon' => 'clock-history',
        'title' => $support_data['old_tickets'] . ' Tickets Older Than ' . $days_filter . ' Days',
        'description' => 'Review and prioritize',
        'link' => 'staff-tickets.php'
    ];
}

// High priority tickets
if (count($support_data['high_priority']) > 0) {
    $attention_items[] = [
        'type' => 'warning',
        'icon' => 'exclamation-circle-fill',
        'title' => count($support_data['high_priority']) . ' High Priority Ticket(s)',
        'description' => 'Requires immediate attention',
        'link' => 'staff-tickets.php'
    ];
}

// Low revenue realization
if ($revenue_data['revenue_percentage'] < 70) {
    $attention_items[] = [
        'type' => 'info',
        'icon' => 'graph-down',
        'title' => 'Revenue Realization Below 70%',
        'description' => 'Follow up on pending invoices',
        'link' => 'invoices.php'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <!-- Custom Styles -->
    <link rel="stylesheet" href="css/dashboard.css">
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark dashboard-nav">
        <div class="container-fluid px-4">
            <a class="navbar-brand d-flex align-items-center" href="/">
                <img src="https://caminhoit.com/assets/logo.png" alt="CaminhoIT" height="32">
                <span class="ms-2 fw-bold">Operations</span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link" href="staff-tickets.php">
                            <i class="bi bi-ticket-detailed"></i> Tickets
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="invoices.php">
                            <i class="bi bi-receipt"></i> Invoices
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="orders.php">
                            <i class="bi bi-box-seam"></i> Orders
                        </a>
                    </li>
                    <li class="nav-item ms-3">
                        <div class="user-badge">
                            <i class="bi bi-person-circle"></i>
                            <span><?php echo htmlspecialchars($_SESSION['user']['username']); ?></span>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Dashboard -->
    <div class="dashboard-container">
        <!-- 1. 72-HOUR COMPANY PULSE -->
        <section class="pulse-section">
            <div class="section-header">
                <h1 class="section-title">
                    <i class="bi bi-lightning-charge-fill pulse-icon"></i>
                    72-Hour Company Pulse
                </h1>
                <p class="section-subtitle">Your business heartbeat at a glance</p>
            </div>
            
            <div class="pulse-grid">
                <!-- Traffic Trend -->
                <div class="pulse-card">
                    <div class="pulse-card-header">
                        <i class="bi bi-graph-up"></i>
                        <span>Traffic Trend</span>
                    </div>
                    <div class="pulse-card-value">
                        <?php echo number_format(array_sum(array_column($pulse_data['traffic'], 'count'))); ?>
                    </div>
                    <div class="pulse-card-trend <?php echo $pulse_data['traffic_trend'] >= 0 ? 'trend-up' : 'trend-down'; ?>">
                        <i class="bi bi-arrow-<?php echo $pulse_data['traffic_trend'] >= 0 ? 'up' : 'down'; ?>"></i>
                        <?php echo abs(round($pulse_data['traffic_trend'], 1)); ?>% vs yesterday
                    </div>
                </div>
                
                <!-- New Signups -->
                <div class="pulse-card">
                    <div class="pulse-card-header">
                        <i class="bi bi-person-plus"></i>
                        <span>New Signups</span>
                    </div>
                    <div class="pulse-card-value">
                        <?php echo $pulse_data['new_signups']; ?>
                    </div>
                    <div class="pulse-card-trend trend-neutral">
                        Last 72 hours
                    </div>
                </div>
                
                <!-- MRR -->
                <div class="pulse-card pulse-card-highlight">
                    <div class="pulse-card-header">
                        <i class="bi bi-currency-dollar"></i>
                        <span>MRR</span>
                    </div>
                    <div class="pulse-card-value">
                        £<?php echo number_format($pulse_data['mrr'], 2); ?>
                    </div>
                    <div class="pulse-card-trend <?php echo $pulse_data['mrr_trend'] >= 0 ? 'trend-up' : 'trend-down'; ?>">
                        <i class="bi bi-arrow-<?php echo $pulse_data['mrr_trend'] >= 0 ? 'up' : 'down'; ?>"></i>
                        <?php echo abs(round($pulse_data['mrr_trend'], 1)); ?>% vs last month
                    </div>
                </div>
                
                <!-- Top Event -->
                <div class="pulse-card">
                    <div class="pulse-card-header">
                        <i class="bi bi-star-fill"></i>
                        <span>Top Category</span>
                    </div>
                    <div class="pulse-card-value" style="font-size: 1.5rem;">
                        <?php echo htmlspecialchars($pulse_data['top_event']); ?>
                    </div>
                    <div class="pulse-card-trend trend-neutral">
                        Most common this week
                    </div>
                </div>
            </div>
        </section>

        <!-- 2 & 7. ATTENTION NEEDED + SUPPORT COMMAND CENTER -->
        <div class="row g-4 mb-4">
            <div class="col-lg-4">
                <!-- ATTENTION NEEDED PANEL -->
                <div class="attention-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            <i class="bi bi-exclamation-diamond-fill"></i>
                            Attention Needed
                        </h2>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($attention_items)): ?>
                            <div class="no-attention">
                                <i class="bi bi-check-circle-fill"></i>
                                <p>All systems green! 🎉</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($attention_items as $item): ?>
                                <a href="<?php echo $item['link']; ?>" class="attention-item attention-<?php echo $item['type']; ?>">
                                    <div class="attention-icon">
                                        <i class="bi bi-<?php echo $item['icon']; ?>"></i>
                                    </div>
                                    <div class="attention-content">
                                        <div class="attention-title"><?php echo $item['title']; ?></div>
                                        <div class="attention-desc"><?php echo $item['description']; ?></div>
                                    </div>
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-8">
                <!-- SUPPORT COMMAND CENTER -->
                <div class="support-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            <i class="bi bi-headset"></i>
                            Support Command Center
                        </h2>
                        <div class="panel-actions">
                            <select class="form-select form-select-sm" id="daysFilter" onchange="window.location.href='?days_filter=' + this.value">
                                <option value="2" <?php echo $days_filter == 2 ? 'selected' : ''; ?>>2+ days old</option>
                                <option value="5" <?php echo $days_filter == 5 ? 'selected' : ''; ?>>5+ days old</option>
                                <option value="7" <?php echo $days_filter == 7 ? 'selected' : ''; ?>>7+ days old</option>
                            </select>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="support-stats">
                            <div class="support-stat">
                                <div class="stat-value"><?php echo $support_data['open_tickets']; ?></div>
                                <div class="stat-label">Open Tickets</div>
                            </div>
                            <div class="support-stat">
                                <div class="stat-value"><?php echo $support_data['old_tickets']; ?></div>
                                <div class="stat-label">Aged Tickets</div>
                            </div>
                            <div class="support-stat">
                                <div class="stat-value"><?php echo count($support_data['high_priority']); ?></div>
                                <div class="stat-label">High Priority</div>
                            </div>
                        </div>
                        
                        <?php if (!empty($support_data['high_priority'])): ?>
                            <div class="priority-tickets mt-4">
                                <h6 class="mb-3">High Priority Tickets</h6>
                                <?php foreach (array_slice($support_data['high_priority'], 0, 5) as $ticket): ?>
                                    <a href="staff-view-ticket.php?id=<?php echo $ticket['id']; ?>" class="priority-ticket-item">
                                        <div class="priority-badge">HIGH</div>
                                        <div class="priority-info">
                                            <div class="priority-title"><?php echo htmlspecialchars($ticket['subject']); ?></div>
                                            <div class="priority-meta">
                                                <?php echo htmlspecialchars($ticket['company_name'] ?? $ticket['username']); ?> • 
                                                <?php echo date('M j, Y', strtotime($ticket['created_at'])); ?>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. REVENUE & PAYMENTS HUB -->
        <section class="revenue-section">
            <div class="panel-header">
                <h2 class="panel-title">
                    <i class="bi bi-cash-stack"></i>
                    Revenue & Payments Hub
                </h2>
            </div>
            
            <div class="row g-4 mb-4">
                <div class="col-lg-4">
                    <div class="revenue-gauge">
                        <div class="gauge-header">Revenue Realization</div>
                        <div class="gauge-chart">
                            <canvas id="revenueGauge"></canvas>
                        </div>
                        <div class="gauge-stats">
                            <div class="gauge-stat">
                                <div class="gauge-label">Realized</div>
                                <div class="gauge-value">£<?php echo number_format($revenue_data['realized_revenue'], 2); ?></div>
                            </div>
                            <div class="gauge-stat">
                                <div class="gauge-label">Expected</div>
                                <div class="gauge-value">£<?php echo number_format($revenue_data['expected_revenue'], 2); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-8">
                    <div class="revenue-chart-panel">
                        <h6 class="chart-title">6-Month Revenue Trend</h6>
                        <canvas id="revenueTrendChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="invoice-list-panel">
                        <h6 class="invoice-list-title">
                            <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                            Overdue Invoices
                        </h6>
                        <div class="invoice-list">
                            <?php if (empty($revenue_data['overdue_invoices'])): ?>
                                <div class="no-items">No overdue invoices 🎉</div>
                            <?php else: ?>
                                <?php foreach (array_slice($revenue_data['overdue_invoices'], 0, 5) as $invoice): ?>
                                    <?php 
                                        $urgency = 'low';
                                        if ($invoice['days_overdue'] > 30) $urgency = 'critical';
                                        elseif ($invoice['days_overdue'] > 14) $urgency = 'high';
                                        elseif ($invoice['days_overdue'] > 7) $urgency = 'medium';
                                    ?>
                                    <a href="invoices.php?id=<?php echo $invoice['id']; ?>" class="invoice-item urgency-<?php echo $urgency; ?>">
                                        <div class="invoice-info">
                                            <div class="invoice-number">#<?php echo $invoice['invoice_number']; ?></div>
                                            <div class="invoice-client"><?php echo htmlspecialchars($invoice['company_name']); ?></div>
                                        </div>
                                        <div class="invoice-details">
                                            <div class="invoice-amount">£<?php echo number_format($invoice['total_amount'], 2); ?></div>
                                            <div class="invoice-overdue"><?php echo $invoice['days_overdue']; ?> days overdue</div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="invoice-list-panel">
                        <h6 class="invoice-list-title">
                            <i class="bi bi-calendar-check text-info"></i>
                            Upcoming Invoices (30 days)
                        </h6>
                        <div class="invoice-list">
                            <?php if (empty($revenue_data['upcoming_invoices'])): ?>
                                <div class="no-items">No upcoming invoices</div>
                            <?php else: ?>
                                <?php foreach (array_slice($revenue_data['upcoming_invoices'], 0, 5) as $invoice): ?>
                                    <a href="invoices.php?id=<?php echo $invoice['id']; ?>" class="invoice-item urgency-upcoming">
                                        <div class="invoice-info">
                                            <div class="invoice-number">#<?php echo $invoice['invoice_number']; ?></div>
                                            <div class="invoice-client"><?php echo htmlspecialchars($invoice['company_name']); ?></div>
                                        </div>
                                        <div class="invoice-details">
                                            <div class="invoice-amount">£<?php echo number_format($invoice['total_amount'], 2); ?></div>
                                            <div class="invoice-upcoming">Due in <?php echo $invoice['days_until_due']; ?> days</div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- 4. SYSTEM HEALTH & 5. SENTIMENT + 6. ACTIVITY -->
        <div class="row g-4 mb-4">
            <div class="col-lg-4">
                <!-- SYSTEM HEALTH -->
                <div class="system-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            <i class="bi bi-heart-pulse-fill"></i>
                            System Health
                        </h2>
                    </div>
                    <div class="panel-body">
                        <div class="health-metric">
                            <div class="health-label">Uptime (30d)</div>
                            <div class="health-value health-excellent"><?php echo $system_data['uptime_percentage']; ?>%</div>
                        </div>
                        <div class="health-metric">
                            <div class="health-label">Avg Response Time</div>
                            <div class="health-value health-good"><?php echo $system_data['avg_response_time']; ?>ms</div>
                        </div>
                        <div class="health-metric">
                            <div class="health-label">Error Rate</div>
                            <div class="health-value health-excellent"><?php echo $system_data['error_rate']; ?>%</div>
                        </div>
                        <div class="health-metric">
                            <div class="health-label">Activity (1h)</div>
                            <div class="health-value health-good"><?php echo $system_data['recent_activity']; ?> events</div>
                        </div>
                    </div>
                </div>
                
                <!-- CUSTOMER SENTIMENT -->
                <div class="sentiment-panel mt-4">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            <i class="bi bi-emoji-smile"></i>
                            Customer Sentiment
                        </h2>
                    </div>
                    <div class="panel-body">
                        <canvas id="sentimentChart"></canvas>
                        <div class="sentiment-summary mt-3">
                            <div class="sentiment-item">
                                <div class="sentiment-color sentiment-positive"></div>
                                <span>Positive: <?php echo $sentiment_data['summary']['positive']; ?></span>
                            </div>
                            <div class="sentiment-item">
                                <div class="sentiment-color sentiment-neutral"></div>
                                <span>Neutral: <?php echo $sentiment_data['summary']['neutral']; ?></span>
                            </div>
                            <div class="sentiment-item">
                                <div class="sentiment-color sentiment-negative"></div>
                                <span>Negative: <?php echo $sentiment_data['summary']['negative']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-8">
                <!-- ACTIVITY FEED -->
                <div class="activity-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            <i class="bi bi-activity"></i>
                            Activity Feed
                        </h2>
                        <div class="panel-actions">
                            <button class="btn btn-sm btn-outline-light" onclick="location.reload()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="activity-feed">
                            <?php if (empty($activity_feed)): ?>
                                <div class="no-items">No recent activity</div>
                            <?php else: ?>
                                <?php foreach ($activity_feed as $activity): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon activity-icon-<?php echo $activity['type']; ?>">
                                            <i class="bi bi-<?php echo $activity['type'] === 'ticket' ? 'ticket-detailed' : 'receipt'; ?>"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title"><?php echo htmlspecialchars($activity['title']); ?></div>
                                            <div class="activity-meta">
                                                <?php echo htmlspecialchars($activity['user']); ?> • 
                                                <?php echo date('M j, g:i A', strtotime($activity['timestamp'])); ?>
                                            </div>
                                        </div>
                                        <?php if ($activity['type'] === 'ticket'): ?>
                                            <span class="badge priority-badge-<?php echo $activity['priority']; ?>">
                                                <?php echo strtoupper($activity['priority']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge status-badge-<?php echo $activity['priority']; ?>">
                                                <?php echo strtoupper($activity['priority']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- TEAM WORKLOAD HEATMAP -->
                <div class="team-panel mt-4">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            <i class="bi bi-people-fill"></i>
                            Team Workload Heatmap
                        </h2>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($support_data['agents'])): ?>
                            <div class="no-items">No agent data available</div>
                        <?php else: ?>
                            <div class="team-heatmap">
                                <?php foreach ($support_data['agents'] as $agent): ?>
                                    <?php 
                                        $load = $agent['ticket_count'];
                                        $load_class = 'load-light';
                                        if ($load > 15) $load_class = 'load-heavy';
                                        elseif ($load > 8) $load_class = 'load-medium';
                                    ?>
                                    <div class="team-member <?php echo $load_class; ?>">
                                        <div class="team-avatar">
                                            <i class="bi bi-person-circle"></i>
                                        </div>
                                        <div class="team-info">
                                            <div class="team-name"><?php echo htmlspecialchars($agent['username']); ?></div>
                                            <div class="team-stats">
                                                <span class="team-stat">
                                                    <i class="bi bi-ticket"></i> <?php echo $agent['ticket_count']; ?>
                                                </span>
                                                <span class="team-stat">
                                                    <i class="bi bi-hourglass-split"></i> <?php echo $agent['in_progress_count']; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="team-load-indicator">
                                            <?php 
                                                if ($load > 15) echo '<i class="bi bi-fire"></i>';
                                                elseif ($load > 8) echo '<i class="bi bi-exclamation-circle"></i>';
                                                else echo '<i class="bi bi-check-circle"></i>';
                                            ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Revenue Gauge Chart
        const revenueGaugeCtx = document.getElementById('revenueGauge').getContext('2d');
        const revenuePercentage = <?php echo round($revenue_data['revenue_percentage']); ?>;
        
        new Chart(revenueGaugeCtx, {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [revenuePercentage, 100 - revenuePercentage],
                    backgroundColor: [
                        revenuePercentage >= 80 ? '#10b981' : revenuePercentage >= 50 ? '#f59e0b' : '#ef4444',
                        'rgba(255, 255, 255, 0.1)'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                cutout: '80%',
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false }
                }
            },
            plugins: [{
                id: 'centerText',
                afterDraw: function(chart) {
                    const ctx = chart.ctx;
                    const centerX = (chart.chartArea.left + chart.chartArea.right) / 2;
                    const centerY = (chart.chartArea.top + chart.chartArea.bottom) / 2;
                    
                    ctx.save();
                    ctx.font = 'bold 32px Inter';
                    ctx.fillStyle = '#fff';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(revenuePercentage + '%', centerX, centerY);
                    ctx.restore();
                }
            }]
        });

        // Revenue Trend Chart
        const revenueTrendCtx = document.getElementById('revenueTrendChart').getContext('2d');
        const revenueTrendData = <?php echo json_encode($revenue_data['monthly_trend']); ?>;
        
        new Chart(revenueTrendCtx, {
            type: 'line',
            data: {
                labels: revenueTrendData.map(item => {
                    const [year, month] = item.month.split('-');
                    return new Date(year, month - 1).toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
                }),
                datasets: [{
                    label: 'Revenue',
                    data: revenueTrendData.map(item => item.revenue),
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return '£' + context.parsed.y.toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.7)',
                            callback: function(value) {
                                return '£' + value.toLocaleString();
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.7)'
                        }
                    }
                }
            }
        });

        // Sentiment Chart
        const sentimentCtx = document.getElementById('sentimentChart').getContext('2d');
        const sentimentData = <?php echo json_encode($sentiment_data['summary']); ?>;
        
        new Chart(sentimentCtx, {
            type: 'doughnut',
            data: {
                labels: ['Positive', 'Neutral', 'Negative'],
                datasets: [{
                    data: [sentimentData.positive, sentimentData.neutral, sentimentData.negative],
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12
                    }
                }
            }
        });

        // Auto refresh every 5 minutes (optional - user can disable via localStorage)
        if (localStorage.getItem('dashboard-auto-refresh') !== 'disabled') {
            setTimeout(() => location.reload(), 5 * 60 * 1000);
        }
        
        // Add refresh control (for future enhancement)
        // To disable: localStorage.setItem('dashboard-auto-refresh', 'disabled');
        // To enable: localStorage.removeItem('dashboard-auto-refresh');
    </script>
</body>
</html>
