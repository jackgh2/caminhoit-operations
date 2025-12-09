<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Access control (Staff and Admin only)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'staff'])) {
    header('Location: /login.php');
    exit;
}

// Get KB statistics
$stats = [];

try {
    // Total articles
    $stmt = $pdo->query("SELECT COUNT(*) FROM kb_articles");
    $stats['total_articles'] = $stmt->fetchColumn();

    // Published articles
    $stmt = $pdo->query("SELECT COUNT(*) FROM kb_articles WHERE status = 'published'");
    $stats['published_articles'] = $stmt->fetchColumn();

    // Draft articles
    $stmt = $pdo->query("SELECT COUNT(*) FROM kb_articles WHERE status = 'draft'");
    $stats['draft_articles'] = $stmt->fetchColumn();

    // Total categories
    $stmt = $pdo->query("SELECT COUNT(*) FROM kb_categories WHERE is_active = 1");
    $stats['total_categories'] = $stmt->fetchColumn();

    // Total views (last 30 days)
    $stmt = $pdo->query("SELECT COUNT(*) FROM kb_article_views WHERE viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stats['recent_views'] = $stmt->fetchColumn();

    // Popular articles (last 30 days)
    $stmt = $pdo->query("
        SELECT a.id, a.title, a.slug, COUNT(av.id) as view_count 
        FROM kb_articles a 
        LEFT JOIN kb_article_views av ON a.id = av.article_id AND av.viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        WHERE a.status = 'published'
        GROUP BY a.id 
        ORDER BY view_count DESC 
        LIMIT 5
    ");
    $stats['popular_articles'] = $stmt->fetchAll();

    // Recent articles
    $stmt = $pdo->query("
        SELECT a.id, a.title, a.slug, a.status, a.created_at, u.username as author
        FROM kb_articles a 
        JOIN users u ON a.author_id = u.id 
        ORDER BY a.created_at DESC 
        LIMIT 5
    ");
    $stats['recent_articles'] = $stmt->fetchAll();

    // Feedback summary
    $stmt = $pdo->query("
        SELECT 
            SUM(CASE WHEN is_helpful = 1 THEN 1 ELSE 0 END) as helpful_count,
            SUM(CASE WHEN is_helpful = 0 THEN 1 ELSE 0 END) as not_helpful_count
        FROM kb_article_feedback 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $feedback = $stmt->fetch();
    $stats['helpful_feedback'] = $feedback['helpful_count'] ?? 0;
    $stats['not_helpful_feedback'] = $feedback['not_helpful_count'] ?? 0;

} catch (Exception $e) {
    $error = "Error loading statistics: " . $e->getMessage();
}

$page_title = "Knowledge Base Dashboard";
?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

    <style>
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
        .kb-icon {
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            color: white;
            font-size: 12px;
            font-weight: 600;
        }

        /* Dark Mode Styles */
        :root.dark body {
            background: #0f172a !important;
        }

        :root.dark .card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .card-header {
            background: #1e293b !important;
            border-bottom-color: #334155 !important;
        }

        :root.dark .card-body {
            color: #e2e8f0 !important;
        }

        :root.dark .card h5,
        :root.dark .card h4,
        :root.dark .card h3,
        :root.dark .card h6 {
            color: #f1f5f9 !important;
        }

        :root.dark .card-title {
            color: #f1f5f9 !important;
        }

        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark .table {
            color: #e2e8f0 !important;
        }

        :root.dark .table thead {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
        }

        :root.dark .table th {
            color: white !important;
        }

        :root.dark .table td {
            color: #cbd5e1 !important;
            border-color: #334155 !important;
        }

        :root.dark .table tbody tr:hover {
            background: #0f172a !important;
        }

        :root.dark .list-group-item {
            background: transparent !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .list-group-item:hover {
            background: #0f172a !important;
        }

        :root.dark .list-group-item h6 {
            color: #f1f5f9 !important;
        }

        :root.dark .list-group-item a {
            color: #a78bfa !important;
        }

        :root.dark .list-group-item a:hover {
            color: #c4b5fd !important;
        }

        :root.dark .list-group-item small {
            color: #94a3b8 !important;
        }

        :root.dark .badge {
            color: white !important;
        }

        :root.dark .alert {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }
    </style>

<!-- HERO -->
<header class="hero">
    <div class="hero-gradient"></div>

    <div class="container position-relative">
        <div class="row align-items-center">
            <div class="col-lg-7">
                <div class="hero-eyebrow">
                    <i class="bi bi-book"></i>
                    Knowledge Base Management
                </div>

                <h1 class="hero-title">
                    <span class="hero-title-line">
                        Manage Your
                    </span>
                    <span class="hero-title-line hero-title-highlight">
                        Knowledge Base
                        <span class="hero-title-highlight-tail"></span>
                    </span>
                    <span class="hero-title-line">
                        Content
                    </span>
                </h1>

                <p class="hero-subtitle">
                    Create, edit, and organize help articles, manage categories, and track article performance.
                </p>

                <div class="hero-cta d-flex flex-wrap align-items-center gap-2">
                    <a href="/operations/kb-articles-create.php" class="btn c-btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>
                        New Article
                    </a>
                    <a href="/operations/kb-categories.php" class="btn c-btn-ghost">
                        <i class="bi bi-tags me-1"></i>
                        Categories
                    </a>
                </div>

                <div class="hero-meta">
                    <span><i class="bi bi-file-text"></i> <?= number_format($stats['total_articles']) ?> Articles</span>
                    <span><i class="bi bi-eye"></i> <?= number_format($stats['recent_views']) ?> Recent Views</span>
                </div>
            </div>

            <!-- Snapshot card -->
            <div class="col-lg-5 mt-5 mt-lg-0 d-none d-lg-block">
                <div class="snapshot-card">
                    <div class="snapshot-header">
                        <span class="snapshot-label">Quick Stats</span>
                    </div>

                    <div class="snapshot-body">
                        <div class="snapshot-metric">
                            <span class="snapshot-metric-main"><?= number_format($stats['published_articles']) ?></span>
                            <span class="snapshot-metric-sub">published articles</span>
                        </div>

                        <ul class="snapshot-list">
                            <li>
                                <i class="bi bi-file-earmark"></i>
                                <?= number_format($stats['draft_articles']) ?> drafts in progress
                            </li>
                            <li>
                                <i class="bi bi-tags"></i>
                                <?= number_format($stats['total_categories']) ?> active categories
                            </li>
                            <li>
                                <i class="bi bi-graph-up"></i>
                                Content management tools
                            </li>
                        </ul>

                        <a href="/kb/" class="snapshot-cta">
                            View public KB
                            <i class="bi bi-arrow-right-short"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                            <i class="bi bi-file-text"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="fw-bold mb-0"><?= number_format($stats['total_articles']) ?></h5>
                                        <p class="text-muted small mb-0">Total Articles</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 mb-3">
                        <div class="card stat-card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                            <i class="bi bi-check-circle"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="fw-bold mb-0"><?= number_format($stats['published_articles']) ?></h5>
                                        <p class="text-muted small mb-0">Published</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 mb-3">
                        <div class="card stat-card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <div class="bg-warning text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                            <i class="bi bi-file-earmark"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="fw-bold mb-0"><?= number_format($stats['draft_articles']) ?></h5>
                                        <p class="text-muted small mb-0">Drafts</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 mb-3">
                        <div class="card stat-card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <div class="bg-info text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                            <i class="bi bi-eye"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <h5 class="fw-bold mb-0"><?= number_format($stats['recent_views']) ?></h5>
                                        <p class="text-muted small mb-0">Views (30 days)</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Content Row -->
                <div class="row">
                    <!-- Popular Articles -->
                    <div class="col-lg-6 mb-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white border-0 pb-0">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-trending-up text-success me-2"></i>
                                    Popular Articles (30 days)
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if (empty($stats['popular_articles'])): ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-file-text text-muted" style="font-size: 2rem;"></i>
                                        <p class="text-muted mb-0 mt-2">No articles with views yet</p>
                                    </div>
                                <?php else: ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($stats['popular_articles'] as $article): ?>
                                            <div class="list-group-item border-0 px-0">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div class="flex-grow-1">
                                                        <a href="/operations/kb-articles-edit.php?id=<?= $article['id'] ?>" class="text-decoration-none">
                                                            <h6 class="mb-1"><?= htmlspecialchars($article['title']) ?></h6>
                                                        </a>
                                                        <small class="text-muted"><?= $article['view_count'] ?> views</small>
                                                    </div>
                                                    <span class="badge bg-primary"><?= $article['view_count'] ?></span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Articles -->
                    <div class="col-lg-6 mb-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white border-0 pb-0">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-clock text-info me-2"></i>
                                    Recent Articles
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if (empty($stats['recent_articles'])): ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-file-plus text-muted" style="font-size: 2rem;"></i>
                                        <p class="text-muted mb-0 mt-2">No articles created yet</p>
                                        <a href="/operations/kb-articles-create.php" class="btn btn-sm btn-primary mt-2">
                                            Create First Article
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($stats['recent_articles'] as $article): ?>
                                            <div class="list-group-item border-0 px-0">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div class="flex-grow-1">
                                                        <a href="/operations/kb-articles-edit.php?id=<?= $article['id'] ?>" class="text-decoration-none">
                                                            <h6 class="mb-1"><?= htmlspecialchars($article['title']) ?></h6>
                                                        </a>
                                                        <small class="text-muted">
                                                            by <?= htmlspecialchars($article['author']) ?> • 
                                                            <?= date('M j, Y', strtotime($article['created_at'])) ?>
                                                        </small>
                                                    </div>
                                                    <?php
                                                    $status_colors = [
                                                        'published' => 'success',
                                                        'draft' => 'warning',
                                                        'archived' => 'secondary'
                                                    ];
                                                    $color = $status_colors[$article['status']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?= $color ?>"><?= ucfirst($article['status']) ?></span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Feedback Summary -->
                <div class="row">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white border-0 pb-0">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-chat-square-heart text-warning me-2"></i>
                                    Feedback Summary (30 days)
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-md-6">
                                        <div class="border-end">
                                            <h4 class="text-success mb-1">
                                                <i class="bi bi-hand-thumbs-up me-2"></i>
                                                <?= number_format($stats['helpful_feedback']) ?>
                                            </h4>
                                            <p class="text-muted mb-0">Helpful Votes</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <h4 class="text-danger mb-1">
                                            <i class="bi bi-hand-thumbs-down me-2"></i>
                                            <?= number_format($stats['not_helpful_feedback']) ?>
                                        </h4>
                                        <p class="text-muted mb-0">Not Helpful Votes</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white border-0 pb-0">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-lightning text-primary me-2"></i>
                                    Quick Actions
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3 mb-2">
                                        <a href="/operations/kb-articles.php" class="btn btn-outline-primary w-100">
                                            <i class="bi bi-file-text me-2"></i>
                                            Manage Articles
                                        </a>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <a href="/operations/kb-categories.php" class="btn btn-outline-success w-100">
                                            <i class="bi bi-tags me-2"></i>
                                            Manage Categories
                                        </a>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <a href="/operations/kb-feedback.php" class="btn btn-outline-info w-100">
                                            <i class="bi bi-chat-square me-2"></i>
                                            View Feedback
                                        </a>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <a href="/operations/kb-settings.php" class="btn btn-outline-warning w-100">
                                            <i class="bi bi-gear me-2"></i>
                                            Settings
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>