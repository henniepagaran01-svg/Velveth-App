<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'customer') {
    header("Location: customer_login.php"); exit;
}
$user_id = (int)$_SESSION['user_id'];

$msg_available = ensureMessagesTable($conn);
$msgs = $msg_available
    ? $conn->query("SELECT id, sender_role, message, created_at FROM messages WHERE customer_id=$user_id ORDER BY id ASC LIMIT 200")
    : false;
if ($msg_available) {
    $conn->query("UPDATE messages SET is_read=1 WHERE customer_id=$user_id AND sender_role='admin' AND is_read=0");
}
$last_id = 0;
if ($msg_available) {
    $r = $conn->query("SELECT MAX(id) m FROM messages WHERE customer_id=$user_id");
    $last_id = (int)(($r ? $r->fetch_assoc()['m'] : 0) ?? 0);
}

$_cur = 'customer_messages.php';
$init_day = '';  // tracks the last rendered day for date dividers
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Message Support | Velveth</title>
  <link rel="stylesheet" href="shared.css"/>
  <link rel="stylesheet" href="mobile-app.css"/>
  <link rel="manifest" href="manifest.json"/>
  <style>
    body.app-shell { display:flex; flex-direction:column; height:100vh; height:100dvh; overflow:hidden; padding-bottom:0 !important; }
    .msg-scroll { flex:1; overflow-y:auto; padding:14px 16px; display:flex; flex-direction:column; gap:10px; }
    .msg-bubble { max-width:78%; padding:10px 14px; border-radius:16px; font-size:13.5px; line-height:1.5; word-break:break-word; }
    .msg-bubble.mine { align-self:flex-end; background:var(--maroon); color:white; border-bottom-right-radius:4px; }
    .msg-bubble.theirs { align-self:flex-start; background:var(--gray-100); color:var(--text-dark); border-bottom-left-radius:4px; }
    .msg-date { align-self:center; font-size:10.5px; font-weight:700; color:var(--gray-400); background:var(--gray-100); border-radius:100px; padding:3px 12px; margin:4px 0; }
    .msg-start { align-self:center; text-align:center; font-size:10.5px; color:var(--gray-400); margin:2px 0 6px; }
    .msg-time { font-size:10px; opacity:.65; margin-top:4px; display:block; }
    .msg-input-bar { display:flex; gap:8px; padding:10px 14px calc(10px + env(safe-area-inset-bottom)); background:white; border-top:1px solid var(--gray-100); align-items:flex-end; }
    .msg-input-bar textarea { flex:1; resize:none; border:1px solid var(--gray-200); border-radius:20px; padding:10px 16px; font-size:13.5px; font-family:inherit; max-height:90px; }
    .msg-send-btn { background:var(--maroon); color:white; border:none; width:42px; height:42px; border-radius:50%; flex-shrink:0; font-size:16px; }
    .msg-send-btn:disabled { opacity:.5; }
    .msg-empty { text-align:center; color:var(--gray-400); font-size:12.5px; padding:40px 20px; }
  </style>
</head>
<body class="app-shell">

  <header class="app-bar maroon">
    <a href="customer_dashboard.php" class="app-bar-back">←</a>
    <div style="flex:1;min-width:0;">
      <div class="app-bar-title">💬 Message Support</div>
      <div class="app-bar-sub">Velveth Lending Team</div>
    </div>
  </header>

  <div id="connStatus" style="display:none;align-items:center;justify-content:space-between;gap:10px;background:#fff8e1;color:#92400e;padding:9px 16px;font-size:12px;font-weight:600;">
    <span>⚠️ Live updates paused — tap to refresh</span>
    <button type="button" onclick="pollMessages()" style="background:#92400e;color:white;border:none;border-radius:100px;padding:5px 14px;font-size:11.5px;font-weight:700;">↻ Refresh</button>
  </div>

  <div class="msg-scroll" id="msgScroll">
    <?php if (!$msg_available): ?>
      <div class="msg-empty">⚠️ Messages are temporarily unavailable. Please try again shortly.</div>
    <?php elseif ($msgs->num_rows === 0): ?>
      <div class="msg-empty">No messages yet. Say hello! 👋</div>
    <?php else: while ($m = $msgs->fetch_assoc()):
        $lb = chatTimeLabels($m['created_at']);
        if ($init_day === ''): ?>
      <div class="msg-start">💬 Conversation started · <?= $lb['date_label'] ?> · <?= $lb['time_label'] ?></div>
    <?php elseif ($lb['day'] !== $init_day): ?>
      <div class="msg-date"><?= $lb['date_label'] ?></div>
    <?php endif; $init_day = $lb['day']; ?>
      <div class="msg-bubble <?= $m['sender_role']==='customer'?'mine':'theirs' ?>">
        <?= nl2br(htmlspecialchars($m['message'])) ?>
        <span class="msg-time"><?= $lb['time_label'] ?></span>
      </div>
    <?php endwhile; endif; ?>
  </div>

  <form class="msg-input-bar" id="msgForm">
    <textarea id="msgInput" placeholder="Type a message…" rows="1" required></textarea>
    <button type="submit" class="msg-send-btn" id="msgSendBtn">➤</button>
  </form>

