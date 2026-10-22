/* OPTIMA CRM · alur Lead/Client (22 Okt 2026, mengikuti pratinjau klien): Pindah PIC / divisi, Lead jadi Client lewat Won,
   layanan dan pemetaannya ke divisi, satu pipeline per divisi, dan department yang melihat lintas divisi.
   Di mode backend (assets/api.js), simpan-simpannya dikirim ke server; fungsi di sini dipakai di mode lokal. */
'use strict';

/** Lead jadi Client otomatis setelah deal Won. Client tetap Client walaupun deal lain Lost atau On Hold. */
function promoteToClient(d) {
  const r = recById(d.recordId); if (!r || r.cat !== 'Lead') return;
  trackChange('record', r.id, 'Status', 'Lead', 'Client', 'Deal ' + d.title + ' Won');
  r.cat = 'Client'; r.convertedAt = today();
  audit('Ubah', 'Client & Partner', r.name + ' jadi Client karena deal ' + d.title + ' Won');
}

/** Riwayat serah terima di drawer detail: dari siapa dan divisi mana, ke siapa, oleh siapa, kapan, dan alasannya. */
function handoverList(r) {
  const list = (r.handovers || []).slice().reverse();
  if (!list.length) return '';
  return '<section style="display:flex;flex-direction:column;gap:10px"><span class="lbl">Riwayat serah terima (' + list.length + ')</span><div class="tl">' +
    list.map(h => '<div class="tl-i"><span class="tile">' + ic('swap') + '</span><div style="flex:1;display:flex;flex-direction:column;gap:2px;min-width:0"><strong class="small">' +
      esc(userName(h.from)) + ' · ' + esc(h.fromDept || '-') + ' → ' + esc(userName(h.to)) + ' · ' + esc(h.toDept || '-') + '</strong><span class="small">' + esc(h.reason) + '</span>' +
      '<span class="small muted">' + fmtTime(h.at) + ' · diserahkan ' + esc(userName(h.by)) + (h.dealsMoved ? ' · ' + h.dealsMoved + ' deal berjalan ikut pindah' : '') + '</span></div></div>').join('') +
    '</div></section>';
}

/**
 * Deal ikut pipeline divisi baru relasinya. Stage dicocokkan lewat nama, kalau tidak ada lewat urutan;
 * deal Won tetap di Closing. Perpindahan dicatat di riwayat deal.
 */
function moveDealToLine(d, line, note) {
  if (!line || d.lineId === line.id) return;
  const old = lineById(d.lineId); const from = stageOf(d) || {};
  const open = line.stages.slice(0, -1); const closing = line.stages[line.stages.length - 1];
  const oldIdx = old ? old.stages.findIndex(s => s.id === d.stageId) : 0;
  const isClosing = d.status === 'Won' || (old && oldIdx === old.stages.length - 1);
  const to = isClosing ? closing : (open.find(s => s.name.toLowerCase() === String(from.name || '').toLowerCase()) || open[Math.min(Math.max(oldIdx, 0), open.length - 1)]);
  d.lineId = line.id; d.stageId = to.id;
  dealLog(d, from.name, to.name, d.status, note + ' (pipeline ' + ((old || {}).name || '-') + ' ke ' + line.name + ')');
}

const svcChip = (s) => s ? '<span class="chip c-y">' + esc(s) + '</span>' : '';

