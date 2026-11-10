<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php"); exit;
}
$_cur = 'admin_payments.php';

// ── POST Handlers (all before HTML output) ────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── CONFIRM PAYMENT ────────────────────────────────────────────────────
    if ($action === 'confirm_payment') {
        $pay_id = (int)($_POST['payment_id'] ?? 0);
        $cash_amount = isset($_POST['cash_amount']) ? (float)$_POST['cash_amount'] : null;

        $pay = $conn->query("
            SELECT p.*, u.email, u.full_name, u.id AS uid,
                   l.outstanding_balance, l.amount_paid, l.next_payment_date,
                   l.application_id, l.monthly_payment
            FROM payments p
            JOIN users u ON p.customer_id = u.id
            JOIN loans  l ON p.loan_id    = l.id
            WHERE p.id = $pay_id AND p.status = 'pending'
        ")->fetch_assoc();

        if (!$pay) {
            $msg = 'error:Payment not found or already processed.';
        } else {
            $amount = ($cash_amount !== null && $cash_amount > 0) ? $cash_amount : (float)$pay['amount'];
            $lid    = (int)$pay['loan_id'];
            $cid    = (int)$pay['uid'];

            $has_confirmed_at = $conn->query("SHOW COLUMNS FROM payments LIKE 'confirmed_at'")->num_rows > 0;
            if ($has_confirmed_at) {
                $stmt = $conn->prepare("UPDATE payments SET status='confirmed', amount=?, confirmed_at=NOW(), received_by=? WHERE id=?");
            } else {
                $stmt = $conn->prepare("UPDATE payments SET status='confirmed', amount=?, received_by=? WHERE id=?");
            }
            $stmt->bind_param("dii", $amount, $_SESSION['user_id'], $pay_id);
            $stmt->execute(); $stmt->close();

            $new_paid    = (float)$pay['amount_paid'] + $amount;
            $new_balance = max(0, (float)$pay['outstanding_balance'] - $amount);
            $loan_status = ($new_balance <= 0) ? 'completed' : 'active';
            $base_date   = !empty($pay['next_payment_date']) ? $pay['next_payment_date'] : date('Y-m-d');
            $next_date   = ($loan_status === 'active')
                ? date('Y-m-d', strtotime($base_date.' +1 month'))
                : null;
            $next_sql    = $next_date ? "'$next_date'" : 'NULL';
            $conn->query("UPDATE loans SET amount_paid=$new_paid, outstanding_balance=$new_balance, status='$loan_status', next_payment_date=$next_sql WHERE id=$lid");

            if ($loan_status === 'completed') {
                $conn->query("UPDATE loan_applications SET status='completed' WHERE id={$pay['application_id']}");
            }

            if (strpos($pay['notes'] ?? '', 'Penalty Payment') !== false) {
                $conn->query("UPDATE penalties SET status='paid' WHERE customer_id=$cid AND status IN ('pending','unpaid') AND penalty_amount=$amount ORDER BY created_at DESC LIMIT 1");
                sendNotification($conn, $cid, '✅ Penalty Payment Confirmed', "Your penalty payment of ₱".number_format($amount,2)." has been confirmed and your penalty has been marked as Paid.", 'success');
            } else {
                sendNotification($conn, $cid, '✅ Payment Confirmed', "Your payment of ₱".number_format($amount,2)." has been confirmed. ".($loan_status==='completed'?'🎉 Your loan is fully paid!':'Remaining balance: ₱'.number_format($new_balance,2).'.'), 'success');
            }

            if (function_exists('notifyPaymentReceived')) {
                @notifyPaymentReceived($pay['email'], $pay['full_name'], $amount, date('F d, Y'), $new_balance);
            }

            notifyAdmins($conn, "✅ Payment Confirmed", "{$_SESSION['fullname']} confirmed ₱".number_format($amount,2)." from {$pay['full_name']}.", 'info');
            logAudit($conn, 'Payment', $_SESSION['fullname'], 'Confirmed payment', "PAY #{$pay_id} | {$pay['full_name']} | ₱".number_format($amount,2));
            $msg = 'success:Payment confirmed. Balance updated.';
        }
    }

    // ── REJECT PAYMENT ─────────────────────────────────────────────────────
    if ($action === 'reject_payment') {
        $pay_id = (int)($_POST['payment_id'] ?? 0);
        $reason = trim($_POST['reject_reason'] ?? 'Invalid reference number');

        $pay = $conn->query("SELECT p.*,u.email,u.full_name,u.id AS uid FROM payments p JOIN users u ON p.customer_id=u.id WHERE p.id=$pay_id AND p.status='pending'")->fetch_assoc();
        if ($pay) {
            $esc = mysqli_real_escape_string($conn, $reason);
            $conn->query("UPDATE payments SET status='failed', notes=CONCAT(COALESCE(notes,''), ' | Rejected: $esc') WHERE id=$pay_id");
            sendNotification($conn, (int)$pay['uid'], '❌ Payment Rejected', "Your payment submission was rejected. Reason: $reason. Please re-submit with correct details.", 'danger');
            if (function_exists('notifyAccountUpdate')) {
                @notifyAccountUpdate($pay['email'], $pay['full_name'], 'Payment Rejected', "Your payment of ₱".number_format($pay['amount'],2)." was rejected. Reason: $reason. Please resubmit.");
            }
            logAudit($conn, 'Payment', $_SESSION['fullname'], 'Rejected payment', "PAY #{$pay_id} | {$pay['full_name']} | Reason: $reason");
            $msg = 'success:Payment rejected and customer notified.';
        } else { $msg = 'error:Payment not found.'; }
    }

    // ── RECORD PAYMENT (admin manually records cash/etc) ──────────────────
    if ($action === 'record') {
        $loan_id  = (int)($_POST['loan_id']       ?? 0);
        $amount   = (float)($_POST['amount']       ?? 0);
        $method   = trim($_POST['payment_method']  ?? '');
        $pay_date = trim($_POST['payment_date']    ?? date('Y-m-d'));
        $notes    = trim($_POST['notes']           ?? '');

        if (!$loan_id || $amount <= 0 || !$method) {
            $msg = 'error:Loan, amount and method are required.';
        } else {
            $loan = $conn->query("SELECT l.*,la.app_id,u.email,u.full_name,u.id AS uid FROM loans l JOIN loan_applications la ON l.application_id=la.id JOIN users u ON l.customer_id=u.id WHERE l.id=$loan_id")->fetch_assoc();
            if (!$loan) { $msg = 'error:Loan not found.'; }
            else {
                $pay_num = (int)($conn->query("SELECT COUNT(*)+1 c FROM payments")->fetch_assoc()['c'] ?? 1);
                $pay_id  = 'PAY-' . str_pad($pay_num, 4, '0', STR_PAD_LEFT);
                $cid     = (int)$loan['uid'];
                $uid     = (int)$_SESSION['user_id'];

                $stmt = $conn->prepare("INSERT INTO payments (payment_id,loan_id,customer_id,amount,payment_method,payment_date,status,notes,received_by) VALUES (?,?,?,?,?,?,'confirmed',?,?)");
                if (!$stmt) { $msg = 'error:DB error: '.$conn->error; }
                else {
                    $stmt->bind_param("siidsssi", $pay_id, $loan_id, $cid, $amount, $method, $pay_date, $notes, $uid);
                    if ($stmt->execute()) {
                        $new_paid    = (float)$loan['amount_paid'] + $amount;
                        $new_balance = max(0, (float)$loan['outstanding_balance'] - $amount);
                        $loan_status = $new_balance <= 0 ? 'completed' : 'active';
                        $base_date2 = !empty($loan['next_payment_date']) ? $loan['next_payment_date'] : date('Y-m-d');
                        $next_date   = $loan_status === 'active' ? date('Y-m-d', strtotime($base_date2.' +1 month')) : null;
                        $nxt_sql     = $next_date ? "'$next_date'" : 'NULL';
                        $conn->query("UPDATE loans SET amount_paid=$new_paid, outstanding_balance=$new_balance, status='$loan_status', next_payment_date=$nxt_sql WHERE id=$loan_id");
                        if ($loan_status === 'completed') $conn->query("UPDATE loan_applications SET status='completed' WHERE id={$loan['application_id']}");

                        sendNotification($conn, $cid, '✅ Payment Recorded', "Payment of ₱".number_format($amount,2)." via ".ucfirst(str_replace('_',' ',$method))." has been recorded. Balance: ₱".number_format($new_balance,2).".", 'success');
                        if (function_exists('notifyPaymentReceived')) @notifyPaymentReceived($loan['email'], $loan['full_name'], $amount, date('F d, Y', strtotime($pay_date)), $new_balance);
                        logAudit($conn,'Payment',$_SESSION['fullname'],"Recorded $pay_id","₱".number_format($amount,2)." via $method for {$loan['app_id']}");
                        $msg = 'success:Payment '.$pay_id.' recorded. Balance updated to ₱'.number_format($new_balance,2).'.';
                    } else { $msg = 'error:Failed to record payment: '.$stmt->error; }
                    $stmt->close();
                }
            }
        }
    }

    // ── EDIT PAYMENT AMOUNT ────────────────────────────────────────────────
    if ($action === 'edit_amount') {
        $pay_id    = (int)($_POST['payment_id']  ?? 0);
        $new_amt   = (float)($_POST['new_amount'] ?? 0);
        if ($pay_id && $new_amt > 0) {
            $old = $conn->query("SELECT p.*,l.outstanding_balance,l.amount_paid FROM payments p JOIN loans l ON p.loan_id=l.id WHERE p.id=$pay_id AND p.status='confirmed'")->fetch_assoc();
            if ($old) {
                $diff = $new_amt - (float)$old['amount'];
                $conn->query("UPDATE payments SET amount=$new_amt WHERE id=$pay_id");
                $conn->query("UPDATE loans SET amount_paid=amount_paid+$diff, outstanding_balance=outstanding_balance-$diff WHERE id={$old['loan_id']}");
                logAudit($conn,'Payment',$_SESSION['fullname'],"Edited payment amount","PAY #{$pay_id}: ₱".number_format($old['amount'],2)." → ₱".number_format($new_amt,2));
                $msg = 'success:Payment amount updated to ₱'.number_format($new_amt,2).'.';
            } else { $msg = 'error:Only confirmed payments can be edited.'; }
        } else { $msg = 'error:Invalid amount.'; }
    }

    header("Location: admin_payments.php?msg=".urlencode($msg)); exit;
}

