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

$invoice_id = $_GET['invoice_id'] ?? null;
if (!$invoice_id) {
    header('Location: /members/orders.php');
    exit;
}

// Get invoice details
$stmt = $pdo->prepare("
    SELECT i.*, o.order_number, c.name as company_name
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

if ($invoice['status'] === 'paid') {
    $_SESSION['info'] = 'This invoice has already been paid';
    header('Location: /members/order-confirmation.php?order_id=' . $invoice['order_id']);
    exit;
}

// Get bank details from config
$bank_name = 'Example Bank';
$account_name = 'CaminhoIT Ltd';
$account_number = '12345678';
$sort_code = '12-34-56';
$iban = 'GB29 NWBK 6016 1331 9268 19';
$swift = 'NWBKGB2L';

if (class_exists('ConfigManager')) {
    $bank_name = ConfigManager::get('payments.bank_name', $bank_name);
    $account_name = ConfigManager::get('payments.bank_account_name', $account_name);
    $account_number = ConfigManager::get('payments.bank_account_number', $account_number);
    $sort_code = ConfigManager::get('payments.bank_sort_code', $sort_code);
    $iban = ConfigManager::get('payments.bank_iban', $iban);
    $swift = ConfigManager::get('payments.bank_swift', $swift);
}

$page_title = "Bank Transfer Payment | CaminhoIT";
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
        .transfer-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .transfer-section {
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

        .invoice-summary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }

        .bank-detail {
            background: #f9fafb;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .bank-label {
            color: #6b7280;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .bank-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827;
            font-family: 'Courier New', monospace;
        }

        .copy-btn {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            border-radius: 6px;
        }

        .important-notice {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 1.5rem;
            border-radius: 8px;
            margin: 2rem 0;
        }

        .important-notice h6 {
            color: #92400e;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .important-notice ul {
            margin-bottom: 0;
            color: #92400e;
        }

        .reference-box {
            background: #eff6ff;
            border: 3px dashed #3b82f6;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
            margin: 2rem 0;
        }

        .reference-box .label {
            font-size: 0.9rem;
            color: #1e40af;
            margin-bottom: 0.5rem;
        }

        .reference-box .value {
            font-size: 2rem;
            font-weight: 700;
            color: #1e40af;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="container py-5">
    <div class="transfer-container">
        <!-- Invoice Summary -->
        <div class="invoice-summary">
            <h3 class="mb-3">
                <i class="bi bi-receipt me-2"></i>
                Invoice <?= htmlspecialchars($invoice['invoice_number']) ?>
            </h3>
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="mb-1">Order: <?= htmlspecialchars($invoice['order_number']) ?></p>
                    <p class="mb-0">Company: <?= htmlspecialchars($invoice['company_name']) ?></p>
                </div>
                <div class="text-end">
                    <div style="font-size: 0.9rem; opacity: 0.9;">Amount Due</div>
                    <div style="font-size: 2.5rem; font-weight: 700;">
                        £<?= number_format($invoice['total_amount'], 2) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Reference -->
        <div class="reference-box">
            <div class="label">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                IMPORTANT: Use this payment reference
            </div>
            <div class="value"><?= htmlspecialchars($invoice['invoice_number']) ?></div>
            <button class="btn btn-sm btn-primary mt-2" onclick="copyReference()">
                <i class="bi bi-clipboard me-1"></i>
                Copy Reference
            </button>
        </div>

        <!-- Bank Details -->
        <div class="transfer-section">
            <div class="section-title">
                <i class="bi bi-bank"></i>
                Bank Account Details
            </div>

            <div class="bank-detail">
                <div>
                    <div class="bank-label">Bank Name</div>
                    <div class="bank-value"><?= htmlspecialchars($bank_name) ?></div>
                </div>
            </div>

            <div class="bank-detail">
                <div>
                    <div class="bank-label">Account Name</div>
                    <div class="bank-value"><?= htmlspecialchars($account_name) ?></div>
                </div>
                <button class="btn btn-outline-primary copy-btn" onclick="copyToClipboard('<?= htmlspecialchars($account_name) ?>', this)">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
            </div>

            <div class="bank-detail">
                <div>
                    <div class="bank-label">Account Number</div>
                    <div class="bank-value"><?= htmlspecialchars($account_number) ?></div>
                </div>
                <button class="btn btn-outline-primary copy-btn" onclick="copyToClipboard('<?= htmlspecialchars($account_number) ?>', this)">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
            </div>

            <div class="bank-detail">
                <div>
                    <div class="bank-label">Sort Code</div>
                    <div class="bank-value"><?= htmlspecialchars($sort_code) ?></div>
                </div>
                <button class="btn btn-outline-primary copy-btn" onclick="copyToClipboard('<?= htmlspecialchars($sort_code) ?>', this)">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
            </div>

            <hr class="my-4">

            <h6 class="text-muted mb-3">For International Transfers</h6>

            <div class="bank-detail">
                <div>
                    <div class="bank-label">IBAN</div>
                    <div class="bank-value" style="font-size: 1.1rem;"><?= htmlspecialchars($iban) ?></div>
                </div>
                <button class="btn btn-outline-primary copy-btn" onclick="copyToClipboard('<?= str_replace(' ', '', $iban) ?>', this)">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
            </div>

            <div class="bank-detail">
                <div>
                    <div class="bank-label">SWIFT/BIC</div>
                    <div class="bank-value"><?= htmlspecialchars($swift) ?></div>
                </div>
                <button class="btn btn-outline-primary copy-btn" onclick="copyToClipboard('<?= htmlspecialchars($swift) ?>', this)">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
            </div>
        </div>

        <!-- Important Instructions -->
        <div class="important-notice">
            <h6><i class="bi bi-info-circle-fill me-2"></i>Important Instructions</h6>
            <ul>
                <li>Please use <strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong> as your payment reference</li>
                <li>Payment verification may take 1-3 business days</li>
                <li>Your services will be activated once payment is confirmed</li>
                <li>Bank transfers typically take 24-48 hours to clear</li>
                <li>For faster processing, please email your payment confirmation to accounts@caminhoit.com</li>
            </ul>
        </div>

        <!-- Actions -->
        <div class="d-flex gap-2 justify-content-center">
            <?php if ($invoice['file_path']): ?>
                <a href="<?= htmlspecialchars($invoice['file_path']) ?>" target="_blank"
                   class="btn btn-primary btn-lg">
                    <i class="bi bi-download me-2"></i>
                    Download Invoice
                </a>
            <?php endif; ?>
            <a href="/members/orders.php" class="btn btn-outline-primary btn-lg">
                <i class="bi bi-list-ul me-2"></i>
                Back to Orders
            </a>
        </div>

        <!-- Help Section -->
        <div class="text-center mt-4">
            <p class="text-muted">
                <i class="bi bi-question-circle me-1"></i>
                Need help? Contact us at
                <a href="mailto:accounts@caminhoit.com">accounts@caminhoit.com</a>
            </p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copyToClipboard(text, button) {
    navigator.clipboard.writeText(text).then(() => {
        const originalHtml = button.innerHTML;
        button.innerHTML = '<i class="bi bi-check"></i> Copied!';
        button.classList.remove('btn-outline-primary');
        button.classList.add('btn-success');

        setTimeout(() => {
            button.innerHTML = originalHtml;
            button.classList.remove('btn-success');
            button.classList.add('btn-outline-primary');
        }, 2000);
    });
}

function copyReference() {
    const reference = '<?= htmlspecialchars($invoice['invoice_number']) ?>';
    copyToClipboard(reference, event.target);
}
</script>

</body>
</html>
