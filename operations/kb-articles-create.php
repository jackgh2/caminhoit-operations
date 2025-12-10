<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Access control (Staff and Admin only)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'support_consultant', 'accountant'])) {
    header('Location: /login.php');
    exit;
}

// Get TinyMCE API key from settings
$tinymce_api_key = 'no-api-key';
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM kb_settings WHERE setting_key = 'tinymce_api_key'");
    $stmt->execute();
    $api_key = $stmt->fetchColumn();
    if (!empty($api_key)) {
        $tinymce_api_key = $api_key;
    }
} catch (Exception $e) {
    // Use default if error
}

// Initialize article data for new article
$article = [
    'title' => '',
    'slug' => '',
    'content' => '',
    'excerpt' => '',
    'category_id' => '',
    'status' => 'draft',
    'visibility' => 'public',
    'featured' => false,
    'meta_title' => '',
    'meta_description' => '',
    'search_keywords' => '',
    'tags' => ''
];

// Handle duplication if requested
if (isset($_GET['duplicate']) && !empty($_GET['duplicate'])) {
    try {
        $duplicate_id = (int)$_GET['duplicate'];
        $stmt = $pdo->prepare("SELECT * FROM kb_articles WHERE id = ?");
        $stmt->execute([$duplicate_id]);
        $duplicate_article = $stmt->fetch();
        
        if ($duplicate_article) {
            $article = array_merge($article, $duplicate_article);
            $article['title'] = $duplicate_article['title'] . ' (Copy)';
            $article['slug'] = $duplicate_article['slug'] . '-copy';
            $article['status'] = 'draft';
            $article['featured'] = false;
            
            // Load tags for duplication
            $stmt = $pdo->prepare("SELECT tag_name FROM kb_article_tags WHERE article_id = ?");
            $stmt->execute([$duplicate_id]);
            $tags = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $article['tags'] = implode(', ', $tags);
            
            $info_message = "Article duplicated! Please review and update the title and content as needed.";
        }
    } catch (Exception $e) {
        $error = "Error duplicating article: " . $e->getMessage();
    }
}

