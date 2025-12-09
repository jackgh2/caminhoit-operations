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

// Check permissions - only administrators can change blog settings
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_role = $stmt->fetchColumn();

if (!in_array($user_role, ['administrator'])) {
    header('Location: /dashboard.php');
    exit;
}

$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $settings = [
            'blog_title' => $_POST['blog_title'] ?? '',
            'blog_description' => $_POST['blog_description'] ?? '',
            'posts_per_page' => (int)($_POST['posts_per_page'] ?? 10),
            'allow_comments' => isset($_POST['allow_comments']) ? '1' : '0',
            'moderate_comments' => isset($_POST['moderate_comments']) ? '1' : '0',
            'tinymce_api_key' => $_POST['tinymce_api_key'] ?? '',
            'upload_max_size' => (int)($_POST['upload_max_size'] ?? 10485760),
            'allowed_file_types' => $_POST['allowed_file_types'] ?? 'jpg,jpeg,png,gif,pdf,doc,docx',
            'auto_publish_scheduled' => isset($_POST['auto_publish_scheduled']) ? '1' : '0',
            'seo_enabled' => isset($_POST['seo_enabled']) ? '1' : '0',
            'blog_timezone' => $_POST['blog_timezone'] ?? 'UTC',
            'posts_excerpt_length' => (int)($_POST['posts_excerpt_length'] ?? 150),
            'enable_rss' => isset($_POST['enable_rss']) ? '1' : '0',
            'blog_footer_text' => $_POST['blog_footer_text'] ?? '',
        ];
        
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO blog_settings (setting_key, setting_value, setting_type, updated_by) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            setting_value = VALUES(setting_value),
            updated_by = VALUES(updated_by),
            updated_at = NOW()
        ");
        
        foreach ($settings as $key => $value) {
            $type = is_numeric($value) ? 'number' : (in_array($value, ['0', '1']) ? 'boolean' : 'string');
            $stmt->execute([$key, $value, $type, $user['id']]);
        }
        
        $pdo->commit();
        
        $message = "Blog settings updated successfully!";
        $message_type = 'success';
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error updating settings: " . $e->getMessage();
        $message_type = 'danger';
    }
}

// Get current settings
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM blog_settings");
$stmt->execute();
$current_settings = [];
while ($row = $stmt->fetch()) {
    $current_settings[$row['setting_key']] = $row['setting_value'];
}

// Default values
$defaults = [
    'blog_title' => 'My Blog',
    'blog_description' => 'Welcome to my blog',
    'posts_per_page' => '10',
    'allow_comments' => '1',
    'moderate_comments' => '1',
    'tinymce_api_key' => '',
    'upload_max_size' => '10485760',
    'allowed_file_types' => 'jpg,jpeg,png,gif,pdf,doc,docx',
    'auto_publish_scheduled' => '1',
    'seo_enabled' => '1',
    'blog_timezone' => 'UTC',
    'posts_excerpt_length' => '150',
    'enable_rss' => '1',
    'blog_footer_text' => '',
];

// Merge current settings with defaults
$settings = array_merge($defaults, $current_settings);

$page_title = "Blog Settings | Admin";
?>

