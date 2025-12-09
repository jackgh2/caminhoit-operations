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

// Function to log errors with more detail
function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    $sessionId = session_id();
    error_log("Chat API Debug [{$timestamp}] [Session: {$sessionId}]: " . $message);
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
    sendJsonResponse(['success' => false, 'error' => 'Server error', 'details' => $e->getMessage()], 500);
}

// Get action from request
$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
} else {
    $action = $_POST['action'] ?? '';
}

// Log the request with more details
logError("=== NEW REQUEST ===");
logError("Action: {$action} | Method: {$_SERVER['REQUEST_METHOD']} | User: " . (isset($_SESSION['user']) ? $_SESSION['user']['username'] : 'guest'));
logError("POST data: " . json_encode($_POST));
logError("GET data: " . json_encode($_GET));

// Handle actions
switch ($action) {
    
    case 'test':
        sendJsonResponse([
            'success' => true,
            'message' => 'Chat API Debug is working',
            'timestamp' => date('Y-m-d H:i:s'),
            'session' => isset($_SESSION['user']) ? $_SESSION['user']['username'] : 'guest',
            'session_id' => session_id(),
            'php_version' => PHP_VERSION,
            'method' => $_SERVER['REQUEST_METHOD'],
            'post_data' => $_POST,
            'get_data' => $_GET
        ]);
        break;
        
    case 'start_session':
        logError("=== PROCESSING START SESSION ===");
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['success' => false, 'error' => 'POST method required'], 405);
        }
        
        $userData = null;
        
        if (isset($_SESSION['user']) && is_array($_SESSION['user']) && isset($_SESSION['user']['id'])) {
            // Logged in user
            $userData = ['user_id' => $_SESSION['user']['id']];
            logError("Logged in user ID: " . $_SESSION['user']['id']);
        } else {
            // Guest user
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            
            logError("Guest data - Name: '{$name}', Email: '{$email}'");
            
            if (empty($name) || empty($email)) {
                logError("ERROR: Empty name or email");
                sendJsonResponse(['success' => false, 'error' => 'Name and email are required for guests'], 400);
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                logError("ERROR: Invalid email format");
                sendJsonResponse(['success' => false, 'error' => 'Invalid email format'], 400);
            }
            
            $userData = ['name' => $name, 'email' => $email];
        }
        
        try {
            logError("Calling ChatManager::startSession with: " . json_encode($userData));
            $result = $chatManager->startSession($userData);
            logError("ChatManager::startSession result: " . json_encode($result));
            
            if ($result['success']) {
                // Store session info in PHP session for validation
                $_SESSION['chat_session'] = [
                    'token' => $result['session_token'],
                    'session_id' => $result['session_id'],
                    'created' => time()
                ];
                logError("Stored session in PHP session: " . json_encode($_SESSION['chat_session']));
                
                // Immediately test if we can retrieve the session
                logError("=== IMMEDIATE SESSION VERIFICATION ===");
                $testSession = $chatManager->getSession($result['session_token']);
                if ($testSession) {
                    logError("✓ Session verification PASSED - found session ID: " . $testSession['id']);
                    $result['verification'] = 'passed';
                } else {
                    logError("❌ Session verification FAILED - could not retrieve session immediately after creation");
                    $result['verification'] = 'failed';
                    
                    // Let's check the database directly
                    try {
                        $stmt = $pdo->prepare("SELECT * FROM chat_sessions WHERE session_token = ?");
                        $stmt->execute([$result['session_token']]);
                        $dbSession = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($dbSession) {
                            logError("Database check: Session exists with status: " . $dbSession['status']);
                            $result['db_status'] = $dbSession['status'];
                            $result['db_session_id'] = $dbSession['id'];
                        } else {
                            logError("Database check: Session NOT found in database");
                            $result['db_status'] = 'not_found';
                        }
                    } catch (Exception $e) {
                        logError("Database check error: " . $e->getMessage());
                        $result['db_error'] = $e->getMessage();
                    }
                }
            }
            
            sendJsonResponse($result);
        } catch (Exception $e) {
            logError("Start session exception: " . $e->getMessage());
            sendJsonResponse(['success' => false, 'error' => 'Failed to start session', 'details' => $e->getMessage()], 500);
        }
        break;
        
    case 'send_message':
        logError("=== PROCESSING SEND MESSAGE ===");
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['success' => false, 'error' => 'POST method required'], 405);
        }
        
        $sessionToken = trim($_POST['session_token'] ?? '');
        $message = trim($_POST['message'] ?? '');
        
        logError("Session token: '{$sessionToken}', Message: '{$message}'");
        
        if (empty($sessionToken)) {
            logError("ERROR: Missing session token");
            sendJsonResponse(['success' => false, 'error' => 'Session token required'], 400);
        }
        
        if (empty($message)) {
            logError("ERROR: Empty message");
            sendJsonResponse(['success' => false, 'error' => 'Message cannot be empty'], 400);
        }
        
        // Check if we have this session in our PHP session
        if (isset($_SESSION['chat_session'])) {
            logError("PHP session contains: " . json_encode($_SESSION['chat_session']));
            if ($_SESSION['chat_session']['token'] === $sessionToken) {
                logError("✓ Session token matches PHP session");
            } else {
                logError("❌ Session token does NOT match PHP session");
            }
        } else {
            logError("❌ No chat session found in PHP session");
        }
        
        try {
            logError("=== CALLING ChatManager::getSession ===");
            $session = $chatManager->getSession($sessionToken);
            
            if (!$session) {
                logError("❌ ChatManager::getSession returned null");
                
                // Let's debug the database directly
                $stmt = $pdo->prepare("SELECT * FROM chat_sessions WHERE session_token = ?");
                $stmt->execute([$sessionToken]);
                $dbSession = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($dbSession) {
                    logError("Database direct check: Session exists!");
                    logError("Session data: " . json_encode($dbSession));
                    
                    // Check if it's the status filter
                    if ($dbSession['status'] === 'ended') {
                        logError("Session is ended");
                        sendJsonResponse(['success' => false, 'error' => 'Chat session has ended'], 400);
                    } else {
                        logError("Session status is: " . $dbSession['status'] . " - should be valid");
                        sendJsonResponse(['success' => false, 'error' => 'Session found in database but getSession() failed', 'debug' => $dbSession], 500);
                    }
                } else {
                    logError("Database direct check: Session NOT found");
                    sendJsonResponse(['success' => false, 'error' => 'Session not found in database'], 404);
                }
                
                sendJsonResponse(['success' => false, 'error' => 'Invalid session or session expired'], 404);
            }
            
            logError("✓ ChatManager::getSession returned session ID: " . $session['id']);
            
            if ($session['status'] === 'ended') {
                logError("ERROR: Session status is 'ended'");
                sendJsonResponse(['success' => false, 'error' => 'Chat session has ended'], 400);
            }
            
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
            
            logError("=== CALLING ChatManager::addMessage ===");
            $result = $chatManager->addMessage($session['id'], $senderType, $senderId, $message);
            logError("ChatManager::addMessage result: " . json_encode($result));
            
            sendJsonResponse($result);
            
        } catch (Exception $e) {
            logError("Send message exception: " . $e->getMessage());
            sendJsonResponse(['success' => false, 'error' => 'Failed to send message', 'details' => $e->getMessage()], 500);
        }
        break;
        
    case 'debug_session':
        logError("=== DEBUG SESSION REQUEST ===");
        
        $sessionToken = $_REQUEST['session_token'] ?? '';
        
        if (empty($sessionToken)) {
            sendJsonResponse(['success' => false, 'error' => 'Session token required for debug'], 400);
        }
        
        logError("Debugging session token: " . substr($sessionToken, 0, 8) . "...");
        
        $debugInfo = [];
        
        try {
            // Direct database query
            $stmt = $pdo->prepare("SELECT * FROM chat_sessions WHERE session_token = ?");
            $stmt->execute([$sessionToken]);
            $dbSession = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $debugInfo['database_session'] = $dbSession;
            
            // ChatManager getSession
            $managerSession = $chatManager->getSession($sessionToken);
            $debugInfo['manager_session'] = $managerSession;
            
            // PHP session
            $debugInfo['php_session'] = $_SESSION['chat_session'] ?? null;
            
            // Recent sessions
            $stmt = $pdo->prepare("SELECT id, session_token, status, created_at FROM chat_sessions ORDER BY created_at DESC LIMIT 5");
            $stmt->execute();
            $recentSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $debugInfo['recent_sessions'] = $recentSessions;
            
            sendJsonResponse(['success' => true, 'debug' => $debugInfo]);
            
        } catch (Exception $e) {
            logError("Debug session exception: " . $e->getMessage());
            sendJsonResponse(['success' => false, 'error' => 'Debug failed', 'details' => $e->getMessage()], 500);
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