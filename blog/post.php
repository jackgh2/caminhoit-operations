<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

// Get post slug from URL
$slug = $_GET['slug'] ?? '';
if (empty($slug)) {
    header('HTTP/1.0 404 Not Found');
    include $_SERVER['DOCUMENT_ROOT'] . '/404.php';
    exit;
}

// Get blog settings
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM blog_settings");
$stmt->execute();
$blog_settings = [];
while ($row = $stmt->fetch()) {
    $blog_settings[$row['setting_key']] = $row['setting_value'];
}

$blog_title = $blog_settings['blog_title'] ?? 'Blog';

// Get post data
$stmt = $pdo->prepare("
    SELECT p.*, u.username as author_name, u.email as author_email,
           c.name as category_name, c.slug as category_slug, c.description as category_description,
           (SELECT GROUP_CONCAT(pt.tag_name SEPARATOR ', ') FROM blog_post_tags pt WHERE pt.post_id = p.id) as tags
    FROM blog_posts p
    LEFT JOIN users u ON p.author_id = u.id
    LEFT JOIN blog_categories c ON p.category_id = c.id
    WHERE p.slug = ? AND p.status = 'published' AND p.published_at <= NOW()
");
$stmt->execute([$slug]);
$post = $stmt->fetch();

if (!$post) {
    header('HTTP/1.0 404 Not Found');
    include $_SERVER['DOCUMENT_ROOT'] . '/404.php';
    exit;
}

// Update view count (only once per session per post)
if (!isset($_SESSION['viewed_posts'])) {
    $_SESSION['viewed_posts'] = [];
}

if (!in_array($post['id'], $_SESSION['viewed_posts'])) {
    $stmt = $pdo->prepare("UPDATE blog_posts SET view_count = view_count + 1 WHERE id = ?");
    $stmt->execute([$post['id']]);
    $_SESSION['viewed_posts'][] = $post['id'];
    $post['view_count']++; // Update local copy
}

// Get post attachments
$stmt = $pdo->prepare("
    SELECT * FROM blog_post_attachments 
    WHERE post_id = ? 
    ORDER BY sort_order ASC, created_at ASC
");
$stmt->execute([$post['id']]);
$attachments = $stmt->fetchAll();

// Get related posts (same category, excluding current post)
$stmt = $pdo->prepare("
    SELECT p.id, p.title, p.slug, p.excerpt, p.featured_image, p.published_at, p.view_count
    FROM blog_posts p
    WHERE p.category_id = ? AND p.id != ? AND p.status = 'published' AND p.published_at <= NOW()
    ORDER BY p.published_at DESC
    LIMIT 3
");
$stmt->execute([$post['category_id'], $post['id']]);
$related_posts = $stmt->fetchAll();

// Get next and previous posts
$stmt = $pdo->prepare("
    SELECT id, title, slug FROM blog_posts 
    WHERE published_at > ? AND status = 'published' AND published_at <= NOW()
    ORDER BY published_at ASC LIMIT 1
");
$stmt->execute([$post['published_at']]);
$next_post = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT id, title, slug FROM blog_posts 
    WHERE published_at < ? AND status = 'published' AND published_at <= NOW()
    ORDER BY published_at DESC LIMIT 1
");
$stmt->execute([$post['published_at']]);
$previous_post = $stmt->fetch();

// Meta information
$page_title = ($post['meta_title'] ?: $post['title']) . ' | ' . $blog_title;
$page_description = $post['meta_description'] ?: ($post['excerpt'] ?: substr(strip_tags($post['content']), 0, 160));
$canonical_url = 'https://' . $_SERVER['HTTP_HOST'] . '/blog/post.php?slug=' . urlencode($post['slug']);

// Calculate reading time (words per minute average is 200)
$word_count = str_word_count(strip_tags($post['content']));
$reading_time = max(1, ceil($word_count / 200));

// Structured data for SEO
$structured_data = [
    "@context" => "https://schema.org",
    "@type" => "BlogPosting",
    "headline" => $post['title'],
    "description" => $page_description,
    "image" => $post['featured_image'] ? 'https://' . $_SERVER['HTTP_HOST'] . $post['featured_image'] : null,
    "author" => [
        "@type" => "Person",
        "name" => $post['author_name']
    ],
    "publisher" => [
        "@type" => "Organization",
        "name" => $blog_title,
        "url" => 'https://' . $_SERVER['HTTP_HOST']
    ],
    "datePublished" => date('c', strtotime($post['published_at'])),
    "dateModified" => date('c', strtotime($post['updated_at'])),
    "url" => $canonical_url,
    "mainEntityOfPage" => [
        "@type" => "WebPage",
        "@id" => $canonical_url
    ],
    "wordCount" => $word_count,
    "timeRequired" => "PT{$reading_time}M",
    "inLanguage" => $lang
];

// Add article section/category if available
if ($post['category_name']) {
    $structured_data['articleSection'] = $post['category_name'];
}

// Add keywords if available
if ($post['tags']) {
    $structured_data['keywords'] = $post['tags'];
}
?>

<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="<?= htmlspecialchars($post['author_name']) ?>">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
    <?php if ($post['tags']): ?>
    <meta name="keywords" content="<?= htmlspecialchars($post['tags']) ?>">
    <?php endif; ?>
    <meta name="language" content="<?= $lang ?>">
    <meta name="revisit-after" content="7 days">
    <link rel="canonical" href="<?= htmlspecialchars($canonical_url) ?>">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?= htmlspecialchars($post['title']) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonical_url) ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars($blog_title) ?>">
    <meta property="og:locale" content="<?= $lang === 'pt' ? 'pt_BR' : 'en_US' ?>">
    <?php if ($post['featured_image']): ?>
        <meta property="og:image" content="https://<?= $_SERVER['HTTP_HOST'] ?><?= htmlspecialchars($post['featured_image']) ?>">
        <meta property="og:image:alt" content="<?= htmlspecialchars($post['title']) ?>">
    <?php endif; ?>
    <meta property="article:author" content="<?= htmlspecialchars($post['author_name']) ?>">
    <meta property="article:published_time" content="<?= date('c', strtotime($post['published_at'])) ?>">
    <meta property="article:modified_time" content="<?= date('c', strtotime($post['updated_at'])) ?>">
    <?php if ($post['category_name']): ?>
        <meta property="article:section" content="<?= htmlspecialchars($post['category_name']) ?>">
    <?php endif; ?>
    <?php if ($post['tags']): ?>
        <?php foreach (explode(', ', $post['tags']) as $tag): ?>
            <meta property="article:tag" content="<?= htmlspecialchars(trim($tag)) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($post['title']) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($page_description) ?>">
    <?php if ($post['featured_image']): ?>
        <meta name="twitter:image" content="https://<?= $_SERVER['HTTP_HOST'] ?><?= htmlspecialchars($post['featured_image']) ?>">
        <meta name="twitter:image:alt" content="<?= htmlspecialchars($post['title']) ?>">
    <?php endif; ?>
    <meta name="twitter:creator" content="@<?= htmlspecialchars($post['author_name']) ?>">
    <meta name="twitter:label1" content="Reading time">
    <meta name="twitter:data1" content="<?= $reading_time ?> min read">
    
    <!-- Structured Data - Blog Post -->
    <script type="application/ld+json">
        <?= json_encode($structured_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?>
    </script>

    <!-- Structured Data - Breadcrumb -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        "itemListElement": [
            {
                "@type": "ListItem",
                "position": 1,
                "name": "Home",
                "item": "https://<?= $_SERVER['HTTP_HOST'] ?>"
            },
            {
                "@type": "ListItem",
                "position": 2,
                "name": "Blog",
                "item": "https://<?= $_SERVER['HTTP_HOST'] ?>/blog/"
            }<?php if ($post['category_name']): ?>,
            {
                "@type": "ListItem",
                "position": 3,
                "name": "<?= htmlspecialchars($post['category_name']) ?>",
                "item": "https://<?= $_SERVER['HTTP_HOST'] ?>/blog/?category=<?= urlencode($post['category_slug']) ?>"
            },
            {
                "@type": "ListItem",
                "position": 4,
                "name": "<?= htmlspecialchars($post['title']) ?>",
                "item": "<?= htmlspecialchars($canonical_url) ?>"
            }<?php else: ?>,
            {
                "@type": "ListItem",
                "position": 3,
                "name": "<?= htmlspecialchars($post['title']) ?>",
                "item": "<?= htmlspecialchars($canonical_url) ?>"
            }<?php endif; ?>
        ]
    }
    </script>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">
    
    <style>
        body {
            background-color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.7;
            padding-top: 80px;
        }

        .post-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4rem 0 6rem;
            margin-bottom: -4rem;
            margin-top: -80px;
            padding-top: calc(4rem + 80px);
            position: relative;
            overflow: hidden;
        }

        .post-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.9) 0%, rgba(118, 75, 162, 0.9) 100%);
            z-index: 0;
        }

        .post-header .post-container {
            position: relative;
            z-index: 1;
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

        .featured-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
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
            border-radius: 12px 12px 0 0;
        }

        .featured-image.crop-mode {
            height: 400px;
            object-fit: cover;
        }

        .featured-image.full-mode {
            height: auto;
            object-fit: contain;
            max-height: 600px;
            background: #f8fafc;
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

        .post-content h1 { font-size: 2rem; }
        .post-content h2 { font-size: 1.75rem; }
        .post-content h3 { font-size: 1.5rem; }
        .post-content h4 { font-size: 1.25rem; }

        .post-content p {
            margin-bottom: 1.5rem;
            color: #374151;
            font-size: 1.1rem;
        }

        .post-content blockquote {
            border-left: 4px solid #667eea;
            padding-left: 1.5rem;
            margin: 2rem 0;
            font-style: italic;
            color: #64748b;
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 0 8px 8px 0;
        }

        .post-content code {
            background: #f1f5f9;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.9rem;
        }

        .post-content pre {
            background: #1e293b;
            color: #e2e8f0;
            padding: 1.5rem;
            border-radius: 8px;
            overflow-x: auto;
            margin: 1.5rem 0;
        }

        .post-content pre code {
            background: none;
            padding: 0;
            color: inherit;
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
            text-decoration: none;
            transition: all 0.2s;
        }

        .tag:hover {
            background: #667eea;
            color: white;
            text-decoration: none;
        }

        .post-navigation {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin: 3rem 0;
        }

        .nav-post {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .nav-post:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            text-decoration: none;
            color: inherit;
        }

        .nav-post-label {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.5rem;
        }

        .nav-post-title {
            font-weight: 600;
            color: #1e293b;
        }

        .related-posts {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            margin-bottom: 3rem;
        }

        .related-posts h3 {
            margin-bottom: 1.5rem;
            color: #1e293b;
            font-weight: 600;
        }

        .related-post {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid #f1f5f9;
            text-decoration: none;
            color: inherit;
        }

        .related-post:last-child {
            border-bottom: none;
        }

        .related-post:hover {
            background: #f8fafc;
            border-radius: 8px;
            text-decoration: none;
            color: inherit;
        }

        .related-post-image {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
            background: #f1f5f9;
            flex-shrink: 0;
        }

        .related-post-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .related-post-content h4 {
            font-size: 1rem;
            margin-bottom: 0.5rem;
            color: #1e293b;
        }

        .related-post-meta {
            font-size: 0.85rem;
            color: #64748b;
        }

        .attachments-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 2rem 0;
        }

        .attachment-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem;
            background: white;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
        }

        .attachment-item:hover {
            background: #e2e8f0;
            text-decoration: none;
            color: inherit;
        }

        .attachment-icon {
            width: 40px;
            height: 40px;
            border-radius: 6px;
            background: #667eea;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .share-buttons {
            display: flex;
            gap: 1rem;
            margin: 2rem 0;
            padding-top: 2rem;
            border-top: 1px solid #e2e8f0;
        }

        .share-btn {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            color: white;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .share-btn:hover {
            transform: translateY(-1px);
            text-decoration: none;
            color: white;
        }

        .share-twitter { background: #1da1f2; }
        .share-facebook { background: #4267b2; }
        .share-linkedin { background: #0077b5; }
        .share-email { background: #6b7280; }

        @media (max-width: 768px) {
            .post-title {
                font-size: 2rem;
            }

            .post-content {
                padding: 2rem 1.5rem;
            }

            .post-navigation {
                grid-template-columns: 1fr;
            }

            .related-post {
                flex-direction: column;
                align-items: flex-start;
            }

            .related-post-image {
                width: 100%;
                height: 200px;
            }
        }

        /* Dark Mode Styles */
        :root.dark body {
            background: #0f172a !important;
        }

        :root.dark .main-content {
            background: #1e293b !important;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3) !important;
        }

        :root.dark .post-content {
            color: #e2e8f0 !important;
        }

        :root.dark .post-content h1,
        :root.dark .post-content h2,
        :root.dark .post-content h3,
        :root.dark .post-content h4,
        :root.dark .post-content h5,
        :root.dark .post-content h6 {
            color: #f1f5f9 !important;
        }

        :root.dark .post-content p {
            color: #cbd5e1 !important;
        }

        :root.dark .post-content blockquote {
            background: #0f172a !important;
            color: #94a3b8 !important;
            border-left-color: #a78bfa !important;
        }

        :root.dark .post-content code {
            background: #0f172a !important;
            color: #a78bfa !important;
        }

        :root.dark .post-content pre {
            background: #0f172a !important;
            color: #e2e8f0 !important;
        }

        :root.dark .tag {
            background: #334155 !important;
            color: #cbd5e1 !important;
        }

        :root.dark .tag:hover {
            background: #a78bfa !important;
            color: white !important;
        }

        :root.dark .nav-post {
            background: #1e293b !important;
            color: #e2e8f0 !important;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2) !important;
        }

        :root.dark .nav-post:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4) !important;
        }

        :root.dark .nav-post-label {
            color: #94a3b8 !important;
        }

        :root.dark .nav-post-title {
            color: #f1f5f9 !important;
        }

        :root.dark .related-posts {
            background: #1e293b !important;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3) !important;
        }

        :root.dark .related-posts h3 {
            color: #f1f5f9 !important;
        }

        :root.dark .related-post {
            border-bottom-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .related-post:hover {
            background: #0f172a !important;
        }

        :root.dark .related-post-content h4 {
            color: #f1f5f9 !important;
        }

        :root.dark .related-post-meta {
            color: #94a3b8 !important;
        }

        :root.dark .related-post-content p {
            color: #cbd5e1 !important;
        }

        :root.dark .related-post-image {
            background: #0f172a !important;
        }

        :root.dark .attachments-section {
            background: #0f172a !important;
        }

        :root.dark .attachments-section h4 {
            color: #f1f5f9 !important;
        }

        :root.dark .attachment-item {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .attachment-item:hover {
            background: #334155 !important;
        }

        :root.dark .attachment-icon {
            background: #a78bfa !important;
        }

        :root.dark .breadcrumb-item a {
            color: rgba(255, 255, 255, 0.9) !important;
        }

        :root.dark .breadcrumb-item.active {
            color: rgba(255, 255, 255, 0.5) !important;
        }

        :root.dark .featured-image.full-mode {
            background: #0f172a !important;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php'; ?>

<!-- Post Header -->
<div class="post-header">
    <div class="post-container">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb" style="background: none; padding: 0; margin: 0;">
                <li class="breadcrumb-item"><a href="/" class="text-white">Home</a></li>
                <li class="breadcrumb-item"><a href="/blog/posts.php" class="text-white">Blog</a></li>
                <?php if ($post['category_name']): ?>
                    <li class="breadcrumb-item">
                        <a href="/blog/posts.php?category=<?= urlencode($post['category_slug']) ?>" class="text-white">
                            <?= htmlspecialchars($post['category_name']) ?>
                        </a>
                    </li>
                <?php endif; ?>
                <li class="breadcrumb-item active text-white-50"><?= htmlspecialchars($post['title']) ?></li>
            </ol>
        </nav>
        
        <?php if ($post['is_featured']): ?>
            <div class="featured-badge mb-3">
                <i class="bi bi-star-fill"></i> Featured Post
            </div>
        <?php endif; ?>
        
        <h1 class="post-title"><?= htmlspecialchars($post['title']) ?></h1>
        
        <div class="post-meta">
            <span><i class="bi bi-person"></i> <?= htmlspecialchars($post['author_name']) ?></span>
            <span><i class="bi bi-calendar"></i> <?= date('F j, Y', strtotime($post['published_at'])) ?></span>
            <?php if ($post['category_name']): ?>
                <span>
                    <i class="bi bi-tag"></i> 
                    <a href="/blog/posts.php?category=<?= urlencode($post['category_slug']) ?>" class="text-white text-decoration-none">
                        <?= htmlspecialchars($post['category_name']) ?>
                    </a>
                </span>
            <?php endif; ?>
            <span><i class="bi bi-eye"></i> <?= number_format($post['view_count']) ?> views</span>
            <span><i class="bi bi-clock"></i> <?= ceil(str_word_count(strip_tags($post['content'])) / 200) ?> min read</span>
        </div>
    </div>
</div>

<div class="post-container">
    <!-- Main Content -->
    <article class="main-content">
        <?php if ($post['featured_image']): ?>
            <?php
            $zoom_level = $post['image_zoom'] ?? 100;
            $image_style = $zoom_level != 100 ? "transform: scale(" . ($zoom_level / 100) . "); transform-origin: center;" : "";
            ?>
            <div class="featured-image-container" style="overflow: hidden; border-radius: 12px 12px 0 0;">
                <img src="<?= htmlspecialchars($post['featured_image']) ?>"
                     alt="<?= htmlspecialchars($post['title']) ?>"
                     class="featured-image <?= ($post['image_display_full'] ?? 0) ? 'full-mode' : 'crop-mode' ?>"
                     style="<?= $image_style ?>">
            </div>
        <?php endif; ?>
        
        <div class="post-content">
            <?= $post['content'] ?>
            
            <!-- Tags -->
            <?php if ($post['tags']): ?>
                <div class="post-tags">
                    <?php foreach (explode(', ', $post['tags']) as $tag): ?>
                        <a href="/blog/posts.php?tag=<?= urlencode(trim($tag)) ?>" class="tag">
                            #<?= htmlspecialchars(trim($tag)) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Attachments -->
            <?php if (!empty($attachments)): ?>
                <div class="attachments-section">
                    <h4><i class="bi bi-paperclip"></i> Attachments</h4>
                    <?php foreach ($attachments as $attachment): ?>
                        <a href="<?= htmlspecialchars($attachment['file_path']) ?>" 
                           class="attachment-item" 
                           target="_blank"
                           download="<?= htmlspecialchars($attachment['original_filename']) ?>">
                            <div class="attachment-icon">
                                <i class="bi bi-<?= getAttachmentIcon($attachment['attachment_type']) ?>"></i>
                            </div>
                            <div>
                                <div class="fw-bold"><?= htmlspecialchars($attachment['original_filename']) ?></div>
                                <small class="text-muted">
                                    <?= strtoupper($attachment['attachment_type']) ?> • 
                                    <?= formatFileSize($attachment['file_size']) ?>
                                </small>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Share Buttons -->
            <div class="share-buttons">
                <a href="https://twitter.com/intent/tweet?text=<?= urlencode($post['title']) ?>&url=<?= urlencode($canonical_url) ?>" 
                   class="share-btn share-twitter" target="_blank">
                    <i class="bi bi-twitter"></i> Twitter
                </a>
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($canonical_url) ?>" 
                   class="share-btn share-facebook" target="_blank">
                    <i class="bi bi-facebook"></i> Facebook
                </a>
                <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= urlencode($canonical_url) ?>" 
                   class="share-btn share-linkedin" target="_blank">
                    <i class="bi bi-linkedin"></i> LinkedIn
                </a>
                <a href="mailto:?subject=<?= urlencode($post['title']) ?>&body=<?= urlencode($canonical_url) ?>" 
                   class="share-btn share-email">
                    <i class="bi bi-envelope"></i> Email
                </a>
            </div>
        </div>
    </article>
    
    <!-- Post Navigation -->
    <?php if ($previous_post || $next_post): ?>
        <div class="post-navigation">
            <?php if ($previous_post): ?>
                <a href="/blog/post.php?slug=<?= htmlspecialchars($previous_post['slug']) ?>" class="nav-post">
                    <div class="nav-post-label">
                        <i class="bi bi-chevron-left"></i> Previous Post
                    </div>
                    <div class="nav-post-title"><?= htmlspecialchars($previous_post['title']) ?></div>
                </a>
            <?php else: ?>
                <div></div>
            <?php endif; ?>
            
            <?php if ($next_post): ?>
                <a href="/blog/post.php?slug=<?= htmlspecialchars($next_post['slug']) ?>" class="nav-post text-end">
                    <div class="nav-post-label">
                        Next Post <i class="bi bi-chevron-right"></i>
                    </div>
                    <div class="nav-post-title"><?= htmlspecialchars($next_post['title']) ?></div>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- Related Posts -->
    <?php if (!empty($related_posts)): ?>
        <div class="related-posts">
            <h3><i class="bi bi-collection"></i> Related Posts</h3>
            <?php foreach ($related_posts as $related): ?>
                <a href="/blog/post.php?slug=<?= htmlspecialchars($related['slug']) ?>" class="related-post">
                    <div class="related-post-image">
                        <?php if ($related['featured_image']): ?>
                            <img src="<?= htmlspecialchars($related['featured_image']) ?>" 
                                 alt="<?= htmlspecialchars($related['title']) ?>">
                        <?php else: ?>
                            <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                                <i class="bi bi-file-text"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="related-post-content">
                        <h4><?= htmlspecialchars($related['title']) ?></h4>
                        <div class="related-post-meta">
                            <?= date('M j, Y', strtotime($related['published_at'])) ?> • 
                            <?= number_format($related['view_count']) ?> views
                        </div>
                        <?php if ($related['excerpt']): ?>
                            <p class="mb-0 mt-2"><?= htmlspecialchars(substr($related['excerpt'], 0, 100)) ?>...</p>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Smooth scrolling for internal links
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

// Copy link functionality
function copyLink() {
    navigator.clipboard.writeText(window.location.href).then(function() {
        // Show temporary notification
        const notification = document.createElement('div');
        notification.className = 'alert alert-success position-fixed';
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999;';
        notification.innerHTML = '<i class="bi bi-check-circle"></i> Link copied to clipboard!';
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.remove();
        }, 3000);
    });
}

// Reading progress indicator
window.addEventListener('scroll', function() {
    const article = document.querySelector('.post-content');
    if (!article) return;
    
    const articleTop = article.offsetTop;
    const articleHeight = article.offsetHeight;
    const windowHeight = window.innerHeight;
    const scrollTop = window.pageYOffset;
    
    const progress = Math.min(
        Math.max((scrollTop - articleTop + windowHeight * 0.3) / articleHeight, 0),
        1
    );
    
    // You could add a progress bar here
});

// Image zoom functionality
document.querySelectorAll('.post-content img').forEach(img => {
    img.style.cursor = 'zoom-in';
    img.addEventListener('click', function() {
        if (this.style.transform === 'scale(1.5)') {
            this.style.transform = 'scale(1)';
            this.style.cursor = 'zoom-in';
        } else {
            this.style.transform = 'scale(1.5)';
            this.style.cursor = 'zoom-out';
        }
    });
});
</script>

<!-- Analytics Tracking -->
<script src="/analytics/track.js" async defer></script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>

</body>
</html>

<?php
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

function getAttachmentIcon($type) {
    switch ($type) {
        case 'image': return 'image';
        case 'document': return 'file-text';
        case 'video': return 'play-circle';
        case 'audio': return 'music-note';
        default: return 'file-earmark';
    }
}
?>