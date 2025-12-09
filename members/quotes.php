<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

// Access control (Administrator and Account Manager only)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'account_manager'])) {
    header('Location: /login.php');
    exit;
}

$user_id = $_SESSION['user']['id'];

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

// Get filters
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query with filters - only show quotes for companies user has access to
$where_conditions = [
    "(q.company_id = (SELECT company_id FROM users WHERE id = ?) 
      OR q.company_id IN (SELECT company_id FROM company_users WHERE user_id = ?))"
];
$params = [$user_id, $user_id];

if (!empty($status_filter)) {
    $where_conditions[] = "q.status = ?";
    $params[] = $status_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(q.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(q.created_at) <= ?";
    $params[] = $date_to;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Get quotes with filters and currency info - Fixed query structure
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

// Get companies user has access to for filter
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id, c.name
    FROM companies c
    WHERE c.id = (SELECT company_id FROM users WHERE id = ?)
       OR c.id IN (SELECT company_id FROM company_users WHERE user_id = ?)
    ORDER BY c.name ASC
");
$stmt->execute([$user_id, $user_id]);
$user_companies = $stmt->fetchAll();

// Get quote statistics for user's companies
$stats_params = [$user_id, $user_id];
$stmt = $pdo->prepare("SELECT 
    COUNT(*) as total_quotes,
    COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft_quotes,
    COUNT(CASE WHEN status = 'sent' THEN 1 END) as sent_quotes,
    COUNT(CASE WHEN status = 'accepted' THEN 1 END) as accepted_quotes,
    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_quotes,
    COUNT(CASE WHEN status = 'expired' THEN 1 END) as expired_quotes
    FROM quotes q
    WHERE (q.company_id = (SELECT company_id FROM users WHERE id = ?) 
           OR q.company_id IN (SELECT company_id FROM company_users WHERE user_id = ?))");
$stmt->execute($stats_params);
$stats = $stmt->fetch();

// Calculate quote values in default currency for user's companies
$revenue_stmt = $pdo->prepare("SELECT 
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
    FROM quotes q
    WHERE (q.company_id = (SELECT company_id FROM users WHERE id = ?) 
           OR q.company_id IN (SELECT company_id FROM company_users WHERE user_id = ?))");
$revenue_stmt->execute($stats_params);
$revenue_stats = $revenue_stmt->fetch();

// Merge stats
$stats = array_merge($stats, $revenue_stats);

$page_title = "My Quotes | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --card-shadow-hover: 0 20px 40px rgba(0, 0, 0, 0.15);
            --border-radius: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Hero Section */
        .quotes-hero-content {
            text-align: center;
            padding: 4rem 0;
            position: relative;
            z-index: 2;
        }

        .quotes-hero-title {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: white;
        }

        .quotes-hero-subtitle {
            font-size: 1.25rem;
            margin-bottom: 2rem;
            opacity: 0.9;
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
            margin-top: -60px;
            position: relative;
            z-index: 10;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 2rem 1.5rem;
            text-align: center;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--card-shadow-hover);
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
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 0;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .filters-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .filters-card h5 {
            background: linear-gradient(135deg, #f8fafc 0%, #e9ecef 100%);
            color: #667eea;
            margin: 0 !important;
            padding: 1.25rem 1.5rem;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
        }

        .filters-card form {
            padding: 1.5rem;
        }

        .quotes-table {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            position: relative;
        }

        .quotes-table::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
            z-index: 1;
        }

        .table thead tr {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .table th {
            background: transparent !important;
            border-bottom: none !important;
            border-right: 1px solid rgba(255,255,255,0.1) !important;
            font-weight: 600;
            color: white;
            padding: 1rem;
        }

        .table th:last-child {
            border-right: none !important;
        }

        .table tbody tr {
            transition: var(--transition);
        }

        .table tbody tr:hover {
            background: rgba(102, 126, 234, 0.05);
            transform: scale(1.01);
        }

        .badge {
            padding: 0.4rem 1rem;
            border-radius: 25px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-draft {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #374151;
        }
        .badge-sent {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }
        .badge-accepted {
            background: var(--success-gradient);
            color: white;
        }
        .badge-rejected {
            background: var(--danger-gradient);
            color: white;
        }
        .badge-expired {
            background: var(--warning-gradient);
            color: white;
        }

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

        .role-indicator {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 0.5rem;
            background: #e0e7ff;
            color: #3730a3;
        }

        /* DARK MODE STYLES */
        html.dark {
            background: #0f172a;
            color: #e2e8f0;
        }

        html.dark body {
            background: #0f172a;
            color: #e2e8f0;
        }

        /* FORCE purple hero gradient to show in dark mode - SAME as light mode */
        :root.dark .hero {
            background: transparent !important;
        }

        :root.dark .hero-gradient {
            /* Don't override the background - keep it the same as light mode! */
            opacity: 1 !important;
            display: block !important;
            visibility: visible !important;
            z-index: 0 !important;
        }

        /* Beautiful fade at bottom of hero in dark mode */
        :root.dark .hero::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 150px;
            background: linear-gradient(
                to bottom,
                rgba(15, 23, 42, 0) 0%,
                rgba(15, 23, 42, 0.7) 50%,
                #0f172a 100%
            ) !important;
            pointer-events: none;
            z-index: 1;
        }

        :root.dark .quotes-hero-title,
        :root.dark .quotes-hero-subtitle {
            color: white !important;
            position: relative;
            z-index: 2;
        }

        html.dark .stat-card {
            background: #1e293b;
            border-color: #334155;
        }

        html.dark .stat-value {
            color: #a78bfa;
        }

        html.dark .stat-label {
            color: #94a3b8;
        }

        html.dark .filters-card {
            background: #1e293b;
            border-color: #334155;
        }

        html.dark .filters-card h5 {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            color: #a78bfa;
            border-color: #334155;
        }

        html.dark .form-label {
            color: #cbd5e1;
        }

        html.dark .form-control,
        html.dark .form-select {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }

        html.dark .form-control:focus,
        html.dark .form-select:focus {
            background: #0f172a;
            border-color: #8b5cf6;
            color: #e2e8f0;
        }

        html.dark .quotes-table {
            background: #1e293b;
            border-color: #334155;
        }

        html.dark .table {
            color: #e2e8f0;
        }

        :root.dark .table tbody {
            background: #1e293b !important;
        }

        :root.dark .table tbody tr {
            background: #1e293b !important;
        }

        :root.dark .table tbody tr:hover {
            background: rgba(139, 92, 246, 0.1) !important;
        }

        :root.dark .table tbody td {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .table tbody td span,
        :root.dark .table tbody td a,
        :root.dark .table tbody td div {
            color: #e2e8f0 !important;
        }

        html.dark .currency-badge {
            background: #334155;
            color: #94a3b8;
        }

        html.dark .currency-note {
            color: #94a3b8;
        }

        html.dark .role-indicator {
            background: #334155;
            color: #cbd5e1;
        }

        html.dark small {
            color: #94a3b8;
        }

        html.dark .btn-primary {
            background: var(--primary-gradient);
            color: white;
        }

        html.dark .alert-success {
            background: linear-gradient(135deg, #064e3b 0%, #065f46 100%);
            color: #d1fae5;
        }

        html.dark .alert-danger {
            background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 100%);
            color: #fecaca;
        }

    </style>

<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="quotes-hero-content">
            <h1 class="quotes-hero-title">
                <i class="bi bi-file-text me-3"></i>My Quotes
            </h1>
            <p class="quotes-hero-subtitle">
                View and manage quotes for your organization
            </p>
            <span class="role-indicator"><?= ucfirst(str_replace('_', ' ', $_SESSION['user']['role'])) ?></span>
        </div>
    </div>
</header>

<div class="container py-5 overlap-cards">
    <!-- Success/Error Messages -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars(urldecode($_GET['success'])) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars(urldecode($_GET['error'])) ?>
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
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">Filter</button>
                <a href="quotes.php" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>

    <!-- Page Actions -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Quote History</h3>
        <a href="create-quote.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-2"></i>Request New Quote
        </a>
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
                                <i class="bi bi-file-text-fill empty-state-icon"></i>
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
                                        <?php if ($quote['status'] === 'accepted'): ?>
                                            <a href="create-order.php?from_quote=<?= $quote['id'] ?>" class="btn btn-sm btn-outline-success" title="Convert to Order">
                                                <i class="bi bi-cart-plus"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (in_array($quote['status'], ['sent', 'accepted'])): ?>
                                            <a href="print-quote.php?id=<?= $quote['id'] ?>" class="btn btn-sm btn-outline-info" title="Download PDF">
                                                <i class="bi bi-file-pdf"></i>
                                            </a>
                                        <?php endif; ?>
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

<script>
// Auto-check for expired quotes and update styling
document.addEventListener('DOMContentLoaded', function() {
    const expiredRows = document.querySelectorAll('.expired-quote');
    expiredRows.forEach(row => {
        row.style.backgroundColor = '#fef3c7';
        row.style.borderLeft = '4px solid #f59e0b';
    });
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
