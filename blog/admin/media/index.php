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

// Handle file upload
$upload_message = '';
$upload_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files'])) {
    $uploaded_files = [];
    $errors = [];
    
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $max_size = 10 * 1024 * 1024; // 10MB
    
    foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
        if ($_FILES['files']['error'][$key] !== UPLOAD_ERR_OK) {
            continue;
        }
        
        $file_name = $_FILES['files']['name'][$key];
        $file_size = $_FILES['files']['size'][$key];
        $file_type = $_FILES['files']['type'][$key];
        
        // Validate file
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Invalid file type for $file_name";
            continue;
        }
        
        if ($file_size > $max_size) {
            $errors[] = "File $file_name is too large (max 10MB)";
            continue;
        }
        
        try {
            // Determine media type and upload directory
            $media_type = 'other';
            $upload_subdir = 'other';
            
            if (strpos($file_type, 'image/') === 0) {
                $media_type = 'image';
                $upload_subdir = 'images';
            } elseif (strpos($file_type, 'video/') === 0) {
                $media_type = 'video';
                $upload_subdir = 'videos';
            } elseif (strpos($file_type, 'audio/') === 0) {
                $media_type = 'audio';
                $upload_subdir = 'audio';
            } elseif (in_array($file_type, ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])) {
                $media_type = 'document';
                $upload_subdir = 'documents';
            }
            
            // Create upload directory
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/blog/uploads/$upload_subdir/" . date('Y/m');
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Generate unique filename
            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
            $filename = uniqid() . '.' . $file_extension;
            $file_path = $upload_dir . '/' . $filename;
            $web_path = "/blog/uploads/$upload_subdir/" . date('Y/m') . '/' . $filename;
            
            // Move uploaded file
            if (move_uploaded_file($tmp_name, $file_path)) {
                // Save to database
                $stmt = $pdo->prepare("
                    INSERT INTO blog_media_library (
                        filename, original_filename, file_path, file_size, mime_type, 
                        media_type, uploaded_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $filename,
                    $file_name,
                    $web_path,
                    $file_size,
                    $file_type,
                    $media_type,
                    $user['id']
                ]);
                
                $uploaded_files[] = $file_name;
            } else {
                $errors[] = "Failed to upload $file_name";
            }
            
        } catch (Exception $e) {
            $errors[] = "Error uploading $file_name: " . $e->getMessage();
        }
    }
    
    if (!empty($uploaded_files)) {
        $upload_message = count($uploaded_files) . " file(s) uploaded successfully: " . implode(', ', $uploaded_files);
        $upload_type = 'success';
    }
    
    if (!empty($errors)) {
        $error_message = implode('<br>', $errors);
        if (empty($upload_message)) {
            $upload_message = $error_message;
            $upload_type = 'danger';
        } else {
            $upload_message .= '<br><strong>Errors:</strong><br>' . $error_message;
            $upload_type = 'warning';
        }
    }
}

// Get filter parameters
$media_type_filter = $_GET['type'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 24;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];

if ($media_type_filter) {
    $where_conditions[] = "media_type = ?";
    $params[] = $media_type_filter;
}

