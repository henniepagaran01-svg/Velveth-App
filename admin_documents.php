<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php"); exit;
}
$_cur = 'admin_documents.php';

$upload_dir = 'uploads/documents/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

// ════════════════════════════════════════════════
//  POST HANDLER
// ════════════════════════════════════════════════
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── UPLOAD ──────────────────────────────────
    if ($action === 'upload') {
        $cust_id   = (int)$_POST['customer_id'];
        $app_db_id = (int)($_POST['application_id'] ?? 0);
        $doc_type  = trim($_POST['doc_type']);

        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            $msg = 'error:Please select a valid file.';
        } else {
            $ext     = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','jpg','jpeg','png'];
            if (!in_array($ext, $allowed)) {
                $msg = 'error:Only PDF, JPG, PNG files allowed.';
            } elseif ($_FILES['document']['size'] > 5 * 1024 * 1024) {
                $msg = 'error:File too large (max 5MB).';
            } else {
                $count  = (int)$conn->query("SELECT COUNT(*)+1 AS c FROM documents")->fetch_assoc()['c'] ?? 0;
                $doc_id = 'DOC-' . str_pad($count, 3, '0', STR_PAD_LEFT);
                $fname  = $doc_id . '_' . time() . '.' . $ext;
                $fpath  = $upload_dir . $fname;

                if (move_uploaded_file($_FILES['document']['tmp_name'], $fpath)) {
                    if ($app_db_id > 0) {
                        $stmt = $conn->prepare("INSERT INTO documents (doc_id,customer_id,application_id,doc_type,file_name,file_path,status) VALUES (?,?,?,?,?,?,'pending')");
                        $stmt->bind_param("siisss", $doc_id, $cust_id, $app_db_id, $doc_type, $fname, $fpath);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO documents (doc_id,customer_id,doc_type,file_name,file_path,status) VALUES (?,?,?,?,?,'pending')");
                        $stmt->bind_param("sisss", $doc_id, $cust_id, $doc_type, $fname, $fpath);
                    }
                    if ($stmt->execute()) {
                        $conn->query("INSERT INTO notifications (user_id,title,message,type)
                            VALUES ($cust_id,'📄 Document Received',
                            'Your $doc_type has been uploaded to your account and is currently under review.','info')");
                        logAudit($conn,'Document',$_SESSION['fullname'],'Uploaded document',"$doc_id — $doc_type");
                        $msg = 'success:Document uploaded successfully.';
                    } else {
                        @unlink($fpath);
                        $msg = 'error:Database error: '.$conn->error;
                    }
                    $stmt->close();
                } else {
                    $msg = 'error:Failed to save file. Check server permissions.';
                }
            }
        }
    }

    // ── VERIFY / REJECT single document ─────────
    if ($action === 'update_status') {
        $doc_db_id = (int)$_POST['doc_id'];
        $status    = $_POST['status'];
        $notes     = trim($_POST['notes'] ?? '');

        if (!in_array($status, ['verified','rejected'])) {
            $msg = 'error:Invalid status.';
        } else {
            $doc = $conn->query("
                SELECT d.*, u.id AS uid, u.full_name, la.app_id AS loan_app_id
                FROM documents d
                JOIN users u ON d.customer_id = u.id
                LEFT JOIN loan_applications la ON d.application_id = la.id
                WHERE d.id = $doc_db_id
            ")->fetch_assoc();

            if ($doc) {
                $admin_id = (int)$_SESSION['user_id'];
                $esc_notes = mysqli_real_escape_string($conn, $notes);
                $conn->query("UPDATE documents SET status='$status', notes='$esc_notes',
                    reviewed_by=$admin_id, reviewed_at=NOW() WHERE id=$doc_db_id");

                $uid      = (int)$doc['uid'];
                $doc_type = $doc['doc_type'];
                $app_ref  = $doc['loan_app_id'] ? " for application {$doc['loan_app_id']}" : '';

                if ($status === 'verified') {
                    $notif_title = '✅ Document Verified';
                    $notif_msg   = "Your $doc_type$app_ref has been verified by our team. Thank you!";
                    $notif_type  = 'success';
                } else {
                    $reason      = $notes ?: 'Please re-upload a clearer copy.';
                    $notif_title = '❌ Document Rejected';
                    $notif_msg   = "Your $doc_type$app_ref was not accepted. Reason: $reason";
                    $notif_type  = 'danger';
                }

                $esc_title = mysqli_real_escape_string($conn, $notif_title);
                $esc_notif = mysqli_real_escape_string($conn, $notif_msg);
                $conn->query("INSERT INTO notifications (user_id,title,message,type)
                    VALUES ($uid,'$esc_title','$esc_notif','$notif_type')");

                logAudit($conn,'Document',$_SESSION['fullname'],"Document → $status","{$doc['doc_id']} ({$doc_type})");
                $msg = 'success:Document ' . $status . '. Customer has been notified.';
            } else {
                $msg = 'error:Document not found.';
            }
        }
    }

    // ── QUALIFY / NOT QUALIFY applicant ─────────
    if ($action === 'qualify_applicant') {
        $app_db_id = (int)$_POST['application_id'];
        $decision  = $_POST['decision']; // approved | rejected
        $remarks   = trim($_POST['remarks'] ?? '');

        $app = $conn->query("
            SELECT la.*, u.full_name, u.email, u.id AS uid
            FROM loan_applications la
            JOIN users u ON la.customer_id = u.id
            WHERE la.id = $app_db_id AND la.status = 'pending'
        ")->fetch_assoc();

        // Safety net (the button is also disabled in the UI): an application can only
        // be QUALIFIED once every submitted document has been viewed and verified.
        if ($app && $decision === 'approved') {
            $dc = $conn->query("SELECT COUNT(*) total, SUM(status='verified') ok FROM documents WHERE application_id=$app_db_id")->fetch_assoc();
            if ((int)$dc['total'] === 0 || (int)$dc['ok'] !== (int)$dc['total']) {
                $msg = 'error:Cannot qualify yet — verify ALL submitted documents first.';
                header("Location: admin_documents.php?tab=applications&app=$app_db_id&msg=" . urlencode($msg));
                exit;
            }
        }

        if ($app) {
            $admin_id  = (int)$_SESSION['user_id'];
            $uid       = (int)$app['uid'];
            $app_code  = $app['app_id'];
            $cust_name = $app['full_name'];
            $amt       = number_format($app['loan_amount'], 2);

            if ($decision === 'approved') {
                $esc_remarks = mysqli_real_escape_string($conn, $remarks);
                $conn->query("UPDATE loan_applications SET status='approved',
                    approved_by=$admin_id, approved_at=NOW(), remarks='$esc_remarks'
                    WHERE id=$app_db_id");

                $end  = date('Y-m-d', strtotime('+' . $app['loan_term'] . ' months'));
                $next = date('Y-m-d', strtotime('+1 month'));
                $conn->query("INSERT INTO loans
                    (application_id,customer_id,loan_amount,outstanding_balance,monthly_payment,next_payment_date,start_date,end_date)
                    VALUES ($app_db_id,{$app['customer_id']},{$app['loan_amount']},{$app['loan_amount']},
                    {$app['monthly_payment']},'$next',NOW(),'$end')");
                $conn->query("UPDATE loan_applications SET status='active' WHERE id=$app_db_id");

                $conn->query("UPDATE documents SET status='verified', reviewed_by=$admin_id, reviewed_at=NOW()
                    WHERE application_id=$app_db_id AND status='pending'");

                $next_fmt   = date('F d, Y', strtotime($next));
                $monthly    = number_format($app['monthly_payment'], 2);
                $notif_title = '🎉 Loan Approved — You\'re Qualified!';
                $notif_msg   = "Congratulations $cust_name! Your loan application $app_code for ₱$amt has been approved after document review. "
                             . "Your monthly payment of ₱$monthly starts on $next_fmt. "
                             . ($remarks ? "Admin note: $remarks" : "Welcome to Velveth Lending!");

                $esc_title = mysqli_real_escape_string($conn, $notif_title);
                $esc_msg   = mysqli_real_escape_string($conn, $notif_msg);
                $conn->query("INSERT INTO notifications (user_id,title,message,type)
                    VALUES ($uid,'$esc_title','$esc_msg','success')");

                logAudit($conn,'Loan',$_SESSION['fullname'],'✅ Qualified & approved',"$app_code — $cust_name");
                $msg = 'success:✅ Loan approved! ' . $cust_name . ' has been notified.';

            } else {
                $reason      = $remarks ?: 'Submitted documents did not meet our requirements.';
                $esc_reason  = mysqli_real_escape_string($conn, $reason);
                $conn->query("UPDATE loan_applications SET status='rejected', remarks='$esc_reason' WHERE id=$app_db_id");

                $notif_title = '❌ Loan Application Not Approved';
                $notif_msg   = "Dear $cust_name, we have reviewed your application $app_code for ₱$amt and your submitted documents. "
                             . "Unfortunately, we are unable to approve your loan at this time. Reason: $reason "
                             . "You may reapply after addressing the stated reason.";

                $esc_title = mysqli_real_escape_string($conn, $notif_title);
                $esc_msg   = mysqli_real_escape_string($conn, $notif_msg);
                $conn->query("INSERT INTO notifications (user_id,title,message,type)
                    VALUES ($uid,'$esc_title','$esc_msg','danger')");

                logAudit($conn,'Loan',$_SESSION['fullname'],'❌ Not qualified — rejected',"$app_code — $cust_name");
                $msg = 'error:Application rejected. ' . $cust_name . ' has been notified.';
            }
        } else {
            $msg = 'error:Application not found or already processed.';
        }
    }

    // ── DELETE document ──────────────────────────
    if ($action === 'delete') {
        $doc_db_id = (int)$_POST['doc_id'];
        $doc = $conn->query("SELECT * FROM documents WHERE id=$doc_db_id")->fetch_assoc();
        if ($doc) {
            if ($doc['file_path'] && file_exists($doc['file_path'])) @unlink($doc['file_path']);
            $conn->query("DELETE FROM documents WHERE id=$doc_db_id");
            logAudit($conn,'Document',$_SESSION['fullname'],'Deleted document',$doc['doc_id']);
            $msg = 'success:Document deleted.';
        } else {
            $msg = 'error:Document not found.';
        }
    }

    $back_app = (int)($_POST['_app'] ?? 0);
    $back_app_q = ($back_app > 0 && $action === 'update_status') ? "&app=$back_app" : '';
    header("Location: admin_documents.php?tab=" . ($_POST['_tab'] ?? 'docs') . $back_app_q . "&msg=" . urlencode($msg));
    exit;
}

// ════════════════════════════════════════════════
//  GET — fetch data
// ════════════════════════════════════════════════
$msg    = isset($_GET['msg']) ? urldecode($_GET['msg']) : '';
[$mtype,$mtext] = $msg ? explode(':', $msg, 2) : ['',''];

$filter = $_GET['filter'] ?? 'all';
$tab    = $_GET['tab']    ?? 'docs';

$doc_where = $filter !== 'all' ? "WHERE d.status='".mysqli_real_escape_string($conn,$filter)."'" : '';
$docs = $conn->query("
    SELECT d.*, u.full_name, u.email, la.app_id AS loan_app_id,
        TIMESTAMPDIFF(HOUR, d.created_at, NOW()) AS hours_ago
    FROM documents d
    JOIN users u ON d.customer_id = u.id
    LEFT JOIN loan_applications la ON d.application_id = la.id
    $doc_where
    ORDER BY d.created_at DESC
");

$apps_with_docs = $conn->query("
    SELECT la.*, u.full_name, u.email,
        COUNT(d.id)                                           AS doc_count,
        SUM(CASE WHEN d.status='verified'  THEN 1 ELSE 0 END) AS verified_count,
        SUM(CASE WHEN d.status='pending'   THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN d.status='rejected'  THEN 1 ELSE 0 END) AS rejected_count,
        TIMESTAMPDIFF(HOUR, la.created_at, NOW())             AS hours_ago
    FROM loan_applications la
    JOIN users u ON la.customer_id = u.id
    LEFT JOIN documents d ON d.application_id = la.id
    WHERE la.status = 'pending'
    GROUP BY la.id
    ORDER BY la.created_at DESC
");

$cnt_pending      = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE status='pending'")->fetch_assoc()['c'] ?? 0;
$cnt_verified     = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE status='verified'")->fetch_assoc()['c'] ?? 0;
$cnt_rejected     = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE status='rejected'")->fetch_assoc()['c'] ?? 0;
$cnt_total_docs   = $cnt_pending + $cnt_verified + $cnt_rejected;
$apps_arr = [];
while ($ar = $apps_with_docs->fetch_assoc()) $apps_arr[] = $ar;
$cnt_new_apps  = 0; foreach ($apps_arr as $ar) if ($ar['hours_ago'] <= 24) $cnt_new_apps++;
$unreadNotifs  = (int)($conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=".(int)$_SESSION['user_id']." AND is_read=0")->fetch_assoc()['c'] ?? 0);

// Renders one document as an inline preview card (image shown right away, no "View" click needed)
function renderDocCard($dd, $lbl, $ctx = 'applications') {
    $sc = ['pending'=>['#fef3c7','#f59e0b'],'verified'=>['#dcfce7','#16a34a'],'rejected'=>['#fee2e2','#ef4444']][$dd['status']] ?? ['#eff6ff','#2563eb'];
    $ext = strtolower(pathinfo($dd['file_name'] ?? '', PATHINFO_EXTENSION));
    $is_img = in_array($ext, ['jpg','jpeg','png','gif','webp']);
    $t = htmlspecialchars(addslashes($dd['doc_type']));
    ob_start(); ?>
    <div class="doc-thumb">
      <div class="doc-thumb-head" style="background:<?= $sc[0] ?>;">
        <span style="font-size:11px;font-weight:800;"><?= htmlspecialchars($lbl) ?></span>
        <span style="font-size:9.5px;font-weight:800;color:<?= $sc[1] ?>;background:white;padding:1px 7px;border-radius:100px;"><?= ucfirst($dd['status']) ?></span>
      </div>
      <?php if ($is_img && $dd['file_path']): ?>
      <div class="doc-thumb-img-wrap"><img src="<?= htmlspecialchars($dd['file_path']) ?>" alt="<?= htmlspecialchars($dd['doc_type']) ?>" onerror="this.style.display='none'"/></div>
      <?php else: ?>
      <div style="padding:16px;text-align:center;font-size:11px;color:var(--gray-400);">📄 <?= htmlspecialchars($dd['file_name'] ?? 'File') ?></div>
      <?php endif; ?>
      <?php if (!empty($dd['notes'])): ?><div style="padding:6px 10px;background:#fff8e1;font-size:10px;color:#92400e;border-top:1px solid #fef3c7;">📝 <?= htmlspecialchars($dd['notes']) ?></div><?php endif; ?>
      <div class="doc-thumb-actions">
        <?php if ($dd['file_path']): ?><a href="<?= htmlspecialchars($dd['file_path']) ?>" target="_blank" class="app-btn app-btn-secondary app-btn-sm" style="flex:1;">View Full</a><?php endif; ?>
        <?php if ($dd['status']==='pending'): ?>
          <button class="app-btn app-btn-sm" style="flex:1;background:#10b981;color:white;border:none;" onclick="updateDocStatus(<?= $dd['id'] ?>,'verified','<?= $t ?>','<?= $ctx ?>')">✓</button>
          <button class="app-btn app-btn-sm" style="flex:1;background:#ef4444;color:white;border:none;" onclick="updateDocStatus(<?= $dd['id'] ?>,'rejected','<?= $t ?>','<?= $ctx ?>')">✕</button>
        <?php elseif ($dd['status']==='rejected'): ?>
          <button class="app-btn app-btn-sm" style="flex:1;background:#10b981;color:white;border:none;" onclick="updateDocStatus(<?= $dd['id'] ?>,'verified','<?= $t ?>','<?= $ctx ?>')">✓ Re-verify</button>
        <?php else: ?>
          <span style="font-size:10.5px;color:#15803d;font-weight:700;flex:1;text-align:center;">✅ Verified</span>
        <?php endif; ?>
      </div>
    </div>
    <?php return ob_get_clean();
}
$cnt_pending_apps = (int)$conn->query("SELECT COUNT(*) c FROM loan_applications WHERE status='pending'")->fetch_assoc()['c'] ?? 0;
$cnt_new_docs     = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE created_at >= NOW() - INTERVAL 24 HOUR")->fetch_assoc()['c'] ?? 0;

$customers_list = $conn->query("SELECT id,full_name FROM users WHERE role='customer' AND deleted_at IS NULL AND status='active' ORDER BY full_name");
$apps_list      = $conn->query("SELECT la.id,la.app_id,u.full_name FROM loan_applications la JOIN users u ON la.customer_id=u.id ORDER BY la.created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Documents | Velveth Admin</title>
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
    .qtab-content { display:none; }
    .qtab-content.active { display:block; animation:fadeInApp .2s ease; }
    @keyframes fadeInApp { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:translateY(0)} }
    .file-drop-app {
      border:2px dashed var(--gray-200); border-radius:14px; padding:26px 16px;
      text-align:center; cursor:pointer; background:var(--gray-50); position:relative;
    }
    .file-drop-app input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
    .app-btn:disabled { opacity:.4; cursor:not-allowed; filter:grayscale(.4); }
    .applicant-row { display:flex; align-items:center; gap:12px; padding:12px 14px; border-bottom:1px solid var(--gray-100); cursor:pointer; }
    .applicant-row:active { background:var(--gray-50); }
    .applicant-avatar { width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,var(--maroon),var(--maroon-light)); color:white; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; flex-shrink:0; }
    .applicant-search { width:100%; border:1.5px solid var(--gray-200); border-radius:14px; padding:11px 14px; font-size:13.5px; font-family:inherit; margin-bottom:10px; background:white; }
    .applicant-search:focus { outline:none; border-color:var(--maroon); }
    .qualify-card { background:white; border-radius:16px; box-shadow:var(--shadow-xs); margin-bottom:16px; overflow:hidden; }
  </style>
</head>
<body class="app-shell">

  <header class="app-bar maroon">
    <a href="admin_dashboard.php" class="app-bar-back">←</a>
    <div style="flex:1;min-width:0;">
      <div class="app-bar-title">Documents</div>
      <div class="app-bar-sub"><?= $cnt_pending ?> docs · <?= $cnt_pending_apps ?> apps awaiting</div>
    </div>
    <a href="admin_notifications.php" class="app-bar-action" title="Notifications">
      🔔
      <?php if ($unreadNotifs > 0): ?><span class="badge-dot"><?= min($unreadNotifs,9) ?></span><?php endif; ?>
    </a>
  </header>

  <main class="app-screen">

    <?php if ($mtext): ?>
    <div class="app-card" style="background:<?= $mtype==='success'?'#d1fae5':'#fee2e2' ?>;color:<?= $mtype==='success'?'#047857':'#b91c1c' ?>;font-size:12.5px;font-weight:600;display:flex;gap:8px;align-items:center;">
      <?= $mtype==='success'?'✅':'❌' ?> <?= htmlspecialchars($mtext) ?>
    </div>
    <?php endif; ?>

    <?php if ($cnt_new_docs > 0): ?>
    <div class="app-card" style="background:#fffbeb;border:1.5px solid #f59e0b;font-size:12.5px;color:#b45309;font-weight:700;">
      🆕 <?= $cnt_new_docs ?> new document<?= $cnt_new_docs>1?'s':'' ?> submitted in the last 24h
    </div>
    <?php endif; ?>

    <?php if ($cnt_new_apps > 0): ?>
    <a href="?tab=applications" class="app-card" style="display:block;background:#fff1f2;border:1.5px solid var(--maroon);font-size:12.5px;color:var(--maroon);font-weight:800;">
      🔔 <?= $cnt_new_apps ?> new applicant<?= $cnt_new_apps>1?'s':'' ?> in the last 24h — tap to review →
    </a>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stat-chip-row">
      <a href="?tab=docs&filter=pending" class="stat-chip" style="<?= ($filter==='pending'&&$tab==='docs')?'border:1.5px solid #f59e0b;':'' ?>">
        <div class="stat-chip-val" style="color:#f59e0b;"><?= $cnt_pending ?></div><div class="stat-chip-label">Pending Docs</div>
      </a>
      <a href="?tab=docs&filter=verified" class="stat-chip" style="<?= ($filter==='verified'&&$tab==='docs')?'border:1.5px solid #10b981;':'' ?>">
        <div class="stat-chip-val" style="color:#10b981;"><?= $cnt_verified ?></div><div class="stat-chip-label">Verified</div>
      </a>
      <a href="?tab=docs&filter=rejected" class="stat-chip" style="<?= ($filter==='rejected'&&$tab==='docs')?'border:1.5px solid #ef4444;':'' ?>">
        <div class="stat-chip-val" style="color:#ef4444;"><?= $cnt_rejected ?></div><div class="stat-chip-label">Rejected</div>
      </a>
      <a href="?tab=applications" class="stat-chip" style="<?= $tab==='applications'?'border:1.5px solid var(--maroon);':'' ?>">
        <div class="stat-chip-val" style="color:var(--maroon);"><?= $cnt_pending_apps ?></div><div class="stat-chip-label">Awaiting Decision</div>
      </a>
    </div>

    <!-- Main Tabs -->
    <div class="seg-tabs" id="mainTabs">
      <button class="seg-tab <?= $tab==='docs'?'active':'' ?>" onclick="switchQTab('docs', this)" type="button">
        📁 All Documents (<?= $cnt_total_docs ?>)
      </button>
      <button class="seg-tab <?= $tab==='applications'?'active':'' ?>" onclick="switchQTab('applications', this)" type="button">
        ⚖️ Qualify<?= $cnt_pending_apps>0?' ('.$cnt_pending_apps.')':'' ?>
      </button>
    </div>

    <!-- ══════════ TAB 1 — ALL DOCUMENTS ══════════ -->
    <div class="qtab-content <?= $tab==='docs'?'active':'' ?>" id="qtab-docs">

      <div class="seg-tabs">
        <a href="?tab=docs&filter=all"      class="seg-tab <?= $filter==='all'?'active':'' ?>">All (<?= $cnt_total_docs ?>)</a>
        <a href="?tab=docs&filter=pending"  class="seg-tab <?= $filter==='pending'?'active':'' ?>">Pending (<?= $cnt_pending ?>)</a>
        <a href="?tab=docs&filter=verified" class="seg-tab <?= $filter==='verified'?'active':'' ?>">Verified (<?= $cnt_verified ?>)</a>
        <a href="?tab=docs&filter=rejected" class="seg-tab <?= $filter==='rejected'?'active':'' ?>">Rejected (<?= $cnt_rejected ?>)</a>
      </div>

      <div class="app-card-flat">
        <?php if ($docs->num_rows === 0): ?>
          <div class="app-empty">
            <div class="app-empty-icon">📭</div>
            <div class="app-empty-title">No documents found<?= $filter!=='all' ? ' for this filter' : '' ?></div>
          </div>
        <?php else: while ($row = $docs->fetch_assoc()):
          $is_new = ($row['hours_ago'] <= 24);
          $sIcon = ['pending'=>'⏳','verified'=>'✅','rejected'=>'❌'][$row['status']] ?? 'ℹ️';
          $sBg   = ['pending'=>'#fef3c7','verified'=>'#dcfce7','rejected'=>'#fee2e2'][$row['status']] ?? '#f5f5f5';
        ?>
        <a href="javascript:void(0)" class="app-list-item" onclick='viewDocRow(<?= json_encode($row, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
          <div class="app-list-icon" style="background:<?= $sBg ?>;"><?= $sIcon ?></div>
          <div class="app-list-body">
            <div class="app-list-title"><?= htmlspecialchars($row['doc_type']) ?><?= $is_new && $row['status']==='pending' ? ' <span style="color:var(--maroon);font-size:9.5px;font-weight:800;">NEW</span>' : '' ?></div>
            <div class="app-list-sub"><?= htmlspecialchars($row['full_name']) ?><?= $row['loan_app_id'] ? ' · '.htmlspecialchars($row['loan_app_id']) : '' ?> · <?= date('M d, Y', strtotime($row['created_at'])) ?></div>
          </div>
          <div class="app-list-end">
            <span class="pill <?= $row['status'] ?>"><?= ucfirst($row['status']) ?></span>
          </div>
        </a>
        <?php endwhile; endif; ?>
      </div>
    </div>

    <!-- ══════════ TAB 2 — QUALIFY APPLICANTS ══════════ -->
    <div class="qtab-content <?= $tab==='applications'?'active':'' ?>" id="qtab-applications">
      <?php if (count($apps_arr) === 0): ?>
        <div class="app-empty" style="padding:50px 20px;">
          <div class="app-empty-icon">⚖️</div>
          <div class="app-empty-title">No pending applications</div>
          <div class="app-empty-sub">All applications have been reviewed.</div>
        </div>
      <?php else: ?>

      <!-- ── Applicant list (pick a name to open ONLY that person's documents) ── -->
      <div id="applicantList">
        <input type="search" id="applicantSearch" class="applicant-search" placeholder="🔍 Search applicant name or APP ID…" oninput="filterApplicants()" autocomplete="off"/>
        <div class="app-card-flat" id="applicantRows">
          <?php foreach ($apps_arr as $ap):
            $ini = strtoupper(substr($ap['full_name'],0,1) . (strpos($ap['full_name'],' ') ? substr($ap['full_name'], strpos($ap['full_name'],' ')+1, 1) : ''));
            $ready = ($ap['doc_count']>0 && $ap['verified_count']==$ap['doc_count']);
          ?>
          <div class="applicant-row" data-search="<?= htmlspecialchars(strtolower($ap['full_name'].' '.$ap['app_id'])) ?>" onclick="openApplicant(<?= $ap['id'] ?>)">
            <div class="applicant-avatar"><?= $ini ?></div>
            <div style="flex:1;min-width:0;">
              <div style="font-size:13.5px;font-weight:700;"><?= htmlspecialchars($ap['full_name']) ?>
                <?php if ($ap['hours_ago'] <= 24): ?><span style="background:var(--maroon);color:white;font-size:9px;font-weight:800;padding:1px 6px;border-radius:100px;margin-left:4px;">🆕 NEW</span><?php endif; ?>
              </div>
              <div style="font-size:11.5px;color:var(--gray-400);margin-top:2px;"><?= htmlspecialchars($ap['app_id']) ?> · ₱<?= number_format($ap['loan_amount'],0) ?> · <?= date('M d', strtotime($ap['created_at'])) ?></div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
              <?php if ($ready): ?>
                <span style="background:#dcfce7;color:#16a34a;font-size:10.5px;font-weight:800;padding:2px 9px;border-radius:100px;">✅ Ready</span>
              <?php else: ?>
                <span style="background:#fef3c7;color:#d97706;font-size:10.5px;font-weight:800;padding:2px 9px;border-radius:100px;"><?= (int)$ap['verified_count'] ?>/<?= (int)$ap['doc_count'] ?> verified</span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
          <div id="applicantNoMatch" style="display:none;padding:26px;text-align:center;color:var(--gray-400);font-size:12.5px;">No applicant matches your search.</div>
        </div>
      </div>

      <!-- ── Selected applicant's documents ── -->
      <?php
        foreach ($apps_arr as $app):
          $app_docs_temp = $conn->query("SELECT * FROM documents WHERE application_id={$app['id']} ORDER BY created_at ASC");
          $doc_list = []; while($dt = $app_docs_temp->fetch_assoc()) $doc_list[] = $dt;
          $selfie_doc = null; $selfie_id_doc = null; $govid_doc = null;
          foreach($doc_list as $dt) {
              $t = strtolower($dt['doc_type']);
              if (!$selfie_id_doc && strpos($t,'selfie with id')!==false) $selfie_id_doc=$dt;
              elseif (!$selfie_doc && strpos($t,'selfie')!==false) $selfie_doc=$dt;
              if (!$govid_doc  && (strpos($t,'government id')!==false || strpos($t,'id card')!==false)) $govid_doc=$dt;
          }
          $all_verified = ($app['doc_count'] > 0 && $app['verified_count'] == $app['doc_count'] && $app['pending_count'] == 0);
          $can_qualify  = $all_verified;  // Qualified stays DISABLED until every document is verified
          $has_rejected = ($app['rejected_count'] > 0);
          $no_docs      = ($app['doc_count'] == 0);
          $is_new_app   = ($app['hours_ago'] <= 24);
      ?>
      <div class="qualify-card applicant-detail" id="app-<?= $app['id'] ?>" style="display:none;<?= $is_new_app ? 'border:2px solid #f59e0b;' : '' ?>">
        <div style="padding:10px 12px;border-bottom:1px solid var(--gray-100);">
          <button type="button" class="app-btn app-btn-secondary app-btn-sm" onclick="closeApplicant()">← All applicants</button>
        </div>
        <div style="padding:16px;background:<?= $all_verified?'linear-gradient(135deg,#f0fdf4,#dcfce7)':($has_rejected?'linear-gradient(135deg,#fff1f2,#fee2e2)':($no_docs?'var(--gray-50)':'linear-gradient(135deg,#fffbeb,#fef3c7)')) ?>;">
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span style="font-size:15px;font-weight:800;color:var(--maroon);"><?= htmlspecialchars($app['app_id']) ?></span>
            <?php if ($is_new_app): ?><span style="background:var(--maroon);color:white;font-size:9.5px;font-weight:800;padding:2px 7px;border-radius:100px;">🆕 NEW</span><?php endif; ?>
          </div>
          <div style="margin-top:4px;font-size:13.5px;font-weight:700;"><?= htmlspecialchars($app['full_name']) ?></div>
          <div style="font-size:11.5px;color:var(--gray-400);margin-top:1px;"><?= htmlspecialchars($app['email']) ?> · Applied <?= date('M d, Y', strtotime($app['created_at'])) ?></div>
          <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:10px;">
            <div style="display:flex;gap:5px;flex-wrap:wrap;">
              <?php if ($no_docs): ?><span style="background:white;color:var(--gray-400);padding:2px 9px;border-radius:100px;font-size:10.5px;font-weight:700;">📭 No docs</span><?php endif; ?>
              <?php if ($app['verified_count']>0): ?><span style="background:#dcfce7;color:#16a34a;padding:2px 9px;border-radius:100px;font-size:10.5px;font-weight:700;">✅ <?= $app['verified_count'] ?></span><?php endif; ?>
              <?php if ($app['pending_count']>0): ?><span style="background:#fef3c7;color:#d97706;padding:2px 9px;border-radius:100px;font-size:10.5px;font-weight:700;">⏳ <?= $app['pending_count'] ?></span><?php endif; ?>
              <?php if ($app['rejected_count']>0): ?><span style="background:#fee2e2;color:#dc2626;padding:2px 9px;border-radius:100px;font-size:10.5px;font-weight:700;">❌ <?= $app['rejected_count'] ?></span><?php endif; ?>
            </div>
            <div style="text-align:right;">
              <div style="font-size:17px;font-weight:800;color:var(--maroon);">₱<?= number_format($app['loan_amount'],0) ?></div>
              <div style="font-size:10.5px;color:var(--gray-400);"><?= $app['loan_term'] ?>mo · <?= $app['interest_rate'] ?>%</div>
            </div>
          </div>
        </div>

        <div style="padding:16px;">
          <?php if ($selfie_doc || $selfie_id_doc || $govid_doc): ?>
          <div style="font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--maroon);margin-bottom:8px;">🤳 Selfie &amp; ID Verification</div>
          <div class="doc-thumb-grid" style="margin-bottom:16px;grid-template-columns:1fr;">
            <?php foreach([['📸 Selfie',$selfie_doc],['🤳🪪 Selfie+ID',$selfie_id_doc],['🪪 Gov ID',$govid_doc]] as [$lbl,$dd]):
              if(!$dd): ?>
              <div style="border:2px dashed var(--gray-200);border-radius:12px;padding:18px;text-align:center;color:var(--gray-400);font-size:11px;">📎 <?= $lbl ?><br>not submitted</div>
              <?php else: echo renderDocCard($dd, $lbl, 'applications'); endif; endforeach; ?>
          </div>
          <?php endif; ?>

          <?php
            $shown_ids = array_filter([$selfie_doc['id'] ?? null, $selfie_id_doc['id'] ?? null, $govid_doc['id'] ?? null]);
            $support_docs = array_values(array_filter($doc_list, fn($d) => !in_array($d['id'], $shown_ids)));
          ?>
          <?php if (count($support_docs) > 0): ?>
          <div style="font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--maroon);margin-bottom:8px;">📎 Supporting Documents (<?= count($support_docs) ?>)</div>
          <div class="doc-thumb-grid" style="grid-template-columns:1fr;margin-bottom:16px;">
            <?php foreach ($support_docs as $d) echo renderDocCard($d, $d['doc_type'], 'applications'); ?>
          </div>
          <?php elseif (count($doc_list) === 0): ?>
          <div style="padding:20px;text-align:center;border:2px dashed var(--gray-100);border-radius:12px;color:var(--gray-400);font-size:12.5px;margin-bottom:16px;">📭 No documents submitted yet.</div>
          <?php endif; ?>

          <div style="border-top:2px solid var(--gray-100);padding-top:16px;">
            <div style="font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-400);margin-bottom:10px;">⚖️ Final Decision</div>
            <?php if ($all_verified): ?>
              <div style="padding:9px 14px;background:#dcfce7;border-radius:10px;font-size:12px;color:#15803d;margin-bottom:12px;font-weight:700;">✅ All documents verified — ready to approve.</div>
            <?php elseif ($has_rejected): ?>
              <div style="padding:9px 14px;background:#fee2e2;border-radius:10px;font-size:12px;color:#dc2626;margin-bottom:12px;font-weight:700;">❌ Some documents were rejected. Re-verify them, or mark the application Not Qualified.</div>
            <?php elseif ($app['pending_count'] > 0): ?>
              <div style="padding:9px 14px;background:#fef3c7;border-radius:10px;font-size:12px;color:#92400e;margin-bottom:12px;font-weight:700;">⏳ <?= $app['pending_count'] ?> document<?= $app['pending_count']>1?'s':'' ?> still pending review. Check &amp; verify every document to enable <strong>Qualified</strong>.</div>
            <?php elseif ($no_docs): ?>
              <div style="padding:9px 14px;background:var(--gray-50);border-radius:10px;font-size:12px;color:var(--gray-400);margin-bottom:12px;font-weight:700;">📭 No documents to verify — Qualified is disabled.</div>
            <?php endif; ?>
            <div class="app-btn-row-2">
              <button class="app-btn" style="background:#dc2626;color:white;border:none;"
                onclick="qualify(<?= $app['id'] ?>,'rejected','<?= htmlspecialchars(addslashes($app['app_id'])) ?>','<?= htmlspecialchars(addslashes($app['full_name'])) ?>','<?= number_format($app['loan_amount'],2) ?>')">
                ❌ Not Qualified
              </button>
              <button class="app-btn" style="background:#16a34a;color:white;border:none;" <?= $can_qualify ? '' : 'disabled title="Verify all documents first"' ?>
                onclick="qualify(<?= $app['id'] ?>,'approved','<?= htmlspecialchars(addslashes($app['app_id'])) ?>','<?= htmlspecialchars(addslashes($app['full_name'])) ?>','<?= number_format($app['loan_amount'],2) ?>')">
                ✅ Qualified
              </button>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>

  </main>

  <button class="app-fab" onclick="openSheet('uploadSheet')">＋</button>
  <?php include 'admin_tabbar.php'; ?>

  <!-- ═══ UPLOAD SHEET ═══ -->
  <div class="sheet-overlay" id="ov_uploadSheet" onclick="closeSheet('uploadSheet')"></div>
  <div class="bottom-sheet" id="uploadSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">📤 Upload Document</div>
      <button class="sheet-close" onclick="closeSheet('uploadSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload"/>
        <input type="hidden" name="_tab" value="docs"/>
        <div class="app-form-group">
          <label class="app-form-label">Customer *</label>
          <select name="customer_id" class="app-select" required>
            <option value="">Select customer…</option>
            <?php $customers_list->data_seek(0); while($c=$customers_list->fetch_assoc()): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Linked Application <span style="color:var(--gray-400);font-size:10.5px;">(optional)</span></label>
          <select name="application_id" class="app-select">
            <option value="">None</option>
            <?php $apps_list->data_seek(0); while($a=$apps_list->fetch_assoc()): ?>
              <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['app_id']) ?> — <?= htmlspecialchars($a['full_name']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Document Type *</label>
          <select name="doc_type" class="app-select" required>
            <option>Government ID</option><option>Proof of Income</option>
            <option>Proof of Billing</option><option>Business Permit</option>
            <option>Birth Certificate</option><option>Bank Statement</option><option>Other</option>
          </select>
        </div>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">File (PDF, JPG, PNG — max 5MB) *</label>
          <div class="file-drop-app" onclick="document.getElementById('fileInput').click()">
            <div style="font-size:30px;margin-bottom:6px;">📁</div>
            <div style="font-size:12.5px;color:var(--gray-400);" id="fileLabel">Tap to browse files</div>
            <input type="file" name="document" id="fileInput" accept=".pdf,.jpg,.jpeg,.png"
              onchange="document.getElementById('fileLabel').textContent = this.files[0]?.name || 'Tap to browse files'"/>
          </div>
        </div>
        <button type="submit" class="app-btn app-btn-primary" style="margin-top:16px;">📤 Upload Document</button>
      </form>
    </div>
  </div>

  <!-- ═══ VIEW FILE SHEET ═══ -->
  <div class="sheet-overlay" id="ov_previewSheet" onclick="closeSheet('previewSheet')"></div>
  <div class="bottom-sheet" id="previewSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div>
        <div class="sheet-title" id="viewDocTitle">Document Preview</div>
        <div id="viewDocMeta" style="font-size:11px;color:var(--gray-400);margin-top:2px;"></div>
      </div>
      <button class="sheet-close" onclick="closeSheet('previewSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <div id="viewDocContent" style="text-align:center;min-height:160px;"></div>
      <a id="viewDocLink" href="#" target="_blank" class="app-btn app-btn-secondary" style="margin-top:14px;">⬇ Open in New Tab</a>
    </div>
  </div>

  <!-- ═══ DOC VIEW (from list) SHEET ═══ -->
  <div class="sheet-overlay" id="ov_docRowSheet" onclick="closeSheet('docRowSheet')"></div>
  <div class="bottom-sheet" id="docRowSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div>
        <div class="sheet-title" id="dr_title"></div>
        <div id="dr_sub" style="font-size:11px;color:var(--gray-400);margin-top:2px;"></div>
      </div>
      <button class="sheet-close" onclick="closeSheet('docRowSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <div id="dr_content"></div>
      <div id="dr_actions" style="margin-top:16px;display:flex;flex-direction:column;gap:10px;"></div>
    </div>
  </div>

  <!-- ═══ VERIFY / REJECT STATUS SHEET ═══ -->
  <div class="sheet-overlay" id="ov_statusSheet" onclick="closeSheet('statusSheet')"></div>
  <div class="bottom-sheet" id="statusSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title" id="statusSheetTitle">Update Document Status</div>
      <button class="sheet-close" onclick="closeSheet('statusSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <form method="POST">
        <input type="hidden" name="action" value="update_status"/>
        <input type="hidden" name="doc_id" id="status_doc_id"/>
        <input type="hidden" name="status" id="status_value"/>
        <input type="hidden" name="_tab" id="status_tab" value="docs"/>
        <input type="hidden" name="_app" id="status_app" value=""/>
        <div id="statusSheetDesc" style="font-size:12.5px;color:var(--text-dark);margin-bottom:14px;padding:11px 14px;border-radius:10px;background:var(--gray-50);"></div>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Note to Customer <span style="color:var(--gray-400);font-size:10.5px;">(optional)</span></label>
          <textarea name="notes" class="app-textarea" rows="3" placeholder="e.g. 'Document is blurry, please re-upload a clearer copy'…"></textarea>
        </div>
        <button type="submit" id="statusSubmitBtn" class="app-btn app-btn-primary" style="margin-top:16px;">Confirm</button>
      </form>
    </div>
  </div>

  <!-- ═══ QUALIFY SHEET ═══ -->
  <div class="sheet-overlay" id="ov_qualifySheet" onclick="closeSheet('qualifySheet')"></div>
  <div class="bottom-sheet" id="qualifySheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title" id="qualifyTitle">Qualification Decision</div>
      <button class="sheet-close" onclick="closeSheet('qualifySheet')">✕</button>
    </div>
    <div class="sheet-body">
      <form method="POST">
        <input type="hidden" name="action" value="qualify_applicant"/>
        <input type="hidden" name="application_id" id="qualify_app_id"/>
        <input type="hidden" name="decision" id="qualify_decision"/>
        <input type="hidden" name="_tab" value="applications"/>

        <div id="qualifyBody" style="padding:13px 16px;border-radius:10px;margin-bottom:14px;font-size:12.5px;line-height:1.6;"></div>

        <div id="qualifyNotifPreview" style="margin-bottom:14px;padding:11px 14px;border-radius:10px;background:var(--gray-50);border:1px solid var(--gray-100);">
          <div style="font-size:10px;font-weight:800;color:var(--gray-400);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">📨 Customer will receive:</div>
          <div id="qualifyNotifText" style="font-size:11.5px;color:var(--text-dark);line-height:1.5;"></div>
        </div>

        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label" id="qualifyRemarksLabel">Remarks</label>
          <textarea name="remarks" id="qualify_remarks" class="app-textarea" rows="3" placeholder="Add a message for the applicant…" oninput="updateNotifPreview()"></textarea>
        </div>
        <button type="submit" id="qualifySubmitBtn" class="app-btn app-btn-primary" style="margin-top:16px;">Confirm</button>
      </form>
    </div>
  </div>

<script>
function openSheet(id) { document.getElementById('ov_'+id)?.classList.add('open'); document.getElementById(id)?.classList.add('open'); }
function closeSheet(id) { document.getElementById('ov_'+id)?.classList.remove('open'); document.getElementById(id)?.classList.remove('open'); }
function esc(s) { const d=document.createElement('div'); d.textContent=s||'—'; return d.innerHTML; }

function switchQTab(tab, btn) {
  document.querySelectorAll('.qtab-content').forEach(t => t.classList.remove('active'));
  document.getElementById('mainTabs').querySelectorAll('.seg-tab').forEach(b => b.classList.remove('active'));
  document.getElementById('qtab-' + tab).classList.add('active');
  btn.classList.add('active');
  history.replaceState(null, '', '?tab=' + tab);
}

// ── Applicant list → one person at a time ──
window._openApp = null;
function openApplicant(id) {
  const card = document.getElementById('app-' + id);
  if (!card) return;
  window._openApp = id;
  document.getElementById('applicantList').style.display = 'none';
  document.querySelectorAll('.applicant-detail').forEach(c => c.style.display = 'none');
  card.style.display = 'block';
  window.scrollTo(0, 0);
  document.querySelector('.app-screen')?.scrollTo(0, 0);
  history.replaceState(null, '', '?tab=applications&app=' + id);
}
function closeApplicant() {
  window._openApp = null;
  document.querySelectorAll('.applicant-detail').forEach(c => c.style.display = 'none');
  const list = document.getElementById('applicantList'); if (list) list.style.display = 'block';
  history.replaceState(null, '', '?tab=applications');
}
function filterApplicants() {
  const q = (document.getElementById('applicantSearch').value || '').trim().toLowerCase();
  let shown = 0;
  document.querySelectorAll('#applicantRows .applicant-row').forEach(r => {
    const ok = !q || r.dataset.search.includes(q);
    r.style.display = ok ? 'flex' : 'none';
    if (ok) shown++;
  });
  const nm = document.getElementById('applicantNoMatch'); if (nm) nm.style.display = shown ? 'none' : 'block';
}
// Re-open the applicant after a verify/reject so the admin keeps their place
(function(){ const a = new URLSearchParams(location.search).get('app'); if (a) openApplicant(parseInt(a,10)); })();

function viewDocFile(path, type, customer) {
  document.getElementById('viewDocTitle').textContent = type;
  document.getElementById('viewDocMeta').textContent  = 'Customer: ' + customer;
  document.getElementById('viewDocLink').href         = path;
  const ext = path.split('.').pop().toLowerCase();
  let html = '';
  if (['jpg','jpeg','png'].includes(ext)) {
    html = `<img src="${path}" style="max-width:100%;max-height:420px;object-fit:contain;border-radius:10px;" onerror="this.parentNode.innerHTML='<div style=padding:40px;color:#aaa>File not accessible.</div>'"/>`;
  } else if (ext === 'pdf') {
    html = `<iframe src="${path}" width="100%" height="400px" style="border:none;border-radius:10px;"></iframe>`;
  } else {
    html = `<div style="padding:40px;color:#aaa;">Preview not available.</div>`;
  }
  document.getElementById('viewDocContent').innerHTML = html;
  openSheet('previewSheet');
}

function viewDocRow(data) {
  document.getElementById('dr_title').textContent = data.doc_type;
  document.getElementById('dr_sub').innerHTML = `<span class="pill ${data.status}">${data.status.charAt(0).toUpperCase()+data.status.slice(1)}</span>`;
  const rows = [
    ['Doc ID',      esc(data.doc_id)],
    ['Customer',    esc(data.full_name)],
    ['Application', data.loan_app_id ? esc(data.loan_app_id) : '—'],
    ['Submitted',   new Date(data.created_at).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})],
  ];
  if (data.notes) rows.push(['Notes', esc(data.notes)]);
  document.getElementById('dr_content').innerHTML = rows.map(([l,v]) =>
    `<div class="view-row"><span class="view-label">${l}</span><span class="view-value">${v}</span></div>`).join('');

  let actions = '';
  if (data.file_name) actions += `<button class="app-btn app-btn-secondary" onclick="viewDocFile('${data.file_path}','${esc(data.doc_type)}','${esc(data.full_name)}')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>View File</button>`;
  if (data.status === 'pending') {
    actions += `<div class="app-btn-row-2">
      <button class="app-btn" style="background:#dc2626;color:white;border:none;" onclick="closeSheet('docRowSheet');updateDocStatus(${data.id},'rejected','${esc(data.doc_type)}','docs')">✕ Reject</button>
      <button class="app-btn" style="background:#16a34a;color:white;border:none;" onclick="closeSheet('docRowSheet');updateDocStatus(${data.id},'verified','${esc(data.doc_type)}','docs')">✓ Verify</button>
    </div>`;
  } else if (data.status === 'rejected') {
    actions += `<button class="app-btn app-btn-primary" style="background:#16a34a;" onclick="closeSheet('docRowSheet');updateDocStatus(${data.id},'verified','${esc(data.doc_type)}','docs')">✓ Re-verify</button>`;
  }
  document.getElementById('dr_actions').innerHTML = actions;
  openSheet('docRowSheet');
}

function updateDocStatus(docId, status, docType, tabCtx) {
  document.getElementById('status_doc_id').value = docId;
  document.getElementById('status_value').value  = status;
  document.getElementById('status_tab').value = tabCtx || 'docs';
  document.getElementById('status_app').value = (tabCtx === 'applications' && window._openApp) ? window._openApp : '';
  const isVerify = status === 'verified';
  document.getElementById('statusSheetTitle').textContent = isVerify ? '✅ Verify Document' : '❌ Reject Document';
  document.getElementById('statusSheetDesc').innerHTML =
    (isVerify
      ? `<strong style="color:#16a34a;">Marking as Verified:</strong> "${docType}" — customer gets a success notification.`
      : `<strong style="color:#dc2626;">Rejecting:</strong> "${docType}" — customer will be notified with your reason below.`);
  const btn = document.getElementById('statusSubmitBtn');
  btn.textContent = isVerify ? '✓ Confirm Verify' : '✕ Confirm Reject';
  btn.style.background = isVerify ? '#16a34a' : '#dc2626';
  openSheet('statusSheet');
}

let _qDecision = '', _qName = '', _qCode = '', _qAmt = '';

function qualify(appId, decision, appCode, fullName, amount) {
  _qDecision = decision; _qName = fullName; _qCode = appCode; _qAmt = amount;
  document.getElementById('qualify_app_id').value  = appId;
  document.getElementById('qualify_decision').value = decision;
  document.getElementById('qualify_remarks').value  = '';
  const isApprove = decision === 'approved';

  document.getElementById('qualifyTitle').textContent = isApprove ? '✅ Approve Application' : '❌ Reject Application';
  document.getElementById('qualifyBody').innerHTML =
    `<div style="background:${isApprove?'#dcfce7':'#fee2e2'};border-radius:8px;padding:10px 14px;">
      <strong>${fullName}</strong> · <span style="color:var(--maroon);font-weight:700;">${appCode}</span> · ₱${amount}<br>
      <span style="font-size:11.5px;color:${isApprove?'#15803d':'#dc2626'};">
        ${isApprove ? 'You are about to <strong>approve this loan</strong>. The customer will be notified and their loan activated.'
                    : 'You are about to <strong>reject this application</strong>. The customer will be notified with your reason.'}
      </span>
    </div>`;

  document.getElementById('qualifyRemarksLabel').textContent = isApprove ? 'Approval Note (optional)' : 'Reason for Rejection';
  const btn = document.getElementById('qualifySubmitBtn');
  btn.textContent = isApprove ? '✅ Confirm — Approve Loan' : '❌ Confirm — Reject Application';
  btn.style.background = isApprove ? '#16a34a' : '#dc2626';

  updateNotifPreview();
  openSheet('qualifySheet');
}

function updateNotifPreview() {
  const remarks  = document.getElementById('qualify_remarks').value.trim();
  const isApprove = _qDecision === 'approved';
  let preview = '';
  if (isApprove) {
    preview = `"🎉 Loan Approved! Congratulations ${_qName}! Your application ${_qCode} for ₱${_qAmt} has been approved. ${remarks || 'Welcome to Velveth Lending!'}"`;
  } else {
    const reason = remarks || 'Submitted documents did not meet our requirements.';
    preview = `"❌ Application Not Approved — Dear ${_qName}, your application ${_qCode} for ₱${_qAmt} was not approved. Reason: ${reason}"`;
  }
  document.getElementById('qualifyNotifText').textContent = preview;
}
</script>
  <script src="realtime.js"></script>
</body>
</html>
