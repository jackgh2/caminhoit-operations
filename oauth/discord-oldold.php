<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/whmcs-user-sync.php';

// Handle OAuth callback
if (isset($_GET['code'])) {
    try {
        // Exchange authorization code for access token
        $token_url = 'https://discord.com/api/oauth2/token';
        $token_data = [
            'client_id' => $oauthConfig['discord']['client_id'],
            'client_secret' => $oauthConfig['discord']['client_secret'],
            'code' => $_GET['code'],
            'grant_type' => 'authorization_code',
            'redirect_uri' => $oauthConfig['discord']['redirect_uri'],
            'scope' => 'identify email'
        ];
        
        $token_context = stream_context_create([
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($token_data)
            ]
        ]);
        
        $token_response = file_get_contents($token_url, false, $token_context);
        $token_data = json_decode($token_response, true);
        
        if (!isset($token_data['access_token'])) {
            throw new Exception('Failed to obtain access token from Discord');
        }
        
        // Get user information from Discord
        $user_url = 'https://discord.com/api/v10/users/@me';
        $user_context = stream_context_create([
            'http' => [
                'header' => "Authorization: Bearer " . $token_data['access_token'] . "\r\n"
            ]
        ]);
        
        $user_response = file_get_contents($user_url, false, $user_context);
        $discord_user = json_decode($user_response, true);
        
        if (!$discord_user || !isset($discord_user['email'])) {
            throw new Exception('Failed to retrieve user information from Discord');
        }
        
        // Build avatar URL
        $avatar_url = '';
        if ($discord_user['avatar']) {
            $avatar_url = "https://cdn.discordapp.com/avatars/{$discord_user['id']}/{$discord_user['avatar']}.png";
        }
        
        // Prepare OAuth user data
        $oauthUser = [
            'id' => $discord_user['id'],
            'email' => $discord_user['email'],
            'name' => $discord_user['global_name'] ?? $discord_user['username'],
            'first_name' => explode(' ', ($discord_user['global_name'] ?? $discord_user['username']))[0],
            'last_name' => '',
            'picture' => $avatar_url,
            'verified_email' => $discord_user['verified'] ?? false
        ];
        
        // Parse last name if global_name contains spaces
        if (!empty($discord_user['global_name']) && strpos($discord_user['global_name'], ' ') !== false) {
            $nameParts = explode(' ', $discord_user['global_name'], 2);
            $oauthUser['first_name'] = $nameParts[0];
            $oauthUser['last_name'] = $nameParts[1];
        }
        
        // Sync user to WHMCS
        $whmcsSync = new WHMCSUserSync();
        $syncResult = $whmcsSync->syncUserToWHMCS($oauthUser, 'Discord');
        
        // Create/update local user session regardless of WHMCS sync result
        $user_data = [
            'id' => $discord_user['id'],
            'email' => $discord_user['email'],
            'name' => $oauthUser['name'],
            'first_name' => $oauthUser['first_name'],
            'last_name' => $oauthUser['last_name'],
            'picture' => $oauthUser['picture'],
            'provider' => 'discord',
            'verified' => $discord_user['verified'] ?? false,
            'role' => 'user', // Default role - adjust as needed
            'login_time' => time(),
            'login_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ];
        
        // Add WHMCS client ID if sync was successful
        if ($syncResult['success'] && isset($syncResult['client_id'])) {
            $user_data['whmcs_client_id'] = $syncResult['client_id'];
        }
        
        $_SESSION['user'] = $user_data;
        
        // Log the authentication
        error_log("[OAUTH-DISCORD] User {$discord_user['email']} authenticated successfully. WHMCS sync: " . 
                 ($syncResult['success'] ? 'SUCCESS' : 'FAILED - ' . ($syncResult['error'] ?? 'Unknown error')));
        
        // Redirect to dashboard
        header('Location: /members/dashboard.php?login=success&provider=discord');
        exit;
        
    } catch (Exception $e) {
        error_log("[OAUTH-DISCORD] Authentication failed: " . $e->getMessage());
        header('Location: /login.php?error=' . urlencode('Discord authentication failed: ' . $e->getMessage()));
        exit;
    }
}

// Handle OAuth error
if (isset($_GET['error'])) {
    error_log("[OAUTH-DISCORD] OAuth error: " . $_GET['error']);
    header('Location: /login.php?error=' . urlencode('Discord authentication was cancelled or failed'));
    exit;
}

// Initial OAuth request - redirect to Discord
$params = [
    'client_id' => $oauthConfig['discord']['client_id'],
    'redirect_uri' => $oauthConfig['discord']['redirect_uri'],
    'response_type' => 'code',
    'scope' => 'identify email',
    'prompt' => 'consent'
];

header('Location: https://discord.com/api/oauth2/authorize?' . http_build_query($params));
exit;
?>