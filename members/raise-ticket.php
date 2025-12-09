<?php
// Error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /login.php');
    exit;
}

$user_id = $user['id'];
$error_message = '';
$success_message = '';

// ENHANCED: Check for POST request and required fields, now with file upload support
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['group_id'], $_POST['subject'], $_POST['details'])) {
    error_log("=== FORM PROCESSING START ===");
    
    $group_id = $_POST['group_id'] ?? null;
    $subject = $_POST['subject'] ?? '';
    $details = $_POST['details'] ?? '';

    error_log("Extracted data:");
    error_log("- Group ID: " . ($group_id ?? 'NULL'));
    error_log("- Subject: '" . $subject . "'");
    error_log("- Details: '" . substr($details, 0, 100) . "...'");
    error_log("- Files uploaded: " . (isset($_FILES['attachment']) ? count($_FILES['attachment']['name']) : 0));

    if ($group_id && $subject && $details) {
        error_log("Validation PASSED - attempting database insert");
        
        try {
            // Start transaction for ticket creation and file uploads
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                INSERT INTO support_tickets 
                    (user_id, group_id, subject, details, status, priority, visibility_scope, created_at)
                VALUES 
                    (?, ?, ?, ?, 'Open', 'Normal', 'private', NOW())
            ");
            
            error_log("Prepared statement created");
            
            $params = [$user_id, $group_id, $subject, $details];
            error_log("Execute parameters: " . print_r($params, true));
            
            $result = $stmt->execute($params);
            error_log("Execute result: " . ($result ? 'SUCCESS' : 'FAILED'));
            
            if ($result) {
                $ticket_id = $pdo->lastInsertId();
                error_log("Last insert ID: " . $ticket_id);
                
                if ($ticket_id) {
                    error_log("SUCCESS: Ticket created with ID " . $ticket_id);
                    
                    // Handle file uploads for initial ticket
                    if (!empty($_FILES['attachment']['name'][0])) {
                        error_log("Processing file uploads...");
                        
                        $uploadDir = __DIR__ . '/attachments/' . $ticket_id . '/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                            error_log("Created upload directory: " . $uploadDir);
                        }

                        $uploadedFiles = 0;
                        foreach ($_FILES['attachment']['tmp_name'] as $i => $tmpName) {
                            if (is_uploaded_file($tmpName)) {
                                $originalName = basename($_FILES['attachment']['name'][$i]);
                                $fileSize = $_FILES['attachment']['size'][$i];
                                $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                                $newName = uniqid() . '.' . $extension;
                                $destination = $uploadDir . $newName;

                                error_log("Processing file: " . $originalName . " (Size: " . $fileSize . " bytes)");
                                
                                // Check file size (5MB limit)
                                if ($fileSize > 5 * 1024 * 1024) {
                                    error_log("File too large: " . $originalName);
                                    continue;
                                }
                                
                                // Check file extension
                                $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt', 'csv', 'xls', 'xlsx', 'zip', 'rar', '7z'];
                                if (!in_array(strtolower($extension), $allowedExtensions)) {
                                    error_log("File type not allowed: " . $originalName);
                                    continue;
                                }

                                if (move_uploaded_file($tmpName, $destination)) {
                                    error_log("File moved successfully: " . $newName);
                                    
                                    // Save to initial attachments table
                                    $stmt = $pdo->prepare("INSERT INTO support_ticket_attachments_initial (ticket_id, file_name, original_name, uploaded_at) VALUES (?, ?, ?, NOW())");
                                    if ($stmt->execute([$ticket_id, $newName, $originalName])) {
                                        $uploadedFiles++;
                                        error_log("File saved to database: " . $originalName);
                                    } else {
                                        error_log("Failed to save file to database: " . $originalName);
                                    }
                                } else {
                                    error_log("Failed to move file: " . $originalName);
                                }
                            }
                        }
                        
                        error_log("Total files uploaded: " . $uploadedFiles);
                    }
                    
                    // Commit transaction
                    $pdo->commit();
                    error_log("Transaction committed successfully");

                    // Send email and Discord notifications
                    try {
                        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/TicketNotifications.php';
                        $notifications = new TicketNotifications($pdo);
                        $notifications->notifyTicketRaised($ticket_id);
                        error_log("Notifications sent for ticket #{$ticket_id}");
                    } catch (Exception $e) {
                        error_log("Failed to send notifications: " . $e->getMessage());
                        // Don't fail the ticket creation if notifications fail
                    }

                    error_log("Redirecting to: /members/view-ticket.php?id=" . $ticket_id);

                    // Success! Redirect to the ticket view
                    header('Location: /members/view-ticket.php?id=' . $ticket_id);
                    exit;
                } else {
                    $pdo->rollBack();
                    error_log("ERROR: No ticket ID returned");
                    $error_message = "Error creating ticket. Please try again.";
                }
            } else {
                $pdo->rollBack();
                error_log("ERROR: Execute returned false");
                $errorInfo = $stmt->errorInfo();
                error_log("Error info: " . print_r($errorInfo, true));
                $error_message = "Database error occurred.";
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("PDO Exception: " . $e->getMessage());
            $error_message = "Database error: " . $e->getMessage();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("General Exception: " . $e->getMessage());
            $error_message = "System error: " . $e->getMessage();
        }
    } else {
        error_log("Validation FAILED");
        $error_message = "All fields are required. Please fill in all the information.";
    }
    
    error_log("=== FORM PROCESSING END ===");
}

