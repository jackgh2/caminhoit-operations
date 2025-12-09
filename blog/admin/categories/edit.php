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

// Check permissions
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_role = $stmt->fetchColumn();

if (!in_array($user_role, ['administrator', 'support_user', 'support_technician'])) {
    header('Location: /dashboard.php');
    exit;
}

$category_id = $_GET['id'] ?? 0;
$message = '';
$message_type = '';

// Get category data
$stmt = $pdo->prepare("SELECT * FROM blog_categories WHERE id = ?");
$stmt->execute([$category_id]);
$category = $stmt->fetch();

if (!$category) {
    header('Location: index.php?error=Category not found');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    // Validate required fields
    if (empty($_POST['name'])) {
        $errors[] = "Category name is required.";
    }
    
    // Generate slug if not provided
    $slug = !empty($_POST['slug']) ? $_POST['slug'] : strtolower(str_replace([' ', '.', ','], '-', $_POST['name']));
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    
    // Check if slug already exists (excluding current category)
    if (!empty($slug)) {
        $stmt = $pdo->prepare("SELECT id FROM blog_categories WHERE slug = ? AND id != ?");
        $stmt->execute([$slug, $category_id]);
        if ($stmt->fetch()) {
            $errors[] = "A category with this slug already exists.";
        }
    }
    
    // Check for circular parent relationship
    if (!empty($_POST['parent_id']) && $_POST['parent_id'] == $category_id) {
        $errors[] = "A category cannot be its own parent.";
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE blog_categories 
                SET name = ?, slug = ?, description = ?, parent_id = ?, sort_order = ?, 
                    is_active = ?, meta_title = ?, meta_description = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $_POST['name'],
                $slug,
                $_POST['description'] ?: null,
                $_POST['parent_id'] ?: null,
                (int)($_POST['sort_order'] ?: 0),
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['meta_title'] ?: null,
                $_POST['meta_description'] ?: null,
                $category_id
            ]);
            
            $message = "Category updated successfully!";
            $message_type = 'success';
            
            // Refresh category data
            $stmt = $pdo->prepare("SELECT * FROM blog_categories WHERE id = ?");
            $stmt->execute([$category_id]);
            $category = $stmt->fetch();
            
        } catch (Exception $e) {
            $errors[] = "Error updating category: " . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $message = implode('<br>', $errors);
        $message_type = 'danger';
    }
} else {
    // Pre-fill form with existing data
    $_POST = $category;
}

