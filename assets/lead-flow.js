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

/** Halaman Partner memakai tampilan yang sama dengan Client, hanya isinya Partner. */
Pages.partner = () => Pages.cp();

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
/* Layanan milik setiap divisi (revisi klien 5 Nov 2026): divisi menambah, mengganti nama, dan menghapus layanannya sendiri.
   Super Admin melihat dan mengatur semua divisi, termasuk memindahkan layanan ke divisi lain. */
AdminTabs.services = (adm) => {
  const depts = adm ? Store.db.departments.map(d => d.name) : [me().dept];
  const all = Store.db.services || [];
  return '<div class="sec-h"><div><span class="lbl">Layanan</span><h2 class="disp h2">' + (adm ? 'Layanan setiap divisi' : 'Layanan ' + esc(me().dept)) + '</h2></div></div>' +
    '<p class="small muted" style="margin:0">Setiap divisi mengisi sendiri layanan yang mereka punya. Layanan dipakai di form Lead dan deal; mengganti nama ikut mengubah data yang memakainya, menghapus masuk Data terhapus (ditolak kalau masih dipakai).</p>' +
    '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">' + depts.map(dept => {
      const list = all.filter(x => x.dept === dept); const can = canManageServices(dept);
      return '<div class="card pad" style="display:flex;flex-direction:column;gap:12px;padding:20px"><strong>' + esc(dept) + '</strong>' +
        (list.length ? '<div style="display:flex;flex-direction:column;gap:8px">' + list.map(x => '<div style="display:flex;flex-direction:column;gap:8px;padding:12px 14px;border-radius:16px;background:var(--bg)">' +
          '<div style="display:flex;align-items:center;gap:8px"><strong style="flex:1;min-width:0;overflow-wrap:anywhere">' + esc(x.name) + '</strong>' +
          (can ? '<button type="button" class="icb" data-a="svcedit" data-v="' + x.id + '" aria-label="Ganti nama ' + esc(x.name) + '">' + ic('edit', 'ic-s') + '</button><button type="button" class="icb" style="color:var(--bad)" data-a="workflow-delete-service" data-v="' + x.id + '" aria-label="Hapus layanan ' + esc(x.name) + '">' + ic('trash', 'ic-s') + '</button>' : '') + '</div>' +
          (adm ? '<label style="display:flex;align-items:center;gap:8px" class="small muted"><span style="white-space:nowrap">Pindah ke divisi</span><select class="inp" data-ch="svcdept" data-v="' + esc(x.id) + '" style="min-height:40px;flex:1;min-width:0">' + Store.db.departments.map(d => opt(d.name, x.dept)).join('') + '</select></label>' : '') +
          '</div>').join('') + '</div>'
          : '<span class="small muted">Belum ada layanan.</span>') +
        (can ? '<form data-form="svcadd" style="display:flex;gap:8px"><input type="hidden" name="dept" value="' + esc(dept) + '"><label style="flex:1"><span class="sr">Layanan baru ' + esc(dept) + '</span><input class="inp" name="name" placeholder="Layanan baru" style="min-height:44px"></label><button type="submit" class="btn btn-sm">Tambah</button></form>' : '') + '</div>';
    }).join('') + '</div>';
};
Forms.svcadd = (o) => {
  if (!o.name || !canManageServices(o.dept)) return;
  Store.db.services = Store.db.services || [];
  if (Store.db.services.some(x => x.name.toLowerCase() === o.name.toLowerCase())) { toast('Udah ada', o.name + ' udah ada di daftar layanan.', 'bad'); return; }
  Store.db.services.push({ id: uid('s'), name: o.name, dept: o.dept, active: true }); audit('Tambah', 'Layanan', o.name + ' (' + o.dept + ')'); commit();
};
Actions.svcedit = (el) => { const x = (Store.db.services || []).find(s => s.id === el.dataset.v); if (!x || !canManageServices(x.dept)) return; App.modal = { kind: 'svcedit', id: x.id, val: { name: x.name } }; render(); };
Modals.svcedit = (m) => modalShell('Ganti nama layanan', 'Layanan', '<div class="form-grid"><label class="fld" style="grid-column:1/-1"><span>Nama layanan *</span><input class="inp" name="name" value="' + esc((m.val || {}).name || '') + '"' + inv(m, 'name') + ' autofocus>' + fieldErr(m, 'name') + '</label></div>', '<button type="button" class="btn btn-s" data-a="close">Batal</button><button type="submit" class="btn">' + ic('check', 'ic-s') + 'Simpan</button>', false, 'svcedit');
Forms.svcedit = (o) => {
  const m = App.modal; const x = (Store.db.services || []).find(s => s.id === m.id); if (!x || !canManageServices(x.dept)) return;
  if (!o.name || Store.db.services.some(s => s.id !== x.id && s.name.toLowerCase() === o.name.toLowerCase())) { m.err = { name: 'Isi nama layanan yang belum dipakai.' }; m.val = o; m.still = true; render(); return; }
  const old = x.name; x.name = o.name;
  Store.db.records.forEach(r => { if (r.service === old) r.service = o.name; });
  Store.db.deals.forEach(d => { if (d.service === old) d.service = o.name; });
  audit('Ubah', 'Layanan', old + ' jadi ' + o.name); App.modal = null; commit();
};
Changes['record-service'] = (el) => { App.modal.val = Object.assign({}, App.modal.val, fd(el.closest('form'))); App.modal.still = true; render(); };
Changes.svcdept = (el) => { const x = (Store.db.services || []).find(s => s.id === el.dataset.v); if (!x) return; audit('Ubah', 'Layanan', x.name + ': ditangani ' + (el.value || 'belum ditentukan')); x.dept = el.value; commit(); };

