<?php
/**
 * Analytics API
 * Provides data for the dashboard
 */

header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Ensure we have PDO connection
if (!isset($pdo)) {
    error_log('Analytics API: PDO connection not available');
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$action = $_GET['action'] ?? 'dashboard';
$start = $_GET['start'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
$end = $_GET['end'] ?? date('Y-m-d 23:59:59');

try {
    switch ($action) {
        case 'realtime':
            echo json_encode(getRealTimeVisitors($pdo));
            break;

        case 'dashboard':
            echo json_encode([
                'metrics' => getKeyMetrics($pdo, $start, $end),
                'traffic' => getTrafficData($pdo, $start, $end),
                'devices' => getDeviceBreakdown($pdo, $start, $end),
                'locations' => getLocationData($pdo, $start, $end),
                'heatmap' => getHeatmapData($pdo, $start, $end),
                'topPages' => getTopPages($pdo, $start, $end),
                'topReferrers' => getTopReferrers($pdo, $start, $end),
                'topCountries' => getTopCountries($pdo, $start, $end),
                'topBrowsers' => getTopBrowsers($pdo, $start, $end),
                'topOS' => getTopOS($pdo, $start, $end),
                'entryPages' => getEntryPages($pdo, $start, $end),
                'exitPages' => getExitPages($pdo, $start, $end),
                'events' => getNotableEvents($pdo, $start, $end)
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('Analytics API error: ' . $e->getMessage());
    error_log('Analytics API trace: ' . $e->getTraceAsString());
    http_response_code(500);
    // Temporarily show detailed error for debugging
    echo json_encode([
        'error' => 'Server error',
        'debug' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

// Real-time active visitors
function getRealTimeVisitors($pdo) {
    // Clean up stale visitors (not seen in 10 minutes)
    $stmt = $pdo->prepare("DELETE FROM analytics_active_visitors WHERE last_seen < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $stmt->execute();

    // Get active visitors
    $stmt = $pdo->prepare("
        SELECT visitor_id, current_page, country_code, city, device_type,
               TIMESTAMPDIFF(SECOND, last_seen, NOW()) as seconds_ago
        FROM analytics_active_visitors
        ORDER BY last_seen DESC
        LIMIT 50
    ");
    $stmt->execute();
    $visitors = $stmt->fetchAll();

    // Add country flags
    foreach ($visitors as &$v) {
        $v['flag'] = getCountryFlag($v['country_code']);
    }

    return [
        'count' => count($visitors),
        'visitors' => $visitors
    ];
}

// Key metrics
function getKeyMetrics($pdo, $start, $end) {
    // Current period
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as pageviews,
            COUNT(DISTINCT visitor_id) as visitors,
            COUNT(DISTINCT session_id) as sessions,
            AVG(time_on_page) as avg_time,
            SUM(CASE WHEN is_bounce = 1 THEN 1 ELSE 0 END) / COUNT(DISTINCT session_id) * 100 as bounce_rate
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
    ");
    $stmt->execute([$start, $end]);
    $current = $stmt->fetch();

    // Previous period (for comparison)
    $dateDiff = (strtotime($end) - strtotime($start));
    $prevStart = date('Y-m-d H:i:s', strtotime($start) - $dateDiff);
    $prevEnd = date('Y-m-d H:i:s', strtotime($end) - $dateDiff);

    $stmt->execute([$prevStart, $prevEnd]);
    $previous = $stmt->fetch();

    return [
        'pageviews' => (int)$current['pageviews'],
        'visitors' => (int)$current['visitors'],
        'sessions' => (int)$current['sessions'],
        'avgTime' => round($current['avg_time'] ?? 0),
        'bounceRate' => round($current['bounce_rate'] ?? 0, 1),
        'pageviewsChange' => calculateChange($current['pageviews'], $previous['pageviews']),
        'visitorsChange' => calculateChange($current['visitors'], $previous['visitors']),
        'timeChange' => calculateChange($current['avg_time'], $previous['avg_time']),
        'bounceChange' => calculateChange($current['bounce_rate'], $previous['bounce_rate'])
    ];
}

// Traffic over time
function getTrafficData($pdo, $start, $end) {
    $stmt = $pdo->prepare("
        SELECT DATE(viewed_at) as date,
               COUNT(*) as pageviews,
               COUNT(DISTINCT visitor_id) as visitors
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
        GROUP BY DATE(viewed_at)
        ORDER BY date
    ");
    $stmt->execute([$start, $end]);

    return $stmt->fetchAll();
}

// Device breakdown
function getDeviceBreakdown($pdo, $start, $end) {
    $stmt = $pdo->prepare("
        SELECT device_type, COUNT(DISTINCT visitor_id) as count
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
        GROUP BY device_type
    ");
    $stmt->execute([$start, $end]);

    return $stmt->fetchAll();
}

// Location data for map
function getLocationData($pdo, $start, $end) {
    $stmt = $pdo->prepare("
        SELECT country_code,
               MAX(country_name) as country_name,
               city,
               latitude,
               longitude,
               COUNT(DISTINCT visitor_id) as visitors
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
          AND latitude IS NOT NULL
          AND longitude IS NOT NULL
        GROUP BY country_code, city, latitude, longitude
        HAVING visitors > 0
        ORDER BY visitors DESC
        LIMIT 500
    ");
    $stmt->execute([$start, $end]);

    return $stmt->fetchAll();
}

// Heatmap (hour x day of week)
function getHeatmapData($pdo, $start, $end) {
    $stmt = $pdo->prepare("
        SELECT DAYOFWEEK(viewed_at) as day_of_week,
               HOUR(viewed_at) as hour,
               COUNT(*) as views
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
        GROUP BY day_of_week, hour
    ");
    $stmt->execute([$start, $end]);

    return $stmt->fetchAll();
}

// Top pages
function getTopPages($pdo, $start, $end) {
    $stmt = $pdo->prepare("
        SELECT page_url,
               COUNT(*) as views,
               COUNT(DISTINCT visitor_id) as visitors,
               AVG(time_on_page) as avg_time
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
        GROUP BY page_url
        ORDER BY views DESC
        LIMIT 10
    ");
    $stmt->execute([$start, $end]);

    return $stmt->fetchAll();
}

// Top referrers
function getTopReferrers($pdo, $start, $end) {
    $stmt = $pdo->prepare("
        SELECT
            CASE
                WHEN referrer IS NULL OR referrer = '' THEN 'Direct'
                ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '/', 3), '://', -1)
            END as source,
            COUNT(DISTINCT visitor_id) as visitors
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
        GROUP BY source
        ORDER BY visitors DESC
        LIMIT 10
    ");
    $stmt->execute([$start, $end]);

    $results = $stmt->fetchAll();
    $total = array_sum(array_column($results, 'visitors'));

    foreach ($results as &$r) {
        $r['percentage'] = $total > 0 ? round(($r['visitors'] / $total) * 100, 1) : 0;
    }

    return $results;
}

// Top countries
function getTopCountries($pdo, $start, $end) {
    $stmt = $pdo->prepare("
        SELECT country_code,
               MAX(country_name) as country_name,
               COUNT(DISTINCT visitor_id) as visitors
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
          AND country_code IS NOT NULL
        GROUP BY country_code
        ORDER BY visitors DESC
        LIMIT 10
    ");
    $stmt->execute([$start, $end]);

    $results = $stmt->fetchAll();
    foreach ($results as &$r) {
        $r['flag'] = getCountryFlag($r['country_code']);
    }

    return $results;
}

// Top browsers
function getTopBrowsers($pdo, $start, $end) {
    $stmt = $pdo->prepare("
        SELECT browser, COUNT(DISTINCT visitor_id) as visitors
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
          AND browser IS NOT NULL
        GROUP BY browser
        ORDER BY visitors DESC
        LIMIT 10
    ");
    $stmt->execute([$start, $end]);

    return $stmt->fetchAll();
}

// Top OS
function getTopOS($pdo, $start, $end) {
    $stmt = $pdo->prepare("
        SELECT os, COUNT(DISTINCT visitor_id) as visitors
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
          AND os IS NOT NULL
        GROUP BY os
        ORDER BY visitors DESC
        LIMIT 10
    ");
    $stmt->execute([$start, $end]);

    return $stmt->fetchAll();
}

// Entry pages
function getEntryPages($pdo, $start, $end) {
    $stmt = $pdo->prepare("
        SELECT page_url,
               COUNT(*) as entries,
               SUM(CASE WHEN is_bounce = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100 as bounce_rate
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
          AND is_entry = 1
        GROUP BY page_url
        ORDER BY entries DESC
        LIMIT 10
    ");
    $stmt->execute([$start, $end]);

    return $stmt->fetchAll();
}

// Exit pages
function getExitPages($pdo, $start, $end) {
    $stmt = $pdo->prepare("
        SELECT page_url,
               COUNT(*) as exits,
               COUNT(*) / (SELECT COUNT(*) FROM analytics_pageviews WHERE viewed_at BETWEEN ? AND ?) * 100 as exit_rate
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
          AND is_exit = 1
        GROUP BY page_url
        ORDER BY exits DESC
        LIMIT 10
    ");
    $stmt->execute([$start, $end, $start, $end]);

    return $stmt->fetchAll();
}

// Notable events and anomalies
function getNotableEvents($pdo, $start, $end) {
    $events = [];

    // Traffic spikes - using self-join for MySQL 5.7 compatibility
    $stmt = $pdo->prepare("
        SELECT t1.date, t1.views, t2.views as prev_views
        FROM (
            SELECT DATE(viewed_at) as date, COUNT(*) as views
            FROM analytics_pageviews
            WHERE viewed_at BETWEEN ? AND ?
            GROUP BY DATE(viewed_at)
        ) t1
        LEFT JOIN (
            SELECT DATE(viewed_at) as date, COUNT(*) as views
            FROM analytics_pageviews
            WHERE viewed_at BETWEEN ? AND ?
            GROUP BY DATE(viewed_at)
        ) t2 ON t2.date = DATE_SUB(t1.date, INTERVAL 1 DAY)
        WHERE t2.views IS NOT NULL AND t1.views > t2.views * 2
        ORDER BY t1.date DESC
        LIMIT 5
    ");
    $stmt->execute([$start, $end, $start, $end]);
    $spikes = $stmt->fetchAll();

    foreach ($spikes as $spike) {
        $events[] = [
            'type' => 'spike',
            'date' => $spike['date'],
            'message' => "Traffic spike detected: " . number_format($spike['views']) . " views (+" . round((($spike['views'] - $spike['prev_views']) / $spike['prev_views']) * 100) . "%)"
        ];
    }

    // New traffic sources
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '/', 3), '://', -1) as source,
            MIN(viewed_at) as first_seen
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
          AND referrer IS NOT NULL
          AND referrer != ''
        GROUP BY source
        HAVING first_seen >= ?
        LIMIT 5
    ");
    $stmt->execute([$start, $end, $start]);
    $newSources = $stmt->fetchAll();

    foreach ($newSources as $source) {
        $events[] = [
            'type' => 'new_source',
            'date' => $source['first_seen'],
            'message' => "New traffic source: " . $source['source']
        ];
    }

    return $events;
}

// Helper functions
function calculateChange($current, $previous) {
    if (!$previous || $previous == 0) return null;
    return round((($current - $previous) / $previous) * 100, 1);
}

function getCountryFlag($countryCode) {
    if (!$countryCode || strlen($countryCode) != 2) return '🌍';

    $offset = 127397;
    $flag = mb_chr($offset + ord($countryCode[0])) . mb_chr($offset + ord($countryCode[1]));
    return $flag;
}
