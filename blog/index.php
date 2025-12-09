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
$excerpt_length = (int)($blog_settings['posts_excerpt_length'] ?? 150);

// Get filters
$category_slug = $_GET['category'] ?? '';
$tag = $_GET['tag'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $posts_per_page;

// Build WHERE clause
$where_conditions = ["p.status = 'published'", "p.published_at <= NOW()"];
$params = [];

if ($category_slug) {
    $where_conditions[] = "c.slug = ?";
    $params[] = $category_slug;
}

if ($tag) {
    $where_conditions[] = "EXISTS (SELECT 1 FROM blog_post_tags t WHERE t.post_id = p.id AND t.tag_name = ?)";
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
    SELECT p.id, p.title, p.slug, p.excerpt, p.content, p.featured_image, 
           p.published_at, p.view_count, p.is_featured,
           u.username as author_name, u.email as author_email,
           c.name as category_name, c.slug as category_slug,
           (SELECT GROUP_CONCAT(tag_name) FROM blog_post_tags WHERE post_id = p.id) as tags,
           (SELECT COUNT(*) FROM blog_post_attachments WHERE post_id = p.id) as attachment_count
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

// Get current category info if filtering
$current_category = null;
if ($category_slug) {
    $stmt = $pdo->prepare("SELECT name, description FROM blog_categories WHERE slug = ? AND is_active = 1");
    $stmt->execute([$category_slug]);
    $current_category = $stmt->fetch();
}

// Get sidebar data
// Recent posts
$stmt = $pdo->prepare("
    SELECT id, title, slug, published_at 
    FROM blog_posts 
    WHERE status = 'published' AND published_at <= NOW()
    ORDER BY published_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_posts = $stmt->fetchAll();

// Categories with post counts
$stmt = $pdo->prepare("
    SELECT c.name, c.slug, COUNT(p.id) as post_count
    FROM blog_categories c
    LEFT JOIN blog_posts p ON c.id = p.category_id AND p.status = 'published'
    WHERE c.is_active = 1
    GROUP BY c.id, c.name, c.slug
    HAVING post_count > 0
    ORDER BY post_count DESC, c.name ASC
    LIMIT 10
");
$stmt->execute();
$popular_categories = $stmt->fetchAll();

// Popular tags
$stmt = $pdo->prepare("
    SELECT t.tag_name, COUNT(*) as count
    FROM blog_post_tags t
    INNER JOIN blog_posts p ON t.post_id = p.id
    WHERE p.status = 'published'
    GROUP BY t.tag_name
    ORDER BY count DESC
    LIMIT 15
");
$stmt->execute();
$popular_tags = $stmt->fetchAll();

// Generate page title and meta description
$page_title = $blog_title;
$meta_description = $blog_description;

if ($current_category) {
    $page_title = $current_category['name'] . ' | ' . $blog_title;
    $meta_description = $current_category['description'] ?: "Posts in " . $current_category['name'] . " category";
} elseif ($tag) {
    $page_title = "Posts tagged: $tag | " . $blog_title;
    $meta_description = "All posts tagged with '$tag'";
} elseif ($search) {
    $page_title = "Search results for: $search | " . $blog_title;
    $meta_description = "Search results for '$search'";
}

// Auto-excerpt function
function generateExcerpt($content, $length = 150) {
    $content = strip_tags($content);
    $content = preg_replace('/\s+/', ' ', $content);
    if (strlen($content) <= $length) {
        return $content;
    }
    return substr($content, 0, $length) . '...';
}
?>

<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($meta_description) ?>">
    
    <?php if ($blog_settings['seo_enabled'] === '1'): ?>
        <!-- SEO Meta Tags -->
        <meta property="og:title" content="<?= htmlspecialchars($page_title) ?>">
        <meta property="og:description" content="<?= htmlspecialchars($meta_description) ?>">
        <meta property="og:type" content="website">
        <meta property="og:url" content="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
        
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="<?= htmlspecialchars($page_title) ?>">
        <meta name="twitter:description" content="<?= htmlspecialchars($meta_description) ?>">
    <?php endif; ?>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">
    
    <?php if ($blog_settings['enable_rss'] === '1'): ?>
        <link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($blog_title) ?> RSS Feed" href="/blog/rss.xml">
    <?php endif; ?>
    
    <style>
        body {
            background-color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .blog-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4rem 0;
            margin-bottom: 3rem;
        }
        
        .blog-header h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .blog-header p {
            font-size: 1.25rem;
            opacity: 0.9;
            margin-bottom: 2rem;
        }
        
        .search-form {
            max-width: 500px;
        }
        
        .search-form .input-group {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .search-form .form-control {
            border: none;
            padding: 0.75rem 1rem;
        }
        
        .search-form .btn {
            border: none;
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .search-form .btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
        }
        
        .content-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 3rem;
            margin-bottom: 3rem;
        }
        
        .post-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 2rem;
            transition: all 0.3s;
        }
        
        .post-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .post-image {
            height: 250px;
            background: #f3f4f6;
            overflow: hidden;
            position: relative;
        }
        
        .post-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .post-image .placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #9ca3af;
            font-size: 3rem;
        }
        
        .featured-badge {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background: #ef4444;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .post-content {
            padding: 2rem;
        }
        
        .post-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 1rem;
            text-decoration: none;
            line-height: 1.3;
        }
        
        .post-title:hover {
            color: #4f46e5;
        }
        
        .post-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .post-meta a {
            color: #4f46e5;
            text-decoration: none;
        }
        
        .post-meta a:hover {
            text-decoration: underline;
        }
        
        .post-excerpt {
            color: #4b5563;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }
        
        .post-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .tag {
            background: #f3f4f6;
            color: #374151;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .tag:hover {
            background: #4f46e5;
            color: white;
        }
        
        .read-more {
            color: #4f46e5;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .read-more:hover {
            color: #3730a3;
        }
        
        .sidebar {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            height: fit-content;
            position: sticky;
            top: 2rem;
        }
        
        .sidebar h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .sidebar-section {
            margin-bottom: 2rem;
        }
        
        .sidebar-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .sidebar-list li {
            padding: 0.5rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .sidebar-list li:last-child {
            border-bottom: none;
        }
        
        .sidebar-list a {
            color: #374151;
            text-decoration: none;
            display: flex;
            justify-content: between;
            align-items: center;
        }
        
        .sidebar-list a:hover {
            color: #4f46e5;
        }
        
        .post-count {
            background: #f3f4f6;
            color: #6b7280;
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            margin-left: auto;
        }
        
        .pagination-wrapper {
            display: flex;
            justify-content: center;
            margin: 3rem 0;
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #d1d5db;
            margin-bottom: 1rem;
        }
        
        .breadcrumb {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 2rem;
        }
        
        @media (max-width: 768px) {
            .blog-header h1 {
                font-size: 2rem;
            }
            
            .content-layout {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
            
            .post-content {
                padding: 1.5rem;
            }
            
            .sidebar {
                position: static;
            }
        }

        /* Hero Search Form Styles */
        .search-form-hero {
            max-width: 600px;
            margin-top: 2rem;
        }

        .search-hero-group {
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            border-radius: 50px;
            overflow: hidden;
        }

        .search-hero-input {
            border: none;
            padding: 1rem 1.5rem;
            font-size: 1rem;
            border-radius: 50px 0 0 50px;
        }

        .search-hero-input:focus {
            box-shadow: none;
            border: none;
        }

        .btn-search-hero {
            background: white;
            color: #667eea;
            border: none;
            padding: 1rem 2rem;
            font-weight: 600;
            border-radius: 0 50px 50px 0;
            transition: all 0.3s;
        }

        .btn-search-hero:hover {
            background: #f8fafc;
            color: #764ba2;
            transform: translateX(2px);
        }

        :root.dark .search-hero-input {
            background: #1e293b;
            color: #e2e8f0;
        }

        :root.dark .search-hero-input::placeholder {
            color: #64748b;
        }

        :root.dark .btn-search-hero {
            background: #1e293b;
            color: #a78bfa;
        }

        :root.dark .btn-search-hero:hover {
            background: #0f172a;
            color: #c4b5fd;
        }
    </style>
</head>
<body>

<?php
$page_title = htmlspecialchars($blog_title) . " | CaminhoIT";
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php';
?>

<!-- Hero Section -->
<section class="hero">
    <div class="hero-gradient"></div>
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8">
                <h1 class="hero-title">
                    <i class="bi bi-newspaper me-3"></i>
                    <?= htmlspecialchars($blog_title) ?>
                </h1>
                <p class="hero-subtitle">
                    <?= htmlspecialchars($blog_description) ?>
                </p>

                <!-- Search Form -->
                <form method="GET" class="search-form-hero mx-auto">
                    <?php if ($category_slug): ?>
                        <input type="hidden" name="category" value="<?= htmlspecialchars($category_slug) ?>">
                    <?php endif; ?>
                    <?php if ($tag): ?>
                        <input type="hidden" name="tag" value="<?= htmlspecialchars($tag) ?>">
                    <?php endif; ?>

                    <div class="input-group search-hero-group">
                        <input type="text" class="form-control search-hero-input" name="search"
                               value="<?= htmlspecialchars($search) ?>"
                               placeholder="Search blog posts...">
                        <button class="btn btn-search-hero" type="submit">
                            <i class="bi bi-search"></i> Search
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

<div class="main-container">
    <!-- Breadcrumb -->
    <?php if ($current_category || $tag || $search): ?>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/blog/">Blog</a></li>
                <?php if ($current_category): ?>
                    <li class="breadcrumb-item active" aria-current="page">
                        <?= htmlspecialchars($current_category['name']) ?>
                    </li>
                <?php elseif ($tag): ?>
                    <li class="breadcrumb-item active" aria-current="page">
                        Tag: <?= htmlspecialchars($tag) ?>
                    </li>
                <?php elseif ($search): ?>
                    <li class="breadcrumb-item active" aria-current="page">
                        Search: <?= htmlspecialchars($search) ?>
                    </li>
                <?php endif; ?>
            </ol>
        </nav>
    <?php endif; ?>

    <div class="content-layout">
        <!-- Main Content -->
        <main>
            <?php if (empty($posts)): ?>
                <!-- Empty State -->
                <div class="empty-state">
                    <i class="bi bi-file-text"></i>
                    <h3>No posts found</h3>
                    <?php if ($search): ?>
                        <p>No posts match your search for "<?= htmlspecialchars($search) ?>". Try different keywords or <a href="/blog/">browse all posts</a>.</p>
                    <?php elseif ($current_category): ?>
                        <p>No posts in the "<?= htmlspecialchars($current_category['name']) ?>" category yet. <a href="/blog/">Browse all posts</a>.</p>
                    <?php elseif ($tag): ?>
                        <p>No posts tagged with "<?= htmlspecialchars($tag) ?>". <a href="/blog/">Browse all posts</a>.</p>
                    <?php else: ?>
                        <p>No blog posts have been published yet. Check back soon!</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Posts Grid -->
                <?php foreach ($posts as $post): ?>
                    <article class="post-card">
                        <?php if ($post['featured_image']): ?>
                            <div class="post-image">
                                <img src="<?= htmlspecialchars($post['featured_image']) ?>" alt="<?= htmlspecialchars($post['title']) ?>">
                                <?php if ($post['is_featured']): ?>
                                    <div class="featured-badge">Featured</div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($post['is_featured']): ?>
                            <div class="post-image">
                                <div class="placeholder">
                                    <i class="bi bi-star-fill"></i>
                                </div>
                                <div class="featured-badge">Featured</div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="post-content">
                            <h2>
                                <a href="/blog/post.php?slug=<?= htmlspecialchars($post['slug']) ?>" class="post-title">
                                    <?= htmlspecialchars($post['title']) ?>
                                </a>
                            </h2>
                            
                            <div class="post-meta">
                                <span><i class="bi bi-person"></i> <?= htmlspecialchars($post['author_name']) ?></span>
                                <span><i class="bi bi-calendar"></i> <?= date('M j, Y', strtotime($post['published_at'])) ?></span>
                                <?php if ($post['category_name']): ?>
                                    <span>
                                        <i class="bi bi-folder"></i> 
                                        <a href="/blog/?category=<?= htmlspecialchars($post['category_slug']) ?>">
                                            <?= htmlspecialchars($post['category_name']) ?>
                                        </a>
                                    </span>
                                <?php endif; ?>
                                <span><i class="bi bi-eye"></i> <?= number_format($post['view_count']) ?></span>
                                <?php if ($post['attachment_count'] > 0): ?>
                                    <span><i class="bi bi-paperclip"></i> <?= $post['attachment_count'] ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($post['tags']): ?>
                                <div class="post-tags">
                                    <?php foreach (explode(',', $post['tags']) as $tag_name): ?>
                                        <a href="/blog/posts.php?tag=<?= urlencode(trim($tag_name)) ?>" class="tag">
                                            <?= htmlspecialchars(trim($tag_name)) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="post-excerpt">
                                <?= htmlspecialchars($post['excerpt'] ?: generateExcerpt($post['content'], $excerpt_length)) ?>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center">
                                <a href="/blog/post.php?slug=<?= htmlspecialchars($post['slug']) ?>" class="read-more">
                                    Read more <i class="bi bi-arrow-right"></i>
                                </a>

                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="bi bi-share"></i> Share
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <a class="dropdown-item" href="https://twitter.com/intent/tweet?text=<?= urlencode($post['title']) ?>&url=<?= urlencode('https://' . $_SERVER['HTTP_HOST'] . '/blog/post.php?slug=' . $post['slug']) ?>" target="_blank">
                                                <i class="bi bi-twitter text-primary"></i> Share on Twitter
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode('https://' . $_SERVER['HTTP_HOST'] . '/blog/post.php?slug=' . $post['slug']) ?>" target="_blank">
                                                <i class="bi bi-facebook text-primary"></i> Share on Facebook
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="https://www.linkedin.com/sharing/share-offsite/?url=<?= urlencode('https://' . $_SERVER['HTTP_HOST'] . '/blog/post.php?slug=' . $post['slug']) ?>" target="_blank">
                                                <i class="bi bi-linkedin text-primary"></i> Share on LinkedIn
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item" href="mailto:?subject=<?= urlencode($post['title']) ?>&body=<?= urlencode('Check out this post: https://' . $_SERVER['HTTP_HOST'] . '/blog/post.php?slug=' . $post['slug']) ?>">
                                                <i class="bi bi-envelope"></i> Share via Email
                                            </a>
                                        </li>
                                    </ul>
                                </div>
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
                                
                                // Previous page
                                if ($page > 1):
                                    $current_params['page'] = $page - 1;
                                    $prev_url = '?' . http_build_query($current_params);
                                ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?= $prev_url ?>">
                                            <i class="bi bi-chevron-left"></i> Previous
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <?php
                                // Page numbers
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                    $current_params['page'] = $i;
                                    $page_url = '?' . http_build_query($current_params);
                                ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= $page_url ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php
                                // Next page
                                if ($page < $total_pages):
                                    $current_params['page'] = $page + 1;
                                    $next_url = '?' . http_build_query($current_params);
                                ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?= $next_url ?>">
                                            Next <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>

        <!-- Sidebar -->
        <aside class="sidebar">
            <!-- Recent Posts -->
            <?php if (!empty($recent_posts)): ?>
                <div class="sidebar-section">
                    <h3>Recent Posts</h3>
                    <ul class="sidebar-list">
                        <?php foreach ($recent_posts as $recent_post): ?>
                            <li>
                                <a href="/blog/post/<?= htmlspecialchars($recent_post['slug']) ?>">
                                    <?= htmlspecialchars($recent_post['title']) ?>
                                    <small class="d-block text-muted"><?= date('M j', strtotime($recent_post['published_at'])) ?></small>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <!-- Categories -->
            <?php if (!empty($popular_categories)): ?>
                <div class="sidebar-section">
                    <h3>Categories</h3>
                    <ul class="sidebar-list">
                        <?php foreach ($popular_categories as $category): ?>
                            <li>
                                <a href="/blog/?category=<?= htmlspecialchars($category['slug']) ?>">
                                    <?= htmlspecialchars($category['name']) ?>
                                    <span class="post-count"><?= $category['post_count'] ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <!-- Popular Tags -->
            <?php if (!empty($popular_tags)): ?>
                <div class="sidebar-section">
                    <h3>Popular Tags</h3>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($popular_tags as $popular_tag): ?>
                            <a href="/blog/?tag=<?= urlencode($popular_tag['tag_name']) ?>" class="tag">
                                <?= htmlspecialchars($popular_tag['tag_name']) ?>
                                <small>(<?= $popular_tag['count'] ?>)</small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Blog Archive -->
            <div class="sidebar-section">
                <h3>Archive</h3>
                <ul class="sidebar-list">
                    <?php
                    // Get archive months
                    $stmt = $pdo->prepare("
                        SELECT DATE_FORMAT(published_at, '%Y-%m') as month,
                               DATE_FORMAT(published_at, '%M %Y') as month_name,
                               COUNT(*) as count
                        FROM blog_posts 
                        WHERE status = 'published' 
                        GROUP BY month, month_name
                        ORDER BY month DESC
                        LIMIT 12
                    ");
                    $stmt->execute();
                    $archive_months = $stmt->fetchAll();
                    
                    foreach ($archive_months as $month): ?>
                        <li>
                            <a href="/blog/?month=<?= $month['month'] ?>">
                                <?= $month['month_name'] ?>
                                <span class="post-count"><?= $month['count'] ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <!-- RSS Feed -->
            <?php if ($blog_settings['enable_rss'] === '1'): ?>
                <div class="sidebar-section">
                    <h3>Subscribe</h3>
                    <a href="/blog/rss.xml" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-rss me-2"></i>RSS Feed
                    </a>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>

<!-- Footer -->
<?php if ($blog_settings['blog_footer_text']): ?>
    <footer class="bg-light py-4">
        <div class="container text-center">
            <p class="text-muted mb-0"><?= htmlspecialchars($blog_settings['blog_footer_text']) ?></p>
        </div>
    </footer>
<?php endif; ?>


<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>

</body>
</html>