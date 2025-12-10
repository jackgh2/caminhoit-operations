<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /login.php');
    exit;
}

// Check if user is staff/admin
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_role = $stmt->fetchColumn();

if (!in_array($user_role, ['administrator', 'support_user', 'support_technician', 'accountant'])) {
    header('Location: /dashboard.php');
    exit;
}

// Get filters from URL parameters
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$filter_user = $_GET['filter_user'] ?? '';
$filter_company = $_GET['filter_company'] ?? '';
$filter_agent = $_GET['filter_agent'] ?? '';
$filter_category = $_GET['filter_category'] ?? '';

// Build dynamic WHERE clause for filters
$where_conditions = ["DATE(t.created_at) BETWEEN ? AND ?"];
$params = [$start_date, $end_date];

if ($filter_user) {
    $where_conditions[] = "t.user_id = ?";
    $params[] = $filter_user;
}

if ($filter_company) {
    $where_conditions[] = "u.company_id = ?";
    $params[] = $filter_company;
}

if ($filter_agent) {
    $where_conditions[] = "t.assigned_to = ?";
    $params[] = $filter_agent;
}

if ($filter_category) {
    $where_conditions[] = "t.group_id = ?";
    $params[] = $filter_category;
}

$where_clause = implode(' AND ', $where_conditions);

// Get filter options for dropdowns
$stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE role NOT IN ('administrator', 'support_user', 'support_technician', 'accountant') ORDER BY username");
$stmt->execute();
$all_users = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name FROM companies ORDER BY name");
$stmt->execute();
$all_companies = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, username FROM users WHERE role IN ('administrator', 'support_user', 'support_technician', 'accountant') ORDER BY username");
$stmt->execute();
$all_agents = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name FROM support_ticket_groups ORDER BY name");
$stmt->execute();
$all_categories = $stmt->fetchAll();

// Basic ticket counts with filters
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_tickets,
        SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) as open_tickets,
        SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_tickets,
        SUM(CASE WHEN t.status = 'Pending' THEN 1 ELSE 0 END) as pending_tickets,
        SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) as closed_tickets
    FROM support_tickets t
    LEFT JOIN users u ON t.user_id = u.id
    WHERE $where_clause
");
$stmt->execute($params);
$ticket_counts = $stmt->fetch();

// Enhanced priority breakdown with P1, P2, P3, P4 labels
$stmt = $pdo->prepare("
    SELECT 
        CASE 
            WHEN t.priority = 'High' THEN 'P1'
            WHEN t.priority = 'Medium' THEN 'P2'
            WHEN t.priority = 'Normal' OR t.priority IS NULL THEN 'P3'
            WHEN t.priority = 'Low' THEN 'P4'
            ELSE 'P3'
        END as priority_label,
        t.priority as original_priority,
        COUNT(*) as count,
        SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) as open_count,
        SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_count,
        SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) as closed_count
    FROM support_tickets t
    LEFT JOIN users u ON t.user_id = u.id
    WHERE $where_clause
    GROUP BY t.priority, priority_label
    ORDER BY FIELD(t.priority, 'High', 'Medium', 'Normal', 'Low'), t.priority IS NULL
");
$stmt->execute($params);
$priority_breakdown = $stmt->fetchAll();

// Average response time
$stmt = $pdo->prepare("
    SELECT 
        AVG(TIMESTAMPDIFF(MINUTE, t.created_at, first_reply.created_at)) as avg_response_minutes,
        COUNT(*) as tickets_with_replies
    FROM support_tickets t
    LEFT JOIN users u ON t.user_id = u.id
    JOIN (
        SELECT 
            r.ticket_id,
            MIN(r.created_at) as created_at
        FROM support_ticket_replies r
        JOIN users ur ON r.user_id = ur.id
        WHERE ur.role IN ('administrator', 'support_user', 'support_technician', 'accountant')
        GROUP BY r.ticket_id
    ) first_reply ON t.id = first_reply.ticket_id
    WHERE $where_clause
");
$stmt->execute($params);
$response_time = $stmt->fetch();

// Average resolution time
$stmt = $pdo->prepare("
    SELECT 
        AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)) as avg_resolution_hours,
        COUNT(*) as resolved_tickets
    FROM support_tickets t
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.status = 'Closed' AND $where_clause
");
$stmt->execute($params);
$resolution_time = $stmt->fetch();

