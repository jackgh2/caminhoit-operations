<?php
/**
 * Add Blocked Time Slot
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
    header('Location: /admin/booking-blocked-slots.php');
    exit;
}

$blockedDate = $_POST['blocked_date'];
$startTime = $_POST['start_time'];
$endTime = $_POST['end_time'];
$reason = !empty($_POST['reason']) ? trim($_POST['reason']) : null;

// Validate date
if (empty($blockedDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $blockedDate)) {
    $_SESSION['error'] = 'Invalid date format';
    header('Location: /admin/booking-blocked-slots.php');
    exit;
}

// Validate times
if (empty($startTime) || empty($endTime)) {
    $_SESSION['error'] = 'Start and end times are required';
    header('Location: /admin/booking-blocked-slots.php');
    exit;
}

if (strtotime($startTime) >= strtotime($endTime)) {
    $_SESSION['error'] = 'End time must be after start time';
    header('Location: /admin/booking-blocked-slots.php');
    exit;
}

try {
    // Check for overlapping blocks
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM booking_blocked_slots
        WHERE blocked_date = ?
        AND (
            (start_time <= ? AND end_time > ?)
            OR (start_time < ? AND end_time >= ?)
            OR (start_time >= ? AND end_time <= ?)
        )
    ");
    $stmt->execute([$blockedDate, $startTime, $startTime, $endTime, $endTime, $startTime, $endTime]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result['count'] > 0) {
        $_SESSION['error'] = 'This time slot overlaps with an existing block';
        header('Location: /admin/booking-blocked-slots.php');
        exit;
    }

    // Insert new blocked slot
    $stmt = $pdo->prepare("
        INSERT INTO booking_blocked_slots (blocked_date, start_time, end_time, reason)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$blockedDate, $startTime, $endTime, $reason]);

    $_SESSION['success'] = 'Time slot blocked successfully';
    header('Location: /admin/booking-blocked-slots.php');
    exit;

} catch (Exception $e) {
    error_log("Add blocked slot error: " . $e->getMessage());
    $_SESSION['error'] = 'Error adding blocked slot';
    header('Location: /admin/booking-blocked-slots.php');
    exit;
}
