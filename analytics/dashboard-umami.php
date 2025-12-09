<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /login.php');
    exit;
}

// Check permissions
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_role = $stmt->fetchColumn();

if (!in_array($user_role, ['administrator'])) {
    header('Location: /dashboard.php');
    exit;
}

// Get date range from query params
$period = $_GET['period'] ?? '7d';
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
    case 'custom':
        $startDate = $_GET['start'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
        $endDate = $_GET['end'] ?? date('Y-m-d 23:59:59');
        break;
    default:
        $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
}

$page_title = "Analytics | CaminhoIT";
?>
<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Chart.js 4 -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- jsPDF for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #fafafa;
            color: #1f2937;
            line-height: 1.6;
            padding-top: 140px;
        }

        /* Header */
        .analytics-header {
            background: white;
            border-bottom: 1px solid #e5e7eb;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 1rem 0;
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .header-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }

        .header-title p {
            font-size: 0.875rem;
            color: #6b7280;
            margin: 0;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .online-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: #f0fdf4;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            color: #16a34a;
        }

        .online-dot {
            width: 8px;
            height: 8px;
            background: #16a34a;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .date-selector {
            display: flex;
            gap: 0.5rem;
            background: #f3f4f6;
            padding: 0.25rem;
            border-radius: 8px;
        }

        .date-btn {
            padding: 0.5rem 1rem;
            border: none;
            background: transparent;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
        }

        .date-btn:hover {
            color: #111827;
        }

        .date-btn.active {
            background: white;
            color: #111827;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .export-btn {
            padding: 0.5rem 1rem;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .export-btn:hover {
            background: #f9fafb;
            border-color: #9ca3af;
        }

        .export-btn .icon {
            width: 16px;
            height: 16px;
        }

        /* Main Container */
        .analytics-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 1.5rem 2rem;
        }

        /* Summary Metrics */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .metric-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            border: 1px solid #f3f4f6;
        }

        .metric-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .metric-icon {
            color: #6b7280;
            width: 18px;
            height: 18px;
        }

        .metric-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #6b7280;
        }

        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            color: #111827;
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .metric-change {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .metric-change.positive {
            color: #16a34a;
        }

        .metric-change.negative {
            color: #dc2626;
        }

        .metric-change .icon {
            width: 14px;
            height: 14px;
        }

        /* Chart Section */
        .chart-section {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            border: 1px solid #f3f4f6;
            margin-bottom: 2rem;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .chart-title {
            font-size: 1rem;
            font-weight: 600;
            color: #111827;
        }

        .chart-filter {
            display: flex;
            gap: 0.5rem;
            background: #f9fafb;
            padding: 0.25rem;
            border-radius: 6px;
        }

        .filter-btn {
            padding: 0.375rem 0.75rem;
            border: none;
            background: transparent;
            border-radius: 4px;
            font-size: 0.8125rem;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-btn.active {
            background: white;
            color: #111827;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        /* Data Grid */
        .data-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .data-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            border: 1px solid #f3f4f6;
        }

        .data-card-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .data-card-title .icon {
            width: 16px;
            height: 16px;
            color: #6b7280;
        }

        .data-table {
            width: 100%;
        }

        .data-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .data-row:last-child {
            border-bottom: none;
        }

        .data-label {
            font-size: 0.875rem;
            color: #374151;
            font-weight: 400;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 70%;
        }

        .data-value {
            font-size: 0.875rem;
            color: #111827;
            font-weight: 600;
        }

        .data-bar {
            width: 100%;
            height: 6px;
            background: #f3f4f6;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 0.375rem;
        }

        .data-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6 0%, #2563eb 100%);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        /* Environment Grid */
        .env-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .env-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .env-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f9ff;
            color: #3b82f6;
            flex-shrink: 0;
        }

        .env-info {
            flex: 1;
            min-width: 0;
        }

        .env-name {
            font-size: 0.875rem;
            color: #374151;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .env-value {
            font-size: 0.75rem;
            color: #9ca3af;
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding-top: 160px;
            }

            .header-top {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .header-actions {
                width: 100%;
                flex-direction: column;
            }

            .date-selector {
                width: 100%;
                overflow-x: auto;
            }

            .metrics-grid {
                grid-template-columns: 1fr;
            }

            .data-grid,
            .env-grid {
                grid-template-columns: 1fr;
            }

            .metric-value {
                font-size: 1.75rem;
            }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #9ca3af;
        }

        .empty-state .icon {
            width: 48px;
            height: 48px;
            margin: 0 auto 1rem;
            opacity: 0.5;
        }

        /* Events Section */
        .events-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .event-row {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f3f4f6;
            align-items: center;
        }

        .event-row:last-child {
            border-bottom: none;
        }

        .event-name {
            font-size: 0.875rem;
            color: #374151;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .event-icon {
            width: 16px;
            height: 16px;
            color: #9ca3af;
        }

        .event-count {
            font-size: 0.875rem;
            color: #111827;
            font-weight: 600;
        }

        .event-percent {
            font-size: 0.75rem;
            color: #6b7280;
            text-align: right;
            min-width: 45px;
        }

        @media (max-width: 992px) {
            .events-grid {
                grid-template-columns: 1fr;
            }
        }

        /* World Map */
        .world-map-container {
            position: relative;
            width: 100%;
            height: 400px;
            background: #f9fafb;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .world-map-svg {
            width: 100%;
            height: 100%;
        }

        .map-country {
            fill: #e5e7eb;
            stroke: #ffffff;
            stroke-width: 0.5;
            transition: all 0.2s;
            cursor: pointer;
        }

        .map-country:hover {
            fill: #3b82f6;
            opacity: 0.8;
        }

        .map-country.has-data {
            fill: #93c5fd;
        }

        .map-country.has-data:hover {
            fill: #3b82f6;
        }

        .map-legend {
            position: absolute;
            bottom: 1rem;
            right: 1rem;
            background: white;
            padding: 0.75rem;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            font-size: 0.75rem;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
        }

        .legend-color {
            width: 20px;
            height: 12px;
            border-radius: 2px;
        }

        /* Real-time Feed */
        .realtime-feed {
            background: white;
            border-radius: 12px;
            border: 1px solid #f3f4f6;
            padding: 1.5rem;
            margin-bottom: 2rem;
            max-height: 400px;
            overflow-y: auto;
        }

        .visitor-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 0.75rem;
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.2s;
        }

        .visitor-item:hover {
            background: #f9fafb;
        }

        .visitor-item:last-child {
            border-bottom: none;
        }

        .visitor-flag {
            font-size: 1.5rem;
            line-height: 1;
        }

        .visitor-info {
            flex: 1;
            min-width: 0;
        }

        .visitor-page {
            font-size: 0.875rem;
            font-weight: 500;
            color: #111827;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .visitor-meta {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        .visitor-time {
            font-size: 0.75rem;
            color: #9ca3af;
            white-space: nowrap;
        }

        .auto-refresh-toggle {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: #f9fafb;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .auto-refresh-toggle:hover {
            background: #f3f4f6;
        }

        .auto-refresh-toggle.active {
            background: #dbeafe;
            border-color: #3b82f6;
            color: #1e40af;
        }

        .toggle-switch {
            width: 36px;
            height: 20px;
            background: #d1d5db;
            border-radius: 10px;
            position: relative;
            transition: background 0.2s;
        }

        .auto-refresh-toggle.active .toggle-switch {
            background: #3b82f6;
        }

        .toggle-switch::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            background: white;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: left 0.2s;
        }

        .auto-refresh-toggle.active .toggle-switch::after {
            left: 18px;
        }

        .update-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
            color: #6b7280;
            padding: 0.25rem 0.5rem;
            background: #f9fafb;
            border-radius: 4px;
        }

        .update-dot {
            width: 6px;
            height: 6px;
            background: #10b981;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<!-- Header -->
<header class="analytics-header">
    <div class="header-content">
        <div class="header-top">
            <div class="header-title">
                <h1>caminhoit.com</h1>
                <p>Real-time website analytics</p>
            </div>
            <div class="header-actions">
                <div style="display: flex; gap: 0.5rem;">
                    <button class="export-btn" onclick="exportToCSV()">
                        <i data-lucide="download" class="icon"></i>
                        Export CSV
                    </button>
                    <button class="export-btn" onclick="exportToPDF()">
                        <i data-lucide="file-text" class="icon"></i>
                        Export PDF
                    </button>
                    <div class="auto-refresh-toggle" id="autoRefreshToggle" onclick="toggleAutoRefresh()">
                        <div class="toggle-switch"></div>
                        <span>Auto-refresh</span>
                    </div>
                </div>
                <div class="online-indicator">
                    <span class="online-dot"></span>
                    <span id="onlineCount">0</span> online
                </div>
            </div>
        </div>

        <div class="date-selector">
            <button class="date-btn <?= $period === 'today' ? 'active' : '' ?>" onclick="changePeriod('today')">Today</button>
            <button class="date-btn <?= $period === '7d' ? 'active' : '' ?>" onclick="changePeriod('7d')">Last 7 days</button>
            <button class="date-btn <?= $period === '30d' ? 'active' : '' ?>" onclick="changePeriod('30d')">Last 30 days</button>
            <button class="date-btn <?= $period === '90d' ? 'active' : '' ?>" onclick="changePeriod('90d')">Last 90 days</button>
        </div>
    </div>
</header>

<!-- Main Content -->
<div class="analytics-container">

    <!-- Live Visitors Feed -->
    <div class="realtime-feed">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="font-size: 0.875rem; font-weight: 600; color: #111827; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="activity"></i>
                Live Visitors
            </h3>
            <span class="update-indicator" id="lastUpdate">
                <span class="update-dot"></span>
                <span id="updateTime">Just now</span>
            </span>
        </div>
        <div id="liveVisitorsFeed">
            <div class="empty-state">
                <i data-lucide="loader" class="icon"></i>
                <p>Loading live visitors...</p>
            </div>
        </div>
    </div>

    <!-- Summary Metrics -->
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-header">
                <i data-lucide="users" class="metric-icon"></i>
                <span class="metric-label">Visitors</span>
            </div>
            <div class="metric-value" id="totalVisitors">-</div>
            <div class="metric-change" id="visitorsChange"></div>
        </div>

        <div class="metric-card">
            <div class="metric-header">
                <i data-lucide="activity" class="metric-icon"></i>
                <span class="metric-label">Visits</span>
            </div>
            <div class="metric-value" id="totalVisits">-</div>
            <div class="metric-change" id="visitsChange"></div>
        </div>

        <div class="metric-card">
            <div class="metric-header">
                <i data-lucide="eye" class="metric-icon"></i>
                <span class="metric-label">Views</span>
            </div>
            <div class="metric-value" id="totalViews">-</div>
            <div class="metric-change" id="viewsChange"></div>
        </div>

        <div class="metric-card">
            <div class="metric-header">
                <i data-lucide="mouse-pointer-click" class="metric-icon"></i>
                <span class="metric-label">Bounce rate</span>
            </div>
            <div class="metric-value" id="bounceRate">-</div>
            <div class="metric-change" id="bounceChange"></div>
        </div>

        <div class="metric-card">
            <div class="metric-header">
                <i data-lucide="clock" class="metric-icon"></i>
                <span class="metric-label">Visit duration</span>
            </div>
            <div class="metric-value" id="avgDuration">-</div>
            <div class="metric-change" id="durationChange"></div>
        </div>
    </div>

    <!-- Main Chart -->
    <div class="chart-section">
        <div class="chart-header">
            <h2 class="chart-title">Traffic Overview</h2>
            <div class="chart-filter">
                <button class="filter-btn active" data-metric="all">All</button>
                <button class="filter-btn" data-metric="visitors">Visitors</button>
                <button class="filter-btn" data-metric="views">Views</button>
            </div>
        </div>
        <canvas id="mainChart" height="80"></canvas>
    </div>

    <!-- Pages & Referrers -->
    <div class="data-grid">
        <div class="data-card">
            <h3 class="data-card-title">
                <i data-lucide="file-text"></i>
                Pages
            </h3>
            <div class="data-table" id="pagesTable">
                <div class="empty-state">
                    <i data-lucide="loader" class="icon"></i>
                    <p>Loading data...</p>
                </div>
            </div>
        </div>

        <div class="data-card">
            <h3 class="data-card-title">
                <i data-lucide="external-link"></i>
                Referrers
            </h3>
            <div class="data-table" id="referrersTable">
                <div class="empty-state">
                    <i data-lucide="loader" class="icon"></i>
                    <p>Loading data...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Environment (Browsers, OS, Devices) -->
    <div class="env-grid">
        <div class="data-card">
            <h3 class="data-card-title">
                <i data-lucide="chrome"></i>
                Browsers
            </h3>
            <div id="browsersTable">
                <div class="empty-state">
                    <i data-lucide="loader" class="icon"></i>
                    <p>Loading data...</p>
                </div>
            </div>
        </div>

        <div class="data-card">
            <h3 class="data-card-title">
                <i data-lucide="monitor"></i>
                Operating Systems
            </h3>
            <div id="osTable">
                <div class="empty-state">
                    <i data-lucide="loader" class="icon"></i>
                    <p>Loading data...</p>
                </div>
            </div>
        </div>

        <div class="data-card">
            <h3 class="data-card-title">
                <i data-lucide="smartphone"></i>
                Devices
            </h3>
            <div id="devicesTable">
                <div class="empty-state">
                    <i data-lucide="loader" class="icon"></i>
                    <p>Loading data...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- World Map & Countries -->
    <div class="data-card" style="margin-bottom: 2rem;">
        <h3 class="data-card-title">
            <i data-lucide="globe"></i>
            Visitor Locations
        </h3>

        <div class="world-map-container" id="worldMapContainer">
            <div class="empty-state">
                <i data-lucide="loader" class="icon"></i>
                <p>Loading map...</p>
            </div>
        </div>

        <h4 style="font-size: 0.875rem; font-weight: 600; color: #111827; margin-top: 1.5rem; margin-bottom: 1rem;">
            Top Countries
        </h4>
        <div class="data-table" id="countriesTable">
            <div class="empty-state">
                <i data-lucide="loader" class="icon"></i>
                <p>Loading data...</p>
            </div>
        </div>
    </div>

    <!-- Events Tracking -->
    <div class="events-grid">
        <div class="data-card">
            <h3 class="data-card-title">
                <i data-lucide="zap"></i>
                Events
            </h3>
            <div id="eventsTable">
                <div class="empty-state">
                    <i data-lucide="loader" class="icon"></i>
                    <p>Loading events...</p>
                </div>
            </div>
        </div>

        <div class="data-card">
            <h3 class="data-card-title">
                <i data-lucide="bar-chart-2"></i>
                Events by Hour
            </h3>
            <canvas id="eventsChart" height="250"></canvas>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Initialize Lucide icons
lucide.createIcons();

// API Configuration
const API_ENDPOINT = '/analytics/api.php';
const period = '<?= $period ?>';
const startDate = '<?= $startDate ?>';
const endDate = '<?= $endDate ?>';

// Change period
function changePeriod(newPeriod) {
    window.location.href = '?period=' + newPeriod;
}

// Format numbers
function formatNumber(num) {
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
    return num.toLocaleString();
}

// Format duration
function formatDuration(seconds) {
    if (!seconds || seconds < 60) return seconds + 's';
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    if (mins >= 60) {
        const hours = Math.floor(mins / 60);
        const remainMins = mins % 60;
        return `${hours}h ${remainMins}m`;
    }
    return `${mins}m ${secs}s`;
}

// Update metric with change indicator
function updateMetric(valueId, changeId, value, change, inverse = false) {
    document.getElementById(valueId).textContent = value;

    const changeEl = document.getElementById(changeId);
    if (change !== null && change !== undefined && change !== 0) {
        const isPositive = inverse ? change < 0 : change > 0;
        const arrow = isPositive ? 'trending-up' : 'trending-down';
        changeEl.className = 'metric-change ' + (isPositive ? 'positive' : 'negative');
        changeEl.innerHTML = `
            <i data-lucide="${arrow}" class="icon"></i>
            ${Math.abs(change).toFixed(1)}%
        `;
        lucide.createIcons();
    } else {
        changeEl.innerHTML = '';
    }
}

// Main Chart
let mainChart = null;
let mainChartData = null;
let currentChartFilter = 'all';

function renderMainChart(data, filter = 'all') {
    const ctx = document.getElementById('mainChart').getContext('2d');

    if (mainChart) {
        mainChart.destroy();
    }

    // Store data for filtering
    if (data) {
        mainChartData = data;
    }

    const labels = mainChartData.map(d => {
        const date = new Date(d.date);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    });

    const visitors = mainChartData.map(d => parseInt(d.visitors || 0));
    const pageviews = mainChartData.map(d => parseInt(d.pageviews || 0));

    // Build datasets based on filter
    let datasets = [];

    if (filter === 'all' || filter === 'visitors') {
        datasets.push({
            label: 'Visitors',
            data: visitors,
            backgroundColor: 'rgba(59, 130, 246, 0.8)',
            borderRadius: 4,
            maxBarThickness: 50
        });
    }

    if (filter === 'all' || filter === 'views') {
        datasets.push({
            label: 'Views',
            data: pageviews,
            backgroundColor: 'rgba(147, 197, 253, 0.6)',
            borderRadius: 4,
            maxBarThickness: 50
        });
    }

    mainChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: filter === 'all',
                    position: 'top',
                    align: 'start',
                    labels: {
                        boxWidth: 12,
                        boxHeight: 12,
                        padding: 15,
                        font: {
                            size: 12,
                            family: 'Inter'
                        }
                    }
                },
                tooltip: {
                    backgroundColor: '#111827',
                    padding: 12,
                    titleFont: {
                        size: 13,
                        family: 'Inter'
                    },
                    bodyFont: {
                        size: 12,
                        family: 'Inter'
                    },
                    cornerRadius: 8
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 11,
                            family: 'Inter'
                        },
                        color: '#6b7280'
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: '#f3f4f6',
                        drawBorder: false
                    },
                    ticks: {
                        font: {
                            size: 11,
                            family: 'Inter'
                        },
                        color: '#6b7280',
                        callback: function(value) {
                            return formatNumber(value);
                        }
                    }
                }
            }
        }
    });

    currentChartFilter = filter;
}

