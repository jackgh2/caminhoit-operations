<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Check permissions
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_role = $stmt->fetchColumn();

if (!in_array($user_role, ['administrator', 'support_user', 'support_technician'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient permissions']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];

// Validate file
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$max_size = 10 * 1024 * 1024; // 10MB

if (!in_array($file['type'], $allowed_types)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed.']);
    exit;
}

if ($file['size'] > $max_size) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large. Maximum size is 10MB.']);
    exit;
}

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload error: ' . $file['error']]);
    exit;
}

try {
    // Create upload directory structure
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/blog/uploads/images/' . date('Y/m');
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate unique filename
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '.' . $file_extension;
    $file_path = $upload_dir . '/' . $filename;
    $web_path = '/blog/uploads/images/' . date('Y/m') . '/' . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        throw new Exception('Failed to move uploaded file');
    }
    
    // Save to media library
    $stmt = $pdo->prepare("
        INSERT INTO blog_media_library (
            filename, original_filename, file_path, file_size, mime_type, 
            media_type, uploaded_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $filename,
        $file['name'],
        $web_path,
        $file['size'],
        $file['type'],
        'image',
        $user['id']
    ]);
    
    echo json_encode([
        'location' => $web_path,
        'filename' => $filename,
        'original_name' => $file['name'],
        'size' => $file['size']
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Upload failed: ' . $e->getMessage()]);
}
?>