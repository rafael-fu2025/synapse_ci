<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<!-- Floating Theme Toggle in Upper Right of Login Screen -->
<div class="position-fixed top-0 end-0 p-3" style="z-index: 1050;">
  <button class="btn btn-sm rounded-circle d-flex align-items-center justify-content-center shadow-sm auth-theme-toggle"
          type="button"
          id="authThemeToggle"
          data-bs-title="Toggle Theme (Light / Dark mode)"
          data-bs-toggle="tooltip"
          data-bs-placement="bottom"
          aria-label="Toggle dark mode"
          style="width: 42px; height: 42px;">
    <i class="bi bi-moon-stars theme-icon-dark fs-5"></i>
    <i class="bi bi-sun theme-icon-light fs-5"></i>
  </button>
</div>

<main class="container-fluid p-0 min-vh-100 auth-main-wrapper">
  <div class="row g-0 min-vh-100">

    <!-- Left 60% Pane: 3-Slide Photo Carousel (hidden on small viewports) -->
    <aside class="col-lg-7 d-none d-lg-block p-2 min-vh-100 auth-photo-aside">
      <div class="w-100 rounded-4 overflow-hidden position-relative auth-photo-container">

        <!-- Bootstrap 5 Carousel: auto-plays every 6 seconds, no arrows, pill indicators -->
        <div id="loginPhotoCarousel"
             class="carousel carousel-fade slide h-100 w-100"
             data-bs-ride="carousel"
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
                       draggable="false" oncontextmenu="return false;">
                  <img src="<?= $darkSrc ?>"
                       alt=""
                       class="no-select auth-slide-img-bg synapse-logo-dark"
                       draggable="false" oncontextmenu="return false;">
                <?php endif; ?>

                <!-- Light mode image (cover = crisp full-bleed, else contained) -->
                <img src="<?= $lightSrc ?>"
                     alt="<?= esc($slide['title'] ?? '') ?>"
                     class="no-select <?= $lightCls ?> synapse-logo-light"
                     draggable="false" oncontextmenu="return false;">

                <!-- Dark mode image -->
                <img src="<?= $darkSrc ?>"
                     alt="<?= esc($slide['title'] ?? '') ?> (Night)"
                     class="no-select <?= $darkCls ?> synapse-logo-dark"
                     draggable="false" oncontextmenu="return false;">

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
        </div><!-- /#loginPhotoCarousel -->
      </div>
    </aside>

    <!-- Right 40% Pane: Centered Sign-in Form with Pinned Socials Row at Bottom -->
    <section class="col-12 col-lg-5 d-flex flex-column justify-content-between align-items-center p-4 p-sm-5 min-vh-100 auth-pane position-relative">

      <!-- Top Spacer -->
      <div class="w-100" style="height: 1rem;"></div>

      <!-- Center Form Container (max-width: 380px) -->
      <div class="w-100 my-auto" style="max-width: 380px;">

        <!-- Brand Mark & Headline -->
        <div class="text-center mb-4">
          <?php $pMeta = $portalMeta ?? \App\Services\PortalContext::metadata(); ?>
          <?php $currentP = $portal ?? \App\Services\PortalContext::current(); ?>

          <div class="mb-3 d-flex justify-content-center align-items-center" style="min-height: 56px;">
            <?php if ($currentP === 'main'): ?>
              <img src="<?= base_url('assets/img/brand/synapse-maroon.png') ?>"
                   alt="SYNAPSE Logo"
                   class="no-select synapse-logo-light"
                   style="height: 56px; width: auto; object-fit: contain;"
                   draggable="false"
                   oncontextmenu="return false;">

              <img src="<?= base_url('assets/img/brand/synapse-white.png') ?>"
                   alt="SYNAPSE Logo"
                   class="no-select synapse-logo-dark"
                   style="height: 56px; width: auto; object-fit: contain;"
                   draggable="false"
                   oncontextmenu="return false;">
            <?php else: ?>
              <img src="<?= base_url($pMeta['logo']) ?>"
                   alt="Portal Mark"
                   class="no-select"
                   style="height: 56px; width: auto; object-fit: contain;"
                   draggable="false"
                   oncontextmenu="return false;">
            <?php endif; ?>
          </div>

          <?php if ($currentP !== 'main'): ?>
            <div class="mb-2">
              <span class="badge bg-primary text-white text-uppercase" style="letter-spacing: 0.05rem; font-size: 0.72rem;">
                <?= esc($pMeta['badge']) ?>
              </span>
            </div>
          <?php endif; ?>

          <h1 id="login-title" class="h3 fw-bold mb-1 text-dark">
            <?= ($currentP === 'main') ? 'Welcome back' : esc($pMeta['title']) ?>
          </h1>
          <p class="text-muted small mb-0">
            <?= ($currentP === 'main') ? 'Access university health, guidance, and composting services.' : 'Department Operational Console · Authorized Staff Only' ?>
          </p>
        </div>

        <!-- Alert messages -->
        <?= $this->include('partials/alerts') ?>

        <!-- Sign-in Form -->
        <form action="<?= site_url('/auth/login') ?>" method="POST" novalidate class="mt-4">
          <?= csrf_field() ?>

          <!-- ID Number -->
          <div class="mb-3">
            <label for="identifier" class="form-label small fw-semibold text-secondary mb-1">ID Number</label>
            <input type="text"
                   class="form-control py-2"
                   id="identifier"
                   name="identifier"
                   placeholder="Enter your ID number"
                   value="<?= esc(old('identifier')) ?>"
                   autocomplete="username"
                   required
                   autofocus>
          </div>

          <!-- Password with seamless internal show/hide toggle -->
          <div class="mb-4">
            <label for="password" class="form-label small fw-semibold text-secondary mb-1">Password</label>
            <div class="password-field-wrapper">
              <input type="password"
                     class="form-control py-2"
                     id="password"
                     name="password"
                     placeholder="Enter your password"
                     autocomplete="current-password"
                     required>
              <button class="password-toggle-btn"
                      type="button"
                      data-toggle-password
                      aria-label="Toggle password visibility">
                <span id="eye-icon-open" class="d-flex align-items-center">
                  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/>
                    <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>
                  </svg>
                </span>
                <span id="eye-icon-closed" class="d-none align-items-center">
                  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7 7 0 0 0-2.79.588l.77.771A6 6 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755q-.247.248-.517.486z"/>
                    <path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829zm-4.708 2.272L5.3 10.16a3.5 3.5 0 0 1-1.477-1.477L2.537 7.397q-.37.49-.691.956a13 13 0 0 0 1.66 2.043C4.12 11.332 5.88 12.5 8 12.5c1.69 0 3.208-.737 4.359-1.876l-.771-.77a6 6 0 0 1-5.176 1.488z"/>
                    <path d="M0 1.5 1.5 0l14.5 14.5L14.5 16 0 1.5z"/>
                  </svg>
                </span>
              </button>
            </div>
          </div>

          <!-- Submit Button -->
          <div class="d-grid mb-3">
            <button type="submit" class="btn btn-primary shadow-sm">
              Sign in
            </button>
          </div>
        </form>

        <p class="mt-4 text-center text-muted" style="font-size: 0.8rem;">
          Trouble signing in? Contact your system administrator.
        </p>
      </div>

      <!-- Bottom Pinned Socials Dock -->
      <nav aria-label="Socials" class="w-100 d-flex flex-column align-items-center gap-2 pt-4 pb-2" style="max-width: 380px;">
        <div class="d-flex w-100 align-items-center gap-2 mb-2">
          <span class="flex-grow-1 bg-secondary opacity-25" style="height: 1px;"></span>
          <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.65rem; letter-spacing: 0.05rem;">
            Connect with us
          </span>
          <span class="flex-grow-1 bg-secondary opacity-25" style="height: 1px;"></span>
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
              <img src="<?= base_url('assets/img/brand/guidance-center.png') ?>" alt="" draggable="false" oncontextmenu="return false;">
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
              <img src="<?= base_url('assets/img/brand/FoundationU.png') ?>" alt="" draggable="false" oncontextmenu="return false;">
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
              <img src="<?= base_url('assets/img/brand/health-services.png') ?>" alt="" draggable="false" oncontextmenu="return false;">
            </span>
            <span class="social-label">Health Care</span>
          </a>
        </div>
      </nav>

    </section>

  </div>
