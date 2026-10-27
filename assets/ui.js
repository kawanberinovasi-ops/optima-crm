/* OPTIMA CRM · helper UI, ikon, navbar, overlay */
'use strict';
const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const DAYS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
const fmtDate = (s) => { if (!s) return '-'; const d = new Date(String(s).slice(0, 10) + 'T00:00:00'); if (isNaN(d)) return esc(s); return d.getDate() + ' ' + MON[d.getMonth()] + ' ' + d.getFullYear(); };
const fmtShort = (s) => { if (!s) return '-'; const d = new Date(String(s).slice(0, 10) + 'T00:00:00'); return isNaN(d) ? esc(s) : d.getDate() + ' ' + MON[d.getMonth()]; };
const fmtTime = (iso) => { const d = new Date(iso); return d.getDate() + ' ' + MON[d.getMonth()] + ', ' + pad2(d.getHours()) + '.' + pad2(d.getMinutes()); };
const dayDiff = (s) => Math.round((new Date(s + 'T00:00:00') - new Date(today() + 'T00:00:00')) / 864e5);
const dec = (x) => (Math.round(x * 10) / 10).toString().replace('.', ',');
const fmtRp = (n) => { n = +n || 0; if (n >= 1e9) return 'Rp ' + dec(n / 1e9) + ' M'; if (n >= 1e6) return 'Rp ' + dec(n / 1e6) + ' jt'; return 'Rp ' + n.toLocaleString('id-ID'); };
const initials = (name) => { const w = String(name || '?').split(/\s+/).filter(x => x.length > 1 && !['PT', 'CV', 'dr.', 'Dr.'].includes(x)); return ((w[0] || '?')[0] + ((w[1] || '')[0] || '')).toUpperCase(); };
const avCls = (id) => { let h = 0; String(id).split('').forEach(c => { h = (h * 31 + c.charCodeAt(0)) % 997; }); return 'g' + (h % 4); };
const profilePhotoSource = value => typeof value === 'string' && value.length <= 150000 && /^data:image\/jpeg;base64,[A-Za-z0-9+/]+={0,2}$/.test(value) ? value : '';
const avatarContent = (name, id) => { const u = Store.db && Store.db.users.find(x => x.id === id); const src = profilePhotoSource(u && u.profile && u.profile.photo); return esc(initials(name)) + (src ? '<img class="profile-photo" src="' + src + '" alt="">' : ''); };
const av = (name, id, extra) => '<span class="av ' + avCls(id || name) + ' ' + (extra || '') + '" aria-hidden="true">' + avatarContent(name, id) + '</span>';

