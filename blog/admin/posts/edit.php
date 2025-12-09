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

$post_id = $_GET['id'] ?? 0;
$message = '';
$message_type = '';

// Get post data
$stmt = $pdo->prepare("
    SELECT p.*, u.username as author_name,
           (SELECT GROUP_CONCAT(tag_name) FROM blog_post_tags WHERE post_id = p.id) as tags
    FROM blog_posts p
    LEFT JOIN users u ON p.author_id = u.id
    WHERE p.id = ?
");
$stmt->execute([$post_id]);
$post = $stmt->fetch();

if (!$post) {
    header('Location: index.php?error=Post not found');
    exit;
}

// Check if user can edit this post
if ($post['author_id'] != $user['id'] && !in_array($user_role, ['administrator'])) {
    header('Location: index.php?error=Permission denied');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    // Validate required fields
    if (empty($_POST['title'])) {
        $errors[] = "Post title is required.";
    }
    
    if (empty($_POST['content'])) {
        $errors[] = "Post content is required.";
    }
    
    // Generate slug if changed
    $slug = !empty($_POST['slug']) ? $_POST['slug'] : strtolower(str_replace([' ', '.', ','], '-', $_POST['title']));
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    
    // Check if slug already exists (excluding current post)
    if (!empty($slug) && $slug !== $post['slug']) {
        $stmt = $pdo->prepare("SELECT id FROM blog_posts WHERE slug = ? AND id != ?");
        $stmt->execute([$slug, $post_id]);
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
            
            // Create revision before updating
            if ($_POST['title'] !== $post['title'] || $_POST['content'] !== $post['content'] || $_POST['excerpt'] !== $post['excerpt']) {
                $stmt = $pdo->prepare("
                    INSERT INTO blog_post_revisions (post_id, title, content, excerpt, revision_note, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $post_id,
                    $post['title'],
                    $post['content'],
                    $post['excerpt'],
                    $_POST['revision_note'] ?? 'Manual save',
                    $user['id']
                ]);
            }
            
            // Update post
            $stmt = $pdo->prepare("
                UPDATE blog_posts
                SET title = ?, slug = ?, content = ?, excerpt = ?, category_id = ?,
                    featured_image = ?, image_display_full = ?, image_zoom = ?, status = ?, scheduled_at = ?, published_at = ?, meta_title = ?, meta_description = ?,
                    is_featured = ?, allow_comments = ?, updated_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $_POST['title'],
                $slug,
                $_POST['content'],
                $_POST['excerpt'] ?: null,
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
                isset($_POST['allow_comments']) ? 1 : 0,
                $post_id
            ]);
            
            // Update tags
            $pdo->prepare("DELETE FROM blog_post_tags WHERE post_id = ?")->execute([$post_id]);

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
            
            $message = "Post updated successfully!";
            $message_type = 'success';
            
            // Refresh post data
            $stmt = $pdo->prepare("
                SELECT p.*, u.username as author_name,
                       (SELECT GROUP_CONCAT(tag_name) FROM blog_post_tags WHERE post_id = p.id) as tags
                FROM blog_posts p
                LEFT JOIN users u ON p.author_id = u.id
                WHERE p.id = ?
            ");
            $stmt->execute([$post_id]);
            $post = $stmt->fetch();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Error updating post: " . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $message = implode('<br>', $errors);
        $message_type = 'danger';
    }
} else {
    // Pre-fill form with existing data
    $_POST = $post;
}

// Get categories for dropdown
$stmt = $pdo->prepare("SELECT id, name FROM blog_categories WHERE is_active = 1 ORDER BY name");
$stmt->execute();
$categories = $stmt->fetchAll();

// Get post attachments
$stmt = $pdo->prepare("
    SELECT * FROM blog_post_attachments 
    WHERE post_id = ? 
    ORDER BY sort_order ASC, created_at DESC
");
$stmt->execute([$post_id]);
$attachments = $stmt->fetchAll();

// Get recent revisions
$stmt = $pdo->prepare("
    SELECT r.*, u.username as created_by_name
    FROM blog_post_revisions r
    LEFT JOIN users u ON r.created_by = u.id
    WHERE r.post_id = ?
    ORDER BY r.created_at DESC
    LIMIT 10
");
$stmt->execute([$post_id]);
$revisions = $stmt->fetchAll();

// Get TinyMCE API key
$stmt = $pdo->prepare("SELECT setting_value FROM blog_settings WHERE setting_key = 'tinymce_api_key'");
$stmt->execute();
$tinymce_api_key = $stmt->fetchColumn() ?: 'your-tinymce-api-key-here';

$page_title = "Edit Post: " . htmlspecialchars($post['title']) . " | Blog Admin";
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
        .content-layout {
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
        .attachment-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        .attachment-preview {
            width: 50px;
            height: 50px;
            border-radius: 6px;
            overflow: hidden;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .attachment-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .revision-item {
            border-left: 3px solid #e5e7eb;
            padding-left: 1rem;
            margin-bottom: 1rem;
        }
        .revision-item:hover {
            border-left-color: #3b82f6;
            background: #f8fafc;
        }
        .autosave-indicator {
            font-size: 0.875rem;
            color: #6b7280;
        }
        @media (max-width: 768px) {
            .content-layout {
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
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h1><i class="bi bi-pencil-square me-3"></i>Edit Post</h1>
                <p class="text-muted mb-1">Editing: <strong><?= htmlspecialchars($post['title']) ?></strong></p>
                <div class="d-flex gap-3 text-muted small">
                    <span><i class="bi bi-person"></i> <?= htmlspecialchars($post['author_name']) ?></span>
                    <span><i class="bi bi-calendar"></i> Created: <?= date('M j, Y g:i A', strtotime($post['created_at'])) ?></span>
                    <span><i class="bi bi-clock"></i> Updated: <?= date('M j, Y g:i A', strtotime($post['updated_at'])) ?></span>
                    <span><i class="bi bi-eye"></i> Views: <?= number_format($post['view_count']) ?></span>
                </div>
                <div class="autosave-indicator mt-2" id="autosaveStatus">
                    <i class="bi bi-cloud-check text-success"></i> Auto-save enabled
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Posts
                </a>
                <?php if ($post['status'] === 'published'): ?>
                    <a href="/blog/post/<?= $post['slug'] ?>" class="btn btn-outline-info" target="_blank">
                        <i class="bi bi-eye me-2"></i>View Live
                    </a>
                <?php else: ?>
                    <button class="btn btn-outline-info" onclick="previewPost()">
                        <i class="bi bi-eye me-2"></i>Preview
                    </button>
                <?php endif; ?>
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
        <input type="hidden" name="post_id" value="<?= $post_id ?>">
        
        <div class="content-layout">
            <!-- Main Content -->
            <div class="main-content">
                <div class="mb-3">
                    <label for="title" class="form-label">Post Title *</label>
                    <input type="text" class="form-control form-control-lg" id="title" name="title" 
                           value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" 
                           required maxlength="255">
                </div>

                <div class="mb-3">
                    <label for="slug" class="form-label">URL Slug</label>
                    <div class="input-group">
                        <span class="input-group-text">/blog/post/</span>
                        <input type="text" class="form-control" id="slug" name="slug" 
                               value="<?= htmlspecialchars($_POST['slug'] ?? '') ?>" 
                               maxlength="255">
                    </div>
                </div>

                <div class="mb-3">
                    <label for="content" class="form-label">Content *</label>
                    <textarea id="content" name="content" rows="20"><?= htmlspecialchars($_POST['content'] ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                    <label for="excerpt" class="form-label">Excerpt</label>
                    <textarea class="form-control" id="excerpt" name="excerpt" rows="3" 
                              maxlength="500"><?= htmlspecialchars($_POST['excerpt'] ?? '') ?></textarea>
                </div>

                <!-- Attachments Section -->
                <?php if (!empty($attachments)): ?>
                    <div class="mb-4">
                        <h5 class="mb-3">Attachments (<?= count($attachments) ?>)</h5>
                        <?php foreach ($attachments as $attachment): ?>
                            <div class="attachment-item">
                                <div class="attachment-preview">
                                    <?php if ($attachment['attachment_type'] === 'image'): ?>
                                        <img src="<?= htmlspecialchars($attachment['file_path']) ?>" alt="<?= htmlspecialchars($attachment['alt_text']) ?>">
                                    <?php else: ?>
                                        <i class="bi bi-file-text text-muted"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-bold"><?= htmlspecialchars($attachment['original_filename']) ?></div>
                                    <small class="text-muted">
                                        <?= strtoupper($attachment['attachment_type']) ?> • 
                                        <?= formatFileSize($attachment['file_size']) ?> • 
                                        <?= date('M j, Y', strtotime($attachment['created_at'])) ?>
                                    </small>
                                </div>
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= htmlspecialchars($attachment['file_path']) ?>" class="btn btn-outline-primary" target="_blank">
                                        <i class="bi bi-download"></i>
                                    </a>
                                    <button type="button" class="btn btn-outline-danger" onclick="deleteAttachment(<?= $attachment['id'] ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- SEO Section -->
                <div class="border-top pt-4 mt-4">
                    <h5 class="mb-3">SEO Settings</h5>
                    
                    <div class="mb-3">
                        <label for="meta_title" class="form-label">Meta Title</label>
                        <input type="text" class="form-control" id="meta_title" name="meta_title" 
                               value="<?= htmlspecialchars($_POST['meta_title'] ?? '') ?>" 
                               maxlength="60">
                    </div>

                    <div class="mb-3">
                        <label for="meta_description" class="form-label">Meta Description</label>
                        <textarea class="form-control" id="meta_description" name="meta_description" 
                                  rows="2" maxlength="160"><?= htmlspecialchars($_POST['meta_description'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Revision Note -->
                <div class="border-top pt-4 mt-4">
                    <div class="mb-3">
                        <label for="revision_note" class="form-label">Revision Note (Optional)</label>
                        <input type="text" class="form-control" id="revision_note" name="revision_note" 
                               placeholder="Describe what you changed..." maxlength="255">
                        <div class="form-text">Help track changes in revision history</div>
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
                            <option value="draft" <?= ($_POST['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= ($_POST['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                            <option value="scheduled" <?= ($_POST['status'] ?? '') === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                            <option value="archived" <?= ($_POST['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archived</option>
                        </select>
                    </div>

                    <div class="mb-3" id="scheduledDateDiv" style="display: none;">
                        <label for="scheduled_at" class="form-label">Scheduled Date & Time</label>
                        <input type="datetime-local" class="form-control" id="scheduled_at" name="scheduled_at"
                               value="<?= htmlspecialchars($_POST['scheduled_at'] ?? '') ?>">
                    </div>

                    <div class="mb-3" id="publishedDateDiv" style="display: none;">
                        <label for="published_at" class="form-label">Published Date & Time</label>
                        <input type="datetime-local" class="form-control" id="published_at" name="published_at"
                               value="<?= !empty($_POST['published_at']) ? date('Y-m-d\TH:i', strtotime($_POST['published_at'])) : '' ?>">
                        <div class="form-text">Set a custom publish date (backdate or future date)</div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" id="updateBtn">
                            <i class="bi bi-check-circle me-2"></i>Update Post
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="saveDraft()">
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
                                    <?= ($_POST['category_id'] ?? '') == $category['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Tags -->
                <div class="mb-4">
                    <h5 class="mb-3">Tags</h5>
                    <input type="text" class="form-control" name="tags" 
                           value="<?= htmlspecialchars($_POST['tags'] ?? '') ?>" 
                           placeholder="tag1, tag2, tag3">
                </div>

                <!-- Featured Image -->
                <div class="mb-4">
                    <h5 class="mb-3">Featured Image</h5>
                    <div id="featuredImagePreview" class="mb-2" style="<?= !empty($_POST['featured_image']) ? '' : 'display: none;' ?>">
                        <img id="featuredImageImg" src="<?= htmlspecialchars($_POST['featured_image'] ?? '') ?>" alt="Featured Image" class="img-fluid rounded" style="max-height: 150px;">
                        <button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="removeFeaturedImage()">
                            <i class="bi bi-trash"></i> Remove
                        </button>
                    </div>
                    <input type="hidden" id="featured_image" name="featured_image" value="<?= htmlspecialchars($_POST['featured_image'] ?? '') ?>">
                    <button type="button" class="btn btn-outline-primary btn-sm w-100 mb-3" onclick="selectFeaturedImage()">
                        <i class="bi bi-image me-2"></i><?= !empty($_POST['featured_image']) ? 'Change' : 'Select' ?> Featured Image
                    </button>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="image_display_full" name="image_display_full" value="1"
                               <?= ($_POST['image_display_full'] ?? false) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="image_display_full">
                            <i class="bi bi-arrows-angle-expand"></i> Show Full Image
                        </label>
                        <div class="form-text">When checked, shows entire image without cropping. Otherwise, fits to container (may crop).</div>
                    </div>

                    <div class="mb-2">
                        <label for="image_zoom" class="form-label">
                            <i class="bi bi-zoom-in"></i> Image Zoom Level: <span id="zoomValue"><?= htmlspecialchars($_POST['image_zoom'] ?? 100) ?></span>%
                        </label>
                        <input type="range" class="form-range" id="image_zoom" name="image_zoom"
                               min="50" max="200" step="5" value="<?= htmlspecialchars($_POST['image_zoom'] ?? 100) ?>"
                               oninput="document.getElementById('zoomValue').textContent = this.value">
                        <div class="form-text">Adjust image size: 50% (zoom out) to 200% (zoom in). Default is 100%.</div>
                    </div>
                </div>

                <!-- Post Options -->
                <div class="mb-4">
                    <h5 class="mb-3">Options</h5>
                    
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured" 
                               <?= ($_POST['is_featured'] ?? false) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_featured">
                            Featured Post
                        </label>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="allow_comments" name="allow_comments" 
                               <?= ($_POST['allow_comments'] ?? true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="allow_comments">
                            Allow Comments
                        </label>
                    </div>
                </div>

                <!-- Revisions -->
                <?php if (!empty($revisions)): ?>
                    <div class="mb-4">
                        <h5 class="mb-3">Recent Revisions</h5>
                        <div style="max-height: 300px; overflow-y: auto;">
                            <?php foreach ($revisions as $revision): ?>
                                <div class="revision-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <small class="text-muted">
                                            <?= date('M j, Y g:i A', strtotime($revision['created_at'])) ?>
                                        </small>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="restoreRevision(<?= $revision['id'] ?>)">
                                            Restore
                                        </button>
                                    </div>
                                    <div class="fw-bold"><?= htmlspecialchars($revision['title']) ?></div>
                                    <?php if ($revision['revision_note']): ?>
                                        <small class="text-info"><?= htmlspecialchars($revision['revision_note']) ?></small><br>
                                    <?php endif; ?>
                                    <small class="text-muted">by <?= htmlspecialchars($revision['created_by_name']) ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
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
    
    autosave_ask_before_unload: true,
    autosave_interval: '30s',
    autosave_prefix: 'blog-post-<?= $post_id ?>-',
    
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
    
    setup: function (editor) {
        editor.on('change', function () {
            markAsChanged();
        });
    }
});

// Status change handler
document.getElementById('status').addEventListener('change', function() {
    const scheduledDiv = document.getElementById('scheduledDateDiv');
    const publishedDiv = document.getElementById('publishedDateDiv');
    const updateBtn = document.getElementById('updateBtn');

    if (this.value === 'scheduled') {
        scheduledDiv.style.display = 'block';
        publishedDiv.style.display = 'none';
        updateBtn.innerHTML = '<i class="bi bi-clock me-2"></i>Schedule Post';
    } else if (this.value === 'published') {
        scheduledDiv.style.display = 'none';
        publishedDiv.style.display = 'block';
        updateBtn.innerHTML = '<i class="bi bi-send me-2"></i>Publish Post';
    } else {
        scheduledDiv.style.display = 'none';
        publishedDiv.style.display = 'none';
        updateBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Update Post';
    }
});

// Initialize on load
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('status').dispatchEvent(new Event('change'));
    
    // Show featured image if exists
    const featuredImage = document.getElementById('featured_image').value;
    if (featuredImage) {
        document.getElementById('featuredImagePreview').style.display = 'block';
    }
});

// Auto-save functionality
let autoSaveTimer;
let hasUnsavedChanges = false;

function markAsChanged() {
    hasUnsavedChanges = true;
    clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(autoSave, 30000);
}

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

function deleteAttachment(id) {
    showConfirm(
        'Delete Attachment',
        'Are you sure you want to delete this attachment? This action cannot be undone.',
        function() {
            fetch('/blog/admin/api/delete-attachment.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('Success', 'Attachment deleted successfully!', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('Error', 'Error deleting attachment: ' + data.error, 'error');
                }
            });
        },
        'Delete',
        'btn-danger'
    );
}

function restoreRevision(revisionId) {
    showConfirm(
        'Restore Revision',
        'Are you sure you want to restore this revision? This will replace the current content with the selected revision.',
        function() {
            window.location.href = 'restore-revision.php?id=' + revisionId + '&post_id=<?= $post_id ?>';
        },
        'Restore',
        'btn-warning'
    );
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

// Clear unsaved changes flag when form is submitted
document.getElementById('postForm').addEventListener('submit', function() {
    hasUnsavedChanges = false;
});

// Prevent accidental navigation
window.addEventListener('beforeunload', function (e) {
    if (hasUnsavedChanges) {
        e.preventDefault();
        e.returnValue = '';
    }
});

// Helper function for file size formatting
function formatFileSize(bytes) {
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (bytes >= 1024 && i < units.length - 1) {
        bytes /= 1024;
        i++;
    }
    return Math.round(bytes * 10) / 10 + ' ' + units[i];
}
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
?>