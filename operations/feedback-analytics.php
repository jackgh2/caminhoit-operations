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

// Check if user is staff/admin
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_role = $stmt->fetchColumn();

if (!in_array($user_role, ['administrator', 'support_user', 'support_technician', 'accountant'])) {
    header('Location: /dashboard.php');
    exit;
}

// Get filters from URL parameters
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$filter_agent = $_GET['filter_agent'] ?? '';
$min_rating = $_GET['min_rating'] ?? '';

// Build dynamic WHERE clause for filters
$where_conditions = ["DATE(f.created_at) BETWEEN ? AND ?"];
$params = [$start_date, $end_date];

if ($filter_agent) {
    $where_conditions[] = "t.assigned_to = ?";
    $params[] = $filter_agent;
}

if ($min_rating) {
    $where_conditions[] = "f.rating >= ?";
    $params[] = $min_rating;
}

$where_clause = implode(' AND ', $where_conditions);

// Get filter options for dropdowns
$stmt = $pdo->prepare("SELECT id, username FROM users WHERE role IN ('administrator', 'support_user', 'support_technician', 'accountant') ORDER BY username");
$stmt->execute();
$all_agents = $stmt->fetchAll();

// Overall feedback statistics
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_feedback,
        AVG(f.rating) as avg_rating,
        AVG(f.response_time_rating) as avg_response_time,
        AVG(f.resolution_quality_rating) as avg_resolution_quality,
        AVG(f.staff_professionalism_rating) as avg_staff_professionalism,
        SUM(CASE WHEN f.helpful = 'yes' THEN 1 ELSE 0 END) as resolved_yes,
        SUM(CASE WHEN f.helpful = 'neutral' THEN 1 ELSE 0 END) as resolved_partial,
        SUM(CASE WHEN f.helpful = 'no' THEN 1 ELSE 0 END) as resolved_no,
        SUM(CASE WHEN f.would_recommend = 1 THEN 1 ELSE 0 END) as would_recommend_yes,
        SUM(CASE WHEN f.would_recommend = 0 THEN 1 ELSE 0 END) as would_recommend_no
    FROM support_ticket_feedback f
    JOIN support_tickets t ON f.ticket_id = t.id
    WHERE $where_clause
");
$stmt->execute($params);
$overall_stats = $stmt->fetch();

