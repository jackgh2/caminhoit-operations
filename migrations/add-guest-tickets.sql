-- Guest Tickets System
-- Allows tickets to be created from emails without user accounts

-- Table to store guest ticket access tokens
CREATE TABLE IF NOT EXISTS `support_guest_tickets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` INT NOT NULL,
    `guest_email` VARCHAR(255) NOT NULL,
    `guest_name` VARCHAR(255),
    `access_token` VARCHAR(64) NOT NULL UNIQUE,
    `token_expires_at` DATETIME NOT NULL,
    `last_accessed_at` DATETIME,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE CASCADE,
    INDEX idx_access_token (access_token),
    INDEX idx_guest_email (guest_email),
    INDEX idx_ticket_id (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add a flag to support_tickets to mark guest tickets
ALTER TABLE `support_tickets`
ADD COLUMN `is_guest_ticket` TINYINT(1) DEFAULT 0 AFTER `visibility_scope`,
ADD COLUMN `guest_email` VARCHAR(255) AFTER `is_guest_ticket`,
ADD INDEX idx_is_guest (is_guest_ticket),
ADD INDEX idx_guest_email (guest_email);

-- Update existing tickets to not be guest tickets
UPDATE `support_tickets` SET `is_guest_ticket` = 0 WHERE `is_guest_ticket` IS NULL;
