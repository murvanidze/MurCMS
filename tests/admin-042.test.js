'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const source = (name) => fs.readFileSync(path.join(__dirname, '../root/murcms/assets', name), 'utf8');
let checks = 0;
const check = (condition) => { assert.ok(condition); checks++; };
const field = (value) => ({ value: String(value), handlers: {}, addEventListener(type, callback) { this.handlers[type] = callback; } });
const parts = { year: field(2024), month: field(2), day: field(29), hour: field(12), minute: field(34), second: field(56) };
parts.day.options = Array.from({ length: 31 }, (_, index) => ({ value: String(index + 1), disabled: false }));
Object.entries(parts).forEach(([key, part]) => { part.dataset = { datePart: key }; });
const permission = { checked: false };
const button = field('');
const clock = {};
const control = {
  dataset: { timezone: 'Europe/Chisinau', siteNow: '1794045600' },
  querySelectorAll: () => Object.values(parts),
  querySelector: (selector) => ({ '[data-date-permission]': permission, '[data-date-now]': button, '[data-site-clock]': clock }[selector]),
};
vm.runInNewContext(source('admin-dates.js'), { document: { querySelectorAll: () => [control] }, Intl, Date, performance: { now: () => 0 }, setInterval: () => {} });
check(!permission.checked);
check(clock.textContent.length === 19);
permission.checked = true;
check(parts.year.value === '2024' && parts.second.value === '56');
permission.checked = false;
parts.year.value = '2023'; parts.year.handlers.input();
check(permission.checked && parts.day.value === '28' && parts.day.options[28].disabled);
permission.checked = false;
parts.year.value = '2024'; parts.year.handlers.change();
check(permission.checked && !parts.day.options[28].disabled);
permission.checked = false;
button.handlers.click();
check(permission.checked && parts.year.value === '2026');
check(parts.month.value === '11' && parts.day.value === '7');
permission.checked = false;
check(parts.year.value === '2026');

function berry({ scope, complete = false, idle = true, existing = false, firstStyle = true }) {
  const events = {}, callbacks = [], links = [];
  const loader = { getAttribute: (key) => key === 'data-berrymur-css' ? '/local/berry.css?v=1' : '.mur-berry' };
  const first = {};
  const head = { querySelector: () => firstStyle ? first : null, insertBefore: (link, before) => { check(before === first); links.push(link); }, appendChild: (link) => links.push(link) };
  const document = {
    currentScript: loader, head, readyState: complete ? 'complete' : 'interactive',
    querySelector: () => scope ? {} : null,
    getElementById: (id) => id === 'murcms-berrymur-css' && existing ? {} : null,
    createElement: () => ({ attributes: {}, setAttribute(key, value) { this.attributes[key] = value; }, addEventListener() {} }),
  };
  const window = { addEventListener: (event, callback) => { events[event] = callback; }, setTimeout: (callback) => callbacks.push(callback) };
  if (idle) window.requestIdleCallback = (callback) => callbacks.push(callback);
  vm.runInNewContext(source('plugins/berrymur/berrymur-loader.js'), { document, window });
  return { events, callbacks, links };
}
let result = berry({ scope: false });
check(result.callbacks.length === 0 && !result.events.load && result.links.length === 0);
result = berry({ scope: true });
check(result.callbacks.length === 0 && result.links.length === 0);
result.events.load();
check(result.callbacks.length === 1 && result.links.length === 0);
result.callbacks[0](); result.callbacks[0]();
check(result.links.length === 1 && result.links[0].id === 'murcms-berrymur-css');
check(result.links[0].attributes.fetchpriority === 'low' && result.links[0].href.startsWith('/local/'));
result = berry({ scope: true, complete: true, idle: false, firstStyle: false });
check(result.callbacks.length === 1); result.callbacks[0](); check(result.links.length === 1);
result = berry({ scope: true, complete: true, existing: true }); result.callbacks[0]();
check(result.links.length === 0);
console.log(`Admin date and BerryMur JavaScript checks passed: ${checks}`);
