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

// Get supported currencies and default currency
$supportedCurrencies = [];
$defaultCurrency = 'GBP';
$exchangeRates = [];

if (class_exists('ConfigManager')) {
    $supportedCurrencies = ConfigManager::getSupportedCurrencies();
    $defaultCurrency = ConfigManager::get('business.default_currency', 'GBP');
    $exchangeRates = ConfigManager::getExchangeRates();
} else {
    // Fallback
    $supportedCurrencies = [
        'GBP' => ['symbol' => '£', 'name' => 'British Pound'],
        'USD' => ['symbol' => '$', 'name' => 'US Dollar'],
        'EUR' => ['symbol' => '€', 'name' => 'Euro'],
        'CAD' => ['symbol' => 'C$', 'name' => 'Canadian Dollar'],
        'AUD' => ['symbol' => 'A$', 'name' => 'Australian Dollar']
    ];
    $exchangeRates = [
        'GBP' => 1.0,
        'USD' => 1.27,
        'EUR' => 1.16,
        'CAD' => 1.71,
        'AUD' => 1.91
    ];
}

$defaultCurrencySymbol = $supportedCurrencies[$defaultCurrency]['symbol'] ?? '£';

// Handle quote status changes
if (isset($_POST['update_status'])) {
    $quote_id = (int)$_POST['quote_id'];
    $new_status = $_POST['new_status'];
    $notes = trim($_POST['status_notes'] ?? '');

    try {
        // Get current quote details
        $stmt = $pdo->prepare("SELECT status FROM quotes WHERE id = ?");
        $stmt->execute([$quote_id]);
        $quote = $stmt->fetch();

        if (!$quote) {
            $error = "Quote not found";
        } else {
            $current_status = $quote['status'];
            
            // Validate status transitions
            $valid_transition = false;
            $additional_updates = [];

            switch ($new_status) {
                case 'sent':
                    $valid_transition = ($current_status === 'draft');
                    $additional_updates = ['sent_at' => 'NOW()'];
                    break;
                    
                case 'accepted':
                    $valid_transition = ($current_status === 'sent');
                    $additional_updates = ['accepted_at' => 'NOW()'];
                    break;
                    
                case 'rejected':
                    $valid_transition = ($current_status === 'sent');
                    $additional_updates = ['rejected_at' => 'NOW()'];
                    break;
                    
                case 'expired':
                    $valid_transition = ($current_status === 'sent');
                    break;
                    
                case 'draft':
                    $valid_transition = in_array($current_status, ['sent', 'rejected']);
                    break;
            }

            if (!$valid_transition) {
                $error = "Invalid status transition from $current_status to $new_status";
            } else {
                $pdo->beginTransaction();

                // Update quote status
                $update_fields = ['status = ?', 'updated_at = NOW()'];
                $update_values = [$new_status];

                foreach ($additional_updates as $field => $value) {
                    $update_fields[] = "$field = $value";
                }

                $sql = "UPDATE quotes SET " . implode(', ', $update_fields) . " WHERE id = ?";
                $update_values[] = $quote_id;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($update_values);

                // Log status change
                $stmt = $pdo->prepare("INSERT INTO quote_status_history (quote_id, status_from, status_to, changed_by, notes) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$quote_id, $current_status, $new_status, $_SESSION['user']['id'], $notes]);

                $pdo->commit();
                $success = "Quote status updated successfully!";
            }
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Error updating quote status: " . $e->getMessage();
    }
}

// Handle quote deletion
if (isset($_GET['delete_quote'])) {
    $quote_id = (int)$_GET['delete_quote'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM quotes WHERE id = ?");
        $stmt->execute([$quote_id]);
        $success = "Quote deleted successfully!";
    } catch (PDOException $e) {
        $error = "Error deleting quote: " . $e->getMessage();
    }
}

// Get filters
$status_filter = $_GET['status'] ?? '';
$company_filter = $_GET['company'] ?? '';
$staff_filter = $_GET['staff'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query with filters
$where_conditions = [];
$params = [];

if (!empty($status_filter)) {
    $where_conditions[] = "q.status = ?";
    $params[] = $status_filter;
}

if (!empty($company_filter)) {
    $where_conditions[] = "q.company_id = ?";
    $params[] = $company_filter;
}

if (!empty($staff_filter)) {
    $where_conditions[] = "q.staff_id = ?";
    $params[] = $staff_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(q.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(q.created_at) <= ?";
    $params[] = $date_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get quotes with filters and currency info
$stmt = $pdo->prepare("SELECT q.*, c.name as company_name, c.preferred_currency, u.username as staff_name,
    COUNT(qi.id) as item_count
    FROM quotes q
    JOIN companies c ON q.company_id = c.id
    JOIN users u ON q.staff_id = u.id
    LEFT JOIN quote_items qi ON q.id = qi.quote_id
    $where_clause
    GROUP BY q.id
    ORDER BY q.created_at DESC");
$stmt->execute($params);
$quotes = $stmt->fetchAll();

// Get filter options
$stmt = $pdo->query("SELECT id, name FROM companies ORDER BY name ASC");
$companies = $stmt->fetchAll();

$stmt = $pdo->query("SELECT id, username FROM users WHERE role IN ('administrator', 'staff') ORDER BY username ASC");
$staff_users = $stmt->fetchAll();

// Get quote statistics with multi-currency support
$stmt = $pdo->query("SELECT 
    COUNT(*) as total_quotes,
    COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft_quotes,
    COUNT(CASE WHEN status = 'sent' THEN 1 END) as sent_quotes,
    COUNT(CASE WHEN status = 'accepted' THEN 1 END) as accepted_quotes,
    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_quotes,
    COUNT(CASE WHEN status = 'expired' THEN 1 END) as expired_quotes
    FROM quotes");
$stats = $stmt->fetch();

// Calculate quote values in default currency
$revenue_stmt = $pdo->query("SELECT 
    SUM(CASE WHEN status = 'accepted' THEN 
        CASE WHEN currency = '$defaultCurrency' THEN total_amount
        ELSE total_amount / (
            CASE currency 
                WHEN 'USD' THEN " . ($exchangeRates['USD'] ?? 1.27) . "
                WHEN 'EUR' THEN " . ($exchangeRates['EUR'] ?? 1.16) . "
                WHEN 'CAD' THEN " . ($exchangeRates['CAD'] ?? 1.71) . "
                WHEN 'AUD' THEN " . ($exchangeRates['AUD'] ?? 1.91) . "
                ELSE 1
            END
        )
        END
    ELSE 0 END) as accepted_value,
    SUM(CASE WHEN status = 'sent' THEN 
        CASE WHEN currency = '$defaultCurrency' THEN total_amount
        ELSE total_amount / (
            CASE currency 
                WHEN 'USD' THEN " . ($exchangeRates['USD'] ?? 1.27) . "
                WHEN 'EUR' THEN " . ($exchangeRates['EUR'] ?? 1.16) . "
                WHEN 'CAD' THEN " . ($exchangeRates['CAD'] ?? 1.71) . "
                WHEN 'AUD' THEN " . ($exchangeRates['AUD'] ?? 1.91) . "
                ELSE 1
            END
        )
        END
    ELSE 0 END) as pending_value,
    AVG(CASE WHEN status = 'accepted' THEN 
        CASE WHEN currency = '$defaultCurrency' THEN total_amount
        ELSE total_amount / (
            CASE currency 
                WHEN 'USD' THEN " . ($exchangeRates['USD'] ?? 1.27) . "
                WHEN 'EUR' THEN " . ($exchangeRates['EUR'] ?? 1.16) . "
                WHEN 'CAD' THEN " . ($exchangeRates['CAD'] ?? 1.71) . "
                WHEN 'AUD' THEN " . ($exchangeRates['AUD'] ?? 1.91) . "
                ELSE 1
            END
        )
        END
    END) as avg_quote_value
    FROM quotes");
$revenue_stats = $revenue_stmt->fetch();

// Merge stats
$stats = array_merge($stats, $revenue_stats);

$page_title = "Quote Management | CaminhoIT";
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php';
?>
    <style>
        :root {
            --primary-color: #4F46E5;
            --success-color: #10B981;
            --warning-color: #F59E0B;
            --danger-color: #EF4444;
            --info-color: #06B6D4;
        }

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
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-label {
            color: #6b7280;
            font-size: 0.875rem;
        }

        .currency-note {
            color: #6b7280;
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }

        .filters-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .quotes-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .table th {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
            color: #374151;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-draft { background: #f3f4f6; color: #374151; }
        .badge-sent { background: #dbeafe; color: #1e40af; }
        .badge-accepted { background: #d1fae5; color: #065f46; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .badge-expired { background: #fef3c7; color: #92400e; }

        .currency-badge {
            background: #f3f4f6;
            color: #374151;
            padding: 0.125rem 0.5rem;
            border-radius: 4px;
            font-size: 0.625rem;
            font-weight: 500;
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: #3f37c9;
        }

        .alert {
            border: none;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .quote-actions {
            display: flex;
            gap: 0.25rem;
        }

        .form-control, .form-select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0.5rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .workflow-info {
            background: #f0f9ff;
            border: 1px solid #e0e7ff;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .expired-quote {
            opacity: 0.7;
        }

        .quote-title {
            font-weight: 600;
            color: #1e293b;
        }

        .quote-company {
            color: #64748b;
            font-size: 0.875rem;
        }
    </style>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-file-text me-3"></i>Quote Management</h1>
                <p class="text-muted mb-0">Create, manage and track quotes for your clients</p>
            </div>
            <div>
                <a href="create-quote.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-2"></i>Create New Quote
                </a>
            </div>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success)): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i><?= $success ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i><?= $error ?>
        </div>
    <?php endif; ?>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['total_quotes'] ?? 0) ?></div>
            <div class="stat-label">Total Quotes</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['draft_quotes'] ?? 0) ?></div>
            <div class="stat-label">Draft Quotes</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['sent_quotes'] ?? 0) ?></div>
            <div class="stat-label">Sent Quotes</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['accepted_quotes'] ?? 0) ?></div>
            <div class="stat-label">Accepted Quotes</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $defaultCurrencySymbol ?><?= number_format($stats['accepted_value'] ?? 0, 2) ?></div>
            <div class="stat-label">Accepted Value</div>
            <div class="currency-note">Converted to <?= $defaultCurrency ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $defaultCurrencySymbol ?><?= number_format($stats['pending_value'] ?? 0, 2) ?></div>
            <div class="stat-label">Pending Value</div>
            <div class="currency-note">Converted to <?= $defaultCurrency ?></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <h5 class="mb-3">Filter Quotes</h5>
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="draft" <?= $status_filter === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="sent" <?= $status_filter === 'sent' ? 'selected' : '' ?>>Sent</option>
                    <option value="accepted" <?= $status_filter === 'accepted' ? 'selected' : '' ?>>Accepted</option>
                    <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="expired" <?= $status_filter === 'expired' ? 'selected' : '' ?>>Expired</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Company</label>
                <select name="company" class="form-select">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $company): ?>
                        <option value="<?= $company['id'] ?>" <?= $company_filter == $company['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($company['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">Filter</button>
                <a href="quotes.php" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>

    <!-- Quotes Table -->
    <div class="quotes-table">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Quote #</th>
                        <th>Title</th>
                        <th>Company</th>
                        <th>Staff</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Valid Until</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($quotes)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-4">
                                <i class="bi bi-file-text-fill" style="font-size: 3rem; color: #d1d5db;"></i>
                                <p class="text-muted mt-2">No quotes found</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($quotes as $quote): ?>
                            <?php
                            $quote_currency = $quote['currency'] ?? $defaultCurrency;
                            $currency_symbol = $supportedCurrencies[$quote_currency]['symbol'] ?? $defaultCurrencySymbol;
                            $is_expired = $quote['valid_until'] && strtotime($quote['valid_until']) < time();
                            ?>
                            <tr class="<?= $is_expired ? 'expired-quote' : '' ?>">
                                <td>
                                    <strong><?= htmlspecialchars($quote['quote_number']) ?></strong>
                                </td>
                                <td>
                                    <div class="quote-title"><?= htmlspecialchars($quote['title']) ?></div>
                                    <?php if ($quote['description']): ?>
                                        <div class="quote-company"><?= htmlspecialchars(substr($quote['description'], 0, 50)) ?>...</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($quote['company_name']) ?>
                                    <?php if ($quote['preferred_currency'] && $quote['preferred_currency'] !== $defaultCurrency): ?>
                                        <br><small class="currency-badge"><?= $quote['preferred_currency'] ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($quote['staff_name']) ?></td>
                                <td><?= $quote['item_count'] ?> items</td>
                                <td>
                                    <?= $currency_symbol ?><?= number_format($quote['total_amount'], 2) ?>
                                    <?php if ($quote_currency !== $defaultCurrency): ?>
                                        <br><small class="currency-badge"><?= $quote_currency ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $quote['status'] ?>">
                                        <?= ucfirst($quote['status']) ?>
                                    </span>
                                    <?php if ($is_expired): ?>
                                        <br><small class="text-danger">Expired</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($quote['valid_until']): ?>
                                        <?= date('d M Y', strtotime($quote['valid_until'])) ?>
                                    <?php else: ?>
                                        <span class="text-muted">No expiry</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d M Y', strtotime($quote['created_at'])) ?></td>
                                <td>
                                    <div class="quote-actions">
                                        <a href="view-quote.php?id=<?= $quote['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="edit-quote.php?id=<?= $quote['id'] ?>" class="btn btn-sm btn-outline-warning">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button class="btn btn-sm btn-outline-info" onclick="updateQuoteStatus(<?= $quote['id'] ?>, '<?= $quote['status'] ?>')">
                                            <i class="bi bi-arrow-repeat"></i>
                                        </button>
                                        <?php if ($quote['status'] === 'accepted'): ?>
                                            <a href="create-order.php?from_quote=<?= $quote['id'] ?>" class="btn btn-sm btn-outline-success" title="Convert to Order">
                                                <i class="bi bi-cart-plus"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="?delete_quote=<?= $quote['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this quote?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div class="modal fade" id="updateStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Quote Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="quote_id" id="status_quote_id">
                
                <div class="mb-3">
                    <label class="form-label">Current Status</label>
                    <input type="text" class="form-control" id="current_status" readonly>
                </div>
                
                <div class="workflow-info">
                    <strong>Quote Workflow:</strong> Draft → Sent → Accepted/Rejected/Expired
                </div>
                
                <div class="mb-3">
                    <label class="form-label">New Status</label>
                    <select name="new_status" class="form-select" required id="new_status_select">
                        <option value="">Select new status...</option>
                        <!-- Options will be populated by JavaScript based on current status -->
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="status_notes" class="form-control" rows="3" placeholder="Optional notes about this status change"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
            </div>
        </form>
    </div>
</div>

<script>
function updateQuoteStatus(quoteId, currentStatus) {
    document.getElementById('status_quote_id').value = quoteId;
    document.getElementById('current_status').value = currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1);
    
    // Define valid next statuses based on current status
    const statusTransitions = {
        'draft': ['sent'],
        'sent': ['accepted', 'rejected', 'expired', 'draft'],
        'accepted': ['draft'],
        'rejected': ['draft'],
        'expired': ['draft']
    };
    
    const statusLabels = {
        'draft': 'Draft',
        'sent': 'Sent',
        'accepted': 'Accepted',
        'rejected': 'Rejected',
        'expired': 'Expired'
    };
    
    const nextStatuses = statusTransitions[currentStatus] || [];
    const selectElement = document.getElementById('new_status_select');
    
    // Clear existing options
    selectElement.innerHTML = '<option value="">Select new status...</option>';
    
    // Add valid transition options
    nextStatuses.forEach(status => {
        const option = document.createElement('option');
        option.value = status;
        option.textContent = statusLabels[status];
        selectElement.appendChild(option);
    });
    
    // If no valid transitions, show message
    if (nextStatuses.length === 0) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'No valid status transitions available';
        option.disabled = true;
        selectElement.appendChild(option);
    }
    
    const modal = new bootstrap.Modal(document.getElementById('updateStatusModal'));
    modal.show();
}

// Auto-check for expired quotes and update styling
document.addEventListener('DOMContentLoaded', function() {
    const expiredRows = document.querySelectorAll('.expired-quote');
    expiredRows.forEach(row => {
        row.style.backgroundColor = '#fef3c7';
        row.style.borderLeft = '4px solid #f59e0b';
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>