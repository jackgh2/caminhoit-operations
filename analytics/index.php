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

$page_title = "Analytics Dashboard | CaminhoIT";
?>

<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">

    <!-- Chart.js for visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- Leaflet for maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        body {
            background-color: #f8fafc;
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 4rem 0 6rem;
            margin-bottom: -4rem;
            margin-top: -56px;
            padding-top: calc(4rem + 56px);
            position: relative;
            overflow: hidden;
            border-bottom-left-radius: 20px;
            border-bottom-right-radius: 20px;
        }

        .hero-gradient {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.9) 0%, rgba(118, 75, 162, 0.9) 100%);
            z-index: 0;
            border-bottom-left-radius: 20px;
            border-bottom-right-radius: 20px;
        }

        .main-container {
            max-width: 1600px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
        }
        .stat-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transform: translateY(-2px);
        }
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
            margin: 0.5rem 0;
        }
        .stat-label {
            color: #6b7280;
            font-size: 0.875rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-change {
            font-size: 0.875rem;
            font-weight: 600;
        }
        .stat-change.positive {
            color: #10b981;
        }
        .stat-change.negative {
            color: #ef4444;
        }
        .chart-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        .chart-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        #worldMap {
            height: 500px;
            border-radius: 8px;
        }
        .heatmap-cell {
            cursor: pointer;
            transition: all 0.2s;
        }
        .heatmap-cell:hover {
            opacity: 0.8;
            transform: scale(1.05);
        }
        .realtime-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.5;
            }
        }
        .visitor-list {
            max-height: 400px;
            overflow-y: auto;
        }
        .visitor-item {
            padding: 0.75rem;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .visitor-item:last-child {
            border-bottom: none;
        }
        .country-flag {
            font-size: 1.5rem;
        }
        .table-responsive {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        .progress-bar-custom {
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }

        /* Dark Mode Styles */
        :root.dark body {
            background: #0f172a !important;
        }

        :root.dark .stat-card {
            background: #1e293b !important;
            color: #e2e8f0 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3) !important;
        }

        :root.dark .stat-label {
            color: #94a3b8 !important;
        }

        :root.dark .stat-value {
            color: #f1f5f9 !important;
        }

        :root.dark .chart-card {
            background: #1e293b !important;
            color: #e2e8f0 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3) !important;
        }

        :root.dark .chart-title {
            color: #f1f5f9 !important;
        }

        :root.dark .table-responsive {
            background: #1e293b !important;
            color: #e2e8f0 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3) !important;
        }

        :root.dark .table-responsive h5 {
            color: #f1f5f9 !important;
        }

        :root.dark .table {
            color: #e2e8f0 !important;
            background: transparent !important;
        }

        :root.dark .table thead {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
            color: white !important;
        }

        :root.dark .table th {
            color: white !important;
            background: transparent !important;
        }

        :root.dark .table td {
            color: #cbd5e1 !important;
            border-bottom-color: #334155 !important;
            background: transparent !important;
        }

        :root.dark .table tbody tr {
            background: transparent !important;
        }

        :root.dark .table tbody tr:hover {
            background: #0f172a !important;
        }

        :root.dark .visitor-item {
            background: #0f172a !important;
            border-bottom-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .visitor-item:hover {
            background: #334155 !important;
        }

        :root.dark .text-muted,
        :root.dark small.text-muted {
            color: #94a3b8 !important;
        }

        :root.dark h5 {
            color: #f1f5f9 !important;
        }

        :root.dark .progress {
            background: #0f172a !important;
        }

        :root.dark .badge {
            color: white !important;
        }

        :root.dark canvas {
            filter: brightness(0.9);
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<!-- HERO -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="dashboard-hero-content">
            <h1 class="dashboard-hero-title">
                <i class="bi bi-graph-up me-2"></i>
                Website Analytics
            </h1>
            <p class="dashboard-hero-subtitle">
                Monitor Your Analytics Dashboard Performance
            </p>
            <p class="dashboard-hero-subtitle">
                Track visitors, analyze behavior, and monitor performance with comprehensive analytics tools.
            </p>
            <div class="dashboard-hero-actions">
                <select class="form-select" id="periodSelect" onchange="window.location.href='?period='+this.value" style="max-width: 200px; background: rgba(255,255,255,0.2); color: white; border-color: rgba(255,255,255,0.3);">
                    <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="7d" <?= $period === '7d' ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="30d" <?= $period === '30d' ? 'selected' : '' ?>>Last 30 Days</option>
                    <option value="90d" <?= $period === '90d' ? 'selected' : '' ?>>Last 90 Days</option>
                </select>
                <a href="/analytics/visitor-activity.php" class="btn c-btn-ghost">
                    <i class="bi bi-person-lines-fill me-1"></i>
                    Visitor Activity
                </a>
            </div>
        </div>
    </div>
</header>

<div class="main-container">

    <!-- Real-time Visitors -->
    <div class="chart-card mb-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">
                <span class="realtime-indicator"></span>
                <span class="ms-2">Active Visitors: <strong id="activeCount">0</strong></span>
            </h5>
            <button class="btn btn-sm btn-outline-primary" onclick="refreshRealtime()">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
        </div>
        <div class="visitor-list" id="activeVisitors">
            Loading...
        </div>
    </div>

    <!-- Key Metrics -->
    <div class="row g-3 mb-4" id="keyMetrics">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">
                    <i class="bi bi-eye me-1"></i> Page Views
                </div>
                <div class="stat-value" id="totalPageviews">-</div>
                <div class="stat-change" id="pageviewsChange"></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">
                    <i class="bi bi-people me-1"></i> Unique Visitors
                </div>
                <div class="stat-value" id="totalVisitors">-</div>
                <div class="stat-change" id="visitorsChange"></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">
                    <i class="bi bi-clock me-1"></i> Avg. Time on Site
                </div>
                <div class="stat-value" id="avgTime">-</div>
                <div class="stat-change" id="timeChange"></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">
                    <i class="bi bi-graph-down me-1"></i> Bounce Rate
                </div>
                <div class="stat-value" id="bounceRate">-</div>
                <div class="stat-change" id="bounceChange"></div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="chart-card">
                <h5 class="chart-title">Traffic Overview</h5>
                <canvas id="trafficChart" height="80"></canvas>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-card">
                <h5 class="chart-title">Device Breakdown</h5>
                <canvas id="deviceChart"></canvas>
            </div>
        </div>
    </div>

    <!-- World Map -->
    <div class="chart-card mb-4">
        <h5 class="chart-title">
            <i class="bi bi-globe me-2"></i>Visitors by Location
        </h5>
        <div id="worldMap"></div>
    </div>

    <!-- Traffic Heatmap -->
    <div class="chart-card mb-4">
        <h5 class="chart-title">
            <i class="bi bi-calendar-heat me-2"></i>Traffic Heatmap (Hour x Day)
        </h5>
        <canvas id="heatmapChart" height="60"></canvas>
    </div>

    <!-- Data Tables Row -->
    <div class="row g-3 mb-4">
        <!-- Top Pages -->
        <div class="col-lg-6">
            <div class="table-responsive">
                <h5 class="mb-3"><i class="bi bi-file-text me-2"></i>Top Pages</h5>
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Page</th>
                            <th>Views</th>
                            <th>Visitors</th>
                            <th>Avg. Time</th>
                        </tr>
                    </thead>
                    <tbody id="topPages">
                        <tr><td colspan="4" class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top Referrers -->
        <div class="col-lg-6">
            <div class="table-responsive">
                <h5 class="mb-3"><i class="bi bi-link-45deg me-2"></i>Top Referrers</h5>
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Source</th>
                            <th>Visitors</th>
                            <th>%</th>
                        </tr>
                    </thead>
                    <tbody id="topReferrers">
                        <tr><td colspan="3" class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- More Data Tables -->
    <div class="row g-3 mb-4">
        <!-- Countries -->
        <div class="col-lg-4">
            <div class="table-responsive">
                <h5 class="mb-3"><i class="bi bi-geo-alt me-2"></i>Top Countries</h5>
                <table class="table table-sm">
                    <tbody id="topCountries">
                        <tr><td class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Browsers -->
        <div class="col-lg-4">
            <div class="table-responsive">
                <h5 class="mb-3"><i class="bi bi-browser-chrome me-2"></i>Browsers</h5>
                <table class="table table-sm">
                    <tbody id="topBrowsers">
                        <tr><td class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Operating Systems -->
        <div class="col-lg-4">
            <div class="table-responsive">
                <h5 class="mb-3"><i class="bi bi-laptop me-2"></i>Operating Systems</h5>
                <table class="table table-sm">
                    <tbody id="topOS">
                        <tr><td class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Entry/Exit Pages -->
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="table-responsive">
                <h5 class="mb-3"><i class="bi bi-box-arrow-in-right me-2"></i>Top Entry Pages</h5>
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Page</th>
                            <th>Entries</th>
                            <th>Bounce Rate</th>
                        </tr>
                    </thead>
                    <tbody id="entryPages">
                        <tr><td colspan="3" class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="table-responsive">
                <h5 class="mb-3"><i class="bi bi-box-arrow-right me-2"></i>Top Exit Pages</h5>
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Page</th>
                            <th>Exits</th>
                            <th>Exit Rate</th>
                        </tr>
                    </thead>
                    <tbody id="exitPages">
                        <tr><td colspan="3" class="text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Notable Events -->
    <div class="chart-card">
        <h5 class="chart-title"><i class="bi bi-star me-2"></i>Notable Events & Anomalies</h5>
        <div id="notableEvents">
            <p class="text-muted">Analyzing traffic patterns...</p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const API_ENDPOINT = '/analytics/api.php';
const period = '<?= $period ?>';
const startDate = '<?= $startDate ?>';
const endDate = '<?= $endDate ?>';

// Initialize dashboard
document.addEventListener('DOMContentLoaded', function() {
    loadDashboardData();
    loadRealTimeVisitors();

    // Refresh real-time data every 15 seconds
    setInterval(loadRealTimeVisitors, 15000);
});

async function loadDashboardData() {
    try {
        const response = await fetch(`${API_ENDPOINT}?action=dashboard&start=${startDate}&end=${endDate}`);
        const data = await response.json();

        updateKeyMetrics(data.metrics);
        renderTrafficChart(data.traffic);
        renderDeviceChart(data.devices);
        renderWorldMap(data.locations);
        renderHeatmap(data.heatmap);
        renderTopPages(data.topPages);
        renderTopReferrers(data.topReferrers);
        renderTopCountries(data.topCountries);
        renderTopBrowsers(data.topBrowsers);
        renderTopOS(data.topOS);
        renderEntryPages(data.entryPages);
        renderExitPages(data.exitPages);
        renderNotableEvents(data.events);
    } catch (error) {
        console.error('Error loading dashboard:', error);
    }
}

async function loadRealTimeVisitors() {
    try {
        const response = await fetch(`${API_ENDPOINT}?action=realtime`);
        const data = await response.json();

        document.getElementById('activeCount').textContent = data.count;

        const container = document.getElementById('activeVisitors');
        if (data.visitors.length === 0) {
            container.innerHTML = '<p class="text-center text-muted py-3">No active visitors right now</p>';
            return;
        }

        container.innerHTML = data.visitors.map(v => `
            <div class="visitor-item">
                <span class="country-flag">${v.flag || '🌍'}</span>
                <div class="flex-grow-1">
                    <div class="fw-bold">${v.current_page || '/'}</div>
                    <small class="text-muted">
                        ${v.city || 'Unknown'}, ${v.country_code || '??'} •
                        ${v.device_type} •
                        ${v.seconds_ago}s ago
                    </small>
                </div>
            </div>
        `).join('');
    } catch (error) {
        console.error('Error loading real-time visitors:', error);
    }
}

function refreshRealtime() {
    loadRealTimeVisitors();
}

function updateKeyMetrics(metrics) {
    document.getElementById('totalPageviews').textContent = formatNumber(metrics.pageviews);
    document.getElementById('totalVisitors').textContent = formatNumber(metrics.visitors);
    document.getElementById('avgTime').textContent = formatDuration(metrics.avgTime);
    document.getElementById('bounceRate').textContent = metrics.bounceRate + '%';

    // Show changes
    updateChange('pageviewsChange', metrics.pageviewsChange);
    updateChange('visitorsChange', metrics.visitorsChange);
    updateChange('timeChange', metrics.timeChange);
    updateChange('bounceChange', metrics.bounceChange, true);
}

function updateChange(elementId, value, inverse = false) {
    const el = document.getElementById(elementId);
    if (!value) {
        el.textContent = '';
        return;
    }

    const isPositive = inverse ? value < 0 : value > 0;
    el.className = 'stat-change ' + (isPositive ? 'positive' : 'negative');
    el.innerHTML = `<i class="bi bi-arrow-${isPositive ? 'up' : 'down'}"></i> ${Math.abs(value)}% vs previous period`;
}

function formatNumber(num) {
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
    return num.toString();
}

function formatDuration(seconds) {
    if (!seconds) return '0s';
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;
}

// Chart instances
let trafficChart = null;
let deviceChart = null;
let heatmapChart = null;

// Render Traffic Chart
function renderTrafficChart(data) {
    const ctx = document.getElementById('trafficChart').getContext('2d');

    if (trafficChart) {
        trafficChart.destroy();
    }

    const labels = data.map(d => new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
    const pageviews = data.map(d => parseInt(d.pageviews));
    const visitors = data.map(d => parseInt(d.visitors));

    trafficChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Page Views',
                    data: pageviews,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Unique Visitors',
                    data: visitors,
                    borderColor: '#764ba2',
                    backgroundColor: 'rgba(118, 75, 162, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return formatNumber(value);
                        }
                    }
                }
            }
        }
    });
}

