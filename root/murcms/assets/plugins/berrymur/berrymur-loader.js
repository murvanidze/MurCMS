(function () {
    'use strict';

    var loader = document.currentScript
        || document.getElementById('murcms-berrymur-loader');

    if (!loader) {
        return;
    }

    var cssUrl = loader.getAttribute('data-berrymur-css');
    var scopeSelector = loader.getAttribute('data-berrymur-scope') || '.mur-berry';

    if (!cssUrl || !document.head) {
        return;
    }

    try {
        if (!document.querySelector(scopeSelector)) {
            return;
        }
    } catch (error) {
        return;
    }

    var scheduled = false;
    var started = false;

    function loadStylesheet() {
        if (started || document.getElementById('murcms-berrymur-css')) {
            return;
        }

        started = true;

        var link = document.createElement('link');
        link.id = 'murcms-berrymur-css';
        link.rel = 'stylesheet';
        link.href = cssUrl;
        link.setAttribute('fetchpriority', 'low');
        link.setAttribute('data-murcms-plugin', 'berrymur');

        link.addEventListener('error', function () {
            link.remove();
            started = false;
        }, { once: true });

        var firstStyle = document.head.querySelector(
            'style, link[rel~="stylesheet"]'
        );

        if (firstStyle) {
            document.head.insertBefore(link, firstStyle);
        } else {
            document.head.appendChild(link);
        }
    }

    function scheduleStylesheet() {
        if (scheduled) {
            return;
        }

        scheduled = true;

        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(loadStylesheet, { timeout: 2000 });
            return;
        }

        window.setTimeout(loadStylesheet, 250);
    }

    if (document.readyState === 'complete') {
        scheduleStylesheet();
    } else {
        window.addEventListener('load', scheduleStylesheet, { once: true });
    }
})();
