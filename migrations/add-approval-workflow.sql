-- Approval Workflow and Discord Notifications
-- Run this migration to enable order approval queue

-- Add approval workflow columns to orders table
ALTER TABLE orders
ADD COLUMN approval_notes TEXT DEFAULT NULL COMMENT 'Staff notes when approving/rejecting' AFTER notes,
ADD COLUMN approved_by INT DEFAULT NULL COMMENT 'User ID of staff who approved' AFTER approval_notes,
ADD COLUMN approved_at DATETIME DEFAULT NULL COMMENT 'When order was approved/rejected' AFTER approved_by,
ADD COLUMN placed_at DATETIME DEFAULT NULL COMMENT 'When order was placed by customer' AFTER approved_at,
ADD INDEX idx_approved_by (approved_by);

-- Update order status to include new statuses
ALTER TABLE orders
MODIFY COLUMN status ENUM('draft', 'pending_approval', 'pending_payment', 'paid', 'completed', 'partially_completed', 'cancelled', 'rejected') DEFAULT 'draft'
COMMENT 'Order status workflow';

-- Add Discord configuration to system_config
INSERT IGNORE INTO system_config (config_key, config_value, config_type, category, description, updated_by)
VALUES
('discord_webhook_url', '', 'string', 'notifications', 'Discord Webhook URL for notifications', 1),
('discord_notifications_enabled', '0', 'boolean', 'notifications', 'Enable Discord notifications', 1);

-- Add foreign key for approved_by if users table exists (optional, uncomment if needed)
-- ALTER TABLE orders
-- ADD FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;
