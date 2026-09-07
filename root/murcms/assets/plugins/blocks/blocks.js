(function () {
  'use strict';

  function animateCounters(root) {
    var counters = Array.prototype.slice.call(root.querySelectorAll('[data-count-to]'));
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    counters.forEach(function (counter) {
      var target = Number(counter.getAttribute('data-count-to') || 0);

      if (reduceMotion) {
        counter.textContent = String(target);
        return;
      }

      var duration = 1100;
      var startTime = 0;

      function tick(time) {
        if (!startTime) {
          startTime = time;
        }

        var progress = Math.min(1, (time - startTime) / duration);
        var eased = 1 - Math.pow(1 - progress, 3);
        counter.textContent = String(Math.round(target * eased));

        if (progress < 1) {
          window.requestAnimationFrame(tick);
        }
      }

      window.requestAnimationFrame(tick);
    });
  }

  function initCounters() {
    var blocks = Array.prototype.slice.call(document.querySelectorAll('[data-mur-counters]'));

    if (!blocks.length) {
      return;
    }

    if (!('IntersectionObserver' in window)) {
      blocks.forEach(animateCounters);
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) {
          return;
        }

        animateCounters(entry.target);
        observer.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0.25 });

    blocks.forEach(function (block) {
      observer.observe(block);
    });
  }

  function initCarousels() {
    Array.prototype.slice.call(document.querySelectorAll('[data-mur-carousel]')).forEach(function (carousel) {
      var track = carousel.querySelector('.mur-carousel-track');
      var previous = carousel.querySelector('[data-carousel-prev]');
      var next = carousel.querySelector('[data-carousel-next]');

      if (!track || !previous || !next) {
        return;
      }

      function updateButtons() {
        var max = track.scrollWidth - track.clientWidth - 2;
        previous.disabled = track.scrollLeft <= 2;
        next.disabled = track.scrollLeft >= max;
      }

      function scrollByPage(direction) {
        track.scrollBy({ left: direction * Math.max(220, track.clientWidth * 0.85), behavior: 'smooth' });
      }

      previous.addEventListener('click', function () {
        scrollByPage(-1);
      });

      next.addEventListener('click', function () {
        scrollByPage(1);
      });

      track.addEventListener('scroll', updateButtons, { passive: true });
      window.addEventListener('resize', updateButtons);
      updateButtons();
    });
  }

  initCounters();
  initCarousels();
})();
