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

    <!-- Chart.js 4 -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- jsPDF -->
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
            background: #f9fafb;
            color: #111827;
            font-size: 16px;
            line-height: 1.5;
            padding-top: 120px;
        }

        body.admin-layout {
            padding-top: 120px !important;
        }

        /* Top Bar */
        .top-bar {
            background: white;
            border-bottom: 1px solid #e5e7eb;
            padding: 0 24px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 18px;
            font-weight: 700;
            color: #111827;
        }

        .brand img {
            height: 32px;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .user-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: #f3f4f6;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
        }

        .avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #3b82f6;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 600;
        }

        /* Main Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 32px 24px;
        }

        /* Site Header */
        .site-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .site-title {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
        }

        .site-url {
            font-size: 14px;
            color: #6b7280;
            margin-top: 4px;
        }

        /* Date Pills */
        .date-pills {
            display: flex;
            gap: 8px;
            background: #f3f4f6;
            padding: 4px;
            border-radius: 8px;
        }

        .pill {
            padding: 8px 16px;
            border: none;
            background: transparent;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
        }

        .pill:hover {
            background: #e5e7eb;
            color: #111827;
        }

        .pill.active {
            background: #3b82f6;
            color: white;
            box-shadow: 0 1px 3px rgba(59, 130, 246, 0.3);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 32px;
            margin-bottom: 48px;
        }

        .stat {
            animation: fadeIn 0.5s ease-in-out;
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

        .stat-label {
            font-size: 13px;
            font-weight: 500;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 36px;
            font-weight: 700;
            color: #111827;
            line-height: 1;
            margin-bottom: 8px;
        }

        .stat-change {
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .stat-change.positive {
            color: #10b981;
        }

        .stat-change.negative {
            color: #ef4444;
        }

        .stat-change.neutral {
            color: #9ca3af;
        }

        .stat-change .icon {
            width: 14px;
            height: 14px;
        }

        /* Section */
        .section {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title .emoji {
            font-size: 18px;
        }

        /* Chart Filters */
        .chart-filters {
            display: flex;
            gap: 8px;
        }

        .filter-btn {
            padding: 6px 12px;
            border: 1px solid #e5e7eb;
            background: white;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
        }

        .filter-btn:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        .filter-btn.active {
            background: #3b82f6;
            border-color: #3b82f6;
            color: white;
        }

        /* Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead {
            border-bottom: 2px solid #f3f4f6;
        }

        .data-table th {
            text-align: left;
            padding: 12px 0;
            font-size: 13px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .data-table tbody tr {
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.2s;
        }

        .data-table tbody tr:hover {
            background: #f9fafb;
        }

        .data-table tbody tr:last-child {
            border-bottom: none;
        }

        .data-table td {
            padding: 12px 0;
            font-size: 14px;
            color: #374151;
        }

        .data-table td:first-child {
            font-weight: 500;
            color: #111827;
        }

        .progress-bar {
            height: 6px;
            background: #f3f4f6;
            border-radius: 3px;
            overflow: hidden;
            width: 100px;
        }

        .progress-fill {
            height: 100%;
            background: #dbeafe;
            transition: width 0.3s;
        }

        /* Grid Layouts */
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }

        /* Country List */
        .country-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .country-item:last-child {
            border-bottom: none;
        }

        .country-flag {
            font-size: 20px;
        }

        .country-name {
            flex: 1;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
        }

        .country-count {
            font-size: 14px;
            font-weight: 600;
            color: #111827;
        }

        /* Events */
        .event-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .event-item:last-child {
            border-bottom: none;
        }

        .event-name {
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .event-name .icon {
            width: 16px;
            height: 16px;
            color: #9ca3af;
        }

        .event-count {
            font-size: 14px;
            font-weight: 600;
            color: #111827;
        }

        .event-percent {
            font-size: 13px;
            color: #6b7280;
        }

        /* Export Buttons */
        .export-btns {
            display: flex;
            gap: 8px;
        }

        .export-btn {
            padding: 8px 16px;
            border: 1px solid #e5e7eb;
            background: white;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: 'Inter', sans-serif;
        }

        .export-btn:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        .export-btn .icon {
            width: 16px;
            height: 16px;
        }

        /* Loading State */
        .loading {
            text-align: center;
            padding: 40px;
            color: #9ca3af;
        }

        .loading .icon {
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }

            .site-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }

            .date-pills {
                width: 100%;
                overflow-x: auto;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 24px;
            }

            .grid-2,
            .grid-3 {
                grid-template-columns: 1fr;
            }

            .stat-value {
                font-size: 28px;
            }

            .top-bar {
                height: auto;
                padding: 12px 16px;
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .section {
                padding: 16px;
            }
        }

        /* Smooth transitions */
        * {
            transition: background-color 0.2s ease-in-out, border-color 0.2s ease-in-out, color 0.2s ease-in-out;
        }

        button, .pill, .filter-btn, .export-btn {
            transition: all 0.2s ease-in-out;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<!-- Hero Section -->
<header class="hero" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 4rem 0 6rem; margin-bottom: -4rem; margin-top: -80px; padding-top: calc(4rem + 80px); position: relative; overflow: hidden;">
    <div class="hero-gradient" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(135deg, rgba(102, 126, 234, 0.9) 0%, rgba(118, 75, 162, 0.9) 100%); z-index: 0;"></div>
    <div class="container position-relative" style="z-index: 1;">
        <div style="text-align: center; color: white;">
            <h1 style="font-size: 2.5rem; font-weight: 700; margin-bottom: 1rem; color: white;">
                <i class="bi bi-graph-up me-2"></i>
                Analytics Dashboard V2
            </h1>
            <p style="font-size: 1.15rem; opacity: 0.95; margin-bottom: 2rem;">
                Advanced website analytics with real-time insights
            </p>
        </div>
    </div>
</header>

<!-- Top Bar -->
<div class="top-bar">
    <div class="brand">
        <img src="/assets/logo.png" alt="CaminhoIT">
        <span>Analytics</span>
    </div>
    <div class="top-actions">
        <div class="export-btns">
            <button class="export-btn" onclick="exportToCSV()">
                <i data-lucide="download" class="icon"></i>
                CSV
            </button>
            <button class="export-btn" onclick="exportToPDF()">
                <i data-lucide="file-text" class="icon"></i>
                PDF
            </button>
        </div>
        <div class="user-badge">
            <div class="avatar"><?= strtoupper(substr($user['username'] ?? 'U', 0, 2)) ?></div>
            <span><?= htmlspecialchars($user['username'] ?? 'User') ?></span>
        </div>
    </div>
</div>

<!-- Main Container -->
<div class="container">

    <!-- Site Header -->
    <div class="site-header">
        <div>
            <h1 class="site-title">caminhoit.com</h1>
            <p class="site-url">Real-time website analytics</p>
        </div>
        <div class="date-pills">
            <button class="pill <?= $period === 'today' ? 'active' : '' ?>" onclick="changePeriod('today')">Today</button>
            <button class="pill <?= $period === '7d' ? 'active' : '' ?>" onclick="changePeriod('7d')">Last 7 days</button>
            <button class="pill <?= $period === '30d' ? 'active' : '' ?>" onclick="changePeriod('30d')">Last 30 days</button>
            <button class="pill <?= $period === '90d' ? 'active' : '' ?>" onclick="changePeriod('90d')">Last 90 days</button>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat">
            <div class="stat-label">Visitors</div>
            <div class="stat-value" id="totalVisitors">-</div>
            <div class="stat-change" id="visitorsChange"></div>
        </div>

        <div class="stat">
            <div class="stat-label">Visits</div>
            <div class="stat-value" id="totalVisits">-</div>
            <div class="stat-change" id="visitsChange"></div>
        </div>

        <div class="stat">
            <div class="stat-label">Views</div>
            <div class="stat-value" id="totalViews">-</div>
            <div class="stat-change" id="viewsChange"></div>
        </div>

        <div class="stat">
            <div class="stat-label">Bounce rate</div>
            <div class="stat-value" id="bounceRate">-</div>
            <div class="stat-change" id="bounceChange"></div>
        </div>

        <div class="stat">
            <div class="stat-label">Visit duration</div>
            <div class="stat-value" id="avgDuration">-</div>
            <div class="stat-change" id="durationChange"></div>
        </div>
    </div>

    <!-- Traffic Chart -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">
                <span class="emoji">📊</span>
                Traffic Overview (Visitors vs Views)
            </h2>
            <div class="chart-filters">
                <button class="filter-btn active" data-metric="all">All</button>
                <button class="filter-btn" data-metric="visitors">Visitors</button>
                <button class="filter-btn" data-metric="views">Views</button>
            </div>
        </div>
        <canvas id="trafficChart" height="80"></canvas>
    </div>

    <!-- Pages & Referrers -->
    <div class="grid-2">
        <div class="section">
            <h2 class="section-title">
                <span class="emoji">📄</span>
                Pages
            </h2>
            <table class="data-table" id="pagesTable">
                <thead>
                    <tr>
                        <th>Page</th>
                        <th>Views</th>
                        <th>Visitors</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="3" class="loading">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2 class="section-title">
                <span class="emoji">🌐</span>
                Referrers
            </h2>
            <table class="data-table" id="referrersTable">
                <thead>
                    <tr>
                        <th>Source</th>
                        <th>Visitors</th>
                        <th style="width: 120px;">Traffic</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="3" class="loading">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Devices, OS, Browsers -->
    <div class="grid-3">
        <div class="section">
            <h2 class="section-title">
                <span class="emoji">💻</span>
                Devices
            </h2>
            <div id="devicesTable">
                <div class="loading">Loading...</div>
            </div>
        </div>

        <div class="section">
            <h2 class="section-title">
                <span class="emoji">⚙️</span>
                Operating Systems
            </h2>
            <div id="osTable">
                <div class="loading">Loading...</div>
            </div>
        </div>

        <div class="section">
            <h2 class="section-title">
                <span class="emoji">🧭</span>
                Browsers
            </h2>
            <div id="browsersTable">
                <div class="loading">Loading...</div>
            </div>
        </div>
    </div>

    <!-- Countries -->
    <div class="section">
        <h2 class="section-title">
            <span class="emoji">🌍</span>
            Top Countries
        </h2>
        <div id="countriesTable">
            <div class="loading">Loading...</div>
        </div>
    </div>

    <!-- Events -->
    <div class="grid-2">
        <div class="section">
            <h2 class="section-title">
                <span class="emoji">⚡</span>
                Events
            </h2>
            <div id="eventsTable">
                <div class="loading">Loading...</div>
            </div>
        </div>

        <div class="section">
            <h2 class="section-title">
                <span class="emoji">📈</span>
                Events by Hour
            </h2>
            <canvas id="eventsChart" height="200"></canvas>
        </div>
    </div>

</div>

<script>
// ============================================================================
// ANTI-DUPLICATION SAFEGUARDS
// ============================================================================
// This dashboard has multiple safeguards to prevent duplicate renders:
// 1. isInitialized - prevents DOMContentLoaded from running multiple times
// 2. dashboardLoading - prevents concurrent API calls to loadDashboardData()
// 3. eventsRendering - prevents concurrent renders of events table
// 4. eventsChartRendering - prevents concurrent renders of events chart
// 5. Chart.getChart() - destroys orphaned Chart.js instances before creating new ones
// 6. Canvas clearing - completely clears canvas before rendering
// 7. No auto-refresh intervals - manual refresh only via period change
// ============================================================================

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
        const arrow = isPositive ? '↑' : '↓';
        const className = isPositive ? 'positive' : 'negative';

        changeEl.className = 'stat-change ' + className;
        changeEl.innerHTML = `
            <span>${arrow}</span>
            ${change > 0 ? '+' : ''}${change.toFixed(1)}%
        `;
    } else {
        changeEl.innerHTML = '<span class="neutral">—</span>';
    }
}

// Main Chart
let mainChart = null;
let mainChartData = null;

function renderMainChart(data, filter = 'all') {
    const canvas = document.getElementById('trafficChart');

    if (!canvas) {
        console.error('Traffic chart canvas not found');
        return;
    }

    // Destroy ALL Chart.js instances on this canvas
    const chartInstances = Chart.getChart(canvas);
    if (chartInstances) {
        try {
            chartInstances.destroy();
        } catch (e) {
            console.error('Error destroying chart instance from canvas:', e);
        }
    }

    // Destroy previous chart instance
    if (mainChart) {
        try {
            mainChart.destroy();
            mainChart = null;
        } catch (e) {
            console.error('Error destroying main chart variable:', e);
        }
    }

    if (data) {
        mainChartData = data;
    }

    if (!mainChartData || mainChartData.length === 0) {
        console.log('No data for main chart');
        return;
    }

    // Clear canvas completely
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.beginPath();

    const labels = mainChartData.map(d => {
        const date = new Date(d.date);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    });

    const visitors = mainChartData.map(d => parseInt(d.visitors || 0));
    const pageviews = mainChartData.map(d => parseInt(d.pageviews || 0));

    let datasets = [];

    if (filter === 'all' || filter === 'visitors') {
        datasets.push({
            label: 'Visitors',
            data: visitors,
            backgroundColor: '#3b82f6',
            borderRadius: 4,
            maxBarThickness: 50
        });
    }

    if (filter === 'all' || filter === 'views') {
        datasets.push({
            label: 'Views',
            data: pageviews,
            backgroundColor: '#93c5fd',
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
                            size: 13,
                            family: 'Inter',
                            weight: 500
                        },
                        color: '#6b7280'
                    }
                },
                tooltip: {
                    backgroundColor: '#111827',
                    padding: 12,
                    titleFont: {
                        size: 13,
                        family: 'Inter',
                        weight: 600
                    },
                    bodyFont: {
                        size: 13,
                        family: 'Inter'
                    },
                    cornerRadius: 8,
                    displayColors: true
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    border: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 12,
                            family: 'Inter'
                        },
                        color: '#9ca3af'
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: '#e5e7eb',
                        drawBorder: false
                    },
                    border: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 12,
                            family: 'Inter'
                        },
                        color: '#9ca3af',
                        callback: function(value) {
                            return formatNumber(value);
                        }
                    }
                }
            }
        }
    });
}