// Render Device Chart
function renderDeviceChart(data) {
    const ctx = document.getElementById('deviceChart').getContext('2d');

    if (deviceChart) {
        deviceChart.destroy();
    }

    const labels = data.map(d => d.device_type.charAt(0).toUpperCase() + d.device_type.slice(1));
    const counts = data.map(d => parseInt(d.count));
    const colors = ['#667eea', '#764ba2', '#f093fb', '#4facfe'];

    deviceChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: counts,
                backgroundColor: colors,
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((context.parsed / total) * 100).toFixed(1);
                            return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
}

// Render World Map
let map = null;
function renderWorldMap(data) {
    if (map) {
        map.remove();
    }

    map = L.map('worldMap').setView([20, 0], 2);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 18
    }).addTo(map);

    // Add markers for each location
    data.forEach(location => {
        if (location.latitude && location.longitude) {
            const marker = L.circleMarker([parseFloat(location.latitude), parseFloat(location.longitude)], {
                radius: Math.min(8 + Math.log(parseInt(location.visitors)) * 2, 20),
                fillColor: '#667eea',
                color: '#fff',
                weight: 2,
                opacity: 1,
                fillOpacity: 0.7
            });

            marker.bindPopup(`
                <strong>${location.city || 'Unknown'}, ${location.country_name || location.country_code}</strong><br>
                Visitors: ${location.visitors}
            `);

            marker.addTo(map);
        }
    });

    // Fit bounds if we have data
    if (data.length > 0) {
        const bounds = data
            .filter(d => d.latitude && d.longitude)
            .map(d => [parseFloat(d.latitude), parseFloat(d.longitude)]);
        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [50, 50] });
        }
    }
}

