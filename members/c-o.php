<?php
// Enable all error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Start output buffering to catch any early output
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<!-- Debug: Session started -->\n";

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
    echo "<!-- Debug: Config loaded -->\n";
} catch (Exception $e) {
    die("Config Error: " . $e->getMessage());
}

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';
    echo "<!-- Debug: Lang loaded -->\n";
} catch (Exception $e) {
    echo "<!-- Debug: Lang file not found, using default -->\n";
    $lang = 'en';
}

$user = $_SESSION['user'] ?? null;
echo "<!-- Debug: User session check - " . ($user ? "User found: " . $user['username'] : "No user") . " -->\n";

if (!$user) {
    echo "<!-- Debug: Redirecting to login -->\n";
    header('Location: /login.php');
    exit;
}

// Check if user has access (administrator or account_manager only)
if (!in_array($user['role'], ['administrator', 'account_manager'])) {
    echo "<!-- Debug: User role '" . $user['role'] . "' not authorized, redirecting -->\n";
    header('Location: /members/dashboard.php');
    exit;
}

echo "<!-- Debug: User authorized with role: " . $user['role'] . " -->\n";

$user_id = $user['id'];

// Test database connection
try {
    $test = $pdo->query("SELECT 1");
    echo "<!-- Debug: Database connection working -->\n";
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}

// Function to get system config value
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

// Get VAT settings from system_config table
try {
    $vatRegistered = getSystemConfig($pdo, 'tax.vat_registered', false);
    $defaultVatRate = getSystemConfig($pdo, 'tax.default_vat_rate', 0.20);
    echo "<!-- Debug: VAT settings loaded - Registered: " . ($vatRegistered ? 'true' : 'false') . ", Rate: $defaultVatRate -->\n";
} catch (Exception $e) {
    echo "<!-- Debug: Error loading VAT settings: " . $e->getMessage() . " -->\n";
    $vatRegistered = false;
    $defaultVatRate = 0.20;
}

// Get companies this user has access to
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.name, 
               COALESCE(c.preferred_currency, 'GBP') as preferred_currency, 
               COALESCE(c.currency_override, 0) as currency_override,
               CASE 
                   WHEN u.company_id = c.id THEN 'Primary'
                   ELSE 'Multi-Company'
               END as relationship_type
        FROM companies c
        JOIN users u ON (u.company_id = c.id OR u.id IN (
            SELECT cu.user_id FROM company_users cu WHERE cu.company_id = c.id
        ))
        WHERE u.id = ? AND c.is_active = 1
        ORDER BY relationship_type ASC, c.name ASC
    ");
    $stmt->execute([$user_id]);
    $companies = $stmt->fetchAll();
    echo "<!-- Debug: Found " . count($companies) . " companies -->\n";
} catch (Exception $e) {
    echo "<!-- Debug: Error fetching companies: " . $e->getMessage() . " -->\n";
    $companies = [];
}

