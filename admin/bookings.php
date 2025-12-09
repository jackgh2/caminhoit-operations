<?php
/**
 * Admin: Manage Bookings
 * View, filter, and manage all appointments
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/BookingHelper.php';

// Access control (Admin only)
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'administrator') {
    header('Location: /login.php');
    exit;
}

$bookingHelper = new BookingHelper($pdo);

// Get filters - default to showing only upcoming active bookings
$filters = [
    'status' => $_GET['status'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'service_id' => $_GET['service_id'] ?? '',
    'show_all' => isset($_GET['show_all']) ? $_GET['show_all'] : '0'
];

// If not explicitly showing all, filter to upcoming only
if ($filters['show_all'] === '0' && empty($filters['status']) && empty($filters['date_from'])) {
    $filters['date_from'] = date('Y-m-d'); // Only show today onwards
    if (empty($filters['status'])) {
        $filters['status_not'] = ['cancelled', 'completed', 'no_show']; // Exclude finished/cancelled
    }
}

// Get all bookings with filters
$bookings = $bookingHelper->getAllBookings($filters);

// Get all services for filter dropdown
$stmt = $pdo->query("SELECT id, name FROM booking_services WHERE is_active = 1 ORDER BY sort_order ASC");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stmt = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN appointment_date >= CURDATE() AND status IN ('confirmed', 'pending') THEN 1 ELSE 0 END) as upcoming
    FROM booking_appointments
");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$page_title = 'Booking Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($page_title) ?> | CaminhoIT Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="/assets/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .stat-label {
            color: #64748b;
            font-size: 0.875rem;
            text-transform: uppercase;
        }
        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .status-confirmed { background: #d1fae5; color: #065f46; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .status-completed { background: #dbeafe; color: #1e40af; }
        .status-no_show { background: #f3f4f6; color: #374151; }

        .booking-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
    </style>
</head>
<body data-page-type="admin">

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-calendar-check me-2"></i>Booking Management</h1>
        <div>
            <a href="/admin/booking-settings.php" class="btn btn-outline-primary">
                <i class="bi bi-gear me-2"></i>Settings
            </a>
            <a href="/book.php" class="btn btn-primary" target="_blank">
                <i class="bi bi-plus-circle me-2"></i>View Booking Page
            </a>
        </div>
    </div>

    <!-- Statistics -->
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['total'] ?></div>
                <div class="stat-label">Total Bookings</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['upcoming'] ?></div>
                <div class="stat-label">Upcoming</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['confirmed'] ?></div>
                <div class="stat-label">Confirmed</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['pending'] ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['completed'] ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['cancelled'] ?></div>
                <div class="stat-label">Cancelled</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="confirmed" <?= $filters['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="pending" <?= $filters['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="cancelled" <?= $filters['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    <option value="completed" <?= $filters['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="no_show" <?= $filters['status'] === 'no_show' ? 'selected' : '' ?>>No Show</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Service</label>
                <select name="service_id" class="form-select">
                    <option value="">All Services</option>
                    <?php foreach ($services as $service): ?>
                        <option value="<?= $service['id'] ?>" <?= $filters['service_id'] == $service['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($service['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Date From</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filters['date_from']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Date To</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filters['date_to']) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <a href="/admin/bookings.php" class="btn btn-outline-secondary">
                    <i class="bi bi-x-circle"></i>
                </a>
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="show_all" value="1" id="showAllCheck" <?= $filters['show_all'] === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="showAllCheck">
                        Show cancelled, expired, and past appointments
                    </label>
                </div>
            </div>
        </form>
    </div>

    <!-- Bookings Table -->
    <div class="booking-table">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date & Time</th>
                        <th>Customer</th>
                        <th>Service</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Contact</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bookings)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox display-4"></i>
                                <p class="mt-3">No bookings found</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td>
                                    <div>
                                        <strong><?= date('M j, Y', strtotime($booking['appointment_date'])) ?></strong><br>
                                        <small class="text-muted"><?= date('g:i A', strtotime($booking['start_time'])) ?></small>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <strong><?= htmlspecialchars($booking['customer_name']) ?></strong><br>
                                        <?php if (!empty($booking['customer_company'])): ?>
                                            <small class="text-muted"><?= htmlspecialchars($booking['customer_company']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background-color: <?= htmlspecialchars($booking['service_color']) ?>;" class="me-1"></span>
                                    <?= htmlspecialchars($booking['service_name']) ?>
                                </td>
                                <td><?= $booking['duration_minutes'] ?> min</td>
                                <td>
                                    <span class="status-badge status-<?= $booking['status'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $booking['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <div>
                                        <a href="mailto:<?= htmlspecialchars($booking['customer_email']) ?>">
                                            <i class="bi bi-envelope"></i>
                                        </a>
                                        <?php if (!empty($booking['customer_phone'])): ?>
                                            <a href="tel:<?= htmlspecialchars($booking['customer_phone']) ?>" class="ms-2">
                                                <i class="bi bi-telephone"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="/admin/view-booking.php?id=<?= $booking['id'] ?>" class="btn btn-outline-primary" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="mailto:<?= htmlspecialchars($booking['customer_email']) ?>" class="btn btn-outline-secondary" title="Email Customer">
                                            <i class="bi bi-envelope"></i>
                                        </a>
                                        <?php if ($booking['status'] === 'pending' || $booking['status'] === 'confirmed'): ?>
                                            <button type="button" class="btn btn-outline-danger" onclick="quickCancel(<?= $booking['id'] ?>)" title="Cancel">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Cancel Booking Modal -->
<div class="modal fade" id="cancelBookingModal" tabindex="-1" aria-labelledby="cancelBookingModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="cancelBookingModalLabel">
                    <i class="bi bi-exclamation-triangle me-2"></i>Cancel Booking
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Are you sure you want to cancel this booking?</p>
                <div class="mb-3">
                    <label for="cancelReason" class="form-label">Reason for cancellation (optional):</label>
                    <textarea class="form-control" id="cancelReason" rows="3" placeholder="Enter reason for cancellation..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>No, Keep Booking
                </button>
                <button type="button" class="btn btn-danger" id="confirmCancelBtn">
                    <i class="bi bi-check-circle me-1"></i>Yes, Cancel Booking
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
let currentBookingId = null;
const cancelModal = new bootstrap.Modal(document.getElementById('cancelBookingModal'));

function quickCancel(bookingId) {
    currentBookingId = bookingId;
    document.getElementById('cancelReason').value = '';
    cancelModal.show();
}

document.getElementById('confirmCancelBtn').addEventListener('click', function() {
    const reason = document.getElementById('cancelReason').value;
    const btn = this;

    // Disable button to prevent double-clicks
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Cancelling...';

    fetch('/admin/update-booking-status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `booking_id=${currentBookingId}&status=cancelled&reason=${encodeURIComponent(reason)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            cancelModal.hide();

            // Show success message
            const successAlert = document.createElement('div');
            successAlert.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
            successAlert.style.zIndex = '9999';
            successAlert.innerHTML = `
                <i class="bi bi-check-circle me-2"></i>Booking cancelled successfully
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(successAlert);

            setTimeout(() => location.reload(), 1500);
        } else {
            alert('Error: ' + (data.error || 'Failed to cancel booking'));
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Yes, Cancel Booking';
        }
    })
    .catch(error => {
        alert('Error: ' + error);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Yes, Cancel Booking';
    });
});

// Reset button when modal is closed
document.getElementById('cancelBookingModal').addEventListener('hidden.bs.modal', function () {
    const btn = document.getElementById('confirmCancelBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Yes, Cancel Booking';
});
</script>

</body>
</html>