// Render Traffic Heatmap
function renderHeatmap(data) {
    const ctx = document.getElementById('heatmapChart').getContext('2d');

    if (heatmapChart) {
        heatmapChart.destroy();
    }

    // Create matrix data
    const matrix = [];
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    // Initialize matrix with zeros
    for (let hour = 0; hour < 24; hour++) {
        matrix[hour] = Array(7).fill(0);
    }

    // Fill matrix with actual data
    data.forEach(item => {
        const dayIndex = parseInt(item.day_of_week) - 1; // MySQL DAYOFWEEK is 1-7
        const hour = parseInt(item.hour);
        matrix[hour][dayIndex] = parseInt(item.views);
    });

    // Find max value for color scaling
    const maxViews = Math.max(...matrix.flat());

    // Create datasets for each hour
    const datasets = matrix.map((hourData, hour) => {
        return {
            label: `${hour}:00`,
            data: hourData,
            backgroundColor: hourData.map(views => {
                const intensity = maxViews > 0 ? views / maxViews : 0;
                return `rgba(102, 126, 234, ${0.1 + intensity * 0.8})`;
            }),
            borderWidth: 1,
            borderColor: '#fff'
        };
    });

    heatmapChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: days,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        title: function(items) {
                            const day = days[items[0].dataIndex];
                            const hour = items[0].dataset.label;
                            return `${day} at ${hour}`;
                        },
                        label: function(context) {
                            return `${context.parsed.y} views`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: {
                        display: false
                    }
                },
                y: {
                    stacked: true,
                    ticks: {
                        callback: function(value, index) {
                            return index % 3 === 0 ? value + ':00' : '';
                        }
                    }
                }
            }
        }
    });
}