// Render Pages Table
function renderPagesTable(data) {
    const tbody = document.querySelector('#pagesTable tbody');

    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" class="loading">No data available</td></tr>';
        return;
    }

    tbody.innerHTML = data.slice(0, 10).map(page => `
        <tr>
            <td>${page.page_url}</td>
            <td>${formatNumber(parseInt(page.views))}</td>
            <td>${formatNumber(parseInt(page.visitors))}</td>
        </tr>
    `).join('');
}

// Render Referrers Table
function renderReferrersTable(data) {
    const tbody = document.querySelector('#referrersTable tbody');

    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" class="loading">No data available</td></tr>';
        return;
    }

    const total = data.reduce((sum, ref) => sum + parseInt(ref.visitors || 0), 0);

    tbody.innerHTML = data.slice(0, 10).map(ref => {
        const visitors = parseInt(ref.visitors || 0);
        const percentage = total > 0 ? (visitors / total) * 100 : 0;

        return `
            <tr>
                <td>${ref.source}</td>
                <td>${formatNumber(visitors)}</td>
                <td>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${percentage}%"></div>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

// Render Simple List (Devices, OS, Browsers)
function renderSimpleList(containerId, data) {
    const container = document.getElementById(containerId);

    if (!data || data.length === 0) {
        container.innerHTML = '<div class="loading">No data available</div>';
        return;
    }

    container.innerHTML = data.slice(0, 8).map(item => {
        const name = item.device_type || item.os || item.browser || 'Unknown';
        const count = parseInt(item.visitors || item.count || 0);

        return `
            <div class="country-item">
                <span class="country-name">${name}</span>
                <span class="country-count">${formatNumber(count)}</span>
            </div>
        `;
    }).join('');
}

// Render Countries
function renderCountries(data) {
    const container = document.getElementById('countriesTable');

    if (!data || data.length === 0) {
        container.innerHTML = '<div class="loading">No data available</div>';
        return;
    }

    container.innerHTML = data.slice(0, 10).map(country => `
        <div class="country-item">
            <span class="country-flag">${country.flag || '🌍'}</span>
            <span class="country-name">${country.country_name || country.country_code}</span>
            <span class="country-count">${formatNumber(parseInt(country.visitors))}</span>
        </div>
    `).join('');
}

// Render Events
let eventsRendering = false;
let eventsRenderCount = 0;
let eventsLastDataHash = null;

function renderEvents(data) {
    // Prevent concurrent renders with aggressive locking
    if (eventsRendering) {
        console.warn('[BLOCKED] Events already rendering, aborting call #' + (++eventsRenderCount));
        return;
    }

    // Create data hash to detect duplicate renders of same data
    const dataHash = data ? JSON.stringify(data).substring(0, 100) : 'empty';
    if (eventsLastDataHash === dataHash) {
        console.warn('[BLOCKED] Events table - same data already rendered, skipping duplicate render #' + (++eventsRenderCount));
        return;
    }

    eventsRendering = true;
    eventsLastDataHash = dataHash;
    console.log('[RENDER] Rendering events table, call #' + (++eventsRenderCount));

    try {
        const container = document.getElementById('eventsTable');

        if (!container) {
            console.error('[ERROR] Events table container not found');
            eventsRendering = false;
            return;
        }

        // Clear existing content first
        container.innerHTML = '';

        if (!data || data.length === 0) {
            container.innerHTML = '<div class="loading">No events tracked</div>';
            eventsRendering = false;
            return;
        }

        const total = data.reduce((sum, e) => sum + parseInt(e.count || 0), 0);

        // Build HTML string
        const html = data.slice(0, 10).map(event => {
            const count = parseInt(event.count || 0);
            const percentage = total > 0 ? ((count / total) * 100).toFixed(1) : 0;

            return `
                <div class="event-item">
                    <div class="event-name">
                        ⚡ ${event.event_name || event.name || 'Unknown Event'}
                    </div>
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <span class="event-count">${formatNumber(count)}</span>
                        <span class="event-percent">${percentage}%</span>
                    </div>
                </div>
            `;
        }).join('');

        // Set innerHTML once
        container.innerHTML = html;
        console.log('[SUCCESS] Events table rendered with ' + data.slice(0, 10).length + ' events');

    } catch (error) {
        console.error('[ERROR] Failed to render events:', error);
    } finally {
        eventsRendering = false;
    }
}

// Events Chart
let eventsChart = null;
let eventsChartRendering = false;
let eventsChartRenderCount = 0;
let eventsChartLastDataHash = null;
let eventsChartLastRenderTime = 0;

function renderEventsChart(data) {
    const now = Date.now();
    const timeSinceLastRender = now - eventsChartLastRenderTime;

    // Prevent concurrent renders with aggressive locking
    if (eventsChartRendering) {
        console.warn('[BLOCKED] Events chart already rendering (' + timeSinceLastRender + 'ms since last), aborting call #' + (++eventsChartRenderCount));
        return;
    }

    // Create data hash to detect duplicate renders of same data
    const dataHash = data ? JSON.stringify(data).substring(0, 100) : 'empty';
    if (eventsChartLastDataHash === dataHash) {
        console.warn('[BLOCKED] Events chart - same data already rendered (' + timeSinceLastRender + 'ms ago), skipping duplicate render #' + (++eventsChartRenderCount));
        return;
    }

    // Detect suspiciously fast re-renders (less than 100ms) even with different data
    if (timeSinceLastRender < 100 && eventsChartLastRenderTime > 0) {
        console.error('[WARNING] ⚠️  Events chart called again after only ' + timeSinceLastRender + 'ms! Possible infinite loop!');
    }

    eventsChartRendering = true;
    eventsChartLastDataHash = dataHash;
    eventsChartLastRenderTime = now;
    console.log('[RENDER] Rendering events chart, call #' + (++eventsChartRenderCount) + ' (last render: ' + timeSinceLastRender + 'ms ago)');

    try {
        // Find the canvas container
        const canvasContainer = document.getElementById('eventsChart');
        if (!canvasContainer) {
            console.error('[ERROR] Events chart canvas not found');
            eventsChartRendering = false;
            eventsChartLastDataHash = null;
            return;
        }

        // Get parent element
        const parent = canvasContainer.parentElement;
        if (!parent) {
            console.error('[ERROR] Canvas parent not found');
            eventsChartRendering = false;
            eventsChartLastDataHash = null;
            return;
        }

        // Destroy ALL Chart.js instances on this canvas
        const chartInstances = Chart.getChart(canvasContainer);
        if (chartInstances) {
            try {
                console.log('[CLEANUP] Destroying orphaned chart instance');
                chartInstances.destroy();
            } catch (e) {
                console.error('[ERROR] Failed to destroy chart instance:', e);
            }
        }

        // Destroy previous chart instance completely
        if (eventsChart) {
            try {
                console.log('[CLEANUP] Destroying previous events chart');
                eventsChart.destroy();
                eventsChart = null;
            } catch (e) {
                console.error('[ERROR] Failed to destroy events chart variable:', e);
            }
        }

        // NUCLEAR OPTION: Remove old canvas and create fresh one
        console.log('[CLEANUP] Removing old canvas (size: ' + canvasContainer.width + 'x' + canvasContainer.height + ') and creating new one');
        const oldCanvas = canvasContainer;
        const newCanvas = document.createElement('canvas');
        newCanvas.id = 'eventsChart';
        newCanvas.height = 200;
        newCanvas.style.maxHeight = '200px'; // Prevent canvas from growing

        // Replace old canvas with new one
        parent.replaceChild(newCanvas, oldCanvas);

        const canvas = newCanvas;
        const ctx = canvas.getContext('2d');

        console.log('[CANVAS] New canvas created with size: ' + canvas.width + 'x' + canvas.height);

        if (!data || data.length === 0) {
            console.log('[INFO] No data for events chart');
            eventsChartRendering = false;
            return;
        }

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
                    backgroundColor: '#a78bfa',
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
                        cornerRadius: 8
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        border: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11,
                                family: 'Inter'
                            },
                            color: '#9ca3af',
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
                            color: '#e5e7eb',
                            drawBorder: false
                        },
                        border: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11,
                                family: 'Inter'
                            },
                            color: '#9ca3af'
                        }
                    }
                }
            }
        });

        console.log('[SUCCESS] Events chart rendered with ' + eventsByHour.reduce((sum, val) => sum + val, 0) + ' total events');

    } catch (error) {
        console.error('[ERROR] Failed to render events chart:', error);
    } finally {
        eventsChartRendering = false;
    }
}

// Load Dashboard Data
let dashboardData = null;
let dashboardLoading = false;
let dashboardLoadCount = 0;

async function loadDashboardData() {
    // Prevent concurrent data loads
    if (dashboardLoading) {
        console.warn('[BLOCKED] Dashboard already loading, aborting call #' + (++dashboardLoadCount));
        return;
    }

    dashboardLoading = true;
    console.log('[API] Loading dashboard data, call #' + (++dashboardLoadCount) + '...');

    try {
        const response = await fetch(`${API_ENDPOINT}?action=dashboard&start=${startDate}&end=${endDate}`);
        const data = await response.json();

        dashboardData = data;

        // Update metrics
        updateMetric('totalVisitors', 'visitorsChange', formatNumber(data.metrics.visitors), data.metrics.visitorsChange);
        updateMetric('totalVisits', 'visitsChange', formatNumber(data.metrics.visits || data.metrics.visitors), data.metrics.visitsChange);
        updateMetric('totalViews', 'viewsChange', formatNumber(data.metrics.pageviews), data.metrics.pageviewsChange);
        updateMetric('bounceRate', 'bounceChange', data.metrics.bounceRate + '%', data.metrics.bounceChange, true);
        updateMetric('avgDuration', 'durationChange', formatDuration(data.metrics.avgTime), data.metrics.timeChange);

        // Render chart
        renderMainChart(data.traffic);

        // Render tables
        renderPagesTable(data.topPages);
        renderReferrersTable(data.topReferrers);
        renderSimpleList('devicesTable', data.devices);
        renderSimpleList('osTable', data.topOS);
        renderSimpleList('browsersTable', data.topBrowsers);
        renderCountries(data.topCountries);

        // Render events
        if (data.events) {
            console.log('[DATA] Events data available, rendering...');
            renderEvents(data.events);
            renderEventsChart(data.eventsByHour || data.events);
        } else {
            console.log('[DATA] No events data available');
        }

        console.log('[API] ✓ Dashboard data loaded successfully');

    } catch (error) {
        console.error('[API ERROR] Failed to load dashboard:', error);
    } finally {
        dashboardLoading = false;
    }
}

// Export to CSV
function exportToCSV() {
    if (!dashboardData) {
        alert('No data available to export');
        return;
    }

    let csv = 'Analytics Report - caminhoit.com\n';
    csv += `Period: ${startDate} to ${endDate}\n\n`;

    csv += 'Summary Metrics\n';
    csv += 'Metric,Value\n';
    csv += `Visitors,${dashboardData.metrics.visitors}\n`;
    csv += `Page Views,${dashboardData.metrics.pageviews}\n`;
    csv += `Avg Time on Site,${formatDuration(dashboardData.metrics.avgTime)}\n`;
    csv += `Bounce Rate,${dashboardData.metrics.bounceRate}%\n\n`;

    csv += 'Top Pages\n';
    csv += 'Page,Views,Visitors\n';
    dashboardData.topPages?.forEach(page => {
        csv += `"${page.page_url}",${page.views},${page.visitors}\n`;
    });
    csv += '\n';

    csv += 'Top Referrers\n';
    csv += 'Source,Visitors\n';
    dashboardData.topReferrers?.forEach(ref => {
        csv += `"${ref.source}",${ref.visitors}\n`;
    });
    csv += '\n';

    csv += 'Top Countries\n';
    csv += 'Country,Visitors\n';
    dashboardData.topCountries?.forEach(country => {
        csv += `"${country.country_name}",${country.visitors}\n`;
    });

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

    doc.setFontSize(20);
    doc.text('Analytics Report', 20, 20);

    doc.setFontSize(12);
    doc.text('caminhoit.com', 20, 30);

    doc.setFontSize(10);
    doc.text(`Period: ${startDate} to ${endDate}`, 20, 38);

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

    doc.setFontSize(8);
    doc.text(`Generated on ${new Date().toLocaleString()}`, 20, 285);

    doc.save(`analytics-report-${Date.now()}.pdf`);
}

// Initialize
let isInitialized = false;
let initCount = 0;

document.addEventListener('DOMContentLoaded', function() {
    initCount++;
    console.log('[INIT] DOMContentLoaded fired, attempt #' + initCount);

    if (isInitialized) {
        console.warn('[INIT] ⚠️  Dashboard already initialized, BLOCKING duplicate initialization!');
        return;
    }

    isInitialized = true;
    console.log('[INIT] ✓ Initializing dashboard (first time)...');

    // Check for duplicate containers
    const eventsContainers = document.querySelectorAll('#eventsTable');
    const eventsCanvases = document.querySelectorAll('#eventsChart');
    if (eventsContainers.length > 1) {
        console.error('[INIT] ⚠️  FOUND ' + eventsContainers.length + ' #eventsTable containers! Should be 1!');
    }
    if (eventsCanvases.length > 1) {
        console.error('[INIT] ⚠️  FOUND ' + eventsCanvases.length + ' #eventsChart canvases! Should be 1!');
    }

    // Initialize Lucide icons once
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
        console.log('[INIT] Lucide icons initialized');
    }

    // Load dashboard data once
    console.log('[INIT] Calling loadDashboardData()...');
    loadDashboardData();

    // Chart filter buttons - attach listeners only once
    const filterButtons = document.querySelectorAll('.filter-btn');
    console.log('[INIT] Attaching listeners to ' + filterButtons.length + ' filter buttons');
    filterButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const metric = this.getAttribute('data-metric');
            console.log('[FILTER] Switching chart filter to: ' + metric);

            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            if (mainChartData) {
                renderMainChart(null, metric);
            }
        }, { once: false });
    });

    console.log('[INIT] ✓✓✓ Dashboard initialization COMPLETE ✓✓✓');
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>

</body>
</html>
