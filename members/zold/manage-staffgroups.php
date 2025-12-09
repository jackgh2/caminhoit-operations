<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/header.php';

// Access control (optional - restrict by role)
if (!in_array($_SESSION['role'], ['administrator'])) {
    echo "<div class='container mt-5 alert alert-danger'>Access denied.</div>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Fetch groups
try {
    $stmt = $pdo->query("SELECT * FROM support_ticket_groups ORDER BY id DESC");
    $groups = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<div class="container mt-5">
    <h2 class="mb-4">Manage Staff Groups</h2>

    <!-- Add Group Button -->
    <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addGroupModal">+ Add Group</button>

    <?php if (count($groups) === 0): ?>
        <div class="alert alert-info">No staff groups found.</div>
    <?php else: ?>
        <table class="table table-bordered table-hover">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Group Name</th>
                    <th>Description</th>
                    <th>Active</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $group): ?>
                    <tr>
                        <td><?= htmlspecialchars($group['id']) ?></td>
                        <td><?= htmlspecialchars($group['name']) ?></td>
                        <td><?= htmlspecialchars($group['description']) ?></td>
                        <td>
                            <span class="badge bg-<?= $group['active'] ? 'success' : 'secondary' ?>">
                                <?= $group['active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars(date("Y-m-d", strtotime($group['created_at']))) ?></td>
                        <td>
                            <!-- You can wire these up to AJAX or modals -->
                            <button class="btn btn-sm btn-warning">Edit</button>
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Add Group Modal -->
<div class="modal fade" id="addGroupModal" tabindex="-1" aria-labelledby="addGroupModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="save-group.php" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addGroupModalLabel">Add New Group</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="groupName" class="form-label">Group Name</label>
                    <input type="text" name="name" id="groupName" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="groupDesc" class="form-label">Description</label>
                    <textarea name="description" id="groupDesc" class="form-control" rows="3"></textarea>
                </div>
                <div class="mb-3">
                    <label for="groupActive" class="form-label">Active</label>
                    <select name="active" id="groupActive" class="form-select">
                        <option value="1" selected>Yes</option>
                        <option value="0">No</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Save Group</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
