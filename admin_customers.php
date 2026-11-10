<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php"); exit;
}
$_cur = 'admin_customers.php';
$msg = '';

// ── POST Actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ADD
    if ($action === 'add') {
        $name  = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $addr  = trim($_POST['address'] ?? '');
        $pass  = trim($_POST['password'] ?? '');
        if (!$name || !$email || !$pass) {
            $msg = 'error:Name, email and password are required.';
        } elseif (strlen($pass) < 6) {
            $msg = 'error:Password must be at least 6 characters.';
        } else {
            $check = $conn->query("SELECT id FROM users WHERE email='".mysqli_real_escape_string($conn,$email)."'");
            if ($check->num_rows > 0) {
                $msg = 'error:Email already exists (it may belong to a deleted customer -- check Archive & History to restore them).';
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (full_name,email,phone,address,password,role,status) VALUES (?,?,?,?,?,'customer','active')");
                $stmt->bind_param("sssss", $name, $email, $phone, $addr, $hash);
                if ($stmt->execute()) {
                    $new_id = $conn->insert_id;
                    sendNotification($conn, $new_id, '👋 Welcome to Velveth Lending!',
                        'Your customer account has been created by our admin team. You can now log in and apply for a loan.', 'success');
                    logAudit($conn, 'Customer', $_SESSION['fullname'], 'Added new customer', "$name ($email)");
                    $msg = 'success:Customer added successfully.';
                } else { $msg = 'error:Failed to add customer: '.$conn->error; }
                $stmt->close();
            }
        }
    }

    // UPDATE
    if ($action === 'update') {
        $id     = (int)($_POST['customer_id'] ?? 0);
        $name   = trim($_POST['full_name'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $phone  = trim($_POST['phone'] ?? '');
        $addr   = trim($_POST['address'] ?? '');
        $status = in_array($_POST['status']??'',['active','inactive','suspended']) ? $_POST['status'] : 'active';
        if (!$id || !$name || !$email) {
            $msg = 'error:Name and email are required.';
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name=?,email=?,phone=?,address=?,status=? WHERE id=? AND role='customer'");
            $stmt->bind_param("sssssi", $name, $email, $phone, $addr, $status, $id);
            if ($stmt->execute()) {
                sendNotification($conn, $id, '📝 Account Updated', 'Your account information has been updated by the admin.', 'info');
                $cust_email = $conn->query("SELECT email FROM users WHERE id=$id")->fetch_assoc();
                if ($cust_email && function_exists('notifyAccountUpdate')) {
                    @notifyAccountUpdate($email, $name, 'Profile Updated',
                        "Your account details (name, email, phone, address, status) have been updated by an administrator.");
                }
                logAudit($conn, 'Customer', $_SESSION['fullname'], 'Updated customer', "$name (ID:$id)");
                $msg = 'success:Customer updated successfully.';
            } else { $msg = 'error:Update failed: '.$conn->error; }
            $stmt->close();
        }
    }

    // RESET PASSWORD
    if ($action === 'reset_password') {
        $id      = (int)($_POST['customer_id'] ?? 0);
        $newpass = trim($_POST['new_password'] ?? '');
        if (strlen($newpass) < 6) {
            $msg = 'error:Password must be at least 6 characters.';
        } else {
            $hash = password_hash($newpass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt->bind_param("si", $hash, $id);
            if ($stmt->execute()) {
                $u = $conn->query("SELECT full_name,email FROM users WHERE id=$id")->fetch_assoc();
                sendNotification($conn, $id, '🔒 Password Reset', 'Your password has been reset by an administrator. Please use your new password to log in.', 'warning');
                if ($u && function_exists('notifyAccountUpdate')) {
                    @notifyAccountUpdate($u['email'], $u['full_name'], 'Password Reset',
                        "Your account password has been reset by an administrator. Please log in with your new password immediately.");
                }
                logAudit($conn, 'Customer', $_SESSION['fullname'], 'Reset customer password', ($u['full_name']??'ID:'.$id));
                $msg = 'success:Password reset successfully.';
            } else { $msg = 'error:Password reset failed.'; }
            $stmt->close();
        }
    }

    // DELETE
    if ($action === 'delete') {
        $id = (int)($_POST['customer_id'] ?? 0);
        $cust = $conn->query("SELECT full_name FROM users WHERE id=$id AND role='customer' AND deleted_at IS NULL")->fetch_assoc();
        if ($cust) {
            // Soft delete: keep the row (and their loans/payments/documents) so
            // the customer can be restored from Archive & History.
            $conn->query("UPDATE users SET deleted_at=NOW() WHERE id=$id AND role='customer'");
            logAudit($conn, 'Customer', $_SESSION['fullname'], 'Deleted customer', $cust['full_name']." (ID:$id)");
            $msg = 'success:Customer '.$cust['full_name'].' moved to Archive. You can restore them anytime from Archive & History.';
        } else { $msg = 'error:Customer not found.'; }
    }

    header("Location: admin_customers.php?msg=".urlencode($msg)); exit;
}

if (isset($_GET['msg'])) $msg = urldecode($_GET['msg']);
[$mtype, $mtext] = $msg ? explode(':', $msg, 2) : ['',''];

// Search & fetch
$search        = trim($_GET['q'] ?? '');
$filter_period = $_GET['period'] ?? 'all'; // all | week | month | year
$sql = "SELECT u.*,
    (SELECT COUNT(*) FROM loan_applications la WHERE la.customer_id=u.id) AS loan_count,
    (SELECT SUM(p.amount) FROM payments p JOIN loans l ON p.loan_id=l.id WHERE l.customer_id=u.id AND p.status='confirmed') AS total_paid
    FROM users u WHERE u.role='customer' AND u.deleted_at IS NULL";
if ($search) {
    $s = mysqli_real_escape_string($conn, $search);
    $sql .= " AND (u.full_name LIKE '%$s%' OR u.email LIKE '%$s%' OR u.phone LIKE '%$s%')";
}
if ($filter_period !== 'all') {
    $period_map = ['week' => '1 WEEK', 'month' => '1 MONTH', 'year' => '1 YEAR'];
    if (isset($period_map[$filter_period])) {
        $sql .= " AND u.created_at >= DATE_SUB(NOW(), INTERVAL {$period_map[$filter_period]})";
    }
}
$sql .= " ORDER BY u.created_at DESC";
$customers  = $conn->query($sql);
$totalCount = $customers->num_rows;
$activeCount = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='customer' AND deleted_at IS NULL AND status='active'")->fetch_assoc()['c'] ?? 0;
$newThisMonth = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='customer' AND deleted_at IS NULL AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetch_assoc()['c'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Customers | Velveth Admin</title>
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
      <div class="app-bar-title">Customers</div>
      <div class="app-bar-sub"><?= $totalCount ?> total · <?= $activeCount ?> active</div>
    </div>
  </header>

  <main class="app-screen">

    <?php if ($mtext): ?>
    <div class="app-card" style="background:<?= $mtype==='success'?'#d1fae5':'#fee2e2' ?>;color:<?= $mtype==='success'?'#047857':'#b91c1c' ?>;font-size:12.5px;font-weight:600;display:flex;gap:8px;align-items:center;">
      <?= $mtype==='success'?'✅':'❌' ?> <?= htmlspecialchars($mtext) ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stat-chip-row">
      <div class="stat-chip"><div class="stat-chip-val"><?= $totalCount ?></div><div class="stat-chip-label">Total</div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:#10b981;"><?= $activeCount ?></div><div class="stat-chip-label">Active</div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:var(--maroon);"><?= $newThisMonth ?></div><div class="stat-chip-label">New This Month</div></div>
    </div>

    <!-- Filter by registration period -->
    <div class="seg-tabs">
      <a href="?period=all<?= $search?'&q='.urlencode($search):'' ?>"   class="seg-tab <?= $filter_period==='all'?'active':'' ?>">All Time</a>
      <a href="?period=week<?= $search?'&q='.urlencode($search):'' ?>"  class="seg-tab <?= $filter_period==='week'?'active':'' ?>">This Week</a>
      <a href="?period=month<?= $search?'&q='.urlencode($search):'' ?>" class="seg-tab <?= $filter_period==='month'?'active':'' ?>">This Month</a>
      <a href="?period=year<?= $search?'&q='.urlencode($search):'' ?>"  class="seg-tab <?= $filter_period==='year'?'active':'' ?>">This Year</a>
    </div>

    <!-- Search -->
    <form method="GET" style="display:flex;gap:8px;margin-bottom:14px;margin-top:10px;">
      <?php if($filter_period!=='all'): ?><input type="hidden" name="period" value="<?= $filter_period ?>"/><?php endif; ?>
      <div style="position:relative;flex:1;">
        <span style="position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:14px;color:var(--gray-400);">🔍</span>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, email, phone…"
               class="app-input" style="padding-left:36px;"/>
      </div>
      <?php if ($search): ?><a href="?period=<?= $filter_period ?>" class="app-btn app-btn-secondary app-btn-sm" style="width:auto;padding:0 16px;">✕</a><?php endif; ?>
    </form>

    <!-- List -->
    <div class="app-card-flat">
      <?php if ($customers->num_rows === 0): ?>
        <div class="app-empty">
          <div class="app-empty-icon">👥</div>
          <div class="app-empty-title"><?= $search ? 'No matches found' : 'No customers yet' ?></div>
          <div class="app-empty-sub"><?= $search ? 'Try a different search term.' : 'Tap the + button to add your first customer.' ?></div>
        </div>
      <?php else: while($row = $customers->fetch_assoc()): ?>
        <a href="javascript:void(0)" class="app-list-item" onclick='viewCustomer(<?= json_encode($row, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
          <div class="app-list-icon" style="background:linear-gradient(135deg,var(--maroon),var(--maroon-light));color:white;">
            <?= strtoupper(substr($row['full_name'],0,2)) ?>
          </div>
          <div class="app-list-body">
            <div class="app-list-title"><?= htmlspecialchars($row['full_name']) ?></div>
            <div class="app-list-sub"><?= htmlspecialchars($row['email']) ?> · <?= $row['loan_count'] ?> loan<?= $row['loan_count']!=1?'s':'' ?></div>
          </div>
          <div class="app-list-end">
            <span class="pill <?= $row['status'] ?>"><?= ucfirst($row['status']) ?></span>
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
      <div style="display:flex;align-items:center;gap:10px;">
        <div id="view_avatar" style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--maroon),var(--maroon-light));color:white;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;flex-shrink:0;"></div>
        <div>
          <div id="view_name" class="sheet-title">Customer Details</div>
          <div id="view_sub" style="font-size:11px;color:var(--gray-400);margin-top:1px;"></div>
        </div>
      </div>
      <button class="sheet-close" onclick="closeSheet('viewSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <div id="viewContent"></div>
      <div id="view_map_wrap" style="display:none;margin-top:14px;">
        <div style="font-size:11px;font-weight:800;color:var(--gray-400);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">📍 Address Location</div>
        <div style="border-radius:12px;overflow:hidden;border:1px solid var(--gray-200);">
          <iframe src="" width="100%" height="180" style="border:0;display:block;" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        </div>
        <a href="#" target="_blank" style="display:block;text-align:center;margin-top:8px;font-size:12px;color:var(--maroon);font-weight:700;">Open in Google Maps →</a>
      </div>
      <div class="app-btn-row-2" style="margin-top:18px;">
        <button class="app-btn app-btn-secondary" id="viewResetBtn">🔑 Reset Password</button>
        <button class="app-btn app-btn-primary" id="viewEditBtn">✏️ Edit</button>
      </div>
      <button class="app-btn app-btn-outline" id="viewDeleteBtn" style="margin-top:10px;color:#ef4444;border-color:#ef4444;">🗑 Delete Customer (can be restored)</button>
    </div>
  </div>

  <!-- ═══ ADD SHEET ═══ -->
  <div class="sheet-overlay" id="ov_addSheet" onclick="closeSheet('addSheet')"></div>
  <div class="bottom-sheet" id="addSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">➕ Add Customer</div>
      <button class="sheet-close" onclick="closeSheet('addSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <form method="POST">
        <input type="hidden" name="action" value="add"/>
        <div class="app-form-group">
          <label class="app-form-label">Full Name *</label>
          <input type="text" name="full_name" class="app-input" placeholder="First Middle Last" required/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Email Address *</label>
          <input type="email" name="email" class="app-input" placeholder="email@example.com" required/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Phone Number</label>
          <input type="tel" name="phone" class="app-input" placeholder="+63 9XX XXX XXXX"/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Address</label>
          <input type="text" name="address" class="app-input" placeholder="House No., Street, Barangay, City"/>
        </div>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Password * (min. 6 characters)</label>
          <input type="password" name="password" class="app-input" placeholder="Set initial password" required minlength="6"/>
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="margin-top:16px;">Add Customer</button>
      </form>
    </div>
  </div>

  <!-- ═══ EDIT SHEET ═══ -->
  <div class="sheet-overlay" id="ov_editSheet" onclick="closeSheet('editSheet')"></div>
  <div class="bottom-sheet" id="editSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">✏️ Edit Customer</div>
      <button class="sheet-close" onclick="closeSheet('editSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <form method="POST">
        <input type="hidden" name="action" value="update"/>
        <input type="hidden" name="customer_id" id="edit_id"/>
        <div class="app-form-group">
          <label class="app-form-label">Full Name *</label>
          <input type="text" name="full_name" id="edit_name" class="app-input" required/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Email Address *</label>
          <input type="email" name="email" id="edit_email" class="app-input" required/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Phone Number</label>
          <input type="tel" name="phone" id="edit_phone" class="app-input"/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Address</label>
          <input type="text" name="address" id="edit_address" class="app-input"/>
        </div>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Account Status</label>
          <select name="status" id="edit_status" class="app-select">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="suspended">Suspended</option>
          </select>
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="margin-top:16px;">Save Changes</button>
      </form>
    </div>
  </div>

  <!-- ═══ RESET PASSWORD SHEET ═══ -->
  <div class="sheet-overlay" id="ov_resetSheet" onclick="closeSheet('resetSheet')"></div>
  <div class="bottom-sheet" id="resetSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">🔑 Reset Password</div>
      <button class="sheet-close" onclick="closeSheet('resetSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <p id="resetName" style="font-size:13px;color:var(--gray-400);margin-bottom:16px;"></p>
      <form method="POST">
        <input type="hidden" name="action" value="reset_password"/>
        <input type="hidden" name="customer_id" id="reset_id"/>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">New Password * (min. 6 chars)</label>
          <input type="password" name="new_password" class="app-input" placeholder="Enter new password" required minlength="6"/>
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="margin-top:16px;">Reset Password</button>
      </form>
    </div>
  </div>

  <!-- ═══ DELETE CONFIRM SHEET ═══ -->
  <div class="sheet-overlay" id="ov_deleteConfirmSheet" onclick="closeSheet('deleteConfirmSheet')"></div>
  <div class="bottom-sheet" id="deleteConfirmSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title" style="color:#ef4444;">🗑 Delete Customer</div>
      <button class="sheet-close" onclick="closeSheet('deleteConfirmSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <p style="font-size:13.5px;color:var(--text-dark);margin-bottom:6px;">
        Delete <strong id="deleteConfirmName"></strong>?
      </p>
      <p style="font-size:12px;color:var(--gray-400);margin-bottom:20px;">
        They will be moved to Archive &amp; History and can no longer log in. You can restore them from there if this was a mistake.
      </p>
      <div class="app-btn-row-2">
        <button type="button" class="app-btn app-btn-secondary" onclick="closeSheet('deleteConfirmSheet')">Cancel</button>
        <button type="button" class="app-btn app-btn-primary" style="background:#ef4444;" onclick="confirmDeleteCustomer()">🗑 Delete</button>
      </div>
    </div>
  </div>

  <!-- Hidden delete form -->
  <form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete"/>
    <input type="hidden" name="customer_id" id="delete_id"/>
  </form>

<script>
function openSheet(id) { document.getElementById('ov_'+id)?.classList.add('open'); document.getElementById(id)?.classList.add('open'); }
function closeSheet(id) { document.getElementById('ov_'+id)?.classList.remove('open'); document.getElementById(id)?.classList.remove('open'); }
function switchSheet(fromId,toId){ closeSheet(fromId); openSheet(toId); }

function viewCustomer(data) {
  const initials = (data.full_name||'?').split(' ').map(w=>w[0]).join('').toUpperCase().slice(0,2);
  document.getElementById('view_avatar').textContent = initials;
  document.getElementById('view_name').textContent   = data.full_name || 'N/A';
  document.getElementById('view_sub').textContent    = data.email || '';

  const statusHtml = `<span class="pill ${data.status}">${(data.status||'').charAt(0).toUpperCase()+(data.status||'').slice(1)}</span>`;
  const joinDate   = data.created_at ? new Date(data.created_at).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'}) : '—';

  const rows = [
    ['Phone',        esc(data.phone||'—')],
    ['Address',      esc(data.address||'—')],
    ['Status',       statusHtml],
    ['Member Since',joinDate],
    ['Total Loans',  `<strong style="color:var(--maroon)">${data.loan_count||0}</strong>`],
    ['Total Paid',   '₱'+parseFloat(data.total_paid||0).toLocaleString('en-PH',{minimumFractionDigits:2})],
  ];

  document.getElementById('viewContent').innerHTML = rows.map(([l,v]) =>
    `<div class="view-row"><span class="view-label">${l}</span><span class="view-value">${v}</span></div>`).join('');

  // Google Maps preview of the customer's address -- helps with tracing a
  // customer's location if they stop paying. Uses the no-API-key embed
  // endpoint since we only have a free-text address, not stored coordinates.
  const mapWrap = document.getElementById('view_map_wrap');
  if (data.address && data.address.trim()) {
    mapWrap.style.display = 'block';
    mapWrap.querySelector('iframe').src = 'https://maps.google.com/maps?q=' + encodeURIComponent(data.address) + '&output=embed';
    mapWrap.querySelector('a').href = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(data.address);
  } else {
    mapWrap.style.display = 'none';
  }

  document.getElementById('viewEditBtn').onclick = function(){ closeSheet('viewSheet'); editCustomer(data); };
  document.getElementById('viewResetBtn').onclick = function(){ closeSheet('viewSheet'); resetPassCustomer(data.id, data.full_name); };
  document.getElementById('viewDeleteBtn').onclick = function(){
    document.getElementById('deleteConfirmName').textContent = data.full_name;
    document.getElementById('delete_id').value = data.id;
    closeSheet('viewSheet');
    openSheet('deleteConfirmSheet');
  };
  openSheet('viewSheet');
}

function confirmDeleteCustomer() {
  closeSheet('deleteConfirmSheet');
  document.getElementById('deleteForm').submit();
}

function editCustomer(data) {
  document.getElementById('edit_id').value      = data.id || '';
  document.getElementById('edit_name').value    = data.full_name || '';
  document.getElementById('edit_email').value   = data.email || '';
  document.getElementById('edit_phone').value   = data.phone || '';
  document.getElementById('edit_address').value = data.address || '';
  document.getElementById('edit_status').value  = data.status || 'active';
  openSheet('editSheet');
}

function resetPassCustomer(id, name) {
  document.getElementById('reset_id').value = id;
  document.getElementById('resetName').textContent = 'Resetting password for: ' + name;
  openSheet('resetSheet');
}

function esc(s) { const d=document.createElement('div'); d.textContent = s || '—'; return d.innerHTML; }
</script>
  <script src="assets/js/form-guard.js"></script>
  <script src="realtime.js"></script>
</body>
</html>
