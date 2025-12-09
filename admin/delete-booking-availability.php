<?php
/**
 * Delete Booking Availability (Working Hours)
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

if (empty($_POST['availability_id'])) {
    echo json_encode(['success' => false, 'error' => 'Availability ID is required']);
    exit;
}

$availabilityId = (int)$_POST['availability_id'];

try {
    // Check if there are upcoming bookings on this day
    $stmt = $pdo->prepare("
        SELECT day_of_week FROM booking_availability WHERE id = ?
    ");
    $stmt->execute([$availabilityId]);
    $availability = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$availability) {
        echo json_encode(['success' => false, 'error' => 'Availability not found']);
        exit;
    }

    $dayOfWeek = $availability['day_of_week'];

    // Check for upcoming bookings on this day
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM booking_appointments
        WHERE DAYOFWEEK(appointment_date) - 1 = ?
        AND appointment_date >= CURDATE()
        AND status NOT IN ('cancelled', 'completed')
    ");
    $stmt->execute([$dayOfWeek]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result['count'] > 0) {
        echo json_encode([
            'success' => false,
            'error' => 'Cannot delete working hours with upcoming bookings on this day. Cancel bookings first or mark as inactive.'
        ]);
        exit;
    }

    // Delete the availability
    $stmt = $pdo->prepare("DELETE FROM booking_availability WHERE id = ?");
    $stmt->execute([$availabilityId]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("Delete booking availability error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
