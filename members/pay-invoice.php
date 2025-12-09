<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/PaymentGateway.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /login.php');
    exit;
}

$invoice_id = $_GET['invoice_id'] ?? null;
if (!$invoice_id) {
    header('Location: /members/orders.php');
    exit;
}

// Get invoice details
$stmt = $pdo->prepare("
    SELECT i.*, o.order_number, c.name as company_name, c.address as company_address
    FROM invoices i
    JOIN orders o ON i.order_id = o.id
    JOIN companies c ON i.company_id = c.id
    WHERE i.id = ? AND i.customer_id = ?
");
$stmt->execute([$invoice_id, $user['id']]);
$invoice = $stmt->fetch();

if (!$invoice) {
    $_SESSION['error'] = 'Invoice not found';
    header('Location: /members/orders.php');
    exit;
}

// Check if already paid
if ($invoice['status'] === 'paid') {
    $_SESSION['info'] = 'This invoice has already been paid';
    header('Location: /members/order-confirmation.php?order_id=' . $invoice['order_id']);
    exit;
}

// Get order items
$stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt->execute([$invoice['order_id']]);
$items = $stmt->fetchAll();

// Handle payment method selection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method = $_POST['payment_method'] ?? '';

    if ($payment_method === 'stripe') {
        // Create Stripe checkout session
        $gateway = new PaymentGateway($pdo);

        $success_url = 'https://' . $_SERVER['HTTP_HOST'] . '/members/payment-success.php?session_id={CHECKOUT_SESSION_ID}';
        $cancel_url = 'https://' . $_SERVER['HTTP_HOST'] . '/members/pay-invoice.php?invoice_id=' . $invoice_id;

        $result = $gateway->createCheckoutSession($invoice_id, $success_url, $cancel_url);

        if ($result['success']) {
            header('Location: ' . $result['url']);
            exit;
        } else {
            $error = $result['error'];
        }
    } elseif ($payment_method === 'bank_transfer') {
        // Redirect to bank transfer instructions
        header('Location: /members/bank-transfer.php?invoice_id=' . $invoice_id);
        exit;
    }
}

$gateway = new PaymentGateway($pdo);
$stripe_publishable_key = $gateway->getPublishableKey();

