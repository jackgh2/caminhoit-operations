<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config-payment-api.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/functions.php';

// Only include automation if it exists
$automation_file = $_SERVER['DOCUMENT_ROOT'] . '/includes/order-automation.php';
if (file_exists($automation_file)) {
    require_once $automation_file;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

// Access control - Staff only (administrator, account manager, support consultant, accountant)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'account manager', 'support consultant', 'accountant'])) {
    header('Location: /login.php');
    exit;
}

$user_id = $_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'];

// Get all companies (staff can see all)
try {
    $stmt = $pdo->query("SELECT id, name FROM companies ORDER BY name");
    $all_companies = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Companies query error: " . $e->getMessage());
    $all_companies = [];
}

// Get selected company filter
$selected_company_id = $_GET['company_id'] ?? '';

// Check if we're viewing a specific invoice
$invoice_id = intval($_GET['invoice_id'] ?? 0);
$order_id = intval($_GET['order_id'] ?? 0);

// INDIVIDUAL INVOICE VIEW
if ($invoice_id || $order_id) {
    try {
        if ($invoice_id) {
            // Load existing invoice
            $stmt = $pdo->prepare("SELECT i.*, o.order_number, c.name as company_name, c.contact_email as company_email,
                c.address as company_address, c.phone as company_phone, c.vat_number as company_vat,
                u.username as created_by_username
                FROM invoices i
                LEFT JOIN orders o ON i.order_id = o.id
                JOIN companies c ON i.company_id = c.id
                LEFT JOIN users u ON i.created_by = u.id
                WHERE i.id = ?");
            $stmt->execute([$invoice_id]);
            $invoice = $stmt->fetch();
        } else {
            // Load by order ID
            $stmt = $pdo->prepare("SELECT i.*, o.order_number, c.name as company_name, c.contact_email as company_email,
                c.address as company_address, c.phone as company_phone, c.vat_number as company_vat,
                u.username as created_by_username
                FROM invoices i
                LEFT JOIN orders o ON i.order_id = o.id
                JOIN companies c ON i.company_id = c.id
                LEFT JOIN users u ON i.created_by = u.id
                WHERE i.order_id = ? 
                ORDER BY i.created_at DESC LIMIT 1");
            $stmt->execute([$order_id]);
            $invoice = $stmt->fetch();
        }
        
        if (!$invoice) {
            throw new Exception('Invoice not found');
        }
        
        // Get invoice items
        $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id");
        $stmt->execute([$invoice['id']]);
        $invoice_items = $stmt->fetchAll();
        
        // Get payment history
        $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE invoice_id = ? ORDER BY transaction_date DESC");
        $stmt->execute([$invoice['id']]);
        $payment_history = $stmt->fetchAll();
        
        $page_title = "Invoice #" . $invoice['invoice_number'] . " | CaminhoIT";
        $show_individual_invoice = true;
        
    } catch (Exception $e) {
        error_log("Invoice view error: " . $e->getMessage());
        header('Location: /operations/invoices.php?error=' . urlencode('Error loading invoice: ' . $e->getMessage()));
        exit;
    }
} 

// INVOICE MANAGEMENT VIEW
else {
    // Get comprehensive stats
    try {
        // Overall statistics - Fixed to use correct column names
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_invoices,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN status = 'partially_paid' THEN 1 ELSE 0 END) as partially_paid_count,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                SUM(CASE WHEN auto_generated = 1 THEN 1 ELSE 0 END) as auto_generated_count,
                COALESCE(SUM(total_amount), 0) as total_value,
                COALESCE(SUM(paid_amount), 0) as total_paid,
                COALESCE(SUM(total_amount) - SUM(COALESCE(paid_amount, 0)), 0) as total_outstanding,
                COALESCE(SUM(CASE WHEN status IN ('sent', 'partially_paid', 'overdue') THEN total_amount - COALESCE(paid_amount, 0) ELSE 0 END), 0) as active_outstanding
            FROM invoices
        ");
        $overall_stats = $stmt->fetch() ?: [];

        // Time-based statistics - Fixed to use paid_date instead of payment_date
        $stmt = $pdo->query("
            SELECT 
                SUM(CASE WHEN due_date < CURDATE() AND status IN ('sent', 'partially_paid') THEN 1 ELSE 0 END) as overdue_today,
                SUM(CASE WHEN due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND status IN ('sent', 'partially_paid') THEN 1 ELSE 0 END) as due_within_7_days,
                SUM(CASE WHEN due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status IN ('sent', 'partially_paid') THEN 1 ELSE 0 END) as due_within_30_days,
                SUM(CASE WHEN issue_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as created_last_30_days,
                SUM(CASE WHEN paid_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as paid_last_30_days,
                SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND status IN ('sent', 'partially_paid') THEN 1 ELSE 0 END) as overdue_30_plus_days,
                SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND status IN ('sent', 'partially_paid') THEN 1 ELSE 0 END) as overdue_60_plus_days,
                SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND status IN ('sent', 'partially_paid') THEN 1 ELSE 0 END) as overdue_90_plus_days
            FROM invoices
        ");
        $time_stats = $stmt->fetch() ?: [];

        // Currency breakdown - Fixed query
        $stmt = $pdo->query("
            SELECT 
                currency,
                COUNT(*) as count,
                COALESCE(SUM(total_amount), 0) as total_value,
                COALESCE(SUM(COALESCE(paid_amount, 0)), 0) as total_paid,
                COALESCE(SUM(total_amount) - SUM(COALESCE(paid_amount, 0)), 0) as outstanding,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count
            FROM invoices
            GROUP BY currency
            ORDER BY total_value DESC
        ");
        $currency_stats = $stmt->fetchAll() ?: [];

        // Recent activity - Fixed query
        $stmt = $pdo->query("
            SELECT i.*, c.name as company_name, u.username as created_by_username,
                   DATEDIFF(CURDATE(), i.due_date) as days_overdue
            FROM invoices i
            JOIN companies c ON i.company_id = c.id
            LEFT JOIN users u ON i.created_by = u.id
            ORDER BY i.updated_at DESC
            LIMIT 10
        ");
        $recent_activity = $stmt->fetchAll() ?: [];

        // Overdue invoices requiring attention - Fixed query
        $stmt = $pdo->query("
            SELECT i.*, c.name as company_name,
                   DATEDIFF(CURDATE(), i.due_date) as days_overdue,
                   (i.total_amount - COALESCE(i.paid_amount, 0)) as outstanding_amount
            FROM invoices i
            JOIN companies c ON i.company_id = c.id
            WHERE i.status IN ('sent', 'partially_paid', 'overdue')
            AND i.due_date < CURDATE()
            ORDER BY i.due_date ASC
            LIMIT 15
        ");
        $overdue_invoices = $stmt->fetchAll() ?: [];

        // Filters for list view
        $status_filter = $_GET['status'] ?? 'all';
        $date_from = $_GET['date_from'] ?? '';
        $date_to = $_GET['date_to'] ?? '';
        $search = $_GET['search'] ?? '';
        $page = max(1, intval($_GET['page'] ?? 1));
        $per_page = 50; // More items for staff
        $offset = ($page - 1) * $per_page;

        // Build query conditions
        $where_conditions = [];
        $params = [];

        if ($selected_company_id !== '') {
            $where_conditions[] = "i.company_id = ?";
            $params[] = $selected_company_id;
        }

        if ($status_filter !== 'all') {
            if ($status_filter === 'outstanding') {
                $where_conditions[] = "i.status IN ('sent', 'partially_paid', 'overdue')";
            } else {
                $where_conditions[] = "i.status = ?";
                $params[] = $status_filter;
            }
        }

        if ($date_from) {
            $where_conditions[] = "i.issue_date >= ?";
            $params[] = $date_from;
        }

        if ($date_to) {
            $where_conditions[] = "i.issue_date <= ?";
            $params[] = $date_to;
        }

        if ($search) {
            $where_conditions[] = "(i.invoice_number LIKE ? OR COALESCE(o.order_number, '') LIKE ? OR COALESCE(i.notes, '') LIKE ? OR c.name LIKE ? OR COALESCE(u.username, '') LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $where_sql = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

        // Get filtered invoices count
        $count_query = "SELECT COUNT(*) as total 
                        FROM invoices i 
                        LEFT JOIN orders o ON i.order_id = o.id 
                        JOIN companies c ON i.company_id = c.id
                        LEFT JOIN users u ON i.created_by = u.id
                        {$where_sql}";
        
        $stmt = $pdo->prepare($count_query);
        $stmt->execute($params);
        $total_count = $stmt->fetch()['total'];

        // Get filtered invoices
        $query = "SELECT i.*, o.order_number, c.name as company_name, u.username as created_by_username,
                         DATEDIFF(CURDATE(), i.due_date) as days_overdue,
                         (i.total_amount - COALESCE(i.paid_amount, 0)) as outstanding_amount
                  FROM invoices i 
                  LEFT JOIN orders o ON i.order_id = o.id 
                  JOIN companies c ON i.company_id = c.id
                  LEFT JOIN users u ON i.created_by = u.id
                  {$where_sql}
                  ORDER BY i.created_at DESC 
                  LIMIT {$per_page} OFFSET {$offset}";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll();

        $total_pages = ceil($total_count / $per_page);
        $page_title = "Invoice Management | CaminhoIT";
        $show_individual_invoice = false;

    } catch (PDOException $e) {
        error_log("Invoice management query error: " . $e->getMessage());
        
        // Initialize with empty arrays to prevent PHP errors
        $overall_stats = [
            'total_invoices' => 0,
            'draft_count' => 0,
            'sent_count' => 0,
            'paid_count' => 0,
            'partially_paid_count' => 0,
            'overdue_count' => 0,
            'cancelled_count' => 0,
            'auto_generated_count' => 0,
            'total_value' => 0,
            'total_paid' => 0,
            'total_outstanding' => 0,
            'active_outstanding' => 0
        ];
        $time_stats = [
            'overdue_today' => 0,
            'due_within_7_days' => 0,
            'due_within_30_days' => 0,
            'created_last_30_days' => 0,
            'paid_last_30_days' => 0,
            'overdue_30_plus_days' => 0,
            'overdue_60_plus_days' => 0,
            'overdue_90_plus_days' => 0
        ];
        $currency_stats = [];
        $recent_activity = [];
        $overdue_invoices = [];
        $invoices = [];
        $total_count = 0;
        $show_individual_invoice = false;
        
        // Show error to user
        $_GET['error'] = 'Database error: ' . $e->getMessage();
    }
}

function formatCurrency($amount, $currency = 'GBP') {
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
    $symbol = $symbols[$currency] ?? $currency . ' ';
    return $symbol . number_format($amount, 2);
}

function getStatusBadgeClass($status) {
    $classes = [
        'draft' => 'secondary',
        'sent' => 'primary',
        'paid' => 'success',
        'overdue' => 'danger',
        'cancelled' => 'warning',
        'partially_paid' => 'info'
    ];
    return $classes[$status] ?? 'secondary';
}

function getUrgencyClass($days_overdue) {
    if ($days_overdue >= 90) return 'danger';
    if ($days_overdue >= 60) return 'warning';
    if ($days_overdue >= 30) return 'info';
    if ($days_overdue >= 0) return 'primary';
    return 'secondary';
}
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --border-radius: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }

        /* Enhanced Hero Section */
        .hero-enhanced {
            background: var(--primary-gradient);
            position: relative;
            overflow: hidden;
            min-height: 35vh;
            display: flex;
            align-items: center;
        }

        .hero-enhanced::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 30% 50%, rgba(255,255,255,0.1) 0%, transparent 50%);
        }

        .dashboard-hero-content {
            position: relative;
            z-index: 2;
            text-align: center;
            padding: 2rem 0;
        }

        .dashboard-hero-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
            color: white;
        }

        .dashboard-hero-subtitle {
            font-size: 1.125rem;
            margin-bottom: 1.5rem;
            opacity: 0.9;
            color: white;
        }

        .quick-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 2rem;
        }

        .quick-action-btn {
            background: rgba(255,255,255,0.2);
            border: 2px solid rgba(255,255,255,0.3);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }

        .quick-action-btn:hover {
            background: rgba(255,255,255,0.3);
            border-color: rgba(255,255,255,0.5);
            color: white;
            transform: translateY(-2px);
        }

        /* Enhanced Cards */
        .enhanced-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            border: none;
            overflow: hidden;
            position: relative;
            margin-bottom: 2rem;
        }

        .enhanced-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .card-header-enhanced {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 1px solid rgba(102, 126, 234, 0.1);
            padding: 1.5rem;
        }

        .card-title-enhanced {
            font-size: 1.25rem;
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-body-enhanced {
            padding: 1.5rem;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            text-align: center;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            opacity: 0;
            transition: var(--transition);
        }

        .stat-card:hover::before {
            opacity: 0.05;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card.primary::before { background: var(--primary-gradient); }
        .stat-card.success::before { background: var(--success-gradient); }
        .stat-card.warning::before { background: var(--warning-gradient); }
        .stat-card.danger::before { background: var(--danger-gradient); }
        .stat-card.info::before { background: var(--info-gradient); }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            position: relative;
            z-index: 1;
        }

        .stat-icon.primary { color: #667eea; }
        .stat-icon.success { color: #28a745; }
        .stat-icon.warning { color: #ffc107; }
        .stat-icon.danger { color: #dc3545; }
        .stat-icon.info { color: #17a2b8; }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .stat-number.primary { color: #667eea; }
        .stat-number.success { color: #28a745; }
        .stat-number.warning { color: #ffc107; }
        .stat-number.danger { color: #dc3545; }
        .stat-number.info { color: #17a2b8; }

        .stat-label {
            color: #6c757d;
            font-weight: 500;
            position: relative;
            z-index: 1;
            font-size: 0.9rem;
        }

        .stat-sublabel {
            color: #adb5bd;
            font-size: 0.8rem;
            margin-top: 0.25rem;
            position: relative;
            z-index: 1;
        }

        /* Activity Cards */
        .activity-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }

        .activity-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: var(--transition);
        }

        .activity-item:hover {
            background: #f8f9fa;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            margin-right: 1rem;
        }

        .activity-details h6 {
            margin: 0;
            font-weight: 600;
            color: #2c3e50;
        }

        .activity-details small {
            color: #6c757d;
        }

        .activity-meta {
            text-align: right;
        }

        .activity-meta .badge {
            margin-bottom: 0.25rem;
        }

        .activity-meta small {
            color: #6c757d;
        }

        /* Enhanced Table */
        .table-enhanced {
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            border: none !important;
            margin-bottom: 0 !important;
        }

        .table-enhanced thead {
            background: var(--primary-gradient) !important;
        }

        .table-enhanced thead th {
            background: transparent !important;
            color: white !important;
            font-weight: 600 !important;
            border: none !important;
            padding: 1rem !important;
            text-shadow: 0 1px 3px rgba(0,0,0,0.3) !important;
        }

        .table-enhanced tbody td {
            padding: 1rem !important;
            border-color: rgba(0,0,0,0.05) !important;
            vertical-align: middle !important;
        }

        .table-enhanced tbody tr:hover td {
            background: rgba(102, 126, 234, 0.05) !important;
        }

        /* Status Badges */
        .badge-enhanced {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Filter Form */
        .filter-form {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }

        .form-control-enhanced {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            transition: var(--transition);
        }

        .form-control-enhanced:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        /* Enhanced Buttons */
        .btn-enhanced {
            border-radius: 50px;
            padding: 0.5rem 1.5rem;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary-enhanced {
            background: var(--primary-gradient);
            color: white;
        }

        .btn-success-enhanced {
            background: var(--success-gradient);
            color: white;
        }

        .btn-warning-enhanced {
            background: var(--warning-gradient);
            color: white;
        }

        .btn-danger-enhanced {
            background: var(--danger-gradient);
            color: white;
        }

        .btn-outline-enhanced {
            border: 2px solid #667eea;
            color: #667eea;
            background: transparent;
        }

        /* Priority indicators */
        .priority-high {
            border-left: 4px solid #dc3545;
        }

        .priority-medium {
            border-left: 4px solid #ffc107;
        }

        .priority-low {
            border-left: 4px solid #28a745;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-hero-title {
                font-size: 2rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
            
            .quick-actions {
                flex-direction: column;
                align-items: center;
            }
            
            .activity-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .activity-meta {
                text-align: left;
            }
        }

        /* Breadcrumb */
        .breadcrumb-enhanced {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
        }
</style>

<?php if ($show_individual_invoice): ?>
<!-- INDIVIDUAL INVOICE VIEW FOR STAFF -->
<div class="container py-4">
    <!-- Action Bar -->
    <div class="row no-print">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <a href="/operations/invoices.php" class="btn btn-outline-enhanced">
                    <i class="bi bi-arrow-left"></i>
                    Back to Invoice Management
                </a>
                <div>
                    <a href="/operations/edit-invoice.php?id=<?= $invoice['id'] ?>" class="btn btn-warning-enhanced">
                        <i class="bi bi-pencil"></i>
                        Edit Invoice
                    </a>
                    <button onclick="window.print()" class="btn btn-outline-enhanced">
                        <i class="bi bi-printer"></i>
                        Print
                    </button>
                    <a href="/includes/api/generate-pdf.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-outline-enhanced">
                        <i class="bi bi-file-pdf"></i>
                        Download PDF
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Staff Invoice Details -->
    <div class="enhanced-card">
        <div class="card-header-enhanced">
            <h5 class="card-title-enhanced">
                <i class="bi bi-receipt"></i>
                Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?>
                <span class="badge bg-<?= getStatusBadgeClass($invoice['status']) ?> ms-2">
                    <?= ucfirst(str_replace('_', ' ', $invoice['status'])) ?>
                </span>
            </h5>
        </div>
        <div class="card-body-enhanced">
            <div class="row">
                <div class="col-md-6">
                    <h6>Invoice Information</h6>
                    <table class="table table-borderless">
                        <tr>
                            <td><strong>Invoice Number:</strong></td>
                            <td><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Company:</strong></td>
                            <td><?= htmlspecialchars($invoice['company_name']) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Amount:</strong></td>
                            <td><strong><?= formatCurrency($invoice['total_amount'], $invoice['currency']) ?></strong></td>
                        </tr>
                        <tr>
                            <td><strong>Outstanding:</strong></td>
                            <td><strong class="text-danger"><?= formatCurrency($invoice['total_amount'] - ($invoice['paid_amount'] ?? 0), $invoice['currency']) ?></strong></td>
                        </tr>
                        <tr>
                            <td><strong>Issue Date:</strong></td>
                            <td><?= date('d/m/Y', strtotime($invoice['issue_date'])) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Due Date:</strong></td>
                            <td><?= date('d/m/Y', strtotime($invoice['due_date'])) ?></td>
                        </tr>
                        <?php if ($invoice['order_number']): ?>
                        <tr>
                            <td><strong>Order:</strong></td>
                            <td><a href="/operations/view-order.php?id=<?= $invoice['order_id'] ?>">#<?= htmlspecialchars($invoice['order_number']) ?></a></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6>System Information</h6>
                    <table class="table table-borderless">
                        <tr>
                            <td><strong>Created By:</strong></td>
                            <td><?= htmlspecialchars($invoice['created_by_username'] ?? 'System') ?></td>
                        </tr>
                        <tr>
                            <td><strong>Created:</strong></td>
                            <td><?= date('d/m/Y H:i', strtotime($invoice['created_at'])) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Last Updated:</strong></td>
                            <td><?= date('d/m/Y H:i', strtotime($invoice['updated_at'])) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Type:</strong></td>
                            <td>
                                <?= ucfirst($invoice['invoice_type']) ?>
                                <?php if ($invoice['auto_generated']): ?>
                                <span class="badge bg-info">Auto-generated</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($invoice['paid_date']): ?>
                        <tr>
                            <td><strong>Payment Date:</strong></td>
                            <td><?= date('d/m/Y', strtotime($invoice['paid_date'])) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($invoice['payment_reference']): ?>
                        <tr>
                            <td><strong>Payment Ref:</strong></td>
                            <td><?= htmlspecialchars($invoice['payment_reference']) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- Payment History -->
            <?php if (!empty($payment_history)): ?>
            <h6 class="mt-4">Payment History</h6>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Fees</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payment_history as $payment): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($payment['transaction_date'])) ?></td>
                            <td><?= formatCurrency($payment['amount'], $payment['currency']) ?></td>
                            <td><?= htmlspecialchars($payment['payment_method']) ?></td>
                            <td><?= htmlspecialchars($payment['payment_reference']) ?></td>
                            <td><span class="badge bg-<?= $payment['status'] === 'completed' ? 'success' : 'warning' ?>"><?= ucfirst($payment['status']) ?></span></td>
                            <td><?= formatCurrency($payment['fees_amount'], $payment['currency']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="mt-4">
                <a href="/operations/edit-invoice.php?id=<?= $invoice['id'] ?>" class="btn btn-warning-enhanced">
                    <i class="bi bi-pencil"></i>
                    Edit Invoice
                </a>
                <?php if (in_array($invoice['status'], ['draft', 'sent'])): ?>
                <a href="/operations/send-invoice.php?id=<?= $invoice['id'] ?>" class="btn btn-primary-enhanced">
                    <i class="bi bi-envelope"></i>
                    Send Invoice
                </a>
                <?php endif; ?>
                <?php if (!in_array($invoice['status'], ['paid', 'cancelled'])): ?>
                <a href="/operations/record-payment.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-success-enhanced">
                    <i class="bi bi-cash-coin"></i>
                    Record Payment
                </a>
                <?php endif; ?>
                <a href="/operations/invoice-actions.php?id=<?= $invoice['id'] ?>" class="btn btn-outline-enhanced">
                    <i class="bi bi-three-dots"></i>
                    More Actions
                </a>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- STAFF INVOICE MANAGEMENT VIEW -->
<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="dashboard-hero-content">
            <h1 class="dashboard-hero-title">
                <i class="bi bi-receipt me-2"></i>
                Invoice Management
            </h1>
            <p class="dashboard-hero-subtitle">
                Comprehensive invoice oversight with real-time insights and quick access to essential features.
            </p>
            
            <div class="quick-actions">
                <a href="/operations/create-invoice.php" class="quick-action-btn">
                    <i class="bi bi-plus-lg me-2"></i>Create Invoice
                </a>
                <a href="/operations/bulk-actions.php" class="quick-action-btn">
                    <i class="bi bi-collection me-2"></i>Bulk Actions
                </a>
                <a href="/reports/invoice-report.php" class="quick-action-btn">
                    <i class="bi bi-graph-up me-2"></i>View Reports
                </a>
                <a href="/operations/payment-reminders.php" class="quick-action-btn">
                    <i class="bi bi-bell me-2"></i>Send Reminders
                </a>
            </div>
        </div>
    </div>
</header>

<div class="container py-5" style="margin-top: -100px; position: relative; z-index: 10;">
    
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="breadcrumb-enhanced">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/operations/">Operations</a></li>
            <li class="breadcrumb-item active" aria-current="page">Invoice Management</li>
        </ol>
    </nav>

    <!-- Error/Success Messages -->
    <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?= htmlspecialchars($_GET['error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i>
        <?= htmlspecialchars($_GET['success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Main Statistics Grid -->
    <div class="stats-grid">
        <!-- Total Outstanding -->
        <div class="stat-card danger" onclick="filterByStatus('outstanding')">
            <div class="stat-icon danger">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
            <div class="stat-number danger"><?= formatCurrency($overall_stats['active_outstanding'] ?? 0, 'GBP') ?></div>
            <div class="stat-label">Outstanding Invoices</div>
            <div class="stat-sublabel"><?= number_format(($overall_stats['sent_count'] ?? 0) + ($overall_stats['partially_paid_count'] ?? 0) + ($overall_stats['overdue_count'] ?? 0)) ?> invoices</div>
        </div>

        <!-- Overdue Today -->
        <div class="stat-card danger" onclick="showOverdueInvoices()">
            <div class="stat-icon danger">
                <i class="bi bi-clock-history"></i>
            </div>
            <div class="stat-number danger"><?= number_format($time_stats['overdue_today'] ?? 0) ?></div>
            <div class="stat-label">Overdue Today</div>
            <div class="stat-sublabel">Require immediate attention</div>
        </div>

        <!-- Due Within 7 Days -->
        <div class="stat-card warning" onclick="showDueSoon()">
            <div class="stat-icon warning">
                <i class="bi bi-calendar-week"></i>
            </div>
            <div class="stat-number warning"><?= number_format($time_stats['due_within_7_days'] ?? 0) ?></div>
            <div class="stat-label">Due Within 7 Days</div>
            <div class="stat-sublabel">Action needed soon</div>
        </div>

        <!-- Total Paid This Month -->
        <div class="stat-card success">
            <div class="stat-icon success">
                <i class="bi bi-cash-coin"></i>
            </div>
            <div class="stat-number success"><?= formatCurrency($overall_stats['total_paid'] ?? 0, 'GBP') ?></div>
            <div class="stat-label">Total Paid</div>
            <div class="stat-sublabel"><?= number_format($overall_stats['paid_count'] ?? 0) ?> invoices</div>
        </div>

        <!-- Created Last 30 Days -->
        <div class="stat-card info">
            <div class="stat-icon info">
                <i class="bi bi-file-plus"></i>
            </div>
            <div class="stat-number info"><?= number_format($time_stats['created_last_30_days'] ?? 0) ?></div>
            <div class="stat-label">Created (30 days)</div>
            <div class="stat-sublabel">New invoices</div>
        </div>

        <!-- Auto Generated -->
        <div class="stat-card primary">
            <div class="stat-icon primary">
                <i class="bi bi-robot"></i>
            </div>
            <div class="stat-number primary"><?= number_format($overall_stats['auto_generated_count'] ?? 0) ?></div>
            <div class="stat-label">Auto-Generated</div>
            <div class="stat-sublabel">System created</div>
        </div>

        <!-- 30+ Days Overdue -->
        <div class="stat-card danger">
            <div class="stat-icon danger">
                <i class="bi bi-hourglass-bottom"></i>
            </div>
            <div class="stat-number danger"><?= number_format($time_stats['overdue_30_plus_days'] ?? 0) ?></div>
            <div class="stat-label">30+ Days Overdue</div>
            <div class="stat-sublabel">Critical attention needed</div>
        </div>

        <!-- Draft Invoices -->
        <div class="stat-card info" onclick="filterByStatus('draft')">
            <div class="stat-icon info">
                <i class="bi bi-file-text"></i>
            </div>
            <div class="stat-number info"><?= number_format($overall_stats['draft_count'] ?? 0) ?></div>
            <div class="stat-label">Draft Invoices</div>
            <div class="stat-sublabel">Ready to send</div>
        </div>
    </div>

    <!-- Currency Breakdown -->
    <?php if (!empty($currency_stats)): ?>
    <div class="enhanced-card">
        <div class="card-header-enhanced">
            <h5 class="card-title-enhanced">
                <i class="bi bi-currency-exchange"></i>
                Currency Breakdown
            </h5>
        </div>
        <div class="card-body-enhanced">
            <div class="row">
                <?php foreach ($currency_stats as $stat): ?>
                <div class="col-md-4 mb-3">
                    <div class="card border-0 bg-light">
                        <div class="card-body text-center">
                            <h3 class="text-primary"><?= strtoupper($stat['currency']) ?></h3>
                            <p class="mb-1"><strong><?= number_format($stat['count']) ?></strong> invoices</p>
                            <p class="mb-1">Total: <strong><?= formatCurrency($stat['total_value'], $stat['currency']) ?></strong></p>
                            <p class="mb-1 text-success">Paid: <?= formatCurrency($stat['total_paid'], $stat['currency']) ?></p>
                            <p class="mb-0 text-danger">Outstanding: <?= formatCurrency($stat['outstanding'], $stat['currency']) ?></p>
                            <?php if ($stat['overdue_count'] > 0): ?>
                            <small class="text-warning"><?= $stat['overdue_count'] ?> overdue</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Two Column Layout -->
    <div class="row">
        <!-- Recent Activity -->
        <div class="col-lg-6">
            <div class="activity-card">
                <div class="card-header-enhanced">
                    <h5 class="card-title-enhanced">
                        <i class="bi bi-activity"></i>
                        Recent Activity
                    </h5>
                </div>
                <?php if (!empty($recent_activity)): ?>
                <?php foreach ($recent_activity as $invoice): ?>
                <div class="activity-item">
                    <div class="d-flex align-items-center">
                        <div class="activity-icon bg-<?= getStatusBadgeClass($invoice['status']) ?>">
                            <i class="bi bi-<?= 
                                $invoice['status'] === 'paid' ? 'check-circle' :
                                ($invoice['status'] === 'overdue' ? 'exclamation-triangle' :
                                ($invoice['status'] === 'sent' ? 'envelope' :
                                ($invoice['status'] === 'draft' ? 'file-text' : 'receipt')))
                            ?>"></i>
                        </div>
                        <div class="activity-details">
                            <h6><?= htmlspecialchars($invoice['invoice_number']) ?></h6>
                            <small><?= htmlspecialchars($invoice['company_name']) ?></small>
                        </div>
                    </div>
                    <div class="activity-meta">
                        <span class="badge bg-<?= getStatusBadgeClass($invoice['status']) ?>"><?= ucfirst($invoice['status']) ?></span>
                        <br><small><?= formatCurrency($invoice['total_amount'], $invoice['currency']) ?></small>
                        <br><small><?= date('M j, H:i', strtotime($invoice['updated_at'])) ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="text-center py-4">
                    <i class="bi bi-activity text-muted" style="font-size: 2rem;"></i>
                    <p class="text-muted mt-2">No recent activity</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Overdue Invoices Requiring Attention -->
        <div class="col-lg-6">
            <div class="activity-card">
                <div class="card-header-enhanced">
                    <h5 class="card-title-enhanced">
                        <i class="bi bi-exclamation-triangle"></i>
                        Overdue Invoices (<?= count($overdue_invoices) ?>)
                    </h5>
                </div>
                <?php if (!empty($overdue_invoices)): ?>
                <?php foreach ($overdue_invoices as $invoice): ?>
                <div class="activity-item <?= 
                    $invoice['days_overdue'] >= 90 ? 'priority-high' :
                    ($invoice['days_overdue'] >= 30 ? 'priority-medium' : 'priority-low')
                ?>">
                    <div class="d-flex align-items-center">
                        <div class="activity-icon bg-<?= getUrgencyClass($invoice['days_overdue']) ?>">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <div class="activity-details">
                            <h6>
                                <a href="/operations/invoices.php?invoice_id=<?= $invoice['id'] ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($invoice['invoice_number']) ?>
                                </a>
                            </h6>
                            <small><?= htmlspecialchars($invoice['company_name']) ?></small>
                        </div>
                    </div>
                    <div class="activity-meta">
                        <span class="badge bg-<?= getUrgencyClass($invoice['days_overdue']) ?>">
                            <?= $invoice['days_overdue'] ?> days overdue
                        </span>
                        <br><small><?= formatCurrency($invoice['outstanding_amount'], $invoice['currency']) ?></small>
                        <br><small>Due: <?= date('M j', strtotime($invoice['due_date'])) ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="text-center p-3 border-top">
                    <a href="?status=overdue" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-eye me-1"></i>View All Overdue
                    </a>
                </div>
                <?php else: ?>
                <div class="text-center py-4">
                    <i class="bi bi-check-circle text-success" style="font-size: 2rem;"></i>
                    <p class="text-muted mt-2">No overdue invoices! 🎉</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-form">
        <form method="GET" action="">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">
                        <i class="bi bi-building me-1"></i>Company Filter
                    </label>
                    <select name="company_id" class="form-control-enhanced">
                        <option value="">All Companies</option>
                        <?php foreach ($all_companies as $company): ?>
                        <option value="<?= $company['id'] ?>" <?= $company['id'] == $selected_company_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($company['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control-enhanced">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="outstanding" <?= $status_filter === 'outstanding' ? 'selected' : '' ?>>Outstanding</option>
                        <option value="draft" <?= $status_filter === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="sent" <?= $status_filter === 'sent' ? 'selected' : '' ?>>Sent</option>
                        <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="partially_paid" <?= $status_filter === 'partially_paid' ? 'selected' : '' ?>>Partially Paid</option>
                        <option value="overdue" <?= $status_filter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                        <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control-enhanced" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control-enhanced" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control-enhanced" placeholder="Invoice #, Company, User" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary-enhanced btn-enhanced w-100">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Invoices Table -->
    <div class="enhanced-card">
        <div class="card-header-enhanced">
            <h5 class="card-title-enhanced">
                <i class="bi bi-table"></i>
                Invoice List (<?= number_format($total_count) ?> total)
            </h5>
        </div>
        <div class="card-body-enhanced p-0">
            <?php if (empty($invoices)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox display-1 text-muted"></i>
                <p class="text-muted mt-3">No invoices found matching your criteria.</p>
                <a href="/operations/invoices.php" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-clockwise me-2"></i>Clear Filters
                </a>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-enhanced mb-0">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Company</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Issue Date</th>
                            <th>Due Date</th>
                            <th>Outstanding</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $invoice): ?>
                        <tr class="<?= 
                            $invoice['days_overdue'] >= 90 ? 'priority-high' :
                            ($invoice['days_overdue'] >= 30 ? 'priority-medium' : 
                            ($invoice['days_overdue'] > 0 ? 'priority-low' : ''))
                        ?>">
                            <td>
                                <strong>
                                    <a href="/operations/invoices.php?invoice_id=<?= $invoice['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($invoice['invoice_number']) ?>
                                    </a>
                                </strong>
                                <?php if ($invoice['invoice_type'] === 'recurring'): ?>
                                <br><small class="badge bg-info">Recurring</small>
                                <?php endif; ?>
                                <?php if ($invoice['auto_generated']): ?>
                                <br><small class="badge bg-secondary"><i class="bi bi-robot"></i> Auto</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($invoice['company_name']) ?></strong>
                            </td>
                            <td>
                                <strong><?= formatCurrency($invoice['total_amount'], $invoice['currency']) ?></strong>
                                <?php if (isset($invoice['paid_amount']) && $invoice['paid_amount'] > 0): ?>
                                <br><small class="text-success">Paid: <?= formatCurrency($invoice['paid_amount'], $invoice['currency']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= getStatusBadgeClass($invoice['status']) ?> badge-enhanced">
                                    <i class="bi bi-<?= 
                                        $invoice['status'] === 'paid' ? 'check-circle' :
                                        ($invoice['status'] === 'overdue' ? 'exclamation-triangle' :
                                        ($invoice['status'] === 'sent' ? 'envelope' :
                                        ($invoice['status'] === 'partially_paid' ? 'hourglass-split' :
                                        ($invoice['status'] === 'cancelled' ? 'x-circle' : 'file-text'))))
                                    ?>"></i>
                                    <?= ucfirst(str_replace('_', ' ', $invoice['status'])) ?>
                                </span>
                                <?php if ($invoice['status'] === 'overdue' && $invoice['days_overdue'] > 0): ?>
                                <br><small class="text-danger"><?= $invoice['days_overdue'] ?> days overdue</small>
                                <?php endif; ?>
                            </td>
                            <td><?= date('d/m/Y', strtotime($invoice['issue_date'])) ?></td>
                            <td>
                                <?= date('d/m/Y', strtotime($invoice['due_date'])) ?>
                                <?php if (!in_array($invoice['status'], ['paid', 'cancelled']) && strtotime($invoice['due_date']) < time()): ?>
                                <br><small class="text-danger">Overdue</small>
                                <?php endif; ?>
                            </td>
                                                        <td>
                                <?php if ($invoice['outstanding_amount'] > 0): ?>
                                <strong class="text-danger"><?= formatCurrency($invoice['outstanding_amount'], $invoice['currency']) ?></strong>
                                <?php else: ?>
                                <span class="text-success">Paid</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?= htmlspecialchars($invoice['created_by_username'] ?? 'System') ?></small>
                                <br><small class="text-muted"><?= date('M j', strtotime($invoice['created_at'])) ?></small>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="/operations/invoices.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-outline-primary" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="/operations/edit-invoice.php?id=<?= $invoice['id'] ?>" class="btn btn-outline-warning" title="Edit Invoice">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if (!in_array($invoice['status'], ['paid', 'cancelled'])): ?>
                                    <a href="/operations/record-payment.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-outline-success" title="Record Payment">
                                        <i class="bi bi-cash-coin"></i>
                                    </a>
                                    <?php endif; ?>
                                    <a href="/includes/api/generate-pdf.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-outline-secondary" title="Download PDF">
                                        <i class="bi bi-file-pdf"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav aria-label="Invoice pagination" class="mt-4">
        <ul class="pagination justify-content-center">
            <?php if ($page > 1): ?>
            <li class="page-item">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
            <li class="page-item">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
            <?php endif; ?>
        </ul>
        <div class="text-center text-muted mt-2">
            Showing <?= number_format($offset + 1) ?> to <?= number_format(min($offset + $per_page, $total_count)) ?> of <?= number_format($total_count) ?> invoices
        </div>
    </nav>
    <?php endif; ?>

    <!-- Quick Actions Panel -->
    <div class="enhanced-card mt-4">
        <div class="card-header-enhanced">
            <h5 class="card-title-enhanced">
                <i class="bi bi-lightning"></i>
                Quick Actions
            </h5>
        </div>
        <div class="card-body-enhanced">
            <div class="row g-3">
                <div class="col-md-3">
                    <a href="/operations/create-invoice.php" class="btn btn-primary-enhanced w-100">
                        <i class="bi bi-plus-lg me-2"></i>
                        Create New Invoice
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="/operations/bulk-actions.php" class="btn btn-warning-enhanced w-100">
                        <i class="bi bi-collection me-2"></i>
                        Bulk Actions
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="/reports/invoice-report.php" class="btn btn-info text-white w-100">
                        <i class="bi bi-graph-up me-2"></i>
                        Generate Report
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="/operations/payment-reminders.php" class="btn btn-danger-enhanced w-100">
                        <i class="bi bi-bell me-2"></i>
                        Send Reminders
                    </a>
                </div>
            </div>
            
            <!-- Export Options -->
            <div class="row g-3 mt-3">
                <div class="col-md-4">
                    <button onclick="exportInvoices('csv')" class="btn btn-outline-enhanced w-100">
                        <i class="bi bi-file-earmark-spreadsheet me-2"></i>
                        Export to CSV
                    </button>
                </div>
                <div class="col-md-4">
                    <button onclick="exportInvoices('excel')" class="btn btn-outline-enhanced w-100">
                        <i class="bi bi-file-earmark-excel me-2"></i>
                        Export to Excel
                    </button>
                </div>
                <div class="col-md-4">
                    <button onclick="exportInvoices('pdf')" class="btn btn-outline-enhanced w-100">
                        <i class="bi bi-file-earmark-pdf me-2"></i>
                        Export to PDF
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Quick filter functions
function filterByStatus(status) {
    const url = new URL(window.location);
    url.searchParams.set('status', status);
    url.searchParams.delete('page');
    window.location = url;
}

function showOverdueInvoices() {
    const url = new URL(window.location);
    url.searchParams.set('status', 'overdue');
    url.searchParams.delete('page');
    window.location = url;
}

function showDueSoon() {
    const url = new URL(window.location);
    url.searchParams.delete('status');
    url.searchParams.delete('page');
    // Add custom filter for due within 7 days
    window.location = url + '?&due_soon=1';
}

// Enhanced tooltips for priority rows
document.addEventListener('DOMContentLoaded', function() {
    // Add tooltips to priority rows
    const priorityRows = document.querySelectorAll('.priority-high, .priority-medium, .priority-low');
    priorityRows.forEach(row => {
        const urgencyText = row.classList.contains('priority-high') ? 'Critical: 90+ days overdue' :
                           row.classList.contains('priority-medium') ? 'High: 30+ days overdue' :
                           'Medium: Recently overdue';
        row.setAttribute('title', urgencyText);
        row.setAttribute('data-bs-toggle', 'tooltip');
    });
    
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Auto-refresh overdue status every 5 minutes
    setTimeout(function() {
        location.reload();
    }, 300000);
    
    // Enhanced table row highlighting
    const tableRows = document.querySelectorAll('.table-enhanced tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'rgba(102, 126, 234, 0.1)';
            this.style.transform = 'scale(1.01)';
            this.style.transition = 'all 0.2s ease';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
            this.style.transform = '';
        });
    });
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+N for new invoice
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        window.location = '/operations/create-invoice.php';
    }
    
    // Ctrl+R for reports
    if (e.ctrlKey && e.key === 'r') {
        e.preventDefault();
        window.location = '/reports/invoice-report.php';
    }
    
    // Ctrl+B for bulk actions
    if (e.ctrlKey && e.key === 'b') {
        e.preventDefault();
        window.location = '/operations/bulk-actions.php';
    }
});

// Live search with debounce
let searchTimeout;
const searchInput = document.querySelector('input[name="search"]');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            if (this.value.length >= 3 || this.value.length === 0) {
                this.form.submit();
            }
        }, 1000);
    });
}

// Export functionality
function exportInvoices(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.location = '/operations/export-invoices.php?' + params.toString();
}

// Bulk selection
let selectedInvoices = [];
function toggleInvoiceSelection(invoiceId) {
    const index = selectedInvoices.indexOf(invoiceId);
    if (index > -1) {
        selectedInvoices.splice(index, 1);
    } else {
        selectedInvoices.push(invoiceId);
    }
    updateBulkActions();
}

function updateBulkActions() {
    const bulkActionsBtn = document.getElementById('bulkActionsBtn');
    if (bulkActionsBtn) {
        bulkActionsBtn.textContent = `Bulk Actions (${selectedInvoices.length})`;
        bulkActionsBtn.disabled = selectedInvoices.length === 0;
    }
}

// Status change confirmation
function confirmStatusChange(invoiceId, newStatus) {
    const statusNames = {
        'paid': 'mark as paid',
        'cancelled': 'cancel',
        'sent': 'mark as sent',
        'draft': 'revert to draft'
    };
    
    if (confirm(`Are you sure you want to ${statusNames[newStatus] || 'change status of'} this invoice?`)) {
        window.location = `/operations/change-invoice-status.php?id=${invoiceId}&status=${newStatus}`;
    }
}

// Quick stats refresh
function refreshStats() {
    fetch('/operations/api/invoice-stats.php')
        .then(response => response.json())
        .then(data => {
            // Update stat numbers without page reload
            document.querySelectorAll('[data-stat]').forEach(el => {
                const statType = el.getAttribute('data-stat');
                if (data[statType] !== undefined) {
                    el.textContent = data[statType];
                }
            });
        })
        .catch(error => console.error('Stats refresh failed:', error));
}

// Refresh stats every 2 minutes
setInterval(refreshStats, 120000);

// Print functionality
function printInvoice(invoiceId) {
    window.open(`/operations/print-invoice.php?id=${invoiceId}`, '_blank');
}

// Advanced filtering
function applyAdvancedFilters() {
    const modal = document.getElementById('advancedFiltersModal');
    if (modal) {
        const bootstrapModal = new bootstrap.Modal(modal);
        bootstrapModal.show();
    }
}

// Currency formatting for live calculations
function formatCurrency(amount, currency = 'GBP') {
    const symbols = {'GBP': '£', 'USD': '$', 'EUR': '€'};
    const symbol = symbols[currency] || currency + ' ';
    return symbol + new Intl.NumberFormat('en-GB', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(amount);
}

// Real-time search suggestions
let searchSuggestions = [];
function setupSearchSuggestions() {
    fetch('/operations/api/search-suggestions.php')
        .then(response => response.json())
        .then(data => {
            searchSuggestions = data;
            setupSearchAutocomplete();
        })
        .catch(error => console.error('Failed to load search suggestions:', error));
}

function setupSearchAutocomplete() {
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput && searchSuggestions.length > 0) {
        // Add autocomplete functionality here
        searchInput.setAttribute('list', 'search-suggestions');
        
        let datalist = document.getElementById('search-suggestions');
        if (!datalist) {
            datalist = document.createElement('datalist');
            datalist.id = 'search-suggestions';
            searchInput.parentNode.appendChild(datalist);
        }
        
        datalist.innerHTML = '';
        searchSuggestions.forEach(suggestion => {
            const option = document.createElement('option');
            option.value = suggestion;
            datalist.appendChild(option);
        });
    }
}

// Initialize search suggestions on page load
document.addEventListener('DOMContentLoaded', function() {
    setupSearchSuggestions();
    
    // Add loading spinner for long operations
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Loading...';
                submitBtn.disabled = true;
            }
        });
    });
});

// Performance monitoring
console.log('Invoice management page loaded at:', new Date().toISOString());
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>