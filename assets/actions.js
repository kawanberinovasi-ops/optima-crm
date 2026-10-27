/* OPTIMA CRM · modal, aksi, import/export, boot */
'use strict';
const commit = (anim) => { Store.save(); if (anim) App.anim = true; render(); };
const closeModal = () => { App.modal = null; render(); };
const fd = (form) => { const o = {}; new FormData(form).forEach((v, k) => { o[k] = typeof v === 'string' ? v.trim() : v; }); return o; };
const fieldErr = (m, k) => m.err && m.err[k] ? '<span class="err">' + esc(m.err[k]) + '</span>' : '';
const inv = (m, k) => ' aria-invalid="' + !!(m.err && m.err[k]) + '"';
const visibleRecs = () => Store.db.records.filter(r => !r.archived && canSeeRec(r)).sort((a, b) => a.name.localeCompare(b.name));
const activeUsers = () => Store.db.users.filter(u => u.active && u.role !== 'Viewer');

/* ---------- modal ---------- */
const comboItems = (list) => list.length ? list.map((r, i) => '<button type="button" class="pi" role="option" aria-selected="' + (i === 0) + '" data-a="intpick" data-v="' + r.id + '">' + av(r.name, r.id, 'sm') + '<span style="flex:1"><span class="t">' + esc(r.name) + '</span><span class="d">' + esc(r.cat) + ' · ' + esc(r.city || '-') + ' · ' + esc((userById(r.picId) || {}).name || '-') + '</span></span></button>').join('') : '<div class="small muted" style="padding:12px">Ga ketemu. Cek ejaan atau tambah datanya dulu di Client & Partner.</div>';
const keepModal = () => { const f = document.querySelector('[data-form=int]'); const o = f ? fd(f) : {}; App.modal.val = Object.assign({}, App.modal.val, o); App.modal.still = true; return App.modal.val; };
const Modals = {
  confirm: (m) => modalShell(m.title, m.sub || 'Konfirmasi', '<p style="margin:0;color:var(--ink2)">' + m.text + '</p>', '<button type="button" class="btn btn-s" data-a="close">Batal</button><button type="button" class="btn ' + (m.danger ? 'btn-d' : '') + '" data-a="confirmok">' + esc(m.ok || 'Lanjut') + '</button>'),
  record: (m) => {
    const r = m.rec || {}; const v = Object.assign({ cat: CATS.find(c => can(catEnt(c), 'C')) || 'Lead', picId: me().id, dept: me().dept, source: 'Referensi' }, r, m.val || {});
    const cats = CATS.filter(c => can(catEnt(c), 'C') || c === r.cat);
    return modalShell(r.id ? 'Edit data' : 'Data baru', r.id ? esc(r.code) : 'Client & Partner',
      '<div class="form-grid">' +
      '<label class="fld" style="grid-column:1/-1"><span>Nama *</span><input class="inp" id="f-name" name="name" value="' + esc(v.name) + '"' + inv(m, 'name') + ' placeholder="Contoh: PT Maju Bersama" autofocus>' + fieldErr(m, 'name') + '</label>' +
      '<label class="fld"><span>Kategori *</span><select class="inp" name="cat">' + cats.map(c => opt(c, v.cat)).join('') + '</select></label>' +
      '<label class="fld"><span>Segmen</span><input class="inp" name="segment" value="' + esc(v.segment) + '" placeholder="Korporat, pendidikan, …"></label>' +
      '<label class="fld"><span>PIC *</span><select class="inp" name="picId">' + activeUsers().map(u => opt(u.id, v.picId, u.name + ' · ' + u.dept)).join('') + '</select></label>' +
      '<label class="fld"><span>Department pemilik *</span><select class="inp" name="dept">' + Store.db.departments.map(d => opt(d.name, v.dept)).join('') + '</select></label>' +
      '<label class="fld"><span>Sumber</span><select class="inp" name="source">' + optList('sources').map(s => opt(s, v.source)).join('') + '</select></label>' +
      '<label class="fld"><span>Kota</span><input class="inp" name="city" value="' + esc(v.city) + '"></label>' +
      '<label class="fld"><span>Nama kontak utama</span><input class="inp" name="contact" value="' + esc(v.contact) + '"></label>' +
      '<label class="fld"><span>Jabatan kontak</span><input class="inp" name="title" value="' + esc(v.title) + '"></label>' +
      '<label class="fld"><span>Email kontak</span><input class="inp" type="email" name="cEmail" value="' + esc(v.cEmail) + '"' + inv(m, 'cEmail') + '>' + fieldErr(m, 'cEmail') + '</label>' +
      '<label class="fld"><span>Telepon kontak</span><input class="inp" name="phone" value="' + esc(v.phone) + '" inputmode="tel"></label>' +
      (canSeeRate() ? '<label class="fld"><span>Rate card (KOL/KOC)</span><input class="inp" name="rate" value="' + esc(v.rate) + '" inputmode="numeric" placeholder="Angka saja"><small>Diisi kalau kategori KOL/KOC.</small></label>' : '') +
      '<label class="fld" style="grid-column:1/-1"><span>Catatan</span><textarea class="inp" name="note">' + esc(v.note) + '</textarea></label></div>' +
      (m.dupe ? '<div style="padding:12px 14px;border-radius:16px;background:#FFF3DA;color:#6A4300" class="small"><strong>Nama mirip udah ada:</strong> ' + esc(m.dupe) + '. Simpan sekali lagi kalau memang beda.</div>' : ''),
      '<button type="button" class="btn btn-s" data-a="close">Batal</button><button type="submit" class="btn">' + ic('check', 'ic-s') + 'Simpan</button>', true, 'record');
  },
  int: (m) => {
    const v = Object.assign({ type: 'WhatsApp', date: today(), recordId: m.recordId || '' }, m.val || {});
    const rec = v.recordId ? recById(v.recordId) : null;
    const canDeal = can('deal', 'R');
    const deals = rec && canDeal ? Store.db.deals.filter(d => d.recordId === rec.id && (d.status === 'Open' || d.status === 'On Hold')) : [];
    const canNew = can('deal', 'C');
    if (v.dealId === undefined || (v.dealId && v.dealId !== 'new' && !deals.some(d => d.id === v.dealId))) v.dealId = deals[0] ? deals[0].id : '';
    let pipe = '';
    if (rec && canDeal) {
      const d = deals.find(x => x.id === v.dealId);
      const line = d ? lineById(d.lineId) : lineForDivision(rec.dept);
      const cur = d ? line.stages.findIndex(s => s.id === d.stageId) : -1;
      const editable = d ? canEditDeal(d) : canNew;
      const stOpts = d ? line.stages : line.stages.slice(0, -1);
      const def = v.toStage && stOpts.some(s => s.id === v.toStage) ? v.toStage : (d ? (cur + 1 < line.stages.length - 1 ? line.stages[cur + 1] : line.stages[cur]).id : stOpts[0].id);
      pipe = '<div style="grid-column:1/-1;display:flex;flex-direction:column;gap:12px;padding:16px;border-radius:20px;background:var(--tint)">' +
        '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap"><span class="lbl" style="color:var(--p)">Langkah berikutnya di pipeline</span>' + (d ? '<span class="chip c-info"><span class="dot"></span>Sekarang: ' + esc(line.stages[cur].name) + '</span>' : '') + '</div>' +
        '<div class="form-grid">' +
        '<label class="fld"><span>Deal</span><select class="inp" name="dealId" data-ch="int-deal">' + deals.map(x => opt(x.id, v.dealId, x.title + ' · ' + ((stageOf(x) || {}).name || ''))).join('') + (canNew ? opt('new', v.dealId, '+ Buat deal baru') : '') + opt('', v.dealId, 'Tanpa deal') + '</select></label>' +
        (v.dealId === 'new' ? ('<div class="fld"><span>Divisi / lini bisnis</span><input class="inp" value="' + esc(line.name) + '" disabled><input type="hidden" name="lineId" value="' + line.id + '"></div>') + '<label class="fld"><span>Judul deal *</span><input class="inp" name="dealTitle" value="' + esc(v.dealTitle || '') + '"' + inv(m, 'dealTitle') + ' placeholder="Contoh: Website ' + esc(rec.name) + '">' + fieldErr(m, 'dealTitle') + '</label>' : '') +
        (v.dealId ? '<label class="fld"' + (v.dealId === 'new' ? ' style="grid-column:1/-1"' : '') + '><span>' + (d ? 'Pindah ke stage' : 'Mulai di stage') + '</span><select class="inp" name="toStage"' + (editable ? '' : ' disabled') + '>' + stOpts.map((s, i) => opt(s.id, def, (d && i === cur ? 'Tetap di ' : '') + s.name + (d && i < cur ? ' (mundur)' : ''))).join('') + '</select>' + (editable ? '<small>Kartu deal di Pipeline ikut pindah begitu disimpan.' + (d ? ' Kalau pilih Closing, kamu diminta isi hasilnya.' : '') + '</small>' : '<small>Kamu ga bisa ubah deal ini.</small>') + '</label>' : '') +
        '</div></div>';
    }
    const matches = visibleRecs().filter(r => !v.q || r.name.toLowerCase().includes(String(v.q).toLowerCase())).slice(0, 8);
    return modalShell('Catat interaksi', 'Interaksi',
      '<div class="form-grid"><div class="fld combo" style="grid-column:1/-1;position:relative"><label for="int-q" style="font-size:14px;font-weight:700">Client & Partner *</label>' +
      '<div class="search" style="width:100%;min-height:48px;border-radius:14px' + (m.err && m.err.recordId ? ';border-color:var(--bad)' : '') + '">' + ic('search', 'ic-s') + '<input id="int-q" data-in="intq" autocomplete="off" role="combobox" aria-controls="int-list" aria-expanded="false" value="' + esc(rec ? rec.name : (v.q || '')) + '" placeholder="Ketik nama client atau partner…"' + (rec ? '' : ' autofocus') + '>' + (rec ? '<span class="chip ' + 'c-info' + '">' + esc(rec.cat) + '</span><button type="button" class="btn-ghost" data-a="intclear" aria-label="Ganti client" style="min-height:32px;padding:0 6px">' + ic('x', 'ic-s') + '</button>' : '') + '</div>' +
      '<input type="hidden" name="recordId" value="' + esc(rec ? rec.id : '') + '">' +
      '<div id="int-list" role="listbox" class="pop l" style="top:calc(100% + 6px);width:100%;max-height:300px;overflow-y:auto' + (rec ? ';display:none' : ';display:none') + '">' + comboItems(matches) + '</div>' + fieldErr(m, 'recordId') + '</div>' +
      '<label class="fld"><span>Jenis *</span><select class="inp" name="type">' + optList('intTypes').map(t => opt(t, v.type)).join('') + '</select></label><label class="fld"><span>Tanggal *</span><input class="inp" type="date" name="date" value="' + esc(v.date) + '" max="' + today() + '"></label>' +
      '<label class="fld" style="grid-column:1/-1"><span>Hasil pembicaraan *</span><textarea class="inp" name="result"' + inv(m, 'result') + ' placeholder="Singkat aja, apa hasilnya?">' + esc(v.result) + '</textarea>' + fieldErr(m, 'result') + '</label>' +
      pipe +
      '<label class="fld"><span>Catatan buat follow-up</span><input class="inp" name="next" value="' + esc(v.next) + '" placeholder="Contoh: kirim revisi harga"></label><label class="fld"><span>Tanggal follow-up</span><input class="inp" type="date" name="fuDate" value="' + esc(v.fuDate) + '"' + inv(m, 'fuDate') + '>' + fieldErr(m, 'fuDate') + '</label></div>',
      '<button type="button" class="btn btn-s" data-a="close">Batal</button><button type="submit" class="btn">' + ic('check', 'ic-s') + 'Simpan interaksi</button>', true, 'int');
  },
  deal: (m) => {
    const d = m.deal || {}; const ro = d.id && !canEditDeal(d);
    const v = Object.assign({ lineId: App.ui.pl.line, ownerId: me().id, target: addDays(30), recordId: m.recordId || '' }, d, m.val || {});
    const related = recById(v.recordId);
    const line = d.id ? lineById(d.lineId) : related ? lineForDivision(related.dept) : (visibleLines().find(l => l.id === v.lineId) || visibleLines()[0]);
    if (!line) return modalShell('Deal baru', 'Pipeline', '<p>Belum ada lini bisnis divisimu.</p>', '<button class="btn" data-a="close">Tutup</button>');
    if (!d.id && related && !(divisionUsers(related.dept).some(u => u.id === v.ownerId))) v.ownerId = related.picId;
    const stages = d.id ? line.stages : line.stages.slice(0, -1);
    const sid = v.stageId && line.stages.some(s => s.id === v.stageId) ? v.stageId : (m.stage || stages[0].id);
    const seeV = canSeeValue(d.id ? d : { ownerId: v.ownerId, recordId: related ? related.id : '' });
    const dis = ro ? ' disabled' : '';
    return modalShell(d.id ? esc(d.title) : 'Deal baru', d.id ? esc(d.code) + ' · ' + esc(line.name) : 'Pipeline',
      (d.id ? '<div style="display:flex;gap:8px;flex-wrap:wrap">' + statusChip(d.status) + '<span class="chip c-n">' + esc((stageOf(d) || {}).name || '') + '</span>' + (ro ? '<span class="chip c-n">' + ic('lock', 'ic-s') + 'Lihat saja</span>' : '') + '</div>' : '') +
      '<div class="form-grid"><label class="fld" style="grid-column:1/-1"><span>Judul deal *</span><input class="inp" name="title" value="' + esc(v.title) + '"' + inv(m, 'title') + dis + ' placeholder="Contoh: Website company profile">' + fieldErr(m, 'title') + '</label>' +
      '<label class="fld" style="grid-column:1/-1"><span>Client & Partner *</span><select class="inp" name="recordId" data-ch="deal-record"' + inv(m, 'recordId') + dis + '>' + opt('', v.recordId, 'Pilih data…') + visibleRecs().map(r => opt(r.id, v.recordId, r.name + ' · ' + r.cat)).join('') + '</select>' + fieldErr(m, 'recordId') + '</label>' +
      '<label class="fld"><span>Divisi / lini bisnis</span><input class="inp" value="' + esc(line.name) + '" disabled><input type="hidden" name="lineId" value="' + esc(line.id) + '"></label>' +
      '<label class="fld"><span>Stage</span><select class="inp" name="stageId"' + (d.id ? ' disabled' : '') + '>' + stages.map(s => opt(s.id, sid, s.name)).join('') + '</select>' + (d.id ? '<small>Pindah stage lewat board.</small>' : '') + '</label>' +
      '<label class="fld"><span>Nilai penawaran (Rp)</span>' + (seeV ? '<input class="inp num" name="offer" value="' + esc(v.offer) + '" inputmode="numeric" placeholder="120000000"' + dis + '>' : '<input class="inp" value="Terkunci sesuai hak akses" disabled>') + '</label>' +
      '<label class="fld"><span>Target closing</span><input class="inp" type="date" name="target" value="' + esc(v.target) + '"' + dis + '></label>' +
      '<label class="fld"><span>PIC deal</span><select class="inp" name="ownerId"' + dis + '>' + divisionUsers(related ? related.dept : line.dept).map(u => opt(u.id, v.ownerId, u.name)).join('') + '</select></label>' +
      '<label class="fld"><span>Layanan</span><input class="inp" value="' + esc(related ? serviceName(related.serviceId) : v.service || 'Pilih relasi dahulu') + '" disabled></label>' +
      (d.id && d.status !== 'Open' ? '<div class="fld" style="grid-column:1/-1;padding:14px;border-radius:16px;background:var(--bg)"><span>Hasil closing</span><span class="small">' + esc(d.status) + (d.status === 'Won' ? ' · ' + (seeV ? fmtRp(d.value) : 'nilai terkunci') + ' · ' + fmtDate(d.closedAt) : '') + (d.reason ? ' · ' + esc(d.reason) : '') + '</span></div>' : '') + '</div>',
      (d.id && perm('deal') === 'M' ? '<button type="button" class="btn btn-s" data-a="deldeal" data-v="' + d.id + '" style="margin-right:auto">' + ic('trash', 'ic-s') + 'Hapus</button>' : '') + '<button type="button" class="btn btn-s" data-a="close">' + (ro ? 'Tutup' : 'Batal') + '</button>' + (ro ? '' : '<button type="submit" class="btn">' + ic('check', 'ic-s') + 'Simpan</button>'), true, ro ? null : 'deal');
  },
  closing: (m) => {
    const d = Store.db.deals.find(x => x.id === m.dealId); const st = m.st || 'Won'; const seeV = canSeeValue(d);
    return modalShell('Hasil closing', esc(d.title), seg([['Won', 'Won'], ['Lost', 'Lost'], ['On Hold', 'On Hold']], st, 'closest', 'Hasil') +
      '<div class="form-grid">' + (st === 'Won' ? '<label class="fld"><span>Nilai deal (Rp) *</span><input class="inp num" name="value" value="' + esc(m.value != null ? m.value : (seeV ? d.offer : '')) + '"' + inv(m, 'value') + ' inputmode="numeric">' + fieldErr(m, 'value') + '</label><label class="fld"><span>Tanggal closing *</span><input class="inp" type="date" name="closedAt" value="' + today() + '"></label>' : '<label class="fld" style="grid-column:1/-1"><span>Alasan ' + st + ' *</span><textarea class="inp" name="reason"' + inv(m, 'reason') + ' placeholder="Contoh: anggaran ditunda">' + esc(m.reason || '') + '</textarea>' + fieldErr(m, 'reason') + '</label>') + '</div>',
      '<button type="button" class="btn btn-s" data-a="close">Batal</button><button type="submit" class="btn">' + ic('check', 'ic-s') + 'Simpan hasil</button>', false, 'closing');
  },
  stage: (m) => {
    const line = lineById(App.ui.pl.line); const s = m.stageId ? line.stages.find(x => x.id === m.stageId) : null;
    const isC = s && isClosingStage(line, s.id); const used = s && Store.db.deals.some(d => d.stageId === s.id);
    const pos = line.stages.slice(0, -1);
    return modalShell(s ? 'Ubah stage' : 'Stage baru', esc(line.name), '<div class="form-grid"><label class="fld" style="grid-column:1/-1"><span>Nama stage *</span><input class="inp" name="name" value="' + esc(s ? s.name : '') + '"' + inv(m, 'name') + ' autofocus>' + fieldErr(m, 'name') + '</label>' +
      (!s ? '<label class="fld" style="grid-column:1/-1"><span>Taruh setelah</span><select class="inp" name="after">' + pos.map(x => opt(x.id, pos[pos.length - 1].id, x.name)).join('') + '</select><small>Stage baru selalu sebelum Closing.</small></label>' : '') + '</div>' + (isC ? '<p class="small muted" style="margin:0">Ini stage akhir. Namanya bisa diganti, tapi ga bisa dihapus.</p>' : ''),
      (s && !isC ? '<button type="button" class="btn btn-s" data-a="delstage" data-v="' + s.id + '" style="margin-right:auto"' + '' + '>' + ic('trash', 'ic-s') + 'Hapus stage' + '</button>' : '') + '<button type="button" class="btn btn-s" data-a="close">Batal</button><button type="submit" class="btn">Simpan</button>', false, 'stage');
  },
  line: (m) => modalShell('Lini bisnis baru', 'Pipeline', '<div class="form-grid"><label class="fld" style="grid-column:1/-1"><span>Nama lini bisnis *</span><input class="inp" name="name"' + inv(m, 'name') + ' placeholder="Contoh: Training & Development" autofocus>' + fieldErr(m, 'name') + '</label><label class="fld" style="grid-column:1/-1"><span>Stage awal</span><textarea class="inp" name="stages">Inquiry\nProposal / Offer\nNegotiation</textarea><small>Satu baris satu stage. Closing otomatis ditambah di akhir.</small></label></div>', '<button type="button" class="btn btn-s" data-a="close">Batal</button><button type="submit" class="btn">Buat pipeline</button>', false, 'line'),
  user: (m) => modalShell('Pengguna baru', 'Akses', '<div class="form-grid"><label class="fld"><span>Nama lengkap *</span><input class="inp" name="name"' + inv(m, 'name') + ' value="' + esc((m.val || {}).name) + '" autofocus>' + fieldErr(m, 'name') + '</label><label class="fld"><span>Email *</span><input class="inp" type="email" name="email"' + inv(m, 'email') + ' value="' + esc((m.val || {}).email) + '" placeholder="nama@contoh.test">' + fieldErr(m, 'email') + '</label><label class="fld"><span>Department</span><select class="inp" name="dept">' + Store.db.departments.map(d => opt(d.name, (m.val || {}).dept)).join('') + '</select></label><label class="fld"><span>Role</span><select class="inp" name="role">' + ROLES.map(r => opt(r, (m.val || {}).role || 'Staf')).join('') + '</select></label></div><p class="small muted" style="margin:0">Di versi uji ga ada password. Login cukup pilih akun di halaman SSO uji.</p>', '<button type="button" class="btn btn-s" data-a="close">Batal</button><button type="submit" class="btn">Tambah</button>', false, 'user'),
  dept: (m) => { const d = m.id ? Store.db.departments.find(x => x.id === m.id) : null; return modalShell(d ? 'Ganti nama department' : 'Department baru', 'Akses', '<label class="fld"><span>Nama department *</span><input class="inp" name="name" value="' + esc(d ? d.name : '') + '"' + inv(m, 'name') + ' autofocus>' + fieldErr(m, 'name') + '</label>' + (d ? '<p class="small muted" style="margin:0">Nama baru ikut berubah di pengguna, data, dan hak akses.</p>' : ''), '<button type="button" class="btn btn-s" data-a="close">Batal</button><button type="submit" class="btn">Simpan</button>', false, 'dept'); },
  import: (m) => {
    const p = m.parsed; const ok = p.records.length + p.ints.length + p.deals.length;
    const box = (n, l, cls) => '<div style="flex:1 1 140px;padding:16px;border-radius:20px;background:' + cls + ';display:flex;flex-direction:column"><span class="disp num" style="font-size:40px;color:var(--p)">' + n + '</span><span class="small" style="font-weight:700">' + l + '</span></div>';
    return modalShell('Cek dulu sebelum masuk', esc(m.file), '<div style="display:flex;flex-wrap:wrap;gap:12px">' + box(p.records.filter(x => x.isNew).length, 'Client & Partner baru', 'var(--tint)') + box(p.records.filter(x => !x.isNew).length, 'Client & Partner diperbarui', 'var(--bg)') + box(p.ints.length, 'Interaksi', 'var(--bg)') + box(p.deals.length, 'Deal', 'var(--bg)') + box(p.errors.length, 'Baris dilewati', p.errors.length ? '#FDECEC' : 'var(--bg)') + '</div>' +
      (p.errors.length ? '<div style="display:flex;flex-direction:column;gap:6px;max-height:220px;overflow-y:auto;padding:12px;border-radius:16px;background:#FDECEC"><strong class="small" style="color:#8F1D21">Baris yang dilewati</strong>' + p.errors.slice(0, 50).map(e => '<span class="small" style="color:#5C1114">' + esc(e) + '</span>').join('') + '</div>' : '') +
      (p.skippedExample ? '<p class="small muted" style="margin:0">Baris contoh dari template otomatis dilewati.</p>' : '') + (ok === 0 ? '<p class="small" style="margin:0">Ga ada baris yang bisa diimport. Cek format file sesuai template.</p>' : ''),
      '<button type="button" class="btn btn-s" data-a="close">Batal</button><button type="button" class="btn" data-a="doimport"' + (ok ? '' : ' disabled') + '>' + ic('up', 'ic-s') + 'Import ' + ok + ' baris</button>', true);
  }
};

