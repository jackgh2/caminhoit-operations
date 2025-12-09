<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config-payment-api.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

// Check if user is logged in
if (!isset($_SESSION['user']['id'])) {
    header('Location: /login.php');
    exit;
}

$user_id = $_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'] ?? 'customer';
$username = $_SESSION['user']['username'] ?? 'Unknown';

$staff_roles = ['administrator', 'admin', 'staff', 'accountant', 'support consultant', 'account manager'];
$is_staff = in_array(strtolower($user_role), array_map('strtolower', $staff_roles));

// Get invoice or order ID
$invoice_id = intval($_GET['invoice_id'] ?? 0);
$order_id = intval($_GET['order_id'] ?? 0);

if (!$invoice_id && !$order_id) {
    header('Location: /members/orders.php?error=' . urlencode('Invalid payment request'));
    exit;
}

try {
    if ($invoice_id) {
        // Load invoice
        $stmt = $pdo->prepare("SELECT i.*, o.order_number, c.name as company_name, c.email as company_email,
            c.address as company_address, c.phone as company_phone, c.vat_number as company_vat
            FROM invoices i
            JOIN orders o ON i.order_id = o.id
            JOIN companies c ON i.company_id = c.id
            WHERE i.id = ?");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch();
        
        if (!$invoice) {
            throw new Exception('Invoice not found');
        }
        
        $order_id = $invoice['order_id'];
        $amount_due = $invoice['total_amount'] - $invoice['paid_amount'];
        
    } else {
        // Load order and create/get invoice
        $stmt = $pdo->prepare("SELECT o.*, c.name as company_name, c.email as company_email,
            c.address as company_address, c.phone as company_phone, c.vat_number as company_vat
            FROM orders o
            JOIN companies c ON o.company_id = c.id
            WHERE o.id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();
        
        if (!$order) {
            throw new Exception('Order not found');
        }
        
        // Get or create invoice
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE order_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$order_id]);
        $invoice = $stmt->fetch();
        
        if (!$invoice) {
            // Create invoice (simplified version)
            $invoice_number = generateInvoiceNumber($pdo);
            $stmt = $pdo->prepare("INSERT INTO invoices (
                invoice_number, order_id, company_id, invoice_type, status, issue_date, due_date,
                subtotal, tax_amount, discount_amount, total_amount, currency, created_by
            ) VALUES (?, ?, ?, 'order', 'sent', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY),
                ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $invoice_number, $order_id, $order['company_id'],
                $order['subtotal'], $order['tax_amount'], $order['discount_amount'], $order['total_amount'],
                $order['customer_currency'] ?? 'GBP', $user_id
            ]);
            
            $invoice_id = $pdo->lastInsertId();
            
            // Reload invoice
            $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt->execute([$invoice_id]);
            $invoice = $stmt->fetch();
        }
        
        $amount_due = $invoice['total_amount'] - $invoice['paid_amount'];
    }
    
    // Access control
    if (!$is_staff) {
        $stmt = $pdo->prepare("SELECT company_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_company = $stmt->fetch();
        
        if (!$user_company || $user_company['company_id'] != $invoice['company_id']) {
            header('Location: /members/orders.php?error=' . urlencode('Access denied'));
            exit;
        }
    }
    
    // Check if already paid
    if ($amount_due <= 0) {
        header('Location: /members/view-invoice.php?invoice_id=' . $invoice['id'] . '&message=' . urlencode('Invoice already paid'));
        exit;
    }
    
} catch (Exception $e) {
    error_log("Payment process error: " . $e->getMessage());
    header('Location: /members/orders.php?error=' . urlencode('Error loading payment information'));
    exit;
}

function generateInvoiceNumber($pdo) {
    $prefix = INVOICE_PREFIX;
    $length = INVOICE_NUMBER_LENGTH;
    
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(invoice_number, " . (strlen($prefix) + 1) . ") AS UNSIGNED)) as max_num 
                        FROM invoices WHERE invoice_number LIKE '" . $prefix . "%'");
    $result = $stmt->fetch();
    $next_num = ($result['max_num'] ?? 0) + 1;
    
    return $prefix . str_pad($next_num, $length, '0', STR_PAD_LEFT);
}

