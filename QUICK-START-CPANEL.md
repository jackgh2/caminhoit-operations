# Quick Start Guide for cPanel Hosting

**Ticket Notifications & Email Import System**

This is a simplified guide for setting up the ticket notification system on cPanel shared hosting (no command line or Composer needed).

---

## ⚡ 5-Minute Setup

### Step 1: Install PHPMailer (2 minutes)

1. Upload `install-phpmailer.php` to your public_html folder via cPanel File Manager
2. Visit: `https://caminhoit.com/install-phpmailer.php`
3. Click "Install PHPMailer Now"
4. **Delete** `install-phpmailer.php` after installation ⚠️

### Step 2: Run Database Migration (1 minute)

1. Log in to cPanel → PHPMyAdmin
2. Select database `caminhoit_webv2`
3. Click "Import" tab
4. Choose file: `migrations/add-ticket-notifications-schema.sql`
5. Click "Go"

### Step 3: Configure Email Settings (2 minutes)

In PHPMyAdmin, go to "SQL" tab and run:

```sql
-- Your email server settings
UPDATE support_email_settings SET setting_value = 'mail.caminhoit.com' WHERE setting_key = 'smtp_host';
UPDATE support_email_settings SET setting_value = 'support@caminhoit.com' WHERE setting_key = 'smtp_username';
UPDATE support_email_settings SET setting_value = 'YOUR_PASSWORD_HERE' WHERE setting_key = 'smtp_password';

-- Where to send new ticket notifications
UPDATE support_email_settings SET setting_value = 'support@caminhoit.com' WHERE setting_key = 'staff_notification_emails';
```

**That's it! Notifications are now working!** 🎉

---

## 🔄 Optional: Email Import Setup (10 minutes)

### Enable Email Importing

```sql
-- Enable email import
UPDATE support_email_import_settings SET setting_value = '1' WHERE setting_key = 'import_enabled';

-- Email server settings (same as above)
UPDATE support_email_import_settings SET setting_value = 'mail.caminhoit.com' WHERE setting_key = 'import_host';
UPDATE support_email_import_settings SET setting_value = 'support@caminhoit.com' WHERE setting_key = 'import_username';
UPDATE support_email_import_settings SET setting_value = 'YOUR_PASSWORD_HERE' WHERE setting_key = 'import_password';
```

### Setup Cron Job

1. cPanel → Cron Jobs
2. Add New Cron Job:
   - **Common Settings**: Every 15 Minutes
   - **Command**:
     ```
     /usr/bin/php /home/caminhoit/public_html/cron/import-ticket-emails.php >> /home/caminhoit/logs/email-import.log 2>&1
     ```
3. Click "Add New Cron Job"

4. Create logs directory via cPanel File Manager:
   - Create folder: `/home/caminhoit/logs`
   - Set permissions: 755

**Done! Emails will now create tickets automatically!** 📧

---

## 🎮 Optional: Discord Webhooks (5 minutes)

Get real-time notifications in Discord!

### Get Discord Webhook URL

1. Discord → Server Settings → Integrations → Webhooks
2. Create New Webhook → Name it "CaminhoIT Support"
3. Copy Webhook URL

### Enable in Database

```sql
UPDATE support_email_settings SET setting_value = '1' WHERE setting_key = 'discord_webhook_enabled';
UPDATE support_email_settings SET setting_value = 'YOUR_WEBHOOK_URL' WHERE setting_key = 'discord_webhook_url';
```

**Discord notifications active!** 🎮

---

## 📋 What Gets Notified?

### When Customer Raises Ticket:
- ✅ Email sent to customer (confirmation)
- ✅ Email sent to staff
- ✅ Discord notification (if enabled)

### When Staff Replies:
- ✅ Email sent to customer
- ✅ Discord notification (if enabled)

### When Customer Replies:
- ✅ Email sent to assigned staff
- ✅ Discord notification (if enabled)

### When Email Received:
- ✅ Creates new ticket OR adds reply
- ✅ Sends notifications as above

---

## 🧪 Testing

### Test Email Notifications

1. Create a test ticket at: `https://caminhoit.com/members/raise-ticket.php`
2. Check your email inbox
3. Check Discord (if configured)

### Check Notification Logs

