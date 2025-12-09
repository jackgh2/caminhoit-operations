<?php
/**
 * Admin: View Booking Details
 * Comprehensive booking management with notes and attachments
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

if (empty($_GET['id'])) {
    header('Location: /admin/bookings.php');
    exit;
}

$bookingId = (int)$_GET['id'];

// Get booking details
$stmt = $pdo->prepare("
    SELECT ba.*, bs.name as service_name, bs.color as service_color, bs.duration_minutes as service_duration
    FROM booking_appointments ba
    JOIN booking_services bs ON ba.service_id = bs.id
    WHERE ba.id = ?
");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    $_SESSION['error'] = 'Booking not found';
    header('Location: /admin/bookings.php');
    exit;
}

// Get internal notes
$stmt = $pdo->prepare("
    SELECT bn.*, u.username as author_name
    FROM booking_notes bn
    JOIN users u ON bn.user_id = u.id
    WHERE bn.booking_id = ?
    ORDER BY bn.created_at DESC
");
$stmt->execute([$bookingId]);
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get attachments
$stmt = $pdo->prepare("
    SELECT ba.*, u.username as uploader_name
    FROM booking_attachments ba
    JOIN users u ON ba.user_id = u.id
    WHERE ba.booking_id = ?
    ORDER BY ba.uploaded_at DESC
");
$stmt->execute([$bookingId]);
$attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Booking #' . $bookingId;

// Status badge colors
$statusColors = [
    'pending' => 'warning',
    'confirmed' => 'success',
    'cancelled' => 'danger',
    'completed' => 'primary',
    'no_show' => 'secondary'
];
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
        .booking-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        .info-row {
            display: flex;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            color: #64748b;
            min-width: 150px;
        }
        .info-value {
            color: #1e293b;
            flex: 1;
        }
        .note-item {
            background: #f8fafc;
            border-left: 3px solid #667eea;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
        }
        .note-meta {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 0.5rem;
        }
        .attachment-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        .attachment-icon {
            width: 40px;
            height: 40px;
            background: #667eea;
            color: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
        }
        .timeline-item {
            position: relative;
            padding-left: 2rem;
            padding-bottom: 1.5rem;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 24px;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }
        .timeline-item:last-child::before {
            display: none;
        }
        .timeline-dot {
            position: absolute;
            left: 0;
            top: 4px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #667eea;
            border: 3px solid white;
            box-shadow: 0 0 0 2px #667eea;
        }
        .status-badge-large {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
    </style>
</head>
<body data-page-type="admin">

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="container-fluid py-4">

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($_SESSION['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Booking Header -->
    <div class="booking-header">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h1 class="mb-2">
                    <i class="bi bi-calendar-check me-2"></i>
                    Booking #<?= $bookingId ?>
                </h1>
                <p class="mb-0 opacity-90">
                    <?= htmlspecialchars($booking['service_name']) ?> -
                    <?= date('l, F j, Y \a\t g:i A', strtotime($booking['appointment_date'] . ' ' . $booking['start_time'])) ?>
                </p>
            </div>
            <div>
                <span class="badge status-badge-large bg-<?= $statusColors[$booking['status']] ?>">
                    <?= ucfirst(str_replace('_', ' ', $booking['status'])) ?>
                </span>
            </div>
        </div>

        <div class="mt-3">
            <div class="btn-group">
                <a href="/admin/bookings.php" class="btn btn-light btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back to Bookings
                </a>
                <button class="btn btn-light btn-sm" onclick="emailCustomer()">
                    <i class="bi bi-envelope me-1"></i>Email Customer
                </button>
                <div class="btn-group">
                    <button class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-three-dots"></i>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" onclick="changeStatus('confirmed')">Mark as Confirmed</a></li>
                        <li><a class="dropdown-item" href="#" onclick="changeStatus('completed')">Mark as Completed</a></li>
                        <li><a class="dropdown-item" href="#" onclick="changeStatus('no_show')">Mark as No Show</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="changeStatus('cancelled')">Cancel Booking</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Left Column -->
        <div class="col-lg-8">

            <!-- Customer Information -->
            <div class="info-card">
                <h5 class="mb-3">
                    <i class="bi bi-person-circle me-2"></i>Customer Information
                </h5>
                <div class="info-row">
                    <div class="info-label">Name:</div>
                    <div class="info-value"><?= htmlspecialchars($booking['customer_name']) ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Email:</div>
                    <div class="info-value">
                        <a href="mailto:<?= htmlspecialchars($booking['customer_email']) ?>">
                            <?= htmlspecialchars($booking['customer_email']) ?>
                        </a>
                    </div>
                </div>
                <?php if (!empty($booking['customer_phone'])): ?>
                <div class="info-row">
                    <div class="info-label">Phone:</div>
                    <div class="info-value">
                        <a href="tel:<?= htmlspecialchars($booking['customer_phone']) ?>">
                            <?= htmlspecialchars($booking['customer_phone']) ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($booking['customer_company'])): ?>
                <div class="info-row">
                    <div class="info-label">Company:</div>
                    <div class="info-value"><?= htmlspecialchars($booking['customer_company']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($booking['notes'])): ?>
                <div class="info-row">
                    <div class="info-label">Customer Notes:</div>
                    <div class="info-value"><?= nl2br(htmlspecialchars($booking['notes'])) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Health Check / Auto-Generated Data -->
            <?php if (!empty($booking['internal_notes'])): ?>
            <?php
            // Parse health check data from internal_notes
            $healthCheckData = null;
            $notesLines = explode("\n", $booking['internal_notes']);

            // Try to extract structured data
            $overallScore = 0;
            $categories = [];
            $currentCategory = null;

            foreach ($notesLines as $line) {
                // Extract overall score
                if (preg_match('/Overall Score: (\d+)%/', $line, $matches)) {
                    $overallScore = (int)$matches[1];
                }

                // Extract category data with emoji icons
                if (preg_match('/^([🏗️🔒☁️🌱🤖🔄])\s+(.+?):\s+(\d+)\/(\d+)\s+\((\d+)%\)\s+(✓|⚠|✗)\s+(\w+)/', $line, $matches)) {
                    $categories[] = [
                        'icon' => $matches[1],
                        'name' => $matches[2],
                        'score' => (int)$matches[3],
                        'total' => (int)$matches[4],
                        'percentage' => (int)$matches[5],
                        'status' => $matches[7]
                    ];
                }
            }

            // Determine assessment
            $assessment = $overallScore >= 80 ? 'Excellent' : ($overallScore >= 50 ? 'Good' : 'Needs Attention');
            $assessmentColor = $overallScore >= 80 ? '#10b981' : ($overallScore >= 50 ? '#3b82f6' : '#ef4444');
            ?>

            <div class="info-card" style="border-left: 4px solid <?= $assessmentColor ?>; background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%);">
                <div class="d-flex justify-content-between align-items-start mb-4">
                    <div>
                        <h5 class="mb-2" style="color: <?= $assessmentColor ?>;">
                            <i class="bi bi-graph-up-arrow me-2"></i>IT Health Check Results
                        </h5>
                        <div class="d-flex align-items-center gap-3">
                            <div style="font-size: 2.5rem; font-weight: 800; color: <?= $assessmentColor ?>;">
                                <?= $overallScore ?>%
                            </div>
                            <div>
                                <span class="badge" style="background: <?= $assessmentColor ?>; font-size: 0.9rem; padding: 0.5rem 1rem;">
                                    <?= $assessment ?>
                                </span>
                                <div class="small text-muted mt-1">Overall Score</div>
                            </div>
                        </div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('rawHealthCheckData').style.display = document.getElementById('rawHealthCheckData').style.display === 'none' ? 'block' : 'none';">
                        <i class="bi bi-code-square me-1"></i>View Raw Data
                    </button>
                </div>

                <!-- Category Breakdown Grid -->
                <?php if (!empty($categories)): ?>
                <div class="row g-3 mb-4">
                    <?php foreach ($categories as $cat): ?>
                    <?php
                    $statusColors = [
                        'COMPLETE' => ['bg' => '#d1fae5', 'text' => '#065f46', 'border' => '#10b981'],
                        'PARTIAL' => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#f59e0b'],
                        'WEAK' => ['bg' => '#fee2e2', 'text' => '#991b1b', 'border' => '#ef4444']
                    ];
                    $colors = $statusColors[$cat['status']] ?? ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#9ca3af'];
                    ?>
                    <div class="col-md-6">
                        <div style="background: <?= $colors['bg'] ?>; border-left: 4px solid <?= $colors['border'] ?>; border-radius: 8px; padding: 1rem;">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <div style="font-size: 1.5rem; margin-bottom: 0.25rem;"><?= $cat['icon'] ?></div>
                                    <div style="font-weight: 600; color: <?= $colors['text'] ?>; font-size: 0.9rem;">
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div style="font-size: 1.5rem; font-weight: 700; color: <?= $colors['text'] ?>;">
                                        <?= $cat['score'] ?>/<?= $cat['total'] ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: <?= $colors['text'] ?>; opacity: 0.8;">
                                        <?= $cat['percentage'] ?>%
                                    </div>
                                </div>
                            </div>

                            <!-- Progress Bar -->
                            <div style="background: rgba(0,0,0,0.1); height: 6px; border-radius: 999px; overflow: hidden;">
                                <div style="background: <?= $colors['border'] ?>; height: 100%; width: <?= $cat['percentage'] ?>%; transition: width 0.3s ease;"></div>
                            </div>

                            <!-- Status Badge -->
                            <div class="mt-2">
                                <span style="font-size: 0.75rem; font-weight: 600; color: <?= $colors['text'] ?>; text-transform: uppercase;">
                                    <?php if ($cat['status'] === 'COMPLETE'): ?>
                                        ✓ Complete
                                    <?php elseif ($cat['status'] === 'PARTIAL'): ?>
                                        ⚠ Partial
                                    <?php else: ?>
                                        ✗ Needs Work
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Priority Focus Areas -->
                <?php
                $weakCategories = array_filter($categories, function($cat) {
                    return $cat['percentage'] < 60;
                });
                ?>

                <?php if (!empty($weakCategories)): ?>
                <div style="background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                    <div style="font-weight: 700; color: #92400e; margin-bottom: 0.5rem;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>Priority Focus Areas
                    </div>
                    <div style="font-size: 0.9rem; color: #78350f;">
                        The following areas need immediate attention during the consultation:
                    </div>
                    <ul class="mb-0 mt-2" style="color: #92400e;">
                        <?php foreach ($weakCategories as $weak): ?>
                        <li><?= $weak['icon'] ?> <strong><?= htmlspecialchars($weak['name']) ?></strong> (<?= $weak['percentage'] ?>%)</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php else: ?>
                <div style="background: #d1fae5; border-left: 4px solid #10b981; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                    <div style="font-weight: 700; color: #065f46;">
                        <i class="bi bi-check-circle-fill me-2"></i>Excellent Performance
                    </div>
                    <div style="font-size: 0.9rem; color: #047857;">
                        Strong performance across all categories! Focus on maintaining standards and exploring innovation opportunities.
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- Raw Data (Collapsible) -->
                <div id="rawHealthCheckData" style="display: none; background: #f8f9fa; padding: 1rem; border-radius: 8px; font-family: 'Courier New', monospace; font-size: 0.85rem; white-space: pre-wrap; margin-top: 1rem; border: 1px solid #e5e7eb;">
<?= htmlspecialchars($booking['internal_notes']) ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Internal Notes (Staff Comments) -->
            <div class="info-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">
                        <i class="bi bi-sticky me-2"></i>Internal Notes
                        <span class="badge bg-secondary"><?= count($notes) ?></span>
                    </h5>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addNoteModal">
                        <i class="bi bi-plus-circle me-1"></i>Add Note
                    </button>
                </div>

                <?php if (empty($notes)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-sticky display-4"></i>
                        <p class="mt-2">No internal notes yet</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notes as $note): ?>
                        <div class="note-item">
                            <div class="note-meta">
                                <i class="bi bi-person-circle me-1"></i>
                                <strong><?= htmlspecialchars($note['author_name']) ?></strong>
                                <span class="ms-2 text-muted">
                                    <?= date('M j, Y \a\t g:i A', strtotime($note['created_at'])) ?>
                                </span>
                            </div>
                            <div><?= nl2br(htmlspecialchars($note['note'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Attachments -->
            <div class="info-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">
                        <i class="bi bi-paperclip me-2"></i>Attachments
                        <span class="badge bg-secondary"><?= count($attachments) ?></span>
                    </h5>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadFileModal">
                        <i class="bi bi-upload me-1"></i>Upload File
                    </button>
                </div>

                <?php if (empty($attachments)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-file-earmark display-4"></i>
                        <p class="mt-2">No attachments yet</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($attachments as $attachment): ?>
                        <div class="attachment-item">
                            <div class="attachment-icon">
                                <i class="bi bi-file-earmark"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div>
                                    <strong><?= htmlspecialchars($attachment['original_name']) ?></strong>
                                </div>
                                <small class="text-muted">
                                    <?= round($attachment['file_size'] / 1024, 2) ?> KB •
                                    Uploaded by <?= htmlspecialchars($attachment['uploader_name']) ?> •
                                    <?= date('M j, Y', strtotime($attachment['uploaded_at'])) ?>
                                </small>
                            </div>
                            <div>
                                <a href="/uploads/bookings/<?= htmlspecialchars($attachment['file_name']) ?>" class="btn btn-sm btn-outline-primary" download>
                                    <i class="bi bi-download"></i>
                                </a>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteAttachment(<?= $attachment['id'] ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>

        <!-- Right Column -->
        <div class="col-lg-4">

            <!-- Booking Details -->
            <div class="info-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">
                        <i class="bi bi-info-circle me-2"></i>Booking Details
                    </h5>
                    <button class="btn btn-sm btn-outline-primary" id="editDateTimeBtn" onclick="toggleEditMode()">
                        <i class="bi bi-pencil me-1"></i>Edit Date/Time
                    </button>
                </div>
                <div class="info-row">
                    <div class="info-label">Service:</div>
                    <div class="info-value">
                        <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background-color: <?= htmlspecialchars($booking['service_color']) ?>;" class="me-1"></span>
                        <?= htmlspecialchars($booking['service_name']) ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Date:</div>
                    <div class="info-value">
                        <span id="displayDate"><?= date('l, F j, Y', strtotime($booking['appointment_date'])) ?></span>
                        <input type="date" id="editDate" class="form-control form-control-sm" value="<?= $booking['appointment_date'] ?>" style="display: none;">
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Time:</div>
                    <div class="info-value">
                        <span id="displayTime">
                            <?= date('g:i A', strtotime($booking['start_time'])) ?> -
                            <?= date('g:i A', strtotime($booking['end_time'])) ?>
                        </span>
                        <div id="editTime" style="display: none;">
                            <input type="time" id="editStartTime" class="form-control form-control-sm mb-1" value="<?= date('H:i', strtotime($booking['start_time'])) ?>">
                            <small class="text-muted">Duration: <?= $booking['duration_minutes'] ?> min</small>
                        </div>
                    </div>
                </div>
                <div id="editActions" style="display: none;" class="mt-3">
                    <button class="btn btn-sm btn-success me-2" onclick="saveDateTime()">
                        <i class="bi bi-check-circle me-1"></i>Save Changes
                    </button>
                    <button class="btn btn-sm btn-secondary" onclick="cancelEdit()">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                </div>
                <div class="info-row">
                    <div class="info-label">Duration:</div>
                    <div class="info-value"><?= $booking['duration_minutes'] ?> minutes</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Status:</div>
                    <div class="info-value">
                        <span class="badge bg-<?= $statusColors[$booking['status']] ?>">
                            <?= ucfirst(str_replace('_', ' ', $booking['status'])) ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Timeline -->
            <div class="info-card">
                <h5 class="mb-3">
                    <i class="bi bi-clock-history me-2"></i>Timeline
                </h5>

                <div class="timeline-item">
                    <div class="timeline-dot"></div>
                    <div class="timeline-content">
                        <strong>Booking Created</strong>
                        <div class="small text-muted">
                            <?= date('M j, Y \a\t g:i A', strtotime($booking['created_at'])) ?>
                        </div>
                    </div>
                </div>

                <?php if ($booking['confirmation_sent']): ?>
                <div class="timeline-item">
                    <div class="timeline-dot" style="background: #10b981;"></div>
                    <div class="timeline-content">
                        <strong>Confirmation Sent</strong>
                        <div class="small text-muted">Email sent to customer</div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($booking['reminder_sent']): ?>
                <div class="timeline-item">
                    <div class="timeline-dot" style="background: #f59e0b;"></div>
                    <div class="timeline-content">
                        <strong>Reminder Sent</strong>
                        <div class="small text-muted">24h reminder email sent</div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($booking['status'] === 'cancelled' && !empty($booking['cancellation_reason'])): ?>
                <div class="timeline-item">
                    <div class="timeline-dot" style="background: #ef4444;"></div>
                    <div class="timeline-content">
                        <strong>Cancelled</strong>
                        <div class="small text-muted"><?= htmlspecialchars($booking['cancellation_reason']) ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($booking['updated_at'] != $booking['created_at']): ?>
                <div class="timeline-item">
                    <div class="timeline-dot" style="background: #667eea;"></div>
                    <div class="timeline-content">
                        <strong>Last Updated</strong>
                        <div class="small text-muted">
                            <?= date('M j, Y \a\t g:i A', strtotime($booking['updated_at'])) ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Metadata -->
            <div class="info-card">
                <h6 class="text-muted mb-3">Metadata</h6>
                <div class="info-row">
                    <div class="info-label small">IP Address:</div>
                    <div class="info-value small"><?= htmlspecialchars($booking['ip_address'] ?? 'N/A') ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label small">Tokens:</div>
                    <div class="info-value small">
                        <button class="btn btn-xs btn-outline-secondary" onclick="copyToken('<?= $booking['confirmation_token'] ?>')">
                            <i class="bi bi-clipboard"></i> Confirmation
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

<!-- Add Note Modal -->
<div class="modal fade" id="addNoteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Internal Note</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/admin/add-booking-note.php">
                <input type="hidden" name="booking_id" value="<?= $bookingId ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Note *</label>
                        <textarea class="form-control" name="note" rows="5" required placeholder="Add internal note (not visible to customer)..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Note</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upload File Modal -->
<div class="modal fade" id="uploadFileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Upload File</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/admin/upload-booking-file.php" enctype="multipart/form-data">
                <input type="hidden" name="booking_id" value="<?= $bookingId ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select File *</label>
                        <input type="file" class="form-control" name="file" required>
                        <small class="text-muted">Max 10MB. PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, ZIP allowed</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Error Modal -->
<div class="modal fade" id="errorModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle me-2"></i>Error
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="errorModalMessage" class="mb-0"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div class="modal fade" id="successModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <h5 class="modal-title">
                    <i class="bi bi-check-circle me-2"></i>Success
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="successModalMessage" class="mb-0"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">
                    <i class="bi bi-question-circle me-2"></i>Confirm Action
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="confirmModalMessage" class="mb-0"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmModalOkBtn">OK</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
let editMode = false;

// Initialize modals
const errorModal = new bootstrap.Modal(document.getElementById('errorModal'));
const successModal = new bootstrap.Modal(document.getElementById('successModal'));
const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));

// Helper functions for styled alerts
function showError(message) {
    document.getElementById('errorModalMessage').textContent = message;
    errorModal.show();
}

function showSuccess(message, callback) {
    document.getElementById('successModalMessage').textContent = message;
    successModal.show();

    if (callback) {
        document.getElementById('successModal').addEventListener('hidden.bs.modal', function handler() {
            callback();
            this.removeEventListener('hidden.bs.modal', handler);
        });
    }
}

function showConfirm(message, onConfirm) {
    document.getElementById('confirmModalMessage').textContent = message;
    document.getElementById('confirmModalOkBtn').onclick = function() {
        confirmModal.hide();
        if (onConfirm) onConfirm();
    };
    confirmModal.show();
}

function toggleEditMode() {
    editMode = !editMode;

    if (editMode) {
        // Show edit fields
        document.getElementById('displayDate').style.display = 'none';
        document.getElementById('editDate').style.display = 'block';
        document.getElementById('displayTime').style.display = 'none';
        document.getElementById('editTime').style.display = 'block';
        document.getElementById('editActions').style.display = 'block';
        document.getElementById('editDateTimeBtn').style.display = 'none';
    } else {
        cancelEdit();
    }
}

function cancelEdit() {
    editMode = false;
    // Hide edit fields
    document.getElementById('displayDate').style.display = 'inline';
    document.getElementById('editDate').style.display = 'none';
    document.getElementById('displayTime').style.display = 'inline';
    document.getElementById('editTime').style.display = 'none';
    document.getElementById('editActions').style.display = 'none';
    document.getElementById('editDateTimeBtn').style.display = 'inline-block';
}

function saveDateTime() {
    const newDate = document.getElementById('editDate').value;
    const newStartTime = document.getElementById('editStartTime').value;

    if (!newDate || !newStartTime) {
        showError('Please fill in all date and time fields');
        return;
    }

    // Disable save button to prevent double-clicks
    const saveBtn = event.target;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

    fetch('/admin/update-booking-datetime.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `booking_id=<?= $bookingId ?>&appointment_date=${encodeURIComponent(newDate)}&start_time=${encodeURIComponent(newStartTime)}&duration_minutes=<?= $booking['duration_minutes'] ?>`
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.text();
    })
    .then(text => {
        if (!text.trim()) {
            throw new Error('Empty response from server');
        }
        return JSON.parse(text);
    })
    .then(data => {
        if (data.success) {
            showSuccess('Booking date/time updated successfully', () => {
                location.reload();
            });
        } else {
            showError(data.error || 'Failed to update booking date/time');
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Save Changes';
        }
    })
    .catch(error => {
        showError('Error: ' + error.message);
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Save Changes';
    });
}

function changeStatus(newStatus) {
    showConfirm(`Change booking status to "${newStatus}"?`, function() {
        fetch('/admin/update-booking-status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `booking_id=<?= $bookingId ?>&status=${newStatus}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                showError(data.error || 'Failed to update status');
            }
        })
        .catch(error => {
            showError('Error: ' + error.message);
        });
    });
}

function emailCustomer() {
    window.location.href = 'mailto:<?= htmlspecialchars($booking['customer_email']) ?>?subject=Booking #<?= $bookingId ?> - <?= urlencode($booking['service_name']) ?>';
}

function copyToken(token) {
    navigator.clipboard.writeText(token)
        .then(() => {
            showSuccess('Token copied to clipboard!');
        })
        .catch(() => {
            showError('Failed to copy token to clipboard');
        });
}

function deleteAttachment(attachmentId) {
    showConfirm('Delete this attachment?', function() {
        fetch('/admin/delete-booking-attachment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'attachment_id=' + attachmentId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                showError(data.error || 'Failed to delete attachment');
            }
        })
        .catch(error => {
            showError('Error: ' + error.message);
        });
    });
}
</script>

</body>
</html>
