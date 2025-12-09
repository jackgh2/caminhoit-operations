<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/DiscordNotifications.php';

// Access control (Staff and Admin only)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'staff'])) {
    header('Location: /login.php');
    exit;
}

$order_id = (int)($_GET['id'] ?? 0);

if (!$order_id) {
    header('Location: orders.php');
    exit;
}

// Get supported currencies and default currency
$supportedCurrencies = [];
$defaultCurrency = 'GBP';
$exchangeRates = [];
$vatSettings = [];

if (class_exists('ConfigManager')) {
    $supportedCurrencies = ConfigManager::getSupportedCurrencies();
    $defaultCurrency = ConfigManager::get('business.default_currency', 'GBP');
    $exchangeRates = ConfigManager::getExchangeRates();
    
    // Get VAT settings for each currency
    $vatSettings = [
        'enabled' => ConfigManager::isVatRegistered(),
        'default_rate' => ConfigManager::get('tax.default_vat_rate', 0.20),
        'currency_settings' => ConfigManager::get('tax.currency_vat_settings', [
            'GBP' => ['enabled' => true, 'rate' => 0.20],
            'USD' => ['enabled' => false, 'rate' => 0.00],
            'EUR' => ['enabled' => true, 'rate' => 0.20],
            'CAD' => ['enabled' => false, 'rate' => 0.00],
            'AUD' => ['enabled' => false, 'rate' => 0.00]
        ])
    ];
} else {
    // Fallback when ConfigManager is not available
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
    
    // Fallback VAT settings
    $vatSettings = [
        'enabled' => false,
        'default_rate' => 0.20,
        'currency_settings' => [
            'GBP' => ['enabled' => true, 'rate' => 0.20],
            'USD' => ['enabled' => false, 'rate' => 0.00],
            'EUR' => ['enabled' => true, 'rate' => 0.20],
            'CAD' => ['enabled' => false, 'rate' => 0.00],
            'AUD' => ['enabled' => false, 'rate' => 0.00]
        ]
    ];
}

// Define all possible order statuses
$order_statuses = [
    'draft' => 'Draft',
    'placed' => 'Placed',
    'pending_payment' => 'Pending Payment',
    'paid' => 'Paid',
    'processing' => 'Processing',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'refunded' => 'Refunded',
    'on_hold' => 'On Hold',
    'failed' => 'Failed'
];

// Handle order status update
if (isset($_POST['update_status'])) {
    $new_status = $_POST['new_status'];
    $notes = trim($_POST['status_notes'] ?? '');

    try {
        // Get current status
        $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $current_status = $stmt->fetchColumn();

        if ($current_status !== false && $current_status !== $new_status) {
            // Update order status
            $stmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $order_id]);

            // Log status change
            $stmt = $pdo->prepare("INSERT INTO order_status_history (order_id, status_from, status_to, changed_by, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$order_id, $current_status, $new_status, $_SESSION['user']['id'], $notes]);

            // Send Discord notification for ALL status changes
            $discord = new DiscordNotifications($pdo);
            $changed_by_name = $_SESSION['user']['username'] ?? 'Staff';
            $discord->notifyOrderStatusChange($order_id, $current_status, $new_status, $changed_by_name);

            $success = "Order status updated successfully from " . ucfirst(str_replace('_', ' ', $current_status)) . " to " . ucfirst(str_replace('_', ' ', $new_status)) . "!";

            // Refresh the page to show updated status
            header("Location: view-order.php?id=$order_id&success=" . urlencode($success));
            exit;
        }
    } catch (PDOException $e) {
        $error = "Error updating order status: " . $e->getMessage();
    }
}

// Get order details with currency and VAT information
$stmt = $pdo->prepare("SELECT o.*, c.name as company_name, c.phone as company_phone, c.address as company_address,
    c.preferred_currency, c.currency_override,
    u.username as staff_name, u.email as staff_email
    FROM orders o
    JOIN companies c ON o.company_id = c.id
    JOIN users u ON o.staff_id = u.id
    WHERE o.id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: orders.php?error=order_not_found');
    exit;
}

