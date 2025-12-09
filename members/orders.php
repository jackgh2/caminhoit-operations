<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

// Access control (Administrator and Account Manager only)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'account_manager'])) {
    header('Location: /login.php');
    exit;
}

$user_id = $_SESSION['user']['id'];

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

// Get filters
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$order_type_filter = $_GET['order_type'] ?? '';

// Build query with filters - only show orders for companies user has access to
$where_conditions = [];
$params = [];

// Add company access restriction
$where_conditions[] = "(o.company_id = (SELECT company_id FROM users WHERE id = ?) 
                       OR o.company_id IN (SELECT company_id FROM company_users WHERE user_id = ?))";
$params[] = $user_id;
$params[] = $user_id;

if (!empty($status_filter)) {
    $where_conditions[] = "o.status = ?";
    $params[] = $status_filter;
}

if (!empty($order_type_filter)) {
    $where_conditions[] = "o.order_type = ?";
    $params[] = $order_type_filter;
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
$stmt = $pdo->prepare("SELECT o.*, c.name as company_name, c.preferred_currency, 
    COALESCE(u.username, 'System') as staff_name,
    COUNT(oi.id) as item_count
    FROM orders o
    JOIN companies c ON o.company_id = c.id
    LEFT JOIN users u ON o.staff_id = u.id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    $where_clause
    GROUP BY o.id
    ORDER BY o.created_at DESC");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Get companies user has access to for filter
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.name
    FROM companies c
    WHERE c.id = (SELECT company_id FROM users WHERE id = ?)
       OR c.id IN (SELECT company_id FROM company_users WHERE user_id = ?)
    ORDER BY c.name ASC
");
$stmt->execute([$user_id, $user_id]);
$user_companies = $stmt->fetchAll();

// Get order statistics for user's companies
$stats_params = [$user_id, $user_id];
$stmt = $pdo->prepare("SELECT 
    COUNT(*) as total_orders,
    COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft_orders,
    COUNT(CASE WHEN status = 'placed' THEN 1 END) as placed_orders,
    COUNT(CASE WHEN status = 'pending_payment' THEN 1 END) as pending_payment_orders,
    COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_orders,
    COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing_orders,
    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_orders,
    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_orders
    FROM orders o
    WHERE (o.company_id = (SELECT company_id FROM users WHERE id = ?) 
           OR o.company_id IN (SELECT company_id FROM company_users WHERE user_id = ?))");
$stmt->execute($stats_params);
$stats = $stmt->fetch();

// Calculate revenue in default currency for user's companies
$revenue_stmt = $pdo->prepare("SELECT 
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
    ELSE 0 END) as pipeline_value
    FROM orders o
    WHERE (o.company_id = (SELECT company_id FROM users WHERE id = ?) 
           OR o.company_id IN (SELECT company_id FROM company_users WHERE user_id = ?))");
$revenue_stmt->execute($stats_params);
$revenue_stats = $revenue_stmt->fetch();

// Merge stats
$stats = array_merge($stats ?: [], $revenue_stats ?: []);

$page_title = "My Orders | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --card-shadow-hover: 0 20px 40px rgba(0, 0, 0, 0.15);
            --border-radius: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Hero Section */
        .orders-hero-content {
            text-align: center;
            padding: 4rem 0;
            position: relative;
            z-index: 2;
        }

        .orders-hero-title {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: white;
        }

        .orders-hero-subtitle {
            font-size: 1.25rem;
            margin-bottom: 2rem;
            opacity: 0.9;
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
            margin-top: -60px;
            position: relative;
            z-index: 10;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 2rem 1.5rem;
            text-align: center;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--card-shadow-hover);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #64748B;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .currency-note {
            color: #6b7280;
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }

        .filters-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 0;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .filters-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .filters-card h5 {
            background: linear-gradient(135deg, #f8fafc 0%, #e9ecef 100%);
            color: #667eea;
            margin: 0 !important;
            padding: 1.25rem 1.5rem;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
        }

        .filters-card form {
            padding: 1.5rem;
        }

        .orders-table {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            position: relative;
        }

        .orders-table::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
            z-index: 1;
        }

        .table thead tr {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .table th {
            background: transparent !important;
            border-bottom: none !important;
            border-right: 1px solid rgba(255,255,255,0.1) !important;
            font-weight: 600;
            color: white;
            padding: 1rem;
        }

        .table th:last-child {
            border-right: none !important;
        }

        .table tbody tr {
            transition: var(--transition);
        }

        .table tbody tr:hover {
            background: rgba(102, 126, 234, 0.05);
            transform: scale(1.01);
        }

        .badge {
            padding: 0.4rem 1rem;
            border-radius: 25px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-draft {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #374151;
        }
        .badge-placed {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }
        .badge-pending_payment {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }
        .badge-paid {
            background: var(--success-gradient);
            color: white;
        }
        .badge-processing {
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            color: #3730a3;
        }
        .badge-completed {
            background: var(--success-gradient);
            color: white;
        }
        .badge-cancelled {
            background: var(--danger-gradient);
            color: white;
        }

        .currency-badge {
            background: #f3f4f6;
            color: #374151;
            padding: 0.125rem 0.5rem;
            border-radius: 4px;
            font-size: 0.625rem;
            font-weight: 500;
        }

        .role-indicator {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 0.5rem;
            background: #e0e7ff;
            color: #3730a3;
        }

        .order-actions {
            display: flex;
            gap: 0.25rem;
        }

        .empty-state {
            text-align: center;
            color: #6b7280;
            padding: 4rem 2rem;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        /* DARK MODE STYLES */
        html.dark {
            background: #0f172a;
            color: #e2e8f0;
        }

        html.dark body {
            background: #0f172a;
            color: #e2e8f0;
        }

        /* FORCE purple hero gradient to show in dark mode - SAME as light mode */
        :root.dark .hero {
            background: transparent !important;
        }

        :root.dark .hero-gradient {
            /* Don't override the background - keep it the same as light mode! */
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

        :root.dark .orders-hero-title,
        :root.dark .orders-hero-subtitle {
            color: white !important;
            position: relative;
            z-index: 2;
        }

        html.dark .stat-card {
            background: #1e293b;
            border-color: #334155;
        }

        html.dark .stat-value {
            color: #a78bfa;
        }

        html.dark .stat-label {
            color: #94a3b8;
        }

        html.dark .filters-card {
            background: #1e293b;
            border-color: #334155;
        }

        html.dark .filters-card h5 {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            color: #a78bfa;
            border-color: #334155;
        }

        html.dark .form-label {
            color: #cbd5e1;
        }

        html.dark .form-control,
        html.dark .form-select {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }

        html.dark .form-control:focus,
        html.dark .form-select:focus {
            background: #0f172a;
            border-color: #8b5cf6;
            color: #e2e8f0;
        }

        html.dark .orders-table {
            background: #1e293b;
            border-color: #334155;
        }

        html.dark .table {
            color: #e2e8f0;
        }

        :root.dark .table tbody {
            background: #1e293b !important;
        }

        :root.dark .table tbody tr {
            background: #1e293b !important;
        }

        :root.dark .table tbody tr:hover {
            background: rgba(139, 92, 246, 0.1) !important;
        }

        :root.dark .table tbody td {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .table tbody td span,
        :root.dark .table tbody td a,
        :root.dark .table tbody td div {
            color: #e2e8f0 !important;
        }

        html.dark .currency-badge {
            background: #334155;
            color: #94a3b8;
        }

        html.dark .currency-note {
            color: #94a3b8;
        }

        html.dark .empty-state {
            color: #94a3b8;
        }

        html.dark small {
            color: #94a3b8;
        }

        html.dark .btn-primary {
            background: var(--primary-gradient);
            color: white;
        }

        html.dark .btn-outline-primary {
            color: #a78bfa;
            border-color: #a78bfa;
        }

        html.dark .btn-outline-primary:hover {
            background: var(--primary-gradient);
            color: white;
        }

    </style>

<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="orders-hero-content">
            <h1 class="orders-hero-title">
                <i class="bi bi-receipt me-3"></i>My Orders
            </h1>
            <p class="orders-hero-subtitle">
                View and manage orders for your organization
            </p>
            <span class="role-indicator"><?= ucfirst(str_replace('_', ' ', $_SESSION['user']['role'])) ?></span>
        </div>
    </div>
</header>

<div class="container py-5 overlap-cards">
    <!-- Success/Error Messages -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success mb-4">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars(urldecode($_GET['success'])) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger mb-4">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars(urldecode($_GET['error'])) ?>
        </div>
    <?php endif; ?>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['total_orders'] ?? 0) ?></div>
            <div class="stat-label">Total Orders</div>
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
            <div class="stat-value"><?= number_format($stats['completed_orders'] ?? 0) ?></div>
            <div class="stat-label">Completed</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $defaultCurrencySymbol ?><?= number_format($stats['paid_revenue'] ?? 0, 2) ?></div>
            <div class="stat-label">Total Paid</div>
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
        <h5 class="mb-3"><i class="bi bi-funnel me-2"></i>Filter Orders</h5>
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
                <label class="form-label">Order Type</label>
                <select name="order_type" class="form-select">
                    <option value="">All Types</option>
                    <option value="new" <?= $order_type_filter === 'new' ? 'selected' : '' ?>>New Service</option>
                    <option value="upgrade" <?= $order_type_filter === 'upgrade' ? 'selected' : '' ?>>Upgrade</option>
                    <option value="addon" <?= $order_type_filter === 'addon' ? 'selected' : '' ?>>Add-on</option>
                    <option value="renewal" <?= $order_type_filter === 'renewal' ? 'selected' : '' ?>>Renewal</option>
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
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                <a href="orders.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-clockwise me-1"></i>Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Quick Actions -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3><i class="bi bi-list-ul me-2"></i>Order History</h3>
        <a href="create-order.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-2"></i>Create New Order
        </a>
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
                            <td colspan="8">
                                <div class="empty-state">
                                    <i class="bi bi-receipt"></i>
                                    <h4>No Orders Found</h4>
                                    <p class="mb-3">You haven't placed any orders yet, or no orders match your current filters.</p>
                                    <a href="create-order.php" class="btn btn-primary">
                                        <i class="bi bi-plus-circle me-2"></i>Create Your First Order
                                    </a>
                                </div>
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
                                    <strong><?= htmlspecialchars($order['order_number']) ?></strong>
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
                                        <a href="view-order.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-primary" title="View Order">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if (in_array($order['status'], ['draft'])): ?>
                                            <a href="edit-order.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-warning" title="Edit Order">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($order['status'] === 'completed'): ?>
                                            <a href="print-order.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-success" title="Download PDF">
                                                <i class="bi bi-file-pdf"></i>
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

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
