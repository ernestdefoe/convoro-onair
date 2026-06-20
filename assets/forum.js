// Convoro extension: OnAir (forum surface) — per-user live streaming.
// Shipped prebuilt — no build step.
//
// Three jobs, all driven by the public presence endpoint /api/ext/onair/live:
//   1. Keep window.Convoro.setLiveUsers(...) populated (on load + every 30s) so
//      the shared Avatar component shows a LIVE badge on streamers everywhere.
//   2. forum:sidebar → a "Live now" card listing live streamers (hidden if none).
//   3. user:menu → a "Go Live" / "End stream" control (modal built in JS) for
//      members who hold the onair.broadcast permission.

const c = window.Convoro;

function tr(key) {
  return c && typeof c.t === 'function' ? c.t(key) : key;
}

const TOK = {
  surface: 'rgb(var(--c-surface,255 255 255))',
  surface2: 'rgb(var(--c-surface-2,245 246 250))',
  border: 'rgb(var(--c-border,230 232 240))',
  text: 'rgb(var(--c-text,27 32 48))',
  text2: 'rgb(var(--c-text-2,74 81 104))',
  muted: 'rgb(var(--c-muted,138 144 166))',
  primary: 'rgb(var(--c-primary,91 91 214))',
};

const AV_COLORS = ['#5b5bd6', '#5b5bd6', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'];

function el(tag, css, text) {
  const n = document.createElement(tag);
  if (css) n.style.cssText = css;
  if (text != null) n.textContent = text;
  return n;
}

const csrf = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
const JSON_HEADERS = { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', Accept: 'application/json' };

// Inject the pulse keyframe once.
function ensureStyle() {
  if (document.getElementById('oa-fx')) return;
  const s = document.createElement('style');
  s.id = 'oa-fx';
  s.textContent = '@keyframes oa-pulse{0%,100%{opacity:1}50%{opacity:.4}}';
  document.head.appendChild(s);
}

// ---- presence: keep the live-user set + sidebar card fresh ----
let lastLive = [];
const sidebarHosts = new Set();

function refreshLive() {
  return fetch('/api/ext/onair/live', { headers: { Accept: 'application/json' } })
    .then((r) => (r.ok ? r.json() : []))
    .then((list) => {
      lastLive = Array.isArray(list) ? list : [];
      if (c && typeof c.setLiveUsers === 'function') {
        c.setLiveUsers(lastLive.map((s) => s.userId));
      }
      sidebarHosts.forEach((host) => renderSidebar(host));
    })
    .catch(() => { /* silent */ });
}

function avatarBubble(av, size) {
  size = size || 30;
  const base = 'width:' + size + 'px;height:' + size + 'px;border-radius:50%;flex-shrink:0;display:inline-flex;'
    + 'align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:12px;'
    + 'background-size:cover;background-position:center';
  if (av && av.avatar) {
    const n = el('span', base + ';background-color:' + TOK.surface2);
    n.style.backgroundImage = "url('" + av.avatar + "')";
    return n;
  }
  const color = AV_COLORS[(av && av.color) || 1] || AV_COLORS[1];
  return el('span', base + ';background:' + color, (av && av.initials) || '?');
}

// ---- forum:sidebar — "Live now" card ----
function renderSidebar(host) {
  host.innerHTML = '';
  if (!lastLive.length) return; // hide entirely when no one is live
  ensureStyle();

  const card = el('div', [
    'overflow:hidden', 'border-radius:var(--c-radius,12px)',
    'border:1px solid ' + TOK.border, 'background:' + TOK.surface,
    'box-shadow:0 1px 2px rgba(0,0,0,.04)', 'margin-bottom:16px',
  ].join(';'));

  const head = el('div', 'display:flex;align-items:center;gap:8px;padding:12px 16px;background:rgb(var(--c-primary,91 91 214) / .10);border-bottom:1px solid ' + TOK.border);
  const pill = el('span', 'display:inline-flex;align-items:center;gap:5px;background:#e0245e;color:#fff;font-size:10px;font-weight:800;letter-spacing:.05em;padding:3px 8px;border-radius:999px;text-transform:uppercase');
  pill.appendChild(el('span', 'width:6px;height:6px;border-radius:50%;background:#fff;animation:oa-pulse 1.6s ease-in-out infinite'));
  pill.appendChild(document.createTextNode(tr('Live')));
  head.appendChild(pill);
  head.appendChild(el('b', 'font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:rgb(var(--c-primary-700,66 66 181))', tr('Live now')));
  card.appendChild(head);

  const list = el('div', null);
  lastLive.slice(0, 8).forEach((s, i) => {
    const row = el('a', [
      'display:flex', 'gap:10px', 'align-items:center', 'padding:10px 16px',
      'text-decoration:none', i < Math.min(lastLive.length, 8) - 1 ? 'border-bottom:1px solid ' + TOK.border : '',
    ].join(';'));
    row.href = s.url;
    row.appendChild(avatarBubble(s.avatar, 34));
    const body = el('div', 'min-width:0;flex:1');
    body.appendChild(el('div', 'font-weight:700;color:' + TOK.text + ';white-space:nowrap;overflow:hidden;text-overflow:ellipsis', s.name));
    if (s.title) {
      body.appendChild(el('div', 'font-size:12px;color:' + TOK.muted + ';white-space:nowrap;overflow:hidden;text-overflow:ellipsis', s.title));
    }
    row.appendChild(body);
    list.appendChild(row);
  });
  card.appendChild(list);

  const foot = el('a', 'display:block;padding:10px 16px;text-align:center;font-size:13px;font-weight:600;text-decoration:none;color:' + TOK.primary, tr('See all'));
  foot.href = '/live';
  card.appendChild(foot);
  host.appendChild(card);
}

// ---- user:menu — Go Live / End stream ----
// The host `el` is a bare <div> rendered between the menu's <Link> items. We add
// a single item styled to match core's menu links (block, px-4 py-2.5, text-sm,
// font-medium, hover:bg-surface-2). Core uses Tailwind tokens, so we mirror the
// equivalent CSS-var styling inline to stay theme-accurate without the classes.
const MENU_ITEM_CSS = 'display:flex;align-items:center;gap:8px;width:100%;padding:10px 16px;'
  + 'font-size:14px;font-weight:500;text-align:left;cursor:pointer;background:none;border:0;'
  + 'color:' + TOK.text2 + ';text-decoration:none;font-family:inherit';

function mountUserMenu(host) {
  fetch('/api/ext/onair/me', { headers: { Accept: 'application/json' } })
    .then((r) => (r.ok ? r.json() : null))
    .then((me) => {
      if (!me || !me.canBroadcast) return;
      renderMenuItem(host, me);
    })
    .catch(() => { /* silent */ });
}

function renderMenuItem(host, me) {
  host.innerHTML = '';
  const item = el('button', MENU_ITEM_CSS);
  item.type = 'button';
  item.addEventListener('mouseenter', () => { item.style.background = TOK.surface2; item.style.color = TOK.text; });
  item.addEventListener('mouseleave', () => { item.style.background = 'none'; item.style.color = TOK.text2; });

  if (me.live) {
    item.appendChild(el('span', 'color:#e0245e', '⏹'));
    item.appendChild(document.createTextNode(tr('End stream')));
    item.addEventListener('click', () => endStream(host));
  } else {
    item.appendChild(el('span', 'color:#e0245e', '🔴'));
    item.appendChild(document.createTextNode(tr('Go Live')));
    item.addEventListener('click', () => openGoLiveModal(host));
  }
  host.appendChild(item);
}

function endStream(host) {
  fetch('/api/ext/onair/end', { method: 'POST', headers: JSON_HEADERS })
    .then(() => refreshLive())
    .then(() => mountUserMenu(host))
    .catch(() => { /* silent */ });
}

function openGoLiveModal(host) {
  ensureStyle();
  const overlay = el('div', 'position:fixed;inset:0;z-index:1000;display:flex;align-items:flex-start;justify-content:center;padding:64px 16px;background:rgba(0,0,0,.45)');
  const card = el('div', 'width:100%;max-width:420px;background:' + TOK.surface + ';border:1px solid ' + TOK.border + ';border-radius:16px;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.25)');

  card.appendChild(el('div', 'font-size:1.2rem;font-weight:800;color:' + TOK.text + ';margin-bottom:4px', tr('Go Live')));
  card.appendChild(el('p', 'font-size:13px;color:' + TOK.muted + ';margin:0 0 16px', tr('Paste a YouTube video ID or a Twitch channel — a LIVE badge appears on your avatar everywhere.')));

  const fieldLabel = (txt) => el('label', 'display:block;font-size:13px;font-weight:600;color:' + TOK.text2 + ';margin:12px 0 5px', txt);
  const inputCss = 'width:100%;font:inherit;font-size:14px;padding:10px 12px;border-radius:10px;box-sizing:border-box;'
    + 'border:1px solid ' + TOK.border + ';background:' + TOK.surface2 + ';color:' + TOK.text;

  card.appendChild(fieldLabel(tr('Platform')));
  const platform = el('select', inputCss);
  [['youtube', 'YouTube'], ['twitch', 'Twitch']].forEach(([v, lbl]) => {
    const o = el('option', null, lbl); o.value = v; platform.appendChild(o);
  });
  card.appendChild(platform);

  card.appendChild(fieldLabel(tr('Stream ID / channel')));
  const streamId = el('input', inputCss);
  streamId.placeholder = tr('YouTube video ID or Twitch channel name');
  card.appendChild(streamId);
  const hint = el('p', 'font-size:12px;color:' + TOK.muted + ';margin:5px 0 0', tr('YouTube: the video ID from the watch URL. Twitch: your channel name.'));
  card.appendChild(hint);

  card.appendChild(fieldLabel(tr('Title (optional)')));
  const title = el('input', inputCss);
  title.placeholder = tr('What are you streaming?');
  card.appendChild(title);

  const msg = el('div', 'font-size:13px;color:#e5484d;margin-top:10px;min-height:18px');
  card.appendChild(msg);

  const actions = el('div', 'display:flex;gap:8px;justify-content:flex-end;margin-top:16px');
  const cancel = el('button', 'font:inherit;font-size:13.5px;font-weight:700;padding:9px 15px;border-radius:10px;cursor:pointer;border:1px solid ' + TOK.border + ';background:' + TOK.surface2 + ';color:' + TOK.text, tr('Cancel'));
  cancel.type = 'button';
  const go = el('button', 'font:inherit;font-size:13.5px;font-weight:700;padding:9px 15px;border-radius:10px;cursor:pointer;border:1px solid ' + TOK.primary + ';background:' + TOK.primary + ';color:#fff', tr('Go Live'));
  go.type = 'button';
  actions.appendChild(cancel);
  actions.appendChild(go);
  card.appendChild(actions);

  function close() { overlay.remove(); }
  cancel.addEventListener('click', close);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

  go.addEventListener('click', () => {
    const sid = streamId.value.trim();
    if (!sid) { msg.textContent = tr('Enter a stream ID or channel.'); return; }
    go.disabled = true; msg.style.color = TOK.muted; msg.textContent = tr('Going live…');
    fetch('/api/ext/onair/go-live', {
      method: 'POST',
      headers: JSON_HEADERS,
      body: JSON.stringify({ platform: platform.value, stream_id: sid, title: title.value.trim() || null }),
    })
      .then((r) => { if (!r.ok) throw new Error('go-live failed'); })
      .then(() => refreshLive())
      .then(() => { close(); mountUserMenu(host); })
      .catch(() => { go.disabled = false; msg.style.color = '#e5484d'; msg.textContent = tr("Couldn't go live."); });
  });

  overlay.appendChild(card);
  document.body.appendChild(overlay);
  streamId.focus();
}

// ---- register slots + start polling ----
if (c && typeof c.registerSlot === 'function') {
  c.registerSlot('forum:sidebar', {
    ext: 'convoro-onair',
    label: 'Live now',
    order: -30,
    mount(host) {
      sidebarHosts.add(host);
      renderSidebar(host);
      return () => sidebarHosts.delete(host);
    },
  });

  c.registerSlot('user:menu', {
    ext: 'convoro-onair',
    label: 'Go Live',
    mount(host) { mountUserMenu(host); },
  });
}

refreshLive();
setInterval(refreshLive, 30000);