```sql
SELECT * FROM support_notification_log ORDER BY sent_at DESC LIMIT 10;
```

### Test Email Import

1. Send an email to: support@caminhoit.com
2. Wait 15 minutes (or trigger cron manually)
3. Check if ticket was created

### Check Import Logs

```sql
SELECT * FROM support_email_imports ORDER BY processed_at DESC LIMIT 10;
```

---

## 🔧 Common Settings

### Change Staff Notification Emails

```sql
UPDATE support_email_settings
SET setting_value = 'email1@caminhoit.com,email2@caminhoit.com'
WHERE setting_key = 'staff_notification_emails';
```

### Disable Customer Confirmation Emails

```sql
UPDATE support_email_settings SET setting_value = '0' WHERE setting_key = 'notify_customer_on_ticket_created';
```

### Change Email Check Frequency

```sql
-- Check every 5 minutes instead of 15
UPDATE support_email_import_settings SET setting_value = '5' WHERE setting_key = 'import_frequency_minutes';
```

Then update your cron job to:
```
*/5 * * * * /usr/bin/php /home/caminhoit/public_html/cron/import-ticket-emails.php >> /home/caminhoit/logs/email-import.log 2>&1
```

---

## ⚠️ Troubleshooting

### Emails Not Sending?

1. **Check settings are correct:**
   ```sql
   SELECT * FROM support_email_settings WHERE setting_key LIKE 'smtp_%';
   ```

2. **Check for failures:**
   ```sql
   SELECT * FROM support_notification_log WHERE status = 'failed' ORDER BY sent_at DESC;
   ```

3. **Make sure PHP can send emails** - test with a simple PHP mail script

### Email Import Not Working?

1. **Check if enabled:**
   ```sql
   SELECT setting_value FROM support_email_import_settings WHERE setting_key = 'import_enabled';
   ```

2. **Check PHP IMAP is installed:**
   - Create file: `phpinfo.php`
   - Content: `<?php phpinfo(); ?>`
   - Look for "imap" section
   - If not found, contact hosting provider to enable PHP IMAP

3. **Check cron is running:**
   ```
   View logs: /home/caminhoit/logs/email-import.log
   ```

### Discord Not Working?

1. **Verify webhook URL is correct**
2. **Check if enabled:**
   ```sql
   SELECT * FROM support_email_settings WHERE setting_key = 'discord_webhook_enabled';
   ```

---

## 📁 File Structure

After installation, you should have:

```
/home/caminhoit/public_html/
├── includes/
│   ├── TicketNotifications.php        ← Handles email/Discord notifications
│   ├── EmailImport.php                 ← Handles POP3/IMAP import
│   └── PHPMailer/                      ← Email library
│       ├── PHPMailer.php
│       ├── SMTP.php
│       └── Exception.php
├── cron/
│   ├── import-ticket-emails.php        ← Cron job script
│   └── CRON-SETUP.md                   ← Detailed cron instructions
├── migrations/
│   └── add-ticket-notifications-schema.sql
├── members/
│   └── raise-ticket.php                ← Updated to send notifications
└── logs/                               ← Create this folder
    └── email-import.log
```

---

## 🎯 Next Steps

1. ✅ Install PHPMailer
2. ✅ Run database migration
3. ✅ Configure email settings
4. ✅ Test notifications
5. ⬜ Setup email import (optional)
6. ⬜ Configure Discord (optional)
7. ⬜ Customize email templates (optional)

---

## 📚 Additional Resources

- **TICKET-NOTIFICATIONS-SETUP-GUIDE.md** - Full detailed setup guide
- **PHPMAILER-SETUP.md** - PHPMailer installation options
- **cron/CRON-SETUP.md** - Cron job configuration details
- **NOTIFICATION-INTEGRATION-EXAMPLES.php** - Code examples for developers

---

## 🆘 Need Help?

If you're stuck:

1. Check the troubleshooting section above
2. Review the detailed setup guide
3. Check error logs in cPanel
4. Contact your hosting provider for PHP IMAP support

---

**That's it! Your ticket system now has full email and Discord notifications! 🚀**

For migrating to Office 365 later, see the main setup guide.

---

**Version:** 1.0.0 | **Date:** 2025-11-06 | **For:** cPanel Shared Hosting
