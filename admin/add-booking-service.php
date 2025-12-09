<?php
/**
 * Add New Booking Service
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

$name = trim($_POST['name']);
$description = !empty($_POST['description']) ? trim($_POST['description']) : null;
$duration = (int)$_POST['duration_minutes'];
$color = !empty($_POST['color']) ? $_POST['color'] : '#667eea';

if (empty($name) || $duration < 15 || $duration > 240) {
    $_SESSION['error'] = 'Invalid service data. Name is required and duration must be between 15-240 minutes.';
    header('Location: /admin/booking-settings.php');
    exit;
}

try {
    // Get the highest sort_order
    $stmt = $pdo->query("SELECT MAX(sort_order) as max_order FROM booking_services");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $sortOrder = ($result['max_order'] ?? 0) + 1;

    $stmt = $pdo->prepare("
        INSERT INTO booking_services (name, description, duration_minutes, color, sort_order, is_active)
        VALUES (?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([$name, $description, $duration, $color, $sortOrder]);

    $_SESSION['success'] = 'Service added successfully';
    header('Location: /admin/booking-settings.php');
    exit;

} catch (Exception $e) {
    error_log("Add booking service error: " . $e->getMessage());
    $_SESSION['error'] = 'Error adding service';
    header('Location: /admin/booking-settings.php');
    exit;
}
