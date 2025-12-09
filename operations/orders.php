<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Access control (Staff and Admin only)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'staff'])) {
    header('Location: /login.php');
    exit;
}

// Get supported currencies and default currency
$supportedCurrencies = [];
$defaultCurrency = 'GBP';
$exchangeRates = [];

if (class_exists('ConfigManager')) {
    $supportedCurrencies = ConfigManager::getSupportedCurrencies();
    $defaultCurrency = ConfigManager::get('business.default_currency', 'GBP');
    $exchangeRates = ConfigManager::getExchangeRates();
} else {
    // Fallback
    $supportedCurrencies = [
        'GBP' => ['symbol' => '£', 'name' => 'British Pound'],
        'USD' => ['symbol' => '$', 'name' => 'US Dollar'],
        'EUR' => ['symbol' => '€', 'name' => 'Euro'],
        'CAD' => ['symbol' => 'C$', 'name' => 'Canadian Dollar'],
        'AUD' => ['symbol' => 'A$', 'name' => 'Australian Dollar']
    ];
    $exchangeRates = [
        'GBP' => 1.0,
        'USD' => 1.27,
        'EUR' => 1.16,
        'CAD' => 1.71,
        'AUD' => 1.91
    ];
}

$defaultCurrencySymbol = $supportedCurrencies[$defaultCurrency]['symbol'] ?? '£';

// Handle order status changes with proper workflow validation
if (isset($_POST['update_status'])) {
    $order_id = (int)$_POST['order_id'];
    $new_status = $_POST['new_status'];
    $notes = trim($_POST['status_notes'] ?? '');

    try {
        // Get current order details including currency
        $stmt = $pdo->prepare("SELECT status, payment_status, total_amount, currency FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();

        if (!$order) {
            $error = "Order not found";
        } else {
            $current_status = $order['status'];
            $current_payment_status = $order['payment_status'];
            $order_currency = $order['currency'] ?? $defaultCurrency;
            
            // Validate status transitions
            $valid_transition = false;
            $additional_updates = [];

            switch ($new_status) {
                case 'placed':
                    $valid_transition = ($current_status === 'draft');
                    $additional_updates = ['placed_at' => 'NOW()'];
                    break;
                    
                case 'pending_payment':
                    $valid_transition = ($current_status === 'placed');
                    $additional_updates = ['payment_status' => 'pending'];
                    break;
                    
                case 'paid':
                    $valid_transition = ($current_status === 'pending_payment');
                    $additional_updates = [
                        'payment_status' => 'paid',
                        'payment_date' => 'NOW()'
                    ];
                    break;
                    
                case 'processing':
                    $valid_transition = ($current_status === 'paid');
                    $additional_updates = ['processed_at' => 'NOW()'];
                    break;
                    
                case 'completed':
                    $valid_transition = ($current_status === 'processing');
                    break;
                    
                case 'cancelled':
                    $valid_transition = in_array($current_status, ['draft', 'placed', 'pending_payment']);
                    break;
            }

            if (!$valid_transition) {
                $error = "Invalid status transition from $current_status to $new_status";
            } else {
                $pdo->beginTransaction();

                // Update order status
                $update_fields = ['status = ?', 'updated_at = NOW()'];
                $update_values = [$new_status];

                foreach ($additional_updates as $field => $value) {
                    $update_fields[] = "$field = $value";
                }

                $sql = "UPDATE orders SET " . implode(', ', $update_fields) . " WHERE id = ?";
                $update_values[] = $order_id;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($update_values);

                // Log status change
                $stmt = $pdo->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by, notes) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$order_id, $current_status, $new_status, $_SESSION['user']['id'], $notes]);

                // If order is now paid, record revenue with proper currency
                if ($new_status === 'paid') {
                    // Convert to default currency for revenue tracking
                    $revenue_amount = $order['total_amount'];
                    if ($order_currency !== $defaultCurrency) {
                        $exchange_rate = $exchangeRates[$order_currency] ?? 1.0;
                        $revenue_amount = $order['total_amount'] / $exchange_rate;
                    }
                    
                    $stmt = $pdo->prepare("INSERT INTO revenue_tracking (order_id, amount, currency, original_amount, original_currency, fiscal_year, fiscal_month) VALUES (?, ?, ?, ?, ?, YEAR(NOW()), MONTH(NOW())) ON DUPLICATE KEY UPDATE amount = ?, original_amount = ?");
                    $stmt->execute([$order_id, $revenue_amount, $defaultCurrency, $order['total_amount'], $order_currency, $revenue_amount, $order['total_amount']]);
                }

                $pdo->commit();
                $success = "Order status updated successfully!";
            }
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Error updating order status: " . $e->getMessage();
    }
}

