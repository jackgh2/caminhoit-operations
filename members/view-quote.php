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
$quote_id = (int)($_GET['id'] ?? 0);

if (!$quote_id) {
    header('Location: quotes.php');
    exit;
}

// Get supported currencies - FIXED to use system_config table
$supportedCurrencies = [];
try {
    $stmt = $pdo->query("SELECT config_key, config_value FROM system_config WHERE category = 'currency' AND config_key = 'currency.supported_currencies'");
    $currencyRow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($currencyRow) {
        $supportedCurrencies = json_decode($currencyRow['config_value'], true);
    }
    
    if (empty($supportedCurrencies)) {
        throw new Exception("No currency data found");
    }
} catch (Exception $e) {
    $supportedCurrencies = [
        'GBP' => ['symbol' => '£', 'name' => 'British Pound'],
        'USD' => ['symbol' => '$', 'name' => 'US Dollar'],
        'EUR' => ['symbol' => '€', 'name' => 'Euro'],
        'CAD' => ['symbol' => 'C$', 'name' => 'Canadian Dollar'],
        'AUD' => ['symbol' => 'A$', 'name' => 'Australian Dollar']
    ];
}

// Get quote details with access control
$stmt = $pdo->prepare("SELECT q.*, c.name as company_name, c.phone as company_phone, c.address as company_address,
    c.preferred_currency,
    u.username as staff_name, u.email as staff_email
    FROM quotes q
    JOIN companies c ON q.company_id = c.id
    JOIN users u ON q.staff_id = u.id
    WHERE q.id = ? AND (
        q.company_id = (SELECT company_id FROM users WHERE id = ?) 
        OR q.company_id IN (SELECT company_id FROM company_users WHERE user_id = ?)
    )");
$stmt->execute([$quote_id, $user_id, $user_id]);
$quote = $stmt->fetch();

if (!$quote) {
    header('Location: quotes.php?error=quote_not_found_or_access_denied');
    exit;
}

// Get quote items
$stmt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id ASC");
$stmt->execute([$quote_id]);
$quote_items = $stmt->fetchAll();

// Get status history
$stmt = $pdo->prepare("SELECT qsh.*, COALESCE(u.username, 'System') as changed_by_name
    FROM quote_status_history qsh
    LEFT JOIN users u ON qsh.changed_by = u.id
    WHERE qsh.quote_id = ?
    ORDER BY qsh.created_at DESC");
$stmt->execute([$quote_id]);
$status_history = $stmt->fetchAll();

// Get currency information
$quote_currency = $quote['currency'] ?? 'GBP';
$currency_symbol = $supportedCurrencies[$quote_currency]['symbol'] ?? '£';
$currency_name = $supportedCurrencies[$quote_currency]['name'] ?? $quote_currency;

// Check for success message
if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}

// Get business information from system_config - FIXED to use correct table
$business_info = [];
try {
    $stmt = $pdo->query("SELECT config_key, config_value FROM system_config WHERE category = 'business'");
    $business_config = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($business_config as $row) {
        // Remove the 'business.' prefix from keys
        $key = str_replace('business.', '', $row['config_key']);
        $business_info[$key] = $row['config_value'];
    }
    
} catch (Exception $e) {
    error_log("Error loading business config: " . $e->getMessage());
}

// Set business details using the correct keys from your database
$business_details = [
    'company_name' => $business_info['company_name'] ?? 'CaminhoIT',
    'company_address' => $business_info['company_address'] ?? '',
    'company_phone' => $business_info['company_phone'] ?? '',
    'company_email' => $business_info['company_email'] ?? '',
    'company_website' => $business_info['company_website'] ?? '',
    'default_currency' => $business_info['default_currency'] ?? 'GBP',
    'invoice_prefix' => $business_info['invoice_prefix'] ?? '',
    'order_prefix' => $business_info['order_prefix'] ?? ''
];

$page_title = "Quote #" . $quote['quote_number'] . " | " . $business_details['company_name'];
?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>
    <style>

        .quote-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            margin-bottom: 2rem;
            border: 1px solid var(--gray-200);
            position: relative;
            min-height: 800px;
        }

        .quote-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 8rem;
            font-weight: 900;
            color: rgba(0, 0, 0, 0.03);
            z-index: 1;
            pointer-events: none;
            user-select: none;
        }

        .quote-header {
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            padding: 3rem 2rem;
            position: relative;
            z-index: 2;
            border-bottom: 3px solid var(--primary-color);
        }

        .company-logo {
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.3);
            position: relative;
            overflow: hidden;
        }

        .company-logo::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transform: rotate(45deg);
            animation: shimmer 3s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }

        .company-name {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
            letter-spacing: -0.025em;
        }

        .company-details {
            color: var(--gray-600);
            font-size: 1rem;
            line-height: 1.7;
        }

        .quote-meta-container {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--gray-200);
        }

        .quote-number {
            font-size: 3rem;
            font-weight: 900;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            letter-spacing: -0.025em;
        }

        .quote-title {
            font-size: 1.25rem;
            color: var(--gray-600);
            margin-bottom: 2rem;
            font-weight: 500;
        }

        .meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-100);
        }

        .meta-row:last-child {
            border-bottom: none;
        }

        .meta-label {
            font-weight: 500;
            color: var(--gray-500);
        }

        .meta-value {
            font-weight: 600;
            color: var(--gray-900);
        }

        .quote-info {
            padding: 3rem 2rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            background: var(--gray-50);
            position: relative;
            z-index: 2;
        }

        .info-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            border: 1px solid var(--gray-200);
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }

        .info-card h6 {
            color: var(--primary-color);
            font-size: 0.875rem;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 1.5rem;
            letter-spacing: 0.05em;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-card p {
            margin-bottom: 0.75rem;
            color: var(--gray-600);
            font-size: 0.95rem;
        }

        .info-card strong {
            color: var(--gray-900);
            font-weight: 600;
        }

        .quote-description {
            padding: 2rem;
            background: var(--gray-50);
            border-left: 4px solid var(--primary-color);
            margin: 2rem;
            border-radius: 0 12px 12px 0;
            position: relative;
            z-index: 2;
        }

        .quote-description h6 {
            color: var(--primary-color);
            font-weight: 700;
            margin-bottom: 1rem;
            font-size: 1rem;
        }

        .quote-items {
            padding: 2rem;
            position: relative;
            z-index: 2;
        }

        .items-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 2rem;
            margin: 0 -2rem 2rem -2rem;
            position: relative;
            overflow: hidden;
        }

        .items-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: repeating-linear-gradient(
                45deg,
                transparent,
                transparent 10px,
                rgba(255, 255, 255, 0.05) 10px,
                rgba(255, 255, 255, 0.05) 20px
            );
        }

        .items-header h5 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .currency-badge {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 25px;
            padding: 0.75rem 1.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: white;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 2rem;
        }

        .items-table {
            border: none;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            background: white;
        }

        .items-table thead {
            background: linear-gradient(135deg, var(--gray-100) 0%, var(--gray-50) 100%);
        }

        .items-table th {
            border: none;
            font-weight: 700;
            color: var(--gray-700);
            padding: 1.5rem 1rem;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            position: relative;
        }

        .items-table th::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 1rem;
            right: 1rem;
            height: 2px;
            background: var(--primary-color);
        }

        .items-table td {
            padding: 1.5rem 1rem;
            border: none;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
            font-size: 0.95rem;
        }

        .items-table tbody tr:last-child td {
            border-bottom: none;
        }

        .items-table tbody tr:hover {
            background: rgba(37, 99, 235, 0.02);
        }

        .item-name {
            font-weight: 700;
            color: var(--gray-900);
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
        }

        .item-description {
            color: var(--gray-600);
            font-size: 0.875rem;
            font-style: italic;
        }

        .quantity-badge {
            background: var(--primary-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.875rem;
            display: inline-block;
            min-width: 40px;
            text-align: center;
        }

        .price-cell {
            font-weight: 700;
            color: var(--gray-900);
            font-size: 1.1rem;
            font-family: 'Inter', monospace;
        }

        .billing-badge {
            background: linear-gradient(135deg, var(--success-color) 0%, #059669 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            box-shadow: 0 2px 4px rgba(5, 150, 105, 0.3);
        }

        .quote-summary {
            background: white;
            border-radius: 16px;
            padding: 2.5rem;
            margin: 2rem;
            border: 2px solid var(--primary-color);
            position: relative;
            z-index: 2;
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.1);
        }

        .quote-summary::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            height: 6px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            border-radius: 16px 16px 0 0;
        }

        .summary-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 2rem;
            text-align: center;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid var(--gray-100);
            font-size: 1.1rem;
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-row.total {
            border-top: 3px solid var(--primary-color);
            margin-top: 1.5rem;
            padding-top: 2rem;
            font-weight: 800;
            font-size: 1.75rem;
            color: var(--primary-color);
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.05) 0%, rgba(37, 99, 235, 0.02) 100%);
            border-radius: 12px;
            padding: 2rem;
            margin: 1.5rem -1.5rem 0 -1.5rem;
        }

        .summary-label {
            font-weight: 600;
            color: var(--gray-600);
        }

        .summary-value {
            font-weight: 700;
            color: var(--gray-900);
            font-family: 'Inter', monospace;
        }

        .status-badge {
            padding: 0.75rem 1.5rem;
            border-radius: 25px;
            font-size: 0.875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border: 2px solid transparent;
        }

        .status-draft { 
            background: var(--gray-100); 
            color: var(--gray-700); 
            border-color: var(--gray-300);
        }
        .status-sent { 
            background: #dbeafe; 
            color: #1e40af; 
            border-color: #3b82f6;
        }
        .status-accepted { 
            background: #d1fae5; 
            color: #065f46; 
            border-color: var(--success-color);
        }
        .status-rejected { 
            background: #fee2e2; 
            color: #991b1b; 
            border-color: var(--danger-color);
        }
        .status-expired { 
            background: #fef3c7; 
            color: #92400e; 
            border-color: var(--warning-color);
        }

        .alert {
            border: none;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-weight: 500;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-left-color: var(--success-color);
        }

        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-left-color: var(--danger-color);
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border-left-color: var(--warning-color);
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
            font-weight: 600;
        }

        .breadcrumb-item a:hover {
            text-decoration: underline;
        }

        .btn {
            border-radius: 8px;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            transition: all 0.2s ease;
            border: 2px solid transparent;
        }

        .btn-primary {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(37, 99, 235, 0.3);
        }

        .btn-outline-primary {
            border-color: var(--primary-color);
            color: var(--primary-color);
            background: white;
        }

        .btn-outline-primary:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-1px);
        }

        .actions-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            border: 1px solid var(--gray-200);
        }

        .actions-section h5 {
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .terms-section {
            background: var(--gray-50);
            border-radius: 12px;
            padding: 2rem;
            margin: 2rem;
            border-left: 4px solid var(--info-color);
            position: relative;
            z-index: 2;
        }

        .terms-section h5 {
            color: var(--info-color);
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .notes-section {
            background: #fffbeb;
            border: 1px solid #fbbf24;
            border-radius: 12px;
            padding: 2rem;
            margin: 2rem;
            border-left: 4px solid var(--warning-color);
            position: relative;
            z-index: 2;
        }

        .notes-section h6 {
            color: var(--warning-color);
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .notes-text {
            color: var(--gray-700);
            font-style: italic;
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
            width: 3px;
            background: linear-gradient(to bottom, var(--primary-color), var(--primary-dark));
            border-radius: 2px;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 2rem;
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            border: 1px solid var(--gray-200);
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.8rem;
            top: 1rem;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: var(--primary-color);
            border: 3px solid white;
            box-shadow: 0 0 0 3px var(--primary-color);
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

        @media (max-width: 768px) {
            .quote-info {
                grid-template-columns: 1fr;
                gap: 1rem;
                padding: 2rem 1rem;
            }
            
            .quote-header {
                text-align: center;
                padding: 2rem 1rem;
            }
            
            .quote-number {
                font-size: 2rem;
            }
            
            .company-name {
                font-size: 1.75rem;
            }
            
            .items-table {
                font-size: 0.875rem;
            }
            
            .items-table th,
            .items-table td {
                padding: 1rem 0.5rem;
            }
        }

        /* DARK MODE STYLES */
        :root.dark,
        :root.dark body {
            background: #0f172a !important;
            color: #e2e8f0 !important;
        }

        /* Hero dark mode */
        :root.dark .hero {
            background: transparent !important;
        }

        :root.dark .hero-gradient {
            opacity: 1 !important;
            display: block !important;
            visibility: visible !important;
            z-index: 0 !important;
        }

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

        :root.dark .viewquote-hero-content h1,
        :root.dark .viewquote-hero-content p {
            color: white !important;
            position: relative;
            z-index: 2;
        }

        /* Quote container */
        :root.dark .quote-container {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .quote-header {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .quote-header h1,
        :root.dark .quote-header h2,
        :root.dark .quote-header h3,
        :root.dark .quote-header p,
        :root.dark .quote-header strong {
            color: #e2e8f0 !important;
        }

        :root.dark .quote-body {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .quote-body h4,
        :root.dark .quote-body h5,
        :root.dark .quote-body h6,
        :root.dark .quote-body p,
        :root.dark .quote-body strong {
            color: #e2e8f0 !important;
        }

        /* Tables */
        :root.dark .table,
        :root.dark .items-table {
            color: #e2e8f0 !important;
        }

        :root.dark .table thead,
        :root.dark .items-table thead {
            background: #0f172a !important;
        }

        :root.dark .table thead th,
        :root.dark .items-table thead th {
            background: #0f172a !important;
            color: #cbd5e1 !important;
            border-color: #334155 !important;
        }

        :root.dark .table tbody,
        :root.dark .items-table tbody {
            background: #1e293b !important;
        }

        :root.dark .table tbody tr,
        :root.dark .items-table tbody tr {
            background: #1e293b !important;
        }

        :root.dark .table tbody td,
        :root.dark .items-table tbody td {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .table tbody tr:hover,
        :root.dark .items-table tbody tr:hover {
            background: rgba(139, 92, 246, 0.1) !important;
        }

        /* Cards and sections */
        :root.dark .card {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .card-header {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .card-body {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark small {
            color: #94a3b8 !important;
        }

        :root.dark h1, :root.dark h2, :root.dark h3, :root.dark h4, :root.dark h5, :root.dark h6 {
            color: #f1f5f9 !important;
        }

        /* All quote boxes and sections */
        :root.dark .quote-meta-container,
        :root.dark .quote-info,
        :root.dark .info-card,
        :root.dark .quote-description,
        :root.dark .quote-items,
        :root.dark .quote-summary,
        :root.dark .actions-section,
        :root.dark .timeline,
        :root.dark .timeline-item,
        :root.dark .terms-section,
        :root.dark .items-header {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .quote-meta-container h5,
        :root.dark .info-card h5,
        :root.dark .quote-description h5,
        :root.dark .actions-section h5 {
            color: #f1f5f9 !important;
        }

        /* Company name and details */
        :root.dark .company-name {
            color: #f1f5f9 !important;
        }

        :root.dark .company-details {
            color: #cbd5e1 !important;
        }

        /* Quote meta elements */
        :root.dark .quote-number {
            color: #a78bfa !important;
        }

        :root.dark .quote-title {
            color: #cbd5e1 !important;
        }

        :root.dark .meta-row {
            border-color: #334155 !important;
        }

        :root.dark .meta-label {
            color: #94a3b8 !important;
        }

        :root.dark .meta-value {
            color: #e2e8f0 !important;
        }

        /* Info card content */
        :root.dark .info-card h6 {
            color: #a78bfa !important;
        }

        :root.dark .info-card p {
            color: #cbd5e1 !important;
        }

        :root.dark .info-card strong {
            color: #f1f5f9 !important;
        }

        /* Summary elements */
        :root.dark .summary-title {
            color: #f1f5f9 !important;
        }

        :root.dark .summary-label {
            color: #94a3b8 !important;
        }

        :root.dark .summary-value {
            color: #e2e8f0 !important;
        }

        /* Item table elements */
        :root.dark .item-name {
            color: #f1f5f9 !important;
        }

        :root.dark .item-description {
            color: #94a3b8 !important;
        }

        :root.dark .price-cell {
            color: #e2e8f0 !important;
        }

        /* Terms and description sections */
        :root.dark .terms-section h5,
        :root.dark .quote-description h6 {
            color: #a78bfa !important;
        }

        :root.dark .terms-section p,
        :root.dark .quote-description p {
            color: #cbd5e1 !important;
        }

        /* Timeline elements */
        :root.dark .timeline-item strong {
            color: #f1f5f9 !important;
        }

        /* Buttons dark mode */
        :root.dark .btn {
            color: #e2e8f0 !important;
        }

        :root.dark .btn-outline-primary {
            background: transparent !important;
            border-color: #8b5cf6 !important;
            color: #a78bfa !important;
        }

        :root.dark .btn-outline-primary:hover {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%) !important;
            border-color: #8b5cf6 !important;
            color: white !important;
        }

        :root.dark .btn-primary {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%) !important;
            border-color: #8b5cf6 !important;
            color: white !important;
        }

        :root.dark .btn-primary:hover {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%) !important;
            border-color: #7c3aed !important;
        }

        /* Section headers */
        :root.dark .section-header,
        :root.dark .box-header {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        /* Total rows and summary */
        :root.dark .total-row,
        :root.dark .summary-row {
            background: #0f172a !important;
            color: #e2e8f0 !important;
            border-color: #334155 !important;
        }

        :root.dark .total-row span,
        :root.dark .summary-row span {
            color: #e2e8f0 !important;
        }

        :root.dark .total-row:last-child {
            background: rgba(139, 92, 246, 0.2) !important;
            color: #a78bfa !important;
        }

        :root.dark .total-row:last-child span {
            color: #a78bfa !important;
        }

        /* Status badges and buttons */
        :root.dark .status-badge {
            background: #334155 !important;
            color: #cbd5e1 !important;
        }

        :root.dark .btn-outline-primary {
            border-color: #8b5cf6 !important;
            color: #a78bfa !important;
        }

        :root.dark .btn-outline-primary:hover {
            background: var(--primary-gradient) !important;
            color: white !important;
        }

        /* CUTE PRINT STYLES */
        @media print {
            /* Hide non-printable elements */
            .no-print,
            header.hero,
            .breadcrumb,
            .btn,
            button,
            nav,
            .alert,
            footer,
            .actions-section {
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

            /* Quote container - compact and cute */
            .quote-container {
                box-shadow: none !important;
                border: 2px solid #667eea !important;
                border-radius: 8px !important;
                padding: 20px !important;
                background: white !important;
                page-break-inside: avoid;
            }

            /* Cute header with gradient */
            .quote-meta-container {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
                color: white !important;
                padding: 15px 20px !important;
                margin: -20px -20px 20px -20px !important;
                border-radius: 6px 6px 0 0 !important;
            }

            .quote-meta-container h3,
            .quote-meta-container h4,
            .quote-meta-container .quote-number,
            .quote-meta-container .company-name,
            .quote-meta-container .meta-label,
            .quote-meta-container .meta-value {
                color: white !important;
            }

            .quote-number {
                font-size: 22pt !important;
                font-weight: 700 !important;
            }

            .company-name {
                font-size: 13pt !important;
            }

            /* Quote info cards - two column layout */
            .quote-info {
                display: flex !important;
                justify-content: space-between !important;
                gap: 15px !important;
            }

            .info-card {
                background: white !important;
                border: 1px solid #e5e7eb !important;
                padding: 12px !important;
                border-radius: 6px !important;
                flex: 1 !important;
            }

            .info-card h6 {
                color: #667eea !important;
                font-size: 9pt !important;
                font-weight: 700 !important;
                text-transform: uppercase !important;
                border-bottom: 2px solid #667eea !important;
                padding-bottom: 4px !important;
                margin-bottom: 8px !important;
            }

            .info-card p {
                font-size: 9pt !important;
                line-height: 1.5 !important;
                margin: 0 !important;
            }

            /* Quote items table */
            .quote-items table {
                width: 100% !important;
                border-collapse: collapse !important;
                margin: 15px 0 !important;
                font-size: 9.5pt !important;
            }

            .quote-items thead {
                background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%) !important;
            }

            .quote-items thead th {
                padding: 8px 10px !important;
                font-weight: 700 !important;
                text-transform: uppercase !important;
                font-size: 8.5pt !important;
                border-bottom: 2px solid #667eea !important;
                color: #374151 !important;
            }

            .quote-items tbody td {
                padding: 8px 10px !important;
                border-bottom: 1px solid #e5e7eb !important;
                vertical-align: top !important;
            }

            .quote-items tbody tr:last-child td {
                border-bottom: none !important;
            }

            /* Quote summary - cute totals box */
            .quote-summary {
                background: #f9fafb !important;
                border: 2px solid #667eea !important;
                border-radius: 8px !important;
                padding: 12px 15px !important;
                margin: 15px 0 !important;
                max-width: 50% !important;
                margin-left: auto !important;
            }

            .summary-row {
                display: flex !important;
                justify-content: space-between !important;
                padding: 5px 0 !important;
                border-bottom: 1px solid #e5e7eb !important;
                font-size: 10pt !important;
            }

            .summary-row:last-child {
                border-bottom: none !important;
                border-top: 2px solid #667eea !important;
                padding-top: 10px !important;
                margin-top: 8px !important;
                font-weight: 700 !important;
                font-size: 12pt !important;
                color: #667eea !important;
            }

            /* Terms section */
            .terms-section {
                margin-top: 15px !important;
                padding: 12px !important;
                background: #fffbeb !important;
                border-left: 4px solid #f59e0b !important;
                border-radius: 4px !important;
                font-size: 9pt !important;
            }

            .terms-section h5 {
                color: #f59e0b !important;
                font-size: 10pt !important;
                margin-bottom: 8px !important;
            }

            /* Timeline/Status history */
            .timeline {
                margin-top: 15px !important;
                font-size: 9pt !important;
            }

            .timeline-item {
                border-left: 3px solid #667eea !important;
                padding-left: 12px !important;
                margin-bottom: 10px !important;
            }

            /* Remove shadows and transitions */
            * {
                box-shadow: none !important;
                transition: none !important;
                animation: none !important;
            }

            /* Status badges */
            .status-badge {
                padding: 3px 10px !important;
                border-radius: 12px !important;
                font-size: 8pt !important;
                font-weight: 600 !important;
            }

            /* Prevent page breaks */
            .quote-meta-container,
            .quote-summary,
            .info-card {
                page-break-inside: avoid !important;
            }
        }

    </style>

<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="viewquote-hero-content">
            <h1 class="viewquote-hero-title">
                <i class="bi bi-file-text me-3"></i>Quote Details
            </h1>
            <p class="viewquote-hero-subtitle">
                View complete quote information and status
            </p>
            <span class="role-indicator"><?= ucfirst(str_replace('_', ' ', $_SESSION['user']['role'])) ?></span>
        </div>
    </div>
</header>

<div class="container py-5 overlap-cards">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="quotes.php">My Quotes</a></li>
            <li class="breadcrumb-item active">Quote #<?= htmlspecialchars($quote['quote_number']) ?></li>
        </ol>
    </nav>

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

    <!-- Check if quote is expired -->
    <?php if ($quote['valid_until'] && strtotime($quote['valid_until']) < time()): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Quote Expired:</strong> This quote expired on <?= date('d M Y', strtotime($quote['valid_until'])) ?>
        </div>
    <?php endif; ?>

    <!-- Quote Document -->
    <div class="quote-container">
        <div class="quote-watermark">QUOTE</div>
        
        <!-- Quote Header -->
        <div class="quote-header">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="company-logo">
                        <?= strtoupper(substr($business_details['company_name'], 0, 1)) ?>
                    </div>
                    <h2 class="company-name"><?= htmlspecialchars($business_details['company_name']) ?></h2>
                    <div class="company-details">
                        <?php if ($business_details['company_address']): ?>
                            <i class="bi bi-geo-alt me-2"></i><?= htmlspecialchars($business_details['company_address']) ?><br>
                        <?php endif; ?>
                        <?php if ($business_details['company_phone']): ?>
                            <i class="bi bi-phone me-2"></i><?= htmlspecialchars($business_details['company_phone']) ?><br>
                        <?php endif; ?>
                        <?php if ($business_details['company_email']): ?>
                            <i class="bi bi-envelope me-2"></i><?= htmlspecialchars($business_details['company_email']) ?><br>
                        <?php endif; ?>
                        <?php if ($business_details['company_website']): ?>
                            <i class="bi bi-globe me-2"></i><?= htmlspecialchars($business_details['company_website']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="quote-meta-container">
                        <div class="quote-number"><?= htmlspecialchars($quote['quote_number']) ?></div>
                        <div class="quote-title"><?= htmlspecialchars($quote['title']) ?></div>
                        <div class="meta-row">
                            <span class="meta-label">Date:</span>
                            <span class="meta-value"><?= date('d M Y', strtotime($quote['created_at'])) ?></span>
                        </div>
                        <?php if ($quote['valid_until']): ?>
                            <div class="meta-row">
                                <span class="meta-label">Valid Until:</span>
                                <span class="meta-value"><?= date('d M Y', strtotime($quote['valid_until'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="meta-row">
                            <span class="meta-label">Status:</span>
                            <span class="status-badge status-<?= $quote['status'] ?>"><?= strtoupper($quote['status']) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quote Information -->
        <div class="quote-info">
            <div class="info-card">
                <h6><i class="bi bi-building"></i>Quote For</h6>
                <p><strong><?= htmlspecialchars($quote['company_name']) ?></strong></p>
                <?php if ($quote['company_address']): ?>
                    <p><i class="bi bi-geo-alt me-2"></i><?= htmlspecialchars($quote['company_address']) ?></p>
                <?php endif; ?>
                <?php if ($quote['company_phone']): ?>
                    <p><i class="bi bi-phone me-2"></i><?= htmlspecialchars($quote['company_phone']) ?></p>
                <?php endif; ?>
            </div>
            <div class="info-card">
                <h6><i class="bi bi-info-circle"></i>Quote Details</h6>
                <p><strong>Currency:</strong> <?= $currency_symbol ?> <?= $quote_currency ?> (<?= $currency_name ?>)</p>
                <p><strong>Staff:</strong> <?= htmlspecialchars($quote['staff_name']) ?></p>
                <?php if ($quote['staff_email']): ?>
                    <p><strong>Email:</strong> <?= htmlspecialchars($quote['staff_email']) ?></p>
                <?php endif; ?>
                <p><strong>Created:</strong> <?= date('d M Y', strtotime($quote['created_at'])) ?></p>
            </div>
        </div>

        <!-- Quote Description -->
        <?php if ($quote['description']): ?>
            <div class="quote-description">
                <h6><i class="bi bi-file-text"></i>Project Description</h6>
                <p><?= nl2br(htmlspecialchars($quote['description'])) ?></p>
            </div>
        <?php endif; ?>

        <!-- Quote Items -->
        <div class="quote-items">
            <div class="items-header">
                <h5><i class="bi bi-list-ul"></i>Itemized Quote</h5>
            </div>
            <div class="currency-badge">
                <i class="bi bi-currency-exchange"></i>
                All prices in <?= $currency_symbol ?> <?= $quote_currency ?>
            </div>
            <div class="table-responsive">
                <table class="table items-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Description</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th>Setup Fee</th>
                            <th>Billing</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quote_items as $item): ?>
                            <tr>
                                <td>
                                    <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                                </td>
                                <td>
                                    <?php if ($item['description']): ?>
                                        <div class="item-description"><?= htmlspecialchars($item['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="quantity-badge"><?= $item['quantity'] ?></span></td>
                                <td class="price-cell"><?= $currency_symbol ?><?= number_format($item['unit_price'], 2) ?></td>
                                <td class="price-cell"><?= $currency_symbol ?><?= number_format($item['setup_fee'], 2) ?></td>
                                <td><span class="billing-badge"><?= ucfirst(str_replace('_', ' ', $item['billing_cycle'])) ?></span></td>
                                <td class="text-end price-cell"><?= $currency_symbol ?><?= number_format($item['line_total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Quote Summary -->
        <div class="quote-summary">
            <div class="summary-title">Quote Summary</div>
            <div class="summary-row">
                <span class="summary-label">Subtotal:</span>
                <span class="summary-value"><?= $currency_symbol ?><?= number_format($quote['subtotal'], 2) ?></span>
            </div>
            <?php if ($quote['vat_enabled'] && $quote['tax_amount'] > 0): ?>
                <div class="summary-row">
                    <span class="summary-label">VAT (<?= number_format($quote['vat_rate'] * 100, 1) ?>%):</span>
                    <span class="summary-value"><?= $currency_symbol ?><?= number_format($quote['tax_amount'], 2) ?></span>
                </div>
            <?php endif; ?>
            <div class="summary-row total">
                <span>Total Amount:</span>
                <span><?= $currency_symbol ?><?= number_format($quote['total_amount'], 2) ?></span>
            </div>
        </div>

        <!-- Terms & Conditions -->
        <?php if ($quote['terms_conditions']): ?>
            <div class="terms-section">
                <h5><i class="bi bi-shield-check"></i>Terms & Conditions</h5>
                <p><?= nl2br(htmlspecialchars($quote['terms_conditions'])) ?></p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Actions Section -->
    <div class="actions-section">
        <h5><i class="bi bi-gear"></i>Actions</h5>
        <div class="d-flex gap-2 flex-wrap">
            <a href="quotes.php" class="btn btn-outline-primary">
                <i class="bi bi-arrow-left me-2"></i>Back to Quotes
            </a>
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer me-2"></i>Print Quote
            </button>
            <?php if ($quote['status'] === 'accepted'): ?>
                <a href="create-order.php?from_quote=<?= $quote['id'] ?>" class="btn btn-outline-primary">
                    <i class="bi bi-cart-plus me-2"></i>Convert to Order
                </a>
            <?php endif; ?>
            <a href="/members/raise-ticket.php?quote_id=<?= $quote['id'] ?>&subject=<?= urlencode('Quote Support - #' . $quote['quote_number']) ?>" class="btn btn-outline-primary">
                <i class="bi bi-headset me-2"></i>Get Support
            </a>
        </div>
    </div>

    <!-- Status History -->
    <?php if (!empty($status_history)): ?>
        <div class="actions-section">
            <h5><i class="bi bi-clock-history"></i>Status History</h5>
            <div class="timeline">
                <?php foreach ($status_history as $history): ?>
                    <div class="timeline-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong>
                                    <?php if ($history['status_from']): ?>
                                        <?= ucfirst(str_replace('_', ' ', $history['status_from'])) ?> → 
                                    <?php endif; ?>
                                    <?= ucfirst(str_replace('_', ' ', $history['status_to'])) ?>
                                </strong>
                                <p class="text-muted mb-0">
                                    by <?= htmlspecialchars($history['changed_by_name']) ?> on <?= date('d M Y \a\t H:i', strtotime($history['created_at'])) ?>
                                </p>
                                <?php if ($history['notes']): ?>
                                    <p class="mt-2 mb-0"><?= nl2br(htmlspecialchars($history['notes'])) ?></p>
                                <?php endif; ?>
                            </div>
                            <span class="status-badge status-<?= $history['status_to'] ?>">
                                <?= strtoupper($history['status_to']) ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Auto-expire check
document.addEventListener('DOMContentLoaded', function() {
    const validUntil = '<?= $quote['valid_until'] ?>';
    const currentStatus = '<?= $quote['status'] ?>';
    
    console.log('Quote view loaded for quote:', '<?= $quote["quote_number"] ?>');
});
</script>


<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
