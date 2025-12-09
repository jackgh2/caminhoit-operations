<?php
/**
 * Update Booking Status
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/BookingHelper.php';

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

$bookingId = (int)($_POST['booking_id'] ?? 0);
$status = $_POST['status'] ?? '';
$reason = !empty($_POST['reason']) ? trim($_POST['reason']) : null;

if (empty($bookingId) || empty($status)) {
    echo json_encode(['success' => false, 'error' => 'Booking ID and status are required']);
    exit;
}

$validStatuses = ['pending', 'confirmed', 'cancelled', 'completed', 'no_show'];
if (!in_array($status, $validStatuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}

try {
    // Update booking status
    if ($status === 'cancelled' && !empty($reason)) {
        $stmt = $pdo->prepare("
            UPDATE booking_appointments
            SET status = ?, cancellation_reason = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $reason, $bookingId]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE booking_appointments
            SET status = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $bookingId]);
    }

    // Add an internal note about the status change
    $noteText = "Status changed to: " . ucfirst(str_replace('_', ' ', $status));
    if (!empty($reason)) {
        $noteText .= "\nReason: " . $reason;
    }

    $stmt = $pdo->prepare("
        INSERT INTO booking_notes (booking_id, user_id, note)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$bookingId, $_SESSION['user']['id'], $noteText]);

    // Send Discord webhook notification
    $bookingHelper = new BookingHelper($pdo);
    $changedBy = $_SESSION['user']['username'] ?? 'Admin';
    $bookingHelper->sendStatusChangeNotification($bookingId, $status, $reason, $changedBy);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("Update booking status error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
