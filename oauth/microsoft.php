<?php
require_once '../includes/config.php';

$params = [
    'client_id' => $oauthConfig['microsoft']['client_id'],
    'redirect_uri' => $oauthConfig['microsoft']['redirect_uri'],
    'response_type' => 'code',
    'scope' => 'openid email profile offline_access',
    'response_mode' => 'query'
];

header('Location: https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' . http_build_query($params));
exit;
