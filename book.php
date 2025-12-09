<?php
/**
 * Public Booking Page (Calendly Alternative)
 * Self-hosted appointment booking system
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/SEOHelper.php';

// Initialize SEO
$seo = new SEOHelper();
$seo->setTitle('Book a Consultation | CaminhoIT - IT Support & MSP Services')
    ->setDescription('Schedule your free IT consultation with CaminhoIT. Expert MSP services across Portugal (Lisbon, Porto, Algarve) and UK. Book your appointment online.')
    ->setKeywords('book IT consultation, schedule MSP meeting, IT support appointment, managed services consultation, cloud migration planning')
    ->setType('WebPage');

$page_title = 'Book Your Consultation | CaminhoIT';

// Get active services
$stmt = $pdo->query("
    SELECT * FROM booking_services
    WHERE is_active = 1
    ORDER BY sort_order ASC, name ASC
");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM booking_settings");
$settingsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
$settings = [];
foreach ($settingsData as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Calculate max booking date (advance_booking_days)
$maxBookingDays = (int)($settings['advance_booking_days'] ?? 30);
$maxDate = date('Y-m-d', strtotime("+{$maxBookingDays} days"));
$minDate = date('Y-m-d', strtotime('+1 day')); // Tomorrow

// Include header
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php';
?>

<!-- SEO Meta Tags -->
<?= $seo->renderMetaTags() ?>

<!-- Structured Data -->
<?= $seo->renderOrganizationSchema() ?>
<?= $seo->renderLocalBusinessSchema() ?>

<style>
    .booking-container {
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        padding: 3rem;
        margin-top: 2rem;
        position: relative;
        z-index: 10;
    }
    .step-indicator {
        display: flex;
        justify-content: space-between;
        margin-bottom: 3rem;
        position: relative;
    }
    .step-indicator::before {
        content: '';
        position: absolute;
        top: 20px;
        left: 0;
        right: 0;
        height: 2px;
        background: #e2e8f0;
        z-index: -1;
    }
    .step {
        flex: 1;
        text-align: center;
        position: relative;
    }
    .step-circle {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #e2e8f0;
        color: #94a3b8;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        margin-bottom: 0.5rem;
        transition: all 0.3s;
    }
    .step.active .step-circle {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }
    .step.completed .step-circle {
        background: #10b981;
        color: white;
    }
    .step-label {
        display: block;
        font-size: 0.875rem;
        color: #64748b;
    }
    .step.active .step-label {
        color: #667eea;
        font-weight: 600;
    }
    .booking-step {
        display: none;
    }
    .booking-step.active {
        display: block;
        animation: fadeIn 0.3s;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .service-card {
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 1rem;
        cursor: pointer;
        transition: all 0.3s;
    }
    .service-card:hover {
        border-color: #667eea;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
    }
    .service-card.selected {
        border-color: #667eea;
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
    }
    .service-card .service-icon {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        margin-bottom: 1rem;
    }
    .time-slot {
        display: inline-block;
        padding: 0.75rem 1.5rem;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        margin: 0.5rem;
        cursor: pointer;
        transition: all 0.3s;
    }
    .time-slot:hover {
        border-color: #667eea;
        background: #f0f4ff;
    }
    .time-slot.selected {
        border-color: #667eea;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }
    .time-slot.unavailable {
        opacity: 0.4;
        cursor: not-allowed;
    }
    .btn-next, .btn-back {
        min-width: 120px;
    }

    /* Dark Mode Styles */
    :root.dark .booking-container {
        background: #1e293b;
        border: 1px solid #334155;
    }

    :root.dark .booking-container h3,
    :root.dark .booking-container h4,
    :root.dark .booking-container h5 {
        color: #f1f5f9;
    }

    :root.dark .step-indicator::before {
        background: #334155;
    }

    :root.dark .step-circle {
        background: #334155;
        color: #94a3b8;
    }

    :root.dark .step.active .step-circle {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }

    :root.dark .step.completed .step-circle {
        background: #10b981;
        color: white;
    }

    :root.dark .step-label {
        color: #94a3b8;
    }

    :root.dark .step.active .step-label {
        color: #a78bfa;
    }

    :root.dark .service-card {
        background: #0f172a;
        border-color: #334155;
    }

    :root.dark .service-card:hover {
        border-color: #667eea;
        background: #1e293b;
    }

    :root.dark .service-card.selected {
        border-color: #667eea;
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.2) 0%, rgba(118, 75, 162, 0.2) 100%);
    }

    :root.dark .service-card h4 {
        color: #f1f5f9;
    }

    :root.dark .service-card .text-muted {
        color: #94a3b8 !important;
    }

    :root.dark .service-card p {
        color: #cbd5e1;
    }

    :root.dark .form-control {
        background: #0f172a;
        border-color: #334155;
        color: #e2e8f0;
    }

    :root.dark .form-control:focus {
        background: #1e293b;
        border-color: #667eea;
        color: #e2e8f0;
    }

    :root.dark .form-label {
        color: #cbd5e1;
    }

    :root.dark .time-slot {
        background: #0f172a;
        border-color: #334155;
        color: #e2e8f0;
    }

    :root.dark .time-slot:hover {
        border-color: #667eea;
        background: #1e293b;
    }

    :root.dark .time-slot.selected {
        border-color: #667eea;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }

    :root.dark .card {
        background: #0f172a;
        border-color: #334155;
    }

    :root.dark .card-body {
        color: #e2e8f0;
    }

    :root.dark .card-title {
        color: #f1f5f9;
    }

    :root.dark .text-muted {
        color: #94a3b8 !important;
    }

    :root.dark .alert-info {
        background: #0c4a6e;
        border-color: #075985;
        color: #bae6fd;
    }

    :root.dark .btn-outline-secondary {
        color: #cbd5e1;
        border-color: #334155;
    }

    :root.dark .btn-outline-secondary:hover {
        background: #334155;
        border-color: #475569;
        color: #f1f5f9;
    }

    :root.dark .spinner-border {
        color: #667eea !important;
    }

    :root.dark #timeSlotsContainer p {
        color: #94a3b8;
    }

    :root.dark .alert-warning {
        background: #78350f;
        border-color: #92400e;
        color: #fde68a;
    }

    :root.dark .alert-danger {
        background: #7f1d1d;
        border-color: #991b1b;
        color: #fca5a5;
    }