$page_title = "Pay Invoice | CaminhoIT";
?>
<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">

    <style>
        .payment-container {
            max-width: 900px;
            margin: 0 auto;
        }

        .payment-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: #667eea;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .invoice-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }

        .invoice-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .invoice-amount {
            font-size: 3rem;
            font-weight: 700;
            margin-top: 1rem;
        }

        .payment-method {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }

        .payment-method:hover {
            border-color: #667eea;
            background: #f9fafb;
        }

        .payment-method.selected {
            border-color: #667eea;
            background: #eff6ff;
        }

        .payment-method input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .payment-method-icon {
            font-size: 2rem;
            color: #667eea;
            margin-bottom: 0.5rem;
        }

        .payment-method-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
        }

        .payment-method-desc {
            color: #6b7280;
            font-size: 0.9rem;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e9ecef;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .pay-btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            padding: 1rem 3rem;
            font-size: 1.2rem;
            font-weight: 600;
            border-radius: 10px;
            width: 100%;
            transition: all 0.3s;
        }

        .pay-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
        }

        .pay-btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }

        .due-badge {
            background: #fee2e2;
            color: #991b1b;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="container py-5">
    <div class="payment-container">
        <!-- Invoice Header -->
        <div class="invoice-header">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="invoice-number">
                        <i class="bi bi-receipt me-2"></i>
                        <?= htmlspecialchars($invoice['invoice_number']) ?>
                    </div>
                    <p class="mb-0">
                        Order: <?= htmlspecialchars($invoice['order_number']) ?> •
                        Company: <?= htmlspecialchars($invoice['company_name']) ?>
                    </p>
                </div>
                <div class="text-end">
                    <div class="due-badge">
                        <i class="bi bi-exclamation-circle me-1"></i>
                        Payment Due
                    </div>
                </div>
            </div>
            <div class="invoice-amount">
                £<?= number_format($invoice['total_amount'], 2) ?>
            </div>
            <small>Due by <?= date('d M Y', strtotime($invoice['due_date'])) ?></small>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Invoice Details -->
        <div class="payment-section">
            <div class="section-title">
                <i class="bi bi-list-ul"></i>
                Invoice Details
            </div>

            <?php foreach ($items as $item): ?>
                <div class="info-row">
                    <div>
                        <strong><?= htmlspecialchars($item['item_name']) ?></strong>
                        <br>
                        <small class="text-muted">
                            Qty: <?= $item['quantity'] ?> •
                            <?= ucfirst($item['billing_cycle']) ?>
                        </small>
                    </div>
                    <div class="text-end">
                        <strong>£<?= number_format($item['line_total'], 2) ?></strong>
                        <?php if ($item['setup_fee'] > 0): ?>
                            <br>
                            <small class="text-muted">
                                + £<?= number_format($item['setup_fee'] * $item['quantity'], 2) ?> setup
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <hr>

            <div class="info-row">
                <span>Subtotal:</span>
                <span>£<?= number_format($invoice['subtotal'], 2) ?></span>
            </div>
            <?php if ($invoice['tax_amount'] > 0): ?>
                <div class="info-row">
                    <span>VAT:</span>
                    <span>£<?= number_format($invoice['tax_amount'], 2) ?></span>
                </div>
            <?php endif; ?>
            <div class="info-row" style="font-size: 1.25rem; font-weight: 600; color: #667eea;">
                <span>Total:</span>
                <span>£<?= number_format($invoice['total_amount'], 2) ?></span>
            </div>
        </div>

        <!-- Payment Methods -->
        <form method="POST">
            <div class="payment-section">
                <div class="section-title">
                    <i class="bi bi-credit-card"></i>
                    Select Payment Method
                </div>

                <?php if (!empty($stripe_publishable_key)): ?>
                    <label class="payment-method" onclick="selectPaymentMethod('stripe')">
                        <input type="radio" name="payment_method" value="stripe" id="method_stripe" required>
                        <div class="payment-method-icon">
                            <i class="bi bi-credit-card-2-front"></i>
                        </div>
                        <div class="payment-method-title">Credit/Debit Card</div>
                        <div class="payment-method-desc">
                            Pay securely with your credit or debit card via Stripe
                        </div>
                    </label>
                <?php endif; ?>

                <label class="payment-method" onclick="selectPaymentMethod('bank_transfer')">
                    <input type="radio" name="payment_method" value="bank_transfer" id="method_bank" required>
                    <div class="payment-method-icon">
                        <i class="bi bi-bank"></i>
                    </div>
                    <div class="payment-method-title">Bank Transfer</div>
                    <div class="payment-method-desc">
                        Pay via direct bank transfer - Manual verification required
                    </div>
                </label>
            </div>

            <button type="submit" class="btn pay-btn text-white" id="payBtn" disabled>
                <i class="bi bi-lock-fill me-2"></i>
                Proceed to Payment
            </button>

            <div class="text-center mt-3">
                <small class="text-muted">
                    <i class="bi bi-shield-check me-1"></i>
                    Your payment is secure and encrypted
                </small>
            </div>
        </form>

        <!-- Download Invoice -->
        <div class="text-center mt-4">
            <?php if ($invoice['file_path']): ?>
                <a href="<?= htmlspecialchars($invoice['file_path']) ?>" target="_blank" class="btn btn-outline-secondary">
                    <i class="bi bi-download me-2"></i>
                    Download Invoice PDF
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function selectPaymentMethod(method) {
    // Remove all selected classes
    document.querySelectorAll('.payment-method').forEach(el => {
        el.classList.remove('selected');
    });

    // Add selected class to clicked method
    const methodEl = document.getElementById('method_' + method);
    methodEl.checked = true;
    methodEl.closest('.payment-method').classList.add('selected');

    // Enable pay button
    document.getElementById('payBtn').disabled = false;
}

// Auto-select if only one method available
document.addEventListener('DOMContentLoaded', function() {
    const methods = document.querySelectorAll('input[name="payment_method"]');
    if (methods.length === 1) {
        methods[0].click();
    }
});
</script>

</body>
</html>
