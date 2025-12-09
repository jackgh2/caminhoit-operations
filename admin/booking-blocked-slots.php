<?php
/**
 * Admin: Manage Blocked Time Slots
 * Block specific dates/times and create recurring blocks
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

// Get all blocked slots
$stmt = $pdo->query("
    SELECT * FROM booking_blocked_slots
    ORDER BY blocked_date ASC, start_time ASC
");
$blockedSlots = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Blocked Time Slots';
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
        .blocked-slot-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
            border-left: 4px solid #ef4444;
        }
        .blocked-slot-card.recurring {
            border-left-color: #f59e0b;
        }
        .badge-holiday {
            background: #ef4444;
            color: white;
        }
        .badge-vacation {
            background: #3b82f6;
            color: white;
        }
        .badge-break {
            background: #8b5cf6;
            color: white;
        }
        .badge-recurring {
            background: #f59e0b;
            color: white;
        }
    </style>
</head>
<body>

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

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-calendar-x me-2"></i>Blocked Time Slots</h1>
        <div class="btn-group">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBlockedSlotModal">
                <i class="bi bi-plus-circle me-2"></i>Block Date/Time
            </button>
            <a href="/admin/booking-settings.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-2"></i>Back to Settings
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Current Blocked Slots</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($blockedSlots)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-calendar-check display-1"></i>
                            <p class="mt-3">No blocked time slots</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBlockedSlotModal">
                                <i class="bi bi-plus-circle me-2"></i>Add First Block
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($blockedSlots as $slot): ?>
                            <div class="blocked-slot-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="mb-2">
                                            <?= date('l, F j, Y', strtotime($slot['blocked_date'])) ?>
                                        </h5>
                                        <p class="mb-2">
                                            <i class="bi bi-clock me-1"></i>
                                            <?= date('g:i A', strtotime($slot['start_time'])) ?> -
                                            <?= date('g:i A', strtotime($slot['end_time'])) ?>
                                        </p>
                                        <?php if (!empty($slot['reason'])): ?>
                                            <p class="mb-0 text-muted">
                                                <i class="bi bi-info-circle me-1"></i>
                                                <?= htmlspecialchars($slot['reason']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteBlockedSlot(<?= $slot['id'] ?>, '<?= date('M j, Y', strtotime($slot['blocked_date'])) ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Quick Blocks</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small">Common dates to block:</p>

                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-primary btn-sm" onclick="quickBlockChristmas()">
                            <i class="bi bi-snow me-2"></i>Christmas Day
                        </button>
                        <button class="btn btn-outline-primary btn-sm" onclick="quickBlockNewYear()">
                            <i class="bi bi-calendar-star me-2"></i>New Year's Day
                        </button>
                        <button class="btn btn-outline-primary btn-sm" onclick="quickBlockEaster()">
                            <i class="bi bi-egg me-2"></i>Easter (Custom Date)
                        </button>
                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addBlockedSlotModal">
                            <i class="bi bi-calendar-range me-2"></i>Custom Date Range
                        </button>
                    </div>

                    <hr class="my-3">

                    <h6>Tips:</h6>
                    <ul class="small text-muted">
                        <li>Block full days for holidays</li>
                        <li>Block specific hours for lunch breaks</li>
                        <li>Block date ranges for vacations</li>
                        <li>Customers won't see blocked times</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Add Blocked Slot Modal -->
<div class="modal fade" id="addBlockedSlotModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Block Date/Time</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/admin/add-blocked-slot.php">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Date *</label>
                        <input type="date" class="form-control" name="blocked_date" id="blocked_date" required min="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Start Time *</label>
                                <input type="time" class="form-control" name="start_time" value="00:00" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">End Time *</label>
                                <input type="time" class="form-control" name="end_time" value="23:59" required>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reason (Optional)</label>
                        <input type="text" class="form-control" name="reason" placeholder="e.g., Christmas Holiday, Team Meeting, etc.">
                    </div>

                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Tip:</strong> To block a full day, use 00:00 to 23:59
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Block This Time</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Delete blocked slot
function deleteBlockedSlot(slotId, dateStr) {
    if (confirm(`Remove block for ${dateStr}?`)) {
        fetch('/admin/delete-blocked-slot.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'slot_id=' + slotId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Blocked slot removed successfully');
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Failed to remove blocked slot'));
            }
        })
        .catch(error => {
            alert('Error: ' + error);
        });
    }
}

// Quick block Christmas
function quickBlockChristmas() {
    const year = new Date().getFullYear();
    document.getElementById('blocked_date').value = `${year}-12-25`;
    document.querySelector('[name="reason"]').value = 'Christmas Day';
    var modal = new bootstrap.Modal(document.getElementById('addBlockedSlotModal'));
    modal.show();
}

// Quick block New Year
function quickBlockNewYear() {
    const year = new Date().getFullYear() + 1;
    document.getElementById('blocked_date').value = `${year}-01-01`;
    document.querySelector('[name="reason"]').value = 'New Year\'s Day';
    var modal = new bootstrap.Modal(document.getElementById('addBlockedSlotModal'));
    modal.show();
}

// Quick block Easter
function quickBlockEaster() {
    const date = prompt('Enter Easter date (YYYY-MM-DD):', '');
    if (date) {
        document.getElementById('blocked_date').value = date;
        document.querySelector('[name="reason"]').value = 'Easter';
        var modal = new bootstrap.Modal(document.getElementById('addBlockedSlotModal'));
        modal.show();
    }
}
</script>

</body>
</html>
