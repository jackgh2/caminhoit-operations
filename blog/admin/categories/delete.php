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
    $category_id = (int)$_POST['id'];
    
    try {
        // Check if category has posts
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM blog_posts WHERE category_id = ?");
        $stmt->execute([$category_id]);
        $post_count = $stmt->fetchColumn();
        
        if ($post_count > 0) {
            header('Location: index.php?error=Cannot delete category with posts');
            exit;
        }
        
        // Check if category has child categories
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM blog_categories WHERE parent_id = ?");
        $stmt->execute([$category_id]);
        $child_count = $stmt->fetchColumn();
        
        if ($child_count > 0) {
            header('Location: index.php?error=Cannot delete category with sub-categories');
            exit;
        }
        
        // Delete the category
        $stmt = $pdo->prepare("DELETE FROM blog_categories WHERE id = ?");
        $stmt->execute([$category_id]);
        
        header('Location: index.php?success=Category deleted successfully');
        exit;
        
    } catch (Exception $e) {
        header('Location: index.php?error=' . urlencode('Error deleting category: ' . $e->getMessage()));
        exit;
    }
}

header('Location: index.php');
exit;
?>