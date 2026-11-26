<?php
/**
 * Shared standalone SYNAPSE editorial sheet error view.
 *
 * Matches SYNAPSE's 3-band hairline-divided sheet architecture
 * (portal-appointments.css / portal-referrals.css / clinic-inventory.css)
 * without database or session dependencies, and with zero inline handlers.
 */
$e = static fn (string $v): string => function_exists('esc')
    ? esc($v)
    : htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$ea = static fn (string $v): string => function_exists('esc')
    ? esc($v, 'attr')
    : htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$assetUrl = static function (string $path): string {
    $clean = ltrim($path, '/');
    return function_exists('base_url') ? base_url($clean) : '/' . $clean;
};
$routeUrl = static function (string $path): string {
    $clean = ltrim($path, '/');
    return function_exists('site_url') ? site_url($clean) : '/' . $clean;
};

$statusCode     = (int) ($status ?? 500);
$pageTitle      = (string) ($title ?? 'Error');
$pageHeading    = (string) ($heading ?? $pageTitle);
$bodyMessage    = (string) ($message ?? 'Unable to complete the request.');
$statusBadge    = (string) ($badge ?? ('HTTP ' . $statusCode));
$iconClass      = (string) ($icon ?? 'bi-exclamation-triangle-fill');
$toneName       = (string) ($tone ?? 'maroon');
$retrySeconds   = isset($retryAfter) ? (int) $retryAfter : 0;
$retryNotice    = (string) ($retryText ?? '');
$primaryUrl     = (string) ($primaryHref ?? $routeUrl('/auth/login'));
$primaryText    = (string) ($primaryLabel ?? 'Return to sign in');
$secondaryUrl   = isset($secondaryHref) && is_string($secondaryHref) ? $secondaryHref : '';
$secondaryText  = isset($secondaryLabel) && is_string($secondaryLabel) ? $secondaryLabel : '';
$wordmarkText   = (string) ($eyebrow ?? 'Foundation University');
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $e($pageTitle) ?> - SYNAPSE</title>
  <link rel="icon" type="image/x-icon" href="<?= $ea($assetUrl('favicon.ico')) ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= $ea($assetUrl('favicon-192.png')) ?>">
  <script src="<?= $ea($assetUrl('assets/js/auth-theme-init.js')) ?>"></script>
  <link href="<?= $ea($assetUrl('assets/css/core/fonts.css')) ?>" rel="stylesheet">
  <link href="<?= $ea($assetUrl('assets/vendor/bootstrap-icons/bootstrap-icons.css')) ?>" rel="stylesheet">
  <style>
    :root {
      --syn-page-bg: #f8f9fb;
      --syn-surface: #ffffff;
      --syn-surface-subtle: #f8f9fb;
      --syn-surface-footer: #f9fafc;
      --syn-border: #e5e7eb;
      --syn-border-subtle: #eff1f5;
      --syn-border-input: #cfd5df;
      --syn-heading: #111827;
      --syn-text: #374151;
      --syn-muted: #5c6573;
      --syn-brand: #800000;
      --syn-brand-hover: #660000;
      --syn-accent: #800000;
      --syn-warning: #9a5b13;
      --syn-danger: #b42318;
      --syn-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
      --syn-btn-sec-bg: #ffffff;
      --syn-btn-sec-hover: #f3f4f6;
      --syn-body-font: "Nunito Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      --syn-head-font: "Poppins", "Nunito Sans", system-ui, sans-serif;
    }
    [data-theme="dark"] {
      --syn-page-bg: #121212;
      --syn-surface: #1c1c1c;
      --syn-surface-subtle: #161616;
      --syn-surface-footer: #181818;
      --syn-border: rgba(255, 255, 255, 0.11);
      --syn-border-subtle: rgba(255, 255, 255, 0.075);
      --syn-border-input: rgba(255, 255, 255, 0.18);
      --syn-heading: #f3f4f6;
      --syn-text: #e2e4e8;
      --syn-muted: #9ca3af;
      --syn-brand: #800000;
      --syn-brand-hover: #6b0000;
      --syn-accent: #e08a8a;
      --syn-warning: #fbbf24;
      --syn-danger: #f87171;
      --syn-shadow: none;
      --syn-btn-sec-bg: #232328;
      --syn-btn-sec-hover: #2c2c32;
    }
    *, *::before, *::after { box-sizing: border-box; }
    html, body {
      margin: 0;
      padding: 0;
      min-height: 100%;
      background-color: var(--syn-page-bg);
      color: var(--syn-text);
      font-family: var(--syn-body-font);
      -webkit-font-smoothing: antialiased;
    }
    .syn-err-shell {
      min-height: 100vh;
      min-height: 100dvh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem 1rem;
    }
    .syn-err-sheet {
      width: 100%;
      max-width: 560px;
      background-color: var(--syn-surface);
      border: 1px solid var(--syn-border);
      border-radius: 12px;
      box-shadow: var(--syn-shadow);
      overflow: hidden;
    }
    .syn-err-head {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 0.65rem 1rem;
      padding: 0.95rem 1.5rem;
      background-color: var(--syn-surface-subtle);
      border-bottom: 1px solid var(--syn-border);
    }
    .syn-err-brand {
      display: flex;
      align-items: center;
      gap: 0.7rem;
      min-width: 0;
    }
    .syn-err-logo {
      height: 28px;
      width: auto;
      display: block;
      object-fit: contain;
    }
    .syn-err-logo-dark { display: none; }
    [data-theme="dark"] .syn-err-logo-light { display: none; }
    [data-theme="dark"] .syn-err-logo-dark { display: block; }
    .syn-err-divider {
      width: 1px;
      height: 16px;
      background-color: var(--syn-border);
      flex-shrink: 0;
    }
    .syn-err-eyebrow {
      font-size: 0.72rem;
      font-weight: 600;
      letter-spacing: 0.02em;
      color: var(--syn-muted);
      line-height: 1.25;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .syn-err-status {
      display: inline-flex;
      align-items: center;
      gap: 0.38rem;
      font-size: 0.78rem;
      font-weight: 600;
      font-variant-numeric: tabular-nums;
      color: var(--syn-accent);
      white-space: nowrap;
    }
    .syn-err-status--warning {
      color: var(--syn-warning);
    }
    .syn-err-body {
      padding: 1.55rem 1.5rem;
    }
    .syn-err-title {
      margin: 0 0 0.5rem;
      font-family: var(--syn-head-font);
      font-size: 1.15rem;
      font-weight: 600;
      line-height: 1.35;
      letter-spacing: -0.015em;
      color: var(--syn-heading);
    }
    .syn-err-message {
      margin: 0;
      font-size: 0.89rem;
      line-height: 1.6;
      color: var(--syn-text);
    }
    .syn-err-notice {
      margin-top: 1.1rem;
      padding: 0.8rem 0.95rem;
      border-radius: 6px;
      background-color: var(--syn-surface-subtle);
      border: 1px solid var(--syn-border);
      border-left: 3px solid var(--syn-accent);
      font-size: 0.83rem;
      line-height: 1.5;
      color: var(--syn-text);
    }
    .syn-err-notice--warning {
      border-left-color: var(--syn-warning);
    }
    .syn-err-notice strong {
      color: var(--syn-heading);
      font-weight: 600;
    }
    .syn-err-foot {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 0.85rem 1rem;
      padding: 0.95rem 1.5rem;
      background-color: var(--syn-surface-footer);
      border-top: 1px solid var(--syn-border);
    }
    .syn-err-help {
      margin: 0;
      font-size: 0.76rem;
      line-height: 1.4;
      color: var(--syn-muted);
    }
    .syn-err-actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 0.5rem;
    }
    .syn-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      min-height: 34px;
      padding: 0 0.9rem;
      border-radius: 0.25rem;
      font-size: 0.8125rem;
      font-weight: 600;
      line-height: 1.2;
      text-decoration: none;
      border: 1px solid transparent;
      transition: background-color 0.14s ease, border-color 0.14s ease, color 0.14s ease;
    }
    .syn-btn-primary {
      background-color: var(--syn-brand);
      border-color: var(--syn-brand);
      color: #ffffff;
    }
    .syn-btn-primary:hover, .syn-btn-primary:focus-visible {
      background-color: var(--syn-brand-hover);
      border-color: var(--syn-brand-hover);
      color: #ffffff;
    }
    .syn-btn-secondary {
      background-color: var(--syn-btn-sec-bg);
      border-color: var(--syn-border-input);
      color: var(--syn-heading);
    }
    .syn-btn-secondary:hover, .syn-btn-secondary:focus-visible {
      background-color: var(--syn-btn-sec-hover);
      color: var(--syn-heading);
    }
    .syn-btn:focus-visible {
      outline: 2px solid var(--syn-brand);
      outline-offset: 2px;
    }
    @media (max-width: 520px) {
      .syn-err-head,
      .syn-err-body,
      .syn-err-foot {
        padding-inline: 1.15rem;
      }
      .syn-err-foot {
        flex-direction: column-reverse;
        align-items: stretch;
      }
      .syn-err-actions {
        width: 100%;
      }
      .syn-btn {
        flex: 1 1 auto;
        min-height: 38px;
      }
    }
  </style>
