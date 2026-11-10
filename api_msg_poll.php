<?php
/**
 * Messages — Poll
 * Customer: fetches their own thread (customer_id = their own session id).
 * Admin: must pass customer_id explicitly (which thread to view).
 * Returns messages with id > since_id, so the client can append only new ones.
 */
session_start();
require_once 'config.php';
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!isset($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']); exit;
}

if (!ensureMessagesTable($conn)) {
    // Table missing and couldn't be created (e.g. restricted DB privileges
    // on some free hosts) -- respond with a clear JSON error instead of
    // letting every query below fatal-error into a blank, non-JSON response
    // that the frontend can't parse and would otherwise fail silently on.
    echo json_encode(['error' => 'Messages are temporarily unavailable. Please try again shortly.']);
    exit;
}

$is_admin  = ($_SESSION['role'] === 'admin');
$since_id  = (int)($_GET['since_id'] ?? 0);

if ($is_admin) {
    $customer_id = (int)($_GET['customer_id'] ?? 0);
    if ($customer_id <= 0) {
        echo json_encode(['error' => 'Missing customer_id']); exit;
    }
} else {
    $customer_id = (int)$_SESSION['user_id'];
}

$stmt = $conn->prepare("SELECT id, sender_role, message, created_at FROM messages WHERE customer_id=? AND id > ? ORDER BY id ASC");
if (!$stmt) {
    error_log('[Velveth] api_msg_poll prepare failed: ' . $conn->error);
    echo json_encode(['error' => 'Could not load messages.']); exit;
}
$stmt->bind_param('ii', $customer_id, $since_id);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = [
        'id'          => (int)$row['id'],
        'sender_role' => $row['sender_role'],
        'message'     => htmlspecialchars($row['message']),
        'created_at'  => $row['created_at'],
    ] + chatTimeLabels($row['created_at']);
}

// Mark the OTHER party's messages as read now that this side has fetched them
$reader_role = $is_admin ? 'customer' : 'admin';
$conn->query("UPDATE messages SET is_read=1 WHERE customer_id=$customer_id AND sender_role='$reader_role' AND is_read=0");

echo json_encode(['messages' => $messages]);
