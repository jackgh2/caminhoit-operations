<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$username = $_SESSION['user']['username'] ?? 'User';
$role = $_SESSION['user']['role'] ?? null;
$lang = $lang ?? 'en';

// Get ticket counts for admin sidebar (only if user is administrator)
$sidebar_ticket_counts = [];
if ($role === 'administrator') {
    // Only load database connection if not already loaded
    if (!isset($pdo)) {
        try {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
        } catch (Exception $e) {
            // If config fails to load, set empty counts
            $sidebar_ticket_counts = [
                'unassigned' => 0,
                'pending_reply' => 0,
                'unanswered' => 0,
                'unclosed' => 0,
                'assigned_to_me' => 0,
                'all' => 0
            ];
        }
    }
    
    if (isset($pdo)) {
        try {
            $sidebar_count_queries = [
                'unassigned' => "SELECT COUNT(*) FROM support_tickets st WHERE st.assigned_to IS NULL",
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
                'unanswered' => "SELECT COUNT(*) FROM support_tickets st WHERE st.status = 'Open' AND st.assigned_to IS NULL",
                'unclosed' => "SELECT COUNT(*) FROM support_tickets st WHERE st.status != 'Closed'",
                'assigned_to_me' => "SELECT COUNT(*) FROM support_tickets st WHERE st.assigned_to = ?",
                'all' => "SELECT COUNT(*) FROM support_tickets st"
            ];

            foreach ($sidebar_count_queries as $key => $query) {
                $sidebar_stmt = $pdo->prepare($query);
                if (in_array($key, ['assigned_to_me', 'pending_reply'])) {
                    $sidebar_stmt->execute([$_SESSION['user']['id']]);
                } else {
                    $sidebar_stmt->execute();
                }
                $sidebar_ticket_counts[$key] = $sidebar_stmt->fetchColumn();
            }
        } catch (Exception $e) {
            // If queries fail, set empty counts
            $sidebar_ticket_counts = [
                'unassigned' => 0,
                'pending_reply' => 0,
                'unanswered' => 0,
                'unclosed' => 0,
                'assigned_to_me' => 0,
                'all' => 0
            ];
        }
    }
}
?>
<nav class="navbar navbar-expand-lg navbar-dark fixed-top bg-transparent">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="/">
            <img src="/assets/logo.png" alt="CaminhoIT Icon" style="height:35px;">
            <span class="logo-text-nav">
                CAMINHO<span class="it">IT</span>
            </span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse justify-content-end" id="mainNav">
            <ul class="navbar-nav align-items-center gap-3">

                <!-- About and Blog -->
                <li class="nav-item"><a class="nav-link text-white" href="../index.php">Homepage</a></li>
                <li class="nav-item"><a class="nav-link text-white" href="/members/dashboard.php">Dashboard</a></li>

                <!-- Support Dropdown -->
                <?php if (in_array($role, ['supported_user', 'account_manager', 'support_consultant', 'accountant', 'administrator'])): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-white" href="#" id="supportDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Support
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="/members/raise-ticket.php">Raise Support Request</a></li>
                            <li><a class="dropdown-item" href="/members/my-ticket.php">My Support Requests</a></li>
                        </ul>
                    </li>
                <?php endif; ?>
                <!-- Services Dropdown -->
                <?php if (in_array($role, ['account_manager', 'administrator'])): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-white" href="#" id="supportDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Services
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="/members/my-services.php">My Services</a></li>
                            <li><a class="dropdown-item" href="/members/manage-services.php">Manage Allocation</a></li>
                            <li><a class="dropdown-item" href="/members/service-catalog.php">Service Catalog</a></li>
                            <li><a class="dropdown-item" href="/members/create-order.php">Place Order</a></li>
                            <li><a class="dropdown-item" href="/members/orders.php">Orders</a></li>
                            <li><a class="dropdown-item" href="/members/quotes.php">Quotes</a></li>
                            <li><a class="dropdown-item" href="/members/company-info.php">Company Management</a></li>
                        </ul>
                    </li>
                <?php endif; ?>
                <!-- Support Dropdown -->
                <?php if (in_array($role, ['supported_user', 'support_consultant', 'accountant'])): ?>
                    <li class="nav-item"><a class="nav-link text-white" href="/members/my-services.php">My Services</a></li>
                <?php endif; ?>
                
                <!-- Language Switcher -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white" href="#" id="langDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        🌍 <?= strtoupper($lang); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="?lang=en">English</a></li>
                        <li><a class="dropdown-item" href="?lang=pt">Português</a></li>
                    </ul>
                </li>

                <!-- Authenticated User Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle btn btn-outline-light rounded-pill px-4 py-1" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?= htmlspecialchars($username); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="/members/dashboard.php">Dashboard</a></li>
                        <li><a class="dropdown-item" href="/members/account.php">My Account</a></li>
                        <li><a class="dropdown-item" href="/members/raise-ticket.php">Support Tickets</a></li>
                        <li><a class="dropdown-item" href="/members/view-invoices.php">Invoices</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="/logout.php">Logout</a></li>
                    </ul>
                </li>

            </ul>
        </div>
    </div>
