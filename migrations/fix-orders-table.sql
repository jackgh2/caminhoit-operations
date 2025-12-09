-- Fix orders table to support e-commerce workflow
-- Run this migration to add missing columns
-- Safe version - checks for existing columns

-- Add customer_id column (for customer-placed orders) if not exists
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'customer_id'
    AND TABLE_SCHEMA = DATABASE());
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE orders ADD COLUMN customer_id INT DEFAULT NULL COMMENT ''Customer who placed the order'' AFTER company_id, ADD INDEX idx_customer_id (customer_id), ADD FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT ''customer_id already exists'' AS Info');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add vat_rate column if not exists
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'vat_rate'
    AND TABLE_SCHEMA = DATABASE());
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE orders ADD COLUMN vat_rate DECIMAL(5,4) DEFAULT 0.0000 COMMENT ''VAT rate applied'' AFTER currency',
    'SELECT ''vat_rate already exists'' AS Info');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add vat_enabled column if not exists
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'vat_enabled'
    AND TABLE_SCHEMA = DATABASE());
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE orders ADD COLUMN vat_enabled BOOLEAN DEFAULT FALSE COMMENT ''Whether VAT was applied'' AFTER vat_rate',
    'SELECT ''vat_enabled already exists'' AS Info');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add placed_at column if not exists
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'placed_at'
    AND TABLE_SCHEMA = DATABASE());
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE orders ADD COLUMN placed_at DATETIME DEFAULT NULL COMMENT ''When order was placed by customer'' AFTER end_date',
    'SELECT ''placed_at already exists'' AS Info');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update status enum to include e-commerce statuses
ALTER TABLE orders
MODIFY COLUMN status ENUM('draft', 'pending_approval', 'pending_payment', 'paid', 'completed', 'partially_completed', 'cancelled', 'rejected', 'pending', 'approved', 'processing') DEFAULT 'draft'
COMMENT 'Order status';

-- Update billing_cycle enum to support more cycles
ALTER TABLE orders
MODIFY COLUMN billing_cycle ENUM('monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially', 'one_time') DEFAULT 'monthly'
COMMENT 'Billing cycle for recurring items';
