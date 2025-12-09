# Admin Tools & Approval Workflow Guide

## Overview
Complete admin toolkit for managing orders, payments, and approvals with Discord notifications.

## ✅ Completed Features

### 1. Discord Notifications System
**File:** `includes/DiscordNotifications.php`

Sends rich embed notifications to Discord for:
- 🛒 **New Order Placed** - When customer submits order (pending approval)
- ✅ **Order Approved** - When staff approves order
- ❌ **Order Rejected** - When staff rejects order
- 💰 **Payment Received** - When payment confirmed
- 🚀 **Services Delivered** - When auto-delivery completes

**Configuration Required:**
```sql
INSERT INTO system_config VALUES
('discord_webhook_url', 'your_webhook_url_here', 'text', 'Discord Webhook URL'),
('discord_notifications_enabled', '1', 'boolean', 'Enable Discord notifications');
```

### 2. Pending Approval Queue
**File:** `operations/pending-orders.php`

Staff dashboard for reviewing and approving orders:
- View all pending orders
- Order details panel with full breakdown
- **Approve** button - Generates invoice & sends to customer
- **Reject** button - With reason (notifies customer)
- Discord notifications on approve/reject
- Email notifications sent automatically

**Workflow:**
```
Customer Places Order → "pending_approval" status
↓
Discord notification sent to staff channel
↓
Staff reviews in queue → Clicks "Approve" or "Reject"
↓
IF APPROVED: Invoice generated → Email sent → Status = "pending_payment"
IF REJECTED: Customer notified → Status = "rejected"
```

### 3. Payments Dashboard
**File:** `operations/payments.php`

Comprehensive payment management:
- **Statistics Cards:**
  - Total Invoiced
  - Total Paid
  - Unpaid Amount
  - Estimated Stripe Fees
- **Payment Methods Breakdown** - Visual progress bars
- **Filters:**
  - Status (paid/unpaid)
  - Payment method (Stripe/Bank/Manual)
  - Date range
- **All Invoices Table:**
  - Invoice & order numbers
  - Customer & company details
  - Payment status badges
  - Payment method badges
  - Quick actions (download PDF, view details, mark as paid)

### 4. Updated Checkout Flow
**File:** `members/checkout.php`

Now creates orders as `pending_approval` instead of immediately generating invoices:
- Order placed → pending_approval status
- Discord notification sent
- Order confirmation email sent
- Redirects to order-pending.php (not confirmation)

### 5. Order Pending Page
**File:** `members/order-pending.php`

Customer-facing page showing order awaiting approval:
- Timeline showing approval progress
- Order details and items
- Status updates
- What happens next information

## 🔧 Configuration

### Database Migrations

Run in this order:

```bash
1. migrations/add-payment-system.sql
2. migrations/add-auto-delivery.sql
3. migrations/add-approval-workflow.sql  # NEW
```

**add-approval-workflow.sql** adds:
- `approval_notes` column to orders
- `approved_by` column (staff user ID)
- `approved_at` column
- Updates status enum to include: `pending_approval`, `rejected`
- Discord configuration in system_config

### Discord Setup

1. **Create Discord Webhook:**
   - Go to your Discord server
   - Server Settings → Integrations → Webhooks
   - Create webhook for orders channel
   - Copy webhook URL

2. **Configure in Database:**
```sql
UPDATE system_config SET setting_value = 'YOUR_WEBHOOK_URL' WHERE setting_key = 'discord_webhook_url';
UPDATE system_config SET setting_value = '1' WHERE setting_key = 'discord_notifications_enabled';
```

3. **Test Notification:**
```php
require_once 'includes/DiscordNotifications.php';
$discord = new DiscordNotifications($pdo);
$discord->notifyNewOrder($order_id);
```

## 📊 New Order Statuses

Updated workflow with approval:

| Status | Description | Trigger |
|--------|-------------|---------|
| `draft` | Cart not checked out yet | Initial state |
| `pending_approval` | Awaiting staff approval | Customer places order |
| `pending_payment` | Approved, awaiting payment | Staff approves |
| `paid` | Payment received | Payment confirmed |
| `completed` | Services delivered | Auto-delivery success |
| `partially_completed` | Some items failed delivery | Partial auto-delivery |
| `rejected` | Staff rejected order | Staff rejects |
| `cancelled` | Customer/admin cancelled | Manual cancellation |

## 🎯 Staff Workflow

### Approving Orders

1. **Receive Discord Notification:**
   - New order alert with details
   - Click "Review Order" button link

