<?php
/**
 * Cron Job: Check Expiring Subscriptions
 * Sends Discord notifications for subscriptions expiring soon
 * Run daily via cron: 0 9 * * * php /path/to/check-expiring-subscriptions.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/DiscordNotifications.php';

try {
    echo "[" . date('Y-m-d H:i:s') . "] Starting expiring subscriptions check...\n";

    $discord = new DiscordNotifications($pdo);

    // Check for subscriptions expiring in 30 days
    $stmt = $pdo->prepare("
        SELECT cs.id, cs.next_billing_date,
               DATEDIFF(cs.next_billing_date, CURDATE()) as days_until
        FROM client_subscriptions cs
        WHERE cs.status = 'active'
        AND cs.auto_renew = 0
        AND cs.next_billing_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        AND cs.next_billing_date > CURDATE()
    ");
    $stmt->execute();
    $expiring_subscriptions = $stmt->fetchAll();

    echo "Found " . count($expiring_subscriptions) . " subscriptions expiring in the next 30 days\n";

    $notification_thresholds = [30, 14, 7, 3, 1]; // Days before expiry to notify

    foreach ($expiring_subscriptions as $subscription) {
        $days_until = $subscription['days_until'];

        // Only send notification if days_until matches a threshold
        if (in_array($days_until, $notification_thresholds)) {
            echo "  - Subscription #{$subscription['id']}: {$days_until} days until expiry\n";

            // Check if we already sent a notification for this threshold
            $stmt = $pdo->prepare("
                SELECT id FROM notification_log
                WHERE entity_type = 'subscription'
                AND entity_id = ?
                AND notification_type = 'expiring'
                AND DATE(created_at) = CURDATE()
                AND meta_data LIKE ?
            ");
            $stmt->execute([$subscription['id'], '%days:' . $days_until . '%']);

            if (!$stmt->fetch()) {
                // Send Discord notification
                $result = $discord->notifySubscriptionExpiring($subscription['id'], $days_until);

                // Log the notification
                if ($result) {
                    $stmt = $pdo->prepare("
                        INSERT INTO notification_log
                        (entity_type, entity_id, notification_type, channel, status, meta_data, created_at)
                        VALUES ('subscription', ?, 'expiring', 'discord', 'sent', ?, NOW())
                    ");
                    $stmt->execute([$subscription['id'], json_encode(['days' => $days_until])]);
                    echo "    ✓ Notification sent\n";
                } else {
                    echo "    ✗ Notification failed\n";
                }
            } else {
                echo "    - Already notified today\n";
            }
        }
    }

    // Check for low inventory (less than 10% available or less than 5 licenses)
    echo "\n[" . date('Y-m-d H:i:s') . "] Checking low inventory...\n";

    $stmt = $pdo->prepare("
        SELECT cs.id, si.total_quantity, si.assigned_quantity, si.available_quantity
        FROM client_subscriptions cs
        JOIN subscription_inventory si ON cs.id = si.subscription_id
        WHERE cs.status = 'active'
        AND (
            (si.available_quantity < 5 AND si.total_quantity >= 10)
            OR
            (si.available_quantity / si.total_quantity < 0.1 AND si.total_quantity > 0)
        )
        AND si.available_quantity > 0
    ");
    $stmt->execute();
    $low_inventory = $stmt->fetchAll();

    echo "Found " . count($low_inventory) . " subscriptions with low inventory\n";

    foreach ($low_inventory as $inventory) {
        // Check if we already notified today
        $stmt = $pdo->prepare("
            SELECT id FROM notification_log
            WHERE entity_type = 'subscription'
            AND entity_id = ?
            AND notification_type = 'low_inventory'
            AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$inventory['id']]);

        if (!$stmt->fetch()) {
            echo "  - Subscription #{$inventory['id']}: {$inventory['available_quantity']} available\n";

            $result = $discord->notifyLowInventory($inventory['id'], $inventory['available_quantity']);

            if ($result) {
                $stmt = $pdo->prepare("
                    INSERT INTO notification_log
                    (entity_type, entity_id, notification_type, channel, status, meta_data, created_at)
                    VALUES ('subscription', ?, 'low_inventory', 'discord', 'sent', ?, NOW())
                ");
                $stmt->execute([$inventory['id'], json_encode([
                    'available' => $inventory['available_quantity'],
                    'total' => $inventory['total_quantity']
                ])]);
                echo "    ✓ Notification sent\n";
            } else {
                echo "    ✗ Notification failed\n";
            }
        } else {
            echo "  - Subscription #{$inventory['id']}: Already notified today\n";
        }
    }

    echo "\n[" . date('Y-m-d H:i:s') . "] Check completed successfully\n";

} catch (Exception $e) {
    echo "[ERROR] " . date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n";
    error_log("Expiring subscriptions check failed: " . $e->getMessage());
    exit(1);
}

exit(0);
?>
