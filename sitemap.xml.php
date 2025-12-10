<?php
/**
 * Comprehensive Dynamic Sitemap Generator
 * Automatically generates sitemap.xml for search engines
 * Includes: Static pages, Blog posts, Knowledge base articles, Categories
 */

header('Content-Type: application/xml; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

$baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'caminhoit.com');

// Define your static pages with priority and change frequency
$pages = [
    // Main pages
    ['url' => '/', 'priority' => '1.0', 'changefreq' => 'daily', 'lastmod' => date('Y-m-d')],
    ['url' => '/about.php', 'priority' => '0.9', 'changefreq' => 'monthly'],
    ['url' => '/services.php', 'priority' => '0.9', 'changefreq' => 'weekly'],
    ['url' => '/pricing.php', 'priority' => '0.8', 'changefreq' => 'weekly'],
    ['url' => '/contact.php', 'priority' => '0.8', 'changefreq' => 'monthly'],
    ['url' => '/book.php', 'priority' => '0.9', 'changefreq' => 'weekly', 'lastmod' => date('Y-m-d')],
    ['url' => '/locations.php', 'priority' => '0.9', 'changefreq' => 'monthly', 'lastmod' => date('Y-m-d')],
    ['url' => '/sustainability.php', 'priority' => '0.8', 'changefreq' => 'monthly'],

    // Service Pages (High priority for conversions)
    ['url' => '/cloud-services.php', 'priority' => '0.9', 'changefreq' => 'weekly'],
    ['url' => '/managed-it-support.php', 'priority' => '0.9', 'changefreq' => 'weekly'],
    ['url' => '/business-hardware.php', 'priority' => '0.8', 'changefreq' => 'weekly'],
    ['url' => '/consultancy.php', 'priority' => '0.8', 'changefreq' => 'weekly'],
    ['url' => '/it-strategy.php', 'priority' => '0.8', 'changefreq' => 'weekly'],
    ['url' => '/compliance.php', 'priority' => '0.7', 'changefreq' => 'monthly'],
    ['url' => '/gdpr.php', 'priority' => '0.7', 'changefreq' => 'monthly'],

    // Blog & Knowledge Base sections
    ['url' => '/blog/', 'priority' => '0.9', 'changefreq' => 'daily'],
    ['url' => '/kb/', 'priority' => '0.9', 'changefreq' => 'weekly'],

    // Auth pages (Lower priority - not for search)
    ['url' => '/login.php', 'priority' => '0.3', 'changefreq' => 'yearly'],
    ['url' => '/register.php', 'priority' => '0.3', 'changefreq' => 'yearly'],

    // SEO Example
    ['url' => '/seo-example.php', 'priority' => '0.6', 'changefreq' => 'monthly'],
];

// Fetch all published blog posts
$blogPosts = [];
try {
    $stmt = $pdo->query("
        SELECT id, slug, updated_at
        FROM blog_posts
        WHERE status = 'published'
        ORDER BY updated_at DESC
    ");
    $blogPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Sitemap: Error fetching blog posts: " . $e->getMessage());
}

// Fetch all published knowledge base articles
$kbArticles = [];
try {
    $stmt = $pdo->query("
        SELECT id, slug, updated_at
        FROM kb_articles
        WHERE status = 'published'
        ORDER BY updated_at DESC
    ");
    $kbArticles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Sitemap: Error fetching KB articles: " . $e->getMessage());
}

// Fetch all KB categories
$kbCategories = [];
try {
    $stmt = $pdo->query("
        SELECT id, slug
        FROM kb_categories
        WHERE active = 1
        ORDER BY name ASC
    ");
    $kbCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Sitemap: Error fetching KB categories: " . $e->getMessage());
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
        xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9
        http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">

<!-- Static Pages -->
<?php foreach ($pages as $page): ?>
    <url>
        <loc><?= htmlspecialchars($baseUrl . $page['url']) ?></loc>
        <?php if (isset($page['lastmod'])): ?>
        <lastmod><?= $page['lastmod'] ?></lastmod>
        <?php endif; ?>
        <changefreq><?= $page['changefreq'] ?></changefreq>
        <priority><?= $page['priority'] ?></priority>
    </url>
<?php endforeach; ?>

<!-- Blog Posts -->
<?php foreach ($blogPosts as $post): ?>
    <url>
        <loc><?= htmlspecialchars($baseUrl . '/blog/post.php?slug=' . $post['slug']) ?></loc>
        <lastmod><?= date('Y-m-d', strtotime($post['updated_at'])) ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.7</priority>
    </url>
<?php endforeach; ?>

<!-- Knowledge Base Articles -->
<?php foreach ($kbArticles as $article): ?>
    <url>
        <loc><?= htmlspecialchars($baseUrl . '/kb/article.php?slug=' . $article['slug']) ?></loc>
        <lastmod><?= date('Y-m-d', strtotime($article['updated_at'])) ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.8</priority>
    </url>
<?php endforeach; ?>

<!-- Knowledge Base Categories -->
<?php foreach ($kbCategories as $category): ?>
    <url>
        <loc><?= htmlspecialchars($baseUrl . '/kb/category.php?slug=' . $category['slug']) ?></loc>
        <changefreq>weekly</changefreq>
        <priority>0.6</priority>
    </url>
<?php endforeach; ?>

</urlset>
