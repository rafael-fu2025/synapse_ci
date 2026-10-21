'use strict';

const { test: nodeTest, before, after } = require('node:test');
const test = (name, run) => nodeTest(name, { timeout: 15_000 }, run);
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { once } = require('node:events');

const root = path.resolve(__dirname, '../..');
const { chromium } = require(path.join(root, 'writable/widget-test-tools/node_modules/playwright'));
const asset = name => path.join(root, 'public/assets/js', name);
let browser, server, origin;
const posts = [];
const held = new Set();

before(async () => {
  let executablePath = chromium.executablePath();
  if (!fs.existsSync(executablePath)) {
    const cache = path.join(os.homedir(), 'AppData/Local/ms-playwright');
    executablePath = fs.existsSync(cache) ? fs.readdirSync(cache)
      .filter(name => /^chromium-\d+$/.test(name))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))
      .map(name => path.join(cache, name, 'chrome-win64/chrome.exe'))
      .find(file => fs.existsSync(file)) : undefined;
    assert.ok(executablePath, 'Install Chromium for the existing browser-test tools');
  }
  browser = await chromium.launch({ executablePath, headless: true, timeout: 30_000 });
  server = http.createServer((req, res) => {
    if (req.method !== 'POST') {
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end('<!doctype html><title>Loading fixture</title>');
      return;
    }
    const chunks = [];
    req.on('data', chunk => chunks.push(chunk));
    req.on('end', () => {
      held.add(res);
      res.on('close', () => held.delete(res));
      posts.push({ url: req.url, body: Buffer.concat(chunks).toString(), res });
    });
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  origin = `http://127.0.0.1:${server.address().port}`;
}, { timeout: 40_000 });

after(async () => {
  await browser?.close();
  for (const res of held) res.destroy();
  if (server?.listening) {
    const closed = once(server, 'close');
    server.close();
    server.closeAllConnections();
    await closed;
  }
});

