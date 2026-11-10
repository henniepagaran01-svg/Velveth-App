<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php"); exit;
}
$_cur = 'admin_penalties.php';

// Auto-generate penalties for any newly-overdue loans before showing the list.
autoGeneratePenalties($conn);

// ── POST Actions (must be before HTML output) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $msg    = '';

    // ADD penalty
    if ($action === 'add') {
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $loan_id     = (int)($_POST['loan_id']     ?? 0);
        $amount      = (float)($_POST['penalty_amount'] ?? 0);
        $reason      = trim($_POST['reason']   ?? '');
        $due_date    = trim($_POST['due_date']  ?? '');

        if (!$customer_id || !$loan_id || $amount <= 0 || !$reason) {
            $msg = 'error:Customer, loan, amount and reason are required.';
        } else {
            $pen_count = (int)$conn->query("SELECT COUNT(*)+1 c FROM penalties")->fetch_assoc()['c'];
            $pen_id    = 'PEN-' . str_pad($pen_count, 3, '0', STR_PAD_LEFT);

            $due = $due_date ?: '';
            $stmt = $conn->prepare(
                "INSERT INTO penalties (penalty_id,loan_id,customer_id,penalty_amount,reason,due_date,status)
                 VALUES (?,?,?,?,?,?,'pending')"
            );
            if (!$stmt) { $msg = 'error:Prepare failed: ' . $conn->error; header("Location: admin_penalties.php?msg=".urlencode($msg)); exit; }
            $stmt->bind_param("siidss", $pen_id, $loan_id, $customer_id, $amount, $reason, $due);
            if ($stmt->execute()) {
                $cust = $conn->query("SELECT email,full_name FROM users WHERE id=$customer_id")->fetch_assoc();
                $loan = $conn->query("SELECT la.app_id FROM loans l JOIN loan_applications la ON l.application_id=la.id WHERE l.id=$loan_id LIMIT 1")->fetch_assoc();

                sendNotification($conn, $customer_id, '⚠️ Penalty Issued — '.$pen_id,
                    "A penalty of ₱".number_format($amount,2)." has been issued on your loan account. Reason: $reason. Due: ".($due_date?date('F d, Y',strtotime($due_date)):'ASAP').".",
                    'warning');

                if ($cust && function_exists('notifyPenaltyIssued')) {
                    @notifyPenaltyIssued(
                        $cust['email'], $cust['full_name'],
                        $pen_id, $amount, $reason,
                        $due_date ? date('F d, Y', strtotime($due_date)) : 'As soon as possible',
                        $loan['app_id'] ?? ''
                    );
                }

                logAudit($conn, 'Penalty', $_SESSION['fullname'],
                    "Issued penalty $pen_id",
                    "Customer ID $customer_id | ₱".number_format($amount,2)." | $reason");
                $msg = 'success:Penalty '.$pen_id.' issued successfully. Customer notified.';
            } else { $msg = 'error:Failed to add penalty: '.$conn->error; }
            $stmt->close();
        }
    }

    // UPDATE status
    if ($action === 'update_status') {
        $pen_db_id = (int)($_POST['penalty_db_id'] ?? 0);
        $status    = in_array($_POST['status']??'', ['pending','paid','waived','unpaid']) ? $_POST['status'] : 'pending';

        $pen = $conn->query("SELECT pen.*,u.email,u.full_name FROM penalties pen JOIN users u ON pen.customer_id=u.id WHERE pen.id=$pen_db_id")->fetch_assoc();
        if ($pen) {
            $conn->query("UPDATE penalties SET status='$status' WHERE id=$pen_db_id");

            sendNotification($conn, (int)$pen['customer_id'],
                '🔔 Penalty '.ucfirst($status).' — '.htmlspecialchars($pen['penalty_id']??'#'.$pen['id']),
                "Your penalty of ₱".number_format($pen['penalty_amount'],2)." has been marked as ".ucfirst($status).".",
                $status==='paid'?'success':($status==='waived'?'info':'warning'));

            if (in_array($status,['paid','waived']) && function_exists('notifyPenaltyUpdated')) {
                @notifyPenaltyUpdated($pen['email'], $pen['full_name'],
                    $pen['penalty_id']??'#'.$pen['id'], $status, $pen['penalty_amount']);
            }

            logAudit($conn,'Penalty',$_SESSION['fullname'],
                'Updated penalty status',
                ($pen['penalty_id']??'ID:'.$pen_db_id)." → $status");
            $msg = 'success:Penalty status updated to '.ucfirst($status).'.';
        } else { $msg = 'error:Penalty not found.'; }
    }

    // EDIT penalty
    if ($action === 'edit') {
        $pen_db_id = (int)($_POST['penalty_db_id'] ?? 0);
        $amount    = (float)($_POST['penalty_amount'] ?? 0);
        $reason    = trim($_POST['reason'] ?? '');
        $due_date  = trim($_POST['due_date'] ?? '');
        $status    = in_array($_POST['status']??'',['pending','paid','waived','unpaid']) ? $_POST['status'] : 'pending';

        if ($amount <= 0 || !$reason) {
            $msg = 'error:Amount and reason are required.';
        } else {
            $due = $due_date ?: '';
            $stmt = $conn->prepare("UPDATE penalties SET penalty_amount=?,reason=?,due_date=?,status=? WHERE id=?");
            if (!$stmt) { $msg = 'error:Prepare failed: ' . $conn->error; header("Location: admin_penalties.php?msg=".urlencode($msg)); exit; }
            $stmt->bind_param("dsssi", $amount, $reason, $due, $status, $pen_db_id);
            if ($stmt->execute()) {
                $pen = $conn->query("SELECT u.email,u.full_name,pen.penalty_id,pen.customer_id FROM penalties pen JOIN users u ON pen.customer_id=u.id WHERE pen.id=$pen_db_id")->fetch_assoc();
                if ($pen && function_exists('notifyAccountUpdate')) {
                    @notifyAccountUpdate($pen['email'], $pen['full_name'],
                        'Penalty Updated — '.($pen['penalty_id']??'#'.$pen_db_id),
                        "Amount: ₱".number_format($amount,2)." | Status: ".ucfirst($status)." | Reason: $reason");
                }
                logAudit($conn,'Penalty',$_SESSION['fullname'],'Edited penalty','ID:'.$pen_db_id." | ₱".number_format($amount,2)." | $status");
                $msg = 'success:Penalty updated successfully.';
            } else { $msg = 'error:Update failed.'; }
            $stmt->close();
        }
    }

    // DELETE
    if ($action === 'delete') {
        $pen_db_id = (int)($_POST['penalty_db_id'] ?? 0);
        $pen = $conn->query("SELECT pen.*,u.full_name FROM penalties pen JOIN users u ON pen.customer_id=u.id WHERE pen.id=$pen_db_id")->fetch_assoc();
        if ($pen) {
            $conn->query("DELETE FROM penalties WHERE id=$pen_db_id");
            logAudit($conn,'Penalty',$_SESSION['fullname'],'Deleted penalty',
                ($pen['penalty_id']??'#'.$pen_db_id)." | {$pen['full_name']}");
            $msg = 'success:Penalty deleted.';
        } else { $msg = 'error:Penalty not found.'; }
    }

    header("Location: admin_penalties.php?msg=".urlencode($msg)); exit;
}

