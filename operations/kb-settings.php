<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Access control (Admin only for settings)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator'])) {
    header('Location: /login.php');
    exit;
}

// Handle form submission
if ($_POST && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    try {
        if ($action === 'update_general') {
            // General Settings
            $settings = [
                'kb_title' => trim($_POST['kb_title'] ?? ''),
                'kb_description' => trim($_POST['kb_description'] ?? ''),
                'kb_footer_text' => trim($_POST['kb_footer_text'] ?? ''),
                'articles_per_page' => (int)($_POST['articles_per_page'] ?? 12),
                'search_results_per_page' => (int)($_POST['search_results_per_page'] ?? 15),
                'default_article_status' => $_POST['default_article_status'] ?? 'draft',
                'default_article_visibility' => $_POST['default_article_visibility'] ?? 'public',
                'enable_article_rating' => isset($_POST['enable_article_rating']) ? '1' : '0',
                'enable_article_comments' => isset($_POST['enable_article_comments']) ? '1' : '0',
                'require_login_for_feedback' => isset($_POST['require_login_for_feedback']) ? '1' : '0'
            ];
            
            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("
                    INSERT INTO kb_settings (setting_key, setting_value) 
                    VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE setting_value = ?
                ");
                $stmt->execute([$key, $value, $value]);
            }
            
            $success = "General settings updated successfully!";
        }
        
        elseif ($action === 'update_display') {
            // Display Settings
            $settings = [
                'featured_articles_count' => (int)($_POST['featured_articles_count'] ?? 6),
                'recent_articles_count' => (int)($_POST['recent_articles_count'] ?? 8),
                'related_articles_count' => (int)($_POST['related_articles_count'] ?? 4),
                'show_article_author' => isset($_POST['show_article_author']) ? '1' : '0',
                'show_article_date' => isset($_POST['show_article_date']) ? '1' : '0',
                'show_view_count' => isset($_POST['show_view_count']) ? '1' : '0',
                'show_breadcrumbs' => isset($_POST['show_breadcrumbs']) ? '1' : '0',
                'enable_search_highlighting' => isset($_POST['enable_search_highlighting']) ? '1' : '0',
                'show_category_icons' => isset($_POST['show_category_icons']) ? '1' : '0'
            ];
            
            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("
                    INSERT INTO kb_settings (setting_key, setting_value) 
                    VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE setting_value = ?
                ");
                $stmt->execute([$key, $value, $value]);
            }
            
            $success = "Display settings updated successfully!";
        }
        
        elseif ($action === 'update_seo') {
            // SEO Settings
            $settings = [
                'kb_meta_title' => trim($_POST['kb_meta_title'] ?? ''),
                'kb_meta_description' => trim($_POST['kb_meta_description'] ?? ''),
                'kb_meta_keywords' => trim($_POST['kb_meta_keywords'] ?? ''),
                'enable_sitemap' => isset($_POST['enable_sitemap']) ? '1' : '0',
                'enable_rss_feed' => isset($_POST['enable_rss_feed']) ? '1' : '0',
                'robots_txt_content' => trim($_POST['robots_txt_content'] ?? ''),
                'google_analytics_id' => trim($_POST['google_analytics_id'] ?? ''),
                'facebook_pixel_id' => trim($_POST['facebook_pixel_id'] ?? '')
            ];
            
            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("
                    INSERT INTO kb_settings (setting_key, setting_value) 
                    VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE setting_value = ?
                ");
                $stmt->execute([$key, $value, $value]);
            }
            
            $success = "SEO settings updated successfully!";
        }
        
        elseif ($action === 'update_editor') {
            // Editor Settings
            $settings = [
                'tinymce_api_key' => trim($_POST['tinymce_api_key'] ?? ''),
                'enable_code_highlighting' => isset($_POST['enable_code_highlighting']) ? '1' : '0',
                'enable_media_upload' => isset($_POST['enable_media_upload']) ? '1' : '0',
                'max_upload_size' => (int)($_POST['max_upload_size'] ?? 5),
                'allowed_file_types' => trim($_POST['allowed_file_types'] ?? 'jpg,jpeg,png,gif,pdf,doc,docx')
            ];
            
            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("
                    INSERT INTO kb_settings (setting_key, setting_value) 
                    VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE setting_value = ?
                ");
                $stmt->execute([$key, $value, $value]);
            }
            
            $success = "Editor settings updated successfully!";
        }
        
        elseif ($action === 'update_notifications') {
            // Notification Settings
            $settings = [
                'notify_new_articles' => isset($_POST['notify_new_articles']) ? '1' : '0',
                'notify_article_feedback' => isset($_POST['notify_article_feedback']) ? '1' : '0',
                'notify_low_ratings' => isset($_POST['notify_low_ratings']) ? '1' : '0',
                'notification_email' => trim($_POST['notification_email'] ?? ''),
                'feedback_threshold' => (int)($_POST['feedback_threshold'] ?? 3),
                'review_reminder_days' => (int)($_POST['review_reminder_days'] ?? 90)
            ];
            
            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("
                    INSERT INTO kb_settings (setting_key, setting_value) 
                    VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE setting_value = ?
                ");
                $stmt->execute([$key, $value, $value]);
            }
            
            $success = "Notification settings updated successfully!";
        }
        
        elseif ($action === 'clear_cache') {
            // Clear any cached data
            $cache_dirs = [
                $_SERVER['DOCUMENT_ROOT'] . '/cache/',
                $_SERVER['DOCUMENT_ROOT'] . '/tmp/',
                $_SERVER['DOCUMENT_ROOT'] . '/uploads/cache/'
            ];
            
            $files_cleared = 0;
            foreach ($cache_dirs as $dir) {
                if (is_dir($dir)) {
                    $files = glob($dir . 'kb_*');
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            unlink($file);
                            $files_cleared++;
                        }
                    }
                }
            }
            
            $success = "Cache cleared successfully! ($files_cleared files removed)";
        }
        
        elseif ($action === 'reset_stats') {
            // Reset article statistics
            $stmt = $pdo->query("UPDATE kb_articles SET view_count = 0, helpful_count = 0, not_helpful_count = 0");
            $stmt = $pdo->query("DELETE FROM kb_article_views");
            $stmt = $pdo->query("DELETE FROM kb_article_feedback");
            $success = "Article statistics reset successfully!";
        }
        
    } catch (Exception $e) {
        $error = "Error updating settings: " . $e->getMessage();
    }
}

