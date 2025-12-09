<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Check permissions
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_role = $stmt->fetchColumn();

if (!in_array($user_role, ['administrator', 'support_user', 'support_technician'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$attachment_id = $input['id'] ?? 0;

if (!$attachment_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Attachment ID required']);
    exit;
}

try {
    // Get attachment info
    $stmt = $pdo->prepare("SELECT * FROM blog_post_attachments WHERE id = ?");
    $stmt->execute([$attachment_id]);
    $attachment = $stmt->fetch();
    
    if (!$attachment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Attachment not found']);
        exit;
    }
    
    // Check if user owns the post or is admin
    $stmt = $pdo->prepare("SELECT author_id FROM blog_posts WHERE id = ?");
    $stmt->execute([$attachment['post_id']]);
    $post_author = $stmt->fetchColumn();
    
    if ($post_author != $user['id'] && !in_array($user_role, ['administrator'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    
    // Delete file from filesystem
    $file_path = $_SERVER['DOCUMENT_ROOT'] . $attachment['file_path'];
    if (file_exists($file_path)) {
        unlink($file_path);
    }
    
    // Delete from database
    $stmt = $pdo->prepare("DELETE FROM blog_post_attachments WHERE id = ?");
    $stmt->execute([$attachment_id]);
    
    echo json_encode(['success' => true, 'message' => 'Attachment deleted successfully']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Delete failed: ' . $e->getMessage()]);
}
?>