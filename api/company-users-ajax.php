<?php
// Disable error display in output
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON header first
header('Content-Type: application/json');

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Configuration error']);
    exit;
}

// Access control
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'account_manager'])) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

if (!isset($_GET['action']) || $_GET['action'] !== 'get_company_users') {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

$user_id = $_SESSION['user']['id'];
$company_id = (int)($_GET['company_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$role_filter = trim($_GET['role'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

if (!$company_id) {
    echo json_encode(['success' => false, 'error' => 'Company ID required']);
    exit;
}

try {
    // Verify user has access to this company
    $stmt = $pdo->prepare("
        SELECT c.id, c.name FROM companies c
        JOIN users u ON (u.company_id = c.id OR u.id IN (
            SELECT cu.user_id FROM company_users cu WHERE cu.company_id = c.id
        ))
        WHERE u.id = ? AND c.id = ? AND c.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$user_id, $company_id]);
    $company = $stmt->fetch();
    
    if (!$company) {
        echo json_encode(['success' => false, 'error' => 'Access denied to this company']);
        exit;
    }

    // Build user query with filters - REMOVED first_name and last_name references
    $where_conditions = ["(u.company_id = ? OR u.id IN (SELECT cu.user_id FROM company_users cu WHERE cu.company_id = ?))"];
    $params = [$company_id, $company_id];

    if (!empty($search)) {
        // Updated search to only use username and email since first_name/last_name don't exist
        $where_conditions[] = "(u.username LIKE ? OR u.email LIKE ?)";
        $search_param = '%' . $search . '%';
        $params = array_merge($params, [$search_param, $search_param]);
    }

    if (!empty($role_filter)) {
        $where_conditions[] = "u.role = ?";
        $params[] = $role_filter;
    }

    if (!empty($status_filter)) {
        if ($status_filter === 'active') {
            $where_conditions[] = "u.is_active = 1";
        } elseif ($status_filter === 'inactive') {
            $where_conditions[] = "u.is_active = 0";
        }
    }

    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

    // Get users - REMOVED first_name, last_name, and avatar from SELECT
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.role, u.is_active, 
               u.last_login, u.created_at,
               CASE 
                   WHEN u.company_id = ? THEN 'Primary'
                   WHEN cu.user_id IS NOT NULL THEN 'Multi-Company'
                   ELSE 'Unknown'
               END as relationship_type
        FROM users u
        LEFT JOIN company_users cu ON u.id = cu.user_id AND cu.company_id = ?
        $where_clause
        ORDER BY u.is_active DESC, u.last_login DESC, u.created_at DESC
        LIMIT 100
    ");
    $stmt->execute(array_merge([$company_id, $company_id], $params));
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Generate HTML table
    $html = '';
    
    if (empty($users)) {
        $html = '
        <div class="empty-state">
            <i class="bi bi-people"></i>
            <h5>No Users Found</h5>
            <p class="mb-0">' . (!empty($search) ? 'No users match your search criteria.' : 'This company has no users.') . '</p>
        </div>';
    } else {
        $html = '
        <table class="users-table table table-hover mb-0">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Activity</th>
                    <th>Joined</th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($users as $user) {
            // Calculate last activity
            $last_activity = 'Never';
            if ($user['last_login']) {
                $last_login = strtotime($user['last_login']);
                $days_ago = floor((time() - $last_login) / (24 * 60 * 60));
                
                if ($days_ago === 0) {
                    $last_activity = 'Today';
                } elseif ($days_ago === 1) {
                    $last_activity = 'Yesterday';
                } elseif ($days_ago <= 7) {
                    $last_activity = $days_ago . ' days ago';
                } elseif ($days_ago <= 30) {
                    $last_activity = date('M j', $last_login);
                } else {
                    $last_activity = date('M j, Y', $last_login);
                }
            }
            
            // Build avatar using just username initials
            $avatar_content = strtoupper(substr($user['username'], 0, 2));
            
            $html .= '
            <tr>
                <td>
                    <div class="d-flex align-items-center">
                        <div class="user-avatar ' . ($user['is_active'] ? '' : 'inactive') . '">
                            ' . $avatar_content . '
                        </div>
                        <div class="ms-3">
                            <div class="fw-bold">' . htmlspecialchars($user['username']) . '</div>
                        </div>
                    </div>
                </td>
                <td>' . htmlspecialchars($user['email']) . '</td>
                <td>
                    <span class="role-badge ' . $user['role'] . '">' . ucfirst(str_replace('_', ' ', $user['role'])) . '</span>
                </td>
                <td>
                    <span class="status-badge ' . ($user['is_active'] ? 'active' : 'inactive') . '">' . ($user['is_active'] ? 'Active' : 'Inactive') . '</span>
                </td>
                <td><small class="text-muted">' . $last_activity . '</small></td>
                <td><small class="text-muted">' . date('M j, Y', strtotime($user['created_at'])) . '</small></td>
            </tr>';
        }
        
        $html .= '
            </tbody>
        </table>';
    }

    echo json_encode([
        'success' => true, 
        'html' => $html, 
        'count' => count($users),
        'company_name' => $company['name']
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in company-users-ajax.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error in company-users-ajax.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An unexpected error occurred']);
}
?>