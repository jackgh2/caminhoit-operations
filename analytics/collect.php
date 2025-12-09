<?php
/**
 * Analytics Collection Endpoint
 * Receives tracking data and stores in database
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Get request data
$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

if (!$data || !isset($data['visitor_id']) || !isset($data['session_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data']);
    exit;
}

// Check settings
$stmt = $pdo->prepare("SELECT setting_value FROM analytics_settings WHERE setting_key = 'tracking_enabled'");
$stmt->execute();
if ($stmt->fetchColumn() != '1') {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Tracking disabled']);
    exit;
}

// Respect Do Not Track
$stmt = $pdo->prepare("SELECT setting_value FROM analytics_settings WHERE setting_key = 'respect_dnt'");
$stmt->execute();
if ($stmt->fetchColumn() == '1' && isset($_SERVER['HTTP_DNT']) && $_SERVER['HTTP_DNT'] == '1') {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'DNT respected']);
    exit;
}

$action = $_GET['action'] ?? 'pageview';

try {
    if ($action === 'heartbeat') {
        // Get latest pageview data to populate active visitor info
        // Prefer entries with geo data if available
        $stmt = $pdo->prepare("
            SELECT country_code, city, device_type, isp
            FROM analytics_pageviews
            WHERE visitor_id = ?
            ORDER BY
                CASE WHEN country_code IS NOT NULL THEN 0 ELSE 1 END,
                viewed_at DESC
            LIMIT 1
        ");
        $stmt->execute([$data['visitor_id']]);
        $pageview = $stmt->fetch();

        // Update active visitor with current data
        $currentPage = $data['page_url'] ?? '/';
        $deviceType = $data['device_type'] ?? ($pageview['device_type'] ?? 'desktop');
        $countryCode = $pageview['country_code'] ?? null;
        $city = $pageview['city'] ?? null;
        $isp = $pageview['isp'] ?? null;

        $stmt = $pdo->prepare("
            INSERT INTO analytics_active_visitors
            (visitor_id, session_id, current_page, country_code, city, device_type, isp, last_seen)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                session_id = ?,
                current_page = ?,
                device_type = ?,
                isp = ?,
                last_seen = NOW()
        ");
        $stmt->execute([
            $data['visitor_id'],
            $data['session_id'],
            $currentPage,
            $countryCode,
            $city,
            $deviceType,
            $isp,
            $data['session_id'],
            $currentPage,
            $deviceType,
            $isp
        ]);

        // Update time on page if provided
        if (isset($data['time_on_page'])) {
            $stmt = $pdo->prepare("
                UPDATE analytics_pageviews
                SET time_on_page = ?
                WHERE session_id = ?
                ORDER BY viewed_at DESC
                LIMIT 1
            ");
            $stmt->execute([$data['time_on_page'], $data['session_id']]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'exit') {
        // Mark as exit page
        $stmt = $pdo->prepare("
            UPDATE analytics_pageviews
            SET is_exit = 1, time_on_page = ?
            WHERE session_id = ?
            ORDER BY viewed_at DESC
            LIMIT 1
        ");
        $stmt->execute([$data['time_on_page'] ?? 0, $data['session_id']]);

        // Remove from active visitors
        $stmt = $pdo->prepare("DELETE FROM analytics_active_visitors WHERE visitor_id = ?");
        $stmt->execute([$data['visitor_id']]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'event') {
        // Track custom event
        $stmt = $pdo->prepare("
            INSERT INTO analytics_events (session_id, visitor_id, event_name, event_category, event_value, page_url)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['session_id'],
            $data['visitor_id'],
            $data['event_name'] ?? 'unknown',
            $data['event_category'] ?? null,
            $data['event_value'] ?? null,
            $data['page_url'] ?? null
        ]);

        echo json_encode(['success' => true]);
        exit;
    }

    // Default: Page view tracking
    // Get real IP (handle proxies/CDN)
    $realIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Check for real IP behind proxy/CDN
    $possibleIpHeaders = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP'
    ];

    foreach ($possibleIpHeaders as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $realIp = trim($ips[0]);
            break;
        }
    }

    // Get geo data from real IP (before anonymization)
    $geoData = null;

    // Try multiple GeoIP services as fallback
    if (!filter_var($realIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        // Local/private IP - skip geo lookup
        error_log("Analytics: Skipping geo lookup for private IP: {$realIp}");
        $geoData = null;
    } else {
        // Try ip-api.com using cURL (more reliable than file_get_contents)
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://ip-api.com/json/{$realIp}?fields=status,country,countryCode,region,city,lat,lon,isp,org,as");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response && $httpCode === 200) {
            $apiData = json_decode($response, true);
            if ($apiData && isset($apiData['status']) && $apiData['status'] === 'success') {
                $geoData = [
                    'country_code' => $apiData['countryCode'] ?? null,
                    'country_name' => $apiData['country'] ?? null,
                    'region' => $apiData['region'] ?? null,
                    'city' => $apiData['city'] ?? null,
                    'latitude' => $apiData['lat'] ?? null,
                    'longitude' => $apiData['lon'] ?? null,
                    'isp' => $apiData['isp'] ?? null,
                    'organization' => $apiData['org'] ?? null,
                    'as' => $apiData['as'] ?? null
                ];
                error_log("Analytics: Geo data SUCCESS for {$realIp}: {$geoData['city']}, {$geoData['country_code']} - ISP: {$geoData['isp']}");
            } else {
                error_log("Analytics: GeoIP API returned error for {$realIp}: " . ($apiData['message'] ?? 'Unknown'));
            }
        } else {
            error_log("Analytics: Failed to reach GeoIP API for {$realIp}. HTTP Code: {$httpCode}");
        }
    }

    // IP storage - check if anonymization is enabled
    $ip = $realIp;
    $stmt = $pdo->prepare("SELECT setting_value FROM analytics_settings WHERE setting_key = 'ip_anonymization'");
    $stmt->execute();
    $anonymize = $stmt->fetchColumn();

    // Store full IP by default, or anonymize if setting is enabled
    if ($anonymize == '1') {
        $ip = preg_replace('/\.\d+$/', '.0', $ip); // Anonymize last octet
    }

    // Parse user agent
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $browser = getBrowser($userAgent);
    $os = getOS($userAgent);

    // Check if entry page (first page of session)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM analytics_pageviews WHERE session_id = ?");
    $stmt->execute([$data['session_id']]);
    $isEntry = $stmt->fetchColumn() == 0;

    // Check if organization column exists
    $hasOrgColumn = false;
    try {
        $pdo->query("SELECT organization FROM analytics_pageviews LIMIT 1");
        $hasOrgColumn = true;
    } catch (Exception $e) {
        // organization column doesn't exist
    }

    // Insert page view
    if ($hasOrgColumn) {
        $stmt = $pdo->prepare("
            INSERT INTO analytics_pageviews (
                session_id, visitor_id, page_url, page_title, referrer,
                utm_source, utm_medium, utm_campaign, utm_content, utm_term,
                country_code, country_name, region, city, latitude, longitude,
                browser, browser_version, os, os_version, device_type, screen_resolution,
                ip_address, isp, organization, user_agent, language, is_entry
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['session_id'],
            $data['visitor_id'],
            $data['page_url'] ?? '/',
            $data['page_title'] ?? '',
            $data['referrer'] ?? null,
            $data['utm_source'] ?? null,
            $data['utm_medium'] ?? null,
            $data['utm_campaign'] ?? null,
            $data['utm_content'] ?? null,
            $data['utm_term'] ?? null,
            $geoData['country_code'] ?? null,
            $geoData['country_name'] ?? null,
            $geoData['region'] ?? null,
            $geoData['city'] ?? null,
            $geoData['latitude'] ?? null,
            $geoData['longitude'] ?? null,
            $browser['name'] ?? null,
            $browser['version'] ?? null,
            $os['name'] ?? null,
            $os['version'] ?? null,
            $data['device_type'] ?? 'desktop',
            $data['screen_resolution'] ?? null,
            $ip,
            $geoData['isp'] ?? null,
            $geoData['organization'] ?? null,
            $userAgent,
            $data['language'] ?? null,
            $isEntry ? 1 : 0
        ]);
    } else {
        // Without organization column
        $stmt = $pdo->prepare("
            INSERT INTO analytics_pageviews (
                session_id, visitor_id, page_url, page_title, referrer,
                utm_source, utm_medium, utm_campaign, utm_content, utm_term,
                country_code, country_name, region, city, latitude, longitude,
                browser, browser_version, os, os_version, device_type, screen_resolution,
                ip_address, isp, user_agent, language, is_entry
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['session_id'],
            $data['visitor_id'],
            $data['page_url'] ?? '/',
            $data['page_title'] ?? '',
            $data['referrer'] ?? null,
            $data['utm_source'] ?? null,
            $data['utm_medium'] ?? null,
            $data['utm_campaign'] ?? null,
            $data['utm_content'] ?? null,
            $data['utm_term'] ?? null,
            $geoData['country_code'] ?? null,
            $geoData['country_name'] ?? null,
            $geoData['region'] ?? null,
            $geoData['city'] ?? null,
            $geoData['latitude'] ?? null,
            $geoData['longitude'] ?? null,
            $browser['name'] ?? null,
            $browser['version'] ?? null,
            $os['name'] ?? null,
            $os['version'] ?? null,
            $data['device_type'] ?? 'desktop',
            $data['screen_resolution'] ?? null,
            $ip,
            $geoData['isp'] ?? null,
            $userAgent,
            $data['language'] ?? null,
            $isEntry ? 1 : 0
        ]);
    }

    // Update or create session
    $stmt = $pdo->prepare("SELECT id FROM analytics_sessions WHERE session_id = ?");
    $stmt->execute([$data['session_id']]);
    $sessionExists = $stmt->fetch();

    if ($sessionExists) {
        // Update existing session
        $stmt = $pdo->prepare("
            UPDATE analytics_sessions
            SET page_views = page_views + 1,
                exit_page = ?,
                last_activity = NOW()
            WHERE session_id = ?
        ");
        $stmt->execute([$data['page_url'], $data['session_id']]);
    } else {
        // Create new session - check if organization column exists
        $hasSessionOrgColumn = false;
        try {
            $pdo->query("SELECT organization FROM analytics_sessions LIMIT 1");
            $hasSessionOrgColumn = true;
        } catch (Exception $e) {
            // organization column doesn't exist
        }

        if ($hasSessionOrgColumn) {
            $stmt = $pdo->prepare("
                INSERT INTO analytics_sessions (
                    session_id, visitor_id, entry_page, exit_page,
                    country_code, country_name, region, city,
                    browser, os, device_type, referrer,
                    utm_source, utm_medium, utm_campaign, isp, organization
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['session_id'],
                $data['visitor_id'],
                $data['page_url'],
                $data['page_url'],
                $geoData['country_code'] ?? null,
                $geoData['country_name'] ?? null,
                $geoData['region'] ?? null,
                $geoData['city'] ?? null,
                $browser['name'] ?? null,
                $os['name'] ?? null,
                $data['device_type'] ?? 'desktop',
                $data['referrer'] ?? null,
                $data['utm_source'] ?? null,
                $data['utm_medium'] ?? null,
                $data['utm_campaign'] ?? null,
                $geoData['isp'] ?? null,
                $geoData['organization'] ?? null
            ]);
        } else {
            // Without organization column
            $stmt = $pdo->prepare("
                INSERT INTO analytics_sessions (
                    session_id, visitor_id, entry_page, exit_page,
                    country_code, country_name, region, city,
                    browser, os, device_type, referrer,
                    utm_source, utm_medium, utm_campaign, isp
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['session_id'],
                $data['visitor_id'],
                $data['page_url'],
                $data['page_url'],
                $geoData['country_code'] ?? null,
                $geoData['country_name'] ?? null,
                $geoData['region'] ?? null,
                $geoData['city'] ?? null,
                $browser['name'] ?? null,
                $os['name'] ?? null,
                $data['device_type'] ?? 'desktop',
                $data['referrer'] ?? null,
                $data['utm_source'] ?? null,
                $data['utm_medium'] ?? null,
                $data['utm_campaign'] ?? null,
                $geoData['isp'] ?? null
            ]);
        }
    }

    // Update active visitors
    $stmt = $pdo->prepare("
        INSERT INTO analytics_active_visitors (visitor_id, session_id, current_page, country_code, city, device_type, isp, last_seen)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            session_id = ?,
            current_page = ?,
            isp = ?,
            last_seen = NOW()
    ");
    $stmt->execute([
        $data['visitor_id'],
        $data['session_id'],
        $data['page_url'],
        $geoData['country_code'] ?? null,
        $geoData['city'] ?? null,
        $data['device_type'],
        $geoData['isp'] ?? null,
        $data['session_id'],
        $data['page_url'],
        $geoData['isp'] ?? null
    ]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('Analytics error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

// Helper functions
function getBrowser($userAgent) {
    $browsers = [
        '/edge\/([\d\.]+)/i' => ['name' => 'Edge', 'version' => 1],
        '/edg\/([\d\.]+)/i' => ['name' => 'Edge', 'version' => 1],
        '/chrome\/([\d\.]+)/i' => ['name' => 'Chrome', 'version' => 1],
        '/safari\/([\d\.]+)/i' => ['name' => 'Safari', 'version' => 1],
        '/firefox\/([\d\.]+)/i' => ['name' => 'Firefox', 'version' => 1],
        '/msie ([\d\.]+)/i' => ['name' => 'IE', 'version' => 1],
        '/trident\/.*rv:([\d\.]+)/i' => ['name' => 'IE', 'version' => 1],
    ];

    foreach ($browsers as $pattern => $browser) {
        if (preg_match($pattern, $userAgent, $matches)) {
            return [
                'name' => $browser['name'],
                'version' => $matches[$browser['version']] ?? null
            ];
        }
    }

    return ['name' => 'Unknown', 'version' => null];
}

function getOS($userAgent) {
    $oses = [
        '/windows nt 10/i' => ['name' => 'Windows', 'version' => '10'],
        '/windows nt 11/i' => ['name' => 'Windows', 'version' => '11'],
        '/windows nt 6\.3/i' => ['name' => 'Windows', 'version' => '8.1'],
        '/windows nt 6\.2/i' => ['name' => 'Windows', 'version' => '8'],
        '/windows nt 6\.1/i' => ['name' => 'Windows', 'version' => '7'],
        '/macintosh|mac os x ([\d_]+)/i' => ['name' => 'macOS', 'version' => 1],
        '/linux/i' => ['name' => 'Linux', 'version' => null],
        '/android ([\d\.]+)/i' => ['name' => 'Android', 'version' => 1],
        '/iphone os ([\d_]+)/i' => ['name' => 'iOS', 'version' => 1],
        '/ipad.*os ([\d_]+)/i' => ['name' => 'iOS', 'version' => 1],
    ];

    foreach ($oses as $pattern => $os) {
        if (preg_match($pattern, $userAgent, $matches)) {
            $version = isset($os['version']) && is_int($os['version']) && isset($matches[$os['version']])
                ? str_replace('_', '.', $matches[$os['version']])
                : $os['version'];
            return [
                'name' => $os['name'],
                'version' => $version
            ];
        }
    }

    return ['name' => 'Unknown', 'version' => null];
}
