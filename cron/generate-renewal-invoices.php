<?php
/**
 * Cron Job: Generate Renewal Invoices for Subscriptions
 * Run this daily to create invoices for subscriptions that are due for renewal
 *
 * Usage:
 *   php C:\Users\jaque\Documents\claude\caminhoit\cron\generate-renewal-invoices.php
 *
 * Or add to Windows Task Scheduler:
 *   php.exe "C:\Users\jaque\Documents\claude\caminhoit\cron\generate-renewal-invoices.php"
 *
 * Or add to Linux crontab (run daily at 2 AM):
 *   0 2 * * * /usr/bin/php /path/to/caminhoit/cron/generate-renewal-invoices.php
 */

// Only allow CLI execution
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

// Set up environment
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = 'caminhoit.com'; // For Discord webhook URLs

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/order-invoice-automation.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/DiscordNotifications.php';

echo "=====================================\n";
echo "Renewal Invoice Generation Cron Job\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";
echo "=====================================\n\n";

try {
    // Generate renewal invoices for due subscriptions
    $invoice_count = generateRenewalInvoicesForDueSubscriptions($pdo);

    if ($invoice_count > 0) {
        echo "✅ SUCCESS: Generated {$invoice_count} renewal invoice(s)\n";

        // Send summary Discord notification if configured
        try {
            $discord = new DiscordNotifications($pdo);
            // You can add a summary notification method if desired
        } catch (Exception $e) {
            echo "⚠️  Warning: Discord notification failed: " . $e->getMessage() . "\n";
        }
    } else {
        echo "ℹ️  No subscriptions due for renewal today\n";
    }

    echo "\nCompleted: " . date('Y-m-d H:i:s') . "\n";
    echo "=====================================\n";

    exit(0); // Success

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    echo "\nFailed: " . date('Y-m-d H:i:s') . "\n";
    echo "=====================================\n";

    exit(1); // Error
}
?>
