(function () {
  'use strict';

  function openDialog(id) {
    var dialog = document.getElementById(id);
    if (dialog && typeof dialog.showModal === 'function') {
      dialog.showModal();
    }
  }

  document.querySelectorAll('[data-dialog-open]').forEach(function (button) {
    button.addEventListener('click', function () {
      openDialog(button.dataset.dialogOpen);
    });
  });

  document.querySelectorAll('[data-selection-scope]').forEach(function (scope) {
    var checkboxes = Array.from(scope.querySelectorAll(
      'input[type="checkbox"][name="selected[]"], input[type="checkbox"][name="selected_files[]"]'
    ));
    var count = scope.querySelector('[data-selection-count]');
    var requiredButtons = scope.querySelectorAll('[data-selection-requires]');

    function updateSelectionState() {
      var selected = checkboxes.filter(function (checkbox) {
        return checkbox.checked;
      }).length;
      if (count) {
        count.textContent = 'Выбрано: ' + selected;
      }
      requiredButtons.forEach(function (button) {
        button.disabled = selected === 0;
      });
    }

    scope.querySelectorAll('[data-selection-action]').forEach(function (button) {
      button.addEventListener('click', function () {
        checkboxes.forEach(function (checkbox) {
          checkbox.checked = button.dataset.selectionAction === 'all' ? true : !checkbox.checked;
        });
        updateSelectionState();
      });
    });

    checkboxes.forEach(function (checkbox) {
      checkbox.addEventListener('change', updateSelectionState);
    });
    updateSelectionState();
  });

  document.querySelectorAll('[data-block-copy-from]').forEach(function (button) {
    button.addEventListener('click', function () {
      var source = document.getElementById(button.dataset.blockCopyFrom);
      var target = document.getElementById(button.dataset.blockCopyTo);
      if (source && target) {
        target.value = source.value;
      }
    });
  });

  document.querySelectorAll('[data-theme-block-create]').forEach(function (button) {
    button.addEventListener('click', function () {
      var dialog = document.getElementById('theme-block-create-dialog');
      if (dialog) {
        dialog.querySelector('[name="block_folder"]').value = button.dataset.themeBlockCreate;
        openDialog(dialog.id);
      }
    });
  });

  document.querySelectorAll('[data-theme-folder-create]').forEach(function (button) {
    button.addEventListener('click', function () {
      var dialog = document.getElementById('theme-folder-create-dialog');
      if (dialog) {
        dialog.querySelector('[name="block_folder"]').value = button.dataset.themeFolderCreate;
        openDialog(dialog.id);
      }
    });
  });

  document.querySelectorAll('[data-theme-block-clone]').forEach(function (button) {
    button.addEventListener('click', function () {
      var dialog = document.getElementById('theme-block-clone-dialog');
      if (!dialog) {
        return;
      }

      var source = button.dataset.themeBlockClone;
      var id = button.dataset.themeBlockId;
      var language = button.dataset.themeBlockLanguage;
      dialog.querySelector('[name="source_file"]').value = source;
      dialog.querySelector('[name="block_language"]').value = language;
      dialog.querySelector('[data-clone-source-label]').textContent = source;
      var pathParts = source.replace(new RegExp('^content/' + language + '/'), '').split('/');
      pathParts.pop();
      dialog.querySelector('[name="block_target"]').value = pathParts.concat(id + '-copy').join('/');
      openDialog(dialog.id);
    });
  });

  document.querySelectorAll('[data-file-replace-open]').forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      var dialog = document.getElementById('admin-file-replace-dialog');
      if (!dialog) {
        return;
      }
      dialog.querySelector('[name="scope"]').value = button.dataset.fileScope || '';
      dialog.querySelector('[name="file"]').value = button.dataset.filePath || '';
      dialog.querySelector('[data-file-replace-target]').textContent = button.dataset.filePath || '';
      var result = dialog.querySelector('[data-file-result]');
      if (result) {
        result.textContent = '';
        result.classList.remove('is-error');
      }
      openDialog(dialog.id);
    });
  });

  document.querySelectorAll('[data-file-drop]').forEach(function (drop) {
    var input = drop.querySelector('input[type="file"]');
    ['dragenter', 'dragover'].forEach(function (name) {
      drop.addEventListener(name, function (event) {
        event.preventDefault();
        drop.classList.add('is-dragover');
      });
    });
    ['dragleave', 'drop'].forEach(function (name) {
      drop.addEventListener(name, function (event) {
        event.preventDefault();
        drop.classList.remove('is-dragover');
      });
    });
    drop.addEventListener('drop', function (event) {
      if (input && event.dataTransfer && event.dataTransfer.files.length === 1) {
        input.files = event.dataTransfer.files;
      }
    });
  });

  document.querySelectorAll('[data-mur-file-upload], [data-mur-file-replace]').forEach(function (form) {
    if (!window.fetch || !window.FormData) {
      return;
    }
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var result = form.querySelector('[data-file-result]');
      if (result) {
        result.textContent = 'Загрузка…';
        result.classList.remove('is-error');
      }
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        credentials: 'same-origin'
      }).then(function (response) {
        return response.json().then(function (data) {
          return {ok: response.ok && data.ok, message: data.message || 'Неизвестный результат операции.'};
        });
      }).then(function (data) {
        if (result) {
          result.textContent = data.message;
          result.classList.toggle('is-error', !data.ok);
        }
        if (data.ok) {
          form.reset();
        }
      }).catch(function () {
        if (result) {
          result.textContent = 'Не удалось выполнить запрос. Попробуйте обычную отправку формы.';
          result.classList.add('is-error');
        }
      });
    });
  });

  document.querySelectorAll('[data-dialog-close]').forEach(function (button) {
    button.addEventListener('click', function () {
      var dialog = button.closest('dialog');
      if (dialog) {
        dialog.close();
      }
    });
  });

  document.querySelectorAll('dialog.admin-dialog').forEach(function (dialog) {
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) {
        dialog.close();
      }
    });
  });
})();
