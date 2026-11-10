<?php
/**
 * Mobile Bottom Tab Bar
 * Requires: $conn, $user_id, $current_page (basename of current file)
 */
$_unread = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$user_id AND is_read=0")->fetch_assoc()['c'];
$_pens   = (int)$conn->query("SELECT COUNT(*) c FROM penalties WHERE customer_id=$user_id AND status IN ('pending','unpaid')")->fetch_assoc()['c'];
$_cur    = $current_page ?? basename($_SERVER['PHP_SELF']);
?>
<nav class="tabbar">
  <a href="customer_dashboard.php" class="tab <?= $_cur==='customer_dashboard.php'?'active':'' ?>">
    <span class="tab-icon">🏠</span><span>Home</span>
  </a>
  <a href="customer_loan.php" class="tab <?= $_cur==='customer_loan.php'?'active':'' ?>">
    <span class="tab-icon">💰</span><span>Loan</span>
  </a>
  <a href="customer_apply.php" class="tab tab-center <?= $_cur==='customer_apply.php'?'active':'' ?>">
    <span class="tab-icon">📝</span><span>Apply</span>
  </a>
  <a href="customer_penalties.php" class="tab <?= $_cur==='customer_penalties.php'?'active':'' ?>">
    <span class="tab-icon" style="position:relative;">⚠️
      <?php if($_pens>0):?><span class="tab-badge"><?= min($_pens,9) ?></span><?php endif; ?>
    </span><span>Penalties</span>
  </a>
  <a href="customer_profile.php" class="tab <?= $_cur==='customer_profile.php'?'active':'' ?>">
    <span class="tab-icon">👤</span><span>Profile</span>
  </a>
</nav>
