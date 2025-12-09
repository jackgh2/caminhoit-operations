<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include existing config and new WHMCS config
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/whmcs-config.php'; // WHMCS API config
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /login.php');
    exit;
}

// Check if user has access (supported_user or account_manager)
if (!in_array($user['role'], ['administrator', 'account_manager'])) {
    header('Location: /members/dashboard.php');
    exit;
}

$user_id = $user['id'];

// Handle AJAX requests for live search and category loading
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'search_products' || $_GET['ajax'] === 'load_category') {
        $category_id = (int)($_GET['category'] ?? 0);
        $search = $_GET['search'] ?? '';
        $price_filter = $_GET['price'] ?? '';

        try {
            // Get products from WHMCS
            if ($search && $search !== 'all') {
                $products = $whmcsApi->searchProducts($search, $category_id ?: null);
            } else {
                $products = $whmcsApi->getProducts($category_id ?: null);
            }

            // Apply price filtering
            if ($price_filter) {
                $products = array_filter($products, function($product) use ($price_filter) {
                    $price = $product['base_price'];
                    switch ($price_filter) {
                        case 'free':
                            return $price == 0;
                        case 'low':
                            return $price > 0 && $price <= 50;
                        case 'medium':
                            return $price > 50 && $price <= 200;
                        case 'high':
                            return $price > 200;
                        default:
                            return true;
                    }
                });
                $products = array_values($products); // Re-index array
            }

            // Get user's current assignments from local database (if this functionality exists)
            $assigned_product_ids = [];
            $assignment_quantities = [];
            
            if (isset($pdo)) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT p.id as product_id, b.id as bundle_id, 
                               COALESCE(SUM(pa.assigned_quantity), 0) as total_assigned
                        FROM product_assignments pa
                        JOIN client_subscriptions cs ON pa.subscription_id = cs.id
                        LEFT JOIN products p ON cs.product_id = p.id
                        LEFT JOIN service_bundles b ON cs.bundle_id = b.id
                        WHERE pa.user_id = ? AND pa.status = 'assigned' AND cs.status = 'active'
                        GROUP BY p.id, b.id
                    ");
                    $stmt->execute([$user_id]);
                    $user_assignments = $stmt->fetchAll();
                    $assigned_product_ids = array_filter(array_column($user_assignments, 'product_id'));

                    // Create assignment map with quantities
                    foreach ($user_assignments as $assignment) {
                        if ($assignment['product_id']) {
                            $assignment_quantities['product_' . $assignment['product_id']] = $assignment['total_assigned'];
                        }
                    }
                } catch (Exception $e) {
                    // Database not available or table doesn't exist
                    error_log('Database error in service catalogue: ' . $e->getMessage());
                }
            }

            // Get current category info if needed
            $current_category = null;
            if ($category_id) {
                $categories = $whmcsApi->getProductGroups();
                foreach ($categories as $cat) {
                    if ($cat['id'] == $category_id) {
                        $current_category = $cat;
                        break;
                    }
                }
            }

            // For now, bundles are empty (WHMCS doesn't have direct bundle support in basic API)
            $bundles = [];
            $bundle_products = [];

            echo json_encode([
                'success' => true,
                'products' => $products,
                'bundles' => $bundles,
                'bundle_products' => $bundle_products,
                'assigned_product_ids' => $assigned_product_ids,
                'assignment_quantities' => $assignment_quantities,
                'current_category' => $current_category,
                'search_term' => $search
            ]);
            
        } catch (Exception $e) {
            error_log('WHMCS API Error: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => 'Unable to fetch products from WHMCS. Please try again later.',
                'details' => $e->getMessage(),
                'products' => [],
                'bundles' => [],
                'bundle_products' => [],
                'assigned_product_ids' => [],
                'assignment_quantities' => [],
                'current_category' => null,
                'search_term' => $search
            ]);
        }
        exit;
    }
}

// Test WHMCS connection and get categories
$categories = [];
$whmcs_connection_error = null;
$whmcs_connected = false;

try {
    // Test connection first
    $connectionTest = $whmcsApi->testConnection();
    if ($connectionTest['success']) {
        $whmcs_connected = true;
        // If connection is successful, get categories
        $categories = $whmcsApi->getProductGroups();
        if (empty($categories)) {
            // Connection works but no products - this is OK, just log it
            error_log('WHMCS connection successful but no product groups found');
        }
    } else {
        $whmcs_connection_error = $connectionTest['message'];
        error_log('WHMCS connection failed: ' . $whmcs_connection_error);
    }
} catch (Exception $e) {
    $whmcs_connection_error = $e->getMessage();
    error_log('WHMCS connection exception: ' . $whmcs_connection_error);
}

// Get user's current assignments for stats (from local database if available)
$total_assigned = 0;
if (isset($pdo)) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.id as product_id, b.id as bundle_id, 
                   COALESCE(SUM(pa.assigned_quantity), 0) as total_assigned
            FROM product_assignments pa
            JOIN client_subscriptions cs ON pa.subscription_id = cs.id
            LEFT JOIN products p ON cs.product_id = p.id
            LEFT JOIN service_bundles b ON cs.bundle_id = b.id
            WHERE pa.user_id = ? AND pa.status = 'assigned' AND cs.status = 'active'
            GROUP BY p.id, b.id
        ");
        $stmt->execute([$user_id]);
        $user_assignments = $stmt->fetchAll();
        $total_assigned = count($user_assignments);
    } catch (Exception $e) {
        // Database not available
        $total_assigned = 0;
    }
}

