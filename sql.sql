-- ===========================================
-- CaminhoIT CMS Database Structure (v2)
-- With User Deactivation / Archiving Logic
-- ===========================================

-- Drop tables if they exist (safe for resets)
DROP TABLE IF EXISTS company_users;
DROP TABLE IF EXISTS navigation_menu;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS companies;

-- ===========================
-- Companies Table
-- ===========================
CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    address TEXT,
    contact_email VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ===========================
-- Users Table (OAuth + Roles + Deactivation)
-- ===========================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider ENUM('google', 'microsoft', 'discord') NOT NULL,
    provider_id VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    username VARCHAR(255),
    original_username VARCHAR(255), -- Store original username if renamed on deactivation
    role ENUM('public', 'supported_user', 'account_manager', 'support_consultant', 'accountant', 'administrator') NOT NULL DEFAULT 'public',
    company_id INT DEFAULT NULL, -- NULL for global staff like admin/support consultant
    is_active BOOLEAN NOT NULL DEFAULT TRUE, -- TRUE = active, FALSE = disabled
    deactivated_at TIMESTAMP NULL, -- Timestamp when account was disabled
    deactivated_by INT NULL, -- User ID who performed the deactivation
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    CONSTRAINT fk_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    UNIQUE KEY unique_provider_user (provider, provider_id),
    CONSTRAINT fk_deactivated_by FOREIGN KEY (deactivated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ==================================
-- Optional Company-User Link Table
-- (Future-Proof Multi-Company User Support)
-- ==================================
CREATE TABLE company_users (
    user_id INT NOT NULL,
    company_id INT NOT NULL,
    role ENUM('supported_user', 'account_manager') NOT NULL,
    PRIMARY KEY (user_id, company_id),
    CONSTRAINT fk_company_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_company_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- ==================================
-- Navigation Menu Table (CMS Control)
-- ==================================
CREATE TABLE navigation_menu (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    url VARCHAR(255) NOT NULL,
    user_level ENUM('public', 'supported_user', 'account_manager', 'support_consultant', 'accountant', 'administrator') NOT NULL DEFAULT 'public',
    sort_order INT DEFAULT 0,
    parent_id INT DEFAULT NULL,
    icon VARCHAR(255) DEFAULT NULL,
    CONSTRAINT fk_navigation_parent FOREIGN KEY (parent_id) REFERENCES navigation_menu(id) ON DELETE CASCADE
);

-- ===========================
-- Example Insert Data
-- ===========================

-- Insert default nav items
INSERT INTO navigation_menu (title, url, user_level, sort_order)
VALUES
  ('IT Solutions', '/it-solutions.php', 'public', 1),
  ('Web Solutions', '/web-solutions.php', 'public', 2),
  ('About Us', '/about.php', 'public', 3),
  ('Blog', '/blog.php', 'public', 4),
  ('Client Dashboard', '/client/dashboard.php', 'account_manager', 1),
  ('Admin Panel', '/admin/dashboard.php', 'administrator', 1);

-- Example company
INSERT INTO companies (name, contact_email)
VALUES ('Test Company Ltd.', 'contact@testcompany.com');

CREATE TABLE services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_type ENUM('it', 'web'),
    title VARCHAR(255),
    description TEXT,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT,
    plan_name VARCHAR(255),
    plan_description TEXT,
    features TEXT, -- Can be stored as JSON or comma-separated list
    price DECIMAL(10,2) NULL,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
);

CREATE TABLE page_content (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page VARCHAR(255),
    section VARCHAR(255),
    content TEXT,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    category ENUM('support', 'billing', 'service_request', 'general_inquiry', 'onboarding') NOT NULL,
    status ENUM('open', 'awaiting_response', 'closed') DEFAULT 'open',
    user_id INT NULL,  -- NULL for public submissions
    company_id INT NULL,  -- Only populated for real users
    name VARCHAR(255),  -- For public submissions
    email VARCHAR(255), -- For public submissions
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- STEP 1: SQL STRUCTURE TO SUPPORT STAFF GROUP MANAGEMENT

-- Create or use existing ticket groups table
CREATE TABLE IF NOT EXISTS support_ticket_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add group_id to users (if not already there)
ALTER TABLE users
    ADD COLUMN support_group_id INT DEFAULT NULL,
    ADD CONSTRAINT fk_users_group
        FOREIGN KEY (support_group_id) REFERENCES support_ticket_groups(id)
        ON DELETE SET NULL;

-- Optional: support multi-group assignment
CREATE TABLE IF NOT EXISTS user_ticket_group_permissions (
    user_id INT,
    group_id INT,
    PRIMARY KEY (user_id, group_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES support_ticket_groups(id) ON DELETE CASCADE
);



--Companies

-- Add missing columns to companies table (run each ALTER TABLE separately)
ALTER TABLE companies 
ADD COLUMN is_active TINYINT(1) DEFAULT 1;

ALTER TABLE companies 
ADD COLUMN created_by INT;

ALTER TABLE companies 
ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE companies 
ADD COLUMN deactivated_at TIMESTAMP NULL;

ALTER TABLE companies 
ADD COLUMN deactivated_by INT;

ALTER TABLE companies 
ADD COLUMN phone VARCHAR(20);

ALTER TABLE companies 
ADD COLUMN website VARCHAR(255);

ALTER TABLE companies 
ADD COLUMN industry VARCHAR(100);

ALTER TABLE companies 
ADD COLUMN notes TEXT;

ALTER TABLE companies 
ADD COLUMN logo_url VARCHAR(255);

-- Update existing companies to be active by default
UPDATE companies SET is_active = 1 WHERE is_active IS NULL;

-- Add foreign key constraints
ALTER TABLE companies 
ADD CONSTRAINT fk_companies_created_by FOREIGN KEY (created_by) REFERENCES users(id);

ALTER TABLE companies 
ADD CONSTRAINT fk_companies_deactivated_by FOREIGN KEY (deactivated_by) REFERENCES users(id);

--- user invites

CREATE TABLE user_invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    company_id INT NOT NULL,
    invitation_token VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    invited_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    accepted_at TIMESTAMP NULL,
    INDEX idx_token (invitation_token),
    INDEX idx_email (email),
    INDEX idx_company (company_id),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (invited_by) REFERENCES users(id)
);

---service catalogue

-- Service Categories (Managed IT, Cloud, Security, etc.)
CREATE TABLE service_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    icon VARCHAR(50),
    color VARCHAR(7), -- Hex color code
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Individual Products/Services
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    short_description VARCHAR(500),
    product_code VARCHAR(50) UNIQUE,
    unit_type ENUM('per_user', 'per_device', 'per_month', 'per_gb', 'per_hour', 'one_time', 'custom') DEFAULT 'per_month',
    base_price DECIMAL(10,2) DEFAULT 0.00,
    setup_fee DECIMAL(10,2) DEFAULT 0.00,
    minimum_quantity INT DEFAULT 1,
    billing_cycle ENUM('monthly', 'quarterly', 'annually', 'one_time') DEFAULT 'monthly',
    is_active TINYINT(1) DEFAULT 1,
    is_featured TINYINT(1) DEFAULT 0,
    requires_setup TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    metadata JSON, -- For custom fields, specifications, etc.
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (category_id) REFERENCES service_categories(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Pricing Tiers (Volume discounts, different pricing for different client types)
CREATE TABLE pricing_tiers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    tier_name VARCHAR(100) NOT NULL,
    min_quantity INT DEFAULT 1,
    max_quantity INT DEFAULT NULL,
    price_per_unit DECIMAL(10,2) NOT NULL,
    discount_percentage DECIMAL(5,2) DEFAULT 0.00,
    client_type ENUM('all', 'small_business', 'enterprise', 'nonprofit', 'preferred') DEFAULT 'all',
    currency VARCHAR(3) DEFAULT 'GBP',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- Service Bundles/Packages
CREATE TABLE service_bundles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    short_description VARCHAR(500),
    bundle_code VARCHAR(50) UNIQUE,
    bundle_price DECIMAL(10,2) NOT NULL,
    discount_percentage DECIMAL(5,2) DEFAULT 0.00,
    billing_cycle ENUM('monthly', 'quarterly', 'annually') DEFAULT 'monthly',
    is_active TINYINT(1) DEFAULT 1,
    is_featured TINYINT(1) DEFAULT 0,
    target_audience VARCHAR(100), -- 'Small Business', 'Enterprise', etc.
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Bundle Components (Products within bundles)
CREATE TABLE bundle_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bundle_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT DEFAULT 1,
    custom_price DECIMAL(10,2) DEFAULT NULL, -- Override product price in bundle
    is_optional TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (bundle_id) REFERENCES service_bundles(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_bundle_product (bundle_id, product_id)
);

-- Client Service Subscriptions
CREATE TABLE client_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    product_id INT DEFAULT NULL,
    bundle_id INT DEFAULT NULL,
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    billing_cycle ENUM('monthly', 'quarterly', 'annually') NOT NULL,
    status ENUM('active', 'suspended', 'cancelled', 'pending') DEFAULT 'active',
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    next_billing_date DATE NOT NULL,
    auto_renew TINYINT(1) DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (bundle_id) REFERENCES service_bundles(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Insert default service categories
INSERT INTO service_categories (name, description, icon, color, sort_order) VALUES
('Managed IT Support', 'Comprehensive IT support and management services', 'bi-gear', '#4F46E5', 1),
('Cloud Services', 'Cloud infrastructure and migration services', 'bi-cloud', '#10B981', 2),
('Cybersecurity', 'Security solutions and compliance services', 'bi-shield-check', '#EF4444', 3),
('Business Hardware', 'Hardware procurement and management', 'bi-laptop', '#F59E0B', 4),
('Web & Digital', 'Web development and digital services', 'bi-globe', '#06B6D4', 5),
('Consultancy', 'IT strategy and consulting services', 'bi-person-workspace', '#8B5CF6', 6),
('Training & Support', 'Staff training and knowledge transfer', 'bi-book', '#84CC16', 7);


-- First, let's check and add missing columns to bundle_products table
-- Run these one by one and ignore errors if columns already exist

ALTER TABLE bundle_products ADD COLUMN description TEXT;
ALTER TABLE bundle_products ADD COLUMN is_required TINYINT(1) DEFAULT 1;

-- Create product assignments table for delegating licenses to company members
CREATE TABLE IF NOT EXISTS product_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    assigned_quantity INT DEFAULT 1,
    status ENUM('unassigned', 'assigned', 'active', 'suspended') DEFAULT 'unassigned',
    assigned_at TIMESTAMP NULL,
    assigned_by INT DEFAULT NULL,
    notes TEXT,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (subscription_id) REFERENCES client_subscriptions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Create product inventory table to track available vs assigned quantities
CREATE TABLE IF NOT EXISTS subscription_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT NOT NULL,
    total_quantity INT NOT NULL DEFAULT 0,
    assigned_quantity INT NOT NULL DEFAULT 0,
    available_quantity INT AS (total_quantity - assigned_quantity) STORED,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (subscription_id) REFERENCES client_subscriptions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_subscription (subscription_id)
);

-- Add indexes for better performance
CREATE INDEX idx_product_assignments_subscription ON product_assignments(subscription_id);
CREATE INDEX idx_product_assignments_user ON product_assignments(user_id);
CREATE INDEX idx_product_assignments_status ON product_assignments(status);

-- Insert some sample data for testing (adjust IDs as needed)
-- You can skip this if you don't have test data yet
INSERT IGNORE INTO client_subscriptions (company_id, product_id, quantity, unit_price, total_price, billing_cycle, status, start_date, next_billing_date) VALUES
(1, 1, 15, 8.99, 134.85, 'monthly', 'active', '2025-01-01', '2025-02-01'),
(1, 2, 5, 12.50, 62.50, 'monthly', 'active', '2025-01-01', '2025-02-01');

-- Initialize inventory for sample subscriptions
INSERT IGNORE INTO subscription_inventory (subscription_id, total_quantity, assigned_quantity) VALUES
(1, 15, 0),
(2, 5, 0);


-- orders

-- Create orders table
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL UNIQUE,
    company_id INT NOT NULL,
    staff_id INT NOT NULL,
    status ENUM('draft', 'pending', 'approved', 'processing', 'completed', 'cancelled') DEFAULT 'draft',
    order_type ENUM('new', 'upgrade', 'addon', 'renewal') DEFAULT 'new',
    subtotal DECIMAL(10,2) DEFAULT 0.00,
    tax_amount DECIMAL(10,2) DEFAULT 0.00,
    discount_amount DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(10,2) DEFAULT 0.00,
    currency VARCHAR(3) DEFAULT 'GBP',
    notes TEXT,
    internal_notes TEXT,
    billing_cycle ENUM('monthly', 'quarterly', 'annually') DEFAULT 'monthly',
    start_date DATE,
    end_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create order items table
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT DEFAULT NULL,
    bundle_id INT DEFAULT NULL,
    item_type ENUM('product', 'bundle', 'custom') DEFAULT 'product',
    name VARCHAR(255) NOT NULL,
    description TEXT,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    setup_fee DECIMAL(10,2) DEFAULT 0.00,
    discount_percentage DECIMAL(5,2) DEFAULT 0.00,
    discount_amount DECIMAL(10,2) DEFAULT 0.00,
    line_total DECIMAL(10,2) NOT NULL,
    billing_cycle ENUM('monthly', 'quarterly', 'annually', 'one_time') DEFAULT 'monthly',
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    FOREIGN KEY (bundle_id) REFERENCES service_bundles(id) ON DELETE SET NULL
);

-- Create order status history table
CREATE TABLE IF NOT EXISTS order_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    status_from VARCHAR(50),
    status_to VARCHAR(50) NOT NULL,
    changed_by INT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Add indexes for better performance
CREATE INDEX idx_orders_company ON orders(company_id);
CREATE INDEX idx_orders_staff ON orders(staff_id);
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_date ON orders(created_at);
CREATE INDEX idx_order_items_order ON order_items(order_id);
CREATE INDEX idx_order_items_product ON order_items(product_id);
CREATE INDEX idx_order_items_bundle ON order_items(bundle_id);

-- Update the orders table to include payment tracking
ALTER TABLE orders ADD COLUMN payment_status ENUM('unpaid', 'pending', 'paid', 'refunded', 'failed') DEFAULT 'unpaid' AFTER status;
ALTER TABLE orders ADD COLUMN payment_date DATETIME NULL AFTER payment_status;
ALTER TABLE orders ADD COLUMN payment_reference VARCHAR(255) NULL AFTER payment_date;
ALTER TABLE orders ADD COLUMN placed_at DATETIME NULL AFTER payment_reference;
ALTER TABLE orders ADD COLUMN processed_at DATETIME NULL AFTER placed_at;

-- Update status enum to include proper workflow
ALTER TABLE orders MODIFY COLUMN status ENUM('draft', 'placed', 'pending_payment', 'paid', 'processing', 'completed', 'cancelled') DEFAULT 'draft';

-- Add revenue tracking - only count paid orders
CREATE TABLE IF NOT EXISTS revenue_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'GBP',
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fiscal_year INT NOT NULL,
    fiscal_month INT NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    UNIQUE KEY unique_order_revenue (order_id)
);

-- Add payment tracking table
CREATE TABLE IF NOT EXISTS order_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    payment_method ENUM('card', 'bank_transfer', 'direct_debit', 'paypal', 'other') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'GBP',
    transaction_id VARCHAR(255),
    payment_date DATETIME NOT NULL,
    status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- Create indexes
CREATE INDEX idx_orders_payment_status ON orders(payment_status);
CREATE INDEX idx_orders_placed_at ON orders(placed_at);
CREATE INDEX idx_orders_processed_at ON orders(processed_at);
CREATE INDEX idx_revenue_tracking_date ON revenue_tracking(recorded_at);
CREATE INDEX idx_order_payments_date ON order_payments(payment_date);



-- Create system_config table for global configuration management
CREATE TABLE system_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(255) UNIQUE NOT NULL,
    config_value TEXT,
    config_type ENUM('string', 'integer', 'boolean', 'json', 'decimal') DEFAULT 'string',
    category VARCHAR(100),
    description TEXT,
    is_encrypted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_category (category),
    INDEX idx_config_key (config_key)
);

