<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<!-- Floating Theme Toggle in Upper Right of Login Screen (same control as the app header) -->
<div class="position-fixed top-0 end-0 p-3 auth-theme-control">
  <button class="header-action theme-toggle auth-theme-toggle"
          type="button"
          id="authThemeToggle"
          data-bs-title="Toggle Theme (Light / Dark mode)"
          data-bs-toggle="tooltip"
          data-bs-placement="bottom"
          aria-label="Toggle dark mode">
    <i class="bi bi-moon-stars theme-icon-dark" aria-hidden="true"></i>
    <i class="bi bi-sun theme-icon-light" aria-hidden="true"></i>
  </button>
</div>

<main class="container-fluid p-0 auth-main-wrapper">
  <div class="row g-0">

    <!-- Left 60% Pane: Photo Carousel (hidden on small viewports) -->
    <aside class="col-lg-7 d-none d-lg-block p-2 auth-photo-aside" aria-label="Campus showcase">
      <div class="rounded-4 overflow-hidden auth-photo-container">

        <!-- Bootstrap 5 Carousel: JS-managed playback with reduced-motion & pause/play control -->
        <div id="loginPhotoCarousel"
             class="carousel carousel-fade slide h-100 w-100"
             data-bs-interval="6000"
             data-bs-wrap="true"
             data-bs-touch="false">

          <!-- Pill indicator dots at bottom center (built from slides) -->
          <div class="carousel-indicators auth-carousel-indicators">
            <?php foreach ($slides as $i => $slide): ?>
              <button type="button"
                      data-bs-target="#loginPhotoCarousel"
                      data-bs-slide-to="<?= $i ?>"
                      class="<?= $i === 0 ? 'active' : '' ?>"
                      <?= $i === 0 ? 'aria-current="true"' : '' ?>
                      aria-label="<?= esc($slide['title'] ?? 'Slide ' . ($i + 1)) ?>"></button>
            <?php endforeach; ?>
          </div>

          <div class="carousel-inner h-100">

            <?php foreach ($slides as $i => $slide): ?>
              <?php
                $isCover   = ($slide['fit_mode'] ?? 'cover') === 'cover';
                $lightSrc  = base_url($slide['light_image']);
                $darkSrc   = !empty($slide['dark_image']) ? base_url($slide['dark_image']) : $lightSrc;
                $lightCls  = $isCover ? 'auth-slide-img-cover' : 'auth-slide-img-fg';
                $darkCls   = $isCover ? 'auth-slide-img-cover' : 'auth-slide-img-fg';
              ?>
              <!-- Slide <?= $i + 1 ?>: <?= esc($slide['title'] ?? 'Untitled') ?> -->
              <div class="carousel-item <?= $i === 0 ? 'active' : '' ?> h-100">

                <?php if (!$isCover): ?>
                  <!-- Blurred backdrop layer (contain_blur only) -->
                  <img src="<?= $lightSrc ?>"
                       alt=""
                       class="no-select auth-slide-img-bg synapse-logo-light"
                       draggable="false" data-no-contextmenu>
                  <img src="<?= $darkSrc ?>"
                       alt=""
                       class="no-select auth-slide-img-bg synapse-logo-dark"
                       draggable="false" data-no-contextmenu>
                <?php endif; ?>

                <!-- Light mode image (cover = crisp full-bleed, else contained) -->
                <img src="<?= $lightSrc ?>"
                     alt="<?= esc($slide['title'] ?? '') ?>"
                     class="no-select <?= $lightCls ?> synapse-logo-light"
                     draggable="false" data-no-contextmenu>

                <!-- Dark mode image -->
                <img src="<?= $darkSrc ?>"
                     alt="<?= esc($slide['title'] ?? '') ?> (Night)"
                     class="no-select <?= $darkCls ?> synapse-logo-dark"
                     draggable="false" data-no-contextmenu>

                <?php if (!empty($slide['title']) || !empty($slide['subtitle'])): ?>
                  <div class="auth-slide-caption">
                    <div class="auth-slide-caption-inner">
                      <?php if (!empty($slide['title'])): ?>
                        <span class="auth-slide-title"><?= esc($slide['title']) ?></span>
                      <?php endif; ?>
                      <?php if (!empty($slide['subtitle'])): ?>
                        <span class="auth-slide-sub"><?= esc($slide['subtitle']) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>

          </div><!-- /.carousel-inner -->

          <?php if (count($slides) > 1): ?>
            <button type="button"
                    class="auth-carousel-pause"
                    data-carousel-toggle
                    aria-controls="loginPhotoCarousel"
                    aria-pressed="false"
                    aria-label="Pause background slideshow">
              <i class="bi bi-pause-fill carousel-icon-pause" aria-hidden="true"></i>
              <i class="bi bi-play-fill carousel-icon-play d-none" aria-hidden="true"></i>
            </button>
          <?php endif; ?>
        </div><!-- /#loginPhotoCarousel -->
      </div>
    </aside>

    <?php
      $pMeta = $portalMeta ?? \App\Services\PortalContext::metadata();
      $currentP = $portal ?? \App\Services\PortalContext::current();
      $isMainPortal = ($currentP === 'main');
      $identifierLabel = $isMainPortal ? 'Student or Employee ID Number' : 'Employee ID Number';
      $identifierPlaceholder = $isMainPortal ? 'Enter your student or employee ID' : 'Enter your employee ID';

      $rawFieldErrors = session()->getFlashdata('auth_field_errors');
      $fieldErrors = is_array($rawFieldErrors) ? $rawFieldErrors : [];
      $identifierError = is_string($fieldErrors['identifier'] ?? null) ? $fieldErrors['identifier'] : '';
      $passwordError = is_string($fieldErrors['password'] ?? null) ? $fieldErrors['password'] : '';
      $hasAlert = (bool) (
          session()->getFlashdata('error')
          || session()->getFlashdata('warning')
          || session()->getFlashdata('errors')
          || session()->getFlashdata('info')
          || session()->getFlashdata('success')
      );
    ?>

    <!-- Right 40% Pane: Centered Sign-in Form with Socials Dock below Sign-in -->
    <section class="col-12 col-lg-5 d-flex flex-column align-items-center auth-pane position-relative" aria-labelledby="login-title">

      <!-- Center Form Container (max-width: 380px) -->
      <div class="w-100 my-auto auth-form-container">

        <!-- Brand Mark & Headline -->
        <div class="text-center auth-brand">
          <div class="d-flex justify-content-center align-items-center auth-brand-mark">
            <?php if ($isMainPortal): ?>
              <img src="<?= base_url('assets/img/brand/synapse-maroon.png') ?>"
                   alt="SYNAPSE Logo"
                   class="no-select synapse-logo-light auth-brand-logo"
                   draggable="false"
                   data-no-contextmenu>

              <img src="<?= base_url('assets/img/brand/synapse-white.png') ?>"
                   alt="SYNAPSE Logo"
                   class="no-select synapse-logo-dark auth-brand-logo"
                   draggable="false"
                   data-no-contextmenu>
            <?php else: ?>
              <img src="<?= base_url($pMeta['logo']) ?>"
                   alt="Portal Mark"
                   class="no-select auth-brand-logo"
                   draggable="false"
                   data-no-contextmenu>
            <?php endif; ?>
          </div>

          <?php if (!$isMainPortal): ?>
            <div class="mb-2">
              <span class="badge bg-primary text-white text-uppercase auth-portal-badge">
                <?= esc($pMeta['badge']) ?>
              </span>
            </div>
          <?php endif; ?>

          <h1 id="login-title" class="h3 fw-bold mb-1 text-dark">
            <?= $isMainPortal ? 'Welcome back' : esc($pMeta['title']) ?>
          </h1>
          <p class="text-muted small mb-0">
            <?= $isMainPortal ? 'Access university health, guidance, and composting services.' : 'Department Operational Console · Authorized Staff Only' ?>
          </p>
        </div>

        <!-- Alert messages -->
        <div id="auth-alerts" tabindex="-1"<?= $hasAlert ? ' data-has-alert="1"' : '' ?>>
          <?= $this->include('partials/alerts') ?>
        </div>

        <!-- Sign-in Form -->
        <form action="<?= site_url('/auth/login') ?>" method="POST" novalidate class="auth-signin-form">
          <?= csrf_field() ?>

          <!-- ID Number -->
          <div class="auth-field-group">
            <label for="identifier" class="form-label auth-field-label"><?= esc($identifierLabel) ?></label>
            <input type="text"
                   class="form-control auth-input<?= $identifierError !== '' ? ' is-invalid' : '' ?>"
                   id="identifier"
                   name="identifier"
                   placeholder="<?= esc($identifierPlaceholder) ?>"
                   value="<?= esc(old('identifier')) ?>"
                   autocomplete="username"
                   <?= $identifierError !== '' ? 'aria-invalid="true" aria-describedby="identifier-error"' : '' ?>
                   required
                   autofocus>
            <?php if ($identifierError !== ''): ?>
              <div id="identifier-error" class="invalid-feedback"><?= esc($identifierError) ?></div>
            <?php endif; ?>
          </div>

          <!-- Password with seamless internal show/hide toggle -->
          <div class="auth-password-group">
            <label for="password" class="form-label auth-field-label">Password</label>
            <div class="password-field-wrapper">
              <input type="password"
                     class="form-control auth-input<?= $passwordError !== '' ? ' is-invalid' : '' ?>"
                     id="password"
                     name="password"
                     placeholder="Enter your password"
                     autocomplete="current-password"
                     <?= $passwordError !== '' ? 'aria-invalid="true" aria-describedby="password-error"' : '' ?>
                     required>
              <button class="password-toggle-btn"
                      type="button"
                      data-toggle-password
                      aria-controls="password"
                      aria-pressed="false"
                      aria-label="Show password">
                <span class="eye-icon-open d-flex align-items-center" aria-hidden="true">
                  <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                    <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/>
                    <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>
                  </svg>
                </span>
                <span class="eye-icon-closed d-none align-items-center" aria-hidden="true">
                  <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                    <path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7 7 0 0 0-2.79.588l.77.771A6 6 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755q-.247.248-.517.486z"/>
                    <path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829zm-4.708 2.272L5.3 10.16a3.5 3.5 0 0 1-1.477-1.477L2.537 7.397q-.37.49-.691.956a13 13 0 0 0 1.66 2.043C4.12 11.332 5.88 12.5 8 12.5c1.69 0 3.208-.737 4.359-1.876l-.771-.77a6 6 0 0 1-5.176 1.488z"/>
                    <path d="M0 1.5 1.5 0l14.5 14.5L14.5 16 0 1.5z"/>
                  </svg>
                </span>
              </button>
            </div>
            <?php if ($passwordError !== ''): ?>
              <div id="password-error" class="invalid-feedback d-block"><?= esc($passwordError) ?></div>
            <?php endif; ?>
          </div>

          <!-- Submit Button -->
          <div class="d-grid">
            <button type="submit" class="btn btn-primary auth-submit-btn">
              Sign in
            </button>
          </div>
        </form>

        <!-- Socials Dock directly below Sign in -->
        <nav aria-label="Socials" class="w-100 d-flex flex-column align-items-center gap-2 auth-socials">
          <div class="d-flex w-100 align-items-center gap-2 auth-socials-heading">
            <span class="flex-grow-1 bg-secondary opacity-25 auth-socials-divider"></span>
            <span class="text-muted text-uppercase fw-semibold auth-socials-title">
              Connect with us
            </span>
            <span class="flex-grow-1 bg-secondary opacity-25 auth-socials-divider"></span>
          </div>

          <div class="social-dock">
            <!-- 1. Guidance Center -->
            <a href="https://www.facebook.com/profile.php?id=100063778576372"
               target="_blank"
               rel="noopener noreferrer"
               aria-label="Guidance Center on Facebook (opens in a new tab)"
               class="social-item"
               data-bs-toggle="tooltip"
               data-bs-placement="top"
               data-bs-html="true"
               data-bs-title="<span class='d-block fw-semibold'>Guidance Center on Facebook</span><span class='d-block' style='font-size:0.65rem;opacity:0.8;'>Opens in a new tab</span>">
              <span class="social-seal">
                <img src="<?= base_url('assets/img/brand/guidance-center.png') ?>" alt="" draggable="false" data-no-contextmenu>
              </span>
              <span class="social-label">Guidance</span>
            </a>

            <!-- 2. Foundation University -->
            <a href="https://www.facebook.com/foundationu.edu"
               target="_blank"
               rel="noopener noreferrer"
               aria-label="Foundation University on Facebook (opens in a new tab)"
               class="social-item"
               data-bs-toggle="tooltip"
               data-bs-placement="top"
               data-bs-html="true"
               data-bs-title="<span class='d-block fw-semibold'>Foundation University on Facebook</span><span class='d-block' style='font-size:0.65rem;opacity:0.8;'>Opens in a new tab</span>">
              <span class="social-seal">
                <img src="<?= base_url('assets/img/brand/FoundationU.png') ?>" alt="" draggable="false" data-no-contextmenu>
              </span>
              <span class="social-label">FoundationU</span>
            </a>

            <!-- 3. Health Services -->
            <a href="https://www.facebook.com/profile.php?id=100057107094031"
               target="_blank"
               rel="noopener noreferrer"
               aria-label="Health Services on Facebook (opens in a new tab)"
               class="social-item"
               data-bs-toggle="tooltip"
               data-bs-placement="top"
               data-bs-html="true"
               data-bs-title="<span class='d-block fw-semibold'>Health Services on Facebook</span><span class='d-block' style='font-size:0.65rem;opacity:0.8;'>Opens in a new tab</span>">
              <span class="social-seal">
                <img src="<?= base_url('assets/img/brand/health-services.png') ?>" alt="" draggable="false" data-no-contextmenu>
              </span>
              <span class="social-label">Health Care</span>
            </a>
          </div>
        </nav>

        <p class="auth-help text-center text-muted mb-0">
          Trouble signing in? Contact your system administrator.
        </p>
      </div>

    </section>

  </div>
</main>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/pages/auth-login.js') ?>"></script>
<?= $this->endSection() ?>
