# 📅 Standalone Booking System (Calendly Alternative)

**Version:** 1.0
**Created:** 2025-11-09
**Status:** ✅ FULLY IMPLEMENTED

## 🎯 Overview

A complete, self-hosted appointment booking system built from scratch. **No external tracking**, **no third-party dependencies** (except email/Discord), fully customizable and privacy-focused.

### ✨ Key Features

- 🗓️ **Real-time Availability** - Dynamic time slot generation based on working hours
- 📧 **Email Notifications** - Automatic confirmation emails to customers
- 💬 **Discord Integration** - Real-time webhook notifications for new bookings
- 🚫 **No External Tracking** - 100% self-hosted, privacy-first approach
- 📱 **Mobile Responsive** - Beautiful booking flow on all devices
- ⚙️ **Fully Customizable** - Services, hours, buffer times, etc.
- 🔒 **Secure Tokens** - Unique confirmation and cancellation tokens
- 📊 **Admin Dashboard** - Manage all bookings, services, and settings

---

## 📁 File Structure

### **Database**
```
/migrations/015_create_booking_system.sql
```
- Creates all necessary tables
- Pre-populated with default services and availability
- Default settings configured

### **Public Pages**
```
/book.php                      - Main booking page (customer-facing)
/booking-confirmation.php      - Confirmation page after booking
/booking-cancel.php            - Customer cancellation page
```

### **Backend Logic**
```
/includes/BookingHelper.php          - Core booking system logic
/includes/process-booking.php        - Form submission handler
/includes/get-available-slots.php    - AJAX endpoint for time slots
```

### **Admin Pages**
```
/admin/bookings.php            - View and manage all bookings
/admin/booking-settings.php    - Configure services, hours, settings
```

---

## 🗄️ Database Tables

### `booking_services`
Defines available consultation types (IT Consultation, MSP Onboarding, etc.)

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| name | VARCHAR | Service name |
| description | TEXT | Service description |
| duration_minutes | INT | Appointment duration |
| color | VARCHAR | Brand color for UI |
| is_active | TINYINT | Enable/disable service |
| sort_order | INT | Display order |

**Default Services:**
- IT Consultation (30 min)
- Technical Support (45 min)
- MSP Onboarding (60 min)
- Cloud Migration Planning (60 min)
- Cybersecurity Assessment (45 min)

### `booking_availability`
Working hours for each day of the week

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| day_of_week | TINYINT | 0=Sunday, 6=Saturday |
| start_time | TIME | Opening time |
| end_time | TIME | Closing time |
| is_active | TINYINT | Enable/disable day |

**Default Hours:** Monday-Friday, 9:00 AM - 5:00 PM

### `booking_blocked_slots`
Holidays, breaks, and blocked time slots

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| blocked_date | DATE | Date to block |
| start_time | TIME | Start of blocked period |
| end_time | TIME | End of blocked period |
| reason | VARCHAR | Why blocked (holiday, etc.) |

### `booking_appointments`
All customer bookings

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| service_id | INT | Foreign key to services |
| customer_name | VARCHAR | Customer name |
| customer_email | VARCHAR | Customer email |
| customer_phone | VARCHAR | Customer phone (optional) |
| customer_company | VARCHAR | Company name (optional) |
| appointment_date | DATE | Appointment date |
| start_time | TIME | Start time |
| end_time | TIME | End time |
| duration_minutes | INT | Duration |
| status | ENUM | pending, confirmed, cancelled, completed, no_show |
| notes | TEXT | Customer notes |
| internal_notes | TEXT | Staff-only notes |
| confirmation_token | VARCHAR | Unique token for viewing |
| cancellation_token | VARCHAR | Unique token for cancelling |
| confirmation_sent | TINYINT | Email sent flag |
| reminder_sent | TINYINT | Reminder sent flag |
| ip_address | VARCHAR | Customer IP |
| user_agent | TEXT | Browser info |

### `booking_settings`
Global configuration (timezone, buffer time, notifications, etc.)

| Setting Key | Default | Description |
|-------------|---------|-------------|
| timezone | Europe/Lisbon | Server timezone |
| booking_buffer_minutes | 15 | Time between appointments |
| advance_booking_days | 30 | How far ahead customers can book |
| min_notice_hours | 24 | Minimum notice before appointment |
| allow_weekend_bookings | 0 | Enable weekend slots |
| send_reminders | 1 | Enable reminder emails |
| reminder_hours_before | 24 | When to send reminder |
| confirmation_email_enabled | 1 | Send confirmation emails |
| discord_webhook_enabled | 1 | Send Discord notifications |

