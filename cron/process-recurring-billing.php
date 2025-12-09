#!/usr/bin/env php
<?php
/**
 * Recurring Billing Processor
 * Run this script daily via cron: 0 2 * * * /usr/bin/php /path/to/cron/process-recurring-billing.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/config-payment-api.php';
require_once __DIR__ . '/../includes/order-automation.php';

error_log("Starting recurring billing processor at " . date('Y-m-d H:i:s'));

try {
    // Get all recurring billing records due for processing
    $stmt = $pdo->prepare("
        SELECT rb.*, oi.*, c.name as company_name, c.email as company_email,
               p.name as product_name, sb.name as bundle_name
        FROM recurring_billing rb
        JOIN order_items oi ON rb.order_item_id = oi.id
        JOIN companies c ON rb.company_id = c.id
        LEFT JOIN products p ON rb.product_id = p.id
        LEFT JOIN service_bundles sb ON rb.bundle_id = sb.id
        WHERE rb.status = 'active' 
        AND rb.next_billing_date <= CURDATE()
        AND rb.next_billing_date IS NOT NULL
    ");
    $stmt->execute();
    $recurring_items = $stmt->fetchAll();
    
    $processed_count = 0;
    $error_count = 0;
    
    foreach ($recurring_items as $item) {
        try {
            $invoice_id = processRecurringBilling($item, $pdo);
            if ($invoice_id) {
                $processed_count++;
                error_log("Created recurring invoice {$invoice_id} for company {$item['company_name']}");
            } else {
                $error_count++;
            }
        } catch (Exception $e) {
            $error_count++;
            error_log("Error processing recurring billing for item {$item['id']}: " . $e->getMessage());
        }
    }
    
    // Check for overdue invoices and update status
    updateOverdueInvoices($pdo);
    
    error_log("Recurring billing completed: {$processed_count} processed, {$error_count} errors");
    
} catch (Exception $e) {
    error_log("Fatal error in recurring billing processor: " . $e->getMessage());
}

function processRecurringBilling($recurring_item, $pdo) {
    try {
        $pdo->beginTransaction();
        
        // Generate new invoice number
        $invoice_number = generateInvoiceNumber($pdo, $recurring_item['currency']);
        
        // Calculate due date
        $payment_terms_days = getPaymentTermsDays($recurring_item['company_id'], $pdo);
        
        // Calculate amounts
        $tax_rate = getVATRate($recurring_item['currency']);
        $subtotal = $recurring_item['amount'];
        $tax_amount = $subtotal * $tax_rate;
        $total_amount = $subtotal + $tax_amount;
        
        // Create recurring invoice
        $stmt = $pdo->prepare("
            INSERT INTO invoices (
                invoice_number, company_id, customer_id, invoice_type, status,
                issue_date, due_date, subtotal, tax_amount, total_amount,
                currency, tax_rate, payment_terms, created_by, auto_generated,
                recurring_billing_id
            ) VALUES (?, ?, ?, 'recurring', 'sent', CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? DAY),
                ?, ?, ?, ?, ?, ?, 1, 1, ?)
        ");
        
        $stmt->execute([
            $invoice_number, $recurring_item['company_id'], null,
            $payment_terms_days, $subtotal, $tax_amount, $total_amount,
            $recurring_item['currency'], $tax_rate, 
            getPaymentTermsText($payment_terms_days), $recurring_item['id']
        ]);
        
        $invoice_id = $pdo->lastInsertId();
        
        // Create invoice item
        $product_name = $recurring_item['product_name'] ?? $recurring_item['bundle_name'] ?? 'Recurring Service';
        
        $stmt = $pdo->prepare("
            INSERT INTO invoice_items (
                invoice_id, order_item_id, product_id, bundle_id, description,
                quantity, unit_price, total_amount, billing_cycle
            ) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)
        ");
        
        $stmt->execute([
            $invoice_id, $recurring_item['order_item_id'], 
            $recurring_item['product_id'], $recurring_item['bundle_id'],
            $product_name . ' (Recurring)', $recurring_item['amount'], 
            $recurring_item['amount'], $recurring_item['billing_cycle']
        ]);
        
        // Update next billing date
        $next_billing_date = calculateNextBillingDate($recurring_item['billing_cycle']);
        
        $stmt = $pdo->prepare("
            UPDATE recurring_billing 
            SET next_billing_date = ?, last_billed_date = CURDATE(), updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$next_billing_date, $recurring_item['id']]);
        
        $pdo->commit();
        
        // Send recurring invoice email
        sendRecurringInvoiceEmail($invoice_id, $pdo);
        
        return $invoice_id;
        
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
}

function updateOverdueInvoices($pdo) {
    try {
        // Update overdue invoices
        $stmt = $pdo->prepare("
            UPDATE invoices 
            SET status = 'overdue', updated_at = NOW()
            WHERE status IN ('sent', 'partially_paid')
            AND due_date < CURDATE()
        ");
        $stmt->execute();
        
        $overdue_count = $stmt->rowCount();
        if ($overdue_count > 0) {
            error_log("Updated {$overdue_count} invoices to overdue status");
        }
        
    } catch (Exception $e) {
        error_log("Error updating overdue invoices: " . $e->getMessage());
    }
}
?>