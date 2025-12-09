<?php
/**
 * Delete Blocked Time Slot
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

if (empty($_POST['slot_id'])) {
    echo json_encode(['success' => false, 'error' => 'Slot ID is required']);
    exit;
}

$slotId = (int)$_POST['slot_id'];

try {
    $stmt = $pdo->prepare("DELETE FROM booking_blocked_slots WHERE id = ?");
    $stmt->execute([$slotId]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("Delete blocked slot error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
