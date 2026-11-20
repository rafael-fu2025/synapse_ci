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
})();
