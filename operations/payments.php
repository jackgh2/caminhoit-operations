<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;
if (!$user || !in_array($user['role'], ['administrator', 'accountant'])) {
    header('Location: /members/dashboard.php');
    exit;
}

// Filters
$filter_status = $_GET['status'] ?? 'all';
$filter_method = $_GET['method'] ?? 'all';
$filter_date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');

// Build query
$where_clauses = ["1=1"];
$params = [];

if ($filter_status !== 'all') {
    $where_clauses[] = "i.status = ?";
    $params[] = $filter_status;
}

if ($filter_method !== 'all') {
    $where_clauses[] = "i.payment_method = ?";
    $params[] = $filter_method;
}

if ($filter_date_from) {
    $where_clauses[] = "DATE(i.created_at) >= ?";
    $params[] = $filter_date_from;
}

if ($filter_date_to) {
    $where_clauses[] = "DATE(i.created_at) <= ?";
    $params[] = $filter_date_to;
}

$where_sql = implode(' AND ', $where_clauses);

// Get all invoices/payments
$stmt = $pdo->prepare("
    SELECT i.*,
           o.order_number,
           c.name as company_name,
           u.username, u.email as customer_email
    FROM invoices i
    JOIN orders o ON i.order_id = o.id
    JOIN companies c ON i.company_id = c.id
    JOIN users u ON i.customer_id = u.id
    WHERE $where_sql
    ORDER BY i.created_at DESC
");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Calculate statistics
$total_invoiced = 0;
$total_paid = 0;
$total_unpaid = 0;
$payment_methods_breakdown = [];

foreach ($invoices as $invoice) {
    $total_invoiced += $invoice['total_amount'];

    if ($invoice['status'] === 'paid') {
        $total_paid += $invoice['total_amount'];
        $method = $invoice['payment_method'] ?: 'unknown';
        $payment_methods_breakdown[$method] = ($payment_methods_breakdown[$method] ?? 0) + $invoice['total_amount'];
    } else {
        $total_unpaid += $invoice['total_amount'];
    }
}

// Get payment logs for fees calculation
$stmt = $pdo->prepare("
    SELECT SUM(amount) as total_processed
    FROM payment_logs
    WHERE payment_method = 'stripe'
      AND status = 'completed'
      AND DATE(created_at) >= ?
      AND DATE(created_at) <= ?
");
$stmt->execute([$filter_date_from, $filter_date_to]);
$stripe_processed = $stmt->fetchColumn() ?: 0;

// Estimate Stripe fees (2.9% + 30p per transaction)
$estimated_stripe_fees = ($stripe_processed * 0.029) + (count(array_filter($invoices, fn($i) => $i['payment_method'] === 'stripe')) * 0.30);

$page_title = "Payments Dashboard | CaminhoIT";
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php';
?>
    <style>
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-bottom: 1rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #111827;
        }

        .stat-label {
            color: #6b7280;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }

        .invoice-table {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
        }

        .status-paid {
            background: #d1fae5;
            color: #065f46;
        }

        .status-unpaid {
            background: #fee2e2;
            color: #991b1b;
        }

        .method-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .method-stripe {
            background: #eff6ff;
            color: #1e40af;
        }

        .method-bank {
            background: #f0fdf4;
            color: #15803d;
        }

        .method-manual {
            background: #f3f4f6;
            color: #374151;
        }
    </style>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12 mb-4">
            <h1><i class="bi bi-credit-card me-2"></i>Payments Dashboard</h1>
            <p class="text-muted">View and manage all payments and invoices</p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <i class="bi bi-receipt"></i>
                </div>
                <div class="stat-value">£<?= number_format($total_invoiced, 2) ?></div>
                <div class="stat-label">Total Invoiced</div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                    <i class="bi bi-check-circle"></i>
                </div>
                <div class="stat-value">£<?= number_format($total_paid, 2) ?></div>
                <div class="stat-label">Total Paid</div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div class="stat-value">£<?= number_format($total_unpaid, 2) ?></div>
                <div class="stat-label">Unpaid</div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div class="stat-value">£<?= number_format($estimated_stripe_fees, 2) ?></div>
                <div class="stat-label">Est. Stripe Fees</div>
            </div>
        </div>
    </div>

    <!-- Payment Methods Breakdown -->
    <?php if (!empty($payment_methods_breakdown)): ?>
        <div class="row">
            <div class="col-12">
                <div class="stat-card">
                    <h5 class="mb-3"><i class="bi bi-pie-chart me-2"></i>Payment Methods Breakdown</h5>
                    <div class="row">
                        <?php foreach ($payment_methods_breakdown as $method => $amount): ?>
                            <div class="col-md-4">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="text-muted"><?= ucfirst($method) ?>:</span>
                                    <strong>£<?= number_format($amount, 2) ?></strong>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar" style="width: <?= ($amount / $total_paid) * 100 ?>%; background: #667eea;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="filter-card">
        <h5 class="mb-3"><i class="bi bi-funnel me-2"></i>Filters</h5>
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="paid" <?= $filter_status === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="unpaid" <?= $filter_status === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Payment Method</label>
                <select name="method" class="form-select">
                    <option value="all" <?= $filter_method === 'all' ? 'selected' : '' ?>>All Methods</option>
                    <option value="stripe" <?= $filter_method === 'stripe' ? 'selected' : '' ?>>Stripe</option>
                    <option value="bank_transfer" <?= $filter_method === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                    <option value="manual" <?= $filter_method === 'manual' ? 'selected' : '' ?>>Manual</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filter_date_from) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filter_date_to) ?>">
            </div>

            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search me-2"></i>Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- Invoices Table -->
    <div class="invoice-table">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5><i class="bi bi-table me-2"></i>All Invoices (<?= count($invoices) ?>)</h5>
            <div>
                <a href="/operations/create-order.php" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-circle me-1"></i>
                    Create Order
                </a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Company</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Payment Method</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                No invoices found matching your filters
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $invoice): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong>
                                </td>
                                <td>
                                    <?= htmlspecialchars($invoice['order_number']) ?>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($invoice['username']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($invoice['customer_email']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($invoice['company_name']) ?></td>
                                <td>
                                    <strong><?= $invoice['currency'] ?> <?= number_format($invoice['total_amount'], 2) ?></strong>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $invoice['status'] ?>">
                                        <?= strtoupper($invoice['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($invoice['payment_method']): ?>
                                        <span class="method-badge method-<?= str_replace('_', '', $invoice['payment_method']) ?>">
                                            <?= ucfirst(str_replace('_', ' ', $invoice['payment_method'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?= date('d M Y', strtotime($invoice['created_at'])) ?></div>
                                    <?php if ($invoice['paid_at']): ?>
                                        <small class="text-success">
                                            Paid: <?= date('d M Y', strtotime($invoice['paid_at'])) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($invoice['file_path']): ?>
                                            <a href="<?= htmlspecialchars($invoice['file_path']) ?>" target="_blank"
                                               class="btn btn-outline-primary" title="Download PDF">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="/operations/invoices.php?invoice_id=<?= $invoice['id'] ?>"
                                           class="btn btn-outline-secondary" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if ($invoice['status'] === 'unpaid'): ?>
                                            <a href="/operations/invoices.php?invoice_id=<?= $invoice['id'] ?>&action=mark_paid"
                                               class="btn btn-outline-success" title="Mark as Paid"
                                               onclick="return confirm('Mark this invoice as paid?')">
                                                <i class="bi bi-check-circle"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>
