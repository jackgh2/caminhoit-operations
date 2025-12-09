<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Access control
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'administrator') {
    header('Location: /login.php');
    exit;
}

// Handle manual rate update
if (isset($_POST['update_rates'])) {
    $result = ConfigManager::updateExchangeRates($_SESSION['user']['id']);
    
    if ($result['success']) {
        $success = "Successfully updated {$result['updated_rates']} exchange rates";
        if (!empty($result['alerts'])) {
            $alerts = $result['alerts'];
        }
    } else {
        $error = "Failed to update rates: " . $result['error'];
    }
}

// Handle manual rate setting
if (isset($_POST['save_rates'])) {
    $rates = [];
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'rate_') === 0) {
            $currency = substr($key, 5);
            $rates[$currency] = floatval($value);
        }
    }
    
    if (ConfigManager::set('currency.exchange_rates', $rates, 'currency', 'Exchange rates', $_SESSION['user']['id'])) {
        ConfigManager::clearCache();
        $success = "Exchange rates updated manually";
    } else {
        $error = "Failed to save exchange rates";
    }
}

$supportedCurrencies = ConfigManager::getSupportedCurrencies();
$currentRates = ConfigManager::getExchangeRates();
$lastUpdate = ConfigManager::getLastRateUpdate();
$lastError = ConfigManager::getLastApiError();
$autoUpdateEnabled = ConfigManager::isAutoUpdateEnabled();
$autoUpdateTime = ConfigManager::getAutoUpdateTime();
$conversionFee = ConfigManager::getConversionFeePercent();

