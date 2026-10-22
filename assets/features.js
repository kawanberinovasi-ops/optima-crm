/* OPTIMA CRM · fitur tambahan sesuai SRS v0.2: arsip, batal interaksi, aturan deal, kontak, PIC,
   data ganda, gabung data, tampilan tersimpan, riwayat, batal import, daftar pipeline, dashboard CRM,
   pengecualian akses, daftar pilihan, batas sesi, halaman galat, notifikasi */
'use strict';

/* ---------- helper umum ---------- */
const syncPrimary = (r) => { const c = (r.contacts && r.contacts[0]) || {}; r.contact = c.name || ''; r.title = c.title || ''; r.cEmail = c.email || ''; r.phone = c.phone || ''; };
const ensureRec = (r) => { if (!r.contacts) { r.contacts = [{ id: uid('c'), name: r.contact || '', title: r.title || '', email: r.cEmail || '', phone: r.phone || '' }]; } if (r.archived === undefined) r.archived = false; return r; };
const userName = (id) => (userById(id) || {}).name || '-';
const canCancelInt = (i) => !i.cancelled && (i.by === me().id || perm('interaksi') === 'M');
const KEY_LABEL = Object.assign({}, ...ENTS.map(([k, l]) => ({ [k]: l })), { nilai: 'Nilai deal', rate: 'Rate card' });
const pageNote = (t) => '<p class="small" style="color:var(--muted2);margin:0">' + t + '</p>';
const normName = (s) => String(s || '').toLowerCase().replace(/\b(pt|cv|tbk|ud|yayasan)\b\.?/g, '').replace(/[^a-z0-9]/g, '');
const bigrams = (s) => { const a = []; for (let i = 0; i < s.length - 1; i++) a.push(s.slice(i, i + 2)); return a; };
const dice = (a, b) => { if (!a || !b) return 0; if (a === b) return 1; const x = bigrams(a), y = bigrams(b); let m = 0; const yy = y.slice(); x.forEach(g => { const k = yy.indexOf(g); if (k >= 0) { m++; yy.splice(k, 1); } }); return (2 * m) / (x.length + y.length || 1); };
const digits8 = (s) => String(s || '').replace(/\D/g, '').slice(-8);
function findDupes(o, selfId) {
  const n = normName(o.name), e = String(o.email || '').toLowerCase(), p = digits8(o.phone);
  return Store.db.records.filter(r => r.id !== selfId && !r.mergedInto).map(r => {
    const why = []; const rn = normName(r.name);
    if (n && rn && (rn === n || dice(n, rn) >= 0.8 || (n.length > 5 && rn.length > 5 && (rn.includes(n) || n.includes(rn))))) why.push('nama mirip');
    if (e && (r.contacts || []).some(c => String(c.email || '').toLowerCase() === e)) why.push('email sama');
    if (p.length >= 7 && (r.contacts || []).some(c => digits8(c.phone) === p)) why.push('telepon sama');
    return why.length ? { r, why } : null;
  }).filter(Boolean);
}
function skeleton() {
  const b = (w, h, r) => '<div class="skel" style="width:' + w + ';height:' + h + 'px;border-radius:' + (r || 14) + 'px"></div>';
  return '<div role="status" aria-label="Sedang memuat" style="display:flex;flex-direction:column;gap:20px"><div class="skel" style="height:240px;border-radius:32px"></div>' +
    '<div class="grid-kpi">' + [1, 2, 3, 4].map(() => '<div class="card pad" style="display:flex;flex-direction:column;gap:14px">' + b('40%', 12) + b('60%', 44) + b('100%', 8) + '</div>').join('') + '</div>' +
    '<div class="card pad" style="display:flex;flex-direction:column;gap:12px">' + [1, 2, 3, 4].map(() => b('100%', 56, 18)).join('') + '</div><span class="sr">Sedang memuat</span></div>';
}
Pages.notfound = () => '<section class="hero enter">' + stripesArt(2) + '<span class="lbl">Galat 404</span><h1 class="disp hero-title">Halaman<br>ga ketemu.</h1><p style="margin:12px 0 0;font-size:16px">Alamatnya mungkin salah ketik, atau halamannya udah dipindah.</p></section>' +
  empty('Yuk balik ke jalur.', 'Semua kerjaan harian ada di My Work.', '<a class="btn btn-sm" href="#/mywork">Ke My Work</a>');
Pages.expired = () => { const x = App.expired || {}; return '<div class="login"><section class="hero enter">' + stripesArt(3) + '<img src="assets/logo-white.png" alt="OPTIMA Media" style="height:44px;align-self:flex-start">' +
  '<div style="display:flex;flex-direction:column;gap:16px"><span class="lbl">Sesi berakhir</span><h1 class="disp" style="margin:0;font-size:clamp(48px,6vw,92px)">Udah lama<br>ditinggal nih.</h1><p style="margin:0;font-size:18px;max-width:460px">Demi keamanan, sesi otomatis berakhir setelah 60 menit ga ada aktivitas atau 12 jam sejak login.</p></div><span></span></section>' +
  '<section class="login-side"><div class="card login-card enter s2"><div style="display:flex;flex-direction:column;gap:6px"><span class="lbl">Keamanan</span><h2 class="disp" style="margin:0;font-size:40px;color:var(--p)">Masuk lagi, yuk.</h2><p style="margin:0;color:var(--ink2)">' + esc(x.reason || 'Sesi berakhir.') + (x.name ? ' Terakhir login sebagai ' + esc(x.name) + '.' : '') + '</p></div>' +
  '<button type="button" class="btn" data-a="relogin" style="min-height:56px;width:100%">' + ic('lock') + 'Masuk lagi</button><p class="small muted" style="margin:0">Data yang belum disimpan di form terakhir perlu diisi ulang.</p></div></section></div>'; };
function errorPage(e) {
  return '<main class="wrap"><section class="hero">' + stripesArt(2) + '<span class="lbl">Galat</span><h1 class="disp hero-title">Waduh, ada<br>yang galat.</h1><p style="margin:12px 0 0">Halaman ini gagal ditampilkan. Data kamu aman, coba muat ulang.</p></section>' +
    empty('Coba muat ulang halaman.', 'Kalau masih muncul, kirim pesan galat di bawah ke tim Campus Innovate.', '<button type="button" class="btn btn-sm" onclick="location.hash=\'#/mywork\';location.reload()">Muat ulang</button>') +
    '<pre class="small" style="white-space:pre-wrap;padding:14px;border-radius:16px;background:var(--bg);color:var(--ink2)">' + esc(String(e && e.message || e)) + '</pre></main>';
}

/* ---------- Client & Partner ---------- */
cpFiltered = function () {
  const ui = App.ui.cp; const q = ui.q.trim().toLowerCase();
  const visible = Store.db.records.filter(canSeeRec);
  const base = visible.filter(r => ui.arch ? r.archived : !r.archived);
  let list = base;
  if (ui.cat !== 'all') list = list.filter(r => r.cat === ui.cat);
  if (ui.dept !== 'all') list = list.filter(r => r.dept === ui.dept);
  if (q) list = list.filter(r => (r.name + ' ' + (r.contacts || []).map(c => c.name + ' ' + c.email).join(' ') + ' ' + r.code + ' ' + r.city + ' ' + userName(r.picId)).toLowerCase().includes(q));
  const ints = liveInts();
  const last = (r) => ints.filter(i => i.recordId === r.id).sort((a, b) => b.date.localeCompare(a.date))[0];
  list = list.map(r => ({ r, last: last(r), fu: ints.filter(i => i.recordId === r.id && !i.done && i.fuDate).sort((a, b) => a.fuDate.localeCompare(b.fuDate))[0] }));
  const s = ui.sort;
  list.sort((a, b) => s === 'name' ? a.r.name.localeCompare(b.r.name) : s === 'new' ? b.r.createdAt.localeCompare(a.r.createdAt) : ((b.last || {}).date || '').localeCompare((a.last || {}).date || ''));
  return { list, base, archivedCount: visible.filter(r => r.archived).length, activeCount: visible.filter(r => !r.archived).length };
};
const _cpPage = Pages.cp;
Pages.cp = function () {
  const ui = App.ui.cp; const res = cpFiltered();
  let html = _cpPage();
  // tampilan tersimpan + arsip
  const views = (Store.db.views || []).filter(v => v.userId === me().id);
  const bar = '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">' + seg([['0', 'Aktif', res.activeCount], ['1', 'Arsip', res.archivedCount]], ui.arch ? '1' : '0', 'cparch', 'Status data') +
    '<span class="lbl" style="margin-left:6px">Tampilan tersimpan</span>' + (views.length ? views.map(v => '<span class="chip c-info" style="padding:0 4px 0 12px;min-height:36px"><button type="button" class="btn-ghost small" style="min-height:34px;padding:0 4px" data-a="viewapply" data-v="' + v.id + '">' + esc(v.name) + '</button><button type="button" class="btn-ghost" style="min-height:34px;padding:0 6px" data-a="viewdel" data-v="' + v.id + '" aria-label="Hapus tampilan ' + esc(v.name) + '">' + ic('x', 'ic-s') + '</button></span>').join('') : '<span class="small muted">Belum ada.</span>') +
    '<button type="button" class="btn btn-s btn-sm" data-a="viewsave">' + ic('plus', 'ic-s') + 'Simpan filter ini</button></div>';
  html = html.replace('<section class="card pad enter s2" aria-label="Tabel client dan partner" style="display:flex;flex-direction:column;gap:16px">', '<section class="card pad enter s2" aria-label="Tabel client dan partner" style="display:flex;flex-direction:column;gap:16px">' + bar);
  // import terakhir
  const imp = (Store.db.imports || []).find(b => !b.undone && (b.by === me().id || canAdmin()));
  if (imp && canCreateAny()) {
    const n = imp.created.r.length + imp.created.i.length + imp.created.d.length + imp.updated.length;
    html = html.replace('<div style="display:flex;flex-wrap:wrap;gap:8px"><a class="btn-ghost" href="assets/Template_Data_Klien_CRM_OPTIMA.xlsx"', '<div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:18px;background:var(--bg)"><span class="tile">' + ic('up') + '</span><span style="flex:1;min-width:0"><span class="small" style="font-weight:800;display:block">Import terakhir: ' + esc(imp.file) + '</span><span class="small muted">' + fmtTime(imp.at) + ' · ' + n + ' baris · ' + esc(userName(imp.by)) + '</span></span><button type="button" class="btn btn-s btn-sm" data-a="undoimport" data-v="' + imp.id + '">Batalkan</button></div><div style="display:flex;flex-wrap:wrap;gap:8px"><a class="btn-ghost" href="assets/Template_Data_Klien_CRM_OPTIMA.xlsx"');
  }
  if (ui.arch) html = html.replace('Belum ada data.<br>Yuk isi duluan.', 'Arsip masih<br>kosong.').replace('Import dari template Excel biar cepat, atau tambah satu per satu.', 'Data yang diarsipkan muncul di sini dan bisa dipulihkan.').replace('Ga ketemu.', 'Arsip kosong.').replace('Coba kata lain atau reset filter.', 'Data yang diarsipkan muncul di sini dan bisa dipulihkan.');
  return html;
};

