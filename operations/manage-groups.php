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

// Check if user is staff/admin
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_role = $stmt->fetchColumn();

if (!in_array($user_role, ['administrator', 'support_user', 'support_technician', 'accountant'])) {
    header('Location: /dashboard.php');
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

// Fetch ticket groups with ticket counts
if ($show_inactive) {
    $stmt = $pdo->query("
        SELECT tg.*, COUNT(st.id) as ticket_count 
        FROM support_ticket_groups tg 
        LEFT JOIN support_tickets st ON tg.id = st.group_id 
        GROUP BY tg.id 
        ORDER BY tg.id DESC
    ");
} else {
    $stmt = $pdo->query("
        SELECT tg.*, COUNT(st.id) as ticket_count 
        FROM support_ticket_groups tg 
        LEFT JOIN support_tickets st ON tg.id = st.group_id 
        WHERE tg.active = 1 
        GROUP BY tg.id 
        ORDER BY tg.id DESC
    ");
}
$groups = $stmt->fetchAll();

$page_title = "Manage Support Groups | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<style>
        :root {
            --primary-color: #4F46E5;
            --primary-hover: #3F37C9;
            --success-color: #10B981;
            --warning-color: #F59E0B;
            --danger-color: #EF4444;
            --info-color: #06B6D4;
            --light-gray: #F8FAFC;
            --border-color: #E2E8F0;
            --text-muted: #64748B;
        }

        body {
            background-color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding-top: 80px;
        }

        /* FORCE NAVBAR BLUE STYLING */
        .navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15) !important;
            padding: 12px 0 !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            z-index: 1030 !important;
        }

        .navbar .navbar-brand,
        .navbar .nav-link,
        .navbar .navbar-text {
            color: white !important;
        }

        .navbar .nav-link:hover {
            color: #e0e7ff !important;
        }

        .main-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

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
            margin: 0;
        }

        .page-header .subtitle {
            color: var(--text-muted);
            margin-top: 0.5rem;
        }

        .actions-bar {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #6b7280;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-secondary:hover {
            background: #4b5563;
            transform: translateY(-1px);
        }

        .groups-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .groups-header {
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            padding: 1.5rem;
        }

        .groups-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }

        .groups-stats {
            display: flex;
            gap: 2rem;
            margin-top: 1rem;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        .stat-value {
            font-weight: 600;
            color: #1f2937;
        }

        .groups-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
        }

        .group-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.2s;
            position: relative;
        }

        .group-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .group-card.inactive {
            background: #f9fafb;
            border-color: #d1d5db;
            opacity: 0.7;
        }

        .group-card.inactive::before {
            content: "Inactive";
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: #6b7280;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .group-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .group-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }

        .group-id {
            background: #e5e7eb;
            color: #374151;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .group-description {
            color: var(--text-muted);
            line-height: 1.5;
            margin-bottom: 1rem;
        }

        .group-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .ticket-count {
            background: var(--primary-color);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .group-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s;
            border: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            color: white;
        }

        .modal-content {
            border: none;
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            border-bottom: 1px solid var(--border-color);
            padding: 1.5rem;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            border-top: 1px solid var(--border-color);
            padding: 1.5rem;
        }

        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }

        .form-control {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .actions-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .groups-grid {
                grid-template-columns: 1fr;
            }
            
            .groups-stats {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
</style>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <h1><i class="bi bi-collection me-3"></i>Support Groups Management</h1>
        <p class="subtitle">Organize and manage your support ticket categories</p>
    </div>

    <!-- Actions Bar -->
    <div class="actions-bar">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle"></i>
            Add New Group
        </button>
        
        <a href="?<?= $show_inactive ? '' : 'show_inactive=1' ?>" class="btn btn-secondary">
            <i class="bi bi-<?= $show_inactive ? 'eye-slash' : 'eye' ?>"></i>
            <?= $show_inactive ? 'Hide Inactive Groups' : 'Show Inactive Groups' ?>
        </a>
    </div>

    <!-- Groups Container -->
    <div class="groups-container">
        <div class="groups-header">
            <h2>Support Groups</h2>
            <div class="groups-stats">
                <div class="stat-item">
                    <i class="bi bi-collection"></i>
                    <span>Total Groups: <span class="stat-value"><?= count($groups) ?></span></span>
                </div>
                <div class="stat-item">
                    <i class="bi bi-check-circle"></i>
                    <span>Active: <span class="stat-value"><?= count(array_filter($groups, fn($g) => $g['active'])) ?></span></span>
                </div>
                <div class="stat-item">
                    <i class="bi bi-x-circle"></i>
                    <span>Inactive: <span class="stat-value"><?= count(array_filter($groups, fn($g) => !$g['active'])) ?></span></span>
                </div>
            </div>
        </div>

        <?php if (empty($groups)): ?>
            <div class="empty-state">
                <i class="bi bi-collection"></i>
                <h3>No Support Groups Found</h3>
                <p>Get started by creating your first support group to organize tickets.</p>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="bi bi-plus-circle"></i>
                    Create First Group
                </button>
            </div>
        <?php else: ?>
            <div class="groups-grid">
                <?php foreach ($groups as $group): ?>
                    <div class="group-card <?= $group['active'] ? '' : 'inactive' ?>">
                        <div class="group-header">
                            <h3 class="group-title"><?= htmlspecialchars($group['name']) ?></h3>
                            <span class="group-id">#<?= $group['id'] ?></span>
                        </div>
                        
                        <p class="group-description"><?= htmlspecialchars($group['description']) ?></p>
                        
                        <div class="group-stats">
                            <span class="ticket-count">
                                <i class="bi bi-ticket"></i>
                                <?= $group['ticket_count'] ?> ticket<?= $group['ticket_count'] != 1 ? 's' : '' ?>
                            </span>
                            <span class="text-muted">
                                Status: <?= $group['active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </div>
                        
                        <div class="group-actions">
                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?= $group['id'] ?>">
                                <i class="bi bi-pencil"></i>
                                Edit
                            </button>
                            <a href="?toggle=<?= $group['id'] ?><?= $show_inactive ? '&show_inactive=1' : '' ?>" 
                               class="btn btn-sm <?= $group['active'] ? 'btn-danger' : 'btn-success' ?>"
                               onclick="return confirm('Are you sure you want to <?= $group['active'] ? 'disable' : 'enable' ?> this group?')">
                                <i class="bi <?= $group['active'] ? 'bi-x-circle' : 'bi-check-circle' ?>"></i>
                                <?= $group['active'] ? 'Disable' : 'Enable' ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle me-2"></i>
                    Create New Support Group
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="add-name" class="form-label">Group Name</label>
                    <input type="text" id="add-name" name="name" class="form-control" placeholder="e.g., Technical Support, Billing, General" required>
                </div>
                <div class="mb-3">
                    <label for="add-description" class="form-label">Description</label>
                    <textarea id="add-description" name="description" class="form-control" rows="3" placeholder="Describe what types of tickets this group handles..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" name="create_group" type="submit">
                    <i class="bi bi-check"></i>
                    Create Group
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modals -->
<?php foreach ($groups as $group): ?>
<div class="modal fade" id="editModal<?= $group['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post">
            <input type="hidden" name="id" value="<?= $group['id'] ?>">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil me-2"></i>
                    Edit Support Group
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="edit-name-<?= $group['id'] ?>" class="form-label">Group Name</label>
                    <input type="text" id="edit-name-<?= $group['id'] ?>" name="name" class="form-control" value="<?= htmlspecialchars($group['name']) ?>" required>
                </div>
                <div class="mb-3">
                    <label for="edit-description-<?= $group['id'] ?>" class="form-label">Description</label>
                    <textarea id="edit-description-<?= $group['id'] ?>" name="description" class="form-control" rows="3" required><?= htmlspecialchars($group['description']) ?></textarea>
                </div>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    This group currently has <strong><?= $group['ticket_count'] ?></strong> ticket<?= $group['ticket_count'] != 1 ? 's' : '' ?> assigned to it.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" name="edit_group" type="submit">
                    <i class="bi bi-check"></i>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<script>
// Add some interactive feedback
document.querySelectorAll('.group-card').forEach(card => {
    card.addEventListener('mouseenter', function() {
        this.style.borderColor = '#4F46E5';
    });
    
    card.addEventListener('mouseleave', function() {
        this.style.borderColor = '#E2E8F0';
    });
});

// Auto-focus on modal inputs
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('shown.bs.modal', function() {
        const firstInput = this.querySelector('input[type="text"]');
        if (firstInput) {
            firstInput.focus();
        }
    });
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>