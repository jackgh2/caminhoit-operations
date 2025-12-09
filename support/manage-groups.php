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

// Fetch primary user profile
$stmt = $pdo->prepare("
    SELECT u.*, c.name AS company_name
    FROM users u
    LEFT JOIN companies c ON u.company_id = c.id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$profile = $stmt->fetch();

// Fetch multi-company affiliations
$stmt = $pdo->prepare("
    SELECT c.name 
    FROM company_users cu
    JOIN companies c ON cu.company_id = c.id
    WHERE cu.user_id = ?
");
$stmt->execute([$user_id]);
$multi_companies = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Handle Toggle Active/Inactive
if (isset($_GET['toggle'])) {
    $toggle_id = (int)$_GET['toggle'];
    $stmt = $pdo->prepare("UPDATE support_ticket_groups SET active = NOT active WHERE id = ?");
    $stmt->execute([$toggle_id]);
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// Handle Insert
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_group'])) {
    $stmt = $pdo->prepare("INSERT INTO support_ticket_groups (name, description) VALUES (?, ?)");
    $stmt->execute([$_POST['name'], $_POST['description']]);
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// Handle Edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_group'])) {
    $stmt = $pdo->prepare("UPDATE support_ticket_groups SET name = ?, description = ? WHERE id = ?");
    $stmt->execute([$_POST['name'], $_POST['description'], $_POST['id']]);
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// Option to show/hide inactive groups
$show_inactive = isset($_GET['show_inactive']);

// Fetch ticket groups
if ($show_inactive) {
    $stmt = $pdo->query("SELECT * FROM support_ticket_groups ORDER BY id DESC");
} else {
    $stmt = $pdo->query("SELECT * FROM support_ticket_groups WHERE active = 1 ORDER BY id DESC");
}
$groups = $stmt->fetchAll();

$page_title = "Support Tickets | CaminhoIT";

include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php';
?>
<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">
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
    <div class="container text-center text-white py-5">
        <h1 class="hero-title">Welcome, <?= htmlspecialchars($profile['username']); ?></h1>
        <p class="hero-subtitle">Here’s your account overview and settings.</p>
    </div>
</header>

<div class="container py-5 overlap-cards">
    <div class="row g-4 justify-content-center">
        <!-- Your original cards here (unchanged) -->
        <!-- (cards for username, email, etc.) -->
    </div>
</div>

<section class="container py-5">
    <h2 class="mb-4">Support Ticket Groups</h2>

    <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="bi bi-plus-circle"></i> Add Group
    </button>

    <a href="?<?= $show_inactive ? '' : 'show_inactive=1' ?>" class="btn btn-secondary mb-3">
        <?= $show_inactive ? 'Hide Inactive Groups' : 'Show Inactive Groups' ?>
    </a>

    <table class="table table-bordered table-striped">
        <thead class="table-light">
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Description</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $row): ?>
            <tr class="<?= $row['active'] ? '' : 'table-secondary' ?>">
                <td><?= $row['id'] ?></td>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= htmlspecialchars($row['description']) ?></td>
                <td>
                    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?= $row['id'] ?>">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                    <a href="?toggle=<?= $row['id'] ?>" class="btn btn-sm <?= $row['active'] ? 'btn-danger' : 'btn-success' ?>">
                        <i class="bi <?= $row['active'] ? 'bi-x-circle' : 'bi-check-circle' ?>"></i> <?= $row['active'] ? 'Disable' : 'Enable' ?>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    
    <!-- All Modals BELOW the table and OUTSIDE table rows -->
    <?php foreach ($groups as $row): ?>
    <div class="modal fade" id="editModal<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" method="post">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Ticket Group</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Group Name</label>
                        <input name="name" class="form-control" value="<?= htmlspecialchars($row['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label>Description</label>
                        <textarea name="description" class="form-control" required><?= htmlspecialchars($row['description']) ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" name="edit_group" type="submit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <?php endforeach; ?>

        </tbody>
    </table>
</section>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <div class="modal-header">
                <h5 class="modal-title">Add Ticket Group</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label>Group Name</label>
                    <input name="name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label>Description</label>
                    <textarea name="description" class="form-control" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" name="create_group" type="submit">Create</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
