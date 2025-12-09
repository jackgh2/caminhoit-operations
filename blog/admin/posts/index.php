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

// Handle bulk actions
$success_message = '';
if ($_POST['bulk_action'] ?? false) {
    $action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_posts'] ?? [];
    
    if (!empty($selected_ids) && in_array($action, ['publish', 'draft', 'trash', 'delete'])) {
        $placeholders = str_repeat('?,', count($selected_ids) - 1) . '?';
        
        try {
            switch ($action) {
                case 'publish':
                    $stmt = $pdo->prepare("UPDATE blog_posts SET status = 'published', published_at = COALESCE(published_at, NOW()) WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $success_message = count($selected_ids) . " posts published successfully.";
                    break;
                case 'draft':
                    $stmt = $pdo->prepare("UPDATE blog_posts SET status = 'draft' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $success_message = count($selected_ids) . " posts moved to draft.";
                    break;
                case 'trash':
                    $stmt = $pdo->prepare("UPDATE blog_posts SET status = 'archived' WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $success_message = count($selected_ids) . " posts moved to trash.";
                    break;
                case 'delete':
                    $stmt = $pdo->prepare("DELETE FROM blog_posts WHERE id IN ($placeholders)");
                    $stmt->execute($selected_ids);
                    $success_message = count($selected_ids) . " posts deleted permanently.";
                    break;
            }
        } catch (Exception $e) {
            $error_message = "Error performing bulk action: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$author_filter = $_GET['author'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];

if ($status_filter) {
    $where_conditions[] = "p.status = ?";
    $params[] = $status_filter;
}

if ($category_filter) {
    $where_conditions[] = "p.category_id = ?";
    $params[] = $category_filter;
}

if ($author_filter) {
    $where_conditions[] = "p.author_id = ?";
    $params[] = $author_filter;
}

if ($search) {
    $where_conditions[] = "(p.title LIKE ? OR p.content LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count
$count_sql = "
    SELECT COUNT(*) 
    FROM blog_posts p 
    LEFT JOIN users u ON p.author_id = u.id 
    LEFT JOIN blog_categories c ON p.category_id = c.id 
    WHERE $where_clause
";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_posts = $stmt->fetchColumn();
$total_pages = ceil($total_posts / $per_page);

// Get posts
$posts_sql = "
    SELECT p.*, u.username as author_name, c.name as category_name,
           (SELECT COUNT(*) FROM blog_post_attachments WHERE post_id = p.id) as attachment_count
    FROM blog_posts p
    LEFT JOIN users u ON p.author_id = u.id
    LEFT JOIN blog_categories c ON p.category_id = c.id
    WHERE $where_clause
    ORDER BY 
        CASE 
            WHEN p.status = 'scheduled' THEN p.scheduled_at 
            ELSE p.created_at 
        END DESC
    LIMIT $per_page OFFSET $offset
";

$stmt = $pdo->prepare($posts_sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

// Get filter options
$categories_stmt = $pdo->prepare("SELECT id, name FROM blog_categories WHERE is_active = 1 ORDER BY name");
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll();

$authors_stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.username 
    FROM users u 
    INNER JOIN blog_posts p ON u.id = p.author_id 
    ORDER BY u.username
");
$authors_stmt->execute();
$authors = $authors_stmt->fetchAll();

$page_title = "Blog Posts | Admin";
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
            max-width: 1600px;
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

        .filters-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .bulk-actions {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .posts-table {
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
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background: #f8fafc;
        }

        .post-title {
            font-weight: 600;
            color: #1f2937;
            text-decoration: none;
            margin-bottom: 0.25rem;
        }

        .post-title:hover {
            color: #4F46E5;
        }

        .post-excerpt {
            font-size: 0.875rem;
            color: #6b7280;
            line-height: 1.4;
            max-height: 2.8em;
            overflow: hidden;
        }

        .badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
        }

        .badge.bg-published { background: #10B981 !important; }
        .badge.bg-draft { background: #F59E0B !important; }
        .badge.bg-scheduled { background: #06B6D4 !important; }
        .badge.bg-archived { background: #6B7280 !important; }

        .pagination-wrapper {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .quick-stats {
            display: flex;
            gap: 2rem;
            margin-bottom: 1rem;
        }

        .quick-stat {
            text-align: center;
        }

        .quick-stat .number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
        }

        .quick-stat .label {
            font-size: 0.875rem;
            color: #6b7280;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="main-container">
    <!-- Success/Error Messages -->
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= $success_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?= $error_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-file-text me-3"></i>Blog Posts</h1>
                <div class="quick-stats">
                    <div class="quick-stat">
                        <div class="number"><?= number_format($total_posts) ?></div>
                        <div class="label">Total Posts</div>
                    </div>
                    <?php if ($status_filter): ?>
                        <div class="quick-stat">
                            <div class="number"><?= number_format($total_posts) ?></div>
                            <div class="label"><?= ucfirst($status_filter) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <a href="/blog/admin/dashboard.php" class="btn btn-outline-secondary me-2">
                    <i class="bi bi-arrow-left me-2"></i>Dashboard
                </a>
                <a href="/blog/admin/posts/create.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-2"></i>New Post
                </a>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <h5 class="mb-3"><i class="bi bi-funnel me-2"></i>Filters & Search</h5>
        <form method="GET" class="filter-form">
            <div class="form-group">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search posts...">
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All Statuses</option>
                    <option value="published" <?= $status_filter === 'published' ? 'selected' : '' ?>>Published</option>
                    <option value="draft" <?= $status_filter === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="scheduled" <?= $status_filter === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                    <option value="archived" <?= $status_filter === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Category</label>
                <select class="form-select" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $category_filter == $category['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Author</label>
                <select class="form-select" name="author">
                    <option value="">All Authors</option>
                    <?php foreach ($authors as $author): ?>
                        <option value="<?= $author['id'] ?>" <?= $author_filter == $author['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($author['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-2"></i>Filter
                </button>
            </div>
            <div class="form-group">
                <a href="?" class="btn btn-outline-secondary">
                    <i class="bi bi-x-circle me-2"></i>Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Bulk Actions -->
    <form method="POST" id="postsForm">
        <div class="bulk-actions">
            <h5 class="mb-3"><i class="bi bi-check2-square me-2"></i>Bulk Actions</h5>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <select name="bulk_action" class="form-select" style="width: auto;">
                    <option value="">Choose action...</option>
                    <option value="publish">Publish Selected</option>
                    <option value="draft">Move to Draft</option>
                    <option value="trash">Move to Trash</option>
                    <option value="delete">Delete Permanently</option>
                </select>
                <button type="submit" class="btn btn-secondary" onclick="return confirmBulkAction()">
                    <i class="bi bi-play-fill me-1"></i>Apply
                </button>
                <div class="ms-auto">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectAll()">Select All</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectNone()">Select None</button>
                </div>
            </div>
        </div>

        <!-- Posts Table -->
        <div class="posts-table">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th width="50">
                                <input type="checkbox" id="selectAllCheckbox" onchange="toggleAll()">
                            </th>
                            <th>Title</th>
                            <th>Author</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Views</th>
                            <th>Attachments</th>
                            <th width="120">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($posts)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <i class="bi bi-file-text text-muted" style="font-size: 3rem;"></i>
                                    <p class="text-muted mt-3 mb-2">No posts found</p>
                                    <?php if ($search || $status_filter || $category_filter || $author_filter): ?>
                                        <p class="text-muted mb-0">Try adjusting your filters or <a href="?">view all posts</a></p>
                                    <?php else: ?>
                                        <a href="/blog/admin/posts/create.php" class="btn btn-primary">Create your first post</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($posts as $post): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected_posts[]" value="<?= $post['id'] ?>" class="post-checkbox">
                                    </td>
                                    <td>
                                        <div>
                                            <a href="/blog/admin/posts/edit.php?id=<?= $post['id'] ?>" class="post-title d-block">
                                                <?= htmlspecialchars($post['title']) ?>
                                            </a>
                                            <?php if ($post['excerpt']): ?>
                                                <div class="post-excerpt"><?= htmlspecialchars(substr($post['excerpt'], 0, 100)) ?><?= strlen($post['excerpt']) > 100 ? '...' : '' ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($post['author_name']) ?></td>
                                    <td><?= htmlspecialchars($post['category_name'] ?? 'Uncategorized') ?></td>
                                    <td>
                                        <span class="badge bg-<?= $post['status'] ?>">
                                            <?= ucfirst($post['status']) ?>
                                        </span>
                                        <?php if ($post['is_featured']): ?>
                                            <span class="badge bg-warning ms-1">Featured</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($post['status'] === 'published'): ?>
                                            <small class="text-muted">Published</small><br>
                                            <?= date('M j, Y', strtotime($post['published_at'])) ?>
                                        <?php elseif ($post['status'] === 'scheduled'): ?>
                                            <small class="text-info">Scheduled</small><br>
                                            <?= date('M j, Y g:i A', strtotime($post['scheduled_at'])) ?>
                                        <?php else: ?>
                                            <small class="text-muted">Created</small><br>
                                            <?= date('M j, Y', strtotime($post['created_at'])) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= number_format($post['view_count']) ?></td>
                                    <td>
                                        <?php if ($post['attachment_count'] > 0): ?>
                                            <span class="badge bg-info"><?= $post['attachment_count'] ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="/blog/admin/posts/edit.php?id=<?= $post['id'] ?>" class="btn btn-outline-primary" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <?php if ($post['status'] === 'published'): ?>
                                                <a href="/blog/post/<?= $post['slug'] ?>" class="btn btn-outline-info" target="_blank" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="/blog/admin/posts/preview.php?id=<?= $post['id'] ?>" class="btn btn-outline-info" target="_blank" title="Preview">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-outline-danger" onclick="deletePost(<?= $post['id'] ?>, '<?= htmlspecialchars($post['title'], ENT_QUOTES) ?>')" title="Delete">
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
        </div>
    </form>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination-wrapper">
            <nav aria-label="Posts pagination">
                <ul class="pagination justify-content-center mb-0">
                    <?php
                    $current_params = $_GET;
                    
                    // Previous page
                    if ($page > 1):
                        $current_params['page'] = $page - 1;
                        $prev_url = '?' . http_build_query($current_params);
                    ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= $prev_url ?>">
                                <i class="bi bi-chevron-left"></i> Previous
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php
                    // Page numbers
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++):
                        $current_params['page'] = $i;
                        $page_url = '?' . http_build_query($current_params);
                    ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= $page_url ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php
                    // Next page
                    if ($page < $total_pages):
                        $current_params['page'] = $page + 1;
                        $next_url = '?' . http_build_query($current_params);
                    ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= $next_url ?>">
                                Next <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
            
            <div class="text-center mt-3">
                <small class="text-muted">
                    Showing <?= number_format(($page - 1) * $per_page + 1) ?> to 
                    <?= number_format(min($page * $per_page, $total_posts)) ?> of 
                    <?= number_format($total_posts) ?> posts
                </small>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleAll() {
    const selectAll = document.getElementById('selectAllCheckbox');
    const checkboxes = document.querySelectorAll('.post-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
    });
}

function selectAll() {
    const checkboxes = document.querySelectorAll('.post-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    document.getElementById('selectAllCheckbox').checked = true;
}

function selectNone() {
    const checkboxes = document.querySelectorAll('.post-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    document.getElementById('selectAllCheckbox').checked = false;
}

function confirmBulkAction() {
    const action = document.querySelector('[name="bulk_action"]').value;
    const selected = document.querySelectorAll('.post-checkbox:checked');
    
    if (!action) {
        alert('Please select an action.');
        return false;
    }
    
    if (selected.length === 0) {
        alert('Please select at least one post.');
        return false;
    }
    
    const actionText = action === 'delete' ? 'permanently delete' : action;
    return confirm(`Are you sure you want to ${actionText} ${selected.length} selected post${selected.length > 1 ? 's' : ''}?`);
}

function deletePost(id, title) {
    if (confirm(`Are you sure you want to delete "${title}"? This action cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/blog/admin/posts/delete.php';
        
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