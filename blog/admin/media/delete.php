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
    $media_id = (int)$_POST['id'];
    
    try {
        // Get media info
        $stmt = $pdo->prepare("SELECT * FROM blog_media_library WHERE id = ?");
        $stmt->execute([$media_id]);
        $media = $stmt->fetch();
        
        if (!$media) {
            header('Location: index.php?error=Media file not found');
            exit;
        }
        
        // Check if user owns the file or is admin
        if ($media['uploaded_by'] != $user['id'] && !in_array($user_role, ['administrator'])) {
            header('Location: index.php?error=Permission denied');
            exit;
        }
        
        // Check if file is being used in any posts
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM blog_post_attachments 
            WHERE file_path = ? 
            UNION ALL 
            SELECT COUNT(*) FROM blog_posts 
            WHERE featured_image = ?
        ");
        $stmt->execute([$media['file_path'], $media['file_path']]);
        $usage_counts = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $total_usage = array_sum($usage_counts);
        
        if ($total_usage > 0) {
            header('Location: index.php?error=Cannot delete: file is being used in ' . $total_usage . ' post(s)');
            exit;
        }
        
        // Delete file from filesystem
        $file_path = $_SERVER['DOCUMENT_ROOT'] . $media['file_path'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }
        
        // Delete from database
        $stmt = $pdo->prepare("DELETE FROM blog_media_library WHERE id = ?");
        $stmt->execute([$media_id]);
        
        header('Location: index.php?success=Media file deleted successfully');
        exit;
        
    } catch (Exception $e) {
        header('Location: index.php?error=' . urlencode('Error deleting media: ' . $e->getMessage()));
        exit;
    }
}

header('Location: index.php');
exit;
?>