-- Insert default configuration values
INSERT INTO system_config (config_key, config_value, config_type, category, description, updated_by) VALUES
-- Tax Configuration
('tax.vat_registered', 'false', 'boolean', 'tax', 'Whether the company is VAT registered', 1),
('tax.default_vat_rate', '0.00', 'decimal', 'tax', 'Default VAT rate as decimal (e.g., 0.20 for 20%)', 1),
('tax.vat_number', '', 'string', 'tax', 'Company VAT registration number', 1),
('tax.tax_calculation_method', 'exclusive', 'string', 'tax', 'Tax calculation method: exclusive or inclusive', 1),

-- Country-specific VAT rates (JSON format)
('tax.country_vat_rates', '{"GB": 0.20, "US": 0.00, "DE": 0.19, "FR": 0.20, "IE": 0.23}', 'json', 'tax', 'VAT rates by country code', 1),

-- Email Configuration
('email.smtp_host', 'localhost', 'string', 'email', 'SMTP server hostname', 1),
('email.smtp_port', '587', 'integer', 'email', 'SMTP server port', 1),
('email.smtp_encryption', 'tls', 'string', 'email', 'SMTP encryption: tls, ssl, or none', 1),
('email.smtp_username', '', 'string', 'email', 'SMTP username', 1),
('email.smtp_password', '', 'string', 'email', 'SMTP password', 1),
('email.from_email', 'no-reply@caminhoit.com', 'string', 'email', 'Default sender email address', 1),
('email.from_name', 'CaminhoIT', 'string', 'email', 'Default sender name', 1),
('email.reply_to_email', 'support@caminhoit.com', 'string', 'email', 'Reply-to email address', 1),

