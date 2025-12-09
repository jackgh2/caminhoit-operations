<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Access control (Staff and Admin only)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'support_consultant', 'accountant'])) {
    header('Location: /login.php');
    exit;
}

// First, let's check what columns exist in the feedback table
$existing_columns = [];
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM kb_article_feedback");
    while ($row = $stmt->fetch()) {
        $existing_columns[] = $row['Field'];
    }
} catch (Exception $e) {
    $error = "Error checking table structure: " . $e->getMessage();
    $existing_columns = ['id', 'article_id', 'user_id', 'is_helpful', 'created_at']; // Basic assumption
}

// Check for specific columns
$has_comment = in_array('comment', $existing_columns);
$has_user_ip = in_array('user_ip', $existing_columns);
$has_staff_response = in_array('staff_response', $existing_columns);
$has_staff_responded_by = in_array('staff_responded_by', $existing_columns);
$has_staff_responded_at = in_array('staff_responded_at', $existing_columns);

// Auto-update table structure if needed
$table_updated = false;
try {
    $updates_needed = [];
    
    if (!$has_comment) {
        $updates_needed[] = "ADD COLUMN comment TEXT NULL";
    }
    if (!$has_user_ip) {
        $updates_needed[] = "ADD COLUMN user_ip VARCHAR(45) NULL";
    }
    if (!$has_staff_response) {
        $updates_needed[] = "ADD COLUMN staff_response TEXT NULL";
    }
    if (!$has_staff_responded_by) {
        $updates_needed[] = "ADD COLUMN staff_responded_by INT NULL";
    }
    if (!$has_staff_responded_at) {
        $updates_needed[] = "ADD COLUMN staff_responded_at TIMESTAMP NULL";
    }
    
    if (!empty($updates_needed)) {
        $sql = "ALTER TABLE kb_article_feedback " . implode(', ', $updates_needed);
        $pdo->exec($sql);
        
        // Add foreign key if staff_responded_by was added
        if (!$has_staff_responded_by) {
            try {
                $pdo->exec("ALTER TABLE kb_article_feedback ADD FOREIGN KEY (staff_responded_by) REFERENCES users(id) ON DELETE SET NULL");
            } catch (Exception $e) {
                // Foreign key might fail if users table doesn't exist or has different structure
            }
        }
        
        $table_updated = true;
        // Update our flags
        $has_comment = true;
        $has_user_ip = true;
        $has_staff_response = true;
        $has_staff_responded_by = true;
        $has_staff_responded_at = true;
    }
    
} catch (Exception $e) {
    // If we can't update the table, we'll work with what we have
    $table_update_error = $e->getMessage();
}

