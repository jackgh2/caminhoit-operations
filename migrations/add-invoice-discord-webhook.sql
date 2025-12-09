-- Add Discord webhook configuration for invoices
-- Run this migration to enable Discord notifications for invoice events

-- Add invoice webhook settings to system_config
INSERT INTO system_config (config_key, config_value, config_type, category, description, updated_by)
VALUES
('discord.invoices_enabled', '0', 'boolean', 'discord', 'Enable Discord notifications for invoices', 1),
('discord.invoices_webhook_url', '', 'string', 'discord', 'Discord webhook URL for invoice notifications', 1)
ON DUPLICATE KEY UPDATE
    config_value = VALUES(config_value),
    description = VALUES(description);

-- Add subscription_id column to invoices table if it doesn't exist
-- This links renewal invoices back to their subscriptions

-- Check if column exists, if not add it
SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'invoices'
    AND COLUMN_NAME = 'subscription_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE invoices ADD COLUMN subscription_id INT DEFAULT NULL AFTER order_id',
    'SELECT "Column subscription_id already exists" AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key if it doesn't exist
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'invoices'
    AND CONSTRAINT_NAME = 'fk_invoices_subscription'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE invoices ADD CONSTRAINT fk_invoices_subscription FOREIGN KEY (subscription_id) REFERENCES client_subscriptions(id) ON DELETE SET NULL',
    'SELECT "Foreign key fk_invoices_subscription already exists" AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add indexes if they don't exist

-- Index 1: idx_invoices_subscription_id
SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'invoices'
    AND INDEX_NAME = 'idx_invoices_subscription_id'
);

SET @sql = IF(@index_exists = 0,
    'CREATE INDEX idx_invoices_subscription_id ON invoices(subscription_id)',
    'SELECT "Index idx_invoices_subscription_id already exists" AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index 2: idx_invoices_status
SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'invoices'
    AND INDEX_NAME = 'idx_invoices_status'
);

SET @sql = IF(@index_exists = 0,
    'CREATE INDEX idx_invoices_status ON invoices(status)',
    'SELECT "Index idx_invoices_status already exists" AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index 3: idx_subscriptions_next_billing
SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'client_subscriptions'
    AND INDEX_NAME = 'idx_subscriptions_next_billing'
);

SET @sql = IF(@index_exists = 0,
    'CREATE INDEX idx_subscriptions_next_billing ON client_subscriptions(next_billing_date, status)',
    'SELECT "Index idx_subscriptions_next_billing already exists" AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Show current configuration
SELECT config_key, config_value, description
FROM system_config
WHERE config_key LIKE 'discord.%'
ORDER BY config_key;
