<?php
require_once __DIR__ . '/includes/config.php';

echo "=== Discord Webhook Configuration ===\n\n";

$stmt = $pdo->query("SELECT config_key, config_value FROM system_config WHERE config_key LIKE 'discord%' ORDER BY config_key");
$configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($configs as $config) {
    $value = $config['config_value'];

    // Mask webhook URLs for security
    if (strpos($config['config_key'], 'webhook_url') !== false && !empty($value)) {
        $value = substr($value, 0, 40) . '...[MASKED]';
    }

    echo $config['config_key'] . " = " . ($value ?: '[EMPTY]') . "\n";
}

echo "\n=== Diagnosis ===\n";
$discord = new DiscordNotifications($pdo);
echo "If you don't see orders enabled above, that's your issue!\n";
?>