// Handle actions
if ($_POST && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    try {
        if ($action === 'mark_helpful' && $has_staff_response) {
            $feedback_id = (int)$_POST['feedback_id'];
            $stmt = $pdo->prepare("UPDATE kb_article_feedback SET staff_response = 'helpful'" . 
                ($has_staff_responded_by ? ", staff_responded_by = ?" : "") . 
                ($has_staff_responded_at ? ", staff_responded_at = NOW()" : "") . 
                " WHERE id = ?");
            $params = $has_staff_responded_by ? [$_SESSION['user']['id'], $feedback_id] : [$feedback_id];
            $stmt->execute($params);
            $success = "Feedback marked as helpful!";
        }
        
        elseif ($action === 'mark_reviewed' && $has_staff_response) {
            $feedback_id = (int)$_POST['feedback_id'];
            $stmt = $pdo->prepare("UPDATE kb_article_feedback SET staff_response = 'reviewed'" . 
                ($has_staff_responded_by ? ", staff_responded_by = ?" : "") . 
                ($has_staff_responded_at ? ", staff_responded_at = NOW()" : "") . 
                " WHERE id = ?");
            $params = $has_staff_responded_by ? [$_SESSION['user']['id'], $feedback_id] : [$feedback_id];
            $stmt->execute($params);
            $success = "Feedback marked as reviewed!";
        }
        
        elseif ($action === 'add_response' && $has_staff_response) {
            $feedback_id = (int)$_POST['feedback_id'];
            $response = trim($_POST['response'] ?? '');
            if (!empty($response)) {
                $stmt = $pdo->prepare("UPDATE kb_article_feedback SET staff_response = ?" . 
                    ($has_staff_responded_by ? ", staff_responded_by = ?" : "") . 
                    ($has_staff_responded_at ? ", staff_responded_at = NOW()" : "") . 
                    " WHERE id = ?");
                $params = $has_staff_responded_by ? [$response, $_SESSION['user']['id'], $feedback_id] : [$response, $feedback_id];
                $stmt->execute($params);
                $success = "Response added successfully!";
            }
        }
        
        elseif ($action === 'delete_feedback') {
            $feedback_id = (int)$_POST['feedback_id'];
            $stmt = $pdo->prepare("DELETE FROM kb_article_feedback WHERE id = ?");
            $stmt->execute([$feedback_id]);
            $success = "Feedback deleted successfully!";
        }
        
        elseif ($action === 'bulk_mark_reviewed' && $has_staff_response) {
            $feedback_ids = $_POST['feedback_ids'] ?? [];
            if (!empty($feedback_ids)) {
                $placeholders = str_repeat('?,', count($feedback_ids) - 1) . '?';
                $stmt = $pdo->prepare("UPDATE kb_article_feedback SET staff_response = 'reviewed'" . 
                    ($has_staff_responded_by ? ", staff_responded_by = ?" : "") . 
                    ($has_staff_responded_at ? ", staff_responded_at = NOW()" : "") . 
                    " WHERE id IN ($placeholders)");
                $params = $has_staff_responded_by ? array_merge([$_SESSION['user']['id']], $feedback_ids) : $feedback_ids;
                $stmt->execute($params);
                $success = count($feedback_ids) . " feedback items marked as reviewed!";
            }
        }
        
    } catch (Exception $e) {
        $error = "Error processing action: " . $e->getMessage();
    }
}

// Pagination and filtering
$page = (int)($_GET['page'] ?? 1);
$per_page = 20;
$offset = ($page - 1) * $per_page;

