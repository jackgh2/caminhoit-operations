<?php
/**
 * Scheduled Posts Publisher
 * 
 * This script should be run via cron job every minute or every few minutes
 * to automatically publish scheduled posts when their time arrives.
 * 
 * Add to crontab:
 * * * * * * /usr/bin/php /path/to/your/blog/cron/publish-scheduled-posts.php
 * 
 * Or every 5 minutes:
 * */5 * * * * /usr/bin/php /path/to/your/blog/cron/publish-scheduled-posts.php
 */

// Set time limit and error reporting
set_time_limit(300); // 5 minutes max
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Include required files
require_once dirname(__DIR__, 2) . '/includes/config.php';

// Log file for cron output
$log_file = dirname(__FILE__) . '/publish-scheduled.log';

/**
 * Log message with timestamp
 */
function logMessage($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

try {
    logMessage("Starting scheduled posts check...");
    
    // Check if auto-publishing is enabled
    $stmt = $pdo->prepare("SELECT setting_value FROM blog_settings WHERE setting_key = 'auto_publish_scheduled'");
    $stmt->execute();
    $auto_publish_enabled = $stmt->fetchColumn();
    
    if ($auto_publish_enabled !== '1') {
        logMessage("Auto-publish is disabled. Exiting.");
        exit(0);
    }
    
    // Get current time
    $current_time = date('Y-m-d H:i:s');
    
    // Find posts that should be published
    $stmt = $pdo->prepare("
        SELECT id, title, scheduled_at, author_id
        FROM blog_posts 
        WHERE status = 'scheduled' 
        AND scheduled_at IS NOT NULL 
        AND scheduled_at <= ?
        ORDER BY scheduled_at ASC
    ");
    $stmt->execute([$current_time]);
    $posts_to_publish = $stmt->fetchAll();
    
    if (empty($posts_to_publish)) {
        logMessage("No posts ready for publishing.");
        exit(0);
    }
    
    logMessage("Found " . count($posts_to_publish) . " posts ready for publishing.");
    
    $published_count = 0;
    $failed_count = 0;
    
    foreach ($posts_to_publish as $post) {
        try {
            $pdo->beginTransaction();
            
            // Update post status to published
            $update_stmt = $pdo->prepare("
                UPDATE blog_posts 
                SET status = 'published', 
                    published_at = ?,
                    updated_at = NOW()
                WHERE id = ? AND status = 'scheduled'
            ");
            
            $result = $update_stmt->execute([$current_time, $post['id']]);
            
            if ($result && $update_stmt->rowCount() > 0) {
                // Create a revision record for the status change
                $revision_stmt = $pdo->prepare("
                    INSERT INTO blog_post_revisions (post_id, title, content, excerpt, revision_note, created_by)
                    SELECT id, title, content, excerpt, 'Auto-published from scheduled', author_id
                    FROM blog_posts 
                    WHERE id = ?
                ");
                $revision_stmt->execute([$post['id']]);
                
                $pdo->commit();
                
                logMessage("Published post: '{$post['title']}' (ID: {$post['id']})");
                $published_count++;
                
                // Optional: Send notification email to author
                sendPublishNotification($post, $pdo);
                
            } else {
                $pdo->rollBack();
                logMessage("Failed to publish post: '{$post['title']}' (ID: {$post['id']}) - No rows affected");
                $failed_count++;
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            logMessage("Error publishing post '{$post['title']}' (ID: {$post['id']}): " . $e->getMessage());
            $failed_count++;
        }
    }
    
    // Log summary
    logMessage("Publishing complete. Published: $published_count, Failed: $failed_count");
    
    // Clean up old log entries (keep last 1000 lines)
    cleanupLogFile();
    
} catch (Exception $e) {
    logMessage("Fatal error in scheduled posts publisher: " . $e->getMessage());
    exit(1);
}

/**
 * Send notification email to post author (optional)
 */
function sendPublishNotification($post, $pdo) {
    try {
        // Get author email
        $stmt = $pdo->prepare("SELECT email, username FROM users WHERE id = ?");
        $stmt->execute([$post['author_id']]);
        $author = $stmt->fetch();
        
        if ($author && $author['email']) {
            // Get blog settings
            $stmt = $pdo->prepare("SELECT setting_value FROM blog_settings WHERE setting_key = 'blog_title'");
            $stmt->execute();
            $blog_title = $stmt->fetchColumn() ?: 'Blog';
            
            $subject = "[{$blog_title}] Your post has been published: {$post['title']}";
            $message = "Hello {$author['username']},\n\n";
            $message .= "Your scheduled post '{$post['title']}' has been automatically published.\n\n";
            $message .= "You can view it at: https://{$_SERVER['HTTP_HOST']}/blog/post.php?slug=" . urlencode($post['slug']) . "\n\n";
            $message .= "Best regards,\n{$blog_title}";
            
            $headers = "From: noreply@{$_SERVER['HTTP_HOST']}\r\n";
            $headers .= "Reply-To: noreply@{$_SERVER['HTTP_HOST']}\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();
            
            mail($author['email'], $subject, $message, $headers);
            
            logMessage("Notification email sent to {$author['email']} for post: {$post['title']}");
        }
        
    } catch (Exception $e) {
        logMessage("Failed to send notification email for post {$post['id']}: " . $e->getMessage());
    }
}

/**
 * Clean up old log entries
 */
function cleanupLogFile() {
    global $log_file;
    
    try {
        if (file_exists($log_file)) {
            $lines = file($log_file);
            if (count($lines) > 1000) {
                $recent_lines = array_slice($lines, -1000);
                file_put_contents($log_file, implode('', $recent_lines), LOCK_EX);
                logMessage("Log file cleaned up - kept last 1000 entries");
            }
        }
    } catch (Exception $e) {
        error_log("Failed to cleanup log file: " . $e->getMessage());
    }
}

logMessage("Scheduled posts publisher completed successfully.");
?>