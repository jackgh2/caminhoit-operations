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

// Check if user has access (supported_user or account_manager)
if (!in_array($user['role'], ['administrator', 'account_manager'])) {
    header('Location: /members/dashboard.php');
    exit;
}

$user_id = $user['id'];

// Function to get system config value (same as create-order.php)
function getSystemConfig($pdo, $key, $default = null) {
    try {
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        
        if ($result !== false) {
            // Handle boolean values
            if ($result === 'true') return true;
            if ($result === 'false') return false;
            
            // Handle numeric values
            if (is_numeric($result)) {
                return strpos($result, '.') !== false ? (float)$result : (int)$result;
            }
            
            return $result;
        }
        
        return $default;
    } catch (Exception $e) {
        error_log("Failed to get system config for key '$key': " . $e->getMessage());
        return $default;
    }
}

// Get company's default currency with proper exchange rate conversion
try {
    // First, try to get the user's company currency settings
    $stmt = $pdo->prepare("
        SELECT c.preferred_currency, c.currency_override 
        FROM companies c 
        JOIN users u ON u.company_id = c.id 
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $company_currency = $stmt->fetch();
    
// Get supported currencies from ConfigManager
$supportedCurrencies = [];
if (class_exists('ConfigManager')) {
    $supportedCurrencies = ConfigManager::getSupportedCurrencies();
} else {
    // Fallback currencies
    $supportedCurrencies = [
        'GBP' => ['symbol' => '£', 'name' => 'British Pound'],
        'EUR' => ['symbol' => '€', 'name' => 'Euro'],
        'USD' => ['symbol' => '$', 'name' => 'US Dollar'],
        'CAD' => ['symbol' => 'C$', 'name' => 'Canadian Dollar'],
        'AUD' => ['symbol' => 'A$', 'name' => 'Australian Dollar']
    ];
}
    
    // Get default currency from system config (same as create-order.php)
    $defaultCurrency = getSystemConfig($pdo, 'business.default_currency', 'GBP');
    
    // Get default currency from system config (same as create-order.php)
    $defaultCurrency = getSystemConfig($pdo, 'business.default_currency', 'GBP');
    
    // Get live exchange rates from ConfigManager instead of hardcoded values
    $exchangeRates = [];
    if (class_exists('ConfigManager')) {
        $exchangeRates = ConfigManager::getExchangeRates();
    } else {
        // Fallback to hardcoded rates if ConfigManager not available
        $exchangeRates = [
            'GBP' => 1.0,
            'USD' => 1.27,
            'EUR' => 1.17,
            'CAD' => 1.71,
            'AUD' => 1.90
        ];
    }
    
    // Ensure base currency is always 1.0
    $exchangeRates[$defaultCurrency] = 1.0;
    
    // Determine effective currency
    if ($company_currency && $company_currency['currency_override'] && $company_currency['preferred_currency']) {
        // Company has override enabled and preferred currency set
        $currency_code = $company_currency['preferred_currency'];
    } else {
        // Use system default
        $currency_code = $defaultCurrency;
    }
    
    // Get currency symbol and exchange rate
    $currency_symbol = $supportedCurrencies[$currency_code]['symbol'] ?? '£';
    $exchange_rate = $exchangeRates[$currency_code] ?? 1.0;
    
    error_log("Service Catalog Currency Info:");
    error_log("- User ID: $user_id");
    error_log("- Company Currency: " . ($company_currency['preferred_currency'] ?? 'none'));
    error_log("- Currency Override: " . ($company_currency['currency_override'] ?? 'false'));
    error_log("- Effective Currency: $currency_code");
    error_log("- Exchange Rate: $exchange_rate");
    
} catch (Exception $e) {
    // Fallback if there's any error
    error_log("Currency lookup error: " . $e->getMessage());
    $currency_code = 'GBP';
    $currency_symbol = '£';
    $exchange_rate = 1.0;
    $defaultCurrency = 'GBP';
    $exchangeRates = ['GBP' => 1.0];
}

// Handle AJAX requests for live search and category loading
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'search_products' || $_GET['ajax'] === 'load_category') {
        $category_id = (int)($_GET['category'] ?? 0);
        $search = $_GET['search'] ?? '';
        $price_filter = $_GET['price'] ?? '';

        // Build query for products
        $where_conditions = ["p.is_active = 1", "c.is_active = 1"];
        $params = [];

        if ($category_id) {
            $where_conditions[] = "p.category_id = ?";
            $params[] = $category_id;
        }

        if ($search) {
            $where_conditions[] = "(p.name LIKE ? OR p.description LIKE ? OR p.short_description LIKE ?)";
            $search_param = '%' . $search . '%';
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
        }

        if ($price_filter) {
            // Apply price filter to converted prices
            switch ($price_filter) {
                case 'free':
                    $where_conditions[] = "p.base_price = 0";
                    break;
                case 'low':
                    $max_low = 50 / $exchange_rate; // Convert filter back to GBP for database query
                    $where_conditions[] = "p.base_price > 0 AND p.base_price <= ?";
                    $params[] = $max_low;
                    break;
                case 'medium':
                    $min_medium = 50 / $exchange_rate;
                    $max_medium = 200 / $exchange_rate;
                    $where_conditions[] = "p.base_price > ? AND p.base_price <= ?";
                    $params[] = $min_medium;
                    $params[] = $max_medium;
                    break;
                case 'high':
                    $min_high = 200 / $exchange_rate;
                    $where_conditions[] = "p.base_price > ?";
                    $params[] = $min_high;
                    break;
            }
        }

        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

        // Get products
        $stmt = $pdo->prepare("
            SELECT p.*, c.name as category_name, c.color as category_color, c.icon as category_icon
            FROM products p 
            JOIN service_categories c ON p.category_id = c.id 
            $where_clause
            ORDER BY p.is_featured DESC, c.sort_order ASC, p.sort_order ASC, p.name ASC
        ");
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        // Get user's current assignments
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
        $assignment_quantities = [];
        foreach ($user_assignments as $assignment) {
            if ($assignment['product_id']) {
                $assignment_quantities['product_' . $assignment['product_id']] = $assignment['total_assigned'];
            }
        }

        // Get current category info if needed
        $current_category = null;
        if ($category_id) {
            $stmt = $pdo->prepare("SELECT * FROM service_categories WHERE id = ? AND is_active = 1");
            $stmt->execute([$category_id]);
            $current_category = $stmt->fetch();
        }

        // Get bundles if needed (for category view or search)
        $bundles = [];
        $bundle_products = [];
        if ($category_id || $search) {
            $stmt = $pdo->query("SELECT * FROM service_bundles WHERE is_active = 1 ORDER BY is_featured DESC, created_at DESC");
            $bundles = $stmt->fetchAll();

            // Get bundle products for display
            foreach ($bundles as $bundle) {
                $stmt = $pdo->prepare("
                    SELECT bp.*, p.name as product_name, p.unit_type, c.name as category_name 
                    FROM bundle_products bp 
                    JOIN products p ON bp.product_id = p.id 
                    JOIN service_categories c ON p.category_id = c.id 
                    WHERE bp.bundle_id = ? 
                    ORDER BY bp.sort_order ASC
                ");
                $stmt->execute([$bundle['id']]);
                $bundle_products[$bundle['id']] = $stmt->fetchAll();
            }
        }

        echo json_encode([
            'success' => true,
            'products' => $products,
            'bundles' => $bundles,
            'bundle_products' => $bundle_products,
            'assigned_product_ids' => $assigned_product_ids,
            'assignment_quantities' => $assignment_quantities,
            'current_category' => $current_category,
            'search_term' => $search,
            'currency_symbol' => $currency_symbol,
            'currency_code' => $currency_code,
            'exchange_rate' => $exchange_rate
        ]);
        exit;
    }
}

// Get all active categories
$stmt = $pdo->query("SELECT * FROM service_categories WHERE is_active = 1 ORDER BY sort_order ASC");
$categories = $stmt->fetchAll();

// Get user's current assignments for stats
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

// Helper functions with currency conversion
function formatPrice($price, $currency_symbol = '£', $exchange_rate = 1.0) {
    if ($price == 0) return 'Free';
    $converted_price = $price * $exchange_rate;
    return $currency_symbol . number_format($converted_price, 2);
}

function convertPrice($price, $exchange_rate = 1.0) {
    return $price * $exchange_rate;
}

function getPriceBadgeClass($price, $exchange_rate = 1.0) {
    $converted_price = $price * $exchange_rate;
    if ($converted_price == 0) return 'bg-success';
    if ($converted_price <= 50) return 'bg-info';
    if ($converted_price <= 200) return 'bg-warning';
    return 'bg-primary';
}

$page_title = "Service Catalog | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>
    <style>
        /* Hero Section Styles */
        .service-catalog-hero-content {
            text-align: center;
            padding: 4rem 0;
            position: relative;
            z-index: 2;
        }

        .service-catalog-hero-title {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .service-catalog-hero-subtitle {
            font-size: 1.25rem;
            margin-bottom: 2rem;
            opacity: 0.9;
        }

        .hero-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-bottom: 1rem;
        }

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

        /* Modal Enhancements */
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

        .fade-in {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.6s ease;
        }

        .fade-in.visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* Clear button for selected category */
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

        /* Currency indicator */
        .currency-indicator {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid rgba(102, 126, 234, 0.2);
        }

        /* Currency conversion badge */
        .currency-converted {
            position: absolute;
            bottom: 0.75rem;
            left: 0.75rem;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
            z-index: 5;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
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

        /* Cart Notification Styles */
        .cart-notification {
            position: fixed;
            top: 80px;
            right: -400px;
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            z-index: 10000;
            transition: right 0.3s ease-in-out;
            max-width: 350px;
            display: flex;
            align-items: center;
        }

        .cart-notification.show {
            right: 20px;
        }

        .cart-notification-success {
            border-left: 4px solid #10b981;
            color: #065f46;
        }

        .cart-notification-error {
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }

        /* DARK MODE STYLES */
        :root.dark,
        :root.dark body {
            background: #0f172a !important;
            color: #e2e8f0 !important;
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

        :root.dark .service-catalog-hero-title,
        :root.dark .service-catalog-hero-subtitle {
            color: white !important;
            position: relative;
            z-index: 2;
        }
        html.dark .template-card { background: #1e293b; border-color: #334155; }
        html.dark .template-card-header { background: #0f172a; border-color: #334155; }
        html.dark .template-card-header h2, html.dark .template-card-header h5 { color: #a78bfa; }
        html.dark .template-card-header p { color: #94a3b8; }
        html.dark .category-card { background: #1e293b; border-color: #334155; }
        html.dark .category-card:hover,html.dark .category-card.selected { border-color: #8b5cf6; }
        html.dark .category-card h5 { color: #f1f5f9; }
        html.dark .product-card { background: #1e293b; border-color: #334155; color: #e2e8f0; }
        html.dark .product-card:hover { border-color: #8b5cf6; }
        html.dark .product-card h5 { color: #f1f5f9; }
        html.dark .product-card .text-muted { color: #94a3b8 !important; }
        html.dark .form-control, html.dark .form-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
        html.dark .form-control:focus, html.dark .form-select:focus { background: #0f172a; border-color: #8b5cf6; }
        html.dark small { color: #94a3b8; }
        html.dark .breadcrumb-bar { background: #1e293b; border-color: #334155; }
        html.dark .breadcrumb-item { color: #94a3b8; }
        html.dark .breadcrumb-item.active { color: #e2e8f0; }
        html.dark .breadcrumb-item a { color: #a78bfa; }
        html.dark .filter-section { background: #1e293b; border-color: #334155; }
        html.dark .filter-section h3 { color: #a78bfa; }
        html.dark .view-toggle .btn { background: #0f172a; border-color: #334155; color: #e2e8f0; }
        html.dark .view-toggle .btn:hover { background: rgba(139, 92, 246, 0.1); border-color: #8b5cf6; }
    </style>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="service-catalog-hero-content">
            <h1 class="service-catalog-hero-title text-white">
                <i class="bi bi-grid-3x3-gap me-3"></i>
                Service Catalog
            </h1>
            <p class="service-catalog-hero-subtitle text-white">
                Browse our available products and services for your organization
            </p>
            <div class="hero-actions">
                <a href="#catalog-overview" class="btn c-btn-primary">
                    <i class="bi bi-arrow-down me-1"></i>
                    Browse Services
                </a>
                <a href="/members/raise-ticket.php" class="btn c-btn-ghost">
                    <i class="bi bi-headset me-1"></i>
                    Get Support
                </a>
            </div>
            <div class="mt-3">
                <span class="badge bg-light text-primary px-3 py-2 me-2">
                    <i class="bi bi-person-badge me-1"></i><?= ucfirst(str_replace('_', ' ', $user['role'])) ?>
                </span>
                <span class="badge bg-light text-primary px-3 py-2">
                    <i class="bi bi-cash-coin me-1"></i><?= htmlspecialchars($currency_code) ?>
                    <?php if ($exchange_rate != 1.0): ?>
                        <small>(1:<?= number_format($exchange_rate, 3) ?>)</small>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>
</header>

<div class="container py-5 overlap-cards" id="catalog-overview">
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
                    <option value="low"><?= $currency_symbol ?>1 - <?= $currency_symbol ?>50</option>
                    <option value="medium"><?= $currency_symbol ?>51 - <?= $currency_symbol ?>200</option>
                    <option value="high"><?= $currency_symbol ?>200+</option>
                </select>
            </div>
            <div class="col-md-5">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" onclick="clearSearch()">
                        <i class="bi bi-arrow-clockwise me-1"></i>Clear Search
                    </button>
                    <span class="text-muted small align-self-center" id="searchStatus">
                        Select a category or search to see products
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Categories Section - Always Visible -->
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
                            <div class="total-price-value" id="totalPrice"><?= $currency_symbol ?>0.00</div>
                            <div class="total-price-breakdown" id="priceBreakdown">per month</div>
                            <div class="total-price-breakdown" id="setupFeeDisplay" style="display: none;">
                                + <?= $currency_symbol ?><span id="setupFeeAmount">0.00</span> setup fee
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

<script>
// Service Catalog with Currency Conversion Support
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

    // Get currency from PHP with exchange rate
    const currencySymbol = '<?= $currency_symbol ?>';
    const currencyCode = '<?= $currency_code ?>';
    const exchangeRate = <?= $exchange_rate ?>;
    const defaultCurrency = '<?= $defaultCurrency ?>';

    console.log('Currency settings:', {
        currencySymbol,
        currencyCode,
        exchangeRate,
        defaultCurrency,
        conversionActive: exchangeRate !== 1.0
    });

    // Utility functions with proper conversion
    function formatPrice(price) {
        if (price == 0) return 'Free';
        const convertedPrice = parseFloat(price) * exchangeRate;
        return currencySymbol + convertedPrice.toFixed(2);
    }

    function convertPrice(price) {
        return parseFloat(price) * exchangeRate;
    }

    function getPriceBadgeClass(price) {
        const convertedPrice = parseFloat(price) * exchangeRate;
        if (convertedPrice == 0) return 'bg-success';
        if (convertedPrice <= 50) return 'bg-info';
        if (convertedPrice <= 200) return 'bg-warning';
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
                    }
                })
                .catch(error => {
                    console.error('Search error:', error);
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
                }
            })
            .catch(error => {
                console.error('Category loading error:', error);
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
        updateSearchStatus('Showing ' + data.products.length + ' products' + (data.bundles.length > 0 ? ' and ' + data.bundles.length + ' bundles' : ''));

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
            html += '<h2 class="mb-1"><i class="bi bi-search me-2"></i>Search Results</h2><p class="text-muted mb-0">Products matching "' + searchTerm + '"</p>';
        } else {
            html += '<h2 class="mb-1"><i class="bi bi-box me-2"></i>Products</h2><p class="text-muted mb-0">Available products and services</p>';
        }

        html += '</div><div class="template-card-body">';

        if (!products || products.length === 0) {
            html += '<div class="text-center py-5"><i class="bi bi-search text-muted" style="font-size: 4rem; opacity: 0.3;"></i><h3 class="mt-3 text-muted">No Products Found</h3>';
            if (searchTerm) {
                html += '<p class="text-muted">Try adjusting your search criteria or browse other categories.</p>';
            } else {
                html += '<p class="text-muted">This category doesn\'t have any products available yet.</p>';
            }
            html += '</div>';
        } else {
            html += '<div class="products-grid">';
            
            products.forEach(product => {
                const isAssigned = assignedProductIds && assignedProductIds.includes(product.id);
                const assignedQuantity = assignmentQuantities ? (assignmentQuantities['product_' + product.id] || 1) : 1;
                const convertedPrice = convertPrice(product.base_price);
                const convertedSetupFee = convertPrice(product.setup_fee || 0);
                
                html += '<div class="product-card ' + (product.is_featured ? 'featured' : '') + ' ' + (isAssigned ? 'assigned' : '') + '">';
                
                if (product.is_featured && !isAssigned) {
                    html += '<div class="featured-badge"><i class="bi bi-star-fill me-1"></i>Featured</div>';
                }
                
                if (isAssigned) {
                    html += '<div class="assigned-badge"><i class="bi bi-check-circle-fill"></i>Assigned (' + assignedQuantity + ')</div>';
                }

                // Add conversion badge if currency is different
                if (exchangeRate !== 1.0) {
                    html += '<div class="currency-converted"><i class="bi bi-arrow-repeat me-1"></i>Converted</div>';
                }

                html += '<div class="product-header"><div class="flex-grow-1"><h4 class="product-title">' + product.name + '</h4><div class="product-meta">';
                html += '<span class="badge bg-secondary">' + product.category_name + '</span>';
                html += '<span class="badge ' + getPriceBadgeClass(product.base_price) + '">' + formatPrice(product.base_price);
                if (product.base_price > 0) {
                    html += ' / ' + product.unit_type.replace('_', ' ').charAt(0).toUpperCase() + product.unit_type.replace('_', ' ').slice(1);
                }
                html += '</span>';
                html += '<span class="badge bg-info">' + product.billing_cycle.charAt(0).toUpperCase() + product.billing_cycle.slice(1) + '</span>';
                html += '</div></div><div class="product-price">' + formatPrice(product.base_price);
                if (product.base_price > 0) {
                    html += '<div class="product-price-unit">per ' + product.unit_type.replace('_', ' ') + '</div>';
                }
                html += '</div></div>';

                if (isAssigned) {
                    html += '<div class="assigned-info"><i class="bi bi-info-circle me-1"></i>You currently have <strong>' + assignedQuantity + '</strong> ' + product.unit_type.replace('_', ' ') + (assignedQuantity > 1 ? 's' : '') + ' assigned.</div>';
                }

                html += '<p class="product-description">' + (product.short_description || product.description) + '</p>';

                if ((product.setup_fee || 0) > 0) {
                    html += '<div class="mb-2"><small class="text-muted"><i class="bi bi-gear me-1"></i>One-time setup fee: ' + currencySymbol + convertedSetupFee.toFixed(2) + '</small></div>';
                }

                if (product.requires_setup) {
                    html += '<div class="mb-2"><small class="text-info"><i class="bi bi-tools me-1"></i>Professional setup included</small></div>';
                }

                html += '<div class="product-actions"><button class="btn btn-primary w-100" onclick="requestService(\'product\', ' + product.id + ', \'' + product.name + '\', ' + convertedPrice + ', ' + convertedSetupFee + ', ' + (product.minimum_quantity || 1) + ')"><i class="bi bi-cart-plus me-2"></i>Order Service</button></div>';
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
                    countElement.textContent = '0 Products';
                });
        <?php endforeach; ?>
    }

        // Add to Cart Function (replaces service request modal)
    window.requestService = function(type, id, name, basePrice, setupFee, minQuantity) {
        basePrice = basePrice || 0;
        setupFee = setupFee || 0;
        minQuantity = minQuantity || 1;

        addToCart(type, id, name, basePrice, minQuantity, setupFee);
    };

    function addToCart(type, id, name, price, quantity, setupFee) {
        quantity = quantity || 1;
        setupFee = setupFee || 0;

        // Show loading state
        const buttonText = event.target.innerHTML;
        event.target.innerHTML = '<i class="bi bi-hourglass-split"></i> Adding...';
        event.target.disabled = true;

        fetch('/members/add-to-cart.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                'type': type,
                'id': id,
                'name': name,
                'price': price,
                'quantity': quantity,
                'billing_cycle': 'monthly',
                'setup_fee': setupFee
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success notification
                showNotification('Added to cart: ' + name, 'success');

                // Update cart count
                updateCartCount();

                // Reset button
                event.target.innerHTML = '<i class="bi bi-check-circle me-2"></i>Added!';
                event.target.classList.remove('btn-primary');
                event.target.classList.add('btn-success');

                setTimeout(() => {
                    event.target.innerHTML = buttonText;
                    event.target.classList.remove('btn-success');
                    event.target.classList.add('btn-primary');
                    event.target.disabled = false;
                }, 2000);
            } else {
                showNotification('Error: ' + (data.error || 'Failed to add to cart'), 'error');
                event.target.innerHTML = buttonText;
                event.target.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Error adding to cart', 'error');
            event.target.innerHTML = buttonText;
            event.target.disabled = false;
        });
    }

    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = 'cart-notification cart-notification-' + type;
        notification.innerHTML = `
            <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill'} me-2"></i>
            ${message}
        `;
        document.body.appendChild(notification);

        setTimeout(() => notification.classList.add('show'), 10);

        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }

    function updateCartCount() {
        // Update cart count badge if it exists
        fetch('/members/cart.php?ajax=count')
            .then(response => response.json())
            .then(data => {
                const cartBadge = document.getElementById('cartCount');
                if (cartBadge && data.count !== undefined) {
                    cartBadge.textContent = data.count;
                    if (data.count > 0) {
                        cartBadge.style.display = 'inline-block';
                    }
                }
            })
            .catch(error => console.error('Error updating cart count:', error));
    }

    function updateTotalPrice() {
        const quantity = parseInt(document.getElementById('quantity').value) || 1;
        const total = currentBasePrice * quantity;
        
        document.getElementById('totalPrice').textContent = currencySymbol + total.toFixed(2);
        
        if (currentBasePrice === 0) {
            document.getElementById('priceBreakdown').textContent = 'Free service';
        } else {
            document.getElementById('priceBreakdown').textContent = quantity + ' × ' + currencySymbol + currentBasePrice.toFixed(2) + ' per month';
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
        console.log('🚀 Service Catalog Loading - Date: 2025-08-28 12:34:49, User: detouredeuropeoutlook, Currency: ' + currencyCode + ', Exchange Rate: ' + exchangeRate);
        
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
            const setupFeeText = currentSetupFee > 0 ? ' + ' + currencySymbol + currentSetupFee.toFixed(2) + ' setup fee' : '';
            
            // Prepare the ticket subject and description
            const subject = 'Service Request: ' + currentServiceName + ' (' + quantity + ' units)';
            let description = 'SERVICE REQUEST DETAILS:\n';
            description += '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n';
            description += 'Service Type: ' + currentServiceType.charAt(0).toUpperCase() + currentServiceType.slice(1) + '\n';
            description += 'Service Name: ' + currentServiceName + '\n';
            description += 'Requested Quantity: ' + quantity + '\n';
            description += 'Priority Level: ' + urgency + '\n';
            if (startDate) {
                description += 'Preferred Start Date: ' + startDate + '\n';
            }
            description += 'Currency: ' + currencyCode + '\n';
            if (exchangeRate !== 1.0) {
                description += 'Exchange Rate: 1 <?= $defaultCurrency ?> = ' + exchangeRate + ' ' + currencyCode + '\n';
            }
            description += '\nPRICING BREAKDOWN:\n';
            description += '• Unit Price: ' + currencySymbol + currentBasePrice.toFixed(2) + ' per month\n';
            description += '• Total Monthly Cost: ' + currencySymbol + totalCost.toFixed(2) + setupFeeText + '\n\n';
            description += 'BUSINESS JUSTIFICATION:\n' + justification;

            if (requirements.trim()) {
                description += '\n\nADDITIONAL REQUIREMENTS:\n' + requirements;
            }

            description += '\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n';
            description += 'This is an automated service request generated from the Service Catalog.\n';
            description += 'Please review and process this request according to your approval workflow.\n';
            description += 'Generated: 2025-08-28 12:34:49 UTC by detouredeuropeoutlook';
            
            // Redirect to raise ticket with pre-filled data
            const params = new URLSearchParams({
                'subject': subject,
                'description': description,
                'priority': urgency,
                'service_request': '1',
                'service_quantity': quantity,
                'service_currency': currencyCode
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
        
        console.log('✅ Service Catalog with dynamic currency conversion fully initialized  - Currency: ' + currencyCode + ' (Rate: ' + exchangeRate + ')');
    });
})();
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