// Render data tables
function renderDataTable(containerId, data, showBar = false) {
    const container = document.getElementById(containerId);

    if (!data || data.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i data-lucide="inbox" class="icon"></i>
                <p>No data available</p>
            </div>
        `;
        lucide.createIcons();
        return;
    }

    const maxValue = Math.max(...data.map(d => parseInt(d.visitors || d.count || d.views || 0)));

    container.innerHTML = data.map(item => {
        const value = parseInt(item.visitors || item.count || item.views || 0);
        const label = item.page_url || item.source || item.browser || item.os || item.device_type || item.country_name || 'Unknown';
        const percentage = maxValue > 0 ? (value / maxValue) * 100 : 0;

        return `
            <div class="data-row">
                <div style="flex: 1; min-width: 0;">
                    <div class="data-label" title="${label}">${label}</div>
                    ${showBar ? `<div class="data-bar"><div class="data-bar-fill" style="width: ${percentage}%"></div></div>` : ''}
                </div>
                <div class="data-value">${formatNumber(value)}</div>
            </div>
        `;
    }).join('');
}

// World Map
function renderWorldMap(data) {
    const container = document.getElementById('worldMapContainer');

    if (!data || data.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i data-lucide="globe" class="icon"></i>
                <p>No location data available</p>
            </div>
        `;
        lucide.createIcons();
        return;
    }

    // Create country visitor map
    const countryData = {};
    const maxVisitors = Math.max(...data.map(d => parseInt(d.visitors || 0)));

    data.forEach(item => {
        const code = (item.country_code || '').toUpperCase();
        if (code && code !== '??') {
            countryData[code] = {
                visitors: parseInt(item.visitors || 0),
                name: item.country_name || code
            };
        }
    });

    // Simple HTML representation with country list
    container.innerHTML = `
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 0.75rem; padding: 1.5rem;">
            ${data.slice(0, 12).map(country => {
                const visitors = parseInt(country.visitors || 0);
                const percentage = maxVisitors > 0 ? (visitors / maxVisitors) * 100 : 0;
                return `
                    <div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; background: white; border-radius: 8px; border: 1px solid #f3f4f6;">
                        <div style="flex: 1;">
                            <div style="font-size: 0.875rem; color: #374151; font-weight: 500; margin-bottom: 0.25rem;">
                                ${country.flag || '🌍'} ${country.country_name || country.country_code}
                            </div>
                            <div class="data-bar">
                                <div class="data-bar-fill" style="width: ${percentage}%"></div>
                            </div>
                        </div>
                        <div style="font-size: 0.875rem; color: #111827; font-weight: 600;">
                            ${formatNumber(visitors)}
                        </div>
                    </div>
                `;
            }).join('')}
        </div>
    `;
}

