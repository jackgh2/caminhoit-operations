# Quick Setup: Guest Tickets System

## What We Built

✅ **Guest Ticket System** - People without accounts can email support and create tickets
✅ **Secure Public Portal** - Guests get unique access links to view/reply to tickets
✅ **Rich Text Editor** - Both staff and guests can use formatting (bold, lists, etc.)
✅ **Email Import Tracking** - Shows when emails were imported vs sent
✅ **Auto-Onboarding** - All guest tickets go to Group ID 4

---

## Installation Steps

### Step 1: Run Database Migration

```bash
cd /home/caminhoit/public_html
mysql -u your_user -p caminhoit_db < migrations/add-guest-tickets.sql
```

This creates the `support_guest_tickets` table and adds guest ticket columns.

### Step 2: Verify Onboarding Group Exists

```sql
SELECT * FROM support_ticket_groups WHERE id = 4;
```

If it doesn't exist, create it:

```sql
INSERT INTO support_ticket_groups (id, name, description, created_at)
VALUES (4, 'Onboarding', 'New customer onboarding and guest inquiries', NOW());
```

### Step 3: Test It!

**Send a test email:**
```
From: your-personal-email@gmail.com
To: support@caminhoit.com
Subject: Test Guest Ticket

This is a test from a non-registered user.
```

**Wait 1 minute** (for cron to import), then check:

```sql
SELECT * FROM support_tickets WHERE is_guest_ticket = 1 ORDER BY id DESC LIMIT 1;
SELECT * FROM support_guest_tickets ORDER BY id DESC LIMIT 1;
```

You should receive an email with an access link like:
```
https://caminhoit.com/public/view-ticket.php?token=ABC123...
```

---

## How Guests Use It

### Creating a Ticket (via Email)
1. Anyone emails `support@caminhoit.com`
2. Ticket created automatically
3. They receive access link via email

### Accessing Their Ticket
1. Click the link in their email
2. See full ticket conversation
3. Post replies with rich text formatting
4. OR reply via email directly

---

## How Staff Use It

### Viewing Guest Tickets
- Open any ticket in staff portal
- See **yellow "Guest Ticket" banner** if it's a guest
- Guest email displayed prominently
- Group shows "Onboarding"

### Replying to Guests
- Reply normally like any ticket
- Guest receives email notification
- Reply shows in public portal instantly

### Managing Guest Tickets
- Assign to staff members
- Change status/priority
- All standard ticket features work

---

## Key Files Created/Modified

### New Files
- `migrations/add-guest-tickets.sql` - Database schema
- `includes/GuestTicketHelper.php` - Guest ticket logic
- `public/view-ticket.php` - Public guest portal
- `GUEST-TICKETS-GUIDE.md` - Full documentation

### Modified Files
- `includes/EmailImport.php` - Handles guest emails
- `operations/staff-view-ticket.php` - Shows guest indicators
- `members/view-ticket.php` - Email import indicators

---

## Security Features

✅ **Token-Based Access** - 64-character secure tokens
✅ **90-Day Expiry** - Tokens expire automatically
✅ **Email Verification** - Only guest's email can reply
✅ **No Account Creation** - No passwords stored
✅ **Secure Links** - One-time unique URLs

---

## Configuration

All guest tickets automatically:
- Go to **Group ID 4** (Onboarding)
- Show **"Guest Ticket"** indicator
- Send access link to guest's email
- Allow replies via web or email

No additional configuration needed!

---

## Troubleshooting

**Guest didn't receive access email?**
- Check email import is running: `tail -f /var/log/php_errors.log`
- Verify PHPMailer is installed
- Check spam folder

**Can't access ticket?**
- Token might be expired (90 days)
- Check token in database: `SELECT * FROM support_guest_tickets WHERE access_token = 'XXX'`

**Guest reply not showing?**
- Ticket might be closed
- Check email import logs
- Verify guest_email matches

---

## Next Steps

1. ✅ Run the migration SQL
2. ✅ Test with a guest email
3. ✅ Verify access link works
4. ✅ Try replying as guest
5. ✅ Check staff view shows correctly

---

**Questions?** See `GUEST-TICKETS-GUIDE.md` for full documentation.

**Last Updated:** November 6, 2025