function formatCurrency($amount, $currency = 'GBP') {
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
    $symbol = $symbols[$currency] ?? $currency . ' ';
    return $symbol . number_format($amount, 2);
}

// Get Stripe keys
$stripe_keys = getStripeKeys();

$page_title = "Secure Payment - Invoice #" . $invoice['invoice_number'] . " | CaminhoIT";
?>
<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Secure payment processing for Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?>">
    
    <link rel="stylesheet" href="/assets/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Payment Provider Scripts -->
    <script src="https://js.stripe.com/v3/"></script>
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --card-shadow-hover: 0 20px 40px rgba(0, 0, 0, 0.15);
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
            min-height: 40vh;
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
            padding: 3rem 0;
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

        /* Payment Container */
        .payment-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            margin-top: -100px;
            position: relative;
            z-index: 10;
        }

        .payment-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 2rem;
            border-bottom: 1px solid rgba(102, 126, 234, 0.1);
        }

        .payment-body {
            padding: 2rem;
        }

        /* Payment Method Tabs */
        .payment-tabs {
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 2rem;
        }

        .payment-tab {
            display: inline-block;
            padding: 1rem 2rem;
            background: transparent;
            border: none;
            color: #6c757d;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border-bottom: 3px solid transparent;
        }

        .payment-tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }

        .payment-tab:hover {
            color: #667eea;
            background: rgba(102, 126, 234, 0.05);
        }

        /* Payment Forms */
        .payment-form {
            display: none;
        }

        .payment-form.active {
            display: block;
            animation: fadeInUp 0.3s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Form Styling */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
            display: block;
        }

        .form-control-enhanced {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 0.875rem 1rem;
            font-size: 1rem;
            transition: var(--transition);
            width: 100%;
            background: white;
        }

        .form-control-enhanced:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            outline: none;
        }

        /* Stripe Elements */
        .stripe-element {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 0.875rem 1rem;
            background: white;
            transition: var(--transition);
            height: 50px;
            display: flex;
            align-items: center;
        }

        .stripe-element:focus,
        .stripe-element.StripeElement--focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .stripe-element.StripeElement--invalid {
            border-color: #dc3545;
        }

        /* Enhanced Buttons */
        .btn-enhanced {
            border-radius: 50px;
            padding: 1rem 2rem;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            position: relative;
            overflow: hidden;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 200px;
            justify-content: center;
        }

        .btn-enhanced::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            transition: all 0.3s ease;
            transform: translate(-50%, -50%);
        }

        .btn-enhanced:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-enhanced span {
            position: relative;
            z-index: 1;
        }

        .btn-success-enhanced {
            background: var(--success-gradient);
            color: white;
        }

        .btn-primary-enhanced {
            background: var(--primary-gradient);
            color: white;
        }

        /* Payment Summary */
        .payment-summary {
            background: #f8f9fa;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid #e9ecef;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e9ecef;
        }

        .summary-row:last-child {
            border-bottom: none;
            font-weight: 700;
            font-size: 1.25rem;
            padding-top: 1rem;
            border-top: 2px solid #667eea;
            margin-top: 1rem;
        }

        /* Security Indicators */
        .security-indicators {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin: 2rem 0;
            flex-wrap: wrap;
        }

        .security-badge {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #e8f5e8;
            color: #2e7d32;
            padding: 0.75rem 1rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        /* Loading States */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Error and Success Messages */
        .payment-message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: none;
        }

        .payment-message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .payment-message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .payment-message.show {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Progress Steps */
        .payment-progress {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
        }

        .progress-step {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            background: #e9ecef;
            color: #6c757d;
            font-weight: 500;
            margin: 0 0.5rem;
        }

        .progress-step.active {
            background: var(--primary-gradient);
            color: white;
        }

        .progress-step.completed {
            background: var(--success-gradient);
            color: white;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero-title-enhanced {
                font-size: 2rem;
            }
            
            .payment-body {
                padding: 1rem;
            }
            
            .payment-tabs {
                overflow-x: auto;
                white-space: nowrap;
            }
            
            .payment-tab {
                padding: 0.75rem 1rem;
            }
            
            .btn-enhanced {
                width: 100%;
                margin: 0.5rem 0;
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
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav.php'; ?>

<!-- Enhanced Hero Section -->
<header class="hero-enhanced">
    <div class="container">
        <div class="hero-content-enhanced">
            <h1 class="hero-title-enhanced">
                <i class="bi bi-shield-lock me-3"></i>
                Secure Payment
            </h1>
            <p class="hero-subtitle-enhanced">
                Complete your payment for Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?>
            </p>
        </div>
    </div>
</header>

<div class="container">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="breadcrumb-enhanced">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="/members/orders.php">Orders</a></li>
            <li class="breadcrumb-item"><a href="/members/view-order.php?id=<?= $order_id ?>">Order #<?= htmlspecialchars($invoice['order_number'] ?? '') ?></a></li>
            <li class="breadcrumb-item"><a href="/members/view-invoice.php?invoice_id=<?= $invoice['id'] ?>">Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Payment</li>
        </ol>
    </nav>

    <!-- Payment Container -->
    <div class="payment-container">
        <div class="payment-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h3 class="mb-2">Complete Your Payment</h3>
                    <p class="mb-0 text-muted">Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?> • <?= htmlspecialchars($invoice['company_name']) ?></p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="h4 mb-0"><?= formatCurrency($amount_due, $invoice['currency']) ?></div>
                    <small class="text-muted">Amount Due</small>
                </div>
            </div>
        </div>

        <div class="payment-body">
            <!-- Progress Steps -->
            <div class="payment-progress">
                <div class="progress-step active">
                    <i class="bi bi-1-circle"></i>
                    Choose Method
                </div>
                <div class="progress-step" id="step-details">
                    <i class="bi bi-2-circle"></i>
                    Enter Details
                </div>
                <div class="progress-step" id="step-complete">
                    <i class="bi bi-3-circle"></i>
                    Complete Payment
                </div>
            </div>

            <!-- Payment Messages -->
            <div id="payment-messages"></div>

            <!-- Payment Summary -->
            <div class="payment-summary">
                <h5 class="mb-3">Payment Summary</h5>
                <div class="summary-row">
                    <span>Invoice Amount:</span>
                    <span><?= formatCurrency($invoice['total_amount'], $invoice['currency']) ?></span>
                </div>
                <div class="summary-row">
                    <span>Paid Amount:</span>
                    <span><?= formatCurrency($invoice['paid_amount'], $invoice['currency']) ?></span>
                </div>
                <div class="summary-row">
                    <span>Amount Due:</span>
                    <span><?= formatCurrency($amount_due, $invoice['currency']) ?></span>
                </div>
            </div>

            <!-- Payment Method Tabs -->
            <div class="payment-tabs">
                <button class="payment-tab active" data-method="stripe" onclick="switchPaymentMethod('stripe')">
                    <i class="bi bi-credit-card me-2"></i>
                    Credit/Debit Card
                </button>
                <button class="payment-tab" data-method="gocardless" onclick="switchPaymentMethod('gocardless')">
                    <i class="bi bi-bank me-2"></i>
                    Direct Debit
                </button>
                <button class="payment-tab" data-method="paypal" onclick="switchPaymentMethod('paypal')">
                    <i class="bi bi-paypal me-2"></i>
                    PayPal
                </button>
            </div>

            <!-- Stripe Payment Form -->
            <div id="stripe-form" class="payment-form active">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-person me-2"></i>
                                Cardholder Name
                            </label>
                            <input type="text" class="form-control-enhanced" id="cardholder-name" 
                                   placeholder="John Doe" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-envelope me-2"></i>
                                Email Address
                            </label>
                            <input type="email" class="form-control-enhanced" id="customer-email" 
                                   value="<?= htmlspecialchars($invoice['company_email']) ?>" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="bi bi-credit-card me-2"></i>
                        Card Details
                    </label>
                    <div id="stripe-card-element" class="stripe-element">
                        <!-- Stripe Elements will create form elements here -->
                    </div>
                    <div id="stripe-card-errors" class="text-danger mt-2"></div>
                </div>

                <div class="d-grid">
                    <button type="button" class="btn btn-success-enhanced btn-enhanced" id="stripe-submit" onclick="processStripePayment()">
                        <span>
                            <i class="bi bi-lock-fill me-2"></i>
                            Pay <?= formatCurrency($amount_due, $invoice['currency']) ?> Securely
                        </span>
                    </button>
                </div>
            </div>

            <!-- GoCardless Payment Form -->
            <div id="gocardless-form" class="payment-form">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Direct Debit setup allows for automatic recurring payments and is protected by the Direct Debit Guarantee.
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-person me-2"></i>
                                Account Holder Name
                            </label>
                            <input type="text" class="form-control-enhanced" id="account-holder-name" 
                                   placeholder="John Doe" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-envelope me-2"></i>
                                Email Address
                            </label>
                            <input type="email" class="form-control-enhanced" id="gc-customer-email" 
                                   value="<?= htmlspecialchars($invoice['company_email']) ?>" required>
                        </div>
                    </div>
                </div>

                <div class="d-grid">
                    <button type="button" class="btn btn-primary-enhanced btn-enhanced" id="gocardless-submit" onclick="processGoCardlessPayment()">
                        <span>
                            <i class="bi bi-bank me-2"></i>
                            Set up Direct Debit for <?= formatCurrency($amount_due, $invoice['currency']) ?>
                        </span>
                    </button>
                </div>
            </div>

            <!-- PayPal Payment Form -->
            <div id="paypal-form" class="payment-form">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    You'll complete your payment securely within our site using PayPal's embedded checkout.
                </div>
                
                <div id="paypal-button-container"></div>
            </div>

            <!-- Security Indicators -->
            <div class="security-indicators">
                <div class="security-badge">
                    <i class="bi bi-shield-check"></i>
                    256-bit SSL Encryption
                </div>
                <div class="security-badge">
                    <i class="bi bi-lock-fill"></i>
                    PCI DSS Compliant
                </div>
                <div class="security-badge">
                    <i class="bi bi-award"></i>
                    Verified Secure
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Global variables
let stripe, elements, cardElement;
let currentPaymentMethod = 'stripe';
let processingPayment = false;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeStripe();
    initializePayPal();
});

// Switch payment method
function switchPaymentMethod(method) {
    if (processingPayment) return;
    
    currentPaymentMethod = method;
    
    // Update tabs
    document.querySelectorAll('.payment-tab').forEach(tab => {
        tab.classList.remove('active');
    });
    document.querySelector(`[data-method="${method}"]`).classList.add('active');
    
    // Update forms
    document.querySelectorAll('.payment-form').forEach(form => {
        form.classList.remove('active');
    });
    document.getElementById(`${method}-form`).classList.add('active');
    
    // Update progress
    updateProgress(1);
}

// Update progress steps
function updateProgress(step) {
    const steps = document.querySelectorAll('.progress-step');
    steps.forEach((stepEl, index) => {
        stepEl.classList.remove('active', 'completed');
        if (index < step) {
            stepEl.classList.add('completed');
        } else if (index === step) {
            stepEl.classList.add('active');
        }
    });
}

// Show payment message
function showMessage(message, type = 'error') {
    const messagesContainer = document.getElementById('payment-messages');
    const messageEl = document.createElement('div');
    messageEl.className = `payment-message ${type} show`;
    messageEl.innerHTML = `
        <i class="bi bi-${type === 'error' ? 'exclamation-triangle' : 'check-circle'} me-2"></i>
        ${message}
    `;
    
    messagesContainer.innerHTML = '';
    messagesContainer.appendChild(messageEl);
    
    // Auto-hide success messages
    if (type === 'success') {
        setTimeout(() => {
            messageEl.remove();
        }, 5000);
    }
}

// Initialize Stripe
function initializeStripe() {
    stripe = Stripe('<?= $stripe_keys['publishable'] ?>');
    elements = stripe.elements({
        appearance: {
            theme: 'stripe',
            variables: {
                colorPrimary: '#667eea',
                colorBackground: '#ffffff',
                colorText: '#2c3e50',
                colorDanger: '#dc3545',
                fontFamily: '"Segoe UI", Tahoma, Geneva, Verdana, sans-serif',
                borderRadius: '8px',
                spacingUnit: '4px'
            }
        }
    });
    
    cardElement = elements.create('card', {
        hidePostalCode: true,
        style: {
            base: {
                fontSize: '16px',
                color: '#2c3e50',
                '::placeholder': {
                    color: '#6c757d',
                },
            },
        },
    });
    
    cardElement.mount('#stripe-card-element');
    
    cardElement.on('change', function(event) {
        const displayError = document.getElementById('stripe-card-errors');
        if (event.error) {
            displayError.textContent = event.error.message;
        } else {
            displayError.textContent = '';
        }
        
        if (event.complete) {
            updateProgress(2);
        }
    });
}

// Process Stripe payment
async function processStripePayment() {
    if (processingPayment) return;
    
    const submitButton = document.getElementById('stripe-submit');
    const cardholderName = document.getElementById('cardholder-name').value.trim();
    const customerEmail = document.getElementById('customer-email').value.trim();
    
    if (!cardholderName || !customerEmail) {
        showMessage('Please fill in all required fields.');
        return;
    }
    
    processingPayment = true;
    submitButton.classList.add('loading');
    submitButton.innerHTML = '<span><div class="spinner me-2"></div>Processing Payment...</span>';
    updateProgress(2);
    
    try {
        // Create payment intent
        const response = await fetch('/includes/api/create-payment-intent.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                invoice_id: <?= $invoice['id'] ?>,
                amount: <?= $amount_due ?>,
                currency: '<?= $invoice['currency'] ?>',
                customer_email: customerEmail,
                customer_name: cardholderName
            })
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error || 'Failed to create payment intent');
        }
        
        // Confirm payment
        const {error, paymentIntent} = await stripe.confirmCardPayment(result.client_secret, {
            payment_method: {
                card: cardElement,
                billing_details: {
                    name: cardholderName,
                    email: customerEmail,
                }
            }
        });
        
        if (error) {
            throw new Error(error.message);
        }
        
        if (paymentIntent.status === 'succeeded') {
            updateProgress(3);
            showMessage('Payment completed successfully!', 'success');
            
            // Redirect to success page
            setTimeout(() => {
                window.location.href = `/members/payment-success.php?invoice_id=<?= $invoice['id'] ?>&payment_intent=${paymentIntent.id}`;
            }, 2000);
        }
        
    } catch (error) {
        console.error('Payment error:', error);
        showMessage(error.message || 'Payment failed. Please try again.');
        processingPayment = false;
        submitButton.classList.remove('loading');
        submitButton.innerHTML = '<span><i class="bi bi-lock-fill me-2"></i>Pay <?= formatCurrency($amount_due, $invoice['currency']) ?> Securely</span>';
        updateProgress(1);
    }
}

