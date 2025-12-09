# Discord Notifications System 🔔

Complete Discord webhook integration for orders and subscriptions.

## 📋 Features

### Order Notifications
- ✅ **New Order Placed** - When customer places order (pending approval)
- ✅ **Order Approved** - When staff approves order
- ✅ **Order Rejected** - When staff rejects order with reason
- ✅ **Payment Received** - When payment is confirmed (Stripe/Bank Transfer)
- ✅ **Services Delivered** - When auto-delivery completes
- ✅ **Staff Order Created** - When staff creates an order manually

### Subscription Notifications
- ✅ **Subscription Created** - When new subscription is set up
- ✅ **Subscription Expiring** - At 30, 14, 7, 3, and 1 days before expiry
- ✅ **Status Changed** - When subscription is activated/suspended/cancelled
- ✅ **Low Inventory** - When licenses are running low (<10% or <5 remaining)

## 🔧 Setup

### 1. Run Database Migration

Run this in phpMyAdmin:
```sql
SOURCE /path/to/migrations/add-notification-log.sql;
```

Or copy/paste the contents of `migrations/add-notification-log.sql`

### 2. Configure Discord Webhook

#### Create Webhook in Discord:
1. Go to your Discord server
2. Server Settings → Integrations → Webhooks
3. Click "New Webhook"
4. Name it (e.g., "CaminhoIT Orders" or "CaminhoIT Subscriptions")
5. Select the channel where notifications should appear
6. Copy the webhook URL

#### Update Database:
```sql
-- Use config_key for system_config table
UPDATE system_config
SET config_value = 'https://discord.com/api/webhooks/YOUR_WEBHOOK_URL_HERE'
WHERE config_key = 'discord_webhook_url';

UPDATE system_config
SET config_value = '1'
WHERE config_key = 'discord_notifications_enabled';
```

### 3. Set Up Cron Job (For Expiring Subscriptions)

Add this to your crontab:
```bash
# Run daily at 9 AM
0 9 * * * php /home/caminhoit/public_html/cron/check-expiring-subscriptions.php >> /var/log/subscription-check.log 2>&1
```

Or in cPanel Cron Jobs:
- **Command:** `php /home/caminhoit/public_html/cron/check-expiring-subscriptions.php`
- **Schedule:** Once per day at 9:00 AM

## 📨 Notification Examples

### New Order (Orange)
```
🛒 New Order Awaiting Approval

Order Number: ORD-2025-00015
Customer: john.doe
Company: Example Ltd
Total Amount: EUR 450.00

Items:
• Microsoft 365 (Qty: 10)
• Antivirus License (Qty: 5)

[Review Order Button]
```

### Subscription Expiring (Red/Orange)
```
🚨 Subscription Expiring Soon

Subscription will expire in 7 days.

Company: Example Ltd
Product/Bundle: Microsoft 365 Business
Next Billing Date: Nov 14, 2025
Total Price: £299.00
Auto-Renew: ❌ Disabled

[Manage Subscription Button]
```

### Low Inventory (Orange)
```
📉 Low Inventory Alert

Subscription is running low on available licenses.

Company: Example Ltd
Product/Bundle: Antivirus License
Available Licenses: 3 remaining
Total / Assigned: 50 / 47

[Manage Inventory Button]
```

### Subscription Created (Purple)
```
📦 New Subscription Created

A new subscription has been set up for a client.

Company: Example Ltd
Product/Bundle: Microsoft 365 Business
Quantity: 50 licenses
Total Price: £449.50 / monthly
Next Billing: Dec 07, 2025
Created By: admin
```

### Status Changed (Green/Red/Orange)
```
✅ Subscription Activated
Status changed from Pending to Active

Company: Example Ltd
Product/Bundle: Microsoft 365 Business
Quantity: 50 licenses
```

## 🎨 Color Coding

| Color | Usage |
|-------|-------|
| 🟢 Green | Success (approved, paid, activated, delivered) |
| 🔴 Red | Critical (rejected, cancelled, 7-day expiry) |
| 🟠 Orange | Warning (new order, 30-day expiry, suspended, low inventory) |
| 🔵 Blue | Info (staff order created, pending) |
| 🟣 Purple | New item (subscription created) |

