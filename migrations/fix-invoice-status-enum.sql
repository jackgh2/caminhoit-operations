-- Fix invoice status ENUM to include 'partially_paid' and 'pending_payment'
-- These status values are used in the UI but missing from the database schema

-- Current ENUM: ('draft','sent','paid','overdue','cancelled')
-- Adding: 'partially_paid', 'pending_payment', 'refunded'

ALTER TABLE invoices
MODIFY COLUMN status ENUM('draft', 'sent', 'pending_payment', 'partially_paid', 'paid', 'overdue', 'cancelled', 'refunded')
DEFAULT 'sent';
