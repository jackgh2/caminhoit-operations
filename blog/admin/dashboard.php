<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /login.php');
    exit;
}

// Check if user can manage blog (admin, support_user, etc.)
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_role = $stmt->fetchColumn();

if (!in_array($user_role, ['administrator', 'support_user', 'support_technician'])) {
    header('Location: /dashboard.php');
    exit;
}

// Get blog statistics
try {
    $stmt = $pdo->prepare("CALL GetBlogStats()");
    $stmt->execute();
    $stats = $stmt->fetch();
    $stmt->nextRowset(); // Clear the result set
} catch (Exception $e) {
    // Fallback if stored procedure doesn't exist
    $stats = [
        'total_posts' => 0,
        'published_posts' => 0,
        'draft_posts' => 0,
        'scheduled_posts' => 0,
        'active_categories' => 0,
        'total_attachments' => 0,
        'total_views' => 0
    ];
}

// Get recent posts
$stmt = $pdo->prepare("
    SELECT p.id, p.title, p.status, p.created_at, p.published_at, p.scheduled_at,
           u.username as author_name, c.name as category_name
    FROM blog_posts p
    LEFT JOIN users u ON p.author_id = u.id
    LEFT JOIN blog_categories c ON p.category_id = c.id
    ORDER BY p.created_at DESC
    LIMIT 10
");
$stmt->execute();
$recent_posts = $stmt->fetchAll();

$page_title = "Blog Dashboard | Admin";
?>

<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">
    
    <style>
        body {
            background-color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding-top: 80px;
        }

        .navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15) !important;
            padding: 12px 0 !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            z-index: 1030 !important;
        }

        .main-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .dashboard-header {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .dashboard-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            text-align: center;
            transition: all 0.2s;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .stat-card .icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin: 0 auto 1rem;
        }

        .stat-card .icon.primary { background: #4F46E5; }
        .stat-card .icon.success { background: #10B981; }
        .stat-card .icon.warning { background: #F59E0B; }
        .stat-card .icon.info { background: #06B6D4; }

        .stat-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }

        .stat-card .label {
            color: #64748B;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .quick-actions {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .quick-actions h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 1rem;
        }

        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            text-decoration: none;
            color: #374151;
            font-weight: 500;
            transition: all 0.2s;
        }

        .action-btn:hover {
            background: #4F46E5;
            color: white;
            transform: translateY(-1px);
        }

        .recent-posts {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .recent-posts h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .post-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.2s;
        }

        .post-item:hover {
            background: #f8fafc;
        }

        .post-item:last-child {
            border-bottom: none;
        }

        .post-title {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.5rem;
            text-decoration: none;
        }

        .post-title:hover {
            color: #4F46E5;
        }

        .post-meta {
            font-size: 0.875rem;
            color: #64748B;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
        }

        .badge.bg-success { background: #10B981 !important; }
        .badge.bg-warning { background: #F59E0B !important; }
        .badge.bg-secondary { background: #6B7280 !important; }
        .badge.bg-info { background: #06B6D4 !important; }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="main-container">
    <!-- Dashboard Header -->
    <div class="dashboard-header">
        <h1><i class="bi bi-speedometer2 me-3"></i>Blog Dashboard</h1>
        <p class="text-muted mb-0">Manage your blog content, categories, and settings</p>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card" onclick="location.href='/blog/admin/posts/'">
            <div class="icon primary">
                <i class="bi bi-file-text"></i>
            </div>
            <div class="value"><?= number_format($stats['total_posts'] ?? 0) ?></div>
            <div class="label">Total Posts</div>
        </div>

        <div class="stat-card" onclick="location.href='/blog/admin/posts/?status=published'">
            <div class="icon success">
                <i class="bi bi-check-circle"></i>
            </div>
            <div class="value"><?= number_format($stats['published_posts'] ?? 0) ?></div>
            <div class="label">Published Posts</div>
        </div>

        <div class="stat-card" onclick="location.href='/blog/admin/posts/?status=draft'">
            <div class="icon warning">
                <i class="bi bi-pencil-square"></i>
            </div>
            <div class="value"><?= number_format($stats['draft_posts'] ?? 0) ?></div>
            <div class="label">Draft Posts</div>
        </div>

        <div class="stat-card" onclick="location.href='/blog/admin/posts/?status=scheduled'">
            <div class="icon info">
                <i class="bi bi-clock"></i>
            </div>
            <div class="value"><?= number_format($stats['scheduled_posts'] ?? 0) ?></div>
            <div class="label">Scheduled Posts</div>
        </div>

        <div class="stat-card" onclick="location.href='/blog/admin/categories/'">
            <div class="icon primary">
                <i class="bi bi-tags"></i>
            </div>
            <div class="value"><?= number_format($stats['active_categories'] ?? 0) ?></div>
            <div class="label">Categories</div>
        </div>

        <div class="stat-card" onclick="location.href='/blog/admin/media/'">
            <div class="icon info">
                <i class="bi bi-image"></i>
            </div>
            <div class="value"><?= number_format($stats['total_attachments'] ?? 0) ?></div>
            <div class="label">Media Files</div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <h3><i class="bi bi-lightning me-2"></i>Quick Actions</h3>
        <div class="action-buttons">
            <a href="/blog/admin/posts/create.php" class="action-btn">
                <i class="bi bi-plus-circle"></i>
                Create New Post
            </a>
            <a href="/blog/admin/categories/create.php" class="action-btn">
                <i class="bi bi-tag"></i>
                Add Category
            </a>
            <a href="/blog/admin/media/upload.php" class="action-btn">
                <i class="bi bi-cloud-upload"></i>
                Upload Media
            </a>
            <a href="/blog/admin/posts/?status=scheduled" class="action-btn">
                <i class="bi bi-calendar-event"></i>
                Scheduled Posts
            </a>
            <a href="/blog/" class="action-btn">
                <i class="bi bi-eye"></i>
                View Blog
            </a>
            <a href="/blog/admin/settings.php" class="action-btn">
                <i class="bi bi-gear"></i>
                Blog Settings
            </a>
        </div>
    </div>

    <!-- Recent Posts -->
    <div class="recent-posts">
        <h3><i class="bi bi-clock-history me-2"></i>Recent Posts</h3>
        <?php if (empty($recent_posts)): ?>
            <div class="post-item text-center py-4">
                <i class="bi bi-file-text text-muted" style="font-size: 2rem;"></i>
                <p class="text-muted mt-2 mb-0">No posts yet. <a href="/blog/admin/posts/create.php">Create your first post</a></p>
            </div>
        <?php else: ?>
            <?php foreach ($recent_posts as $post): ?>
                <div class="post-item">
                    <a href="/blog/admin/posts/edit.php?id=<?= $post['id'] ?>" class="post-title">
                        <?= htmlspecialchars($post['title']) ?>
                    </a>
                    <div class="post-meta">
                        <span class="badge bg-<?= $post['status'] === 'published' ? 'success' : ($post['status'] === 'draft' ? 'warning' : ($post['status'] === 'scheduled' ? 'info' : 'secondary')) ?>">
                            <?= ucfirst($post['status']) ?>
                        </span>
                        <span><i class="bi bi-person"></i> <?= htmlspecialchars($post['author_name']) ?></span>
                        <?php if ($post['category_name']): ?>
                            <span><i class="bi bi-tag"></i> <?= htmlspecialchars($post['category_name']) ?></span>
                        <?php endif; ?>
                        <span><i class="bi bi-calendar"></i> <?= date('M j, Y', strtotime($post['created_at'])) ?></span>
                        <?php if ($post['scheduled_at']): ?>
                            <span class="text-info"><i class="bi bi-clock"></i> Scheduled: <?= date('M j, Y g:i A', strtotime($post['scheduled_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>