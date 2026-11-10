<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'customer') {
    header("Location: customer_login.php"); exit;
}
$user_id   = (int)$_SESSION['user_id'];
$full_name = $_SESSION['fullname'] ?? '';

ensureLoanAppSignatureColumns($conn);

// Ensure upload directory exists with proper permissions
$upload_dir = 'uploads/documents/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// -- Fetch the customer's registered ID type (set at registration) ---------
// Older databases may not have this column yet -- check defensively, same
// pattern customer_register.php already uses.
$id_type = null;
$col_check = $conn->query("SHOW COLUMNS FROM users LIKE 'id_type'");
if ($col_check && $col_check->num_rows > 0) {
    $idres = $conn->query("SELECT id_type FROM users WHERE id=$user_id");
    if ($idres) $id_type = $idres->fetch_assoc()['id_type'] ?? null;
}
$id_ratio_range = ID_RATIO_RANGES[$id_type] ?? ID_RATIO_RANGES['_default'];

// Loan ceiling tiers -- the more supporting documents submitted, the higher
// the loan ceiling. Selfie / Selfie-with-ID / Government ID / Proof of
// Income / Proof of Billing are always required for ANY loan amount; each
// additional optional supporting document raises the ceiling. The stated
// monthly income is a SECOND, independent constraint -- the final max is
// the lower of the two, so a large claimed income alone (without
// supporting documents) can't unlock a higher ceiling, and vice versa.
const LOAN_MIN          = 1000;
const LOAN_BASE_MAX     = 100000;
const LOAN_STEP         = 200000;
const LOAN_HARD_MAX     = 500000;
const INCOME_MULTIPLIER = 5; // max loan = up to 5x stated monthly income

function computeLoanMax($optionalDocCount, $monthlyIncome = 0) {
    $docBasedMax = min(LOAN_HARD_MAX, LOAN_BASE_MAX + ($optionalDocCount * LOAN_STEP));
    if ($monthlyIncome <= 0) return $docBasedMax;
    $incomeBasedMax = max(LOAN_MIN, floor(($monthlyIncome * INCOME_MULTIPLIER) / 500) * 500);
    return max(LOAN_MIN, min($docBasedMax, $incomeBasedMax));
}

// ====================================================
//  POST HANDLER
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Hard block: cannot apply for a new loan while an existing loan is
    // still active (unpaid). Checked here server-side so this can't be
    // bypassed even if the UI block below were somehow skipped.
    $active_loan_check = $conn->query("SELECT id FROM loans WHERE customer_id=$user_id AND status='active' LIMIT 1")->fetch_assoc();
    if ($active_loan_check) {
        $_SESSION['apply_error'] = 'You have an active loan that is not yet fully paid. Please settle it before applying for another loan.';
        header("Location: customer_apply.php"); exit;
    }

    $amount  = (float)($_POST['loan_amount'] ?? 0);
    $term    = (int)($_POST['loan_term']    ?? 12);
    $purpose = trim($_POST['purpose'] ?? '');
    $rate    = 5.0;
    $monthly_income = (float)($_POST['monthly_income'] ?? 0);

    if ($monthly_income <= 0) {
        $_SESSION['apply_error'] = 'Please enter your monthly income -- it\'s used together with your documents to set your loan limit.';
        header("Location: customer_apply.php"); exit;
    }

    // How many OPTIONAL supporting documents were submitted? Combined with
    // stated income to determine the loan ceiling for this application
    // (server-authoritative -- never trust the client-computed value alone).
    $optional_doc_count = 0;
    foreach (['doc_extra_1'] as $optField) {
        if (isset($_FILES[$optField]) && $_FILES[$optField]['error'] !== UPLOAD_ERR_NO_FILE && !empty($_FILES[$optField]['name'])) {
            $optional_doc_count++;
        }
    }
    $loan_max = computeLoanMax($optional_doc_count, $monthly_income);

    $monthly = round(($amount + ($amount * $rate / 100)) / max(1, $term), 2);
    $total   = round($monthly * $term, 2);

    // Validate loan details
    if ($amount < LOAN_MIN || $amount > $loan_max) {
        $_SESSION['apply_error'] = "Please enter a loan amount between P" . number_format(LOAN_MIN) . " and P" . number_format($loan_max) . ". Your maximum is based on the supporting documents you submitted -- upload additional documents to raise it.";
        header("Location: customer_apply.php"); exit;
    }
    if (empty($purpose)) {
        $_SESSION['apply_error'] = 'Please select a purpose for your loan.';
        header("Location: customer_apply.php"); exit;
    }
    if (!in_array($term, [3,6,12,18,24,36,48,60])) {
        $_SESSION['apply_error'] = 'Please select a valid loan term.';
        header("Location: customer_apply.php"); exit;
    }

    // Validate required documents are present
    $required_docs = [
        'doc_selfie'          => 'Selfie',
        'doc_selfie_with_id'  => 'Selfie with ID',
        'doc_gov_id'          => 'Government ID',
        'doc_proof_income'    => 'Proof of Income',
        'doc_proof_billing'   => 'Proof of Billing',
        'doc_extra_1'         => (trim($_POST['doc_extra_1_type'] ?? '') ?: 'Additional Document'),
    ];
    $missing_docs = [];
    foreach ($required_docs as $field => $label) {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE || empty($_FILES[$field]['name'])) {
            $missing_docs[] = $label;
        }
    }
    if (!empty($missing_docs)) {
        $_SESSION['apply_error'] = 'Please upload all required documents: ' . implode(', ', $missing_docs) . '.';
        header("Location: customer_apply.php"); exit;
    }

    // Validate declaration checkbox
    if (!isset($_POST['declare'])) {
        $_SESSION['apply_error'] = 'Please accept the declaration before submitting.';
        header("Location: customer_apply.php"); exit;
    }

    // Validate promissory note e-signature -- must type their registered full
    // name exactly (case-insensitive, whitespace-trimmed) as a lightweight
    // security-verification / signing step before we accept the application.
    $signed_name = trim($_POST['esign'] ?? '');
    if ($signed_name === '' || strcasecmp($signed_name, trim($full_name)) !== 0) {
        $_SESSION['apply_error'] = 'Please type your full name exactly as registered ("' . $full_name . '") to sign the promissory note.';
        header("Location: customer_apply.php"); exit;
    }

    // -- Pre-validate ALL files (type, size, MIME) BEFORE creating the application --
    $all_doc_fields_check = [
        'doc_selfie'          => 'Selfie',
        'doc_selfie_with_id'  => 'Selfie with ID',
        'doc_gov_id'          => 'Government ID',
        'doc_proof_income'    => 'Proof of Income',
        'doc_proof_billing'   => 'Proof of Billing',
        'doc_extra_1'         => trim($_POST['doc_extra_1_type'] ?? 'Supporting Document'),
    ];
    // These three are identity-verification documents -- image quality
    // (resolution + blur) is checked, and the Government ID additionally
    // gets an aspect-ratio plausibility check against the customer's
    // registered ID type. See analyzeImageQuality() in config.php for what
    // this can and cannot actually detect.
    $identity_fields = ['doc_selfie', 'doc_selfie_with_id', 'doc_gov_id'];

    $pre_errors = [];
    foreach ($all_doc_fields_check as $field => $label) {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) continue;
        $f = $_FILES[$field];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $pre_errors[] = "$label: Upload error (code {$f['error']}).";
            continue;
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf','jpg','jpeg','png'])) {
            $pre_errors[] = "$label: Only PDF, JPG, PNG files are allowed.";
            continue;
        }
        if ($f['size'] > 10 * 1024 * 1024) {
            $pre_errors[] = "$label: File too large (max 10MB).";
            continue;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, ['application/pdf','image/jpeg','image/jpg','image/png'])) {
            $pre_errors[] = "$label: Invalid file content detected.";
            continue;
        }

        // Identity-document quality / legitimacy gate
        if (in_array($field, $identity_fields, true) && in_array($ext, ['jpg','jpeg','png'])) {
            $ratioRange = ($field === 'doc_gov_id') ? $id_ratio_range : null;
            $check = analyzeImageQuality($f['tmp_name'], $ratioRange);
            if (!$check['ok']) {
                $pre_errors[] = "$label: " . $check['reason'];
            }
        }
    }
    if (!empty($pre_errors)) {
        $_SESSION['apply_error'] = implode(' ', $pre_errors);
        header("Location: customer_apply.php"); exit;
    }

    // Generate unique APP-ID
    $result  = $conn->query("SELECT COUNT(*) AS c FROM loan_applications");
    $app_num = ($result ? (int)$result->fetch_assoc()['c'] : 0) + 1;
    $app_id  = 'APP-' . str_pad($app_num, 3, '0', STR_PAD_LEFT);

    // -- INSERT loan application ------------------
    $signed_at = date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        "INSERT INTO loan_applications
         (app_id, customer_id, loan_amount, loan_term, interest_rate, purpose, monthly_payment, total_payable, signed_name, signed_at, stated_monthly_income)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        $_SESSION['apply_error'] = 'Database prepare error: ' . $conn->error;
        header("Location: customer_apply.php"); exit;
    }
    $stmt->bind_param("sididsddssd", $app_id, $user_id, $amount, $term, $rate, $purpose, $monthly, $total, $signed_name, $signed_at, $monthly_income);

    if (!$stmt->execute()) {
        $_SESSION['apply_error'] = 'Failed to save application: ' . $stmt->error;
        $stmt->close();
        header("Location: customer_apply.php"); exit;
    }
    $new_app_db_id = (int)$conn->insert_id;
    $stmt->close();

    // -- PROCESS DOCUMENT UPLOADS -----------------
    $doc_fields = [
        'doc_selfie'          => 'Selfie',
        'doc_selfie_with_id'  => 'Selfie with ID',
        'doc_gov_id'          => 'Government ID',
        'doc_proof_income'    => 'Proof of Income',
        'doc_proof_billing'   => 'Proof of Billing',
        'doc_extra_1'         => trim($_POST['doc_extra_1_type'] ?? 'Supporting Document'),
    ];

    $uploaded      = 0;
    $upload_errors = [];

    foreach ($doc_fields as $field => $doc_type) {
        if (!isset($_FILES[$field])) continue;
        $file = $_FILES[$field];
        if ($file['error'] === UPLOAD_ERR_NO_FILE) continue;

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $upload_errors[] = "$doc_type: Upload failed (code {$file['error']})";
            continue;
        }

        // Validate extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf','jpg','jpeg','png'])) {
            $upload_errors[] = "$doc_type: Only PDF, JPG, PNG allowed.";
            continue;
        }

        // Validate size (10MB)
        if ($file['size'] > 10 * 1024 * 1024) {
            $upload_errors[] = "$doc_type: File too large (max 10MB).";
            continue;
        }

        // Validate it's actually an image/pdf (basic MIME check)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed_mimes = ['application/pdf','image/jpeg','image/jpg','image/png'];
        if (!in_array($mime, $allowed_mimes)) {
            $upload_errors[] = "$doc_type: Invalid file type detected.";
            continue;
        }

        // Generate unique DOC-ID and filename
        $doc_result = $conn->query("SELECT COUNT(*) AS c FROM documents");
        $doc_num    = ($doc_result ? (int)$doc_result->fetch_assoc()['c'] : 0) + 1;
        $doc_id     = 'DOC-' . str_pad($doc_num, 3, '0', STR_PAD_LEFT);
        $fname      = $doc_id . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        $fpath      = $upload_dir . $fname;

        if (!move_uploaded_file($file['tmp_name'], $fpath)) {
            $upload_errors[] = "$doc_type: Could not save file. Check folder permissions on 'uploads/documents/'.";
            continue;
        }

        // INSERT document record -- correct type: s=doc_id, i=customer_id, i=application_id, s=doc_type, s=file_name, s=file_path
        $dstmt = $conn->prepare(
            "INSERT INTO documents (doc_id, customer_id, application_id, doc_type, file_name, file_path, status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending')"
        );
        if ($dstmt) {
            $dstmt->bind_param("siisss", $doc_id, $user_id, $new_app_db_id, $doc_type, $fname, $fpath);
            if ($dstmt->execute()) {
                $uploaded++;
            } else {
                @unlink($fpath);
                $upload_errors[] = "$doc_type: DB insert failed -- " . $dstmt->error;
            }
            $dstmt->close();
        } else {
            @unlink($fpath);
            $upload_errors[] = "$doc_type: DB prepare failed -- " . $conn->error;
        }
    }

    // -- AUDIT LOG ---------------------------------
    $audit_msg = "$app_id -- $uploaded doc(s) uploaded, signed by \"$signed_name\"";
    if ($upload_errors) $audit_msg .= ' | Errors: ' . implode('; ', $upload_errors);
    logAudit($conn, 'Loan', $_SESSION['fullname'], 'Submitted loan application', $audit_msg);

    // -- NOTIFY ALL ADMINS -------------------------
    $cname    = mysqli_real_escape_string($conn, $_SESSION['fullname']);
    $cpurpose = mysqli_real_escape_string($conn, $purpose);
    $a_title  = "New Loan Application -- $app_id";
    $a_msg    = "$cname submitted $app_id for P" . number_format($amount, 2) . " ($cpurpose, {$term} mos). $uploaded doc(s) attached. Click to review.";
    $esc_atitle = mysqli_real_escape_string($conn, $a_title);
    $esc_amsg   = mysqli_real_escape_string($conn, $a_msg);

    $admins = $conn->query("SELECT id FROM users WHERE role='admin' AND status='active'");
    if ($admins) {
        while ($adm = $admins->fetch_assoc()) {
            $adm_id = (int)$adm['id'];
            $conn->query("INSERT INTO notifications (user_id, title, message, type) VALUES ($adm_id, '$esc_atitle', '$esc_amsg', 'info')");
        }
    }

    // -- NOTIFY CUSTOMER ---------------------------
    $doc_note = $uploaded > 0 ? "$uploaded document(s) submitted for review." : "No documents uploaded -- you can contact us to submit them separately.";
    $err_note = $upload_errors ? " Note: " . implode(', ', $upload_errors) : '';
    $c_title  = "Application $app_id Submitted";
    $c_msg    = "Your loan application $app_id for P" . number_format($amount, 2) . " ($purpose) has been received. $doc_note$err_note";
    $esc_ctitle = mysqli_real_escape_string($conn, $c_title);
    $esc_cmsg   = mysqli_real_escape_string($conn, $c_msg);
    $conn->query("INSERT INTO notifications (user_id, title, message, type) VALUES ($user_id, '$esc_ctitle', '$esc_cmsg', 'success')");

    // -- REDIRECT -----------------------------------
    header("Location: customer_loan.php?new_app=" . urlencode($app_id));
    exit;
}

