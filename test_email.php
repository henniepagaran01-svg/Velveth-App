<?php
/**
 * Velveth Lending — Email Diagnostic Tool
 * Visit: http://localhost/velveth/test_email.php
 * DELETE this file once email notifications are confirmed working!
 */
require_once __DIR__ . '/gmail_notify.php';

$result   = null;
$message  = '';
$debugLog = '';
$errorInfo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['send_to'])) {
    $to = trim($_POST['send_to']);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $message = 'Invalid email address.';
    } else {
        $ok = sendGmailNotification(
            $to,
            'Velveth Lending - Test Email',
            gmailTemplate('Test Email', "
              <p>This is a test email from <strong>Velveth Lending</strong>.</p>
              <p>PHPMailer + Gmail SMTP is working correctly!</p>
            "),
            true // debug=true: capture the SMTP transcript for display below, only here
        );
        $result    = $ok;
        $debugLog  = getLastMailDebug();
        $errorInfo = getLastMailError();
        $message   = $ok
            ? 'Reported success sending to ' . htmlspecialchars($to) . '. Check that inbox (and its Spam/Promotions folder) to confirm it actually arrived.'
            : 'PHPMailer reported a failure. See the exact error and full SMTP conversation below.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Velveth - Email Diagnostic</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
    .box { max-width: 640px; margin: 40px auto; background: #fff; padding: 40px; border-radius: 10px; box-shadow: 0 2px 16px rgba(0,0,0,.1); }
    h2 { color: #7a1e2e; margin-top: 0; text-align:center; }
    input[type=email] { width: 100%; padding: 12px 14px; font-size: 15px; border: 1px solid #ddd; border-radius: 6px; margin-bottom: 14px; }
    button { background: #7a1e2e; color: #fff; border: none; padding: 13px 30px; border-radius: 6px; font-size: 15px; cursor: pointer; width: 100%; }
    button:hover { background: #5a1520; }
    .ok  { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 14px; border-radius: 6px; margin-bottom: 20px; }
    .err { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 14px; border-radius: 6px; margin-bottom: 20px; }
    .note { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; font-size: 13px; margin-top: 20px; padding: 12px 16px; border-radius: 6px; text-align: left; }
    .checklist { background:#eef2ff; border:1px solid #c7d2fe; color:#3730a3; font-size:13px; margin-top:20px; padding:14px 18px; border-radius:6px; line-height:1.7; }
    code { background: #f0f0f0; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
    pre { background:#1e1e1e; color:#d4d4d4; padding:16px; border-radius:8px; font-size:11.5px; line-height:1.6; overflow-x:auto; white-space:pre-wrap; word-break:break-all; max-height:400px; overflow-y:auto; }
    .error-info { background:#7f1d1d; color:#fecaca; padding:12px 16px; border-radius:6px; font-size:13px; font-weight:bold; margin-bottom:12px; }
  </style>
</head>
<body>
  <div class="box">
    <h2>Email Diagnostic</h2>
    <?php if ($message): ?>
      <div class="<?= $result ? 'ok' : 'err' ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <p style="color:#555;text-align:center;">Enter any email address (Gmail, Yahoo, Outlook, etc.) to send a real test notification and see exactly what happens.</p>
    <form method="POST">
      <input type="email" name="send_to" placeholder="recipient@example.com"
             value="<?= htmlspecialchars($_POST['send_to'] ?? '') ?>" required>
      <button type="submit">Send Test Email</button>
    </form>

    <?php if ($errorInfo): ?>
      <div class="error-info">PHPMailer error: <?= htmlspecialchars($errorInfo) ?></div>
    <?php endif; ?>

    <?php if ($debugLog): ?>
      <p style="font-size:12.5px;color:#555;margin-bottom:6px;"><strong>Full SMTP conversation</strong> (this shows exactly what Gmail's server said back):</p>
      <pre><?= htmlspecialchars($debugLog) ?></pre>
    <?php endif; ?>

    <div class="checklist">
      <strong>If sending fails for EVERY address (including your own):</strong><br>
      • Look for "Connection timed out" or "Connection refused" in the log above — this almost always means your
        web host <strong>blocks outbound SMTP on port 587/465</strong>. Many free hosts (including InfinityFree) do
        this deliberately to prevent spam, and no code change here can work around it — you'd need to switch to an
        HTTP-API-based email service (e.g. Brevo, Resend, SendGrid) instead of raw SMTP.<br>
      • "Username and Password not accepted" means the Gmail App Password below is wrong, expired, or 2-Step
        Verification got turned off for the account.<br><br>
      <strong>If sending REPORTS success but the email never arrives for other people:</strong><br>
      • Check their <strong>Spam / Promotions folder</strong> first — this is the most common cause of "it says sent
        but I never got it."<br>
      • Confirm the address you tested with doesn't have a typo.
    </div>

    <div class="note">
      <strong>Current sender configured in gmail_notify.php:</strong><br><br>
      <code><?= htmlspecialchars(GMAIL_USERNAME) ?></code><br><br>
      <strong>Delete this file once notifications are confirmed working end-to-end!</strong>
    </div>
  </div>
</body>
</html>
