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

// Check if user is administrator
if ($user['role'] !== 'administrator') {
    // Redirect to unauthorized page or dashboard
    header('Location: /dashboard.php?error=unauthorized');
    exit;
}

$user_id = $user['id'];

// Fetch user profile
$stmt = $pdo->prepare("
    SELECT u.*, c.name AS company_name
    FROM users u
    LEFT JOIN companies c ON u.company_id = c.id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$profile = $stmt->fetch();

// Fetch user's tickets with necessary data
$stmt = $pdo->prepare("
    SELECT st.*, 
           tg.name AS group_name, 
           assigned.username AS assigned_to_username, 
           updated.username AS updated_by_username
    FROM support_tickets st
    LEFT JOIN support_ticket_groups tg ON st.group_id = tg.id
    LEFT JOIN users assigned ON st.assigned_to = assigned.id
    LEFT JOIN users updated ON st.updated_by = updated.id
    WHERE st.user_id = ?
    ORDER BY st.created_at DESC
");
$stmt->execute([$user_id]);
$tickets = $stmt->fetchAll();

$page_title = "My Support Tickets | CaminhoIT";
?>
<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>
<header class="hero dashboard-hero">
    <div class="container text-center text-white py-5">
        <h1>Template Header</h1>
        <p>Template Page Description.</p>
    </div>
</header>
<div class="container py-5 overlap-cards">
    This is where content would go...
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>