## 📁 Files Modified/Created

### Core System:
- ✅ `includes/DiscordNotifications.php` - Main notification class (updated with subscription methods)

### Order Integration:
- ✅ `members/checkout.php` - Sends notification when customer places order
- ✅ `operations/pending-orders.php` - Sends notifications for approve/reject
- ✅ `operations/create-order.php` - Sends notification for staff-created orders

### Subscription Integration:
- ✅ `operations/manage-subscriptions.php` - Sends notifications for subscription events

### Automation:
- ✅ `cron/check-expiring-subscriptions.php` - Daily check for expiring subscriptions and low inventory

### Database:
- ✅ `migrations/add-notification-log.sql` - Notification tracking table

## 🔔 Notification Methods

### Available Methods in DiscordNotifications Class:

```php
$discord = new DiscordNotifications($pdo);

// Order notifications
$discord->notifyNewOrder($order_id);
$discord->notifyOrderApproved($order_id, $approved_by);
$discord->notifyOrderRejected($order_id, $rejected_by, $reason);
$discord->notifyPaymentReceived($invoice_id);
$discord->notifyServicesDelivered($order_id, $delivery_details);
$discord->notifyStaffOrderCreated($order_id);

// Subscription notifications
$discord->notifySubscriptionCreated($subscription_id);
$discord->notifySubscriptionExpiring($subscription_id, $days_until_expiry);
$discord->notifySubscriptionStatusChange($subscription_id, $old_status, $new_status);
$discord->notifyLowInventory($subscription_id, $available_quantity);
```

## 🧪 Testing

### Test Order Notifications:
1. Place an order as a customer from service catalog
2. Check Discord for "New Order" notification
3. Approve order in `/operations/pending-orders.php`
4. Check Discord for "Order Approved" notification

### Test Subscription Notifications:
1. Create subscription from `/operations/manage-subscriptions.php`
2. Check Discord for "Subscription Created" notification
3. Change status to "Suspended"
4. Check Discord for "Status Changed" notification

### Test Cron Job:
```bash
php /home/caminhoit/public_html/cron/check-expiring-subscriptions.php
```

Check output for expiring subscriptions and low inventory alerts.

## 🐛 Troubleshooting

### No Notifications Appearing:

1. **Check webhook URL is correct:**
```sql
SELECT config_value FROM system_config WHERE config_key = 'discord_webhook_url';
```

2. **Check notifications are enabled:**
```sql
SELECT config_value FROM system_config WHERE config_key = 'discord_notifications_enabled';
-- Should return '1'
```

3. **Check PHP error logs:**
```bash
tail -f /path/to/php_error.log
```

4. **Test webhook directly:**
```bash
curl -X POST YOUR_WEBHOOK_URL \
  -H "Content-Type: application/json" \
  -d '{"content": "Test message from CaminhoIT"}'
```

### Duplicate Notifications:

The `notification_log` table prevents duplicate notifications for:
- Expiring subscriptions (max 1 per day per threshold)
- Low inventory (max 1 per day)

Check logs:
```sql
SELECT * FROM notification_log
WHERE created_at >= CURDATE()
ORDER BY created_at DESC;
```

## 📊 Notification Log

View all sent notifications:
```sql
SELECT
    entity_type,
    entity_id,
    notification_type,
    channel,
    status,
    created_at
FROM notification_log
ORDER BY created_at DESC
LIMIT 50;
```

## 🔒 Security

- Webhook URL is stored securely in database
- Only accessible to administrator/accountant/support roles
- No sensitive data included in notifications
- Can be disabled globally via config

## 📚 Configuration Options

### System Config Keys:

| Key | Type | Description |
|-----|------|-------------|
| `discord_webhook_url` | string | Discord webhook URL |
| `discord_notifications_enabled` | boolean | Enable/disable all notifications |

## ✨ Next Steps

1. ✅ Run `migrations/add-notification-log.sql`
2. ✅ Configure Discord webhook URL
3. ✅ Enable notifications
4. ✅ Set up cron job
5. ✅ Test all notification types
6. 📝 Create dedicated Discord channels for different notification types (optional)

---

**Status:** Complete & Production Ready 🎉
**Last Updated:** 2025-11-07