/* ---------- aksi klik ---------- */
const findDeal = (id) => Store.db.deals.find(d => d.id === id);
function moveDeal(id, toStage) {
  const d = findDeal(id); if (!d || !canEditDeal(d) || d.stageId === toStage) return;
  const line = lineById(d.lineId); if (!line.stages.some(s => s.id === toStage)) return;
  if (isClosingStage(line, toStage)) { App.modal = { kind: 'closing', dealId: id, st: 'Won' }; render(); return; }
  const from = stageOf(d); d.stageId = toStage;
  if (d.status === 'Won' || d.status === 'Lost') { d.status = 'Open'; d.value = ''; d.closedAt = ''; d.reason = ''; }
  audit('Ubah', 'Deal', d.title + ' pindah dari ' + (from ? from.name : '-') + ' ke ' + stageOf(d).name);
  commit();
}
const Actions = {
  pop: (el) => { App.pop = App.pop === el.dataset.v ? null : el.dataset.v; render(); },
  nav: (el) => go(el.dataset.v),
  navline: (el) => { App.ui.pl.line = el.dataset.v; go('pipeline'); },
  scrollfu: (el, e) => { e.preventDefault(); const t = document.getElementById('fu'); if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' }); },
  soon: () => toast('Belum ada di versi uji', 'Chat tim masuk tahap berikutnya.', 'info'),
  pal: () => { App.pop = null; App.pal = { q: '', i: 0 }; render(); setTimeout(() => { const i = document.getElementById('pal-q'); if (i) i.focus(); }, 0); },
  close: () => closeModal(),
  scrim: (el, e) => { if (e.target === el) { App.modal = null; App.pal = null; render(); } },
  closedrawer: () => { App.drawer = null; render(); },
  openrec: (el) => { App.pal = null; App.pop = null; App.drawer = { id: el.dataset.v }; if (App.route !== 'cp' && App.route !== 'mywork') { render(); } else render(); },
  opendeal: (el) => { App.pal = null; App.pop = null; const d = findDeal(el.dataset.v); if (!d) return; App.modal = { kind: 'deal', deal: d }; render(); },
  newrec: () => { App.pop = null; App.modal = { kind: 'record' }; render(); },
  editrec: (el, e) => { e.stopPropagation(); App.modal = { kind: 'record', rec: recById(el.dataset.v) }; render(); },
  delrec: (el) => { const r = recById(el.dataset.v); const n = Store.db.deals.filter(d => d.recordId === r.id).length; App.modal = { kind: 'confirm', title: 'Hapus ' + esc(r.name) + '?', text: 'Data ini, ' + Store.db.interactions.filter(i => i.recordId === r.id).length + ' interaksi, dan ' + n + ' deal terkait ikut terhapus dari browser ini.', ok: 'Hapus', danger: true, fn: () => { Store.db.records = Store.db.records.filter(x => x.id !== r.id); Store.db.interactions = Store.db.interactions.filter(i => i.recordId !== r.id); Store.db.deals = Store.db.deals.filter(d => d.recordId !== r.id); audit('Hapus', 'Client & Partner', r.name + ' (' + r.code + ')'); App.drawer = null; toast('Dihapus', r.name + ' udah ga ada di daftar.', 'info'); } }; render(); },
  intpick: (el) => { const v = keepModal(); v.recordId = el.dataset.v; v.q = ''; v.dealId = undefined; v.toStage = ''; App.modal.err = {}; render(); const n = document.querySelector('[data-form=int] [name=type]'); if (n) n.focus(); },
  intclear: () => { const v = keepModal(); v.recordId = ''; v.q = ''; v.dealId = undefined; render(); setTimeout(() => { const i = document.getElementById('int-q'); if (i) i.focus(); }, 0); },
  newint: (el) => { App.pop = null; App.modal = { kind: 'int', recordId: el.dataset.v || '' }; render(); },
  newdeal: (el) => { App.pop = null; if (!Store.db.records.some(canSeeRec)) { toast('Belum ada data', 'Tambah Client & Partner dulu.', 'bad'); return; } App.modal = { kind: 'deal', recordId: el.dataset.v || '', stage: el.dataset.stage || '' }; render(); },
  fudone: (el) => { const i = Store.db.interactions.find(x => x.id === el.dataset.v); if (!i) return; i.done = true; const r = recById(i.recordId); audit('Ubah', 'Interaksi', 'Follow-up ' + r.name + ' ditandai selesai'); Store.save(); render(); toast('Nice, beres!', r.name + ' ditandai selesai.'); },
  mwf: (el) => { App.ui.mw.f = el.dataset.v; render(); },
  dbper: (el) => { App.ui.db.per = el.dataset.v; App.ui.db.val = ''; App.anim = true; render(); },
  plline: (el) => { App.ui.pl.line = el.dataset.v; App.anim = true; render(); },
  plallc: () => { App.ui.pl.allC = !App.ui.pl.allC; render(); },
  plper: (el) => { App.ui.pl.per = el.dataset.v; App.ui.pl.val = ''; render(); },
  cpcat: (el) => { App.ui.cp.cat = el.dataset.v; App.ui.cp.page = 0; render(); },
  cpreset: () => { Object.assign(App.ui.cp, { cat: 'all', q: '', dept: 'all', service: 'all', page: 0 }); render(); },
  cppage: (el) => { App.ui.cp.page += +el.dataset.v; render(); },
  move: (el, e) => { e.stopPropagation(); const d = findDeal(el.dataset.v); const line = lineById(d.lineId); const i = line.stages.findIndex(s => s.id === d.stageId) + (+el.dataset.dir); if (line.stages[i]) moveDeal(d.id, line.stages[i].id); },
  closest: (el) => { const f = document.querySelector('[data-form=closing]'); const o = f ? fd(f) : {}; App.modal = Object.assign(App.modal, { st: el.dataset.v, err: {}, value: o.value, reason: o.reason }); render(); },
  addstage: () => { App.modal = { kind: 'stage' }; render(); },
  editstage: (el) => { App.modal = { kind: 'stage', stageId: el.dataset.v }; render(); },
  delstage: (el) => { const line = lineById(App.ui.pl.line); const s = line.stages.find(x => x.id === el.dataset.v); line.stages = line.stages.filter(x => x.id !== s.id); audit('Hapus', 'Stage', s.name + ' dari ' + line.name); App.modal = null; commit(); },
  newline: () => { App.pop = null; App.modal = { kind: 'line' }; render(); },
  deldeal: (el) => { const d = findDeal(el.dataset.v); App.modal = { kind: 'confirm', title: 'Hapus deal ini?', text: esc(d.title) + ' bakal hilang dari pipeline.', ok: 'Hapus', danger: true, fn: () => { Store.db.deals = Store.db.deals.filter(x => x.id !== d.id); audit('Hapus', 'Deal', d.title); } }; render(); },
  confirmok: () => { const m = App.modal; App.modal = null; m.fn(); commit(); },
  aktab: (el) => { App.ui.ak.tab = el.dataset.v; App.ui.ak.q = ''; App.anim = true; render(); },
  akreset: () => { Object.assign(App.ui.ak, { q: '', act: 'all', by: 'all' }); render(); },
  newuser: () => { App.modal = { kind: 'user' }; render(); },
  toggleuser: (el) => { const u = userById(el.dataset.v); u.active = !u.active; audit('Ubah', 'Pengguna', u.name + (u.active ? ' diaktifkan' : ' dinonaktifkan')); commit(); },
  newdept: () => { App.modal = { kind: 'dept' }; render(); },
  editdept: (el) => { App.modal = { kind: 'dept', id: el.dataset.v }; render(); },
  deldept: (el) => { const d = Store.db.departments.find(x => x.id === el.dataset.v); Store.db.departments = Store.db.departments.filter(x => x.id !== d.id); delete Store.db.access[d.name]; audit('Hapus', 'Department', d.name); commit(); },
  exportaudit: () => { const rows = [['Waktu', 'Pengguna', 'Aksi', 'Bagian', 'Detail']].concat(Store.db.audit.map(a => [a.at, (userById(a.by) || {}).name || '-', a.action, a.entity, a.detail])); const csv = rows.map(r => r.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\n'); const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv' })); a.download = 'Audit_CRM_OPTIMA_' + today() + '.csv'; a.click(); audit('Export', 'Audit log', rows.length - 1 + ' baris'); Store.save(); render(); },
  switch: (el) => { const u = userById(el.dataset.v); Store.db.session = u.id; audit('Login', 'Sesi', 'Ganti akun uji ke ' + u.name); App.pop = null; App.drawer = null; Store.save(); go(App.route === 'akses' && !canViewAdmin() ? 'mywork' : App.route); toast('Halo, ' + u.name + '!', u.role + ' · ' + u.dept); },
  sso: () => { if (App.login.step !== 'idle') return; App.login.step = 'loading'; render(); setTimeout(() => { App.login.step = 'pick'; render(); }, 900); },
  loginback: () => { App.login.step = 'idle'; render(); },
  loginas: (el) => { const u = userById(el.dataset.v); Store.db.session = u.id; App.login = { step: 'idle', email: '', err: '' }; audit('Login', 'Sesi', u.name + ' masuk lewat SSO uji'); Store.save(); App.anim = true; location.hash = '#/cp'; render(); toast('Masuk, ' + u.name + '!', u.role + ' · ' + u.dept); },
  logout: () => { audit('Logout', 'Sesi', me().name + ' keluar'); Store.db.session = null; App.pop = null; App.drawer = null; App.modal = null; Store.save(); location.hash = '#/login'; render(); },
  resetdata: () => { App.pop = null; App.modal = { kind: 'confirm', title: 'Reset data contoh?', text: 'Semua perubahan di browser ini hilang dan data balik ke kondisi awal.', ok: 'Reset', danger: true, fn: () => { Store.reset(); toast('Data direset', 'Balik ke data contoh awal.', 'info'); } }; render(); },
  export: () => { App.pop = null; exportXlsx(); },
  import: () => { App.pop = null; if (App.route !== 'cp') { location.hash = '#/cp'; } setTimeout(() => Actions.pickfile(), 80); },
  pickfile: () => { const i = document.querySelector('[data-ch=importfile]'); if (i) i.click(); },
  doimport: () => doImport()
};

