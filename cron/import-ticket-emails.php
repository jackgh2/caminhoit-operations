<?php
/**
 * Cron job for importing ticket emails
 * Should be run every 5-15 minutes depending on your needs
 *
 * Cron example (every 15 minutes):
 * STAR-SLASH-15 * * * * /usr/bin/php /home/caminhoit/public_html/cron/import-ticket-emails.php >> /home/caminhoit/logs/email-import.log 2>&1
 * (Replace STAR-SLASH with: asterisk followed by forward slash)
 *
 * @author CaminhoIT Support Team
 * @date 2025-11-06
 */

// Prevent web access
if (php_sapi_name() !== 'cli' && !isset($_GET['manual_run'])) {
    die('This script can only be run from command line or with manual_run parameter');
}

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start timing
$startTime = microtime(true);

echo "==========================================================\n";
echo "CaminhoIT Email Import - " . date('Y-m-d H:i:s') . "\n";
echo "==========================================================\n\n";

// Include required files
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/EmailImport.php';

try {
    // Create email import instance
    $emailImport = new EmailImport($pdo);

    // Check if enabled
    if (!$emailImport->isEnabled()) {
        echo "Email import is disabled in settings.\n";
        echo "To enable, update the 'import_enabled' setting in support_email_import_settings table.\n\n";
        exit(0);
    }

    echo "Starting email import process...\n\n";

    // Process emails
    $result = $emailImport->processEmails();

    if ($result['success']) {
        echo "✓ Success: " . $result['message'] . "\n";

        if (isset($result['processed']) && $result['processed'] > 0) {
            echo "  - Emails processed: " . $result['processed'] . "\n";

            if (isset($result['errors']) && $result['errors'] > 0) {
                echo "  - Errors encountered: " . $result['errors'] . "\n";
            }
        }
    } else {
        echo "✗ Error: " . $result['message'] . "\n";
    }

    // Show statistics
    echo "\n--- Import Statistics ---\n";
    $stats = $emailImport->getStats();

    if (!empty($stats)) {
        echo "Total imports: " . ($stats['total'] ?? 0) . "\n";
        echo "New tickets created: " . ($stats['new_tickets'] ?? 0) . "\n";
        echo "Replies imported: " . ($stats['replies'] ?? 0) . "\n";
        echo "Last import: " . ($stats['last_import'] ?? 'Never') . "\n";
    }

} catch (Exception $e) {
    echo "✗ Fatal error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

// End timing
$endTime = microtime(true);
$executionTime = round($endTime - $startTime, 2);

echo "\n==========================================================\n";
echo "Completed in {$executionTime} seconds\n";
echo "==========================================================\n\n";

exit(0);
?>