/* ---------- Pindah PIC / divisi ---------- */
const handoverPics = divisionUsers;
Modals.handover = (m) => {
  const r = recById(m.id); const svc = serviceList().find(x => x.name === r.service);
  // Divisi tujuan bawaan: divisi yang menangani layanannya, kalau belum ada divisi sekarang.
  const v = Object.assign({ dept: (svc && svc.dept) || r.dept, picId: '', reason: '' }, m.val || {});
  const pics = v.dept ? handoverPics(v.dept) : [];
  return modalShell('Pindah PIC / divisi', esc(r.name),
    '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center" class="small">' + catChip(r.cat) + svcChip(r.service) + '<span>Sekarang: <b>' + esc(userName(r.picId)) + '</b> · ' + esc(r.dept) + '</span></div>' +
    '<p class="small muted" style="margin:0">Bisa ke divisi mana pun, termasuk ke atau dari Customer Service. Data, kontak, interaksi, dan deal mengikuti divisi penerima; deal pindah ke pipeline divisi itu dan yang masih berjalan dipegang PIC baru. Divisi asal tidak lagi bisa membuka data ini, kecuali Executive. Pengisi data tetap tercatat.</p>' +
    '<div class="form-grid">' +
    '<label class="fld"><span>Divisi tujuan *</span><select class="inp" name="dept" data-ch="hodept"' + inv(m, 'deptId') + '>' + opt('', v.dept, 'Pilih divisi') + Store.db.departments.map(d => opt(d.name, v.dept, d.name + (svc && svc.dept === d.name ? ' · menangani ' + svc.name : ''))).join('') + '</select>' + fieldErr(m, 'deptId') + '</label>' +
    '<label class="fld"><span>PIC penerima *</span><select class="inp" name="picId"' + inv(m, 'picId') + (v.dept ? '' : ' disabled') + '>' + opt('', v.picId, v.dept ? (pics.length ? 'Pilih orang' : 'Belum ada pengguna aktif') : 'Pilih divisi dulu') + pics.map(u => opt(u.id, v.picId, u.name + ' · ' + u.role)).join('') + '</select>' + fieldErr(m, 'picId') + '</label>' +
    '<label class="fld" style="grid-column:1/-1"><span>Alasan serah terima *</span><input class="inp" name="reason" value="' + esc(v.reason) + '"' + inv(m, 'reason') + ' placeholder="Contoh: butuh paket KOL, dipegang Digital Campaign">' + fieldErr(m, 'reason') + '</label></div>',
    '<button type="button" class="btn btn-s" data-a="close">Batal</button><button type="submit" class="btn">' + ic('swap', 'ic-s') + 'Serahkan</button>', false, 'handover');
};
Actions.handover = (el) => { App.modal = { kind: 'handover', id: el.dataset.v }; render(); };
Changes.hodept = (el) => { const f = document.querySelector('[data-form=handover]'); App.modal.val = Object.assign(f ? fd(f) : {}, { dept: el.value, picId: '' }); App.modal.err = {}; App.modal.still = true; render(); };
const handoverErr = (m, o) => { const err = {}; if (!o.dept) err.deptId = 'Pilih divisi tujuan.'; if (!o.picId) err.picId = 'Pilih PIC penerima.'; if (!o.reason) err.reason = 'Alasan wajib diisi.'; const r = recById(m.id); if (o.picId && o.picId === r.picId && o.dept === r.dept) err.picId = 'Pilih PIC atau divisi yang berbeda dari sekarang.'; if (Object.keys(err).length) { m.err = err; m.val = o; m.still = true; render(); return true; } return false; };
Forms.handover = (o) => {
  const m = App.modal; if (handoverErr(m, o)) return;
  const r = recById(m.id); const to = userById(o.picId); const from = { pic: r.picId, dept: r.dept };
  if (!r || !canHandover(r)) { toast('Tidak ada akses', 'Data ini berada di luar divisi atau kewenanganmu.', 'bad'); return; }
  if (!to || !divisionUsers(o.dept).includes(to)) { m.err = { picId: 'Pilih pengguna aktif dari divisi tujuan.' }; m.val = o; m.still = true; render(); return; }
  const note = 'Pindah ke ' + to.name + ' (' + to.dept + ')';
  const line = lineForDivision(to.dept);
  Store.db.deals.filter(d => d.recordId === r.id).forEach(d => moveDealToLine(d, line, note));
  const running = Store.db.deals.filter(d => d.recordId === r.id && (d.status === 'Open' || d.status === 'On Hold') && d.ownerId !== to.id);
  running.forEach(d => { d.ownerId = to.id; dealLog(d, (stageOf(d) || {}).name, (stageOf(d) || {}).name, d.status, note); });
  trackChange('record', r.id, 'PIC', userName(from.pic), to.name, o.reason); trackChange('record', r.id, 'Divisi pemilik', from.dept, to.dept, o.reason);
  r.picId = to.id; r.dept = to.dept;
  r.handovers = (r.handovers || []).concat([{ at: new Date().toISOString(), from: from.pic, fromDept: from.dept, to: to.id, toDept: to.dept, by: me().id, reason: o.reason, dealsMoved: running.length }]);
  audit('Ubah', 'Client & Partner', 'Penanganan ' + r.name + ' diserahkan: ' + from.dept + ' ke ' + to.dept + ' (' + o.reason + ')');
  notify(to.id, me().name + ' menyerahkan ' + r.name + ' ke kamu', 'rec:' + r.id);
  App.modal = null; if (!canSeeRec(r)) App.drawer = null; commit();
  toast('Penanganan diserahkan', r.name + ' → ' + to.dept + ' · ' + to.name, 'info');
};

