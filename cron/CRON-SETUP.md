# Cron Job Setup Guide

This guide explains how to set up cron jobs for the CaminhoIT support ticket email import system.

## Email Import Cron Job

The email import cron job checks your support email account for new messages and automatically creates tickets or adds replies to existing tickets.

### Setup Instructions for cPanel

1. **Log in to cPanel**

2. **Navigate to Cron Jobs**
   - Find "Cron Jobs" in the Advanced section

3. **Add New Cron Job**
   - **Common Settings**: Choose "Every 15 Minutes" (or custom)
   - **Command**: Enter the following command:

   ```bash
   /usr/bin/php /home/caminhoit/public_html/cron/import-ticket-emails.php >> /home/caminhoit/logs/email-import.log 2>&1
   ```

   **Note**: Replace `/home/caminhoit/public_html` with your actual document root path.

4. **Create logs directory** (if it doesn't exist):
   ```bash
   mkdir -p /home/caminhoit/logs
   chmod 755 /home/caminhoit/logs
   ```

### Recommended Schedules

- **Every 5 minutes** (for high volume): `*/5 * * * *`
- **Every 15 minutes** (recommended): `*/15 * * * *`
- **Every 30 minutes** (low volume): `*/30 * * * *`
- **Every hour**: `0 * * * *`

### Testing the Cron Job

Before setting up the cron job, test it manually:

1. **Via SSH**:
   ```bash
   cd /home/caminhoit/public_html/cron
   php import-ticket-emails.php
   ```

2. **Via Web Browser** (for testing only):
   ```
   https://caminhoit.com/cron/import-ticket-emails.php?manual_run=1
   ```

   **IMPORTANT**: Remove or comment out the `manual_run` check in production for security.

### Monitoring

**Check the log file**:
```bash
tail -f /home/caminhoit/logs/email-import.log
```

**View recent imports**:
```sql
SELECT * FROM support_email_imports ORDER BY processed_at DESC LIMIT 10;
```

### Troubleshooting

**No emails being imported:**
1. Check if email import is enabled in settings
2. Verify email server credentials are correct
3. Check PHP IMAP extension is installed: `php -m | grep imap`
4. Review the log file for errors

**IMAP extension not installed:**
```bash
# On cPanel, use EasyApache to enable PHP IMAP module
# Or contact your hosting provider
```

**Permission issues:**
```bash
chmod +x /home/caminhoit/public_html/cron/import-ticket-emails.php
```

### Multiple Cron Jobs

If you want to run different tasks at different intervals:

```bash
# Email import every 15 minutes
*/15 * * * * /usr/bin/php /home/caminhoit/public_html/cron/import-ticket-emails.php >> /home/caminhoit/logs/email-import.log 2>&1

# Cleanup old logs daily at 2 AM
0 2 * * * find /home/caminhoit/logs -name "*.log" -mtime +30 -delete

# Send digest emails daily at 8 AM
0 8 * * * /usr/bin/php /home/caminhoit/public_html/cron/send-ticket-digest.php >> /home/caminhoit/logs/digest.log 2>&1
```

## Email Configuration

Before running the cron job, make sure to configure the email import settings in the database:

```sql
UPDATE support_email_import_settings SET setting_value = '1' WHERE setting_key = 'import_enabled';
UPDATE support_email_import_settings SET setting_value = 'pop3' WHERE setting_key = 'import_protocol';
UPDATE support_email_import_settings SET setting_value = 'mail.caminhoit.com' WHERE setting_key = 'import_host';
UPDATE support_email_import_settings SET setting_value = '995' WHERE setting_key = 'import_port';
UPDATE support_email_import_settings SET setting_value = 'support@caminhoit.com' WHERE setting_key = 'import_username';
UPDATE support_email_import_settings SET setting_value = 'your_password' WHERE setting_key = 'import_password';
UPDATE support_email_import_settings SET setting_value = 'ssl' WHERE setting_key = 'import_encryption';
```

## Office 365 Migration Note

When migrating to Office 365, update the settings accordingly:

```sql
UPDATE support_email_import_settings SET setting_value = 'imap' WHERE setting_key = 'import_protocol';
UPDATE support_email_import_settings SET setting_value = 'outlook.office365.com' WHERE setting_key = 'import_host';
UPDATE support_email_import_settings SET setting_value = '993' WHERE setting_key = 'import_port';
UPDATE support_email_import_settings SET setting_value = 'support@caminhoit.com' WHERE setting_key = 'import_username';
UPDATE support_email_import_settings SET setting_value = 'your_password' WHERE setting_key = 'import_password';
UPDATE support_email_import_settings SET setting_value = 'ssl' WHERE setting_key = 'import_encryption';
```

**Note**: For Office 365, you may need to enable IMAP access in the Exchange admin center and potentially use app-specific passwords if MFA is enabled.
