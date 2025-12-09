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

$session_id = $_GET['session_id'] ?? null;

// Get invoice from session ID
$invoice = null;
$order = null;

if ($session_id) {
    $stmt = $pdo->prepare("
        SELECT i.*, o.order_number, o.id as order_id
        FROM invoices i
        JOIN orders o ON i.order_id = o.id
        WHERE i.stripe_session_id = ? AND i.customer_id = ?
    ");
    $stmt->execute([$session_id, $user['id']]);
    $invoice = $stmt->fetch();

    if ($invoice) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$invoice['order_id']]);
        $order = $stmt->fetch();
    }
}

$page_title = "Payment Successful | CaminhoIT";
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
        .success-container {
            max-width: 700px;
            margin: 0 auto;
            text-align: center;
            padding: 3rem 0;
        }

        .success-icon {
            font-size: 6rem;
            color: #10b981;
            margin-bottom: 2rem;
            animation: scaleIn 0.5s ease-out;
        }

        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }

        .success-card {
            background: white;
            border-radius: 12px;
            padding: 3rem 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .success-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #10b981;
            margin-bottom: 1rem;
        }

        .success-message {
            font-size: 1.1rem;
            color: #6b7280;
            margin-bottom: 2rem;
        }

        .info-box {
            background: #f9fafb;
            border-left: 4px solid #667eea;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: left;
            margin: 2rem 0;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
        }

        .info-label {
            color: #6b7280;
            font-weight: 500;
        }

        .info-value {
            font-weight: 600;
        }

        .action-btn {
            padding: 1rem 2.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 10px;
            margin: 0.5rem;
        }

        .confetti {
            position: absolute;
            width: 10px;
            height: 10px;
            background: #667eea;
            animation: confetti-fall 3s linear;
        }

        @keyframes confetti-fall {
            to {
                transform: translateY(100vh) rotate(360deg);
                opacity: 0;
            }
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="container py-5">
    <div class="success-container">
        <div class="success-card">
            <div class="success-icon">
                <i class="bi bi-check-circle-fill"></i>
            </div>

            <h1 class="success-title">Payment Successful!</h1>

            <p class="success-message">
                Thank you for your payment. Your order has been confirmed and will be processed shortly.
            </p>

            <?php if ($invoice): ?>
                <div class="info-box">
                    <div class="info-row">
                        <span class="info-label">Invoice Number:</span>
                        <span class="info-value"><?= htmlspecialchars($invoice['invoice_number']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Order Number:</span>
                        <span class="info-value"><?= htmlspecialchars($invoice['order_number']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Amount Paid:</span>
                        <span class="info-value text-success">
                            £<?= number_format($invoice['total_amount'], 2) ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Payment Date:</span>
                        <span class="info-value"><?= date('d M Y H:i') ?></span>
                    </div>
                </div>

                <div class="alert alert-success" role="alert">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>What's Next?</strong> Your services will be activated automatically within the next few minutes.
                    You'll receive a confirmation email with access details shortly.
                </div>

                <div class="mt-4">
                    <a href="/members/order-confirmation.php?order_id=<?= $invoice['order_id'] ?>"
                       class="btn btn-primary action-btn">
                        <i class="bi bi-receipt me-2"></i>
                        View Order Details
                    </a>
                    <a href="/members/orders.php" class="btn btn-outline-primary action-btn">
                        <i class="bi bi-list-ul me-2"></i>
                        View All Orders
                    </a>
                </div>

                <?php if ($invoice['file_path']): ?>
                    <div class="mt-3">
                        <a href="<?= htmlspecialchars($invoice['file_path']) ?>" target="_blank"
                           class="btn btn-link">
                            <i class="bi bi-download me-2"></i>
                            Download Receipt
                        </a>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-info" role="alert">
                    <i class="bi bi-clock-history me-2"></i>
                    Your payment is being processed. Please check your orders page in a few moments.
                </div>

                <div class="mt-4">
                    <a href="/members/orders.php" class="btn btn-primary action-btn">
                        <i class="bi bi-list-ul me-2"></i>
                        View My Orders
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <p class="text-muted">
            <i class="bi bi-envelope me-1"></i>
            A confirmation email has been sent to your registered email address.
        </p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Create confetti effect
function createConfetti() {
    const colors = ['#667eea', '#764ba2', '#10b981', '#f59e0b', '#ef4444'];
    const confettiCount = 50;

    for (let i = 0; i < confettiCount; i++) {
        setTimeout(() => {
            const confetti = document.createElement('div');
            confetti.className = 'confetti';
            confetti.style.left = Math.random() * 100 + '%';
            confetti.style.background = colors[Math.floor(Math.random() * colors.length)];
            confetti.style.animationDelay = Math.random() * 2 + 's';
            confetti.style.animationDuration = (Math.random() * 2 + 2) + 's';
            document.body.appendChild(confetti);

            setTimeout(() => confetti.remove(), 5000);
        }, i * 30);
    }
}

// Trigger confetti on page load
window.addEventListener('load', createConfetti);
</script>

</body>
</html>
