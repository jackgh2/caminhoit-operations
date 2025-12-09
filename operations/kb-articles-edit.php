<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Access control (Staff and Admin only)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'support_consultant', 'accountant'])) {
    header('Location: /login.php');
    exit;
}

// Must have article ID for editing
$article_id = $_GET['id'] ?? null;
if (!$article_id) {
    header('Location: /operations/kb-articles.php');
    exit;
}

// Get TinyMCE API key from settings
$tinymce_api_key = 'no-api-key';
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM kb_settings WHERE setting_key = 'tinymce_api_key'");
    $stmt->execute();
    $api_key = $stmt->fetchColumn();
    if (!empty($api_key)) {
        $tinymce_api_key = $api_key;
    }
} catch (Exception $e) {
    // Use default if error
}

// Initialize article data
$article = null;
$tags = [];

// Load existing article
try {
    $stmt = $pdo->prepare("
        SELECT a.*, c.name as category_name, u.username as author_name
        FROM kb_articles a
        LEFT JOIN kb_categories c ON a.category_id = c.id
        LEFT JOIN users u ON a.author_id = u.id
        WHERE a.id = ?
    ");
    $stmt->execute([$article_id]);
    $article = $stmt->fetch();
    
    if (!$article) {
        $_SESSION['error'] = "Article not found.";
        header('Location: /operations/kb-articles.php');
        exit;
    }
    
    // Load tags
    $stmt = $pdo->prepare("SELECT tag_name FROM kb_article_tags WHERE article_id = ? ORDER BY tag_name");
    $stmt->execute([$article_id]);
    $tags = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get article statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(v.id) as total_views,
            COUNT(CASE WHEN v.viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as views_30d,
            COUNT(CASE WHEN v.viewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as views_7d
        FROM kb_article_views v 
        WHERE v.article_id = ?
    ");
    $stmt->execute([$article_id]);
    $stats = $stmt->fetch();
    
    // Get feedback stats
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN is_helpful = 1 THEN 1 ELSE 0 END) as helpful_count,
            SUM(CASE WHEN is_helpful = 0 THEN 1 ELSE 0 END) as not_helpful_count,
            COUNT(*) as total_feedback
        FROM kb_article_feedback 
        WHERE article_id = ?
    ");
    $stmt->execute([$article_id]);
    $feedback_stats = $stmt->fetch();
    
} catch (Exception $e) {
    $error = "Error loading article: " . $e->getMessage();
}

// Handle form submission
if ($_POST && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'update_article') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $excerpt = trim($_POST['excerpt'] ?? '');
        $category_id = (int)($_POST['category_id'] ?? 0);
        $status = $_POST['status'] ?? 'draft';
        $visibility = $_POST['visibility'] ?? 'public';
        $featured = isset($_POST['featured']) ? 1 : 0;
        $meta_title = trim($_POST['meta_title'] ?? '');
        $meta_description = trim($_POST['meta_description'] ?? '');
        $search_keywords = trim($_POST['search_keywords'] ?? '');
        $tags_input = trim($_POST['tags'] ?? '');
        
        // Generate slug from title if not provided
        $slug = trim($_POST['slug'] ?? '');
        if (empty($slug)) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
            $slug = preg_replace('/-+/', '-', $slug); // Remove multiple hyphens
            $slug = trim($slug, '-'); // Remove leading/trailing hyphens
        }
        
        // Validation
        $errors = [];
        
        if (empty($title)) {
            $errors[] = "Title is required.";
        }
        
        if (empty($content)) {
            $errors[] = "Content is required.";
        }
        
        if (empty($category_id)) {
            $errors[] = "Category is required.";
        }
        
        if (empty($slug)) {
            $errors[] = "Slug is required.";
        } else {
            // Check if slug is unique (excluding current article)
            $stmt = $pdo->prepare("SELECT id FROM kb_articles WHERE slug = ? AND id != ?");
            $stmt->execute([$slug, $article_id]);
            if ($stmt->fetchColumn()) {
                $errors[] = "Slug already exists. Please choose a different one.";
            }
        }
        
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                // Update article
                $stmt = $pdo->prepare("
                    UPDATE kb_articles SET 
                        title = ?, slug = ?, content = ?, excerpt = ?, category_id = ?, 
                        status = ?, visibility = ?, featured = ?, meta_title = ?, 
                        meta_description = ?, search_keywords = ?,
                        published_at = CASE 
                            WHEN status != 'published' AND ? = 'published' THEN NOW() 
                            WHEN status = 'published' AND ? != 'published' THEN NULL
                            ELSE published_at 
                        END,
                        last_reviewed_at = NOW(),
                        last_reviewed_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $title, $slug, $content, $excerpt, $category_id, $status, 
                    $visibility, $featured, $meta_title, $meta_description, 
                    $search_keywords, $article['status'], $status, 
                    $_SESSION['user']['id'], $article_id
                ]);
                
                // Update tags
                $stmt = $pdo->prepare("DELETE FROM kb_article_tags WHERE article_id = ?");
                $stmt->execute([$article_id]);
                
                if (!empty($tags_input)) {
                    $new_tags = array_map('trim', explode(',', $tags_input));
                    $new_tags = array_filter($new_tags); // Remove empty tags
                    
                    foreach ($new_tags as $tag) {
                        if (!empty($tag)) {
                            $stmt = $pdo->prepare("INSERT INTO kb_article_tags (article_id, tag_name) VALUES (?, ?)");
                            $stmt->execute([$article_id, $tag]);
                        }
                    }
                }
                
                $pdo->commit();
                
                // Update local article data
                $article['title'] = $title;
                $article['slug'] = $slug;
                $article['content'] = $content;
                $article['excerpt'] = $excerpt;
                $article['category_id'] = $category_id;
                $article['status'] = $status;
                $article['visibility'] = $visibility;
                $article['featured'] = $featured;
                $article['meta_title'] = $meta_title;
                $article['meta_description'] = $meta_description;
                $article['search_keywords'] = $search_keywords;
                $tags = $new_tags;
                
                $success_message = "Article updated successfully!";
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error updating article: " . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'quick_publish') {
        try {
            $new_status = $article['status'] === 'published' ? 'draft' : 'published';
            $stmt = $pdo->prepare("
                UPDATE kb_articles SET 
                    status = ?,
                    published_at = CASE WHEN ? = 'published' THEN NOW() ELSE NULL END,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$new_status, $new_status, $article_id]);
            
            $article['status'] = $new_status;
            $success_message = $new_status === 'published' ? "Article published!" : "Article moved to draft.";
            
        } catch (Exception $e) {
            $error = "Error updating status: " . $e->getMessage();
        }
    }
    
    elseif ($action === 'toggle_featured') {
        try {
            $new_featured = $article['featured'] ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE kb_articles SET featured = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_featured, $article_id]);
            
            $article['featured'] = $new_featured;
            $success_message = $new_featured ? "Article marked as featured!" : "Article unmarked as featured.";
            
        } catch (Exception $e) {
            $error = "Error updating featured status: " . $e->getMessage();
        }
    }
}

