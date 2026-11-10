<?php
/**
 * Bottom Tab Bar — Mobile App Navigation
 * Requires: $conn, $user_id, $_cur (current page basename)
 */
if (!isset($_cur)) $_cur = basename($_SERVER['PHP_SELF']);
$_unread = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$user_id AND is_read=0")->fetch_assoc()['c'];
$_pens   = (int)$conn->query("SELECT COUNT(*) c FROM penalties WHERE customer_id=$user_id AND status IN ('pending','unpaid')")->fetch_assoc()['c'];
?>
<nav class="tab-bar">
  <a href="customer_dashboard.php" class="tab-item <?= $_cur==='customer_dashboard.php'?'active':'' ?>">
    <span class="tab-icon"><?= $_cur==='customer_dashboard.php'?'🏠':'🏡' ?></span>
    <span>Home</span>
  </a>
  <a href="customer_loan.php" class="tab-item <?= $_cur==='customer_loan.php'?'active':'' ?>">
    <span class="tab-icon">💰</span>
    <span>My Loan</span>
  </a>
  <a href="customer_apply.php" class="tab-item center">
    <span class="tab-fab">📝</span>
  </a>
  <a href="customer_penalties.php" class="tab-item <?= $_cur==='customer_penalties.php'?'active':'' ?>">
    <span class="tab-icon">
      ⚠️
      <?php if($_pens>0): ?><span class="tab-badge"><?= min($_pens,9) ?></span><?php endif; ?>
    </span>
    <span>Penalties</span>
  </a>
  <a href="customer_profile.php" class="tab-item <?= $_cur==='customer_profile.php'?'active':'' ?>">
    <span class="tab-icon">👤</span>
    <span>Profile</span>
  </a>
</nav>
