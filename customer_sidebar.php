<?php
/**
 * Customer Sidebar + Mobile Bottom Nav
 * Requires: $conn, $user_id, $current_page
 */
$_unread = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$user_id AND is_read=0")->fetch_assoc()['c'];
$_pens   = (int)$conn->query("SELECT COUNT(*) c FROM penalties WHERE customer_id=$user_id AND status='pending'")->fetch_assoc()['c'];
$_nav    = [
    ['href'=>'customer_dashboard.php',    'icon'=>'⊞',  'label'=>'Dashboard'],
    ['href'=>'customer_loan.php',         'icon'=>'💰',  'label'=>'My Loan'],
    ['href'=>'customer_apply.php',        'icon'=>'📝',  'label'=>'Apply Loan'],
    ['href'=>'customer_penalties.php',    'icon'=>'⚠️',   'label'=>'Penalties', 'badge'=>$_pens,'bc'=>'#ef4444'],
    ['href'=>'customer_notifications.php','icon'=>'🔔',  'label'=>'Notifications','badge'=>$_unread],
    ['href'=>'customer_profile.php',      'icon'=>'👤',  'label'=>'Profile'],
];
$_cur = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <img src="assets/images/logo.png" alt="VLC Logo"
         style="width:40px;height:40px;object-fit:contain;border-radius:6px;flex-shrink:0;"/>
    <div class="brand-text">
      <div class="brand-logo"><span>Velveth</span> Lending</div>
      <div class="brand-sub">Customer Portal</div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <?php foreach($_nav as $n): ?>
    <a href="<?=$n['href']?>" class="nav-item <?=$_cur===$n['href']?'active':''?>">
      <span class="nav-icon"><?=$n['icon']?></span><?=$n['label']?>
      <?php if(!empty($n['badge']) && $n['badge']>0): ?>
        <span style="margin-left:auto;background:<?=$n['bc']??'var(--maroon)'?>;color:white;font-size:9px;font-weight:800;min-width:16px;height:16px;padding:0 3px;border-radius:100px;display:flex;align-items:center;justify-content:center;"><?=min($n['badge'],99)?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <a href="logout.php" class="nav-item logout"><span class="nav-icon">🚪</span>Logout</a>
  </div>
</aside>

<nav class="bottom-nav">
  <div class="bottom-nav-inner">
    <a href="customer_dashboard.php" class="bottom-nav-item <?=$_cur==='customer_dashboard.php'?'active':''?>">
      <span class="bottom-nav-icon">⊞</span><span class="bottom-nav-label">Home</span>
    </a>
    <a href="customer_notifications.php" class="bottom-nav-item <?=$_cur==='customer_notifications.php'?'active':''?>">
      <span class="bottom-nav-icon" style="position:relative;">🔔
        <?php if($_unread>0):?><span class="bn-badge"><?=min($_unread,9)?></span><?php endif;?>
      </span><span class="bottom-nav-label">Notifs</span>
    </a>
    <a href="customer_loan.php" class="bottom-nav-item <?=$_cur==='customer_loan.php'?'active':''?>">
      <span class="bottom-nav-icon">💳</span><span class="bottom-nav-label">Pay</span>
    </a>
    <a href="customer_penalties.php" class="bottom-nav-item <?=$_cur==='customer_penalties.php'?'active':''?>">
      <span class="bottom-nav-icon" style="position:relative;">⚠️
        <?php if($_pens>0):?><span class="bn-badge"><?=min($_pens,9)?></span><?php endif;?>
      </span><span class="bottom-nav-label">Penalties</span>
    </a>
    <a href="customer_profile.php" class="bottom-nav-item <?=$_cur==='customer_profile.php'?'active':''?>">
      <span class="bottom-nav-icon">👤</span><span class="bottom-nav-label">Profile</span>
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
