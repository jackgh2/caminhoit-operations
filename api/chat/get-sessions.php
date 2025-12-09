<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ChatManager.php';

// Check if user is logged in and has proper permissions
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'staff'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $chatManager = new ChatManager($pdo);
    $sessions = $chatManager->getActiveSessions();
    
    echo json_encode([
        'success' => true,
        'sessions' => $sessions
    ]);
} catch (Exception $e) {
    error_log('Chat get sessions error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
?>