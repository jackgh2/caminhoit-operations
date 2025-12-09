<?php
/**
 * Stripe Webhook Handler
 * Processes Stripe payment webhooks
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/PaymentGateway.php';

// Get raw POST body
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Log webhook receipt
error_log("Stripe webhook received: " . substr($payload, 0, 200));

// Process webhook
$gateway = new PaymentGateway($pdo);
$result = $gateway->handleWebhook($payload, $sig_header);

if ($result) {
    http_response_code(200);
    echo json_encode(['status' => 'success']);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Webhook processing failed']);
}
?>
