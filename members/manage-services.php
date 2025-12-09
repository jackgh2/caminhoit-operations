<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /login.php');
    exit;
}

// Check if user has access (account_manager only)
if (!in_array($user['role'], ['account_manager', 'administrator'])) {
    header('Location: /members/dashboard.php');
    exit;
}

$user_id = $user['id'];

// Handle AJAX requests for company data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'company_data') {
    $company_id = (int)($_GET['company_id'] ?? 0);
    
    header('Content-Type: application/json');
    
    if (!$company_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid company ID']);
        exit;
    }
    
    try {
        // Verify access to this company
        $stmt = $pdo->prepare("
            SELECT c.* FROM companies c
            WHERE c.id = ? AND c.id IN (
                SELECT c2.id FROM companies c2
                JOIN users u ON (u.company_id = c2.id OR u.id IN (
                    SELECT cu.user_id FROM company_users cu WHERE cu.company_id = c2.id
                ))
                WHERE u.id = ?
            )
        ");
        $stmt->execute([$company_id, $user_id]);
        $company = $stmt->fetch();

        if (!$company) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit;
        }

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

        // Get ALL users for this company
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

        // Get current assignments
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

        echo json_encode([
            'success' => true,
            'company' => $company,
            'subscriptions' => $subscriptions,
            'users' => $company_users,
            'assignments' => $assignments
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Handle AJAX assign product request
if (isset($_POST['ajax']) && $_POST['ajax'] === 'assign_product') {
    header('Content-Type: application/json');
    
    $subscription_id = (int)$_POST['subscription_id'];
    $assigned_user_id = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
    $quantity = (int)$_POST['quantity'];
    $notes = trim($_POST['notes'] ?? '');

    try {
        // Verify access
        $stmt = $pdo->prepare("
            SELECT cs.company_id 
            FROM client_subscriptions cs
            WHERE cs.id = ? AND cs.company_id IN (
                SELECT c.id FROM companies c 
                WHERE c.id = (SELECT company_id FROM users WHERE id = ?)
                OR c.id IN (SELECT company_id FROM company_users WHERE user_id = ?)
            )
        ");
        $stmt->execute([$subscription_id, $user_id, $user_id]);
        if (!$stmt->fetch()) {
            throw new Exception("Access denied");
        }

        // Get total and assigned licenses for this subscription
        $stmt = $pdo->prepare("
            SELECT cs.quantity as total_licenses,
                   COALESCE(si.assigned_quantity, 0) as assigned_licenses
            FROM client_subscriptions cs
            LEFT JOIN subscription_inventory si ON cs.id = si.subscription_id
            WHERE cs.id = ?
        ");
        $stmt->execute([$subscription_id]);
        $license_info = $stmt->fetch();

        if (!$license_info) {
            echo json_encode(['success' => false, 'error' => 'Subscription not found']);
            exit;
        }

        // Check if assignment already exists
        $stmt = $pdo->prepare("SELECT id, assigned_quantity FROM product_assignments WHERE subscription_id = ? AND user_id = ?");
        $stmt->execute([$subscription_id, $assigned_user_id]);
        $existing = $stmt->fetch();

        // Calculate how many licenses will be assigned in total
        $current_assigned = $existing ? $existing['assigned_quantity'] : 0;
        $new_total_assigned = $license_info['assigned_licenses'] + $quantity;

        // Prevent over-assignment
        if ($new_total_assigned > $license_info['total_licenses']) {
            $available = $license_info['total_licenses'] - $license_info['assigned_licenses'];
            echo json_encode([
                'success' => false,
                'error' => "Cannot assign $quantity license(s). Only $available license(s) available out of {$license_info['total_licenses']} total."
            ]);
            exit;
        }

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE product_assignments SET assigned_quantity = assigned_quantity + ?, notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$quantity, $notes, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO product_assignments (subscription_id, user_id, assigned_quantity, status, assigned_at, assigned_by, notes) VALUES (?, ?, ?, 'assigned', NOW(), ?, ?)");
            $stmt->execute([$subscription_id, $assigned_user_id, $quantity, $user_id, $notes]);
        }

        // Update inventory
        $stmt = $pdo->prepare("INSERT INTO subscription_inventory (subscription_id, total_quantity, assigned_quantity) VALUES (?, (SELECT quantity FROM client_subscriptions WHERE id = ?), ?) ON DUPLICATE KEY UPDATE assigned_quantity = assigned_quantity + ?");
        $stmt->execute([$subscription_id, $subscription_id, $quantity, $quantity]);

        echo json_encode(['success' => true, 'message' => 'Product assigned successfully!']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error assigning product: ' . $e->getMessage()]);
        exit;
    }
}

// Handle AJAX edit assignment request
if (isset($_POST['ajax']) && $_POST['ajax'] === 'edit_assignment') {
    header('Content-Type: application/json');
    
    $assignment_id = (int)$_POST['assignment_id'];
    $new_quantity = (int)$_POST['new_quantity'];
    $notes = trim($_POST['notes'] ?? '');

    try {
        // Verify access to this assignment
        $stmt = $pdo->prepare("
            SELECT pa.*, cs.company_id 
            FROM product_assignments pa
            JOIN client_subscriptions cs ON pa.subscription_id = cs.id
            WHERE pa.id = ? AND cs.company_id IN (
                SELECT c.id FROM companies c 
                WHERE c.id = (SELECT company_id FROM users WHERE id = ?)
                OR c.id IN (SELECT company_id FROM company_users WHERE user_id = ?)
            )
        ");
        $stmt->execute([$assignment_id, $user_id, $user_id]);
        $assignment = $stmt->fetch();

        if ($assignment) {
            $old_quantity = $assignment['assigned_quantity'];
            $quantity_diff = $new_quantity - $old_quantity;

            // Get total and assigned licenses for validation
            $stmt = $pdo->prepare("
                SELECT cs.quantity as total_licenses,
                       COALESCE(si.assigned_quantity, 0) as assigned_licenses
                FROM client_subscriptions cs
                LEFT JOIN subscription_inventory si ON cs.id = si.subscription_id
                WHERE cs.id = ?
            ");
            $stmt->execute([$assignment['subscription_id']]);
            $license_info = $stmt->fetch();

            // Check if new assignment would exceed total licenses
            $new_total_assigned = $license_info['assigned_licenses'] + $quantity_diff;
            if ($new_total_assigned > $license_info['total_licenses']) {
                $available = $license_info['total_licenses'] - ($license_info['assigned_licenses'] - $old_quantity);
                echo json_encode([
                    'success' => false,
                    'error' => "Cannot assign $new_quantity license(s). Only $available license(s) available out of {$license_info['total_licenses']} total."
                ]);
                exit;
            }

            // Update assignment
            $stmt = $pdo->prepare("UPDATE product_assignments SET assigned_quantity = ?, notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_quantity, $notes, $assignment_id]);

            // Update inventory
            $stmt = $pdo->prepare("UPDATE subscription_inventory SET assigned_quantity = assigned_quantity + ? WHERE subscription_id = ?");
            $stmt->execute([$quantity_diff, $assignment['subscription_id']]);

            echo json_encode(['success' => true, 'message' => 'Assignment updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Access denied or assignment not found']);
        }
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Error updating assignment: ' . $e->getMessage()]);
        exit;
    }
}

// Handle AJAX unassign request
if (isset($_POST['ajax']) && $_POST['ajax'] === 'unassign_product') {
    header('Content-Type: application/json');
    
    $assignment_id = (int)$_POST['assignment_id'];
    
    try {
        // Verify access and get assignment details
        $stmt = $pdo->prepare("
            SELECT pa.*, cs.company_id 
            FROM product_assignments pa
            JOIN client_subscriptions cs ON pa.subscription_id = cs.id
            WHERE pa.id = ? AND cs.company_id IN (
                SELECT c.id FROM companies c 
                WHERE c.id = (SELECT company_id FROM users WHERE id = ?)
                OR c.id IN (SELECT company_id FROM company_users WHERE user_id = ?)
            )
        ");
        $stmt->execute([$assignment_id, $user_id, $user_id]);
        $assignment = $stmt->fetch();

        if ($assignment) {
            // Delete assignment
            $stmt = $pdo->prepare("DELETE FROM product_assignments WHERE id = ?");
            $stmt->execute([$assignment_id]);

            // Update inventory
            $stmt = $pdo->prepare("UPDATE subscription_inventory SET assigned_quantity = assigned_quantity - ? WHERE subscription_id = ?");
            $stmt->execute([$assignment['assigned_quantity'], $assignment['subscription_id']]);
            
            echo json_encode(['success' => true, 'message' => 'Product unassigned successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Access denied or assignment not found']);
        }
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Error unassigning product: ' . $e->getMessage()]);
        exit;
    }
}

// Get companies that this account manager has access to
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.name, c.phone, c.address,
        COUNT(cs.id) as subscription_count,
        SUM(cs.quantity) as total_licenses,
        COALESCE(SUM(si.assigned_quantity), 0) as assigned_licenses,
        CASE 
            WHEN u.company_id = c.id THEN 'Primary'
            ELSE 'Multi-Company'
        END as relationship_type
    FROM companies c
    JOIN users u ON (u.company_id = c.id OR u.id IN (
        SELECT cu.user_id FROM company_users cu WHERE cu.company_id = c.id
    ))
    JOIN client_subscriptions cs ON c.id = cs.company_id
    LEFT JOIN subscription_inventory si ON cs.id = si.subscription_id
    WHERE u.id = ? AND cs.status = 'active'
    GROUP BY c.id, c.name, c.phone, c.address, relationship_type
    ORDER BY relationship_type ASC, c.name ASC
");
$stmt->execute([$user_id]);
$companies = $stmt->fetchAll();

$page_title = "Service Allocation | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>
    <style>
        /* Template-style cards */
        .template-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            position: relative;
            margin-bottom: 2rem;
        }

        .template-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            z-index: 1;
        }

        .template-card-header {
            padding: 1.5rem 2rem 1rem 2rem;
            border-bottom: 1px solid #e9ecef;
            background: #f8f9fa;
        }

        .template-card-body {
            padding: 2rem;
        }

        .company-card {
            transition: all 0.3s ease;
            cursor: pointer;
            border: 1px solid #e9ecef;
            position: relative;
        }

        .company-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .company-card.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .company-card.expanded {
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.2);
            border-color: #667eea;
        }

        /* Expansion area styles */
        .company-expansion {
            display: none;
            margin-top: 2rem;
            opacity: 0;
            transform: translateY(-20px);
            transition: all 0.4s ease;
        }

        .company-expansion.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .usage-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }

        .usage-fill {
            height: 100%;
            transition: width 0.3s ease;
        }

        .usage-fill.success { background: linear-gradient(90deg, #10b981, #059669); }
        .usage-fill.warning { background: linear-gradient(90deg, #f59e0b, #d97706); }
        .usage-fill.danger { background: linear-gradient(90deg, #ef4444, #dc2626); }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
            font-size: 1.2rem;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #6b7280;
            font-size: 0.9rem;
        }

        /* FIXED SUBSCRIPTION CARD STYLING */
        .subscription-card {
            position: relative;
            overflow: hidden;
            margin-bottom: 1.5rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .subscription-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .subscription-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 2px solid #667eea;
            padding: 1.5rem;
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .subscription-header h6 {
            color: #667eea;
            font-weight: 600;
            margin: 0;
        }

        .subscription-body {
            padding: 1.5rem;
        }

        /* FIXED ASSIGNMENT CARD STYLING */
        .assignment-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem 1.5rem; /* FIXED PADDING */
            margin-bottom: 0.75rem; /* BETTER SPACING */
            transition: all 0.2s ease;
        }

        .assignment-card:hover {
            transform: translateX(3px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .assignment-card.unassigned {
            background: #fef3c7;
            border-color: #fbbf24;
        }

        /* FIXED ASSIGNMENT CONTENT STYLING */
        .assignment-user-info h6 {
            margin-bottom: 0.25rem !important;
            font-weight: 600;
            color: #1f2937;
        }

        .assignment-user-info p {
            margin-bottom: 0 !important;
            font-size: 0.875rem;
            color: #6b7280;
        }

        .assignment-badges {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .assignment-actions {
            display: flex;
            gap: 0.25rem;
        }

        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .collapse-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: all 0.2s ease;
            z-index: 10;
        }

        .collapse-btn:hover {
            background: #5a67d8;
            transform: scale(1.1);
        }

        .btn-action {
            transition: all 0.2s ease;
        }

        .btn-action:hover {
            transform: translateY(-2px);
        }

        /* AJAX Loading States */
        .btn-loading {
            position: relative;
            pointer-events: none;
            opacity: 0.7;
        }

        .btn-loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .btn-loading .btn-text {
            opacity: 0;
        }

        /* Success/Error Messages */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }

        .toast-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .toast-error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .template-card-body, .subscription-body {
                padding: 1rem;
            }
            
            .assignment-badges {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .assignment-actions {
                margin-top: 0.5rem;
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

        :root.dark .manage-services-hero-content h1,
        :root.dark .manage-services-hero-content p {
            color: white !important;
            position: relative;
            z-index: 2;
        }
        :root.dark .template-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .template-card-header {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .template-card-header h4 {
            color: #f1f5f9 !important;
        }

        :root.dark .company-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .company-card:hover,
        :root.dark .company-card.expanded {
            border-color: #8b5cf6 !important;
        }

        :root.dark .company-card h5,
        :root.dark .company-card .card-title {
            color: #f1f5f9 !important;
        }

        :root.dark .company-card .badge {
            background: #334155 !important;
            color: #cbd5e1 !important;
        }

        :root.dark .subscription-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .subscription-header {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%) !important;
            border-color: #334155 !important;
        }

        :root.dark .subscription-header h5,
        :root.dark .subscription-header h6 {
            color: #a78bfa !important;
        }

        :root.dark .subscription-body {
            color: #e2e8f0 !important;
        }

        :root.dark .subscription-body h6 {
            color: #f1f5f9 !important;
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
            border-color: #8b5cf6 !important;
        }

        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark small {
            color: #94a3b8 !important;
        }

        /* Assignment cards dark mode */
        :root.dark .assignment-card {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .assignment-card:hover {
            background: rgba(139, 92, 246, 0.05) !important;
            border-color: #8b5cf6 !important;
        }

        :root.dark .assignment-card.unassigned {
            background: rgba(251, 191, 36, 0.1) !important;
            border-color: #fbbf24 !important;
        }

        :root.dark .assignment-user-info h6 {
            color: #f1f5f9 !important;
        }

        :root.dark .assignment-user-info p {
            color: #94a3b8 !important;
        }

        /* Modal dark mode */
        :root.dark .modal-content {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .modal-header {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%) !important;
            border-color: #334155 !important;
        }

        :root.dark .modal-title {
            color: #a78bfa !important;
        }

        :root.dark .modal-body {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .modal-footer {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .btn-close {
            filter: invert(1) !important;
        }

        :root.dark .form-label {
            color: #cbd5e1 !important;
        }

        :root.dark .form-text {
            color: #94a3b8 !important;
        }

        /* Delete confirmation modal dark mode */
        :root.dark #deleteConfirmModal .modal-content {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark #deleteConfirmModal .modal-header {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%) !important;
            border-color: #991b1b !important;
        }

        :root.dark #deleteConfirmModal .modal-body {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark #deleteConfirmModal .modal-body h5 {
            color: #f1f5f9 !important;
        }

        :root.dark #deleteConfirmModal .modal-footer {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        /* Stats dark mode */
        :root.dark .stat-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .stat-number {
            color: #a78bfa !important;
        }

        :root.dark .stat-label {
            color: #94a3b8 !important;
        }

        :root.dark .stat-icon {
            background: var(--primary-gradient) !important;
        }
    </style>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="manage-services-hero-content text-center text-white py-5">
            <h1 class="display-4 fw-bold mb-3">
                <i class="bi bi-person-gear me-3"></i>Service Allocation
            </h1>
            <p class="lead mb-4">Manage product licenses and assignments for your companies</p>
            <div class="hero-badge">
                <span class="badge bg-light text-primary px-3 py-2">Account Manager</span>
            </div>
        </div>
    </div>
</header>

<!-- Toast Container for AJAX Messages -->
<div class="toast-container" id="toastContainer"></div>

<!-- Overlapping Cards Container -->
<div class="container py-5 overlap-cards">
    <!-- Template-style Companies Card -->
    <div class="template-card">
        <div class="template-card-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-1">
                        <i class="bi bi-building me-2"></i>
                        Your Companies with Active Subscriptions
                    </h4>
                    <p class="text-muted mb-0">Click on a company to manage license allocations</p>
                </div>
                <span class="badge bg-primary fs-6 px-3 py-2">
                    <?= count($companies) ?> Companies
                </span>
            </div>
        </div>
        
        <div class="template-card-body">
            <?php if (empty($companies)): ?>
                <!-- Empty State -->
                <div class="text-center py-5">
                    <i class="bi bi-building text-muted" style="font-size: 5rem; opacity: 0.3;"></i>
                    <h4 class="text-muted mb-3">No Companies Found</h4>
                    <p class="text-muted mb-3">You don't have access to any companies with active subscriptions yet.</p>
                    <p class="text-muted mb-4">Contact your administrator if you need access to manage licenses.</p>
                    <a href="/members/dashboard.php" class="btn btn-primary btn-lg rounded-pill">
                        <i class="bi bi-house me-2"></i>Back to Dashboard
                    </a>
                </div>
            <?php else: ?>
                <!-- Companies Grid -->
                <div class="row g-4" id="companiesGrid">
                    <?php foreach ($companies as $comp): ?>
                        <div class="col-md-6 col-xl-4">
                            <div class="card h-100 company-card" data-company-id="<?= $comp['id'] ?>" onclick="toggleCompany(<?= $comp['id'] ?>, '<?= htmlspecialchars($comp['name']) ?>')">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <h5 class="card-title mb-0 fw-bold">
                                            <?= htmlspecialchars($comp['name']) ?>
                                        </h5>
                                        <span class="badge bg-<?= $comp['relationship_type'] === 'Primary' ? 'primary' : 'warning' ?> rounded-pill">
                                            <?= $comp['relationship_type'] ?>
                                        </span>
                                    </div>
                                    
                                    <?php if ($comp['phone'] || $comp['address']): ?>
                                        <div class="text-muted small mb-3">
                                            <?php if ($comp['phone']): ?>
                                                <div class="mb-1">
                                                    <i class="bi bi-telephone me-1"></i>
                                                    <?= htmlspecialchars($comp['phone']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($comp['address']): ?>
                                                <div>
                                                    <i class="bi bi-geo-alt me-1"></i>
                                                    <?= htmlspecialchars($comp['address']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- License Usage Progress -->
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <small class="text-muted fw-medium">License Usage</small>
                                            <small class="text-muted">
                                                <?= $comp['assigned_licenses'] ?> / <?= $comp['total_licenses'] ?>
                                            </small>
                                        </div>
                                        <div class="usage-bar">
                                            <?php 
                                            $usage_percent = $comp['total_licenses'] > 0 ? ($comp['assigned_licenses'] / $comp['total_licenses']) * 100 : 0;
                                            $usage_class = $usage_percent > 90 ? 'danger' : ($usage_percent > 75 ? 'warning' : 'success');
                                            ?>
                                            <div class="usage-fill <?= $usage_class ?>" style="width: <?= $usage_percent ?>%"></div>
                                        </div>
                                    </div>
                                    
                                    <!-- Company Stats -->
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="badge bg-light text-dark">
                                            <?= $comp['subscription_count'] ?> Subscriptions
                                        </span>
                                        <div class="loading-indicator" style="display: none;">
                                            <div class="loading-spinner"></div>
                                        </div>
                                        <i class="bi bi-chevron-down text-primary fs-5 expand-icon"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Dynamic Company Expansion Areas -->
                <div id="companyExpansions"></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Assign Product Modal -->
<div class="modal fade" id="assignProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="assignProductForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-person-plus me-2"></i>
                    Assign Product to Member
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="subscription_id" id="assign_subscription_id">
                <input type="hidden" name="ajax" value="assign_product">
                
                <div class="mb-3">
                    <label class="form-label">Select Member</label>
                    <select name="user_id" id="assign_user_select" class="form-select" required>
                        <option value="">Select a member...</option>
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
                    <div class="form-text">
                        <small class="text-muted">Number of licenses to assign to this member</small>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Assignment Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes about this assignment"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="assignProductBtn">
                    <span class="btn-text">
                        <i class="bi bi-person-plus me-2"></i>Assign License
                    </span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Assignment Modal -->
<div class="modal fade" id="editAssignmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="editAssignmentForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil me-2"></i>
                    Edit License Assignment
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="assignment_id" id="edit_assignment_id">
                <input type="hidden" name="ajax" value="edit_assignment">
                
                <div class="mb-3">
                    <label class="form-label">License Quantity</label>
                    <input type="number" name="new_quantity" id="edit_quantity" class="form-control" min="1" required>
                    <div class="form-text">
                        <small class="text-muted" id="edit_quantity_help">
                            <i class="bi bi-info-circle me-1"></i>
                            Available licenses: <span id="edit_available_licenses"></span>
                        </small>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Assignment Notes</label>
                    <textarea name="notes" id="edit_notes" class="form-control" rows="3" placeholder="Optional notes about this assignment"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="editAssignmentBtn">
                    <span class="btn-text">
                        <i class="bi bi-check-circle me-2"></i>Update Assignment
                    </span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Confirm License Removal
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center py-3">
                    <i class="bi bi-person-dash text-danger" style="font-size: 4rem;"></i>
                    <h5 class="mt-3 mb-3">Are you sure you want to remove this license assignment?</h5>
                    <p class="text-muted">This action cannot be undone. The license will be returned to the available pool.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-2"></i>Cancel
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="bi bi-trash me-2"></i>Yes, Remove License
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// License Management with AJAX Operations (No Page Refresh)
(function() {
    'use strict';
    
    let expandedCompanies = new Set();
    let companyData = new Map();
    let currentCompanyUsers = [];
    let currentAssignments = [];
    let currentCompanyId = null;
    
    // Toast notification system
    function showToast(message, type = 'success') {
        const toastContainer = document.getElementById('toastContainer');
        const toastId = 'toast-' + Date.now();
        
        const toast = document.createElement('div');
        toast.id = toastId;
        toast.className = `toast show toast-${type}`;
        toast.innerHTML = `
            <div class="toast-body d-flex align-items-center">
                <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close ms-auto" onclick="document.getElementById('${toastId}').remove()"></button>
            </div>
        `;
        
        toastContainer.appendChild(toast);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (document.getElementById(toastId)) {
                document.getElementById(toastId).remove();
            }
        }, 5000);
    }
    
    // AJAX form submission handler
    function handleAjaxForm(formId, buttonId, onSuccess) {
        const form = document.getElementById(formId);
        const button = document.getElementById(buttonId);
        
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Show loading state
            button.classList.add('btn-loading');
            
            const formData = new FormData(form);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    if (onSuccess) onSuccess();
                    
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(form.closest('.modal'));
                    if (modal) modal.hide();
                    
                    // Refresh the expanded company data
                    if (currentCompanyId) {
                        refreshCompanyData(currentCompanyId);
                    }
                } else {
                    showToast(data.error, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred. Please try again.', 'error');
            })
            .finally(() => {
                // Remove loading state
                button.classList.remove('btn-loading');
            });
        });
    }
    
    // Refresh company data after operations
    function refreshCompanyData(companyId) {
        // Clear cached data
        companyData.delete(companyId);
        
        // Fetch fresh data
        fetch(`?ajax=company_data&company_id=${companyId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    companyData.set(companyId, data);
                    
                    // Update the expansion area
                    const expansion = document.getElementById(`expansion-${companyId}`);
                    if (expansion) {
                        const companyName = data.company.name;
                        expansion.innerHTML = createExpansionArea(companyId, companyName, data);
                        
                        // Update stored data for modals
                        currentCompanyUsers = data.users;
                        currentAssignments = data.assignments;
                    }
                }
            })
            .catch(error => {
                console.error('Error refreshing data:', error);
            });
    }
    
    // Toggle company expansion
    window.toggleCompany = function(companyId, companyName) {
        currentCompanyId = companyId;
        const card = document.querySelector(`[data-company-id="${companyId}"]`);
        const expandIcon = card.querySelector('.expand-icon');
        const loadingIndicator = card.querySelector('.loading-indicator');
        
        if (expandedCompanies.has(companyId)) {
            // Collapse
            collapseCompany(companyId);
        } else {
            // Expand
            expandCompany(companyId, companyName, card, expandIcon, loadingIndicator);
        }
    };
    
    function expandCompany(companyId, companyName, card, expandIcon, loadingIndicator) {
        // Show loading state
        card.classList.add('loading');
        expandIcon.style.display = 'none';
        loadingIndicator.style.display = 'block';
        
        // Check if we already have the data
        if (companyData.has(companyId)) {
            showCompanyData(companyId, companyName, companyData.get(companyId));
            hideLoading(card, expandIcon, loadingIndicator);
            return;
        }
        
        // Fetch company data via AJAX
        fetch(`?ajax=company_data&company_id=${companyId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    companyData.set(companyId, data);
                    showCompanyData(companyId, companyName, data);
                } else {
                    showToast('Failed to load company data: ' + data.error, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to load company data. Please try again.', 'error');
            })
            .finally(() => {
                hideLoading(card, expandIcon, loadingIndicator);
            });
    }
    
    function hideLoading(card, expandIcon, loadingIndicator) {
        card.classList.remove('loading');
        expandIcon.style.display = 'block';
        loadingIndicator.style.display = 'none';
    }
    
    function showCompanyData(companyId, companyName, data) {
        const card = document.querySelector(`[data-company-id="${companyId}"]`);
        const expandIcon = card.querySelector('.expand-icon');
        
        // Update card state
        card.classList.add('expanded');
        expandIcon.classList.remove('bi-chevron-down');
        expandIcon.classList.add('bi-chevron-up');
        expandedCompanies.add(companyId);
        
        // Store current company data for modals
        currentCompanyUsers = data.users;
        currentAssignments = data.assignments;
        
        // Create expansion area
        const expansionHtml = createExpansionArea(companyId, companyName, data);
        
        // Add to DOM
        const expansionsContainer = document.getElementById('companyExpansions');
        const expansionDiv = document.createElement('div');
        expansionDiv.id = `expansion-${companyId}`;
        expansionDiv.className = 'company-expansion';
        expansionDiv.innerHTML = expansionHtml;
        expansionsContainer.appendChild(expansionDiv);
        
        // Animate in
        setTimeout(() => {
            expansionDiv.classList.add('show');
        }, 100);
    }
    
    function collapseCompany(companyId) {
        const card = document.querySelector(`[data-company-id="${companyId}"]`);
        const expandIcon = card.querySelector('.expand-icon');
        const expansion = document.getElementById(`expansion-${companyId}`);
        
        // Update card state
        card.classList.remove('expanded');
        expandIcon.classList.remove('bi-chevron-up');
        expandIcon.classList.add('bi-chevron-down');
        expandedCompanies.delete(companyId);
        currentCompanyId = null;
        
        // Animate out and remove
        if (expansion) {
            expansion.classList.remove('show');
            setTimeout(() => {
                expansion.remove();
            }, 400);
        }
    }
    
    function createExpansionArea(companyId, companyName, data) {
        const { subscriptions, users, assignments } = data;
        
        // Calculate stats
        const totalSubscriptions = subscriptions.length;
        const totalLicenses = subscriptions.reduce((sum, sub) => sum + parseInt(sub.total_quantity), 0);
        const assignedLicenses = subscriptions.reduce((sum, sub) => sum + parseInt(sub.assigned_quantity), 0);
        const availableLicenses = subscriptions.reduce((sum, sub) => sum + parseInt(sub.available_quantity), 0);
        
        return `
        <div class="template-card">
            <button class="collapse-btn" onclick="toggleCompany(${companyId}, '${companyName.replace(/'/g, "\\'")}')" title="Collapse">
                <i class="bi bi-x"></i>
            </button>
            <div class="template-card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-1">
                            <i class="bi bi-building me-2"></i>
                            ${companyName} - License Management
                        </h4>
                        <p class="text-muted mb-0">Manage product licenses and member assignments</p>
                    </div>
                </div>
            </div>
            
            <div class="template-card-body">
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon bg-primary bg-gradient">
                            <i class="bi bi-box"></i>
                        </div>
                        <div class="stat-number text-primary">${totalSubscriptions}</div>
                        <div class="stat-label">Active Subscriptions</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon bg-info bg-gradient">
                            <i class="bi bi-key"></i>
                        </div>
                        <div class="stat-number text-info">${totalLicenses}</div>
                        <div class="stat-label">Total Licenses</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon bg-success bg-gradient">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="stat-number text-success">${assignedLicenses}</div>
                        <div class="stat-label">Assigned Licenses</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon bg-warning bg-gradient">
                            <i class="bi bi-clock"></i>
                        </div>
                        <div class="stat-number text-warning">${availableLicenses}</div>
                        <div class="stat-label">Available Licenses</div>
                    </div>
                </div>
                
                <!-- Subscriptions -->
                ${createSubscriptionsHtml(subscriptions, assignments, users)}
            </div>
        </div>
        `;
    }
    
    function createSubscriptionsHtml(subscriptions, assignments, users) {
        if (subscriptions.length === 0) {
            return `
            <div class="template-card">
                <div class="template-card-body">
                    <div class="text-center py-5">
                        <i class="bi bi-box text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                        <h4 class="text-muted mb-3">No Active Subscriptions</h4>
                        <p class="text-muted">This company doesn't have any active product subscriptions yet.</p>
                    </div>
                </div>
            </div>
            `;
        }
        
        return subscriptions.map(subscription => {
            const subscriptionAssignments = assignments.filter(a => a.subscription_id == subscription.id);
            const usagePercent = subscription.total_quantity > 0 ? (subscription.assigned_quantity / subscription.total_quantity) * 100 : 0;
            const usageClass = usagePercent > 90 ? 'danger' : (usagePercent > 75 ? 'warning' : 'success');
            
            return `
            <div class="subscription-card">
                <div class="subscription-header">
                    <div class="d-flex justify-content-between align-items-center w-100">
                        <div>
                            <h5 class="mb-1 fw-bold">
                                <i class="bi bi-box me-2"></i>
                                ${subscription.product_name || subscription.bundle_name}
                            </h5>
                            <p class="text-muted mb-0">
                                ${subscription.unit_type.replace('_', ' ').charAt(0).toUpperCase() + subscription.unit_type.replace('_', ' ').slice(1)} • 
                                £${parseFloat(subscription.unit_price).toFixed(2)} each
                            </p>
                        </div>
                        <button class="btn btn-primary" onclick="assignProduct(${subscription.id})">
                            <i class="bi bi-person-plus me-2"></i>Assign to Member
                        </button>
                    </div>
                </div>
                
                <div class="subscription-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-bold mb-0">License Usage</h6>
                                    <span class="text-muted">
                                        ${subscription.assigned_quantity} / ${subscription.total_quantity}
                                    </span>
                                </div>
                                <div class="usage-bar mb-3">
                                    <div class="usage-fill ${usageClass}" style="width: ${usagePercent}%"></div>
                                </div>
                                <div class="d-flex gap-2">
                                    <span class="badge bg-success">${subscription.assigned_quantity} Assigned</span>
                                    <span class="badge bg-warning">${subscription.available_quantity} Available</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-8">
                            <h6 class="fw-bold mb-3">License Assignments</h6>
                            ${createAssignmentsHtml(subscriptionAssignments, subscription)}
                        </div>
                    </div>
                </div>
            </div>
            `;
        }).join('');
    }
    
    function createAssignmentsHtml(assignments, subscription) {
        if (assignments.length === 0) {
            return `
            <div class="assignment-card unassigned">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="assignment-user-info">
                        <h6>All licenses unassigned</h6>
                        <p>${subscription.total_quantity} licenses available for assignment</p>
                    </div>
                    <span class="badge bg-warning">Unassigned</span>
                </div>
            </div>
            `;
        }
        
        let html = assignments.map(assignment => `
        <div class="assignment-card">
            <div class="d-flex justify-content-between align-items-center">
                <div class="assignment-user-info">
                    ${assignment.user_id ? `
                        <h6>${assignment.username}</h6>
                        <p>${assignment.email}</p>
                    ` : `
                        <h6>Unassigned Pool</h6>
                        <p>Available for assignment</p>
                    `}
                </div>
                <div class="assignment-badges">
                    <span class="badge bg-info">${assignment.assigned_quantity} licenses</span>
                    <span class="badge bg-${assignment.status === 'assigned' ? 'success' : 'secondary'}">
                        ${assignment.status.charAt(0).toUpperCase() + assignment.status.slice(1)}
                    </span>
                    <div class="assignment-actions">
                        <button class="btn btn-outline-warning btn-sm btn-action" onclick="editAssignment(${assignment.id}, ${assignment.assigned_quantity}, '${(assignment.notes || '').replace(/'/g, "\\'")}', ${assignment.subscription_id}, ${subscription.total_quantity})" title="Edit Assignment">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-outline-danger btn-sm btn-action" onclick="removeAssignment(${assignment.id})" title="Remove Assignment">
                            <i class="bi bi-person-dash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        `).join('');
        
        if (subscription.available_quantity > 0) {
            html += `
            <div class="assignment-card unassigned">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="assignment-user-info">
                        <h6>${subscription.available_quantity} licenses unassigned</h6>
                        <p>Available for assignment to members</p>
                    </div>
                    <span class="badge bg-warning">Unassigned</span>
                </div>
            </div>
            `;
        }
        
        return html;
    }
    
    // WORKING ASSIGN PRODUCT FUNCTION
    window.assignProduct = function(subscriptionId) {
        console.log('Assigning product:', subscriptionId);
        
        document.getElementById('assign_subscription_id').value = subscriptionId;
        
        // Get users who already have this product assigned
        const assignedUserIds = currentAssignments
            .filter(assignment => assignment.subscription_id == subscriptionId && assignment.user_id)
            .map(assignment => assignment.user_id);
        
        // Filter out users who already have this product
        const availableUsers = currentCompanyUsers.filter(user => !assignedUserIds.includes(user.id));
        
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
        const availableUserCount = availableUsers.length;
        
        if (availableUserCount === 0) {
            userCountSpan.innerHTML = '<span style="color: #ef4444;"><i class="bi bi-exclamation-triangle me-1"></i>All members already have this product assigned</span>';
        } else {
            userCountSpan.innerHTML = `<span style="color: #059669;"><i class="bi bi-check-circle me-1"></i>Showing ${availableUserCount} available members</span>`;
        }
        
        const modal = new bootstrap.Modal(document.getElementById('assignProductModal'));
        modal.show();
    };
    
    // WORKING EDIT ASSIGNMENT FUNCTION
    window.editAssignment = function(assignmentId, currentQuantity, notes, subscriptionId, totalLicenses) {
        console.log('Editing assignment:', assignmentId);
        
        document.getElementById('edit_assignment_id').value = assignmentId;
        document.getElementById('edit_quantity').value = currentQuantity;
        document.getElementById('edit_notes').value = notes;
        
        const availableLicenses = totalLicenses - currentQuantity;
        document.getElementById('edit_available_licenses').textContent = availableLicenses;
        document.getElementById('edit_quantity').max = totalLicenses;
        
        const modal = new bootstrap.Modal(document.getElementById('editAssignmentModal'));
        modal.show();
    };
    
    // AJAX REMOVE ASSIGNMENT FUNCTION - Using Modal
    let assignmentToDelete = null;

    window.removeAssignment = function(assignmentId) {
        // Store the assignment ID
        assignmentToDelete = assignmentId;

        // Show the delete confirmation modal
        const modal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
        modal.show();
    };

    // Handle the actual deletion when user confirms
    function performDelete() {
        if (!assignmentToDelete) return;

        const formData = new FormData();
        formData.append('ajax', 'unassign_product');
        formData.append('assignment_id', assignmentToDelete);

        // Show loading state on button
        const confirmBtn = document.getElementById('confirmDeleteBtn');
        const originalHtml = confirmBtn.innerHTML;
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Removing...';

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');

                // Close the modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal'));
                if (modal) modal.hide();

                // Refresh the expanded company data
                if (currentCompanyId) {
                    refreshCompanyData(currentCompanyId);
                }
            } else {
                showToast(data.error, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('An error occurred. Please try again.', 'error');
        })
        .finally(() => {
            // Reset button state
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = originalHtml;
            assignmentToDelete = null;
        });
    }
    
    // Initialize AJAX form handlers
    function initializeAjaxForms() {
        // Handle assign product form
        handleAjaxForm('assignProductForm', 'assignProductBtn', () => {
            // Reset form
            document.getElementById('assignProductForm').reset();
        });
        
        // Handle edit assignment form
        handleAjaxForm('editAssignmentForm', 'editAssignmentBtn', () => {
            // Reset form
            document.getElementById('editAssignmentForm').reset();
        });
    }
    
    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        console.log('License Management with AJAX operations (no page refresh) initialized');

        // Initialize AJAX forms
        initializeAjaxForms();

        // Add event listener for delete confirmation button
        document.getElementById('confirmDeleteBtn').addEventListener('click', performDelete);

        // Add animations to initial cards
        const cards = document.querySelectorAll('.company-card');
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';

            setTimeout(() => {
                card.style.transition = 'all 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 100);
        });
    });
})();
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
