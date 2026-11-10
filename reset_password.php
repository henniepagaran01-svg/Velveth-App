<?php
session_start();
require_once 'config.php';
ensureResetTokenColumns($conn);

$rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error    = "";
$done     = false;
$user     = null;

if ($rawToken === '') {
    $error = "Missing reset token.";
} else {
    $hashedToken = hash('sha256', $rawToken);
    $stmt = $conn->prepare(
        "SELECT id, full_name, email, role FROM users
         WHERE reset_token = ? AND reset_token_expires > NOW() AND status = 'active'"
    );
    if (!$stmt) {
        error_log('[Velveth] reset_password.php: failed to prepare token SELECT — ' . $conn->error);
        $error = "Something went wrong. Please request a new reset link.";
    } else {
        $stmt->bind_param("s", $hashedToken);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $error = "This reset link is invalid or has expired. Please request a new one.";
        } else {
            $user = $result->fetch_assoc();
        }
    }
}

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 9) {
        $error = "Password must be at least 9 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upd = $conn->prepare(
            "UPDATE users SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?"
        );
        if ($upd) {
            $upd->bind_param("si", $hash, $user['id']);
            $upd->execute();

            logAudit($conn, 'User', $user['full_name'], 'Password reset completed', 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

            notifyAccountUpdate(
                $user['email'],
                $user['full_name'],
                'Password Changed',
                'Your password was just reset. If this wasn\'t you, please contact us immediately.'
            );

            $done = true;
        } else {
            error_log('[Velveth] reset_password.php: failed to prepare password UPDATE — ' . $conn->error);
            $error = "Something went wrong updating your password. Please try again.";
        }
    }
}

$loginPage = ($user && $user['role'] === 'admin') ? 'admin_login.php' : 'customer_login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Reset Password | Velveth Lending</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
    html,body { background:linear-gradient(160deg,var(--maroon-deep) 0%,var(--maroon) 50%,var(--maroon-light) 100%) !important; min-height:100vh; max-width:100%; }
    .login-card { background:white;border-radius:24px 24px 0 0;padding:28px 22px 40px;min-height:60vh; margin-top:auto; box-shadow:0 -8px 40px rgba(0,0,0,.18); }
    .login-logo { text-align:center;padding:36px 20px 28px; }
    .login-logo-circle { width:72px;height:72px;border-radius:20px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;margin:0 auto 14px;border:2px solid rgba(255,255,255,.25); }
    .login-logo-circle img { width:44px;height:44px;object-fit:contain; }
    .login-brand { font-size:22px;font-weight:900;color:white; }
    .login-brand span { color:rgba(255,255,255,.7);font-weight:400; }
    .login-tagline { font-size:12.5px;color:rgba(255,255,255,.65);margin-top:4px; }
    body.app-shell { padding-bottom:0 !important; }
  </style>
</head>
<body class="app-shell" style="display:flex;flex-direction:column;min-height:100vh;">

  <!-- Logo Hero -->
  <div class="login-logo">
    <div class="login-logo-circle">
      <img src="assets/images/logo.png" alt="VLC" onerror="this.parentElement.textContent='VC'"/>
    </div>
    <div class="login-brand">Velveth <span>Lending</span></div>
    <div class="login-tagline">Fast, reliable, and trusted loans</div>
  </div>

  <!-- White Card -->
  <div class="login-card" style="flex:1;">

    <?php if ($done): ?>
      <div style="text-align:center;padding:20px 0 10px;">
        <div style="font-size:44px;margin-bottom:14px;">✅</div>
        <h2 style="font-size:19px;font-weight:800;color:var(--text-dark);margin-bottom:8px;">Password updated</h2>
        <p style="font-size:13px;color:var(--gray-400);line-height:1.6;margin-bottom:26px;">
          Your password has been changed successfully. You can now sign in with your new password.
        </p>
        <a href="<?= htmlspecialchars($loginPage) ?>" class="app-btn app-btn-primary" style="font-size:15px;display:block;">Sign In</a>
      </div>

    <?php elseif (!$user): ?>
      <div style="text-align:center;padding:20px 0 10px;">
        <div style="font-size:44px;margin-bottom:14px;">⚠️</div>
        <h2 style="font-size:19px;font-weight:800;color:var(--text-dark);margin-bottom:8px;">Link invalid or expired</h2>
        <p style="font-size:13px;color:var(--gray-400);line-height:1.6;margin-bottom:26px;">
          <?= htmlspecialchars($error) ?>
        </p>
        <a href="forgot_password.php" class="app-btn app-btn-primary" style="font-size:15px;display:block;">Request a New Link</a>
      </div>

    <?php else: ?>
      <h2 style="font-size:20px;font-weight:800;color:var(--text-dark);margin-bottom:4px;">Set a new password 🔑</h2>
      <p style="font-size:13px;color:var(--gray-400);margin-bottom:22px;">
        Hi <strong><?= htmlspecialchars($user['full_name']) ?></strong> — choose a new password for your account.
      </p>

      <?php if ($error): ?>
      <div style="background:#fee2e2;color:#b91c1c;padding:11px 14px;border-radius:12px;font-size:12.5px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
        ❌ <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="token" value="<?= htmlspecialchars($rawToken) ?>"/>

        <div class="app-form-group">
          <label class="app-form-label">New Password</label>
          <div style="position:relative;">
            <input type="password" name="password" id="pw1" class="app-input" required minlength="9"
                   placeholder="At least 9 characters" autocomplete="new-password" style="padding-right:44px;"/>
            <button type="button" onclick="togglePw('pw1','eye1')" id="eye1"
                    style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;padding:0;display:flex;cursor:pointer;color:var(--gray-400);">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <div class="app-form-group">
          <label class="app-form-label">Confirm New Password</label>
          <div style="position:relative;">
            <input type="password" name="confirm_password" id="pw2" class="app-input" required minlength="9"
                   placeholder="Re-enter new password" autocomplete="new-password" style="padding-right:44px;"/>
            <button type="button" onclick="togglePw('pw2','eye2')" id="eye2"
                    style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;padding:0;display:flex;cursor:pointer;color:var(--gray-400);">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <button type="submit" class="app-btn app-btn-primary" style="font-size:15px;margin-top:8px;">Update Password</button>
      </form>
    <?php endif; ?>

  </div>

<script>
const EYE_OPEN = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>';
const EYE_OFF  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 1 12s4 8 11 8a9.26 9.26 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>';
function togglePw(fieldId, eyeId) {
  const f = document.getElementById(fieldId);
  const e = document.getElementById(eyeId);
  if (f.type === 'password') { f.type = 'text'; e.innerHTML = EYE_OFF; }
  else { f.type = 'password'; e.innerHTML = EYE_OPEN; }
}
</script>
  <script>if("serviceWorker" in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register("/sw.js").catch(function(){});});}</script>
  <script src="assets/js/form-guard.js"></script>
</body>
</html>
