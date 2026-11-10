<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php"); exit;
}
$_cur = 'admin_users.php';
$self_id = (int)$_SESSION['user_id'];

// ── POST Actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $msg    = '';

    // ADD user
    if ($action === 'add') {
        $name  = trim($_POST['full_name']  ?? '');
        $email = trim($_POST['email']      ?? '');
        $phone = trim($_POST['phone']      ?? '');
        $role  = in_array($_POST['role']??'',['admin','customer']) ? $_POST['role'] : 'customer';
        $pass  = trim($_POST['password']   ?? '');

        if (!$name || !$email || !$pass) {
            $msg = 'error:Name, email and password are required.';
        } elseif (strlen($pass) < 6) {
            $msg = 'error:Password must be at least 6 characters.';
        } else {
            $check = $conn->query("SELECT id FROM users WHERE email='".mysqli_real_escape_string($conn,$email)."'");
            if ($check->num_rows > 0) {
                $msg = 'error:Email already exists.';
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (full_name,email,phone,password,role,status) VALUES (?,?,?,?,?,'active')");
                $stmt->bind_param("sssss", $name, $email, $phone, $hash, $role);
                if ($stmt->execute()) {
                    $new_id = $conn->insert_id;
                    sendNotification($conn, $new_id, '👋 Welcome to Velveth Lending!',
                        "Your ".ucfirst($role)." account has been created. You can now sign in.", 'success');
                    logAudit($conn,'User',$_SESSION['fullname'],"Created $role account","$name ($email)");
                    $msg = 'success:User added successfully.';
                } else { $msg = 'error:Failed to add user: '.$conn->error; }
                $stmt->close();
            }
        }
    }

    // UPDATE user
    if ($action === 'update') {
        $id     = (int)($_POST['user_id'] ?? 0);
        $name   = trim($_POST['full_name'] ?? '');
        $email  = trim($_POST['email']     ?? '');
        $phone  = trim($_POST['phone']     ?? '');
        $role   = in_array($_POST['role']??'',['admin','customer']) ? $_POST['role'] : 'customer';
        $status = in_array($_POST['status']??'',['active','inactive','suspended']) ? $_POST['status'] : 'active';

        if (!$id || !$name || !$email) {
            $msg = 'error:Name and email are required.';
        } elseif ($id === $self_id && $role !== 'admin') {
            $msg = 'error:You cannot change your own role.';
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name=?,email=?,phone=?,role=?,status=? WHERE id=?");
            $stmt->bind_param("sssssi", $name, $email, $phone, $role, $status, $id);
            if ($stmt->execute()) {
                sendNotification($conn, $id, '📝 Account Updated',
                    'Your account details have been updated by an administrator.', 'info');
                logAudit($conn,'User',$_SESSION['fullname'],'Updated user',"$name (ID:$id)");
                $msg = 'success:User updated successfully.';
            } else { $msg = 'error:Update failed: '.$conn->error; }
            $stmt->close();
        }
    }

    // RESET PASSWORD
    if ($action === 'reset_password') {
        $id      = (int)($_POST['user_id']      ?? 0);
        $newpass = trim($_POST['new_password']   ?? '');
        if (strlen($newpass) < 6) {
            $msg = 'error:Password must be at least 6 characters.';
        } else {
            $hash = password_hash($newpass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt->bind_param("si", $hash, $id);
            if ($stmt->execute()) {
                $u = $conn->query("SELECT full_name FROM users WHERE id=$id")->fetch_assoc();
                sendNotification($conn, $id, '🔒 Password Changed',
                    'Your account password has been reset by an administrator. Please log in with your new password.', 'warning');
                logAudit($conn,'User',$_SESSION['fullname'],'Reset password for',($u['full_name']??'ID:'.$id));
                $msg = 'success:Password reset successfully.';
            } else { $msg = 'error:Failed to reset password.'; }
            $stmt->close();
        }
    }

    // DELETE user
    if ($action === 'delete') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id === $self_id) {
            $msg = 'error:You cannot delete your own account.';
        } else {
            $u = $conn->query("SELECT full_name,role FROM users WHERE id=$id AND deleted_at IS NULL")->fetch_assoc();
            if ($u) {
                if ($u['role'] === 'customer') {
                    // Customers are soft-deleted so they can be restored from Archive & History.
                    $conn->query("UPDATE users SET deleted_at=NOW() WHERE id=$id");
                } else {
                    $conn->query("DELETE FROM users WHERE id=$id");
                }
                logAudit($conn,'User',$_SESSION['fullname'],'Deleted user',
                    $u['full_name'].' ('.ucfirst($u['role']).") ID:$id");
                $msg = 'success:User "'.$u['full_name'].'" deleted.';
            } else { $msg = 'error:User not found.'; }
        }
    }

    header("Location: admin_users.php?msg=".urlencode($msg)); exit;
}

