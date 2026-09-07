'use strict';

const fs = require('fs');
const vm = require('vm');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '../../../root/murcms/assets/plugins/murconsent/murconsent.js'), 'utf8');
let passed = 0;
function assert(condition, message) {
    if (!condition) throw new Error('FAIL: ' + message);
    passed += 1;
}
function config(overrides) {
    return Object.assign({
        pluginVersion: '1.0.1', schemaVersion: '1.0', cookieName: 'murconsent_preferences', days: 365,
        language: 'en', ga4Enabled: true, ga4Id: 'G-ABCD1234', adsEnabled: true,
        adsId: 'AW-123456789', adsLabel: 'LABEL_1', gtmEnabled: true, gtmId: 'GTM-ABC1234',
        gtmAnalyticsEnabled: false, gtmMarketingEnabled: true,
        capabilities: {analytics: true, marketing: true, flagCounter: false},
        flagCounterHtml: '', previewMode: false, reloadOnWithdraw: true
    }, overrides || {});
}
function run(cfg, cookieState) {
    const requests = [];
    const cookie = cookieState ? cfg.cookieName + '=' + encodeURIComponent(JSON.stringify(cookieState)) : '';
    const scriptNode = {getAttribute(name) { return name === 'data-murconsent-config' ? Buffer.from(JSON.stringify(cfg), 'utf8').toString('base64') : ''; }};
    const document = {
        currentScript: scriptNode, readyState: 'complete', cookie,
        head: {appendChild(node) { requests.push(node.src); }},
        documentElement: {classList: {add(){}, remove(){}}},
        querySelector(selector) { if (selector === 'script[data-murconsent-config]') return scriptNode; return null; },
        querySelectorAll() { return []; },
        createElement() { return {id:'', async:false, src:''}; },
        getElementById() { return null; }, addEventListener(){}, dispatchEvent(){},
    };
    const window = {
        atob(value) { return Buffer.from(value, 'base64').toString('binary'); },
        TextDecoder, Uint8Array, location: {protocol:'https:', href:'https://example.test/'},
        setTimeout(fn) { fn(); }, clearTimeout(){}, CustomEvent: function(name, opts){ this.type=name; this.detail=opts.detail; }
    };
    window.window = window;
    const context = vm.createContext({window, document, TextDecoder, Uint8Array, CustomEvent: window.CustomEvent, Date, JSON, Math, Number, String, Boolean, Object, Array, RegExp, encodeURIComponent, decodeURIComponent, escape, isFinite, console});
    vm.runInContext(source, context, {filename:'murconsent.js'});
    return {window, document, requests};
}

let result = run(config(), null);
assert(result.requests.length === 0, 'no Google request before consent');
assert(typeof result.window.MurConsent === 'object', 'MurConsent API exposed');
assert(result.window.MurConsent.getState() === null, 'no state without cookie');
assert(result.window.MurConsent.hasConsent('analytics') === false, 'analytics denied without cookie');
assert(result.window.MurConsent.trackConversion({sendTo:'AW-123456789/LABEL_1'}) === false, 'conversion blocked without marketing consent');

const timestamp = new Date().toISOString();
result = run(config({gtmEnabled:false, gtmMarketingEnabled:false}), {schema:'1.0', timestamp, analytics:true, marketing:false, flag_counter:false});
assert(result.requests.length === 1, 'one direct Google script after analytics consent');
assert(result.requests[0].includes('googletagmanager.com/gtag/js?id=G-ABCD1234'), 'GA4 destination loaded');
assert(result.window.MurConsent.hasConsent('analytics') === true, 'persisted analytics consent read');
assert(result.window.MurConsent.hasConsent('marketing') === false, 'persisted marketing refusal read');

result = run(config(), {schema:'1.0', timestamp, analytics:false, marketing:true, flag_counter:false});
assert(result.requests.some(url => url.includes('/gtag/js?id=AW-123456789')), 'direct Ads Google tag loaded');
assert(result.requests.some(url => url.includes('/gtm.js?id=GTM-ABC1234')), 'marketing GTM loaded');
assert(result.window.dataLayer.some(item => item && item.event === 'murconsent_ready'), 'GTM consent state event pushed');
assert(result.window.MurConsent.trackConversion({sendTo:'AW-123456789/LABEL_1', eventName:'phone_click'}) === true, 'conversion sent with marketing consent');

result = run(config({previewMode:true}), {schema:'1.0', timestamp, analytics:true, marketing:true, flag_counter:false});
assert(result.requests.length === 0, 'preview never loads Google');
assert(result.window.MurConsent.getState() === null, 'preview ignores stored consent');

result = run(config(), {schema:'old', timestamp, analytics:true, marketing:true, flag_counter:false});
assert(result.requests.length === 0, 'schema mismatch fails closed');
assert(result.window.MurConsent.getState() === null, 'schema mismatch invalidates state');

assert(source.includes("'murconsent:change'"), 'MurConsent change event implemented');
assert(source.includes('data-murconsent-conversion'), 'automatic conversion links implemented');
assert(source.includes("ad_personalization: 'denied'"), 'ad personalization always denied');
assert(!source.includes('window.Consenta'), 'old public API name removed');

console.log('JavaScript tests passed: ' + passed);
