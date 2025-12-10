# Ticket Closure & Feedback System Guide

## ✅ What's Been Added

### 1. **Ticket Closure Notifications**
When you close a ticket, the customer now receives:
- ✅ Email notification that their ticket is resolved
- ✅ Request for feedback with star ratings
- ✅ Direct link to leave feedback
- ✅ Link to view the closed ticket
- ✅ Discord webhook notification (purple "Ticket Closed" message)

### 2. **Customer Feedback System**
Customers can rate their support experience with:
- ⭐ Overall rating (1-5 stars)
- ⭐ Response time rating
- ⭐ Resolution quality rating
- ⭐ Staff professionalism rating
- ✅ Was issue resolved? (Yes/Partially/No)
- 👍 Would recommend? (Yes/No)
- 💬 Optional written feedback

### 3. **Secure Feedback Access**
Feedback can be submitted via:
- 🔐 Logged-in user session
- 🔗 Email link with secure token (no login required)

---

## 📁 Files Created

| File | Purpose |
|------|---------|
| `members/ticket-feedback.php` | Customer feedback form |
| `migrations/add-ticket-feedback.sql` | Feedback database table |
| `TICKET-CLOSURE-FEEDBACK-GUIDE.md` | This guide |

## 📝 Files Modified

| File | What Changed |
|------|--------------|
| `includes/TicketNotifications.php` | Added `notifyTicketClosed()` method and email template |
| `operations/staff-view-ticket.php` | Added closure notification trigger |
| `migrations/add-ticket-notifications-schema.sql` | Added `notify_customer_on_ticket_closed` setting |
| `cron/import-ticket-emails.php` | Fixed syntax error with comment block |

---

## 🚀 Setup Instructions

### Step 1: Run Database Migration

In phpMyAdmin, import:
```
migrations/add-ticket-feedback.sql
```

This creates:
- `support_ticket_feedback` table
- `notify_customer_on_ticket_closed` setting

If you haven't run the main migration yet, also import:
```
migrations/add-ticket-notifications-schema.sql
```

### Step 2: Configure Settings (Optional)

The notification is enabled by default. To disable:
```sql
UPDATE support_email_settings
SET setting_value = '0'
WHERE setting_key = 'notify_customer_on_ticket_closed';
```

### Step 3: Test It!

1. Create a test ticket
2. Reply to it as staff
3. **Close the ticket** (change status to "Closed")
4. ✅ Customer receives email with feedback link
5. ✅ Discord shows purple "Ticket Closed" notification
6. Click feedback link in email
7. Submit feedback with ratings

---

## 📧 Email Notification Preview

When you close a ticket, customer receives:

**Subject:** "Ticket #11 Closed - [Subject]"

**Email contains:**
- 🔒 "Ticket Resolved" header (purple gradient)
- Ticket details (ID, subject, status, closed date)
- ⭐ Star ratings section
- "Leave Feedback" button (secure link)
- "View Ticket" button
- Professional branding

---

## 🎨 Feedback Form Features

### Beautiful UI
- Responsive design
- Large star ratings
- Color-coded buttons
- Success confirmation page
- Mobile-friendly

### Security
- Token-based access via email
- Session-based access when logged in
- Prevents duplicate feedback
- Only closed tickets can receive feedback

### Ratings Collected
1. **Overall Experience** (1-5 stars, required)
2. **Response Time** (1-5 stars, optional)
3. **Resolution Quality** (1-5 stars, optional)
4. **Staff Professionalism** (1-5 stars, optional)
5. **Issue Resolved?** (Yes/Partially/No)
6. **Would Recommend?** (Yes/No)
7. **Written Comments** (Optional)

---

## 🔍 Viewing Feedback

### SQL Queries

**Recent feedback:**
```sql
SELECT
    tf.*,
    t.subject,
    u.username,
    u.email
FROM support_ticket_feedback tf
LEFT JOIN support_tickets t ON tf.ticket_id = t.id
LEFT JOIN users u ON tf.user_id = u.id
ORDER BY tf.created_at DESC
LIMIT 20;
```

**Average ratings:**
```sql
SELECT
    COUNT(*) as total_feedback,
    AVG(rating) as avg_overall,
    AVG(response_time_rating) as avg_response_time,
    AVG(resolution_quality_rating) as avg_resolution,
    AVG(staff_professionalism_rating) as avg_professionalism,
    SUM(CASE WHEN helpful = 'yes' THEN 1 ELSE 0 END) as resolved_count,
    SUM(CASE WHEN would_recommend = 1 THEN 1 ELSE 0 END) as recommend_count
FROM support_ticket_feedback;
```

**Feedback for specific ticket:**
```sql
SELECT * FROM support_ticket_feedback WHERE ticket_id = 11;
```

**Low ratings (needs attention):**
```sql
SELECT
    tf.*,
    t.subject,
    u.username
FROM support_ticket_feedback tf
LEFT JOIN support_tickets t ON tf.ticket_id = t.id
LEFT JOIN users u ON tf.user_id = u.id
WHERE tf.rating <= 2
ORDER BY tf.created_at DESC;
```

---

## 📊 Future Analytics Integration

The feedback table is ready for analytics:

### Database Schema
```sql
support_ticket_feedback:
- id (Primary Key)
- ticket_id (Foreign Key)
- user_id (Foreign Key)
- rating (1-5)
- response_time_rating (1-5)
- resolution_quality_rating (1-5)
- staff_professionalism_rating (1-5)
- helpful (yes/no/neutral)
- would_recommend (0/1)
- feedback_text (TEXT)
- created_at (TIMESTAMP)
```