// -- GET --------------------------------------------
$error_msg = '';
if (isset($_SESSION['apply_error'])) {
    $error_msg = $_SESSION['apply_error'];
    unset($_SESSION['apply_error']);
}

$existing = $conn->query("SELECT app_id FROM loan_applications WHERE customer_id=$user_id AND status='pending' LIMIT 1")->fetch_assoc();
$active_loan = $conn->query("
    SELECT l.*, la.app_id FROM loans l
    JOIN loan_applications la ON l.application_id = la.id
    WHERE l.customer_id=$user_id AND l.status='active' LIMIT 1
")->fetch_assoc();
$_cur = 'customer_apply.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Apply for Loan | Velveth</title>
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
    /* Upload zones -- mobile */
    .upload-zone {
      border:2px dashed var(--gray-200); border-radius:14px; padding:18px 10px;
      text-align:center; background:var(--gray-50); transition:all 0.2s; cursor:pointer;
    }
    .upload-zone.has-file { border-color:#22c55e; background:#f0fdf4; border-style:solid; }
    .upload-zone.has-error { border-color:#ef4444; background:#fef2f2; border-style:solid; }
    .upload-zone.dragover { border-color:var(--maroon); background:var(--maroon-pale); border-style:solid; }
    .upload-zone label { cursor:pointer; display:block; }
    .upload-zone input[type=file] { display:none; }
    .zone-icon { font-size:26px; margin-bottom:6px; display:block; }
    .zone-text { font-size:12px; color:var(--text-medium); }
    .zone-text strong { color:var(--maroon); }
    .zone-hint { font-size:10px; color:var(--gray-400); margin-top:3px; line-height:1.4; }
    .zone-preview {
      margin-top:8px; padding:6px 10px; background:#dcfce7;
      border:1px solid #86efac; border-radius:8px; font-size:11px;
      color:#16a34a; display:none; text-align:left; word-break:break-all;
    }
    .zone-error-msg {
      margin-top:8px; padding:6px 10px; background:#fee2e2;
      border:1px solid #fca5a5; border-radius:8px; font-size:10.5px;
      color:#b91c1c; display:none; text-align:left; line-height:1.5;
    }
    .req-badge { background:#fee2e2; color:#dc2626; font-size:9.5px; font-weight:700; padding:1px 7px; border-radius:100px; margin-left:4px; }
    .opt-badge { background:#eff6ff; color:#2563eb; font-size:9.5px; font-weight:700; padding:1px 7px; border-radius:100px; margin-left:4px; }
    .verify-badge { background:#ede9fe; color:#6d28d9; font-size:9.5px; font-weight:700; padding:1px 7px; border-radius:100px; margin-left:4px; }

    /* Review rows */
    .rev-row { display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--gray-100); }
    .rev-row:last-child { border-bottom:none; }
    .rev-lbl { color:var(--gray-400); font-size:11px; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; }
    .rev-val { font-weight:800; color:var(--text-dark); font-size:13px; }

    .doc-pill { display:inline-flex; align-items:center; gap:5px; margin:3px; padding:5px 12px; border-radius:100px; font-size:11.5px; font-weight:700; }
    .doc-pill.ok      { background:#f0fdf4; border:1px solid #86efac; color:#16a34a; }
    .doc-pill.missing { background:#fff8e1; border:1px solid #fcd34d; color:#92400e; }

    .step-panel { display:none; }
    .step-panel.active { display:block; animation: appFadeIn .25s ease; }

    #stepErrBanner { background:#fee2e2;color:#b91c1c;padding:10px 14px;border-radius:10px;font-size:12.5px;margin-bottom:14px;font-weight:600; }

    .tier-banner {
      background:linear-gradient(135deg,#ede9fe,#f5f3ff); border:1px solid #ddd6fe;
      border-radius:14px; padding:14px 16px; margin-bottom:14px;
    }
    .tier-banner .tier-max { font-size:22px;font-weight:900;color:#6d28d9; }
    .tier-banner .tier-hint { font-size:11px;color:#5b21b6;line-height:1.5;margin-top:4px; }
    .loanable-bar-track { width:100%; height:14px; background:#e9d5ff; border-radius:100px; margin-top:14px; overflow:hidden; }
    .loanable-bar-fill { height:100%; border-radius:100px; background:linear-gradient(90deg,#8b5cf6,#6d28d9); width:0%; transition:width .25s ease; }
    .loanable-bar-labels { display:flex; justify-content:space-between; font-size:10px; color:#6d28d9; font-weight:700; margin-top:6px; }

    .promissory-box {
      max-height:220px; overflow-y:auto; font-size:11.5px; line-height:1.7;
      color:var(--text-medium); background:var(--gray-50); border:1px solid var(--gray-200);
      border-radius:12px; padding:14px 16px;
    }
  </style>
</head>
<body class="app-shell">

  <!-- App Bar -->
  <header class="app-bar maroon">
    <a href="customer_dashboard.php" class="app-bar-back">←</a>
    <div style="flex:1;min-width:0;">
      <div class="app-bar-title">Apply for Loan</div>
      <div class="app-bar-sub">3 quick steps to apply</div>
    </div>
  </header>

  <main class="app-screen">

    <?php if ($active_loan): ?>
    <!-- ═══ BLOCKED: existing active loan not yet fully paid ═══ -->
    <div class="app-card" style="text-align:center;padding:32px 24px;">
      <div style="font-size:44px;margin-bottom:14px;">🚫</div>
      <h2 style="font-size:18px;font-weight:800;color:var(--text-dark);margin-bottom:8px;">You Have an Active Loan</h2>
      <p style="font-size:13px;color:var(--text-medium);line-height:1.6;margin-bottom:20px;">
        You can't apply for a new loan while <strong><?= htmlspecialchars($active_loan['app_id']) ?></strong> is still unpaid.
        Please finish paying it off first.
      </p>

      <div class="app-card-flat" style="text-align:left;margin-bottom:20px;">
        <div class="rev-row"><span class="rev-lbl">Loan Amount</span><span class="rev-val">₱<?= number_format($active_loan['loan_amount'],2) ?></span></div>
        <div class="rev-row"><span class="rev-lbl">Amount Paid</span><span class="rev-val" style="color:#16a34a;">₱<?= number_format($active_loan['amount_paid'],2) ?></span></div>
        <div class="rev-row"><span class="rev-lbl">Outstanding Balance</span><span class="rev-val" style="color:var(--maroon);">₱<?= number_format($active_loan['outstanding_balance'],2) ?></span></div>
        <?php if ($active_loan['next_payment_date']): ?>
        <div class="rev-row"><span class="rev-lbl">Next Payment Due</span><span class="rev-val"><?= date('M j, Y', strtotime($active_loan['next_payment_date'])) ?></span></div>
        <?php endif; ?>
      </div>

      <a href="customer_loan.php" class="app-btn app-btn-primary" style="display:block;">View &amp; Pay My Loan →</a>
      <a href="customer_dashboard.php" class="app-btn app-btn-secondary" style="display:block;margin-top:10px;">← Back to Home</a>
    </div>

    <?php else: ?>

    <?php if ($error_msg): ?>
    <div class="app-card" style="background:#fee2e2;color:#b91c1c;font-size:12.5px;font-weight:600;display:flex;gap:10px;align-items:center;">
      <span style="font-size:18px;">X</span><?= htmlspecialchars($error_msg) ?>
    </div>
    <?php endif; ?>

    <?php if ($existing): ?>
    <div class="app-card" style="background:#fff8e1;color:#92400e;font-size:12.5px;">
      Note: You already have a pending application: <strong><?= htmlspecialchars($existing['app_id']) ?></strong>.
      You can still submit a new one.
      <a href="customer_loan.php" style="color:var(--maroon);font-weight:700;display:block;margin-top:6px;">View Status &rarr;</a>
    </div>
    <?php endif; ?>

    <!-- App Stepper -->
    <div class="app-stepper" id="stepper">
      <div class="app-step active" id="si-1">
        <div class="app-step-circle">1</div>
        <div class="app-step-label">Promissory<br>Note</div>
      </div>
      <div class="app-step" id="si-2">
        <div class="app-step-circle">2</div>
        <div class="app-step-label">Verify<br>Identity</div>
      </div>
      <div class="app-step" id="si-3">
        <div class="app-step-circle">3</div>
        <div class="app-step-label">Loan<br>Details</div>
      </div>
      <div class="app-step" id="si-4">
        <div class="app-step-circle">4</div>
        <div class="app-step-label">Review &amp;<br>Submit</div>
      </div>
    </div>

    <!-- THE FORM -->
    <form method="POST" enctype="multipart/form-data" id="applyForm">

      <!-- ========== STEP 1 -- Promissory Note & Signature ========== -->
      <div class="step-panel active" id="step-1">

        <div class="app-card-flat" style="background:#fef9e7;padding:12px 14px;font-size:12px;color:#92400e;line-height:1.6;margin-bottom:14px;">
          Please read and sign the Promissory Note below. You must agree to these terms before you can continue
          with your loan application.
        </div>

        <div class="app-section-title">📜 Promissory Note</div>
        <div class="promissory-box">
          <p style="text-align:center;font-weight:800;color:var(--text-dark);margin-bottom:10px;letter-spacing:.5px;">PROMISSORY NOTE</p>

          <p>FOR VALUE RECEIVED, I, <strong><?= htmlspecialchars($full_name) ?></strong>, of legal age, Filipino, and a
          resident of the address on file with Velveth Lending Company, do hereby promise to pay
          <strong>VELVETH LENDING COMPANY</strong>, with business address at 4th Floor, Door 5, Leope Building,
          C.M. Recto St. corner Palma Gil St., Davao City, Philippines, or its order, the principal amount, interest,
          and installment schedule I select and confirm later in this application, subject to the following terms:</p>

          <p style="margin-top:10px;">
          1. The loan shall be payable according to the term and monthly installment I select and confirm, together
          with the applicable interest rate disclosed to me before I submit this application.<br><br>
          2. In case of failure to pay any installment when due, the unpaid installment shall earn additional interest
          as liquidated damages, as disclosed in Velveth Lending's penalty policy, until fully paid.<br><br>
          3. If the principal amount, or any portion of it, is not fully paid on its due date, the remaining balance
          shall earn an additional daily charge, as disclosed in Velveth Lending's penalty policy, until fully paid.<br><br>
          4. I further bind myself to pay an additional collection fee of ten percent (10%) in case of extrajudicial
          collection, or twenty-five percent (25%) in case of judicial collection, based on the remaining principal
          obligation, by way of attorney's fees.<br><br>
          5. In case of litigation arising from or in connection with this note, venue shall be at the proper courts
          of Davao City, to the exclusion of all other courts.<br><br>
          6. To secure payment of this note, I agree that Velveth Lending Company may require supporting documents
          and, where applicable, collateral related to my loan application.
          </p>

          <p style="margin-top:10px;">I certify that the information and documents I will submit in this application
          are true and accurate, and that I am the person named above. I understand that typing my full name below
          and checking the acknowledgment box constitutes my digital signature on this Promissory Note, with the
          same legal effect as a handwritten signature.</p>
        </div>

        <div class="app-card">
          <label class="app-form-label">Type your full name to sign <span class="req-badge">Required</span></label>
          <input type="text" name="esign" id="esign" class="app-input" placeholder="<?= htmlspecialchars($full_name) ?>" autocomplete="off"/>
          <div style="font-size:10.5px;color:var(--gray-400);margin-top:4px;">
            Must exactly match your registered name: <strong><?= htmlspecialchars($full_name) ?></strong>
          </div>
        </div>

        <div class="app-card">
          <div style="display:flex;align-items:flex-start;gap:12px;">
            <input type="checkbox" id="declare" name="declare"
                   style="width:20px;height:20px;margin-top:2px;accent-color:var(--maroon);cursor:pointer;flex-shrink:0;"/>
            <label for="declare" style="font-size:12.5px;color:var(--text-medium);line-height:1.7;cursor:pointer;">
              I have read and agree to the Promissory Note above, and I authorize Velveth Lending Company to verify
              my information and documents. I have read and agree to the
              <a href="terms.php" target="_blank" style="color:var(--maroon);font-weight:700;">Terms and Conditions</a>.
            </label>
          </div>
        </div>

        <button type="button" class="app-btn app-btn-primary" style="margin-top:6px;" onclick="goStep(2)">Next: Verify Identity &rarr;</button>
      </div>

      <!-- ========== STEP 2 -- Identity Verification & Documents ========== -->
      <div class="step-panel" id="step-2">

        <div class="app-card-flat" style="background:#fef9e7;padding:12px 14px;font-size:12px;color:#92400e;line-height:1.6;margin-bottom:14px;">
          Security Verification: upload clear, readable photos. We check every identity photo for
          resolution and clarity right here -- blurry or unclear photos will be rejected before you can continue.
        </div>

        <!-- Selfie + Selfie w/ ID + Gov ID -->
        <div class="app-card" style="background:var(--maroon-pale);border:1.5px solid var(--maroon-muted);">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
            <span style="font-size:16px;">🤳</span>
            <div>
              <div style="font-size:12.5px;font-weight:800;color:var(--maroon);">Identity Verification <span class="req-badge">Required</span></div>
              <div style="font-size:10.5px;color:var(--text-light);margin-top:1px;">
                Registered ID type: <strong><?= htmlspecialchars($id_type ?: 'Not set -- update it in your profile') ?></strong>
              </div>
            </div>
          </div>

          <div class="selfie-id-grid" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
            <!-- Selfie -->
            <div>
              <label class="app-form-label" style="color:var(--maroon);font-size:11px;">Selfie</label>
              <div class="upload-zone" id="zone-doc_selfie" style="border-color:var(--maroon-muted);padding:14px 6px;" onclick="openCamera('doc_selfie','user')">
                <span class="zone-icon" id="zicon-doc_selfie" style="font-size:22px;">📷</span>
                <div class="zone-text"><strong>Take Photo</strong></div>
                <div class="zone-hint">Live camera · face only</div>
                <input type="file" name="doc_selfie" id="doc_selfie" accept=".jpg,.jpeg,.png" style="display:none;"/>
                <div class="zone-preview" id="zprev-doc_selfie"></div>
                <div class="zone-error-msg" id="zerr-doc_selfie"></div>
              </div>
              <div style="text-align:center;margin-top:5px;">
                <a href="javascript:void(0)" onclick="event.stopPropagation();document.getElementById('doc_selfie_gallery').click()" style="font-size:9.5px;color:var(--gray-400);text-decoration:underline;">or choose from gallery</a>
                <input type="file" id="doc_selfie_gallery" accept=".jpg,.jpeg,.png" style="display:none;" onchange="galleryFileChosen(this,'doc_selfie')"/>
              </div>
            </div>

            <!-- Selfie with ID -->
            <div>
              <label class="app-form-label" style="color:var(--maroon);font-size:11px;">Selfie + ID</label>
              <div class="upload-zone" id="zone-doc_selfie_with_id" style="border-color:var(--maroon-muted);padding:14px 6px;" onclick="openCamera('doc_selfie_with_id','user')">
                <span class="zone-icon" id="zicon-doc_selfie_with_id" style="font-size:22px;">📷</span>
                <div class="zone-text"><strong>Take Photo</strong></div>
                <div class="zone-hint">Live camera · holding ID</div>
                <input type="file" name="doc_selfie_with_id" id="doc_selfie_with_id" accept=".jpg,.jpeg,.png" style="display:none;"/>
                <div class="zone-preview" id="zprev-doc_selfie_with_id"></div>
                <div class="zone-error-msg" id="zerr-doc_selfie_with_id"></div>
              </div>
              <div style="text-align:center;margin-top:5px;">
                <a href="javascript:void(0)" onclick="event.stopPropagation();document.getElementById('doc_selfie_with_id_gallery').click()" style="font-size:9.5px;color:var(--gray-400);text-decoration:underline;">or choose from gallery</a>
                <input type="file" id="doc_selfie_with_id_gallery" accept=".jpg,.jpeg,.png" style="display:none;" onchange="galleryFileChosen(this,'doc_selfie_with_id')"/>
              </div>
            </div>

            <!-- ID Card -->
            <div>
              <label class="app-form-label" style="color:var(--maroon);font-size:11px;">ID Card</label>
              <div class="upload-zone" id="zone-doc_gov_id"
                   style="border-color:var(--maroon-muted);padding:14px 6px;"
                   ondragover="zDragOver(event,this)" ondragleave="zDragLeave(this)"
                   ondrop="zDrop(event,'doc_gov_id')">
                <label for="doc_gov_id">
                  <span class="zone-icon" id="zicon-doc_gov_id" style="font-size:22px;">🪪</span>
                  <div class="zone-text"><strong>Upload</strong></div>
                  <div class="zone-hint">Straight photo</div>
                </label>
                <input type="file" name="doc_gov_id" id="doc_gov_id"
                       accept=".jpg,.jpeg,.png"
                       onchange="zFileChosen(this,'doc_gov_id')"/>
                <div class="zone-preview" id="zprev-doc_gov_id"></div>
                <div class="zone-error-msg" id="zerr-doc_gov_id"></div>
              </div>
              <div style="text-align:center;margin-top:5px;">
                <a href="javascript:void(0)" onclick="openCamera('doc_gov_id','environment')" style="font-size:9.5px;color:var(--gray-400);text-decoration:underline;">or take a photo instead</a>
              </div>
            </div>
          </div>

          <!-- Tip -->
          <div style="display:flex;align-items:center;gap:8px;margin-top:10px;padding:10px 12px;background:white;border-radius:10px;border:1px solid var(--maroon-muted);">
            <span style="font-size:18px;flex-shrink:0;">💡</span>
            <div style="font-size:11px;color:var(--text-medium);line-height:1.5;">
              <strong>Tip:</strong> Good lighting, no glare, and the whole ID card in frame. Selfies use your live camera with
              real-time face detection, and every photo is checked for clarity automatically before you can continue.
            </div>
          </div>
        </div>

        <!-- Monthly Income (drives your loan limit, live) -->
        <div class="app-card">
          <label class="app-form-label">Monthly Income (₱) <span class="req-badge">Required</span></label>
          <input type="number" name="monthly_income" id="monthlyIncome" class="app-input"
                 min="1" step="500" placeholder="e.g. 25,000" oninput="updateLiveLimitEstimate()"/>
          <div style="font-size:10.5px;color:var(--gray-400);margin-top:6px;line-height:1.5;">
            Used together with your submitted documents to set your maximum loan amount.
          </div>
          <div id="liveLimitEstimate" style="margin-top:10px;padding:10px 12px;background:#ede9fe;border-radius:10px;font-size:11.5px;color:#5b21b6;font-weight:700;display:none;"></div>
        </div>

        <!-- Proof of Income + Billing -->
        <div class="app-section-title" style="margin-top:6px;">Required Documents</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
          <?php
          $req = [
            ['doc_proof_income',  '💼', 'Income',  'Payslip, COE, ITR'],
            ['doc_proof_billing', '📄', 'Billing', 'Utility, bank stmt'],
          ];
          foreach ($req as [$fid, $icon, $label, $hint]): ?>
            <div>
              <label class="app-form-label">Proof of <?= $label ?> <span class="req-badge">Required</span></label>
              <div class="upload-zone" id="zone-<?= $fid ?>"
                   ondragover="zDragOver(event,this)" ondragleave="zDragLeave(this)"
                   ondrop="zDrop(event,'<?= $fid ?>')">
                <label for="<?= $fid ?>">
                  <span class="zone-icon" id="zicon-<?= $fid ?>"><?= $icon ?></span>
                  <div class="zone-text"><strong>Upload</strong></div>
                  <div class="zone-hint"><?= $hint ?></div>
                </label>
                <input type="file" name="<?= $fid ?>" id="<?= $fid ?>"
                       accept=".pdf,.jpg,.jpeg,.png"
                       onchange="zFileChosen(this,'<?= $fid ?>')"/>
                <div class="zone-preview" id="zprev-<?= $fid ?>"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Additional document (REQUIRED, one only) -->
        <div class="app-section-title">
          Additional Document <span class="req-badge">Required</span>
        </div>
        <div class="app-card-flat" style="background:#eff6ff;padding:11px 13px;font-size:11.5px;color:#1e40af;line-height:1.6;margin-bottom:10px;">
          Attach one additional supporting document. Pick the type that applies to you, then upload it.
        </div>
        <div style="margin-bottom:14px;">
          <?php
          $opt_types = ['Business Permit','Bank Statement','Birth Certificate','Marriage Certificate','Property Title','Other Supporting Document'];
          $fid = 'doc_extra_1'; $tname = 'doc_extra_1_type';
          ?>
          <div class="app-form-group" style="margin-bottom:6px;">
            <select name="<?= $tname ?>" id="<?= $tname ?>" class="app-select" style="font-size:12px;padding:10px 12px;">
              <?php foreach ($opt_types as $i => $t): ?><option <?= $i===0?'selected':'' ?>><?= $t ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="upload-zone" id="zone-<?= $fid ?>"
               ondragover="zDragOver(event,this)" ondragleave="zDragLeave(this)"
               ondrop="zDrop(event,'<?= $fid ?>')">
            <label for="<?= $fid ?>">
              <span class="zone-icon" id="zicon-<?= $fid ?>">📁</span>
              <div class="zone-text"><strong>Upload</strong></div>
              <div class="zone-hint">Required -- PDF, JPG or PNG</div>
            </label>
            <input type="file" name="<?= $fid ?>" id="<?= $fid ?>"
                   accept=".pdf,.jpg,.jpeg,.png"
                   onchange="zFileChosen(this,'<?= $fid ?>');updateLiveLimitEstimate();"/>
            <div class="zone-preview" id="zprev-<?= $fid ?>"></div>
          </div>
        </div>

        <button type="button" class="app-btn app-btn-primary" style="margin-top:6px;" onclick="goStep(3)">Next: Loan Details &rarr;</button>
      </div>

      <!-- ========== STEP 3 -- Loan Details ========== -->
      <div class="step-panel" id="step-3">

        <div class="tier-banner" id="tierBanner">
          <div style="font-size:10.5px;font-weight:800;color:#6d28d9;text-transform:uppercase;letter-spacing:.5px;">Loanable Amount</div>
          <div class="tier-max" id="tierMaxDisplay">P100,000</div>
          <div class="tier-hint" id="tierHint">Based on the documents and income you submitted in Step 2.</div>
          <div class="loanable-bar-track"><div class="loanable-bar-fill" id="loanableBarFill"></div></div>
          <div class="loanable-bar-labels"><span id="loanableBarUsed">P0 selected</span><span id="loanableBarMax">P100,000 max</span></div>
        </div>

        <div class="app-card">
          <div class="app-form-group">
            <label class="app-form-label">Loan Amount (P) <span class="req-badge">Required</span></label>
            <input type="number" name="loan_amount" id="loanAmount" class="app-input"
                   min="1000" max="100000" step="500" placeholder="e.g. 50,000" oninput="calcPayment()"/>
            <div style="font-size:10.5px;color:var(--gray-400);margin-top:4px;" id="loanRangeHint">Min P1,000 -- Max P100,000</div>
          </div>
          <div class="app-form-group">
            <label class="app-form-label">Loan Term <span class="req-badge">Required</span></label>
            <select name="loan_term" id="loanTerm" class="app-select" onchange="calcPayment()">
              <?php foreach([3,6,12,18,24,36,48,60] as $m): ?>
                <option value="<?= $m ?>"><?= $m ?> months<?= $m>=24?' ('.($m/12).' yrs)':'' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="app-form-group">
            <label class="app-form-label">Purpose <span class="req-badge">Required</span></label>
            <select name="purpose" id="loanPurpose" class="app-select">
              <option>Business Capital</option><option>Medical Expenses</option>
              <option>Home Improvement</option><option>Education</option>
              <option>Emergency Fund</option><option>Debt Consolidation</option>
              <option>Vehicle Purchase</option><option>Other</option>
            </select>
          </div>
          <div class="app-form-group" style="margin-bottom:0;">
            <label class="app-form-label">Additional Notes <span class="opt-badge">Optional</span></label>
            <textarea name="notes" class="app-textarea" rows="2" placeholder="Any extra context..."></textarea>
          </div>
        </div>

        <!-- Payment Estimate -->
        <div class="app-section-title">Payment Estimate</div>
        <div class="app-card" style="background:linear-gradient(135deg,var(--maroon),var(--maroon-dark));color:white;">
          <div style="text-align:center;padding:6px 0 14px;border-bottom:1px solid rgba(255,255,255,.15);margin-bottom:14px;">
            <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:1px;opacity:.75;margin-bottom:6px;">Monthly Payment</div>
            <div id="monthlyDisplay" style="font-size:30px;font-weight:800;">P0.00</div>
          </div>
          <div style="display:flex;flex-direction:column;gap:9px;font-size:12.5px;">
            <div style="display:flex;justify-content:space-between;">
              <span style="opacity:.75;">Loan Amount</span><span id="amountDisplay" style="font-weight:700;">P0.00</span>
            </div>
            <div style="display:flex;justify-content:space-between;">
              <span style="opacity:.75;">Interest (5% p.a.)</span><span id="interestDisplay" style="font-weight:700;">P0.00</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding-top:9px;border-top:1px solid rgba(255,255,255,.15);">
              <span style="font-weight:700;">Total Payable</span><span id="totalDisplay" style="font-weight:800;">P0.00</span>
            </div>
          </div>
        </div>
        <div class="app-card-flat" style="background:#eff6ff;padding:12px 14px;font-size:11.5px;color:#1e40af;line-height:1.6;">
          Note: This is an estimate only. 5% fixed annual rate. Final terms confirmed upon approval.
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <button type="button" class="app-btn app-btn-secondary" onclick="goStep(2)">&larr; Back</button>
          <button type="button" class="app-btn app-btn-primary" onclick="goStep(4)">Next: Review &rarr;</button>
        </div>
      </div>

      <!-- ========== STEP 4 -- Review & Submit ========== -->
      <div class="step-panel" id="step-4">

        <div class="app-section-title">Loan Details Summary</div>
        <div class="app-card-flat" style="padding:4px 16px;">
          <div class="rev-row"><span class="rev-lbl">Loan Amount</span>   <span class="rev-val" id="rev-amount">-</span></div>
          <div class="rev-row"><span class="rev-lbl">Loan Term</span>     <span class="rev-val" id="rev-term">-</span></div>
          <div class="rev-row"><span class="rev-lbl">Purpose</span>       <span class="rev-val" id="rev-purpose">-</span></div>
          <div class="rev-row"><span class="rev-lbl">Stated Monthly Income</span> <span class="rev-val" id="rev-income">-</span></div>
          <div class="rev-row"><span class="rev-lbl">Interest Rate</span> <span class="rev-val">5% per annum</span></div>
          <div class="rev-row">
            <span class="rev-lbl">Monthly Payment</span>
            <span class="rev-val" id="rev-monthly" style="color:var(--maroon);font-size:15px;">-</span>
          </div>
          <div class="rev-row"><span class="rev-lbl">Total Payable</span> <span class="rev-val" id="rev-total">-</span></div>
        </div>

        <div class="app-section-title">Documents Submitted</div>
        <div class="app-card">
          <div id="rev-docs" style="margin-bottom:12px;line-height:2;"></div>
          <div class="app-card-flat" style="background:#eff6ff;padding:11px 13px;font-size:11.5px;color:#1e40af;line-height:1.6;margin:0;">
            <strong>After submission:</strong> Our team reviews your documents within 24 hours and notifies you of the outcome.
          </div>
        </div>

        <!-- Security Verification -->
        <div class="app-section-title">Security Verification <span class="verify-badge">Checked</span></div>
        <div class="app-card-flat" style="padding:4px 16px;">
          <div class="rev-row"><span class="rev-lbl">Registered ID Type</span><span class="rev-val"><?= htmlspecialchars($id_type ?: '-') ?></span></div>
          <div class="rev-row"><span class="rev-lbl">Identity Photos</span><span class="rev-val" style="color:#16a34a;">Clarity &amp; format checked</span></div>
        </div>

        <!-- Promissory Note -- already signed in Step 1 -->
        <div class="app-section-title">Promissory Note <span class="verify-badge">Signed</span></div>
        <div class="app-card-flat" style="padding:4px 16px;">
          <div class="rev-row"><span class="rev-lbl">Signed By</span><span class="rev-val" id="rev-esign">-</span></div>
          <div class="rev-row"><span class="rev-lbl">Status</span><span class="rev-val" style="color:#16a34a;">✅ Agreed in Step 1</span></div>
        </div>
        <div class="app-card-flat" style="background:#eff6ff;padding:11px 13px;font-size:11.5px;color:#1e40af;line-height:1.6;">
          <strong>Note:</strong> You already reviewed and signed the Promissory Note in Step 1. Go back to Step 1 if you need to review it again.
        </div>

        <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:10px;">
          <button type="button" class="app-btn app-btn-secondary" onclick="goStep(3)">&larr; Back</button>
          <button type="button" id="submitBtn" onclick="doSubmit()" class="app-btn app-btn-primary">
            Submit Application
          </button>
        </div>
      </div>

    </form>

    <?php endif; ?>

  </main>

  <?php include 'app_tabbar.php'; ?>

<script src="assets/js/tracking-min.js"></script>
<script src="assets/js/face-min.js"></script>
<script>
let currentStep = 1;
const FULL_NAME = <?= json_encode($full_name) ?>;
const ID_TYPE   = <?= json_encode($id_type) ?>;
const LOAN_MIN_JS  = <?= LOAN_MIN ?>;
const LOAN_BASE_JS = <?= LOAN_BASE_MAX ?>;
const LOAN_STEP_JS = <?= LOAN_STEP ?>;
const LOAN_HARD_JS = <?= LOAN_HARD_MAX ?>;

// Expected aspect-ratio range per ID type -- mirrors ID_RATIO_RANGES in config.php
const ID_RATIO_RANGES = {
  'PhilSys':            [1.10, 2.40],
  "Driver's License":   [1.10, 2.40],
  'UMID':               [1.10, 2.40],
  'Postal ID':          [1.10, 2.40],
  "Voter's ID":         [1.10, 2.40],
  'Passport':           [1.10, 2.40],
  '_default':           [1.10, 2.40],
};

// Tracks whether each identity-sensitive upload has passed its client-side
// quality/legitimacy check. A field only counts as "complete" once true.
const zoneValidity = { doc_selfie: false, doc_selfie_with_id: false, doc_gov_id: false };

// -- Payment calculator --
function fmt(n) {
  return 'P' + parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
function calcPayment() {
  const amountEl = document.getElementById('loanAmount');
  if (!amountEl) return { amount:0, term:12, interest:0, total:0, monthly:0 }; // wizard not rendered (e.g. active-loan block screen)
  const amount   = parseFloat(amountEl.value) || 0;
  const term     = parseInt(document.getElementById('loanTerm').value) || 12;
  const interest = amount * 0.05;
  const total    = amount + interest;
  const monthly  = total / term;
  document.getElementById('monthlyDisplay').textContent  = fmt(monthly);
  document.getElementById('amountDisplay').textContent   = fmt(amount);
  document.getElementById('interestDisplay').textContent = fmt(interest);
  document.getElementById('totalDisplay').textContent    = fmt(total);
  updateLoanableBar();
  return { amount, term, interest, total, monthly };
}
calcPayment();

const INCOME_MULT_JS = <?= INCOME_MULTIPLIER ?>;

// -- Tiered loan ceiling: combines documents submitted AND stated income --
function countOptionalDocs() {
  let n = 0;
  ['doc_extra_1'].forEach(fid => {
    const inp = document.getElementById(fid);
    if (inp && inp.files && inp.files.length > 0) n++;
  });
  return n;
}
function getMonthlyIncome() {
  const el = document.getElementById('monthlyIncome');
  return el ? (parseFloat(el.value) || 0) : 0;
}
function computeLoanMax() {
  const docBasedMax = Math.min(LOAN_HARD_JS, LOAN_BASE_JS + countOptionalDocs() * LOAN_STEP_JS);
  const income = getMonthlyIncome();
  if (income <= 0) return docBasedMax;
  const incomeBasedMax = Math.max(LOAN_MIN_JS, Math.floor((income * INCOME_MULT_JS) / 500) * 500);
  return Math.max(LOAN_MIN_JS, Math.min(docBasedMax, incomeBasedMax));
}
// Live estimate shown right in Step 2 as the user types income / attaches docs
function updateLiveLimitEstimate() {
  const box = document.getElementById('liveLimitEstimate');
  if (!box) return;
  const income = getMonthlyIncome();
  const n = countOptionalDocs();
  if (income <= 0) { box.style.display = 'none'; return; }
  const max = computeLoanMax();
  box.style.display = 'block';
  box.textContent = `Estimated max loan right now: ${fmt(max).replace('.00','')} (based on your income + additional document)`;
}
function updateLoanableBar() {
  const fill = document.getElementById('loanableBarFill');
  if (!fill) return;
  const max = computeLoanMax();
  const amt = parseFloat(document.getElementById('loanAmount')?.value) || 0;
  const pct = max > 0 ? Math.min(100, (amt / max) * 100) : 0;
  fill.style.width = pct + '%';
  document.getElementById('loanableBarUsed').textContent = fmt(amt).replace('.00','') + ' selected';
  document.getElementById('loanableBarMax').textContent  = fmt(max).replace('.00','') + ' max';
}

function updateTierBanner() {
  const max = computeLoanMax();
  const amountEl = document.getElementById('loanAmount');
  amountEl.max = max;
  document.getElementById('tierMaxDisplay').textContent = fmt(max).replace('.00','');
  document.getElementById('loanRangeHint').textContent  = `Min P1,000 -- Max ${fmt(max).replace('.00','')}`;
  const n = countOptionalDocs();
  const income = getMonthlyIncome();
  document.getElementById('tierHint').textContent = income > 0
    ? `Based on your stated income (₱${income.toLocaleString()}/mo) and the additional document attached in Step 2.`
    : 'Enter your monthly income in Step 2 to see your loan limit.';
  if (parseFloat(amountEl.value) > max) { amountEl.value = max; calcPayment(); }
  updateLoanableBar();
}

// -- Step navigation with validation --
function showStepError(stepId, message) {
  let banner = document.getElementById('stepErrBanner');
  const panel = document.getElementById(stepId);
  if (!banner) {
    banner = document.createElement('div');
    banner.id = 'stepErrBanner';
    panel.prepend(banner);
  } else if (banner.parentElement !== panel) {
    panel.prepend(banner);
  }
  banner.textContent = 'Warning: ' + message;
  banner.style.display = 'block';
  setTimeout(() => { if (banner) banner.style.display = 'none'; }, 6000);
}

function highlightInvalid(el) {
  if (!el) return;
  el.style.borderColor = '#ef4444';
  el.style.boxShadow   = '0 0 0 3px rgba(239,68,68,.15)';
  setTimeout(() => { el.style.borderColor = ''; el.style.boxShadow = ''; }, 3000);
}

// Validate Step 1: Promissory Note signature + declaration
function validateStep1() {
  const esignEl = document.getElementById('esign');
  if (!esignEl.value.trim() || esignEl.value.trim().toLowerCase() !== FULL_NAME.trim().toLowerCase()) {
    showStepError('step-1', `Please type your full name exactly as registered ("${FULL_NAME}") to sign the promissory note.`);
    esignEl.scrollIntoView({behavior:'smooth', block:'center'});
    highlightInvalid(esignEl);
    return false;
  }
  const declareEl = document.getElementById('declare');
  if (!declareEl.checked) {
    showStepError('step-1', 'Please read and accept the Promissory Note before continuing.');
    declareEl.scrollIntoView({behavior:'smooth', block:'center'});
    highlightInvalid(declareEl.parentElement);
    return false;
  }
  return true;
}

// Validate Step 2: Identity + required documents, all must have PASSED
// their quality/legitimacy check (not just be present).
function validateStep2() {
  const incomeEl = document.getElementById('monthlyIncome');
  if (!incomeEl.value || parseFloat(incomeEl.value) <= 0) {
    highlightInvalid(incomeEl);
    showStepError('step-2', 'Please enter your monthly income.');
    incomeEl.scrollIntoView({behavior:'smooth', block:'center'});
    return false;
  }
  const required = [
    { id:'doc_selfie',          label:'Selfie',            checked:true },
    { id:'doc_selfie_with_id',  label:'Selfie with ID',    checked:true },
    { id:'doc_gov_id',          label:'Government ID',     checked:true },
    { id:'doc_proof_income',    label:'Proof of Income',   checked:false },
    { id:'doc_proof_billing',   label:'Proof of Billing',  checked:false },
    { id:'doc_extra_1',         label:'Additional Document (' + document.getElementById('doc_extra_1_type').value + ')', checked:false },
  ];
  let missing = [];
  let firstBadZone = null;
  required.forEach(d => {
    const inp = document.getElementById(d.id);
    const zone = document.getElementById('zone-' + d.id);
    const present = inp && inp.files && inp.files.length > 0;
    const ok = d.checked ? (present && zoneValidity[d.id] === true) : present;
    if (!ok) {
      missing.push(d.label);
      if (!firstBadZone) firstBadZone = zone;
      if (zone && present) {
        // File is present but failed its quality check -- zone already shows its own error.
      } else if (zone) {
        zone.style.borderColor = '#ef4444';
        zone.style.background  = '#fef2f2';
        setTimeout(() => { zone.style.borderColor = ''; zone.style.background = ''; }, 3000);
      }
    }
  });
  if (missing.length > 0) {
    showStepError('step-2', 'Please upload valid, clear copies of: ' + missing.join(', ') + '.');
    if (firstBadZone) firstBadZone.scrollIntoView({behavior:'smooth', block:'center'});
    return false;
  }
  return true;
}

// Validate Step 3: Loan Amount, Term, Purpose -- amount capped by document tier
function validateStep3() {
  const amountEl  = document.getElementById('loanAmount');
  const termEl    = document.getElementById('loanTerm');
  const purposeEl = document.getElementById('loanPurpose');
  const amt       = parseFloat(amountEl.value);
  const max       = computeLoanMax();

  if (!amountEl.value || !amt || amt < LOAN_MIN_JS || amt > max) {
    highlightInvalid(amountEl);
    showStepError('step-3', `Please enter a loan amount between ${fmt(LOAN_MIN_JS)} and ${fmt(max)}. Your maximum is based on the documents you submitted.`);
    amountEl.focus();
    return false;
  }
  if (!termEl.value) {
    highlightInvalid(termEl);
    showStepError('step-3', 'Please select a loan term.');
    return false;
  }
  if (!purposeEl.value) {
    highlightInvalid(purposeEl);
    showStepError('step-3', 'Please select a purpose for your loan.');
    return false;
  }
  return true;
}

function goStep(n) {
  // Validate before moving forward
  if (n > currentStep) {
    if (currentStep === 1 && !validateStep1()) return;
    if (currentStep === 2 && !validateStep2()) return;
    if (currentStep === 3 && !validateStep3()) return;
  }
  if (n === 3) updateTierBanner();
  if (n === 4) buildReview();

  document.getElementById('step-' + currentStep).classList.remove('active');
  currentStep = n;
  document.getElementById('step-' + currentStep).classList.add('active');

  for (let i = 1; i <= 4; i++) {
    const si = document.getElementById('si-' + i);
    si.classList.remove('active','done');
    if (i < n) si.classList.add('done');
    if (i === n) si.classList.add('active');
  }
  window.scrollTo({ top: 0, behavior:'smooth' });
}

// -- Build review panel --
function buildReview() {
  const vals    = calcPayment();
  const term    = parseInt(document.getElementById('loanTerm').value);
  const purpose = document.getElementById('loanPurpose').value;
  document.getElementById('rev-amount').textContent  = fmt(vals.amount);
  document.getElementById('rev-term').textContent    = term + ' months';
  document.getElementById('rev-purpose').textContent = purpose;
  document.getElementById('rev-income').textContent  = fmt(getMonthlyIncome()).replace('.00','') + '/mo';
  document.getElementById('rev-monthly').textContent = fmt(vals.monthly);
  document.getElementById('rev-total').textContent   = fmt(vals.total);
  document.getElementById('rev-esign').textContent   = document.getElementById('esign').value.trim();

  const docDefs = [
    { id:'doc_selfie',          label:'Selfie',            req:true },
    { id:'doc_selfie_with_id',  label:'Selfie with ID',    req:true },
    { id:'doc_gov_id',          label:'Government ID',     req:true },
    { id:'doc_proof_income',    label:'Proof of Income',   req:true },
    { id:'doc_proof_billing',   label:'Proof of Billing',  req:true },
    { id:'doc_extra_1',         label:document.querySelector('[name=doc_extra_1_type]').value, req:true },
  ];
  let html = '';
  docDefs.forEach(d => {
    const inp = document.getElementById(d.id);
    const ok  = inp && inp.files && inp.files.length > 0;
    if (ok) {
      const kb = Math.round(inp.files[0].size / 1024);
      html += `<span class="doc-pill ok">${d.label}: ${inp.files[0].name} (${kb} KB)</span>`;
    } else if (d.req) {
      html += `<span class="doc-pill missing">${d.label}: Not uploaded</span>`;
    }
  });
  document.getElementById('rev-docs').innerHTML = html || '<p style="color:var(--gray-400);font-size:12.5px;margin:0;">No documents selected.</p>';
}

// -- File caching to prevent net::ERR_UPLOAD_FILE_CHANGED on submit --
// On Android/Chrome, a picked file (especially a camera-captured selfie)
// lives at a temp path that can be invalidated by the time this multi-step
// form is finally submitted, which makes the browser abort the upload with
// net::ERR_UPLOAD_FILE_CHANGED. Reading the file into memory the moment
// it's chosen -- and rebuilding a fresh File object from that memory copy at
// submit time -- avoids depending on that temp path still being valid later.
const _cachedFiles  = {};  // fieldId -> {name, type, buffer}
const _filePromises = {};  // fieldId -> Promise (resolves once cached)

function cacheFileForSubmit(fieldId, file) {
  const p = new Promise((resolve) => {
    const reader = new FileReader();
    reader.onload  = () => { _cachedFiles[fieldId] = { name:file.name, type:file.type, buffer:reader.result }; resolve(); };
    reader.onerror = () => { delete _cachedFiles[fieldId]; resolve(); }; // fall back to the live input on read failure
    reader.readAsArrayBuffer(file);
  });
  _filePromises[fieldId] = p;
  return p;
}

// -- Client-side image quality/legitimacy check --
// A heuristic check (resolution + blur-variance + aspect-ratio plausibility
// for the Government ID vs the customer's registered ID type) -- not true
// AI/OCR document verification. Good enough to catch obviously blurry,
// tiny, or wrong-shaped photos before they're even uploaded.
function checkImageQuality(file, fieldId) {
  return new Promise((resolve) => {
    if (!file.type.startsWith('image/')) { resolve({ok:true}); return; } // PDFs skip this check
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      const w = img.naturalWidth, h = img.naturalHeight;
      if (w < 480 || h < 480) {
        URL.revokeObjectURL(url);
        resolve({ok:false, reason:'Resolution is too low. Please upload a clearer, higher-resolution photo.'});
        return;
      }
      if (fieldId === 'doc_gov_id') {
        const range = ID_RATIO_RANGES[ID_TYPE] || ID_RATIO_RANGES['_default'];
        const ratio = Math.max(w,h) / Math.max(1, Math.min(w,h));
        if (ratio < range[0] || ratio > range[1]) {
          URL.revokeObjectURL(url);
          resolve({ok:false, reason:`This photo's shape doesn't look like a valid ${ID_TYPE || 'ID'}. Please upload a clear, straight-on photo of your actual ID card.`});
          return;
        }
      }
      // Blur estimate via a small canvas + Laplacian-variance proxy.
      const maxDim = 220;
      const scale  = Math.min(1, maxDim / Math.max(w, h));
      const cw = Math.max(1, Math.round(w * scale));
      const ch = Math.max(1, Math.round(h * scale));
      const canvas = document.createElement('canvas');
      canvas.width = cw; canvas.height = ch;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0, cw, ch);
      URL.revokeObjectURL(url);
      let data;
      try { data = ctx.getImageData(0, 0, cw, ch).data; }
      catch (e) { resolve({ok:true}); return; } // canvas tainted or unsupported -- don't block the user over it

      const gray = new Float32Array(cw * ch);
      for (let i = 0, p = 0; i < data.length; i += 4, p++) {
        gray[p] = 0.299*data[i] + 0.587*data[i+1] + 0.114*data[i+2];
      }
      let sum = 0, sumSq = 0, count = 0;
      for (let y = 1; y < ch - 1; y++) {
        for (let x = 1; x < cw - 1; x++) {
          const idx = y*cw + x;
          const lap = gray[idx-1] + gray[idx+1] + gray[idx-cw] + gray[idx+cw] - 4*gray[idx];
          sum += lap; sumSq += lap*lap; count++;
        }
      }
      const mean = sum / count;
      const variance = (sumSq / count) - (mean * mean);
      if (variance < 25) {
        resolve({ok:false, reason:'This photo looks blurry or out of focus. Please retake it in good lighting, holding the camera steady.'});
        return;
      }
      resolve({ok:true});
    };
    img.onerror = () => { URL.revokeObjectURL(url); resolve({ok:false, reason:'Could not read this image file. Please try a different photo.'}); };
    img.src = url;
  });
}

// -- Upload zone handlers --
async function zFileChosen(input, fieldId) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const ext  = file.name.split('.').pop().toLowerCase();

  const zone = document.getElementById('zone-' + fieldId);
  const prev = document.getElementById('zprev-' + fieldId);
  const icon = document.getElementById('zicon-' + fieldId);
  const errEl = document.getElementById('zerr-' + fieldId);

  function showZoneError(msg) {
    zone.classList.remove('has-file');
    zone.classList.add('has-error');
    if (icon) icon.textContent = '!';
    if (prev) prev.style.display = 'none';
    if (errEl) { errEl.textContent = msg; errEl.style.display = 'block'; }
    if (fieldId in zoneValidity) zoneValidity[fieldId] = false;
    input.value = '';
  }

  if (!['pdf','jpg','jpeg','png'].includes(ext)) {
    showZoneError('Only PDF, JPG, PNG files are accepted.');
    return;
  }
  if (file.size > 10 * 1024 * 1024) {
    showZoneError('File too large. Maximum size is 10MB.');
    return;
  }

  // Identity docs get an extra clarity/legitimacy pass before we accept them.
  if (fieldId in zoneValidity) {
    if (icon) icon.textContent = '...';
    if (errEl) errEl.style.display = 'none';
    const result = await checkImageQuality(file, fieldId);
    if (!result.ok) {
      showZoneError(result.reason);
      return;
    }
    zoneValidity[fieldId] = true;
  }

  cacheFileForSubmit(fieldId, file);

  zone.classList.remove('has-error');
  zone.classList.add('has-file');
  if (errEl) errEl.style.display = 'none';
  if (icon) icon.textContent = 'OK';
  if (prev) {
    prev.style.display = 'block';
    prev.innerHTML = `<strong>${file.name}</strong> -- ${Math.round(file.size/1024)} KB`;
  }
}

function zDragOver(e, zone) { e.preventDefault(); zone.classList.add('dragover'); }
function zDragLeave(zone)   { zone.classList.remove('dragover'); }
function zDrop(e, fieldId)  {
  e.preventDefault();
  const zone  = e.currentTarget;
  zone.classList.remove('dragover');
  const input = document.getElementById(fieldId);
  if (!input || !e.dataTransfer.files.length) return;
  try {
    const dt = new DataTransfer();
    dt.items.add(e.dataTransfer.files[0]);
    input.files = dt.files;
    zFileChosen(input, fieldId);
    updateTierBanner();
  } catch(err) {
    alert('Drag & drop not supported here. Please click to upload.');
  }
}

// -- Gallery fallback: routes a gallery-picked file through the same real
// named <input> and the same quality-check pipeline as camera capture --
function galleryFileChosen(galleryInput, fieldId) {
  if (!galleryInput.files || !galleryInput.files[0]) return;
  const realInput = document.getElementById(fieldId);
  try {
    const dt = new DataTransfer();
    dt.items.add(galleryInput.files[0]);
    realInput.files = dt.files;
    zFileChosen(realInput, fieldId);
    updateLiveLimitEstimate();
  } catch (err) {
    alert('Could not read that file. Please try again.');
  }
  galleryInput.value = '';
}

// ════════════════════════════════════════════════
//  LIVE CAMERA CAPTURE + REAL-TIME FACE DETECTION
// ════════════════════════════════════════════════
// Uses tracking.js (Viola-Jones / Haar-cascade face detection) -- a real,
// well-established computer-vision algorithm that runs entirely client-side
// via <canvas> pixel analysis. Unlike the browser-native Shape Detection API
// (window.FaceDetector), this works in EVERY browser, including Safari/iOS,
// where FaceDetector doesn't exist at all. No network calls, no CDN.
let _cameraStream    = null;
let _cameraFieldId   = null;
let _cameraLoopTimer = null;
let _cameraLastFaceOk = false; // true only when exactly 1 well-centered, well-lit, sharp face is currently showing

const FACE_MIN_BRIGHTNESS = 55;   // 0-255 average luma; below this = "too dark"
const FACE_MIN_SHARPNESS  = 18;   // Laplacian-variance proxy; below this = "blurry"
const FACE_CENTER_TOLERANCE = 0.16; // fraction of frame width/height allowed off-center before showing a directional hint

function ensureCameraModal() {
  if (document.getElementById('cameraModal')) return;
  const modal = document.createElement('div');
  modal.id = 'cameraModal';
  modal.style.cssText = 'position:fixed;inset:0;background:#000;z-index:100000;display:none;flex-direction:column;';
  modal.innerHTML = `
    <div style="position:relative;flex:1;overflow:hidden;display:flex;align-items:center;justify-content:center;">
      <video id="cameraVideo" autoplay playsinline muted style="width:100%;height:100%;object-fit:cover;"></video>
      <div id="cameraOval" style="position:absolute;border:4px solid rgba(255,255,255,.5);border-radius:50%;width:62%;aspect-ratio:3/4;pointer-events:none;transition:border-color .15s;"></div>
      <div id="cameraArrow" style="position:absolute;font-size:38px;color:#fff;text-shadow:0 1px 6px rgba(0,0,0,.7);pointer-events:none;display:none;"></div>
      <canvas id="cameraCanvas" style="display:none;"></canvas>
      <canvas id="cameraSampleCanvas" width="120" height="120" style="display:none;"></canvas>
      <div id="cameraStatus" style="position:absolute;top:16px;left:0;right:0;text-align:center;color:white;font-size:13px;font-weight:700;text-shadow:0 1px 4px rgba(0,0,0,.6);padding:0 20px;"></div>
    </div>
    <div style="padding:16px 20px 28px;background:#000;display:flex;align-items:center;justify-content:space-between;gap:14px;">
      <button type="button" onclick="closeCamera()" style="background:rgba(255,255,255,.15);color:white;border:none;border-radius:50%;width:48px;height:48px;font-size:18px;">✕</button>
      <button type="button" id="cameraCaptureBtn" onclick="captureCameraPhoto()" style="background:white;border:4px solid rgba(255,255,255,.4);border-radius:50%;width:68px;height:68px;"></button>
      <div style="width:48px;"></div>
    </div>
  `;
  document.body.appendChild(modal);
}

// -- Analyze one video frame: face position (via tracking.js), brightness, sharpness --
function analyzeFrame(video) {
  const sample = document.getElementById('cameraSampleCanvas');
  const sw = sample.width, sh = sample.height;
  const ctx = sample.getContext('2d');
  ctx.drawImage(video, 0, 0, sw, sh);

  let pixels;
  try { pixels = ctx.getImageData(0, 0, sw, sh).data; }
  catch (e) { return { faces: [], brightness: 255, sharpness: 999 }; } // can't read -- don't block the user over it

  // Brightness (average luma) + sharpness (Laplacian-variance proxy) on a
  // downscaled grayscale copy -- cheap enough to run several times a second.
  const gray = new Float32Array(sw * sh);
  let sum = 0;
  for (let i = 0, p = 0; i < pixels.length; i += 4, p++) {
    const l = 0.299*pixels[i] + 0.587*pixels[i+1] + 0.114*pixels[i+2];
    gray[p] = l;
    sum += l;
  }
  const brightness = sum / (sw*sh);

  let lapSum = 0, lapSumSq = 0, count = 0;
  for (let y = 1; y < sh-1; y++) {
    for (let x = 1; x < sw-1; x++) {
      const idx = y*sw+x;
      const lap = gray[idx-1] + gray[idx+1] + gray[idx-sw] + gray[idx+sw] - 4*gray[idx];
      lapSum += lap; lapSumSq += lap*lap; count++;
    }
  }
  const mean = lapSum / count;
  const sharpness = (lapSumSq / count) - (mean * mean);

  // Face detection via tracking.js (Viola-Jones), run on the same video element
  let faces = [];
  try {
    faces = tracking.ViolaJones.detect(
      pixels, sw, sh,
      4,     // initialScale
      1.25,  // scaleFactor
      2,     // stepSize
      0.1,   // edgesDensity
      tracking.ViolaJones.classifiers.face
    ) || [];
  } catch (e) { faces = []; }

  return { faces, brightness, sharpness, sw, sh };
}

async function openCamera(fieldId, facingMode) {
  ensureCameraModal();
  _cameraFieldId = fieldId;
  const modal  = document.getElementById('cameraModal');
  const video  = document.getElementById('cameraVideo');
  const status = document.getElementById('cameraStatus');
  const oval   = document.getElementById('cameraOval');
  const arrow  = document.getElementById('cameraArrow');
  const isFace = (facingMode === 'user'); // only run face/quality analysis for selfie-style captures
  const isSelfieWithId = (fieldId === 'doc_selfie_with_id');

  oval.style.display  = isFace ? 'block' : 'none';
  arrow.style.display = 'none';

  // Selfie + ID needs a BOX guide (wide enough to frame face + ID card
  // together) instead of the round/oval guide used for a plain face selfie.
  if (isSelfieWithId) {
    oval.style.borderRadius = '18px';
    oval.style.width        = '86%';
    oval.style.aspectRatio  = '4/3';
    status.textContent      = 'Center your face and ID in the box';
  } else {
    oval.style.borderRadius = '50%';
    oval.style.width        = '62%';
    oval.style.aspectRatio  = '3/4';
    status.textContent      = isFace ? 'Center your face in the oval' : 'Center your ID in frame';
  }

  _cameraLastFaceOk = !isFace; // ID captures don't require a face check to proceed

  try {
    _cameraStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: facingMode, width: { ideal: 1280 }, height: { ideal: 1280 } },
      audio: false
    });
  } catch (err) {
    alert('Could not access the camera. Please allow camera permission, or use "choose from gallery" instead.');
    return;
  }

  video.srcObject = _cameraStream;
  // iOS Safari in particular can fail to reliably auto-play/populate video
  // dimensions from just the `autoplay` attribute + srcObject -- explicitly
  // calling play() is the well-known fix. Errors here are non-fatal (some
  // browsers are stricter about autoplay); the load-timeout failsafe below
  // catches the case where the video genuinely never starts.
  video.play().catch(() => {});
  modal.style.display = 'flex';

  if (isFace) {
    // Failsafe: if the video never actually starts streaming frames (blank
    // videoWidth), don't loop silently forever with no feedback -- that is
    // exactly what makes the capture button look "dead" with no way out.
    let videoReadyWaitMs = 0;
    let stuckWaitMs = 0;
    const STUCK_HELP_AFTER_MS = 12000; // offer a manual way forward after ~12s of no green

    const loop = () => {
      if (!_cameraStream) return; // modal was closed

      if (!video.videoWidth) {
        videoReadyWaitMs += 300;
        if (videoReadyWaitMs >= 6000) {
          status.textContent = 'Camera preview failed to load. Please close and try again, or use "choose from gallery" instead.';
          oval.style.borderColor = '#ef4444';
          return; // stop looping -- retrying forever here is the actual bug
        }
        _cameraLoopTimer = setTimeout(loop, 300);
        return;
      }

      const { faces, brightness, sharpness } = analyzeFrame(video);

      if (faces.length === 0) {
        status.textContent = isSelfieWithId ? 'No face detected -- center your face and ID in the box' : 'No face detected -- center your face in the oval';
        oval.style.borderColor = 'rgba(255,255,255,.5)';
        arrow.style.display = 'none';
        _cameraLastFaceOk = false;
      } else if (faces.length > 1) {
        status.textContent = 'Multiple faces detected -- only you should be in frame';
        oval.style.borderColor = '#ef4444';
        arrow.style.display = 'none';
        _cameraLastFaceOk = false;
      } else if (brightness < FACE_MIN_BRIGHTNESS) {
        status.textContent = 'Too dark -- move to better lighting';
        oval.style.borderColor = '#ef4444';
        arrow.style.display = 'none';
        _cameraLastFaceOk = false;
      } else if (sharpness < FACE_MIN_SHARPNESS) {
        status.textContent = 'Image unclear -- hold the camera steady';
        oval.style.borderColor = '#ef4444';
        arrow.style.display = 'none';
        _cameraLastFaceOk = false;
      } else {
        // Face found and image quality is fine -- check how centered it is
        const f = faces[0];
        const cx = (f.x + f.width / 2);
        const cy = (f.y + f.height / 2);
        const sw = 120, sh = 120; // matches sample canvas size
        const dx = (cx - sw/2) / sw;   // -0.5..0.5, negative = face is left of center
        const dy = (cy - sh/2) / sh;

        if (Math.abs(dx) > FACE_CENTER_TOLERANCE || Math.abs(dy) > FACE_CENTER_TOLERANCE) {
          let dir = '';
          if (Math.abs(dx) > Math.abs(dy)) dir = dx > 0 ? '→ Move right' : '← Move left';
          else dir = dy > 0 ? '↓ Move down' : '↑ Move up';
          const arrowChar = dir.startsWith('→') ? '→' : dir.startsWith('←') ? '←' : dir.startsWith('↓') ? '↓' : '↑';
          status.textContent = dir.replace(/^[←→↑↓]\s*/, '');
          arrow.textContent = arrowChar;
          arrow.style.display = 'block';
          oval.style.borderColor = '#facc15'; // amber -- close, just needs centering
          _cameraLastFaceOk = false;
        } else {
          status.textContent = 'Face detected -- hold still and capture';
          oval.style.borderColor = '#22c55e'; // green -- good to go
          arrow.style.display = 'none';
          _cameraLastFaceOk = true;
        }
      }

      // Safety valve: don't let an imperfect detector or a persistently
      // tricky lighting/angle situation permanently trap a real user who
      // genuinely can't get it to turn green. After a while, offer a manual
      // way forward instead of silently blocking capture forever.
      if (_cameraLastFaceOk) {
        stuckWaitMs = 0;
        const helpEl = document.getElementById('cameraStuckHelp');
        if (helpEl) helpEl.style.display = 'none';
      } else {
        stuckWaitMs += 350;
        if (stuckWaitMs >= STUCK_HELP_AFTER_MS) {
          let helpEl = document.getElementById('cameraStuckHelp');
          if (!helpEl) {
            helpEl = document.createElement('div');
            helpEl.id = 'cameraStuckHelp';
            helpEl.style.cssText = 'position:absolute;bottom:14px;left:0;right:0;text-align:center;';
            helpEl.innerHTML = '<a href="javascript:void(0)" onclick="forceCaptureCameraPhoto()" style="color:#fff;background:rgba(0,0,0,.5);padding:8px 16px;border-radius:100px;font-size:12px;text-decoration:underline;">Having trouble? Tap to capture anyway</a>';
            document.getElementById('cameraVideo').parentElement.appendChild(helpEl);
          }
          helpEl.style.display = 'block';
        }
      }

      _cameraLoopTimer = setTimeout(loop, 350);
    };
    loop();
  }
}

function closeCamera() {
  if (_cameraLoopTimer) { clearTimeout(_cameraLoopTimer); _cameraLoopTimer = null; }
  if (_cameraStream) { _cameraStream.getTracks().forEach(t => t.stop()); _cameraStream = null; }
  const modal = document.getElementById('cameraModal');
  if (modal) modal.style.display = 'none';
  const helpEl = document.getElementById('cameraStuckHelp');
  if (helpEl) helpEl.remove();
}

// Manual override shown only after ~12s of the live check never turning
// green -- lets a real user get past an imperfect detector or unlucky
// lighting instead of being permanently stuck. Still runs the normal
// resolution/blur/legitimacy checks on the captured photo; only the live
// face-count gate is skipped.
function forceCaptureCameraPhoto() {
  captureCameraPhoto(true);
}

async function captureCameraPhoto(force) {
  const video  = document.getElementById('cameraVideo');
  const canvas = document.getElementById('cameraCanvas');
  const status = document.getElementById('cameraStatus');
  const fieldId = _cameraFieldId;
  const isFace = document.getElementById('cameraOval').style.display !== 'none';

  // Hard block until the live analysis loop confirms one well-centered,
  // well-lit, sharp face is currently showing -- unless the user explicitly
  // used the "capture anyway" override after being stuck for a while.
  if (isFace && !_cameraLastFaceOk && !force) {
    if (!status.textContent) status.textContent = 'Please center your face before capturing';
    return;
  }

  canvas.width  = video.videoWidth;
  canvas.height = video.videoHeight;
  canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

  // Re-verify the FROZEN still (not just the live feed) before accepting it --
  // lighting/motion can differ between the live loop's last read and the
  // actual captured frame. Skipped when the user used the manual override,
  // since if the live detector kept failing, the still would too --
  // clarity/quality checks in the normal upload pipeline still apply.
  if (isFace && !force) {
    const stillCtx = canvas.getContext('2d');
    let stillPixels;
    try { stillPixels = stillCtx.getImageData(0, 0, canvas.width, canvas.height).data; } catch (e) { stillPixels = null; }
    if (stillPixels) {
      let faces = [];
      try {
        faces = tracking.ViolaJones.detect(
          stillPixels, canvas.width, canvas.height,
          4, 1.25, 2, 0.1, tracking.ViolaJones.classifiers.face
        ) || [];
      } catch (e) { faces = []; }
      if (faces.length !== 1) {
        status.textContent = faces.length === 0
          ? 'No face detected in the captured photo -- please retake'
          : 'Multiple faces detected in the captured photo -- please retake';
        return;
      }
    }
  }

  canvas.toBlob(async (blob) => {
    if (!blob) { alert('Could not capture photo. Please try again.'); return; }
    const file = new File([blob], fieldId + '_' + Date.now() + '.jpg', { type: 'image/jpeg' });

    const realInput = document.getElementById(fieldId);
    try {
      const dt = new DataTransfer();
      dt.items.add(file);
      realInput.files = dt.files;
    } catch (e) { /* older browsers -- zFileChosen still caches the blob via _cachedFiles */ }

    closeCamera();
    await zFileChosen(realInput, fieldId);
    updateLiveLimitEstimate();
  }, 'image/jpeg', 0.9);
}

// -- Final submit --
async function doSubmit() {
  // Re-validate Step 1 (Promissory Note)
  if (!validateStep1()) { goStep(1); return; }

  // Re-validate Step 2 (Documents)
  if (!validateStep2()) { goStep(2); return; }

  // Re-validate Step 3 (Loan Details)
  if (!validateStep3()) { goStep(3); return; }

  // Disable button, show submitting state
  const btn = document.getElementById('submitBtn');
  const btnOriginalText = btn.innerHTML;
  btn.textContent = 'Submitting... Please wait';
  btn.disabled    = true;
  btn.style.opacity = '0.7';
  btn.style.cursor  = 'not-allowed';

  // Show overlay so user can't double-click during upload
  const overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9999;display:flex;align-items:center;justify-content:center;';
  overlay.innerHTML = '<div style="background:white;border-radius:16px;padding:28px 36px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.2);max-width:80%;"><div style="font-weight:800;font-size:14px;color:var(--text-dark);">Submitting your application...</div><div style="font-size:11.5px;color:var(--gray-400);margin-top:4px;">Please don\'t close this page</div></div>';
  document.body.appendChild(overlay);

  try {
    // Make sure every picked file has finished being read into memory
    await Promise.all(Object.values(_filePromises));

    const form = document.getElementById('applyForm');
    const formData = new FormData(form);

    // Swap in the memory-cached copy of each file so we never depend on the
    // original <input> still pointing at a valid temp path.
    const fileFieldIds = ['doc_selfie','doc_selfie_with_id','doc_gov_id','doc_proof_income','doc_proof_billing','doc_extra_1'];
    fileFieldIds.forEach(fid => {
      const cached = _cachedFiles[fid];
      if (cached) {
        formData.delete(fid);
        formData.append(fid, new File([cached.buffer], cached.name, { type: cached.type }));
      }
    });

    const res = await fetch('customer_apply.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    });

    // The server responds with a redirect (success -> customer_loan.php,
    // error -> back to this page with $_SESSION['apply_error'] set).
    // fetch() follows it automatically, so just navigate to where it landed.
    window.location.href = res.url || 'customer_apply.php';
  } catch (err) {
    overlay.remove();
    btn.innerHTML   = btnOriginalText;
    btn.disabled    = false;
    btn.style.opacity = '1';
    btn.style.cursor  = 'pointer';
    alert('Something went wrong while submitting your application. Please check your connection and try again.');
  }
}
</script>
<?php include 'chatbot.php'; ?>
  <script src="realtime.js"></script>
</body>
</html>
