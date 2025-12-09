<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

try {
    // Test database connection
    if (!isset($pdo)) {
        throw new Exception('PDO not available');
    }

    // Test simple query
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM analytics_pageviews");
    $count = $stmt->fetchColumn();

    echo json_encode([
        'status' => 'success',
        'pageviews_count' => $count,
        'pdo_available' => isset($pdo)
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
