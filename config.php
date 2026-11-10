<?php
// ── Prevent caching of authenticated/session-bound pages ─────────────────────
// Every page that requires this file is tied to a PHP session (login state,
// per-user data, forms). Without this, a browser's own HTTP cache (separate
// from the service worker) can serve a stale version of a page from before a
// login/logout — e.g. showing an old dashboard, or an old login-error state,
// instead of the current one. This is unconditional and cheap, so it's set
// centrally here rather than repeated on every individual page.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// ── Error reporting (set display_errors to 0 on production) ──────────────────
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors',     1);
ini_set('upload_max_filesize', '10M');
ini_set('post_max_size',       '32M');

// ── Database Configuration ────────────────────────────────────────────────────
define('DB_HOST', 'sql101.infinityfree.com');
define('DB_USER', 'if0_42634765');
define('DB_PASS', 'XkQqNGCaUr2');
define('DB_NAME', 'if0_42634765_velveth');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    error_log('Velveth DB connection failed: ' . $conn->connect_error);
    die("
    <div style='font-family:Arial,sans-serif;max-width:500px;margin:80px auto;
                padding:40px;text-align:center;background:#fff3f3;
                border:2px solid #c0392b;border-radius:8px;'>
      <h2 style='color:#7a1e2e;'>Database Connection Error</h2>
      <p>Could not connect to the database.</p>
      <p style='font-size:13px;color:#666;'>
        Host: <strong>" . DB_HOST . "</strong> |
        DB: <strong>" . DB_NAME . "</strong> |
        User: <strong>" . DB_USER . "</strong>
      </p>
      <p style='font-size:12px;color:#999;'>Make sure MySQL is running in XAMPP.</p>
    </div>");
}

$conn->set_charset('utf8mb4');

// ── Time zone ────────────────────────────────────────────────────────────────
// Everything (PHP date() and MySQL NOW()/CURRENT_TIMESTAMP) now runs on
// Philippine time. Before, PHP and MySQL could disagree (host default = UTC or
// another zone), which made chat / notification times wrong. TIMESTAMP columns
// are stored in UTC internally, so setting the session zone also corrects the
// display of EXISTING rows.
date_default_timezone_set('Asia/Manila');
@$conn->query("SET time_zone = '+08:00'");