-- Business Configuration
('business.company_name', 'CaminhoIT', 'string', 'business', 'Company legal name', 1),
('business.company_address', '', 'string', 'business', 'Company address', 1),
('business.company_phone', '', 'string', 'business', 'Company phone number', 1),
('business.company_email', 'info@caminhoit.com', 'string', 'business', 'Company contact email', 1),
('business.company_website', 'https://caminhoit.com', 'string', 'business', 'Company website URL', 1),
('business.default_currency', 'GBP', 'string', 'business', 'Default currency code', 1),
('business.invoice_prefix', 'INV', 'string', 'business', 'Invoice number prefix', 1),
('business.order_prefix', 'ORD', 'string', 'business', 'Order number prefix', 1),

-- System Configuration
('system.timezone', 'Europe/London', 'string', 'system', 'Default system timezone', 1),
('system.date_format', 'd/m/Y', 'string', 'system', 'Default date format', 1),
('system.time_format', 'H:i:s', 'string', 'system', 'Default time format', 1),
('system.language', 'en', 'string', 'system', 'Default system language', 1),
('system.maintenance_mode', 'false', 'boolean', 'system', 'Enable maintenance mode', 1),

-- Billing Configuration
('billing.default_payment_terms', '30', 'integer', 'billing', 'Default payment terms in days', 1),
('billing.default_billing_cycle', 'monthly', 'string', 'billing', 'Default billing cycle', 1),
('billing.late_payment_fee', '5.00', 'decimal', 'billing', 'Late payment fee amount', 1),
('billing.grace_period_days', '7', 'integer', 'billing', 'Grace period for late payments in days', 1),