### Analytics Ideas
1. **Dashboard Widget** - Show average rating
2. **Staff Performance** - Rating by assigned staff member
3. **Trend Analysis** - Ratings over time
4. **NPS Score** - Calculate Net Promoter Score
5. **Issue Categories** - Common feedback themes
6. **Response Time Correlation** - Fast response = higher ratings?

### Sample Analytics Queries

**Staff performance:**
```sql
SELECT
    a.username as staff_member,
    COUNT(tf.id) as tickets_closed,
    AVG(tf.rating) as avg_rating,
    AVG(tf.staff_professionalism_rating) as professionalism
FROM support_tickets t
LEFT JOIN support_ticket_feedback tf ON t.id = tf.ticket_id
LEFT JOIN users a ON t.assigned_to = a.id
WHERE t.status = 'Closed'
GROUP BY a.id, a.username
ORDER BY avg_rating DESC;
```

**Monthly trends:**
```sql
SELECT
    DATE_FORMAT(created_at, '%Y-%m') as month,
    COUNT(*) as feedback_count,
    AVG(rating) as avg_rating,
    AVG(response_time_rating) as avg_response_time,
    SUM(CASE WHEN helpful = 'yes' THEN 1 ELSE 0 END) as resolved_count
FROM support_ticket_feedback
GROUP BY DATE_FORMAT(created_at, '%Y-%m')
ORDER BY month DESC;
```

**Net Promoter Score (NPS):**
```sql
SELECT
    SUM(CASE WHEN would_recommend = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*) as promoter_percentage,
    SUM(CASE WHEN would_recommend = 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(*) as detractor_percentage,
    (SUM(CASE WHEN would_recommend = 1 THEN 1 ELSE 0 END) - SUM(CASE WHEN would_recommend = 0 THEN 1 ELSE 0 END)) * 100.0 / COUNT(*) as nps_score
FROM support_ticket_feedback
WHERE would_recommend IS NOT NULL;
```

---

## 🎮 Discord Notification

When ticket is closed:
- 🟣 **Purple embed** (color: 9442302)
- Title: "🔒 Ticket Closed"
- Shows: Ticket ID, subject, customer, company, status
- Message: "Customer has been asked for feedback."

---

## 🔧 Configuration Options

### Enable/Disable Features

**Disable closure notifications:**
```sql
UPDATE support_email_settings
SET setting_value = '0'
WHERE setting_key = 'notify_customer_on_ticket_closed';
```

**Change feedback secret key:**
Edit `members/ticket-feedback.php` line 687:
```php
$feedbackUrl = "..." . md5($ticket_id . $ticket['customer_email'] . 'your_custom_secret_here');
```

---

## ✅ Complete Notification Flow

### When Ticket Is Closed:

1. **Staff closes ticket** (changes status to "Closed")
2. **System triggers notification**
   - Calls `notifyTicketClosed()` method
   - Generates secure feedback link
3. **Customer receives email**
   - Purple gradient "Ticket Resolved" header
   - Ticket details
   - Feedback request with stars
   - "Leave Feedback" button
   - "View Ticket" button
4. **Discord notification sent** (if enabled)
   - Purple "Ticket Closed" embed
5. **Customer clicks "Leave Feedback"**
   - Opens feedback form
   - Can access with token (no login needed)
6. **Customer submits feedback**
   - Ratings saved to database
   - Success confirmation shown
   - Can only submit once per ticket

---

## 🧪 Testing Checklist

- [ ] Run feedback migration SQL
- [ ] Close a test ticket
- [ ] Check customer receives closure email
- [ ] Click feedback link in email
- [ ] Submit feedback with ratings
- [ ] Verify feedback saved in database
- [ ] Check Discord shows purple "Ticket Closed" notification
- [ ] Verify cannot submit feedback twice
- [ ] Check feedback only works for closed tickets
- [ ] Test with logged-in user
- [ ] Test with email link (logged out)

---

## 📌 Important Notes

### Security
- Feedback links use MD5 token (ticket_id + email + secret)
- Tokens are unique per ticket and customer
- Only one feedback per customer per ticket
- Only closed tickets can receive feedback

### Email vs Session Access
- **Email link** - Works even if customer not logged in
- **Logged in** - Can access via session if already logged in
- Both methods verify ownership

### Preventing Duplicates
- Database unique constraint on (ticket_id, user_id)
- Form checks for existing feedback
- Shows "already submitted" message if duplicate

---

## 🐛 Troubleshooting

### "No email received when closing ticket"
1. Check PHPMailer is installed
2. Verify SMTP settings
3. Check `notify_customer_on_ticket_closed` is '1'
4. Review notification log:
   ```sql
   SELECT * FROM support_notification_log
   WHERE notification_type = 'email'
   AND subject LIKE '%Closed%'
   ORDER BY sent_at DESC;
   ```

### "Feedback link doesn't work"
1. Verify ticket is closed
2. Check URL has both `id` and `token` parameters
3. Token must match: `md5(ticket_id + email + secret)`

### "Can't submit feedback"
1. Check ticket status is "Closed"
2. Verify feedback table exists
3. Check for existing feedback:
   ```sql
   SELECT * FROM support_ticket_feedback WHERE ticket_id = X;
   ```

### "Discord not showing closed tickets"
1. Verify `discord_webhook_enabled` is '1'
2. Check webhook URL is correct
3. Test webhook manually

---

## 📚 Related Documentation

- **NOTIFICATION-SUMMARY.md** - Complete notification system overview
- **QUICK-START-CPANEL.md** - Initial setup guide
- **TICKET-NOTIFICATIONS-SETUP-GUIDE.md** - Detailed setup guide

---

**Ticket closure notifications and feedback system are now fully operational!** 🎉

Customers will receive beautiful emails asking for feedback every time you close their ticket, and you can track all ratings in the database for analytics.

---

**Last Updated:** 2025-11-06
**Version:** 1.2.0
