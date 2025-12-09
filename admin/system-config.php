<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Access control (Admin only)
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'administrator') {
    header('Location: /login.php');
    exit;
}

// Check if ConfigManager is available
if (!class_exists('ConfigManager')) {
    // ConfigManager not available, show setup page
    $page_title = "System Configuration Setup | CaminhoIT";
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?= $page_title; ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
        <style>
            :root {
                --primary-color: #4F46E5;
                --warning-color: #F59E0B;
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
                max-width: 800px;
                margin: 2rem auto;
                padding: 0 1rem;
            }

            .setup-card {
                background: white;
                border-radius: 12px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                padding: 3rem;
                text-align: center;
            }

            .setup-icon {
                font-size: 4rem;
                color: var(--warning-color);
                margin-bottom: 1.5rem;
            }

            .btn-primary {
                background: var(--primary-color);
                border: none;
                padding: 0.75rem 1.5rem;
                border-radius: 8px;
                font-weight: 600;
            }

            .alert {
                border: none;
                border-radius: 8px;
                padding: 1rem;
                margin-bottom: 1rem;
            }

            .alert-warning {
                background: #fef3c7;
                color: #92400e;
            }

            .alert-info {
                background: #f0f9ff;
                color: #1e40af;
            }

            .setup-steps {
                text-align: left;
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                padding: 2rem;
                margin: 2rem 0;
            }

            .setup-steps h5 {
                color: #374151;
                margin-bottom: 1rem;
            }

            .setup-steps ol {
                padding-left: 1.5rem;
            }

            .setup-steps li {
                margin-bottom: 0.5rem;
                color: #6b7280;
            }

            .code-block {
                background: #1f2937;
                color: #f3f4f6;
                padding: 1rem;
                border-radius: 6px;
                font-family: 'Courier New', monospace;
                font-size: 0.875rem;
                overflow-x: auto;
                margin: 1rem 0;
            }
        </style>
    </head>
    <body>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

    <div class="main-container">
        <div class="setup-card">
            <i class="bi bi-gear setup-icon"></i>
            <h1>Configuration System Setup Required</h1>
            <p class="text-muted mb-4">The advanced configuration system is not yet set up. Let's get it configured!</p>
            
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>ConfigManager class not found.</strong> The system configuration requires additional setup to function properly.
            </div>

            <div class="setup-steps">
                <h5>Setup Steps:</h5>
                <ol>
                    <li><strong>Create ConfigManager.php</strong> in your <code>/includes/</code> directory</li>
                    <li><strong>Run the database migration</strong> to create the system_config table</li>
                    <li><strong>Initialize the configuration</strong> with default values</li>
                    <li><strong>Access the full configuration interface</strong></li>
                </ol>

                <div class="alert alert-info mt-3">
                    <i class="bi bi-info-circle me-2"></i>
                    In the meantime, you can manage basic settings in your <code>config.php</code> file.
                </div>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                <a href="/admin/settings.php" class="btn btn-primary">
                    <i class="bi bi-gear me-2"></i>Basic Settings
                </a>
                <button class="btn btn-outline-secondary" onclick="showSetupInstructions()">
                    <i class="bi bi-code-slash me-2"></i>Show Setup Code
                </button>
            </div>

            <!-- Setup Instructions (Hidden by default) -->
            <div id="setupInstructions" style="display: none;" class="mt-4">
                <h5>Quick Setup:</h5>
                <p>1. Create <code>/includes/ConfigManager.php</code> with this content:</p>
                <div class="code-block">
&lt;?php
// ConfigManager class content goes here
// (Full code available in documentation)
class ConfigManager {
    // ... implementation
}
                </div>
                
                <p>2. Run this SQL to create the configuration table:</p>
                <div class="code-block">
CREATE TABLE system_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(255) UNIQUE NOT NULL,
    config_value TEXT,
    config_type ENUM('string', 'integer', 'boolean', 'json', 'decimal') DEFAULT 'string',
    category VARCHAR(100),
    description TEXT,
    -- ... additional fields
);
                </div>
                
                <p>3. Refresh this page once setup is complete!</p>
            </div>

            <div class="mt-4">
                <small class="text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Current configuration is working via config.php fallback values.
                </small>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function showSetupInstructions() {
        const instructions = document.getElementById('setupInstructions');
        if (instructions.style.display === 'none') {
            instructions.style.display = 'block';
        } else {
            instructions.style.display = 'none';
        }
    }
    </script>
    </body>
    </html>
    <?php
    exit;
}