// Process GoCardless payment
async function processGoCardlessPayment() {
    if (processingPayment) return;
    
    const submitButton = document.getElementById('gocardless-submit');
    const accountHolderName = document.getElementById('account-holder-name').value.trim();
    const customerEmail = document.getElementById('gc-customer-email').value.trim();
    
    if (!accountHolderName || !customerEmail) {
        showMessage('Please fill in all required fields.');
        return;
    }
    
    processingPayment = true;
    submitButton.classList.add('loading');
    submitButton.innerHTML = '<span><div class="spinner me-2"></div>Setting up Direct Debit...</span>';
    updateProgress(2);
    
    try {
        const response = await fetch('/includes/api/create-gocardless-flow.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                invoice_id: <?= $invoice['id'] ?>,
                customer_name: accountHolderName,
                customer_email: customerEmail
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            updateProgress(3);
            showMessage('Redirecting to complete Direct Debit setup...', 'success');
            
            // Redirect to GoCardless hosted flow
            setTimeout(() => {
                window.location.href = result.redirect_url;
            }, 1000);
        } else {
            throw new Error(result.error || 'Failed to set up Direct Debit');
        }
        
    } catch (error) {
        console.error('GoCardless error:', error);
        showMessage(error.message || 'Direct Debit setup failed. Please try again.');
        processingPayment = false;
        submitButton.classList.remove('loading');
        submitButton.innerHTML = '<span><i class="bi bi-bank me-2"></i>Set up Direct Debit for <?= formatCurrency($amount_due, $invoice['currency']) ?></span>';
        updateProgress(1);
    }
}

