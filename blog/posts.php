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

// Default settings
$blog_title = $blog_settings['blog_title'] ?? 'Blog';
$blog_description = $blog_settings['blog_description'] ?? 'Welcome to our blog';
$posts_per_page = (int)($blog_settings['posts_per_page'] ?? 10);

// Get URL parameters
$category_slug = $_GET['category'] ?? '';
$tag = $_GET['tag'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $posts_per_page;

// Build WHERE clause for filtering
$where_conditions = ["p.status = 'published'", "p.published_at <= NOW()"];
$params = [];

if ($category_slug) {
    $where_conditions[] = "c.slug = ?";
    $params[] = $category_slug;
}

if ($tag) {
    $where_conditions[] = "EXISTS (SELECT 1 FROM blog_post_tags pt WHERE pt.post_id = p.id AND pt.tag_name = ?)";
    $params[] = $tag;
}

if ($search) {
    $where_conditions[] = "(p.title LIKE ? OR p.content LIKE ? OR p.excerpt LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count for pagination
$count_sql = "
    SELECT COUNT(DISTINCT p.id) 
    FROM blog_posts p
    LEFT JOIN blog_categories c ON p.category_id = c.id
    WHERE $where_clause
";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_posts = $stmt->fetchColumn();
$total_pages = ceil($total_posts / $posts_per_page);

// Get posts
$posts_sql = "
    SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.featured_image, p.published_at, 
           p.view_count, p.is_featured, p.meta_title, p.meta_description,
           u.username as author_name, u.email as author_email,
           c.name as category_name, c.slug as category_slug,
           (SELECT GROUP_CONCAT(pt.tag_name SEPARATOR ', ') FROM blog_post_tags pt WHERE pt.post_id = p.id) as tags,
           (SELECT COUNT(*) FROM blog_post_attachments pa WHERE pa.post_id = p.id AND pa.attachment_type = 'image') as image_count
    FROM blog_posts p
    LEFT JOIN users u ON p.author_id = u.id
    LEFT JOIN blog_categories c ON p.category_id = c.id
    WHERE $where_clause
    ORDER BY p.is_featured DESC, p.published_at DESC
    LIMIT $posts_per_page OFFSET $offset
";

$stmt = $pdo->prepare($posts_sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

// Get featured posts for sidebar
$featured_sql = "
    SELECT p.id, p.title, p.slug, p.published_at, p.view_count
    FROM blog_posts p
    WHERE p.status = 'published' AND p.is_featured = 1 AND p.published_at <= NOW()
    ORDER BY p.published_at DESC
    LIMIT 5
";
$stmt = $pdo->prepare($featured_sql);
$stmt->execute();
$featured_posts = $stmt->fetchAll();

// Get categories for sidebar
$categories_sql = "
    SELECT c.id, c.name, c.slug, COUNT(p.id) as post_count
    FROM blog_categories c
    LEFT JOIN blog_posts p ON c.id = p.category_id AND p.status = 'published' AND p.published_at <= NOW()
    WHERE c.is_active = 1
    GROUP BY c.id, c.name, c.slug
    HAVING post_count > 0
    ORDER BY post_count DESC, c.name ASC
    LIMIT 10
";
$stmt = $pdo->prepare($categories_sql);
$stmt->execute();
$categories = $stmt->fetchAll();

// Get popular tags
$tags_sql = "
    SELECT pt.tag_name, COUNT(*) as tag_count
    FROM blog_post_tags pt
    INNER JOIN blog_posts p ON pt.post_id = p.id
    WHERE p.status = 'published' AND p.published_at <= NOW()
    GROUP BY pt.tag_name
    ORDER BY tag_count DESC, pt.tag_name ASC
    LIMIT 20
";
$stmt = $pdo->prepare($tags_sql);
$stmt->execute();
$popular_tags = $stmt->fetchAll();

// Get recent posts for sidebar
$recent_sql = "
    SELECT p.id, p.title, p.slug, p.published_at, p.featured_image
    FROM blog_posts p
    WHERE p.status = 'published' AND p.published_at <= NOW()
    ORDER BY p.published_at DESC
    LIMIT 5
";
$stmt = $pdo->prepare($recent_sql);
$stmt->execute();
$recent_posts = $stmt->fetchAll();

// Page title and description
$page_title = $blog_title;
$page_description = $blog_description;

if ($category_slug) {
    $stmt = $pdo->prepare("SELECT name, description FROM blog_categories WHERE slug = ?");
    $stmt->execute([$category_slug]);
    $current_category = $stmt->fetch();
    if ($current_category) {
        $page_title = $current_category['name'] . " | " . $blog_title;
        $page_description = $current_category['description'] ?: "Posts in " . $current_category['name'];
    }
}

if ($tag) {
    $page_title = "Tag: " . htmlspecialchars($tag) . " | " . $blog_title;
    $page_description = "Posts tagged with " . htmlspecialchars($tag);
}

if ($search) {
    $page_title = "Search: " . htmlspecialchars($search) . " | " . $blog_title;
    $page_description = "Search results for " . htmlspecialchars($search);
}

// Increment view count for posts (simple implementation)
foreach ($posts as $post) {
    if (!isset($_SESSION['viewed_posts'])) {
        $_SESSION['viewed_posts'] = [];
    }
    
    if (!in_array($post['id'], $_SESSION['viewed_posts'])) {
        $stmt = $pdo->prepare("UPDATE blog_posts SET view_count = view_count + 1 WHERE id = ?");
        $stmt->execute([$post['id']]);
        $_SESSION['viewed_posts'][] = $post['id'];
        
        // Limit session array to prevent memory issues
        if (count($_SESSION['viewed_posts']) > 100) {
            $_SESSION['viewed_posts'] = array_slice($_SESSION['viewed_posts'], -50);
        }
    }
}
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
    <meta property="og:url" content="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
    
    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:title" content="<?= htmlspecialchars($page_title) ?>">
    <meta property="twitter:description" content="<?= htmlspecialchars($page_description) ?>">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">
    
    <style>
        body {
            background-color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
        }

        .blog-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4rem 0;
            text-align: center;
        }

        .blog-header h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .blog-header p {
            font-size: 1.25rem;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
        }

        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 3rem 1rem;
        }

        .blog-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 3rem;
        }

        .post-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            overflow: hidden;
            margin-bottom: 2rem;
            transition: all 0.3s ease;
        }

        .post-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .post-featured {
            border-left: 4px solid #667eea;
        }

        .post-image {
            height: 250px;
            background: #f1f5f9;
            overflow: hidden;
            position: relative;
        }

        .post-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .post-image .featured-badge {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background: #667eea;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .post-content {
            padding: 2rem;
        }

        .post-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1rem;
            text-decoration: none;
            display: block;
        }

        .post-title:hover {
            color: #667eea;
            text-decoration: none;
        }

        .post-excerpt {
            color: #64748b;
            margin-bottom: 1.5rem;
            line-height: 1.7;
        }

        .post-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 1rem;
        }

        .post-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .post-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .tag {
            background: #e2e8f0;
            color: #475569;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            text-decoration: none;
            transition: all 0.2s;
        }

        .tag:hover {
            background: #667eea;
            color: white;
            text-decoration: none;
        }

        .sidebar {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            padding: 2rem;
            height: fit-content;
            position: sticky;
            top: 2rem;
        }

        .sidebar-section {
            margin-bottom: 2rem;
        }

        .sidebar-section:last-child {
            margin-bottom: 0;
        }

        .sidebar-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
        }

        .sidebar-post {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .sidebar-post:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .sidebar-post-image {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            overflow: hidden;
            background: #f1f5f9;
            flex-shrink: 0;
        }

        .sidebar-post-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .sidebar-post-content h6 {
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .sidebar-post-content h6 a {
            color: #1e293b;
            text-decoration: none;
        }

        .sidebar-post-content h6 a:hover {
            color: #667eea;
        }

        .sidebar-post-meta {
            font-size: 0.75rem;
            color: #64748b;
        }

        .category-link, .category-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            color: #475569;
            text-decoration: none;
            border-bottom: 1px solid #f1f5f9;
        }

        .category-link:hover {
            color: #667eea;
            text-decoration: none;
        }

        .category-count {
            background: #e2e8f0;
            color: #475569;
            padding: 0.125rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
        }

        .search-box {
            position: relative;
            margin-bottom: 2rem;
        }

        .search-box input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
        }

        .search-box input:focus {
            outline: none;
            border-color: #667eea;
        }

        .search-box button {
            position: absolute;
            right: 0.5rem;
            top: 50%;
            transform: translateY(-50%);
            background: #667eea;
            color: white;
            border: none;
            padding: 0.5rem;
            border-radius: 6px;
        }

        .pagination-wrapper {
            margin-top: 3rem;
            text-align: center;
        }

        .pagination {
            display: inline-flex;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            overflow: hidden;
        }

        .page-link {
            color: #475569;
            border: none;
            padding: 0.75rem 1rem;
        }

        .page-link:hover {
            background: #f1f5f9;
            color: #667eea;
        }

        .page-item.active .page-link {
            background: #667eea;
            color: white;
        }

        .breadcrumb {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .no-posts {
            text-align: center;
            padding: 4rem 2rem;
            color: #64748b;
        }

        .no-posts i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .blog-layout {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
            
            .blog-header h1 {
                font-size: 2rem;
            }
            
            .post-image {
                height: 200px;
            }
            
            .main-container {
                padding: 2rem 1rem;
            }
        }
    </style>
</head>
<body>

<?php
$page_title = htmlspecialchars($blog_title) . " | CaminhoIT";
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php';
?>

<!-- HERO -->
<header class="hero">
    <div class="hero-gradient"></div>

    <div class="container position-relative">
        <div class="row align-items-center">
            <div class="col-lg-7">
                <div class="hero-eyebrow">
                    <i class="bi bi-newspaper"></i>
                    Blog & Insights
                </div>

                <h1 class="hero-title">
                    <span class="hero-title-line">
                        Stay Informed on
                    </span>
                    <span class="hero-title-line hero-title-highlight">
                        IT, Cloud & Web
                        <span class="hero-title-highlight-tail"></span>
                    </span>
                    <span class="hero-title-line">
                        Best Practices
                    </span>
                </h1>

                <p class="hero-subtitle">
                    Expert insights, practical guides, and the latest trends in managed IT, cloud services, cybersecurity, and web development.
                </p>

                <div class="hero-cta d-flex flex-wrap align-items-center gap-2">
                    <a href="/book.php" class="btn c-btn-primary">
                        <i class="bi bi-calendar-week me-1"></i>
                        Book a Consultation
                    </a>
                    <a href="#posts" class="btn c-btn-ghost">
                        Browse Articles
                    </a>
                </div>

                <div class="hero-meta">
                    <span><i class="bi bi-rss-fill"></i> Regular updates</span>
                    <span><i class="bi bi-bookmark-fill"></i> Expert tips & guides</span>
                </div>
            </div>

            <!-- Snapshot card -->
            <div class="col-lg-5 mt-5 mt-lg-0 d-none d-lg-block">
                <div class="snapshot-card">
                    <div class="snapshot-header">
                        <span class="snapshot-label">What you'll find here</span>
                    </div>

                    <div class="snapshot-body">
                        <div class="snapshot-metric">
                            <span class="snapshot-metric-main"><?= count($posts) ?>+</span>
                            <span class="snapshot-metric-sub">articles & guides</span>
                        </div>

                        <ul class="snapshot-list">
                            <li>
                                <i class="bi bi-laptop"></i>
                                Managed IT tips and best practices
                            </li>
                            <li>
                                <i class="bi bi-cloud-check"></i>
                                Cloud migration and optimization guides
                            </li>
                            <li>
                                <i class="bi bi-shield-lock"></i>
                                Cybersecurity insights and updates
                            </li>
                        </ul>

                        <a href="/contact.php" class="snapshot-cta">
                            Get in touch with our team
                            <i class="bi bi-arrow-right-short"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<div class="main-container">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/">Home</a></li>
            <li class="breadcrumb-item"><a href="/blog/posts.php">Blog</a></li>
            <?php if ($category_slug && isset($current_category)): ?>
                <li class="breadcrumb-item active"><?= htmlspecialchars($current_category['name']) ?></li>
            <?php elseif ($tag): ?>
                <li class="breadcrumb-item active">Tag: <?= htmlspecialchars($tag) ?></li>
            <?php elseif ($search): ?>
                <li class="breadcrumb-item active">Search: <?= htmlspecialchars($search) ?></li>
            <?php else: ?>
                <li class="breadcrumb-item active">All Posts</li>
            <?php endif; ?>
        </ol>
    </nav>

    <div class="blog-layout">
        <!-- Main Content -->
        <main class="posts-content">
            <!-- Search Box -->
            <div class="search-box">
                <form method="GET" action="">
                    <?php if ($category_slug): ?>
                        <input type="hidden" name="category" value="<?= htmlspecialchars($category_slug) ?>">
                    <?php endif; ?>
                    <?php if ($tag): ?>
                        <input type="hidden" name="tag" value="<?= htmlspecialchars($tag) ?>">
                    <?php endif; ?>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Search posts..." autocomplete="off">
                    <button type="submit">
                        <i class="bi bi-search"></i>
                    </button>
                </form>
            </div>

            <!-- Active Filters -->
            <?php if ($category_slug || $tag || $search): ?>
                <div class="mb-3">
                    <span class="text-muted">Active filters:</span>
                    <?php if ($category_slug): ?>
                        <span class="badge bg-primary me-2">
                            Category: <?= htmlspecialchars($current_category['name'] ?? $category_slug) ?>
                            <a href="?" class="text-white ms-1">&times;</a>
                        </span>
                    <?php endif; ?>
                    <?php if ($tag): ?>
                        <span class="badge bg-info me-2">
                            Tag: <?= htmlspecialchars($tag) ?>
                            <a href="<?= $category_slug ? '?category=' . urlencode($category_slug) : '?' ?>" class="text-white ms-1">&times;</a>
                        </span>
                    <?php endif; ?>
                    <?php if ($search): ?>
                        <span class="badge bg-success me-2">
                            Search: <?= htmlspecialchars($search) ?>
                            <a href="<?= $category_slug ? '?category=' . urlencode($category_slug) : ($tag ? '?tag=' . urlencode($tag) : '?') ?>" class="text-white ms-1">&times;</a>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Posts -->
            <?php if (empty($posts)): ?>
                <div class="no-posts">
                    <i class="bi bi-file-text"></i>
                    <h3>No posts found</h3>
                    <?php if ($search): ?>
                        <p>No posts match your search criteria. Try different keywords or <a href="?">browse all posts</a>.</p>
                    <?php elseif ($category_slug || $tag): ?>
                        <p>No posts found in this category/tag. <a href="?">Browse all posts</a>.</p>
                    <?php else: ?>
                        <p>No blog posts have been published yet. Check back soon!</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($posts as $post): ?>
                    <article class="post-card <?= $post['is_featured'] ? 'post-featured' : '' ?>">
                        <?php if ($post['featured_image']): ?>
                            <div class="post-image">
                                <img src="<?= htmlspecialchars($post['featured_image']) ?>" 
                                     alt="<?= htmlspecialchars($post['title']) ?>"
                                     loading="lazy">
                                <?php if ($post['is_featured']): ?>
                                    <div class="featured-badge">
                                        <i class="bi bi-star-fill"></i> Featured
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($post['is_featured']): ?>
                            <div class="post-image" style="height: 60px; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                                <div class="featured-badge" style="position: static;">
                                    <i class="bi bi-star-fill"></i> Featured Post
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="post-content">
                            <div class="post-meta">
                                <span><i class="bi bi-person"></i> <?= htmlspecialchars($post['author_name']) ?></span>
                                <span><i class="bi bi-calendar"></i> <?= date('M j, Y', strtotime($post['published_at'])) ?></span>
                                <?php if ($post['category_name']): ?>
                                    <span>
                                        <i class="bi bi-tag"></i> 
                                        <a href="?category=<?= urlencode($post['category_slug']) ?>" class="text-decoration-none">
                                            <?= htmlspecialchars($post['category_name']) ?>
                                        </a>
                                    </span>
                                <?php endif; ?>
                                <span><i class="bi bi-eye"></i> <?= number_format($post['view_count']) ?></span>
                                <?php if ($post['image_count'] > 0): ?>
                                    <span><i class="bi bi-images"></i> <?= $post['image_count'] ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <a href="/blog/post.php?slug=<?= htmlspecialchars($post['slug']) ?>" class="post-title">
                                <?= htmlspecialchars($post['title']) ?>
                            </a>
                            
                            <div class="post-excerpt">
                                <?php if ($post['excerpt']): ?>
                                    <?= htmlspecialchars($post['excerpt']) ?>
                                <?php else: ?>
                                    <?= htmlspecialchars(substr(strip_tags($post['content']), 0, 200)) ?>...
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($post['tags']): ?>
                                <div class="post-tags">
                                    <?php foreach (explode(', ', $post['tags']) as $post_tag): ?>
                                        <a href="?tag=<?= urlencode(trim($post_tag)) ?>" class="tag">
                                            #<?= htmlspecialchars(trim($post_tag)) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="mt-3">
                                <a href="/blog/post.php?slug=<?= htmlspecialchars($post['slug']) ?>" class="btn btn-outline-primary">
                                    Read More <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-wrapper">
                        <nav aria-label="Blog pagination">
                            <ul class="pagination">
                                <?php
                                $current_params = $_GET;
                                unset($current_params['page']);
                                $base_url = '?' . http_build_query($current_params);
                                $base_url = $base_url === '?' ? '?' : $base_url . '&';
                                
                                // Previous page
                                if ($page > 1):
                                ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?= $base_url ?>page=<?= $page - 1 ?>">
                                            <i class="bi bi-chevron-left"></i> Previous
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <?php
                                // Page numbers
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                if ($start_page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?= $base_url ?>page=1">1</a>
                                    </li>
                                    <?php if ($start_page > 2): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= $base_url ?>page=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?= $base_url ?>page=<?= $total_pages ?>"><?= $total_pages ?></a>
                                    </li>
                                <?php endif; ?>

                                <?php
                                // Next page
                                if ($page < $total_pages):
                                ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?= $base_url ?>page=<?= $page + 1 ?>">
                                            Next <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                        
                        <div class="mt-3">
                            <small class="text-muted">
                                Showing <?= number_format(($page - 1) * $posts_per_page + 1) ?> to 
                                <?= number_format(min($page * $posts_per_page, $total_posts)) ?> of 
                                <?= number_format($total_posts) ?> posts
                            </small>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>

        <!-- Sidebar -->
        <aside class="sidebar">
            <!-- Recent Posts -->
            <?php if (!empty($recent_posts)): ?>
                <div class="sidebar-section">
                    <h3 class="sidebar-title"><i class="bi bi-clock-history"></i> Recent Posts</h3>
                    <?php foreach ($recent_posts as $recent_post): ?>
                        <div class="sidebar-post">
                            <div class="sidebar-post-image">
                                <?php if ($recent_post['featured_image']): ?>
                                    <img src="<?= htmlspecialchars($recent_post['featured_image']) ?>" 
                                         alt="<?= htmlspecialchars($recent_post['title']) ?>">
                                <?php else: ?>
                                    <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                                        <i class="bi bi-file-text"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="sidebar-post-content">
                                <h6>
                                    <a href="/blog/post.php?slug=<?= htmlspecialchars($recent_post['slug']) ?>">
                                        <?= htmlspecialchars($recent_post['title']) ?>
                                    </a>
                                </h6>
                                <div class="sidebar-post-meta">
                                    <?= date('M j, Y', strtotime($recent_post['published_at'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Featured Posts -->
            <?php if (!empty($featured_posts)): ?>
                <div class="sidebar-section">
                    <h3 class="sidebar-title"><i class="bi bi-star"></i> Featured Posts</h3>
                    <?php foreach ($featured_posts as $featured_post): ?>
                        <div class="sidebar-post">
                            <div class="sidebar-post-content">
                                <h6>
                                    <a href="/blog/post.php?slug=<?= htmlspecialchars($featured_post['slug']) ?>">
                                        <?= htmlspecialchars($featured_post['title']) ?>
                                    </a>
                                </h6>
                                <div class="sidebar-post-meta">
                                    <?= date('M j, Y', strtotime($featured_post['published_at'])) ?> • 
                                    <?= number_format($featured_post['view_count']) ?> views
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Categories -->
            <?php if (!empty($categories)): ?>
                <div class="sidebar-section">
                    <h3 class="sidebar-title"><i class="bi bi-tags"></i> Categories</h3>
                    <?php foreach ($categories as $cat): ?>
                        <a href="?category=<?= urlencode($cat['slug']) ?>" class="category-link">
                            <span><?= htmlspecialchars($cat['name']) ?></span>
                            <span class="category-count"><?= $cat['post_count'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Popular Tags -->
            <?php if (!empty($popular_tags)): ?>
                <div class="sidebar-section">
                    <h3 class="sidebar-title"><i class="bi bi-hash"></i> Popular Tags</h3>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($popular_tags as $tag_item): ?>
                            <a href="?tag=<?= urlencode($tag_item['tag_name']) ?>" class="tag">
                                #<?= htmlspecialchars($tag_item['tag_name']) ?>
                                <small>(<?= $tag_item['tag_count'] ?>)</small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- RSS Feed -->
            <?php if (($blog_settings['enable_rss'] ?? '1') === '1'): ?>
                <div class="sidebar-section">
                    <h3 class="sidebar-title"><i class="bi bi-rss"></i> Subscribe</h3>
                    <a href="/blog/rss.xml" class="btn btn-outline-warning btn-sm w-100">
                        <i class="bi bi-rss"></i> RSS Feed
                    </a>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>

<script>
// Smooth scrolling for internal links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        document.querySelector(this.getAttribute('href')).scrollIntoView({
            behavior: 'smooth'
        });
    });
});

// Search form enhancement
const searchForm = document.querySelector('.search-box form');
const searchInput = document.querySelector('.search-box input');

searchInput.addEventListener('keyup', function(e) {
    if (e.key === 'Escape') {
        this.value = '';
    }
});

// Lazy loading for images
if ('IntersectionObserver' in window) {
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src;
                img.classList.remove('lazy');
                imageObserver.unobserve(img);
            }
        });
    });

    document.querySelectorAll('img[data-src]').forEach(img => {
        imageObserver.observe(img);
    });
}
</script>

</body>
</html>