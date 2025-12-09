<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/lang.php';

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /login.php');
    exit;
}

// Check if user is staff/admin
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_role = $stmt->fetchColumn();

if (!in_array($user_role, ['administrator', 'support_user', 'support_technician', 'accountant'])) {
    header('Location: /dashboard.php');
    exit;
}

$user_id = $user['id'];
$ticket_id = $_GET['id'] ?? null;

if (!$ticket_id) {
    header('Location: /members/staff-tickets.php');
    exit;
}

// Function to log activity
function logActivity($pdo, $ticket_id, $user_id, $action_type, $old_value = null, $new_value = null, $details = null) {
    $stmt = $pdo->prepare("INSERT INTO support_ticket_activity_log (ticket_id, user_id, action_type, old_value, new_value, details, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$ticket_id, $user_id, $action_type, $old_value, $new_value, $details]);
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_reply':
                $reply_content = trim($_POST['reply_content']);
                $is_private = isset($_POST['is_private']) ? 1 : 0;
                
                if (!empty($reply_content)) {
                    if ($is_private) {
                        // Only add to support_ticket_responses with is_internal = 1
                        $stmt = $pdo->prepare("INSERT INTO support_ticket_responses (ticket_id, user_id, message, is_internal, created_at) VALUES (?, ?, ?, 1, NOW())");
                        $stmt->execute([$ticket_id, $user_id, $reply_content]);
                        $reply_id = $pdo->lastInsertId();
                        
                        // Log activity
                        logActivity($pdo, $ticket_id, $user_id, 'private_reply', null, null, 'Added private note');
                    } else {
                        // Add to support_ticket_replies (public reply)
                        $stmt = $pdo->prepare("INSERT INTO support_ticket_replies (ticket_id, user_id, message, created_at) VALUES (?, ?, ?, NOW())");
                        $stmt->execute([$ticket_id, $user_id, $reply_content]);
                        $reply_id = $pdo->lastInsertId();

                        // Log activity
                        logActivity($pdo, $ticket_id, $user_id, 'reply', null, null, 'Added reply');

                        // Send notifications when staff replies (only for public replies, not private notes)
                        try {
                            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/TicketNotifications.php';
                            $notifications = new TicketNotifications($pdo);
                            $notifications->notifyStaffReply($ticket_id, $reply_id, $user_id);
                            error_log("Staff reply notifications sent for ticket #{$ticket_id}, reply #{$reply_id}");
                        } catch (Exception $e) {
                            error_log("Failed to send staff reply notifications: " . $e->getMessage());
                            // Don't fail the reply if notifications fail
                        }
                    }

                    // Handle file uploads
                    if (!empty($_FILES['attachment']['name'][0])) {
                        $uploadDir = __DIR__ . '/attachments/' . $ticket_id . '/';
                        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

                        foreach ($_FILES['attachment']['tmp_name'] as $i => $tmpName) {
                            if (is_uploaded_file($tmpName)) {
                                $originalName = basename($_FILES['attachment']['name'][$i]);
                                $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                                $newName = uniqid() . '.' . $extension;
                                $destination = $uploadDir . $newName;

                                if (move_uploaded_file($tmpName, $destination)) {
                                    $stmt = $pdo->prepare("INSERT INTO support_ticket_attachments (reply_id, file_name, original_name, uploaded_at) VALUES (?, ?, ?, NOW())");
                                    $stmt->execute([$reply_id, $newName, $originalName]);
                                }
                            }
                        }
                    }

                    $pdo->prepare("UPDATE support_tickets SET updated_at = NOW(), updated_by = ? WHERE id = ?")->execute([$user_id, $ticket_id]);
                    echo json_encode(['success' => true, 'message' => 'Reply added successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Reply content cannot be empty']);
                }
                exit;
                
            case 'update_ticket_status':
                $status = $_POST['status'] ?? null;
                if ($status) {
                    // Get current status for logging
                    $stmt = $pdo->prepare("SELECT status FROM support_tickets WHERE id = ?");
                    $stmt->execute([$ticket_id]);
                    $old_status = $stmt->fetchColumn();
                    
                    $stmt = $pdo->prepare("UPDATE support_tickets SET status = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                    if ($stmt->execute([$status, $user_id, $ticket_id])) {
                        // Log activity
                        logActivity($pdo, $ticket_id, $user_id, 'status_change', $old_status, $status, "Status changed from '{$old_status}' to '{$status}'");

                        // Send notification if ticket is closed
                        if ($status === 'Closed' && $old_status !== 'Closed') {
                            try {
                                require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/TicketNotifications.php';
                                $notifications = new TicketNotifications($pdo);
                                $notifications->notifyTicketClosed($ticket_id, $user_id);
                                error_log("Ticket closure notification sent for ticket #{$ticket_id}");
                            } catch (Exception $e) {
                                error_log("Failed to send ticket closure notification: " . $e->getMessage());
                                // Don't fail the status update if notification fails
                            }
                        }

                        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to update status']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid status']);
                }
                exit;
                
            case 'update_ticket_priority':
                $priority = $_POST['priority'] ?? null;
                if ($priority) {
                    // Get current priority for logging
                    $stmt = $pdo->prepare("SELECT priority FROM support_tickets WHERE id = ?");
                    $stmt->execute([$ticket_id]);
                    $old_priority = $stmt->fetchColumn() ?: 'Normal';
                    
                    $stmt = $pdo->prepare("UPDATE support_tickets SET priority = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                    if ($stmt->execute([$priority, $user_id, $ticket_id])) {
                        // Log activity
                        logActivity($pdo, $ticket_id, $user_id, 'priority_change', $old_priority, $priority, "Priority changed from '{$old_priority}' to '{$priority}'");
                        
                        echo json_encode(['success' => true, 'message' => 'Priority updated successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to update priority']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid priority']);
                }
                exit;
                
            case 'update_ticket_category':
                $group_id = $_POST['group_id'] ?? null;
                $group_id = $group_id === '' ? null : $group_id;
                
                // Get current category for logging
                $stmt = $pdo->prepare("SELECT tg.name FROM support_tickets t LEFT JOIN support_ticket_groups tg ON t.group_id = tg.id WHERE t.id = ?");
                $stmt->execute([$ticket_id]);
                $old_category = $stmt->fetchColumn() ?: 'No Category';
                
                // Get new category name
                if ($group_id) {
                    $stmt = $pdo->prepare("SELECT name FROM support_ticket_groups WHERE id = ?");
                    $stmt->execute([$group_id]);
                    $new_category = $stmt->fetchColumn();
                } else {
                    $new_category = 'No Category';
                }
                
                $stmt = $pdo->prepare("UPDATE support_tickets SET group_id = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                if ($stmt->execute([$group_id, $user_id, $ticket_id])) {
                    // Log activity
                    logActivity($pdo, $ticket_id, $user_id, 'category_change', $old_category, $new_category, "Category changed from '{$old_category}' to '{$new_category}'");
                    
                    echo json_encode(['success' => true, 'message' => 'Category updated successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update category']);
                }
                exit;
                
            case 'update_ticket_customer':
                $new_user_id = $_POST['user_id'] ?? null;
                if ($new_user_id) {
                    // Get current and new customer names for logging
                    $stmt = $pdo->prepare("SELECT u.username FROM support_tickets t LEFT JOIN users u ON t.user_id = u.id WHERE t.id = ?");
                    $stmt->execute([$ticket_id]);
                    $old_customer = $stmt->fetchColumn();
                    
                    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                    $stmt->execute([$new_user_id]);
                    $new_customer = $stmt->fetchColumn();
                    
                    $stmt = $pdo->prepare("UPDATE support_tickets SET user_id = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                    if ($stmt->execute([$new_user_id, $user_id, $ticket_id])) {
                        // Log activity
                        logActivity($pdo, $ticket_id, $user_id, 'customer_change', $old_customer, $new_customer, "Customer changed from '{$old_customer}' to '{$new_customer}'");
                        
                        echo json_encode(['success' => true, 'message' => 'Customer updated successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to update customer']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid customer']);
                }
                exit;
                
            case 'update_ticket_assignment':
                $assigned_to = $_POST['assigned_to'] ?? null;
                if ($assigned_to !== null) {
                    $assigned_to = $assigned_to === '' ? null : $assigned_to;
                    
                    // Get current and new assignment for logging
                    $stmt = $pdo->prepare("SELECT u.username FROM support_tickets t LEFT JOIN users u ON t.assigned_to = u.id WHERE t.id = ?");
                    $stmt->execute([$ticket_id]);
                    $old_assigned = $stmt->fetchColumn() ?: 'Unassigned';
                    
                    if ($assigned_to) {
                        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                        $stmt->execute([$assigned_to]);
                        $new_assigned = $stmt->fetchColumn();
                    } else {
                        $new_assigned = 'Unassigned';
                    }
                    
                    $stmt = $pdo->prepare("UPDATE support_tickets SET assigned_to = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                    if ($stmt->execute([$assigned_to, $user_id, $ticket_id])) {
                        // Log activity
                        logActivity($pdo, $ticket_id, $user_id, 'assignment_change', $old_assigned, $new_assigned, "Assignment changed from '{$old_assigned}' to '{$new_assigned}'");
                        
                        echo json_encode(['success' => true, 'message' => 'Assignment updated successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to update assignment']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid assignment']);
                }
                exit;
                
            case 'add_private_note':
                $note_content = trim($_POST['note_content']);
                if (!empty($note_content)) {
                    $stmt = $pdo->prepare("INSERT INTO support_ticket_responses (ticket_id, user_id, message, is_internal, created_at) VALUES (?, ?, ?, 1, NOW())");
                    if ($stmt->execute([$ticket_id, $user_id, $note_content])) {
                        // Log activity
                        logActivity($pdo, $ticket_id, $user_id, 'private_note', null, null, 'Added private note');
                        
                        echo json_encode(['success' => true, 'message' => 'Private note added successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to add private note']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Note content cannot be empty']);
                }
                exit;
                
            case 'update_private_note':
                $note_id = $_POST['note_id'] ?? null;
                $note_content = trim($_POST['note_content']);
                if ($note_id && !empty($note_content)) {
                    $stmt = $pdo->prepare("UPDATE support_ticket_responses SET message = ? WHERE id = ? AND user_id = ? AND is_internal = 1");
                    if ($stmt->execute([$note_content, $note_id, $user_id])) {
                        // Log activity
                        logActivity($pdo, $ticket_id, $user_id, 'note_update', null, null, 'Updated private note');

                        echo json_encode(['success' => true, 'message' => 'Private note updated successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to update private note']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid note data']);
                }
                exit;

            case 'generate_ai_reply':
                try {
                    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/AIHelper.php';
                    $ai = new AIHelper($pdo);

                    if (!$ai->isEnabled()) {
                        echo json_encode([
                            'success' => false,
                            'error' => 'AI assistance is not enabled. Please configure OpenAI API key in settings.'
                        ]);
                        exit;
                    }

                    $result = $ai->generateTicketReply($ticket_id);
                    echo json_encode($result);
                } catch (Exception $e) {
                    error_log("AI reply generation error: " . $e->getMessage());
                    echo json_encode([
                        'success' => false,
                        'error' => 'Failed to generate AI reply: ' . $e->getMessage()
                    ]);
                }
                exit;
        }
    }
}

// Get ticket details
$stmt = $pdo->prepare("
    SELECT t.*,
           COALESCE(u.username, CONCAT('Guest: ', t.guest_email)) AS customer_name,
           COALESCE(u.email, t.guest_email) AS customer_email,
           c.name AS company_name,
           a.username AS assigned_name,
           tg.name AS group_name,
           ei.processed_at as ticket_imported_at
    FROM support_tickets t
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN companies c ON u.company_id = c.id
    LEFT JOIN users a ON t.assigned_to = a.id
    LEFT JOIN support_ticket_groups tg ON t.group_id = tg.id
    LEFT JOIN support_email_imports ei ON ei.ticket_id = t.id AND ei.import_type = 'new_ticket'
    WHERE t.id = ?
");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    header('Location: /members/staff-tickets.php');
    exit;
}

// Get all replies from support_ticket_replies table (public replies only)
$stmt = $pdo->prepare("
    SELECT r.*,
           COALESCE(u.username, CONCAT('Guest: ', t.guest_email)) as username,
           COALESCE(u.role, 'guest') as role,
           0 as is_private,
           ei.processed_at as email_imported_at
    FROM support_ticket_replies r
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN support_tickets t ON r.ticket_id = t.id
    LEFT JOIN support_email_imports ei ON ei.reply_id = r.id AND ei.import_type = 'reply'
    WHERE r.ticket_id = ?
    ORDER BY r.created_at ASC
");
$stmt->execute([$ticket_id]);
$public_replies = $stmt->fetchAll();

// Get private replies from support_ticket_responses table (internal only)
$stmt = $pdo->prepare("
    SELECT r.*, u.username, u.role, 1 as is_private,
           NULL as email_imported_at
    FROM support_ticket_responses r
    JOIN users u ON r.user_id = u.id
    WHERE r.ticket_id = ? AND r.is_internal = 1
    ORDER BY r.created_at ASC
");
$stmt->execute([$ticket_id]);
$private_replies = $stmt->fetchAll();

// Combine and sort all replies
$all_replies = array_merge($public_replies, $private_replies);
usort($all_replies, function($a, $b) {
    return strtotime($a['created_at']) - strtotime($b['created_at']);
});

// Get attachments for replies
$attachmentsByReply = [];
$stmt = $pdo->prepare("SELECT * FROM support_ticket_attachments WHERE reply_id IN (SELECT id FROM support_ticket_replies WHERE ticket_id = ?)");
$stmt->execute([$ticket_id]);
foreach ($stmt->fetchAll() as $att) {
    $attachmentsByReply[$att['reply_id']][] = $att;
}

// Get initial ticket attachments
$initialAttachments = [];
$stmt = $pdo->prepare("SELECT * FROM support_ticket_attachments_initial WHERE ticket_id = ? ORDER BY uploaded_at DESC");
$stmt->execute([$ticket_id]);
$initialAttachments = $stmt->fetchAll();

// Get activity log
$stmt = $pdo->prepare("
    SELECT al.*, u.username 
    FROM support_ticket_activity_log al 
    LEFT JOIN users u ON al.user_id = u.id 
    WHERE al.ticket_id = ? 
    ORDER BY al.created_at DESC
");
$stmt->execute([$ticket_id]);
$activity_log = $stmt->fetchAll();

// Get private notes (same as private replies, but we'll keep them separate for the sidebar)
$private_notes = $private_replies;

// Get data for dropdowns
$stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE role IN ('administrator', 'support_user', 'support_technician', 'accountant') ORDER BY username");
$stmt->execute();
$staff_users = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, username, email FROM users ORDER BY username");
$stmt->execute();
$all_users = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name FROM support_ticket_groups ORDER BY name");
$stmt->execute();
$ticket_groups = $stmt->fetchAll();

$page_title = "Ticket #" . htmlspecialchars($ticket['id']) . " | Staff Portal";

// Function to get file icon and color
function getFileIcon($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    switch($extension) {
        case 'pdf':
            return ['icon' => 'bi-file-earmark-pdf', 'color' => '#ef4444'];
        case 'doc':
        case 'docx':
            return ['icon' => 'bi-file-earmark-word', 'color' => '#2563eb'];
        case 'xls':
        case 'xlsx':
            return ['icon' => 'bi-file-earmark-excel', 'color' => '#059669'];
        case 'png':
        case 'jpg':
        case 'jpeg':
        case 'gif':
        case 'webp':
            return ['icon' => 'bi-file-earmark-image', 'color' => '#8b5cf6'];
        case 'zip':
        case 'rar':
        case '7z':
            return ['icon' => 'bi-file-earmark-zip', 'color' => '#f59e0b'];
        case 'txt':
            return ['icon' => 'bi-file-earmark-text', 'color' => '#6b7280'];
        case 'csv':
            return ['icon' => 'bi-file-earmark-spreadsheet', 'color' => '#059669'];
        default:
            return ['icon' => 'bi-file-earmark', 'color' => '#6b7280'];
    }
}

// Helper function for relative time
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' min ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' months ago';
    return floor($time/31536000) . ' years ago';
}

// Function to get activity icon and color
function getActivityIcon($action_type) {
    switch ($action_type) {
        case 'status_change':
            return ['icon' => 'bi-flag', 'color' => '#3b82f6'];
        case 'priority_change':
            return ['icon' => 'bi-exclamation-triangle', 'color' => '#f59e0b'];
        case 'assignment_change':
            return ['icon' => 'bi-person-check', 'color' => '#10b981'];
        case 'category_change':
            return ['icon' => 'bi-collection', 'color' => '#8b5cf6'];
        case 'customer_change':
            return ['icon' => 'bi-person', 'color' => '#ef4444'];
        case 'reply':
            return ['icon' => 'bi-chat', 'color' => '#059669'];
        case 'private_reply':
        case 'private_note':
            return ['icon' => 'bi-lock', 'color' => '#6b7280'];
        case 'note_update':
            return ['icon' => 'bi-pencil', 'color' => '#64748b'];
        default:
            return ['icon' => 'bi-circle', 'color' => '#6b7280'];
    }
}

// Function to get status badge class
function getStatusClass($status) {
    switch (strtolower($status)) {
        case 'open':
            return 'status-open';
        case 'in progress':
            return 'status-in-progress';
        case 'awaiting member reply':
            return 'status-awaiting-member';
        case 'on hold':
            return 'status-on-hold';
        case 'pending third party':
            return 'status-pending-third-party';
        case 'pending':
            return 'status-pending';
        case 'closed':
            return 'status-closed';
        default:
            return 'status-default';
    }
}
?>

<!DOCTYPE html>
<html lang="<?= $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">

    <!-- Quill Rich Text Editor -->
    <link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #4F46E5;
            --primary-hover: #3F37C9;
            --success-color: #10B981;
            --warning-color: #F59E0B;
            --danger-color: #EF4444;
            --info-color: #06B6D4;
            --light-gray: #F8FAFC;
            --border-color: #E2E8F0;
            --text-muted: #64748B;
            --customer-color: #059669;
            --staff-color: #3b82f6;
            --private-color: #6b7280;
        }

        body {
            background-color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding-top: 80px;
        }

        /* FORCE NAVBAR BLUE STYLING */
        .navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15) !important;
            padding: 12px 0 !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            z-index: 1030 !important;
        }

        .navbar .navbar-brand,
        .navbar .nav-link,
        .navbar .navbar-text {
            color: white !important;
        }

        .navbar .nav-link:hover {
            color: #e0e7ff !important;
        }

        .navbar .dropdown-menu {
            border: none;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .main-container {
            display: flex;
            max-width: 1400px;
            margin: 2rem auto;
            gap: 2rem;
            padding: 0 1rem;
        }

        .ticket-content {
            flex: 1;
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .ticket-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .ticket-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }

        .ticket-actions {
            display: flex;
            gap: 0.75rem;
        }

        .btn-action {
            padding: 0.5rem 1rem;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            font-size: 0.875rem;
            color: #374151;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-action:hover {
            background: #f9fafb;
            border-color: #9ca3af;
            color: #374151;
        }

        .btn-action.primary {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .btn-action.primary:hover {
            background: var(--primary-hover);
            color: white;
        }

        .btn-action.danger {
            background: var(--danger-color);
            color: white;
            border-color: var(--danger-color);
        }

        .btn-action.danger:hover {
            background: #dc2626;
            color: white;
        }

        .btn-action.warning {
            background: var(--warning-color);
            color: white;
            border-color: var(--warning-color);
        }

        .btn-action.warning:hover {
            background: #d97706;
            color: white;
        }

        .btn-action.info {
            background: var(--info-color);
            color: white;
            border-color: var(--info-color);
        }

        .btn-action.info:hover {
            background: #0891b2;
            color: white;
        }

        .ticket-body {
            padding: 2rem;
        }

        .initial-message {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            line-height: 1.6;
            color: #374151;
        }

        .initial-attachments {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }

        .initial-attachments-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .replies-section {
            border-top: 1px solid #e2e8f0;
            padding-top: 2rem;
        }

        .reply-item {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            border-radius: 8px;
            transition: all 0.2s;
            border-left: 4px solid transparent;
        }

        .reply-item:hover {
            background: #f8fafc;
        }

        /* Color coding for different reply types */
        .reply-item.customer {
            background: #f0fdf4;
            border-left-color: var(--customer-color);
        }

        .reply-item.staff {
            background: #eff6ff;
            border-left-color: var(--staff-color);
        }

        .reply-item.private {
            background: #f8fafc;
            border-left-color: var(--private-color);
            position: relative;
        }

        .reply-item.private::before {
            content: "Private";
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: var(--private-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
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
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        .reply-avatar.customer {
            background: var(--customer-color);
        }

        .reply-avatar.staff {
            background: var(--staff-color);
        }

        .reply-avatar.private {
            background: var(--private-color);
        }

        .reply-content {
            flex: 1;
        }

        .reply-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .reply-author {
            font-weight: 600;
            color: #1f2937;
            font-size: 0.875rem;
        }

        .reply-time {
            color: var(--text-muted);
            font-size: 0.75rem;
        }

        .reply-message {
            color: #374151;
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .attachment-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .attachment-item {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.2s;
            text-decoration: none;
            color: #374151;
        }

        .attachment-item:hover {
            background: #f8fafc;
            color: #374151;
        }

        .attachment-icon {
            font-size: 1.25rem;
        }

        .reply-form {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .reply-textarea {
            width: 100%;
            min-height: 120px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 0.75rem;
            font-size: 0.875rem;
            resize: vertical;
            font-family: inherit;
        }

        .reply-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        /* Quill Editor Styling */
        #replyEditor {
            min-height: 120px;
            background: white;
        }

        #replyEditor .ql-editor {
            min-height: 120px;
            font-size: 0.875rem;
        }

        .ql-container {
            border-bottom-left-radius: 6px;
            border-bottom-right-radius: 6px;
        }

        .ql-toolbar {
            border-top-left-radius: 6px;
            border-top-right-radius: 6px;
            background: #f8fafc;
        }

        .reply-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
        }

        .reply-options {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .sidebar {
            width: 300px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            height: fit-content;
            overflow: hidden;
        }

        .sidebar-section {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .sidebar-section:last-child {
            border-bottom: none;
        }

        .sidebar-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 1rem;
        }

        .sidebar-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            font-size: 0.875rem;
        }

        .sidebar-label {
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .sidebar-value {
            color: #1f2937;
            font-weight: 500;
        }

        .editable-field {
            cursor: pointer;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .editable-field:hover {
            background: #f8fafc;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            cursor: pointer;
        }

        .status-badge:hover {
            opacity: 0.8;
        }

        /* Updated status badge colors */
        .status-open {
            background: #fef2f2;
            color: #dc2626;
        }

        .status-in-progress {
            background: #fef3c7;
            color: #d97706;
        }

        .status-awaiting-member {
            background: #dbeafe;
            color: #2563eb;
        }

        .status-on-hold {
            background: #f3e8ff;
            color: #7c3aed;
        }

        .status-pending-third-party {
            background: #fef3c7;
            color: #92400e;
        }

        .status-pending {
            background: #f3f4f6;
            color: #6b7280;
        }

        .status-closed {
            background: #d1fae5;
            color: #059669;
        }

        .status-default {
            background: #f3f4f6;
            color: #6b7280;
        }

        .private-notes {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .private-note-item {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            position: relative;
        }

        .private-note-item:last-child {
            margin-bottom: 0;
        }

        .private-note-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .private-note-author {
            font-weight: 600;
            color: #1f2937;
            font-size: 0.875rem;
        }

        .private-note-time {
            color: var(--text-muted);
            font-size: 0.75rem;
        }

        .private-note-content {
            color: #374151;
            font-size: 0.875rem;
            line-height: 1.5;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .private-note-content:hover {
            background: #f8fafc;
        }

        .private-note-content.editing {
            background: white;
            border: 1px solid var(--primary-color);
        }

        .private-note-textarea {
            width: 100%;
            min-height: 60px;
            border: none;
            outline: none;
            resize: vertical;
            font-family: inherit;
            font-size: 0.875rem;
            line-height: 1.5;
        }

        .private-note-form {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .private-note-input {
            flex: 1;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
        }

        .private-note-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .btn-add-note {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn-add-note:hover {
            background: var(--primary-hover);
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-wrapper input[type="checkbox"] {
            width: 1rem;
            height: 1rem;
            accent-color: var(--primary-color);
        }

        .file-input {
            display: none;
        }

        .file-upload-btn {
            background: #f8fafc;
            border: 1px solid #d1d5db;
            color: #374151;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .file-upload-btn:hover {
            background: #f1f5f9;
        }

        .toast {
            position: fixed;
            top: 6rem;
            right: 2rem;
            z-index: 1100;
            max-width: 350px;
            padding: 1rem;
            border-radius: 0.5rem;
            color: white;
            font-weight: 500;
            display: none;
        }

        .toast.success {
            background: var(--success-color);
        }

        .toast.error {
            background: var(--danger-color);
        }

        .toast.info {
            background: var(--info-color);
        }

        .dropdown-select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
            background: white;
            transition: all 0.2s ease;
        }

        .dropdown-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .dropdown-select:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        /* Activity Log Styles */
        .activity-log {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: white;
            font-size: 0.875rem;
        }

        .activity-content {
            flex: 1;
        }

        .activity-description {
            font-size: 0.875rem;
            color: #374151;
            line-height: 1.4;
        }

        .activity-meta {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        /* Quick Action Buttons in Header */
        .quick-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        @media (max-width: 1024px) {
            .main-container {
                flex-direction: column;
                gap: 1rem;
            }
            
            .sidebar {
                width: 100%;
                order: -1;
            }

            .quick-actions {
                flex-direction: column;
                width: 100%;
            }

            .btn-action {
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="main-container">
    <!-- Main Ticket Content -->
    <div class="ticket-content">
        <div class="ticket-header">
            <h1><?= htmlspecialchars($ticket['subject']); ?></h1>
            <div class="ticket-actions">
                <div class="quick-actions">
                    <a href="/members/staff-tickets.php" class="btn-action">
                        <i class="bi bi-arrow-left"></i>
                        Back
                    </a>
                    <button class="btn-action primary" onclick="updateTicketStatus('In Progress')">
                        <i class="bi bi-arrow-clockwise"></i>
                        In Progress
                    </button>
                    <button class="btn-action info" onclick="updateTicketStatus('Awaiting Member Reply')">
                        <i class="bi bi-person-lines-fill"></i>
                        Awaiting Member
                    </button>
                    <button class="btn-action warning" onclick="updateTicketStatus('On Hold')">
                        <i class="bi bi-pause-circle"></i>
                        On Hold
                    </button>
                    <button class="btn-action warning" onclick="updateTicketStatus('Pending Third Party')">
                        <i class="bi bi-hourglass-split"></i>
                        Pending 3rd Party
                    </button>
                    <button class="btn-action danger" onclick="updateTicketStatus('Closed')">
                        <i class="bi bi-x-circle"></i>
                        Close Ticket
                    </button>
                </div>
            </div>
        </div>

        <div class="ticket-body">
            <!-- Initial Message -->
            <div class="initial-message">
                <?php if ($ticket['is_guest_ticket'] == 1): ?>
                    <div style="margin-bottom: 1rem; padding: 0.75rem 1rem; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-left: 3px solid #f59e0b; border-radius: 4px;">
                        <strong style="color: #92400e; display: block; margin-bottom: 0.25rem;">
                            <i class="bi bi-person-badge"></i> Guest Ticket
                        </strong>
                        <small style="color: #78350f;">
                            <i class="bi bi-envelope"></i> Guest Email: <strong><?= htmlspecialchars($ticket['guest_email']); ?></strong>
                            <span class="mx-2">•</span>
                            <i class="bi bi-info-circle"></i> This person does not have a CaminhoIT account
                            <?php if (!empty($ticket['ticket_imported_at'])): ?>
                                <span class="mx-2">•</span>
                                Imported <?= timeAgo($ticket['ticket_imported_at']); ?>
                            <?php endif; ?>
                        </small>
                    </div>
                <?php elseif (!empty($ticket['ticket_imported_at'])): ?>
                    <div style="margin-bottom: 1rem; padding: 0.5rem 1rem; background: #dbeafe; border-left: 3px solid #3b82f6; border-radius: 4px;">
                        <small style="color: #1e40af; font-weight: 600;">
                            <i class="bi bi-envelope-fill"></i> This ticket was created from an email import
                            <span style="margin-left: 0.5rem; color: #64748b;">• Imported <?= timeAgo($ticket['ticket_imported_at']); ?></span>
                        </small>
                    </div>
                <?php endif; ?>
                <?= nl2br(htmlspecialchars($ticket['details'] ?? 'No details provided')); ?>
                
                <!-- Display initial attachments if any -->
                <?php if (!empty($initialAttachments)): ?>
                    <div class="initial-attachments">
                        <div class="initial-attachments-title">
                            <i class="bi bi-paperclip"></i>
                            Initial Attachments
                        </div>
                        <div class="attachment-list">
                            <?php foreach ($initialAttachments as $att):
                                $fileInfo = getFileIcon($att['file_name']);
                                $filePath = "/members/attachments/{$ticket['id']}/" . urlencode($att['file_name']);
                            ?>
                                <a href="<?= $filePath ?>" target="_blank" class="attachment-item">
                                    <i class="<?= $fileInfo['icon'] ?> attachment-icon" style="color: <?= $fileInfo['color'] ?>"></i>
                                    <span><?= htmlspecialchars($att['original_name']); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Replies Section -->
            <?php if (!empty($all_replies)): ?>
            <div class="replies-section">
                <?php foreach ($all_replies as $reply): ?>
                    <?php 
                    $isStaff = in_array($reply['role'], ['administrator', 'support_user', 'support_technician', 'accountant']);
                    $isPrivate = $reply['is_private'];
                    $replyClass = $isPrivate ? 'private' : ($isStaff ? 'staff' : 'customer');
                    $avatarClass = $isPrivate ? 'private' : ($isStaff ? 'staff' : 'customer');
                    ?>
                    <div class="reply-item <?= $replyClass ?>">
                        <div class="reply-avatar <?= $avatarClass ?>">
                            <?= strtoupper(substr($reply['username'], 0, 2)); ?>
                        </div>
                        <div class="reply-content">
                            <div class="reply-header">
                                <div class="reply-author">
                                    <?= htmlspecialchars($reply['username']); ?>
                                    <?php if ($isStaff): ?>
                                        <span class="badge bg-primary ms-2">Staff</span>
                                    <?php else: ?>
                                        <span class="badge bg-success ms-2">Customer</span>
                                    <?php endif; ?>
                                    <?php if (!empty($reply['email_imported_at'])): ?>
                                        <span class="badge bg-info ms-2" title="Imported from email at <?= date('M j, Y g:i A', strtotime($reply['email_imported_at'])); ?>">
                                            <i class="bi bi-envelope-fill"></i> Email Import
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="reply-time">
                                    <?= date('M j, Y g:i A', strtotime($reply['created_at'])); ?> • <?= timeAgo($reply['created_at']); ?>
                                    <?php if (!empty($reply['email_imported_at'])): ?>
                                        <br><small style="color: #06B6D4; font-weight: 600;">
                                            <i class="bi bi-inbox"></i> Imported <?= timeAgo($reply['email_imported_at']); ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="reply-message">
                                <?= $reply['message']; ?>
                            </div>
                            
                            <?php if (!empty($attachmentsByReply[$reply['id']])): ?>
                                <div class="attachment-list">
                                    <?php foreach ($attachmentsByReply[$reply['id']] as $att):
                                        $fileInfo = getFileIcon($att['file_name']);
                                        $filePath = "/members/attachments/{$ticket['id']}/" . urlencode($att['file_name']);
                                    ?>
                                        <a href="<?= $filePath ?>" target="_blank" class="attachment-item">
                                            <i class="<?= $fileInfo['icon'] ?> attachment-icon" style="color: <?= $fileInfo['color'] ?>"></i>
                                            <span><?= htmlspecialchars($att['original_name']); ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Reply Form -->
            <?php if ($ticket['status'] !== 'Closed'): ?>
            <div class="reply-form">
                <form id="replyForm" enctype="multipart/form-data">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <label style="font-size: 0.875rem; color: #64748B; font-weight: 500;">Your Reply</label>
                        <button type="button" class="btn-action" id="aiSuggestBtn" onclick="generateAIReply()" style="padding: 0.4rem 0.75rem; font-size: 0.8rem;">
                            <i class="bi bi-robot"></i>
                            AI Suggest Reply
                        </button>
                    </div>
                    <div id="replyEditor"></div>
                    <input type="hidden" name="reply_content" id="replyContent">
                    <div class="reply-actions">
                        <div class="reply-options">
                            <div class="checkbox-wrapper">
                                <input type="checkbox" name="is_private" id="isPrivate">
                                <label for="isPrivate">Private reply (only visible to staff)</label>
                            </div>
                            <label for="fileInput" class="file-upload-btn">
                                <i class="bi bi-paperclip"></i>
                                <span id="fileLabel">Attach files</span>
                            </label>
                            <input type="file" name="attachment[]" multiple class="file-input" id="fileInput">
                        </div>
                        <button type="submit" class="btn-action primary">
                            <i class="bi bi-send"></i>
                            Reply
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="sidebar">
        <!-- Details Section -->
        <div class="sidebar-section">
            <div class="sidebar-title">Details</div>
            <div class="sidebar-item">
                <span class="sidebar-label">
                    <i class="bi bi-hash"></i>
                    Ticket ID
                </span>
                <span class="sidebar-value">#<?= htmlspecialchars($ticket['id']); ?></span>
            </div>
            <div class="sidebar-item">
                <span class="sidebar-label">
                    <i class="bi bi-flag"></i>
                    Status
                </span>
                <select class="dropdown-select" id="statusSelect">
                    <option value="Open" <?= $ticket['status'] === 'Open' ? 'selected' : '' ?>>Open</option>
                    <option value="In Progress" <?= $ticket['status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="Awaiting Member Reply" <?= $ticket['status'] === 'Awaiting Member Reply' ? 'selected' : '' ?>>Awaiting Member Reply</option>
                    <option value="On Hold" <?= $ticket['status'] === 'On Hold' ? 'selected' : '' ?>>On Hold</option>
                    <option value="Pending Third Party" <?= $ticket['status'] === 'Pending Third Party' ? 'selected' : '' ?>>Pending Third Party</option>
                    <option value="Pending" <?= $ticket['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Closed" <?= $ticket['status'] === 'Closed' ? 'selected' : '' ?>>Closed</option>
                </select>
            </div>
            <div class="sidebar-item">
                <span class="sidebar-label">
                    <i class="bi bi-exclamation-triangle"></i>
                    Priority
                </span>
                <select class="dropdown-select" id="prioritySelect">
                    <option value="Low" <?= $ticket['priority'] === 'Low' ? 'selected' : '' ?>>Low</option>
                    <option value="Normal" <?= ($ticket['priority'] === 'Normal' || !$ticket['priority']) ? 'selected' : '' ?>>Normal</option>
                    <option value="Medium" <?= $ticket['priority'] === 'Medium' ? 'selected' : '' ?>>Medium</option>
                    <option value="High" <?= $ticket['priority'] === 'High' ? 'selected' : '' ?>>High</option>
                </select>
            </div>
            <div class="sidebar-item">
                <span class="sidebar-label">
                    <i class="bi bi-collection"></i>
                    Category
                </span>
                <select class="dropdown-select" id="categorySelect">
                    <option value="">No Category</option>
                    <?php foreach ($ticket_groups as $group): ?>
                        <option value="<?= $group['id'] ?>" <?= $ticket['group_id'] == $group['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($group['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sidebar-item">
                <span class="sidebar-label">
                    <i class="bi bi-person"></i>
                    From
                </span>
                <select class="dropdown-select" id="customerSelect">
                    <?php foreach ($all_users as $customer): ?>
                        <option value="<?= $customer['id'] ?>" <?= $ticket['user_id'] == $customer['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($customer['username']) ?> (<?= htmlspecialchars($customer['email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sidebar-item">
                <span class="sidebar-label">
                    <i class="bi bi-building"></i>
                    Company
                </span>
                <span class="sidebar-value"><?= htmlspecialchars($ticket['company_name'] ?? 'None'); ?></span>
            </div>
            <div class="sidebar-item">
                <span class="sidebar-label">
                    <i class="bi bi-person-check"></i>
                    Assigned to
                </span>
                <select class="dropdown-select" id="assignmentSelect">
                    <option value="">Unassigned</option>
                    <?php foreach ($staff_users as $staff): ?>
                        <option value="<?= $staff['id'] ?>" <?= $ticket['assigned_to'] == $staff['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($staff['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sidebar-item">
                <span class="sidebar-label">
                    <i class="bi bi-calendar"></i>
                    Date
                </span>
                <span class="sidebar-value"><?= date('M j, Y g:i A', strtotime($ticket['created_at'])); ?></span>
            </div>
            
            <!-- Save Changes Button -->
            <div class="sidebar-item" style="margin-top: 1rem;">
                <button class="btn-action primary" id="saveChangesBtn" onclick="saveAllChanges()" style="width: 100%;">
                    <i class="bi bi-check"></i>
                    Save Changes
                </button>
            </div>
        </div>

        <!-- Activity Log Section -->
        <div class="sidebar-section">
            <div class="sidebar-title">Activity Log</div>
            <div class="activity-log">
                <?php if (!empty($activity_log)): ?>
                    <?php foreach ($activity_log as $activity): 
                        $activityIcon = getActivityIcon($activity['action_type']);
                    ?>
                        <div class="activity-item">
                            <div class="activity-icon" style="background-color: <?= $activityIcon['color']; ?>">
                                <i class="bi <?= $activityIcon['icon']; ?>"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-description">
                                    <?= htmlspecialchars($activity['details'] ?: $activity['action_type']); ?>
                                </div>
                                <div class="activity-meta">
                                    <?= htmlspecialchars($activity['username']); ?> • <?= timeAgo($activity['created_at']); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted" style="font-size: 0.875rem; text-align: center; padding: 1rem;">
                        No activity yet
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Private Notes Section -->
        <div class="sidebar-section">
            <div class="sidebar-title">Private Notes</div>
            <div class="private-notes">
                <?php if (!empty($private_notes)): ?>
                    <?php foreach ($private_notes as $note): ?>
                        <div class="private-note-item">
                            <div class="private-note-header">
                                <div class="private-note-author"><?= htmlspecialchars($note['username']); ?></div>
                                <div class="private-note-time"><?= timeAgo($note['created_at']); ?></div>
                            </div>
                            <div class="private-note-content" onclick="editNote(<?= $note['id'] ?>, this)" data-note-id="<?= $note['id'] ?>">
                                <?= nl2br(htmlspecialchars($note['message'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted" style="font-size: 0.875rem; text-align: center; padding: 1rem;">
                        No private notes yet
                    </div>
                <?php endif; ?>
                
                <div class="private-note-form">
                    <input type="text" class="private-note-input" id="newNoteInput" placeholder="Add a private note...">
                    <button class="btn-add-note" onclick="addPrivateNote()">
                        <i class="bi bi-plus"></i>
                        Add
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div class="toast" id="toast"></div>

<!-- Quill Rich Text Editor JS -->
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>

<script>
let editingNote = null;
let quill = null;

function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = `toast ${type}`;
    toast.style.display = 'block';
    setTimeout(() => { toast.style.display = 'none'; }, 4000);
}

function autoSave(selectElement, actionType, updateFunction) {
    selectElement.disabled = true;
    selectElement.style.opacity = '0.7';
    const originalValue = selectElement.dataset.originalValue;
    const newValue = selectElement.value;
    if (originalValue === newValue) {
        selectElement.disabled = false;
        selectElement.style.opacity = '1';
        return;
    }
    updateFunction(newValue).then(() => {
        selectElement.dataset.originalValue = newValue;
        selectElement.disabled = false;
        selectElement.style.opacity = '1';
    }).catch(() => {
        selectElement.value = originalValue;
        selectElement.disabled = false;
        selectElement.style.opacity = '1';
    });
}

function updateTicketStatus(status) {
    return new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('action', 'update_ticket_status');
        formData.append('status', status);
        fetch(window.location.href, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(`Status updated to ${status}`, 'success');
                resolve(data);
                setTimeout(() => location.reload(), 800);
            } else {
                showToast(data.message, 'error');
                reject(data);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to update status', 'error');
            reject(error);
        });
    });
}

function updateTicketPriority(priority) {
    return new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('action', 'update_ticket_priority');
        formData.append('priority', priority);
        fetch(window.location.href, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(`Priority updated to ${priority}`, 'success');
                resolve(data);
            } else {
                showToast(data.message, 'error');
                reject(data);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to update priority', 'error');
            reject(error);
        });
    });
}

function updateTicketCategory(groupId) {
    return new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('action', 'update_ticket_category');
        formData.append('group_id', groupId);
        fetch(window.location.href, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const categoryName = document.querySelector(`#categorySelect option[value="${groupId}"]`).textContent;
                showToast(`Category updated to ${categoryName}`, 'success');
                resolve(data);
            } else {
                showToast(data.message, 'error');
                reject(data);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to update category', 'error');
            reject(error);
        });
    });
}

function updateTicketCustomer(userId) {
    return new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('action', 'update_ticket_customer');
        formData.append('user_id', userId);
        fetch(window.location.href, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const customerName = document.querySelector(`#customerSelect option[value="${userId}"]`).textContent;
                showToast(`Customer updated to ${customerName}`, 'success');
                resolve(data);
                setTimeout(() => location.reload(), 800);
            } else {
                showToast(data.message, 'error');
                reject(data);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to update customer', 'error');
            reject(error);
        });
    });
}

function updateTicketAssignment(assignedTo) {
    return new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('action', 'update_ticket_assignment');
        formData.append('assigned_to', assignedTo);
        fetch(window.location.href, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const assigneeName = assignedTo ? document.querySelector(`#assignmentSelect option[value="${assignedTo}"]`).textContent : 'Unassigned';
                showToast(`Assignment updated to ${assigneeName}`, 'success');
                resolve(data);
            } else {
                showToast(data.message, 'error');
                reject(data);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to update assignment', 'error');
            reject(error);
        });
    });
}

function saveAllChanges() {
    const saveBtn = document.getElementById('saveChangesBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
    
    const dropdowns = [
        { element: document.getElementById('statusSelect'), func: updateTicketStatus },
        { element: document.getElementById('prioritySelect'), func: updateTicketPriority },
        { element: document.getElementById('categorySelect'), func: updateTicketCategory },
        { element: document.getElementById('customerSelect'), func: updateTicketCustomer },
        { element: document.getElementById('assignmentSelect'), func: updateTicketAssignment }
    ];
    
    let promises = [];
    dropdowns.forEach(({ element, func }) => {
        const originalValue = element.dataset.originalValue;
        const newValue = element.value;
        if (originalValue !== newValue) {
            promises.push(func(newValue));
        }
    });
    
    if (promises.length === 0) {
        showToast('No changes to save', 'info');
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check"></i> Save Changes';
        return;
    }
    
    Promise.all(promises).then(() => {
        showToast('All changes saved successfully', 'success');
        dropdowns.forEach(({ element }) => {
            element.dataset.originalValue = element.value;
        });
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check"></i> Save Changes';
    }).catch(() => {
        showToast('Some changes failed to save', 'error');
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check"></i> Save Changes';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // Initialize Quill Editor
    const editorElement = document.getElementById('replyEditor');
    if (editorElement) {
        quill = new Quill('#replyEditor', {
            theme: 'snow',
            placeholder: 'Type your reply here...',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    [{ 'header': [1, 2, 3, false] }],
                    ['link'],
                    ['clean']
                ]
            }
        });
    }

    const statusSelect = document.getElementById('statusSelect');
    const prioritySelect = document.getElementById('prioritySelect');
    const categorySelect = document.getElementById('categorySelect');
    const customerSelect = document.getElementById('customerSelect');
    const assignmentSelect = document.getElementById('assignmentSelect');
    
    if (statusSelect) {
        statusSelect.dataset.originalValue = statusSelect.value;
        statusSelect.addEventListener('change', function() {
            autoSave(this, 'status', updateTicketStatus);
        });
    }
    
    if (prioritySelect) {
        prioritySelect.dataset.originalValue = prioritySelect.value;
        prioritySelect.addEventListener('change', function() {
            autoSave(this, 'priority', updateTicketPriority);
        });
    }
    
    if (categorySelect) {
        categorySelect.dataset.originalValue = categorySelect.value;
        categorySelect.addEventListener('change', function() {
            autoSave(this, 'category', updateTicketCategory);
        });
    }
    
    if (customerSelect) {
        customerSelect.dataset.originalValue = customerSelect.value;
        customerSelect.addEventListener('change', function() {
            autoSave(this, 'customer', updateTicketCustomer);
        });
    }
    
    if (assignmentSelect) {
        assignmentSelect.dataset.originalValue = assignmentSelect.value;
        assignmentSelect.addEventListener('change', function() {
            autoSave(this, 'assignment', updateTicketAssignment);
        });
    }
    
    const replyForm = document.getElementById('replyForm');
    if (replyForm) {
        replyForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // Get HTML content from Quill editor
            if (quill) {
                const htmlContent = quill.root.innerHTML;
                document.getElementById('replyContent').value = htmlContent;

                // Check if empty (Quill adds <p><br></p> for empty content)
                const textContent = quill.getText().trim();
                if (!textContent) {
                    showToast('Reply content cannot be empty', 'error');
                    return;
                }
            }

            const formData = new FormData(this);
            formData.append('action', 'add_reply');
            fetch(window.location.href, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to add reply', 'error');
            });
        });
    }
    
    const fileInput = document.getElementById('fileInput');
    const fileLabel = document.getElementById('fileLabel');
    if (fileInput && fileLabel) {
        fileInput.addEventListener('change', function() {
            const fileCount = this.files.length;
            fileLabel.textContent = fileCount > 0 ? `${fileCount} file${fileCount > 1 ? 's' : ''} selected` : 'Attach files';
        });
    }
});

function addPrivateNote() {
    const noteInput = document.getElementById('newNoteInput');
    const noteContent = noteInput.value.trim();
    if (!noteContent) {
        showToast('Please enter a note', 'error');
        return;
    }
    const formData = new FormData();
    formData.append('action', 'add_private_note');
    formData.append('note_content', noteContent);
    fetch(window.location.href, { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            noteInput.value = '';
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to add private note', 'error');
    });
}

function editNote(noteId, element) {
    if (editingNote === noteId) return;
    if (editingNote) {
        const currentEditingElement = document.querySelector(`[data-note-id="${editingNote}"]`);
        if (currentEditingElement) saveNote(editingNote, currentEditingElement);
    }
    editingNote = noteId;
    const originalContent = element.innerHTML;
    const textContent = element.textContent || element.innerText;
    element.classList.add('editing');
    element.innerHTML = `<textarea class="private-note-textarea">${textContent}</textarea>`;
    const textarea = element.querySelector('.private-note-textarea');
    textarea.focus();
    textarea.select();
    textarea.addEventListener('blur', () => saveNote(noteId, element));
    textarea.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            saveNote(noteId, element);
        }
        if (e.key === 'Escape') {
            element.classList.remove('editing');
            element.innerHTML = originalContent;
            editingNote = null;
        }
    });
}

function saveNote(noteId, element) {
    const textarea = element.querySelector('.private-note-textarea');
    if (!textarea) return;
    const newContent = textarea.value.trim();
    if (!newContent) {
        showToast('Note cannot be empty', 'error');
        return;
    }
    const formData = new FormData();
    formData.append('action', 'update_private_note');
    formData.append('note_id', noteId);
    formData.append('note_content', newContent);
    fetch(window.location.href, { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            element.classList.remove('editing');
            element.innerHTML = newContent.replace(/\n/g, '<br>');
            editingNote = null;
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to update note', 'error');
    });
}

function generateAIReply() {
    const aiBtn = document.getElementById('aiSuggestBtn');

    // Disable button and show loading state
    aiBtn.disabled = true;
    const originalHTML = aiBtn.innerHTML;
    aiBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating...';

    // Show info toast
    showToast('AI is analyzing the ticket and generating a reply...', 'info');

    const formData = new FormData();
    formData.append('action', 'generate_ai_reply');

    fetch(window.location.href, { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Convert plain text to HTML paragraphs and insert into Quill
            if (quill) {
                const htmlContent = data.reply.split('\n\n').map(para => `<p>${para.replace(/\n/g, '<br>')}</p>`).join('');
                quill.root.innerHTML = htmlContent;
                quill.focus();
            }
            showToast('AI reply generated successfully! You can edit it before sending.', 'success');
        } else {
            showToast(data.error || 'Failed to generate AI reply', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to generate AI reply', 'error');
    })
    .finally(() => {
        // Re-enable button
        aiBtn.disabled = false;
        aiBtn.innerHTML = originalHTML;
    });
}
</script>
</body>
</html>