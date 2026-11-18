#!/usr/bin/env node

import { createHash } from "node:crypto";
import {
  constants as fsConstants,
  lstat,
  open,
} from "node:fs/promises";
import path from "node:path";
import { spawn } from "node:child_process";
import { fileURLToPath } from "node:url";
import { Renderer, marked } from "marked";

const PINNED_MARKED_VERSION = "18.2.1";
const REQUIRED_MARKDOWN_PATH = "papers/blackbox/SYNAPSE-BlackBox-Testing.md";
const REQUIRED_HTML_PATH = "papers/blackbox/SYNAPSE-BlackBox-Testing.html";
const SCRIPT_DIR = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_ROOT = path.resolve(SCRIPT_DIR, "../..");
const MAX_JSON_BYTES = 4 * 1024 * 1024;
const MAX_MARKDOWN_BYTES = 32 * 1024 * 1024;
const MAX_JSON_DEPTH = 64;
const WINDOWS_DEVICE_NAMES = new Set([
  "CON", "PRN", "AUX", "NUL",
  ...Array.from({ length: 9 }, (_, index) => `COM${index + 1}`),
  ...Array.from({ length: 9 }, (_, index) => `LPT${index + 1}`),
]);
const PYTHON_CANDIDATES = [
  process.env.PYTHON,
  process.env.PYTHON_EXECUTABLE,
  process.platform === "win32" && process.env.LOCALAPPDATA
    ? path.join(process.env.LOCALAPPDATA, "Programs", "Python", "Python312", "python.exe")
    : null,
  process.platform === "win32" ? "py.exe" : null,
  "python3",
  "python",
].filter(Boolean);
let resolvedPython = null;

