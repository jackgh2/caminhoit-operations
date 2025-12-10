<?php
/**
 * Test Discord Order Webhook
 * This script tests if Discord notifications are working for staff-created orders
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/DiscordNotifications.php';

// Only allow admin access
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'administrator') {
    die('Access denied. Admin only.');
}

echo "<h1>Discord Order Webhook Test</h1>";
echo "<pre>";

// 1. Check Discord configuration
echo "=== Discord Configuration ===\n";
$discord = new DiscordNotifications($pdo);

// Use reflection to access private properties
$reflection = new ReflectionClass($discord);

$globalEnabled = $reflection->getProperty('global_enabled');
$globalEnabled->setAccessible(true);
echo "Global Enabled: " . ($globalEnabled->getValue($discord) ? 'YES' : 'NO') . "\n";

$ordersEnabled = $reflection->getProperty('orders_enabled');
$ordersEnabled->setAccessible(true);
echo "Orders Enabled: " . ($ordersEnabled->getValue($discord) ? 'YES' : 'NO') . "\n";

$ordersWebhookUrl = $reflection->getProperty('orders_webhook_url');
$ordersWebhookUrl->setAccessible(true);
$webhookUrl = $ordersWebhookUrl->getValue($discord);
echo "Orders Webhook URL: " . (!empty($webhookUrl) ? substr($webhookUrl, 0, 50) . '...' : 'EMPTY') . "\n";

// 2. Get the most recent staff-created order
echo "\n=== Finding Recent Staff-Created Orders ===\n";
$stmt = $pdo->prepare("
    SELECT o.id, o.order_number, o.status, o.total_amount, o.currency,
           c.name as company_name, o.created_at
    FROM orders o
    JOIN companies c ON o.company_id = c.id
    WHERE o.staff_id IS NOT NULL
    ORDER BY o.created_at DESC
    LIMIT 5
");
$stmt->execute();
$recentOrders = $stmt->fetchAll();

if (empty($recentOrders)) {
    echo "No staff-created orders found.\n";
} else {
    echo "Found " . count($recentOrders) . " recent staff-created orders:\n";
    foreach ($recentOrders as $order) {
        echo "  - Order #{$order['order_number']} (ID: {$order['id']}) - {$order['company_name']} - {$order['currency']} {$order['total_amount']} - Status: {$order['status']} - Created: {$order['created_at']}\n";
    }

    // 3. Test webhook with the most recent order
    if (isset($_GET['test']) && $_GET['test'] == '1') {
        $testOrderId = $recentOrders[0]['id'];
        echo "\n=== Testing Webhook for Order ID: $testOrderId ===\n";

        // First, let's manually test the webhook URL
        echo "\n--- Manual Webhook Test ---\n";

        $testPayload = [
            'username' => 'CaminhoIT Test',
            'content' => 'This is a test message from the webhook diagnostic tool. If you see this, webhooks are working!'
        ];

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For testing

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_info = curl_getinfo($ch);
        curl_close($ch);

        echo "HTTP Code: $http_code\n";
        echo "cURL Error: " . ($curl_error ?: 'None') . "\n";
        echo "Response: " . ($response ?: 'Empty') . "\n";

        if ($http_code >= 200 && $http_code < 300) {
            echo "✅ Manual webhook test SUCCESSFUL!\n";
        } else {
            echo "❌ Manual webhook test FAILED!\n";
            echo "Full cURL Info:\n";
            print_r($curl_info);
        }

        echo "\n--- Testing notifyStaffOrderCreated() ---\n";
        $result = $discord->notifyStaffOrderCreated($testOrderId);

        if ($result) {
            echo "✅ Webhook sent successfully!\n";
            echo "Check your Discord channel for the notification.\n";
        } else {
            echo "❌ Webhook failed!\n";
            echo "Check the error log for details.\n";
        }

        // Show recent error log entries
        echo "\n=== Recent Error Log Entries ===\n";
        echo "(Check your PHP error log for full details)\n";

        // Try to read error log if accessible
        $errorLogPaths = [
            'C:\xampp\apache\logs\error.log',
            'C:\xampp\php\logs\php_error_log.txt',
            '/var/log/apache2/error.log',
            '/var/log/php-fpm/error.log'
        ];

        foreach ($errorLogPaths as $logPath) {
            if (file_exists($logPath) && is_readable($logPath)) {
                echo "Reading from: $logPath\n";
                $logLines = file($logPath);
                $relevantLines = array_slice($logLines, -20); // Last 20 lines
                foreach ($relevantLines as $line) {
                    if (stripos($line, 'discord') !== false || stripos($line, 'webhook') !== false) {
                        echo htmlspecialchars($line);
                    }
                }
                break;
            }
        }
    } else {
        echo "\n=== Test Instructions ===\n";
        echo "To send a test webhook for the most recent order, add ?test=1 to the URL.\n";
        echo "Example: " . $_SERVER['REQUEST_URI'] . "?test=1\n";
    }
}

// 4. Check database configuration
echo "\n=== Database Configuration ===\n";
$stmt = $pdo->prepare("
    SELECT config_key, config_value
    FROM system_config
    WHERE config_key LIKE 'discord.%'
    ORDER BY config_key
");
$stmt->execute();
$configs = $stmt->fetchAll();

if (empty($configs)) {
    echo "No Discord configuration found in system_config table.\n";
} else {
    foreach ($configs as $config) {
        $value = $config['config_value'];
        if (strpos($config['config_key'], 'webhook_url') !== false) {
            $value = !empty($value) ? substr($value, 0, 50) . '...' : 'EMPTY';
        }
        echo "{$config['config_key']}: {$value}\n";
    }
}

echo "\n=== Summary ===\n";
if ($globalEnabled->getValue($discord) && $ordersEnabled->getValue($discord) && !empty($webhookUrl)) {
    echo "✅ Discord notifications are properly configured!\n";
} else {
    echo "❌ Discord notifications are NOT properly configured.\n";

    if (!$globalEnabled->getValue($discord)) {
        echo "   - Global Discord notifications are DISABLED\n";
    }
    if (!$ordersEnabled->getValue($discord)) {
        echo "   - Order notifications are DISABLED\n";
    }
    if (empty($webhookUrl)) {
        echo "   - Orders webhook URL is EMPTY\n";
    }

    echo "\nTo enable Discord notifications:\n";
    echo "1. Go to Admin Tools > System Configuration\n";
    echo "2. Enable 'Discord Notifications Enabled'\n";
    echo "3. Enable 'Discord Orders Notifications'\n";
    echo "4. Set the 'Discord Orders Webhook URL'\n";
}

echo "</pre>";
?>