<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">
    
    <style>
        body {
            background-color: #f8fafc;
            padding-top: 80px;
        }
        .main-container {
            max-width: 1000px;
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
        .settings-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e5e7eb;
        }
        .form-label {
            font-weight: 600;
            color: #374151;
        }
        .form-control, .form-select {
            border: 1px solid #d1d5db;
            border-radius: 6px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
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
                <h1><i class="bi bi-gear me-3"></i>Blog Settings</h1>
                <p class="text-muted mb-0">Configure your blog settings and preferences</p>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST">
        <!-- General Settings -->
        <div class="settings-section">
            <h3 class="section-title"><i class="bi bi-gear me-2"></i>General Settings</h3>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="blog_title" class="form-label">Blog Title *</label>
                        <input type="text" class="form-control" id="blog_title" name="blog_title" 
                               value="<?= htmlspecialchars($settings['blog_title']) ?>" 
                               required maxlength="255">
                        <div class="form-text">The main title of your blog</div>
                    </div>

                    <div class="mb-3">
                        <label for="blog_description" class="form-label">Blog Description</label>
                        <textarea class="form-control" id="blog_description" name="blog_description" 
                                  rows="3" maxlength="500"><?= htmlspecialchars($settings['blog_description']) ?></textarea>
                        <div class="form-text">A brief description for SEO and site tagline</div>
                    </div>

                    <div class="mb-3">
                        <label for="blog_timezone" class="form-label">Timezone</label>
                        <select class="form-select" id="blog_timezone" name="blog_timezone">
                            <option value="UTC" <?= $settings['blog_timezone'] === 'UTC' ? 'selected' : '' ?>>UTC</option>
                            <option value="America/New_York" <?= $settings['blog_timezone'] === 'America/New_York' ? 'selected' : '' ?>>Eastern Time</option>
                            <option value="America/Chicago" <?= $settings['blog_timezone'] === 'America/Chicago' ? 'selected' : '' ?>>Central Time</option>
                            <option value="America/Denver" <?= $settings['blog_timezone'] === 'America/Denver' ? 'selected' : '' ?>>Mountain Time</option>
                            <option value="America/Los_Angeles" <?= $settings['blog_timezone'] === 'America/Los_Angeles' ? 'selected' : '' ?>>Pacific Time</option>
                            <option value="Europe/London" <?= $settings['blog_timezone'] === 'Europe/London' ? 'selected' : '' ?>>London</option>
                            <option value="Europe/Paris" <?= $settings['blog_timezone'] === 'Europe/Paris' ? 'selected' : '' ?>>Paris</option>
                            <option value="Asia/Tokyo" <?= $settings['blog_timezone'] === 'Asia/Tokyo' ? 'selected' : '' ?>>Tokyo</option>
                        </select>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="posts_per_page" class="form-label">Posts Per Page</label>
                        <input type="number" class="form-control" id="posts_per_page" name="posts_per_page" 
                               value="<?= htmlspecialchars($settings['posts_per_page']) ?>" 
                               min="1" max="50">
                        <div class="form-text">Number of posts to show on blog homepage</div>
                    </div>

                    <div class="mb-3">
                        <label for="posts_excerpt_length" class="form-label">Excerpt Length</label>
                        <input type="number" class="form-control" id="posts_excerpt_length" name="posts_excerpt_length" 
                               value="<?= htmlspecialchars($settings['posts_excerpt_length']) ?>" 
                               min="50" max="500">
                        <div class="form-text">Number of characters for auto-generated excerpts</div>
                    </div>

                    <div class="mb-3">
                        <label for="blog_footer_text" class="form-label">Footer Text</label>
                        <textarea class="form-control" id="blog_footer_text" name="blog_footer_text" 
                                  rows="2" maxlength="200"><?= htmlspecialchars($settings['blog_footer_text']) ?></textarea>
                        <div class="form-text">Custom text to display in blog footer</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content & Editor Settings -->
        <div class="settings-section">
            <h3 class="section-title"><i class="bi bi-pencil-square me-2"></i>Content & Editor</h3>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="tinymce_api_key" class="form-label">TinyMCE API Key</label>
                        <input type="text" class="form-control" id="tinymce_api_key" name="tinymce_api_key" 
                               value="<?= htmlspecialchars($settings['tinymce_api_key']) ?>" 
                               placeholder="your-tinymce-api-key">
                        <div class="form-text">Get your free API key from <a href="https://www.tiny.cloud/" target="_blank">TinyMCE Cloud</a></div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="auto_publish_scheduled" name="auto_publish_scheduled" 
                                   <?= $settings['auto_publish_scheduled'] === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="auto_publish_scheduled">
                                Auto-publish Scheduled Posts
                            </label>
                        </div>
                        <div class="form-text">Automatically publish posts when their scheduled time arrives</div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="allow_comments" name="allow_comments" 
                                   <?= $settings['allow_comments'] === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="allow_comments">
                                Allow Comments on Posts
                            </label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="moderate_comments" name="moderate_comments" 
                                   <?= $settings['moderate_comments'] === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="moderate_comments">
                                Moderate Comments Before Publishing
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- File Upload Settings -->
        <div class="settings-section">
            <h3 class="section-title"><i class="bi bi-cloud-upload me-2"></i>File Upload</h3>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="upload_max_size" class="form-label">Maximum Upload Size (bytes)</label>
                        <input type="number" class="form-control" id="upload_max_size" name="upload_max_size" 
                               value="<?= htmlspecialchars($settings['upload_max_size']) ?>" 
                               min="1048576" max="104857600">
                        <div class="form-text">
                            Current: <?= formatFileSize($settings['upload_max_size']) ?>
                            (Server limit: <?= formatFileSize(getServerUploadLimit()) ?>)
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="allowed_file_types" class="form-label">Allowed File Types</label>
                        <input type="text" class="form-control" id="allowed_file_types" name="allowed_file_types" 
                               value="<?= htmlspecialchars($settings['allowed_file_types']) ?>">
                        <div class="form-text">Comma-separated list (e.g., jpg,png,pdf,doc)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SEO Settings -->
        <div class="settings-section">
            <h3 class="section-title"><i class="bi bi-search me-2"></i>SEO & Features</h3>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="seo_enabled" name="seo_enabled" 
                                   <?= $settings['seo_enabled'] === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="seo_enabled">
                                Enable SEO Features
                            </label>
                        </div>
                        <div class="form-text">Enable meta tags, structured data, and SEO optimizations</div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="enable_rss" name="enable_rss" 
                                   <?= $settings['enable_rss'] === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="enable_rss">
                                Enable RSS Feed
                            </label>
                        </div>
                        <div class="form-text">Provide RSS feed for blog posts at /blog/rss.xml</div>
                    </div>
                </div>

                <div class="col-md-6">
                    <!-- Future feature placeholders -->
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="enable_search" name="enable_search" disabled>
                            <label class="form-check-label text-muted" for="enable_search">
                                Enable Blog Search (Coming Soon)
                            </label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="enable_social_sharing" name="enable_social_sharing" disabled>
                            <label class="form-check-label text-muted" for="enable_social_sharing">
                                Enable Social Sharing (Coming Soon)
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Save Button -->
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <small class="text-muted">
                    <i class="bi bi-info-circle"></i> 
                    Changes will take effect immediately. Some settings may require a page refresh.
                </small>
            </div>
            <div>
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-check-circle me-2"></i>Save Settings
                </button>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// File size converter
document.getElementById('upload_max_size').addEventListener('input', function() {
    const bytes = parseInt(this.value);
    const formText = this.parentNode.querySelector('.form-text');
    if (bytes && !isNaN(bytes)) {
        const formatted = formatFileSize(bytes);
        formText.innerHTML = formText.innerHTML.replace(/Current: [^(]+/, 'Current: ' + formatted + ' ');
    }
});

function formatFileSize(bytes) {
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (bytes >= 1024 && i < units.length - 1) {
        bytes /= 1024;
        i++;
    }
    return Math.round(bytes * 10) / 10 + ' ' + units[i];
}

// Auto-save indication
let saveTimeout;
document.querySelectorAll('input, textarea, select').forEach(element => {
    element.addEventListener('change', function() {
        clearTimeout(saveTimeout);
        // Could implement auto-save here
    });
});
</script>

</body>
</html>

<?php
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

function getServerUploadLimit() {
    $max_upload = (int)(ini_get('upload_max_filesize'));
    $max_post = (int)(ini_get('post_max_size'));
    $memory_limit = (int)(ini_get('memory_limit'));
    return min($max_upload, $max_post, $memory_limit) * 1024 * 1024;
}
?>