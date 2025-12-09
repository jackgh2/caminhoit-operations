# CaminhoIT E-Commerce System

## Overview
A complete WHMCS-style e-commerce workflow for selling IT services, products, and licenses with automated provisioning.

## System Components

### 1. Shopping Cart System
**File:** `includes/CartManager.php`

Session-based shopping cart that supports:
- Multiple items with quantities
- Different billing cycles (monthly, quarterly, annually, etc.)
- Setup fees
- Company selection for multi-company users
- VAT calculation based on currency settings
- Order creation from cart

**Key Methods:**
- `addItem()` - Add product/license to cart
- `updateQuantity()` - Change item quantity
- `removeItem()` - Remove item from cart
- `getTotals()` - Calculate cart totals with VAT
- `createOrder()` - Convert cart to order

### 2. Cart & Checkout Pages

**Cart Page:** `members/cart.php`
- View all cart items
- Company selection (required)
- Update quantities
- Remove items
- VAT calculation
- Proceed to checkout button

**Checkout Page:** `members/checkout.php`
- Order review
- Company information display
- PO number input (optional)
- Order notes
- Terms & conditions acceptance
- Place order button
- Creates order + generates invoice

**Order Confirmation:** `members/order-confirmation.php`
- Order details
- Invoice download link
- Payment instructions
- Status display

### 3. Invoice Generation System
**File:** `includes/InvoiceGenerator.php`

Professional PDF invoice generation using TCPDF:
- Unique invoice numbers (INV-YYYY-#####)
- Company branding
- Order items breakdown
- VAT calculations
- Payment instructions
- Email delivery capability
- Automatic regeneration support

**Key Methods:**
- `generateInvoice($order_id, $send_email)` - Create PDF invoice
- `markAsPaid($invoice_id, $payment_method, $transaction_id)` - Mark as paid & trigger delivery

### 4. Payment Integration
**File:** `includes/PaymentGateway.php`

Stripe payment integration:
- Checkout session creation
- Webhook handling
- Payment verification
- Transaction logging

**Payment Pages:**
- `members/pay-invoice.php` - Payment method selection
- `members/payment-success.php` - Success confirmation with confetti
- `members/bank-transfer.php` - Bank transfer instructions
- `webhooks/stripe.php` - Stripe webhook endpoint

**Supported Payment Methods:**
1. **Credit/Debit Card** - Stripe checkout
2. **Bank Transfer** - Manual with verification

### 5. Auto-Delivery System
**File:** `includes/AutoDelivery.php`

Automatically provisions services after payment:
- License key generation
- Service activation
- Bundle component delivery
- Delivery logging

**Delivery Types:**
- **Licenses** - Generates unique keys (XXXX-XXXX-XXXX-XXXX format)
- **Products** - Creates service instances
- **Bundles** - Delivers all components

**Key Features:**
- Automatic expiry date calculation
- Billing cycle management
- Partial delivery handling
- Error logging

### 6. Email Notifications
**File:** `includes/OrderNotifications.php`

Automated email flow:
1. **Order Confirmation** - Sent when order placed
2. **Invoice Email** - Payment request with invoice link
3. **Payment Confirmation** - Payment received notification
4. **Service Activation** - License keys and access details

All emails use professional HTML templates with company branding.

## Database Tables

### New Tables Created:

**payment_logs**
- Logs all payment transactions
- Stores Stripe transaction IDs
- Status tracking (pending, completed, failed, refunded)

**licenses**
- Software license keys
- Company and customer assignment
- Status and expiry tracking

**customer_services**
- Active service subscriptions
- Billing cycle management
- Next billing date tracking

**delivery_logs**
- Auto-delivery execution logs
- Success and failure tracking
- Delivered items JSON

**bundle_items**
- Bundle component definitions
- Product relationships

### Modified Tables:

**invoices**
- Added `stripe_session_id` column
- Stores Stripe checkout session IDs

**orders**
- Updated status enum to include `partially_completed`

## Workflow

### Complete Order Flow:

```
1. Customer browses service catalog
   ↓
2. Adds items to cart (members/cart.php)
   ↓
3. Selects company
   ↓
4. Proceeds to checkout (members/checkout.php)
   ↓
5. Reviews order & accepts terms
   ↓
6. Places order → Creates order + invoice
   ↓
7. Redirected to order confirmation
   ↓
8. Receives order confirmation email
   ↓
9. Receives invoice email
   ↓
10. Clicks "Pay Invoice" link
    ↓
11. Selects payment method:

    → STRIPE:
      - Redirected to Stripe checkout
      - Completes payment
      - Webhook updates invoice status
      - Auto-delivery triggered

    → BANK TRANSFER:
      - Views bank details
      - Makes manual transfer
      - Admin marks as paid
      - Auto-delivery triggered
    ↓
12. Payment confirmation email sent
    ↓
13. Auto-delivery provisions services
    ↓
14. Service activation email with license keys
    ↓
15. Customer accesses services from dashboard
```

## Database Migrations

Run these migrations in order:

1. `migrations/add-payment-system.sql`
   - Adds payment_logs table
   - Adds stripe_session_id to invoices
   - Adds payment configuration

2. `migrations/add-auto-delivery.sql`
   - Adds licenses table
   - Adds customer_services table
   - Adds delivery_logs table
   - Adds bundle_items table

## Configuration

### Stripe Setup:
Add to `system_config` table:
- `stripe_publishable_key` - Stripe publishable key
- `stripe_secret_key` - Stripe secret API key
- `stripe_webhook_secret` - Webhook signing secret

### Bank Transfer Setup:
Add to `system_config` table:
- `bank_name` - Your bank name
- `bank_account_name` - Account holder name
- `bank_account_number` - Account number
- `bank_sort_code` - Sort code
- `bank_iban` - IBAN for international transfers
- `bank_swift` - SWIFT/BIC code

### Webhook Configuration:
Set up Stripe webhook at:
```
https://yourdomain.com/webhooks/stripe.php
```

Listen for events:
- `checkout.session.completed`
- `payment_intent.succeeded`
- `payment_intent.payment_failed`

## Integration Points

### To integrate with service catalog:

Update `members/service-catalog.php` to add "Add to Cart" buttons:

```php
<button onclick="addToCart('product', <?= $product['id'] ?>, '<?= htmlspecialchars($product['name']) ?>', <?= $product['price'] ?>, 1, 'monthly', <?= $product['setup_fee'] ?>)">
    <i class="bi bi-cart-plus"></i> Add to Cart
</button>

<script>
function addToCart(type, id, name, price, quantity, billing_cycle, setup_fee) {
    fetch('/members/add-to-cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `type=${type}&id=${id}&name=${encodeURIComponent(name)}&price=${price}&quantity=${quantity}&billing_cycle=${billing_cycle}&setup_fee=${setup_fee}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Added to cart!');
            // Update cart count in navigation
        }
    });
}
</script>
```

### To manually trigger email notifications:

```php
require_once 'includes/OrderNotifications.php';