// ── Make sure notifications/messages can store emoji (utf8mb4) ───────────────
// Titles like "✅ Document Verified" were showing up as "? Document Verified"
// when the table's charset was not utf8mb4.
function ensureUtf8mb4Tables($conn) {
    foreach (['notifications' => 'title', 'messages' => 'message'] as $tbl => $col) {
        $r = @$conn->query("SHOW FULL COLUMNS FROM `$tbl` WHERE Field='$col'");
        if ($r && ($row = $r->fetch_assoc())) {
            if (stripos((string)$row['Collation'], 'utf8mb4') !== 0) {
                @$conn->query("ALTER TABLE `$tbl` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        }
    }
}
ensureUtf8mb4Tables($conn);

// ── Chat time labels (computed on the SERVER so every device shows the same,
//    correct Philippine date/time -- no more browser-timezone guessing) ───────
function chatTimeLabels($created_at) {
    $ts = strtotime($created_at);
    return [
        'day'        => date('Y-m-d', $ts),
        'date_label' => date('M j, Y', $ts),
        'time_label' => date('g:i A', $ts),
    ];
}

// ── Base URL (used to build absolute links in emails, e.g. password reset) ──
if (!defined('BASE_URL')) {
    $_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $_host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    define('BASE_URL', $_scheme . '://' . $_host . $_dir);
}

// ── Load Gmail Notification System ───────────────────────────────────────────
$gmailFile = __DIR__ . '/gmail_notify.php';
if (file_exists($gmailFile)) {
    require_once $gmailFile;
} else {
    error_log('[Velveth] gmail_notify.php not found. Email notifications disabled.');
}

// ── Helper: log an audit trail entry ─────────────────────────────────────────
function logAudit($conn, $type, $user, $action, $details) {
    $stmt = $conn->prepare(
        "INSERT INTO audit_trail (type, user_name, action, details, ip_address)
         VALUES (?, ?, ?, ?, ?)"
    );
    if ($stmt) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $stmt->bind_param('sssss', $type, $user, $action, $details, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

// ── Helper: insert an in-app notification for a user ─────────────────────────
function sendNotification($conn, $user_id, $title, $message, $type = 'info') {
    $uid   = (int)$user_id;
    $esc_t = $conn->real_escape_string($title);
    $esc_m = $conn->real_escape_string($message);
    $esc_y = $conn->real_escape_string($type);
    return $conn->query(
        "INSERT INTO notifications (user_id, title, message, type)
         VALUES ($uid, '$esc_t', '$esc_m', '$esc_y')"
    );
}

// ── Helper: send in-app notification to all active admins ────────────────────
function notifyAdmins($conn, $title, $message, $type = 'info') {
    $admins = $conn->query(
        "SELECT id FROM users WHERE role='admin' AND status='active'"
    );
    if ($admins) {
        while ($a = $admins->fetch_assoc()) {
            sendNotification($conn, $a['id'], $title, $message, $type);
        }
    }
}

// ── Helper: get a user's email and full name by ID ───────────────────────────
function getUserEmail($conn, $user_id) {
    $uid = (int)$user_id;
    $res = $conn->query("SELECT email, full_name FROM users WHERE id=$uid");
    return $res ? $res->fetch_assoc() : null;
}

// ── Helper: make sure the password-reset columns exist on `users` ────────────
// Older/already-deployed databases won't have these yet. Rather than require
// a manual ALTER TABLE (easy to forget and causes a fatal error on the
// forgot/reset-password pages), add them automatically the first time
// they're needed.
function ensureResetTokenColumns($conn) {
    static $checked = false;
    if ($checked) return; // only check once per request
    $checked = true;

    $cols_res = $conn->query("SHOW COLUMNS FROM users");
    $cols = [];
    while ($col_row = $cols_res->fetch_assoc()) $cols[] = $col_row['Field'];

    if (!in_array('reset_token', $cols)) {
        $conn->query("ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) NULL");
    }
    if (!in_array('reset_token_expires', $cols)) {
        $conn->query("ALTER TABLE users ADD COLUMN reset_token_expires DATETIME NULL");
    }
}

// ── Helper: make sure `users.deleted_at` exists (soft-delete / archive) ──────
// Deleting a customer no longer wipes the row (which cascaded away their
// loans, payments, documents...). It just stamps deleted_at so the admin can
// RESTORE the customer from Archive & History if it was a mistake.
function ensureUserDeletedColumn($conn) {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $res = $conn->query("SHOW COLUMNS FROM users LIKE 'deleted_at'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL");
    }
}
ensureUserDeletedColumn($conn);

// ── Helper: make sure the e-signature columns exist on `loan_applications` ───
// Used by the Promissory Note / security-verification step of the loan
// application flow (customer_apply.php). Same self-healing pattern as
// ensureResetTokenColumns() above.
function ensureLoanAppSignatureColumns($conn) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $cols_res = $conn->query("SHOW COLUMNS FROM loan_applications");
    $cols = [];
    while ($col_row = $cols_res->fetch_assoc()) $cols[] = $col_row['Field'];

    if (!in_array('signed_name', $cols)) {
        $conn->query("ALTER TABLE loan_applications ADD COLUMN signed_name VARCHAR(150) NULL");
    }
    if (!in_array('signed_at', $cols)) {
        $conn->query("ALTER TABLE loan_applications ADD COLUMN signed_at DATETIME NULL");
    }
    if (!in_array('stated_monthly_income', $cols)) {
        $conn->query("ALTER TABLE loan_applications ADD COLUMN stated_monthly_income DECIMAL(15,2) NULL");
    }
}

// ── Helper: analyze an uploaded image for basic quality red flags ────────────
// This is a plausibility/heuristic check, NOT a true AI/OCR identity
// verification service (that would require a paid third-party KYC API such
// as Jumio/Onfido/AWS Rekognition, which isn't wired up here). What this
// DOES catch, server-side, mirroring the same check done client-side before
// upload:
//   - the file isn't actually a readable image (corrupt / not really a photo)
//   - resolution is too low to be a usable ID/selfie photo
//   - the image is statistically "flat" (very low edge variance), which is
//     a strong signal for an out-of-focus / blurry / blank photo
//   - for ID photos, the aspect ratio roughly matches a real ID card/passport
//     shape for the ID type the customer selected at registration, so a
//     random unrelated photo won't pass as a submitted "ID"
//
// Returns ['ok'=>bool, 'reason'=>string|null, 'width'=>int, 'height'=>int]
function analyzeImageQuality($filepath, $expectedRatioRange = null) {
    $info = @getimagesize($filepath);
    if (!$info) {
        return ['ok' => false, 'reason' => 'Could not read this image. Please upload a valid JPG or PNG photo.', 'width' => 0, 'height' => 0];
    }
    [$width, $height] = $info;

    if ($width < 480 || $height < 480) {
        return ['ok' => false, 'reason' => 'This image resolution is too low. Please upload a clearer, higher-resolution photo.', 'width' => $width, 'height' => $height];
    }

    if ($expectedRatioRange) {
        $ratio = max($width, $height) / max(1, min($width, $height));
        [$minR, $maxR] = $expectedRatioRange;
        if ($ratio < $minR || $ratio > $maxR) {
            return ['ok' => false, 'reason' => "This photo's shape doesn't look like a valid ID for the ID type you selected. Please upload a clear, straight-on photo of your actual ID card.", 'width' => $width, 'height' => $height];
        }
    }

    // Blur estimate via a coarse Laplacian-variance proxy on a downscaled
    // grayscale copy — cheap enough to run per-upload, good enough to catch
    // obviously out-of-focus or blank/solid-color images.
    if (extension_loaded('gd')) {
        $src = @imagecreatefromstring(file_get_contents($filepath));
        if ($src) {
            $maxDim = 200;
            $scale  = min(1, $maxDim / max($width, $height));
            $w2 = max(1, (int)round($width * $scale));
            $h2 = max(1, (int)round($height * $scale));
            $small = imagecreatetruecolor($w2, $h2);
            imagecopyresampled($small, $src, 0, 0, 0, 0, $w2, $h2, $width, $height);
            imagefilter($small, IMG_FILTER_GRAYSCALE);

            $sum = 0.0; $sumSq = 0.0; $count = 0;
            $gray = [];
            for ($y = 0; $y < $h2; $y++) {
                for ($x = 0; $x < $w2; $x++) {
                    $rgb = imagecolorat($small, $x, $y);
                    $gray[$y][$x] = $rgb & 0xFF; // R=G=B after grayscale filter
                }
            }
            for ($y = 1; $y < $h2 - 1; $y++) {
                for ($x = 1; $x < $w2 - 1; $x++) {
                    $lap = $gray[$y][$x-1] + $gray[$y][$x+1] + $gray[$y-1][$x] + $gray[$y+1][$x] - 4 * $gray[$y][$x];
                    $sum   += $lap;
                    $sumSq += $lap * $lap;
                    $count++;
                }
            }
            imagedestroy($small);
            imagedestroy($src);

            if ($count > 0) {
                $mean     = $sum / $count;
                $variance = ($sumSq / $count) - ($mean * $mean);
                if ($variance < 25) {
                    return ['ok' => false, 'reason' => 'This photo looks blurry, out of focus, or unclear. Please retake it in good lighting, holding the camera steady.', 'width' => $width, 'height' => $height];
                }
            }
        }
    }

    return ['ok' => true, 'reason' => null, 'width' => $width, 'height' => $height];
}

// ── VelBot Chatbot ────────────────────────────────────────────
// Get your API key from: https://console.anthropic.com
define('ANTHROPIC_API_KEY', 'YOUR_ANTHROPIC_API_KEY_HERE');

// ── Shared: expected aspect-ratio range per government ID type ───────────────
// Used to sanity-check that an uploaded "Government ID" photo is roughly the
// right shape for the ID type the customer selected at registration. This is
// a plausibility check (catches wrong/unrelated files), not true document
// authentication.
define('ID_RATIO_RANGES', [
    // One forgiving range for EVERY ID type. Real-world photos of an ID
    // (card on a table, slight angle, phone screenshot of a digital
    // PhilSys/National ID, passport data page, etc.) vary a lot in shape, so
    // the old per-type ranges wrongly rejected valid IDs (e.g. PhilSys/National
    // ID). This only screens out clearly wrong files -- blur/resolution checks
    // still apply.
    'PhilSys'            => [1.10, 2.40],
    "Driver's License"   => [1.10, 2.40],
    'UMID'               => [1.10, 2.40],
    'Postal ID'          => [1.10, 2.40],
    "Voter's ID"         => [1.10, 2.40],
    'Passport'           => [1.10, 2.40],
    '_default'           => [1.10, 2.40],
]);

// ── Automatic penalty generation ──────────────────────────────────────────────
// Replaces manually adding penalties one at a time. Scans active loans for
// overdue payments and creates a penalty automatically using Velveth's
// documented policy: ₱50/day for the first 5 days late, then +5% of the
// weekly payment on top of that once 5 days have passed. Safe to call on
// every relevant page load -- it only creates a new penalty when one
// doesn't already exist for that loan's current due date, so it never
// double-charges the same missed payment.
function autoGeneratePenalties($conn) {
    static $ran = false;
    if ($ran) return; // only run once per request
    $ran = true;

    $overdue = $conn->query("
        SELECT l.id AS loan_id, l.customer_id, l.monthly_payment, l.next_payment_date, la.app_id
        FROM loans l
        JOIN loan_applications la ON l.application_id = la.id
        WHERE l.status = 'active'
          AND l.next_payment_date IS NOT NULL
          AND l.next_payment_date < CURDATE()
    ");
    if (!$overdue) return;

    while ($loan = $overdue->fetch_assoc()) {
        $due_date = $loan['next_payment_date'];

        // Already have a penalty for this exact missed due date? Don't duplicate it.
        $exists = $conn->query("SELECT id FROM penalties WHERE loan_id={$loan['loan_id']} AND due_date='$due_date'")->fetch_assoc();
        if ($exists) continue;

        $days_late = (int)floor((strtotime('today') - strtotime($due_date)) / 86400);
        if ($days_late <= 0) continue;

        $weekly_payment = (float)$loan['monthly_payment'] / 4.33;
        if ($days_late <= 5) {
            $penalty_amount = 50 * $days_late;
            $reason = "Late payment — {$days_late} day(s) overdue (₱50/day)";
        } else {
            $penalty_amount = (50 * 5) + (0.05 * $weekly_payment);
            $reason = "Late payment — {$days_late} day(s) overdue (₱50/day for first 5 days + 5% of weekly payment)";
        }
        $penalty_amount = round($penalty_amount, 2);

        $pen_count  = (int)$conn->query("SELECT COUNT(*)+1 c FROM penalties")->fetch_assoc()['c'];
        $penalty_id = 'PEN-' . str_pad($pen_count, 3, '0', STR_PAD_LEFT);

        $stmt = $conn->prepare("INSERT INTO penalties (penalty_id, loan_id, customer_id, penalty_amount, reason, due_date, status) VALUES (?,?,?,?,?,?,'pending')");
        if (!$stmt) continue;
        $stmt->bind_param('siidss', $penalty_id, $loan['loan_id'], $loan['customer_id'], $penalty_amount, $reason, $due_date);
        $stmt->execute();

        $esc_reason = mysqli_real_escape_string($conn, $reason);
        $amt_fmt    = number_format($penalty_amount, 2);
        $conn->query("INSERT INTO notifications (user_id,title,message,type) VALUES ({$loan['customer_id']}, '⚠️ Penalty Issued', 'A penalty of ₱{$amt_fmt} has been automatically added for a late payment. {$esc_reason}', 'warning')");

        $cust = $conn->query("SELECT email, full_name FROM users WHERE id={$loan['customer_id']}")->fetch_assoc();
        if ($cust && function_exists('notifyPenaltyIssued')) {
            @notifyPenaltyIssued($cust['email'], $cust['full_name'], $penalty_id, $penalty_amount, $reason, $due_date, $loan['app_id']);
        }

        if (function_exists('logAudit')) {
            logAudit($conn, 'Penalty', 'System (Auto)', 'Auto-generated penalty', "{$penalty_id} for loan {$loan['app_id']} — {$reason}");
        }
    }
}

// ── Chat between borrower and admin ───────────────────────────────────────────
// One thread per customer -- any admin can read/reply, matching how a small
// lending company's support desk actually works (not one admin per
// customer). Self-healing table creation, same pattern as the rest of this
// file, so no manual migration step is needed.
function ensureMessagesTable($conn) {
    static $checked = false;
    static $ok = false;
    if ($checked) return $ok;
    $checked = true;

    $result = $conn->query("
        CREATE TABLE IF NOT EXISTS messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            sender_role ENUM('customer','admin') NOT NULL,
            sender_id INT NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES users(id)
        )
    ");

    if ($result === false) {
        // Some shared/free hosts restrict CREATE TABLE for the assigned DB
        // user. Log this clearly so it's actually diagnosable (check the
        // host's PHP error log) instead of every chat request silently
        // fatal-erroring on a missing table with no visible cause.
        error_log('[Velveth] ensureMessagesTable() failed: ' . $conn->error);
        $ok = false;
    } else {
        // Table statement succeeded, but double-check the table is really
        // there -- belt and suspenders for hosts with unusual DDL behavior.
        $check = $conn->query("SHOW TABLES LIKE 'messages'");
        $ok = ($check && $check->num_rows > 0);
        if (!$ok) {
            error_log('[Velveth] messages table still missing after CREATE TABLE IF NOT EXISTS -- check DB user privileges.');
        }
    }
    return $ok;
}

?>
