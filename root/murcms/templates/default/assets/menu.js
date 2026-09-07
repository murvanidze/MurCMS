(function () {
  'use strict';

  var header = document.querySelector('.site-header');
  var button = document.querySelector('.site-menu-toggle');
  var nav = document.querySelector('#site-nav');
  var languageToggle = document.querySelector('.language-toggle');
  var languageList = document.querySelector('.language-menu-list');
  var submenuToggles = nav ? nav.querySelectorAll('.submenu-toggle') : [];
  var nestedSubmenuItems = nav ? nav.querySelectorAll('.site-menu ul .has-children') : [];

  if (!header || !button || !nav) {
    return;
  }

  function setLanguageOpen(open) {
    if (!languageToggle || !languageList) {
      return;
    }

    header.classList.toggle('is-language-open', open);
    languageToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function closeSubmenus() {
    submenuToggles.forEach(function (toggle) {
      toggle.setAttribute('aria-expanded', 'false');
      toggle.closest('.has-children')?.classList.remove('is-submenu-open');
    });
  }

  function setOpen(open) {
    header.classList.toggle('is-menu-open', open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    button.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');

    if (!open) {
      closeSubmenus();
    }
  }

  button.addEventListener('click', function () {
    setLanguageOpen(false);
    setOpen(!header.classList.contains('is-menu-open'));
  });

  if (languageToggle && languageList) {
    languageToggle.addEventListener('click', function () {
      setOpen(false);
      setLanguageOpen(!header.classList.contains('is-language-open'));
    });

    languageList.addEventListener('click', function (event) {
      if (event.target.closest('a')) {
        setLanguageOpen(false);
      }
    });
  }

  submenuToggles.forEach(function (toggle) {
    toggle.addEventListener('click', function () {
      var item = toggle.closest('.has-children');
      var open = toggle.getAttribute('aria-expanded') === 'true';

      if (!item) {
        return;
      }

      toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
      item.classList.toggle('is-submenu-open', !open);

      if (open) {
        item.querySelectorAll('.is-submenu-open').forEach(function (child) {
          child.classList.remove('is-submenu-open');
          child.querySelector(':scope > .site-menu-row > .submenu-toggle')?.setAttribute('aria-expanded', 'false');
        });
      }
    });
  });

  function placeNestedSubmenu(item) {
    if (!window.matchMedia('(min-width: 761px)').matches) {
      item.classList.remove('submenu-opens-left');
      return;
    }

    var submenu = item.querySelector(':scope > ul');
    if (!submenu) {
      return;
    }

    item.classList.remove('submenu-opens-left');
    if (submenu.getBoundingClientRect().right > document.documentElement.clientWidth) {
      item.classList.add('submenu-opens-left');
    }
  }

  nestedSubmenuItems.forEach(function (item) {
    item.addEventListener('mouseenter', function () {
      placeNestedSubmenu(item);
    });
    item.addEventListener('focusin', function () {
      placeNestedSubmenu(item);
    });
  });

  nav.addEventListener('click', function (event) {
    if (event.target.closest('a')) {
      setOpen(false);
      setLanguageOpen(false);
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      setOpen(false);
      setLanguageOpen(false);

      if (document.activeElement === languageToggle) {
        languageToggle.focus({ preventScroll: true });
      } else {
        button.focus({ preventScroll: true });
      }
    }
  });

  document.addEventListener('click', function (event) {
    if (!header.contains(event.target)) {
      setOpen(false);
      setLanguageOpen(false);
    }
  });

  window.addEventListener('resize', function () {
    if (window.matchMedia('(min-width: 761px)').matches) {
      setOpen(false);
      setLanguageOpen(false);
    }
  });
})();
