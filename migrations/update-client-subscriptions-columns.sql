-- Add missing columns to client_subscriptions table
-- Run this migration to fix subscription creation errors
-- Note: If a column already exists, that statement will give an error - you can ignore it

-- Add total_price column
-- If you get "Duplicate column" error, skip this one
ALTER TABLE client_subscriptions
ADD COLUMN total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER unit_price;

-- Add notes column
-- If you get "Duplicate column" error, skip this one
ALTER TABLE client_subscriptions
ADD COLUMN notes TEXT AFTER auto_renew;

-- Add created_by column
-- If you get "Duplicate column" error, skip this one
ALTER TABLE client_subscriptions
ADD COLUMN created_by INT AFTER notes;

-- Update existing rows to calculate total_price from unit_price * quantity
UPDATE client_subscriptions
SET total_price = unit_price * quantity
WHERE total_price = 0 OR total_price IS NULL;
