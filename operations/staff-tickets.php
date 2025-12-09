<?php
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

// Check if user is staff/admin - updated with correct role names
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_role = $stmt->fetchColumn();

if (!in_array($user_role, ['administrator', 'support_user'])) {
    header('Location: /dashboard.php');
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'assign_ticket':
                $ticket_id = $_POST['ticket_id'] ?? null;
                $assigned_to = $_POST['assigned_to'] ?? null;
                
                if ($ticket_id && $assigned_to) {
                    $stmt = $pdo->prepare("UPDATE support_tickets SET assigned_to = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                    if ($stmt->execute([$assigned_to, $user['id'], $ticket_id])) {
                        echo json_encode(['success' => true, 'message' => 'Ticket assigned successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to assign ticket']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                }
                exit;
                
            case 'update_ticket':
                $ticket_id = $_POST['ticket_id'] ?? null;
                $subject = $_POST['subject'] ?? null;
                $status = $_POST['status'] ?? null;
                $priority = $_POST['priority'] ?? null;
                $assigned_to = $_POST['assigned_to'] ?? null;
                $group_id = $_POST['group_id'] ?? null;
                $user_id = $_POST['user_id'] ?? null;
                
                if ($ticket_id && $subject) {
                    $update_fields = [];
                    $update_params = [];
                    
                    $update_fields[] = "subject = ?";
                    $update_params[] = $subject;
                    
                    if ($status) {
                        $update_fields[] = "status = ?";
                        $update_params[] = $status;
                    }
                    
                    if ($priority) {
                        $update_fields[] = "priority = ?";
                        $update_params[] = $priority;
                    }
                    
                    if ($assigned_to !== null) {
                        $update_fields[] = "assigned_to = ?";
                        $update_params[] = $assigned_to ?: null;
                    }
                    
                    if ($group_id !== null) {
                        $update_fields[] = "group_id = ?";
                        $update_params[] = $group_id ?: null;
                    }
                    
                    if ($user_id) {
                        $update_fields[] = "user_id = ?";
                        $update_params[] = $user_id;
                    }
                    
                    $update_fields[] = "updated_at = NOW()";
                    $update_fields[] = "updated_by = ?";
                    $update_params[] = $user['id'];
                    $update_params[] = $ticket_id;
                    
                    $sql = "UPDATE support_tickets SET " . implode(", ", $update_fields) . " WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    
                    if ($stmt->execute($update_params)) {
                        echo json_encode(['success' => true, 'message' => 'Ticket updated successfully']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to update ticket']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                }
                exit;
                
            case 'get_ticket_details':
                $ticket_id = $_POST['ticket_id'] ?? null;
                
                if ($ticket_id) {
                    $stmt = $pdo->prepare("
                        SELECT st.*, u.username, u.email, c.name as company_name, c.id as company_id
                        FROM support_tickets st
                        LEFT JOIN users u ON st.user_id = u.id
                        LEFT JOIN companies c ON u.company_id = c.id
                        WHERE st.id = ?
                    ");
                    $stmt->execute([$ticket_id]);
                    $ticket = $stmt->fetch();
                    
                    if ($ticket) {
                        echo json_encode(['success' => true, 'ticket' => $ticket]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Ticket not found']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid ticket ID']);
                }
                exit;
        }
    }
}

// Get filter parameters - default to unassigned (awaiting to be assigned)
$status_filter = $_GET['status'] ?? 'unassigned';
$search = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Build WHERE clause based on filters
$where_conditions = [];
$params = [];

if ($status_filter !== 'all') {
    switch ($status_filter) {
        case 'unanswered':
            $where_conditions[] = "st.status = 'Open' AND st.assigned_to IS NULL";
            break;
        case 'unclosed':
            $where_conditions[] = "st.status != 'Closed'";
            break;
        case 'unassigned':
            $where_conditions[] = "st.assigned_to IS NULL";
            break;
        case 'assigned_to_me':
            $where_conditions[] = "st.assigned_to = ?";
            $params[] = $user['id'];
            break;
        case 'pending_reply':
            // Tickets where the last reply was from a customer (non-staff member)
            $where_conditions[] = "st.id IN (
                SELECT DISTINCT t.id 
                FROM support_tickets t
                LEFT JOIN (
                    SELECT str.ticket_id, str.user_id, str.created_at,
                           ROW_NUMBER() OVER (PARTITION BY str.ticket_id ORDER BY str.created_at DESC) as rn
                    FROM support_ticket_replies str
                ) latest_reply ON t.id = latest_reply.ticket_id AND latest_reply.rn = 1
                LEFT JOIN users reply_user ON latest_reply.user_id = reply_user.id
                WHERE t.status != 'Closed' 
                AND t.assigned_to = ?
                AND (
                    (latest_reply.user_id IS NOT NULL AND reply_user.role NOT IN ('administrator', 'support_user'))
                    OR (latest_reply.user_id IS NULL AND t.user_id IS NOT NULL)
                )
            )";
            $params[] = $user['id'];
            break;
    }
}

if (!empty($search)) {
    $where_conditions[] = "(st.subject LIKE ? OR st.id LIKE ? OR u.username LIKE ? OR c.name LIKE ?)";
    $search_param = '%' . $search . '%';
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Fetch all tickets with user and company information, including last reply info
$sql = "
    SELECT st.*, 
           u.username AS customer_username,
           u.email AS customer_email,
           c.name AS company_name,
           c.id AS company_id,
           tg.name AS group_name, 
           assigned.username AS assigned_to_username,
           assigned.email AS assigned_to_email,
           updated.username AS updated_by_username,
           COUNT(str.id) AS reply_count,
           latest_reply.user_id AS last_reply_user_id,
           latest_reply.created_at AS last_reply_at,
           last_reply_user.username AS last_reply_username,
           last_reply_user.role AS last_reply_user_role
    FROM support_tickets st
    LEFT JOIN users u ON st.user_id = u.id
    LEFT JOIN companies c ON u.company_id = c.id
    LEFT JOIN support_ticket_groups tg ON st.group_id = tg.id
    LEFT JOIN users assigned ON st.assigned_to = assigned.id
    LEFT JOIN users updated ON st.updated_by = updated.id
    LEFT JOIN support_ticket_replies str ON st.id = str.ticket_id
    LEFT JOIN (
        SELECT str2.ticket_id, str2.user_id, str2.created_at,
               ROW_NUMBER() OVER (PARTITION BY str2.ticket_id ORDER BY str2.created_at DESC) as rn
        FROM support_ticket_replies str2
    ) latest_reply ON st.id = latest_reply.ticket_id AND latest_reply.rn = 1
    LEFT JOIN users last_reply_user ON latest_reply.user_id = last_reply_user.id
    $where_clause
    GROUP BY st.id, latest_reply.user_id, latest_reply.created_at, last_reply_user.username, last_reply_user.role
    ORDER BY st.$sort_by $sort_order
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

// Get data for dropdowns
$stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE role IN ('administrator', 'support_user') ORDER BY username");
$stmt->execute();
$staff_users = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, username, email, company_id FROM users ORDER BY username");
$stmt->execute();
$all_users = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name FROM support_ticket_groups ORDER BY name");
$stmt->execute();
$ticket_groups = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name FROM companies ORDER BY name");
$stmt->execute();
$companies = $stmt->fetchAll();

// Get ticket counts for filter tabs
$count_queries = [
    'unanswered' => "SELECT COUNT(*) FROM support_tickets st WHERE st.status = 'Open' AND st.assigned_to IS NULL",
    'unclosed' => "SELECT COUNT(*) FROM support_tickets st WHERE st.status != 'Closed'",
    'unassigned' => "SELECT COUNT(*) FROM support_tickets st WHERE st.assigned_to IS NULL",
    'assigned_to_me' => "SELECT COUNT(*) FROM support_tickets st WHERE st.assigned_to = ?",
    'pending_reply' => "
        SELECT COUNT(DISTINCT t.id) 
        FROM support_tickets t
        LEFT JOIN (
            SELECT str.ticket_id, str.user_id, str.created_at,
                   ROW_NUMBER() OVER (PARTITION BY str.ticket_id ORDER BY str.created_at DESC) as rn
            FROM support_ticket_replies str
        ) latest_reply ON t.id = latest_reply.ticket_id AND latest_reply.rn = 1
        LEFT JOIN users reply_user ON latest_reply.user_id = reply_user.id
        WHERE t.status != 'Closed' 
        AND t.assigned_to = ?
        AND (
            (latest_reply.user_id IS NOT NULL AND reply_user.role NOT IN ('administrator', 'support_user'))
            OR (latest_reply.user_id IS NULL AND t.user_id IS NOT NULL)
        )
    ",
    'all' => "SELECT COUNT(*) FROM support_tickets st"
];

$counts = [];
foreach ($count_queries as $key => $query) {
    $stmt = $pdo->prepare($query);
    if (in_array($key, ['assigned_to_me', 'pending_reply'])) {
        $stmt->execute([$user['id']]);
    } else {
        $stmt->execute();
    }
    $counts[$key] = $stmt->fetchColumn();
}

$page_title = "Staff Support Tickets | CaminhoIT";

// Function to get status badge class
function getStatusBadge($status) {
    switch(strtolower($status)) {
        case 'open':
            return 'status-open';
        case 'in progress':
            return 'status-progress';
        case 'closed':
            return 'status-closed';
        case 'pending':
        case 'awaiting response':
            return 'status-pending';
        default:
            return 'status-default';
    }
}

// Function to get priority badge class
function getPriorityBadge($priority) {
    switch(strtolower($priority)) {
        case 'high':
            return 'priority-high';
        case 'medium':
            return 'priority-medium';
        case 'low':
            return 'priority-low';
        default:
            return 'priority-normal';
    }
}

// Function to determine if ticket is pending user's reply
function isPendingReply($ticket, $current_user_id) {
    // If no replies exist, and ticket is assigned to current user, it's pending reply
    if ($ticket['reply_count'] == 0 && $ticket['assigned_to'] == $current_user_id) {
        return true;
    }
    
    // If last reply was from a customer (non-staff), it's pending staff reply
    if ($ticket['last_reply_user_id'] && 
        $ticket['last_reply_user_role'] && 
        !in_array($ticket['last_reply_user_role'], ['administrator', 'support_user']) &&
        $ticket['assigned_to'] == $current_user_id) {
        return true;
    }
    
    return false;
}
$page_title = "Staff Support Dashboard | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<style>
    :root {
        --primary-color: #667eea;
        --primary-hover: #5568d3;
        --success-color: #10B981;
        --warning-color: #F59E0B;
        --danger-color: #EF4444;
        --info-color: #06B6D4;
        --light-gray: #F8FAFC;
        --border-color: #E2E8F0;
        --text-muted: #64748B;
        --card-bg: #ffffff;
        --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1);
        --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
    }

    body {
        background: #F8FAFC;
    }

    /* Search Section */
    .search-section {
        background: white;
        padding: 1.5rem 0;
        box-shadow: var(--shadow);
        margin-bottom: 2rem;
    }

    .integrated-controls {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    /* Filter Tabs */
    .filter-tabs-row {
        border-bottom: 2px solid var(--border-color);
    }

    .filter-tabs {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        padding-bottom: 0;
    }

    .filter-tab {
        padding: 0.75rem 1.25rem;
        border-radius: 8px 8px 0 0;
        background: #f3f4f6;
        color: #6b7280;
        text-decoration: none;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        border: 1px solid transparent;
        border-bottom: none;
        white-space: nowrap;
        font-size: 0.875rem;
        font-weight: 500;
    }

    .filter-tab:hover {
        background: #e5e7eb;
        color: var(--primary-color);
        text-decoration: none;
    }

    .filter-tab.active {
        background: white;
        color: var(--primary-color);
        border-color: var(--border-color);
        border-bottom-color: white;
        font-weight: 600;
        position: relative;
        bottom: -2px;
    }

    .filter-tab .badge {
        background: var(--primary-color);
        color: white;
        padding: 0.2rem 0.5rem;
        border-radius: 10px;
        font-size: 0.7rem;
        min-width: 20px;
        text-align: center;
    }

    /* Search and Sort Row */
    .search-and-sort-row {
        display: flex;
        gap: 1rem;
        align-items: center;
        padding: 1rem 0 0.5rem;
        flex-wrap: wrap;
    }

    .search-wrapper {
        flex: 1;
        min-width: 250px;
        position: relative;
    }

    .search-icon {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
    }

    .search-input {
        width: 100%;
        padding: 0.75rem 1rem 0.75rem 2.75rem;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-size: 0.9375rem;
    }

    .sort-controls {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }

    .sort-select {
        padding: 0.75rem 1rem;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-size: 0.875rem;
    }

    /* Ticket Cards */
    .tickets-container {
        max-width: 1400px;
    }

    .ticket-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 1.25rem;
        transition: all 0.2s;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    .ticket-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        border-color: #cbd5e0;
    }

    .ticket-card.pending-reply {
        border-left: 4px solid var(--warning-color);
    }

    .ticket-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .customer-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .customer-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary-color), #764ba2);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 1rem;
    }

    .ticket-title {
        font-size: 1.125rem;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 0.5rem;
        line-height: 1.4;
    }

    .last-reply-info {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 0.75rem;
        background: #fef3c7;
        color: #92400e;
        border-radius: 6px;
        font-size: 0.8125rem;
        margin-top: 0.5rem;
    }

    .ticket-actions {
        display: flex;
        gap: 0.5rem;
        flex-shrink: 0;
    }

    .btn-modern {
        padding: 0.5rem 1rem;
        border-radius: 8px;
        border: none;
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
    }

    .btn-view {
        background: var(--primary-color);
        color: white;
    }

    .btn-view:hover {
        background: var(--primary-hover);
        transform: translateY(-1px);
    }

    .btn-edit {
        background: #f3f4f6;
        color: #374151;
        border: 1px solid #d1d5db;
    }

    .btn-edit:hover {
        background: #e5e7eb;
        border-color: #9ca3af;
    }

    .btn-assign {
        background: var(--success-color);
        color: white;
    }

    .btn-assign:hover {
        background: #059669;
    }

    .ticket-id {
        font-family: 'Monaco', 'Courier New', monospace;
        font-size: 0.875rem;
        font-weight: 600;
    }

    .status-badge {
        padding: 0.375rem 0.75rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: capitalize;
    }

    .status-badge.open {
        background: #dbeafe;
        color: #1e40af;
    }

    .status-badge.closed {
        background: #d1fae5;
        color: #065f46;
    }

    .status-badge.in-progress {
        background: #fef3c7;
        color: #92400e;
    }

    .priority-badge {
        padding: 0.375rem 0.75rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }

    .priority-high {
        background: #fee2e2;
        color: #991b1b;
    }

    .priority-medium {
        background: #fef3c7;
        color: #92400e;
    }

    .priority-low {
        background: #e0e7ff;
        color: #3730a3;
    }

    .ticket-meta-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid #f3f4f6;
    }

    .meta-item {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .meta-label {
        font-size: 0.75rem;
        color: #6b7280;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    .meta-value {
        font-size: 0.875rem;
        color: #1f2937;
        display: flex;
        align-items: center;
        gap: 0.375rem;
    }

    /* Modal Overlay */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(4px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .modal-overlay.show {
        display: flex !important;
    }

    .modal-content {
        background: white;
        border-radius: 16px;
        padding: 0;
        max-width: 500px;
        width: 100%;
        max-height: 90vh;
        overflow: hidden;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        animation: modalSlideIn 0.3s ease-out;
    }

    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .modal-header {
        padding: 1.5rem;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .modal-title {
        font-size: 1.25rem;
        font-weight: 600;
        color: #1f2937;
        margin: 0;
    }

    .modal-close {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: none;
        background: #f3f4f6;
        color: #6b7280;
        font-size: 1.5rem;
        line-height: 1;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .modal-close:hover {
        background: #e5e7eb;
        color: #374151;
    }

    .modal-overlay form {
        padding: 1.5rem;
        overflow-y: auto;
        max-height: calc(90vh - 130px);
    }

    .form-group {
        margin-bottom: 1.25rem;
    }

    .form-row {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
        margin-bottom: 1.25rem;
    }

    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        color: #374151;
        font-size: 0.875rem;
    }

    .form-select,
    .form-control {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 0.9375rem;
        transition: border-color 0.2s;
    }

    .form-select:focus,
    .form-control:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .modal-actions {
        display: flex;
        gap: 0.75rem;
        justify-content: flex-end;
        padding-top: 1rem;
        border-top: 1px solid #f3f4f6;
        margin-top: 1.5rem;
    }

    .btn-cancel {
        background: #f3f4f6;
        color: #374151;
        border: 1px solid #d1d5db;
    }

    .btn-cancel:hover {
        background: #e5e7eb;
    }

    .btn-save {
        background: var(--primary-color);
        color: white;
    }

    .btn-save:hover {
        background: var(--primary-hover);
    }

    .empty-state {
        text-align: center;
        padding: 3rem 1rem;
        color: #6b7280;
    }

    /* Clickable Links */
    .ticket-title-link {
        color: #1f2937;
        text-decoration: none;
        transition: color 0.2s;
    }

    .ticket-title-link:hover {
        color: var(--primary-color);
        text-decoration: none;
    }

    .ticket-id-link {
        text-decoration: none;
        transition: all 0.2s;
    }

    .ticket-id-link:hover {
        color: var(--primary-color) !important;
        text-decoration: underline;
    }

    .meta-link {
        color: #1f2937;
        text-decoration: none;
        transition: color 0.2s;
        font-weight: 500;
    }

    .meta-link:hover {
        color: var(--primary-color);
        text-decoration: underline;
    }

    /* DARK MODE */
    :root.dark {
        --light-gray: #1e293b;
        --border-color: #334155;
        --text-muted: #94a3b8;
        --card-bg: #1e293b;
    }

    :root.dark body {
        background: #0f172a;
    }

    :root.dark .search-section {
        background: #1e293b;
        border-bottom: 1px solid #334155;
    }

    :root.dark .filter-tab {
        background: #0f172a;
        color: #94a3b8;
    }

    :root.dark .filter-tab:hover {
        background: #1e293b;
        color: #a78bfa;
    }

    :root.dark .filter-tab.active {
        background: #1e293b;
        color: #a78bfa;
        border-color: #334155;
        border-bottom-color: #1e293b;
    }

    :root.dark .search-input,
    :root.dark .sort-select {
        background: #0f172a;
        border-color: #334155;
        color: #e2e8f0;
    }

    :root.dark .ticket-card {
        background: #1e293b;
        border-color: #334155;
    }

    :root.dark .ticket-card:hover {
        border-color: #475569;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    }

    :root.dark .ticket-title {
        color: #f1f5f9;
    }

    :root.dark .meta-label {
        color: #94a3b8;
    }

    :root.dark .meta-value {
        color: #e2e8f0;
    }

    :root.dark .ticket-meta-grid {
        border-top-color: #334155;
    }

    :root.dark .modal-content {
        background: #1e293b;
    }

    :root.dark .modal-header {
        border-bottom-color: #334155;
    }

    :root.dark .modal-title {
        color: #f1f5f9;
    }

    :root.dark .modal-close {
        background: #334155;
        color: #94a3b8;
    }

    :root.dark .modal-close:hover {
        background: #475569;
        color: #e2e8f0;
    }

    :root.dark .form-label {
        color: #e2e8f0;
    }

    :root.dark .form-select,
    :root.dark .form-control {
        background: #0f172a;
        border-color: #334155;
        color: #e2e8f0;
    }

    :root.dark .modal-actions {
        border-top-color: #334155;
    }

    :root.dark .btn-edit {
        background: #334155;
        color: #e2e8f0;
        border-color: #475569;
    }

    :root.dark .btn-edit:hover {
        background: #475569;
        border-color: #64748b;
    }

    :root.dark .btn-cancel {
        background: #334155;
        color: #e2e8f0;
        border-color: #475569;
    }

    :root.dark .btn-cancel:hover {
        background: #475569;
    }

    :root.dark .ticket-title-link {
        color: #f1f5f9;
    }

    :root.dark .ticket-title-link:hover {
        color: #a78bfa;
    }

    :root.dark .ticket-id-link:hover {
        color: #a78bfa !important;
    }

    :root.dark .meta-link {
        color: #e2e8f0;
    }

    :root.dark .meta-link:hover {
        color: #a78bfa;
    }

    /* Make submit buttons visible in dark mode */
    :root.dark .btn-modern[type="submit"],
    :root.dark button[type="submit"].btn-modern,
    :root.dark .btn-save {
        background: var(--primary-color);
        color: white;
    }

    :root.dark .btn-modern[type="submit"]:hover,
    :root.dark button[type="submit"].btn-modern:hover,
    :root.dark .btn-save:hover {
        background: var(--primary-hover);
    }

    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }
        .ticket-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .ticket-actions {
            width: 100%;
            justify-content: flex-start;
        }

        .ticket-meta-grid {
            grid-template-columns: 1fr;
        }

        .filter-tabs {
            overflow-x: scroll;
            -webkit-overflow-scrolling: touch;
        }

        .search-and-sort-row {
            flex-direction: column;
            align-items: stretch;
        }

        .search-wrapper {
            width: 100%;
        }

        .sort-controls {
            width: 100%;
            flex-wrap: wrap;
        }
    }
</style>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="dashboard-hero-content">
            <h1 class="dashboard-hero-title">
                <i class="bi bi-headset me-2"></i>
                Staff Support Dashboard
            </h1>
            <p class="dashboard-hero-subtitle">
                Manage and track all customer support tickets across the platform with real-time insights.
            </p>
            <div class="dashboard-hero-actions">
                <a href="/members/raise-ticket.php" class="btn c-btn-primary">
                    <i class="bi bi-plus-circle me-1"></i>
                    New Ticket
                </a>
                <a href="/operations/staff-analytics.php" class="btn c-btn-ghost">
                    <i class="bi bi-graph-up me-1"></i>
                    Analytics
                </a>
            </div>
        </div>
    </div>
</header>

<!-- Integrated Search and Filter Section -->
<div class="search-section">
    <div class="container">
        <div class="search-container">
            <div class="integrated-controls">
                <!-- Filter Tabs Row -->
                <div class="filter-tabs-row">
                    <div class="filter-tabs">
                        <a href="?status=unassigned&search=<?= urlencode($search) ?>&sort=<?= $sort_by ?>&order=<?= $sort_order ?>" 
                           class="filter-tab <?= $status_filter === 'unassigned' ? 'active' : '' ?>">
                            Awaiting Assignment
                            <span class="badge"><?= $counts['unassigned'] ?></span>
                        </a>
                        <a href="?status=pending_reply&search=<?= urlencode($search) ?>&sort=<?= $sort_by ?>&order=<?= $sort_order ?>" 
                           class="filter-tab <?= $status_filter === 'pending_reply' ? 'active' : '' ?>">
                            Pending Your Reply
                            <span class="badge"><?= $counts['pending_reply'] ?></span>
                        </a>
                        <a href="?status=unanswered&search=<?= urlencode($search) ?>&sort=<?= $sort_by ?>&order=<?= $sort_order ?>" 
                           class="filter-tab <?= $status_filter === 'unanswered' ? 'active' : '' ?>">
                            Unanswered
                            <span class="badge"><?= $counts['unanswered'] ?></span>
                        </a>
                        <a href="?status=unclosed&search=<?= urlencode($search) ?>&sort=<?= $sort_by ?>&order=<?= $sort_order ?>" 
                           class="filter-tab <?= $status_filter === 'unclosed' ? 'active' : '' ?>">
                            Unclosed
                            <span class="badge"><?= $counts['unclosed'] ?></span>
                        </a>
                        <a href="?status=assigned_to_me&search=<?= urlencode($search) ?>&sort=<?= $sort_by ?>&order=<?= $sort_order ?>" 
                           class="filter-tab <?= $status_filter === 'assigned_to_me' ? 'active' : '' ?>">
                            Assigned to you
                            <span class="badge"><?= $counts['assigned_to_me'] ?></span>
                        </a>
                        <a href="?status=all&search=<?= urlencode($search) ?>&sort=<?= $sort_by ?>&order=<?= $sort_order ?>" 
                           class="filter-tab <?= $status_filter === 'all' ? 'active' : '' ?>">
                            All
                            <span class="badge"><?= $counts['all'] ?></span>
                        </a>
                    </div>
                </div>

                <!-- Search and Sort Row -->
                <form method="GET" class="search-and-sort-row">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                    
                    <div class="search-wrapper">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" name="search" class="search-input" 
                               placeholder="Search by ticket ID, subject, customer, or company..." 
                               value="<?= htmlspecialchars($search) ?>">
                    </div>
                    
                    <div class="sort-controls">
                        <select name="sort" class="sort-select">
                            <option value="created_at" <?= $sort_by === 'created_at' ? 'selected' : '' ?>>Date Created</option>
                            <option value="updated_at" <?= $sort_by === 'updated_at' ? 'selected' : '' ?>>Last Updated</option>
                            <option value="status" <?= $sort_by === 'status' ? 'selected' : '' ?>>Status</option>
                            <option value="priority" <?= $sort_by === 'priority' ? 'selected' : '' ?>>Priority</option>
                        </select>
                        
                        <select name="order" class="sort-select">
                            <option value="DESC" <?= $sort_order === 'DESC' ? 'selected' : '' ?>>Newest First</option>
                            <option value="ASC" <?= $sort_order === 'ASC' ? 'selected' : '' ?>>Oldest First</option>
                        </select>
                        
                        <button type="submit" class="btn btn-modern btn-view">
                            <i class="bi bi-search me-1"></i>Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="container tickets-container py-5">
    <!-- Tickets List -->
    <div id="ticketsList">
        <?php if (empty($tickets)): ?>
            <div class="empty-state">
                <i class="bi bi-ticket-perforated" style="font-size: 3rem; color: var(--text-muted);"></i>
                <h3 class="h4 mb-2 mt-3">No tickets found</h3>
                <p class="text-muted mb-4">No tickets match your current filter criteria.</p>
            </div>
        <?php else: ?>
            <?php foreach ($tickets as $ticket): ?>
                <?php $is_pending_reply = isPendingReply($ticket, $user['id']); ?>
                <div class="ticket-card <?= $is_pending_reply ? 'pending-reply' : '' ?>" data-ticket-id="<?= $ticket['id'] ?>">
                    <div class="ticket-header">
                        <div class="flex-grow-1">
                            <div class="customer-info">
                                <div class="customer-avatar">
                                    <?= strtoupper(substr($ticket['customer_username'] ?? 'U', 0, 1)) ?>
                                </div>
                                <div>
                                    <strong><?= htmlspecialchars($ticket['customer_username'] ?? 'Unknown User') ?></strong>
                                    <?php if ($ticket['company_name']): ?>
                                        <span class="text-muted">from <?= htmlspecialchars($ticket['company_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">No company assigned</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <h3 class="ticket-title">
                                <a href="staff-view-ticket.php?id=<?= $ticket['id'] ?>" class="ticket-title-link">
                                    <?= htmlspecialchars($ticket['subject']) ?>
                                </a>
                            </h3>
                            
                            <?php if ($is_pending_reply): ?>
                                <div class="last-reply-info">
                                    <i class="bi bi-clock me-1"></i>
                                    <?php if ($ticket['last_reply_username']): ?>
                                        Customer <?= htmlspecialchars($ticket['last_reply_username']) ?> replied 
                                        <?= date('M j, Y g:i A', strtotime($ticket['last_reply_at'])) ?> - Awaiting your response
                                    <?php else: ?>
                                        New ticket from customer - Awaiting your initial response
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="ticket-actions">
                            <a href="staff-view-ticket.php?id=<?= $ticket['id'] ?>" class="btn btn-modern btn-view">
                                <i class="bi bi-eye me-1"></i>View
                            </a>
                            <button type="button" class="btn btn-modern btn-edit edit-ticket-btn" data-ticket-id="<?= $ticket['id'] ?>">
                                <i class="bi bi-pencil me-1"></i>Edit
                            </button>
                            <?php if (!$ticket['assigned_to']): ?>
                                <button type="button" class="btn btn-modern btn-assign assign-ticket-btn" data-ticket-id="<?= $ticket['id'] ?>">
                                    <i class="bi bi-person-plus me-1"></i>Assign
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-3 mb-3">
                        <a href="staff-view-ticket.php?id=<?= $ticket['id'] ?>" class="ticket-id text-muted ticket-id-link">
                            #<?= $ticket['id'] ?>
                        </a>
                        <span class="status-badge <?= getStatusBadge($ticket['status']) ?>">
                            <?= htmlspecialchars($ticket['status']) ?>
                        </span>
                        <span class="priority-badge <?= getPriorityBadge($ticket['priority']) ?>">
                            <i class="bi bi-flag"></i>
                            <?= htmlspecialchars($ticket['priority'] ?: 'Normal') ?>
                        </span>
                    </div>

                    <div class="ticket-meta-grid">
                        <div class="meta-item">
                            <span class="meta-label">Customer Email</span>
                            <span class="meta-value">
                                <i class="bi bi-envelope me-1"></i>
                                <?= htmlspecialchars($ticket['customer_email'] ?? 'N/A') ?>
                            </span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Company</span>
                            <span class="meta-value">
                                <?php if ($ticket['company_name']): ?>
                                    <i class="bi bi-building me-1"></i>
                                    <a href="/operations/manage-companies.php?id=<?= $ticket['company_id'] ?>" class="meta-link">
                                        <?= htmlspecialchars($ticket['company_name']) ?>
                                    </a>
                                <?php else: ?>
                                    <i class="bi bi-building me-1 text-muted"></i>
                                    <span class="text-muted">None</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Assigned To</span>
                            <span class="meta-value assigned-to-display">
                                <?php if ($ticket['assigned_to_username']): ?>
                                    <i class="bi bi-person-check me-1"></i>
                                    <a href="/operations/manage-users.php?id=<?= $ticket['assigned_to'] ?>" class="meta-link">
                                        <?= htmlspecialchars($ticket['assigned_to_username']) ?>
                                    </a>
                                <?php else: ?>
                                    <i class="bi bi-person-dash me-1 text-muted"></i>
                                    <span class="text-muted">Unassigned</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Created</span>
                            <span class="meta-value">
                                <i class="bi bi-calendar me-1"></i>
                                <?= date('M j, Y g:i A', strtotime($ticket['created_at'])) ?>
                            </span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Last Updated</span>
                            <span class="meta-value">
                                <i class="bi bi-clock me-1"></i>
                                <?= $ticket['updated_at'] ? date('M j, Y g:i A', strtotime($ticket['updated_at'])) : 'Never' ?>
                            </span>
                        </div>
                        <?php if ($ticket['group_name']): ?>
                        <div class="meta-item">
                            <span class="meta-label">Department</span>
                            <span class="meta-value">
                                <i class="bi bi-collection me-1"></i>
                                <?= htmlspecialchars($ticket['group_name']) ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="meta-item">
                            <span class="meta-label">Replies</span>
                            <span class="meta-value">
                                <i class="bi bi-chat-dots me-1"></i>
                                <?= $ticket['reply_count'] ?> replies
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Assignment Modal -->
<div class="modal-overlay" id="assignModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Assign Ticket</h3>
            <button type="button" class="modal-close" onclick="closeAssignModal()">×</button>
        </div>
        <form id="assignTicketForm">
            <input type="hidden" id="assignTicketId" name="ticket_id">
            <div class="form-group">
                <label class="form-label">Assign to:</label>
                <select name="assigned_to" class="form-select" required>
                    <option value="">Select a staff member...</option>
                    <?php foreach ($staff_users as $staff): ?>
                        <option value="<?= $staff['id'] ?>"><?= htmlspecialchars($staff['username']) ?> (<?= htmlspecialchars($staff['email']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-modern btn-cancel" onclick="closeAssignModal()">Cancel</button>
                <button type="submit" class="btn btn-modern btn-assign">Assign Ticket</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Ticket Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Edit Ticket</h3>
            <button type="button" class="modal-close" onclick="closeEditModal()">×</button>
        </div>
        <form id="editTicketForm">
            <input type="hidden" id="editTicketId" name="ticket_id">
            
            <div class="form-group">
                <label class="form-label">Subject:</label>
                <input type="text" name="subject" id="editSubject" class="form-control" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Status:</label>
                    <select name="status" id="editStatus" class="form-select">
                        <option value="Open">Open</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Pending">Pending</option>
                        <option value="Awaiting Response">Awaiting Response</option>
                        <option value="Closed">Closed</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Priority:</label>
                    <select name="priority" id="editPriority" class="form-select">
                        <option value="Low">Low</option>
                        <option value="Normal">Normal</option>
                        <option value="Medium">Medium</option>
                        <option value="High">High</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Customer:</label>
                    <select name="user_id" id="editCustomer" class="form-select">
                        <option value="">Select customer...</option>
                        <?php foreach ($all_users as $customer): ?>
                            <option value="<?= $customer['id'] ?>" data-company="<?= $customer['company_id'] ?>">
                                <?= htmlspecialchars($customer['username']) ?> (<?= htmlspecialchars($customer['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Assigned Agent:</label>
                    <select name="assigned_to" id="editAgent" class="form-select">
                        <option value="">Unassigned</option>
                        <?php foreach ($staff_users as $staff): ?>
                            <option value="<?= $staff['id'] ?>"><?= htmlspecialchars($staff['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Department:</label>
                <select name="group_id" id="editDepartment" class="form-select">
                    <option value="">No department</option>
                    <?php foreach ($ticket_groups as $group): ?>
                        <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div id="customerCompanyInfo" class="company-info" style="display: none;">
                Company: <span id="companyName"></span>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn btn-modern btn-cancel" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn btn-modern btn-save">
                    <i class="bi bi-check-circle me-1"></i>Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Toast Notification -->
<div class="toast" id="toast"></div>

<script>
// Global variables
const companies = <?= json_encode($companies) ?>;

// Toast notification function
function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = `toast ${type}`;
    toast.style.display = 'block';
    
    setTimeout(() => {
        toast.style.display = 'none';
    }, 4000);
}

// Assignment Modal Functions
function openAssignModal(ticketId) {
    document.getElementById('assignTicketId').value = ticketId;
    document.getElementById('assignModal').style.display = 'flex';
}

function closeAssignModal() {
    document.getElementById('assignModal').style.display = 'none';
    document.getElementById('assignTicketForm').reset();
}

// Edit Modal Functions
function openEditModal(ticketId) {
    // Fetch ticket details
    const formData = new FormData();
    formData.append('action', 'get_ticket_details');
    formData.append('ticket_id', ticketId);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const ticket = data.ticket;
            
            document.getElementById('editTicketId').value = ticketId;
            document.getElementById('editSubject').value = ticket.subject;
            document.getElementById('editStatus').value = ticket.status;
            document.getElementById('editPriority').value = ticket.priority || 'Normal';
            document.getElementById('editCustomer').value = ticket.user_id;
            document.getElementById('editAgent').value = ticket.assigned_to || '';
            document.getElementById('editDepartment').value = ticket.group_id || '';
            
            // Show company info if customer has one
            updateCompanyInfo(ticket.user_id);
            
            document.getElementById('editModal').style.display = 'flex';
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Failed to load ticket details', 'error');
    });
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    document.getElementById('editTicketForm').reset();
    document.getElementById('customerCompanyInfo').style.display = 'none';
}

function updateCompanyInfo(userId) {
    const customerSelect = document.getElementById('editCustomer');
    const selectedOption = customerSelect.querySelector(`option[value="${userId}"]`);
    const companyInfo = document.getElementById('customerCompanyInfo');
    const companyName = document.getElementById('companyName');
    
    if (selectedOption && selectedOption.dataset.company) {
        const companyId = selectedOption.dataset.company;
        const company = companies.find(c => c.id == companyId);
        
        if (company) {
            companyName.textContent = company.name;
            companyInfo.style.display = 'block';
        } else {
            companyInfo.style.display = 'none';
        }
    } else {
        companyInfo.style.display = 'none';
    }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // Animate cards on load
    const cards = document.querySelectorAll('.ticket-card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 50);
    });

    // Auto-submit form on sort change
    document.querySelectorAll('.sort-select').forEach(select => {
        select.addEventListener('change', function() {
            this.closest('form').submit();
        });
    });

    // Assign button click handlers
    document.querySelectorAll('.assign-ticket-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const ticketId = this.dataset.ticketId;
            openAssignModal(ticketId);
        });
    });

    // Edit button click handlers
    document.querySelectorAll('.edit-ticket-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const ticketId = this.dataset.ticketId;
            openEditModal(ticketId);
        });
    });

    // Customer selection change
    document.getElementById('editCustomer').addEventListener('change', function() {
        updateCompanyInfo(this.value);
    });

    // Assignment form submission
    document.getElementById('assignTicketForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('action', 'assign_ticket');
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                closeAssignModal();
                showToast(data.message);
                
                // Update the ticket card
                const ticketId = formData.get('ticket_id');
                const assignedTo = formData.get('assigned_to');
                const assignedSelect = document.querySelector(`select[name="assigned_to"] option[value="${assignedTo}"]`);
                const assignedName = assignedSelect ? assignedSelect.textContent.split(' (')[0] : 'Assigned';
                
                const ticketCard = document.querySelector(`[data-ticket-id="${ticketId}"]`);
                if (ticketCard) {
                    const assignedDisplay = ticketCard.querySelector('.assigned-to-display');
                    assignedDisplay.innerHTML = `<i class="bi bi-person-check me-1"></i>${assignedName}`;
                    
                    const assignBtn = ticketCard.querySelector('.assign-ticket-btn');
                    if (assignBtn) {
                        assignBtn.remove();
                    }
                }
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('An error occurred while assigning the ticket', 'error');
        });
    });

    // Edit form submission
    document.getElementById('editTicketForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('action', 'update_ticket');
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                closeEditModal();
                showToast(data.message);
                
                // Refresh the page to show updated data
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('An error occurred while updating the ticket', 'error');
        });
    });

    // Close modals when clicking outside
    document.getElementById('assignModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAssignModal();
        }
    });

    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeEditModal();
        }
    });
});

// Close modals with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAssignModal();
        closeEditModal();
    }
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>
