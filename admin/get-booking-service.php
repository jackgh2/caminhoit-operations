<?php
/**
 * Get Booking Service Data (AJAX endpoint)
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

if (empty($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'Service ID is required']);
    exit;
}

$serviceId = (int)$_GET['id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM booking_services WHERE id = ?");
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$service) {
        echo json_encode(['success' => false, 'error' => 'Service not found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'service' => $service
    ]);

} catch (Exception $e) {
    error_log("Get booking service error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
