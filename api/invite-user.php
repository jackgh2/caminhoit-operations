<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Set JSON header
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'administrator') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$company_id = (int)($input['company_id'] ?? 0);
$email = trim($input['email'] ?? '');

if (!$company_id || !$email) {
    http_response_code(400);
    echo json_encode(['error' => 'Company ID and email are required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email address']);
    exit;
}

try {
    // Get company info
    $stmt = $pdo->prepare("SELECT name FROM companies WHERE id = ? AND is_active = 1");
    $stmt->execute([$company_id]);
    $company = $stmt->fetch();
    
    if (!$company) {
        http_response_code(404);
        echo json_encode(['error' => 'Company not found or inactive']);
        exit;
    }
    
    // Check if user already exists
    $stmt = $pdo->prepare("SELECT id, company_id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $existing_user = $stmt->fetch();
    
    if ($existing_user) {
        // User exists, check if already assigned to this company
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company_users WHERE user_id = ? AND company_id = ?");
        $stmt->execute([$existing_user['id'], $company_id]);
        $already_assigned = $stmt->fetchColumn();
        
        if ($existing_user['company_id'] == $company_id || $already_assigned) {
            echo json_encode(['error' => 'User is already assigned to this company']);
            exit;
        }
        
        // Add existing user to company
        $stmt = $pdo->prepare("INSERT INTO company_users (user_id, company_id, role) VALUES (?, ?, 'supported_user')");
        $stmt->execute([$existing_user['id'], $company_id]);
        
        // Send notification email to existing user
        $subject = "You've been added to " . $company['name'];
        $message = "Hello,\n\nYou have been added to " . $company['name'] . " by " . $_SESSION['user']['username'] . ".\n\nYou can now access company resources through your existing account.\n\nBest regards,\nCaminhoIT Team";
        
        // Send email (you'll need to implement your email sending logic)
        // mail($email, $subject, $message);
        
        echo json_encode(['success' => 'User added to company successfully']);
    } else {
        // User doesn't exist, create invitation
        $invitation_token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
        
        // Create invitation record
        $stmt = $pdo->prepare("INSERT INTO user_invitations (email, company_id, invitation_token, expires_at, invited_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$email, $company_id, $invitation_token, $expires_at, $_SESSION['user']['id']]);
        
        // Send invitation email
        $invitation_url = "https://" . $_SERVER['HTTP_HOST'] . "/accept-invitation.php?token=" . $invitation_token;
        $subject = "Invitation to join " . $company['name'] . " on CaminhoIT";
        $message = "Hello,\n\nYou have been invited to join " . $company['name'] . " on CaminhoIT by " . $_SESSION['user']['username'] . ".\n\nClick the link below to accept the invitation and create your account:\n\n" . $invitation_url . "\n\nThis invitation will expire in 7 days.\n\nBest regards,\nCaminhoIT Team";
        
        // Send email (you'll need to implement your email sending logic)
        // mail($email, $subject, $message);
        
        echo json_encode(['success' => 'Invitation sent successfully']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>