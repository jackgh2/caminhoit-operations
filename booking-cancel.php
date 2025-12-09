<?php
/**
 * Booking Cancellation Page
 * Allows customers to cancel their booking
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/SEOHelper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/BookingHelper.php';

// Get booking by cancellation token
if (empty($_GET['token'])) {
    header('Location: /book.php');
    exit;
}

$token = $_GET['token'];
$bookingHelper = new BookingHelper($pdo);
$booking = $bookingHelper->getBookingByToken($token, 'cancellation');

if (!$booking) {
    $error = 'Booking not found';
}

$cancelled = false;
$cancelError = null;

// Handle cancellation form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['confirm_cancel'])) {
    $reason = !empty($_POST['cancellation_reason']) ? trim($_POST['cancellation_reason']) : null;
    $result = $bookingHelper->cancelBooking($token, $reason);

    if ($result['success']) {
        $cancelled = true;
        $booking = $bookingHelper->getBookingByToken($token, 'cancellation'); // Refresh
    } else {
        $cancelError = $result['error'];
    }
}

// Initialize SEO
$seo = new SEOHelper();
$seo->setTitle('Cancel Booking | CaminhoIT')
    ->setDescription('Cancel your appointment booking')
    ->setType('WebPage');

$page_title = 'Cancel Booking | CaminhoIT';
?>
<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">

    <!-- CSS -->
    <link rel="stylesheet" href="/assets/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background-color: #f8fafc;
        }
        .cancel-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            padding: 3rem;
            margin-top: -60px;
            position: relative;
            z-index: 10;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }
        .warning-icon {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2rem;
        }
        .warning-icon i {
            font-size: 3rem;
            color: white;
        }
        .info-row {
            padding: 1rem 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav.php'; ?>

<header class="hero-enhanced">
    <div class="container">
        <div class="hero-content-enhanced">
            <h1 class="hero-title-enhanced text-white">
                <i class="bi bi-x-circle me-3"></i>
                Cancel Booking
            </h1>
            <p class="hero-subtitle-enhanced text-white">
                Manage your appointment
            </p>
        </div>
    </div>
</header>

<div class="container py-5">
    <div class="cancel-container">

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <div class="text-center mt-4">
                <a href="/book.php" class="btn btn-primary">Book New Appointment</a>
            </div>

        <?php elseif ($cancelled): ?>
            <div class="text-center">
                <div class="warning-icon">
                    <i class="bi bi-check-lg"></i>
                </div>
                <h2 class="mb-4">Booking Cancelled</h2>
                <p class="text-muted mb-4">Your appointment has been successfully cancelled.</p>

                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    We've sent you a confirmation email. If you'd like to reschedule, you can book a new appointment anytime.
                </div>

                <div class="mt-4">
                    <a href="/book.php" class="btn btn-primary">Book New Appointment</a>
                    <a href="/" class="btn btn-outline-secondary ms-2">Return to Home</a>
                </div>
            </div>

        <?php elseif ($booking['status'] === 'cancelled'): ?>
            <div class="alert alert-warning">
                <i class="bi bi-info-circle me-2"></i>
                This booking has already been cancelled.
            </div>
            <div class="text-center mt-4">
                <a href="/book.php" class="btn btn-primary">Book New Appointment</a>
            </div>

        <?php else: ?>
            <div class="warning-icon">
                <i class="bi bi-exclamation-lg"></i>
            </div>

            <h2 class="text-center mb-4">Cancel Your Booking?</h2>
            <p class="text-center text-muted mb-4">
                Are you sure you want to cancel this appointment? This action cannot be undone.
            </p>

            <?php if ($cancelError): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?= htmlspecialchars($cancelError) ?>
                </div>
            <?php endif; ?>

            <div class="mb-4">
                <div class="info-row">
                    <strong>Service:</strong><br>
                    <?= htmlspecialchars($booking['service_name']) ?>
                </div>
                <div class="info-row">
                    <strong>Date & Time:</strong><br>
                    <?= date('l, F j, Y \a\t g:i A', strtotime($booking['appointment_date'] . ' ' . $booking['start_time'])) ?>
                </div>
                <div class="info-row">
                    <strong>Duration:</strong><br>
                    <?= $booking['duration_minutes'] ?> minutes
                </div>
            </div>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Reason for Cancellation (Optional)</label>
                    <textarea class="form-control" name="cancellation_reason" rows="3" placeholder="Let us know why you're cancelling..."></textarea>
                    <small class="text-muted">This helps us improve our service</small>
                </div>

                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Please Note:</strong> Once cancelled, this time slot will become available for other customers. If you'd like to reschedule, please book a new appointment after cancelling.
                </div>

                <div class="d-flex gap-2 justify-content-center">
                    <a href="/" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-left me-2"></i>Keep Booking
                    </a>
                    <button type="submit" name="confirm_cancel" value="1" class="btn btn-danger btn-lg">
                        <i class="bi bi-x-circle me-2"></i>Yes, Cancel Booking
                    </button>
                </div>
            </form>

        <?php endif; ?>

    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
