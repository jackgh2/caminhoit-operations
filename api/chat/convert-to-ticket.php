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

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['session_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing session_id']);
    exit;
}

try {
    $chatManager = new ChatManager($pdo);
    
    $ticketId = $chatManager->convertToTicket($input['session_id'], $_SESSION['user']['id']);
    
    if ($ticketId) {
        echo json_encode([
            'success' => true,
            'ticket_id' => $ticketId,
            'message' => 'Chat converted to ticket successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to convert chat to ticket'
        ]);
    }
} catch (Exception $e) {
    error_log('Chat convert to ticket error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
?>