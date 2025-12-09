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

// Handle AJAX cart count request
if (isset($_GET['ajax']) && $_GET['ajax'] === 'count') {
    header('Content-Type: application/json');
    echo json_encode(['count' => $cart->getItemCount()]);
    exit;
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');

    switch ($_POST['action']) {
        case 'update_quantity':
            $result = $cart->updateQuantity($_POST['cart_item_id'], $_POST['quantity']);
            echo json_encode($result);
            exit;

        case 'remove_item':
            $result = $cart->removeItem($_POST['cart_item_id']);
            echo json_encode($result);
            exit;

        case 'set_company':
            $cart->setCompany($_POST['company_id']);
            echo json_encode(['success' => true]);
            exit;

        case 'clear_cart':
            $cart->clear();
            echo json_encode(['success' => true, 'message' => 'Cart cleared']);
            exit;
    }
}

// Get user's accessible companies
$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.preferred_currency, c.currency_override
    FROM companies c
    LEFT JOIN users u ON c.id = u.company_id
    LEFT JOIN company_users cu ON c.id = cu.company_id
    WHERE u.id = ? OR cu.user_id = ?
    GROUP BY c.id
    ORDER BY c.name
");
$stmt->execute([$user['id'], $user['id']]);
$companies = $stmt->fetchAll();