/* ---------- input & change ---------- */
const Inputs = {
  intq: (v, el) => {
    const hid = document.querySelector('[data-form=int] [name=recordId]');
    if (hid && hid.value) { const vv = keepModal(); vv.recordId = ''; vv.q = v; vv.dealId = undefined; render(); return; }
    const list = document.getElementById('int-list'); if (!list) return;
    const q = v.trim().toLowerCase();
    list.innerHTML = comboItems(visibleRecs().filter(r => !q || (r.name + ' ' + r.contact + ' ' + r.code).toLowerCase().includes(q)).slice(0, 8));
    list.style.display = 'flex'; el.setAttribute('aria-expanded', 'true');
  },
  cpq: (v) => { App.ui.cp.q = v; App.ui.cp.page = 0; render(); },
  plq: (v) => { App.ui.pl.q = v; render(); },
  akq: (v) => { App.ui.ak.q = v; render(); },
  pal: (v) => { App.pal.q = v; App.pal.i = 0; render(); }
};
const Changes = {
  'int-deal': () => { const v = keepModal(); v.toStage = ''; render(); },
  cpdept: (el) => { App.ui.cp.dept = el.value; App.ui.cp.page = 0; render(); },
  cpsort: (el) => { App.ui.cp.sort = el.value; render(); },
  dbval: (el) => { App.ui.db.val = el.value; App.anim = true; render(); },
  dbfl: (el) => { App.ui.db.fline = el.value; render(); },
  plval: (el) => { App.ui.pl.val = el.value; render(); },
  akact: (el) => { App.ui.ak.act = el.value; render(); },
  akby: (el) => { App.ui.ak.by = el.value; render(); },
  'deal-line': (el) => { const f = el.closest('form'); App.modal.val = Object.assign(fd(f), { lineId: el.value, stageId: '' }); App.modal.stage = ''; render(); },
  udept: (el) => { const u = userById(el.dataset.v); audit('Ubah', 'Pengguna', u.name + ' pindah department ' + u.dept + ' ke ' + el.value); u.dept = el.value; commit(); },
  urole: (el) => { const u = userById(el.dataset.v); audit('Ubah', 'Pengguna', u.name + ' role ' + u.role + ' jadi ' + el.value); u.role = el.value; commit(); },
  acc: (el) => { const d = el.dataset.d, k = el.dataset.k; const a = Store.db.access[d] = Store.db.access[d] || {}; audit('Ubah', 'Hak akses', d + ' · ' + k + ': ' + (a[k] || '-') + ' jadi ' + el.value); a[k] = el.value; commit(); },
  importfile: (el) => { const f = el.files && el.files[0]; if (f) readImport(f); el.value = ''; }
};