// Top ticket creators (drill-down ready)
$stmt = $pdo->prepare("
    SELECT 
        u.id,
        u.username,
        u.email,
        c.name as company_name,
        COUNT(t.id) as ticket_count,
        SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) as open_count,
        SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) as closed_count
    FROM users u
    LEFT JOIN support_tickets t ON u.id = t.user_id AND DATE(t.created_at) BETWEEN ? AND ?
    LEFT JOIN companies c ON u.company_id = c.id
    WHERE u.role NOT IN ('administrator', 'support_user', 'support_technician', 'accountant')
    " . ($filter_company ? " AND u.company_id = ?" : "") . "
    GROUP BY u.id, u.username, u.email, c.name
    HAVING ticket_count > 0
    ORDER BY ticket_count DESC
    LIMIT 15
");
$top_creators_params = [$start_date, $end_date];
if ($filter_company) {
    $top_creators_params[] = $filter_company;
}
$stmt->execute($top_creators_params);
$top_ticket_creators = $stmt->fetchAll();

// Agent performance
$stmt = $pdo->prepare("
    SELECT 
        u.id,
        u.username,
        u.email,
        COUNT(t.id) as assigned_count,
        SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) as closed_count,
        SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) as open_count,
        SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_count,
        AVG(CASE WHEN t.status = 'Closed' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) END) as avg_resolution_hours
    FROM users u
    LEFT JOIN support_tickets t ON u.id = t.assigned_to AND DATE(t.created_at) BETWEEN ? AND ?
    WHERE u.role IN ('administrator', 'support_user', 'support_technician', 'accountant')
    GROUP BY u.id, u.username, u.email
    HAVING assigned_count > 0
    ORDER BY assigned_count DESC
    LIMIT 10
");
$stmt->execute([$start_date, $end_date]);
$agent_performance = $stmt->fetchAll();

// Company performance
$stmt = $pdo->prepare("
    SELECT 
        c.id,
        c.name as company_name,
        COUNT(t.id) as ticket_count,
        SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) as closed_count,
        SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) as open_count,
        AVG(CASE WHEN t.status = 'Closed' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) END) as avg_resolution_hours
    FROM companies c
    LEFT JOIN users u ON c.id = u.company_id
    LEFT JOIN support_tickets t ON u.id = t.user_id AND DATE(t.created_at) BETWEEN ? AND ?
    WHERE c.name IS NOT NULL
    GROUP BY c.id, c.name
    HAVING ticket_count > 0
    ORDER BY ticket_count DESC
    LIMIT 15
");
$stmt->execute([$start_date, $end_date]);
$company_performance = $stmt->fetchAll();

// Category breakdown
$stmt = $pdo->prepare("
    SELECT 
        tg.id,
        COALESCE(tg.name, 'No Category') as category,
        COUNT(t.id) as count,
        SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) as open_count,
        SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) as closed_count
    FROM support_tickets t
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN support_ticket_groups tg ON t.group_id = tg.id
    WHERE $where_clause
    GROUP BY tg.id, tg.name
    ORDER BY count DESC
");
$stmt->execute($params);
$category_breakdown = $stmt->fetchAll();