// Get active products
try {
    $stmt = $pdo->query("
        SELECT p.*, 
               COALESCE(c.name, 'Uncategorized') as category_name 
        FROM products p 
        LEFT JOIN service_categories c ON p.category_id = c.id 
        WHERE p.is_active = 1 
        ORDER BY c.sort_order ASC, p.name ASC
    ");
    $products = $stmt->fetchAll();
    echo "<!-- Debug: Found " . count($products) . " products -->\n";
} catch (Exception $e) {
    echo "<!-- Debug: Error fetching products: " . $e->getMessage() . " -->\n";
    $products = [];
}

// Get active bundles
try {
    $stmt = $pdo->query("SELECT * FROM service_bundles WHERE is_active = 1 ORDER BY name ASC");
    $bundles = $stmt->fetchAll();
    echo "<!-- Debug: Found " . count($bundles) . " bundles -->\n";
} catch (Exception $e) {
    echo "<!-- Debug: Error fetching bundles: " . $e->getMessage() . " -->\n";
    $bundles = [];
}

// Check if we have the required data
$hasData = count($companies) > 0 && (count($products) > 0 || count($bundles) > 0);
echo "<!-- Debug: Has required data: " . ($hasData ? 'YES' : 'NO') . " -->\n";

$page_title = "Create Order | CaminhoIT";
?>
<!DOCTYPE html>
<html lang="<?= $lang ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- CSS Links -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        .debug-panel {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
            font-family: monospace;
            font-size: 0.875rem;
        }
        .debug-error {
            background: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        .debug-success {
            background: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        .debug-warning {
            background: #fff3cd;
            border-color: #ffeaa7;
            color: #856404;
        }
    </style>
</head>
<body>

<div class="container mt-4">
    <h1>Create Order - Debug Mode</h1>
    
    <!-- Debug Information Panel -->
    <div class="debug-panel">
        <h5>Debug Information</h5>
        <ul>
            <li><strong>User:</strong> <?= htmlspecialchars($user['username'] ?? 'Unknown') ?> (ID: <?= $user['id'] ?? 'Unknown' ?>)</li>
            <li><strong>Role:</strong> <?= htmlspecialchars($user['role'] ?? 'Unknown') ?></li>
            <li><strong>Companies:</strong> <?= count($companies) ?> found</li>
            <li><strong>Products:</strong> <?= count($products) ?> found</li>
            <li><strong>Bundles:</strong> <?= count($bundles) ?> found</li>
            <li><strong>VAT Registered:</strong> <?= $vatRegistered ? 'Yes' : 'No' ?></li>
            <li><strong>VAT Rate:</strong> <?= ($defaultVatRate * 100) ?>%</li>
            <li><strong>PHP Version:</strong> <?= PHP_VERSION ?></li>
            <li><strong>Current Time:</strong> <?= date('Y-m-d H:i:s') ?></li>
        </ul>
    </div>

    <!-- Data Status Checks -->
    <?php if (count($companies) === 0): ?>
        <div class="debug-panel debug-error">
            <h6>❌ No Companies Found</h6>
            <p>The query for companies returned no results. Check:</p>
            <ul>
                <li>Does the companies table exist?</li>
                <li>Are there active companies (is_active = 1)?</li>
                <li>Is the user associated with any companies?</li>
                <li>Check company_users table for multi-company access</li>
            </ul>
        </div>
    <?php else: ?>
        <div class="debug-panel debug-success">
            <h6>✅ Companies Loaded Successfully</h6>
            <ul>
                <?php foreach ($companies as $company): ?>
                    <li><?= htmlspecialchars($company['name']) ?> (ID: <?= $company['id'] ?>)</li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (count($products) === 0 && count($bundles) === 0): ?>
        <div class="debug-panel debug-error">
            <h6>❌ No Products or Bundles Found</h6>
            <p>No products or service bundles are available. Check:</p>
            <ul>
                <li>Does the products table exist with active products (is_active = 1)?</li>
                <li>Does the service_bundles table exist with active bundles?</li>
                <li>Are there any records in these tables?</li>
            </ul>
        </div>
    <?php else: ?>
        <div class="debug-panel debug-success">
            <h6>✅ Products/Bundles Loaded</h6>
            <p>Products: <?= count($products) ?>, Bundles: <?= count($bundles) ?></p>
        </div>
    <?php endif; ?>

    <!-- JavaScript Test -->
    <div class="debug-panel" id="jsTest">
        <h6>🔄 Testing JavaScript...</h6>
    </div>

    <!-- Simple Test Form -->
    <?php if ($hasData): ?>
        <div class="card">
            <div class="card-header">
                <h5>Test Order Creation</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="testForm">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Company</label>
                                <select name="company_id" class="form-select" required>
                                    <option value="">Select Company</option>
                                    <?php foreach ($companies as $company): ?>
                                        <option value="<?= $company['id'] ?>"><?= htmlspecialchars($company['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Order Type</label>
                                <select name="order_type" class="form-select" required>
                                    <option value="">Select Type</option>
                                    <option value="new">New Service</option>
                                    <option value="upgrade">Service Upgrade</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Billing Cycle</label>
                                <select name="billing_cycle" class="form-select" required>
                                    <option value="">Select Cycle</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="annually">Annually</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Service Start Date</label>
                                <input type="date" name="start_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Available Products:</label>
                        <div class="row">
                            <?php foreach (array_slice($products, 0, 3) as $product): ?>
                                <div class="col-md-4 mb-2">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-title"><?= htmlspecialchars($product['name']) ?></h6>
                                            <p class="card-text">£<?= number_format($product['base_price'], 2) ?></p>
                                            <button type="button" class="btn btn-sm btn-primary" onclick="addTestProduct(<?= $product['id'] ?>, '<?= htmlspecialchars($product['name']) ?>', <?= $product['base_price'] ?>)">Add to Order</button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Test Order Items:</label>
                        <div id="testOrderItems">
                            <p class="text-muted">No items added yet</p>
                        </div>
                    </div>

                    <input type="hidden" name="order_items" id="testOrderItemsInput" value="[]">
                    <input type="hidden" name="order_currency" value="GBP">
                    <input type="hidden" name="place_immediately" value="1">
                    
                    <button type="submit" name="create_order" class="btn btn-success">Test Create Order</button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="debug-panel debug-warning">
            <h6>⚠️ Cannot Test Order Creation</h6>
            <p>Missing required data (companies and/or products). Please resolve the issues above first.</p>
        </div>
    <?php endif; ?>

    <!-- Error Log Display -->
    <div class="mt-4">
        <h5>Recent Error Log</h5>
        <div class="debug-panel">
            <pre id="errorLog">Checking error log...</pre>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Test JavaScript functionality
document.addEventListener('DOMContentLoaded', function() {
    const jsTestDiv = document.getElementById('jsTest');
    jsTestDiv.innerHTML = '<h6>✅ JavaScript Working</h6><p>DOM loaded at: ' + new Date().toLocaleString() + '</p>';
    jsTestDiv.className = 'debug-panel debug-success';
    
    console.log('Debug page loaded successfully');
    console.log('Products available:', <?= json_encode($products) ?>);
    console.log('Companies available:', <?= json_encode($companies) ?>);
});

let testOrderItems = [];

function addTestProduct(id, name, price) {
    const existingIndex = testOrderItems.findIndex(item => item.product_id == id);
    
    if (existingIndex >= 0) {
        testOrderItems[existingIndex].quantity++;
        testOrderItems[existingIndex].line_total = testOrderItems[existingIndex].unit_price * testOrderItems[existingIndex].quantity;
    } else {
        testOrderItems.push({
            product_id: id,
            bundle_id: null,
            item_type: 'product',
            name: name,
            description: '',
            quantity: 1,
            unit_price: price,
            setup_fee: 0,
            line_total: price,
            billing_cycle: 'monthly',
            base_price: price,
            base_setup_fee: 0
        });
    }
    
    updateTestOrderDisplay();
}

function updateTestOrderDisplay() {
    const container = document.getElementById('testOrderItems');
    
    if (testOrderItems.length === 0) {
        container.innerHTML = '<p class="text-muted">No items added yet</p>';
    } else {
        container.innerHTML = testOrderItems.map((item, index) => `
            <div class="alert alert-info d-flex justify-content-between align-items-center">
                <span>${item.name} - £${item.unit_price.toFixed(2)} x ${item.quantity} = £${item.line_total.toFixed(2)}</span>
                <button type="button" class="btn btn-sm btn-danger" onclick="removeTestItem(${index})">Remove</button>
            </div>
        `).join('');
    }
    
    document.getElementById('testOrderItemsInput').value = JSON.stringify(testOrderItems);
}

function removeTestItem(index) {
    testOrderItems.splice(index, 1);
    updateTestOrderDisplay();
}

document.getElementById('testForm').addEventListener('submit', function(e) {
    console.log('Form submitted with test order items:', testOrderItems);
    
    if (testOrderItems.length === 0) {
        e.preventDefault();
        alert('Please add at least one product to test the order creation');
        return false;
    }
    
    document.getElementById('testOrderItemsInput').value = JSON.stringify(testOrderItems);
    console.log('Form data being submitted:', new FormData(this));
});

// Check for JavaScript errors
window.addEventListener('error', function(e) {
    const errorLog = document.getElementById('errorLog');
    errorLog.textContent += '\nJavaScript Error: ' + e.message + ' at ' + e.filename + ':' + e.lineno;
});

// Load error log
fetch('<?= $_SERVER['PHP_SELF'] ?>?action=get_error_log')
    .then(response => response.text())
    .then(data => {
        document.getElementById('errorLog').textContent = data || 'No recent errors found';
    })
    .catch(error => {
        document.getElementById('errorLog').textContent = 'Could not load error log: ' + error.message;
    });
</script>

</body>
</html>

<?php
// Handle AJAX request for error log
if (isset($_GET['action']) && $_GET['action'] === 'get_error_log') {
    header('Content-Type: text/plain');
    
    $errorLogFile = ini_get('error_log');
    if ($errorLogFile && file_exists($errorLogFile)) {
        $lines = file($errorLogFile);
        $recentLines = array_slice($lines, -50); // Last 50 lines
        echo implode('', $recentLines);
    } else {
        echo "Error log file not found or not configured.\n";
        echo "PHP error_log setting: " . ($errorLogFile ?: 'not set') . "\n";
        echo "Try checking: /var/log/php_errors.log or /tmp/php_errors.log\n";
    }
    exit;
}

// Add the order creation logic from your original file here
if (isset($_POST['create_order'])) {
    echo "<!-- Debug: Order creation attempt started -->\n";
    
    // [Include your original order creation code here]
    // For now, just show what was submitted
    echo "<script>console.log('Order creation POST data:', " . json_encode($_POST) . ");</script>\n";
}

ob_end_flush();
?>