// ── GET ───────────────────────────────────────────────────────────────────────
$msg    = isset($_GET['msg']) ? urldecode($_GET['msg']) : '';
[$mtype,$mtext] = $msg ? explode(':',$msg,2) : ['',''];

$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM users WHERE deleted_at IS NULL";
if ($filter === 'admin')    $sql .= " AND role='admin'";
if ($filter === 'customer') $sql .= " AND role='customer'";
if ($search) {
    $s = mysqli_real_escape_string($conn, $search);
    $sql .= " AND (full_name LIKE '%$s%' OR email LIKE '%$s%')";
}
$sql .= " ORDER BY role ASC, created_at DESC";
$users = $conn->query($sql);

$total_all      = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE deleted_at IS NULL")->fetch_assoc()['c'] ?? 0;
$total_admins   = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='admin'")->fetch_assoc()['c'] ?? 0;
$total_customers= (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='customer' AND deleted_at IS NULL")->fetch_assoc()['c'] ?? 0;
$total_active   = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE status='active' AND deleted_at IS NULL")->fetch_assoc()['c'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>User Management | Velveth Admin</title>
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
      <div class="app-bar-title">User Management</div>
      <div class="app-bar-sub"><?= $total_admins ?> admins · <?= $total_customers ?> customers</div>
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
      <div class="stat-chip"><div class="stat-chip-val"><?= $total_all ?></div><div class="stat-chip-label">Total Users</div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:#9b1c1c;"><?= $total_admins ?></div><div class="stat-chip-label">Admins</div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:#1e40af;"><?= $total_customers ?></div><div class="stat-chip-label">Customers</div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:#10b981;"><?= $total_active ?></div><div class="stat-chip-label">Active</div></div>
    </div>

    <!-- Filters -->
    <div class="seg-tabs">
      <a href="?filter=all<?= $search?'&q='.urlencode($search):''?>"      class="seg-tab <?= $filter==='all'?'active':''?>">All (<?= $total_all ?>)</a>
      <a href="?filter=admin<?= $search?'&q='.urlencode($search):''?>"    class="seg-tab <?= $filter==='admin'?'active':''?>">Admins (<?= $total_admins ?>)</a>
      <a href="?filter=customer<?= $search?'&q='.urlencode($search):''?>" class="seg-tab <?= $filter==='customer'?'active':''?>">Customers (<?= $total_customers ?>)</a>
    </div>

    <!-- Search -->
    <form method="GET" style="display:flex;gap:8px;margin-bottom:14px;">
      <?php if($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= $filter ?>"/><?php endif; ?>
      <div style="position:relative;flex:1;">
        <span style="position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:14px;color:var(--gray-400);">🔍</span>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or email…" class="app-input" style="padding-left:36px;"/>
      </div>
      <?php if($search): ?><a href="?filter=<?= $filter ?>" class="app-btn app-btn-secondary" style="width:auto;padding:0 16px;">✕</a><?php endif; ?>
    </form>

    <!-- List -->
    <div class="app-card-flat">
      <?php if ($users->num_rows === 0): ?>
        <div class="app-empty">
          <div class="app-empty-icon">👥</div>
          <div class="app-empty-title"><?= $search ? 'No users match your search' : 'No users found' ?></div>
        </div>
      <?php else: while($row = $users->fetch_assoc()): $is_self = ($row['id'] == $self_id); $isAdmin = $row['role']==='admin'; ?>
        <a href="javascript:void(0)" class="app-list-item" style="<?= $is_self?'background:#fde8ec;':'' ?>" onclick='viewUser(<?= json_encode($row, JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= $is_self?1:0 ?>)'>
          <div class="app-list-icon" style="background:<?= $isAdmin?'var(--maroon-deep,#5c1520)':'linear-gradient(135deg,var(--maroon),var(--maroon-light))' ?>;color:white;">
            <?= strtoupper(substr($row['full_name'],0,2)) ?>
          </div>
          <div class="app-list-body">
            <div class="app-list-title"><?= htmlspecialchars($row['full_name']) ?><?= $is_self?' <span style="color:var(--maroon);font-size:9.5px;font-weight:800;">YOU</span>':'' ?></div>
            <div class="app-list-sub"><?= htmlspecialchars($row['email']) ?></div>
          </div>
          <div class="app-list-end" style="display:flex;flex-direction:column;align-items:flex-end;gap:3px;">
            <span style="padding:2px 9px;border-radius:100px;font-size:10px;font-weight:700;background:<?= $isAdmin?'#fef3f2':'#eff6ff' ?>;color:<?= $isAdmin?'#9b1c1c':'#1e40af' ?>;"><?= $isAdmin?'🔐 Admin':'👤 Customer' ?></span>
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
        <div id="vu_avatar" style="width:38px;height:38px;border-radius:50%;background:var(--maroon);color:white;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;flex-shrink:0;"></div>
        <div>
          <div id="vu_name" class="sheet-title">User Details</div>
          <div id="vu_sub" style="font-size:11px;color:var(--gray-400);margin-top:1px;"></div>
        </div>
      </div>
      <button class="sheet-close" onclick="closeSheet('viewSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <div id="vuContent"></div>
      <div class="app-btn-row-2" style="margin-top:18px;">
        <button class="app-btn app-btn-secondary" id="vuResetBtn">🔑 Reset Password</button>
        <button class="app-btn app-btn-primary" id="vuEditBtn">✏️ Edit</button>
      </div>
      <button class="app-btn app-btn-outline" id="vuDeleteBtn" style="margin-top:10px;color:#ef4444;border-color:#ef4444;">🗑 Delete User</button>
    </div>
  </div>

  <!-- ═══ ADD SHEET ═══ -->
  <div class="sheet-overlay" id="ov_addSheet" onclick="closeSheet('addSheet')"></div>
  <div class="bottom-sheet" id="addSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">➕ Add New User</div>
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
          <label class="app-form-label">Role *</label>
          <select name="role" class="app-select" required>
            <option value="customer">👤 Customer</option>
            <option value="admin">🔐 Admin</option>
          </select>
        </div>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Password * (min. 6 characters)</label>
          <input type="password" name="password" class="app-input" placeholder="Initial password" required minlength="6"/>
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="margin-top:16px;">Add User</button>
      </form>
    </div>
  </div>

  <!-- ═══ EDIT SHEET ═══ -->
  <div class="sheet-overlay" id="ov_editSheet" onclick="closeSheet('editSheet')"></div>
  <div class="bottom-sheet" id="editSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">✏️ Edit User</div>
      <button class="sheet-close" onclick="closeSheet('editSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <form method="POST">
        <input type="hidden" name="action" value="update"/>
        <input type="hidden" name="user_id" id="eu_id"/>
        <div class="app-form-group">
          <label class="app-form-label">Full Name *</label>
          <input type="text" name="full_name" id="eu_name" class="app-input" required/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Email Address *</label>
          <input type="email" name="email" id="eu_email" class="app-input" required/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Phone Number</label>
          <input type="tel" name="phone" id="eu_phone" class="app-input"/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Role</label>
          <select name="role" id="eu_role" class="app-select">
            <option value="customer">👤 Customer</option>
            <option value="admin">🔐 Admin</option>
          </select>
        </div>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Status</label>
          <select name="status" id="eu_status" class="app-select">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="suspended">Suspended</option>
          </select>
        </div>
        <div id="eu_self_note" style="display:none;background:#fef9c3;border:1px solid #fde047;color:#92400e;padding:10px 14px;border-radius:10px;font-size:11.5px;margin-top:12px;">
          ⚠️ You are editing your own account. Role cannot be changed.
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
      <p style="font-size:13px;color:var(--gray-400);margin-bottom:14px;">Resetting password for: <strong id="rp_name" style="color:var(--maroon);"></strong></p>
      <form method="POST">
        <input type="hidden" name="action" value="reset_password"/>
        <input type="hidden" name="user_id" id="rp_uid"/>
        <div class="app-form-group">
          <label class="app-form-label">New Password * (min. 6 characters)</label>
          <input type="password" name="new_password" class="app-input" placeholder="Enter new password" required minlength="6"/>
        </div>
        <div style="background:#fef9c3;border:1px solid #fde047;color:#92400e;padding:10px 14px;border-radius:10px;font-size:11.5px;">
          ⚠️ This immediately changes the user's password. Please inform them.
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="margin-top:16px;background:#f59e0b;">🔑 Reset Password</button>
      </form>
    </div>
  </div>

  <!-- Hidden delete form -->
  <form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete"/>
    <input type="hidden" name="user_id" id="delete_id"/>
  </form>

<script>
function openSheet(id) { document.getElementById('ov_'+id)?.classList.add('open'); document.getElementById(id)?.classList.add('open'); }
function closeSheet(id) { document.getElementById('ov_'+id)?.classList.remove('open'); document.getElementById(id)?.classList.remove('open'); }
function esc(s) { const d=document.createElement('div'); d.textContent=s||'—'; return d.innerHTML; }

const SELF_ID = <?= $self_id ?>;

function viewUser(data, isSelf) {
  const initials = (data.full_name||'?').split(' ').map(w=>w[0]).join('').toUpperCase().slice(0,2);
  const isAdmin  = data.role === 'admin';

  document.getElementById('vu_avatar').textContent = initials;
  document.getElementById('vu_avatar').style.background = isAdmin ? 'var(--maroon-deep,#5c1520)' : 'linear-gradient(135deg,var(--maroon),var(--maroon-light))';
  document.getElementById('vu_name').textContent = data.full_name || 'N/A';
  document.getElementById('vu_sub').textContent  = data.email || '';

  const roleHtml = `<span style="padding:2px 9px;border-radius:100px;font-size:10.5px;font-weight:700;background:${isAdmin?'#fef3f2':'#eff6ff'};color:${isAdmin?'#9b1c1c':'#1e40af'};">${isAdmin?'🔐 Admin':'👤 Customer'}</span>`;
  const statusHtml = `<span class="pill ${data.status}">${(data.status||'').charAt(0).toUpperCase()+(data.status||'').slice(1)}</span>`;
  const joinDate = data.created_at ? new Date(data.created_at).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'}) : '—';

  const rows = [
    ['User ID',   '#'+esc(data.id)],
    ['Phone',     esc(data.phone||'—')],
    ['Role',      roleHtml],
    ['Status',    statusHtml],
    ['Joined',    joinDate],
  ];
  document.getElementById('vuContent').innerHTML = rows.map(([l,v]) =>
    `<div class="view-row"><span class="view-label">${l}</span><span class="view-value">${v}</span></div>`).join('');

  document.getElementById('vuEditBtn').onclick = function(){ closeSheet('viewSheet'); editUser(data, isSelf); };
  document.getElementById('vuResetBtn').onclick = function(){ closeSheet('viewSheet'); resetPw(data.id, data.full_name); };

  const delBtn = document.getElementById('vuDeleteBtn');
  if (isSelf) {
    delBtn.style.display = 'none';
  } else {
    delBtn.style.display = 'block';
    delBtn.onclick = function(){
      if (confirm('Delete ' + data.full_name + '?\nThis cannot be undone.')) {
        document.getElementById('delete_id').value = data.id;
        document.getElementById('deleteForm').submit();
      }
    };
  }
  openSheet('viewSheet');
}

function editUser(data, isSelf) {
  document.getElementById('eu_id').value     = data.id      || '';
  document.getElementById('eu_name').value   = data.full_name || '';
  document.getElementById('eu_email').value  = data.email   || '';
  document.getElementById('eu_phone').value  = data.phone   || '';
  document.getElementById('eu_role').value   = data.role    || 'customer';
  document.getElementById('eu_status').value = data.status  || 'active';

  const roleSelect = document.getElementById('eu_role');
  const selfNote   = document.getElementById('eu_self_note');
  roleSelect.disabled    = !!isSelf;
  selfNote.style.display = isSelf ? 'block' : 'none';

  openSheet('editSheet');
}

function resetPw(id, name) {
  document.getElementById('rp_uid').value = id;
  document.getElementById('rp_name').textContent = name;
  openSheet('resetSheet');
}
</script>
  <script src="realtime.js"></script>
</body>
</html>
