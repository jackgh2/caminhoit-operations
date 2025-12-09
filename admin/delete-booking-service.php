<?php
/**
 * Delete Booking Service
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

if (empty($_POST['service_id'])) {
    echo json_encode(['success' => false, 'error' => 'Service ID is required']);
    exit;
}

$serviceId = (int)$_POST['service_id'];

try {
    // Check if there are upcoming bookings for this service
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM booking_appointments
        WHERE service_id = ? AND appointment_date >= CURDATE()
        AND status NOT IN ('cancelled', 'completed')
    ");
    $stmt->execute([$serviceId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result['count'] > 0) {
        echo json_encode([
            'success' => false,
            'error' => 'Cannot delete service with upcoming bookings. Cancel bookings first or mark service as inactive.'
        ]);
        exit;
    }

    // Delete the service
    $stmt = $pdo->prepare("DELETE FROM booking_services WHERE id = ?");
    $stmt->execute([$serviceId]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("Delete booking service error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