// Load current settings
try {
    // First, check if kb_settings table exists and has correct structure
    $stmt = $pdo->query("SHOW TABLES LIKE 'kb_settings'");
    if (!$stmt->fetchColumn()) {
        // Create kb_settings table if it doesn't exist
        $pdo->exec("
            CREATE TABLE kb_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
        
        // Insert default settings
        $default_settings = [
            'kb_title' => 'Knowledge Base',
            'kb_description' => 'Find answers to frequently asked questions and get help with our services',
            'articles_per_page' => '12',
            'featured_articles_count' => '6',
            'recent_articles_count' => '8',
            'related_articles_count' => '4',
            'enable_article_rating' => '1',
            'show_article_author' => '1',
            'show_article_date' => '1',
            'show_view_count' => '1',
            'show_breadcrumbs' => '1',
            'enable_sitemap' => '1',
            'enable_rss_feed' => '1'
        ];
        
        foreach ($default_settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO kb_settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }
    }
    
    // Load settings
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM kb_settings");
    $settings_data = $stmt->fetchAll();
    
    $current_settings = [];
    foreach ($settings_data as $setting) {
        $current_settings[$setting['setting_key']] = $setting['setting_value'];
    }
    
    // Get KB statistics
    $stmt = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM kb_articles) as total_articles,
            (SELECT COUNT(*) FROM kb_articles WHERE status = 'published') as published_articles,
            (SELECT COUNT(*) FROM kb_articles WHERE status = 'draft') as draft_articles,
            (SELECT COUNT(*) FROM kb_categories WHERE is_active = 1) as active_categories,
            (SELECT COALESCE(SUM(view_count), 0) FROM kb_articles) as total_views,
            (SELECT COUNT(*) FROM kb_article_feedback) as total_feedback
    ");
    $stats = $stmt->fetch();
    
} catch (Exception $e) {
    $error = "Error loading settings: " . $e->getMessage();
    $current_settings = [];
    $stats = [
        'total_articles' => 0,
        'published_articles' => 0,
        'draft_articles' => 0,
        'active_categories' => 0,
        'total_views' => 0,
        'total_feedback' => 0
    ];
}

// Helper function to get setting value
function getSetting($key, $default = '') {
    global $current_settings;
    return $current_settings[$key] ?? $default;
}