// Initialize PayPal
function initializePayPal() {
    // Load PayPal SDK dynamically
    const script = document.createElement('script');
    script.src = 'https://www.paypal.com/sdk/js?client-id=<?= getPayPalCredentials()['client_id'] ?>&currency=<?= $invoice['currency'] ?>&intent=capture&components=buttons';
    script.onload = function() {
        paypal.Buttons({
            style: {
                shape: 'pill',
                color: 'blue',
                layout: 'vertical',
                height: 50
            },
            
            createOrder: function(data, actions) {
                return actions.order.create({
                    purchase_units: [{
                        amount: {
                            value: '<?= number_format($amount_due, 2, '.', '') ?>',
                            currency_code: '<?= $invoice['currency'] ?>'
                        },
                        description: 'Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?> - <?= htmlspecialchars($invoice['company_name']) ?>'
                    }]
                });
            },
            
            onApprove: function(data, actions) {
                updateProgress(2);
                showMessage('Processing PayPal payment...', 'success');
                
                return actions.order.capture().then(function(details) {
                    updateProgress(3);
                    
                    // Send to server for processing
                    return fetch('/includes/api/process-paypal-payment.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            invoice_id: <?= $invoice['id'] ?>,
                            order_id: data.orderID,
                            details: details
                        })
                    }).then(response => response.json()).then(result => {
                        if (result.success) {
                            showMessage('Payment completed successfully!', 'success');
                            setTimeout(() => {
                                window.location.href = `/members/payment-success.php?invoice_id=<?= $invoice['id'] ?>&paypal_order=${data.orderID}`;
                            }, 2000);
                        } else {
                            throw new Error(result.error || 'Payment processing failed');
                        }
                    });
                });
            },
            
            onError: function(err) {
                console.error('PayPal error:', err);
                showMessage('PayPal payment failed. Please try again.');
                updateProgress(1);
            },
            
            onCancel: function(data) {
                showMessage('PayPal payment was cancelled.');
                updateProgress(1);
            }
        }).render('#paypal-button-container');
    };
    document.head.appendChild(script);
}

// Prevent form submission on Enter key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && e.target.tagName !== 'BUTTON') {
        e.preventDefault();
        
        // Trigger payment based on current method
        if (currentPaymentMethod === 'stripe') {
            processStripePayment();
        } else if (currentPaymentMethod === 'gocardless') {
            processGoCardlessPayment();
        }
    }
});

// Cleanup on page unload
window.addEventListener('beforeunload', function(e) {
    if (processingPayment) {
        e.preventDefault();
        e.returnValue = 'Payment is in progress. Are you sure you want to leave?';
    }
});
</script>

</body>
</html>