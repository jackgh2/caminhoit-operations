-- Create subscription and inventory management tables
-- For managing client subscriptions and product assignments

-- Create client_subscriptions table
CREATE TABLE IF NOT EXISTS client_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    product_id INT DEFAULT NULL,
    bundle_id INT DEFAULT NULL,
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    billing_cycle ENUM('monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially') NOT NULL,
    status ENUM('active', 'suspended', 'cancelled', 'pending') DEFAULT 'active',
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    next_billing_date DATE NOT NULL,
    auto_renew TINYINT(1) DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_company (company_id),
    INDEX idx_status (status),
    INDEX idx_next_billing (next_billing_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Client subscriptions to products/bundles';

-- Create subscription_inventory table
CREATE TABLE IF NOT EXISTS subscription_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT NOT NULL,
    total_quantity INT NOT NULL DEFAULT 0,
    assigned_quantity INT NOT NULL DEFAULT 0,
    available_quantity INT AS (total_quantity - assigned_quantity) STORED,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (subscription_id) REFERENCES client_subscriptions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_subscription (subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks inventory for subscription-based products';

-- Create product_assignments table if not exists
CREATE TABLE IF NOT EXISTS product_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT NOT NULL,
    user_id INT NOT NULL,
    product_id INT DEFAULT NULL,
    bundle_id INT DEFAULT NULL,
    quantity INT DEFAULT 1,
    license_key VARCHAR(255) DEFAULT NULL,
    status ENUM('active', 'inactive', 'expired') DEFAULT 'active',
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT DEFAULT NULL,
    notes TEXT,
    FOREIGN KEY (subscription_id) REFERENCES client_subscriptions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_subscription (subscription_id),
    INDEX idx_user (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Product/license assignments to users';
