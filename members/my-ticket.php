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

$user_id = $user['id'];

// Fetch user profile
$stmt = $pdo->prepare("
    SELECT u.*, c.name AS company_name
    FROM users u
    LEFT JOIN companies c ON u.company_id = c.id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$profile = $stmt->fetch();

// Fetch user's tickets with necessary data
$stmt = $pdo->prepare("
    SELECT st.*, 
           tg.name AS group_name, 
           assigned.username AS assigned_to_username, 
           updated.username AS updated_by_username
    FROM support_tickets st
    LEFT JOIN support_ticket_groups tg ON st.group_id = tg.id
    LEFT JOIN users assigned ON st.assigned_to = assigned.id
    LEFT JOIN users updated ON st.updated_by = updated.id
    WHERE st.user_id = ?
    ORDER BY st.created_at DESC
");
$stmt->execute([$user_id]);
$tickets = $stmt->fetchAll();

$page_title = "My Support Tickets | CaminhoIT";

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
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>

<style>
        /* Search Section */
        .search-container {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 2rem;
            margin-top: -5rem;
            position: relative;
            z-index: 10;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .search-wrapper {
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            border: 1px solid #D1D5DB;
            border-radius: 12px;
            font-size: 0.875rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: white;
            color: #1F2937;
            box-shadow: inset 0 1px 2px 0 rgb(0 0 0 / 0.05);
        }

        .search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1), 0 1px 3px 0 rgb(0 0 0 / 0.1);
            transform: translateY(-1px);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6B7280;
            font-size: 1rem;
        }

        /* Ticket Cards */
        .ticket-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(0,0,0,0.05);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .ticket-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .ticket-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .ticket-title-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .ticket-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1F2937;
            margin: 0;
        }

        .ticket-title-link {
            color: #1F2937;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-block;
        }

        .ticket-title-link:hover {
            color: #667eea;
            text-decoration: none;
            transform: translateX(3px);
        }

        .ticket-actions {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .ticket-meta-container {
            display: flex;
            align-items: flex-start;
            gap: 2rem;
        }

        .ticket-id-container {
            display: flex;
            flex-direction: column;
            min-width: 140px;
        }

        .ticket-id {
            font-size: 0.875rem;
            color: #6c757d;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        .ticket-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            flex: 1;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 0.75rem;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .meta-value {
            font-size: 0.875rem;
            color: #374151;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Status and Priority Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.875rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-open {
            background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
            color: #1E40AF;
        }

        .status-progress {
            background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
            color: #92400E;
        }

        .status-closed {
            background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
            color: #065F46;
        }

        .status-pending {
            background: linear-gradient(135deg, #F3F4F6 0%, #E5E7EB 100%);
            color: #374151;
        }

        .priority-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.875rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .priority-normal {
            background: linear-gradient(135deg, #F3F4F6 0%, #E5E7EB 100%);
            color: #6B7280;
        }

        /* Buttons */
        .btn-enhanced {
            border-radius: 50px;
            padding: 0.5rem 1.25rem;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            position: relative;
            overflow: hidden;
            font-size: 0.875rem;
        }

        .btn-view {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-view:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .btn-close {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }

        .btn-close:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
            color: white;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .empty-state::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .empty-state-icon {
            width: 5rem;
            height: 5rem;
            margin: 0 auto 1.5rem;
            color: #6c757d;
            opacity: 0.6;
        }

        /* Button link styling */
        .c-btn-primary {
            text-decoration: none !important;
        }

        .c-btn-primary:hover {
            text-decoration: none !important;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .ticket-title-section {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }

            .ticket-meta-container {
                flex-direction: column;
                gap: 1rem;
            }

            .ticket-meta {
                grid-template-columns: 1fr;
            }

            .search-container {
                margin-top: -3rem;
                padding: 1.5rem;
            }
        }

        /* Dark Mode Styles */
        :root.dark .search-container {
            background: rgba(30, 41, 59, 0.95);
            border-color: rgba(71, 85, 105, 0.4);
        }

        :root.dark .search-input {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }

        :root.dark .search-input:focus {
            background: #1e293b;
            border-color: #667eea;
        }

        :root.dark .search-input::placeholder {
            color: #64748b;
        }

        :root.dark .search-icon {
            color: #94a3b8;
        }

        :root.dark .ticket-card {
            background: #1e293b;
            border-color: #334155;
        }

        :root.dark .ticket-card:hover {
            background: #1e293b;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
        }

        :root.dark .ticket-title {
            color: #f1f5f9;
        }

        :root.dark .ticket-title-link {
            color: #f1f5f9;
        }

        :root.dark .ticket-title-link:hover {
            color: #a78bfa;
        }

        :root.dark .ticket-id {
            color: #94a3b8;
        }

        :root.dark .meta-label {
            color: #94a3b8;
        }

        :root.dark .meta-value {
            color: #cbd5e1;
        }

        :root.dark .status-open {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: #bfdbfe;
        }

        :root.dark .status-progress {
            background: linear-gradient(135deg, #92400e 0%, #b45309 100%);
            color: #fde68a;
        }

        :root.dark .status-closed {
            background: linear-gradient(135deg, #065f46 0%, #047857 100%);
            color: #a7f3d0;
        }

        :root.dark .status-pending {
            background: linear-gradient(135deg, #334155 0%, #475569 100%);
            color: #cbd5e1;
        }

        :root.dark .priority-normal {
            background: linear-gradient(135deg, #334155 0%, #475569 100%);
            color: #cbd5e1;
        }

        :root.dark .empty-state {
            background: #1e293b;
            border-color: #334155;
        }

        :root.dark .empty-state h3 {
            color: #f1f5f9;
        }

        :root.dark .empty-state p {
            color: #94a3b8;
        }

        :root.dark .empty-state-icon {
            color: #64748b;
        }

        :root.dark .breadcrumb-enhanced {
            background: #1e293b;
            border-color: #334155;
        }

        :root.dark .breadcrumb-item a {
            color: #94a3b8;
        }

        :root.dark .breadcrumb-item.active {
            color: #cbd5e1;
        }

        :root.dark .text-muted {
            color: #94a3b8 !important;
        }
    </style>

<!-- Hero Section -->
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="my-ticket-hero-content">
            <h1 class="my-ticket-hero-title text-white">
                <i class="bi bi-ticket-perforated me-3"></i>
                My Support Tickets
            </h1>
            <p class="my-ticket-hero-subtitle text-white">
                View and manage your submitted support tickets and their current status. Track progress and get updates on your requests.
            </p>
            <div class="my-ticket-hero-actions">
                <a href="raise-ticket.php" class="c-btn-primary" style="text-decoration: none;">
                    <i class="bi bi-plus-circle"></i>
                    Create New Ticket
                </a>
            </div>
        </div>
    </div>
</header>

<div class="container py-5 content-overlap">
    
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="breadcrumb-enhanced fade-in">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item active" aria-current="page">My Tickets</li>
        </ol>
    </nav>

    <!-- Search Section -->
    <div class="search-container fade-in">
        <div class="search-wrapper">
            <i class="bi bi-search search-icon"></i>
            <input type="text" class="search-input" id="ticketSearch" placeholder="Search tickets by ID or subject...">
        </div>
    </div>

    <!-- Tickets Section -->
    <div class="content-section fade-in">
        <div id="ticketsList">
            <?php if (empty($tickets)): ?>
                <div class="empty-state">
                    <i class="bi bi-ticket-perforated empty-state-icon"></i>
                    <h3 class="h4 mb-3">No tickets found</h3>
                    <p class="text-muted mb-4">You haven't submitted any support tickets yet. Create your first ticket to get started.</p>
                    <a href="raise-ticket.php" class="c-btn-primary" style="text-decoration: none;">
                        <i class="bi bi-plus-circle"></i>
                        Create Your First Ticket
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($tickets as $ticket): ?>
                    <div class="ticket-card fade-in" data-ticket-id="<?= $ticket['id']; ?>" data-subject="<?= strtolower(htmlspecialchars($ticket['subject'])); ?>">
                        <div class="ticket-title-section">
                            <h3 class="ticket-title">
                                <a href="view-ticket.php?id=<?= $ticket['id']; ?>" class="ticket-title-link">
                                    <?= htmlspecialchars($ticket['subject']); ?>
                                </a>
                            </h3>
                            <div class="ticket-actions">
                                <a href="view-ticket.php?id=<?= $ticket['id']; ?>" class="btn btn-enhanced btn-view">
                                    <i class="bi bi-eye me-1"></i>View Details
                                </a>
                                <?php if($ticket['status'] !== 'Closed'): ?>
                                    <button type="button" class="btn btn-enhanced btn-close" onclick="closeTicket(<?= $ticket['id']; ?>)">
                                        <i class="bi bi-x-circle me-1"></i>Close
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="ticket-meta-container">
                            <div class="ticket-id-container">
                                <div class="ticket-id">Ticket #<?= htmlspecialchars($ticket['id']); ?></div>
                                <span class="status-badge <?= getStatusBadge($ticket['status']); ?>">
                                    <?= htmlspecialchars($ticket['status']); ?>
                                </span>
                            </div>

                            <div class="ticket-meta">
                                <div class="meta-item">
                                    <span class="meta-label">Priority</span>
                                    <span class="meta-value">
                                        <span class="priority-badge priority-normal">
                                            <i class="bi bi-flag"></i>
                                            Normal
                                        </span>
                                    </span>
                                </div>
                                <div class="meta-item">
                                    <span class="meta-label">Assigned To</span>
                                    <span class="meta-value">
                                        <?php if ($ticket['assigned_to_username']): ?>
                                            <i class="bi bi-person-check"></i>
                                            <?= htmlspecialchars($ticket['assigned_to_username']); ?>
                                        <?php else: ?>
                                            <i class="bi bi-person-dash text-muted"></i>
                                            <span class="text-muted">Unassigned</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="meta-item">
                                    <span class="meta-label">Created</span>
                                    <span class="meta-value">
                                        <i class="bi bi-calendar"></i>
                                        <?= date('M j, Y g:i A', strtotime($ticket['created_at'])); ?>
                                    </span>
                                </div>
                                <div class="meta-item">
                                    <span class="meta-label">Last Updated</span>
                                    <span class="meta-value">
                                        <i class="bi bi-clock"></i>
                                        <?= $ticket['updated_at'] ? date('M j, Y g:i A', strtotime($ticket['updated_at'])) : 'Never'; ?>
                                    </span>
                                </div>
                                <?php if ($ticket['updated_by_username']): ?>
                                <div class="meta-item">
                                    <span class="meta-label">Last Updated By</span>
                                    <span class="meta-value">
                                        <i class="bi bi-person"></i>
                                        <?= htmlspecialchars($ticket['updated_by_username']); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Enhanced scroll animations
document.addEventListener('DOMContentLoaded', function() {
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

    // Search functionality
    document.getElementById('ticketSearch').addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const tickets = document.querySelectorAll('.ticket-card');
        
        tickets.forEach(ticket => {
            const ticketId = ticket.dataset.ticketId;
            const subject = ticket.dataset.subject;
            
            if (ticketId.includes(searchTerm) || subject.includes(searchTerm)) {
                ticket.style.display = 'block';
            } else {
                ticket.style.display = 'none';
            }
        });
    });

    // Animate cards on load
    const cards = document.querySelectorAll('.ticket-card');
    cards.forEach((card, index) => {
        setTimeout(() => {
            card.classList.add('visible');
        }, index * 100);
    });
});

// Close ticket function
function closeTicket(ticketId) {
    if (confirm('Are you sure you want to close this ticket? This action cannot be undone.')) {
        // Show loading state
        const btn = event.target.closest('button');
        const originalContent = btn.innerHTML;
        btn.innerHTML = '<i class="spinner-border spinner-border-sm me-1"></i>Closing...';
        btn.disabled = true;
        
        // Redirect to close ticket page
        window.location.href = `close-ticket.php?id=${ticketId}`;
    }
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-v2.php'; ?>
