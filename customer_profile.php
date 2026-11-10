<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'customer') {
    header("Location: customer_login.php"); exit;
}
$user_id = $_SESSION['user_id'];

// ── POST → Process → Redirect (PRG pattern prevents double-save on refresh) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update_profile';
    $msg    = '';

    if ($action === 'update_profile') {
        $name  = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $addr  = trim($_POST['address'] ?? '');
        if (empty($name)) {
            $msg = 'error:Full name cannot be empty.';
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name=?, phone=?, address=? WHERE id=?");
            $stmt->bind_param("sssi", $name, $phone, $addr, $user_id);
            if ($stmt->execute()) {
                $_SESSION['fullname'] = $name;
                $msg = 'success:Profile updated successfully.';
            } else {
                $msg = 'error:Failed to update profile. Please try again.';
            }
            $stmt->close();
        }
    }

    if ($action === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        if (strlen($new_pass) < 6) {
            $msg = 'error:New password must be at least 6 characters.';
        } elseif ($new_pass !== $confirm) {
            $msg = 'error:New passwords do not match.';
        } else {
            $row = $conn->query("SELECT password FROM users WHERE id=$user_id")->fetch_assoc();
            if (!password_verify($current, $row['password'])) {
                $msg = 'error:Current password is incorrect.';
            } else {
                $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
                $stmt->bind_param("si", $hash, $user_id);
                $msg  = $stmt->execute() ? 'success:Password changed successfully.' : 'error:Failed to change password.';
                $stmt->close();
            }
        }
    }

    header("Location: customer_profile.php?msg=".urlencode($msg));
    exit;
}

// ── GET ──
$customer = $conn->query("SELECT * FROM users WHERE id=$user_id")->fetch_assoc();
$msg      = isset($_GET['msg']) ? urldecode($_GET['msg']) : '';
[$mtype, $mtext] = $msg ? explode(':', $msg, 2) : ['', ''];

$_cur = 'customer_profile.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>My Profile | Velveth</title>
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
</head>
<body class="app-shell">

  <header class="app-bar maroon">
    <a href="customer_dashboard.php" class="app-bar-back">←</a>
    <div style="flex:1;min-width:0;">
      <div class="app-bar-title">My Profile</div>
      <div class="app-bar-sub">Account settings</div>
    </div>
  </header>

  <main class="app-screen">

    <!-- Flash message -->
    <?php if ($mtext): ?>
    <div class="app-card" style="background:<?= $mtype==='success'?'#d1fae5':'#fee2e2' ?>;color:<?= $mtype==='success'?'#047857':'#b91c1c' ?>;font-size:12.5px;font-weight:600;display:flex;gap:8px;align-items:center;">
      <?= $mtype==='success'?'✅':'❌' ?> <?= htmlspecialchars($mtext) ?>
    </div>
    <?php endif; ?>

    <!-- Profile Hero -->
    <div class="profile-hero">
      <div class="profile-avatar-lg"><?= strtoupper(substr($customer['full_name'],0,2)) ?></div>
      <div style="font-size:17px;font-weight:800;margin-bottom:3px;"><?= htmlspecialchars($customer['full_name']) ?></div>
      <div style="font-size:12px;opacity:.8;"><?= htmlspecialchars($customer['email']) ?></div>
      <div style="margin-top:10px;">
        <span style="background:rgba(255,255,255,.15);padding:4px 12px;border-radius:100px;font-size:11px;font-weight:700;">
          🆔 Customer · Member since <?= date('M Y', strtotime($customer['created_at'])) ?>
        </span>
      </div>
    </div>

    <!-- Edit Profile -->
    <div class="app-section-title">Personal Information</div>
    <div class="app-card">
      <form method="POST">
        <input type="hidden" name="action" value="update_profile"/>
        <div class="app-form-group">
          <label class="app-form-label">Full Name *</label>
          <input type="text" name="full_name" class="app-input"
                 value="<?= htmlspecialchars($customer['full_name']) ?>" required/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Email Address</label>
          <input type="email" class="app-input"
                 value="<?= htmlspecialchars($customer['email']) ?>" disabled
                 style="opacity:.6;"/>
          <div style="font-size:10.5px;color:var(--gray-400);margin-top:3px;">Email cannot be changed. Contact support.</div>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Phone Number</label>
          <input type="tel" name="phone" class="app-input"
                 value="<?= htmlspecialchars($customer['phone'] ?? '') ?>" placeholder="+63 9XX XXX XXXX"/>
        </div>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Home Address</label>
          <textarea name="address" class="app-textarea" rows="2"
                    placeholder="House No., Street, Barangay, City"><?= htmlspecialchars($customer['address'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="margin-top:14px;">Save Changes</button>
      </form>
    </div>

    <!-- Change Password -->
    <div class="app-section-title">Change Password</div>
    <div class="app-card">
      <form method="POST">
        <input type="hidden" name="action" value="change_password"/>
        <div class="app-form-group">
          <label class="app-form-label">Current Password</label>
          <input type="password" name="current_password" class="app-input" placeholder="Enter current password" required/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">New Password</label>
          <input type="password" name="new_password" class="app-input" placeholder="Min. 6 characters" minlength="6" required/>
        </div>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Confirm New Password</label>
          <input type="password" name="confirm_password" class="app-input" placeholder="Repeat new password" minlength="6" required/>
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="margin-top:14px;">Change Password</button>
      </form>
    </div>

    <!-- Account Info -->
    <div class="app-section-title">Account Information</div>
    <div class="app-card-flat">
      <div class="settings-row">
        <div class="settings-icon" style="background:#ede9fe;">🆔</div>
        <span class="settings-label">Customer ID</span>
        <span class="settings-value">#<?= $user_id ?></span>
      </div>
      <div class="settings-row">
        <div class="settings-icon" style="background:#dcfce7;">📅</div>
        <span class="settings-label">Member Since</span>
        <span class="settings-value"><?= date('F Y', strtotime($customer['created_at'])) ?></span>
      </div>
      <div class="settings-row">
        <div class="settings-icon" style="background:#fef3c7;">🔔</div>
        <span class="settings-label">Email Notifications</span>
        <span class="settings-value" style="color:#10b981;font-weight:700;">Enabled</span>
      </div>
      <div class="settings-row" style="border-bottom:none;">
        <div class="settings-icon" style="background:#fee2e2;">🔐</div>
        <span class="settings-label">Account Status</span>
        <span class="settings-value" style="color:#10b981;font-weight:700;"><?= ucfirst($customer['status'] ?? 'Active') ?></span>
      </div>
    </div>

    <!-- Logout -->
    <a href="logout.php" class="app-btn app-btn-outline" style="margin-top:4px;margin-bottom:20px;color:#ef4444;border-color:#ef4444;">
      🚪 Logout
    </a>

  </main>

  <?php include 'app_tabbar.php'; ?>
  <?php include 'chatbot.php'; ?>
  <script src="realtime.js"></script>
</body>
</html>
