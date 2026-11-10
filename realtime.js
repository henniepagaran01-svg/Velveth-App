/**
 * Velveth Real-Time Polling
 * Polls api_poll.php every 15 seconds.
 * Shows toast notifications for new data.
 * Updates badge counters automatically.
 */
(function() {
  'use strict';

  let lastCheck = new Date().toISOString().slice(0,19).replace('T',' ');
  let toastContainer = null;

  function ensureToastContainer() {
    if (!toastContainer) {
      toastContainer = document.createElement('div');
      toastContainer.id = 'rtToastContainer';
      toastContainer.style.cssText = 'position:fixed;top:70px;right:16px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none;max-width:340px;';
      document.body.appendChild(toastContainer);
    }
    return toastContainer;
  }

  function showToast(msg, type, url) {
    const tc = ensureToastContainer();
    const colors = {payment:'#f59e0b',loan:'#3b82f6',customer:'#10b981',notification:'#8b5cf6'};
    const icons  = {payment:'💳',loan:'📋',customer:'👤',notification:'🔔'};
    const bg     = colors[type] || '#8B2A3A';
    const ic     = icons[type]  || '🔔';

    const t = document.createElement('div');
    t.style.cssText = `background:white;border-radius:10px;padding:12px 16px;box-shadow:0 4px 20px rgba(0,0,0,.15);border-left:4px solid ${bg};pointer-events:all;cursor:${url?'pointer':'default'};display:flex;align-items:flex-start;gap:10px;animation:rtSlideIn .3s ease;`;
    t.innerHTML = `<span style="font-size:18px;flex-shrink:0;">${ic}</span><div style="flex:1;min-width:0;"><div style="font-size:12px;font-weight:700;color:#1e0d10;">${msg}</div><div style="font-size:10px;color:#aaa;margin-top:2px;">Just now • Click to view</div></div><button style="background:none;border:none;color:#aaa;cursor:pointer;font-size:14px;padding:0;flex-shrink:0;" onclick="this.parentElement.remove()">✕</button>`;
    if (url) t.addEventListener('click', function(e){ if(e.target.tagName!=='BUTTON') window.location.href=url; });
    tc.appendChild(t);
    setTimeout(() => t.remove(), 8000);
  }

  function updateBadge(selector, count) {
    const el = document.querySelector(selector);
    if (!el) return;
    if (count > 0) {
      el.textContent = count > 99 ? '99+' : count;
      el.style.display = 'flex';
    } else {
      el.style.display = 'none';
    }
  }

  function poll() {
    fetch('api_poll.php?since=' + encodeURIComponent(lastCheck))
      .then(r => r.ok ? r.json() : null)
      .then(data => {
        if (!data || data.error) return;
        lastCheck = data.timestamp;

        // Show toasts for new updates
        if (data.updates && data.updates.length > 0) {
          data.updates.forEach(u => showToast(u.msg, u.type, u.url));
        }

        // Update notification badge in header
        if (typeof data.unread_notifications !== 'undefined') {
          updateBadge('.notif-badge', data.unread_notifications);
          updateBadge('.bn-badge', data.unread_notifications);
        }

        // Update sidebar nav badges
        if (typeof data.pending_payments !== 'undefined') {
          document.querySelectorAll('[data-badge="pending_payments"]').forEach(el => {
            el.textContent = data.pending_payments;
            el.style.display = data.pending_payments > 0 ? 'flex' : 'none';
          });
        }
        if (typeof data.pending_apps !== 'undefined') {
          document.querySelectorAll('[data-badge="pending_apps"]').forEach(el => {
            el.textContent = data.pending_apps;
            el.style.display = data.pending_apps > 0 ? 'flex' : 'none';
          });
        }
      })
      .catch(() => {}); // Silently ignore network errors
  }

  // Add CSS animation
  const style = document.createElement('style');
  style.textContent = '@keyframes rtSlideIn{from{transform:translateX(120%);opacity:0;}to{transform:translateX(0);opacity:1;}}';
  document.head.appendChild(style);

  // Start polling after page loads
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(() => { poll(); setInterval(poll, 15000); }, 2000));
  } else {
    setTimeout(() => { poll(); setInterval(poll, 15000); }, 2000);
  }
})();

// ─────────────────────────────────────────────────────
//  PWA — Service Worker Registration
// ─────────────────────────────────────────────────────
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('/sw.js')
      .then(function(reg) {
        console.log('[PWA] Service worker registered:', reg.scope);
      })
      .catch(function(err) {
        console.log('[PWA] Service worker registration failed:', err);
      });
  });
}

// ── Install prompt handling (Android/Desktop "Add to Home Screen") ──
let _deferredInstallPrompt = null;
window.addEventListener('beforeinstallprompt', function(e) {
  e.preventDefault();
  _deferredInstallPrompt = e;
  const btn = document.getElementById('pwaInstallBtn');
  if (btn) btn.style.display = 'flex';
});

window.triggerPWAInstall = function() {
  if (!_deferredInstallPrompt) return;
  _deferredInstallPrompt.prompt();
  _deferredInstallPrompt.userChoice.then(function(choice) {
    _deferredInstallPrompt = null;
    const btn = document.getElementById('pwaInstallBtn');
    if (btn) btn.style.display = 'none';
  });
};

window.addEventListener('appinstalled', function() {
  console.log('[PWA] App installed');
  const btn = document.getElementById('pwaInstallBtn');
  if (btn) btn.style.display = 'none';
});
