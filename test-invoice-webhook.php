<?php
/**
 * Test Invoice Discord Webhook
 * Checks configuration and sends a test notification
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

echo "<h1>Discord Invoice Webhook Test</h1>";
echo "<pre>";

// 1. Check Discord configuration
echo "=== Discord Configuration ===\n";
$discord = new DiscordNotifications($pdo);

// Use reflection to access private properties
$reflection = new ReflectionClass($discord);

$globalEnabled = $reflection->getProperty('global_enabled');
$globalEnabled->setAccessible(true);
echo "Global Enabled: " . ($globalEnabled->getValue($discord) ? 'YES' : 'NO') . "\n";

$invoicesEnabled = $reflection->getProperty('invoices_enabled');
$invoicesEnabled->setAccessible(true);
echo "Invoices Enabled: " . ($invoicesEnabled->getValue($discord) ? 'YES' : 'NO') . "\n";

$invoicesWebhookUrl = $reflection->getProperty('invoices_webhook_url');
$invoicesWebhookUrl->setAccessible(true);
$webhookUrl = $invoicesWebhookUrl->getValue($discord);
echo "Invoices Webhook URL: " . (!empty($webhookUrl) ? substr($webhookUrl, 0, 50) . '...' : 'EMPTY') . "\n";

// 2. Check database configuration
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

// 3. Get the most recent invoices
echo "\n=== Finding Recent Invoices ===\n";
$stmt = $pdo->prepare("
    SELECT i.id, i.invoice_number, i.status, i.total_amount, i.currency,
           c.name as company_name, i.created_at
    FROM invoices i
    JOIN companies c ON i.company_id = c.id
    ORDER BY i.created_at DESC
    LIMIT 5
");
$stmt->execute();
$recentInvoices = $stmt->fetchAll();

if (empty($recentInvoices)) {
    echo "No invoices found.\n";
} else {
    echo "Found " . count($recentInvoices) . " recent invoices:\n";
    foreach ($recentInvoices as $invoice) {
        echo "  - Invoice #{$invoice['invoice_number']} (ID: {$invoice['id']}) - {$invoice['company_name']} - {$invoice['currency']} {$invoice['total_amount']} - Status: {$invoice['status']} - Created: {$invoice['created_at']}\n";
    }

    // 4. Test webhook with the most recent invoice
    if (isset($_GET['test']) && $_GET['test'] == '1') {
        $testInvoiceId = $recentInvoices[0]['id'];
        echo "\n=== Testing Webhook for Invoice ID: $testInvoiceId ===\n";

        // Manual webhook test
        if (!empty($webhookUrl)) {
            echo "\n--- Manual Webhook Test ---\n";

            $testPayload = [
                'username' => 'CaminhoIT Invoices Test',
                'content' => 'This is a test message from the invoice webhook diagnostic tool. If you see this, webhooks are working!'
            ];

            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testPayload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            echo "HTTP Code: $http_code\n";
            echo "cURL Error: " . ($curl_error ?: 'None') . "\n";
            echo "Response: " . ($response ?: 'Empty') . "\n";

            if ($http_code >= 200 && $http_code < 300) {
                echo "✅ Manual webhook test SUCCESSFUL!\n";
            } else {
                echo "❌ Manual webhook test FAILED!\n";
            }
        }

        echo "\n--- Testing notifyInvoiceCreated() ---\n";
        $result = $discord->notifyInvoiceCreated($testInvoiceId);

        if ($result) {
            echo "✅ Webhook sent successfully!\n";
            echo "Check your Discord channel for the notification.\n";
        } else {
            echo "❌ Webhook failed!\n";
            echo "Check the error log for details.\n";
        }
    } else {
        echo "\n=== Test Instructions ===\n";
        echo "To send a test webhook for the most recent invoice, add ?test=1 to the URL.\n";
        echo "Example: " . $_SERVER['REQUEST_URI'] . "?test=1\n";
    }
}

echo "\n=== Summary ===\n";
if ($globalEnabled->getValue($discord) && $invoicesEnabled->getValue($discord) && !empty($webhookUrl)) {
    echo "✅ Discord invoice notifications are properly configured!\n";
} else {
    echo "❌ Discord invoice notifications are NOT properly configured.\n";

    if (!$globalEnabled->getValue($discord)) {
        echo "   - Global Discord notifications are DISABLED\n";
    }
    if (!$invoicesEnabled->getValue($discord)) {
        echo "   - Invoice notifications are DISABLED\n";
    }
    if (empty($webhookUrl)) {
        echo "   - Invoices webhook URL is EMPTY\n";
    }

    echo "\nTo enable Discord invoice notifications:\n";
    echo "1. Go to System Configuration or run:\n";
    echo "   UPDATE system_config SET config_value = '1' WHERE config_key = 'discord.invoices_enabled';\n";
    echo "2. Set the webhook URL:\n";
    echo "   UPDATE system_config SET config_value = 'YOUR_WEBHOOK_URL' WHERE config_key = 'discord.invoices_webhook_url';\n";
}

echo "</pre>";
?>