</nav>

<!-- Administrator Left Sidebar -->
<?php if ($role === 'administrator'): ?>
<div class="admin-sidebar" id="adminSidebar">
    <div class="sidebar-header">
        <h5><i class="bi bi-gear-fill me-2"></i>Admin Panel</h5>
        <button class="btn btn-sm btn-outline-secondary d-md-none" onclick="toggleSidebar()">
            <i class="bi bi-x"></i>
        </button>
    </div>
    <nav class="sidebar-nav">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link" href="/operations/staff-analytics.php">
                    <i class="bi bi-graph-up me-2"></i>Ticket Analytics
                </a>
            </li>
            
            <!-- Tickets Section Header -->
            <li class="nav-item">
                <a class="nav-link tickets-header" href="/operations/staff-tickets.php">
                    <i class="bi bi-ticket me-2"></i>Tickets
                    <i class="bi bi-chevron-up ms-auto"></i>
                </a>
            </li>
            
            <!-- Always Expanded Tickets Submenu -->
            <li class="nav-item">
                <a class="nav-link submenu-link" href="/operations/staff-tickets.php?status=unassigned">
                    <i class="bi bi-person-dash me-2"></i>Awaiting Assignment
                    <span class="badge bg-primary ms-auto"><?= $sidebar_ticket_counts['unassigned'] ?? 0 ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link submenu-link" href="/operations/staff-tickets.php?status=pending_reply">
                    <i class="bi bi-reply me-2"></i>Pending Your Reply
                    <span class="badge bg-warning ms-auto"><?= $sidebar_ticket_counts['pending_reply'] ?? 0 ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link submenu-link" href="/operations/staff-tickets.php?status=unanswered">
                    <i class="bi bi-question-circle me-2"></i>Unanswered
                    <span class="badge bg-danger ms-auto"><?= $sidebar_ticket_counts['unanswered'] ?? 0 ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link submenu-link" href="/operations/staff-tickets.php?status=unclosed">
                    <i class="bi bi-unlock me-2"></i>Unclosed
                    <span class="badge bg-info ms-auto"><?= $sidebar_ticket_counts['unclosed'] ?? 0 ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link submenu-link" href="/operations/staff-tickets.php?status=assigned_to_me">
                    <i class="bi bi-person-check me-2"></i>Assigned to you
                    <span class="badge bg-success ms-auto"><?= $sidebar_ticket_counts['assigned_to_me'] ?? 0 ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link submenu-link" href="/operations/staff-tickets.php?status=all">
                    <i class="bi bi-list-ul me-2"></i>All
                    <span class="badge bg-secondary ms-auto"><?= $sidebar_ticket_counts['all'] ?? 0 ?></span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="/operations/manage-companies.php">
                    <i class="bi bi-building me-2"></i>Manage Companies
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="/operations/manage-users.php">
                    <i class="bi bi-people me-2"></i>Manage Users
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="/operations/manage-groups.php">
                    <i class="bi bi-people-fill me-2"></i>Manage Support Groups
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="/operations/service-catalog.php">
                    <i class="bi bi-list-ul me-2"></i>Service Catalog
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="/operations/product-assignments.php">
                    <i class="bi bi-box me-2"></i>Product Assignments
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="/operations/orders.php">
                    <i class="bi bi-cart me-2"></i>Orders
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="/operations/create-order.php">
                    <i class="bi bi-plus-circle me-2"></i>Create Order
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="/admin/system-config.php">
                    <i class="bi bi-gear me-2"></i>System Configuration
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="/operations/quotes.php">
                    <i class="bi bi-file-text me-2"></i>Quotes
                </a>
            </li>
        </ul>
    </nav>
