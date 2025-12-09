<?php
session_start();
require_once 'includes/config.php';

$provider = $_GET['provider'] ?? null;
$code = $_GET['code'] ?? null;

// Handle login errors (like access_denied)
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
    $errorDescription = htmlspecialchars($_GET['error_description'] ?? 'Login was denied or cancelled.');
    
    require_once 'includes/lang.php';
    ?>
    <!DOCTYPE html>
    <html lang="<?= $lang; ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= $translations['login']; ?> | CaminhoIT</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="assets/styles.css">
    </head>
    <body class="login-page">
        <?php include 'includes/nav.php'; ?>
        <header class="hero login-hero">
            <div class="container hero-content">
                <h1 class="hero-title"><?= $translations['login_hero_heading']; ?></h1>
                <p class="hero-subtitle"><?= $translations['login_hero_subheading']; ?></p>
            </div>
        </header>
        <div class="login-overlap-box d-flex justify-content-center">
            <div class="text-center login-box">
                <img src="/assets/logo.png" alt="CaminhoIT Logo">
                <h2 class="mb-4"><?= $translations['login']; ?></h2>
                <div class="alert alert-danger">
                    <strong>Login Failed:</strong> <?= $errorDescription; ?> (<?= $error; ?>)
                </div>
                <a href="login.php" class="btn btn-primary mt-3">Return to Login</a>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

// Normal login flow
if (!$provider || !$code) {
    die('Invalid callback.');
}

$config = $oauthConfig[$provider] ?? null;
if (!$config) {
    die('Unknown provider.');
}

switch ($provider) {
    case 'google':
        $token_url = 'https://oauth2.googleapis.com/token';
        $userinfo_url = 'https://openidconnect.googleapis.com/v1/userinfo';
        break;
    case 'microsoft':
        $token_url = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
        $userinfo_url = 'https://graph.microsoft.com/oidc/userinfo';
        break;
    case 'discord':
        $token_url = 'https://discord.com/api/oauth2/token';
        $userinfo_url = 'https://discord.com/api/users/@me';
        break;
    default:
        die('Unsupported provider.');
}

$post_fields = [
    'code' => $code,
    'client_id' => $config['client_id'],
    'client_secret' => $config['client_secret'],
    'redirect_uri' => $config['redirect_uri'],
    'grant_type' => 'authorization_code'
];

// Exchange code for access token
$ch = curl_init($token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

$responseRaw = curl_exec($ch);
$response = json_decode($responseRaw, true);
curl_close($ch);

$access_token = $response['access_token'] ?? null;

if (!$access_token) {
    echo "<pre>";
    echo "Token Exchange Failed:\n";
    echo "Raw Response:\n$responseRaw\n";
    echo "Parsed Response:\n";
    print_r($response);
    echo "</pre>";
    die('Failed to get access token.');
}

// Retrieve user info
$ch = curl_init($userinfo_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
$userinfo = json_decode(curl_exec($ch), true);
curl_close($ch);

// Normalize and validate
$email = $userinfo['email'] ?? null;
$username = $userinfo['name'] ?? $userinfo['username'] ?? null;
$provider_id = $userinfo['sub'] ?? $userinfo['id'] ?? null;

if (!$email || !$provider_id) {
    die('Unable to retrieve user information.');
}

// Check or insert user
$stmt = $pdo->prepare("SELECT * FROM users WHERE provider = ? AND provider_id = ?");
$stmt->execute([$provider, $provider_id]);
$user = $stmt->fetch();

if ($user && !$user['is_active']) {
    die('Your account has been disabled. Please contact support.');
}

if (!$user) {
    $stmt = $pdo->prepare("INSERT INTO users (provider, provider_id, email, username, role, is_active) VALUES (?, ?, ?, ?, 'public', 1)");
    $stmt->execute([$provider, $provider_id, $email, $username]);
    $userId = $pdo->lastInsertId();
    $user = $pdo->query("SELECT * FROM users WHERE id = $userId")->fetch();
}

// Set session
$_SESSION['user'] = [
    'id' => $user['id'],
    'username' => $user['username'],
    'role' => $user['role'],
    'email' => $user['email']
];

header('Location: /members/dashboard.php');
exit;
