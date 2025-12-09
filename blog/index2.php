<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

// Get blog settings
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM blog_settings");
$stmt->execute();
$blog_settings = [];
while ($row = $stmt->fetch()) {
    $blog_settings[$row['setting_key']] = $row['setting_value'];
}

$blog_title = $blog_settings['blog_title'] ?? 'Blog';
$blog_description = $blog_settings['blog_description'] ?? 'Welcome to our blog';

// Get featured posts
$stmt = $pdo->prepare("
    SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.featured_image, p.published_at, p.view_count,
           u.username as author_name, c.name as category_name, c.slug as category_slug
    FROM blog_posts p
    LEFT JOIN users u ON p.author_id = u.id
    LEFT JOIN blog_categories c ON p.category_id = c.id
    WHERE p.status = 'published' AND p.is_featured = 1 AND p.published_at <= NOW()
    ORDER BY p.published_at DESC
    LIMIT 3
");
$stmt->execute();
$featured_posts = $stmt->fetchAll();

// Get recent posts (excluding featured)
$featured_ids = array_column($featured_posts, 'id');
$featured_condition = empty($featured_ids) ? '' : 'AND p.id NOT IN (' . implode(',', $featured_ids) . ')';

$stmt = $pdo->prepare("
    SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.featured_image, p.published_at, p.view_count,
           u.username as author_name, c.name as category_name, c.slug as category_slug,
           (SELECT GROUP_CONCAT(pt.tag_name SEPARATOR ', ') FROM blog_post_tags pt WHERE pt.post_id = p.id LIMIT 3) as tags
    FROM blog_posts p
    LEFT JOIN users u ON p.author_id = u.id
    LEFT JOIN blog_categories c ON p.category_id = c.id
    WHERE p.status = 'published' AND p.published_at <= NOW() $featured_condition
    ORDER BY p.published_at DESC
    LIMIT 6
");
$stmt->execute();
$recent_posts = $stmt->fetchAll();

// Get categories with post counts
$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.slug, c.description, COUNT(p.id) as post_count
    FROM blog_categories c
    LEFT JOIN blog_posts p ON c.id = p.category_id AND p.status = 'published' AND p.published_at <= NOW()
    WHERE c.is_active = 1
    GROUP BY c.id, c.name, c.slug, c.description
    HAVING post_count > 0
    ORDER BY post_count DESC
    LIMIT 8
");
$stmt->execute();
$categories = $stmt->fetchAll();

// Get blog statistics
$stmt = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM blog_posts WHERE status = 'published' AND published_at <= NOW()) as total_posts,
        (SELECT COUNT(DISTINCT category_id) FROM blog_posts WHERE status = 'published' AND published_at <= NOW() AND category_id IS NOT NULL) as active_categories,
        (SELECT SUM(view_count) FROM blog_posts WHERE status = 'published') as total_views
");
$stmt->execute();
$stats = $stmt->fetch();

$page_title = $blog_title;
$page_description = $blog_description;
?>

<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>">
    <meta property="og:url" content="https://<?= $_SERVER['HTTP_HOST'] ?>/blog/">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">
    
    <style>
        body {
            background-color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .hero-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 5rem 0;
            text-align: center;
        }

        .hero-section h1 {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }

        .hero-section p {
            font-size: 1.25rem;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto 2rem;
        }

        .stats-section {
            background: white;
            box-shadow: 0 -2px 20px rgba(0,0,0,0.1);
            margin-top: -3rem;
            position: relative;
            z-index: 1;
            border-radius: 15px;
            padding: 2rem;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #64748b;
            font-weight: 500;
        }

        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        .section-title {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 3rem;
            text-align: center;
        }

        .featured-posts {
            margin: 4rem 0;
        }

        .featured-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
        }

        .featured-post {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }

        .featured-post:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
            text-decoration: none;
            color: inherit;
        }

        .featured-image {
            height: 250px;
            background: #f1f5f9;
            overflow: hidden;
            position: relative;
        }

        .featured-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .featured-badge {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background: #667eea;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .featured-content {
            padding: 2rem;
        }

        .featured-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.9rem;
            color: #64748b;
            margin-bottom: 1rem;
        }

        .featured-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1rem;
            line-height: 1.3;
        }

        .featured-excerpt {
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }

        .read-more {
            color: #667eea;
            font-weight: 600;
            text-decoration: none;
        }

        .recent-posts {
            margin: 4rem 0;
        }

        .posts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }

        .post-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
            transition: all 0.2s;
            text-decoration: none;
            color: inherit;
        }

        .post-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            text-decoration: none;
            color: inherit;
        }

        .post-image {
            height: 200px;
            background: #f1f5f9;
            overflow: hidden;
        }

        .post-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .post-content {
            padding: 1.5rem;
        }

        .post-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 1rem;
        }

        .post-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.75rem;
            line-height: 1.3;
        }

        .post-excerpt {
            color: #64748b;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .categories-section {
            background: white;
            border-radius: 15px;
            padding: 3rem;
            margin: 4rem 0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
        }

        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .category-card {
            padding: 1.5rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
        }

        .category-card:hover {
            border-color: #667eea;
            background: #f8fafc;
            text-decoration: none;
            color: inherit;
        }

        .category-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .category-description {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .category-count {
            color: #667eea;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .cta-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4rem 0;
            text-align: center;
            border-radius: 15px;
            margin: 4rem 0;
        }

        .cta-section h2 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .cta-section p {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 2rem;
        }

        .btn-primary {
            background: white;
            color: #667eea;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-primary:hover {
            background: #f1f5f9;
            color: #667eea;
            transform: translateY(-1px);
        }

        .btn-outline-primary {
            border: 2px solid white;
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-outline-primary:hover {
            background: white;
            color: #667eea;
        }

        @media (max-width: 768px) {
            .hero-section h1 {
                font-size: 2.5rem;
            }
            
            .featured-grid, .posts-grid, .categories-grid {
                grid-template-columns: 1fr;
            }
            
            .cta-section h2 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>

<?php
$page_title = htmlspecialchars($blog_title) . " | CaminhoIT";
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php';
?>

<!-- HERO -->
<header class="hero">
    <div class="hero-gradient"></div>

    <div class="container position-relative">
        <div class="row align-items-center">
            <div class="col-lg-7">
                <div class="hero-eyebrow">
                    <i class="bi bi-newspaper"></i>
                    Blog Dashboard
                </div>

                <h1 class="hero-title">
                    <span class="hero-title-line">
                        Manage Your
                    </span>
                    <span class="hero-title-line hero-title-highlight">
                        Blog Content
                        <span class="hero-title-highlight-tail"></span>
                    </span>
                    <span class="hero-title-line">
                        & Insights
                    </span>
                </h1>

                <p class="hero-subtitle">
                    Create, edit, and manage your blog posts, categories, and media all in one place.
                </p>

                <div class="hero-cta d-flex flex-wrap align-items-center gap-2">
                    <a href="/blog/admin/posts/create.php" class="btn c-btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>
                        Create New Post
                    </a>
                    <a href="/blog/posts.php" class="btn c-btn-ghost">
                        View Blog
                    </a>
                </div>

                <div class="hero-meta">
                    <span><i class="bi bi-file-text"></i> <?= number_format($stats['total_posts']) ?> Posts</span>
                    <span><i class="bi bi-eye"></i> <?= number_format($stats['total_views']) ?> Views</span>
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
                            <span class="snapshot-metric-main"><?= number_format($stats['active_categories']) ?></span>
                            <span class="snapshot-metric-sub">active categories</span>
                        </div>

                        <ul class="snapshot-list">
                            <li>
                                <i class="bi bi-file-earmark-text"></i>
                                <?= number_format($stats['total_posts']) ?> total posts published
                            </li>
                            <li>
                                <i class="bi bi-graph-up"></i>
                                <?= number_format($stats['total_views']) ?> total views
                            </li>
                            <li>
                                <i class="bi bi-calendar-check"></i>
                                Regular content updates
                            </li>
                        </ul>

                        <a href="/blog/admin/settings.php" class="snapshot-cta">
                            Manage blog settings
                            <i class="bi bi-arrow-right-short"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<div class="main-container">
    <!-- Stats Section -->
    <div class="stats-section">
        <div class="row">
            <div class="col-md-4">
                <div class="stat-item">
                    <div class="stat-number"><?= number_format($stats['total_posts']) ?></div>
                    <div class="stat-label">Total Posts</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-item">
                    <div class="stat-number"><?= number_format($stats['active_categories']) ?></div>
                    <div class="stat-label">Categories</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-item">
                    <div class="stat-number"><?= number_format($stats['total_views']) ?></div>
                    <div class="stat-label">Total Views</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Featured Posts -->
    <?php if (!empty($featured_posts)): ?>
        <section id="featured" class="featured-posts">
            <h2 class="section-title">Featured Posts</h2>
            <div class="featured-grid">
                <?php foreach ($featured_posts as $post): ?>
                    <a href="/blog/post.php?slug=<?= htmlspecialchars($post['slug']) ?>" class="featured-post">
                        <div class="featured-image">
                            <?php if ($post['featured_image']): ?>
                                <img src="<?= htmlspecialchars($post['featured_image']) ?>" 
                                     alt="<?= htmlspecialchars($post['title']) ?>">
                            <?php else: ?>
                                <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                                    <i class="bi bi-file-text" style="font-size: 3rem;"></i>
                                </div>
                            <?php endif; ?>
                            <div class="featured-badge">
                                <i class="bi bi-star-fill"></i> Featured
                            </div>
                        </div>
                        <div class="featured-content">
                            <div class="featured-meta">
                                <span><i class="bi bi-person"></i> <?= htmlspecialchars($post['author_name']) ?></span>
                                <span><i class="bi bi-calendar"></i> <?= date('M j, Y', strtotime($post['published_at'])) ?></span>
                                <?php if ($post['category_name']): ?>
                                    <span><i class="bi bi-tag"></i> <?= htmlspecialchars($post['category_name']) ?></span>
                                <?php endif; ?>
                            </div>
                            <h3 class="featured-title"><?= htmlspecialchars($post['title']) ?></h3>
                            <p class="featured-excerpt">
                                <?php if ($post['excerpt']): ?>
                                    <?= htmlspecialchars($post['excerpt']) ?>
                                <?php else: ?>
                                    <?= htmlspecialchars(substr(strip_tags($post['content']), 0, 150)) ?>...
                                <?php endif; ?>
                            </p>
                            <span class="read-more">Read More <i class="bi bi-arrow-right"></i></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Recent Posts -->
    <?php if (!empty($recent_posts)): ?>
        <section class="recent-posts">
            <h2 class="section-title">Recent Posts</h2>
            <div class="posts-grid">
                <?php foreach ($recent_posts as $post): ?>
                    <a href="/blog/post.php?slug=<?= htmlspecialchars($post['slug']) ?>" class="post-card">
                        <div class="post-image">
                            <?php if ($post['featured_image']): ?>
                                <img src="<?= htmlspecialchars($post['featured_image']) ?>" 
                                     alt="<?= htmlspecialchars($post['title']) ?>">
                            <?php else: ?>
                                <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                                    <i class="bi bi-file-text" style="font-size: 2rem;"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="post-content">
                            <div class="post-meta">
                                <span><i class="bi bi-calendar"></i> <?= date('M j', strtotime($post['published_at'])) ?></span>
                                <?php if ($post['category_name']): ?>
                                    <span><i class="bi bi-tag"></i> <?= htmlspecialchars($post['category_name']) ?></span>
                                <?php endif; ?>
                                <span><i class="bi bi-eye"></i> <?= number_format($post['view_count']) ?></span>
                            </div>
                            <h3 class="post-title"><?= htmlspecialchars($post['title']) ?></h3>
                            <p class="post-excerpt">
                                <?php if ($post['excerpt']): ?>
                                    <?= htmlspecialchars(substr($post['excerpt'], 0, 100)) ?>...
                                <?php else: ?>
                                    <?= htmlspecialchars(substr(strip_tags($post['content']), 0, 100)) ?>...
                                <?php endif; ?>
                            </p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            
            <div class="text-center mt-4">
                <a href="/blog/posts.php" class="btn btn-primary">
                    View All Posts <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </section>
    <?php endif; ?>

    <!-- Categories -->
    <?php if (!empty($categories)): ?>
        <section class="categories-section">
            <h2 class="section-title">Explore Categories</h2>
            <div class="categories-grid">
                <?php foreach ($categories as $category): ?>
                    <a href="/blog/posts.php?category=<?= urlencode($category['slug']) ?>" class="category-card">
                        <div class="category-name"><?= htmlspecialchars($category['name']) ?></div>
                        <?php if ($category['description']): ?>
                            <div class="category-description"><?= htmlspecialchars($category['description']) ?></div>
                        <?php endif; ?>
                        <div class="category-count">
                            <?= $category['post_count'] ?> post<?= $category['post_count'] != 1 ? 's' : '' ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Call to Action -->
    <section class="cta-section">
        <h2>Stay Updated</h2>
        <p>Never miss our latest articles and insights</p>
        <div class="d-flex gap-3 justify-content-center">
            <a href="/blog/posts.php" class="btn btn-primary">
                Browse All Posts
            </a>
            <?php if (($blog_settings['enable_rss'] ?? '1') === '1'): ?>
                <a href="/blog/rss.xml" class="btn btn-outline-primary">
                    <i class="bi bi-rss"></i> RSS Feed
                </a>
            <?php endif; ?>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Smooth scrolling for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});

// Intersection Observer for animations
if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    });

    // Observe elements for animation
    document.querySelectorAll('.featured-post, .post-card, .category-card').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(el);
    });
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>

</body>
</html>