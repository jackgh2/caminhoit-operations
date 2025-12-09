<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<h1>Order Creation Debug</h1>";

// Check session
echo "<h2>Session Data:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// Check POST data
if ($_POST) {
    echo "<h2>POST Data:</h2>";
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";
}

// Test database connection
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
    echo "<h2>Database Connection: SUCCESS</h2>";
    
    // Check if orders table exists
    echo "<h3>Orders Table Structure:</h3>";
    $stmt = $pdo->query("DESCRIBE orders");
    $columns = $stmt->fetchAll();
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>{$col['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<h2>Database Error: " . $e->getMessage() . "</h2>";
}

// Test simple order creation
if (isset($_POST['test_order'])) {
    echo "<h2>Testing Order Creation...</h2>";
    
    try {
        $user = $_SESSION['user'] ?? null;
        if (!$user) {
            throw new Exception("No user in session");
        }
        
        $pdo->beginTransaction();
        
        // Simple test order
        $order_number = 'TEST-' . time();
        $company_id = $user['company_id'] ?? 1; // Use user's company
        $user_id = $user['id'];
        
        echo "Attempting to create order with:<br>";
        echo "Order Number: $order_number<br>";
        echo "Company ID: $company_id<br>";
        echo "User ID: $user_id<br>";
        echo "User Role: " . ($user['role'] ?? 'unknown') . "<br><br>";
        
        // Determine customer_id vs staff_id
        $customer_id = null;
        $staff_id = null;
        
        if (($user['role'] ?? '') === 'customer') {
            $customer_id = $user_id;
            echo "Setting as customer order (customer_id = $customer_id)<br>";
        } else {
            $staff_id = $user_id;
            echo "Setting as staff order (staff_id = $staff_id)<br>";
        }
        
        $sql = "INSERT INTO orders (order_number, company_id, customer_id, staff_id, status, payment_status, order_type, subtotal, tax_amount, total_amount, currency, vat_rate, vat_enabled, notes, billing_cycle, start_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $params = [
            $order_number,
            $company_id,
            $customer_id,
            $staff_id,
            'draft',
            'unpaid',
            'new',
            10.00,
            0.00,
            10.00,
            'GBP',
            0.00,
            0,
            'Test order',
            'monthly',
            date('Y-m-d'),
        ];
        
        echo "SQL: $sql<br>";
        echo "Params: " . print_r($params, true) . "<br>";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($params);
        
        if ($result) {
            $order_id = $pdo->lastInsertId();
            echo "<strong style='color: green;'>SUCCESS! Order created with ID: $order_id</strong><br>";
            $pdo->commit();
        } else {
            $errorInfo = $stmt->errorInfo();
            throw new Exception("SQL Error: " . $errorInfo[2]);
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<strong style='color: red;'>ERROR: " . $e->getMessage() . "</strong><br>";
        echo "Stack trace:<br><pre>" . $e->getTraceAsString() . "</pre>";
    }
}

// Simple form to test order creation
if (!isset($_POST['test_order'])) {
    echo "<h2>Test Order Creation</h2>";
    echo "<form method='POST'>";
    echo "<button type='submit' name='test_order'>Create Test Order</button>";
    echo "</form>";
}
?>