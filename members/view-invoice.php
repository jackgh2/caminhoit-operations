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

// Access control - customers and allowed roles only
if (!isset($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

$user_id = $_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'];

// FIXED: Get user's accessible companies using the correct table structure from your manage users page
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.name 
    FROM companies c
    WHERE c.id IN (
        SELECT u.company_id FROM users u WHERE u.id = ? AND u.company_id IS NOT NULL
        UNION
        SELECT cu.company_id FROM company_users cu WHERE cu.user_id = ?
    )
    ORDER BY c.name
");
$stmt->execute([$user_id, $user_id]);
$accessible_companies = $stmt->fetchAll();

// If STILL no companies, check the user's primary company directly
if (empty($accessible_companies)) {
    $stmt = $pdo->prepare("SELECT id, username, company_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch();
    
    if ($user_data && $user_data['company_id']) {
        $stmt = $pdo->prepare("SELECT id, name FROM companies WHERE id = ?");
        $stmt->execute([$user_data['company_id']]);
        $company = $stmt->fetch();
        if ($company) {
            $accessible_companies = [$company];
        }
    }
}

if (empty($accessible_companies)) {
    header('Location: /dashboard.php?error=' . urlencode('No company access found - check your company assignments'));
    exit;
}

// Get selected company - DEFAULT TO EMPTY (ALL COMPANIES)
$selected_company_id = $_GET['company_id'] ?? '';

// Verify user has access to selected company if one is selected
if ($selected_company_id !== '') {
    $has_access = false;
    foreach ($accessible_companies as $company) {
        if ($company['id'] == $selected_company_id) {
            $has_access = true;
            break;
        }
    }
    
    if (!$has_access) {
        $selected_company_id = '';
    }
}

// Simple company details function (fallback)
function getCompanyDetailsSimple($currency = 'GBP') {
    if (function_exists('getCompanyDetailsPayment')) {
        return getCompanyDetailsPayment($currency);
    }
    
    if ($currency === 'EUR') {
        return [
            'name' => 'CaminhoIT Portugal',
            'address' => "Rua Example, 123\n1000-001 Lisboa\nPortugal",
            'phone' => '+351 21 123 4567',
            'email' => 'invoices@caminhoit.pt',
            'vat_number' => 'PT123456789',
            'vat_registered' => true
        ];
    } else {
        return [
            'name' => 'CaminhoIT Ltd',
            'address' => "123 Example Street\nLondon, SW1A 1AA\nUnited Kingdom",
            'phone' => '+44 20 1234 5678',
            'email' => 'invoices@caminhoit.com',
            'vat_number' => 'GB123456789',
            'vat_registered' => true
        ];
    }
}

// Check if we're viewing a specific invoice
$invoice_id = intval($_GET['invoice_id'] ?? 0);
$order_id = intval($_GET['order_id'] ?? 0);

// INDIVIDUAL INVOICE VIEW
if ($invoice_id || $order_id) {
    try {
        $company_ids = array_column($accessible_companies, 'id');
        
        if ($invoice_id) {
            // Load existing invoice
            $stmt = $pdo->prepare("SELECT i.*, o.order_number, c.name as company_name, c.contact_email as company_email,
                c.address as company_address, c.phone as company_phone, c.vat_number as company_vat
                FROM invoices i
                LEFT JOIN orders o ON i.order_id = o.id
                JOIN companies c ON i.company_id = c.id
                WHERE i.id = ? AND i.company_id IN (" . implode(',', $company_ids) . ")");
            $stmt->execute([$invoice_id]);
            $invoice = $stmt->fetch();
        } else {
            // Check if invoice already exists for this order
            $stmt = $pdo->prepare("SELECT i.*, o.order_number, c.name as company_name, c.contact_email as company_email,
                c.address as company_address, c.phone as company_phone, c.vat_number as company_vat
                FROM invoices i
                LEFT JOIN orders o ON i.order_id = o.id
                JOIN companies c ON i.company_id = c.id
                WHERE i.order_id = ? AND i.company_id IN (" . implode(',', $company_ids) . ") 
                ORDER BY i.created_at DESC LIMIT 1");
            $stmt->execute([$order_id]);
            $invoice = $stmt->fetch();
            
            if (!$invoice) {
                throw new Exception('Invoice not found for this order. Please contact support to create an invoice.');
            }
        }
        
        if (!$invoice) {
            throw new Exception('Invoice not found or access denied');
        }
        
        // Get invoice items
        $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id");
        $stmt->execute([$invoice['id']]);
        $invoice_items = $stmt->fetchAll();
        
        // Get company details for the invoice header
        $company_details = getCompanyDetailsSimple($invoice['currency']);
        
        $page_title = "Invoice #" . $invoice['invoice_number'] . " | CaminhoIT";
        $show_individual_invoice = true;
        
    } catch (Exception $e) {
        error_log("Invoice view error: " . $e->getMessage());
        header('Location: /members/view-invoice.php?error=' . urlencode('Error loading invoice: ' . $e->getMessage()));
        exit;
    }
} 

// INVOICE LIST VIEW
else {
    // Get filter parameters
    $status_filter = $_GET['status'] ?? 'all';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $search = $_GET['search'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = 25;
    $offset = ($page - 1) * $per_page;

    // Build query conditions
    $company_ids = array_column($accessible_companies, 'id');
    
    // DEFAULT: Show all companies unless specifically filtered
    if ($selected_company_id !== '' && $selected_company_id !== 'all') {
        $where_conditions = ["i.company_id = ?"];
        $params = [$selected_company_id];
    } else {
        $where_conditions = ["i.company_id IN (" . implode(',', $company_ids) . ")"];
        $params = [];
    }

    if ($status_filter !== 'all') {
        $where_conditions[] = "i.status = ?";
        $params[] = $status_filter;
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
        $where_conditions[] = "(i.invoice_number LIKE ? OR o.order_number LIKE ? OR i.notes LIKE ? OR c.name LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);

    try {
        // Get total count
        $count_query = "SELECT COUNT(*) as total 
                        FROM invoices i 
                        LEFT JOIN orders o ON i.order_id = o.id 
                        JOIN companies c ON i.company_id = c.id
                        {$where_sql}";
        $stmt = $pdo->prepare($count_query);
        $stmt->execute($params);
        $total_count = $stmt->fetch()['total'];
        
        // Get invoices
        $query = "SELECT i.*, o.order_number, c.name as company_name,
                         DATEDIFF(CURDATE(), i.due_date) as days_overdue,
                         (i.total_amount - COALESCE(i.paid_amount, 0)) as outstanding_amount
                  FROM invoices i 
                  LEFT JOIN orders o ON i.order_id = o.id 
                  JOIN companies c ON i.company_id = c.id
                  {$where_sql}
                  ORDER BY i.created_at DESC 
                  LIMIT {$per_page} OFFSET {$offset}";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll();
        
        // Get summary statistics across all or selected companies
        if ($selected_company_id !== '' && $selected_company_id !== 'all') {
            $stats_query = "SELECT 
                                COUNT(*) as total_invoices,
                                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
                                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
                                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
                                SUM(CASE WHEN status = 'partially_paid' THEN 1 ELSE 0 END) as partially_paid_count,
                                SUM(total_amount) as total_value,
                                SUM(COALESCE(paid_amount, 0)) as paid_value,
                                SUM(total_amount - COALESCE(paid_amount, 0)) as outstanding_value,
                                currency
                            FROM invoices i 
                            WHERE i.company_id = ?
                            GROUP BY currency";
            
            $stmt = $pdo->prepare($stats_query);
            $stmt->execute([$selected_company_id]);
            $statistics = $stmt->fetchAll();
        } else {
            $stats_query = "SELECT 
                                COUNT(*) as total_invoices,
                                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
                                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
                                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
                                SUM(CASE WHEN status = 'partially_paid' THEN 1 ELSE 0 END) as partially_paid_count,
                                SUM(total_amount) as total_value,
                                SUM(COALESCE(paid_amount, 0)) as paid_value,
                                SUM(total_amount - COALESCE(paid_amount, 0)) as outstanding_value,
                                currency
                            FROM invoices i 
                            WHERE i.company_id IN (" . implode(',', $company_ids) . ")
                            GROUP BY currency";
            
            $stmt = $pdo->prepare($stats_query);
            $stmt->execute();
            $statistics = $stmt->fetchAll();
        }
        
        $total_pages = ceil($total_count / $per_page);
        $page_title = "Invoices | CaminhoIT";
        $show_individual_invoice = false;
        
    } catch (PDOException $e) {
        error_log("Invoice query error: " . $e->getMessage());
        $invoices = [];
        $total_count = 0;
        $statistics = [];
        $show_individual_invoice = false;
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
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>
    <style>

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
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            text-align: center;
            position: relative;
            overflow: hidden;
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

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .stat-number.primary { color: #667eea; }
        .stat-number.success { color: #28a745; }
        .stat-number.warning { color: #ffc107; }
        .stat-number.danger { color: #dc3545; }

        .stat-label {
            color: #6c757d;
            font-weight: 500;
            position: relative;
            z-index: 1;
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

        /* Invoice Number Link */
        .invoice-number-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }

        .invoice-number-link:hover {
            color: #764ba2;
            text-decoration: underline;
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
            padding: 0;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .filter-form::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .filter-form h5 {
            background: linear-gradient(135deg, #f8fafc 0%, #e9ecef 100%);
            color: #667eea;
            margin: 0 !important;
            padding: 1.25rem 1.5rem;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
        }

        .filter-form form {
            padding: 1.5rem;
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

        .btn-outline-enhanced {
            border: 2px solid #667eea;
            color: #667eea;
            background: transparent;
        }

        /* Invoice Specific Styles */
        .invoice-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            margin: 2rem 0;
        }

        .invoice-header {
            background: var(--primary-gradient);
            color: white;
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }

        .invoice-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            transform: translate(50%, -50%);
        }

        .invoice-body {
            padding: 2rem;
        }

        .invoice-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .invoice-status {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .status-sent { background: #e3f2fd; color: #1976d2; }
        .status-paid { background: #e8f5e8; color: #2e7d32; }
        .status-partially_paid { background: #fff3e0; color: #f57c00; }
        .status-overdue { background: #ffebee; color: #d32f2f; }
        .status-draft { background: #f5f5f5; color: #757575; }

        .company-details {
            text-align: right;
        }

        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin: 2rem 0;
        }

        .invoice-table thead {
            background: var(--primary-gradient);
        }

        .invoice-table th {
            background: transparent;
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            border: none;
        }

        .invoice-table td {
            padding: 1rem;
            border-bottom: 1px solid #e9ecef;
        }

        .invoice-table tr:hover td {
            background: rgba(102, 126, 234, 0.05);
        }

        .invoice-totals {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-top: 2rem;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e9ecef;
        }

        .total-row:last-child {
            border-bottom: 2px solid #667eea;
            font-weight: 700;
            font-size: 1.25rem;
            padding-top: 1rem;
        }

        .payment-buttons {
            text-align: center;
            margin: 2rem 0;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero-title-enhanced {
                font-size: 2rem;
            }
            
            .table-responsive {
                font-size: 0.875rem;
            }
            
            .filter-form {
                padding: 1rem;
            }

            .invoice-body { 
                padding: 1rem; 
            }
            
            .company-details { 
                text-align: left; 
                margin-top: 2rem; 
            }
            
            .invoice-table { 
                font-size: 0.875rem; 
            }
            
            .btn-enhanced { 
                margin: 0.25rem; 
            }
        }

        @media print {
            /* Hide non-printable elements */
            .no-print,
            header.hero,
            .breadcrumb,
            .btn,
            button,
            nav,
            .alert,
            footer {
                display: none !important;
            }

            /* Page setup for single page */
            @page {
                size: A4;
                margin: 15mm;
            }

            body {
                background: white !important;
                color: #000 !important;
                font-size: 11pt;
                line-height: 1.4;
                margin: 0;
                padding: 0;
            }

            .container {
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            /* Invoice container - compact and cute */
            .invoice-container {
                box-shadow: none !important;
                border: 2px solid #667eea !important;
                border-radius: 8px !important;
                padding: 20px !important;
                background: white !important;
                page-break-inside: avoid;
            }

            /* Cute header with gradient accent */
            .invoice-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
                color: white !important;
                padding: 15px 20px !important;
                margin: -20px -20px 20px -20px !important;
                border-radius: 6px 6px 0 0 !important;
                display: flex !important;
                justify-content: space-between !important;
                align-items: flex-start !important;
            }

            .invoice-header .row {
                width: 100%;
                display: flex;
                justify-content: space-between;
            }

            .invoice-header .col-md-6 {
                width: 48%;
            }

            .invoice-header h1,
            .invoice-header h4,
            .invoice-header div,
            .invoice-header small {
                color: white !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .invoice-number {
                font-size: 24pt !important;
                font-weight: 700 !important;
                margin-bottom: 5px !important;
            }

            .invoice-status {
                background: rgba(255,255,255,0.3) !important;
                padding: 4px 12px !important;
                border-radius: 20px !important;
                font-size: 9pt !important;
                font-weight: 600 !important;
                display: inline-block !important;
            }

            .company-details {
                text-align: right !important;
                font-size: 9pt !important;
                line-height: 1.5 !important;
            }

            .company-details h4 {
                font-size: 13pt !important;
                margin-bottom: 5px !important;
            }

            /* Invoice body - two columns for customer and invoice details */
            .invoice-body {
                background: white !important;
                padding: 15px 0 !important;
            }

            .invoice-body .row {
                display: flex !important;
                justify-content: space-between !important;
                margin-bottom: 20px !important;
            }

            .invoice-body .col-md-6 {
                width: 48% !important;
            }

            .invoice-body h6 {
                color: #667eea !important;
                font-size: 10pt !important;
                font-weight: 700 !important;
                text-transform: uppercase !important;
                letter-spacing: 0.5px !important;
                margin-bottom: 8px !important;
                border-bottom: 2px solid #667eea !important;
                padding-bottom: 4px !important;
            }

            .invoice-body address {
                font-size: 9.5pt !important;
                line-height: 1.6 !important;
                font-style: normal !important;
            }

            .invoice-body table {
                width: 100% !important;
                font-size: 9pt !important;
            }

            .invoice-body table td {
                padding: 3px 0 !important;
                border: none !important;
            }

            /* Invoice items table - cute and compact */
            .invoice-table {
                width: 100% !important;
                border-collapse: collapse !important;
                margin: 15px 0 !important;
                font-size: 9.5pt !important;
            }

            .invoice-table thead {
                background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%) !important;
                color: #374151 !important;
            }

            .invoice-table thead th {
                padding: 8px 10px !important;
                font-weight: 700 !important;
                text-transform: uppercase !important;
                font-size: 8.5pt !important;
                letter-spacing: 0.3px !important;
                border: none !important;
                border-bottom: 2px solid #667eea !important;
            }

            .invoice-table tbody td {
                padding: 8px 10px !important;
                border-bottom: 1px solid #e5e7eb !important;
                vertical-align: top !important;
            }

            .invoice-table tbody tr:last-child td {
                border-bottom: none !important;
            }

            .invoice-table tbody strong {
                color: #1f2937 !important;
                font-weight: 600 !important;
            }

            .invoice-table .text-muted,
            .invoice-table small {
                color: #6b7280 !important;
                font-size: 8pt !important;
            }

            /* Totals section - cute box */
            .invoice-totals {
                background: #f9fafb !important;
                border: 2px solid #667eea !important;
                border-radius: 8px !important;
                padding: 12px 15px !important;
                margin-top: 10px !important;
            }

            .total-row {
                display: flex !important;
                justify-content: space-between !important;
                padding: 5px 0 !important;
                border-bottom: 1px solid #e5e7eb !important;
                font-size: 10pt !important;
            }

            .total-row:last-child {
                border-bottom: none !important;
                border-top: 2px solid #667eea !important;
                padding-top: 10px !important;
                margin-top: 8px !important;
                font-weight: 700 !important;
                font-size: 12pt !important;
                color: #667eea !important;
            }

            .total-row span {
                color: #374151 !important;
            }

            .total-row.text-success {
                color: #059669 !important;
            }

            .total-row.text-danger {
                color: #dc2626 !important;
            }

            /* Notes section */
            .invoice-notes {
                margin-top: 15px !important;
                padding: 12px !important;
                background: #fffbeb !important;
                border-left: 4px solid #f59e0b !important;
                border-radius: 4px !important;
                font-size: 9pt !important;
                line-height: 1.5 !important;
            }

            /* Payment details */
            .payment-details {
                margin-top: 20px !important;
                padding: 12px !important;
                background: #f0fdf4 !important;
                border-left: 4px solid #10b981 !important;
                border-radius: 4px !important;
                font-size: 9pt !important;
            }

            /* Footer text */
            .invoice-footer {
                margin-top: 20px !important;
                padding-top: 15px !important;
                border-top: 2px solid #e5e7eb !important;
                text-align: center !important;
                font-size: 8pt !important;
                color: #6b7280 !important;
            }

            /* Ensure no page breaks in critical areas */
            .invoice-header,
            .invoice-totals {
                page-break-inside: avoid !important;
            }

            /* Remove shadows and transitions */
            * {
                box-shadow: none !important;
                transition: none !important;
                animation: none !important;
            }

            /* Bootstrap overrides for print */
            .row {
                display: flex !important;
            }

            .col-md-6 {
                flex: 0 0 50% !important;
                max-width: 50% !important;
            }

            .justify-content-end {
                justify-content: flex-end !important;
            }

            /* Badge styling for print */
            .badge {
                padding: 3px 8px !important;
                border-radius: 12px !important;
                font-size: 8pt !important;
            }

            .bg-info {
                background: #3b82f6 !important;
                color: white !important;
            }
        }

        /* DARK MODE STYLES */
        :root.dark,
        :root.dark body {
            background: #0f172a !important;
            color: #e2e8f0 !important;
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

        :root.dark .viewinvoice-hero-title,
        :root.dark .viewinvoice-hero-subtitle {
            color: white !important;
            position: relative;
            z-index: 2;
        }
        html.dark .enhanced-card { background: #1e293b; border-color: #334155; }
        html.dark .card-header-enhanced { background: linear-gradient(135deg, #1e293b 0%, #334155 100%); border-color: #334155; }
        html.dark .card-title-enhanced { color: #f1f5f9; }
        html.dark .stat-card { background: #1e293b; border-color: #334155; }
        html.dark .stat-number { color: #a78bfa; }
        html.dark .stat-label { color: #94a3b8; }
        html.dark .filter-form { background: #1e293b; border-color: #334155; }
        html.dark .filter-form h5 { background: linear-gradient(135deg, #1e293b 0%, #334155 100%); color: #a78bfa; border-color: #334155; }
        html.dark .form-control, html.dark .form-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
        html.dark .form-control:focus, html.dark .form-select:focus { background: #0f172a; border-color: #8b5cf6; }
        :root.dark .invoice-container {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }
        :root.dark .invoice-header {
            background: #0f172a !important;
            border-color: #334155 !important;
        }
        :root.dark .invoice-header h1,
        :root.dark .invoice-header h2,
        :root.dark .invoice-header h3,
        :root.dark .invoice-header p,
        :root.dark .invoice-header strong {
            color: #e2e8f0 !important;
        }
        :root.dark .invoice-body {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }
        :root.dark .invoice-body h4,
        :root.dark .invoice-body h5,
        :root.dark .invoice-body h6,
        :root.dark .invoice-body p,
        :root.dark .invoice-body strong {
            color: #e2e8f0 !important;
        }

        /* Total rows and summary */
        :root.dark .invoice-totals {
            background: #1e293b !important;
            border: 1px solid #334155 !important;
            padding: 1.5rem !important;
            border-radius: 8px !important;
        }

        :root.dark .total-row {
            background: transparent !important;
            color: #e2e8f0 !important;
            border-color: #334155 !important;
        }

        :root.dark .total-row span {
            color: #e2e8f0 !important;
        }

        :root.dark .total-row:last-child {
            background: rgba(139, 92, 246, 0.2) !important;
            color: #a78bfa !important;
            border-color: #8b5cf6 !important;
        }

        :root.dark .total-row:last-child span {
            color: #a78bfa !important;
        }

        /* All sections and boxes */
        :root.dark .invoice-details,
        :root.dark .invoice-section,
        :root.dark .info-box,
        :root.dark .summary-box {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        html.dark .table { color: #e2e8f0; }
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
        html.dark .table tbody tr:hover td { background: rgba(139, 92, 246, 0.1) !important; }
        html.dark .text-muted { color: #94a3b8 !important; }
        html.dark small { color: #94a3b8; }

        /* Invoice Number Link - Dark Mode */
        :root.dark .invoice-number-link {
            color: #a78bfa;
        }

        :root.dark .invoice-number-link:hover {
            color: #c4b5fd;
        }

        /* Breadcrumb - Dark Mode Fix */
        :root.dark .breadcrumb-enhanced {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .breadcrumb-item {
            color: #cbd5e1 !important;
        }

        :root.dark .breadcrumb-item a {
            color: #94a3b8 !important;
        }

        :root.dark .breadcrumb-item a:hover {
            color: #cbd5e1 !important;
        }

        :root.dark .breadcrumb-item.active {
            color: #e2e8f0 !important;
        }

        :root.dark .breadcrumb-item + .breadcrumb-item::before {
            color: #64748b !important;
        }
    </style>

<?php if ($show_individual_invoice): ?>
<!-- INDIVIDUAL INVOICE VIEW -->
<div class="container py-4">
    <!-- Action Bar -->
    <div class="row no-print">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <a href="/members/view-invoice.php" class="btn btn-outline-enhanced">
                    <i class="bi bi-arrow-left"></i>
                    Back to Invoices
                </a>
                <div>
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

    <!-- Invoice -->
    <div class="invoice-container">
        <!-- Invoice Header -->
        <div class="invoice-header">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="invoice-number">Invoice #<?= htmlspecialchars($invoice['invoice_number']) ?></h1>
                    <span class="invoice-status status-<?= $invoice['status'] ?>">
                        <?= ucfirst(str_replace('_', ' ', $invoice['status'])) ?>
                    </span>
                    <?php if (isset($invoice['auto_generated']) && $invoice['auto_generated']): ?>
                    <br><small class="mt-2 d-inline-block opacity-75"><i class="bi bi-robot me-1"></i>Auto-generated</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-6 company-details">
                    <h4><?= htmlspecialchars($company_details['name']) ?></h4>
                    <div><?= nl2br(htmlspecialchars($company_details['address'])) ?></div>
                    <?php if ($company_details['vat_number']): ?>
                    <div>VAT: <?= htmlspecialchars($company_details['vat_number']) ?></div>
                    <?php endif; ?>
                    <div><?= htmlspecialchars($company_details['phone']) ?></div>
                    <div><?= htmlspecialchars($company_details['email']) ?></div>
                </div>
            </div>
        </div>

        <!-- Invoice Body -->
        <div class="invoice-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Bill To:</h6>
                    <address>
                        <strong><?= htmlspecialchars($invoice['company_name']) ?></strong><br>
                        <?= nl2br(htmlspecialchars($invoice['company_address'])) ?><br>
                        <?php if ($invoice['company_phone']): ?>
                        Tel: <?= htmlspecialchars($invoice['company_phone']) ?><br>
                        <?php endif; ?>
                        Email: <?= htmlspecialchars($invoice['company_email']) ?><br>
                        <?php if ($invoice['company_vat']): ?>
                        VAT: <?= htmlspecialchars($invoice['company_vat']) ?>
                        <?php endif; ?>
                    </address>
                </div>
                <div class="col-md-6">
                    <table class="table table-borderless">
                        <tr>
                            <td><strong>Invoice Date:</strong></td>
                            <td><?= date('d/m/Y', strtotime($invoice['issue_date'])) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Due Date:</strong></td>
                            <td><?= date('d/m/Y', strtotime($invoice['due_date'])) ?></td>
                        </tr>
                        <?php if ($invoice['order_number']): ?>
                        <tr>
                            <td><strong>Order Number:</strong></td>
                            <td>#<?= htmlspecialchars($invoice['order_number']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($invoice['payment_reference']): ?>
                        <tr>
                            <td><strong>Payment Ref:</strong></td>
                            <td><?= htmlspecialchars($invoice['payment_reference']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($invoice['invoice_type'] === 'recurring'): ?>
                        <tr>
                            <td><strong>Type:</strong></td>
                            <td><span class="badge bg-info">Recurring</span></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- Invoice Items -->
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoice_items as $item): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($item['description']) ?></strong>
                            <?php if ($item['billing_cycle'] !== 'one_time'): ?>
                            <br><small class="text-muted">Billing: <?= ucfirst(str_replace('_', ' ', $item['billing_cycle'])) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= number_format($item['quantity'], 2) ?></td>
                        <td><?= formatCurrency($item['unit_price'], $invoice['currency']) ?></td>
                        <td><?= formatCurrency($item['total_amount'], $invoice['currency']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Invoice Totals -->
            <div class="row justify-content-end">
                <div class="col-md-6">
                    <div class="invoice-totals">
                        <div class="total-row">
                            <span>Subtotal:</span>
                            <span><?= formatCurrency($invoice['subtotal'], $invoice['currency']) ?></span>
                        </div>
                        
                        <?php if ($invoice['discount_amount'] > 0): ?>
                        <div class="total-row text-success">
                            <span>Discount:</span>
                            <span>-<?= formatCurrency($invoice['discount_amount'], $invoice['currency']) ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($invoice['tax_amount'] > 0): ?>
                        <div class="total-row">
                            <span><?= $company_details['vat_registered'] ? 'VAT' : 'Tax' ?> (<?= number_format($invoice['tax_rate'] * 100, 1) ?>%):</span>
                            <span><?= formatCurrency($invoice['tax_amount'], $invoice['currency']) ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (isset($invoice['late_fee_amount']) && $invoice['late_fee_amount'] > 0): ?>
                        <div class="total-row text-danger">
                            <span>Late Fee:</span>
                            <span><?= formatCurrency($invoice['late_fee_amount'], $invoice['currency']) ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="total-row">
                            <span>Total:</span>
                            <span><?= formatCurrency($invoice['total_amount'], $invoice['currency']) ?></span>
                        </div>
                        
                        <?php if (isset($invoice['paid_amount']) && $invoice['paid_amount'] > 0): ?>
                        <div class="total-row text-success">
                            <span>Paid:</span>
                            <span>-<?= formatCurrency($invoice['paid_amount'], $invoice['currency']) ?></span>
                        </div>
                        <div class="total-row">
                            <span><strong>Outstanding:</strong></span>
                            <span><strong><?= formatCurrency($invoice['total_amount'] - $invoice['paid_amount'], $invoice['currency']) ?></strong></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Payment Terms -->
            <?php if ($invoice['payment_terms']): ?>
            <div class="mt-4">
                <h6>Payment Terms:</h6>
                <p><?= htmlspecialchars($invoice['payment_terms']) ?></p>
            </div>
            <?php endif; ?>

            <!-- Notes -->
            <?php if ($invoice['notes']): ?>
            <div class="mt-4">
                <h6>Notes:</h6>
                <p><?= nl2br(htmlspecialchars($invoice['notes'])) ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Buttons -->
    <?php if (in_array($invoice['status'], ['sent', 'overdue', 'partially_paid'])): ?>
    <div class="payment-buttons no-print">
        <a href="/members/process-payment.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-success-enhanced btn-enhanced">
            <i class="bi bi-credit-card"></i>
            Pay Now - <?= formatCurrency($invoice['total_amount'] - ($invoice['paid_amount'] ?? 0), $invoice['currency']) ?>
        </a>
        <a href="/members/setup-recurring.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-primary-enhanced btn-enhanced">
            <i class="bi bi-arrow-repeat"></i>
            Set Up Recurring Payment
        </a>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- INVOICE LIST VIEW -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="viewinvoice-hero-content">
            <h1 class="viewinvoice-hero-title">
                <i class="bi bi-receipt me-3"></i>
                Your Invoices
            </h1>
            <p class="viewinvoice-hero-subtitle">
                View and manage invoices across all your companies
            </p>
        </div>
    </div>
</header>

<div class="container py-5 overlap-cards">
    
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="breadcrumb-enhanced">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item active" aria-current="page">Invoices</li>
        </ol>
    </nav>

    <!-- Error Messages -->
    <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?= htmlspecialchars($_GET['error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Success Messages -->
    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i>
        <?= htmlspecialchars($_GET['success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <?php if (!empty($statistics)): ?>
    <div class="row g-4 mb-4">
        <?php foreach ($statistics as $stat): ?>
        <div class="col-lg-6 col-md-6">
            <div class="enhanced-card">
                <div class="card-body-enhanced text-center">
                    <h6 class="text-muted mb-3"><?= strtoupper($stat['currency']) ?> Statistics</h6>
                    
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="stat-card primary">
                                <div class="stat-number primary"><?= number_format($stat['sent_count']) ?></div>
                                <div class="stat-label">Sent</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-card success">
                                <div class="stat-number success"><?= number_format($stat['paid_count']) ?></div>
                                <div class="stat-label">Paid</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-card danger">
                                <div class="stat-number danger"><?= number_format($stat['overdue_count']) ?></div>
                                <div class="stat-label">Overdue</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-card warning">
                                <div class="stat-number warning"><?= formatCurrency($stat['outstanding_value'], $stat['currency']) ?></div>
                                <div class="stat-label">Outstanding</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="filter-form">
        <form method="GET" action="">
            <div class="row g-3 align-items-end">
                <!-- ALWAYS SHOW COMPANY DROPDOWN -->
                <div class="col-md-3">
                    <label class="form-label">
                        <i class="bi bi-building me-1"></i>Company Filter
                    </label>
                    <select name="company_id" class="form-control-enhanced" onchange="this.form.submit()">
                        <option value="">All Companies (<?= count($accessible_companies) ?>)</option>
                        <?php foreach ($accessible_companies as $company): ?>
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
                    <input type="text" name="search" class="form-control-enhanced" placeholder="Invoice #, Order #" value="<?= htmlspecialchars($search) ?>">
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
                <i class="bi bi-receipt"></i>
                <?php 
                if ($selected_company_id !== '' && $selected_company_id !== 'all') {
                    $selected_company_name = '';
                    foreach ($accessible_companies as $company) {
                        if ($company['id'] == $selected_company_id) {
                            $selected_company_name = $company['name'];
                            break;
                        }
                    }
                    echo "Invoices for " . htmlspecialchars($selected_company_name);
                } else {
                    echo "All Company Invoices";
                }
                ?>
                (<?= number_format($total_count) ?> total)
            </h5>
        </div>
        <div class="card-body-enhanced p-0">
            <?php if (empty($invoices)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox display-1 text-muted"></i>
                <p class="text-muted mt-3">No invoices found matching your criteria.</p>
                <a href="/members/view-invoice.php" class="btn btn-outline-primary">
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
                            <th>Order #</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Issue Date</th>
                            <th>Due Date</th>
                            <th>Outstanding</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td>
                                <a href="/members/view-invoice.php?invoice_id=<?= $invoice['id'] ?>" class="invoice-number-link">
                                    <strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong>
                                </a>
                                <?php if ($invoice['invoice_type'] === 'recurring'): ?>
                                <br><small class="badge bg-info">Recurring</small>
                                <?php endif; ?>
                                <?php if (isset($invoice['auto_generated']) && $invoice['auto_generated']): ?>
                                <br><small class="badge bg-secondary"><i class="bi bi-robot"></i> Auto</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($invoice['company_name']) ?></strong>
                            </td>
                            <td>
                                <?php if ($invoice['order_number']): ?>
                                <strong>#<?= htmlspecialchars($invoice['order_number']) ?></strong>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
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
                                <div class="btn-group btn-group-sm">
                                    <a href="/members/view-invoice.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-outline-primary" title="View Invoice">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if (!in_array($invoice['status'], ['paid', 'cancelled']) && $invoice['outstanding_amount'] > 0): ?>
                                    <a href="/members/process-payment.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-outline-success" title="Pay Now">
                                        <i class="bi bi-credit-card"></i>
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
</div>
<?php endif; ?>

<script>
// Auto-refresh overdue status
setTimeout(function() {
    // Update overdue invoices every 5 minutes
    const overdueElements = document.querySelectorAll('.status-sent, .status-partially_paid');
    overdueElements.forEach(function(element) {
        const dueDateText = element.closest('tr').querySelector('td:nth-child(7)').textContent;
        const dueDate = new Date(dueDateText.split('/').reverse().join('-'));
        const today = new Date();
        
        if (dueDate < today && element.textContent.toLowerCase().includes('sent')) {
            element.className = element.className.replace('bg-primary', 'bg-danger');
            element.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Overdue';
        }
    });
}, 300000); // 5 minutes
</script>



<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