/* ---------- Akses & Audit: tab Layanan & Divisi, dan department lintas divisi ---------- */
AdminTabs.services = (adm) => {
  const svcs = (Store.db.services || serviceList());
  return '<div class="sec-h"><div><span class="lbl">Layanan dan penanggung jawab</span><h2 class="disp h2">Layanan terpisah dari status.</h2></div></div>' +
    '<p class="small muted" style="margin:0">Status relasi hanya Lead atau Client. Pemetaan layanan adalah saran divisi penerima, bukan jalur wajib: setiap divisi tetap bisa menerima Lead langsung dan memindahkan PIC / divisi. Mengubah pemetaan tidak memindahkan data yang sudah ada.</p>' +
    '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Layanan</th><th>Divisi layanan</th>' + (adm ? '<th><span class="sr">Aksi</span></th>' : '') + '</tr></thead><tbody>' +
    svcs.map(x => '<tr><td><strong>' + esc(x.name) + '</strong>' + (x.active === false ? ' <span class="chip c-n">nonaktif</span>' : '') + '</td><td>' +
      (adm ? '<label><span class="sr">Divisi layanan ' + esc(x.name) + '</span><select class="inp" data-ch="svcdept" data-v="' + x.id + '" style="min-height:44px">' + opt('', x.dept, 'Belum ditentukan') + Store.db.departments.map(d => opt(d.name, x.dept)).join('') + '</select></label>' : esc(x.dept || 'Belum ditentukan')) + '</td>' +
      (adm ? '<td style="text-align:right"><button type="button" class="btn btn-s btn-sm" data-a="svcactive" data-v="' + x.id + '">' + (x.active === false ? 'Aktifkan' : 'Nonaktifkan') + '</button></td>' : '') + '</tr>').join('') + '</tbody></table></div>' +
    (adm ? '<form data-form="svcadd" style="display:flex;flex-wrap:wrap;gap:8px"><label style="flex:1 1 180px"><span class="sr">Nama layanan baru</span><input class="inp" name="name" placeholder="Layanan baru" style="min-height:44px"></label><button type="submit" class="btn btn-sm">' + ic('plus', 'ic-s') + 'Layanan baru</button></form>' : '');
};
Changes['record-service'] = (el) => { App.modal.val = Object.assign({}, App.modal.val, fd(el.closest('form'))); App.modal.still = true; render(); };
Changes.svcdept = (el) => { const x = (Store.db.services || []).find(s => s.id === el.dataset.v); if (!x) return; audit('Ubah', 'Layanan', x.name + ': ditangani ' + (el.value || 'belum ditentukan')); x.dept = el.value; commit(); };
Actions.svcactive = (el) => { const x = (Store.db.services || []).find(s => s.id === el.dataset.v); if (!x) return; x.active = x.active === false; audit('Ubah', 'Layanan', x.name + (x.active ? ' diaktifkan' : ' dinonaktifkan')); commit(); };
Forms.svcadd = (o) => { if (!o.name) return; Store.db.services = Store.db.services || []; if (Store.db.services.some(x => x.name.toLowerCase() === o.name.toLowerCase())) { toast('Udah ada', o.name + ' udah ada di daftar.', 'bad'); return; } Store.db.services.push({ id: uid('s'), name: o.name, dept: '', active: true }); audit('Tambah', 'Layanan', o.name); commit(); };
Actions.deptall = (el) => { const d = Store.db.departments.find(x => x.id === el.dataset.v); if (!d) return; d.seesAll = !d.seesAll; audit('Ubah', 'Department', d.name + (d.seesAll ? ': bisa melihat data lintas divisi' : ': hanya melihat data divisinya')); commit(); };
