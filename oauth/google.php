<?php
require_once '../includes/config.php';

$params = [
    'client_id' => $oauthConfig['google']['client_id'],
    'redirect_uri' => $oauthConfig['google']['redirect_uri'],
    'response_type' => 'code',
    'scope' => 'openid email profile',
];

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
exit;
