<?php
/**
 * Sitemap Index
 * Points to all individual sitemaps for better organization
 */

header('Content-Type: application/xml; charset=utf-8');

$baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'caminhoit.com');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <!-- Main sitemap with all pages -->
    <sitemap>
        <loc><?= htmlspecialchars($baseUrl . '/sitemap.xml.php') ?></loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
    </sitemap>

    <!-- Blog sitemap (if exists) -->
    <sitemap>
        <loc><?= htmlspecialchars($baseUrl . '/blog/sitemap.xml.php') ?></loc>
        <lastmod><?= date('Y-m-d') ?></lastmod>
    </sitemap>
</sitemapindex>