function parseArguments(argv) {
  let root = DEFAULT_ROOT;
  let check = false;
  let results = null;
  for (let index = 0; index < argv.length; index += 1) {
    const value = argv[index];
    if (value === "--check") {
      check = true;
    } else if (value === "--root") {
      index += 1;
      if (index >= argv.length) {
        throw new Error("--root requires a path");
      }
      root = path.resolve(argv[index]);
    } else if (value === "--results") {
      index += 1;
      if (index >= argv.length) {
        throw new Error("--results requires a path");
      }
      results = path.resolve(argv[index]);
    } else if (value === "--help" || value === "-h") {
      process.stdout.write(
        "Usage: node render-html.mjs [--root PATH] [--results PATH] [--check]\n" +
          "Validate freshness, then render generated black-box Markdown as deterministic standalone HTML.\n",
      );
      return null;
    } else {
      throw new Error(`unknown argument: ${value}`);
    }
  }
  return { root, check, results };
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function sha256(buffer) {
  return createHash("sha256").update(buffer).digest("hex");
}

function resolveRepositoryPath(root, rawPath, label) {
  if (typeof rawPath !== "string" || rawPath === "" || rawPath !== rawPath.trim()) {
    throw new Error(`${label} must be a non-empty repository-relative path without outer whitespace`);
  }
  if (rawPath.includes("\\")) {
    throw new Error(`${label} must use forward slashes only`);
  }
  const parts = rawPath.split("/");
  if (
    rawPath.startsWith("/") ||
    /^[A-Za-z]:/u.test(rawPath) ||
    rawPath.includes(":") ||
    /[\u0000-\u001f\u007f]/u.test(rawPath) ||
    parts.some((part) => part === "" || part === "." || part === "..") ||
    parts.some((part) => /[ .]$/u.test(part)) ||
    parts.some((part) => WINDOWS_DEVICE_NAMES.has(part.split(".", 1)[0].toUpperCase()))
  ) {
    throw new Error(`${label} is unsafe: ${rawPath}`);
  }
  const rootPath = path.resolve(root);
  const resolved = path.resolve(rootPath, ...parts);
  const relative = path.relative(rootPath, resolved);
  if (relative.startsWith("..") || path.isAbsolute(relative)) {
    throw new Error(`${label} escapes the repository root: ${rawPath}`);
  }
  return resolved;
}

function checkJsonDepth(value) {
  const stack = [[value, 1]];
  while (stack.length > 0) {
    const [node, depth] = stack.pop();
    if (depth > MAX_JSON_DEPTH) {
      throw new Error(`JSON nesting exceeds the depth limit of ${MAX_JSON_DEPTH}`);
    }
    if (Array.isArray(node)) {
      for (const child of node) stack.push([child, depth + 1]);
    } else if (node !== null && typeof node === "object") {
      for (const child of Object.values(node)) stack.push([child, depth + 1]);
    }
  }
}

async function strictJsonParse(buffer, label) {
  if (!Buffer.isBuffer(buffer)) {
    throw new Error(`${label} parser requires a byte buffer`);
  }
  if (buffer.length > MAX_JSON_BYTES) {
    throw new Error(`${label} exceeds the ${MAX_JSON_BYTES}-byte limit`);
  }
  const helper = path.join(SCRIPT_DIR, "secure_io.py");
  const normalized = await runPython(
    [
      helper,
      "strict-json-normalize",
      "--label", label,
      "--max-bytes", String(MAX_JSON_BYTES),
      "--max-depth", String(MAX_JSON_DEPTH),
    ],
    `${label} strict JSON parser`,
    buffer,
  );
  const value = JSON.parse(normalized.toString("utf8"));
  checkJsonDepth(value);
  return value;
}

async function verifyDirectoryChain(root, directory, label) {
  const rootPath = path.resolve(root);
  const directoryPath = path.resolve(directory);
  const relative = path.relative(rootPath, directoryPath);
  if (relative.startsWith("..") || path.isAbsolute(relative)) {
    throw new Error(`${label} escapes the repository root`);
  }
  const anchor = path.parse(rootPath).root;
  const rootFromAnchor = path.relative(anchor, rootPath);
  let current = anchor;
  const components = [
    ...rootFromAnchor.split(path.sep).filter(Boolean),
    ...relative.split(path.sep).filter(Boolean),
  ];
  for (const component of components) {
    current = path.join(current, component);
    const info = await lstat(current);
    if (info.isSymbolicLink() || !info.isDirectory()) {
      throw new Error(`${label} has a symlink or non-directory component: ${current}`);
    }
  }
}

async function readSafeRegularFile(root, filePath, label, maximumBytes) {
  await verifyDirectoryChain(root, path.dirname(filePath), label);
  const before = await lstat(filePath);
  if (before.isSymbolicLink() || !before.isFile() || before.nlink !== 1) {
    throw new Error(`${label} must be a single-link regular file`);
  }
  if (before.size <= 0 || before.size > maximumBytes) {
    throw new Error(`${label} must be nonempty and at most ${maximumBytes} bytes`);
  }
  const noFollow = fsConstants.O_NOFOLLOW || 0;
  const handle = await open(filePath, fsConstants.O_RDONLY | noFollow);
  try {
    const opened = await handle.stat();
    if (!opened.isFile() || opened.nlink !== 1 || opened.dev !== before.dev || opened.ino !== before.ino) {
      throw new Error(`${label} changed while being opened`);
    }
    const buffer = await handle.readFile();
    const after = await handle.stat();
    const current = await lstat(filePath);
    if (
      buffer.length !== opened.size ||
      after.dev !== opened.dev || after.ino !== opened.ino || after.size !== opened.size ||
      current.dev !== opened.dev || current.ino !== opened.ino || current.isSymbolicLink()
    ) {
      throw new Error(`${label} changed while being read`);
    }
    return buffer;
  } finally {
    await handle.close();
  }
}

function spawnPython(executable, argumentsList, input) {
  return new Promise((resolve, reject) => {
    const child = spawn(executable, argumentsList, {
      cwd: SCRIPT_DIR,
      env: {
        PATH: process.env.PATH || process.env.Path || "",
        Path: process.env.Path || process.env.PATH || "",
        PATHEXT: process.env.PATHEXT || ".COM;.EXE;.BAT;.CMD",
        SYSTEMROOT: process.env.SYSTEMROOT || process.env.SystemRoot || "",
        SystemRoot: process.env.SystemRoot || process.env.SYSTEMROOT || "",
        WINDIR: process.env.WINDIR || "",
        COMSPEC: process.env.COMSPEC || process.env.ComSpec || "",
        ComSpec: process.env.ComSpec || process.env.COMSPEC || "",
      },
      shell: false,
      stdio: [input === null ? "ignore" : "pipe", "pipe", "pipe"],
      windowsHide: true,
    });
    const output = [];
    const errors = [];
    child.stdout.on("data", (chunk) => output.push(chunk));
    child.stderr.on("data", (chunk) => errors.push(chunk));
    child.on("error", (error) => reject(error));
    child.on("close", (code, signal) => resolve({
      code,
      signal,
      stdout: Buffer.concat(output),
      stderr: Buffer.concat(errors),
    }));
    if (input !== null) child.stdin.end(input);
  });
}

async function runPython(argumentsList, label, input = null) {
  let lastError = null;
  const candidates = resolvedPython ? [resolvedPython] : PYTHON_CANDIDATES;
  for (const executable of candidates) {
    try {
      const result = await spawnPython(executable, argumentsList, input);
      resolvedPython = executable;
      if (result.code !== 0) {
        const detail = Buffer.concat([result.stdout, result.stderr]).toString("utf8").trim();
        throw new Error(`${label} failed${result.signal ? ` with signal ${result.signal}` : ` with exit ${result.code}`}${detail ? `: ${detail}` : ""}`);
      }
      return result.stdout;
    } catch (error) {
      if (error && error.code === "ENOENT" && !resolvedPython) {
        lastError = error;
        continue;
      }
      throw error instanceof Error ? error : new Error(String(error));
    }
  }
  throw new Error(`${label} could not start a Python 3 interpreter: ${lastError instanceof Error ? lastError.message : "not found"}`);
}

async function requireFreshValidatedMarkdown(options) {
  const common = [path.join(SCRIPT_DIR, "validate.py"), "--root", options.root];
  if (options.results) common.push("--results", options.results);
  await runPython(common, "black-box validation");
  const buildArguments = [path.join(SCRIPT_DIR, "build.py"), "--root", options.root];
  if (options.results) buildArguments.push("--results", options.results);
  buildArguments.push("--check");
  await runPython(buildArguments, "deterministic Markdown freshness check");
}

async function atomicWriteViaPython(root, destination, payload) {
  const helper = path.join(SCRIPT_DIR, "secure_io.py");
  await runPython(
    [
      helper,
      "atomic-write",
      "--root", root,
      "--destination", destination,
      "--sha256", sha256(payload),
      "--max-bytes", String(Math.max(payload.length, 1)),
    ],
    "secure HTML atomic write",
    payload,
  );
}

function assertSafeRenderedArticle(article) {
  const forbiddenTags = /<(?:script|style|iframe|frame|object|embed|link|base|form|input|textarea|select|button|video|audio|source)\b/iu;
  const eventHandler = /\son[a-z]+\s*=/iu;
  const sourceAttribute = /\s(?:src|srcset)\s*=/iu;
  const dangerousUrlAttribute = /\s(?:href|action|cite|poster|data|formaction|xlink:href)\s*=\s*["']?\s*(?:javascript|vbscript)\s*:/iu;
  if (forbiddenTags.test(article)) {
    throw new Error("Markdown rendered a forbidden active or asset-bearing HTML element");
  }
  if (eventHandler.test(article)) {
    throw new Error("Markdown rendered an inline event-handler attribute");
  }
  if (sourceAttribute.test(article)) {
    throw new Error("Markdown rendered an asset source; standalone output permits no external assets");
  }
  if (dangerousUrlAttribute.test(article)) {
    throw new Error("Markdown rendered a dangerous URL scheme in an HTML attribute");
  }
}

function countCaseVerdicts(markdown) {
  const verdicts = new Map([
    ["NOT_RUN", 0],
    ["PASS", 0],
    ["FAIL", 0],
    ["BLOCKED", 0],
    ["NOT_IN_RUN", 0],
  ]);
  const expression = /^\s*-\s+(?:\*\*Verdict:?\*\*:?\s*|Verdict:\s*)`?(NOT_RUN|PASS|FAIL|BLOCKED|NOT_IN_RUN)`?\s*$/gmu;
  for (const match of markdown.matchAll(expression)) {
    verdicts.set(match[1], verdicts.get(match[1]) + 1);
  }
  return verdicts;
}

function standaloneHtml({ article, markdownHash, metadata, targetCommit, verdicts }) {
  const documentStatus = String(metadata.status || "DRAFT").toUpperCase();
  const counts = Object.fromEntries([...verdicts.entries()]);
  const total = Object.values(counts).reduce((sum, count) => sum + count, 0);
  const acceptanceReady =
    documentStatus === "READY" &&
    counts.NOT_RUN === 0 &&
    counts.FAIL === 0 &&
    counts.BLOCKED === 0;
  const warningClass = acceptanceReady ? "status-ready" : "status-warning";
  const warningHeading = acceptanceReady
    ? "Acceptance-ready execution set"
    : "Execution document is not acceptance-ready";
  const countsText = ["PASS", "FAIL", "BLOCKED", "NOT_RUN", "NOT_IN_RUN"]
    .map((name) => `${name}=${counts[name] || 0}`)
    .join(", ");
  const warningText = acceptanceReady
    ? `${countsText}. Suite metadata is READY; no case is NOT_RUN, FAIL, or BLOCKED. NOT_IN_RUN is shown as excluded scope and is not accepted execution evidence.`
    : `${countsText}. Suite status is ${documentStatus}. Any NOT_IN_RUN case is excluded scope, not accepted evidence; failures or blocked/unrun cases prevent acceptance readiness.`;
  const title = String(metadata.title || "SYNAPSE Black-Box Testing");
  const version = String(metadata.version || "unspecified");

  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; font-src 'none'; media-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'">
  <meta name="generator" content="SYNAPSE black-box renderer; marked ${PINNED_MARKED_VERSION}">
  <title>${escapeHtml(title)}</title>
  <style>
    :root { color-scheme: light; --ink: #17212b; --muted: #56616d; --line: #cbd4dc; --paper: #fff; --panel: #f4f7f9; --accent: #075985; --warn-bg: #fff7d6; --warn-border: #a16207; --ok-bg: #eaf8ef; --ok-border: #177245; }
    * { box-sizing: border-box; }
    html { background: #e8edf1; scroll-behavior: smooth; }
    body { margin: 0; color: var(--ink); background: var(--paper); font: 16px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; }
    .skip-link { position: absolute; left: .75rem; top: -5rem; z-index: 10; padding: .65rem .9rem; color: #fff; background: #111827; }
    .skip-link:focus { top: .75rem; }
    .status-banner { margin: 0; padding: 1rem max(1rem, calc((100vw - 76rem) / 2)); border-bottom: .25rem solid; }
    .status-banner.status-warning { color: #5f3b00; background: var(--warn-bg); border-color: var(--warn-border); }
    .status-banner.status-ready { color: #0f5132; background: var(--ok-bg); border-color: var(--ok-border); }
    .status-banner strong { display: block; font-size: 1.15rem; }
    .status-banner p { margin: .25rem 0 0; }
    main, footer { width: min(76rem, calc(100% - 2rem)); margin-inline: auto; }
    main { padding: 2rem 0 4rem; }
    article { min-width: 0; }
    h1, h2, h3, h4 { line-height: 1.2; margin-top: 1.8em; scroll-margin-top: 1rem; }
    h1 { margin-top: 0; font-size: clamp(2rem, 5vw, 3rem); }
    h2 { padding-bottom: .35rem; border-bottom: 2px solid var(--line); }
    h3 { color: #123a52; }
    a { color: var(--accent); text-underline-offset: .16em; }
    a:focus-visible { outline: 3px solid #f59e0b; outline-offset: 3px; }
    blockquote { margin-inline: 0; padding: .75rem 1rem; color: #394652; background: var(--panel); border-left: .35rem solid var(--accent); }
    code { padding: .08em .28em; background: #eef2f5; border-radius: .2rem; font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; overflow-wrap: anywhere; }
    pre { padding: 1rem; overflow: auto; background: #111827; color: #f9fafb; border-radius: .35rem; }
    pre code { padding: 0; color: inherit; background: transparent; }
    .table-scroll { width: 100%; overflow-x: auto; }
    .table-scroll:focus-visible { outline: 3px solid #f59e0b; outline-offset: 2px; }
    table { width: 100%; margin: 1rem 0 1.5rem; border-collapse: collapse; font-size: .94rem; }
    caption { text-align: left; font-weight: 700; }
    th, td { padding: .5rem .6rem; vertical-align: top; text-align: left; border: 1px solid var(--line); overflow-wrap: anywhere; }
    th { background: #eaf0f4; }
    tbody tr:nth-child(even) { background: #f8fafb; }
    hr { border: 0; border-top: 1px solid var(--line); }
    footer { padding: 1.25rem 0 2.5rem; color: var(--muted); border-top: 2px solid var(--line); }
    footer h2 { color: var(--ink); font-size: 1.15rem; border: 0; }
    footer dl { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: .25rem 1rem; }
    footer dt { font-weight: 700; }
    footer dd { margin: 0; overflow-wrap: anywhere; }
    @media (max-width: 46rem) { body { font-size: 15px; } table { white-space: nowrap; } footer dl { display: block; } footer dd { margin: 0 0 .6rem; } }
    @media print { @page { margin: 1.5cm; } html, body { background: #fff; } body { font-size: 10.5pt; } .skip-link { display: none; } .status-banner { padding: .6rem 0; } main, footer { width: 100%; } main { padding-top: 1rem; } a { color: inherit; text-decoration: none; } h1, h2, h3 { break-after: avoid; } .table-scroll { overflow: visible; } table { display: table; white-space: normal; break-inside: avoid; } tr, blockquote, pre { break-inside: avoid; } footer { break-before: page; } }
  </style>
</head>
<body>
  <a class="skip-link" href="#document">Skip to document</a>
  <aside class="status-banner ${warningClass}" aria-label="Document execution status">
    <strong>${escapeHtml(warningHeading)}</strong>
    <p>${escapeHtml(warningText)}</p>
  </aside>
  <main id="document" tabindex="-1">
    <article aria-label="${escapeHtml(title)}">
${article.trimEnd()}
    </article>
  </main>
  <footer aria-labelledby="generated-provenance">
    <h2 id="generated-provenance">Generated provenance</h2>
    <dl>
      <dt>Suite version</dt><dd>${escapeHtml(version)}</dd>
      <dt>Target commit</dt><dd><code>${escapeHtml(targetCommit)}</code></dd>
      <dt>Markdown SHA-256</dt><dd><code>${markdownHash}</code> (internal file consistency, not independent execution attestation)</dd>
      <dt>Renderer</dt><dd><code>tools/blackbox/render-html.mjs</code> with locally pinned <code>marked@${PINNED_MARKED_VERSION}</code></dd>
      <dt>Reproducibility</dt><dd>No wall-clock timestamp or external asset is included.</dd>
    </dl>
  </footer>
</body>
</html>
`;
}

function headingId(text, depth, used) {
  const visible = String(text)
    .replace(/<[^>]*>/gu, "")
    .replace(/&(?:#\d+|#x[0-9a-f]+|[a-z]+);/giu, "-");
  const caseMatch = visible.match(/^([A-Z][A-Z0-9]*(?:-[A-Z][A-Z0-9]*)*-\d{3})\s+—\s+/u);
  const plain = caseMatch
    ? `case-${caseMatch[1].toLowerCase()}`
    : visible
        .toLowerCase()
        .replace(/[^a-z0-9]+/gu, "-")
        .replace(/^-+|-+$/gu, "") || `heading-${depth}`;
  let candidate = plain;
  let suffix = 2;
  while (used.has(candidate)) {
    candidate = `${plain}-${suffix}`;
    suffix += 1;
  }
  used.add(candidate);
  return candidate;
}

function createSafeRenderer() {
  const renderer = new Renderer();
  const usedIds = new Set();
  renderer.html = ({ text }) => `<p>${escapeHtml(text)}</p>\n`;
  renderer.heading = function renderHeading({ tokens, depth }) {
    const rendered = this.parser.parseInline(tokens);
    const id = headingId(rendered, depth, usedIds);
    return `<h${depth} id="${escapeHtml(id)}">${rendered}</h${depth}>\n`;
  };
  renderer.link = function renderLink({ href, title, tokens }) {
    const text = this.parser.parseInline(tokens);
    if (typeof href !== "string" || !/^#[A-Za-z0-9][A-Za-z0-9._:-]*$/u.test(href)) {
      return text;
    }
    const titleAttribute = title ? ` title="${escapeHtml(title)}"` : "";
    return `<a href="${escapeHtml(href)}"${titleAttribute}>${text}</a>`;
  };
  renderer.image = ({ text }) => escapeHtml(text || "[image removed]");
  return renderer;
}

async function render(options) {
  await requireFreshValidatedMarkdown(options);
  const packageMetadataPath = path.join(SCRIPT_DIR, "node_modules", "marked", "package.json");
  const packageMetadataBuffer = await readSafeRegularFile(
    SCRIPT_DIR,
    packageMetadataPath,
    "marked package metadata",
    MAX_JSON_BYTES,
  );
  const packageMetadata = await strictJsonParse(packageMetadataBuffer, "marked package metadata");
  if (packageMetadata.version !== PINNED_MARKED_VERSION) {
    throw new Error(
      `marked version mismatch: expected ${PINNED_MARKED_VERSION}, found ${packageMetadata.version || "unknown"}`,
    );
  }

  const suitePath = path.join(options.root, "papers", "blackbox", "suite.json");
  const suiteBuffer = await readSafeRegularFile(
    options.root,
    suitePath,
    "suite manifest",
    MAX_JSON_BYTES,
  );
  const suite = await strictJsonParse(suiteBuffer, "suite manifest");
  if (!suite.metadata || typeof suite.metadata !== "object") {
    suite.metadata = {
      title: suite.title || "SYNAPSE Black-Box Testing",
      version: suite.suite_version || "unspecified",
      status: suite.status || "DRAFT",
    };
  } else {
    suite.metadata = {
      title: suite.title || "SYNAPSE Black-Box Testing",
      version: suite.suite_version || "unspecified",
      status: suite.status || "DRAFT",
      ...suite.metadata,
    };
  }
  const targetCommit = suite.target_commit || suite.target_application_commit || suite.metadata.target_commit;
  if (typeof targetCommit !== "string" || !/^[0-9a-fA-F]{7,40}$/u.test(targetCommit)) {
    throw new Error("suite.json target_commit is missing or invalid");
  }
  const declaredOutputs = suite.outputs && typeof suite.outputs === "object"
    ? suite.outputs
    : Object.fromEntries(
        (Array.isArray(suite.generated_root_outputs) ? suite.generated_root_outputs : [])
          .filter((value) => typeof value === "string")
          .map((value) => [value.toLowerCase().endsWith(".html") ? "html" : value.toLowerCase().endsWith(".md") ? "markdown" : "unknown", value]),
      );
  if (declaredOutputs.markdown !== REQUIRED_MARKDOWN_PATH) {
    throw new Error(`suite.json Markdown output must be ${REQUIRED_MARKDOWN_PATH}`);
  }
  if (declaredOutputs.html !== REQUIRED_HTML_PATH) {
    throw new Error(`suite.json HTML output must be ${REQUIRED_HTML_PATH}`);
  }
  const markdownPath = resolveRepositoryPath(options.root, declaredOutputs.markdown, "Markdown input");
  const htmlPath = resolveRepositoryPath(options.root, declaredOutputs.html, "HTML output");
  const markdownBuffer = await readSafeRegularFile(
    options.root,
    markdownPath,
    "generated Markdown input",
    MAX_MARKDOWN_BYTES,
  );
  const markdown = markdownBuffer.toString("utf8");
  const verdicts = countCaseVerdicts(markdown);
  const totalVerdicts = [...verdicts.values()].reduce((sum, count) => sum + count, 0);
  const declaredCount = suite.expected_case_count ?? suite.expected_count ?? suite.metadata.expected_case_count;
  if (Number.isInteger(declaredCount) && totalVerdicts !== declaredCount) {
    throw new Error(
      `generated Markdown verdict count is ${totalVerdicts}; suite declares ${declaredCount}. Run build.py first.`,
    );
  }

  const renderer = createSafeRenderer();
  marked.setOptions({ gfm: true, breaks: false, pedantic: false, renderer });
  const article = marked.parse(markdown, { async: false, renderer });
  if (typeof article !== "string") {
    throw new Error("marked returned an unexpected asynchronous result");
  }
  assertSafeRenderedArticle(article);
  const accessibleArticle = article
    .replaceAll("<table>", '<div class="table-scroll" role="region" aria-label="Scrollable table" tabindex="0"><table>')
    .replaceAll("</table>", "</table></div>");
  const expected = Buffer.from(
    standaloneHtml({
      article: accessibleArticle,
      markdownHash: sha256(markdownBuffer),
      metadata: suite.metadata,
      targetCommit: targetCommit.toLowerCase(),
      verdicts,
    }),
    "utf8",
  );

  if (options.check) {
    let actual;
    try {
      actual = await readSafeRegularFile(
        options.root,
        htmlPath,
        "generated HTML output",
        Math.max(64 * 1024 * 1024, expected.length + 1),
      );
    } catch (error) {
      throw new Error(`CHECK FAILED: could not safely read generated HTML ${htmlPath}: ${error instanceof Error ? error.message : String(error)}`);
    }
    if (!actual.equals(expected)) {
      throw new Error(
        `CHECK FAILED: HTML differs from Markdown (expected sha256=${sha256(expected)}, actual sha256=${sha256(actual)})`,
      );
    }
    process.stdout.write(`CHECK OK: ${htmlPath} is deterministic and current (${expected.length} bytes).\n`);
    return;
  }

  await verifyDirectoryChain(options.root, path.dirname(htmlPath), "HTML output parent");
  await atomicWriteViaPython(options.root, htmlPath, expected);
  process.stdout.write(
    `Rendered ${htmlPath} (${expected.length} bytes, sha256=${sha256(expected)}).\n`,
  );
}

try {
  const options = parseArguments(process.argv.slice(2));
  if (options !== null) {
    await render(options);
  }
} catch (error) {
  process.stderr.write(`HTML render failed: ${error instanceof Error ? error.message : String(error)}\n`);
  process.exitCode = 1;
}
