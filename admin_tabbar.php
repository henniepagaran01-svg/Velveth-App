<?php
/**
 * Admin Bottom Tab Bar — App Navigation
 * Requires: $conn, $_cur (current page basename)
 */
if (!isset($_cur)) $_cur = basename($_SERVER['PHP_SELF']);
$_tb_new_apps  = (int)$conn->query("SELECT COUNT(*) c FROM loan_applications WHERE status='pending'")->fetch_assoc()['c'];
$_tb_pend_pays = (int)$conn->query("SELECT COUNT(*) c FROM payments WHERE status='pending'")->fetch_assoc()['c'];
$_tb_notif     = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id IN (SELECT id FROM users WHERE role='admin') AND is_read=0")->fetch_assoc()['c'];
$_tb_docs      = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE status='pending'")->fetch_assoc()['c'];
$_tb_more_badge = $_tb_notif; // most actionable signal for the More hub
$_tb_more_pages = ['admin_penalties.php','admin_documents.php','admin_reports.php','admin_notifications.php','admin_users.php','admin_audit_trail.php','admin_archive.php','admin_more.php'];
?>
<nav class="tab-bar">
  <a href="admin_dashboard.php" class="tab-item <?= $_cur==='admin_dashboard.php'?'active':'' ?>">
    <span class="tab-icon">⊞</span><span>Home</span>
  </a>
  <a href="admin_customers.php" class="tab-item <?= $_cur==='admin_customers.php'?'active':'' ?>">
    <span class="tab-icon">👥</span><span>Customers</span>
  </a>
  <a href="admin_loan_applicants.php" class="tab-item <?= $_cur==='admin_loan_applicants.php'?'active':'' ?>">
    <span class="tab-icon" style="position:relative;">📋
      <?php if($_tb_new_apps>0): ?><span class="tab-badge"><?= min($_tb_new_apps,9) ?></span><?php endif; ?>
    </span><span>Loans</span>
  </a>
  <a href="admin_payments.php" class="tab-item <?= $_cur==='admin_payments.php'?'active':'' ?>">
    <span class="tab-icon" style="position:relative;">💳
      <?php if($_tb_pend_pays>0): ?><span class="tab-badge"><?= min($_tb_pend_pays,9) ?></span><?php endif; ?>
    </span><span>Payments</span>
  </a>
  <a href="admin_more.php" class="tab-item <?= in_array($_cur,$_tb_more_pages)?'active':'' ?>">
    <span class="tab-icon" style="position:relative;">☰
      <?php if($_tb_more_badge>0): ?><span class="tab-badge"><?= min($_tb_more_badge,9) ?></span><?php endif; ?>
    </span><span>More</span>
  </a>
</nav>
