<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'customer') {
    header("Location: customer_login.php"); exit;
}
$user_id = (int)$_SESSION['user_id'];

// ── Handle Penalty Payment Submission (must be before HTML) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_penalty') {
    $pen_id_db = (int)($_POST['penalty_id'] ?? 0);
    $method    = trim($_POST['payment_method'] ?? '');
    $ref       = trim($_POST['reference_number'] ?? '');
    $gcash_num = trim($_POST['gcash_number']     ?? '');
    $coll_date = trim($_POST['collection_date']  ?? '');

    // Verify penalty belongs to this customer and is still pending
    $pen = $conn->query("
        SELECT pen.*, la.app_id
        FROM penalties pen
        LEFT JOIN loans l     ON pen.loan_id = l.id
        LEFT JOIN loan_applications la ON l.application_id = la.id
        WHERE pen.id = $pen_id_db
          AND pen.customer_id = $user_id
          AND pen.status IN ('pending','unpaid')
    ")->fetch_assoc();

    if (!$pen || !$method) {
        $_SESSION['pen_error'] = 'Invalid request. Please try again.';
    } else {
        // Generate payment record
        $pay_num = (int)($conn->query("SELECT COUNT(*)+1 c FROM payments")->fetch_assoc()['c'] ?? 1);
        $pay_id  = 'PAY-PEN-' . str_pad($pay_num, 4, '0', STR_PAD_LEFT);
        $amount  = (float)$pen['penalty_amount'];
        $today   = date('Y-m-d');
        $notes   = match($method) {
            'gcash'           => "Penalty Payment | GCash Ref: $ref" . ($gcash_num ? " | From: $gcash_num" : ''),
            'bank_transfer'   => "Penalty Payment | Bank Ref: $ref",
            'cash_collection' => "Penalty Payment | Cash Collection on $coll_date",
            default           => "Penalty Payment | Ref: $ref",
        };

        // We store penalty payments in the payments table with loan_id
        // If no loan, use a dummy entry in notes only; mark penalty as "paid" after admin confirms
        $loan_id = (int)($pen['loan_id'] ?? 0);

        if ($loan_id > 0) {
            // Auto-heal ENUM if older schema doesn't support this method
            $enum_check = $conn->query("SHOW COLUMNS FROM payments LIKE 'payment_method'")->fetch_assoc();
            if ($enum_check && stripos($enum_check['Type'], $method) === false) {
                @$conn->query("ALTER TABLE payments MODIFY COLUMN payment_method ENUM('cash','gcash','bank_transfer','bank','cash_collection','check') DEFAULT 'cash'");
            }

            $stmt = $conn->prepare(
                "INSERT INTO payments (payment_id,loan_id,customer_id,amount,payment_method,payment_date,notes,status)
                 VALUES (?,?,?,?,?,?,?,'pending')"
            );
            if ($stmt) {
                $stmt->bind_param("siidsss", $pay_id, $loan_id, $user_id, $amount, $method, $today, $notes);
                if (!$stmt->execute()) {
                    $_SESSION['pen_error'] = 'Failed to record payment: ' . $stmt->error;
                }
                $stmt->close();
            }
        } else {
            $_SESSION['pen_error'] = 'This penalty is not linked to an active loan. Please contact support.';
        }

        if (!isset($_SESSION['pen_error'])) {
        // Mark penalty as "pending_payment" by adding a note — admin will confirm
        // Update penalty status to show payment submitted
        $pay_ref = mysqli_real_escape_string($conn, $pay_id);
        $conn->query("UPDATE penalties SET status='pending', reason=CONCAT(reason, ' [Payment Submitted: $pay_ref]') WHERE id=$pen_id_db");

        // In-app notifications
        sendNotification($conn, $user_id,
            '💳 Penalty Payment Submitted',
            "Your payment of ₱".number_format($amount,2)." for penalty has been submitted via ".ucwords(str_replace('_',' ',$method)).". Reference: $pay_id.",
            'info'
        );
        notifyAdmins($conn,
            "⚠️ Penalty Payment Submitted — $pay_id",
            "{$_SESSION['fullname']} submitted penalty payment of ₱".number_format($amount,2)." via $method for Penalty ID #{$pen_id_db}.",
            'warning'
        );
        logAudit($conn, 'Penalty', $_SESSION['fullname'],
            "Submitted penalty payment $pay_id",
            "Penalty #{$pen_id_db} | ₱".number_format($amount,2)." via $method"
        );

        // Email notification
        $cust = $conn->query("SELECT email,full_name FROM users WHERE id=$user_id")->fetch_assoc();
        if ($cust && function_exists('notifyAccountUpdate')) {
            @notifyAccountUpdate(
                $cust['email'], $cust['full_name'],
                'Penalty Payment Submitted',
                "Payment of ₱".number_format($amount,2)." submitted via ".ucwords(str_replace('_',' ',$method)).". Reference: $pay_id. Admin will confirm shortly."
            );
        }

        $_SESSION['pen_success'] = [
            'pay_id'  => $pay_id,
            'amount'  => $amount,
            'method'  => $method,
            'ref'     => $ref,
            'coll_dt' => $coll_date,
        ];
        } // end if no pen_error
    }
    header("Location: customer_penalties.php"); exit;
}

