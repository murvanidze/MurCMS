(function () {
  'use strict';

  var galleries = Array.prototype.slice.call(document.querySelectorAll('[data-mur-gallery]'));

  if (!galleries.length) {
    return;
  }

  var lang = (document.documentElement.lang || 'en').slice(0, 2);
  var labels = {
    ru: {
      viewer: 'Просмотр изображения',
      close: 'Закрыть',
      previous: 'Предыдущее изображение',
      next: 'Следующее изображение'
    },
    ro: {
      viewer: 'Vizualizare imagine',
      close: 'Inchide',
      previous: 'Imaginea precedenta',
      next: 'Imaginea urmatoare'
    },
    en: {
      viewer: 'Image viewer',
      close: 'Close',
      previous: 'Previous image',
      next: 'Next image'
    }
  }[lang] || {
    viewer: 'Image viewer',
    close: 'Close',
    previous: 'Previous image',
    next: 'Next image'
  };

  var state = {
    items: [],
    index: 0,
    opener: null,
    active: false,
    gallery: '',
    historyDepth: 0,
    closing: false
  };

  var lightbox = document.createElement('div');
  lightbox.className = 'mur-lightbox';
  lightbox.setAttribute('role', 'dialog');
  lightbox.setAttribute('aria-modal', 'true');
  lightbox.setAttribute('aria-label', labels.viewer);
  lightbox.innerHTML = [
    '<div class="mur-lightbox-top">',
    '  <span class="mur-lightbox-counter" data-counter></span>',
    '  <button class="mur-lightbox-button mur-lightbox-close" type="button" aria-label="' + labels.close + '">&times;</button>',
    '</div>',
    '<div class="mur-lightbox-stage" data-stage>',
    '  <button class="mur-lightbox-button mur-lightbox-prev" type="button" aria-label="' + labels.previous + '">&#8249;</button>',
    '  <img class="mur-lightbox-image" alt="" data-image>',
    '  <button class="mur-lightbox-button mur-lightbox-next" type="button" aria-label="' + labels.next + '">&#8250;</button>',
    '</div>',
    '<div class="mur-lightbox-bottom">',
    '  <div class="mur-lightbox-caption" data-caption></div>',
    '</div>'
  ].join('');
  document.body.appendChild(lightbox);

  var image = lightbox.querySelector('[data-image]');
  var caption = lightbox.querySelector('[data-caption]');
  var counter = lightbox.querySelector('[data-counter]');
  var stage = lightbox.querySelector('[data-stage]');
  var closeButton = lightbox.querySelector('.mur-lightbox-close');
  var previousButton = lightbox.querySelector('.mur-lightbox-prev');
  var nextButton = lightbox.querySelector('.mur-lightbox-next');
  var touchStartX = 0;
  var touchStartY = 0;

  image.decoding = 'async';
  image.loading = 'eager';

  function collect(gallery) {
    return Array.prototype.slice.call(gallery.querySelectorAll('.mur-gallery-link')).map(function (link, index) {
      var img = link.querySelector('img');

      return {
        src: link.href,
        alt: img ? img.alt : '',
        caption: link.getAttribute('data-caption') || link.title || (img ? img.alt : ''),
        index: index
      };
    });
  }

  function open(gallery, index, push) {
    state.items = collect(gallery);

    if (!state.items.length) {
      return;
    }

    state.index = Math.max(0, Math.min(index, state.items.length - 1));
    if (!state.active) {
      state.opener = document.activeElement;
    }
    state.active = true;
    state.gallery = gallery.getAttribute('data-mur-gallery') || 'gallery';
    lightbox.classList.add('is-open');
    document.body.classList.add('mur-lightbox-lock');
    render();
    closeButton.focus({ preventScroll: true });

    if (push !== false) {
      pushHistory();
    } else if (history.state && history.state.murGallery) {
      state.historyDepth = Number(history.state.depth || 1);
    }
  }

  function close(fromHistory) {
    if (!state.active) {
      return;
    }

    state.active = false;
    lightbox.classList.remove('is-open');
    document.body.classList.remove('mur-lightbox-lock');
    image.removeAttribute('src');

    if (state.opener && typeof state.opener.focus === 'function') {
      state.opener.focus({ preventScroll: true });
    }

    if (!fromHistory && state.historyDepth > 0) {
      state.closing = true;
      history.go(-state.historyDepth);
    }

    state.historyDepth = 0;
  }

  function render() {
    var item = state.items[state.index];

    if (!item) {
      return;
    }

    image.src = item.src;
    image.alt = item.alt;
    caption.textContent = item.caption || '';
    counter.textContent = String(state.index + 1) + ' / ' + String(state.items.length);
  }

  function go(delta, push) {
    if (!state.active || state.items.length < 2) {
      return;
    }

    state.index = (state.index + delta + state.items.length) % state.items.length;
    render();

    if (push !== false) {
      pushHistory();
    }
  }

  function pushHistory() {
    var url = new URL(window.location.href);
    url.hash = 'gallery=' + encodeURIComponent(state.gallery) + '&image=' + String(state.index + 1);
    state.historyDepth += 1;
    history.pushState({ murGallery: true, gallery: state.gallery, index: state.index, depth: state.historyDepth }, '', url);
  }

  galleries.forEach(function (gallery) {
    gallery.addEventListener('click', function (event) {
      var link = event.target.closest('.mur-gallery-link');

      if (!link || !gallery.contains(link)) {
        return;
      }

      event.preventDefault();
      open(gallery, Number(link.getAttribute('data-index') || 0), true);
    });
  });

  closeButton.addEventListener('click', function () {
    close(false);
  });

  previousButton.addEventListener('click', function () {
    go(-1, true);
  });

  nextButton.addEventListener('click', function () {
    go(1, true);
  });

  stage.addEventListener('click', function (event) {
    if (event.target === stage) {
      close(false);
    }
  });

  stage.addEventListener('touchstart', function (event) {
    if (!event.changedTouches.length) {
      return;
    }

    touchStartX = event.changedTouches[0].clientX;
    touchStartY = event.changedTouches[0].clientY;
  }, { passive: true });

  stage.addEventListener('touchend', function (event) {
    if (!event.changedTouches.length) {
      return;
    }

    var dx = event.changedTouches[0].clientX - touchStartX;
    var dy = event.changedTouches[0].clientY - touchStartY;

    if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.3) {
      go(dx < 0 ? 1 : -1, true);
    }
  }, { passive: true });

  document.addEventListener('keydown', function (event) {
    if (!state.active) {
      return;
    }

    if (event.key === 'Escape') {
      close(false);
    } else if (event.key === 'ArrowLeft') {
      go(-1, true);
    } else if (event.key === 'ArrowRight') {
      go(1, true);
    }
  });

  window.addEventListener('popstate', function (event) {
    if (state.closing) {
      state.closing = false;
      close(true);
      return;
    }

    if (event.state && event.state.murGallery) {
      var gallery = galleries.find(function (candidate) {
        return candidate.getAttribute('data-mur-gallery') === event.state.gallery;
      });

      if (gallery) {
        open(gallery, Number(event.state.index || 0), false);
        state.historyDepth = Number(event.state.depth || 1);
      }

      return;
    }

    close(true);
  });
})();