// Fetch user profile and groups
try {
    $stmt = $pdo->prepare("
        SELECT u.*, c.name AS company_name
        FROM users u
        LEFT JOIN companies c ON u.company_id = c.id
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $profile = $stmt->fetch();

    $stmt = $pdo->query("SELECT * FROM support_ticket_groups WHERE active = 1 ORDER BY id DESC");
    $groups = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Database error fetching data: " . $e->getMessage());
    $error_message = "Sorry, there was an error loading the page. Please try again.";
    $groups = [];
}

$page_title = "Submit Support Request | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<style>
        /* Ticket Wizard Specific Styles */
        .ticket-wizard {
            background: white;
            border-radius: var(--border-radius-large);
            padding: 2rem;
            box-shadow: var(--card-shadow-hover);
            position: relative;
            overflow: hidden;
            border: 1px solid #e9ecef;
            min-height: 400px;
        }
        
        .wizard-step {
            display: none;
            animation: fadeIn 0.5s ease-in-out;
        }
        
        .wizard-step.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .category-button {
            display: block;
            width: 100%;
            padding: 1.5rem;
            margin-bottom: 1rem;
            background: white;
            border: 2px solid #e9ecef;
            border-radius: var(--border-radius);
            text-align: left;
            transition: var(--transition);
            color: #495057;
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }
        
        .category-button:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow);
            border-color: #667eea;
            color: #667eea;
        }
        
        .category-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(102, 126, 234, 0.1), transparent);
            transition: left 0.5s ease;
        }
        
        .category-button:hover::before {
            left: 100%;
        }
        
        .category-icon {
            font-size: 2.5rem;
            margin-right: 1.25rem;
            color: #667eea;
        }
        
        .category-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .category-description {
            font-size: 1rem;
            color: #6c757d;
            margin: 0;
        }
        
        .form-floating {
            position: relative;
            margin-bottom: 2rem;
        }
        
        .form-floating .form-control {
            background: rgba(255,255,255,0.95);
            border: 2px solid #e9ecef;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            font-size: 1rem;
            transition: var(--transition);
        }
        
        .form-floating .form-control:focus {
            background: white;
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            transform: translateY(-2px);
        }
        
        .form-floating label {
            color: #6c757d;
            font-weight: 500;
            transition: var(--transition);
        }
        
        .form-floating .form-control:focus ~ label,
        .form-floating .form-control:not(:placeholder-shown) ~ label {
            color: #667eea;
        }
        
        .character-count {
            position: absolute;
            right: 15px;
            bottom: 10px;
            font-size: 0.8rem;
            color: #6c757d;
            background: rgba(255,255,255,0.9);
            padding: 4px 8px;
            border-radius: 8px;
            transition: var(--transition);
        }
        
        .character-count.warning {
            color: #dc3545;
            background: rgba(220, 53, 69, 0.1);
        }
        
        .progress-bar-container {
            background: #e9ecef;
            height: 6px;
            border-radius: 10px;
            margin-bottom: 2rem;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            background: var(--primary-gradient);
            border-radius: 10px;
            transition: width 0.5s ease;
            width: 33.33%;
        }
        
        .step-indicator {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .step-number {
            display: inline-block;
            width: 50px;
            height: 50px;
            background: var(--primary-gradient);
            color: white;
            border-radius: 50%;
            line-height: 50px;
            font-weight: bold;
            font-size: 1.25rem;
            margin-bottom: 0.75rem;
        }
        
        .step-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        .step-subtitle {
            color: #6c757d;
            font-size: 1rem;
        }
        
        .attachment-area {
            border: 3px dashed #dee2e6;
            border-radius: var(--border-radius);
            padding: 3rem;
            text-align: center;
            margin-bottom: 2rem;
            transition: var(--transition);
            background: #f8f9fa;
        }
        
        .attachment-area:hover {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.05);
        }

        .upload-icon {
            font-size: 4rem;
            color: #667eea;
            margin-bottom: 1.5rem;
        }
        
        .attachment-area.dragover {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.1);
            transform: scale(1.02);
        }
        
        .file-input {
            display: none;
        }
        
        .file-list {
            margin-top: 1.5rem;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            background: rgba(102, 126, 234, 0.1);
            border-radius: var(--border-radius);
            margin-bottom: 0.75rem;
            border: 1px solid rgba(102, 126, 234, 0.2);
        }
        
        .file-item i {
            margin-right: 0.75rem;
            color: #667eea;
            font-size: 1.25rem;
        }
        
        .file-item-info {
            flex: 1;
        }
        
        .file-item-name {
            font-weight: 500;
            color: #2c3e50;
        }
        
        .file-item-size {
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        .file-remove {
            margin-left: auto;
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }
        
        .file-remove:hover {
            background: rgba(220, 53, 69, 0.1);
        }
        
        .loading-spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid #ffffff;
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .success-message {
            background: var(--success-gradient);
            color: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
            display: none;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from { transform: translateX(-100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .file-upload-info {
            background: #e7f3ff;
            border: 1px solid #b8daff;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-top: 1.5rem;
            font-size: 0.9rem;
            color: #004085;
        }
        
        .alert {
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: none;
            font-weight: 500;
        }
        
        .alert-danger {
            background: var(--danger-gradient);
            color: white;
        }
        
        .alert-success {
            background: var(--success-gradient);
            color: white;
        }

        /* Dark Mode Styles */
        :root.dark .ticket-wizard {
            background: #1e293b;
            border-color: #334155;
        }

        :root.dark .step-title {
            color: #f1f5f9;
        }

        :root.dark .step-subtitle {
            color: #94a3b8;
        }

        :root.dark .category-button {
            background: #0f172a;
            border-color: #334155;
            color: #cbd5e1;
        }

        :root.dark .category-button:hover {
            background: #1e293b;
            border-color: #a78bfa;
            color: #a78bfa;
        }

        :root.dark .category-title {
            color: #f1f5f9;
        }

        :root.dark .category-description {
            color: #94a3b8;
        }

        :root.dark .category-icon {
            color: #a78bfa;
        }

        :root.dark .form-floating .form-control {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }

        :root.dark .form-floating .form-control:focus {
            background: #1e293b;
            border-color: #a78bfa;
        }

        :root.dark .form-floating label {
            color: #94a3b8;
        }

        :root.dark .form-floating .form-control:focus ~ label,
        :root.dark .form-floating .form-control:not(:placeholder-shown) ~ label {
            color: #a78bfa;
        }

        :root.dark .character-count {
            background: rgba(15, 23, 42, 0.9);
            color: #94a3b8;
        }

        :root.dark .character-count.warning {
            background: rgba(220, 53, 69, 0.2);
            color: #f87171;
        }

        :root.dark .attachment-area {
            background: #0f172a;
            border-color: #334155;
        }

        :root.dark .attachment-area:hover {
            border-color: #a78bfa;
            background: rgba(167, 139, 250, 0.05);
        }

        :root.dark .attachment-area h4 {
            color: #f1f5f9;
        }

        :root.dark .attachment-area .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark .upload-icon {
            color: #a78bfa;
        }

        :root.dark .file-item {
            background: rgba(167, 139, 250, 0.1);
            border-color: rgba(167, 139, 250, 0.2);
        }

        :root.dark .file-item-name {
            color: #e2e8f0;
        }

        :root.dark .file-item-size {
            color: #94a3b8;
        }

        :root.dark .file-item i {
            color: #a78bfa;
        }

        :root.dark .file-upload-info {
            background: #0c4a6e;
            border-color: #0ea5e9;
            color: #7dd3fc;
        }

        :root.dark .btn-link {
            color: #a78bfa;
        }

        :root.dark .btn-link:hover {
            color: #c4b5fd;
        }

        :root.dark .progress-bar-container {
            background: #334155;
        }

        :root.dark .breadcrumb-enhanced {
            background: transparent;
        }

        :root.dark .breadcrumb-item a {
            color: #a78bfa;
        }

        :root.dark .breadcrumb-item.active {
            color: #cbd5e1;
        }

        :root.dark .alert-warning {
            background: #78350f;
            color: #fde68a;
            border: 1px solid #f59e0b;
        }

    </style>

<!-- Hero Section - Using Theme -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="raise-ticket-hero-content">
            <h1 class="raise-ticket-hero-title">
                <i class="bi bi-headset me-2"></i>
                Need Help? We're Here!
            </h1>
            <p class="raise-ticket-hero-subtitle">
                Don't worry, we've got your back! Tell us what's up and we'll make it right. Our expert support team is ready to assist you.
            </p>
            <div class="raise-ticket-hero-actions">
                <a href="#ticket-form" class="btn c-btn-primary">
                    <i class="bi bi-arrow-down me-1"></i>
                    Submit Request
                </a>
                <a href="/members/my-ticket.php" class="btn c-btn-ghost">
                    <i class="bi bi-list-ul me-1"></i>
                    My Tickets
                </a>
            </div>
        </div>
    </div>
</header>

<div class="container py-5 content-overlap" id="ticket-form">
    
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="breadcrumb-enhanced fade-in">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/members/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="/members/my-ticket.php">Support</a></li>
            <li class="breadcrumb-item active" aria-current="page">Submit Request</li>
        </ol>
    </nav>

    <div class="row justify-content-center">
        <div class="col-lg-10 col-xl-8">
            <?php if ($error_message): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success_message): ?>
                <div class="alert alert-success" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>
            
            <div class="success-message" id="successMessage">
                <i class="bi bi-check-circle-fill me-2"></i>
                Great! Your ticket is being created...
            </div>
            
            <div class="ticket-wizard fade-in">
                <div class="progress-bar-container">
                    <div class="progress-bar" id="progressBar"></div>
                </div>
                
                <form method="post" id="ticketForm" enctype="multipart/form-data">
                    <input type="hidden" name="group_id" id="selectedGroupId">
                    
                    <!-- Step 1: Category Selection -->
                    <div class="wizard-step active" id="step1">
                        <div class="step-indicator">
                            <div class="step-number">1</div>
                            <div class="step-title">What can we help you with?</div>
                            <div class="step-subtitle">Choose the category that best describes your issue</div>
                        </div>
                        
                        <div class="categories-container">
                            <?php if (empty($groups)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    No support categories are currently available. Please try again later.
                                </div>
                            <?php else: ?>
                                <?php foreach ($groups as $group): ?>
                                    <button type="button" class="category-button" data-group-id="<?= $group['id']; ?>" data-group-name="<?= htmlspecialchars($group['name']); ?>">
                                        <div class="d-flex align-items-center">
                                            <div class="category-icon">
                                                <?php 
                                                // Add icons based on category name
                                                $iconMap = [
                                                    'General Support' => 'bi-question-circle-fill',
                                                    'Technical Support' => 'bi-gear-fill',
                                                    'Billing' => 'bi-credit-card-fill',
                                                    'Account' => 'bi-person-fill',
                                                    'Bug Report' => 'bi-bug-fill',
                                                    'Feature Request' => 'bi-lightbulb-fill'
                                                ];
                                                $icon = $iconMap[$group['name']] ?? 'bi-chat-dots-fill';
                                                ?>
                                                <i class="bi <?= $icon; ?>"></i>
                                            </div>
                                            <div>
                                                <div class="category-title"><?= htmlspecialchars($group['name']); ?></div>
                                                <div class="category-description">
                                                    <?php
                                                    // Add descriptions based on category
                                                    $descriptions = [
                                                        'General Support' => 'General questions and support requests',
                                                        'Technical Support' => 'Technical issues and troubleshooting',
                                                        'Billing' => 'Billing questions and payment issues',
                                                        'Account' => 'Account management and access issues',
                                                        'Bug Report' => 'Report bugs and software issues',
                                                        'Feature Request' => 'Suggest new features and improvements'
                                                    ];
                                                    echo $descriptions[$group['name']] ?? 'Get help with this category';
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Step 2: Subject -->
                    <div class="wizard-step" id="step2">
                        <div class="step-indicator">
                            <div class="step-number">2</div>
                            <div class="step-title">What's the issue in a nutshell?</div>
                            <div class="step-subtitle">Give us a brief summary of your problem</div>
                        </div>
                        
                        <div class="form-floating">
                            <input type="text" name="subject" class="form-control" id="subjectInput" 
                                   placeholder="Brief description of your issue..." 
                                   maxlength="100" required>
                            <label for="subjectInput">
                                <i class="bi bi-chat-quote-fill me-2"></i>Subject
                            </label>
                            <div class="character-count" id="subjectCount">0/100</div>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-enhanced btn-outline-secondary" onclick="TicketWizard.previousStep()">
                                <i class="bi bi-arrow-left me-2"></i>Back
                            </button>
                            <button type="button" class="btn btn-enhanced btn-primary" onclick="TicketWizard.nextStep()" id="subjectNext">
                                Next<i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 3: Details and Attachments -->
                    <div class="wizard-step" id="step3">
                        <div class="step-indicator">
                            <div class="step-number">3</div>
                            <div class="step-title">Tell us more!</div>
                            <div class="step-subtitle">Provide details and attach any relevant files</div>
                        </div>
                        
                        <div class="form-floating position-relative">
                            <textarea name="details" class="form-control" id="detailsTextarea" 
                                      placeholder="Please provide as much detail as possible. What happened? When did it happen? What were you trying to do?" 
                                      style="height: 150px; resize: vertical;" 
                                      maxlength="1000" required></textarea>
                            <label for="detailsTextarea">
                                <i class="bi bi-card-text me-2"></i>Details
                            </label>
                            <div class="character-count" id="detailsCount">0/1000</div>
                        </div>
                        
                        <div class="attachment-area" id="attachmentArea">
                            <i class="bi bi-cloud-upload upload-icon"></i>
                            <h4>Drag & Drop Files Here</h4>
                            <p class="text-muted">or <button type="button" class="btn btn-link p-0 fw-bold" onclick="document.getElementById('fileInput').click()">browse files</button></p>
                            <small class="text-muted">Supported: JPG, PNG, GIF, PDF, DOC, DOCX, TXT, CSV, XLS, XLSX, ZIP, RAR, 7Z<br>Max 5MB each, 5 files maximum</small>
                            <input type="file" name="attachment[]" id="fileInput" class="file-input" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.csv,.xls,.xlsx,.zip,.rar,.7z">
                        </div>
                        
                        <div class="file-list" id="fileList"></div>
                        
                        <div class="file-upload-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>File Upload:</strong> Files will be attached to your initial ticket and visible to support staff. 
                            You can also attach files to replies after the ticket is created.
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-enhanced btn-outline-secondary" onclick="TicketWizard.previousStep()">
                                <i class="bi bi-arrow-left me-2"></i>Back
                            </button>
                            <button type="submit" name="submit_ticket" class="btn btn-enhanced btn-success" id="submitBtn">
                                <span class="btn-text">
                                    <i class="bi bi-send-fill me-2"></i>Send My Request
                                </span>
                                <div class="loading-spinner" id="loadingSpinner"></div>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- NON-CONFLICTING JAVASCRIPT -->
<script>
// Create namespaced object to avoid conflicts
window.TicketWizard = (function() {
    'use strict';
    
    // Private variables
    let currentStep = 1;
    const totalSteps = 3;
    let elements = {};
    let initialized = false;
    
    // Private methods
    function initializeElements() {
        elements = {
            progressBar: document.getElementById('progressBar'),
            form: document.getElementById('ticketForm'),
            submitBtn: document.getElementById('submitBtn'),
            loadingSpinner: document.getElementById('loadingSpinner'),
            btnText: document.querySelector('.btn-text'),
            successMessage: document.getElementById('successMessage'),
            subjectInput: document.getElementById('subjectInput'),
            detailsTextarea: document.getElementById('detailsTextarea'),
            subjectCount: document.getElementById('subjectCount'),
            detailsCount: document.getElementById('detailsCount'),
            fileInput: document.getElementById('fileInput'),
            attachmentArea: document.getElementById('attachmentArea'),
            fileList: document.getElementById('fileList')
        };
    }
    
    function updateCharacterCount(input, counter, maxLength) {
        const current = input.value.length;
        counter.textContent = `${current}/${maxLength}`;
        
        if (current > maxLength * 0.8) {
            counter.classList.add('warning');
        } else {
            counter.classList.remove('warning');
        }
    }
    
    function updateProgress() {
        const progress = (currentStep / totalSteps) * 100;
        elements.progressBar.style.width = `${progress}%`;
    }
    
    function showStep(step) {
        document.querySelectorAll('.wizard-step').forEach(s => s.classList.remove('active'));
        const targetStep = document.getElementById(`step${step}`);
        if (targetStep) {
            targetStep.classList.add('active');
        }
        updateProgress();
    }
    
    function handleFiles(files) {
        const allowedTypes = ['.jpg', '.jpeg', '.png', '.gif', '.pdf', '.doc', '.docx', '.txt', '.csv', '.xls', '.xlsx', '.zip', '.rar', '.7z'];
        
        Array.from(files).forEach(file => {
            const extension = '.' + file.name.split('.').pop().toLowerCase();
            
            if (!allowedTypes.includes(extension)) {
                alert(`File type not supported: ${file.name}. Please use: ${allowedTypes.join(', ')}`);
                return;
            }
            
            if (file.size > 5 * 1024 * 1024) { // 5MB limit
                alert(`File ${file.name} is too large. Please choose files under 5MB.`);
                return;
            }
            
            if (elements.fileInput.files.length >= 5) { // Max 5 files
                alert('You can only attach up to 5 files.');
                return;
            }
        });
        
        updateFileList();
    }
    
    function updateFileList() {
        elements.fileList.innerHTML = '';
        
        if (elements.fileInput.files.length === 0) {
            return;
        }
        
        Array.from(elements.fileInput.files).forEach((file, index) => {
            const fileItem = document.createElement('div');
            fileItem.className = 'file-item';
            fileItem.innerHTML = `
                <i class="bi bi-file-earmark"></i>
                <div class="file-item-info">
                    <div class="file-item-name">${file.name}</div>
                    <div class="file-item-size">${formatFileSize(file.size)}</div>
                </div>
                <button type="button" class="file-remove" onclick="TicketWizard.removeFile(${index})">
                    <i class="bi bi-x-circle"></i>
                </button>
            `;
            elements.fileList.appendChild(fileItem);
        });
    }
    
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    function setupEventListeners() {
        // Character counting
        elements.subjectInput.addEventListener('input', () => {
            updateCharacterCount(elements.subjectInput, elements.subjectCount, 100);
        });
        
        elements.detailsTextarea.addEventListener('input', () => {
            updateCharacterCount(elements.detailsTextarea, elements.detailsCount, 1000);
        });
        
        // Category selection
        document.querySelectorAll('.category-button').forEach(button => {
            button.addEventListener('click', function() {
                const groupId = this.dataset.groupId;
                const groupName = this.dataset.groupName;
                
                document.getElementById('selectedGroupId').value = groupId;
                
                // Update step 2 title to include selected category
                const step2Title = document.querySelector('#step2 .step-title');
                if (step2Title) {
                    step2Title.textContent = `${groupName} - What's the issue?`;
                }
                
                nextStep();
            });
        });
        
        // File handling
        elements.attachmentArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });
        
        elements.attachmentArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
        });
        
        elements.attachmentArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            const files = Array.from(e.dataTransfer.files);
            handleFiles(files);
        });
        
        elements.fileInput.addEventListener('change', function(e) {
            const files = Array.from(e.target.files);
            handleFiles(files);
            updateFileList();
        });
        
        // Form submission
        elements.form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const groupId = document.getElementById('selectedGroupId').value;
            const subject = elements.subjectInput.value.trim();
            const details = elements.detailsTextarea.value.trim();
            
            if (!groupId || !subject || !details) {
                alert('Please fill in all required fields!');
                return;
            }
            
            // Show loading state
            elements.btnText.style.display = 'none';
            elements.loadingSpinner.style.display = 'inline-block';
            elements.submitBtn.disabled = true;
            
            // Show success message
            elements.successMessage.style.display = 'block';
            
            // Submit the form
            elements.form.submit();
        });
        
        // Auto-resize textarea
        elements.detailsTextarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 250) + 'px';
        });
    }
    
    function setupScrollAnimations() {
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, observerOptions);

        // Observe fade-in elements
        document.querySelectorAll('.fade-in').forEach(el => {
            observer.observe(el);
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    }
    
    // Public methods
    function nextStep() {
        if (currentStep < totalSteps) {
            currentStep++;
            showStep(currentStep);
        }
    }
    
    function previousStep() {
        if (currentStep > 1) {
            currentStep--;
            showStep(currentStep);
        }
    }
    
    function removeFile(index) {
        const dt = new DataTransfer();
        const files = Array.from(elements.fileInput.files);
        
        files.forEach((file, i) => {
            if (i !== index) {
                dt.items.add(file);
            }
        });
        
        elements.fileInput.files = dt.files;
        updateFileList();
    }
    
    function init() {
        if (initialized) return;
        
        initializeElements();
        setupEventListeners();
        setupScrollAnimations();
        updateProgress();
        
        initialized = true;
        console.log('TicketWizard initialized successfully');
    }
    
    // Public API
    return {
        init: init,
        nextStep: nextStep,
        previousStep: previousStep,
        removeFile: removeFile
    };
})();

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Small delay to ensure Bootstrap is fully loaded
    setTimeout(function() {
        TicketWizard.init();
    }, 100);
});
</script>




<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