$notifications = new OrderNotifications($pdo);

// Send order confirmation
$notifications->sendOrderConfirmation($order_id);

// Send invoice
$notifications->sendInvoiceEmail($invoice_id);

// Send payment confirmation
$notifications->sendPaymentConfirmation($invoice_id);

// Send service activation
$notifications->sendServiceActivation($order_id);
```

### To manually trigger delivery:

```php
require_once 'includes/AutoDelivery.php';

$delivery = new AutoDelivery($pdo);
$result = $delivery->processOrderDelivery($order_id);

if ($result['success']) {
    echo "Delivered: " . count($result['delivered']) . " items";
    echo "Failed: " . count($result['failed']) . " items";
}
```

## Security Considerations

1. **Payment Processing**
   - All card payments via Stripe (PCI compliant)
   - Webhook signature verification
   - Transaction logging

2. **Access Control**
   - Customer can only view their own orders
   - Company verification on checkout
   - Session-based cart security

3. **License Generation**
   - Cryptographically random keys
   - Unique key validation
   - Expiry date enforcement

## Testing

### Test the Complete Flow:

1. Add items to cart
2. Select company
3. Checkout with test data
4. Use Stripe test card: `4242 4242 4242 4242`
5. Verify invoice generated
6. Check payment webhook received
7. Confirm auto-delivery executed
8. Check all emails sent

### Stripe Test Cards:
- Success: `4242 4242 4242 4242`
- Decline: `4000 0000 0000 0002`
- Auth required: `4000 0027 6000 3184`

## File Structure

```
CaminhoIT/
├── includes/
│   ├── CartManager.php           # Shopping cart logic
│   ├── InvoiceGenerator.php      # PDF invoice generation
│   ├── PaymentGateway.php        # Stripe integration
│   ├── AutoDelivery.php          # Service provisioning
│   └── OrderNotifications.php    # Email notifications
│
├── members/
│   ├── cart.php                  # Shopping cart page
│   ├── checkout.php              # Checkout page
│   ├── order-confirmation.php    # Order confirmation
│   ├── pay-invoice.php           # Payment page
│   ├── payment-success.php       # Payment success
│   ├── bank-transfer.php         # Bank transfer instructions
│   └── add-to-cart.php           # AJAX cart handler
│
├── webhooks/
│   └── stripe.php                # Stripe webhook handler
│
├── migrations/
│   ├── add-payment-system.sql    # Payment tables
│   └── add-auto-delivery.sql     # Delivery tables
│
└── invoices/                     # Generated PDF invoices
```

## Next Steps

To complete the integration:

1. ✅ Run database migrations
2. ✅ Configure Stripe API keys
3. ✅ Set up Stripe webhook
4. ✅ Configure bank transfer details
5. ⏳ Update service catalog with "Add to Cart" buttons
6. ⏳ Add cart icon to navigation with item count
7. ⏳ Test complete flow end-to-end
8. ⏳ Set up email SMTP (optional, for better deliverability)

## Support

For issues or questions:
- Check error logs in server error_log
- Review payment_logs table for transaction issues
- Check delivery_logs for provisioning failures
- Verify Stripe webhook logs in dashboard

---

**Built:** 2025-11-06
**Version:** 1.0
**Author:** CaminhoIT Development Team