$page_title = "Exchange Rates | CaminhoIT";
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

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .card-header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            border-radius: 12px 12px 0 0 !important;
            padding: 1.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        .rate-input {
            font-family: 'Courier New', monospace;
            text-align: right;
        }

        .status-badge {
            font-size: 0.875rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
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
            margin-bottom: 1rem;
        }

        .conversion-fee-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="main-container">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1><i class="bi bi-currency-exchange me-3"></i>Exchange Rates</h1>
            <p class="text-muted mb-0">Manage currency exchange rates and conversion settings</p>
        </div>
        <div>
            <a href="/admin/system-config.php?category=currency" class="btn btn-outline-secondary me-2">
                <i class="bi bi-gear me-2"></i>Currency Config
            </a>
            <form method="POST" class="d-inline">
                <button type="submit" name="update_rates" class="btn btn-primary">
                    <i class="bi bi-arrow-clockwise me-2"></i>Update from API
                </button>
            </form>
        </div>
    </div>

    <!-- Status Messages -->
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

    <?php if (isset($alerts)): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Significant Rate Changes Detected:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($alerts as $alert): ?>
                    <li>
                        <strong><?= $alert['currency'] ?>:</strong> 
                        <?= $alert['old_rate'] ?> → <?= $alert['new_rate'] ?> 
                        (<?= number_format($alert['change_percent'], 2) ?>% change)
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-8">
            <!-- Exchange Rates Card -->
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Current Exchange Rates</h5>
                        <div>
                            <?php if ($autoUpdateEnabled): ?>
                                <span class="status-badge bg-success text-white">
                                    <i class="bi bi-check-circle me-1"></i>Auto-Update ON
                                </span>
                            <?php else: ?>
                                <span class="status-badge bg-secondary text-white">
                                    <i class="bi bi-pause-circle me-1"></i>Manual Only
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <small class="text-muted">
                            Base Currency: <strong><?= ConfigManager::get('business.default_currency', 'GBP') ?></strong>
                            <?php if ($lastUpdate): ?>
                                | Last Updated: <strong><?= date('d/m/Y H:i', strtotime($lastUpdate)) ?></strong>
                            <?php endif; ?>
                        </small>
                    </div>

                    <form method="POST">
                        <div class="row">
                            <?php foreach ($supportedCurrencies as $code => $currency): ?>
                                <?php if ($code !== ConfigManager::get('business.default_currency', 'GBP')): ?>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <?= $currency['symbol'] ?> <?= $currency['name'] ?> (<?= $code ?>)
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">1 GBP =</span>
                                            <input type="number" 
                                                   name="rate_<?= $code ?>" 
                                                   class="form-control rate-input" 
                                                   value="<?= $currentRates[$code] ?? 1 ?>" 
                                                   step="0.000001" 
                                                   min="0">
                                            <span class="input-group-text"><?= $code ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                Manual rates will be overridden by automatic updates if enabled
                            </small>
                            <button type="submit" name="save_rates" class="btn btn-outline-primary">
                                <i class="bi bi-save me-2"></i>Save Manual Rates
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Conversion Fee Settings -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Conversion Fee Configuration</h5>
                </div>
                <div class="card-body">
                    <div class="conversion-fee-info">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Current Conversion Fee:</strong>
                                <span class="text-primary"><?= $conversionFee ?>%</span>
                            </div>
                            <div class="col-md-6">
                                <strong>Visible to Customers:</strong>
                                <span class="<?= ConfigManager::shouldShowConversionFee() ? 'text-success' : 'text-muted' ?>">
                                    <?= ConfigManager::shouldShowConversionFee() ? 'Yes' : 'No' ?>
                                </span>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Example Conversion:</strong><br>
                                <small class="text-muted">
                                    £100.00 → $<?= number_format(ConfigManager::convertCurrency(100, 'GBP', 'USD'), 2) ?> 
                                    (includes <?= $conversionFee ?>% fee)
                                </small>
                            </div>
                            <div class="col-md-6">
                                <strong>Without Fee:</strong><br>
                                <small class="text-muted">
                                    £100.00 → $<?= number_format(ConfigManager::convertCurrency(100, 'GBP', 'USD', false), 2) ?> 
                                    (no fee)
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <a href="/admin/system-config.php?category=currency" class="btn btn-outline-secondary">
                            <i class="bi bi-gear me-2"></i>Configure Conversion Fees
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <!-- Status Card -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">Update Status</h6>
                </div>
                <div class="card-body">
                    <?php if ($autoUpdateEnabled): ?>
                        <div class="mb-3">
                            <i class="bi bi-clock text-primary me-2"></i>
                            <strong>Next Update:</strong><br>
                            <small class="text-muted">Daily at <?= $autoUpdateTime ?></small>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($lastUpdate): ?>
                        <div class="mb-3">
                            <i class="bi bi-calendar-check text-success me-2"></i>
                            <strong>Last Updated:</strong><br>
                            <small class="text-muted"><?= date('d/m/Y H:i:s', strtotime($lastUpdate)) ?></small>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($lastError): ?>
                        <div class="mb-3">
                            <i class="bi bi-exclamation-triangle text-danger me-2"></i>
                            <strong>Last Error:</strong><br>
                            <small class="text-danger"><?= htmlspecialchars($lastError) ?></small>
                        </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <i class="bi bi-info-circle text-info me-2"></i>
                        <strong>API Provider:</strong><br>
                        <small class="text-muted"><?= ConfigManager::get('currency.api_provider', 'exchangerate-api') ?></small>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">Quick Actions</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="/admin/currency-converter.php" class="btn btn-outline-primary">
                            <i class="bi bi-calculator me-2"></i>Currency Converter
                        </a>
                        <a href="/admin/system-config.php?category=currency" class="btn btn-outline-secondary">
                            <i class="bi bi-gear me-2"></i>Currency Settings
                        </a>
                        <button class="btn btn-outline-info" onclick="showCronSetup()">
                            <i class="bi bi-clock me-2"></i>Setup Cron Job
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cron Setup Modal -->
<div class="modal fade" id="cronModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Setup Automatic Updates</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>To enable automatic exchange rate updates, add this cron job to your server:</p>
                <div class="bg-dark text-light p-3 rounded">
                    <code>0 0 * * * /usr/bin/php <?= $_SERVER['DOCUMENT_ROOT'] ?>/cron/update-exchange-rates.php</code>
                </div>
                <p class="mt-3">
                    <small class="text-muted">
                        This will update exchange rates daily at midnight. 
                        Make sure to enable auto-updates in the currency configuration.
                    </small>
                </p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showCronSetup() {
    new bootstrap.Modal(document.getElementById('cronModal')).show();
}
</script>
</body>
</html>