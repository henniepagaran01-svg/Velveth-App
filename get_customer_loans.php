<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$cid = (int)($_GET['customer_id'] ?? 0);
if (!$cid) {
    echo json_encode([]);
    exit;
}

// Get all loans for this customer (not just active ones)
$result = $conn->query("
    SELECT l.id, la.app_id, la.loan_amount, l.outstanding_balance,
           l.status, l.amount_paid
    FROM loans l
    JOIN loan_applications la ON l.application_id = la.id
    WHERE l.customer_id = $cid
    ORDER BY l.status = 'active' DESC, l.created_at DESC
");

$loans = [];
if ($result) {
    while ($r = $result->fetch_assoc()) {
        $loans[] = [
            'id'                  => (int)$r['id'],
            'app_id'              => $r['app_id'],
            'loan_amount'         => (float)$r['loan_amount'],
            'outstanding_balance' => (float)($r['outstanding_balance'] ?? $r['loan_amount']),
            'amount_paid'         => (float)($r['amount_paid'] ?? 0),
            'status'              => $r['status'],
        ];
    }
}

// If no loans found, return helpful message
header('Content-Type: application/json');
echo json_encode($loans);
