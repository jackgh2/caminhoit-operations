<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Allow iframe from same origin
header('X-Frame-Options: SAMEORIGIN');
header('Content-Security-Policy: frame-ancestors \'self\'');

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    http_response_code(403);
    exit('Unauthorized');
}

// Get media files
$media_type_filter = $_GET['type'] ?? 'image';
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];

if ($media_type_filter) {
    $where_conditions[] = "media_type = ?";
    $params[] = $media_type_filter;
}

if ($search) {
    $where_conditions[] = "(original_filename LIKE ? OR alt_text LIKE ?)";
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
    SELECT *
    FROM blog_media_library
    WHERE $where_clause
    ORDER BY created_at DESC
    LIMIT $per_page OFFSET $offset
";

$stmt = $pdo->prepare($media_sql);
$stmt->execute($params);
$media_files = $stmt->fetchAll();

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

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            padding: 1rem;
        }
        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        .media-item {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.2s;
        }
        .media-item:hover, .media-item.selected {
            border-color: #3b82f6;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
        }
        .media-preview {
            height: 120px;
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
            font-size: 2.5rem;
            color: #6b7280;
        }
        .media-info {
            padding: 0.5rem;
            background: white;
        }
        .media-filename {
            font-size: 0.75rem;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        .upload-area:hover, .upload-area.dragover {
            border-color: #3b82f6;
            background: #f0f9ff;
        }
        #fileInput {
            display: none;
        }
    </style>
</head>
<body>
    <div class="mb-3">
        <h5>Select Image</h5>
    </div>

    <!-- Upload Area -->
    <div class="upload-area" id="uploadArea" onclick="triggerFileUpload()">
        <form method="POST" action="upload-modal.php" enctype="multipart/form-data" id="uploadForm">
            <input type="file" id="fileInput" name="files[]" multiple accept="image/*">
            <i class="bi bi-cloud-upload text-primary" style="font-size: 2rem;"></i>
            <p class="mb-0 mt-2"><strong>Click to upload</strong> or drag and drop</p>
            <small class="text-muted">Images only (JPG, PNG, GIF, WebP) - Max 10MB</small>
        </form>
    </div>

    <!-- Search -->
    <div class="mb-3">
        <input type="text" class="form-control" id="searchInput" placeholder="Search files..." value="<?= htmlspecialchars($search) ?>">
    </div>

    <!-- Media Grid -->
    <div id="mediaContainer">
        <?php if (empty($media_files)): ?>
            <div class="text-center py-4 text-muted">
                <i class="bi bi-images" style="font-size: 3rem;"></i>
                <p class="mt-2">No images found. Upload your first image above.</p>
            </div>
        <?php else: ?>
            <div class="media-grid">
                <?php foreach ($media_files as $file): ?>
                    <div class="media-item" data-url="<?= htmlspecialchars($file['file_path']) ?>" onclick="selectMediaItem(this)">
                        <div class="media-preview">
                            <?php if ($file['media_type'] === 'image'): ?>
                                <img src="<?= htmlspecialchars($file['file_path']) ?>" alt="<?= htmlspecialchars($file['original_filename']) ?>">
                            <?php else: ?>
                                <i class="file-icon bi bi-file-text"></i>
                            <?php endif; ?>
                        </div>
                        <div class="media-info">
                            <div class="media-filename" title="<?= htmlspecialchars($file['original_filename']) ?>">
                                <?= htmlspecialchars($file['original_filename']) ?>
                            </div>
                            <small class="text-muted"><?= formatFileSize($file['file_size']) ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination pagination-sm justify-content-center">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?type=<?= $media_type_filter ?>&search=<?= urlencode($search) ?>&page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="d-flex justify-content-between mt-3">
        <button type="button" class="btn btn-outline-secondary" onclick="window.parent.closeMediaLibrary()">Cancel</button>
        <button type="button" class="btn btn-primary" id="selectBtn" disabled onclick="confirmSelection()">Select Image</button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let selectedUrl = '';
        const selectBtn = document.getElementById('selectBtn');

        function selectMediaItem(element) {
            // Remove previous selection
            document.querySelectorAll('.media-item').forEach(item => {
                item.classList.remove('selected');
            });

            // Select this item
            element.classList.add('selected');
            selectedUrl = element.dataset.url;
            selectBtn.disabled = false;
        }

        function confirmSelection() {
            if (selectedUrl) {
                window.parent.handleMediaSelection(selectedUrl);
            }
        }

        // File upload
        function triggerFileUpload() {
            document.getElementById('fileInput').click();
        }

        document.getElementById('fileInput').addEventListener('change', function() {
            if (this.files.length > 0) {
                const formData = new FormData(document.getElementById('uploadForm'));

                fetch('upload-modal.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Reload the modal
                        window.location.reload();
                    } else {
                        alert('Upload failed: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Upload failed: ' + error);
                });
            }
        });

        // Drag and drop
        const uploadArea = document.getElementById('uploadArea');

        uploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');

            const files = e.dataTransfer.files;
            document.getElementById('fileInput').files = files;
            document.getElementById('fileInput').dispatchEvent(new Event('change'));
        });

        // Search
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                window.location.href = '?type=image&search=' + encodeURIComponent(this.value);
            }, 500);
        });
    </script>
</body>
</html>
