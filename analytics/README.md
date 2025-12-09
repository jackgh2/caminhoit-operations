# CaminhoIT Analytics System

A comprehensive, privacy-focused analytics system similar to Umami, built specifically for CaminhoIT.

## Features

### 📊 **Real-Time Tracking**
- Active visitors counter
- Current pages being viewed
- Live visitor locations
- Device types in real-time

### 📈 **Key Metrics**
- Total page views
- Unique visitors
- Average time on site
- Bounce rate
- Trend comparisons with previous periods

### 🗺️ **Geographic Data**
- Interactive world map with hover tooltips
- Country breakdown
- Region and city tracking
- Visitor heatmap by location

### ⏰ **Time-Based Analytics**
- Traffic heatmap (hour x day of week)
- Daily/weekly/monthly trends
- Peak traffic times
- Traffic patterns analysis

### 📄 **Page Analytics**
- Top pages by views
- Entry pages
- Exit pages
- Average time per page
- Bounce rates per page

### 🔗 **Traffic Sources**
- Referrer tracking
- UTM campaign tracking
- Direct vs. referral traffic
- Top traffic sources

### 💻 **Technical Insights**
- Browser breakdown
- Operating system stats
- Device types (desktop/mobile/tablet)
- Screen resolutions

### 🎯 **Notable Events**
- Traffic spikes detection
- New traffic sources alerts
- Anomaly detection
- Custom event tracking

## Installation

### 1. Run Database Migration

```bash
mysql -u your_user -p your_database < migrations/create_analytics_tables.sql
```

Or via phpMyAdmin:
1. Import `/analytics/migrations/create_analytics_tables.sql`
2. Verify all tables are created

### 2. Add Tracking Script to Your Website

Add this to your website's `<head>` or before `</body>`:

```html
<script src="/analytics/track.js" async defer></script>
```

**For existing sites**, add to:
- `/includes/nav.php`
- `/includes/footer.php`
- Or your main template file

### 3. Configure Permissions

Make sure your web server can write to the analytics directory:

```bash
chmod 755 /analytics
chmod 644 /analytics/*.php
```

### 4. Access the Dashboard

Navigate to: `https://caminhoit.com/analytics/`

**Note:** Only administrators can access the analytics dashboard.

## Privacy & GDPR Compliance

This analytics system is designed with privacy in mind:

✅ **IP Anonymization** - Last octet removed by default
✅ **No Cookies** - Uses localStorage for visitor ID
✅ **DNT Respect** - Honors Do-Not-Track headers
✅ **No Personal Data** - No email, names, or PII collected
✅ **Bot Filtering** - Excludes search engine bots
✅ **Session-Based** - 30-minute session timeout

### Settings

Configure in the database:
```sql
UPDATE analytics_settings SET setting_value = '1' WHERE setting_key = 'tracking_enabled';
UPDATE analytics_settings SET setting_value = '1' WHERE setting_key = 'respect_dnt';
UPDATE analytics_settings SET setting_value = '1' WHERE setting_key = 'ip_anonymization';
UPDATE analytics_settings SET setting_value = '0' WHERE setting_key = 'track_bots';
```

## Custom Event Tracking

Track custom events in your JavaScript:

```javascript
// Track button click
trackEvent('Button Click', 'CTA', 'Homepage Subscribe');

// Track form submission
trackEvent('Form Submit', 'Contact', 'Sales Inquiry');

// Track video play
trackEvent('Video Play', 'Media', 'Product Demo');
```

## Dashboard Features

### Period Selection
- Today
- Last 7 days
- Last 30 days
- Last 90 days
- Custom date range (coming soon)

### Real-Time View
- See who's online right now
- What pages they're viewing
- Where they're from
- What devices they're using
- Auto-refreshes every 15 seconds

### Interactive Map
- Hover over locations to see details
- Visitor count per city
- Click to zoom
- Color-coded by traffic volume

### Traffic Heatmap
- Visualize busy hours
- Day of week patterns
- Find peak times
- Optimize posting schedule

### Data Export (Coming Soon)
- CSV export
- PDF reports
- Email scheduled reports

## Performance

### Tracking Script
- **Size:** ~3KB (minified)
- **Load Time:** <50ms
- **Impact:** Negligible
- **Async Loading:** Non-blocking

### Database
- Optimized indexes
- Daily aggregation for speed
- Automatic cleanup of old data
- Efficient queries

### Recommended Maintenance

```sql
-- Clean up old data (older than 1 year)
DELETE FROM analytics_pageviews WHERE viewed_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);

-- Clean up stale active visitors
DELETE FROM analytics_active_visitors WHERE last_seen < DATE_SUB(NOW(), INTERVAL 1 HOUR);

-- Optimize tables monthly
OPTIMIZE TABLE analytics_pageviews;
OPTIMIZE TABLE analytics_sessions;
```

## Troubleshooting

### No Data Showing Up

1. **Check tracking script is loaded:**
   ```javascript
   // In browser console
   console.log(window.trackEvent); // Should be a function
   ```

2. **Verify database connection:**
   Check `/analytics/collect.php` directly

3. **Check browser console:**
   Look for any JavaScript errors

### Real-Time Not Updating

1. Ensure PHP can make HTTP requests (for GeoIP)
2. Check active visitors table:
   ```sql
   SELECT * FROM analytics_active_visitors;
   ```

### Slow Dashboard

1. Check database indexes are created
2. Run aggregation manually:
   ```sql
   -- Creates daily stats for faster queries
   INSERT INTO analytics_daily_stats (stat_date, total_pageviews, total_visitors)
   SELECT DATE(viewed_at), COUNT(*), COUNT(DISTINCT visitor_id)
   FROM analytics_pageviews
   WHERE DATE(viewed_at) = CURDATE()
   GROUP BY DATE(viewed_at);
   ```

## API Endpoints

### Collect Data
`POST /analytics/collect.php`

### Get Dashboard Data
`GET /analytics/api.php?action=dashboard&start=YYYY-MM-DD&end=YYYY-MM-DD`

### Real-Time Visitors
`GET /analytics/api.php?action=realtime`

## Future Enhancements

- [ ] A/B testing framework
- [ ] Conversion funnels
- [ ] Goal tracking
- [ ] Email reports
- [ ] API keys for external access
- [ ] WordPress plugin
- [ ] Chrome extension for quick view

## Credits

Inspired by:
- **Umami** - Privacy-focused analytics
- **Plausible** - Simple analytics
- **Fathom** - GDPR-compliant tracking

Built with ❤️ for CaminhoIT by Claude

## License

Proprietary - CaminhoIT Internal Use Only

## Support

For issues or questions, contact your system administrator.
