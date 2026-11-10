<?php
session_start();
require_once 'config.php';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if ($_SESSION['role'] === 'admin') header("Location: admin_dashboard.php");
    else header("Location: customer_dashboard.php");
    exit;
}

$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (empty($email) || empty($password)) {
        $error = "Please enter your email and password.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND role = 'customer'");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            if (!empty($user['deleted_at'])) {
                $error = "This account is no longer active. Please contact Velveth Lending support.";
            } elseif (password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['fullname']  = $user['full_name'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['logged_in'] = true;

                // Login alert email -- best-effort, never blocks the login
                // itself if it fails or is slow.
                if (function_exists('notifyLoginDetected') && !empty($user['email'])) {
                    @notifyLoginDetected(
                        $user['email'],
                        $user['full_name'],
                        date('F j, Y g:i A'),
                        $_SERVER['REMOTE_ADDR'] ?? ''
                    );
                }

                header("Location: customer_dashboard.php");
                exit;
            }
        }
        if (empty($error)) $error = "Invalid email or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Sign In | Velveth Lending</title>
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
    <h2 style="font-size:20px;font-weight:800;color:var(--text-dark);margin-bottom:4px;">Welcome back 👋</h2>
    <p style="font-size:13px;color:var(--gray-400);margin-bottom:22px;">Sign in to your account to continue</p>

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
      <div class="app-form-group" style="margin-bottom:6px;">
        <label class="app-form-label">Password</label>
        <div style="position:relative;">
          <input type="password" name="password" id="pwField" class="app-input"
                 placeholder="Enter your password" required autocomplete="current-password"
                 style="padding-right:44px;"/>
          <button type="button" onclick="togglePw()" id="pwEye"
                  style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;padding:0;display:flex;cursor:pointer;color:var(--gray-400);">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>
      <div style="text-align:right;margin-bottom:20px;">
        <a href="forgot_password.php" style="font-size:12px;color:var(--maroon);font-weight:700;">Forgot password?</a>
      </div>
      <button type="submit" class="app-btn app-btn-primary" style="font-size:15px;">Sign In</button>
    </form>

    <div style="text-align:center;margin-top:20px;font-size:13px;color:var(--gray-400);">
      Don't have an account?
      <a href="customer_register.php" style="color:var(--maroon);font-weight:800;"> Register</a>
    </div>

    <div style="text-align:center;margin-top:14px;font-size:11.5px;color:var(--gray-400);">
      Are you an admin? <a href="admin_login.php" style="color:var(--maroon);font-weight:700;">Admin Login →</a>
    </div>
  </div>

<script>
const EYE_OPEN = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>';
const EYE_OFF  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 1 12s4 8 11 8a9.26 9.26 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>';
function togglePw() {
  const f = document.getElementById('pwField');
  const e = document.getElementById('pwEye');
  if (f.type === 'password') { f.type = 'text'; e.innerHTML = EYE_OFF; }
  else { f.type = 'password'; e.innerHTML = EYE_OPEN; }
}
</script>
  <script>if("serviceWorker" in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register("/sw.js").catch(function(){});});}</script>
  <script src="assets/js/form-guard.js"></script>
</body>
</html>
