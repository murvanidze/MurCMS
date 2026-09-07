(() => {
  'use strict';

  const insertAtSelection = (textarea, before, after = '', mode = 'wrap') => {
    const start = textarea.selectionStart ?? textarea.value.length;
    const end = textarea.selectionEnd ?? start;
    const selected = textarea.value.slice(start, end);
    let replacement = '';
    let selectionStart = start;
    let selectionEnd = start;

    if (mode === 'line') {
      const lineStart = textarea.value.lastIndexOf('\n', Math.max(0, start - 1)) + 1;
      replacement = before;
      textarea.setRangeText(replacement, lineStart, lineStart, 'end');
      textarea.focus();
      return;
    }

    if (mode === 'insert') {
      replacement = before;
      selectionStart = start + replacement.length;
      selectionEnd = selectionStart;
    } else if (mode === 'block') {
      const content = selected || 'Текст';
      replacement = `${before}\n${content}\n${after}`;
      selectionStart = start + before.length + 1;
      selectionEnd = selectionStart + content.length;
    } else {
      const content = selected || 'текст';
      replacement = before + content + after;
      selectionStart = start + before.length;
      selectionEnd = selectionStart + content.length;
    }

    textarea.setRangeText(replacement, start, end, 'end');
    textarea.focus();
    textarea.setSelectionRange(selectionStart, selectionEnd);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  };

  const init = () => {
    document.querySelectorAll('.murtext-editor-tools').forEach((tools) => {
      const form = tools.closest('form') || document;
      const targetName = tools.dataset.murtextTarget || 'body';
      const textarea = form.querySelector(`textarea[name="${CSS.escape(targetName)}"]`);
      const dialog = tools.nextElementSibling?.matches('[data-murtext-dialog]')
        ? tools.nextElementSibling
        : document.querySelector('[data-murtext-dialog]');

      if (!textarea) {
        tools.hidden = true;
        if (dialog) dialog.hidden = true;
        return;
      }

      tools.querySelectorAll('[data-murtext-action]').forEach((button) => {
        button.addEventListener('click', () => {
          insertAtSelection(
            textarea,
            button.dataset.murtextBefore || '',
            button.dataset.murtextAfter || '',
            button.dataset.murtextAction || 'wrap',
          );
        });
      });

      tools.querySelector('[data-murtext-help]')?.addEventListener('click', () => {
        if (!dialog) return;
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', 'open');
      });

      dialog?.querySelector('[data-murtext-close]')?.addEventListener('click', () => {
        dialog.close();
      });

      dialog?.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
      });

      const typeSelect = form.querySelector('select[name="type"]');
      if (typeSelect) {
        const sync = () => {
          tools.hidden = typeSelect.value === 'php';
        };
        typeSelect.addEventListener('change', sync);
        sync();
      }
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
