<?php
// Admin notification bell — include in top-header header-right
$_bell = (int)$conn->query("
  SELECT COUNT(*) c FROM notifications
  WHERE user_id IN (SELECT id FROM users WHERE role='admin') AND is_read=0
")->fetch_assoc()['c'];
?>
<a href="admin_notifications.php" class="notif-btn">
  🔔<?php if($_bell>0):?><span class="notif-badge"><?=min($_bell,99)?></span><?php endif;?>
</a>
