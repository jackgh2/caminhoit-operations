<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/GuestTicketHelper.php';

$guestHelper = new GuestTicketHelper($pdo);

// Get access token from URL
$accessToken = $_GET['token'] ?? '';

if (empty($accessToken)) {
    header('Location: /login.php');
    exit;
}

// Verify access token
$guestTicket = $guestHelper->verifyAccessToken($accessToken);

if (!$guestTicket) {
    $error = "Invalid or expired access link. Please check your email for the correct link.";
} else {
    $ticket_id = $guestTicket['ticket_id'];

    // Handle reply submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_content'])) {
        $reply_content = trim($_POST['reply_content']);
        if (!empty($reply_content)) {
            $result = $guestHelper->createGuestReply(
                $ticket_id,
                $guestTicket['guest_email'],
                $reply_content
            );

            if ($result['success']) {
                $_SESSION['success_message'] = "Your reply has been posted successfully!";
                header("Location: /public/view-ticket.php?token=" . $accessToken);
                exit;
            } else {
                $error = "Failed to post reply. Please try again.";
            }
        }
    }

    // Get ticket details
    $stmt = $pdo->prepare("
        SELECT t.*,
               tg.name AS group_name,
               ei.processed_at as ticket_imported_at
        FROM support_tickets t
        LEFT JOIN support_ticket_groups tg ON t.group_id = tg.id
        LEFT JOIN support_email_imports ei ON ei.ticket_id = t.id AND ei.import_type = 'new_ticket'
        WHERE t.id = ? AND t.is_guest_ticket = 1
    ");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch();

    // Get replies
    $stmt = $pdo->prepare("
        SELECT r.*,
               COALESCE(u.username, 'You') as username,
               COALESCE(u.role, 'guest') as role,
               ei.processed_at as email_imported_at
        FROM support_ticket_replies r
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN support_email_imports ei ON ei.reply_id = r.id AND ei.import_type = 'reply'
        WHERE r.ticket_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$ticket_id]);
    $replies = $stmt->fetchAll();
}

// Helper functions
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);

    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' min ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' months ago';
    return floor($time/31536000) . ' years ago';
}

function isStaff($role) {
    return in_array($role, ['administrator', 'support_user', 'support_technician', 'accountant']);
}

function getAvatarColor($role) {
    if (isStaff($role)) {
        return '#3b82f6'; // Blue for staff
    }
    return '#10b981'; // Green for guest
}

