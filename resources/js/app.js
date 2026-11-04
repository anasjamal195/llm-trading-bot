import './bootstrap';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const conf = window.PUSHER_CONF || {};
let echo = null;
try {
  echo = new Echo({
    broadcaster: 'pusher',
    key: conf.key,
    wsHost: conf.host || window.location.hostname,
    wsPort: Number(conf.port || 6001),
    wssPort: Number(conf.port || 6001),
    forceTLS: (conf.scheme || 'http') === 'https',
    disableStats: true,
    enabledTransports: ['ws', 'wss'],
    cluster: conf.cluster || 'mt1',
  });
  window.Echo = echo;
  const dot = document.getElementById('ws-dot');
  const conn = echo.connector?.pusher?.connection;
  const paint = (ok) => { if (dot) { dot.style.color = ok ? '#10b981' : '#f59e0b'; dot.title = ok ? 'WS connected' : 'WS disconnected'; } };
  conn?.bind('connected', () => paint(true));
  conn?.bind('disconnected', () => paint(false));
} catch (e) { console.warn('Echo init failed', e); }

async function refreshStatus() {
  try {
    const res = await fetch('/admin/api/status', { headers: { 'Accept': 'application/json' } });
    if (!res.ok) return;
    const j = await res.json();
    const badge = document.getElementById('bot-badge');
    if (badge) {
      badge.textContent = 'BOT ' + j.bot.status;
      badge.className = 'text-xs font-semibold px-2 py-1 rounded ' + (
        j.bot.status === 'RUNNING' ? 'bg-emerald-200 text-emerald-900' :
        j.bot.status === 'ERROR' ? 'bg-red-200 text-red-900' :
        j.bot.status === 'DEGRADED' ? 'bg-amber-200 text-amber-900' : 'bg-slate-200 dark:bg-slate-700');
    }
  } catch {}
}
setInterval(refreshStatus, 5000);
refreshStatus();

// Live position/order updates without full reload.
if (echo) {
  const bump = (sel, id, field, val) => {
    const row = document.querySelector(`[data-position="${id}"]`);
    if (row) { const el = row.querySelector(`[data-${field}]`); if (el) el.textContent = val; }
  };
  echo.private('trading.positions')
    .listen('PaperPositionUpdated', (e) => { bump(null, e.id, 'upl', e.unrealized); bump(null, e.id, 'mark', e.price); })
    .listen('PaperPositionClosed', () => setTimeout(() => location.reload(), 1500))
    .listen('PaperPositionOpened', () => setTimeout(() => location.reload(), 1500));
  echo.private('trading.orders').listen('PaperOrderFilled', () => setTimeout(() => location.reload(), 1500));
  echo.private('bot.status').listen('BotStatusChanged', (e) => {
    const badge = document.getElementById('bot-badge');
    if (badge) badge.textContent = 'BOT ' + e.status;
  });
}
