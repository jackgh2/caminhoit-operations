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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Generate a unique token for this preview
    $token = bin2hex(random_bytes(16));
    
    // Store preview data in session
    $_SESSION['blog_preview'][$token] = [
        'title' => $_POST['title'] ?? '',
        'content' => $_POST['content'] ?? '',
        'excerpt' => $_POST['excerpt'] ?? '',
        'category_id' => $_POST['category_id'] ?? null,
        'featured_image' => $_POST['featured_image'] ?? '',
        'tags' => $_POST['tags'] ?? '',
        'timestamp' => time(),
        'user_id' => $user['id']
    ];
    
    // Clean up old preview sessions (older than 1 hour)
    if (isset($_SESSION['blog_preview'])) {
        foreach ($_SESSION['blog_preview'] as $key => $data) {
            if (time() - $data['timestamp'] > 3600) {
                unset($_SESSION['blog_preview'][$key]);
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'token' => $token,
        'preview_url' => '/blog/admin/preview.php?token=' . $token
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Preview save failed: ' . $e->getMessage()]);
}
?>