<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'customer') {
    header("Location: customer_login.php"); exit;
}

$user_id = $_SESSION['user_id'];

// Get customer info
$customer = $conn->query("SELECT * FROM users WHERE id=$user_id")->fetch_assoc();

// Active loan
$loan = $conn->query("SELECT l.*, la.app_id, la.purpose FROM loans l JOIN loan_applications la ON l.application_id=la.id WHERE l.customer_id=$user_id AND l.status='active' LIMIT 1")->fetch_assoc();

// Stats
$active_count  = $conn->query("SELECT COUNT(*) c FROM loans WHERE customer_id=$user_id AND status='active'")->fetch_assoc()['c'] ?? 0;
$total_apps    = $conn->query("SELECT COUNT(*) c FROM loan_applications WHERE customer_id=$user_id")->fetch_assoc()['c'] ?? 0;
$penalties     = $conn->query("SELECT SUM(penalty_amount) s FROM penalties WHERE customer_id=$user_id AND status IN ('pending','unpaid')")->fetch_assoc()['s'] ?? 0;
$unread_notifs = $conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$user_id AND is_read=0")->fetch_assoc()['c'] ?? 0;
ensureMessagesTable($conn);
$unread_msg = $conn->query("SELECT COUNT(*) c FROM messages WHERE customer_id=$user_id AND sender_role='admin' AND is_read=0")->fetch_assoc()['c'] ?? 0;

