<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;

if (!$user) {
    header('Location: /login.php');
    exit;
}

$role        = $user['role'] ?? 'public';
$username    = htmlspecialchars($user['username']);
$company_id  = $user['company_id'] ?? null;

// Example placeholders (replace with real queries when ready)
$invoice_count      = 12;
$paid_count         = 7;
$awaiting_tickets   = 5;
$open_tickets       = 9;
$licenses           = [
    ['product_name' => 'Microsoft 365 Business Premium'],
    ['product_name' => 'Bitdefender GravityZone'],
    ['product_name' => 'SSL Certificate - caminhoit.com']
];

$page_title = "Dashboard | CaminhoIT";
include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php';
?>

<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?? 'CaminhoIT'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>

<!-- ✅ HERO SECTION -->
<header class="hero dashboard-hero">
    <div class="container hero-content">
        <h1 class="hero-title">Welcome, <?= $username; ?></h1>
        <p class="hero-subtitle">Here’s your self service dashboard.</p>
    </div>
</header>

<!-- ✅ MAIN DASHBOARD CONTENT -->
<section class="py-5">
    <?php if ($role === 'administrator'): ?>
        <!-- OVERLAPPING STATS BOX SECTION -->
        <section class="dashboard-overlap-section">
            <div class="container">
                <div class="row justify-content-center text-center">
                    <div class="col-md-3">
                        <div class="dashboard-box">
                            <div class="icon mb-2">🧾</div>
                            <div class="stat-number"><?= $invoice_count; ?></div>
                            <div class="stat-label">Outstanding Invoices</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="dashboard-box">
                            <div class="icon mb-2">💰</div>
                            <div class="stat-number"><?= $paid_count; ?></div>
                            <div class="stat-label">Invoices Paid (Last 30 Days)</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="dashboard-box">
                            <div class="icon mb-2">🎫</div>
                            <div class="stat-number"><?= $awaiting_tickets; ?></div>
                            <div class="stat-label">Tickets Awaiting Response</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="dashboard-box">
                            <div class="icon mb-2">🚩</div>
                            <div class="stat-number"><?= $open_tickets; ?></div>
                            <div class="stat-label">Total Open Tickets</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="container">
            <!-- ✅ Admin Controls Box -->
            <div class="dashboard-box mb-5">
                <h3 class="mb-4">Admin Controls</h3>
                <div class="d-flex gap-3 flex-wrap justify-content-center">
                    <a href="manage_services.php" class="btn btn-custom-primary">Manage Services</a>
                    <a href="manage_plans.php" class="btn btn-custom-primary">Manage Plans</a>
                    <a href="manage_users.php" class="btn btn-custom-primary">Manage Users</a>
                    <a href="manage_navigation.php" class="btn btn-custom-primary">Manage Navigation Menu</a>
                </div>
            </div>
    <?php elseif ($role === 'support_consultant'): ?>
        <!-- SUPPORT TEAM VIEW -->
        <div class="container">
            <div class="dashboard-box mb-5">
                <h3>Support Team Dashboard</h3>
                <p>Tickets needing your attention will be listed here.</p>
            </div>
    <?php elseif ($role === 'accountant'): ?>
        <!-- BILLING TEAM VIEW -->
        <div class="container">
            <div class="dashboard-box mb-5">
                <h3>Billing Overview</h3>
                <p>See outstanding invoices, paid invoices, and payment reminders here.</p>
            </div>
    <?php elseif ($role === 'account_manager'): ?>
        <!-- ACCOUNT MANAGER VIEW -->
        <div class="container">
            <div class="dashboard-box mb-5">
                <h3>Your Company Overview</h3>
                <p>Company-specific licenses and open tickets will be listed here.</p>
            </div>
    <?php elseif ($role === 'supported_user'): ?>
        <!-- SUPPORTED USER VIEW -->
        <div class="container">
            <div class="dashboard-box mb-5">
                <h3>Your Account Dashboard</h3>
                <p>View your open tickets and services below.</p>
            </div>
    <?php else: ?>
        <!-- UNKNOWN OR PUBLIC ROLE -->
        <div class="container">
            <div class="dashboard-box mb-5">
                <p>You do not have access to this area.</p>
            </div>
    <?php endif; ?>

        <!-- ✅ What Can We Help You With Box -->
        <div class="dashboard-box mb-5">
            <h3 class="mb-4">What Can We Help You With?</h3>
            <div class="d-flex gap-3 flex-wrap justify-content-center">
                <a href="/members/raise-ticket.php" class="btn btn-custom-primary">Raise a Support Ticket</a>
                <a href="/members/request-license.php" class="btn btn-custom-primary">Request a License</a>
                <a href="/members/view-invoices.php" class="btn btn-custom-primary">View Invoices</a>
                <a href="/members/account.php" class="btn btn-custom-primary">My Account</a>
            </div>
        </div>

        <!-- ✅ You Have Access To Box -->
        <div class="dashboard-box">
            <h3 class="mb-4">You Have Access To:</h3>
            <ul class="list-group list-group-flush">
                <?php foreach ($licenses as $license): ?>
                    <li class="list-group-item"><?= htmlspecialchars($license['product_name']); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

    </div>
</section>

</html>
