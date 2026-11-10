<?php
/**
 * Real-Time Polling Endpoint
 * Called every 15s by admin pages to check for new data.
 * Returns JSON with counts of new items since last_check timestamp.
 */
session_start();
require_once 'config.php';
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!isset($_SESSION['logged_in'])) {
    echo json_encode(['error' => 'unauthorized']); exit;
}

$since    = $_GET['since'] ?? date('Y-m-d H:i:s', strtotime('-60 seconds'));
$since    = $conn->real_escape_string($since);
$user_id  = (int)$_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');
$now      = date('Y-m-d H:i:s');

$data = ['timestamp' => $now, 'updates' => []];

if ($is_admin) {
    // New pending payments
    $r = $conn->query("SELECT COUNT(*) c FROM payments WHERE status='pending' AND created_at > '$since'");
    $new_pays = (int)($r->fetch_assoc()['c'] ?? 0);
    if ($new_pays > 0) $data['updates'][] = ['type'=>'payment','count'=>$new_pays,'msg'=>"$new_pays new payment submission(s) need confirmation",'url'=>'admin_payments.php'];

    // New loan applications
    $r = $conn->query("SELECT COUNT(*) c FROM loan_applications WHERE status='pending' AND created_at > '$since'");
    $new_apps = (int)($r->fetch_assoc()['c'] ?? 0);
    if ($new_apps > 0) $data['updates'][] = ['type'=>'loan','count'=>$new_apps,'msg'=>"$new_apps new loan application(s) submitted",'url'=>'admin_loan_applicants.php'];

    // New customers
    $r = $conn->query("SELECT COUNT(*) c FROM users WHERE role='customer' AND deleted_at IS NULL AND created_at > '$since'");
    $new_custs = (int)($r->fetch_assoc()['c'] ?? 0);
    if ($new_custs > 0) $data['updates'][] = ['type'=>'customer','count'=>$new_custs,'msg'=>"$new_custs new customer(s) registered",'url'=>'admin_customers.php'];

    // Unread admin notifications total
    $r = $conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id IN (SELECT id FROM users WHERE role='admin') AND is_read=0");
    $data['unread_notifications'] = (int)($r->fetch_assoc()['c'] ?? 0);

    // Pending stats
    $r = $conn->query("SELECT COUNT(*) c FROM payments WHERE status='pending'");
    $data['pending_payments'] = (int)($r->fetch_assoc()['c'] ?? 0);
    $r = $conn->query("SELECT COUNT(*) c FROM loan_applications WHERE status='pending'");
    $data['pending_apps'] = (int)($r->fetch_assoc()['c'] ?? 0);
} else {
    // Customer polling — new notifications for them
    $r = $conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$user_id AND is_read=0");
    $data['unread_notifications'] = (int)($r->fetch_assoc()['c'] ?? 0);

    // Check if any payment status changed
    $r = $conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$user_id AND created_at > '$since' AND is_read=0");
    $new_notifs = (int)($r->fetch_assoc()['c'] ?? 0);
    if ($new_notifs > 0) $data['updates'][] = ['type'=>'notification','count'=>$new_notifs,'msg'=>"You have $new_notifs new notification(s)",'url'=>'customer_notifications.php'];
}

echo json_encode($data);
