<?php
/**
 * Check payment_transactions table structure
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Only allow admin access
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'administrator') {
    die('Access denied. Admin only.');
}

echo "<h1>Payment Table Diagnostics</h1>";
echo "<pre>";

// Check if payment_transactions table exists
$stmt = $pdo->query("SHOW TABLES LIKE 'payment_transactions'");
$exists = $stmt->fetch();

if ($exists) {
    echo "✅ payment_transactions table EXISTS\n\n";

    // Get table structure
    echo "=== Table Structure ===\n";
    $stmt = $pdo->query("DESCRIBE payment_transactions");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($columns as $col) {
        echo "{$col['Field']}: {$col['Type']}\n";
        if ($col['Field'] === 'status') {
            echo "  ^^^ STATUS COLUMN TYPE: {$col['Type']}\n";
        }
    }

    // Show recent records
    echo "\n=== Recent Records (last 5) ===\n";
    $stmt = $pdo->query("SELECT * FROM payment_transactions ORDER BY id DESC LIMIT 5");
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($records)) {
        echo "No records found\n";
    } else {
        foreach ($records as $record) {
            echo "ID: {$record['id']}, Status: {$record['status']}, Amount: {$record['amount']}\n";
        }
    }

} else {
    echo "❌ payment_transactions table DOES NOT EXIST\n\n";

    // Check for similar tables
    echo "=== Looking for similar payment tables ===\n";
    $stmt = $pdo->query("SHOW TABLES LIKE '%payment%'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tables)) {
        echo "No payment-related tables found\n";
    } else {
        foreach ($tables as $table) {
            echo "- {$table}\n";
        }
    }
}

// Check invoices table status column
echo "\n=== Invoices Table Status Column ===\n";
$stmt = $pdo->query("DESCRIBE invoices");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($columns as $col) {
    if ($col['Field'] === 'status') {
        echo "Status column type: {$col['Type']}\n";
    }
}

echo "</pre>";
?>
