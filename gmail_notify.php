<?php
/**
 * Velveth Lending — Gmail Notification System (PHPMailer edition)
 *
 * Uses PHPMailer + Gmail SMTP with a Gmail App Password.
 * No OAuth, no credentials.json, no token.json needed.
 *
 * ── HOW TO SET UP (one time only) ───────────────────────────────────────────
 *  1. Sign in to your Google Account
 *  2. Go to: https://myaccount.google.com/security
 *     → Enable "2-Step Verification" (required)
 *  3. Go to: https://myaccount.google.com/apppasswords
 *  4. Select App: "Mail" → Device: "Windows Computer" → Generate
 *  5. Copy the 16-character App Password Google gives you
 *  6. Paste it in GMAIL_APP_PASSWORD below (remove the spaces)
 *  7. Set GMAIL_USERNAME to your Gmail address
 * ────────────────────────────────────────────────────────────────────────────
 */

// ── ✏️  EDIT THESE TWO LINES ─────────────────────────────────────────────────
define('GMAIL_USERNAME',    'velveth869@gmail.com');    // your Gmail address
define('GMAIL_APP_PASSWORD','mwbz hgal dzwq dsbc');     // 16-char App Password
// ─────────────────────────────────────────────────────────────────────────────

define('GMAIL_FROM_NAME', 'Velveth Lending');

// ── Load PHPMailer ────────────────────────────────────────────────────────────
require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ─────────────────────────────────────────────────────────────────────────────
// Core send function — used by all notification helpers below
// ─────────────────────────────────────────────────────────────────────────────
// Captures the full SMTP conversation + error detail from the most recent
// send attempt, so a caller (like test_email.php) can show a real diagnostic
// instead of a bare "failed to send".
$GLOBALS['_velveth_last_mail_debug'] = '';
$GLOBALS['_velveth_last_mail_error'] = '';

function getLastMailDebug() { return $GLOBALS['_velveth_last_mail_debug']; }
function getLastMailError() { return $GLOBALS['_velveth_last_mail_error']; }

