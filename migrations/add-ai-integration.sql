-- Migration: Add AI Integration for Support Tickets
-- Date: 2025-11-06
-- Purpose: Enable OpenAI GPT-4 Mini integration for AI-assisted reply suggestions

-- Table for AI settings
CREATE TABLE IF NOT EXISTS `support_ai_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) UNIQUE NOT NULL,
    `setting_value` TEXT,
    `description` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default AI settings
INSERT INTO `support_ai_settings` (`setting_key`, `setting_value`, `description`) VALUES
('ai_enabled', '0', 'Enable AI-assisted reply suggestions'),
('openai_api_key', '', 'OpenAI API Key (get from https://platform.openai.com)'),
('ai_model', 'gpt-4o-mini', 'OpenAI model to use (gpt-4o-mini recommended)'),
('ai_temperature', '0.7', 'AI creativity level (0.0-1.0, default 0.7)'),
('ai_max_tokens', '500', 'Maximum tokens for AI replies (default 500)')
ON DUPLICATE KEY UPDATE setting_value=setting_value;

-- Table for AI usage logging (for analytics)
CREATE TABLE IF NOT EXISTS `support_ai_usage_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` INT NOT NULL,
    `model_used` VARCHAR(50) NOT NULL,
    `success` TINYINT(1) NOT NULL DEFAULT 1,
    `tokens_used` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE CASCADE,
    INDEX idx_ticket (ticket_id),
    INDEX idx_created_at (created_at),
    INDEX idx_success (success)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- All done!
-- Next steps:
-- 1. Import this SQL in phpMyAdmin
-- 2. Get OpenAI API key from https://platform.openai.com/api-keys
-- 3. Enable AI in settings: UPDATE support_ai_settings SET setting_value = '1' WHERE setting_key = 'ai_enabled';
-- 4. Add API key: UPDATE support_ai_settings SET setting_value = 'sk-your-key-here' WHERE setting_key = 'openai_api_key';
