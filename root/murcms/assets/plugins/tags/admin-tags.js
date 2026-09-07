(() => {
  const input = document.getElementById('field-tags');

  if (!input) {
    return;
  }

  document.querySelectorAll('[data-mur-tag]').forEach((button) => {
    button.addEventListener('click', () => {
      const tag = button.getAttribute('data-mur-tag') || '';
      const current = input.value
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean);

      if (tag !== '' && !current.includes(tag)) {
        current.push(tag);
        input.value = current.join(', ');
        input.focus();
      }
    });
  });
})();