$page_title = isset($ticket) ? "Ticket #" . htmlspecialchars($ticket['id']) : "Access Denied";
?>
<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?> | CaminhoIT Support</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Quill Rich Text Editor -->
    <link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">

    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --border-radius: 12px;
        }

        body {
            background-color: #f8fafc;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .ticket-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .status-open {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-closed {
            background: #d1fae5;
            color: #065f46;
        }

        .reply-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        .reply-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .reply-avatar {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin-right: 1rem;
        }

        .reply-user {
            display: flex;
            align-items: center;
        }

        /* Quill Editor Styling */
        #replyEditor {
            min-height: 150px;
            background: white;
        }

        .ql-toolbar {
            border-top-left-radius: var(--border-radius);
            border-top-right-radius: var(--border-radius);
            background: #f8fafc;
        }

        .ql-container {
            border-bottom-left-radius: var(--border-radius);
            border-bottom-right-radius: var(--border-radius);
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav.php'; ?>

<header class="hero-enhanced">
    <div class="container">
        <div class="hero-content-enhanced">
            <h1 class="hero-title-enhanced text-white">
                <i class="bi bi-ticket-detailed me-2"></i>
                Support Ticket
            </h1>
            <p class="hero-subtitle-enhanced text-white">
                View and manage your support ticket
            </p>
        </div>
    </div>
</header>

<div class="container py-5" style="margin-top: -60px; position: relative; z-index: 10;">
    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($error); ?>
        </div>
    <?php else: ?>
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <?= htmlspecialchars($_SESSION['success_message']); ?>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <!-- Ticket Details -->
        <div class="ticket-card">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h2 class="mb-2"><?= htmlspecialchars($ticket['subject']); ?></h2>
                    <div class="text-muted">
                        <small>
                            <i class="bi bi-hash"></i> Ticket #<?= $ticket['id']; ?>
                            <span class="mx-2">•</span>
                            <i class="bi bi-calendar"></i> <?= date('M j, Y g:i A', strtotime($ticket['created_at'])); ?>
                            <span class="mx-2">•</span>
                            <i class="bi bi-envelope"></i> <?= htmlspecialchars($guestTicket['guest_email']); ?>
                        </small>
                    </div>
                </div>
                <span class="status-badge status-<?= strtolower($ticket['status']); ?>">
                    <?= htmlspecialchars($ticket['status']); ?>
                </span>
            </div>

            <div class="border-top pt-3">
                <h5 class="mb-3">Initial Request</h5>
                <p style="white-space: pre-wrap;"><?= htmlspecialchars($ticket['details']); ?></p>
            </div>
        </div>

        <!-- Replies -->
        <?php if (!empty($replies)): ?>
            <h4 class="mb-3">Conversation</h4>
            <?php foreach ($replies as $reply): ?>
                <?php $isStaffReply = isStaff($reply['role']); ?>
                <div class="reply-card">
                    <div class="reply-header">
                        <div class="reply-user">
                            <div class="reply-avatar" style="background: <?= getAvatarColor($reply['role']); ?>">
                                <?= strtoupper(substr($reply['username'], 0, 2)); ?>
                            </div>
                            <div>
                                <strong><?= htmlspecialchars($reply['username']); ?></strong>
                                <?php if ($isStaffReply): ?>
                                    <span class="badge bg-primary ms-2">Support Staff</span>
                                <?php endif; ?>
                                <?php if (!empty($reply['email_imported_at'])): ?>
                                    <span class="badge bg-info ms-2">
                                        <i class="bi bi-envelope-fill"></i> Email
                                    </span>
                                <?php endif; ?>
                                <br>
                                <small class="text-muted">
                                    <?= date('M j, Y g:i A', strtotime($reply['created_at'])); ?> • <?= timeAgo($reply['created_at']); ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    <div><?= $reply['message']; ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Reply Form -->
        <?php if ($ticket['status'] !== 'Closed'): ?>
            <div class="ticket-card">
                <h4 class="mb-3">Add Your Reply</h4>
                <form method="post" id="replyFormElement">
                    <div id="replyEditor"></div>
                    <input type="hidden" name="reply_content" id="replyContent">
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send me-2"></i>
                            Submit Reply
                        </button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                This ticket has been closed. If you need further assistance, please send a new email to support.
            </div>
        <?php endif; ?>

        <div class="text-center mt-4">
            <small class="text-muted">
                <i class="bi bi-shield-check me-1"></i>
                This is a secure link for your ticket. Do not share this URL with others.
            </small>
        </div>
    <?php endif; ?>
</div>

<!-- Quill Rich Text Editor JS -->
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let quill = null;

document.addEventListener('DOMContentLoaded', function() {
    // Initialize Quill Editor
    const editorElement = document.getElementById('replyEditor');
    if (editorElement) {
        quill = new Quill('#replyEditor', {
            theme: 'snow',
            placeholder: 'Type your reply here...',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline'],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    ['link'],
                    ['clean']
                ]
            }
        });
    }

    // Handle form submission
    const replyFormElement = document.getElementById('replyFormElement');
    if (replyFormElement) {
        replyFormElement.addEventListener('submit', function(e) {
            if (quill) {
                const htmlContent = quill.root.innerHTML;
                document.getElementById('replyContent').value = htmlContent;

                const textContent = quill.getText().trim();
                if (!textContent) {
                    e.preventDefault();
                    alert('Reply content cannot be empty');
                    return false;
                }
            }
        });
    }
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>

</body>
</html>
