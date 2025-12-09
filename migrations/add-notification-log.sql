-- Create notification log table
-- For tracking sent Discord notifications to avoid duplicates

CREATE TABLE IF NOT EXISTS notification_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL COMMENT 'order, subscription, invoice, etc',
    entity_id INT NOT NULL COMMENT 'ID of the entity',
    notification_type VARCHAR(50) NOT NULL COMMENT 'new_order, expiring, low_inventory, etc',
    channel VARCHAR(20) NOT NULL COMMENT 'discord, email, sms',
    status ENUM('sent', 'failed', 'queued') DEFAULT 'sent',
    meta_data JSON DEFAULT NULL COMMENT 'Additional data about the notification',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_type (notification_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Log of sent notifications';
