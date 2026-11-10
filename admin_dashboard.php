<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php"); exit;
}

$_cur = 'admin_dashboard.php';

// Auto-generate penalties for any newly-overdue loans on every dashboard visit.
autoGeneratePenalties($conn);

// Stats
$totalCustomers = $conn->query("SELECT COUNT(*) c FROM users WHERE role='customer' AND deleted_at IS NULL")->fetch_assoc()['c'];
$activeLoans    = $conn->query("SELECT COUNT(*) c FROM loans WHERE status='active'")->fetch_assoc()['c'];
$pendingApps    = $conn->query("SELECT COUNT(*) c FROM loan_applications WHERE status='pending'")->fetch_assoc()['c'];
$totalPayments  = $conn->query("SELECT SUM(amount) s FROM payments WHERE status='confirmed'")->fetch_assoc()['s'] ?? 0;
$pendingDocs    = $conn->query("SELECT COUNT(*) c FROM documents WHERE status='pending'")->fetch_assoc()['c'];
$unreadNotifs   = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id IN (SELECT id FROM users WHERE role='admin') AND is_read=0")->fetch_assoc()['c'];
ensureMessagesTable($conn);
$unreadMsg     = (int)$conn->query("SELECT COUNT(*) c FROM messages WHERE sender_role='customer' AND is_read=0")->fetch_assoc()['c'];
$outstandingBal = (float)($conn->query("SELECT SUM(outstanding_balance) s FROM loans WHERE status='active'")->fetch_assoc()['s'] ?? 0);
$collectionThisMonth = (float)($conn->query("SELECT SUM(amount) s FROM payments WHERE status='confirmed' AND MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())")->fetch_assoc()['s'] ?? 0);

// Recent customers
$recentCustomers = $conn->query("SELECT * FROM users WHERE role='customer' AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 5");

