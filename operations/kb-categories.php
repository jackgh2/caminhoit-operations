<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Check if user is admin or support staff
if (!in_array($_SESSION['user']['role'], ['administrator', 'support_consultant', 'accountant'])) {
    header('Location: /members/dashboard.php');
    exit;
}

// Handle category operations
if ($_POST) {
    try {
        if (isset($_POST['create_category'])) {
            // Create new category
            $name = trim($_POST['name']);
            $description = trim($_POST['description']);
            $parent_id = $_POST['parent_id'] ?: null;
            $icon = trim($_POST['icon']);
            $color = trim($_POST['color']);
            
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
            
            if (empty($name)) {
                throw new Exception("Category name is required.");
            }
            
            // Check for duplicate slug
            $stmt = $pdo->prepare("SELECT id FROM kb_categories WHERE slug = ?");
            $stmt->execute([$slug]);
            if ($stmt->fetchColumn()) {
                $slug .= '-' . time();
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO kb_categories (name, slug, description, parent_id, icon, color, sort_order)
                SELECT ?, ?, ?, ?, ?, ?, COALESCE(MAX(sort_order), 0) + 1
                FROM kb_categories
            ");
            $stmt->execute([$name, $slug, $description, $parent_id, $icon, $color]);
            
            $success = "Category created successfully!";
            
        } elseif (isset($_POST['update_category'])) {
            // Update existing category
            $id = (int)$_POST['category_id'];
            $name = trim($_POST['name']);
            $description = trim($_POST['description']);
            $parent_id = $_POST['parent_id'] ?: null;
            $icon = trim($_POST['icon']);
            $color = trim($_POST['color']);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($name)) {
                throw new Exception("Category name is required.");
            }
            
            // Prevent circular parent relationships
            if ($parent_id == $id) {
                throw new Exception("A category cannot be its own parent.");
            }
            
            $stmt = $pdo->prepare("
                UPDATE kb_categories 
                SET name = ?, description = ?, parent_id = ?, icon = ?, color = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $description, $parent_id, $icon, $color, $is_active, $id]);
            
            $success = "Category updated successfully!";
            
        } elseif (isset($_POST['delete_category'])) {
            // Delete category
            $id = (int)$_POST['category_id'];
            
            // Check if category has articles
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM kb_articles WHERE category_id = ?");
            $stmt->execute([$id]);
            $article_count = $stmt->fetchColumn();
            
            if ($article_count > 0) {
                throw new Exception("Cannot delete category with existing articles. Please move or delete the articles first.");
            }
            
            // Check if category has subcategories
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM kb_categories WHERE parent_id = ?");
            $stmt->execute([$id]);
            $subcategory_count = $stmt->fetchColumn();
            
            if ($subcategory_count > 0) {
                throw new Exception("Cannot delete category with subcategories. Please move or delete the subcategories first.");
            }
            
            $stmt = $pdo->prepare("DELETE FROM kb_categories WHERE id = ?");
            $stmt->execute([$id]);
            
            $success = "Category deleted successfully!";
            
        } elseif (isset($_POST['reorder_categories'])) {
            // Update sort order
            $orders = $_POST['category_order'] ?? [];
            
            foreach ($orders as $id => $order) {
                $stmt = $pdo->prepare("UPDATE kb_categories SET sort_order = ? WHERE id = ?");
                $stmt->execute([(int)$order, (int)$id]);
            }
            
            $success = "Category order updated successfully!";
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load categories with article counts
try {
    $stmt = $pdo->query("
        SELECT c.*, 
               COUNT(a.id) as article_count,
               parent.name as parent_name
        FROM kb_categories c
        LEFT JOIN kb_articles a ON c.id = a.category_id
        LEFT JOIN kb_categories parent ON c.parent_id = parent.id
        GROUP BY c.id
        ORDER BY c.sort_order, c.name
    ");
    $categories = $stmt->fetchAll();
    
    // Build category tree
    $category_tree = [];
    $category_lookup = [];
    
    foreach ($categories as $category) {
        $category_lookup[$category['id']] = $category;
        if ($category['parent_id']) {
            $category_lookup[$category['parent_id']]['children'][] = $category;
        } else {
            $category_tree[] = $category;
        }
    }
    
} catch (Exception $e) {
    $error = "Error loading categories: " . $e->getMessage();
    $categories = [];
    $category_tree = [];
}

$page_title = "Knowledge Base Categories";
?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.css">
    <style>
        .category-card {
            transition: all 0.2s ease;
            cursor: move;
        }
        .category-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
        }
        .category-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            color: white;
            font-size: 1.2rem;
        }
        .subcategory-card {
            margin-left: 20px;
            border-left: 3px solid #e9ecef;
        }
        .sortable-ghost {
            opacity: 0.4;
        }
        .color-picker {
            width: 50px;
            height: 38px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        .icon-preview {
            width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            color: white;
            margin-right: 8px;
        }
    </style>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1">
                            <i class="bi bi-tags text-primary me-2"></i>
                            Knowledge Base Categories
                        </h1>
                        <p class="text-muted mb-0">Organize your articles with categories and subcategories</p>
                    </div>
                    <div class="btn-group">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal">
                            <i class="bi bi-plus-circle me-1"></i>
                            New Category
                        </button>
                        <a href="/operations/kb-dashboard.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>
                            Dashboard
                        </a>
                    </div>
                </div>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle me-2"></i>
                        <?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Categories Grid -->
                <?php if (empty($category_tree)): ?>
                    <div class="card border-0 shadow-sm">
                        <div class="card-body text-center py-5">
                            <i class="bi bi-tags text-muted" style="font-size: 3rem;"></i>
                            <h5 class="mt-3 text-muted">No categories yet</h5>
                            <p class="text-muted mb-3">Create your first category to organize your knowledge base articles</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal">
                                <i class="bi bi-plus-circle me-1"></i>
                                Create First Category
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div id="categoriesContainer" class="row">
                        <?php foreach ($category_tree as $category): ?>
                            <?php include 'kb-category-card.php'; ?>
                        <?php endforeach; ?>
                    </div>

                    <!-- Reorder Form -->
                    <form method="POST" id="reorderForm" style="display: none;">
                        <input type="hidden" name="reorder_categories" value="1">
                        <div id="orderInputs"></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Category Modal -->
    <div class="modal fade" id="categoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitle">New Category</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="category_id" id="categoryId">
                        
                        <div class="mb-3">
                            <label class="form-label">Category Name *</label>
                            <input type="text" class="form-control" name="name" id="categoryName" required maxlength="100">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="categoryDescription" rows="3" maxlength="500"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Parent Category</label>
                            <select class="form-select" name="parent_id" id="categoryParent">
                                <option value="">None (Top Level)</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-8">
                                <label class="form-label">Icon Class</label>
                                <input type="text" class="form-control" name="icon" id="categoryIcon" 
                                       placeholder="bi-folder" value="bi-folder"
                                       oninput="updateIconPreview()">
                                <small class="form-text text-muted">Bootstrap Icons class (e.g., bi-folder, bi-gear)</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Color</label>
                                <input type="color" class="form-control color-picker" name="color" 
                                       id="categoryColor" value="#6c757d" oninput="updateIconPreview()">
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">Preview</label>
                            <div class="d-flex align-items-center">
                                <div id="iconPreview" class="icon-preview" style="background-color: #6c757d;">
                                    <i class="bi bi-folder"></i>
                                </div>
                                <span id="namePreview">Category Name</span>
                            </div>
                        </div>

                        <div class="form-check mt-3" id="activeCheckContainer" style="display: none;">
                            <input class="form-check-input" type="checkbox" id="categoryActive" name="is_active" checked>
                            <label class="form-check-label" for="categoryActive">
                                Active
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_category" id="createBtn" class="btn btn-primary">Create Category</button>
                        <button type="submit" name="update_category" id="updateBtn" class="btn btn-primary" style="display: none;">Update Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this category?</p>
                    <p class="fw-bold" id="deleteCategoryName"></p>
                    <p class="text-danger small">This action cannot be undone. Make sure the category has no articles or subcategories.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="category_id" id="deleteCategoryId">
                        <button type="submit" name="delete_category" class="btn btn-danger">Delete Category</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script>
        // Initialize sortable
        const container = document.getElementById('categoriesContainer');
        if (container) {
            Sortable.create(container, {
                animation: 150,
                ghostClass: 'sortable-ghost',
                onEnd: function(evt) {
                    updateCategoryOrder();
                }
            });
        }

        function updateCategoryOrder() {
            const cards = document.querySelectorAll('.category-card[data-category-id]');
            const orderInputs = document.getElementById('orderInputs');
            orderInputs.innerHTML = '';
            
            cards.forEach((card, index) => {
                const categoryId = card.getAttribute('data-category-id');
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `category_order[${categoryId}]`;
                input.value = index + 1;
                orderInputs.appendChild(input);
            });
            
            // Submit the form
            document.getElementById('reorderForm').submit();
        }

        function editCategory(id, name, description, parentId, icon, color, isActive) {
            document.getElementById('modalTitle').textContent = 'Edit Category';
            document.getElementById('categoryId').value = id;
            document.getElementById('categoryName').value = name;
            document.getElementById('categoryDescription').value = description;
            document.getElementById('categoryParent').value = parentId || '';
            document.getElementById('categoryIcon').value = icon;
            document.getElementById('categoryColor').value = color;
            document.getElementById('categoryActive').checked = isActive;
            
            document.getElementById('createBtn').style.display = 'none';
            document.getElementById('updateBtn').style.display = 'block';
            document.getElementById('activeCheckContainer').style.display = 'block';
            
            updateIconPreview();
            
            new bootstrap.Modal(document.getElementById('categoryModal')).show();
        }

        function deleteCategory(id, name) {
            document.getElementById('deleteCategoryId').value = id;
            document.getElementById('deleteCategoryName').textContent = name;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        function updateIconPreview() {
            const icon = document.getElementById('categoryIcon').value || 'bi-folder';
            const color = document.getElementById('categoryColor').value;
            const name = document.getElementById('categoryName').value || 'Category Name';
            
            const preview = document.getElementById('iconPreview');
            preview.style.backgroundColor = color;
            preview.innerHTML = `<i class="${icon}"></i>`;
            
            document.getElementById('namePreview').textContent = name;
        }

        // Reset modal when hidden
        document.getElementById('categoryModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('modalTitle').textContent = 'New Category';
            document.getElementById('categoryId').value = '';
            document.getElementById('categoryName').value = '';
            document.getElementById('categoryDescription').value = '';
            document.getElementById('categoryParent').value = '';
            document.getElementById('categoryIcon').value = 'bi-folder';
            document.getElementById('categoryColor').value = '#6c757d';
            document.getElementById('categoryActive').checked = true;
            
            document.getElementById('createBtn').style.display = 'block';
            document.getElementById('updateBtn').style.display = 'none';
            document.getElementById('activeCheckContainer').style.display = 'none';
            
            updateIconPreview();
        });

        // Initialize preview
        document.addEventListener('DOMContentLoaded', function() {
            updateIconPreview();
            
            // Update preview when name changes
            document.getElementById('categoryName').addEventListener('input', updateIconPreview);
        });
    </script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>