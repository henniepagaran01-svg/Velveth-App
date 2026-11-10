<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json');
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['error'=>'unauthorized']); exit;
}
$loan_id = (int)($_GET['loan_id'] ?? 0);
if (!$loan_id) { echo json_encode(['error'=>'no loan_id']); exit; }

$loan = $conn->query("
    SELECT l.*, la.app_id, la.purpose, la.loan_term, la.interest_rate, la.total_payable,
           u.full_name, u.email, u.phone
    FROM loans l
    JOIN loan_applications la ON l.application_id = la.id
    JOIN users u ON l.customer_id = u.id
    WHERE l.id = $loan_id
")->fetch_assoc();

if (!$loan) { echo json_encode(['error'=>'not found']); exit; }

// Payment history
$payments = $conn->query("SELECT * FROM payments WHERE loan_id=$loan_id ORDER BY payment_date DESC");
$pay_arr = [];
while ($p = $payments->fetch_assoc()) $pay_arr[] = $p;

// Penalties
$pens = $conn->query("SELECT * FROM penalties WHERE loan_id=$loan_id ORDER BY created_at DESC");
$pen_arr = [];
$pen_total = 0;
while ($p = $pens->fetch_assoc()) { $pen_arr[] = $p; if(in_array($p['status'],['pending','unpaid'])) $pen_total += $p['penalty_amount']; }

// Computation
$pct = $loan['loan_amount'] > 0 ? min(100, round(($loan['amount_paid']/$loan['loan_amount'])*100)) : 0;
$days_overdue = 0;
$is_overdue   = false;
if ($loan['next_payment_date']) {
    $diff = (new DateTime())->diff(new DateTime($loan['next_payment_date']));
    if ($loan['next_payment_date'] < date('Y-m-d')) {
        $days_overdue = $diff->days;
        $is_overdue   = true;
    }
}

// Penalty eligibility: overdue > 0 days
$auto_penalty_amt = 0;
if ($is_overdue) {
    if ($days_overdue <= 5) $auto_penalty_amt = $days_overdue * 50;
    else $auto_penalty_amt = (5*50) + (($days_overdue-5) * ($loan['monthly_payment'] * 0.05));
}

echo json_encode([
    'loan'          => $loan,
    'payments'      => $pay_arr,
    'penalties'     => $pen_arr,
    'penalty_total' => $pen_total,
    'progress_pct'  => $pct,
    'is_overdue'    => $is_overdue,
    'days_overdue'  => $days_overdue,
    'auto_penalty'  => round($auto_penalty_amt, 2),
    'total_due'     => (float)$loan['outstanding_balance'] + $pen_total,
]);
