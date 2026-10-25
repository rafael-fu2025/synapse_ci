/** Keep table markup and controls intact while exposing column labels for mobile cards. */
(function () {
  'use strict';

  function enhance(table) {
    if (table.closest('[data-mobile-cards="false"]') || !table.tHead || table.tHead.rows.length !== 1) return;
    const headers = Array.from(table.tHead.rows[0].cells);
    // Grouped headers and row-spanning matrices need their original table layout.
    if (headers.some(cell => cell.colSpan !== 1 || cell.rowSpan !== 1) ||
        Array.from(table.tBodies).some(body => Array.from(body.rows).some(row =>
          Array.from(row.cells).some(cell => cell.rowSpan !== 1)))) return;
    table.classList.add('table-mobile-cards');
    table.classList.toggle('table-mobile-controls', !!table.tHead.querySelector('input, button, select'));
    for (const body of table.tBodies) {
      for (const row of body.rows) {
        let column = 0;
        for (const cell of row.cells) {
          const label = headers[column]?.textContent.trim().replace(/\s+/g, ' ') || '';
          if (cell.colSpan === 1 && cell.dataset.label !== label) cell.dataset.label = label;
          column += cell.colSpan;
        }
      }
    }
  }

  function scan(root) {
    const tables = new Set();
    if (root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_NODE) root = root.parentElement;
    if (!root) return;
    const table = root.closest?.('table.table');
    if (table) tables.add(table);
    root.querySelectorAll('table.table').forEach(table => tables.add(table));
    tables.forEach(enhance);
  }

  function start() {
    scan(document);
    // Covers live filters, polling, HTMX, modal content and progressive navigation.
    new MutationObserver(records => {
      const roots = new Set(records.map(record => record.target));
      roots.forEach(scan);
    }).observe(document.body, { childList: true, subtree: true, characterData: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
