<?php
/**
 * Admin Sidebar + Mobile Bottom Nav
 * Requires: $conn, $current_page
 */
$_new_apps  = (int)$conn->query("SELECT COUNT(*) c FROM loan_applications WHERE status='pending'")->fetch_assoc()['c'];
$_pend_pays = (int)$conn->query("SELECT COUNT(*) c FROM payments WHERE status='pending'")->fetch_assoc()['c'];
$_adm_notif = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id IN (SELECT id FROM users WHERE role='admin') AND is_read=0")->fetch_assoc()['c'];
$_cur = isset($current_page) ? $current_page : basename($_SERVER['PHP_SELF']);
$_nav = [
    ['href'=>'admin_dashboard.php',      'icon'=>'⊞','label'=>'Dashboard'],
    ['href'=>'admin_customers.php',      'icon'=>'👥','label'=>'Customers'],
    ['href'=>'admin_loan_applicants.php','icon'=>'📋','label'=>'Loan Applicants','badge'=>$_new_apps],
    ['href'=>'admin_payments.php',       'icon'=>'💳','label'=>'Payments','badge'=>$_pend_pays,'bc'=>'#f59e0b'],
    ['href'=>'admin_penalties.php',      'icon'=>'⚠️','label'=>'Penalties'],
    ['href'=>'admin_documents.php',      'icon'=>'📄','label'=>'Documents'],
    ['href'=>'admin_reports.php',        'icon'=>'📊','label'=>'Reports'],
    ['href'=>'admin_notifications.php',  'icon'=>'🔔','label'=>'Notifications','badge'=>$_adm_notif],
    ['href'=>'admin_users.php',          'icon'=>'🔧','label'=>'Admin Users'],
    ['href'=>'admin_audit_trail.php',    'icon'=>'📝','label'=>'Audit Trail'],
    ['href'=>'admin_archive.php',          'icon'=>'📦','label'=>'Archive'],
];
?>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <img src="assets/images/logo.png" alt="VLC Logo"
         style="width:40px;height:40px;object-fit:contain;border-radius:6px;flex-shrink:0;"/>
    <div class="brand-text">
      <div class="brand-logo"><span>Velveth</span> Lending</div>
      <div class="brand-sub">Admin Panel</div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <?php foreach($_nav as $n): ?>
    <a href="<?=$n['href']?>" class="nav-item <?=$_cur===$n['href']?'active':''?>">
      <span class="nav-icon"><?=$n['icon']?></span><?=$n['label']?>
      <?php if(!empty($n['badge'])&&$n['badge']>0): ?>
        <span style="margin-left:auto;background:<?=$n['bc']??'var(--maroon)'?>;color:white;font-size:9px;font-weight:800;min-width:16px;height:16px;padding:0 3px;border-radius:100px;display:flex;align-items:center;justify-content:center;"><?=min($n['badge'],99)?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <div style="padding:8px 12px;font-size:11px;color:rgba(255,255,255,.28);margin-bottom:2px;">
      Logged in as <strong style="color:rgba(255,255,255,.45);"><?=htmlspecialchars($_SESSION['fullname']??'Admin')?></strong>
    </div>
    <a href="logout.php" class="nav-item logout"><span class="nav-icon">🚪</span>Logout</a>
  </div>
</aside>

<nav class="bottom-nav">
  <div class="bottom-nav-inner">
    <a href="admin_dashboard.php" class="bottom-nav-item <?=$_cur==='admin_dashboard.php'?'active':''?>">
      <span class="bottom-nav-icon">⊞</span><span class="bottom-nav-label">Home</span>
    </a>
    <a href="admin_customers.php" class="bottom-nav-item <?=$_cur==='admin_customers.php'?'active':''?>">
      <span class="bottom-nav-icon">👥</span><span class="bottom-nav-label">Customers</span>
    </a>
    <a href="admin_loan_applicants.php" class="bottom-nav-item <?=$_cur==='admin_loan_applicants.php'?'active':''?>">
      <span class="bottom-nav-icon" style="position:relative;">📋
        <?php if($_new_apps>0):?><span class="bn-badge"><?=$_new_apps?></span><?php endif;?>
      </span><span class="bottom-nav-label">Loans</span>
    </a>
    <a href="admin_payments.php" class="bottom-nav-item <?=$_cur==='admin_payments.php'?'active':''?>">
      <span class="bottom-nav-icon" style="position:relative;">💳
        <?php if($_pend_pays>0):?><span class="bn-badge" style="background:#f59e0b;"><?=$_pend_pays?></span><?php endif;?>
      </span><span class="bottom-nav-label">Payments</span>
    </a>
    <a href="admin_notifications.php" class="bottom-nav-item <?=$_cur==='admin_notifications.php'?'active':''?>">
      <span class="bottom-nav-icon" style="position:relative;">🔔
        <?php if($_adm_notif>0):?><span class="bn-badge"><?=$_adm_notif?></span><?php endif;?>
      </span><span class="bottom-nav-label">Notifs</span>
    </a>
  </div>
</nav>

<script>
function openSidebar(){
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sidebarOverlay').classList.add('open');
  document.body.style.overflow='hidden';
}
function closeSidebar(){
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('open');
  document.body.style.overflow='';
}
</script>
