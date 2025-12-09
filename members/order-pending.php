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

$page_title = "Order Pending Approval | CaminhoIT";
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
        .pending-container {
            max-width: 900px;
            margin: 0 auto;
        }

        .pending-header {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 3rem 2rem;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(245, 158, 11, 0.3);
        }

        .pending-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.5;
            }
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
            background: #fef3c7;
            color: #92400e;
        }

        .timeline {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 1.5rem;
            border-radius: 8px;
        }

        .timeline h6 {
            color: #1e40af;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .timeline-step {
            margin-bottom: 1rem;
            padding-left: 1.5rem;
            position: relative;
        }

        .timeline-step::before {
            content: '●';
            position: absolute;
            left: 0;
            color: #3b82f6;
        }

        .timeline-step.completed {
            color: #059669;
        }

        .timeline-step.completed::before {
            color: #059669;
        }

        .timeline-step.current {
            font-weight: 600;
            color: #1e40af;
        }

        .timeline-step.current::before {
            animation: pulse 2s ease-in-out infinite;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="container py-5">
    <div class="pending-container">
        <!-- Pending Header -->
        <div class="pending-header">
            <div class="pending-icon">
                <i class="bi bi-clock-history"></i>
            </div>
            <h1 class="mb-2">Order Awaiting Approval</h1>
            <p class="mb-0">Your order has been received and is being reviewed by our team.</p>
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
                    <span class="status-badge">
                        <i class="bi bi-clock me-1"></i>
                        Pending Approval
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

        <!-- What Happens Next -->
        <div class="order-section">
            <div class="timeline">
                <h6><i class="bi bi-list-check me-2"></i>Order Progress</h6>
                <div class="timeline-step completed">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    Order placed and submitted
                </div>
                <div class="timeline-step current">
                    <i class="bi bi-hourglass-split me-2"></i>
                    Staff reviewing your order (current step)
                </div>
                <div class="timeline-step">
                    Invoice will be generated upon approval
                </div>
                <div class="timeline-step">
                    You'll receive payment instructions
                </div>
                <div class="timeline-step">
                    Services activated after payment
                </div>
            </div>
        </div>

        <!-- Information Box -->
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            <strong>What's Next?</strong> Our team will review your order shortly. Once approved, you'll receive an email with your invoice and payment instructions. Most orders are approved within 24 hours during business days.
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
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
