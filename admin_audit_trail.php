<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php"); exit;
}
$_cur = 'admin_audit_trail.php';

// Stats
$today_count = $conn->query("SELECT COUNT(*) c FROM audit_trail WHERE DATE(created_at)=CURDATE()")->fetch_assoc()['c'];
$week_count  = $conn->query("SELECT COUNT(*) c FROM audit_trail WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$month_count = $conn->query("SELECT COUNT(*) c FROM audit_trail WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc()['c'];
$users_count = $conn->query("SELECT COUNT(DISTINCT user_name) c FROM audit_trail WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['c'];

// Filter
$type_filter = $_GET['type'] ?? 'all';
$sql = "SELECT * FROM audit_trail";
if ($type_filter !== 'all') $sql .= " WHERE type='".mysqli_real_escape_string($conn,$type_filter)."'";
$sql .= " ORDER BY created_at DESC LIMIT 100";
$logs = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Audit Trail | Velveth Admin</title>
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
      <div class="app-bar-title">Audit Trail</div>
      <div class="app-bar-sub">Last 100 records</div>
    </div>
  </header>

  <main class="app-screen">

    <!-- Stats -->
    <div class="stat-chip-row">
      <div class="stat-chip"><div class="stat-chip-val" style="color:#16a34a;"><?= $today_count ?></div><div class="stat-chip-label">Today</div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:#d97706;"><?= $week_count ?></div><div class="stat-chip-label">This Week</div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:#2563eb;"><?= $month_count ?></div><div class="stat-chip-label">This Month</div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:var(--maroon);"><?= $users_count ?></div><div class="stat-chip-label">Active Users</div></div>
    </div>

    <!-- Type Filter -->
    <div class="seg-tabs">
      <?php foreach(['all','Loan','Payment','Customer','Penalty','Document','User','System'] as $t): ?>
        <a href="?type=<?= $t ?>" class="seg-tab <?= $type_filter===$t?'active':'' ?>"><?= $t==='all'?'All':$t ?></a>
      <?php endforeach; ?>
    </div>

    <!-- List -->
    <div class="app-card-flat">
      <?php if ($logs->num_rows === 0): ?>
        <div class="app-empty">
          <div class="app-empty-icon">📝</div>
          <div class="app-empty-title">No audit logs found</div>
        </div>
      <?php else:
        $typeColors = [
          'Loan'=>['#eff6ff','#3b82f6','📋'], 'Payment'=>['#dcfce7','#16a34a','💳'],
          'Customer'=>['#f9eef0','#7a1e2e','👥'], 'Penalty'=>['#fef3c7','#d97706','⚠️'],
          'Document'=>['#f3e8ff','#9333ea','📄'], 'User'=>['#e0f2fe','#0284c7','🔧'],
          'System'=>['#f0f0f0','#666666','⚙️'],
        ];
        while($row = $logs->fetch_assoc()):
          $tc = $typeColors[$row['type']] ?? ['#f0f0f0','#666666','📝'];
      ?>
        <div class="app-list-item" style="cursor:default;">
          <div class="app-list-icon" style="background:<?= $tc[0] ?>;"><?= $tc[2] ?></div>
          <div class="app-list-body">
            <div class="app-list-title">
              <?= htmlspecialchars($row['action']) ?>
              <span style="background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;font-size:9.5px;font-weight:700;padding:1px 7px;border-radius:100px;margin-left:5px;"><?= htmlspecialchars($row['type']) ?></span>
            </div>
            <div class="app-list-sub" style="white-space:normal;line-height:1.4;"><?= htmlspecialchars($row['user_name']) ?> · <?= htmlspecialchars($row['details']) ?></div>
            <div style="font-size:10.5px;color:var(--gray-400);margin-top:4px;">🕐 <?= date('M d, Y h:i A', strtotime($row['created_at'])) ?></div>
          </div>
        </div>
      <?php endwhile; endif; ?>
    </div>

    <!-- Info box -->
    <div class="app-card" style="background:var(--gray-50);margin-top:6px;margin-bottom:20px;">
      <h4 style="font-size:13px;font-weight:800;margin-bottom:6px;">🔒 About Audit Trail</h4>
      <p style="font-size:11.5px;color:var(--gray-400);line-height:1.6;margin-bottom:0;">The audit trail logs all important activities in the system including loan approvals, payments, customer updates, and administrative actions — helping maintain transparency and accountability across all operations.</p>
    </div>

  </main>

  <?php include 'admin_tabbar.php'; ?>
  <script src="realtime.js"></script>
</body>
</html>
