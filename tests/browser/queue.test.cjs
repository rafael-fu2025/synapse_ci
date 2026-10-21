'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const view = fs.readFileSync(path.join(__dirname, '../../app/Views/queue/index.php'), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/)[1];

function fixture() {
  const handlers = new Map();
  const notice = { hidden: true, textContent: '' };
  const document = {
    addEventListener: (name, handler) => handlers.set(name, handler),
    getElementById: id => id === 'queue-action-error' ? notice : null,
  };
  vm.runInNewContext(script, { document });
  return { notice, fire: (name, detail) => handlers.get(name)({ detail }) };
}

test('stale queue actions display JSON errors as text without replacing the board', () => {
  const { notice, fire } = fixture();
  const message = '<img src=x onerror=alert(1)> Queue ticket changed.';
  fire('htmx:responseError', {
    target: { id: 'queue-board-container' }, xhr: { responseText: JSON.stringify({ error: message }) },
  });
  assert.equal(notice.hidden, false);
  assert.equal(notice.textContent, message);
  assert.equal(Object.hasOwn(notice, 'innerHTML'), false);
});

test('unrelated errors are ignored and malformed responses use a safe fallback', () => {
  const { notice, fire } = fixture();
  fire('htmx:responseError', { target: { id: 'another-panel' } });
  assert.equal(notice.hidden, true);
  fire('htmx:responseError', { target: { id: 'queue-board-container' }, xhr: { responseText: '<html>Error</html>' } });
  assert.equal(notice.hidden, false);
  assert.equal(notice.textContent, 'Queue action failed. Reload the board before trying again.');
});

test('polling does not dismiss a conflict, but a successful queue mutation does', () => {
  const { notice, fire } = fixture();
  notice.hidden = false;
  const target = { id: 'queue-board-container' };
  fire('htmx:afterRequest', { target, successful: true, requestConfig: { verb: 'get' } });
  assert.equal(notice.hidden, false);
  fire('htmx:afterRequest', { target, successful: false, requestConfig: { verb: 'post' } });
  assert.equal(notice.hidden, false);
  fire('htmx:afterRequest', { target, successful: true, requestConfig: { verb: 'post' } });
  assert.equal(notice.hidden, true);
});

test('call-next and board polling include the selected queue date', () => {
  assert.match(view, /queue\/call-next[\s\S]*?hx-include="#queueDateFilter_iso"/);
  const markup = view.replace(/<\?[\s\S]*?\?>/g, '');
  assert.match(markup, /id="queue-board-container"[^>]*hx-include="#queueDateFilter_iso"/);
  assert.match(view, /id="queue-action-error"[^>]*role="alert"[^>]*hidden/);
});
