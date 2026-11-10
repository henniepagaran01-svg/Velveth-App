<?php
/**
 * Admin Mobile Drawer + Bottom Tab Bar
 * Requires: $conn, $current_page (or falls back to basename)
 */
$_cur = isset($current_page) ? $current_page : basename($_SERVER['PHP_SELF']);
$_new_apps  = (int)$conn->query("SELECT COUNT(*) c FROM loan_applications WHERE status='pending'")->fetch_assoc()['c'];
$_pend_pays = (int)$conn->query("SELECT COUNT(*) c FROM payments WHERE status='pending'")->fetch_assoc()['c'];
$_adm_notif = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id IN (SELECT id FROM users WHERE role='admin') AND is_read=0")->fetch_assoc()['c'];

$nav = [
    ['href'=>'admin_dashboard.php',      'icon'=>'🏠','label'=>'Dashboard',    'group'=>'main'],
    ['href'=>'admin_customers.php',      'icon'=>'👥','label'=>'Customers',    'group'=>'main'],
    ['href'=>'admin_loan_applicants.php','icon'=>'📋','label'=>'Loan Applicants','badge'=>$_new_apps,'group'=>'main'],
    ['href'=>'admin_payments.php',       'icon'=>'💳','label'=>'Payments',     'badge'=>$_pend_pays,'group'=>'main'],
    ['href'=>'admin_penalties.php',      'icon'=>'⚠️','label'=>'Penalties',    'group'=>'main'],
    ['href'=>'admin_documents.php',      'icon'=>'📄','label'=>'Documents',    'group'=>'main'],
    ['href'=>'admin_reports.php',        'icon'=>'📊','label'=>'Reports',      'group'=>'reports'],
    ['href'=>'admin_notifications.php',  'icon'=>'🔔','label'=>'Notifications','badge'=>$_adm_notif,'group'=>'reports'],
    ['href'=>'admin_users.php',          'icon'=>'🔧','label'=>'Admin Users',  'group'=>'settings'],
    ['href'=>'admin_audit_trail.php',    'icon'=>'📝','label'=>'Audit Trail',  'group'=>'settings'],
    ['href'=>'admin_archive.php',        'icon'=>'📦','label'=>'Archive',      'group'=>'settings'],
];

$initials = strtoupper(substr($_SESSION['fullname'] ?? 'AD', 0, 2));
?>

<!-- Drawer Overlay -->
<div class="admin-drawer-overlay" id="adminDrawerOverlay" onclick="closeAdminDrawer()"></div>

<!-- Drawer -->
<div class="admin-drawer" id="adminDrawer">
  <!-- Brand -->
  <div class="admin-drawer-brand">
    <img src="assets/images/logo.png" alt="VLC" class="admin-drawer-logo"
         onerror="this.style.display='none';this.nextElementSibling.style.background='var(--maroon)'"/>
    <div>
      <div class="admin-drawer-name"><span>Velveth</span> Lending</div>
      <div class="admin-drawer-sub">Admin Panel</div>
    </div>
  </div>

  <!-- Nav -->
  <nav>
    <div class="drawer-section-label">Main</div>
    <?php foreach ($nav as $n):
      if ($n['group'] !== 'main') continue;
    ?>
    <a href="<?= $n['href'] ?>" class="drawer-nav-item <?= $_cur === $n['href'] ? 'active' : '' ?>">
      <span class="drawer-nav-icon"><?= $n['icon'] ?></span>
      <?= $n['label'] ?>
      <?php if (!empty($n['badge']) && $n['badge'] > 0): ?>
        <span class="drawer-nav-badge"><?= min($n['badge'], 99) ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>

    <div class="drawer-section-label" style="margin-top:6px;">Reports & Alerts</div>
    <?php foreach ($nav as $n):
      if ($n['group'] !== 'reports') continue;
    ?>
    <a href="<?= $n['href'] ?>" class="drawer-nav-item <?= $_cur === $n['href'] ? 'active' : '' ?>">
      <span class="drawer-nav-icon"><?= $n['icon'] ?></span>
      <?= $n['label'] ?>
      <?php if (!empty($n['badge']) && $n['badge'] > 0): ?>
        <span class="drawer-nav-badge"><?= min($n['badge'], 99) ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>

    <div class="drawer-section-label" style="margin-top:6px;">Settings</div>
    <?php foreach ($nav as $n):
      if ($n['group'] !== 'settings') continue;
    ?>
    <a href="<?= $n['href'] ?>" class="drawer-nav-item <?= $_cur === $n['href'] ? 'active' : '' ?>">
      <span class="drawer-nav-icon"><?= $n['icon'] ?></span>
      <?= $n['label'] ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- Footer -->
  <div class="drawer-footer">
    <div class="drawer-user-row">
      <div class="drawer-avatar"><?= $initials ?></div>
      <div>
        <div class="drawer-user-name"><?= htmlspecialchars($_SESSION['fullname'] ?? 'Admin') ?></div>
        <div class="drawer-user-role">Administrator</div>
      </div>
    </div>
    <a href="logout.php" class="drawer-logout-btn">🚪 Logout</a>
  </div>
</div>

<!-- Admin Bottom Tab Bar -->
<nav class="admin-tab-bar">
  <a href="admin_dashboard.php"       class="admin-tab-item <?= $_cur==='admin_dashboard.php'?'active':''?>">
    <span class="admin-tab-icon">🏠</span><span>Home</span>
  </a>
  <a href="admin_customers.php"       class="admin-tab-item <?= $_cur==='admin_customers.php'?'active':''?>">
    <span class="admin-tab-icon">👥</span><span>Customers</span>
  </a>
  <a href="admin_loan_applicants.php" class="admin-tab-item <?= $_cur==='admin_loan_applicants.php'?'active':''?>">
    <span class="admin-tab-icon">📋
      <?php if ($_new_apps>0): ?><span class="admin-tab-badge"><?= $_new_apps ?></span><?php endif; ?>
    </span><span>Loans</span>
  </a>
  <a href="admin_payments.php"        class="admin-tab-item <?= $_cur==='admin_payments.php'?'active':''?>">
    <span class="admin-tab-icon">💳
      <?php if ($_pend_pays>0): ?><span class="admin-tab-badge" style="background:#f59e0b;"><?= $_pend_pays ?></span><?php endif; ?>
    </span><span>Payments</span>
  </a>
  <a href="admin_notifications.php"   class="admin-tab-item <?= $_cur==='admin_notifications.php'?'active':''?>">
    <span class="admin-tab-icon">🔔
      <?php if ($_adm_notif>0): ?><span class="admin-tab-badge"><?= $_adm_notif ?></span><?php endif; ?>
    </span><span>Alerts</span>
  </a>
</nav>

<script>
function openAdminDrawer()  {
  document.getElementById('adminDrawer').classList.add('open');
  document.getElementById('adminDrawerOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeAdminDrawer() {
  document.getElementById('adminDrawer').classList.remove('open');
  document.getElementById('adminDrawerOverlay').classList.remove('open');
  document.body.style.overflow = '';
}
</script>
