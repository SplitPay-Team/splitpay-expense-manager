/**
 * SplitPay — Main JavaScript
 * Handles: AJAX requests, UI helpers, notifications, sidebar, modals
 */

/* ── API Helper ─────────────────────────────────────────────── */
const API = {
  async post(endpoint, action, data = {}) {
    const fd = new FormData();
    Object.entries(data).forEach(([k, v]) => {
      if (Array.isArray(v)) v.forEach(i => fd.append(k + '[]', i));
      else fd.append(k, v);
    });
    fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
    const res = await fetch(`/api/${endpoint}.php?action=${action}`, {
      method: 'POST',
      body: fd
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  },

  async get(endpoint, action, params = {}) {
    const qs = new URLSearchParams({ action, ...params }).toString();
    const res = await fetch(`/api/${endpoint}.php?${qs}`);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  }
};

/* ── Toast Notifications ────────────────────────────────────── */
const Toast = (() => {
  let container;
  function init() {
    if (container) return;
    container = document.createElement('div');
    container.style.cssText = `
      position:fixed;bottom:24px;right:24px;z-index:9999;
      display:flex;flex-direction:column;gap:10px;pointer-events:none;
    `;
    document.body.appendChild(container);
  }

  function show(message, type = 'info', duration = 4000) {
    init();
    const colors = {
      success: { bg: 'rgba(78,189,138,0.12)', border: 'rgba(78,189,138,0.3)', color: '#4ebd8a', icon: '✓' },
      error:   { bg: 'rgba(224,85,85,0.12)',  border: 'rgba(224,85,85,0.3)',  color: '#e05555', icon: '✕' },
      warning: { bg: 'rgba(224,160,48,0.12)', border: 'rgba(224,160,48,0.3)', color: '#e0a030', icon: '⚠' },
      info:    { bg: 'rgba(201,168,76,0.1)',  border: 'rgba(201,168,76,0.25)',color: '#c9a84c', icon: 'ℹ' }
    };
    const c = colors[type] || colors.info;
    const el = document.createElement('div');
    el.style.cssText = `
      background:${c.bg};border:1px solid ${c.border};color:${c.color};
      padding:11px 16px;border-radius:8px;font-size:13px;font-family:Outfit,sans-serif;
      display:flex;align-items:center;gap:9px;
      pointer-events:all;cursor:default;
      min-width:220px;max-width:340px;
      box-shadow:0 8px 32px rgba(0,0,0,0.4);
      animation:toastIn 0.3s cubic-bezier(0.34,1.56,0.64,1) both;
      backdrop-filter:blur(8px);
    `;
    el.innerHTML = `<span style="font-size:15px;">${c.icon}</span><span style="flex:1;color:#e8e6e0;">${message}</span>`;
    container.appendChild(el);

    if (!document.getElementById('toast-style')) {
      const s = document.createElement('style');
      s.id = 'toast-style';
      s.textContent = `
        @keyframes toastIn { from{opacity:0;transform:translateX(20px) scale(0.95)} to{opacity:1;transform:none} }
        @keyframes toastOut { to{opacity:0;transform:translateX(20px) scale(0.95)} }
      `;
      document.head.appendChild(s);
    }

    setTimeout(() => {
      el.style.animation = 'toastOut 0.25s ease forwards';
      setTimeout(() => el.remove(), 300);
    }, duration);
  }

  return { success: m => show(m,'success'), error: m => show(m,'error'),
           warning: m => show(m,'warning'), info: m => show(m,'info') };
})();

/* ── Modal ──────────────────────────────────────────────────── */
const Modal = {
  open(id) {
    const el = document.getElementById(id);
    if (el) { el.classList.add('open'); document.body.style.overflow = 'hidden'; }
  },
  close(id) {
    const el = document.getElementById(id);
    if (el) { el.classList.remove('open'); document.body.style.overflow = ''; }
  },
  closeAll() {
    document.querySelectorAll('.modal-overlay.open').forEach(el => {
      el.classList.remove('open');
    });
    document.body.style.overflow = '';
  }
};

/* ── Form Utilities ─────────────────────────────────────────── */
const Form = {
  serialize(formEl) {
    const data = {};
    new FormData(formEl).forEach((v, k) => {
      if (k.endsWith('[]')) {
        const key = k.slice(0, -2);
        data[key] = data[key] ? [...data[key], v] : [v];
      } else {
        data[k] = v;
      }
    });
    return data;
  },

  setLoading(btn, loading) {
    if (loading) {
      btn.dataset.originalText = btn.innerHTML;
      btn.innerHTML = '<span class="spinner"></span>';
      btn.disabled = true;
    } else {
      btn.innerHTML = btn.dataset.originalText || btn.innerHTML;
      btn.disabled = false;
    }
  },

  showError(field, message) {
    clearError(field);
    const err = document.createElement('p');
    err.className = 'form-error';
    err.textContent = message;
    field.parentElement.appendChild(err);
    field.style.borderColor = 'var(--danger)';
  },

  clearErrors(formEl) {
    formEl.querySelectorAll('.form-error').forEach(e => e.remove());
    formEl.querySelectorAll('.form-control').forEach(f => f.style.borderColor = '');
  }
};

/* ── Sidebar (Mobile) ───────────────────────────────────────── */
function initSidebar() {
  const sidebar = document.querySelector('.sidebar');
  const toggleBtn = document.getElementById('sidebar-toggle');
  if (!sidebar || !toggleBtn) return;

  toggleBtn.addEventListener('click', () => {
    sidebar.classList.toggle('open');
  });

  // Close on outside click
  document.addEventListener('click', (e) => {
    if (window.innerWidth <= 900 && !sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
      sidebar.classList.remove('open');
    }
  });
}

/* ── Active Nav Highlighting ────────────────────────────────── */
function highlightNav() {
  const path = window.location.pathname.split('/').pop();
  document.querySelectorAll('.nav-item').forEach(item => {
    const href = item.getAttribute('href') || '';
    if (href.includes(path) && path !== '') {
      item.classList.add('active');
    }
  });
}

/* ── Notification Polling ───────────────────────────────────── */
function initNotifications() {
  const badge = document.getElementById('notif-badge');
  if (!badge) return;

  async function pollNotifications() {
    try {
      const res = await API.get('notifications', 'list');
      if (res.success) {
        const count = res.data.filter(n => !n.is_read).length;
        if (count > 0) {
          badge.textContent = count > 9 ? '9+' : count;
          badge.classList.remove('hidden');
        } else {
          badge.classList.add('hidden');
        }
        // Update sidebar badge too
        const navBadge = document.querySelector('.nav-item[href*="notifications"] .nav-badge');
        if (navBadge) {
          navBadge.textContent = count;
          navBadge.style.display = count > 0 ? '' : 'none';
        }
      }
    } catch(e) { /* silent */ }
  }

  pollNotifications();
  setInterval(pollNotifications, 30000); // every 30s
}

/* ── Confirm Dialog ─────────────────────────────────────────── */
function confirmDialog(message) {
  return new Promise(resolve => {
    // Use native confirm as fallback (styled by browser)
    resolve(window.confirm(message));
  });
}

/* ── Amount Formatter ───────────────────────────────────────── */
function formatAmount(amount, currency = 'LKR') {
  return `${currency} ${parseFloat(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}`;
}

/* ── Date Formatter ─────────────────────────────────────────── */
function formatDate(dateStr) {
  if (!dateStr) return '—';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function timeAgo(dateStr) {
  const d = new Date(dateStr);
  const diff = (Date.now() - d) / 1000;
  if (diff < 60) return 'just now';
  if (diff < 3600) return `${Math.floor(diff/60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff/3600)}h ago`;
  return `${Math.floor(diff/86400)}d ago`;
}

/* ── Initialize ─────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  initSidebar();
  highlightNav();
  initNotifications();

  // Modal close on overlay click
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) Modal.closeAll();
    });
  });

  // Escape key closes modal
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') Modal.closeAll();
  });

  // Auto-hide alerts after 5s
  document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
      alert.style.transition = 'opacity 0.4s ease';
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 400);
    }, 5000);
  });
});
