'use strict';

document.querySelectorAll('[data-updated-at]').forEach((control) => {
  const parts = Object.fromEntries(Array.from(control.querySelectorAll('[data-date-part]'), (field) => [field.dataset.datePart, field]));
  const permission = control.querySelector('[data-date-permission]');
  const formatter = new Intl.DateTimeFormat('en-GB', {
    timeZone: control.dataset.timezone, year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  });
  const started = performance.now();
  const serverTime = Number(control.dataset.siteNow) * 1000;
  const currentParts = () => Object.fromEntries(formatter.formatToParts(new Date(serverTime + performance.now() - started)).map((part) => [part.type, part.value]));
  const daysInMonth = () => {
    const date = new Date(0);
    date.setUTCFullYear(Number(parts.year.value), Number(parts.month.value), 0);
    return date.getUTCDate();
  };
  const updateDays = () => {
    const days = daysInMonth();
    Array.from(parts.day.options).forEach((option) => { option.disabled = Number(option.value) > days; });
    if (Number(parts.day.value) > days) parts.day.value = String(days);
  };
  Object.values(parts).forEach((field) => {
    const changed = () => { updateDays(); permission.checked = true; };
    field.addEventListener('input', changed);
    field.addEventListener('change', changed);
  });
  control.querySelector('[data-date-now]').addEventListener('click', () => {
    const now = currentParts();
    Object.entries(parts).forEach(([key, field]) => { field.value = String(Number(now[key])); });
    updateDays();
    permission.checked = true;
  });
  const clock = control.querySelector('[data-site-clock]');
  const tick = () => {
    const now = currentParts();
    clock.textContent = `${now.year}-${now.month}-${now.day} ${now.hour}:${now.minute}:${now.second}`;
  };
  updateDays();
  tick();
  setInterval(tick, 1000);
});