/* ---------- Drawer detail ---------- */
function Drawer() {
  const r = recById(App.drawer.id); if (!r || !canSeeRec(r)) { App.drawer = null; return ''; }
  ensureRec(r);
  const ints = Store.db.interactions.filter(i => i.recordId === r.id).sort((a, b) => b.date.localeCompare(a.date));
  const deals = can('deal', 'R') ? Store.db.deals.filter(d => d.recordId === r.id) : [];
  const info = [['Kota', r.city], ['Segmen', r.segment], ['Sumber', r.source], ['PIC', userName(r.picId)], ['Department pemilik', r.dept], ['Dibuat', fmtDate(r.createdAt)]];
  if (r.cat === 'KOL/KOC' && canSeeRate()) info.push(['Rate card', r.rate ? fmtRp(r.rate) : '-']);
  const ed = canEditRec(r) && !r.archived;
  const hb = (a, icon, label, extra) => '<button type="button" class="btn btn-sm" style="background:rgba(255,255,255,.14)" data-a="' + a + '" data-v="' + r.id + '"' + (extra || '') + '>' + ic(icon, 'ic-s') + label + '</button>';
  const more = [];
  if (ed && r.cat === 'Lead' && can('client', 'C')) more.push(['convert', 'check', 'Jadikan Client']);
  if (canMovePic(r) && !r.archived) more.push(['movepic', 'swap', 'Pindah PIC']);
  if (canDelRec(r) && !r.archived) more.push(['mergerec', 'users', 'Gabung data ganda']);
  if (canDelRec(r)) more.push(r.archived ? ['restorerec', 'refresh', 'Pulihkan dari arsip'] : ['delrec', 'file', 'Arsipkan']);
  const ch = (Store.db.changes || []).filter(c => c.kind === 'record' && c.refId === r.id);
  const icn = (t) => t === 'Telepon' ? 'phone' : t === 'Email' ? 'mail' : t === 'Kunjungan' ? 'pin' : t === 'Meeting' ? 'users' : 'chat';
  return '<div class="drawer-scrim" data-a="closedrawer"></div><aside class="drawer" role="dialog" aria-modal="true" aria-label="Detail ' + esc(r.name) + '">' +
    '<div class="hero" style="border-radius:0;padding:26px 26px 30px;display:flex;flex-direction:column;gap:14px">' + stripesArt(2) +
    '<div style="display:flex;justify-content:space-between;gap:10px"><span class="lbl">' + esc(r.code) + ' · ' + esc(r.cat) + '</span><button type="button" class="icb" data-a="closedrawer" aria-label="Tutup" style="color:#fff;border-color:rgba(255,255,255,.25)">' + ic('x') + '</button></div>' +
    '<h2 class="disp" style="margin:0;font-size:40px">' + esc(r.name) + '</h2>' +
    (r.archived ? '<div class="small" style="padding:10px 14px;border-radius:14px;background:rgba(255,255,255,.14)"><b>Diarsipkan</b> ' + fmtDate(r.archivedAt) + ' oleh ' + esc(userName(r.archivedBy)) + '. Alasan: ' + esc(r.archiveReason || '-') + '</div>' : '') +
    '<div style="display:flex;flex-wrap:wrap;gap:8px">' + (!r.archived && can('interaksi', 'C') ? '<button type="button" class="btn btn-w btn-sm" data-a="newint" data-v="' + r.id + '">' + ic('chat', 'ic-s') + 'Catat interaksi</button>' : '') + (!r.archived && can('deal', 'C') ? hb('newdeal', 'deal', 'Deal baru') : '') + (ed ? hb('editrec', 'edit', 'Edit') : '') + '</div></div>' +
    '<div style="padding:24px;display:flex;flex-direction:column;gap:22px">' +
    (more.length ? '<div style="display:flex;flex-wrap:wrap;gap:8px">' + more.map(([a, icon, l]) => '<button type="button" class="btn btn-s btn-sm" data-a="' + a + '" data-v="' + r.id + '">' + ic(icon, 'ic-s') + l + '</button>').join('') + '</div>' : '') +
    '<section style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' + info.map(([k, v]) => '<div style="display:flex;flex-direction:column;gap:2px;padding:12px;border-radius:16px;background:var(--bg)"><span class="lbl" style="font-size:11px">' + k + '</span><span style="font-weight:600;word-break:break-word">' + esc(v || '-') + '</span></div>').join('') + '</section>' +
    (r.note ? '<p style="margin:0;padding:14px;border-radius:16px;background:var(--ysoft)" class="small">' + esc(r.note) + '</p>' : '') +
    '<section style="display:flex;flex-direction:column;gap:10px"><div style="display:flex;justify-content:space-between;align-items:center"><span class="lbl">Kontak (' + r.contacts.length + ')</span>' + (ed ? '<button type="button" class="btn-ghost small" data-a="addcontact" data-v="' + r.id + '">' + ic('plus', 'ic-s') + ' Kontak</button>' : '') + '</div>' +
    r.contacts.map((c, i) => '<div class="tl-i" style="align-items:center">' + av(c.name || '?', c.id, 'round sm') + '<div style="flex:1;min-width:0;display:flex;flex-direction:column"><strong class="small">' + esc(c.name || '-') + (i === 0 ? ' <span class="chip c-info" style="padding:2px 8px">Utama</span>' : '') + '</strong><span class="small muted">' + esc([c.title, c.email, c.phone].filter(Boolean).join(' · ') || '-') + '</span></div>' +
      (ed ? '<span style="display:flex;gap:2px">' + (i > 0 ? '<button type="button" class="btn-ghost small" data-a="cprimary" data-v="' + r.id + '" data-c="' + c.id + '">Jadikan utama</button>' : '') + '<button type="button" class="icb" style="width:36px;height:36px" data-a="editcontact" data-v="' + r.id + '" data-c="' + c.id + '" aria-label="Edit kontak ' + esc(c.name) + '">' + ic('edit', 'ic-s') + '</button>' + (r.contacts.length > 1 ? '<button type="button" class="icb" style="width:36px;height:36px" data-a="cremove" data-v="' + r.id + '" data-c="' + c.id + '" aria-label="Lepas kontak ' + esc(c.name) + '">' + ic('x', 'ic-s') + '</button>' : '') + '</span>' : '') + '</div>').join('') + '</section>' +
    '<section style="display:flex;flex-direction:column;gap:10px"><span class="lbl">Deal (' + deals.length + ')</span>' + (deals.length ? deals.map(d => '<button type="button" class="pi" style="background:var(--bg)" data-a="opendeal" data-v="' + d.id + '"><span class="tile">' + ic('deal') + '</span><span style="flex:1"><span class="t">' + esc(d.title) + '</span><span class="d">' + esc((lineById(d.lineId) || {}).name) + ' · ' + esc((stageOf(d) || {}).name || '') + '</span></span><span style="display:flex;flex-direction:column;align-items:flex-end;gap:4px"><strong class="num small">' + valOrLock(d, d.status === 'Won' ? d.value : d.offer) + '</strong>' + statusChip(d.status) + '</span></button>').join('') : '<span class="small muted">' + (can('deal', 'R') ? 'Belum ada deal.' : 'Akun ini ga punya akses deal.') + '</span>') + '</section>' +
    '<section style="display:flex;flex-direction:column;gap:10px"><span class="lbl">Riwayat interaksi (' + ints.filter(i => !i.cancelled).length + ')</span><div class="tl">' + (ints.length ? ints.map(i => '<div class="tl-i' + (i.cancelled ? ' cancelled' : '') + '"><span class="tile">' + ic(icn(i.type)) + '</span><div style="flex:1;display:flex;flex-direction:column;gap:4px;min-width:0"><div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><strong class="small">' + esc(i.type) + ' · ' + fmtDate(i.date) + '</strong><span class="small muted">' + esc(userName(i.by)) + '</span></div><span class="small struck-t">' + esc(i.result) + '</span>' +
      (i.cancelled ? '<span class="chip c-warn" style="align-self:flex-start"><span class="dot"></span>Dibatalkan ' + esc(userName(i.cancelledBy)) + ': ' + esc(i.cancelReason) + '</span>' :
        '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">' + (i.next ? '<span class="small muted">Next: ' + esc(i.next) + '</span>' + (i.done ? '<span class="chip c-ok"><span class="dot"></span>Selesai</span>' : fuChip(i.fuDate) + (can('interaksi', 'C') && i.fuDate ? '<button type="button" class="btn-ghost small" data-a="fudone" data-v="' + i.id + '">Tandai selesai</button>' : '')) : '') + (canCancelInt(i) ? '<button type="button" class="btn-ghost small" data-a="cancelint" data-v="' + i.id + '" style="color:#8F1D21">Batalkan</button>' : '') + '</div>') + '</div></div>').join('') : empty('Belum ada interaksi.', 'Catat obrolan pertama biar tim tau progresnya.', can('interaksi', 'C') && !r.archived ? '<button type="button" class="btn btn-sm" data-a="newint" data-v="' + r.id + '">+ Catat interaksi</button>' : '')) + '</div></section>' +
    '<details class="hist"><summary><span class="lbl">Riwayat perubahan (' + ch.length + ')</span></summary>' + historyList(ch) + '</details></div></aside>';
};
function historyList(ch) {
  if (!ch.length) return '<p class="small muted" style="margin:8px 0 0">Belum ada perubahan sejak data dibuat.</p>';
  return '<div style="display:flex;flex-direction:column;gap:8px;margin-top:10px">' + ch.slice(0, 60).map(c => '<div class="tl-i" style="padding:10px 12px"><div style="flex:1;display:flex;flex-direction:column;gap:2px"><span class="small"><b>' + esc(c.field) + '</b>: ' + (c.before ? '<span class="muted" style="text-decoration:line-through">' + esc(c.before) + '</span> jadi ' : '') + '<b>' + esc(c.after || '(kosong)') + '</b></span><span class="small muted">' + fmtTime(c.at) + ' · ' + esc(userName(c.by)) + (c.note ? ' · ' + esc(c.note) : '') + '</span></div></div>').join('') + '</div>';
}

