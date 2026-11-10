<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php"); exit;
}
$_cur = 'admin_archive.php';
$msg = '';

// ── Restore / Permanent Delete ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['record_id'] ?? 0);
    $type   = $_POST['record_type'] ?? '';

    if ($action === 'restore' && $id) {
        if ($type === 'customer') {
            $r = $conn->query("SELECT full_name FROM users WHERE id=$id AND role='customer' AND deleted_at IS NOT NULL")->fetch_assoc();
            if ($r) {
                $conn->query("UPDATE users SET deleted_at=NULL WHERE id=$id");
                logAudit($conn,'Customer',$_SESSION['fullname'],'Restored customer',$r['full_name']." (ID:$id)");
                $msg = 'success:Customer '.$r['full_name'].' restored.';
            } else { $msg = 'error:Customer not found or not deleted.'; }
        }
        if ($type === 'loan') {
            $r = $conn->query("SELECT app_id FROM loan_applications WHERE id=$id AND status='archived'")->fetch_assoc();
            if ($r) {
                $conn->query("UPDATE loan_applications SET status='pending' WHERE id=$id");
                logAudit($conn,'Loan',$_SESSION['fullname'],'Restored loan from archive',$r['app_id']);
                $msg = 'success:Loan application '.$r['app_id'].' restored to Pending.';
            } else { $msg = 'error:Record not found or not archived.'; }
        }
    }

    if ($action === 'perm_delete' && $id) {
        if ($type === 'customer') {
            $r = $conn->query("SELECT full_name FROM users WHERE id=$id AND role='customer' AND deleted_at IS NOT NULL")->fetch_assoc();
            if ($r) {
                if ($conn->query("DELETE FROM users WHERE id=$id")) {
                    logAudit($conn,'Customer',$_SESSION['fullname'],'Permanently deleted customer',$r['full_name']." (ID:$id)");
                    $msg = 'success:Customer permanently deleted.';
                } else { $msg = 'error:Could not permanently delete: '.$conn->error; }
            } else { $msg = 'error:Customer not found.'; }
        }
        if ($type === 'loan') {
            $r = $conn->query("SELECT app_id FROM loan_applications WHERE id=$id AND status='archived'")->fetch_assoc();
            if ($r) {
                $conn->query("DELETE FROM loan_applications WHERE id=$id");
                logAudit($conn,'Loan',$_SESSION['fullname'],'Permanently deleted archived application',$r['app_id']);
                $msg = 'success:Application permanently deleted.';
            } else { $msg = 'error:Record not found.'; }
        }
    }

    header("Location: admin_archive.php?msg=".urlencode($msg)); exit;
}

$msg = isset($_GET['msg']) ? urldecode($_GET['msg']) : '';
[$mtype,$mtext] = $msg ? explode(':',$msg,2) : ['',''];