// ── GET Page Data ─────────────────────────────────────────────────────────
$msg     = isset($_GET['msg']) ? urldecode($_GET['msg']) : '';
[$mtype, $mtext] = $msg ? explode(':', $msg, 2) : ['',''];

$filter_loan = $_GET['loan'] ?? '';
$filter_stat = $_GET['status'] ?? '';
$search      = trim($_GET['q'] ?? '');

$total_collected  = (float)($conn->query("SELECT SUM(amount) s FROM payments WHERE status='confirmed'")->fetch_assoc()['s'] ?? 0);
$month_collected  = (float)($conn->query("SELECT SUM(amount) s FROM payments WHERE status='confirmed' AND MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())")->fetch_assoc()['s'] ?? 0);
$total_txns       = (int)($conn->query("SELECT COUNT(*) c FROM payments")->fetch_assoc()['c'] ?? 0);
$pending_count    = (int)($conn->query("SELECT COUNT(*) c FROM payments WHERE status='pending'")->fetch_assoc()['c'] ?? 0);

$all_loans = $conn->query("SELECT l.id, la.app_id, u.full_name, l.monthly_payment FROM loans l JOIN loan_applications la ON l.application_id=la.id JOIN users u ON l.customer_id=u.id ORDER BY la.app_id");

$where = "WHERE 1";
if ($filter_loan)  $where .= " AND l.id=" . (int)$filter_loan;
if ($filter_stat)  $where .= " AND p.status='".mysqli_real_escape_string($conn,$filter_stat)."'";
if ($search) {
    $s = mysqli_real_escape_string($conn, $search);
    $where .= " AND (u.full_name LIKE '%$s%' OR p.payment_id LIKE '%$s%')";
}
$payments_result = $conn->query("
    SELECT p.*, u.full_name, u.email, la.app_id, l.outstanding_balance AS bal_after,
           l.loan_amount, l.id AS loan_db_id
    FROM payments p
    JOIN users u ON p.customer_id = u.id
    JOIN loans  l ON p.loan_id    = l.id
    JOIN loan_applications la ON l.application_id = la.id
    $where
    ORDER BY p.created_at DESC
");

// Customers with at least one unresolved penalty — used to flag payment
// rows on the side so admins can spot at-risk accounts while reviewing payments.
$customers_with_penalties = [];
$pen_res = $conn->query("SELECT DISTINCT customer_id FROM penalties WHERE status='pending'");
if ($pen_res) { while ($pr = $pen_res->fetch_assoc()) $customers_with_penalties[(int)$pr['customer_id']] = true; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Payments | Velveth Admin</title>
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
      <div class="app-bar-title">Payments</div>
      <div class="app-bar-sub"><?= $pending_count ?> pending confirmation</div>
    </div>
  </header>

  <main class="app-screen">

    <?php if ($mtext): ?>
    <div class="app-card" style="background:<?= $mtype==='success'?'#d1fae5':'#fee2e2' ?>;color:<?= $mtype==='success'?'#047857':'#b91c1c' ?>;font-size:12.5px;font-weight:600;display:flex;gap:8px;align-items:center;">
      <?= $mtype==='success'?'✅':'❌' ?> <?= htmlspecialchars($mtext) ?>
    </div>
    <?php endif; ?>

    <?php if ($pending_count > 0): ?>
    <div class="app-card" style="background:#fef9c3;border:1.5px solid #fde047;display:flex;align-items:center;gap:12px;">
      <span style="font-size:22px;">💳</span>
      <div style="flex:1;">
        <div style="font-weight:700;font-size:13.5px;color:#a16207;"><?= $pending_count ?> Pending Payment<?= $pending_count>1?'s':'' ?></div>
        <a href="?status=pending" style="font-size:11.5px;color:var(--maroon);font-weight:700;">Review now →</a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stat-chip-row">
      <div class="stat-chip"><div class="stat-chip-val" style="color:#10b981;">₱<?= number_format($total_collected/1000,1) ?>K</div><div class="stat-chip-label">Total Collected</div></div>
      <div class="stat-chip"><div class="stat-chip-val">₱<?= number_format($month_collected,0) ?></div><div class="stat-chip-label">This Month</div></div>
      <div class="stat-chip"><div class="stat-chip-val"><?= $total_txns ?></div><div class="stat-chip-label">Transactions</div></div>
      <div class="stat-chip" style="<?= $pending_count>0?'border:1.5px solid #fde047;':'' ?>"><div class="stat-chip-val" style="color:<?= $pending_count>0?'#f59e0b':'inherit' ?>;"><?= $pending_count ?></div><div class="stat-chip-label">Pending</div></div>
    </div>

    <!-- Filters -->
    <div class="seg-tabs">
      <a href="?<?= $filter_loan?'loan='.$filter_loan.'&':'' ?>" class="seg-tab <?= !$filter_stat?'active':'' ?>">All</a>
      <a href="?<?= $filter_loan?'loan='.$filter_loan.'&':'' ?>status=pending"   class="seg-tab <?= $filter_stat==='pending'?'active':'' ?>">Pending (<?= $pending_count ?>)</a>
      <a href="?<?= $filter_loan?'loan='.$filter_loan.'&':'' ?>status=confirmed" class="seg-tab <?= $filter_stat==='confirmed'?'active':'' ?>">Confirmed</a>
      <a href="?<?= $filter_loan?'loan='.$filter_loan.'&':'' ?>status=failed"    class="seg-tab <?= $filter_stat==='failed'?'active':'' ?>">Failed</a>
    </div>

    <form method="GET" style="display:flex;gap:8px;margin-bottom:14px;">
      <?php if($filter_stat): ?><input type="hidden" name="status" value="<?= $filter_stat ?>"/><?php endif; ?>
      <div style="position:relative;flex:1;">
        <span style="position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:14px;color:var(--gray-400);">🔍</span>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search customer, pay ID…" class="app-input" style="padding-left:36px;"/>
      </div>
      <?php if($search||$filter_loan||$filter_stat): ?><a href="admin_payments.php" class="app-btn app-btn-secondary" style="width:auto;padding:0 16px;">✕</a><?php endif; ?>
    </form>

    <!-- List -->
    <div class="app-card-flat">
      <?php if (!$payments_result || $payments_result->num_rows === 0): ?>
        <div class="app-empty">
          <div class="app-empty-icon">💳</div>
          <div class="app-empty-title"><?= $search ? 'No matches found' : 'No payment records yet' ?></div>
        </div>
      <?php else: while($row = $payments_result->fetch_assoc()): ?>
        <a href="javascript:void(0)" class="app-list-item" onclick='viewPayment(<?= json_encode($row, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
          <div class="app-list-icon" style="background:<?= $row['status']==='confirmed'?'#d1fae5':($row['status']==='pending'?'#fef3c7':'#fee2e2') ?>;">
            <?= $row['status']==='confirmed'?'✅':($row['status']==='pending'?'⏳':'❌') ?>
          </div>
          <div class="app-list-body">
            <div class="app-list-title"><?= htmlspecialchars($row['full_name']) ?></div>
            <div class="app-list-sub"><?= htmlspecialchars($row['payment_id']) ?> · <?= ucwords(str_replace('_',' ',$row['payment_method'])) ?> · <?= date('M d, Y', strtotime($row['payment_date'])) ?></div>
          </div>
          <div class="app-list-end">
            <div class="app-list-amount" style="color:#10b981;">₱<?= number_format($row['amount'],2) ?></div>
            <span class="pill <?= $row['status'] ?>" style="margin-top:2px;"><?= ucfirst($row['status']) ?></span>
            <?php if (isset($customers_with_penalties[(int)$row['customer_id']])): ?>
              <span title="This customer has an unpaid penalty" style="display:inline-flex;align-items:center;gap:3px;margin-top:4px;padding:2px 8px;background:#fee2e2;color:#b91c1c;border-radius:100px;font-size:9.5px;font-weight:800;">⚠️ Penalty</span>
            <?php endif; ?>
          </div>
        </a>
      <?php endwhile; endif; ?>
    </div>

  </main>

  <button class="app-fab" onclick="openSheet('recordSheet')">＋</button>
  <?php include 'admin_tabbar.php'; ?>

  <!-- ═══ VIEW PAYMENT SHEET ═══ -->
  <div class="sheet-overlay" id="ov_viewSheet" onclick="closeSheet('viewSheet')"></div>
  <div class="bottom-sheet" id="viewSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div>
        <div class="sheet-title" id="vp_title"></div>
        <div id="vp_sub" style="font-size:11px;color:var(--gray-400);margin-top:2px;"></div>
      </div>
      <button class="sheet-close" onclick="closeSheet('viewSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <div id="vp_content"></div>
      <div id="vp_actions" style="margin-top:16px;"></div>
    </div>
  </div>

  <!-- ═══ CASH CONFIRM SHEET ═══ -->
  <div class="sheet-overlay" id="ov_cashSheet" onclick="closeSheet('cashSheet')"></div>
  <div class="bottom-sheet" id="cashSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">💰 Confirm Cash Collection</div>
      <button class="sheet-close" onclick="closeSheet('cashSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <p id="cashConfirmName" style="font-size:13px;color:var(--gray-400);margin-bottom:14px;"></p>
      <div style="background:#dcfce7;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:11.5px;color:#15803d;">
        💡 Enter the exact amount collected by your collector.
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="confirm_payment"/>
        <input type="hidden" name="payment_id" id="cashConfirmPayId"/>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Amount Collected (₱) *</label>
          <input type="number" name="cash_amount" id="cashConfirmAmt" class="app-input" min="1" step="0.01" required/>
          <div id="cashExpected" style="font-size:11px;color:var(--maroon);margin-top:5px;"></div>
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="margin-top:16px;background:#10b981;">✅ Confirm Collection</button>
      </form>
    </div>
  </div>

  <!-- ═══ REJECT SHEET ═══ -->
  <div class="sheet-overlay" id="ov_rejectSheet" onclick="closeSheet('rejectSheet')"></div>
  <div class="bottom-sheet" id="rejectSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">❌ Reject Payment</div>
      <button class="sheet-close" onclick="closeSheet('rejectSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <form method="POST">
        <input type="hidden" name="action" value="reject_payment"/>
        <input type="hidden" name="payment_id" id="reject_pay_id"/>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Reason for Rejection *</label>
          <select name="reject_reason" class="app-select" required>
            <option value="Invalid reference number">Invalid reference number</option>
            <option value="Amount mismatch">Amount mismatch</option>
            <option value="Duplicate submission">Duplicate submission</option>
            <option value="Payment not received">Payment not received</option>
            <option value="GCash number incorrect">GCash number incorrect</option>
            <option value="Bank transfer not received">Bank transfer not received</option>
          </select>
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="margin-top:16px;background:#ef4444;" onclick="return confirm('Reject this payment and notify customer?')">Reject Payment</button>
      </form>
    </div>
  </div>

  <!-- ═══ EDIT AMOUNT SHEET ═══ -->
  <div class="sheet-overlay" id="ov_editAmtSheet" onclick="closeSheet('editAmtSheet')"></div>
  <div class="bottom-sheet" id="editAmtSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">✏️ Edit Payment Amount</div>
      <button class="sheet-close" onclick="closeSheet('editAmtSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <div style="background:#fef9c3;border:1px solid #fde047;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:11.5px;color:#a16207;">
        ⚠️ Editing recalculates the loan balance automatically.
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit_amount"/>
        <input type="hidden" name="payment_id" id="edit_pay_id"/>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">New Amount (₱) *</label>
          <input type="number" name="new_amount" id="edit_pay_amt" class="app-input" min="1" step="0.01" required/>
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="margin-top:16px;" onclick="return confirm('Update this payment amount?')">Update Amount</button>
      </form>
    </div>
  </div>

  <!-- ═══ RECORD PAYMENT SHEET ═══ -->
  <div class="sheet-overlay" id="ov_recordSheet" onclick="closeSheet('recordSheet')"></div>
  <div class="bottom-sheet" id="recordSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">➕ Record Payment</div>
      <button class="sheet-close" onclick="closeSheet('recordSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <form method="POST">
        <input type="hidden" name="action" value="record"/>
        <div class="app-form-group">
          <label class="app-form-label">Loan / Application *</label>
          <select name="loan_id" class="app-select" required id="rec_loan_sel" onchange="setExpectedAmt(this)">
            <option value="">— Select Loan —</option>
            <?php $all_loans->data_seek(0); while($ln=$all_loans->fetch_assoc()): ?>
            <option value="<?=$ln['id']?>" data-amt="<?= $ln['monthly_payment'] ?? 0 ?>">
              <?=htmlspecialchars($ln['app_id'])?> — <?=htmlspecialchars($ln['full_name'])?>
            </option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Amount (₱) *</label>
          <input type="number" name="amount" id="rec_amount" class="app-input" min="1" step="0.01" required placeholder="0.00"/>
          <div id="rec_expected" style="font-size:11px;color:var(--maroon);margin-top:5px;display:none;"></div>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Payment Date *</label>
          <input type="date" name="payment_date" class="app-input" required value="<?= date('Y-m-d') ?>"/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Method *</label>
          <select name="payment_method" class="app-select" required>
            <option value="">— Select —</option>
            <option value="gcash">GCash</option>
            <option value="bank_transfer">Bank Transfer</option>
            <option value="cash_collection">Cash Collection</option>
          </select>
        </div>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Reference / Notes</label>
          <input type="text" name="notes" class="app-input" placeholder="Reference number or notes"/>
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="margin-top:16px;">Record Payment</button>
      </form>
    </div>
  </div>

  <!-- Hidden quick confirm form -->
  <form method="POST" id="quickConfirmForm" style="display:none;">
    <input type="hidden" name="action" value="confirm_payment"/>
    <input type="hidden" name="payment_id" id="quickConfirmId"/>
  </form>

<script>
function openSheet(id) { document.getElementById('ov_'+id)?.classList.add('open'); document.getElementById(id)?.classList.add('open'); }
function closeSheet(id) { document.getElementById('ov_'+id)?.classList.remove('open'); document.getElementById(id)?.classList.remove('open'); }
function esc(s) { const d=document.createElement('div'); d.textContent=s||'—'; return d.innerHTML; }

function viewPayment(data) {
  document.getElementById('vp_title').textContent = data.payment_id;
  document.getElementById('vp_sub').innerHTML = `<span class="pill ${data.status}">${data.status.charAt(0).toUpperCase()+data.status.slice(1)}</span>`;

  const rows = [
    ['Customer',     esc(data.full_name)],
    ['Loan',         esc(data.app_id) + ' (#' + data.loan_db_id + ')'],
    ['Amount',       '₱'+parseFloat(data.amount).toLocaleString('en-PH',{minimumFractionDigits:2})],
    ['Method',       esc(data.payment_method.replace('_',' '))],
    ['Date',         new Date(data.payment_date).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})],
    ['Balance After',data.bal_after>0 ? '₱'+parseFloat(data.bal_after).toLocaleString('en-PH',{minimumFractionDigits:2}) : '✅ Fully Paid'],
  ];
  if (data.notes) rows.push(['Notes', esc(data.notes)]);

  document.getElementById('vp_content').innerHTML = rows.map(([l,v]) =>
    `<div class="view-row"><span class="view-label">${l}</span><span class="view-value">${v}</span></div>`).join('');

  let actions = '';
  if (data.status === 'pending') {
    if (['cash_collection','cash'].includes(data.payment_method)) {
      actions = `<button class="app-btn app-btn-primary" style="background:#10b981;" onclick="closeSheet('viewSheet');openCashConfirm(${data.id},'${esc(data.full_name)}',${data.monthly_payment||data.amount})">💰 Set Amount &amp; Confirm</button>`;
    } else {
      actions = `<button class="app-btn app-btn-primary" style="background:#10b981;" onclick="quickConfirm(${data.id})">✓ Confirm Payment</button>`;
    }
    actions += `<button class="app-btn app-btn-secondary" style="margin-top:10px;color:#ef4444;" onclick="closeSheet('viewSheet');openRejectSheet(${data.id})">✗ Reject Payment</button>`;
  } else if (data.status === 'confirmed') {
    actions = `<button class="app-btn app-btn-secondary" onclick="closeSheet('viewSheet');openEditAmount(${data.id},${data.amount})">✏️ Edit Amount</button>`;
  }
  document.getElementById('vp_actions').innerHTML = actions;
  openSheet('viewSheet');
}

