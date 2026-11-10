<?php
/**
 * Improved Chatbot Widget v2
 * - Quick reply buttons
 * - Persistent chat history (localStorage-based)  
 * - Direct action links (Apply Loan, Check Balance, View Payments)
 * - Available on all customer pages
 * - Removed: Login Help, Registration Help from main menu
 */
?>
<div class="chatbot-fab has-bottom-nav" id="chatbotFab">
  <div class="chatbot-bubble" id="chatbotBubble">
    <div class="chatbot-header">
      <div class="chatbot-avatar">🤖</div>
      <div style="flex:1;">
        <div class="chatbot-name">VLC Assistant</div>
        <div class="chatbot-status" id="chatStatus">● Online</div>
      </div>
      <div style="display:flex;gap:6px;">
        <button onclick="clearChatHistory()" title="Clear history"
          style="background:rgba(255,255,255,.15);border:none;color:white;width:24px;height:24px;border-radius:50%;cursor:pointer;font-size:11px;display:flex;align-items:center;justify-content:center;">🗑</button>
        <button onclick="closeChatbot()"
          style="background:rgba(255,255,255,.15);border:none;color:white;width:24px;height:24px;border-radius:50%;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;">✕</button>
      </div>
    </div>
    <div class="chatbot-messages" id="chatMessages"></div>
    <div class="chatbot-input-row">
      <input type="text" id="chatInput" placeholder="Type a message…"
             onkeydown="if(event.key==='Enter'){event.preventDefault();sendChat();}"/>
      <button class="chatbot-send" onclick="sendChat()">➤</button>
    </div>
  </div>
  <button class="chatbot-toggle" onclick="toggleChatbot()" id="chatToggleBtn" title="Chat with VLC Assistant">🤖</button>
</div>

<script>
const CHAT_STORAGE_KEY = 'vlc_chat_history';
const CHAT_OPEN_KEY    = 'vlc_chat_open';

