-- Blog Database Schema Creation
-- Created: 2025-07-21
-- Purpose: Complete blogging system with categories, posts, attachments, scheduling, and revisions

-- ============================================================================
-- 1. BLOG CATEGORIES TABLE
-- ============================================================================
CREATE TABLE `blog_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `parent_id` (`parent_id`),
  KEY `is_active` (`is_active`),
  KEY `sort_order` (`sort_order`),
  CONSTRAINT `blog_categories_parent_fk` FOREIGN KEY (`parent_id`) REFERENCES `blog_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. BLOG POSTS TABLE
-- ============================================================================
CREATE TABLE `blog_posts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `excerpt` text DEFAULT NULL,
  `author_id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `featured_image` varchar(255) DEFAULT NULL,
  `status` enum('draft','published','scheduled','archived','trash') DEFAULT 'draft',
  `published_at` datetime DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `meta_keywords` text DEFAULT NULL,
  `view_count` int(11) DEFAULT 0,
  `is_featured` tinyint(1) DEFAULT 0,
  `allow_comments` tinyint(1) DEFAULT 1,
  `comment_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `author_id` (`author_id`),
  KEY `category_id` (`category_id`),
  KEY `status` (`status`),
  KEY `published_at` (`published_at`),
  KEY `scheduled_at` (`scheduled_at`),
  KEY `is_featured` (`is_featured`),
  KEY `created_at` (`created_at`),
  FULLTEXT KEY `search_content` (`title`,`content`,`excerpt`),
  CONSTRAINT `blog_posts_author_fk` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `blog_posts_category_fk` FOREIGN KEY (`category_id`) REFERENCES `blog_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. BLOG POST ATTACHMENTS TABLE
-- ============================================================================
CREATE TABLE `blog_post_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `post_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint(20) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `attachment_type` enum('image','document','video','audio','other') DEFAULT 'other',
  `alt_text` varchar(255) DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_featured` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `uploaded_by` int(11) NOT NULL,
  `download_count` int(11) DEFAULT 0,
  `is_public` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `attachment_type` (`attachment_type`),
  KEY `is_featured` (`is_featured`),
  KEY `sort_order` (`sort_order`),
  CONSTRAINT `blog_attachments_post_fk` FOREIGN KEY (`post_id`) REFERENCES `blog_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `blog_attachments_user_fk` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. BLOG POST TAGS TABLE
