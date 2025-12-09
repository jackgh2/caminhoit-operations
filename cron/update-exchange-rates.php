#!/usr/bin/env php
<?php
/**
 * Cron job to automatically update exchange rates
 * Run daily: 0 0 * * * /usr/bin/php /path/to/your/site/cron/update-exchange-rates.php
 */

// Set error reporting for cron environment
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Determine the document root
$documentRoot = dirname(__DIR__);

// Include the configuration
require_once $documentRoot . '/includes/config.php';

// Log function for cron output
function cronLog($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] Exchange Rate Update: {$message}" . PHP_EOL;
    
    // Log to system log
    error_log($logMessage);
    
    // Also output to console for cron email notifications
    echo $logMessage;
}

try {
    cronLog("Starting exchange rate update process");
    
    // Check if auto-update is enabled
    if (!ConfigManager::isAutoUpdateEnabled()) {
        cronLog("Auto-update is disabled. Skipping update.", 'WARNING');
        exit(0);
    }
    
    // Perform the update (using system user ID = 1 for cron jobs)
    $systemUserId = 1;
    $result = ConfigManager::updateExchangeRates($systemUserId);
    
    if ($result['success']) {
        $message = "Successfully updated {$result['updated_rates']} exchange rates";
        cronLog($message, 'SUCCESS');
        
        // Log any significant rate changes
        if (!empty($result['alerts'])) {
            cronLog("Significant rate changes detected:", 'WARNING');
            foreach ($result['alerts'] as $alert) {
                $changeInfo = sprintf(
                    "%s: %s → %s (%.2f%% change)",
                    $alert['currency'],
                    $alert['old_rate'],
                    $alert['new_rate'],
                    $alert['change_percent']
                );
                cronLog($changeInfo, 'WARNING');
            }
        }
        
        // Clear any cached exchange rate data
        ConfigManager::clearCache();
        cronLog("Cache cleared successfully");
        
    } else {
        $errorMessage = "Failed to update exchange rates: " . $result['error'];
        cronLog($errorMessage, 'ERROR');
        exit(1);
    }
    
} catch (Exception $e) {
    $errorMessage = "Exception during exchange rate update: " . $e->getMessage();
    cronLog($errorMessage, 'ERROR');
    cronLog("Stack trace: " . $e->getTraceAsString(), 'ERROR');
    exit(1);
}

cronLog("Exchange rate update process completed successfully");
exit(0);
?>