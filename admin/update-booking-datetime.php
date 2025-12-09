<?php
/**
 * Update Booking Date/Time
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
$appointmentDate = $_POST['appointment_date'] ?? '';
$startTime = $_POST['start_time'] ?? '';
$durationMinutes = (int)($_POST['duration_minutes'] ?? 0);

if (empty($bookingId) || empty($appointmentDate) || empty($startTime) || empty($durationMinutes)) {
    echo json_encode(['success' => false, 'error' => 'Booking ID, date, time, and duration are required']);
    exit;
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $appointmentDate)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

// Validate time format
if (!preg_match('/^\d{2}:\d{2}$/', $startTime)) {
    echo json_encode(['success' => false, 'error' => 'Invalid time format']);
    exit;
}

try {
    // Get the old booking details for the note
    $stmt = $pdo->prepare("SELECT appointment_date, start_time, end_time FROM booking_appointments WHERE id = ?");
    $stmt->execute([$bookingId]);
    $oldBooking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$oldBooking) {
        echo json_encode(['success' => false, 'error' => 'Booking not found']);
        exit;
    }

    // Calculate end time based on start time + duration
    $startDateTime = new DateTime($appointmentDate . ' ' . $startTime);
    $endDateTime = clone $startDateTime;
    $endDateTime->add(new DateInterval('PT' . $durationMinutes . 'M'));
    $endTime = $endDateTime->format('H:i:s');

    // Update booking date and time
    $stmt = $pdo->prepare("
        UPDATE booking_appointments
        SET appointment_date = ?, start_time = ?, end_time = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$appointmentDate, $startTime, $endTime, $bookingId]);

    // Add an internal note about the date/time change
    $oldDateFormatted = date('l, F j, Y \a\t g:i A', strtotime($oldBooking['appointment_date'] . ' ' . $oldBooking['start_time']));
    $newDateFormatted = date('l, F j, Y \a\t g:i A', strtotime($appointmentDate . ' ' . $startTime));

    $noteText = "Date/Time changed:\n";
    $noteText .= "From: " . $oldDateFormatted . "\n";
    $noteText .= "To: " . $newDateFormatted;

    $stmt = $pdo->prepare("
        INSERT INTO booking_notes (booking_id, user_id, note)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$bookingId, $_SESSION['user']['id'], $noteText]);

    // Send Discord webhook notification
    $bookingHelper = new BookingHelper($pdo);
    $changedBy = $_SESSION['user']['username'] ?? 'Admin';

    // Get booking details for notification
    $stmt = $pdo->prepare("
        SELECT ba.*, bs.name as service_name
        FROM booking_appointments ba
        JOIN booking_services bs ON ba.service_id = bs.id
        WHERE ba.id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($booking) {
        $webhookUrl = $bookingHelper->getSettingValue('discord_webhook_url');
        if (!empty($webhookUrl)) {
            $embed = [
                'title' => '📅 Booking Rescheduled',
                'color' => hexdec('3b82f6'),
                'fields' => [
                    [
                        'name' => 'Booking ID',
                        'value' => '#' . $bookingId,
                        'inline' => true
                    ],
                    [
                        'name' => 'Customer',
                        'value' => $booking['customer_name'],
                        'inline' => true
                    ],
                    [
                        'name' => 'Service',
                        'value' => $booking['service_name'],
                        'inline' => true
                    ],
                    [
                        'name' => 'Previous Date/Time',
                        'value' => $oldDateFormatted,
                        'inline' => false
                    ],
                    [
                        'name' => 'New Date/Time',
                        'value' => $newDateFormatted,
                        'inline' => false
                    ],
                    [
                        'name' => 'Changed By',
                        'value' => $changedBy,
                        'inline' => true
                    ]
                ],
                'timestamp' => date('c')
            ];

            $payload = json_encode(['embeds' => [$embed]]);

            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("Update booking date/time error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