</div>

<!-- Sidebar Toggle Button (for mobile) -->
<button class="btn btn-primary sidebar-toggle d-md-none" onclick="toggleSidebar()">
    <i class="bi bi-list"></i>
</button>

<!-- Sidebar Overlay (for mobile) -->
<div class="sidebar-overlay d-md-none" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<style>
.admin-sidebar {
    position: fixed;
    top: 70px; /* Adjust based on your main navbar height */
    left: 0;
    width: 280px;
    height: calc(100vh - 70px);
    background: #f8f9fa;
    border-right: 1px solid #dee2e6;
    z-index: 1000;
    transform: translateX(0);
    transition: transform 0.3s ease;
    overflow-y: auto;
}

.admin-sidebar.collapsed {
    transform: translateX(-100%);
}

.sidebar-header {
    padding: 1rem;
    border-bottom: 1px solid #dee2e6;
    background: #fff;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.sidebar-nav {
    padding: 1rem 0;
}

.sidebar-nav .nav-link {
    padding: 0.75rem 1.5rem;
    color: #495057;
    border-radius: 0;
    transition: all 0.2s;
    display: flex;
    align-items: center;
}

.sidebar-nav .nav-link:hover,
.sidebar-nav .nav-link.active {
    background: #e9ecef;
    color: #007bff;
}

.sidebar-toggle {
    position: fixed;
    top: 80px;
    left: 10px;
    z-index: 1001;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    display: none;
}

.sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 999;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.sidebar-overlay.show {
    opacity: 1;
    visibility: visible;
}

/* Tickets Section Styles */
.tickets-header {
    cursor: pointer;
    background: #e9ecef !important;
    color: #495057 !important;
    font-weight: 600;
}

.tickets-header:hover {
    background: #dee2e6 !important;
    color: #007bff !important;
}

.submenu-link {
    padding: 0.5rem 1.5rem 0.5rem 3rem !important;
    font-size: 0.875rem;
    color: #6c757d !important;
    margin-left: 0;
    border-left: 2px solid transparent;
}

.submenu-link:hover {
    background: #e9ecef !important;
    color: #007bff !important;
    border-left-color: #007bff;
}

.submenu-link.active {
    background: #007bff !important;
    color: white !important;
    border-left-color: #0056b3;
}

.submenu-link .badge {
    font-size: 0.65rem;
    padding: 0.25rem 0.4rem;
    min-width: 1.5rem;
    text-align: center;
}

/* Adjust main content when sidebar is present */
body.admin-layout {
    margin-left: 280px;
}

/* Mobile responsiveness */
@media (max-width: 767.98px) {
    .admin-sidebar {
        transform: translateX(-100%);
    }
    
    .sidebar-toggle {
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    body.admin-layout {
        margin-left: 0;
    }
    
    .admin-sidebar.show {
        transform: translateX(0);
    }
}
</style>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
}

// Add admin-layout class to body when sidebar is present
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('adminSidebar')) {
        document.body.classList.add('admin-layout');
    }
});
</script>
<?php endif; ?>