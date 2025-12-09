<?php
require_once __DIR__ . '/includes/config.php';

echo "=== Discord Webhook URLs Comparison ===\n\n";

$stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key IN ('discord.orders_webhook_url', 'discord.subscriptions_webhook_url')");
$stmt->execute();
$configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($configs as $config) {
    $value = $config['config_value'];

    // Extract webhook ID for comparison
    if (preg_match('/webhooks\/(\d+)\//', $value, $matches)) {
        $webhook_id = $matches[1];
        echo $config['config_key'] . ":\n";
        echo "  Webhook ID: " . $webhook_id . "\n";
        echo "  Full URL: " . $value . "\n\n";
    } else {
        echo $config['config_key'] . ": [EMPTY or INVALID]\n\n";
    }
}

echo "=== Analysis ===\n";
echo "If the Webhook IDs are DIFFERENT, they're posting to different Discord channels!\n";
echo "If you want them in the SAME channel, copy the subscriptions URL to orders URL.\n";
?>
