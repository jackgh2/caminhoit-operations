<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Set JSON header
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'administrator') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$company_id = (int)($_GET['company_id'] ?? 0);

if (!$company_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Company ID required']);
    exit;
}

try {
    // Get company info
    $stmt = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
    $stmt->execute([$company_id]);
    $company = $stmt->fetch();
    
    if (!$company) {
        http_response_code(404);
        echo json_encode(['error' => 'Company not found']);
        exit;
    }
    
    // Get primary users (users where company_id = this company)
    $stmt = $pdo->prepare("SELECT u.id, u.username, u.email, u.role, u.is_active, u.last_login, 'primary' as relationship_type
        FROM users u 
        WHERE u.company_id = ? 
        ORDER BY u.username");
    $stmt->execute([$company_id]);
    $primary_users = $stmt->fetchAll();
    
    // Get multi-company users (users in company_users table)
    $stmt = $pdo->prepare("SELECT u.id, u.username, u.email, u.role, u.is_active, u.last_login, cu.role as company_role, 'multi' as relationship_type
        FROM users u 
        JOIN company_users cu ON u.id = cu.user_id 
        WHERE cu.company_id = ? 
        ORDER BY u.username");
    $stmt->execute([$company_id]);
    $multi_users = $stmt->fetchAll();
    
    // Combine and remove duplicates
    $all_users = array_merge($primary_users, $multi_users);
    $unique_users = [];
    $seen_ids = [];
    
    foreach ($all_users as $user) {
        if (!in_array($user['id'], $seen_ids)) {
            $seen_ids[] = $user['id'];
            $unique_users[] = $user;
        }
    }
    
    // Generate HTML
    $html = '<div class="company-users-list">';
    $html .= '<div class="d-flex justify-content-between align-items-center mb-3">';
    $html .= '<h6 class="mb-0">Users for ' . htmlspecialchars($company['name']) . '</h6>';
    $html .= '<button class="btn btn-sm btn-primary" onclick="inviteUser(' . $company_id . ')">';
    $html .= '<i class="bi bi-person-plus"></i> Invite User';
    $html .= '</button>';
    $html .= '</div>';
    
    if (empty($unique_users)) {
        $html .= '<div class="text-center text-muted py-4">';
        $html .= '<i class="bi bi-people" style="font-size: 3rem; opacity: 0.3;"></i>';
        $html .= '<p class="mt-2">No users assigned to this company</p>';
        $html .= '</div>';
    } else {
        $html .= '<div class="list-group">';
        foreach ($unique_users as $user) {
            $html .= '<div class="list-group-item d-flex justify-content-between align-items-center">';
            $html .= '<div class="d-flex align-items-center">';
            $html .= '<div class="user-avatar-small me-3">';
            $html .= strtoupper(substr($user['username'], 0, 2));
            $html .= '</div>';
            $html .= '<div>';
            $html .= '<h6 class="mb-0">' . htmlspecialchars($user['username']) . '</h6>';
            $html .= '<small class="text-muted">' . htmlspecialchars($user['email']) . '</small>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '<div class="d-flex align-items-center gap-2">';
            $html .= '<span class="badge bg-' . ($user['is_active'] ? 'success' : 'secondary') . '">';
            $html .= $user['is_active'] ? 'Active' : 'Inactive';
            $html .= '</span>';
            $html .= '<span class="badge bg-info">';
            $html .= ucfirst(str_replace('_', ' ', $user['role']));
            $html .= '</span>';
            $html .= '<span class="badge bg-warning">';
            $html .= ucfirst($user['relationship_type']);
            $html .= '</span>';
            $html .= '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';
    }
    
    $html .= '</div>';
    $html .= '<style>';
    $html .= '.user-avatar-small { width: 40px; height: 40px; border-radius: 8px; background: linear-gradient(135deg, #4F46E5, #7C3AED); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.875rem; }';
    $html .= '</style>';
    
    echo json_encode(['html' => $html]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>