if ($search) {
    $where_conditions[] = "(original_filename LIKE ? OR alt_text LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count
$count_sql = "SELECT COUNT(*) FROM blog_media_library WHERE $where_clause";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_files = $stmt->fetchColumn();
$total_pages = ceil($total_files / $per_page);

// Get media files
$media_sql = "
    SELECT m.*, u.username as uploaded_by_name
    FROM blog_media_library m
    LEFT JOIN users u ON m.uploaded_by = u.id
    WHERE $where_clause
    ORDER BY m.created_at DESC
    LIMIT $per_page OFFSET $offset
";

$stmt = $pdo->prepare($media_sql);
$stmt->execute($params);
$media_files = $stmt->fetchAll();

$page_title = "Media Library | Blog Admin";
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

        .upload-area {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 2px dashed #d1d5db;
            text-align: center;
            transition: all 0.3s;
        }

        .upload-area:hover, .upload-area.dragover {
            border-color: #3b82f6;
            background: #f0f9ff;
        }

        .filters-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .media-item {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            transition: all 0.2s;
            cursor: pointer;
        }

        .media-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .media-preview {
            height: 150px;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .media-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .media-preview .file-icon {
            font-size: 3rem;
            color: #6b7280;
        }

        .media-info {
            padding: 1rem;
        }

        .media-filename {
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            word-break: break-word;
        }

        .media-meta {
            font-size: 0.75rem;
            color: #6b7280;
        }

        .media-actions {
            padding: 0.75rem 1rem;
            border-top: 1px solid #f3f4f6;
            display: flex;
            gap: 0.5rem;
        }

        .pagination-wrapper {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
        }

        #fileInput {
            display: none;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="main-container">
    <!-- Upload Messages -->
    <?php if ($upload_message): ?>
        <div class="alert alert-<?= $upload_type ?> alert-dismissible fade show" role="alert">
            <?= $upload_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-images me-3"></i>Media Library</h1>
                <p class="text-muted mb-0">Upload and manage your blog media files</p>
                <small class="text-muted">Total files: <?= number_format($total_files) ?></small>
            </div>
            <div>
                <a href="/blog/admin/dashboard.php" class="btn btn-outline-secondary me-2">
                    <i class="bi bi-arrow-left me-2"></i>Dashboard
                </a>
                <button class="btn btn-primary" onclick="triggerFileUpload()">
                    <i class="bi bi-cloud-upload me-2"></i>Upload Files
                </button>
            </div>
        </div>
    </div>

    <!-- Upload Area -->
    <div class="upload-area" id="uploadArea">
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <input type="file" id="fileInput" name="files[]" multiple accept="image/*,.pdf,.doc,.docx">
            <div class="upload-content">
                <i class="bi bi-cloud-upload text-primary" style="font-size: 3rem;"></i>
                <h4 class="mt-3">Drop files here or click to upload</h4>
                <p class="text-muted">Supports: Images (JPG, PNG, GIF, WebP), Documents (PDF, DOC, DOCX)</p>
                <p class="text-muted">Maximum file size: 10MB</p>
            </div>
        </form>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search files...">
            </div>
            <div class="col-md-3">
                <label class="form-label">File Type</label>
                <select class="form-select" name="type">
                    <option value="">All Types</option>
                    <option value="image" <?= $media_type_filter === 'image' ? 'selected' : '' ?>>Images</option>
                    <option value="document" <?= $media_type_filter === 'document' ? 'selected' : '' ?>>Documents</option>
                    <option value="video" <?= $media_type_filter === 'video' ? 'selected' : '' ?>>Videos</option>
                    <option value="audio" <?= $media_type_filter === 'audio' ? 'selected' : '' ?>>Audio</option>
                    <option value="other" <?= $media_type_filter === 'other' ? 'selected' : '' ?>>Other</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-2"></i>Filter
                </button>
            </div>
            <div class="col-md-2">
                <a href="?" class="btn btn-outline-secondary">
                    <i class="bi bi-x-circle me-2"></i>Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Media Grid -->
    <?php if (empty($media_files)): ?>
        <div class="text-center py-5">
            <i class="bi bi-images text-muted" style="font-size: 4rem;"></i>
            <h3 class="mt-3 text-muted">No media files found</h3>
            <?php if ($search || $media_type_filter): ?>
                <p class="text-muted">Try adjusting your filters or <a href="?">view all files</a></p>
            <?php else: ?>
                <p class="text-muted">Upload your first file to get started</p>
                <button class="btn btn-primary" onclick="triggerFileUpload()">
                    <i class="bi bi-cloud-upload me-2"></i>Upload Files
                </button>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="media-grid">
            <?php foreach ($media_files as $file): ?>
                <div class="media-item" onclick="selectMedia('<?= $file['id'] ?>')">
                    <div class="media-preview">
                        <?php if ($file['media_type'] === 'image'): ?>
                            <img src="<?= htmlspecialchars($file['file_path']) ?>" alt="<?= htmlspecialchars($file['alt_text'] ?: $file['original_filename']) ?>">
                        <?php else: ?>
                            <i class="file-icon bi bi-<?= $file['media_type'] === 'document' ? 'file-text' : ($file['media_type'] === 'video' ? 'play-circle' : ($file['media_type'] === 'audio' ? 'music-note' : 'file')) ?>"></i>
                        <?php endif; ?>
                    </div>
                    <div class="media-info">
                        <div class="media-filename"><?= htmlspecialchars($file['original_filename']) ?></div>
                        <div class="media-meta">
                            <?= strtoupper($file['media_type']) ?> • <?= formatFileSize($file['file_size']) ?><br>
                            Uploaded: <?= date('M j, Y', strtotime($file['created_at'])) ?>
                        </div>
                    </div>
                    <div class="media-actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="event.stopPropagation(); renameMedia(<?= $file['id'] ?>, '<?= htmlspecialchars($file['original_filename'], ENT_QUOTES) ?>')">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="event.stopPropagation(); copyUrl('<?= $file['file_path'] ?>')">
                            <i class="bi bi-link"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation(); deleteMedia(<?= $file['id'] ?>, '<?= htmlspecialchars($file['original_filename'], ENT_QUOTES) ?>')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination-wrapper">
                <nav aria-label="Media pagination">
                    <ul class="pagination justify-content-center mb-0">
                        <?php
                        $current_params = $_GET;
                        
                        // Previous page
                        if ($page > 1):
                            $current_params['page'] = $page - 1;
                            $prev_url = '?' . http_build_query($current_params);
                        ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= $prev_url ?>">
                                    <i class="bi bi-chevron-left"></i> Previous
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php
                        // Page numbers
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                            $current_params['page'] = $i;
                            $page_url = '?' . http_build_query($current_params);
                        ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= $page_url ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php
                        // Next page
                        if ($page < $total_pages):
                            $current_params['page'] = $page + 1;
                            $next_url = '?' . http_build_query($current_params);
                        ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= $next_url ?>">
                                    Next <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Rename Modal -->
<div class="modal fade" id="renameModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Rename File</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label for="renameInput" class="form-label">New filename:</label>
                <input type="text" class="form-control" id="renameInput" placeholder="Enter new filename">
                <div class="form-text">Enter a display name for this file (actual file on server stays the same)</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="renameConfirmBtn">Rename</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// File upload functionality
function triggerFileUpload() {
    document.getElementById('fileInput').click();
}

document.getElementById('fileInput').addEventListener('change', function() {
    if (this.files.length > 0) {
        document.getElementById('uploadForm').submit();
    }
});

// Drag and drop functionality
const uploadArea = document.getElementById('uploadArea');

uploadArea.addEventListener('dragover', function(e) {
    e.preventDefault();
    this.classList.add('dragover');
});

uploadArea.addEventListener('dragleave', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');
});

uploadArea.addEventListener('drop', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');
    
    const files = e.dataTransfer.files;
    const fileInput = document.getElementById('fileInput');
    fileInput.files = files;
    
    if (files.length > 0) {
        document.getElementById('uploadForm').submit();
    }
});

