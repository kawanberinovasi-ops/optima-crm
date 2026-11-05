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

/** Pemberitahuan tentang data atau deal yang sudah tidak boleh dilihat tidak ditampilkan. */
function workflowNotificationVisible(n) {
  const [kind, id] = (n.link || '').split(':');
  if (kind === 'rec') return canSeeRec(recById(id));
  if (kind === 'deal') return canSeeDeal(Store.db.deals.find(d => d.id === id));
  return true;
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
    '<p class="small muted" style="margin:0">Bisa ke divisi mana pun, termasuk ke atau dari Customer Service. Data, kontak, interaksi, dan deal mengikuti divisi penerima; deal pindah ke pipeline yang dipakai PIC baru dan yang masih berjalan dipegang PIC baru. PIC lama tidak lagi bisa membuka data ini, kecuali Super Admin. Pengisi data tetap tercatat.</p>' +
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
  // Deal pindah ke pipeline PIC baru kalau PIC baru tidak memakai pipeline lamanya.
  Store.db.deals.filter(d => d.recordId === r.id && !linesFor(to).some(l => l.id === d.lineId)).forEach(d => moveDealToLine(d, lineForUser(to), note));
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
AdminTabs.services = (adm) => '<div class="sec-h"><div><span class="lbl">Layanan dan penanggung jawab</span><h2 class="disp h2">Layanan terpisah dari status.</h2></div>' + (adm ? '<button type="button" class="btn btn-sm" data-a="svcnew">Layanan baru</button>' : '') + '</div>' +
  '<p class="small muted" style="margin:0">Status relasi hanya Lead atau Client. Pemetaan layanan adalah saran divisi penerima, bukan jalur wajib: setiap divisi tetap bisa menerima Lead langsung dan melakukan serah terima.</p><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Layanan</th><th>Divisi layanan</th><th>Aksi</th></tr></thead><tbody>' +
  (Store.db.services || []).map(x => '<tr><td><strong>' + esc(x.name) + '</strong></td><td>' + (adm ? '<select class="inp" data-ch="svcdept" data-v="' + esc(x.id) + '" aria-label="Divisi layanan ' + esc(x.name) + '">' + Store.db.departments.map(d => opt(d.name, x.dept)).join('') + '</select>' : esc(x.dept)) + '</td><td>' + (adm ? '<button type="button" class="btn btn-s btn-sm" style="color:var(--bad)" data-a="workflow-delete-service" data-v="' + x.id + '" aria-label="Hapus layanan ' + esc(x.name) + '">Hapus</button>' : '') + '</td></tr>').join('') + '</tbody></table></div>';
Actions.svcnew = () => { if (!canAdmin()) return; App.modal = { kind: 'svcnew' }; render(); };
Modals.svcnew = (m) => { const v = m.val || {}; return modalShell('Layanan baru', 'Layanan & Divisi', '<div class="form-grid"><label class="fld"><span>Nama layanan *</span><input class="inp" name="name" value="' + esc(v.name || '') + '" required' + inv(m, 'name') + '>' + fieldErr(m, 'name') + '</label><label class="fld"><span>Divisi layanan</span><select class="inp" name="dept">' + Store.db.departments.map(d => opt(d.name, v.dept)).join('') + '</select></label></div>', '<button type="button" class="btn btn-s" data-a="close">Batal</button><button type="submit" class="btn">' + ic('check', 'ic-s') + 'Simpan</button>', false, 'svcnew'); };
Forms.svcnew = (o) => { if (!canAdmin()) return; const m = App.modal; m.val = o; if (!o.name || (Store.db.services || []).some(x => x.name.toLowerCase() === o.name.toLowerCase())) { m.err = { name: 'Isi nama layanan yang belum ada.' }; m.still = true; render(); return; } Store.db.services = Store.db.services || []; Store.db.services.push({ id: uid('s'), name: o.name, dept: o.dept, active: true }); audit('Tambah', 'Layanan', o.name + ' ditangani ' + o.dept); App.modal = null; commit(); };
Changes['record-service'] = (el) => { App.modal.val = Object.assign({}, App.modal.val, fd(el.closest('form'))); App.modal.still = true; render(); };
Changes.svcdept = (el) => { const x = (Store.db.services || []).find(s => s.id === el.dataset.v); if (!x) return; audit('Ubah', 'Layanan', x.name + ': ditangani ' + (el.value || 'belum ditentukan')); x.dept = el.value; commit(); };

/* ---------- Akses & Audit: tab Pipeline (revisi klien 5 Nov 2026) ----------
   Super Admin mengatur divisi mana yang memakai pipeline mana, ditambah pengecualian per pengguna (tambah atau cabut). */
AdminTabs.pipelines = (adm) => {
  const lines = (Store.db.lines || []).filter(l => !l.archived);
  const ov = Store.db.lineUsers || [];
  const userOpts = Store.db.users.filter(u => u.active && u.role !== 'Super Admin');
  return '<div class="sec-h"><div><span class="lbl">Pipeline per jenis pekerjaan</span><h2 class="disp h2">Siapa pakai pipeline apa</h2></div>' + (adm ? '<button type="button" class="btn btn-sm" data-a="newline">' + ic('plus', 'ic-s') + 'Pipeline baru</button>' : '') + '</div>' +
    '<p class="small muted" style="margin:0">Pengguna memakai pipeline divisinya. Super Admin bisa menambah pipeline untuk orang tertentu, atau mencabutnya walaupun divisinya memakai pipeline itu. Pengguna tanpa pipeline tidak melihat menu Pipeline. Stage diatur Super Admin dari halaman Pipeline.</p>' +
    '<div style="display:flex;flex-direction:column;gap:14px">' + lines.map(l => {
      const exc = ov.filter(x => x.lineId === l.id);
      return '<div class="card pad" style="display:flex;flex-direction:column;gap:12px;padding:20px"><div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><strong style="font-size:17px">' + esc(l.name) + '</strong><span class="small muted">' + l.stages.map(s => esc(s.name)).join(' → ') + '</span></div>' +
        '<div><span class="lbl">Divisi yang memakai</span><div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">' + Store.db.departments.map(d => {
          const on = (l.depts || []).includes(d.name);
          return adm ? '<label class="chip ' + (on ? 'c-info' : 'c-n') + '" style="cursor:pointer;min-height:36px"><input type="checkbox" data-ch="linedept" data-v="' + l.id + '" data-d="' + esc(d.name) + '"' + (on ? ' checked' : '') + ' style="margin-right:6px">' + esc(d.name) + '</label>' : (on ? '<span class="chip c-info">' + esc(d.name) + '</span>' : '');
        }).join('') + '</div></div>' +
        '<div><span class="lbl">Pengecualian per pengguna</span><div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">' + (exc.length ? exc.map(x => '<span class="chip ' + (x.allowed ? 'c-ok' : 'c-bad') + '" style="padding-right:4px">' + (x.allowed ? 'Ditambah: ' : 'Dicabut: ') + esc(userName(x.userId)) + (adm ? '<button type="button" class="btn-ghost" style="min-height:30px;padding:0 6px" data-a="lineuserdel" data-v="' + l.id + '" data-u="' + x.userId + '" aria-label="Hapus pengecualian ' + esc(userName(x.userId)) + '">' + ic('x', 'ic-s') + '</button>' : '') + '</span>').join('') : '<span class="small muted">Belum ada.</span>') + '</div>' +
        (adm ? '<form data-form="lineuser" data-v="' + l.id + '" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px"><input type="hidden" name="lineId" value="' + l.id + '"><label style="flex:1 1 200px"><span class="sr">Pengguna</span><select class="inp" name="userId" style="min-height:44px">' + opt('', '', 'Pilih pengguna') + userOpts.map(u => opt(u.id, '', u.name + ' · ' + u.dept)).join('') + '</select></label><label><span class="sr">Aksi</span><select class="inp" name="allowed" style="min-height:44px;width:auto">' + opt('1', '1', 'Tambahkan') + opt('0', '1', 'Cabut') + '</select></label><button type="submit" class="btn btn-s btn-sm">Simpan</button></form>' : '') + '</div></div>';
    }).join('') + '</div>';
};
Changes.linedept = (el) => {
  if (!canAdmin()) return;
  const l = lineById(el.dataset.v); if (!l) return;
  l.depts = el.checked ? (l.depts || []).concat([el.dataset.d]) : (l.depts || []).filter(x => x !== el.dataset.d);
  audit('Ubah', 'Pipeline', l.name + ' dipakai: ' + (l.depts.join(', ') || 'tidak ada divisi')); commit();
};
const setLineUser = (lineId, userId, allowed) => {
  Store.db.lineUsers = (Store.db.lineUsers || []).filter(x => !(x.lineId === lineId && x.userId === userId));
  if (allowed !== null) Store.db.lineUsers.push({ lineId, userId, allowed });
  const l = lineById(lineId);
  audit('Ubah', 'Pipeline', l.name + ' untuk ' + userName(userId) + ': ' + (allowed === null ? 'kembali mengikuti divisinya' : allowed ? 'ditambahkan' : 'dicabut'));
  commit();
};
Forms.lineuser = (o) => { if (!canAdmin() || !o.userId) return; setLineUser(o.lineId, o.userId, o.allowed === '1'); };
Actions.lineuserdel = (el) => { if (!canAdmin()) return; setLineUser(el.dataset.v, el.dataset.u, null); };