// Enhanced duplicate detection and key normalization helper functions
function normalizeConfigKey($key) {
    // Convert any format to consistent underscore format
    $key = strtolower($key);
    $key = str_replace(['-', ' ', '.'], '_', $key);
    $key = preg_replace('/[^a-z0-9_]/', '', $key);
    $key = preg_replace('/_+/', '_', $key); // Remove duplicate underscores
    return trim($key, '_');
}

function findExistingConfigKey($intended_key, $category = null) {
    global $pdo;
    
    $normalized_key = normalizeConfigKey($intended_key);
    
    // Try multiple potential formats
    $potential_keys = [
        $intended_key,
        $normalized_key,
        str_replace('_', '.', $normalized_key),
        str_replace('_', ' ', $normalized_key),
        str_replace('_', '-', $normalized_key),
        $category . '.' . str_replace($category . '_', '', $normalized_key),
        $category . '_' . str_replace($category . '_', '', $normalized_key)
    ];
    
    $potential_keys = array_unique($potential_keys);
    
    foreach ($potential_keys as $test_key) {
        $sql = "SELECT config_key FROM system_config WHERE config_key = ?";
        $params = [$test_key];
        
        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        if ($stmt->fetch()) {
            return $test_key;
        }
    }
    
    return null;
}

function findConfigDuplicates($category = null) {
    global $pdo;
    
    $sql = "SELECT config_key, COUNT(*) as count FROM system_config";
    $params = [];
    
    if ($category) {
        $sql .= " WHERE category = ?";
        $params[] = $category;
    }
    
    $sql .= " GROUP BY config_key HAVING count > 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Debug variables
$debug_info = [];
$debug_mode = true; // Set to false in production

// Handle duplicate cleanup
if (isset($_POST['cleanup_duplicates'])) {
    $debug_info[] = "Cleanup duplicates requested";
    
    try {
        // Find duplicates
        $duplicates = findConfigDuplicates();
        $cleanup_count = 0;
        
        foreach ($duplicates as $duplicate) {
            $key = $duplicate['config_key'];
            
            // Keep the oldest record, delete newer ones
            $sql = "DELETE FROM system_config WHERE config_key = ? AND id NOT IN (SELECT * FROM (SELECT MIN(id) FROM system_config WHERE config_key = ?) AS temp)";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([$key, $key]);
            
            if ($result) {
                $cleanup_count += $stmt->rowCount();
                $debug_info[] = "Cleaned up duplicates for key: {$key}";
            }
        }
        
        if ($cleanup_count > 0) {
            $success = "Cleaned up {$cleanup_count} duplicate configuration entries!";
        } else {
            $info = "No duplicate entries found to clean up.";
        }
        
    } catch (Exception $e) {
        $error = "Error during cleanup: " . $e->getMessage();
        $debug_info[] = "Cleanup error: " . $e->getMessage();
    }
}

// If we get here, ConfigManager is available - proceed with original functionality
// Handle configuration updates
if (isset($_POST['update_config'])) {
    $debug_info[] = "Form submitted with update_config";
    $debug_info[] = "POST data: " . print_r($_POST, true);
    
    $category = $_POST['category'];
    $success_count = 0;
    $error_count = 0;
    $update_details = [];
    
    $debug_info[] = "Processing category: " . $category;
    $debug_info[] = "User ID: " . $_SESSION['user']['id'];
    
    foreach ($_POST as $key => $value) {
        // Skip non-config fields
        if ($key === 'category' || $key === 'update_config' || strpos($key, '_checkbox') !== false) {
            continue;
        }
        
        // Only process fields that start with the category name
        if (strpos($key, $category . '_') === 0) {
            $debug_info[] = "Processing key: {$key} with value: " . (is_string($value) ? substr($value, 0, 100) : $value);
            
            // Handle checkboxes (boolean values)
            if (isset($_POST[$key . '_checkbox'])) {
                $value = isset($_POST[$key]) ? true : false;
                $debug_info[] = "Checkbox detected for {$key}, value set to: " . ($value ? 'true' : 'false');
            }
            
            // Handle JSON fields
            if (is_string($value) && (strpos($value, '{') === 0 || strpos($value, '[') === 0)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                    $debug_info[] = "JSON detected for {$key}, decoded successfully";
                } else {
                    $debug_info[] = "JSON decode failed for {$key}: " . json_last_error_msg();
                }
            }
            
            // Enhanced duplicate prevention logic
            $config_key_with_dots = str_replace('_', '.', $key);
            
            // Check for existing key in various formats
            $existing_key = findExistingConfigKey($config_key_with_dots, $category);
            
            if ($existing_key) {
                $debug_info[] = "Found existing key: {$existing_key} for intended key: {$config_key_with_dots}";
                $config_key_to_use = $existing_key;
            } else {
                $debug_info[] = "No existing key found, will create new: {$config_key_with_dots}";
                $config_key_to_use = $config_key_with_dots;
            }
            
            // Attempt to save the configuration
            $result = ConfigManager::set($config_key_to_use, $value, $category, null, $_SESSION['user']['id']);
            $debug_info[] = "ConfigManager::set result for {$config_key_to_use}: " . ($result ? 'SUCCESS' : 'FAILED');
            
            if ($result) {
                $success_count++;
                $update_details[] = "{$config_key_to_use} = " . (is_array($value) ? 'array' : $value);
            } else {
                $error_count++;
                $debug_info[] = "FAILED to save {$config_key_to_use}";
            }
        }
    }
    
    $debug_info[] = "Final results: {$success_count} successes, {$error_count} errors";
    
    if ($success_count > 0) {
        $success = "Updated {$success_count} configuration setting(s) successfully!";
        if ($debug_mode) {
            $success .= "<br><small>Details: " . implode(', ', $update_details) . "</small>";
        }
    }
    if ($error_count > 0) {
        $error = "Failed to update {$error_count} configuration setting(s).";
    }
    
    // Clear cache to reflect changes
    ConfigManager::clearCache();
    $debug_info[] = "Cache cleared: SUCCESS";
    
    // Test if values were actually saved by reading them back
    $debug_info[] = "Verifying saved values...";
    foreach ($update_details as $detail) {
        list($key, $saved_value) = explode(' = ', $detail, 2);
        $current_value = ConfigManager::get($key, 'NOT_FOUND');
        $debug_info[] = "Verification - {$key}: saved='{$saved_value}', current='" . (is_array($current_value) ? 'array' : $current_value) . "'";
    }
}

