<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in'])) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

header('Content-Type: application/json');

$input   = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$page    = trim($input['page']    ?? 'general');
$role    = $_SESSION['role'] ?? 'customer';

if (!$message) { echo json_encode(['error'=>'No message']); exit; }

// ── API Key ── define ANTHROPIC_API_KEY in config.php or here
if (!defined('ANTHROPIC_API_KEY')) {
    echo json_encode(['reply'=>"⚠️ Chatbot not configured. Ask your admin to add ANTHROPIC_API_KEY to config.php."]);
    exit;
}

$system = "You are VelBot, the friendly in-app assistant for Velveth Lending — a loan management app used in the Philippines.
You help users understand how to use the app. Be concise, warm, and helpful. Use emojis sparingly. Answer in the user's language if they write in Filipino/Tagalog.

APP OVERVIEW:
Velveth Lending manages customer loans, repayments, penalties, and documents.

CUSTOMER FEATURES:
- Dashboard: See your loan balance, next payment date, quick actions
- My Loans: View loan details, outstanding balance, payment history, monthly dues
- Payments: Submit payment via GCash, bank transfer, or cash — upload reference photo
- Penalties: View any penalty issued on your account and its status
- Documents: Upload required documents (Government ID, Selfie, Proof of Income, etc.)
- Notifications: View updates about your loan, payments, and documents
- Profile: Update your personal information and change password

ADMIN FEATURES:
- Dashboard: Portfolio overview, pending applications alert, quick actions
- Customers: Add, edit, reset passwords, view loan counts
- Loan Applicants: Review applications, approve/reject, monitor active loans
- Payments: Confirm or reject customer payment submissions, record manual payments
- Penalties: Issue penalties, mark paid/waived, track overdue
- Documents: Verify uploaded documents, qualify applicants for loan approval
- Reports: Export CSV/PDF reports for payments, loans, penalties, customers
- Notifications: View and manage system alerts
- Users: Manage admin and customer accounts
- Audit Trail: View full activity history
- Archive: Restore or permanently delete archived applications
- More: Access all secondary admin tools

CURRENT USER ROLE: $role
CURRENT PAGE: $page

Always give step-by-step guidance when explaining how to do something. If unsure, say so honestly.";

$payload = json_encode([
    'model'      => 'claude-haiku-4-5-20251001',
    'max_tokens' => 400,
    'system'     => $system,
    'messages'   => [['role'=>'user','content'=>$message]]
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_TIMEOUT => 20
]);
$res  = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($err) { echo json_encode(['reply'=>"⚠️ Connection error. Please try again."]); exit; }

$data  = json_decode($res, true);
$reply = $data['content'][0]['text'] ?? '⚠️ Could not get a response. Please try again.';
echo json_encode(['reply' => $reply]);