// Get parent categories for dropdown (excluding current category and its children)
$stmt = $pdo->prepare("
    SELECT id, name 
    FROM blog_categories 
    WHERE is_active = 1 AND id != ? AND parent_id != ?
    ORDER BY name
");
$stmt->execute([$category_id, $category_id]);
$parent_categories = $stmt->fetchAll();

// Get post count for this category
$stmt = $pdo->prepare("SELECT COUNT(*) as post_count FROM blog_posts WHERE category_id = ?");
$stmt->execute([$category_id]);
$post_count = $stmt->fetchColumn();

$page_title = "Edit Category: " . htmlspecialchars($category['name']) . " | Blog Admin";
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
        .main-container {
            max-width: 900px;
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
        .content-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 2rem;
        }
        .form-label {
            font-weight: 600;
            color: #374151;
        }
        .form-control, .form-select {
            border: 1px solid #d1d5db;
            border-radius: 6px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .danger-zone {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 2rem;
        }
        .danger-zone h5 {
            color: #dc2626;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-pencil-square me-3"></i>Edit Category</h1>
                <p class="text-muted mb-0">Editing: <strong><?= htmlspecialchars($category['name']) ?></strong></p>
                <small class="text-muted">Created: <?= date('M j, Y g:i A', strtotime($category['created_at'])) ?> | Posts: <?= $post_count ?></small>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary me-2">
                    <i class="bi bi-arrow-left me-2"></i>Back to Categories
                </a>
                <?php if ($post_count > 0): ?>
                    <a href="/blog/admin/posts/?category=<?= $category['id'] ?>" class="btn btn-outline-info">
                        <i class="bi bi-file-text me-2"></i>View Posts (<?= $post_count ?>)
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Edit Form -->
    <div class="content-card">
        <form method="POST">
            <div class="row">
                <div class="col-md-8">
                    <div class="mb-3">
                        <label for="name" class="form-label">Category Name *</label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" 
                               required maxlength="255">
                        <div class="form-text">The name is how it appears on your site.</div>
                    </div>

                    <div class="mb-3">
                        <label for="slug" class="form-label">Slug</label>
                        <input type="text" class="form-control" id="slug" name="slug" 
                               value="<?= htmlspecialchars($_POST['slug'] ?? '') ?>" 
                               maxlength="255">
                        <div class="form-text">The "slug" is the URL-friendly version of the name.</div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" 
                                  rows="3"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                        <div class="form-text">Optional description for the category.</div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="parent_id" class="form-label">Parent Category</label>
                        <select class="form-select" id="parent_id" name="parent_id">
                            <option value="">None (Top Level)</option>
                            <?php foreach ($parent_categories as $parent): ?>
                                <option value="<?= $parent['id'] ?>" 
                                        <?= ($_POST['parent_id'] ?? '') == $parent['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($parent['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="sort_order" class="form-label">Sort Order</label>
                        <input type="number" class="form-control" id="sort_order" name="sort_order" 
                               value="<?= htmlspecialchars($_POST['sort_order'] ?? '0') ?>" 
                               min="0">
                        <div class="form-text">Lower numbers appear first.</div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                   <?= ($_POST['is_active'] ?? false) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">
                                Active
                            </label>
                        </div>
                        <div class="form-text">Inactive categories won't be shown to visitors.</div>
                    </div>
                </div>
            </div>

            <!-- SEO Section -->
            <hr class="my-4">
            <h5 class="mb-3">SEO Settings (Optional)</h5>
            
            <div class="mb-3">
                <label for="meta_title" class="form-label">Meta Title</label>
                <input type="text" class="form-control" id="meta_title" name="meta_title" 
                       value="<?= htmlspecialchars($_POST['meta_title'] ?? '') ?>" 
                       maxlength="255">
                <div class="form-text">Recommended length: 50-60 characters</div>
            </div>

            <div class="mb-3">
                <label for="meta_description" class="form-label">Meta Description</label>
                <textarea class="form-control" id="meta_description" name="meta_description" 
                          rows="2" maxlength="160"><?= htmlspecialchars($_POST['meta_description'] ?? '') ?></textarea>
                <div class="form-text">Recommended length: 150-160 characters</div>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex justify-content-between">
                <a href="index.php" class="btn btn-secondary">Cancel</a>
                <div>
                    <a href="/blog/?category=<?= $category['slug'] ?>" class="btn btn-outline-info me-2" target="_blank">
                        <i class="bi bi-eye me-2"></i>Preview
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>Update Category
                    </button>
                </div>
            </div>
        </form>

        <!-- Danger Zone -->
        <?php if ($post_count == 0): ?>
            <div class="danger-zone">
                <h5><i class="bi bi-exclamation-triangle me-2"></i>Danger Zone</h5>
                <p class="mb-3">This category has no posts associated with it. You can safely delete it.</p>
                <button type="button" class="btn btn-danger" onclick="deleteCategory()">
                    <i class="bi bi-trash me-2"></i>Delete Category
                </button>
            </div>
        <?php else: ?>
            <div class="danger-zone">
                <h5><i class="bi bi-info-circle me-2"></i>Category Information</h5>
                <p class="mb-0">This category contains <strong><?= $post_count ?></strong> post<?= $post_count > 1 ? 's' : '' ?>. 
                To delete this category, you must first move or delete all associated posts.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-generate slug from name (but allow manual override)
document.getElementById('name').addEventListener('input', function() {
    const slugField = document.getElementById('slug');
    if (!slugField.dataset.manualEdit) {
        const slug = this.value
            .toLowerCase()
            .replace(/[^a-z0-9\s\-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/\-+/g, '-')
            .replace(/^\-|\-$/g, '');
        slugField.value = slug;
    }
});

document.getElementById('slug').addEventListener('input', function() {
    this.dataset.manualEdit = 'true';
});

// Character counters
function updateCharCount(inputId, maxLength) {
    const input = document.getElementById(inputId);
    const counter = document.createElement('small');
    counter.className = 'text-muted mt-1';
    input.parentNode.appendChild(counter);
    
    function update() {
        const remaining = maxLength - input.value.length;
        counter.textContent = `${input.value.length}/${maxLength} characters`;
        counter.style.color = remaining < 20 ? '#dc3545' : '#6c757d';
    }
    
    input.addEventListener('input', update);
    update();
}

updateCharCount('meta_title', 255);
updateCharCount('meta_description', 160);

function deleteCategory() {
    if (confirm('Are you sure you want to delete this category? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'delete.php';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'id';
        idInput.value = '<?= $category_id ?>';
        
        form.appendChild(idInput);
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
</body>
</html>