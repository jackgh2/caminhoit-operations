<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

header('Content-Type: application/json');

$user = $_SESSION['user'] ?? null;
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['files'])) {
    echo json_encode(['success' => false, 'error' => 'No files uploaded']);
    exit;
}

$uploaded_files = [];
$errors = [];

$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
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
        // Create upload directory
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/blog/uploads/images/" . date('Y/m');
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Generate unique filename
        $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $file_extension;
        $file_path = $upload_dir . '/' . $filename;
        $web_path = "/blog/uploads/images/" . date('Y/m') . '/' . $filename;

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
                'image',
                $user['id']
            ]);

            $uploaded_files[] = [
                'filename' => $file_name,
                'url' => $web_path
            ];
        } else {
            $errors[] = "Failed to upload $file_name";
        }

    } catch (Exception $e) {
        $errors[] = "Error uploading $file_name: " . $e->getMessage();
    }
}

if (!empty($uploaded_files)) {
    echo json_encode([
        'success' => true,
        'files' => $uploaded_files,
        'count' => count($uploaded_files)
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => implode('; ', $errors)
    ]);
}
