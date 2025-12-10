<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Access control (Staff only)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'staff'])) {
    header('Location: /login.php');
    exit;
}

$company_id = (int)($_GET['company_id'] ?? 0);

// Handle assign product to user
if (isset($_POST['assign_product'])) {
    $subscription_id = (int)$_POST['subscription_id'];
    $user_id = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
    $quantity = (int)$_POST['quantity'];
    $notes = trim($_POST['notes'] ?? '');

    try {
        // Check available quantity first
        $stmt = $pdo->prepare("
            SELECT cs.quantity, COALESCE(si.assigned_quantity, 0) as assigned_quantity,
                   COALESCE(si.available_quantity, cs.quantity) as available_quantity
            FROM client_subscriptions cs
            LEFT JOIN subscription_inventory si ON cs.id = si.subscription_id
            WHERE cs.id = ?
        ");
        $stmt->execute([$subscription_id]);
        $inventory = $stmt->fetch();

        if (!$inventory) {
            throw new Exception("Subscription not found");
        }

        if ($quantity > $inventory['available_quantity']) {
            throw new Exception("Cannot assign $quantity licenses. Only {$inventory['available_quantity']} available.");
        }

        // Check if assignment already exists
        $stmt = $pdo->prepare("SELECT id, assigned_quantity FROM product_assignments WHERE subscription_id = ? AND user_id = ?");
        $stmt->execute([$subscription_id, $user_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update existing assignment
            $stmt = $pdo->prepare("UPDATE product_assignments SET assigned_quantity = assigned_quantity + ?, notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$quantity, $notes, $existing['id']]);
        } else {
            // Create new assignment
            $stmt = $pdo->prepare("INSERT INTO product_assignments (subscription_id, user_id, assigned_quantity, status, assigned_at, assigned_by, notes) VALUES (?, ?, ?, 'assigned', NOW(), ?, ?)");
            $stmt->execute([$subscription_id, $user_id, $quantity, $_SESSION['user']['id'], $notes]);
        }

        // Update inventory
        $stmt = $pdo->prepare("INSERT INTO subscription_inventory (subscription_id, total_quantity, assigned_quantity) VALUES (?, (SELECT quantity FROM client_subscriptions WHERE id = ?), ?) ON DUPLICATE KEY UPDATE assigned_quantity = assigned_quantity + ?");
        $stmt->execute([$subscription_id, $subscription_id, $quantity, $quantity]);

        $success = "Product assigned successfully!";
    } catch (Exception $e) {
        $error = "Error assigning product: " . $e->getMessage();
    }
}

// Handle edit assignment
if (isset($_POST['edit_assignment'])) {
    $assignment_id = (int)$_POST['assignment_id'];
    $new_quantity = (int)$_POST['new_quantity'];
    $notes = trim($_POST['notes'] ?? '');

    try {
        // Get current assignment details
        $stmt = $pdo->prepare("SELECT * FROM product_assignments WHERE id = ?");
        $stmt->execute([$assignment_id]);
        $assignment = $stmt->fetch();

        if ($assignment) {
            $old_quantity = $assignment['assigned_quantity'];
            $quantity_diff = $new_quantity - $old_quantity;

            // Update assignment
            $stmt = $pdo->prepare("UPDATE product_assignments SET assigned_quantity = ?, notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_quantity, $notes, $assignment_id]);

            // Update inventory
            $stmt = $pdo->prepare("UPDATE subscription_inventory SET assigned_quantity = assigned_quantity + ? WHERE subscription_id = ?");
            $stmt->execute([$quantity_diff, $assignment['subscription_id']]);

            $success = "Assignment updated successfully!";
        }
    } catch (PDOException $e) {
        $error = "Error updating assignment: " . $e->getMessage();
    }
}

// Handle unassign product
if (isset($_GET['unassign'])) {
    $assignment_id = (int)$_GET['unassign'];
    
    try {
        // Get assignment details
        $stmt = $pdo->prepare("SELECT * FROM product_assignments WHERE id = ?");
        $stmt->execute([$assignment_id]);
        $assignment = $stmt->fetch();

        if ($assignment) {
            // Delete assignment
            $stmt = $pdo->prepare("DELETE FROM product_assignments WHERE id = ?");
            $stmt->execute([$assignment_id]);

            // Update inventory
            $stmt = $pdo->prepare("UPDATE subscription_inventory SET assigned_quantity = assigned_quantity - ? WHERE subscription_id = ?");
            $stmt->execute([$assignment['assigned_quantity'], $assignment['subscription_id']]);
        }

        $success = "Product unassigned successfully!";
    } catch (PDOException $e) {
        $error = "Error unassigning product: " . $e->getMessage();
    }
}

// Get all companies with subscriptions - Fixed SQL query
$stmt = $pdo->query("SELECT DISTINCT c.id, c.name, c.phone, c.address,
    COUNT(cs.id) as subscription_count,
    SUM(cs.quantity) as total_licenses,
    COALESCE(SUM(si.assigned_quantity), 0) as assigned_licenses
    FROM companies c
    JOIN client_subscriptions cs ON c.id = cs.company_id
    LEFT JOIN subscription_inventory si ON cs.id = si.subscription_id
    WHERE cs.status = 'active'
    GROUP BY c.id, c.name, c.phone, c.address
    ORDER BY c.name ASC");
$companies = $stmt->fetchAll();

// If specific company selected, get detailed info
$company = null;
$subscriptions = [];
$company_users = [];
$assignments = [];

if ($company_id) {
    // Get company details
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
    $stmt->execute([$company_id]);
    $company = $stmt->fetch();

    if ($company) {
        // Get company subscriptions with product details
        $stmt = $pdo->prepare("SELECT cs.*, p.name as product_name, p.unit_type, p.base_price, b.name as bundle_name,
            COALESCE(si.total_quantity, cs.quantity) as total_quantity,
            COALESCE(si.assigned_quantity, 0) as assigned_quantity,
            COALESCE(si.available_quantity, cs.quantity) as available_quantity
            FROM client_subscriptions cs
            LEFT JOIN products p ON cs.product_id = p.id
            LEFT JOIN service_bundles b ON cs.bundle_id = b.id
            LEFT JOIN subscription_inventory si ON cs.id = si.subscription_id
            WHERE cs.company_id = ? AND cs.status = 'active'
            ORDER BY p.name ASC, b.name ASC");
        $stmt->execute([$company_id]);
        $subscriptions = $stmt->fetchAll();

        // Get ALL users for this company (both primary and multi-company)
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.username, u.email, u.role, 
                   CASE 
                       WHEN u.company_id = ? THEN 'Primary'
                       ELSE 'Multi-Company'
                   END as user_type
            FROM users u
            WHERE u.is_active = 1 
            AND (
                u.company_id = ? 
                OR u.id IN (
                    SELECT cu.user_id 
                    FROM company_users cu 
                    WHERE cu.company_id = ?
                )
            )
            ORDER BY u.username ASC
        ");
        $stmt->execute([$company_id, $company_id, $company_id]);
        $company_users = $stmt->fetchAll();

        // Get current assignments with additional details for editing
        $stmt = $pdo->prepare("SELECT pa.*, u.username, u.email, p.name as product_name, b.name as bundle_name, cs.quantity as total_quantity,
            cs.id as subscription_id
            FROM product_assignments pa
            JOIN client_subscriptions cs ON pa.subscription_id = cs.id
            LEFT JOIN users u ON pa.user_id = u.id
            LEFT JOIN products p ON cs.product_id = p.id
            LEFT JOIN service_bundles b ON cs.bundle_id = b.id
            WHERE cs.company_id = ?
            ORDER BY p.name ASC, b.name ASC, u.username ASC");
        $stmt->execute([$company_id]);
        $assignments = $stmt->fetchAll();
    }
}

$page_title = $company ? "Product Assignments - " . $company['name'] . " | CaminhoIT" : "Product Assignments | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>


<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
        --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        --border-radius: 12px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    body {
        background: #F8FAFC;
    }

    .main-container {
        max-width: 1400px;
        margin: 2rem auto;
        padding: 0 1rem;
    }

    /* Page Header */
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
        margin: 0 0 0.5rem 0;
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        text-align: center;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        transition: var(--transition);
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
    }

    .stat-value {
        font-size: 2.5rem;
        font-weight: 700;
        background: var(--primary-gradient);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .stat-label {
        color: #6b7280;
        font-size: 0.875rem;
        margin-top: 0.5rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    /* Section Card */
    .section-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        margin-bottom: 2rem;
    }

    .section-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        padding: 1.5rem 2rem;
        color: white;
    }

    .section-header h3 {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 600;
    }

    .section-content {
        padding: 2rem;
    }

    /* Company Cards */
    .company-card {
        background: white;
        border: 2px solid #e5e7eb;
        border-radius: 12px;
        padding: 1.5rem;
        cursor: pointer;
        transition: var(--transition);
        height: 100%;
    }

    .company-card:hover {
        border-color: #667eea;
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(102, 126, 234, 0.2);
    }

    .company-card h5 {
        font-size: 1.125rem;
        font-weight: 700;
        color: #1f2937;
    }

    /* Usage Bar */
    .usage-bar {
        height: 6px;
        background: #e5e7eb;
        border-radius: 10px;
        overflow: hidden;
    }

    .usage-fill {
        height: 100%;
        background: var(--success-gradient);
        transition: width 0.3s ease;
    }

    .usage-fill.warning {
        background: var(--warning-gradient);
    }

    /* Tables */
    table.table {
        background: white;
        border-radius: 12px;
        overflow: hidden;
    }

    .table thead {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }

    .table thead th {
        color: white;
        font-weight: 600;
        border: none;
        padding: 1rem;
    }

    .table tbody tr {
        transition: var(--transition);
    }

    .table tbody tr:hover {
        background: rgba(102, 126, 234, 0.05);
    }

    .table tbody td {
        padding: 1rem;
        vertical-align: middle;
    }

    /* Badges */
    .badge {
        padding: 0.5rem 1rem;
        border-radius: 50px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .badge-info {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        color: white;
    }

    .badge-success {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: white;
    }

    .badge-warning {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        color: white;
    }

    /* Buttons */
    .btn-primary {
        background: var(--primary-gradient);
        border: none;
        color: white;
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        transition: var(--transition);
        font-weight: 600;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        color: white;
    }

    /* Breadcrumb */
    .breadcrumb {
        background: transparent;
        padding: 0;
        margin-bottom: 1rem;
    }

    .breadcrumb-item a {
        color: #667eea;
        text-decoration: none;
        font-weight: 600;
    }

    .breadcrumb-item.active {
        color: #6b7280;
    }

    /* Modal */
    .modal {
        z-index: 9999 !important;
    }

    .modal-backdrop {
        z-index: 9998 !important;
    }

    .modal-content {
        border-radius: 12px;
        border: none;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        background: white;
    }

    .modal-header {
        background: var(--primary-gradient);
        color: white;
        border-radius: 12px 12px 0 0;
        border: none;
    }

    .modal-title {
        font-weight: 700;
    }

    .modal-header .btn-close {
        filter: brightness(0) invert(1);
    }

    .modal-body {
        background: white;
    }

    .modal-footer {
        background: white;
    }

    /* Alerts */
    .alert {
        border-radius: 12px;
        border: none;
        padding: 1rem 1.5rem;
        font-weight: 500;
    }

    .alert-success {
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(5, 150, 105, 0.1) 100%);
        color: #065f46;
        border-left: 4px solid #10b981;
    }

    .alert-danger {
        background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(220, 38, 38, 0.1) 100%);
        color: #991b1b;
        border-left: 4px solid #ef4444;
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

    :root.dark .page-header p {
        color: #94a3b8 !important;
    }

    /* Stats Cards */
    :root.dark .stat-card {
        background: #1e293b !important;
        border-color: #334155 !important;
    }

    :root.dark .stat-value {
        background: linear-gradient(135deg, #a78bfa 0%, #c4b5fd 100%) !important;
        -webkit-background-clip: text !important;
        -webkit-text-fill-color: transparent !important;
        background-clip: text !important;
    }

    :root.dark .stat-label {
        color: #94a3b8 !important;
    }

    /* Section Cards */
    :root.dark .section-card {
        background: #1e293b !important;
        border-color: #334155 !important;
    }

    :root.dark .section-header {
        background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
    }

    :root.dark .section-content {
        background: transparent !important;
    }

    /* Company Cards */
    :root.dark .company-card {
        background: #0f172a !important;
        border-color: #334155 !important;
    }

    :root.dark .company-card:hover {
        border-color: #8b5cf6 !important;
        box-shadow: 0 10px 30px rgba(139, 92, 246, 0.3) !important;
    }

    :root.dark .company-card h5 {
        color: #f1f5f9 !important;
    }

    :root.dark .usage-bar {
        background: #334155 !important;
    }

    /* Tables */
    :root.dark table.table {
        background: #1e293b !important;
    }

    :root.dark .table {
        color: #e2e8f0 !important;
    }

    :root.dark .table thead {
        background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
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

    /* Badges - keep gradients */
    :root.dark .badge-info {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%) !important;
    }

    :root.dark .badge-success {
        background: linear-gradient(135deg, #065f46 0%, #047857 100%) !important;
    }

    :root.dark .badge-warning {
        background: linear-gradient(135deg, #92400e 0%, #b45309 100%) !important;
    }

    /* Forms */
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

    :root.dark .form-label {
        color: #cbd5e1 !important;
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

    /* Breadcrumb */
    :root.dark .breadcrumb-item a {
        color: #a78bfa !important;
    }

    :root.dark .breadcrumb-item.active {
        color: #94a3b8 !important;
    }

    /* Modal */
    :root.dark .modal-content {
        background: #1e293b !important;
        border-color: #334155 !important;
    }

    :root.dark .modal-header {
        background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
        border: none !important;
    }

    :root.dark .modal-body {
        background: #1e293b !important;
        border-color: #334155 !important;
    }

    :root.dark .modal-footer {
        background: #1e293b !important;
        border-color: #334155 !important;
    }

    :root.dark .modal-backdrop {
        opacity: 0.8 !important;
    }

    /* Alerts */
    :root.dark .alert-success {
        background: linear-gradient(135deg, rgba(6, 95, 70, 0.3) 0%, rgba(4, 120, 87, 0.3) 100%) !important;
        color: #a7f3d0 !important;
        border-left-color: #10b981 !important;
    }

    :root.dark .alert-danger {
        background: linear-gradient(135deg, rgba(127, 29, 29, 0.3) 0%, rgba(153, 27, 27, 0.3) 100%) !important;
        color: #fca5a5 !important;
        border-left-color: #ef4444 !important;
    }

    /* Hero Section Styles */
    body {
        padding-top: 80px;
    }

    .hero {
        position: relative;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        padding: 4rem 0 6rem;
        margin-bottom: -4rem;
        overflow: hidden;
        margin-top: -80px;
        padding-top: calc(4rem + 80px);
    }

    .hero-gradient {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.9) 0%, rgba(118, 75, 162, 0.9) 100%);
        z-index: 0;
    }

    .hero .container {
        position: relative;
        z-index: 1;
    }

    .hero-title {
        font-size: 2.5rem;
        font-weight: 700;
        color: white;
        margin-bottom: 1rem;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .hero-subtitle {
        font-size: 1.125rem;
        color: rgba(255, 255, 255, 0.9);
        margin-bottom: 2rem;
    }

    /* Improved Assignment Table */
    .assignment-table-container {
        background: white;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        margin-bottom: 2rem;
    }

    .assignment-table-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        padding: 1.5rem 2rem;
        color: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .assignment-table-header h3 {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 600;
    }

    .assignment-table-body {
        padding: 2rem;
    }

    /* Breadcrumb Enhanced */
    .breadcrumb-enhanced {
        background: white;
        border-radius: 12px;
        padding: 1rem 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    :root.dark .hero {
        background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%);
    }

    :root.dark .assignment-table-container {
        background: #1e293b !important;
        border-color: #334155 !important;
    }

    :root.dark .assignment-table-header {
        background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
    }

    :root.dark .assignment-table-body {
        background: transparent !important;
    }

    :root.dark .breadcrumb-enhanced {
        background: #1e293b !important;
        border-color: #334155 !important;
    }

    /* Subscription Cards */
    .subscription-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        padding: 2rem;
        margin-bottom: 2rem;
        border: 2px solid #e5e7eb;
        transition: var(--transition);
    }

    .subscription-card:hover {
        border-color: #667eea;
        box-shadow: 0 10px 30px rgba(102, 126, 234, 0.15);
    }

    .subscription-card h5 {
        font-size: 1.25rem;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 0.5rem;
    }

    .subscription-card h6 {
        font-size: 1rem;
        font-weight: 600;
        color: #374151;
    }

    /* Assignment List */
    .assignment-list {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .assignment-card {
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 1rem;
        transition: var(--transition);
    }

    .assignment-card:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
    }

    .assignment-card.unassigned {
        background: linear-gradient(135deg, rgba(251, 191, 36, 0.1) 0%, rgba(245, 158, 11, 0.1) 100%);
        border-color: #fbbf24;
    }

    .assignment-card strong {
        font-size: 0.95rem;
        font-weight: 600;
        color: #1f2937;
    }

    .assignment-card p {
        font-size: 0.875rem;
        margin-bottom: 0;
    }

    /* Dark Mode for Subscription Cards */
    :root.dark .subscription-card {
        background: #1e293b !important;
        border-color: #334155 !important;
    }

    :root.dark .subscription-card:hover {
        border-color: #8b5cf6 !important;
    }

    :root.dark .subscription-card h5 {
        color: #f1f5f9 !important;
    }

    :root.dark .subscription-card h6 {
        color: #cbd5e1 !important;
    }

    :root.dark .assignment-card {
        background: #0f172a !important;
        border-color: #334155 !important;
    }

    :root.dark .assignment-card:hover {
        background: #1e293b !important;
        border-color: #475569 !important;
    }

    :root.dark .assignment-card.unassigned {
        background: linear-gradient(135deg, rgba(146, 64, 14, 0.3) 0%, rgba(180, 83, 9, 0.3) 100%) !important;
        border-color: #f59e0b !important;
    }

    :root.dark .assignment-card strong {
        color: #f1f5f9 !important;
    }

    :root.dark .assignment-card p {
        color: #94a3b8 !important;
    }
</style>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="text-center">
            <h1 class="hero-title">
                <i class="bi bi-person-lines-fill me-3"></i>
                Product Assignments
            </h1>
            <p class="hero-subtitle">
                Manage product licenses and assignments across all companies
            </p>
        </div>
    </div>
</header>

<div class="main-container">
    <?php if (!$company_id): ?>
        <!-- Company Selection View -->

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

        <div class="section-card">
            <div class="section-header">
                <h3><i class="bi bi-building me-2"></i>Companies with Active Subscriptions</h3>
            </div>
            <div class="section-content">
                <?php if (empty($companies)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-building" style="font-size: 4rem; color: #d1d5db;"></i>
                        <h4 class="mt-3 text-muted">No Companies Found</h4>
                        <p class="text-muted">No companies have active subscriptions yet.</p>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($companies as $comp): ?>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div class="company-card" onclick="window.location.href='?company_id=<?= $comp['id'] ?>'">
                                    <h5 class="mb-2"><?= htmlspecialchars($comp['name']) ?></h5>
                                    <p class="text-muted mb-3">
                                        <?php if ($comp['phone']): ?>
                                            <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($comp['phone']) ?>
                                        <?php endif; ?>
                                        <?php if ($comp['address']): ?>
                                            <br><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($comp['address']) ?>
                                        <?php endif; ?>
                                    </p>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge badge-info"><?= $comp['subscription_count'] ?> Subscriptions</span>
                                        </div>
                                        <div class="text-end">
                                            <div class="text-muted small">
                                                <?= $comp['assigned_licenses'] ?> / <?= $comp['total_licenses'] ?> Assigned
                                            </div>
                                            <div class="usage-bar mt-1" style="width: 100px;">
                                                <?php $usage_percent = $comp['total_licenses'] > 0 ? ($comp['assigned_licenses'] / $comp['total_licenses']) * 100 : 0; ?>
                                                <div class="usage-fill <?= $usage_percent > 90 ? 'warning' : '' ?>" style="width: <?= $usage_percent ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php else: ?>
        <!-- Company Detail View -->
        <nav class="breadcrumb-enhanced" aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="product-assignments.php"><i class="bi bi-arrow-left me-1"></i>Product Assignments</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($company['name']) ?></li>
            </ol>
        </nav>

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

        <!-- Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= count($subscriptions) ?></div>
                <div class="stat-label">Active Subscriptions</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= array_sum(array_column($subscriptions, 'total_quantity')) ?></div>
                <div class="stat-label">Total Licenses</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= array_sum(array_column($subscriptions, 'assigned_quantity')) ?></div>
                <div class="stat-label">Assigned Licenses</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= array_sum(array_column($subscriptions, 'available_quantity')) ?></div>
                <div class="stat-label">Available Licenses</div>
            </div>
        </div>

        <!-- Subscriptions and Assignments -->
        <?php foreach ($subscriptions as $subscription): ?>
            <div class="subscription-card">
                <div class="row">
                    <div class="col-md-4">
                        <h5 class="mb-2">
                            <?= htmlspecialchars($subscription['product_name'] ?: $subscription['bundle_name']) ?>
                        </h5>
                        <p class="text-muted mb-3">
                            <?= ucfirst(str_replace('_', ' ', $subscription['unit_type'])) ?> • 
                            £<?= number_format($subscription['unit_price'], 2) ?> each
                        </p>
                        
                        <!-- Usage Bar -->
                        <div class="mb-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <small class="text-muted">License Usage</small>
                                <small class="text-muted">
                                    <?= $subscription['assigned_quantity'] ?> / <?= $subscription['total_quantity'] ?>
                                </small>
                            </div>
                            <div class="usage-bar">
                                <?php $usage_percent = $subscription['total_quantity'] > 0 ? ($subscription['assigned_quantity'] / $subscription['total_quantity']) * 100 : 0; ?>
                                <div class="usage-fill <?= $usage_percent > 90 ? 'warning' : '' ?>" style="width: <?= $usage_percent ?>%"></div>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <span class="badge badge-success"><?= $subscription['assigned_quantity'] ?> Assigned</span>
                            <span class="badge badge-warning"><?= $subscription['available_quantity'] ?> Available</span>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">Assignments</h6>
                            <button class="btn btn-sm btn-primary" onclick="assignProduct(<?= $subscription['id'] ?>)">
                                <i class="bi bi-person-plus me-1"></i>Assign to Member
                            </button>
                        </div>
                        
                        <div class="assignment-list">
                            <?php 
                            $subscription_assignments = array_filter($assignments, function($a) use ($subscription) {
                                return $a['subscription_id'] == $subscription['id'];
                            });
                            
                            if (empty($subscription_assignments)): ?>
                                <div class="assignment-card unassigned">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong>All licenses unassigned</strong>
                                            <p class="text-muted mb-0"><?= $subscription['total_quantity'] ?> licenses available for assignment</p>
                                        </div>
                                        <span class="badge badge-warning">Unassigned</span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($subscription_assignments as $assignment): ?>
                                    <div class="assignment-card">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <?php if ($assignment['user_id']): ?>
                                                    <strong><?= htmlspecialchars($assignment['username']) ?></strong>
                                                    <p class="text-muted mb-0"><?= htmlspecialchars($assignment['email']) ?></p>
                                                <?php else: ?>
                                                    <strong>Unassigned Pool</strong>
                                                    <p class="text-muted mb-0">Available for assignment</p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge badge-info"><?= $assignment['assigned_quantity'] ?> licenses</span>
                                                <span class="badge badge-<?= $assignment['status'] == 'assigned' ? 'success' : 'secondary' ?>">
                                                    <?= ucfirst($assignment['status']) ?>
                                                </span>
                                                <div class="btn-group">
                                                    <button class="btn btn-sm btn-outline-warning" onclick="editAssignment(<?= $assignment['id'] ?>, <?= $assignment['assigned_quantity'] ?>, '<?= htmlspecialchars($assignment['notes'] ?? '') ?>', <?= $assignment['subscription_id'] ?>, <?= $subscription['total_quantity'] ?>)">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <a href="#" 
                                                       class="btn btn-sm btn-outline-danger"
                                                       onclick="showUnassignModal(<?= $company_id ?>, <?= $assignment['id'] ?>); return false;">
                                                        <i class="bi bi-person-dash"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if ($subscription['available_quantity'] > 0): ?>
                                    <div class="assignment-card unassigned">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?= $subscription['available_quantity'] ?> licenses unassigned</strong>
                                                <p class="text-muted mb-0">Available for assignment</p>
                                            </div>
                                            <span class="badge badge-warning">Unassigned</span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($subscriptions)): ?>
            <div class="text-center py-5">
                <i class="bi bi-box" style="font-size: 4rem; color: #d1d5db;"></i>
                <h4 class="mt-3 text-muted">No Active Subscriptions</h4>
                <p class="text-muted">This company doesn't have any active product subscriptions yet.</p>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<!-- Assign Product Modal -->
<div class="modal fade" id="assignProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assign Product to Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="subscription_id" id="assign_subscription_id">
                
                <div class="mb-3">
                    <label class="form-label">Select Member</label>
                    <select name="user_id" id="assign_user_select" class="form-select" required>
                        <option value="">Select a member...</option>
                        <!-- Options will be populated dynamically -->
                    </select>
                    <div class="form-text">
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            <span id="assign_user_count">Loading...</span>
                        </small>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Quantity to Assign</label>
                    <input type="number" name="quantity" class="form-control" min="1" value="1" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes about this assignment"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="assign_product" class="btn btn-primary">Assign Product</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Assignment Modal -->
<div class="modal fade" id="editAssignmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Assignment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="assignment_id" id="edit_assignment_id">
                
                <div class="mb-3">
                    <label class="form-label">Current Quantity</label>
                    <input type="number" name="new_quantity" id="edit_quantity" class="form-control" min="1" required>
                    <div class="form-text">
                        <small class="text-muted" id="edit_quantity_help">
                            <i class="bi bi-info-circle me-1"></i>
                            Available licenses: <span id="edit_available_licenses"></span>
                        </small>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" id="edit_notes" class="form-control" rows="3" placeholder="Optional notes about this assignment"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="edit_assignment" class="btn btn-primary">Update Assignment</button>
            </div>
        </form>
    </div>
</div>

<!-- Unassign Product Confirmation Modal -->
<div class="modal fade" id="unassignProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Unassign Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to unassign this product from this user?</p>
                <p class="text-muted mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    This action will free up the license for reassignment.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="confirmUnassignLink" class="btn btn-danger">Unassign Product</a>
            </div>
        </div>
    </div>
</div>

<script>
// Store all users and assignments data
const allUsers = <?= json_encode($company_users) ?>;
const allAssignments = <?= json_encode($assignments) ?>;

function assignProduct(subscriptionId) {
    document.getElementById('assign_subscription_id').value = subscriptionId;
    
    // Get users who already have this product assigned
    const assignedUserIds = allAssignments
        .filter(assignment => assignment.subscription_id == subscriptionId && assignment.user_id)
        .map(assignment => assignment.user_id);
    
    // Filter out users who already have this product
    const availableUsers = allUsers.filter(user => !assignedUserIds.includes(user.id));
    
    // Populate the select dropdown
    const userSelect = document.getElementById('assign_user_select');
    userSelect.innerHTML = '<option value="">Select a member...</option>';
    
    availableUsers.forEach(user => {
        const option = document.createElement('option');
        option.value = user.id;
        option.textContent = `${user.username} (${user.email})`;
        if (user.user_type === 'Multi-Company') {
            option.textContent += ' • Multi';
        }
        userSelect.appendChild(option);
    });
    
    // Update the user count display
    const userCountSpan = document.getElementById('assign_user_count');
    const totalUsers = allUsers.length;
    const assignedUsers = assignedUserIds.length;
    const availableUserCount = availableUsers.length;
    
    if (availableUserCount === 0) {
        userCountSpan.innerHTML = '<span class="form-text-assigned"><i class="bi bi-exclamation-triangle me-1"></i>All users already have this product assigned</span>';
    } else {
        userCountSpan.innerHTML = `<span class="form-text-available"><i class="bi bi-check-circle me-1"></i>Showing ${availableUserCount} available users (${assignedUsers} already assigned)</span>`;
    }
    
    const modal = new bootstrap.Modal(document.getElementById('assignProductModal'));
    modal.show();
}

function editAssignment(assignmentId, currentQuantity, notes, subscriptionId, totalLicenses) {
    document.getElementById('edit_assignment_id').value = assignmentId;
    document.getElementById('edit_quantity').value = currentQuantity;
    document.getElementById('edit_notes').value = notes;
    
    // Calculate available licenses (assuming we need to account for current assignment)
    const availableLicenses = totalLicenses - currentQuantity;
    document.getElementById('edit_available_licenses').textContent = availableLicenses;
    
    // Set max quantity to current + available
    document.getElementById('edit_quantity').max = totalLicenses;
    
    const modal = new bootstrap.Modal(document.getElementById('editAssignmentModal'));
    modal.show();
}

function showUnassignModal(companyId, assignmentId) {
    const confirmLink = document.getElementById('confirmUnassignLink');
    confirmLink.href = `?company_id=${companyId}&unassign=${assignmentId}`;
    
    const modal = new bootstrap.Modal(document.getElementById('unassignProductModal'));
    modal.show();
}

// Debug: Log the filtering
console.log('Total users available:', allUsers.length);
console.log('Users and assignments loaded for dynamic filtering');
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>
