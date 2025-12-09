<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Access control (Staff and Admin only)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'staff'])) {
    header('Location: /login.php');
    exit;
}

// Handle bulk actions
if ($_POST && isset($_POST['bulk_action']) && isset($_POST['selected_articles'])) {
    $selected_ids = array_map('intval', $_POST['selected_articles']);
    $action = $_POST['bulk_action'];
    
    if (!empty($selected_ids)) {
        $placeholders = str_repeat('?,', count($selected_ids) - 1) . '?';
        
        try {
            switch ($action) {
                case 'publish':
                    $stmt = $pdo->prepare("UPDATE kb_articles SET status = 'published', published_at = NOW() WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $success = "Selected articles published successfully.";
                    break;
                    
                case 'draft':
                    $stmt = $pdo->prepare("UPDATE kb_articles SET status = 'draft' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $success = "Selected articles moved to draft.";
                    break;
                    
                case 'archive':
                    $stmt = $pdo->prepare("UPDATE kb_articles SET status = 'archived' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $success = "Selected articles archived.";
                    break;
                    
                case 'delete':
                    $stmt = $pdo->prepare("DELETE FROM kb_articles WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $success = "Selected articles deleted permanently.";
                    break;
            }
        } catch (Exception $e) {
            $error = "Error performing bulk action: " . $e->getMessage();
        }
    }
}

// Get filters
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query
$where_conditions = [];
$params = [];

if ($status_filter) {
    $where_conditions[] = "a.status = ?";
    $params[] = $status_filter;
}

if ($category_filter) {
    $where_conditions[] = "a.category_id = ?";
    $params[] = $category_filter;
}

if ($search) {
    $where_conditions[] = "(a.title LIKE ? OR a.content LIKE ? OR a.search_keywords LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

try {
    // Get total count
    $count_sql = "SELECT COUNT(*) FROM kb_articles a $where_clause";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_articles = $stmt->fetchColumn();
    
    // Get articles
    $sql = "
        SELECT a.*, c.name as category_name, c.color as category_color, u.username as author_name
        FROM kb_articles a
        LEFT JOIN kb_categories c ON a.category_id = c.id
        LEFT JOIN users u ON a.author_id = u.id
        $where_clause
        ORDER BY a.created_at DESC
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $articles = $stmt->fetchAll();
    
    // Get categories for filter
    $stmt = $pdo->query("SELECT id, name FROM kb_categories WHERE is_active = 1 ORDER BY sort_order, name");
    $categories = $stmt->fetchAll();
    
} catch (Exception $e) {
    $error = "Error loading articles: " . $e->getMessage();
    $articles = [];
    $categories = [];
    $total_articles = 0;
}

$total_pages = ceil($total_articles / $per_page);
$page_title = "Knowledge Base Articles";
?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>
    <style>
        .article-row:hover {
            background-color: #f8f9fa;
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        .category-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            color: white;
            border-radius: 12px;
        }
        .bulk-actions {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: none;
        }
        .bulk-actions.show {
            display: block;
        }
    </style>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1">
                            <i class="bi bi-file-text text-primary me-2"></i>
                            Knowledge Base Articles
                        </h1>
                        <p class="text-muted mb-0">Manage your help articles and documentation</p>
                    </div>
                    <div class="btn-group">
                        <a href="/operations/kb-articles-create.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>
                            New Article
                        </a>
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

                <!-- Filters -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Search Articles</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-search"></i>
                                    </span>
                                    <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search title, content, or keywords...">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All Statuses</option>
                                    <option value="draft" <?= $status_filter === 'draft' ? 'selected' : '' ?>>Draft</option>
                                    <option value="published" <?= $status_filter === 'published' ? 'selected' : '' ?>>Published</option>
                                    <option value="archived" <?= $status_filter === 'archived' ? 'selected' : '' ?>>Archived</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Category</label>
                                <select name="category" class="form-select">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['id'] ?>" <?= $category_filter == $category['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($category['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-funnel me-1"></i>
                                        Filter
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Bulk Actions -->
                <div id="bulkActions" class="bulk-actions">
                    <form method="POST" id="bulkForm">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <strong><span id="selectedCount">0</span> articles selected</strong>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="btn-group">
                                    <select name="bulk_action" class="form-select form-select-sm me-2" style="width: auto;">
                                        <option value="">Choose action...</option>
                                        <option value="publish">Publish</option>
                                        <option value="draft">Move to Draft</option>
                                        <option value="archive">Archive</option>
                                        <option value="delete">Delete</option>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary" onclick="return confirmBulkAction()">
                                        Apply
                                    </button>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="clearSelection()">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Articles Table -->
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-0">
                        <?php if (empty($articles)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-file-text text-muted" style="font-size: 3rem;"></i>
                                <h5 class="mt-3 text-muted">No articles found</h5>
                                <p class="text-muted mb-3">
                                    <?php if ($search || $status_filter || $category_filter): ?>
                                        Try adjusting your filters or 
                                        <a href="/operations/kb-articles.php">view all articles</a>
                                    <?php else: ?>
                                        Get started by creating your first knowledge base article
                                    <?php endif; ?>
                                </p>
                                <?php if (!$search && !$status_filter && !$category_filter): ?>
                                    <a href="/operations/kb-articles-create.php" class="btn btn-primary">
                                        <i class="bi bi-plus-circle me-1"></i>
                                        Create First Article
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="40">
                                                <input type="checkbox" id="selectAll" class="form-check-input">
                                            </th>
                                            <th>Title</th>
                                            <th width="120">Category</th>
                                            <th width="100">Status</th>
                                            <th width="120">Author</th>
                                            <th width="80">Views</th>
                                            <th width="100">Created</th>
                                            <th width="100">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($articles as $article): ?>
                                            <tr class="article-row">
                                                <td>
                                                    <input type="checkbox" name="selected_articles[]" value="<?= $article['id'] ?>" class="form-check-input article-checkbox">
                                                </td>
                                                <td>
                                                    <div>
                                                        <a href="/operations/kb-articles-edit.php?id=<?= $article['id'] ?>" class="text-decoration-none fw-medium">
                                                            <?= htmlspecialchars($article['title']) ?>
                                                        </a>
                                                        <?php if ($article['featured']): ?>
                                                            <i class="bi bi-star-fill text-warning ms-1" title="Featured"></i>
                                                        <?php endif; ?>
                                                        <?php if ($article['excerpt']): ?>
                                                            <div class="text-muted small mt-1">
                                                                <?= htmlspecialchars(substr($article['excerpt'], 0, 100)) ?>...
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($article['category_name']): ?>
                                                        <span class="category-badge" style="background-color: <?= htmlspecialchars($article['category_color']) ?>">
                                                            <?= htmlspecialchars($article['category_name']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_colors = [
                                                        'published' => 'success',
                                                        'draft' => 'warning',
                                                        'archived' => 'secondary'
                                                    ];
                                                    $color = $status_colors[$article['status']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?= $color ?> status-badge">
                                                        <?= ucfirst($article['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?= htmlspecialchars($article['author_name']) ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?= number_format($article['view_count']) ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?= date('M j, Y', strtotime($article['created_at'])) ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="/operations/kb-articles-edit.php?id=<?= $article['id'] ?>" class="btn btn-outline-primary btn-sm" title="Edit">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <a href="/kb/<?= htmlspecialchars($article['slug']) ?>" class="btn btn-outline-info btn-sm" title="View" target="_blank">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-outline-danger btn-sm" title="Delete" onclick="deleteArticle(<?= $article['id'] ?>, '<?= htmlspecialchars($article['title']) ?>')">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                                <div class="card-footer bg-white border-0">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="text-muted small">
                                            Showing <?= $offset + 1 ?> to <?= min($offset + $per_page, $total_articles) ?> of <?= number_format($total_articles) ?> articles
                                        </div>
                                        <nav>
                                            <ul class="pagination pagination-sm mb-0">
                                                <?php if ($page > 1): ?>
                                                    <li class="page-item">
                                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Previous</a>
                                                    </li>
                                                <?php endif; ?>

                                                <?php
                                                $start = max(1, $page - 2);
                                                $end = min($total_pages, $page + 2);
                                                ?>

                                                <?php for ($i = $start; $i <= $end; $i++): ?>
                                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                                    </li>
                                                <?php endfor; ?>

                                                <?php if ($page < $total_pages): ?>
                                                    <li class="page-item">
                                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a>
                                                    </li>
                                                <?php endif; ?>
                                            </ul>
                                        </nav>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
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
                    <p>Are you sure you want to delete this article?</p>
                    <p class="fw-bold" id="deleteArticleTitle"></p>
                    <p class="text-danger small">This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="delete_id" id="deleteArticleId">
                        <button type="submit" name="delete_article" class="btn btn-danger">Delete Article</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Handle select all
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.article-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });

        // Handle individual checkboxes
        document.querySelectorAll('.article-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateBulkActions);
        });

        function updateBulkActions() {
            const checkedBoxes = document.querySelectorAll('.article-checkbox:checked');
            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');
            
            selectedCount.textContent = checkedBoxes.length;
            
            if (checkedBoxes.length > 0) {
                bulkActions.classList.add('show');
                // Add hidden inputs for selected articles
                const form = document.getElementById('bulkForm');
                const existingInputs = form.querySelectorAll('input[name="selected_articles[]"]');
                existingInputs.forEach(input => input.remove());
                
                checkedBoxes.forEach(checkbox => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'selected_articles[]';
                    input.value = checkbox.value;
                    form.appendChild(input);
                });
            } else {
                bulkActions.classList.remove('show');
            }
        }

        function clearSelection() {
            document.querySelectorAll('.article-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });
            document.getElementById('selectAll').checked = false;
            updateBulkActions();
        }

        function confirmBulkAction() {
            const action = document.querySelector('select[name="bulk_action"]').value;
            if (!action) {
                alert('Please select an action first.');
                return false;
            }
            
            const count = document.querySelectorAll('.article-checkbox:checked').length;
            
            if (action === 'delete') {
                return confirm(`Are you sure you want to delete ${count} article(s)? This action cannot be undone.`);
            }
            
            return confirm(`Are you sure you want to ${action} ${count} article(s)?`);
        }

        function deleteArticle(id, title) {
            document.getElementById('deleteArticleId').value = id;
            document.getElementById('deleteArticleTitle').textContent = title;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
    </script>

    <?php
    // Handle individual article deletion
    if ($_POST && isset($_POST['delete_article']) && isset($_POST['delete_id'])) {
        $delete_id = (int)$_POST['delete_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM kb_articles WHERE id = ?");
            $stmt->execute([$delete_id]);
            echo "<script>
                window.location.href = '/operations/kb-articles.php?deleted=1';
            </script>";
        } catch (Exception $e) {
            echo "<script>
                alert('Error deleting article: " . addslashes($e->getMessage()) . "');
            </script>";
        }
    }
    ?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>