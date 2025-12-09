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

$message = '';
$message_type = '';
$form_data = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_data = $_POST;
    
    // Validate required fields
    $errors = [];
    
    if (empty($_POST['name'])) {
        $errors[] = "Category name is required.";
    }
    
    // Generate slug if not provided
    $slug = !empty($_POST['slug']) ? $_POST['slug'] : strtolower(str_replace([' ', '.', ','], '-', $_POST['name']));
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    
    // Check if slug already exists
    if (!empty($slug)) {
        $stmt = $pdo->prepare("SELECT id FROM blog_categories WHERE slug = ?");
        $stmt->execute([$slug]);
        if ($stmt->fetch()) {
            $errors[] = "A category with this slug already exists.";
        }
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO blog_categories (name, slug, description, parent_id, sort_order, is_active, meta_title, meta_description)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $_POST['name'],
                $slug,
                $_POST['description'] ?: null,
                $_POST['parent_id'] ?: null,
                (int)($_POST['sort_order'] ?: 0),
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['meta_title'] ?: null,
                $_POST['meta_description'] ?: null
            ]);
            
            $message = "Category created successfully!";
            $message_type = 'success';
            $form_data = []; // Clear form
            
        } catch (Exception $e) {
            $errors[] = "Error creating category: " . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $message = implode('<br>', $errors);
        $message_type = 'danger';
    }
}

// Get parent categories for dropdown
$stmt = $pdo->prepare("SELECT id, name FROM blog_categories WHERE is_active = 1 ORDER BY name");
$stmt->execute();
$parent_categories = $stmt->fetchAll();

$page_title = "Create Category | Blog Admin";
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
            max-width: 800px;
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
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-folder-plus me-3"></i>Create Category</h1>
                <p class="text-muted mb-0">Add a new category to organize your blog content</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Categories
                </a>
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

    <!-- Create Form -->
    <div class="content-card">
        <form method="POST">
            <div class="row">
                <div class="col-md-8">
                    <div class="mb-3">
                        <label for="name" class="form-label">Category Name *</label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?= htmlspecialchars($form_data['name'] ?? '') ?>" 
                               required maxlength="255">
                        <div class="form-text">The name is how it appears on your site.</div>
                    </div>

                    <div class="mb-3">
                        <label for="slug" class="form-label">Slug</label>
                        <input type="text" class="form-control" id="slug" name="slug" 
                               value="<?= htmlspecialchars($form_data['slug'] ?? '') ?>" 
                               maxlength="255">
                        <div class="form-text">The "slug" is the URL-friendly version of the name. Leave blank to auto-generate.</div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" 
                                  rows="3"><?= htmlspecialchars($form_data['description'] ?? '') ?></textarea>
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
                                        <?= ($form_data['parent_id'] ?? '') == $parent['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($parent['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="sort_order" class="form-label">Sort Order</label>
                        <input type="number" class="form-control" id="sort_order" name="sort_order" 
                               value="<?= htmlspecialchars($form_data['sort_order'] ?? '0') ?>" 
                               min="0">
                        <div class="form-text">Lower numbers appear first.</div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                   <?= ($form_data['is_active'] ?? true) ? 'checked' : '' ?>>
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
                       value="<?= htmlspecialchars($form_data['meta_title'] ?? '') ?>" 
                       maxlength="255">
                <div class="form-text">Recommended length: 50-60 characters</div>
            </div>

            <div class="mb-3">
                <label for="meta_description" class="form-label">Meta Description</label>
                <textarea class="form-control" id="meta_description" name="meta_description" 
                          rows="2" maxlength="160"><?= htmlspecialchars($form_data['meta_description'] ?? '') ?></textarea>
                <div class="form-text">Recommended length: 150-160 characters</div>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex justify-content-between">
                <a href="index.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-2"></i>Create Category
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-generate slug from name
document.getElementById('name').addEventListener('input', function() {
    const slugField = document.getElementById('slug');
    if (!slugField.value || slugField.dataset.autoGenerated) {
        const slug = this.value
            .toLowerCase()
            .replace(/[^a-z0-9\s\-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/\-+/g, '-')
            .replace(/^\-|\-$/g, '');
        slugField.value = slug;
        slugField.dataset.autoGenerated = 'true';
    }
});

document.getElementById('slug').addEventListener('input', function() {
    this.dataset.autoGenerated = 'false';
});

// Character counters
function updateCharCount(inputId, maxLength) {
    const input = document.getElementById(inputId);
    const counter = document.createElement('small');
    counter.className = 'text-muted';
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
</script>
</body>
</html>