# Guest Tickets System Guide

## Overview

The Guest Tickets system allows people **without CaminhoIT accounts** to create support tickets by sending emails to your support address. They can then view and reply to their tickets via a secure public portal.

## Features

✅ **Email-to-Ticket**: Anyone can email support@ and create a ticket
✅ **Secure Access**: Unique access tokens sent via email
✅ **Public Portal**: View and reply without logging in
✅ **Rich Text**: Formatted replies with bold, lists, etc.
✅ **Auto-Onboarding**: All guest tickets go to Group ID 4 (Onboarding)
✅ **Email Import Tracking**: Shows when tickets/replies were imported

---

## How It Works

### 1. Guest Sends Email
```
From: customer@example.com
To: support@caminhoit.com
Subject: Need help with my account

I can't access my account. Can you help?
```

### 2. System Creates Ticket
- ✅ Email is imported via IMAP
- ✅ No user account found → Create **Guest Ticket**
- ✅ Ticket assigned to Group ID 4 (Onboarding)
- ✅ Access token generated
- ✅ Email sent to guest with secure link

### 3. Guest Receives Access Email
```
Subject: Your Support Ticket #123

Your ticket has been created successfully.

Click here to view your ticket:
https://caminhoit.com/public/view-ticket.php?token=ABC123...

You can also reply by replying to this email.
```

### 4. Guest Views & Replies
- Guest clicks link → Sees ticket details
- Can read all staff replies
- Can post new replies (with rich text formatting)
- Staff get notified of guest replies

---

## Database Setup

### 1. Run Migration

```bash
mysql -u your_user -p your_database < migrations/add-guest-tickets.sql
```

This creates:
- `support_guest_tickets` table
- Adds `is_guest_ticket` and `guest_email` columns to `support_tickets`

### 2. Verify Tables

```sql
-- Check guest tickets table
DESCRIBE support_guest_tickets;

-- Check support_tickets has new columns
SHOW COLUMNS FROM support_tickets LIKE 'is_guest_ticket';
SHOW COLUMNS FROM support_tickets LIKE 'guest_email';
```

---

## Configuration

### Email Import Settings

Make sure email import is configured (already done):

```sql
SELECT * FROM support_email_import_settings;
```

Key settings:
- `import_enabled` = 1
- `import_protocol` = imap
- `import_encryption` = none (for port 143)
- `import_username` = support@caminhoit.com

### Onboarding Group

Guest tickets automatically go to Group ID 4. Verify it exists:

```sql
SELECT * FROM support_ticket_groups WHERE id = 4;
```

If not, create it:

```sql
INSERT INTO support_ticket_groups (id, name, description)
VALUES (4, 'Onboarding', 'New customer onboarding and inquiries');
```

---

## Staff View Features

### Guest Ticket Indicators

Staff will see clear indicators for guest tickets:

**In Ticket List:**
- 🔵 Badge showing "Guest Ticket"
- Email address shown instead of username
- Group shows "Onboarding"

**In Ticket Detail:**
- 📧 "Guest Ticket" banner at top
- Email address displayed prominently
- "Reply as Staff" works normally

**Staff Can:**
- ✅ View all guest tickets
- ✅ Reply normally (replies go via email)
- ✅ Assign to staff members
- ✅ Change status/priority
- ✅ See email import timestamps

---

## Guest Workflow

### Creating a Ticket (via Email)

1. Guest sends email to support@caminhoit.com
2. Email is imported (runs every 1 minute via cron)
3. System checks if sender has account → No
4. Creates guest ticket in Onboarding group
5. Sends access link email to guest

### Accessing the Ticket

**Via Secure Link:**
```
https://caminhoit.com/public/view-ticket.php?token=ABC123...
```

- Token is unique and secure (64 characters)
- Expires in 90 days
- Can be regenerated if lost

**Via Email Reply:**
- Guest replies to any ticket email
- System imports reply automatically
- Matches to ticket by #ID or guest email

### Replying to Ticket

**Method 1: Web Portal**
- Click access link
- See full conversation
- Use rich text editor
- Click "Submit Reply"

**Method 2: Email Reply**
- Reply to any email from support
- Email is imported automatically
- Shows as "Email Import" in conversation

---

## Security

### Access Control

✅ **Token-Based Access**
- 64-character random tokens
- Stored securely in database
- Expire after 90 days
- One token per ticket

✅ **Email Verification**
- Guest can only access their own tickets
- Replies must come from same email
- Staff replies work normally

✅ **No Account Required**
- Guest never creates password
- No PII stored beyond email
- GDPR compliant

### Token Expiry

Tokens expire after 90 days. To regenerate:

```sql
-- Find guest ticket
SELECT * FROM support_guest_tickets WHERE guest_email = 'customer@example.com';

-- Regenerate token (manual process)
UPDATE support_guest_tickets
SET access_token = '<new-token>',
    token_expires_at = DATE_ADD(NOW(), INTERVAL 90 DAY)
WHERE id = <id>;
```

---

## Troubleshooting

### Guest Ticket Not Created

**Check email import logs:**
```bash
tail -f /var/log/php_errors.log
```

Look for:
- "Creating guest ticket"
- "Created guest ticket #123"

**Common issues:**
- Email import disabled
- IMAP connection failed
- Email from support@ itself (filtered out)

### Guest Can't Access Ticket

**Check token:**
```sql
SELECT * FROM support_guest_tickets WHERE access_token = '<token>';
```

Verify:
- Token exists
- `token_expires_at` > NOW()
- `ticket_id` is valid

### Guest Reply Not Working

**Check ticket status:**
```sql
SELECT * FROM support_tickets WHERE id = <id> AND is_guest_ticket = 1;
```

Verify:
- `status` != 'Closed'
- `guest_email` matches sender
- `is_guest_ticket` = 1

---

## Migration Path

### Converting Guest to Registered User

If a guest later creates an account:

```sql
-- Find guest ticket
SELECT * FROM support_tickets WHERE guest_email = 'customer@example.com';

-- Update to regular ticket
UPDATE support_tickets
SET is_guest_ticket = 0,
    user_id = <new-user-id>,
    company_id = <company-id>
WHERE id = <ticket-id> AND is_guest_ticket = 1;

-- Keep guest access record for audit
-- (automatically hidden once is_guest_ticket = 0)
```

---

## Cron Job

Email import runs every minute:

```bash
* * * * * cd /home/caminhoit/public_html && php cron/import-emails.php >> /var/log/email-import.log 2>&1
```

Check if running:
```bash
ps aux | grep import-emails
```

---

## Support

For questions about the Guest Tickets system, contact the development team or refer to:

- `/includes/GuestTicketHelper.php` - Core guest ticket logic
- `/includes/EmailImport.php` - Email import with guest support
- `/public/view-ticket.php` - Public guest portal
- `/migrations/add-guest-tickets.sql` - Database schema

---

**Last Updated:** November 6, 2025
**Version:** 1.0.0
**Author:** CaminhoIT Development Team