/* ---------- Pipeline ---------- */
Pages.pipeline = function () {
  const ui = App.ui.pl; ui.view = ui.view || 'board'; ui.st = ui.st || 'active';
  const hero = (extra, n) => '<section class="hero enter" style="display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:24px">' + stripesArt(3) + '<div style="display:flex;flex-direction:column;gap:10px;min-width:0"><span class="lbl">Pipeline · ' + esc(extra) + '</span><h1 class="disp hero-title">Gas, geser<br>deal-nya.</h1><p style="margin:0;font-size:16px">Drag kartu ke stage lain, atau pakai tombol panah di kartu.</p></div>' + (n || '') + '</section>';
  if (!can('deal', 'R')) return hero('Terkunci') + empty('Pipeline terkunci.', 'Akun ini ga punya akses ke deal. Minta Super Admin kalau butuh.', '<a class="btn btn-sm" href="#/mywork">Balik ke My Work</a>');
  const line = lineById(ui.line) || Store.db.lines[0]; ui.line = line.id;
  const range = periodRange(ui.per, ui.val); const q = ui.q.trim().toLowerCase();
  const deals = Store.db.deals.filter(d => d.lineId === line.id && inRange(d.status === 'Open' || d.status === 'On Hold' ? (d.createdAt || d.target) : (d.closedAt || d.target), range) && (!q || (d.title + ' ' + ((recById(d.recordId) || {}).name || '')).toLowerCase().includes(q)));
  const open = deals.filter(d => d.status === 'Open'); const seeV = canSeeValue(null);
  const won = deals.filter(d => d.status === 'Won'); const lost = deals.filter(d => d.status === 'Lost'); const hold = deals.filter(d => d.status === 'On Hold');
  const wonV = won.reduce((a, d) => a + (+d.value || 0), 0);
  const cfg = canConfigPipeline();
  const stats = '<div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:28px"><div style="display:flex;flex-direction:column"><span class="lbl">Deal open</span><span class="disp" style="font-size:46px">' + cnt(open.length) + '</span></div><div style="display:flex;flex-direction:column"><span class="lbl">Nilai open</span><span class="disp" style="font-size:46px">' + (seeV ? cnt(open.reduce((a, d) => a + (+d.offer || 0), 0), 'rp') : 'Rp ···') + '</span></div>' + (wonV && seeV ? '<span class="sticker" style="font-size:20px;align-self:center">Won ' + fmtRp(wonV) + '</span>' : '') + (can('deal', 'C') ? '<button type="button" class="btn btn-w" data-a="newdeal">' + ic('plus', 'ic-s') + 'Deal baru</button>' : '') + '</div>';
  const closingId = line.stages[line.stages.length - 1].id;
  const active = deals.filter(d => d.status !== 'Lost');
  const total = active.reduce((a, d) => a + (+(d.status === 'Won' ? d.value : d.offer) || 0), 0) || 1;
  let body = '';
  if (ui.view === 'board') {
    const cols = line.stages.map((s, si) => {
      const isC = s.id === closingId;
      let ds = active.filter(d => isC ? d.status === 'Won' : (d.stageId === s.id && d.status !== 'Won')).sort((a, b) => (a.status === 'On Hold') - (b.status === 'On Hold') || (a.target || '').localeCompare(b.target || ''));
      const v = ds.reduce((a, d) => a + (+(d.status === 'Won' ? d.value : d.offer) || 0), 0);
      const full = ds.length;
      if (isC) { ds = ds.sort((a, b) => (b.closedAt || '').localeCompare(a.closedAt || '')); if (!ui.allC) ds = ds.slice(0, 4); }
      return '<div class="col enter s' + Math.min(7, si + 1) + '" data-drop-stage="' + s.id + '"><div class="col-h"><div style="display:flex;justify-content:space-between;align-items:center;gap:6px"><strong style="font-size:15px">' + esc(s.name) + (isC ? ' <span class="chip c-ok" style="padding:2px 8px">Won</span>' : '') + '</strong><span style="display:flex;gap:4px;align-items:center"><span class="chip num" style="background:#fff;color:var(--p)">' + full + '</span>' + (cfg ? '<button type="button" class="btn-ghost" style="min-height:32px;padding:0 6px" data-a="editstage" data-v="' + s.id + '" aria-label="Ubah stage ' + esc(s.name) + '">' + ic('edit', 'ic-s') + '</button>' : '') + '</span></div><div style="display:flex;justify-content:space-between" class="small muted num"><span>' + (seeV ? fmtRp(v) : 'Rp ···') + '</span><span>' + Math.round(v / total * 100) + '%</span></div><div class="bar" style="height:6px;background:#fff"><i class="growx" style="width:' + Math.round(v / total * 100) + '%;' + (isC ? 'background:var(--ok)' : '') + '"></i></div></div>' +
        ds.map(d => dealCard(d, line, si)).join('') + (isC && full > 4 ? '<button type="button" class="btn-ghost" data-a="plallc">' + (ui.allC ? 'Tampilkan 4 terbaru' : 'Lihat ' + (full - 4) + ' closing lainnya') + '</button>' : '') +
        (ds.length ? '' : '<div class="empty-col">' + stripesSvg(80) + '<strong style="color:var(--ink)">Belum ada deal di sini</strong><span>' + (isC ? 'Deal yang masuk Closing otomatis berstatus Won.' : 'Geser deal ke stage ini, atau bikin yang baru.') + '</span>' + (can('deal', 'C') && !isC ? '<button type="button" class="btn btn-s btn-sm" data-a="newdeal" data-stage="' + s.id + '">+ Tambah deal</button>' : '') + '</div>') + '</div>';
    }).join('');
    body = '<section class="board" aria-label="Board pipeline ' + esc(line.name) + '">' + cols + (cfg ? '<button type="button" class="add-col" data-a="addstage"><span class="tile" style="width:48px;height:48px">' + ic('plus') + '</span>Tambah stage<span class="small muted" style="font-weight:600">Khusus ' + esc(line.name) + '</span></button>' : '') + '</section>' +
      (lost.length ? '<button type="button" class="btn-ghost" data-a="pllost" style="align-self:flex-start">' + lost.length + ' deal Lost disembunyikan dari papan. Lihat di tampilan Daftar.</button>' : '');
  } else {
    const st = ui.st;
    const list = deals.filter(d => st === 'all' || (st === 'active' ? (d.status === 'Open' || d.status === 'On Hold') : d.status === st)).sort((a, b) => (a.status === 'Open' ? (a.target || '') : (a.closedAt || a.target || '')).localeCompare(b.status === 'Open' ? (b.target || '') : (b.closedAt || b.target || '')));
    const lastI = (d) => liveInts().filter(i => i.dealId === d.id || (!i.dealId && i.recordId === d.recordId)).sort((a, b) => b.date.localeCompare(a.date))[0];
    body = '<section class="card pad enter s2" style="display:flex;flex-direction:column;gap:14px">' + seg([['active', 'Aktif', open.length + hold.length], ['Won', 'Won', won.length], ['Lost', 'Lost', lost.length], ['all', 'Semua', deals.length]], st, 'plst', 'Status deal') +
      (list.length ? '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Deal</th><th>Stage</th><th>Status</th><th>Nilai</th><th>PIC</th><th>Target / closing</th><th>Interaksi terakhir</th></tr></thead><tbody>' +
        list.map(d => { const r = recById(d.recordId); const li = lastI(d); const late = d.status === 'Open' && d.target && d.target < today(); return '<tr class="click" data-a="opendeal" data-v="' + d.id + '" tabindex="0"><td><strong>' + esc(d.title) + '</strong><div class="small" style="color:var(--p2);font-weight:600">' + esc(r ? r.name : '-') + '</div></td><td>' + esc((stageOf(d) || {}).name || '-') + '</td><td>' + statusChip(d.status) + (d.status === 'Lost' && d.lostReason ? '<div class="small muted">' + esc(d.lostReason) + '</div>' : '') + (d.status === 'On Hold' && d.reviewDate ? '<div class="small muted">Tinjau ' + fmtShort(d.reviewDate) + '</div>' : '') + '</td><td class="num" style="font-weight:700">' + valOrLock(d, d.status === 'Won' ? d.value : d.offer) + '</td><td>' + esc(userName(d.ownerId)) + '</td><td class="num">' + (d.status === 'Won' || d.status === 'Lost' ? fmtShort(d.closedAt) : (late ? '<span class="chip c-bad"><span class="dot"></span>' + fmtShort(d.target) + '</span>' : fmtShort(d.target))) + '</td><td class="small">' + (li ? fmtShort(li.date) + ' · ' + esc(li.type) : '<span class="muted">-</span>') + '</td></tr>'; }).join('') + '</tbody></table></div>'
        : empty('Ga ada deal di filter ini.', 'Ganti status atau periode.', '<button type="button" class="btn btn-sm" data-a="plst" data-v="all">Lihat semua</button>')) + '</section>';
  }
  return hero(line.name, stats) +
    '<div class="enter s1" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px"><div style="display:flex;flex-wrap:wrap;gap:10px">' + seg([['board', 'Papan'], ['list', 'Daftar']], ui.view, 'plview', 'Tampilan') + seg(Store.db.lines.map(l => [l.id, l.name, Store.db.deals.filter(d => d.lineId === l.id && d.status === 'Open').length]), line.id, 'plline', 'Lini bisnis', 'tablist') + '</div>' +
    '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">' + seg([['all', 'Semua'], ['m', 'Bulan'], ['q', 'Quarter'], ['y', 'Tahun']], ui.per, 'plper', 'Jenis periode') + (ui.per !== 'all' ? '<label><span class="sr">Pilih periode</span><select id="pl-val" class="inp" data-ch="plval" style="width:auto;min-height:44px;font-weight:700;color:var(--p)">' + periodOptions(ui.per).map(o => opt(o[0], ui.val || periodOptions(ui.per)[0][0], o[1])).join('') + '</select></label>' : '') + '<label class="search" style="width:220px">' + ic('search', 'ic-s') + '<span class="sr">Cari deal</span><input id="pl-q" data-in="plq" value="' + esc(ui.q) + '" placeholder="Cari deal"></label></div></div>' +
    (ui.per !== 'all' ? '<p class="small muted" style="margin:-8px 0 0">Deal yang masih jalan dihitung dari tanggal dibuat, deal Won dan Lost dari tanggal closing.</p>' : '') + body +
    pageNote('Deal yang masuk Closing berstatus Won. Lost dan On Hold diatur dari detail deal, dan stage terakhirnya tetap tersimpan.');
};
function dealCard(d, line, si) {
  const r = recById(d.recordId); const o = userById(d.ownerId); const ed = canEditDeal(d) && d.status === 'Open';
  const late = d.status === 'Open' && d.target && d.target < today();
  const lastI = liveInts().filter(i => i.dealId === d.id || (!i.dealId && i.recordId === d.recordId)).sort((a, b) => b.date.localeCompare(a.date))[0];
  const chip = d.status === 'Won' ? statusChip('Won') + (d.closedAt ? ' <span class="small muted">' + fmtShort(d.closedAt) + '</span>' : '') : d.status === 'On Hold' ? '<span class="chip c-warn"><span class="dot"></span>On Hold' + (d.reviewDate ? ' · tinjau ' + fmtShort(d.reviewDate) : '') + '</span>' : late ? '<span class="chip c-bad ping"><span class="dot"></span>Target ' + fmtShort(d.target) + '</span>' : '<span class="chip c-info"><span class="dot"></span>' + fmtShort(d.target) + '</span>';
  return '<article class="card hov deal' + (d.status === 'On Hold' ? ' dim' : '') + '"' + (ed ? ' draggable="true" data-drag="' + d.id + '"' : '') + ' aria-label="Deal ' + esc(d.title) + '"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px"><button type="button" data-a="opendeal" data-v="' + d.id + '" style="all:unset;cursor:pointer;display:flex;flex-direction:column;gap:2px;min-width:0"><strong style="font-size:15px;line-height:1.3">' + esc(d.title) + '</strong><span class="small" style="color:var(--p2);font-weight:600">' + esc(r ? r.name : '-') + '</span></button>' + av(o ? o.name : '?', d.ownerId, 'round sm') + '</div>' +
    '<span class="disp num" style="font-size:26px;color:var(--p)">' + valOrLock(d, d.status === 'Won' ? d.value : d.offer) + '</span>' +
    (lastI ? '<span class="small" style="display:flex;gap:6px;align-items:flex-start;color:var(--ink2)">' + ic('chat', 'ic-s') + '<span><b>' + fmtShort(lastI.date) + ' · ' + esc(lastI.type) + '</b> ' + esc(lastI.result.length > 60 ? lastI.result.slice(0, 60) + '…' : lastI.result) + '</span></span>' : '') +
    '<div style="display:flex;justify-content:space-between;align-items:center;gap:6px;flex-wrap:wrap"><span>' + chip + '</span>' +
    (ed ? '<span style="display:flex;gap:6px"><button type="button" class="btn btn-s btn-sm btn-ic" data-a="move" data-v="' + d.id + '" data-dir="-1" aria-label="Mundur satu stage"' + (si === 0 ? ' disabled' : '') + '>' + ic('left', 'ic-s') + '</button><button type="button" class="btn btn-s btn-sm btn-ic" data-a="move" data-v="' + d.id + '" data-dir="1" aria-label="Maju ke ' + esc((line.stages[si + 1] || {}).name || '') + '"' + (si >= line.stages.length - 1 ? ' disabled' : '') + '>' + ic('right', 'ic-s') + '</button></span>' : (d.status !== 'Open' && canReopen(d) ? '<button type="button" class="btn-ghost small" data-a="reopen" data-v="' + d.id + '">Buka lagi</button>' : '')) + '</div></article>';
};
moveDeal = function (id, toStage) {
  const d = findDeal(id); if (!d || !canEditDeal(d) || d.stageId === toStage) return;
  const line = lineById(d.lineId); if (!line.stages.some(s => s.id === toStage)) return;
  if (d.status !== 'Open') {
    if (!canReopen(d)) { toast('Deal ' + d.status + ' terkunci', d.status === 'Won' ? 'Cuma Super Admin atau Kepala Department yang bisa buka lagi.' : 'Cuma PIC atau atasannya yang bisa buka lagi.', 'bad'); return; }
    App.modal = { kind: 'dstatus', dealId: id, st: 'reopen', toStage: isClosingStage(line, toStage) ? '' : toStage }; render(); return;
  }
  if (isClosingStage(line, toStage)) { App.modal = { kind: 'closing', dealId: id }; render(); return; }
  const from = stageOf(d); d.stageId = toStage; dealLog(d, from ? from.name : '', stageOf(d).name, 'Open', '');
  audit('Ubah', 'Deal', d.title + ' pindah dari ' + (from ? from.name : '-') + ' ke ' + stageOf(d).name);
  commit();
};

/* ---------- My Work: deal On Hold yang perlu ditinjau ---------- */
const _mywork = Pages.mywork;
Pages.mywork = function () {
  let html = _mywork();
  if (!can('deal', 'R')) return html;
  const due = Store.db.deals.filter(d => d.status === 'On Hold' && d.ownerId === me().id && d.reviewDate && dayDiff(d.reviewDate) <= 3).sort((a, b) => a.reviewDate.localeCompare(b.reviewDate));
  if (!due.length) return html;
  const box = '<section class="card pad enter s4" aria-label="Deal On Hold" style="display:flex;flex-direction:column;gap:12px"><div class="sec-h"><div><span class="lbl">Tinjau ulang</span><h2 class="disp h2" style="font-size:30px">Deal On Hold kamu</h2></div><a href="#/pipeline" style="font-size:14px;font-weight:700">Pipeline</a></div>' +
    due.map(d => '<div class="lrow"><button type="button" data-a="opendeal" data-v="' + d.id + '" style="all:unset;cursor:pointer;flex:1 1 260px;min-width:0;display:flex;flex-direction:column"><strong>' + esc(d.title) + '</strong><span class="small" style="color:var(--ink2)">' + esc((recById(d.recordId) || {}).name || '') + ' · ' + esc(d.reason || '') + '</span></button>' + fuChip(d.reviewDate) + (canReopen(d) ? '<button type="button" class="btn btn-sm" data-a="reopen" data-v="' + d.id + '">Buka lagi</button>' : '') + '</div>').join('') + '</section>';
  return html.replace('<div class="row-f"><section id="fu"', box + '<div class="row-f"><section id="fu"');
};