</style>

<!-- Hero Section -->
<section class="hero">
    <div class="hero-gradient"></div>
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8">
                <h1 class="hero-title">
                    <i class="bi bi-calendar-check me-3"></i>
                    Book Your Consultation
                </h1>
                <p class="hero-subtitle">
                    Schedule a free consultation with our IT experts. No commitment required.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Booking Content -->
<div class="container py-5">
    <div class="booking-container">

        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step active" data-step="1">
                <span class="step-circle">1</span>
                <span class="step-label">Choose Service</span>
            </div>
            <div class="step" data-step="2">
                <span class="step-circle">2</span>
                <span class="step-label">Select Date & Time</span>
            </div>
            <div class="step" data-step="3">
                <span class="step-circle">3</span>
                <span class="step-label">Your Information</span>
            </div>
            <div class="step" data-step="4">
                <span class="step-circle">4</span>
                <span class="step-label">Confirm</span>
            </div>
        </div>

        <form id="bookingForm" action="/includes/process-booking.php" method="POST">

            <!-- Step 1: Choose Service -->
            <div class="booking-step active" id="step1">
                <h3 class="mb-4">Choose Your Service</h3>

                <?php foreach ($services as $service): ?>
                    <div class="service-card" data-service-id="<?= $service['id'] ?>" data-duration="<?= $service['duration_minutes'] ?>">
                        <div class="service-icon" style="background-color: <?= htmlspecialchars($service['color']) ?>20; color: <?= htmlspecialchars($service['color']) ?>;">
                            <i class="bi bi-calendar-event"></i>
                        </div>
                        <h4><?= htmlspecialchars($service['name']) ?></h4>
                        <p class="text-muted mb-2"><?= htmlspecialchars($service['description']) ?></p>
                        <p class="mb-0">
                            <i class="bi bi-clock me-1"></i>
                            <strong><?= $service['duration_minutes'] ?> minutes</strong>
                        </p>
                    </div>
                <?php endforeach; ?>

                <input type="hidden" name="service_id" id="selectedServiceId" required>
                <input type="hidden" id="selectedDuration">

                <div class="text-end mt-4">
                    <button type="button" class="btn c-btn-primary btn-next" onclick="nextStep(2)" disabled>
                        Next: Choose Date & Time <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>

            <!-- Step 2: Select Date & Time -->
            <div class="booking-step" id="step2">
                <h3 class="mb-4">Select Date & Time</h3>

                <div class="row">
                    <div class="col-md-6">
                        <h5>Choose a Date</h5>
                        <input type="date"
                               class="form-control form-control-lg"
                               id="appointmentDate"
                               name="appointment_date"
                               min="<?= $minDate ?>"
                               max="<?= $maxDate ?>"
                               required>
                    </div>
                    <div class="col-md-6">
                        <h5>Available Times</h5>
                        <div id="timeSlotsContainer">
                            <p class="text-muted">Please select a date to see available times</p>
                        </div>
                        <input type="hidden" name="start_time" id="selectedTime" required>
                    </div>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-outline-secondary btn-back" onclick="prevStep(1)">
                        <i class="bi bi-arrow-left me-2"></i> Back
                    </button>
                    <button type="button" class="btn c-btn-primary btn-next" onclick="nextStep(3)" disabled id="step2Next">
                        Next: Your Information <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>

            <!-- Step 3: Your Information -->
            <div class="booking-step" id="step3">
                <h3 class="mb-4">Your Information</h3>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Full Name *</label>
                        <input type="text" class="form-control" name="customer_name" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email Address *</label>
                        <input type="email" class="form-control" name="customer_email" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" name="customer_phone">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Company Name</label>
                        <input type="text" class="form-control" name="customer_company">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Additional Notes (Optional)</label>
                        <textarea class="form-control" name="notes" rows="4" placeholder="Tell us what you'd like to discuss..."></textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-outline-secondary btn-back" onclick="prevStep(2)">
                        <i class="bi bi-arrow-left me-2"></i> Back
                    </button>
                    <button type="button" class="btn c-btn-primary btn-next" onclick="nextStep(4)">
                        Review Booking <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>

            <!-- Step 4: Confirm -->
            <div class="booking-step" id="step4">
                <h3 class="mb-4">Confirm Your Booking</h3>

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-3">Booking Summary</h5>

                        <div class="row mb-3">
                            <div class="col-sm-4 text-muted">Service:</div>
                            <div class="col-sm-8" id="confirmService"></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-4 text-muted">Date & Time:</div>
                            <div class="col-sm-8" id="confirmDateTime"></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-4 text-muted">Duration:</div>
                            <div class="col-sm-8" id="confirmDuration"></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-4 text-muted">Name:</div>
                            <div class="col-sm-8" id="confirmName"></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-4 text-muted">Email:</div>
                            <div class="col-sm-8" id="confirmEmail"></div>
                        </div>
                        <div class="row" id="confirmPhoneRow" style="display: none;">
                            <div class="col-sm-4 text-muted">Phone:</div>
                            <div class="col-sm-8" id="confirmPhone"></div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info mt-3">
                    <i class="bi bi-info-circle me-2"></i>
                    You'll receive a confirmation email with a calendar invite and meeting details.
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-outline-secondary btn-back" onclick="prevStep(3)">
                        <i class="bi bi-arrow-left me-2"></i> Back
                    </button>
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-check-circle me-2"></i> Confirm Booking
                    </button>
                </div>
            </div>

        </form>

    </div>
