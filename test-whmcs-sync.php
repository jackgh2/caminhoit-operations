<?php
/**
 * WHMCS Sync Test Script
 * Use this to test WHMCS integration without OAuth
 */

// Enable debugging
define('DEBUG_MODE', true);
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'includes/config.php';
require_once 'includes/whmcs-user-sync.php';

echo "<h1>WHMCS Sync Test</h1>";
echo "<pre>";

try {
    // Test OAuth user data (replace with your actual email)
    $testOAuthUser = [
        'id' => 'test_' . time(),
        'email' => 'detouredeuropeoutlook@gmail.com', // Replace with your email
        'name' => 'Test User Portal',
        'first_name' => 'Test',
        'last_name' => 'User',
        'picture' => 'https://example.com/avatar.jpg',
        'verified_email' => true
    ];
    
    echo "Testing WHMCS sync with data:\n";
    echo json_encode($testOAuthUser, JSON_PRETTY_PRINT) . "\n\n";
    
    // Initialize WHMCS sync
    $whmcsSync = new WHMCSUserSync();
    
    // Test the sync
    $result = $whmcsSync->syncUserToWHMCS($testOAuthUser, 'Test');
    
    echo "Sync Result:\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
    
    if ($result['success']) {
        echo "✅ SUCCESS: WHMCS sync completed successfully!\n";
        echo "Client ID: " . $result['client_id'] . "\n";
    } else {
        echo "❌ FAILED: " . $result['message'] . "\n";
        if (isset($result['error'])) {
            echo "Error: " . $result['error'] . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
?>