// Events Chart
let eventsChart = null;

function renderEventsTable(data) {
    const container = document.getElementById('eventsTable');

    if (!data || data.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i data-lucide="activity" class="icon"></i>
                <p>No events tracked</p>
            </div>
        `;
        lucide.createIcons();
        return;
    }

    const totalEvents = data.reduce((sum, e) => sum + parseInt(e.count || 0), 0);

    container.innerHTML = data.slice(0, 10).map(event => {
        const count = parseInt(event.count || 0);
        const percentage = totalEvents > 0 ? ((count / totalEvents) * 100).toFixed(1) : 0;

        return `
            <div class="event-row">
                <div class="event-name">
                    <i data-lucide="zap" class="event-icon"></i>
                    ${event.event_name || event.name || 'Unknown Event'}
                </div>
                <div class="event-count">${formatNumber(count)}</div>
                <div class="event-percent">${percentage}%</div>
            </div>
        `;
    }).join('');

    lucide.createIcons();
}

function renderEventsChart(data) {
    const ctx = document.getElementById('eventsChart').getContext('2d');

    if (eventsChart) {
        eventsChart.destroy();
    }

    if (!data || data.length === 0) {
        // Show empty state
        return;
    }

    // Group events by hour
    const hours = Array.from({length: 24}, (_, i) => i);
    const eventsByHour = hours.map(hour => {
        const hourData = data.filter(d => parseInt(d.hour) === hour);
        return hourData.reduce((sum, d) => sum + parseInt(d.count || 0), 0);
    });

    eventsChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: hours.map(h => `${h}:00`),
            datasets: [{
                label: 'Events',
                data: eventsByHour,
                backgroundColor: 'rgba(147, 51, 234, 0.8)',
                borderRadius: 4,
                maxBarThickness: 30
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: '#111827',
                    padding: 12,
                    titleFont: {
                        size: 13,
                        family: 'Inter'
                    },
                    bodyFont: {
                        size: 12,
                        family: 'Inter'
                    },
                    cornerRadius: 8,
                    callbacks: {
                        title: function(items) {
                            return `${items[0].label}`;
                        },
                        label: function(context) {
                            return `${context.parsed.y} events`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 10,
                            family: 'Inter'
                        },
                        color: '#6b7280',
                        maxRotation: 45,
                        minRotation: 45,
                        callback: function(value, index) {
                            return index % 3 === 0 ? this.getLabelForValue(value) : '';
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: '#f3f4f6',
                        drawBorder: false
                    },
                    ticks: {
                        font: {
                            size: 11,
                            family: 'Inter'
                        },
                        color: '#6b7280',
                        callback: function(value) {
                            return formatNumber(value);
                        }
                    }
                }
            }
        }
    });
}

// Load dashboard data
async function loadDashboardData() {
    try {
        const response = await fetch(`${API_ENDPOINT}?action=dashboard&start=${startDate}&end=${endDate}`);
        const data = await response.json();

        // Store data for export
        dashboardData = data;

        // Update metrics
        updateMetric('totalVisitors', 'visitorsChange', formatNumber(data.metrics.visitors), data.metrics.visitorsChange);
        updateMetric('totalVisits', 'visitsChange', formatNumber(data.metrics.visits || data.metrics.visitors), data.metrics.visitsChange);
        updateMetric('totalViews', 'viewsChange', formatNumber(data.metrics.pageviews), data.metrics.pageviewsChange);
        updateMetric('bounceRate', 'bounceChange', data.metrics.bounceRate + '%', data.metrics.bounceChange, true);
        updateMetric('avgDuration', 'durationChange', formatDuration(data.metrics.avgTime), data.metrics.timeChange);

        // Render charts
        renderMainChart(data.traffic);

        // Render tables
        renderDataTable('pagesTable', data.topPages?.slice(0, 10), true);
        renderDataTable('referrersTable', data.topReferrers?.slice(0, 10), true);
        renderDataTable('browsersTable', data.topBrowsers?.slice(0, 8), false);
        renderDataTable('osTable', data.topOS?.slice(0, 8), false);
        renderDataTable('devicesTable', data.devices?.slice(0, 8), false);

        // Render world map and countries
        renderWorldMap(data.topCountries);
        renderDataTable('countriesTable', data.topCountries?.slice(0, 10), true);

        // Render events (if available)
        if (data.events) {
            renderEventsTable(data.events);
            renderEventsChart(data.eventsByHour || data.events);
        }

    } catch (error) {
        console.error('Error loading dashboard:', error);
    }
}

// Load real-time visitors
async function loadRealTimeVisitors() {
    try {
        const response = await fetch(`${API_ENDPOINT}?action=realtime`);
        const data = await response.json();

        document.getElementById('onlineCount').textContent = data.count || 0;

        // Update live visitor feed
        renderLiveVisitorsFeed(data.visitors || []);

        // Update last update time
        updateLastUpdateTime();
    } catch (error) {
        console.error('Error loading real-time data:', error);
    }
}

// Render live visitors feed
function renderLiveVisitorsFeed(visitors) {
    const container = document.getElementById('liveVisitorsFeed');

    if (!visitors || visitors.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i data-lucide="users" class="icon"></i>
                <p>No active visitors right now</p>
            </div>
        `;
        lucide.createIcons();
        return;
    }

    container.innerHTML = visitors.slice(0, 10).map(visitor => {
        const timeAgo = visitor.seconds_ago || 0;
        const timeText = timeAgo < 60 ? `${timeAgo}s ago` : `${Math.floor(timeAgo / 60)}m ago`;

        return `
            <div class="visitor-item">
                <div class="visitor-flag">${visitor.flag || '🌍'}</div>
                <div class="visitor-info">
                    <div class="visitor-page">${visitor.current_page || '/'}</div>
                    <div class="visitor-meta">
                        ${visitor.city || 'Unknown'}, ${visitor.country_code || '??'} •
                        ${visitor.device_type || 'Desktop'} •
                        ${visitor.browser || 'Unknown'}
                    </div>
                </div>
                <div class="visitor-time">${timeText}</div>
            </div>
        `;
    }).join('');
}

