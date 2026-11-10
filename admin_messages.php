<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php"); exit;
}
$_cur = 'admin_messages.php';
$msg_available = ensureMessagesTable($conn);

// Customers with at least one message, sorted by most recent activity,
// with an unread-from-customer count per thread.
$threads = $msg_available ? $conn->query("
    SELECT u.id, u.full_name, u.email,
           MAX(m.created_at) AS last_at,
           (SELECT message FROM messages WHERE customer_id=u.id ORDER BY id DESC LIMIT 1) AS last_message,
           SUM(CASE WHEN m.sender_role='customer' AND m.is_read=0 THEN 1 ELSE 0 END) AS unread
    FROM messages m
    JOIN users u ON m.customer_id = u.id
    GROUP BY u.id, u.full_name, u.email
    ORDER BY last_at DESC
") : false;
$thread_count = $threads ? $threads->num_rows : 0;

// All customers (for starting a NEW conversation with someone who hasn't messaged yet)
$all_customers = $conn->query("SELECT id, full_name FROM users WHERE role='customer' AND deleted_at IS NULL ORDER BY full_name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>
  <meta name="theme-color" content="#8B2A3A"/>
  <title>Messages | Velveth Admin</title>
  <link rel="stylesheet" href="shared.css"/>
  <link rel="stylesheet" href="mobile-app.css"/>
  <style>
    body.app-shell { display:flex; flex-direction:column; height:100vh; overflow:hidden; }
    .msg-thread-item { display:flex; align-items:center; gap:12px; padding:12px 16px; border-bottom:1px solid var(--gray-100); cursor:pointer; }
    .msg-thread-item:active { background:var(--gray-50); }
    .msg-avatar { width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,var(--maroon),var(--maroon-light)); color:white; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; flex-shrink:0; }
    .msg-thread-body { flex:1; min-width:0; }
    .msg-thread-name { font-size:13.5px; font-weight:700; color:var(--text-dark); }
    .msg-thread-preview { font-size:11.5px; color:var(--gray-400); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:2px; }
    .msg-unread-badge { background:var(--maroon); color:white; font-size:10px; font-weight:800; border-radius:100px; min-width:18px; height:18px; display:flex; align-items:center; justify-content:center; padding:0 5px; flex-shrink:0; }

    /* Thread view (hidden until a customer is selected) */
    #threadView { display:none; flex-direction:column; height:100%; }
    .msg-scroll { flex:1; overflow-y:auto; padding:14px 16px; display:flex; flex-direction:column; gap:10px; }
    .msg-bubble { max-width:78%; padding:10px 14px; border-radius:16px; font-size:13.5px; line-height:1.5; word-break:break-word; }
    .msg-bubble.mine { align-self:flex-end; background:var(--maroon); color:white; border-bottom-right-radius:4px; }
    .msg-bubble.theirs { align-self:flex-start; background:var(--gray-100); color:var(--text-dark); border-bottom-left-radius:4px; }
    .msg-date { align-self:center; font-size:10.5px; font-weight:700; color:var(--gray-400); background:var(--gray-100); border-radius:100px; padding:3px 12px; margin:4px 0; }
    .msg-start { align-self:center; text-align:center; font-size:10.5px; color:var(--gray-400); margin:2px 0 6px; }
    .msg-time { font-size:10px; opacity:.65; margin-top:4px; display:block; }
    .msg-input-bar { display:flex; gap:8px; padding:10px 14px; background:white; border-top:1px solid var(--gray-100); align-items:flex-end; }
    .msg-input-bar textarea { flex:1; resize:none; border:1px solid var(--gray-200); border-radius:20px; padding:10px 16px; font-size:13.5px; font-family:inherit; max-height:90px; }
    .msg-send-btn { background:var(--maroon); color:white; border:none; width:42px; height:42px; border-radius:50%; flex-shrink:0; font-size:16px; }
    .msg-send-btn:disabled { opacity:.5; }
  </style>
</head>
<body class="app-shell">

  <!-- ═══ INBOX LIST ═══ -->
  <div id="inboxView" style="display:flex;flex-direction:column;height:100%;">
    <header class="app-bar maroon">
      <a href="admin_dashboard.php" class="app-bar-back">←</a>
      <div style="flex:1;min-width:0;">
        <div class="app-bar-title">💬 Customer Messages</div>
        <div class="app-bar-sub"><?= $thread_count ?> conversation<?= $thread_count!=1?'s':'' ?></div>
      </div>
      <button class="app-bar-action" onclick="openSheet('newMsgSheet')" style="background:none;border:none;color:white;font-size:20px;">＋</button>
    </header>

    <div style="flex:1;overflow-y:auto;">
      <?php if (!$msg_available): ?>
        <div class="app-empty" style="padding:60px 20px;">
          <div class="app-empty-icon">⚠️</div>
          <div class="app-empty-title">Messages are temporarily unavailable</div>
          <div class="app-empty-sub">Please try again shortly</div>
        </div>
      <?php elseif ($thread_count === 0): ?>
        <div class="app-empty" style="padding:60px 20px;">
          <div class="app-empty-icon">💬</div>
          <div class="app-empty-title">No conversations yet</div>
          <div class="app-empty-sub">Tap ＋ to message a customer</div>
        </div>
      <?php else: while ($t = $threads->fetch_assoc()):
        $initials = strtoupper(substr($t['full_name'],0,1) . (strpos($t['full_name'],' ') ? substr($t['full_name'], strpos($t['full_name'],' ')+1, 1) : ''));
      ?>
      <div class="msg-thread-item" onclick="openThread(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['full_name'])) ?>')">
        <div class="msg-avatar"><?= $initials ?></div>
        <div class="msg-thread-body">
          <div class="msg-thread-name"><?= htmlspecialchars($t['full_name']) ?></div>
          <div class="msg-thread-preview"><?= htmlspecialchars($t['last_message'] ?? '') ?></div>
        </div>
        <?php if ($t['unread'] > 0): ?><span class="msg-unread-badge"><?= min(99,$t['unread']) ?></span><?php endif; ?>
      </div>
      <?php endwhile; endif; ?>
    </div>
  </div>

  <!-- ═══ THREAD VIEW ═══ -->
  <div id="threadView">
    <header class="app-bar maroon">
      <button class="app-bar-back" onclick="closeThread()" style="background:none;border:none;">←</button>
      <div style="flex:1;min-width:0;">
        <div class="app-bar-title" id="threadCustomerName">Customer</div>
        <div class="app-bar-sub">Velveth Lending Support</div>
      </div>
    </header>
    <div id="connStatus" style="display:none;align-items:center;justify-content:space-between;gap:10px;background:#fff8e1;color:#92400e;padding:9px 16px;font-size:12px;font-weight:600;">
      <span>⚠️ Live updates paused — tap to refresh</span>
      <button type="button" onclick="pollThread()" style="background:#92400e;color:white;border:none;border-radius:100px;padding:5px 14px;font-size:11.5px;font-weight:700;">↻ Refresh</button>
    </div>
    <div class="msg-scroll" id="msgScroll"></div>
    <form class="msg-input-bar" id="msgForm">
      <textarea id="msgInput" placeholder="Type a reply…" rows="1" required></textarea>
      <button type="submit" class="msg-send-btn" id="msgSendBtn">➤</button>
    </form>
  </div>

  <!-- ═══ NEW MESSAGE SHEET ═══ -->
  <div class="sheet-overlay" id="ov_newMsgSheet" onclick="closeSheet('newMsgSheet')"></div>
  <div class="bottom-sheet" id="newMsgSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div class="sheet-title">Start a Conversation</div>
      <button class="sheet-close" onclick="closeSheet('newMsgSheet')">✕</button>
    </div>
    <div class="sheet-body">
      <select id="newMsgCustomer" class="app-select">
        <option value="">— Select Customer —</option>
        <?php while ($c = $all_customers->fetch_assoc()): ?>
        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?></option>
        <?php endwhile; ?>
      </select>
      <button type="button" class="app-btn app-btn-primary" style="margin-top:14px;" onclick="startNewMsg()">Start</button>
    </div>
  </div>

<script>
function openSheet(id)  { document.getElementById('ov_'+id)?.classList.add('open');    document.getElementById(id)?.classList.add('open'); }
function closeSheet(id) { document.getElementById('ov_'+id)?.classList.remove('open'); document.getElementById(id)?.classList.remove('open'); }

let currentCustomerId = null;
let lastId = 0;
let pollTimer = null;

let lastDay = '';
function dayDivider(m) {
  let pre = '';
  if (!lastDay) pre = `<div class="msg-start">💬 Conversation started · ${m.date_label} · ${m.time_label}</div>`;
  else if (m.day !== lastDay) pre = `<div class="msg-date">${m.date_label}</div>`;
  lastDay = m.day;
  return pre;
}
function bubbleHtml(m) {
  const cls = m.sender_role === 'admin' ? 'mine' : 'theirs';
  return dayDivider(m) + `<div class="msg-bubble ${cls}">${m.message.replace(/\n/g,'<br>')}<span class="msg-time">${m.time_label}</span></div>`;
}

async function openThread(customerId, customerName) {
  currentCustomerId = customerId;
  lastId = 0;
  lastDay = '';
  document.getElementById('threadCustomerName').textContent = customerName;
  document.getElementById('inboxView').style.display = 'none';
  document.getElementById('threadView').style.display = 'flex';

  const scrollEl = document.getElementById('msgScroll');
  scrollEl.innerHTML = '<div style="text-align:center;color:var(--gray-400);font-size:12px;padding:20px;">Loading…</div>';

  try {
    const res = await fetch('api_msg_poll.php?customer_id=' + customerId + '&since_id=0');
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    scrollEl.innerHTML = '';
    (data.messages || []).forEach(m => {
      scrollEl.insertAdjacentHTML('beforeend', bubbleHtml(m));
      lastId = Math.max(lastId, m.id);
    });
    scrollEl.scrollTop = scrollEl.scrollHeight;
    setConnStatus(true);
  } catch (e) {
    scrollEl.innerHTML = '<div style="text-align:center;color:#b91c1c;font-size:12px;padding:20px;">Could not load messages. <a href="javascript:void(0)" onclick="openThread(' + customerId + ',\'' + customerName.replace(/'/g,"\\'") + '\')" style="color:#b91c1c;font-weight:700;text-decoration:underline;">Retry</a></div>';
  }

  pollFailCount = 0;
  scheduleNextPoll();
}

function closeThread() {
  currentCustomerId = null;
  if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
  document.getElementById('threadView').style.display = 'none';
  document.getElementById('inboxView').style.display = 'flex';
  window.location.reload(); // refresh inbox previews/unread counts
}

// ── Polling, tuned to avoid tripping InfinityFree's (and similar free
// hosts') firewall -- see the matching comment in customer_messages.php for the
// full reasoning. Same approach here: slow baseline, jitter, pause when the
// tab is hidden, back off (not hammer) on failure. ──
const POLL_BASE_MS   = 18000;
const POLL_JITTER_MS = 2000;
const POLL_MAX_BACKOFF_MS = 90000;
let pollFailCount = 0;

function setConnStatus(ok) {
  const el = document.getElementById('connStatus');
  if (!el) return;
  el.style.display = ok ? 'none' : 'flex';
}

function scheduleNextPoll() {
  if (pollTimer) clearTimeout(pollTimer);
  if (!currentCustomerId) return;
  const backoff = Math.min(POLL_MAX_BACKOFF_MS, POLL_BASE_MS * Math.pow(1.6, pollFailCount));
  const delay = backoff + Math.random() * POLL_JITTER_MS;
  pollTimer = setTimeout(pollThread, delay);
}

async function pollThread() {
  if (!currentCustomerId) return;
  if (document.hidden) { scheduleNextPoll(); return; }
  try {
    const res = await fetch('api_msg_poll.php?customer_id=' + currentCustomerId + '&since_id=' + lastId);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    if (data.messages && data.messages.length) {
      const scrollEl = document.getElementById('msgScroll');
      data.messages.forEach(m => {
        scrollEl.insertAdjacentHTML('beforeend', bubbleHtml(m));
        lastId = Math.max(lastId, m.id);
      });
      scrollEl.scrollTop = scrollEl.scrollHeight;
    }
    pollFailCount = 0;
    setConnStatus(true);
  } catch (e) {
    pollFailCount = Math.min(pollFailCount + 1, 6);
    if (pollFailCount >= 2) setConnStatus(false);
  }
  scheduleNextPoll();
}

document.addEventListener('visibilitychange', () => { if (!document.hidden && currentCustomerId) pollThread(); });

document.getElementById('msgForm').addEventListener('submit', async function (e) {
  e.preventDefault();
  if (!currentCustomerId) return;
  const input = document.getElementById('msgInput');
  const btn   = document.getElementById('msgSendBtn');
  const text  = input.value.trim();
  if (!text) return;

  btn.disabled = true;
  try {
    const res = await fetch('api_msg_send.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'message=' + encodeURIComponent(text) + '&customer_id=' + currentCustomerId
    });
    const data = await res.json();
    if (data.success) {
      const scrollEl = document.getElementById('msgScroll');
      scrollEl.insertAdjacentHTML('beforeend', bubbleHtml(data.message));
      lastId = Math.max(lastId, data.message.id);
      scrollEl.scrollTop = scrollEl.scrollHeight;
      input.value = '';
    } else {
      alert(data.error || 'Could not send message.');
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

function startNewMsg() {
  const sel = document.getElementById('newMsgCustomer');
  if (!sel.value) { alert('Please select a customer.'); return; }
  closeSheet('newMsgSheet');
  openThread(sel.value, sel.options[sel.selectedIndex].text);
}
</script>
  <script src="assets/js/form-guard.js"></script>
  <script src="realtime.js"></script>
</body>
</html>
