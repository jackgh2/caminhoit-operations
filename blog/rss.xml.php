<?php
header('Content-Type: application/rss+xml; charset=UTF-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Get blog settings
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM blog_settings");
$stmt->execute();
$blog_settings = [];
while ($row = $stmt->fetch()) {
    $blog_settings[$row['setting_key']] = $row['setting_value'];
}

// Check if RSS is enabled
if (($blog_settings['enable_rss'] ?? '1') !== '1') {
    http_response_code(404);
    exit('RSS feed is disabled');
}

$blog_title = $blog_settings['blog_title'] ?? 'Blog';
$blog_description = $blog_settings['blog_description'] ?? 'Latest blog posts';
$base_url = 'https://' . $_SERVER['HTTP_HOST'];

// Get recent posts
$stmt = $pdo->prepare("
    SELECT p.title, p.slug, p.content, p.excerpt, p.published_at,
           u.username as author_name, c.name as category_name
    FROM blog_posts p
    LEFT JOIN users u ON p.author_id = u.id
    LEFT JOIN blog_categories c ON p.category_id = c.id
    WHERE p.status = 'published' AND p.published_at <= NOW()
    ORDER BY p.published_at DESC
    LIMIT 20
");
$stmt->execute();
$posts = $stmt->fetchAll();

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title><?= htmlspecialchars($blog_title) ?></title>
    <link><?= $base_url ?>/blog/</link>
    <description><?= htmlspecialchars($blog_description) ?></description>
    <language>en-us</language>
    <lastBuildDate><?= date('r') ?></lastBuildDate>
    <atom:link href="<?= $base_url ?>/blog/rss.xml" rel="self" type="application/rss+xml" />
    
    <?php foreach ($posts as $post): ?>
    <item>
        <title><?= htmlspecialchars($post['title']) ?></title>
        <link><?= $base_url ?>/blog/post.php?slug=<?= urlencode($post['slug']) ?></link>
        <description><?= htmlspecialchars($post['excerpt'] ?: substr(strip_tags($post['content']), 0, 200)) ?></description>
        <content:encoded><![CDATA[<?= $post['content'] ?>]]></content:encoded>
        <author><?= htmlspecialchars($post['author_name']) ?></author>
        <?php if ($post['category_name']): ?>
        <category><?= htmlspecialchars($post['category_name']) ?></category>
        <?php endif; ?>
        <pubDate><?= date('r', strtotime($post['published_at'])) ?></pubDate>
        <guid><?= $base_url ?>/blog/post.php?slug=<?= urlencode($post['slug']) ?></guid>
    </item>
    <?php endforeach; ?>
</channel>
</rss>