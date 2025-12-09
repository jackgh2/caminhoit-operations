<?php
header('Content-Type: application/xml; charset=UTF-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

$base_url = 'https://' . $_SERVER['HTTP_HOST'];

// Get published posts
$stmt = $pdo->prepare("
    SELECT slug, updated_at, published_at
    FROM blog_posts 
    WHERE status = 'published' AND published_at <= NOW()
    ORDER BY published_at DESC
");
$stmt->execute();
$posts = $stmt->fetchAll();

// Get active categories
$stmt = $pdo->prepare("
    SELECT c.slug, c.updated_at
    FROM blog_categories c
    INNER JOIN blog_posts p ON c.id = p.category_id
    WHERE c.is_active = 1 AND p.status = 'published' AND p.published_at <= NOW()
    GROUP BY c.id, c.slug, c.updated_at
");
$stmt->execute();
$categories = $stmt->fetchAll();

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <!-- Blog homepage -->
    <url>
        <loc><?= $base_url ?>/blog/</loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>
    
    <!-- Blog posts page -->
    <url>
        <loc><?= $base_url ?>/blog/posts.php</loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>0.9</priority>
    </url>
    
    <!-- Individual posts -->
    <?php foreach ($posts as $post): ?>
    <url>
        <loc><?= $base_url ?>/blog/post.php?slug=<?= urlencode($post['slug']) ?></loc>
        <lastmod><?= date('Y-m-d', strtotime($post['updated_at'])) ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>
    <?php endforeach; ?>
    
    <!-- Category pages -->
    <?php foreach ($categories as $category): ?>
    <url>
        <loc><?= $base_url ?>/blog/posts.php?category=<?= urlencode($category['slug']) ?></loc>
        <lastmod><?= date('Y-m-d', strtotime($category['updated_at'])) ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url>
    <?php endforeach; ?>
</urlset>