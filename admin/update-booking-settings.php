<?php
/**
 * Update Global Booking Settings
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

$settings = [
    'timezone' => $_POST['timezone'] ?? 'Europe/Lisbon',
    'booking_buffer_minutes' => (int)($_POST['booking_buffer_minutes'] ?? 15),
    'advance_booking_days' => (int)($_POST['advance_booking_days'] ?? 30),
    'min_notice_hours' => (int)($_POST['min_notice_hours'] ?? 24),
    'reminder_hours_before' => (int)($_POST['reminder_hours_before'] ?? 24),
    'allow_weekend_bookings' => isset($_POST['allow_weekend_bookings']) ? '1' : '0',
    'send_reminders' => isset($_POST['send_reminders']) ? '1' : '0',
    'confirmation_email_enabled' => isset($_POST['confirmation_email_enabled']) ? '1' : '0',
    'discord_webhook_enabled' => isset($_POST['discord_webhook_enabled']) ? '1' : '0',
    'discord_webhook_url' => trim($_POST['discord_webhook_url'] ?? ''),
    'company_phone' => trim($_POST['company_phone'] ?? '+351 963 452 653'),
    'company_email' => trim($_POST['company_email'] ?? 'support@caminhoit.com'),
];

try {
    // Update each setting
    foreach ($settings as $key => $value) {
        $stmt = $pdo->prepare("
            INSERT INTO booking_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = ?
        ");
        $stmt->execute([$key, $value, $value]);
    }

    $_SESSION['success'] = 'Settings updated successfully';
    header('Location: /admin/booking-settings.php');
    exit;

} catch (Exception $e) {
    error_log("Update booking settings error: " . $e->getMessage());
    $_SESSION['error'] = 'Error updating settings';
    header('Location: /admin/booking-settings.php');
    exit;
}
