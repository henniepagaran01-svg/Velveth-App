<?php
session_start();
require_once 'config.php';
// gmail_notify.php is already loaded by config.php

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['full_name']     ?? '');
    $email     = trim($_POST['email']         ?? '');
    $phone     = trim($_POST['phone']         ?? '');
    $address   = trim($_POST['address']       ?? '');
    $dob_month = $_POST['dob_month']          ?? '';
    $dob_day   = $_POST['dob_day']            ?? '';
    $dob_year  = $_POST['dob_year']           ?? '';
    $gender    = $_POST['gender']             ?? '';
    $civil     = $_POST['civil_status']       ?? '';
    $id_card   = $_POST['id_card']            ?? '';
    $lang      = $_POST['native_language']    ?? '';
    $password  = $_POST['password']           ?? '';
    $confirm   = $_POST['confirm_password']   ?? '';
    $agreed    = isset($_POST['agree']);

    if (!$agreed) {
        $msg = 'error:You must agree to the Terms and Conditions and Privacy Policy.';
    } elseif (empty($name) || empty($email)) {
        $msg = 'error:Full name and email are required.';
    } elseif (empty($phone)) {
        $msg = 'error:Phone number is required.';
    } elseif (!preg_match('/^\d{11}$/', $phone)) {
        $msg = 'error:Phone number must be exactly 11 digits (e.g. 09171234567).';
    } elseif (empty($address)) {
        $msg = 'error:Home address is required.';
    } elseif (strlen($password) < 9) {
        $msg = 'error:Password must be at least 9 characters.';
    } elseif ($password !== $confirm) {
        $msg = 'error:Passwords do not match.';
    } else {
        $check = $conn->query("SELECT id FROM users WHERE email='".mysqli_real_escape_string($conn,$email)."'");
        if ($check->num_rows > 0) {
            $msg = 'error:Email already registered.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $dob  = ($dob_year && $dob_month && $dob_day)
                  ? sprintf('%04d-%02d-%02d', (int)$dob_year, (int)$dob_month, (int)$dob_day)
                  : null;

            // Check if extra columns exist, fall back gracefully
            $cols_res = $conn->query("SHOW COLUMNS FROM users");
            $cols = [];
            while ($col_row = $cols_res->fetch_assoc()) $cols[] = $col_row['Field'];

            if (in_array('date_of_birth', $cols)) {
                $stmt = $conn->prepare(
                    "INSERT INTO users (full_name,email,phone,address,date_of_birth,gender,civil_status,id_type,password,role)
                     VALUES (?,?,?,?,?,?,?,?,?,'customer')"
                );
                $stmt->bind_param("sssssssss", $name,$email,$phone,$address,$dob,$gender,$civil,$id_card,$hash);
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO users (full_name,email,phone,address,password,role) VALUES (?,?,?,?,?,'customer')"
                );
                $stmt->bind_param("sssss", $name,$email,$phone,$address,$hash);
            }

            if ($stmt->execute()) {
                $new_uid  = (int)$conn->insert_id;
                $esc_name = mysqli_real_escape_string($conn, $name);

                // Welcome in-app notification
                $conn->query("INSERT INTO notifications (user_id,title,message,type)
                    VALUES ($new_uid,'👋 Welcome to Velveth Lending!',
                    'Your account has been created successfully. You can now apply for a loan.','success')");

                // Gmail welcome email (non-fatal)
                if (function_exists('notifyCustomerRegistered')) {
                    @notifyCustomerRegistered($email, $name);
                }

                // Notify all active admins (in-app + email)
                $admins = $conn->query("SELECT id,email,full_name FROM users WHERE role='admin' AND status='active'");
                while ($adm = $admins->fetch_assoc()) {
                    $aid = (int)$adm['id'];
                    $conn->query("INSERT INTO notifications (user_id,title,message,type)
                        VALUES ($aid,'👤 New Customer Registered',
                        '$esc_name just registered. Review their profile in the Customers section.','info')");
                    if (function_exists('sendGmailNotification') && !empty($adm['email'])) {
                        $body = "<p>New customer <strong>" . htmlspecialchars($name) .
                                "</strong> registered with email <strong>" . htmlspecialchars($email) . "</strong>.</p>";
                        @sendGmailNotification($adm['email'], 'New Customer Registered — ' . $name, $body);
                    }
                }

                logAudit($conn, 'Customer', $name, 'New customer registered', $email);
                $msg = 'success:Account created successfully! You can now sign in.';
            } else {
                $msg = 'error:Registration failed: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}
[$mtype, $mtext] = $msg ? explode(':', $msg, 2) : ['',''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Create Account | Velveth</title>
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
    html,body { background:var(--bg-page) !important; max-width:100%; }
    body.app-shell { padding-bottom:0 !important; }
    .reg-section { font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--maroon);margin:18px 0 10px;padding-bottom:6px;border-bottom:2px solid var(--maroon-pale); }
  </style>
</head>
<body class="app-shell">

  <!-- App Bar -->
  <header class="app-bar maroon">
    <a href="customer_login.php" class="app-bar-back">←</a>
    <div style="flex:1;min-width:0;">
      <div class="app-bar-title">Create Account</div>
      <div class="app-bar-sub">Join Velveth Lending today</div>
    </div>
  </header>

  <main class="app-screen">

    <?php if ($mtype === 'success'): ?>
    <div style="text-align:center;padding:32px 20px;">
      <div style="font-size:52px;margin-bottom:14px;">🎉</div>
      <div style="font-size:18px;font-weight:800;color:var(--text-dark);margin-bottom:8px;">Account Created!</div>
      <div style="font-size:13px;color:var(--gray-400);margin-bottom:24px;"><?= htmlspecialchars($mtext) ?></div>
      <a href="customer_login.php" class="app-btn app-btn-primary">Sign In Now →</a>
    </div>
    <?php else: ?>

    <?php if ($mtype === 'error'): ?>
    <div style="background:#fee2e2;color:#b91c1c;padding:11px 14px;border-radius:12px;font-size:12.5px;font-weight:600;margin-bottom:16px;display:flex;gap:8px;align-items:center;">
      ❌ <?= htmlspecialchars($mtext) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
      <!-- Personal Information -->
      <div class="reg-section">👤 Personal Information</div>
      <div class="app-card">
        <div class="app-form-group">
          <label class="app-form-label">Full Name *</label>
          <input type="text" name="full_name" class="app-input" required placeholder="First Middle Last"
                 value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Email Address *</label>
          <input type="email" name="email" class="app-input" required placeholder="your@email.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"/>
        </div>
        <div class="app-form-group">
          <label class="app-form-label">Phone Number *</label>
          <input type="tel" name="phone" id="phoneInput" class="app-input" required
                 placeholder="09XX XXX XXXX" inputmode="numeric" maxlength="11"
                 pattern="\d{11}" title="Enter exactly 11 digits, e.g. 09171234567"
                 oninput="this.value=this.value.replace(/\D/g,'').slice(0,11);"
                 value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"/>
          <div style="font-size:10.5px;color:var(--gray-400);margin-top:4px;">Exactly 11 digits, e.g. 09171234567</div>
        </div>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Home Address *</label>
          <textarea name="address" class="app-textarea" rows="2" required placeholder="House No., Street, Barangay, City"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
        </div>
      </div>

      <!-- Birth / Demographics -->
      <div class="reg-section">🎂 Personal Details</div>
      <div class="app-card">
        <div class="app-form-group">
          <label class="app-form-label">Date of Birth</label>
          <div style="display:grid;grid-template-columns:2fr 1fr 2fr;gap:8px;">
            <select name="dob_month" class="app-select" style="font-size:13px;">
              <option value="">Month</option>
              <?php foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $mi=>$mn): ?>
              <option value="<?= $mi+1 ?>" <?= ($_POST['dob_month']??'')==$mi+1?'selected':'' ?>><?= $mn ?></option>
              <?php endforeach; ?>
            </select>
            <select name="dob_day" class="app-select" style="font-size:13px;">
              <option value="">Day</option>
              <?php for($d=1;$d<=31;$d++): ?>
              <option value="<?= $d ?>" <?= ($_POST['dob_day']??'')==$d?'selected':'' ?>><?= $d ?></option>
              <?php endfor; ?>
            </select>
            <select name="dob_year" class="app-select" style="font-size:13px;">
              <option value="">Year</option>
              <?php for($y=date('Y')-18;$y>=1940;$y--): ?>
              <option value="<?= $y ?>" <?= ($_POST['dob_year']??'')==$y?'selected':'' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="app-form-group">
            <label class="app-form-label">Gender</label>
            <select name="gender" class="app-select">
              <option value="">Select</option>
              <option value="Male"   <?= ($_POST['gender']??'')==='Male'  ?'selected':''?>>Male</option>
              <option value="Female" <?= ($_POST['gender']??'')==='Female'?'selected':''?>>Female</option>
              <option value="Other"  <?= ($_POST['gender']??'')==='Other' ?'selected':''?>>Other</option>
            </select>
          </div>
          <div class="app-form-group">
            <label class="app-form-label">Civil Status</label>
            <select name="civil_status" class="app-select">
              <option value="">Select</option>
              <option value="Single"  <?= ($_POST['civil_status']??'')==='Single'  ?'selected':''?>>Single</option>
              <option value="Married" <?= ($_POST['civil_status']??'')==='Married' ?'selected':''?>>Married</option>
              <option value="Widowed" <?= ($_POST['civil_status']??'')==='Widowed' ?'selected':''?>>Widowed</option>
            </select>
          </div>
        </div>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Government ID Type</label>
          <select name="id_card" class="app-select">
            <option value="">Select ID Type</option>
            <option value="PhilSys">PhilSys (National ID)</option>
            <option value="Passport">Philippine Passport</option>
            <option value="Driver's License">Driver's License</option>
            <option value="SSS ID">SSS ID</option>
            <option value="GSIS ID">GSIS ID</option>
            <option value="PhilHealth ID">PhilHealth ID</option>
            <option value="Voter's ID">Voter's ID</option>
          </select>
        </div>
      </div>

      <!-- Password -->
      <div class="reg-section">🔒 Account Security</div>
      <div class="app-card">
        <div class="app-form-group">
          <label class="app-form-label">Password * <span style="color:var(--gray-400);font-size:10.5px;font-weight:500;">(min. 9 characters)</span></label>
          <div style="position:relative;">
            <input type="password" name="password" id="pw1" class="app-input" required minlength="9"
                   placeholder="Create a strong password" style="padding-right:44px;"/>
            <button type="button" onclick="togglePw('pw1','eye1')" id="eye1"
                    style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;padding:0;display:flex;cursor:pointer;color:var(--gray-400);">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
        <div class="app-form-group" style="margin-bottom:0;">
          <label class="app-form-label">Confirm Password *</label>
          <div style="position:relative;">
            <input type="password" name="confirm_password" id="pw2" class="app-input" required minlength="9"
                   placeholder="Re-enter your password" style="padding-right:44px;"/>
            <button type="button" onclick="togglePw('pw2','eye2')" id="eye2"
                    style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;padding:0;display:flex;cursor:pointer;color:var(--gray-400);">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
      </div>

      <!-- Terms -->
      <div class="app-card" style="margin-bottom:8px;">
        <div style="display:flex;align-items:flex-start;gap:12px;">
          <input type="checkbox" name="agree" id="agree" required
                 style="width:20px;height:20px;margin-top:2px;accent-color:var(--maroon);cursor:pointer;flex-shrink:0;"/>
          <label for="agree" style="font-size:12.5px;color:var(--text-medium);line-height:1.7;cursor:pointer;">
            I have read and agree to the
            <a href="terms.php" style="color:var(--maroon);font-weight:700;">Terms and Conditions</a>
            and <a href="privacy.php" style="color:var(--maroon);font-weight:700;">Privacy Policy</a>.
          </label>
        </div>
      </div>

      <button type="submit" class="app-btn app-btn-primary" style="font-size:15px;">
        Create Account
      </button>

      <div style="text-align:center;margin-top:18px;font-size:13px;color:var(--gray-400);margin-bottom:20px;">
        Already have an account?
        <a href="customer_login.php" style="color:var(--maroon);font-weight:800;"> Sign In</a>
      </div>

    <?php endif; ?>
  </main>

<script>
const EYE_OPEN = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>';
const EYE_OFF  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 1 12s4 8 11 8a9.26 9.26 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>';
function togglePw(fieldId, eyeId) {
  const f = document.getElementById(fieldId);
  const e = document.getElementById(eyeId);
  if (f.type === 'password') { f.type = 'text'; e.innerHTML = EYE_OFF; }
  else { f.type = 'password'; e.innerHTML = EYE_OPEN; }
}
</script>
  <script>if("serviceWorker" in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register("/sw.js").catch(function(){});});}</script>
  <script src="assets/js/form-guard.js"></script>
</body>
</html>
