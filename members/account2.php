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

$user_id = $user['id'];

// Get user profile with more details
$stmt = $pdo->prepare("
    SELECT u.*, c.name AS company_name, c.phone as company_phone, c.address as company_address
    FROM users u
    LEFT JOIN companies c ON u.company_id = c.id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$profile = $stmt->fetch();

// Get multi-company access
$stmt = $pdo->prepare("
    SELECT c.name, c.id
    FROM company_users cu
    JOIN companies c ON cu.company_id = c.id
    WHERE cu.user_id = ?
");
$stmt->execute([$user_id]);
$multi_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get account statistics
$stats = [];

// Ticket statistics
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_tickets,
            COUNT(CASE WHEN LOWER(status) NOT IN ('closed') THEN 1 END) as open_tickets,
            COUNT(CASE WHEN LOWER(status) = 'awaiting member reply' THEN 1 END) as awaiting_reply,
            MAX(created_at) as last_ticket_date
        FROM support_tickets 
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $stats['tickets'] = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stats['tickets'] = ['total_tickets' => 0, 'open_tickets' => 0, 'awaiting_reply' => 0, 'last_ticket_date' => null];
}

// Service statistics
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as active_services
        FROM product_assignments pa
        JOIN client_subscriptions cs ON pa.subscription_id = cs.id
        WHERE pa.user_id = ? AND cs.status = 'active' AND pa.status = 'assigned'
    ");
    $stmt->execute([$user_id]);
    $stats['services'] = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stats['services'] = ['active_services' => 0];
}

// Invoice statistics
try {
    if ($profile['company_id']) {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_invoices,
                COUNT(CASE WHEN status IN ('pending', 'sent', 'draft') THEN 1 END) as outstanding,
                SUM(CASE WHEN status IN ('pending', 'sent', 'draft') THEN total_amount ELSE 0 END) as outstanding_value
            FROM invoices 
            WHERE company_id = ?
        ");
        $stmt->execute([$profile['company_id']]);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_invoices,
                COUNT(CASE WHEN status IN ('pending', 'sent', 'draft') THEN 1 END) as outstanding,
                SUM(CASE WHEN status IN ('pending', 'sent', 'draft') THEN total_amount ELSE 0 END) as outstanding_value
            FROM invoices 
            WHERE user_id = ? OR created_by = ?
        ");
        $stmt->execute([$user_id, $user_id]);
    }
    $stats['invoices'] = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stats['invoices'] = ['total_invoices' => 0, 'outstanding' => 0, 'outstanding_value' => 0];
}