// ── Knowledge base ─────────────────────────────────────────────────────────
const KB = {
  menu: {
    msg: '👋 Hi! I\'m your VLC Assistant. What can I help you with today?',
    choices: [
      { label:'💰 Apply for Loan',    action:'apply' },
      { label:'💳 Make Payment',      action:'payment' },
      { label:'📋 Check Loan Status', action:'status' },
      { label:'⚠️ About Penalties',   action:'penalty' },
      { label:'📞 Contact Support',   action:'contact' },
    ]
  },
  apply: {
    msg:'📝 <b>How to Apply for a Loan:</b><br>1. Go to <a href="customer_apply.php" style="color:var(--maroon,#7a1e2e);font-weight:800;text-decoration:underline;">Apply Loan</a> in the menu<br>2. Fill in all 4 steps: Personal → Loan Details → Financial → Documents<br>3. Upload your Selfie + Gov\'t ID<br>4. Submit — we review in 3–5 business days',
    choices:[
      { label:'→ Apply Now', url:'customer_apply.php' },
      { label:'📄 Documents needed', action:'docs' },
      { label:'🔙 Back', action:'menu' },
    ]
  },
  docs: {
    msg:'📄 <b>Required Documents:</b><br>• 📸 Selfie (face clearly visible)<br>• 🪪 Government ID (PhilSys, Passport, Driver\'s License)<br>• 💼 Proof of Income (Payslip, ITR, COE)<br>• 📋 Proof of Billing (optional)',
    choices:[
      { label:'→ Apply Now', url:'customer_apply.php' },
      { label:'🔙 Back', action:'menu' },
    ]
  },
  payment: {
    msg:'💳 <b>Payment Methods Available:</b><br>• 💚 <b>GCash</b> — 09307158807 (Velveth Lending Co.)<br>• 🏦 <b>Bank Transfer</b> — BDO Acct: 1234-5678-9011<br>• 💰 <b>Cash Collection</b> — Schedule a collection date',
    choices:[
      { label:'→ Make Payment Now', url:'customer_loan.php' },
      { label:'💚 GCash details',   action:'gcash' },
      { label:'🏦 Bank details',    action:'bank' },
      { label:'🔙 Back',            action:'menu' },
    ]
  },
  gcash: {
    msg:'💚 <b>GCash Payment:</b><br>Send to: <b>09307158807</b><br>Name: Velveth Lending Co.<br><br>After sending, enter your <b>13-digit reference number</b> on the payment screen.',
    choices:[
      { label:'→ Pay via GCash', url:'customer_loan.php' },
      { label:'🔙 Payment methods', action:'payment' },
    ]
  },
  bank: {
    msg:'🏦 <b>Bank Transfer Details:</b><br>Bank: <b>BDO Unibank</b><br>Account: Velveth Lending Company<br>Account No.: <b>1234-5678-9011</b><br><br>Enter your <b>transaction reference number</b> on the payment screen.',
    choices:[
      { label:'→ Pay via Bank', url:'customer_loan.php' },
      { label:'🔙 Payment methods', action:'payment' },
    ]
  },
  status: {
    msg:'📋 <b>Check Your Loan:</b><br>• Outstanding balance<br>• Next payment due date<br>• Full payment history<br>• Application timeline',
    choices:[
      { label:'→ My Loan Page',       url:'customer_loan.php' },
      { label:'📊 Dashboard',         url:'customer_dashboard.php' },
      { label:'🔙 Back',              action:'menu' },
    ]
  },
  penalty: {
    msg:'⚠️ <b>Penalty Policy:</b><br>• <b>₱50/day</b> for the first 5 days late<br>• After 5 days: <b>+5%</b> of weekly payment<br>• Must be settled before next payment<br><br>You can pay penalties via GCash, Bank, or Cash.',
    choices:[
      { label:'→ View My Penalties', url:'customer_penalties.php' },
      { label:'💳 Pay Penalty',      url:'customer_penalties.php' },
      { label:'🔙 Back',             action:'menu' },
    ]
  },
  contact: {
    msg:'📞 <b>Contact Velveth Lending:</b><br>📧 velveth869@gmail.com<br>📞 (123) 456-7890<br>📍 Claveria, Davao City<br>🕐 Mon–Sat: 8AM–5PM',
    choices:[
      { label:'📧 Email Us', url:'mailto:velveth869@gmail.com' },
      { label:'🔙 Back',     action:'menu' },
    ]
  },
  balance: {
    msg:'💰 You can check your outstanding balance on the <b>Dashboard</b> or <b>My Loan</b> page.',
    choices:[
      { label:'→ Dashboard',  url:'customer_dashboard.php' },
      { label:'→ My Loan',    url:'customer_loan.php' },
      { label:'🔙 Back',      action:'menu' },
    ]
  },
  default: {
    msg:'🤔 I\'m not sure about that. Here\'s what I can help with:',
    choices:[
      { label:'💰 Apply for Loan',    action:'apply' },
      { label:'💳 Make Payment',      action:'payment' },
      { label:'📋 Loan Status',       action:'status' },
      { label:'⚠️ Penalties',         action:'penalty' },
    ]
  }
};

// ── History helpers ────────────────────────────────────────────────────────
function getHistory() {
  try { return JSON.parse(localStorage.getItem(CHAT_STORAGE_KEY)||'[]'); }
  catch(e) { return []; }
}
function saveHistory(msgs) {
  try { localStorage.setItem(CHAT_STORAGE_KEY, JSON.stringify(msgs.slice(-60))); }
  catch(e) {}
}
function clearChatHistory() {
  localStorage.removeItem(CHAT_STORAGE_KEY);
  document.getElementById('chatMessages').innerHTML = '';
  renderBotMsg(KB.menu.msg);
  renderChoices(KB.menu.choices);
}

// ── Render helpers ─────────────────────────────────────────────────────────
function renderMsg(text, type, save=true) {
  const wrap = document.getElementById('chatMessages');
  const d    = document.createElement('div');
  d.className = 'chat-msg ' + type;
  d.innerHTML = text.replace(/\n/g, '<br>');
  wrap.appendChild(d);
  wrap.scrollTop = wrap.scrollHeight;
  if (save) {
    const hist = getHistory();
    hist.push({ type, text });
    saveHistory(hist);
  }
}
function renderBotMsg(text, save=true) { renderMsg(text, 'bot', save); }
function renderUserMsg(text, save=true) { renderMsg(text, 'user', save); }

