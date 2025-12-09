<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;
$ticket_id = $_GET['id'] ?? null;
$token = $_GET['token'] ?? null;

$error_message = '';
$success_message = '';

// Verify ticket exists and belongs to user (or use token for email link access)
if (!$ticket_id) {
    header('Location: /members/my-ticket.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT t.*, u.email, u.username
    FROM support_tickets t
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.id = ?
");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    header('Location: /members/my-ticket.php');
    exit;
}

// Verify access via session or token
$has_access = false;
if ($user && $user['id'] == $ticket['user_id']) {
    $has_access = true;
} elseif ($token) {
    $expected_token = md5($ticket_id . $ticket['email'] . 'caminhoit_feedback_secret');
    if ($token === $expected_token) {
        $has_access = true;
        // Set user context for token-based access
        if (!$user) {
            $user = ['id' => $ticket['user_id'], 'username' => $ticket['username']];
        }
    }
}

if (!$has_access) {
    header('Location: /login.php');
    exit;
}

// Check if ticket is closed
if ($ticket['status'] !== 'Closed') {
    $error_message = "Feedback can only be submitted for closed tickets.";
}

// Check if feedback already exists
$stmt = $pdo->prepare("SELECT id FROM support_ticket_feedback WHERE ticket_id = ? AND user_id = ?");
$stmt->execute([$ticket_id, $user['id']]);
$existing_feedback = $stmt->fetch();

if ($existing_feedback) {
    $success_message = "Thank you! You've already submitted feedback for this ticket.";
}

// Handle feedback submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existing_feedback && $ticket['status'] === 'Closed') {
    $rating = (int)($_POST['rating'] ?? 0);
    $feedback_text = trim($_POST['feedback_text'] ?? '');
    $helpful = $_POST['helpful'] ?? 'neutral';
    $would_recommend = isset($_POST['would_recommend']) ? (int)$_POST['would_recommend'] : null;
    $response_time_rating = (int)($_POST['response_time_rating'] ?? 0);
    $resolution_quality_rating = (int)($_POST['resolution_quality_rating'] ?? 0);
    $staff_professionalism_rating = (int)($_POST['staff_professionalism_rating'] ?? 0);

    if ($rating >= 1 && $rating <= 5) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO support_ticket_feedback
                (ticket_id, user_id, rating, feedback_text, helpful, would_recommend,
                 response_time_rating, resolution_quality_rating, staff_professionalism_rating, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $result = $stmt->execute([
                $ticket_id,
                $user['id'],
                $rating,
                $feedback_text,
                $helpful,
                $would_recommend,
                $response_time_rating ?: null,
                $resolution_quality_rating ?: null,
                $staff_professionalism_rating ?: null
            ]);

            if ($result) {
                $success_message = "Thank you for your feedback! We appreciate your input.";
            } else {
                $error_message = "Failed to submit feedback. Please try again.";
            }
        } catch (PDOException $e) {
            error_log("Feedback submission error: " . $e->getMessage());
            $error_message = "An error occurred while submitting your feedback.";
        }
    } else {
        $error_message = "Please provide a valid rating (1-5 stars).";
    }
}