const P = {
  search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>', plus: '<path d="M12 5v14M5 12h14"/>',
  chat: '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>', bell: '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10 20a2 2 0 0 0 4 0"/>',
  chev: '<path d="M6 9l6 6 6-6"/>', right: '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>', left: '<path d="M19 12H5"/><path d="M11 6l-6 6 6 6"/>',
  check: '<path d="M5 12.5l4.5 4.5L19 7"/>', x: '<path d="M6 6l12 12M18 6L6 18"/>', edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
  trash: '<path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13M9 7V4h6v3"/>', up: '<path d="M12 16V4M6 10l6-6 6 6M4 20h16"/>', down: '<path d="M12 4v12M6 10l6 6 6-6M4 20h16"/>',
  list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>', code: '<path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/>', flag: '<path d="M4 22V4M4 4h13l-2 4 2 4H4"/>',
  pen: '<path d="M3 21l3.5-1 11-11-2.5-2.5-11 11zM14 6l2.5 2.5"/>', user: '<path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/>', users: '<path d="M17 21a6 6 0 0 0-12 0"/><circle cx="11" cy="7" r="4"/><path d="M21 21a5 5 0 0 0-4-5M16 3.5a4 4 0 0 1 0 7"/>',
  deal: '<rect x="3" y="7" width="18" height="13" rx="3"/><path d="M8 7V4h8v3"/>', shield: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
  log: '<path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/>', logout: '<path d="M15 4h4v16h-4"/><path d="M10 8l-4 4 4 4M6 12h11"/>', refresh: '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>',
  phone: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>', mail: '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 7l9 6 9-6"/>',
  pin: '<path d="M12 21s-7-6-7-11a7 7 0 0 1 14 0c0 5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>', cal: '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/>',
  swap: '<path d="M7 4L3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/>', lock: '<rect x="4" y="10" width="16" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>', file: '<path d="M14 3H6v18h12V7z"/><path d="M14 3v4h4"/>',
  home: '<path d="M3 11l9-7 9 7v9H3z"/><path d="M9 20v-6h6v6"/>', chart: '<path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/>', grip: '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>'
};
const ic = (n, cls) => '<svg class="ic ' + (cls || '') + '" viewBox="0 0 24 24" aria-hidden="true">' + (P[n] || '') + '</svg>';
const plane = (fill, size, cls, style) => '<svg class="optima-mark ' + (cls || '') + '" width="' + size + '" height="' + size + '" viewBox="0 0 64 64" fill="none" aria-hidden="true" style="' + (style || '') + '"><rect x="9" y="9" width="32" height="32" rx="6" style="stroke:' + fill + '" stroke-width="3"/><rect x="23" y="23" width="32" height="32" rx="6" style="fill:' + fill + '"/><path d="M32 39h14M39 32v14" style="stroke:var(--p)" stroke-width="2"/></svg>';
const stripesArt = (n) => { const tops = [22, 36, 50, 64]; let s = '<div class="hero-art">'; for (let i = 0; i < (n || 3); i++) s += '<span class="speed" style="top:' + tops[i] + '%;left:' + [0, 14, 6, 20][i] + '%;width:' + [44, 30, 38, 28][i] + '%;animation-delay:' + (i * .65) + 's"></span>'; return s + '</div>'; };
const stripesSvg = (w) => '<svg aria-hidden="true" width="' + (w || 120) + '" height="' + Math.round((w || 120) / 2) + '" viewBox="0 0 120 60" fill="none"><rect x="34" y="8" width="34" height="34" rx="6" style="stroke:var(--p3)" stroke-width="2"/><rect x="52" y="24" width="34" height="30" rx="6" style="fill:var(--y)"/><path d="M62 39h14M69 32v14" style="stroke:var(--p)" stroke-width="2"/></svg>';
const miniStripe = '<svg aria-hidden="true" width="18" height="14" viewBox="0 0 18 14"><rect x="1" y="1" width="9" height="9" rx="2" style="fill:var(--p3)"/><rect x="7" y="5" width="9" height="8" rx="2" style="fill:var(--y)"/></svg>';
const empty = (title, text, btn) => '<div class="empty enter">' + stripesSvg() + '<strong class="disp">' + title + '</strong><span>' + text + '</span>' + (btn || '') + '</div>';
const seg = (items, cur, act, label, role) => '<div class="seg" role="' + (role || 'group') + '" aria-label="' + esc(label) + '">' + items.map(([k, l, c]) => '<button type="button" data-a="' + act + '" data-v="' + esc(k) + '" ' + (role === 'tablist' ? 'role="tab" aria-selected' : 'aria-pressed') + '="' + (String(k) === String(cur)) + '">' + esc(l) + (c !== undefined ? '<span class="cnt num">' + c + '</span>' : '') + '</button>').join('') + '</div>';
const opt = (v, cur, label) => '<option value="' + esc(v) + '"' + (String(v) === String(cur) ? ' selected' : '') + '>' + esc(label == null ? v : label) + '</option>';
const fuChip = (d) => { if (!d) return ''; const n = dayDiff(d); if (n < 0) return '<span class="chip c-bad ping"><span class="dot"></span>Telat ' + (-n) + ' hari</span>'; if (n === 0) return '<span class="chip c-warn"><span class="dot"></span>Hari ini</span>'; return '<span class="chip c-info"><span class="dot"></span>' + fmtShort(d) + '</span>'; };
const statusChip = (s) => '<span class="chip ' + ({ Open: 'c-info', Won: 'c-ok', Lost: 'c-bad', 'On Hold': 'c-warn' }[s] || 'c-n') + '"><span class="dot"></span>' + esc(s) + '</span>';
const catChip = (c) => '<span class="chip ' + ({ Client: 'c-info', Lead: 'c-n', Community: 'c-y', 'KOL/KOC': 'c-y', Media: 'c-n' }[c] || 'c-n') + '">' + esc(c) + '</span>';
const valOrLock = (deal, v) => canSeeValue(deal) ? fmtRp(v) : '<span title="Nilai disembunyikan sesuai hak akses" style="display:inline-flex;align-items:center;gap:6px">' + ic('lock', 'ic-s') + 'Rp ···</span>';