// Archived loans
$archived_loans = $conn->query("
    SELECT la.*, u.full_name, u.email,
           COUNT(d.id) AS doc_count
    FROM loan_applications la
    JOIN users u ON la.customer_id = u.id
    LEFT JOIN documents d ON d.application_id = la.id
    WHERE la.status = 'archived'
    GROUP BY la.id
    ORDER BY la.updated_at DESC
");

// Deleted (archived) customers -- restorable
$archived_customers = $conn->query("
    SELECT id, full_name, email, phone, deleted_at
    FROM users
    WHERE role='customer' AND deleted_at IS NOT NULL
    ORDER BY deleted_at DESC
");
$archived_cust_count = $archived_customers ? $archived_customers->num_rows : 0;

// Audit trail for reference
$audit_history = $conn->query("
    SELECT * FROM audit_trail
    WHERE action LIKE '%Archived%' OR action LIKE '%Deleted%' OR action LIKE '%Restored%'
    ORDER BY created_at DESC LIMIT 50
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Archive &amp; History | Velveth Admin</title>
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
      <div class="app-bar-title">Archive &amp; History</div>
      <div class="app-bar-sub"><?= $archived_loans->num_rows + $archived_cust_count ?> archived record<?= ($archived_loans->num_rows + $archived_cust_count)!=1?'s':'' ?></div>
    </div>
  </header>

  <main class="app-screen">

    <?php if ($mtext): ?>
    <div class="app-card" style="background:<?= $mtype==='success'?'#d1fae5':'#fee2e2' ?>;color:<?= $mtype==='success'?'#047857':'#b91c1c' ?>;font-size:12.5px;font-weight:600;display:flex;gap:8px;align-items:center;">
      <?= $mtype==='success'?'✅':'❌' ?> <?= htmlspecialchars($mtext) ?>
    </div>
    <?php endif; ?>

    <div class="app-card" style="background:var(--gray-50);">
      <p style="font-size:11.5px;color:var(--gray-400);line-height:1.5;margin-bottom:0;">📦 Archived records can be restored. Permanent deletion cannot be undone.</p>
    </div>

    <!-- Deleted Customers (restorable) -->
    <div class="app-section-title"><span>👤 Deleted Customers (<?= $archived_cust_count ?>)</span></div>
    <div class="app-card-flat">
      <?php if ($archived_cust_count === 0): ?>
        <div class="app-empty">
          <div class="app-empty-icon">👥</div>
          <div class="app-empty-title">No deleted customers</div>
          <div class="app-empty-sub">Deleted customers can be restored from here</div>
        </div>
      <?php else: while ($c = $archived_customers->fetch_assoc()): ?>
        <a href="javascript:void(0)" class="app-list-item" onclick='viewDeletedCustomer(<?= json_encode($c, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
          <div class="app-list-icon" style="background:var(--gray-100);">👤</div>
          <div class="app-list-body">
            <div class="app-list-title"><?= htmlspecialchars($c['full_name']) ?></div>
            <div class="app-list-sub"><?= htmlspecialchars($c['email']) ?> · deleted <?= date('M d, Y', strtotime($c['deleted_at'])) ?></div>
          </div>
          <div class="app-list-end"><span style="font-size:11px;font-weight:700;color:#16a34a;">Restore</span></div>
        </a>
      <?php endwhile; endif; ?>
    </div>

    <!-- Archived Loan Applications -->
    <div class="app-section-title"><span>📦 Archived Loan Applications (<?= $archived_loans->num_rows ?>)</span></div>
    <div class="app-card-flat">
      <?php if ($archived_loans->num_rows === 0): ?>
        <div class="app-empty">
          <div class="app-empty-icon">📭</div>
          <div class="app-empty-title">No archived applications</div>
          <div class="app-empty-sub">Archived records will appear here</div>
        </div>
      <?php else: while ($row = $archived_loans->fetch_assoc()): ?>
        <a href="javascript:void(0)" class="app-list-item" onclick='viewArchived(<?= json_encode($row, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
          <div class="app-list-icon" style="background:var(--gray-100);">📦</div>
          <div class="app-list-body">
            <div class="app-list-title"><?= htmlspecialchars($row['app_id']) ?></div>
            <div class="app-list-sub"><?= htmlspecialchars($row['full_name']) ?> · <?= $row['doc_count'] ?> doc<?= $row['doc_count']!=1?'s':'' ?> · <?= date('M d, Y', strtotime($row['created_at'])) ?></div>
          </div>
          <div class="app-list-end"><div class="app-list-amount">₱<?= number_format($row['loan_amount'],0) ?></div></div>
        </a>
      <?php endwhile; endif; ?>
    </div>

    <!-- Activity Log -->
    <div class="app-section-title">
      <span>📋 Archive Activity Log</span>
      <a href="admin_audit_trail.php" class="see-all">Full Audit →</a>
    </div>
    <div class="app-card-flat">
      <?php if ($audit_history->num_rows === 0): ?>
        <div class="app-empty"><div class="app-empty-icon">📋</div><div class="app-empty-title">No archive activity yet</div></div>
      <?php else: while($row=$audit_history->fetch_assoc()): ?>
        <div class="app-list-item" style="cursor:default;">
          <div class="app-list-icon" style="background:var(--maroon-pale,#fde8ec);color:var(--maroon);font-size:11px;font-weight:800;"><?= substr($row['type'],0,2) ?></div>
          <div class="app-list-body">
            <div class="app-list-title"><?= htmlspecialchars($row['action']) ?></div>
            <div class="app-list-sub" style="white-space:normal;line-height:1.4;"><?= htmlspecialchars($row['user_name']) ?> · <?= htmlspecialchars($row['details']) ?></div>
            <div style="font-size:10.5px;color:var(--gray-400);margin-top:4px;">🕐 <?= date('M d, Y H:i', strtotime($row['created_at'])) ?></div>
          </div>
        </div>
      <?php endwhile; endif; ?>
    </div>

  </main>

  <?php include 'admin_tabbar.php'; ?>

  <!-- ═══ VIEW ARCHIVED SHEET ═══ -->
  <div class="sheet-overlay" id="ov_viewSheet" onclick="closeSheet('viewSheet')"></div>
  <div class="bottom-sheet" id="viewSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title" id="va_title">Archived Application</div>
      <button class="sheet-close" onclick="closeSheet('viewSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <div id="va_content"></div>
      <div class="app-btn-row-2" style="margin-top:18px;">
        <button class="app-btn" style="background:#dc2626;color:white;border:none;" id="va_deleteBtn">🗑 Delete Forever</button>
        <button class="app-btn" style="background:#16a34a;color:white;border:none;" id="va_restoreBtn">🔄 Restore</button>
      </div>
    </div>
  </div>

  <!-- Deleted customer sheet -->
  <div class="sheet-overlay" id="ov_custSheet" onclick="closeSheet('custSheet')"></div>
  <div class="bottom-sheet" id="custSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title" id="dc_title">Deleted Customer</div>
      <button class="sheet-close" onclick="closeSheet('custSheet')">&#10005;</button>
    </div>
    <div class="sheet-body">
      <div id="dc_content"></div>
      <div class="app-btn-row-2" style="margin-top:18px;">
        <button class="app-btn" style="background:#dc2626;color:white;border:none;" id="dc_deleteBtn">🗑 Delete Forever</button>
        <button class="app-btn" style="background:#16a34a;color:white;border:none;" id="dc_restoreBtn">🔄 Restore</button>
      </div>
    </div>
  </div>
  <form method="POST" id="custRestoreForm" style="display:none;">
    <input type="hidden" name="action" value="restore"/>
    <input type="hidden" name="record_type" value="customer"/>
    <input type="hidden" name="record_id" id="cust_restore_id"/>
  </form>
  <form method="POST" id="custDeleteForm" style="display:none;">
    <input type="hidden" name="action" value="perm_delete"/>
    <input type="hidden" name="record_type" value="customer"/>
    <input type="hidden" name="record_id" id="cust_delete_id"/>
  </form>

  <form method="POST" id="restoreForm" style="display:none;">
    <input type="hidden" name="action" value="restore"/>
    <input type="hidden" name="record_type" value="loan"/>
    <input type="hidden" name="record_id" id="restore_id"/>
  </form>
  <form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="perm_delete"/>
    <input type="hidden" name="record_type" value="loan"/>
    <input type="hidden" name="record_id" id="delete_id"/>
  </form>

<script>
function openSheet(id) { document.getElementById('ov_'+id)?.classList.add('open'); document.getElementById(id)?.classList.add('open'); }
function closeSheet(id) { document.getElementById('ov_'+id)?.classList.remove('open'); document.getElementById(id)?.classList.remove('open'); }
function esc(s) { const d=document.createElement('div'); d.textContent=s||'—'; return d.innerHTML; }

function viewDeletedCustomer(data) {
  document.getElementById('dc_title').textContent = data.full_name;
  const rows = [
    ['Name',  esc(data.full_name)],
    ['Email', esc(data.email)],
    ['Phone', esc(data.phone)],
    ['Deleted On', new Date(data.deleted_at.replace(' ','T')).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})],
  ];
  document.getElementById('dc_content').innerHTML = rows.map(([l,v]) =>
    `<div class="view-row"><span class="view-label">${l}</span><span class="view-value">${v}</span></div>`).join('');
  document.getElementById('dc_restoreBtn').onclick = function(){
    if (confirm('Restore ' + data.full_name + '? They will be able to log in and appear in Customers again.')) {
      document.getElementById('cust_restore_id').value = data.id;
      document.getElementById('custRestoreForm').submit();
    }
  };
  document.getElementById('dc_deleteBtn').onclick = function(){
    if (confirm('PERMANENTLY DELETE ' + data.full_name + '?\n\nThis CANNOT be undone. Their loans, payments and documents will also be deleted.')) {
      document.getElementById('cust_delete_id').value = data.id;
      document.getElementById('custDeleteForm').submit();
    }
  };
  openSheet('custSheet');
}

function viewArchived(data) {
  document.getElementById('va_title').textContent = data.app_id;
  const rows = [
    ['Applicant',   esc(data.full_name)],
    ['Email',       esc(data.email)],
    ['Loan Amount', '₱'+parseFloat(data.loan_amount).toLocaleString('en-PH',{minimumFractionDigits:2})],
    ['Purpose',     esc(data.purpose||'—')],
    ['Documents',   (data.doc_count||0) + ' file(s)'],
    ['Archived On', new Date(data.created_at).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})],
  ];
  document.getElementById('va_content').innerHTML = rows.map(([l,v]) =>
    `<div class="view-row"><span class="view-label">${l}</span><span class="view-value">${v}</span></div>`).join('');

  document.getElementById('va_restoreBtn').onclick = function(){
    if (confirm('Restore this application to Pending?')) {
      document.getElementById('restore_id').value = data.id;
      document.getElementById('restoreForm').submit();
    }
  };
  document.getElementById('va_deleteBtn').onclick = function(){
    if (confirm('PERMANENTLY DELETE ' + data.app_id + '?\n\nThis CANNOT be undone. All documents will also be deleted.')) {
      document.getElementById('delete_id').value = data.id;
      document.getElementById('deleteForm').submit();
    }
  };
  openSheet('viewSheet');
}
</script>
  <script src="realtime.js"></script>
</body>
</html>
