-- Migration: Add support for ticket notifications, email import, and Discord webhooks
-- Date: 2025-11-06
--
-- IMPORTANT: If you get errors about "Duplicate column name" when running this,
-- that's okay! It means those columns already exist. Just ignore those errors.

-- Table for initial ticket attachments
CREATE TABLE IF NOT EXISTS `support_ticket_attachments_initial` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` INT NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255),
    `uploaded_at` DATETIME NOT NULL,
    FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE CASCADE,
    INDEX idx_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for email notification settings
CREATE TABLE IF NOT EXISTS `support_email_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) UNIQUE NOT NULL,
    `setting_value` TEXT,
    `description` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default email settings
INSERT INTO `support_email_settings` (`setting_key`, `setting_value`, `description`) VALUES
('smtp_host', 'localhost', 'SMTP server host'),
('smtp_port', '587', 'SMTP server port'),
('smtp_username', '', 'SMTP username (email address)'),
('smtp_password', '', 'SMTP password'),
('smtp_encryption', 'tls', 'SMTP encryption (tls, ssl, or none)'),
('smtp_from_email', 'support@caminhoit.com', 'From email address'),
('smtp_from_name', 'CaminhoIT Support', 'From name'),
('notify_staff_on_new_ticket', '1', 'Send email to staff when new ticket is created'),
('notify_customer_on_ticket_created', '1', 'Send confirmation email to customer when ticket is created'),
('notify_customer_on_staff_reply', '1', 'Send email to customer when staff replies'),
('notify_staff_on_customer_reply', '1', 'Send email to assigned staff when customer replies'),
('notify_customer_on_ticket_closed', '1', 'Send email to customer when ticket is closed with feedback request'),
('staff_notification_emails', 'support@caminhoit.com', 'Comma-separated list of staff emails for new ticket notifications'),
('discord_webhook_enabled', '0', 'Enable Discord webhook notifications'),
('discord_webhook_url', '', 'Discord webhook URL'),
('discord_notify_on_new_ticket', '1', 'Send Discord notification on new ticket'),
('discord_notify_on_ticket_reply', '1', 'Send Discord notification on ticket reply')
ON DUPLICATE KEY UPDATE setting_value=setting_value;

-- Table for tracking email imports
CREATE TABLE IF NOT EXISTS `support_email_imports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email_uid` VARCHAR(255) NOT NULL,
    `email_from` VARCHAR(255) NOT NULL,
    `email_subject` VARCHAR(500),
    `email_date` DATETIME,
    `ticket_id` INT,
    `reply_id` INT,
    `import_type` ENUM('new_ticket', 'reply') DEFAULT 'reply',
    `raw_message_id` VARCHAR(255),
    `processed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_email_uid (email_uid),
    FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`reply_id`) REFERENCES `support_ticket_replies`(`id`) ON DELETE SET NULL,
    INDEX idx_ticket (ticket_id),
    INDEX idx_email_from (email_from),
    INDEX idx_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for POP3/IMAP settings
CREATE TABLE IF NOT EXISTS `support_email_import_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) UNIQUE NOT NULL,
    `setting_value` TEXT,
    `description` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default email import settings
INSERT INTO `support_email_import_settings` (`setting_key`, `setting_value`, `description`) VALUES
('import_enabled', '0', 'Enable email import'),
('import_protocol', 'pop3', 'Email protocol (pop3 or imap)'),
('import_host', '', 'Email server host'),
('import_port', '995', 'Email server port (995 for POP3 SSL, 993 for IMAP SSL)'),
('import_username', '', 'Email username'),
('import_password', '', 'Email password'),
('import_encryption', 'ssl', 'Encryption type (ssl, tls, or none)'),
('import_delete_after_import', '0', 'Delete emails from server after import'),
('import_default_group_id', '', 'Default ticket group for imported emails'),
('import_allowed_domains', '', 'Comma-separated list of allowed email domains (empty = all)'),
('import_frequency_minutes', '15', 'How often to check for new emails (in minutes)')
ON DUPLICATE KEY UPDATE setting_value=setting_value;

-- Table for notification logs
CREATE TABLE IF NOT EXISTS `support_notification_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` INT NOT NULL,
    `notification_type` ENUM('email', 'discord') NOT NULL,
    `recipient` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(500),
    `status` ENUM('sent', 'failed') NOT NULL,
    `error_message` TEXT,
    `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE CASCADE,
    INDEX idx_ticket (ticket_id),
    INDEX idx_status (status),
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ALTER TABLE statements below
-- Note: If you get "Duplicate column" errors, that's fine - columns already exist
-- ============================================================================

-- Add updated_by column to support_tickets
-- (Ignore error if column already exists)
ALTER TABLE `support_tickets`
ADD COLUMN `updated_by` INT NULL AFTER `updated_at`;

-- Add foreign key for updated_by
-- (Ignore error if foreign key already exists)
ALTER TABLE `support_tickets`
ADD FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- Add subject column if using title/description schema
-- (Ignore error if column already exists)
ALTER TABLE `support_tickets`
ADD COLUMN `subject` VARCHAR(500) NULL AFTER `id`;

-- Add details column if using title/description schema
-- (Ignore error if column already exists)
ALTER TABLE `support_tickets`
ADD COLUMN `details` TEXT NULL AFTER `subject`;

-- Migrate existing data from title/description to subject/details
-- This is safe to run multiple times
UPDATE `support_tickets`
SET `subject` = `title`
WHERE (`subject` IS NULL OR `subject` = '') AND `title` IS NOT NULL;

UPDATE `support_tickets`
SET `details` = `description`
WHERE (`details` IS NULL OR `details` = '') AND `description` IS NOT NULL;

-- Make subject NOT NULL if it was just created
-- (This may fail if subject already exists and is NOT NULL - that's ok)
ALTER TABLE `support_tickets`
MODIFY `subject` VARCHAR(500) NOT NULL;

-- Make details NOT NULL if it was just created
-- (This may fail if details already exists and is NOT NULL - that's ok)
ALTER TABLE `support_tickets`
MODIFY `details` TEXT NOT NULL;

-- Update status enum to include new statuses
-- This is safe to run multiple times - it will just update the enum
ALTER TABLE `support_tickets`
MODIFY `status` ENUM('Open', 'In Progress', 'Pending', 'Awaiting Response', 'Closed') DEFAULT 'Open';

-- Update priority enum to include 'Normal'
-- This is safe to run multiple times
ALTER TABLE `support_tickets`
MODIFY `priority` ENUM('Low', 'Normal', 'Medium', 'High', 'Urgent') DEFAULT 'Normal';

-- All done!
-- Check tables were created:
-- SHOW TABLES LIKE 'support_%';
