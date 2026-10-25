'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const tabs = [
  ['Team board', 'bi-kanban'],
  ['My tasks', 'bi-list-check'],
  ['Team chat', 'bi-chat-dots'],
  ['Team calendar', 'bi-calendar3']
];

for (const view of ['index', 'chat', 'calendar']) {
  test(`${view}: Teams tabs keep consistent icons, spacing, order and accessible labels`, () => {
    const source = fs.readFileSync(path.join(__dirname, `../../app/Views/teams/${view}.php`), 'utf8');
    const nav = source.match(/<nav\b[^>]*aria-label="Teams views"[^>]*>([\s\S]*?)<\/nav>/);
    assert.ok(nav, 'The Teams views navigation must exist');
    const markup = nav[1].replace(/<\?[\s\S]*?\?>/g, '');
    const links = [...markup.matchAll(/<a\b[^>]*>([\s\S]*?)<\/a>/g)].slice(0, tabs.length);
    assert.equal(links.length, tabs.length);
    tabs.forEach(([label, icon], index) => {
      const content = links[index][1];
      assert.equal(content.replace(/<[^>]*>/g, '').trim(), label);
      const icons = [...content.matchAll(/<i\b([^>]*)><\/i>/g)];
      assert.equal(icons.length, 1, `${label} needs one leading icon`);
      assert.match(icons[0][1], new RegExp(`class="bi ${icon} me-2"`));
      assert.match(icons[0][1], /aria-hidden="true"/);
    });
  });
}
