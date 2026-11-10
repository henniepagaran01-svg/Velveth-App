<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'customer') {
    header("Location: customer_login.php"); exit;
}
$user_id = (int)$_SESSION['user_id'];

// Mark all read
if (isset($_GET['mark_all'])) {
    $conn->query("UPDATE notifications SET is_read=1 WHERE user_id=$user_id");
    header("Location: customer_notifications.php"); exit;
}
// Mark single read
if (isset($_GET['read'])) {
    $rid = (int)$_GET['read'];
    $conn->query("UPDATE notifications SET is_read=1 WHERE id=$rid AND user_id=$user_id");
}

$filter   = $_GET['filter'] ?? 'all';
$where    = "WHERE user_id=$user_id";
if ($filter === 'unread') $where .= " AND is_read=0";

$notifs   = $conn->query("SELECT * FROM notifications $where ORDER BY created_at DESC LIMIT 60");
$unread   = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$user_id AND is_read=0")->fetch_assoc()['c'];

$type_icons = ['success'=>'✅','warning'=>'⚠️','danger'=>'❌','info'=>'💡','error'=>'❌'];
$type_colors = ['success'=>'#d1fae5','warning'=>'#fef9c3','danger'=>'#fee2e2','info'=>'#eff6ff','error'=>'#fee2e2'];
$_cur = 'customer_notifications.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Notifications | Velveth</title>
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
      <div class="app-bar-title">Notifications</div>
      <div class="app-bar-sub"><?= $unread ?> unread</div>
    </div>
    <?php if ($unread > 0): ?>
    <a href="?mark_all=1" class="app-bar-action" title="Mark all read" style="font-size:13px;font-weight:700;">✓ All</a>
    <?php endif; ?>
  </header>

  <main class="app-screen">

    <!-- Filter tabs -->
    <div class="seg-tabs">
      <a href="?filter=all"    class="seg-tab <?= $filter==='all'?'active':'' ?>">All</a>
      <a href="?filter=unread" class="seg-tab <?= $filter==='unread'?'active':'' ?>">
        Unread <?= $unread>0?"($unread)":'' ?>
      </a>
    </div>

    <?php if ($notifs->num_rows === 0): ?>
    <div class="app-card app-empty">
      <div class="app-empty-icon">🔔</div>
      <div class="app-empty-title">No Notifications</div>
      <div class="app-empty-sub"><?= $filter==='unread' ? "You've read everything!" : "You have no notifications yet." ?></div>
    </div>
    <?php else: ?>
    <div class="app-card-flat">
      <?php while ($n = $notifs->fetch_assoc()):
        $ic    = $type_icons[$n['type']]   ?? '🔔';
        $bg    = $type_colors[$n['type']]  ?? '#f0f4ff';
        $unr   = !(bool)$n['is_read'];
        $timeStr = date('F d, Y', strtotime($n['created_at'])) . ' at ' . date('g:i A', strtotime($n['created_at']));
        // Old rows saved while the table wasn't utf8mb4 start with a literal "?" instead of the emoji
        $disp_title = preg_replace('/^\?+\s*/', '', $n['title']);
      ?>
      <div class="app-list-item notif-row" style="cursor:pointer;<?= $unr ? 'background:#fffdf9;' : '' ?>"
           data-id="<?= (int)$n['id'] ?>"
           data-title="<?= htmlspecialchars($disp_title, ENT_QUOTES) ?>"
           data-msg="<?= htmlspecialchars($n['message'], ENT_QUOTES) ?>"
           data-time="<?= htmlspecialchars($timeStr, ENT_QUOTES) ?>"
           data-icon="<?= $ic ?>"
           onclick="openNotifRow(this)">
        <div class="app-list-icon" style="background:<?= $bg ?>;font-size:18px;"><?= $ic ?></div>
        <div class="app-list-body">
          <div class="app-list-title" style="<?= $unr?'font-weight:800;':'font-weight:600;' ?>">
            <?= htmlspecialchars($disp_title) ?>
          </div>
          <div class="app-list-sub" style="margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px;">
            <?= htmlspecialchars($n['message']) ?>
          </div>
          <div style="font-size:10px;color:var(--gray-400);margin-top:3px;"><?= date('M d · g:i A', strtotime($n['created_at'])) ?></div>
        </div>
        <?php if ($unr): ?>
        <div class="unread-dot" style="width:8px;height:8px;border-radius:50%;background:var(--maroon);flex-shrink:0;"></div>
        <?php endif; ?>
      </div>
      <?php endwhile; ?>
    </div>
    <?php endif; ?>

  </main>

  <!-- Notif Detail Sheet -->
  <div class="sheet-overlay" id="ov_notifSheet" onclick="closeNotifSheet()"></div>
  <div class="bottom-sheet" id="notifSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div style="display:flex;align-items:center;gap:10px;">
        <div id="nd_icon" style="font-size:22px;"></div>
        <div class="sheet-title" id="nd_title"></div>
      </div>
      <button class="sheet-close" onclick="closeNotifSheet()">✕</button>
    </div>
    <div class="sheet-body">
      <p id="nd_message" style="font-size:13.5px;color:var(--text-medium);line-height:1.8;margin-bottom:14px;white-space:pre-wrap;"></p>
      <p id="nd_time" style="font-size:11.5px;color:var(--gray-400);border-top:1px solid var(--gray-100);padding-top:10px;"></p>
    </div>
  </div>

  <?php include 'app_tabbar.php'; ?>
  <?php include 'chatbot.php'; ?>

<script>
function openNotifRow(el) {
  openNotifDetail(el.dataset.title, el.dataset.msg, el.dataset.time, el.dataset.icon);
  // mark this one as read (server already supports ?read=ID) and update the row instantly
  const dot = el.querySelector('.unread-dot');
  if (dot) {
    dot.remove();
    el.style.background = '';
    const t = el.querySelector('.app-list-title'); if (t) t.style.fontWeight = '600';
    fetch('customer_notifications.php?read=' + encodeURIComponent(el.dataset.id), {credentials:'same-origin'}).catch(()=>{});
  }
}
function openNotifDetail(title, msg, timeStr, icon) {
  document.getElementById('nd_title').textContent   = title;
  document.getElementById('nd_message').textContent = msg;
  document.getElementById('nd_time').textContent    = 'Received: ' + timeStr;
  document.getElementById('nd_icon').textContent    = icon || '🔔';
  document.getElementById('ov_notifSheet').classList.add('open');
  document.getElementById('notifSheet').classList.add('open');
}
function closeNotifSheet() {
  document.getElementById('ov_notifSheet').classList.remove('open');
  document.getElementById('notifSheet').classList.remove('open');
}
</script>
  <script src="realtime.js"></script>
</body>
</html>
