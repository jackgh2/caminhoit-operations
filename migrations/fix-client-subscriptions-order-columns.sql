-- Make order_id and order_item_id nullable in client_subscriptions
-- This allows manual subscriptions to be created without an order

ALTER TABLE client_subscriptions
MODIFY COLUMN order_id INT NULL;

ALTER TABLE client_subscriptions
MODIFY COLUMN order_item_id INT NULL;