-- Feature Toggles
('features.enable_quotes', 'true', 'boolean', 'features', 'Enable quotation system', 1),
('features.enable_invoicing', 'true', 'boolean', 'features', 'Enable invoicing system', 1),
('features.enable_reports', 'true', 'boolean', 'features', 'Enable reporting system', 1),
('features.enable_api', 'false', 'boolean', 'features', 'Enable API access', 1);

--

-- Add currency support to system_config
INSERT INTO system_config (config_key, config_value, config_type, category, description, updated_by) VALUES
-- Currency Configuration
('currency.supported_currencies', '{"GBP": {"symbol": "£", "name": "British Pound", "code": "GBP"}, "USD": {"symbol": "$", "name": "US Dollar", "code": "USD"}, "EUR": {"symbol": "€", "name": "Euro", "code": "EUR"}, "CAD": {"symbol": "C$", "name": "Canadian Dollar", "code": "CAD"}, "AUD": {"symbol": "A$", "name": "Australian Dollar", "code": "AUD"}}', 'json', 'currency', 'List of supported currencies with their symbols and names', 1),
('currency.exchange_rates', '{"USD": 1.27, "EUR": 1.16, "CAD": 1.71, "AUD": 1.91}', 'json', 'currency', 'Exchange rates from GBP to other currencies', 1),
('currency.auto_update_rates', 'false', 'boolean', 'currency', 'Automatically update exchange rates from external API', 1),
('currency.rate_update_frequency', '24', 'integer', 'currency', 'Hours between automatic rate updates', 1),
('currency.last_rate_update', '', 'string', 'currency', 'Last time exchange rates were updated', 1);

