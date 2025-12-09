<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

echo "<h1>Database Test</h1>";

// Test basic connection
try {
    $result = $pdo->query("SELECT 1 as test");
    echo "<p>✓ Database connection working</p>";
} catch (Exception $e) {
    echo "<p>❌ Database connection failed: " . $e->getMessage() . "</p>";
    exit;
}

// Check tables
$tables = ['chat_sessions', 'chat_messages', 'users'];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        echo "<p>✓ Table '$table' exists</p>";
    } catch (Exception $e) {
        echo "<p>❌ Table '$table' missing or error: " . $e->getMessage() . "</p>";
    }
}

// Test session creation directly
echo "<h2>Direct Session Test</h2>";
try {
    $sessionToken = bin2hex(random_bytes(32));
    
    $stmt = $pdo->prepare("
        INSERT INTO chat_sessions (guest_name, guest_email, session_token, status, created_at, last_activity) 
        VALUES (?, ?, ?, 'waiting', NOW(), NOW())
    ");
    
    $stmt->execute(['Test User', 'test@example.com', $sessionToken]);
    $sessionId = $pdo->lastInsertId();
    
    echo "<p>✓ Session created - ID: $sessionId, Token: " . substr($sessionToken, 0, 8) . "...</p>";
    
    // Test retrieval
    $stmt = $pdo->prepare("SELECT * FROM chat_sessions WHERE session_token = ?");
    $stmt->execute([$sessionToken]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($session) {
        echo "<p>✓ Session retrieved successfully</p>";
        echo "<pre>" . print_r($session, true) . "</pre>";
    } else {
        echo "<p>❌ Session not found after creation</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Direct session test failed: " . $e->getMessage() . "</p>";
}
?>