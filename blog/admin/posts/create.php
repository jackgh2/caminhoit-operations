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

// Check permissions
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_role = $stmt->fetchColumn();

if (!in_array($user_role, ['administrator', 'support_user', 'support_technician'])) {
    header('Location: /dashboard.php');
    exit;
}

$message = '';
$message_type = '';
$form_data = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_data = $_POST;
    $errors = [];
    
    // Validate required fields
    if (empty($_POST['title'])) {
        $errors[] = "Post title is required.";
    }
    
    if (empty($_POST['content'])) {
        $errors[] = "Post content is required.";
    }
    
    // Generate slug if not provided
    $slug = !empty($_POST['slug']) ? $_POST['slug'] : strtolower(str_replace([' ', '.', ','], '-', $_POST['title']));
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    
    // Check if slug already exists
    if (!empty($slug)) {
        $stmt = $pdo->prepare("SELECT id FROM blog_posts WHERE slug = ?");
        $stmt->execute([$slug]);
        if ($stmt->fetch()) {
            $slug .= '-' . time(); // Make it unique
        }
    }
    
    // Validate scheduled date
    $scheduled_at = null;
    if ($_POST['status'] === 'scheduled' && !empty($_POST['scheduled_at'])) {
        $scheduled_at = $_POST['scheduled_at'];
        if (strtotime($scheduled_at) <= time()) {
            $errors[] = "Scheduled date must be in the future.";
        }
    }

    // Handle custom published date (backdate/future date)
    $published_at = null;
    if ($_POST['status'] === 'published' && !empty($_POST['published_at'])) {
        $published_at = $_POST['published_at'];
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Insert post
            $stmt = $pdo->prepare("
                INSERT INTO blog_posts (
                    title, slug, content, excerpt, author_id, category_id,
                    featured_image, image_display_full, image_zoom, status, scheduled_at, published_at, meta_title, meta_description,
                    is_featured, allow_comments
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $_POST['title'],
                $slug,
                $_POST['content'],
                $_POST['excerpt'] ?: null,
                $user['id'],
                $_POST['category_id'] ?: null,
                $_POST['featured_image'] ?: null,
                isset($_POST['image_display_full']) ? 1 : 0,
                !empty($_POST['image_zoom']) ? (int)$_POST['image_zoom'] : 100,
                $_POST['status'],
                $scheduled_at,
                $published_at,
                $_POST['meta_title'] ?: null,
                $_POST['meta_description'] ?: null,
                isset($_POST['is_featured']) ? 1 : 0,
                isset($_POST['allow_comments']) ? 1 : 0
            ]);
            
            $post_id = $pdo->lastInsertId();
            
            // Handle tags
            if (!empty($_POST['tags'])) {
                $tags = array_map('trim', explode(',', $_POST['tags']));
                $tag_stmt = $pdo->prepare("INSERT INTO blog_post_tags (post_id, tag_name, tag_slug) VALUES (?, ?, ?)");

                foreach ($tags as $tag) {
                    if (!empty($tag)) {
                        // Generate slug from tag name
                        $tag_slug = strtolower(str_replace([' ', '.', ','], '-', $tag));
                        $tag_slug = preg_replace('/[^a-z0-9\-]/', '', $tag_slug);
                        $tag_slug = preg_replace('/\-+/', '-', $tag_slug);
                        $tag_slug = trim($tag_slug, '-');

                        $tag_stmt->execute([$post_id, $tag, $tag_slug]);
                    }
                }
            }
            
            $pdo->commit();
            
            $message = "Post created successfully!";
            $message_type = 'success';
            
            // Redirect to edit page for further editing
            header("Location: edit.php?id=$post_id&success=1");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Error creating post: " . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $message = implode('<br>', $errors);
        $message_type = 'danger';
    }
}

// Get categories for dropdown
$stmt = $pdo->prepare("SELECT id, name FROM blog_categories WHERE is_active = 1 ORDER BY name");
$stmt->execute();
$categories = $stmt->fetchAll();

// Get TinyMCE API key from settings
$stmt = $pdo->prepare("SELECT setting_value FROM blog_settings WHERE setting_key = 'tinymce_api_key'");
$stmt->execute();
$tinymce_api_key = $stmt->fetchColumn() ?: 'your-tinymce-api-key-here';

