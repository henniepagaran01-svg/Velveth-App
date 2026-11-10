<?php
// Offline fallback page — shown by service worker when page not cached
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Offline | Velveth Lending</title>
  <link rel="stylesheet" href="shared.css"/>
  <link rel="stylesheet" href="mobile-app.css"/>
  <link rel="manifest" href="manifest.json"/>
  <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32.png"/>
  <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png"/>
  <meta name="apple-mobile-web-app-capable" content="yes"/>
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
  <meta name="apple-mobile-web-app-title" content="VLC App"/>
  <meta name="mobile-web-app-capable" content="yes"/>
  <style>
    body { min-height:100vh; display:flex; align-items:center; justify-content:center; background:linear-gradient(160deg,#1a0810,#3d0d15,#5c1520); }
    .offline-card { background:white; border-radius:24px; padding:36px 28px; text-align:center; max-width:340px; margin:20px; box-shadow:0 20px 60px rgba(0,0,0,.3); }
    .offline-icon { font-size:64px; margin-bottom:16px; }
    .offline-title { font-size:20px; font-weight:800; color:#1a1a2e; margin-bottom:8px; }
    .offline-sub { font-size:13.5px; color:#6b7280; line-height:1.6; margin-bottom:24px; }
    .retry-btn { background:linear-gradient(135deg,#8B2A3A,#c0394d); color:white; border:none; padding:14px 32px; border-radius:14px; font-size:15px; font-weight:700; cursor:pointer; width:100%; }
    .retry-btn:active { opacity:.85; }
  </style>
</head>
<body>
  <div class="offline-card">
    <div class="offline-icon">📡</div>
    <div class="offline-title">You're Offline</div>
    <div class="offline-sub">
      No internet connection detected.<br>
      Please check your connection and try again.
    </div>
    <button class="retry-btn" onclick="window.location.reload()">🔄 Try Again</button>
    <div style="margin-top:20px;">
      <img src="assets/images/logo.png" style="height:32px;opacity:.4;" onerror="this.style.display='none'"/>
    </div>
    <div style="font-size:11px;color:#d1d5db;margin-top:8px;">Velveth Lending App</div>
  </div>
</body>
</html>
