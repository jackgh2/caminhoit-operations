-- Make staff_id nullable for customer-placed orders
-- Customer orders don't have a staff member assigned initially

ALTER TABLE orders
MODIFY COLUMN staff_id INT DEFAULT NULL COMMENT 'Staff member who created/assigned to the order';