2. **Review in Queue:**
   - Go to `operations/pending-orders.php`
   - Click order from list
   - Review customer, company, items, totals

3. **Approve:**
   - Add optional approval notes
   - Click "Approve Order & Generate Invoice"
   - System automatically:
     - Generates PDF invoice
     - Sends invoice email to customer
     - Updates status to pending_payment
     - Sends Discord approval notification

4. **Reject:**
   - Click "Reject Order" button
   - Enter rejection reason (required)
   - Customer notified via Discord

### Managing Payments

1. **View Dashboard:**
   - Go to `operations/payments.php`
   - See total invoiced, paid, unpaid
   - View estimated Stripe fees
   - Filter by status, method, date range

2. **Mark Manual Payments:**
   - For bank transfers received
   - Click eye icon to view invoice
   - Click "Mark as Paid"
   - Enter transaction details

## 🚨 Still To Build

### 1. Admin Invoice Management Page
**File:** `operations/invoices.php` (NOT BUILT YET)

Will include:
- Individual invoice detail view
- Mark as paid manually (for bank transfers)
- Regenerate PDF
- Send/resend invoice email
- View payment logs
- Refund functionality

### 2. Admin Order Creation Tool
**File:** `operations/create-order.php` (NOT BUILT YET)

Will include:
- Select customer & company
- Add products/licenses manually
- Set quantities and billing cycles
- Choose currency
- Apply custom pricing/discounts
- Option to mark as paid immediately
- Option to skip approval (admin orders)
- Generate invoice on creation

### 3. Service Catalog Integration
**File:** `members/service-catalog.php` (UPDATE NEEDED)

Need to add:
- "Add to Cart" buttons instead of "Order Service"
- AJAX cart functionality
- Success notifications
- Cart preview popup

### 4. Navigation Cart Icon
**File:** `includes/nav-auth.php` (UPDATE NEEDED)

Need to add:
- Shopping cart icon in header
- Badge showing item count
- Link to cart page

## 🔄 Currency & Billing Cycle Support

### Supported Currencies
- **GBP** - British Pound (£)
- **USD** - US Dollar ($)
- **EUR** - Euro (€)
- **CAD** - Canadian Dollar (C$)
- **AUD** - Australian Dollar (A$)

Companies can set preferred currency. Each order uses company's currency.

### Supported Billing Cycles
- **Monthly** - Billed every month
- **Quarterly** - Billed every 3 months
- **Semiannually** - Billed every 6 months
- **Annually** - Billed every year
- **Biennially** - Billed every 2 years
- **Triennially** - Billed every 3 years

Each cart item can have its own billing cycle.

## 📈 Payment Fees

### Stripe Fees (Estimated)
- **Rate:** 2.9% + 30p per transaction
- **Calculated automatically** in payments dashboard
- Shows estimated fees for selected period

### No Fees
- Bank transfers (free)
- Manual payments (free)

## 🎨 Discord Notification Examples

### New Order
```
🛒 New Order Awaiting Approval

Order Number: ORD-2025-00123
Customer: john.doe
Company: Example Ltd
Total Amount: GBP 450.00

Items:
• Microsoft 365 Business (Qty: 5)
• Antivirus License (Qty: 5)

[Review Order Button]
```

### Order Approved
```
✅ Order Approved

Order Number: ORD-2025-00123
Customer: john.doe
Total Amount: GBP 450.00
Approved By: admin_user
```

### Payment Received
```
💰 Payment Received

Invoice Number: INV-2025-00089
Order Number: ORD-2025-00123
Customer: john.doe
Amount Paid: GBP 450.00
Payment Method: Stripe
Transaction ID: pi_3AbC123...
```

## 📝 Testing Checklist

- [ ] Run all database migrations
- [ ] Configure Discord webhook
- [ ] Enable Discord notifications
- [ ] Place test order as customer
- [ ] Verify Discord notification received
- [ ] Approve order in staff queue
- [ ] Verify invoice generated
- [ ] Verify invoice email sent
- [ ] Make test Stripe payment
- [ ] Verify payment notification
- [ ] Check auto-delivery triggered
- [ ] View payments dashboard
- [ ] Filter payments by different criteria
- [ ] Reject a test order
- [ ] Verify rejection notification

## 🎯 Next Priority

1. **Build admin order creation tool** - For manual orders
2. **Build admin invoice management** - For detailed invoice actions
3. **Update service catalog** - Add "Add to Cart" buttons
4. **Add cart icon to nav** - Show cart count

---

**Status:** Core workflow complete, admin tools 60% done
**Last Updated:** 2025-11-06
