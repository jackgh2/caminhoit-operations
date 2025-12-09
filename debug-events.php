<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

echo "<h2>Analytics Events Debug</h2>";
echo "<h3>Raw Events Data:</h3>";

$stmt = $pdo->query("SELECT * FROM analytics_events ORDER BY created_at DESC LIMIT 10");
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<pre>";
print_r($events);
echo "</pre>";

echo "<h3>Events Summary (Last 7 Days):</h3>";
$stmt = $pdo->query("
    SELECT event_name, event_category, COUNT(*) as count
    FROM analytics_events
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY event_name, event_category
    ORDER BY count DESC
");
$summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>Event Name</th><th>Category</th><th>Count</th></tr>";
foreach ($summary as $row) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['event_name'] ?? 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars($row['event_category'] ?? 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars($row['count']) . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>Events By Hour (Today):</h3>";
$stmt = $pdo->query("
    SELECT HOUR(created_at) as hour, COUNT(*) as count
    FROM analytics_events
    WHERE DATE(created_at) = CURDATE()
    GROUP BY HOUR(created_at)
    ORDER BY hour
");
$byHour = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>Hour</th><th>Count</th></tr>";
foreach ($byHour as $row) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['hour']) . ":00</td>";
    echo "<td>" . htmlspecialchars($row['count']) . "</td>";
    echo "</tr>";
}
echo "</table>";