/* ---------- form submit ---------- */
const num = (s) => { const n = String(s == null ? '' : s).replace(/[^\d]/g, ''); return n ? +n : ''; };
const emailOk = (s) => /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(s);
const Forms = {
  loginemail: (o) => { const u = Store.db.users.find(x => x.email.toLowerCase() === (o.email || '').toLowerCase()); App.login.email = o.email; if (!emailOk(o.email || '')) { App.login.err = 'Email belum valid. Contoh: nama@contoh.test'; render(); return; } if (!u || !u.active) { App.login.err = 'Email ini belum terdaftar atau nonaktif. Minta Super Admin buat nambahin.'; render(); return; } App.login.step = 'loading'; render(); setTimeout(() => Actions.loginas({ dataset: { v: u.id } }), 700); },
  record: (o) => {
    const m = App.modal; const err = {};
    if (!o.name) err.name = 'Nama wajib diisi.'; if (o.cEmail && !emailOk(o.cEmail)) err.cEmail = 'Format email belum benar.';
    if (Object.keys(err).length) { m.err = err; m.val = o; render(); return; }
    const dupe = Store.db.records.find(r => r.id !== (m.rec || {}).id && r.name.toLowerCase() === o.name.toLowerCase());
    if (dupe && !m.dupeOk) { m.dupe = dupe.name; m.dupeOk = true; m.val = o; m.err = {}; render(); return; }
    o.rate = num(o.rate);
    if (m.rec) { Object.assign(m.rec, o, { updatedAt: today() }); audit('Ubah', 'Client & Partner', o.name); toast('Tersimpan!', o.name + ' udah diperbarui.'); }
    else { const r = Object.assign({ id: uid('r'), code: nextCode('r'), createdAt: today(), updatedAt: today(), contact: '', title: '', cEmail: '', phone: '', city: '', segment: '', note: '', rate: '' }, o); Store.db.records.push(r); audit('Tambah', 'Client & Partner', r.name + ' (' + r.code + ')'); toast('Masuk, mantap!', r.name + ' ditambahkan.'); App.drawer = { id: r.id }; }
    App.modal = null; commit();
  },
  int: (o) => {
    const m = App.modal; const err = {};
    const scopedRecord = recById(o.recordId);
    if (!scopedRecord || !canSeeRec(scopedRecord) || !can('interaksi', 'C')) { toast('Tidak ada akses', 'Pilih relasi di divisimu.', 'bad'); return; }
    if (o.dealId && o.dealId !== 'new') { const existing = findDeal(o.dealId); if (!existing || !canSeeDeal(existing) || existing.recordId !== scopedRecord.id) { toast('Deal tidak sesuai', 'Pilih deal dari relasi yang sama.', 'bad'); return; } }
    if (o.dealId === 'new' && !can('deal', 'C')) return;
    if (!o.recordId) err.recordId = 'Cari lalu pilih Client & Partner.'; if (!o.result) err.result = 'Isi hasil pembicaraan.'; if (o.fuDate && o.fuDate < o.date) err.fuDate = 'Follow-up ga bisa sebelum tanggal interaksi.';
    if (o.dealId === 'new' && !o.dealTitle) err.dealTitle = 'Kasih judul deal-nya.';
    if (Object.keys(err).length) { m.err = err; m.val = Object.assign({}, m.val, o); m.still = true; render(); return; }
    const r = recById(o.recordId);
    let deal = null, toClosing = false, stageNote = '';
    if (o.dealId === 'new') {
      const line = lineForDivision(r.dept);
      if (!line.stages.slice(0, -1).some(s => s.id === o.toStage)) o.toStage = line.stages[0].id;
      deal = { id: uid('dl'), code: nextCode('d'), title: o.dealTitle, recordId: r.id, serviceId: r.serviceId || '', lineId: line.id, stageId: o.toStage || line.stages[0].id, status: 'Open', offer: '', value: '', ownerId: me().id, target: addDays(30), closedAt: '', reason: '', service: serviceName(r.serviceId), createdAt: today() };
      deal.history = []; dealLog(deal, '', stageOf(deal).name, 'Open', 'Deal dibuat dari catat interaksi');
      Store.db.deals.push(deal); audit('Tambah', 'Deal', deal.title + ' dari catat interaksi, mulai di ' + stageOf(deal).name);
      stageNote = 'Deal baru di ' + stageOf(deal).name;
    } else if (o.dealId) {
      deal = findDeal(o.dealId);
      if (deal && o.toStage && o.toStage !== deal.stageId && canEditDeal(deal)) {
        const line = lineById(deal.lineId);
        if (isClosingStage(line, o.toStage)) { toClosing = true; stageNote = 'Masuk Closing'; }
        else { const from = stageOf(deal); deal.stageId = o.toStage; const wasHold = deal.status === 'On Hold'; if (wasHold) { deal.status = 'Open'; deal.reviewDate = ''; } dealLog(deal, from ? from.name : '', stageOf(deal).name, 'Open', wasHold ? 'Dibuka lagi lewat catat interaksi' : 'Lewat catat interaksi'); audit('Ubah', 'Deal', deal.title + ' pindah dari ' + (from ? from.name : '-') + ' ke ' + stageOf(deal).name + ' lewat catat interaksi'); stageNote = 'Masuk ' + stageOf(deal).name; }
      } else if (deal) stageNote = 'Tetap di ' + ((stageOf(deal) || {}).name || '');
    }
    const next = [stageNote, o.next].filter(Boolean).join(': ');
    const i = { id: uid('i'), code: nextCode('i'), recordId: r.id, dealId: deal ? deal.id : '', type: o.type, date: o.date || today(), result: o.result, next, fuDate: o.fuDate, done: false, by: me().id };
    Store.db.interactions.push(i); audit('Tambah', 'Interaksi', i.type + ' dengan ' + r.name);
    if (r.picId !== me().id) notify(r.picId, me().name + ' mencatat ' + i.type.toLowerCase() + ' dengan ' + r.name, 'rec:' + r.id);
    if (toClosing) { Store.save(); App.modal = { kind: 'closing', dealId: deal.id, st: 'Won' }; render(); toast('Kecatat!', 'Sekarang isi hasil closing ' + deal.title + '.', 'info'); return; }
    App.modal = null; commit();
    toast('Kecatat!', stageNote && deal ? deal.title + ': ' + stageNote.toLowerCase() + '.' : (o.fuDate ? 'Follow-up ' + fmtShort(o.fuDate) + ' masuk antrian.' : 'Interaksi dengan ' + r.name + ' tersimpan.'));
  },
  deal: (o) => {
    const m = App.modal; const err = {};
    if (!o.title) err.title = 'Judul wajib diisi.'; if (!o.recordId) err.recordId = 'Pilih Client & Partner.';
    if (Object.keys(err).length) { m.err = err; m.val = Object.assign({}, m.val, o); render(); return; }
    if (m.deal) { const d = m.deal; ['title', 'recordId', 'target', 'ownerId', 'service'].forEach(k => { if (o[k] !== undefined) d[k] = o[k]; }); if (o.offer !== undefined) d.offer = num(o.offer); audit('Ubah', 'Deal', d.title); toast('Tersimpan!', d.title + ' diperbarui.'); }
    else { const line = lineById(o.lineId); const d = { id: uid('dl'), code: nextCode('d'), title: o.title, recordId: o.recordId, lineId: line.id, stageId: o.stageId || line.stages[0].id, status: 'Open', offer: num(o.offer), value: '', ownerId: o.ownerId, target: o.target, closedAt: '', reason: '', service: o.service, createdAt: today() }; Store.db.deals.push(d); audit('Tambah', 'Deal', d.title + ' di ' + line.name); App.ui.pl.line = line.id; toast('Deal masuk!', d.title + ' ada di ' + stageOf(d).name + '.'); }
    App.modal = null; commit();
  },
  closing: (o) => {
    const m = App.modal; const d = findDeal(m.dealId); const st = m.st || 'Won'; const err = {};
    if (st === 'Won' && !num(o.value)) err.value = 'Nilai deal wajib buat Won.'; if (st !== 'Won' && !o.reason) err.reason = 'Alasan wajib diisi.';
    if (Object.keys(err).length) { m.err = err; m.value = o.value; m.reason = o.reason; render(); return; }
    const line = lineById(d.lineId); const from = stageOf(d);
    d.stageId = line.stages[line.stages.length - 1].id; d.status = st;
    if (st === 'Won') { d.value = num(o.value); d.closedAt = o.closedAt || today(); d.reason = ''; } else { d.reason = o.reason; d.closedAt = st === 'Lost' ? today() : ''; d.value = ''; }
    audit('Ubah', 'Deal', d.title + ' dari ' + (from ? from.name : '-') + ' ke Closing, ' + st);
    App.modal = null; commit();
    if (st === 'Won') toast('Deal won. Keren!', d.title + (canSeeValue(d) ? ', ' + fmtRp(d.value) : '') + '.'); else toast('Tersimpan', d.title + ' dicatat ' + st + '.', 'info');
  },
  stage: (o) => {
    const m = App.modal; const line = lineById(App.ui.pl.line);
    if (!o.name) { m.err = { name: 'Nama stage wajib diisi.' }; render(); return; }
    if (line.stages.some(s => s.name.toLowerCase() === o.name.toLowerCase() && s.id !== m.stageId)) { m.err = { name: 'Nama stage udah dipakai di pipeline ini.' }; render(); return; }
    if (m.stageId) { const s = line.stages.find(x => x.id === m.stageId); audit('Ubah', 'Stage', s.name + ' jadi ' + o.name + ' di ' + line.name); s.name = o.name; }
    else { const i = line.stages.findIndex(s => s.id === o.after); line.stages.splice(i + 1, 0, { id: uid(line.id + 's'), name: o.name }); audit('Tambah', 'Stage', o.name + ' di ' + line.name); toast('Stage baru!', o.name + ' siap dipakai.'); }
    App.modal = null; commit(true);
  },
  line: (o) => {
    if (!o.name) { App.modal.err = { name: 'Nama wajib diisi.' }; render(); return; }
    const id = uid('ln'); const names = (o.stages || '').split('\n').map(s => s.trim()).filter(Boolean).filter(s => s.toLowerCase() !== 'closing');
    Store.db.lines.push({ id, name: o.name, stages: names.concat(['Closing']).map((n, i) => ({ id: id + 's' + i, name: n })) });
    audit('Tambah', 'Pipeline', o.name); App.ui.pl.line = id; App.modal = null; Store.save(); go('pipeline'); toast('Pipeline baru!', o.name + ' udah ada.');
  },
  user: (o) => {
    const err = {}; if (!o.name) err.name = 'Nama wajib diisi.'; if (!emailOk(o.email || '')) err.email = 'Format email belum benar.'; else if (Store.db.users.some(u => u.email.toLowerCase() === o.email.toLowerCase())) err.email = 'Email ini udah terdaftar.';
    if (Object.keys(err).length) { App.modal.err = err; App.modal.val = o; render(); return; }
    Store.db.users.push({ id: uid('u'), name: o.name, email: o.email, dept: o.dept, role: o.role, active: true }); audit('Tambah', 'Pengguna', o.name + ' (' + o.role + ', ' + o.dept + ')'); App.modal = null; commit(); toast('Pengguna masuk!', o.name + ' bisa login lewat SSO uji.');
  },
  dept: (o) => {
    const m = App.modal; if (!o.name) { m.err = { name: 'Nama wajib diisi.' }; render(); return; }
    if (Store.db.departments.some(d => d.name.toLowerCase() === o.name.toLowerCase() && d.id !== m.id)) { m.err = { name: 'Nama udah dipakai.' }; render(); return; }
    if (m.id) { const d = Store.db.departments.find(x => x.id === m.id); const old = d.name; d.name = o.name; Store.db.users.forEach(u => { if (u.dept === old) u.dept = o.name; }); Store.db.records.forEach(r => { if (r.dept === old) r.dept = o.name; }); if (Store.db.access[old]) { Store.db.access[o.name] = Store.db.access[old]; delete Store.db.access[old]; } audit('Ubah', 'Department', old + ' jadi ' + o.name); }
    else { Store.db.departments.push({ id: uid('d'), name: o.name }); const a = { nilai: 'Tidak', rate: 'Tidak' }; ENTS.forEach(([k]) => { a[k] = 'R'; }); Store.db.access[o.name] = a; audit('Tambah', 'Department', o.name); }
    App.modal = null; commit();
  }
};

