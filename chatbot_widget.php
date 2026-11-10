<?php
$_chatbot_page = basename($_SERVER['PHP_SELF'], '.php');
$_chatbot_role = $_SESSION['role'] ?? 'guest';

// Contextual greeting per page
$_chatbot_hints = [
  'admin_dashboard'        => 'Try: "How do I approve a loan?" or "What do the stats mean?"',
  'admin_customers'        => 'Try: "How do I add a customer?" or "How do I reset a password?"',
  'admin_loan_applicants'  => 'Try: "How do I approve an application?" or "What is loan monitoring?"',
  'admin_payments'         => 'Try: "How do I confirm a payment?" or "How do I record cash?"',
  'admin_penalties'        => 'Try: "How do I issue a penalty?" or "How do I waive a penalty?"',
  'admin_documents'        => 'Try: "How do I verify a document?" or "How do I qualify an applicant?"',
  'admin_reports'          => 'Try: "How do I export a CSV?" or "What reports are available?"',
  'admin_notifications'    => 'Try: "How do I mark all as read?" or "What triggers a notification?"',
  'admin_users'            => 'Try: "How do I add an admin?" or "How do I change someone\'s role?"',
  'admin_audit_trail'      => 'Try: "What is the audit trail?" or "How do I filter by type?"',
  'admin_archive'          => 'Try: "How do I restore an application?" or "Can I undo a deletion?"',
  'customer_dashboard'     => 'Try: "How do I pay my loan?" or "When is my next payment?"',
  'customer_loan'          => 'Try: "What is my outstanding balance?" or "How is interest computed?"',
  'customer_payments'      => 'Try: "How do I submit a payment?" or "What payment methods are accepted?"',
  'customer_documents'     => 'Try: "What documents do I need to upload?" or "Why was my document rejected?"',
  'customer_penalties'     => 'Try: "Why do I have a penalty?" or "How do I pay a penalty?"',
  'customer_profile'       => 'Try: "How do I change my password?" or "How do I update my info?"',
  'customer_notifications' => 'Try: "What do notifications mean?" or "How do I clear all?"',
];
$_hint = $_chatbot_hints[$_chatbot_page] ?? 'Ask me anything about this app!';
?>
<!-- ═══════════════════════════════════════════════════════
     VelBot — Floating Chatbot Widget
     ═══════════════════════════════════════════════════════ -->
<style>
#velbot-fab {
  position:fixed; bottom:calc(140px + env(safe-area-inset-bottom,0px)); right:16px;
  width:52px; height:52px; border-radius:50%;
  background:linear-gradient(135deg,#7a1e2e,#c0394d);
  color:white; font-size:22px; border:none; cursor:pointer;
  box-shadow:0 4px 20px rgba(122,30,46,.45);
  display:flex; align-items:center; justify-content:center;
  z-index:900; transition:transform .18s ease, box-shadow .18s ease;
}
#velbot-fab:hover { transform:scale(1.08); box-shadow:0 6px 28px rgba(122,30,46,.55); }
#velbot-fab .vb-badge {
  position:absolute; top:-2px; right:-2px; background:#ef4444; color:white;
  font-size:9px; font-weight:800; width:16px; height:16px;
  border-radius:50%; display:flex; align-items:center; justify-content:center;
  border:2px solid white; display:none;
}

/* Sheet */
#velbot-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; display:none; }
#velbot-sheet {
  position:fixed; left:0; right:0; bottom:0;
  background:white; border-radius:20px 20px 0 0;
  box-shadow:0 -8px 40px rgba(0,0,0,.2);
  z-index:1001; display:flex; flex-direction:column;
  height:72dvh; max-height:72dvh; transform:translateY(100%);
  transition:transform .28s cubic-bezier(.32,.72,0,1);
}
#velbot-sheet.open { transform:translateY(0); }
#velbot-overlay.open { display:block; }

