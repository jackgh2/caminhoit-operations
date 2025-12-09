<?php
/**
 * Update or Add Booking Availability (Working Hours)
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
    header('Location: /admin/booking-settings.php');
    exit;
}

$availabilityId = !empty($_POST['availability_id']) ? (int)$_POST['availability_id'] : null;
$dayOfWeek = (int)$_POST['day_of_week'];
$startTime = $_POST['start_time'];
$endTime = $_POST['end_time'];
$isActive = isset($_POST['is_active']) ? 1 : 0;

// Validate times
if (empty($startTime) || empty($endTime)) {
    $_SESSION['error'] = 'Start and end times are required';
    header('Location: /admin/booking-settings.php');
    exit;
}

if (strtotime($startTime) >= strtotime($endTime)) {
    $_SESSION['error'] = 'End time must be after start time';
    header('Location: /admin/booking-settings.php');
    exit;
}

try {
    if ($availabilityId) {
        // Update existing
        $stmt = $pdo->prepare("
            UPDATE booking_availability
            SET start_time = ?, end_time = ?, is_active = ?
            WHERE id = ?
        ");
        $stmt->execute([$startTime, $endTime, $isActive, $availabilityId]);
        $_SESSION['success'] = 'Working hours updated successfully';
    } else {
        // Check if already exists for this day
        $stmt = $pdo->prepare("SELECT id FROM booking_availability WHERE day_of_week = ?");
        $stmt->execute([$dayOfWeek]);

        if ($stmt->fetch()) {
            $_SESSION['error'] = 'Working hours already exist for this day. Use edit instead.';
            header('Location: /admin/booking-settings.php');
            exit;
        }

        // Insert new
        $stmt = $pdo->prepare("
            INSERT INTO booking_availability (day_of_week, start_time, end_time, is_active)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$dayOfWeek, $startTime, $endTime, $isActive]);
        $_SESSION['success'] = 'Working hours added successfully';
    }

    header('Location: /admin/booking-settings.php');
    exit;

} catch (Exception $e) {
    error_log("Update booking availability error: " . $e->getMessage());
    $_SESSION['error'] = 'Error updating working hours';
    header('Location: /admin/booking-settings.php');
    exit;
}
