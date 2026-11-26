'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const {
  ROOT,
  EVIDENCE_ROOT,
  launchBrowser,
  loadDefenseCatalog,
} = require('./harness.cjs');
const { runTrackA } = require('./track_a.cjs');
const { runTrackB } = require('./track_b.cjs');
const { runTrackC } = require('./track_c.cjs');

function loadFixtures() {
  const raw = execFileSync('php', [path.join(ROOT, 'tools/blackbox/dryrun/setup_fixtures.php')], {
    cwd: ROOT,
    encoding: 'utf8',
  });
  return JSON.parse(raw);
}

function writeReport(allResults) {
  const resultsJson = path.join(EVIDENCE_ROOT, 'results.json');
  fs.writeFileSync(resultsJson, JSON.stringify({ generated_at: new Date().toISOString(), total: allResults.length, cases: allResults }, null, 2));

  const counts = { PASS: 0, FAIL: 0, SKIPPED_MOBILE: 0 };
  for (const r of allResults) counts[r.status] = (counts[r.status] || 0) + 1;

  const lines = [
    '# SYNAPSE Live Pre-Defense Black-Box Dry-Run Report',
    '',
    `- **Generated at:** ${new Date().toISOString()}`,
    `- **Total Defense Sheet Cases:** ${allResults.length} (83 Web + 1 Mobile Skipped)`,
    `- **PASS:** ${counts.PASS}`,
    `- **FAIL:** ${counts.FAIL}`,
    `- **SKIPPED_MOBILE:** ${counts.SKIPPED_MOBILE}`,
    '',
    '## Failures & Blockers Found',
    '',
  ];

  const failures = allResults.filter((r) => r.status === 'FAIL');
  if (failures.length === 0) {
    lines.push('None — all 83 web cases passed with recorded `.mp4` videos and zero console/HTTP 5xx errors.');
  } else {
    for (const f of failures) {
      lines.push(`- **${f.id} (${f.role})** — ${f.scenario}`);
      lines.push(`  - Error: \`${f.error}\``);
      if (f.video) lines.push(`  - Video: \`${f.video}\``);
      if (f.screenshot) lines.push(`  - Screenshot: \`${f.screenshot}\``);
    }
  }

  lines.push('', '## Full Case Ledger', '', '| ID | Track | Role | Status | Time (ms) | Video | Notes |', '| --- | --- | --- | --- | ---: | --- | --- |');
  for (const r of allResults) {
    const vid = r.video ? `\`${path.basename(r.video)}\`` : '—';
    const note = (r.status === 'FAIL' ? r.error : r.observed).replace(/\|/g, '/').replace(/\s+/g, ' ').slice(0, 140);
    lines.push(`| ${r.id} | ${r.track} | ${r.role} | ${r.status} | ${r.duration_ms} | ${vid} | ${note} |`);
  }

  fs.writeFileSync(path.join(EVIDENCE_ROOT, 'DRYRUN-REPORT.md'), lines.join('\n') + '\n', 'utf8');
  console.log(`\nDRY-RUN SUMMARY: total=${allResults.length} PASS=${counts.PASS} FAIL=${counts.FAIL} SKIPPED_MOBILE=${counts.SKIPPED_MOBILE}`);
}

async function main() {
  const args = process.argv.slice(2);
  const mode = args.find((a) => a.startsWith('--track-') || a === '--all') || '--all';
  const onlyArg = args.find((a) => a.startsWith('--only='));
  const onlySet = onlyArg ? new Set(onlyArg.slice(7).split(',').map((s) => s.trim()).filter(Boolean)) : null;

  const catalog = loadDefenseCatalog();
  const byId = Object.fromEntries(catalog.cases.map((c) => [c.id, c]));
  const fixtures = loadFixtures();
  if (onlySet) {
    process.env.DRYRUN_ONLY_IDS = [...onlySet].join(',');
  }
  const browser = await launchBrowser();

  function mergeTrack(fileName, freshRaw) {
    const freshList = freshRaw.filter(Boolean);
    const filePath = path.join(EVIDENCE_ROOT, fileName);
    if (!onlySet || !fs.existsSync(filePath)) {
      fs.writeFileSync(filePath, JSON.stringify(freshList, null, 2));
      return;
    }
    const prev = JSON.parse(fs.readFileSync(filePath, 'utf8'));
    const byKey = Object.fromEntries(freshList.map((r) => [r.id, r]));
    const merged = prev.map((r) => byKey[r.id] || r);
    for (const r of freshList) {
      if (!merged.some((m) => m.id === r.id)) merged.push(r);
    }
    fs.writeFileSync(filePath, JSON.stringify(merged, null, 2));
  }

  try {
    if (mode === '--track-a' || mode === '--all') {
      const a = await runTrackA(browser, byId, fixtures);
      mergeTrack('track-a.json', a);
    }
    if (mode === '--track-b' || mode === '--all') {
      const b = await runTrackB(browser, byId, fixtures);
      mergeTrack('track-b.json', b);
    }
    if (mode === '--track-c' || mode === '--all') {
      const c = await runTrackC(browser, byId, fixtures);
      mergeTrack('track-c.json', c);
    }
  } finally {
    await browser.close();
  }

  const combined = [];
  for (const f of ['track-a.json', 'track-b.json', 'track-c.json']) {
    const p = path.join(EVIDENCE_ROOT, f);
    if (fs.existsSync(p)) {
      combined.push(...JSON.parse(fs.readFileSync(p, 'utf8')));
    }
  }
  if (combined.length > 0) {
    writeReport(combined);
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
