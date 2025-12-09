-- Standalone Booking System (Calendly Alternative)
-- Self-hosted, no external tracking
-- Created: 2025-11-09

-- Booking service types (consultation types)
CREATE TABLE IF NOT EXISTS booking_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    duration_minutes INT NOT NULL DEFAULT 30,
    color VARCHAR(7) DEFAULT '#667eea',
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff availability (working hours)
CREATE TABLE IF NOT EXISTS booking_availability (
    id INT AUTO_INCREMENT PRIMARY KEY,
    day_of_week TINYINT NOT NULL COMMENT '0=Sunday, 6=Saturday',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blocked time slots (holidays, breaks, etc.)
CREATE TABLE IF NOT EXISTS booking_blocked_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blocked_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_blocked_date (blocked_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Booking appointments
CREATE TABLE IF NOT EXISTS booking_appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,

    -- Customer information
    customer_name VARCHAR(255) NOT NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(50),
    customer_company VARCHAR(255),

    -- Appointment details
    appointment_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    duration_minutes INT NOT NULL,

    -- Status tracking
    status ENUM('pending', 'confirmed', 'cancelled', 'completed', 'no_show') DEFAULT 'pending',
    cancellation_reason TEXT,

    -- Additional information
    notes TEXT,
    internal_notes TEXT COMMENT 'Staff-only notes',

    -- Confirmation tokens
    confirmation_token VARCHAR(64) UNIQUE,
    cancellation_token VARCHAR(64) UNIQUE,

    -- Notifications
    confirmation_sent TINYINT(1) DEFAULT 0,
    reminder_sent TINYINT(1) DEFAULT 0,

    -- Metadata
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (service_id) REFERENCES booking_services(id) ON DELETE CASCADE,
    INDEX idx_appointment_date (appointment_date),
    INDEX idx_customer_email (customer_email),
    INDEX idx_status (status),
    INDEX idx_confirmation_token (confirmation_token),
    INDEX idx_cancellation_token (cancellation_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Booking settings (global configuration)
CREATE TABLE IF NOT EXISTS booking_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default services
INSERT INTO booking_services (name, description, duration_minutes, color, sort_order) VALUES
('IT Consultation', 'General IT consultation and needs assessment', 30, '#667eea', 1),
('Technical Support', 'Technical support session for existing clients', 45, '#764ba2', 2),
('MSP Onboarding', 'Initial consultation for managed services onboarding', 60, '#f093fb', 3),
('Cloud Migration Planning', 'Discuss your cloud migration strategy', 60, '#4facfe', 4),
('Cybersecurity Assessment', 'Security audit and recommendations', 45, '#43e97b', 5);

-- Insert default availability (Monday-Friday, 9 AM - 5 PM)
INSERT INTO booking_availability (day_of_week, start_time, end_time) VALUES
(1, '09:00:00', '17:00:00'), -- Monday
(2, '09:00:00', '17:00:00'), -- Tuesday
(3, '09:00:00', '17:00:00'), -- Wednesday
(4, '09:00:00', '17:00:00'), -- Thursday
(5, '09:00:00', '17:00:00'); -- Friday

-- Insert default settings
INSERT INTO booking_settings (setting_key, setting_value) VALUES
('timezone', 'Europe/Lisbon'),
('booking_buffer_minutes', '15'),
('advance_booking_days', '30'),
('min_notice_hours', '24'),
('allow_weekend_bookings', '0'),
('send_reminders', '1'),
('reminder_hours_before', '24'),
('confirmation_email_enabled', '1'),
('discord_webhook_enabled', '1'),
('company_name', 'CaminhoIT'),
('company_email', 'support@caminhoit.com'),
('company_phone', '+351 963 452 653');
