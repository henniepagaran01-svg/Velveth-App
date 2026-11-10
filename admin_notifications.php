<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php"); exit;
}
$_cur = 'admin_notifications.php';
$admin_id = (int)$_SESSION['user_id'];

// ── Mark single as read + optional redirect ──
if (isset($_GET['mark'])) {
    $nid = (int)$_GET['mark'];
    $conn->query("UPDATE notifications SET is_read=1 WHERE id=$nid AND user_id=$admin_id");

    if (!empty($_GET['redirect'])) {
        $redirect = urldecode($_GET['redirect']);
        if (preg_match('/^admin_[a-z_]+\.php/', $redirect)) {
            header("Location: $redirect"); exit;
        }
    }

    $n = $conn->query("SELECT title,message FROM notifications WHERE id=$nid")->fetch_assoc();
    if ($n) {
        $t = strtolower($n['title'] . ' ' . $n['message']);
        if (strpos($t,'new loan application') !== false) { header("Location: admin_documents.php?tab=applications"); exit; }
        if (strpos($t,'application'))             { header("Location: admin_loan_applicants.php?filter=pending"); exit; }
        if (strpos($t,'document')||strpos($t,'doc')) { header("Location: admin_documents.php?tab=applications"); exit; }
        if (strpos($t,'payment'))                  { header("Location: admin_payments.php"); exit; }
        if (strpos($t,'penalty'))                  { header("Location: admin_penalties.php"); exit; }
        if (strpos($t,'customer')||strpos($t,'registered')) { header("Location: admin_customers.php"); exit; }
    }
    header("Location: admin_notifications.php"); exit;
}

// ── Mark all as read ──
if (isset($_GET['action']) && $_GET['action'] === 'mark_all_read') {
    $conn->query("UPDATE notifications SET is_read=1 WHERE user_id=$admin_id");
    header("Location: admin_notifications.php?msg=success:All notifications marked as read."); exit;
}

// ── Delete a notification ──
if (isset($_GET['delete'])) {
    $nid = (int)$_GET['delete'];
    $conn->query("DELETE FROM notifications WHERE id=$nid AND user_id=$admin_id");
    header("Location: admin_notifications.php?msg=success:Notification deleted."); exit;
}

$msg = isset($_GET['msg']) ? urldecode($_GET['msg']) : '';
[$mtype,$mtext] = $msg ? explode(':', $msg, 2) : ['',''];

$filter = $_GET['filter'] ?? 'all';
$where  = "WHERE user_id=$admin_id";
if ($filter === 'unread') $where .= " AND is_read=0";
if ($filter === 'read')   $where .= " AND is_read=1";

$notifs      = $conn->query("SELECT * FROM notifications $where ORDER BY created_at DESC");
$unread_cnt  = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$admin_id AND is_read=0")->fetch_assoc()['c'] ?? 0;
$total_cnt   = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$admin_id")->fetch_assoc()['c'] ?? 0;

function notif_link($title, $message) {
    $t = strtolower($title . ' ' . $message);
    if (strpos($t,'new loan application') !== false)                    return 'admin_documents.php?tab=applications';
    if (strpos($t,'application'))                                       return 'admin_loan_applicants.php?filter=pending';
    if (strpos($t,'document') || strpos($t,'doc'))               return 'admin_documents.php?tab=applications';
    if (strpos($t,'payment'))                                            return 'admin_payments.php';
    if (strpos($t,'penalty'))                                            return 'admin_penalties.php';
    if (strpos($t,'customer') || strpos($t,'registered'))         return 'admin_customers.php';
    return '#';
}

$type_colors = [
    'success' => ['bg'=>'#dcfce7','text'=>'#16a34a','icon'=>'✅'],
    'warning' => ['bg'=>'#fef3c7','text'=>'#d97706','icon'=>'⚠️'],
    'danger'  => ['bg'=>'#fee2e2','text'=>'#dc2626','icon'=>'❌'],
    'info'    => ['bg'=>'#dbeafe','text'=>'#2563eb','icon'=>'ℹ️'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Notifications | Velveth Admin</title>
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
    <a href="admin_dashboard.php" class="app-bar-back">←</a>
    <div style="flex:1;min-width:0;">
      <div class="app-bar-title">Notifications</div>
      <div class="app-bar-sub"><?= $unread_cnt ?> unread</div>
    </div>
    <?php if ($unread_cnt > 0): ?>
      <a href="?action=mark_all_read" class="app-bar-action" style="font-size:14px;" title="Mark all read">✓✓</a>
    <?php endif; ?>
  </header>

  <main class="app-screen">

    <?php if ($mtext): ?>
    <div class="app-card" style="background:<?= $mtype==='success'?'#d1fae5':'#fee2e2' ?>;color:<?= $mtype==='success'?'#047857':'#b91c1c' ?>;font-size:12.5px;font-weight:600;display:flex;gap:8px;align-items:center;">
      <?= $mtype==='success'?'✅':'❌' ?> <?= htmlspecialchars($mtext) ?>
    </div>
    <?php endif; ?>

    <div class="seg-tabs">
      <a href="?filter=all"    class="seg-tab <?= $filter==='all'?'active':'' ?>">All (<?= $total_cnt ?>)</a>
      <a href="?filter=unread" class="seg-tab <?= $filter==='unread'?'active':'' ?>">Unread (<?= $unread_cnt ?>)</a>
      <a href="?filter=read"   class="seg-tab <?= $filter==='read'?'active':'' ?>">Read</a>
    </div>

    <div class="app-card-flat">
      <?php if (!$notifs || $notifs->num_rows === 0): ?>
        <div class="app-empty">
          <div class="app-empty-icon">🔔</div>
          <div class="app-empty-title">No notifications</div>
          <div class="app-empty-sub">New activity will appear here automatically.</div>
        </div>
      <?php else:
        while ($n = $notifs->fetch_assoc()):
          $c    = $type_colors[$n['type']] ?? $type_colors['info'];
          $link = notif_link($n['title'], $n['message']);
          $unread = !$n['is_read'];
          $target = "admin_notifications.php?mark={$n['id']}&redirect=" . urlencode($link);
      ?>
        <div class="app-list-item" style="background:<?= $unread?'#fffbf0':'transparent' ?>;cursor:pointer;" onclick="window.location.href='<?= $target ?>'">
          <div class="app-list-icon" style="background:<?= $c['bg'] ?>;"><?= $c['icon'] ?></div>
          <div class="app-list-body">
            <div class="app-list-title" style="color:<?= $c['text'] ?>;">
              <?= htmlspecialchars($n['title']) ?>
              <?php if ($unread): ?><span style="background:var(--maroon);color:white;font-size:8.5px;font-weight:800;padding:1px 6px;border-radius:100px;margin-left:4px;">NEW</span><?php endif; ?>
            </div>
            <div class="app-list-sub" style="white-space:normal;color:var(--text-dark);line-height:1.4;margin-top:2px;"><?= htmlspecialchars($n['message']) ?></div>
            <div style="font-size:10.5px;color:var(--gray-400);margin-top:5px;">🕐 <?= date('M d, Y \a\t g:i A', strtotime($n['created_at'])) ?></div>
          </div>
          <a href="?delete=<?= $n['id'] ?>" onclick="event.stopPropagation();return confirm('Delete this notification?')"
             style="flex-shrink:0;font-size:13px;color:var(--gray-300);padding:4px;">🗑</a>
        </div>
      <?php endwhile; endif; ?>
    </div>

  </main>

  <?php include 'admin_tabbar.php'; ?>
  <script src="realtime.js"></script>
</body>
</html>