// Get current category from URL or default to 'tax'
$current_category = $_GET['category'] ?? 'tax';
$debug_info[] = "Current category: " . $current_category;

$categories = ConfigManager::getCategories();
$debug_info[] = "Available categories: " . implode(', ', $categories);

$config_data = ConfigManager::getByCategory($current_category);
$debug_info[] = "Config data for {$current_category}: " . count($config_data) . " items";

// Check for duplicates in current category
$duplicates = findConfigDuplicates($current_category);
$debug_info[] = "Found " . count($duplicates) . " duplicate keys in {$current_category}";

// If we're processing a form submission, force reload the config data
if (isset($_POST['update_config']) || isset($_POST['cleanup_duplicates'])) {
    $config_data = ConfigManager::getByCategory($current_category);
}

$page_title = "System Configuration | CaminhoIT";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
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
            max-width: 1200px;
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

        .config-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .config-tabs {
            border-bottom: 1px solid #e2e8f0;
            padding: 0 2rem;
        }

        .nav-tabs {
            border-bottom: none;
        }

        .nav-tabs .nav-link {
            border: none;
            padding: 1rem 1.5rem;
            color: #6b7280;
            font-weight: 500;
            border-bottom: 2px solid transparent;
        }

        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            background: none;
        }

        .config-content {
            padding: 2rem;
        }

        .config-group {
            margin-bottom: 2rem;
        }

        .config-group h6 {
            color: #374151;
            font-weight: 600;
            margin-bottom: 1rem;
            text-transform: uppercase;
            font-size: 0.875rem;
            letter-spacing: 0.5px;
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

        .btn-warning {
            background: var(--warning-color);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
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

        .alert-info {
            background: #f0f9ff;
            color: #1e40af;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .form-text {
            color: #6b7280;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        .category-icon {
            margin-right: 0.5rem;
        }

        .config-item {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #f3f4f6;
        }

        .config-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .config-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }

        .json-editor {
            font-family: 'Courier New', monospace;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
        }

        .debug-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
        }

        .form-row {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            background: #fafafa;
        }

        .form-row.updated {
            border-color: #10b981;
            background: #f0fdf4;
        }

        .duplicate-warning {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .cleanup-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-gear me-3"></i>System Configuration</h1>
                <p class="text-muted mb-0">Manage global system settings and business configuration</p>
            </div>
            <div>
                <button class="btn btn-outline-secondary" onclick="exportConfig()">
                    <i class="bi bi-download me-2"></i>Export Config
                </button>
                <?php if ($debug_mode): ?>
                <button class="btn btn-outline-info ms-2" onclick="toggleDebug()">
                    <i class="bi bi-bug me-2"></i>Debug Info
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Duplicate Warning -->
    <?php if (!empty($duplicates)): ?>
        <div class="duplicate-warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-1"><i class="bi bi-exclamation-triangle me-2"></i>Duplicate Configuration Entries Detected</h5>
                    <p class="mb-0">Found <?= count($duplicates) ?> duplicate configuration keys in this category that may cause issues.</p>
                </div>
                <form method="POST" class="ms-3">
                    <input type="hidden" name="category" value="<?= $current_category ?>">
                    <button type="submit" name="cleanup_duplicates" class="btn btn-warning btn-sm">
                        <i class="bi bi-trash me-1"></i>Cleanup Duplicates
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Debug Information -->
    <?php if ($debug_mode && !empty($debug_info)): ?>
    <div class="alert alert-info" id="debugInfo" style="display: none;">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <strong><i class="bi bi-bug me-2"></i>Debug Information</strong>
            <button class="btn btn-sm btn-outline-secondary" onclick="toggleDebug()">Hide</button>
        </div>
        <div class="debug-info"><?= implode("\n", $debug_info) ?></div>
    </div>
    <?php endif; ?>

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

    <?php if (isset($info)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i><?= $info ?>
        </div>
    <?php endif; ?>

    <!-- Configuration Section -->
    <div class="config-section">
        <!-- Category Tabs -->
        <div class="config-tabs">
            <ul class="nav nav-tabs" role="tablist">
                <?php 
                $category_icons = [
                    'tax' => 'bi-receipt',
                    'email' => 'bi-envelope',
                    'business' => 'bi-building',
                    'currency' => 'bi-currency-exchange',
                    'system' => 'bi-gear',
                    'billing' => 'bi-credit-card',
                    'features' => 'bi-toggles'
                ];
                ?>
                <?php foreach ($categories as $category): ?>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?= $category === $current_category ? 'active' : '' ?>" 
                           href="?category=<?= $category ?>">
                            <i class="<?= $category_icons[$category] ?? 'bi-gear' ?> category-icon"></i>
                            <?= ucfirst($category) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Configuration Content -->
        <div class="config-content">
            <form method="POST" id="configForm">
                <input type="hidden" name="category" value="<?= $current_category ?>">
                
                <?php if (empty($config_data)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-gear" style="font-size: 3rem;"></i>
                        <p class="mt-2">No configuration settings found for this category.</p>
                        <?php if ($debug_mode): ?>
                            <div class="alert alert-info mt-3">
                                <strong>Debug:</strong> Check if the system_config table has data for category "<?= $current_category ?>"
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($config_data as $key => $config): ?>
                        <div class="config-item">
                            <label class="config-label"><?= ucwords(str_replace(['_', '.'], ' ', explode('.', $key)[1])) ?></label>
                            
                            <?php 
                            // Convert config key (currency.api_endpoint) to form field name (currency_api_endpoint)
                            $form_field_name = str_replace('.', '_', $key);
                            ?>
                            
                            <?php if ($config['type'] === 'boolean'): ?>
                                <div class="form-check">
                                    <input type="hidden" name="<?= $form_field_name ?>_checkbox" value="1">
                                    <input class="form-check-input" type="checkbox" name="<?= $form_field_name ?>" 
                                           id="<?= $form_field_name ?>" <?= $config['value'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="<?= $form_field_name ?>">
                                        Enable <?= ucwords(str_replace(['_', '.'], ' ', explode('.', $key)[1])) ?>
                                    </label>
                                </div>
                            <?php elseif ($config['type'] === 'json'): ?>
                                <textarea class="form-control json-editor" name="<?= $form_field_name ?>" rows="6"><?= json_encode($config['value'], JSON_PRETTY_PRINT) ?></textarea>
                            <?php elseif ($config['type'] === 'integer'): ?>
                                <input type="number" class="form-control" name="<?= $form_field_name ?>" 
                                       value="<?= htmlspecialchars($config['value']) ?>" step="1">
                            <?php elseif ($config['type'] === 'decimal'): ?>
                                <input type="number" class="form-control" name="<?= $form_field_name ?>" 
                                       value="<?= htmlspecialchars($config['value']) ?>" step="0.01">
                            <?php else: ?>
                                <input type="text" class="form-control" name="<?= $form_field_name ?>" 
                                       value="<?= htmlspecialchars($config['value']) ?>">
                            <?php endif; ?>
                            
                            <?php if ($config['description']): ?>
                                <div class="form-text"><?= htmlspecialchars($config['description']) ?></div>
                            <?php endif; ?>
                            
                            <?php if ($debug_mode): ?>
                                <div class="form-text">
                                    <small class="text-muted">
                                        Debug: Key=<?= $key ?>, FormField=<?= $form_field_name ?>, Type=<?= $config['type'] ?>, Current=<?= is_array($config['value']) ? json_encode($config['value']) : $config['value'] ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            Changes will take effect immediately after saving.
                        </small>
                        <button type="submit" name="update_config" class="btn btn-primary">
                            <i class="bi bi-save me-2"></i>Save Configuration
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function exportConfig() {
    // Create a downloadable JSON file with current configuration
    const category = new URLSearchParams(window.location.search).get('category') || 'tax';
    
    fetch(`/admin/api/export-config.php?category=${category}`)
        .then(response => response.json())
        .then(data => {
            const blob = new Blob([JSON.stringify(data, null, 2)], {type: 'application/json'});
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `caminhoit-config-${category}-${new Date().toISOString().split('T')[0]}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        })
        .catch(error => {
            console.error('Export failed:', error);
            alert('Failed to export configuration');
        });
}

function toggleDebug() {
    const debugInfo = document.getElementById('debugInfo');
    if (debugInfo.style.display === 'none') {
        debugInfo.style.display = 'block';
    } else {
        debugInfo.style.display = 'none';
    }
}

// Auto-format JSON fields
document.addEventListener('DOMContentLoaded', function() {
    const jsonEditors = document.querySelectorAll('.json-editor');
    jsonEditors.forEach(editor => {
        editor.addEventListener('blur', function() {
            try {
                const parsed = JSON.parse(this.value);
                this.value = JSON.stringify(parsed, null, 2);
            } catch (e) {
                // Invalid JSON, leave as is
            }
        });
    });
});

</script>
</body>
</html>