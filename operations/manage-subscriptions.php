<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/DiscordNotifications.php';

$user = $_SESSION['user'] ?? null;
if (!$user || !in_array($user['role'], ['administrator', 'accountant', 'support_consultant'])) {
    header('Location: /members/dashboard.php');
    exit;
}

$success = '';
$error = '';

/**
 * Calculate next billing date based on billing cycle
 */
function calculateNextBillingDate($start_date, $billing_cycle) {
    $date = new DateTime($start_date);

    switch ($billing_cycle) {
        case 'monthly':
            $date->modify('+1 month');
            break;
        case 'quarterly':
            $date->modify('+3 months');
            break;
        case 'semiannually':
            $date->modify('+6 months');
            break;
        case 'annually':
            $date->modify('+1 year');
            break;
        case 'biennially':
            $date->modify('+2 years');
            break;
        case 'triennially':
            $date->modify('+3 years');
            break;
        default:
            // Default to monthly if invalid cycle
            $date->modify('+1 month');
            break;
    }

    return $date->format('Y-m-d');
}

// Handle creating subscriptions from order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_from_order'])) {
    $order_id = $_POST['order_id'] ?? '';

    if ($order_id) {
        try {
            $pdo->beginTransaction();

            // Get order details
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch();

            if (!$order) {
                throw new Exception("Order not found");
            }

            // Get order items
            $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
            $stmt->execute([$order_id]);
            $order_items = $stmt->fetchAll();

            if (empty($order_items)) {
                throw new Exception("No items found in order");
            }

            $created_count = 0;

            foreach ($order_items as $item) {
                // Check if subscription already exists for this order item
                $check_product_id = !empty($item['product_id']) ? $item['product_id'] : null;
                $check_bundle_id = !empty($item['bundle_id']) ? $item['bundle_id'] : null;

                $stmt = $pdo->prepare("
                    SELECT id FROM client_subscriptions
                    WHERE company_id = ?
                    AND (product_id = ? OR (product_id IS NULL AND ? IS NULL))
                    AND (bundle_id = ? OR (bundle_id IS NULL AND ? IS NULL))
                    AND notes LIKE ?
                ");
                $stmt->execute([
                    $order['company_id'],
                    $check_product_id,
                    $check_product_id,
                    $check_bundle_id,
                    $check_bundle_id,
                    '%Order #' . $order['order_number'] . '%'
                ]);

                if ($stmt->fetch()) {
                    continue; // Skip if already created
                }

                // Create subscription
                $start_date = !empty($order['start_date']) ? $order['start_date'] : date('Y-m-d');
                $next_billing_date = calculateNextBillingDate($start_date, $item['billing_cycle']);

                $subscription_notes = "Created from Order #" . $order['order_number'];
                if (!empty($order['notes'])) {
                    $subscription_notes .= "\n\nOrder Notes: " . $order['notes'];
                }

                $stmt = $pdo->prepare("
                    INSERT INTO client_subscriptions
                    (order_id, order_item_id, company_id, product_id, bundle_id, quantity, unit_price, total_price,
                     billing_cycle, status, start_date, next_billing_date, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $order_id,
                    $item['id'],
                    $order['company_id'],
                    $check_product_id,
                    $check_bundle_id,
                    $item['quantity'],
                    $item['unit_price'],
                    $item['line_total'],
                    $item['billing_cycle'],
                    $start_date,
                    $next_billing_date,
                    $subscription_notes,
                    $user['id']
                ]);

                $subscription_id = $pdo->lastInsertId();

                // Create inventory entry
                $stmt = $pdo->prepare("
                    INSERT INTO subscription_inventory
                    (subscription_id, total_quantity, assigned_quantity)
                    VALUES (?, ?, 0)
                ");
                $stmt->execute([$subscription_id, $item['quantity']]);

                // Send Discord notification
                $discord = new DiscordNotifications($pdo);
                $discord->notifySubscriptionCreated($subscription_id);

                $created_count++;
            }

            $pdo->commit();
            $success = "Successfully created $created_count subscription(s) from Order #" . $order['order_number'];

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error creating subscriptions: " . $e->getMessage();
        }
    }
}

// Handle manual subscription creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_subscription'])) {
    $company_id = $_POST['company_id'] ?? '';
    $product_id = !empty($_POST['product_id']) ? intval($_POST['product_id']) : null;
    $bundle_id = !empty($_POST['bundle_id']) ? intval($_POST['bundle_id']) : null;
    $quantity = intval($_POST['quantity'] ?? 1);
    $unit_price = floatval($_POST['unit_price'] ?? 0);
    $billing_cycle = $_POST['billing_cycle'] ?? 'monthly';
    $start_date = $_POST['start_date'] ?? date('Y-m-d');
    $notes = $_POST['notes'] ?? '';

    if ($company_id && ($product_id || $bundle_id) && $quantity > 0) {
        try {
            $pdo->beginTransaction();

            $total_price = $unit_price * $quantity;
            $next_billing_date = calculateNextBillingDate($start_date, $billing_cycle);

            $stmt = $pdo->prepare("
                INSERT INTO client_subscriptions
                (order_id, order_item_id, company_id, product_id, bundle_id, quantity, unit_price, total_price,
                 billing_cycle, status, start_date, next_billing_date, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?)
            ");

            $stmt->execute([
                null,
                null,
                $company_id,
                $product_id,
                $bundle_id,
                $quantity,
                $unit_price,
                $total_price,
                $billing_cycle,
                $start_date,
                $next_billing_date,
                $notes,
                $user['id']
            ]);

            $subscription_id = $pdo->lastInsertId();

            // Create inventory entry
            $stmt = $pdo->prepare("
                INSERT INTO subscription_inventory
                (subscription_id, total_quantity, assigned_quantity)
                VALUES (?, ?, 0)
            ");
            $stmt->execute([$subscription_id, $quantity]);

            // Send Discord notification
            $discord = new DiscordNotifications($pdo);
            $discord->notifySubscriptionCreated($subscription_id);

            $pdo->commit();
            $success = "Subscription created successfully";

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error creating subscription: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields";
    }
}

// Handle subscription status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $subscription_id = $_POST['subscription_id'] ?? '';
    $new_status = $_POST['status'] ?? '';

    if ($subscription_id && $new_status) {
        try {
            // Get old status first
            $stmt = $pdo->prepare("SELECT status FROM client_subscriptions WHERE id = ?");
            $stmt->execute([$subscription_id]);
            $old_status = $stmt->fetchColumn();

            // Update status
            $stmt = $pdo->prepare("UPDATE client_subscriptions SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $subscription_id]);

            // Send Discord notification
            if ($old_status && $old_status !== $new_status) {
                $discord = new DiscordNotifications($pdo);
                $discord->notifySubscriptionStatusChange($subscription_id, $old_status, $new_status);
            }

            $success = "Subscription status updated to: " . ucfirst($new_status);
        } catch (Exception $e) {
            $error = "Error updating status: " . $e->getMessage();
        }
    }
}

// Get filters
$filter_status = $_GET['status'] ?? 'all';
$filter_company = $_GET['company'] ?? 'all';

// Build query
$where_clauses = ["1=1"];
$params = [];

if ($filter_status !== 'all') {
    $where_clauses[] = "cs.status = ?";
    $params[] = $filter_status;
}

if ($filter_company !== 'all') {
    $where_clauses[] = "cs.company_id = ?";
    $params[] = $filter_company;
}

$where_sql = implode(" AND ", $where_clauses);

// Get all subscriptions
$stmt = $pdo->prepare("
    SELECT cs.*,
           c.name as company_name,
           p.name as product_name,
           sb.name as bundle_name,
           si.total_quantity,
           si.assigned_quantity,
           si.available_quantity
    FROM client_subscriptions cs
    JOIN companies c ON cs.company_id = c.id
    LEFT JOIN products p ON cs.product_id = p.id
    LEFT JOIN service_bundles sb ON cs.bundle_id = sb.id
    LEFT JOIN subscription_inventory si ON cs.id = si.subscription_id
    WHERE $where_sql
    ORDER BY cs.created_at DESC
");
$stmt->execute($params);
$subscriptions = $stmt->fetchAll();

// Get all companies for filters
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();

// Get all products and bundles for manual creation
$products = $pdo->query("SELECT id, name, base_price as price FROM products ORDER BY name")->fetchAll();
$bundles = $pdo->query("SELECT id, name, bundle_price as price FROM service_bundles ORDER BY name")->fetchAll();

// Get recent eligible orders (may or may not have subscriptions already)
$stmt = $pdo->query("
    SELECT o.id, o.order_number, o.created_at, c.name as company_name,
           COUNT(oi.id) as item_count,
           o.total_amount, o.currency
    FROM orders o
    JOIN companies c ON o.company_id = c.id
    JOIN order_items oi ON o.id = oi.order_id
    WHERE o.status IN ('paid', 'pending_payment', 'completed')
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 20
");
$orders_without_subscriptions = $stmt->fetchAll();

$page_title = "Manage Subscriptions | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<style>
        .subscription-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }

        .subscription-card:hover {
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .status-active { background: #d1fae5; color: #065f46; }
        .status-suspended { background: #fed7aa; color: #92400e; }
        .status-cancelled { background: #fecaca; color: #991b1b; }
        .status-pending { background: #dbeafe; color: #1e40af; }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .stat-card h3 {
            font-size: 2rem;
            margin: 0;
            font-weight: 700;
        }

        .stat-card p {
            margin: 0;
            opacity: 0.9;
        }

        .inventory-bar {
            background: #e5e7eb;
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .inventory-fill {
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            height: 100%;
            transition: width 0.3s;
        }

        .order-card {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 8px;
        }

        /* Dark Mode Styles */
        :root.dark body {
            background: #0f172a !important;
        }

        :root.dark .card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .card-header {
            background: #1e293b !important;
            border-bottom-color: #334155 !important;
            color: #f1f5f9 !important;
        }

        :root.dark .card-body {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .subscription-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .subscription-card:hover {
            background: #0f172a !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4) !important;
        }

        :root.dark .form-label {
            color: #cbd5e1 !important;
        }

        :root.dark .form-control,
        :root.dark .form-select {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .form-control:focus,
        :root.dark .form-select:focus {
            background: #1e293b !important;
            border-color: #8b5cf6 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .table {
            color: #e2e8f0 !important;
        }

        :root.dark .table thead th {
            background: #0f172a !important;
            color: #f1f5f9 !important;
            border-color: #334155 !important;
        }

        :root.dark .table tbody tr {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .table tbody tr:hover {
            background: #0f172a !important;
        }

        :root.dark .table td {
            color: #e2e8f0 !important;
            border-color: #334155 !important;
        }

        :root.dark .order-card {
            background: #92400e !important;
            border-left-color: #f59e0b !important;
            color: #fde68a !important;
        }

        :root.dark .modal-content {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .modal-header,
        :root.dark .modal-footer,
        :root.dark .modal-body {
            border-color: #334155 !important;
        }

        :root.dark .modal-title {
            color: #f1f5f9 !important;
        }

        :root.dark h1,
        :root.dark h2,
        :root.dark h3,
        :root.dark h4,
        :root.dark h5,
        :root.dark h6 {
            color: #f1f5f9 !important;
        }

        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        /* Status Badge Dark Mode - Better Contrast */
        :root.dark .status-badge {
            font-weight: 700;
        }

        :root.dark .status-active { 
            background: #065f46 !important; 
            color: #d1fae5 !important; 
        }

        :root.dark .status-suspended { 
            background: #92400e !important; 
            color: #fed7aa !important; 
        }

        :root.dark .status-cancelled { 
            background: #991b1b !important; 
            color: #fecaca !important; 
        }

        :root.dark .status-pending { 
            background: #1e40af !important; 
            color: #dbeafe !important; 
        }

        :root.dark .status-overdue {
            background: #7f1d1d !important;
            color: #fca5a5 !important;
        }
    </style>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="dashboard-hero-content">
            <h1 class="dashboard-hero-title">
                <i class="bi bi-card-list me-2"></i>
                Manage Subscriptions
            </h1>
            <p class="dashboard-hero-subtitle">
                Create and manage client subscriptions with comprehensive billing management
            </p>
        </div>
    </div>
</header>

<div class="container py-5">
    <div class="row mb-4">
        <div class="col">
        </div>
        <div class="col-auto">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                <i class="bi bi-plus-circle me-2"></i>Create Subscription
            </button>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Orders Without Subscriptions -->
    <?php if (!empty($orders_without_subscriptions)): ?>
        <div class="card mb-4" style="border-left: 4px solid #ffc107;">
            <div class="card-header bg-warning bg-opacity-10">
                <h5 class="mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Orders Without Subscriptions (<?= count($orders_without_subscriptions) ?>)
                </h5>
            </div>
            <div class="card-body">
                <p class="text-muted">These orders have been placed but subscriptions haven't been created yet:</p>
                <?php foreach ($orders_without_subscriptions as $order): ?>
                    <div class="order-card">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <strong><?= htmlspecialchars($order['order_number']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($order['company_name']) ?></small>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">Items:</small> <?= $order['item_count'] ?><br>
                                <small class="text-muted">Total:</small> <?= $order['currency'] ?> <?= number_format($order['total_amount'], 2) ?>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted"><?= date('M d, Y', strtotime($order['created_at'])) ?></small>
                            </div>
                            <div class="col-md-2 text-end">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <button type="submit" name="create_from_order" class="btn btn-sm btn-success">
                                        <i class="bi bi-plus-circle me-1"></i>Create Subscriptions
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="row mb-4">
        <?php
        $total_subscriptions = count($subscriptions);
        $active_subscriptions = count(array_filter($subscriptions, fn($s) => $s['status'] === 'active'));
        $total_licenses = array_sum(array_column($subscriptions, 'total_quantity'));
        $assigned_licenses = array_sum(array_column($subscriptions, 'assigned_quantity'));
        ?>
        <div class="col-md-3">
            <div class="stat-card">
                <h3><?= $total_subscriptions ?></h3>
                <p>Total Subscriptions</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <h3><?= $active_subscriptions ?></h3>
                <p>Active Subscriptions</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <h3><?= $total_licenses ?></h3>
                <p>Total Licenses</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <h3><?= $assigned_licenses ?></h3>
                <p>Assigned Licenses</p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="suspended" <?= $filter_status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                        <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Company</label>
                    <select name="company" class="form-select" onchange="this.form.submit()">
                        <option value="all">All Companies</option>
                        <?php foreach ($companies as $comp): ?>
                            <option value="<?= $comp['id'] ?>" <?= $filter_company == $comp['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($comp['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <a href="?" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-clockwise me-1"></i>Reset Filters
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Subscriptions List -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">All Subscriptions (<?= count($subscriptions) ?>)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($subscriptions)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox" style="font-size: 4rem; color: #d1d5db;"></i>
                    <p class="text-muted mt-3">No subscriptions found</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                        <i class="bi bi-plus-circle me-2"></i>Create First Subscription
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($subscriptions as $subscription): ?>
                    <div class="subscription-card">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <h6 class="mb-1">
                                    <?= htmlspecialchars($subscription['product_name'] ?? $subscription['bundle_name'] ?? 'Unknown') ?>
                                </h6>
                                <small class="text-muted">
                                    <i class="bi bi-building me-1"></i>
                                    <?= htmlspecialchars($subscription['company_name']) ?>
                                </small>
                            </div>
                            <div class="col-md-2">
                                <span class="status-badge status-<?= $subscription['status'] ?>">
                                    <?= ucfirst($subscription['status']) ?>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <div class="d-flex justify-content-between">
                                    <small>Licenses:</small>
                                    <strong><?= $subscription['assigned_quantity'] ?> / <?= $subscription['total_quantity'] ?></strong>
                                </div>
                                <div class="inventory-bar">
                                    <div class="inventory-fill" style="width: <?= $subscription['total_quantity'] > 0 ? ($subscription['assigned_quantity'] / $subscription['total_quantity'] * 100) : 0 ?>%"></div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <small class="text-muted d-block">Next Billing:</small>
                                <strong><?= $subscription['next_billing_date'] ? date('M d, Y', strtotime($subscription['next_billing_date'])) : '<span class="text-muted">Not set</span>' ?></strong>
                            </div>
                            <div class="col-md-1 text-end">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a class="dropdown-item" href="/operations/product-assignments.php?company_id=<?= $subscription['company_id'] ?>">
                                                <i class="bi bi-person-plus me-2"></i>Assign to Users
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <button class="dropdown-item" onclick="updateStatus(<?= $subscription['id'] ?>, 'suspended')">
                                                <i class="bi bi-pause-circle me-2"></i>Suspend
                                            </button>
                                        </li>
                                        <li>
                                            <button class="dropdown-item text-danger" onclick="updateStatus(<?= $subscription['id'] ?>, 'cancelled')">
                                                <i class="bi bi-x-circle me-2"></i>Cancel
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <?php if (!empty($subscription['notes'])): ?>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <i class="bi bi-sticky me-1"></i>
                                    <?= nl2br(htmlspecialchars($subscription['notes'])) ?>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Create Subscription Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Subscription</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Company *</label>
                        <select name="company_id" class="form-select" required>
                            <option value="">Select Company</option>
                            <?php foreach ($companies as $comp): ?>
                                <option value="<?= $comp['id'] ?>"><?= htmlspecialchars($comp['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <select name="product_id" class="form-select" id="productSelect" onchange="updatePrice('product')">
                            <option value="">Select Product</option>
                            <?php foreach ($products as $prod): ?>
                                <option value="<?= $prod['id'] ?>" data-price="<?= $prod['price'] ?>">
                                    <?= htmlspecialchars($prod['name']) ?> - £<?= number_format($prod['price'], 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Or select a bundle below</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Bundle</label>
                        <select name="bundle_id" class="form-select" id="bundleSelect" onchange="updatePrice('bundle')">
                            <option value="">Select Bundle</option>
                            <?php foreach ($bundles as $bundle): ?>
                                <option value="<?= $bundle['id'] ?>" data-price="<?= $bundle['price'] ?>">
                                    <?= htmlspecialchars($bundle['name']) ?> - £<?= number_format($bundle['price'], 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity *</label>
                            <input type="number" name="quantity" class="form-control" value="1" min="1" required id="quantityInput" onchange="calculateTotal()">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Unit Price *</label>
                            <input type="number" name="unit_price" class="form-control" step="0.01" required id="priceInput" onchange="calculateTotal()">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Total Price</label>
                        <input type="text" class="form-control" id="totalPrice" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Billing Cycle *</label>
                        <select name="billing_cycle" class="form-select" id="billingCycleSelect" required onchange="updateNextBillingDate()">
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly (3 months)</option>
                            <option value="semiannually">Semi-annually (6 months)</option>
                            <option value="annually">Annually (1 year)</option>
                            <option value="biennially">Biennially (2 years)</option>
                            <option value="triennially">Triennially (3 years)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Start Date *</label>
                        <input type="date" name="start_date" class="form-control" id="startDateInput" value="<?= date('Y-m-d') ?>" required onchange="updateNextBillingDate()">
                    </div>

                    <div class="mb-3">
                        <div class="alert alert-info" style="background: #dbeafe; border: 1px solid #3b82f6; color: #1e40af;">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Next Billing Date:</strong> <span id="nextBillingPreview"><?= date('M d, Y', strtotime('+1 month')) ?></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_subscription" class="btn btn-primary">Create Subscription</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updatePrice(type) {
    const productSelect = document.getElementById('productSelect');
    const bundleSelect = document.getElementById('bundleSelect');
    const priceInput = document.getElementById('priceInput');

    if (type === 'product') {
        bundleSelect.value = '';
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        const price = selectedOption.getAttribute('data-price');
        priceInput.value = price || '';
    } else {
        productSelect.value = '';
        const selectedOption = bundleSelect.options[bundleSelect.selectedIndex];
        const price = selectedOption.getAttribute('data-price');
        priceInput.value = price || '';
    }

    calculateTotal();
}

function calculateTotal() {
    const quantity = parseFloat(document.getElementById('quantityInput').value) || 0;
    const price = parseFloat(document.getElementById('priceInput').value) || 0;
    const total = quantity * price;
    document.getElementById('totalPrice').value = '£' + total.toFixed(2);
}

function updateNextBillingDate() {
    const startDateInput = document.getElementById('startDateInput');
    const billingCycleSelect = document.getElementById('billingCycleSelect');
    const nextBillingPreview = document.getElementById('nextBillingPreview');

    if (!startDateInput || !billingCycleSelect || !nextBillingPreview) return;

    const startDate = new Date(startDateInput.value);
    const billingCycle = billingCycleSelect.value;

    if (isNaN(startDate.getTime())) {
        nextBillingPreview.textContent = 'Invalid start date';
        return;
    }

    let nextBillingDate = new Date(startDate);

    switch (billingCycle) {
        case 'monthly':
            nextBillingDate.setMonth(nextBillingDate.getMonth() + 1);
            break;
        case 'quarterly':
            nextBillingDate.setMonth(nextBillingDate.getMonth() + 3);
            break;
        case 'semiannually':
            nextBillingDate.setMonth(nextBillingDate.getMonth() + 6);
            break;
        case 'annually':
            nextBillingDate.setFullYear(nextBillingDate.getFullYear() + 1);
            break;
        case 'biennially':
            nextBillingDate.setFullYear(nextBillingDate.getFullYear() + 2);
            break;
        case 'triennially':
            nextBillingDate.setFullYear(nextBillingDate.getFullYear() + 3);
            break;
        default:
            nextBillingDate.setMonth(nextBillingDate.getMonth() + 1);
    }

    // Format date nicely
    const options = { year: 'numeric', month: 'short', day: 'numeric' };
    nextBillingPreview.textContent = nextBillingDate.toLocaleDateString('en-US', options);
}

function updateStatus(subscriptionId, status) {
    // Use custom modal instead of confirm()
    if (typeof showConfirmModal === 'function') {
        showConfirmModal(
            'Confirm Status Change',
            'Are you sure you want to change this subscription status to: ' + status + '?',
            function() {
                submitStatusChange(subscriptionId, status);
            }
        );
    } else {
        // Fallback to native confirm
        if (confirm('Are you sure you want to change this subscription status to: ' + status + '?')) {
            submitStatusChange(subscriptionId, status);
        }
    }
}

function submitStatusChange(subscriptionId, status) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="subscription_id" value="${subscriptionId}">
        <input type="hidden" name="status" value="${status}">
        <input type="hidden" name="update_status" value="1">
    `;
    document.body.appendChild(form);
    form.submit();
}

// Custom Modal Functions (same as order-view.js)
function showConfirmModal(title, message, onConfirm, onCancel) {
    const modalId = 'customConfirmModal';
    let modal = document.getElementById(modalId);

    if (!modal) {
        const modalHTML = `
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="border: none; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
                        <div class="modal-header" style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: white; border: none; border-radius: 12px 12px 0 0; padding: 1.5rem;">
                            <h5 class="modal-title" id="${modalId}Label" style="font-weight: 600;">
                                <i class="bi bi-question-circle me-2"></i>
                                <span id="${modalId}Title"></span>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" style="padding: 2rem; font-size: 1.05rem; line-height: 1.6; color: #1e293b;">
                            <p id="${modalId}Message" style="margin: 0;"></p>
                        </div>
                        <div class="modal-footer" style="border: none; padding: 1rem 1.5rem 1.5rem; background: #f8fafc; border-radius: 0 0 12px 12px;">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 8px; padding: 0.6rem 1.5rem; font-weight: 500;">
                                <i class="bi bi-x-circle me-2"></i>Cancel
                            </button>
                            <button type="button" class="btn btn-primary" id="${modalId}Confirm" style="background: linear-gradient(135deg, #1e3a8a, #3b82f6); border: none; border-radius: 8px; padding: 0.6rem 1.5rem; font-weight: 500;">
                                <i class="bi bi-check-circle me-2"></i>Confirm
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        modal = document.getElementById(modalId);
    }

    document.getElementById(modalId + 'Title').textContent = title;
    document.getElementById(modalId + 'Message').textContent = message;

    const confirmBtn = document.getElementById(modalId + 'Confirm');
    confirmBtn.onclick = function() {
        const bsModal = bootstrap.Modal.getInstance(modal);
        if (bsModal) bsModal.hide();
        if (onConfirm) onConfirm();
    };

    modal.addEventListener('hidden.bs.modal', function handler() {
        if (onCancel && !confirmBtn.clicked) {
            onCancel();
        }
        modal.removeEventListener('hidden.bs.modal', handler);
        confirmBtn.clicked = false;
    });

    confirmBtn.addEventListener('click', function() {
        confirmBtn.clicked = true;
    });

    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
}

calculateTotal();

// Initialize next billing date preview on page load
document.addEventListener('DOMContentLoaded', function() {
    updateNextBillingDate();
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>
