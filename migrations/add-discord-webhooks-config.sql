-- Add Discord webhook configuration for orders and subscriptions
-- Separate webhooks allow posting to different Discord channels

INSERT IGNORE INTO system_config (config_key, config_value, config_type, category, description, updated_by)
VALUES
-- Orders webhook
('discord.orders_webhook_url', '', 'string', 'notifications', 'Discord Webhook URL for order notifications', 1),
('discord.orders_enabled', '0', 'boolean', 'notifications', 'Enable Discord notifications for orders', 1),

-- Subscriptions webhook
('discord.subscriptions_webhook_url', '', 'string', 'notifications', 'Discord Webhook URL for subscription notifications', 1),
('discord.subscriptions_enabled', '0', 'boolean', 'notifications', 'Enable Discord notifications for subscriptions', 1),

-- Global Discord settings
('discord.notifications_enabled', '1', 'boolean', 'notifications', 'Master switch for all Discord notifications', 1);