$page_title = "Create Post | Blog Admin";
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
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }
        .main-content, .sidebar {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 2rem;
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
        .tox-tinymce {
            border-radius: 6px !important;
        }
        .btn-save-draft {
            background: #6b7280;
            border: none;
        }
        .btn-save-draft:hover {
            background: #4b5563;
        }
        .autosave-indicator {
            font-size: 0.875rem;
            color: #6b7280;
        }
        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
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
                <h1><i class="bi bi-plus-circle me-3"></i>Create New Post</h1>
                <p class="text-muted mb-0">Write and publish your blog content</p>
                <div class="autosave-indicator mt-2" id="autosaveStatus">
                    <i class="bi bi-cloud-check text-success"></i> Auto-save enabled
                </div>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Posts
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

    <form method="POST" id="postForm">
        <div class="content-grid">
            <!-- Main Content -->
            <div class="main-content">
                <div class="mb-3">
                    <label for="title" class="form-label">Post Title *</label>
                    <input type="text" class="form-control form-control-lg" id="title" name="title" 
                           value="<?= htmlspecialchars($form_data['title'] ?? '') ?>" 
                           required maxlength="255" placeholder="Enter your post title...">
                </div>

                <div class="mb-3">
                    <label for="slug" class="form-label">URL Slug</label>
                    <div class="input-group">
                        <span class="input-group-text">/blog/post/</span>
                        <input type="text" class="form-control" id="slug" name="slug" 
                               value="<?= htmlspecialchars($form_data['slug'] ?? '') ?>" 
                               maxlength="255" placeholder="auto-generated">
                    </div>
                    <div class="form-text">Leave blank to auto-generate from title</div>
                </div>

                <div class="mb-3">
                    <label for="content" class="form-label">Content *</label>
                    <textarea id="content" name="content" rows="20"><?= htmlspecialchars($form_data['content'] ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                    <label for="excerpt" class="form-label">Excerpt</label>
                    <textarea class="form-control" id="excerpt" name="excerpt" rows="3" 
                              maxlength="500" placeholder="Brief description of your post..."><?= htmlspecialchars($form_data['excerpt'] ?? '') ?></textarea>
                    <div class="form-text">Optional summary for post listings and SEO</div>
                </div>

                <!-- SEO Section -->
                <div class="border-top pt-4 mt-4">
                    <h5 class="mb-3">SEO Settings</h5>
                    
                    <div class="mb-3">
                        <label for="meta_title" class="form-label">Meta Title</label>
                        <input type="text" class="form-control" id="meta_title" name="meta_title" 
                               value="<?= htmlspecialchars($form_data['meta_title'] ?? '') ?>" 
                               maxlength="60" placeholder="SEO-optimized title">
                        <div class="form-text">Recommended: 50-60 characters</div>
                    </div>

                    <div class="mb-3">
                        <label for="meta_description" class="form-label">Meta Description</label>
                        <textarea class="form-control" id="meta_description" name="meta_description" 
                                  rows="2" maxlength="160" placeholder="SEO description for search engines"><?= htmlspecialchars($form_data['meta_description'] ?? '') ?></textarea>
                        <div class="form-text">Recommended: 150-160 characters</div>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="sidebar">
                <!-- Publish Box -->
                <div class="mb-4">
                    <h5 class="mb-3">Publish</h5>
                    
                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="draft" <?= ($form_data['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= ($form_data['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                            <option value="scheduled" <?= ($form_data['status'] ?? '') === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                        </select>
                    </div>

                    <div class="mb-3" id="scheduledDateDiv" style="display: none;">
                        <label for="scheduled_at" class="form-label">Publish Date & Time</label>
                        <input type="datetime-local" class="form-control" id="scheduled_at" name="scheduled_at"
                               value="<?= htmlspecialchars($form_data['scheduled_at'] ?? '') ?>">
                    </div>

                    <div class="mb-3" id="publishedDateDiv" style="display: none;">
                        <label for="published_at" class="form-label">Published Date & Time</label>
                        <input type="datetime-local" class="form-control" id="published_at" name="published_at"
                               value="<?= htmlspecialchars($form_data['published_at'] ?? '') ?>">
                        <div class="form-text">Set a custom publish date (backdate or future date)</div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" id="publishBtn">
                            <i class="bi bi-send me-2"></i>Publish
                        </button>
                        <button type="button" class="btn btn-save-draft text-white" onclick="saveDraft()">
                            <i class="bi bi-save me-2"></i>Save Draft
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="previewPost()">
                            <i class="bi bi-eye me-2"></i>Preview
                        </button>
                    </div>
                </div>

                <!-- Category -->
                <div class="mb-4">
                    <h5 class="mb-3">Category</h5>
                    <select class="form-select" name="category_id">
                        <option value="">Uncategorized</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= $category['id'] ?>" 
                                    <?= ($form_data['category_id'] ?? '') == $category['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Tags -->
                <div class="mb-4">
                    <h5 class="mb-3">Tags</h5>
                    <input type="text" class="form-control" name="tags" 
                           value="<?= htmlspecialchars($form_data['tags'] ?? '') ?>" 
                           placeholder="tag1, tag2, tag3">
                    <div class="form-text">Separate tags with commas</div>
                </div>

                <!-- Featured Image -->
                <div class="mb-4">
                    <h5 class="mb-3">Featured Image</h5>
                    <div id="featuredImagePreview" class="mb-2" style="display: none;">
                        <img id="featuredImageImg" src="" alt="Featured Image" class="img-fluid rounded" style="max-height: 150px;">
                        <button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="removeFeaturedImage()">
                            <i class="bi bi-trash"></i> Remove
                        </button>
                    </div>
                    <input type="hidden" id="featured_image" name="featured_image" value="<?= htmlspecialchars($form_data['featured_image'] ?? '') ?>">
                    <button type="button" class="btn btn-outline-primary btn-sm w-100 mb-3" onclick="selectFeaturedImage()">
                        <i class="bi bi-image me-2"></i>Select Featured Image
                    </button>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="image_display_full" name="image_display_full" value="1"
                               <?= ($form_data['image_display_full'] ?? false) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="image_display_full">
                            <i class="bi bi-arrows-angle-expand"></i> Show Full Image
                        </label>
                        <div class="form-text">When checked, shows entire image without cropping. Otherwise, fits to container (may crop).</div>
                    </div>

                    <div class="mb-2">
                        <label for="image_zoom" class="form-label">
                            <i class="bi bi-zoom-in"></i> Image Zoom Level: <span id="zoomValue">100</span>%
                        </label>
                        <input type="range" class="form-range" id="image_zoom" name="image_zoom"
                               min="50" max="200" step="5" value="<?= htmlspecialchars($form_data['image_zoom'] ?? 100) ?>"
                               oninput="document.getElementById('zoomValue').textContent = this.value">
                        <div class="form-text">Adjust image size: 50% (zoom out) to 200% (zoom in). Default is 100%.</div>
                    </div>
                </div>

                <!-- Post Options -->
                <div class="mb-4">
                    <h5 class="mb-3">Options</h5>
                    
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured" 
                               <?= ($form_data['is_featured'] ?? false) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_featured">
                            Featured Post
                        </label>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="allow_comments" name="allow_comments" 
                               <?= ($form_data['allow_comments'] ?? true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="allow_comments">
                            Allow Comments
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmModalTitle">Confirm Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="confirmModalBody">
                Are you sure you want to proceed?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmModalConfirm">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- Success/Alert Modal -->
<div class="modal fade" id="alertModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="alertModalTitle">Notice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="alertModalBody">
                Message
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- TinyMCE -->
<script src="https://cdn.tiny.cloud/1/<?= $tinymce_api_key ?>/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Custom Modal Functions
function showConfirm(title, message, onConfirm, confirmBtnText = 'Confirm', confirmBtnClass = 'btn-primary') {
    document.getElementById('confirmModalTitle').textContent = title;
    document.getElementById('confirmModalBody').innerHTML = message;

    const confirmBtn = document.getElementById('confirmModalConfirm');
    confirmBtn.textContent = confirmBtnText;
    confirmBtn.className = 'btn ' + confirmBtnClass;

    // Remove old event listeners by cloning
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

    newConfirmBtn.addEventListener('click', function() {
        bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide();
        if (onConfirm) onConfirm();
    });

    new bootstrap.Modal(document.getElementById('confirmModal')).show();
}

function showAlert(title, message, type = 'info') {
    const iconMap = {
        'success': '<i class="bi bi-check-circle-fill text-success me-2"></i>',
        'error': '<i class="bi bi-exclamation-circle-fill text-danger me-2"></i>',
        'warning': '<i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>',
        'info': '<i class="bi bi-info-circle-fill text-primary me-2"></i>'
    };

    document.getElementById('alertModalTitle').innerHTML = iconMap[type] + title;
    document.getElementById('alertModalBody').innerHTML = message;

    new bootstrap.Modal(document.getElementById('alertModal')).show();
}

// TinyMCE Configuration
tinymce.init({
    selector: '#content',
    height: 500,
    menubar: false,
    plugins: [
        'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
        'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
        'insertdatetime', 'media', 'table', 'help', 'wordcount', 'autosave'
    ],
    toolbar: 'undo redo | blocks | bold italic forecolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat | image media link | code preview fullscreen | help',
    content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 16px; line-height: 1.6; }',
    
    // Auto-save configuration
    autosave_ask_before_unload: true,
    autosave_interval: '30s',
    autosave_prefix: 'blog-post-new-',
    
    // Image upload handler
    images_upload_handler: function (blobInfo, success, failure, progress) {
        const xhr = new XMLHttpRequest();
        xhr.withCredentials = false;
        xhr.open('POST', '/blog/admin/api/upload-image.php');
        
        xhr.upload.onprogress = function (e) {
            progress(e.loaded / e.total * 100);
        };
        
        xhr.onload = function() {
            if (xhr.status === 403) {
                failure('HTTP Error: ' + xhr.status, { remove: true });
                return;
            }
            
            if (xhr.status < 200 || xhr.status >= 300) {
                failure('HTTP Error: ' + xhr.status);
                return;
            }
            
            const json = JSON.parse(xhr.responseText);
            
            if (!json || typeof json.location != 'string') {
                failure('Invalid JSON: ' + xhr.responseText);
                return;
            }
            
            success(json.location);
        };
        
        xhr.onerror = function () {
            failure('Image upload failed due to a XHR Transport error. Code: ' + xhr.status);
        };
        
        const formData = new FormData();
        formData.append('file', blobInfo.blob(), blobInfo.filename());
        
        xhr.send(formData);
    },
    
    // File picker for media library
    file_picker_callback: function (callback, value, meta) {
        if (meta.filetype === 'image') {
            openMediaLibrary(callback);
        }
    },
    
    setup: function (editor) {
        editor.on('change', function () {
            updateWordCount();
        });
    }
});

// Auto-generate slug from title
document.getElementById('title').addEventListener('input', function() {
    const slugField = document.getElementById('slug');
    if (!slugField.dataset.manualEdit) {
        const slug = this.value
            .toLowerCase()
            .replace(/[^a-z0-9\s\-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/\-+/g, '-')
            .replace(/^\-|\-$/g, '');
        slugField.value = slug;
    }
});

document.getElementById('slug').addEventListener('input', function() {
    this.dataset.manualEdit = 'true';
});

// Status change handler
document.getElementById('status').addEventListener('change', function() {
    const scheduledDiv = document.getElementById('scheduledDateDiv');
    const publishedDiv = document.getElementById('publishedDateDiv');
    const publishBtn = document.getElementById('publishBtn');

    if (this.value === 'scheduled') {
        scheduledDiv.style.display = 'block';
        publishedDiv.style.display = 'none';
        publishBtn.innerHTML = '<i class="bi bi-clock me-2"></i>Schedule';
    } else if (this.value === 'published') {
        scheduledDiv.style.display = 'none';
        publishedDiv.style.display = 'block';
        publishBtn.innerHTML = '<i class="bi bi-send me-2"></i>Publish';
    } else {
        scheduledDiv.style.display = 'none';
        publishedDiv.style.display = 'none';
        publishBtn.innerHTML = '<i class="bi bi-save me-2"></i>Save Draft';
    }
});

// Initialize scheduled date visibility
document.addEventListener('DOMContentLoaded', function() {
    const statusSelect = document.getElementById('status');
    statusSelect.dispatchEvent(new Event('change'));
});

// Auto-save functionality
let autoSaveTimer;
let hasUnsavedChanges = false;

function markAsChanged() {
    hasUnsavedChanges = true;
    clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(autoSave, 30000); // Auto-save after 30 seconds of inactivity
}

// Track changes
document.addEventListener('input', markAsChanged);

function autoSave() {
    if (!hasUnsavedChanges) return;
    
    const formData = new FormData(document.getElementById('postForm'));
    formData.append('action', 'autosave');
    
    fetch('/blog/admin/api/autosave.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('autosaveStatus').innerHTML = 
                '<i class="bi bi-cloud-check text-success"></i> Auto-saved at ' + new Date().toLocaleTimeString();
            hasUnsavedChanges = false;
        }
    })
    .catch(error => {
        console.error('Auto-save failed:', error);
        document.getElementById('autosaveStatus').innerHTML = 
            '<i class="bi bi-cloud-slash text-warning"></i> Auto-save failed';
    });
}

function saveDraft() {
    document.getElementById('status').value = 'draft';
    document.getElementById('postForm').submit();
}

function previewPost() {
    // Save current content to session storage for preview
    const formData = new FormData(document.getElementById('postForm'));
    
    fetch('/blog/admin/api/preview-save.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.open('/blog/admin/preview.php?token=' + data.token, '_blank');
        }
    });
}

function selectFeaturedImage() {
    // Open media library modal
    openMediaLibrary(function(url) {
        document.getElementById('featured_image').value = url;
        document.getElementById('featuredImageImg').src = url;
        document.getElementById('featuredImagePreview').style.display = 'block';
    });
}

function removeFeaturedImage() {
    document.getElementById('featured_image').value = '';
    document.getElementById('featuredImagePreview').style.display = 'none';
}

// Media Library Modal
let mediaCallback = null;

function openMediaLibrary(callback) {
    mediaCallback = callback;

    // Create modal
    const modalHtml = `
        <div class="modal fade" id="mediaLibraryModal" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Select or Upload Image</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="height: 70vh;">
                        <iframe src="${window.location.origin}/blog/admin/media/modal.php" style="width: 100%; height: 100%; border: none;" allow="same-origin"></iframe>
                    </div>
                </div>
            </div>
        </div>
    `;

    // Remove existing modal if any
    const existingModal = document.getElementById('mediaLibraryModal');
    if (existingModal) {
        existingModal.remove();
    }

    // Add modal to page
    document.body.insertAdjacentHTML('beforeend', modalHtml);

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('mediaLibraryModal'));
    modal.show();
}

function closeMediaLibrary() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('mediaLibraryModal'));
    if (modal) {
        modal.hide();
    }
}

