<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php"); exit;
}
$current_page = 'admin_reports.php';

// ── Handle Export (before HTML output) ──────────────────────────────────────
$export = $_GET['export'] ?? '';

if ($export === 'excel') {
    // ── EXCEL / CSV EXPORT ───────────────────────────────────────────────────
    $type = $_GET['type'] ?? 'payments';
    $fn   = 'velveth_' . $type . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fn.'"');
    $out = fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel

    if ($type === 'payments') {
        fputcsv($out, ['Payment ID','Customer','Email','Loan','Amount','Method','Date','Status','Notes']);
        $rows = $conn->query("
            SELECT p.payment_id, u.full_name, u.email, la.app_id,
                   p.amount, p.payment_method, p.payment_date, p.status, p.notes
            FROM payments p
            JOIN users u ON p.customer_id=u.id
            JOIN loans l ON p.loan_id=l.id
            JOIN loan_applications la ON l.application_id=la.id
            ORDER BY p.payment_date DESC
        ");
        while ($r = $rows->fetch_assoc()) {
            fputcsv($out, [
                $r['payment_id'], $r['full_name'], $r['email'], $r['app_id'],
                '₱'.number_format($r['amount'],2), ucfirst(str_replace('_',' ',$r['payment_method'])),
                date('M d, Y', strtotime($r['payment_date'])), ucfirst($r['status']), $r['notes']
            ]);
        }
    } elseif ($type === 'penalties') {
        fputcsv($out, ['Penalty ID','Customer','Email','Loan','Amount','Reason','Due Date','Status']);
        $rows = $conn->query("
            SELECT pen.penalty_id, u.full_name, u.email, la.app_id,
                   pen.penalty_amount, pen.reason, pen.due_date, pen.status
            FROM penalties pen
            JOIN users u ON pen.customer_id=u.id
            JOIN loans l ON pen.loan_id=l.id
            JOIN loan_applications la ON l.application_id=la.id
            ORDER BY pen.created_at DESC
        ");
        while ($r = $rows->fetch_assoc()) {
            fputcsv($out, [
                $r['penalty_id'], $r['full_name'], $r['email'], $r['app_id'],
                '₱'.number_format($r['penalty_amount'],2), $r['reason'],
                $r['due_date']?date('M d, Y',strtotime($r['due_date'])):'—', ucfirst($r['status'])
            ]);
        }
    } elseif ($type === 'customers') {
        fputcsv($out, ['ID','Full Name','Email','Phone','Address','Status','Joined','Total Loans','Total Paid']);
        $rows = $conn->query("
            SELECT u.id, u.full_name, u.email, u.phone, u.address, u.status, u.created_at,
                   COUNT(DISTINCT la.id) AS loan_count,
                   COALESCE(SUM(CASE WHEN p.status='confirmed' THEN p.amount ELSE 0 END),0) AS total_paid
            FROM users u
            LEFT JOIN loan_applications la ON la.customer_id=u.id
            LEFT JOIN payments p ON p.customer_id=u.id
            WHERE u.role='customer' AND u.deleted_at IS NULL
            GROUP BY u.id ORDER BY u.created_at DESC
        ");
        while ($r = $rows->fetch_assoc()) {
            fputcsv($out, [
                $r['id'], $r['full_name'], $r['email'], $r['phone']??'', $r['address']??'',
                ucfirst($r['status']), date('M d, Y',strtotime($r['created_at'])),
                $r['loan_count'], '₱'.number_format($r['total_paid'],2)
            ]);
        }
    } elseif ($type === 'loans') {
        fputcsv($out, ['App ID','Customer','Email','Loan Amount','Monthly Payment','Term','Interest','Amount Paid','Balance','Status','Start Date','Next Due']);
        $rows = $conn->query("
            SELECT la.app_id, u.full_name, u.email, la.loan_amount, la.monthly_payment,
                   la.loan_term, la.interest_rate, l.amount_paid, l.outstanding_balance,
                   l.status, l.start_date, l.next_payment_date
            FROM loan_applications la
            JOIN users u ON la.customer_id=u.id
            LEFT JOIN loans l ON l.application_id=la.id
            ORDER BY la.created_at DESC
        ");
        while ($r = $rows->fetch_assoc()) {
            fputcsv($out, [
                $r['app_id'], $r['full_name'], $r['email'],
                '₱'.number_format($r['loan_amount'],2),
                '₱'.number_format($r['monthly_payment']??0,2),
                ($r['loan_term']??'—').' months',
                ($r['interest_rate']??5).'%',
                '₱'.number_format($r['amount_paid']??0,2),
                '₱'.number_format($r['outstanding_balance']??$r['loan_amount'],2),
                ucfirst($r['status']??'—'),
                $r['start_date']?date('M d, Y',strtotime($r['start_date'])):'—',
                $r['next_payment_date']?date('M d, Y',strtotime($r['next_payment_date'])):'—',
            ]);
        }
    }
    fclose($out);
    logAudit($conn,'System',$_SESSION['fullname'],"Exported $type report","CSV/Excel download");
    exit;
}

if ($export === 'pdf') {
    // ── PDF EXPORT (pure PHP, no library needed) ─────────────────────────────
    $type = $_GET['type'] ?? 'payments';
    $fn   = 'velveth_' . $type . '_' . date('Ymd_His') . '.html';

    // Build a print-ready HTML page and trigger print dialog
    // (works in all browsers, user can save as PDF from print dialog)
    $title_map = [
        'payments'  => 'Payment Report',
        'penalties' => 'Penalties Report',
        'customers' => 'Customer Report',
        'loans'     => 'Loan Portfolio Report',
    ];
    $report_title = $title_map[$type] ?? 'Report';
    $gen_date     = date('F d, Y \a\t h:i A');

    ob_start();
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8"/>
<title><?= $report_title ?> — Velveth Lending</title>
<style>
  * { margin:0;padding:0;box-sizing:border-box; }
  body { font-family:Arial,sans-serif;font-size:12px;color:#222;padding:30px; }
  .header { display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #7a1e2e;padding-bottom:14px;margin-bottom:20px; }
  .header .logo { font-size:22px;font-weight:900;color:#7a1e2e; }
  .header .logo span { color:#e8aab4; }
  .header .meta { text-align:right;font-size:11px;color:#666; }
  h1 { font-size:18px;margin-bottom:4px;color:#7a1e2e; }
  .generated { font-size:11px;color:#888;margin-bottom:16px; }
  table { width:100%;border-collapse:collapse;margin-bottom:20px; }
  th { background:#7a1e2e;color:white;padding:8px 10px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px; }
  td { padding:8px 10px;border-bottom:1px solid #eee;font-size:12px; }
  tr:nth-child(even) td { background:#fdf5f6; }
  .status-active, .status-confirmed, .status-paid { color:#15803d;font-weight:700; }
  .status-pending { color:#a16207;font-weight:700; }
  .status-rejected, .status-failed, .status-unpaid { color:#b91c1c;font-weight:700; }
  .summary { display:flex;gap:20px;margin-bottom:18px;flex-wrap:wrap; }
  .sum-card { background:#fdf5f6;border:1px solid #f0c8ce;border-radius:6px;padding:12px 16px;min-width:140px; }
  .sum-val { font-size:22px;font-weight:900;color:#7a1e2e; }
  .sum-lbl { font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-top:2px; }
  .footer { border-top:1px solid #eee;padding-top:12px;text-align:center;font-size:11px;color:#aaa;margin-top:16px; }
  @media print {
    @page { margin:20mm; }
    .no-print { display:none !important; }
    body { padding:0; }
  }
</style>
</head>
<body>
<div class="header">
  <div>
    <div class="logo"><span>Velveth</span> Lending</div>
    <div style="font-size:11px;color:#888;">Velveth Lending Corporation</div>
  </div>
  <div class="meta">
    <div style="font-size:14px;font-weight:700;color:#7a1e2e;"><?= $report_title ?></div>
    <div>Generated: <?= $gen_date ?></div>
    <div>By: <?= htmlspecialchars($_SESSION['fullname']) ?></div>
  </div>
</div>

<div class="no-print" style="margin-bottom:16px;">
  <button onclick="window.print()" style="background:#7a1e2e;color:white;border:none;padding:10px 22px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;margin-right:8px;">🖨️ Print / Save as PDF</button>
  <button onclick="window.close()" style="background:#eee;border:none;padding:10px 18px;border-radius:6px;font-size:13px;cursor:pointer;">✕ Close</button>
</div>

<?php
    if ($type === 'payments'):
        $rows = $conn->query("SELECT p.*,u.full_name,la.app_id FROM payments p JOIN users u ON p.customer_id=u.id JOIN loans l ON p.loan_id=l.id JOIN loan_applications la ON l.application_id=la.id ORDER BY p.payment_date DESC");
        $total_amt  = (float)($conn->query("SELECT SUM(amount) s FROM payments WHERE status='confirmed'")->fetch_assoc()['s']??0);
        $total_cnt  = (int)$conn->query("SELECT COUNT(*) c FROM payments")->fetch_assoc()['c'];
        $pend_cnt   = (int)$conn->query("SELECT COUNT(*) c FROM payments WHERE status='pending'")->fetch_assoc()['c'];
?>
<div class="summary">
  <div class="sum-card"><div class="sum-val"><?= $total_cnt ?></div><div class="sum-lbl">Total Payments</div></div>
  <div class="sum-card"><div class="sum-val" style="color:#15803d;">₱<?= number_format($total_amt,2) ?></div><div class="sum-lbl">Total Confirmed</div></div>
  <div class="sum-card"><div class="sum-val" style="color:#a16207;"><?= $pend_cnt ?></div><div class="sum-lbl">Pending</div></div>
</div>
<table>
  <thead><tr><th>#</th><th>Pay ID</th><th>Customer</th><th>Loan</th><th>Amount</th><th>Method</th><th>Date</th><th>Status</th></tr></thead>
  <tbody>
  <?php $i=1; while($r=$rows->fetch_assoc()): ?>
  <tr>
    <td><?= $i++ ?></td>
    <td><strong><?= htmlspecialchars($r['payment_id']) ?></strong></td>
    <td><?= htmlspecialchars($r['full_name']) ?></td>
    <td><?= htmlspecialchars($r['app_id']) ?></td>
    <td><strong>₱<?= number_format($r['amount'],2) ?></strong></td>
    <td><?= ucwords(str_replace('_',' ',$r['payment_method'])) ?></td>
    <td><?= date('M d, Y',strtotime($r['payment_date'])) ?></td>
    <td><span class="status-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
  </tr>
  <?php endwhile; ?>
  </tbody>
</table>
<?php elseif ($type === 'penalties'):
        $rows = $conn->query("SELECT pen.*,u.full_name,la.app_id FROM penalties pen JOIN users u ON pen.customer_id=u.id JOIN loans l ON pen.loan_id=l.id JOIN loan_applications la ON l.application_id=la.id ORDER BY pen.created_at DESC");
        $sum_all  = (float)($conn->query("SELECT SUM(penalty_amount) s FROM penalties")->fetch_assoc()['s']??0);
        $sum_pend = (float)($conn->query("SELECT SUM(penalty_amount) s FROM penalties WHERE status='pending' OR status='unpaid'")->fetch_assoc()['s']??0);
        $cnt_paid = (int)$conn->query("SELECT COUNT(*) c FROM penalties WHERE status='paid'")->fetch_assoc()['c'];
?>
<div class="summary">
  <div class="sum-card"><div class="sum-val">₱<?= number_format($sum_all,2) ?></div><div class="sum-lbl">Total Penalties</div></div>
  <div class="sum-card"><div class="sum-val" style="color:#b91c1c;">₱<?= number_format($sum_pend,2) ?></div><div class="sum-lbl">Outstanding</div></div>
  <div class="sum-card"><div class="sum-val" style="color:#15803d;"><?= $cnt_paid ?></div><div class="sum-lbl">Paid</div></div>
</div>
<table>
  <thead><tr><th>#</th><th>Penalty ID</th><th>Customer</th><th>Loan</th><th>Amount</th><th>Reason</th><th>Due Date</th><th>Status</th></tr></thead>
  <tbody>
  <?php $i=1; while($r=$rows->fetch_assoc()): ?>
  <tr>
    <td><?= $i++ ?></td>
    <td><strong><?= htmlspecialchars($r['penalty_id']??'#'.$r['id']) ?></strong></td>
    <td><?= htmlspecialchars($r['full_name']) ?></td>
    <td><?= htmlspecialchars($r['app_id']) ?></td>
    <td style="color:#b91c1c;font-weight:700;">₱<?= number_format($r['penalty_amount'],2) ?></td>
    <td><?= htmlspecialchars($r['reason']??'—') ?></td>
    <td><?= $r['due_date']?date('M d, Y',strtotime($r['due_date'])):'—' ?></td>
    <td><span class="status-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
  </tr>
  <?php endwhile; ?>
  </tbody>
</table>
<?php elseif ($type === 'customers'):
        $rows = $conn->query("SELECT u.*,COUNT(DISTINCT la.id) AS loan_count, COALESCE(SUM(CASE WHEN p.status='confirmed' THEN p.amount ELSE 0 END),0) AS total_paid FROM users u LEFT JOIN loan_applications la ON la.customer_id=u.id LEFT JOIN payments p ON p.customer_id=u.id WHERE u.role='customer' AND u.deleted_at IS NULL GROUP BY u.id ORDER BY u.created_at DESC");
        $total_c  = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='customer' AND deleted_at IS NULL")->fetch_assoc()['c'];
        $active_c = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='customer' AND deleted_at IS NULL AND status='active'")->fetch_assoc()['c'];
?>
<div class="summary">
  <div class="sum-card"><div class="sum-val"><?= $total_c ?></div><div class="sum-lbl">Total Customers</div></div>
  <div class="sum-card"><div class="sum-val" style="color:#15803d;"><?= $active_c ?></div><div class="sum-lbl">Active</div></div>
</div>
<table>
  <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Status</th><th>Loans</th><th>Total Paid</th><th>Joined</th></tr></thead>
  <tbody>
  <?php $i=1; while($r=$rows->fetch_assoc()): ?>
  <tr>
    <td><?= $i++ ?></td>
    <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
    <td><?= htmlspecialchars($r['email']) ?></td>
    <td><?= htmlspecialchars($r['phone']??'—') ?></td>
    <td><span class="status-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
    <td style="text-align:center;"><?= $r['loan_count'] ?></td>
    <td>₱<?= number_format($r['total_paid'],2) ?></td>
    <td><?= date('M d, Y',strtotime($r['created_at'])) ?></td>
  </tr>
  <?php endwhile; ?>
  </tbody>
</table>
<?php elseif ($type === 'loans'):
        $rows = $conn->query("SELECT la.*,u.full_name,l.amount_paid,l.outstanding_balance,l.status AS loan_status,l.next_payment_date,l.start_date FROM loan_applications la JOIN users u ON la.customer_id=u.id LEFT JOIN loans l ON l.application_id=la.id ORDER BY la.created_at DESC");
        $active_l = (int)$conn->query("SELECT COUNT(*) c FROM loans WHERE status='active'")->fetch_assoc()['c'];
        $total_port= (float)($conn->query("SELECT SUM(outstanding_balance) s FROM loans WHERE status='active'")->fetch_assoc()['s']??0);
        $total_coll= (float)($conn->query("SELECT SUM(amount) s FROM payments WHERE status='confirmed'")->fetch_assoc()['s']??0);
?>
<div class="summary">
  <div class="sum-card"><div class="sum-val"><?= $active_l ?></div><div class="sum-lbl">Active Loans</div></div>
  <div class="sum-card"><div class="sum-val" style="color:#7a1e2e;">₱<?= number_format($total_port/1000,1) ?>K</div><div class="sum-lbl">Portfolio Balance</div></div>
  <div class="sum-card"><div class="sum-val" style="color:#15803d;">₱<?= number_format($total_coll/1000,1) ?>K</div><div class="sum-lbl">Total Collected</div></div>
</div>
<table>
  <thead><tr><th>#</th><th>App ID</th><th>Customer</th><th>Loan Amount</th><th>Monthly</th><th>Paid</th><th>Balance</th><th>Status</th><th>Next Due</th></tr></thead>
  <tbody>
  <?php $i=1; while($r=$rows->fetch_assoc()): ?>
  <tr>
    <td><?= $i++ ?></td>
    <td><strong><?= htmlspecialchars($r['app_id']) ?></strong></td>
    <td><?= htmlspecialchars($r['full_name']) ?></td>
    <td>₱<?= number_format($r['loan_amount'],2) ?></td>
    <td>₱<?= number_format($r['monthly_payment']??0,2) ?></td>
    <td style="color:#15803d;font-weight:700;">₱<?= number_format($r['amount_paid']??0,2) ?></td>
    <td style="color:#b91c1c;font-weight:700;">₱<?= number_format($r['outstanding_balance']??$r['loan_amount'],2) ?></td>
    <td><span class="status-<?= $r['loan_status']??$r['status'] ?>"><?= ucfirst($r['loan_status']??$r['status']) ?></span></td>
    <td><?= $r['next_payment_date']?date('M d, Y',strtotime($r['next_payment_date'])):'—' ?></td>
  </tr>
  <?php endwhile; ?>
  </tbody>
</table>
<?php endif; ?>

<div class="footer">Velveth Lending Corporation · Generated <?= $gen_date ?> · Page 1</div>
<script>window.onload = function(){ window.print(); };</script>
  <script src="realtime.js"></script>
</body>
</html>
    <?php
    $html = ob_get_clean();
    logAudit($conn,'System',$_SESSION['fullname'],"Exported $type PDF report","Print/PDF download");
    echo $html;
    exit;
}
// ── Regular page data ─────────────────────────────────────────────────────────
// ── Collections filter: Daily / Weekly / Monthly / Yearly ────────────────────
$period = $_GET['period'] ?? 'monthly';
if (!in_array($period, ['daily','weekly','monthly','yearly'], true)) $period = 'monthly';
$period_cfg = [
    'daily'   => ['title'=>'Daily Collections',   'sub'=>'last 30 days',  'unit'=>'day',   'sql'=>"DATE(payment_date)",            'label'=>"DATE_FORMAT(MIN(payment_date),'%b %e')",                 'limit'=>30],
    'weekly'  => ['title'=>'Weekly Collections',  'sub'=>'last 12 weeks', 'unit'=>'week',  'sql'=>"YEARWEEK(payment_date,1)",      'label'=>"CONCAT('Wk ', DATE_FORMAT(MIN(payment_date),'%b %e'))",  'limit'=>12],
    'monthly' => ['title'=>'Monthly Collections', 'sub'=>'last 12 months','unit'=>'month', 'sql'=>"DATE_FORMAT(payment_date,'%Y-%m')", 'label'=>"DATE_FORMAT(MIN(payment_date),'%M %Y')",             'limit'=>12],
    'yearly'  => ['title'=>'Yearly Collections',  'sub'=>'all years',     'unit'=>'year',  'sql'=>"YEAR(payment_date)",            'label'=>"DATE_FORMAT(MIN(payment_date),'%Y')",                    'limit'=>10],
][$period];
$monthly_payments_res = $conn->query("
    SELECT {$period_cfg['sql']} AS grp,
           {$period_cfg['label']} AS label,
           SUM(amount) AS total, COUNT(*) AS cnt
    FROM payments WHERE status='confirmed'
    GROUP BY grp ORDER BY grp DESC LIMIT {$period_cfg['limit']}
");
$monthly_payments = [];
while ($r = $monthly_payments_res->fetch_assoc()) $monthly_payments[] = $r;
// Quick insights for the selected period
$period_total = 0; $period_top = null;
foreach ($monthly_payments as $r) {
    $period_total += (float)$r['total'];
    if ($period_top === null || (float)$r['total'] > (float)$period_top['total']) $period_top = $r;
}
$period_avg = count($monthly_payments) ? $period_total / count($monthly_payments) : 0;

$loan_summary_res = $conn->query("SELECT status,COUNT(*) cnt,SUM(loan_amount) total FROM loan_applications GROUP BY status ORDER BY FIELD(status,'active','approved','pending','completed','rejected')");
$loan_summary = [];
while ($r = $loan_summary_res->fetch_assoc()) $loan_summary[] = $r;

$penalty_summary_res = $conn->query("SELECT status,COUNT(*) cnt,SUM(penalty_amount) total FROM penalties GROUP BY status");
$penalty_summary = [];
while ($r = $penalty_summary_res->fetch_assoc()) $penalty_summary[] = $r;

$customer_summary_res = $conn->query("SELECT status,COUNT(*) cnt FROM users WHERE role='customer' AND deleted_at IS NULL GROUP BY status");
$customer_summary = [];
while ($r = $customer_summary_res->fetch_assoc()) $customer_summary[] = $r;

// Compact preview rows for the "Preview" sheet — same shape as the exports, just LIMIT 6
$preview_payments = [];
$pr = $conn->query("SELECT u.full_name, p.amount, p.payment_date, p.status FROM payments p JOIN users u ON p.customer_id=u.id ORDER BY p.payment_date DESC LIMIT 6");
while ($r = $pr->fetch_assoc()) $preview_payments[] = $r;

$preview_loans = [];
$pr = $conn->query("SELECT la.app_id, u.full_name, la.loan_amount, la.status FROM loan_applications la JOIN users u ON la.customer_id=u.id ORDER BY la.created_at DESC LIMIT 6");
while ($r = $pr->fetch_assoc()) $preview_loans[] = $r;

$preview_penalties = [];
$pr = $conn->query("SELECT u.full_name, pen.penalty_amount, pen.status FROM penalties pen JOIN users u ON pen.customer_id=u.id ORDER BY pen.created_at DESC LIMIT 6");
while ($r = $pr->fetch_assoc()) $preview_penalties[] = $r;

$preview_customers = [];
$pr = $conn->query("SELECT full_name, email, status, created_at FROM users WHERE role='customer' AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 6");
while ($r = $pr->fetch_assoc()) $preview_customers[] = $r;

// ── Tiny dependency-free SVG bar chart renderer ─────────────────────────────
// No external chart library / CDN required — safe for any host (InfinityFree,
// shared hosting, offline LAN demo, etc.) since everything is generated
// server-side as plain inline SVG. Uses only core PHP (no mbstring dependency).
function _chartTruncate($str, $len) {
    return strlen($str) > $len ? substr($str, 0, $len - 1) . '…' : $str;
}
function renderBarChart($rows, $labelKey, $valueKey, $colorMap = [], $defaultColor = '#7a1e2e', $height = 150) {
    if (empty($rows)) return '<div style="text-align:center;color:var(--gray-400);font-size:12px;padding:20px 0;">No data yet</div>';
    $max = 0;
    foreach ($rows as $r) $max = max($max, (float)($r[$valueKey] ?? 0));
    if ($max <= 0) $max = 1;

    $barW  = 46;
    $gap   = 14;
    $w     = count($rows) * ($barW + $gap) + $gap;
    $chartH = $height;
    $labelH = 34;
    $svgH  = $chartH + $labelH;

    $bars = '';
    $x = $gap;
    foreach ($rows as $r) {
        $val   = (float)($r[$valueKey] ?? 0);
        $label = (string)($r[$labelKey] ?? '');
        $barH  = $max > 0 ? max(3, round(($val / $max) * ($chartH - 24))) : 3;
        $y     = $chartH - $barH;
        $color = $colorMap[$label] ?? $defaultColor;
        $shortLabel = htmlspecialchars(_chartTruncate($label, 9));
        $valLabel   = $val >= 1000 ? number_format($val/1000,1).'K' : number_format($val,0);
        $bars .= "<g>
            <rect x='{$x}' y='{$y}' width='{$barW}' height='{$barH}' rx='6' fill='{$color}'/>
            <text x='" . ($x + $barW/2) . "' y='" . ($y - 6) . "' text-anchor='middle' font-size='10' font-weight='700' fill='#4a3538'>{$valLabel}</text>
            <text x='" . ($x + $barW/2) . "' y='" . ($chartH + 16) . "' text-anchor='middle' font-size='9.5' fill='#a89899'>{$shortLabel}</text>
        </g>";
        $x += $barW + $gap;
    }

    return "<div style='overflow-x:auto;'><svg width='{$w}' height='{$svgH}' viewBox='0 0 {$w} {$svgH}' xmlns='http://www.w3.org/2000/svg' style='display:block;min-width:100%;'>
        <line x1='0' y1='{$chartH}' x2='{$w}' y2='{$chartH}' stroke='#e8e2e3' stroke-width='1.5'/>
        {$bars}
    </svg></div>";
}

$STATUS_COLORS = [
    'active'=>'#10b981','approved'=>'#10b981','confirmed'=>'#10b981','verified'=>'#10b981','completed'=>'#3b82f6','paid'=>'#10b981',
    'pending'=>'#f59e0b','unpaid'=>'#f59e0b',
    'rejected'=>'#ef4444','failed'=>'#ef4444','suspended'=>'#ef4444','inactive'=>'#a89899',
];

$total_collected = (float)($conn->query("SELECT SUM(amount) s FROM payments WHERE status='confirmed'")->fetch_assoc()['s']??0);
$total_active    = (int)$conn->query("SELECT COUNT(*) c FROM loans WHERE status='active'")->fetch_assoc()['c'];
$total_customers = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='customer' AND deleted_at IS NULL")->fetch_assoc()['c'];
$outstanding     = (float)($conn->query("SELECT SUM(outstanding_balance) s FROM loans WHERE status='active'")->fetch_assoc()['s']??0);

$_cur = 'admin_reports.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Reports | Velveth Admin</title>
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
    .export-row-app { display:flex; gap:8px; margin-top:10px; }
  </style>
</head>
<body class="app-shell">

  <header class="app-bar maroon">
    <a href="admin_dashboard.php" class="app-bar-back">←</a>
    <div style="flex:1;min-width:0;">
      <div class="app-bar-title">Reports &amp; Analytics</div>
      <div class="app-bar-sub">Operations overview &amp; exports</div>
    </div>
  </header>

  <main class="app-screen">

    <!-- KPIs -->
    <div class="stat-chip-row">
      <div class="stat-chip"><div class="stat-chip-val" style="color:#10b981;">₱<?= number_format($total_collected/1000,1) ?>K</div><div class="stat-chip-label">Total Collected</div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:var(--maroon);"><?= $total_active ?></div><div class="stat-chip-label">Active Loans</div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:#f59e0b;">₱<?= number_format($outstanding/1000,1) ?>K</div><div class="stat-chip-label">Outstanding</div></div>
      <div class="stat-chip"><div class="stat-chip-val"><?= $total_customers ?></div><div class="stat-chip-label">Customers</div></div>
    </div>

    <!-- Collections (filterable) -->
    <div class="app-section-title">
      <span>📅 <?= $period_cfg['title'] ?></span>
      <a href="?export=excel&type=payments" class="see-all">Export →</a>
    </div>
    <div class="seg-tabs">
      <?php foreach (['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','yearly'=>'Yearly'] as $k=>$lbl): ?>
        <a href="?period=<?= $k ?>" class="seg-tab <?= $period===$k?'active':'' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
    <?php if ($period_top): ?>
    <div class="stat-chip-row">
      <div class="stat-chip"><div class="stat-chip-val" style="color:#10b981;">₱<?= number_format($period_total,2) ?></div><div class="stat-chip-label">Total (<?= $period_cfg['sub'] ?>)</div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:var(--maroon);font-size:15px;">₱<?= number_format($period_top['total'],2) ?></div><div class="stat-chip-label">Highest <?= $period_cfg['unit'] ?>: <?= htmlspecialchars($period_top['label']) ?></div></div>
      <div class="stat-chip"><div class="stat-chip-val" style="color:#f59e0b;">₱<?= number_format($period_avg,2) ?></div><div class="stat-chip-label">Avg per <?= $period_cfg['unit'] ?></div></div>
    </div>
    <?php endif; ?>
    <div class="app-card-flat">
      <?php
      // Chart shows oldest→newest left-to-right, so reverse the DESC-ordered rows
      echo renderBarChart(array_reverse($monthly_payments), 'label', 'total', [], '#10b981');
      ?>
    </div>
    <div class="app-card-flat">
      <?php if (empty($monthly_payments)): ?>
        <div class="app-empty"><div class="app-empty-icon">📅</div><div class="app-empty-title">No confirmed payments yet</div></div>
      <?php else: foreach ($monthly_payments as $row): ?>
        <div class="app-list-item" style="cursor:default;">
          <div class="app-list-body">
            <div class="app-list-title"><?= $row['label'] ?></div>
            <div class="app-list-sub"><?= $row['cnt'] ?> payment<?= $row['cnt']!=1?'s':'' ?></div>
          </div>
          <div class="app-list-end"><div class="app-list-amount" style="color:#10b981;">₱<?= number_format($row['total'],2) ?></div></div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Loan Status Summary -->
    <div class="app-section-title">
      <span>📋 Loan Applications by Status</span>
      <a href="?export=excel&type=loans" class="see-all">Export →</a>
    </div>
    <div class="app-card-flat">
      <?php echo renderBarChart($loan_summary, 'status', 'cnt', $STATUS_COLORS); ?>
    </div>
    <div class="app-card-flat">
      <?php if (empty($loan_summary)): ?>
        <div class="app-empty"><div class="app-empty-icon">📋</div><div class="app-empty-title">No applications yet</div></div>
      <?php else: foreach ($loan_summary as $row): ?>
        <div class="app-list-item" style="cursor:default;">
          <div class="app-list-body">
            <span class="pill <?= $row['status'] ?>"><?= ucfirst($row['status']) ?></span>
            <div class="app-list-sub" style="margin-top:5px;"><?= $row['cnt'] ?> application<?= $row['cnt']!=1?'s':'' ?></div>
          </div>
          <div class="app-list-end"><div class="app-list-amount">₱<?= number_format($row['total']??0,2) ?></div></div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Penalty Summary -->
    <div class="app-section-title">
      <span>⚠️ Penalties Summary</span>
      <a href="?export=excel&type=penalties" class="see-all">Export →</a>
    </div>
    <div class="app-card-flat">
      <?php echo renderBarChart($penalty_summary, 'status', 'cnt', $STATUS_COLORS, '#ef4444'); ?>
    </div>
    <div class="app-card-flat">
      <?php if (empty($penalty_summary)): ?>
        <div class="app-empty"><div class="app-empty-icon">⚠️</div><div class="app-empty-title">No penalties issued yet</div></div>
      <?php else: foreach ($penalty_summary as $row): ?>
        <div class="app-list-item" style="cursor:default;">
          <div class="app-list-body">
            <span class="pill <?= $row['status'] ?>"><?= ucfirst($row['status']) ?></span>
            <div class="app-list-sub" style="margin-top:5px;"><?= $row['cnt'] ?> record<?= $row['cnt']!=1?'s':'' ?></div>
          </div>
          <div class="app-list-end"><div class="app-list-amount" style="color:<?= $row['status']==='paid'?'#15803d':'#ef4444' ?>;">₱<?= number_format($row['total']??0,2) ?></div></div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Customer Status Summary -->
    <div class="app-section-title">
      <span>👥 Customers by Status</span>
      <a href="?export=excel&type=customers" class="see-all">Export →</a>
    </div>
    <div class="app-card-flat">
      <?php echo renderBarChart($customer_summary, 'status', 'cnt', $STATUS_COLORS, '#3b82f6'); ?>
    </div>

    <!-- Export Section -->
    <div class="app-section-title"><span>📥 Export Reports</span></div>

    <div class="app-card">
      <h4 style="font-size:14px;font-weight:800;margin-bottom:3px;">💳 Payments Report</h4>
      <p style="font-size:11.5px;color:var(--gray-400);margin-bottom:0;line-height:1.5;">Confirmed &amp; pending payments — names, methods, dates, amounts.</p>
      <div class="export-row-app">
        <button type="button" onclick="openSheet('preview_payments')" class="app-btn app-btn-sm" style="flex:1;background:var(--gray-100);color:var(--text-dark);"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>Preview</button>
        <a href="?export=excel&type=payments" class="app-btn app-btn-sm" style="flex:1;background:#16a34a;color:white;">📊 CSV</a>
        <a href="?export=pdf&type=payments" target="_blank" class="app-btn app-btn-sm" style="flex:1;background:#dc2626;color:white;">📄 PDF</a>
      </div>
    </div>

    <div class="app-card">
      <h4 style="font-size:14px;font-weight:800;margin-bottom:3px;">🏦 Loan Portfolio Report</h4>
      <p style="font-size:11.5px;color:var(--gray-400);margin-bottom:0;line-height:1.5;">All applications — amount, monthly payment, balance, status.</p>
      <div class="export-row-app">
        <button type="button" onclick="openSheet('preview_loans')" class="app-btn app-btn-sm" style="flex:1;background:var(--gray-100);color:var(--text-dark);"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>Preview</button>
        <a href="?export=excel&type=loans" class="app-btn app-btn-sm" style="flex:1;background:#16a34a;color:white;">📊 CSV</a>
        <a href="?export=pdf&type=loans" target="_blank" class="app-btn app-btn-sm" style="flex:1;background:#dc2626;color:white;">📄 PDF</a>
      </div>
    </div>

    <div class="app-card">
      <h4 style="font-size:14px;font-weight:800;margin-bottom:3px;">⚠️ Penalties Report</h4>
      <p style="font-size:11.5px;color:var(--gray-400);margin-bottom:0;line-height:1.5;">Issued penalties — customer, reason, due date, payment status.</p>
      <div class="export-row-app">
        <button type="button" onclick="openSheet('preview_penalties')" class="app-btn app-btn-sm" style="flex:1;background:var(--gray-100);color:var(--text-dark);"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>Preview</button>
        <a href="?export=excel&type=penalties" class="app-btn app-btn-sm" style="flex:1;background:#16a34a;color:white;">📊 CSV</a>
        <a href="?export=pdf&type=penalties" target="_blank" class="app-btn app-btn-sm" style="flex:1;background:#dc2626;color:white;">📄 PDF</a>
      </div>
    </div>

    <div class="app-card">
      <h4 style="font-size:14px;font-weight:800;margin-bottom:3px;">👥 Customer Report</h4>
      <p style="font-size:11.5px;color:var(--gray-400);margin-bottom:0;line-height:1.5;">Full customer list — contact, loan count, total paid, status.</p>
      <div class="export-row-app">
        <button type="button" onclick="openSheet('preview_customers')" class="app-btn app-btn-sm" style="flex:1;background:var(--gray-100);color:var(--text-dark);"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>Preview</button>
        <a href="?export=excel&type=customers" class="app-btn app-btn-sm" style="flex:1;background:#16a34a;color:white;">📊 CSV</a>
        <a href="?export=pdf&type=customers" target="_blank" class="app-btn app-btn-sm" style="flex:1;background:#dc2626;color:white;">📄 PDF</a>
      </div>
    </div>

  </main>

  <!-- ═══ PREVIEW SHEETS ═══ -->
  <div class="sheet-overlay" id="ov_preview_payments" onclick="closeSheet('preview_payments')"></div>
  <div class="bottom-sheet" id="preview_payments" style="max-height:80vh;overflow-y:auto;">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">💳 Payments Report Preview</div>
      <button class="sheet-close" onclick="closeSheet('preview_payments')">✕</button>
    </div>
    <div class="sheet-body">
      <?= renderBarChart(array_reverse($monthly_payments), 'label', 'total', [], '#10b981') ?>
      <div style="font-size:11px;font-weight:800;color:var(--gray-400);text-transform:uppercase;letter-spacing:.5px;margin:14px 0 8px;">Most Recent (sample)</div>
      <?php foreach ($preview_payments as $r): ?>
        <div class="app-list-item" style="cursor:default;padding:9px 0;">
          <div class="app-list-body">
            <div class="app-list-title" style="font-size:12.5px;"><?= htmlspecialchars($r['full_name']) ?></div>
            <div class="app-list-sub"><?= date('M d, Y', strtotime($r['payment_date'])) ?> · <span class="pill <?= $r['status'] ?>" style="font-size:9px;"><?= ucfirst($r['status']) ?></span></div>
          </div>
          <div class="app-list-end"><div class="app-list-amount">₱<?= number_format($r['amount'],2) ?></div></div>
        </div>
      <?php endforeach; if (empty($preview_payments)): ?>
        <div class="app-empty" style="padding:20px;"><div class="app-empty-title">No payments yet</div></div>
      <?php endif; ?>
      <div class="export-row-app" style="margin-top:16px;">
        <a href="?export=excel&type=payments" class="app-btn app-btn-sm" style="flex:1;background:#16a34a;color:white;">📊 Download CSV</a>
        <a href="?export=pdf&type=payments" target="_blank" class="app-btn app-btn-sm" style="flex:1;background:#dc2626;color:white;">📄 Download PDF</a>
      </div>
    </div>
  </div>

  <div class="sheet-overlay" id="ov_preview_loans" onclick="closeSheet('preview_loans')"></div>
  <div class="bottom-sheet" id="preview_loans" style="max-height:80vh;overflow-y:auto;">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">🏦 Loan Portfolio Preview</div>
      <button class="sheet-close" onclick="closeSheet('preview_loans')">✕</button>
    </div>
    <div class="sheet-body">
      <?= renderBarChart($loan_summary, 'status', 'cnt', $STATUS_COLORS) ?>
      <div style="font-size:11px;font-weight:800;color:var(--gray-400);text-transform:uppercase;letter-spacing:.5px;margin:14px 0 8px;">Most Recent (sample)</div>
      <?php foreach ($preview_loans as $r): ?>
        <div class="app-list-item" style="cursor:default;padding:9px 0;">
          <div class="app-list-body">
            <div class="app-list-title" style="font-size:12.5px;"><?= htmlspecialchars($r['app_id']) ?> — <?= htmlspecialchars($r['full_name']) ?></div>
            <div class="app-list-sub"><span class="pill <?= $r['status'] ?>" style="font-size:9px;"><?= ucfirst($r['status']) ?></span></div>
          </div>
          <div class="app-list-end"><div class="app-list-amount">₱<?= number_format($r['loan_amount'],2) ?></div></div>
        </div>
      <?php endforeach; if (empty($preview_loans)): ?>
        <div class="app-empty" style="padding:20px;"><div class="app-empty-title">No applications yet</div></div>
      <?php endif; ?>
      <div class="export-row-app" style="margin-top:16px;">
        <a href="?export=excel&type=loans" class="app-btn app-btn-sm" style="flex:1;background:#16a34a;color:white;">📊 Download CSV</a>
        <a href="?export=pdf&type=loans" target="_blank" class="app-btn app-btn-sm" style="flex:1;background:#dc2626;color:white;">📄 Download PDF</a>
      </div>
    </div>
  </div>

  <div class="sheet-overlay" id="ov_preview_penalties" onclick="closeSheet('preview_penalties')"></div>
  <div class="bottom-sheet" id="preview_penalties" style="max-height:80vh;overflow-y:auto;">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">⚠️ Penalties Report Preview</div>
      <button class="sheet-close" onclick="closeSheet('preview_penalties')">✕</button>
    </div>
    <div class="sheet-body">
      <?= renderBarChart($penalty_summary, 'status', 'cnt', $STATUS_COLORS, '#ef4444') ?>
      <div style="font-size:11px;font-weight:800;color:var(--gray-400);text-transform:uppercase;letter-spacing:.5px;margin:14px 0 8px;">Most Recent (sample)</div>
      <?php foreach ($preview_penalties as $r): ?>
        <div class="app-list-item" style="cursor:default;padding:9px 0;">
          <div class="app-list-body">
            <div class="app-list-title" style="font-size:12.5px;"><?= htmlspecialchars($r['full_name']) ?></div>
            <div class="app-list-sub"><span class="pill <?= $r['status'] ?>" style="font-size:9px;"><?= ucfirst($r['status']) ?></span></div>
          </div>
          <div class="app-list-end"><div class="app-list-amount" style="color:#ef4444;">₱<?= number_format($r['penalty_amount'],2) ?></div></div>
        </div>
      <?php endforeach; if (empty($preview_penalties)): ?>
        <div class="app-empty" style="padding:20px;"><div class="app-empty-title">No penalties yet</div></div>
      <?php endif; ?>
      <div class="export-row-app" style="margin-top:16px;">
        <a href="?export=excel&type=penalties" class="app-btn app-btn-sm" style="flex:1;background:#16a34a;color:white;">📊 Download CSV</a>
        <a href="?export=pdf&type=penalties" target="_blank" class="app-btn app-btn-sm" style="flex:1;background:#dc2626;color:white;">📄 Download PDF</a>
      </div>
    </div>
  </div>

  <div class="sheet-overlay" id="ov_preview_customers" onclick="closeSheet('preview_customers')"></div>
  <div class="bottom-sheet" id="preview_customers" style="max-height:80vh;overflow-y:auto;">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">👥 Customer Report Preview</div>
      <button class="sheet-close" onclick="closeSheet('preview_customers')">✕</button>
    </div>
    <div class="sheet-body">
      <?= renderBarChart($customer_summary, 'status', 'cnt', $STATUS_COLORS, '#3b82f6') ?>
      <div style="font-size:11px;font-weight:800;color:var(--gray-400);text-transform:uppercase;letter-spacing:.5px;margin:14px 0 8px;">Most Recent (sample)</div>
      <?php foreach ($preview_customers as $r): ?>
        <div class="app-list-item" style="cursor:default;padding:9px 0;">
          <div class="app-list-body">
            <div class="app-list-title" style="font-size:12.5px;"><?= htmlspecialchars($r['full_name']) ?></div>
            <div class="app-list-sub"><?= htmlspecialchars($r['email']) ?> · <span class="pill <?= $r['status'] ?>" style="font-size:9px;"><?= ucfirst($r['status']) ?></span></div>
          </div>
        </div>
      <?php endforeach; if (empty($preview_customers)): ?>
        <div class="app-empty" style="padding:20px;"><div class="app-empty-title">No customers yet</div></div>
      <?php endif; ?>
      <div class="export-row-app" style="margin-top:16px;">
        <a href="?export=excel&type=customers" class="app-btn app-btn-sm" style="flex:1;background:#16a34a;color:white;">📊 Download CSV</a>
        <a href="?export=pdf&type=customers" target="_blank" class="app-btn app-btn-sm" style="flex:1;background:#dc2626;color:white;">📄 Download PDF</a>
      </div>
    </div>
  </div>

  <?php include 'admin_tabbar.php'; ?>
  <script>
    function openSheet(id)  { document.getElementById('ov_'+id)?.classList.add('open');    document.getElementById(id)?.classList.add('open'); }
    function closeSheet(id) { document.getElementById('ov_'+id)?.classList.remove('open'); document.getElementById(id)?.classList.remove('open'); }
  </script>
  <script src="realtime.js"></script>
</body>
</html>
