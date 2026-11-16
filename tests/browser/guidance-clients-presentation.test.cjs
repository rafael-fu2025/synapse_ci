'use strict';

const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const { createFixtureServer } = require('../_support/guidance-clients-fixture-server.cjs');

let fixture;
let origin;
before(async () => { fixture = createFixtureServer(); origin = await fixture.start(); });
after(async () => { await fixture?.close(); });

function render(recordPath) {
  const result = spawnSync('php', [path.resolve(__dirname, '../_support/render-guidance-clients-fixture.php')], {
    cwd: path.resolve(__dirname, '../..'), input: JSON.stringify({ origin, path: recordPath }), encoding: 'utf8', timeout: 15000,
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  return result.stdout;
}

test('Guidance client directory renders shared shell, neutral links, and read-only fragments', () => {
  const html = render('/guidance/clients');
  assert.match(html, /Guidance Clients &amp; Records/);
  assert.match(html, /data-live-table/);
  assert.match(html, /data-refresh-owner="guidance.clients"/);
  assert.match(html, /data-native-select/);
  assert.match(html, /guidance\/clients\/1/);
  assert.doesNotMatch(html, /Blood Type|Clinic Visits|Start Visit/);
  const fragment = render('/guidance/clients?q=SYNTH-001&fragment=1');
  assert.match(fragment, /^<div data-table-content>/);
  assert.match(fragment, /SYNTH-001/);
  assert.doesNotMatch(fragment, /SYNTH-002|<script|<!DOCTYPE|<form/);
});

test('all seven record sections and the legacy cumulative URL render without PHP warnings', () => {
  const sections = {
    overview: /Client profile/, cases: /Authorized counseling cases/, sessions: /Session chronology/,
    assessments: /Multiple Intelligence history/, coordination: /Intake referrals/,
    forms: /Counseling Contact Sheet/, appointments: /Shared scheduling appointments/,
  };
  for (const [section, expected] of Object.entries(sections)) {
    const html = render('/guidance/clients/1?tab=' + section);
    assert.match(html, expected, section);
    assert.match(html, /data-refresh-owner="guidance.client.1"/);
    assert.match(html, /aria-current="page"/);
    assert.doesNotMatch(html, /Warning:|Fatal error:|Blood Type|Vital Trends|Lab Results/);
  }
  assert.match(render('/guidance/students/1/cumulative?tab=cases'), /Authorized counseling cases/);
  const employee = render('/guidance/clients/2');
  assert.match(employee, /Employee/);
  assert.match(employee, /Campus Services/);
});

test('synthetic fixture serves no-store GET pages and never accepts writes', async () => {
  const directory = await fetch(origin + '/guidance/clients');
  assert.equal(directory.status, 200);
  assert.equal(directory.headers.get('cache-control'), 'private, no-store');
  const fragment = await fetch(origin + '/guidance/clients?kind=employee&fragment=1');
  assert.equal(fragment.status, 200);
  const text = await fragment.text();
  assert.match(text, /SYNTH-002/);
  assert.doesNotMatch(text, /SYNTH-001/);
  const write = await fetch(origin + '/guidance/clients', { method: 'POST', body: 'synthetic=1' });
  assert.equal(write.status, 405);
});
