(function () {
    'use strict';

    var script = document.currentScript || document.querySelector('script[data-murconsent-config]');
    if (!script) return;

    var config = readConfig(script.getAttribute('data-murconsent-config'));
    if (!config || !config.capabilities) return;

    /* Prevent duplicate runtime initialization if a hook or template includes the asset twice. */
    if (window.__murconsentRuntime && window.__murconsentRuntime.initialized) return;
    window.__murconsentRuntime = { initialized: true, version: config.pluginVersion };

    var root;
    var banner;
    var overlay;
    var dialog;
    var statusNode;
    var analyticsInput;
    var marketingInput;
    var flagInput;
    var consentState = null;
    var lastFocused = null;
    var googleConsentInitialized = false;
    var loaded = {
        googleScript: false,
        analyticsConfig: false,
        marketingConfig: false,
        gtm: false,
        handlers: false
    };

    function readConfig(encoded) {
        if (!encoded) return null;
        try {
            var binary = window.atob(encoded);
            var json;
            if (window.TextDecoder && window.Uint8Array) {
                var bytes = new Uint8Array(binary.length);
                var i;
                for (i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
                json = new TextDecoder('utf-8').decode(bytes);
            } else {
                json = decodeURIComponent(escape(binary));
            }
            return JSON.parse(json);
        } catch (error) {
            return null;
        }
    }

    function bool(value) {
        return value === true;
    }

    function emptyChoice() {
        return {
            analytics: false,
            marketing: false,
            flag_counter: false
        };
    }

    function normalizeChoice(choice) {
        choice = choice || {};
        return {
            analytics: bool(choice.analytics) && bool(config.capabilities.analytics),
            marketing: bool(choice.marketing) && bool(config.capabilities.marketing),
            flag_counter: bool(choice.flag_counter) && bool(config.capabilities.flagCounter)
        };
    }

    function acceptAllChoice() {
        return normalizeChoice({
            analytics: true,
            marketing: true,
            flag_counter: true
        });
    }

    function choicesEqual(a, b) {
        a = normalizeChoice(a);
        b = normalizeChoice(b);
        return a.analytics === b.analytics
            && a.marketing === b.marketing
            && a.flag_counter === b.flag_counter;
    }

    function directAnalyticsAllowed(choice) {
        choice = normalizeChoice(choice);
        return bool(config.ga4Enabled) && choice.analytics && Boolean(config.ga4Id);
    }

    function directMarketingAllowed(choice) {
        choice = normalizeChoice(choice);
        return bool(config.adsEnabled) && choice.marketing && Boolean(config.adsId);
    }

    function gtmAnalyticsAllowed(choice) {
        choice = normalizeChoice(choice);
        return bool(config.gtmEnabled) && bool(config.gtmAnalyticsEnabled) && choice.analytics && Boolean(config.gtmId);
    }

    function gtmMarketingAllowed(choice) {
        choice = normalizeChoice(choice);
        return bool(config.gtmEnabled) && bool(config.gtmMarketingEnabled) && choice.marketing && Boolean(config.gtmId);
    }

    function hasDirectGoogleConsent(choice) {
        return directAnalyticsAllowed(choice) || directMarketingAllowed(choice);
    }

    function hasGtmConsent(choice) {
        return gtmAnalyticsAllowed(choice) || gtmMarketingAllowed(choice);
    }

    function categoryUsesGtm(category, choice) {
        return category === 'analytics' ? gtmAnalyticsAllowed(choice) : gtmMarketingAllowed(choice);
    }

    function categoryUsesDirect(category, choice) {
        return category === 'analytics' ? directAnalyticsAllowed(choice) : directMarketingAllowed(choice);
    }

    function getCookie(name) {
        var prefix = name + '=';
        var cookies = document.cookie ? document.cookie.split(';') : [];
        var i;
        for (i = 0; i < cookies.length; i += 1) {
            var item = cookies[i].replace(/^\s+/, '');
            if (item.indexOf(prefix) === 0) return item.substring(prefix.length);
        }
        return null;
    }

    function validTimestamp(timestamp) {
        if (typeof timestamp !== 'string') return false;
        var time = Date.parse(timestamp);
        if (!isFinite(time)) return false;

        var now = new Date().getTime();
        var maxAge = Math.max(1, Number(config.days) || 365) * 86400000;
        if (time > now + 300000) return false;
        if (now - time > maxAge + 86400000) return false;
        return true;
    }

    function readState() {
        var raw = getCookie(config.cookieName);
        if (!raw) return null;

        try {
            var state = JSON.parse(decodeURIComponent(raw));
            if (!state || String(state.schema) !== String(config.schemaVersion)) return null;
            if (!validTimestamp(state.timestamp)) return null;
            if (typeof state.analytics !== 'boolean') return null;
            if (typeof state.marketing !== 'boolean') return null;
            if (typeof state.flag_counter !== 'boolean') return null;

            var normalized = normalizeChoice(state);
            return {
                schema: String(config.schemaVersion),
                timestamp: state.timestamp,
                analytics: normalized.analytics,
                marketing: normalized.marketing,
                flag_counter: normalized.flag_counter
            };
        } catch (error) {
            return null;
        }
    }

    function writeState(choice) {
        var normalized = normalizeChoice(choice);
        var state = {
            schema: String(config.schemaVersion),
            timestamp: new Date().toISOString(),
            analytics: normalized.analytics,
            marketing: normalized.marketing,
            flag_counter: normalized.flag_counter
        };
        var maxAge = Math.max(1, Number(config.days) || 365) * 86400;
        var cookie = config.cookieName + '=' + encodeURIComponent(JSON.stringify(state))
            + '; Max-Age=' + maxAge
            + '; Path=/'
            + '; SameSite=Lax';
        if (window.location.protocol === 'https:') cookie += '; Secure';
        if (!config.previewMode) document.cookie = cookie;
        consentState = state;
        return state;
    }

    function expireNamedCookie(name) {
        document.cookie = name + '=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; Path=/; SameSite=Lax';
    }

    function removeLegacyCookie() {
        if (config.previewMode) return;
        ['mur_consent', 'maxsite_consent'].forEach(function (name) {
            if (getCookie(name) !== null) expireNamedCookie(name);
        });
    }

    function consentPayload(choice) {
        choice = normalizeChoice(choice);
        return {
            analytics_storage: choice.analytics ? 'granted' : 'denied',
            ad_storage: choice.marketing ? 'granted' : 'denied',
            ad_user_data: choice.marketing ? 'granted' : 'denied',
            ad_personalization: 'denied',
            functionality_storage: 'denied',
            personalization_storage: 'denied',
            security_storage: 'denied'
        };
    }

    function ensureGoogleLayer(choice) {
        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function () {
            window.dataLayer.push(arguments);
        };

        if (!googleConsentInitialized) {
            window.gtag('consent', 'default', consentPayload(choice));
            googleConsentInitialized = true;
        } else {
            window.gtag('consent', 'update', consentPayload(choice));
        }
    }

    function pushConsentEvent(choice, name) {
        if (!window.dataLayer) return;
        choice = normalizeChoice(choice);
        window.dataLayer.push({
            event: name || 'murconsent_consent_update',
            murconsent_analytics: choice.analytics,
            murconsent_marketing: choice.marketing,
            murconsent_flag_counter: choice.flag_counter
        });
    }

    function loadScript(src, id) {
        if (id && document.getElementById(id)) return false;
        var node = document.createElement('script');
        if (id) node.id = id;
        node.async = true;
        node.src = src;
        document.head.appendChild(node);
        return true;
    }

    function configureDirectDestinations(choice) {
        choice = normalizeChoice(choice);

        if (directAnalyticsAllowed(choice) && !loaded.analyticsConfig) {
            window.gtag('config', config.ga4Id, {
                allow_google_signals: false,
                allow_ad_personalization_signals: false
            });
            loaded.analyticsConfig = true;
        }

        if (directMarketingAllowed(choice) && !loaded.marketingConfig) {
            window.gtag('config', config.adsId, {
                allow_ad_personalization_signals: false
            });
            loaded.marketingConfig = true;
        }
    }

    function loadDirectGoogle(choice) {
        choice = normalizeChoice(choice);
        var firstDestination = '';

        if (directAnalyticsAllowed(choice)) firstDestination = config.ga4Id;
        if (!firstDestination && directMarketingAllowed(choice)) firstDestination = config.adsId;
        if (!firstDestination) return;

        ensureGoogleLayer(choice);

        if (!loaded.googleScript) {
            window.gtag('js', new Date());
            configureDirectDestinations(choice);
            loaded.googleScript = loadScript(
                'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(firstDestination),
                'murconsent-google-tag'
            ) || Boolean(document.getElementById('murconsent-google-tag'));
        } else {
            configureDirectDestinations(choice);
            pushConsentEvent(choice);
        }
    }

    function loadGtm(choice) {
        choice = normalizeChoice(choice);
        if (!hasGtmConsent(choice)) return;

        ensureGoogleLayer(choice);
        pushConsentEvent(choice, 'murconsent_ready');

        if (!loaded.gtm) {
            window.dataLayer.push({
                'gtm.start': new Date().getTime(),
                event: 'gtm.js'
            });
            loaded.gtm = loadScript(
                'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(config.gtmId),
                'murconsent-google-tag-manager'
            ) || Boolean(document.getElementById('murconsent-google-tag-manager'));
        }
    }

    function applyGoogle(choice) {
        if (config.previewMode) return;
        choice = normalizeChoice(choice);

        if (hasDirectGoogleConsent(choice)) loadDirectGoogle(choice);
        if (hasGtmConsent(choice)) loadGtm(choice);
    }

    function insertFlagCounter(choice) {
        choice = config.previewMode ? emptyChoice() : normalizeChoice(choice);
        var containers = document.querySelectorAll('[data-murconsent-flag-counter]');
        var shouldInsert = choice.flag_counter && config.flagCounterHtml && containers.length;
        var i;

        for (i = 0; i < containers.length; i += 1) {
            /* Only the first output point is populated to prevent duplicate counting. */
            if (shouldInsert && i === 0) {
                if (containers[i].getAttribute('data-murconsent-loaded') !== '1') {
                    containers[i].innerHTML = config.flagCounterHtml;
                    containers[i].setAttribute('data-murconsent-loaded', '1');
                }
            } else {
                containers[i].innerHTML = '';
                containers[i].removeAttribute('data-murconsent-loaded');
            }
        }
    }

    function analyticsCookieName(name) {
        return name === '_ga'
            || name === '_gid'
            || name === '_gat'
            || name.indexOf('_ga_') === 0
            || name.indexOf('_gat_') === 0
            || name.indexOf('_dc_gtm_') === 0;
    }

    function marketingCookieName(name) {
        return name.indexOf('_gcl_') === 0 || name.indexOf('_gac_') === 0;
    }

    function domainCandidates() {
        var host = window.location.hostname;
        var result = [''];
        if (!host || host === 'localhost' || /^\d+\.\d+\.\d+\.\d+$/.test(host)) return result;

        var parts = host.split('.');
        var i;
        for (i = 0; i <= parts.length - 2; i += 1) {
            var domain = parts.slice(i).join('.');
            result.push(domain);
            result.push('.' + domain);
        }
        return result;
    }

    function pathCandidates() {
        var result = ['/'];
        var parts = window.location.pathname.split('/');
        var current = '';
        var i;
        for (i = 0; i < parts.length - 1; i += 1) {
            if (!parts[i]) continue;
            current += '/' + parts[i];
            result.push(current);
            result.push(current + '/');
        }
        return result;
    }

    function expireCookie(name, domain, path) {
        var cookie = name + '=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; Path=' + path + '; SameSite=Lax';
        if (domain) cookie += '; Domain=' + domain;
        if (window.location.protocol === 'https:') cookie += '; Secure';
        document.cookie = cookie;
    }

    function deleteCookies(matcher) {
        var visible = document.cookie ? document.cookie.split(';') : [];
        var domains = domainCandidates();
        var paths = pathCandidates();
        var i;
        var d;
        var p;

        for (i = 0; i < visible.length; i += 1) {
            var name = visible[i].split('=')[0].replace(/^\s+|\s+$/g, '');
            if (!matcher(name)) continue;
            for (d = 0; d < domains.length; d += 1) {
                for (p = 0; p < paths.length; p += 1) expireCookie(name, domains[d], paths[p]);
            }
        }
    }

    function deleteRevokedCookies(previous, next) {
        previous = normalizeChoice(previous);
        next = normalizeChoice(next);
        if (previous.analytics && !next.analytics) deleteCookies(analyticsCookieName);
        if (previous.marketing && !next.marketing) deleteCookies(marketingCookieName);
    }

    function dispatchConsentEvent(choice) {
        var detail = normalizeChoice(choice);
        try {
            document.dispatchEvent(new CustomEvent('murconsent:change', { detail: detail }));
        } catch (error) {
            var event = document.createEvent('CustomEvent');
            event.initCustomEvent('murconsent:change', false, false, detail);
            document.dispatchEvent(event);
        }
    }

    function setHidden(node, hidden) {
        if (node) node.hidden = hidden;
    }

    function renderVisibility() {
        var hasDecision = Boolean(consentState);
        setHidden(banner, hasDecision && !config.previewMode);
    }

    function setChoiceInputs(choice) {
        choice = normalizeChoice(choice);
        if (analyticsInput) analyticsInput.checked = choice.analytics;
        if (marketingInput) marketingInput.checked = choice.marketing;
        if (flagInput) flagInput.checked = choice.flag_counter;
    }

    function choiceFromInputs() {
        return normalizeChoice({
            analytics: analyticsInput ? analyticsInput.checked : false,
            marketing: marketingInput ? marketingInput.checked : false,
            flag_counter: flagInput ? flagInput.checked : false
        });
    }

    function focusableNodes() {
        if (!dialog) return [];
        return dialog.querySelectorAll('button:not([disabled]), input:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])');
    }

    function openSettings(opener) {
        if (!overlay || !dialog) return;
        lastFocused = opener || document.activeElement;
        setChoiceInputs(consentState || emptyChoice());
        setHidden(overlay, false);
        document.documentElement.classList.add('murconsent-dialog-open');

        window.setTimeout(function () {
            var nodes = focusableNodes();
            if (nodes.length) nodes[0].focus();
            else dialog.focus();
        }, 0);
    }

    function closeSettings() {
        if (!overlay) return;
        setHidden(overlay, true);
        document.documentElement.classList.remove('murconsent-dialog-open');
        renderVisibility();
        if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
    }

    function showSavedMessage() {
        if (!statusNode) return;
        statusNode.textContent = statusNode.getAttribute('data-murconsent-saved-message') || '';
        window.setTimeout(function () {
            if (statusNode) statusNode.textContent = '';
        }, 2400);
    }

    function googleWasLoaded() {
        return loaded.googleScript || loaded.gtm;
    }

    function saveChoice(choice) {
        var previous = consentState ? normalizeChoice(consentState) : emptyChoice();
        var next = normalizeChoice(choice);
        var googleRevoked = (previous.analytics && !next.analytics)
            || (previous.marketing && !next.marketing);
        var gtmChanged = loaded.gtm && (
            (bool(config.gtmAnalyticsEnabled) && previous.analytics !== next.analytics)
            || (bool(config.gtmMarketingEnabled) && previous.marketing !== next.marketing)
        );

        writeState(next);
        if (!config.previewMode) deleteRevokedCookies(previous, next);
        insertFlagCounter(next);

        if (googleConsentInitialized) {
            ensureGoogleLayer(next);
            pushConsentEvent(next);
        }

        dispatchConsentEvent(next);
        setHidden(overlay, true);
        document.documentElement.classList.remove('murconsent-dialog-open');
        renderVisibility();
        showSavedMessage();

        if (!config.previewMode && config.reloadOnWithdraw !== false && ((googleRevoked && googleWasLoaded()) || gtmChanged)) {
            window.setTimeout(function () {
                window.location.reload();
            }, 80);
            return;
        }

        applyGoogle(next);
    }

    function closestWithAttribute(node, attribute) {
        while (node && node !== document) {
            if (node.nodeType === 1 && node.hasAttribute(attribute)) return node;
            node = node.parentNode;
        }
        return null;
    }

    function handleRootClick(event) {
        var target = event.target;

        if (closestWithAttribute(target, 'data-murconsent-accept-all')) {
            saveChoice(acceptAllChoice());
            return;
        }

        if (closestWithAttribute(target, 'data-murconsent-reject-all')
            || closestWithAttribute(target, 'data-murconsent-reject-all-dialog')) {
            saveChoice(emptyChoice());
            return;
        }

        if (closestWithAttribute(target, 'data-murconsent-customize')) {
            openSettings(target);
            return;
        }

        if (closestWithAttribute(target, 'data-murconsent-save')) {
            saveChoice(choiceFromInputs());
            return;
        }

        if (closestWithAttribute(target, 'data-murconsent-close')) {
            closeSettings();
        }
    }

    function handleDocumentClick(event) {
        var opener = closestWithAttribute(event.target, 'data-murconsent-open');
        if (opener) {
            event.preventDefault();
            openSettings(opener);
            return;
        }

        var conversionLink = closestWithAttribute(event.target, 'data-murconsent-conversion');
        if (conversionLink) handleConversionLink(event, conversionLink);
    }

    function handleDialogKeydown(event) {
        if (!overlay || overlay.hidden) return;

        if (event.key === 'Escape' || event.keyCode === 27) {
            event.preventDefault();
            closeSettings();
            return;
        }

        if (event.key !== 'Tab' && event.keyCode !== 9) return;

        var nodes = focusableNodes();
        if (!nodes.length) {
            event.preventDefault();
            dialog.focus();
            return;
        }

        var first = nodes[0];
        var last = nodes[nodes.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function normalizedCategory(category) {
        category = String(category || '').toLowerCase();
        if (category === 'ads' || category === 'adsmeasurement') return 'marketing';
        if (category === 'flagcounter') return 'flag_counter';
        return category;
    }

    function hasConsent(category) {
        if (!consentState) return false;
        category = normalizedCategory(category);
        if (category === 'analytics') return bool(consentState.analytics);
        if (category === 'marketing') return bool(consentState.marketing);
        if (category === 'flag_counter') return bool(consentState.flag_counter);
        return false;
    }

    function defaultSendTo() {
        if (!config.adsId || !config.adsLabel) return '';
        return config.adsId + '/' + config.adsLabel;
    }

    function validSendTo(value) {
        return /^AW-[0-9]{5,20}\/[A-Za-z0-9_-]{1,128}$/.test(String(value || ''));
    }

    function trackConversion(input, legacyParameters) {
        if (!hasConsent('marketing') || !config.capabilities.marketing) return false;

        var data = {};
        var source;
        var sourceKey;

        if (typeof input === 'string') {
            source = legacyParameters || {};
            for (sourceKey in source) {
                if (Object.prototype.hasOwnProperty.call(source, sourceKey)) data[sourceKey] = source[sourceKey];
            }
            data.sendTo = input;
        } else {
            source = input || {};
            for (sourceKey in source) {
                if (Object.prototype.hasOwnProperty.call(source, sourceKey)) data[sourceKey] = source[sourceKey];
            }
        }

        var sendTo = data.sendTo || data.send_to || defaultSendTo();
        var eventName = data.eventName || data.event_name || 'conversion';
        var payload = {};
        var key;
        var sent = false;

        for (key in data) {
            if (!Object.prototype.hasOwnProperty.call(data, key)) continue;
            if (key === 'sendTo' || key === 'send_to' || key === 'eventName' || key === 'event_name') continue;
            payload[key] = data[key];
        }

        applyGoogle(consentState);

        if (gtmMarketingAllowed(consentState)) {
            window.dataLayer = window.dataLayer || [];
            var gtmPayload = {};
            for (key in payload) {
                if (Object.prototype.hasOwnProperty.call(payload, key)) gtmPayload[key] = payload[key];
            }
            gtmPayload.event = 'murconsent_conversion';
            gtmPayload.murconsent_conversion_name = eventName;
            if (sendTo) gtmPayload.send_to = sendTo;
            if (gtmPayload.event_callback && !gtmPayload.eventCallback) gtmPayload.eventCallback = gtmPayload.event_callback;
            window.dataLayer.push(gtmPayload);
            sent = true;
        }

        if (directMarketingAllowed(consentState) && validSendTo(sendTo) && typeof window.gtag === 'function') {
            var directPayload = {};
            for (key in payload) {
                if (Object.prototype.hasOwnProperty.call(payload, key)) directPayload[key] = payload[key];
            }
            directPayload.send_to = sendTo;
            window.gtag('event', 'conversion', directPayload);
            sent = true;
        }

        return sent;
    }

    function trackEvent(category, eventName, parameters) {
        category = normalizedCategory(category);
        if ((category !== 'analytics' && category !== 'marketing') || !hasConsent(category)) return false;
        if (!eventName || typeof eventName !== 'string') return false;

        applyGoogle(consentState);
        parameters = parameters || {};
        var key;
        var sent = false;

        if (categoryUsesGtm(category, consentState)) {
            window.dataLayer = window.dataLayer || [];
            var eventData = {};
            for (key in parameters) {
                if (Object.prototype.hasOwnProperty.call(parameters, key)) eventData[key] = parameters[key];
            }
            eventData.event = eventName;
            eventData.murconsent_category = category;
            window.dataLayer.push(eventData);
            sent = true;
        }

        if (categoryUsesDirect(category, consentState) && typeof window.gtag === 'function') {
            var directParameters = {};
            for (key in parameters) {
                if (Object.prototype.hasOwnProperty.call(parameters, key)) directParameters[key] = parameters[key];
            }
            if (!directParameters.send_to) {
                directParameters.send_to = category === 'analytics' ? config.ga4Id : config.adsId;
            }
            window.gtag('event', eventName, directParameters);
            sent = true;
        }

        return sent;
    }

    function handleConversionLink(event, link) {
        if (!hasConsent('marketing')) return;
        if (event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        var href = link.href;
        if (!href) return;

        var eventName = link.getAttribute('data-murconsent-conversion') || 'link_click';
        var sendTo = link.getAttribute('data-murconsent-send-to') || defaultSendTo();
        var canUseGtm = gtmMarketingAllowed(consentState);
        var canUseDirect = directMarketingAllowed(consentState) && validSendTo(sendTo);
        if (!canUseGtm && !canUseDirect) return;

        var completed = false;
        function navigate() {
            if (completed) return;
            completed = true;
            window.location.href = href;
        }

        var target = String(link.getAttribute('target') || '').toLowerCase();
        var opensNewContext = target && target !== '_self';
        var payload = {
            sendTo: sendTo,
            eventName: eventName
        };

        var value = link.getAttribute('data-murconsent-value');
        var currency = link.getAttribute('data-murconsent-currency');
        var numericValue = Number(value);
        if (value !== null && value !== '' && isFinite(numericValue)) payload.value = numericValue;
        if (currency && /^[A-Za-z]{3}$/.test(currency)) payload.currency = currency.toUpperCase();

        /* A new tab/window is already safe for the current page; do not interfere with navigation. */
        if (opensNewContext) {
            trackConversion(payload);
            return;
        }

        payload.event_callback = navigate;
        payload.event_timeout = 800;
        event.preventDefault();
        if (!trackConversion(payload)) {
            navigate();
            return;
        }

        window.setTimeout(navigate, 900);
    }

    function bindUi() {
        root = document.querySelector('[data-murconsent-root]');
        if (!root) return;

        banner = root.querySelector('[data-murconsent-banner]');
        overlay = root.querySelector('[data-murconsent-overlay]');
        dialog = root.querySelector('[data-murconsent-dialog]');
        statusNode = root.querySelector('[data-murconsent-status]');
        analyticsInput = root.querySelector('[data-murconsent-choice="analytics"]');
        marketingInput = root.querySelector('[data-murconsent-choice="marketing"]');
        flagInput = root.querySelector('[data-murconsent-choice="flag-counter"]');

        if (!loaded.handlers) {
            root.addEventListener('click', handleRootClick);
            document.addEventListener('click', handleDocumentClick);
            document.addEventListener('keydown', handleDialogKeydown);
            loaded.handlers = true;
        }

        renderVisibility();
        insertFlagCounter(consentState || emptyChoice());
    }

    window.MurConsent = {
        version: config.pluginVersion,
        getState: function () {
            return consentState ? normalizeChoice(consentState) : null;
        },
        hasConsent: hasConsent,
        openSettings: function () {
            openSettings(document.activeElement);
        },
        trackConversion: trackConversion,
        trackEvent: trackEvent,
        refreshFlagCounter: function () {
            insertFlagCounter(consentState || emptyChoice());
        }
    };

    removeLegacyCookie();
    consentState = config.previewMode ? null : readState();

    if (consentState) {
        applyGoogle(consentState);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindUi);
    } else {
        bindUi();
    }
}());
