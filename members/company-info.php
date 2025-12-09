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

// Get companies user has access to
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.name, c.contact_email, c.phone, c.website, c.address, c.industry, 
           c.logo_url, c.preferred_currency, c.currency_override, c.created_at,
           CASE 
               WHEN u.company_id = c.id THEN 'Primary'
               ELSE 'Multi-Company'
           END as relationship_type
    FROM companies c
    JOIN users u ON (u.company_id = c.id OR u.id IN (
        SELECT cu.user_id FROM company_users cu WHERE cu.company_id = c.id
    ))
    WHERE u.id = ? AND c.is_active = 1
    ORDER BY relationship_type ASC, c.name ASC
");
$stmt->execute([$user_id]);
$user_companies = $stmt->fetchAll();

// Get statistics for all companies
$company_stats = [];
$total_users_across_all = 0;
foreach ($user_companies as $company) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as user_count,
               COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_count,
               COUNT(CASE WHEN role = 'administrator' THEN 1 END) as admin_count,
               COUNT(CASE WHEN role = 'account_manager' THEN 1 END) as manager_count,
               COUNT(CASE WHEN last_login > DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as recent_count
        FROM users u
        WHERE u.company_id = ? OR u.id IN (SELECT cu.user_id FROM company_users cu WHERE cu.company_id = ?)
    ");
    $stmt->execute([$company['id'], $company['id']]);
    $stats = $stmt->fetch();
    $company_stats[$company['id']] = $stats;
    $total_users_across_all += $stats['user_count'];
}

