<?php
session_start();
require_once 'config.php';
ensureResetTokenColumns($conn);

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if ($_SESSION['role'] === 'admin') header("Location: admin_dashboard.php");
    else header("Location: customer_dashboard.php");
    exit;
}

$error   = "";
$sent    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $stmt = $conn->prepare("SELECT id, full_name, email, role FROM users WHERE email = ? AND status = 'active'");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();

            // Generate a random token; only the SHA-256 hash is stored in the
            // database, so a leaked/compromised DB row alone can't be used
            // to reset the account (mirrors how the password hash works).
            $rawToken    = bin2hex(random_bytes(32));
            $hashedToken = hash('sha256', $rawToken);
            $expires     = date('Y-m-d H:i:s', time() + 3600); // 1 hour

            $upd = $conn->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
            if ($upd) {
                $upd->bind_param("ssi", $hashedToken, $expires, $user['id']);
                $upd->execute();

                $resetLink = BASE_URL . '/reset_password.php?token=' . $rawToken;

                notifyPasswordReset($user['email'], $user['full_name'], $resetLink);

                logAudit($conn, 'User', $user['full_name'], 'Password reset requested', 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            } else {
                error_log('[Velveth] forgot_password.php: failed to prepare reset_token UPDATE — ' . $conn->error);
            }
        }

        // Always show the same success message whether or not the email
        // exists, so this form can't be used to check which emails are
        // registered.
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Forgot Password | Velveth Lending</title>
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

    <?php if ($sent): ?>
      <div style="text-align:center;padding:20px 0 10px;">
        <div style="font-size:44px;margin-bottom:14px;">📩</div>
        <h2 style="font-size:19px;font-weight:800;color:var(--text-dark);margin-bottom:8px;">Check your email</h2>
        <p style="font-size:13px;color:var(--gray-400);line-height:1.6;margin-bottom:26px;">
          If an account exists for that email address, we've sent a link to reset your password.
          It'll expire in 1 hour.
        </p>
        <a href="customer_login.php" class="app-btn app-btn-primary" style="font-size:15px;display:block;">Back to Sign In</a>
      </div>
    <?php else: ?>
      <h2 style="font-size:20px;font-weight:800;color:var(--text-dark);margin-bottom:4px;">Forgot password? 🔑</h2>
      <p style="font-size:13px;color:var(--gray-400);margin-bottom:22px;">
        Enter the email on your account and we'll send you a link to reset your password.
      </p>

      <?php if ($error): ?>
      <div style="background:#fee2e2;color:#b91c1c;padding:11px 14px;border-radius:12px;font-size:12.5px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
        ❌ <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST">
        <div class="app-form-group">
          <label class="app-form-label">Email Address</label>
          <input type="email" name="email" class="app-input"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                 placeholder="your@email.com" required autocomplete="email"/>
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="font-size:15px;margin-top:8px;">Send Reset Link</button>
      </form>

      <div style="text-align:center;margin-top:20px;font-size:13px;color:var(--gray-400);">
        <a href="customer_login.php" style="color:var(--maroon);font-weight:800;">← Back to Sign In</a>
      </div>
    <?php endif; ?>

  </div>

  <script>if("serviceWorker" in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register("/sw.js").catch(function(){});});}</script>
  <script src="assets/js/form-guard.js"></script>
</body>
</html>
