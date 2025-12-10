# Member Pages Theme Update Summary

## Pattern to Apply:
For each member page, apply these changes:

### 1. Remove Duplicate HTML Structure
After `<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2.php'; ?>`
Remove:
- `<!DOCTYPE html>`
- `<html lang...>`
- `<head>...</head>`
- `<body>`
- All duplicate CSS/JS includes (Bootstrap, fonts, etc.)

### 2. Keep Only Page-Specific Styles
Replace with clean style block:
```css
<style>
    body {
        font-family: 'Poppins', 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        background: var(--c-bg-soft, #F3F4F8);
    }

    .pagename-hero-content {
        text-align: center;
        max-width: 800px;
        margin: 0 auto;
    }

    /* ...page-specific styles... */
</style>
```

### 3. Replace Hero Section
Replace `<header class="hero-enhanced">` with:
```html
<header class="hero">
    <div class="hero-gradient"></div>
    <div class="container position-relative">
        <div class="pagename-hero-content">
            <h1 class="pagename-hero-title">
                <i class="bi bi-icon me-2"></i>
                Page Title
            </h1>
            <p class="pagename-hero-subtitle">
                Page description
            </p>
            <div class="pagename-hero-actions">
                <a href="#" class="btn c-btn-primary">Primary Action</a>
                <a href="#" class="btn c-btn-ghost">Secondary Action</a>
            </div>
        </div>
    </div>
</header>
```

### 4. Remove Duplicate Bootstrap JS
Check before `<?php include footer-v2.php ?>` and remove any:
- `<script src="...bootstrap...bundle.min.js"></script>`

## Completed:
- ✅ dashboard.php
- ✅ account.php
- ✅ my-services.php

## In Progress:
- orders.php
- quotes.php
- raise-ticket.php
- my-ticket.php

## Remaining:
- view-ticket.php
- cart.php
- checkout.php
- view-order.php
- view-quote.php
- view-invoice.php