// ── GET data ─────────────────────────────────────────────────────────────────
$msg = isset($_GET['msg']) ? urldecode($_GET['msg']) : '';
[$mtype,$mtext] = $msg ? explode(':',$msg,2) : ['',''];

$filter_status = $_GET['status'] ?? 'all';
$filter_period = $_GET['period'] ?? 'all'; // all | day | week | month | year
$search        = trim($_GET['q'] ?? '');

$sql = "
    SELECT pen.*, u.full_name, u.email,
           COALESCE(la.app_id,'—') AS app_id,
           l.id AS loan_db_id,
           COALESCE(l.loan_amount,0) AS loan_amount
    FROM penalties pen
    LEFT JOIN users u   ON pen.customer_id = u.id
    LEFT JOIN loans l   ON pen.loan_id     = l.id
    LEFT JOIN loan_applications la ON l.application_id = la.id
    WHERE 1";
if ($filter_status !== 'all') {
    $fs = mysqli_real_escape_string($conn, $filter_status);
    $sql .= " AND pen.status='$fs'";
}
if ($filter_period !== 'all') {
    $period_map = ['day' => '1 DAY', 'week' => '1 WEEK', 'month' => '1 MONTH', 'year' => '1 YEAR'];
    if (isset($period_map[$filter_period])) {
        $sql .= " AND pen.created_at >= DATE_SUB(NOW(), INTERVAL {$period_map[$filter_period]})";
    }
}
if ($search) {
    $s = mysqli_real_escape_string($conn, $search);
    $sql .= " AND (u.full_name LIKE '%$s%' OR pen.reason LIKE '%$s%' OR pen.penalty_id LIKE '%$s%')";
}
$sql .= " ORDER BY pen.created_at DESC";
$penalties = $conn->query($sql);

