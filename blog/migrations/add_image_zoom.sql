-- Add image_zoom column to blog_posts table
-- This allows posts to control the zoom level of featured images

ALTER TABLE blog_posts
ADD COLUMN image_zoom INT DEFAULT 100 AFTER image_display_full;

-- Update existing posts to use default zoom
UPDATE blog_posts SET image_zoom = 100 WHERE image_zoom IS NULL;

-- Add comment for documentation
ALTER TABLE blog_posts
MODIFY COLUMN image_zoom INT DEFAULT 100 COMMENT 'Image zoom level 50-200 (percentage)';
