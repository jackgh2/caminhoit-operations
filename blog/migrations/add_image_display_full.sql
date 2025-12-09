-- Add image_display_full and image_zoom columns to blog_posts table
-- This allows posts to control how the featured image is displayed

ALTER TABLE blog_posts
ADD COLUMN image_display_full TINYINT(1) DEFAULT 0 AFTER featured_image,
ADD COLUMN image_zoom INT DEFAULT 100 AFTER image_display_full;

-- Update existing posts to use crop mode and default zoom (default behavior)
UPDATE blog_posts SET image_display_full = 0 WHERE image_display_full IS NULL;
UPDATE blog_posts SET image_zoom = 100 WHERE image_zoom IS NULL;

-- Add comments for documentation
ALTER TABLE blog_posts
MODIFY COLUMN image_display_full TINYINT(1) DEFAULT 0 COMMENT 'Show full image (1) or crop to fit (0)',
MODIFY COLUMN image_zoom INT DEFAULT 100 COMMENT 'Image zoom level 50-200 (percentage)';
