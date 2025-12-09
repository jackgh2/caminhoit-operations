<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;
if (!$user || !in_array($user['role'], ['administrator', 'support_consultant', 'accountant'])) {
    header('Location: /members/dashboard.php');
    exit;
}

// Handle order approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $order_id = $_POST['order_id'] ?? '';
    $notes = $_POST['notes'] ?? '';

    if ($action === 'approve' && $order_id) {
        try {
            $pdo->beginTransaction();

            // Update order status
            $stmt = $pdo->prepare("
                UPDATE orders
                SET status = 'pending_payment',
                    approval_notes = ?,
                    approved_by = ?,
                    approved_at = NOW(),
                    updated_at = NOW()
                WHERE id = ? AND status = 'pending_approval'
            ");
            $stmt->execute([$notes, $user['id'], $order_id]);

            if ($stmt->rowCount() > 0) {
                // Generate invoice
                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/InvoiceGenerator.php';
                $invoiceGen = new InvoiceGenerator($pdo);
                $invoice_result = $invoiceGen->generateInvoice($order_id, true); // Send email

                if ($invoice_result['success']) {
                    // Send Discord notification
                    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/DiscordNotifications.php';
                    $discord = new DiscordNotifications($pdo);
                    $discord->notifyOrderApproved($order_id, $user['username']);

                    // Send invoice email
                    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/OrderNotifications.php';
                    $notifications = new OrderNotifications($pdo);
                    $notifications->sendInvoiceEmail($invoice_result['invoice_id']);

                    $pdo->commit();
                    $_SESSION['success'] = "Order approved successfully! Invoice #{$invoice_result['invoice_number']} generated and sent to customer.";
                } else {
                    $pdo->rollBack();
                    $_SESSION['error'] = "Order approved but invoice generation failed: " . $invoice_result['error'];
                }
            } else {
                $pdo->rollBack();
                $_SESSION['error'] = "Order not found or already processed";
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error approving order: " . $e->getMessage();
            error_log("Order approval error: " . $e->getMessage());
        }

        header('Location: /operations/pending-orders.php');
        exit;
    }

    if ($action === 'reject' && $order_id) {
        try {
            $reject_reason = $_POST['reject_reason'] ?? 'No reason provided';

            $stmt = $pdo->prepare("
                UPDATE orders
                SET status = 'rejected',
                    approval_notes = ?,
                    approved_by = ?,
                    approved_at = NOW(),
                    updated_at = NOW()
                WHERE id = ? AND status = 'pending_approval'
            ");
            $stmt->execute([$reject_reason, $user['id'], $order_id]);

            if ($stmt->rowCount() > 0) {
                // Send Discord notification
                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/DiscordNotifications.php';
                $discord = new DiscordNotifications($pdo);
                $discord->notifyOrderRejected($order_id, $user['username'], $reject_reason);

                // TODO: Send rejection email to customer

                $_SESSION['success'] = "Order rejected successfully";
            } else {
                $_SESSION['error'] = "Order not found or already processed";
            }

        } catch (Exception $e) {
            $_SESSION['error'] = "Error rejecting order: " . $e->getMessage();
            error_log("Order rejection error: " . $e->getMessage());
        }

        header('Location: /operations/pending-orders.php');
        exit;
    }
}

// Get specific order details if order_id provided
$viewing_order = null;
$order_id_param = $_GET['order_id'] ?? null;

if ($order_id_param) {
    $stmt = $pdo->prepare("
        SELECT o.*,
               c.name as company_name, c.address as company_address, c.vat_number as company_vat,
               u.username, u.email as customer_email
        FROM orders o
        JOIN companies c ON o.company_id = c.id
        JOIN users u ON o.customer_id = u.id
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id_param]);
    $viewing_order = $stmt->fetch();

    if ($viewing_order) {
        // Get order items
        $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
        $stmt->execute([$order_id_param]);
        $viewing_order['items'] = $stmt->fetchAll();
    }
}

// Get all pending orders
$stmt = $pdo->prepare("
    SELECT o.*,
           c.name as company_name,
           u.username, u.email as customer_email
    FROM orders o
    JOIN companies c ON o.company_id = c.id
    JOIN users u ON o.customer_id = u.id
    WHERE o.status = 'pending_approval'
    ORDER BY o.created_at DESC
");
$stmt->execute();
$pending_orders = $stmt->fetchAll();

$page_title = "Pending Orders - Staff Approval Queue | CaminhoIT";
?>
<?php include $_SERVER'['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>


<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
        --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        --border-radius: 12px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    body {
        background: #F8FAFC;
    }

    .container {
        max-width: 1400px;
    }

    .card, .box, .panel {
        background: white;
        border-radius: var(--border-radius);
        padding: 1.5rem;
        box-shadow: var(--card-shadow);
        margin-bottom: 1.5rem;
    }

    .btn-primary {
        background: var(--primary-gradient);
        border: none;
        color: white;
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        transition: var(--transition);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
    }

    table.table {
        background: white;
        border-radius: var(--border-radius);
        overflow: hidden;
    }

    .table thead {
        background: #F8FAFC;
    }

    .badge {
        padding: 0.375rem 0.75rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .modal {
        z-index: 1050;
    }

    .modal-content {
        border-radius: var(--border-radius);
    }
</style>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12 mb-4">
            <h1><i class="bi bi-clock-history me-2"></i>Pending Orders Approval Queue</h1>
            <p class="text-muted">Review and approve customer orders before invoicing</p>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($_SESSION['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="row">
        <!-- Pending Orders List -->
        <div class="col-lg-5 col-xl-4">
            <div class="mb-3">
                <span class="badge bg-warning text-dark fs-6">
                    <i class="bi bi-exclamation-circle me-1"></i>
                    <?= count($pending_orders) ?> Orders Awaiting Approval
                </span>
            </div>

            <?php if (empty($pending_orders)): ?>
                <div class="empty-state">
                    <i class="bi bi-check-circle"></i>
                    <h3>All Caught Up!</h3>
                    <p>No orders pending approval at the moment.</p>
                </div>
            <?php else: ?>
                <?php foreach ($pending_orders as $order): ?>
                    <div class="order-card <?= $viewing_order && $viewing_order['id'] == $order['id'] ? 'selected' : '' ?>"
                         onclick="window.location.href='?order_id=<?= $order['id'] ?>'">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h5 class="mb-1"><?= htmlspecialchars($order['order_number']) ?></h5>
                                <small class="text-muted">
                                    <?= date('d M Y H:i', strtotime($order['created_at'])) ?>
                                </small>
                            </div>
                            <span class="pending-badge">PENDING</span>
                        </div>
                        <div class="mb-2">
                            <i class="bi bi-person me-1"></i>
                            <strong><?= htmlspecialchars($order['username']) ?></strong>
                        </div>
                        <div class="mb-2">
                            <i class="bi bi-building me-1"></i>
                            <?= htmlspecialchars($order['company_name']) ?>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <strong style="color: #667eea; font-size: 1.1rem;">
                                <?= $order['currency'] ?> <?= number_format($order['total_amount'], 2) ?>
                            </strong>
                            <button class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation(); window.location.href='?order_id=<?= $order['id'] ?>'">
                                Review <i class="bi bi-arrow-right ms-1"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Order Details Panel -->
        <div class="col-lg-7 col-xl-8">
            <?php if ($viewing_order): ?>
                <div class="order-detail-panel">
                    <h3 class="mb-4">
                        <i class="bi bi-file-earmark-text me-2"></i>
                        Order Review
                    </h3>

                    <!-- Order Information -->
                    <div class="mb-4">
                        <h5 class="text-muted mb-3">Order Information</h5>
                        <div class="info-row">
                            <span>Order Number:</span>
                            <strong><?= htmlspecialchars($viewing_order['order_number']) ?></strong>
                        </div>
                        <div class="info-row">
                            <span>Order Date:</span>
                            <strong><?= date('d M Y H:i', strtotime($viewing_order['created_at'])) ?></strong>
                        </div>
                        <div class="info-row">
                            <span>Customer:</span>
                            <strong><?= htmlspecialchars($viewing_order['username']) ?></strong>
                        </div>
                        <div class="info-row">
                            <span>Email:</span>
                            <strong><?= htmlspecialchars($viewing_order['customer_email']) ?></strong>
                        </div>
                        <div class="info-row">
                            <span>Company:</span>
                            <strong><?= htmlspecialchars($viewing_order['company_name']) ?></strong>
                        </div>
                        <?php if ($viewing_order['notes']): ?>
                            <div class="info-row">
                                <span>Order Notes:</span>
                                <span><?= nl2br(htmlspecialchars($viewing_order['notes'])) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Order Items -->
                    <div class="mb-4">
                        <h5 class="text-muted mb-3">Order Items</h5>
                        <?php foreach ($viewing_order['items'] as $item): ?>
                            <div class="item-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?= htmlspecialchars($item['item_name']) ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <?= ucfirst($item['item_type']) ?> •
                                            <?= ucfirst($item['billing_cycle']) ?> •
                                            Qty: <?= $item['quantity'] ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <strong><?= $viewing_order['currency'] ?> <?= number_format($item['line_total'], 2) ?></strong>
                                        <?php if ($item['setup_fee'] > 0): ?>
                                            <br>
                                            <small class="text-muted">
                                                + <?= number_format($item['setup_fee'] * $item['quantity'], 2) ?> setup
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Order Totals -->
                    <div class="mb-4">
                        <div class="info-row">
                            <span>Subtotal:</span>
                            <strong><?= $viewing_order['currency'] ?> <?= number_format($viewing_order['subtotal'], 2) ?></strong>
                        </div>
                        <?php if ($viewing_order['vat_enabled']): ?>
                            <div class="info-row">
                                <span>VAT (<?= $viewing_order['vat_rate'] * 100 ?>%):</span>
                                <strong><?= $viewing_order['currency'] ?> <?= number_format($viewing_order['tax_amount'], 2) ?></strong>
                            </div>
                        <?php endif; ?>
                        <div class="info-row" style="font-size: 1.25rem; border-top: 2px solid #667eea; padding-top: 1rem; margin-top: 1rem;">
                            <span><strong>Total:</strong></span>
                            <strong style="color: #667eea;"><?= $viewing_order['currency'] ?> <?= number_format($viewing_order['total_amount'], 2) ?></strong>
                        </div>
                    </div>

                    <!-- Approval Actions -->
                    <div class="border-top pt-4">
                        <h5 class="mb-3">Approval Actions</h5>

                        <!-- Approve Form -->
                        <form method="POST" class="mb-3">
                            <input type="hidden" name="order_id" value="<?= $viewing_order['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <div class="mb-3">
                                <label class="form-label">Approval Notes (Optional)</label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="Add any notes about this approval..."></textarea>
                            </div>
                            <button type="submit" class="btn approve-btn w-100">
                                <i class="bi bi-check-circle me-2"></i>
                                Approve Order & Generate Invoice
                            </button>
                        </form>

                        <!-- Reject Button -->
                        <button type="button" class="btn reject-btn w-100" data-bs-toggle="modal" data-bs-target="#rejectModal">
                            <i class="bi bi-x-circle me-2"></i>
                            Reject Order
                        </button>
                    </div>
                </div>

                <!-- Reject Modal -->
                <div class="modal fade" id="rejectModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <input type="hidden" name="order_id" value="<?= $viewing_order['id'] ?>">
                                <input type="hidden" name="action" value="reject">
                                <div class="modal-header">
                                    <h5 class="modal-title">Reject Order</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <p class="text-muted">Please provide a reason for rejecting this order. The customer will be notified.</p>
                                    <div class="mb-3">
                                        <label class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                                        <textarea name="reject_reason" class="form-control" rows="4" placeholder="Explain why this order is being rejected..." required></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-danger">
                                        <i class="bi bi-x-circle me-2"></i>
                                        Confirm Rejection
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="order-detail-panel">
                    <div class="empty-state">
                        <i class="bi bi-arrow-left-circle"></i>
                        <h3>Select an Order</h3>
                        <p>Choose an order from the list to review and approve</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>

