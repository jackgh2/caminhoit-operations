<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

// ✅ Access control (Administrator only)
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'administrator') {
    header('Location: /login.php');
    exit;
}

// ✅ Handle Logo Upload
if (isset($_POST['upload_logo'])) {
    $company_id = (int)$_POST['company_id'];
    
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        if (!in_array($_FILES['logo']['type'], $allowed_types)) {
            $error = "Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.";
        } elseif ($_FILES['logo']['size'] > $max_size) {
            $error = "File too large. Maximum size is 2MB.";
        } else {
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/company-logos/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $new_filename = 'company_' . $company_id . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
                $logo_url = '/uploads/company-logos/' . $new_filename;
                
                // Update database
                $stmt = $pdo->prepare("UPDATE companies SET logo_url = ? WHERE id = ?");
                $stmt->execute([$logo_url, $company_id]);
                
                header('Location: manage-companies.php?success=logo_updated');
                exit;
            } else {
                $error = "Error uploading file.";
            }
        }
    }
}

// ✅ Handle Add Company Form
if (isset($_POST['add_company'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['contact_email']);
    $address = trim($_POST['address']);
    $phone = trim($_POST['phone'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $industry = trim($_POST['industry'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $preferred_currency = trim($_POST['preferred_currency'] ?? '');
    $currency_override = isset($_POST['currency_override']) ? 1 : 0;

    try {
        $stmt = $pdo->prepare("INSERT INTO companies (name, contact_email, address, phone, website, industry, notes, preferred_currency, currency_override, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $email, $address, $phone, $website, $industry, $notes, $preferred_currency, $currency_override, $_SESSION['user']['id']]);
        header('Location: manage-companies.php?success=added');
        exit;
    } catch (PDOException $e) {
        $error = "Error adding company: " . $e->getMessage();
    }
}

// ✅ Handle Edit Company Form
if (isset($_POST['edit_company'])) {
    $company_id = (int)$_POST['company_id'];
    $name = trim($_POST['name']);
    $email = trim($_POST['contact_email']);
    $address = trim($_POST['address']);
    $phone = trim($_POST['phone'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $industry = trim($_POST['industry'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $preferred_currency = trim($_POST['preferred_currency'] ?? '');
    $currency_override = isset($_POST['currency_override']) ? 1 : 0;

    try {
        $stmt = $pdo->prepare("UPDATE companies SET name = ?, contact_email = ?, address = ?, phone = ?, website = ?, industry = ?, notes = ?, preferred_currency = ?, currency_override = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$name, $email, $address, $phone, $website, $industry, $notes, $preferred_currency, $currency_override, $company_id]);
        header('Location: manage-companies.php?success=updated');
        exit;
    } catch (PDOException $e) {
        $error = "Error updating company: " . $e->getMessage();
    }
}

// ✅ Handle Toggle Active/Inactive
if (isset($_GET['toggle'])) {
    $company_id = (int)$_GET['toggle'];
    
    try {
        $stmt = $pdo->prepare("SELECT is_active FROM companies WHERE id = ?");
        $stmt->execute([$company_id]);
        $current_status = $stmt->fetchColumn();

        if ($current_status !== false) {
            $new_status = $current_status ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE companies SET is_active = ?, deactivated_at = IF(? = 0, NOW(), NULL), deactivated_by = IF(? = 0, ?, NULL) WHERE id = ?");
            $stmt->execute([$new_status, $new_status, $new_status, $_SESSION['user']['id'], $company_id]);
        }
        
        header('Location: manage-companies.php?success=status_changed');
        exit;
    } catch (PDOException $e) {
        $error = "Error changing status: " . $e->getMessage();
    }
}

// ✅ Handle search and filters
$search = $_GET['search'] ?? '';
$show_inactive = isset($_GET['show_inactive']);
$show_inactive_only = isset($_GET['show_inactive_only']);
$filter_industry = $_GET['filter_industry'] ?? '';
$filter_user_count = $_GET['filter_user_count'] ?? '';

$where_conditions = [];
$params = [];

// Search conditions
if (!empty($search)) {
    $where_conditions[] = "(c.name LIKE ? OR c.contact_email LIKE ? OR c.address LIKE ? OR c.phone LIKE ? OR c.website LIKE ? OR c.industry LIKE ? OR c.notes LIKE ?)";
    $search_param = '%' . $search . '%';
    $params = array_merge($params, array_fill(0, 7, $search_param));
}

// Active/Inactive filter
if ($show_inactive_only) {
    $where_conditions[] = "c.is_active = 0";
} elseif (!$show_inactive) {
    $where_conditions[] = "c.is_active = 1";
}

// Industry filter
if (!empty($filter_industry)) {
    $where_conditions[] = "c.industry = ?";
    $params[] = $filter_industry;
}

// User count filter
if (!empty($filter_user_count)) {
    switch ($filter_user_count) {
        case 'none':
            $where_conditions[] = "user_count = 0";
            break;
        case 'low':
            $where_conditions[] = "user_count BETWEEN 1 AND 5";
            break;
        case 'medium':
            $where_conditions[] = "user_count BETWEEN 6 AND 20";
            break;
        case 'high':
            $where_conditions[] = "user_count > 20";
            break;
    }
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(' AND ', $where_conditions);
}

// ✅ Fetch companies with enhanced data
$stmt = $pdo->prepare("SELECT c.*, 
    (SELECT COUNT(*) FROM company_users cu WHERE cu.company_id = c.id) AS user_count,
    (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id) AS primary_user_count,
    u1.username as created_by_name,
    u2.username as deactivated_by_name
    FROM companies c
    LEFT JOIN users u1 ON c.created_by = u1.id
    LEFT JOIN users u2 ON c.deactivated_by = u2.id
    $where_clause
    ORDER BY c.created_at DESC");
$stmt->execute($params);
$companies = $stmt->fetchAll();

// Get stats
$stmt = $pdo->query("SELECT 
    COUNT(*) as total_companies,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_companies,
    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_companies,
    COUNT(DISTINCT industry) as total_industries
    FROM companies");
$stats = $stmt->fetch();

// Get industries for filter
$stmt = $pdo->query("SELECT DISTINCT industry FROM companies WHERE industry IS NOT NULL AND industry != '' ORDER BY industry");
$industries = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get supported currencies
$supportedCurrencies = [];
$defaultCurrency = 'GBP';
if (class_exists('ConfigManager')) {
    $supportedCurrencies = ConfigManager::getSupportedCurrencies();
    $defaultCurrency = ConfigManager::get('business.default_currency', 'GBP');
}

$page_title = "Manage Companies | CaminhoIT";
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php';
?>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="dashboard-hero-content">
            <h1 class="dashboard-hero-title">
                <i class="bi bi-building me-2"></i>
                Manage Companies
            </h1>
            <p class="dashboard-hero-subtitle">
                Manage client companies and their accounts
            </p>
            <div class="dashboard-hero-actions">
                <button class="btn c-btn-primary" data-bs-toggle="modal" data-bs-target="#createCompanyModal">
                    <i class="bi bi-plus-circle me-1"></i>
                    Add Company
                </button>
            </div>
        </div>
    </div>
</header>

    <style>
        :root {
            --primary-color: #4F46E5;
            --primary-hover: #3F37C9;
            --success-color: #10B981;
            --warning-color: #F59E0B;
            --danger-color: #EF4444;
            --info-color: #06B6D4;
            --light-gray: #F8FAFC;
            --border-color: #E2E8F0;
            --text-muted: #64748B;
        }

        body {
            background-color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding-top: 80px;
        }

        /* FORCE NAVBAR BLUE STYLING */
        .navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15) !important;
            padding: 12px 0 !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            z-index: 1030 !important;
        }

        .navbar .navbar-brand,
        .navbar .nav-link,
        .navbar .navbar-text {
            color: white !important;
        }

        .navbar .nav-link:hover {
            color: #e0e7ff !important;
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

        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }

        .page-header .subtitle {
            color: var(--text-muted);
            margin-top: 0.5rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            transition: all 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .stat-card .icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-bottom: 1rem;
        }

        .stat-card .icon.primary { background: var(--primary-color); }
        .stat-card .icon.success { background: var(--success-color); }
        .stat-card .icon.warning { background: var(--warning-color); }
        .stat-card .icon.danger { background: var(--danger-color); }
        .stat-card .icon.info { background: var(--info-color); }

        .stat-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }

        .stat-card .label {
            color: var(--text-muted);
            font-size: 0.875rem;
            font-weight: 500;
        }

        .stat-card.clickable {
            cursor: pointer;
        }

        .stat-card.clickable:hover {
            background: #f8fafc;
        }

        .filters-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .filters-row {
            display: flex;
            gap: 1rem;
            align-items: end;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-group label {
            font-weight: 600;
            color: #374151;
            font-size: 0.875rem;
        }

        .search-input {
            padding: 0.75rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.875rem;
            transition: all 0.2s;
            min-width: 250px;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .filter-select {
            padding: 0.75rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.875rem;
            transition: all 0.2s;
            min-width: 150px;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .filter-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem;
            background: #f8fafc;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .filter-checkbox input[type="checkbox"] {
            width: 1.25rem;
            height: 1.25rem;
            accent-color: var(--primary-color);
        }

        .filter-count {
            background: #e5e7eb;
            color: #374151;
            padding: 0.125rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .filter-count.active {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #6b7280;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-secondary:hover {
            background: #4b5563;
            transform: translateY(-1px);
        }

        .companies-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .companies-header {
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .companies-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }

        .companies-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
        }

        .company-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.2s;
            position: relative;
        }

        .company-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .company-card.inactive {
            background: #f9fafb;
            border-color: #d1d5db;
            opacity: 0.8;
        }

        .company-card.inactive::before {
            content: "Inactive";
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: #6b7280;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .company-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .company-avatar {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            font-weight: 600;
            background: linear-gradient(135deg, #10B981, #059669);
            position: relative;
            overflow: hidden;
        }

        .company-avatar.inactive {
            background: linear-gradient(135deg, #9CA3AF, #6B7280);
        }

        .company-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 12px;
        }

        .company-info h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }

        .company-info .company-email {
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        .company-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .company-detail {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
        }

        .company-detail .icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
        }

        .company-detail .value {
            color: #1f2937;
            font-weight: 500;
        }

        .industry-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            color: white;
            background: var(--info-color);
        }

        .currency-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            color: white;
            background: var(--primary-color);
        }

        .currency-badge.default {
            background: #6b7280;
        }

        .user-count-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            color: white;
            background: var(--success-color);
        }

        .user-count-badge.none {
            background: #6b7280;
        }

        .user-count-badge.low {
            background: var(--warning-color);
        }

        .user-count-badge.medium {
            background: var(--success-color);
        }

        .user-count-badge.high {
            background: var(--primary-color);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.inactive {
            background: #f3f4f6;
            color: #374151;
        }

        .company-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s;
            border: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            color: white;
        }

        .btn-info {
            background: var(--info-color);
            color: white;
        }

        .btn-info:hover {
            background: #0891b2;
            color: white;
        }

        .modal-content {
            border: none;
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            border-bottom: 1px solid var(--border-color);
            padding: 1.5rem;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            border-top: 1px solid var(--border-color);
            padding: 1.5rem;
        }

        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }

        .form-control {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .form-select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
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

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .currency-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .currency-section h6 {
            color: #374151;
            font-weight: 600;
            margin-bottom: 0.75rem;
            font-size: 0.875rem;
        }

        @media (max-width: 768px) {
            .filters-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .companies-grid {
                grid-template-columns: 1fr;
            }
            
            .company-details {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Dark Mode Styles */
        :root.dark .main-container {
            background: #0f172a !important;
        }

        /* Page Header - Dark Mode */
        :root.dark .page-header {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .page-header h1 {
            color: #f1f5f9 !important;
        }

        :root.dark .page-header .subtitle,
        :root.dark .page-header p {
            color: #94a3b8 !important;
        }

        /* Stat Cards */
        :root.dark .stat-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .stat-card.clickable:hover {
            background: #1e293b !important;
        }

        :root.dark .stat-card .icon {
            color: #a78bfa !important;
        }

        :root.dark .stat-card .value {
            color: #f1f5f9 !important;
        }

        :root.dark .stat-card .label {
            color: #94a3b8 !important;
        }

        /* Filters Section */
        :root.dark .filters-section {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .filter-group label {
            color: #cbd5e1 !important;
        }

        :root.dark .search-input,
        :root.dark .filter-select {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .search-input:focus,
        :root.dark .filter-select:focus {
            background: #1e293b !important;
            border-color: #8b5cf6 !important;
        }

        :root.dark .filter-checkbox {
            background: #0f172a !important;
            color: #cbd5e1 !important;
        }

        :root.dark .filter-count {
            background: #334155 !important;
            color: #cbd5e1 !important;
        }

        :root.dark .filter-count.active {
            background: #8b5cf6 !important;
            color: white !important;
        }

        /* Companies Container */
        :root.dark .companies-container {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .companies-header {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .companies-header h2 {
            color: #f1f5f9 !important;
        }

        :root.dark .companies-header p {
            color: #94a3b8 !important;
        }

        /* Company Cards */
        :root.dark .company-card {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .company-card:hover {
            background: #0f172a !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4) !important;
        }

        :root.dark .company-card.inactive {
            background: #1e293b !important;
            border-color: #475569 !important;
        }

        :root.dark .company-card.inactive::before {
            background: #475569 !important;
        }

        :root.dark .company-header {
            color: #f1f5f9 !important;
        }

        :root.dark .company-name {
            color: #f1f5f9 !important;
        }

        :root.dark .company-email {
            color: #94a3b8 !important;
        }

        :root.dark .company-avatar {
            background: #334155 !important;
            color: #cbd5e1 !important;
        }

        :root.dark .company-avatar.inactive {
            background: #475569 !important;
        }

        :root.dark .company-detail {
            color: #cbd5e1 !important;
        }

        :root.dark .company-detail strong,
        :root.dark .company-details label,
        :root.dark .company-info-label {
            color: #94a3b8 !important;
        }

        :root.dark .company-info-value,
        :root.dark p {
            color: #cbd5e1 !important;
        }

        :root.dark .status-badge {
            background: #334155 !important;
            color: #cbd5e1 !important;
        }

        :root.dark .status-badge.active {
            background: #10b981 !important;
            color: white !important;
        }

        :root.dark .status-badge.inactive {
            background: #ef4444 !important;
            color: white !important;
        }

        /* Form Elements */
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

        :root.dark .text-muted,
        :root.dark small {
            color: #94a3b8 !important;
        }

        :root.dark .badge {
            background: #334155 !important;
            color: #cbd5e1 !important;
        }

        /* Card & Modal */
        :root.dark .card,
        :root.dark .card-title,
        :root.dark h1, :root.dark h2, :root.dark h3, :root.dark h4, :root.dark h5 {
            color: #f1f5f9 !important;
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

        /* Breadcrumb */
        :root.dark .breadcrumb-enhanced {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .breadcrumb-item a {
            color: #94a3b8 !important;
        }

        :root.dark .breadcrumb-item.active {
            color: #cbd5e1 !important;
        }

        /* Tables - Company Users Table */
        :root.dark .table {
            color: #e2e8f0 !important;
            background: #1e293b !important;
        }

        :root.dark .table thead {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
        }

        :root.dark .table th {
            color: white !important;
            background: transparent !important;
        }

        :root.dark .table td {
            color: #cbd5e1 !important;
            border-color: #334155 !important;
            background: transparent !important;
        }

        :root.dark .table tbody tr {
            background: transparent !important;
        }

        :root.dark .table tbody tr:hover {
            background: #0f172a !important;
        }

        :root.dark .table-responsive {
            background: #1e293b !important;
        }

        /* Currency Settings Section */
        :root.dark .card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .card-header {
            background: #1e293b !important;
            border-bottom-color: #334155 !important;
        }

        :root.dark .card-body {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .form-label {
            color: #cbd5e1 !important;
        }

        :root.dark .currency-section {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .currency-section h6 {
            color: #f1f5f9 !important;
        }

        /* Fix unreadable text in dark mode */
        :root.dark .text-dark {
            color: #e2e8f0 !important;
        }

        :root.dark small,
        :root.dark .small {
            color: #94a3b8 !important;
        }

        :root.dark .company-phone,
        :root.dark .company-date {
            color: #cbd5e1 !important;
        }

        :root.dark .list-group-item {
            background: transparent !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .list-group-item:hover {
            background: #0f172a !important;
        }

        :root.dark .alert {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .alert-success {
            background: #065f46 !important;
            color: #a7f3d0 !important;
        }

        :root.dark .alert-danger {
            background: #7f1d1d !important;
            color: #fca5a5 !important;
        }

        :root.dark .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }
    </style>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <h1><i class="bi bi-buildings me-3"></i>Company Management</h1>
        <p class="subtitle">Manage companies, their users, and business relationships</p>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            Company <?= htmlspecialchars($_GET['success']) ?> successfully!
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary">
                <i class="bi bi-buildings"></i>
            </div>
            <div class="value"><?= number_format($stats['total_companies']) ?></div>
            <div class="label">Total Companies</div>
        </div>

        <div class="stat-card">
            <div class="icon success">
                <i class="bi bi-building-check"></i>
            </div>
            <div class="value"><?= number_format($stats['active_companies']) ?></div>
            <div class="label">Active Companies</div>
        </div>

        <div class="stat-card clickable" onclick="window.location.href='?show_inactive_only=1'">
            <div class="icon warning">
                <i class="bi bi-building-x"></i>
            </div>
            <div class="value"><?= number_format($stats['inactive_companies']) ?></div>
            <div class="label">Inactive Companies</div>
        </div>

        <div class="stat-card">
            <div class="icon info">
                <i class="bi bi-diagram-3"></i>
            </div>
            <div class="value"><?= number_format($stats['total_industries']) ?></div>
            <div class="label">Industries</div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="filters-section">
        <form method="GET" class="filters-form">
            <div class="filters-row">
                <div class="filter-group">
                    <label>Search Companies</label>
                    <input type="text" name="search" class="search-input" placeholder="Search by name, email, address, phone..." value="<?= htmlspecialchars($search) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Filter by Industry</label>
                    <select name="filter_industry" class="filter-select">
                        <option value="">All Industries</option>
                        <?php foreach ($industries as $industry): ?>
                            <option value="<?= htmlspecialchars($industry) ?>" <?= $filter_industry === $industry ? 'selected' : '' ?>>
                                <?= htmlspecialchars($industry) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>User Count</label>
                    <select name="filter_user_count" class="filter-select">
                        <option value="">All Counts</option>
                        <option value="none" <?= $filter_user_count === 'none' ? 'selected' : '' ?>>No Users</option>
                        <option value="low" <?= $filter_user_count === 'low' ? 'selected' : '' ?>>1-5 Users</option>
                        <option value="medium" <?= $filter_user_count === 'medium' ? 'selected' : '' ?>>6-20 Users</option>
                        <option value="high" <?= $filter_user_count === 'high' ? 'selected' : '' ?>>20+ Users</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>&nbsp;</label>
                    <div class="filter-checkbox">
                        <input type="checkbox" name="show_inactive" <?= $show_inactive ? 'checked' : '' ?>>
                        <span>Show Inactive</span>
                        <span class="filter-count <?= $show_inactive ? 'active' : '' ?>"><?= number_format($stats['inactive_companies']) ?></span>
                    </div>
                </div>

                <div class="filter-group">
                    <label>&nbsp;</label>
                    <div class="filter-checkbox">
                        <input type="checkbox" name="show_inactive_only" <?= $show_inactive_only ? 'checked' : '' ?>>
                        <span>Only Inactive</span>
                        <span class="filter-count <?= $show_inactive_only ? 'active' : '' ?>"><?= number_format($stats['inactive_companies']) ?></span>
                    </div>
                </div>

                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i>
                        Apply Filters
                    </button>
                </div>
                
                <?php if (!empty($search) || $show_inactive || $show_inactive_only || !empty($filter_industry) || !empty($filter_user_count)): ?>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <a href="?" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i>
                            Clear All
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Companies Container -->
    <div class="companies-container">
        <div class="companies-header">
            <div>
                <h2>Companies <?= !empty($search) ? '- Search Results' : '' ?></h2>
                <?php if (!empty($search)): ?>
                    <p class="mb-0 text-muted">
                        Found <?= count($companies) ?> compan<?= count($companies) != 1 ? 'ies' : 'y' ?> matching "<?= htmlspecialchars($search) ?>"
                    </p>
                <?php endif; ?>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCompanyModal">
                <i class="bi bi-plus-circle"></i>
                Add Company
            </button>
        </div>

        <?php if (empty($companies)): ?>
            <div class="empty-state">
                <i class="bi bi-building-x"></i>
                <h3>No Companies Found</h3>
                <p><?= !empty($search) ? 'No companies match your search criteria.' : 'No companies found matching the current filters.' ?></p>
            </div>
        <?php else: ?>
            <div class="companies-grid">
                <?php foreach ($companies as $company): ?>
                    <?php
                    $total_users = $company['user_count'] + $company['primary_user_count'];
                    $user_count_class = $total_users == 0 ? 'none' : ($total_users <= 5 ? 'low' : ($total_users <= 20 ? 'medium' : 'high'));
                    
                    // Get company currency info
                    $company_currency = $company['preferred_currency'] ?? null;
                    $currency_override = $company['currency_override'] ?? false;
                    $effective_currency = $currency_override && $company_currency ? $company_currency : $defaultCurrency;
                    $currency_symbol = $supportedCurrencies[$effective_currency]['symbol'] ?? $effective_currency;
                    ?>
                    <div class="company-card <?= $company['is_active'] ? '' : 'inactive' ?>">
                        <div class="company-header">
                            <div class="company-avatar <?= $company['is_active'] ? '' : 'inactive' ?>">
                                <?php if ($company['logo_url']): ?>
                                    <img src="<?= htmlspecialchars($company['logo_url']) ?>" alt="<?= htmlspecialchars($company['name']) ?>">
                                <?php else: ?>
                                    <?= strtoupper(substr($company['name'], 0, 2)) ?>
                                <?php endif; ?>
                            </div>
                            <div class="company-info">
                                <h3><?= htmlspecialchars($company['name']) ?></h3>
                                <div class="company-email"><?= htmlspecialchars($company['contact_email']) ?></div>
                            </div>
                        </div>

                        <div class="company-details">
                            <div class="company-detail">
                                <div class="icon">
                                    <i class="bi bi-people"></i>
                                </div>
                                <div class="value">
                                    <span class="user-count-badge <?= $user_count_class ?>">
                                        <?= $total_users ?> users
                                    </span>
                                </div>
                            </div>

                            <div class="company-detail">
                                <div class="icon">
                                    <i class="bi bi-currency-exchange"></i>
                                </div>
                                <div class="value">
                                    <span class="currency-badge <?= $currency_override ? '' : 'default' ?>">
                                        <?= $currency_symbol ?> <?= $effective_currency ?>
                                        <?= $currency_override ? '' : ' (default)' ?>
                                    </span>
                                </div>
                            </div>

                            <div class="company-detail">
                                <div class="icon">
                                    <i class="bi bi-diagram-3"></i>
                                </div>
                                <div class="value">
                                    <?php if ($company['industry']): ?>
                                        <span class="industry-badge">
                                            <?= htmlspecialchars($company['industry']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">No industry</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="company-detail">
                                <div class="icon">
                                    <i class="bi bi-telephone"></i>
                                </div>
                                <div class="value">
                                    <?= htmlspecialchars($company['phone'] ?: 'No phone') ?>
                                </div>
                            </div>

                            <div class="company-detail">
                                <div class="icon">
                                    <i class="bi bi-globe"></i>
                                </div>
                                <div class="value">
                                    <?php if ($company['website']): ?>
                                        <a href="<?= htmlspecialchars($company['website']) ?>" target="_blank" class="text-decoration-none">
                                            <?= htmlspecialchars($company['website']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">No website</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="company-detail">
                                <div class="icon">
                                    <i class="bi bi-person-badge"></i>
                                </div>
                                <div class="value">
                                    <span class="status-badge <?= $company['is_active'] ? 'active' : 'inactive' ?>">
                                        <?= $company['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </div>
                            </div>

                            <div class="company-detail">
                                <div class="icon">
                                    <i class="bi bi-calendar-plus"></i>
                                </div>
                                <div class="value">
                                    <?= date('M j, Y', strtotime($company['created_at'])) ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($company['address']): ?>
                            <div class="company-detail mb-2">
                                <div class="icon">
                                    <i class="bi bi-geo-alt"></i>
                                </div>
                                <div class="value">
                                    <small class="text-muted"><?= htmlspecialchars($company['address']) ?></small>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="company-actions">
                            <button class="btn btn-sm btn-info" onclick="viewCompanyUsers(<?= $company['id'] ?>)">
                                <i class="bi bi-people"></i>
                                View Users
                            </button>
                            <button class="btn btn-sm btn-secondary" onclick="uploadLogo(<?= $company['id'] ?>)">
                                <i class="bi bi-image"></i>
                                Logo
                            </button>
                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editCompanyModal<?= $company['id'] ?>">
                                <i class="bi bi-pencil"></i>
                                Edit
                            </button>
                            <a href="?toggle=<?= $company['id'] ?><?= http_build_query(array_filter(['search' => $search, 'show_inactive' => $show_inactive, 'show_inactive_only' => $show_inactive_only, 'filter_industry' => $filter_industry, 'filter_user_count' => $filter_user_count]), '', '&') ?>" 
                               class="btn btn-sm <?= $company['is_active'] ? 'btn-danger' : 'btn-success' ?>"
                               onclick="return confirm('Are you sure you want to <?= $company['is_active'] ? 'deactivate' : 'activate' ?> this company?')">
                                <i class="bi <?= $company['is_active'] ? 'bi-building-x' : 'bi-building-check' ?>"></i>
                                <?= $company['is_active'] ? 'Deactivate' : 'Activate' ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Company Modal -->
<div class="modal fade" id="addCompanyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-building-add me-2"></i>
                    Add New Company
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Company Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Contact Email *</label>
                            <input type="email" name="contact_email" class="form-control" required>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Website</label>
                            <input type="url" name="website" class="form-control" placeholder="https://example.com">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Industry</label>
                            <input type="text" name="industry" class="form-control" placeholder="e.g., Technology, Healthcare">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Currency Section -->
                <?php if (!empty($supportedCurrencies)): ?>
                <div class="currency-section">
                    <h6><i class="bi bi-currency-exchange me-2"></i>Currency Settings</h6>
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="form-label">Preferred Currency</label>
                                <select name="preferred_currency" class="form-select">
                                    <option value="">Use System Default (<?= $defaultCurrency ?>)</option>
                                    <?php foreach ($supportedCurrencies as $code => $currency): ?>
                                        <option value="<?= $code ?>">
                                            <?= $currency['symbol'] ?> <?= $currency['name'] ?> (<?= $code ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">&nbsp;</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="currency_override" id="currency_override_add">
                                    <label class="form-check-label" for="currency_override_add">
                                        Override system currency
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <small>If enabled, this company will use their preferred currency for all transactions instead of the system default.</small>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes about this company..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="add_company" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i>
                    Add Company
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Company Modals -->
<?php foreach ($companies as $company): ?>
<div class="modal fade" id="editCompanyModal<?= $company['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <input type="hidden" name="company_id" value="<?= $company['id'] ?>">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-building-gear me-2"></i>
                    Edit Company: <?= htmlspecialchars($company['name']) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Company Name *</label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($company['name']) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Contact Email *</label>
                            <input type="email" name="contact_email" class="form-control" value="<?= htmlspecialchars($company['contact_email']) ?>" required>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($company['phone'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Website</label>
                            <input type="url" name="website" class="form-control" value="<?= htmlspecialchars($company['website'] ?? '') ?>" placeholder="https://example.com">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Industry</label>
                            <input type="text" name="industry" class="form-control" value="<?= htmlspecialchars($company['industry'] ?? '') ?>" placeholder="e.g., Technology, Healthcare">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($company['address'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Currency Section -->
                <?php if (!empty($supportedCurrencies)): ?>
                <div class="currency-section">
                    <h6><i class="bi bi-currency-exchange me-2"></i>Currency Settings</h6>
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="form-label">Preferred Currency</label>
                                <select name="preferred_currency" class="form-select">
                                    <option value="">Use System Default (<?= $defaultCurrency ?>)</option>
                                    <?php foreach ($supportedCurrencies as $code => $currency): ?>
                                        <option value="<?= $code ?>" <?= ($company['preferred_currency'] === $code) ? 'selected' : '' ?>>
                                            <?= $currency['symbol'] ?> <?= $currency['name'] ?> (<?= $code ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">&nbsp;</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="currency_override" id="currency_override_edit_<?= $company['id'] ?>" <?= $company['currency_override'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="currency_override_edit_<?= $company['id'] ?>">
                                        Override system currency
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <small>
                            Current effective currency: <strong><?= $effective_currency ?></strong>
                            <?php if ($company['currency_override'] && $company['preferred_currency']): ?>
                                (using company override)
                            <?php else: ?>
                                (using system default)
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes about this company..."><?= htmlspecialchars($company['notes'] ?? '') ?></textarea>
                </div>
                
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Company Info:</strong> Created on <?= date('M j, Y', strtotime($company['created_at'])) ?><?= $company['created_by_name'] ? ' by ' . htmlspecialchars($company['created_by_name']) : '' ?>.
                    <?php if (!$company['is_active'] && $company['deactivated_at']): ?>
                        <br>Deactivated on <?= date('M j, Y', strtotime($company['deactivated_at'])) ?><?= $company['deactivated_by_name'] ? ' by ' . htmlspecialchars($company['deactivated_by_name']) : '' ?>.
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="edit_company" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- Company Users Modal -->
<div class="modal fade" id="companyUsersModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-people me-2"></i>
                    Company Users
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="companyUsersContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Handle mutual exclusivity of inactive checkboxes
document.querySelector('input[name="show_inactive"]').addEventListener('change', function() {
    if (this.checked) {
        document.querySelector('input[name="show_inactive_only"]').checked = false;
    }
});

document.querySelector('input[name="show_inactive_only"]').addEventListener('change', function() {
    if (this.checked) {
        document.querySelector('input[name="show_inactive"]').checked = false;
    }
});

// View Company Users function
function viewCompanyUsers(companyId) {
    const modal = new bootstrap.Modal(document.getElementById('companyUsersModal'));
    modal.show();
    
    // Load company users via AJAX
    fetch(`/api/company-users.php?company_id=${companyId}`)
        .then(response => response.json())
        .then(data => {
            document.getElementById('companyUsersContent').innerHTML = data.html;
        })
        .catch(error => {
            document.getElementById('companyUsersContent').innerHTML = 
                '<div class="alert alert-danger">Error loading users</div>';
        });
}

// Invite User function
function inviteUser(companyId) {
    const email = prompt('Enter email address to invite:');
    if (email && email.includes('@')) {
        fetch('/api/invite-user.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                company_id: companyId,
                email: email
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Invitation sent successfully!');
                // Refresh company users modal if it's open
                if (document.getElementById('companyUsersModal').classList.contains('show')) {
                    viewCompanyUsers(companyId);
                }
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            alert('Error sending invitation');
        });
    } else {
        alert('Please enter a valid email address');
    }
}

// Upload Logo function
function uploadLogo(companyId) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = function(event) {
        const file = event.target.files[0];
        if (file) {
            const formData = new FormData();
            formData.append('logo', file);
            formData.append('company_id', companyId);
            formData.append('upload_logo', '1');
            
            // Show loading
            const uploadBtn = document.querySelector(`[onclick="uploadLogo(${companyId})"]`);
            const originalText = uploadBtn.innerHTML;
            uploadBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Uploading...';
            uploadBtn.disabled = true;
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                location.reload();
            })
            .catch(error => {
                alert('Error uploading logo');
                uploadBtn.innerHTML = originalText;
                uploadBtn.disabled = false;
            });
        }
    };
    input.click();
}

// Auto-focus on modal inputs
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('shown.bs.modal', function() {
        const firstInput = this.querySelector('input[type="text"]');
        if (firstInput) {
            firstInput.focus();
        }
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>