-- Add currency column to companies table
ALTER TABLE companies ADD COLUMN preferred_currency VARCHAR(3) DEFAULT NULL AFTER address;
ALTER TABLE companies ADD COLUMN currency_override BOOLEAN DEFAULT FALSE AFTER preferred_currency;

-- Add currency to orders table if not exists
ALTER TABLE orders ADD COLUMN customer_currency VARCHAR(3) DEFAULT NULL AFTER currency;

-- Create currency conversion log table
CREATE TABLE currency_conversions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_currency VARCHAR(3) NOT NULL,
    to_currency VARCHAR(3) NOT NULL,
    exchange_rate DECIMAL(10, 6) NOT NULL,
    amount_original DECIMAL(15, 2) NOT NULL,
    amount_converted DECIMAL(15, 2) NOT NULL,
    conversion_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reference_type ENUM('order', 'invoice', 'quote') NOT NULL,
    reference_id INT NOT NULL,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_reference (reference_type, reference_id),
    INDEX idx_currencies (from_currency, to_currency)
);

-- Add exchange rate API configuration
INSERT INTO system_config (config_key, config_value, config_type, category, description, updated_by) VALUES
-- Exchange Rate API Configuration
('currency.api_provider', 'exchangerate-api', 'string', 'currency', 'Exchange rate API provider (exchangerate-api, fixer, currencylayer)', 1),
('currency.api_key', '', 'string', 'currency', 'API key for exchange rate service', 1),
('currency.api_endpoint', 'https://api.exchangerate-api.com/v4/latest/', 'string', 'currency', 'API endpoint for exchange rates', 1),
('currency.conversion_fee_percent', '1.00', 'decimal', 'currency', 'Conversion fee percentage (e.g., 1.00 for 1%)', 1),
('currency.show_conversion_fee', 'false', 'boolean', 'currency', 'Show conversion fee to customers', 1),
('currency.auto_update_time', '00:00', 'string', 'currency', 'Time to auto-update rates (HH:MM format)', 1),
('currency.backup_rates', '{"USD": 1.27, "EUR": 1.16, "CAD": 1.71, "AUD": 1.91}', 'json', 'currency', 'Backup exchange rates if API fails', 1),
('currency.rate_tolerance', '5.00', 'decimal', 'currency', 'Maximum percentage change before alert (e.g., 5.00 for 5%)', 1),
('currency.last_api_error', '', 'string', 'currency', 'Last API error message', 1);