// Rating distribution
$stmt = $pdo->prepare("
    SELECT
        f.rating,
        COUNT(*) as count
    FROM support_ticket_feedback f
    JOIN support_tickets t ON f.ticket_id = t.id
    WHERE $where_clause
    GROUP BY f.rating
    ORDER BY f.rating DESC
");
$stmt->execute($params);
$rating_distribution = $stmt->fetchAll();

// Performance by agent
$stmt = $pdo->prepare("
    SELECT
        u.username,
        u.id,
        COUNT(f.id) as feedback_count,
        AVG(f.rating) as avg_rating,
        AVG(f.response_time_rating) as avg_response_time,
        AVG(f.resolution_quality_rating) as avg_resolution_quality,
        AVG(f.staff_professionalism_rating) as avg_staff_professionalism,
        SUM(CASE WHEN f.would_recommend = 1 THEN 1 ELSE 0 END) as recommendations
    FROM support_ticket_feedback f
    JOIN support_tickets t ON f.ticket_id = t.id
    JOIN users u ON t.assigned_to = u.id
    WHERE $where_clause AND t.assigned_to IS NOT NULL
    GROUP BY u.id, u.username
    ORDER BY avg_rating DESC
");
$stmt->execute($params);
$agent_performance = $stmt->fetchAll();

// Recent feedback with comments
$stmt = $pdo->prepare("
    SELECT
        f.*,
        t.id as ticket_id,
        t.subject,
        u.username as customer_name,
        a.username as agent_name
    FROM support_ticket_feedback f
    JOIN support_tickets t ON f.ticket_id = t.id
    LEFT JOIN users u ON f.user_id = u.id
    LEFT JOIN users a ON t.assigned_to = a.id
    WHERE $where_clause
    ORDER BY f.created_at DESC
    LIMIT 20
");
$stmt->execute($params);
$recent_feedback = $stmt->fetchAll();

// Trend data (last 30 days)
$stmt = $pdo->prepare("
    SELECT
        DATE(f.created_at) as date,
        COUNT(*) as count,
        AVG(f.rating) as avg_rating
    FROM support_ticket_feedback f
    JOIN support_tickets t ON f.ticket_id = t.id
    WHERE DATE(f.created_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE()
    GROUP BY DATE(f.created_at)
    ORDER BY date
");
$stmt->execute();
$trend_data = $stmt->fetchAll();

$page_title = "Feedback Analytics | CaminhoIT";
?>
<?php include $_SERVER'['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>


<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
        --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        --border-radius: 12px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    body {
        background: #F8FAFC;
    }

    .container {
        max-width: 1400px;
    }

    .card, .box, .panel {
        background: white;
        border-radius: var(--border-radius);
        padding: 1.5rem;
        box-shadow: var(--card-shadow);
        margin-bottom: 1.5rem;
    }

    .btn-primary {
        background: var(--primary-gradient);
        border: none;
        color: white;
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        transition: var(--transition);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
    }

    table.table {
        background: white;
        border-radius: var(--border-radius);
        overflow: hidden;
    }

    .table thead {
        background: #F8FAFC;
    }

    .badge {
        padding: 0.375rem 0.75rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .modal {
        z-index: 1050;
    }

    .modal-content {
        border-radius: var(--border-radius);
    }
</style>


<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0"><i class="bi bi-star-fill text-warning me-2"></i>Feedback Analytics</h1>
            <p class="text-muted">Customer satisfaction and feedback insights</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Start Date</label>
                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">End Date</label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Agent</label>
                <select name="filter_agent" class="form-select">
                    <option value="">All Agents</option>
                    <?php foreach ($all_agents as $agent): ?>
                        <option value="<?= $agent['id'] ?>" <?= $filter_agent == $agent['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($agent['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Min Rating</label>
                <select name="min_rating" class="form-select">
                    <option value="">All Ratings</option>
                    <option value="5" <?= $min_rating == '5' ? 'selected' : '' ?>>5 Stars</option>
                    <option value="4" <?= $min_rating == '4' ? 'selected' : '' ?>>4+ Stars</option>
                    <option value="3" <?= $min_rating == '3' ? 'selected' : '' ?>>3+ Stars</option>
                </select>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-funnel"></i> Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Key Metrics -->
    <div class="row">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">Total Feedback</div>
                <p class="stat-number text-primary"><?= number_format($overall_stats['total_feedback'] ?? 0) ?></p>
                <small class="text-muted">Responses received</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">Average Rating</div>
                <p class="stat-number text-warning">
                    <?= number_format($overall_stats['avg_rating'] ?? 0, 1) ?>
                    <span class="star-display">★</span>
                </p>
                <small class="text-muted">Out of 5.0</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">Resolution Rate</div>
                <p class="stat-number text-success">
                    <?php
                    $total = ($overall_stats['resolved_yes'] ?? 0) + ($overall_stats['resolved_partial'] ?? 0) + ($overall_stats['resolved_no'] ?? 0);
                    $rate = $total > 0 ? (($overall_stats['resolved_yes'] ?? 0) / $total * 100) : 0;
                    echo number_format($rate, 1);
                    ?>%
                </p>
                <small class="text-muted">Issues fully resolved</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">Would Recommend</div>
                <p class="stat-number text-info">
                    <?php
                    $rec_total = ($overall_stats['would_recommend_yes'] ?? 0) + ($overall_stats['would_recommend_no'] ?? 0);
                    $rec_rate = $rec_total > 0 ? (($overall_stats['would_recommend_yes'] ?? 0) / $rec_total * 100) : 0;
                    echo number_format($rec_rate, 1);
                    ?>%
                </p>
                <small class="text-muted">Customer loyalty</small>
            </div>
        </div>
    </div>

    <!-- Detailed Ratings -->
    <div class="row">
        <div class="col-md-4">
            <div class="stat-card">
                <h6><i class="bi bi-clock-history text-primary me-2"></i>Response Time</h6>
                <div class="d-flex align-items-center">
                    <div class="progress-circle" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                        <?= number_format($overall_stats['avg_response_time'] ?? 0, 1) ?>
                    </div>
                    <div class="ms-3">
                        <div class="star-display">
                            <?php for($i = 1; $i <= 5; $i++): ?>
                                <?= $i <= round($overall_stats['avg_response_time'] ?? 0) ? '★' : '☆' ?>
                            <?php endfor; ?>
                        </div>
                        <small class="text-muted">Out of 5.0</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <h6><i class="bi bi-check-circle text-success me-2"></i>Resolution Quality</h6>
                <div class="d-flex align-items-center">
                    <div class="progress-circle" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white;">
                        <?= number_format($overall_stats['avg_resolution_quality'] ?? 0, 1) ?>
                    </div>
                    <div class="ms-3">
                        <div class="star-display">
                            <?php for($i = 1; $i <= 5; $i++): ?>
                                <?= $i <= round($overall_stats['avg_resolution_quality'] ?? 0) ? '★' : '☆' ?>
                            <?php endfor; ?>
                        </div>
                        <small class="text-muted">Out of 5.0</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <h6><i class="bi bi-person-badge text-info me-2"></i>Staff Professionalism</h6>
                <div class="d-flex align-items-center">
                    <div class="progress-circle" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
                        <?= number_format($overall_stats['avg_staff_professionalism'] ?? 0, 1) ?>
                    </div>
                    <div class="ms-3">
                        <div class="star-display">
                            <?php for($i = 1; $i <= 5; $i++): ?>
                                <?= $i <= round($overall_stats['avg_staff_professionalism'] ?? 0) ? '★' : '☆' ?>
                            <?php endfor; ?>
                        </div>
                        <small class="text-muted">Out of 5.0</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Rating Distribution -->
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="stat-card">
                <h5 class="mb-4"><i class="bi bi-bar-chart me-2"></i>Rating Distribution</h5>
                <?php
                $total_feedback = $overall_stats['total_feedback'] ?? 1;
                for ($rating = 5; $rating >= 1; $rating--):
                    $count = 0;
                    foreach ($rating_distribution as $dist) {
                        if ($dist['rating'] == $rating) {
                            $count = $dist['count'];
                            break;
                        }
                    }
                    $percentage = $total_feedback > 0 ? ($count / $total_feedback * 100) : 0;
                ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span>
                                <?= $rating ?>
                                <span class="star-display">★</span>
                            </span>
                            <span class="text-muted"><?= $count ?> (<?= number_format($percentage, 1) ?>%)</span>
                        </div>
                        <div class="rating-bar">
                            <div class="rating-bar-fill" style="width: <?= $percentage ?>%">
                                <?php if ($percentage > 10): ?>
                                    <?= number_format($percentage, 0) ?>%
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Agent Performance -->
        <div class="col-md-6">
            <div class="stat-card">
                <h5 class="mb-4"><i class="bi bi-people me-2"></i>Agent Performance</h5>
                <?php if (empty($agent_performance)): ?>
                    <p class="text-muted text-center">No feedback data for agents in selected period.</p>
                <?php else: ?>
                    <?php foreach (array_slice($agent_performance, 0, 5) as $agent): ?>
                        <div class="agent-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?= htmlspecialchars($agent['username']) ?></strong>
                                    <div class="star-display">
                                        <?php for($i = 1; $i <= 5; $i++): ?>
                                            <?= $i <= round($agent['avg_rating']) ? '★' : '☆' ?>
                                        <?php endfor; ?>
                                        <span class="text-muted"><?= number_format($agent['avg_rating'], 1) ?></span>
                                    </div>
                                    <small class="text-muted">
                                        <?= $agent['feedback_count'] ?> feedback •
                                        <?= $agent['recommendations'] ?> recommendations
                                    </small>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted d-block">Quality: <?= number_format($agent['avg_resolution_quality'], 1) ?>★</small>
                                    <small class="text-muted d-block">Speed: <?= number_format($agent['avg_response_time'], 1) ?>★</small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- All Tickets with Feedback -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="stat-card">
                <h5 class="mb-4"><i class="bi bi-list-check me-2"></i>All Tickets with Feedback</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Ticket #</th>
                                <th>Subject</th>
                                <th>Customer</th>
                                <th>Agent</th>
                                <th>Overall</th>
                                <th>Response Time</th>
                                <th>Quality</th>
                                <th>Professionalism</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Get all tickets with feedback
                            $stmt = $pdo->prepare("
                                SELECT
                                    f.*,
                                    t.id as ticket_id,
                                    t.subject,
                                    u.username as customer_name,
                                    a.username as agent_name
                                FROM support_ticket_feedback f
                                JOIN support_tickets t ON f.ticket_id = t.id
                                LEFT JOIN users u ON f.user_id = u.id
                                LEFT JOIN users a ON t.assigned_to = a.id
                                WHERE $where_clause
                                ORDER BY f.created_at DESC
                            ");
                            $stmt->execute($params);
                            $all_feedback = $stmt->fetchAll();
                            ?>
                            <?php if (empty($all_feedback)): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted">No feedback found for selected filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_feedback as $fb): ?>
                                    <tr>
                                        <td>
                                            <strong>#<?= $fb['ticket_id'] ?></strong>
                                        </td>
                                        <td>
                                            <span title="<?= htmlspecialchars($fb['subject']) ?>">
                                                <?= htmlspecialchars(substr($fb['subject'], 0, 40)) ?><?= strlen($fb['subject']) > 40 ? '...' : '' ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($fb['customer_name'] ?? 'Guest') ?></td>
                                        <td><?= htmlspecialchars($fb['agent_name'] ?? 'Unassigned') ?></td>
                                        <td>
                                            <div class="star-display" style="font-size: 1rem;">
                                                <?php for($i = 1; $i <= 5; $i++): ?>
                                                    <?= $i <= $fb['rating'] ? '★' : '☆' ?>
                                                <?php endfor; ?>
                                            </div>
                                            <small class="text-muted"><?= $fb['rating'] ?>/5</small>
                                        </td>
                                        <td>
                                            <?php if ($fb['response_time_rating']): ?>
                                                <div class="star-display" style="font-size: 0.9rem;">
                                                    <?php for($i = 1; $i <= 5; $i++): ?>
                                                        <?= $i <= $fb['response_time_rating'] ? '★' : '☆' ?>
                                                    <?php endfor; ?>
                                                </div>
                                                <small class="text-muted"><?= $fb['response_time_rating'] ?>/5</small>
                                            <?php else: ?>
                                                <small class="text-muted">-</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($fb['resolution_quality_rating']): ?>
                                                <div class="star-display" style="font-size: 0.9rem;">
                                                    <?php for($i = 1; $i <= 5; $i++): ?>
                                                        <?= $i <= $fb['resolution_quality_rating'] ? '★' : '☆' ?>
                                                    <?php endfor; ?>
                                                </div>
                                                <small class="text-muted"><?= $fb['resolution_quality_rating'] ?>/5</small>
                                            <?php else: ?>
                                                <small class="text-muted">-</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($fb['staff_professionalism_rating']): ?>
                                                <div class="star-display" style="font-size: 0.9rem;">
                                                    <?php for($i = 1; $i <= 5; $i++): ?>
                                                        <?= $i <= $fb['staff_professionalism_rating'] ? '★' : '☆' ?>
                                                    <?php endfor; ?>
                                                </div>
                                                <small class="text-muted"><?= $fb['staff_professionalism_rating'] ?>/5</small>
                                            <?php else: ?>
                                                <small class="text-muted">-</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?= date('M j, Y', strtotime($fb['created_at'])) ?></small>
                                        </td>
                                        <td>
                                            <a href="/operations/staff-view-ticket.php?id=<?= $fb['ticket_id'] ?>"
                                               class="btn btn-sm btn-outline-primary"
                                               target="_blank"
                                               title="View Ticket">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Feedback -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="stat-card">
                <h5 class="mb-4"><i class="bi bi-chat-quote me-2"></i>Recent Feedback with Comments</h5>
                <?php if (empty($recent_feedback)): ?>
                    <p class="text-muted text-center">No feedback comments found.</p>
                <?php else: ?>
                    <?php foreach ($recent_feedback as $feedback): ?>
                        <?php if (!empty($feedback['feedback_text'])): ?>
                            <div class="feedback-card">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <strong>Ticket #<?= $feedback['ticket_id'] ?>:</strong>
                                        <?= htmlspecialchars(substr($feedback['subject'], 0, 50)) ?>...
                                        <br>
                                        <small class="text-muted">
                                            By <?= htmlspecialchars($feedback['customer_name'] ?? 'Guest') ?> •
                                            Assigned to <?= htmlspecialchars($feedback['agent_name'] ?? 'Unassigned') ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <div class="star-display">
                                            <?php for($i = 1; $i <= 5; $i++): ?>
                                                <?= $i <= $feedback['rating'] ? '★' : '☆' ?>
                                            <?php endfor; ?>
                                        </div>
                                        <small class="text-muted"><?= date('M j, Y', strtotime($feedback['created_at'])) ?></small>
                                    </div>
                                </div>
                                <div class="border-top pt-2 mt-2">
                                    <em class="text-dark">"<?= htmlspecialchars($feedback['feedback_text']) ?>"</em>
                                </div>
                                <div class="mt-2">
                                    <?php if ($feedback['helpful'] === 'yes'): ?>
                                        <span class="badge bg-success">Issue Resolved</span>
                                    <?php elseif ($feedback['helpful'] === 'neutral'): ?>
                                        <span class="badge bg-warning">Partially Resolved</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Not Resolved</span>
                                    <?php endif; ?>

                                    <?php if ($feedback['would_recommend'] === 1): ?>
                                        <span class="badge bg-info">Would Recommend</span>
                                    <?php elseif ($feedback['would_recommend'] === 0): ?>
                                        <span class="badge bg-secondary">Would Not Recommend</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Make table rows clickable
document.addEventListener('DOMContentLoaded', function() {
    const tableRows = document.querySelectorAll('.table-hover tbody tr');
    tableRows.forEach(row => {
        row.style.cursor = 'pointer';
        row.addEventListener('click', function(e) {
            // Don't trigger if clicking on the button itself
            if (e.target.closest('.btn')) return;

            const link = this.querySelector('a[href*="staff-view-ticket"]');
            if (link) {
                window.open(link.href, '_blank');
            }
        });
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>

