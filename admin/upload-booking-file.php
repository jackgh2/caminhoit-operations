<?php
/**
 * Upload File Attachment to Booking
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

if (empty($bookingId)) {
    $_SESSION['error'] = 'Booking ID is required';
    header('Location: /admin/bookings.php');
    exit;
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['error'] = 'Please select a valid file';
    header('Location: /admin/view-booking.php?id=' . $bookingId);
    exit;
}

$file = $_FILES['file'];
$originalName = basename($file['name']);
$fileSize = $file['size'];
$tmpName = $file['tmp_name'];

// Validate file size (10MB max)
if ($fileSize > 10 * 1024 * 1024) {
    $_SESSION['error'] = 'File size must be less than 10MB';
    header('Location: /admin/view-booking.php?id=' . $bookingId);
    exit;
}

// Get file extension
$fileExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

// Allowed extensions
$allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif', 'zip', 'txt'];
if (!in_array($fileExt, $allowedExtensions)) {
    $_SESSION['error'] = 'Invalid file type. Allowed: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, ZIP, TXT';
    header('Location: /admin/view-booking.php?id=' . $bookingId);
    exit;
}

// Get MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $tmpName);
finfo_close($finfo);

try {
    // Create upload directory if it doesn't exist
    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/bookings/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate unique filename
    $fileName = 'booking_' . $bookingId . '_' . uniqid() . '.' . $fileExt;
    $uploadPath = $uploadDir . $fileName;

    // Move uploaded file
    if (!move_uploaded_file($tmpName, $uploadPath)) {
        throw new Exception('Failed to move uploaded file');
    }

    // Save to database
    $stmt = $pdo->prepare("
        INSERT INTO booking_attachments (booking_id, user_id, file_name, original_name, file_size, mime_type)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $bookingId,
        $_SESSION['user']['id'],
        $fileName,
        $originalName,
        $fileSize,
        $mimeType
    ]);

    $_SESSION['success'] = 'File uploaded successfully';
    header('Location: /admin/view-booking.php?id=' . $bookingId);
    exit;

} catch (Exception $e) {
    error_log("Upload booking file error: " . $e->getMessage());

    // Clean up file if database insert failed
    if (isset($uploadPath) && file_exists($uploadPath)) {
        unlink($uploadPath);
    }

    $_SESSION['error'] = 'Error uploading file';
    header('Location: /admin/view-booking.php?id=' . $bookingId);
    exit;
}