$page_title = "Company Users | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>
    <style>
        /* Hero Section Styles */
        .company-info-hero-content {
            text-align: center;
            max-width: 800px;
            margin: 0 auto;
        }

        .company-info-hero-title {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .company-info-hero-subtitle {
            font-size: 1.25rem;
            opacity: 0.9;
        }

        .role-indicator {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.65rem;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.15);
            color: rgba(255, 255, 255, 0.95);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stats-overview {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
            margin-top: -60px;
            position: relative;
            z-index: 10;
        }

        .stat-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 2rem 1.5rem;
            text-align: center;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-number.primary { color: #4F46E5; }
        .stat-number.info { color: #06B6D4; }
        .stat-number.success { color: #10B981; }
        .stat-number.warning { color: #F59E0B; }

        .stat-label {
            color: #6b7280;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .companies-section {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .companies-header {
            background: #f8fafc;
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .companies-header h5 {
            margin: 0;
            color: #374151;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .companies-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            padding: 1.5rem;
            gap: 1.5rem;
        }

        .company-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .company-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .company-card:hover {
            border-color: #667eea;
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.2);
        }

        .company-card.selected {
            border-color: #667eea;
            background: #f8fafc;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.25);
        }

        .company-card.selected::before {
            height: 6px;
        }

        .company-card > div {
            padding: 1.5rem;
        }

        .company-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .company-logo {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: white;
            background: linear-gradient(135deg, #10B981, #059669);
            font-size: 1.25rem;
        }

        .company-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
        }

        .company-info h6 {
            margin: 0;
            color: #1f2937;
            font-weight: 600;
        }

        .company-info .company-email {
            color: #6b7280;
            font-size: 0.875rem;
        }

        .company-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .company-badge.primary {
            background: #d1fae5;
            color: #065f46;
        }

        .company-badge.multi {
            background: #fef3c7;
            color: #92400e;
        }

        .company-stats {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .company-stat {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.875rem;
        }

        .company-stat .icon {
            color: #4F46E5;
            font-size: 1rem;
        }

        .company-stat .value {
            font-weight: 600;
            color: #1f2937;
        }

        .company-stat .label {
            color: #6b7280;
        }

        .expanded-content {
            display: none;
            margin-top: 2rem;
            animation: slideDown 0.3s ease-out;
        }

        .expanded-content.show {
            display: block;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .detail-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .detail-stat-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 2rem 1.5rem;
            text-align: center;
            transition: all 0.2s;
        }

        .detail-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .detail-stat-card .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin: 0 auto 1rem;
        }

        .detail-stat-card .stat-icon.users { background: #4F46E5; }
        .detail-stat-card .stat-icon.active { background: #10B981; }
        .detail-stat-card .stat-icon.recent { background: #F59E0B; }
        .detail-stat-card .stat-icon.admins { background: #EF4444; }

        .detail-stat-card .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.25rem;
        }

        .detail-stat-card .stat-label {
            color: #6b7280;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .filters-section {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .filters-row {
            display: flex;
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            flex: 1;
        }

        .filter-group label {
            display: block;
            font-weight: 600;
            color: #374151;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }

        .filter-group .form-control,
        .filter-group .form-select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0.75rem;
        }

        .users-section {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
        }

        .users-header {
            background: #f8fafc;
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .users-header h6 {
            margin: 0;
            color: #374151;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .users-table {
            width: 100%;
            margin: 0;
        }

        .users-table th {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
            color: #374151;
            padding: 1rem;
            font-size: 0.875rem;
        }

        .users-table td {
            padding: 1rem;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }

        .users-table tbody tr:hover {
            background: #f8fafc;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: white;
            background: linear-gradient(135deg, #4F46E5, #3F37C9);
            font-size: 0.875rem;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .user-avatar.inactive {
            background: linear-gradient(135deg, #9CA3AF, #6B7280);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.inactive {
            background: #f3f4f6;
            color: #374151;
        }

        .role-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .role-badge.administrator {
            background: #fee2e2;
            color: #991b1b;
        }

        .role-badge.account_manager {
            background: #dbeafe;
            color: #1e40af;
        }

        .role-badge.staff {
            background: #f3e8ff;
            color: #7c3aed;
        }

        .role-badge.user {
            background: #f3f4f6;
            color: #374151;
        }

        .role-indicator {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 0.5rem;
            background: #e0e7ff;
            color: #3730a3;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        @media (max-width: 768px) {
            .stats-overview {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .companies-grid {
                grid-template-columns: 1fr;
            }

            .detail-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filters-row {
                flex-direction: column;
                align-items: stretch;
            }
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

        :root.dark .company-info-hero-title,
        :root.dark .company-info-hero-subtitle {
            color: white !important;
            position: relative;
            z-index: 2;
        }
        html.dark .stat-card { background: #1e293b; border-color: #334155; }
        html.dark .stat-number { color: #a78bfa; }
        html.dark .stat-label { color: #94a3b8; }
        html.dark .company-card { background: #1e293b; border-color: #334155; }
        html.dark .company-card:hover { border-color: #8b5cf6; }
        html.dark .company-header h3 { color: #f1f5f9; }
        html.dark .company-logo { background: linear-gradient(135deg, #065f46, #047857); }
        html.dark .company-badge { background: #334155; color: #cbd5e1; }
        html.dark .user-card { background: #1e293b; border-color: #334155; }
        html.dark .user-card:hover { border-color: #8b5cf6; }
        html.dark .user-card h6 { color: #f1f5f9; }
        html.dark .user-meta .text-muted { color: #94a3b8 !important; }
        html.dark .filters-card { background: #1e293b; border-color: #334155; }
        html.dark .filters-card h6 { color: #a78bfa; }
        html.dark .form-control, html.dark .form-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
        html.dark .form-control:focus, html.dark .form-select:focus { background: #0f172a; border-color: #8b5cf6; }
        html.dark .table { color: #e2e8f0; }
        html.dark .table tbody td { border-color: #334155 !important; }
        html.dark .table tbody tr:hover { background: rgba(139, 92, 246, 0.1) !important; }
        html.dark small { color: #94a3b8; }

        /* Companies section dark mode */
        :root.dark .companies-section {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .companies-header {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .companies-header h5 {
            color: #f1f5f9 !important;
        }

        :root.dark .companies-grid {
            background: #1e293b !important;
        }

        :root.dark .company-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .company-card:hover {
            border-color: #8b5cf6 !important;
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.3) !important;
        }

        :root.dark .company-card.selected {
            border-color: #8b5cf6 !important;
            background: #0f172a !important;
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4) !important;
        }

        :root.dark .company-header h6,
        :root.dark .company-info h6,
        :root.dark .company-info p {
            color: #f1f5f9 !important;
        }

        :root.dark .company-stats .company-stat .label,
        :root.dark .company-stats .company-stat .value {
            color: #cbd5e1 !important;
        }

        /* Detail stats grid dark mode */
        :root.dark .detail-stats-grid {
            background: transparent !important;
        }

        :root.dark .detail-stat-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .detail-stat-card:hover {
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3) !important;
            border-color: #8b5cf6 !important;
        }

        :root.dark .detail-stat-card .stat-number {
            color: #a78bfa !important;
        }

        :root.dark .detail-stat-card .stat-label {
            color: #94a3b8 !important;
        }

        /* Filters section dark mode */
        :root.dark .filters-section {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .filter-group label {
            color: #cbd5e1 !important;
        }

        /* Users section dark mode */
        :root.dark .users-section {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .users-header {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .users-header h6 {
            color: #f1f5f9 !important;
        }

        :root.dark .users-table {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .users-table th {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #cbd5e1 !important;
        }

        :root.dark .users-table td {
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .users-table tbody {
            background: #1e293b !important;
        }

        :root.dark .users-table tbody tr {
            background: #1e293b !important;
        }

        :root.dark .users-table tbody tr:hover {
            background: rgba(139, 92, 246, 0.1) !important;
        }

        /* Force all table content within users section to be dark */
        :root.dark #users-content table {
            background: #1e293b !important;
        }

        :root.dark #users-content table tbody {
            background: #1e293b !important;
        }

        :root.dark #users-content table tbody tr {
            background: #1e293b !important;
        }

        :root.dark #users-content table tbody tr:hover {
            background: rgba(139, 92, 246, 0.1) !important;
        }

        :root.dark #users-content table tbody td {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark #users-content .table {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark #users-content .table tbody tr {
            background: #1e293b !important;
        }

        :root.dark #users-content .table tbody td {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        /* Table headers in users content */
        :root.dark #users-content table thead {
            background: #0f172a !important;
        }

        :root.dark #users-content table thead tr {
            background: #0f172a !important;
        }

        :root.dark #users-content table thead th,
        :root.dark #users-content table th {
            background: #0f172a !important;
            color: #cbd5e1 !important;
            border-color: #334155 !important;
        }

        :root.dark #users-content .table thead th {
            background: #0f172a !important;
            color: #cbd5e1 !important;
            border-color: #334155 !important;
        }

        /* All text inside table cells */
        :root.dark #users-content table td .fw-bold,
        :root.dark #users-content table td span,
        :root.dark #users-content table td div {
            color: #e2e8f0 !important;
        }

        /* Role and status badges */
        :root.dark #users-content .role-badge,
        :root.dark #users-content .status-badge {
            background: #334155 !important;
            color: #cbd5e1 !important;
            padding: 0.25rem 0.75rem;
            border-radius: 6px;
            font-size: 0.875rem;
        }

        :root.dark #users-content .status-badge.active {
            background: rgba(16, 185, 129, 0.2) !important;
            color: #34d399 !important;
        }

        :root.dark #users-content .status-badge.inactive {
            background: rgba(239, 68, 68, 0.2) !important;
            color: #f87171 !important;
        }

        /* Text muted elements in table */
        :root.dark #users-content table .text-muted,
        :root.dark #users-content table small.text-muted {
            color: #94a3b8 !important;
        }

        :root.dark .user-avatar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
        }

        :root.dark .user-role-badge {
            background: #334155 !important;
            color: #cbd5e1 !important;
        }

        :root.dark .user-status-badge {
            background: #334155 !important;
        }

        /* Expanded content dark mode */
        :root.dark .expanded-content {
            background: transparent !important;
        }

        /* Role indicator dark mode */
        :root.dark .role-indicator {
            background: rgba(139, 92, 246, 0.2) !important;
            color: #a78bfa !important;
        }

        /* Empty state dark mode */
        :root.dark .empty-state {
            background: #1e293b !important;
            color: #94a3b8 !important;
        }

        :root.dark .empty-state h4 {
            color: #cbd5e1 !important;
        }
    </style>

<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="company-info-hero-content">
            <h1 class="company-info-hero-title"><i class="bi bi-people me-3"></i>Company Users</h1>
            <p class="company-info-hero-subtitle">Select a company to view and manage its users</p>
            <span class="role-indicator"><?= ucfirst(str_replace('_', ' ', $_SESSION['user']['role'])) ?></span>
        </div>
    </div>
</header>

<div class="container py-5 overlap-cards">
    <!-- Overview Statistics -->
    <div class="stats-overview">
        <div class="stat-card">
            <div class="stat-number primary"><?= count($user_companies) ?></div>
            <div class="stat-label">Your Companies</div>
        </div>
        <div class="stat-card">
            <div class="stat-number info"><?= $total_users_across_all ?></div>
            <div class="stat-label">Total Users</div>
        </div>
        <div class="stat-card">
            <div class="stat-number success"><?= array_sum(array_column($company_stats, 'active_count')) ?></div>
            <div class="stat-label">Active Users</div>
        </div>
        <div class="stat-card">
            <div class="stat-number warning"><?= array_sum(array_column($company_stats, 'recent_count')) ?></div>
            <div class="stat-label">Recently Active</div>
        </div>
    </div>

    <!-- Companies Selection -->
    <div class="companies-section">
        <div class="companies-header">
            <h5><i class="bi bi-buildings"></i>Select a Company to View Users</h5>
        </div>

        <?php if (empty($user_companies)): ?>
            <div class="empty-state">
                <i class="bi bi-buildings"></i>
                <h4>No Companies Found</h4>
                <p class="mb-0">You don't have access to any companies yet.</p>
            </div>
        <?php else: ?>
            <div class="companies-grid">
                <?php foreach ($user_companies as $company): ?>
                    <div class="company-card" data-company-id="<?= $company['id'] ?>" onclick="selectCompany(<?= $company['id'] ?>, '<?= htmlspecialchars($company['name'], ENT_QUOTES) ?>')">
                        <div class="company-badge <?= $company['relationship_type'] === 'Primary' ? 'primary' : 'multi' ?>">
                            <i class="bi <?= $company['relationship_type'] === 'Primary' ? 'bi-building' : 'bi-diagram-3' ?>"></i>
                            <?= $company['relationship_type'] ?>
                        </div>
                        
                        <div class="company-header">
                            <div class="company-logo">
                                <?php if ($company['logo_url']): ?>
                                    <img src="<?= htmlspecialchars($company['logo_url']) ?>" alt="<?= htmlspecialchars($company['name']) ?>">
                                <?php else: ?>
                                    <?= strtoupper(substr($company['name'], 0, 2)) ?>
                                <?php endif; ?>
                            </div>
                            <div class="company-info">
                                <h6><?= htmlspecialchars($company['name']) ?></h6>
                                <div class="company-email"><?= htmlspecialchars($company['contact_email']) ?></div>
                            </div>
                        </div>
                        
                        <div class="company-stats">
                            <div class="company-stat">
                                <i class="bi bi-people icon"></i>
                                <span class="value"><?= $company_stats[$company['id']]['user_count'] ?></span>
                                <span class="label">users</span>
                            </div>
                            <div class="company-stat">
                                <i class="bi bi-person-check icon"></i>
                                <span class="value"><?= $company_stats[$company['id']]['active_count'] ?></span>
                                <span class="label">active</span>
                            </div>
                            <div class="company-stat">
                                <i class="bi bi-shield-check icon"></i>
                                <span class="value"><?= $company_stats[$company['id']]['admin_count'] ?></span>
                                <span class="label">admins</span>
                            </div>
                            <div class="company-stat">
                                <i class="bi bi-clock-history icon"></i>
                                <span class="value"><?= $company_stats[$company['id']]['recent_count'] ?></span>
                                <span class="label">recent</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Expandable Company Details -->
    <div class="expanded-content" id="expandedContent">
        <!-- Detailed Statistics Grid (2x2) -->
        <div class="detail-stats-grid">
            <div class="detail-stat-card">
                <div class="stat-icon users">
                    <i class="bi bi-people"></i>
                </div>
                <div class="stat-number" id="detail-total-users">0</div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="detail-stat-card">
                <div class="stat-icon active">
                    <i class="bi bi-person-check"></i>
                </div>
                <div class="stat-number" id="detail-active-users">0</div>
                <div class="stat-label">Active Users</div>
            </div>
            <div class="detail-stat-card">
                <div class="stat-icon recent">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div class="stat-number" id="detail-recent-activity">0</div>
                <div class="stat-label">Recent Activity</div>
            </div>
            <div class="detail-stat-card">
                <div class="stat-icon admins">
                    <i class="bi bi-shield-exclamation"></i>
                </div>
                <div class="stat-number" id="detail-admins-managers">0</div>
                <div class="stat-label">Admins & Managers</div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <div class="filters-row">
                <div class="filter-group">
                    <label><i class="bi bi-funnel me-1"></i>Filter Users</label>
                </div>
                <div class="filter-group">
                    <label>Search users...</label>
                    <input type="text" class="form-control" placeholder="Search users..." onkeyup="filterUsers()">
                </div>
                <div class="filter-group">
                    <label>All Roles</label>
                    <select class="form-select" onchange="filterUsers()">
                        <option value="">All Roles</option>
                        <option value="administrator">Administrator</option>
                        <option value="account_manager">Account Manager</option>
                        <option value="staff">Staff</option>
                        <option value="user">User</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>All Status</label>
                    <select class="form-select" onchange="filterUsers()">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button class="btn btn-primary" onclick="filterUsers()">
                        <i class="bi bi-search me-1"></i>Filter
                    </button>
                </div>
            </div>
        </div>

        <!-- Users Section -->
        <div class="users-section">
            <div class="users-header">
                <h6><i class="bi bi-people-fill"></i>Users</h6>
            </div>
            <div id="users-content">
                <!-- Users table will be loaded here -->
            </div>
        </div>
    </div>
</div>

<script>
let selectedCompanyId = null;

function selectCompany(companyId, companyName) {
    // Remove selection from all company cards
    document.querySelectorAll('.company-card').forEach(card => {
        card.classList.remove('selected');
    });
    
    // Add selection to clicked card
    const selectedCard = document.querySelector(`[data-company-id="${companyId}"]`);
    selectedCard.classList.add('selected');
    
    // Update selected company ID
    selectedCompanyId = companyId;
    
    // Update detailed statistics
    const stats = <?= json_encode($company_stats) ?>;
    const companyStats = stats[companyId];
    
    document.getElementById('detail-total-users').textContent = companyStats.user_count;
    document.getElementById('detail-active-users').textContent = companyStats.active_count;
    document.getElementById('detail-recent-activity').textContent = companyStats.recent_count;
    document.getElementById('detail-admins-managers').textContent = parseInt(companyStats.admin_count) + parseInt(companyStats.manager_count);
    
    // Show expanded content
    const expandedContent = document.getElementById('expandedContent');
    expandedContent.classList.add('show');
    
    // Load users for selected company
    loadCompanyUsers(companyId);
    
    // Smooth scroll to expanded content
    setTimeout(() => {
        expandedContent.scrollIntoView({ 
            behavior: 'smooth', 
            block: 'start' 
        });
    }, 100);
}

function loadCompanyUsers(companyId, search = '', role = '', status = '') {
    const usersContent = document.getElementById('users-content');
    
    // Show loading state
    usersContent.innerHTML = `
        <div class="text-center p-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted mt-3 mb-0">Loading users...</p>
        </div>
    `;
    
    // Build query parameters
    const params = new URLSearchParams({
        action: 'get_company_users',
        company_id: companyId
    });
    
    if (search) params.append('search', search);
    if (role) params.append('role', role);
    if (status) params.append('status', status);
    
    // Make AJAX request - Updated path
    console.log('Making request to:', `/api/company-users-ajax.php?${params}`);
    
    fetch(`/api/company-users-ajax.php?${params}`)
        .then(response => {
            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers);
            return response.text(); // Get as text first to debug
        })
        .then(text => {
            console.log('Raw response:', text);
            try {
                const data = JSON.parse(text);
                if (data.success) {
                    usersContent.innerHTML = data.html;
                } else {
                    usersContent.innerHTML = `
                        <div class="empty-state">
                            <i class="bi bi-exclamation-circle"></i>
                            <h5>Error Loading Users</h5>
                            <p class="mb-0">${data.error || 'Unknown error occurred'}</p>
                        </div>
                    `;
                }
            } catch (e) {
                console.error('JSON Parse error:', e);
                console.error('Response was:', text);
                usersContent.innerHTML = `
                    <div class="empty-state">
                        <i class="bi bi-code-slash"></i>
                        <h5>Parse Error</h5>
                        <p class="mb-0">Invalid response format. Check console for details.</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading users:', error);
            usersContent.innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-wifi-off"></i>
                    <h5>Connection Error</h5>
                    <p class="mb-0">Unable to load users. Please try again.</p>
                </div>
            `;
        });
}

// Debounced filter function
let filterTimeout;
function filterUsers() {
    if (!selectedCompanyId) return;
    
    clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => {
        const searchInput = document.querySelector('.filters-section input[type="text"]');
        const roleSelect = document.querySelector('.filters-section select:nth-of-type(1)');
        const statusSelect = document.querySelector('.filters-section select:nth-of-type(2)');
        
        const search = searchInput ? searchInput.value : '';
        const role = roleSelect ? roleSelect.value : '';
        const status = statusSelect ? statusSelect.value : '';
        
        loadCompanyUsers(selectedCompanyId, search, role, status);
    }, 300);
}

document.addEventListener('DOMContentLoaded', function() {
    console.log('Company Users page loaded');
    console.log('Available companies:', <?= count($user_companies) ?>);
    console.log('Current user:', '<?= $_SESSION["user"]["username"] ?>');
    console.log('User role:', '<?= $_SESSION["user"]["role"] ?>');
});
</script>


<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