/* ---------- import & export Excel ---------- */
function readImport(file) {
  if (typeof XLSX === 'undefined') { toast('Library Excel ga kebaca', 'Cek file assets/xlsx.full.min.js.', 'bad'); return; }
  const rd = new FileReader();
  rd.onload = (e) => { try { const wb = XLSX.read(new Uint8Array(e.target.result), { type: 'array', cellDates: false }); App.modal = { kind: 'import', file: file.name, parsed: parseWorkbook(wb) }; render(); } catch (x) { toast('File ga bisa dibaca', 'Pastikan formatnya .xlsx atau .csv.', 'bad'); } };
  rd.readAsArrayBuffer(file);
}
function sheetRows(wb, prefix, key) {
  let name = wb.SheetNames.find(n => n.trim().toLowerCase().startsWith(prefix));
  if (!name && prefix === '7') name = wb.SheetNames.length === 1 ? wb.SheetNames[0] : null;
  if (!name) return null;
  const arr = XLSX.utils.sheet_to_json(wb.Sheets[name], { header: 1, raw: false, defval: '' });
  const h = arr.findIndex(r => r.some(c => String(c).trim().toLowerCase().startsWith(key)));
  if (h < 0) return null;
  const heads = arr[h].map(c => String(c).replace('*', '').trim().toLowerCase());
  return arr.slice(h + 1).map((r, i) => { const o = { _row: h + i + 2 }; heads.forEach((k, j) => { if (k) o[k] = String(r[j] == null ? '' : r[j]).trim(); }); return o; }).filter(o => { const ks = Object.keys(o).filter(k => k !== '_row' && o[k]); if (!ks.length) return false; return !(ks.length === 1 && String(o[ks[0]]).length > 40); });
}
const normDate = (s) => { if (!s) return ''; if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0, 10); const m = s.match(/^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$/); if (m) { const y = m[3].length === 2 ? '20' + m[3] : m[3]; return y + '-' + pad2(m[2]) + '-' + pad2(m[1]); } const d = new Date(s); return isNaN(d) ? '' : isoDate(d); };
function parseWorkbook(wb) {
  const out = { records: [], ints: [], deals: [], errors: [], skippedExample: false };
  const uByEmail = (e) => Store.db.users.find(u => u.email.toLowerCase() === String(e).toLowerCase());
  const codes = {};
  const R = sheetRows(wb, '7', 'kode sumber') || [];
  if (!R.length && !sheetRows(wb, '8', 'kode sumber') && !sheetRows(wb, '9', 'kode sumber')) { out.errors.push('Sheet "7 Data Client & Partner" ga ketemu. Pakai template dari tombol Template kosong.'); return out; }
  R.slice(0, 2000).forEach(o => {
    if (o['kode sumber'] === 'CP-0001' && o['nama'] === 'PT Contoh Sejahtera') { out.skippedExample = true; return; }
    const row = 'Sheet 7 baris ' + o._row + ': ';
    const ex = Store.db.records.find(r => r.code === o['kode sumber']);
    const legacyService = { 'kol/koc':'kol', kol:'kol', community:'community', media:'media', 'digital campaign':'digital-campaign' }[(o['kategori'] || '').toLowerCase()];
    const requestedCat = o['kategori'] || 'Lead';
    const cat = CATS.find(c => c.toLowerCase() === requestedCat.toLowerCase()) || (legacyService ? 'Lead' : '');
    const service = (Store.db.services || []).find(x => [x.name.toLowerCase(), x.id].includes((o['layanan'] || legacyService || '').toLowerCase()));
    const pic = ex ? userById(ex.picId) : me();
    const dept = Store.db.departments.find(d => d.name === (ex ? ex.dept : me().dept));
    if (!o['kode sumber'] || !o['nama']) return out.errors.push(row + 'Kode sumber dan Nama wajib diisi.');
    if (codes[o['kode sumber']]) return out.errors.push(row + 'Kode sumber ' + o['kode sumber'] + ' dobel di file.');
    if (!cat) return out.errors.push(row + 'Kategori "' + (o['kategori'] || '') + '" ga dikenal.');
    if (!pic) return out.errors.push(row + 'Email PIC "' + (o['email pic'] || '') + '" belum terdaftar di CRM.');
    if (!dept) return out.errors.push(row + 'Department "' + (o['department pemilik'] || '') + '" belum ada.');
    if (!can(catEnt(cat), 'C')) return out.errors.push(row + 'Akun ini ga boleh menambah kategori ' + cat + '.');
    if (ex && !canEditRec(ex)) return out.errors.push(row + 'Data ' + ex.name + ' udah ada dan akun ini ga boleh mengubahnya.');
    codes[o['kode sumber']] = true;
    out.records.push({ isNew: !ex, ex, data: { code: o['kode sumber'], name: o['nama'], cat: ex ? ex.cat : 'Lead', serviceId: service ? service.id : (ex ? ex.serviceId : ''), service: service ? service.name : (ex ? ex.service : ''), segment: o['segmen'] || '', picId: pic.id, dept: dept.name, source: optList('sources').includes(o['sumber']) ? o['sumber'] : (o['sumber'] || 'Lainnya'), contact: o['nama kontak utama'] || '', title: o['jabatan kontak'] || '', cEmail: o['email kontak'] || '', phone: o['telepon kontak'] || '', city: o['kota'] || '', rate: num(o['rate card (kol/koc)']), note: o['catatan'] || '' } });
  });
  const recByCode = c => (out.records.find(x => x.data.code === c) || {}).data || Store.db.records.find(r => r.code === c && canSeeRec(r));
  (sheetRows(wb, '8', 'kode sumber') || []).forEach(o => {
    if (o['kode sumber'] === 'INT-0001' && o['kode client & partner'] === 'CP-0001') { out.skippedExample = true; return; }
    const row = 'Sheet 8 baris ' + o._row + ': '; const by = o['email pencatat'] ? uByEmail(o['email pencatat']) : me();
    if (!o['kode sumber'] || !o['kode client & partner'] || !o['hasil pembicaraan']) return out.errors.push(row + 'Kode, kode Client & Partner, dan hasil wajib diisi.');
    if (!recByCode(o['kode client & partner'])) return out.errors.push(row + 'Kode Client & Partner ' + o['kode client & partner'] + ' ga ketemu.');
    if (!by) return out.errors.push(row + 'Email pencatat belum terdaftar.');
    if (!can('interaksi', 'C')) return out.errors.push(row + 'Akun ini ga boleh menambah interaksi.');
    if (Store.db.interactions.some(i => i.code === o['kode sumber'])) return out.errors.push(row + 'Interaksi ' + o['kode sumber'] + ' udah pernah diimport.');
    out.ints.push({ code: o['kode sumber'], recCode: o['kode client & partner'], date: normDate(o['tanggal']) || today(), type: optList('intTypes').find(t => t.toLowerCase() === (o['jenis'] || '').toLowerCase()) || 'Lainnya', result: o['hasil pembicaraan'], next: o['langkah berikutnya'] || '', fuDate: normDate(o['tanggal follow-up']), by: by.id });
  });
  (sheetRows(wb, '9', 'kode sumber') || []).forEach(o => {
    if (o['kode sumber'] === 'DL-0001' && o['kode client & partner'] === 'CP-0001') { out.skippedExample = true; return; }
    const row = 'Sheet 9 baris ' + o._row + ': ';
    const rec = recByCode(o['kode client & partner']);
    const owner = o['email pic'] ? uByEmail(o['email pic']) : rec && userById(rec.picId);
    const line = Store.db.lines.find(l => !l.legacy && l.dept === (rec || {}).dept && (!o['lini bisnis'] || l.name.toLowerCase() === o['lini bisnis'].toLowerCase()));
    const status = DEAL_STATUS.find(s => s.toLowerCase() === (o['status'] || '').toLowerCase());
    if (!o['kode sumber'] || !o['kode client & partner']) return out.errors.push(row + 'Kode wajib diisi.');
    if (!recByCode(o['kode client & partner'])) return out.errors.push(row + 'Kode Client & Partner ' + o['kode client & partner'] + ' ga ketemu.');
    if (!line) return out.errors.push(row + 'Lini bisnis "' + (o['lini bisnis'] || '') + '" belum ada.');
    if (!status) return out.errors.push(row + 'Status harus Open, Won, Lost, atau On Hold.');
    if (!owner || !owner.active || owner.dept !== rec.dept) return out.errors.push(row + 'PIC harus aktif dan berasal dari divisi pemilik relasi.');
    if (!can('deal', 'C')) return out.errors.push(row + 'Akun ini ga boleh menambah deal.');
    let stage = o['stage'] ? line.stages.find(s => s.name.toLowerCase() === o['stage'].toLowerCase()) : line.stages[0];
    if (status === 'Won' || status === 'Lost') stage = line.stages[line.stages.length - 1];
    if (!stage) return out.errors.push(row + 'Stage "' + (o['stage'] || '') + '" ga ada di ' + line.name + '.');
    if (status === 'Won' && (!num(o['nilai deal (rp)']) || !normDate(o['tanggal closing']))) return out.errors.push(row + 'Deal Won wajib ada nilai deal dan tanggal closing.');
    if ((status === 'Lost' || status === 'On Hold') && !o['alasan lost / on hold']) return out.errors.push(row + 'Deal ' + status + ' wajib ada alasan.');
    if (Store.db.deals.some(d => d.code === o['kode sumber'])) return out.errors.push(row + 'Deal ' + o['kode sumber'] + ' udah pernah diimport.');
    out.deals.push({ code: o['kode sumber'], recCode: o['kode client & partner'], lineId: line.id, stageId: stage.id, status, title: o['layanan'] || ('Deal ' + o['kode sumber']), service: o['layanan'] || '', offer: num(o['nilai penawaran (rp)']), value: num(o['nilai deal (rp)']), target: normDate(o['target closing']), closedAt: normDate(o['tanggal closing']), reason: o['alasan lost / on hold'] || '', ownerId: owner.id });
  });
  return out;
}
function doImport() {
  const m = App.modal; const p = m.parsed; let n = 0;
  p.records.forEach(x => { if (x.ex) Object.assign(x.ex, x.data, { updatedAt: today() }); else Store.db.records.push(Object.assign({ id: uid('r'), createdAt: today(), updatedAt: today(), createdBy: me().id, createdByName: me().name }, x.data)); n++; });
  const idOf = (code) => (Store.db.records.find(r => r.code === code) || {}).id;
  p.ints.forEach(x => { Store.db.interactions.push({ id: uid('i'), code: x.code, recordId: idOf(x.recCode), type: x.type, date: x.date, result: x.result, next: x.next, fuDate: x.fuDate, done: false, by: x.by }); n++; });
  p.deals.forEach(x => { Store.db.deals.push({ id: uid('dl'), code: x.code, title: x.title, recordId: idOf(x.recCode), lineId: x.lineId, stageId: x.stageId, status: x.status, offer: x.offer, value: x.status === 'Won' ? x.value : '', ownerId: x.ownerId, target: x.target, closedAt: x.closedAt, reason: x.reason, service: x.service, createdAt: today() }); n++; });
  audit('Import', 'Client & Partner', m.file + ': ' + p.records.length + ' data, ' + p.ints.length + ' interaksi, ' + p.deals.length + ' deal' + (p.errors.length ? ', ' + p.errors.length + ' baris dilewati' : ''));
  App.modal = null; commit(true); toast(n + ' baris masuk!', p.errors.length ? p.errors.length + ' baris dilewati, cek audit log.' : 'Semua baris berhasil.');
}
function exportXlsx() {
  if (typeof XLSX === 'undefined') { toast('Library Excel ga kebaca', 'Cek file assets/xlsx.full.min.js.', 'bad'); return; }
  const recs = (App.route === 'cp' ? cpFiltered().list.map(x => x.r) : Store.db.records.filter(canSeeRec));
  const ids = new Set(recs.map(r => r.id)); const seeRate = canSeeRate();
  const wb = XLSX.utils.book_new();
  const s7 = [['Kode sumber *', 'Nama *', 'Kategori *', 'Segmen', 'Email PIC *', 'Department pemilik *', 'Sumber', 'Nama kontak utama', 'Jabatan kontak', 'Email kontak', 'Telepon kontak', 'Kota', 'Rate card (KOL/KOC)', 'Catatan', 'Layanan']].concat(recs.map(r => [r.code, r.name, r.cat, r.segment, (userById(r.picId) || {}).email || '', r.dept, r.source, r.contact, r.title, r.cEmail, r.phone, r.city, seeRate ? r.rate : '', r.note, serviceName(r.serviceId)]));
  XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(s7), '7 Data Client & Partner');
  if (can('interaksi', 'R')) { const s8 = [['Kode sumber *', 'Kode Client & Partner *', 'Tanggal *', 'Jenis *', 'Hasil pembicaraan *', 'Langkah berikutnya', 'Tanggal follow-up', 'Email pencatat *', 'Catatan']].concat(Store.db.interactions.filter(i => ids.has(i.recordId)).map(i => [i.code, (recById(i.recordId) || {}).code, i.date, i.type, i.result, i.next, i.fuDate, (userById(i.by) || {}).email || '', i.done ? 'Follow-up selesai' : ''])); XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(s8), '8 Data Interaksi'); }
  if (can('deal', 'R')) { const s9 = [['Kode sumber *', 'Kode Client & Partner *', 'Lini bisnis *', 'Stage *', 'Status *', 'Layanan', 'Nilai penawaran (Rp)', 'Nilai deal (Rp)', 'Target closing', 'Tanggal closing', 'Alasan Lost / On Hold', 'Email PIC *', 'Catatan']].concat(Store.db.deals.filter(d => ids.has(d.recordId)).map(d => [d.code, (recById(d.recordId) || {}).code, (lineById(d.lineId) || {}).name, (stageOf(d) || {}).name, d.status, d.service || d.title, canSeeValue(d) ? d.offer : '', canSeeValue(d) ? d.value : '', d.target, d.closedAt, d.reason, (userById(d.ownerId) || {}).email || '', ''])); XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(s9), '9 Data Deal'); }
  XLSX.writeFile(wb, 'Export_CRM_OPTIMA_' + today() + '.xlsx');
  audit('Export', 'Client & Partner', recs.length + ' data diexport'); Store.save(); render(); toast('Export jadi!', recs.length + ' data diunduh.');
}