// Daily trend
$stmt = $pdo->prepare("
    SELECT 
        DATE(t.created_at) as date,
        COUNT(*) as count
    FROM support_tickets t
    LEFT JOIN users u ON t.user_id = u.id
    WHERE $where_clause
    GROUP BY DATE(t.created_at)
    ORDER BY date ASC
");
$stmt->execute($params);
$daily_trend = $stmt->fetchAll();

// Helper functions
function formatDuration($minutes) {
    if ($minutes < 60) {
        return round($minutes) . ' min';
    } elseif ($minutes < 1440) {
        return round($minutes / 60, 1) . ' hrs';
    } else {
        return round($minutes / 1440, 1) . ' days';
    }
}

function formatHours($hours) {
    if ($hours < 24) {
        return round($hours, 1) . ' hrs';
    } else {
        return round($hours / 24, 1) . ' days';
    }
}

function getPriorityColor($priority) {
    switch ($priority) {
        case 'P1':
        case 'High':
            return '#EF4444';
        case 'P2':
        case 'Medium':
            return '#F59E0B';
        case 'P3':
        case 'Normal':
            return '#06B6D4';
        case 'P4':
        case 'Low':
            return '#10B981';
        default:
            return '#6B7280';
    }
}

$page_title = "Analytics Dashboard | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
        }

        body {
            background-color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding-top: 80px;
        }

        .navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15) !important;
            padding: 12px 0 !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            z-index: 1030 !important;
        }

        .navbar .navbar-brand,
        .navbar .nav-link,
        .navbar .navbar-text {
            color: white !important;
        }

        .navbar .nav-link:hover {
            color: #e0e7ff !important;
        }

        .main-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .page-header {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }

        .page-header .subtitle {
            color: var(--text-muted);
            margin-top: 0.5rem;
        }

        .filters-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .filters-section h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 1rem;
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-weight: 600;
            color: #374151;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }

        .filter-group input,
        .filter-group select {
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.2s;
            height: fit-content;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-secondary {
            background: #6b7280;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.2s;
            height: fit-content;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        .active-filters {
            margin-top: 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .filter-tag {
            background: var(--primary-color);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-tag a {
            color: white;
            text-decoration: none;
            opacity: 0.8;
        }

        .filter-tag a:hover {
            opacity: 1;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            transition: all 0.2s;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .stat-card .icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-bottom: 1rem;
        }

        .stat-card .icon.primary { background: var(--primary-color); }
        .stat-card .icon.success { background: var(--success-color); }
        .stat-card .icon.warning { background: var(--warning-color); }
        .stat-card .icon.danger { background: var(--danger-color); }
        .stat-card .icon.info { background: var(--info-color); }

        .stat-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }

        .stat-card .label {
            color: var(--text-muted);
            font-size: 0.875rem;
            font-weight: 500;
        }

        .priority-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .priority-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            text-align: center;
            transition: all 0.2s;
        }

        .priority-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .priority-card .priority-label {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .priority-card .priority-count {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
        }

        .priority-card .priority-name {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }

        .priority-breakdown {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            margin-top: 1rem;
        }

        .priority-breakdown span {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            background: #f3f4f6;
            color: #374151;
        }

        .charts-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .chart-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
        }

        .chart-card h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 1rem;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .data-tables {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 2rem;
        }

        .table-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .table-card h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .table-responsive {
            max-height: 400px;
            overflow-y: auto;
        }

        .table {
            margin: 0;
        }

        .table th {
            background: #f8fafc;
            color: #374151;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            padding: 1rem;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.875rem;
        }

        .table tbody tr:hover {
            background: #f8fafc;
        }

        .table tbody tr {
            cursor: pointer;
        }

        .badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
        }

        .badge.bg-success { background: var(--success-color) !important; }
        .badge.bg-warning { background: var(--warning-color) !important; }
        .badge.bg-danger { background: var(--danger-color) !important; }
        .badge.bg-info { background: var(--info-color) !important; }
        .badge.bg-secondary { background: var(--text-muted) !important; }

        @media (max-width: 768px) {
            .filters-form {
                grid-template-columns: 1fr;
            }

            .charts-section {
                grid-template-columns: 1fr;
            }

            .data-tables {
                grid-template-columns: 1fr;
            }

            .priority-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Dark Mode Styles */
        :root.dark body {
            background: #0f172a !important;
        }

        :root.dark .page-header {
            background: #1e293b !important;
        }

        :root.dark .page-header h1 {
            color: #f1f5f9 !important;
        }

        :root.dark .page-header .subtitle {
            color: #94a3b8 !important;
        }

        :root.dark .filters-section {
            background: #1e293b !important;
        }

        :root.dark .filters-section h3 {
            color: #f1f5f9 !important;
        }

        :root.dark .filter-group label {
            color: #cbd5e1 !important;
        }

        :root.dark .filter-group input,
        :root.dark .filter-group select {
            background: #0f172a !important;
            color: #e2e8f0 !important;
            border-color: #334155 !important;
        }

        :root.dark .stat-card {
            background: #1e293b !important;
        }

        :root.dark .stat-card .value {
            color: #f1f5f9 !important;
        }

        :root.dark .stat-card .label {
            color: #94a3b8 !important;
        }

        :root.dark .priority-card {
            background: #1e293b !important;
        }

        :root.dark .priority-card .priority-count {
            color: #f1f5f9 !important;
        }

        :root.dark .priority-card .priority-name {
            color: #94a3b8 !important;
        }

        :root.dark .priority-breakdown span {
            background: #0f172a !important;
            color: #cbd5e1 !important;
        }

        :root.dark .chart-card {
            background: #1e293b !important;
        }

        :root.dark .chart-card h3 {
            color: #f1f5f9 !important;
        }

        :root.dark .table-card {
            background: #1e293b !important;
        }

        :root.dark .table-card h3 {
            color: #f1f5f9 !important;
            border-bottom-color: #334155 !important;
        }

        :root.dark .table-card .table-responsive {
            background: #1e293b !important;
        }

        :root.dark .table {
            color: #e2e8f0 !important;
            background: #1e293b !important;
        }

        :root.dark .table thead {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
        }

        :root.dark .table th {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
            color: white !important;
        }

        :root.dark .table thead th {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
            color: white !important;
        }

        :root.dark .table tbody td {
            color: #cbd5e1 !important;
            border-color: #334155 !important;
            background: transparent !important;
        }

        :root.dark .table tbody tr {
            background: transparent !important;
        }

        :root.dark .table tbody tr:hover {
            background: #0f172a !important;
        }

        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark small.text-muted {
            color: #94a3b8 !important;
        }
</style>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <h1><i class="bi bi-graph-up me-3"></i>Analytics Dashboard</h1>
        <p class="subtitle">Comprehensive ticket analytics and performance metrics</p>
    </div>

    <!-- Filters Section -->
    <div class="filters-section">
        <h3><i class="bi bi-funnel me-2"></i>Filters & Drill-Down</h3>
        <form method="GET" class="filters-form">
            <div class="filter-group">
                <label for="start_date">Start Date</label>
                <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" required>
            </div>
            <div class="filter-group">
                <label for="end_date">End Date</label>
                <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" required>
            </div>
            <div class="filter-group">
                <label for="filter_user">Filter by User</label>
                <select id="filter_user" name="filter_user">
                    <option value="">All Users</option>
                    <?php foreach ($all_users as $user_option): ?>
                        <option value="<?= $user_option['id'] ?>" <?= $filter_user == $user_option['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user_option['username']) ?> (<?= htmlspecialchars($user_option['email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="filter_company">Filter by Company</label>
                <select id="filter_company" name="filter_company">
                    <option value="">All Companies</option>
                    <?php foreach ($all_companies as $company): ?>
                        <option value="<?= $company['id'] ?>" <?= $filter_company == $company['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($company['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="filter_agent">Filter by Agent</label>
                <select id="filter_agent" name="filter_agent">
                    <option value="">All Agents</option>
                    <?php foreach ($all_agents as $agent): ?>
                        <option value="<?= $agent['id'] ?>" <?= $filter_agent == $agent['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($agent['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="filter_category">Filter by Category</label>
                <select id="filter_category" name="filter_category">
                    <option value="">All Categories</option>
                    <?php foreach ($all_categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $filter_category == $category['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-search me-2"></i>Apply Filters
            </button>
            <a href="?" class="btn btn-secondary">
                <i class="bi bi-arrow-clockwise me-2"></i>Reset
            </a>
        </form>

        <!-- Active Filters -->
        <?php if ($filter_user || $filter_company || $filter_agent || $filter_category): ?>
            <div class="active-filters">
                <strong>Active Filters:</strong>
                <?php if ($filter_user): 
                    $selected_user = array_filter($all_users, function($u) use ($filter_user) { return $u['id'] == $filter_user; });
                    $selected_user = reset($selected_user);
                ?>
                    <span class="filter-tag">
                        User: <?= htmlspecialchars($selected_user['username']) ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['filter_user' => ''])) ?>">&times;</a>
                    </span>
                <?php endif; ?>
                <?php if ($filter_company): 
                    $selected_company = array_filter($all_companies, function($c) use ($filter_company) { return $c['id'] == $filter_company; });
                    $selected_company = reset($selected_company);
                ?>
                    <span class="filter-tag">
                        Company: <?= htmlspecialchars($selected_company['name']) ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['filter_company' => ''])) ?>">&times;</a>
                    </span>
                <?php endif; ?>
                <?php if ($filter_agent): 
                    $selected_agent = array_filter($all_agents, function($a) use ($filter_agent) { return $a['id'] == $filter_agent; });
                    $selected_agent = reset($selected_agent);
                ?>
                    <span class="filter-tag">
                        Agent: <?= htmlspecialchars($selected_agent['username']) ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['filter_agent' => ''])) ?>">&times;</a>
                    </span>
                <?php endif; ?>
                <?php if ($filter_category): 
                    $selected_category = array_filter($all_categories, function($c) use ($filter_category) { return $c['id'] == $filter_category; });
                    $selected_category = reset($selected_category);
                ?>
                    <span class="filter-tag">
                        Category: <?= htmlspecialchars($selected_category['name']) ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['filter_category' => ''])) ?>">&times;</a>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary">
                <i class="bi bi-ticket"></i>
            </div>
            <div class="value"><?= number_format($ticket_counts['total_tickets'] ?? 0) ?></div>
            <div class="label">Total Tickets</div>
        </div>

        <div class="stat-card">
            <div class="icon danger">
                <i class="bi bi-exclamation-circle"></i>
            </div>
            <div class="value"><?= number_format($ticket_counts['open_tickets'] ?? 0) ?></div>
            <div class="label">Open Tickets</div>
        </div>

        <div class="stat-card">
            <div class="icon warning">
                <i class="bi bi-arrow-clockwise"></i>
            </div>
            <div class="value"><?= number_format($ticket_counts['in_progress_tickets'] ?? 0) ?></div>
            <div class="label">In Progress</div>
        </div>

        <div class="stat-card">
            <div class="icon success">
                <i class="bi bi-check-circle"></i>
            </div>
            <div class="value"><?= number_format($ticket_counts['closed_tickets'] ?? 0) ?></div>
            <div class="label">Closed Tickets</div>
        </div>

        <div class="stat-card">
            <div class="icon info">
                <i class="bi bi-clock"></i>
            </div>
            <div class="value">
                <?= $response_time['avg_response_minutes'] ? formatDuration($response_time['avg_response_minutes']) : 'N/A' ?>
            </div>
            <div class="label">Avg Response Time</div>
        </div>

        <div class="stat-card">
            <div class="icon info">
                <i class="bi bi-hourglass-split"></i>
            </div>
            <div class="value">
                <?= $resolution_time['avg_resolution_hours'] ? formatHours($resolution_time['avg_resolution_hours']) : 'N/A' ?>
            </div>
            <div class="label">Avg Resolution Time</div>
        </div>
    </div>

    <!-- Priority Breakdown -->
    <div class="priority-grid">
        <?php foreach ($priority_breakdown as $priority): ?>
            <div class="priority-card">
                <div class="priority-label" style="color: <?= getPriorityColor($priority['priority_label']) ?>">
                    <?= $priority['priority_label'] ?>
                </div>
                <div class="priority-count" style="color: <?= getPriorityColor($priority['priority_label']) ?>">
                    <?= $priority['count'] ?>
                </div>
                <div class="priority-name">
                    <?= $priority['original_priority'] ?: 'Normal' ?> Priority
                </div>
                <div class="priority-breakdown">
                    <span title="Open">🔴 <?= $priority['open_count'] ?></span>
                    <span title="In Progress">🟡 <?= $priority['in_progress_count'] ?></span>
                    <span title="Closed">🟢 <?= $priority['closed_count'] ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts Section -->
    <div class="charts-section">
        <div class="chart-card">
            <h3>Tickets by Status</h3>
            <div class="chart-container">
                <canvas id="statusChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3>Daily Ticket Trend</h3>
            <div class="chart-container">
                <canvas id="trendChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3>Priority Distribution</h3>
            <div class="chart-container">
                <canvas id="priorityChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3>Category Distribution</h3>
            <div class="chart-container">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Data Tables -->
    <div class="data-tables">
        <div class="table-card">
            <h3>Top Ticket Creators</h3>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Company</th>
                            <th>Total</th>
                            <th>Open</th>
                            <th>Closed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_ticket_creators as $creator): ?>
                            <tr onclick="drillDownUser(<?= $creator['id'] ?>)">
                                <td>
                                    <strong><?= htmlspecialchars($creator['username']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($creator['email']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($creator['company_name'] ?? 'No Company') ?></td>
                                <td><span class="badge bg-primary"><?= $creator['ticket_count'] ?></span></td>
                                <td><span class="badge bg-danger"><?= $creator['open_count'] ?></span></td>
                                <td><span class="badge bg-success"><?= $creator['closed_count'] ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-card">
            <h3>Agent Performance</h3>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Assigned</th>
                            <th>Open</th>
                            <th>In Progress</th>
                            <th>Closed</th>
                            <th>Avg Resolution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($agent_performance as $agent): ?>
                            <tr onclick="drillDownAgent(<?= $agent['id'] ?>)">
                                <td>
                                    <strong><?= htmlspecialchars($agent['username']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($agent['email']) ?></small>
                                </td>
                                <td><span class="badge bg-primary"><?= $agent['assigned_count'] ?></span></td>
                                <td><span class="badge bg-danger"><?= $agent['open_count'] ?></span></td>
                                <td><span class="badge bg-warning"><?= $agent['in_progress_count'] ?></span></td>
                                <td><span class="badge bg-success"><?= $agent['closed_count'] ?></span></td>
                                <td><?= $agent['avg_resolution_hours'] ? formatHours($agent['avg_resolution_hours']) : 'N/A' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-card">
            <h3>Company Performance</h3>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Total</th>
                            <th>Open</th>
                            <th>Closed</th>
                            <th>Avg Resolution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($company_performance as $company): ?>
                            <tr onclick="drillDownCompany(<?= $company['id'] ?>)">
                                <td><strong><?= htmlspecialchars($company['company_name']) ?></strong></td>
                                <td><span class="badge bg-primary"><?= $company['ticket_count'] ?></span></td>
                                <td><span class="badge bg-danger"><?= $company['open_count'] ?></span></td>
                                <td><span class="badge bg-success"><?= $company['closed_count'] ?></span></td>
                                <td><?= $company['avg_resolution_hours'] ? formatHours($company['avg_resolution_hours']) : 'N/A' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Drill-down functions
function drillDownUser(userId) {
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.set('filter_user', userId);
    currentUrl.searchParams.delete('filter_company');
    currentUrl.searchParams.delete('filter_agent');
    window.location.href = currentUrl.toString();
}

function drillDownAgent(agentId) {
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.set('filter_agent', agentId);
    currentUrl.searchParams.delete('filter_user');
    currentUrl.searchParams.delete('filter_company');
    window.location.href = currentUrl.toString();
}

function drillDownCompany(companyId) {
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.set('filter_company', companyId);
    currentUrl.searchParams.delete('filter_user');
    currentUrl.searchParams.delete('filter_agent');
    window.location.href = currentUrl.toString();
}

// Chart.js configuration
Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
Chart.defaults.color = '#64748B';

// Status Chart
const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Open', 'In Progress', 'Pending', 'Closed'],
        datasets: [{
            data: [
                <?= $ticket_counts['open_tickets'] ?? 0 ?>,
                <?= $ticket_counts['in_progress_tickets'] ?? 0 ?>,
                <?= $ticket_counts['pending_tickets'] ?? 0 ?>,
                <?= $ticket_counts['closed_tickets'] ?? 0 ?>
            ],
            backgroundColor: ['#EF4444', '#F59E0B', '#06B6D4', '#10B981'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 20,
                    usePointStyle: true
                }
            }
        }
    }
});

