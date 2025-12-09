<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /login.php');
    exit;
}

// Check permissions
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_role = $stmt->fetchColumn();

if (!in_array($user_role, ['administrator', 'support_user', 'support_technician'])) {
    header('Location: /dashboard.php');
    exit;
}

// Handle bulk actions
if ($_POST['bulk_action'] ?? false) {
    $action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_categories'] ?? [];
    
    if (!empty($selected_ids) && in_array($action, ['activate', 'deactivate', 'delete'])) {
        $placeholders = str_repeat('?,', count($selected_ids) - 1) . '?';
        
        switch ($action) {
            case 'activate':
                $stmt = $pdo->prepare("UPDATE blog_categories SET is_active = 1 WHERE id IN ($placeholders)");
                $stmt->execute($selected_ids);
                $success_message = count($selected_ids) . " categories activated successfully.";
                break;
            case 'deactivate':
                $stmt = $pdo->prepare("UPDATE blog_categories SET is_active = 0 WHERE id IN ($placeholders)");
                $stmt->execute($selected_ids);
                $success_message = count($selected_ids) . " categories deactivated successfully.";
                break;
            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM blog_categories WHERE id IN ($placeholders)");
                $stmt->execute($selected_ids);
                $success_message = count($selected_ids) . " categories deleted successfully.";
                break;
        }
    }
}

// Get categories with post counts
$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.slug, c.description, c.parent_id, c.sort_order, c.is_active, c.created_at,
           pc.name as parent_name,
           COUNT(p.id) as post_count
    FROM blog_categories c
    LEFT JOIN blog_categories pc ON c.parent_id = pc.id
    LEFT JOIN blog_posts p ON c.id = p.category_id
    GROUP BY c.id, c.name, c.slug, c.description, c.parent_id, c.sort_order, c.is_active, c.created_at, pc.name
    ORDER BY c.sort_order ASC, c.name ASC
");
$stmt->execute();
$categories = $stmt->fetchAll();

$page_title = "Blog Categories | Admin";
?>

<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">
    
    <style>
        body {
            background-color: #f8fafc;
            padding-top: 80px;
        }

        .navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15) !important;
            position: fixed !important;
            top: 0 !important;
            z-index: 1030 !important;
        }

        .main-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .page-header {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }

        .btn-primary {
            background: #4F46E5;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-primary:hover {
            background: #3F37C9;
            transform: translateY(-1px);
        }

        .categories-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .table th {
            background: #f8fafc;
            color: #374151;
            font-weight: 600;
            border: none;
            padding: 1rem;
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background: #f8fafc;
        }

        .badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
        }

        .badge.bg-success { background: #10B981 !important; }
        .badge.bg-secondary { background: #6B7280 !important; }

        .bulk-actions {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .bulk-actions h5 {
            margin-bottom: 1rem;
            color: #374151;
            font-weight: 600;
        }

        .action-controls {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="main-container">
    <!-- Success Message -->
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= $success_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1><i class="bi bi-tags me-3"></i>Blog Categories</h1>
            <p class="text-muted mb-0">Organize your blog posts with categories</p>
        </div>
        <div>
            <a href="/blog/admin/dashboard.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
            </a>
            <a href="/blog/admin/categories/create.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-2"></i>Add Category
            </a>
        </div>
    </div>

    <!-- Bulk Actions -->
    <form method="POST" id="categoriesForm">
        <div class="bulk-actions">
            <h5><i class="bi bi-check2-square me-2"></i>Bulk Actions</h5>
            <div class="action-controls">
                <select name="bulk_action" class="form-select" style="width: auto;">
                    <option value="">Choose action...</option>
                    <option value="activate">Activate Selected</option>
                    <option value="deactivate">Deactivate Selected</option>
                    <option value="delete">Delete Selected</option>
                </select>
                <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirmBulkAction()">
                    <i class="bi bi-play-fill me-1"></i>Apply
                </button>
                <div class="ms-auto">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectAll()">Select All</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectNone()">Select None</button>
                </div>
            </div>
        </div>

        <!-- Categories Table -->
        <div class="categories-table">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th width="50">
                            <input type="checkbox" id="selectAllCheckbox" onchange="toggleAll()">
                        </th>
                        <th>Name</th>
                        <th>Slug</th>
                        <th>Parent</th>
                        <th>Posts</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th width="150">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <i class="bi bi-tags text-muted" style="font-size: 2rem;"></i>
                                <p class="text-muted mt-2 mb-0">No categories found. <a href="/blog/admin/categories/create.php">Create your first category</a></p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categories as $category): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="selected_categories[]" value="<?= $category['id'] ?>" class="category-checkbox">
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($category['name']) ?></strong>
                                    <?php if ($category['description']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars(substr($category['description'], 0, 100)) ?><?= strlen($category['description']) > 100 ? '...' : '' ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code><?= htmlspecialchars($category['slug']) ?></code>
                                </td>
                                <td>
                                    <?= $category['parent_name'] ? htmlspecialchars($category['parent_name']) : '-' ?>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?= $category['post_count'] ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $category['is_active'] ? 'success' : 'secondary' ?>">
                                        <?= $category['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td>
                                    <?= date('M j, Y', strtotime($category['created_at'])) ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="/blog/admin/categories/edit.php?id=<?= $category['id'] ?>" class="btn btn-outline-primary">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="/blog/?category=<?= $category['slug'] ?>" class="btn btn-outline-info" target="_blank">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger" onclick="deleteCategory(<?= $category['id'] ?>, '<?= htmlspecialchars($category['name'], ENT_QUOTES) ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleAll() {
    const selectAll = document.getElementById('selectAllCheckbox');
    const checkboxes = document.querySelectorAll('.category-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
    });
}

function selectAll() {
    const checkboxes = document.querySelectorAll('.category-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    document.getElementById('selectAllCheckbox').checked = true;
}

function selectNone() {
    const checkboxes = document.querySelectorAll('.category-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    document.getElementById('selectAllCheckbox').checked = false;
}

function confirmBulkAction() {
    const action = document.querySelector('[name="bulk_action"]').value;
    const selected = document.querySelectorAll('.category-checkbox:checked');
    
    if (!action) {
        alert('Please select an action.');
        return false;
    }
    
    if (selected.length === 0) {
        alert('Please select at least one category.');
        return false;
    }
    
    const actionText = action === 'delete' ? 'delete' : action;
    return confirm(`Are you sure you want to ${actionText} ${selected.length} selected categor${selected.length > 1 ? 'ies' : 'y'}?`);
}

function deleteCategory(id, name) {
    if (confirm(`Are you sure you want to delete the category "${name}"? This action cannot be undone.`)) {
        // Create a form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/blog/admin/categories/delete.php';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'id';
        idInput.value = id;
        
        form.appendChild(idInput);
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

</body>
</html>