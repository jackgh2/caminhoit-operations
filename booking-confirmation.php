<?php
/**
 * Booking Confirmation Page
 * Shows booking details after successful booking
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/SEOHelper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/BookingHelper.php';

// Get booking by token
if (empty($_GET['token'])) {
    header('Location: /book.php');
    exit;
}

$token = $_GET['token'];
$bookingHelper = new BookingHelper($pdo);
$booking = $bookingHelper->getBookingByToken($token, 'confirmation');

if (!$booking) {
    header('Location: /book.php');
    exit;
}

// Initialize SEO
$seo = new SEOHelper();
$seo->setTitle('Booking Confirmed | CaminhoIT')
    ->setDescription('Your consultation has been confirmed. Thank you for choosing CaminhoIT.')
    ->setType('WebPage');

$page_title = 'Booking Confirmed | CaminhoIT';

// Include header
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php';
?>

<!-- SEO Meta Tags -->
<?= $seo->renderMetaTags() ?>

<style>
    .confirmation-container {
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        padding: 3rem;
        margin-top: 2rem;
        position: relative;
        z-index: 10;
        max-width: 700px;
        margin-left: auto;
        margin-right: auto;
    }
    .success-icon {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 2rem;
        animation: scaleIn 0.5s;
    }
    .success-icon i {
        font-size: 3rem;
        color: white;
    }
    @keyframes scaleIn {
        from { transform: scale(0); }
        to { transform: scale(1); }
    }
    .info-row {
        padding: 1rem 0;
        border-bottom: 1px solid #e2e8f0;
    }
    .info-row:last-child {
        border-bottom: none;
    }
    .info-label {
        font-weight: 600;
        color: #64748b;
        margin-bottom: 0.25rem;
        font-size: 0.875rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .info-value {
        font-size: 1.1rem;
        color: #1e293b;
        font-weight: 500;
    }
    .calendar-button {
        display: inline-block;
        padding: 0.75rem 1.5rem;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 8px;
        text-decoration: none;
        transition: all 0.3s;
        font-weight: 600;
    }
    .calendar-button:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        color: white;
    }
    .cancel-link {
        color: #ef4444;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.3s;
    }
    .cancel-link:hover {
        color: #dc2626;
        text-decoration: underline;
    }

    /* Dark Mode Styles */
    :root.dark .confirmation-container {
        background: #1e293b;
        border: 1px solid #334155;
    }

    :root.dark .confirmation-container h2 {
        color: #f1f5f9;
    }

    :root.dark .confirmation-container p {
        color: #cbd5e1;
    }

    :root.dark .text-muted {
        color: #94a3b8 !important;
    }

    :root.dark .info-row {
        border-bottom-color: #334155;
    }

    :root.dark .info-label {
        color: #94a3b8;
    }

    :root.dark .info-value {
        color: #f1f5f9;
    }

    :root.dark .alert-info {
        background: #0c4a6e;
        border-color: #075985;
        color: #bae6fd;
    }

    :root.dark .alert-info strong {
        color: #e0f2fe;
    }

    :root.dark hr {
        border-color: #334155;
        opacity: 1;
    }

    :root.dark .cancel-link {
        color: #fca5a5;
    }

    :root.dark .cancel-link:hover {
        color: #f87171;
    }

    :root.dark .badge {
        background: #334155 !important;
        color: #cbd5e1 !important;
    }
</style>

<!-- Hero Section -->
<section class="hero">
    <div class="hero-gradient"></div>
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8">
                <h1 class="hero-title">
                    <i class="bi bi-check-circle-fill me-3"></i>
                    Booking Confirmed
                </h1>
                <p class="hero-subtitle">
                    Your consultation has been successfully scheduled
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Confirmation Content -->
<div class="container py-5">
    <div class="confirmation-container">

        <div class="success-icon">
            <i class="bi bi-check-lg"></i>
        </div>

        <h2 class="text-center mb-4">Thank You, <?= htmlspecialchars($booking['customer_name']) ?>!</h2>
        <p class="text-center text-muted mb-5">
            Your booking has been confirmed. We've sent a confirmation email to <strong><?= htmlspecialchars($booking['customer_email']) ?></strong>
        </p>

        <div class="mb-4">
            <div class="info-row">
                <div class="info-label">Service</div>
                <div class="info-value">
                    <?php if (!empty($booking['service_color'])): ?>
                        <i class="bi bi-star-fill me-2" style="color: <?= htmlspecialchars($booking['service_color']) ?>;"></i>
                    <?php else: ?>
                        <i class="bi bi-star-fill me-2" style="color: #667eea;"></i>
                    <?php endif; ?>
                    <?= htmlspecialchars($booking['service_name']) ?>
                </div>
            </div>

            <div class="info-row">
                <div class="info-label">Date</div>
                <div class="info-value">
                    <i class="bi bi-calendar-event me-2"></i>
                    <?= date('l, F j, Y', strtotime($booking['appointment_date'])) ?>
                </div>
            </div>

            <div class="info-row">
                <div class="info-label">Time</div>
                <div class="info-value">
                    <i class="bi bi-clock me-2"></i>
                    <?= date('g:i A', strtotime($booking['start_time'])) ?> - <?= date('g:i A', strtotime($booking['end_time'])) ?>
                    <span class="badge bg-primary ms-2"><?= $booking['duration_minutes'] ?> minutes</span>
                </div>
            </div>

            <?php if (!empty($booking['customer_phone'])): ?>
            <div class="info-row">
                <div class="info-label">Phone</div>
                <div class="info-value">
                    <i class="bi bi-telephone me-2"></i>
                    <?= htmlspecialchars($booking['customer_phone']) ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($booking['customer_company'])): ?>
            <div class="info-row">
                <div class="info-label">Company</div>
                <div class="info-value">
                    <i class="bi bi-building me-2"></i>
                    <?= htmlspecialchars($booking['customer_company']) ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($booking['notes'])): ?>
            <div class="info-row">
                <div class="info-label">Notes</div>
                <div class="info-value">
                    <i class="bi bi-chat-text me-2"></i>
                    <?= nl2br(htmlspecialchars($booking['notes'])) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            <strong>What's Next?</strong><br>
            We'll send you a reminder 24 hours before your appointment. In the meantime, feel free to prepare any questions or topics you'd like to discuss.
        </div>

        <div class="text-center mt-4">
            <a href="/contact.php" class="calendar-button">
                <i class="bi bi-envelope me-2"></i>
                Contact Us
            </a>
        </div>

        <hr class="my-4">

        <div class="text-center">
            <p class="text-muted mb-2">Need to make changes?</p>
            <a href="/booking-cancel.php?token=<?= htmlspecialchars($booking['cancellation_token']) ?>" class="cancel-link">
                <i class="bi bi-x-circle me-1"></i>Cancel This Booking
            </a>
        </div>

    </div>
</div>

<!-- Analytics Tracking -->
<script src="/analytics/track.js" async defer></script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
