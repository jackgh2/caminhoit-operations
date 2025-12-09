-- Fix subscription_inventory table to make order_id optional
-- This allows subscriptions to be created manually without an order

-- Option 1: Make order_id nullable (recommended)
ALTER TABLE subscription_inventory
MODIFY COLUMN order_id INT NULL;

-- Option 2: If you want to remove the column entirely (uncomment if needed)
-- ALTER TABLE subscription_inventory
-- DROP COLUMN order_id;
