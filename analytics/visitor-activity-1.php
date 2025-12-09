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
        body { background-color: #f8fafc; padding-top: 80px; }
        .main-container { max-width: 1400px; margin: 2rem auto; padding: 0 1rem; }
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
        .ip-badge {
            font-family: 'Courier New', monospace;
            background: #f3f4f6;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="main-container">

    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-person-lines-fill me-3"></i>Visitor Activity</h1>
                <p class="mb-0 opacity-90">Detailed visitor journey and behavior tracking</p>
            </div>
            <a href="/analytics/" class="btn btn-light">
                <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>
    </div>

    <?php if ($visitor_id): ?>
        <?php
        // Fetch visitor info safely with aggregate functions (avoiding ONLY_FULL_GROUP_BY issue)
        $stmt = $pdo->prepare("
            SELECT 
                visitor_id,
                MIN(ip_address) AS ip_address,
                MAX(isp) AS isp,
                MAX(organization) AS organization,
                MAX(country_name) AS country_name,
                MAX(country_code) AS country_code,
                MAX(region) AS region,
                MAX(city) AS city,
                MAX(browser) AS browser,
                MAX(os) AS os,
                MAX(device_type) AS device_type,
                MIN(viewed_at) AS first_visit,
                MAX(viewed_at) AS last_visit,
                COUNT(*) AS total_pageviews
            FROM analytics_pageviews
            WHERE visitor_id = ?
            GROUP BY visitor_id
        ");
        $stmt->execute([$visitor_id]);
        $visitor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$visitor):
        ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>Visitor not found.
            </div>
        <?php else: ?>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="info-card">
                        <h5 class="mb-3"><i class="bi bi-person-badge me-2"></i>Visitor Information</h5>
                        <table class="table table-sm">
                            <tr><th width="40%">Visitor ID:</th><td><code><?= htmlspecialchars($visitor['visitor_id']) ?></code></td></tr>
                            <tr><th>IP Address:</th><td><span class="ip-badge"><?= htmlspecialchars($visitor['ip_address']) ?></span></td></tr>
                            <tr><th>ISP:</th><td><?= htmlspecialchars($visitor['isp'] ?? 'Unknown') ?></td></tr>
                            <tr><th>Organization:</th><td><?= htmlspecialchars($visitor['organization'] ?? 'Unknown') ?></td></tr>
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
                            <tr><th width="40%">Device Type:</th><td><span class="badge bg-primary"><?= ucfirst($visitor['device_type']) ?></span></td></tr>
                            <tr><th>Browser:</th><td><?= htmlspecialchars($visitor['browser'] ?? 'Unknown') ?></td></tr>
                            <tr><th>Operating System:</th><td><?= htmlspecialchars($visitor['os'] ?? 'Unknown') ?></td></tr>
                            <tr><th>First Visit:</th><td><?= date('M d, Y H:i:s', strtotime($visitor['first_visit'])) ?></td></tr>
                            <tr><th>Last Visit:</th><td><?= date('M d, Y H:i:s', strtotime($visitor['last_visit'])) ?></td></tr>
                            <tr><th>Total Pageviews:</th><td><strong><?= number_format($visitor['total_pageviews']) ?></strong></td></tr>
                        </table>
                    </div>
                </div>
            </div>

            <div class="info-card">
                <h5 class="mb-4"><i class="bi bi-clock-history me-2"></i>Activity Timeline</h5>
                <?php
                $stmt = $pdo->prepare("
                    SELECT page_url, page_title, referrer, viewed_at, time_on_page, is_entry, is_exit
                    FROM analytics_pageviews
                    WHERE visitor_id = ?
                    ORDER BY viewed_at DESC
                    LIMIT 100
                ");
                $stmt->execute([$visitor_id]);
                $pageviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                                <?php if ($pv['is_entry']): ?><span class="badge bg-success me-1">Entry</span><?php endif; ?>
                                <?php if ($pv['is_exit']): ?><span class="badge bg-danger">Exit</span><?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex gap-3 text-muted small">
                            <span><i class="bi bi-clock me-1"></i><?= date('M d, Y H:i:s', strtotime($pv['viewed_at'])) ?></span>
                            <?php if ($pv['time_on_page']): ?><span><i class="bi bi-hourglass-split me-1"></i><?= formatDuration($pv['time_on_page']) ?></span><?php endif; ?>
                            <?php if ($pv['referrer']): ?><span><i class="bi bi-link-45deg me-1"></i><?= htmlspecialchars(parse_url($pv['referrer'], PHP_URL_HOST) ?? 'Direct') ?></span><?php endif; ?>
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
                        $stmt = $pdo->query("
                            SELECT 
                                visitor_id,
                                MIN(ip_address) AS ip_address,
                                MAX(isp) AS isp,
                                MAX(country_name) AS country_name,
                                MAX(country_code) AS country_code,
                                MAX(city) AS city,
                                MAX(device_type) AS device_type,
                                COUNT(*) AS pageviews,
                                MAX(viewed_at) AS last_visit
                            FROM analytics_pageviews
                            GROUP BY visitor_id
                            ORDER BY last_visit DESC
                            LIMIT 50
                        ");
                        $visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
</body>
</html>
