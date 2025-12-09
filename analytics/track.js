/**
 * CaminhoIT Analytics - Privacy-focused tracking script
 * Similar to Umami - lightweight and GDPR compliant
 */
(function() {
    'use strict';

    // Configuration
    const config = {
        endpoint: '/analytics/collect.php',
        sessionTimeout: 30 * 60 * 1000, // 30 minutes
        heartbeatInterval: 15000 // 15 seconds
    };

    // Generate unique visitor and session IDs
    function generateId() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    // Get or create visitor ID (persistent)
    function getVisitorId() {
        let visitorId = localStorage.getItem('cit_visitor_id');
        if (!visitorId) {
            visitorId = generateId();
            localStorage.setItem('cit_visitor_id', visitorId);
        }
        return visitorId;
    }

    // Get or create session ID (expires after timeout)
    function getSessionId() {
        const stored = sessionStorage.getItem('cit_session');
        if (stored) {
            const session = JSON.parse(stored);
            if (Date.now() - session.timestamp < config.sessionTimeout) {
                session.timestamp = Date.now();
                sessionStorage.setItem('cit_session', JSON.stringify(session));
                return session.id;
            }
        }

        const newSession = {
            id: generateId(),
            timestamp: Date.now()
        };
        sessionStorage.setItem('cit_session', JSON.stringify(newSession));
        return newSession.id;
    }

    // Extract UTM parameters
    function getUtmParams() {
        const params = new URLSearchParams(window.location.search);
        return {
            utm_source: params.get('utm_source'),
            utm_medium: params.get('utm_medium'),
            utm_campaign: params.get('utm_campaign'),
            utm_content: params.get('utm_content'),
            utm_term: params.get('utm_term')
        };
    }

    // Get screen resolution
    function getScreenResolution() {
        return `${window.screen.width}x${window.screen.height}`;
    }

    // Get device type
    function getDeviceType() {
        const ua = navigator.userAgent;
        if (/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i.test(ua)) {
            return 'tablet';
        }
        if (/Mobile|Android|iP(hone|od)|IEMobile|BlackBerry|Kindle|Silk-Accelerated|(hpw|web)OS|Opera M(obi|ini)/.test(ua)) {
            return 'mobile';
        }
        return 'desktop';
    }

    // Track page view
    function trackPageView() {
        const data = {
            visitor_id: getVisitorId(),
            session_id: getSessionId(),
            page_url: window.location.pathname + window.location.search,
            page_title: document.title,
            referrer: document.referrer || null,
            ...getUtmParams(),
            screen_resolution: getScreenResolution(),
            device_type: getDeviceType(),
            language: navigator.language || navigator.userLanguage,
            timestamp: Date.now()
        };

        // Send beacon (doesn't block page unload)
        if (navigator.sendBeacon) {
            const blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
            navigator.sendBeacon(config.endpoint, blob);
        } else {
            // Fallback to fetch
            fetch(config.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
                keepalive: true
            }).catch(() => {}); // Silent fail
        }
    }

    // Track time on page
    let pageStartTime = Date.now();
    let lastActivityTime = Date.now();

    function updateActivity() {
        lastActivityTime = Date.now();
    }

    // Track engagement
    ['mousedown', 'keydown', 'scroll', 'touchstart'].forEach(event => {
        document.addEventListener(event, updateActivity, { passive: true });
    });

    // Send heartbeat to keep visitor active
    function sendHeartbeat() {
        const timeOnPage = Math.floor((lastActivityTime - pageStartTime) / 1000);
        fetch(config.endpoint + '?action=heartbeat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                visitor_id: getVisitorId(),
                session_id: getSessionId(),
                page_url: window.location.pathname + window.location.search,
                device_type: getDeviceType(),
                time_on_page: timeOnPage
            }),
            keepalive: true
        }).catch(() => {});
    }

    // Send heartbeat every 15 seconds while page is visible
    setInterval(function() {
        // Only send if page is visible or was recently active
        if (!document.hidden || (Date.now() - lastActivityTime) < 30000) {
            sendHeartbeat();
        }
    }, config.heartbeatInterval);

    // Send heartbeat when page becomes visible again
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            updateActivity();
            sendHeartbeat();
        }
    });

    // Track page exit (only on actual page unload, not tab switches)
    window.addEventListener('pagehide', function(e) {
        // Only track exit if page is actually being unloaded (not just hidden)
        if (e.persisted) return; // Page is being cached, not unloaded

        const timeOnPage = Math.floor((lastActivityTime - pageStartTime) / 1000);
        if (timeOnPage > 0) {
            const data = {
                visitor_id: getVisitorId(),
                session_id: getSessionId(),
                action: 'exit',
                time_on_page: timeOnPage
            };

            if (navigator.sendBeacon) {
                const blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
                navigator.sendBeacon(config.endpoint + '?action=exit', blob);
            }
        }
    });

    // Track custom events
    window.trackEvent = function(eventName, eventCategory, eventValue) {
        fetch(config.endpoint + '?action=event', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                visitor_id: getVisitorId(),
                session_id: getSessionId(),
                event_name: eventName,
                event_category: eventCategory || null,
                event_value: eventValue || null,
                page_url: window.location.pathname
            }),
            keepalive: true
        }).catch(() => {});
    };

    // Initialize tracking
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', trackPageView);
    } else {
        trackPageView();
    }

    // Track SPA navigation (if using hash routing or pushState)
    let lastUrl = location.href;
    new MutationObserver(() => {
        const url = location.href;
        if (url !== lastUrl) {
            lastUrl = url;
            pageStartTime = Date.now();
            trackPageView();
        }
    }).observe(document, { subtree: true, childList: true });

})();