// Render Top Pages
function renderTopPages(data) {
    const tbody = document.getElementById('topPages');
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No data available</td></tr>';
        return;
    }

    tbody.innerHTML = data.map(page => `
        <tr>
            <td><code>${page.page_url}</code></td>
            <td>${formatNumber(parseInt(page.views))}</td>
            <td>${formatNumber(parseInt(page.visitors))}</td>
            <td>${formatDuration(parseInt(page.avg_time || 0))}</td>
        </tr>
    `).join('');
}

// Render Top Referrers
function renderTopReferrers(data) {
    const tbody = document.getElementById('topReferrers');
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No data available</td></tr>';
        return;
    }

    tbody.innerHTML = data.map(ref => `
        <tr>
            <td>${ref.source}</td>
            <td>${formatNumber(parseInt(ref.visitors))}</td>
            <td>
                <div class="d-flex align-items-center">
                    <div class="progress flex-grow-1 me-2" style="height: 20px;">
                        <div class="progress-bar progress-bar-custom" role="progressbar"
                             style="width: ${ref.percentage}%"
                             aria-valuenow="${ref.percentage}" aria-valuemin="0" aria-valuemax="100">
                        </div>
                    </div>
                    <span>${ref.percentage}%</span>
                </div>
            </td>
        </tr>
    `).join('');
}

