<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==========================================
// DEBUG MODE TOGGLE
// Set to 1 to enable detailed logging
// Set to 0 to disable debug logs
// ==========================================
define('INVOICE_DEBUG_MODE', 0);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config-payment-api.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/functions.php';

// Only include automation if it exists
$automation_file = $_SERVER['DOCUMENT_ROOT'] . '/includes/order-automation.php';
if (file_exists($automation_file)) {
    require_once $automation_file;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

// Access control - Staff only
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'account manager', 'support consultant', 'accountant'])) {
    header('Location: /login.php');
    exit;
}

$user_id = $_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'];

// Debug logging helper function
function invoice_debug_log($message) {
    if (INVOICE_DEBUG_MODE) {
        error_log($message);
    }
}

// Get all companies
try {
    $stmt = $pdo->query("SELECT id, name FROM companies ORDER BY name");
    $all_companies = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Companies query error: " . $e->getMessage());
    $all_companies = [];
}

// Email templates
$email_templates = [
    'Invoice Created' => 'New invoice has been created for your account.',
    'Credit Card Invoice Created' => 'Your credit card invoice has been generated.',
    'Invoice Payment Reminder' => 'This is a friendly reminder that your invoice payment is due.',
    'First Invoice Overdue Notice' => 'Your invoice payment is now overdue. Please arrange payment.',
    'Second Invoice Overdue Notice' => 'Second notice: Your invoice payment remains overdue.',
    'Third Invoice Overdue Notice' => 'Final notice: Immediate payment required for overdue invoice.',
    'Credit Card Payment Due' => 'Your credit card payment is due soon.',
    'Credit Card Payment Failed' => 'Your credit card payment could not be processed.',
    'Invoice Payment Confirmation' => 'Thank you! Your payment has been received and processed.',
    'Credit Card Payment Confirmation' => 'Your credit card payment has been successfully processed.',
    'Invoice Refund Confirmation' => 'Your refund has been processed and will appear shortly.',
    'Direct Debit Payment Failed' => 'Your direct debit payment could not be processed.',
    'Direct Debit Payment Confirmation' => 'Your direct debit payment has been successfully processed.',
    'Direct Debit Payment Pending' => 'Your direct debit payment is being processed.',
    'Credit Card Payment Pending' => 'Your credit card payment is being processed.',
    'Invoice Modified' => 'Your invoice has been updated with new information.'
];