async function fixture(t, html = '<main id="region"><p>Original content</p></main>', scripts = []) {
  const context = await browser.newContext();
  t.after(() => context.close());
  const page = await context.newPage();
  page.setDefaultTimeout(5000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  t.after(() => assert.deepEqual(errors, [], 'No uncaught browser errors'));
  await page.goto(origin);
  await page.setContent(html);
  for (const name of ['loading.js', ...scripts]) await page.addScriptTag({ path: asset(name) });
  return page;
}

async function loading(page, selector = '#region', count = 1) {
  await page.waitForFunction(({ selector, count }) => {
    const target = document.querySelector(selector);
    return target.querySelectorAll('.app-loading-overlay, .app-loading-status').length === count &&
      (count === 0 || target.getAttribute('aria-busy') === 'true');
  }, { selector, count }, { polling: 20 });
}

test('API adds accessible skeleton, preserves content, and restores absent aria-busy idempotently', async t => {
  const page = await fixture(t);
  assert.deepEqual(await page.evaluate(() => {
    const target = document.querySelector('#region');
    window.original = target.firstElementChild;
    window.finish = SynapseLoading.begin(target, { skeleton: true, label: 'Loading records…' });
    const overlay = target.querySelector('.app-loading-overlay');
    return {
      busy: target.getAttribute('aria-busy'), region: target.classList.contains('app-loading-region'),
      role: overlay.getAttribute('role'), live: overlay.getAttribute('aria-live'),
      label: overlay.textContent, skeletonHidden: overlay.lastElementChild.getAttribute('aria-hidden'),
      bars: overlay.querySelectorAll('.app-loading-skeleton > span').length,
      spinnerHidden: overlay.querySelector('.app-loading-spinner').getAttribute('aria-hidden'),
      retained: target.firstElementChild === original
    };
  }), { busy: 'true', region: true, role: 'status', live: 'polite', label: 'Loading records…',
    skeletonHidden: 'true', bars: 6, spinnerHidden: 'true', retained: true });
  await page.evaluate(() => { finish(); finish(); });
  await loading(page, '#region', 0);
  assert.deepEqual(await page.locator('#region').evaluate(target => ({
    busy: target.getAttribute('aria-busy'), region: target.classList.contains('app-loading-region'),
    retained: target.firstElementChild === window.original
  })), { busy: null, region: false, retained: true });
});

test('overlapping begin replaces the old indicator and stale finish cannot clear the newer request', async t => {
  const page = await fixture(t, '<main id="region" aria-busy="false"></main>');
  await page.evaluate(() => {
    const target = document.querySelector('#region');
    window.oldFinish = SynapseLoading.begin(target, { skeleton: true });
    window.newFinish = SynapseLoading.begin(target, { label: 'New request' });
    oldFinish(); oldFinish();
  });
  await loading(page);
  assert.equal(await page.locator('#region [role=status]').textContent(), 'New request');
  assert.equal(await page.locator('.app-loading-overlay').count(), 0);
  await page.evaluate(() => { newFinish(); oldFinish(); newFinish(); });
  await loading(page, '#region', 0);
  assert.equal(await page.locator('#region').getAttribute('aria-busy'), 'false');
});

test('button label, child identity and handlers, name/value, and previous state survive begin/finish', async t => {
  const page = await fixture(t, '<main id="region"></main><button id="save" name="intent" value="publish" aria-busy="false"><span>Publish</span></button>');
  await page.evaluate(() => {
    window.child = document.querySelector('#save span');
    window.clicks = 0;
    child.addEventListener('click', () => clicks++);
    window.finish = SynapseLoading.begin(document.querySelector('#region'), { button: document.querySelector('#save') });
  });
  const state = () => page.locator('#save').evaluate(button => ({
    name: button.name, value: button.value, label: button.textContent,
    sameChild: button.querySelector('span:last-child') === window.child,
    busy: button.getAttribute('aria-busy'), disabled: button.disabled
  }));
  assert.deepEqual(await state(), { name: 'intent', value: 'publish', label: 'Publish', sameChild: true, busy: 'true', disabled: true });
  await page.evaluate(() => finish());
  assert.deepEqual(await state(), { name: 'intent', value: 'publish', label: 'Publish', sameChild: true, busy: 'false', disabled: false });
  await page.locator('#save span').click();
  assert.equal(await page.evaluate(() => clicks), 1);
  await page.evaluate(() => {
    const button = document.querySelector('#save');
    button.disabled = true;
    SynapseLoading.begin(document.querySelector('#region'), { button })();
  });
  assert.equal(await page.locator('#save').isDisabled(), true);
});

test('button submissions show only the button spinner without an extra status row', async t => {
  const page = await fixture(t, '<form id="region"><button id="save" type="submit">Sign in</button></form>');
  await page.addStyleTag({ path: path.join(root, 'public/assets/vendor/bootstrap/css/bootstrap.min.css') });
  await page.addStyleTag({ path: path.join(root, 'public/assets/css/loading.css') });
  const beforeHeight = await page.locator('#region').evaluate(form => form.getBoundingClientRect().height);
  await page.evaluate(() => {
    window.finish = SynapseLoading.begin(document.querySelector('#region'), {
      label: 'Submitting…', button: document.querySelector('#save'), disableButton: false
    });
  });
  assert.equal(await page.locator('#save > .app-loading-spinner').count(), 1);
  assert.equal(await page.locator('.app-loading-status .app-loading-spinner').count(), 0);
  assert.equal(await page.locator('.app-loading-status').textContent(), 'Submitting…');
  assert.equal(await page.locator('.app-loading-status').getAttribute('role'), 'status');
  assert.equal(await page.locator('.app-loading-status').evaluate(status =>
    status.classList.contains('visually-hidden') && status.getBoundingClientRect().height <= 1), true);
  assert.equal(await page.locator('#region').evaluate(form => form.getBoundingClientRect().height), beforeHeight);
  await page.evaluate(() => finish());
  assert.equal(await page.locator('.app-loading-spinner, .app-loading-status').count(), 0);
});

test('input submitter value is untouched and disableButton false keeps it successful', async t => {
  const page = await fixture(t, '<form id="region"><input id="save" type="submit" name="intent" value="Save changes"></form>');
  assert.deepEqual(await page.evaluate(() => {
    const button = document.querySelector('#save');
    const finish = SynapseLoading.begin(button.form, { button, disableButton: false });
    const during = { value: button.value, disabled: button.disabled, busy: button.getAttribute('aria-busy'),
      submitted: new FormData(button.form, button).get('intent') };
    finish();
    return { during, after: button.getAttribute('aria-busy'), value: button.value };
  }), { during: { value: 'Save changes', disabled: false, busy: 'true', submitted: 'Save changes' }, after: null, value: 'Save changes' });
});

test('scoped swap cleanup leaves unrelated work active; pageshow clears all remaining work', async t => {
  const page = await fixture(t, '<section id="scope"><main id="region"></main></section><aside id="other" aria-busy="true"></aside>');
  await page.evaluate(() => {
    SynapseLoading.begin(document.querySelector('#region'));
    SynapseLoading.begin(document.querySelector('#other'));
    document.dispatchEvent(new CustomEvent('synapse:before-swap', { detail: { root: document.querySelector('#scope') } }));
  });
  await loading(page, '#region', 0);
  await loading(page, '#other');
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
  await loading(page, '#other', 0);
  assert.equal(await page.locator('#other').getAttribute('aria-busy'), 'true');
});

test('real loading CSS disables spinner and skeleton animations under reduced motion', async t => {
  const page = await fixture(t);
  await page.addStyleTag({ path: path.join(root, 'public/assets/css/loading.css') });
  await page.evaluate(() => SynapseLoading.begin(document.querySelector('#region'), { skeleton: true }));
  const animations = () => page.locator('.app-loading-spinner, .app-loading-skeleton > span')
    .evaluateAll(nodes => nodes.map(node => getComputedStyle(node).animationName));
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  assert.deepEqual(await animations(), ['app-loading-spin', ...Array(6).fill('app-loading-pulse')]);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  assert.deepEqual(await animations(), Array(7).fill('none'));
  await loading(page);
});

async function htmxFixture(t) {
  const page = await fixture(t, '<button id="queue" type="button" name="action" value="advance" aria-busy="false"><span>Advance queue</span></button><div id="poll" hx-trigger="every 15s"></div><main id="region" aria-busy="false"><p>Queue contents</p></main>');
  await page.evaluate(() => {
    window.htmxXhrs = [new XMLHttpRequest(), new XMLHttpRequest()];
    window.fireHtmx = (name, index, source = 'queue', successful = true) => {
      const elt = document.getElementById(source);
      return elt.dispatchEvent(new CustomEvent(`htmx:${name}`, {
        bubbles: true, cancelable: name !== 'beforeSend',
        detail: { elt, target: document.querySelector('#region'), xhr: htmxXhrs[index],
          successful, failed: !successful }
      }));
    };
  });
  return page;
}

test('HTMX beforeRequest cancelled by a later listener never starts loading or disables the button', async t => {
  const page = await htmxFixture(t);
  assert.equal(await page.evaluate(() => {
    // Register after loading.js so the old beforeRequest hook would already
    // have started loading before this listener cancels the request.
    document.addEventListener('htmx:beforeRequest', event => event.preventDefault(), { once: true });
    return fireHtmx('beforeRequest', 0);
  }), false, 'The request was cancelled before HTMX could send it');
  await page.evaluate(() => new Promise(resolve => setTimeout(resolve, 0)));
  await loading(page, '#region', 0);
  assert.equal(await page.locator('#region').getAttribute('aria-busy'), 'false');
  assert.equal(await page.locator('#queue').getAttribute('aria-busy'), 'false');
  assert.equal(await page.locator('#queue').isDisabled(), false);
  assert.equal(await page.locator('.app-loading-spinner').count(), 0);
  assert.equal(await page.locator('.app-loading-region, .app-loading-button').count(), 0);
});

for (const successful of [true, false]) {
  test(`HTMX queue button stays busy until afterRequest ${successful ? 'success' : 'failure'} and restores controls`, async t => {
    const page = await htmxFixture(t);
    await page.evaluate(() => fireHtmx('beforeSend', 0));
    await loading(page);
    assert.equal(await page.locator('#region [role=status]').textContent(), 'Updating…');
    assert.equal(await page.locator('#queue').isDisabled(), true);
    assert.equal(await page.locator('#queue').getAttribute('aria-busy'), 'true');
    assert.equal(await page.locator('#queue > .app-loading-spinner').count(), 1);
    assert.equal(await page.locator('#queue').textContent(), 'Advance queue');
    assert.match(await page.locator('#region').textContent(), /Queue contents/);
    // No completion timer should remove an indicator while the XHR is held.
    await page.evaluate(() => new Promise(resolve => setTimeout(resolve, 0)));
    await loading(page);
    await page.evaluate(successful => fireHtmx('afterRequest', 0, 'queue', successful), successful);
    await loading(page, '#region', 0);
    assert.equal(await page.locator('#region').getAttribute('aria-busy'), 'false');
    assert.equal(await page.locator('#queue').getAttribute('aria-busy'), 'false');
    assert.equal(await page.locator('#queue').isDisabled(), false);
    assert.equal(await page.locator('#queue > .app-loading-spinner').count(), 0);
    assert.deepEqual(await page.locator('#queue').evaluate(button => ({ name: button.name, value: button.value })),
      { name: 'action', value: 'advance' });
    await page.evaluate(() => fireHtmx('afterRequest', 0));
    await loading(page, '#region', 0);
  });
}

test('superseded HTMX XHR completion cannot clear a newer indicator on the same target', async t => {
  const page = await htmxFixture(t);
  await page.evaluate(() => {
    fireHtmx('beforeSend', 0);
    window.oldHtmxIndicator = document.querySelector('#region .app-loading-status');
    fireHtmx('beforeSend', 1);
    window.newHtmxIndicator = document.querySelector('#region .app-loading-status');
  });
  await loading(page);
  assert.equal(await page.evaluate(() => !oldHtmxIndicator.isConnected && newHtmxIndicator.isConnected), true);
  await page.evaluate(() => {
    fireHtmx('afterRequest', 0, 'queue', false);
    fireHtmx('afterRequest', 0);
  });
  await loading(page);
  assert.equal(await page.evaluate(() => newHtmxIndicator.isConnected), true);
  assert.equal(await page.locator('#queue').isDisabled(), true);
  assert.equal(await page.locator('#queue').getAttribute('aria-busy'), 'true');
  await page.evaluate(() => fireHtmx('afterRequest', 1));
  await loading(page, '#region', 0);
  assert.equal(await page.locator('#queue').isDisabled(), false);
  assert.equal(await page.locator('#queue').getAttribute('aria-busy'), 'false');
});

test('HTMX polling div is quiet and its completion does not clear an actionable request', async t => {
  const page = await htmxFixture(t);
  await page.evaluate(() => fireHtmx('beforeSend', 0, 'poll'));
  await loading(page, '#region', 0);
  assert.equal(await page.locator('#region').getAttribute('aria-busy'), 'false');
  assert.equal(await page.locator('#poll').getAttribute('aria-busy'), null);
  assert.equal(await page.locator('.app-loading-spinner').count(), 0);
  await page.evaluate(() => {
    fireHtmx('beforeSend', 1);
    fireHtmx('afterRequest', 0, 'poll');
  });
  await loading(page);
  assert.equal(await page.locator('#queue').isDisabled(), true);
  await page.evaluate(() => fireHtmx('afterRequest', 1));
  await loading(page, '#region', 0);
});

async function waitForPosts(count) {
  const deadline = Date.now() + 5000;
  while (posts.length < count && Date.now() < deadline) {
    await new Promise(resolve => setTimeout(resolve, 10));
  }
  assert.equal(posts.length, count, 'Expected native POST count');
}

const nativeForm = '<form id=region method=post action=/submit><input name=record value=42><button id=save name=intent value=publish><span>Publish</span></button></form>';

test('real native POST preserves submitter, remains busy, and blocks duplicate submission', async t => {
  const page = await fixture(t, nativeForm, ['form-loading.js']);
  const start = posts.length;
  let resolveReport;
  const reported = new Promise(resolve => { resolveReport = resolve; });
  await page.exposeFunction('reportSubmission', resolveReport);
  const request = page.waitForRequest(req => req.method() === 'POST');
  // Inspect after the native handler's timer: Chromium may suspend external
  // evaluations while navigation is waiting for response headers.
  await page.evaluate(() => {
    const form = document.querySelector('#region');
    const button = document.querySelector('#save');
    window.addEventListener('submit', event => {
      const trusted = event.isTrusted;
      setTimeout(() => {
      let prevented;
      window.addEventListener('submit', event => { prevented = event.defaultPrevented; }, { once: true });
      form.requestSubmit(button);
      reportSubmission({ busy: form.getAttribute('aria-busy'),
        indicators: form.querySelectorAll('.app-loading-status').length,
        buttonBusy: button.getAttribute('aria-busy'), disabled: button.disabled,
        label: button.textContent, duplicatePrevented: prevented, trusted });
      }, 0);
    }, { once: true });
  });
  await page.click('#save', { noWaitAfter: true });
  await request;
  await waitForPosts(start + 1);
  assert.deepEqual(await reported, { busy: 'true', indicators: 1, buttonBusy: 'true',
    disabled: false, label: 'Publish', duplicatePrevented: true, trusted: true });
  assert.equal(posts[start].url, '/submit');
  assert.deepEqual(Object.fromEntries(new URLSearchParams(posts[start].body)), { record: '42', intent: 'publish' });
  assert.equal(posts[start].res.writableEnded, false, 'Response is still held with the old busy document');
  const navigated = page.waitForURL(origin + '/submit');
  posts[start].res.writeHead(200, { 'Content-Type': 'text/html' });
  posts[start].res.end('<!doctype html><p id=complete>Submitted</p>');
  await navigated;
  await page.locator('#complete').waitFor();
});

test('pageshow clears native pending state and restores controls', async t => {
  const page = await fixture(t, nativeForm, ['form-loading.js']);
  // Dispatch exercises the native handler without a navigation consuming the
  // document before the simulated BFCache restoration can be inspected.
  const submit = () => page.evaluate(() => {
    const form = document.querySelector('#region');
    return form.dispatchEvent(new SubmitEvent('submit', {
      bubbles: true, cancelable: true, submitter: document.querySelector('#save')
    }));
  });
  assert.equal(await submit(), true);
  await loading(page);
  assert.equal(await submit(), false, 'Pending guard is armed');
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
  await loading(page, '#region', 0);
  assert.equal(await page.locator('#save').getAttribute('aria-busy'), null);
  assert.equal(await page.locator('#save').isDisabled(), false);
  assert.equal(await submit(), true, 'Restored form accepts another submission');
  await loading(page);
});

test('prevented AJAX submissions, including later window listeners, never get a generic indicator', async t => {
  const page = await fixture(t, nativeForm, ['form-loading.js']);
  const start = posts.length;
  for (const scope of ['form', 'window']) {
    await page.evaluate(scope => {
      const form = document.querySelector('#region');
      const target = scope === 'form' ? form : window;
      target.addEventListener('submit', event => event.preventDefault(), { once: true });
      form.requestSubmit(document.querySelector('#save'));
    }, scope);
    await loading(page, '#region', 0);
    assert.equal(await page.locator('#region').getAttribute('aria-busy'), null);
    assert.equal(await page.locator('#save').getAttribute('aria-busy'), null);
  }
  assert.equal(posts.length, start);
});

for (const scope of ['document', 'window']) {
  test(`real submit click lets a later ${scope} AJAX owner claim loading before the modal busy guard`, async t => {
    const page = await fixture(t, nativeForm, ['form-loading.js']);
    const start = posts.length;
    const initialUrl = page.url();
    await page.evaluate(scope => {
      window.ajaxState = { started: 0, blocked: 0, trusted: false, labels: [] };
      const begin = SynapseLoading.begin;
      SynapseLoading.begin = (target, options) => {
        ajaxState.labels.push(options?.label);
        return begin(target, options);
      };
      const owner = scope === 'document' ? document : window;
      owner.addEventListener('submit', event => {
        event.preventDefault();
        const form = event.target;
        ajaxState.trusted = event.isTrusted;
        // Match modal AJAX owners: an already-busy form must not save twice.
        if (form.getAttribute('aria-busy') === 'true') {
          ajaxState.blocked++;
          return;
        }
        ajaxState.started++;
        const finish = SynapseLoading.begin(form, { label: 'Saving…', button: event.submitter });
        window.savingComplete = new Promise(resolve => { window.releaseSaving = resolve; }).then(finish);
      });
    }, scope);

    // Browser-dispatched events can run microtasks between propagation listeners;
    // requestSubmit/dispatchEvent inside evaluate would hide this regression.
    await page.click('#save');
    await page.evaluate(() => new Promise(resolve => setTimeout(resolve, 0)));
    assert.deepEqual(await page.evaluate(() => ajaxState), {
      started: 1, blocked: 0, trusted: true, labels: ['Saving…']
    });
    await loading(page);
    assert.equal(await page.locator('#region [role=status]').textContent(), 'Saving…');
    assert.equal(await page.locator('#save').getAttribute('aria-busy'), 'true');
    assert.equal(await page.locator('#save').isDisabled(), true);
    assert.equal(posts.length, start, 'Claimed AJAX submission must not make a native POST');
    assert.equal(page.url(), initialUrl, 'Claimed AJAX submission must not navigate');

    await page.evaluate(async () => { releaseSaving(); await savingComplete; });
    await loading(page, '#region', 0);
    assert.equal(await page.locator('#region').getAttribute('aria-busy'), null);
    assert.equal(await page.locator('#save').isDisabled(), false);
    assert.deepEqual(await page.evaluate(() => ajaxState.labels), ['Saving…'], 'No native fallback began');
  });
}

const pagination = '<div data-table-pagination data-page="1" data-per-page="25" data-filtered="1" data-total="1"></div>';
const fragment = label => `<section data-table-content><p>${label}</p>${pagination}</section>`;
async function tableFixture(t) {
  const page = await fixture(t, `<section data-live-table data-endpoint="/table">
    <form data-table-filters><input name="q"><button>Search</button></form>
    <div id="region" data-table-results><p>Original results</p>${pagination}</div>
    <p data-table-status></p><div data-table-error hidden><span data-table-error-message></span></div>
  </section>`, ['form-loading.js']);
  // Separate response and body gates exercise the entire await response.text/json lifecycle.
  await page.evaluate(() => {
    window.requests = [];
    window.fetch = (url, options) => new Promise((resolve, reject) => {
      requests.push({ url: String(url), signal: options.signal, resolve, reject });
    });
    window.headersArrived = (index, status = 200, type = 'text/html') => {
      const request = requests[index];
      request.resolve({ ok: status < 400, status, redirected: false,
        headers: new Headers({ 'Content-Type': type }),
        text: () => new Promise(resolve => { request.body = resolve; }),
        json: () => new Promise(resolve => { request.body = resolve; }) });
    };
  });
  await page.addScriptTag({ path: asset('live-table.js') });
  return page;
}
async function search(page, count) {
  await page.evaluate(() => document.querySelector('[data-table-filters]').requestSubmit());
  await page.waitForFunction(count => requests.length === count, count);
  await loading(page);
  assert.equal(await page.locator('.app-loading-status').count(), 0, 'AJAX form has no generic submitting indicator');
}
async function headers(page, index, status = 200, type = 'text/html') {
  await page.evaluate(({ index, status, type }) => headersArrived(index, status, type), { index, status, type });
  await page.waitForFunction(index => typeof requests[index].body === 'function', index);
  await loading(page);
}
async function settled(page) {
  await loading(page, '#region', 0);
  assert.equal(await page.locator('#region').getAttribute('aria-busy'), 'false');
  assert.equal(await page.locator('#region').evaluate(el => el.classList.contains('app-loading-region')), false);
}

test('live-table skeleton lasts through held response body and clears on successful replacement', async t => {
  const page = await tableFixture(t);
  await search(page, 1);
  await headers(page, 0);
  assert.match(await page.locator('#region').textContent(), /Original results/);
  assert.equal(await page.locator('.app-loading-skeleton > span').count(), 6);
  await page.evaluate(html => requests[0].body(html), fragment('Fresh results'));
  await settled(page);
  assert.match(await page.locator('#region').textContent(), /Fresh results/);
  assert.equal(await page.locator('[data-table-error]').isVisible(), false);
  assert.match(await page.locator('[data-table-status]').textContent(), /1.*1.*1 matching records/);
});

test('live-table clears loader after network failure, malformed body, and held JSON error body', async t => {
  const page = await tableFixture(t);
  await search(page, 1);
  await page.evaluate(() => requests[0].reject(new TypeError('Network unavailable')));
  await settled(page);
  assert.equal(await page.locator('[data-table-error-message]').textContent(), 'Network unavailable');
  await search(page, 2);
  await headers(page, 1);
  await page.evaluate(() => requests[1].body('<section data-table-content>Missing metadata</section>'));
  await settled(page);
  assert.match(await page.locator('[data-table-error-message]').textContent(), /incomplete/);
  await search(page, 3);
  await headers(page, 2, 503, 'application/json');
  await page.evaluate(() => requests[2].body({ message: 'Try again later' }));
  await settled(page);
  assert.equal(await page.locator('[data-table-error-message]').textContent(), 'Try again later');
  assert.match(await page.locator('#region').textContent(), /Original results/);
});

test('superseded live-table body cannot replace results or clear the newer loader; navigation cleans up', async t => {
  const page = await tableFixture(t);
  await search(page, 1);
  await headers(page, 0);
  await page.evaluate(() => { window.oldOverlay = document.querySelector('.app-loading-overlay'); });
  await search(page, 2);
  assert.deepEqual(await page.evaluate(() => ({ aborted: requests[0].signal.aborted, removed: !oldOverlay.isConnected })),
    { aborted: true, removed: true });
  await headers(page, 1);
  await page.evaluate(html => requests[0].body(html), fragment('Stale results'));
  await loading(page);
  assert.doesNotMatch(await page.locator('#region').textContent(), /Stale results/);
  await page.evaluate(html => requests[1].body(html), fragment('Newest results'));
  await settled(page);
  assert.match(await page.locator('#region').textContent(), /Newest results/);
  await search(page, 3);
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('synapse:before-navigate')));
  await settled(page);
  assert.equal(await page.evaluate(() => requests[2].signal.aborted), true);
});
