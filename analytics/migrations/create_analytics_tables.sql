-- Analytics System Database Schema
-- Privacy-focused analytics similar to Umami

-- Page views tracking table
CREATE TABLE IF NOT EXISTS analytics_pageviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    visitor_id VARCHAR(64) NOT NULL,
    page_url VARCHAR(2048) NOT NULL,
    page_title VARCHAR(512),
    referrer VARCHAR(2048),
    utm_source VARCHAR(255),
    utm_medium VARCHAR(255),
    utm_campaign VARCHAR(255),
    utm_content VARCHAR(255),
    utm_term VARCHAR(255),

    -- Geographic data
    country_code CHAR(2),
    country_name VARCHAR(100),
    region VARCHAR(100),
    city VARCHAR(100),
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),

    -- Technical data
    browser VARCHAR(100),
    browser_version VARCHAR(50),
    os VARCHAR(100),
    os_version VARCHAR(50),
    device_type ENUM('desktop', 'mobile', 'tablet', 'bot') DEFAULT 'desktop',
    device_brand VARCHAR(100),
    device_model VARCHAR(100),
    screen_resolution VARCHAR(20),

    -- Network
    ip_address VARCHAR(45),
    user_agent TEXT,
    language VARCHAR(10),

    -- Engagement
    time_on_page INT UNSIGNED,
    is_bounce BOOLEAN DEFAULT FALSE,
    is_entry BOOLEAN DEFAULT FALSE,
    is_exit BOOLEAN DEFAULT FALSE,

    -- Timestamps
    viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_session (session_id),
    INDEX idx_visitor (visitor_id),
    INDEX idx_page_url (page_url(255)),
    INDEX idx_viewed_at (viewed_at),
    INDEX idx_country (country_code),
    INDEX idx_referrer (referrer(255)),
    INDEX idx_device_type (device_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions table
CREATE TABLE IF NOT EXISTS analytics_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) UNIQUE NOT NULL,
    visitor_id VARCHAR(64) NOT NULL,

    -- Session details
    entry_page VARCHAR(2048),
    exit_page VARCHAR(2048),
    page_views INT UNSIGNED DEFAULT 1,
    total_time INT UNSIGNED DEFAULT 0,
    is_bounce BOOLEAN DEFAULT TRUE,

    -- Geographic
    country_code CHAR(2),
    country_name VARCHAR(100),
    region VARCHAR(100),
    city VARCHAR(100),

    -- Technical
    browser VARCHAR(100),
    os VARCHAR(100),
    device_type VARCHAR(20),

    -- Traffic source
    referrer VARCHAR(2048),
    utm_source VARCHAR(255),
    utm_medium VARCHAR(255),
    utm_campaign VARCHAR(255),

    -- Timestamps
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_visitor (visitor_id),
    INDEX idx_started_at (started_at),
    INDEX idx_country (country_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Real-time active visitors
CREATE TABLE IF NOT EXISTS analytics_active_visitors (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visitor_id VARCHAR(64) NOT NULL,
    session_id VARCHAR(64) NOT NULL,
    current_page VARCHAR(2048),
    country_code CHAR(2),
    city VARCHAR(100),
    device_type VARCHAR(20),
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_visitor (visitor_id),
    INDEX idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Events tracking (for notable events, conversions, etc)
CREATE TABLE IF NOT EXISTS analytics_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    visitor_id VARCHAR(64) NOT NULL,
    event_name VARCHAR(100) NOT NULL,
    event_category VARCHAR(100),
    event_value VARCHAR(255),
    page_url VARCHAR(2048),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_event_name (event_name),
    INDEX idx_created_at (created_at),
    INDEX idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily aggregated stats for faster queries
CREATE TABLE IF NOT EXISTS analytics_daily_stats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stat_date DATE NOT NULL,

    -- Totals
    total_pageviews INT UNSIGNED DEFAULT 0,
    total_visitors INT UNSIGNED DEFAULT 0,
    total_sessions INT UNSIGNED DEFAULT 0,

    -- Averages
    avg_time_on_site INT UNSIGNED DEFAULT 0,
    avg_pages_per_session DECIMAL(5,2) DEFAULT 0,
    bounce_rate DECIMAL(5,2) DEFAULT 0,

    -- Top values (stored as JSON for flexibility)
    top_pages JSON,
    top_countries JSON,
    top_referrers JSON,
    top_browsers JSON,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_date (stat_date),
    INDEX idx_stat_date (stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings for analytics
CREATE TABLE IF NOT EXISTS analytics_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO analytics_settings (setting_key, setting_value) VALUES
('tracking_enabled', '1'),
('respect_dnt', '1'),
('session_timeout', '1800'),
('ip_anonymization', '1'),
('track_bots', '0')
ON DUPLICATE KEY UPDATE setting_key=setting_key;