// Handle order deletion
if (isset($_GET['delete_order'])) {
    $order_id = (int)$_GET['delete_order'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $success = "Order deleted successfully!";
    } catch (PDOException $e) {
        $error = "Error deleting order: " . $e->getMessage();
    }
}

// Get filters
$status_filter = $_GET['status'] ?? '';
$company_filter = $_GET['company'] ?? '';
$staff_filter = $_GET['staff'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query with filters
$where_conditions = [];
$params = [];

if (!empty($status_filter)) {
    $where_conditions[] = "o.status = ?";
    $params[] = $status_filter;
}

if (!empty($company_filter)) {
    $where_conditions[] = "o.company_id = ?";
    $params[] = $company_filter;
}

if (!empty($staff_filter)) {
    $where_conditions[] = "o.staff_id = ?";
    $params[] = $staff_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(o.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(o.created_at) <= ?";
    $params[] = $date_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get orders with filters and currency info
$stmt = $pdo->prepare("SELECT o.*, c.name as company_name, c.preferred_currency, u.username as staff_name,
    COUNT(oi.id) as item_count
    FROM orders o
    JOIN companies c ON o.company_id = c.id
    JOIN users u ON o.staff_id = u.id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    $where_clause
    GROUP BY o.id
    ORDER BY o.created_at DESC");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Get filter options
$stmt = $pdo->query("SELECT id, name FROM companies ORDER BY name ASC");
$companies = $stmt->fetchAll();

$stmt = $pdo->query("SELECT id, username FROM users WHERE role IN ('administrator', 'staff') ORDER BY username ASC");
$staff_users = $stmt->fetchAll();

// Get order statistics with multi-currency support
$stmt = $pdo->query("SELECT 
    COUNT(*) as total_orders,
    COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft_orders,
    COUNT(CASE WHEN status = 'placed' THEN 1 END) as placed_orders,
    COUNT(CASE WHEN status = 'pending_payment' THEN 1 END) as pending_payment_orders,
    COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_orders,
    COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing_orders,
    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_orders,
    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_orders
    FROM orders");
$stats = $stmt->fetch();

// Calculate revenue in default currency
$revenue_stmt = $pdo->query("SELECT 
    SUM(CASE WHEN payment_status = 'paid' THEN 
        CASE WHEN currency = '$defaultCurrency' THEN total_amount
        ELSE total_amount / (
            CASE currency 
                WHEN 'USD' THEN " . ($exchangeRates['USD'] ?? 1.27) . "
                WHEN 'EUR' THEN " . ($exchangeRates['EUR'] ?? 1.16) . "
                WHEN 'CAD' THEN " . ($exchangeRates['CAD'] ?? 1.71) . "
                WHEN 'AUD' THEN " . ($exchangeRates['AUD'] ?? 1.91) . "
                ELSE 1
            END
        )
        END
    ELSE 0 END) as paid_revenue,
    SUM(CASE WHEN status IN ('placed', 'pending_payment', 'paid', 'processing', 'completed') THEN 
        CASE WHEN currency = '$defaultCurrency' THEN total_amount
        ELSE total_amount / (
            CASE currency 
                WHEN 'USD' THEN " . ($exchangeRates['USD'] ?? 1.27) . "
                WHEN 'EUR' THEN " . ($exchangeRates['EUR'] ?? 1.16) . "
                WHEN 'CAD' THEN " . ($exchangeRates['CAD'] ?? 1.71) . "
                WHEN 'AUD' THEN " . ($exchangeRates['AUD'] ?? 1.91) . "
                ELSE 1
            END
        )
        END
    ELSE 0 END) as pipeline_value,
    AVG(CASE WHEN payment_status = 'paid' THEN 
        CASE WHEN currency = '$defaultCurrency' THEN total_amount
        ELSE total_amount / (
            CASE currency 
                WHEN 'USD' THEN " . ($exchangeRates['USD'] ?? 1.27) . "
                WHEN 'EUR' THEN " . ($exchangeRates['EUR'] ?? 1.16) . "
                WHEN 'CAD' THEN " . ($exchangeRates['CAD'] ?? 1.71) . "
                WHEN 'AUD' THEN " . ($exchangeRates['AUD'] ?? 1.91) . "
                ELSE 1
            END
        )
        END
    END) as avg_paid_order_value
    FROM orders");
$revenue_stats = $revenue_stmt->fetch();

// Merge stats
$stats = array_merge($stats, $revenue_stats);

$page_title = "Order Management | CaminhoIT";
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php';
?>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="dashboard-hero-content">
            <h1 class="dashboard-hero-title">
                <i class="bi bi-cart me-2"></i>
                Order Management
            </h1>
            <p class="dashboard-hero-subtitle">
                Track and manage all customer orders with comprehensive oversight
            </p>
            <div class="dashboard-hero-actions">
                <a href="/operations/create-order.php" class="btn c-btn-primary">
                    <i class="bi bi-plus-circle me-1"></i>
                    Create Order
                </a>
            </div>
        </div>
    </div>
</header>
?>
    <style>
        :root {
            --primary-color: #4F46E5;
            --success-color: #10B981;
            --warning-color: #F59E0B;
            --danger-color: #EF4444;
            --info-color: #06B6D4;
        }

        body {
            background-color: #f8fafc;
            padding-top: 80px;
        }

        .navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15) !important;
            position: fixed !important;
            top: 0 !important;
            z-index: 1030 !important;
        }

        .main-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .page-header {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-label {
            color: #6b7280;
            font-size: 0.875rem;
        }

        .currency-note {
            color: #6b7280;
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }

        .filters-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .orders-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .table th {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
            color: #374151;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-draft { background: #f3f4f6; color: #374151; }
        .badge-placed { background: #dbeafe; color: #1e40af; }
        .badge-pending_payment { background: #fef3c7; color: #92400e; }
        .badge-paid { background: #d1fae5; color: #065f46; }
        .badge-processing { background: #e0e7ff; color: #3730a3; }
        .badge-completed { background: #d1fae5; color: #065f46; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }

        .currency-badge {
            background: #f3f4f6;
            color: #374151;
            padding: 0.125rem 0.5rem;
            border-radius: 4px;
            font-size: 0.625rem;
            font-weight: 500;
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: #3f37c9;
        }

        .alert {
            border: none;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .order-actions {
            display: flex;
            gap: 0.25rem;
        }

        .form-control, .form-select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0.5rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .workflow-info {
            background: #f0f9ff;
            border: 1px solid #e0e7ff;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        /* Dark Mode Styles */
        :root.dark body {
            background-color: #0f172a !important;
        }

        :root.dark .main-container {
            background: transparent !important;
        }

        /* Page Header */
        :root.dark .page-header {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .page-header h1 {
            color: #f1f5f9 !important;
        }

        :root.dark .page-header p {
            color: #94a3b8 !important;
        }

        /* Stat Cards */
        :root.dark .stat-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .stat-value {
            color: #a78bfa !important;
        }

        :root.dark .stat-label {
            color: #94a3b8 !important;
        }

        :root.dark .currency-note {
            color: #94a3b8 !important;
        }

        /* Filters Card */
        :root.dark .filters-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .filters-card h5 {
            color: #f1f5f9 !important;
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
        }

        /* Orders Table */
        :root.dark .orders-table {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .table-responsive {
            background: #1e293b !important;
        }

        :root.dark .table {
            color: #e2e8f0 !important;
            background: #1e293b !important;
        }

        :root.dark .table th {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #cbd5e1 !important;
        }

        :root.dark .table tbody tr {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .table tbody tr:hover {
            background: rgba(139, 92, 246, 0.15) !important;
        }

        :root.dark .table tbody td {
            color: #e2e8f0 !important;
            border-color: #334155 !important;
        }

        :root.dark .table tbody td strong {
            color: #f1f5f9 !important;
        }

        :root.dark .table tbody td small {
            color: #94a3b8 !important;
        }

        /* Badges */
        :root.dark .badge-draft {
            background: #334155 !important;
            color: #cbd5e1 !important;
        }

        :root.dark .badge-placed {
            background: #1e3a8a !important;
            color: #bfdbfe !important;
        }

        :root.dark .badge-pending_payment {
            background: #92400e !important;
            color: #fde68a !important;
        }

        :root.dark .badge-paid {
            background: #065f46 !important;
            color: #a7f3d0 !important;
        }

        :root.dark .badge-processing {
            background: #7c2d12 !important;
            color: #fed7aa !important;
        }

        :root.dark .badge-completed {
            background: #065f46 !important;
            color: #a7f3d0 !important;
        }

        :root.dark .badge-cancelled {
            background: #7f1d1d !important;
            color: #fca5a5 !important;
        }

        /* Text and General Elements */
        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark h1, :root.dark h2, :root.dark h3, :root.dark h4, :root.dark h5 {
            color: #f1f5f9 !important;
        }

        :root.dark .card,
        :root.dark .card-title {
            background: #1e293b !important;
            color: #f1f5f9 !important;
            border-color: #334155 !important;
        }

        /* Alerts */
        :root.dark .alert-success {
            background: #064e3b !important;
            border-color: #065f46 !important;
            color: #a7f3d0 !important;
        }

        :root.dark .alert-danger {
            background: #7f1d1d !important;
            border-color: #991b1b !important;
            color: #fca5a5 !important;
        }

        /* Modal */
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

        /* Workflow Info */
        :root.dark .workflow-info {
            background: #0c4a6e !important;
            border-color: #075985 !important;
            color: #bae6fd !important;
        }

        /* Order Number Link */
        .order-number-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }

        .order-number-link:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        :root.dark .order-number-link {
            color: #a78bfa;
        }

        :root.dark .order-number-link:hover {
            color: #c4b5fd;
        }
    </style>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-cart me-3"></i>Order Management</h1>
                <p class="text-muted mb-0">Manage company orders and track their status</p>
            </div>
            <div>
                <a href="create-order.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-2"></i>Create New Order
                </a>
            </div>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success)): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i><?= $success ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i><?= $error ?>
        </div>
    <?php endif; ?>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['total_orders'] ?? 0) ?></div>
            <div class="stat-label">Total Orders</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['draft_orders'] ?? 0) ?></div>
            <div class="stat-label">Draft Orders</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['pending_payment_orders'] ?? 0) ?></div>
            <div class="stat-label">Pending Payment</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['processing_orders'] ?? 0) ?></div>
            <div class="stat-label">Processing</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $defaultCurrencySymbol ?><?= number_format($stats['paid_revenue'] ?? 0, 2) ?></div>
            <div class="stat-label">Paid Revenue</div>
            <div class="currency-note">Converted to <?= $defaultCurrency ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $defaultCurrencySymbol ?><?= number_format($stats['pipeline_value'] ?? 0, 2) ?></div>
            <div class="stat-label">Pipeline Value</div>
            <div class="currency-note">Converted to <?= $defaultCurrency ?></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <h5 class="mb-3">Filter Orders</h5>
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="draft" <?= $status_filter === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="placed" <?= $status_filter === 'placed' ? 'selected' : '' ?>>Placed</option>
                    <option value="pending_payment" <?= $status_filter === 'pending_payment' ? 'selected' : '' ?>>Pending Payment</option>
                    <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="processing" <?= $status_filter === 'processing' ? 'selected' : '' ?>>Processing</option>
                    <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Company</label>
                <select name="company" class="form-select">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $company): ?>
                        <option value="<?= $company['id'] ?>" <?= $company_filter == $company['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($company['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">Filter</button>
                <a href="orders.php" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>

    <!-- Orders Table -->
    <div class="orders-table">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Company</th>
                        <th>Staff</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <i class="bi bi-cart-x" style="font-size: 3rem; color: #d1d5db;"></i>
                                <p class="text-muted mt-2">No orders found</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <?php
                            $order_currency = $order['currency'] ?? $defaultCurrency;
                            $currency_symbol = $supportedCurrencies[$order_currency]['symbol'] ?? $defaultCurrencySymbol;
                            ?>
                            <tr>
                                <td>
                                    <a href="view-order.php?id=<?= $order['id'] ?>" class="order-number-link">
                                        <strong><?= htmlspecialchars($order['order_number']) ?></strong>
                                    </a>
                                    <br><small class="text-muted"><?= ucfirst($order['order_type']) ?></small>
                                </td>
                                <td>
                                    <?= htmlspecialchars($order['company_name']) ?>
                                    <?php if ($order['preferred_currency'] && $order['preferred_currency'] !== $defaultCurrency): ?>
                                        <br><small class="currency-badge"><?= $order['preferred_currency'] ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($order['staff_name']) ?></td>
                                <td><?= $order['item_count'] ?> items</td>
                                <td>
                                    <?= $currency_symbol ?><?= number_format($order['total_amount'], 2) ?>
                                    <?php if ($order_currency !== $defaultCurrency): ?>
                                        <br><small class="currency-badge"><?= $order_currency ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $order['status'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $order['status'])) ?>
                                    </span>
                                </td>
                                <td><?= date('d M Y', strtotime($order['created_at'])) ?></td>
                                <td>
                                    <div class="order-actions">
                                        <a href="view-order.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="edit-order.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-warning">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button class="btn btn-sm btn-outline-info" onclick="updateOrderStatus(<?= $order['id'] ?>, '<?= $order['status'] ?>')">
                                            <i class="bi bi-arrow-repeat"></i>
                                        </button>
                                        <a href="?delete_order=<?= $order['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this order?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
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

<!-- Update Status Modal -->
<div class="modal fade" id="updateStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Order Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="order_id" id="status_order_id">
                
                <div class="mb-3">
                    <label class="form-label">Current Status</label>
                    <input type="text" class="form-control" id="current_status" readonly>
                </div>
                
                <div class="workflow-info">
                    <strong>Order Workflow:</strong> Draft → Placed → Pending Payment → Paid → Processing → Completed
                </div>
                
                <div class="mb-3">
                    <label class="form-label">New Status</label>
                    <select name="new_status" class="form-select" required id="new_status_select">
                        <option value="">Select new status...</option>
                        <!-- Options will be populated by JavaScript based on current status -->
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="status_notes" class="form-control" rows="3" placeholder="Optional notes about this status change"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
            </div>
        </form>
    </div>
</div>

<script>
function updateOrderStatus(orderId, currentStatus) {
    document.getElementById('status_order_id').value = orderId;
    document.getElementById('current_status').value = currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1).replace('_', ' ');
    
    // Define valid next statuses based on current status
    const statusTransitions = {
        'draft': ['placed', 'cancelled'],
        'placed': ['pending_payment', 'cancelled'],
        'pending_payment': ['paid', 'cancelled'],
        'paid': ['processing'],
        'processing': ['completed'],
        'completed': [],
        'cancelled': []
    };
    
    const statusLabels = {
        'draft': 'Draft',
        'placed': 'Placed',
        'pending_payment': 'Pending Payment',
        'paid': 'Paid',
        'processing': 'Processing',
        'completed': 'Completed',
        'cancelled': 'Cancelled'
    };
    
    const nextStatuses = statusTransitions[currentStatus] || [];
    const selectElement = document.getElementById('new_status_select');
    
    // Clear existing options
    selectElement.innerHTML = '<option value="">Select new status...</option>';
    
    // Add valid transition options
    nextStatuses.forEach(status => {
        const option = document.createElement('option');
        option.value = status;
        option.textContent = statusLabels[status];
        selectElement.appendChild(option);
    });
    
    // If no valid transitions, show message
    if (nextStatuses.length === 0) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'No valid status transitions available';
        option.disabled = true;
        selectElement.appendChild(option);
    }
    
    const modal = new bootstrap.Modal(document.getElementById('updateStatusModal'));
    modal.show();
}
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>