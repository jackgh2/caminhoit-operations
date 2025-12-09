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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$media_id = $data['id'] ?? 0;
$new_filename = trim($data['filename'] ?? '');

if (!$media_id || !$new_filename) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// Sanitize filename
$new_filename = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $new_filename);
if (empty($new_filename)) {
    echo json_encode(['success' => false, 'error' => 'Invalid filename']);
    exit;
}

try {
    // Get current media info
    $stmt = $pdo->prepare("SELECT * FROM blog_media_library WHERE id = ?");
    $stmt->execute([$media_id]);
    $media = $stmt->fetch();

    if (!$media) {
        echo json_encode(['success' => false, 'error' => 'Media not found']);
        exit;
    }

    // Update the original filename in database (we keep the actual file as-is for URLs to work)
    $stmt = $pdo->prepare("UPDATE blog_media_library SET original_filename = ? WHERE id = ?");
    $stmt->execute([$new_filename, $media_id]);

    echo json_encode([
        'success' => true,
        'message' => 'Filename updated successfully',
        'new_filename' => $new_filename
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