// Handle form submission
if ($_POST) {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $excerpt = trim($_POST['excerpt'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $status = $_POST['status'] ?? 'draft';
    $visibility = $_POST['visibility'] ?? 'public';
    $featured = isset($_POST['featured']) ? 1 : 0;
    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    $search_keywords = trim($_POST['search_keywords'] ?? '');
    $tags_input = trim($_POST['tags'] ?? '');
    
    // Generate slug from title if not provided
    $slug = trim($_POST['slug'] ?? '');
    if (empty($slug)) {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
        $slug = preg_replace('/-+/', '-', $slug); // Remove multiple hyphens
        $slug = trim($slug, '-'); // Remove leading/trailing hyphens
    }
    
    // Validation
    $errors = [];
    
    if (empty($title)) {
        $errors[] = "Title is required.";
    }
    
    if (empty($content)) {
        $errors[] = "Content is required.";
    }
    
    if (empty($category_id)) {
        $errors[] = "Category is required.";
    }
    
    if (empty($slug)) {
        $errors[] = "Slug is required.";
    } else {
        // Check if slug is unique
        $stmt = $pdo->prepare("SELECT id FROM kb_articles WHERE slug = ?");
        $stmt->execute([$slug]);
        if ($stmt->fetchColumn()) {
            $errors[] = "Slug already exists. Please choose a different one.";
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Create new article
            $stmt = $pdo->prepare("
                INSERT INTO kb_articles 
                (title, slug, content, excerpt, category_id, status, visibility, 
                 featured, meta_title, meta_description, search_keywords, author_id,
                 published_at, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        CASE WHEN ? = 'published' THEN NOW() ELSE NULL END,
                        NOW(), NOW())
            ");
            $stmt->execute([
                $title, $slug, $content, $excerpt, $category_id, $status, 
                $visibility, $featured, $meta_title, $meta_description, 
                $search_keywords, $_SESSION['user']['id'], $status
            ]);
            
            $article_id = $pdo->lastInsertId();
            
            // Handle tags
            if (!empty($tags_input)) {
                $tags = array_map('trim', explode(',', $tags_input));
                $tags = array_filter($tags); // Remove empty tags
                
                foreach ($tags as $tag) {
                    if (!empty($tag)) {
                        $stmt = $pdo->prepare("INSERT IGNORE INTO kb_article_tags (article_id, tag_name) VALUES (?, ?)");
                        $stmt->execute([$article_id, $tag]);
                    }
                }
            }
            
            $pdo->commit();
            
            // Redirect to edit page with success message
            header("Location: /operations/kb-articles-edit.php?id=$article_id&success=1");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error creating article: " . $e->getMessage();
        }
    }
}

// Load categories
try {
    $stmt = $pdo->query("SELECT id, name, color FROM kb_categories WHERE is_active = 1 ORDER BY sort_order, name");
    $categories = $stmt->fetchAll();
} catch (Exception $e) {
    $categories = [];
    $error = "Error loading categories: " . $e->getMessage();
}

$page_title = "Create New Article";
?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

    <!-- Dynamic TinyMCE Loading -->
    <?php if ($tinymce_api_key !== 'no-api-key'): ?>
        <!-- Use TinyMCE Cloud with API key -->
        <script src="https://cdn.tiny.cloud/1/<?= htmlspecialchars($tinymce_api_key) ?>/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <?php else: ?>
        <!-- Use free TinyMCE from CDN -->
        <script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
    <?php endif; ?>

    <style>
        .form-floating textarea {
            min-height: 100px;
        }
        .meta-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1rem;
        }
        .char-counter {
            font-size: 0.875rem;
            color: #6c757d;
        }
        .char-counter.warning {
            color: #ffc107;
        }
        .char-counter.danger {
            color: #dc3545;
        }
        .draft-notice {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .category-preview {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            color: white;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        .api-status {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 0.5rem;
        }
        .api-status.pro {
            color: #28a745;
        }

        /* Dark Mode Styles */
        :root.dark body {
            background: #0f172a !important;
        }

        :root.dark .meta-section {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .card {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        :root.dark .card-header {
            background: #1e293b !important;
            border-bottom-color: #334155 !important;
            color: #f1f5f9 !important;
        }

        :root.dark .card-body {
            background: #1e293b !important;
            color: #e2e8f0 !important;
        }

        :root.dark .form-label {
            color: #cbd5e1 !important;
        }

        :root.dark .form-control,
        :root.dark .form-select {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .form-control:focus,
        :root.dark .form-select:focus {
            background: #1e293b !important;
            border-color: #8b5cf6 !important;
            color: #e2e8f0 !important;
        }

        :root.dark .char-counter {
            color: #94a3b8 !important;
        }

        :root.dark .api-status {
            color: #94a3b8 !important;
        }

        :root.dark h1,
        :root.dark h2,
        :root.dark h3,
        :root.dark h4,
        :root.dark h5,
        :root.dark h6 {
            color: #f1f5f9 !important;
        }

        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark .form-check-input {
            background-color: #0f172a !important;
            border-color: #334155 !important;
        }

        :root.dark .form-check-input:checked {
            background-color: #8b5cf6 !important;
            border-color: #8b5cf6 !important;
        }

        :root.dark .form-check-label {
            color: #cbd5e1 !important;
        }
    </style>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1">
                            <i class="bi bi-plus-circle text-primary me-2"></i>
                            <?= $page_title ?>
                        </h1>
                        <p class="text-muted mb-0">
                            Create a new knowledge base article to help your users
                            <span class="api-status <?= $tinymce_api_key !== 'no-api-key' ? 'pro' : '' ?>">
                                <i class="bi bi-<?= $tinymce_api_key !== 'no-api-key' ? 'check-circle' : 'info-circle' ?> me-1"></i>
                                <?= $tinymce_api_key !== 'no-api-key' ? 'TinyMCE Pro Features Active' : 'Using Basic TinyMCE' ?>
                            </span>
                        </p>
                    </div>
                    <div class="btn-group">
                        <a href="/operations/kb-articles.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>
                            Back to Articles
                        </a>
                        <a href="/operations/kb-dashboard.php" class="btn btn-outline-info">
                            <i class="bi bi-grid me-1"></i>
                            Dashboard
                        </a>
                        <?php if ($tinymce_api_key === 'no-api-key'): ?>
                            <a href="/operations/kb-settings.php#editor" class="btn btn-outline-warning btn-sm">
                                <i class="bi bi-gear me-1"></i>
                                Configure API Key
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Draft Notice -->
                <div class="draft-notice">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-info-circle me-2" style="font-size: 1.2rem;"></i>
                        <div>
                            <strong>Draft Mode</strong><br>
                            <small>Your article will be saved as a draft. You can publish it when ready.</small>
                        </div>
                    </div>
                </div>

                <?php if ($tinymce_api_key === 'no-api-key'): ?>
                    <div class="alert alert-info alert-dismissible fade show">
                        <i class="bi bi-lightbulb me-2"></i>
                        <strong>Pro Tip:</strong> Set up your TinyMCE API key in 
                        <a href="/operations/kb-settings.php#editor" class="alert-link">Editor Settings</a> 
                        to unlock spell checking, premium plugins, and enhanced features.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($info_message)): ?>
                    <div class="alert alert-info alert-dismissible fade show">
                        <i class="bi bi-info-circle me-2"></i>
                        <?= htmlspecialchars($info_message) ?>
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

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Please fix the following errors:</strong>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($errors as $error_msg): ?>
                                <li><?= htmlspecialchars($error_msg) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" class="row" id="articleForm">
                    <!-- Main Content -->
                    <div class="col-lg-8">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <!-- Title -->
                                <div class="form-floating mb-3">
                                    <input type="text" class="form-control" id="title" name="title" 
                                           value="<?= htmlspecialchars($article['title']) ?>" 
                                           placeholder="Article title" required maxlength="255"
                                           oninput="updateSlug(); updateMetaTitle(); countChars('title', 255)">
                                    <label for="title">Article Title *</label>
                                    <div class="char-counter mt-1" id="title-counter">0/255</div>
                                </div>

                                <!-- Slug -->
                                <div class="form-floating mb-3">
                                    <input type="text" class="form-control" id="slug" name="slug" 
                                           value="<?= htmlspecialchars($article['slug']) ?>" 
                                           placeholder="article-slug" pattern="[a-z0-9\-]+" 
                                           title="Only lowercase letters, numbers, and hyphens allowed">
                                    <label for="slug">URL Slug *</label>
                                    <div class="form-text">
                                        Used in the article URL. Leave blank to auto-generate from title.
                                        <br><strong>Preview:</strong> <span id="url-preview">/kb/article-slug</span>
                                    </div>
                                </div>

                                <!-- Excerpt -->
                                <div class="form-floating mb-3">
                                    <textarea class="form-control" id="excerpt" name="excerpt" 
                                              style="height: 100px" placeholder="Brief description"
                                              maxlength="500" oninput="countChars('excerpt', 500)"><?= htmlspecialchars($article['excerpt']) ?></textarea>
                                    <label for="excerpt">Excerpt</label>
                                    <div class="char-counter mt-1" id="excerpt-counter">0/500</div>
                                    <div class="form-text">Brief description shown in search results and article listings.</div>
                                </div>

                                <!-- Content -->
                                <div class="mb-3">
                                    <label for="content" class="form-label">
                                        Content *
                                        <?php if ($tinymce_api_key !== 'no-api-key'): ?>
                                            <span class="badge bg-success ms-2">Pro Editor</span>
                                        <?php endif; ?>
                                    </label>
                                    <textarea id="content" name="content" class="form-control"><?= htmlspecialchars($article['content']) ?></textarea>
                                </div>

                                <!-- Tags -->
                                <div class="form-floating mb-3">
                                    <input type="text" class="form-control" id="tags" name="tags" 
                                           value="<?= htmlspecialchars($article['tags']) ?>" 
                                           placeholder="tag1, tag2, tag3">
                                    <label for="tags">Tags</label>
                                    <div class="form-text">
                                        Separate multiple tags with commas. Example: tutorial, getting-started, basics
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- SEO Section -->
                        <div class="meta-section">
                            <h6 class="fw-bold mb-3">
                                <i class="bi bi-search me-2"></i>
                                SEO & Meta Information
                                <small class="text-muted fw-normal">(Optional but recommended)</small>
                            </h6>
                            
                            <div class="form-floating mb-3">
                                <input type="text" class="form-control" id="meta_title" name="meta_title" 
                                       value="<?= htmlspecialchars($article['meta_title']) ?>" 
                                       placeholder="SEO title" maxlength="60"
                                       oninput="countChars('meta_title', 60)">
                                <label for="meta_title">Meta Title</label>
                                <div class="char-counter mt-1" id="meta_title-counter">0/60</div>
                                <div class="form-text">Shown in search engine results. Leave blank to use article title.</div>
                            </div>

                            <div class="form-floating mb-3">
                                <textarea class="form-control" id="meta_description" name="meta_description" 
                                          style="height: 80px" placeholder="Meta description"
                                          maxlength="160" oninput="countChars('meta_description', 160)"><?= htmlspecialchars($article['meta_description']) ?></textarea>
                                <label for="meta_description">Meta Description</label>
                                <div class="char-counter mt-1" id="meta_description-counter">0/160</div>
                                <div class="form-text">Description shown in search engine results.</div>
                            </div>

                            <div class="form-floating">
                                <input type="text" class="form-control" id="search_keywords" name="search_keywords" 
                                       value="<?= htmlspecialchars($article['search_keywords']) ?>" 
                                       placeholder="keyword1, keyword2, keyword3">
                                <label for="search_keywords">Search Keywords</label>
                                <div class="form-text">Additional keywords to help users find this article.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="col-lg-4">
                        <!-- Publish Box -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-white border-0">
                                <h6 class="mb-0">
                                    <i class="bi bi-send me-2"></i>
                                    Publishing Options
                                </h6>
                            </div>
                            <div class="card-body">
                                <!-- Status -->
                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select" id="status-select">
                                        <option value="draft" <?= $article['status'] === 'draft' ? 'selected' : '' ?>>
                                            📝 Draft - Save for later
                                        </option>
                                        <option value="published" <?= $article['status'] === 'published' ? 'selected' : '' ?>>
                                            🌐 Published - Live immediately
                                        </option>
                                    </select>
                                </div>

                                <!-- Visibility -->
                                <div class="mb-3">
                                    <label class="form-label">Visibility</label>
                                    <select name="visibility" class="form-select">
                                        <option value="public" <?= $article['visibility'] === 'public' ? 'selected' : '' ?>>
                                            🌍 Public - Everyone can see
                                        </option>
                                        <option value="authenticated" <?= $article['visibility'] === 'authenticated' ? 'selected' : '' ?>>
                                            👤 Authenticated Users Only
                                        </option>
                                        <option value="staff_only" <?= $article['visibility'] === 'staff_only' ? 'selected' : '' ?>>
                                            🔒 Staff Only
                                        </option>
                                    </select>
                                </div>

                                <!-- Featured -->
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="featured" name="featured" 
                                           <?= $article['featured'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="featured">
                                        <i class="bi bi-star me-1"></i>
                                        Featured Article
                                        <div class="form-text">Show in featured section</div>
                                    </label>
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="bi bi-plus-circle me-1"></i>
                                        Create Article
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="saveDraft()">
                                        <i class="bi bi-bookmark me-1"></i>
                                        Save as Draft
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Category -->
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white border-0">
                                <h6 class="mb-0">
                                    <i class="bi bi-tags me-2"></i>
                                    Category *
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if (empty($categories)): ?>
                                    <div class="alert alert-warning">
                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                        No categories available. 
                                        <a href="/operations/kb-categories.php" target="_blank">Create one first</a>.
                                    </div>
                                <?php else: ?>
                                    <select name="category_id" class="form-select" required id="category-select" onchange="updateCategoryPreview()">
                                        <option value="">Choose category...</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?= $category['id'] ?>" 
                                                    data-color="<?= htmlspecialchars($category['color']) ?>"
                                                    <?= $article['category_id'] == $category['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($category['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="category-preview"></div>
                                <?php endif; ?>
                                
                                <div class="form-text mt-2">
                                    <a href="/operations/kb-categories.php" target="_blank">
                                        <i class="bi bi-plus-circle me-1"></i>
                                        Manage categories
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Initialize TinyMCE with dynamic configuration
        tinymce.init({
            selector: '#content',
            height: 400,
            menubar: false,
            skin: (document.documentElement.classList.contains('dark') ? 'oxide-dark' : 'oxide'),
            content_css: (document.documentElement.classList.contains('dark') ? 'dark' : 'default'),
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'media', 'table', 'help', 'wordcount'
                <?php if ($tinymce_api_key !== 'no-api-key'): ?>
                    , 'spellchecker', 'paste', 'importcss', 'autosave'
                <?php endif; ?>
            ],
            toolbar: 'undo redo | blocks | bold italic forecolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat<?php if ($tinymce_api_key !== 'no-api-key'): ?> | spellchecker<?php endif; ?> | help',
            content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 14px }',
            branding: false,
            <?php if ($tinymce_api_key !== 'no-api-key'): ?>
            spellchecker_language: 'en',
            spellchecker_active: true,
            paste_auto_cleanup_on_paste: true,
            paste_remove_styles: true,
            paste_remove_styles_if_webkit: true,
            paste_strip_class_attributes: 'all',
            <?php endif; ?>
            setup: function (editor) {
                editor.on('change', function () {
                    tinymce.triggerSave();
                });
            }
        });

        // Auto-generate slug from title
        function updateSlug() {
            const title = document.getElementById('title').value;
            const slugField = document.getElementById('slug');
            
            // Only auto-generate if slug is empty
            if (!slugField.value) {
                const slug = title
                    .toLowerCase()
                    .trim()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-')
                    .replace(/^-|-$/g, '');
                
                slugField.value = slug;
                updateUrlPreview();
            }
        }

        // Update URL preview
        function updateUrlPreview() {
            const slug = document.getElementById('slug').value || 'article-slug';
            document.getElementById('url-preview').textContent = '/kb/' + slug;
        }

        // Auto-fill meta title from title
        function updateMetaTitle() {
            const title = document.getElementById('title').value;
            const metaTitleField = document.getElementById('meta_title');
            
            // Only auto-fill if meta title is empty
            if (!metaTitleField.value && title) {
                metaTitleField.value = title.substring(0, 60);
                countChars('meta_title', 60);
            }
        }

        // Update category preview
        function updateCategoryPreview() {
            const select = document.getElementById('category-select');
            const preview = document.getElementById('category-preview');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption.value) {
                const color = selectedOption.getAttribute('data-color');
                const name = selectedOption.text;
                preview.innerHTML = `<div class="category-preview" style="background-color: ${color}">${name}</div>`;
            } else {
                preview.innerHTML = '';
            }
        }

        // Character counter
        function countChars(fieldId, maxLength) {
            const field = document.getElementById(fieldId);
            const counter = document.getElementById(fieldId + '-counter');
            const length = field.value.length;
            
            counter.textContent = length + '/' + maxLength;
            
            // Update counter color based on length
            counter.classList.remove('warning', 'danger');
            if (length > maxLength * 0.9) {
                counter.classList.add('danger');
            } else if (length > maxLength * 0.8) {
                counter.classList.add('warning');
            }
        }

        // Save as draft function
        function saveDraft() {
            document.getElementById('status-select').value = 'draft';
            document.getElementById('articleForm').submit();
        }

        // Initialize character counters and URL preview
        document.addEventListener('DOMContentLoaded', function() {
            countChars('title', 255);
            countChars('excerpt', 500);
            countChars('meta_title', 60);
            countChars('meta_description', 160);
            updateUrlPreview();
            updateCategoryPreview();
            
            // Update URL preview when slug changes
            document.getElementById('slug').addEventListener('input', updateUrlPreview);
        });

        // Form validation before submit
        document.getElementById('articleForm').addEventListener('submit', function(e) {
            const title = document.getElementById('title').value.trim();
            const content = tinymce.get('content').getContent();
            const category = document.getElementById('category-select').value;
            
            if (!title || !content || !category) {
                e.preventDefault();
                alert('Please fill in all required fields (Title, Content, and Category).');
                return false;
            }
        });
    </script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>