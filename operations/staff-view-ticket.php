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
                        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/members/attachments/' . $ticket_id . '/';
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

            case 'update_ticket_field':
                $field = $_POST['field'] ?? null;
                $value = $_POST['value'] ?? null;

                if ($field && $value !== null) {
                    // Only allow updating subject
                    if ($field === 'subject') {
                        // Get old value
                        $stmt = $pdo->prepare("SELECT subject FROM support_tickets WHERE id = ?");
                        $stmt->execute([$ticket_id]);
                        $old_subject = $stmt->fetchColumn();

                        $stmt = $pdo->prepare("UPDATE support_tickets SET subject = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                        if ($stmt->execute([trim($value), $user_id, $ticket_id])) {
                            logActivity($pdo, $ticket_id, $user_id, 'subject_change', $old_subject, trim($value), "Subject updated");
                            echo json_encode(['success' => true, 'message' => 'Subject updated successfully']);
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Failed to update subject']);
                        }
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Invalid field']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
                }
                exit;

            case 'update_ticket_details':
                $details = $_POST['details'] ?? null;

                if ($details !== null) {
                    // Get old value
                    $stmt = $pdo->prepare("SELECT details FROM support_tickets WHERE id = ?");
                    $stmt->execute([$ticket_id]);
                    $old_details = $stmt->fetchColumn();

                    $stmt = $pdo->prepare("UPDATE support_tickets SET details = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                    if ($stmt->execute([trim($details), $user_id, $ticket_id])) {
                        // Truncate for display in activity log
                        $old_truncated = strlen($old_details) > 100 ? substr($old_details, 0, 100) . '...' : $old_details;
                        $new_truncated = strlen($details) > 100 ? substr($details, 0, 100) . '...' : $details;
                        logActivity($pdo, $ticket_id, $user_id, 'details_update', $old_truncated, $new_truncated, "Ticket details updated");
                        echo json_encode(['success' => true, 'message' => 'Details updated successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to update details']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Missing details']);
                }
                exit;

            case 'update_reply':
                $reply_id = $_POST['reply_id'] ?? null;
                $is_private = isset($_POST['is_private']) && $_POST['is_private'] == '1';
                $message = $_POST['message'] ?? null;

                if ($reply_id && $message !== null) {
                    // Get old message first
                    if ($is_private) {
                        $stmt = $pdo->prepare("SELECT message FROM support_ticket_responses WHERE id = ? AND ticket_id = ?");
                        $stmt->execute([$reply_id, $ticket_id]);
                        $old_message = $stmt->fetchColumn();

                        // Update in support_ticket_responses
                        $stmt = $pdo->prepare("UPDATE support_ticket_responses SET message = ? WHERE id = ? AND ticket_id = ?");
                        $success = $stmt->execute([trim($message), $reply_id, $ticket_id]);
                    } else {
                        $stmt = $pdo->prepare("SELECT message FROM support_ticket_replies WHERE id = ? AND ticket_id = ?");
                        $stmt->execute([$reply_id, $ticket_id]);
                        $old_message = $stmt->fetchColumn();

                        // Update in support_ticket_replies
                        $stmt = $pdo->prepare("UPDATE support_ticket_replies SET message = ? WHERE id = ? AND ticket_id = ?");
                        $success = $stmt->execute([trim($message), $reply_id, $ticket_id]);
                    }

                    if ($success && $old_message) {
                        // Strip HTML tags and truncate for activity log
                        $old_text = strip_tags($old_message);
                        $new_text = strip_tags($message);
                        $old_truncated = strlen($old_text) > 150 ? substr($old_text, 0, 150) . '...' : $old_text;
                        $new_truncated = strlen($new_text) > 150 ? substr($new_text, 0, 150) . '...' : $new_text;

                        logActivity($pdo, $ticket_id, $user_id, 'reply_edit', $old_truncated, $new_truncated, "Edited a " . ($is_private ? "private" : "public") . " reply");
                        echo json_encode(['success' => true, 'message' => 'Reply updated successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to update reply']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
                }
                exit;

            case 'delete_reply':
                $reply_id = $_POST['reply_id'] ?? null;
                $is_private = isset($_POST['is_private']) && $_POST['is_private'] == '1';

                if ($reply_id) {
                    if ($is_private) {
                        // Delete from support_ticket_responses
                        $stmt = $pdo->prepare("DELETE FROM support_ticket_responses WHERE id = ? AND ticket_id = ?");
                        $success = $stmt->execute([$reply_id, $ticket_id]);
                    } else {
                        // Delete from support_ticket_replies
                        $stmt = $pdo->prepare("DELETE FROM support_ticket_replies WHERE id = ? AND ticket_id = ?");
                        $success = $stmt->execute([$reply_id, $ticket_id]);
                    }

                    if ($success) {
                        logActivity($pdo, $ticket_id, $user_id, 'reply_delete', null, null, "Deleted a " . ($is_private ? "private" : "public") . " reply");
                        echo json_encode(['success' => true, 'message' => 'Reply deleted successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to delete reply']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Missing reply ID']);
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

$page_title = "Ticket #" . $ticket['id'] . " - " . htmlspecialchars($ticket['subject']) . " | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<!-- Quill Rich Text Editor CSS -->
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">

<style>
    :root {
        --primary-color: #667eea;
        --primary-hover: #5568d3;
        --success-color: #10B981;
        --warning-color: #F59E0B;
        --danger-color: #EF4444;
        --info-color: #06B6D4;
        --border-radius: 12px;
        --card-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    body {
        background: #f8fafc;
    }

    .ticket-view-container {
        max-width: 1600px;
        margin: 0 auto;
        padding: 2rem 1rem;
        display: grid;
        grid-template-columns: 1fr 380px;
        gap: 1.5rem;
    }

    .ticket-main-content {
        min-width: 0;
    }

    .ticket-card {
        background: white;
        border-radius: var(--border-radius);
        padding: 2rem;
        box-shadow: var(--card-shadow);
        margin-bottom: 1.5rem;
    }

    .ticket-sidebar {
        position: sticky;
        top: 100px;
        align-self: start;
    }

    .sidebar-card {
        background: white;
        border-radius: var(--border-radius);
        padding: 1.5rem;
        box-shadow: var(--card-shadow);
        margin-bottom: 1.5rem;
    }

    .sidebar-card h3 {
        font-size: 0.875rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 1.25rem;
        letter-spacing: 0.5px;
    }

    .ticket-header-section {
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid #f1f5f9;
    }

    .ticket-title-area h1 {
        font-size: 1.75rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 0.75rem;
    }

    .ticket-meta-badges {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        align-items: center;
    }

    .status-badge {
        padding: 0.5rem 1rem;
        border-radius: 8px;
        font-size: 0.875rem;
        font-weight: 600;
        text-transform: capitalize;
    }

    .status-open { background: #dbeafe; color: #1e40af; }
    .status-in-progress { background: #fef3c7; color: #92400e; }
    .status-closed { background: #d1fae5; color: #065f46; }
    .status-pending { background: #fef3c7; color: #92400e; }

    .priority-badge {
        padding: 0.5rem 1rem;
        border-radius: 8px;
        font-size: 0.875rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
    }

    .priority-high { background: #fee2e2; color: #991b1b; }
    .priority-medium { background: #fef3c7; color: #92400e; }
    .priority-low { background: #e0e7ff; color: #3730a3; }

    .quick-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .btn-action {
        padding: 0.625rem 1.25rem;
        border-radius: 8px;
        border: none;
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
    }

    .btn-action.primary {
        background: var(--primary-color);
        color: white;
    }

    .btn-action.primary:hover {
        background: var(--primary-hover);
        transform: translateY(-1px);
    }

    .btn-action.success {
        background: var(--success-color);
        color: white;
    }

    .btn-action.success:hover {
        background: #059669;
    }

    .btn-action.warning {
        background: var(--warning-color);
        color: white;
    }

    .btn-action.warning:hover {
        background: #d97706;
    }

    .btn-action.danger {
        background: var(--danger-color);
        color: white;
    }

    .btn-action.danger:hover {
        background: #dc2626;
    }

    .btn-action.info {
        background: var(--info-color);
        color: white;
    }

    .btn-action.info:hover {
        background: #0891b2;
    }

    .btn-action.secondary {
        background: #f3f4f6;
        color: #374151;
        border: 1px solid #d1d5db;
    }

    .btn-action.secondary:hover {
        background: #e5e7eb;
    }

    .ticket-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .info-item {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        padding: 0.75rem 0;
        border-bottom: 1px solid #f1f5f9;
    }

    .info-item:last-child {
        border-bottom: none;
    }

    .info-label {
        font-size: 0.75rem;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .info-value {
        font-size: 0.9375rem;
        color: #1e293b;
        font-weight: 500;
    }

    :root.dark .info-item {
        border-bottom-color: #334155;
    }

    :root.dark .sidebar-card h3 {
        color: #f1f5f9;
    }

    :root.dark .sidebar-card {
        border: 1px solid #334155;
    }

    /* Dark mode activity log */
    :root.dark .activity-item {
        border-bottom-color: #334155;
    }

    :root.dark .activity-item .activity-description {
        color: #e2e8f0;
    }

    :root.dark .activity-item .activity-meta {
        color: #94a3b8;
    }

    /* Dark mode private notes */
    :root.dark .private-note-item {
        background: #334155;
        border-color: #475569;
    }

    :root.dark .private-note-header {
        color: #f1f5f9;
    }

    :root.dark .private-note-content {
        color: #e2e8f0;
    }

    /* Activity Log Container */
    .activity-log-container {
        max-height: 400px;
        overflow-y: auto;
    }

    .activity-icon-wrapper {
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

    .activity-content-wrapper {
        flex: 1;
        min-width: 0;
    }

    .activity-item {
        display: flex;
        gap: 0.75rem;
        padding: 0.75rem 0;
        border-bottom: 1px solid #f1f5f9;
    }

    .activity-item:last-child {
        border-bottom: none;
    }

    .activity-description {
        font-size: 0.875rem;
        color: #334155;
        margin-bottom: 0.25rem;
    }

    .activity-meta {
        font-size: 0.75rem;
        color: #64748b;
    }

    /* Activity expandable */
    .activity-expandable {
        cursor: pointer;
    }

    .activity-expandable:hover {
        background: #f8fafc;
    }

    .activity-chevron {
        font-size: 0.75rem;
        margin-left: 0.5rem;
        transition: transform 0.2s;
    }

    .activity-item.expanded .activity-chevron {
        transform: rotate(180deg);
    }

    .activity-details {
        margin-top: 0.75rem;
        padding: 0.75rem;
        background: #f8fafc;
        border-radius: 6px;
        border-left: 3px solid #667eea;
    }

    .activity-change {
        margin-bottom: 0.5rem;
        font-size: 0.8125rem;
    }

    .activity-change:last-child {
        margin-bottom: 0;
    }

    .change-label {
        font-weight: 600;
        color: #64748b;
        margin-right: 0.5rem;
    }

    .change-value {
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-family: monospace;
        font-size: 0.8125rem;
    }

    .old-value {
        background: #fee2e2;
        color: #991b1b;
    }

    .new-value {
        background: #dcfce7;
        color: #166534;
    }

    :root.dark .activity-expandable:hover {
        background: #1e293b;
    }

    :root.dark .activity-details {
        background: #1e293b;
        border-left-color: #a78bfa;
    }

    :root.dark .old-value {
        background: #450a0a;
        color: #fca5a5;
    }

    :root.dark .new-value {
        background: #052e16;
        color: #86efac;
    }

    /* Private Notes Container */
    .private-notes-container {
        max-height: 300px;
        overflow-y: auto;
        margin-bottom: 1rem;
    }

    .private-note-item {
        padding: 0.75rem;
        background: #fef3c7;
        border-left: 3px solid #f59e0b;
        border-radius: 8px;
        margin-bottom: 0.75rem;
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
        font-size: 0.875rem;
        color: #1e293b;
    }

    .private-note-time {
        font-size: 0.75rem;
        color: #64748b;
    }

    .private-note-content {
        font-size: 0.875rem;
        color: #334155;
        cursor: pointer;
        padding: 0.5rem;
        border-radius: 4px;
        transition: background 0.2s;
        line-height: 1.5;
    }

    .private-note-content:hover {
        background: rgba(0, 0, 0, 0.05);
    }

    .private-note-content p {
        margin: 0 0 0.5rem 0;
    }

    .private-note-content p:last-child {
        margin-bottom: 0;
    }

    .private-note-content ul,
    .private-note-content ol {
        margin: 0 0 0.5rem 1.25rem;
        padding: 0;
    }

    .private-note-content li {
        margin-bottom: 0.25rem;
    }

    .private-note-form {
        display: flex;
        gap: 0.5rem;
    }

    .private-note-form input {
        flex: 1;
    }

    .empty-state {
        text-align: center;
        padding: 1.5rem;
        color: #94a3b8;
        font-size: 0.875rem;
    }

    .empty-state i {
        font-size: 2rem;
        display: block;
        margin-bottom: 0.5rem;
    }

    /* Dark mode updates for new classes */
    :root.dark .private-note-item {
        background: #422006;
        border-left-color: #f59e0b;
    }

    :root.dark .private-note-author {
        color: #fef3c7;
    }

    :root.dark .private-note-time {
        color: #94a3b8;
    }

    :root.dark .private-note-content {
        color: #fde68a;
    }

    :root.dark .private-note-content:hover {
        background: rgba(251, 191, 36, 0.1);
    }

    :root.dark .private-note-content p,
    :root.dark .private-note-content ul,
    :root.dark .private-note-content ol,
    :root.dark .private-note-content li {
        color: #fde68a;
    }

    :root.dark .empty-state {
        color: #64748b;
    }

    .ticket-messages {
        margin-top: 2rem;
    }

    .message-item {
        background: #f8fafc;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 1rem;
        border-left: 4px solid #e2e8f0;
    }

    .message-item.staff-message {
        background: #eff6ff;
        border-left-color: var(--primary-color);
    }

    .message-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }

    .message-author {
        font-weight: 600;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .message-time {
        font-size: 0.875rem;
        color: #64748b;
    }

    .message-content {
        color: #334155;
        line-height: 1.6;
    }

    /* Dark mode support */
    :root.dark body {
        background: #0f172a;
    }

    :root.dark .ticket-card {
        background: #1e293b;
        border: 1px solid #334155;
    }

    .ticket-card {
        border: 1px solid #e2e8f0;
    }

    /* Initial Message Styling */
    .initial-message-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid #e2e8f0;
    }

    .initial-message-label {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .initial-request-badge {
        font-size: 1.125rem;
        font-weight: 700;
        color: #3b82f6;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .initial-message-from,
    .initial-message-subject {
        margin-bottom: 0.75rem;
        font-size: 0.9375rem;
        color: #475569;
    }

    .initial-message-from strong,
    .initial-message-subject strong {
        color: #1e293b;
        margin-right: 0.5rem;
    }

    .editable-title {
        cursor: pointer;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        transition: all 0.2s;
        display: inline-block;
    }

    .editable-title:hover {
        background: #f1f5f9;
        color: #667eea;
    }

    .editable-title i {
        transition: opacity 0.2s;
    }

    .editable-title:hover i {
        opacity: 1 !important;
    }

    .editable-message-content {
        position: relative;
        padding: 1rem;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
        line-height: 1.6;
        color: #1e293b;
        min-height: 60px;
    }

    .editable-message-content:hover {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        padding: calc(1rem - 1px);
    }

    .edit-hint {
        display: none;
        position: absolute;
        top: 0.5rem;
        right: 0.5rem;
        font-size: 0.75rem;
        color: #94a3b8;
        background: white;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    .editable-message-content:hover .edit-hint {
        display: block;
    }

    .initial-message-divider {
        margin: 1rem 0;
        border-color: #e2e8f0;
    }

    /* Dark mode for initial message */
    :root.dark .initial-message-divider {
        border-color: #334155;
    }
    :root.dark .initial-message-header {
        border-bottom-color: #334155;
    }

    :root.dark .initial-request-badge {
        color: #60a5fa;
    }

    :root.dark .initial-message-from,
    :root.dark .initial-message-subject {
        color: #94a3b8;
    }

    :root.dark .initial-message-from strong,
    :root.dark .initial-message-subject strong {
        color: #e2e8f0;
    }

    :root.dark .editable-title:hover {
        background: #334155;
        color: #a78bfa;
    }

    :root.dark .editable-message-content {
        color: #e2e8f0;
    }

    :root.dark .editable-message-content:hover {
        background: #1e293b;
        border-color: #475569;
    }

    :root.dark .edit-hint {
        background: #334155;
        color: #cbd5e1;
    }

    #initial-message-edit {
        padding: 1rem;
        background: #f8fafc;
        border-radius: 8px;
        border: 2px solid #667eea;
    }

    #initial-message-editor {
        min-height: 200px;
        background: white;
    }

    :root.dark #initial-message-edit {
        background: #0f172a;
        border-color: #a78bfa;
    }

    :root.dark #initial-message-editor {
        background: #1e293b;
    }

    :root.dark .ticket-title-area h1 {
        color: #f1f5f9;
    }

    :root.dark .ticket-header-section {
        border-bottom-color: #334155;
    }

    :root.dark .info-label {
        color: #94a3b8;
    }

    :root.dark .info-value {
        color: #e2e8f0;
    }

    :root.dark .message-item {
        background: #0f172a;
        border-left-color: #334155;
    }

    :root.dark .message-item.staff-message {
        background: #1e293b;
        border-left-color: #a78bfa;
    }

    :root.dark .message-author {
        color: #f1f5f9;
    }

    :root.dark .message-content {
        color: #cbd5e1;
    }

    :root.dark .btn-action.secondary {
        background: #334155;
        color: #e2e8f0;
        border-color: #475569;
    }

    :root.dark .btn-action.secondary:hover {
        background: #475569;
    }

    :root.dark .ticket-sidebar .sidebar-card {
        background: #1e293b;
        border-color: #334155;
    }

    :root.dark .sidebar-card h3 {
        color: #94a3b8;
    }

    /* Reply styles */
    .reply-item {
        display: flex;
        gap: 1rem;
        margin-bottom: 1.5rem;
        padding: 1.25rem;
        background: #f8fafc;
        border-radius: 12px;
        border-left: 4px solid #e2e8f0;
    }

    .reply-item.staff {
        background: #eff6ff;
        border-left-color: var(--primary-color);
    }

    .reply-item.private {
        background: #fef3c7;
        border-left-color: #f59e0b;
    }

    .reply-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.875rem;
        flex-shrink: 0;
    }

    .reply-avatar.customer {
        background: linear-gradient(135deg, #10b981, #059669);
    }

    .reply-avatar.private {
        background: linear-gradient(135deg, #f59e0b, #d97706);
    }

    .reply-content {
        flex: 1;
        min-width: 0;
    }

    .reply-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.75rem;
    }

    .reply-author {
        font-weight: 600;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .reply-time {
        font-size: 0.8125rem;
        color: #64748b;
    }

    .reply-message {
        color: #334155;
        line-height: 1.6;
        margin-top: 0.5rem;
    }

    .reply-message p {
        margin-bottom: 0.5rem;
    }

    .reply-message p:last-child {
        margin-bottom: 0;
    }

    /* Reply action buttons */
    .reply-actions-wrapper {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 0.5rem;
    }

    .btn-reply-action {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        color: #64748b;
        font-size: 0.875rem;
    }

    .btn-reply-action:hover {
        background: #f8fafc;
        color: #667eea;
        border-color: #667eea;
    }

    .btn-reply-action.btn-danger:hover {
        background: #fef2f2;
        color: #ef4444;
        border-color: #ef4444;
    }

    .reply-item .reply-actions {
        opacity: 0;
        transition: opacity 0.2s;
    }

    .reply-item:hover .reply-actions {
        opacity: 1;
    }

    .reply-edit-container {
        margin-top: 1rem;
        padding: 1.25rem;
        background: #f8fafc;
        border-radius: 12px;
        border: 2px solid #667eea;
    }

    .reply-editor-wrapper {
        margin-bottom: 1rem;
    }

    .reply-editor-wrapper .ql-container {
        min-height: 150px;
        font-size: 0.9375rem;
    }

    .reply-editor-wrapper .ql-editor {
        min-height: 150px;
        padding: 1rem;
    }

    .reply-edit-actions {
        display: flex;
        gap: 0.75rem;
        justify-content: flex-end;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid #e2e8f0;
    }

    :root.dark .reply-edit-container {
        background: #0f172a;
        border-color: #a78bfa;
    }

    :root.dark .reply-edit-actions {
        border-top-color: #334155;
    }

    .reply-content {
        flex: 1;
        min-width: 0;
    }

    .reply-item {
        margin-bottom: 1.5rem;
    }

    :root.dark .btn-reply-action {
        background: #1e293b;
        border-color: #334155;
        color: #94a3b8;
    }

    :root.dark .btn-reply-action:hover {
        background: #334155;
        color: #a78bfa;
        border-color: #a78bfa;
    }

    :root.dark .btn-reply-action.btn-danger:hover {
        background: #3f1515;
        color: #f87171;
        border-color: #f87171;
    }

    .initial-message {
        padding: 1.5rem;
        background: #f8fafc;
        border-radius: 12px;
        margin-bottom: 2rem;
        border-left: 4px solid #3b82f6;
    }

    .reply-form {
        margin-top: 2rem;
        padding: 1.5rem;
        background: white;
        border-radius: 12px;
        border: 2px solid #e5e7eb;
    }

    .reply-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 1rem;
    }

    .reply-options {
        display: flex;
        gap: 1rem;
        align-items: center;
    }

    .checkbox-wrapper {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .checkbox-wrapper input[type="checkbox"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: var(--primary-color);
    }

    .checkbox-wrapper label {
        margin: 0;
        color: #64748b;
    }

    :root.dark .checkbox-wrapper label {
        color: #94a3b8;
    }

    :root.dark .ticket-card label {
        color: #e2e8f0;
    }

    .file-upload-btn {
        cursor: pointer;
        padding: 0.5rem 1rem;
        background: #f3f4f6;
        border-radius: 8px;
        font-size: 0.875rem;
        transition: all 0.2s;
        margin: 0;
    }

    .file-upload-btn:hover {
        background: #e5e7eb;
    }

    .file-input {
        display: none;
    }

    :root.dark .reply-item {
        background: #0f172a;
        border-left-color: #334155;
    }

    :root.dark .reply-item.staff {
        background: #1e293b;
        border-left-color: #a78bfa;
    }

    :root.dark .reply-item.private {
        background: #422006;
        border-left-color: #f59e0b;
    }

    :root.dark .initial-message {
        background: #0f172a;
        border-left-color: #60a5fa;
    }

    :root.dark .reply-form {
        background: #1e293b;
        border-color: #334155;
    }

    :root.dark .reply-author {
        color: #f1f5f9;
    }

    :root.dark .reply-message {
        color: #cbd5e1;
    }

    :root.dark .file-upload-btn {
        background: #334155;
        color: #e2e8f0;
    }

    :root.dark .file-upload-btn:hover {
        background: #475569;
    }

    /* Attachment styles */
    .initial-attachments-title {
        font-weight: 600;
        color: #64748b;
        margin: 1.5rem 0 0.75rem 0;
        font-size: 0.875rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .attachment-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 1rem;
    }

    .attachment-item {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.75rem 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
        color: #1e293b;
        transition: all 0.2s;
        font-size: 0.875rem;
    }

    .attachment-item:hover {
        background: #f8fafc;
        border-color: var(--primary-color);
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .attachment-icon {
        font-size: 1.25rem;
    }

    :root.dark .attachment-item {
        background: #0f172a;
        border-color: #334155;
        color: #e2e8f0;
    }

    :root.dark .attachment-item:hover {
        background: #1e293b;
        border-color: #a78bfa;
    }

    :root.dark .initial-attachments-title {
        color: #94a3b8;
    }

    /* Quill Editor Base Styles */
    #replyEditor {
        min-height: 150px;
        background: white;
    }

    #replyEditor .ql-editor {
        min-height: 150px;
        font-size: 0.875rem;
        line-height: 1.6;
    }

    .ql-container {
        border-bottom-left-radius: 8px;
        border-bottom-right-radius: 8px;
        border-color: #e2e8f0;
    }

    .ql-toolbar {
        border-top-left-radius: 8px;
        border-top-right-radius: 8px;
        background: #f8fafc;
        border-color: #e2e8f0;
    }

    .ql-toolbar .ql-stroke {
        stroke: #475569;
    }

    .ql-toolbar .ql-fill {
        fill: #475569;
    }

    .ql-toolbar button:hover .ql-stroke,
    .ql-toolbar button.ql-active .ql-stroke {
        stroke: var(--primary-color);
    }

    .ql-toolbar button:hover .ql-fill,
    .ql-toolbar button.ql-active .ql-fill {
        fill: var(--primary-color);
    }

    /* Quill Editor Dark Mode */
    :root.dark .ql-toolbar {
        background: #0f172a;
        border-color: #334155;
    }

    :root.dark .ql-container {
        border-color: #334155;
    }

    :root.dark .ql-editor {
        background: #1e293b;
        color: #e2e8f0;
    }

    :root.dark .ql-editor.ql-blank::before {
        color: #64748b;
    }

    :root.dark .ql-toolbar .ql-stroke {
        stroke: #94a3b8;
    }

    :root.dark .ql-toolbar .ql-fill {
        fill: #94a3b8;
    }

    :root.dark .ql-toolbar button:hover .ql-stroke,
    :root.dark .ql-toolbar button.ql-active .ql-stroke {
        stroke: #a78bfa;
    }

    :root.dark .ql-toolbar button:hover .ql-fill,
    :root.dark .ql-toolbar button.ql-active .ql-fill {
        fill: #a78bfa;
    }

    :root.dark .ql-toolbar .ql-picker-label {
        color: #94a3b8;
    }

    :root.dark .ql-toolbar .ql-picker-options {
        background: #1e293b;
        border-color: #334155;
    }

    :root.dark .ql-toolbar .ql-picker-item:hover {
        color: #a78bfa;
    }

    /* Form controls in sidebar */
    .sidebar-card .form-select {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 0.875rem;
        padding: 0.5rem;
        color: #1e293b;
        transition: all 0.2s;
    }

    .sidebar-card .form-select:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        outline: none;
    }

    .sidebar-card .form-control {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 0.875rem;
        padding: 0.5rem;
        color: #1e293b;
    }

    .sidebar-card .form-control:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        outline: none;
    }

    :root.dark .sidebar-card .form-select,
    :root.dark .sidebar-card .form-control {
        background: #0f172a;
        border-color: #475569;
        color: #e2e8f0;
    }

    :root.dark .sidebar-card .form-select:focus,
    :root.dark .sidebar-card .form-control:focus {
        border-color: #a78bfa;
        box-shadow: 0 0 0 3px rgba(167, 139, 250, 0.1);
    }

    /* Toast notification */
    .toast {
        display: none;
        position: fixed;
        bottom: 2rem;
        right: 2rem;
        background: white;
        color: #1e293b;
        padding: 1rem 1.5rem;
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        z-index: 9999;
        font-size: 0.875rem;
        font-weight: 500;
        border-left: 4px solid var(--success-color);
        animation: slideInUp 0.3s ease;
    }

    .toast.success {
        border-left-color: var(--success-color);
    }

    .toast.error {
        border-left-color: var(--danger-color);
    }

    .toast.info {
        border-left-color: var(--info-color);
    }

    @keyframes slideInUp {
        from {
            transform: translateY(100%);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    :root.dark .toast {
        background: #1e293b;
        color: #f1f5f9;
    }

    @media (max-width: 1024px) {
        .ticket-view-container {
            grid-template-columns: 1fr;
        }

        .ticket-sidebar {
            position: static;
            order: -1;
        }
    }
</style>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="dashboard-hero-content">
            <h1 class="dashboard-hero-title">
                <i class="bi bi-ticket-detailed me-2"></i>
                Ticket #<?= $ticket['id'] ?>
            </h1>
            <p class="dashboard-hero-subtitle">
                <?= htmlspecialchars($ticket['subject']) ?>
            </p>
            <div class="dashboard-hero-actions">
                <a href="/operations/staff-tickets.php" class="btn c-btn-ghost">
                    <i class="bi bi-arrow-left me-1"></i>
                    Back to Tickets
                </a>
                <a href="staff-view-ticket2.php?id=<?= $ticket['id'] ?>" class="btn c-btn-ghost">
                    <i class="bi bi-layout-split me-1"></i>
                    Alternative View
                </a>
            </div>
        </div>
    </div>
</header>

<div class="ticket-view-container">
    <!-- Main Content (Left Column) -->
    <div class="ticket-main-content">
        <div class="ticket-card">
            <!-- Initial Message -->
            <div class="initial-message">
                <div class="initial-message-header">
                    <div class="initial-message-label">
                        <i class="bi bi-envelope-open-fill" style="font-size: 1.25rem; color: #3b82f6;"></i>
                        <span class="initial-request-badge">Initial Request</span>
                    </div>
                    <div class="reply-time">
                        <?= date('M j, Y g:i A', strtotime($ticket['created_at'])); ?>
                    </div>
                </div>
                <div class="initial-message-from">
                    <strong>From:</strong> <?= htmlspecialchars($ticket['customer_name']); ?>
                    <span class="badge bg-success ms-2">Customer</span>
                    <?php if ($ticket['is_guest_ticket'] == 1): ?>
                        <span class="badge bg-warning ms-1">Guest</span>
                    <?php endif; ?>
                </div>
                <div class="initial-message-subject">
                    <strong>Subject:</strong>
                    <span class="editable-title" id="ticket-subject-display" onclick="editTicketSubject()">
                        <?= htmlspecialchars($ticket['subject']); ?>
                        <i class="bi bi-pencil-square ms-1" style="font-size: 0.875rem; opacity: 0.5;"></i>
                    </span>
                    <div id="ticket-subject-edit" style="display: none;">
                        <input type="text" class="form-control form-control-sm d-inline-block" id="ticket-subject-input" value="<?= htmlspecialchars($ticket['subject']); ?>" style="width: auto; min-width: 300px;">
                        <button class="btn btn-sm btn-primary ms-2" onclick="saveTicketSubject()"><i class="bi bi-check"></i> Save</button>
                        <button class="btn btn-sm btn-secondary ms-1" onclick="cancelTicketSubject()"><i class="bi bi-x"></i> Cancel</button>
                    </div>
                </div>
                <hr class="initial-message-divider">

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
                <div id="initial-message-display" class="editable-message-content" onclick="editInitialMessage()">
                    <?= nl2br(htmlspecialchars($ticket['details'] ?? 'No details provided')); ?>
                    <div class="edit-hint"><i class="bi bi-pencil-square"></i> Click to edit</div>
                </div>
                <div id="initial-message-edit" style="display: none;">
                    <div id="initial-message-editor"></div>
                    <div class="reply-edit-actions" style="margin-top: 1rem;">
                        <button class="btn-action primary" onclick="saveInitialMessage()">
                            <i class="bi bi-check"></i> Save
                        </button>
                        <button class="btn-action" onclick="cancelInitialMessage()">
                            <i class="bi bi-x"></i> Cancel
                        </button>
                    </div>
                </div>
                
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
                    <div class="reply-item <?= $replyClass ?>" data-reply-id="<?= $reply['id']; ?>" data-is-private="<?= $isPrivate ? '1' : '0'; ?>">
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
                                <div class="reply-actions-wrapper">
                                    <div class="reply-time">
                                        <?= date('M j, Y g:i A', strtotime($reply['created_at'])); ?> • <?= timeAgo($reply['created_at']); ?>
                                        <?php if (!empty($reply['email_imported_at'])): ?>
                                            <br><small style="color: #06B6D4; font-weight: 600;">
                                                <i class="bi bi-inbox"></i> Imported <?= timeAgo($reply['email_imported_at']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="reply-actions">
                                        <button class="btn-reply-action" onclick="editReply(<?= $reply['id']; ?>, <?= $isPrivate ? '1' : '0'; ?>)" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn-reply-action btn-danger" onclick="deleteReply(<?= $reply['id']; ?>, <?= $isPrivate ? '1' : '0'; ?>)" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="reply-message" id="reply-message-<?= $reply['id']; ?>">
                                <?= $reply['message']; ?>
                            </div>
                            <div class="reply-edit-container" id="reply-edit-<?= $reply['id']; ?>" style="display: none;">
                                <div class="reply-editor-wrapper"></div>
                                <div class="reply-edit-actions">
                                    <button class="btn-action primary" onclick="saveReplyEdit(<?= $reply['id']; ?>, <?= $isPrivate ? '1' : '0'; ?>)">
                                        <i class="bi bi-check"></i> Save
                                    </button>
                                    <button class="btn-action" onclick="cancelReplyEdit(<?= $reply['id']; ?>)">
                                        <i class="bi bi-x"></i> Cancel
                                    </button>
                                </div>
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

            <!-- Reply Form - Always Visible -->
            <?php if ($ticket['status'] !== 'Closed'): ?>
            <div class="ticket-card" style="margin-top: 1.5rem;">
                <h3 style="font-size: 1.125rem; font-weight: 600; color: #1e293b; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
                    <span><i class="bi bi-reply-fill me-2"></i>Add Reply</span>
                    <button type="button" class="btn-action secondary" id="aiSuggestBtn" onclick="generateAIReply()" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                        <i class="bi bi-robot"></i>
                        AI Suggest Reply
                    </button>
                </h3>
                <form id="replyForm" enctype="multipart/form-data">
                    <div id="replyEditor"></div>
                    <input type="hidden" name="reply_content" id="replyContent">
                    <div class="reply-actions">
                        <div class="reply-options">
                            <div class="checkbox-wrapper">
                                <input type="checkbox" name="is_private" id="isPrivate">
                                <label for="isPrivate" style="cursor: pointer; font-size: 0.875rem; user-select: none;">
                                    <i class="bi bi-lock-fill me-1"></i>Private reply (only visible to staff)
                                </label>
                            </div>
                            <label for="fileInput" class="file-upload-btn">
                                <i class="bi bi-paperclip"></i>
                                <span id="fileLabel">Attach files</span>
                            </label>
                            <input type="file" name="attachment[]" multiple class="file-input" id="fileInput">
                        </div>
                        <button type="submit" class="btn-action primary">
                            <i class="bi bi-send-fill"></i>
                            Send Reply
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- End Main Content -->

    <!-- Right Sidebar -->
    <div class="ticket-sidebar">
        <!-- Details Card -->
        <div class="sidebar-card">
            <h3>Ticket Details</h3>

            <div class="info-item">
                <div class="info-label">Ticket ID</div>
                <div class="info-value">#<?= htmlspecialchars($ticket['id']); ?></div>
            </div>

            <div class="info-item">
                <div class="info-label">Status</div>
                <select class="form-select form-select-sm" id="statusSelect">
                    <option value="Open" <?= $ticket['status'] === 'Open' ? 'selected' : '' ?>>Open</option>
                    <option value="In Progress" <?= $ticket['status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="Awaiting Member Reply" <?= $ticket['status'] === 'Awaiting Member Reply' ? 'selected' : '' ?>>Awaiting Member Reply</option>
                    <option value="On Hold" <?= $ticket['status'] === 'On Hold' ? 'selected' : '' ?>>On Hold</option>
                    <option value="Pending Third Party" <?= $ticket['status'] === 'Pending Third Party' ? 'selected' : '' ?>>Pending Third Party</option>
                    <option value="Pending" <?= $ticket['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Closed" <?= $ticket['status'] === 'Closed' ? 'selected' : '' ?>>Closed</option>
                </select>
            </div>

            <div class="info-item">
                <div class="info-label">Priority</div>
                <select class="form-select form-select-sm" id="prioritySelect">
                    <option value="Low" <?= $ticket['priority'] === 'Low' ? 'selected' : '' ?>>Low</option>
                    <option value="Normal" <?= ($ticket['priority'] === 'Normal' || !$ticket['priority']) ? 'selected' : '' ?>>Normal</option>
                    <option value="Medium" <?= $ticket['priority'] === 'Medium' ? 'selected' : '' ?>>Medium</option>
                    <option value="High" <?= $ticket['priority'] === 'High' ? 'selected' : '' ?>>High</option>
                </select>
            </div>

            <div class="info-item">
                <div class="info-label">Category</div>
                <select class="form-select form-select-sm" id="categorySelect">
                    <option value="">No Category</option>
                    <?php foreach ($ticket_groups as $group): ?>
                        <option value="<?= $group['id'] ?>" <?= $ticket['group_id'] == $group['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($group['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="info-item">
                <div class="info-label">Customer</div>
                <select class="form-select form-select-sm" id="customerSelect">
                    <?php foreach ($all_users as $customer): ?>
                        <option value="<?= $customer['id'] ?>" <?= $ticket['user_id'] == $customer['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($customer['username']) ?> (<?= htmlspecialchars($customer['email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="info-item">
                <div class="info-label">Company</div>
                <div class="info-value"><?= htmlspecialchars($ticket['company_name'] ?? 'None'); ?></div>
            </div>

            <div class="info-item">
                <div class="info-label">Assigned To</div>
                <select class="form-select form-select-sm" id="assignmentSelect">
                    <option value="">Unassigned</option>
                    <?php foreach ($staff_users as $staff): ?>
                        <option value="<?= $staff['id'] ?>" <?= $ticket['assigned_to'] == $staff['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($staff['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="info-item">
                <div class="info-label">Created</div>
                <div class="info-value"><?= date('M j, Y g:i A', strtotime($ticket['created_at'])); ?></div>
            </div>

            <div class="info-item">
                <div class="info-label">Last Updated</div>
                <div class="info-value">
                    <?php if ($ticket['updated_at']): ?>
                        <?= date('M j, Y g:i A', strtotime($ticket['updated_at'])); ?>
                    <?php else: ?>
                        Never
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Activity Log Card -->
        <div class="sidebar-card">
            <h3>Activity Log</h3>
            <div class="activity-log-container">
                <?php if (!empty($activity_log)): ?>
                    <?php foreach ($activity_log as $activity):
                        $activityIcon = getActivityIcon($activity['action_type']);
                    ?>
                        <div class="activity-item <?= ($activity['old_value'] || $activity['new_value']) ? 'activity-expandable' : ''; ?>"
                             onclick="<?= ($activity['old_value'] || $activity['new_value']) ? 'toggleActivityDetails(this)' : ''; ?>">
                            <div class="activity-icon-wrapper" style="background-color: <?= $activityIcon['color']; ?>;">
                                <i class="bi <?= $activityIcon['icon']; ?>"></i>
                            </div>
                            <div class="activity-content-wrapper">
                                <div class="activity-description">
                                    <?= htmlspecialchars($activity['details'] ?: $activity['action_type']); ?>
                                    <?php if ($activity['old_value'] || $activity['new_value']): ?>
                                        <i class="bi bi-chevron-down activity-chevron"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-meta">
                                    <?= htmlspecialchars($activity['username']); ?> • <?= timeAgo($activity['created_at']); ?>
                                </div>
                                <?php if ($activity['old_value'] || $activity['new_value']): ?>
                                    <div class="activity-details" style="display: none;">
                                        <?php if ($activity['old_value']): ?>
                                            <div class="activity-change">
                                                <span class="change-label">Before:</span>
                                                <span class="change-value old-value"><?= htmlspecialchars($activity['old_value']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($activity['new_value']): ?>
                                            <div class="activity-change">
                                                <span class="change-label">After:</span>
                                                <span class="change-value new-value"><?= htmlspecialchars($activity['new_value']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-clock-history"></i>
                        No activity yet
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Private Notes Card -->
        <div class="sidebar-card">
            <h3>Private Notes</h3>
            <div class="private-notes-container">
                <?php if (!empty($private_notes)): ?>
                    <?php foreach ($private_notes as $note): ?>
                        <div class="private-note-item">
                            <div class="private-note-header">
                                <span class="private-note-author"><?= htmlspecialchars($note['username']); ?></span>
                                <span class="private-note-time"><?= timeAgo($note['created_at']); ?></span>
                            </div>
                            <div class="private-note-content" onclick="editNote(<?= $note['id'] ?>, this)" data-note-id="<?= $note['id'] ?>">
                                <?= $note['message']; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        No private notes yet
                    </div>
                <?php endif; ?>
            </div>

            <div class="private-note-form">
                <input type="text" class="form-control form-control-sm" id="newNoteInput" placeholder="Add a private note...">
                <button class="btn btn-sm btn-primary" onclick="addPrivateNote()">
                    <i class="bi bi-plus"></i>
                </button>
            </div>
        </div>
    </div>
    <!-- End Sidebar -->
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

function editTicketSubject() {
    document.getElementById('ticket-subject-display').style.display = 'none';
    document.getElementById('ticket-subject-edit').style.display = 'inline-block';
    document.getElementById('ticket-subject-input').focus();
    document.getElementById('ticket-subject-input').select();
}

function cancelTicketSubject() {
    document.getElementById('ticket-subject-display').style.display = 'inline';
    document.getElementById('ticket-subject-edit').style.display = 'none';
}

function saveTicketSubject() {
    const newValue = document.getElementById('ticket-subject-input').value.trim();
    if (!newValue) {
        showToast('Subject cannot be empty', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'update_ticket_field');
    formData.append('field', 'subject');
    formData.append('value', newValue);

    fetch(window.location.href, { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Subject updated successfully', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.message || 'Failed to update subject', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to update subject', 'error');
    });
}

let initialMessageEditor = null;
let replyEditors = {};

function editInitialMessage() {
    const displayDiv = document.getElementById('initial-message-display');
    const editDiv = document.getElementById('initial-message-edit');

    displayDiv.style.display = 'none';
    editDiv.style.display = 'block';

    // Create Quill editor if it doesn't exist
    if (!initialMessageEditor) {
        initialMessageEditor = new Quill('#initial-message-editor', {
            theme: 'snow',
            placeholder: 'Edit message...',
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

    // Get current content (convert BR tags back to newlines)
    const currentHTML = displayDiv.innerHTML.replace(/<div class="edit-hint">.*?<\/div>/s, '');
    initialMessageEditor.root.innerHTML = currentHTML;
}

function cancelInitialMessage() {
    document.getElementById('initial-message-display').style.display = 'block';
    document.getElementById('initial-message-edit').style.display = 'none';
}

function saveInitialMessage() {
    if (!initialMessageEditor) return;

    const htmlContent = initialMessageEditor.root.innerHTML;
    const textContent = initialMessageEditor.getText().trim();

    if (!textContent) {
        showToast('Message content cannot be empty', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'update_ticket_details');
    formData.append('details', textContent);

    fetch(window.location.href, { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Message updated successfully', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.message || 'Failed to update message', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to update message', 'error');
    });
}

function editReply(replyId, isPrivate) {
    const messageDiv = document.getElementById(`reply-message-${replyId}`);
    const editContainer = document.getElementById(`reply-edit-${replyId}`);

    if (!messageDiv || !editContainer) return;

    // Hide message, show editor
    messageDiv.style.display = 'none';
    editContainer.style.display = 'block';

    // Get current content
    const currentContent = messageDiv.innerHTML;

    // Create Quill editor if it doesn't exist
    if (!replyEditors[replyId]) {
        const editorWrapper = editContainer.querySelector('.reply-editor-wrapper');
        const editorId = `reply-editor-${replyId}`;
        editorWrapper.innerHTML = `<div id="${editorId}"></div>`;

        replyEditors[replyId] = new Quill(`#${editorId}`, {
            theme: 'snow',
            placeholder: 'Edit your reply...',
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

    // Set content
    replyEditors[replyId].root.innerHTML = currentContent;
}

function cancelReplyEdit(replyId) {
    const messageDiv = document.getElementById(`reply-message-${replyId}`);
    const editContainer = document.getElementById(`reply-edit-${replyId}`);

    if (!messageDiv || !editContainer) return;

    messageDiv.style.display = 'block';
    editContainer.style.display = 'none';
}

function saveReplyEdit(replyId, isPrivate) {
    if (!replyEditors[replyId]) return;

    const htmlContent = replyEditors[replyId].root.innerHTML;
    const textContent = replyEditors[replyId].getText().trim();

    if (!textContent) {
        showToast('Reply content cannot be empty', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'update_reply');
    formData.append('reply_id', replyId);
    formData.append('is_private', isPrivate);
    formData.append('message', htmlContent);

    fetch(window.location.href, { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Reply updated successfully', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.message || 'Failed to update reply', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to update reply', 'error');
    });
}

function deleteReply(replyId, isPrivate) {
    if (!confirm('Are you sure you want to delete this reply? This action cannot be undone.')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'delete_reply');
    formData.append('reply_id', replyId);
    formData.append('is_private', isPrivate);

    fetch(window.location.href, { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Reply deleted successfully', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.message || 'Failed to delete reply', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to delete reply', 'error');
    });
}

function toggleActivityDetails(element) {
    const detailsDiv = element.querySelector('.activity-details');
    if (!detailsDiv) return;

    const isExpanded = detailsDiv.style.display === 'block';

    if (isExpanded) {
        detailsDiv.style.display = 'none';
        element.classList.remove('expanded');
    } else {
        detailsDiv.style.display = 'block';
        element.classList.add('expanded');
    }
}
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>