function handleMediaSelection(url) {
    if (mediaCallback) {
        mediaCallback(url);
        mediaCallback = null;
    }
    closeMediaLibrary();
}

function updateWordCount() {
    // This would be implemented with TinyMCE's word count plugin
}

// Character counters for SEO fields
function updateCharCount(inputId, maxLength, displayId) {
    const input = document.getElementById(inputId);
    const display = document.getElementById(displayId);
    
    function update() {
        const length = input.value.length;
        const remaining = maxLength - length;
        display.textContent = `${length}/${maxLength} characters`;
        display.style.color = remaining < 10 ? '#dc3545' : (remaining < 20 ? '#f59e0b' : '#6b7280');
    }
    
    input.addEventListener('input', update);
    update();
}

// Initialize character counters
document.addEventListener('DOMContentLoaded', function() {
    const metaTitleDiv = document.querySelector('#meta_title').parentNode.querySelector('.form-text');
    metaTitleDiv.innerHTML += ' <span id="metaTitleCount"></span>';
    updateCharCount('meta_title', 60, 'metaTitleCount');
    
    const metaDescDiv = document.querySelector('#meta_description').parentNode.querySelector('.form-text');
    metaDescDiv.innerHTML += ' <span id="metaDescCount"></span>';
    updateCharCount('meta_description', 160, 'metaDescCount');
});

// Clear unsaved changes flag when form is submitted
document.getElementById('postForm').addEventListener('submit', function() {
    hasUnsavedChanges = false;
});

// Prevent accidental navigation away
window.addEventListener('beforeunload', function (e) {
    if (hasUnsavedChanges) {
        e.preventDefault();
        e.returnValue = '';
    }
});
</script>

</body>
</html>