/* ---------- Dashboard: tab Pipeline dan CRM ---------- */
const _dash = Pages.dashboard;
Pages.dashboard = function () {
  const ui = App.ui.db; ui.tab = ui.tab || 'pipe';
  const html = _dash();
  const cut = html.indexOf('</section>') + 10;
  const tabs = '<div class="enter s1">' + seg([['pipe', 'Dashboard pipeline'], ['crm', 'Dashboard CRM']], ui.tab, 'dbtab', 'Jenis dashboard', 'tablist') + '</div>';
  const head = html.slice(0, cut);
  if (ui.tab === 'crm') return head + tabs + dashCRM();
  let rest = html.slice(cut);
  if (can('deal', 'R')) {
    const r = periodRange(ui.per, ui.val || periodOptions(ui.per)[0][0]);
    const lost = Store.db.deals.filter(d => d.status === 'Lost' && inRange(d.closedAt, r));
    const reasons = {}; lost.forEach(d => { const k = d.lostReason || 'Lainnya'; reasons[k] = (reasons[k] || 0) + 1; });
    const rs = Object.entries(reasons).sort((a, b) => b[1] - a[1]); const rmax = Math.max(1, ...rs.map(x => x[1]));
    const sc = DEAL_STATUS.map(s => [s, Store.db.deals.filter(d => d.status === s && (s === 'Open' || s === 'On Hold' || inRange(d.closedAt, r))).length]);
    const extra = '<div class="row-f" style="align-items:stretch"><section class="card pad enter s6" style="flex:1 1 420px;min-width:0;display:flex;flex-direction:column;gap:12px"><div><span class="lbl">Status deal</span><h2 class="disp h2" style="font-size:30px">Open, Won, Lost, On Hold</h2></div><div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px">' + sc.map(([s, n]) => '<div style="padding:14px;border-radius:18px;background:var(--bg);display:flex;justify-content:space-between;align-items:center">' + statusChip(s) + '<span class="disp num" style="font-size:32px;color:var(--p)">' + n + '</span></div>').join('') + '</div><span class="small muted">Won dan Lost dihitung di periode terpilih.</span></section>' +
      '<section class="card pad enter s7" style="flex:1 1 420px;min-width:0;display:flex;flex-direction:column;gap:12px"><div><span class="lbl">Alasan gagal · ' + esc(perLabel(ui.per, ui.val)) + '</span><h2 class="disp h2" style="font-size:30px">Kenapa deal lepas</h2></div>' +
      (rs.length ? rs.map(([k, n]) => '<div style="display:grid;grid-template-columns:170px 1fr 30px;gap:10px;align-items:center"><span class="small" style="font-weight:600">' + esc(k) + '</span><div class="bar" style="height:14px"><i class="growx" style="width:' + (n / rmax * 100) + '%;background:linear-gradient(90deg,#C9343A,#E5484D)"></i></div><span class="num" style="font-weight:800;text-align:right">' + n + '</span></div>').join('') : '<p class="small muted" style="margin:0">Ga ada deal Lost di periode ini.</p>') + '</section></div>';
    rest = rest.replace('<p class="small" style="color:var(--muted2);margin:0">Semua nama', extra + '<p class="small" style="color:var(--muted2);margin:0">Semua nama');
  }
  return head + tabs + rest;
};
function dashCRM() {
  const ui = App.ui.db; const r = periodRange(ui.per, ui.val || periodOptions(ui.per)[0][0]);
  const recs = Store.db.records.filter(x => !x.archived && canSeeRec(x));
  const ints = liveInts().filter(i => recById(i.recordId) && canSeeRec(recById(i.recordId)));
  const fuOpen = ints.filter(i => !i.done && i.fuDate);
  const late = fuOpen.filter(i => i.fuDate < today()); const due = fuOpen.filter(i => i.fuDate === today());
  const intP = ints.filter(i => inRange(i.date, r));
  const bars = (rows, max, color) => rows.map(([k, n]) => '<div style="display:grid;grid-template-columns:150px 1fr 34px;gap:10px;align-items:center"><span class="small" style="font-weight:600">' + esc(k) + '</span><div class="bar" style="height:14px"><i class="growx" style="width:' + (n / max * 100) + '%;' + (color || '') + '"></i></div><span class="num" style="font-weight:800;text-align:right">' + n + '</span></div>').join('');
  const byCat = CATS.filter(c => can(catEnt(c), 'R')).map(c => [c, recs.filter(x => x.cat === c).length]);
  const segs = {}; recs.forEach(x => { const k = x.segment || 'Tanpa segmen'; segs[k] = (segs[k] || 0) + 1; });
  const bySeg = Object.entries(segs).sort((a, b) => b[1] - a[1]).slice(0, 8);
  const pics = Store.db.users.filter(u => recs.some(x => x.picId === u.id)).map(u => ({ u, n: recs.filter(x => x.picId === u.id).length, fu: fuOpen.filter(i => (recById(i.recordId) || {}).picId === u.id).length, lt: late.filter(i => (recById(i.recordId) || {}).picId === u.id).length, ia: intP.filter(i => i.by === u.id).length })).sort((a, b) => b.n - a.n);
  const recent = ints.slice().sort((a, b) => b.date.localeCompare(a.date)).slice(0, 7);
  return '<section class="grid-kpi" aria-label="Ringkasan CRM">' + dk('Data aktif', cnt(recs.length), 'total', 'c-info', byCat.filter(x => x[1]).length + ' kategori', 's1') + dk('Follow-up telat', cnt(late.length), 'telat', 'c-bad', 'Perlu dikejar sekarang', 's2') + dk('Jatuh tempo hari ini', cnt(due.length), 'hari ini', 'c-warn', fuOpen.length + ' follow-up masih terbuka', 's3') + dk('Interaksi', cnt(intP.length), esc(perLabel(ui.per, ui.val)), 'c-info', 'Di periode terpilih', 's4') + '</section>' +
    '<div class="row-f" style="align-items:stretch"><section class="card pad enter s4" style="flex:1 1 380px;min-width:0;display:flex;flex-direction:column;gap:12px"><div><span class="lbl">Client & Partner</span><h2 class="disp h2" style="font-size:30px">Per kategori</h2></div>' + bars(byCat, Math.max(1, ...byCat.map(x => x[1]))) + '</section>' +
    '<section class="card pad enter s5" style="flex:1 1 380px;min-width:0;display:flex;flex-direction:column;gap:12px"><div><span class="lbl">Client & Partner</span><h2 class="disp h2" style="font-size:30px">Per segmen</h2></div>' + bars(bySeg, Math.max(1, ...bySeg.map(x => x[1])), 'background:linear-gradient(90deg,var(--y),var(--y2))') + '</section></div>' +
    '<div class="row-f" style="align-items:stretch"><section class="card pad enter s6" style="flex:1.4 1 520px;min-width:0;display:flex;flex-direction:column;gap:12px"><div><span class="lbl">Per PIC</span><h2 class="disp h2" style="font-size:30px">Siapa pegang berapa</h2></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>PIC</th><th>Data</th><th>Follow-up terbuka</th><th>Telat</th><th>Interaksi periode ini</th></tr></thead><tbody>' +
    pics.map(p => '<tr><td><div style="display:flex;gap:10px;align-items:center">' + av(p.u.name, p.u.id, 'round sm') + '<span><strong>' + esc(p.u.name) + '</strong><div class="small muted">' + esc(p.u.dept) + '</div></span></div></td><td class="num">' + p.n + '</td><td class="num">' + p.fu + '</td><td>' + (p.lt ? '<span class="chip c-bad"><span class="dot"></span>' + p.lt + '</span>' : '<span class="muted">0</span>') + '</td><td class="num">' + p.ia + '</td></tr>').join('') + '</tbody></table></div></section>' +
    '<section class="card pad enter s7" style="flex:1 1 380px;min-width:0;display:flex;flex-direction:column;gap:10px"><div><span class="lbl">Terbaru</span><h2 class="disp h2" style="font-size:30px">Interaksi terakhir</h2></div>' +
    (recent.length ? recent.map(i => { const x = recById(i.recordId); return '<button type="button" class="pi" style="background:var(--bg)" data-a="openrec" data-v="' + x.id + '">' + av(x.name, x.id, 'sm') + '<span style="flex:1;min-width:0"><span class="t">' + esc(x.name) + '</span><span class="d">' + fmtShort(i.date) + ' · ' + esc(i.type) + ' · ' + esc(userName(i.by)) + '</span></span></button>'; }).join('') : '<p class="small muted" style="margin:0">Belum ada interaksi.</p>') + '</section></div>' +
    pageNote('Angka di sini dihitung dari data yang boleh kamu lihat, jadi bisa beda antar akun.');
}

/* ---------- Akses & Audit: tab tambahan ---------- */
const AdminTabs = {
  override: (adm) => {
    const list = Store.db.overrides || [];
    return '<div class="sec-h"><div><span class="lbl">Pengecualian hak akses</span><h2 class="disp h2">Khusus orang tertentu</h2></div>' + (adm ? '<button type="button" class="btn btn-sm" data-a="newoverride">' + ic('plus', 'ic-s') + 'Pengecualian</button>' : '') + '</div>' +
      '<p class="small muted" style="margin:0">Pengecualian menimpa aturan department buat satu orang saja. Contoh: staf Commercial tertentu boleh lihat nilai semua deal.</p>' +
      (list.length ? '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Pengguna</th><th>Bagian</th><th>Akses</th><th>Alasan</th><th>Dibuat</th><th><span class="sr">Aksi</span></th></tr></thead><tbody>' + list.map(o => { const u = userById(o.userId) || {}; return '<tr><td><div style="display:flex;gap:10px;align-items:center">' + av(u.name || '?', o.userId, 'round sm') + '<span><strong>' + esc(u.name || '-') + '</strong><div class="small muted">' + esc(u.role || '') + ' · ' + esc(u.dept || '') + '</div></span></div></td><td>' + esc(KEY_LABEL[o.key] || o.key) + '</td><td><span class="chip c-info">' + esc(o.value) + '</span></td><td class="small">' + esc(o.reason) + '</td><td class="small muted">' + fmtShort(o.at) + ' · ' + esc(userName(o.by)) + '</td><td style="text-align:right">' + (adm ? '<button type="button" class="btn btn-s btn-sm" data-a="ovdel" data-v="' + o.id + '">Cabut</button>' : '') + '</td></tr>'; }).join('') + '</tbody></table></div>'
        : empty('Belum ada pengecualian.', 'Semua orang ikut aturan department masing-masing.', adm ? '<button type="button" class="btn btn-sm" data-a="newoverride">+ Pengecualian</button>' : ''));
  },
  options: (adm) => '<div class="sec-h"><div><span class="lbl">Daftar pilihan</span><h2 class="disp h2">Isi dropdown, diatur admin</h2></div></div><p class="small muted" style="margin:0">Pilihan yang dihapus ga mengubah data lama. Data lama tetap menyimpan nilainya.</p>' +
    '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px">' + Object.keys(DEFAULT_OPTS).map(k => '<div class="card pad" style="display:flex;flex-direction:column;gap:12px;padding:20px"><strong>' + OPT_LABEL[k] + '</strong><div style="display:flex;flex-wrap:wrap;gap:6px">' +
      optList(k).map(v => '<span class="chip c-n" style="padding:4px 6px 4px 12px">' + esc(v) + (adm ? '<button type="button" class="btn-ghost" style="min-height:26px;padding:0 4px" data-a="optdel" data-k="' + k + '" data-v="' + esc(v) + '" aria-label="Hapus ' + esc(v) + '">' + ic('x', 'ic-s') + '</button>' : '') + '</span>').join('') + '</div>' +
      (adm ? '<form data-form="optadd" style="display:flex;gap:8px"><input type="hidden" name="k" value="' + k + '"><label style="flex:1"><span class="sr">Tambah ' + OPT_LABEL[k] + '</span><input class="inp" name="v" placeholder="Tambah pilihan" style="min-height:44px"></label><button type="submit" class="btn btn-sm">Tambah</button></form>' : '') + '</div>').join('') + '</div>',
  changes: () => {
    const q = App.ui.ak.q.toLowerCase();
    const refName = (c) => c.kind === 'record' ? ((recById(c.refId) || {}).name || '-') : c.kind === 'deal' ? ((findDeal(c.refId) || {}).title || '-') : '-';
    const list = (Store.db.changes || []).filter(c => !q || (c.field + ' ' + c.before + ' ' + c.after + ' ' + refName(c)).toLowerCase().includes(q));
    return '<div class="sec-h"><div><span class="lbl">Riwayat data</span><h2 class="disp h2">Isi sebelum dan sesudah</h2></div><label class="search" style="width:260px">' + ic('search', 'ic-s') + '<span class="sr">Cari riwayat</span><input id="ak-q" data-in="akq" value="' + esc(App.ui.ak.q) + '" placeholder="Cari nama data atau kolom"></label></div>' +
      (list.length ? '<div style="display:flex;flex-direction:column;gap:8px">' + list.slice(0, 200).map(c => '<div class="lrow" style="padding:12px 14px"><span class="chip ' + (c.kind === 'deal' ? 'c-y' : 'c-info') + '">' + (c.kind === 'deal' ? 'Deal' : 'Data') + '</span><span style="flex:1 1 300px;min-width:0"><strong>' + esc(refName(c)) + '</strong> <span class="muted">· ' + esc(c.field) + '</span><div class="small">' + (c.before ? '<span class="muted" style="text-decoration:line-through">' + esc(c.before) + '</span> jadi ' : '') + '<b>' + esc(c.after || '(kosong)') + '</b>' + (c.note ? ' <span class="muted">· ' + esc(c.note) + '</span>' : '') + '</div></span><span class="small muted num" style="min-width:150px;text-align:right">' + fmtTime(c.at) + '<br>' + esc(userName(c.by)) + '</span></div>').join('') + '</div>'
        : empty('Belum ada riwayat.', 'Setiap perubahan data dan deal bakal tercatat di sini.', '<a class="btn btn-sm" href="#/cp">Buka Client & Partner</a>'));
  }
};

