<?php
/**
 * Add Internal Note to Booking
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Access control (Admin only)
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'administrator') {
    header('Location: /login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/bookings.php');
    exit;
}

$bookingId = (int)($_POST['booking_id'] ?? 0);
$note = trim($_POST['note'] ?? '');

if (empty($bookingId) || empty($note)) {
    $_SESSION['error'] = 'Booking ID and note content are required';
    header('Location: /admin/view-booking.php?id=' . $bookingId);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO booking_notes (booking_id, user_id, note)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$bookingId, $_SESSION['user']['id'], $note]);

    $_SESSION['success'] = 'Note added successfully';
    header('Location: /admin/view-booking.php?id=' . $bookingId);
    exit;

} catch (Exception $e) {
    error_log("Add booking note error: " . $e->getMessage());
    $_SESSION['error'] = 'Error adding note';
    header('Location: /admin/view-booking.php?id=' . $bookingId);
    exit;
}
