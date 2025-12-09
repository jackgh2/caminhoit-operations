-- Auto-Delivery System Tables
-- Run this migration to enable automatic service/license provisioning

-- Create licenses table
CREATE TABLE IF NOT EXISTS licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(50) NOT NULL UNIQUE,
    company_id INT NOT NULL,
    customer_id INT NOT NULL,
    product_id INT DEFAULT NULL,
    product_name VARCHAR(255) NOT NULL,
    order_id INT DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'active' COMMENT 'active, suspended, expired, cancelled',
    billing_cycle VARCHAR(50) DEFAULT 'monthly',
    expiry_date DATE DEFAULT NULL,
    last_checked DATETIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    INDEX idx_license_key (license_key),
    INDEX idx_company (company_id),
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    INDEX idx_expiry (expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Software license keys and management';

-- Create customer services table
CREATE TABLE IF NOT EXISTS customer_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    customer_id INT NOT NULL,
    product_id INT DEFAULT NULL,
    product_name VARCHAR(255) NOT NULL,
    order_id INT DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'active' COMMENT 'active, suspended, cancelled, expired',
    billing_cycle VARCHAR(50) DEFAULT 'monthly',
    next_billing_date DATE DEFAULT NULL,
    service_data TEXT DEFAULT NULL COMMENT 'JSON data for service-specific details',
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    INDEX idx_company (company_id),
    INDEX idx_customer (customer_id),
    INDEX idx_product (product_id),
    INDEX idx_status (status),
    INDEX idx_billing_date (next_billing_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Customer service instances and subscriptions';

-- Create delivery logs table
CREATE TABLE IF NOT EXISTS delivery_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    delivered_items TEXT DEFAULT NULL COMMENT 'JSON array of successfully delivered items',
    failed_items TEXT DEFAULT NULL COMMENT 'JSON array of failed delivery attempts',
    status VARCHAR(50) DEFAULT 'success' COMMENT 'success, partial, failed',
    error_message TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_order (order_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Auto-delivery execution logs';

-- Create bundle items table (for bundle product definitions)
CREATE TABLE IF NOT EXISTS bundle_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bundle_id INT NOT NULL COMMENT 'Product ID of the bundle',
    product_id INT NOT NULL COMMENT 'Product ID of included item',
    product_name VARCHAR(255) NOT NULL,
    item_type VARCHAR(50) NOT NULL COMMENT 'product, license',
    quantity INT DEFAULT 1,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (bundle_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_bundle (bundle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Bundle product component definitions';

-- Add order status for partially completed deliveries
ALTER TABLE orders
MODIFY COLUMN status VARCHAR(50) DEFAULT 'draft'
COMMENT 'draft, pending_payment, paid, completed, partially_completed, cancelled';