// Handle AJAX requests for payment recording, status updates, etc.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'change_currency':
            try {
                $invoice_id = intval($_POST['invoice_id']);
                $new_currency = $_POST['new_currency'];
                $exchange_rate = floatval($_POST['exchange_rate'] ?? 1);
                
                // Get current invoice
                $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
                $stmt->execute([$invoice_id]);
                $invoice = $stmt->fetch();
                
                if (!$invoice) {
                    throw new Exception('Invoice not found');
                }
                
                // Convert amounts
                $new_total = $invoice['total_amount'] * $exchange_rate;
                $new_paid = ($invoice['paid_amount'] ?? 0) * $exchange_rate;
                
                // Update invoice currency and amounts
                $stmt = $pdo->prepare("
                    UPDATE invoices 
                    SET currency = ?, total_amount = ?, paid_amount = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ");
                $stmt->execute([$new_currency, $new_total, $new_paid, $invoice_id]);
                
                // Update invoice items
                $stmt = $pdo->prepare("
                    UPDATE invoice_items 
                    SET amount = amount * ? 
                    WHERE invoice_id = ?
                ");
                $stmt->execute([$exchange_rate, $invoice_id]);
                
                echo json_encode(['success' => true, 'message' => "Currency changed to {$new_currency} successfully"]);
                exit;
                
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            break;
            
        case 'record_payment':
            try {
                $invoice_id = intval($_POST['invoice_id']);
                $amount = floatval($_POST['amount']);
                $payment_method = $_POST['payment_method'] ?? 'manual';
                $transaction_id = $_POST['transaction_id'] ?? '';
                $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
                $transaction_fees = floatval($_POST['transaction_fees'] ?? 0);
                
                // Get current invoice
                $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
                $stmt->execute([$invoice_id]);
                $invoice = $stmt->fetch();
                
                if (!$invoice) {
                    throw new Exception('Invoice not found');
                }
                
                // Insert payment transaction
                $net_amount = $amount - $transaction_fees;
                $stmt = $pdo->prepare("
                    INSERT INTO payment_transactions
                    (invoice_id, order_id, company_id, amount, net_amount, payment_method, payment_reference, transaction_date, currency, fees_amount, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')
                ");
                $stmt->execute([
                    $invoice_id,
                    $invoice['order_id'],
                    $invoice['company_id'],
                    $amount,
                    $net_amount,
                    $payment_method,
                    $transaction_id,
                    $payment_date,
                    $invoice['currency'],
                    $transaction_fees
                ]);

                // Note: Transaction fees are recorded in the payment_transactions table (fees_amount and net_amount fields)
                // If you want to track these as company expenses, create the company_expenses table

                // Update invoice paid amount
                $new_paid_amount = ($invoice['paid_amount'] ?? 0) + $amount;
                $new_status = $new_paid_amount >= $invoice['total_amount'] ? 'paid' : 'partially_paid';
                $was_unpaid = ($invoice['status'] === 'pending_payment' || $invoice['status'] === 'sent');

                // Use full datetime for paid_date (only set when fully paid)
                $paid_date_value = ($new_status === 'paid') ? date('Y-m-d H:i:s') : null;

                $stmt = $pdo->prepare("
                    UPDATE invoices
                    SET paid_amount = ?, status = ?, paid_date = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$new_paid_amount, $new_status, $paid_date_value, $invoice_id]);

                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/DiscordNotifications.php';
                $discord = new DiscordNotifications($pdo);

                // Auto-update order status and create subscriptions if invoice is fully paid
                if ($new_status === 'paid') {
                    error_log("Payment recorded - Invoice fully paid, sending Discord notification for invoice #$invoice_id");

                    // Send Discord notification for full payment
                    $result = $discord->notifyInvoicePaid($invoice_id);
                    error_log("Discord notifyInvoicePaid result: " . ($result ? 'SUCCESS' : 'FAILED'));

                    // Update linked order if exists
                    if ($invoice['order_id']) {
                        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/order-invoice-automation.php';
                        autoUpdateOrderOnInvoicePayment($invoice_id, $pdo);
                    }
                } else {
                    error_log("Payment recorded - Partial payment of $amount for invoice #$invoice_id, sending Discord notification");

                    // Send Discord notification for partial payment
                    $result = $discord->notifyInvoicePartialPayment($invoice_id, $amount);
                    error_log("Discord notifyInvoicePartialPayment result: " . ($result ? 'SUCCESS' : 'FAILED'));
                }

                echo json_encode(['success' => true, 'message' => 'Payment recorded successfully']);
                exit;
                
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            break;
            
        case 'update_invoice':
            try {
                $invoice_id = intval($_POST['invoice_id']);
                $issue_date = $_POST['issue_date'];
                $due_date = $_POST['due_date'];
                $status = $_POST['status'];
                $payment_method = $_POST['payment_method'];

                // Get current invoice status before update
                $stmt = $pdo->prepare("SELECT status, order_id, total_amount, paid_amount FROM invoices WHERE id = ?");
                $stmt->execute([$invoice_id]);
                $current_invoice = $stmt->fetch();
                $old_status = $current_invoice['status'];

                $stmt = $pdo->prepare("
                    UPDATE invoices
                    SET issue_date = ?, due_date = ?, status = ?, payment_terms = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$issue_date, $due_date, $status, $payment_method, $invoice_id]);

                // If manually marked as paid, trigger automations
                if ($status === 'paid' && $old_status !== 'paid') {
                    // Update paid_amount to match total if not already set
                    if (($current_invoice['paid_amount'] ?? 0) < $current_invoice['total_amount']) {
                        $stmt = $pdo->prepare("
                            UPDATE invoices
                            SET paid_amount = total_amount, paid_date = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$invoice_id]);
                    }

                    // Trigger order status update and subscription creation
                    if ($current_invoice['order_id']) {
                        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/order-invoice-automation.php';
                        autoUpdateOrderOnInvoicePayment($invoice_id, $pdo);
                    }

                    // Send Discord notification
                    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/DiscordNotifications.php';
                    $discord = new DiscordNotifications($pdo);
                    $discord->notifyInvoicePaid($invoice_id);
                }

                echo json_encode(['success' => true, 'message' => 'Invoice updated successfully']);
                exit;

            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            break;
            
        case 'add_credit':
            try {
                $invoice_id = intval($_POST['invoice_id']);
                $credit_amount = floatval($_POST['credit_amount']);
                
                // Get current invoice
                $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
                $stmt->execute([$invoice_id]);
                $invoice = $stmt->fetch();
                
                if (!$invoice) {
                    throw new Exception('Invoice not found');
                }
                
                // Apply credit as negative payment
                $stmt = $pdo->prepare("
                    INSERT INTO payment_transactions 
                    (invoice_id, amount, payment_method, payment_reference, transaction_date, currency, status) 
                    VALUES (?, ?, 'credit', 'Credit Applied', CURDATE(), ?, 'completed')
                ");
                $stmt->execute([$invoice_id, -$credit_amount, $invoice['currency']]);
                
                // Update invoice
                $new_paid_amount = ($invoice['paid_amount'] ?? 0) - $credit_amount;
                $new_status = $new_paid_amount >= $invoice['total_amount'] ? 'paid' : 
                            ($new_paid_amount > 0 ? 'partially_paid' : $invoice['status']);
                
                $stmt = $pdo->prepare("
                    UPDATE invoices 
                    SET paid_amount = ?, status = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ");
                $stmt->execute([$new_paid_amount, $new_status, $invoice_id]);
                
                echo json_encode(['success' => true, 'message' => 'Credit applied successfully']);
                exit;
                
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            break;
            
        case 'send_email':
            try {
                $invoice_id = intval($_POST['invoice_id']);
                $email_template = $_POST['email_template'] ?? 'Invoice Created';
                
                // Get invoice details
                $stmt = $pdo->prepare("
                    SELECT i.*, c.name as company_name, c.contact_email 
                    FROM invoices i 
                    JOIN companies c ON i.company_id = c.id 
                    WHERE i.id = ?
                ");
                $stmt->execute([$invoice_id]);
                $invoice = $stmt->fetch();
                
                if (!$invoice) {
                    throw new Exception('Invoice not found');
                }
                
                // Log email activity
                $stmt = $pdo->prepare("
                    INSERT INTO email_logs 
                    (invoice_id, email_template, recipient_email, sent_by, sent_at) 
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$invoice_id, $email_template, $invoice['contact_email'], $user_id]);
                
                // Send email logic here (implement your email sending)
                // For now, just update status if it's a creation email
                if ($email_template === 'Invoice Created' && $invoice['status'] === 'draft') {
                    $stmt = $pdo->prepare("UPDATE invoices SET status = 'sent', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$invoice_id]);
                }
                
                echo json_encode(['success' => true, 'message' => "Email sent successfully using template: {$email_template}"]);
                exit;
                
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            break;
    }
}

// Get selected filters
$selected_company_id = $_GET['company_id'] ?? '';
$currency_filter = $_GET['currency'] ?? '';

// Check if we're viewing a specific invoice
$invoice_id = intval($_GET['invoice_id'] ?? 0);
$order_id = intval($_GET['order_id'] ?? 0);

// INDIVIDUAL INVOICE VIEW
if ($invoice_id || $order_id) {
    try {
        if ($invoice_id) {
            // Load existing invoice with all financial details
            $stmt = $pdo->prepare("SELECT i.*, o.order_number, c.name as company_name, c.contact_email as company_email,
                c.address as company_address, c.phone as company_phone, c.vat_number as company_vat,
                u.username as created_by_username
                FROM invoices i
                LEFT JOIN orders o ON i.order_id = o.id
                JOIN companies c ON i.company_id = c.id
                LEFT JOIN users u ON i.created_by = u.id
                WHERE i.id = ?");
            $stmt->execute([$invoice_id]);
            $invoice = $stmt->fetch();
        } else {
            // Load by order ID
            $stmt = $pdo->prepare("SELECT i.*, o.order_number, c.name as company_name, c.contact_email as company_email,
                c.address as company_address, c.phone as company_phone, c.vat_number as company_vat,
                u.username as created_by_username
                FROM invoices i
                LEFT JOIN orders o ON i.order_id = o.id
                JOIN companies c ON i.company_id = c.id
                LEFT JOIN users u ON i.created_by = u.id
                WHERE i.order_id = ? 
                ORDER BY i.created_at DESC LIMIT 1");
            $stmt->execute([$order_id]);
            $invoice = $stmt->fetch();
        }
        
        if (!$invoice) {
            throw new Exception('Invoice not found');
        }
        
        // REPLACE THIS ENTIRE SECTION:
// Get invoice items
$stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id");
$stmt->execute([$invoice['id']]);
$invoice_items = $stmt->fetchAll();

// WITH THIS DIAGNOSTIC VERSION:
// Add this diagnostic section right after getting the invoice data and before the invoice items query

// DIAGNOSTIC: Check what's in the invoices table for this invoice
invoice_debug_log("=== INVOICE DIAGNOSTIC ===");
invoice_debug_log("Invoice ID: " . $invoice['id']);
invoice_debug_log("Invoice Number: " . $invoice['invoice_number']);
invoice_debug_log("Total Amount: " . $invoice['total_amount']);
invoice_debug_log("Currency: " . $invoice['currency']);

// Check invoice_items table structure and data
try {
    // First, let's see what columns exist in invoice_items
    $stmt = $pdo->prepare("DESCRIBE invoice_items");
    $stmt->execute();
    $columns = $stmt->fetchAll();
    invoice_debug_log("Invoice_items columns: " . print_r($columns, true));
    
    // Check if there are ANY items for this invoice
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM invoice_items WHERE invoice_id = ?");
    $stmt->execute([$invoice['id']]);
    $item_count = $stmt->fetch()['count'];
    invoice_debug_log("Invoice items count: " . $item_count);
    
    // Get all data from invoice_items for this invoice
    $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
    $stmt->execute([$invoice['id']]);
    $all_items = $stmt->fetchAll();
    invoice_debug_log("All invoice items raw data: " . print_r($all_items, true));
    
} catch (PDOException $e) {
    invoice_debug_log("Diagnostic query error: " . $e->getMessage());
}

// Enhanced invoice items query with multiple fallback strategies
$invoice_items = [];

// Strategy 1: Standard query with correct column names
try {
    $stmt = $pdo->prepare("
        SELECT 
            id,
            invoice_id,
            description,
            quantity,
            unit_price,
            total_amount,
            discount_amount,
            billing_cycle,
            billing_start_date,
            billing_end_date,
            created_at
        FROM invoice_items 
        WHERE invoice_id = ? 
        ORDER BY id ASC
    ");
    $stmt->execute([$invoice['id']]);
    $invoice_items = $stmt->fetchAll();
    invoice_debug_log("Strategy 1 - Standard query items: " . count($invoice_items));
} catch (PDOException $e) {
    invoice_debug_log("Strategy 1 failed: " . $e->getMessage());
}

// Strategy 2: If no items found, try alternative column names
if (empty($invoice_items)) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                description,
                qty as quantity,
                price as amount,
                price as unit_price,
                total as line_total,
                taxable as is_taxed
            FROM invoice_items 
            WHERE invoice_id = ? 
            ORDER BY id ASC
        ");
        $stmt->execute([$invoice['id']]);
        $invoice_items = $stmt->fetchAll();
        invoice_debug_log("Strategy 2 - Alternative columns items: " . count($invoice_items));
    } catch (PDOException $e) {
        invoice_debug_log("Strategy 2 failed: " . $e->getMessage());
    }
}

// Strategy 3: Check if items are stored in a different table
if (empty($invoice_items)) {
    try {
        // Check order_items if this invoice is linked to an order
        if (!empty($invoice['order_id'])) {
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    description,
                    quantity,
                    price as amount,
                    price as unit_price,
                    (quantity * price) as line_total,
                    0 as is_taxed
                FROM order_items 
                WHERE order_id = ? 
                ORDER BY id ASC
            ");
            $stmt->execute([$invoice['order_id']]);
            $invoice_items = $stmt->fetchAll();
            invoice_debug_log("Strategy 3 - Order items: " . count($invoice_items));
        }
    } catch (PDOException $e) {
        invoice_debug_log("Strategy 3 failed: " . $e->getMessage());
    }
}

// Strategy 4: Create items from invoice totals if still empty
if (empty($invoice_items) && ($invoice['total_amount'] ?? 0) > 0) {
    // Try to find description from invoice notes or create generic
    $description = $invoice['notes'] ?? $invoice['description'] ?? 'Service/Product';
    
    $invoice_items = [
        [
            'id' => 1,
            'description' => $description,
            'quantity' => 1,
            'amount' => $invoice['total_amount'],
            'unit_price' => $invoice['total_amount'],
            'line_total' => $invoice['total_amount'],
            'is_taxed' => 0,
            'item_code' => ''
        ]
    ];
    invoice_debug_log("Strategy 4 - Created from invoice total: " . count($invoice_items));
}

// In the diagnostic section, add this additional check for description fields
try {
    // Check what description-related fields exist and have data
    $stmt = $pdo->prepare("
        SELECT 
            id,
            description,
            item_description,
            service_description,
            product_name,
            name,
            details,
            notes,
            title,
            service_name
        FROM invoice_items 
        WHERE invoice_id = ?
    ");
    $stmt->execute([$invoice['id']]);
    $description_check = $stmt->fetchAll();
    invoice_debug_log("Description fields check: " . print_r($description_check, true));
} catch (PDOException $e) {
    invoice_debug_log("Description check failed: " . $e->getMessage());
}

// Process and normalize the items (REPLACE the existing normalization section)
$normalized_items = [];
foreach ($invoice_items as $item) {
    // Use the correct database column name
$description = $item['description'] ?? 'Service/Product';

// Don't override if we have a valid description
if (empty(trim($description))) {
    $description = 'Service/Product';
}

$normalized_item = [
    'id' => $item['id'] ?? 0,
    'description' => $description,
    'quantity' => max(1, floatval($item['quantity'] ?? 1)),
    'unit_price' => floatval($item['unit_price'] ?? 0),
    'amount' => floatval($item['total_amount'] ?? 0),
    'line_total' => floatval($item['total_amount'] ?? 0),
    'is_taxed' => boolval($item['is_taxed'] ?? 0),
    'item_code' => $item['item_code'] ?? ''
];
    
    // Calculate missing values
    if ($normalized_item['amount'] == 0 && $normalized_item['unit_price'] > 0) {
        $normalized_item['amount'] = $normalized_item['unit_price'] * $normalized_item['quantity'];
    }
    if ($normalized_item['unit_price'] == 0 && $normalized_item['amount'] > 0) {
        $normalized_item['unit_price'] = $normalized_item['amount'] / $normalized_item['quantity'];
    }
    if ($normalized_item['line_total'] == 0) {
        $normalized_item['line_total'] = $normalized_item['amount'];
    }
    
    $normalized_items[] = $normalized_item;
}

$invoice_items = $normalized_items;

// Check what financial columns actually exist in the invoices table
try {
    $stmt = $pdo->prepare("DESCRIBE invoices");
    $stmt->execute();
    $invoice_columns = $stmt->fetchAll();
    invoice_debug_log("Invoice table columns: " . print_r($invoice_columns, true));
} catch (PDOException $e) {
    invoice_debug_log("Invoice columns check failed: " . $e->getMessage());
}

// Calculate discount from the data we have
$subtotal_calculated = 0;
foreach ($invoice_items as $item) {
    $subtotal_calculated += ($item['line_total'] ?: ($item['unit_price'] * $item['quantity']));
}

$total_amount = $invoice['total_amount'];
$calculated_discount = $subtotal_calculated - $total_amount;

invoice_debug_log("Subtotal calculated: " . $subtotal_calculated);
invoice_debug_log("Total amount: " . $total_amount);
invoice_debug_log("Calculated discount: " . $calculated_discount);

// Also check if there are additional financial fields in the invoice table
try {
    $stmt = $pdo->prepare("
        SELECT 
            subtotal,
            tax_amount,
            tax_rate,
            discount_amount,
            discount_percentage,
            discount_code,
            discount_reason,
            discount_type,
            discount_applied_date,
            discount_applied_by,
            shipping_amount,
            promotion_details,
            promo_code,
            notes,
            admin_notes,
            internal_notes,
            created_by,
            updated_at
        FROM invoices 
        WHERE id = ?
    ");
    $stmt->execute([$invoice['id']]);
    $financial_details = $stmt->fetch();
    
    if ($financial_details) {
        // Merge financial details into invoice array
        $invoice = array_merge($invoice, $financial_details);
        invoice_debug_log("Financial details found: " . print_r($financial_details, true));
    } else {
        // If no financial details found, calculate from available data
        $invoice['subtotal'] = $subtotal_calculated;
        if ($calculated_discount > 0) {
            $invoice['discount_amount'] = $calculated_discount;
            $invoice['discount_percentage'] = ($calculated_discount / $subtotal_calculated) * 100;
        }
        invoice_debug_log("No financial details found, calculated discount: " . $calculated_discount);
    }
} catch (PDOException $e) {
    invoice_debug_log("Financial details query failed: " . $e->getMessage());
    // Fallback calculation
    $invoice['subtotal'] = $subtotal_calculated;
    if ($calculated_discount > 0) {
        $invoice['discount_amount'] = $calculated_discount;
        $invoice['discount_percentage'] = ($calculated_discount / $subtotal_calculated) * 100;
    }
}
        
        // Get payment history
        $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE invoice_id = ? ORDER BY transaction_date DESC");
        $stmt->execute([$invoice['id']]);
        $payment_history = $stmt->fetchAll();
        
        // Get all invoices for this company
        $stmt = $pdo->prepare("
            SELECT id, invoice_number, total_amount, currency, status, issue_date, due_date,
                   (total_amount - COALESCE(paid_amount, 0)) as outstanding_amount
            FROM invoices 
            WHERE company_id = ? 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $stmt->execute([$invoice['company_id']]);
        $company_invoices = $stmt->fetchAll();
        
        $page_title = "Invoice #" . $invoice['invoice_number'] . " | Staff Portal";
        $show_individual_invoice = true;
        
    } catch (Exception $e) {
        error_log("Invoice view error: " . $e->getMessage());
        header('Location: /operations/invoices-enhanced-complete.php?error=' . urlencode('Error loading invoice: ' . $e->getMessage()));
        exit;
    }
} 

// INVOICE MANAGEMENT VIEW
else {
    // Get comprehensive stats
    try {
        // Overall statistics
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_invoices,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN status = 'partially_paid' THEN 1 ELSE 0 END) as partially_paid_count,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                SUM(CASE WHEN auto_generated = 1 THEN 1 ELSE 0 END) as auto_generated_count,
                COALESCE(SUM(total_amount), 0) as total_value,
                COALESCE(SUM(COALESCE(paid_amount, 0)), 0) as total_paid,
                COALESCE(SUM(total_amount) - SUM(COALESCE(paid_amount, 0)), 0) as total_outstanding,
                COALESCE(SUM(CASE WHEN status IN ('sent', 'partially_paid', 'overdue') THEN total_amount - COALESCE(paid_amount, 0) ELSE 0 END), 0) as active_outstanding
            FROM invoices
        ");
        $overall_stats = $stmt->fetch() ?: [];

        // Time-based statistics
        $stmt = $pdo->query("
            SELECT 
                SUM(CASE WHEN due_date < CURDATE() AND status IN ('sent', 'partially_paid') THEN 1 ELSE 0 END) as overdue_today,
                SUM(CASE WHEN due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND status IN ('sent', 'partially_paid') THEN 1 ELSE 0 END) as due_within_7_days,
                SUM(CASE WHEN due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status IN ('sent', 'partially_paid') THEN 1 ELSE 0 END) as due_within_30_days,
                SUM(CASE WHEN issue_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as created_last_30_days,
                SUM(CASE WHEN paid_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as paid_last_30_days,
                SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND status IN ('sent', 'partially_paid') THEN 1 ELSE 0 END) as overdue_30_plus_days,
                SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND status IN ('sent', 'partially_paid') THEN 1 ELSE 0 END) as overdue_60_plus_days,
                SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND status IN ('sent', 'partially_paid') THEN 1 ELSE 0 END) as overdue_90_plus_days
            FROM invoices
        ");
        $time_stats = $stmt->fetch() ?: [];

        // Currency breakdown
        $stmt = $pdo->query("
            SELECT 
                currency,
                COUNT(*) as count,
                COALESCE(SUM(total_amount), 0) as total_value,
                COALESCE(SUM(COALESCE(paid_amount, 0)), 0) as total_paid,
                COALESCE(SUM(total_amount) - SUM(COALESCE(paid_amount, 0)), 0) as outstanding,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count
            FROM invoices
            GROUP BY currency
            ORDER BY total_value DESC
        ");
        $currency_stats = $stmt->fetchAll() ?: [];

        // Recent activity
        $stmt = $pdo->query("
            SELECT i.*, c.name as company_name, u.username as created_by_username,
                   DATEDIFF(CURDATE(), i.due_date) as days_overdue
            FROM invoices i
            JOIN companies c ON i.company_id = c.id
            LEFT JOIN users u ON i.created_by = u.id
            ORDER BY i.updated_at DESC
            LIMIT 10
        ");
        $recent_activity = $stmt->fetchAll() ?: [];

        // Overdue invoices requiring attention
        $stmt = $pdo->query("
            SELECT i.*, c.name as company_name,
                   DATEDIFF(CURDATE(), i.due_date) as days_overdue,
                   (i.total_amount - COALESCE(i.paid_amount, 0)) as outstanding_amount
            FROM invoices i
            JOIN companies c ON i.company_id = c.id
            WHERE i.status IN ('sent', 'partially_paid', 'overdue')
            AND i.due_date < CURDATE()
            ORDER BY i.due_date ASC
            LIMIT 15
        ");
        $overdue_invoices = $stmt->fetchAll() ?: [];

        // Filters for list view
        $status_filter = $_GET['status'] ?? 'all';
        $date_from = $_GET['date_from'] ?? '';
        $date_to = $_GET['date_to'] ?? '';
        $search = $_GET['search'] ?? '';
        $page = max(1, intval($_GET['page'] ?? 1));
        $per_page = 50;
        $offset = ($page - 1) * $per_page;

        // Build query conditions
        $where_conditions = [];
        $params = [];

        if ($selected_company_id !== '') {
            $where_conditions[] = "i.company_id = ?";
            $params[] = $selected_company_id;
        }

        if ($currency_filter !== '') {
            $where_conditions[] = "i.currency = ?";
            $params[] = $currency_filter;
        }

        if ($status_filter !== 'all') {
            if ($status_filter === 'outstanding') {
                $where_conditions[] = "i.status IN ('sent', 'partially_paid', 'overdue')";
            } else {
                $where_conditions[] = "i.status = ?";
                $params[] = $status_filter;
            }
        }

        if ($date_from) {
            $where_conditions[] = "i.issue_date >= ?";
            $params[] = $date_from;
        }

        if ($date_to) {
            $where_conditions[] = "i.issue_date <= ?";
            $params[] = $date_to;
        }

        if ($search) {
            $where_conditions[] = "(i.invoice_number LIKE ? OR COALESCE(o.order_number, '') LIKE ? OR COALESCE(i.notes, '') LIKE ? OR c.name LIKE ? OR COALESCE(u.username, '') LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $where_sql = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

        // Get filtered invoices count
        $count_query = "SELECT COUNT(*) as total 
                        FROM invoices i 
                        LEFT JOIN orders o ON i.order_id = o.id 
                        JOIN companies c ON i.company_id = c.id
                        LEFT JOIN users u ON i.created_by = u.id
                        {$where_sql}";
        
        $stmt = $pdo->prepare($count_query);
        $stmt->execute($params);
        $total_count = $stmt->fetch()['total'];

        // Get filtered invoices
        $query = "SELECT i.*, o.order_number, c.name as company_name, u.username as created_by_username,
                         DATEDIFF(CURDATE(), i.due_date) as days_overdue,
                         (i.total_amount - COALESCE(i.paid_amount, 0)) as outstanding_amount
                  FROM invoices i 
                  LEFT JOIN orders o ON i.order_id = o.id 
                  JOIN companies c ON i.company_id = c.id
                  LEFT JOIN users u ON i.created_by = u.id
                  {$where_sql}
                  ORDER BY i.created_at DESC 
                  LIMIT {$per_page} OFFSET {$offset}";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll();

        $total_pages = ceil($total_count / $per_page);
        $page_title = "Invoice Management | Staff Portal";
        $show_individual_invoice = false;

    } catch (PDOException $e) {
        error_log("Invoice management query error: " . $e->getMessage());
        
        // Initialize with empty arrays to prevent PHP errors
        $overall_stats = [
            'total_invoices' => 0,
            'draft_count' => 0,
            'sent_count' => 0,
            'paid_count' => 0,
            'partially_paid_count' => 0,
            'overdue_count' => 0,
            'cancelled_count' => 0,
            'auto_generated_count' => 0,
            'total_value' => 0,
            'total_paid' => 0,
            'total_outstanding' => 0,
            'active_outstanding' => 0
        ];
        $time_stats = [
            'overdue_today' => 0,
            'due_within_7_days' => 0,
            'due_within_30_days' => 0,
            'created_last_30_days' => 0,
            'paid_last_30_days' => 0,
            'overdue_30_plus_days' => 0,
            'overdue_60_plus_days' => 0,
            'overdue_90_plus_days' => 0
        ];
        $currency_stats = [];
        $recent_activity = [];
        $overdue_invoices = [];
        $invoices = [];
        $total_count = 0;
        $show_individual_invoice = false;
        
        // Show error to user
        $_GET['error'] = 'Database error: ' . $e->getMessage();
    }
}

function formatCurrency($amount, $currency = 'GBP') {
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
    $symbol = $symbols[$currency] ?? $currency . ' ';
    return $symbol . number_format($amount, 2);
}

function getStatusBadgeClass($status) {
    $classes = [
        'draft' => 'secondary',
        'sent' => 'primary',
        'paid' => 'success',
        'overdue' => 'danger',
        'cancelled' => 'warning',
        'partially_paid' => 'info'
    ];
    return $classes[$status] ?? 'secondary';
}

function getUrgencyClass($days_overdue) {
    if ($days_overdue >= 90) return 'danger';
    if ($days_overdue >= 60) return 'warning';
    if ($days_overdue >= 30) return 'info';
    if ($days_overdue >= 0) return 'primary';
    return 'secondary';
}

function buildFilterUrl($params) {
    $current_params = $_GET;
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($current_params[$key]);
        } else {
            $current_params[$key] = $value;
        }
    }
    unset($current_params['page']); // Reset pagination
    return '?' . http_build_query($current_params);
}

include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php';
?>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --border-radius: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }

        /* Enhanced Hero Section */
        .hero-enhanced {
            background: var(--primary-gradient);
            position: relative;
            overflow: hidden;
            min-height: 35vh;
            display: flex;
            align-items: center;
        }

        .hero-enhanced::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 30% 50%, rgba(255,255,255,0.1) 0%, transparent 50%);
        }

        .hero-content-enhanced {
            position: relative;
            z-index: 2;
            text-align: center;
            padding: 2rem 0;
        }

        .hero-title-enhanced {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
            color: white;
        }

        .hero-subtitle-enhanced {
            font-size: 1.125rem;
            margin-bottom: 1.5rem;
            opacity: 0.9;
            color: white;
        }

        .quick-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 2rem;
        }

        .quick-action-btn {
            background: rgba(255,255,255,0.2);
            border: 2px solid rgba(255,255,255,0.3);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }

        .quick-action-btn:hover {
            background: rgba(255,255,255,0.3);
            border-color: rgba(255,255,255,0.5);
            color: white;
            transform: translateY(-2px);
        }

        /* Enhanced Cards */
        .enhanced-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            border: none;
            overflow: hidden;
            position: relative;
            margin-bottom: 2rem;
        }

        .enhanced-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .card-header-enhanced {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 1px solid rgba(102, 126, 234, 0.1);
            padding: 1.5rem;
        }

        .card-title-enhanced {
            font-size: 1.25rem;
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-body-enhanced {
            padding: 1.5rem;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            text-align: center;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            opacity: 0;
            transition: var(--transition);
        }

        .stat-card:hover::before {
            opacity: 0.05;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card.primary::before { background: var(--primary-gradient); }
        .stat-card.success::before { background: var(--success-gradient); }
        .stat-card.warning::before { background: var(--warning-gradient); }
        .stat-card.danger::before { background: var(--danger-gradient); }
        .stat-card.info::before { background: var(--info-gradient); }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            position: relative;
            z-index: 1;
        }

        .stat-icon.primary { color: #667eea; }
        .stat-icon.success { color: #28a745; }
        .stat-icon.warning { color: #ffc107; }
        .stat-icon.danger { color: #dc3545; }
        .stat-icon.info { color: #17a2b8; }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .stat-number.primary { color: #667eea; }
        .stat-number.success { color: #28a745; }
        .stat-number.warning { color: #ffc107; }
        .stat-number.danger { color: #dc3545; }
        .stat-number.info { color: #17a2b8; }

        .stat-label {
            color: #6c757d;
            font-weight: 500;
            position: relative;
            z-index: 1;
            font-size: 0.9rem;
        }

        .stat-sublabel {
            color: #adb5bd;
            font-size: 0.8rem;
            margin-top: 0.25rem;
            position: relative;
            z-index: 1;
        }

        /* Activity Cards */
        .activity-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }

        .activity-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: var(--transition);
        }

        .activity-item:hover {
            background: #f8f9fa;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            margin-right: 1rem;
        }

        .activity-details h6 {
            margin: 0;
            font-weight: 600;
            color: #2c3e50;
        }

        .activity-details small {
            color: #6c757d;
        }

        .activity-meta {
            text-align: right;
        }

        .activity-meta .badge {
            margin-bottom: 0.25rem;
        }

        .activity-meta small {
            color: #6c757d;
        }

        /* Enhanced Table */
        .table-enhanced {
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            border: none !important;
            margin-bottom: 0 !important;
        }

        .table-enhanced thead {
            background: var(--primary-gradient) !important;
        }

        .table-enhanced thead th {
            background: transparent !important;
            color: white !important;
            font-weight: 600 !important;
            border: none !important;
            padding: 1rem !important;
            text-shadow: 0 1px 3px rgba(0,0,0,0.3) !important;
        }

        .table-enhanced tbody td {
            padding: 1rem !important;
            border-color: rgba(0,0,0,0.05) !important;
            vertical-align: middle !important;
        }

        .table-enhanced tbody tr:hover td {
            background: rgba(102, 126, 234, 0.05) !important;
        }

        /* Enhanced Currency Breakdown */
        .currency-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .currency-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .currency-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .currency-amount {
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            padding: 2px 6px;
            border-radius: 4px;
        }

        .currency-amount:hover {
            background: rgba(102, 126, 234, 0.1);
            transform: scale(1.05);
        }

        .currency-amount.outstanding:hover {
            background: rgba(220, 53, 69, 0.1);
        }

        .currency-amount.paid:hover {
            background: rgba(40, 167, 69, 0.1);
        }

        /* Enhanced filters and forms */
        .filter-form {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }

        .form-control-enhanced {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            transition: var(--transition);
        }

        .form-control-enhanced:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .btn-enhanced {
            border-radius: 50px;
            padding: 0.5rem 1.5rem;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary-enhanced {
            background: var(--primary-gradient);
            color: white;
        }

        .btn-success-enhanced {
            background: var(--success-gradient);
            color: white;
        }

        .btn-warning-enhanced {
            background: var(--warning-gradient);
            color: white;
        }

        .btn-danger-enhanced {
            background: var(--danger-gradient);
            color: white;
        }

        .btn-outline-enhanced {
            border: 2px solid #667eea;
            color: #667eea;
            background: transparent;
        }

        /* Tab enhancements */
        .nav-tabs-enhanced {
            border: none;
            background: #f8f9fa;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            padding: 0.5rem 1rem 0;
        }

        .nav-tabs-enhanced .nav-link {
            border: none;
            background: transparent;
            color: #6c757d;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            margin-right: 0.5rem;
            border-radius: 8px 8px 0 0;
            transition: var(--transition);
        }

        .nav-tabs-enhanced .nav-link:hover {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
        }

        .nav-tabs-enhanced .nav-link.active {
            background: white;
            color: #667eea;
            border-bottom: 3px solid #667eea;
        }

        .tab-content {
            background: white;
            padding: 2rem;
            border-radius: 0 0 var(--border-radius) var(--border-radius);
        }

        /* Invoice specific styles */
        .invoice-items-table {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
        }

        .invoice-items-table thead {
            background: #f8f9fa;
        }

        .invoice-items-table th,
        .invoice-items-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #dee2e6;
        }

        .invoice-summary {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1rem 0;
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
        }

        .status-paid { background: #28a745; }
        .status-unpaid { background: #dc3545; }
        .status-partial { background: #ffc107; }
        .status-draft { background: #6c757d; }

        .company-invoices-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .company-invoice-item {
            padding: 0.5rem;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .company-invoice-item:last-child {
            border-bottom: none;
        }

        .transaction-fee-note {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 0.75rem;
            margin-top: 0.5rem;
            font-size: 0.875rem;
        }

        .currency-converter {
            background: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }

        /* Priority indicators */
        .priority-high {
            border-left: 4px solid #dc3545;
        }

        .priority-medium {
            border-left: 4px solid #ffc107;
        }

        .priority-low {
            border-left: 4px solid #28a745;
        }

        /* Filter badges */
        .filter-badge {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.875rem;
            margin: 0.25rem;
            display: inline-block;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero-title-enhanced {
                font-size: 2rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
            
            .quick-actions {
                flex-direction: column;
                align-items: center;
            }
            
            .activity-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .activity-meta {
                text-align: left;
            }
        }

        /* Breadcrumb */
        .breadcrumb-enhanced {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
        }
        
        /* Timeline Styles */
        .timeline-container {
            position: relative;
            padding: 1rem 0;
        }
        
        .timeline-item {
            position: relative;
            padding-left: 3rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
        }
        
        .timeline-item:not(:last-child)::before {
            content: '';
            position: absolute;
            left: 1.25rem;
            top: 2.5rem;
            bottom: -1.5rem;
            width: 2px;
            background: #dee2e6;
            z-index: 1;
        }
        
        .timeline-marker {
            position: absolute;
            left: 0;
            top: 0;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
            z-index: 2;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .timeline-content {
            flex: 1;
            background: white;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 1px solid #e9ecef;
            margin-left: 0.5rem;
        }
        
        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .timeline-amount {
            font-size: 1.25rem;
            font-weight: 700;
        }
        
        .timeline-date {
            font-size: 0.875rem;
        }
        
        .timeline-description {
            margin-bottom: 0.25rem;
        }
        
        .timeline-details {
            font-size: 0.875rem;
        }
        
        .timeline-summary .timeline-content {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
        }
        
        .timeline-summary .timeline-marker {
            border: 3px solid white;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .timeline-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .timeline-amount {
                font-size: 1.1rem;
            }
        }
        
        /* Fix container height issues */
        .container {
            min-height: auto !important;
        }
        
        .enhanced-card {
            margin-bottom: 1rem;
        }
        
        .timeline-container {
            margin-bottom: 2rem;
        }
        
        /* Ensure proper spacing */
        .tab-content {
            min-height: auto;
            padding-bottom: 2rem;
        }

        /* Dark Mode Styles */
        :root.dark body {
            background: #0f172a !important;
        }

        :root.dark .enhanced-card,
        :root.dark .card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .card-header {
            background: #1e293b !important;
            border-bottom-color: #334155 !important;
            color: #f1f5f9 !important;
        }

        :root.dark .card-body {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .stats-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .stats-value {
            color: #f1f5f9 !important;
        }

        :root.dark .stats-label {
            color: #94a3b8 !important;
        }

        :root.dark .filters-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .form-label {
            color: #cbd5e1 !important;
        }

        :root.dark .form-control,
        :root.dark .form-select {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .form-control:focus,
        :root.dark .form-select:focus {
            background: #1e293b !important;
            border-color: #8b5cf6 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .table {
            color: #e2e8f0 !important;
        }

        :root.dark .table thead th {
            background: #0f172a !important;
            color: #f1f5f9 !important;
            border-color: #334155 !important;
        }

        :root.dark .table tbody tr {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .table tbody tr:hover {
            background: #0f172a !important;
        }

        :root.dark .table td {
            color: #e2e8f0 !important;
            border-color: #334155 !important;
        }

        :root.dark .invoice-number {
            color: #f1f5f9 !important;
        }

        :root.dark .invoice-company {
            color: #94a3b8 !important;
        }

        :root.dark .timeline-content {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .timeline-header h4 {
            color: #f1f5f9 !important;
        }

        :root.dark .timeline-details,
        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark .nav-tabs .nav-link {
            color: #cbd5e1 !important;
        }

        :root.dark .nav-tabs .nav-link.active {
            background: #1e293b !important;
            color: #f1f5f9 !important;
            border-color: #334155 #334155 #1e293b !important;
        }

        :root.dark h1,
        :root.dark h2,
        :root.dark h3,
        :root.dark h4,
        :root.dark h5,
        :root.dark h6 {
            color: #f1f5f9 !important;
        }

        :root.dark .breadcrumb {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .breadcrumb-item {
            color: #94a3b8 !important;
        }

        :root.dark .breadcrumb-item.active {
            color: #e2e8f0 !important;
        }

        :root.dark .card-header-enhanced,
        :root.dark .stat-card {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .card-title-enhanced {
            color: #f1f5f9 !important;
        }

        :root.dark .stat-card::before {
            opacity: 0.1 !important;
        }

        :root.dark .quick-actions {
            background: transparent !important;
        }

        :root.dark .modal-content {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .modal-header,
        :root.dark .modal-footer {
            border-color: #334155 !important;
        }

        :root.dark .modal-title {
            color: #f1f5f9 !important;
        }

        :root.dark .btn-close {
            filter: invert(1);
        }
    </style>

<?php if ($show_individual_invoice): ?>
<!-- INDIVIDUAL INVOICE VIEW -->
<div class="container py-4">
    <!-- Action Bar -->
    <div class="row no-print mb-3">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <a href="/operations/invoices-enhanced-complete.php" class="btn btn-outline-enhanced">
                    <i class="bi bi-arrow-left"></i>
                    Back to Invoice Management
                </a>
                <div class="d-flex gap-2">
                    <a href="?company_id=<?= $invoice['company_id'] ?>" class="btn btn-outline-enhanced">
                        <i class="bi bi-building me-2"></i>
                        View All Customer Invoices
                    </a>
                    <button onclick="window.print()" class="btn btn-outline-enhanced">
                        <i class="bi bi-printer"></i>
                        Print
                    </button>
                    <a href="/includes/api/generate-pdf.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-outline-enhanced">
                        <i class="bi bi-file-pdf"></i>
                        Download PDF
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="enhanced-card">
        <div class="card-header-enhanced">
            <h5 class="card-title-enhanced">
                <i class="bi bi-receipt"></i>
                Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?>
                <span class="badge bg-<?= getStatusBadgeClass($invoice['status']) ?> ms-2">
                    <span class="status-indicator status-<?= $invoice['status'] === 'paid' ? 'paid' : ($invoice['status'] === 'partially_paid' ? 'partial' : 'unpaid') ?>"></span>
                    <?= ucfirst(str_replace('_', ' ', $invoice['status'])) ?>
                </span>
                <button class="btn btn-sm btn-outline-primary ms-auto" onclick="showCurrencyConverter()">
                    <i class="bi bi-arrow-repeat me-1"></i>
                    Change Currency (<?= strtoupper($invoice['currency']) ?>)
                </button>
            </h5>
        </div>

        <!-- Currency Converter Modal/Section -->
        <div id="currencyConverter" class="currency-converter d-none">
            <h6><i class="bi bi-currency-exchange me-2"></i>Change Invoice Currency</h6>
            <form id="currencyForm">
                <input type="hidden" name="action" value="change_currency">
                <input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>">
                
                <div class="row">
                    <div class="col-md-4">
                        <label class="form-label">New Currency</label>
                        <select name="new_currency" class="form-control form-control-enhanced" required>
                            <option value="GBP" <?= $invoice['currency'] === 'GBP' ? 'selected' : '' ?>>GBP - British Pound</option>
                            <option value="EUR" <?= $invoice['currency'] === 'EUR' ? 'selected' : '' ?>>EUR - Euro</option>
                            <option value="USD" <?= $invoice['currency'] === 'USD' ? 'selected' : '' ?>>USD - US Dollar</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Exchange Rate</label>
                        <input type="number" name="exchange_rate" step="0.0001" class="form-control form-control-enhanced" value="1.0000" required>
                        <small class="text-muted">Multiply current amounts by this rate</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-warning-enhanced">
                                <i class="bi bi-arrow-repeat me-2"></i>Convert Currency
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Enhanced Tab Navigation -->
        <ul class="nav nav-tabs nav-tabs-enhanced" id="invoiceTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="summary-tab" data-bs-toggle="tab" data-bs-target="#summary" type="button" role="tab">
                    <i class="bi bi-file-text me-2"></i>Summary
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="payment-tab" data-bs-toggle="tab" data-bs-target="#payment" type="button" role="tab">
                    <i class="bi bi-credit-card me-2"></i>Add Payment
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="options-tab" data-bs-toggle="tab" data-bs-target="#options" type="button" role="tab">
                    <i class="bi bi-gear me-2"></i>Options
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="credit-tab" data-bs-toggle="tab" data-bs-target="#credit" type="button" role="tab">
                    <i class="bi bi-plus-circle me-2"></i>Credit
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="email-tab" data-bs-toggle="tab" data-bs-target="#email" type="button" role="tab">
                    <i class="bi bi-envelope me-2"></i>Send Email
                </button>
            </li>
        </ul>

        <div class="tab-content" id="invoiceTabsContent">
            <!-- Summary Tab -->
            <div class="tab-pane fade show active" id="summary" role="tabpanel">
                <div class="row">
                    <div class="col-md-6">
                        <div class="invoice-summary">
                            <h6><i class="bi bi-info-circle me-2"></i>Invoice Information</h6>
                            <table class="table table-borderless table-sm">
                                <tr>
                                    <td><strong>Client Name:</strong></td>
                                    <td>
                                        <?= htmlspecialchars($invoice['company_name']) ?>
                                        <a href="?company_id=<?= $invoice['company_id'] ?>" class="text-decoration-none ms-2" title="View All Invoices">
                                            <i class="bi bi-eye text-primary"></i>
                                        </a>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Invoice Date:</strong></td>
                                    <td><?= date('d/m/Y', strtotime($invoice['issue_date'])) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Due Date:</strong></td>
                                    <td><?= date('d/m/Y', strtotime($invoice['due_date'])) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Invoice Amount:</strong></td>
                                    <td><strong><?= formatCurrency($invoice['total_amount'], $invoice['currency']) ?></strong></td>
                                </tr>
                                <tr>
                                    <td><strong>Balance:</strong></td>
                                    <td class="<?= ($invoice['total_amount'] - ($invoice['paid_amount'] ?? 0)) > 0 ? 'text-danger' : 'text-success' ?>">
                                        <strong><?= formatCurrency($invoice['total_amount'] - ($invoice['paid_amount'] ?? 0), $invoice['currency']) ?></strong>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="invoice-summary">
                            <h6><i class="bi bi-credit-card me-2"></i>Payment Information</h6>
                            <div class="text-center mb-3">
                                <h3 class="<?= $invoice['status'] === 'paid' ? 'text-success' : 'text-danger' ?>">
                                    <?= strtoupper($invoice['status'] === 'paid' ? 'PAID' : 'UNPAID') ?>
                                </h3>
                                <p class="text-muted">Payment Method: <?= htmlspecialchars($invoice['payment_terms'] ?? 'Not specified') ?></p>
                            </div>
                            
                            <?php if ($invoice['status'] !== 'paid'): ?>
                            <div class="d-grid gap-2">
                                <button class="btn btn-success-enhanced" onclick="showPaymentTab()">
                                    <i class="bi bi-plus-circle me-2"></i>Record Payment
                                </button>
                                <button class="btn btn-primary-enhanced" onclick="showEmailTab()">
                                    <i class="bi bi-envelope me-2"></i>Send Email
                                </button>
                                <button class="btn btn-warning-enhanced" onclick="showCreditTab()">
                                    <i class="bi bi-credit-card me-2"></i>Apply Credit
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Company Invoices Summary -->
                <?php if (!empty($company_invoices)): ?>
                <div class="company-invoices-card">
                    <h6><i class="bi bi-building me-2"></i>All Invoices for <?= htmlspecialchars($invoice['company_name']) ?></h6>
                    <?php foreach ($company_invoices as $comp_inv): ?>
                    <div class="company-invoice-item">
                        <div>
                            <a href="?invoice_id=<?= $comp_inv['id'] ?>" class="text-decoration-none">
                                <strong><?= htmlspecialchars($comp_inv['invoice_number']) ?></strong>
                            </a>
                            <span class="badge bg-<?= getStatusBadgeClass($comp_inv['status']) ?> ms-2">
                                <?= ucfirst($comp_inv['status']) ?>
                            </span>
                        </div>
                        <div class="text-end">
                            <div><?= formatCurrency($comp_inv['total_amount'], $comp_inv['currency']) ?></div>
                            <?php if ($comp_inv['outstanding_amount'] > 0): ?>
                            <small class="text-danger">Outstanding: <?= formatCurrency($comp_inv['outstanding_amount'], $comp_inv['currency']) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="text-center mt-2">
                        <a href="?company_id=<?= $invoice['company_id'] ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-eye me-1"></i>View All Customer Invoices
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Enhanced Invoice Items Section with Better Data Handling -->
<h6 class="mt-4"><i class="bi bi-list-ul me-2"></i>Invoice Items</h6>
<div class="table-responsive">
    <table class="table invoice-items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th>Quantity</th>
                <th>Unit Price</th>
                <th>Line Total</th>
                <th>Taxed</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($invoice_items)): ?>
                <?php 
                $subtotal_calculated = 0;
                foreach ($invoice_items as $item): 
                    $line_total = $item['line_total'] ?: ($item['unit_price'] * $item['quantity']);
                    $subtotal_calculated += $line_total;
                ?>
                <tr>
                    <td>
                        <strong>
                            <?= !empty(trim($item['description'])) ? htmlspecialchars($item['description']) : 'Service/Product - ' . formatCurrency($item['line_total'], $invoice['currency']) ?>
                        </strong>
                        <?php if (!empty($item['item_code'])): ?>
                        <br><small class="text-muted">Code: <?= htmlspecialchars($item['item_code']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= number_format($item['quantity'], 0) ?></td>
                    <td class="text-end"><?= formatCurrency($item['unit_price'], $invoice['currency']) ?></td>
                    <td class="text-end"><strong><?= formatCurrency($line_total, $invoice['currency']) ?></strong></td>
                    <td class="text-center">
                        <?php if ($item['is_taxed']): ?>
                        <i class="bi bi-check-circle text-success" title="Taxed"></i>
                        <?php else: ?>
                        <i class="bi bi-x-circle text-muted" title="Not taxed"></i>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        <i class="bi bi-inbox display-6 text-warning"></i>
                        <p class="mt-2">No detailed line items found</p>
                        <small>This invoice may have been created without itemized billing</small>
                    </td>
                </tr>
                <!-- Still show a summary row based on invoice total -->
                <tr class="table-info">
                    <td><strong>Invoice Total</strong></td>
                    <td class="text-center">1</td>
                    <td class="text-end"><?= formatCurrency($invoice['total_amount'], $invoice['currency']) ?></td>
                    <td class="text-end"><strong><?= formatCurrency($invoice['total_amount'], $invoice['currency']) ?></strong></td>
                    <td class="text-center">-</td>
                </tr>
            <?php endif; ?>
        </tbody>
        <tfoot class="table-dark">
            <!-- Subtotal Row -->
            <tr>
                <th colspan="3">Subtotal:</th>
                <th class="text-end"><?= formatCurrency($subtotal_calculated ?: ($invoice['subtotal'] ?? $invoice['total_amount']), $invoice['currency']) ?></th>
                <th></th>
            </tr>
            
            <!-- Tax Row (if applicable) -->
            <?php 
            $tax_amount = ($invoice['tax_amount'] ?? 0);
            $tax_rate = ($invoice['tax_rate'] ?? 0);
            if ($tax_amount > 0): 
            ?>
            <tr>
                <th colspan="3">Tax (<?= number_format($tax_rate, 1) ?>%):</th>
                <th class="text-end"><?= formatCurrency($tax_amount, $invoice['currency']) ?></th>
                <th></th>
            </tr>
            <?php endif; ?>
            
            <!-- Discount Row (if applicable) -->
                <?php 
                $discount_amount = ($invoice['discount_amount'] ?? 0);
                $discount_percentage = ($invoice['discount_percentage'] ?? 0);
                
                // If no discount amount but we have a difference, calculate it
                if ($discount_amount <= 0) {
                    $calculated_subtotal = 0;
                    foreach ($invoice_items as $item) {
                        $calculated_subtotal += ($item['line_total'] ?: ($item['unit_price'] * $item['quantity']));
                    }
                    $discount_amount = $calculated_subtotal - $invoice['total_amount'];
                    if ($discount_amount > 0) {
                        $discount_percentage = ($discount_amount / $calculated_subtotal) * 100;
                    }
                }
                
                if ($discount_amount > 0): 
                ?>
                <tr class="text-success">
                    <th colspan="3">
                        Discount Applied
                        <?php if ($discount_percentage > 0): ?>
                        (<?= number_format($discount_percentage, 1) ?>%)
                        <?php endif; ?>
                        <?php 
                        // Show discount reason/code if available
                        $discount_info = [];
                        if (!empty($invoice['discount_code'])) {
                            $discount_info[] = "Code: " . $invoice['discount_code'];
                        }
                        if (!empty($invoice['promotion_details'])) {
                            $discount_info[] = $invoice['promotion_details'];
                        }
                        if (!empty($invoice['discount_reason'])) {
                            $discount_info[] = $invoice['discount_reason'];
                        }
                        if (!empty($invoice['notes']) && stripos($invoice['notes'], 'discount') !== false) {
                            $discount_info[] = "See notes";
                        }
                        
                        if (!empty($discount_info)): ?>
                        <br><small class="text-muted" style="font-weight: normal;"><?= htmlspecialchars(implode(' | ', $discount_info)) ?></small>
                        <?php endif; ?>:
                    </th>
                    <th class="text-end">-<?= formatCurrency($discount_amount, $invoice['currency']) ?></th>
                    <th></th>
                </tr>
                <?php endif; ?>
            
            <!-- Credit Applied Row (if applicable) -->
            <?php 
            $credit_applied = 0;
            if (!empty($payment_history)) {
                foreach ($payment_history as $payment) {
                    if ($payment['payment_method'] === 'credit' && $payment['amount'] < 0) {
                        $credit_applied += abs($payment['amount']);
                    }
                }
            }
            if ($credit_applied > 0): 
            ?>
            <tr class="text-info">
                <th colspan="3">Credit Applied:</th>
                <th class="text-end">-<?= formatCurrency($credit_applied, $invoice['currency']) ?></th>
                <th></th>
            </tr>
            <?php endif; ?>
            
            <!-- Shipping/Fees Row (if applicable) -->
            <?php 
            $shipping_amount = ($invoice['shipping_amount'] ?? 0);
            if ($shipping_amount > 0): 
            ?>
            <tr>
                <th colspan="3">Shipping & Handling:</th>
                <th class="text-end"><?= formatCurrency($shipping_amount, $invoice['currency']) ?></th>
                <th></th>
            </tr>
            <?php endif; ?>
            
            <!-- Total Due Row -->
            <tr class="border-top border-3">
                <th colspan="3"><strong>Total Due:</strong></th>
                <th class="text-end"><strong><?= formatCurrency($invoice['total_amount'], $invoice['currency']) ?></strong></th>
                <th></th>
            </tr>
            
            <!-- Amount Paid Row (if applicable) -->
            <?php if (($invoice['paid_amount'] ?? 0) > 0): ?>
            <tr class="text-success">
                <th colspan="3">Amount Paid:</th>
                <th class="text-end">-<?= formatCurrency($invoice['paid_amount'], $invoice['currency']) ?></th>
                <th></th>
            </tr>
            
            <!-- Outstanding Balance Row -->
            <tr class="<?= ($invoice['total_amount'] - ($invoice['paid_amount'] ?? 0)) > 0 ? 'text-danger' : 'text-success' ?>">
                <th colspan="3"><strong>Outstanding Balance:</strong></th>
                <th class="text-end"><strong><?= formatCurrency($invoice['total_amount'] - ($invoice['paid_amount'] ?? 0), $invoice['currency']) ?></strong></th>
                <th></th>
            </tr>
            <?php endif; ?>
        </tfoot>
    </table>
</div>

<!-- Data Source Information (for debugging - remove in production) -->
<?php if (!empty($invoice_items)): ?>
<div class="alert alert-info mt-2">
    <small><i class="bi bi-info-circle me-1"></i>
    Showing <?= count($invoice_items) ?> line item(s) | 
    Data source: <?= empty($all_items) ? 'Generated from invoice total' : 'Invoice items table' ?> | 
    Calculated subtotal: <?= formatCurrency($subtotal_calculated, $invoice['currency']) ?>
    </small>
</div>
<?php endif; ?>

                <!-- Enhanced Invoice Summary with Breakdown -->
                <?php if ($discount_amount > 0 || $credit_applied > 0 || $tax_amount > 0): ?>
                <div class="invoice-summary mt-4">
                    <h6><i class="bi bi-calculator me-2"></i>Invoice Breakdown</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td><strong>Original Amount:</strong></td>
                                    <td class="text-end"><?= formatCurrency(($invoice['subtotal'] ?? 0) + ($discount_amount ?? 0), $invoice['currency']) ?></td>
                                </tr>
                                
                                <?php if ($discount_amount > 0): ?>
                                <tr class="text-success">
                                    <td>Discount Applied:</td>
                                    <td class="text-end">-<?= formatCurrency($discount_amount, $invoice['currency']) ?></td>
                                </tr>
                                <?php endif; ?>
                                
                                <tr>
                                    <td>Subtotal:</td>
                                    <td class="text-end"><?= formatCurrency($invoice['subtotal'] ?? $invoice['total_amount'], $invoice['currency']) ?></td>
                                </tr>
                                
                                <?php if ($tax_amount > 0): ?>
                                <tr>
                                    <td>Tax (<?= number_format($tax_rate, 1) ?>%):</td>
                                    <td class="text-end"><?= formatCurrency($tax_amount, $invoice['currency']) ?></td>
                                </tr>
                                <?php endif; ?>
                                
                                <?php if ($shipping_amount > 0): ?>
                                <tr>
                                    <td>Shipping:</td>
                                    <td class="text-end"><?= formatCurrency($shipping_amount, $invoice['currency']) ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                        
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless">
                                <tr class="border-bottom">
                                    <td><strong>Total Due:</strong></td>
                                    <td class="text-end"><strong><?= formatCurrency($invoice['total_amount'], $invoice['currency']) ?></strong></td>
                                </tr>
                                
                                <?php if ($credit_applied > 0): ?>
                                <tr class="text-info">
                                    <td>Credits Applied:</td>
                                    <td class="text-end">-<?= formatCurrency($credit_applied, $invoice['currency']) ?></td>
                                </tr>
                                <?php endif; ?>
                                
                                <?php if (($invoice['paid_amount'] ?? 0) > 0): ?>
                                <tr class="text-success">
                                    <td>Payments Received:</td>
                                    <td class="text-end">-<?= formatCurrency($invoice['paid_amount'], $invoice['currency']) ?></td>
                                </tr>
                                <?php endif; ?>
                                
                                <tr class="border-top border-2 <?= ($invoice['total_amount'] - ($invoice['paid_amount'] ?? 0)) > 0 ? 'text-danger' : 'text-success' ?>">
                                    <td><strong>Outstanding Balance:</strong></td>
                                    <td class="text-end"><strong><?= formatCurrency($invoice['total_amount'] - ($invoice['paid_amount'] ?? 0), $invoice['currency']) ?></strong></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Discount/Promotion Details (if applicable) -->
                <?php if (!empty($invoice['discount_code']) || !empty($invoice['promotion_details'])): ?>
                <div class="alert alert-success mt-3">
                    <h6><i class="bi bi-tag me-2"></i>Applied Discounts & Promotions</h6>
                    
                    <?php if (!empty($invoice['discount_code'])): ?>
                    <p class="mb-1">
                        <strong>Discount Code:</strong> 
                        <code><?= htmlspecialchars($invoice['discount_code']) ?></code>
                        <?php if ($discount_percentage > 0): ?>
                        (<?= number_format($discount_percentage, 1) ?>% off)
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($invoice['promotion_details'])): ?>
                    <p class="mb-1">
                        <strong>Promotion:</strong> <?= htmlspecialchars($invoice['promotion_details']) ?>
                    </p>
                    <?php endif; ?>
                    
                    <p class="mb-0 text-success">
                        <strong>Total Savings:</strong> <?= formatCurrency($discount_amount + $credit_applied, $invoice['currency']) ?>
                    </p>
                </div>
                <?php endif; ?>

                <!-- Enhanced Payment & Discount Timeline -->
                    <h6 class="mt-4"><i class="bi bi-clock-history me-2"></i>Financial Activity Timeline</h6>
                    
                    <?php
                    // Build comprehensive timeline of all financial activities
                    $timeline_items = [];
                    
                    // Add invoice creation
                    $timeline_items[] = [
                        'date' => $invoice['issue_date'],
                        'type' => 'invoice_created',
                        'amount' => $invoice['total_amount'],
                        'description' => 'Invoice created',
                        'details' => 'Original amount: ' . formatCurrency($invoice['total_amount'], $invoice['currency']),
                        'icon' => 'file-text',
                        'color' => 'primary'
                    ];
                    
                    // Add discount if applicable
                    if ($discount_amount > 0) {
                        // Build comprehensive discount description
                        $discount_desc = 'Discount applied';
                        $discount_details = [];
                        
                        // Add discount code if available
                        if (!empty($invoice['discount_code'])) {
                            $discount_desc = 'Discount Code: ' . $invoice['discount_code'];
                            $discount_details[] = 'Code: ' . $invoice['discount_code'];
                        }
                        
                        // Add discount reason/type
                        if (!empty($invoice['discount_reason'])) {
                            $discount_details[] = 'Reason: ' . $invoice['discount_reason'];
                        } elseif (!empty($invoice['discount_type'])) {
                            $discount_details[] = 'Type: ' . ucfirst(str_replace('_', ' ', $invoice['discount_type']));
                        }
                        
                        // Add promotion info
                        if (!empty($invoice['promotion_details'])) {
                            $discount_details[] = 'Promotion: ' . $invoice['promotion_details'];
                        }
                        
                        // Add percentage info
                        $discount_details[] = number_format($discount_percentage, 1) . '% discount';
                        
                        // If no specific details found, try to infer from notes
                        if (empty($discount_details) || count($discount_details) == 1) {
                            if (!empty($invoice['notes']) && stripos($invoice['notes'], 'discount') !== false) {
                                $discount_details[] = 'See invoice notes';
                            } else {
                                $discount_details[] = 'Staff applied discount';
                            }
                        }
                        
                        $timeline_items[] = [
                            'date' => $invoice['issue_date'],
                            'type' => 'discount',
                            'amount' => -$discount_amount,
                            'description' => $discount_desc,
                            'details' => implode(' | ', $discount_details),
                            'icon' => 'tag',
                            'color' => 'success'
                        ];
                    }
                    
                    // Add payments from payment history
                    if (!empty($payment_history)) {
                        foreach ($payment_history as $payment) {
                            $payment_desc = 'Payment received';
                            if ($payment['payment_method'] === 'credit') {
                                $payment_desc = 'Credit applied';
                            }
                            
                            $details = 'via ' . $payment['payment_method'];
                            if (!empty($payment['payment_reference'])) {
                                $details .= ' (Ref: ' . $payment['payment_reference'] . ')';
                            }
                            if (($payment['fees_amount'] ?? 0) > 0) {
                                $details .= ' | Fees: ' . formatCurrency($payment['fees_amount'], $payment['currency']);
                            }
                            
                            $timeline_items[] = [
                                'date' => $payment['transaction_date'],
                                'type' => $payment['payment_method'] === 'credit' ? 'credit' : 'payment',
                                'amount' => $payment['amount'],
                                'description' => $payment_desc,
                                'details' => $details,
                                'icon' => $payment['payment_method'] === 'credit' ? 'credit-card' : 'cash-coin',
                                'color' => $payment['amount'] < 0 ? 'info' : 'success'
                            ];
                        }
                    }
                    
                    // Sort timeline by date (newest first)
                    usort($timeline_items, function($a, $b) {
                        return strtotime($b['date']) - strtotime($a['date']);
                    });
                    ?>
                    
                    <div class="timeline-container">
                        <?php if (!empty($timeline_items)): ?>
                            <?php foreach ($timeline_items as $index => $item): ?>
                            <div class="timeline-item">
                                <div class="timeline-marker bg-<?= $item['color'] ?>">
                                    <i class="bi bi-<?= $item['icon'] ?>"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-header">
                                        <span class="timeline-amount text-<?= $item['color'] ?>">
                                            <?= $item['amount'] >= 0 ? formatCurrency($item['amount'], $invoice['currency']) : '-' . formatCurrency(abs($item['amount']), $invoice['currency']) ?>
                                        </span>
                                        <span class="timeline-date text-muted">
                                            <?= date('d/m/Y', strtotime($item['date'])) ?>
                                            <?php if ($item['type'] !== 'invoice_created'): ?>
                                            <small>(<?= date('H:i', strtotime($item['date'])) ?>)</small>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="timeline-description">
                                        <strong><?= $item['description'] ?></strong>
                                    </div>
                                    <div class="timeline-details text-muted">
                                        <small><?= $item['details'] ?></small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <!-- Current Balance Summary -->
                            <div class="timeline-item timeline-summary">
                                <div class="timeline-marker bg-<?= ($invoice['total_amount'] - ($invoice['paid_amount'] ?? 0)) > 0 ? 'danger' : 'success' ?>">
                                    <i class="bi bi-calculator"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-header">
                                        <span class="timeline-amount text-<?= ($invoice['total_amount'] - ($invoice['paid_amount'] ?? 0)) > 0 ? 'danger' : 'success' ?>">
                                            <strong><?= formatCurrency($invoice['total_amount'] - ($invoice['paid_amount'] ?? 0), $invoice['currency']) ?></strong>
                                        </span>
                                        <span class="timeline-date text-muted">
                                            Current Status
                                        </span>
                                    </div>
                                    <div class="timeline-description">
                                        <strong>Outstanding Balance</strong>
                                    </div>
                                    <div class="timeline-details text-muted">
                                        <small>
                                            <?= ($invoice['total_amount'] - ($invoice['paid_amount'] ?? 0)) > 0 ? 'Payment required' : 'Fully paid' ?>
                                            <?php if (($invoice['total_amount'] - ($invoice['paid_amount'] ?? 0)) > 0 && strtotime($invoice['due_date']) < time()): ?>
                                            | <span class="text-danger">Overdue since <?= date('d/m/Y', strtotime($invoice['due_date'])) ?></span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="timeline-item">
                                <div class="timeline-marker bg-primary">
                                    <i class="bi bi-file-text"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-header">
                                        <span class="timeline-amount text-primary">
                                            <?= formatCurrency($invoice['total_amount'], $invoice['currency']) ?>
                                        </span>
                                        <span class="timeline-date text-muted">
                                            <?= date('d/m/Y', strtotime($invoice['issue_date'])) ?>
                                        </span>
                                    </div>
                                    <div class="timeline-description">
                                        <strong>Invoice created</strong>
                                    </div>
                                    <div class="timeline-details text-muted">
                                        <small>No payments or adjustments recorded</small>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <!-- Add Payment Tab -->
            <div class="tab-pane fade" id="payment" role="tabpanel">
                <form id="paymentForm">
                    <input type="hidden" name="action" value="record_payment">
                    <input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><i class="bi bi-calendar me-2"></i>Date</label>
                                <input type="date" name="payment_date" class="form-control form-control-enhanced" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="bi bi-credit-card me-2"></i>Payment Method</label>
                                <select name="payment_method" class="form-control form-control-enhanced" required>
                                    <option value="PayPal Basic">PayPal Basic</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                    <option value="Credit Card">Credit Card</option>
                                    <option value="Cash">Cash</option>
                                    <option value="Check">Check</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="bi bi-hash me-2"></i>Transaction ID</label>
                                <input type="text" name="transaction_id" class="form-control form-control-enhanced" placeholder="Optional">
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><i class="bi bi-currency-dollar me-2"></i>Amount</label>
                                <input type="number" name="amount" step="0.01" class="form-control form-control-enhanced" 
                                       value="<?= $invoice['total_amount'] - ($invoice['paid_amount'] ?? 0) ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="bi bi-receipt me-2"></i>Transaction Fees (<?= strtoupper($invoice['currency']) ?>)
                                    <i class="bi bi-info-circle text-muted ms-1" title="Will be recorded as company expense"></i>
                                </label>
                                <input type="number" name="transaction_fees" step="0.01" class="form-control form-control-enhanced" value="0.00" placeholder="0.00">
                                <div class="transaction-fee-note">
                                    <i class="bi bi-info-circle me-2"></i>
                                    Transaction fees will be recorded as a company expense for financial reporting but will not affect the customer's invoice balance.
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="sendConfirmationEmail" checked>
                                    <label class="form-check-label" for="sendConfirmationEmail">
                                        Send Confirmation Email
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-success-enhanced">
                            <i class="bi bi-check-circle me-2"></i>Add Payment
                        </button>
                    </div>
                </form>
            </div>

            <!-- Options Tab -->
            <div class="tab-pane fade" id="options" role="tabpanel">
                <form id="optionsForm">
                    <input type="hidden" name="action" value="update_invoice">
                    <input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><i class="bi bi-calendar-event me-2"></i>Invoice Date</label>
                                <input type="date" name="issue_date" class="form-control form-control-enhanced" 
                                       value="<?= date('Y-m-d', strtotime($invoice['issue_date'])) ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="bi bi-calendar-x me-2"></i>Due Date</label>
                                <input type="date" name="due_date" class="form-control form-control-enhanced" 
                                       value="<?= date('Y-m-d', strtotime($invoice['due_date'])) ?>" required>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><i class="bi bi-credit-card me-2"></i>Payment Method</label>
                                <select name="payment_method" class="form-control form-control-enhanced" required>
                                    <option value="PayPal Basic" <?= $invoice['payment_terms'] === 'PayPal Basic' ? 'selected' : '' ?>>PayPal Basic</option>
                                    <option value="Bank Transfer" <?= $invoice['payment_terms'] === 'Bank Transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                                    <option value="Credit Card" <?= $invoice['payment_terms'] === 'Credit Card' ? 'selected' : '' ?>>Credit Card</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="bi bi-flag me-2"></i>Status</label>
                                <select name="status" class="form-control form-control-enhanced" required>
                                    <option value="draft" <?= $invoice['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                                    <option value="sent" <?= $invoice['status'] === 'sent' ? 'selected' : '' ?>>Sent</option>
                                    <option value="paid" <?= $invoice['status'] === 'paid' ? 'selected' : '' ?>>Paid</option>
                                    <option value="partially_paid" <?= $invoice['status'] === 'partially_paid' ? 'selected' : '' ?>>Partially Paid</option>
                                    <option value="overdue" <?= $invoice['status'] === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                                    <option value="cancelled" <?= $invoice['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary-enhanced">
                            <i class="bi bi-save me-2"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- Credit Tab -->
            <div class="tab-pane fade" id="credit" role="tabpanel">
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="bi bi-plus-circle me-2"></i>Add Credit to Invoice</h6>
                        <form id="addCreditForm">
                            <input type="hidden" name="action" value="add_credit">
                            <input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>">
                            
                            <div class="mb-3">
                                <input type="number" name="credit_amount" step="0.01" class="form-control form-control-enhanced" 
                                       placeholder="0.00" required>
                            </div>
                            
                            <button type="submit" class="btn btn-success-enhanced">
                                <i class="bi bi-plus me-2"></i>Apply Credit
                            </button>
                        </form>
                        <p class="text-success mt-2">
                            <i class="bi bi-info-circle me-2"></i><?= formatCurrency(0, $invoice['currency']) ?> Available
                        </p>
                    </div>
                    
                    <div class="col-md-6">
                        <h6><i class="bi bi-dash-circle me-2"></i>Remove Credit from Invoice</h6>
                        <form id="removeCreditForm">
                            <div class="mb-3">
                                <input type="number" step="0.01" class="form-control form-control-enhanced" 
                                       placeholder="0.00">
                            </div>
                            
                            <button type="button" class="btn btn-warning-enhanced">
                                <i class="bi bi-dash me-2"></i>Remove Credit
                            </button>
                        </form>
                        <p class="text-danger mt-2">
                            <i class="bi bi-info-circle me-2"></i><?= formatCurrency(0, $invoice['currency']) ?> Available
                        </p>
                    </div>
                </div>
            </div>

            <!-- Send Email Tab -->
            <div class="tab-pane fade" id="email" role="tabpanel">
                <form id="emailForm">
                    <input type="hidden" name="action" value="send_email">
                    <input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>">
                    
                    <div class="invoice-summary mb-4">
                        <h6><i class="bi bi-envelope me-2"></i>Email Invoice to Client</h6>
                        <p>Send invoice #<?= htmlspecialchars($invoice['invoice_number']) ?> to <?= htmlspecialchars($invoice['company_name']) ?></p>
                        <p><strong>Email:</strong> <?= htmlspecialchars($invoice['company_email']) ?></p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-envelope-fill me-2"></i>Email Template</label>
                        <select name="email_template" class="form-control form-control-enhanced" required>
                            <?php foreach ($email_templates as $template_name => $template_desc): ?>
                            <option value="<?= htmlspecialchars($template_name) ?>"><?= htmlspecialchars($template_name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Choose the appropriate email template for this communication.</small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="attachPDF" checked>
                            <label class="form-check-label" for="attachPDF">
                                Attach PDF Invoice
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="markAsSent" checked>
                            <label class="form-check-label" for="markAsSent">
                                Mark invoice as "Sent" after sending (for Invoice Created template)
                            </label>
                        </div>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary-enhanced">
                            <i class="bi bi-send me-2"></i>Send Email
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- STAFF INVOICE MANAGEMENT VIEW -->
<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="dashboard-hero-content">
            <h1 class="dashboard-hero-title">
                <i class="bi bi-receipt me-2"></i>
                Invoice Management
            </h1>
            <p class="dashboard-hero-subtitle">
                Comprehensive invoice oversight with real-time insights and quick access to essential features.
            </p>
            <div class="dashboard-hero-actions">
                <a href="/operations/create-invoice.php" class="btn c-btn-primary">
                    <i class="bi bi-plus-circle me-1"></i>
                    Create Invoice
                </a>
                <a href="/operations/bulk-actions.php" class="btn c-btn-ghost">
                    <i class="bi bi-collection me-1"></i>
                    Bulk Actions
                </a>
                <a href="/reports/invoice-report.php" class="btn c-btn-ghost">
                    <i class="bi bi-graph-up me-1"></i>
                    Reports
                </a>
            </div>
        </div>
    </div>
</header>

<div class="container py-5" style="margin-top: -100px; position: relative; z-index: 10;">
    
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="breadcrumb-enhanced">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/operations/">Operations</a></li>
            <li class="breadcrumb-item active" aria-current="page">Invoice Management</li>
        </ol>
    </nav>

    <!-- Error/Success Messages -->
    <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?= htmlspecialchars($_GET['error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i>
        <?= htmlspecialchars($_GET['success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Active Filters Display -->
    <?php if ($currency_filter || $selected_company_id || $status_filter !== 'all'): ?>
    <div class="mb-3">
        <h6>Active Filters:</h6>
        <?php if ($currency_filter): ?>
        <span class="filter-badge">
            Currency: <?= strtoupper($currency_filter) ?>
            <a href="<?= buildFilterUrl(['currency' => null]) ?>" class="text-decoration-none ms-1">×</a>
        </span>
        <?php endif; ?>
        <?php if ($selected_company_id): ?>
        <span class="filter-badge">
            Company: <?= htmlspecialchars($all_companies[array_search($selected_company_id, array_column($all_companies, 'id'))]['name'] ?? 'Unknown') ?>
            <a href="<?= buildFilterUrl(['company_id' => null]) ?>" class="text-decoration-none ms-1">×</a>
        </span>
        <?php endif; ?>
        <?php if ($status_filter !== 'all'): ?>
        <span class="filter-badge">
            Status: <?= ucfirst($status_filter) ?>
            <a href="<?= buildFilterUrl(['status' => 'all']) ?>" class="text-decoration-none ms-1">×</a>
        </span>
        <?php endif; ?>
        <a href="/operations/invoices-enhanced-complete.php" class="btn btn-sm btn-outline-secondary ms-2">Clear All</a>
    </div>
    <?php endif; ?>

    <!-- Main Statistics Grid -->
    <div class="stats-grid">
        <!-- Total Outstanding -->
        <div class="stat-card danger" onclick="filterByStatus('outstanding')">
            <div class="stat-icon danger">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
            <div class="stat-number danger"><?= formatCurrency($overall_stats['active_outstanding'] ?? 0, 'GBP') ?></div>
            <div class="stat-label">Outstanding Invoices</div>
            <div class="stat-sublabel"><?= number_format(($overall_stats['sent_count'] ?? 0) + ($overall_stats['partially_paid_count'] ?? 0) + ($overall_stats['overdue_count'] ?? 0)) ?> invoices</div>
        </div>

        <!-- Overdue Today -->
        <div class="stat-card danger" onclick="filterByStatus('overdue')">
            <div class="stat-icon danger">
                <i class="bi bi-clock-history"></i>
            </div>
            <div class="stat-number danger"><?= number_format($time_stats['overdue_today'] ?? 0) ?></div>
            <div class="stat-label">Overdue Today</div>
            <div class="stat-sublabel">Require immediate attention</div>
        </div>

        <!-- Due Within 7 Days -->
        <div class="stat-card warning" onclick="showDueSoon()">
            <div class="stat-icon warning">
                <i class="bi bi-calendar-week"></i>
            </div>
            <div class="stat-number warning"><?= number_format($time_stats['due_within_7_days'] ?? 0) ?></div>
            <div class="stat-label">Due Within 7 Days</div>
            <div class="stat-sublabel">Action needed soon</div>
        </div>

        <!-- Total Paid This Month -->
        <div class="stat-card success">
            <div class="stat-icon success">
                <i class="bi bi-cash-coin"></i>
            </div>
            <div class="stat-number success"><?= formatCurrency($overall_stats['total_paid'] ?? 0, 'GBP') ?></div>
            <div class="stat-label">Total Paid</div>
            <div class="stat-sublabel"><?= number_format($overall_stats['paid_count'] ?? 0) ?> invoices</div>
        </div>

        <!-- Created Last 30 Days -->
        <div class="stat-card info">
            <div class="stat-icon info">
                <i class="bi bi-file-plus"></i>
            </div>
            <div class="stat-number info"><?= number_format($time_stats['created_last_30_days'] ?? 0) ?></div>
            <div class="stat-label">Created (30 days)</div>
            <div class="stat-sublabel">New invoices</div>
        </div>

        <!-- Auto Generated -->
        <div class="stat-card primary">
            <div class="stat-icon primary">
                <i class="bi bi-robot"></i>
            </div>
            <div class="stat-number primary"><?= number_format($overall_stats['auto_generated_count'] ?? 0) ?></div>
            <div class="stat-label">Auto-Generated</div>
            <div class="stat-sublabel">System created</div>
        </div>

        <!-- 30+ Days Overdue -->
        <div class="stat-card danger">
            <div class="stat-icon danger">
                <i class="bi bi-hourglass-bottom"></i>
            </div>
            <div class="stat-number danger"><?= number_format($time_stats['overdue_30_plus_days'] ?? 0) ?></div>
            <div class="stat-label">30+ Days Overdue</div>
            <div class="stat-sublabel">Critical attention needed</div>
        </div>

        <!-- Draft Invoices -->
        <div class="stat-card info" onclick="filterByStatus('draft')">
            <div class="stat-icon info">
                <i class="bi bi-file-text"></i>
            </div>
            <div class="stat-number info"><?= number_format($overall_stats['draft_count'] ?? 0) ?></div>
            <div class="stat-label">Draft Invoices</div>
            <div class="stat-sublabel">Ready to send</div>
        </div>
    </div>

    <!-- Currency Breakdown with Clickable Drilldowns -->
    <?php if (!empty($currency_stats)): ?>
    <div class="enhanced-card">
        <div class="card-header-enhanced">
            <h5 class="card-title-enhanced">
                <i class="bi bi-currency-exchange"></i>
                Currency Breakdown
            </h5>
        </div>
        <div class="card-body-enhanced">
            <div class="row">
                <?php foreach ($currency_stats as $stat): ?>
                <div class="col-md-6 mb-3">
                    <div class="currency-card" onclick="filterByCurrency('<?= $stat['currency'] ?>')">
                        <div class="card-body text-center">
                            <h3 class="text-primary mb-3"><?= strtoupper($stat['currency']) ?></h3>
                            <p class="mb-1"><strong><?= number_format($stat['count']) ?></strong> invoices</p>
                            <p class="mb-1">
                                Total: 
                                <span class="currency-amount" onclick="event.stopPropagation(); filterByCurrencyAndStatus('<?= $stat['currency'] ?>', 'all')">
                                    <strong><?= formatCurrency($stat['total_value'], $stat['currency']) ?></strong>
                                </span>
                            </p>
                            <p class="mb-1">
                                <span class="currency-amount paid" onclick="event.stopPropagation(); filterByCurrencyAndStatus('<?= $stat['currency'] ?>', 'paid')">
                                    Paid: <?= formatCurrency($stat['total_paid'], $stat['currency']) ?>
                                </span>
                            </p>
                            <p class="mb-0">
                                <span class="currency-amount outstanding" onclick="event.stopPropagation(); filterByCurrencyAndStatus('<?= $stat['currency'] ?>', 'outstanding')">
                                    Outstanding: <?= formatCurrency($stat['outstanding'], $stat['currency']) ?>
                                </span>
                            </p>
                            <?php if ($stat['overdue_count'] > 0): ?>
                            <small>
                                <span class="currency-amount text-warning" onclick="event.stopPropagation(); filterByCurrencyAndStatus('<?= $stat['currency'] ?>', 'overdue')">
                                    <?= $stat['overdue_count'] ?> overdue
                                </span>
                            </small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Two Column Layout -->
    <div class="row">
        <!-- Recent Activity -->
        <div class="col-lg-6">
            <div class="activity-card">
                <div class="card-header-enhanced">
                    <h5 class="card-title-enhanced">
                        <i class="bi bi-activity"></i>
                        Recent Activity
                    </h5>
                </div>
                <?php if (!empty($recent_activity)): ?>
                <?php foreach ($recent_activity as $invoice_item): ?>
                <div class="activity-item">
                    <div class="d-flex align-items-center">
                        <div class="activity-icon bg-<?= getStatusBadgeClass($invoice_item['status']) ?>">
                            <i class="bi bi-<?= 
                                $invoice_item['status'] === 'paid' ? 'check-circle' :
                                ($invoice_item['status'] === 'overdue' ? 'exclamation-triangle' :
                                ($invoice_item['status'] === 'sent' ? 'envelope' :
                                ($invoice_item['status'] === 'draft' ? 'file-text' : 'receipt')))
                            ?>"></i>
                        </div>
                        <div class="activity-details">
                            <h6>
                                <a href="?invoice_id=<?= $invoice_item['id'] ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($invoice_item['invoice_number']) ?>
                                </a>
                            </h6>
                            <small><?= htmlspecialchars($invoice_item['company_name']) ?></small>
                        </div>
                    </div>
                    <div class="activity-meta">
                        <span class="badge bg-<?= getStatusBadgeClass($invoice_item['status']) ?>"><?= ucfirst($invoice_item['status']) ?></span>
                        <br><small><?= formatCurrency($invoice_item['total_amount'], $invoice_item['currency']) ?></small>
                        <br><small><?= date('M j, H:i', strtotime($invoice_item['updated_at'])) ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="text-center py-4">
                    <i class="bi bi-activity text-muted" style="font-size: 2rem;"></i>
                    <p class="text-muted mt-2">No recent activity</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Overdue Invoices Requiring Attention -->
        <div class="col-lg-6">
            <div class="activity-card">
                <div class="card-header-enhanced">
                    <h5 class="card-title-enhanced">
                        <i class="bi bi-exclamation-triangle"></i>
                        Overdue Invoices (<?= count($overdue_invoices) ?>)
                    </h5>
                </div>
                <?php if (!empty($overdue_invoices)): ?>
                <?php foreach ($overdue_invoices as $invoice_item): ?>
                <div class="activity-item <?= 
                    $invoice_item['days_overdue'] >= 90 ? 'priority-high' :
                    ($invoice_item['days_overdue'] >= 30 ? 'priority-medium' : 'priority-low')
                ?>">
                    <div class="d-flex align-items-center">
                        <div class="activity-icon bg-<?= getUrgencyClass($invoice_item['days_overdue']) ?>">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <div class="activity-details">
                            <h6>
                                <a href="?invoice_id=<?= $invoice_item['id'] ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($invoice_item['invoice_number']) ?>
                                </a>
                            </h6>
                            <small><?= htmlspecialchars($invoice_item['company_name']) ?></small>
                        </div>
                    </div>
                    <div class="activity-meta">
                        <span class="badge bg-<?= getUrgencyClass($invoice_item['days_overdue']) ?>">
                            <?= $invoice_item['days_overdue'] ?> days overdue
                        </span>
                        <br><small><?= formatCurrency($invoice_item['outstanding_amount'], $invoice_item['currency']) ?></small>
                        <br><small>Due: <?= date('M j', strtotime($invoice_item['due_date'])) ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="text-center p-3 border-top">
                    <a href="?status=overdue" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-eye me-1"></i>View All Overdue
                    </a>
                </div>
                <?php else: ?>
                <div class="text-center py-4">
                    <i class="bi bi-check-circle text-success" style="font-size: 2rem;"></i>
                    <p class="text-muted mt-2">No overdue invoices! 🎉</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-form">
        <form method="GET" action="">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">
                        <i class="bi bi-building me-1"></i>Company Filter
                    </label>
                    <select name="company_id" class="form-control-enhanced">
                        <option value="">All Companies</option>
                        <?php foreach ($all_companies as $company): ?>
                        <option value="<?= $company['id'] ?>" <?= $company['id'] == $selected_company_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($company['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Currency</label>
                    <select name="currency" class="form-control-enhanced">
                        <option value="">All Currencies</option>
                        <option value="GBP" <?= $currency_filter === 'GBP' ? 'selected' : '' ?>>GBP</option>
                        <option value="EUR" <?= $currency_filter === 'EUR' ? 'selected' : '' ?>>EUR</option>
                        <option value="USD" <?= $currency_filter === 'USD' ? 'selected' : '' ?>>USD</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control-enhanced">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="outstanding" <?= $status_filter === 'outstanding' ? 'selected' : '' ?>>Outstanding</option>
                        <option value="draft" <?= $status_filter === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="sent" <?= $status_filter === 'sent' ? 'selected' : '' ?>>Sent</option>
                        <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="partially_paid" <?= $status_filter === 'partially_paid' ? 'selected' : '' ?>>Partially Paid</option>
                        <option value="overdue" <?= $status_filter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                        <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control-enhanced" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control-enhanced" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary-enhanced btn-enhanced w-100">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
            <div class="row g-3 mt-2">
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control-enhanced" placeholder="Search invoice #, company, user..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-3">
                    <a href="/operations/create-invoice.php" class="btn btn-success-enhanced w-100">
                        <i class="bi bi-plus me-2"></i>New Invoice
                    </a>
                </div>
                <div class="col-md-3">
                    <button type="button" onclick="location.href='/operations/invoices-enhanced-complete.php'" class="btn btn-outline-enhanced w-100">
                        <i class="bi bi-arrow-clockwise me-2"></i>Clear Filters
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Invoices Table -->
    <div class="enhanced-card">
        <div class="card-header-enhanced">
            <h5 class="card-title-enhanced">
                <i class="bi bi-table"></i>
                Invoice List (<?= number_format($total_count) ?> total)
            </h5>
        </div>
        <div class="card-body-enhanced p-0">
            <?php if (empty($invoices)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox display-1 text-muted"></i>
                <p class="text-muted mt-3">No invoices found matching your criteria.</p>
                <a href="/operations/invoices-enhanced-complete.php" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-clockwise me-2"></i>Clear Filters
                </a>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-enhanced mb-0">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Company</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Issue Date</th>
                            <th>Due Date</th>
                            <th>Outstanding</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $invoice_row): ?>
                        <tr class="<?= 
                            $invoice_row['days_overdue'] >= 90 ? 'priority-high' :
                            ($invoice_row['days_overdue'] >= 30 ? 'priority-medium' : 
                            ($invoice_row['days_overdue'] > 0 ? 'priority-low' : ''))
                        ?>">
                            <td>
                                <strong>
                                    <a href="?invoice_id=<?= $invoice_row['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($invoice_row['invoice_number']) ?>
                                    </a>
                                </strong>
                                <?php if ($invoice_row['invoice_type'] === 'recurring'): ?>
                                <br><small class="badge bg-info">Recurring</small>
                                <?php endif; ?>
                                <?php if ($invoice_row['auto_generated']): ?>
                                <br><small class="badge bg-secondary"><i class="bi bi-robot"></i> Auto</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($invoice_row['company_name']) ?></strong>
                                <?php if (!$selected_company_id): ?>
                                <a href="?company_id=<?= $invoice_row['company_id'] ?>" class="text-decoration-none ms-2" title="View All Company Invoices">
                                    <i class="bi bi-building text-primary"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= formatCurrency($invoice_row['total_amount'], $invoice_row['currency']) ?></strong>
                                <?php if (isset($invoice_row['paid_amount']) && $invoice_row['paid_amount'] > 0): ?>
                                <br><small class="text-success">Paid: <?= formatCurrency($invoice_row['paid_amount'], $invoice_row['currency']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= getStatusBadgeClass($invoice_row['status']) ?> badge-enhanced">
                                    <i class="bi bi-<?= 
                                        $invoice_row['status'] === 'paid' ? 'check-circle' :
                                        ($invoice_row['status'] === 'overdue' ? 'exclamation-triangle' :
                                        ($invoice_row['status'] === 'sent' ? 'envelope' :
                                        ($invoice_row['status'] === 'partially_paid' ? 'hourglass-split' :
                                        ($invoice_row['status'] === 'cancelled' ? 'x-circle' : 'file-text'))))
                                    ?>"></i>
                                    <?= ucfirst(str_replace('_', ' ', $invoice_row['status'])) ?>
                                </span>
                                <?php if ($invoice_row['status'] === 'overdue' && $invoice_row['days_overdue'] > 0): ?>
                                <br><small class="text-danger"><?= $invoice_row['days_overdue'] ?> days overdue</small>
                                <?php endif; ?>
                            </td>
                            <td><?= date('d/m/Y', strtotime($invoice_row['issue_date'])) ?></td>
                            <td>
                                <?= date('d/m/Y', strtotime($invoice_row['due_date'])) ?>
                                <?php if (!in_array($invoice_row['status'], ['paid', 'cancelled']) && strtotime($invoice_row['due_date']) < time()): ?>
                                <br><small class="text-danger">Overdue</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($invoice_row['outstanding_amount'] > 0): ?>
                                <strong class="text-danger"><?= formatCurrency($invoice_row['outstanding_amount'], $invoice_row['currency']) ?></strong>
                                <?php else: ?>
                                <span class="text-success">Paid</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?= htmlspecialchars($invoice_row['created_by_username'] ?? 'System') ?></small>
                                <br><small class="text-muted"><?= date('M j', strtotime($invoice_row['created_at'])) ?></small>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="?invoice_id=<?= $invoice_row['id'] ?>" class="btn btn-outline-primary" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="/operations/edit-invoice.php?id=<?= $invoice_row['id'] ?>" class="btn btn-outline-warning" title="Edit Invoice">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if (!in_array($invoice_row['status'], ['paid', 'cancelled'])): ?>
                                    <a href="/operations/record-payment.php?invoice_id=<?= $invoice_row['id'] ?>" class="btn btn-outline-success" title="Record Payment">
                                        <i class="bi bi-cash-coin"></i>
                                    </a>
                                    <?php endif; ?>
                                    <a href="/includes/api/generate-pdf.php?invoice_id=<?= $invoice_row['id'] ?>" class="btn btn-outline-secondary" title="Download PDF">
                                        <i class="bi bi-file-pdf"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav aria-label="Invoice pagination" class="mt-4">
        <ul class="pagination justify-content-center">
            <?php if ($page > 1): ?>
            <li class="page-item">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
            <li class="page-item">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
            <?php endif; ?>
        </ul>
        <div class="text-center text-muted mt-2">
            Showing <?= number_format($offset + 1) ?> to <?= number_format(min($offset + $per_page, $total_count)) ?> of <?= number_format($total_count) ?> invoices
        </div>
    </nav>
    <?php endif; ?>

    <!-- Quick Actions Panel -->
    <div class="enhanced-card mt-4">
        <div class="card-header-enhanced">
            <h5 class="card-title-enhanced">
                <i class="bi bi-lightning"></i>
                Quick Actions
            </h5>
        </div>
        <div class="card-body-enhanced">
            <div class="row g-3">
                <div class="col-md-3">
                    <a href="/operations/create-invoice.php" class="btn btn-primary-enhanced w-100">
                        <i class="bi bi-plus-lg me-2"></i>
                        Create New Invoice
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="/operations/bulk-actions.php" class="btn btn-warning-enhanced w-100">
                        <i class="bi bi-collection me-2"></i>
                        Bulk Actions
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="/reports/invoice-report.php" class="btn btn-info text-white w-100">
                        <i class="bi bi-graph-up me-2"></i>
                        Generate Report
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="/operations/payment-reminders.php" class="btn btn-danger-enhanced w-100">
                        <i class="bi bi-bell me-2"></i>
                        Send Reminders
                    </a>
                </div>
            </div>
            
            <!-- Export Options -->
            <div class="row g-3 mt-3">
                <div class="col-md-4">
                    <button onclick="exportInvoices('csv')" class="btn btn-outline-enhanced w-100">
                        <i class="bi bi-file-earmark-spreadsheet me-2"></i>
                        Export to CSV
                    </button>
                </div>
                <div class="col-md-4">
                    <button onclick="exportInvoices('excel')" class="btn btn-outline-enhanced w-100">
                        <i class="bi bi-file-earmark-excel me-2"></i>
                        Export to Excel
                    </button>
                </div>
                <div class="col-md-4">
                    <button onclick="exportInvoices('pdf')" class="btn btn-outline-enhanced w-100">
                        <i class="bi bi-file-earmark-pdf me-2"></i>
                        Export to PDF
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Toast Notifications -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 11">
    <div id="successToast" class="toast hide" role="alert">
        <div class="toast-header">
            <i class="bi bi-check-circle text-success me-2"></i>
            <strong class="me-auto">Success</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body" id="successMessage"></div>
    </div>
    
    <div id="errorToast" class="toast hide" role="alert">
        <div class="toast-header">
            <i class="bi bi-exclamation-triangle text-danger me-2"></i>
            <strong class="me-auto">Error</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body" id="errorMessage"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Helper functions for tab navigation
function showPaymentTab() {
    document.getElementById('payment-tab').click();
}

function showEmailTab() {
    document.getElementById('email-tab').click();
}

function showCreditTab() {
    document.getElementById('credit-tab').click();
}

function showCurrencyConverter() {
    const converter = document.getElementById('currencyConverter');
    converter.classList.toggle('d-none');
}

// Quick filter functions
function filterByStatus(status) {
    const url = new URL(window.location);
    url.searchParams.set('status', status);
    url.searchParams.delete('page');
    window.location = url;
}

function filterByCurrency(currency) {
    const url = new URL(window.location);
    url.searchParams.set('currency', currency);
    url.searchParams.delete('page');
    window.location = url;
}

function filterByCurrencyAndStatus(currency, status) {
    const url = new URL(window.location);
    url.searchParams.set('currency', currency);
    url.searchParams.set('status', status);
    url.searchParams.delete('page');
    window.location = url;
}

function showOverdueInvoices() {
    const url = new URL(window.location);
    url.searchParams.set('status', 'overdue');
    url.searchParams.delete('page');
    window.location = url;
}

function showDueSoon() {
    const url = new URL(window.location);
    url.searchParams.delete('status');
    url.searchParams.delete('page');
    // Add custom filter for due within 7 days
    window.location = url + '?&due_soon=1';
}

// Toast notifications
function showToast(type, message) {
    const toast = type === 'success' ? 
        new bootstrap.Toast(document.getElementById('successToast')) :
        new bootstrap.Toast(document.getElementById('errorToast'));
    
    document.getElementById(type + 'Message').textContent = message;
    toast.show();
}

// Form submissions
document.getElementById('paymentForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('success', data.message);
            setTimeout(() => location.reload(), 1500);
        } else {
                        showToast('error', data.message);
        }
    })
    .catch(error => {
        showToast('error', 'An error occurred');
    });
});

document.getElementById('optionsForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('success', data.message);
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('error', data.message);
        }
    })
    .catch(error => {
        showToast('error', 'An error occurred while updating the invoice');
    });
});

document.getElementById('addCreditForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('success', data.message);
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('error', data.message);
        }
    })
    .catch(error => {
        showToast('error', 'An error occurred while applying credit');
    });
});

document.getElementById('emailForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Sending...';
    submitBtn.disabled = true;
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('success', data.message);
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('error', data.message);
        }
    })
    .catch(error => {
        showToast('error', 'An error occurred while sending email');
    })
    .finally(() => {
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});

document.getElementById('currencyForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Converting...';
    submitBtn.disabled = true;
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('success', data.message);
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('error', data.message);
        }
    })
    .catch(error => {
        showToast('error', 'An error occurred while converting currency');
    })
    .finally(() => {
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});

// Transaction fee calculation helper with currency format
document.querySelector('input[name="transaction_fees"]')?.addEventListener('input', function() {
    const feeAmount = parseFloat(this.value) || 0;
    const noteElement = document.querySelector('.transaction-fee-note');
    const currency = this.closest('form').querySelector('input[name="invoice_id"]')?.getAttribute('data-currency') || 'EUR';
    
    if (feeAmount > 0) {
        noteElement.style.display = 'block';
        noteElement.innerHTML = `
            <i class="bi bi-info-circle me-2"></i>
            Transaction fee of <strong>${formatCurrencyJS(feeAmount, currency)}</strong> will be recorded as a company expense for financial reporting but will not affect the customer's invoice balance.
        `;
    } else {
        noteElement.style.display = 'none';
    }
});

// Export functionality
function exportInvoices(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.location = '/operations/export-invoices.php?' + params.toString();
}

// Currency formatting helper for JavaScript
function formatCurrencyJS(amount, currency = 'GBP') {
    const symbols = {'GBP': '£', 'USD': '$', 'EUR': '€'};
    const symbol = symbols[currency] || currency + ' ';
    return symbol + new Intl.NumberFormat('en-GB', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(amount);
}

// Email template change handler
document.querySelector('select[name="email_template"]')?.addEventListener('change', function() {
    const template = this.value;
    const markAsSentCheckbox = document.getElementById('markAsSent');
    const markAsSentContainer = markAsSentCheckbox?.closest('.form-check');
    
    // Only show "Mark as Sent" option for Invoice Created template
    if (template === 'Invoice Created' && markAsSentContainer) {
        markAsSentContainer.style.display = 'block';
        markAsSentCheckbox.checked = true;
    } else if (markAsSentContainer) {
        markAsSentContainer.style.display = 'none';
        markAsSentCheckbox.checked = false;
    }
});

// Enhanced tooltips for priority rows
document.addEventListener('DOMContentLoaded', function() {
    // Add tooltips to priority rows
    const priorityRows = document.querySelectorAll('.priority-high, .priority-medium, .priority-low');
    priorityRows.forEach(row => {
        const urgencyText = row.classList.contains('priority-high') ? 'Critical: 90+ days overdue' :
                           row.classList.contains('priority-medium') ? 'High: 30+ days overdue' :
                           'Medium: Recently overdue';
        row.setAttribute('title', urgencyText);
        row.setAttribute('data-bs-toggle', 'tooltip');
    });
    
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize email template handler
    const emailTemplateSelect = document.querySelector('select[name="email_template"]');
    if (emailTemplateSelect) {
        emailTemplateSelect.dispatchEvent(new Event('change'));
    }
    
    // Setup table row click handlers for better UX
    const tableRows = document.querySelectorAll('.table-enhanced tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('click', function(e) {
            // Don't trigger if clicking on a button or link
            if (e.target.closest('a, button')) return;
            
            const link = this.querySelector('a[href*="invoice_id"]');
            if (link) {
                window.location = link.href;
            }
        });
        
        // Add hover effect
        row.style.cursor = 'pointer';
    });
    
    // Performance monitoring (disabled - only enable for debugging)
    // console.log('Enhanced invoice management system loaded at:', new Date().toISOString());
    // console.log('Current user:', 'detouredeuropeoutlook');
    // console.log('Page load time:', performance.now().toFixed(2) + 'ms');
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+P for print
    if (e.ctrlKey && e.key === 'p') {
        e.preventDefault();
        window.print();
    }

    // Ctrl+N for new invoice
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        window.location = '/operations/create-invoice.php';
    }

    // Ctrl+R removed - conflicts with browser refresh
    // Use browser's native Ctrl+R to refresh the page

    // Ctrl+B for bulk actions
    if (e.ctrlKey && e.key === 'b') {
        e.preventDefault();
        window.location = '/operations/bulk-actions.php';
    }

    // Escape to go back to invoice list
    if (e.key === 'Escape' && window.location.search.includes('invoice_id=')) {
        window.location = '/operations/invoices.php';
    }
});

// Live search with debounce
let searchTimeout;
const searchInput = document.querySelector('input[name="search"]');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            if (this.value.length >= 3 || this.value.length === 0) {
                this.form.submit();
            }
        }, 1000);
    });
}

// Auto-refresh for real-time updates (optional)
let autoRefreshInterval;
const enableAutoRefresh = false; // Set to true to enable auto-refresh

if (enableAutoRefresh && !window.location.search.includes('invoice_id=')) {
    autoRefreshInterval = setInterval(() => {
        // Only refresh if user hasn't interacted recently
        if (document.hidden === false) {
            const lastActivity = localStorage.getItem('lastActivity');
            const now = Date.now();
            
            if (!lastActivity || (now - parseInt(lastActivity)) > 300000) { // 5 minutes
                // console.log('Auto-refreshing invoice data...');
                location.reload();
            }
        }
    }, 600000); // 10 minutes
}

// Track user activity for auto-refresh
document.addEventListener('mousemove', () => {
    localStorage.setItem('lastActivity', Date.now().toString());
});

document.addEventListener('keypress', () => {
    localStorage.setItem('lastActivity', Date.now().toString());
});

// Handle visibility change to pause auto-refresh when tab is not active
document.addEventListener('visibilitychange', function() {
    if (document.hidden && autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    } else if (!document.hidden && enableAutoRefresh && !window.location.search.includes('invoice_id=')) {
        autoRefreshInterval = setInterval(() => {
            const lastActivity = localStorage.getItem('lastActivity');
            const now = Date.now();
            
            if (!lastActivity || (now - parseInt(lastActivity)) > 300000) {
                // console.log('Auto-refreshing invoice data...');
                location.reload();
            }
        }, 600000);
    }
});

// Enhanced error handling for network issues
window.addEventListener('online', function() {
    showToast('success', 'Connection restored');
});

window.addEventListener('offline', function() {
    showToast('error', 'Connection lost - some features may not work');
});

// Local storage for form data persistence
function saveFormData(formId, data) {
    localStorage.setItem(`invoice_form_${formId}`, JSON.stringify(data));
}

function loadFormData(formId) {
    const saved = localStorage.getItem(`invoice_form_${formId}`);
    return saved ? JSON.parse(saved) : null;
}

// Save form data on input change for payment form
const paymentForm = document.getElementById('paymentForm');
if (paymentForm) {
    paymentForm.addEventListener('input', function() {
        const formData = new FormData(this);
        const data = Object.fromEntries(formData);
        saveFormData('payment', data);
    });
    
    // Load saved data on page load
    const savedData = loadFormData('payment');
    if (savedData) {
        Object.keys(savedData).forEach(key => {
            const input = paymentForm.querySelector(`[name="${key}"]`);
            if (input && input.type !== 'hidden') {
                input.value = savedData[key];
            }
        });
    }
}

// Clear saved form data on successful submission
document.addEventListener('formSubmissionSuccess', function(e) {
    localStorage.removeItem(`invoice_form_${e.detail.formId}`);
});

// Performance optimization: Lazy load images if any
if ('IntersectionObserver' in window) {
    const lazyImages = document.querySelectorAll('img[data-src]');
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src;
                img.classList.remove('lazy');
                imageObserver.unobserve(img);
            }
        });
    });
    
    lazyImages.forEach(img => imageObserver.observe(img));
}

// Analytics tracking (if enabled)
function trackEvent(category, action, label) {
    if (typeof gtag !== 'undefined') {
        gtag('event', action, {
            event_category: category,
            event_label: label
        });
    }
    // console.log(`Analytics: ${category} - ${action} - ${label}`);
}

// Track important user actions
document.addEventListener('click', function(e) {
    if (e.target.matches('.stat-card')) {
        trackEvent('Invoice Dashboard', 'Stat Card Click', e.target.querySelector('.stat-label')?.textContent);
    } else if (e.target.matches('.currency-amount')) {
        trackEvent('Invoice Dashboard', 'Currency Drilldown', 'Currency Filter');
    } else if (e.target.matches('[data-bs-toggle="tab"]')) {
        trackEvent('Invoice Management', 'Tab Switch', e.target.textContent.trim());
    }
});

// Form submission tracking
['paymentForm', 'emailForm', 'optionsForm', 'addCreditForm'].forEach(formId => {
    const form = document.getElementById(formId);
    if (form) {
        form.addEventListener('submit', function() {
            trackEvent('Invoice Management', 'Form Submit', formId);
        });
    }
});

// console.log('Invoice management system fully loaded and ready!');
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>