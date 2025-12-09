<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$token = $_GET['token'] ?? '';
if (empty($token) || !isset($_SESSION['blog_preview'][$token])) {
    header('HTTP/1.0 404 Not Found');
    echo '<h1>Preview Not Found</h1><p>This preview has expired or does not exist.</p>';
    exit;
}

$preview_data = $_SESSION['blog_preview'][$token];

// Get category name if provided
$category_name = '';
if ($preview_data['category_id']) {
    $stmt = $pdo->prepare("SELECT name FROM blog_categories WHERE id = ?");
    $stmt->execute([$preview_data['category_id']]);
    $category_name = $stmt->fetchColumn() ?: '';
}

// Get user info
$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$preview_data['user_id']]);
$author_name = $stmt->fetchColumn() ?: 'Unknown Author';

$page_title = $preview_data['title'] . ' (Preview)';
?>

<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">
    
    <style>
        body {
            background-color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.7;
        }

        .preview-banner {
            background: #f59e0b;
            color: white;
            padding: 0.75rem 0;
            text-align: center;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .post-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4rem 0 2rem;
        }

        .post-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        .post-title {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 1rem;
        }

        .post-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            align-items: center;
            font-size: 0.95rem;
            opacity: 0.9;
            margin-bottom: 2rem;
        }

        .post-meta span {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .main-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            margin: -2rem auto 3rem;
            position: relative;
            z-index: 1;
        }

        .featured-image {
            width: 100%;
            height: 400px;
            object-fit: cover;
            border-radius: 12px 12px 0 0;
        }

        .post-content {
            padding: 3rem;
        }

        .post-content img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            margin: 1.5rem 0;
        }

        .post-content h1, .post-content h2, .post-content h3, 
        .post-content h4, .post-content h5, .post-content h6 {
            margin-top: 2rem;
            margin-bottom: 1rem;
            color: #1e293b;
            font-weight: 600;
        }

        .post-content p {
            margin-bottom: 1.5rem;
            color: #374151;
            font-size: 1.1rem;
        }

        .post-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin: 2rem 0;
            padding-top: 2rem;
            border-top: 1px solid #e2e8f0;
        }

        .tag {
            background: #e2e8f0;
            color: #475569;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
        }

        .preview-actions {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            display: flex;
            gap: 1rem;
        }

        .preview-btn {
            background: white;
            border: 2px solid #667eea;
            color: #667eea;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: all 0.2s;
        }

        .preview-btn:hover {
            background: #667eea;
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
        }

        .preview-btn.close {
            background: #ef4444;
            border-color: #ef4444;
            color: white;
        }

        .preview-btn.close:hover {
            background: #dc2626;
            border-color: #dc2626;
        }

        @media (max-width: 768px) {
            .post-title {
                font-size: 2rem;
            }
            
            .post-content {
                padding: 2rem 1.5rem;
            }
            
            .preview-actions {
                position: static;
                justify-content: center;
                margin: 2rem 0;
            }
        }
    </style>
</head>
<body>

<!-- Preview Banner -->
<div class="preview-banner">
    <i class="bi bi-eye me-2"></i>
    This is a preview - changes have not been saved
</div>

<!-- Post Header -->
<div class="post-header">
    <div class="post-container">
        <h1 class="post-title"><?= htmlspecialchars($preview_data['title'] ?: 'Untitled Post') ?></h1>
        
        <div class="post-meta">
            <span><i class="bi bi-person"></i> <?= htmlspecialchars($author_name) ?></span>
            <span><i class="bi bi-calendar"></i> <?= date('F j, Y') ?> (Preview)</span>
            <?php if ($category_name): ?>
                <span><i class="bi bi-tag"></i> <?= htmlspecialchars($category_name) ?></span>
            <?php endif; ?>
            <span><i class="bi bi-clock"></i> <?= ceil(str_word_count(strip_tags($preview_data['content'])) / 200) ?> min read</span>
        </div>
    </div>
</div>

<div class="post-container">
    <!-- Main Content -->
    <article class="main-content">
        <?php if ($preview_data['featured_image']): ?>
            <img src="<?= htmlspecialchars($preview_data['featured_image']) ?>" 
                 alt="<?= htmlspecialchars($preview_data['title']) ?>" 
                 class="featured-image">
        <?php endif; ?>
        
        <div class="post-content">
            <?php if ($preview_data['excerpt']): ?>
                <div class="alert alert-info">
                    <strong>Excerpt:</strong> <?= htmlspecialchars($preview_data['excerpt']) ?>
                </div>
            <?php endif; ?>
            
            <?= $preview_data['content'] ?: '<p class="text-muted"><em>No content added yet.</em></p>' ?>
            
            <!-- Tags -->
            <?php if ($preview_data['tags']): ?>
                <div class="post-tags">
                    <?php foreach (explode(',', $preview_data['tags']) as $tag): ?>
                        <span class="tag">
                            #<?= htmlspecialchars(trim($tag)) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </article>
</div>

<!-- Preview Actions -->
<div class="preview-actions">
    <a href="javascript:window.close()" class="preview-btn close">
        <i class="bi bi-x-circle me-2"></i>Close Preview
    </a>
    <a href="javascript:history.back()" class="preview-btn">
        <i class="bi bi-arrow-left me-2"></i>Back to Editor
    </a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-close preview if opened in popup
if (window.opener) {
    // Add close button functionality
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            window.close();
        }
    });
}

// Cleanup preview data after 5 minutes of inactivity
setTimeout(function() {
    fetch('/blog/admin/api/cleanup-preview.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ token: '<?= $token ?>' })
    });
}, 300000); // 5 minutes
</script>

</body>
</html>