// Load categories
try {
    $stmt = $pdo->query("SELECT id, name, color FROM kb_categories WHERE is_active = 1 ORDER BY sort_order, name");
    $categories = $stmt->fetchAll();
} catch (Exception $e) {
    $categories = [];
}

$page_title = "Edit Article: " . ($article['title'] ?? 'Unknown');
?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

    <!-- HERO -->
    <header class="hero">
        <div class="hero-gradient"></div>
        <div class="container position-relative">
            <div class="row align-items-center">
                <div class="col-lg-7">
                    <div class="hero-eyebrow">
                        <i class="bi bi-pencil-square"></i>
                        Knowledge Base Management
                    </div>
                    <h1 class="hero-title">
                        <span class="hero-title-line">Edit Your</span>
                        <span class="hero-title-line hero-title-highlight">
                            Knowledge Article
                            <span class="hero-title-highlight-tail"></span>
                        </span>
                        <span class="hero-title-line">Content</span>
                    </h1>
                    <p class="hero-subtitle">
                        Update and refine your knowledge base article to provide the best support experience for your users.
                    </p>
                    <div class="hero-cta d-flex flex-wrap align-items-center gap-2">
                        <a href="/kb/<?= htmlspecialchars($article['slug']) ?>" class="btn c-btn-ghost" target="_blank">
                            <i class="bi bi-eye me-1"></i>
                            View Live Article
                        </a>
                        <a href="/operations/kb-articles.php" class="btn c-btn-ghost">
                            <i class="bi bi-arrow-left me-1"></i>
                            Back to Articles
                        </a>
                    </div>
                    <div class="hero-meta">
                        <span><i class="bi bi-clock-history"></i> Last updated: <?= date('M j, Y', strtotime($article['updated_at'])) ?></span>
                        <span><i class="bi bi-eye-fill"></i> <?= number_format($stats['total_views'] ?? 0) ?> views</span>
                    </div>
                </div>
                <!-- Snapshot card -->
                <div class="col-lg-5 mt-5 mt-lg-0 d-none d-lg-block">
                    <div class="snapshot-card">
                        <div class="snapshot-header">
                            <span class="snapshot-label">Article Stats</span>
                        </div>
                        <div class="snapshot-body">
                            <div class="snapshot-metric">
                                <span class="snapshot-metric-main"><?= number_format($stats['total_views'] ?? 0) ?></span>
                                <span class="snapshot-metric-sub">total views</span>
                            </div>
                            <ul class="snapshot-list">
                                <li><i class="bi bi-eye"></i> <?= number_format($stats['views_30d'] ?? 0) ?> views (30 days)</li>
                                <li><i class="bi bi-hand-thumbs-up"></i> <?= number_format($feedback_stats['helpful_count'] ?? 0) ?> helpful votes</li>
                                <li><i class="bi bi-<?= $article['status'] === 'published' ? 'check-circle text-success' : 'pause-circle text-warning' ?>"></i> Status: <?= ucfirst($article['status']) ?></li>
                            </ul>
                            <a href="/operations/kb-dashboard.php" class="snapshot-cta">
                                KB Dashboard
                                <i class="bi bi-arrow-right-short"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Dynamic TinyMCE Loading -->
    <?php if ($tinymce_api_key !== 'no-api-key'): ?>
        <!-- Use TinyMCE Cloud with API key -->
        <script src="https://cdn.tiny.cloud/1/<?= htmlspecialchars($tinymce_api_key) ?>/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <?php else: ?>
        <!-- Use free TinyMCE from CDN -->
        <script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
    <?php endif; ?>

    <style>
        .form-floating textarea {
            min-height: 100px;
        }
        .meta-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1rem;
        }
        .char-counter {
            font-size: 0.875rem;
            color: #6c757d;
        }
        .char-counter.warning {
            color: #ffc107;
        }
        .char-counter.danger {
            color: #dc3545;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
        }
        .stat-item {
            text-align: center;
            padding: 1rem;
        }
        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            display: block;
        }
        .stat-label {
            font-size: 0.875rem;
            opacity: 0.9;
        }
        .quick-actions .btn {
            margin-bottom: 0.5rem;
        }
        .article-preview {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 1rem;
            background: #f8f9fa;
        }
        .api-status {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 0.5rem;
        }
        .api-status.pro {
            color: #28a745;
        }
        .edit-notice {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        /* Dark Mode Styles */
        :root.dark body {
            background-color: #0f172a !important;
            color: #e2e8f0 !important;
        }

        /* Hero Section */
        :root.dark .hero {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%);
        }

        :root.dark .hero-gradient {
            background: linear-gradient(135deg, rgba(76, 29, 149, 0.9) 0%, rgba(91, 33, 182, 0.9) 100%);
        }

        /* Meta Section */
        :root.dark .meta-section {
            background: #1e293b !important;
            border: 1px solid #334155;
        }

        :root.dark .meta-section h6 {
            color: #f1f5f9 !important;
        }

        /* Article Preview */
        :root.dark .article-preview {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        /* Edit Notice */
        :root.dark .edit-notice {
            background: linear-gradient(135deg, #0e7490 0%, #0c5d6e 100%);
        }

        /* Cards */
        :root.dark .card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .card-header {
            background: #1e293b !important;
            border-bottom-color: #334155 !important;
            color: #f1f5f9 !important;
        }

        :root.dark .card-header.bg-white {
            background: #1e293b !important;
        }

        :root.dark .card-body {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .card-title {
            color: #f1f5f9 !important;
        }

        /* Form Controls */
        :root.dark .form-control,
        :root.dark .form-select {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .form-control:focus,
        :root.dark .form-select:focus {
            background: #0f172a !important;
            border-color: #667eea !important;
            color: #e2e8f0 !important;
        }

        :root.dark .form-control::placeholder {
            color: #64748b !important;
        }

        :root.dark .form-label {
            color: #cbd5e1 !important;
        }

        :root.dark .form-floating > label {
            color: #94a3b8 !important;
        }

        :root.dark .form-text {
            color: #94a3b8 !important;
        }

        :root.dark .form-check-input {
            background-color: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .form-check-input:checked {
            background-color: #667eea !important;
            border-color: #667eea !important;
        }

        :root.dark .form-check-label {
            color: #cbd5e1 !important;
        }

        /* Alerts */
        :root.dark .alert-info {
            background: #1e3a5f !important;
            border-color: #2563eb !important;
            color: #93c5fd !important;
        }

        :root.dark .alert-info .alert-link {
            color: #bfdbfe !important;
        }

        :root.dark .alert-success {
            background: #14532d !important;
            border-color: #16a34a !important;
            color: #86efac !important;
        }

        :root.dark .alert-danger {
            background: #7f1d1d !important;
            border-color: #dc2626 !important;
            color: #fca5a5 !important;
        }

        :root.dark .alert-warning {
            background: #713f12 !important;
            border-color: #f59e0b !important;
            color: #fcd34d !important;
        }

        /* Buttons */
        :root.dark .btn-outline-secondary {
            color: #cbd5e1 !important;
            border-color: #475569 !important;
        }

        :root.dark .btn-outline-secondary:hover {
            background: #475569 !important;
            color: #f1f5f9 !important;
        }

        :root.dark .btn-outline-info {
            color: #67e8f9 !important;
            border-color: #06b6d4 !important;
        }

        :root.dark .btn-outline-info:hover {
            background: #06b6d4 !important;
            color: white !important;
        }

        :root.dark .btn-outline-primary {
            color: #a78bfa !important;
            border-color: #667eea !important;
        }

        :root.dark .btn-outline-primary:hover {
            background: #667eea !important;
            color: white !important;
        }

        :root.dark .btn-outline-warning {
            color: #fcd34d !important;
            border-color: #f59e0b !important;
        }

        :root.dark .btn-outline-warning:hover {
            background: #f59e0b !important;
            color: #0f172a !important;
        }

        /* Text Colors */
        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark .text-primary {
            color: #a78bfa !important;
        }

        :root.dark h1, :root.dark h2, :root.dark h3,
        :root.dark h4, :root.dark h5, :root.dark h6 {
            color: #f1f5f9 !important;
        }

        :root.dark .h3 {
            color: #f1f5f9 !important;
        }

        /* Badges */
        :root.dark .badge.bg-secondary {
            background: #475569 !important;
            color: #f1f5f9 !important;
        }

        :root.dark .badge.bg-success {
            background: #16a34a !important;
        }

        /* Char Counter */
        :root.dark .char-counter {
            color: #94a3b8 !important;
        }

        /* API Status */
        :root.dark .api-status {
            color: #94a3b8 !important;
        }

        :root.dark .api-status.pro {
            color: #4ade80 !important;
        }

        /* Stats Card - Keep gradient but ensure text is visible */
        :root.dark .stats-card {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%);
        }

        /* TinyMCE Editor */
        :root.dark .tox-tinymce {
            border-color: #334155 !important;
        }

        /* Container Fluid */
        :root.dark .container-fluid {
            background: transparent !important;
        }
    </style>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <?php if ($tinymce_api_key === 'no-api-key'): ?>
                    <div class="alert alert-info alert-dismissible fade show">
                        <i class="bi bi-lightbulb me-2"></i>
                        <strong>Pro Tip:</strong> Set up your TinyMCE API key in 
                        <a href="/operations/kb-settings.php#editor" class="alert-link">Editor Settings</a> 
                        to unlock spell checking, premium plugins, and enhanced features.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle me-2"></i>
                        <?= htmlspecialchars($success_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Please fix the following errors:</strong>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($errors as $error_msg): ?>
                                <li><?= htmlspecialchars($error_msg) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Main Content -->
                    <div class="col-lg-8">
                        <form method="POST" id="editForm">
                            <input type="hidden" name="action" value="update_article">
                            
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <!-- Title -->
                                    <div class="form-floating mb-3">
                                        <input type="text" class="form-control" id="title" name="title" 
                                               value="<?= htmlspecialchars($article['title']) ?>" 
                                               placeholder="Article title" required maxlength="255"
                                               oninput="updateSlug(); updateMetaTitle(); countChars('title', 255)">
                                        <label for="title">Article Title *</label>
                                        <div class="char-counter mt-1" id="title-counter">0/255</div>
                                    </div>

                                    <!-- Slug -->
                                    <div class="form-floating mb-3">
                                        <input type="text" class="form-control" id="slug" name="slug" 
                                               value="<?= htmlspecialchars($article['slug']) ?>" 
                                               placeholder="article-slug" pattern="[a-z0-9\-]+" 
                                               title="Only lowercase letters, numbers, and hyphens allowed">
                                        <label for="slug">URL Slug *</label>
                                        <div class="form-text">
                                            Current URL: <strong>/kb/<?= htmlspecialchars($article['slug']) ?></strong>
                                            <br><span id="url-preview-new" style="display: none;"><strong>New URL:</strong> <span id="url-preview"></span></span>
                                        </div>
                                    </div>

                                    <!-- Excerpt -->
                                    <div class="form-floating mb-3">
                                        <textarea class="form-control" id="excerpt" name="excerpt" 
                                                  style="height: 100px" placeholder="Brief description"
                                                  maxlength="500" oninput="countChars('excerpt', 500)"><?= htmlspecialchars($article['excerpt']) ?></textarea>
                                        <label for="excerpt">Excerpt</label>
                                        <div class="char-counter mt-1" id="excerpt-counter">0/500</div>
                                        <div class="form-text">Brief description shown in search results and article listings.</div>
                                    </div>

                                    <!-- Content -->
                                    <div class="mb-3">
                                        <label for="content" class="form-label">
                                            Content *
                                            <?php if ($tinymce_api_key !== 'no-api-key'): ?>
                                                <span class="badge bg-success ms-2">Pro Editor</span>
                                            <?php endif; ?>
                                        </label>
                                        <textarea id="content" name="content" class="form-control"><?= htmlspecialchars($article['content']) ?></textarea>
                                    </div>

                                    <!-- Tags -->
                                    <div class="form-floating mb-3">
                                        <input type="text" class="form-control" id="tags" name="tags" 
                                               value="<?= htmlspecialchars(implode(', ', $tags)) ?>" 
                                               placeholder="tag1, tag2, tag3">
                                        <label for="tags">Tags</label>
                                        <div class="form-text">
                                            Current tags: 
                                            <?php if (!empty($tags)): ?>
                                                <?php foreach ($tags as $tag): ?>
                                                    <span class="badge bg-secondary me-1"><?= htmlspecialchars($tag) ?></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <em>No tags</em>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- SEO Section -->
                            <div class="meta-section">
                                <h6 class="fw-bold mb-3">
                                    <i class="bi bi-search me-2"></i>
                                    SEO & Meta Information
                                </h6>
                                
                                <div class="form-floating mb-3">
                                    <input type="text" class="form-control" id="meta_title" name="meta_title" 
                                           value="<?= htmlspecialchars($article['meta_title']) ?>" 
                                           placeholder="SEO title" maxlength="60"
                                           oninput="countChars('meta_title', 60)">
                                    <label for="meta_title">Meta Title</label>
                                    <div class="char-counter mt-1" id="meta_title-counter">0/60</div>
                                    <div class="form-text">Shown in search engine results. Leave blank to use article title.</div>
                                </div>

                                <div class="form-floating mb-3">
                                    <textarea class="form-control" id="meta_description" name="meta_description" 
                                              style="height: 80px" placeholder="Meta description"
                                              maxlength="160" oninput="countChars('meta_description', 160)"><?= htmlspecialchars($article['meta_description']) ?></textarea>
                                    <label for="meta_description">Meta Description</label>
                                    <div class="char-counter mt-1" id="meta_description-counter">0/160</div>
                                    <div class="form-text">Description shown in search engine results.</div>
                                </div>

                                <div class="form-floating">
                                    <input type="text" class="form-control" id="search_keywords" name="search_keywords" 
                                           value="<?= htmlspecialchars($article['search_keywords']) ?>" 
                                           placeholder="keyword1, keyword2, keyword3">
                                    <label for="search_keywords">Search Keywords</label>
                                    <div class="form-text">Additional keywords to help users find this article.</div>
                                </div>
                            </div>

                            <!-- Update Button -->
                            <div class="d-grid mt-4">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-check-circle me-1"></i>
                                    Update Article
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Sidebar -->
                    <div class="col-lg-4">
                        <!-- Article Stats -->
                        <div class="card stats-card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <h6 class="card-title text-center mb-3">
                                    <i class="bi bi-graph-up me-2"></i>
                                    Article Statistics
                                </h6>
                                <div class="row">
                                    <div class="col-4">
                                        <div class="stat-item">
                                            <span class="stat-number"><?= number_format($stats['total_views'] ?? 0) ?></span>
                                            <div class="stat-label">Total Views</div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="stat-item">
                                            <span class="stat-number"><?= number_format($stats['views_30d'] ?? 0) ?></span>
                                            <div class="stat-label">30 Days</div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="stat-item">
                                            <span class="stat-number"><?= number_format($feedback_stats['helpful_count'] ?? 0) ?></span>
                                            <div class="stat-label">Helpful</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-white border-0">
                                <h6 class="mb-0">
                                    <i class="bi bi-lightning me-2"></i>
                                    Quick Actions
                                </h6>
                            </div>
                            <div class="card-body quick-actions">
                                <!-- Publish/Unpublish -->
                                <form method="POST" class="d-grid mb-2">
                                    <input type="hidden" name="action" value="quick_publish">
                                    <button type="submit" class="btn <?= $article['status'] === 'published' ? 'btn-warning' : 'btn-success' ?>">
                                        <i class="bi bi-<?= $article['status'] === 'published' ? 'pause' : 'play' ?>-circle me-1"></i>
                                        <?= $article['status'] === 'published' ? 'Unpublish' : 'Publish Now' ?>
                                    </button>
                                </form>

                                <!-- Featured Toggle -->
                                <form method="POST" class="d-grid mb-2">
                                    <input type="hidden" name="action" value="toggle_featured">
                                    <button type="submit" class="btn <?= $article['featured'] ? 'btn-outline-warning' : 'btn-outline-primary' ?>">
                                        <i class="bi bi-star<?= $article['featured'] ? '-fill' : '' ?> me-1"></i>
                                        <?= $article['featured'] ? 'Remove Featured' : 'Mark Featured' ?>
                                    </button>
                                </form>

                                <!-- View Article -->
                                <a href="/kb/<?= htmlspecialchars($article['slug']) ?>" target="_blank" class="btn btn-outline-info d-grid mb-2">
                                    <i class="bi bi-eye me-1"></i>
                                    View Live Article
                                </a>

                                <!-- Duplicate Article -->
                                <a href="/operations/kb-articles-create.php?duplicate=<?= $article['id'] ?>" class="btn btn-outline-secondary d-grid">
                                    <i class="bi bi-files me-1"></i>
                                    Duplicate Article
                                </a>
                            </div>
                        </div>

                        <!-- Publishing Info -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-white border-0">
                                <h6 class="mb-0">
                                    <i class="bi bi-send me-2"></i>
                                    Publishing Options
                                </h6>
                            </div>
                            <div class="card-body">
                                <!-- Status -->
                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select" form="editForm">
                                        <option value="draft" <?= $article['status'] === 'draft' ? 'selected' : '' ?>>📝 Draft</option>
                                        <option value="published" <?= $article['status'] === 'published' ? 'selected' : '' ?>>🌐 Published</option>
                                        <option value="archived" <?= $article['status'] === 'archived' ? 'selected' : '' ?>>📦 Archived</option>
                                    </select>
                                </div>

                                <!-- Visibility -->
                                <div class="mb-3">
                                    <label class="form-label">Visibility</label>
                                    <select name="visibility" class="form-select" form="editForm">
                                        <option value="public" <?= $article['visibility'] === 'public' ? 'selected' : '' ?>>🌍 Public</option>
                                        <option value="authenticated" <?= $article['visibility'] === 'authenticated' ? 'selected' : '' ?>>👤 Authenticated Users Only</option>
                                        <option value="staff_only" <?= $article['visibility'] === 'staff_only' ? 'selected' : '' ?>>🔒 Staff Only</option>
                                    </select>
                                </div>

                                <!-- Featured -->
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="featured" name="featured" 
                                           <?= $article['featured'] ? 'checked' : '' ?> form="editForm">
                                    <label class="form-check-label" for="featured">
                                        <i class="bi bi-star me-1"></i>
                                        Featured Article
                                    </label>
                                </div>

                                <!-- Publishing info -->
                                <div class="text-muted small">
                                    <div class="mb-1">
                                        <strong>Author:</strong> <?= htmlspecialchars($article['author_name']) ?>
                                    </div>
                                    <div class="mb-1">
                                        <strong>Created:</strong> <?= date('M j, Y', strtotime($article['created_at'])) ?>
                                    </div>
                                    <?php if ($article['published_at']): ?>
                                        <div class="mb-1">
                                            <strong>Published:</strong> <?= date('M j, Y', strtotime($article['published_at'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Category -->
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white border-0">
                                <h6 class="mb-0">
                                    <i class="bi bi-tags me-2"></i>
                                    Category
                                </h6>
                            </div>
                            <div class="card-body">
                                <select name="category_id" class="form-select" required form="editForm" id="category-select" onchange="updateCategoryPreview()">
                                    <option value="">Choose category...</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['id'] ?>" 
                                                data-color="<?= htmlspecialchars($category['color']) ?>"
                                                <?= $article['category_id'] == $category['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($category['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text mt-2">
                                    Current: <strong><?= htmlspecialchars($article['category_name']) ?></strong>
                                </div>
                                <div id="category-preview"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize TinyMCE with dynamic configuration
        tinymce.init({
            selector: '#content',
            height: 400,
            menubar: false,
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'media', 'table', 'help', 'wordcount'
                <?php if ($tinymce_api_key !== 'no-api-key'): ?>
                    , 'spellchecker', 'paste', 'importcss', 'autosave'
                <?php endif; ?>
            ],
            toolbar: 'undo redo | blocks | bold italic forecolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat<?php if ($tinymce_api_key !== 'no-api-key'): ?> | spellchecker<?php endif; ?> | help',
            content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 14px }',
            branding: false,
            <?php if ($tinymce_api_key !== 'no-api-key'): ?>
            spellchecker_language: 'en',
            spellchecker_active: true,
            paste_auto_cleanup_on_paste: true,
            paste_remove_styles: true,
            paste_remove_styles_if_webkit: true,
            paste_strip_class_attributes: 'all',
            <?php endif; ?>
            setup: function (editor) {
                editor.on('change', function () {
                    tinymce.triggerSave();
                });
            }
        });

        // Auto-generate slug from title
        function updateSlug() {
            const title = document.getElementById('title').value;
            const slugField = document.getElementById('slug');
            const currentSlug = '<?= $article['slug'] ?>';
            
            // Only auto-generate if slug matches current or is empty
            if (!slugField.value || slugField.value === currentSlug) {
                const slug = title
                    .toLowerCase()
                    .trim()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-')
                    .replace(/^-|-$/g, '');
                
                slugField.value = slug;
                updateUrlPreview();
            }
        }

        // Update URL preview when slug changes
        function updateUrlPreview() {
            const slug = document.getElementById('slug').value;
            const currentSlug = '<?= $article['slug'] ?>';
            const previewElement = document.getElementById('url-preview-new');
            const urlElement = document.getElementById('url-preview');
            
            if (slug && slug !== currentSlug) {
                urlElement.textContent = '/kb/' + slug;
                previewElement.style.display = 'block';
            } else {
                previewElement.style.display = 'none';
            }
        }

        // Auto-fill meta title from title
        function updateMetaTitle() {
            const title = document.getElementById('title').value;
            const metaTitleField = document.getElementById('meta_title');
            
            // Only auto-fill if meta title is empty
            if (!metaTitleField.value && title) {
                metaTitleField.value = title.substring(0, 60);
                countChars('meta_title', 60);
            }
        }

        // Update category preview
        function updateCategoryPreview() {
            const select = document.getElementById('category-select');
            const preview = document.getElementById('category-preview');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption.value) {
                const color = selectedOption.getAttribute('data-color');
                const name = selectedOption.text;
                preview.innerHTML = `<div style="display: inline-block; padding: 0.25rem 0.75rem; border-radius: 12px; color: white; font-size: 0.875rem; margin-top: 0.5rem; background-color: ${color}">${name}</div>`;
            } else {
                preview.innerHTML = '';
            }
        }

        // Character counter
        function countChars(fieldId, maxLength) {
            const field = document.getElementById(fieldId);
            const counter = document.getElementById(fieldId + '-counter');
            const length = field.value.length;
            
            counter.textContent = length + '/' + maxLength;
            
            // Update counter color based on length
            counter.classList.remove('warning', 'danger');
            if (length > maxLength * 0.9) {
                counter.classList.add('danger');
            } else if (length > maxLength * 0.8) {
                counter.classList.add('warning');
            }
        }

        // Initialize character counters and URL preview
        document.addEventListener('DOMContentLoaded', function() {
            countChars('title', 255);
            countChars('excerpt', 500);
            countChars('meta_title', 60);
            countChars('meta_description', 160);
            updateCategoryPreview();
            
            // Update URL preview when slug changes
            document.getElementById('slug').addEventListener('input', updateUrlPreview);
        });

        // Form validation before submit
        document.getElementById('editForm').addEventListener('submit', function(e) {
            const title = document.getElementById('title').value.trim();
            const content = tinymce.get('content').getContent();
            const category = document.getElementById('category-select').value;
            
            if (!title || !content || !category) {
                e.preventDefault();
                alert('Please fill in all required fields (Title, Content, and Category).');
                return false;
            }
        });

        // Handle success parameter
        <?php if (isset($_GET['success'])): ?>
        // Show success message
        setTimeout(() => {
            const successAlert = document.createElement('div');
            successAlert.className = 'alert alert-success alert-dismissible fade show';
            successAlert.innerHTML = `
                <i class="bi bi-check-circle me-2"></i>
                Article saved successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.querySelector('.container-fluid .row .col-12').insertBefore(
                successAlert, 
                document.querySelector('.row')
            );
        }, 100);
        <?php endif; ?>
    </script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>