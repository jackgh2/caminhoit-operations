<?php
// Clear PHP opcache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache cleared!<br>";
} else {
    echo "OPcache not available<br>";
}

// Clear APCu cache if available
if (function_exists('apcu_clear_cache')) {
    apcu_clear_cache();
    echo "APCu cache cleared!<br>";
}

echo "<br>Cache cleared. <a href='/analytics/'>Go to Analytics</a>";