-- ============================================================================
CREATE TABLE `blog_post_tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `post_id` int(11) NOT NULL,
  `tag_name` varchar(100) NOT NULL,
  `tag_slug` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`),
  KEY `tag_slug` (`tag_slug`),
  KEY `tag_name` (`tag_name`),
  CONSTRAINT `blog_tags_post_fk` FOREIGN KEY (`post_id`) REFERENCES `blog_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. BLOG POST REVISIONS TABLE
-- ============================================================================
CREATE TABLE `blog_post_revisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `post_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `excerpt` text DEFAULT NULL,
  `revision_note` text DEFAULT NULL,
  `revision_type` enum('auto','manual','scheduled') DEFAULT 'manual',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`),
  KEY `created_by` (`created_by`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `blog_revisions_post_fk` FOREIGN KEY (`post_id`) REFERENCES `blog_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `blog_revisions_user_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. BLOG SETTINGS TABLE
-- ============================================================================
CREATE TABLE `blog_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` longtext DEFAULT NULL,
  `setting_type` enum('string','number','boolean','json','text') DEFAULT 'string',
  `description` text DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 0,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `blog_settings_user_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7. BLOG MEDIA LIBRARY TABLE (for reusable attachments)
-- ============================================================================
CREATE TABLE `blog_media_library` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint(20) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `media_type` enum('image','document','video','audio','other') DEFAULT 'other',
  `alt_text` varchar(255) DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `folder` varchar(255) DEFAULT 'uploads',
  `uploaded_by` int(11) NOT NULL,
  `usage_count` int(11) DEFAULT 0,
  `is_public` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `media_type` (`media_type`),
  KEY `folder` (`folder`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `blog_media_user_fk` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INSERT DEFAULT BLOG SETTINGS
-- ============================================================================
INSERT INTO `blog_settings` (`setting_key`, `setting_value`, `setting_type`, `description`, `is_public`) VALUES
('blog_title', 'My Blog', 'string', 'Main blog title', 1),
('blog_description', 'Welcome to my blog', 'text', 'Blog description/tagline', 1),
('posts_per_page', '10', 'number', 'Number of posts per page', 0),
('allow_comments', '1', 'boolean', 'Allow comments on posts', 0),
('moderate_comments', '1', 'boolean', 'Moderate comments before publishing', 0),
('tinymce_api_key', '', 'string', 'TinyMCE API Key', 0),
('upload_max_size', '10485760', 'number', 'Maximum upload size in bytes (10MB)', 0),
('allowed_file_types', 'jpg,jpeg,png,gif,pdf,doc,docx,zip', 'string', 'Allowed file upload types', 0),
('auto_publish_scheduled', '1', 'boolean', 'Auto-publish scheduled posts', 0),
('seo_enabled', '1', 'boolean', 'Enable SEO features', 0);

-- ============================================================================
-- INSERT DEFAULT CATEGORIES
-- ============================================================================
INSERT INTO `blog_categories` (`name`, `slug`, `description`, `sort_order`) VALUES
('General', 'general', 'General blog posts and updates', 1),
('Technology', 'technology', 'Technology related articles', 2),
('Tutorials', 'tutorials', 'Step-by-step tutorials and guides', 3),
('News', 'news', 'Latest news and announcements', 4);

-- ============================================================================
-- CREATE INDEXES FOR PERFORMANCE
-- ============================================================================

-- Additional indexes for blog_posts
ALTER TABLE `blog_posts` ADD INDEX `status_published_date` (`status`, `published_at`);
ALTER TABLE `blog_posts` ADD INDEX `category_status` (`category_id`, `status`);
ALTER TABLE `blog_posts` ADD INDEX `author_status` (`author_id`, `status`);

-- Additional indexes for blog_post_tags  
ALTER TABLE `blog_post_tags` ADD INDEX `tag_post_lookup` (`tag_slug`, `post_id`);

-- Additional indexes for blog_post_attachments
ALTER TABLE `blog_post_attachments` ADD INDEX `post_type_order` (`post_id`, `attachment_type`, `sort_order`);

-- ============================================================================
-- CREATE VIEWS FOR COMMON QUERIES
-- ============================================================================

-- View for published posts with category and author info
CREATE VIEW `published_posts_view` AS
SELECT 
    p.id,
    p.title,
    p.slug,
    p.excerpt,
    p.published_at,
    p.view_count,
    p.is_featured,
    p.featured_image,
    c.name as category_name,
    c.slug as category_slug,
    u.username as author_name,
    u.email as author_email,
    (SELECT COUNT(*) FROM blog_post_attachments WHERE post_id = p.id) as attachment_count,
    (SELECT GROUP_CONCAT(tag_name) FROM blog_post_tags WHERE post_id = p.id) as tags
FROM blog_posts p
LEFT JOIN blog_categories c ON p.category_id = c.id
LEFT JOIN users u ON p.author_id = u.id
WHERE p.status = 'published' 
AND (p.published_at IS NULL OR p.published_at <= NOW());

-- View for scheduled posts that need to be published
CREATE VIEW `scheduled_posts_view` AS
SELECT 
    p.id,
    p.title,
    p.scheduled_at,
    p.author_id,
    u.username as author_name
FROM blog_posts p
LEFT JOIN users u ON p.author_id = u.id
WHERE p.status = 'scheduled' 
AND p.scheduled_at IS NOT NULL 
AND p.scheduled_at <= NOW();

-- ============================================================================
-- CREATE TRIGGERS FOR AUTOMATION
-- ============================================================================

-- Trigger to update post slug when title changes
DELIMITER $$
CREATE TRIGGER `blog_posts_slug_update` 
BEFORE UPDATE ON `blog_posts`
FOR EACH ROW 
BEGIN
    IF NEW.title != OLD.title AND (NEW.slug = OLD.slug OR NEW.slug = '') THEN
        SET NEW.slug = LOWER(REPLACE(REPLACE(REPLACE(NEW.title, ' ', '-'), '.', ''), ',', ''));
    END IF;
END$$
DELIMITER ;

-- Trigger to set published_at when status changes to published
DELIMITER $$
CREATE TRIGGER `blog_posts_publish_date` 
BEFORE UPDATE ON `blog_posts`
FOR EACH ROW 
BEGIN
    IF NEW.status = 'published' AND OLD.status != 'published' AND NEW.published_at IS NULL THEN
        SET NEW.published_at = NOW();
    END IF;
END$$
DELIMITER ;

-- Trigger to create automatic revision on post update
DELIMITER $$
CREATE TRIGGER `blog_posts_auto_revision` 
AFTER UPDATE ON `blog_posts`
FOR EACH ROW 
BEGIN
    IF NEW.content != OLD.content OR NEW.title != OLD.title THEN
        INSERT INTO blog_post_revisions (post_id, title, content, excerpt, revision_type, created_by)
        VALUES (NEW.id, OLD.title, OLD.content, OLD.excerpt, 'auto', NEW.author_id);
    END IF;
END$$
DELIMITER ;

-- ============================================================================
-- STORED PROCEDURES FOR COMMON OPERATIONS
-- ============================================================================

-- Procedure to publish scheduled posts
DELIMITER $$
CREATE PROCEDURE `PublishScheduledPosts`()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE post_id INT;
    DECLARE cur CURSOR FOR 
        SELECT id FROM blog_posts 
        WHERE status = 'scheduled' 
        AND scheduled_at IS NOT NULL 
        AND scheduled_at <= NOW();
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO post_id;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        UPDATE blog_posts 
        SET status = 'published', published_at = NOW() 
        WHERE id = post_id;
    END LOOP;
    CLOSE cur;
    
    SELECT ROW_COUNT() as posts_published;
END$$
DELIMITER ;

-- Procedure to get post statistics
DELIMITER $$
CREATE PROCEDURE `GetBlogStats`()
BEGIN
    SELECT 
        (SELECT COUNT(*) FROM blog_posts WHERE status = 'published') as published_posts,
        (SELECT COUNT(*) FROM blog_posts WHERE status = 'draft') as draft_posts,
        (SELECT COUNT(*) FROM blog_posts WHERE status = 'scheduled') as scheduled_posts,
        (SELECT COUNT(*) FROM blog_categories WHERE is_active = 1) as active_categories,
        (SELECT COUNT(*) FROM blog_post_attachments) as total_attachments,
        (SELECT SUM(file_size) FROM blog_post_attachments) as total_file_size,
        (SELECT COUNT(DISTINCT tag_name) FROM blog_post_tags) as unique_tags;
END$$
DELIMITER ;

-- ============================================================================
-- FINAL NOTES
-- ============================================================================
-- 
-- This schema provides:
-- 1. Complete blog structure with categories, posts, and attachments
-- 2. Revision tracking for post changes
-- 3. Scheduled publishing capability
-- 4. Media library for file management
-- 5. SEO optimization fields
-- 6. Performance optimized with proper indexes
-- 7. Automated triggers for common tasks
-- 8. Stored procedures for maintenance
-- 9. Views for common queries
-- 10. Flexible settings system
--
-- Next steps:
-- 1. Run this schema on your database
-- 2. Create admin interface files
-- 3. Set up TinyMCE integration
-- 4. Create cron job for scheduled publishing
-- ============================================================================