// Helper functions
function formatPrice($price) {
    return $price == 0 ? 'Free' : '£' . number_format($price, 2);
}

function getPriceBadgeClass($price) {
    if ($price == 0) return 'bg-success';
    if ($price <= 50) return 'bg-info';
    if ($price <= 200) return 'bg-warning';
    return 'bg-primary';
}

$page_title = "Service Catalog | CaminhoIT";
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link rel="stylesheet" href="/assets/styles.css">
    
    <style>
        /* FIXED HORIZONTAL STATS LAYOUT */
        .stats-grid {
            display: flex !important;
            flex-direction: row !important;
            justify-content: space-between !important;
            align-items: stretch !important;
            gap: 1.5rem !important;
            margin-bottom: 2rem !important;
            flex-wrap: wrap !important;
        }

        .dashboard-stat-box {
            flex: 1 !important;
            min-width: 200px !important;
            max-width: none !important;
        }

        /* Template-style cards */
        .template-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            position: relative;
            margin-bottom: 2rem;
            transition: all 0.3s ease;
        }

        .template-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            z-index: 1;
        }

        .template-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .template-card-header {
            padding: 1.5rem 2rem 1rem 2rem;
            border-bottom: 1px solid #e9ecef;
            background: #f8f9fa;
        }

        .template-card-body {
            padding: 2rem;
        }

        /* Category Cards */
        .category-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .category-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            border: 2px solid transparent;
        }

        .category-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .category-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            border-color: #667eea;
        }

        .category-card.selected {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.05);
            transform: translateY(-5px);
        }

        .category-card.selected::before {
            height: 6px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }

        .category-icon {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: white;
            margin: 0 auto 1.5rem;
            transition: all 0.3s ease;
        }

        .category-card:hover .category-icon {
            transform: scale(1.1) rotateY(10deg);
        }

        .category-card.selected .category-icon {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .category-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.75rem;
        }

        .category-card.selected .category-title {
            color: #667eea;
            font-weight: 700;
        }

        .category-description {
            color: #64748B;
            font-size: 0.875rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }

        /* Product Cards */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .product-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1.5rem;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .product-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .product-card.featured {
            border-color: #f59e0b;
            background: linear-gradient(135deg, #fffbeb 0%, #ffffff 100%);
        }

        .product-card.featured::before {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .product-card.assigned {
            border-color: #10b981;
            background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%);
        }

        .product-card.assigned::before {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .featured-badge, .assigned-badge {
            position: absolute;
            top: -8px;
            right: 1rem;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .featured-badge {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .assigned-badge {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .product-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .product-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 0.5rem 0;
        }

        .product-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
            text-align: right;
        }

        .product-price-unit {
            font-size: 0.875rem;
            font-weight: 400;
            color: #64748B;
        }

        .product-description {
            color: #64748B;
            font-size: 0.875rem;
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .product-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .product-actions {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        .assigned-info {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: #065f46;
        }

        /* Search and Filter Enhancements */
        .search-container {
            position: relative;
        }

        .search-loading {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            display: none;
        }

        .search-loading.show {
            display: block;
        }

        .loading-spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Results Container */
        .results-container {
            display: none;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.4s ease;
        }

        .results-container.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        /* Filters */
        .filter-section {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .filter-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .view-toggle {
            display: flex;
            gap: 0.5rem;
        }

        .view-toggle .btn {
            padding: 0.5rem 1rem;
            border: 1px solid #e2e8f0;
            background: white;
            color: #64748B;
            border-radius: 6px;
        }

        .view-toggle .btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }

        /* Success indicator */
        .api-success {
            background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%);
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            color: #15803d;
            text-align: center;
        }

        /* API Error Display */
        .api-error {
            background: linear-gradient(135deg, #fef2f2 0%, #ffffff 100%);
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            color: #dc2626;
        }

        .api-error h4 {
            color: #dc2626;
            margin-bottom: 0.5rem;
        }

        /* Offline indicator */
        .offline-indicator {
            background: linear-gradient(135deg, #f3f4f6 0%, #ffffff 100%);
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            color: #6b7280;
            text-align: center;
        }

        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .stats-grid {
                flex-direction: column !important;
                gap: 1rem !important;
            }
            
            .dashboard-stat-box {
                min-width: unset !important;
            }
            
            .category-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
            
            .products-grid {
                grid-template-columns: 1fr;
            }
            
            .product-header {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .product-price {
                text-align: left;
            }
            
            .template-card-body {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<!-- Enhanced Compact Hero Section -->
<header class="hero-enhanced">
    <div class="container">
        <div class="hero-content-enhanced">
            <h1 class="hero-title-enhanced text-white">
                <i class="bi bi-grid-3x3-gap me-3"></i>
                Service Catalog
            </h1>
            <p class="hero-subtitle-enhanced text-white">
                Browse our available products and services powered by WHMCS
            </p>
            <div class="hero-actions">
                <a href="#catalog-overview" class="hero-btn hero-btn-primary">
                    <i class="bi bi-arrow-down"></i>
                    Browse Services
                </a>
                <a href="/members/raise-ticket.php" class="hero-btn hero-btn-outline">
                    <i class="bi bi-headset"></i>
                    Get Support
                </a>
            </div>
            <span class="role-indicator"><?= ucfirst(str_replace('_', ' ', $user['role'])) ?></span>
        </div>
    </div>
</header>

<div class="container py-5 overlap-cards" id="catalog-overview">
    <!-- API Connection Status -->
    <?php if (!$whmcs_connected): ?>
    <div class="api-error">
        <h4><i class="bi bi-exclamation-triangle me-2"></i>WHMCS Connection Issue</h4>
        <p class="mb-2">Unable to connect to WHMCS API:</p>
        <p class="mb-2"><strong><?= htmlspecialchars($whmcs_connection_error) ?></strong></p>
        <ul class="mb-2">
            <li>Check API credentials and permissions</li>
            <li>Verify WHMCS server is accessible</li>
            <li>Check IP restrictions in WHMCS admin</li>
        </ul>
        <p class="mb-0"><strong>Admin:</strong> Check error logs and verify API settings in whmcs-config.php</p>
    </div>
    <?php elseif (empty($categories)): ?>
    <div class="api-success">
        <i class="bi bi-check-circle me-2"></i>
        <strong>WHMCS Connected Successfully</strong> but no product groups found. 
        Products may not be organized into groups or no products exist yet.
    </div>
    <?php endif; ?>

    <!-- HORIZONTAL STATISTICS -->
    <div class="stats-grid fade-in">
        <div class="dashboard-stat-box">
            <div class="stat-icon primary">
                <i class="bi bi-grid-3x3-gap"></i>
            </div>
            <div class="stat-number primary"><?= count($categories) ?></div>
            <div class="stat-label">Categories</div>
        </div>
        
        <div class="dashboard-stat-box">
            <div class="stat-icon info">
                <i class="bi bi-box"></i>
            </div>
            <div class="stat-number info" id="total-products-stat">0</div>
            <div class="stat-label">Available Products</div>
        </div>
        
        <div class="dashboard-stat-box">
            <div class="stat-icon warning">
                <i class="bi bi-collection"></i>
            </div>
            <div class="stat-number warning" id="total-bundles-stat">0</div>
            <div class="stat-label">Service Bundles</div>
        </div>
        
        <div class="dashboard-stat-box">
            <div class="stat-icon success">
                <i class="bi bi-check-circle"></i>
            </div>
            <div class="stat-number success"><?= $total_assigned ?></div>
            <div class="stat-label">Your Services</div>
        </div>
    </div>

    <!-- Live Search and Filters -->
    <div class="filter-section fade-in">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0">
                <i class="bi bi-funnel me-2"></i>Search & Browse Services
            </h3>
            <div class="view-toggle">
                <button class="btn" id="clearAllBtn" onclick="clearAllFilters()" style="display: none;">
                    <i class="bi bi-x-circle me-1"></i>Clear All
                </button>
                <button class="btn" onclick="showAllProducts()">
                    <i class="bi bi-list me-1"></i>All Products
                </button>
            </div>
        </div>
        
        <div class="row g-3">
            <div class="col-md-4">
                <div class="search-container">
                    <input type="text" id="liveSearch" class="form-control" placeholder="Type to search products..." autocomplete="off">
                    <div class="search-loading" id="searchLoading">
                        <div class="loading-spinner"></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <select id="priceFilter" class="form-select">
                    <option value="">All Prices</option>
                    <option value="free">Free</option>
                    <option value="low">£1 - £50</option>
                    <option value="medium">£51 - £200</option>
                    <option value="high">£200+</option>
                </select>
            </div>
            <div class="col-md-5">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" onclick="clearSearch()">
                        <i class="bi bi-arrow-clockwise me-1"></i>Clear Search
                    </button>
                    <span class="text-muted small align-self-center" id="searchStatus">
                        <?php if (!$whmcs_connected): ?>
                            WHMCS API unavailable
                        <?php elseif (empty($categories)): ?>
                            WHMCS connected - Use search to find products
                        <?php else: ?>
                            Select a category or search to see products
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Categories Section - Show if we have categories OR if connected but no categories -->
    <?php if ($whmcs_connected): ?>
        <?php if (!empty($categories)): ?>
        <div class="template-card fade-in" id="categoriesSection">
            <div class="template-card-header">
                <h2 class="mb-1">
                    <i class="bi bi-grid-3x3-gap me-2"></i>Browse by Category
                </h2>
                <p class="text-muted mb-0">Click on a category to explore services, or use search above</p>
            </div>
            <div class="template-card-body">
                <div class="category-grid">
                    <?php foreach ($categories as $category): ?>
                        <div class="category-card" data-category-id="<?= $category['id'] ?>" onclick="loadCategory(<?= $category['id'] ?>, '<?= htmlspecialchars($category['name']) ?>')">
                            <button class="clear-selection" onclick="event.stopPropagation(); clearCategorySelection();" title="Clear selection">
                                <i class="bi bi-x"></i>
                            </button>
                            <div class="category-icon" style="background-color: <?= htmlspecialchars($category['color']) ?>;">
                                <i class="<?= htmlspecialchars($category['icon']) ?>"></i>
                            </div>
                            <h3 class="category-title"><?= htmlspecialchars($category['name']) ?></h3>
                            <p class="category-description"><?= htmlspecialchars($category['description']) ?></p>
                            <div class="d-flex justify-content-center">
                                <span class="badge bg-primary" id="category-count-<?= $category['id'] ?>">
                                    Loading...
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="template-card fade-in">
            <div class="template-card-header">
                <h2 class="mb-1">
                    <i class="bi bi-search me-2"></i>Product Search
                </h2>
                <p class="text-muted mb-0">WHMCS is connected but no product categories found. Use the search above to find products.</p>
            </div>
            <div class="template-card-body text-center py-4">
                <i class="bi bi-search" style="font-size: 3rem; color: #6c757d; opacity: 0.5;"></i>
                <h4 class="mt-3 text-muted">Search for Products</h4>
                <p class="text-muted">Try searching for specific product names or use "all" to see everything</p>
            </div>
        </div>
        <?php endif; ?>
    <?php else: ?>
    <div class="offline-indicator">
        <i class="bi bi-wifi-off" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>
        <h4>Service Catalog Temporarily Unavailable</h4>
        <p class="mb-0">We're unable to load the product catalog at this time. Please try again later or contact support.</p>
    </div>
    <?php endif; ?>

    <!-- Dynamic Results Container - Shows Below Categories -->
    <div class="results-container" id="resultsContainer">
        <!-- Results will be inserted here -->
    </div>
</div>

<!-- Service Request Modal -->
<div class="modal fade" id="serviceRequestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-cart-plus me-2"></i>Request Service
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    You are requesting: <strong id="serviceName"></strong>
                </div>
                
                <div class="row">
                    <div class="col-md-8">
                        <div class="mb-3">
                            <label class="form-label">Quantity</label>
                            <div class="quantity-selector">
                                <button type="button" class="quantity-btn" id="decreaseQty">
                                    <i class="bi bi-dash"></i>
                                </button>
                                <input type="number" id="quantity" class="form-control quantity-input" value="1" min="1" max="999">
                                <button type="button" class="quantity-btn" id="increaseQty">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </div>
                            <small class="form-text text-muted">
                                Minimum quantity: <span id="minQuantity">1</span>
                            </small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="total-price">
                            <div class="total-price-value" id="totalPrice">£0.00</div>
                            <div class="total-price-breakdown" id="priceBreakdown">per month</div>
                            <div class="total-price-breakdown" id="setupFeeDisplay" style="display: none;">
                                + £<span id="setupFeeAmount">0.00</span> setup fee
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Business Justification <span class="text-danger">*</span></label>
                    <textarea id="justification" class="form-control" rows="4" placeholder="Please explain why you need this service and how it will be used for business purposes..." required></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Urgency Level</label>
                            <select id="urgency" class="form-select">
                                <option value="Low">Low Priority - Can wait</option>
                                <option value="Medium" selected>Medium Priority - Within a week</option>
                                <option value="High">High Priority - As soon as possible</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Preferred Start Date</label>
                            <input type="date" id="startDate" class="form-control" min="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Additional Requirements</label>
                    <textarea id="requirements" class="form-control" rows="3" placeholder="Any specific requirements, configurations, or additional information..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="submitRequestBtn">
                    <i class="bi bi-send me-2"></i>Submit Request
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Service Catalog with WHMCS Integration
(function() {
    'use strict';
    
    let currentServiceType = '';
    let currentServiceId = '';
    let currentServiceName = '';
    let currentBasePrice = 0;
    let currentSetupFee = 0;
    let currentMinQuantity = 1;
    let searchTimeout = null;
    let isSearchActive = false;
    let selectedCategoryId = null;

    // Utility functions
    function formatPrice(price) {
        return price == 0 ? 'Free' : '£' + parseFloat(price).toFixed(2);
    }

    function getPriceBadgeClass(price) {
        if (price == 0) return 'bg-success';
        if (price <= 50) return 'bg-info';
        if (price <= 200) return 'bg-warning';
        return 'bg-primary';
    }

    // Update search status display
    function updateSearchStatus(text) {
        document.getElementById('searchStatus').textContent = text;
    }

    // Show/hide clear all button
    function toggleClearAllButton(show) {
        const clearBtn = document.getElementById('clearAllBtn');
        clearBtn.style.display = show ? 'block' : 'none';
    }

    // Display API error
    function displayApiError(error) {
        const resultsContainer = document.getElementById('resultsContainer');
        resultsContainer.innerHTML = '<div class="api-error"><h4><i class="bi bi-exclamation-triangle me-2"></i>Connection Error</h4><p>Unable to fetch products from WHMCS: ' + error + '</p><p class="mb-0">Please try again later or contact support if the problem persists.</p></div>';
        resultsContainer.classList.add('show');
    }

    // Live Search Functionality
    function setupLiveSearch() {
        const searchInput = document.getElementById('liveSearch');
        const searchLoading = document.getElementById('searchLoading');
        const priceFilter = document.getElementById('priceFilter');

        function performSearch() {
            const searchTerm = searchInput.value.trim();
            const priceFilterValue = priceFilter.value;
            
            if (searchTerm.length < 2 && !priceFilterValue && !selectedCategoryId) {
                if (isSearchActive) {
                    clearResults();
                    updateSearchStatus('Select a category or search to see products');
                }
                return;
            }

            searchLoading.classList.add('show');
            isSearchActive = true;

            const params = new URLSearchParams({
                ajax: 'search_products',
                search: searchTerm,
                price: priceFilterValue,
                category: selectedCategoryId || ''
            });

            fetch('?' + params.toString())
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayResults(data, searchTerm);
                        toggleClearAllButton(true);
                    } else {
                        displayApiError(data.error || 'Unknown error occurred');
                        updateSearchStatus('Error loading products');
                    }
                })
                .catch(error => {
                    console.error('Search error:', error);
                    displayApiError('Network connection failed');
                    updateSearchStatus('Connection failed');
                })
                .finally(() => {
                    searchLoading.classList.remove('show');
                });
        }

        // Live search with debounce
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(performSearch, 300);
        });

        // Price filter change
        priceFilter.addEventListener('change', performSearch);
    }

    // Category Loading
    window.loadCategory = function(categoryId, categoryName) {
        // Update category selection visual state
        document.querySelectorAll('.category-card').forEach(card => {
            card.classList.remove('selected');
        });
        document.querySelector('[data-category-id="' + categoryId + '"]').classList.add('selected');
        
        selectedCategoryId = categoryId;
        
        const searchLoading = document.getElementById('searchLoading');
        searchLoading.classList.add('show');

        const searchTerm = document.getElementById('liveSearch').value;
        const priceFilterValue = document.getElementById('priceFilter').value;

        const params = new URLSearchParams({
            ajax: 'load_category',
            category: categoryId,
            search: searchTerm,
            price: priceFilterValue
        });

        fetch('?' + params.toString())
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayResults(data, null, categoryName);
                    toggleClearAllButton(true);
                } else {
                    displayApiError(data.error || 'Unknown error occurred');
                }
            })
            .catch(error => {
                console.error('Category loading error:', error);
                displayApiError('Failed to load category');
            })
            .finally(() => {
                searchLoading.classList.remove('show');
            });
    };

    // Clear category selection
    window.clearCategorySelection = function() {
        selectedCategoryId = null;
        document.querySelectorAll('.category-card').forEach(card => {
            card.classList.remove('selected');
        });
        
        // Trigger search if there's a search term or filter
        const searchTerm = document.getElementById('liveSearch').value;
        const priceFilterValue = document.getElementById('priceFilter').value;
        
        if (searchTerm.length >= 2 || priceFilterValue) {
            const event = new Event('input');
            document.getElementById('liveSearch').dispatchEvent(event);
        } else {
            clearResults();
            updateSearchStatus('Select a category or search to see products');
            toggleClearAllButton(false);
        }
    };

    // Display results
    function displayResults(data, searchTerm, categoryName) {
        searchTerm = searchTerm || null;
        categoryName = categoryName || null;
        
        const resultsContainer = document.getElementById('resultsContainer');
        resultsContainer.innerHTML = '';
        
        // Create breadcrumb
        let breadcrumbText = '';
        if (categoryName && searchTerm) {
            breadcrumbText = categoryName + ' - Search: "' + searchTerm + '"';
        } else if (categoryName) {
            breadcrumbText = categoryName;
        } else if (searchTerm) {
            breadcrumbText = 'Search: "' + searchTerm + '"';
        } else {
            breadcrumbText = 'All Products';
        }
        
        if (breadcrumbText) {
            const breadcrumbHtml = '<div class="breadcrumb-bar"><nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item">Service Catalog</li><li class="breadcrumb-item active" aria-current="page">' + breadcrumbText + '</li></ol></nav></div>';
            resultsContainer.innerHTML += breadcrumbHtml;
        }

        // Add bundles if any
        if (data.bundles && data.bundles.length > 0) {
            resultsContainer.innerHTML += createBundlesSection(data.bundles, data.bundle_products, data.assigned_bundle_ids, data.assignment_quantities);
        }

        // Add products
        resultsContainer.innerHTML += createProductsSection(data.products, data.assigned_product_ids, data.assignment_quantities, data.current_category, searchTerm);

        // Update stats and status
        updateStats(data.products.length, data.bundles.length);
        updateSearchStatus('Showing ' + data.products.length + ' products' + (data.bundles.length > 0 ? ' and ' + data.bundles.length + ' bundles' : '') + ' from WHMCS');

        // Show results with animation
        resultsContainer.classList.add('show');
    }

    // Clear results
    function clearResults() {
        const resultsContainer = document.getElementById('resultsContainer');
        resultsContainer.classList.remove('show');
        setTimeout(() => {
            resultsContainer.innerHTML = '';
        }, 300);
        isSearchActive = false;
        updateStats(0, 0);
    }

    // Create bundles section HTML
    function createBundlesSection(bundles, bundleProducts, assignedBundleIds, assignmentQuantities) {
        if (!bundles || bundles.length === 0) return '';

        let html = '<div class="template-card"><div class="template-card-header"><h2 class="mb-1"><i class="bi bi-collection me-2"></i>Service Bundles</h2><p class="text-muted mb-0">Comprehensive packages combining multiple services at discounted rates</p></div><div class="template-card-body">';

        bundles.forEach(bundle => {
            const isAssigned = assignedBundleIds && assignedBundleIds.includes(bundle.id);
            const assignedQuantity = assignmentQuantities ? (assignmentQuantities['bundle_' + bundle.id] || 1) : 1;
            
            html += '<div class="bundle-card ' + (isAssigned ? 'assigned' : '') + '">';
            
            if (bundle.is_featured) {
                html += '<div class="featured-badge"><i class="bi bi-star-fill me-1"></i>Featured</div>';
            }
            
            if (isAssigned) {
                html += '<div class="assigned-badge"><i class="bi bi-check-circle-fill"></i>Assigned (' + assignedQuantity + ')</div>';
            }

            html += '<div class="row"><div class="col-md-8"><h3 class="bundle-title">' + bundle.name + '</h3>';
            html += '<p class="text-muted mb-3">' + bundle.target_audience + '</p>';
            html += '<p class="mb-3">' + bundle.short_description + '</p>';
            
            html += '<div class="d-flex gap-2 mb-3">';
            html += '<span class="badge bg-info">' + bundle.billing_cycle.charAt(0).toUpperCase() + bundle.billing_cycle.slice(1) + ' Billing</span>';
            if (bundle.discount_percentage > 0) {
                html += '<span class="badge bg-success">' + bundle.discount_percentage + '% Discount</span>';
            }
            html += '</div>';

            if (bundleProducts[bundle.id]) {
                html += '<div class="bundle-products"><h6><i class="bi bi-box me-2"></i>Included Products</h6>';
                bundleProducts[bundle.id].forEach(bp => {
                    html += '<div class="bundle-product-item"><div class="flex-grow-1">';
                    html += '<strong>' + bp.product_name + '</strong>';
                    html += '<span class="badge bg-secondary ms-2">' + bp.quantity + ' ' + bp.unit_type.replace('_', ' ').charAt(0).toUpperCase() + bp.unit_type.replace('_', ' ').slice(1) + '</span>';
                    if (bp.is_optional) {
                        html += '<span class="badge bg-warning ms-1">Optional</span>';
                    }
                    html += '</div></div>';
                });
                html += '</div>';
            }
            
            html += '</div><div class="col-md-4 text-end">';
            html += '<div class="bundle-price">' + formatPrice(bundle.bundle_price) + '<div class="product-price-unit">per ' + bundle.billing_cycle + '</div></div>';
            html += '<div class="mt-3"><button class="btn btn-primary btn-lg w-100" onclick="requestService(\'bundle\', ' + bundle.id + ', \'' + bundle.name + '\', ' + bundle.bundle_price + ', 1, ' + (bundle.minimum_quantity || 1) + ')"><i class="bi bi-cart-plus me-2"></i>Order Bundle</button></div>';
            html += '</div></div></div>';
        });

        html += '</div></div>';
        return html;
    }

    // Create products section HTML
    function createProductsSection(products, assignedProductIds, assignmentQuantities, currentCategory, searchTerm) {
        searchTerm = searchTerm || null;
        
        let html = '<div class="template-card"><div class="template-card-header">';

        if (currentCategory) {
            html += '<h2 class="mb-1"><i class="bi bi-box me-2"></i>' + currentCategory.name + ' Products</h2><p class="text-muted mb-0">' + currentCategory.description + '</p>';
        } else if (searchTerm) {
            html += '<h2 class="mb-1"><i class="bi bi-search me-2"></i>Search Results</h2><p class="text-muted mb-0">Products matching "' + searchTerm + '" from WHMCS</p>';
        } else {
            html += '<h2 class="mb-1"><i class="bi bi-box me-2"></i>Products</h2><p class="text-muted mb-0">Available products and services from WHMCS</p>';
        }

        html += '</div><div class="template-card-body">';

        if (!products || products.length === 0) {
            html += '<div class="text-center py-5"><i class="bi bi-search text-muted" style="font-size: 4rem; opacity: 0.3;"></i><h3 class="mt-3 text-muted">No Products Found</h3>';
            if (searchTerm) {
                html += '<p class="text-muted">Try adjusting your search criteria or browse other categories.</p>';
            } else {
                html += '<p class="text-muted">This category doesn\'t have any products available in WHMCS.</p>';
            }
            html += '</div>';
        } else {
            html += '<div class="products-grid">';
            
            products.forEach(product => {
                const isAssigned = assignedProductIds && assignedProductIds.includes(product.id);
                const assignedQuantity = assignmentQuantities ? (assignmentQuantities['product_' + product.id] || 1) : 1;
                
                html += '<div class="product-card ' + (product.is_featured ? 'featured' : '') + ' ' + (isAssigned ? 'assigned' : '') + '">';
                
                if (product.is_featured && !isAssigned) {
                    html += '<div class="featured-badge"><i class="bi bi-star-fill me-1"></i>Featured</div>';
                }
                
                if (isAssigned) {
                    html += '<div class="assigned-badge"><i class="bi bi-check-circle-fill"></i>Assigned (' + assignedQuantity + ')</div>';
                }

                html += '<div class="product-header"><div class="flex-grow-1"><h4 class="product-title">' + product.name + '</h4><div class="product-meta">';
                html += '<span class="badge bg-secondary">' + product.category_name + '</span>';
                html += '<span class="badge ' + getPriceBadgeClass(product.base_price) + '">' + formatPrice(product.base_price);
                if (product.base_price > 0) {
                    html += ' / ' + product.unit_type.replace('_', ' ').charAt(0).toUpperCase() + product.unit_type.replace('_', ' ').slice(1);
                }
                html += '</span>';
                html += '<span class="badge bg-info">' + product.billing_cycle.charAt(0).toUpperCase() + product.billing_cycle.slice(1) + '</span>';
                html += '<span class="badge bg-primary">WHMCS</span>';
                html += '</div></div><div class="product-price">' + formatPrice(product.base_price);
                if (product.base_price > 0) {
                    html += '<div class="product-price-unit">per ' + product.unit_type.replace('_', ' ') + '</div>';
                }
                html += '</div></div>';

                if (isAssigned) {
                    html += '<div class="assigned-info"><i class="bi bi-info-circle me-1"></i>You currently have <strong>' + assignedQuantity + '</strong> ' + product.unit_type.replace('_', ' ') + (assignedQuantity > 1 ? 's' : '') + ' assigned.</div>';
                }

                html += '<p class="product-description">' + (product.short_description || product.description) + '</p>';

                if (product.setup_fee > 0) {
                    html += '<div class="mb-2"><small class="text-muted"><i class="bi bi-gear me-1"></i>One-time setup fee: £' + parseFloat(product.setup_fee).toFixed(2) + '</small></div>';
                }

                if (product.requires_setup) {
                    html += '<div class="mb-2"><small class="text-info"><i class="bi bi-tools me-1"></i>Professional setup included</small></div>';
                }

                html += '<div class="product-actions"><button class="btn btn-primary w-100" onclick="requestService(\'product\', ' + product.id + ', \'' + product.name.replace(/'/g, "\\'") + '\', ' + product.base_price + ', ' + product.setup_fee + ', ' + (product.minimum_quantity || 1) + ')"><i class="bi bi-cart-plus me-2"></i>Order Service</button></div>';
                html += '</div>';
            });
            
            html += '</div>';
        }

        html += '</div></div>';
        return html;
    }

    // Update stats
    function updateStats(productCount, bundleCount) {
        document.getElementById('total-products-stat').textContent = productCount;
        document.getElementById('total-bundles-stat').textContent = bundleCount;
    }

    // Clear all filters and selections
    window.clearAllFilters = function() {
        document.getElementById('liveSearch').value = '';
        document.getElementById('priceFilter').value = '';
        clearCategorySelection();
        toggleClearAllButton(false);
        updateSearchStatus('Select a category or search to see products');
    };

    window.showAllProducts = function() {
        clearAllFilters();
        const searchInput = document.getElementById('liveSearch');
        searchInput.value = 'all';
        
        const event = new Event('input');
        searchInput.dispatchEvent(event);
    };

    window.clearSearch = function() {
        document.getElementById('liveSearch').value = '';
        document.getElementById('priceFilter').value = '';
        
        if (selectedCategoryId) {
            // Keep category selected but clear search
            loadCategory(selectedCategoryId, document.querySelector('[data-category-id="' + selectedCategoryId + '"] .category-title').textContent);
        } else {
            clearResults();
            updateSearchStatus('Select a category or search to see products');
            toggleClearAllButton(false);
        }
    };

    // Load category product counts
    function loadCategoryCounts() {
        <?php if (!empty($categories)): ?>
        <?php foreach ($categories as $category): ?>
            fetch('?ajax=search_products&category=<?= $category['id'] ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const countElement = document.getElementById('category-count-<?= $category['id'] ?>');
                        const productCount = data.products.length;
                        countElement.textContent = productCount + ' Product' + (productCount !== 1 ? 's' : '');
                    }
                })
                .catch(error => {
                    console.error('Error loading category count:', error);
                    const countElement = document.getElementById('category-count-<?= $category['id'] ?>');
                    countElement.textContent = 'Error loading';
                });
        <?php endforeach; ?>
        <?php endif; ?>
    }

    // Service Request Modal Functions
    window.requestService = function(type, id, name, basePrice, setupFee, minQuantity) {
        basePrice = basePrice || 0;
        setupFee = setupFee || 0;
        minQuantity = minQuantity || 1;
        
        currentServiceType = type;
        currentServiceId = id;
        currentServiceName = name;
        currentBasePrice = parseFloat(basePrice);
        currentSetupFee = parseFloat(setupFee);
        currentMinQuantity = parseInt(minQuantity);
        
        document.getElementById('serviceName').textContent = name;
        document.getElementById('minQuantity').textContent = minQuantity;
        document.getElementById('quantity').min = minQuantity;
        document.getElementById('quantity').value = minQuantity;
        
        // Setup fee display
        if (currentSetupFee > 0) {
            document.getElementById('setupFeeAmount').textContent = currentSetupFee.toFixed(2);
            document.getElementById('setupFeeDisplay').style.display = 'block';
        } else {
            document.getElementById('setupFeeDisplay').style.display = 'none';
        }
        
        updateTotalPrice();
        
        const modal = new bootstrap.Modal(document.getElementById('serviceRequestModal'));
        modal.show();
    };

    function updateTotalPrice() {
        const quantity = parseInt(document.getElementById('quantity').value) || 1;
        const total = currentBasePrice * quantity;
        
        document.getElementById('totalPrice').textContent = '£' + total.toFixed(2);
        
        if (currentBasePrice === 0) {
            document.getElementById('priceBreakdown').textContent = 'Free service';
        } else {
            document.getElementById('priceBreakdown').textContent = quantity + ' × £' + currentBasePrice.toFixed(2) + ' per month';
        }
    }

    // Scroll animations
    function setupScrollAnimations() {
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, observerOptions);

        document.querySelectorAll('.fade-in').forEach(el => {
            observer.observe(el);
        });
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        console.log('🚀 WHMCS Service Catalog Loading - Date: 2025-08-04 18:27:14, User: jackbetherxi');
        
        // Setup live search
        setupLiveSearch();
        
        // Load category counts
        loadCategoryCounts();
        
        // Setup scroll animations
        setupScrollAnimations();
        
        // Quantity controls
        document.getElementById('decreaseQty').addEventListener('click', function() {
            const quantityInput = document.getElementById('quantity');
            const currentValue = parseInt(quantityInput.value);
            const minValue = parseInt(quantityInput.min);
            
            if (currentValue > minValue) {
                quantityInput.value = currentValue - 1;
                updateTotalPrice();
            }
        });
        
        document.getElementById('increaseQty').addEventListener('click', function() {
            const quantityInput = document.getElementById('quantity');
            const currentValue = parseInt(quantityInput.value);
            
            quantityInput.value = currentValue + 1;
            updateTotalPrice();
        });
        
        document.getElementById('quantity').addEventListener('input', function() {
            const value = parseInt(this.value);
            const minValue = parseInt(this.min);
            
            if (value < minValue) {
                this.value = minValue;
            }
            
            updateTotalPrice();
        });
        
        // Submit request
        document.getElementById('submitRequestBtn').addEventListener('click', function() {
            const justification = document.getElementById('justification').value;
            const urgency = document.getElementById('urgency').value;
            const requirements = document.getElementById('requirements').value;
            const quantity = parseInt(document.getElementById('quantity').value);
            const startDate = document.getElementById('startDate').value;
            
            if (!justification.trim()) {
                alert('Please provide a business justification for this request.');
                document.getElementById('justification').focus();
                return;
            }
            
            const totalCost = currentBasePrice * quantity;
            const setupFeeText = currentSetupFee > 0 ? ' + £' + currentSetupFee.toFixed(2) + ' setup fee' : '';
            
                        // Prepare the ticket subject and description
            const subject = 'WHMCS Service Request: ' + currentServiceName + ' (' + quantity + ' units)';
            let description = 'WHMCS SERVICE REQUEST DETAILS:\n';
            description += '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n';
            description += 'Service Type: ' + currentServiceType.charAt(0).toUpperCase() + currentServiceType.slice(1) + '\n';
            description += 'Service Name: ' + currentServiceName + '\n';
            description += 'WHMCS Product ID: ' + currentServiceId + '\n';
            description += 'Requested Quantity: ' + quantity + '\n';
            description += 'Priority Level: ' + urgency + '\n';
            if (startDate) {
                description += 'Preferred Start Date: ' + startDate + '\n';
            }
            description += '\nPRICING BREAKDOWN:\n';
            description += '• Unit Price: £' + currentBasePrice.toFixed(2) + ' per month\n';
            description += '• Total Monthly Cost: £' + totalCost.toFixed(2) + setupFeeText + '\n\n';
            description += 'BUSINESS JUSTIFICATION:\n' + justification;

            if (requirements.trim()) {
                description += '\n\nADDITIONAL REQUIREMENTS:\n' + requirements;
            }

            description += '\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n';
            description += 'This is an automated service request generated from the WHMCS Service Catalog.\n';
            description += 'Please review and process this request according to your approval workflow.\n';
            description += 'Product can be provisioned directly in WHMCS using Product ID: ' + currentServiceId + '\n';
            description += 'Generated: 2025-08-04 20:30:24 UTC by jackbetherxi';
            
            // Redirect to raise ticket with pre-filled data
            const params = new URLSearchParams({
                'subject': subject,
                'description': description,
                'priority': urgency,
                'service_request': '1',
                'whmcs_product_id': currentServiceId,
                'service_quantity': quantity
            });
            
            window.location.href = '/members/raise-ticket.php?' + params.toString();
        });
        
        // Add entry animations to initial elements
        const cards = document.querySelectorAll('.category-card, .dashboard-stat-box');
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                card.style.transition = 'all 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 50);
        });
        
        console.log('✅ WHMCS Service Catalog fully initialized for user jackbetherxi');
        
        // Display connection status
        <?php if ($whmcs_connected): ?>
        console.log('✅ WHMCS API connection successful - <?= count($categories) ?> categories loaded');
        updateSearchStatus('WHMCS connected - <?= empty($categories) ? "Use search to find products" : "Select a category or search to see products" ?>');
        <?php else: ?>
        console.log('❌ WHMCS API connection failed - Check configuration');
        updateSearchStatus('WHMCS API unavailable - Check connection');
        <?php endif; ?>
    });

    // Add custom styles for fade-in animation
    const style = document.createElement('style');
    style.textContent = `
        .fade-in {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.6s ease;
        }
        
        .fade-in.visible {
            opacity: 1;
            transform: translateY(0);
        }
        
        .clear-selection {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255, 255, 255, 0.9);
            border: none;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: #666;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .category-card.selected .clear-selection {
            display: flex;
        }
        
        .clear-selection:hover {
            background: #ff4757;
            color: white;
            transform: scale(1.1);
        }
        
        /* Modal enhancements */
        .quantity-selector {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .quantity-input {
            width: 80px;
            text-align: center;
        }
        
        .quantity-btn {
            width: 35px;
            height: 35px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .quantity-btn:hover {
            background: #f3f4f6;
            border-color: #9ca3af;
        }
        
        .quantity-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .total-price {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1rem 0;
            text-align: center;
        }
        
        .total-price-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
        }
        
        .total-price-breakdown {
            font-size: 0.875rem;
            color: #64748B;
            margin-top: 0.25rem;
        }
        
        .breadcrumb-bar {
            background: white;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }
        
        .breadcrumb-bar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .breadcrumb {
            background: none;
            padding: 0;
            margin: 0;
        }
        
        .breadcrumb-item a {
            color: #667eea;
            text-decoration: none;
        }
        
        .breadcrumb-item a:hover {
            text-decoration: underline;
        }
    `;
    document.head.appendChild(style);
})();
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>

</body>
</html>