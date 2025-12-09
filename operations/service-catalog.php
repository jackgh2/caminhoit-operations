<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

// Access control (Administrator only)
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'administrator') {
    header('Location: /login.php');
    exit;
}

// Handle Add Category
if (isset($_POST['add_category'])) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $icon = trim($_POST['icon']);
    $color = trim($_POST['color']);
    $sort_order = (int)$_POST['sort_order'];

    try {
        $stmt = $pdo->prepare("INSERT INTO service_categories (name, description, icon, color, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $description, $icon, $color, $sort_order, $_SESSION['user']['id']]);
        header('Location: service-catalog.php?success=category_added');
        exit;
    } catch (PDOException $e) {
        $error = "Error adding category: " . $e->getMessage();
    }
}

// Handle Edit Category
if (isset($_POST['edit_category'])) {
    $category_id = (int)$_POST['category_id'];
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $icon = trim($_POST['icon']);
    $color = trim($_POST['color']);
    $sort_order = (int)$_POST['sort_order'];

    try {
        $stmt = $pdo->prepare("UPDATE service_categories SET name = ?, description = ?, icon = ?, color = ?, sort_order = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$name, $description, $icon, $color, $sort_order, $category_id]);
        header('Location: service-catalog.php?success=category_updated');
        exit;
    } catch (PDOException $e) {
        $error = "Error updating category: " . $e->getMessage();
    }
}

// Handle Toggle Category Status
if (isset($_GET['toggle_category'])) {
    $category_id = (int)$_GET['toggle_category'];
    
    try {
        $stmt = $pdo->prepare("SELECT is_active FROM service_categories WHERE id = ?");
        $stmt->execute([$category_id]);
        $current_status = $stmt->fetchColumn();

        if ($current_status !== false) {
            $new_status = $current_status ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE service_categories SET is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $category_id]);
        }
        
        header('Location: service-catalog.php?success=category_status_changed');
        exit;
    } catch (PDOException $e) {
        $error = "Error changing category status: " . $e->getMessage();
    }
}

// Handle Delete Category
if (isset($_GET['delete_category'])) {
    $category_id = (int)$_GET['delete_category'];
    
    try {
        // Check if category has products
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
        $stmt->execute([$category_id]);
        $product_count = $stmt->fetchColumn();
        
        if ($product_count > 0) {
            $error = "Cannot delete category with existing products. Please move or delete products first.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM service_categories WHERE id = ?");
            $stmt->execute([$category_id]);
            header('Location: service-catalog.php?success=category_deleted');
            exit;
        }
    } catch (PDOException $e) {
        $error = "Error deleting category: " . $e->getMessage();
    }
}