.vb-header {
  background:linear-gradient(135deg,#7a1e2e,#c0394d);
  border-radius:20px 20px 0 0; padding:16px 18px 14px;
  display:flex; align-items:center; gap:12px; flex-shrink:0;
}
.vb-header-avatar {
  width:40px; height:40px; border-radius:50%;
  background:rgba(255,255,255,.18); font-size:20px;
  display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.vb-header-info { flex:1; min-width:0; }
.vb-header-name { font-size:15px; font-weight:800; color:white; }
.vb-header-sub  { font-size:11px; color:rgba(255,255,255,.7); margin-top:1px; }
.vb-close { background:rgba(255,255,255,.15); border:none; color:white; width:32px; height:32px; border-radius:50%; font-size:18px; cursor:pointer; display:flex; align-items:center; justify-content:center; }

/* Quick suggestions */
.vb-hints { padding:10px 14px 4px; display:flex; gap:7px; overflow-x:auto; flex-shrink:0; -webkit-overflow-scrolling:touch; }
.vb-hint-chip { background:#f0eef8; color:#7a1e2e; border:1px solid #e8d8df; border-radius:100px; padding:5px 12px; font-size:11px; font-weight:700; white-space:nowrap; cursor:pointer; flex-shrink:0; }
.vb-hint-chip:hover { background:#e8d8df; }

/* Messages */
#velbot-msgs {
  flex:1; overflow-y:auto; padding:12px 14px 8px;
  display:flex; flex-direction:column; gap:10px;
  -webkit-overflow-scrolling:touch;
}
.vb-msg { display:flex; gap:8px; align-items:flex-end; }
.vb-msg.user { flex-direction:row-reverse; }
.vb-bubble {
  max-width:78%; padding:10px 13px; border-radius:16px;
  font-size:13px; line-height:1.55; word-break:break-word;
}
.vb-msg.bot .vb-bubble  { background:#f3f4f6; color:#1a1a2e; border-bottom-left-radius:4px; }
.vb-msg.user .vb-bubble { background:linear-gradient(135deg,#7a1e2e,#c0394d); color:white; border-bottom-right-radius:4px; }
.vb-avatar-sm { width:28px; height:28px; border-radius:50%; background:linear-gradient(135deg,#7a1e2e,#c0394d); color:white; font-size:13px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.vb-time { font-size:10px; color:#b0b0b0; margin-top:3px; text-align:center; }
.vb-typing { display:flex; gap:4px; align-items:center; padding:11px 14px; }
.vb-dot { width:7px; height:7px; background:#c0394d; border-radius:50%; animation:vbPulse 1.2s infinite; opacity:.6; }
.vb-dot:nth-child(2) { animation-delay:.2s; }
.vb-dot:nth-child(3) { animation-delay:.4s; }
@keyframes vbPulse { 0%,80%,100%{transform:scale(1)} 40%{transform:scale(1.3);opacity:1} }

/* Input */
.vb-input-row {
  display:flex; gap:9px; padding:10px 14px calc(10px + env(safe-area-inset-bottom,0px));
  border-top:1px solid #f0f0f0; flex-shrink:0; align-items:flex-end;
}
#velbot-input {
  flex:1; border:1.5px solid #e5e7eb; border-radius:20px;
  padding:9px 14px; font-size:13.5px; outline:none; resize:none;
  max-height:90px; overflow-y:auto; line-height:1.45;
  font-family:inherit;
}
#velbot-input:focus { border-color:#c0394d; }
#velbot-send {
  width:40px; height:40px; border-radius:50%;
  background:linear-gradient(135deg,#7a1e2e,#c0394d);
  border:none; color:white; font-size:17px; cursor:pointer;
  display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
#velbot-send:disabled { opacity:.5; cursor:default; }
</style>

<!-- FAB button -->
<button id="velbot-fab" onclick="velbotOpen()" title="Ask VelBot">
  🤖
  <span class="vb-badge" id="vb-unread"></span>
</button>

<!-- Overlay -->
<div id="velbot-overlay" onclick="velbotClose()"></div>

<!-- Chat Sheet -->
<div id="velbot-sheet">
  <div class="vb-header">
    <div class="vb-header-avatar">🤖</div>
    <div class="vb-header-info">
      <div class="vb-header-name">VelBot — App Guide</div>
      <div class="vb-header-sub">Online · <?= $_chatbot_role === 'admin' ? '⚙️ Admin Mode' : '👤 Customer Mode' ?></div>
    </div>
    <button class="vb-close" onclick="velbotClose()">✕</button>
  </div>

  <!-- Quick hint chips -->
  <div class="vb-hints" id="vb-hints-row">
    <?php
      $hints_list = [
        ['How do I use this page?', 'How do I use this page?'],
        ['What can I do here?',     'What can I do here?'],
    ];
    // Add page-specific chips
    if ($_chatbot_page === 'admin_loan_applicants' || $_chatbot_page === 'customer_loan') {
        $hints_list[] = ['Approve a loan', 'How do I approve a loan application?'];
    }
    if (strpos($_chatbot_page, 'payment') !== false) {
        $hints_list[] = ['Submit payment', 'How do I submit or confirm a payment?'];
    }
    if (strpos($_chatbot_page, 'document') !== false) {
        $hints_list[] = ['Upload docs', 'What documents do I need to upload?'];
    }
    foreach ($hints_list as $h): ?>
    <div class="vb-hint-chip" onclick="velbotSendQuick('<?= htmlspecialchars(addslashes($h[1])) ?>')"><?= htmlspecialchars($h[0]) ?></div>
    <?php endforeach; ?>
  </div>

  <!-- Messages -->
  <div id="velbot-msgs"></div>

  <!-- Input -->
  <div class="vb-input-row">
    <textarea id="velbot-input" rows="1" placeholder="Ask anything about this app…"
      onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();velbotSend()}"
      oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,90)+'px'"></textarea>
    <button id="velbot-send" onclick="velbotSend()">➤</button>
  </div>
</div>

<script>
(function(){
  var msgs = document.getElementById('velbot-msgs');
  var input= document.getElementById('velbot-input');
  var send = document.getElementById('velbot-send');
  var fab  = document.getElementById('velbot-fab');
  var badge= document.getElementById('vb-unread');
  var _page= '<?= $_chatbot_page ?>';
  var _opened = false;
  var _unread = 0;

  // Welcome message on first open
  function welcome() {
    addMsg('bot', '👋 Hi<?= $_SESSION['fullname'] ? ', '.htmlspecialchars(explode(' ',$_SESSION['fullname'])[0]) : '' ?>! I\'m VelBot, your Velveth Lending guide.\n\n<?= addslashes($_hint) ?>');
  }

  function addMsg(who, text) {
    var d    = document.createElement('div');
    d.className = 'vb-msg ' + who;
    var now  = new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'});
    // Format newlines and bold (**)
    var html = text
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>')
      .replace(/\n/g,'<br>');
    d.innerHTML = who === 'bot'
      ? '<div class="vb-avatar-sm">🤖</div><div><div class="vb-bubble">'+html+'</div><div class="vb-time">'+now+'</div></div>'
      : '<div><div class="vb-bubble">'+html+'</div><div class="vb-time" style="text-align:right;">'+now+'</div></div><div class="vb-avatar-sm" style="background:linear-gradient(135deg,#2563eb,#7c3aed);">👤</div>';
    msgs.appendChild(d);
    msgs.scrollTop = msgs.scrollHeight;
    if (who === 'bot' && !_opened) { _unread++; badge.textContent=_unread; badge.style.display='flex'; }
  }

  function addTyping() {
    var d = document.createElement('div');
    d.className = 'vb-msg bot'; d.id='vb-typing';
    d.innerHTML = '<div class="vb-avatar-sm">🤖</div><div class="vb-bubble vb-typing"><div class="vb-dot"></div><div class="vb-dot"></div><div class="vb-dot"></div></div>';
    msgs.appendChild(d); msgs.scrollTop = msgs.scrollHeight;
  }
  function removeTyping() { var t=document.getElementById('vb-typing'); if(t) t.remove(); }

  function doSend(text) {
    text = text.trim(); if (!text) return;
    addMsg('user', text);
    input.value=''; input.style.height='auto'; send.disabled=true;
    // Hide hints after first message
    var hr=document.getElementById('vb-hints-row'); if(hr) hr.style.display='none';
    addTyping();
    fetch('api_chatbot.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({message: text, page: _page})
    })
    .then(function(r){return r.json();})
    .then(function(d){
      removeTyping();
      addMsg('bot', d.reply || d.error || '⚠️ No response.');
    })
    .catch(function(){
      removeTyping();
      addMsg('bot','⚠️ Network error. Please check your connection and try again.');
    })
    .finally(function(){ send.disabled=false; input.focus(); });
  }

  window.velbotSend = function(){
    var t = input.value; if(t.trim()) doSend(t);
  };

  window.velbotSendQuick = function(text){ doSend(text); };

  window.velbotOpen = function(){
    document.getElementById('velbot-overlay').classList.add('open');
    document.getElementById('velbot-sheet').classList.add('open');
    _opened = true; _unread = 0; badge.style.display='none';
    if (msgs.children.length === 0) welcome();
    setTimeout(function(){ input.focus(); }, 350);
  };

  // Hide chatbot FAB when add/edit sheets open
  document.querySelectorAll('.sheet-overlay').forEach(function(ov){
    var obs = new MutationObserver(function(){
      var anyOpen = document.querySelector('.sheet-overlay.open') !== null;
      fab.style.display = anyOpen ? 'none' : 'flex';
    });
    obs.observe(ov, {attributes:true, attributeFilter:['class']});
  });

  window.velbotClose = function(){
    document.getElementById('velbot-overlay').classList.remove('open');
    document.getElementById('velbot-sheet').classList.remove('open');
  };
})();
</script>