-- Add vat_rate column  
ALTER TABLE orders ADD COLUMN vat_rate DECIMAL(5,4) DEFAULT 0.2000 AFTER customer_currency;

-- Add vat_enabled column
ALTER TABLE orders ADD COLUMN vat_enabled TINYINT(1) DEFAULT 1 AFTER vat_rate;

-- Add currency to order_items
ALTER TABLE order_items ADD COLUMN currency VARCHAR(3) DEFAULT 'GBP' AFTER billing_cycle;



-- Quotes table
CREATE TABLE IF NOT EXISTS quotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_number VARCHAR(50) UNIQUE NOT NULL,
    company_id INT NOT NULL,
    staff_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('draft', 'sent', 'accepted', 'rejected', 'expired') DEFAULT 'draft',
    currency VARCHAR(3) DEFAULT 'GBP',
    subtotal DECIMAL(10,2) DEFAULT 0.00,
    tax_amount DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(10,2) DEFAULT 0.00,
    vat_enabled BOOLEAN DEFAULT false,
    vat_rate DECIMAL(5,4) DEFAULT 0.0000,
    valid_until DATE NULL,
    terms_conditions TEXT,
    notes TEXT,
    sent_at TIMESTAMP NULL,
    accepted_at TIMESTAMP NULL,
    rejected_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_quote_number (quote_number),
    INDEX idx_company_id (company_id),
    INDEX idx_staff_id (staff_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

-- Quote items table
CREATE TABLE IF NOT EXISTS quote_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NOT NULL,
    product_id INT NULL,
    bundle_id INT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    setup_fee DECIMAL(10,2) DEFAULT 0.00,
    billing_cycle ENUM('one_time', 'monthly', 'quarterly', 'semi_annually', 'annually') DEFAULT 'one_time',
    line_total DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    FOREIGN KEY (bundle_id) REFERENCES service_bundles(id) ON DELETE SET NULL,
    INDEX idx_quote_id (quote_id),
    INDEX idx_product_id (product_id),
    INDEX idx_bundle_id (bundle_id)
);

-- Quote status history table
CREATE TABLE IF NOT EXISTS quote_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NOT NULL,
    status_from VARCHAR(50),
    status_to VARCHAR(50) NOT NULL,
    changed_by INT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_quote_id (quote_id),
    INDEX idx_changed_by (changed_by)
);

-- Quote templates table (for reusable quote templates)
CREATE TABLE IF NOT EXISTS quote_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    template_data JSON,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_created_by (created_by)
);

-- Note: No sample data insertion to avoid foreign key constraint issues
-- Data will be created when quotes are actually created through the interface