// Handle Add Product
if (isset($_POST['add_product'])) {
    $category_id = (int)$_POST['category_id'];
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $short_description = trim($_POST['short_description']);
    $product_code = trim($_POST['product_code']) ?: strtoupper(substr(str_replace(' ', '', $name), 0, 10));
    $unit_type = $_POST['unit_type'];
    $base_price = (float)$_POST['base_price'];
    $setup_fee = (float)$_POST['setup_fee'];
    $minimum_quantity = (int)$_POST['minimum_quantity'];
    $billing_cycle = $_POST['billing_cycle'];
    $requires_setup = isset($_POST['requires_setup']) ? 1 : 0;
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;

    try {
        $stmt = $pdo->prepare("INSERT INTO products (category_id, name, description, short_description, product_code, unit_type, base_price, setup_fee, minimum_quantity, billing_cycle, requires_setup, is_featured, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$category_id, $name, $description, $short_description, $product_code, $unit_type, $base_price, $setup_fee, $minimum_quantity, $billing_cycle, $requires_setup, $is_featured, $_SESSION['user']['id']]);
        header('Location: service-catalog.php?success=product_added');
        exit;
    } catch (PDOException $e) {
        $error = "Error adding product: " . $e->getMessage();
    }
}

// Handle Edit Product
if (isset($_POST['edit_product'])) {
    $product_id = (int)$_POST['product_id'];
    $category_id = (int)$_POST['category_id'];
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $short_description = trim($_POST['short_description']);
    $product_code = trim($_POST['product_code']);
    $unit_type = $_POST['unit_type'];
    $base_price = (float)$_POST['base_price'];
    $setup_fee = (float)$_POST['setup_fee'];
    $minimum_quantity = (int)$_POST['minimum_quantity'];
    $billing_cycle = $_POST['billing_cycle'];
    $requires_setup = isset($_POST['requires_setup']) ? 1 : 0;
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;

    try {
        $stmt = $pdo->prepare("UPDATE products SET category_id = ?, name = ?, description = ?, short_description = ?, product_code = ?, unit_type = ?, base_price = ?, setup_fee = ?, minimum_quantity = ?, billing_cycle = ?, requires_setup = ?, is_featured = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$category_id, $name, $description, $short_description, $product_code, $unit_type, $base_price, $setup_fee, $minimum_quantity, $billing_cycle, $requires_setup, $is_featured, $product_id]);
        header('Location: service-catalog.php?success=product_updated');
        exit;
    } catch (PDOException $e) {
        $error = "Error updating product: " . $e->getMessage();
    }
}

// Handle Toggle Product Status
if (isset($_GET['toggle_product'])) {
    $product_id = (int)$_GET['toggle_product'];
    
    try {
        $stmt = $pdo->prepare("SELECT is_active FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $current_status = $stmt->fetchColumn();

        if ($current_status !== false) {
            $new_status = $current_status ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE products SET is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $product_id]);
        }
        
        header('Location: service-catalog.php?success=product_status_changed');
        exit;
    } catch (PDOException $e) {
        $error = "Error changing product status: " . $e->getMessage();
    }
}

// Handle Delete Product
if (isset($_GET['delete_product'])) {
    $product_id = (int)$_GET['delete_product'];
    
    try {
        // Check if product has active subscriptions
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM client_subscriptions WHERE product_id = ? AND status = 'active'");
        $stmt->execute([$product_id]);
        $subscription_count = $stmt->fetchColumn();
        
        if ($subscription_count > 0) {
            $error = "Cannot delete product with active subscriptions. Please cancel subscriptions first.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
            header('Location: service-catalog.php?success=product_deleted');
            exit;
        }
    } catch (PDOException $e) {
        $error = "Error deleting product: " . $e->getMessage();
    }
}

// Handle Add Bundle
if (isset($_POST['add_bundle'])) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $short_description = trim($_POST['short_description']);
    $bundle_code = trim($_POST['bundle_code']) ?: 'BUNDLE-' . strtoupper(substr(str_replace(' ', '', $name), 0, 6));
    $bundle_price = (float)$_POST['bundle_price'];
    $discount_percentage = (float)$_POST['discount_percentage'];
    $billing_cycle = $_POST['billing_cycle'];
    $target_audience = trim($_POST['target_audience']);
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;

    try {
        $stmt = $pdo->prepare("INSERT INTO service_bundles (name, description, short_description, bundle_code, bundle_price, discount_percentage, billing_cycle, target_audience, is_featured, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $description, $short_description, $bundle_code, $bundle_price, $discount_percentage, $billing_cycle, $target_audience, $is_featured, $_SESSION['user']['id']]);
        header('Location: service-catalog.php?success=bundle_added');
        exit;
    } catch (PDOException $e) {
        $error = "Error adding bundle: " . $e->getMessage();
    }
}

// Handle Edit Bundle
if (isset($_POST['edit_bundle'])) {
    $bundle_id = (int)$_POST['bundle_id'];
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $short_description = trim($_POST['short_description']);
    $bundle_code = trim($_POST['bundle_code']);
    $bundle_price = (float)$_POST['bundle_price'];
    $discount_percentage = (float)$_POST['discount_percentage'];
    $billing_cycle = $_POST['billing_cycle'];
    $target_audience = trim($_POST['target_audience']);
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;

    try {
        $stmt = $pdo->prepare("UPDATE service_bundles SET name = ?, description = ?, short_description = ?, bundle_code = ?, bundle_price = ?, discount_percentage = ?, billing_cycle = ?, target_audience = ?, is_featured = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$name, $description, $short_description, $bundle_code, $bundle_price, $discount_percentage, $billing_cycle, $target_audience, $is_featured, $bundle_id]);
        header('Location: service-catalog.php?success=bundle_updated');
        exit;
    } catch (PDOException $e) {
        $error = "Error updating bundle: " . $e->getMessage();
    }
}

// Handle Toggle Bundle Status
if (isset($_GET['toggle_bundle'])) {
    $bundle_id = (int)$_GET['toggle_bundle'];
    
    try {
        $stmt = $pdo->prepare("SELECT is_active FROM service_bundles WHERE id = ?");
        $stmt->execute([$bundle_id]);
        $current_status = $stmt->fetchColumn();

        if ($current_status !== false) {
            $new_status = $current_status ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE service_bundles SET is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $bundle_id]);
        }
        
        header('Location: service-catalog.php?success=bundle_status_changed');
        exit;
    } catch (PDOException $e) {
        $error = "Error changing bundle status: " . $e->getMessage();
    }
}

// Handle Delete Bundle
if (isset($_GET['delete_bundle'])) {
    $bundle_id = (int)$_GET['delete_bundle'];
    
    try {
        // Check if bundle has active subscriptions
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM client_subscriptions WHERE bundle_id = ? AND status = 'active'");
        $stmt->execute([$bundle_id]);
        $subscription_count = $stmt->fetchColumn();
        
        if ($subscription_count > 0) {
            $error = "Cannot delete bundle with active subscriptions. Please cancel subscriptions first.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM service_bundles WHERE id = ?");
            $stmt->execute([$bundle_id]);
            header('Location: service-catalog.php?success=bundle_deleted');
            exit;
        }
    } catch (PDOException $e) {
        $error = "Error deleting bundle: " . $e->getMessage();
    }
}

// Handle Add Product to Bundle
if (isset($_POST['add_product_to_bundle'])) {
    $bundle_id = (int)$_POST['bundle_id'];
    $product_id = (int)$_POST['product_id'];
    $quantity = (int)$_POST['quantity'];
    $custom_price = !empty($_POST['custom_price']) ? (float)$_POST['custom_price'] : null;
    $is_optional = isset($_POST['is_optional']) ? 1 : 0;
    $is_required = isset($_POST['is_required']) ? 1 : 0;
    $description = trim($_POST['description'] ?? '');

    try {
        $stmt = $pdo->prepare("INSERT INTO bundle_products (bundle_id, product_id, quantity, custom_price, is_optional, is_required, description) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantity = ?, custom_price = ?, is_optional = ?, is_required = ?, description = ?");
        $stmt->execute([$bundle_id, $product_id, $quantity, $custom_price, $is_optional, $is_required, $description, $quantity, $custom_price, $is_optional, $is_required, $description]);
        
        header("Location: service-catalog.php?success=product_added_to_bundle");
        exit;
    } catch (PDOException $e) {
        $error = "Error adding product to bundle: " . $e->getMessage();
    }
}

