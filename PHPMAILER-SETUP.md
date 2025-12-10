# PHPMailer Installation Guide

The ticket notification system requires PHPMailer to send emails. This guide will help you install and configure PHPMailer.

## Installation Methods

### Method 1: Composer (Recommended)

If you have Composer installed on your server:

```bash
cd /home/caminhoit/public_html
composer require phpmailer/phpmailer
```

This will create the necessary structure:
```
/home/caminhoit/public_html/
└── vendor/
    └── phpmailer/
        └── phpmailer/
            ├── src/
            │   ├── PHPMailer.php
            │   ├── SMTP.php
            │   └── Exception.php
            └── ...
```

Then update `includes/TicketNotifications.php` line 73-75 to:

```php
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/phpmailer/phpmailer/src/SMTP.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/phpmailer/phpmailer/src/Exception.php';
```

### Method 2: Manual Installation

1. **Download PHPMailer**

   Visit: https://github.com/PHPMailer/PHPMailer/releases

   Download the latest release (e.g., `PHPMailer-6.x.x.zip`)

2. **Extract to your server**

   ```bash
   cd /home/caminhoit/public_html/includes
   mkdir PHPMailer
   # Upload the files to this directory
   ```

3. **Verify structure**

   ```
   /home/caminhoit/public_html/includes/
   └── PHPMailer/
       ├── PHPMailer.php
       ├── SMTP.php
       └── Exception.php
   ```

4. **The code in TicketNotifications.php already expects this structure:**

   ```php
   require_once 'PHPMailer/PHPMailer.php';
   require_once 'PHPMailer/SMTP.php';
   require_once 'PHPMailer/Exception.php';
   ```

### Method 3: Using cPanel File Manager

1. Log in to cPanel
2. Navigate to File Manager
3. Go to `/public_html/includes`
4. Create folder `PHPMailer`
5. Download these 3 files from https://github.com/PHPMailer/PHPMailer/tree/master/src:
   - PHPMailer.php
   - SMTP.php
   - Exception.php
6. Upload them to the `PHPMailer` folder

## Verify Installation

Create a test file `test-phpmailer.php` in your root directory:

```php
<?php
require_once 'includes/PHPMailer/PHPMailer.php';
require_once 'includes/PHPMailer/SMTP.php';
require_once 'includes/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;

echo "PHPMailer loaded successfully!<br>";
echo "PHPMailer version: " . PHPMailer::VERSION;
?>
```

Access via: `https://caminhoit.com/test-phpmailer.php`

You should see:
```
PHPMailer loaded successfully!
PHPMailer version: 6.x.x
```

**IMPORTANT**: Delete `test-phpmailer.php` after testing!

## Configuration

After installing PHPMailer, configure your email settings in the database:

### For cPanel Email

```sql
UPDATE support_email_settings SET setting_value = 'mail.caminhoit.com' WHERE setting_key = 'smtp_host';
UPDATE support_email_settings SET setting_value = '587' WHERE setting_key = 'smtp_port';
UPDATE support_email_settings SET setting_value = 'support@caminhoit.com' WHERE setting_key = 'smtp_username';
UPDATE support_email_settings SET setting_value = 'your_password_here' WHERE setting_key = 'smtp_password';
UPDATE support_email_settings SET setting_value = 'tls' WHERE setting_key = 'smtp_encryption';
UPDATE support_email_settings SET setting_value = 'support@caminhoit.com' WHERE setting_key = 'smtp_from_email';
UPDATE support_email_settings SET setting_value = 'CaminhoIT Support' WHERE setting_key = 'smtp_from_name';
```

### For Office 365 / Outlook

```sql
UPDATE support_email_settings SET setting_value = 'smtp.office365.com' WHERE setting_key = 'smtp_host';
UPDATE support_email_settings SET setting_value = '587' WHERE setting_key = 'smtp_port';
UPDATE support_email_settings SET setting_value = 'support@caminhoit.com' WHERE setting_key = 'smtp_username';
UPDATE support_email_settings SET setting_value = 'your_password_here' WHERE setting_key = 'smtp_password';
UPDATE support_email_settings SET setting_value = 'tls' WHERE setting_key = 'smtp_encryption';
UPDATE support_email_settings SET setting_value = 'support@caminhoit.com' WHERE setting_key = 'smtp_from_email';
UPDATE support_email_settings SET setting_value = 'CaminhoIT Support' WHERE setting_key = 'smtp_from_name';
```

