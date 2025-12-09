<?php
// Debug IP detection and GeoIP lookup

header('Content-Type: application/json');

// Get real IP (handle proxies/CDN)
$realIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Check for real IP behind proxy/CDN
$possibleIpHeaders = [
    'HTTP_CF_CONNECTING_IP', // Cloudflare
    'HTTP_X_FORWARDED_FOR',
    'HTTP_X_REAL_IP',
    'HTTP_CLIENT_IP'
];

$detectedFrom = 'REMOTE_ADDR';
foreach ($possibleIpHeaders as $header) {
    if (!empty($_SERVER[$header])) {
        $ips = explode(',', $_SERVER[$header]);
        $realIp = trim($ips[0]);
        $detectedFrom = $header;
        break;
    }
}

// Check if it's a public IP
$isPublic = filter_var($realIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

// Try GeoIP lookup
$geoData = null;
$geoError = null;

if ($isPublic) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://ip-api.com/json/{$realIp}?fields=status,message,country,countryCode,region,city,lat,lon,isp,org,as,query");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response && $httpCode === 200) {
        $geoData = json_decode($response, true);
    } else {
        $geoError = "HTTP {$httpCode}" . ($curlError ? ": {$curlError}" : '');
    }
} else {
    $geoError = "Private/Reserved IP - not publicly routable";
}

echo json_encode([
    'detected_ip' => $realIp,
    'detected_from' => $detectedFrom,
    'is_public' => $isPublic,
    'geo_data' => $geoData,
    'geo_error' => $geoError,
    'all_headers' => [
        'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null,
        'HTTP_CF_CONNECTING_IP' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        'HTTP_X_FORWARDED_FOR' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        'HTTP_X_REAL_IP' => $_SERVER['HTTP_X_REAL_IP'] ?? null,
        'HTTP_CLIENT_IP' => $_SERVER['HTTP_CLIENT_IP'] ?? null,
    ]
], JSON_PRETTY_PRINT);