// Handle Remove Product from Bundle
if (isset($_GET['remove_bundle_product'])) {
    $bundle_id = (int)$_GET['bundle_id'];
    $product_id = (int)$_GET['remove_bundle_product'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM bundle_products WHERE bundle_id = ? AND product_id = ?");
        $stmt->execute([$bundle_id, $product_id]);
        
        header("Location: service-catalog.php?success=product_removed_from_bundle");
        exit;
    } catch (PDOException $e) {
        $error = "Error removing product from bundle: " . $e->getMessage();
    }
}

// Fetch data
$stmt = $pdo->query("SELECT * FROM service_categories ORDER BY sort_order ASC");
$categories = $stmt->fetchAll();

$stmt = $pdo->query("SELECT p.*, c.name as category_name, c.color as category_color 
    FROM products p 
    JOIN service_categories c ON p.category_id = c.id 
    ORDER BY c.sort_order ASC, p.sort_order ASC");
$products = $stmt->fetchAll();

$stmt = $pdo->query("SELECT * FROM service_bundles ORDER BY created_at DESC");
$bundles = $stmt->fetchAll();

// Get bundle products for configuration
$bundle_products = [];
foreach ($bundles as $bundle) {
    $stmt = $pdo->prepare("SELECT bp.*, p.name as product_name, p.base_price, p.unit_type, c.name as category_name 
        FROM bundle_products bp 
        JOIN products p ON bp.product_id = p.id 
        JOIN service_categories c ON p.category_id = c.id 
        WHERE bp.bundle_id = ? 
        ORDER BY bp.sort_order ASC");
    $stmt->execute([$bundle['id']]);
    $bundle_products[$bundle['id']] = $stmt->fetchAll();
}

// Get stats with null checks
$stmt = $pdo->query("SELECT 
    COUNT(*) as total_products,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_products,
    SUM(CASE WHEN is_featured = 1 THEN 1 ELSE 0 END) as featured_products,
    COUNT(DISTINCT category_id) as categories_with_products
    FROM products");
$product_stats = $stmt->fetch();

// Ensure stats are never null
$product_stats = array_merge([
    'total_products' => 0,
    'active_products' => 0,
    'featured_products' => 0,
    'categories_with_products' => 0
], $product_stats ?: []);

$stmt = $pdo->query("SELECT COUNT(*) as total_bundles FROM service_bundles");
$bundle_stats = $stmt->fetch();
$bundle_stats = array_merge(['total_bundles' => 0], $bundle_stats ?: []);

$page_title = "Service Catalog | CaminhoIT";
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php';
?>
    <style>
        :root {
            --primary-color: #4F46E5;
            --success-color: #10B981;
            --warning-color: #F59E0B;
            --danger-color: #EF4444;
            --info-color: #06B6D4;
            --border-color: #E2E8F0;
            --text-muted: #64748B;
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
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            transition: all 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .stat-card .icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-bottom: 1rem;
        }

        .stat-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
        }

        .stat-card .label {
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        .section-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .section-header {
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 12px 12px 0 0;
        }

        .section-content {
            padding: 1.5rem;
        }

        .category-card {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }

        .category-card:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .category-card.inactive {
            opacity: 0.6;
            background: #f9fafb;
        }

        .category-header {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .category-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
        }

        .category-actions {
            display: flex;
            gap: 0.5rem;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .product-card {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            transition: all 0.2s;
        }

        .product-card:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .product-card.inactive {
            opacity: 0.6;
            background: #f9fafb;
        }

        .product-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 0.5rem;
        }

        .product-price {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .product-meta {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
            flex-wrap: wrap;
        }

        .product-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-primary { background: #dbeafe; color: #1e40af; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-featured { background: #fce7f3; color: #be185d; }
        .badge-inactive { background: #f3f4f6; color: #6b7280; }

        .bundle-card {
            border: 2px solid var(--primary-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #f8faff 0%, #ffffff 100%);
            transition: all 0.2s;
        }

        .bundle-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .bundle-card.inactive {
            opacity: 0.6;
            background: #f9fafb;
            border-color: #d1d5db;
        }

        .bundle-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .bundle-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .bundle-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .bundle-products {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .bundle-product {
            display: flex;
            justify-content: between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .bundle-product:last-child {
            border-bottom: none;
        }

        .nav-tabs {
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 2rem;
        }

        .nav-tabs .nav-link {
            border: none;
            padding: 1rem 1.5rem;
            font-weight: 500;
            color: var(--text-muted);
        }

        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            background: none;
        }

        .form-control, .form-select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0.75rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
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

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
        }

        .btn-warning {
            background: var(--warning-color);
            border: none;
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            border: none;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            border: none;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
            color: white;
        }

        .btn-secondary {
            background: #6b7280;
            border: none;
            color: white;
        }

        .btn-secondary:hover {
            background: #4b5563;
            color: white;
        }

        .btn-info {
            background: var(--info-color);
            border: none;
            color: white;
        }

        .btn-info:hover {
            background: #0891b2;
            color: white;
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

        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }

        .status-indicator.active {
            background: var(--success-color);
        }

        .status-indicator.inactive {
            background: #6b7280;
        }

        .product-selector {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.5rem;
        }

        .product-option {
            padding: 0.5rem;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .product-option:hover {
            background: #f8fafc;
        }

        .product-option.selected {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Dark Mode Styles */
        :root.dark body {
            background: #0f172a !important;
        }

        :root.dark .main-container {
            background: transparent !important;
        }

        /* Page Header */
        :root.dark .page-header {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .page-header h1 {
            color: #f1f5f9 !important;
        }

        :root.dark .page-header .subtitle {
            color: #94a3b8 !important;
        }

        /* Stats Grid */
        :root.dark .stats-grid .stat-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .stat-value {
            color: #a78bfa !important;
        }

        :root.dark .stat-label {
            color: #94a3b8 !important;
        }

        /* Tabs */
        :root.dark .tabs-container {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .tab-btn {
            color: #94a3b8 !important;
            border-color: #334155 !important;
        }

        :root.dark .tab-btn.active {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
            color: white !important;
            border-color: transparent !important;
        }

        /* Content Cards */
        :root.dark .content-card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .content-card h2,
        :root.dark .content-card h3,
        :root.dark .content-card h4 {
            color: #f1f5f9 !important;
        }

        /* Category Cards */
        :root.dark .category-card {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .category-card:hover {
            background: #1e293b !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4) !important;
        }

        :root.dark .category-header {
            border-color: #334155 !important;
        }

        :root.dark .category-icon {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
        }

        :root.dark .category-name {
            color: #f1f5f9 !important;
        }

        :root.dark .category-description {
            color: #94a3b8 !important;
        }

        :root.dark .category-stats {
            color: #cbd5e1 !important;
        }

        /* Product Cards */
        :root.dark .product-card {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .product-card:hover {
            background: #1e293b !important;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4) !important;
        }

        :root.dark .product-name {
            color: #f1f5f9 !important;
        }

        :root.dark .product-description {
            color: #94a3b8 !important;
        }

        :root.dark .product-price {
            color: #a78bfa !important;
        }

        :root.dark .product-features {
            border-color: #334155 !important;
        }

        :root.dark .product-features li {
            color: #cbd5e1 !important;
        }

        /* Bundle Cards */
        :root.dark .bundle-card {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .bundle-card:hover {
            background: #1e293b !important;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4) !important;
        }

        :root.dark .bundle-header {
            border-color: #334155 !important;
        }

        :root.dark .bundle-name {
            color: #f1f5f9 !important;
        }

        :root.dark .bundle-description {
            color: #94a3b8 !important;
        }

        :root.dark .bundle-price {
            color: #a78bfa !important;
        }

        :root.dark .bundle-savings {
            background: #065f46 !important;
            color: #a7f3d0 !important;
        }

        :root.dark .bundle-products {
            border-color: #334155 !important;
        }

        :root.dark .bundle-product-item {
            color: #cbd5e1 !important;
        }

        /* Tables */
        :root.dark .table {
            color: #e2e8f0 !important;
        }

        :root.dark .table thead {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
        }

        :root.dark .table thead th {
            color: white !important;
            border-color: transparent !important;
        }

        :root.dark .table tbody tr {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .table tbody tr:hover {
            background: rgba(139, 92, 246, 0.1) !important;
        }

        :root.dark .table tbody td {
            color: #e2e8f0 !important;
            border-color: #334155 !important;
        }

        /* Badges */
        :root.dark .badge {
            background: #334155 !important;
            color: #cbd5e1 !important;
        }

        :root.dark .badge-success {
            background: #065f46 !important;
            color: #a7f3d0 !important;
        }

        :root.dark .badge-warning {
            background: #92400e !important;
            color: #fde68a !important;
        }

        :root.dark .badge-danger {
            background: #7f1d1d !important;
            color: #fca5a5 !important;
        }

        :root.dark .badge-info {
            background: #1e3a8a !important;
            color: #bfdbfe !important;
        }

        /* Forms */
        :root.dark .form-control,
        :root.dark .form-select,
        :root.dark textarea {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .form-control:focus,
        :root.dark .form-select:focus,
        :root.dark textarea:focus {
            background: #1e293b !important;
            border-color: #8b5cf6 !important;
        }

        :root.dark .form-label {
            color: #cbd5e1 !important;
        }

        :root.dark .form-check-input {
            background: #0f172a !important;
            border-color: #334155 !important;
        }

        /* Modals */
        :root.dark .modal-content {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .modal-header {
            background: linear-gradient(135deg, #4c1d95 0%, #5b21b6 100%) !important;
            color: white !important;
            border-color: transparent !important;
        }

        :root.dark .modal-title {
            color: white !important;
        }

        :root.dark .modal-body,
        :root.dark .modal-footer {
            border-color: #334155 !important;
        }

        :root.dark .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        /* Alerts */
        :root.dark .alert-success {
            background: rgba(6, 95, 70, 0.3) !important;
            color: #a7f3d0 !important;
            border-color: #10b981 !important;
        }

        :root.dark .alert-danger {
            background: rgba(127, 29, 29, 0.3) !important;
            color: #fca5a5 !important;
            border-color: #ef4444 !important;
        }

        :root.dark .alert-info {
            background: rgba(30, 58, 138, 0.3) !important;
            color: #bfdbfe !important;
            border-color: #3b82f6 !important;
        }

        /* Text */
        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark h1, :root.dark h2, :root.dark h3, :root.dark h4, :root.dark h5 {
            color: #f1f5f9 !important;
        }

        :root.dark p {
            color: #cbd5e1 !important;
        }

        /* Empty States */
        :root.dark .empty-state {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .empty-state h3 {
            color: #f1f5f9 !important;
        }

        :root.dark .empty-state p {
            color: #94a3b8 !important;
        }

        /* Product Options */
        :root.dark .product-option {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #cbd5e1 !important;
        }

        :root.dark .product-option:hover {
            background: #1e293b !important;
        }

        :root.dark .product-option.selected {
            background: #1e3a8a !important;
            color: #bfdbfe !important;
        }
    </style>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <h1><i class="bi bi-basket me-3"></i>Service Catalog</h1>
        <p class="subtitle">Manage your service offerings, products, and bundles</p>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            <?= ucfirst(str_replace('_', ' ', $_GET['success'])) ?> successfully!
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon" style="background: var(--primary-color);">
                <i class="bi bi-box"></i>
            </div>
            <div class="value"><?= number_format($product_stats['total_products']) ?></div>
            <div class="label">Total Products</div>
        </div>

        <div class="stat-card">
            <div class="icon" style="background: var(--success-color);">
                <i class="bi bi-check-circle"></i>
            </div>
            <div class="value"><?= number_format($product_stats['active_products']) ?></div>
            <div class="label">Active Products</div>
        </div>

        <div class="stat-card">
            <div class="icon" style="background: var(--warning-color);">
                <i class="bi bi-star"></i>
            </div>
            <div class="value"><?= number_format($product_stats['featured_products']) ?></div>
            <div class="label">Featured Products</div>
        </div>

        <div class="stat-card">
            <div class="icon" style="background: var(--info-color);">
                <i class="bi bi-collection"></i>
            </div>
            <div class="value"><?= number_format($bundle_stats['total_bundles']) ?></div>
            <div class="label">Service Bundles</div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs" id="catalogTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories" type="button" role="tab">
                <i class="bi bi-grid me-2"></i>Categories
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="products-tab" data-bs-toggle="tab" data-bs-target="#products" type="button" role="tab">
                <i class="bi bi-box me-2"></i>Products
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="bundles-tab" data-bs-toggle="tab" data-bs-target="#bundles" type="button" role="tab">
                <i class="bi bi-collection me-2"></i>Bundles
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="catalogTabsContent">
        <!-- Categories Tab -->
        <div class="tab-pane fade show active" id="categories" role="tabpanel">
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="bi bi-grid me-2"></i>Service Categories</h3>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                        <i class="bi bi-plus-circle me-2"></i>Add Category
                    </button>
                </div>
                <div class="section-content">
                    <?php foreach ($categories as $category): ?>
                        <div class="category-card <?= $category['is_active'] ? '' : 'inactive' ?>">
                            <div class="category-header">
                                <div class="category-icon" style="background-color: <?= htmlspecialchars($category['color']) ?>;">
                                    <i class="<?= htmlspecialchars($category['icon']) ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="mb-1">
                                        <span class="status-indicator <?= $category['is_active'] ? 'active' : 'inactive' ?>"></span>
                                        <?= htmlspecialchars($category['name']) ?>
                                    </h5>
                                    <p class="text-muted mb-0"><?= htmlspecialchars($category['description']) ?></p>
                                </div>
                                <div class="text-end">
                                    <?php
                                    $product_count = array_filter($products, function($p) use ($category) {
                                        return $p['category_id'] == $category['id'];
                                    });
                                    ?>
                                    <span class="badge badge-primary"><?= count($product_count) ?> products</span>
                                    <div class="category-actions mt-2">
                                        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editCategoryModal<?= $category['id'] ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <a href="?toggle_category=<?= $category['id'] ?>" class="btn btn-sm <?= $category['is_active'] ? 'btn-secondary' : 'btn-success' ?>">
                                            <i class="bi <?= $category['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                                        </a>
                                        <a href="?delete_category=<?= $category['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this category?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Products Tab -->
        <div class="tab-pane fade" id="products" role="tabpanel">
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="bi bi-box me-2"></i>Products & Services</h3>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                        <i class="bi bi-plus-circle me-2"></i>Add Product
                    </button>
                </div>
                <div class="section-content">
                    <div class="product-grid">
                        <?php foreach ($products as $product): ?>
                            <div class="product-card <?= $product['is_active'] ? '' : 'inactive' ?>">
                                <div class="product-header">
                                    <h6 class="mb-0">
                                        <span class="status-indicator <?= $product['is_active'] ? 'active' : 'inactive' ?>"></span>
                                        <?= htmlspecialchars($product['name']) ?>
                                    </h6>
                                    <div class="product-price">
                                        £<?= number_format($product['base_price'], 2) ?>
                                        <small class="text-muted">/<?= $product['unit_type'] ?></small>
                                    </div>
                                </div>
                                <p class="text-muted small mb-2"><?= htmlspecialchars($product['short_description']) ?></p>
                                <div class="product-meta">
                                    <span class="badge badge-primary"><?= htmlspecialchars($product['category_name']) ?></span>
                                    <span class="badge badge-success"><?= ucfirst($product['billing_cycle']) ?></span>
                                    <?php if ($product['is_featured']): ?>
                                        <span class="badge badge-featured">Featured</span>
                                    <?php endif; ?>
                                    <?php if ($product['requires_setup']): ?>
                                        <span class="badge badge-warning">Setup Required</span>
                                    <?php endif; ?>
                                    <?php if (!$product['is_active']): ?>
                                        <span class="badge badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($product['setup_fee'] > 0): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">Setup Fee: £<?= number_format($product['setup_fee'], 2) ?></small>
                                    </div>
                                <?php endif; ?>
                                <div class="product-actions">
                                    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editProductModal<?= $product['id'] ?>">
                                        <i class="bi bi-pencil"></i> Edit
                                    </button>
                                    <a href="?toggle_product=<?= $product['id'] ?>" class="btn btn-sm <?= $product['is_active'] ? 'btn-secondary' : 'btn-success' ?>">
                                        <i class="bi <?= $product['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                                        <?= $product['is_active'] ? 'Disable' : 'Enable' ?>
                                    </a>
                                    <a href="?delete_product=<?= $product['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this product?')">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bundles Tab -->
        <div class="tab-pane fade" id="bundles" role="tabpanel">
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="bi bi-collection me-2"></i>Service Bundles</h3>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBundleModal">
                        <i class="bi bi-plus-circle me-2"></i>Add Bundle
                    </button>
                </div>
                <div class="section-content">
                    <?php foreach ($bundles as $bundle): ?>
                        <div class="bundle-card <?= $bundle['is_active'] ? '' : 'inactive' ?>">
                            <div class="bundle-header">
                                <div>
                                    <h5 class="mb-1">
                                        <span class="status-indicator <?= $bundle['is_active'] ? 'active' : 'inactive' ?>"></span>
                                        <?= htmlspecialchars($bundle['name']) ?>
                                    </h5>
                                    <p class="text-muted mb-0"><?= htmlspecialchars($bundle['target_audience']) ?></p>
                                </div>
                                <div class="text-end">
                                    <div class="bundle-price">
                                        £<?= number_format($bundle['bundle_price'], 2) ?>
                                        <small class="text-muted">/<?= $bundle['billing_cycle'] ?></small>
                                    </div>
                                    <?php if ($bundle['discount_percentage'] > 0): ?>
                                        <div class="text-success">
                                            <small><?= $bundle['discount_percentage'] ?>% discount</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <p class="mb-0"><?= htmlspecialchars($bundle['short_description']) ?></p>
                            <div class="mt-2">
                                <?php if ($bundle['is_featured']): ?>
                                    <span class="badge badge-featured">Featured Package</span>
                                <?php endif; ?>
                                <?php if (!$bundle['is_active']): ?>
                                    <span class="badge badge-inactive">Inactive</span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Bundle Products -->
                            <?php if (isset($bundle_products[$bundle['id']]) && !empty($bundle_products[$bundle['id']])): ?>
                                <div class="bundle-products">
                                    <h6><i class="bi bi-box me-2"></i>Bundle Products</h6>
                                    <?php foreach ($bundle_products[$bundle['id']] as $bp): ?>
                                        <div class="bundle-product">
                                            <div class="flex-grow-1">
                                                <strong><?= htmlspecialchars($bp['product_name']) ?></strong>
                                                <span class="badge badge-info ms-2">Qty: <?= $bp['quantity'] ?></span>
                                                <?php if ($bp['is_optional']): ?>
                                                    <span class="badge badge-warning ms-1">Optional</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-end">
                                                <span class="text-muted">£<?= number_format(($bp['custom_price'] ?? $bp['base_price']) * $bp['quantity'], 2) ?></span>
                                                <a href="?bundle_id=<?= $bundle['id'] ?>&remove_bundle_product=<?= $bp['product_id'] ?>" class="btn btn-sm btn-outline-danger ms-2" onclick="return confirm('Remove this product from the bundle?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="bundle-actions">
                                <button class="btn btn-sm btn-info" onclick="configureBundleProducts(<?= $bundle['id'] ?>)">
                                    <i class="bi bi-gear"></i> Configure Products
                                </button>
                                <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editBundleModal<?= $bundle['id'] ?>">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <a href="?toggle_bundle=<?= $bundle['id'] ?>" class="btn btn-sm <?= $bundle['is_active'] ? 'btn-secondary' : 'btn-success' ?>">
                                    <i class="bi <?= $bundle['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                                    <?= $bundle['is_active'] ? 'Disable' : 'Enable' ?>
                                </a>
                                <a href="?delete_bundle=<?= $bundle['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this bundle?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Service Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Category Name *</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"></textarea>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Icon (Bootstrap Icons)</label>
                            <input type="text" name="icon" class="form-control" placeholder="bi-gear" value="bi-gear">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Color</label>
                            <input type="color" name="color" class="form-control" value="#4F46E5">
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="add_category" class="btn btn-primary">Add Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Category Modals -->
<?php foreach ($categories as $category): ?>
<div class="modal fade" id="editCategoryModal<?= $category['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="category_id" value="<?= $category['id'] ?>">
            <div class="modal-header">
                <h5 class="modal-title">Edit Category: <?= htmlspecialchars($category['name']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Category Name *</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($category['name']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($category['description']) ?></textarea>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Icon (Bootstrap Icons)</label>
                            <input type="text" name="icon" class="form-control" value="<?= htmlspecialchars($category['icon']) ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Color</label>
                            <input type="color" name="color" class="form-control" value="<?= htmlspecialchars($category['color']) ?>">
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="<?= $category['sort_order'] ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="edit_category" class="btn btn-primary">Update Category</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Product/Service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-8">
                        <div class="mb-3">
                            <label class="form-label">Product Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Product Code</label>
                            <input type="text" name="product_code" class="form-control" placeholder="AUTO-GENERATED">
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Category *</label>
                    <select name="category_id" class="form-select" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Short Description</label>
                    <textarea name="short_description" class="form-control" rows="2" placeholder="Brief description for listings"></textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Full Description</label>
                    <textarea name="description" class="form-control" rows="4" placeholder="Detailed description of the service"></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Unit Type</label>
                            <select name="unit_type" class="form-select">
                                <option value="per_user">Per User</option>
                                <option value="per_device">Per Device</option>
                                <option value="per_month">Per Month</option>
                                <option value="per_gb">Per GB</option>
                                <option value="per_hour">Per Hour</option>
                                <option value="one_time">One Time</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Base Price (£)</label>
                            <input type="number" name="base_price" class="form-control" step="0.01" min="0" value="0.00">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Setup Fee (£)</label>
                            <input type="number" name="setup_fee" class="form-control" step="0.01" min="0" value="0.00">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Billing Cycle</label>
                            <select name="billing_cycle" class="form-select">
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="annually">Annually</option>
                                <option value="one_time">One Time</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Minimum Quantity</label>
                            <input type="number" name="minimum_quantity" class="form-control" min="1" value="1">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="requires_setup" id="requiresSetup">
                            <label class="form-check-label" for="requiresSetup">
                                Requires Setup/Configuration
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_featured" id="isFeatured">
                            <label class="form-check-label" for="isFeatured">
                                Featured Product
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="add_product" class="btn btn-primary">Add Product</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Product Modals -->
<?php foreach ($products as $product): ?>
<div class="modal fade" id="editProductModal<?= $product['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
            <div class="modal-header">
                <h5 class="modal-title">Edit Product: <?= htmlspecialchars($product['name']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-8">
                        <div class="mb-3">
                            <label class="form-label">Product Name *</label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($product['name']) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Product Code</label>
                            <input type="text" name="product_code" class="form-control" value="<?= htmlspecialchars($product['product_code']) ?>">
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Category *</label>
                    <select name="category_id" class="form-select" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= $category['id'] ?>" <?= $product['category_id'] == $category['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Short Description</label>
                    <textarea name="short_description" class="form-control" rows="2"><?= htmlspecialchars($product['short_description']) ?></textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Full Description</label>
                    <textarea name="description" class="form-control" rows="4"><?= htmlspecialchars($product['description']) ?></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Unit Type</label>
                            <select name="unit_type" class="form-select">
                                <option value="per_user" <?= $product['unit_type'] == 'per_user' ? 'selected' : '' ?>>Per User</option>
                                <option value="per_device" <?= $product['unit_type'] == 'per_device' ? 'selected' : '' ?>>Per Device</option>
                                <option value="per_month" <?= $product['unit_type'] == 'per_month' ? 'selected' : '' ?>>Per Month</option>
                                <option value="per_gb" <?= $product['unit_type'] == 'per_gb' ? 'selected' : '' ?>>Per GB</option>
                                <option value="per_hour" <?= $product['unit_type'] == 'per_hour' ? 'selected' : '' ?>>Per Hour</option>
                                <option value="one_time" <?= $product['unit_type'] == 'one_time' ? 'selected' : '' ?>>One Time</option>
                                <option value="custom" <?= $product['unit_type'] == 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Base Price (£)</label>
                            <input type="number" name="base_price" class="form-control" step="0.01" min="0" value="<?= $product['base_price'] ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Setup Fee (£)</label>
                            <input type="number" name="setup_fee" class="form-control" step="0.01" min="0" value="<?= $product['setup_fee'] ?>">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Billing Cycle</label>
                            <select name="billing_cycle" class="form-select">
                                <option value="monthly" <?= $product['billing_cycle'] == 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                <option value="quarterly" <?= $product['billing_cycle'] == 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                                <option value="annually" <?= $product['billing_cycle'] == 'annually' ? 'selected' : '' ?>>Annually</option>
                                <option value="one_time" <?= $product['billing_cycle'] == 'one_time' ? 'selected' : '' ?>>One Time</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Minimum Quantity</label>
                            <input type="number" name="minimum_quantity" class="form-control" min="1" value="<?= $product['minimum_quantity'] ?>">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="requires_setup" id="requiresSetup<?= $product['id'] ?>" <?= $product['requires_setup'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="requiresSetup<?= $product['id'] ?>">
                                Requires Setup/Configuration
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_featured" id="isFeatured<?= $product['id'] ?>" <?= $product['is_featured'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="isFeatured<?= $product['id'] ?>">
                                Featured Product
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="edit_product" class="btn btn-primary">Update Product</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- Add Bundle Modal -->
<div class="modal fade" id="addBundleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Service Bundle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-8">
                        <div class="mb-3">
                            <label class="form-label">Bundle Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Bundle Code</label>
                            <input type="text" name="bundle_code" class="form-control" placeholder="AUTO-GENERATED">
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Target Audience</label>
                    <input type="text" name="target_audience" class="form-control" placeholder="e.g., Small Business, Enterprise">
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Short Description</label>
                    <textarea name="short_description" class="form-control" rows="2" placeholder="Brief description for listings"></textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Full Description</label>
                    <textarea name="description" class="form-control" rows="4" placeholder="Detailed description of the bundle"></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Bundle Price (£)</label>
                            <input type="number" name="bundle_price" class="form-control" step="0.01" min="0" value="0.00" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Discount %</label>
                            <input type="number" name="discount_percentage" class="form-control" step="0.01" min="0" max="100" value="0">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Billing Cycle</label>
                            <select name="billing_cycle" class="form-select">
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="annually">Annually</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="is_featured" id="bundleFeatured">
                    <label class="form-check-label" for="bundleFeatured">
                        Featured Bundle
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="add_bundle" class="btn btn-primary">Add Bundle</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Bundle Modals -->
<?php foreach ($bundles as $bundle): ?>
<div class="modal fade" id="editBundleModal<?= $bundle['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <input type="hidden" name="bundle_id" value="<?= $bundle['id'] ?>">
            <div class="modal-header">
                <h5 class="modal-title">Edit Bundle: <?= htmlspecialchars($bundle['name']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-8">
                        <div class="mb-3">
                            <label class="form-label">Bundle Name *</label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($bundle['name']) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Bundle Code</label>
                            <input type="text" name="bundle_code" class="form-control" value="<?= htmlspecialchars($bundle['bundle_code']) ?>">
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Target Audience</label>
                    <input type="text" name="target_audience" class="form-control" value="<?= htmlspecialchars($bundle['target_audience']) ?>">
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Short Description</label>
                    <textarea name="short_description" class="form-control" rows="2"><?= htmlspecialchars($bundle['short_description']) ?></textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Full Description</label>
                    <textarea name="description" class="form-control" rows="4"><?= htmlspecialchars($bundle['description']) ?></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Bundle Price (£)</label>
                            <input type="number" name="bundle_price" class="form-control" step="0.01" min="0" value="<?= $bundle['bundle_price'] ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Discount %</label>
                            <input type="number" name="discount_percentage" class="form-control" step="0.01" min="0" max="100" value="<?= $bundle['discount_percentage'] ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Billing Cycle</label>
                            <select name="billing_cycle" class="form-select">
                                <option value="monthly" <?= $bundle['billing_cycle'] == 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                <option value="quarterly" <?= $bundle['billing_cycle'] == 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                                <option value="annually" <?= $bundle['billing_cycle'] == 'annually' ? 'selected' : '' ?>>Annually</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="is_featured" id="bundleFeatured<?= $bundle['id'] ?>" <?= $bundle['is_featured'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="bundleFeatured<?= $bundle['id'] ?>">
                        Featured Bundle
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="edit_bundle" class="btn btn-primary">Update Bundle</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- Configure Bundle Products Modal -->
<div class="modal fade" id="configureBundleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Configure Bundle Products</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Available Products</h6>
                        <div class="product-selector">
                            <?php foreach ($products as $product): ?>
                                <div class="product-option" data-product-id="<?= $product['id'] ?>" data-product-name="<?= htmlspecialchars($product['name']) ?>" data-product-price="<?= $product['base_price'] ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?= htmlspecialchars($product['name']) ?></strong>
                                            <small class="text-muted d-block"><?= htmlspecialchars($product['category_name']) ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge badge-primary">£<?= number_format($product['base_price'], 2) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6>Add to Bundle</h6>
                        <form method="POST" id="bundleProductForm">
                            <input type="hidden" name="bundle_id" id="configure_bundle_id">
                            <input type="hidden" name="product_id" id="configure_product_id">
                            
                            <div class="mb-3">
                                <label class="form-label">Selected Product</label>
                                <input type="text" class="form-control" id="configure_product_name" readonly>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Quantity *</label>
                                <input type="number" name="quantity" class="form-control" min="1" value="1" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Custom Price (£)</label>
                                <input type="number" name="custom_price" class="form-control" step="0.01" min="0" placeholder="Leave blank to use product price">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="2" placeholder="Optional description for this product in the bundle"></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_optional" id="configure_is_optional">
                                        <label class="form-check-label" for="configure_is_optional">
                                            Optional Product
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_required" id="configure_is_required">
                                        <label class="form-check-label" for="configure_is_required">
                                            Required Product
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" name="add_product_to_bundle" class="btn btn-primary w-100 mt-3">Add to Bundle</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-generate product codes
document.addEventListener('DOMContentLoaded', function() {
    // Product code generation
    const productNameInput = document.querySelector('#addProductModal input[name="name"]');
    const productCodeInput = document.querySelector('#addProductModal input[name="product_code"]');
    
    if (productNameInput && productCodeInput) {
        productNameInput.addEventListener('input', function() {
            const name = this.value;
            const code = name.toUpperCase().replace(/[^A-Z0-9]/g, '').substring(0, 10);
            if (!productCodeInput.value || productCodeInput.placeholder === 'AUTO-GENERATED') {
                productCodeInput.value = code;
            }
        });
    }

    // Bundle code generation
    const bundleNameInput = document.querySelector('#addBundleModal input[name="name"]');
    const bundleCodeInput = document.querySelector('#addBundleModal input[name="bundle_code"]');
    
    if (bundleNameInput && bundleCodeInput) {
        bundleNameInput.addEventListener('input', function() {
            const name = this.value;
            const code = 'BUNDLE-' + name.toUpperCase().replace(/[^A-Z0-9]/g, '').substring(0, 6);
            if (!bundleCodeInput.value || bundleCodeInput.placeholder === 'AUTO-GENERATED') {
                bundleCodeInput.value = code;
            }
        });
    }

    // Configure bundle products
    document.querySelectorAll('.product-option').forEach(option => {
        option.addEventListener('click', function() {
            // Remove previous selections
            document.querySelectorAll('.product-option').forEach(p => p.classList.remove('selected'));
            
            // Select this option
            this.classList.add('selected');
            
            // Fill the form
            document.getElementById('configure_product_id').value = this.dataset.productId;
            document.getElementById('configure_product_name').value = this.dataset.productName;
            document.querySelector('#configureBundleModal input[name="custom_price"]').placeholder = 'Default: £' + parseFloat(this.dataset.productPrice).toFixed(2);
        });
    });

    // Confirmation for delete actions
    document.querySelectorAll('a[href*="delete_"]').forEach(link => {
        link.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this item? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    });

    // Auto-focus on modal inputs
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('shown.bs.modal', function() {
            const firstInput = this.querySelector('input[type="text"]');
            if (firstInput) {
                firstInput.focus();
            }
        });
    });
});

function configureBundleProducts(bundleId) {
    document.getElementById('configure_bundle_id').value = bundleId;
    const modal = new bootstrap.Modal(document.getElementById('configureBundleModal'));
    modal.show();
}
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>