uploadArea.addEventListener('click', function() {
    triggerFileUpload();
});

function copyUrl(url) {
    const fullUrl = window.location.origin + url;
    navigator.clipboard.writeText(fullUrl).then(function() {
        // Show success message
        const toast = document.createElement('div');
        toast.className = 'alert alert-success position-fixed';
        toast.style.cssText = 'top: 100px; right: 20px; z-index: 9999; min-width: 300px;';
        toast.innerHTML = '<i class="bi bi-check-circle me-2"></i>URL copied to clipboard!';
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 3000);
    });
}

function selectMedia(id) {
    // This would be used when the media library is opened as a modal
    console.log('Selected media ID:', id);
}

let currentRenameId = null;

function renameMedia(id, currentFilename) {
    currentRenameId = id;

    // Set current filename in input
    document.getElementById('renameInput').value = currentFilename;

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('renameModal'));
    modal.show();

    // Focus on input after modal opens
    document.getElementById('renameModal').addEventListener('shown.bs.modal', function () {
        document.getElementById('renameInput').focus();
        document.getElementById('renameInput').select();
    }, { once: true });
}

// Handle rename confirmation
document.getElementById('renameConfirmBtn').addEventListener('click', function() {
    const newFilename = document.getElementById('renameInput').value.trim();

    if (!newFilename) {
        alert('Please enter a filename');
        return;
    }

    fetch('rename.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            id: currentRenameId,
            filename: newFilename
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('renameModal')).hide();

            // Show success message
            const toast = document.createElement('div');
            toast.className = 'alert alert-success position-fixed';
            toast.style.cssText = 'top: 100px; right: 20px; z-index: 9999; min-width: 300px;';
            toast.innerHTML = '<i class="bi bi-check-circle me-2"></i>File renamed successfully!';
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.remove();
                location.reload();
            }, 1500);
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error renaming file: ' + error);
    });
});

// Allow Enter key to submit
document.getElementById('renameInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        document.getElementById('renameConfirmBtn').click();
    }
});

function deleteMedia(id, filename) {
    if (confirm(`Are you sure you want to delete "${filename}"? This action cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'delete.php';

        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'id';
        idInput.value = id;

        form.appendChild(idInput);
        document.body.appendChild(form);
        form.submit();
    }
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