function renderChoices(choices) {
  const wrap = document.getElementById('chatMessages');
  const d    = document.createElement('div');
  d.className = 'chat-choices';
  choices.forEach(ch => {
    const b = document.createElement('button');
    b.className = 'chat-choice-btn';
    b.textContent = ch.label;
    if (ch.url)    b.onclick = () => window.location.href = ch.url;
    if (ch.action) b.onclick = () => { renderUserMsg(ch.label); handleAction(ch.action); };
    d.appendChild(b);
  });
  wrap.appendChild(d);
  wrap.scrollTop = wrap.scrollHeight;
}

// ── Action handler ─────────────────────────────────────────────────────────
function handleAction(key) {
  const resp = KB[key] || KB.default;
  setTimeout(() => {
    renderBotMsg(resp.msg);
    if (resp.choices) renderChoices(resp.choices);
  }, 300);
}

// ── Text input handler ─────────────────────────────────────────────────────
function sendChat() {
  const inp  = document.getElementById('chatInput');
  const text = inp.value.trim();
  if (!text) return;
  inp.value = '';
  renderUserMsg(text);

  const tl = text.toLowerCase();
  let key = 'default';
  if (/apply|loan|borrow|pautang|mag-apply/.test(tl))    key = 'apply';
  else if (/pay|gcash|bank|transfer|bayad/.test(tl))      key = 'payment';
  else if (/status|balance|remaining|check/.test(tl))     key = 'balance';
  else if (/penalt|late|overdue|multa/.test(tl))          key = 'penalty';
  else if (/contact|email|phone|address|office/.test(tl)) key = 'contact';
  else if (/document|id|selfie|requirement/.test(tl))     key = 'docs';
  else if (/hi|hello|helo|kumusta|good/.test(tl))         key = 'menu';

  handleAction(key);
}

// ── Open / close ───────────────────────────────────────────────────────────
function toggleChatbot() {
  const bubble = document.getElementById('chatbotBubble');
  if (bubble.classList.contains('open')) closeChatbot();
  else openChatbot();
}
function openChatbot() {
  document.getElementById('chatbotBubble').classList.add('open');
  document.getElementById('chatToggleBtn').textContent = '✕';
  sessionStorage.setItem(CHAT_OPEN_KEY, '1');
  document.getElementById('chatInput').focus();
}
function closeChatbot() {
  document.getElementById('chatbotBubble').classList.remove('open');
  document.getElementById('chatToggleBtn').textContent = '🤖';
  sessionStorage.setItem(CHAT_OPEN_KEY, '0');
}

// ── Init: restore history or show welcome ──────────────────────────────────
(function init() {
  const wrap   = document.getElementById('chatMessages');
  const hist   = getHistory();
  if (hist.length > 0) {
    // Restore previous messages
    hist.forEach(m => renderMsg(m.text, m.type, false));
    // Show quick "continue" choices
    const cont = document.createElement('div');
    cont.className = 'chat-choices';
    cont.style.marginTop = '8px';
    [
      { label:'💰 Apply for Loan', action:'apply' },
      { label:'💳 Make Payment',   action:'payment' },
      { label:'🔙 Main Menu',      action:'menu' },
    ].forEach(ch => {
      const b = document.createElement('button');
      b.className = 'chat-choice-btn';
      b.textContent = ch.label;
      b.onclick = () => { renderUserMsg(ch.label); handleAction(ch.action); };
      cont.appendChild(b);
    });
    wrap.appendChild(cont);
    wrap.scrollTop = wrap.scrollHeight;
  } else {
    // Fresh welcome
    renderBotMsg(KB.menu.msg, false);
    renderChoices(KB.menu.choices);
  }

  // Restore open state
  if (sessionStorage.getItem(CHAT_OPEN_KEY) === '1') {
    openChatbot();
  }
})();
</script>
