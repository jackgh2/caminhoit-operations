<?php
/**
 * CaminhoIT Analytics → Discord Daily Report
 * ----------------------------------------------------
 * Sends a summary of the last 24 hours to a Discord webhook.
 *
 * ✅ Works both via web browser and cron (CLI)
 * ✅ Logs all output and errors when run via cron
 *
 * Example cron job (runs daily at 9AM):
 * 0 9 * * * /opt/cpanel/ea-php81/root/usr/bin/php -q /home/caminhoit/public_html/analytics/discord-report.php >> /home/caminhoit/cronlogs/discord-report.log 2>&1
 */

// ---------- SAFETY FIX: Handle CLI execution ----------
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__); // /home/caminhoit/public_html
}

// ---------- CONFIG INCLUDES ----------
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// ---------- DISCORD CONFIG ----------
$configFile = __DIR__ . '/discord-config.php';
if (!file_exists($configFile)) {
    echo "Error: discord-config.php not found. Copy discord-config.example.php to discord-config.php and add your webhook URL.\n";
    exit(1);
}

$discordConfig = require $configFile;
$discordWebhookUrl = $discordConfig['webhook_url'] ?? '';

if (strpos($discordWebhookUrl, 'YOUR_WEBHOOK') !== false || !$discordWebhookUrl) {
    echo "Error: Please configure your Discord webhook URL in discord-config.php\n";
    exit(1);
}

// ---------- TIME RANGE ----------
$hours = $discordConfig['report_hours'] ?? 24;
$endDate = date('Y-m-d H:i:s');
$startDate = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