/* ---------- event ---------- */
document.addEventListener('click', (e) => {
  const el = e.target.closest('[data-a]');
  if (App.pop && !e.target.closest('.pop') && !e.target.closest('[data-pop]')) { App.pop = null; if (!el) { render(); return; } }
  if (!el) return;
  const fn = Actions[el.dataset.a]; if (!fn) return;
  if (el.tagName === 'A' && el.getAttribute('href') && el.getAttribute('href').startsWith('#/')) return;
  if (el.closest('.pop') && el.dataset.a !== 'pop') App.pop = null;
  fn(el, e);
});
document.addEventListener('input', (e) => { const el = e.target.closest('[data-in]'); if (el && Inputs[el.dataset.in]) Inputs[el.dataset.in](el.value, el); });
document.addEventListener('change', (e) => { const el = e.target.closest('[data-ch]'); if (el && Changes[el.dataset.ch]) Changes[el.dataset.ch](el); });
document.addEventListener('submit', (e) => { const f = e.target.closest('[data-form]'); if (!f) return; e.preventDefault(); const fn = Forms[f.dataset.form]; if (fn) fn(fd(f), f); });
document.addEventListener('keydown', (e) => {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { if (me()) { e.preventDefault(); Actions.pal(); } return; }
  if (e.key === 'Escape') { if (App.pal) App.pal = null; else if (App.modal) App.modal = null; else if (App.drawer) App.drawer = null; else if (App.pop) App.pop = null; else return; render(); return; }
  if (App.pal && ['ArrowDown', 'ArrowUp', 'Enter'].includes(e.key)) { const res = App.pal.res || []; if (!res.length) return; e.preventDefault(); if (e.key === 'Enter') { const x = res[Math.min(App.pal.i || 0, res.length - 1)]; Actions[x.a]({ dataset: { v: x.v } }, e); return; } App.pal.i = Math.max(0, Math.min(res.length - 1, (App.pal.i || 0) + (e.key === 'ArrowDown' ? 1 : -1))); render(); return; }
  if (e.target && e.target.id === 'int-q' && ['ArrowDown', 'ArrowUp', 'Enter'].includes(e.key)) {
    const list = document.getElementById('int-list'); const opts = list ? [...list.querySelectorAll('[role=option]')] : [];
    if (!opts.length) return; e.preventDefault(); list.style.display = 'flex';
    let i = opts.findIndex(o => o.getAttribute('aria-selected') === 'true'); if (i < 0) i = 0;
    if (e.key === 'Enter') { opts[i].click(); return; }
    i = Math.max(0, Math.min(opts.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1))); opts.forEach((o, j) => o.setAttribute('aria-selected', String(j === i))); opts[i].scrollIntoView({ block: 'nearest' }); return;
  }
  if (e.key === 'Enter' && e.target.matches('tr[data-a]')) { e.preventDefault(); e.target.click(); }
});
let dragId = null;
document.addEventListener('dragstart', (e) => { const el = e.target.closest('[data-drag]'); if (!el) return; dragId = el.dataset.drag; e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', dragId); } catch (x) { /* */ } el.classList.add('drag'); });
document.addEventListener('dragend', (e) => { const el = e.target.closest && e.target.closest('[data-drag]'); if (el) el.classList.remove('drag'); document.querySelectorAll('.col.over').forEach(c => c.classList.remove('over')); });
document.addEventListener('dragover', (e) => { const c = e.target.closest('[data-drop-stage]'); const z = e.target.closest('[data-drop]'); if (c && dragId) { e.preventDefault(); document.querySelectorAll('.col.over').forEach(x => x !== c && x.classList.remove('over')); c.classList.add('over'); } else if (z) { e.preventDefault(); } });
document.addEventListener('drop', (e) => {
  const z = e.target.closest('[data-drop]'); if (z && e.dataTransfer.files.length) { e.preventDefault(); readImport(e.dataTransfer.files[0]); return; }
  const c = e.target.closest('[data-drop-stage]'); if (!c || !dragId) return; e.preventDefault(); const id = dragId; dragId = null; moveDeal(id, c.dataset.dropStage);
});

document.addEventListener('focusin', (e) => { if (e.target.id === 'int-q') { const l = document.getElementById('int-list'); const h = document.querySelector('[data-form=int] [name=recordId]'); if (l && !(h && h.value)) { l.style.display = 'flex'; e.target.setAttribute('aria-expanded', 'true'); } } });
document.addEventListener('click', (e) => { const l = document.getElementById('int-list'); if (l && !e.target.closest('.combo')) l.style.display = 'none'; }, true);

