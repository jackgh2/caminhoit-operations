<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /login.php');
    exit;
}

$order_id = $_GET['order_id'] ?? null;
if (!$order_id) {
    header('Location: /members/orders.php');
    exit;
}

// Get order details
$stmt = $pdo->prepare("
    SELECT o.*, c.name as company_name, c.address as company_address
    FROM orders o
    JOIN companies c ON o.company_id = c.id
    WHERE o.id = ? AND o.customer_id = ?
");
$stmt->execute([$order_id, $user['id']]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: /members/orders.php');
    exit;
}

// Get order items
$stmt = $pdo->prepare("
    SELECT * FROM order_items WHERE order_id = ? ORDER BY id
");
$stmt->execute([$order_id]);
$order_items = $stmt->fetchAll();

// Get invoice details
$stmt = $pdo->prepare("
    SELECT * FROM invoices WHERE order_id = ? ORDER BY id DESC LIMIT 1
");
$stmt->execute([$order_id]);
$invoice = $stmt->fetch();

$page_title = "Order Confirmation | CaminhoIT";
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
        .confirmation-container {
            max-width: 900px;
            margin: 0 auto;
        }

        .success-header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 3rem 2rem;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.3);
        }

        .success-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        .order-section {
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

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e9ecef;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #6b7280;
            font-weight: 500;
        }

        .info-value {
            font-weight: 600;
        }

        .order-item {
            padding: 1rem;
            background: #f9fafb;
            border-radius: 8px;
            margin-bottom: 0.75rem;
        }

        .order-item:last-child {
            margin-bottom: 0;
        }

        .item-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .item-meta {
            color: #6b7280;
            font-size: 0.875rem;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            display: inline-block;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-unpaid {
            background: #fee2e2;
            color: #991b1b;
        }

        .invoice-box {
            background: #f3f4f6;
            padding: 1.5rem;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }

        .download-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .download-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .next-steps {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 1.5rem;
            border-radius: 8px;
        }

        .next-steps h6 {
            color: #1e40af;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .next-steps ol {
            margin-bottom: 0;
            padding-left: 1.5rem;
        }

        .next-steps li {
            margin-bottom: 0.5rem;
            color: #1e40af;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="container py-5">
    <div class="confirmation-container">
        <!-- Success Header -->
        <div class="success-header">
            <div class="success-icon">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <h1 class="mb-2">Order Placed Successfully!</h1>
            <p class="mb-0">Thank you for your order. We've received your request and are processing it now.</p>
        </div>

        <!-- Order Details -->
        <div class="order-section">
            <div class="section-title">
                <i class="bi bi-receipt-cutoff"></i>
                Order Details
            </div>
            <div class="info-row">
                <span class="info-label">Order Number:</span>
                <span class="info-value"><?= htmlspecialchars($order['order_number']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Order Date:</span>
                <span class="info-value"><?= date('d M Y H:i', strtotime($order['created_at'])) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Company:</span>
                <span class="info-value"><?= htmlspecialchars($order['company_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Order Status:</span>
                <span class="info-value">
                    <span class="status-badge status-pending">
                        <?= ucfirst(str_replace('_', ' ', $order['status'])) ?>
                    </span>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Payment Status:</span>
                <span class="info-value">
                    <span class="status-badge status-unpaid">
                        <?= ucfirst($order['payment_status']) ?>
                    </span>
                </span>
            </div>
        </div>

        <!-- Order Items -->
        <div class="order-section">
            <div class="section-title">
                <i class="bi bi-box-seam"></i>
                Order Items
            </div>
            <?php foreach ($order_items as $item): ?>
                <div class="order-item">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="item-name"><?= htmlspecialchars($item['item_name']) ?></div>
                            <div class="item-meta">
                                <?= ucfirst($item['item_type']) ?> •
                                <?= ucfirst($item['billing_cycle']) ?> billing •
                                Qty: <?= $item['quantity'] ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold">£<?= number_format($item['line_total'], 2) ?></div>
                            <?php if ($item['setup_fee'] > 0): ?>
                                <div class="item-meta">
                                    + £<?= number_format($item['setup_fee'] * $item['quantity'], 2) ?> setup
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <hr class="my-3">

            <div class="info-row">
                <span class="info-label">Subtotal:</span>
                <span class="info-value">£<?= number_format($order['subtotal'], 2) ?></span>
            </div>
            <?php if ($order['vat_enabled']): ?>
                <div class="info-row">
                    <span class="info-label">VAT (<?= $order['vat_rate'] * 100 ?>%):</span>
                    <span class="info-value">£<?= number_format($order['tax_amount'], 2) ?></span>
                </div>
            <?php endif; ?>
            <div class="info-row">
                <span class="info-label" style="font-size: 1.25rem;">Total:</span>
                <span class="info-value" style="font-size: 1.25rem; color: #667eea;">
                    £<?= number_format($order['total_amount'], 2) ?>
                </span>
            </div>
        </div>

        <!-- Invoice Information -->
        <?php if ($invoice): ?>
            <div class="order-section">
                <div class="section-title">
                    <i class="bi bi-file-earmark-text"></i>
                    Invoice
                </div>
                <div class="invoice-box">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?></h6>
                            <p class="mb-0 text-muted">
                                <small>
                                    Issue Date: <?= date('d M Y', strtotime($invoice['issue_date'])) ?> •
                                    Due Date: <?= date('d M Y', strtotime($invoice['due_date'])) ?>
                                </small>
                            </p>
                        </div>
                        <a href="<?= htmlspecialchars($invoice['file_path']) ?>" target="_blank"
                           class="btn download-btn text-white">
                            <i class="bi bi-download me-2"></i>
                            Download PDF
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Next Steps -->
        <div class="order-section">
            <div class="next-steps">
                <h6><i class="bi bi-list-check me-2"></i>What Happens Next?</h6>
                <ol>
                    <li>Review your invoice and payment instructions above</li>
                    <li>Make payment according to the instructions provided</li>
                    <li>We'll verify your payment and activate your services</li>
                    <li>You'll receive a confirmation email once services are active</li>
                    <li>Access your services from the dashboard or receive login details via email</li>
                </ol>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="d-flex gap-2 justify-content-center">
            <a href="/members/orders.php" class="btn btn-outline-primary btn-lg">
                <i class="bi bi-list-ul me-2"></i>
                View All Orders
            </a>
            <a href="/members/service-catalog.php" class="btn btn-outline-secondary btn-lg">
                <i class="bi bi-grid-3x3-gap me-2"></i>
                Browse Services
            </a>
            <?php if ($invoice): ?>
                <a href="<?= htmlspecialchars($invoice['file_path']) ?>" target="_blank"
                   class="btn btn-primary btn-lg">
                    <i class="bi bi-file-pdf me-2"></i>
                    View Invoice
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