### For Gmail

```sql
UPDATE support_email_settings SET setting_value = 'smtp.gmail.com' WHERE setting_key = 'smtp_host';
UPDATE support_email_settings SET setting_value = '587' WHERE setting_key = 'smtp_port';
UPDATE support_email_settings SET setting_value = 'your-email@gmail.com' WHERE setting_key = 'smtp_username';
UPDATE support_email_settings SET setting_value = 'your-app-password' WHERE setting_key = 'smtp_password';
UPDATE support_email_settings SET setting_value = 'tls' WHERE setting_key = 'smtp_encryption';
UPDATE support_email_settings SET setting_value = 'your-email@gmail.com' WHERE setting_key = 'smtp_from_email';
UPDATE support_email_settings SET setting_value = 'CaminhoIT Support' WHERE setting_key = 'smtp_from_name';
```

**Note for Gmail**: You must use an [App Password](https://support.google.com/accounts/answer/185833), not your regular password.

## Testing Email Sending

Create `test-email.php`:

```php
<?php
require_once 'includes/config.php';
require_once 'includes/TicketNotifications.php';

// Test sending to a real ticket
$ticket_id = 1; // Change to an actual ticket ID

$notifications = new TicketNotifications($pdo);
$result = $notifications->notifyTicketRaised($ticket_id);

echo "Notification sent: " . ($result ? "Success" : "Failed");
?>
```

Access via: `https://caminhoit.com/test-email.php`

Check the `support_notification_log` table for results:

```sql
SELECT * FROM support_notification_log ORDER BY sent_at DESC LIMIT 10;
```

**IMPORTANT**: Delete `test-email.php` after testing!

## Troubleshooting

### PHPMailer not found

**Error**: `Class 'PHPMailer\PHPMailer\PHPMailer' not found`

**Solution**: Verify the file paths and structure. Make sure files are in `includes/PHPMailer/`

### SMTP Connection Failed

**Error**: `SMTP connect() failed`

**Solutions**:
1. Check firewall settings (port 587 or 465 must be open)
2. Verify SMTP credentials are correct
3. Try changing port to 465 with SSL encryption
4. Check if your hosting provider blocks outbound SMTP

### Authentication Failed

**Error**: `SMTP Error: Could not authenticate`

**Solutions**:
1. Double-check username and password
2. For Office 365: Ensure SMTP auth is enabled
3. For Gmail: Use App Password instead of account password
4. Check if 2FA is enabled on your email account

### Emails go to Spam

**Solutions**:
1. Set up SPF records for your domain
2. Set up DKIM signing
3. Use a verified email address as "From"
4. Make sure reverse DNS is configured

## PHP IMAP Extension

For email importing to work, you need the PHP IMAP extension installed.

### Check if IMAP is installed:

```bash
php -m | grep imap
```

Or create `phpinfo.php`:

```php
<?php phpinfo(); ?>
```

Look for "imap" in the output.

### Install IMAP on cPanel

1. Log in to WHM (Web Host Manager)
2. Go to **EasyApache 4**
3. Click **Customize**
4. Search for "imap"
5. Enable `php-imap` module
6. Click **Review** and **Provision**

### Install IMAP on Ubuntu/Debian

```bash
sudo apt-get install php-imap
sudo service apache2 restart
```

### Install IMAP on CentOS/RHEL

```bash
sudo yum install php-imap
sudo service httpd restart
```

## Next Steps

After PHPMailer is installed:

1. Run the database migration: `migrations/add-ticket-notifications-schema.sql`
2. Configure email settings in database
3. Test notifications
4. Set up Discord webhook (optional)
5. Configure email import
6. Set up cron job for email import

See `SETUP-GUIDE.md` for complete setup instructions.
