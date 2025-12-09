<?php
/**
 * Admin: Booking System Settings
 * Manage services, availability, and global settings
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

// Get all services
$stmt = $pdo->query("SELECT * FROM booking_services ORDER BY sort_order ASC, name ASC");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get availability
$stmt = $pdo->query("SELECT * FROM booking_availability ORDER BY day_of_week ASC");
$availability = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM booking_settings");
$settingsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
$settings = [];
foreach ($settingsData as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$page_title = 'Booking Settings';
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
        .settings-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .service-item {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.3s;
        }
        .service-item:hover {
            border-color: #667eea;
        }
        .color-preview {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            border: 2px solid #e2e8f0;
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
        <h1><i class="bi bi-gear me-2"></i>Booking System Settings</h1>
        <div class="btn-group">
            <a href="/admin/booking-blocked-slots.php" class="btn btn-outline-danger">
                <i class="bi bi-calendar-x me-2"></i>Manage Blocked Dates
            </a>
            <a href="/admin/bookings.php" class="btn btn-outline-primary">
                <i class="bi bi-arrow-left me-2"></i>Back to Bookings
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">

            <!-- Services -->
            <div class="settings-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3><i class="bi bi-briefcase me-2"></i>Services</h3>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                        <i class="bi bi-plus-circle me-1"></i>Add Service
                    </button>
                </div>

                <?php foreach ($services as $service): ?>
                    <div class="service-item">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <div class="color-preview" style="background-color: <?= htmlspecialchars($service['color']) ?>;"></div>
                            </div>
                            <div class="col">
                                <h5 class="mb-1"><?= htmlspecialchars($service['name']) ?></h5>
                                <p class="text-muted mb-0 small"><?= htmlspecialchars($service['description']) ?></p>
                            </div>
                            <div class="col-auto">
                                <span class="badge bg-primary"><?= $service['duration_minutes'] ?> min</span>
                            </div>
                            <div class="col-auto">
                                <span class="badge <?= $service['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $service['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </div>
                            <div class="col-auto">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" onclick="editService(<?= $service['id'] ?>)" title="Edit Service">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-outline-danger" onclick="deleteService(<?= $service['id'] ?>, '<?= htmlspecialchars($service['name'], ENT_QUOTES) ?>')" title="Delete Service">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Availability -->
            <div class="settings-card">
                <h3 class="mb-4"><i class="bi bi-clock me-2"></i>Working Hours</h3>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($days as $dayIndex => $dayName): ?>
                                <?php
                                $dayAvailability = null;
                                foreach ($availability as $avail) {
                                    if ($avail['day_of_week'] == $dayIndex) {
                                        $dayAvailability = $avail;
                                        break;
                                    }
                                }
                                ?>
                                <tr>
                                    <td><strong><?= $dayName ?></strong></td>
                                    <?php if ($dayAvailability): ?>
                                        <td><?= date('g:i A', strtotime($dayAvailability['start_time'])) ?></td>
                                        <td><?= date('g:i A', strtotime($dayAvailability['end_time'])) ?></td>
                                        <td>
                                            <span class="badge <?= $dayAvailability['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                                <?= $dayAvailability['is_active'] ? 'Open' : 'Closed' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary" onclick="editAvailability(<?= $dayAvailability['id'] ?>, <?= $dayIndex ?>, '<?= $dayName ?>', '<?= $dayAvailability['start_time'] ?>', '<?= $dayAvailability['end_time'] ?>', <?= $dayAvailability['is_active'] ?>)" title="Edit Hours">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="btn btn-outline-danger" onclick="deleteAvailability(<?= $dayAvailability['id'] ?>, '<?= $dayName ?>')" title="Remove Hours">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    <?php else: ?>
                                        <td colspan="3" class="text-muted">Closed</td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-success" onclick="addAvailability(<?= $dayIndex ?>, '<?= $dayName ?>')" title="Add Hours">
                                                <i class="bi bi-plus-circle"></i>
                                            </button>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <div class="col-lg-4">

            <!-- Global Settings -->
            <div class="settings-card">
                <h3 class="mb-4"><i class="bi bi-sliders me-2"></i>Global Settings</h3>

                <form method="POST" action="/admin/update-booking-settings.php">
                    <div class="mb-3">
                        <label class="form-label">Timezone</label>
                        <select class="form-select" name="timezone">
                            <option value="Europe/Lisbon" <?= ($settings['timezone'] ?? '') === 'Europe/Lisbon' ? 'selected' : '' ?>>Europe/Lisbon</option>
                            <option value="Europe/London" <?= ($settings['timezone'] ?? '') === 'Europe/London' ? 'selected' : '' ?>>Europe/London</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Buffer Between Bookings (minutes)</label>
                        <input type="number" class="form-control" name="booking_buffer_minutes" value="<?= htmlspecialchars($settings['booking_buffer_minutes'] ?? '15') ?>" min="0" max="60">
                        <small class="text-muted">Time between appointments</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Advance Booking (days)</label>
                        <input type="number" class="form-control" name="advance_booking_days" value="<?= htmlspecialchars($settings['advance_booking_days'] ?? '30') ?>" min="1" max="365">
                        <small class="text-muted">How far ahead customers can book</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Minimum Notice (hours)</label>
                        <input type="number" class="form-control" name="min_notice_hours" value="<?= htmlspecialchars($settings['min_notice_hours'] ?? '24') ?>" min="1" max="168">
                        <small class="text-muted">Minimum time before appointment</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reminder Time (hours before)</label>
                        <input type="number" class="form-control" name="reminder_hours_before" value="<?= htmlspecialchars($settings['reminder_hours_before'] ?? '24') ?>" min="1" max="72">
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="allow_weekend_bookings" value="1" <?= ($settings['allow_weekend_bookings'] ?? '0') == '1' ? 'checked' : '' ?>>
                        <label class="form-check-label">Allow Weekend Bookings</label>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="send_reminders" value="1" <?= ($settings['send_reminders'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <label class="form-check-label">Send Reminders</label>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="confirmation_email_enabled" value="1" <?= ($settings['confirmation_email_enabled'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <label class="form-check-label">Send Confirmation Emails</label>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="discord_webhook_enabled" value="1" <?= ($settings['discord_webhook_enabled'] ?? '1') == '1' ? 'checked' : '' ?>>
                        <label class="form-check-label">Discord Notifications</label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Discord Webhook URL</label>
                        <input type="url" class="form-control" name="discord_webhook_url" value="<?= htmlspecialchars($settings['discord_webhook_url'] ?? '') ?>" placeholder="https://discord.com/api/webhooks/...">
                        <small class="text-muted">Separate webhook for booking notifications (different from ticket system)</small>
                    </div>

                    <hr>

                    <div class="mb-3">
                        <label class="form-label">Company Phone</label>
                        <input type="tel" class="form-control" name="company_phone" value="<?= htmlspecialchars($settings['company_phone'] ?? '+351 963 452 653') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Company Email</label>
                        <input type="email" class="form-control" name="company_email" value="<?= htmlspecialchars($settings['company_email'] ?? 'support@caminhoit.com') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-circle me-2"></i>Save Settings
                    </button>
                </form>
            </div>

        </div>
    </div>

</div>

<!-- Add Service Modal -->
<div class="modal fade" id="addServiceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/admin/add-booking-service.php">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Service Name *</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Duration (minutes) *</label>
                        <input type="number" class="form-control" name="duration_minutes" value="30" min="15" max="240" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Color</label>
                        <input type="color" class="form-control form-control-color" name="color" value="#667eea">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Service</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Service Modal -->
<div class="modal fade" id="editServiceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/admin/update-booking-service.php" id="editServiceForm">
                <input type="hidden" name="service_id" id="edit_service_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Service Name *</label>
                        <input type="text" class="form-control" name="name" id="edit_service_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="edit_service_description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Duration (minutes) *</label>
                        <input type="number" class="form-control" name="duration_minutes" id="edit_service_duration" min="15" max="240" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Color</label>
                        <input type="color" class="form-control form-control-color" name="color" id="edit_service_color">
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" id="edit_service_active" value="1">
                        <label class="form-check-label">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Service</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Availability Modal -->
<div class="modal fade" id="editAvailabilityModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="availabilityModalTitle">Edit Working Hours</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/admin/update-booking-availability.php" id="availabilityForm">
                <input type="hidden" name="availability_id" id="availability_id">
                <input type="hidden" name="day_of_week" id="availability_day">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Day</label>
                        <input type="text" class="form-control" id="availability_day_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Start Time *</label>
                        <input type="time" class="form-control" name="start_time" id="availability_start_time" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">End Time *</label>
                        <input type="time" class="form-control" name="end_time" id="availability_end_time" required>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" id="availability_is_active" value="1">
                        <label class="form-check-label">Active (Open for bookings)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Delete Service
function deleteService(serviceId, serviceName) {
    if (confirm(`Are you sure you want to delete "${serviceName}"?\n\nThis will cancel all future bookings for this service.`)) {
        fetch('/admin/delete-booking-service.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'service_id=' + serviceId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Service deleted successfully');
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Failed to delete service'));
            }
        })
        .catch(error => {
            alert('Error deleting service: ' + error);
        });
    }
}

// Edit Service
function editService(serviceId) {
    // Get service data via AJAX
    fetch('/admin/get-booking-service.php?id=' + serviceId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit_service_id').value = data.service.id;
                document.getElementById('edit_service_name').value = data.service.name;
                document.getElementById('edit_service_description').value = data.service.description || '';
                document.getElementById('edit_service_duration').value = data.service.duration_minutes;
                document.getElementById('edit_service_color').value = data.service.color;
                document.getElementById('edit_service_active').checked = data.service.is_active == 1;

                var modal = new bootstrap.Modal(document.getElementById('editServiceModal'));
                modal.show();
            } else {
                alert('Error loading service data');
            }
        })
        .catch(error => {
            alert('Error: ' + error);
        });
}

// Edit Availability
function editAvailability(availabilityId, dayIndex, dayName, startTime, endTime, isActive) {
    document.getElementById('availabilityModalTitle').textContent = 'Edit Working Hours - ' + dayName;
    document.getElementById('availability_id').value = availabilityId;
    document.getElementById('availability_day').value = dayIndex;
    document.getElementById('availability_day_name').value = dayName;
    document.getElementById('availability_start_time').value = startTime;
    document.getElementById('availability_end_time').value = endTime;
    document.getElementById('availability_is_active').checked = isActive == 1;

    var modal = new bootstrap.Modal(document.getElementById('editAvailabilityModal'));
    modal.show();
}

// Add Availability
function addAvailability(dayIndex, dayName) {
    document.getElementById('availabilityModalTitle').textContent = 'Add Working Hours - ' + dayName;
    document.getElementById('availability_id').value = '';
    document.getElementById('availability_day').value = dayIndex;
    document.getElementById('availability_day_name').value = dayName;
    document.getElementById('availability_start_time').value = '09:00';
    document.getElementById('availability_end_time').value = '17:00';
    document.getElementById('availability_is_active').checked = true;

    var modal = new bootstrap.Modal(document.getElementById('editAvailabilityModal'));
    modal.show();
}

// Delete Availability
function deleteAvailability(availabilityId, dayName) {
    if (confirm(`Are you sure you want to remove working hours for ${dayName}?\n\nThis will prevent bookings on this day.`)) {
        fetch('/admin/delete-booking-availability.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'availability_id=' + availabilityId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Working hours removed successfully');
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Failed to delete working hours'));
            }
        })
        .catch(error => {
            alert('Error: ' + error);
        });
    }
}
</script>

</body>
</html>