/* ---------- modal tambahan ---------- */
const ft = (save) => '<button type="button" class="btn btn-s" data-a="close">Batal</button><button type="submit" class="btn">' + ic('check', 'ic-s') + (save || 'Simpan') + '</button>';
const _recordModal = Modals.record;
Modals.record = (m) => {
  let html = _recordModal(m);
  const r = m.rec || {}; const v = Object.assign({}, r, m.val || {});
  const segs = optList('segments'); if (v.segment && !segs.includes(v.segment)) segs.unshift(v.segment);
  html = html.replace(/<label class="fld"><span>Segmen<\/span><input[^>]*><\/label>/, '<label class="fld"><span>Segmen</span><select class="inp" name="segment">' + opt('', v.segment, 'Pilih segmen') + segs.map(s => opt(s, v.segment)).join('') + '</select><small>Daftar segmen diatur admin.</small></label>');
  if (r.id) html = html.replace(/<label class="fld"><span>PIC \*<\/span><select class="inp" name="picId">[\s\S]*?<\/select><\/label>/, '<div class="fld"><span>PIC</span><input class="inp" value="' + esc(userName(r.picId)) + '" disabled><small>Ganti lewat tombol Pindah PIC di detail data, biar tercatat.</small></div>');
  if (m.dupes && m.dupes.length) {
    html = html.replace(/<div style="padding:12px 14px;border-radius:16px;background:#FFF3DA[\s\S]*?<\/div>/, '');
    const box = '<div style="padding:14px 16px;border-radius:18px;background:#FFF3DA;color:#6A4300;display:flex;flex-direction:column;gap:8px"><strong>Ada ' + m.dupes.length + ' data yang mirip.</strong>' + m.dupes.map(x => { const o = recById(x.id); return x.vis ? '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><span class="small" style="flex:1"><b>' + esc(o.name) + '</b> · ' + esc(o.cat) + (o.archived ? ' · diarsipkan' : '') + ' · ' + esc(x.why.join(', ')) + '</span><button type="button" class="btn-ghost small" data-a="dupeopen" data-v="' + o.id + '">Buka data ini</button></div>' : '<span class="small">Data serupa milik department lain (' + esc(x.why.join(', ')) + '). Detailnya ga bisa kamu lihat.</span>'; }).join('') + '<span class="small">Kalau memang data beda, klik Simpan sekali lagi.</span></div>';
    html = html.replace('<div class="modal-f">', box + '<div class="modal-f">');
  }
  return html;
};
Modals.closing = (m) => {
  const d = findDeal(m.dealId); const seeV = canSeeValue(d);
  return modalShell('Closing, deal Won', esc(d.title), '<p style="margin:0;color:var(--ink2)">Deal yang masuk Closing berstatus Won. Kalau deal-nya gagal, pakai tombol Lost di detail deal.</p><div class="form-grid"><label class="fld"><span>Nilai deal (Rp) *</span><input class="inp num" name="value" value="' + esc(m.value != null ? m.value : (seeV ? d.offer : '')) + '"' + inv(m, 'value') + ' inputmode="numeric" autofocus>' + fieldErr(m, 'value') + '</label><label class="fld"><span>Tanggal closing *</span><input class="inp" type="date" name="closedAt" value="' + esc(m.closedAt || today()) + '" max="' + today() + '"' + inv(m, 'closedAt') + '>' + fieldErr(m, 'closedAt') + '</label></div>', ft('Tandai Won'), false, 'closing');
};
Modals.dstatus = (m) => {
  const d = findDeal(m.dealId); const line = lineById(d.lineId); const st = m.st;
  if (st === 'Lost') return modalShell('Tandai Lost', esc(d.title), '<p style="margin:0;color:var(--ink2)">Deal keluar dari papan aktif, tapi stage terakhirnya (' + esc((stageOf(d) || {}).name || '-') + ') tetap tersimpan.</p><div class="form-grid"><label class="fld"><span>Alasan gagal *</span><select class="inp" name="lostReason"' + inv(m, 'lostReason') + '>' + opt('', m.lostReason, 'Pilih alasan') + optList('lostReasons').map(x => opt(x, m.lostReason)).join('') + '</select>' + fieldErr(m, 'lostReason') + '</label><label class="fld"><span>Catatan</span><input class="inp" name="reason" value="' + esc(m.reason || '') + '" placeholder="Opsional"></label></div>', ft('Tandai Lost'), false, 'dstatus');
  if (st === 'On Hold') return modalShell('Tunda deal (On Hold)', esc(d.title), '<p style="margin:0;color:var(--ink2)">Deal tetap di stage ' + esc((stageOf(d) || {}).name || '-') + ' dan muncul lagi di pengingat pada tanggal tinjau ulang.</p><div class="form-grid"><label class="fld" style="grid-column:1/-1"><span>Alasan *</span><textarea class="inp" name="reason"' + inv(m, 'reason') + ' placeholder="Contoh: menunggu anggaran tahun depan">' + esc(m.reason || '') + '</textarea>' + fieldErr(m, 'reason') + '</label><label class="fld"><span>Tanggal tinjau ulang *</span><input class="inp" type="date" name="reviewDate" min="' + today() + '" value="' + esc(m.reviewDate || addDays(14)) + '"' + inv(m, 'reviewDate') + '>' + fieldErr(m, 'reviewDate') + '</label></div>', ft('Simpan On Hold'), false, 'dstatus');
  const stOpts = line.stages.slice(0, -1);
  const back = d.status === 'Won' ? stOpts[stOpts.length - 1].id : (stOpts.some(s => s.id === d.stageId) ? d.stageId : stOpts[0].id);
  const def = m.toStage && stOpts.some(s => s.id === m.toStage) ? m.toStage : back;
  return modalShell('Buka kembali deal', esc(d.title) + ' · ' + esc(d.status), '<p style="margin:0;color:var(--ink2)">' + (d.status === 'Won' ? 'Deal Won cuma bisa dibuka lagi oleh Super Admin atau Kepala Department. Nilai dan tanggal closing dikosongkan, riwayatnya tetap tersimpan.' : 'Deal balik jadi Open di stage yang kamu pilih.') + '</p><div class="form-grid"><label class="fld"><span>Kembali ke stage</span><select class="inp" name="toStage">' + stOpts.map(s => opt(s.id, def, s.name)).join('') + '</select></label><label class="fld"><span>Alasan' + (d.status === 'Won' ? ' *' : '') + '</span><input class="inp" name="reason" value="' + esc(m.reason || '') + '"' + inv(m, 'reason') + ' placeholder="Contoh: klien minta revisi kontrak">' + fieldErr(m, 'reason') + '</label></div>', ft('Buka kembali'), false, 'dstatus');
};
const _dealModal = Modals.deal;
Modals.deal = (m) => {
  let html = _dealModal(m);
  html = html.replace(/<button type="button" class="btn btn-s" data-a="deldeal"[\s\S]*?<\/button>/, '');
  html = html.replace(/<div class="fld" style="grid-column:1\/-1;padding:14px;border-radius:16px;background:var(--bg)"><span>Hasil closing<\/span>[\s\S]*?<\/span><\/div>/, '');
  const d = m.deal; if (!d) return html;
  const btns = [];
  if (d.status === 'Open' && canEditDeal(d)) { btns.push('<button type="button" class="btn btn-s btn-sm" data-a="dsset" data-v="' + d.id + '" data-st="Lost">' + ic('x', 'ic-s') + 'Tandai Lost</button>'); btns.push('<button type="button" class="btn btn-s btn-sm" data-a="dsset" data-v="' + d.id + '" data-st="On Hold">' + ic('cal', 'ic-s') + 'On Hold</button>'); }
  if (d.status !== 'Open' && canReopen(d)) btns.push('<button type="button" class="btn btn-s btn-sm" data-a="reopen" data-v="' + d.id + '">' + ic('refresh', 'ic-s') + 'Buka kembali</button>');
  if (d.status !== 'Open' && !canReopen(d)) btns.push('<span class="small muted">' + (d.status === 'Won' ? 'Deal Won cuma bisa dibuka lagi oleh Super Admin atau Kepala Department.' : 'Cuma PIC atau atasannya yang bisa buka lagi.') + '</span>');
  const info = d.status === 'Lost' ? '<span class="small"><b>Alasan Lost:</b> ' + esc(d.lostReason || '-') + (d.reason ? ' · ' + esc(d.reason) : '') + '</span>' : d.status === 'On Hold' ? '<span class="small"><b>On Hold:</b> ' + esc(d.reason || '-') + ' · tinjau ulang ' + fmtDate(d.reviewDate) + '</span>' : '';
  const hist = (d.history || []).slice().reverse();
  const ch = (Store.db.changes || []).filter(c => c.kind === 'deal' && c.refId === d.id);
  const block = '<div style="display:flex;flex-direction:column;gap:10px;padding:16px;border-radius:20px;background:var(--bg)">' + (btns.length || info ? '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">' + btns.join('') + '</div>' + info : '') +
    '<details class="hist" open><summary><span class="lbl">Riwayat stage dan status (' + hist.length + ')</span></summary><div style="display:flex;flex-direction:column;gap:6px;margin-top:10px;max-height:220px;overflow-y:auto">' + (hist.length ? hist.map(h => '<div class="small" style="display:flex;gap:10px;align-items:flex-start"><span class="dot" style="margin-top:6px;background:' + ({ Won: 'var(--ok)', Lost: 'var(--bad)', 'On Hold': 'var(--warn)' }[h.status] || 'var(--p2)') + '"></span><span style="flex:1"><b>' + (h.from && h.from !== h.to ? esc(h.from) + ' ke ' + esc(h.to) : esc(h.to || '-')) + '</b> · ' + esc(h.status) + (h.note ? ' · ' + esc(h.note) : '') + '<br><span class="muted">' + fmtTime(h.at) + ' · ' + esc(userName(h.by)) + '</span></span></div>').join('') : '<span class="small muted">Belum ada riwayat.</span>') + '</div></details>' +
    (ch.length ? '<details class="hist"><summary><span class="lbl">Perubahan isi deal (' + ch.length + ')</span></summary>' + historyList(ch) + '</details>' : '') + '</div>';
  return html.replace('<div class="modal-f">', block + '<div class="modal-f">');
};
Modals.archive = (m) => { const r = recById(m.id); return modalShell('Arsipkan ' + esc(r.name) + '?', 'Arsip', '<p style="margin:0;color:var(--ink2)">Data ga dihapus. Data pindah ke tab Arsip, ga muncul di daftar dan pencarian, dan bisa dipulihkan kapan aja. Interaksi dan deal-nya tetap tersimpan.</p><label class="fld"><span>Alasan *</span><input class="inp" name="reason"' + inv(m, 'reason') + ' placeholder="Contoh: perusahaan udah tutup" autofocus>' + fieldErr(m, 'reason') + '</label>', ft('Arsipkan'), false, 'archive'); };
Modals.cancelint = (m) => { const i = Store.db.interactions.find(x => x.id === m.id); return modalShell('Batalkan catatan ini?', esc(i.type) + ' · ' + fmtDate(i.date), '<p style="margin:0;color:var(--ink2)">Catatan interaksi ga bisa dihapus. Catatan ini tetap kelihatan dengan tanda dibatalkan, dan follow-up-nya keluar dari antrian.</p><div class="tl-i small">' + esc(i.result) + '</div><label class="fld"><span>Alasan *</span><input class="inp" name="reason"' + inv(m, 'reason') + ' placeholder="Contoh: salah pilih client" autofocus>' + fieldErr(m, 'reason') + '</label>', ft('Batalkan catatan'), false, 'cancelint'); };
Modals.contact = (m) => { const r = recById(m.recId); const c = m.cid ? r.contacts.find(x => x.id === m.cid) : {}; const v = Object.assign({}, c, m.val || {}); return modalShell(m.cid ? 'Edit kontak' : 'Kontak baru', esc(r.name), '<div class="form-grid"><label class="fld"><span>Nama *</span><input class="inp" name="name" value="' + esc(v.name) + '"' + inv(m, 'name') + ' autofocus>' + fieldErr(m, 'name') + '</label><label class="fld"><span>Jabatan</span><input class="inp" name="title" value="' + esc(v.title) + '"></label><label class="fld"><span>Email</span><input class="inp" type="email" name="email" value="' + esc(v.email) + '"' + inv(m, 'email') + '>' + fieldErr(m, 'email') + '</label><label class="fld"><span>Telepon</span><input class="inp" name="phone" value="' + esc(v.phone) + '" inputmode="tel"></label></div>', ft(), false, 'contact'); };
Modals.pic = (m) => { const r = recById(m.id); return modalShell('Pindah PIC', esc(r.name), '<p style="margin:0;color:var(--ink2)">PIC sekarang: <b>' + esc(userName(r.picId)) + '</b>. PIC baru dapat pemberitahuan, dan perpindahannya tercatat di riwayat.</p><div class="form-grid"><label class="fld"><span>PIC baru *</span><select class="inp" name="picId"' + inv(m, 'picId') + '>' + opt('', '', 'Pilih orang') + activeUsers().filter(u => u.id !== r.picId).map(u => opt(u.id, (m.val || {}).picId, u.name + ' · ' + u.dept)).join('') + '</select>' + fieldErr(m, 'picId') + '</label><label class="fld"><span>Alasan *</span><input class="inp" name="reason" value="' + esc((m.val || {}).reason) + '"' + inv(m, 'reason') + ' placeholder="Contoh: pembagian wilayah baru">' + fieldErr(m, 'reason') + '</label></div>', ft('Pindahkan'), false, 'pic'); };
Modals.merge = (m) => {
  const keep = recById(m.keepId);
  const cands = Store.db.records.filter(x => x.id !== keep.id && !x.archived && canSeeRec(x));
  const dupIds = findDupes({ name: keep.name, email: keep.cEmail, phone: keep.phone }, keep.id).map(x => x.r.id);
  cands.sort((a, b) => (dupIds.includes(b.id) - dupIds.includes(a.id)) || a.name.localeCompare(b.name));
  const src = m.srcId ? recById(m.srcId) : null;
  const nI = src ? Store.db.interactions.filter(i => i.recordId === src.id).length : 0, nD = src ? Store.db.deals.filter(d => d.recordId === src.id).length : 0;
  return modalShell('Gabung data ganda', 'Data yang disimpan: ' + esc(keep.name), '<p style="margin:0;color:var(--ink2)">Pilih data duplikat. Interaksi, deal, dan kontaknya dipindah ke <b>' + esc(keep.name) + '</b>, kolom yang kosong diisi dari data duplikat, lalu data duplikat diarsipkan.</p>' +
    '<label class="fld"><span>Data duplikat *</span><select class="inp" name="srcId" data-ch="merge-src"' + inv(m, 'srcId') + '>' + opt('', m.srcId, 'Pilih data') + cands.map(x => opt(x.id, m.srcId, (dupIds.includes(x.id) ? 'Mirip · ' : '') + x.name + ' · ' + x.cat + ' · ' + x.code)).join('') + '</select>' + fieldErr(m, 'srcId') + '</label>' +
    (src ? '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">' + [[keep, 'Disimpan'], [src, 'Diarsipkan']].map(([x, l]) => '<div style="padding:14px;border-radius:18px;background:' + (l === 'Disimpan' ? 'var(--tint)' : 'var(--bg)') + '"><span class="lbl" style="font-size:11px">' + l + '</span><div style="font-weight:800">' + esc(x.name) + '</div><div class="small muted">' + esc(x.code) + ' · ' + esc(x.cat) + ' · PIC ' + esc(userName(x.picId)) + '</div></div>').join('') + '</div><p class="small" style="margin:0">Yang dipindah: <b>' + nI + ' interaksi</b>, <b>' + nD + ' deal</b>, dan <b>' + (src.contacts || []).length + ' kontak</b>.</p>' : ''),
    ft('Gabungkan'), true, 'merge');
};
Modals.view = (m) => modalShell('Simpan tampilan', 'Client & Partner', '<p style="margin:0;color:var(--ink2)">Filter yang sekarang aktif (kategori, department, urutan, pencarian, dan arsip) disimpan dengan nama ini. Tampilan ini cuma muncul di akun kamu.</p><label class="fld"><span>Nama tampilan *</span><input class="inp" name="name"' + inv(m, 'name') + ' placeholder="Contoh: Lead Bandung punya saya" autofocus>' + fieldErr(m, 'name') + '</label>', ft(), false, 'view');
Modals.override = (m) => {
  const v = m.val || {}; const key = v.key || 'deal'; const isFlag = key === 'nilai' || key === 'rate';
  return modalShell('Pengecualian hak akses', 'Akses', '<div class="form-grid"><label class="fld"><span>Pengguna *</span><select class="inp" name="userId"' + inv(m, 'userId') + '>' + opt('', v.userId, 'Pilih pengguna') + Store.db.users.filter(u => u.role !== 'Super Admin').map(u => opt(u.id, v.userId, u.name + ' · ' + u.role + ' · ' + u.dept)).join('') + '</select>' + fieldErr(m, 'userId') + '</label>' +
    '<label class="fld"><span>Bagian</span><select class="inp" name="key" data-ch="ov-key">' + Object.keys(KEY_LABEL).map(k => opt(k, key, KEY_LABEL[k])).join('') + '</select></label>' +
    '<label class="fld"><span>Akses</span><select class="inp" name="value">' + (isFlag ? [['Ya', 'Ya, boleh lihat'], ['Tidak', 'Tidak boleh lihat']] : [['N', 'N, ga ada akses'], ['R', 'R, lihat'], ['C', 'C, tambah dan ubah punya sendiri'], ['M', 'M, kelola semua']]).map(([a, b]) => opt(a, v.value, b)).join('') + '</select></label>' +
    '<label class="fld"><span>Alasan *</span><input class="inp" name="reason" value="' + esc(v.reason) + '"' + inv(m, 'reason') + ' placeholder="Contoh: pegang akun korporat besar">' + fieldErr(m, 'reason') + '</label></div><p class="small muted" style="margin:0">Satu orang cuma punya satu pengecualian per bagian. Yang lama otomatis diganti.</p>', ft(), false, 'override');
};

/* ---------- aksi tambahan ---------- */
const keepForm = (name) => { const f = document.querySelector('[data-form=' + name + ']'); return f ? fd(f) : {}; };
Object.assign(Actions, {
  delrec: (el) => { App.modal = { kind: 'archive', id: el.dataset.v }; render(); },
  restorerec: (el) => { const r = recById(el.dataset.v); App.modal = { kind: 'confirm', title: 'Pulihkan ' + esc(r.name) + '?', text: 'Data balik ke daftar aktif dan bisa dipakai lagi.', ok: 'Pulihkan', fn: () => { r.archived = false; trackChange('record', r.id, 'Status data', 'Diarsipkan', 'Aktif'); audit('Ubah', 'Client & Partner', r.name + ' dipulihkan dari arsip'); toast('Dipulihkan!', r.name + ' aktif lagi.'); } }; render(); },
  cparch: (el) => { App.ui.cp.arch = el.dataset.v === '1'; App.ui.cp.page = 0; render(); },
  cancelint: (el) => { App.modal = { kind: 'cancelint', id: el.dataset.v }; render(); },
  dupeopen: (el) => { App.modal = null; App.drawer = { id: el.dataset.v }; render(); },
  addcontact: (el) => { App.modal = { kind: 'contact', recId: el.dataset.v }; render(); },
  editcontact: (el) => { App.modal = { kind: 'contact', recId: el.dataset.v, cid: el.dataset.c }; render(); },
  cprimary: (el) => { const r = recById(el.dataset.v); const i = r.contacts.findIndex(c => c.id === el.dataset.c); const [c] = r.contacts.splice(i, 1); const old = r.contacts[0]; r.contacts.unshift(c); trackChange('record', r.id, 'Kontak utama', old ? old.name : '', c.name); syncPrimary(r); audit('Ubah', 'Client & Partner', r.name + ': kontak utama jadi ' + c.name); commit(); },
  cremove: (el) => { const r = recById(el.dataset.v); const c = r.contacts.find(x => x.id === el.dataset.c); App.modal = { kind: 'confirm', title: 'Lepas kontak ' + esc(c.name) + '?', text: 'Kontak ini ga muncul lagi di data ' + esc(r.name) + '. Perubahannya tercatat di riwayat.', ok: 'Lepas', fn: () => { r.contacts = r.contacts.filter(x => x.id !== c.id); syncPrimary(r); trackChange('record', r.id, 'Kontak', c.name, '', 'Kontak dilepas'); audit('Ubah', 'Client & Partner', r.name + ': kontak ' + c.name + ' dilepas'); } }; render(); },
  convert: (el) => { const r = recById(el.dataset.v); App.modal = { kind: 'confirm', sub: 'Lead jadi Client', title: 'Jadikan ' + esc(r.name) + ' Client?', text: 'Kategori berubah dari Lead jadi Client. Riwayat interaksi dan deal tetap ikut.', ok: 'Jadikan Client', fn: () => { trackChange('record', r.id, 'Kategori', r.cat, 'Client', 'Konversi Lead'); r.cat = 'Client'; r.convertedAt = today(); audit('Ubah', 'Client & Partner', r.name + ' dikonversi dari Lead jadi Client'); toast('Selamat, jadi Client!', r.name + ' udah naik kelas.'); } }; render(); },
  movepic: (el) => { App.modal = { kind: 'pic', id: el.dataset.v }; render(); },
  mergerec: (el) => { App.modal = { kind: 'merge', keepId: el.dataset.v }; render(); },
  viewsave: () => { App.modal = { kind: 'view' }; render(); },
  viewapply: (el) => { const v = (Store.db.views || []).find(x => x.id === el.dataset.v); Object.assign(App.ui.cp, v.filter, { page: 0 }); render(); toast('Tampilan dipakai', v.name, 'info'); },
  viewdel: (el) => { Store.db.views = (Store.db.views || []).filter(x => x.id !== el.dataset.v); commit(); },
  dsset: (el) => { App.modal = { kind: 'dstatus', dealId: el.dataset.v, st: el.dataset.st }; render(); },
  reopen: (el, e) => { if (e && e.stopPropagation) e.stopPropagation(); App.modal = { kind: 'dstatus', dealId: el.dataset.v, st: 'reopen' }; render(); },
  plview: (el) => { App.ui.pl.view = el.dataset.v; App.anim = true; render(); },
  plst: (el) => { App.ui.pl.st = el.dataset.v; render(); },
  pllost: () => { App.ui.pl.view = 'list'; App.ui.pl.st = 'Lost'; App.anim = true; render(); },
  dbtab: (el) => { App.ui.db.tab = el.dataset.v; App.anim = true; render(); },
  newoverride: () => { App.modal = { kind: 'override' }; render(); },
  ovdel: (el) => { const o = Store.db.overrides.find(x => x.id === el.dataset.v); Store.db.overrides = Store.db.overrides.filter(x => x.id !== o.id); audit('Ubah', 'Hak akses', 'Pengecualian ' + KEY_LABEL[o.key] + ' buat ' + userName(o.userId) + ' dicabut'); commit(); },
  optdel: (el) => { const k = el.dataset.k, v = el.dataset.v; Store.db.options = Store.db.options || JSON.parse(JSON.stringify(DEFAULT_OPTS)); Store.db.options[k] = optList(k).filter(x => x !== v); audit('Ubah', 'Daftar pilihan', OPT_LABEL[k] + ': ' + v + ' dihapus'); commit(); },
  notifall: () => { (Store.db.notifs || []).forEach(n => { if (n.userId === me().id) n.read = true; }); Store.save(); render(); },
  notifopen: (el) => { const n = (Store.db.notifs || []).find(x => x.id === el.dataset.v); if (!n) return; n.read = true; Store.save(); App.pop = null; const [k, id] = (n.link || '').split(':'); if (k === 'rec') { App.drawer = { id }; if (App.route !== 'cp') location.hash = '#/cp'; else render(); } else if (k === 'deal' && findDeal(id)) { App.modal = { kind: 'deal', deal: findDeal(id) }; render(); } else render(); },
  simexpire: () => expireSession('Ini simulasi sesi berakhir buat uji coba.'),
  relogin: () => { App.expired = null; App.login = { step: 'idle', email: '', err: '' }; location.hash = '#/login'; render(); },
  undoimport: (el) => { const b = Store.db.imports.find(x => x.id === el.dataset.v); App.modal = { kind: 'confirm', sub: 'Batalkan import', title: 'Tarik balik ' + esc(b.file) + '?', text: b.created.r.length + ' data baru, ' + b.created.i.length + ' interaksi, dan ' + b.created.d.length + ' deal dari import ini ditarik. ' + b.updated.length + ' data yang diperbarui balik ke isi sebelumnya.', ok: 'Batalkan import', danger: true, fn: () => undoImport(b) }; render(); }
});
const _loginas = Actions.loginas; Actions.loginas = (el) => { Store.db.sessionStart = Date.now(); Store.db.lastActive = Date.now(); App.expired = null; _loginas(el); };
const _switch = Actions.switch; Actions.switch = (el) => { Store.db.sessionStart = Date.now(); Store.db.lastActive = Date.now(); _switch(el); };
Changes['merge-src'] = (el) => { App.modal.srcId = el.value; App.modal.still = true; render(); };
Changes['ov-key'] = () => { App.modal.val = keepForm('override'); App.modal.val.value = ''; App.modal.still = true; render(); };

/* ---------- form tambahan ---------- */
const reqErr = (m, o, keys, msg) => { const err = {}; keys.forEach(k => { if (!o[k]) err[k] = msg[k] || 'Wajib diisi.'; }); if (Object.keys(err).length) { m.err = err; m.still = true; render(); return true; } return false; };
Object.assign(Forms, {
  record: (o) => {
    const m = App.modal; const err = {};
    if (!o.name) err.name = 'Nama wajib diisi.'; if (o.cEmail && !emailOk(o.cEmail)) err.cEmail = 'Format email belum benar.';
    if (Object.keys(err).length) { m.err = err; m.val = o; render(); return; }
    const dupes = findDupes({ name: o.name, email: o.cEmail, phone: o.phone }, (m.rec || {}).id);
    if (dupes.length && !m.dupeOk) { m.dupes = dupes.map(x => ({ id: x.r.id, vis: canSeeRec(x.r), why: x.why })); m.dupeOk = true; m.val = o; m.err = {}; m.still = true; render(); return; }
    if ('rate' in o) o.rate = num(o.rate);
    if (m.rec) {
      const r = ensureRec(m.rec); const L = { name: 'Nama', cat: 'Kategori', segment: 'Segmen', dept: 'Department pemilik', source: 'Sumber', city: 'Kota', note: 'Catatan', rate: 'Rate card' };
      Object.keys(L).forEach(k => { if (k in o) { trackChange('record', r.id, L[k], k === 'rate' && r[k] ? fmtRp(r[k]) : r[k], k === 'rate' && o[k] ? fmtRp(o[k]) : o[k]); r[k] = o[k]; } });
      const c0 = r.contacts[0] || (r.contacts[0] = { id: uid('c') });
      [['contact', 'name', 'Nama kontak utama'], ['title', 'title', 'Jabatan kontak'], ['cEmail', 'email', 'Email kontak'], ['phone', 'phone', 'Telepon kontak']].forEach(([f, cf, l]) => { if (f in o) { trackChange('record', r.id, l, c0[cf], o[f]); c0[cf] = o[f]; } });
      syncPrimary(r); r.updatedAt = today(); audit('Ubah', 'Client & Partner', r.name); toast('Tersimpan!', r.name + ' udah diperbarui.');
    } else {
      const r = { id: uid('r'), code: nextCode('r'), createdAt: today(), updatedAt: today(), name: o.name, cat: o.cat, segment: o.segment || '', picId: o.picId, dept: o.dept, source: o.source, city: o.city || '', note: o.note || '', rate: o.rate || '', archived: false, contacts: [{ id: uid('c'), name: o.contact || '', title: o.title || '', email: o.cEmail || '', phone: o.phone || '' }] };
      syncPrimary(r); Store.db.records.push(r); audit('Tambah', 'Client & Partner', r.name + ' (' + r.code + ')'); notify(r.picId, me().name + ' menjadikan kamu PIC ' + r.name, 'rec:' + r.id); toast('Masuk, mantap!', r.name + ' ditambahkan.'); App.drawer = { id: r.id };
    }
    App.modal = null; commit();
  },
  deal: (o) => {
    const m = App.modal; const err = {};
    if (!o.title) err.title = 'Judul wajib diisi.'; if (!o.recordId) err.recordId = 'Pilih Client & Partner.';
    if (Object.keys(err).length) { m.err = err; m.val = Object.assign({}, m.val, o); render(); return; }
    if (m.deal) {
      const d = m.deal; const L = { title: 'Judul', recordId: 'Client & Partner', target: 'Target closing', ownerId: 'PIC deal', service: 'Layanan' };
      const show = (k, v) => k === 'ownerId' ? userName(v) : k === 'recordId' ? ((recById(v) || {}).name || '') : v;
      Object.keys(L).forEach(k => { if (o[k] !== undefined && String(o[k]) !== String(d[k] || '')) { trackChange('deal', d.id, L[k], show(k, d[k]), show(k, o[k])); if (k === 'ownerId') notify(o[k], 'Deal ' + d.title + ' sekarang kamu yang pegang', 'deal:' + d.id); d[k] = o[k]; } });
      if (o.offer !== undefined) { const nv = num(o.offer); trackChange('deal', d.id, 'Nilai penawaran', d.offer ? fmtRp(d.offer) : '', nv ? fmtRp(nv) : ''); d.offer = nv; }
      audit('Ubah', 'Deal', d.title); toast('Tersimpan!', d.title + ' diperbarui.');
    } else {
      const line = lineById(o.lineId); const d = { id: uid('dl'), code: nextCode('d'), title: o.title, recordId: o.recordId, lineId: line.id, stageId: o.stageId || line.stages[0].id, status: 'Open', offer: num(o.offer), value: '', ownerId: o.ownerId, target: o.target, closedAt: '', reason: '', lostReason: '', reviewDate: '', service: o.service, createdAt: today(), history: [] };
      dealLog(d, '', stageOf(d).name, 'Open', 'Deal dibuat'); Store.db.deals.push(d); audit('Tambah', 'Deal', d.title + ' di ' + line.name); notify(d.ownerId, me().name + ' menjadikan kamu PIC deal ' + d.title, 'deal:' + d.id); App.ui.pl.line = line.id; toast('Deal masuk!', d.title + ' ada di ' + stageOf(d).name + '.');
    }
    App.modal = null; commit();
  },
  closing: (o) => {
    const m = App.modal; const d = findDeal(m.dealId); const err = {};
    if (!num(o.value)) err.value = 'Nilai deal wajib diisi buat Won.'; if (!o.closedAt) err.closedAt = 'Tanggal closing wajib diisi.';
    if (Object.keys(err).length) { m.err = err; m.value = o.value; m.closedAt = o.closedAt; m.still = true; render(); return; }
    const line = lineById(d.lineId); const from = stageOf(d);
    d.stageId = line.stages[line.stages.length - 1].id; d.status = 'Won'; d.value = num(o.value); d.closedAt = o.closedAt; d.reason = ''; d.lostReason = ''; d.reviewDate = '';
    dealLog(d, from ? from.name : '', stageOf(d).name, 'Won', 'Closing'); audit('Ubah', 'Deal', d.title + ' dari ' + (from ? from.name : '-') + ' ke Closing, Won');
    App.modal = null; commit(); toast('Deal won. Keren!', d.title + (canSeeValue(d) ? ', ' + fmtRp(d.value) : '') + '.');
  },
  dstatus: (o) => {
    const m = App.modal; const d = findDeal(m.dealId); const st = m.st; Object.assign(m, o);
    if (st === 'Lost') { if (reqErr(m, o, ['lostReason'], { lostReason: 'Pilih alasan gagal.' })) return; d.status = 'Lost'; d.lostReason = o.lostReason; d.reason = o.reason || ''; d.closedAt = today(); d.reviewDate = ''; dealLog(d, '', (stageOf(d) || {}).name, 'Lost', o.lostReason + (o.reason ? ', ' + o.reason : '')); audit('Ubah', 'Deal', d.title + ' Lost: ' + o.lostReason); toast('Dicatat Lost', d.title + ' keluar dari papan aktif.', 'info'); }
    else if (st === 'On Hold') { if (reqErr(m, o, ['reason', 'reviewDate'], { reason: 'Isi alasannya.', reviewDate: 'Isi tanggal tinjau ulang.' })) return; if (o.reviewDate < today()) { m.err = { reviewDate: 'Tanggal tinjau ulang ga boleh lewat dari hari ini.' }; m.still = true; render(); return; } d.status = 'On Hold'; d.reason = o.reason; d.reviewDate = o.reviewDate; dealLog(d, '', (stageOf(d) || {}).name, 'On Hold', o.reason + ', tinjau ' + fmtShort(o.reviewDate)); audit('Ubah', 'Deal', d.title + ' On Hold sampai ' + o.reviewDate); toast('Ditunda dulu', 'Diingatkan lagi ' + fmtShort(o.reviewDate) + '.', 'info'); }
    else {
      if (d.status === 'Won' && reqErr(m, o, ['reason'], { reason: 'Alasan wajib buat buka lagi deal Won.' })) return;
      const prev = d.status; const from = stageOf(d);
      d.status = 'Open'; d.stageId = o.toStage; if (prev === 'Won') { d.value = ''; d.closedAt = ''; } if (prev === 'Lost') d.closedAt = ''; d.reviewDate = '';
      dealLog(d, from ? from.name : '', stageOf(d).name, 'Open', 'Dibuka kembali dari ' + prev + (o.reason ? ': ' + o.reason : '')); audit('Ubah', 'Deal', d.title + ' dibuka kembali dari ' + prev);
      toast('Deal aktif lagi', d.title + ' balik ke ' + stageOf(d).name + '.', 'info');
    }
    App.modal = null; commit();
  },
  archive: (o) => { const m = App.modal; if (reqErr(m, o, ['reason'], { reason: 'Isi alasan arsip.' })) return; const r = recById(m.id); r.archived = true; r.archivedAt = today(); r.archivedBy = me().id; r.archiveReason = o.reason; trackChange('record', r.id, 'Status data', 'Aktif', 'Diarsipkan', o.reason); audit('Arsip', 'Client & Partner', r.name + ' diarsipkan: ' + o.reason); App.modal = null; commit(); toast('Diarsipkan', r.name + ' pindah ke tab Arsip.', 'info'); },
  cancelint: (o) => { const m = App.modal; if (reqErr(m, o, ['reason'], { reason: 'Isi alasan pembatalan.' })) return; const i = Store.db.interactions.find(x => x.id === m.id); i.cancelled = true; i.cancelReason = o.reason; i.cancelledBy = me().id; i.cancelledAt = new Date().toISOString(); audit('Batal', 'Interaksi', i.type + ' ' + fmtDate(i.date) + ' dengan ' + (recById(i.recordId) || {}).name + ': ' + o.reason); App.modal = null; commit(); toast('Catatan dibatalkan', 'Tetap kelihatan di riwayat dengan tanda batal.', 'info'); },
  contact: (o) => {
    const m = App.modal; const err = {}; if (!o.name) err.name = 'Nama wajib diisi.'; if (o.email && !emailOk(o.email)) err.email = 'Format email belum benar.';
    if (Object.keys(err).length) { m.err = err; m.val = o; m.still = true; render(); return; }
    const r = ensureRec(recById(m.recId));
    if (m.cid) { const c = r.contacts.find(x => x.id === m.cid); ['name', 'title', 'email', 'phone'].forEach(k => { trackChange('record', r.id, 'Kontak ' + (c.name || '') + ': ' + k, c[k], o[k]); c[k] = o[k]; }); audit('Ubah', 'Client & Partner', r.name + ': kontak ' + o.name + ' diubah'); }
    else { r.contacts.push({ id: uid('c'), name: o.name, title: o.title, email: o.email, phone: o.phone }); trackChange('record', r.id, 'Kontak', '', o.name, 'Kontak baru'); audit('Ubah', 'Client & Partner', r.name + ': kontak ' + o.name + ' ditambah'); }
    syncPrimary(r); App.modal = null; commit(); toast('Kontak tersimpan', o.name + ' ada di ' + r.name + '.');
  },
  pic: (o) => { const m = App.modal; m.val = o; if (reqErr(m, o, ['picId', 'reason'], { picId: 'Pilih PIC baru.', reason: 'Isi alasannya.' })) return; const r = recById(m.id); const old = r.picId; trackChange('record', r.id, 'PIC', userName(old), userName(o.picId), o.reason); r.picId = o.picId; audit('Ubah', 'Client & Partner', r.name + ': PIC ' + userName(old) + ' jadi ' + userName(o.picId) + ' (' + o.reason + ')'); notify(o.picId, me().name + ' memindahkan ' + r.name + ' ke kamu', 'rec:' + r.id); notify(old, r.name + ' sekarang dipegang ' + userName(o.picId), 'rec:' + r.id); App.modal = null; commit(); toast('PIC pindah', r.name + ' sekarang dipegang ' + userName(o.picId) + '.'); },
  merge: (o) => {
    const m = App.modal; if (!o.srcId) { m.err = { srcId: 'Pilih data duplikatnya.' }; m.still = true; render(); return; }
    const keep = ensureRec(recById(m.keepId)), src = ensureRec(recById(o.srcId));
    let ni = 0, nd = 0;
    Store.db.interactions.forEach(i => { if (i.recordId === src.id) { i.recordId = keep.id; ni++; } });
    Store.db.deals.forEach(d => { if (d.recordId === src.id) { d.recordId = keep.id; nd++; } });
    const emails = keep.contacts.map(c => String(c.email || '').toLowerCase());
    src.contacts.forEach(c => { if (!c.email || !emails.includes(String(c.email).toLowerCase())) keep.contacts.push(Object.assign({}, c, { id: uid('c') })); });
    ['segment', 'city', 'source', 'note', 'rate'].forEach(k => { if (!keep[k] && src[k]) { trackChange('record', keep.id, k, '', src[k], 'Diisi dari ' + src.name); keep[k] = src[k]; } });
    syncPrimary(keep);
    src.archived = true; src.archivedAt = today(); src.archivedBy = me().id; src.archiveReason = 'Digabung ke ' + keep.name; src.mergedInto = keep.id;
    trackChange('record', keep.id, 'Gabung data', '', src.name + ' (' + src.code + ')', ni + ' interaksi, ' + nd + ' deal dipindah');
    trackChange('record', src.id, 'Status data', 'Aktif', 'Digabung ke ' + keep.name);
    audit('Gabung', 'Client & Partner', src.name + ' (' + src.code + ') digabung ke ' + keep.name + ' (' + keep.code + ')');
    App.modal = null; App.drawer = { id: keep.id }; commit(); toast('Digabung!', ni + ' interaksi dan ' + nd + ' deal pindah ke ' + keep.name + '.');
  },
  view: (o) => { const m = App.modal; if (reqErr(m, o, ['name'], { name: 'Kasih nama tampilannya.' })) return; const ui = App.ui.cp; Store.db.views = Store.db.views || []; Store.db.views.push({ id: uid('v'), userId: me().id, name: o.name, filter: { cat: ui.cat, dept: ui.dept, q: ui.q, sort: ui.sort, arch: !!ui.arch } }); App.modal = null; commit(); toast('Tampilan disimpan', o.name + ' bisa dipakai kapan aja.'); },
  override: (o) => {
    const m = App.modal; m.val = o; if (reqErr(m, o, ['userId', 'reason'], { userId: 'Pilih pengguna.', reason: 'Isi alasannya.' })) return;
    Store.db.overrides = (Store.db.overrides || []).filter(x => !(x.userId === o.userId && x.key === o.key));
    Store.db.overrides.push({ id: uid('ov'), userId: o.userId, key: o.key, value: o.value, reason: o.reason, by: me().id, at: new Date().toISOString() });
    audit('Ubah', 'Hak akses', 'Pengecualian ' + KEY_LABEL[o.key] + ' = ' + o.value + ' buat ' + userName(o.userId) + ' (' + o.reason + ')'); notify(o.userId, 'Hak akses kamu buat ' + KEY_LABEL[o.key] + ' diubah jadi ' + o.value, '');
    App.modal = null; commit(); toast('Pengecualian disimpan', userName(o.userId) + ' · ' + KEY_LABEL[o.key] + ' ' + o.value, 'info');
  },
  optadd: (o) => { if (!o.v) return; Store.db.options = Store.db.options || JSON.parse(JSON.stringify(DEFAULT_OPTS)); const list = optList(o.k); if (list.some(x => x.toLowerCase() === o.v.toLowerCase())) { toast('Udah ada', o.v + ' udah ada di daftar.', 'bad'); return; } list.push(o.v); Store.db.options[o.k] = list; audit('Ubah', 'Daftar pilihan', OPT_LABEL[o.k] + ': ' + o.v + ' ditambah'); commit(); }
});

/* ---------- import: catat batch supaya bisa dibatalkan ---------- */
const _doImport = doImport;
doImport = function () {
  const p = App.modal.parsed; const file = App.modal.file;
  const before = { r: new Set(Store.db.records.map(x => x.id)), i: new Set(Store.db.interactions.map(x => x.id)), d: new Set(Store.db.deals.map(x => x.id)) };
  const updated = p.records.filter(x => x.ex).map(x => ({ id: x.ex.id, before: JSON.stringify(x.ex) }));
  _doImport();
  const created = { r: Store.db.records.filter(x => !before.r.has(x.id)).map(x => x.id), i: Store.db.interactions.filter(x => !before.i.has(x.id)).map(x => x.id), d: Store.db.deals.filter(x => !before.d.has(x.id)).map(x => x.id) };
  created.r.forEach(id => { const r = recById(id); r.contacts = [{ id: uid('c'), name: r.contact || '', title: r.title || '', email: r.cEmail || '', phone: r.phone || '' }]; r.archived = false; });
  updated.forEach(u => { const r = recById(u.id); ensureRec(r); Object.assign(r.contacts[0], { name: r.contact, title: r.title, email: r.cEmail, phone: r.phone }); trackChange('record', r.id, 'Import', '', file, 'Data diperbarui dari import'); });
  created.i.forEach(id => { const i = Store.db.interactions.find(x => x.id === id); i.cancelled = false; });
  created.d.forEach(id => { const d = findDeal(id); d.history = []; if (d.status === 'Lost') { d.lostReason = optList('lostReasons').find(x => x.toLowerCase() === String(d.reason).toLowerCase()) || 'Lainnya'; } if (d.status === 'On Hold' && !d.reviewDate) d.reviewDate = addDays(14); dealLog(d, '', (stageOf(d) || {}).name, d.status, 'Dari import ' + file); });
  Store.db.imports = Store.db.imports || [];
  Store.db.imports.unshift({ id: uid('im'), file, at: new Date().toISOString(), by: me().id, created, updated, undone: false });
  Store.save(); render();
};
function undoImport(b) {
  const cr = new Set(b.created.r), ci = new Set(b.created.i), cd = new Set(b.created.d);
  Store.db.records = Store.db.records.filter(x => !cr.has(x.id));
  Store.db.interactions = Store.db.interactions.filter(x => !ci.has(x.id) && !cr.has(x.recordId));
  Store.db.deals = Store.db.deals.filter(x => !cd.has(x.id) && !cr.has(x.recordId));
  b.updated.forEach(u => { const i = Store.db.records.findIndex(x => x.id === u.id); if (i >= 0) Store.db.records[i] = JSON.parse(u.before); });
  b.undone = true; b.undoneAt = new Date().toISOString(); b.undoneBy = me().id;
  audit('Batal', 'Import', b.file + ' dibatalkan: ' + b.created.r.length + ' data, ' + b.created.i.length + ' interaksi, ' + b.created.d.length + ' deal ditarik, ' + b.updated.length + ' data dikembalikan');
  App.drawer = null; toast('Import dibatalkan', b.file + ' udah ditarik balik.', 'info');
}

/* ---------- batas waktu sesi ---------- */
const IDLE_MS = 60 * 60 * 1000, MAX_MS = 12 * 60 * 60 * 1000;
function expireSession(reason) {
  const u = me(); if (!u) return;
  audit('Logout', 'Sesi', u.name + ': sesi berakhir. ' + reason);
  App.expired = { reason, name: u.name }; Store.db.session = null; App.modal = null; App.drawer = null; App.pop = null; App.pal = null; Store.save(); render();
}
let _lastTouch = 0;
['click', 'keydown', 'pointermove', 'scroll'].forEach(ev => document.addEventListener(ev, () => { const n = Date.now(); if (n - _lastTouch > 15000 && me()) { _lastTouch = n; Store.db.lastActive = n; Store.save(); } }, { passive: true, capture: true }));
setInterval(() => { if (!me()) return; const n = Date.now(); if (n - (Store.db.lastActive || n) > IDLE_MS) expireSession('Ga ada aktivitas selama 60 menit.'); else if (n - (Store.db.sessionStart || n) > MAX_MS) expireSession('Udah 12 jam sejak login.'); }, 30000);

/* ---------- galat global ---------- */
const _render = render;
render = function () { try { _render(); } catch (e) { console.error(e); document.getElementById('app').innerHTML = errorPage(e); } };
window.addEventListener('error', (e) => { if (e && e.message && !/ResizeObserver/.test(e.message)) toast('Ada yang galat', 'Coba ulangi. Kalau masih, muat ulang halaman.', 'bad'); });


/* ---------- tema warna ---------- */
const THEMES = [
  { k: 'samudra', name: 'Samudra', desc: 'Biru cerah, kuning hangat, dan sentuhan glossy yang ringan.', sw: ['#1246A0', '#177DB7', '#FFD43B', '#80CAEF'] },
  { k: 'grafit', name: 'Grafit', desc: 'Arang gelap, kuning logo jadi aksen utama, aksen mint. Tegas dan netral.', sw: ['#1A1D24', '#3A4150', '#FFCE00', '#7CF2C3'] },
  { k: 'anggur', name: 'Anggur', desc: 'Ungu tua ke violet, aksen pink. Paling ekspresif.', sw: ['#3B0B6E', '#6A28D6', '#FFCE00', '#FF8FC2'] },
  { k: 'asli', name: 'Asli', desc: 'Biru OPTIMA seperti sebelumnya, aksen lime.', sw: ['#010088', '#131AB0', '#FFCE00', '#D4FF3A'] }
];
const THEME_KEY = 'optima_client_preview_theme';
function currentTheme() { try { return localStorage.getItem(THEME_KEY) || 'samudra'; } catch (e) { return App.theme || 'samudra'; } }
function applyTheme(k) {
  App.theme = k;
  if (k === 'asli') document.documentElement.removeAttribute('data-theme'); else document.documentElement.setAttribute('data-theme', k);
  try { localStorage.setItem(THEME_KEY, k); } catch (e) { /* abaikan */ }
}
Modals.theme = () => {
  const cur = App.theme || currentTheme();
  return modalShell('Warna tema', 'Tampilan', '<p style="margin:0;color:var(--ink2)">Bentuk dan susunan tetap sama, cuma paletnya yang berganti. Kuning logo OPTIMA dipakai di semua tema. Pilihan ini tersimpan di browser kamu.</p>' +
    '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">' + THEMES.map(t => '<button type="button" data-a="themeset" data-v="' + t.k + '" aria-pressed="' + (t.k === cur) + '" style="text-align:left;cursor:pointer;padding:14px;border-radius:18px;background:#fff;font:inherit;color:var(--ink);display:flex;flex-direction:column;gap:10px;border:' + (t.k === cur ? '2px solid var(--p2);box-shadow:0 0 0 3px rgba(var(--p-rgb),.12)' : '1px solid var(--line2)') + '">' +
      '<span aria-hidden="true" style="display:flex;height:44px;border-radius:12px;overflow:hidden"><i style="flex:2;background:linear-gradient(135deg,' + t.sw[0] + ',' + t.sw[1] + ')"></i><i style="flex:1;background:' + t.sw[2] + '"></i><i style="flex:1;background:' + t.sw[3] + '"></i></span>' +
      '<span style="display:flex;justify-content:space-between;align-items:center"><strong>' + t.name + '</strong>' + (t.k === cur ? '<span class="chip c-ok"><span class="dot"></span>Dipakai</span>' : '') + '</span><span class="small muted">' + t.desc + '</span></button>').join('') + '</div>',
    '<button type="button" class="btn" data-a="close">Selesai</button>', true);
};
Actions.themepick = () => { App.pop = null; App.modal = { kind: 'theme' }; render(); };
Actions.themeset = (el) => { applyTheme(el.dataset.v); App.modal.still = true; render(); toast('Tema ' + THEMES.find(t => t.k === el.dataset.v).name, 'Langsung kepakai di semua halaman.', 'info'); };
applyTheme(currentTheme());

/* ---------- boot ---------- */
Store.load();
['options', 'overrides', 'views', 'imports', 'notifs', 'changes'].forEach(k => { if (!Store.db[k]) Store.db[k] = k === 'options' ? JSON.parse(JSON.stringify(DEFAULT_OPTS)) : []; });
Store.db.records.forEach(ensureRec);
if (Store.db.session && !(userById(Store.db.session) || {}).active) Store.db.session = null;
if (Store.db.session) { const n = Date.now(); if (!Store.db.sessionStart) Store.db.sessionStart = n; if (!Store.db.lastActive) Store.db.lastActive = n; if (n - Store.db.lastActive > IDLE_MS) { App.expired = { reason: 'Ga ada aktivitas selama 60 menit.', name: (me() || {}).name }; Store.db.session = null; } else if (n - Store.db.sessionStart > MAX_MS) { App.expired = { reason: 'Udah 12 jam sejak login.', name: (me() || {}).name }; Store.db.session = null; } }
parseHash();
if (me() && (App.route === 'akses') && !canViewAdmin()) App.route = 'mywork';
render();