function sendGmailNotification($to, $subject, $htmlBody, $debug = false) {
    $mail = new PHPMailer(true);
    $debugLog = '';

    try {
        // Server
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = GMAIL_USERNAME;
        $mail->Password   = str_replace(' ', '', GMAIL_APP_PASSWORD); // strip spaces
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        // Keep this short. The default is 300 seconds -- on a host that
        // blocks outbound SMTP entirely (common on free hosting), or any
        // time Gmail is briefly unreachable, a call made synchronously
        // during a page load (e.g. a login-alert email sent right before
        // the dashboard redirect) would otherwise hang the ENTIRE request
        // for up to 5 minutes. 8 seconds is enough for a real handshake but
        // fails fast when the connection has no hope of succeeding.
        $mail->Timeout = 8;

        // Debug capture is OPT-IN only (used by test_email.php), never on by
        // default. The bundled SMTP class here only recognizes the literal
        // strings 'error_log' or 'html' for Debugoutput -- anything else
        // (including a Closure) falls through to a raw `echo` straight onto
        // the page. That's what was leaking full SMTP transcripts onto
        // customer_register.php for every real user. The fix has two parts:
        // (1) leave SMTPDebug at its default (0) for every normal send, so
        // edebug()'s internal level check short-circuits before anything is
        // ever echoed; (2) when a caller explicitly asks for $debug, still
        // let it use the library's default 'echo' behavior, but capture
        // that echoed output into a PHP output buffer below (never letting
        // it reach the real page) and return it as a string instead.
        if ($debug) {
            $mail->SMTPDebug = 2; // 2 = client+server messages, no passwords
            // Debugoutput intentionally left at its default ('echo') here --
            // that's what makes the ob_start()/ob_get_clean() capture below work.
        }

        // From / To
        $mail->setFrom(GMAIL_USERNAME, GMAIL_FROM_NAME);
        $mail->addReplyTo(GMAIL_USERNAME, GMAIL_FROM_NAME);
        $mail->addAddress(trim($to));

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</tr>'], "\n", $htmlBody));

        // Capture debug output via a PHP output buffer instead of relying on
        // Debugoutput (which this bundled SMTP class doesn't fully support),
        // so test_email.php can still show the transcript without any risk
        // of it leaking to a real page.
        if ($debug) ob_start();
        $mail->send();
        if ($debug) $debugLog = ob_get_clean();

        $GLOBALS['_velveth_last_mail_debug'] = $debugLog;
        $GLOBALS['_velveth_last_mail_error'] = '';
        return true;

    } catch (Exception $e) {
        if ($debug && ob_get_level() > 0) $debugLog = ob_get_clean();
        $GLOBALS['_velveth_last_mail_debug'] = $debugLog;
        $GLOBALS['_velveth_last_mail_error'] = $mail->ErrorInfo ?: $e->getMessage();
        error_log('[Velveth] PHPMailer failed | to=' . $to . ' | error=' . $GLOBALS['_velveth_last_mail_error']);
        return false;
    } catch (\Throwable $e) {
        // Catch anything else (e.g. a network-level fatal) so a mail failure
        // never takes down the page that triggered it.
        if ($debug && ob_get_level() > 0) $debugLog = ob_get_clean();
        $GLOBALS['_velveth_last_mail_debug'] = $debugLog;
        $GLOBALS['_velveth_last_mail_error'] = $e->getMessage();
        error_log('[Velveth] Mail send crashed | to=' . $to . ' | error=' . $e->getMessage());
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Shared HTML email template
// ─────────────────────────────────────────────────────────────────────────────
function gmailTemplate($title, $content, $accentColor = '#7a1e2e') {
    $year = date('Y');
    return "<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:0;background:#f0f0f0;font-family:Arial,Helvetica,sans-serif;'>
  <table width='100%' cellpadding='0' cellspacing='0' style='background:#f0f0f0;padding:32px 0;'>
    <tr><td align='center'>
      <table width='600' cellpadding='0' cellspacing='0'
             style='background:#ffffff;border-radius:10px;overflow:hidden;'>

        <!-- Header -->
        <tr>
          <td bgcolor='{$accentColor}'
              style='background:{$accentColor};background:linear-gradient(135deg,{$accentColor},#4a1020);padding:36px 32px;text-align:center;'>
            <div style='font-size:34px;line-height:1;margin-bottom:10px;'>💰</div>
            <h1 style='color:#ffffff;margin:0;font-size:24px;letter-spacing:1px;font-weight:bold;'>
              Velveth Lending
            </h1>
            <div style='color:rgba(255,255,255,.75);margin-top:4px;font-size:11.5px;letter-spacing:2px;text-transform:uppercase;'>
              Lending &amp; Loan Management
            </div>
          </td>
        </tr>

        <!-- Section title strip -->
        <tr>
          <td bgcolor='#faf5f6'
              style='background:#faf5f6;padding:16px 32px;border-bottom:1px solid #f0e3e5;'>
            <span style='color:{$accentColor};font-size:16px;font-weight:bold;'>{$title}</span>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style='padding:32px 32px 36px;color:#333333;font-size:15px;line-height:1.7;'>
            {$content}
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style='background:#f9f9f9;padding:20px 32px;text-align:center;
                     border-top:1px solid #eeeeee;color:#aaaaaa;font-size:12px;line-height:1.6;'>
            &copy; {$year} Velveth Lending &nbsp;|&nbsp; All rights reserved.<br>
            This is an automated message — please do not reply.
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>";
}

// ─────────────────────────────────────────────────────────────────────────────
// Shared table row helper
// ─────────────────────────────────────────────────────────────────────────────
function _emailRow($label, $value, $shade = false) {
    $bg = $shade ? "background:#f9f9f9;" : "";
    return "<tr style='{$bg}'>
      <td style='color:#777777;width:45%;padding:10px 14px;font-size:14px;'>{$label}</td>
      <td style='padding:10px 14px;font-size:14px;'>{$value}</td>
    </tr>";
}

function _emailTable($rows) {
    return "<table width='100%' cellpadding='0' cellspacing='0'
             style='border-collapse:collapse;margin-top:16px;border:1px solid #eeeeee;border-radius:6px;'>
      {$rows}
    </table>";
}

// ─────────────────────────────────────────────────────────────────────────────
// Notification functions — call these from your PHP pages
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Login alert — sent whenever a customer successfully logs in
 */
function notifyLoginDetected($toEmail, $fullName, $loginTime, $ipAddress = '') {
    $n    = htmlspecialchars($fullName);
    $time = htmlspecialchars($loginTime);
    $rows = _emailRow('Name', "<strong>{$n}</strong>", false)
          . _emailRow('Login Time', $time, true);
    if ($ipAddress) {
        $rows .= _emailRow('IP Address', htmlspecialchars($ipAddress), false);
    }
    $content = "
      <p>Hi <strong>{$n}</strong>,</p>
      <p>A successful login was detected on your Velveth Lending account.</p>
      " . _emailTable($rows) . "
      <p style='margin-top:20px;color:#555555;'>
        If this was you, no action is needed. If you don't recognize this login,
        please reset your password immediately and contact us.
      </p>
      <p style='margin-top:24px;'>
        <a href='" . BASE_URL . "/forgot_password.php'
           style='display:inline-block;background:#7a1e2e;color:#ffffff;
                  padding:13px 30px;border-radius:6px;text-decoration:none;font-size:15px;font-weight:bold;'>
          Reset My Password →
        </a>
      </p>";
    return sendGmailNotification(
        $toEmail,
        'Login Detected 🔒 — Velveth Lending',
        gmailTemplate('Login Detected 🔒', $content)
    );
}

/**
 * Welcome email after customer registration
 */
function notifyCustomerRegistered($toEmail, $fullName) {
    $n = htmlspecialchars($fullName);
    $content = "
      <p>Hi <strong>{$n}</strong>,</p>
      <p>Welcome to <strong>Velveth Lending</strong>! 🎉</p>
      <p>Your account has been successfully created. You can now log in and apply for a loan.</p>
      <p style='margin-top:28px;'>
        <a href='" . BASE_URL . "/customer_login.php'
           style='display:inline-block;background:#7a1e2e;color:#ffffff;
                  padding:13px 30px;border-radius:6px;text-decoration:none;font-size:15px;font-weight:bold;'>
          Log In Now →
        </a>
      </p>";
    return sendGmailNotification(
        $toEmail,
        'Welcome to Velveth Lending! 🎉',
        gmailTemplate('Welcome! 🎉', $content)
    );
}

/**
 * Confirmation after a loan application is submitted
 */
function notifyLoanApplicationReceived($toEmail, $fullName, $appId, $amount) {
    $n   = htmlspecialchars($fullName);
    $id  = htmlspecialchars($appId);
    $amt = '₱' . number_format((float)$amount, 2);
    $rows = _emailRow('Application ID', "<strong>{$id}</strong>", false)
          . _emailRow('Amount Requested', "<strong>{$amt}</strong>", true)
          . _emailRow('Status', "<strong style='color:#e67e22;'>⏳ Under Review</strong>", false);
    $content = "
      <p>Hi <strong>{$n}</strong>,</p>
      <p>We have received your loan application. Our team will review it and get back to you shortly.</p>
      " . _emailTable($rows) . "
      <p style='margin-top:16px;color:#555555;'>We will notify you by email once a decision has been made.</p>";
    return sendGmailNotification(
        $toEmail,
        "Loan Application {$id} — Received",
        gmailTemplate('Loan Application Received 📋', $content)
    );
}

/**
 * Notification when a loan is approved
 */
function notifyLoanApproved($toEmail, $fullName, $appId, $amount, $firstPaymentDate) {
    $n    = htmlspecialchars($fullName);
    $id   = htmlspecialchars($appId);
    $amt  = '₱' . number_format((float)$amount, 2);
    $date = htmlspecialchars($firstPaymentDate);
    $rows = _emailRow('Application ID', "<strong>{$id}</strong>", false)
          . _emailRow('Approved Amount', "<strong>{$amt}</strong>", true)
          . _emailRow('First Payment Due', "<strong>{$date}</strong>", false)
          . _emailRow('Status', "<strong style='color:#1a7a2e;'>✅ Approved</strong>", true);
    $content = "
      <p>Hi <strong>{$n}</strong>,</p>
      <p>🎉 Great news! Your loan application has been <strong style='color:#1a7a2e;'>approved</strong>.</p>
      " . _emailTable($rows) . "
      <p style='margin-top:16px;color:#555555;'>Please log in to view your repayment schedule.</p>
      <p style='margin-top:24px;'>
        <a href='" . BASE_URL . "/customer_login.php'
           style='display:inline-block;background:#1a7a2e;color:#ffffff;
                  padding:13px 30px;border-radius:6px;text-decoration:none;font-size:15px;font-weight:bold;'>
          View My Loan →
        </a>
      </p>";
    return sendGmailNotification(
        $toEmail,
        "Loan {$id} — Approved! 🎉",
        gmailTemplate('Your Loan is Approved! 🎉', $content, '#1a7a2e')
    );
}

/**
 * Notification when a loan is rejected
 */
function notifyLoanRejected($toEmail, $fullName, $appId, $reason) {
    $n      = htmlspecialchars($fullName);
    $id     = htmlspecialchars($appId);
    $reason = htmlspecialchars($reason);
    $rows = _emailRow('Application ID', "<strong>{$id}</strong>", false)
          . _emailRow('Reason', $reason, true)
          . _emailRow('Status', "<strong style='color:#c0392b;'>❌ Not Approved</strong>", false);
    $content = "
      <p>Hi <strong>{$n}</strong>,</p>
      <p>We regret to inform you that your loan application has not been approved at this time.</p>
      " . _emailTable($rows) . "
      <p style='margin-top:16px;color:#555555;'>
        You are welcome to reapply after addressing the concerns above.<br>
        Contact us if you have questions.
      </p>";
    return sendGmailNotification(
        $toEmail,
        "Loan Application {$id} — Not Approved",
        gmailTemplate('Loan Application Update', $content)
    );
}

/**
 * Notification when a payment is recorded
 */
function notifyPaymentReceived($toEmail, $fullName, $amount, $date, $remainingBalance) {
    $n    = htmlspecialchars($fullName);
    $amt  = '₱' . number_format((float)$amount, 2);
    $bal  = '₱' . number_format((float)$remainingBalance, 2);
    $date = htmlspecialchars($date);
    $rows = _emailRow('Amount Paid', "<strong style='color:#1a7a2e;'>{$amt}</strong>", false)
          . _emailRow('Payment Date', $date, true)
          . _emailRow('Remaining Balance', "<strong>{$bal}</strong>", false);
    $content = "
      <p>Hi <strong>{$n}</strong>,</p>
      <p>Your payment has been successfully recorded. Thank you! 💪</p>
      " . _emailTable($rows) . "
      <p style='margin-top:16px;color:#555555;'>
        Keep it up! Log in anytime to view your full payment history.
      </p>";
    return sendGmailNotification(
        $toEmail,
        'Payment Successfully Recorded ✅',
        gmailTemplate('Payment Received ✅', $content, '#1a5c7a')
    );
}

/**
 * Alert to admin when a new application is submitted
 */
function notifyAdminNewApplication($adminEmail, $customerName, $appId, $amount) {
    $name = htmlspecialchars($customerName);
    $id   = htmlspecialchars($appId);
    $amt  = '₱' . number_format((float)$amount, 2);
    $rows = _emailRow('Customer', "<strong>{$name}</strong>", false)
          . _emailRow('Application ID', "<strong>{$id}</strong>", true)
          . _emailRow('Amount Requested', "<strong>{$amt}</strong>", false);
    $content = "
      <p>A new loan application has been submitted and requires your review.</p>
      " . _emailTable($rows) . "
      <p style='margin-top:24px;'>
        <a href='" . BASE_URL . "/admin_loan_applicants.php'
           style='display:inline-block;background:#7a1e2e;color:#ffffff;
                  padding:13px 30px;border-radius:6px;text-decoration:none;font-size:15px;font-weight:bold;'>
          Review Application →
        </a>
      </p>";
    return sendGmailNotification(
        $adminEmail,
        "New Loan Application: {$id}",
        gmailTemplate('New Loan Application 📋', $content)
    );
}

/**
 * Penalty notification email to customer
 */
function notifyPenaltyIssued($toEmail, $fullName, $penaltyId, $amount, $reason, $dueDate, $loanId = '') {
    $n      = htmlspecialchars($fullName);
    $pid    = htmlspecialchars($penaltyId);
    $amt    = '₱' . number_format((float)$amount, 2);
    $reason = htmlspecialchars($reason);
    $due    = htmlspecialchars($dueDate);
    $lid    = htmlspecialchars($loanId);
    $rows = _emailRow('Penalty ID',  "<strong style='color:#c0392b;'>{$pid}</strong>", false)
          . _emailRow('Amount Due',  "<strong style='color:#c0392b;'>{$amt}</strong>", true)
          . _emailRow('Reason',       $reason, false)
          . _emailRow('Due Date',     "<strong>{$due}</strong>", true)
          . ($lid ? _emailRow('Related Loan', $lid, false) : '');
    $content = "
      <p>Hi <strong>{$n}</strong>,</p>
      <p>⚠️ A penalty has been issued on your loan account. Please settle the amount by the due date to avoid additional charges.</p>
      " . _emailTable($rows) . "
      <p style='margin-top:16px;color:#c0392b;font-weight:bold;'>
        Please settle this penalty along with your next payment.
      </p>
      <p style='margin-top:20px;'>
        <a href='" . BASE_URL . "/customer_loan.php'
           style='display:inline-block;background:#7a1e2e;color:#ffffff;
                  padding:13px 30px;border-radius:6px;text-decoration:none;font-size:15px;font-weight:bold;'>
          View My Loan →
        </a>
      </p>";
    return sendGmailNotification(
        $toEmail,
        "⚠️ Penalty Issued — {$pid}",
        gmailTemplate('Penalty Notice ⚠️', $content, '#7a1e2e')
    );
}

/**
 * Penalty waived / paid notification
 */
function notifyPenaltyUpdated($toEmail, $fullName, $penaltyId, $newStatus, $amount) {
    $n      = htmlspecialchars($fullName);
    $pid    = htmlspecialchars($penaltyId);
    $amt    = '₱' . number_format((float)$amount, 2);
    $sLabel = ucfirst($newStatus);
    $sColor = $newStatus === 'paid' ? '#1a7a2e' : ($newStatus === 'waived' ? '#1a5c7a' : '#c0392b');
    $content = "
      <p>Hi <strong>{$n}</strong>,</p>
      <p>Your penalty <strong>{$pid}</strong> of <strong>{$amt}</strong> has been updated to 
         <strong style='color:{$sColor};'>{$sLabel}</strong>.</p>
      <p style='margin-top:20px;'>
        <a href='" . BASE_URL . "/customer_loan.php'
           style='display:inline-block;background:#7a1e2e;color:#ffffff;
                  padding:13px 30px;border-radius:6px;text-decoration:none;font-size:15px;font-weight:bold;'>
          View My Account →
        </a>
      </p>";
    return sendGmailNotification(
        $toEmail,
        "Penalty {$pid} — {$sLabel}",
        gmailTemplate("Penalty Updated — {$sLabel}", $content, $sColor)
    );
}

/**
 * Generic account update notification
 */
function notifyAccountUpdate($toEmail, $fullName, $updateType, $details) {
    $n    = htmlspecialchars($fullName);
    $type = htmlspecialchars($updateType);
    $det  = htmlspecialchars($details);
    $content = "
      <p>Hi <strong>{$n}</strong>,</p>
      <p>Your Velveth Lending account has been updated:</p>
      <div style='background:#f9f9f9;border-left:4px solid #7a1e2e;padding:14px 18px;margin:16px 0;border-radius:0 6px 6px 0;'>
        <strong>{$type}</strong><br>
        <span style='color:#555;'>{$det}</span>
      </div>
      <p style='color:#555;'>If you did not request this change, please contact us immediately.</p>";
    return sendGmailNotification(
        $toEmail,
        "Account Update — {$type}",
        gmailTemplate('Account Updated 📝', $content)
    );
}

/**
 * Password reset link — sent when a user requests "Forgot password?"
 */
function notifyPasswordReset($toEmail, $fullName, $resetLink) {
    $n = htmlspecialchars($fullName);
    $content = "
      <p>Hi <strong>{$n}</strong>,</p>
      <p>We received a request to reset your Velveth Lending password. Click the button below to choose a new one.</p>
      <p style='margin-top:24px;text-align:center;'>
        <a href='{$resetLink}'
           style='display:inline-block;background:#7a1e2e;color:#ffffff;
                  padding:13px 30px;border-radius:6px;text-decoration:none;font-size:15px;font-weight:bold;'>
          Reset My Password →
        </a>
      </p>
      <p style='margin-top:20px;color:#555555;font-size:13px;'>
        This link will expire in 1 hour. If you didn't request a password reset, you can safely ignore this
        email — your password will remain unchanged.
      </p>
      <p style='margin-top:16px;color:#999999;font-size:11.5px;word-break:break-all;'>
        Or copy this link into your browser:<br>{$resetLink}
      </p>";
    return sendGmailNotification(
        $toEmail,
        'Reset Your Velveth Lending Password',
        gmailTemplate('Password Reset Request 🔑', $content)
    );
}
