<?php
/**
 * Delete Booking Attachment
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

header('Content-Type: application/json');

// Access control (Admin only)
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'administrator') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$attachmentId = (int)($_POST['attachment_id'] ?? 0);

if (empty($attachmentId)) {
    echo json_encode(['success' => false, 'error' => 'Attachment ID is required']);
    exit;
}

try {
    // Get attachment details
    $stmt = $pdo->prepare("SELECT file_name FROM booking_attachments WHERE id = ?");
    $stmt->execute([$attachmentId]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attachment) {
        echo json_encode(['success' => false, 'error' => 'Attachment not found']);
        exit;
    }

    // Delete file from disk
    $filePath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/bookings/' . $attachment['file_name'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    // Delete from database
    $stmt = $pdo->prepare("DELETE FROM booking_attachments WHERE id = ?");
    $stmt->execute([$attachmentId]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("Delete booking attachment error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