function quickConfirm(payId) {
  if (confirm('Confirm this payment?')) {
    document.getElementById('quickConfirmId').value = payId;
    document.getElementById('quickConfirmForm').submit();
  }
}

function openCashConfirm(payId, name, expectedAmt) {
  document.getElementById('cashConfirmPayId').value = payId;
  document.getElementById('cashConfirmName').textContent = 'Confirming payment from: ' + name;
  document.getElementById('cashConfirmAmt').value = expectedAmt || '';
  document.getElementById('cashExpected').textContent = expectedAmt > 0 ? 'Expected: ₱' + parseFloat(expectedAmt).toLocaleString('en-PH',{minimumFractionDigits:2}) : '';
  openSheet('cashSheet');
}

function openRejectSheet(payId) {
  document.getElementById('reject_pay_id').value = payId;
  openSheet('rejectSheet');
}

function openEditAmount(payId, currentAmt) {
  document.getElementById('edit_pay_id').value = payId;
  document.getElementById('edit_pay_amt').value = currentAmt;
  openSheet('editAmtSheet');
}

function setExpectedAmt(sel) {
  const opt = sel.options[sel.selectedIndex];
  const amt = opt ? parseFloat(opt.dataset.amt||0) : 0;
  const el = document.getElementById('rec_expected');
  const amtEl = document.getElementById('rec_amount');
  if (amt > 0) {
    el.textContent = 'Monthly payment: ₱' + amt.toLocaleString('en-PH',{minimumFractionDigits:2});
    el.style.display = 'block';
    if (!amtEl.value) amtEl.value = amt;
  } else { el.style.display = 'none'; }
}
</script>
  <script src="assets/js/form-guard.js"></script>
  <script src="realtime.js"></script>
</body>
</html>