</div>

<script>
let currentStep = 1;
let selectedService = null;
let selectedDate = null;
let selectedTime = null;

// Service selection
document.querySelectorAll('.service-card').forEach(card => {
    card.addEventListener('click', function() {
        document.querySelectorAll('.service-card').forEach(c => c.classList.remove('selected'));
        this.classList.add('selected');

        const serviceId = this.dataset.serviceId;
        const duration = this.dataset.duration;

        document.getElementById('selectedServiceId').value = serviceId;
        document.getElementById('selectedDuration').value = duration;

        selectedService = {
            id: serviceId,
            name: this.querySelector('h4').textContent,
            duration: duration
        };

        document.querySelector('#step1 .btn-next').disabled = false;
    });
});

// Date selection
document.getElementById('appointmentDate').addEventListener('change', function() {
    selectedDate = this.value;
    loadAvailableTimeSlots(selectedDate);
});

// Load available time slots
function loadAvailableTimeSlots(date) {
    const serviceId = document.getElementById('selectedServiceId').value;
    const container = document.getElementById('timeSlotsContainer');

    container.innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">Loading available times...</p></div>';

    fetch(`/includes/get-available-slots.php?date=${date}&service_id=${serviceId}`)
        .then(response => response.json())
        .then(data => {
            if (data.slots && data.slots.length > 0) {
                container.innerHTML = '';
                data.slots.forEach(slot => {
                    const timeSlot = document.createElement('div');
                    timeSlot.className = 'time-slot';
                    timeSlot.textContent = slot.display;
                    timeSlot.dataset.time = slot.time;

                    timeSlot.addEventListener('click', function() {
                        document.querySelectorAll('.time-slot').forEach(t => t.classList.remove('selected'));
                        this.classList.add('selected');

                        selectedTime = slot.time;
                        document.getElementById('selectedTime').value = slot.time;
                        document.getElementById('step2Next').disabled = false;
                    });

                    container.appendChild(timeSlot);
                });
            } else {
                container.innerHTML = '<div class="alert alert-warning">No available time slots for this date. Please choose another date.</div>';
            }
        })
        .catch(error => {
            container.innerHTML = '<div class="alert alert-danger">Error loading time slots. Please try again.</div>';
            console.error('Error:', error);
        });
}

