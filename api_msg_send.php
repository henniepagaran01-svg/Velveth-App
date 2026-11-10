<?php
/**
 * Messages — Send
 * Customer: sends to their own thread (customer_id = their own session id).
 * Admin: must pass customer_id explicitly (which customer's thread to reply to).
 */
session_start();
require_once 'config.php';
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Dependency-free truncation (mbstring isn't guaranteed available on every host)
function _msgTruncate($str, $len) {
    return strlen($str) > $len ? substr($str, 0, $len - 1) . '…' : $str;
}

if (!isset($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']); exit;
}

if (!ensureMessagesTable($conn)) {
    echo json_encode(['error' => 'Messages are temporarily unavailable. Please try again shortly.']);
    exit;
}

$message = trim($_POST['message'] ?? '');
if ($message === '') {
    echo json_encode(['error' => 'Message cannot be empty']); exit;
}
if (strlen($message) > 2000) {
    $message = substr($message, 0, 2000);
}

$sender_id   = (int)$_SESSION['user_id'];
$sender_role = $_SESSION['role'] === 'admin' ? 'admin' : 'customer';

if ($sender_role === 'customer') {
    $customer_id = $sender_id;
} else {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    if ($customer_id <= 0) {
        echo json_encode(['error' => 'Missing customer_id']); exit;
    }
    // Confirm this is actually a customer account
    $chk_res = $conn->query("SELECT id FROM users WHERE id=$customer_id AND role='customer'");
    $chk = $chk_res ? $chk_res->fetch_assoc() : null;
    if (!$chk) {
        echo json_encode(['error' => 'Invalid customer']); exit;
    }
}

$stmt = $conn->prepare("INSERT INTO messages (customer_id, sender_role, sender_id, message) VALUES (?, ?, ?, ?)");
if (!$stmt) {
    error_log('[Velveth] api_msg_send prepare failed: ' . $conn->error);
    echo json_encode(['error' => 'Could not send message.']); exit;
}
$stmt->bind_param('isis', $customer_id, $sender_role, $sender_id, $message);

if ($stmt->execute()) {
    $msg_id = $conn->insert_id;

    // Notify the other party (in-app only -- a message doesn't need a Gmail email)
    if ($sender_role === 'customer') {
        $admins = $conn->query("SELECT id FROM users WHERE role='admin' AND status='active'");
        $esc = mysqli_real_escape_string($conn, _msgTruncate($message, 80));
        $cname = mysqli_real_escape_string($conn, $_SESSION['fullname']);
        while ($adm = $admins->fetch_assoc()) {
            $conn->query("INSERT INTO notifications (user_id,title,message,type) VALUES ({$adm['id']}, '💬 New message from {$cname}', '{$esc}', 'info')");
        }
    } else {
        $esc = mysqli_real_escape_string($conn, _msgTruncate($message, 80));
        $conn->query("INSERT INTO notifications (user_id,title,message,type) VALUES ($customer_id, '💬 New message from Velveth Support', '{$esc}', 'info')");
    }

    echo json_encode([
        'success' => true,
        'message' => [
            'id' => $msg_id,
            'sender_role' => $sender_role,
            'message' => htmlspecialchars($message),
            'created_at' => date('Y-m-d H:i:s'),
        ] + chatTimeLabels(date('Y-m-d H:i:s'))
    ]);
} else {
    echo json_encode(['error' => 'Failed to send message']);
}
