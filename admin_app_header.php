<?php
/**
 * Admin Mobile App Header
 * Usage: include('admin_app_header.php');
 * Requires: $page_title, $conn, $current_page already set
 */
if (!isset($page_title)) $page_title = 'Admin';
$_hdr_notifs = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id={$_SESSION['user_id']} AND is_read=0")->fetch_assoc()['c'];
?>
<header class="admin-app-bar">
  <button class="admin-bar-menu" onclick="openAdminDrawer()">☰</button>
  <div class="admin-bar-title"><?= htmlspecialchars($page_title) ?></div>
  <a href="admin_notifications.php" class="admin-bar-action">
    🔔<?php if($_hdr_notifs>0):?><span class="admin-badge-dot"><?=min($_hdr_notifs,9)?></span><?php endif;?>
  </a>
  <div class="admin-bar-avatar"><?=strtoupper(substr($_SESSION['fullname'],0,2))?></div>
</header>
