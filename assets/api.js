/* OPTIMA CRM · sambungan ke backend Laravel (G1).
   Aktif hanya kalau halaman disajikan backend (window.OPTIMA_API). Tanpa backend, prototipe jalan seperti biasa.
   Store.db tetap dipakai sebagai cache tampilan: login, pengguna, hak akses, daftar pilihan, Client & Partner,
   pipeline, deal, dan interaksi diisi dari server, dan setiap perubahan dikirim ke server dulu. */
(() => {
  'use strict';
  if (!window.OPTIMA_API) return;
  const BASE = window.OPTIMA_API;
  App.backend = { devLogin: false, sso: { google: false } };

  /* ---------- HTTP ---------- */
  const cookie = (n) => { const c = document.cookie.split('; ').find(x => x.startsWith(n + '=')); return c ? decodeURIComponent(c.slice(n.length + 1)) : ''; };
  class ApiError extends Error { constructor(status, data) { super((data && data.message) || 'Galat ' + status); this.status = status; this.data = data || {}; } }
  async function req(method, path, body) {
    let res;
    try {
      res = await fetch(BASE + '/' + path, {
        method, credentials: 'same-origin',
        headers: Object.assign({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, body ? { 'Content-Type': 'application/json' } : {}),
        body: body ? JSON.stringify(body) : undefined
      });
    } catch (e) { throw new ApiError(0, { message: 'Ga bisa terhubung ke server. Cek koneksi lalu coba lagi.' }); }
    let data = null; try { data = await res.json(); } catch (e) { /* respons kosong */ }
    if (res.status === 401 && path !== 'bootstrap') { sessionEnded(data); throw new ApiError(401, data); }
    if (!res.ok) throw new ApiError(res.status, data);
    return data;
  }
  const Api = window.Api = { req, get: (p) => req('GET', p), post: (p, b) => req('POST', p, b || {}), patch: (p, b) => req('PATCH', p, b), del: (p) => req('DELETE', p) };

  /** Galat dari server ke form (422) atau toast. */
  function fail(e, m) {
    if (e.status === 422 && m) { m.err = Object.fromEntries(Object.entries(e.data.errors || {}).map(([k, v]) => [k, v[0]])); m.still = true; render(); return; }
    if (e.status === 401) return;
    toast(e.status === 403 ? 'Ga punya akses' : e.status === 419 ? 'Halaman kedaluwarsa' : 'Gagal tersimpan', e.status === 419 ? 'Muat ulang halaman lalu coba lagi.' : e.message, 'bad');
    if (m) { m.still = true; m.busy = false; render(); }
  }

  /* ---------- sesi ---------- */
  const REASON = { session_expired: 'Udah 12 jam sejak login.', account_inactive: 'Akun kamu sudah dinonaktifkan. Hubungi Super Admin.' };
  function sessionEnded(data) {
    const u = me(); if (!u) return;
    App.expired = { reason: REASON[(data || {}).code] || 'Ga ada aktivitas selama 60 menit.', name: u.name };
    Store.db.session = null; App.modal = null; App.drawer = null; App.pop = null; App.pal = null; Store.save(); render();
    loadLoginInfo();
  }
  // Sesi server berakhir setelah 60 menit tanpa permintaan. Selama pengguna aktif di halaman, sesi dijaga lewat ping.
  let lastTouch = 0, lastPing = Date.now();
  ['click', 'keydown', 'pointermove', 'scroll'].forEach(ev => document.addEventListener(ev, () => { lastTouch = Date.now(); }, { passive: true, capture: true }));
  setInterval(() => { if (me() && lastTouch > lastPing) { lastPing = Date.now(); Api.post('ping').catch(() => {}); } }, 4 * 60 * 1000);

  async function loadLoginInfo() {
    try {
      const s = await Api.get('session');
      App.backend = { devLogin: s.devLogin, sso: s.sso };
      if (!me()) { remapLocal(idMap(knownUsers(), s.accounts, 'email'), {}); Store.db.users = s.accounts || []; Store.save(); }
    } catch (e) { /* halaman login tetap tampil */ }
    render();
  }

  /* ---------- isi Store dari server ---------- */
  const idMap = (oldList, newList, key) => { const m = {}; const byKey = {}; (newList || []).forEach(x => { byKey[String(x[key] || '').toLowerCase()] = x.id; }); (oldList || []).forEach(x => { const n = byKey[String(x[key] || '').toLowerCase()]; if (n && n !== x.id) m[x.id] = n; }); return m; };
  const swap = (obj, k, map) => { if (obj && obj[k] && map[obj[k]]) obj[k] = map[obj[k]]; };
  /** Data lokal (deal, interaksi, log) masih memakai id contoh. Samakan dengan id server lewat email dan kode data. */
  function remapLocal(um, rm) {
    const db = Store.db;
    (db.deals || []).forEach(d => { swap(d, 'recordId', rm); swap(d, 'ownerId', um); (d.history || []).forEach(h => swap(h, 'by', um)); });
    (db.interactions || []).forEach(i => { swap(i, 'recordId', rm); swap(i, 'by', um); swap(i, 'cancelledBy', um); });
    ['audit', 'changes', 'imports'].forEach(k => (db[k] || []).forEach(x => swap(x, 'by', um)));
    (db.notifs || []).forEach(n => swap(n, 'userId', um));
    (db.views || []).forEach(v => swap(v, 'userId', um));
  }
  /** Pengguna lama untuk penyamaan id: isi cache ditambah akun contoh dari seed(), supaya id contoh (u1, u2, ...) selalu bisa dipetakan. */
  const knownUsers = () => { const seen = new Set(); return (Store.db.users || []).concat(seed().users).filter(u => !seen.has(u.id) && seen.add(u.id)); };
  function applyBootstrap(b) {
    const db = Store.db;
    const um = idMap(knownUsers(), b.users, 'email');
    const rm = idMap(db.records, b.records, 'code');
    remapLocal(um, rm);
    // Profil (foto, bio) masih disimpan di browser: bawa ke data pengguna dari server.
    const prof = {}; (db.users || []).forEach(u => { if (u.profile) prof[String(u.email).toLowerCase()] = u.profile; });
    const users = b.users.map(u => Object.assign(u, prof[u.email.toLowerCase()] ? { profile: prof[u.email.toLowerCase()] } : {}));
    // Pengguna di Data terhapus hanya dipakai untuk menampilkan nama di riwayat lama.
    db.users = users.filter(u => !u.deleted); db.deletedUsers = users.filter(u => u.deleted);
    db.trash = b.trash || [];
    db.lineUsers = (b.pipelineAccess || {}).users || [];
    db.departments = b.departments; db.services = b.services; db.access = b.access; db.overrides = b.overrides; db.options = b.options;
    db.records = b.records.map(ensureRec);
    // Sejak G2 pipeline, deal, dan interaksi juga datang dari server.
    db.lines = b.lines; db.deals = b.deals; db.interactions = b.interactions;
    db.notifs = b.notifs; db.views = b.views; db.audit = b.audit; db.changes = b.changes; db.imports = b.imports;
    // Data yang direferensikan deal atau interaksi lokal tapi tidak boleh dilihat: tampil sebagai data tersembunyi.
    const ids = new Set(db.records.map(r => r.id));
    const missing = new Set([].concat((db.deals || []).map(d => d.recordId), (db.interactions || []).map(i => i.recordId)).filter(id => id && !ids.has(id)));
    missing.forEach(id => db.records.push({ id, code: '-', name: 'Data tidak bisa dilihat', cat: 'Lead', hidden: true, archived: false, contacts: [], contact: '', title: '', cEmail: '', phone: '', picId: '', dept: '', createdAt: '', updatedAt: '' }));
    db.session = b.me.id;
    App.backend = { devLogin: b.devLogin, sso: App.backend.sso };
    App.expired = null;
    Store.save();
  }
  async function loadBootstrap() { applyBootstrap(await Api.get('bootstrap')); }

  /** Ganti satu record di cache dengan versi server, lalu tandai riwayatnya perlu dimuat ulang. */
  function putRecord(r) {
    const i = Store.db.records.findIndex(x => x.id === r.id);
    ensureRec(r); if (i >= 0) Store.db.records[i] = r; else Store.db.records.push(r);
    delete histLoaded[r.id];
    Store.save();
    return r;
  }

  /* ---------- riwayat perubahan dari audit log server ---------- */
  const histLoaded = {};
  async function loadHistory(id) {
    histLoaded[id] = 'loading';
    try {
      const res = await Api.get('client-partners/' + encodeURIComponent(id) + '/history');
      Store.db.changes = (Store.db.changes || []).filter(c => !(c.kind === 'record' && c.refId === id)).concat(res.data);
      histLoaded[id] = true;
      if (App.drawer && App.drawer.id === id) render();
    } catch (e) { delete histLoaded[id]; }
  }
  const _Drawer = Drawer;
  Drawer = function () { const id = App.drawer && App.drawer.id; if (id && !histLoaded[id] && (recById(id) || {}).code !== '-') loadHistory(id); return _Drawer(); };

  /* ---------- login ---------- */
  const LOGIN_ERR = {
    not_registered: 'Email ini belum didaftarkan. Hubungi Super Admin di tim kamu.',
    inactive: 'Akun kamu sudah dinonaktifkan. Hubungi Super Admin.',
    sso_not_configured: 'Login Google belum disiapkan di server ini. Untuk uji coba, pakai akun uji.',
    sso_failed: 'Login lewat Google gagal. Coba sekali lagi.',
    wrong_domain: 'Pakai akun Google kantor, bukan akun pribadi.',
    not_verified: 'Email Google kamu belum terverifikasi.'
  };
  async function devLogin(email) {
    App.login.step = 'loading'; App.login.err = ''; render();
    try {
      await Api.post('auth/dev-login', { email });
      await loadBootstrap();
      App.login = { step: 'idle', email: '', err: '' }; App.pop = null; App.drawer = null; App.anim = true;
      location.hash = '#/mywork'; render();
    } catch (e) {
      App.login.step = 'idle'; App.login.err = e.message; render();
    }
  }
  Actions.sso = () => {
    if (App.backend.sso.google) { App.login.step = 'loading'; render(); location.href = BASE + '/auth/google/redirect'; return; }
    if (App.backend.devLogin) { App.login.step = 'pick'; App.login.err = ''; render(); return; }
    App.login.err = LOGIN_ERR.sso_not_configured; render();
  };
  Actions.loginas = (el) => { const u = userById(el.dataset.v); if (u) devLogin(u.email); };
  Forms.loginemail = (o) => {
    App.login.email = o.email;
    if (!emailOk(o.email || '')) { App.login.err = 'Email belum valid. Contoh: nama@kantor-kamu.id'; render(); return; }
    if (!App.backend.devLogin) { App.login.err = 'Masuk pakai tombol SSO di atas ya.'; render(); return; }
    devLogin(o.email);
  };
  Actions.switch = (el) => {
    if (!App.backend.devLogin) { toast('Ganti akun', 'Keluar dulu, lalu masuk dengan akun lain.', 'info'); return; }
    const u = userById(el.dataset.v); if (u) devLogin(u.email);
  };
  Actions.logout = async () => {
    try { await Api.post('auth/logout'); } catch (e) { /* tetap keluar di sisi browser */ }
    Store.db.session = null; App.pop = null; App.drawer = null; App.modal = null; Store.save();
    location.hash = '#/login'; await loadLoginInfo();
  };
  const _relogin = Actions.relogin;
  Actions.relogin = () => { _relogin(); loadLoginInfo(); };
  Actions.simexpire = async () => {
    const u = me(); try { await Api.post('auth/logout'); } catch (e) { /* abaikan */ }
    App.expired = { reason: 'Ini simulasi sesi berakhir buat uji coba.', name: u && u.name };
    Store.db.session = null; App.pop = null; Store.save(); render(); loadLoginInfo();
  };

  /* ---------- Client & Partner lewat API ---------- */
  // Status (selalu Lead saat dibuat), PIC, dan divisi ditentukan server dari akun yang login, jadi tidak dikirim.
  const PAYLOAD = ['name', 'kind', 'partnerType', 'service', 'segment', 'source', 'city', 'note', 'rate', 'contact', 'title', 'cEmail', 'phone'];
  Forms.record = async (o) => {
    const m = App.modal; if (m.busy) return;
    const err = {};
    if (!o.name) err.name = 'Nama wajib diisi.'; if (o.cEmail && !emailOk(o.cEmail)) err.cEmail = 'Format email belum benar.';
    if (Object.keys(err).length) { m.err = err; m.val = o; render(); return; }
    m.val = o; m.busy = true;
    try {
      if (!m.dupeOk) {
        const d = await Api.post('client-partners/duplicates', { name: o.name, email: o.cEmail || '', phone: o.phone || '', excludeId: m.rec ? Number(m.rec.id) : null });
        const dupes = d.matches.map(x => ({ id: x.id, vis: !!recById(x.id), why: x.why })).concat((d.hiddenWhy || []).map(why => ({ vis: false, why })));
        if (dupes.length) { m.dupes = dupes; m.dupeOk = true; m.err = {}; m.busy = false; m.still = true; render(); return; }
      }
      const body = {}; PAYLOAD.forEach(k => { if (k in o) body[k] = k === 'rate' ? (o.rate === '' ? null : num(o.rate)) : o[k]; });
      const res = m.rec ? await Api.patch('client-partners/' + m.rec.id, body) : await Api.post('client-partners', body);
      const r = putRecord(res.data);
      App.modal = null;
      if (!m.rec) App.drawer = { id: r.id };
      toast(m.rec ? 'Tersimpan!' : 'Masuk, mantap!', r.name + (m.rec ? ' udah diperbarui.' : ' ditambahkan.'));
      render();
    } catch (e) { m.busy = false; fail(e, m); }
  };
  Forms.archive = async (o) => {
    const m = App.modal; if (!o.reason) { m.err = { reason: 'Isi alasan arsip.' }; m.still = true; render(); return; }
    try { const r = putRecord((await Api.post('client-partners/' + m.id + '/archive', { reason: o.reason })).data); App.modal = null; render(); toast('Diarsipkan', r.name + ' pindah ke tab Arsip.', 'info'); } catch (e) { fail(e, m); }
  };
  const confirmApi = (opts, call, done) => { App.modal = Object.assign({ kind: 'confirm' }, opts, { fn: () => { call().then(res => { const r = putRecord(res.data); render(); done(r); }).catch(e => fail(e)); } }); render(); };
  Actions.restorerec = (el) => { const r = recById(el.dataset.v); confirmApi({ title: 'Pulihkan ' + esc(r.name) + '?', text: 'Data balik ke daftar aktif dan bisa dipakai lagi.', ok: 'Pulihkan' }, () => Api.post('client-partners/' + r.id + '/restore'), x => toast('Dipulihkan!', x.name + ' aktif lagi.')); };
  Forms.handover = async (o) => {
    const m = App.modal; if (handoverErr(m, o)) return;
    const dept = Store.db.departments.find(d => d.name === o.dept);
    try {
      await Api.post('client-partners/' + m.id + '/handover', { deptId: Number(dept.id), picId: Number(o.picId), reason: o.reason });
      // Akses ikut divisi penerima: muat ulang supaya data, deal, dan interaksi yang terlihat sesuai cakupan baru.
      const stillVisible = seesAll(me()) || o.dept === me().dept;
      await loadBootstrap(); App.modal = null; if (!stillVisible) App.drawer = null; render();
      toast('Penanganan diserahkan', o.dept + ' · ' + userName(o.picId) + (stillVisible ? '' : '. Data ini ga muncul lagi di divisimu.'), 'info');
    } catch (e) { m.val = o; fail(e, m); }
  };
  Forms.contact = async (o) => {
    const m = App.modal; m.val = o;
    if (!o.name) { m.err = { name: 'Nama wajib diisi.' }; m.still = true; render(); return; }
    if (o.email && !emailOk(o.email)) { m.err = { email: 'Format email belum benar.' }; m.still = true; render(); return; }
    const body = { name: o.name, title: o.title || '', email: o.email || '', phone: o.phone || '' };
    try { putRecord((await (m.cid ? Api.patch('contacts/' + m.cid, body) : Api.post('client-partners/' + m.recId + '/contacts', body))).data); App.modal = null; render(); toast('Kontak tersimpan', o.name + ' tersimpan.'); } catch (e) { fail(e, m); }
  };
  Actions.cprimary = async (el) => { try { putRecord((await Api.post('contacts/' + el.dataset.c + '/primary')).data); render(); } catch (e) { fail(e); } };
  Actions.cremove = (el) => { const r = recById(el.dataset.v); const c = r.contacts.find(x => x.id === el.dataset.c); confirmApi({ title: 'Lepas kontak ' + esc(c.name) + '?', text: 'Kontak ini ga muncul lagi di data ' + esc(r.name) + '. Perubahannya tercatat di riwayat.', ok: 'Lepas' }, () => Api.del('contacts/' + c.id), () => toast('Kontak dilepas', c.name + ' ga muncul lagi di ' + r.name + '.', 'info')); };

  /* ---------- pipeline, deal, dan interaksi lewat API (G2) ---------- */
  const putIn = (key, item) => { const list = Store.db[key]; const i = list.findIndex(x => x.id === item.id); if (i >= 0) list[i] = item; else list.push(item); Store.save(); return item; };
  const putDeal = (d) => putIn('deals', d);
  const putInt = (x) => putIn('interactions', x);
  const putLine = (l) => putIn('lines', l);
  const fmErr = (m, o, err) => { if (Object.keys(err).length) { Object.assign(m, { err, val: Object.assign({}, m.val, o), still: true }); render(); return true; } return false; };

  const _moveDeal = moveDeal;
  moveDeal = function (id, toStage) {
    const d = findDeal(id); if (!d || !canEditDeal(d) || d.stageId === toStage) return;
    const line = lineById(d.lineId);
    // Deal yang bukan Open atau tujuan Closing tetap lewat form (buka kembali / Won) seperti biasa.
    if (d.status !== 'Open' || isClosingStage(line, toStage)) return _moveDeal(id, toStage);
    Api.post('deals/' + id + '/move', { stageId: Number(toStage) }).then(res => { putDeal(res.data); render(); }).catch(e => fail(e));
  };
  Forms.deal = async (o) => {
    const m = App.modal; const err = {};
    if (!o.title) err.title = 'Judul wajib diisi.'; if (!o.recordId) err.recordId = 'Pilih Client & Partner.';
    if (fmErr(m, o, err)) return;
    const body = {}; ['title', 'recordId', 'lineId', 'stageId', 'ownerId', 'target', 'service'].forEach(k => { if (o[k] !== undefined && o[k] !== '') body[k] = o[k]; });
    if (o.offer !== undefined) body.offer = o.offer === '' ? null : num(o.offer);
    try {
      const res = m.deal ? await Api.patch('deals/' + m.deal.id, body) : await Api.post('deals', body);
      const d = putDeal(res.data); App.modal = null; if (!m.deal) App.ui.pl.line = d.lineId; render();
      toast(m.deal ? 'Tersimpan!' : 'Deal masuk!', m.deal ? d.title + ' diperbarui.' : d.title + ' ada di ' + ((stageOf(d) || {}).name || '') + '.');
    } catch (e) { fail(e, m); }
  };
  Forms.closing = async (o) => {
    const m = App.modal; const err = {};
    if (!num(o.value)) err.value = 'Nilai deal wajib diisi buat Won.'; if (!o.closedAt) err.closedAt = 'Tanggal closing wajib diisi.';
    if (Object.keys(err).length) { m.err = err; m.value = o.value; m.closedAt = o.closedAt; m.still = true; render(); return; }
    try { const d = putDeal((await Api.post('deals/' + m.dealId + '/won', { value: num(o.value), closedAt: o.closedAt })).data); const rec = await Api.get('client-partners/' + d.recordId).catch(() => null); if (rec) putRecord(rec.data); App.modal = null; render(); toast('Deal won. Keren!', d.title + (canSeeValue(d) ? ', ' + fmtRp(d.value) : '') + '.'); } catch (e) { m.value = o.value; m.closedAt = o.closedAt; fail(e, m); }
  };
  Forms.dstatus = async (o) => {
    const m = App.modal; const st = m.st; Object.assign(m, o);
    const req = (keys, msg) => { const err = {}; keys.forEach(k => { if (!o[k]) err[k] = msg[k]; }); if (Object.keys(err).length) { m.err = err; m.still = true; render(); return true; } return false; };
    try {
      let res, note;
      if (st === 'Lost') { if (req(['lostReason'], { lostReason: 'Pilih alasan gagal.' })) return; res = await Api.post('deals/' + m.dealId + '/lost', { lostReason: o.lostReason, note: o.reason || '' }); note = ['Dicatat Lost', ' keluar dari papan aktif.', 'info']; }
      else if (st === 'On Hold') { if (req(['reason', 'reviewDate'], { reason: 'Isi alasannya.', reviewDate: 'Isi tanggal tinjau ulang.' })) return; res = await Api.post('deals/' + m.dealId + '/hold', { reason: o.reason, reviewDate: o.reviewDate }); note = ['Ditunda dulu', ' diingatkan lagi ' + fmtShort(o.reviewDate) + '.', 'info']; }
      else { res = await Api.post('deals/' + m.dealId + '/reopen', { toStage: Number(o.toStage), reason: o.reason || '' }); note = ['Deal aktif lagi', ' balik ke Open.', 'info']; }
      const d = putDeal(res.data); App.modal = null; render(); toast(note[0], d.title + note[1], note[2]);
    } catch (e) { fail(e, m); }
  };
  Forms.int = async (o) => {
    const m = App.modal; const err = {};
    if (!o.recordId) err.recordId = 'Cari lalu pilih Client & Partner.'; if (!o.result) err.result = 'Isi hasil pembicaraan.';
    if (o.fuDate && o.date && o.fuDate < o.date) err.fuDate = 'Follow-up ga bisa sebelum tanggal interaksi.';
    if (o.dealId === 'new' && !o.dealTitle) err.dealTitle = 'Kasih judul deal-nya.';
    if (fmErr(m, o, err)) return;
    const body = { recordId: o.recordId, type: o.type, date: o.date || today(), result: o.result, next: o.next || '', fuDate: o.fuDate || null, dealId: o.dealId || '' };
    if (o.dealTitle) body.dealTitle = o.dealTitle; if (o.lineId) body.lineId = o.lineId; if (o.toStage) body.toStage = o.toStage;
    try {
      const res = await Api.post('interactions', body);
      putInt(res.data); if (res.deal) putDeal(res.deal);
      if (res.needsClosing && res.deal) { App.modal = { kind: 'closing', dealId: res.deal.id, st: 'Won' }; render(); toast('Kecatat!', 'Sekarang isi hasil closing ' + res.deal.title + '.', 'info'); return; }
      App.modal = null; render();
      toast('Kecatat!', res.deal ? res.deal.title + ': ' + (res.data.next.split(':')[0] || '').toLowerCase() + '.' : (o.fuDate ? 'Follow-up ' + fmtShort(o.fuDate) + ' masuk antrian.' : 'Interaksi tersimpan.'));
    } catch (e) { fail(e, m); }
  };
  Actions.fudone = async (el) => {
    try { const i = putInt((await Api.post('interactions/' + el.dataset.v + '/done')).data); render(); toast('Nice, beres!', ((recById(i.recordId) || {}).name || 'Follow-up') + ' ditandai selesai.'); } catch (e) { fail(e); }
  };
  Forms.cancelint = async (o) => {
    const m = App.modal; if (!o.reason) { m.err = { reason: 'Isi alasan pembatalan.' }; m.still = true; render(); return; }
    try { putInt((await Api.post('interactions/' + m.id + '/cancel', { reason: o.reason })).data); App.modal = null; render(); toast('Catatan dibatalkan', 'Tetap kelihatan di riwayat dengan tanda batal.', 'info'); } catch (e) { fail(e, m); }
  };
  Forms.stage = async (o) => {
    const m = App.modal; if (!o.name) { m.err = { name: 'Nama stage wajib diisi.' }; m.still = true; render(); return; }
    try {
      const res = m.stageId ? await Api.patch('stages/' + m.stageId, { name: o.name }) : await Api.post('pipelines/' + App.ui.pl.line + '/stages', { name: o.name, after: o.after || null });
      putLine(res.data); App.modal = null; App.anim = true; render(); if (!m.stageId) toast('Stage baru!', o.name + ' siap dipakai.');
    } catch (e) { fail(e, m); }
  };
  Forms.line = async (o) => {
    const m = App.modal; if (!o.name) { m.err = { name: 'Nama wajib diisi.' }; m.still = true; render(); return; }
    try { const l = putLine((await Api.post('pipelines', { name: o.name, stages: o.stages || '' })).data); App.ui.pl.line = l.id; App.modal = null; go('pipeline'); toast('Pipeline baru!', l.name + ' udah ada.'); } catch (e) { fail(e, m); }
  };
  /* ---------- Pipeline per pengguna: daftar dari server sudah disaring ---------- */
  // Pengguna biasa hanya menerima pipeline yang dia pakai, jadi semuanya miliknya. Super Admin menerima semua pipeline
  // beserta pengaturan divisi dan pengecualian, jadi pipeline orang lain dihitung seperti di mode lokal.
  const localLinesFor = linesFor;
  linesFor = function (u) {
    if (u && me() && u.id === me().id && me().role !== 'Super Admin') return (Store.db.lines || []).filter(l => !l.archived);
    return localLinesFor(u);
  };
  Changes.linedept = (el) => {
    const l = lineById(el.dataset.v); if (!l) return;
    const names = el.checked ? (l.depts || []).concat([el.dataset.d]) : (l.depts || []).filter(x => x !== el.dataset.d);
    const deptIds = Store.db.departments.filter(d => names.includes(d.name)).map(d => Number(d.id));
    adminCall(() => req('PUT', 'pipelines/' + l.id + '/departments', { deptIds }), 'Tersimpan', l.name + ' dipakai: ' + (names.join(', ') || 'tidak ada divisi'));
  };
  Forms.lineuser = (o) => { if (!o.userId) return; adminCall(() => req('PUT', 'pipelines/' + o.lineId + '/users', { userId: Number(o.userId), allowed: o.allowed === '1' }), 'Tersimpan', userName(o.userId) + (o.allowed === '1' ? ' ditambahkan' : ' dicabut')); };
  Actions.lineuserdel = (el) => adminCall(() => req('PUT', 'pipelines/' + el.dataset.v + '/users', { userId: Number(el.dataset.u), allowed: null }), 'Tersimpan', userName(el.dataset.u) + ' kembali mengikuti divisinya');

  /* ---------- Data terhapus lewat API: server memeriksa ulang hak dan syaratnya ---------- */
  const trashDone = async (title, text) => {
    await loadBootstrap();
    if (App.drawer && !recById(App.drawer.id)) App.drawer = null;
    if (!visibleLines().some(l => l.id === App.ui.pl.line)) App.ui.pl.line = (visibleLines()[0] || {}).id || '';
    App.modal = null; render(); toast(title, text, title === 'Dihapus' ? 'info' : undefined);
  };
  TrashOps.remove = (kind, id) => {
    const p = TrashOps.plan(kind, id);
    Api.del('trash/' + kind + '/' + id).then(() => trashDone('Dihapus', p.label + ' dapat dipulihkan melalui Data terhapus.')).catch(e => { App.modal = null; fail(e); render(); });
  };
  TrashOps.restore = (id) => {
    const entry = (Store.db.trash || []).find(t => t.id === id);
    Api.post('trash/' + id + '/restore').then(() => trashDone('Dipulihkan', entry.label + (entry.kind === 'user' ? '. Akun dipulihkan dalam keadaan nonaktif.' : ' kembali tersedia.'))).catch(e => { App.modal = null; fail(e); render(); });
  };
  // Daftar Data terhapus dari server sudah disaring sesuai hak akses; syarat pemulihan diperiksa server.
  TrashOps.canRestore = (entry) => !!entry;
  TrashOps.restoreBlock = () => '';

  /* ---------- Akses & Audit, pemberitahuan, tampilan tersimpan lewat API (G2) ---------- */
  // Setelah pengaturan admin berubah, isi Store dimuat ulang supaya hak akses di layar sama dengan di server.
  const refresh = async (okTitle, okText) => { await loadBootstrap(); App.modal = null; render(); if (okTitle) toast(okTitle, okText || '', 'info'); };
  const adminCall = (call, okTitle, okText, m) => call().then(() => refresh(okTitle, okText)).catch(e => { fail(e, m); render(); });
  Forms.user = (o) => {
    const m = App.modal; const err = {}; if (!o.name) err.name = 'Nama wajib diisi.'; if (!emailOk(o.email || '')) err.email = 'Format email belum benar.';
    if (fmErr(m, o, err)) return;
    adminCall(() => Api.post('users', { name: o.name, email: o.email, dept: o.dept, role: o.role }), 'Pengguna masuk!', o.name + ' bisa login lewat SSO.', m);
  };
  Actions.toggleuser = (el) => { const u = userById(el.dataset.v); adminCall(() => Api.patch('users/' + u.id, { active: !u.active }), u.active ? 'Dinonaktifkan' : 'Diaktifkan', u.name + (u.active ? ' langsung keluar dari sistem.' : ' bisa login lagi.')); };
  Changes.udept = (el) => adminCall(() => Api.patch('users/' + el.dataset.v, { dept: el.value }), 'Tersimpan', 'Department diganti.');
  Changes.urole = (el) => adminCall(() => Api.patch('users/' + el.dataset.v, { role: el.value }), 'Tersimpan', 'Role diganti.');
  Changes.acc = (el) => adminCall(() => req('PUT', 'access-grants', { dept: el.dataset.d, entity: el.dataset.k, level: el.value }), 'Hak akses diubah', el.dataset.d + ' · ' + el.dataset.k + ': ' + el.value);
  Forms.dept = (o) => {
    const m = App.modal; if (!o.name) { m.err = { name: 'Nama wajib diisi.' }; m.still = true; render(); return; }
    adminCall(() => m.id ? Api.patch('departments/' + m.id, { name: o.name }) : Api.post('departments', { name: o.name }), 'Tersimpan', o.name, m);
  };
  Forms.override = (o) => {
    const m = App.modal; m.val = o; const err = {}; if (!o.userId) err.userId = 'Pilih pengguna.'; if (!o.reason) err.reason = 'Isi alasannya.';
    if (fmErr(m, o, err)) return;
    adminCall(() => Api.post('access-overrides', { userId: o.userId, key: o.key, value: o.value, reason: o.reason }), 'Pengecualian disimpan', userName(o.userId) + ' · ' + (KEY_LABEL[o.key] || o.key) + ' ' + o.value, m);
  };
  Forms.optadd = async (o) => { if (!o.v) return; try { Store.db.options = (await Api.post('option-lists', { list: o.k, value: o.v })).data; Store.save(); render(); toast('Ditambah', o.v + ' masuk daftar.', 'info'); } catch (e) { fail(e); } };
  Actions.optdel = async (el) => { try { Store.db.options = (await req('DELETE', 'option-lists', { list: el.dataset.k, value: el.dataset.v })).data; Store.save(); render(); } catch (e) { fail(e); } };
  Actions.exportaudit = () => { location.href = BASE + '/audit-logs/export'; };
  const svcId = (id) => Number(id);
  Forms.svcadd = (o) => {
    if (!o.name) return;
    const d = Store.db.departments.find(x => x.name === o.dept);
    Api.post('services', { name: o.name, deptId: d ? Number(d.id) : null }).then(res => { Store.db.services = res.data; Store.save(); render(); toast('Ditambah', o.name + ' masuk layanan ' + o.dept + '.', 'info'); }).catch(e => fail(e));
  };
  Forms.svcedit = (o) => {
    const m = App.modal; if (!o.name) { m.err = { name: 'Isi nama layanan.' }; m.still = true; render(); return; }
    Api.patch('services/' + svcId(m.id), { name: o.name }).then(() => refresh('Tersimpan', 'Nama layanan diganti jadi ' + o.name + '.')).catch(e => { m.val = o; fail(e, m); });
  };
  Changes.svcdept = async (el) => { const d = Store.db.departments.find(x => x.name === el.value); try { Store.db.services = (await Api.patch('services/' + svcId(el.dataset.v), { deptId: d ? Number(d.id) : null })).data; Store.save(); render(); toast('Tersimpan', 'Pemetaan layanan diubah. PIC data yang ada ga berubah.', 'info'); } catch (e) { fail(e); render(); } };
  Actions.notifall = async () => { try { Store.db.notifs = (await Api.post('notifications/read')).data; Store.save(); render(); } catch (e) { fail(e); } };
  const _notifopen = Actions.notifopen;
  Actions.notifopen = (el) => { Api.post('notifications/read', { id: Number(el.dataset.v) }).catch(() => {}); _notifopen(el); };
  Forms.view = async (o) => {
    const m = App.modal; if (!o.name) { m.err = { name: 'Kasih nama tampilannya.' }; m.still = true; render(); return; }
    const ui = App.ui.cp;
    try { Store.db.views = (await Api.post('saved-views', { name: o.name, filter: { cat: ui.cat, dept: ui.dept, service: ui.service || 'all', q: ui.q, sort: ui.sort, arch: !!ui.arch } })).data; Store.save(); App.modal = null; render(); toast('Tampilan disimpan', o.name + ' bisa dipakai kapan aja.'); } catch (e) { fail(e, m); }
  };
  Actions.viewdel = async (el) => { try { Store.db.views = (await Api.del('saved-views/' + el.dataset.v)).data; Store.save(); render(); } catch (e) { fail(e); } };
  // Audit log dan riwayat data dimuat ulang setiap kali halaman Akses & Audit dibuka.
  window.addEventListener('hashchange', () => {
    if (!me() || !/^#\/akses/.test(location.hash) || !canAdmin()) return;
    Promise.all([Api.get('audit-logs'), Api.get('changes')]).then(([a, c]) => { Store.db.audit = a.data; Store.db.changes = c.data; Store.save(); render(); }).catch(() => {});
  });

  /* ---------- import dan gabung data lewat API (G2) ---------- */
  // File dibaca di browser (sama seperti prototipe), lalu barisnya diperiksa ulang dan disimpan oleh server.
  window.readImport = (file) => {
    if (typeof XLSX === 'undefined') { toast('Library Excel ga kebaca', 'Cek file assets/xlsx.full.min.js.', 'bad'); return; }
    const rd = new FileReader();
    rd.onload = async (e) => {
      let sheets;
      try {
        const wb = XLSX.read(new Uint8Array(e.target.result), { type: 'array', cellDates: false });
        sheets = { records: sheetRows(wb, '7', 'kode sumber') || [], interactions: sheetRows(wb, '8', 'kode sumber') || [], deals: sheetRows(wb, '9', 'kode sumber') || [] };
      } catch (x) { toast('File ga bisa dibaca', 'Pastikan formatnya .xlsx atau .csv.', 'bad'); return; }
      toast('Lagi dicek…', file.name, 'info');
      // Import dari menu Partner diperiksa sebagai modul Partner (semua baris Partner, tanpa sheet deal).
      const module = App.route === 'partner' ? 'partner' : 'client';
      try { const res = await Api.post('imports/check', { filename: file.name, sheets, module }); App.modal = { kind: 'import', file: file.name, parsed: res.data, importId: res.data.id }; render(); }
      catch (x) { fail(x); }
    };
    rd.readAsArrayBuffer(file);
  };
  doImport = async function () {
    const m = App.modal; if (!m || m.busy) return; m.busy = true;
    try {
      await Api.post('imports/' + m.importId + '/commit');
      const p = m.parsed; const n = p.records.length + p.ints.length + p.deals.length;
      await refresh(n + ' baris masuk!', p.errors.length ? p.errors.length + ' baris dilewati. Perbaiki lalu import ulang, data yang sama ga bakal dobel.' : 'Semua baris berhasil.');
    } catch (e) { m.busy = false; fail(e); }
  };
  Actions.doimport = () => doImport();
  Actions.undoimport = (el) => {
    const b = Store.db.imports.find(x => x.id === el.dataset.v);
    App.modal = { kind: 'confirm', sub: 'Batalkan import', title: 'Tarik balik ' + esc(b.file) + '?', danger: true, ok: 'Batalkan import',
      text: b.created.r.length + ' data baru, ' + b.created.i.length + ' interaksi, dan ' + b.created.d.length + ' deal dari import ini ditarik. ' + b.updated.length + ' data yang diperbarui balik ke isi sebelumnya.',
      fn: () => { Api.post('imports/' + b.id + '/undo').then(() => refresh('Import dibatalkan', b.file + ' udah ditarik balik.')).catch(e => fail(e)); } };
    render();
  };
  Forms.merge = async (o) => {
    const m = App.modal; if (!o.srcId) { m.err = { srcId: 'Pilih data duplikatnya.' }; m.still = true; render(); return; }
    try {
      const res = await Api.post('client-partners/' + m.keepId + '/merge', { srcId: o.srcId });
      await loadBootstrap(); App.modal = null; App.drawer = { id: res.data.id }; render();
      toast('Digabung!', res.moved.interactions + ' interaksi, ' + res.moved.deals + ' deal, dan ' + res.moved.contacts + ' kontak pindah ke ' + res.data.name + '.');
    } catch (e) { fail(e, m); }
  };
  Actions.resetdata = () => toast('Reset data contoh', 'Cuma ada di mode lokal tanpa server.', 'info');

  /* ---------- boot ---------- */
  async function boot() {
    document.getElementById('app').innerHTML = '<main class="wrap" aria-busy="true">' + skeleton() + '</main>';
    const err = (location.hash.match(/[?&]err=([a-z_]+)/) || [])[1];
    try {
      await loadBootstrap();
    } catch (e) {
      Store.db.session = null;
      if (e.status !== 401) { document.getElementById('app').innerHTML = errorPage(e); return; }
      await loadLoginInfo();
    }
    if (err) { App.login.err = LOGIN_ERR[err] || 'Login gagal. Coba lagi.'; history.replaceState(null, '', '#/login'); }
    parseHash();
    if (me() && App.route === 'akses' && !canViewAdmin()) App.route = 'mywork';
    if (me() && App.route === 'login') location.hash = '#/mywork';
    App.anim = true; render();
  }
  boot();
})();