---

## 🚀 How It Works

### **Customer Booking Flow**

1. **Visit** `https://caminhoit.com/book.php`

2. **Step 1: Choose Service**
   - Browse available services
   - Each shows duration and description
   - Click to select

3. **Step 2: Select Date & Time**
   - Choose appointment date
   - View available time slots (auto-calculated)
   - Slots account for:
     - Working hours
     - Existing bookings
     - Buffer time
     - Minimum notice requirement

4. **Step 3: Your Information**
   - Name, email (required)
   - Phone, company (optional)
   - Additional notes

5. **Step 4: Confirm Booking**
   - Review all details
   - Submit booking

6. **Success!**
   - Redirected to confirmation page
   - Receives confirmation email with:
     - Booking details
     - Cancellation link
   - Staff notified via Discord webhook

### **Time Slot Calculation**

The system automatically calculates available slots based on:

1. **Working Hours** - Only shows slots during configured hours
2. **Existing Bookings** - Excludes already-booked times
3. **Buffer Time** - Adds gap between appointments (default: 15 min)
4. **Minimum Notice** - Requires advance booking (default: 24 hours)
5. **Weekend Settings** - Optionally disable weekends
6. **Blocked Dates** - Excludes holidays and breaks

**Example:**
```
Working Hours: 9:00 AM - 5:00 PM
Service Duration: 30 minutes
Buffer Time: 15 minutes

Available Slots:
9:00 AM, 9:45 AM, 10:30 AM, 11:15 AM, 12:00 PM,
1:00 PM, 1:45 PM, 2:30 PM, 3:15 PM, 4:00 PM
```

### **Notification System**

**Customer Receives:**
- ✅ Confirmation email with booking details
- ✅ Cancellation link
- ⏰ Reminder email 24 hours before (if enabled)
- ❌ Cancellation confirmation (if cancelled)

**Staff Receives (via Discord):**
- 📅 New booking notification with all details
- ❌ Cancellation notification

---

## ⚙️ Configuration

### **1. Run Database Migration**

```bash
mysql -u username -p database_name < migrations/015_create_booking_system.sql
```

This creates all tables and populates:
- 5 default services
- Monday-Friday working hours (9 AM - 5 PM)
- Default settings

### **2. Configure Discord Webhook (Optional)**

In `/includes/config.php`, add:

```php
$discordWebhookUrl = 'https://discord.com/api/webhooks/YOUR_WEBHOOK_URL';
```

### **3. Add Booking Link to Website**

**Navigation Menu:**
```php
<a href="/book.php" class="nav-link">Book Consultation</a>
```

**Call-to-Action Buttons:**
```php
<a href="/book.php" class="btn btn-primary">
    <i class="bi bi-calendar-check me-2"></i>Book Free Consultation
</a>
```

**Footer:**
```php
<a href="/book.php">Schedule Appointment</a>
```

### **4. Customize Services**

Go to: `https://caminhoit.com/admin/booking-settings.php`

- Add new services
- Edit durations, descriptions, colors
- Enable/disable services
- Reorder services

### **5. Set Working Hours**

Same page: `https://caminhoit.com/admin/booking-settings.php`

- Configure hours for each day
- Enable/disable specific days
- Set different hours per day

### **6. Adjust Global Settings**

On settings page:
- **Timezone** - Europe/Lisbon or Europe/London
- **Buffer Time** - Gap between appointments (0-60 min)
- **Advance Booking** - How far ahead (1-365 days)
- **Minimum Notice** - Minimum advance booking (1-168 hours)
- **Allow Weekends** - Enable/disable weekend bookings
- **Reminders** - Enable 24-hour reminder emails
- **Notifications** - Toggle email and Discord notifications

---

## 📧 Email Notifications

### **Confirmation Email**

Sent immediately after booking:

```
Subject: Booking Confirmed - [Service Name]

Hi [Customer Name],

Your consultation has been confirmed. We're looking forward to speaking with you!

Service: IT Consultation
Date & Time: Monday, November 11, 2025 at 2:00 PM
Duration: 30 minutes

[View Booking Details Button]

Need to cancel? Click here

---
Questions? Contact us:
Email: support@caminhoit.com
Phone: +351 963 452 653
```

