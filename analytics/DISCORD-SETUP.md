# Discord Daily Analytics Reports

Get automated daily analytics reports sent directly to your Discord server!

## 📋 What's Included in the Report

- **Key Metrics**: Page views, unique visitors, sessions, avg time, bounce rate
- **Top Pages**: Most visited pages with view counts
- **Top Exit Pages**: Where users are leaving your site
- **Top Locations**: Visitor countries with flags 🌍
- **Device Breakdown**: Desktop/Mobile/Tablet percentages 📱💻
- **Top Browsers**: Browser distribution
- **Traffic Spikes**: Anomaly detection for unusual traffic patterns ⚡

## 🚀 Setup Instructions

### 1. Create a Discord Webhook

1. Open your Discord server
2. Go to **Server Settings** → **Integrations** → **Webhooks**
3. Click **New Webhook** or **Create Webhook**
4. Choose a channel (e.g., `#analytics` or `#reports`)
5. Set a name (e.g., "CaminhoIT Analytics")
6. **Copy the Webhook URL** (you'll need this!)
7. Click **Save**

### 2. Configure the Script

1. Copy the example config:
   ```bash
   cp discord-config.example.php discord-config.php
   ```

2. Edit `discord-config.php` and paste your webhook URL:
   ```php
   'webhook_url' => 'https://discord.com/api/webhooks/YOUR_WEBHOOK_ID/YOUR_TOKEN',
   ```

3. (Optional) Customize settings:
   ```php
   'report_hours' => 24,        // Time range for report
   'spike_threshold' => 1.5,    // 1.5 = 50% increase = spike
   ```

### 3. Test the Report

Run manually to test:
```bash
php /home/caminhoit/public_html/analytics/discord-report.php
```

You should see:
```
✓ Daily report sent to Discord successfully!
Pageviews: 1,234
Visitors: 567
```

Check your Discord channel - you should see a beautiful embedded report!

### 4. Schedule Daily Reports (Cron)

**Option A: Daily at 9 AM**
```bash
crontab -e
```

Add this line:
```
0 9 * * * /usr/bin/php /home/caminhoit/public_html/analytics/discord-report.php
```

**Option B: Via cPanel**
1. Go to cPanel → **Cron Jobs**
2. Add new cron job:
   - **Minute**: 0
   - **Hour**: 9
   - **Day**: *
   - **Month**: *
   - **Weekday**: *
   - **Command**: `/usr/bin/php /home/caminhoit/public_html/analytics/discord-report.php`

## 📊 Example Report

The Discord message will look like:

```
📊 CaminhoIT Analytics - Daily Report
Analytics summary for the last 24 hours

📈 Key Metrics
Page Views: 1,234
Unique Visitors: 567
Sessions: 489
Avg. Time: 2m 34s
Bounce Rate: 45.2%

🏆 Top Pages
**/services** - 234 views
**/pricing** - 156 views
**/contact** - 89 views

🚪 Top Exit Pages
**/thank-you** - 45 exits
**/pricing** - 23 exits

🌍 Top Locations        📱 Device Breakdown
🇬🇧 United Kingdom - 234   💻 Desktop - 345 (61%)
🇵🇹 Portugal - 123         📱 Mobile - 189 (33%)
🇺🇸 United States - 89     📲 Tablet - 33 (6%)

🌐 Top Browsers
Chrome - 345 visitors
Safari - 123 visitors
Firefox - 89 visitors

⚡ Traffic Spikes Detected
14:00 - 234 views (+78%)
```

## ⚙️ Configuration Options

### `discord-config.php`

```php
return [
    // Required: Your Discord webhook URL
    'webhook_url' => 'https://discord.com/api/webhooks/...',

    // Optional: Bot display name
    'bot_name' => 'CaminhoIT Analytics',

    // Optional: Bot avatar URL
    'bot_avatar' => 'https://caminhoit.com/assets/logo.png',

    // Optional: Report time range in hours (default: 24)
    'report_hours' => 24,

    // Optional: Spike detection threshold (default: 1.5 = 50% increase)
    'spike_threshold' => 1.5
];
```

## 🔧 Troubleshooting

### No webhook message appears

1. Check webhook URL is correct
2. Run script manually and check for errors:
   ```bash
   php discord-report.php
   ```
3. Ensure Discord webhook is active in server settings

### "Error: discord-config.php not found"

Copy the example config:
```bash
cp discord-config.example.php discord-config.php
```

### "Error: Please configure your Discord webhook URL"

Edit `discord-config.php` and replace `YOUR_WEBHOOK_ID` with your actual webhook URL

### Cron not working

1. Check cron is running: `crontab -l`
2. Check PHP path: `which php`
3. Use absolute paths in cron command
4. Check cron logs: `/var/log/cron` or cPanel cron email

## 📅 Recommended Schedule

- **Daily summary**: `0 9 * * *` (9 AM every day)
- **Twice daily**: `0 9,18 * * *` (9 AM and 6 PM)
- **Weekly**: `0 9 * * 1` (9 AM every Monday)
- **Test hourly**: `0 * * * *` (Every hour)

## 🎨 Custom Reports

You can modify `discord-report.php` to:
- Add custom metrics
- Change time ranges
- Add more charts/graphs
- Filter by specific pages
- Include revenue data
- Add custom alerts

## 📞 Support

If you need help setting this up, check:
- Discord webhook documentation
- cPanel cron job tutorials
- Analytics dashboard at: https://caminhoit.com/analytics/