/* ---------- state & router ---------- */
const App = {
  route: 'cp', anim: true, pop: null, modal: null, drawer: null, pal: null,
  ui: { cp: { cat: 'all', q: '', dept: 'all', service: 'all', page: 0, sort: 'name' }, pl: { line: 'um', per: 'all', val: '', q: '' }, mw: { f: 'all' }, db: { per: 'm', val: '' }, ak: { tab: 'users', q: '', act: 'all', by: 'all' } }
};
const ROUTES = ['login', 'mywork', 'dashboard', 'cp', 'pipeline', 'akses', 'expired'];
function parseHash() {
  const h = location.hash.replace(/^#\/?/, ''); const [r, qs] = h.split('?');
  App.route = ROUTES.includes(r) ? r : (r ? 'notfound' : 'cp');
  const q = new URLSearchParams(qs || '');
  if (App.route === 'pipeline' && q.get('line')) App.ui.pl.line = q.get('line');
  if (App.route === 'akses' && q.get('tab')) App.ui.ak.tab = q.get('tab');
  if (App.route === 'cp' && q.get('open')) App.drawer = { id: q.get('open') };
}
function go(route, extra) { App.pop = null; App.pal = null; const h = '#/' + route + (extra ? '?' + extra : ''); if (location.hash === h) { App.anim = true; render(); } else location.hash = h; }
window.addEventListener('hashchange', () => { parseHash(); App.pop = null; window.scrollTo(0, 0); if (!me() || App.noSkeleton) { App.anim = true; render(); return; } App.loading = true; render(); clearTimeout(App._lt); App._lt = setTimeout(() => { App.loading = false; App.anim = true; render(); }, 260); });

function saveFocus() { const a = document.activeElement; if (!a || !a.id) return null; let s = null, e = null; try { s = a.selectionStart; e = a.selectionEnd; } catch (x) { /* */ } return { id: a.id, s, e }; }
function restoreFocus(f) { if (!f) return; const el = document.getElementById(f.id); if (!el) return; el.focus({ preventScroll: true }); try { if (f.s != null) el.setSelectionRange(f.s, f.e); } catch (x) { /* */ } }

function render() {
  const f = saveFocus();
  const root = document.getElementById('app');
  if (!me()) { if (App.expired) { App.route = 'expired'; root.innerHTML = Pages.expired(); } else { App.route = 'login'; root.innerHTML = Pages.login(); } }
  else {
    if (App.route === 'login') App.route = 'cp';
    root.innerHTML = navbar() + '<main class="wrap ' + (App.anim ? '' : 'noanim') + '" id="main">' + (App.loading ? skeleton() : (Pages[App.route] || Pages.notfound)()) + '</main>';
  }
  document.getElementById('ov').innerHTML = overlays();
  restoreFocus(f);
  if (App.anim) { countUp(); App.anim = false; }
  const t = { mywork: 'My Work', dashboard: 'Dashboard', cp: 'Client & Partner', pipeline: 'Pipeline', akses: 'Akses & Audit', login: 'Masuk', notfound: 'Ga ketemu', expired: 'Sesi berakhir' }[App.route] || 'OPTIMA';
  document.title = t + ' · OPTIMA CRM';
}
function countUp() {
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  document.querySelectorAll('[data-count]').forEach(el => {
    const to = +el.dataset.count, fmt = el.dataset.fmt || 'n', start = performance.now();
    const out = (v) => fmt === 'rp' ? fmtRp(v) : fmt === 'pct' ? Math.round(v) + '%' : Math.round(v).toLocaleString('id-ID');
    const step = (now) => { const t = Math.min(1, (now - start) / 900), e = 1 - Math.pow(1 - t, 3); el.textContent = out(to * e); if (t < 1) requestAnimationFrame(step); };
    requestAnimationFrame(step);
  });
}
const cnt = (v, fmt) => '<span class="num" data-count="' + (+v || 0) + '" data-fmt="' + (fmt || 'n') + '">' + (fmt === 'rp' ? fmtRp(v) : fmt === 'pct' ? Math.round(v) + '%' : (+v || 0).toLocaleString('id-ID')) + '</span>';

/* ---------- toast ---------- */
function toast(title, text, kind) {
  const z = document.getElementById('toasts'); const d = document.createElement('div');
  d.className = 'toast ' + (kind || ''); d.setAttribute('role', 'status');
  d.innerHTML = (kind ? '' : plane('#000', 36, 'jump')) + '<div style="display:flex;flex-direction:column"><strong class="disp">' + esc(title) + '</strong>' + (text ? '<span style="font-size:14px;font-weight:600">' + esc(text) + '</span>' : '') + '</div>';
  z.appendChild(d); setTimeout(() => { d.style.transition = 'opacity .3s'; d.style.opacity = '0'; setTimeout(() => d.remove(), 300); }, 2600);
}

/* ---------- navbar ---------- */
function myFollowups(u) {
  u = u || me();
  return Store.db.interactions.filter(i => { const r = recById(i.recordId); return !i.done && !i.cancelled && i.fuDate && r && !r.archived && (i.by === u.id || r.picId === u.id) && canSeeRec(r); });
}
function navbar() {
  const u = me(), r = App.route, pop = App.pop;
  const fu = myFollowups().filter(i => dayDiff(i.fuDate) <= 0);
  const item = (key, label, href, drop) => '<div class="mi">' + (drop
    ? '<button type="button" class="ml" data-a="pop" data-v="' + key + '" data-pop aria-expanded="' + (pop === key) + '" aria-current="' + (r === href ? 'page' : 'false') + '">' + label + ic('chev', 'ic-s') + '</button>' + (pop === key ? drop : '')
    : '<a class="ml" href="#/' + href + '" aria-current="' + (r === href ? 'page' : 'false') + '">' + label + '</a>') + '</div>';
  const pi = (act, v, icon, t, d, y) => '<button type="button" role="menuitem" class="pi" data-a="' + act + '" data-v="' + esc(v || '') + '"><span class="tile ' + (y ? 'y' : '') + '">' + ic(icon) + '</span><span><span class="t">' + t + '</span><span class="d">' + d + '</span></span></button>';
  const cpDrop = '<div class="pop l" role="menu">' + pi('nav', 'cp', 'list', 'Semua data', 'Cari, filter, dan buka profil') + (canCreateAny() ? pi('import', '', 'up', 'Import data', 'Pakai template Excel') : '') + pi('export', '', 'down', 'Export data', 'Unduh data sesuai hak akses') + '</div>';
  const plDrop = '<div class="pop l" role="menu">' + visibleLines().map((l, i) => pi('navline', l.id, ['code', 'flag', 'pen'][i % 3] || 'deal', esc(l.name), l.stages.length + ' stage', i === 1)).join('') + (canConfigPipeline() ? pi('newline', '', 'plus', 'Lini bisnis baru', 'Tambah pipeline sendiri') : '') + '</div>';
  const plusDrop = '<div class="pop r" role="menu" style="min-width:280px">' + (canCreateAny() ? pi('newrec', '', 'user', 'Data baru', 'Lead baru, PIC otomatis akunmu') : '') + (can('deal', 'C') ? pi('newdeal', '', 'deal', 'Deal baru', 'Masuk ke pipeline') : '') + (can('interaksi', 'C') ? pi('newint', '', 'chat', 'Catat interaksi', 'Call, meeting, atau chat', true) : '') + (!canCreateAny() && !can('deal', 'C') && !can('interaksi', 'C') ? '<div class="pi"><span class="d">Akun ini cuma bisa melihat.</span></div>' : '') + '</div>';
  const myN = (Store.db.notifs || []).filter(n => n.userId === u.id && workflowNotificationVisible(n)); const unread = myN.filter(n => !n.read).length;
  const late = fu.filter(i => dayDiff(i.fuDate) < 0).length;
  const notifDrop = '<div class="pop r" role="menu" style="min-width:360px;max-height:520px;overflow-y:auto">' +
    '<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px"><span class="lbl">Ringkasan hari ini</span><span class="small muted">jam 08.00</span></div>' +
    '<div style="margin:0 6px 6px;padding:12px 14px;border-radius:16px;background:var(--tint)" class="small"><b>' + fu.length + ' follow-up</b> jatuh tempo' + (late ? ', <b style="color:#8F1D21">' + late + ' telat</b>' : '') + '. Nanti di versi server, ringkasan ini juga dikirim lewat email.</div>' +
    '<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px"><span class="lbl">Pemberitahuan</span>' + (unread ? '<button type="button" class="btn-ghost small" data-a="notifall" style="min-height:32px">Tandai semua dibaca</button>' : '') + '</div>' +
    (myN.length ? myN.slice(0, 8).map(n => '<button type="button" class="pi" data-a="notifopen" data-v="' + n.id + '"' + (n.read ? '' : ' style="background:var(--card2)"') + '><span class="tile ' + (n.read ? '' : 'y') + '">' + ic('bell') + '</span><span style="flex:1"><span class="t" style="font-weight:' + (n.read ? 600 : 800) + '">' + esc(n.text) + '</span><span class="d">' + fmtTime(n.at) + '</span></span>' + (n.read ? '' : '<span class="dot" style="background:var(--bad)"></span>') + '</button>').join('') : '<div style="padding:4px 10px 8px" class="small muted">Belum ada pemberitahuan.</div>') +
    '<div class="lbl" style="padding:8px 10px">Follow-up mepet</div>' + (fu.length ? fu.slice(0, 6).map(i => { const rec = recById(i.recordId); return '<button type="button" class="pi" data-a="nav" data-v="mywork">' + av(rec.name, rec.id, 'sm') + '<span style="flex:1"><span class="t">' + esc(rec.name) + '</span><span class="d">' + esc(i.next || i.result) + '</span></span>' + fuChip(i.fuDate) + '</button>'; }).join('') : '<div style="padding:10px" class="small muted">Aman, ga ada yang telat.</div>') + '</div>';
  const ctxDrop = '<div class="pop l" role="menu" style="min-width:320px"><div class="lbl" style="padding:8px 10px">Ganti akun uji</div>' + Store.db.users.filter(x => x.active).map(x => '<button type="button" class="pi" data-a="switch" data-v="' + x.id + '"' + (x.id === u.id ? ' aria-current="true" style="background:var(--tint)"' : '') + '>' + av(x.name, x.id, 'round sm') + '<span><span class="t">' + esc(x.name) + '</span><span class="d">' + esc(x.role) + ' · ' + esc(x.dept) + '</span></span></button>').join('') + '</div>';
  const meDrop = '<div class="pop r" role="menu" style="min-width:290px"><div style="display:flex;gap:12px;align-items:center;padding:10px">' + av(u.name, u.id, 'round') + '<div><strong>' + esc(u.name) + '</strong><div class="small muted">' + esc(u.role) + ' · ' + esc(u.dept) + '</div></div></div>' +
    pi('editprofile', '', 'user', 'Edit Profil', 'Foto dan informasi pribadi kamu') +
    pi('opentrash', '', 'trash', 'Data terhapus', 'Pulihkan data sesuai hak akses') +
    (canViewAdmin() ? pi('nav', 'akses', 'shield', 'Akses & Audit', canAdmin() ? 'Pengguna, hak akses, log' : 'Lihat pengguna dan log') : '') +
    (canAdmin() ? pi('resetdata', '', 'refresh', 'Reset data contoh', 'Balik ke kondisi awal', true) : '') + pi('simexpire', '', 'lock', 'Coba sesi berakhir', 'Simulasi batas waktu sesi (uji)') + pi('themepick', '', 'pen', 'Warna tema', 'Ganti palet warna aplikasi') + pi('logout', '', 'logout', 'Keluar', 'Akhiri sesi di browser ini') + '</div>';
  const roleTag = { 'Super Admin': 'ADMIN', 'Kepala Department': 'HEAD', Koordinator: 'KOOR', Staf: 'STAF', Viewer: 'VIEW' }[u.role] || '';
  return '<header class="nav glass"><div class="nav-in">' +
    '<a class="nav-logo" href="#/cp" aria-label="OPTIMA Media, ke Client & Partner"><img src="assets/logo.png" alt="OPTIMA Media"></a>' +
    '<div style="position:relative"><button type="button" class="ctx" data-a="pop" data-v="ctx" data-pop aria-expanded="' + (pop === 'ctx') + '" aria-label="Konteks kerja dan ganti akun uji"><span class="dot" style="background:var(--ok)"></span><span class="cl">' + esc(u.dept) + '</span>' + ic('chev', 'ic-s') + '</button>' + (pop === 'ctx' ? ctxDrop : '') + '</div>' +
    '<nav class="menu" aria-label="Menu utama">' + item('', 'My Work', 'mywork') + item('', 'Dashboard', 'dashboard') + item('pl', 'Pipeline', 'pipeline', plDrop) + item('cp', 'Client & Partner', 'cp', cpDrop) + '</nav>' +
    '<button type="button" class="sbtn" data-a="pal" aria-label="Cari, shortcut Command K">' + ic('search') + '<span class="sl" style="flex:1;text-align:left">Cari…</span><span class="kbd">⌘K</span></button>' +
    '<div style="position:relative"><button type="button" class="icb plus" data-a="pop" data-v="plus" data-pop aria-expanded="' + (pop === 'plus') + '" aria-label="Buat baru">' + ic('plus') + '</button>' + (pop === 'plus' ? plusDrop : '') + '</div>' +
    '<div style="position:relative"><button type="button" class="icb" data-a="pop" data-v="notif" data-pop aria-expanded="' + (pop === 'notif') + '" aria-label="Notifikasi, ' + unread + ' belum dibaca, ' + fu.length + ' follow-up mepet">' + ic('bell') + (unread ? '<span class="badge-dot" style="min-width:18px;height:18px;top:4px;right:4px;font-size:10px;font-weight:800;color:#fff;display:flex;align-items:center;justify-content:center;padding:0 4px">' + unread + '</span>' : fu.length ? '<span class="badge-dot"></span>' : '') + '</button>' + (pop === 'notif' ? notifDrop : '') + '</div>' +
    '<div style="position:relative"><button type="button" class="me" data-a="pop" data-v="me" data-pop aria-expanded="' + (pop === 'me') + '" aria-label="Profil ' + esc(u.name) + ', ' + esc(u.role) + '">' + avatarContent(u.name, u.id) + '<span class="rb">' + roleTag + '</span></button>' + (pop === 'me' ? meDrop : '') + '</div>' +
    '</div></header>';
}

/* ---------- overlays ---------- */
function overlays() {
  let s = '';
  if (App.drawer && me()) s += Drawer();
  if (App.modal && me()) s += '<div class="scrim" data-a="scrim"' + (App.modal.still ? ' style="animation:none"' : '') + '>' + (Modals[App.modal.kind] ? Modals[App.modal.kind](App.modal) : '') + '</div>';
  if (App.pal && me()) s += Palette();
  return s;
}
function modalShell(title, sub, body, foot, wide, formName) {
  const tag = formName ? 'form' : 'div';
  return '<' + tag + ' class="modal ' + (wide ? 'wide' : '') + '"' + (App.modal && App.modal.still ? ' style="animation:none"' : '') + ' role="dialog" aria-modal="true" aria-label="' + esc(title) + '"' + (formName ? ' data-form="' + formName + '" novalidate' : '') + '>' +
    '<div class="modal-h"><div style="display:flex;flex-direction:column;gap:4px">' + (sub ? '<span class="lbl">' + sub + '</span>' : '') + '<h2 class="disp h2">' + title + '</h2></div><button type="button" class="icb" data-a="close" aria-label="Tutup">' + ic('x') + '</button></div>' +
    body + (foot ? '<div class="modal-f">' + foot + '</div>' : '') + '</' + tag + '>';
}
function Palette() {
  const q = (App.pal.q || '').trim().toLowerCase();
  const pages = [['mywork', 'My Work', 'Antrian follow-up'], ['dashboard', 'Dashboard', 'Angka dan grafik'], ['pipeline', 'Pipeline', 'Board deal'], ['cp', 'Client & Partner', 'Database relasi']].concat(canViewAdmin() ? [['akses', 'Akses & Audit', 'Pengguna dan log']] : []);
  let res = pages.filter(p => !q || p[1].toLowerCase().includes(q)).map(p => ({ a: 'nav', v: p[0], t: p[1], d: 'Menu · ' + p[2], icon: 'home' }));
  if (q) {
    res = res.concat(Store.db.records.filter(r => !r.archived && canSeeRec(r) && (r.name + ' ' + r.contact + ' ' + r.code).toLowerCase().includes(q)).slice(0, 6).map(r => ({ a: 'openrec', v: r.id, t: r.name, d: r.cat + ' · ' + r.city, av: r })));
    res = res.concat(can('deal', 'R') ? Store.db.deals.filter(d => canSeeDeal(d) && d.title.toLowerCase().includes(q)).slice(0, 5).map(d => ({ a: 'opendeal', v: d.id, t: d.title, d: 'Deal · ' + (lineById(d.lineId) || {}).name + ' · ' + d.status, icon: 'deal' })) : []);
  }
  App.pal.res = res; const sel = Math.min(App.pal.i || 0, Math.max(0, res.length - 1));
  return '<div class="scrim" data-a="scrim" style="padding-top:110px"><div class="pal" role="dialog" aria-modal="true" aria-label="Pencarian"><label class="pal-in">' + ic('search') + '<span class="sr">Cari data, deal, atau menu</span><input id="pal-q" data-in="pal" value="' + esc(App.pal.q || '') + '" placeholder="Cari client, deal, atau menu" autocomplete="off"><span class="kbd">Esc</span></label>' +
    '<div role="listbox" style="display:flex;flex-direction:column;gap:2px;padding-top:8px;max-height:420px;overflow-y:auto">' + (res.length ? res.map((x, i) => '<button type="button" class="pi" role="option" aria-selected="' + (i === sel) + '" data-a="' + x.a + '" data-v="' + x.v + '">' + (x.av ? av(x.av.name, x.av.id) : '<span class="tile">' + ic(x.icon) + '</span>') + '<span><span class="t">' + esc(x.t) + '</span><span class="d">' + esc(x.d) + '</span></span></button>').join('') : '<div style="padding:18px" class="muted">Ga ketemu. Coba kata lain.</div>') + '</div></div></div>';
}
