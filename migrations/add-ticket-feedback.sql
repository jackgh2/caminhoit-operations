-- Migration: Add ticket feedback system
-- Date: 2025-11-06

-- Table for ticket feedback
CREATE TABLE IF NOT EXISTS `support_ticket_feedback` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `rating` TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    `feedback_text` TEXT,
    `helpful` ENUM('yes', 'no', 'neutral') DEFAULT 'neutral',
    `would_recommend` TINYINT(1) DEFAULT NULL,
    `response_time_rating` TINYINT CHECK (response_time_rating BETWEEN 1 AND 5),
    `resolution_quality_rating` TINYINT CHECK (resolution_quality_rating BETWEEN 1 AND 5),
    `staff_professionalism_rating` TINYINT CHECK (staff_professionalism_rating BETWEEN 1 AND 5),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX idx_ticket (ticket_id),
    INDEX idx_rating (rating),
    INDEX idx_created_at (created_at),
    UNIQUE KEY unique_ticket_feedback (ticket_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add notification setting for ticket closure
INSERT INTO `support_email_settings` (`setting_key`, `setting_value`, `description`) VALUES
('notify_customer_on_ticket_closed', '1', 'Send email to customer when ticket is closed with feedback request')
ON DUPLICATE KEY UPDATE setting_value=setting_value;

-- All done!
-- To check feedback:
-- SELECT * FROM support_ticket_feedback ORDER BY created_at DESC;
