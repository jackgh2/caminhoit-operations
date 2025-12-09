<?php
/**
 * Discord Daily Analytics Report
 * Sends a summary of the last 24 hours to Discord webhook
 *
 * Usage: Run this via cron daily
 * Example cron: 0 9 * * * /usr/bin/php /path/to/analytics/discord-report.php
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Load Discord config
$configFile = __DIR__ . '/discord-config.php';
if (!file_exists($configFile)) {
    die("Error: discord-config.php not found. Copy discord-config.example.php to discord-config.php and add your webhook URL.\n");
}

$discordConfig = require $configFile;
$discordWebhookUrl = $discordConfig['webhook_url'];

if (strpos($discordWebhookUrl, 'YOUR_WEBHOOK') !== false) {
    die("Error: Please configure your Discord webhook URL in discord-config.php\n");
}

// Time range: last 24 hours (or custom from config)
$hours = $discordConfig['report_hours'] ?? 24;
$endDate = date('Y-m-d H:i:s');
$startDate = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

try {
    // Get key metrics
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
    $stmt->execute([$startDate, $endDate]);
    $metrics = $stmt->fetch();

    // Top pages
    $stmt = $pdo->prepare("
        SELECT page_url, COUNT(*) as views
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
        GROUP BY page_url
        ORDER BY views DESC
        LIMIT 5
    ");
    $stmt->execute([$startDate, $endDate]);
    $topPages = $stmt->fetchAll();

    // Top exit pages
    $stmt = $pdo->prepare("
        SELECT page_url, COUNT(*) as exits
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
          AND is_exit = 1
        GROUP BY page_url
        ORDER BY exits DESC
        LIMIT 3
    ");
    $stmt->execute([$startDate, $endDate]);
    $topExitPages = $stmt->fetchAll();

    // Top countries
    $stmt = $pdo->prepare("
        SELECT country_name, country_code, COUNT(DISTINCT visitor_id) as visitors
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
          AND country_code IS NOT NULL
        GROUP BY country_code, country_name
        ORDER BY visitors DESC
        LIMIT 5
    ");
    $stmt->execute([$startDate, $endDate]);
    $topCountries = $stmt->fetchAll();

    // Device breakdown
    $stmt = $pdo->prepare("
        SELECT device_type, COUNT(DISTINCT visitor_id) as visitors
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
        GROUP BY device_type
        ORDER BY visitors DESC
    ");
    $stmt->execute([$startDate, $endDate]);
    $devices = $stmt->fetchAll();

    // Browser breakdown
    $stmt = $pdo->prepare("
        SELECT browser, COUNT(DISTINCT visitor_id) as visitors
        FROM analytics_pageviews
        WHERE viewed_at BETWEEN ? AND ?
          AND browser IS NOT NULL
        GROUP BY browser
        ORDER BY visitors DESC
        LIMIT 3
    ");
    $stmt->execute([$startDate, $endDate]);
    $browsers = $stmt->fetchAll();

    // Traffic spikes
    $stmt = $pdo->prepare("
        SELECT t1.hour, t1.views, t2.views as prev_views
        FROM (
            SELECT HOUR(viewed_at) as hour, COUNT(*) as views
            FROM analytics_pageviews
            WHERE viewed_at BETWEEN ? AND ?
            GROUP BY HOUR(viewed_at)
        ) t1
        LEFT JOIN (
            SELECT HOUR(viewed_at) as hour, COUNT(*) as views
            FROM analytics_pageviews
            WHERE viewed_at BETWEEN DATE_SUB(?, INTERVAL 24 HOUR) AND ?
            GROUP BY HOUR(viewed_at)
        ) t2 ON t2.hour = t1.hour
        WHERE t2.views IS NOT NULL AND t1.views > t2.views * ?
        ORDER BY t1.views DESC
        LIMIT 3
    ");
    $spikeThreshold = $discordConfig['spike_threshold'] ?? 1.5;
    $stmt->execute([$startDate, $endDate, $startDate, $startDate, $spikeThreshold]);
    $spikes = $stmt->fetchAll();

    // Build Discord embed
    $embed = [
        'title' => '📊 CaminhoIT Analytics - Daily Report',
        'description' => 'Analytics summary for the last 24 hours',
        'color' => 6641914, // Purple color
        'timestamp' => date('c'),
        'footer' => [
            'text' => 'CaminhoIT Analytics'
        ],
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
            $metrics['bounce_rate'] ?? 0
        ),
        'inline' => false
    ];

    // Top Pages
    if (count($topPages) > 0) {
        $pagesText = '';
        foreach ($topPages as $page) {
            $pagesText .= sprintf("**%s** - %s views\n", $page['page_url'], number_format($page['views']));
        }
        $embed['fields'][] = [
            'name' => '🏆 Top Pages',
            'value' => $pagesText,
            'inline' => false
        ];
    }

    // Top Exit Pages
    if (count($topExitPages) > 0) {
        $exitText = '';
        foreach ($topExitPages as $page) {
            $exitText .= sprintf("**%s** - %s exits\n", $page['page_url'], number_format($page['exits']));
        }
        $embed['fields'][] = [
            'name' => '🚪 Top Exit Pages',
            'value' => $exitText,
            'inline' => false
        ];
    }

    // Top Countries
    if (count($topCountries) > 0) {
        $countriesText = '';
        foreach ($topCountries as $country) {
            $flag = getCountryFlag($country['country_code']);
            $countriesText .= sprintf("%s **%s** - %s visitors\n", $flag, $country['country_name'], number_format($country['visitors']));
        }
        $embed['fields'][] = [
            'name' => '🌍 Top Locations',
            'value' => $countriesText,
            'inline' => true
        ];
    }

    // Device Breakdown
    if (count($devices) > 0) {
        $deviceText = '';
        $total = array_sum(array_column($devices, 'visitors'));
        foreach ($devices as $device) {
            $percentage = ($device['visitors'] / $total) * 100;
            $icon = match($device['device_type']) {
                'mobile' => '📱',
                'tablet' => '📲',
                'desktop' => '💻',
                default => '🖥️'
            };
            $deviceText .= sprintf("%s **%s** - %s (%.0f%%)\n",
                $icon,
                ucfirst($device['device_type']),
                number_format($device['visitors']),
                $percentage
            );
        }
        $embed['fields'][] = [
            'name' => '📱 Device Breakdown',
            'value' => $deviceText,
            'inline' => true
        ];
    }

    // Browser Breakdown
    if (count($browsers) > 0) {
        $browserText = '';
        foreach ($browsers as $browser) {
            $browserText .= sprintf("**%s** - %s visitors\n", $browser['browser'], number_format($browser['visitors']));
        }
        $embed['fields'][] = [
            'name' => '🌐 Top Browsers',
            'value' => $browserText,
            'inline' => true
        ];
    }

    // Anomalies/Spikes
    if (count($spikes) > 0) {
        $spikeText = '';
        foreach ($spikes as $spike) {
            $change = (($spike['views'] - $spike['prev_views']) / $spike['prev_views']) * 100;
            $spikeText .= sprintf("**%02d:00** - %s views (+%.0f%%)\n",
                $spike['hour'],
                number_format($spike['views']),
                $change
            );
        }
        $embed['fields'][] = [
            'name' => '⚡ Traffic Spikes Detected',
            'value' => $spikeText,
            'inline' => false
        ];
    }

    // If no traffic
    if ($metrics['pageviews'] == 0) {
        $embed['fields'][] = [
            'name' => '😴 No Traffic',
            'value' => 'No visitors in the last 24 hours.',
            'inline' => false
        ];
    }

    // Send to Discord
    $payload = json_encode([
        'username' => $discordConfig['bot_name'] ?? 'CaminhoIT Analytics',
        'avatar_url' => $discordConfig['bot_avatar'] ?? 'https://caminhoit.com/assets/logo.png',
        'embeds' => [$embed]
    ]);

    $ch = curl_init($discordWebhookUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

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

} catch (Exception $e) {
    echo "Error generating report: " . $e->getMessage() . "\n";
    error_log("Analytics Discord Report Error: " . $e->getMessage());
}

// Helper functions
function formatDuration($seconds) {
    if (!$seconds) return '0s';
    $mins = floor($seconds / 60);
    $secs = $seconds % 60;
    return $mins > 0 ? "{$mins}m {$secs}s" : "{$secs}s";
}

function getCountryFlag($countryCode) {
    if (!$countryCode || strlen($countryCode) != 2) return '🌍';
    $offset = 127397;
    $flag = mb_chr($offset + ord($countryCode[0])) . mb_chr($offset + ord($countryCode[1]));
    return $flag;
}
