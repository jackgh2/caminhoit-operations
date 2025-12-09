<?php
// Start output buffering to prevent accidental output
ob_start();

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Set content type first
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Function to send JSON response and exit
function sendJsonResponse($data, $status = 200) {
    ob_clean();
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// Function to log errors
function logError($message) {
    error_log("Chat API: " . $message);
}

try {
    // Include required files
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ChatManager.php';
    
    // Test database connection
    $pdo->query("SELECT 1");
    
    // Initialize ChatManager
    $chatManager = new ChatManager($pdo);
    
} catch (Exception $e) {
    logError("Initialization error: " . $e->getMessage());
    sendJsonResponse(['success' => false, 'error' => 'Server error'], 500);
}

// Get action from request
$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
} else {
    $action = $_POST['action'] ?? '';
}

// Log the request
logError("Action: {$action} | Method: {$_SERVER['REQUEST_METHOD']} | User: " . (isset($_SESSION['user']) ? $_SESSION['user']['username'] : 'guest') . " | Session ID: " . session_id());

// Handle actions
switch ($action) {
    
    case 'test':
        sendJsonResponse([
            'success' => true,
            'message' => 'Chat API is working',
            'timestamp' => date('Y-m-d H:i:s'),
            'session' => isset($_SESSION['user']) ? $_SESSION['user']['username'] : 'guest',
            'session_id' => session_id(),
            'php_version' => PHP_VERSION,
            'server' => $_SERVER['HTTP_HOST'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'],
            'post_data' => !empty($_POST) ? 'present' : 'empty',
            'get_data' => !empty($_GET) ? 'present' : 'empty'
        ]);
        break;
        
    case 'start_session':
        logError("Processing start_session");
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['success' => false, 'error' => 'POST method required'], 405);
        }
        
        $userData = null;
        
        if (isset($_SESSION['user']) && is_array($_SESSION['user']) && isset($_SESSION['user']['id'])) {
            // Logged in user
            $userData = ['user_id' => $_SESSION['user']['id']];
            logError("Logged in user ID: " . $_SESSION['user']['id'] . " | Session: " . session_id());
        } else {
            // Guest user
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            
            logError("Guest data - Name: {$name}, Email: {$email} | Session: " . session_id());
            
            if (empty($name) || empty($email)) {
                sendJsonResponse(['success' => false, 'error' => 'Name and email are required for guests'], 400);
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                sendJsonResponse(['success' => false, 'error' => 'Invalid email format'], 400);
            }
            
            $userData = ['name' => $name, 'email' => $email];
        }
        
        try {
            $result = $chatManager->startSession($userData);
            logError("Start session result: " . json_encode($result) . " | Session: " . session_id());
            
            // Store session info in PHP session for validation
            if ($result['success']) {
                $_SESSION['chat_session'] = [
                    'token' => $result['session_token'],
                    'session_id' => $result['session_id'],
                    'created' => time()
                ];
            }
            
            sendJsonResponse($result);
        } catch (Exception $e) {
            logError("Start session exception: " . $e->getMessage());
            sendJsonResponse(['success' => false, 'error' => 'Failed to start session'], 500);
        }
        break;
        
    case 'send_message':
        logError("Processing send_message");
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['success' => false, 'error' => 'POST method required'], 405);
        }
        
        $sessionToken = trim($_POST['session_token'] ?? '');
        $message = trim($_POST['message'] ?? '');
        
        logError("Send message - Token: {$sessionToken}, Message length: " . strlen($message) . " | Session: " . session_id());
        
        if (empty($sessionToken)) {
            sendJsonResponse(['success' => false, 'error' => 'Session token required'], 400);
        }
        
        if (empty($message)) {
            sendJsonResponse(['success' => false, 'error' => 'Message cannot be empty'], 400);
        }
        
        if (strlen($message) > 1000) {
            sendJsonResponse(['success' => false, 'error' => 'Message too long (max 1000 characters)'], 400);
        }
        
        try {
            // First check if we have this session in our PHP session
            $sessionValid = false;
            if (isset($_SESSION['chat_session']) && $_SESSION['chat_session']['token'] === $sessionToken) {
                $sessionValid = true;
                logError("Session found in PHP session");
            }
            
            $session = $chatManager->getSession($sessionToken);
            if (!$session) {
                logError("Invalid session in database for token: {$sessionToken}");
                sendJsonResponse(['success' => false, 'error' => 'Invalid session or session expired'], 404);
            }
            
            if ($session['status'] === 'ended') {
                sendJsonResponse(['success' => false, 'error' => 'Chat session has ended'], 400);
            }
            
            logError("Session found in database - ID: {$session['id']}, Status: {$session['status']} | Session: " . session_id());
            
            // Determine sender type
            $senderType = 'customer';
            $senderId = null;
            
            if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                if (in_array($_SESSION['user']['role'] ?? '', ['administrator', 'staff'])) {
                    $senderType = 'staff';
                    $senderId = $_SESSION['user']['id'];
                    logError("Sender is staff: {$senderId}");
                } else {
                    $senderId = $_SESSION['user']['id'] ?? null;
                    logError("Sender is customer: {$senderId}");
                }
            } else {
                logError("Sender is guest");
            }
            
            $result = $chatManager->addMessage($session['id'], $senderType, $senderId, $message);
            logError("Add message result: " . json_encode($result));
            sendJsonResponse($result);
            
        } catch (Exception $e) {
            logError("Send message exception: " . $e->getMessage());
            sendJsonResponse(['success' => false, 'error' => 'Failed to send message: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'get_messages':
        $sessionToken = trim($_GET['session_token'] ?? '');
        $lastMessageId = (int)($_GET['last_message_id'] ?? 0);
        
        logError("Get messages - Token: {$sessionToken}, Last ID: {$lastMessageId} | Session: " . session_id());
        
        if (empty($sessionToken)) {
            sendJsonResponse(['success' => false, 'error' => 'Session token required'], 400);
        }
        
        try {
            $session = $chatManager->getSession($sessionToken);
            if (!$session) {
                logError("Invalid session for get_messages: {$sessionToken}");
                sendJsonResponse(['success' => false, 'error' => 'Invalid session'], 404);
            }
            
            $messages = $chatManager->getMessages($session['id'], $lastMessageId);
            logError("Retrieved " . count($messages) . " messages for session " . $session['id']);
            
            // Mark customer messages as read if staff is viewing
            if (isset($_SESSION['user']) && in_array($_SESSION['user']['role'] ?? '', ['administrator', 'staff'])) {
                try {
                    $stmt = $pdo->prepare("UPDATE chat_messages SET is_read = TRUE WHERE session_id = ? AND sender_type = 'customer' AND is_read = FALSE");
                    $stmt->execute([$session['id']]);
                } catch (Exception $e) {
                    logError("Mark messages as read error: " . $e->getMessage());
                }
            }
            
            sendJsonResponse([
                'success' => true,
                'messages' => $messages,
                'session_status' => $session['status']
            ]);
            
        } catch (Exception $e) {
            logError("Get messages exception: " . $e->getMessage());
            sendJsonResponse(['success' => false, 'error' => 'Failed to get messages'], 500);
        }
        break;
        
    case 'end_session':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['success' => false, 'error' => 'POST method required'], 405);
        }
        
        $sessionToken = trim($_POST['session_token'] ?? '');
        
        if (empty($sessionToken)) {
            sendJsonResponse(['success' => false, 'error' => 'Session token required'], 400);
        }
        
        try {
            $session = $chatManager->getSession($sessionToken);
            if (!$session) {
                sendJsonResponse(['success' => false, 'error' => 'Invalid session'], 404);
            }
            
            // Check if user can end session
            $canEnd = false;
            
            if (isset($_SESSION['user']) && in_array($_SESSION['user']['role'] ?? '', ['administrator', 'staff'])) {
                $canEnd = true;
            } elseif (isset($_SESSION['user']) && ($session['user_id'] == $_SESSION['user']['id'])) {
                $canEnd = true;
            } elseif (!$session['user_id']) {
                $canEnd = true; // Guest session
            }
            
            if (!$canEnd) {
                sendJsonResponse(['success' => false, 'error' => 'Unauthorized to end this session'], 403);
            }
            
            $result = $chatManager->endSession($session['id']);
            
            // Clear PHP session
            unset($_SESSION['chat_session']);
            
            sendJsonResponse($result);
            
        } catch (Exception $e) {
            logError("End session exception: " . $e->getMessage());
            sendJsonResponse(['success' => false, 'error' => 'Failed to end session'], 500);
        }
        break;
        
    default:
        if (empty($action)) {
            sendJsonResponse(['success' => false, 'error' => 'No action specified'], 400);
        } else {
            sendJsonResponse(['success' => false, 'error' => 'Invalid action: ' . $action], 400);
        }
        break;
}

// If we get here, something went wrong
sendJsonResponse(['success' => false, 'error' => 'Unexpected error'], 500);
?>