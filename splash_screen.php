<?php
/**
 * Velveth Lending — Branded Splash Screen
 *
 * A short, full-screen loading splash shown with the VLC logo when the app
 * is first opened (fresh tab / launched from the home-screen icon). It
 * shows once per browser session — internal navigation between pages
 * within the same session won't re-trigger it.
 *
 * Usage: <?php include 'splash_screen.php'; ?>  right after the opening
 * <body> tag of an entry-point page (home.php, customer_dashboard.php,
 * admin_dashboard.php).
 */
?>
<div id="vlcSplash" class="vlc-splash">
  <div class="vlc-splash-logo-wrap">
    <img src="assets/images/logo.png" alt="Velveth Lending" class="vlc-splash-logo"
         onerror="this.style.display='none';this.parentElement.textContent='VLC';"/>
  </div>
  <div class="vlc-splash-brand">Velveth <span>Lending</span></div>
  <div class="vlc-splash-tagline">Fast, reliable, and trusted loans</div>
  <div class="vlc-splash-loader"></div>
</div>

<style>
  .vlc-splash {
    position: fixed; inset: 0; z-index: 99999;
    background: linear-gradient(160deg, var(--maroon-deep) 0%, var(--maroon) 50%, var(--maroon-light) 100%);
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    transition: opacity .4s ease, visibility .4s ease;
  }
  .vlc-splash.vlc-splash-hide { opacity: 0; visibility: hidden; pointer-events: none; }
  .vlc-splash-logo-wrap {
    width: 96px; height: 96px; border-radius: 26px;
    background: rgba(255,255,255,.15);
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 18px;
    border: 2px solid rgba(255,255,255,.25);
    animation: vlcSplashPulse 1.6s ease-in-out infinite;
    color: #fff; font-weight: 900; font-size: 28px;
  }
  .vlc-splash-logo { width: 60px; height: 60px; object-fit: contain; }
  .vlc-splash-brand { font-size: 24px; font-weight: 900; color: #fff; letter-spacing: .3px; }
  .vlc-splash-brand span { color: rgba(255,255,255,.7); font-weight: 400; }
  .vlc-splash-tagline { font-size: 12.5px; color: rgba(255,255,255,.65); margin-top: 6px; }
  .vlc-splash-loader {
    margin-top: 28px; width: 34px; height: 34px; border-radius: 50%;
    border: 3px solid rgba(255,255,255,.25); border-top-color: #fff;
    animation: vlcSplashSpin .8s linear infinite;
  }
  @keyframes vlcSplashPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.06); } }
  @keyframes vlcSplashSpin { to { transform: rotate(360deg); } }
</style>

<script>
(function () {
  var splash = document.getElementById('vlcSplash');
  if (!splash) return;

  // Only show once per browser session — internal navigation to other
  // pages in the same session skips it so it doesn't interrupt normal use.
  try {
    if (sessionStorage.getItem('vlc_splash_shown')) {
      splash.style.display = 'none';
      return;
    }
    sessionStorage.setItem('vlc_splash_shown', '1');
  } catch (e) {
    // sessionStorage unavailable (e.g. private mode) — just show it once and move on.
  }

  window.addEventListener('load', function () {
    setTimeout(function () {
      splash.classList.add('vlc-splash-hide');
      setTimeout(function () { splash.remove(); }, 450);
    }, 700);
  });
})();
</script>
