<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= esc($title ?? 'Sign In') ?> - SYNAPSE Campus Health & Guidance</title>
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-name" content="<?= csrf_token() ?>">
  <meta name="csrf-token" content="<?= csrf_hash() ?>">
  <meta name="csrf-header" content="<?= csrf_header() ?>">

  <!-- Favicons -->
  <link rel="icon" type="image/x-icon" href="<?= base_url('favicon.ico') ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= base_url('favicon-192.png') ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= base_url('favicon-180.png') ?>">

  <!-- Pre-paint theme restoration to preserve dark mode after logout -->
  <script src="<?= base_url('assets/js/auth-theme-init.js') ?>"></script>

  <!-- Google Fonts - Nunito Sans + Poppins -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;500;600;700;800&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>" rel="stylesheet">

  <!-- NiceAdmin Master CSS File (Native Typography, Spacing, and Components) -->
  <link href="<?= base_url('assets/css/style.css') ?>" rel="stylesheet">

  <!-- Split Layout & Social Dock Geometry -->
  <style>
    :root {
      --fu-maroon: #800000;
      --fu-maroon-hover: #6b0000;
      --fu-maroon-active: #5a0000;
    }

    body {
      margin: 0;
      padding: 0;
      min-height: 100vh;
      min-height: 100dvh;
      background-color: #ffffff;
      color: #1a1a1a;
      transition: none !important;
    }

    .auth-main-wrapper,
    .auth-main-wrapper > .row,
    .auth-photo-aside,
    .auth-pane {
      min-height: 100vh;
      min-height: 100dvh;
    }

    .auth-pane {
      --auth-logo-height: 56px;
      padding: 1.5rem;
    }

    .auth-form-container, .auth-socials { max-width: 380px; min-width: 0; }
    .auth-form-container, .auth-socials { flex-shrink: 0; }
    .auth-brand { margin-bottom: 1.5rem; }
    .auth-brand-mark { min-height: var(--auth-logo-height); margin-bottom: 1rem; }
    .auth-brand-mark img { max-width: 100%; }
    .auth-brand-logo { height: var(--auth-logo-height); width: auto; object-fit: contain; }
    .auth-portal-badge { letter-spacing: 0.05rem; font-size: 0.72rem; }
    .auth-signin-form { margin-top: 1.5rem; }
    .auth-password-group { margin-bottom: 1.5rem; }
    .auth-help { margin-top: 1.25rem; font-size: 0.8rem; }
    #auth-alerts:focus { outline: none; }
    #auth-alerts:focus-visible { outline: 2px solid var(--fu-maroon); outline-offset: 2px; border-radius: 0.375rem; }
    .auth-socials { margin-top: 1.25rem; padding-top: 0; padding-bottom: 0; }
    .auth-socials-heading { margin-bottom: .35rem; }
    .auth-socials-divider { height: 1px; }
    .auth-socials-title { font-size: 0.65rem; letter-spacing: 0.05rem; }

    @media (min-width: 576px) {
      .auth-pane { padding: 3rem; }
    }

    /* Compact decoration and whitespace on short screens, never the controls.
       Content remains in normal flow so errors and enlarged text can scroll. */
    @media (max-height: 760px) {
      .auth-pane { --auth-logo-height: 44px; padding-top: 1rem; padding-bottom: 1rem; }
      .auth-brand { margin-bottom: 1rem; }
      .auth-brand-mark { margin-bottom: .5rem; }
      .auth-signin-form { margin-top: 1rem; }
      .auth-password-group { margin-bottom: 1rem; }
      .auth-socials { margin-top: .875rem; }
      .auth-socials-heading { margin-bottom: .25rem; }
      .auth-socials .social-seal { width: 36px; height: 36px; }
      .auth-socials .social-item { padding-top: .375rem; padding-bottom: .375rem; }
      .auth-help { margin-top: .875rem; }
    }

    @media (max-height: 640px) {
      .auth-pane { --auth-logo-height: 40px; }
      .auth-brand, .auth-password-group { margin-bottom: .75rem; }
      .auth-signin-form { margin-top: .75rem; }
      .auth-socials { margin-top: .625rem; }
      .auth-socials .social-seal { width: 32px; height: 32px; }
      .auth-help { margin-top: .625rem; }
    }

    .auth-pane .alert {
      font-size: 0.8125rem;
      line-height: 1.45;
      padding: 0.75rem 2.25rem 0.75rem 0.875rem;
    }

    .auth-pane .alert.alert-dismissible .btn-close {
      top: 0.25rem;
      right: 0.25rem;
      width: 1rem;
      height: 1rem;
      padding: 0.5rem;
      background-size: 0.625rem;
      filter: none;
      opacity: 0.75;
    }

    :is(html[data-theme="dark"], html:not([data-theme="light"]) body.dark-mode) .auth-pane .alert.alert-dismissible .btn-close {
      filter: invert(1) grayscale(100%) brightness(200%);
    }

    .auth-pane .alert-dismissible .btn-close:hover,
    .auth-pane .alert-dismissible .btn-close:focus-visible {
      opacity: 1;
    }

    /* Theme-aware logo/image visibility toggles */
    .synapse-logo-light {
      display: block !important;
    }
    .synapse-logo-dark {
      display: none !important;
    }

    [data-theme="dark"] .synapse-logo-light {
      display: none !important;
    }
    [data-theme="dark"] .synapse-logo-dark {
      display: block !important;
    }

    /* Zero-lag instant background transition on login screen */
    .auth-main-wrapper,
    .auth-photo-aside,
    .auth-pane {
      transition: none !important;
    }

    [data-theme="dark"] body,
    [data-theme="dark"] .auth-main-wrapper,
    [data-theme="dark"] .auth-photo-aside {
      background-color: #121212 !important;
      color: #f0f0f0 !important;
    }

    .auth-photo-aside {
      position: relative;
    }

    .auth-photo-container {
      position: absolute;
      inset: 0.5rem;
      background-color: var(--surface-color, #ffffff);
      border: 0 !important;
      outline: 0 !important;
      box-shadow: none !important;
    }

    /* Keep Bootstrap's fade opacity/stacking: the outgoing image remains opaque
       underneath the incoming image until the latter has finished fading in. */
    #loginPhotoCarousel.carousel-fade .carousel-item {
      transition: opacity 0.9s ease-in-out;
    }

    /* Bootstrap completes slides on a timer as well as transitionend. Snap the
       newly active slide fully opaque so a fractional final frame cannot leak. */
    #loginPhotoCarousel.carousel-fade .carousel-item.active:not(.carousel-item-start):not(.carousel-item-end) {
      transition: none;
    }

    #loginPhotoCarousel.carousel-fade .active.carousel-item-start,
    #loginPhotoCarousel.carousel-fade .active.carousel-item-end {
      transition: opacity 0s 0.9s;
    }

    @media (prefers-reduced-motion: reduce) {
      #loginPhotoCarousel.carousel-fade .carousel-item,
      #loginPhotoCarousel.carousel-fade .active.carousel-item-start,
      #loginPhotoCarousel.carousel-fade .active.carousel-item-end {
        transition: none;
      }
    }

    /* Carousel fills the full rounded container */
    #loginPhotoCarousel,
    #loginPhotoCarousel .carousel-inner,
    #loginPhotoCarousel .carousel-item {
      height: 100%;
      width: 100%;
    }

    /* Slide images: two-layer system — blurred bg + contained foreground */
    .auth-slide-img-bg {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      transform: scale(1.12);
      filter: blur(22px) brightness(0.72);
      pointer-events: none;
      border: 0;
      outline: 0;
      user-select: none;
      -webkit-user-select: none;
      z-index: 0;
    }

    /* Cover mode: crisp, full-bleed, NO blur */
    .auth-slide-img-cover {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      pointer-events: none;
      border: 0;
      outline: 0;
      user-select: none;
      -webkit-user-select: none;
      z-index: 1;
    }

    .auth-slide-img-fg {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: contain;
      pointer-events: none;
      border: 0;
      outline: 0;
      user-select: none;
      -webkit-user-select: none;
      z-index: 2;
    }

    /* Outrank NiceAdmin's dark-mode img:not(...) filter, which would remove blur. */
    [data-theme="dark"] #loginPhotoCarousel .auth-slide-img-bg {
      filter: blur(22px) brightness(0.95) opacity(1);
    }

    /* Gradient caption bar at the bottom of each slide */
    .auth-slide-caption {
      position: absolute;
      left: 0;
      right: 0;
      bottom: 0;
      padding: 2rem 2rem 3.5rem 2rem;
      background: linear-gradient(to top, rgba(0,0,0,0.72) 0%, transparent 100%);
      z-index: 15;
      pointer-events: none;
    }

    .auth-slide-caption-inner {
      display: flex;
      flex-direction: column;
      gap: 0.25rem;
    }

    .auth-slide-title {
      font-size: 1.1rem;
      font-weight: 700;
      color: #ffffff;
      letter-spacing: 0.01em;
      line-height: 1.2;
      text-shadow: 0 1px 4px rgba(0,0,0,0.5);
    }

    .auth-slide-sub {
      font-size: 0.75rem;
      color: rgba(255,255,255,0.8);
      font-weight: 500;
      text-shadow: 0 1px 3px rgba(0,0,0,0.4);
    }

    /* Custom pill indicator dots (pill shape, brand maroon) */
    .auth-carousel-indicators {
      bottom: 1.5rem;
      margin-bottom: 0;
      z-index: 20;
      gap: 6px;
      justify-content: center;
    }

    #loginPhotoCarousel .auth-carousel-indicators [data-bs-target] {
      width: 24px;
      height: 6px;
      border-radius: 999px;
      background-color: rgba(255,255,255,0.5);
      border: 0;
      padding: 0;
      opacity: 1;
      transition: width 0.3s ease, background-color 0.3s ease;
    }

    #loginPhotoCarousel .auth-carousel-indicators [data-bs-target].active {
      width: 40px;
      background-color: #ffffff;
    }

    /* WCAG 2.2 SC 2.2.2 slideshow Pause/Play control */
    .auth-carousel-pause {
      position: absolute;
      right: 1.25rem;
      bottom: 1.05rem;
      z-index: 25;
      width: 2rem;
      height: 2rem;
      padding: 0;
      border: 1px solid rgba(255, 255, 255, 0.35);
      border-radius: 999px;
      background-color: rgba(0, 0, 0, 0.52);
      color: #ffffff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      backdrop-filter: blur(4px);
      transition: background-color 0.15s ease, border-color 0.15s ease;
    }

    .auth-carousel-pause:hover {
      background-color: rgba(0, 0, 0, 0.72);
      border-color: rgba(255, 255, 255, 0.65);
    }

    .auth-carousel-pause:focus-visible {
      outline: 2px solid #ffffff;
      outline-offset: 2px;
    }

    /* The floating wrapper must not block nearby controls with its padding. */
    .auth-theme-control { pointer-events: none; z-index: 1050; }

    /* The login toggle reuses the app header's .header-action control, so its
       surface, border, and icon colors come from the shared theme tokens. */
    .auth-theme-toggle {
      pointer-events: auto;
      padding: 0;
      gap: 0;
      line-height: 1;
    }

    .auth-theme-toggle > .bi {
      width: 1em;
      height: 1em;
      margin: 0;
      flex: 0 0 auto;
      line-height: 1;
    }

    .auth-theme-toggle > .bi::before {
      display: block;
      vertical-align: 0;
      line-height: 1;
    }

    .auth-theme-toggle .theme-icon-light {
      display: none;
    }

    [data-theme="dark"] .auth-theme-toggle .theme-icon-dark {
      display: none;
    }

    [data-theme="dark"] .auth-theme-toggle .theme-icon-light {
      display: block;
    }


    .auth-signin-form .form-control {
      font-family: var(--default-font);
      font-size: 0.875rem;
      line-height: 1.5;
    }

    .auth-signin-form .form-control::placeholder {
      font-size: 0.875rem;
    }

    .auth-signin-form .form-control:-webkit-autofill,
    .auth-signin-form .form-control:-webkit-autofill:hover,
    .auth-signin-form .form-control:-webkit-autofill:focus,
    .auth-signin-form .form-control:-webkit-autofill:active,
    .auth-signin-form .form-control:autofill {
      font-family: var(--default-font) !important;
      font-size: 0.875rem !important;
      line-height: 1.5 !important;
    }

    .auth-signin-form .form-control:-webkit-autofill::first-line,
    .auth-signin-form .form-control:autofill::first-line {
      font-family: var(--default-font) !important;
      font-size: 0.875rem !important;
      font-weight: 400 !important;
      line-height: 1.5 !important;
    }

    .form-control:focus {
      border-color: var(--fu-maroon);
      box-shadow: 0 0 0 0.2rem rgba(128, 0, 0, 0.15);
    }

    /* Seamless Password Field with Embedded Eye Toggle */
    .password-field-wrapper {
      position: relative;
      width: 100%;
    }

    .password-field-wrapper .form-control {
      padding-right: 2.75rem !important;
    }

    .password-toggle-btn {
      position: absolute;
      right: 0.4rem;
      top: 50%;
      transform: translateY(-50%);
      background: transparent;
      border: none;
      padding: 0.4rem 0.5rem;
      border-radius: 0.375rem;
      color: #6c757d;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: background-color 0.15s ease, color 0.15s ease;
      z-index: 5;
    }

    .password-toggle-btn:hover {
      background-color: rgba(0, 0, 0, 0.05);
      color: var(--fu-maroon, #800000);
    }

    .password-toggle-btn:focus-visible {
      outline: 2px solid var(--fu-maroon, #800000);
      outline-offset: 1px;
    }

    .social-dock {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 0.25rem;
      width: 100%;
      background: rgba(248, 249, 250, 0.9);
      border: 1px solid #e9ecef;
      border-radius: 2rem;
      padding: 0.375rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .social-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      padding: 0.65rem 0.25rem;
      border-radius: 1.6rem;
      text-decoration: none;
      color: #6c757d;
      min-width: 0;
      width: 100%;
      transition: all 0.15s ease-in-out;
    }

    .social-item:hover {
      background: #ffffff;
      color: #1a1a1a;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
      transform: translateY(-2px);
    }

    .social-label {
      font-size: 0.72rem;
      font-weight: 600;
      line-height: 1.2;
      text-align: center;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 100%;
    }

    .social-seal {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: #ffffff;
      padding: 2px;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .social-seal img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 50%;
    }

    .no-select {
      user-select: none;
      -webkit-user-select: none;
    }
  </style>

  <?= $this->renderSection('styles') ?>
</head>

<body>
  <?= $this->renderSection('content') ?>

  <script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
  <script src="<?= base_url('assets/js/loading.js') ?>"></script>
  <script src="<?= base_url('assets/js/form-loading.js') ?>"></script>
  <script src="<?= base_url('assets/js/csrf.js') ?>"></script>
  <?= $this->renderSection('scripts') ?>
</body>

</html>
