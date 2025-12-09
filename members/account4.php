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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Preconnect to fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="/assets/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            --info-gradient: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%);
            --glass-bg: rgba(255, 255, 255, 0.25);
            --glass-border: rgba(255, 255, 255, 0.18);
            --shadow-light: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
            --shadow-medium: 0 15px 35px rgba(0, 0, 0, 0.1);
            --border-radius: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--primary-gradient);
            min-height: 100vh;
            position: relative;
        }

        /* Animated Background */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 80%, rgba(120, 119, 198, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 40% 40%, rgba(120, 119, 198, 0.15) 0%, transparent 50%);
            z-index: -1;
            animation: float 20s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            33% { transform: translateY(-20px) rotate(1deg); }
            66% { transform: translateY(10px) rotate(-1deg); }
        }

        /* Hero Section Enhancement */
        .hero.dashboard-hero {
            background: transparent;
            padding: 4rem 0 2rem;
            position: relative;
            overflow: hidden;
        }

        .hero-title {
            font-size: 3rem;
            font-weight: 700;
            color: white;
            text-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            margin-bottom: 1rem;
            animation: fadeInUp 0.8s ease-out;
        }

        .hero-subtitle {
            font-size: 1.2rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 0;
            animation: fadeInUp 0.8s ease-out 0.2s both;
        }

        /* Card Enhancements */
        .overlap-cards {
            margin-top: -80px;
            position: relative;
            z-index: 2;
        }

        .info-section {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-light);
            position: relative;
            overflow: hidden;
            animation: slideUp 0.6s ease-out;
        }

        .info-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: white;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .card {
            background: rgba(255, 255, 255, 0.95);
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: var(--transition);
            overflow: hidden;
            position: relative;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary-gradient);
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        /* Stats Card Enhancement */
        .stats-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            color: white;
            border-radius: var(--border-radius);
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-light);
            position: relative;
            overflow: hidden;
            animation: slideUp 0.6s ease-out 0.3s both;
        }

        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.5), transparent);
        }

        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-medium);
        }

        .stats-number {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .stats-label {
            font-size: 1rem;
            opacity: 0.9;
            font-weight: 500;
        }

        /* Activity Card Enhancement */
        .activity-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 2.5rem;
            box-shadow: var(--shadow-light);
            margin-bottom: 2rem;
            animation: slideUp 0.6s ease-out 0.6s both;
        }

        .activity-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 0.75rem;
            background: rgba(255, 255, 255, 0.95);
            border-left: 4px solid transparent;
            border-image: var(--primary-gradient) 1;
            transition: var(--transition);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .activity-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }

        /* Quick Actions Enhancement */
        .quick-action {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            color: white;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            text-decoration: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0.25rem 0;
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }

        .quick-action::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }

        .quick-action:hover::before {
            left: 100%;
        }

        .quick-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
            color: white;
            text-decoration: none;
            background: rgba(255, 255, 255, 0.15);
        }

        /* Security Info Enhancement */
        .security-info {
            background: var(--success-gradient);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 4px 15px rgba(17, 153, 142, 0.3);
            position: relative;
            overflow: hidden;
        }

        .security-info::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: securityGlow 3s ease-in-out infinite;
        }

        @keyframes securityGlow {
            0%, 100% { transform: scale(1) rotate(0deg); }
            50% { transform: scale(1.1) rotate(180deg); }
        }

        /* Company Badge Enhancement */
        .company-badge {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 25px;
            padding: 0.75rem 1.25rem;
            margin: 0.5rem;
            display: inline-block;
            font-size: 0.9rem;
            font-weight: 500;
            color: #2c3e50;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }

        .company-badge:hover {
            background: rgba(255, 255, 255, 1);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-open { 
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            color: white;
        }
        .status-closed { 
            background: linear-gradient(135deg, #00b894 0%, #00a085 100%);
            color: white;
        }
        .status-in-progress { 
            background: linear-gradient(135deg, #fdcb6e 0%, #e17055 100%);
            color: white;
        }
        .status-awaiting { 
            background: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%);
            color: white;
        }

        /* Account Created Enhancement */
        .account-created {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.9rem;
            margin-top: 1.5rem;
            text-align: center;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 25px;
            backdrop-filter: blur(10px);
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }
            
            .info-section,
            .stats-card,
            .activity-card {
                padding: 2rem 1.5rem;
            }
            
            .section-title {
                font-size: 1.25rem;
            }
        }

        /* Custom Icon Colors */
        .text-purple { color: #667eea !important; }
        .text-primary { color: #4facfe !important; }
        .text-success { color: #11998e !important; }
        .text-warning { color: #f093fb !important; }
        .text-danger { color: #ff6b6b !important; }
        .text-info { color: #74b9ff !important; }

        /* Enhanced Button Styles */
        .btn-outline-primary {
            border-color: rgba(255, 255, 255, 0.5);
            color: white;
            transition: var(--transition);
        }

        .btn-outline-primary:hover {
            background: white;
            border-color: white;
            color: #667eea;
        }

        .btn-outline-danger {
            border-color: rgba(255, 107, 107, 0.8);
            color: #ff6b6b;
            transition: var(--transition);
        }

        .btn-outline-danger:hover {
            background: var(--danger-gradient);
            border-color: transparent;
            color: white;
        }

        /* Loading Animation */
        .loading-skeleton {
            background: linear-gradient(90deg, rgba(255,255,255,0.1) 25%, rgba(255,255,255,0.2) 50%, rgba(255,255,255,0.1) 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
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
                            <div class="card-body text-center p-0">
                                <div class="security-info">
                                    <i class="bi bi-shield-check fs-1 mb-2" style="position: relative; z-index: 1;"></i>
                                    <h5 class="mb-1" style="position: relative; z-index: 1;">Secured By</h5>
                                    <p class="mb-0 fw-bold" style="position: relative; z-index: 1;"><?= ucfirst($profile['provider']); ?> SSO</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($profile['created_at']): ?>
                    <div class="account-created">
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
                        <h5 style="color: rgba(255,255,255,0.9); margin-bottom: 1rem;">
                            <i class="bi bi-building text-info me-2"></i>Primary Company
                        </h5>
                        <div class="company-badge">
                            <strong><?= $profile['company_name'] ?? 'No primary company assigned'; ?></strong>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h5 style="color: rgba(255,255,255,0.9); margin-bottom: 1rem;">
                            <i class="bi bi-clipboard text-warning me-2"></i>Additional Access
                        </h5>
                        <?php if (!empty($multi_companies)): ?>
                            <?php foreach ($multi_companies as $company): ?>
                                <div class="company-badge">
                                    <?= htmlspecialchars($company['name']); ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="company-badge" style="opacity: 0.7;">
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
                <div class="text-center mt-4">
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
                <h3 class="mb-4"><i class="bi bi-graph-up me-2"></i>Account Overview</h3>
                
                <div class="mb-4">
                    <div class="stats-number"><?= $stats['tickets']['total_tickets']; ?></div>
                    <div class="stats-label">Total Support Tickets</div>
                </div>
                
                <div class="mb-4">
                    <div class="stats-number" style="color: #f093fb;"><?= $stats['tickets']['open_tickets']; ?></div>
                    <div class="stats-label">Open Tickets</div>
                </div>
                
                <?php if ($stats['tickets']['awaiting_reply'] > 0): ?>
                <div class="mb-4">
                    <div class="stats-number" style="color: #ff6b6b;"><?= $stats['tickets']['awaiting_reply']; ?></div>
                    <div class="stats-label">Awaiting Your Reply</div>
                </div>
                <?php endif; ?>
                
                <div class="mb-4">
                    <div class="stats-number" style="color: #11998e;"><?= $stats['services']['active_services']; ?></div>
                    <div class="stats-label">Active Services</div>
                </div>
                
                <?php if ($stats['invoices']['outstanding'] > 0): ?>
                <div class="mb-4">
                    <div class="stats-number" style="color: #fdcb6e;"><?= $stats['invoices']['outstanding']; ?></div>
                    <div class="stats-label">Outstanding Invoices</div>
                    <?php if ($stats['invoices']['outstanding_value'] > 0): ?>
                        <small style="opacity: 0.8;">(£<?= number_format($stats['invoices']['outstanding_value'], 2); ?>)</small>
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
                        <span>Raise New Ticket</span>
                    </a>
                    <a href="/members/my-ticket.php" class="quick-action">
                        <i class="bi bi-ticket-perforated"></i>
                        <span>View My Tickets</span>
                    </a>
                    <a href="/members/my-services.php" class="quick-action">
                        <i class="bi bi-gear"></i>
                        <span>Manage Services</span>
                    </a>
                    <a href="/members/view-invoices.php" class="quick-action">
                        <i class="bi bi-receipt"></i>
                        <span>View Invoices</span>
                    </a>
                    <?php if (in_array($profile['role'], ['account_manager', 'administrator'])): ?>
                    <a href="/members/create-order.php" class="quick-action">
                        <i class="bi bi-cart-plus"></i>
                        <span>Place New Order</span>
                    </a>
                    <?php endif; ?>
                    <a href="/dashboard.php" class="quick-action">
                        <i class="bi bi-speedometer2"></i>
                        <span>Back to Dashboard</span>
                    </a>
                </div>
                
                <hr style="border-color: rgba(255,255,255,0.2); margin: 2rem 0;">
                
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
                    <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0" style="background: transparent; color: rgba(255,255,255,0.9);">
                        <span><i class="bi bi-check-circle text-success me-2"></i>Email Verified</span>
                        <span class="badge bg-success rounded-pill">Active</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0" style="background: transparent; color: rgba(255,255,255,0.9);">
                        <span><i class="bi bi-shield-check text-success me-2"></i>SSO Enabled</span>
                        <span class="badge bg-success rounded-pill">Secure</span>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center border-0 px-0" style="background: transparent; color: rgba(255,255,255,0.9);">
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
document.addEventListener('DOMContentLoaded', function() {
    // Enhanced animation system
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.animationPlayState = 'running';
                entry.target.classList.add('animated');
            }
        });
    }, observerOptions);

    // Observe all animated elements
    document.querySelectorAll('.info-section, .stats-card, .activity-card').forEach(el => {
        observer.observe(el);
    });

    // Enhanced card hover effects
    const cards = document.querySelectorAll('.card');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-8px) rotateX(5deg)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) rotateX(0deg)';
        });
    });

    // Interactive quick actions
    const quickActions = document.querySelectorAll('.quick-action');
    quickActions.forEach(action => {
        action.addEventListener('click', function(e) {
            // Add ripple effect
            const ripple = document.createElement('span');
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;
            
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';
            ripple.style.position = 'absolute';
            ripple.style.borderRadius = '50%';
            ripple.style.background = 'rgba(255, 255, 255, 0.6)';
            ripple.style.transform = 'scale(0)';
            ripple.style.animation = 'ripple 0.6s linear';
            ripple.style.pointerEvents = 'none';
            
            this.appendChild(ripple);
            
            setTimeout(() => {
                ripple.remove();
            }, 600);
        });
    });

    // Add CSS for ripple animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
        .quick-action {
            position: relative;
            overflow: hidden;
        }
    `;
    document.head.appendChild(style);

    // Stats counter animation
    const statsNumbers = document.querySelectorAll('.stats-number');
    statsNumbers.forEach(stat => {
        const finalValue = parseInt(stat.textContent);
        if (finalValue > 0 && finalValue < 1000) {
            let currentValue = 0;
            const increment = finalValue / 50;
            const timer = setInterval(() => {
                currentValue += increment;
                if (currentValue >= finalValue) {
                    stat.textContent = finalValue;
                    clearInterval(timer);
                } else {
                    stat.textContent = Math.floor(currentValue);
                }
            }, 20);
        }
    });

    // Performance monitoring
    if ('performance' in window) {
        window.addEventListener('load', function() {
            const loadTime = performance.timing.loadEventEnd - performance.timing.navigationStart;
            console.log('Account page loaded in:', loadTime + 'ms');
        });
    }
});
</script>
</body>
</html>