// Stats
$total_pending = (int)($conn->query("SELECT COUNT(*) c FROM penalties WHERE status='pending' OR status='unpaid'")->fetch_assoc()['c'] ?? 0);
$total_paid    = (int)($conn->query("SELECT COUNT(*) c FROM penalties WHERE status='paid'")->fetch_assoc()['c'] ?? 0);
$total_waived  = (int)($conn->query("SELECT COUNT(*) c FROM penalties WHERE status='waived'")->fetch_assoc()['c'] ?? 0);
$sum_pending   = (float)($conn->query("SELECT SUM(penalty_amount) s FROM penalties WHERE status='pending' OR status='unpaid'")->fetch_assoc()['s'] ?? 0);

$customers_with_loans = $conn->query("
    SELECT id, full_name FROM users
    WHERE role='customer' AND deleted_at IS NULL AND status='active'
    ORDER BY full_name ASC
");

// Pending Penalty Payments (customer submitted, awaiting confirmation)
$pending_pen_payments = $conn->query("
    SELECT p.*, u.full_name, u.email
    FROM payments p
    JOIN users u ON p.customer_id = u.id
    WHERE p.status = 'pending'
      AND p.notes LIKE '%Penalty Payment%'
    ORDER BY p.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Penalties | Velveth Admin</title>
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
      <div class="app-bar-title">Penalties</div>
      <div class="app-bar-sub"><?= $total_pending ?> pending · ₱<?= number_format($sum_pending,2) ?> unpaid</div>
    </div>
  </header>

  <main class="app-screen">

    <?php if ($mtext): ?>
    <div class="app-card" style="background:<?= $mtype==='success'?'#d1fae5':'#fee2e2' ?>;color:<?= $mtype==='success'?'#047857':'#b91c1c' ?>;font-size:12.5px;font-weight:600;display:flex;gap:8px;align-items:center;">
      <?= $mtype==='success'?'✅':'❌' ?> <?= htmlspecialchars($mtext) ?>
    </div>
    <?php endif; ?>

    <!-- Pending Penalty Payments -->
    <?php if ($pending_pen_payments && $pending_pen_payments->num_rows > 0): ?>
    <div class="app-section-title">
      <span>⚠️ Penalty Payments to Confirm</span>
    </div>
    <div class="app-card-flat" style="border:1.5px solid #fde047;margin-bottom:18px;">
      <?php while ($pp = $pending_pen_payments->fetch_assoc()): ?>
      <div class="app-list-item" style="cursor:default;flex-wrap:wrap;">
        <div class="app-list-icon" style="background:#fef9c3;">💰</div>
        <div class="app-list-body">
          <div class="app-list-title"><?= htmlspecialchars($pp['full_name']) ?></div>
          <div class="app-list-sub"><?= htmlspecialchars($pp['payment_id']) ?> · ₱<?= number_format($pp['amount'],2) ?> · <?= ucwords(str_replace('_',' ',$pp['payment_method'])) ?></div>
        </div>
        <div style="display:flex;gap:6px;width:100%;margin-top:8px;">
          <form method="POST" action="admin_payments.php" style="flex:1;">
            <input type="hidden" name="action" value="confirm_payment"/>
            <input type="hidden" name="payment_id" value="<?= $pp['id'] ?>"/>
            <button type="submit" class="app-btn app-btn-primary app-btn-sm" style="background:#10b981;width:100%;" onclick="return confirm('Confirm this penalty payment?')">✅ Confirm</button>
          </form>
          <form method="POST" action="admin_payments.php" style="flex:1;">
            <input type="hidden" name="action" value="reject_payment"/>
            <input type="hidden" name="payment_id" value="<?= $pp['id'] ?>"/>
            <input type="hidden" name="reject_reason" value="Penalty payment rejected"/>
            <button type="submit" class="app-btn app-btn-secondary app-btn-sm" style="color:#ef4444;width:100%;" onclick="return confirm('Reject this payment?')">✗ Reject</button>
          </form>
        </div>
      </div>
      <?php endwhile; ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stat-chip-row">
      <div class="stat-chip"><div class="stat-chip-val" style="color:#ef4444;"><?= $total_pending ?></div><div class="stat-chip-label">Pending</div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:#ef4444;">₱<?= number_format($sum_pending/1000,1) ?>K</div><div class="stat-chip-label">Unpaid Amt</div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:#10b981;"><?= $total_paid ?></div><div class="stat-chip-label">Paid</div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:#3b82f6;"><?= $total_waived ?></div><div class="stat-chip-label">Waived</div></div>
    </div>

    <!-- Filters -->
    <div class="seg-tabs">
      <a href="?status=all&period=<?= $filter_period ?><?= $search?'&q='.urlencode($search):'' ?>"     class="seg-tab <?= $filter_status==='all'?'active':'' ?>">All</a>
      <a href="?status=pending&period=<?= $filter_period ?><?= $search?'&q='.urlencode($search):'' ?>" class="seg-tab <?= $filter_status==='pending'?'active':'' ?>">Pending (<?= $total_pending ?>)</a>
      <a href="?status=paid&period=<?= $filter_period ?><?= $search?'&q='.urlencode($search):'' ?>"    class="seg-tab <?= $filter_status==='paid'?'active':'' ?>">Paid (<?= $total_paid ?>)</a>
      <a href="?status=waived&period=<?= $filter_period ?><?= $search?'&q='.urlencode($search):'' ?>"  class="seg-tab <?= $filter_status==='waived'?'active':'' ?>">Waived (<?= $total_waived ?>)</a>
    </div>

    <div class="seg-tabs" style="margin-top:8px;">
      <a href="?status=<?= $filter_status ?>&period=all<?= $search?'&q='.urlencode($search):'' ?>"    class="seg-tab <?= $filter_period==='all'?'active':'' ?>">Any Time</a>
      <a href="?status=<?= $filter_status ?>&period=day<?= $search?'&q='.urlencode($search):'' ?>"    class="seg-tab <?= $filter_period==='day'?'active':'' ?>">Today</a>
      <a href="?status=<?= $filter_status ?>&period=week<?= $search?'&q='.urlencode($search):'' ?>"   class="seg-tab <?= $filter_period==='week'?'active':'' ?>">This Week</a>
      <a href="?status=<?= $filter_status ?>&period=month<?= $search?'&q='.urlencode($search):'' ?>"  class="seg-tab <?= $filter_period==='month'?'active':'' ?>">This Month</a>
      <a href="?status=<?= $filter_status ?>&period=year<?= $search?'&q='.urlencode($search):'' ?>"   class="seg-tab <?= $filter_period==='year'?'active':'' ?>">This Year</a>
    </div>

    <form method="GET" style="display:flex;gap:8px;margin-bottom:14px;margin-top:10px;">
      <?php if($filter_status!=='all'): ?><input type="hidden" name="status" value="<?= $filter_status ?>"/><?php endif; ?>
      <?php if($filter_period!=='all'): ?><input type="hidden" name="period" value="<?= $filter_period ?>"/><?php endif; ?>
      <div style="position:relative;flex:1;">
        <span style="position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:14px;color:var(--gray-400);">🔍</span>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search customer, reason…" class="app-input" style="padding-left:36px;"/>
      </div>
      <?php if($search): ?><a href="?status=<?= $filter_status ?>&period=<?= $filter_period ?>" class="app-btn app-btn-secondary" style="width:auto;padding:0 16px;">✕</a><?php endif; ?>
    </form>

    <div class="app-card-flat" style="background:#eff6ff;padding:11px 13px;font-size:11.5px;color:#1e40af;line-height:1.6;margin-bottom:14px;">
      ℹ️ Penalties are added automatically for late payments, following Velveth's penalty policy. No manual entry needed.
    </div>

    <!-- List -->
    <div class="app-card-flat">
      <?php if ($penalties->num_rows === 0): ?>
        <div class="app-empty">
          <div class="app-empty-icon">✅</div>
          <div class="app-empty-title"><?= $search ? 'No penalties match your search' : 'No penalties recorded yet' ?></div>
        </div>
      <?php else: while ($row = $penalties->fetch_assoc()):
        $overdue = $row['due_date'] && strtotime($row['due_date']) < time() && $row['status']==='pending';
      ?>
        <a href="javascript:void(0)" class="app-list-item" onclick='viewPenalty(<?= json_encode($row, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
          <div class="app-list-icon" style="background:<?= $overdue?'#fee2e2':'#fde8ec' ?>;"><?= $overdue?'⚠️':strtoupper(substr($row['full_name']??'?',0,2)) ?></div>
          <div class="app-list-body">
            <div class="app-list-title"><?= htmlspecialchars($row['full_name']??'—') ?></div>
            <div class="app-list-sub">
              <?= htmlspecialchars($row['penalty_id'] ?? '#'.$row['id']) ?> · <?= htmlspecialchars(substr($row['reason']??'—',0,28)) ?>
              <?php if($overdue): ?> · <span style="color:#ef4444;font-weight:700;">OVERDUE</span><?php endif; ?>
            </div>
          </div>
          <div class="app-list-end">
            <div class="app-list-amount" style="color:#ef4444;">₱<?= number_format($row['penalty_amount'],2) ?></div>
            <span class="pill <?= $row['status'] ?>" style="margin-top:2px;"><?= ucfirst($row['status']) ?></span>
          </div>
        </a>
      <?php endwhile; endif; ?>
    </div>

  </main>

  <?php include 'admin_tabbar.php'; ?>

  <!-- ═══ VIEW SHEET ═══ -->
  <div class="sheet-overlay" id="ov_viewSheet" onclick="closeSheet('viewSheet')"></div>
  <div class="bottom-sheet" id="viewSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div>
        <div class="sheet-title" id="vw_title"></div>
        <div id="vw_sub" style="font-size:11px;color:var(--gray-400);margin-top:2px;"></div>
      </div>
      <button class="sheet-close" onclick="closeSheet('viewSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <div id="vw_content"></div>
      <div id="vw_actions" style="margin-top:16px;"></div>
    </div>
  </div>

  <!-- ═══ EDIT SHEET ═══ -->
  <div class="sheet-overlay" id="ov_editSheet" onclick="closeSheet('editSheet')"></div>
  <div class="bottom-sheet" id="editSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">✏️ Edit Penalty</div>
      <button class="sheet-close" onclick="closeSheet('editSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <form method="POST">
        <input type="hidden" name="action" value="edit"/>
        <input type="hidden" name="penalty_db_id" id="edit_pen_id"/>
        <div style="background:var(--maroon-pale,#fde8ec);border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px;">
          <strong id="edit_pen_label" style="color:var(--maroon);"></strong>
          <div id="edit_cust_name" style="color:var(--gray-400);font-size:12px;margin-top:2px;"></div>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Penalty Amount (₱) *</label>
          <input type="number" name="penalty_amount" id="edit_amount" class="app-input" min="1" step="0.01" required/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Due Date</label>
          <input type="date" name="due_date" id="edit_due" class="app-input"/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Reason *</label>
          <input type="text" name="reason" id="edit_reason" class="app-input" required/>
        </div>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Status</label>
          <select name="status" id="edit_status" class="app-select">
            <option value="pending">Pending</option>
            <option value="unpaid">Unpaid</option>
            <option value="paid">Paid</option>
            <option value="waived">Waived</option>
          </select>
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="margin-top:16px;">Save Changes</button>
      </form>
    </div>
  </div>

  <!-- Hidden forms for quick actions -->
  <form method="POST" id="statusForm" style="display:none;">
    <input type="hidden" name="action" value="update_status"/>
    <input type="hidden" name="penalty_db_id" id="status_pen_id"/>
    <input type="hidden" name="status" id="status_value"/>
  </form>
  <form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete"/>
    <input type="hidden" name="penalty_db_id" id="delete_pen_id"/>
  </form>

<script>
function openSheet(id) { document.getElementById('ov_'+id)?.classList.add('open'); document.getElementById(id)?.classList.add('open'); }
function closeSheet(id) { document.getElementById('ov_'+id)?.classList.remove('open'); document.getElementById(id)?.classList.remove('open'); }
function esc(s) { const d=document.createElement('div'); d.textContent=s||'—'; return d.innerHTML; }

function viewPenalty(data) {
  document.getElementById('vw_title').textContent = data.penalty_id || ('#'+data.id);
  document.getElementById('vw_sub').innerHTML = `<span class="pill ${data.status}">${data.status.charAt(0).toUpperCase()+data.status.slice(1)}</span>`;

  const overdue = data.due_date && new Date(data.due_date) < new Date() && data.status==='pending';
  const rows = [
    ['Customer',  esc(data.full_name)],
    ['Loan',      esc(data.app_id)],
    ['Amount',    '<span style="color:#ef4444;font-weight:800;">₱'+parseFloat(data.penalty_amount).toLocaleString('en-PH',{minimumFractionDigits:2})+'</span>'],
    ['Reason',    esc(data.reason)],
    ['Due Date',  data.due_date ? new Date(data.due_date).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'}) + (overdue?' <span style="color:#ef4444;font-weight:800;">(OVERDUE)</span>':'') : '—'],
    ['Issued On', new Date(data.created_at).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})],
  ];
  document.getElementById('vw_content').innerHTML = rows.map(([l,v]) =>
    `<div class="view-row"><span class="view-label">${l}</span><span class="view-value">${v}</span></div>`).join('');

  // Store data on a hidden element to avoid onclick quoting issues
  const editBtn = document.createElement('button');
  editBtn.className = 'app-btn app-btn-secondary';
  editBtn.textContent = '✏️ Edit Penalty';
  editBtn.onclick = function(){ closeSheet('viewSheet'); editPenalty(data); };
  let actions = '';
  document.getElementById('vw_actions').appendChild(editBtn);
  if (data.status === 'pending' || data.status === 'unpaid') {
    actions += `<div class="app-btn-row-2" style="margin-top:10px;">
      <button class="app-btn app-btn-secondary" style="color:#3b82f6;" onclick="setPenaltyStatus(${data.id},'waived')">🔵 Waive</button>
      <button class="app-btn app-btn-primary" style="background:#10b981;" onclick="setPenaltyStatus(${data.id},'paid')">✅ Mark Paid</button>
    </div>`;
  }
  actions += `<button class="app-btn app-btn-outline" style="margin-top:10px;color:#ef4444;border-color:#ef4444;" onclick="deletePenalty(${data.id},'${esc(data.penalty_id||('#'+data.id))}')">🗑 Delete Penalty</button>`;
  // actions string is for subsequent buttons (waive/paid/delete)
  document.getElementById('vw_actions').innerHTML = actions;
  document.getElementById('vw_actions').insertBefore(editBtn, document.getElementById('vw_actions').firstChild);

  openSheet('viewSheet');
}

function setPenaltyStatus(id, status) {
  const verb = status === 'paid' ? 'Mark this penalty as Paid' : 'Waive this penalty';
  if (confirm(verb + '?')) {
    document.getElementById('status_pen_id').value = id;
    document.getElementById('status_value').value = status;
    document.getElementById('statusForm').submit();
  }
}

function deletePenalty(id, label) {
  if (confirm('Delete penalty ' + label + '? This cannot be undone.')) {
    document.getElementById('delete_pen_id').value = id;
    document.getElementById('deleteForm').submit();
  }
}

function editPenalty(data) {
  document.getElementById('edit_pen_id').value    = data.id;
  document.getElementById('edit_pen_label').textContent  = data.penalty_id || '#' + data.id;
  document.getElementById('edit_cust_name').textContent  = data.full_name || '';
  document.getElementById('edit_amount').value    = data.penalty_amount;
  document.getElementById('edit_due').value       = data.due_date || '';
  document.getElementById('edit_reason').value    = data.reason || '';
  document.getElementById('edit_status').value    = data.status || 'pending';
  openSheet('editSheet');
}

// Load loans for selected customer via AJAX

</script>
  <script src="assets/js/form-guard.js"></script>
  <script src="realtime.js"></script>
</body>
</html>