<script>
let lastId = <?= $last_id ?>;
const scrollEl = document.getElementById('msgScroll');
scrollEl.scrollTop = scrollEl.scrollHeight;

let lastDay = <?= json_encode($init_day) ?>;
function dayDivider(m) {
  let pre = '';
  if (!lastDay) pre = `<div class="msg-start">💬 Conversation started · ${m.date_label} · ${m.time_label}</div>`;
  else if (m.day !== lastDay) pre = `<div class="msg-date">${m.date_label}</div>`;
  lastDay = m.day;
  return pre;
}
function bubbleHtml(m) {
  const cls = m.sender_role === 'customer' ? 'mine' : 'theirs';
  return dayDivider(m) + `<div class="msg-bubble ${cls}">${m.message.replace(/\n/g,'<br>')}<span class="msg-time">${m.time_label}</span></div>`;
}

function appendMessages(messages) {
  if (!messages.length) return;
  const empty = scrollEl.querySelector('.msg-empty');
  if (empty) empty.remove();
  messages.forEach(m => {
    scrollEl.insertAdjacentHTML('beforeend', bubbleHtml(m));
    lastId = Math.max(lastId, m.id);
  });
  scrollEl.scrollTop = scrollEl.scrollHeight;
}

// ── Polling, tuned to avoid tripping InfinityFree's (and similar free
// hosts') firewall, which blocks traffic that LOOKS like bot/scraper
// activity -- a tight, perfectly-regular polling loop is exactly that
// pattern. Mitigations: a much slower baseline interval, randomized jitter
// so requests aren't perfectly periodic, pausing entirely while the tab
// isn't visible, and backing off further (not hammering harder) when a
// request fails. A manual refresh button is the reliable fallback if
// automatic polling is ever blocked outright. ──
const POLL_BASE_MS   = 18000; // ~18-20s baseline -- fast enough to feel responsive, still gentle enough for free hosting
const POLL_JITTER_MS = 2000;  // +0..2s random, avoids a robotic fixed-interval pattern
const POLL_MAX_BACKOFF_MS = 90000; // cap backoff at 90s so it still recovers eventually
let pollFailCount = 0;
let pollTimer = null;

function setConnStatus(ok) {
  const el = document.getElementById('connStatus');
  if (!el) return;
  el.style.display = ok ? 'none' : 'flex';
}

function scheduleNextPoll() {
  if (pollTimer) clearTimeout(pollTimer);
  const backoff = Math.min(POLL_MAX_BACKOFF_MS, POLL_BASE_MS * Math.pow(1.6, pollFailCount));
  const delay = backoff + Math.random() * POLL_JITTER_MS;
  pollTimer = setTimeout(pollMessages, delay);
}

async function pollMessages() {
  if (document.hidden) { scheduleNextPoll(); return; } // don't poll a backgrounded tab
  try {
    const res = await fetch('api_msg_poll.php?since_id=' + lastId);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json(); // throws if the host returned an HTML error/block page instead of JSON
    if (data.messages) appendMessages(data.messages);
    pollFailCount = 0;
    setConnStatus(true);
  } catch (e) {
    pollFailCount = Math.min(pollFailCount + 1, 6); // cap growth so backoff plateaus at POLL_MAX_BACKOFF_MS
    if (pollFailCount >= 2) setConnStatus(false); // don't flash the warning on a single blip
  }
  scheduleNextPoll();
}
scheduleNextPoll();

// Poll immediately when the tab becomes visible again, instead of waiting
// out whatever delay was scheduled while it was hidden.
document.addEventListener('visibilitychange', () => { if (!document.hidden) pollMessages(); });

document.getElementById('msgForm').addEventListener('submit', async function (e) {
  e.preventDefault();
  const input = document.getElementById('msgInput');
  const btn   = document.getElementById('msgSendBtn');
  const text  = input.value.trim();
  if (!text) return;

  btn.disabled = true;
  try {
    const res = await fetch('api_msg_send.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'message=' + encodeURIComponent(text)
    });
    const data = await res.json();
    if (data.success) {
      appendMessages([data.message]);
      input.value = '';
    } else {
      alert(data.error || 'Could not send message. Please try again.');
    }
  } catch (e) {
    alert('Network error. Please check your connection and try again.');
  }
  btn.disabled = false;
  input.focus();
});

document.getElementById('msgInput').addEventListener('keydown', function (e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    document.getElementById('msgForm').requestSubmit();
  }
});
</script>
  <script src="assets/js/form-guard.js"></script>
</body>
</html>