// Render Top Countries
function renderTopCountries(data) {
    const tbody = document.getElementById('topCountries');
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td class="text-center text-muted">No data available</td></tr>';
        return;
    }

    tbody.innerHTML = data.map(country => `
        <tr>
            <td>
                <span class="country-flag me-2">${country.flag || '🌍'}</span>
                ${country.country_name || country.country_code}
                <span class="float-end badge bg-primary">${formatNumber(parseInt(country.visitors))}</span>
            </td>
        </tr>
    `).join('');
}

// Render Top Browsers
function renderTopBrowsers(data) {
    const tbody = document.getElementById('topBrowsers');
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td class="text-center text-muted">No data available</td></tr>';
        return;
    }

    tbody.innerHTML = data.map(browser => `
        <tr>
            <td>
                ${browser.browser}
                <span class="float-end badge bg-primary">${formatNumber(parseInt(browser.visitors))}</span>
            </td>
        </tr>
    `).join('');
}

// Render Top OS
function renderTopOS(data) {
    const tbody = document.getElementById('topOS');
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td class="text-center text-muted">No data available</td></tr>';
        return;
    }

    tbody.innerHTML = data.map(os => `
        <tr>
            <td>
                ${os.os}
                <span class="float-end badge bg-primary">${formatNumber(parseInt(os.visitors))}</span>
            </td>
        </tr>
    `).join('');
}

// Render Entry Pages
function renderEntryPages(data) {
    const tbody = document.getElementById('entryPages');
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No data available</td></tr>';
        return;
    }

    tbody.innerHTML = data.map(page => `
        <tr>
            <td><code>${page.page_url}</code></td>
            <td>${formatNumber(parseInt(page.entries))}</td>
            <td>${parseFloat(page.bounce_rate || 0).toFixed(1)}%</td>
        </tr>
    `).join('');
}

// Render Exit Pages
function renderExitPages(data) {
    const tbody = document.getElementById('exitPages');
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No data available</td></tr>';
        return;
    }

    tbody.innerHTML = data.map(page => `
        <tr>
            <td><code>${page.page_url}</code></td>
            <td>${formatNumber(parseInt(page.exits))}</td>
            <td>${parseFloat(page.exit_rate || 0).toFixed(1)}%</td>
        </tr>
    `).join('');
}

// Render Notable Events
function renderNotableEvents(data) {
    const container = document.getElementById('notableEvents');
    if (!data || data.length === 0) {
        container.innerHTML = '<p class="text-muted">No notable events detected in this period.</p>';
        return;
    }

    container.innerHTML = data.map(event => {
        const icon = event.type === 'spike' ? 'graph-up-arrow' : 'link-45deg';
        const color = event.type === 'spike' ? 'success' : 'info';
        return `
            <div class="alert alert-${color} d-flex align-items-center mb-2">
                <i class="bi bi-${icon} me-3" style="font-size: 1.5rem;"></i>
                <div>
                    <strong>${new Date(event.date).toLocaleDateString()}</strong>
                    <div>${event.message}</div>
                </div>
            </div>
        `;
    }).join('');
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>

</body>
</html>
