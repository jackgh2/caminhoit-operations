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

// Handle quote status update
if (isset($_POST['update_status'])) {
    $new_status = $_POST['new_status'];
    $notes = trim($_POST['status_notes'] ?? '');

    try {
        // Get current status
        $stmt = $pdo->prepare("SELECT status FROM quotes WHERE id = ?");
        $stmt->execute([$quote_id]);
        $current_status = $stmt->fetchColumn();

        if ($current_status !== false && $current_status !== $new_status) {
            // Update quote status
            $stmt = $pdo->prepare("UPDATE quotes SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $quote_id]);

            // Log status change
            $stmt = $pdo->prepare("INSERT INTO quote_status_history (quote_id, status_from, status_to, changed_by, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$quote_id, $current_status, $new_status, $_SESSION['user']['id'], $notes]);

            $success = "Quote status updated successfully from " . ucfirst(str_replace('_', ' ', $current_status)) . " to " . ucfirst(str_replace('_', ' ', $new_status)) . "!";
            
            // Refresh the page to show updated status
            header("Location: view-quote.php?id=$quote_id&success=" . urlencode($success));
            exit;
        }
    } catch (PDOException $e) {
        $error = "Error updating quote status: " . $e->getMessage();
    }
}

// Get quote details
$stmt = $pdo->prepare("SELECT q.*, c.name as company_name, c.phone as company_phone, c.address as company_address,
    c.preferred_currency,
    u.username as staff_name, u.email as staff_email
    FROM quotes q
    JOIN companies c ON q.company_id = c.id
    JOIN users u ON q.staff_id = u.id
    WHERE q.id = ?");
$stmt->execute([$quote_id]);
$quote = $stmt->fetch();

if (!$quote) {
    header('Location: quotes.php?error=quote_not_found');
    exit;
}

// Get quote items
$stmt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id ASC");
$stmt->execute([$quote_id]);
$quote_items = $stmt->fetchAll();

// Get status history
$stmt = $pdo->prepare("SELECT qsh.*, u.username as changed_by_name
    FROM quote_status_history qsh
    JOIN users u ON qsh.changed_by = u.id
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

// Define possible quote statuses
$quote_statuses = [
    'draft' => 'Draft',
    'sent' => 'Sent',
    'accepted' => 'Accepted',
    'rejected' => 'Rejected',
    'expired' => 'Expired'
];

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
    
    // Debug: Log what we found
    error_log("Business config loaded: " . print_r($business_info, true));
    
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

// Debug: Log final business details
error_log("Final business details: " . print_r($business_details, true));

$page_title = "Quote #" . $quote['quote_number'] . " | " . $business_details['company_name'];
?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

    <style>
        :root {
            --primary-color: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary-color: #64748b;
            --success-color: #059669;
            --warning-color: #d97706;
            --danger-color: #dc2626;
            --info-color: #0891b2;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
        }

        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
        }

        body {
            background-color: #f8fafc;
            color: var(--gray-800);
            font-size: 14px;
            line-height: 1.6;
            padding-top: 80px;
        }

        .navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%) !important;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06) !important;
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
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--gray-200);
        }

        .quote-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            margin-bottom: 2rem;
            border: 1px solid var(--gray-200);
            position: relative;
            min-height: 800px; /* Ensure minimum height */
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

        .config-debug {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            font-family: monospace;
            font-size: 0.75rem;
            color: #92400e;
        }

        /* Print styles */
        @media print {
            body { 
                padding-top: 0; 
                background: white !important;
                font-size: 12px;
            }
            .navbar, .btn, .alert, .actions-section, .timeline, .config-debug { 
                display: none !important; 
            }
            .main-container { 
                margin: 0; 
                max-width: none; 
                padding: 0;
            }
            .quote-container { 
                box-shadow: none !important; 
                border: 2px solid #000 !important;
                border-radius: 0 !important;
                page-break-inside: avoid;
            }
            .page-header {
                display: none !important;
            }
            .breadcrumb {
                display: none !important;
            }
            .quote-header {
                background: white !important;
                page-break-inside: avoid;
            }
            .quote-summary {
                background: #f8fafc !important;
                page-break-inside: avoid;
            }
            .items-table {
                page-break-inside: avoid;
            }
            .quote-watermark {
                display: none;
            }
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
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="main-container">
    <!-- Debug Config (temporary) -->
    <div class="config-debug">
        <strong>Config Debug:</strong><br>
        Company Name: <?= htmlspecialchars($business_details['company_name']) ?><br>
        Address: <?= htmlspecialchars($business_details['company_address']) ?><br>
        Phone: <?= htmlspecialchars($business_details['company_phone']) ?><br>
        Email: <?= htmlspecialchars($business_details['company_email']) ?><br>
        Website: <?= htmlspecialchars($business_details['company_website']) ?>
    </div>

    <!-- Page Header -->
    <div class="page-header">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="quotes.php">Quotes</a></li>
                <li class="breadcrumb-item active">Quote #<?= htmlspecialchars($quote['quote_number']) ?></li>
            </ol>
        </nav>
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-file-text me-3"></i>Quote #<?= htmlspecialchars($quote['quote_number']) ?></h1>
                <p class="text-muted mb-0">
                    Created on <?= date('d M Y \a\t H:i', strtotime($quote['created_at'])) ?> by <?= htmlspecialchars($quote['staff_name']) ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <span class="status-badge status-<?= $quote['status'] ?>">
                    <?= strtoupper($quote['status']) ?>
                </span>
                
                <div class="dropdown">
                    <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-arrow-repeat me-2"></i>Change Status
                    </button>
                    <ul class="dropdown-menu">
                        <?php foreach ($quote_statuses as $status_key => $status_name): ?>
                            <?php if ($status_key !== $quote['status']): ?>
                                <li>
                                    <a class="dropdown-item" href="#" onclick="updateQuoteStatus('<?= $status_key ?>', '<?= $status_name ?>')">
                                        <?= $status_name ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <button class="btn btn-outline-primary" onclick="printQuote()">
                    <i class="bi bi-printer me-2"></i>Print
                </button>
                <a href="edit-quote.php?id=<?= $quote['id'] ?>" class="btn btn-primary">
                    <i class="bi bi-pencil me-2"></i>Edit
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

        <!-- Internal Notes (not printed) -->
        <?php if ($quote['notes']): ?>
            <div class="notes-section">
                <h6><i class="bi bi-sticky"></i>Internal Notes</h6>
                <p class="notes-text"><?= nl2br(htmlspecialchars($quote['notes'])) ?></p>
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
            <a href="edit-quote.php?id=<?= $quote['id'] ?>" class="btn btn-primary">
                <i class="bi bi-pencil me-2"></i>Edit Quote
            </a>
            <button class="btn btn-outline-primary" onclick="printQuote()">
                <i class="bi bi-printer me-2"></i>Print Quote
            </button>
            <button class="btn btn-outline-primary" onclick="emailQuote()">
                <i class="bi bi-envelope me-2"></i>Email Quote
            </button>
            <?php if ($quote['status'] === 'accepted'): ?>
                <a href="create-order.php?from_quote=<?= $quote['id'] ?>" class="btn btn-outline-primary">
                    <i class="bi bi-cart-plus me-2"></i>Convert to Order
                </a>
            <?php endif; ?>
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

<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Quote Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="new_status" id="new_status">
                
                <div class="mb-3">
                    <label class="form-label">New Status</label>
                    <input type="text" class="form-control" id="status_display" readonly>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Notes (Optional)</label>
                    <textarea name="status_notes" class="form-control" rows="3" placeholder="Add notes about this status change..."></textarea>
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
function updateQuoteStatus(status, statusName) {
    document.getElementById('new_status').value = status;
    document.getElementById('status_display').value = statusName;
    
    const modal = new bootstrap.Modal(document.getElementById('statusModal'));
    modal.show();
}

function printQuote() {
    window.print();
}

function emailQuote() {
    // This would typically integrate with your email system
    alert('Email quote functionality would be implemented here');
}

// Auto-expire check
document.addEventListener('DOMContentLoaded', function() {
    const validUntil = '<?= $quote['valid_until'] ?>';
    const currentStatus = '<?= $quote['status'] ?>';
    
    if (validUntil && currentStatus === 'sent') {
        const expiryDate = new Date(validUntil);
        const now = new Date();
        
        if (now > expiryDate) {
            const expiredBanner = document.createElement('div');
            expiredBanner.className = 'alert alert-warning';
            expiredBanner.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i><strong>Quote Expired:</strong> This quote expired on ' + expiryDate.toDateString();
            
            const container = document.querySelector('.main-container');
            container.insertBefore(expiredBanner, container.firstChild.nextSibling);
        }
    }
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>