// Sync cart currency with selected company's preferred currency
$selected_company = $cart->getCompany();
if ($selected_company) {
    foreach ($companies as $company) {
        if ($company['id'] == $selected_company && !empty($company['preferred_currency'])) {
            $cart->setCurrency($company['preferred_currency']);
            break;
        }
    }
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
$selected_company = $cart->getCompany();

$page_title = "Shopping Cart | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<style>
        .cart-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .cart-item {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }

        .cart-item:hover {
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }

        .cart-summary {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 20px;
        }

        .cart-summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e9ecef;
        }

        .cart-summary-row:last-child {
            border-bottom: none;
        }

        .cart-summary-row.total {
            font-size: 1.25rem;
            font-weight: 700;
            color: #667eea;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid #667eea;
        }

        .quantity-control {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .quantity-btn {
            width: 32px;
            height: 32px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .quantity-btn:hover {
            background: #f3f4f6;
            border-color: #667eea;
        }

        .quantity-input {
            width: 60px;
            text-align: center;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 0.25rem;
        }

        .empty-cart {
            background: white;
            border-radius: 12px;
            padding: 4rem 2rem;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .empty-cart-icon {
            font-size: 5rem;
            color: #d1d5db;
            margin-bottom: 1rem;
        }

        .checkout-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 1rem 2rem;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 10px;
            width: 100%;
            transition: all 0.3s;
        }

        .checkout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        .checkout-btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
        }

        /* DARK MODE STYLES */
        :root.dark,
        :root.dark body {
            background: #0f172a !important;
            color: #e2e8f0 !important;
        }

        /* FORCE purple hero gradient to show in dark mode - SAME as light mode */
        :root.dark .hero {
            background: transparent !important;
        }

        :root.dark .hero-gradient {
            opacity: 1 !important;
            display: block !important;
            visibility: visible !important;
            z-index: 0 !important;
        }

        /* Beautiful fade at bottom of hero in dark mode */
        :root.dark .hero::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 150px;
            background: linear-gradient(
                to bottom,
                rgba(15, 23, 42, 0) 0%,
                rgba(15, 23, 42, 0.7) 50%,
                #0f172a 100%
            ) !important;
            pointer-events: none;
            z-index: 1;
        }

        :root.dark .cart-hero-content h1,
        :root.dark .cart-hero-content p {
            color: white !important;
            position: relative;
            z-index: 2;
        }

        /* Cart items */
        :root.dark .cart-item {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .cart-item:hover {
            box-shadow: 0 4px 20px rgba(139, 92, 246, 0.3) !important;
            border-color: #8b5cf6 !important;
        }

        /* Cart summary */
        :root.dark .cart-summary {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .cart-summary-row {
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .cart-summary-row.total {
            color: #a78bfa !important;
            border-color: #8b5cf6 !important;
        }

        /* Empty cart */
        :root.dark .empty-cart {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .empty-cart-icon {
            color: #475569 !important;
        }

        /* Quantity controls */
        :root.dark .quantity-btn {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .quantity-btn:hover {
            background: rgba(139, 92, 246, 0.1) !important;
            border-color: #8b5cf6 !important;
        }

        :root.dark .quantity-input {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark h1, :root.dark h2, :root.dark h3, :root.dark h4, :root.dark h5, :root.dark h6 {
            color: #f1f5f9 !important;
        }

        /* Company dropdown dark mode */
        :root.dark .form-select,
        :root.dark #companySelect {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .form-select:focus,
        :root.dark #companySelect:focus {
            background: #0f172a !important;
            border-color: #8b5cf6 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .form-select option,
        :root.dark #companySelect option {
            background: #0f172a !important;
            color: #e2e8f0 !important;
        }

        /* Recurring amount section dark mode */
        :root.dark .bg-light {
            background: #0f172a !important;
            border: 1px solid #334155 !important;
        }

        :root.dark .bg-light small.text-muted {
            color: #94a3b8 !important;
        }

        :root.dark .bg-light strong {
            color: #a78bfa !important;
        }

        :root.dark .bg-light .text-muted {
            color: #cbd5e1 !important;
        }

        /* Small text danger */
        :root.dark .text-danger {
            color: #f87171 !important;
        }
    </style>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="cart-hero-content">
            <h1 class="cart-hero-title text-white">
                <i class="bi bi-cart3 me-3"></i>
                Shopping Cart
            </h1>
            <p class="cart-hero-subtitle text-white">
                Review your selected items and proceed to checkout when ready.
            </p>
            <div class="cart-hero-actions">
                <a href="/members/service-catalog.php" class="c-btn-ghost">
                    <i class="bi bi-arrow-left"></i>
                    Continue Shopping
                </a>
            </div>
        </div>
    </div>
</header>

<div class="container py-5 content-overlap">
    <div class="cart-container">

        <?php if (empty($cart_items)): ?>
            <!-- Empty Cart -->
            <div class="empty-cart">
                <div class="empty-cart-icon">
                    <i class="bi bi-cart-x"></i>
                </div>
                <h3>Your cart is empty</h3>
                <p class="text-muted">Browse our service catalog and add items to your cart.</p>
                <a href="/members/service-catalog.php" class="btn btn-primary btn-lg mt-3">
                    <i class="bi bi-grid-3x3-gap me-2"></i>View Services
                </a>
            </div>
        <?php else: ?>
            <div class="row">
                <!-- Cart Items -->
                <div class="col-lg-8">
                    <!-- Company Selection -->
                    <div class="cart-item mb-3">
                        <h5 class="mb-3"><i class="bi bi-building me-2"></i>Select Company</h5>
                        <select class="form-select" id="companySelect" onchange="setCompany(this.value)">
                            <option value="">-- Select Company --</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= $company['id'] ?>" <?= $selected_company == $company['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($company['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$selected_company): ?>
                            <small class="text-danger mt-2 d-block">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Please select a company to proceed
                            </small>
                        <?php endif; ?>
                    </div>

                    <!-- Cart Items -->
                    <?php foreach ($cart_items as $cart_item_id => $item): ?>
                        <div class="cart-item" data-item-id="<?= $cart_item_id ?>">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <h5 class="mb-1"><?= htmlspecialchars($item['name']) ?></h5>
                                    <small class="text-muted">
                                        <?= ucfirst($item['type']) ?> •
                                        <?= ucfirst($item['billing_cycle']) ?> billing
                                    </small>
                                </div>
                                <div class="col-md-2">
                                    <div class="quantity-control">
                                        <button class="quantity-btn" onclick="updateQuantity('<?= $cart_item_id ?>', <?= $item['quantity'] - 1 ?>)">
                                            <i class="bi bi-dash"></i>
                                        </button>
                                        <input type="number" class="quantity-input" value="<?= $item['quantity'] ?>"
                                               min="1" readonly>
                                        <button class="quantity-btn" onclick="updateQuantity('<?= $cart_item_id ?>', <?= $item['quantity'] + 1 ?>)">
                                            <i class="bi bi-plus"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-3 text-end">
                                    <div class="fw-bold">£<?= number_format($item['price'] * $item['quantity'], 2) ?></div>
                                    <?php if ($item['setup_fee'] > 0): ?>
                                        <small class="text-muted">
                                            + £<?= number_format($item['setup_fee'] * $item['quantity'], 2) ?> setup
                                        </small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-1 text-end">
                                    <button class="btn btn-sm btn-outline-danger" onclick="removeItem('<?= $cart_item_id ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Actions -->
                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-outline-secondary" onclick="clearCart()">
                            <i class="bi bi-x-circle me-1"></i>Clear Cart
                        </button>
                    </div>
                </div>

                <!-- Cart Summary -->
                <div class="col-lg-4">
                    <div class="cart-summary">
                        <h5 class="mb-4"><i class="bi bi-receipt me-2"></i>Order Summary</h5>

                        <div class="cart-summary-row">
                            <span>Subtotal:</span>
                            <span>£<?= number_format($totals['subtotal'], 2) ?></span>
                        </div>

                        <?php if ($totals['setup_fees'] > 0): ?>
                            <div class="cart-summary-row">
                                <span>Setup Fees:</span>
                                <span>£<?= number_format($totals['setup_fees'], 2) ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($vat_enabled): ?>
                            <div class="cart-summary-row">
                                <span>VAT (<?= $vat_rate * 100 ?>%):</span>
                                <span>£<?= number_format($totals['tax_amount'], 2) ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="cart-summary-row total">
                            <span>Total Due Today:</span>
                            <span>£<?= number_format($totals['total_due_today'], 2) ?></span>
                        </div>

                        <?php if ($totals['recurring_total'] > 0): ?>
                            <div class="mt-3 p-3 bg-light rounded">
                                <small class="text-muted d-block mb-1">Recurring Amount:</small>
                                <strong>£<?= number_format($totals['total_recurring'], 2) ?></strong>
                                <span class="text-muted">/ <?= $cart->getBillingCycle() ?></span>
                            </div>
                        <?php endif; ?>

                        <hr>

                        <a href="/members/checkout.php"
                           class="btn checkout-btn text-white <?= !$selected_company ? 'disabled' : '' ?>"
                           <?= !$selected_company ? 'onclick="return false;"' : '' ?>>
                            <i class="bi bi-lock-fill me-2"></i>
                            Proceed to Checkout
                        </a>

                        <div class="text-center mt-3">
                            <small class="text-muted">
                                <i class="bi bi-shield-check me-1"></i>
                                Secure checkout
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function updateQuantity(cartItemId, quantity) {
    if (quantity < 1) return;

    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=update_quantity&cart_item_id=${cartItemId}&quantity=${quantity}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

function removeItem(cartItemId) {
    if (!confirm('Remove this item from cart?')) return;

    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=remove_item&cart_item_id=${cartItemId}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

function clearCart() {
    if (!confirm('Clear all items from cart?')) return;

    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=clear_cart'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

function setCompany(companyId) {
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=set_company&company_id=${companyId}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}
</script>




<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
