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

if (!isset($input['session_id']) || !isset($input['message']) || !isset($input['sender_type'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

// Validate message content
$message = trim($input['message']);
if (empty($message) || strlen($message) > 1000) {
    echo json_encode(['success' => false, 'message' => 'Message must be between 1 and 1000 characters']);
    exit;
}

try {
    $chatManager = new ChatManager($pdo);
    
    $messageData = [
        'session_id' => $input['session_id'],
        'message' => $message,
        'sender_type' => $input['sender_type'],
        'sender_id' => $_SESSION['user']['id'],
        'sender_name' => $_SESSION['user']['username']
    ];
    
    $messageId = $chatManager->sendMessage($messageData);
    
    if ($messageId) {
        echo json_encode([
            'success' => true,
            'message_id' => $messageId,
            'message' => 'Message sent successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to send message'
        ]);
    }
} catch (Exception $e) {
    error_log('Chat send message error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
?>