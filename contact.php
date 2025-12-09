<?php
// Public Contact Form - No login required
// Only allows submission to "Onboarding" support group

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$error_message = '';
$success_message = '';

// Check if user is logged in - redirect to member area if they are
$user = $_SESSION['user'] ?? null;
if ($user) {
    header('Location: /members/raise-ticket.php');
    exit;
}

// Get the Onboarding support group ID
try {
    $stmt = $pdo->query("
        SELECT id, name
        FROM support_ticket_groups
        WHERE name LIKE '%onboard%' AND active = 1
        LIMIT 1
    ");
    $onboarding_group = $stmt->fetch();

    if (!$onboarding_group) {
        // If no "Onboarding" group found, get first active group
        $stmt = $pdo->query("SELECT id, name FROM support_ticket_groups WHERE active = 1 ORDER BY id ASC LIMIT 1");
        $onboarding_group = $stmt->fetch();
    }
} catch (PDOException $e) {
    error_log("Error fetching onboarding group: " . $e->getMessage());
    $error_message = "Sorry, the contact form is temporarily unavailable. Please try again later.";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'], $_POST['email'], $_POST['subject'], $_POST['message'])) {
    error_log("=== PUBLIC CONTACT FORM SUBMISSION ===");

    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $company = trim($_POST['company'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);

    // Validate required fields
    if (empty($name) || empty($email) || empty($subject) || empty($message)) {
        $error_message = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } elseif ($onboarding_group) {
        try {
            // Create a guest user entry or find existing
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $existing_user = $stmt->fetch();

            if ($existing_user) {
                $user_id = $existing_user['id'];
            } else {
                // Create temporary guest user
                $stmt = $pdo->prepare("
                    INSERT INTO users (name, email, role, status, created_at)
                    VALUES (?, ?, 'guest', 'inactive', NOW())
                ");
                $stmt->execute([$name, $email]);
                $user_id = $pdo->lastInsertId();
            }

            // Build details with contact info
            $details = "Contact Information:\n";
            $details .= "Name: $name\n";
            $details .= "Email: $email\n";
            if ($company) $details .= "Company: $company\n";
            if ($phone) $details .= "Phone: $phone\n";
            $details .= "\n---\n\n";
            $details .= $message;

            // Insert support ticket (mark as guest ticket)
            $stmt = $pdo->prepare("
                INSERT INTO support_tickets
                    (user_id, group_id, subject, details, status, priority, visibility_scope, is_guest_ticket, guest_email, created_at)
                VALUES
                    (?, ?, ?, ?, 'Open', 'Normal', 'public', 1, ?, NOW())
            ");

            $result = $stmt->execute([
                $user_id,
                $onboarding_group['id'],
                $subject,
                $details,
                $email
            ]);

            if ($result) {
                $ticket_id = $pdo->lastInsertId();
                error_log("Contact form ticket created: #" . $ticket_id);

                // Generate access token for guest ticket
                $accessToken = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));

                $stmt = $pdo->prepare("
                    INSERT INTO support_guest_tickets
                    (ticket_id, guest_email, guest_name, access_token, token_expires_at)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$ticket_id, $email, $name, $accessToken, $expiresAt]);

                // Handle file attachments if any
                if (!empty($_FILES['attachment']['name'][0])) {
                    $uploadDir = __DIR__ . '/members/attachments/' . $ticket_id . '/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }

                    foreach ($_FILES['attachment']['tmp_name'] as $i => $tmpName) {
                        if (is_uploaded_file($tmpName)) {
                            $originalName = basename($_FILES['attachment']['name'][$i]);
                            $fileSize = $_FILES['attachment']['size'][$i];
                            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                            $newName = uniqid() . '.' . $extension;
                            $destination = $uploadDir . $newName;

                            // Check file size (5MB limit)
                            if ($fileSize > 5 * 1024 * 1024) {
                                continue;
                            }

                            // Check file extension
                            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt', 'csv', 'xls', 'xlsx', 'zip'];
                            if (!in_array(strtolower($extension), $allowedExtensions)) {
                                continue;
                            }

                            if (move_uploaded_file($tmpName, $destination)) {
                                // Store attachment in database (use initial attachments table)
                                $stmt = $pdo->prepare("
                                    INSERT INTO support_ticket_attachments_initial
                                        (ticket_id, file_name, original_name, uploaded_at)
                                    VALUES (?, ?, ?, NOW())
                                ");
                                $stmt->execute([$ticket_id, $newName, $originalName]);
                            }
                        }
                    }
                }

                // Send email and Discord notifications
                try {
                    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/TicketNotifications.php';
                    $notifications = new TicketNotifications($pdo);

                    // Send notifications (this will handle Discord webhook and email to staff)
                    $notifications->notifyTicketRaised($ticket_id);

                    // Send guest-specific email with access link
                    $viewUrl = "https://" . $_SERVER['HTTP_HOST'] . "/public/view-ticket.php?token=" . $accessToken;
                    $guestEmailSubject = "Ticket #{$ticket_id} Created - " . htmlspecialchars($subject);
                    $guestEmailBody = "
                        <html>
                        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                                <h2 style='color: #667eea;'>Thank you for contacting us!</h2>
                                <p>Hi {$name},</p>
                                <p>We've received your message and created support ticket <strong>#{$ticket_id}</strong>.</p>

                                <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                                    <strong>Subject:</strong> " . htmlspecialchars($subject) . "<br>
                                    <strong>Status:</strong> Open<br>
                                    <strong>Priority:</strong> Normal
                                </div>

                                <p>You can view and reply to your ticket using the link below:</p>
                                <p style='text-align: center; margin: 30px 0;'>
                                    <a href='{$viewUrl}' style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; display: inline-block;'>
                                        View Your Ticket
                                    </a>
                                </p>

                                <p style='font-size: 0.9em; color: #6c757d;'>
                                    <strong>Important:</strong> Keep this email safe. The link above is your unique access link to view and manage this ticket.
                                    This link will remain valid for 90 days.
                                </p>

                                <p>Our support team will review your request and get back to you as soon as possible.</p>

                                <hr style='border: none; border-top: 1px solid #e9ecef; margin: 30px 0;'>
                                <p style='font-size: 0.85em; color: #6c757d;'>
                                    CaminhoIT Support Team<br>
                                    <a href='mailto:support@caminhoit.com'>support@caminhoit.com</a>
                                </p>
                            </div>
                        </body>
                        </html>
                    ";

                    // Use the notification system to send the guest email
                    $notifications->sendGuestEmail($email, $guestEmailSubject, $guestEmailBody);

                    error_log("Notifications sent for guest ticket #{$ticket_id}");
                } catch (Exception $e) {
                    error_log("Failed to send notifications for guest ticket: " . $e->getMessage());
                    // Don't fail the ticket creation if notifications fail
                }

                // Redirect to public ticket view
                header('Location: /public/view-ticket.php?token=' . $accessToken);
                exit;
            } else {
                $error_message = "Sorry, there was an error submitting your message. Please try again.";
            }

        } catch (PDOException $e) {
            error_log("Contact form error: " . $e->getMessage());
            $error_message = "Sorry, there was an error submitting your message. Please try again later.";
        }
    }
}

$page_title = "Contact Us | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php'; ?>

<!-- Hero Section -->
<header class="hero">
    <div class="container">
        <div class="hero-content text-center">
            <h1 class="display-3 fw-bold text-white mb-3">
                <i class="bi bi-envelope-heart me-3"></i>
                Get In Touch
            </h1>
            <p class="lead text-white opacity-90">
                We'd love to hear from you. Send us a message and we'll respond as soon as possible.
            </p>
        </div>
    </div>
</header>

<section class="section-soft fade-in">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10 col-xl-9">
                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?= htmlspecialchars($success_message) ?>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>

                <!-- Contact Info Cards -->
                <div class="row g-4 mb-5">
                    <div class="col-md-4">
                        <div class="pill-card text-center h-100">
                            <i class="bi bi-envelope-at fs-1 text-primary mb-3"></i>
                            <h5>Email Us</h5>
                            <p class="mb-0"><a href="mailto:support@caminhoit.com">support@caminhoit.com</a></p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="pill-card text-center h-100">
                            <i class="bi bi-telephone fs-1 text-primary mb-3"></i>
                            <h5>Call Us</h5>
                            <p class="mb-0"><a href="tel:+351963452653">+351 963 452 653</a></p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="pill-card text-center h-100">
                            <i class="bi bi-whatsapp fs-1 text-success mb-3"></i>
                            <h5>WhatsApp</h5>
                            <p class="mb-0"><a href="https://wa.me/351963452653" target="_blank">Chat with us</a></p>
                        </div>
                    </div>
                </div>

                <!-- Contact Form -->
                <div class="feature-card">
                    <form method="POST" enctype="multipart/form-data" id="contactForm">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" required
                                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" required
                                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="company" class="form-label">Company Name</label>
                                <input type="text" class="form-control" id="company" name="company"
                                       value="<?= htmlspecialchars($_POST['company'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="phone" name="phone"
                                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <label for="subject" class="form-label">Subject <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="subject" name="subject" required
                                       placeholder="How can we help you?"
                                       value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <label for="message" class="form-label">Message <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="message" name="message" rows="6" required
                                          placeholder="Tell us more about your inquiry..."><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Attachments (Optional)</label>
                                <div class="border border-2 border-dashed rounded p-4 text-center" style="background: #f8f9fa;">
                                    <i class="bi bi-cloud-upload fs-1 text-primary"></i>
                                    <p class="mt-2 mb-2">Drag and drop files here or click to browse</p>
                                    <input type="file" class="form-control" name="attachment[]" multiple
                                           accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.csv,.xls,.xlsx,.zip">
                                    <small class="text-muted">Max file size: 5MB. Allowed: Images, PDFs, Documents, Spreadsheets</small>
                                </div>
                            </div>
                            <div class="col-12 text-center mt-4">
                                <button type="submit" class="btn c-btn-primary btn-lg">
                                    <i class="bi bi-send me-2"></i>Send Message
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Already a member? -->
                    <div class="text-center mt-4">
                        <p class="text-muted">
                            Already a member? <a href="/members/raise-ticket.php">Access the member portal</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
// Fade-in animation
document.addEventListener('DOMContentLoaded', () => {
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) entry.target.classList.add('visible');
        });
    }, { threshold: 0.1 });
    document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));
});

// Clear form on successful submission
<?php if ($success_message): ?>
document.getElementById('contactForm').reset();
<?php endif; ?>
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
