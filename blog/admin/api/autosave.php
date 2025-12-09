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

try {
    $post_id = $_POST['post_id'] ?? null;
    $title = $_POST['title'] ?? '';
    $content = $_POST['content'] ?? '';
    $excerpt = $_POST['excerpt'] ?? '';
    
    if (empty($title) && empty($content)) {
        echo json_encode(['success' => false, 'error' => 'Nothing to save']);
        exit;
    }
    
    if ($post_id) {
        // Update existing post
        $stmt = $pdo->prepare("
            UPDATE blog_posts 
            SET title = ?, content = ?, excerpt = ?, updated_at = NOW()
            WHERE id = ? AND author_id = ?
        ");
        $stmt->execute([$title, $content, $excerpt, $post_id, $user['id']]);
    } else {
        // Save as temporary draft in session
        $_SESSION['blog_autosave'] = [
            'title' => $title,
            'content' => $content,
            'excerpt' => $excerpt,
            'timestamp' => time()
        ];
    }
    
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Auto-saved successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Auto-save failed: ' . $e->getMessage()]);
}
?>