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

$visitor_id = $_GET['visitor_id'] ?? null;
$page_title = "Visitor Activity | CaminhoIT Analytics";
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

    <style>
        body {
            background-color: #f8fafc;
            padding-top: 80px;
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 4rem 0 6rem;
            margin-bottom: -4rem;
            margin-top: -80px;
            padding-top: calc(4rem + 80px);
            position: relative;
            overflow: hidden;
        }

        .hero-gradient {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.9) 0%, rgba(118, 75, 162, 0.9) 100%);
            z-index: 0;
        }

        .dashboard-hero-content {
            position: relative;
            z-index: 1;
            text-align: center;
            color: white;
        }

        .dashboard-hero-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: white;
        }

        .dashboard-hero-subtitle {
            font-size: 1.15rem;
            opacity: 0.95;
            margin-bottom: 2rem;
        }

        .dashboard-hero-actions {
            display: flex;
            justify-content: center;
            gap: 1rem;
        }
        .main-container {
            max-width: 1400px;
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
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        .timeline-item {
            border-left: 3px solid #667eea;
            padding-left: 1.5rem;
            margin-bottom: 1.5rem;
            position: relative;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -8px;
            top: 0;
            width: 13px;
            height: 13px;
            border-radius: 50%;
            background: #667eea;
            border: 3px solid white;
            box-shadow: 0 0 0 3px #f8fafc;
        }
        .badge-custom {
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .ip-badge {
            font-family: 'Courier New', monospace;
            background: #f3f4f6;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.9rem;
        }

        /* Dark Mode Styles */
        :root.dark body {
            background: #0f172a !important;
        }

        :root.dark .info-card {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .info-card h5 {
            color: #f1f5f9 !important;
        }

        :root.dark .table {
            color: #e2e8f0 !important;
        }

        :root.dark .table th {
            color: #94a3b8 !important;
        }

        :root.dark .table td {
            color: #cbd5e1 !important;
        }

        :root.dark .table tbody tr {
            border-bottom-color: #334155 !important;
        }

        :root.dark .table thead {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
        }

        :root.dark .table thead th {
            color: white !important;
        }

        :root.dark .table-hover tbody tr:hover {
            background: #0f172a !important;
        }

        :root.dark .timeline-item {
            border-left-color: #a78bfa !important;
        }

        :root.dark .timeline-item::before {
            background: #a78bfa !important;
            border-color: #1e293b !important;
            box-shadow: 0 0 0 3px #0f172a !important;
        }

        :root.dark .timeline-item strong {
            color: #f1f5f9 !important;
        }

        :root.dark .text-muted,
        :root.dark small.text-muted {
            color: #94a3b8 !important;
        }

        :root.dark .ip-badge {
            background: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .alert-warning {
            background: #451a03 !important;
            color: #fbbf24 !important;
            border-color: #78350f !important;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="dashboard-hero-content">
            <h1 class="dashboard-hero-title">
                <i class="bi bi-person-lines-fill me-2"></i>
                Visitor Activity
            </h1>
            <p class="dashboard-hero-subtitle">
                Detailed visitor journey and behavior tracking
            </p>
            <div class="dashboard-hero-actions">
                <a href="/analytics/" class="btn btn-light">
                    <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </div>
    </div>
</header>

<div class="main-container">

    <?php if ($visitor_id): ?>
        <?php
        // Get visitor info (check if ISP columns exist)
        $hasISP = false;
        try {
            $pdo->query("SELECT isp FROM analytics_pageviews LIMIT 1");
            $hasISP = true;
        } catch (Exception $e) {
            // ISP columns don't exist yet
        }

        if ($hasISP) {
            $stmt = $pdo->prepare("
                SELECT visitor_id,
                       MAX(ip_address) as ip_address,
                       MAX(isp) as isp,
                       MAX(organization) as organization,
                       MAX(country_name) as country_name,
                       MAX(country_code) as country_code,
                       MAX(region) as region,
                       MAX(city) as city,
                       MAX(browser) as browser,
                       MAX(os) as os,
                       MAX(device_type) as device_type,
                       MIN(viewed_at) as first_visit,
                       MAX(viewed_at) as last_visit,
                       COUNT(*) as total_pageviews
                FROM analytics_pageviews
                WHERE visitor_id = ?
                GROUP BY visitor_id
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT visitor_id,
                       MAX(ip_address) as ip_address,
                       MAX(country_name) as country_name,
                       MAX(country_code) as country_code,
                       MAX(region) as region,
                       MAX(city) as city,
                       MAX(browser) as browser,
                       MAX(os) as os,
                       MAX(device_type) as device_type,
                       MIN(viewed_at) as first_visit,
                       MAX(viewed_at) as last_visit,
                       COUNT(*) as total_pageviews
                FROM analytics_pageviews
                WHERE visitor_id = ?
                GROUP BY visitor_id
            ");
        }

        $stmt->execute([$visitor_id]);
        $visitor = $stmt->fetch();

        if (!$visitor):
        ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>Visitor not found.
            </div>
        <?php else: ?>

            <!-- Visitor Info Card -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="info-card">
                        <h5 class="mb-3"><i class="bi bi-person-badge me-2"></i>Visitor Information</h5>
                        <table class="table table-sm">
                            <tr>
                                <th width="40%">Visitor ID:</th>
                                <td><code><?= htmlspecialchars($visitor['visitor_id']) ?></code></td>
                            </tr>
                            <tr>
                                <th>IP Address:</th>
                                <td><span class="ip-badge"><?= htmlspecialchars($visitor['ip_address']) ?></span></td>
                            </tr>
                            <?php if (isset($visitor['isp'])): ?>
                            <tr>
                                <th>ISP:</th>
                                <td><?= htmlspecialchars($visitor['isp'] ?? 'Unknown') ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (isset($visitor['organization'])): ?>
                            <tr>
                                <th>Organization:</th>
                                <td><?= htmlspecialchars($visitor['organization'] ?? 'Unknown') ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Location:</th>
                                <td>
                                    <?php if ($visitor['country_name']): ?>
                                        <?= getCountryFlag($visitor['country_code']) ?>
                                        <?= htmlspecialchars($visitor['city']) ?>, <?= htmlspecialchars($visitor['region']) ?>, <?= htmlspecialchars($visitor['country_name']) ?>
                                    <?php else: ?>
                                        Unknown
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="info-card">
                        <h5 class="mb-3"><i class="bi bi-laptop me-2"></i>Device & Browser</h5>
                        <table class="table table-sm">
                            <tr>
                                <th width="40%">Device Type:</th>
                                <td>
                                    <span class="badge bg-primary"><?= ucfirst($visitor['device_type']) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th>Browser:</th>
                                <td><?= htmlspecialchars($visitor['browser'] ?? 'Unknown') ?></td>
                            </tr>
                            <tr>
                                <th>Operating System:</th>
                                <td><?= htmlspecialchars($visitor['os'] ?? 'Unknown') ?></td>
                            </tr>
                            <tr>
                                <th>First Visit:</th>
                                <td><?= date('M d, Y H:i:s', strtotime($visitor['first_visit'])) ?></td>
                            </tr>
                            <tr>
                                <th>Last Visit:</th>
                                <td><?= date('M d, Y H:i:s', strtotime($visitor['last_visit'])) ?></td>
                            </tr>
                            <tr>
                                <th>Total Pageviews:</th>
                                <td><strong><?= number_format($visitor['total_pageviews']) ?></strong></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Activity Timeline -->
            <div class="info-card">
                <h5 class="mb-4"><i class="bi bi-clock-history me-2"></i>Activity Timeline</h5>

                <?php
                // Get all pageviews for this visitor
                $stmt = $pdo->prepare("
                    SELECT page_url, page_title, referrer, viewed_at, time_on_page, is_entry, is_exit
                    FROM analytics_pageviews
                    WHERE visitor_id = ?
                    ORDER BY viewed_at DESC
                    LIMIT 100
                ");
                $stmt->execute([$visitor_id]);
                $pageviews = $stmt->fetchAll();

                if (count($pageviews) > 0):
                    foreach ($pageviews as $pv):
                ?>
                    <div class="timeline-item">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <strong><?= htmlspecialchars($pv['page_url']) ?></strong>
                                <?php if ($pv['page_title']): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($pv['page_title']) ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
                                <?php if ($pv['is_entry']): ?>
                                    <span class="badge bg-success me-1">Entry</span>
                                <?php endif; ?>
                                <?php if ($pv['is_exit']): ?>
                                    <span class="badge bg-danger">Exit</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex gap-3 text-muted small">
                            <span><i class="bi bi-clock me-1"></i><?= date('M d, Y H:i:s', strtotime($pv['viewed_at'])) ?></span>
                            <?php if ($pv['time_on_page']): ?>
                                <span><i class="bi bi-hourglass-split me-1"></i><?= formatDuration($pv['time_on_page']) ?></span>
                            <?php endif; ?>
                            <?php if ($pv['referrer']): ?>
                                <span><i class="bi bi-link-45deg me-1"></i><?= htmlspecialchars(parse_url($pv['referrer'], PHP_URL_HOST) ?? 'Direct') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php
                    endforeach;
                else:
                ?>
                    <p class="text-muted">No activity recorded.</p>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    <?php else: ?>

        <!-- List all visitors -->
        <div class="info-card">
            <h5 class="mb-3"><i class="bi bi-people me-2"></i>Recent Visitors</h5>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Visitor ID</th>
                            <th>IP Address</th>
                            <th>ISP</th>
                            <th>Location</th>
                            <th>Device</th>
                            <th>Pageviews</th>
                            <th>Last Visit</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Check if ISP columns exist for visitor list
                        $hasISPList = false;
                        try {
                            $pdo->query("SELECT isp FROM analytics_pageviews LIMIT 1");
                            $hasISPList = true;
                        } catch (Exception $e) {
                            // ISP column doesn't exist
                        }

                        if ($hasISPList) {
                            $stmt = $pdo->query("
                                SELECT visitor_id,
                                       MAX(ip_address) as ip_address,
                                       MAX(isp) as isp,
                                       MAX(country_name) as country_name,
                                       MAX(country_code) as country_code,
                                       MAX(city) as city,
                                       MAX(device_type) as device_type,
                                       COUNT(*) as pageviews,
                                       MAX(viewed_at) as last_visit
                                FROM analytics_pageviews
                                GROUP BY visitor_id
                                ORDER BY last_visit DESC
                                LIMIT 50
                            ");
                        } else {
                            $stmt = $pdo->query("
                                SELECT visitor_id,
                                       MAX(ip_address) as ip_address,
                                       MAX(country_name) as country_name,
                                       MAX(country_code) as country_code,
                                       MAX(city) as city,
                                       MAX(device_type) as device_type,
                                       COUNT(*) as pageviews,
                                       MAX(viewed_at) as last_visit
                                FROM analytics_pageviews
                                GROUP BY visitor_id
                                ORDER BY last_visit DESC
                                LIMIT 50
                            ");
                        }
                        $visitors = $stmt->fetchAll();

                        foreach ($visitors as $v):
                        ?>
                            <tr>
                                <td><code class="small"><?= substr($v['visitor_id'], 0, 8) ?>...</code></td>
                                <td><span class="ip-badge"><?= htmlspecialchars($v['ip_address']) ?></span></td>
                                <td><?= htmlspecialchars($v['isp'] ?? 'Unknown') ?></td>
                                <td>
                                    <?php if ($v['country_name']): ?>
                                        <?= getCountryFlag($v['country_code']) ?>
                                        <?= htmlspecialchars($v['city'] ?? '') ?>, <?= htmlspecialchars($v['country_code']) ?>
                                    <?php else: ?>
                                        Unknown
                                    <?php endif; ?>
                                </td>
                                <td><?= ucfirst($v['device_type']) ?></td>
                                <td><?= number_format($v['pageviews']) ?></td>
                                <td><?= date('M d, H:i', strtotime($v['last_visit'])) ?></td>
                                <td>
                                    <a href="?visitor_id=<?= urlencode($v['visitor_id']) ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<?php
function formatDuration($seconds) {
    if (!$seconds) return '0s';
    $mins = floor($seconds / 60);
    $secs = $seconds % 60;
    return $mins > 0 ? "{$mins}m {$secs}s" : "{$secs}s";
}

function getCountryFlag($countryCode) {
    if (!$countryCode || strlen($countryCode) != 2) return '🌍';
    $offset = 127397;
    return mb_chr($offset + ord($countryCode[0])) . mb_chr($offset + ord($countryCode[1]));
}
?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>

</body>
</html>