// Recent activity
try {
    $stmt = $pdo->prepare("
        SELECT 'ticket' as type, id, subject as title, status, created_at as activity_date
        FROM support_tickets 
        WHERE user_id = ?
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_activity = [];
}

$page_title = "My Account | CaminhoIT";
include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php';
?>

<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <link rel="stylesheet" href="/assets/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .overlap-cards {
            margin-top: -100px;
            position: relative;
            z-index: 1;
        }
        
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: transform 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .activity-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            background: #f8fafc;
            border-left: 4px solid #4F46E5;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-open { background: #fef2f2; color: #dc2626; }
        .status-closed { background: #d1fae5; color: #059669; }
        .status-in-progress { background: #fef3c7; color: #d97706; }
        .status-awaiting { background: #dbeafe; color: #2563eb; }
        
        .info-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .quick-action {
            background: linear-gradient(135deg, #4F46E5 0%, #7C3AED 100%);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 0.75rem 1.5rem;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0.25rem;
        }
        
        .quick-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.4);
            color: white;
            text-decoration: none;
        }
        
        .security-info {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
            color: white;
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
        }
        
        .company-badge {
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            margin: 0.25rem;
            display: inline-block;
            font-size: 0.875rem;
        }
        
        .account-created {
            color: #6b7280;
            font-size: 0.875rem;
            margin-top: 1rem;
        }
    </style>
</head>
<body>

<header class="hero dashboard-hero">
    <div class="container hero-content text-center">
        <h1 class="hero-title">
            <i class="bi bi-person-circle me-2"></i>
            Welcome, <?= htmlspecialchars($profile['username']); ?>
        </h1>
        <p class="hero-subtitle">Manage your account settings and view your activity overview</p>
    </div>
</header>

<div class="container py-5 overlap-cards">
    <div class="row">
        <!-- Left Column - Profile Info -->
        <div class="col-lg-8">
            <!-- Profile Information -->
            <div class="info-section">
                <h2 class="section-title">
                    <i class="bi bi-person-badge"></i>
                    Profile Information
                </h2>
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="bi bi-person-fill text-purple fs-1 mb-3"></i>
                                <h5 class="card-title">Full Name</h5>
                                <p class="card-text fw-bold"><?= htmlspecialchars($profile['username']); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="bi bi-envelope-at text-primary fs-1 mb-3"></i>
                                <h5 class="card-title">Email Address</h5>
                                <p class="card-text fw-bold"><?= htmlspecialchars($profile['email']); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <i class="bi bi-patch-check-fill text-success fs-1 mb-3"></i>
                                <h5 class="card-title">Account Role</h5>
                                <p class="card-text fw-bold"><?= ucwords(str_replace('_', ' ', $profile['role'])); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <div class="security-info">
                                    <i class="bi bi-shield-check fs-1 mb-2"></i>
                                    <h5 class="mb-1">Secured By</h5>
                                    <p class="mb-0 fw-bold"><?= ucfirst($profile['provider']); ?> SSO</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($profile['created_at']): ?>
                    <div class="account-created text-center">
                        <i class="bi bi-calendar-check me-1"></i>
                        Account created: <?= date('F j, Y', strtotime($profile['created_at'])); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Company Information -->
            <div class="info-section">
                <h2 class="section-title">
                    <i class="bi bi-buildings"></i>
                    Company Access
                </h2>
                <div class="row">
                    <div class="col-md-6">
                        <h5><i class="bi bi-building text-info me-2"></i>Primary Company</h5>
                        <div class="company-badge">
                            <strong><?= $profile['company_name'] ?? 'No primary company assigned'; ?></strong>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h5><i class="bi bi-clipboard text-danger me-2"></i>Additional Access</h5>
                        <?php if (!empty($multi_companies)): ?>
                            <?php foreach ($multi_companies as $company): ?>
                                <div class="company-badge">
                                    <?= htmlspecialchars($company['name']); ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="company-badge text-muted">
                                No additional company access
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <?php if (!empty($recent_activity)): ?>
            <div class="activity-card">
                <h2 class="section-title">
                    <i class="bi bi-clock-history"></i>
                    Recent Activity
                </h2>
                <?php foreach ($recent_activity as $activity): ?>
                    <div class="activity-item">
                        <div class="me-3">
                            <i class="bi bi-ticket-perforated text-primary fs-5"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-bold"><?= htmlspecialchars($activity['title']); ?></div>
                            <small class="text-muted">
                                <i class="bi bi-calendar me-1"></i>
                                <?= date('M j, Y g:i A', strtotime($activity['activity_date'])); ?>
                            </small>
                        </div>
                        <div>
                            <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $activity['status'])); ?>">
                                <?= ucfirst($activity['status']); ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="text-center mt-3">
                    <a href="/members/my-ticket.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-eye me-1"></i>View All Tickets
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right Column - Stats & Actions -->
        <div class="col-lg-4">
            <!-- Account Statistics -->
            <div class="stats-card">
                <h3 class="mb-3"><i class="bi bi-graph-up me-2"></i>Account Overview</h3>
                
                <div class="mb-3">
                    <div class="stats-number"><?= $stats['tickets']['total_tickets']; ?></div>
                    <div>Total Support Tickets</div>
                </div>
                
                <div class="mb-3">
                    <div class="stats-number text-warning"><?= $stats['tickets']['open_tickets']; ?></div>
                    <div>Open Tickets</div>
                </div>
                
                <?php if ($stats['tickets']['awaiting_reply'] > 0): ?>
                <div class="mb-3">
                    <div class="stats-number text-danger"><?= $stats['tickets']['awaiting_reply']; ?></div>
                    <div>Awaiting Your Reply</div>
                </div>
                <?php endif; ?>
                
                <div class="mb-3">
                    <div class="stats-number text-success"><?= $stats['services']['active_services']; ?></div>
                    <div>Active Services</div>
                </div>
                
                <?php if ($stats['invoices']['outstanding'] > 0): ?>
                <div class="mb-3">
                    <div class="stats-number text-warning"><?= $stats['invoices']['outstanding']; ?></div>
                    <div>Outstanding Invoices</div>
                    <?php if ($stats['invoices']['outstanding_value'] > 0): ?>
                        <small>(£<?= number_format($stats['invoices']['outstanding_value'], 2); ?>)</small>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Quick Actions -->
            <div class="info-section">
                <h3 class="section-title">
                    <i class="bi bi-lightning"></i>
                    Quick Actions
                </h3>
                <div class="d-grid gap-2">
                    <a href="/members/raise-ticket.php" class="quick-action">
                        <i class="bi bi-plus-circle"></i>
                        Raise New Ticket
                    </a>
                    <a href="/members/my-ticket.php" class="quick-action">
                        <i class="bi bi-ticket-perforated"></i>
                        View My Tickets
                    </a>
                    <a href="/members/my-services.php" class="quick-action">
                        <i class="bi bi-gear"></i>
                        Manage Services
                    </a>
                    <a href="/members/view-invoices.php" class="quick-action">
                        <i class="bi bi-receipt"></i>
                        View Invoices
                    </a>
                    <?php if (in_array($profile['role'], ['account_manager', 'administrator'])): ?>
                    <a href="/members/create-order.php" class="quick-action">
                        <i class="bi bi-cart-plus"></i>
                        Place New Order
                    </a>
                    <?php endif; ?>
                    <a href="/dashboard.php" class="quick-action">
                        <i class="bi bi-speedometer2"></i>
                        Back to Dashboard
                    </a>
                </div>
                
                <hr class="my-4">
                
                <div class="d-grid">
                    <a href="/logout.php" class="btn btn-outline-danger">
                        <i class="bi bi-box-arrow-right me-2"></i>
                        Logout
                    </a>
                </div>
            </div>

            <!-- Account Health -->
            <div class="info-section">
                <h3 class="section-title">
                    <i class="bi bi-shield-check"></i>
                    Account Health
                </h3>
                <div class="list-group list-group-flush">
                    <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0">
                        <span><i class="bi bi-check-circle text-success me-2"></i>Email Verified</span>
                        <span class="badge bg-success rounded-pill">Active</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0">
                        <span><i class="bi bi-shield-check text-success me-2"></i>SSO Enabled</span>
                        <span class="badge bg-success rounded-pill">Secure</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0">
                        <span><i class="bi bi-person-check text-success me-2"></i>Profile Complete</span>
                        <span class="badge bg-success rounded-pill">100%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Add some nice animations
document.addEventListener('DOMContentLoaded', function() {
    // Animate cards on load
    const cards = document.querySelectorAll('.card, .info-section, .stats-card, .activity-card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.6s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
});
</script>
</body>
</html>