<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /login.php');
    exit;
}

// Check permissions
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_role = $stmt->fetchColumn();

if (!in_array($user_role, ['administrator', 'support_user', 'support_technician'])) {
    header('Location: /dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $post_id = (int)$_POST['id'];
    
    try {
        // Verify post ownership or admin privileges
        $stmt = $pdo->prepare("SELECT author_id, title FROM blog_posts WHERE id = ?");
        $stmt->execute([$post_id]);
        $post = $stmt->fetch();
        
        if (!$post) {
            header('Location: index.php?error=Post not found');
            exit;
        }
        
        // Check if user can delete this post
        if ($post['author_id'] != $user['id'] && !in_array($user_role, ['administrator'])) {
            header('Location: index.php?error=Permission denied');
            exit;
        }
        
        $pdo->beginTransaction();
        
        // Delete associated data
        $pdo->prepare("DELETE FROM blog_post_tags WHERE post_id = ?")->execute([$post_id]);
        $pdo->prepare("DELETE FROM blog_post_revisions WHERE post_id = ?")->execute([$post_id]);
        $pdo->prepare("DELETE FROM blog_post_attachments WHERE post_id = ?")->execute([$post_id]);
        
        // Delete the post
        $stmt = $pdo->prepare("DELETE FROM blog_posts WHERE id = ?");
        $stmt->execute([$post_id]);
        
        $pdo->commit();
        
        header('Location: index.php?success=Post deleted successfully');
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        header('Location: index.php?error=' . urlencode('Error deleting post: ' . $e->getMessage()));
        exit;
    }
}

header('Location: index.php');
exit;
?>