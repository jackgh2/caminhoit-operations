<?php
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

// Check if user has access (supported_user or account_manager)
if (!in_array($user['role'], ['supported_user', 'account_manager', 'support_consultant', 'accountant', 'administrator'])) {
    header('Location: /members/dashboard.php');
    exit;
}

$user_id = $user['id'];

// Get user's company assignments (both primary company and multi-company access)
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.name, c.phone, c.address,
           CASE 
               WHEN u.company_id = c.id THEN 'Primary'
               ELSE 'Multi-Company'
           END as relationship_type
    FROM companies c
    JOIN users u ON (u.company_id = c.id OR u.id IN (
        SELECT cu.user_id FROM company_users cu WHERE cu.company_id = c.id
    ))
    WHERE u.id = ? AND c.is_active = 1
    ORDER BY relationship_type ASC, c.name ASC
");
$stmt->execute([$user_id]);
$user_companies = $stmt->fetchAll();

// Get all product assignments for this user across all their companies
$stmt = $pdo->prepare("
    SELECT pa.*, 
           cs.quantity as total_quantity,
           cs.unit_price,
           cs.start_date,
           cs.end_date,
           cs.status as subscription_status,
           p.name as product_name,
           p.unit_type,
           p.description as product_description,
           b.name as bundle_name,
           b.description as bundle_description,
           c.id as company_id,
           c.name as company_name,
           assigned_by_user.username as assigned_by_name
    FROM product_assignments pa
    JOIN client_subscriptions cs ON pa.subscription_id = cs.id
    JOIN companies c ON cs.company_id = c.id
    LEFT JOIN products p ON cs.product_id = p.id
    LEFT JOIN service_bundles b ON cs.bundle_id = b.id
    LEFT JOIN users assigned_by_user ON pa.assigned_by = assigned_by_user.id
    WHERE pa.user_id = ?
    AND cs.status = 'active'
    AND pa.status = 'assigned'
    ORDER BY c.name ASC, p.name ASC, b.name ASC
");
$stmt->execute([$user_id]);
$assignments = $stmt->fetchAll();

// Group assignments by company
$assignments_by_company = [];
foreach ($assignments as $assignment) {
    $company_id = $assignment['company_id'];
    if (!isset($assignments_by_company[$company_id])) {
        $assignments_by_company[$company_id] = [
            'company' => [
                'id' => $company_id,
                'name' => $assignment['company_name']
            ],
            'assignments' => []
        ];
    }
    $assignments_by_company[$company_id]['assignments'][] = $assignment;
}

// Get summary statistics
$total_assignments = count($assignments);
$active_assignments = count(array_filter($assignments, function($a) { 
    return $a['subscription_status'] === 'active'; 
}));
$total_licenses = array_sum(array_column($assignments, 'assigned_quantity'));

// Helper functions
function getStatusBadgeClass($status) {
    switch(strtolower($status)) {
        case 'active': return 'badge-success';
        case 'expired': return 'badge-danger';
        case 'suspended': return 'badge-warning';
        default: return 'badge-secondary';
    }
}

function formatDate($date) {
    return $date ? date('M j, Y', strtotime($date)) : 'N/A';
}

$page_title = "My Services | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>
    <style>

        /* Enhanced Hover Effects - Higher Specificity */
        .company-section {
            background: white !important;
            border-radius: var(--border-radius) !important;
            box-shadow: var(--card-shadow) !important;
            margin-bottom: 2rem !important;
            border: none !important;
            overflow: hidden !important;
            position: relative !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            transform: translateY(0) !important;
        }

        .company-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
            z-index: 1;
        }

        .company-section:hover {
            transform: translateY(-8px) !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
            border-color: #667eea !important;
        }

        .company-header {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 1.5rem;
            position: relative;
            z-index: 2;
        }

        .company-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
            display: flex;
            align-items: center;
        }

        .company-type-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .company-type-primary {
            background: var(--info-gradient);
            color: white;
        }

        .company-type-multicompany {
            background: var(--warning-gradient);
            color: white;
        }

        .license-card {
            background: white !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: var(--border-radius) !important;
            padding: 1.5rem !important;
            margin: 1rem 1.5rem 0 !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            position: relative !important;
            overflow: hidden !important;
            transform: translateY(0) !important;
        }

        .license-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary-gradient);
            z-index: 1;
        }

        .license-card:last-child {
            margin-bottom: 1.5rem !important;
        }

        .license-card:hover {
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.12) !important;
            transform: translateY(-5px) translateX(5px) !important;
            border-color: #667eea !important;
            background: rgba(102, 126, 234, 0.02) !important;
        }

        .license-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 0.5rem 0;
            position: relative;
            z-index: 2;
        }

        .license-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
            position: relative;
            z-index: 2;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 0.75rem;
            color: #64748B;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }

        .meta-value {
            font-size: 0.875rem;
            color: #374151;
            font-weight: 500;
            display: flex;
            align-items: center;
        }

        .badge-success { 
            background: var(--success-gradient) !important; 
            color: white !important; 
            border: none !important;
            padding: 0.4rem 0.8rem !important;
            border-radius: 50px !important;
            font-weight: 600 !important;
        }
        .badge-warning { 
            background: var(--warning-gradient) !important; 
            color: white !important;
            border: none !important;
            padding: 0.4rem 0.8rem !important;
            border-radius: 50px !important;
            font-weight: 600 !important;
        }
        .badge-danger { 
            background: var(--danger-gradient) !important; 
            color: white !important;
            border: none !important;
            padding: 0.4rem 0.8rem !important;
            border-radius: 50px !important;
            font-weight: 600 !important;
        }
        .badge-info { 
            background: var(--info-gradient) !important; 
            color: white !important;
            border: none !important;
            padding: 0.4rem 0.8rem !important;
            border-radius: 50px !important;
            font-weight: 600 !important;
        }
        .badge-secondary { 
            background: var(--dark-gradient) !important; 
            color: white !important;
            border: none !important;
            padding: 0.4rem 0.8rem !important;
            border-radius: 50px !important;
            font-weight: 600 !important;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white !important;
            border-radius: var(--border-radius) !important;
            box-shadow: var(--card-shadow) !important;
            padding: 2rem 1.5rem !important;
            text-align: center !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            border: none !important;
            position: relative !important;
            overflow: hidden !important;
            cursor: pointer !important;
            transform: translateY(0) !important;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
            z-index: 1;
        }

        .stat-card:hover {
            transform: translateY(-10px) !important;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15) !important;
            background: rgba(102, 126, 234, 0.02) !important;
        }

        .stat-card .icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin: 0 auto 1rem;
            position: relative;
            z-index: 2;
            transition: all 0.3s ease;
        }

        .stat-card:hover .icon {
            transform: scale(1.1) rotateY(10deg);
        }

        .stat-card .icon.primary { background: var(--primary-gradient); }
        .stat-card .icon.success { background: var(--success-gradient); }
        .stat-card .icon.info { background: var(--info-gradient); }

        .stat-card .value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 2;
            transition: all 0.3s ease;
        }

        .stat-card:hover .value {
            color: #667eea;
            transform: scale(1.05);
        }

        .stat-card .label {
            color: #64748B;
            font-size: 0.875rem;
            font-weight: 500;
            position: relative;
            z-index: 2;
        }

        .license-quantity {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }

        .quantity-badge {
            background: var(--primary-gradient);
            color: white;
            padding: 0.4rem 0.8rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .license-description {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #e2e8f0;
            color: #64748B;
            font-size: 0.875rem;
            line-height: 1.5;
            position: relative;
            z-index: 2;
        }

        .license-actions {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
            position: relative;
            z-index: 2;
        }

        .role-indicator {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 0.5rem;
            background: var(--info-gradient);
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
            transition: var(--transition);
        }

        .empty-state::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .empty-state:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .empty-state i {
            font-size: 4rem;
            color: #d1d5db;
            margin-bottom: 1.5rem;
            transition: var(--transition);
        }

        .empty-state:hover i {
            color: #667eea;
            transform: scale(1.1);
        }

        .empty-state h3 {
            color: #6b7280;
            margin-bottom: 1rem;
        }

        .btn-enhanced {
            border-radius: 50px !important;
            padding: 0.5rem 1.5rem !important;
            font-weight: 600 !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            border: none !important;
            position: relative !important;
            overflow: hidden !important;
            transform: translateY(0) !important;
        }

        .btn-enhanced::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            transition: all 0.3s ease;
            transform: translate(-50%, -50%);
            z-index: 1;
        }

        .btn-enhanced:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-enhanced span {
            position: relative;
            z-index: 2;
        }

        .btn-enhanced.btn-outline-primary {
            background: transparent !important;
            border: 2px solid #667eea !important;
            color: #667eea !important;
        }

        .btn-enhanced.btn-outline-primary:hover {
            background: var(--primary-gradient) !important;
            color: white !important;
            border-color: transparent !important;
            transform: translateY(-3px) !important;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4) !important;
        }

        /* Fade-in animation utilities */
        .fade-in {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.6s ease;
        }

        .fade-in.visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* Enhanced breadcrumb */
        .breadcrumb-enhanced {
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 0.75rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--card-shadow-light);
            transition: var(--transition);
        }

        .breadcrumb-enhanced:hover {
            transform: translateY(-2px);
            box-shadow: var(--card-shadow);
        }

        @media (max-width: 768px) {
            .license-meta {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .license-quantity {
                flex-direction: column;
                align-items: flex-start;
            }

            .company-section:hover {
                transform: translateY(-5px) !important;
            }

            .license-card:hover {
                transform: translateY(-3px) !important;
            }

            .stat-card:hover {
                transform: translateY(-5px) !important;
            }
        }

        /* Force hover effects with JavaScript backup */
        .hover-effect {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
        }

        .hover-effect:hover {
            transform: translateY(-8px) !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15) !important;
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

        :root.dark .services-hero-title,
        :root.dark .services-hero-subtitle {
            color: white !important;
            position: relative;
            z-index: 2;
        }
        :root.dark .stat-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .stat-card .value {
            color: #a78bfa !important;
        }

        :root.dark .stat-card .label {
            color: #cbd5e1 !important;
        }

        :root.dark .stat-card .icon {
            background: var(--primary-gradient) !important;
        }

        :root.dark .stat-card:hover .value {
            color: #c4b5fd !important;
        }

        :root.dark .company-section {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .company-header {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .company-header h3 {
            color: #f1f5f9 !important;
        }

        :root.dark .license-card {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .license-card:hover {
            border-color: #8b5cf6 !important;
            background: rgba(139, 92, 246, 0.05) !important;
        }

        :root.dark .license-title {
            color: #f1f5f9 !important;
        }

        :root.dark .meta-value,
        :root.dark .meta-label {
            color: #cbd5e1 !important;
        }

        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark small {
            color: #94a3b8 !important;
        }

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

        :root.dark .empty-state {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .empty-state h3,
        :root.dark .empty-state p {
            color: #cbd5e1 !important;
        }

        :root.dark .breadcrumb-enhanced {
            background: rgba(30, 41, 59, 0.9) !important;
        }
    </style>

<!-- Hero Section - Using Theme -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="services-hero-content">
            <h1 class="services-hero-title">
                <i class="bi bi-key me-2"></i>
                My Services & Licenses
            </h1>
            <p class="services-hero-subtitle">
                View and manage your assigned software licenses and product access across all your companies.
            </p>
            <div class="services-hero-actions">
                <a href="#services-overview" class="btn c-btn-primary">
                    <i class="bi bi-arrow-down me-1"></i>
                    View Services
                </a>
                <a href="/members/raise-ticket.php" class="btn c-btn-ghost">
                    <i class="bi bi-headset me-1"></i>
                    Get Support
                </a>
            </div>
        </div>
    </div>
</header>

<div class="container py-5 content-overlap" id="services-overview">
    <!-- Summary Statistics -->
    <div class="stats-grid fade-in">
        <div class="stat-card hover-effect">
            <div class="icon primary">
                <i class="bi bi-box"></i>
            </div>
            <div class="value"><?= $total_assignments ?></div>
            <div class="label">Total Products</div>
        </div>
        
        <div class="stat-card hover-effect">
            <div class="icon success">
                <i class="bi bi-check-circle"></i>
            </div>
            <div class="value"><?= $active_assignments ?></div>
            <div class="label">Active Licenses</div>
        </div>
        
        <div class="stat-card hover-effect">
            <div class="icon info">
                <i class="bi bi-key"></i>
            </div>
            <div class="value"><?= $total_licenses ?></div>
            <div class="label">License Count</div>
        </div>
    </div>

    <!-- Company Sections -->
    <?php if (empty($assignments_by_company)): ?>
        <div class="empty-state fade-in hover-effect">
            <i class="bi bi-key"></i>
            <h3>No Licenses Assigned</h3>
            <p class="mb-3">You don't have any product licenses assigned to your account yet.</p>
            <p class="text-muted mb-4">Contact your administrator if you need access to specific products.</p>
            <a href="/members/raise-ticket.php" class="btn btn-enhanced btn-outline-primary">
                <span><i class="bi bi-headset me-2"></i>Contact Support</span>
            </a>
        </div>
    <?php else: ?>
        <?php foreach ($assignments_by_company as $company_data): ?>
            <?php 
            $company = $company_data['company'];
            $company_assignments = $company_data['assignments'];
            
            // Get company relationship type
            $relationship_type = 'Primary';
            foreach ($user_companies as $uc) {
                if ($uc['id'] == $company['id']) {
                    $relationship_type = $uc['relationship_type'];
                    break;
                }
            }
            ?>
            
            <div class="company-section fade-in hover-effect">
                <div class="company-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3>
                                <i class="bi bi-building me-2"></i>
                                <?= htmlspecialchars($company['name']) ?>
                                <span class="company-type-badge company-type-<?= strtolower(str_replace('-', '', $relationship_type)) ?>">
                                    <?= $relationship_type ?>
                                </span>
                            </h3>
                        </div>
                        <div>
                            <span class="badge badge-info">
                                <?= count($company_assignments) ?> Service<?= count($company_assignments) !== 1 ? 's' : '' ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php foreach ($company_assignments as $assignment): ?>
                    <div class="license-card hover-effect">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <h4 class="license-title">
                                    <i class="bi bi-box me-2"></i>
                                    <?= htmlspecialchars($assignment['product_name'] ?: $assignment['bundle_name']) ?>
                                </h4>
                                <div class="license-quantity">
                                    <span class="quantity-badge">
                                        <?= $assignment['assigned_quantity'] ?> <?= ucfirst(str_replace('_', ' ', $assignment['unit_type'])) ?>
                                    </span>
                                    <span class="badge <?= getStatusBadgeClass($assignment['subscription_status']) ?>">
                                        <?= ucfirst($assignment['subscription_status']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="license-meta">
                            <div class="meta-item">
                                <span class="meta-label">License Type</span>
                                <span class="meta-value">
                                    <i class="bi bi-tag me-1"></i>
                                    <?= ucfirst(str_replace('_', ' ', $assignment['unit_type'])) ?>
                                </span>
                            </div>
                            
                            <div class="meta-item">
                                <span class="meta-label">Unit Price</span>
                                <span class="meta-value">
                                    <i class="bi bi-currency-pound me-1"></i>
                                    £<?= number_format($assignment['unit_price'], 2) ?>
                                </span>
                            </div>
                            
                            <div class="meta-item">
                                <span class="meta-label">Start Date</span>
                                <span class="meta-value">
                                    <i class="bi bi-calendar-event me-1"></i>
                                    <?= formatDate($assignment['start_date']) ?>
                                </span>
                            </div>
                            
                            <div class="meta-item">
                                <span class="meta-label">End Date</span>
                                <span class="meta-value">
                                    <i class="bi bi-calendar-x me-1"></i>
                                    <?= formatDate($assignment['end_date']) ?>
                                </span>
                            </div>
                            
                            <div class="meta-item">
                                <span class="meta-label">Assigned By</span>
                                <span class="meta-value">
                                    <i class="bi bi-person me-1"></i>
                                    <?= htmlspecialchars($assignment['assigned_by_name'] ?: 'System') ?>
                                </span>
                            </div>
                            
                            <div class="meta-item">
                                <span class="meta-label">Assigned Date</span>
                                <span class="meta-value">
                                    <i class="bi bi-clock me-1"></i>
                                    <?= formatDate($assignment['assigned_at']) ?>
                                </span>
                            </div>
                        </div>

                        <?php if ($assignment['product_description'] || $assignment['bundle_description'] || $assignment['notes']): ?>
                            <div class="license-description">
                                <?php if ($assignment['product_description'] || $assignment['bundle_description']): ?>
                                    <p><strong>Product Description:</strong></p>
                                    <p><?= nl2br(htmlspecialchars($assignment['product_description'] ?: $assignment['bundle_description'])) ?></p>
                                <?php endif; ?>
                                
                                <?php if ($assignment['notes']): ?>
                                    <p><strong>Assignment Notes:</strong></p>
                                    <p><?= nl2br(htmlspecialchars($assignment['notes'])) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="license-actions">
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-enhanced btn-outline-primary btn-sm" onclick="ServicesManager.showLicenseDetails(<?= htmlspecialchars(json_encode($assignment)) ?>)">
                                    <span><i class="bi bi-info-circle me-1"></i>View Details</span>
                                </button>
                                <?php if ($assignment['subscription_status'] === 'active'): ?>
                                    <a href="/members/raise-ticket.php?product=<?= urlencode($assignment['product_name'] ?: $assignment['bundle_name']) ?>" class="btn btn-enhanced btn-outline-primary btn-sm">
                                        <span><i class="bi bi-headset me-1"></i>Get Support</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- License Details Modal -->
<div class="modal fade" id="licenseDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-info-circle me-2"></i>
                    Service Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="licenseDetailsContent">
                <!-- Content will be populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ENHANCED JAVASCRIPT WITH HOVER EFFECTS -->
<script>
// Create namespaced object to avoid conflicts
window.ServicesManager = (function() {
    'use strict';
    
    // Private variables
    let initialized = false;
    
    // Public methods
    function showLicenseDetails(assignment) {
        const modal = new bootstrap.Modal(document.getElementById('licenseDetailsModal'));
        const content = document.getElementById('licenseDetailsContent');
        
        const statusBadgeClass = {
            'active': 'badge-success',
            'expired': 'badge-danger',
            'suspended': 'badge-warning'
        }[assignment.subscription_status] || 'badge-secondary';
        
        content.innerHTML = `
            <div class="row">
                <div class="col-md-6">
                    <h6 class="text-muted mb-3">PRODUCT INFORMATION</h6>
                    <div class="mb-3">
                        <strong>Product Name:</strong><br>
                        <i class="bi bi-box me-2"></i>${assignment.product_name || assignment.bundle_name}
                    </div>
                    <div class="mb-3">
                        <strong>License Type:</strong><br>
                        <i class="bi bi-tag me-2"></i>${assignment.unit_type.replace('_', ' ').charAt(0).toUpperCase() + assignment.unit_type.replace('_', ' ').slice(1)}
                    </div>
                    <div class="mb-3">
                        <strong>Quantity Assigned:</strong><br>
                        <span class="badge bg-primary">${assignment.assigned_quantity} licenses</span>
                    </div>
                    <div class="mb-3">
                        <strong>Unit Price:</strong><br>
                        <i class="bi bi-currency-pound me-2"></i>£${parseFloat(assignment.unit_price).toFixed(2)} per ${assignment.unit_type.replace('_', ' ')}
                    </div>
                </div>
                <div class="col-md-6">
                    <h6 class="text-muted mb-3">SUBSCRIPTION DETAILS</h6>
                    <div class="mb-3">
                        <strong>Status:</strong><br>
                        <span class="badge ${statusBadgeClass}">${assignment.subscription_status.charAt(0).toUpperCase() + assignment.subscription_status.slice(1)}</span>
                    </div>
                    <div class="mb-3">
                        <strong>Start Date:</strong><br>
                        <i class="bi bi-calendar-event me-2"></i>${assignment.start_date ? new Date(assignment.start_date).toLocaleDateString() : 'N/A'}
                    </div>
                    <div class="mb-3">
                        <strong>End Date:</strong><br>
                        <i class="bi bi-calendar-x me-2"></i>${assignment.end_date ? new Date(assignment.end_date).toLocaleDateString() : 'N/A'}
                    </div>
                    <div class="mb-3">
                        <strong>Assigned By:</strong><br>
                        <i class="bi bi-person me-2"></i>${assignment.assigned_by_name || 'System'}
                    </div>
                </div>
            </div>
            ${assignment.product_description || assignment.bundle_description ? `
                <hr>
                <h6 class="text-muted mb-3">PRODUCT DESCRIPTION</h6>
                <p>${(assignment.product_description || assignment.bundle_description).replace(/\n/g, '<br>')}</p>
            ` : ''}
            ${assignment.notes ? `
                <hr>
                <h6 class="text-muted mb-3">ASSIGNMENT NOTES</h6>
                <p>${assignment.notes.replace(/\n/g, '<br>')}</p>
            ` : ''}
        `;
        
        modal.show();
    }
    
    function setupScrollAnimations() {
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, observerOptions);

        // Observe fade-in elements
        document.querySelectorAll('.fade-in').forEach(el => {
            observer.observe(el);
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    }
    
    function enhanceHoverEffects() {
        // Force hover effects for all cards
        const hoverElements = document.querySelectorAll('.company-section, .license-card, .stat-card, .empty-state');
        
        hoverElements.forEach(element => {
            // Add hover class for CSS targeting
            element.classList.add('hover-effect');
            
            // Add mouse enter effect
            element.addEventListener('mouseenter', function() {
                this.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
                
                if (this.classList.contains('stat-card')) {
                    this.style.transform = 'translateY(-10px)';
                    this.style.boxShadow = '0 25px 50px rgba(0, 0, 0, 0.15)';
                    this.style.background = 'rgba(102, 126, 234, 0.02)';
                } else if (this.classList.contains('company-section')) {
                    this.style.transform = 'translateY(-8px)';
                    this.style.boxShadow = '0 20px 40px rgba(0, 0, 0, 0.15)';
                } else if (this.classList.contains('license-card')) {
                    this.style.transform = 'translateY(-5px) translateX(5px)';
                    this.style.boxShadow = '0 15px 30px rgba(0, 0, 0, 0.12)';
                    this.style.borderColor = '#667eea';
                    this.style.background = 'rgba(102, 126, 234, 0.02)';
                } else if (this.classList.contains('empty-state')) {
                    this.style.transform = 'translateY(-5px)';
                    this.style.boxShadow = '0 20px 40px rgba(0, 0, 0, 0.15)';
                }
            });
            
            // Add mouse leave effect
            element.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) translateX(0)';
                this.style.boxShadow = '';
                this.style.borderColor = '';
                this.style.background = '';
            });
        });
        
        // Enhance buttons
        const buttons = document.querySelectorAll('.btn-enhanced');
        buttons.forEach(button => {
            button.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-3px)';
                if (this.classList.contains('btn-outline-primary')) {
                    this.style.boxShadow = '0 8px 25px rgba(102, 126, 234, 0.4)';
                }
            });
            
            button.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = '';
            });
        });
        
        console.log('Hover effects enhanced for', hoverElements.length, 'elements');
    }
    
    function animateCards() {
        // Animate cards on load
        const cards = document.querySelectorAll('.license-card, .stat-card, .company-section');
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                card.style.transition = 'all 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 100);
        });
    }
    
    function init() {
        if (initialized) return;
        
        setupScrollAnimations();
        enhanceHoverEffects();
        animateCards();
        
        initialized = true;
        console.log('ServicesManager initialized successfully with enhanced hover effects');
    }
    
    // Public API
    return {
        init: init,
        showLicenseDetails: showLicenseDetails
    };
})();

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Small delay to ensure all styles are loaded
    setTimeout(function() {
        ServicesManager.init();
    }, 200);
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