// Navigation
function nextStep(step) {
    // Validate current step
    if (step === 2 && !selectedService) {
        alert('Please select a service');
        return;
    }
    if (step === 3 && (!selectedDate || !selectedTime)) {
        alert('Please select a date and time');
        return;
    }
    if (step === 4) {
        // Validate form inputs
        const form = document.getElementById('bookingForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        // Update confirmation display
        updateConfirmation();
    }

    // Update UI
    document.querySelectorAll('.booking-step').forEach(s => s.classList.remove('active'));
    document.getElementById('step' + step).classList.add('active');

    document.querySelectorAll('.step').forEach(s => {
        s.classList.remove('active', 'completed');
        const stepNum = parseInt(s.dataset.step);
        if (stepNum === step) {
            s.classList.add('active');
        } else if (stepNum < step) {
            s.classList.add('completed');
        }
    });

    currentStep = step;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function prevStep(step) {
    nextStep(step);
}

function updateConfirmation() {
    document.getElementById('confirmService').textContent = selectedService.name;
    document.getElementById('confirmDateTime').textContent = formatDate(selectedDate) + ' at ' + selectedTime;
    document.getElementById('confirmDuration').textContent = selectedService.duration + ' minutes';
    document.getElementById('confirmName').textContent = document.querySelector('[name="customer_name"]').value;
    document.getElementById('confirmEmail').textContent = document.querySelector('[name="customer_email"]').value;

    const phone = document.querySelector('[name="customer_phone"]').value;
    if (phone) {
        document.getElementById('confirmPhone').textContent = phone;
        document.getElementById('confirmPhoneRow').style.display = '';
    }
}

function formatDate(dateStr) {
    const date = new Date(dateStr + 'T00:00:00');
    return date.toLocaleDateString('en-GB', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
}

// ============================================================================
// HEALTH CHECK INTEGRATION
// ============================================================================
// Automatically include health check data if user came from the health check tool
(function() {
    console.log('[BOOKING] Checking for health check data in localStorage...');

    // Check if health check data exists in localStorage
    const healthCheckData = localStorage.getItem('healthCheckData');

    if (healthCheckData) {
        console.log('[BOOKING] Health check data found:', healthCheckData);

        // Create hidden field if it doesn't exist
        let hiddenField = document.querySelector('[name="health_check_data"]');

        if (!hiddenField) {
            hiddenField = document.createElement('input');
            hiddenField.type = 'hidden';
            hiddenField.name = 'health_check_data';
            document.getElementById('bookingForm').appendChild(hiddenField);
            console.log('[BOOKING] Created hidden field for health check data');
        }

        // Set the value
        hiddenField.value = healthCheckData;

        console.log('[BOOKING] Health check data added to form:', hiddenField.value.substring(0, 100) + '...');
        console.log('[BOOKING] Hidden field name:', hiddenField.name);

        // Show a banner if they came from health check
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('source') === 'health-check') {
            try {
                const data = JSON.parse(healthCheckData);
                const banner = document.createElement('div');
                banner.style.cssText = 'background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1rem; text-align: center; border-radius: 12px; margin-bottom: 2rem; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);';
                banner.innerHTML = `
                    <strong>Great! We've captured your IT Health Check results (${data.overall}% overall score)</strong><br>
                    <span style="font-size: 0.9rem; opacity: 0.9;">Our team will review your results before the consultation</span>
                `;

                const container = document.querySelector('.booking-container');
                if (container && container.firstChild) {
                    container.insertBefore(banner, container.firstChild);
                }
            } catch (e) {
                console.error('Error parsing health check data:', e);
            }
        }
    }
})();
</script>

<!-- Analytics Tracking -->
<script src="/analytics/track.js" async defer></script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
