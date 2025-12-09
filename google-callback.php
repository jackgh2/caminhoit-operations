<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/lang.php';

if (isset($_GET['error'])) {
    $error = $_GET['error'];
    $description = $_GET['error_description'] ?? $_GET['error_subcode'] ?? 'Unknown error';
    ?>
    <!DOCTYPE html>
    <html lang="<?= $lang; ?>">
    <head>
        <meta charset="UTF-8">
        <title>Login Error | CaminhoIT</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body>
        <div class="container mt-5">
            <div class="alert alert-danger">
                <h4 class="alert-heading">Login Failed</h4>
                <p><strong>Error:</strong> <?= htmlspecialchars($error); ?></p>
                <p><strong>Description:</strong> <?= htmlspecialchars($description); ?></p>
                <a href="/login.php" class="btn btn-primary mt-3">Return to Login</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 1. Exchange auth code for tokens
$code = $_GET['code'] ?? null;

if (!$code) {
    die("No authorization code provided.");
}

$token_url = 'https://oauth2.googleapis.com/token';

$data = [
    'code' => $code,
    'client_id' => $google_client_id,
    'client_secret' => $google_client_secret,
    'redirect_uri' => $google_redirect_uri,
    'grant_type' => 'authorization_code'
];

$options = [
    'http' => [
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'method'  => 'POST',
        'content' => http_build_query($data)
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($token_url, false, $context);

if ($response === false) {
    die("Failed to contact token endpoint.");
}

$token_data = json_decode($response, true);

// Optional debug dump
// echo '<pre>'; print_r($token_data); echo '</pre>'; exit;

if (!isset($token_data['access_token']) || !isset($token_data['id_token'])) {
    die("Token response incomplete.");
}

$id_token = $token_data['id_token'];

// 2. Decode ID token to get user info
$jwt_parts = explode('.', $id_token);
if (count($jwt_parts) !== 3) {
    die("Invalid ID token format.");
}

$payload_json = base64_decode(strtr($jwt_parts[1], '-_', '+/'));
$payload = json_decode($payload_json, true);

if (!$payload) {
    die("Failed to decode ID token.");
}

// 3. Save user session
$_SESSION['user'] = [
    'email' => $payload['email'] ?? 'unknown',
    'name' => $payload['name'] ?? 'unknown',
    'picture' => $payload['picture'] ?? '',
    'provider' => 'google'
];

// 4. Redirect to dashboard
header("Location: dashboard.php");
exit;
