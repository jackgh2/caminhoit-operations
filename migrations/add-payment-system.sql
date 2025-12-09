-- Add payment system tables and columns
-- Run this migration to enable Stripe payment integration

-- Create invoices table first
CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    order_id INT NOT NULL,
    company_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('draft', 'pending_payment', 'paid', 'overdue', 'cancelled', 'refunded') DEFAULT 'pending_payment',
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    paid_date DATETIME DEFAULT NULL,

    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(3) DEFAULT 'GBP',

    payment_method VARCHAR(50) DEFAULT NULL COMMENT 'stripe, bank_transfer, manual, etc',
    transaction_id VARCHAR(255) DEFAULT NULL COMMENT 'Payment gateway transaction ID',

    notes TEXT DEFAULT NULL,
    terms TEXT DEFAULT NULL,
    file_path VARCHAR(500) DEFAULT NULL COMMENT 'Path to PDF invoice file',
    stripe_session_id VARCHAR(255) DEFAULT NULL,

    sent_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,

    INDEX idx_invoice_number (invoice_number),
    INDEX idx_order_id (order_id),
    INDEX idx_company_id (company_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_stripe_session (stripe_session_id),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Customer invoices for orders';

-- Create payment logs table
CREATE TABLE IF NOT EXISTS payment_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    payment_method VARCHAR(50) NOT NULL COMMENT 'stripe, bank_transfer, manual, etc',
    transaction_id VARCHAR(255) DEFAULT NULL COMMENT 'External transaction/payment ID',
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'GBP',
    status VARCHAR(50) DEFAULT 'pending' COMMENT 'pending, completed, failed, refunded',
    raw_data TEXT DEFAULT NULL COMMENT 'JSON data from payment gateway',
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    INDEX idx_invoice (invoice_id),
    INDEX idx_transaction (transaction_id),
    INDEX idx_status (status),
    INDEX idx_payment_method (payment_method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Payment transaction logs';

-- Add payment config to system_config table
INSERT IGNORE INTO system_config (config_key, config_value, config_type, category, description, updated_by)
VALUES
('stripe_publishable_key', '', 'string', 'payment', 'Stripe Publishable API Key', 1),
('stripe_secret_key', '', 'string', 'payment', 'Stripe Secret API Key', 1),
('stripe_webhook_secret', '', 'string', 'payment', 'Stripe Webhook Signing Secret', 1),
('bank_name', 'Example Bank', 'string', 'payment', 'Bank Name for Transfers', 1),
('bank_account_name', 'CaminhoIT Ltd', 'string', 'payment', 'Bank Account Name', 1),
('bank_account_number', '12345678', 'string', 'payment', 'Bank Account Number', 1),
('bank_sort_code', '12-34-56', 'string', 'payment', 'Bank Sort Code', 1),
('bank_iban', 'GB29 NWBK 6016 1331 9268 19', 'string', 'payment', 'Bank IBAN', 1),
('bank_swift', 'NWBKGB2L', 'string', 'payment', 'Bank SWIFT/BIC Code', 1);