// Get order items with currency
$stmt = $pdo->prepare("SELECT oi.*, p.name as product_name, b.name as bundle_name
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.id
    LEFT JOIN service_bundles b ON oi.bundle_id = b.id
    WHERE oi.order_id = ?
    ORDER BY oi.created_at ASC");
$stmt->execute([$order_id]);
$order_items = $stmt->fetchAll();

// Get status history
$stmt = $pdo->prepare("SELECT osh.*, u.username as changed_by_name
    FROM order_status_history osh
    JOIN users u ON osh.changed_by = u.id
    WHERE osh.order_id = ?
    ORDER BY osh.created_at DESC");
$stmt->execute([$order_id]);
$status_history = $stmt->fetchAll();

// Calculate totals
$setup_fees_total = array_sum(array_map(function($item) { return $item['setup_fee'] * $item['quantity']; }, $order_items));

// Get currency information
$order_currency = $order['currency'] ?? $defaultCurrency;
$currency_symbol = $supportedCurrencies[$order_currency]['symbol'] ?? '£';
$currency_name = $supportedCurrencies[$order_currency]['name'] ?? $order_currency;

// Get VAT information for this order
$vat_enabled = $order['vat_enabled'] ?? false;
$vat_rate = $order['vat_rate'] ?? 0.20;
$vat_percentage = ($vat_rate * 100);

// Get exchange rate information
$exchange_rate = 1.0;
$is_converted = false;
if ($order_currency !== $defaultCurrency) {
    $exchange_rate = $exchangeRates[$order_currency] ?? 1.0;
    $is_converted = true;
}

// Check for success message
if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}

$page_title = "Order #" . $order['order_number'] . " | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

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
            max-width: 1200px;
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

        .breadcrumb {
            background: none;
            padding: 0;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .breadcrumb-item a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .breadcrumb-item a:hover {
            text-decoration: underline;
        }

        .order-details-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .order-items-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .currency-info {
            background: #f0f9ff;
            border: 1px solid #3b82f6;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 2rem;
        }

        .currency-badge {
            background: var(--primary-color);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .currency-badge.default {
            background: #6b7280;
        }

        .vat-info {
            background: #f0fdf4;
            border: 1px solid #10b981;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .vat-info.disabled {
            background: #f3f4f6;
            border-color: #6b7280;
            color: #6b7280;
        }

        .conversion-info {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }

        .order-summary {
            background: white;
            border: 2px solid var(--primary-color);
            border-radius: 12px;
            padding: 2rem;
            position: sticky;
            top: 100px;
        }

        .summary-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 1.5rem;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-row.total {
            border-top: 2px solid #e2e8f0;
            margin-top: 1rem;
            padding-top: 1rem;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #374151;
        }

        .info-value {
            color: #6b7280;
            text-align: right;
        }

        .order-item-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-draft { background: #f3f4f6; color: #374151; }
        .status-placed { background: #dbeafe; color: #1e40af; }
        .status-pending_payment { background: #fef3c7; color: #92400e; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-processing { background: #e0e7ff; color: #3730a3; }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .status-refunded { background: #fef3c7; color: #92400e; }
        .status-on_hold { background: #f3f4f6; color: #374151; }
        .status-failed { background: #fee2e2; color: #991b1b; }

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

        .alert-info {
            background: #f0f9ff;
            color: #1e40af;
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

        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-outline-primary:hover {
            background: var(--primary-color);
            color: white;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 1.5rem;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.5rem;
            top: 0.25rem;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--primary-color);
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 2rem;
        }

        .notes-section {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #f3f4f6;
        }

        .notes-text {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            color: #6b7280;
            font-style: italic;
        }
        
        .workflow-indicator {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .workflow-steps {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
            color: #6b7280;
        }

        .workflow-step {
            text-align: center;
            flex: 1;
        }

        .workflow-step.active {
            color: var(--primary-color);
            font-weight: 600;
        }

        .workflow-arrow {
            color: #d1d5db;
            margin: 0 0.5rem;
        }

        .status-dropdown {
            display: none;
            position: absolute;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            min-width: 200px;
        }

        .status-dropdown.show {
            display: block;
        }

        /* Print styles */
        @media print {
            body { padding-top: 0; }
            .navbar, .btn, .alert { display: none !important; }
            .main-container { margin: 0; max-width: none; }
            .page-header { box-shadow: none; }
        }

        /* Dark Mode Styles */
        :root.dark body {
            background: #0f172a !important;
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

        :root.dark .breadcrumb {
            background: transparent !important;
        }

        :root.dark .breadcrumb-item a {
            color: #a78bfa !important;
        }

        :root.dark .breadcrumb-item.active {
            color: #94a3b8 !important;
        }

        /* Order Header Card */
        :root.dark .order-header {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .order-header h2 {
            color: #f1f5f9 !important;
        }

        :root.dark .order-meta span {
            color: #cbd5e1 !important;
        }

        /* Info Sections */
        :root.dark .info-section {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .info-section h3 {
            color: #f1f5f9 !important;
        }

        :root.dark .info-row label {
            color: #94a3b8 !important;
        }

        :root.dark .info-row span,
        :root.dark .info-row p {
            color: #cbd5e1 !important;
        }

        /* Order Items Table */
        :root.dark .order-items {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .table {
            color: #e2e8f0 !important;
            background: transparent !important;
        }

        :root.dark .table thead {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
        }

        :root.dark .table thead th {
            color: white !important;
            border-color: transparent !important;
        }

        :root.dark .table tbody tr {
            background: transparent !important;
            border-color: #334155 !important;
        }

        :root.dark .table tbody tr:hover {
            background: rgba(139, 92, 246, 0.1) !important;
        }

        :root.dark .table tbody td {
            color: #e2e8f0 !important;
            border-color: #334155 !important;
        }

        :root.dark .table tfoot {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .table tfoot td {
            color: #cbd5e1 !important;
        }

        /* Timeline */
        :root.dark .timeline-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .timeline-card h3 {
            color: #f1f5f9 !important;
        }

        :root.dark .timeline-item {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .timeline-item h5 {
            color: #f1f5f9 !important;
        }

        :root.dark .timeline-item p {
            color: #cbd5e1 !important;
        }

        :root.dark .timeline-meta {
            color: #94a3b8 !important;
        }

        /* Badges */
        :root.dark .badge {
            background: #334155 !important;
            color: #cbd5e1 !important;
        }

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

        /* Forms */
        :root.dark .form-control,
        :root.dark .form-select,
        :root.dark textarea {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .form-control:focus,
        :root.dark .form-select:focus,
        :root.dark textarea:focus {
            background: #1e293b !important;
            border-color: #8b5cf6 !important;
        }

        :root.dark .form-label {
            color: #cbd5e1 !important;
        }

        /* Modals */
        :root.dark .modal-content {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .modal-header {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
            color: white !important;
            border-color: transparent !important;
        }

        :root.dark .modal-title {
            color: white !important;
        }

        :root.dark .modal-body,
        :root.dark .modal-footer {
            border-color: #334155 !important;
        }

        :root.dark .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        /* Alerts */
        :root.dark .alert-success {
            background: rgba(6, 95, 70, 0.3) !important;
            color: #a7f3d0 !important;
            border-color: #10b981 !important;
        }

        :root.dark .alert-danger {
            background: rgba(127, 29, 29, 0.3) !important;
            color: #fca5a5 !important;
            border-color: #ef4444 !important;
        }

        :root.dark .alert-warning {
            background: rgba(146, 64, 14, 0.3) !important;
            color: #fde68a !important;
            border-color: #f59e0b !important;
        }

        :root.dark .alert-info {
            background: rgba(30, 58, 138, 0.3) !important;
            color: #bfdbfe !important;
            border-color: #3b82f6 !important;
        }

        /* Text */
        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark h1, :root.dark h2, :root.dark h3, :root.dark h4, :root.dark h5 {
            color: #f1f5f9 !important;
        }

        :root.dark p {
            color: #cbd5e1 !important;
        }

        /* Status Dropdown */
        :root.dark .status-dropdown {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .status-dropdown button {
            background: #0f172a !important;
            color: #cbd5e1 !important;
            border-color: #334155 !important;
        }

        :root.dark .status-dropdown button:hover {
            background: #1e293b !important;
        }
</style>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="orders.php">Orders</a></li>
                <li class="breadcrumb-item active">Order #<?= htmlspecialchars($order['order_number']) ?></li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-receipt me-3"></i>Order #<?= htmlspecialchars($order['order_number']) ?></h1>
                <p class="text-muted mb-0">
                    Created on <?= date('d M Y \a\t H:i', strtotime($order['created_at'])) ?> by <?= htmlspecialchars($order['staff_name']) ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <span class="status-badge status-<?= $order['status'] ?>">
                    <?= strtoupper(str_replace('_', ' ', $order['status'])) ?>
                </span>
                
                <!-- Alternative Status Update Dropdown -->
                <div class="position-relative">
                    <button class="btn btn-outline-primary" onclick="showStatusDropdown()">
                        <i class="bi bi-arrow-repeat me-2"></i>Change Status
                    </button>
                    <div id="statusDropdown" class="status-dropdown">
                        <div class="p-3">
                            <h6>Change Status</h6>
                            <select class="form-select" onchange="changeStatus(this)">
                                <option value="">Select new status...</option>
                                <?php foreach ($order_statuses as $status_key => $status_name): ?>
                                    <?php if ($status_key !== $order['status']): ?>
                                        <option value="<?= $status_key ?>"><?= $status_name ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <button class="btn btn-outline-success" onclick="printOrderPDF()">
                    <i class="bi bi-file-pdf me-2"></i>Print PDF
                </button>
                <a href="edit-order.php?id=<?= $order['id'] ?>" class="btn btn-outline-warning">
                    <i class="bi bi-pencil me-2"></i>Edit Order
                </a>
            </div>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success)): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-8">
            <!-- Currency Information -->
            <div class="currency-info">
                <h6 class="mb-2"><i class="bi bi-currency-exchange me-2"></i>Currency Information</h6>
                <div class="row">
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span>Order Currency:</span>
                            <span class="currency-badge <?= $order_currency === $defaultCurrency ? 'default' : '' ?>">
                                <?= $currency_symbol ?> <?= $order_currency ?>
                            </span>
                        </div>
                        <small class="text-muted">
                            <?= $order_currency === $defaultCurrency ? 'System default currency' : $currency_name ?>
                        </small>
                    </div>
                    <div class="col-md-6">
                        <?php if ($is_converted): ?>
                            <div class="conversion-info">
                                <i class="bi bi-arrow-left-right me-2"></i>
                                <strong>Converted Rate:</strong> 1 <?= $defaultCurrency ?> = <?= number_format($exchange_rate, 4) ?> <?= $order_currency ?>
                            </div>
                        <?php endif; ?>
                        <div class="vat-info <?= $vat_enabled ? '' : 'disabled' ?>">
                            <i class="bi bi-receipt me-2"></i>
                            <strong>VAT:</strong> 
                            <?php if ($vat_enabled): ?>
                                Enabled (<?= number_format($vat_percentage, 1) ?>%)
                            <?php else: ?>
                                Not applicable for <?= $order_currency ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Information -->
            <div class="order-details-section">
                <h5 class="section-title">Order Information</h5>
                <div class="info-grid">
                    <div>
                        <div class="info-item">
                            <span class="info-label">Company:</span>
                            <span class="info-value"><?= htmlspecialchars($order['company_name']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Order Type:</span>
                            <span class="info-value"><?= ucfirst($order['order_type']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Billing Cycle:</span>
                            <span class="info-value"><?= ucfirst($order['billing_cycle']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Start Date:</span>
                            <span class="info-value"><?= date('d M Y', strtotime($order['start_date'])) ?></span>
                        </div>
                    </div>
                    <div>
                        <div class="info-item">
                            <span class="info-label">Created By:</span>
                            <span class="info-value"><?= htmlspecialchars($order['staff_name']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Created:</span>
                            <span class="info-value"><?= date('d M Y H:i', strtotime($order['created_at'])) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Last Updated:</span>
                            <span class="info-value"><?= date('d M Y H:i', strtotime($order['updated_at'])) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Order Currency:</span>
                            <span class="info-value"><?= $currency_symbol ?> <?= $order_currency ?></span>
                        </div>
                    </div>
                </div>
                
                <?php if ($order['notes']): ?>
                    <div class="notes-section">
                        <div class="info-item">
                            <span class="info-label">Notes:</span>
                            <span></span>
                        </div>
                        <div class="notes-text">
                            <?= nl2br(htmlspecialchars($order['notes'])) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Order Items -->
            <div class="order-items-section">
                <h5 class="section-title">Order Items</h5>
                <?php if (empty($order_items)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-cart-x" style="font-size: 3rem;"></i>
                        <p class="mt-2">No items in this order</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($order_items as $item): ?>
                        <div class="order-item-card">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1"><?= htmlspecialchars($item['name']) ?></h6>
                                    <?php if ($item['description']): ?>
                                        <p class="text-muted small mb-2"><?= htmlspecialchars($item['description']) ?></p>
                                    <?php endif; ?>
                                    <div class="d-flex gap-2">
                                        <span class="badge bg-primary">Qty: <?= $item['quantity'] ?></span>
                                        <span class="badge bg-secondary"><?= $currency_symbol ?><?= number_format($item['unit_price'], 2) ?> each</span>
                                        <?php if ($item['setup_fee'] > 0): ?>
                                            <span class="badge bg-warning">Setup: <?= $currency_symbol ?><?= number_format($item['setup_fee'], 2) ?></span>
                                        <?php endif; ?>
                                        <span class="badge bg-info"><?= ucfirst($item['billing_cycle']) ?></span>
                                        <?php if ($is_converted): ?>
                                            <span class="badge bg-warning">Converted</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="h5 mb-1"><?= $currency_symbol ?><?= number_format($item['line_total'], 2) ?></div>
                                    <?php if ($item['setup_fee'] > 0): ?>
                                        <small class="text-muted">+ <?= $currency_symbol ?><?= number_format($item['setup_fee'] * $item['quantity'], 2) ?> setup</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Status History -->
            <?php if (!empty($status_history)): ?>
                <div class="order-details-section">
                    <h5 class="section-title">Status History</h5>
                    <div class="timeline">
                        <?php foreach ($status_history as $history): ?>
                            <div class="timeline-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong>
                                            <?= $history['status_from'] ? ucfirst(str_replace('_', ' ', $history['status_from'])) . ' → ' : '' ?>
                                            <?= ucfirst(str_replace('_', ' ', $history['status_to'])) ?>
                                        </strong>
                                        <p class="text-muted mb-1">by <?= htmlspecialchars($history['changed_by_name']) ?></p>
                                        <?php if ($history['notes']): ?>
                                            <p class="small text-muted mb-0"><?= htmlspecialchars($history['notes']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted"><?= date('d M Y H:i', strtotime($history['created_at'])) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-md-4">
            <!-- Workflow Indicator -->
            <div class="workflow-indicator">
                <h6 class="mb-2">Order Status</h6>
                <div class="workflow-steps">
                    <div class="workflow-step <?= $order['status'] === 'draft' ? 'active' : '' ?>">
                        <i class="bi bi-pencil-square d-block mb-1"></i>
                        <small>Draft</small>
                    </div>
                    <div class="workflow-arrow">→</div>
                    <div class="workflow-step <?= $order['status'] === 'placed' ? 'active' : '' ?>">
                        <i class="bi bi-send d-block mb-1"></i>
                        <small>Placed</small>
                    </div>
                    <div class="workflow-arrow">→</div>
                    <div class="workflow-step <?= in_array($order['status'], ['pending_payment', 'paid']) ? 'active' : '' ?>">
                        <i class="bi bi-credit-card d-block mb-1"></i>
                        <small>Payment</small>
                    </div>
                    <div class="workflow-arrow">→</div>
                    <div class="workflow-step <?= in_array($order['status'], ['processing', 'completed']) ? 'active' : '' ?>">
                        <i class="bi bi-check-circle d-block mb-1"></i>
                        <small>Complete</small>
                    </div>
                </div>
            </div>

            <!-- Order Summary -->
            <div class="order-summary">
                <div class="summary-title">Order Summary</div>
                
                <div class="summary-row">
                    <span>Items:</span>
                    <span><?= count($order_items) ?></span>
                </div>
                
                <div class="summary-row">
                    <span>Subtotal:</span>
                    <span><?= $currency_symbol ?><?= number_format($order['subtotal'], 2) ?></span>
                </div>
                
                <div class="summary-row">
                    <span>Setup Fees:</span>
                    <span><?= $currency_symbol ?><?= number_format($setup_fees_total, 2) ?></span>
                </div>
                
                <?php if ($vat_enabled): ?>
                    <div class="summary-row">
                        <span>VAT (<?= number_format($vat_percentage, 1) ?>%):</span>
                        <span><?= $currency_symbol ?><?= number_format($order['tax_amount'], 2) ?></span>
                    </div>
                <?php endif; ?>
                
                <div class="summary-row total">
                    <span>Total:</span>
                    <span><?= $currency_symbol ?><?= number_format($order['total_amount'], 2) ?></span>
                </div>

                <!-- Currency Conversion Information -->
                <?php if ($is_converted): ?>
                    <div class="alert alert-warning mt-3">
                        <i class="bi bi-arrow-left-right me-2"></i>
                        <strong>Currency Conversion</strong><br>
                        <small>
                            Original: <?= $currency_symbol ?><?= number_format($order['total_amount'], 2) ?> <?= $order_currency ?><br>
                            Rate: 1 <?= $defaultCurrency ?> = <?= number_format($exchange_rate, 4) ?> <?= $order_currency ?><br>
                            Base: <?= $supportedCurrencies[$defaultCurrency]['symbol'] ?><?= number_format($order['total_amount'] / $exchange_rate, 2) ?> <?= $defaultCurrency ?>
                        </small>
                    </div>
                <?php endif; ?>

                <!-- Status Information -->
                <div class="alert alert-info mt-3">
                    <i class="bi bi-info-circle me-2"></i>
                    <?php if ($order['status'] === 'draft'): ?>
                        <strong>Draft orders</strong> are saved but not processed until placed.
                    <?php elseif ($order['status'] === 'placed'): ?>
                        <strong>Placed orders</strong> are ready for payment processing.
                    <?php elseif ($order['status'] === 'paid'): ?>
                        <strong>Paid orders</strong> are counted in revenue.
                    <?php else: ?>
                        Order status: <strong><?= ucfirst(str_replace('_', ' ', $order['status'])) ?></strong>
                    <?php endif; ?>
                </div>

                <!-- Company Details -->
                <div class="mt-4">
                    <h6>Company Details</h6>
                    <div class="small">
                        <div class="mb-1"><strong><?= htmlspecialchars($order['company_name']) ?></strong></div>
                        <?php if ($order['company_phone']): ?>
                            <div class="mb-1"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($order['company_phone']) ?></div>
                        <?php endif; ?>
                        <?php if ($order['company_address']): ?>
                            <div class="mb-1"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($order['company_address']) ?></div>
                        <?php endif; ?>
                        <?php if ($order['currency_override'] && $order['preferred_currency']): ?>
                            <div class="mb-1">
                                <i class="bi bi-currency-exchange me-1"></i>
                                Preferred Currency: <?= $order['preferred_currency'] ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="mt-4">
                    <h6>Quick Actions</h6>
                    <div class="d-grid gap-2">
                        <?php if ($order['status'] === 'draft'): ?>
                            <button class="btn btn-primary btn-sm" onclick="quickStatusUpdate('placed')">
                                <i class="bi bi-send me-1"></i>Place Order
                            </button>
                        <?php elseif ($order['status'] === 'placed'): ?>
                            <button class="btn btn-warning btn-sm" onclick="quickStatusUpdate('pending_payment')">
                                <i class="bi bi-credit-card me-1"></i>Mark Payment Pending
                            </button>
                        <?php elseif ($order['status'] === 'pending_payment'): ?>
                            <button class="btn btn-success btn-sm" onclick="quickStatusUpdate('paid')">
                                <i class="bi bi-check-circle me-1"></i>Mark as Paid
                            </button>
                        <?php elseif ($order['status'] === 'paid'): ?>
                            <button class="btn btn-info btn-sm" onclick="quickStatusUpdate('processing')">
                                <i class="bi bi-gear me-1"></i>Start Processing
                            </button>
                        <?php elseif ($order['status'] === 'processing'): ?>
                            <button class="btn btn-success btn-sm" onclick="quickStatusUpdate('completed')">
                                <i class="bi bi-check-circle me-1"></i>Mark Complete
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="js/order-view.js"></script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>