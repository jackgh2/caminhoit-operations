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
$stmt = $pdo->prepare("
    SELECT u.*, c.name AS company_name
    FROM users u
    LEFT JOIN companies c ON u.company_id = c.id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$profile = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT c.name 
    FROM company_users cu
    JOIN companies c ON cu.company_id = c.id
    WHERE cu.user_id = ?
");
$stmt->execute([$user_id]);
$multi_companies = $stmt->fetchAll(PDO::FETCH_COLUMN);

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
    </style>
</head>
<body>

<header class="hero dashboard-hero">
    <div class="container hero-content text-center">
        <h1 class="hero-title">Welcome, <?= htmlspecialchars($profile['username']); ?></h1>
        <p class="hero-subtitle">Here’s your account overview and settings.</p>
    </div>
</header>

<div class="container py-5 overlap-cards">
    <div class="row justify-content-center mb-4">
        <div class="col-lg-10">
            <div class="row g-4">
                <div class="col-md-6 col-lg-4">
                    <div class="card text-center shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title"><i class="bi bi-person-fill text-purple"></i> Full Name</h5>
                            <p class="card-text"><?= htmlspecialchars($profile['username']); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="card text-center shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title"><i class="bi bi-envelope-at text-primary"></i> Email</h5>
                            <p class="card-text"><?= htmlspecialchars($profile['email']); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="card text-center shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title"><i class="bi bi-lock-fill text-warning"></i> Secured By</h5>
                            <p class="card-text"><?= ucfirst($profile['provider']); ?> SSO</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="card text-center shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title"><i class="bi bi-patch-check-fill text-success"></i> Role</h5>
                            <p class="card-text"><?= ucfirst($profile['role']); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="card text-center shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title"><i class="bi bi-building text-info"></i> Primary Company</h5>
                            <p class="card-text"><?= $profile['company_name'] ?? 'None'; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="card text-center shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title"><i class="bi bi-clipboard text-danger"></i> Other Companies</h5>
                            <p class="card-text">
                                <?php if (!empty($multi_companies)): ?>
                                    <ul class="list-unstyled mb-0">
                                        <?php foreach ($multi_companies as $company): ?>
                                            <li><?= htmlspecialchars($company); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <span class="text-muted">None</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-5 text-center">
                <a href="/members/view-invoices.php" class="btn btn-outline-primary me-2">View Invoices</a>
                <a href="/members/raise-ticket.php" class="btn btn-outline-primary me-2">Raise Ticket</a>
                <a href="/logout.php" class="btn btn-danger">Logout</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