// ---------- MAIN EXECUTION ----------
try {
    // --- Key Metrics ---
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS pageviews,
            COUNT(DISTINCT visitor_id) AS visitors,
            COUNT(DISTINCT session_id) AS sessions,
            AVG(time_on_page) AS avg_time,
            (SUM(CASE WHEN is_bounce = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT session_id), 0)) * 100 AS bounce_rate
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);
    $metrics = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'pageviews' => 0, 'visitors' => 0, 'sessions' => 0, 'avg_time' => 0, 'bounce_rate' => 0
    ];

    // --- Top Pages ---
    $stmt = $pdo->prepare("
        SELECT page_url, COUNT(*) AS views
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
        GROUP BY page_url
        ORDER BY views DESC
        LIMIT 5
    ");
    $stmt->execute([$startDate, $endDate]);
    $topPages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Top Exit Pages ---
    $stmt = $pdo->prepare("
        SELECT page_url, COUNT(*) AS exits
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
          AND is_exit = 1
        GROUP BY page_url
        ORDER BY exits DESC
        LIMIT 3
    ");
    $stmt->execute([$startDate, $endDate]);
    $topExitPages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Top Countries ---
    $stmt = $pdo->prepare("
        SELECT country_name, country_code, COUNT(DISTINCT visitor_id) AS visitors
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
          AND country_code IS NOT NULL
        GROUP BY country_code, country_name
        ORDER BY visitors DESC
        LIMIT 5
    ");
    $stmt->execute([$startDate, $endDate]);
    $topCountries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Device Breakdown ---
    $stmt = $pdo->prepare("
        SELECT device_type, COUNT(DISTINCT visitor_id) AS visitors
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
        GROUP BY device_type
        ORDER BY visitors DESC
    ");
    $stmt->execute([$startDate, $endDate]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Browser Breakdown ---
    $stmt = $pdo->prepare("
        SELECT browser, COUNT(DISTINCT visitor_id) AS visitors
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
          AND browser IS NOT NULL
        GROUP BY browser
        ORDER BY visitors DESC
        LIMIT 3
    ");
    $stmt->execute([$startDate, $endDate]);
    $browsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Traffic Spikes ---
    $spikeThreshold = $discordConfig['spike_threshold'] ?? 1.5;
    $stmt = $pdo->prepare("
        SELECT t1.hour, t1.views, t2.views AS prev_views
        FROM (
            SELECT HOUR(viewed_at) AS hour, COUNT(*) AS views
            FROM analytics_pageviews
            WHERE viewed_at BETWEEN ? AND ?
            GROUP BY HOUR(viewed_at)
        ) t1
        LEFT JOIN (
            SELECT HOUR(viewed_at) AS hour, COUNT(*) AS views
            FROM analytics_pageviews
            WHERE viewed_at BETWEEN DATE_SUB(?, INTERVAL 24 HOUR) AND ?
            GROUP BY HOUR(viewed_at)
        ) t2 ON t2.hour = t1.hour
        WHERE t2.views IS NOT NULL AND t1.views > t2.views * ?
        ORDER BY t1.views DESC
        LIMIT 3
    ");
    $stmt->execute([$startDate, $endDate, $startDate, $startDate, $spikeThreshold]);
    $spikes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ---------- DISCORD EMBED ----------
    $embed = [
        'title' => '📊 CaminhoIT Analytics - Daily Report',
        'description' => "Traffic summary for the past {$hours} hours.",
        'color' => 6641914,
        'timestamp' => date('c'),
        'footer' => ['text' => 'CaminhoIT Analytics'],
        'fields' => []
    ];

    // Key Metrics
    $embed['fields'][] = [
        'name' => '📈 Key Metrics',
        'value' => sprintf(
            "**Page Views:** %s\n**Unique Visitors:** %s\n**Sessions:** %s\n**Avg. Time:** %s\n**Bounce Rate:** %.1f%%",
            number_format($metrics['pageviews']),
            number_format($metrics['visitors']),
            number_format($metrics['sessions']),
            formatDuration($metrics['avg_time']),
            $metrics['bounce_rate']
        ),
        'inline' => false
    ];

    // Top Pages
    if ($topPages) {
        $value = '';
        foreach ($topPages as $p)
            $value .= sprintf("**%s** - %s views\n", $p['page_url'], number_format($p['views']));
        $embed['fields'][] = ['name' => '🏆 Top Pages', 'value' => $value, 'inline' => false];
    }

    // Exit Pages
    if ($topExitPages) {
        $value = '';
        foreach ($topExitPages as $p)
            $value .= sprintf("**%s** - %s exits\n", $p['page_url'], number_format($p['exits']));
        $embed['fields'][] = ['name' => '🚪 Top Exit Pages', 'value' => $value, 'inline' => false];
    }

    // Countries
    if ($topCountries) {
        $value = '';
        foreach ($topCountries as $c)
            $value .= sprintf("%s **%s** - %s visitors\n", getCountryFlag($c['country_code']), $c['country_name'], number_format($c['visitors']));
        $embed['fields'][] = ['name' => '🌍 Top Locations', 'value' => $value, 'inline' => true];
    }

    // Devices
    if ($devices) {
        $value = '';
        $total = array_sum(array_column($devices, 'visitors'));
        foreach ($devices as $d) {
            $icon = match ($d['device_type']) {
                'mobile' => '📱', 'tablet' => '📲', 'desktop' => '💻', default => '🖥️'
            };
            $pct = $total ? ($d['visitors'] / $total) * 100 : 0;
            $value .= sprintf("%s **%s** - %s (%.0f%%)\n", $icon, ucfirst($d['device_type']), number_format($d['visitors']), $pct);
        }
        $embed['fields'][] = ['name' => '📱 Device Breakdown', 'value' => $value, 'inline' => true];
    }

    // Browsers
    if ($browsers) {
        $value = '';
        foreach ($browsers as $b)
            $value .= sprintf("**%s** - %s visitors\n", $b['browser'], number_format($b['visitors']));
        $embed['fields'][] = ['name' => '🌐 Top Browsers', 'value' => $value, 'inline' => true];
    }

    // Spikes
    if ($spikes) {
        $value = '';
        foreach ($spikes as $s) {
            $change = (($s['views'] - $s['prev_views']) / max(1, $s['prev_views'])) * 100;
            $value .= sprintf("**%02d:00** - %s views (+%.0f%%)\n", $s['hour'], number_format($s['views']), $change);
        }
        $embed['fields'][] = ['name' => '⚡ Traffic Spikes Detected', 'value' => $value, 'inline' => false];
    }

    // No traffic case
    if ($metrics['pageviews'] == 0) {
        $embed['fields'][] = ['name' => '😴 No Traffic', 'value' => 'No visitors in the last 24 hours.', 'inline' => false];
    }

    // ---------- SEND TO DISCORD ----------
    $payload = json_encode([
        'username' => $discordConfig['bot_name'] ?? 'CaminhoIT Analytics',
        'avatar_url' => $discordConfig['bot_avatar'] ?? 'https://caminhoit.com/assets/logo.png',
        'embeds' => [$embed]
    ]);

    $ch = curl_init($discordWebhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        echo "✓ Daily report sent to Discord successfully!\n";
        echo "Pageviews: " . number_format($metrics['pageviews']) . "\n";
        echo "Visitors: " . number_format($metrics['visitors']) . "\n";
    } else {
        echo "✗ Failed to send Discord webhook. HTTP Code: $httpCode\n";
        echo "Response: $response\n";
    }

} catch (Throwable $e) {
    echo "Error generating report: " . $e->getMessage() . "\n";
    error_log("Analytics Discord Report Error: " . $e->getMessage());
}

// ---------- HELPER FUNCTIONS ----------
function formatDuration($seconds) {
    if (!$seconds) return '0s';
    $mins = floor($seconds / 60);
    $secs = $seconds % 60;
    return $mins > 0 ? "{$mins}m {$secs}s" : "{$secs}s";
}

function getCountryFlag($code) {
    if (!$code || strlen($code) != 2) return '🌍';
    $offset = 127397;
    return mb_chr($offset + ord($code[0])) . mb_chr($offset + ord($code[1]));
}
?>