$filter_type = $_GET['type'] ?? 'all';
$filter_article = $_GET['article'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build WHERE clause
$where_conditions = [];
$where_params = [];

if ($filter_type === 'helpful') {
    $where_conditions[] = "f.is_helpful = 1";
} elseif ($filter_type === 'not_helpful') {
    $where_conditions[] = "f.is_helpful = 0";
} elseif ($filter_type === 'with_comments' && $has_comment) {
    $where_conditions[] = "f.comment IS NOT NULL AND f.comment != ''";
} elseif ($filter_type === 'unreviewed' && $has_staff_response) {
    $where_conditions[] = "f.staff_response IS NULL";
}

if (!empty($filter_article)) {
    $where_conditions[] = "a.id = ?";
    $where_params[] = $filter_article;
}

if (!empty($search) && $has_comment) {
    $where_conditions[] = "(f.comment LIKE ? OR a.title LIKE ?)";
    $search_param = "%$search%";
    $where_params = array_merge($where_params, [$search_param, $search_param]);
} elseif (!empty($search)) {
    $where_conditions[] = "a.title LIKE ?";
    $where_params[] = "%$search%";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get feedback with pagination
try {
    // Count total
    $count_sql = "
        SELECT COUNT(*) 
        FROM kb_article_feedback f
        LEFT JOIN kb_articles a ON f.article_id = a.id
        $where_clause
    ";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($where_params);
    $total_count = $stmt->fetchColumn();
    
    // Build select columns based on what exists
    $select_columns = [
        'f.id',
        'f.article_id', 
        'f.user_id',
        'f.is_helpful',
        'f.created_at'
    ];
    
    if ($has_comment) $select_columns[] = 'f.comment';
    if ($has_user_ip) $select_columns[] = 'f.user_ip';
    if ($has_staff_response) $select_columns[] = 'f.staff_response';
    if ($has_staff_responded_at) $select_columns[] = 'f.staff_responded_at';
    
    $select_columns[] = 'a.title as article_title';
    $select_columns[] = 'a.slug as article_slug';
    $select_columns[] = 'u.username as user_name';
    
    if ($has_staff_responded_by) {
        $select_columns[] = 'sr.username as staff_responder_name';
        $staff_join = "LEFT JOIN users sr ON f.staff_responded_by = sr.id";
    } else {
        $select_columns[] = 'NULL as staff_responder_name';
        $staff_join = '';
    }
    
    // Get feedback
    $feedback_sql = "
        SELECT " . implode(', ', $select_columns) . "
        FROM kb_article_feedback f
        LEFT JOIN kb_articles a ON f.article_id = a.id
        LEFT JOIN users u ON f.user_id = u.id
        $staff_join
        $where_clause
        ORDER BY f.created_at DESC
        LIMIT $per_page OFFSET $offset
    ";
    $stmt = $pdo->prepare($feedback_sql);
    $stmt->execute($where_params);
    $feedback_items = $stmt->fetchAll();
    
    // Get statistics
    $stats_columns = [
        'COUNT(*) as total_feedback',
        'SUM(CASE WHEN is_helpful = 1 THEN 1 ELSE 0 END) as helpful_count',
        'SUM(CASE WHEN is_helpful = 0 THEN 1 ELSE 0 END) as not_helpful_count',
        'AVG(CASE WHEN is_helpful = 1 THEN 100 ELSE 0 END) as helpfulness_rate'
    ];
    
    if ($has_comment) {
        $stats_columns[] = "SUM(CASE WHEN comment IS NOT NULL AND comment != '' THEN 1 ELSE 0 END) as with_comments";
    } else {
        $stats_columns[] = "0 as with_comments";
    }
    
    if ($has_staff_response) {
        $stats_columns[] = "SUM(CASE WHEN staff_response IS NULL THEN 1 ELSE 0 END) as unreviewed";
    } else {
        $stats_columns[] = "0 as unreviewed";
    }
    
    $stats_sql = "SELECT " . implode(', ', $stats_columns) . " FROM kb_article_feedback";
    $stats = $pdo->query($stats_sql)->fetch();
    
    // Get articles for filter
    $articles_sql = "
        SELECT a.id, a.title, COUNT(f.id) as feedback_count
        FROM kb_articles a
        LEFT JOIN kb_article_feedback f ON a.id = f.article_id
        WHERE a.status = 'published'
        GROUP BY a.id, a.title
        HAVING feedback_count > 0
        ORDER BY feedback_count DESC, a.title
        LIMIT 20
    ";
    $articles_with_feedback = $pdo->query($articles_sql)->fetchAll();
    
} catch (Exception $e) {
    $error = "Error loading feedback: " . $e->getMessage();
    $feedback_items = [];
    $total_count = 0;
    $stats = ['total_feedback' => 0, 'helpful_count' => 0, 'not_helpful_count' => 0, 'with_comments' => 0, 'unreviewed' => 0, 'helpfulness_rate' => 0];
    $articles_with_feedback = [];
}

$total_pages = ceil($total_count / $per_page);

$page_title = "Article Feedback Management | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<style>
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
        }
        .stat-item {
            text-align: center;
            padding: 1rem;
        }
        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            display: block;
        }
        .feedback-card {
            border-left: 4px solid #dee2e6;
            transition: all 0.3s ease;
            margin-bottom: 1.5rem;
        }
        .feedback-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .feedback-card.helpful {
            border-left-color: #28a745;
        }
        .feedback-card.not-helpful {
            border-left-color: #dc3545;
        }
        .feedback-card.with-comment {
            border-left-color: #17a2b8;
        }
        .feedback-meta {
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        /* Enhanced comment styling */
        .user-comment {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-radius: 15px;
            padding: 1.5rem;
            margin: 1rem 0;
            border-left: 4px solid #2196f3;
            position: relative;
        }
        .user-comment::before {
            content: '"';
            font-size: 3rem;
            color: #2196f3;
            position: absolute;
            top: -10px;
            left: 15px;
            opacity: 0.3;
            font-family: Georgia, serif;
        }
        .user-comment .comment-text {
            font-style: italic;
            font-size: 1.05rem;
            line-height: 1.6;
            color: #1976d2;
            margin-left: 1rem;
        }
        .comment-header {
            display: flex;
            align-items: center;
            margin-bottom: 0.75rem;
            font-size: 0.9rem;
            color: #1976d2;
            font-weight: 500;
        }
        
        .staff-response {
            background: linear-gradient(135deg, #e8f5e8 0%, #c8e6c9 100%);
            border-left-color: #4caf50;
            border-radius: 15px;
            padding: 1.5rem;
            margin: 1rem 0;
        }
        .staff-response .response-header {
            display: flex;
            align-items: center;
            margin-bottom: 0.75rem;
            color: #2e7d32;
            font-weight: 600;
        }
        .staff-response .response-text {
            color: #2e7d32;
            line-height: 1.6;
        }
        
        .rating-badge {
            font-size: 0.75rem;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-weight: 600;
        }
        .bulk-actions {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: none;
        }
        .upgrade-notice {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .basic-mode {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .feedback-summary {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .no-comment-note {
            color: #6c757d;
            font-style: italic;
            text-align: center;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            margin: 1rem 0;
        }
</style>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1">
                            <i class="bi bi-chat-square-text text-primary me-2"></i>
                            Article Feedback Management
                        </h1>
                        <p class="text-muted mb-0">Monitor and respond to user feedback on knowledge base articles</p>
                    </div>
                    <div class="btn-group">
                        <a href="/operations/kb-dashboard.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>
                            Back to Dashboard
                        </a>
                        <a href="/operations/kb-articles.php" class="btn btn-outline-info">
                            <i class="bi bi-file-text me-1"></i>
                            Manage Articles
                        </a>
                    </div>
                </div>

                <?php if ($table_updated): ?>
                    <div class="upgrade-notice">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-check-circle me-2" style="font-size: 1.2rem;"></i>
                            <div>
                                <strong>Database Updated!</strong><br>
                                <small>Enhanced feedback features (comments, staff responses, IP tracking) have been automatically enabled.</small>
                            </div>
                        </div>
                    </div>
                <?php elseif (!$has_comment || !$has_staff_response): ?>
                    <div class="basic-mode">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-info-circle me-2" style="font-size: 1.2rem;"></i>
                            <div>
                                <strong>Basic Mode Active</strong><br>
                                <small>Advanced features (comments, staff responses) will be automatically enabled when you perform actions.</small>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($table_update_error)): ?>
                    <div class="alert alert-warning alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Note:</strong> Some advanced features may be limited due to database permissions. 
                        Core functionality is still available.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

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

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card stats-card border-0 shadow-sm">
                            <div class="card-body">
                                <h6 class="card-title text-center mb-3">Feedback Overview</h6>
                                <div class="row">
                                    <div class="col-3">
                                        <div class="stat-item">
                                            <span class="stat-number"><?= number_format($stats['total_feedback'] ?? 0) ?></span>
                                            <small>Total</small>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="stat-item">
                                            <span class="stat-number"><?= number_format($stats['helpful_count'] ?? 0) ?></span>
                                            <small>Helpful</small>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="stat-item">
                                            <span class="stat-number"><?= number_format($stats['not_helpful_count'] ?? 0) ?></span>
                                            <small>Not Helpful</small>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="stat-item">
                                            <span class="stat-number"><?= number_format($stats['unreviewed'] ?? 0) ?></span>
                                            <small>Unreviewed</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <h6 class="card-title">Helpfulness Rate</h6>
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <div class="progress" style="height: 10px;">
                                            <div class="progress-bar bg-success" role="progressbar" 
                                                 style="width: <?= round($stats['helpfulness_rate'] ?? 0, 1) ?>%"></div>
                                        </div>
                                    </div>
                                    <span class="ms-2 fw-bold"><?= round($stats['helpfulness_rate'] ?? 0, 1) ?>%</span>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-6">
                                        <small class="text-muted">With Comments</small>
                                        <div class="fw-bold"><?= number_format($stats['with_comments'] ?? 0) ?></div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Available Columns</small>
                                        <div class="fw-bold text-info">
                                            <?= count(array_filter([$has_comment, $has_user_ip, $has_staff_response])) ?>/3
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Filter by Type</label>
                                <select name="type" class="form-select">
                                    <option value="all" <?= $filter_type === 'all' ? 'selected' : '' ?>>All Feedback</option>
                                    <option value="helpful" <?= $filter_type === 'helpful' ? 'selected' : '' ?>>Helpful</option>
                                    <option value="not_helpful" <?= $filter_type === 'not_helpful' ? 'selected' : '' ?>>Not Helpful</option>
                                    <?php if ($has_comment): ?>
                                        <option value="with_comments" <?= $filter_type === 'with_comments' ? 'selected' : '' ?>>With Comments</option>
                                    <?php endif; ?>
                                    <?php if ($has_staff_response): ?>
                                        <option value="unreviewed" <?= $filter_type === 'unreviewed' ? 'selected' : '' ?>>Unreviewed</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Filter by Article</label>
                                <select name="article" class="form-select">
                                    <option value="">All Articles</option>
                                    <?php foreach ($articles_with_feedback as $article): ?>
                                        <option value="<?= $article['id'] ?>" <?= $filter_article == $article['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($article['title']) ?> (<?= $article['feedback_count'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" 
                                       value="<?= htmlspecialchars($search) ?>" 
                                       placeholder="Search <?= $has_comment ? 'comments or ' : '' ?>articles...">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search me-1"></i>
                                        Filter
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Bulk Actions -->
                <?php if ($has_staff_response): ?>
                    <div class="bulk-actions" id="bulkActions">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>
                                <strong id="selectedCount">0</strong> feedback items selected
                            </span>
                            <div class="btn-group">
                                <form method="POST" style="display: inline;" id="bulkForm">
                                    <input type="hidden" name="action" value="bulk_mark_reviewed">
                                    <input type="hidden" name="feedback_ids" id="bulkFeedbackIds" value="">
                                    <button type="submit" class="btn btn-success btn-sm">
                                        <i class="bi bi-check-circle me-1"></i>
                                        Mark as Reviewed
                                    </button>
                                </form>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelection()">
                                    Clear Selection
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Feedback List -->
                <div class="row">
                    <div class="col-12">
                        <?php if (empty($feedback_items)): ?>
                            <div class="card border-0 shadow-sm">
                                <div class="card-body text-center py-5">
                                    <i class="bi bi-chat-square-text display-1 text-muted mb-3"></i>
                                    <h5 class="text-muted">No feedback found</h5>
                                    <p class="text-muted">
                                        <?php if (!empty($search) || $filter_type !== 'all'): ?>
                                            Try adjusting your filters or search terms.
                                        <?php else: ?>
                                            Users haven't provided feedback on articles yet.
                                        <?php endif; ?>
                                    </p>
                                    <?php if (!empty($search) || $filter_type !== 'all'): ?>
                                        <a href="/operations/kb-feedback.php" class="btn btn-outline-primary">
                                            <i class="bi bi-arrow-clockwise me-1"></i>
                                            Clear Filters
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($feedback_items as $feedback): ?>
                                <div class="card feedback-card border-0 shadow-sm 
                                            <?= $feedback['is_helpful'] ? 'helpful' : 'not-helpful' ?>
                                            <?= ($has_comment && !empty($feedback['comment'])) ? 'with-comment' : '' ?>">
                                    <div class="card-body">
                                        <!-- Feedback Header -->
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div class="d-flex align-items-center">
                                                <?php if ($has_staff_response): ?>
                                                    <div class="form-check me-3">
                                                        <input class="form-check-input feedback-checkbox" type="checkbox" 
                                                               value="<?= $feedback['id'] ?>" onchange="updateBulkActions()">
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <h6 class="mb-1">
                                                        <a href="/kb/<?= htmlspecialchars($feedback['article_slug']) ?>" 
                                                           target="_blank" class="text-decoration-none">
                                                            <?= htmlspecialchars($feedback['article_title']) ?>
                                                            <i class="bi bi-box-arrow-up-right ms-1 small"></i>
                                                        </a>
                                                    </h6>
                                                    <div class="feedback-meta">
                                                        <?php if ($feedback['user_name']): ?>
                                                            By <strong><?= htmlspecialchars($feedback['user_name']) ?></strong> • 
                                                        <?php else: ?>
                                                            By <strong>Anonymous</strong> • 
                                                        <?php endif; ?>
                                                        <?= date('M j, Y \a\t g:i A', strtotime($feedback['created_at'])) ?>
                                                        <?php if ($has_user_ip && !empty($feedback['user_ip'])): ?>
                                                            • IP: <?= htmlspecialchars($feedback['user_ip']) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center">
                                                <?php if ($feedback['is_helpful']): ?>
                                                    <span class="badge bg-success rating-badge me-2">
                                                        <i class="bi bi-hand-thumbs-up me-1"></i>
                                                        Helpful
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger rating-badge me-2">
                                                        <i class="bi bi-hand-thumbs-down me-1"></i>
                                                        Not Helpful
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <?php if ($has_staff_response && !empty($feedback['staff_response'])): ?>
                                                    <span class="badge bg-info rating-badge me-2">
                                                        <i class="bi bi-check-circle me-1"></i>
                                                        <?= ucfirst($feedback['staff_response']) ?>
                                                    </span>
                                                <?php endif; ?>

                                                <div class="dropdown">
                                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" 
                                                            type="button" data-bs-toggle="dropdown">
                                                        <i class="bi bi-three-dots"></i>
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <?php if ($has_staff_response): ?>
                                                            <li>
                                                                <button class="dropdown-item" onclick="showResponseModal(<?= $feedback['id'] ?>, '<?= htmlspecialchars($feedback['article_title'], ENT_QUOTES) ?>')">
                                                                    <i class="bi bi-reply me-2"></i>
                                                                    Add Response
                                                                </button>
                                                            </li>
                                                            <li>
                                                                <form method="POST" style="display: inline;">
                                                                    <input type="hidden" name="action" value="mark_reviewed">
                                                                    <input type="hidden" name="feedback_id" value="<?= $feedback['id'] ?>">
                                                                    <button type="submit" class="dropdown-item">
                                                                        <i class="bi bi-check-circle me-2"></i>
                                                                        Mark as Reviewed
                                                                    </button>
                                                                </form>
                                                            </li>
                                                            <li><hr class="dropdown-divider"></li>
                                                        <?php endif; ?>
                                                        <li>
                                                            <form method="POST" style="display: inline;" 
                                                                  onsubmit="return confirm('Are you sure you want to delete this feedback?')">
                                                                <input type="hidden" name="action" value="delete_feedback">
                                                                <input type="hidden" name="feedback_id" value="<?= $feedback['id'] ?>">
                                                                <button type="submit" class="dropdown-item text-danger">
                                                                    <i class="bi bi-trash me-2"></i>
                                                                    Delete Feedback
                                                                </button>
                                                            </form>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- User Comment Section (Enhanced!) -->
                                        <?php if ($has_comment && !empty($feedback['comment'])): ?>
                                            <div class="user-comment">
                                                <div class="comment-header">
                                                    <i class="bi bi-chat-quote-fill me-2"></i>
                                                    <strong>User Comment:</strong>
                                                </div>
                                                <div class="comment-text">
                                                    <?= nl2br(htmlspecialchars($feedback['comment'])) ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="no-comment-note">
                                                <i class="bi bi-chat-square-dots me-2"></i>
                                                No additional comment provided
                                            </div>
                                        <?php endif; ?>

                                        <!-- Staff Response Section -->
                                        <?php if ($has_staff_response && !empty($feedback['staff_response']) && !in_array($feedback['staff_response'], ['reviewed', 'helpful'])): ?>
                                            <div class="staff-response">
                                                <div class="response-header">
                                                    <i class="bi bi-person-badge-fill me-2"></i>
                                                    <strong>Staff Response:</strong>
                                                </div>
                                                <div class="response-text">
                                                    <?= nl2br(htmlspecialchars($feedback['staff_response'])) ?>
                                                </div>
                                                <?php if ($has_staff_responded_at && !empty($feedback['staff_responded_at'])): ?>
                                                    <div class="feedback-meta mt-2">
                                                        <?php if (!empty($feedback['staff_responder_name'])): ?>
                                                            Responded by <strong><?= htmlspecialchars($feedback['staff_responder_name']) ?></strong> 
                                                        <?php else: ?>
                                                            Staff response 
                                                        <?php endif; ?>
                                                        on <?= date('M j, Y \a\t g:i A', strtotime($feedback['staff_responded_at'])) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                                <nav aria-label="Feedback pagination">
                                    <ul class="pagination justify-content-center">
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                                    <i class="bi bi-chevron-left"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>

                                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                                    <?= $i ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <?php if ($page < $total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                                    <i class="bi bi-chevron-right"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>

                                <div class="text-center text-muted">
                                    Showing <?= number_format($offset + 1) ?> to <?= number_format(min($offset + $per_page, $total_count)) ?> 
                                    of <?= number_format($total_count) ?> feedback items
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Response Modal -->
    <?php if ($has_staff_response): ?>
        <div class="modal fade" id="responseModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Staff Response</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="action" value="add_response">
                            <input type="hidden" name="feedback_id" id="modalFeedbackId">
                            
                            <div class="mb-3">
                                <label class="form-label">Article</label>
                                <input type="text" class="form-control" id="modalArticleTitle" readonly>
                            </div>
                            
                            <div class="mb-3">
                                <label for="response" class="form-label">Your Response</label>
                                <textarea class="form-control" id="response" name="response" rows="4" 
                                          placeholder="Enter your response to this feedback..." required></textarea>
                                <div class="form-text">This response will be visible to other staff members reviewing this feedback.</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-reply me-1"></i>
                                Add Response
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        <?php if ($has_staff_response): ?>
        function showResponseModal(feedbackId, articleTitle) {
            document.getElementById('modalFeedbackId').value = feedbackId;
            document.getElementById('modalArticleTitle').value = articleTitle;
            document.getElementById('response').value = '';
            new bootstrap.Modal(document.getElementById('responseModal')).show();
        }

        function updateBulkActions() {
            const checkboxes = document.querySelectorAll('.feedback-checkbox:checked');
            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');
            const bulkFeedbackIds = document.getElementById('bulkFeedbackIds');
            
            if (checkboxes.length > 0) {
                bulkActions.style.display = 'block';
                selectedCount.textContent = checkboxes.length;
                
                const ids = Array.from(checkboxes).map(cb => cb.value);
                bulkFeedbackIds.value = JSON.stringify(ids);
            } else {
                bulkActions.style.display = 'none';
            }
        }

        function clearSelection() {
            document.querySelectorAll('.feedback-checkbox').forEach(cb => cb.checked = false);
            updateBulkActions();
        }

        // Handle bulk form submission
        document.getElementById('bulkForm').addEventListener('submit', function(e) {
            const ids = JSON.parse(document.getElementById('bulkFeedbackIds').value);
            
            // Replace the hidden input with individual inputs for each ID
            const hiddenInput = document.querySelector('input[name="feedback_ids"]');
            hiddenInput.remove();
            
            ids.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'feedback_ids[]';
                input.value = id;
                this.appendChild(input);
            });
        });
        <?php endif; ?>
    </script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>