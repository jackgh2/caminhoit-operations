<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/CartManager.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /login.php');
    exit;
}

$cart = new CartManager($pdo, $user['id']);

// Redirect if cart is empty
if ($cart->isEmpty()) {
    header('Location: /members/cart.php');
    exit;
}

// Redirect if no company selected
$selected_company = $cart->getCompany();
if (!$selected_company) {
    $_SESSION['error'] = 'Please select a company before checkout';
    header('Location: /members/cart.php');
    exit;
}

// Handle order placement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $notes = $_POST['notes'] ?? '';
    $po_number = $_POST['po_number'] ?? '';
    $terms_accepted = isset($_POST['terms_accepted']);

    if (!$terms_accepted) {
        $error = 'You must accept the terms and conditions';
    } else {
        // Create order with pending_approval status
        $full_notes = $notes;
        if ($po_number) {
            $full_notes .= "\n\nPO Number: " . $po_number;
        }

        $result = $cart->createOrder($full_notes, false); // Don't place yet (will be pending_approval)

        if ($result['success']) {
            $order_id = $result['order_id'];

            // Update order status to pending_approval
            $stmt = $pdo->prepare("UPDATE orders SET status = 'pending_approval', placed_at = NOW() WHERE id = ?");
            $stmt->execute([$order_id]);

            // Send Discord notification
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/DiscordNotifications.php';
            $discord = new DiscordNotifications($pdo);
            $discord->notifyNewOrder($order_id);

            // Send order confirmation email
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/OrderNotifications.php';
            $notifications = new OrderNotifications($pdo);
            $notifications->sendOrderConfirmation($order_id);

            $_SESSION['success'] = 'Order placed successfully! Awaiting staff approval.';
            header('Location: /members/order-pending.php?order_id=' . $order_id);
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

// Get company details
$stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$selected_company]);
$company = $stmt->fetch();

// Sync cart currency with company's preferred currency
if ($company && !empty($company['preferred_currency'])) {
    $cart->setCurrency($company['preferred_currency']);
}

// Get VAT settings
$vat_enabled = false;
$vat_rate = 0.20;
$currency = $cart->getCurrency();

if (class_exists('ConfigManager')) {
    $vat_enabled = ConfigManager::isVatRegistered();
    $vatSettings = ConfigManager::get('tax.currency_vat_settings', []);
    if (isset($vatSettings[$currency])) {
        $vat_enabled = $vatSettings[$currency]['enabled'] ?? false;
        $vat_rate = $vatSettings[$currency]['rate'] ?? 0.20;
    }
}

$cart_items = $cart->getItems();
$totals = $cart->getTotals($vat_rate, $vat_enabled);