// Priority Chart
const priorityCtx = document.getElementById('priorityChart').getContext('2d');
new Chart(priorityCtx, {
    type: 'bar',
    data: {
        labels: [<?= implode(',', array_map(function($p) { return '"' . $p['priority_label'] . ' (' . ($p['original_priority'] ?: 'Normal') . ')"'; }, $priority_breakdown)) ?>],
        datasets: [{
            label: 'Tickets',
            data: [<?= implode(',', array_column($priority_breakdown, 'count')) ?>],
            backgroundColor: [<?= implode(',', array_map(function($p) { return '"' . getPriorityColor($p['priority_label']) . '"'; }, $priority_breakdown)) ?>],
            borderRadius: 6
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
                grid: {
                    color: '#E2E8F0'
                }
            },
            x: {
                grid: {
                    display: false
                }
            }
        }
    }
});

// Category Chart
const categoryCtx = document.getElementById('categoryChart').getContext('2d');
new Chart(categoryCtx, {
    type: 'doughnut',
    data: {
        labels: [<?= implode(',', array_map(function($c) { return '"' . $c['category'] . '"'; }, $category_breakdown)) ?>],
        datasets: [{
            data: [<?= implode(',', array_column($category_breakdown, 'count')) ?>],
            backgroundColor: [
                '#4F46E5', '#10B981', '#F59E0B', '#EF4444', '#06B6D4', 
                '#8B5CF6', '#F97316', '#EC4899', '#14B8A6', '#84CC16'
            ],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 20,
                    usePointStyle: true
                }
            }
        }
    }
});

// Trend Chart
const trendCtx = document.getElementById('trendChart').getContext('2d');
new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: [<?= implode(',', array_map(function($d) { return '"' . date('M j', strtotime($d['date'])) . '"'; }, $daily_trend)) ?>],
        datasets: [{
            label: 'Tickets Created',
            data: [<?= implode(',', array_column($daily_trend, 'count')) ?>],
            borderColor: '#4F46E5',
            backgroundColor: 'rgba(79, 70, 229, 0.1)',
            fill: true,
            tension: 0.4
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
                grid: {
                    color: '#E2E8F0'
                }
            },
            x: {
                grid: {
                    display: false
                }
            }
        }
    }
});
</script>


<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>