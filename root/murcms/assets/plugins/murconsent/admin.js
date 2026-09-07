(function () {
    'use strict';


    function matches(element, selector) {
        var method = element.matches || element.msMatchesSelector || element.webkitMatchesSelector;
        return method ? method.call(element, selector) : false;
    }

    function closest(element, selector) {
        while (element && element.nodeType === 1) {
            if (matches(element, selector)) return element;
            element = element.parentElement;
        }
        return null;
    }

    function serviceRows(container) {
        return container.querySelectorAll('[data-murconsent-service-row]');
    }

    function updateContainer(container) {
        var rows = serviceRows(container);
        var addButton = container.querySelector('[data-murconsent-add-service]');

        Array.prototype.forEach.call(rows, function (row, index) {
            var number = row.querySelector('[data-murconsent-service-number]');
            var remove = row.querySelector('[data-murconsent-remove-service]');

            if (number) number.textContent = 'Запись ' + (index + 1);
            if (remove) remove.disabled = rows.length <= 1;
        });

        if (addButton) addButton.disabled = rows.length >= 10;
    }

    function addService(container) {
        var rows = serviceRows(container);
        if (!rows.length || rows.length >= 10) return;

        var nextIndex = parseInt(container.getAttribute('data-next-index'), 10);
        if (!isFinite(nextIndex) || nextIndex < 0) nextIndex = rows.length;

        var clone = rows[0].cloneNode(true);
        var controls = clone.querySelectorAll('input, textarea, select');

        Array.prototype.forEach.call(controls, function (control) {
            if (control.name) {
                control.name = control.name.replace(/\[\d+\](?=\[[^\]]+\]$)/, '[' + nextIndex + ']');
            }

            if (control.tagName === 'SELECT') {
                control.selectedIndex = 0;
            } else if (control.type === 'checkbox' || control.type === 'radio') {
                control.checked = false;
            } else {
                control.value = '';
            }
        });

        container.appendChild(clone);
        container.setAttribute('data-next-index', String(nextIndex + 1));
        updateContainer(container);

        var firstInput = clone.querySelector('input, textarea, select');
        if (firstInput && typeof firstInput.focus === 'function') firstInput.focus();
    }

    function removeService(container, row) {
        var rows = serviceRows(container);
        if (rows.length <= 1) return;

        row.parentNode.removeChild(row);
        updateContainer(container);
    }

    function initContainer(container) {
        var addButton = container.querySelector('[data-murconsent-add-service]');
        if (addButton) {
            addButton.addEventListener('click', function () {
                addService(container);
            });
        }

        container.addEventListener('click', function (event) {
            var button = closest(event.target, '[data-murconsent-remove-service]');
            if (!button || !container.contains(button)) return;

            var row = closest(button, '[data-murconsent-service-row]');
            if (row) removeService(container, row);
        });

        updateContainer(container);
    }

    function initTabs(root) {
        var buttons = root.querySelectorAll('[data-murconsent-tab-button]');
        var panels = root.querySelectorAll('[data-murconsent-tab]');
        function activate(id, updateHash) {
            Array.prototype.forEach.call(buttons, function (button) {
                var active = button.getAttribute('data-murconsent-tab-button') === id;
                button.setAttribute('aria-selected', active ? 'true' : 'false');
                button.tabIndex = active ? 0 : -1;
            });
            Array.prototype.forEach.call(panels, function (panel) {
                panel.hidden = panel.getAttribute('data-murconsent-tab') !== id;
            });
            if (updateHash && window.history && window.history.replaceState) {
                window.history.replaceState(null, '', '#murconsent-' + id);
            }
        }
        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function () { activate(button.getAttribute('data-murconsent-tab-button'), true); });
            button.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
                event.preventDefault();
                var list = Array.prototype.slice.call(buttons);
                var index = list.indexOf(button);
                index = event.key === 'ArrowRight' ? (index + 1) % list.length : (index - 1 + list.length) % list.length;
                list[index].focus();
                activate(list[index].getAttribute('data-murconsent-tab-button'), true);
            });
        });
        var requested = String(window.location.hash || '').replace('#murconsent-', '');
        var valid = false;
        Array.prototype.forEach.call(buttons, function (button) { if (button.getAttribute('data-murconsent-tab-button') === requested) valid = true; });
        activate(valid ? requested : 'main', false);
    }

    function init() {
        var containers = document.querySelectorAll('[data-murconsent-services]');
        Array.prototype.forEach.call(containers, initContainer);
        var roots = document.querySelectorAll('[data-murconsent-admin]');
        Array.prototype.forEach.call(roots, initTabs);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
