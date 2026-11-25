(function () {
  'use strict';

  try {
    const storedTheme = localStorage.getItem('na-dark-mode');
    const prefersDark = Boolean(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    const theme = storedTheme === '1' || (!storedTheme && prefersDark) ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', theme);
  } catch (_) {
    document.documentElement.setAttribute('data-theme', 'light');
  }

  document.addEventListener('error', function (event) {
    const img = event.target;
    if (!(img instanceof HTMLImageElement)) return;
    const fallback = img.getAttribute('data-fallback-src');
    if (!fallback || img.dataset.fallbackApplied === '1') return;
    img.dataset.fallbackApplied = '1';
    img.src = fallback;
  }, true);
})();
