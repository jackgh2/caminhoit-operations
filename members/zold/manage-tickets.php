<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /login.php');
    exit;
}
$user_id = $user['id'];

// Filtering
$whereClauses = ['1=1'];
$params = [];

if (!empty($_GET['filter'])) {
    switch ($_GET['filter']) {
        case 'unanswered':      $whereClauses[] = 'st.updated_by IS NULL'; break;
        case 'unclosed':        $whereClauses[] = "st.status != 'Resolved'"; break;
        case 'unassigned':      $whereClauses[] = 'st.assigned_to IS NULL'; break;
        case 'assigned_to_you': $whereClauses[] = 'st.assigned_to = :assigned_to'; $params['assigned_to'] = $user_id; break;
    }
}

if (!empty($_GET['group'])) {
    $whereClauses[] = 'tg.name = :group';
    $params['group'] = $_GET['group'];
}

if (!empty($_GET['search'])) {
    $whereClauses[] = '(st.subject LIKE :search OR u.username LIKE :search OR c.name LIKE :search)';
    $params['search'] = '%' . $_GET['search'] . '%';
}

$orderBy = 'st.created_at DESC';
$allowedSorts = ['subject', 'status', 'priority', 'created_at', 'due_date', 'assigned_to', 'updated_at'];
if (!empty($_GET['sort']) && in_array($_GET['sort'], $allowedSorts)) {
    $dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    $orderBy = "st.{$_GET['sort']} $dir";
}

// SQL query
$sql = "
    SELECT st.*, tg.name AS group_name, u.username, u.name AS customer_name, c.name AS company_name,
           assigned.username AS assigned_to_username, updated.username AS updated_by_username
    FROM support_tickets st
    LEFT JOIN support_ticket_groups tg ON st.group_id = tg.id
    LEFT JOIN users u ON st.user_id = u.id
    LEFT JOIN companies c ON u.company_id = c.id
    LEFT JOIN users assigned ON st.assigned_to = assigned.id
    LEFT JOIN users updated ON st.updated_by = updated.id
    WHERE " . implode(' AND ', $whereClauses) . "
    ORDER BY $orderBy
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

// Groups
$groups = $pdo->query("
    SELECT g.name, COUNT(t.id) as count 
    FROM support_ticket_groups g 
    LEFT JOIN support_tickets t ON t.group_id = g.id 
    GROUP BY g.id
")->fetchAll();

$page_title = "Manage Tickets | CaminhoIT";
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="/assets/styles.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .dropdown-toggle::after { display: none; }
        .active-filter { font-weight: bold; }
    </style>
</head>
<body>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<header class="hero dashboard-hero">
    <div class="container text-center text-white py-5">
        <h1>Manage Support Tickets</h1>
        <p>View all support tickets and their current status.</p>
    </div>
</header>

<div class="container-fluid mt-4" id="ticket-container">
    <div class="ticket-filters d-flex flex-wrap gap-2 mb-4">
        <?php
        $filters = [
            'unanswered' => 'Unanswered',
            'unclosed' => 'Unclosed',
            'unassigned' => 'Unassigned',
            'assigned_to_you' => 'Assigned to You',
            'all' => 'All'
        ];
        foreach ($filters as $key => $label):
            $active = ($_GET['filter'] ?? 'all') === $key ? 'active' : '';
            echo "<a href=\"?filter=$key\" class=\"btn btn-outline-secondary $active ajax-filter\">$label</a>";
        endforeach;
        ?>
        <a href="manage-tickets.php" class="btn btn-outline-dark ajax-clear">Clear Filters</a>
        <form method="get" class="ms-auto d-flex" id="search-form">
            <input type="text" name="search" class="form-control me-2" placeholder="Search user, subject, company" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" style="width: 300px;">
            <button type="submit" class="btn btn-primary">Search</button>
        </form>
    </div>

    <div class="row">
        <div class="col-md-3">
            <div class="card bg-light">
                <div class="card-header"><strong>Categories</strong></div>
                <div class="list-group list-group-flush">
                    <?php foreach ($groups as $group): ?>
                        <a href="?group=<?= urlencode($group['name']) ?>" class="list-group-item list-group-item-action ajax-filter <?= ($_GET['group'] ?? '') === $group['name'] ? 'active' : '' ?>">
                            <?= htmlspecialchars($group['name']) ?>
                            <span class="badge bg-secondary float-end"><?= $group['count'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-md-9">
            <div class="card bg-light">
                <div class="card-header"><strong>Tickets</strong></div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                        <tr>
                            <?php
                            $headers = [
                                'subject' => 'Subject',
                                'status' => 'Status',
                                'priority' => 'Priority',
                                'created_at' => 'Date',
                                'due_date' => 'Due',
                                'assigned_to' => 'Assigned',
                                'updated_at' => 'Updated'
                            ];
                            foreach ($headers as $key => $label):
                                $dir = ($_GET['sort'] ?? '') === $key && ($_GET['dir'] ?? '') === 'asc' ? 'desc' : 'asc';
                                $icon = ($_GET['sort'] ?? '') === $key ? ($_GET['dir'] === 'asc' ? '▲' : '▼') : '';
                                $query = http_build_query(array_merge($_GET, ['sort' => $key, 'dir' => $dir]));
                                echo "<th><a href=\"?$query\" class=\"text-decoration-none text-dark ajax-filter\">$label $icon</a></th>";
                            endforeach;
                            ?>
                            <th>User</th>
                            <th>Name</th>
                            <th>Company</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tickets as $ticket): ?>
                            <tr>
                                <td><a href="view-ticket.php?id=<?= $ticket['id'] ?>" class="fw-bold text-primary"><?= htmlspecialchars($ticket['subject']) ?></a></td>
                                <td><?= htmlspecialchars($ticket['status']) ?></td>
                                <td><?= htmlspecialchars($ticket['priority']) ?></td>
                                <td><?= date('j M Y', strtotime($ticket['created_at'])) ?></td>
                                <td><?= $ticket['due_date'] ? date('j M Y', strtotime($ticket['due_date'])) : '-' ?></td>
                                <td><?= htmlspecialchars($ticket['assigned_to_username'] ?? '-') ?></td>
                                <td><?= date('j M Y H:i', strtotime($ticket['updated_at'])) ?></td>
                                <td><?= htmlspecialchars($ticket['username'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($ticket['customer_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($ticket['company_name'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($tickets)): ?>
                            <tr><td colspan="10" class="text-center text-muted">No tickets found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.ajax-filter, .ajax-clear').forEach(el => {
    el.addEventListener('click', function (e) {
        e.preventDefault();
        fetch(this.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(res => res.text())
            .then(html => {
                const parser = new DOMParser();
                const newDoc = parser.parseFromString(html, 'text/html');
                document.querySelector('#ticket-container').innerHTML = newDoc.querySelector('#ticket-container').innerHTML;
                history.pushState({}, '', this.href);
            });
    });
});

document.getElementById('search-form')?.addEventListener('submit', function (e) {
    e.preventDefault();
    const formData = new FormData(this);
    const search = formData.get('search');
    const query = new URLSearchParams(window.location.search);
    query.set('search', search);
    fetch(`?${query.toString()}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.text())
        .then(html => {
            const parser = new DOMParser();
            const newDoc = parser.parseFromString(html, 'text/html');
            document.querySelector('#ticket-container').innerHTML = newDoc.querySelector('#ticket-container').innerHTML;
            history.pushState({}, '', `?${query.toString()}`);
        });
});
</script>
</body>
</html>
