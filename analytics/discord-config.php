<?php
/**
 * Discord Webhook Configuration
 *
 * How to set up:
 * 1. Copy this file to discord-config.php
 * 2. Create a Discord webhook in your server:
 *    - Go to Server Settings > Integrations > Webhooks
 *    - Click "New Webhook"
 *    - Choose a channel (e.g., #analytics)
 *    - Copy the webhook URL
 * 3. Paste the webhook URL below
 * 4. Test by running: php /path/to/analytics/discord-report.php
 */

return [
    // Your Discord Webhook URL
    'webhook_url' => 'https://discord.com/api/webhooks/1436880166331879466/p40wbDpytXcj2nl8t21FwX6DwLKchw-hygZTwn-rS8d2OpiLUtYpUo91VPwtjOd7K3Kr',

    // Bot name that appears in Discord
    'bot_name' => 'CaminhoIT Analytics',

    // Bot avatar URL (optional)
    'bot_avatar' => 'https://caminhoit.com/assets/logo.png',

    // Report time range (hours)
    'report_hours' => 24,

    // Spike detection threshold (1.5 = 50% increase)
    'spike_threshold' => 1.5
];
