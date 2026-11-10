<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'customer') {
    header("Location: customer_login.php"); exit;
}
$user_id = (int)$_SESSION['user_id'];

// ── PAYMENT SUBMISSION — must be at top before any output ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_payment') {
    $loan_id = (int)($_POST['loan_id']          ?? 0);
    $method  = trim($_POST['payment_method']     ?? '');
    $ref     = trim($_POST['reference_number']   ?? '');
    $gcash_n = trim($_POST['gcash_number']       ?? '');
    $coll_dt = trim($_POST['collection_date']    ?? '');

    $pay_loan = $conn->query("
        SELECT l.*, la.app_id
        FROM loans l
        JOIN loan_applications la ON l.application_id = la.id
        WHERE l.id = $loan_id AND l.customer_id = $user_id
    ")->fetch_assoc();

    // Amount the customer chose to pay (editable). Falls back to the monthly
    // installment if none was sent. Validated server-side -- never trust the client.
    $amt_err = '';
    if ($pay_loan) {
        $balance = ($pay_loan['outstanding_balance'] !== null)
            ? (float)$pay_loan['outstanding_balance'] : (float)$pay_loan['loan_amount'];
        $req_amt = isset($_POST['amount']) && $_POST['amount'] !== ''
            ? round((float)str_replace(',', '', $_POST['amount']), 2)
            : round((float)$pay_loan['monthly_payment'], 2);
        if ($pay_loan['status'] !== 'active' || $balance <= 0) {
            $amt_err = 'This loan has no outstanding balance to pay.';
        } elseif ($req_amt < 1) {
            $amt_err = 'Please enter a payment amount of at least ₱1.00.';
        } elseif ($req_amt > $balance + 0.005) {
            $amt_err = 'Payment amount cannot be more than your outstanding balance (₱' . number_format($balance, 2) . ').';
        }
    }
    $user_note = trim(preg_replace('/\s+/', ' ', $_POST['payment_notes'] ?? ''));
    $user_note = substr($user_note, 0, 100);

    if ($pay_loan && $method && $amt_err !== '') {
        $_SESSION['pay_error'] = $amt_err;
    } elseif ($pay_loan && $method) {
        $pay_num = (int)($conn->query("SELECT COUNT(*)+1 c FROM payments")->fetch_assoc()['c'] ?? 1);
        $pay_id  = 'PAY-' . str_pad($pay_num, 4, '0', STR_PAD_LEFT);
        $amount  = $req_amt;
        $today   = date('Y-m-d');
        $notes   = match($method) {
            'gcash'           => "GCash Ref: $ref" . ($gcash_n ? " | From: $gcash_n" : ''),
            'bank_transfer'   => "Bank Ref: $ref",
            'cash_collection' => "Cash Collection on $coll_dt",
            default           => $ref,
        };
        if ($user_note !== '') $notes .= ($notes !== '' ? ' | ' : '') . 'Note: ' . $user_note;

        // Ensure payment_method ENUM supports this value (auto-heal old schemas)
        $enum_check = $conn->query("SHOW COLUMNS FROM payments LIKE 'payment_method'")->fetch_assoc();
        if ($enum_check && stripos($enum_check['Type'], $method) === false) {
            @$conn->query("ALTER TABLE payments MODIFY COLUMN payment_method ENUM('cash','gcash','bank_transfer','bank','cash_collection','check') DEFAULT 'cash'");
        }

        $stmt = $conn->prepare(
            "INSERT INTO payments
             (payment_id,loan_id,customer_id,amount,payment_method,payment_date,notes,status)
             VALUES (?,?,?,?,?,?,?,'pending')"
        );
        if ($stmt) {
            $stmt->bind_param("siidsss",
                $pay_id, $loan_id, $user_id, $amount, $method, $today, $notes
            );
            if ($stmt->execute()) {
                sendNotification($conn, $user_id,
                    '💳 Payment Submitted',
                    "Your payment of ₱" . number_format($amount,2) .
                    " via " . str_replace('_',' ',$method) .
                    " has been submitted and is being verified.",
                    'info'
                );
                notifyAdmins($conn,
                    "💳 New Payment — $pay_id",
                    "{$_SESSION['fullname']} submitted ₱" . number_format($amount,2) .
                    " via $method for {$pay_loan['app_id']}.",
                    'info'
                );
                logAudit($conn, 'Payment', $_SESSION['fullname'],
                    "Submitted $pay_id",
                    "₱" . number_format($amount,2) . " via $method"
                );
                $_SESSION['pay_success'] = [
                    'id'      => $pay_id,
                    'amount'  => $amount,
                    'method'  => $method,
                    'ref'     => $ref,
                    'coll_dt' => $coll_dt,
                ];
            } else {
                $_SESSION['pay_error'] = 'Failed to submit payment: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            $_SESSION['pay_error'] = 'Database error: ' . $conn->error;
        }
    } else {
        $_SESSION['pay_error'] = 'Invalid loan or payment method.';
    }
    header("Location: customer_loan.php"); exit;
}

// ── PAGE DATA ─────────────────────────────────────────────────────────────
$new_app = isset($_GET['new_app']) ? htmlspecialchars(urldecode($_GET['new_app'])) : '';

$applications = $conn->query("
    SELECT la.*,
        COUNT(d.id)                                            AS doc_count,
        SUM(CASE WHEN d.status='verified'  THEN 1 ELSE 0 END) AS doc_verified,
        SUM(CASE WHEN d.status='pending'   THEN 1 ELSE 0 END) AS doc_pending,
        SUM(CASE WHEN d.status='rejected'  THEN 1 ELSE 0 END) AS doc_rejected
    FROM loan_applications la
    LEFT JOIN documents d ON d.application_id = la.id
    WHERE la.customer_id = $user_id
    GROUP BY la.id
    ORDER BY la.created_at DESC
");

$active_loans = $conn->query("
    SELECT l.*, la.app_id, la.purpose, la.loan_term, la.interest_rate, la.total_payable
    FROM loans l
    JOIN loan_applications la ON l.application_id = la.id
    WHERE l.customer_id = $user_id
    ORDER BY l.created_at DESC
");

// Build payment history grouped by loan
$payments_raw = $conn->query("
    SELECT p.*, l.loan_amount
    FROM payments p
    JOIN loans l ON p.loan_id = l.id
    WHERE p.customer_id = $user_id
    ORDER BY p.loan_id ASC, p.payment_date ASC, p.id ASC
");
$running_paid    = [];
$payments_by_loan = [];
while ($r = $payments_raw->fetch_assoc()) {
    $lid = (int)$r['loan_id'];
    if (!isset($running_paid[$lid])) $running_paid[$lid] = 0;
    $running_paid[$lid]  += (float)$r['amount'];
    $r['balance_after']   = max(0, (float)$r['loan_amount'] - $running_paid[$lid]);
    $r['cumulative_paid'] = $running_paid[$lid];
    $payments_by_loan[$lid][] = $r;
}
foreach ($payments_by_loan as &$list) $list = array_reverse($list);
unset($list);

// First active loan for payment modal
$_modal_loan = $conn->query("
    SELECT l.*, la.app_id, la.loan_term, la.interest_rate
    FROM loans l
    JOIN loan_applications la ON l.application_id = la.id
    WHERE l.customer_id = $user_id AND l.status = 'active'
    LIMIT 1
")->fetch_assoc() ?? null;

// Payment success / error flash
$_pay_success = null;
if (isset($_SESSION['pay_success'])) {
    $_pay_success = $_SESSION['pay_success'];
    unset($_SESSION['pay_success']);
}
$_pay_error = null;
if (isset($_SESSION['pay_error'])) {
    $_pay_error = $_SESSION['pay_error'];
    unset($_SESSION['pay_error']);
}

// Data for the Make-a-Payment sheet
$_pay_balance = $_modal_loan ? (($_modal_loan['outstanding_balance'] !== null) ? (float)$_modal_loan['outstanding_balance'] : (float)$_modal_loan['loan_amount']) : 0;
$_pay_monthly = $_modal_loan ? min((float)$_modal_loan['monthly_payment'], $_pay_balance) : 0;
$_pay_term    = $_modal_loan ? max(1, (int)$_modal_loan['loan_term']) : 1;
$_pay_princ   = $_modal_loan ? min($_pay_monthly, round((float)$_modal_loan['loan_amount'] / $_pay_term, 2)) : 0;
$_pay_int     = max(0, $_pay_monthly - $_pay_princ);
$_pay_due     = ($_modal_loan && !empty($_modal_loan['next_payment_date'])) ? $_modal_loan['next_payment_date'] : null;
$_pay_overdue = $_pay_due ? (int)floor((strtotime(date('Y-m-d')) - strtotime($_pay_due)) / 86400) : 0;

$status_info = [
    'pending'   => ['label'=>'Under Review',  'color'=>'#d97706','bg'=>'#fef3c7','icon'=>'⏳',
                    'desc'=>'Your application has been received and is awaiting admin review.'],
    'approved'  => ['label'=>'Approved',       'color'=>'#16a34a','bg'=>'#dcfce7','icon'=>'✅',
                    'desc'=>'Your application has been approved. Your loan will be activated shortly.'],
    'active'    => ['label'=>'Active',          'color'=>'#16a34a','bg'=>'#dcfce7','icon'=>'💰',
                    'desc'=>'Your loan is active. Keep up with your monthly payments.'],
    'rejected'  => ['label'=>'Not Approved',   'color'=>'#dc2626','bg'=>'#fee2e2','icon'=>'❌',
                    'desc'=>'Unfortunately your application was not approved. See remarks below.'],
    'completed' => ['label'=>'Completed',       'color'=>'#2563eb','bg'=>'#dbeafe','icon'=>'🎉',
                    'desc'=>'Congratulations! Your loan has been fully paid.'],
];

$_cur = 'customer_loan.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>My Loan | Velveth Lending</title>
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
  <style>
    .timeline-step { display:flex;align-items:flex-start;gap:10px;padding:9px 0; }
    .timeline-step:not(:last-child) { border-bottom:1px solid var(--gray-100); }
    .ts-dot { width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;margin-top:1px; }
    .ps-card  { background:var(--gray-50);border-radius:12px;padding:12px;text-align:center; }
    .ps-label { font-size:10px;color:var(--gray-400);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px;font-weight:700; }
    .ps-value { font-size:15px;font-weight:800; }
    .ps-green  { color:#10b981; }
    .ps-red    { color:#ef4444; }
    .ps-maroon { color:var(--maroon); }
    .method-chip { display:inline-block;padding:2px 8px;border-radius:6px;font-size:10.5px;font-weight:800;background:var(--maroon-pale);color:var(--maroon);text-transform:capitalize; }
    .payment-method-row {
      display:flex; align-items:center; gap:12px; width:100%;
      padding:14px; border-radius:14px; border:1.5px solid var(--gray-100);
      background:white; cursor:pointer; text-align:left; margin-bottom:10px;
    }
    .payment-method-row:active { background:var(--gray-50); }
    .pm-icon-img { width:38px;height:38px;border-radius:8px;object-fit:contain; flex-shrink:0; }
    .pm-row-title { font-size:13.5px;font-weight:800; }
    .pm-row-desc  { font-size:11px;color:var(--gray-400);margin-top:1px; }
    /* Make-a-Payment sheet */
    .pay-due { display:flex;justify-content:space-between;align-items:center;background:var(--gray-50);border-radius:14px;padding:12px 14px;margin-bottom:6px; }
    .pay-due.overdue { background:#fef2f2; }
    .pay-due-lbl { font-size:11px;color:var(--gray-400); }
    .pay-due-val { font-size:16px;font-weight:800; }
    .pay-due-flag { font-size:14px;font-weight:800;color:#dc2626; }
    .pay-due-flag.ok { color:var(--maroon);font-size:13px; }
    .pay-due-sub { font-size:11px;color:var(--gray-400); }
    .pay-due.overdue .pay-due-sub { color:#dc2626; }
    .pay-section { font-size:13px;font-weight:800;margin:14px 0 8px; }
    .pay-break { background:var(--maroon-pale);border-radius:14px;padding:10px 14px; }
    .pay-row { display:flex;justify-content:space-between;font-size:13px;padding:4px 0; }
    .pay-row.total { border-top:1px solid rgba(139,42,58,.15);margin-top:6px;padding-top:9px;font-weight:800; }
    .pay-row.total span:last-child { color:var(--maroon);font-size:16px; }
    .pay-row.muted { font-size:11.5px;color:var(--gray-400); }
    .pay-amt-box { display:flex;align-items:center;gap:8px;border:1.5px solid var(--gray-200);border-radius:14px;padding:0 12px;background:white; }
    .pay-amt-box:focus-within { border-color:var(--maroon); }
    .pay-amt-box.err { border-color:#dc2626;background:#fef2f2; }
    .pay-peso { font-size:18px;font-weight:800;color:var(--maroon); }
    .pay-amt-box input { flex:1;border:none;outline:none;background:transparent;font-size:20px;font-weight:800;padding:13px 0;min-width:0;font-family:inherit; }
    .pay-clear { border:none;background:var(--gray-200);color:white;width:22px;height:22px;border-radius:50%;font-size:11px;line-height:22px;padding:0;flex-shrink:0; }
    .pay-chips { display:flex;gap:8px;margin-top:8px;flex-wrap:wrap; }
    .pay-chip { border:1.5px solid var(--gray-200);background:white;border-radius:100px;padding:6px 12px;font-size:11.5px;font-weight:700;color:var(--maroon); }
    .pay-hint { font-size:11px;color:var(--gray-400);margin-top:6px;line-height:1.5; }
    .pay-hint.err { color:#dc2626;font-weight:700; }
    .pay-mode { display:flex;align-items:center;gap:12px;border:1.5px solid var(--gray-100);border-radius:14px;padding:11px 14px;margin-bottom:8px;background:white;cursor:pointer; }
    .pay-mode.selected { border-color:var(--maroon);background:var(--maroon-pale); }
    .pay-mode input { display:none; }
    .pay-mode-name { flex:1;font-size:13.5px;font-weight:700; }
    .pay-radio { width:20px;height:20px;border-radius:50%;border:2px solid var(--gray-200);flex-shrink:0;position:relative; }
    .pay-mode.selected .pay-radio { border-color:var(--maroon); }
    .pay-mode.selected .pay-radio::after { content:'';position:absolute;inset:3px;border-radius:50%;background:var(--maroon); }
  </style>
</head>
<body class="app-shell">

  <!-- App Bar -->
  <header class="app-bar maroon">
    <a href="customer_dashboard.php" class="app-bar-back">←</a>
    <div style="flex:1;min-width:0;">
      <div class="app-bar-title">My Loan</div>
      <div class="app-bar-sub">Applications &amp; repayments</div>
    </div>
    <?php if ($_modal_loan): ?>
    <button class="app-bar-action" onclick="openSheet('paymentMethodSheet')">💳</button>
    <?php endif; ?>
  </header>

  <main class="app-screen">

    <!-- Payment error banner -->
    <?php if ($_pay_error): ?>
    <div class="app-card" style="background:#fee2e2;color:#b91c1c;font-size:13px;font-weight:600;padding:14px;">
      ❌ <?= htmlspecialchars($_pay_error) ?>
    </div>
    <?php endif; ?>

    <!-- New application success banner -->
    <?php if ($new_app): ?>
    <div class="app-card" style="background:#dcfce7;color:#15803d;display:flex;align-items:center;gap:12px;">
      <span style="font-size:26px;">🎉</span>
      <div>
        <div style="font-weight:800;font-size:13.5px;">Application Submitted!</div>
        <div style="font-size:12px;">Application <strong><?= $new_app ?></strong> is now under review.</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Make Payment Button (sticky CTA if active loan) -->
    <?php if ($_modal_loan): ?>
    <button class="app-btn app-btn-primary" style="margin-bottom:16px;" onclick="openSheet('paymentMethodSheet')">
      💳 Make a Payment — ₱<?= number_format($_modal_loan['monthly_payment'],2) ?>
    </button>
    <?php endif; ?>

    <!-- ── NO APPLICATIONS ──────────────────────────────────────────── -->
    <?php if ($applications->num_rows === 0): ?>
    <div class="app-card app-empty">
      <div class="app-empty-icon">📋</div>
      <div class="app-empty-title">No Loan Applications Yet</div>
      <div class="app-empty-sub" style="margin-bottom:16px;">You haven't applied for a loan yet. Get started today!</div>
      <a href="customer_apply.php" class="app-btn app-btn-primary">📝 Apply for a Loan</a>
    </div>

    <?php else: ?>
    <!-- ── APPLICATION CARDS ───────────────────────────────────────── -->
    <?php $applications->data_seek(0); while ($app = $applications->fetch_assoc()):
      $si     = $status_info[$app['status']] ?? $status_info['pending'];
      $is_new = ($new_app === $app['app_id']);

      // Match active loan row
      $loan_row = null;
      if ($active_loans && $active_loans->num_rows > 0) {
          $active_loans->data_seek(0);
          while ($lr = $active_loans->fetch_assoc()) {
              if ($lr['app_id'] === $app['app_id']) { $loan_row = $lr; break; }
          }
      }
      $loan_payments = $loan_row ? ($payments_by_loan[(int)$loan_row['id']] ?? []) : [];
    ?>
    <div class="app-card-flat" style="<?= $is_new ? 'border:2px solid #86efac;' : '' ?>">

      <!-- Card header bar -->
      <div style="background:<?= $si['bg'] ?>;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px;">
        <div style="display:flex;align-items:center;gap:10px;min-width:0;">
          <span style="font-size:20px;flex-shrink:0;"><?= $si['icon'] ?></span>
          <div style="min-width:0;">
            <div style="font-weight:800;font-size:14px;color:var(--text-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
              <?= htmlspecialchars($app['app_id']) ?>
              <?php if ($is_new): ?>
                <span style="background:var(--maroon);color:white;font-size:9px;padding:1px 7px;border-radius:100px;margin-left:5px;">NEW</span>
              <?php endif; ?>
            </div>
            <div style="font-size:11px;color:var(--text-medium);">
              <?= htmlspecialchars($app['purpose'] ?? '') ?> · <?= date('M d, Y', strtotime($app['created_at'])) ?>
            </div>
          </div>
        </div>
        <div style="text-align:right;flex-shrink:0;">
          <div style="font-size:14px;font-weight:800;color:var(--maroon);">₱<?= number_format($app['loan_amount'],2) ?></div>
          <span class="pill <?= $app['status'] ?>"><?= $si['label'] ?></span>
        </div>
      </div>

      <div style="padding:16px;">

        <!-- Status message -->
        <div style="background:<?= $si['bg'] ?>;border-radius:10px;padding:11px 14px;margin-bottom:16px;font-size:12.5px;color:<?= $si['color'] ?>;font-weight:600;">
          <?= $si['icon'] ?> <?= $si['desc'] ?>
          <?php if ($app['status']==='rejected' && !empty($app['remarks'])): ?>
            <div style="margin-top:5px;font-weight:400;">Reason: <?= htmlspecialchars($app['remarks']) ?></div>
          <?php endif; ?>
        </div>

        <!-- Key figures -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid var(--gray-100);">
          <?php foreach ([
            ['Loan Amount',    '₱'.number_format($app['loan_amount'],2),         'var(--maroon)'],
            ['Term',           ($app['loan_term'] ?? '—').' months',             'var(--text-dark)'],
            ['Monthly Payment','₱'.number_format($app['monthly_payment'] ?? 0,2),'var(--text-dark)'],
            ['Interest Rate',  ($app['interest_rate'] ?? '5').'% p.a.',         'var(--text-dark)'],
          ] as [$lbl,$val,$clr]): ?>
          <div>
            <div style="font-size:10px;color:var(--gray-400);text-transform:uppercase;letter-spacing:.7px;margin-bottom:3px;font-weight:700;"><?= $lbl ?></div>
            <div style="font-size:15px;font-weight:800;color:<?= $clr ?>;"><?= $val ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Application Progress Timeline -->
        <div class="app-section-title" style="margin-bottom:8px;">Application Progress</div>
        <?php
        $steps = [
          ['Submitted',          'done',   date('M d, Y', strtotime($app['created_at']))],
          ['Under Review',       in_array($app['status'],['pending','approved','active','rejected','completed']) ? 'done' : 'wait', ''],
          ['Documents Verified', ($app['doc_verified']>0 && $app['doc_pending']==0 && $app['doc_rejected']==0) ? 'done' : ($app['doc_count']>0 ? 'wait' : 'future'), ''],
          ['Decision Made',      in_array($app['status'],['approved','active','rejected','completed']) ? 'done' : 'wait', !empty($app['approved_at']) ? date('M d, Y',strtotime($app['approved_at'])) : ''],
          ['Loan Activated',     in_array($app['status'],['active','completed']) ? 'done' : 'wait', ''],
        ];
        foreach ($steps as [$lbl,$state,$dt]):
          $bg = ['done'=>'#dcfce7','wait'=>'#fef3c7','future'=>'#f5f5f5'][$state];
          $ic = ['done'=>'✅','wait'=>'⏳','future'=>'○'][$state];
          $cl = ['done'=>'#16a34a','wait'=>'#d97706','future'=>'#ccc'][$state];
        ?>
        <div class="timeline-step">
          <div class="ts-dot" style="background:<?= $bg ?>"><?= $ic ?></div>
          <div>
            <div style="font-size:12.5px;font-weight:700;color:<?= $cl ?>"><?= $lbl ?></div>
            <?php if($dt): ?><div style="font-size:10.5px;color:var(--gray-400);"><?= $dt ?></div><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- Documents -->
        <div class="app-section-title" style="margin-top:16px;margin-bottom:8px;">Submitted Documents</div>
        <?php
        $app_docs = $conn->query("SELECT * FROM documents WHERE application_id={$app['id']} ORDER BY created_at ASC");
        if ($app_docs->num_rows === 0): ?>
          <div style="padding:16px;text-align:center;border:1px dashed var(--gray-200);border-radius:10px;color:var(--gray-400);font-size:12.5px;">
            📭 No documents submitted yet.
            <?php if ($app['status']==='pending'): ?>
              <div style="margin-top:6px;"><a href="customer_apply.php" style="color:var(--maroon);font-weight:700;">Upload →</a></div>
            <?php endif; ?>
          </div>
        <?php else: while ($d = $app_docs->fetch_assoc()):
          $dc = ['pending'=>['#fef3c7','#d97706','⏳'],'verified'=>['#dcfce7','#16a34a','✅'],'rejected'=>['#fee2e2','#dc2626','❌']][$d['status']] ?? ['#eff6ff','#2563eb','ℹ️'];
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 12px;background:<?= $dc[0] ?>;border-radius:10px;margin-bottom:7px;gap:10px;">
          <div style="display:flex;align-items:center;gap:8px;min-width:0;">
            <span><?= $dc[2] ?></span>
            <div style="font-size:12px;font-weight:700;color:var(--text-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($d['doc_type']) ?></div>
          </div>
          <span style="background:white;color:<?= $dc[1] ?>;font-size:10px;font-weight:800;padding:2px 8px;border-radius:100px;border:1px solid <?= $dc[1] ?>44;white-space:nowrap;flex-shrink:0;"><?= ucfirst($d['status']) ?></span>
        </div>
        <?php endwhile; endif; ?>
        <?php if ($app['doc_count'] > 0): ?>
        <div style="margin-top:8px;padding:9px 12px;background:var(--gray-50);border-radius:10px;font-size:11.5px;display:flex;gap:10px;flex-wrap:wrap;">
          <?php if($app['doc_verified']>0): ?><span style="color:#16a34a;font-weight:700;">✅ <?= $app['doc_verified'] ?> verified</span><?php endif; ?>
          <?php if($app['doc_pending']>0):  ?><span style="color:#d97706;font-weight:700;">⏳ <?= $app['doc_pending']  ?> pending</span><?php endif; ?>
          <?php if($app['doc_rejected']>0): ?><span style="color:#dc2626;font-weight:700;">❌ <?= $app['doc_rejected'] ?> rejected</span><?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── REPAYMENT SECTION (active loans only) ─────────────────── -->
        <?php if ($loan_row): ?>
        <?php
          $pct = $loan_row['loan_amount'] > 0
               ? min(100, round(($loan_row['amount_paid'] / $loan_row['loan_amount']) * 100))
               : 0;
        ?>
        <div style="border-top:1px solid var(--gray-100);margin-top:16px;padding-top:16px;">

          <div class="app-section-title" style="margin-bottom:8px;">Repayment Progress</div>

          <!-- Summary cards -->
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px;">
            <div class="ps-card">
              <div class="ps-label">Total Paid</div>
              <div class="ps-value ps-green">₱<?= number_format($loan_row['amount_paid'],0) ?></div>
              <div style="font-size:10px;color:var(--gray-400);margin-top:2px;"><?= count($loan_payments) ?> pmt(s)</div>
            </div>
            <div class="ps-card">
              <div class="ps-label">Balance</div>
              <div class="ps-value ps-red">₱<?= number_format($loan_row['outstanding_balance'],0) ?></div>
              <div style="font-size:10px;color:var(--gray-400);margin-top:2px;">
                <?= $loan_row['outstanding_balance'] <= 0 ? '✅ Settled' : 'of ₱'.number_format($loan_row['loan_amount'],0) ?>
              </div>
            </div>
            <div class="ps-card">
              <div class="ps-label">Next Due</div>
              <div class="ps-value ps-maroon" style="font-size:13px;">
                <?php if ($loan_row['outstanding_balance'] <= 0): ?>
                  🎉 Done
                <?php elseif ($loan_row['next_payment_date']): ?>
                  <?= date('M d', strtotime($loan_row['next_payment_date'])) ?>
                <?php else: ?>—<?php endif; ?>
              </div>
              <?php if ($loan_row['next_payment_date'] && $loan_row['outstanding_balance'] > 0): ?>
                <div style="font-size:10px;color:var(--gray-400);margin-top:2px;">₱<?= number_format($loan_row['monthly_payment'],0) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Progress bar -->
          <div style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;font-size:11.5px;margin-bottom:5px;">
              <span style="font-weight:700;color:var(--text-medium);">Repayment Progress</span>
              <span style="font-weight:800;color:var(--maroon);"><?= $pct ?>%</span>
            </div>
            <div style="height:8px;background:var(--gray-100);border-radius:100px;overflow:hidden;">
              <div style="height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg,var(--maroon),var(--maroon-light));border-radius:100px;"></div>
            </div>
          </div>

          <!-- Payment History (app-list-item style) -->
          <?php if (!empty($loan_payments)): ?>
          <div class="app-section-title" style="margin-bottom:6px;">Payment History</div>
          <div style="border:1px solid var(--gray-100);border-radius:12px;overflow:hidden;">
            <?php foreach ($loan_payments as $idx => $pay):
              $num = count($loan_payments) - $idx;
              $is_paid_full = $pay['balance_after'] <= 0;
            ?>
            <div class="app-list-item">
              <div class="app-list-icon" style="background:var(--maroon-pale);">
                <span style="font-size:13px;font-weight:800;color:var(--maroon);">#<?= $num ?></span>
              </div>
              <div class="app-list-body">
                <div class="app-list-title"><?= htmlspecialchars($pay['payment_id']) ?></div>
                <div class="app-list-sub">
                  <?= date('M d, Y',strtotime($pay['payment_date'])) ?> ·
                  <span class="method-chip"><?= str_replace('_',' ',$pay['payment_method']) ?></span>
                </div>
              </div>
              <div class="app-list-end">
                <div class="app-list-amount" style="color:#10b981;">+₱<?= number_format($pay['amount'],2) ?></div>
                <span class="pill <?= $pay['status'] ?>" style="margin-top:3px;"><?= ucfirst($pay['status']) ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <!-- Total row -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:var(--gray-50);border-radius:12px;margin-top:8px;">
            <span style="font-size:11.5px;font-weight:800;color:var(--text-medium);text-transform:uppercase;letter-spacing:.5px;">Total Paid</span>
            <span style="font-size:15px;font-weight:800;color:#10b981;">₱<?= number_format($loan_row['amount_paid'],2) ?></span>
          </div>
          <?php else: ?>
          <div class="app-empty" style="padding:24px;">
            <div class="app-empty-icon" style="font-size:32px;">📭</div>
            <div class="app-empty-sub">No payments recorded yet.</div>
            <?php if ($_modal_loan): ?>
            <button onclick="openSheet('paymentMethodSheet')" class="app-btn app-btn-primary app-btn-sm" style="margin-top:12px;">
              💳 Make First Payment
            </button>
            <?php endif; ?>
          </div>
          <?php endif; ?>

        </div>
        <?php endif; // loan_row ?>

      </div><!-- /card padding -->
    </div><!-- /app-card-flat -->
    <?php endwhile; ?>
    <?php endif; // applications exist ?>

  </main>

  <!-- ═══════════════════════════════════════
       PAYMENT BOTTOM SHEETS
       ═══════════════════════════════════════ -->
  <div class="sheet-overlay" id="ov_paymentMethodSheet" onclick="closeSheet('paymentMethodSheet')"></div>
  <div class="bottom-sheet" id="paymentMethodSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">Make a Payment</div>
      <button class="sheet-close" onclick="closeSheet('paymentMethodSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <?php if ($_modal_loan): ?>
      <!-- Due date / status -->
      <div class="pay-due <?= $_pay_overdue > 0 ? 'overdue' : '' ?>">
        <div>
          <div class="pay-due-lbl">Due Date</div>
          <div class="pay-due-val"><?= $_pay_due ? date('M d, Y', strtotime($_pay_due)) : '—' ?></div>
        </div>
        <div style="text-align:right;">
          <?php if ($_pay_overdue > 0): ?>
            <div class="pay-due-flag">⚠️ Overdue</div>
            <div class="pay-due-sub"><?= $_pay_overdue ?> day<?= $_pay_overdue!=1?'s':'' ?> overdue</div>
          <?php else: ?>
            <div class="pay-due-flag ok"><?= htmlspecialchars($_modal_loan['app_id']) ?></div>
            <div class="pay-due-sub">On schedule</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Breakdown -->
      <div class="pay-section">Payment Breakdown</div>
      <div class="pay-break">
        <div class="pay-row"><span>Principal Amount</span><span>₱<?= number_format($_pay_princ,2) ?></span></div>
        <div class="pay-row"><span>Interest</span><span>₱<?= number_format($_pay_int,2) ?></span></div>
        <div class="pay-row total"><span>Installment Due</span><span>₱<?= number_format($_pay_monthly,2) ?></span></div>
        <div class="pay-row muted"><span>Outstanding Balance</span><span>₱<?= number_format($_pay_balance,2) ?></span></div>
      </div>

      <!-- Editable amount -->
      <div class="pay-section">Payment Amount</div>
      <div class="pay-amt-box" id="payAmtBox">
        <span class="pay-peso">₱</span>
        <input type="text" inputmode="decimal" id="payAmtInput" autocomplete="off"
               value="<?= number_format($_pay_monthly,2,'.','') ?>" oninput="onPayAmtInput()" onblur="fmtPayAmt()"/>
        <button type="button" class="pay-clear" onclick="clearPayAmt()" aria-label="Clear amount">✕</button>
      </div>
      <div class="pay-chips">
        <button type="button" class="pay-chip" onclick="setPayAmt(<?= json_encode(round($_pay_monthly,2)) ?>)">Installment ₱<?= number_format($_pay_monthly,2) ?></button>
        <button type="button" class="pay-chip" onclick="setPayAmt(<?= json_encode(round($_pay_balance,2)) ?>)">Full balance ₱<?= number_format($_pay_balance,2) ?></button>
      </div>
      <div class="pay-hint" id="payAmtHint">You can change the amount. Minimum ₱1.00, maximum ₱<?= number_format($_pay_balance,2) ?> (your outstanding balance).</div>

      <!-- Mode of payment -->
      <div class="pay-section">Mode of Payment</div>
      <label class="pay-mode selected" data-method="gcash">
        <input type="radio" name="pay_mode" value="gcash" checked onchange="selectPayMode(this)"/>
        <img class="pm-icon-img" src="assets/images/gcash.jpeg" alt="GCash"/>
        <span class="pay-mode-name">GCash</span><span class="pay-radio"></span>
      </label>
      <label class="pay-mode" data-method="bank">
        <input type="radio" name="pay_mode" value="bank" onchange="selectPayMode(this)"/>
        <img class="pm-icon-img" src="assets/images/bank_transfer.png" alt="Bank"/>
        <span class="pay-mode-name">Bank Transfer</span><span class="pay-radio"></span>
      </label>
      <label class="pay-mode" data-method="cash">
        <input type="radio" name="pay_mode" value="cash" onchange="selectPayMode(this)"/>
        <img class="pm-icon-img" src="assets/images/cash_collection.jpg" alt="Cash"/>
        <span class="pay-mode-name">Cash Collection</span><span class="pay-radio"></span>
      </label>

      <button type="button" class="app-btn app-btn-primary" style="margin-top:14px;" onclick="proceedToPayment()">Proceed to Payment →</button>
      <?php else: ?>
      <div class="app-empty-sub" style="text-align:center;padding:20px;">You have no active loan to pay right now.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- GCash Sheet -->
  <div class="sheet-overlay" id="ov_gcashSheet" onclick="closeSheet('gcashSheet')"></div>
  <div class="bottom-sheet" id="gcashSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div style="display:flex;align-items:center;gap:10px;">
        <img src="assets/images/gcash.jpeg" alt="GCash" style="width:28px;height:28px;border-radius:6px;object-fit:contain;"/>
        <div class="sheet-title">GCash Payment</div>
      </div>
      <button class="sheet-close" onclick="closeSheet('gcashSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <?php if ($_modal_loan): ?>
      <div style="background:var(--maroon-pale);border-radius:14px;padding:16px;text-align:center;margin-bottom:14px;">
        <div style="font-size:11px;color:var(--text-light);">Amount to Pay</div>
        <div style="font-size:26px;font-weight:800;color:var(--maroon);">₱<span class="payAmt"><?= number_format($_pay_monthly,2) ?></span></div>
        <div style="font-size:11px;color:var(--text-light);"><?= htmlspecialchars($_modal_loan['app_id']) ?></div>
      </div>
      <div style="border:1.5px solid var(--gray-100);border-radius:14px;padding:14px;margin-bottom:14px;">
        <div style="font-size:12px;font-weight:700;color:var(--text-dark);margin-bottom:10px;">Send payment to:</div>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div>
            <div style="font-size:11px;color:var(--gray-400);">GCash Number</div>
            <div style="font-size:16px;font-weight:800;color:var(--maroon);" id="gcashNum">09307158807</div>
            <div style="font-size:11px;color:var(--gray-400);margin-top:2px;">Velveth Lending Co.</div>
          </div>
          <button onclick="copyVal('gcashNum','gcashCopied')" class="app-btn app-btn-secondary app-btn-sm" style="width:auto;padding:8px 14px;">📋 Copy</button>
        </div>
        <div id="gcashCopied" style="font-size:11px;color:#15803d;display:none;margin-top:5px;">✓ Copied!</div>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="submit_payment"/>
        <input type="hidden" name="loan_id" value="<?= $_modal_loan['id'] ?>"/>
        <input type="hidden" name="payment_method" value="gcash"/>
        <input type="hidden" name="amount" class="payAmtInput" value="<?= number_format($_pay_monthly,2,'.','') ?>"/>
        <div class="app-form-group">
          <label class="app-form-label">Your GCash Number</label>
          <input type="tel" name="gcash_number" class="app-input" placeholder="09XX-XXX-XXXX"/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">GCash Reference Number *</label>
          <input type="text" name="reference_number" class="app-input" placeholder="13-digit reference number" required/>
          <div style="font-size:11px;color:var(--gray-400);margin-top:4px;">Found in your GCash transaction receipt</div>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Notes (Optional)</label>
          <textarea name="payment_notes" class="app-input" rows="2" maxlength="100" placeholder="Add payment notes..." oninput="this.nextElementSibling.textContent=this.value.length+'/100'" style="resize:none;"></textarea>
          <div style="text-align:right;font-size:10.5px;color:var(--gray-400);margin-top:3px;">0/100</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px;">
          <button type="button" onclick="switchSheet('gcashSheet','paymentMethodSheet')" class="app-btn app-btn-secondary">← Back</button>
          <button type="submit" class="app-btn app-btn-primary">✓ Confirm Payment</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Bank Transfer Sheet -->
  <div class="sheet-overlay" id="ov_bankSheet" onclick="closeSheet('bankSheet')"></div>
  <div class="bottom-sheet" id="bankSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div style="display:flex;align-items:center;gap:10px;">
        <img src="assets/images/bank_transfer.png" alt="Bank" style="width:28px;height:28px;border-radius:6px;object-fit:contain;"/>
        <div class="sheet-title">Bank Transfer</div>
      </div>
      <button class="sheet-close" onclick="closeSheet('bankSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <?php if ($_modal_loan): ?>
      <div style="background:var(--maroon-pale);border-radius:14px;padding:16px;text-align:center;margin-bottom:14px;">
        <div style="font-size:11px;color:var(--text-light);">Amount to Pay</div>
        <div style="font-size:26px;font-weight:800;color:var(--maroon);">₱<span class="payAmt"><?= number_format($_pay_monthly,2) ?></span></div>
        <div style="font-size:11px;color:var(--text-light);"><?= htmlspecialchars($_modal_loan['app_id']) ?></div>
      </div>
      <div style="border:1.5px solid var(--gray-100);border-radius:14px;padding:14px;margin-bottom:14px;">
        <div style="font-size:12px;font-weight:700;margin-bottom:10px;">Bank Account Details</div>
        <div style="margin-bottom:7px;"><div style="font-size:11px;color:var(--gray-400);">Bank Name</div><div style="font-size:13px;font-weight:700;">BDO Unibank</div></div>
        <div style="margin-bottom:7px;"><div style="font-size:11px;color:var(--gray-400);">Account Name</div><div style="font-size:13px;font-weight:700;">Velveth Lending Company</div></div>
        <div>
          <div style="font-size:11px;color:var(--gray-400);">Account Number</div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:3px;">
            <div style="font-size:15px;font-weight:800;color:var(--maroon);" id="bankAcct">1234-5678-9011</div>
            <button onclick="copyVal('bankAcct','bankCopied')" class="app-btn app-btn-secondary app-btn-sm" style="width:auto;padding:8px 14px;">📋 Copy</button>
          </div>
          <div id="bankCopied" style="font-size:11px;color:#15803d;display:none;margin-top:4px;">✓ Copied!</div>
        </div>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="submit_payment"/>
        <input type="hidden" name="loan_id" value="<?= $_modal_loan['id'] ?>"/>
        <input type="hidden" name="payment_method" value="bank_transfer"/>
        <input type="hidden" name="amount" class="payAmtInput" value="<?= number_format($_pay_monthly,2,'.','') ?>"/>
        <div class="app-form-group">
          <label class="app-form-label">Bank Reference Number *</label>
          <input type="text" name="reference_number" class="app-input" placeholder="Transaction reference number" required/>
          <div style="font-size:11px;color:var(--gray-400);margin-top:4px;">Found in your bank receipt</div>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Notes (Optional)</label>
          <textarea name="payment_notes" class="app-input" rows="2" maxlength="100" placeholder="Add payment notes..." oninput="this.nextElementSibling.textContent=this.value.length+'/100'" style="resize:none;"></textarea>
          <div style="text-align:right;font-size:10.5px;color:var(--gray-400);margin-top:3px;">0/100</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px;">
          <button type="button" onclick="switchSheet('bankSheet','paymentMethodSheet')" class="app-btn app-btn-secondary">← Back</button>
          <button type="submit" class="app-btn app-btn-primary">✓ Confirm Payment</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Cash Collection Sheet -->
  <div class="sheet-overlay" id="ov_cashSheet" onclick="closeSheet('cashSheet')"></div>
  <div class="bottom-sheet" id="cashSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div style="display:flex;align-items:center;gap:10px;">
        <img src="assets/images/cash_collection.jpg" alt="Cash" style="width:28px;height:28px;border-radius:6px;object-fit:contain;"/>
        <div class="sheet-title">Cash Collection</div>
      </div>
      <button class="sheet-close" onclick="closeSheet('cashSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <?php if ($_modal_loan): ?>
      <div style="background:var(--maroon-pale);border-radius:14px;padding:16px;text-align:center;margin-bottom:14px;">
        <div style="font-size:11px;color:var(--text-light);">Amount to Pay</div>
        <div style="font-size:26px;font-weight:800;color:var(--maroon);">₱<span class="payAmt"><?= number_format($_pay_monthly,2) ?></span></div>
        <div style="font-size:11px;color:var(--text-light);"><?= htmlspecialchars($_modal_loan['app_id']) ?></div>
      </div>
      <div style="background:#fef9c3;border:1px solid #fde047;border-radius:14px;padding:14px;margin-bottom:12px;">
        <div style="font-size:12px;font-weight:700;color:#a16207;margin-bottom:8px;">💰 Cash Collection Info</div>
        <ul style="font-size:11.5px;color:#a16207;line-height:1.9;list-style:none;padding:0;margin:0;">
          <li>• Our authorized collector will visit your registered address</li>
          <li>• Please prepare the exact amount</li>
          <li>• Collection hours: 9:00 AM – 5:00 PM (Mon–Sat)</li>
        </ul>
      </div>
      <div style="background:#dcfce7;border:1px solid #86efac;border-radius:14px;padding:12px 14px;margin-bottom:14px;font-size:12px;color:#15803d;">
        💡 Please prepare the exact amount shown above for the collector.
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="submit_payment"/>
        <input type="hidden" name="loan_id" value="<?= $_modal_loan['id'] ?>"/>
        <input type="hidden" name="payment_method" value="cash_collection"/>
        <input type="hidden" name="amount" class="payAmtInput" value="<?= number_format($_pay_monthly,2,'.','') ?>"/>
        <input type="hidden" name="reference_number" value=""/>
        <div class="app-form-group">
          <label class="app-form-label">Preferred Collection Date *</label>
          <input type="date" name="collection_date" class="app-input" required min="<?= date('Y-m-d') ?>"/>
          <div style="font-size:11px;color:var(--gray-400);margin-top:4px;">Our collector will visit your registered address on this date.</div>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Notes (Optional)</label>
          <textarea name="payment_notes" class="app-input" rows="2" maxlength="100" placeholder="Add payment notes..." oninput="this.nextElementSibling.textContent=this.value.length+'/100'" style="resize:none;"></textarea>
          <div style="text-align:right;font-size:10.5px;color:var(--gray-400);margin-top:3px;">0/100</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px;">
          <button type="button" onclick="switchSheet('cashSheet','paymentMethodSheet')" class="app-btn app-btn-secondary">← Back</button>
          <button type="submit" class="app-btn app-btn-primary">✓ Confirm Payment</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Payment Success Sheet -->
  <?php if ($_pay_success): ?>
  <div class="sheet-overlay open" id="ov_successSheet" onclick="closeSheet('successSheet')"></div>
  <div class="bottom-sheet open" id="successSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">Payment Submitted!</div>
      <button class="sheet-close" onclick="closeSheet('successSheet')">✕</button>
    </div>
    <div class="sheet-body" style="text-align:center;">
      <div style="background:#dcfce7;border-radius:16px;padding:22px;margin-bottom:16px;">
        <div style="font-size:42px;margin-bottom:8px;">✅</div>
        <div style="font-weight:800;font-size:15px;color:#15803d;margin-bottom:5px;">Payment Submitted!</div>
        <div style="font-size:12px;color:#166534;line-height:1.6;">Being verified. You'll be notified once confirmed.</div>
      </div>
      <?php $pm = $_pay_success['method']; ?>
      <div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:14px;">
        <img src="assets/images/<?= $pm==='gcash'?'gcash.jpeg':($pm==='bank_transfer'?'bank_transfer.png':'cash_collection.jpg') ?>"
             style="width:32px;height:32px;border-radius:8px;object-fit:contain;" alt=""/>
        <div style="font-size:13.5px;font-weight:800;"><?= ucwords(str_replace('_',' ',$pm)) ?></div>
      </div>
      <div style="background:var(--gray-50);border-radius:12px;padding:14px;text-align:left;">
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
          <span style="font-size:12px;color:var(--gray-400);">Payment ID</span>
          <span style="font-size:12px;font-weight:700;color:var(--maroon);"><?= htmlspecialchars($_pay_success['id']) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;<?= $_pay_success['ref']?'margin-bottom:8px;':'' ?>">
          <span style="font-size:12px;color:var(--gray-400);">Amount</span>
          <span style="font-size:13px;font-weight:800;color:var(--maroon);">₱<?= number_format($_pay_success['amount'],2) ?></span>
        </div>
        <?php if ($_pay_success['ref']): ?>
        <div style="display:flex;justify-content:space-between;">
          <span style="font-size:12px;color:var(--gray-400);">Reference</span>
          <span style="font-size:12px;font-weight:700;"><?= htmlspecialchars($_pay_success['ref']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($_pay_success['coll_dt']): ?>
        <div style="display:flex;justify-content:space-between;margin-top:8px;">
          <span style="font-size:12px;color:var(--gray-400);">Collection Date</span>
          <span style="font-size:12px;font-weight:700;"><?= date('F d, Y', strtotime($_pay_success['coll_dt'])) ?></span>
        </div>
        <?php endif; ?>
      </div>
      <button onclick="closeSheet('successSheet')" class="app-btn app-btn-success" style="margin-top:16px;">Done ✓</button>
    </div>
  </div>
  <?php endif; ?>

  <?php include 'app_tabbar.php'; ?>

<script>
function openSheet(id) {
  document.getElementById('ov_'+id)?.classList.add('open');
  document.getElementById(id)?.classList.add('open');
}
function closeSheet(id) {
  document.getElementById('ov_'+id)?.classList.remove('open');
  document.getElementById(id)?.classList.remove('open');
}
function switchSheet(fromId, toId) {
  closeSheet(fromId);
  openSheet(toId);
}
// ── Make-a-Payment: editable amount + mode of payment ──
const PAY_MIN = 1;
const PAY_MAX = <?= json_encode(round($_pay_balance,2)) ?>;
function parsePayAmt() {
  const v = parseFloat((document.getElementById('payAmtInput')?.value || '').replace(/,/g,''));
  return isNaN(v) ? 0 : Math.round(v * 100) / 100;
}
function payAmtError() {
  const a = parsePayAmt();
  if (a < PAY_MIN) return 'Enter an amount of at least ₱1.00.';
  if (a > PAY_MAX + 0.005) return 'Amount cannot be more than your outstanding balance (₱' + PAY_MAX.toLocaleString('en-PH',{minimumFractionDigits:2}) + ').';
  return '';
}
function onPayAmtInput() {
  const el = document.getElementById('payAmtInput');
  el.value = el.value.replace(/[^0-9.]/g,'').replace(/(\..*)\./g,'$1'); // digits + one dot only
  const err = payAmtError();
  document.getElementById('payAmtBox').classList.toggle('err', !!err && el.value !== '');
  const hint = document.getElementById('payAmtHint');
  hint.classList.toggle('err', !!err && el.value !== '');
  if (err && el.value !== '') hint.textContent = err;
  else hint.textContent = 'You can change the amount. Minimum ₱1.00, maximum ₱' + PAY_MAX.toLocaleString('en-PH',{minimumFractionDigits:2}) + ' (your outstanding balance).';
}
function fmtPayAmt() { const a = parsePayAmt(); if (a > 0) document.getElementById('payAmtInput').value = a.toFixed(2); }
function setPayAmt(v) { document.getElementById('payAmtInput').value = Number(v).toFixed(2); onPayAmtInput(); }
function clearPayAmt() { const el = document.getElementById('payAmtInput'); el.value = ''; el.focus(); onPayAmtInput(); }
function selectPayMode(radio) {
  document.querySelectorAll('.pay-mode').forEach(l => l.classList.remove('selected'));
  radio.closest('.pay-mode').classList.add('selected');
}
function proceedToPayment() {
  const err = payAmtError();
  if (err) {
    document.getElementById('payAmtBox').classList.add('err');
    const hint = document.getElementById('payAmtHint'); hint.classList.add('err'); hint.textContent = err;
    document.getElementById('payAmtInput').focus();
    return;
  }
  const amt = parsePayAmt();
  document.querySelectorAll('.payAmtInput').forEach(i => i.value = amt.toFixed(2));
  document.querySelectorAll('.payAmt').forEach(e => e.textContent = amt.toLocaleString('en-PH',{minimumFractionDigits:2}));
  const mode = document.querySelector('input[name=pay_mode]:checked')?.value || 'gcash';
  switchSheet('paymentMethodSheet', mode + 'Sheet');
}
function copyVal(elId, badgeId) {
  const val = document.getElementById(elId)?.textContent?.trim();
  if (!val) return;
  navigator.clipboard.writeText(val).then(() => {
    const b = document.getElementById(badgeId);
    if (b) { b.style.display = 'block'; setTimeout(() => b.style.display = 'none', 2000); }
  });
}
</script>

<?php include 'chatbot.php'; ?>
  <script src="realtime.js"></script>
</body>
</html>
