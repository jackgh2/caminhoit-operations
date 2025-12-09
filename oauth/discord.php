<?php
require_once '../includes/config.php';

$params = [
    'client_id' => $oauthConfig['discord']['client_id'],
    'redirect_uri' => $oauthConfig['discord']['redirect_uri'],
    'response_type' => 'code',
    'scope' => 'identify email'
];

header('Location: https://discord.com/api/oauth2/authorize?' . http_build_query($params));
exit;
