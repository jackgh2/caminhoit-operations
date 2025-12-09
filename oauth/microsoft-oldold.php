<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/whmcs-user-sync.php';

// Handle OAuth callback
if (isset($_GET['code'])) {
    try {
        // Exchange authorization code for access token
        $token_url = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
        $token_data = [
            'client_id' => $oauthConfig['microsoft']['client_id'],
            'client_secret' => $oauthConfig['microsoft']['client_secret'],
            'code' => $_GET['code'],
            'grant_type' => 'authorization_code',
            'redirect_uri' => $oauthConfig['microsoft']['redirect_uri'],
            'scope' => 'openid email profile'
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
            throw new Exception('Failed to obtain access token from Microsoft: ' . json_encode($token_data));
        }
        
        // Get user information from Microsoft Graph
        $user_url = 'https://graph.microsoft.com/v1.0/me';
        $user_context = stream_context_create([
            'http' => [
                'header' => "Authorization: Bearer " . $token_data['access_token'] . "\r\n"
            ]
        ]);
        
        $user_response = file_get_contents($user_url, false, $user_context);
        $microsoft_user = json_decode($user_response, true);
        
        if (!$microsoft_user || !isset($microsoft_user['mail']) && !isset($microsoft_user['userPrincipalName'])) {
            throw new Exception('Failed to retrieve user information from Microsoft');
        }
        
        // Prepare OAuth user data
        $email = $microsoft_user['mail'] ?? $microsoft_user['userPrincipalName'];
        $oauthUser = [
            'id' => $microsoft_user['id'],
            'email' => $email,
            'name' => $microsoft_user['displayName'] ?? '',
            'first_name' => $microsoft_user['givenName'] ?? '',
            'last_name' => $microsoft_user['surname'] ?? '',
            'picture' => '', // Microsoft Graph photo requires separate API call
            'verified_email' => true // Microsoft accounts are considered verified
        ];
        
        // Sync user to WHMCS
        $whmcsSync = new WHMCSUserSync();
        $syncResult = $whmcsSync->syncUserToWHMCS($oauthUser, 'Microsoft');
        
        // Create/update local user session regardless of WHMCS sync result
        $user_data = [
            'id' => $microsoft_user['id'],
            'email' => $email,
            'name' => $oauthUser['name'],
            'first_name' => $oauthUser['first_name'],
            'last_name' => $oauthUser['last_name'],
            'picture' => $oauthUser['picture'],
            'provider' => 'microsoft',
            'verified' => true,
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
        error_log("[OAUTH-MICROSOFT] User {$email} authenticated successfully. WHMCS sync: " . 
                 ($syncResult['success'] ? 'SUCCESS' : 'FAILED - ' . ($syncResult['error'] ?? 'Unknown error')));
        
        // Redirect to dashboard
        header('Location: /members/dashboard.php?login=success&provider=microsoft');
        exit;
        
    } catch (Exception $e) {
        error_log("[OAUTH-MICROSOFT] Authentication failed: " . $e->getMessage());
        header('Location: /login.php?error=' . urlencode('Microsoft authentication failed: ' . $e->getMessage()));
        exit;
    }
}

// Handle OAuth error
if (isset($_GET['error'])) {
    error_log("[OAUTH-MICROSOFT] OAuth error: " . $_GET['error']);
    header('Location: /login.php?error=' . urlencode('Microsoft authentication was cancelled or failed'));
    exit;
}

// Initial OAuth request - redirect to Microsoft (keeping your exact parameters)
$params = [
    'client_id' => $oauthConfig['microsoft']['client_id'],
    'redirect_uri' => $oauthConfig['microsoft']['redirect_uri'],
    'response_type' => 'code',
    'scope' => 'openid email profile offline_access',
    'response_mode' => 'query'
];

header('Location: https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' . http_build_query($params));
exit;
?>