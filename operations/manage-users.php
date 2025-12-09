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

// ✅ Handle Edit User Form Submission
if (isset($_POST['edit_user'])) {
    $user_id        = (int)$_POST['user_id'];
    $username       = trim($_POST['username']);
    $email          = trim($_POST['email']);
    $role           = trim($_POST['role']);
    $company_id     = !empty($_POST['company_id']) ? (int)$_POST['company_id'] : null;
    $is_active      = isset($_POST['is_active']) ? 1 : 0;
    $multi_companies = $_POST['multi_companies'] ?? [];

    // Validate role against allowed values for users table
    $allowed_user_roles = ['public', 'supported_user', 'account_manager', 'support_consultant', 'accountant', 'administrator'];
    if (!in_array($role, $allowed_user_roles)) {
        die("Invalid role selected: '$role'. Please contact administrator.");
    }

    // Map user roles to company_users roles
    function mapToCompanyRole($userRole) {
        switch ($userRole) {
            case 'supported_user':
                return 'supported_user';
            case 'account_manager':
                return 'account_manager';
            case 'support_consultant':
            case 'accountant':
            case 'administrator':
                return 'account_manager'; // Default to account_manager for staff roles
            default:
                return 'supported_user'; // Default for public users
        }
    }

    try {
        // Start transaction
        $pdo->beginTransaction();

        // Update users table
        $stmt = $pdo->prepare("UPDATE users 
            SET username = ?, email = ?, role = ?, company_id = ?, is_active = ?, 
                deactivated_at = IF(? = 0, NOW(), NULL), 
                deactivated_by = IF(? = 0, ?, NULL) 
            WHERE id = ?");
        
        $stmt->execute([
            $username, $email, $role, $company_id, $is_active,
            $is_active, $is_active, $_SESSION['user']['id'],
            $user_id
        ]);
        
        // Handle multi-company assignments
        $pdo->prepare("DELETE FROM company_users WHERE user_id = ?")->execute([$user_id]);
        
        if (!empty($multi_companies)) {
            $company_role = mapToCompanyRole($role);
            $stmt = $pdo->prepare("INSERT INTO company_users (user_id, company_id, role) VALUES (?, ?, ?)");
            
            foreach ($multi_companies as $company) {
                if (!empty($company)) {
                    $stmt->execute([$user_id, (int)$company, $company_role]);
                }
            }
        }

        // Commit transaction
        $pdo->commit();
        
        header('Location: manage-users.php?success=updated');
        exit;
        
    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollback();
        error_log("Database error: " . $e->getMessage());
        header('Location: manage-users.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// ✅ Handle Toggle Active/Inactive
if (isset($_GET['toggle'])) {
    $user_id = (int)$_GET['toggle'];
    $stmt = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_status = $stmt->fetchColumn();

    if ($current_status !== false) {
        $new_status = $current_status ? 0 : 1;
        $stmt = $pdo->prepare("UPDATE users 
            SET is_active = ?, deactivated_at = IF(? = 0, NOW(), NULL), 
                deactivated_by = IF(? = 0, ?, NULL) 
            WHERE id = ?");
        $stmt->execute([$new_status, $new_status, $new_status, $_SESSION['user']['id'], $user_id]);
    }

    header('Location: manage-users.php?success=status_changed');
    exit;
}

// ✅ Handle search and filters
$search = $_GET['search'] ?? '';
$show_inactive = isset($_GET['show_inactive']);
$show_inactive_only = isset($_GET['show_inactive_only']);
$show_flagged_only = isset($_GET['show_flagged_only']);
$filter_role = $_GET['filter_role'] ?? '';

$where_conditions = [];
$params = [];

// Base search conditions
if (!empty($search)) {
    $where_conditions[] = "(
        u.username LIKE ? OR 
        u.email LIKE ? OR 
        c.name LIKE ? OR
        u.id IN (
            SELECT DISTINCT cu.user_id 
            FROM company_users cu 
            JOIN companies c2 ON cu.company_id = c2.id 
            WHERE c2.name LIKE ?
        )
    )";
    $search_param = '%' . $search . '%';
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

// Active/Inactive filter
if ($show_inactive_only) {
    $where_conditions[] = "u.is_active = 0";
} elseif (!$show_inactive) {
    $where_conditions[] = "u.is_active = 1";
}

// Role filter
if (!empty($filter_role)) {
    $where_conditions[] = "u.role = ?";
    $params[] = $filter_role;
}

// Flagged users filter (Active users with company assignment but public role)
if ($show_flagged_only) {
    $where_conditions[] = "(u.is_active = 1 AND (u.company_id IS NOT NULL OR u.id IN (SELECT user_id FROM company_users)) AND u.role = 'public')";
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(' AND ', $where_conditions);
}

// ✅ Fetch users and companies
$stmt = $pdo->prepare("SELECT u.*, c.name AS company_name,
    CASE WHEN u.deactivated_by IS NOT NULL THEN u2.username ELSE NULL END as deactivated_by_username
    FROM users u 
    LEFT JOIN companies c ON u.company_id = c.id 
    LEFT JOIN users u2 ON u.deactivated_by = u2.id
    $where_clause
    ORDER BY u.created_at DESC");
$stmt->execute($params);
$users = $stmt->fetchAll();

$stmt = $pdo->query("SELECT id, name FROM companies ORDER BY name ASC");
$companies = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// ✅ Enhanced multi-company links with company names for better display
$stmt = $pdo->query("SELECT cu.user_id, cu.company_id, c.name as company_name 
    FROM company_users cu 
    JOIN companies c ON cu.company_id = c.id 
    ORDER BY c.name");
$multi_company_links = [];
$multi_company_names = [];
foreach ($stmt as $row) {
    $multi_company_links[$row['user_id']][] = $row['company_id'];
    $multi_company_names[$row['user_id']][] = $row['company_name'];
}

// Get comprehensive stats including filter counts
$stmt = $pdo->query("SELECT 
    COUNT(*) as total_users,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_users,
    SUM(CASE WHEN role = 'administrator' THEN 1 ELSE 0 END) as admin_users,
    SUM(CASE WHEN last_login IS NOT NULL THEN 1 ELSE 0 END) as users_with_login,
    SUM(CASE WHEN is_active = 1 AND (company_id IS NOT NULL OR id IN (SELECT user_id FROM company_users)) AND role = 'public' THEN 1 ELSE 0 END) as flagged_users
    FROM users");
$stats = $stmt->fetch();

$page_title = "Manage Users | CaminhoIT";

// Helper function to get role color
function getRoleColor($role) {
    switch ($role) {
        case 'administrator':
            return '#EF4444';
        case 'accountant':
            return '#F59E0B';
        case 'support_consultant':
            return '#3B82F6';
        case 'account_manager':
            return '#10B981';
        case 'supported_user':
            return '#06B6D4';
        default:
            return '#6B7280';
    }
}

// Helper function to get provider icon
function getProviderIcon($provider) {
    switch ($provider) {
        case 'google':
            return 'bi-google';
        case 'microsoft':
            return 'bi-microsoft';
        case 'discord':
            return 'bi-discord';
        default:
            return 'bi-person';
    }
}

// Helper function to format role display name
function formatRoleDisplayName($role) {
    switch ($role) {
        case 'supported_user':
            return 'Supported User';
        case 'account_manager':
            return 'Account Manager';
        case 'support_consultant':
            return 'Support Consultant';
        default:
            return ucfirst($role);
    }
}

// Helper function to check if user matches search in multi-company access
function hasMultiCompanyMatch($user_id, $search, $multi_company_names) {
    if (empty($search) || !isset($multi_company_names[$user_id])) {
        return false;
    }
    
    foreach ($multi_company_names[$user_id] as $company_name) {
        if (stripos($company_name, $search) !== false) {
            return true;
        }
    }
    
    return false;
}

// Helper function to check if user is flagged
function isUserFlagged($user, $multi_company_links) {
    return $user['is_active'] && 
           ($user['company_id'] || isset($multi_company_links[$user['id']])) && 
           $user['role'] === 'public';
}

// Helper function to get flag message
function getFlagMessage($user, $multi_company_links) {
    if (!isUserFlagged($user, $multi_company_links)) {
        return null;
    }
    
    $companies = [];
    if ($user['company_name']) {
        $companies[] = $user['company_name'];
    }
    
    if (isset($multi_company_links[$user['id']])) {
        $companies[] = "+" . count($multi_company_links[$user['id']]) . " multi-company";
    }
    
    return "Active user with company access (" . implode(', ', $companies) . ") but has Public role";
}

// Helper function to format deactivation info
function getDeactivationInfo($user) {
    if (!$user['deactivated_at']) {
        return null;
    }
    
    $info = [
        'date' => date('M j, Y g:i A', strtotime($user['deactivated_at'])),
        'by' => $user['deactivated_by_username'] ?? 'System'
    ];
    
    return $info;
}
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="dashboard-hero-content">
            <h1 class="dashboard-hero-title">
                <i class="bi bi-people me-2"></i>
                Manage Users
            </h1>
            <p class="dashboard-hero-subtitle">
                Manage user accounts and permissions
            </p>
            <div class="dashboard-hero-actions">
                <button class="btn c-btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
                    <i class="bi bi-person-plus me-1"></i>
                    Add User
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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

        .users-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .users-header {
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            padding: 1.5rem;
        }

        .users-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }

        .users-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
        }

        .user-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.2s;
            position: relative;
        }

        .user-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .user-card.inactive {
            background: #f9fafb;
            border-color: #d1d5db;
            opacity: 0.8;
        }

        .user-card.inactive::before {
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

        .user-card.search-match {
            border-color: var(--primary-color);
            background: #f8faff;
        }

        .user-card.flagged {
            border-color: var(--danger-color);
            background: #fef2f2;
        }

        .user-card.flagged::after {
            content: "!";
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: var(--danger-color);
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            font-weight: 700;
        }

        .flag-alert {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .flag-alert .icon {
            color: var(--danger-color);
            font-size: 1.125rem;
        }

        .flag-alert .message {
            color: #991b1b;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .deactivation-info {
            background: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .deactivation-info .icon {
            color: var(--warning-color);
            font-size: 1.125rem;
        }

        .deactivation-info .message {
            color: #92400e;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .user-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            font-weight: 600;
            background: linear-gradient(135deg, #4F46E5, #7C3AED);
        }

        .user-avatar.inactive {
            background: linear-gradient(135deg, #9CA3AF, #6B7280);
        }

        .user-info h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }

        .user-info .user-email {
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        .user-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .user-detail {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
        }

        .user-detail .icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
        }

        .user-detail .value {
            color: #1f2937;
            font-weight: 500;
        }

        .role-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .role-badge.flagged {
            background: var(--danger-color);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .provider-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
            background: #f3f4f6;
            color: #374151;
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

        .multi-company-access {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1rem;
        }

        .multi-company-access .title {
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }

        .company-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .company-tag {
            background: #dbeafe;
            color: #1e40af;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .company-tag.search-highlight {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fbbf24;
        }

        .user-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
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

        .form-check-input {
            width: 1.25rem;
            height: 1.25rem;
            margin-top: 0;
            accent-color: var(--primary-color);
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

        @media (max-width: 768px) {
            .filters-row {
                flex-direction: column;
                align-items: stretch;
            }

            .users-grid {
                grid-template-columns: 1fr;
            }

            .user-details {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Dark Mode Styles */
        :root.dark body {
            background: #0f172a !important;
        }

        :root.dark .page-header {
            background: #1e293b !important;
        }

        :root.dark .page-header h1 {
            color: #f1f5f9 !important;
        }

        :root.dark .page-header .subtitle {
            color: #94a3b8 !important;
        }

        :root.dark .stat-card {
            background: #1e293b !important;
        }

        :root.dark .stat-card .value {
            color: #f1f5f9 !important;
        }

        :root.dark .stat-card .label {
            color: #94a3b8 !important;
        }

        :root.dark .filters-section {
            background: #1e293b !important;
        }

        :root.dark .search-box input {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .search-box input::placeholder {
            color: #64748b !important;
        }

        :root.dark .filter-group select {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .user-card {
            background: #1e293b !important;
        }

        :root.dark .user-card h3 {
            color: #f1f5f9 !important;
        }

        :root.dark .user-card p,
        :root.dark .user-card small {
            color: #cbd5e1 !important;
        }

        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark .modal-content {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .modal-header {
            border-bottom-color: #334155 !important;
        }

        :root.dark .modal-footer {
            border-top-color: #334155 !important;
        }

        :root.dark .modal-title {
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
            background: #0f172a !important;
            border-color: #667eea !important;
            color: #e2e8f0 !important;
        }

        :root.dark .alert {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .alert-success {
            background: #065f46 !important;
            border-color: #047857 !important;
            color: #a7f3d0 !important;
        }

        :root.dark .alert-danger {
            background: #7f1d1d !important;
            border-color: #991b1b !important;
            color: #fca5a5 !important;
        }

        :root.dark .badge {
            color: white !important;
        }

        :root.dark .table-responsive {
            background: #1e293b !important;
        }

        :root.dark .table {
            color: #e2e8f0 !important;
        }

        :root.dark .table thead {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
        }

        :root.dark .table th {
            color: white !important;
        }

        :root.dark .table td {
            color: #cbd5e1 !important;
            border-color: #334155 !important;
        }

        :root.dark .table tbody tr:hover {
            background: #0f172a !important;
        }

        :root.dark .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        /* Users Container */
        :root.dark .users-container {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .users-header {
            background: #1e293b !important;
            border-bottom-color: #334155 !important;
        }

        :root.dark .users-header h2 {
            color: #f1f5f9 !important;
        }

        :root.dark .users-grid {
            background: transparent !important;
        }

        /* User Card Borders */
        :root.dark .user-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .user-card.inactive {
            background: #0f172a !important;
            border-color: #475569 !important;
        }

        /* Flag Alert */
        :root.dark .flag-alert {
            background: #7f1d1d !important;
            border-color: #991b1b !important;
        }

        :root.dark .flag-alert .message {
            color: #fca5a5 !important;
        }

        :root.dark .flag-alert .icon {
            color: #fca5a5 !important;
        }

        /* Deactivation Info */
        :root.dark .deactivation-info {
            background: #713f12 !important;
            border-color: #92400e !important;
        }

        :root.dark .deactivation-info .message {
            color: #fcd34d !important;
        }

        :root.dark .deactivation-info .icon {
            color: #fcd34d !important;
        }

        /* User Details */
        :root.dark .user-detail .value {
            color: #e2e8f0 !important;
        }

        :root.dark .user-detail .icon {
            color: #94a3b8 !important;
        }

        /* Provider Badge */
        :root.dark .provider-badge {
            background: #0f172a !important;
            color: #cbd5e1 !important;
            border: 1px solid #334155;
        }

        /* Multi-Company Access */
        :root.dark .multi-company-access {
            background: #0c4a6e !important;
            border-color: #075985 !important;
        }

        :root.dark .multi-company-access .title {
            color: #7dd3fc !important;
        }

        :root.dark .company-tag {
            background: #075985 !important;
            color: #bae6fd !important;
        }

        :root.dark .company-tag.search-highlight {
            background: #713f12 !important;
            color: #fcd34d !important;
            border-color: #92400e !important;
        }

        /* Filter Elements */
        :root.dark .search-input {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .search-input::placeholder {
            color: #64748b !important;
        }

        :root.dark .filter-select {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .filter-checkbox {
            background: #0f172a !important;
            color: #e2e8f0 !important;
        }

        :root.dark .filter-count {
            background: #475569 !important;
            color: #f1f5f9 !important;
        }

        :root.dark .filter-count.active {
            background: #667eea !important;
            color: white !important;
        }

        :root.dark .filter-group label {
            color: #cbd5e1 !important;
        }

        /* Status Badge */
        :root.dark .status-badge {
            color: white !important;
        }

        /* User Email */
        :root.dark .user-email {
            color: #94a3b8 !important;
        }

        /* Empty State */
        :root.dark .empty-state {
            color: #94a3b8 !important;
        }

        :root.dark .empty-state h3 {
            color: #cbd5e1 !important;
        }

        /* Role Badge Clickable */
        .role-badge {
            cursor: pointer;
            transition: all 0.2s;
        }

        .role-badge:hover {
            opacity: 0.8;
            transform: scale(1.05);
        }
</style>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <h1><i class="bi bi-people me-3"></i>User Management</h1>
        <p class="subtitle">Manage user accounts, roles, and permissions</p>
    </div>

    <!-- Success Message -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            User <?= htmlspecialchars($_GET['success']) ?> successfully!
        </div>
    <?php endif; ?>

    <!-- Error Message -->
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            Error: <?= htmlspecialchars($_GET['error']) ?>
        </div>
    <?php endif; ?>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary">
                <i class="bi bi-people"></i>
            </div>
            <div class="value"><?= number_format($stats['total_users']) ?></div>
            <div class="label">Total Users</div>
        </div>

        <div class="stat-card">
            <div class="icon success">
                <i class="bi bi-person-check"></i>
            </div>
            <div class="value"><?= number_format($stats['active_users']) ?></div>
            <div class="label">Active Users</div>
        </div>

        <div class="stat-card clickable" onclick="window.location.href='?show_inactive_only=1'">
            <div class="icon warning">
                <i class="bi bi-person-x"></i>
            </div>
            <div class="value"><?= number_format($stats['inactive_users']) ?></div>
            <div class="label">Inactive Users</div>
        </div>

        <div class="stat-card">
            <div class="icon danger">
                <i class="bi bi-shield-check"></i>
            </div>
            <div class="value"><?= number_format($stats['admin_users']) ?></div>
            <div class="label">Administrators</div>
        </div>

        <div class="stat-card">
            <div class="icon info">
                <i class="bi bi-clock-history"></i>
            </div>
            <div class="value"><?= number_format($stats['users_with_login']) ?></div>
            <div class="label">Users with Login</div>
        </div>

        <div class="stat-card clickable" onclick="window.location.href='?show_flagged_only=1'">
            <div class="icon danger">
                <i class="bi bi-flag"></i>
            </div>
            <div class="value"><?= number_format($stats['flagged_users']) ?></div>
            <div class="label">Flagged Users</div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="filters-section">
        <form method="GET" class="filters-form">
            <div class="filters-row">
                <div class="filter-group">
                    <label>Search Users</label>
                    <input type="text" name="search" class="search-input" placeholder="Search by name, email, or company..." value="<?= htmlspecialchars($search) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Filter by Role</label>
                    <select name="filter_role" class="filter-select">
                        <option value="">All Roles</option>
                        <option value="public" <?= $filter_role === 'public' ? 'selected' : '' ?>>Public</option>
                        <option value="supported_user" <?= $filter_role === 'supported_user' ? 'selected' : '' ?>>Supported User</option>
                        <option value="account_manager" <?= $filter_role === 'account_manager' ? 'selected' : '' ?>>Account Manager</option>
                        <option value="support_consultant" <?= $filter_role === 'support_consultant' ? 'selected' : '' ?>>Support Consultant</option>
                        <option value="accountant" <?= $filter_role === 'accountant' ? 'selected' : '' ?>>Accountant</option>
                        <option value="administrator" <?= $filter_role === 'administrator' ? 'selected' : '' ?>>Administrator</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>&nbsp;</label>
                    <div class="filter-checkbox">
                        <input type="checkbox" name="show_inactive" <?= $show_inactive ? 'checked' : '' ?>>
                        <span>Show Inactive Users</span>
                        <span class="filter-count <?= $show_inactive ? 'active' : '' ?>"><?= number_format($stats['inactive_users']) ?></span>
                    </div>
                </div>

                <div class="filter-group">
                    <label>&nbsp;</label>
                    <div class="filter-checkbox">
                        <input type="checkbox" name="show_inactive_only" <?= $show_inactive_only ? 'checked' : '' ?>>
                        <span>Show Only Inactive</span>
                        <span class="filter-count <?= $show_inactive_only ? 'active' : '' ?>"><?= number_format($stats['inactive_users']) ?></span>
                    </div>
                </div>

                <div class="filter-group">
                    <label>&nbsp;</label>
                    <div class="filter-checkbox">
                        <input type="checkbox" name="show_flagged_only" <?= $show_flagged_only ? 'checked' : '' ?>>
                        <span>Show Flagged Only</span>
                        <span class="filter-count <?= $show_flagged_only ? 'active' : '' ?>"><?= number_format($stats['flagged_users']) ?></span>
                    </div>
                </div>

                <div class="filter-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i>
                        Apply Filters
                    </button>
                </div>
                
                <?php if (!empty($search) || $show_inactive || $show_inactive_only || $show_flagged_only || !empty($filter_role)): ?>
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

    <!-- Users Container -->
    <div class="users-container">
        <div class="users-header">
            <h2>Users <?= !empty($search) ? '- Search Results' : '' ?></h2>
            <div class="mt-2">
                <?php if (!empty($search)): ?>
                    <p class="mb-0 text-muted">
                        Found <?= count($users) ?> user<?= count($users) != 1 ? 's' : '' ?> matching "<?= htmlspecialchars($search) ?>"
                    </p>
                <?php endif; ?>
                
                <?php if ($show_inactive_only): ?>
                    <p class="mb-0 text-warning">
                        <i class="bi bi-person-x me-1"></i>
                        Showing only inactive users (<?= number_format($stats['inactive_users']) ?> total)
                    </p>
                <?php endif; ?>
                
                <?php if ($show_flagged_only): ?>
                    <p class="mb-0 text-danger">
                        <i class="bi bi-flag me-1"></i>
                        Showing only flagged users (<?= number_format($stats['flagged_users']) ?> total)
                    </p>
                <?php endif; ?>
                
                <?php if (!$show_inactive && !$show_inactive_only): ?>
                    <p class="mb-0 text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Inactive users are hidden (<?= number_format($stats['inactive_users']) ?> total). Use filters to show them.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($users)): ?>
            <div class="empty-state">
                <i class="bi bi-person-x"></i>
                <h3>No Users Found</h3>
                <p><?= !empty($search) ? 'No users match your search criteria.' : 'No users found matching the current filters.' ?></p>
            </div>
        <?php else: ?>
            <div class="users-grid">
                <?php foreach ($users as $user): ?>
                    <?php 
                    $hasMultiCompanySearch = hasMultiCompanyMatch($user['id'], $search, $multi_company_names);
                    $isFlagged = isUserFlagged($user, $multi_company_links);
                    $flagMessage = getFlagMessage($user, $multi_company_links);
                    $deactivationInfo = getDeactivationInfo($user);
                    ?>
                    <div class="user-card <?= $user['is_active'] ? '' : 'inactive' ?> <?= $hasMultiCompanySearch ? 'search-match' : '' ?> <?= $isFlagged ? 'flagged' : '' ?>">
                        
                        <?php if ($isFlagged): ?>
                            <div class="flag-alert">
                                <i class="bi bi-flag-fill icon"></i>
                                <span class="message"><?= htmlspecialchars($flagMessage) ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($deactivationInfo): ?>
                            <div class="deactivation-info">
                                <i class="bi bi-person-x icon"></i>
                                <span class="message">
                                    Deactivated on <?= $deactivationInfo['date'] ?> by <?= htmlspecialchars($deactivationInfo['by']) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        
                        <div class="user-header">
                            <div class="user-avatar <?= $user['is_active'] ? '' : 'inactive' ?>">
                                <?= strtoupper(substr($user['username'], 0, 2)) ?>
                            </div>
                            <div class="user-info">
                                <h3><?= htmlspecialchars($user['username']) ?></h3>
                                <div class="user-email"><?= htmlspecialchars($user['email']) ?></div>
                            </div>
                        </div>

                        <div class="user-details">
                            <div class="user-detail">
                                <div class="icon">
                                    <i class="bi bi-shield"></i>
                                </div>
                                <div class="value">
                                    <span class="role-badge <?= $isFlagged ? 'flagged' : '' ?>"
                                          style="background-color: <?= $isFlagged ? 'var(--danger-color)' : getRoleColor($user['role']) ?>"
                                          onclick="window.location.href='?filter_role=<?= urlencode($user['role']) ?>'"
                                          title="Click to filter by this role">
                                        <?= formatRoleDisplayName($user['role']) ?>
                                        <?= $isFlagged ? ' ⚠️' : '' ?>
                                    </span>
                                </div>
                            </div>

                            <div class="user-detail">
                                <div class="icon">
                                    <i class="bi bi-building"></i>
                                </div>
                                <div class="value">
                                    <?= htmlspecialchars($user['company_name'] ?? 'No Company') ?>
                                </div>
                            </div>

                            <div class="user-detail">
                                <div class="icon">
                                    <i class="bi <?= getProviderIcon($user['provider']) ?>"></i>
                                </div>
                                <div class="value">
                                    <span class="provider-badge">
                                        <i class="bi <?= getProviderIcon($user['provider']) ?>"></i>
                                        <?= ucfirst($user['provider']) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="user-detail">
                                <div class="icon">
                                    <i class="bi bi-clock"></i>
                                </div>
                                <div class="value">
                                    <?= $user['last_login'] ? date('M j, Y', strtotime($user['last_login'])) : 'Never' ?>
                                </div>
                            </div>

                            <div class="user-detail">
                                <div class="icon">
                                    <i class="bi bi-person-badge"></i>
                                </div>
                                <div class="value">
                                    <span class="status-badge <?= $user['is_active'] ? 'active' : 'inactive' ?>">
                                        <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </div>
                            </div>

                            <div class="user-detail">
                                <div class="icon">
                                    <i class="bi bi-calendar-plus"></i>
                                </div>
                                <div class="value">
                                    <?= date('M j, Y', strtotime($user['created_at'])) ?>
                                </div>
                            </div>
                        </div>

                        <?php if (isset($multi_company_names[$user['id']])): ?>
                            <div class="multi-company-access">
                                <div class="title">
                                    <i class="bi bi-buildings me-1"></i>
                                    Multi-Company Access (<?= count($multi_company_names[$user['id']]) ?> companies)
                                </div>
                                <div class="company-tags">
                                    <?php foreach ($multi_company_names[$user['id']] as $company_name): ?>
                                        <?php $isSearchMatch = !empty($search) && stripos($company_name, $search) !== false; ?>
                                        <span class="company-tag <?= $isSearchMatch ? 'search-highlight' : '' ?>">
                                            <?= htmlspecialchars($company_name) ?>
                                            <?php if ($isSearchMatch): ?>
                                                <i class="bi bi-search ms-1"></i>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="user-actions">
                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editUserModal<?= $user['id'] ?>">
                                <i class="bi bi-pencil"></i>
                                Edit
                            </button>
                            <button class="btn btn-sm <?= $user['is_active'] ? 'btn-danger' : 'btn-success' ?>"
                                    data-bs-toggle="modal"
                                    data-bs-target="#toggleUserModal<?= $user['id'] ?>">
                                <i class="bi <?= $user['is_active'] ? 'bi-person-x' : 'bi-person-check' ?>"></i>
                                <?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit User Modals -->
<?php foreach ($users as $user): ?>
<div class="modal fade" id="editUserModal<?= $user['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-person-gear me-2"></i>
                    Edit User: <?= htmlspecialchars($user['username']) ?>
                    <?php if (isUserFlagged($user, $multi_company_links)): ?>
                        <span class="badge bg-danger ms-2">Flagged</span>
                    <?php endif; ?>
                    <?php if (!$user['is_active']): ?>
                        <span class="badge bg-secondary ms-2">Inactive</span>
                    <?php endif; ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (isUserFlagged($user, $multi_company_links)): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-flag-fill me-2"></i>
                        <strong>Role Mismatch:</strong> This user is active and has company access but is assigned the "Public" role. Consider changing their role to "Supported User" or higher.
                    </div>
                <?php endif; ?>
                
                <?php if (!$user['is_active'] && $user['deactivated_at']): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Account Status:</strong> This account was deactivated on <?= date('M j, Y g:i A', strtotime($user['deactivated_at'])) ?> by <?= htmlspecialchars($user['deactivated_by_username'] ?? 'System') ?>.
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select" required>
                                <option value="public" <?= ($user['role'] === 'public') ? 'selected' : '' ?>>Public User</option>
                                <option value="supported_user" <?= ($user['role'] === 'supported_user') ? 'selected' : '' ?>>Supported User</option>
                                <option value="account_manager" <?= ($user['role'] === 'account_manager') ? 'selected' : '' ?>>Account Manager</option>
                                <option value="support_consultant" <?= ($user['role'] === 'support_consultant') ? 'selected' : '' ?>>Support Consultant</option>
                                <option value="accountant" <?= ($user['role'] === 'accountant') ? 'selected' : '' ?>>Accountant</option>
                                <option value="administrator" <?= ($user['role'] === 'administrator') ? 'selected' : '' ?>>Administrator</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Primary Company</label>
                            <select name="company_id" class="form-select">
                                <option value="">No Company</option>
                                <?php foreach ($companies as $id => $name): ?>
                                    <option value="<?= $id ?>" <?= ($user['company_id'] == $id ? 'selected' : '') ?>><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Multi-Company Access</label>
                    <select name="multi_companies[]" class="form-select" multiple size="4">
                        <?php foreach ($companies as $id => $name): ?>
                            <option value="<?= $id ?>" <?= (isset($multi_company_links[$user['id']]) && in_array($id, $multi_company_links[$user['id']]) ? 'selected' : '') ?>>
                                <?= htmlspecialchars($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text text-muted">Hold Ctrl (Windows) or Cmd (Mac) to select multiple companies.</small>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="is_active" id="activeCheck<?= $user['id'] ?>" <?= $user['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="activeCheck<?= $user['id'] ?>">
                        <strong>Active User</strong>
                    </label>
                </div>

                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Account Info:</strong> Created on <?= date('M j, Y', strtotime($user['created_at'])) ?> via <?= ucfirst($user['provider']) ?> login.
                    <?php if ($user['last_login']): ?>
                        Last login: <?= date('M j, Y g:i A', strtotime($user['last_login'])) ?>.
                    <?php else: ?>
                        User has never logged in.
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="edit_user" class="btn btn-primary">
                    <i class="bi bi-check"></i>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<script>
// Auto-focus on modal inputs
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('shown.bs.modal', function() {
        const firstInput = this.querySelector('input[type="text"]');
        if (firstInput) {
            firstInput.focus();
        }
    });
});

// Add hover effects to user cards
document.querySelectorAll('.user-card').forEach(card => {
    card.addEventListener('mouseenter', function() {
        if (!this.classList.contains('flagged')) {
            this.style.borderColor = '#4F46E5';
        }
    });
    
    card.addEventListener('mouseleave', function() {
        if (!this.classList.contains('search-match') && !this.classList.contains('flagged')) {
            this.style.borderColor = '#E2E8F0';
        }
    });
});

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
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>