$page_title = "Knowledge Base Settings";
?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

    <style>
        .settings-nav {
            position: sticky;
            top: 100px;
        }
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
        .danger-zone {
            border: 2px solid #dc3545;
            border-radius: 8px;
            padding: 1.5rem;
            background: #fff5f5;
        }
        .form-section {
            background: white;
            border-radius: 8px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .api-key-input {
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
        }
    </style>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1">
                            <i class="bi bi-gear text-primary me-2"></i>
                            Knowledge Base Settings
                        </h1>
                        <p class="text-muted mb-0">Configure your knowledge base system settings</p>
                    </div>
                    <div class="btn-group">
                        <a href="/operations/kb-dashboard.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>
                            Back to Dashboard
                        </a>
                        <a href="/kb/" class="btn btn-outline-info" target="_blank">
                            <i class="bi bi-eye me-1"></i>
                            View KB
                        </a>
                    </div>
                </div>

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

                <div class="row">
                    <!-- Sidebar Navigation -->
                    <div class="col-lg-3">
                        <div class="settings-nav">
                            <!-- Stats Card -->
                            <div class="card stats-card border-0 shadow-sm mb-4">
                                <div class="card-body">
                                    <h6 class="card-title text-center mb-3">KB Statistics</h6>
                                    <div class="row">
                                        <div class="col-6">
                                            <div class="stat-item">
                                                <span class="stat-number"><?= number_format($stats['total_articles'] ?? 0) ?></span>
                                                <small>Articles</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="stat-item">
                                                <span class="stat-number"><?= number_format($stats['active_categories'] ?? 0) ?></span>
                                                <small>Categories</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="stat-item">
                                                <span class="stat-number"><?= number_format($stats['total_views'] ?? 0) ?></span>
                                                <small>Views</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="stat-item">
                                                <span class="stat-number"><?= number_format($stats['published_articles'] ?? 0) ?></span>
                                                <small>Published</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Navigation -->
                            <div class="list-group">
                                <a href="#general" class="list-group-item list-group-item-action active" data-bs-toggle="list">
                                    <i class="bi bi-gear me-2"></i>
                                    General Settings
                                </a>
                                <a href="#display" class="list-group-item list-group-item-action" data-bs-toggle="list">
                                    <i class="bi bi-display me-2"></i>
                                    Display Settings
                                </a>
                                <a href="#editor" class="list-group-item list-group-item-action" data-bs-toggle="list">
                                    <i class="bi bi-pencil-square me-2"></i>
                                    Editor Settings
                                </a>
                                <a href="#seo" class="list-group-item list-group-item-action" data-bs-toggle="list">
                                    <i class="bi bi-search me-2"></i>
                                    SEO Settings
                                </a>
                                <a href="#notifications" class="list-group-item list-group-item-action" data-bs-toggle="list">
                                    <i class="bi bi-bell me-2"></i>
                                    Notifications
                                </a>
                                <a href="#maintenance" class="list-group-item list-group-item-action" data-bs-toggle="list">
                                    <i class="bi bi-tools me-2"></i>
                                    Maintenance
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Settings Content -->
                    <div class="col-lg-9">
                        <div class="tab-content">
                            <!-- General Settings -->
                            <div class="tab-pane fade show active" id="general">
                                <form method="POST" class="form-section">
                                    <input type="hidden" name="action" value="update_general">
                                    
                                    <h5 class="mb-4">
                                        <i class="bi bi-gear text-primary me-2"></i>
                                        General Settings
                                    </h5>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-floating mb-3">
                                                <input type="text" class="form-control" id="kb_title" name="kb_title" 
                                                       value="<?= htmlspecialchars(getSetting('kb_title', 'Knowledge Base')) ?>" 
                                                       placeholder="Knowledge Base Title" required>
                                                <label for="kb_title">Knowledge Base Title *</label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating mb-3">
                                                <input type="number" class="form-control" id="articles_per_page" name="articles_per_page" 
                                                       value="<?= getSetting('articles_per_page', '12') ?>" 
                                                       min="1" max="50">
                                                <label for="articles_per_page">Articles Per Page</label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-floating mb-3">
                                        <textarea class="form-control" id="kb_description" name="kb_description" 
                                                  style="height: 100px" placeholder="Knowledge Base Description"><?= htmlspecialchars(getSetting('kb_description', 'Find answers to frequently asked questions')) ?></textarea>
                                        <label for="kb_description">Knowledge Base Description</label>
                                    </div>

                                    <div class="form-floating mb-3">
                                        <textarea class="form-control" id="kb_footer_text" name="kb_footer_text" 
                                                  style="height: 80px" placeholder="Footer Text"><?= htmlspecialchars(getSetting('kb_footer_text', '')) ?></textarea>
                                        <label for="kb_footer_text">Footer Text</label>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Default Article Status</label>
                                                <select name="default_article_status" class="form-select">
                                                    <option value="draft" <?= getSetting('default_article_status', 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                                                    <option value="published" <?= getSetting('default_article_status') === 'published' ? 'selected' : '' ?>>Published</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Default Article Visibility</label>
                                                <select name="default_article_visibility" class="form-select">
                                                    <option value="public" <?= getSetting('default_article_visibility', 'public') === 'public' ? 'selected' : '' ?>>Public</option>
                                                    <option value="authenticated" <?= getSetting('default_article_visibility') === 'authenticated' ? 'selected' : '' ?>>Authenticated Users Only</option>
                                                    <option value="staff_only" <?= getSetting('default_article_visibility') === 'staff_only' ? 'selected' : '' ?>>Staff Only</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="enable_article_rating" name="enable_article_rating" 
                                                       <?= getSetting('enable_article_rating', '1') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="enable_article_rating">
                                                    Enable Article Rating
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="enable_article_comments" name="enable_article_comments" 
                                                       <?= getSetting('enable_article_comments', '0') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="enable_article_comments">
                                                    Enable Article Comments
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="require_login_for_feedback" name="require_login_for_feedback" 
                                                       <?= getSetting('require_login_for_feedback', '0') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="require_login_for_feedback">
                                                    Require Login for Feedback
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle me-1"></i>
                                            Save General Settings
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Display Settings -->
                            <div class="tab-pane fade" id="display">
                                <form method="POST" class="form-section">
                                    <input type="hidden" name="action" value="update_display">
                                    
                                    <h5 class="mb-4">
                                        <i class="bi bi-display text-primary me-2"></i>
                                        Display Settings
                                    </h5>

                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-floating mb-3">
                                                <input type="number" class="form-control" id="featured_articles_count" name="featured_articles_count" 
                                                       value="<?= getSetting('featured_articles_count', '6') ?>" min="1" max="20">
                                                <label for="featured_articles_count">Featured Articles Count</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-floating mb-3">
                                                <input type="number" class="form-control" id="recent_articles_count" name="recent_articles_count" 
                                                       value="<?= getSetting('recent_articles_count', '8') ?>" min="1" max="20">
                                                <label for="recent_articles_count">Recent Articles Count</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-floating mb-3">
                                                <input type="number" class="form-control" id="related_articles_count" name="related_articles_count" 
                                                       value="<?= getSetting('related_articles_count', '4') ?>" min="1" max="10">
                                                <label for="related_articles_count">Related Articles Count</label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="show_article_author" name="show_article_author" 
                                                       <?= getSetting('show_article_author', '1') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="show_article_author">
                                                    Show Article Author
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="show_article_date" name="show_article_date" 
                                                       <?= getSetting('show_article_date', '1') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="show_article_date">
                                                    Show Article Date
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="show_view_count" name="show_view_count" 
                                                       <?= getSetting('show_view_count', '1') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="show_view_count">
                                                    Show View Count
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="show_breadcrumbs" name="show_breadcrumbs" 
                                                       <?= getSetting('show_breadcrumbs', '1') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="show_breadcrumbs">
                                                    Show Breadcrumbs
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="enable_search_highlighting" name="enable_search_highlighting" 
                                                       <?= getSetting('enable_search_highlighting', '1') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="enable_search_highlighting">
                                                    Enable Search Highlighting
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="show_category_icons" name="show_category_icons" 
                                                       <?= getSetting('show_category_icons', '1') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="show_category_icons">
                                                    Show Category Icons
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle me-1"></i>
                                            Save Display Settings
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Editor Settings -->
                            <div class="tab-pane fade" id="editor">
                                <form method="POST" class="form-section">
                                    <input type="hidden" name="action" value="update_editor">
                                    
                                    <h5 class="mb-4">
                                        <i class="bi bi-pencil-square text-primary me-2"></i>
                                        Editor Settings
                                    </h5>

                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle me-2"></i>
                                        <strong>TinyMCE API Key:</strong> Get your free API key from 
                                        <a href="https://www.tiny.cloud/auth/signup/" target="_blank">TinyMCE Cloud</a> 
                                        for enhanced features like spell checking and premium plugins.
                                    </div>

                                    <div class="form-floating mb-3">
                                        <input type="text" class="form-control api-key-input" id="tinymce_api_key" name="tinymce_api_key" 
                                               value="<?= htmlspecialchars(getSetting('tinymce_api_key', '')) ?>" 
                                               placeholder="your-api-key-here">
                                        <label for="tinymce_api_key">TinyMCE API Key</label>
                                        <div class="form-text">
                                            Leave blank to use basic TinyMCE features. 
                                            <a href="https://www.tiny.cloud/docs/tinymce/6/editor-setup/" target="_blank">Learn more</a>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-floating mb-3">
                                                <input type="number" class="form-control" id="max_upload_size" name="max_upload_size" 
                                                       value="<?= getSetting('max_upload_size', '5') ?>" min="1" max="50">
                                                <label for="max_upload_size">Max Upload Size (MB)</label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating mb-3">
                                                <input type="text" class="form-control" id="allowed_file_types" name="allowed_file_types" 
                                                       value="<?= htmlspecialchars(getSetting('allowed_file_types', 'jpg,jpeg,png,gif,pdf,doc,docx')) ?>" 
                                                       placeholder="jpg,png,pdf">
                                                <label for="allowed_file_types">Allowed File Types</label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="enable_code_highlighting" name="enable_code_highlighting" 
                                                       <?= getSetting('enable_code_highlighting', '1') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="enable_code_highlighting">
                                                    Enable Code Highlighting
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="enable_media_upload" name="enable_media_upload" 
                                                       <?= getSetting('enable_media_upload', '1') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="enable_media_upload">
                                                    Enable Media Upload
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle me-1"></i>
                                            Save Editor Settings
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- SEO Settings -->
                            <div class="tab-pane fade" id="seo">
                                <form method="POST" class="form-section">
                                    <input type="hidden" name="action" value="update_seo">
                                    
                                    <h5 class="mb-4">
                                        <i class="bi bi-search text-primary me-2"></i>
                                        SEO Settings
                                    </h5>

                                    <div class="form-floating mb-3">
                                        <input type="text" class="form-control" id="kb_meta_title" name="kb_meta_title" 
                                               value="<?= htmlspecialchars(getSetting('kb_meta_title', '')) ?>" 
                                               placeholder="Meta Title" maxlength="60">
                                        <label for="kb_meta_title">Meta Title</label>
                                    </div>

                                    <div class="form-floating mb-3">
                                        <textarea class="form-control" id="kb_meta_description" name="kb_meta_description" 
                                                  style="height: 100px" placeholder="Meta Description" maxlength="160"><?= htmlspecialchars(getSetting('kb_meta_description', '')) ?></textarea>
                                        <label for="kb_meta_description">Meta Description</label>
                                    </div>

                                    <div class="form-floating mb-3">
                                        <input type="text" class="form-control" id="kb_meta_keywords" name="kb_meta_keywords" 
                                               value="<?= htmlspecialchars(getSetting('kb_meta_keywords', '')) ?>" 
                                               placeholder="keyword1, keyword2, keyword3">
                                        <label for="kb_meta_keywords">Meta Keywords</label>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-floating mb-3">
                                                <input type="text" class="form-control" id="google_analytics_id" name="google_analytics_id" 
                                                       value="<?= htmlspecialchars(getSetting('google_analytics_id', '')) ?>" 
                                                       placeholder="GA-XXXXXXXXX-X">
                                                <label for="google_analytics_id">Google Analytics ID</label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating mb-3">
                                                <input type="text" class="form-control" id="facebook_pixel_id" name="facebook_pixel_id" 
                                                       value="<?= htmlspecialchars(getSetting('facebook_pixel_id', '')) ?>" 
                                                       placeholder="Facebook Pixel ID">
                                                <label for="facebook_pixel_id">Facebook Pixel ID</label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="enable_sitemap" name="enable_sitemap" 
                                                       <?= getSetting('enable_sitemap', '1') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="enable_sitemap">
                                                    Enable XML Sitemap
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="enable_rss_feed" name="enable_rss_feed" 
                                                       <?= getSetting('enable_rss_feed', '1') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="enable_rss_feed">
                                                    Enable RSS Feed
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle me-1"></i>
                                            Save SEO Settings
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Notifications -->
                            <div class="tab-pane fade" id="notifications">
                                <form method="POST" class="form-section">
                                    <input type="hidden" name="action" value="update_notifications">
                                    
                                    <h5 class="mb-4">
                                        <i class="bi bi-bell text-primary me-2"></i>
                                        Notification Settings
                                    </h5>

                                    <div class="form-floating mb-3">
                                        <input type="email" class="form-control" id="notification_email" name="notification_email" 
                                               value="<?= htmlspecialchars(getSetting('notification_email', '')) ?>" 
                                               placeholder="admin@example.com">
                                        <label for="notification_email">Notification Email</label>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-floating mb-3">
                                                <input type="number" class="form-control" id="feedback_threshold" name="feedback_threshold" 
                                                       value="<?= getSetting('feedback_threshold', '3') ?>" min="1" max="10">
                                                <label for="feedback_threshold">Low Rating Alert Threshold</label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating mb-3">
                                                <input type="number" class="form-control" id="review_reminder_days" name="review_reminder_days" 
                                                       value="<?= getSetting('review_reminder_days', '90') ?>" min="1" max="365">
                                                <label for="review_reminder_days">Review Reminder (Days)</label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="notify_new_articles" name="notify_new_articles" 
                                                       <?= getSetting('notify_new_articles', '1') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="notify_new_articles">
                                                    Notify on New Articles
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="notify_article_feedback" name="notify_article_feedback" 
                                                       <?= getSetting('notify_article_feedback', '1') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="notify_article_feedback">
                                                    Notify on Article Feedback
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="notify_low_ratings" name="notify_low_ratings" 
                                                       <?= getSetting('notify_low_ratings', '1') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="notify_low_ratings">
                                                    Notify on Low Ratings
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle me-1"></i>
                                            Save Notification Settings
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Maintenance -->
                            <div class="tab-pane fade" id="maintenance">
                                <div class="form-section">
                                    <h5 class="mb-4">
                                        <i class="bi bi-tools text-primary me-2"></i>
                                        Maintenance & Tools
                                    </h5>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="card">
                                                <div class="card-body">
                                                    <h6 class="card-title">
                                                        <i class="bi bi-arrow-clockwise me-2"></i>
                                                        Clear Cache
                                                    </h6>
                                                    <p class="card-text small text-muted">
                                                        Clear cached data to improve performance and reflect recent changes.
                                                    </p>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="clear_cache">
                                                        <button type="submit" class="btn btn-outline-primary btn-sm">
                                                            <i class="bi bi-arrow-clockwise me-1"></i>
                                                            Clear Cache
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card">
                                                <div class="card-body">
                                                    <h6 class="card-title">
                                                        <i class="bi bi-download me-2"></i>
                                                        Export Data
                                                    </h6>
                                                    <p class="card-text small text-muted">
                                                        Download a backup of your knowledge base articles and settings.
                                                    </p>
                                                    <a href="/operations/kb-export.php" class="btn btn-outline-info btn-sm">
                                                        <i class="bi bi-download me-1"></i>
                                                        Export KB
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Danger Zone -->
                                    <div class="danger-zone mt-4">
                                        <h6 class="text-danger">
                                            <i class="bi bi-exclamation-triangle me-2"></i>
                                            Danger Zone
                                        </h6>
                                        <p class="text-muted mb-3">
                                            These actions are permanent and cannot be undone. Please use with caution.
                                        </p>
                                        
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to reset all article statistics? This cannot be undone.')">
                                            <input type="hidden" name="action" value="reset_stats">
                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                <i class="bi bi-arrow-counterclockwise me-1"></i>
                                                Reset Statistics
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Character counters for SEO fields
        document.addEventListener('DOMContentLoaded', function() {
            const metaTitle = document.getElementById('kb_meta_title');
            const metaDescription = document.getElementById('kb_meta_description');
            
            if (metaTitle) {
                metaTitle.addEventListener('input', function() {
                    updateCharCount(this, 60);
                });
                updateCharCount(metaTitle, 60);
            }
            
            if (metaDescription) {
                metaDescription.addEventListener('input', function() {
                    updateCharCount(this, 160);
                });
                updateCharCount(metaDescription, 160);
            }
        });

        function updateCharCount(element, maxLength) {
            const length = element.value.length;
            let countElement = element.parentNode.querySelector('.char-count');
            
            if (!countElement) {
                countElement = document.createElement('div');
                countElement.className = 'char-count text-muted small mt-1';
                element.parentNode.appendChild(countElement);
            }
            
            countElement.textContent = `${length}/${maxLength} characters`;
            
            if (length > maxLength * 0.9) {
                countElement.className = 'char-count text-danger small mt-1';
            } else if (length > maxLength * 0.8) {
                countElement.className = 'char-count text-warning small mt-1';
            } else {
                countElement.className = 'char-count text-muted small mt-1';
            }
        }
    </script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>