// Update last update time
let lastUpdateTimestamp = Date.now();

function updateLastUpdateTime() {
    lastUpdateTimestamp = Date.now();
    document.getElementById('updateTime').textContent = 'Just now';
}

// Update time display every second
setInterval(() => {
    const secondsAgo = Math.floor((Date.now() - lastUpdateTimestamp) / 1000);
    const timeEl = document.getElementById('updateTime');

    if (secondsAgo < 60) {
        timeEl.textContent = secondsAgo === 0 ? 'Just now' : `${secondsAgo}s ago`;
    } else {
        timeEl.textContent = `${Math.floor(secondsAgo / 60)}m ago`;
    }
}, 1000);

// Auto-refresh functionality
let autoRefreshEnabled = false;
let autoRefreshInterval = null;

function toggleAutoRefresh() {
    autoRefreshEnabled = !autoRefreshEnabled;
    const toggle = document.getElementById('autoRefreshToggle');

    if (autoRefreshEnabled) {
        toggle.classList.add('active');
        // Refresh every 30 seconds
        autoRefreshInterval = setInterval(() => {
            loadDashboardData();
            loadRealTimeVisitors();
        }, 30000);
    } else {
        toggle.classList.remove('active');
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
    }
}

// Export to CSV
let dashboardData = null;

