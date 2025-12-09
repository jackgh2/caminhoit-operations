-- Check current Discord configuration
SELECT config_key, config_value
FROM system_config
WHERE config_key LIKE 'discord%'
ORDER BY config_key;

-- SOLUTION: Enable Discord notifications for orders
-- Run this if discord.orders_enabled shows '0' above:

UPDATE system_config
SET config_value = '1'
WHERE config_key = 'discord.orders_enabled';

-- Also make sure you have a webhook URL set:
-- (Replace YOUR_DISCORD_WEBHOOK_URL with your actual webhook)
-- UPDATE system_config
-- SET config_value = 'YOUR_DISCORD_WEBHOOK_URL'
-- WHERE config_key = 'discord.orders_webhook_url';

-- Check again after update:
SELECT config_key, config_value
FROM system_config
WHERE config_key LIKE 'discord%'
ORDER BY config_key;
