<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php"); exit;
}
$_cur = 'admin_more.php';

$_pend_docs   = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE status='pending'")->fetch_assoc()['c'];
ensureMessagesTable($conn);
$_unread_msg = (int)$conn->query("SELECT COUNT(*) c FROM messages WHERE sender_role='customer' AND is_read=0")->fetch_assoc()['c'];
$_pend_pens   = (int)$conn->query("SELECT COUNT(*) c FROM penalties WHERE status IN ('pending','unpaid')")->fetch_assoc()['c'];
$_unread_notif= (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id IN (SELECT id FROM users WHERE role='admin') AND is_read=0")->fetch_assoc()['c'];
$_admin_count = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='admin'")->fetch_assoc()['c'];
$_archived    = (int)$conn->query("SELECT COUNT(*) c FROM loan_applications WHERE status='archived'")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>More | Velveth Admin</title>
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
    <div style="flex:1;min-width:0;">
      <div class="app-bar-title">More</div>
      <div class="app-bar-sub">Tools, reports &amp; settings</div>
    </div>
  </header>

  <main class="app-screen">

    <!-- Admin profile -->
    <div class="profile-hero">
      <div class="profile-avatar-lg"><?= strtoupper(substr($_SESSION['fullname'],0,2)) ?></div>
      <div style="font-size:17px;font-weight:800;margin-bottom:3px;"><?= htmlspecialchars($_SESSION['fullname']) ?></div>
      <div style="font-size:12px;opacity:.8;">Administrator</div>
    </div>

    <!-- Operations -->
    <div class="app-section-title">Operations</div>
    <div class="app-card-flat">
      <a href="admin_documents.php" class="app-list-item">
        <div class="app-list-icon" style="background:#fef3c7;">📄</div>
        <div class="app-list-body">
          <div class="app-list-title">Documents</div>
          <div class="app-list-sub">Review uploads &amp; qualify applicants</div>
        </div>
        <div class="app-list-end" style="display:flex;align-items:center;gap:8px;">
          <?php if ($_pend_docs>0): ?><span class="row-badge amber"><?= min($_pend_docs,99) ?></span><?php endif; ?>
          <span class="app-list-chevron">›</span>
        </div>
      </a>
      <a href="admin_messages.php" class="app-list-item">
        <div class="app-list-icon" style="background:#dbeafe;">💬</div>
        <div class="app-list-body">
          <div class="app-list-title">Customer Messages</div>
          <div class="app-list-sub">Message borrowers directly</div>
        </div>
        <div class="app-list-end" style="display:flex;align-items:center;gap:8px;">
          <?php if ($_unread_msg>0): ?><span class="row-badge"><?= min($_unread_msg,99) ?></span><?php endif; ?>
          <span class="app-list-chevron">›</span>
        </div>
      </a>
    </div>

    <!-- System -->
    <div class="app-section-title">System</div>
    <div class="app-card-flat">
      <a href="admin_notifications.php" class="app-list-item">
        <div class="app-list-icon" style="background:#ede9fe;">🔔</div>
        <div class="app-list-body">
          <div class="app-list-title">Notifications</div>
          <div class="app-list-sub">Alerts &amp; activity updates</div>
        </div>
        <div class="app-list-end" style="display:flex;align-items:center;gap:8px;">
          <?php if ($_unread_notif>0): ?><span class="row-badge"><?= min($_unread_notif,99) ?></span><?php endif; ?>
          <span class="app-list-chevron">›</span>
        </div>
      </a>
      <a href="admin_users.php" class="app-list-item">
        <div class="app-list-icon" style="background:#fce7f3;">🔧</div>
        <div class="app-list-body">
          <div class="app-list-title">Admin &amp; User Accounts</div>
          <div class="app-list-sub"><?= $_admin_count ?> admin<?= $_admin_count!=1?'s':'' ?> · manage all users</div>
        </div>
        <span class="app-list-chevron">›</span>
      </a>
      <a href="admin_archive.php" class="app-list-item">
        <div class="app-list-icon" style="background:var(--gray-100);">📦</div>
        <div class="app-list-body">
          <div class="app-list-title">Archive</div>
          <div class="app-list-sub">Restore or permanently remove records</div>
        </div>
        <div class="app-list-end" style="display:flex;align-items:center;gap:8px;">
          <?php if ($_archived>0): ?><span class="row-badge muted"><?= min($_archived,99) ?></span><?php endif; ?>
          <span class="app-list-chevron">›</span>
        </div>
      </a>
    </div>

    <!-- Account -->
    <div class="app-section-title">Account</div>
    <div class="app-card-flat">
      <div class="settings-row">
        <div class="settings-icon" style="background:#ede9fe;">🆔</div>
        <span class="settings-label">Logged in as</span>
        <span class="settings-value"><?= htmlspecialchars($_SESSION['fullname']) ?></span>
      </div>
      <div class="settings-row" style="border-bottom:none;">
        <div class="settings-icon" style="background:#dcfce7;">🔐</div>
        <span class="settings-label">Role</span>
        <span class="settings-value" style="color:#10b981;font-weight:700;">Administrator</span>
      </div>
    </div>

    <a href="logout.php" class="app-btn app-btn-outline" style="margin-top:4px;margin-bottom:20px;color:#ef4444;border-color:#ef4444;">
      🚪 Logout
    </a>

  </main>

  <?php include 'admin_tabbar.php'; ?>
  <script src="realtime.js"></script>
</body>
</html>
