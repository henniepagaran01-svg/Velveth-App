<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php"); exit;
}
$_cur = 'admin_loan_applicants.php';

// ════════════════════════════════════════════════
//  POST HANDLER
// ════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $msg    = '';
    $id     = (int)($_POST['app_id'] ?? 0);

    if ($action === 'approve' && $id) {
        $app = $conn->query("
            SELECT la.*, u.email, u.full_name
            FROM loan_applications la
            JOIN users u ON la.customer_id = u.id
            WHERE la.id=$id AND la.status='pending'
        ")->fetch_assoc();
        if ($app) {
            $admin_id = (int)$_SESSION['user_id'];
            $conn->query("UPDATE loan_applications SET status='approved', approved_by=$admin_id, approved_at=NOW() WHERE id=$id");
            $end  = date('Y-m-d', strtotime('+' . $app['loan_term'] . ' months'));
            $next = date('Y-m-d', strtotime('+1 month'));
            $conn->query("INSERT INTO loans (application_id,customer_id,loan_amount,outstanding_balance,monthly_payment,next_payment_date,start_date,end_date)
                VALUES ($id,{$app['customer_id']},{$app['loan_amount']},{$app['loan_amount']},{$app['monthly_payment']},'$next',NOW(),'$end')");
            $conn->query("UPDATE loan_applications SET status='active' WHERE id=$id");
            $uid  = (int)$app['customer_id'];
            $amt  = number_format($app['loan_amount'],2);
            $nxt  = date('F d, Y', strtotime($next));
            $conn->query("INSERT INTO notifications (user_id,title,message,type)
                VALUES ($uid,'🎉 Loan Approved!','Your loan application {$app['app_id']} for ₱$amt has been approved. First payment due $nxt.','success')");
            // In-app notification only -- no Gmail email is sent for loan approval.
            logAudit($conn,'Loan',$_SESSION['fullname'],'Approved loan',$app['app_id']);
            $msg = 'success:Loan ' . $app['app_id'] . ' approved successfully.';
        } else {
            $msg = 'error:Application not found or not in pending status.';
        }
    }

    if ($action === 'reject' && $id) {
        $remarks = trim($_POST['remarks'] ?? 'Application did not meet requirements.');
        $app = $conn->query("
            SELECT la.*, u.email, u.full_name
            FROM loan_applications la
            JOIN users u ON la.customer_id = u.id
            WHERE la.id=$id
        ")->fetch_assoc();
        if ($app) {
            $esc = mysqli_real_escape_string($conn, $remarks);
            $conn->query("UPDATE loan_applications SET status='rejected', remarks='$esc' WHERE id=$id");
            $uid = (int)$app['customer_id'];
            $conn->query("INSERT INTO notifications (user_id,title,message,type)
                VALUES ($uid,'❌ Loan Application Not Approved','Your application {$app['app_id']} was reviewed but not approved. Reason: $esc','danger')");
            // In-app notification only -- no Gmail email is sent for loan rejection.
            logAudit($conn,'Loan',$_SESSION['fullname'],'Rejected loan',$app['app_id'].' — '.$remarks);
            $msg = 'error:Application ' . $app['app_id'] . ' rejected.';
        }
    }

    if ($action === 'add') {
        $cust_id = (int)$_POST['customer_id'];
        $amount  = (float)$_POST['loan_amount'];
        $term    = (int)$_POST['loan_term'];
        $purpose = trim($_POST['purpose']);
        $rate    = 5.0;
        $monthly = round(($amount * (1 + $rate/100)) / $term, 2);
        $total   = round($monthly * $term, 2);
        $num     = (int)$conn->query("SELECT COUNT(*)+1 AS c FROM loan_applications")->fetch_assoc()['c'] ?? 0;
        $app_id  = 'APP-' . str_pad($num, 3, '0', STR_PAD_LEFT);

        $stmt = $conn->prepare("INSERT INTO loan_applications (app_id,customer_id,loan_amount,loan_term,interest_rate,purpose,monthly_payment,total_payable) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param("sididsdd", $app_id, $cust_id, $amount, $term, $rate, $purpose, $monthly, $total);
        if ($stmt->execute()) {
            $conn->query("INSERT INTO notifications (user_id,title,message,type)
                VALUES ($cust_id,'📋 Loan Application Created','Your application $app_id for ₱" . number_format($amount,2) . " has been submitted and is under review.','info')");
            $customerData = $conn->query("SELECT email, full_name FROM users WHERE id=$cust_id")->fetch_assoc();
            if ($customerData && function_exists('notifyLoanApplicationReceived')) {
                notifyLoanApplicationReceived($customerData['email'], $customerData['full_name'], $app_id, $amount);
            }
            if (function_exists('notifyAdminNewApplication')) {
                $adminUsers = $conn->query("SELECT email FROM users WHERE role='admin' AND status='active'");
                while ($admin = $adminUsers->fetch_assoc()) {
                    notifyAdminNewApplication($admin['email'], $customerData['full_name'] ?? 'Customer', $app_id, $amount);
                }
            }
            logAudit($conn,'Loan',$_SESSION['fullname'],'Created application',$app_id.' for customer #'.$cust_id);
            $msg = 'success:Application ' . $app_id . ' created.';
        } else {
            $msg = 'error:Failed to create application: ' . $stmt->error;
        }
        $stmt->close();
    }

    if ($action === 'delete' && $id) {
        $app = $conn->query("SELECT app_id FROM loan_applications WHERE id=$id AND status IN ('pending','rejected')")->fetch_assoc();
        if ($app) {
            $archive_ts = date('Y-m-d H:i:s');
                $conn->query("UPDATE loan_applications SET status='archived', remarks=CONCAT(COALESCE(remarks,''),' [Archived on $archive_ts]') WHERE id=$id");
            logAudit($conn,'Loan',$_SESSION['fullname'],'Archived application',$app['app_id']);
            $msg = 'success:Application archived (can be restored).';
        } else {
            $msg = 'error:Can only archive pending or rejected applications.';
        }
    }
    if ($action === 'restore' && $id) {
        $app = $conn->query("SELECT app_id FROM loan_applications WHERE id=$id AND status='archived'")->fetch_assoc();
        if ($app) {
            $conn->query("UPDATE loan_applications SET status='pending' WHERE id=$id");
            logAudit($conn,'Loan',$_SESSION['fullname'],'Restored archived application',$app['app_id']);
            $msg = 'success:Application ' . $app['app_id'] . ' restored to pending.';
        } else {
            $msg = 'error:Only archived applications can be restored.';
        }
    }

    header("Location: admin_loan_applicants.php?msg=" . urlencode($msg)); exit;
}

// ── GET ──
$msg    = isset($_GET['msg']) ? urldecode($_GET['msg']) : '';
[$mtype,$mtext] = $msg ? explode(':', $msg, 2) : ['',''];

$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$where = [];
if ($filter !== 'all') $where[] = "la.status='" . mysqli_real_escape_string($conn,$filter) . "'";
if ($search)           $where[] = "(u.full_name LIKE '%" . mysqli_real_escape_string($conn,$search) . "%' OR la.app_id LIKE '%" . mysqli_real_escape_string($conn,$search) . "%' OR u.email LIKE '%" . mysqli_real_escape_string($conn,$search) . "%')";
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$apps = $conn->query("
    SELECT la.*, u.full_name, u.email, u.phone,
        l.id AS loan_db_id,
        COUNT(d.id)                                           AS doc_count,
        SUM(CASE WHEN d.status='verified'  THEN 1 ELSE 0 END) AS doc_verified,
        SUM(CASE WHEN d.status='pending'   THEN 1 ELSE 0 END) AS doc_pending,
        SUM(CASE WHEN d.status='rejected'  THEN 1 ELSE 0 END) AS doc_rejected
    FROM loan_applications la
    JOIN users u ON la.customer_id = u.id
    LEFT JOIN documents d ON d.application_id = la.id
    LEFT JOIN loans l ON l.application_id = la.id
    $where_sql
    GROUP BY la.id
    ORDER BY la.created_at DESC
");

$counts = [];
foreach (['all','pending','approved','active','rejected','completed','archived'] as $s) {
    $q = $s==='all' ? "SELECT COUNT(*) c FROM loan_applications WHERE status != 'archived'"
                    : "SELECT COUNT(*) c FROM loan_applications WHERE status='$s'";
    $counts[$s] = (int)$conn->query($q)->fetch_assoc()['c'];
}

$customers_list = $conn->query("SELECT id,full_name FROM users WHERE role='customer' AND deleted_at IS NULL AND status='active' ORDER BY full_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Loan Applicants | Velveth Admin</title>
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
      <div class="app-bar-title">Loan Applicants</div>
      <div class="app-bar-sub"><?= $counts['pending'] ?> awaiting review</div>
    </div>
    <a href="admin_documents.php?tab=applications" class="app-bar-action">⚖️</a>
  </header>

  <main class="app-screen">

    <?php if ($mtext): ?>
    <div class="app-card" style="background:<?= $mtype==='success'?'#d1fae5':'#fee2e2' ?>;color:<?= $mtype==='success'?'#047857':'#b91c1c' ?>;font-size:12.5px;font-weight:600;display:flex;gap:8px;align-items:center;">
      <?= $mtype==='success'?'✅':'❌' ?> <?= htmlspecialchars($mtext) ?>
    </div>
    <?php endif; ?>

    <?php if ($counts['pending'] > 0): ?>
    <div class="app-card" style="background:#fff8e1;border:1.5px solid #f59e0b;display:flex;align-items:center;gap:12px;">
      <span style="font-size:24px;">⏳</span>
      <div style="flex:1;">
        <div style="font-weight:700;font-size:13.5px;color:#92400e;"><?= $counts['pending'] ?> Application<?= $counts['pending']>1?'s':'' ?> Awaiting Review</div>
        <a href="admin_documents.php?tab=applications" style="font-size:11.5px;color:var(--maroon);font-weight:700;">Review documents & qualify →</a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Filter tabs -->
    <div class="seg-tabs">
      <?php foreach(['all','pending','approved','active','rejected','completed'] as $f): ?>
        <a href="?filter=<?= $f ?><?= $search?'&q='.urlencode($search):'' ?>" class="seg-tab <?= $filter===$f?'active':'' ?>">
          <?= ucfirst($f) ?> (<?= $counts[$f] ?>)
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Search -->
    <form method="GET" style="display:flex;gap:8px;margin-bottom:14px;">
      <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>"/>
      <div style="position:relative;flex:1;">
        <span style="position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:14px;color:var(--gray-400);">🔍</span>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, email, App ID…" class="app-input" style="padding-left:36px;"/>
      </div>
      <?php if($search): ?><a href="?filter=<?= $filter ?>" class="app-btn app-btn-secondary" style="width:auto;padding:0 16px;">✕</a><?php endif; ?>
    </form>

    <!-- List -->
    <div class="app-card-flat">
      <?php if (!$apps || $apps->num_rows === 0): ?>
        <div class="app-empty">
          <div class="app-empty-icon">📋</div>
          <div class="app-empty-title">No applications found</div>
          <div class="app-empty-sub"><?= $filter!=='all'||$search?'Try a different filter or search.':'New applications will appear here.' ?></div>
        </div>
      <?php else: while ($row = $apps->fetch_assoc()): ?>
        <a href="javascript:void(0)" class="app-list-item" onclick='viewApp(<?= json_encode($row, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
          <div class="app-list-icon" style="background:#fde8ec;"><?= strtoupper(substr($row['full_name'],0,2)) ?></div>
          <div class="app-list-body">
            <div class="app-list-title"><?= htmlspecialchars($row['full_name']) ?></div>
            <div class="app-list-sub">
              <?= htmlspecialchars($row['app_id']) ?> · <?= $row['loan_term'] ?>mo
              <?php if ($row['doc_count']>0): ?>
                · <?php if ($row['doc_verified']==$row['doc_count']): ?><span style="color:#10b981;">✅ Verified</span>
                <?php elseif ($row['doc_rejected']>0): ?><span style="color:#ef4444;">❌ Issue</span>
                <?php else: ?><span style="color:#d97706;">⏳ Pending</span><?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="app-list-end">
            <div class="app-list-amount">₱<?= number_format($row['loan_amount'],0) ?></div>
            <span class="pill <?= $row['status'] ?>" style="margin-top:2px;"><?= ucfirst($row['status']) ?></span>
          </div>
        </a>
      <?php endwhile; endif; ?>
    </div>

  </main>

  <button class="app-fab" onclick="openSheet('addSheet')">＋</button>
  <?php include 'admin_tabbar.php'; ?>

  <!-- ═══ VIEW SHEET ═══ -->
  <div class="sheet-overlay" id="ov_viewSheet" onclick="closeSheet('viewSheet')"></div>
  <div class="bottom-sheet" id="viewSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div>
        <div class="sheet-title" id="view_title">Application Details</div>
        <div id="view_sub" style="font-size:11px;color:var(--gray-400);margin-top:2px;"></div>
      </div>
      <button class="sheet-close" onclick="closeSheet('viewSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <div id="viewContent"></div>
      <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--maroon);margin:16px 0 10px;" id="docHeader"></div>
      <div id="docLoadArea" style="min-height:50px;"></div>
      <div id="viewActions" style="margin-top:16px;"></div>
    </div>
  </div>

  <!-- ═══ REJECT SHEET ═══ -->
  <div class="sheet-overlay" id="ov_rejectSheet" onclick="closeSheet('rejectSheet')"></div>
  <div class="bottom-sheet" id="rejectSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">Reject Application</div>
      <button class="sheet-close" onclick="closeSheet('rejectSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <form method="POST">
        <input type="hidden" name="action" value="reject"/>
        <input type="hidden" name="app_id" id="reject_id"/>
        <p style="font-size:13px;color:var(--gray-400);margin-bottom:14px;">Rejecting: <strong id="reject_code" style="color:var(--maroon);"></strong></p>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Reason for Rejection *</label>
          <textarea name="remarks" class="app-textarea" rows="3" required placeholder="e.g. Insufficient documents, Credit check failed…"></textarea>
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="margin-top:16px;background:#ef4444;">Confirm Reject</button>
      </form>
    </div>
  </div>

  <!-- ═══ ADD SHEET ═══ -->
  <div class="sheet-overlay" id="ov_addSheet" onclick="closeSheet('addSheet')"></div>
  <div class="bottom-sheet" id="addSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">New Loan Application</div>
      <button class="sheet-close" onclick="closeSheet('addSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <form method="POST">
        <input type="hidden" name="action" value="add"/>
        <div class="app-form-group">
          <label class="app-form-label">Customer *</label>
          <select name="customer_id" class="app-select" required>
            <option value="">Select customer…</option>
            <?php if ($customers_list) while($c=$customers_list->fetch_assoc()): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Loan Amount (₱) *</label>
          <input type="number" name="loan_amount" class="app-input" min="1000" step="500" required/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Term (months) *</label>
          <select name="loan_term" class="app-select">
            <?php foreach([3,6,12,18,24,36,48,60] as $t): ?>
              <option value="<?= $t ?>"><?= $t ?> months</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Purpose *</label>
          <select name="purpose" class="app-select">
            <option>Business Capital</option><option>Medical Expenses</option>
            <option>Home Improvement</option><option>Education</option>
            <option>Emergency Fund</option><option>Vehicle Purchase</option><option>Other</option>
          </select>
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="margin-top:16px;">Create Application</button>
      </form>
    </div>
  </div>

  <!-- ═══ LOAN MONITORING SHEET ═══ -->
  <div class="sheet-overlay" id="ov_monitorSheet" onclick="closeSheet('monitorSheet')"></div>
  <div class="bottom-sheet" id="monitorSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div>
        <div class="sheet-title">📊 Loan Monitoring</div>
        <div id="lm_subtitle" style="font-size:11px;color:var(--gray-400);margin-top:2px;"></div>
      </div>
      <button class="sheet-close" onclick="closeSheet('monitorSheet')">✕</button>
    </div>
    <div class="sheet-body" id="lm_content">
      <div style="text-align:center;padding:32px;color:var(--gray-400);">Loading…</div>
    </div>
  </div>

  <!-- Hidden forms for quick actions -->
  <form method="POST" id="approveForm" style="display:none;">
    <input type="hidden" name="action" value="approve"/>
    <input type="hidden" name="app_id" id="approve_id"/>
  </form>

<script>
function openSheet(id) { document.getElementById('ov_'+id)?.classList.add('open'); document.getElementById(id)?.classList.add('open'); }
function closeSheet(id) { document.getElementById('ov_'+id)?.classList.remove('open'); document.getElementById(id)?.classList.remove('open'); }

function esc(s) { const d=document.createElement('div'); d.textContent=s||'—'; return d.innerHTML; }

function viewApp(data) {
  const fmt = n => '₱' + parseFloat(n||0).toLocaleString('en-PH',{minimumFractionDigits:2});
  document.getElementById('view_title').textContent = data.app_id;
  document.getElementById('view_sub').innerHTML = `<span class="pill ${data.status}">${data.status.charAt(0).toUpperCase()+data.status.slice(1)}</span>`;

  const rows = [
    ['Customer',     esc(data.full_name)],
    ['Phone',        esc(data.phone||'—')],
    ['Loan Amount',  fmt(data.loan_amount)],
    ['Monthly Pmt',  fmt(data.monthly_payment)],
    ['Term',         data.loan_term + ' months'],
    ['Interest Rate',(data.interest_rate||5) + '% p.a.'],
    ['Purpose',      esc(data.purpose||'—')],
    ['Applied On',   new Date(data.created_at).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})],
  ];
  if (data.remarks) rows.push(['Admin Remarks', `<span style="color:#dc2626">${esc(data.remarks)}</span>`]);

  document.getElementById('viewContent').innerHTML = rows.map(([l,v]) =>
    `<div class="view-row"><span class="view-label">${l}</span><span class="view-value">${v}</span></div>`).join('');

  document.getElementById('docHeader').textContent = `📎 Submitted Documents (${data.doc_count||0})`;
  document.getElementById('docLoadArea').innerHTML = '<div style="text-align:center;color:var(--gray-400);font-size:13px;padding:14px;">Loading documents…</div>';

  // Build actions
  let actionsHtml = '';
  if (data.status === 'pending') {
    actionsHtml += `<div class="app-btn-row-2">
      <button class="app-btn app-btn-secondary" style="color:#ef4444;" onclick="openReject(${data.id},'${data.app_id}')">✕ Reject</button>
      <button class="app-btn app-btn-primary" style="background:#10b981;" onclick="quickApprove(${data.id})">✓ Approve</button>
    </div>
    <a href="admin_documents.php?tab=applications" class="app-btn app-btn-secondary" style="margin-top:10px;">📄 Review Documents</a>`;
  } else if (data.loan_db_id) {
    actionsHtml += `<button class="app-btn app-btn-primary" onclick="closeSheet('viewSheet');openLoanMonitor(${data.loan_db_id},'${esc(data.full_name)}')">📊 Monitor Loan</button>`;
  }
  document.getElementById('viewActions').innerHTML = actionsHtml;

  openSheet('viewSheet');

  fetch(`api_get_docs.php?app_id=${data.id}`)
    .then(r => r.json())
    .then(docs => {
      const area = document.getElementById('docLoadArea');
      if (!area) return;
      if (!docs || docs.length === 0) {
        area.innerHTML = '<div style="text-align:center;padding:16px;color:var(--gray-400);font-size:13px;">📭 No documents submitted yet.</div>';
        return;
      }
      const selfie   = docs.find(d => d.is_selfie);
      const selfieId = docs.find(d => d.is_selfie_with_id);
      const govId    = docs.find(d => d.is_id);
      let html = '';
      if (selfie || selfieId || govId) {
        html += '<div class="doc-thumb-grid" style="margin-bottom:12px;grid-template-columns:1fr 1fr 1fr;">';
        [selfie, selfieId, govId].forEach((d, idx) => {
          if (!d) { html += '<div></div>'; return; }
          const label = idx===0 ? '📸 Selfie' : (idx===1 ? '🤳🪪 Selfie+ID' : '🪪 Gov ID');
          const sColor = {verified:'#dcfce7',pending:'#fef9c3',rejected:'#fee2e2'}[d.status]||'#f0f0f0';
          const sText  = {verified:'#15803d',pending:'#a16207',rejected:'#b91c1c'}[d.status]||'#555';
          html += `<div class="doc-thumb">
            <div class="doc-thumb-head" style="background:${sColor};">
              <span style="font-size:11px;font-weight:800;color:var(--text-dark);">${label}</span>
              <span style="font-size:9.5px;font-weight:800;color:${sText};background:white;padding:1px 7px;border-radius:100px;">${d.status}</span>
            </div>`;
          if (d.is_image) {
            html += `<div class="doc-thumb-img-wrap"><img src="${d.file_path}" alt="${d.doc_type}" onerror="this.style.display='none'"/></div>`;
          } else {
            html += `<div style="padding:14px;text-align:center;font-size:11px;color:var(--gray-400);">📄 File</div>`;
          }
          html += `<div class="doc-thumb-actions"><a href="${d.file_path}" target="_blank" class="app-btn app-btn-secondary app-btn-sm" style="flex:1;">View Full</a></div></div>`;
        });
        html += '</div>';
      }
      const others = docs.filter(d => !d.is_selfie && !d.is_selfie_with_id && !d.is_id);
      if (others.length > 0) {
        html += '<div class="app-card-flat" style="margin-top:8px;">';
        others.forEach(d => {
          const sColor = {verified:'#dcfce7',pending:'#fef9c3',rejected:'#fee2e2'}[d.status]||'#f0f0f0';
          const sText  = {verified:'#15803d',pending:'#a16207',rejected:'#b91c1c'}[d.status]||'#555';
          html += `<div class="app-list-item" style="cursor:default;">
            <div class="app-list-icon" style="background:${sColor};">${d.is_image?'🖼️':'📄'}</div>
            <div class="app-list-body">
              <div class="app-list-title">${esc(d.doc_type)}</div>
              <div class="app-list-sub">${new Date(d.created_at).toLocaleDateString()}</div>
            </div>
            <div class="app-list-end">
              <span style="font-size:9.5px;font-weight:800;color:${sText};background:${sColor};padding:2px 8px;border-radius:100px;">${d.status}</span><br>
              <a href="${d.file_path}" target="_blank" style="font-size:11px;color:var(--maroon);font-weight:700;">View →</a>
            </div>
          </div>`;
        });
        html += '</div>';
      }
      area.innerHTML = html || '<div style="text-align:center;color:var(--gray-400);padding:16px;">No viewable documents.</div>';
    })
    .catch(() => {
      const area = document.getElementById('docLoadArea');
      if (area) area.innerHTML = '<div style="text-align:center;color:var(--gray-400);padding:16px;">⚠️ Could not load documents.</div>';
    });
}

function openReject(id, code) {
  document.getElementById('reject_id').value = id;
  document.getElementById('reject_code').textContent = code;
  closeSheet('viewSheet');
  openSheet('rejectSheet');
}

function quickApprove(id) {
  if (confirm('Approve this application?')) {
    document.getElementById('approve_id').value = id;
    document.getElementById('approveForm').submit();
  }
}

function openLoanMonitor(loanId, customerName) {
  document.getElementById('lm_subtitle').textContent = customerName || '';
  document.getElementById('lm_content').innerHTML = '<div style="text-align:center;padding:32px;color:var(--gray-400);">⏳ Loading loan data…</div>';
  openSheet('monitorSheet');

  fetch('api_loan_monitor.php?loan_id=' + loanId)
    .then(r => r.json())
    .then(data => {
      if (data.error) { document.getElementById('lm_content').innerHTML = '<div style="text-align:center;padding:32px;color:#ef4444;">Error: '+data.error+'</div>'; return; }
      const l = data.loan;
      const fmt = n => '₱' + parseFloat(n||0).toLocaleString('en-PH',{minimumFractionDigits:2});
      const pct = data.progress_pct;
      const balColor = data.is_overdue ? '#ef4444' : '#10b981';

      let html = `<div style="background:${data.is_overdue?'#fee2e2':'#dcfce7'};border-radius:14px;padding:16px;margin-bottom:14px;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:${balColor};margin-bottom:4px;">Loan Status</div>
        <div style="font-size:18px;font-weight:800;color:${balColor};">${data.is_overdue ? '⚠️ OVERDUE' : '✅ ' + (l.status||'active').toUpperCase()}</div>
        ${data.is_overdue ? `<div style="font-size:11px;color:#ef4444;margin-top:2px;">${data.days_overdue} day(s) past due</div>` : ''}
        <div style="margin-top:10px;font-size:11px;color:${balColor};">Remaining Balance</div>
        <div style="font-size:20px;font-weight:800;color:${balColor};">${fmt(l.outstanding_balance)}</div>
      </div>`;

      html += `<div style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;font-size:11.5px;margin-bottom:6px;">
          <span style="font-weight:700;color:var(--gray-400);">Repayment Progress</span><span style="font-weight:800;color:var(--maroon);">${pct}%</span>
        </div>
        <div style="height:8px;background:var(--gray-100);border-radius:100px;overflow:hidden;"><div style="height:100%;width:${pct}%;background:var(--maroon);border-radius:100px;"></div></div>
      </div>`;

      const kf = [['Loan Amount',fmt(l.loan_amount)],['Monthly Payment',fmt(l.monthly_payment)],['Total Paid',fmt(l.amount_paid)],['Balance Left',fmt(l.outstanding_balance)],['Term',(l.loan_term||'—')+' mo'],['Next Due',l.next_payment_date||'—']];
      html += '<div class="view-row-wrap" style="margin-bottom:14px;">';
      kf.forEach(([lbl,val]) => { html += `<div class="view-row"><span class="view-label">${lbl}</span><span class="view-value">${val}</span></div>`; });
      html += '</div>';

      if (data.penalties && data.penalties.length > 0) {
        html += `<div style="font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#ef4444;margin-bottom:8px;">⚠️ Penalties (${data.penalties.length})</div>`;
        data.penalties.forEach(p => {
          const pColor = p.status==='paid'?'#dcfce7':(p.status==='waived'?'#dbeafe':'#fee2e2');
          const pText  = p.status==='paid'?'#15803d':(p.status==='waived'?'#1d4ed8':'#b91c1c');
          html += `<div style="display:flex;justify-content:space-between;padding:9px 12px;background:${pColor};border-radius:10px;margin-bottom:6px;">
            <div style="font-size:12px;font-weight:700;color:${pText};">${esc(p.reason||'—')}</div>
            <div style="font-size:12.5px;font-weight:800;color:${pText};">₱${parseFloat(p.penalty_amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</div>
          </div>`;
        });
      } else {
        html += '<div style="background:#dcfce7;border-radius:10px;padding:10px 14px;font-size:12px;color:#15803d;font-weight:700;margin-bottom:14px;">✅ No penalties on this loan</div>';
      }

      if (data.is_overdue && data.auto_penalty > 0) {
        html += `<div style="background:#fff3cd;border:1px solid #fbbf24;border-radius:12px;padding:14px;margin-top:10px;">
          <div style="font-size:12px;font-weight:800;color:#a16207;margin-bottom:6px;">⚠️ Auto-Penalty Eligible</div>
          <div style="font-size:11.5px;color:#a16207;line-height:1.6;">Overdue ${data.days_overdue} day(s) · Computed: ₱${parseFloat(data.auto_penalty).toLocaleString('en-PH',{minimumFractionDigits:2})}</div>
          <a href="admin_penalties.php" class="app-btn app-btn-secondary app-btn-sm" style="margin-top:10px;">Issue Penalty</a>
        </div>`;
      }

      html += `<div style="background:var(--maroon);border-radius:12px;padding:14px 16px;margin-top:14px;display:flex;justify-content:space-between;align-items:center;">
        <div style="font-size:11px;color:rgba(255,255,255,.7);">TOTAL DUE</div>
        <div style="font-size:17px;font-weight:800;color:white;">${fmt(data.total_due)}</div>
      </div>`;

      document.getElementById('lm_content').innerHTML = html;
    })
    .catch(err => { document.getElementById('lm_content').innerHTML = '<div style="text-align:center;padding:32px;color:#ef4444;">Failed to load data.</div>'; });
}
</script>
  <script src="assets/js/form-guard.js"></script>
  <script src="realtime.js"></script>
</body>
</html>