// Recent activity
$activities = $conn->query("
    SELECT 'payment' as type, p.amount, p.payment_date as activity_date, p.status, CONCAT('Payment') as title
    FROM payments p WHERE p.customer_id=$user_id
    UNION
    SELECT 'loan' as type, la.loan_amount, la.created_at, la.status, CONCAT('Loan Application — ', la.app_id)
    FROM loan_applications la WHERE la.customer_id=$user_id
    ORDER BY activity_date DESC LIMIT 5");

$progress = 0;
if ($loan) {
    $progress = $loan['loan_amount'] > 0 ? round(($loan['amount_paid'] / $loan['loan_amount']) * 100) : 0;
}

$first_name = htmlspecialchars(explode(' ', $_SESSION['fullname'])[0]);
$_cur = 'customer_dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Velveth Lending</title>
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
      <div class="app-bar-sub">Welcome back to Velveth Lending</div>
    </div>
    <a href="customer_messages.php" class="app-bar-action" style="margin-right:2px;">
      💬
      <?php if ($unread_msg > 0): ?><span class="badge-dot"><?= min($unread_msg,9) ?></span><?php endif; ?>
    </a>
    <a href="customer_notifications.php" class="app-bar-action">
      🔔
      <?php if ($unread_notifs > 0): ?><span class="badge-dot"><?= min($unread_notifs,9) ?></span><?php endif; ?>
    </a>
    <a href="customer_profile.php" class="app-bar-avatar"><?= strtoupper(substr($_SESSION['fullname'],0,2)) ?></a>
  </header>

  <main class="app-screen">

    <!-- Balance Hero -->
    <?php if ($loan): ?>
    <div class="balance-hero">
      <div class="balance-hero-label">Outstanding Balance</div>
      <div class="balance-hero-amount">₱<?= number_format($loan['outstanding_balance'],2) ?></div>
      <div style="margin-bottom:6px;">
        <div style="display:flex;justify-content:space-between;font-size:11px;opacity:.85;margin-bottom:5px;">
          <span>Loan Progress</span><span style="font-weight:800;"><?= $progress ?>%</span>
        </div>
        <div style="height:6px;background:rgba(255,255,255,.2);border-radius:100px;overflow:hidden;">
          <div style="height:100%;width:<?= $progress ?>%;background:white;border-radius:100px;"></div>
        </div>
      </div>
      <div class="balance-hero-row">
        <div>
          <div class="balance-hero-stat-label">Next Payment</div>
          <div class="balance-hero-stat-val"><?= $loan['next_payment_date'] ? date('M d, Y', strtotime($loan['next_payment_date'])) : 'N/A' ?></div>
        </div>
        <div style="text-align:right;">
          <div class="balance-hero-stat-label">Amount Due</div>
          <div class="balance-hero-stat-val">₱<?= number_format($loan['monthly_payment'],2) ?></div>
        </div>
      </div>
    </div>
    <?php else: ?>
    <div class="app-card" style="text-align:center;padding:32px 20px;">
      <div style="font-size:42px;margin-bottom:10px;">📋</div>
      <div style="font-weight:800;font-size:15px;margin-bottom:4px;">No Active Loan</div>
      <div style="font-size:12.5px;color:var(--gray-400);margin-bottom:16px;">Apply now to get started with Velveth Lending</div>
      <a href="customer_apply.php" class="app-btn app-btn-primary">📝 Apply for a Loan</a>
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="qa-grid-app">
      <a href="customer_loan.php" class="qa-app-btn">
        <div class="qa-app-icon" style="background:#fde8ec;">💳</div>
        <div class="qa-app-label">Make<br>Payment</div>
      </a>
      <a href="customer_loan.php" class="qa-app-btn">
        <div class="qa-app-icon" style="background:#e0f2fe;">📊</div>
        <div class="qa-app-label">My<br>Loan</div>
      </a>
      <a href="customer_notifications.php" class="qa-app-btn">
        <div class="qa-app-icon" style="background:#fef3c7;">🔔</div>
        <div class="qa-app-label">Notifi-<br>cations</div>
      </a>
      <a href="customer_profile.php" class="qa-app-btn">
        <div class="qa-app-icon" style="background:#ede9fe;">⚙️</div>
        <div class="qa-app-label">My<br>Account</div>
      </a>
    </div>

    <!-- Stats Row -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;">
      <div class="app-card" style="display:flex;align-items:center;gap:10px;padding:14px;">
        <div class="qa-app-icon" style="background:#fde8ec;flex-shrink:0;">🏦</div>
        <div>
          <div style="font-size:18px;font-weight:800;"><?= $active_count ?></div>
          <div style="font-size:10.5px;color:var(--gray-400);font-weight:700;text-transform:uppercase;">Active Loans</div>
        </div>
      </div>
      <div class="app-card" style="display:flex;align-items:center;gap:10px;padding:14px;">
        <div class="qa-app-icon" style="background:<?= $penalties>0?'#fee2e2':'#d1fae5' ?>;flex-shrink:0;"><?= $penalties>0?'⚠️':'✅' ?></div>
        <div>
          <div style="font-size:16px;font-weight:800;color:<?= $penalties>0?'#ef4444':'#10b981' ?>;">₱<?= number_format($penalties,0) ?></div>
          <div style="font-size:10.5px;color:var(--gray-400);font-weight:700;text-transform:uppercase;">Penalties Due</div>
        </div>
      </div>
    </div>

    <!-- Loan Summary -->
    <?php if ($loan): ?>
    <div class="app-section-title">
      <span>Loan Summary</span>
      <a href="customer_loan.php" class="see-all">Details →</a>
    </div>
    <div class="app-card-flat">
      <div style="padding:14px 16px;display:flex;justify-content:space-between;border-bottom:1px solid var(--gray-100);">
        <span style="font-size:12.5px;color:var(--gray-400);">Loan Amount</span>
        <span style="font-weight:800;font-size:13.5px;">₱<?= number_format($loan['loan_amount'],2) ?></span>
      </div>
      <div style="padding:14px 16px;display:flex;justify-content:space-between;border-bottom:1px solid var(--gray-100);">
        <span style="font-size:12.5px;color:var(--gray-400);">Amount Paid</span>
        <span style="font-weight:800;font-size:13.5px;color:#10b981;">₱<?= number_format($loan['amount_paid'],2) ?></span>
      </div>
      <div style="padding:14px 16px;display:flex;justify-content:space-between;">
        <span style="font-size:12.5px;color:var(--gray-400);">Purpose</span>
        <span style="font-weight:700;font-size:12.5px;text-align:right;max-width:60%;"><?= htmlspecialchars($loan['purpose'] ?? '—') ?></span>
      </div>
    </div>
    <?php endif; ?>

    <!-- Recent Activity -->
    <div class="app-section-title">
      <span>Recent Activity</span>
      <a href="customer_loan.php" class="see-all">View All →</a>
    </div>
    <div class="app-card-flat">
      <?php if ($activities->num_rows === 0): ?>
        <div class="app-empty">
          <div class="app-empty-icon">🗂️</div>
          <div class="app-empty-title">No activity yet</div>
          <div class="app-empty-sub">Your payments and applications will appear here</div>
        </div>
      <?php else: while($act = $activities->fetch_assoc()): ?>
        <div class="app-list-item">
          <div class="app-list-icon" style="background:<?= $act['type']==='payment'?'#d1fae5':'#fde8ec' ?>;">
            <?= $act['type']==='payment' ? '💳' : '📝' ?>
          </div>
          <div class="app-list-body">
            <div class="app-list-title"><?= htmlspecialchars($act['title']) ?></div>
            <div class="app-list-sub"><?= $act['activity_date'] ? date('M d, Y', strtotime($act['activity_date'])) : 'N/A' ?></div>
          </div>
          <div class="app-list-end">
            <?php if ($act['type'] === 'payment'): ?>
              <div class="app-list-amount" style="color:#10b981;">+₱<?= number_format($act['amount'],2) ?></div>
            <?php else: ?>
              <span class="pill <?= $act['status'] ?>"><?= ucfirst($act['status']) ?></span>
            <?php endif; ?>
          </div>
        </div>
      <?php endwhile; endif; ?>
    </div>

  </main>

  <?php include 'app_tabbar.php'; ?>
  <?php include 'chatbot.php'; ?>
  <script src="realtime.js"></script>
</body>
</html>
