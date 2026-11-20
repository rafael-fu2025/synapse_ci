(function () {
  'use strict';

  document.addEventListener('contextmenu', function (event) {
    if (event.target && typeof event.target.closest === 'function' && event.target.closest('[data-no-contextmenu]')) {
      event.preventDefault();
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    // Focus server-rendered error/warning banners without disrupting layout scroll.
    const alertRegion = document.getElementById('auth-alerts');
    if (alertRegion && alertRegion.dataset.hasAlert === '1') {
      try {
        alertRegion.focus({ preventScroll: true });
      } catch (_) {
        alertRegion.focus();
      }
    }

    // Hover-only tooltip trigger with 150ms show delay.
    if (window.bootstrap && window.bootstrap.Tooltip) {
      document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        const tip = new window.bootstrap.Tooltip(el, {
          trigger: 'hover',
          html: true,
          delay: { show: 150, hide: 100 },
        });
        el.addEventListener('click', function () {
          tip.hide();
        });
      });
    }

    // Background photo carousel with WCAG 2.2 SC 2.2.2 Pause/Play control and reduced-motion pause.
    const carouselEl = document.getElementById('loginPhotoCarousel');
    if (carouselEl && carouselEl.querySelectorAll('.carousel-item').length > 1 && window.bootstrap && window.bootstrap.Carousel) {
      const interval = parseInt(carouselEl.getAttribute('data-bs-interval') || '6000', 10) || 6000;
      const pauseBtn = carouselEl.querySelector('[data-carousel-toggle]');
      const motionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
      const carousel = window.bootstrap.Carousel.getOrCreateInstance(carouselEl, {
        interval: interval,
        ride: false,
        wrap: true,
        touch: false,
      });

      let paused = Boolean(motionQuery && motionQuery.matches);

      const syncCarouselState = function (nextPaused) {
        paused = Boolean(nextPaused);
        if (paused) {
          carousel.pause();
        } else {
          carousel.cycle();
        }
        carouselEl.dataset.carouselPaused = paused ? 'true' : 'false';
        if (pauseBtn) {
          pauseBtn.setAttribute('aria-pressed', paused ? 'true' : 'false');
          pauseBtn.setAttribute('aria-label', paused ? 'Resume background slideshow' : 'Pause background slideshow');
          const pauseIcon = pauseBtn.querySelector('.carousel-icon-pause');
          const playIcon = pauseBtn.querySelector('.carousel-icon-play');
          if (pauseIcon) pauseIcon.classList.toggle('d-none', paused);
          if (playIcon) playIcon.classList.toggle('d-none', !paused);
        }
      };

      syncCarouselState(paused);

      if (pauseBtn) {
        pauseBtn.addEventListener('click', function (event) {
          event.preventDefault();
          syncCarouselState(!paused);
        });
      }

      if (motionQuery && typeof motionQuery.addEventListener === 'function') {
        motionQuery.addEventListener('change', function (event) {
          if (event.matches) {
            syncCarouselState(true);
          }
        });
      }
    }

    // Password visibility toggle with dynamic aria-pressed / aria-label (WCAG 4.1.2).
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
      btn.addEventListener('click', function (event) {
        event.preventDefault();
        const wrapper = btn.closest('.password-field-wrapper') || btn.closest('.input-group');
        if (!wrapper) return;
        const input = wrapper.querySelector('input');
        if (!input) return;
        const eyeOpen = btn.querySelector('.eye-icon-open');
        const eyeClosed = btn.querySelector('.eye-icon-closed');

        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        btn.setAttribute('aria-pressed', reveal ? 'true' : 'false');
        btn.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
        if (eyeOpen) {
          eyeOpen.classList.toggle('d-none', reveal);
          eyeOpen.classList.toggle('d-flex', !reveal);
        }
        if (eyeClosed) {
          eyeClosed.classList.toggle('d-none', !reveal);
          eyeClosed.classList.toggle('d-flex', reveal);
        }
        input.focus();
      });
    });

    // Login screen theme toggle (radial pixel-dissolve reveal matching app shell).
    const authToggle = document.getElementById('authThemeToggle');
    if (authToggle) {
      const root = document.documentElement;
      let animTimer = 0;

      const applyTheme = function (nextTheme) {
        root.setAttribute('data-theme', nextTheme);
      };

      const originPoint = function (ev) {
        if (ev && (ev.clientX || ev.clientY)) return { x: ev.clientX, y: ev.clientY };
        const rect = authToggle.getBoundingClientRect();
        return rect.width || rect.height
          ? { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 }
          : { x: window.innerWidth, y: window.innerHeight };
      };

      const revealTheme = function (nextTheme, x, y) {
        const duration = 900;
        const fade = 7;
        const block = Math.max(9, Math.round(Math.min(window.innerWidth, window.innerHeight) / 72));
        const cols = Math.ceil(window.innerWidth / block) + 1;
        const rows = Math.ceil(window.innerHeight / block) + 1;
        const cx = x / block;
        const cy = y / block;
        const maxRadius = Math.hypot(Math.max(cx, cols - cx), Math.max(cy, rows - cy));
        const vt = (root.__synapseThemeVt = document.startViewTransition(function () {
          root.classList.add('theme-instant');
          applyTheme(nextTheme);
        }));
        const cleanup = function () {
          if (root.__synapseThemeVt !== vt) return;
          root.classList.remove('theme-instant');
          root.style.removeProperty('--vt-mask');
        };
        vt.ready
          .then(function () {
            if (root.__synapseThemeVt !== vt) return;
            root.classList.remove('theme-instant');
            root.animate(
              { opacity: [1, 1] },
              { duration: duration, easing: 'linear', pseudoElement: '::view-transition-new(root)' }
            );
            const cnv = document.createElement('canvas');
            cnv.width = cols;
            cnv.height = rows;
            const ctx = cnv.getContext('2d');
            if (!ctx) return;
            ctx.fillStyle = '#fff';
            const t0 = performance.now();
            (function frame(now) {
              if (root.__synapseThemeVt !== vt) return;
              const t = Math.min((now - t0) / duration, 1);
              const k = t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
              const radius = k * (maxRadius + fade);
              ctx.clearRect(0, 0, cols, rows);
              for (let row = 0; row < rows; row++) {
                for (let col = 0; col < cols; col++) {
                  const dist = Math.hypot(col - cx, row - cy);
                  const hash = Math.abs(Math.sin(col * 127.1 + row * 311.7) * 43758.5453) % 1;
                  const alpha = (radius - dist - (hash - 0.5) * 1.4) / fade;
                  if (alpha > 0) {
                    ctx.globalAlpha = alpha >= 1 ? 1 : alpha;
                    ctx.fillRect(col, row, 1, 1);
                  }
                }
              }
              ctx.globalAlpha = 1;
              root.style.setProperty('--vt-mask', 'url("' + cnv.toDataURL() + '")');
              if (t < 1) requestAnimationFrame(frame);
              else root.style.removeProperty('--vt-mask');
            })(t0);
          })
          .catch(cleanup);
        vt.finished.then(cleanup, cleanup);
      };

      authToggle.addEventListener('click', function (event) {
        event.preventDefault();
        if (window.bootstrap && window.bootstrap.Tooltip) {
          const tip = window.bootstrap.Tooltip.getInstance(authToggle);
          if (tip) tip.hide();
        }
        document.querySelectorAll('.tooltip').forEach(function (t) {
          t.remove();
        });

        const isDark = root.getAttribute('data-theme') === 'dark';
        const nextTheme = isDark ? 'light' : 'dark';
        try {
          localStorage.setItem('na-dark-mode', isDark ? '0' : '1');
        } catch (_) {
          /* Storage may be disabled or full. */
        }

        const reduceMotion = Boolean(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        if (reduceMotion) {
          applyTheme(nextTheme);
          return;
        }
        if (!document.startViewTransition) {
          root.classList.add('theme-anim');
          applyTheme(nextTheme);
          clearTimeout(animTimer);
          animTimer = setTimeout(function () {
            root.classList.remove('theme-anim');
          }, 520);
          return;
        }
        const origin = originPoint(event);
        revealTheme(nextTheme, origin.x, origin.y);
      });
    }
  });
})();