// ── Page data ──────────────────────────────────────────────────────────────
$pen_success = null;
$pen_error   = '';
if (isset($_SESSION['pen_success'])) { $pen_success = $_SESSION['pen_success']; unset($_SESSION['pen_success']); }
if (isset($_SESSION['pen_error']))   { $pen_error   = $_SESSION['pen_error'];   unset($_SESSION['pen_error']); }

$penalties  = $conn->query("
    SELECT pen.*,
           COALESCE(la.app_id, '—') AS app_id
    FROM penalties pen
    LEFT JOIN loans l ON pen.loan_id = l.id
    LEFT JOIN loan_applications la ON l.application_id = la.id
    WHERE pen.customer_id = $user_id
    ORDER BY pen.created_at DESC
");

$total_pen     = (float)($conn->query("SELECT SUM(penalty_amount) s FROM penalties WHERE customer_id=$user_id AND status IN ('pending','unpaid')")->fetch_assoc()['s'] ?? 0);
$pending_count = (int)($conn->query("SELECT COUNT(*) c FROM penalties WHERE customer_id=$user_id AND status IN ('pending','unpaid')")->fetch_assoc()['c'] ?? 0);
$paid_count    = (int)($conn->query("SELECT COUNT(*) c FROM penalties WHERE customer_id=$user_id AND status='paid'")->fetch_assoc()['c'] ?? 0);
$_cur = 'customer_penalties.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>My Penalties | Velveth Lending</title>
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
    .pen-card {
      background:var(--white);border-radius:16px;box-shadow:var(--shadow-sm);
      margin-bottom:12px;overflow:hidden;
    }
    .pen-card-header {
      display:flex;align-items:center;justify-content:space-between;
      padding:14px 16px;border-bottom:1px solid var(--gray-100);
    }
    .pen-row {
      display:flex;justify-content:space-between;align-items:center;
      padding:10px 16px;border-bottom:1px solid var(--gray-100);font-size:12.5px;
    }
    .pen-row:last-child{border-bottom:none;}
    .pen-row-label { color:var(--gray-400);font-weight:600;font-size:11.5px; }
    .pen-row-val   { font-weight:700;color:var(--text-dark);text-align:right; }
    .overdue-chip  { background:#fee2e2;color:#dc2626;font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:100px; }
    .payment-method-row {
      display:flex;align-items:center;gap:12px;width:100%;
      padding:14px;border-radius:14px;border:1.5px solid var(--gray-100);
      background:white;cursor:pointer;text-align:left;margin-bottom:10px;
    }
    .payment-method-row:active{background:var(--gray-50);}
    .pm-icon-img{width:38px;height:38px;border-radius:8px;object-fit:contain;flex-shrink:0;}
    .pm-row-title{font-size:13.5px;font-weight:800;}
    .pm-row-desc{font-size:11px;color:var(--gray-400);margin-top:1px;}
  </style>
</head>
<body class="app-shell">

  <!-- App Bar -->
  <header class="app-bar maroon">
    <a href="customer_dashboard.php" class="app-bar-back">←</a>
    <div style="flex:1;min-width:0;">
      <div class="app-bar-title">My Penalties</div>
      <div class="app-bar-sub">Outstanding dues on your account</div>
    </div>
  </header>

  <main class="app-screen">

    <!-- Error / Success -->
    <?php if ($pen_error): ?>
    <div class="app-card" style="background:#fee2e2;color:#b91c1c;font-size:12.5px;font-weight:600;">
      ❌ <?= htmlspecialchars($pen_error) ?>
    </div>
    <?php endif; ?>

    <!-- Outstanding banner -->
    <?php if ($total_pen > 0): ?>
    <div style="background:linear-gradient(135deg,#b91c1c,#ef4444);border-radius:18px;padding:18px 20px;margin-bottom:16px;color:white;display:flex;align-items:center;justify-content:space-between;gap:12px;">
      <div style="display:flex;align-items:center;gap:12px;">
        <span style="font-size:28px;">⚠️</span>
        <div>
          <div style="font-size:10px;opacity:.8;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px;">Outstanding Penalties</div>
          <div style="font-size:22px;font-weight:800;">₱<?= number_format($total_pen,2) ?></div>
          <div style="font-size:11px;opacity:.8;margin-top:2px;"><?= $pending_count ?> pending · settle ASAP</div>
        </div>
      </div>
    </div>
    <?php elseif ($paid_count > 0): ?>
    <div style="background:linear-gradient(135deg,#15803d,#10b981);border-radius:18px;padding:18px 20px;margin-bottom:16px;color:white;">
      <div style="font-size:26px;margin-bottom:6px;">🎉</div>
      <div style="font-weight:800;font-size:14px;">All Penalties Settled!</div>
      <div style="font-size:11px;opacity:.8;margin-top:2px;">Great job keeping up with your payments.</div>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <?php if ($penalties->num_rows > 0): ?>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;">
      <div class="app-card" style="padding:12px;text-align:center;">
        <div style="font-size:20px;font-weight:800;color:#ef4444;"><?= $pending_count ?></div>
        <div style="font-size:10px;color:var(--gray-400);font-weight:700;text-transform:uppercase;margin-top:2px;">Pending</div>
      </div>
      <div class="app-card" style="padding:12px;text-align:center;">
        <div style="font-size:20px;font-weight:800;color:#10b981;"><?= $paid_count ?></div>
        <div style="font-size:10px;color:var(--gray-400);font-weight:700;text-transform:uppercase;margin-top:2px;">Paid</div>
      </div>
      <div class="app-card" style="padding:12px;text-align:center;">
        <div style="font-size:16px;font-weight:800;color:#ef4444;">₱<?= number_format($total_pen,0) ?></div>
        <div style="font-size:10px;color:var(--gray-400);font-weight:700;text-transform:uppercase;margin-top:2px;">Total Due</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Penalty Cards -->
    <?php if ($penalties->num_rows === 0): ?>
    <div class="app-card app-empty">
      <div class="app-empty-icon">✅</div>
      <div class="app-empty-title">No Penalties!</div>
      <div class="app-empty-sub">Your account has no penalty records. Keep making payments on time!</div>
    </div>
    <?php else: while ($row = $penalties->fetch_assoc()):
      $is_pending  = in_array($row['status'], ['pending','unpaid']);
      $is_overdue  = $row['due_date'] && strtotime($row['due_date']) < time() && $is_pending;
      $pen_db_id   = (int)$row['id'];
    ?>
    <div class="pen-card">
      <!-- Header -->
      <div class="pen-card-header">
        <div>
          <div style="font-weight:800;font-size:13px;color:var(--maroon);">
            <?= htmlspecialchars($row['penalty_id'] ?? '#'.$row['id']) ?>
            <?php if ($is_overdue): ?><span class="overdue-chip">OVERDUE</span><?php endif; ?>
          </div>
          <div style="font-size:10.5px;color:var(--gray-400);margin-top:1px;"><?= date('F d, Y', strtotime($row['created_at'])) ?></div>
        </div>
        <div style="text-align:right;">
          <div style="font-size:20px;font-weight:800;color:#ef4444;">₱<?= number_format($row['penalty_amount'],2) ?></div>
          <span class="pill <?= $row['status'] ?>"><?= ucfirst($row['status']) ?></span>
        </div>
      </div>
      <!-- Details -->
      <div class="pen-row">
        <span class="pen-row-label">Loan</span>
        <span class="pen-row-val" style="color:var(--maroon);"><?= htmlspecialchars($row['app_id']) ?></span>
      </div>
      <div class="pen-row">
        <span class="pen-row-label">Reason</span>
        <span class="pen-row-val" style="max-width:60%;text-align:right;"><?= htmlspecialchars($row['reason'] ?? '—') ?></span>
      </div>
      <div class="pen-row">
        <span class="pen-row-label">Due Date</span>
        <span class="pen-row-val" style="color:<?= $is_overdue?'#ef4444':'var(--text-dark)' ?>;">
          <?= $row['due_date'] ? date('M d, Y', strtotime($row['due_date'])) : 'N/A' ?>
        </span>
      </div>
      <?php if ($is_pending): ?>
      <div style="padding:12px 16px;">
        <button class="app-btn app-btn-primary"
          onclick="openPenPaySheet(<?= $pen_db_id ?>, <?= $row['penalty_amount'] ?>, '<?= htmlspecialchars(addslashes($row['penalty_id'] ?? '#'.$row['id'])) ?>')">
          💳 Pay This Penalty
        </button>
      </div>
      <?php endif; ?>
    </div>
    <?php endwhile; endif; ?>

    <!-- Policy -->
    <div class="app-card" style="background:#fef9c3;border:1px solid #fde047;">
      <div style="font-size:13px;font-weight:800;color:#a16207;margin-bottom:8px;">ℹ️ Penalty Policy</div>
      <ul style="font-size:12px;color:#a16207;line-height:1.9;list-style:none;padding:0;margin:0;">
        <li>• Late payment fee: <strong>₱50/day</strong> for the first 5 days</li>
        <li>• After 5 days: <strong>+5%</strong> of the weekly payment amount</li>
        <li>• Penalties must be settled before the next payment</li>
      </ul>
    </div>

  </main>

  <!-- ═══════════════════════════════════════
       PENALTY PAYMENT BOTTOM SHEETS
       ═══════════════════════════════════════ -->

  <!-- Choose Method Sheet -->
  <div class="sheet-overlay" id="ov_penMethodSheet" onclick="closePenSheet('penMethodSheet')"></div>
  <div class="bottom-sheet" id="penMethodSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div>
        <div class="sheet-title">Choose Payment Method</div>
        <div id="pen_sheet_sub" style="font-size:11.5px;color:var(--gray-400);margin-top:2px;"></div>
      </div>
      <button class="sheet-close" onclick="closePenSheet('penMethodSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <button class="payment-method-row" onclick="switchPenSheet('penMethodSheet','penGcashSheet')" type="button">
        <img class="pm-icon-img" src="assets/images/gcash.jpeg" alt="GCash"/>
        <div><div class="pm-row-title">GCash</div><div class="pm-row-desc">Pay via GCash mobile wallet</div></div>
        <span style="margin-left:auto;color:var(--gray-300);font-size:20px;">›</span>
      </button>
      <button class="payment-method-row" onclick="switchPenSheet('penMethodSheet','penBankSheet')" type="button">
        <img class="pm-icon-img" src="assets/images/bank_transfer.png" alt="Bank"/>
        <div><div class="pm-row-title">Bank Transfer</div><div class="pm-row-desc">Direct bank deposit or online transfer</div></div>
        <span style="margin-left:auto;color:var(--gray-300);font-size:20px;">›</span>
      </button>
      <button class="payment-method-row" onclick="switchPenSheet('penMethodSheet','penCashSheet')" type="button">
        <img class="pm-icon-img" src="assets/images/cash_collection.jpg" alt="Cash"/>
        <div><div class="pm-row-title">Cash Collection</div><div class="pm-row-desc">Our collector will visit you</div></div>
        <span style="margin-left:auto;color:var(--gray-300);font-size:20px;">›</span>
      </button>
    </div>
  </div>

  <!-- GCash Sheet -->
  <div class="sheet-overlay" id="ov_penGcashSheet" onclick="closePenSheet('penGcashSheet')"></div>
  <div class="bottom-sheet" id="penGcashSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div style="display:flex;align-items:center;gap:10px;">
        <img src="assets/images/gcash.jpeg" alt="GCash" style="width:28px;height:28px;border-radius:6px;object-fit:contain;"/>
        <div class="sheet-title">GCash Payment</div>
      </div>
      <button class="sheet-close" onclick="closePenSheet('penGcashSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <div style="background:var(--maroon-pale);border-radius:14px;padding:14px;text-align:center;margin-bottom:14px;">
        <div style="font-size:10.5px;color:var(--text-light);">Penalty Amount</div>
        <div class="pen_amt_display" style="font-size:24px;font-weight:800;color:var(--maroon);">—</div>
        <div class="pen_lbl_display" style="font-size:10.5px;color:var(--text-light);">—</div>
      </div>
      <div style="border:1.5px solid var(--gray-100);border-radius:14px;padding:14px;margin-bottom:14px;">
        <div style="font-size:12px;font-weight:700;margin-bottom:10px;">Send payment to:</div>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div>
            <div style="font-size:10.5px;color:var(--gray-400);">GCash Number</div>
            <div style="font-size:15px;font-weight:800;color:var(--maroon);" id="penGcashNum">09307158807</div>
            <div style="font-size:10.5px;color:var(--gray-400);margin-top:1px;">Velveth Lending Co.</div>
          </div>
          <button onclick="copyVal('penGcashNum','penGcashCopied')" class="app-btn app-btn-secondary app-btn-sm" style="width:auto;padding:8px 12px;">📋 Copy</button>
        </div>
        <div id="penGcashCopied" style="font-size:11px;color:#15803d;display:none;margin-top:5px;">✓ Copied!</div>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="pay_penalty"/>
        <input type="hidden" name="penalty_id" class="pen_id_input" value=""/>
        <input type="hidden" name="payment_method" value="gcash"/>
        <div class="app-form-group">
          <label class="app-form-label">Your GCash Number</label>
          <input type="tel" name="gcash_number" class="app-input" placeholder="09XX-XXX-XXXX"/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">GCash Reference Number *</label>
          <input type="text" name="reference_number" class="app-input" placeholder="13-digit reference number" required/>
          <div style="font-size:10.5px;color:var(--gray-400);margin-top:4px;">Found in your GCash receipt</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <button type="button" onclick="switchPenSheet('penGcashSheet','penMethodSheet')" class="app-btn app-btn-secondary">← Back</button>
          <button type="submit" class="app-btn app-btn-primary">Submit</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Bank Sheet -->
  <div class="sheet-overlay" id="ov_penBankSheet" onclick="closePenSheet('penBankSheet')"></div>
  <div class="bottom-sheet" id="penBankSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div style="display:flex;align-items:center;gap:10px;">
        <img src="assets/images/bank_transfer.png" alt="Bank" style="width:28px;height:28px;border-radius:6px;object-fit:contain;"/>
        <div class="sheet-title">Bank Transfer</div>
      </div>
      <button class="sheet-close" onclick="closePenSheet('penBankSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <div style="background:var(--maroon-pale);border-radius:14px;padding:14px;text-align:center;margin-bottom:14px;">
        <div style="font-size:10.5px;color:var(--text-light);">Penalty Amount</div>
        <div class="pen_amt_display" style="font-size:24px;font-weight:800;color:var(--maroon);">—</div>
        <div class="pen_lbl_display" style="font-size:10.5px;color:var(--text-light);">—</div>
      </div>
      <div style="border:1.5px solid var(--gray-100);border-radius:14px;padding:14px;margin-bottom:14px;">
        <div style="font-size:12px;font-weight:700;margin-bottom:10px;">Bank Account Details</div>
        <div style="margin-bottom:7px;"><div style="font-size:10.5px;color:var(--gray-400);">Bank Name</div><div style="font-size:13px;font-weight:700;">BDO Unibank</div></div>
        <div style="margin-bottom:7px;"><div style="font-size:10.5px;color:var(--gray-400);">Account Name</div><div style="font-size:13px;font-weight:700;">Velveth Lending Company</div></div>
        <div>
          <div style="font-size:10.5px;color:var(--gray-400);">Account Number</div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:3px;">
            <div style="font-size:15px;font-weight:800;color:var(--maroon);" id="penBankAcct">1234-5678-9011</div>
            <button onclick="copyVal('penBankAcct','penBankCopied')" class="app-btn app-btn-secondary app-btn-sm" style="width:auto;padding:8px 12px;">📋 Copy</button>
          </div>
          <div id="penBankCopied" style="font-size:11px;color:#15803d;display:none;margin-top:4px;">✓ Copied!</div>
        </div>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="pay_penalty"/>
        <input type="hidden" name="penalty_id" class="pen_id_input" value=""/>
        <input type="hidden" name="payment_method" value="bank_transfer"/>
        <div class="app-form-group">
          <label class="app-form-label">Bank Reference Number *</label>
          <input type="text" name="reference_number" class="app-input" placeholder="Transaction reference number" required/>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <button type="button" onclick="switchPenSheet('penBankSheet','penMethodSheet')" class="app-btn app-btn-secondary">← Back</button>
          <button type="submit" class="app-btn app-btn-primary">Submit</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Cash Sheet -->
  <div class="sheet-overlay" id="ov_penCashSheet" onclick="closePenSheet('penCashSheet')"></div>
  <div class="bottom-sheet" id="penCashSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div style="display:flex;align-items:center;gap:10px;">
        <img src="assets/images/cash_collection.jpg" alt="Cash" style="width:28px;height:28px;border-radius:6px;object-fit:contain;"/>
        <div class="sheet-title">Cash Collection</div>
      </div>
      <button class="sheet-close" onclick="closePenSheet('penCashSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <div style="background:var(--maroon-pale);border-radius:14px;padding:14px;text-align:center;margin-bottom:14px;">
        <div style="font-size:10.5px;color:var(--text-light);">Penalty Amount</div>
        <div class="pen_amt_display" style="font-size:24px;font-weight:800;color:var(--maroon);">—</div>
        <div class="pen_lbl_display" style="font-size:10.5px;color:var(--text-light);">—</div>
      </div>
      <div style="background:#fef9c3;border:1px solid #fde047;border-radius:14px;padding:14px;margin-bottom:12px;font-size:12px;color:#a16207;">
        <div style="font-weight:800;margin-bottom:6px;">💰 Cash Collection Info</div>
        <ul style="line-height:1.8;list-style:none;padding:0;margin:0;">
          <li>• Our authorized collector will visit your address</li>
          <li>• Please prepare the exact amount</li>
          <li>• Hours: 9:00 AM – 5:00 PM (Mon–Sat)</li>
        </ul>
      </div>
      <div style="background:#dcfce7;border-radius:14px;padding:10px 14px;margin-bottom:14px;font-size:11.5px;color:#15803d;">
        💡 <strong>No amount needed</strong> — pay the exact amount on collection day.
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="pay_penalty"/>
        <input type="hidden" name="penalty_id" class="pen_id_input" value=""/>
        <input type="hidden" name="payment_method" value="cash_collection"/>
        <input type="hidden" name="reference_number" value=""/>
        <div class="app-form-group">
          <label class="app-form-label">Preferred Collection Date *</label>
          <input type="date" name="collection_date" class="app-input" required min="<?= date('Y-m-d') ?>"/>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <button type="button" onclick="switchPenSheet('penCashSheet','penMethodSheet')" class="app-btn app-btn-secondary">← Back</button>
          <button type="submit" class="app-btn app-btn-primary">Schedule</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Success Sheet -->
  <?php if ($pen_success): ?>
  <div class="sheet-overlay open" id="ov_penSuccessSheet"></div>
  <div class="bottom-sheet open" id="penSuccessSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">Payment Submitted!</div>
      <button class="sheet-close" onclick="document.getElementById('ov_penSuccessSheet').classList.remove('open');document.getElementById('penSuccessSheet').classList.remove('open');">✕</button>
    </div>
    <div class="sheet-body" style="text-align:center;">
      <div style="background:#dcfce7;border-radius:16px;padding:20px;margin-bottom:14px;">
        <div style="font-size:38px;margin-bottom:8px;">✅</div>
        <div style="font-weight:800;font-size:14px;color:#15803d;margin-bottom:4px;">Payment Submitted!</div>
        <div style="font-size:11.5px;color:#166534;line-height:1.6;">Being verified. You'll be notified once confirmed.</div>
      </div>
      <?php $pm = $pen_success['method']; ?>
      <div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:14px;">
        <img src="assets/images/<?= $pm==='gcash'?'gcash.jpeg':($pm==='bank_transfer'?'bank_transfer.png':'cash_collection.jpg') ?>"
             style="width:30px;height:30px;border-radius:8px;object-fit:contain;" alt=""/>
        <div style="font-size:13px;font-weight:800;"><?= ucwords(str_replace('_',' ',$pm)) ?></div>
      </div>
      <div style="background:var(--gray-50);border-radius:12px;padding:14px;text-align:left;">
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
          <span style="font-size:12px;color:var(--gray-400);">Payment ID</span>
          <span style="font-size:12px;font-weight:700;color:var(--maroon);"><?= htmlspecialchars($pen_success['pay_id']) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;">
          <span style="font-size:12px;color:var(--gray-400);">Amount</span>
          <span style="font-size:13px;font-weight:800;color:var(--maroon);">₱<?= number_format($pen_success['amount'],2) ?></span>
        </div>
        <?php if ($pen_success['ref']): ?>
        <div style="display:flex;justify-content:space-between;margin-top:8px;">
          <span style="font-size:12px;color:var(--gray-400);">Reference</span>
          <span style="font-size:12px;font-weight:700;"><?= htmlspecialchars($pen_success['ref']) ?></span>
        </div>
        <?php endif; ?>
      </div>
      <button onclick="document.getElementById('ov_penSuccessSheet').classList.remove('open');document.getElementById('penSuccessSheet').classList.remove('open');"
              class="app-btn app-btn-success" style="margin-top:14px;">Done ✓</button>
    </div>
  </div>
  <?php endif; ?>

  <?php include 'app_tabbar.php'; ?>
  <?php include 'chatbot.php'; ?>

<script>
let _activePenId = 0, _activePenAmt = 0, _activePenLabel = '';
const PEN_SHEETS = ['penMethodSheet','penGcashSheet','penBankSheet','penCashSheet'];

function closePenSheet(id) {
  document.getElementById('ov_'+id)?.classList.remove('open');
  document.getElementById(id)?.classList.remove('open');
}
function openPenSheet(id) {
  document.getElementById('ov_'+id)?.classList.add('open');
  document.getElementById(id)?.classList.add('open');
}
function switchPenSheet(fromId, toId) { closePenSheet(fromId); openPenSheet(toId); }

function openPenPaySheet(penId, amount, label) {
  _activePenId    = penId;
  _activePenAmt   = amount;
  _activePenLabel = label;
  const fmtAmt = '₱' + parseFloat(amount).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
  document.getElementById('pen_sheet_sub').textContent = label + ' · Amount: ' + fmtAmt;
  // Update all amount displays
  document.querySelectorAll('.pen_amt_display').forEach(el => el.textContent = fmtAmt);
  document.querySelectorAll('.pen_lbl_display').forEach(el => el.textContent = label);
  // Set penalty_id on all hidden inputs
  document.querySelectorAll('.pen_id_input').forEach(el => el.value = penId);
  openPenSheet('penMethodSheet');
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
  <script src="assets/js/form-guard.js"></script>
  <script src="realtime.js"></script>
</body>
</html>