$page_title = "Ticket Feedback | CaminhoIT";
?>
<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">

    <style>
        .feedback-container {
            max-width: 700px;
            margin: 50px auto;
            padding: 40px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }

        .feedback-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .feedback-header h1 {
            color: #667eea;
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .feedback-header .emoji {
            font-size: 4rem;
            margin-bottom: 20px;
        }

        .ticket-summary {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 4px solid #667eea;
        }

        .star-rating {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 20px 0;
        }

        .star-rating input {
            display: none;
        }

        .star-rating .star {
            font-size: 3rem;
            cursor: pointer;
            color: #ddd;
            transition: all 0.2s;
            user-select: none;
        }

        .star-rating .star.filled {
            color: #667eea;
        }

        .star-rating .star:hover {
            transform: scale(1.1);
        }

        .rating-group {
            margin: 25px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .rating-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #495057;
        }

        .rating-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 15px 0;
        }

        .rating-label {
            flex: 1;
        }

        .mini-stars {
            display: flex;
            gap: 5px;
        }

        .mini-stars input {
            display: none;
        }

        .mini-stars .star {
            font-size: 1.5rem;
            cursor: pointer;
            color: #ddd;
            transition: all 0.2s;
            user-select: none;
        }

        .mini-stars .star.filled {
            color: #667eea;
        }

        .mini-stars .star:hover {
            transform: scale(1.1);
        }

        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 15px 50px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 10px;
            width: 100%;
            margin-top: 20px;
            transition: transform 0.2s;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }

        .success-box {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
        }

        .success-box h2 {
            color: white;
            margin-bottom: 15px;
        }

        .success-emoji {
            font-size: 5rem;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

<?php if (isset($_SESSION['user'])): ?>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>
<?php endif; ?>

<div class="container">
    <div class="feedback-container">

        <?php if ($success_message && $existing_feedback): ?>
            <div class="success-box">
                <div class="success-emoji">🎉</div>
                <h2>Thank You!</h2>
                <p><?= htmlspecialchars($success_message) ?></p>
                <a href="/members/my-ticket.php" class="btn btn-light mt-3">View My Tickets</a>
            </div>

        <?php elseif ($success_message): ?>
            <div class="success-box">
                <div class="success-emoji">⭐</div>
                <h2>Feedback Submitted!</h2>
                <p><?= htmlspecialchars($success_message) ?></p>
                <a href="/members/my-ticket.php" class="btn btn-light mt-3">View My Tickets</a>
            </div>

        <?php elseif ($error_message && $ticket['status'] !== 'Closed'): ?>
            <div class="feedback-header">
                <div class="emoji">🔓</div>
                <h1>Ticket Still Open</h1>
            </div>
            <div class="alert alert-warning">
                <?= htmlspecialchars($error_message) ?>
            </div>
            <div class="text-center">
                <a href="/members/view-ticket.php?id=<?= $ticket_id ?>" class="btn btn-primary">View Ticket</a>
            </div>

        <?php else: ?>
            <div class="feedback-header">
                <div class="emoji">📝</div>
                <h1>How Did We Do?</h1>
                <p class="text-muted">Your feedback helps us improve our service</p>
            </div>

            <div class="ticket-summary">
                <h5><i class="bi bi-ticket-perforated me-2"></i>Ticket #<?= $ticket_id ?></h5>
                <p class="mb-0"><strong>Subject:</strong> <?= htmlspecialchars($ticket['subject']) ?></p>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <!-- Overall Rating -->
                <div class="rating-group">
                    <label class="text-center d-block">Overall Experience</label>
                    <div class="star-rating" id="mainRating">
                        <span class="star" data-value="1" title="1 - Poor">☆</span>
                        <span class="star" data-value="2" title="2 - Fair">☆</span>
                        <span class="star" data-value="3" title="3 - Good">☆</span>
                        <span class="star" data-value="4" title="4 - Very Good">☆</span>
                        <span class="star" data-value="5" title="5 - Excellent">☆</span>
                    </div>
                    <input type="hidden" name="rating" id="ratingValue" required>
                    <div class="d-flex justify-content-between px-3" style="font-size: 0.75rem; color: #6c757d;">
                        <span>1 - Poor</span>
                        <span>5 - Excellent</span>
                    </div>
                </div>

                <!-- Detailed Ratings -->
                <div class="rating-group">
                    <label>Detailed Feedback</label>
                    <div class="text-muted mb-3" style="font-size: 0.75rem;">
                        <small>Rate from 1 (Poor) to 5 (Excellent) - Optional</small>
                    </div>

                    <div class="rating-row">
                        <div class="rating-label">Response Time</div>
                        <div class="mini-stars" data-rating="response_time">
                            <span class="star" data-value="1" title="1 - Poor">☆</span>
                            <span class="star" data-value="2" title="2 - Fair">☆</span>
                            <span class="star" data-value="3" title="3 - Good">☆</span>
                            <span class="star" data-value="4" title="4 - Very Good">☆</span>
                            <span class="star" data-value="5" title="5 - Excellent">☆</span>
                        </div>
                        <input type="hidden" name="response_time_rating" id="responseTimeValue">
                    </div>

                    <div class="rating-row">
                        <div class="rating-label">Resolution Quality</div>
                        <div class="mini-stars" data-rating="resolution_quality">
                            <span class="star" data-value="1" title="1 - Poor">☆</span>
                            <span class="star" data-value="2" title="2 - Fair">☆</span>
                            <span class="star" data-value="3" title="3 - Good">☆</span>
                            <span class="star" data-value="4" title="4 - Very Good">☆</span>
                            <span class="star" data-value="5" title="5 - Excellent">☆</span>
                        </div>
                        <input type="hidden" name="resolution_quality_rating" id="resolutionQualityValue">
                    </div>

                    <div class="rating-row">
                        <div class="rating-label">Staff Professionalism</div>
                        <div class="mini-stars" data-rating="staff_professionalism">
                            <span class="star" data-value="1" title="1 - Poor">☆</span>
                            <span class="star" data-value="2" title="2 - Fair">☆</span>
                            <span class="star" data-value="3" title="3 - Good">☆</span>
                            <span class="star" data-value="4" title="4 - Very Good">☆</span>
                            <span class="star" data-value="5" title="5 - Excellent">☆</span>
                        </div>
                        <input type="hidden" name="staff_professionalism_rating" id="staffProfessionalismValue">
                    </div>
                </div>

                <!-- Was it helpful -->
                <div class="form-group mb-3">
                    <label class="form-label">Did we resolve your issue?</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="helpful" id="helpful-yes" value="yes">
                        <label class="btn btn-outline-success" for="helpful-yes">✅ Yes</label>

                        <input type="radio" class="btn-check" name="helpful" id="helpful-neutral" value="neutral" checked>
                        <label class="btn btn-outline-secondary" for="helpful-neutral">➖ Partially</label>

                        <input type="radio" class="btn-check" name="helpful" id="helpful-no" value="no">
                        <label class="btn btn-outline-danger" for="helpful-no">❌ No</label>
                    </div>
                </div>

                <!-- Would recommend -->
                <div class="form-group mb-3">
                    <label class="form-label">Would you recommend our support to others?</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="would_recommend" id="recommend-yes" value="1">
                        <label class="btn btn-outline-success" for="recommend-yes">👍 Yes</label>

                        <input type="radio" class="btn-check" name="would_recommend" id="recommend-no" value="0">
                        <label class="btn btn-outline-danger" for="recommend-no">👎 No</label>
                    </div>
                </div>

                <!-- Comments -->
                <div class="form-group mb-3">
                    <label class="form-label">Additional Comments (Optional)</label>
                    <textarea name="feedback_text" class="form-control" rows="4"
                              placeholder="Tell us more about your experience..."></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-submit">
                    <i class="bi bi-send-fill me-2"></i>Submit Feedback
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Star Rating System
document.addEventListener('DOMContentLoaded', function() {
    // Main rating
    const mainRating = document.getElementById('mainRating');
    if (mainRating) {
        const stars = mainRating.querySelectorAll('.star');
        const ratingInput = document.getElementById('ratingValue');

        stars.forEach((star, index) => {
            star.addEventListener('click', function() {
                const value = this.getAttribute('data-value');
                ratingInput.value = value;

                // Update star display
                stars.forEach((s, i) => {
                    if (i < value) {
                        s.textContent = '★';
                        s.classList.add('filled');
                    } else {
                        s.textContent = '☆';
                        s.classList.remove('filled');
                    }
                });
            });
        });
    }

    // Mini star ratings
    const miniStarContainers = document.querySelectorAll('.mini-stars');
    miniStarContainers.forEach(container => {
        const stars = container.querySelectorAll('.star');
        const ratingType = container.getAttribute('data-rating');
        let hiddenInput;

        // Find the corresponding hidden input
        if (ratingType === 'response_time') {
            hiddenInput = document.getElementById('responseTimeValue');
        } else if (ratingType === 'resolution_quality') {
            hiddenInput = document.getElementById('resolutionQualityValue');
        } else if (ratingType === 'staff_professionalism') {
            hiddenInput = document.getElementById('staffProfessionalismValue');
        }

        stars.forEach((star, index) => {
            star.addEventListener('click', function() {
                const value = this.getAttribute('data-value');
                if (hiddenInput) {
                    hiddenInput.value = value;
                }

                // Update star display
                stars.forEach((s, i) => {
                    if (i < value) {
                        s.textContent = '★';
                        s.classList.add('filled');
                    } else {
                        s.textContent = '☆';
                        s.classList.remove('filled');
                    }
                });
            });
        });
    });
});
</script>
</body>
</html>
