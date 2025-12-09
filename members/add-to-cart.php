<?php
/**
 * Add to Cart AJAX Handler
 * Handles adding items to shopping cart from service catalog
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/CartManager.php';

header('Content-Type: application/json');

$user = $_SESSION['user'] ?? null;
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$type = $_POST['type'] ?? '';
$id = $_POST['id'] ?? '';
$name = $_POST['name'] ?? '';
$price = floatval($_POST['price'] ?? 0);
$quantity = intval($_POST['quantity'] ?? 1);
$billing_cycle = $_POST['billing_cycle'] ?? 'monthly';
$setup_fee = floatval($_POST['setup_fee'] ?? 0);

if (empty($type) || empty($id) || empty($name)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    $cart = new CartManager($pdo, $user['id']);

    // Auto-select user's company if not already set
    if (!$cart->getCompany()) {
        // Get user's primary company
        $stmt = $pdo->prepare("
            SELECT c.id, c.preferred_currency
            FROM companies c
            LEFT JOIN users u ON c.id = u.company_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$user['id']]);
        $userCompany = $stmt->fetch();

        if ($userCompany) {
            $cart->setCompany($userCompany['id']);
            // Currency will be set automatically by setCompany
        }
    }

    $result = $cart->addItem(
        $type,
        $id,
        $name,
        $price,
        $quantity,
        $billing_cycle,
        $setup_fee,
        []
    );

    echo json_encode($result);

} catch (Exception $e) {
    error_log("Add to cart error: " . $e->getMessage());
    error_log("Add to cart stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'error' => 'Failed to add item to cart: ' . $e->getMessage()
    ]);
}
?>