// Recent payments
$recentPayments = $conn->query("
    SELECT p.*, u.full_name FROM payments p
    JOIN users u ON p.customer_id = u.id
    ORDER BY p.created_at DESC LIMIT 5");

// Pending applications (with doc counts)
$pendingList = $conn->query("
    SELECT la.*, u.full_name,
        COUNT(d.id) AS doc_count,
        SUM(CASE WHEN d.status='verified' THEN 1 ELSE 0 END) AS doc_verified
    FROM loan_applications la
    JOIN users u ON la.customer_id = u.id
    LEFT JOIN documents d ON d.application_id = la.id
    WHERE la.status='pending'
    GROUP BY la.id
    ORDER BY la.created_at DESC LIMIT 5
");

$first_name = htmlspecialchars(explode(' ', $_SESSION['fullname'])[0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Dashboard | Velveth Admin</title>
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
<?php include 'splash_screen.php'; ?>

  <!-- App Bar -->
  <header class="app-bar maroon" style="background:var(--maroon-light);">
    <div style="flex:1;min-width:0;">
      <div class="app-bar-title">Hi, <?= $first_name ?> 👋</div>
      <div class="app-bar-sub"><?= date('l, F j, Y') ?></div>
    </div>
    <a href="admin_messages.php" class="app-bar-action" style="margin-right:2px;">
      💬
      <?php if ($unreadMsg > 0): ?><span class="badge-dot"><?= min($unreadMsg,9) ?></span><?php endif; ?>
    </a>
    <a href="admin_notifications.php" class="app-bar-action">
      🔔
      <?php if ($unreadNotifs > 0): ?><span class="badge-dot"><?= min($unreadNotifs,9) ?></span><?php endif; ?>
    </a>
    <a href="admin_more.php" class="app-bar-avatar"><?= strtoupper(substr($_SESSION['fullname'],0,2)) ?></a>
  </header>

  <main class="app-screen">

    <!-- Portfolio Hero -->
    <div class="balance-hero">
      <div class="balance-hero-label">Collection for the Month</div>
      <div class="balance-hero-amount">₱<?= number_format($collectionThisMonth,2) ?></div>
      <div class="balance-hero-row">
        <div>
          <div class="balance-hero-stat-label">Active Loans</div>
          <div class="balance-hero-stat-val"><?= $activeLoans ?></div>
        </div>
        <div style="text-align:right;">
          <div class="balance-hero-stat-label">Outstanding Balance</div>
          <div class="balance-hero-stat-val">₱<?= number_format($outstandingBal,2) ?></div>
        </div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="qa-grid-app">
      <a href="admin_penalties.php" class="qa-app-btn">
        <div class="qa-app-icon" style="background:#fee2e2;">⚠️</div>
        <div class="qa-app-label">Penalties</div>
      </a>
      <a href="admin_reports.php" class="qa-app-btn">
        <div class="qa-app-icon" style="background:#dbeafe;">📊</div>
        <div class="qa-app-label">Reports &<br>Analytics</div>
      </a>
      <a href="admin_audit_trail.php" class="qa-app-btn">
        <div class="qa-app-icon" style="background:#e0f2fe;">📝</div>
        <div class="qa-app-label">Audit<br>Trail</div>
      </a>
      <a href="admin_documents.php?tab=applications" class="qa-app-btn">
        <div class="qa-app-icon" style="background:#fef3c7;">⚖️</div>
        <div class="qa-app-label">Review<br>Docs</div>
      </a>
    </div>

    <!-- Stat chips -->
    <div class="stat-chip-row">
      <div class="stat-chip"><div class="stat-chip-val"><?= $totalCustomers ?></div><div class="stat-chip-label">Customers</div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:var(--maroon);"><?= $pendingApps ?></div><div class="stat-chip-label">Pending Apps</div></div>
      <a href="admin_documents.php?tab=applications" class="stat-chip" style="<?= $pendingDocs>0?'border:1.5px solid #f59e0b;':'' ?>">
        <div class="stat-chip-val" style="color:<?= $pendingDocs>0?'#f59e0b':'inherit' ?>;"><?= $pendingDocs ?></div><div class="stat-chip-label">Docs to Review</div>
      </a>
      <div class="stat-chip"><div class="stat-chip-val" style="color:#10b981;"><?= $activeLoans ?></div><div class="stat-chip-label">Active Loans</div></div>
    </div>

    <!-- Pending Applications -->
    <div class="app-section-title">
      <span>Pending Applications<?= $pendingApps>0 ? " ($pendingApps)" : '' ?></span>
      <a href="admin_loan_applicants.php?filter=pending" class="see-all">View All →</a>
    </div>
    <div class="app-card-flat">
      <?php if (!$pendingList || $pendingList->num_rows === 0): ?>
        <div class="app-empty" style="padding:30px 20px;">
          <div class="app-empty-icon" style="font-size:34px;">✅</div>
          <div class="app-empty-title" style="font-size:13.5px;">No pending applications</div>
        </div>
      <?php else: while($row = $pendingList->fetch_assoc()): ?>
        <a href="admin_loan_applicants.php?filter=pending" class="app-list-item">
          <div class="app-list-icon" style="background:#fde8ec;"><?= strtoupper(substr($row['full_name'],0,2)) ?></div>
          <div class="app-list-body">
            <div class="app-list-title"><?= htmlspecialchars($row['full_name']) ?></div>
            <div class="app-list-sub">
              <?= htmlspecialchars($row['app_id']) ?> ·
              <?php if ($row['doc_count']==0): ?>No docs
              <?php elseif ($row['doc_verified']==$row['doc_count']): ?><span style="color:#10b981;">✅ Verified</span>
              <?php else: ?><span style="color:#d97706;">⏳ <?= $row['doc_count'] ?> submitted</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="app-list-end">
            <div class="app-list-amount">₱<?= number_format($row['loan_amount'],0) ?></div>
          </div>
        </a>
      <?php endwhile; endif; ?>
    </div>

    <!-- Recent Payments -->
    <div class="app-section-title">
      <span>Recent Payments</span>
      <a href="admin_payments.php" class="see-all">View All →</a>
    </div>
    <div class="app-card-flat">
      <?php if (!$recentPayments || $recentPayments->num_rows === 0): ?>
        <div class="app-empty" style="padding:30px 20px;">
          <div class="app-empty-icon" style="font-size:34px;">💳</div>
          <div class="app-empty-title" style="font-size:13.5px;">No payments yet</div>
        </div>
      <?php else: while($row = $recentPayments->fetch_assoc()): ?>
        <a href="admin_payments.php" class="app-list-item">
          <div class="app-list-icon" style="background:<?= $row['status']==='confirmed'?'#d1fae5':'#fef3c7' ?>;">
            <?= $row['status']==='confirmed' ? '✅' : '⏳' ?>
          </div>
          <div class="app-list-body">
            <div class="app-list-title"><?= htmlspecialchars($row['full_name']) ?></div>
            <div class="app-list-sub"><?= htmlspecialchars($row['payment_id']) ?> · <?= date('M d, Y', strtotime($row['created_at'])) ?></div>
          </div>
          <div class="app-list-end">
            <div class="app-list-amount" style="color:#10b981;">₱<?= number_format($row['amount'],2) ?></div>
            <span class="pill <?= $row['status'] ?>" style="margin-top:2px;"><?= ucfirst($row['status']) ?></span>
          </div>
        </a>
      <?php endwhile; endif; ?>
    </div>

    <!-- Recent Customers -->
    <div class="app-section-title">
      <span>Recently Registered</span>
      <a href="admin_customers.php" class="see-all">View All →</a>
    </div>
    <div class="app-card-flat">
      <?php if (!$recentCustomers || $recentCustomers->num_rows === 0): ?>
        <div class="app-empty" style="padding:30px 20px;">
          <div class="app-empty-icon" style="font-size:34px;">👥</div>
          <div class="app-empty-title" style="font-size:13.5px;">No customers yet</div>
        </div>
      <?php else: while($row = $recentCustomers->fetch_assoc()): ?>
        <a href="admin_customers.php" class="app-list-item">
          <div class="app-list-icon" style="background:linear-gradient(135deg,var(--maroon),var(--maroon-light));color:white;"><?= strtoupper(substr($row['full_name'],0,2)) ?></div>
          <div class="app-list-body">
            <div class="app-list-title"><?= htmlspecialchars($row['full_name']) ?></div>
            <div class="app-list-sub"><?= htmlspecialchars($row['email']) ?></div>
          </div>
          <div class="app-list-end">
            <span class="pill <?= $row['status'] ?>"><?= ucfirst($row['status']) ?></span>
          </div>
        </a>
      <?php endwhile; endif; ?>
    </div>

  </main>

  <?php include 'admin_tabbar.php'; ?>
  <script src="realtime.js"></script>
</body>
</html>