### **Reminder Email**

Sent 24 hours before appointment (if enabled):

```
Subject: Reminder: Your appointment is tomorrow

Hi [Customer Name],

This is a friendly reminder about your upcoming consultation:

Service: IT Consultation
Date & Time: Tomorrow at 2:00 PM
Duration: 30 minutes

We're looking forward to speaking with you!

[View Booking Details Button]
```

---

## 💬 Discord Webhooks

When a new booking is created:

```json
{
  "embeds": [{
    "title": "📅 New Booking - IT Consultation",
    "color": 6737386,
    "fields": [
      {"name": "Customer", "value": "John Smith", "inline": true},
      {"name": "Email", "value": "john@example.com", "inline": true},
      {"name": "Date & Time", "value": "Monday, November 11, 2025 at 2:00 PM"},
      {"name": "Duration", "value": "30 minutes", "inline": true},
      {"name": "Status", "value": "✅ Confirmed", "inline": true},
      {"name": "Notes", "value": "Would like to discuss cloud migration"}
    ],
    "footer": {"text": "CaminhoIT Booking System"},
    "timestamp": "2025-11-09T14:30:00Z"
  }]
}
```

---

## 🔐 Security Features

1. **Unique Tokens** - Each booking has:
   - Confirmation token (64 characters)
   - Cancellation token (64 characters)
   - Prevents unauthorized access

2. **No Authentication Required** - Customers don't need accounts

3. **IP & User Agent Tracking** - For security auditing

4. **SQL Injection Protection** - Prepared statements throughout

5. **Email Validation** - Validates all customer emails

6. **Admin-Only Access** - Booking management requires admin login

---

## 📊 Admin Features

### **Bookings Dashboard** (`/admin/bookings.php`)

**Statistics:**
- Total bookings
- Upcoming appointments
- Confirmed, pending, completed, cancelled counts

**Filter Bookings By:**
- Status (confirmed, pending, cancelled, completed, no_show)
- Service type
- Date range

**Actions:**
- View booking details
- Contact customer (email/phone links)
- Cancel appointments
- Mark as completed/no-show
- Add internal notes

### **Settings Page** (`/admin/booking-settings.php`)

**Manage Services:**
- Add new service types
- Edit existing services
- Enable/disable services
- Reorder services
- Change colors and durations

**Configure Availability:**
- Set working hours per day
- Enable/disable specific days
- Different hours for different days

**Global Settings:**
- Timezone
- Buffer times
- Advance booking limits
- Minimum notice
- Email/Discord notifications

---

## 🎨 Customization

### **Change Brand Colors**

In `/book.php`, update CSS:

```css
background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
```

Replace with your brand colors.

### **Add New Service Types**

Two methods:

1. **Via Admin Panel:**
   - Go to `/admin/booking-settings.php`
   - Click "Add Service"
   - Fill in name, description, duration, color

2. **Via SQL:**
```sql
INSERT INTO booking_services (name, description, duration_minutes, color)
VALUES ('Security Audit', 'Complete IT security assessment', 90, '#e74c3c');
```

### **Block Specific Dates**

**Method 1: Admin Panel** (to be implemented)

**Method 2: SQL:**
```sql
INSERT INTO booking_blocked_slots (blocked_date, start_time, end_time, reason)
VALUES ('2025-12-25', '00:00:00', '23:59:59', 'Christmas Holiday');
```

### **Change Email Template**

Edit `/includes/BookingHelper.php`, find `sendCustomerConfirmationEmail()` method:

```php
$mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px;'>
        <!-- Your custom HTML here -->
    </div>
";
```

---

## 📈 Analytics Integration

The booking page already includes analytics tracking:

```html
<script src="/analytics/track.js" async defer></script>
```

This tracks:
- Page views on `/book.php`
- Time spent on booking page
- Referrer sources
- Device types

**To Track Conversions:**

In `/includes/process-booking.php`, after successful booking, add:

```php
// Track conversion event
$stmt = $pdo->prepare("
    INSERT INTO analytics_events (event_type, event_data)
    VALUES ('booking_completed', ?)
");
$stmt->execute([json_encode([
    'booking_id' => $bookingId,
    'service' => $service['name'],
    'value' => $service['duration_minutes']
])]);
```

---

