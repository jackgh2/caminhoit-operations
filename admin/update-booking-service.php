<?php
/**
 * Update Booking Service
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

$serviceId = (int)$_POST['service_id'];
$name = trim($_POST['name']);
$description = !empty($_POST['description']) ? trim($_POST['description']) : null;
$duration = (int)$_POST['duration_minutes'];
$color = $_POST['color'];
$isActive = isset($_POST['is_active']) ? 1 : 0;

if (empty($name) || $duration < 15 || $duration > 240) {
    $_SESSION['error'] = 'Invalid service data';
    header('Location: /admin/booking-settings.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE booking_services
        SET name = ?, description = ?, duration_minutes = ?, color = ?, is_active = ?
        WHERE id = ?
    ");
    $stmt->execute([$name, $description, $duration, $color, $isActive, $serviceId]);

    $_SESSION['success'] = 'Service updated successfully';
    header('Location: /admin/booking-settings.php');
    exit;

} catch (Exception $e) {
    error_log("Update booking service error: " . $e->getMessage());
    $_SESSION['error'] = 'Error updating service';
    header('Location: /admin/booking-settings.php');
    exit;
}
