(() => {
  const zones = document.querySelectorAll('[data-media-dropzone]');

  zones.forEach((zone) => {
    const input = zone.querySelector('[data-media-input]');

    if (!input) {
      return;
    }

    ['dragenter', 'dragover'].forEach((eventName) => {
      zone.addEventListener(eventName, (event) => {
        event.preventDefault();
        zone.classList.add('is-dragging');
      });
    });

    ['dragleave', 'drop'].forEach((eventName) => {
      zone.addEventListener(eventName, () => {
        zone.classList.remove('is-dragging');
      });
    });

    zone.addEventListener('drop', (event) => {
      event.preventDefault();

      if (event.dataTransfer?.files?.length) {
        input.files = event.dataTransfer.files;
      }
    });
  });

  document.querySelectorAll('.admin-copy-field input[readonly]').forEach((input) => {
    input.addEventListener('focus', () => input.select());
  });

  document.querySelectorAll('[data-webp-conversion]').forEach((checkbox) => {
    const form = checkbox.closest('form');
    const quality = form?.querySelector('[data-webp-quality] input');

    const updateQuality = () => {
      if (quality) {
        quality.disabled = !checkbox.checked;
      }
    };

    checkbox.addEventListener('change', updateQuality);
    updateQuality();
  });

  document.querySelectorAll('[data-media-urls-open]').forEach((button) => {
    button.addEventListener('click', (event) => {
      const form = button.closest('form');
      const dialog = document.getElementById('media-bulk-urls-dialog');
      const output = dialog?.querySelector('[data-media-urls-output]');
      const count = dialog?.querySelector('[data-media-urls-count]');
      const status = dialog?.querySelector('[data-media-urls-status]');
      const selected = form
        ? Array.from(form.querySelectorAll('input[name="selected_files[]"]:checked'))
        : [];

      if (!dialog || !output || selected.length === 0 || typeof dialog.showModal !== 'function') {
        return;
      }

      event.preventDefault();
      const urls = selected.map((checkbox) => {
        const path = checkbox.value
          .split('/')
          .filter(Boolean)
          .map((segment) => encodeURIComponent(segment))
          .join('/');
        return `/uploads/${path}`;
      });

      output.value = urls.join('\n');
      output.rows = Math.min(16, Math.max(4, urls.length));
      if (count) {
        count.textContent = `Файлов: ${urls.length}.`;
      }
      if (status) {
        status.textContent = '';
      }
      dialog.showModal();
      output.focus();
      output.select();
    });
  });

  document.querySelectorAll('[data-media-urls-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
      const dialog = button.closest('dialog');
      const output = dialog?.querySelector('[data-media-urls-output]');
      const status = dialog?.querySelector('[data-media-urls-status]');

      if (!output) {
        return;
      }

      try {
        await navigator.clipboard.writeText(output.value);
        if (status) {
          status.textContent = 'Адреса скопированы.';
        }
      } catch (error) {
        output.focus();
        output.select();
        if (status) {
          status.textContent = 'Выделите адреса и скопируйте их вручную.';
        }
      }
    });
  });
})();
