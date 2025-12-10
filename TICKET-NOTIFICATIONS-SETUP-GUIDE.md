# CaminhoIT Support Ticket Notifications & Email Import Setup Guide

This comprehensive guide will walk you through setting up email notifications, Discord webhooks, and POP3/IMAP email importing for your CaminhoIT support ticket system.

## Table of Contents

1. [Overview](#overview)
2. [Prerequisites](#prerequisites)
3. [Installation Steps](#installation-steps)
4. [Configuration](#configuration)
5. [Testing](#testing)
6. [Troubleshooting](#troubleshooting)
7. [Maintenance](#maintenance)

---

## Overview

This system provides:

- ✉️ **Email Notifications**: Automatic emails when tickets are created and replied to
- 🎮 **Discord Integration**: Real-time notifications to your Discord server
- 📥 **Email Import**: Convert incoming emails into tickets or replies
- 🔄 **Bi-directional Communication**: Customers and staff can use email or web interface

### How it Works

1. **Customer raises ticket** → Email sent to staff + Discord notification
2. **Staff replies** → Email sent to customer + Discord notification
3. **Customer replies via email** → Reply added to ticket automatically
4. **Customer replies via web** → Email sent to assigned staff

---

## Prerequisites

Before you begin, ensure you have:

- ✅ Access to cPanel and PHPMyAdmin
- ✅ MySQL/MariaDB database access
- ✅ Email account credentials (for sending and receiving)
- ✅ PHP 7.4 or higher
- ✅ PHP IMAP extension installed (for email import)
- ✅ Composer (optional, for PHPMailer installation)

---

## Installation Steps

### Step 1: Install PHPMailer

PHPMailer is required for sending emails. Follow the detailed guide:

📄 **[PHPMAILER-SETUP.md](./PHPMAILER-SETUP.md)**

**Quick Install via Composer:**

```bash
cd /home/caminhoit/public_html
composer require phpmailer/phpmailer
```

### Step 2: Run Database Migration

Run the SQL migration file to create necessary tables:

```bash
mysql -u caminhoit_webv2 -p caminhoit_webv2 < migrations/add-ticket-notifications-schema.sql
```

Or via PHPMyAdmin:
1. Open PHPMyAdmin
2. Select database `caminhoit_webv2`
3. Go to "Import" tab
4. Choose `migrations/add-ticket-notifications-schema.sql`
5. Click "Go"

This will create the following tables:
- `support_email_settings`
- `support_email_import_settings`
- `support_email_imports`
- `support_notification_log`
- `support_ticket_attachments_initial`

### Step 3: Verify File Structure

Ensure these files are in place:

```
/home/caminhoit/public_html/
├── includes/
│   ├── TicketNotifications.php      ✅ Notification handler
│   ├── EmailImport.php               ✅ Email import handler
│   └── PHPMailer/                    ✅ PHPMailer library
│       ├── PHPMailer.php
│       ├── SMTP.php
│       └── Exception.php
├── cron/
│   └── import-ticket-emails.php      ✅ Cron job script
├── members/
│   └── raise-ticket.php              ✅ Updated with notifications
└── migrations/
    └── add-ticket-notifications-schema.sql
```

### Step 4: Install PHP IMAP Extension

Required for email importing:

**Check if installed:**
```bash
php -m | grep imap
```

**Install on cPanel:**
1. WHM → EasyApache 4
2. Customize → Search "imap"
3. Enable `php-imap`
4. Provision

**Install on Ubuntu/Debian:**
```bash
sudo apt-get install php-imap
sudo service apache2 restart
```

---

## Configuration

### 1. Email Sending Configuration

Configure SMTP settings for sending emails:

```sql
-- For cPanel Email
UPDATE support_email_settings SET setting_value = 'mail.caminhoit.com' WHERE setting_key = 'smtp_host';
UPDATE support_email_settings SET setting_value = '587' WHERE setting_key = 'smtp_port';
UPDATE support_email_settings SET setting_value = 'support@caminhoit.com' WHERE setting_key = 'smtp_username';
UPDATE support_email_settings SET setting_value = 'YOUR_PASSWORD' WHERE setting_key = 'smtp_password';
UPDATE support_email_settings SET setting_value = 'tls' WHERE setting_key = 'smtp_encryption';
UPDATE support_email_settings SET setting_value = 'support@caminhoit.com' WHERE setting_key = 'smtp_from_email';
UPDATE support_email_settings SET setting_value = 'CaminhoIT Support' WHERE setting_key = 'smtp_from_name';

-- Staff notification emails (comma-separated)
UPDATE support_email_settings SET setting_value = 'support@caminhoit.com,admin@caminhoit.com' WHERE setting_key = 'staff_notification_emails';

-- Enable notifications
UPDATE support_email_settings SET setting_value = '1' WHERE setting_key = 'notify_staff_on_new_ticket';
UPDATE support_email_settings SET setting_value = '1' WHERE setting_key = 'notify_customer_on_ticket_created';
UPDATE support_email_settings SET setting_value = '1' WHERE setting_key = 'notify_customer_on_staff_reply';
UPDATE support_email_settings SET setting_value = '1' WHERE setting_key = 'notify_staff_on_customer_reply';
```

**For Office 365:**

```sql
UPDATE support_email_settings SET setting_value = 'smtp.office365.com' WHERE setting_key = 'smtp_host';
UPDATE support_email_settings SET setting_value = 'support@caminhoit.com' WHERE setting_key = 'smtp_username';
UPDATE support_email_settings SET setting_value = 'YOUR_PASSWORD' WHERE setting_key = 'smtp_password';
```

### 2. Discord Webhook Configuration (Optional)

Get real-time notifications in Discord:

**Create Discord Webhook:**

1. Open your Discord server
2. Server Settings → Integrations → Webhooks
3. Create New Webhook
4. Name it "CaminhoIT Support"
5. Select channel (e.g., #support-tickets)
6. Copy Webhook URL

**Configure in database:**

```sql
UPDATE support_email_settings SET setting_value = '1' WHERE setting_key = 'discord_webhook_enabled';
UPDATE support_email_settings SET setting_value = 'YOUR_DISCORD_WEBHOOK_URL' WHERE setting_key = 'discord_webhook_url';
UPDATE support_email_settings SET setting_value = '1' WHERE setting_key = 'discord_notify_on_new_ticket';
UPDATE support_email_settings SET setting_value = '1' WHERE setting_key = 'discord_notify_on_ticket_reply';
```

### 3. Email Import Configuration

Enable automatic ticket creation from emails:

```sql
-- Enable email import
UPDATE support_email_import_settings SET setting_value = '1' WHERE setting_key = 'import_enabled';

-- POP3 Configuration (cPanel)
UPDATE support_email_import_settings SET setting_value = 'pop3' WHERE setting_key = 'import_protocol';
UPDATE support_email_import_settings SET setting_value = 'mail.caminhoit.com' WHERE setting_key = 'import_host';
UPDATE support_email_import_settings SET setting_value = '995' WHERE setting_key = 'import_port';
UPDATE support_email_import_settings SET setting_value = 'support@caminhoit.com' WHERE setting_key = 'import_username';
UPDATE support_email_import_settings SET setting_value = 'YOUR_PASSWORD' WHERE setting_key = 'import_password';
UPDATE support_email_import_settings SET setting_value = 'ssl' WHERE setting_key = 'import_encryption';

-- Optional: Default ticket group for imported emails
UPDATE support_email_import_settings SET setting_value = '1' WHERE setting_key = 'import_default_group_id';

-- How often to check (in minutes)
UPDATE support_email_import_settings SET setting_value = '15' WHERE setting_key = 'import_frequency_minutes';
```

**For Office 365 IMAP:**

```sql
UPDATE support_email_import_settings SET setting_value = 'imap' WHERE setting_key = 'import_protocol';
UPDATE support_email_import_settings SET setting_value = 'outlook.office365.com' WHERE setting_key = 'import_host';
UPDATE support_email_import_settings SET setting_value = '993' WHERE setting_key = 'import_port';
UPDATE support_email_import_settings SET setting_value = 'support@caminhoit.com' WHERE setting_key = 'import_username';
UPDATE support_email_import_settings SET setting_value = 'YOUR_PASSWORD' WHERE setting_key = 'import_password';
```

### 4. Setup Cron Job

Configure automatic email checking:

📄 **[cron/CRON-SETUP.md](./cron/CRON-SETUP.md)**

**cPanel Instructions:**

1. cPanel → Cron Jobs
2. Add New Cron Job
3. Common Settings: Every 15 Minutes
4. Command:
   ```bash
   /usr/bin/php /home/caminhoit/public_html/cron/import-ticket-emails.php >> /home/caminhoit/logs/email-import.log 2>&1
   ```
5. Click "Add New Cron Job"

**Create logs directory:**

```bash
mkdir -p /home/caminhoit/logs
chmod 755 /home/caminhoit/logs
```

---

## Testing

### Test 1: Email Sending

```bash
# Create test file: test-notifications.php
<?php
require_once 'includes/config.php';
require_once 'includes/TicketNotifications.php';

$notifications = new TicketNotifications($pdo);
$result = $notifications->notifyTicketRaised(1); // Use a real ticket ID

echo $result ? "✓ Success" : "✗ Failed";

// Check logs
$stmt = $pdo->query("SELECT * FROM support_notification_log ORDER BY sent_at DESC LIMIT 5");
while ($log = $stmt->fetch()) {
    echo "<br>{$log['notification_type']} to {$log['recipient']}: {$log['status']}";
}
?>
```

Access: `https://caminhoit.com/test-notifications.php`

### Test 2: Create a Test Ticket

1. Go to: https://caminhoit.com/members/raise-ticket.php
2. Submit a test ticket
3. Check:
   - Email inbox for notifications
   - Discord channel for webhook
   - `support_notification_log` table

```sql
SELECT * FROM support_notification_log ORDER BY sent_at DESC;
```

### Test 3: Email Import

```bash
# Test manually first
php /home/caminhoit/public_html/cron/import-ticket-emails.php
```

Send a test email to support@caminhoit.com and run the command again.

Check `support_email_imports` table:

```sql
SELECT * FROM support_email_imports ORDER BY processed_at DESC;
```

### Test 4: Discord Webhook

```bash
# Test Discord directly
<?php
$webhookUrl = "YOUR_DISCORD_WEBHOOK_URL";

$payload = json_encode([
    'content' => 'Test notification from CaminhoIT Support System'
]);

$ch = curl_init($webhookUrl);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: {$httpCode}\n";
echo ($httpCode >= 200 && $httpCode < 300) ? "✓ Success" : "✗ Failed";
?>
```

---

## Troubleshooting

### Emails Not Sending

1. **Check notification log:**
   ```sql
   SELECT * FROM support_notification_log WHERE status = 'failed' ORDER BY sent_at DESC;
   ```

2. **Verify SMTP settings:**
   ```sql
   SELECT * FROM support_email_settings WHERE setting_key LIKE 'smtp_%';
   ```

3. **Test SMTP connection:**
   - Try sending test email via PHPMailer
   - Check firewall (port 587 must be open)
   - Verify credentials

4. **Check PHP error logs:**
   ```bash
   tail -f /home/caminhoit/logs/error_log
   ```

### Email Import Not Working

1. **Check if enabled:**
   ```sql
   SELECT setting_value FROM support_email_import_settings WHERE setting_key = 'import_enabled';
   ```

2. **Verify IMAP extension:**
   ```bash
   php -m | grep imap
   ```

3. **Test mailbox connection:**
   ```bash
   php /home/caminhoit/public_html/cron/import-ticket-emails.php
   ```

4. **Check cron log:**
   ```bash
   tail -f /home/caminhoit/logs/email-import.log
   ```

### Discord Webhook Not Working

1. **Verify webhook URL is valid**
2. **Check if enabled in settings:**
   ```sql
   SELECT * FROM support_email_settings WHERE setting_key LIKE 'discord_%';
   ```

3. **Test webhook directly** (see Test 4 above)

### Common Issues

**"Class 'PHPMailer' not found"**
- Install PHPMailer (see PHPMAILER-SETUP.md)

**"IMAP extension not installed"**
- Install PHP IMAP extension

**"SMTP connect() failed"**
- Check firewall settings
- Verify SMTP credentials
- Try different port (587 vs 465)

**"Permission denied" on cron**
- `chmod +x /home/caminhoit/public_html/cron/import-ticket-emails.php`

---

## Maintenance

### Monitor Notification Logs

```sql
-- Failed notifications in last 24 hours
SELECT * FROM support_notification_log
WHERE status = 'failed' AND sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- Notification statistics
SELECT
    notification_type,
    status,
    COUNT(*) as count
FROM support_notification_log
GROUP BY notification_type, status;
```

### Monitor Email Imports

```sql
-- Recent imports
SELECT * FROM support_email_imports ORDER BY processed_at DESC LIMIT 20;

-- Import statistics
SELECT
    import_type,
    COUNT(*) as count,
    MAX(processed_at) as last_import
FROM support_email_imports
GROUP BY import_type;
```

### Clean Old Logs (Optional)

```sql
-- Delete notification logs older than 90 days
DELETE FROM support_notification_log WHERE sent_at < DATE_SUB(NOW(), INTERVAL 90 DAY);

-- Delete import logs older than 90 days
DELETE FROM support_email_imports WHERE processed_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

### Update Staff Notification Emails

```sql
UPDATE support_email_settings
SET setting_value = 'staff1@caminhoit.com,staff2@caminhoit.com'
WHERE setting_key = 'staff_notification_emails';
```

### Disable/Enable Notifications

```sql
-- Disable all email notifications
UPDATE support_email_settings SET setting_value = '0'
WHERE setting_key IN (
    'notify_staff_on_new_ticket',
    'notify_customer_on_ticket_created',
    'notify_customer_on_staff_reply',
    'notify_staff_on_customer_reply'
);

-- Disable Discord
UPDATE support_email_settings SET setting_value = '0' WHERE setting_key = 'discord_webhook_enabled';

-- Disable email import
UPDATE support_email_import_settings SET setting_value = '0' WHERE setting_key = 'import_enabled';
```

---

## Additional Resources

- **[PHPMAILER-SETUP.md](./PHPMAILER-SETUP.md)** - Detailed PHPMailer installation
- **[cron/CRON-SETUP.md](./cron/CRON-SETUP.md)** - Cron job configuration
- **[NOTIFICATION-INTEGRATION-EXAMPLES.php](./NOTIFICATION-INTEGRATION-EXAMPLES.php)** - Code examples

---

## Support

If you encounter issues:

1. Check the troubleshooting section above
2. Review error logs
3. Verify all prerequisites are met
4. Test each component individually

For additional help, contact your system administrator or CaminhoIT support team.

---

## Migration to Office 365

When migrating from cPanel to Office 365:

### Update SMTP Settings:

```sql
UPDATE support_email_settings SET setting_value = 'smtp.office365.com' WHERE setting_key = 'smtp_host';
UPDATE support_email_settings SET setting_value = '587' WHERE setting_key = 'smtp_port';
```

### Update Import Settings:

```sql
UPDATE support_email_import_settings SET setting_value = 'imap' WHERE setting_key = 'import_protocol';
UPDATE support_email_import_settings SET setting_value = 'outlook.office365.com' WHERE setting_key = 'import_host';
UPDATE support_email_import_settings SET setting_value = '993' WHERE setting_key = 'import_port';
```

### Important Notes for Office 365:

- Enable IMAP in Exchange Admin Center
- May need app-specific password if MFA is enabled
- Ensure "Authenticated SMTP" is enabled
- Check Exchange Online Protection settings

---

**Last Updated:** 2025-11-06
**Version:** 1.0.0
**Author:** CaminhoIT Support Team
