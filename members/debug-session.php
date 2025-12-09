<?php
session_start();

echo "<h2>Session Debug Information</h2>";
echo "<pre>";
echo "User Role: " . ($_SESSION['user']['role'] ?? 'NOT SET') . "\n";
echo "Username: " . ($_SESSION['user']['username'] ?? 'NOT SET') . "\n";
echo "User ID: " . ($_SESSION['user']['id'] ?? 'NOT SET') . "\n";
echo "\nFull Session Data:\n";
print_r($_SESSION);
echo "</pre>";

// Check if role === 'administrator'
$role = $_SESSION['user']['role'] ?? null;
if ($role === 'administrator') {
    echo "<p style='color: green; font-weight: bold;'>✓ Admin Controls SHOULD be visible</p>";
} else {
    echo "<p style='color: red; font-weight: bold;'>✗ Admin Controls will NOT be visible (role is: $role)</p>";
}
?>