/* ---------- Akses & Audit: tab Pipeline (revisi klien 5 Nov 2026) ----------
   Super Admin mengatur divisi mana yang memakai pipeline mana, ditambah pengecualian per pengguna (tambah atau cabut). */
AdminTabs.pipelines = (adm) => {
  // Diatur per divisi (revisi klien 10 Nov 2026): setiap kartu adalah satu divisi, centang pipeline yang dipakai divisi itu.
  const lines = (Store.db.lines || []).filter(l => !l.archived);
  const ov = Store.db.lineUsers || [];
  const userOpts = Store.db.users.filter(u => u.active && u.role !== 'Super Admin');
  const divisions = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px">' + Store.db.departments.map(d => {
    const used = lines.filter(l => (l.depts || []).includes(d.name));
    return '<div class="card pad" style="display:flex;flex-direction:column;gap:10px;padding:20px"><div style="display:flex;justify-content:space-between;gap:8px;align-items:baseline"><strong style="font-size:17px">' + esc(d.name) + '</strong><span class="small muted">' + used.length + ' pipeline</span></div>' +
      '<span class="lbl">Pipeline yang dipakai</span><div style="display:flex;flex-direction:column;gap:6px">' + lines.map(l => {
        const on = (l.depts || []).includes(d.name);
        return adm ? '<label class="lrow" style="padding:8px 12px;gap:10px;cursor:pointer;' + (on ? 'background:var(--tint)' : '') + '"><input type="checkbox" data-ch="linedept" data-v="' + l.id + '" data-d="' + esc(d.name) + '"' + (on ? ' checked' : '') + '><span style="flex:1;min-width:0"><strong>' + esc(l.name) + '</strong><span class="small muted" style="display:block">' + l.stages.map(s => esc(s.name)).join(' → ') + '</span></span></label>'
          : (on ? '<span class="chip c-info">' + esc(l.name) + '</span>' : '');
      }).join('') + '</div></div>';
  }).join('') + '</div>';
  const exceptions = '<div class="card pad" style="display:flex;flex-direction:column;gap:12px;padding:20px"><strong>Pengecualian per pengguna</strong>' +
    '<p class="small muted" style="margin:0">Tambahkan pipeline untuk orang tertentu di luar divisinya, atau cabut pipeline divisinya khusus untuk orang itu.</p>' +
    '<div style="display:flex;flex-wrap:wrap;gap:8px">' + (ov.length ? ov.map(x => { const l = lineById(x.lineId) || {}; return '<span class="chip ' + (x.allowed ? 'c-ok' : 'c-bad') + '" style="padding-right:4px">' + esc(userName(x.userId)) + ': ' + (x.allowed ? '+ ' : '− ') + esc(l.name || '-') + (adm ? '<button type="button" class="btn-ghost" style="min-height:30px;padding:0 6px" data-a="lineuserdel" data-v="' + x.lineId + '" data-u="' + x.userId + '" aria-label="Hapus pengecualian ' + esc(userName(x.userId)) + '">' + ic('x', 'ic-s') + '</button>' : '') + '</span>'; }).join('') : '<span class="small muted">Belum ada.</span>') + '</div>' +
    (adm ? '<form data-form="lineuser" style="display:flex;flex-wrap:wrap;gap:8px"><label style="flex:1 1 200px"><span class="sr">Pengguna</span><select class="inp" name="userId" style="min-height:44px">' + opt('', '', 'Pilih pengguna') + userOpts.map(u => opt(u.id, '', u.name + ' · ' + u.dept)).join('') + '</select></label><label style="flex:1 1 160px"><span class="sr">Pipeline</span><select class="inp" name="lineId" style="min-height:44px">' + lines.map(l => opt(l.id, '', l.name)).join('') + '</select></label><label><span class="sr">Aksi</span><select class="inp" name="allowed" style="min-height:44px;width:auto">' + opt('1', '1', 'Tambahkan') + opt('0', '1', 'Cabut') + '</select></label><button type="submit" class="btn btn-s btn-sm">Simpan</button></form>' : '') + '</div>';
  const list = '<div class="card pad" style="display:flex;flex-direction:column;gap:10px;padding:20px"><div style="display:flex;justify-content:space-between;align-items:center;gap:8px"><strong>Daftar pipeline</strong>' + (adm ? '<button type="button" class="btn btn-sm" data-a="newline">' + ic('plus', 'ic-s') + 'Pipeline baru</button>' : '') + '</div>' +
    lines.map(l => '<div class="lrow" style="padding:10px 12px;gap:10px"><span style="flex:1;min-width:0"><strong>' + esc(l.name) + '</strong><span class="small muted" style="display:block">Dipakai: ' + esc((l.depts || []).join(', ') || 'belum ada divisi') + '</span></span>' + (adm ? '<button type="button" class="btn btn-s btn-sm" style="color:var(--bad)" data-a="delline" data-v="' + l.id + '" aria-label="Hapus pipeline ' + esc(l.name) + '">' + ic('trash', 'ic-s') + 'Hapus</button>' : '') + '</div>').join('') + '</div>';
  return '<div class="sec-h"><div><span class="lbl">Pipeline per divisi</span><h2 class="disp h2">Divisi pakai pipeline apa</h2></div></div>' +
    '<p class="small muted" style="margin:0">Centang pipeline yang dipakai setiap divisi. Satu divisi boleh memakai lebih dari satu pipeline. Orang di divisi itu melihat pipeline yang dicentang setelah halaman dimuat ulang. Stage diatur dari halaman Pipeline.</p>' +
    divisions + exceptions + list;
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