</main>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
  document.addEventListener('DOMContentLoaded', function() {
    // Password toggle
    document.querySelectorAll('[data-toggle-password]').forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        const wrapper = btn.closest('.password-field-wrapper') || btn.closest('.input-group');
        const input = wrapper.querySelector('input');
        const eyeOpen = btn.querySelector('#eye-icon-open');
        const eyeClosed = btn.querySelector('#eye-icon-closed');

        if (input.type === 'password') {
          input.type = 'text';
          if (eyeOpen) eyeOpen.classList.add('d-none');
          if (eyeClosed) eyeClosed.classList.remove('d-none');
        } else {
          input.type = 'password';
          if (eyeOpen) eyeOpen.classList.remove('d-none');
          if (eyeClosed) eyeClosed.classList.add('d-none');
        }
        input.focus();
      });
    });

    // Login screen theme toggle
    const authToggle = document.getElementById('authThemeToggle');
    if (authToggle) {
      authToggle.addEventListener('click', function(e) {
        e.preventDefault();
        const tip = bootstrap.Tooltip.getInstance(authToggle);
        if (tip) tip.hide();
        document.querySelectorAll('.tooltip').forEach(t => t.remove());

        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const nextTheme = isDark ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', nextTheme);
        localStorage.setItem('na-dark-mode', isDark ? '0' : '1');
      });
    }
  });
</script>
<?= $this->endSection() ?>