function exportToCSV() {
    if (!dashboardData) {
        alert('No data available to export');
        return;
    }

    let csv = 'Analytics Report - caminhoit.com\n';
    csv += `Period: ${startDate} to ${endDate}\n\n`;

    // Summary metrics
    csv += 'Summary Metrics\n';
    csv += 'Metric,Value\n';
    csv += `Visitors,${dashboardData.metrics.visitors}\n`;
    csv += `Page Views,${dashboardData.metrics.pageviews}\n`;
    csv += `Avg Time on Site,${formatDuration(dashboardData.metrics.avgTime)}\n`;
    csv += `Bounce Rate,${dashboardData.metrics.bounceRate}%\n\n`;

    // Top Pages
    csv += 'Top Pages\n';
    csv += 'Page,Views,Visitors\n';
    dashboardData.topPages?.forEach(page => {
        csv += `"${page.page_url}",${page.views},${page.visitors}\n`;
    });
    csv += '\n';

    // Top Referrers
    csv += 'Top Referrers\n';
    csv += 'Source,Visitors\n';
    dashboardData.topReferrers?.forEach(ref => {
        csv += `"${ref.source}",${ref.visitors}\n`;
    });
    csv += '\n';

    // Top Countries
    csv += 'Top Countries\n';
    csv += 'Country,Visitors\n';
    dashboardData.topCountries?.forEach(country => {
        csv += `"${country.country_name}",${country.visitors}\n`;
    });

    // Download
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `analytics-report-${Date.now()}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// Export to PDF
function exportToPDF() {
    if (!dashboardData) {
        alert('No data available to export');
        return;
    }

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();

    // Title
    doc.setFontSize(20);
    doc.text('Analytics Report', 20, 20);

    // Website
    doc.setFontSize(12);
    doc.text('caminhoit.com', 20, 30);

    // Period
    doc.setFontSize(10);
    doc.text(`Period: ${startDate} to ${endDate}`, 20, 38);

    // Summary Metrics
    doc.setFontSize(14);
    doc.text('Summary Metrics', 20, 50);

    doc.setFontSize(10);
    let y = 60;
    doc.text(`Visitors: ${formatNumber(dashboardData.metrics.visitors)}`, 20, y);
    y += 7;
    doc.text(`Page Views: ${formatNumber(dashboardData.metrics.pageviews)}`, 20, y);
    y += 7;
    doc.text(`Avg Time: ${formatDuration(dashboardData.metrics.avgTime)}`, 20, y);
    y += 7;
    doc.text(`Bounce Rate: ${dashboardData.metrics.bounceRate}%`, 20, y);
    y += 15;

    // Top Pages
    doc.setFontSize(14);
    doc.text('Top Pages', 20, y);
    y += 10;

    doc.setFontSize(9);
    dashboardData.topPages?.slice(0, 10).forEach(page => {
        if (y > 270) {
            doc.addPage();
            y = 20;
        }
        doc.text(`${page.page_url} - ${formatNumber(page.views)} views`, 20, y);
        y += 6;
    });

    y += 10;

    // Top Countries
    if (y > 250) {
        doc.addPage();
        y = 20;
    }

    doc.setFontSize(14);
    doc.text('Top Countries', 20, y);
    y += 10;

    doc.setFontSize(9);
    dashboardData.topCountries?.slice(0, 10).forEach(country => {
        if (y > 270) {
            doc.addPage();
            y = 20;
        }
        doc.text(`${country.country_name} - ${formatNumber(country.visitors)} visitors`, 20, y);
        y += 6;
    });

    // Footer
    doc.setFontSize(8);
    doc.text(`Generated on ${new Date().toLocaleString()}`, 20, 285);

    // Download
    doc.save(`analytics-report-${Date.now()}.pdf`);
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    loadDashboardData();
    loadRealTimeVisitors();

    // Refresh real-time every 10 seconds
    setInterval(loadRealTimeVisitors, 10000);

    // Chart filter buttons
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const metric = this.getAttribute('data-metric');

            // Update active state
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            // Re-render chart with filter
            if (mainChartData) {
                renderMainChart(null, metric);
            }
        });
    });
});
</script>

</body>
</html>
