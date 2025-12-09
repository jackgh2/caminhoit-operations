<?php
session_start();

// Clear any existing session
unset($_SESSION['user']);

echo "<h1>Simple OAuth Test</h1>";
echo "<p>Current session user: " . (isset($_SESSION['user']) ? 'SET' : 'NOT SET') . "</p>";

// Try the Google OAuth
echo '<p><a href="oauth/google.php?test=1">Test Google OAuth (with debug)</a></p>';

// Check if we have any error logs
if (file_exists('/home/caminhoit/logs/error_log')) {
    echo "<h2>Recent Error Logs:</h2>";
    echo "<pre>";
    $logs = file_get_contents('/home/caminhoit/logs/error_log');
    $lines = explode("\n", $logs);
    $recentLines = array_slice($lines, -50); // Last 50 lines
    foreach ($recentLines as $line) {
        if (strpos($line, 'OAUTH') !== false || strpos($line, 'WHMCS') !== false) {
            echo htmlspecialchars($line) . "\n";
        }
    }
    echo "</pre>";
}
?>