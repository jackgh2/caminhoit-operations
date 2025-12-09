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

$user_id = $user['id'];
$ticket_id = $_GET['id'] ?? null;

if (!$ticket_id) {
    header('Location: /members/my-ticket.php');
    exit;
}

// Enhanced query using same approach as staff-view-ticket.php
$stmt = $pdo->prepare("
    SELECT t.*,
           u.username AS creator_name,
           u.role AS creator_role,
           a.username AS assigned_name,
           a.role AS assigned_role,
           g.name AS group_name,
           ei.processed_at as ticket_imported_at
    FROM support_tickets t
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN users a ON t.assigned_to = a.id
    LEFT JOIN support_ticket_groups g ON t.group_id = g.id
    LEFT JOIN support_email_imports ei ON ei.ticket_id = t.id AND ei.import_type = 'new_ticket'
    WHERE t.id = ? AND (t.user_id = ? OR ? IN (
        SELECT user_id FROM company_users WHERE company_id IN (
            SELECT company_id FROM company_users WHERE user_id = t.user_id
        )
    ))
");
$stmt->execute([$ticket_id, $user_id, $user_id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    header('Location: /members/my-ticket.php');
    exit;
}

// Handle reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_content'])) {
    $reply_content = trim($_POST['reply_content']);
    if (!empty($reply_content)) {
        $stmt = $pdo->prepare("INSERT INTO support_ticket_replies (ticket_id, user_id, message, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$ticket_id, $user_id, $reply_content]);
        $reply_id = $pdo->lastInsertId();

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

        $pdo->prepare("UPDATE support_tickets SET updated_at = NOW() WHERE id = ?")->execute([$ticket_id]);

        // Send notifications when customer replies
        try {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/TicketNotifications.php';
            $notifications = new TicketNotifications($pdo);
            $notifications->notifyCustomerReply($ticket_id, $reply_id, $user_id);
            error_log("Customer reply notifications sent for ticket #{$ticket_id}, reply #{$reply_id}");
        } catch (Exception $e) {
            error_log("Failed to send customer reply notifications: " . $e->getMessage());
            // Don't fail the reply if notifications fail
        }

        header("Location: view-ticket.php?id=" . $ticket_id);
        exit;
    }
}

// Enhanced query to get replies with user role information (same as staff version)
$stmt = $pdo->prepare("
    SELECT r.*, u.username, u.role,
           ei.processed_at as email_imported_at
    FROM support_ticket_replies r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN support_email_imports ei ON ei.reply_id = r.id AND ei.import_type = 'reply'
    WHERE r.ticket_id = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$ticket_id]);
$replies = $stmt->fetchAll();

// Get attachments for replies
$attachmentsByReply = [];
$stmt = $pdo->prepare("SELECT * FROM support_ticket_attachments WHERE reply_id IN (SELECT id FROM support_ticket_replies WHERE ticket_id = ?)");
$stmt->execute([$ticket_id]);
foreach ($stmt->fetchAll() as $att) {
    $attachmentsByReply[$att['reply_id']][] = $att;
}

// Get initial ticket attachments from the new table
$initialAttachments = [];
$stmt = $pdo->prepare("SELECT * FROM support_ticket_attachments_initial WHERE ticket_id = ? ORDER BY uploaded_at DESC");
$stmt->execute([$ticket_id]);
$initialAttachments = $stmt->fetchAll();

$page_title = "Ticket #" . htmlspecialchars($ticket['id']) . " | CaminhoIT";

// Enhanced function to determine if user is staff (same logic as staff-view-ticket.php)
function isStaff($role) {
    return in_array($role, ['administrator', 'support_user', 'support_technician', 'accountant']);
}

// Enhanced function to get user badge information with custom labels
function getUserBadge($role) {
    if (isStaff($role)) {
        switch (strtolower($role)) {
            case 'administrator':
                return [
                    'text' => 'Account Manager',
                    'class' => 'badge-admin',
                    'icon' => 'bi-shield-fill'
                ];
            case 'support_user':
                return [
                    'text' => 'Support',
                    'class' => 'badge-support',
                    'icon' => 'bi-headset'
                ];
            case 'support_technician':
                return [
                    'text' => 'Technical Guru',
                    'class' => 'badge-technical',
                    'icon' => 'bi-gear-fill'
                ];
            case 'accountant':
                return [
                    'text' => 'Financial Analyst',
                    'class' => 'badge-financial',
                    'icon' => 'bi-calculator'
                ];
            default:
                return [
                    'text' => 'Staff',
                    'class' => 'badge-staff',
                    'icon' => 'bi-shield-check'
                ];
        }
    } else {
        return [
            'text' => 'Member',
            'class' => 'badge-member',
            'icon' => 'bi-person'
        ];
    }
}

// Function to get avatar color based on role (same as staff version)
function getAvatarColor($role) {
    if (isStaff($role)) {
        switch (strtolower($role)) {
            case 'administrator':
                return '#dc2626'; // Red - Account Manager
            case 'support_user':
                return '#2563eb'; // Blue - Support
            case 'support_technician':
                return '#059669'; // Green - Technical Guru
            case 'accountant':
                return '#7c3aed'; // Purple - Financial Analyst
            default:
                return '#059669'; // Green (staff)
        }
    }
    return '#6b7280'; // Gray (member)
}

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
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<!-- Quill Rich Text Editor -->
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">

<style>
        /* Main container dark mode */
        :root.dark .enhanced-card {
            background: #1e293b !important;
        }

        :root.dark .content-overlap {
            background: transparent;
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

        :root.dark .text-muted {
            color: #94a3b8 !important;
        }

        :root.dark small {
            color: inherit;
        }

        /* Metadata Section */
        .metadata-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            padding: 2rem;
            background: transparent;
        }

        :root.dark .metadata-section {
            background: transparent;
        }

        .metadata-group {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .metadata-group::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .metadata-group:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .metadata-group-title {
            color: #1e293b;
            font-size: 0.875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .metadata-item-enhanced {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 0.875rem 0;
            border-bottom: 1px solid rgba(226, 232, 240, 0.6);
        }

        .metadata-item-enhanced:last-child {
            border-bottom: none;
        }

        .metadata-label-enhanced {
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .metadata-value-enhanced {
            color: #1e293b;
            font-weight: 600;
            font-size: 0.875rem;
            text-align: right;
            flex: 1;
            margin-left: 1rem;
        }

        /* Status and Priority Badges */
        .status-badge-enhanced {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .status-open {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        .status-progress, .status-in-progress {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
            border: 1px solid #fcd34d;
        }

        .status-closed {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
            border: 1px solid #86efac;
        }

        .priority-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.5rem 1rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: #475569;
            border: 1px solid #cbd5e1;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        /* User Badge Styles */
        .user-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.625rem;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-left: 0.5rem;
        }

        .badge-admin {
            background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
            color: #991b1b;
            border: 1px solid #f87171;
        }

        .badge-support {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        .badge-technical {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            color: #166534;
            border: 1px solid #86efac;
        }

        .badge-financial {
            background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%);
            color: #7c2d12;
            border: 1px solid #c4b5fd;
        }

        .badge-member {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #374151;
            border: 1px solid #d1d5db;
        }

        /* Initial Request Section */
        .initial-request-section {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 2px solid #0ea5e9;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .initial-request-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .initial-request-label {
            color: #0369a1;
            font-size: 0.875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .initial-request-content {
            color: #1e293b;
            line-height: 1.7;
            font-size: 0.9rem;
        }

        /* Reply Section */
        .reply-section-enhanced {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-top: 1px solid rgba(226, 232, 240, 0.8);
            padding: 2rem;
            position: relative;
        }

        .reply-toggle-enhanced {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: #64748b;
            cursor: pointer;
            padding: 1.5rem;
            border-radius: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 2px dashed #cbd5e1;
            background: rgba(255, 255, 255, 0.7);
        }

        .reply-toggle-enhanced:hover {
            background: white;
            border-color: #94a3b8;
            color: #475569;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .reply-form-section {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            margin-top: 1rem;
            display: none;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .reply-input-wrapper {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
        }

        .reply-textarea {
            width: 100%;
            background: transparent;
            border: none;
            color: #1e293b;
            font-size: 0.875rem;
            resize: vertical;
            min-height: 120px;
            line-height: 1.6;
        }

        .reply-textarea:focus {
            outline: none;
        }

        /* Quill Editor Styling */
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
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 12px;
            border-color: #e2e8f0;
        }

        .ql-toolbar {
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-color: #e2e8f0;
        }

        .ql-toolbar .ql-stroke {
            stroke: #475569;
        }

        .ql-toolbar .ql-fill {
            fill: #475569;
        }

        .ql-toolbar button:hover,
        .ql-toolbar button.ql-active {
            color: #667eea;
        }

        .ql-toolbar button:hover .ql-stroke,
        .ql-toolbar button.ql-active .ql-stroke {
            stroke: #667eea;
        }

        .ql-toolbar button:hover .ql-fill,
        .ql-toolbar button.ql-active .ql-fill {
            fill: #667eea;
        }

        .reply-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.5rem;
            gap: 1rem;
        }

        /* Buttons */
        .btn-enhanced {
            border-radius: 50px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            position: relative;
            overflow: hidden;
            font-size: 0.875rem;
        }

        .btn-action-enhanced {
            background: white;
            border: 2px solid #e2e8f0;
            color: #475569;
            padding: 0.625rem 1.25rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-action-enhanced:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #334155;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .send-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .send-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.6);
        }

        .file-upload-btn {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.875rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .file-upload-btn:hover {
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
            transform: translateY(-1px);
        }

        /* Reply Cards */
        .reply-card {
            background: white;
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 12px;
            margin-bottom: 1.5rem;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .reply-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .reply-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }

        .reply-user {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .reply-user-avatar {
            width: 3rem;
            height: 3rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1rem;
            position: relative;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .reply-user-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .reply-username {
            color: #1e293b;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .reply-time {
            color: #64748b;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .reply-content {
            padding: 2rem;
            color: #374151;
            line-height: 1.7;
            font-size: 0.9rem;
        }

        .time-relative {
            color: #10b981;
            font-weight: 600;
        }

        /* Attachments */
        .attachment-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .attachment-item {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.25rem;
            text-align: center;
            min-width: 140px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .attachment-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .attachment-item:hover {
            background: white;
            border-color: #cbd5e1;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .attachment-icon {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
        }

        .attachment-name {
            color: #374151;
            font-size: 0.75rem;
            font-weight: 600;
            word-break: break-word;
            margin-bottom: 1rem;
            line-height: 1.4;
        }

        .attachment-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
        }

        .btn-attachment {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 0.375rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .btn-attachment:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .btn-attachment.outline {
            background: transparent;
            border: 1px solid #667eea;
            color: #667eea;
        }

        .btn-attachment.outline:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }

        .image-preview {
            max-width: 120px;
            max-height: 100px;
            border-radius: 12px;
            margin-bottom: 0.75rem;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .image-preview:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        /* Dark Mode Styles */
        :root.dark .metadata-group {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-color: #334155;
        }

        :root.dark .metadata-group::before {
            background: linear-gradient(135deg, #a78bfa 0%, #8b5cf6 100%);
        }

        :root.dark .metadata-group-title {
            color: #f1f5f9;
        }

        :root.dark .metadata-item-enhanced {
            border-bottom-color: #334155;
        }

        :root.dark .metadata-label-enhanced {
            color: #94a3b8;
        }

        :root.dark .metadata-value-enhanced {
            color: #e2e8f0;
        }

        :root.dark .status-badge-enhanced {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
        }

        :root.dark .status-open {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: #bfdbfe;
            border-color: #3b82f6;
        }

        :root.dark .status-progress,
        :root.dark .status-in-progress {
            background: linear-gradient(135deg, #78350f 0%, #92400e 100%);
            color: #fde68a;
            border-color: #f59e0b;
        }

        :root.dark .status-closed {
            background: linear-gradient(135deg, #064e3b 0%, #065f46 100%);
            color: #a7f3d0;
            border-color: #10b981;
        }

        :root.dark .priority-badge {
            background: linear-gradient(135deg, #334155 0%, #475569 100%);
            color: #cbd5e1;
            border-color: #64748b;
        }

        :root.dark .badge-admin {
            background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 100%);
            color: #fca5a5;
            border-color: #dc2626;
        }

        :root.dark .badge-support {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: #bfdbfe;
            border-color: #3b82f6;
        }

        :root.dark .badge-technical {
            background: linear-gradient(135deg, #064e3b 0%, #065f46 100%);
            color: #a7f3d0;
            border-color: #10b981;
        }

        :root.dark .badge-financial {
            background: linear-gradient(135deg, #581c87 0%, #6b21a8 100%);
            color: #e9d5ff;
            border-color: #a855f7;
        }

        :root.dark .badge-member {
            background: linear-gradient(135deg, #374151 0%, #4b5563 100%);
            color: #d1d5db;
            border-color: #6b7280;
        }

        :root.dark .user-badge[style*="background: linear-gradient"] {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%) !important;
            color: #bfdbfe !important;
            border: 1px solid #3b82f6 !important;
        }

        :root.dark .initial-request-section {
            background: linear-gradient(135deg, #0c4a6e 0%, #075985 100%);
            border-color: #0ea5e9;
        }

        :root.dark .initial-request-label {
            color: #7dd3fc;
        }

        :root.dark .initial-request-content {
            color: #e0f2fe;
        }

        :root.dark .reply-section-enhanced {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-top-color: #334155;
        }

        :root.dark .reply-toggle-enhanced {
            background: rgba(30, 41, 59, 0.7);
            border-color: #475569;
            color: #94a3b8;
        }

        :root.dark .reply-toggle-enhanced:hover {
            background: #1e293b;
            border-color: #64748b;
            color: #cbd5e1;
        }

        :root.dark .reply-form-section {
            background: #1e293b;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
        }

        :root.dark .reply-input-wrapper {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-color: #334155;
        }

        :root.dark .reply-textarea {
            color: #e2e8f0;
        }

        :root.dark #replyEditor {
            background: #1e293b;
        }

        :root.dark .ql-toolbar {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-color: #334155;
        }

        :root.dark .ql-container {
            border-color: #334155;
        }

        :root.dark .ql-editor {
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

        :root.dark .btn-action-enhanced {
            background: #1e293b;
            border-color: #334155;
            color: #cbd5e1;
        }

        :root.dark .btn-action-enhanced:hover {
            background: #334155;
            border-color: #475569;
            color: #f1f5f9;
        }

        :root.dark .send-button {
            background: linear-gradient(135deg, #a78bfa 0%, #8b5cf6 100%);
            box-shadow: 0 4px 15px rgba(167, 139, 250, 0.4);
        }

        :root.dark .send-button:hover {
            box-shadow: 0 8px 25px rgba(167, 139, 250, 0.6);
        }

        :root.dark .file-upload-btn {
            background: linear-gradient(135deg, #334155 0%, #475569 100%);
            border-color: #64748b;
            color: #cbd5e1;
        }

        :root.dark .file-upload-btn:hover {
            background: linear-gradient(135deg, #475569 0%, #64748b 100%);
        }

        :root.dark .reply-card {
            background: #1e293b;
            border-color: #334155;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
        }

        :root.dark .reply-card:hover {
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
        }

        :root.dark .reply-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-bottom-color: #334155;
        }

        :root.dark .reply-username {
            color: #f1f5f9;
        }

        :root.dark .reply-time {
            color: #94a3b8;
        }

        :root.dark .reply-content {
            color: #cbd5e1;
        }

        :root.dark .reply-content p,
        :root.dark .reply-content ul,
        :root.dark .reply-content ol,
        :root.dark .reply-content li {
            color: #cbd5e1;
        }

        :root.dark .initial-request-content p,
        :root.dark .initial-request-content ul,
        :root.dark .initial-request-content ol,
        :root.dark .initial-request-content li {
            color: #e0f2fe;
        }

        :root.dark .initial-request-label span {
            background: #1e293b !important;
            color: #7dd3fc !important;
            border: 1px solid #334155 !important;
        }

        :root.dark .reply-time small {
            color: #7dd3fc !important;
        }

        :root.dark .time-relative {
            color: #86efac !important;
        }

        /* Modal dark mode */
        :root.dark .modal-content {
            background: #1e293b;
            color: #e2e8f0;
        }

        :root.dark .modal-header {
            background: #0f172a;
            border-bottom-color: #334155;
        }

        :root.dark .modal-title {
            color: #f1f5f9;
        }

        :root.dark .btn-close {
            filter: invert(1);
        }

        :root.dark .attachment-item {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-color: #334155;
        }

        :root.dark .attachment-item:hover {
            background: #1e293b;
            border-color: #475569;
        }

        :root.dark .attachment-name {
            color: #cbd5e1;
        }

        :root.dark .btn-attachment {
            background: linear-gradient(135deg, #a78bfa 0%, #8b5cf6 100%);
        }

        :root.dark .btn-attachment.outline {
            background: transparent;
            border-color: #a78bfa;
            color: #a78bfa;
        }

        :root.dark .btn-attachment.outline:hover {
            background: linear-gradient(135deg, #a78bfa 0%, #8b5cf6 100%);
            color: white;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .metadata-section {
                grid-template-columns: 1fr;
                padding: 1.5rem;
            }

            .reply-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .attachment-grid {
                justify-content: center;
            }
        }
    </style>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="view-ticket-hero-content">
            <h1 class="view-ticket-hero-title text-white">
                <i class="bi bi-ticket-detailed me-3"></i>
                <?= htmlspecialchars($ticket['subject']); ?>
            </h1>
            <p class="view-ticket-hero-subtitle text-white">
                Ticket #<?= htmlspecialchars($ticket['id']); ?> • <?= htmlspecialchars($ticket['group_name'] ?? 'General'); ?>
            </p>
        </div>
    </div>
</header>

<div class="container py-5 content-overlap">
    
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="breadcrumb-enhanced fade-in">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="/members/my-ticket.php">My Tickets</a></li>
            <li class="breadcrumb-item active" aria-current="page">Ticket #<?= htmlspecialchars($ticket['id']); ?></li>
        </ol>
    </nav>

    <!-- Enhanced Ticket Container -->
    <div class="enhanced-card fade-in">
        <!-- Enhanced Metadata Section -->
        <div class="metadata-section">
            <!-- Status & Priority Group -->
            <div class="metadata-group">
                <div class="metadata-group-title">
                    <i class="bi bi-info-circle"></i>
                    Status & Priority
                </div>
                <div class="metadata-item-enhanced">
                    <span class="metadata-label-enhanced">
                        <i class="bi bi-flag"></i>
                        Status
                    </span>
                    <span class="metadata-value-enhanced">
                        <span class="status-badge-enhanced status-<?= strtolower(str_replace(' ', '-', $ticket['status'])); ?>">
                            <i class="bi bi-dot"></i>
                            <?= htmlspecialchars($ticket['status']); ?>
                        </span>
                    </span>
                </div>
                <div class="metadata-item-enhanced">
                    <span class="metadata-label-enhanced">
                        <i class="bi bi-exclamation-triangle"></i>
                        Priority
                    </span>
                    <span class="metadata-value-enhanced">
                        <span class="priority-badge">
                            <i class="bi bi-flag"></i>
                            <?= htmlspecialchars($ticket['priority'] ?? 'Normal'); ?>
                        </span>
                    </span>
                </div>
            </div>

            <!-- Assignment Group -->
            <div class="metadata-group">
                <div class="metadata-group-title">
                    <i class="bi bi-people"></i>
                    Assignment
                </div>
                <div class="metadata-item-enhanced">
                    <span class="metadata-label-enhanced">
                        <i class="bi bi-person-badge"></i>
                        Assigned To
                    </span>
                    <span class="metadata-value-enhanced">
                        <?php if ($ticket['assigned_name']): ?>
                            <div class="d-flex align-items-center justify-content-end">
                                <span><?= htmlspecialchars($ticket['assigned_name']); ?></span>
                                <?php 
                                $assignedBadge = getUserBadge($ticket['assigned_role']);
                                ?>
                                <span class="user-badge <?= $assignedBadge['class']; ?>">
                                    <i class="<?= $assignedBadge['icon']; ?>"></i>
                                    <?= $assignedBadge['text']; ?>
                                </span>
                            </div>
                        <?php else: ?>
                            <span class="text-muted">Unassigned</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="metadata-item-enhanced">
                    <span class="metadata-label-enhanced">
                        <i class="bi bi-person-plus"></i>
                        Created By
                    </span>
                    <span class="metadata-value-enhanced">
                        <div class="d-flex align-items-center justify-content-end">
                            <span><?= htmlspecialchars($ticket['creator_name']); ?></span>
                            <?php 
                            $creatorBadge = getUserBadge($ticket['creator_role']);
                            ?>
                            <span class="user-badge <?= $creatorBadge['class']; ?>">
                                <i class="<?= $creatorBadge['icon']; ?>"></i>
                                <?= $creatorBadge['text']; ?>
                            </span>
                        </div>
                    </span>
                </div>
            </div>

            <!-- Timeline Group -->
            <div class="metadata-group">
                <div class="metadata-group-title">
                    <i class="bi bi-clock-history"></i>
                    Timeline
                </div>
                <div class="metadata-item-enhanced">
                    <span class="metadata-label-enhanced">
                        <i class="bi bi-calendar-plus"></i>
                        Created
                    </span>
                    <span class="metadata-value-enhanced">
                        <?= date('M j, Y g:i A', strtotime($ticket['created_at'])); ?>
                        <br><small class="time-relative"><?= timeAgo($ticket['created_at']); ?></small>
                    </span>
                </div>
                <div class="metadata-item-enhanced">
                    <span class="metadata-label-enhanced">
                        <i class="bi bi-clock"></i>
                        Last Updated
                    </span>
                    <span class="metadata-value-enhanced">
                        <?php if ($ticket['updated_at']): ?>
                            <?= date('M j, Y g:i A', strtotime($ticket['updated_at'])); ?>
                            <br><small class="time-relative"><?= timeAgo($ticket['updated_at']); ?></small>
                        <?php else: ?>
                            <span class="text-muted">Never</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Enhanced Reply Section -->
        <?php if ($ticket['status'] !== 'Closed'): ?>
        <div class="reply-section-enhanced">
            <div class="reply-toggle-enhanced" onclick="toggleReplyForm()" id="replyToggle">
                <i class="bi bi-reply"></i>
                <span>Click here to add a reply to this ticket</span>
                <i class="bi bi-arrow-right ms-auto"></i>
            </div>
            
            <!-- Reply Form -->
            <div class="reply-form-section" id="replyForm">
                <form method="post" enctype="multipart/form-data" id="replyFormElement">
                    <div class="reply-input-wrapper">
                        <div id="replyEditor"></div>
                        <input type="hidden" name="reply_content" id="replyContent">
                    </div>
                    <div class="reply-actions">
                        <div class="file-upload-area">
                            <label for="fileInput" class="file-upload-btn">
                                <i class="bi bi-paperclip"></i>
                                <span id="fileLabel">Attach files</span>
                            </label>
                            <input type="file" name="attachment[]" multiple class="d-none" id="fileInput">
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn-action-enhanced" onclick="toggleReplyForm()">
                                <i class="bi bi-x"></i>
                                Cancel
                            </button>
                            <button type="submit" class="send-button">
                                <i class="bi bi-send"></i>
                                Submit Reply
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Initial Request Message -->
    <div class="initial-request-section fade-in">
        <div class="initial-request-label">
            <i class="bi bi-envelope-open"></i>
            Initial Request
            <?php if (!empty($ticket['ticket_imported_at'])): ?>
                <span style="margin-left: 1rem; font-size: 0.75rem; padding: 0.25rem 0.75rem; background: white; border-radius: 50px; color: #0369a1;">
                    <i class="bi bi-inbox"></i> Created from Email • Imported <?= timeAgo($ticket['ticket_imported_at']); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="initial-request-content">
            <?= nl2br(htmlspecialchars($ticket['details'] ?? 'No details provided')); ?>
        </div>
        
        <!-- Display initial attachments if any -->
        <?php if (!empty($initialAttachments)): ?>
            <div class="attachment-grid">
                <?php foreach ($initialAttachments as $att):
                    $fileInfo = getFileIcon($att['file_name']);
                    $ext = strtolower(pathinfo($att['file_name'], PATHINFO_EXTENSION));
                    $filePath = "/members/attachments/{$ticket['id']}/" . urlencode($att['file_name']);
                ?>
                    <div class="attachment-item">
                        <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])): ?>
                            <img src="<?= $filePath ?>" alt="attachment" class="image-preview" 
                                 onclick="openPreview('<?= $filePath ?>')">
                        <?php else: ?>
                            <div class="attachment-icon" style="color: <?= $fileInfo['color']; ?>">
                                <i class="<?= $fileInfo['icon']; ?>"></i>
                            </div>
                        <?php endif; ?>
                        <div class="attachment-name"><?= htmlspecialchars($att['original_name']); ?></div>
                        <div class="attachment-actions">
                            <a href="<?= $filePath ?>" target="_blank" class="btn-attachment outline">
                                <i class="bi bi-eye"></i>
                                View
                            </a>
                            <a href="<?= $filePath ?>" download class="btn-attachment">
                                <i class="bi bi-download"></i>
                                Download
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Enhanced Replies -->
    <?php foreach ($replies as $reply): 
        $userBadge = getUserBadge($reply['role']);
        $avatarColor = getAvatarColor($reply['role']);
        $isStaffUser = isStaff($reply['role']);
    ?>
        <div class="reply-card fade-in">
            <div class="reply-header">
                <div class="reply-user">
                    <div class="reply-user-avatar" style="background: <?= $avatarColor; ?>">
                        <?= strtoupper(substr($reply['username'], 0, 2)); ?>
                    </div>
                    <div class="reply-user-info">
                        <div class="reply-username">
                            <?= htmlspecialchars($reply['username']); ?>
                            <span class="user-badge <?= $userBadge['class']; ?>">
                                <i class="<?= $userBadge['icon']; ?>"></i>
                                <?= $userBadge['text']; ?>
                            </span>
                            <?php if (!empty($reply['email_imported_at'])): ?>
                                <span class="user-badge" style="background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1e40af; border: 1px solid #93c5fd;" title="Imported from email at <?= date('M j, Y g:i A', strtotime($reply['email_imported_at'])); ?>">
                                    <i class="bi bi-envelope-fill"></i>
                                    Email Import
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="reply-time">
                            <?= date('M j, Y g:i A', strtotime($reply['created_at'])); ?> • <?= timeAgo($reply['created_at']); ?>
                            <?php if (!empty($reply['email_imported_at'])): ?>
                                <br><small style="color: #0ea5e9; font-weight: 600;">
                                    <i class="bi bi-inbox"></i> Imported <?= timeAgo($reply['email_imported_at']); ?>
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <button class="btn-action-enhanced">
                    <i class="bi bi-three-dots"></i>
                </button>
            </div>
            <div class="reply-content">
                <?= $reply['message']; ?>
                
                <?php if (!empty($attachmentsByReply[$reply['id']])): ?>
                    <div class="attachment-grid">
                        <?php foreach ($attachmentsByReply[$reply['id']] as $att):
                            $fileInfo = getFileIcon($att['file_name']);
                            $ext = strtolower(pathinfo($att['file_name'], PATHINFO_EXTENSION));
                            $filePath = "/members/attachments/{$ticket['id']}/" . urlencode($att['file_name']);
                        ?>
                            <div class="attachment-item">
                                <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])): ?>
                                    <img src="<?= $filePath ?>" alt="attachment" class="image-preview" 
                                         onclick="openPreview('<?= $filePath ?>')">
                                <?php else: ?>
                                    <div class="attachment-icon" style="color: <?= $fileInfo['color']; ?>">
                                        <i class="<?= $fileInfo['icon']; ?>"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="attachment-name"><?= htmlspecialchars($att['original_name']); ?></div>
                                <div class="attachment-actions">
                                    <a href="<?= $filePath ?>" target="_blank" class="btn-attachment outline">
                                        <i class="bi bi-eye"></i>
                                        View
                                    </a>
                                    <a href="<?= $filePath ?>" download class="btn-attachment">
                                        <i class="bi bi-download"></i>
                                        Download
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Back Button -->
    <div class="text-center mt-4">
        <a href="/members/my-ticket.php" class="btn-action-enhanced">
            <i class="bi bi-arrow-left-circle"></i>
            Back to My Tickets
        </a>
    </div>
</div>

<!-- Modal for image preview -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="previewContainer" class="text-center"></div>
            </div>
        </div>
    </div>
</div>

<!-- Quill Rich Text Editor JS -->
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script>
let quill = null;

// Enhanced scroll animations
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

    // Handle form submission
    const replyFormElement = document.getElementById('replyFormElement');
    if (replyFormElement) {
        replyFormElement.addEventListener('submit', function(e) {
            // Get HTML content from Quill editor
            if (quill) {
                const htmlContent = quill.root.innerHTML;
                document.getElementById('replyContent').value = htmlContent;

                // Check if empty (Quill adds <p><br></p> for empty content)
                const textContent = quill.getText().trim();
                if (!textContent) {
                    e.preventDefault();
                    alert('Reply content cannot be empty');
                    return false;
                }
            }
            // Form will submit normally if validation passes
        });
    }
    // Intersection Observer for fade-in animations
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

    // Observe all fade-in elements
    document.querySelectorAll('.fade-in').forEach(el => {
        observer.observe(el);
    });

    // Auto-resize textarea
    const textarea = document.querySelector('.reply-textarea');
    if (textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 250) + 'px';
        });
    }
});

function toggleReplyForm() {
    const form = document.getElementById('replyForm');
    const toggle = document.getElementById('replyToggle');

    if (form.style.display === 'none' || form.style.display === '') {
        form.style.display = 'block';
        toggle.style.display = 'none';
        setTimeout(() => {
            if (quill) {
                quill.focus();
            }
        }, 300);
    } else {
        form.style.display = 'none';
        toggle.style.display = 'block';
        if (quill) {
            quill.setText('');
        }
        document.getElementById('replyFormElement').reset();
        document.getElementById('fileLabel').textContent = 'Attach files';
    }
}

function openPreview(src) {
    document.getElementById('previewContainer').innerHTML = `<img src="${src}" class="img-fluid rounded">`;
    new bootstrap.Modal(document.getElementById('previewModal')).show();
}

// File input change handler
document.getElementById('fileInput').addEventListener('change', function() {
    const fileCount = this.files.length;
    const label = document.getElementById('fileLabel');
    if (fileCount > 0) {
        label.textContent = `${fileCount} file${fileCount > 1 ? 's' : ''} selected`;
    } else {
        label.textContent = 'Attach files';
    }
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