</head>
<body>
  <main class="syn-err-shell" role="main">
    <section class="syn-err-sheet" aria-labelledby="synErrTitle">
      <header class="syn-err-head">
        <div class="syn-err-brand">
          <img src="<?= $ea($assetUrl('assets/img/brand/synapse-maroon.png')) ?>"
               alt="SYNAPSE Logo"
               class="syn-err-logo syn-err-logo-light">
          <img src="<?= $ea($assetUrl('assets/img/brand/synapse-white.png')) ?>"
               alt="SYNAPSE Logo"
               class="syn-err-logo syn-err-logo-dark">
          <span class="syn-err-divider" aria-hidden="true"></span>
          <span class="syn-err-eyebrow"><?= $e($wordmarkText) ?></span>
        </div>
        <span class="syn-err-status <?= $toneName === 'warning' ? 'syn-err-status--warning' : '' ?>">
          <i class="bi <?= $ea($iconClass) ?>" aria-hidden="true"></i>
          <span><?= $e($statusBadge) ?></span>
        </span>
      </header>

      <div class="syn-err-body">
        <h1 class="syn-err-title" id="synErrTitle"><?= $e($pageHeading) ?></h1>
        <p class="syn-err-message"><?= $e($bodyMessage) ?></p>

        <?php if ($retrySeconds > 0): ?>
          <div class="syn-err-notice <?= $toneName === 'warning' ? 'syn-err-notice--warning' : '' ?>" role="status">
            <strong>Cooldown active:</strong>
            <?= $e($retryNotice !== '' ? $retryNotice : ('Please wait about ' . $retrySeconds . ' seconds before trying again.')) ?>
          </div>
        <?php endif; ?>
      </div>

      <footer class="syn-err-foot">
        <p class="syn-err-help">Need help? Contact your system administrator.</p>
        <div class="syn-err-actions">
          <?php if ($secondaryUrl !== '' && $secondaryText !== ''): ?>
            <a href="<?= $ea($secondaryUrl) ?>" class="syn-btn syn-btn-secondary">
              <span><?= $e($secondaryText) ?></span>
            </a>
          <?php endif; ?>
          <?php if ($primaryUrl !== '' && $primaryText !== ''): ?>
            <a href="<?= $ea($primaryUrl) ?>" class="syn-btn syn-btn-primary">
              <i class="bi bi-arrow-left" aria-hidden="true"></i>
              <span><?= $e($primaryText) ?></span>
            </a>
          <?php endif; ?>
        </div>
      </footer>
    </section>
  </main>
</body>
</html>