## 🔧 Troubleshooting

### **No Time Slots Showing**

**Check:**
1. Working hours configured for that day?
2. Date is not too far in advance (check `advance_booking_days`)
3. Date meets minimum notice (check `min_notice_hours`)
4. Not a weekend (if weekends disabled)
5. Date not in `booking_blocked_slots`

**Debug:**
```php
$bookingHelper = new BookingHelper($pdo);
$slots = $bookingHelper->getAvailableSlots('2025-11-15', 1, 30);
var_dump($slots);
```

### **Emails Not Sending**

**Check:**
1. SMTP settings in `/includes/config.php`
2. `confirmation_email_enabled` setting is `1`
3. PHPMailer installed (`vendor/autoload.php` exists)
4. Check error logs: `/var/log/php_errors.log`

**Test Email:**
```php
require 'vendor/autoload.php';
$mail = new PHPMailer\PHPMailer\PHPMailer(true);
// ... configure SMTP
$mail->send();
```

### **Discord Webhooks Not Working**

**Check:**
1. `$discordWebhookUrl` set in `/includes/config.php`
2. `discord_webhook_enabled` setting is `1`
3. Webhook URL is valid
4. cURL extension enabled

**Test Webhook:**
```bash
curl -X POST "YOUR_WEBHOOK_URL" \
  -H "Content-Type: application/json" \
  -d '{"content": "Test message"}'
```

### **Time Slots Overlapping**

**Cause:** Buffer time too small or concurrent bookings

**Fix:** Increase buffer time:
```sql
UPDATE booking_settings
SET setting_value = '30'
WHERE setting_key = 'booking_buffer_minutes';
```

---

## 🌍 SEO Optimization

The booking page is already SEO-optimized:

**Meta Tags:**
```php
$seo->setTitle('Book a Consultation | CaminhoIT - IT Support & MSP Services')
    ->setDescription('Schedule your free IT consultation...')
    ->setKeywords('book IT consultation, schedule MSP meeting...');
```

**Structured Data:**
- Organization schema with all locations
- Local business schema

**Add to Sitemap:**

Edit `/sitemap.xml.php`:
```php
['url' => '/book.php', 'priority' => '0.9', 'changefreq' => 'weekly'],
```

**Submit to Google Search Console:**
```
https://caminhoit.com/sitemap.xml.php
```

---

## 📱 CTA Placement Ideas

### **Homepage Hero Section**
```php
<a href="/book.php" class="btn btn-lg btn-primary">
    <i class="bi bi-calendar-check me-2"></i>Book Free Consultation
</a>
```

### **Service Pages**
```php
<div class="cta-box">
    <h3>Ready to Get Started?</h3>
    <p>Schedule a free consultation to discuss your IT needs</p>
    <a href="/book.php" class="btn btn-primary">Book Now</a>
</div>
```

### **Contact Page**
```php
<div class="alert alert-info">
    <i class="bi bi-calendar-check me-2"></i>
    Prefer to schedule online? <a href="/book.php">Book an appointment</a>
</div>
```

### **Footer (Every Page)**
```php
<a href="/book.php" class="btn btn-outline-light">
    Schedule Consultation
</a>
```

### **Floating Button**
```html
<div class="floating-cta">
    <a href="/book.php" class="btn btn-primary btn-lg">
        <i class="bi bi-calendar-check"></i> Book Now
    </a>
</div>
<style>
.floating-cta {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 1000;
}
</style>
```

---

## 🚀 Next Steps

1. ✅ **Run Migration** - Create database tables
2. ✅ **Configure Settings** - Timezone, hours, buffer time
3. ✅ **Add Services** - Customize consultation types
4. ✅ **Test Booking Flow** - Make a test booking
5. ✅ **Add CTA Links** - Place booking links throughout site
6. ✅ **Submit to Sitemap** - Add to Google Search Console
7. ✅ **Monitor Bookings** - Check admin dashboard daily

---

## 📞 Support

**Documentation:**
- This File: `/BOOKING-SYSTEM-README.md`
- SEO Guide: `/LOCAL-SEO-SUMMARY.md`

**Need Help?**
- Email: support@caminhoit.com
- Phone: +351 963 452 653

---

**Last Updated:** 2025-11-09
**Version:** 1.0
**Status:** ✅ PRODUCTION READY

Your standalone booking system is ready to accept appointments! 🎉
