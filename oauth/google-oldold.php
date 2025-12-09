<?php
session_start();

// Enable debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log everything
error_log("[OAUTH-GOOGLE] =================================");
error_log("[OAUTH-GOOGLE] OAuth process started at " . date('Y-m-d H:i:s'));
error_log("[OAUTH-GOOGLE] Request URI: " . $_SERVER['REQUEST_URI']);
error_log("[OAUTH-GOOGLE] Document root: " . $_SERVER['DOCUMENT_ROOT']);
error_log("[OAUTH-GOOGLE] Script name: " . $_SERVER['SCRIPT_NAME']);
error_log("[OAUTH-GOOGLE] Current working directory: " . getcwd());
error_log("[OAUTH-GOOGLE] GET parameters: " . json_encode($_GET));
error_log("[OAUTH-GOOGLE] Session before: " . json_encode($_SESSION));

// Check file paths
$configPath = '../includes/config.php';
$syncPath = '../includes/whmcs-user-sync.php';

error_log("[OAUTH-GOOGLE] Config path: " . $configPath . " (exists: " . (file_exists($configPath) ? 'YES' : 'NO') . ")");
error_log("[OAUTH-GOOGLE] Sync path: " . $syncPath . " (exists: " . (file_exists($syncPath) ? 'YES' : 'NO') . ")");

// Try to include config
try {
    require_once $configPath;
    error_log("[OAUTH-GOOGLE] Config included successfully");
    
    if (isset($oauthConfig)) {
        error_log("[OAUTH-GOOGLE] OAuth config found with providers: " . implode(', ', array_keys($oauthConfig)));
    } else {
        error_log("[OAUTH-GOOGLE] ERROR: oauthConfig variable not found in config");
        die("OAuth configuration error");
    }
} catch (Exception $e) {
    error_log("[OAUTH-GOOGLE] ERROR including config: " . $e->getMessage());
    die("Config include error: " . $e->getMessage());
}

// Try to include WHMCS sync
try {
    require_once $syncPath;
    error_log("[OAUTH-GOOGLE] WHMCS sync included successfully");
    
    if (class_exists('WHMCSUserSync')) {
        error_log("[OAUTH-GOOGLE] WHMCSUserSync class is available");
    } else {
        error_log("[OAUTH-GOOGLE] ERROR: WHMCSUserSync class not found");
    }
} catch (Exception $e) {
    error_log("[OAUTH-GOOGLE] ERROR including WHMCS sync: " . $e->getMessage());
    // Continue without WHMCS sync
}

// Handle OAuth callback
if (isset($_GET['code'])) {
    error_log("[OAUTH-GOOGLE] Processing OAuth callback");
    
    try {
        // Exchange code for token
        $token_url = 'https://oauth2.googleapis.com/token';
        $token_data = [
            'client_id' => $oauthConfig['google']['client_id'],
            'client_secret' => $oauthConfig['google']['client_secret'],
            'code' => $_GET['code'],
            'grant_type' => 'authorization_code',
            'redirect_uri' => $oauthConfig['google']['redirect_uri']
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $token_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($token_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $token_response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            throw new Exception("CURL error: " . $curl_error);
        }
        
        $token_data = json_decode($token_response, true);
        error_log("[OAUTH-GOOGLE] Token response: " . json_encode($token_data));
        
        if (!isset($token_data['access_token'])) {
            throw new Exception('No access token received: ' . $token_response);
        }
        
        // Get user info
        $user_url = 'https://www.googleapis.com/oauth2/v2/userinfo?access_token=' . $token_data['access_token'];
        $user_response = file_get_contents($user_url);
        $google_user = json_decode($user_response, true);
        
        error_log("[OAUTH-GOOGLE] User info: " . json_encode($google_user));
        
        if (!$google_user || !isset($google_user['email'])) {
            throw new Exception('Failed to get user info');
        }
        
        // Prepare OAuth user data
        $oauthUser = [
            'id' => $google_user['id'],
            'email' => $google_user['email'],
            'name' => $google_user['name'] ?? ($google_user['given_name'] . ' ' . $google_user['family_name']),
            'first_name' => $google_user['given_name'] ?? '',
            'last_name' => $google_user['family_name'] ?? '',
            'picture' => $google_user['picture'] ?? '',
            'verified_email' => $google_user['verified_email'] ?? false
        ];
        
        error_log("[OAUTH-GOOGLE] Prepared OAuth user: " . json_encode($oauthUser));
        
        // Initialize WHMCS sync
        $syncResult = ['success' => false, 'message' => 'WHMCS sync not available'];
        
        if (class_exists('WHMCSUserSync')) {
            try {
                error_log("[OAUTH-GOOGLE] Starting WHMCS sync...");
                $whmcsSync = new WHMCSUserSync();
                $syncResult = $whmcsSync->syncUserToWHMCS($oauthUser, 'Google');
                error_log("[OAUTH-GOOGLE] WHMCS sync completed: " . json_encode($syncResult));
            } catch (Exception $e) {
                error_log("[OAUTH-GOOGLE] WHMCS sync exception: " . $e->getMessage());
                $syncResult = ['success' => false, 'error' => $e->getMessage()];
            }
        } else {
            error_log("[OAUTH-GOOGLE] WHMCSUserSync class not available");
        }
        
        // Create session
        $user_data = [
            'id' => $google_user['id'],
            'email' => $google_user['email'],
            'name' => $oauthUser['name'],
            'first_name' => $oauthUser['first_name'],
            'last_name' => $oauthUser['last_name'],
            'picture' => $oauthUser['picture'],
            'provider' => 'google',
            'verified' => $google_user['verified_email'] ?? false,
            'role' => 'user',
            'login_time' => time(),
            'login_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ];
        
        // Add WHMCS client ID if successful
        if ($syncResult['success'] && isset($syncResult['client_id'])) {
            $user_data['whmcs_client_id'] = $syncResult['client_id'];
            $_SESSION['whmcs_client_id'] = $syncResult['client_id'];
            error_log("[OAUTH-GOOGLE] WHMCS client ID added: " . $syncResult['client_id']);
        }
        
        $_SESSION['user'] = $user_data;
        
        error_log("[OAUTH-GOOGLE] Final session: " . json_encode($_SESSION));
        error_log("[OAUTH-GOOGLE] Authentication completed successfully");
        
        // Redirect to dashboard
        header('Location: /members/dashboard.php?login=success&provider=google');
        exit;
        
    } catch (Exception $e) {
        error_log("[OAUTH-GOOGLE] Exception: " . $e->getMessage());
        header('Location: /login.php?error=' . urlencode('Google authentication failed: ' . $e->getMessage()));
        exit;
    }
}

// Handle OAuth error
if (isset($_GET['error'])) {
    error_log("[OAUTH-GOOGLE] OAuth error: " . $_GET['error']);
    header('Location: /login.php?error=' . urlencode('Google authentication was cancelled'));
    exit;
}

// Initial OAuth request
error_log("[OAUTH-GOOGLE] Starting OAuth flow");

$params = [
    'client_id' => $oauthConfig['google']['client_id'],
    'redirect_uri' => $oauthConfig['google']['redirect_uri'],
    'scope' => 'openid email profile',
    'response_type' => 'code',
    'access_type' => 'offline',
    'prompt' => 'consent'
];

$auth_url = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query($params);
error_log("[OAUTH-GOOGLE] Redirecting to: " . $auth_url);

header('Location: ' . $auth_url);
exit;
?>