$page_title = "Checkout | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<style>
        .checkout-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .checkout-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .checkout-section.sticky {
            position: sticky;
            top: 20px;
        }

        .checkout-info-alert {
            font-size: 0.85rem;
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

        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid #e9ecef;
        }

        .order-item:last-child {
            border-bottom: none;
        }

        .item-details {
            flex: 1;
        }

        .item-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .item-meta {
            color: #6b7280;
            font-size: 0.875rem;
        }

        .item-price {
            font-weight: 600;
            font-size: 1.1rem;
            color: #667eea;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e9ecef;
        }

        .summary-row.total {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid #667eea;
            border-bottom: none;
        }

        .place-order-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 1rem 3rem;
            font-size: 1.2rem;
            font-weight: 600;
            border-radius: 10px;
            width: 100%;
            transition: all 0.3s;
        }

        .place-order-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        .company-info {
            background: #f3f4f6;
            padding: 1.5rem;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }

        .company-info h6 {
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .company-info p {
            margin: 0;
            color: #6b7280;
            font-size: 0.9rem;
        }

        .terms-box {
            background: #f9fafb;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            max-height: 200px;
            overflow-y: auto;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #4b5563;
        }

        .form-check-input:checked {
            background-color: #667eea;
            border-color: #667eea;
        }

        .alert {
            border-radius: 8px;
        }
    </style>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="checkout-hero-content">
            <h1 class="checkout-hero-title text-white">
                <i class="bi bi-credit-card me-3"></i>
                Checkout
            </h1>
            <p class="checkout-hero-subtitle text-white">
                Complete your order securely. Review your items and billing details below.
            </p>
            <div class="checkout-hero-actions">
                <a href="/members/cart.php" class="c-btn-ghost">
                    <i class="bi bi-arrow-left"></i>
                    Back to Cart
                </a>
            </div>
        </div>
    </div>
</header>

<div class="container py-5 content-overlap">
    <div class="checkout-container">

        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="row">
                <!-- Left Column - Order Details -->
                <div class="col-lg-8">
                    <!-- Company Information -->
                    <div class="checkout-section">
                        <div class="section-title">
                            <i class="bi bi-building"></i>
                            Billing Company
                        </div>
                        <div class="company-info">
                            <h6><?= htmlspecialchars($company['name']) ?></h6>
                            <?php if ($company['address']): ?>
                                <p><?= nl2br(htmlspecialchars($company['address'])) ?></p>
                            <?php endif; ?>
                            <?php if ($company['vat_number']): ?>
                                <p><strong>VAT:</strong> <?= htmlspecialchars($company['vat_number']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Order Review -->
                    <div class="checkout-section">
                        <div class="section-title">
                            <i class="bi bi-cart-check"></i>
                            Order Review
                        </div>
                        <?php foreach ($cart_items as $item): ?>
                            <div class="order-item">
                                <div class="item-details">
                                    <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                                    <div class="item-meta">
                                        <?= ucfirst($item['type']) ?> •
                                        <?= ucfirst($item['billing_cycle']) ?> billing •
                                        Qty: <?= $item['quantity'] ?>
                                    </div>
                                    <?php if ($item['setup_fee'] > 0): ?>
                                        <div class="item-meta">
                                            Setup Fee: £<?= number_format($item['setup_fee'], 2) ?> each
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="item-price">
                                    £<?= number_format($item['price'] * $item['quantity'], 2) ?>
                                    <?php if ($item['setup_fee'] > 0): ?>
                                        <div class="item-meta">
                                            + £<?= number_format($item['setup_fee'] * $item['quantity'], 2) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Additional Information -->
                    <div class="checkout-section">
                        <div class="section-title">
                            <i class="bi bi-chat-left-text"></i>
                            Additional Information
                        </div>
                        <div class="mb-3">
                            <label for="po_number" class="form-label">Purchase Order Number (Optional)</label>
                            <input type="text" class="form-control" id="po_number" name="po_number"
                                   placeholder="Enter PO number if applicable">
                        </div>
                        <div class="mb-3">
                            <label for="notes" class="form-label">Order Notes (Optional)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"
                                      placeholder="Any special instructions or comments"></textarea>
                        </div>
                    </div>

                    <!-- Terms and Conditions -->
                    <div class="checkout-section">
                        <div class="section-title">
                            <i class="bi bi-file-text"></i>
                            Terms and Conditions
                        </div>
                        <div class="terms-box">
                            <h6>CaminhoIT Service Agreement</h6>
                            <p>By placing this order, you agree to the following terms:</p>
                            <ul>
                                <li>Services will be provisioned upon receipt of payment</li>
                                <li>Recurring services will be billed according to the selected billing cycle</li>
                                <li>All fees are non-refundable unless otherwise stated</li>
                                <li>You agree to comply with our Acceptable Use Policy</li>
                                <li>Services may be suspended for non-payment</li>
                                <li>We reserve the right to modify pricing with 30 days notice</li>
                                <li>Your data will be handled in accordance with our Privacy Policy</li>
                                <li>Support is provided during business hours (9am-5pm GMT)</li>
                            </ul>
                            <p><strong>Payment Terms:</strong> Payment is due immediately upon order placement. Services will be activated once payment is confirmed.</p>
                            <p><strong>Cancellation:</strong> Services may be cancelled at any time with 30 days notice. No refunds for partial billing periods.</p>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="terms_accepted" name="terms_accepted" required>
                            <label class="form-check-label" for="terms_accepted">
                                I have read and agree to the Terms and Conditions
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Order Summary -->
                <div class="col-lg-4">
                    <div class="checkout-section sticky">
                        <div class="section-title">
                            <i class="bi bi-receipt"></i>
                            Order Summary
                        </div>

                        <div class="summary-row">
                            <span>Subtotal:</span>
                            <span>£<?= number_format($totals['subtotal'], 2) ?></span>
                        </div>

                        <?php if ($totals['setup_fees'] > 0): ?>
                            <div class="summary-row">
                                <span>Setup Fees:</span>
                                <span>£<?= number_format($totals['setup_fees'], 2) ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($vat_enabled): ?>
                            <div class="summary-row">
                                <span>VAT (<?= $vat_rate * 100 ?>%):</span>
                                <span>£<?= number_format($totals['tax_amount'], 2) ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="summary-row total">
                            <span>Total Due Today:</span>
                            <span>£<?= number_format($totals['total_due_today'], 2) ?></span>
                        </div>

                        <?php if ($totals['recurring_total'] > 0): ?>
                            <div class="mt-3 p-3 bg-light rounded">
                                <small class="text-muted d-block mb-1">Recurring Amount:</small>
                                <strong class="text-dark">£<?= number_format($totals['total_recurring'], 2) ?></strong>
                                <span class="text-muted">/ <?= $cart->getBillingCycle() ?></span>
                            </div>
                        <?php endif; ?>

                        <hr class="my-4">

                        <button type="submit" name="place_order" class="btn place-order-btn text-white">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            Place Order
                        </button>

                        <div class="text-center mt-3">
                            <small class="text-muted">
                                <i class="bi bi-shield-check me-1"></i>
                                Secure checkout
                            </small>
                        </div>

                        <div class="alert alert-info mt-3 mb-0 checkout-info-alert">
                            <i class="bi bi-info-circle me-1"></i>
                            You'll receive an invoice after placing your order. Payment instructions will be included.
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>


<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
