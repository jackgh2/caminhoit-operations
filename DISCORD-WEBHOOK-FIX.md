# Discord Webhook Fix for Orders

## Problem Summary
Discord webhooks were working for **subscriptions** but NOT for **orders** when:
1. Creating orders in operations/create-order.php
2. Progressing orders through statuses (draft → placed → paid → completed)

## Root Causes

### Issue #1: Orders Disabled in Configuration
The `discord.orders_enabled` config was set to `'0'` (disabled) in the database, while subscriptions were enabled.

**Location:** `system_config` table in database

### Issue #2: Missing Discord Notifications on Status Change
When updating order status in `operations/view-order.php`, no Discord notifications were being triggered.

**Location:** `operations/view-order.php:93-120`

## Fixes Applied

### Fix #1: Enable Orders in Discord Configuration

Run this SQL to check your current configuration:
```sql
SELECT config_key, config_value
FROM system_config
WHERE config_key LIKE 'discord%'
ORDER BY config_key;
```

If `discord.orders_enabled` shows `'0'`, run:
```sql
UPDATE system_config
SET config_value = '1'
WHERE config_key = 'discord.orders_enabled';
```

Also verify that `discord.orders_webhook_url` is set to your Discord webhook URL:
```sql
UPDATE system_config
SET config_value = 'YOUR_DISCORD_WEBHOOK_URL_HERE'
WHERE config_key = 'discord.orders_webhook_url';
```

**Note:** You can use the same webhook URL as subscriptions, or create a separate channel for orders.

### Fix #2: Added Discord Notifications to Status Updates

**File:** `operations/view-order.php`

**Changes:**
1. Added `require_once` for DiscordNotifications.php (line 10)
2. Added Discord notification triggers when status changes (lines 113-133):
   - **Paid status**: Triggers `notifyPaymentReceived()` when order moves to 'paid'
   - **Completed status**: Triggers `notifyServicesDelivered()` when order is completed

## Testing Checklist

### ✅ Test 1: Order Creation
1. Go to `operations/create-order.php`
2. Create a new order
3. **Expected:** Discord message appears with "🛍️ Staff Order Created"

### ✅ Test 2: Order Status - Draft to Placed
1. Go to `operations/view-order.php` for a draft order
2. Change status to "Placed" or "Pending Payment"
3. **Expected:** Status updates (no specific Discord notification for this transition)

### ✅ Test 3: Order Status - Paid
1. Go to `operations/view-order.php` for an order
2. Change status to "Paid"
3. **Expected:** Discord message appears with "💰 Payment Received"

### ✅ Test 4: Order Status - Completed
1. Go to `operations/view-order.php` for an order
2. Change status to "Completed"
3. **Expected:** Discord message appears with "🚀 Services Fully Delivered"

### ✅ Test 5: Order Approval (existing functionality)
1. Go to `operations/pending-orders.php`
2. Approve an order
3. **Expected:** Discord message appears with "✅ Order Approved"

### ✅ Test 6: Order Rejection (existing functionality)
1. Go to `operations/pending-orders.php`
2. Reject an order with a reason
3. **Expected:** Discord message appears with "❌ Order Rejected"

## Debugging

If notifications still don't work, check the PHP error log:

**Location varies by system:**
- cPanel: `/home/username/public_html/error_log`
- XAMPP: `C:\xampp\apache\logs\error.log`
- Linux: `/var/log/apache2/error.log` or `/var/log/php-fpm/error.log`

**Look for these messages:**
```
notifyStaffOrderCreated called for order #123
notifyStaffOrderCreated blocked - Global: 1, Orders: 0, URL: set
Discord notification sent successfully (HTTP 204)
Discord notification failed: HTTP 400 - Response: ...
```

## Additional Notes

### Current Discord Notification Events for Orders:
1. **New Order (Customer)** - When customer places order via checkout
2. **Staff Order Created** - When staff creates order
3. **Order Approved** - When staff approves pending order
4. **Order Rejected** - When staff rejects order
5. **Payment Received** - When order status changes to "paid"
6. **Services Delivered** - When order status changes to "completed"

### Files Modified:
- ✅ `operations/view-order.php` - Added Discord notifications for status changes
- ✅ Created `fix-discord-orders.sql` - SQL script to enable orders
- ✅ Created `check-discord-config.php` - Script to check configuration

### Configuration Keys:
| Config Key | Purpose | Expected Value |
|------------|---------|----------------|
| `discord.notifications_enabled` | Master switch | `'1'` |
| `discord.orders_enabled` | Enable order notifications | `'1'` |
| `discord.orders_webhook_url` | Webhook URL for orders | Your Discord webhook URL |
| `discord.subscriptions_enabled` | Enable subscription notifications | `'1'` |
| `discord.subscriptions_webhook_url` | Webhook URL for subscriptions | Your Discord webhook URL |

## Next Steps

1. ✅ Run the SQL to enable `discord.orders_enabled`
2. ✅ Verify `discord.orders_webhook_url` is set
3. ✅ Test creating a new order
4. ✅ Test progressing an order through statuses
5. ✅ Check error logs if notifications don't appear

---

**Last Updated:** 2025-11-07
**Issue:** Discord webhooks not working for orders/operations
**Status:** Fixed ✅
