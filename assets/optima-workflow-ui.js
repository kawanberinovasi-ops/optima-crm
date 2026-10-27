/* Workflow controls; original module order and visual system are preserved. */
(() => {
  'use strict';
  const denied = () => toast('Tidak ada akses', 'Data ini berada di luar divisi atau kewenanganmu.', 'bad');
  const localInternal = () => ['127.0.0.1', 'localhost', '[::1]'].includes(location.hostname);
  const input = (m, name, label, value, extra = '') => '<label class="fld"><span>' + label + '</span><input class="inp" name="' + name + '" value="' + esc(value || '') + '"' + extra + inv(m, name) + '>' + fieldErr(m, name) + '</label>';
  const options = (items, selected) => items.map(s => opt(s.id, selected, s.name)).join('');

  Modals.record = m => {
    const r = m.rec || {};
    const v = Object.assign({ serviceId: '', source: 'Referensi', segment: '' }, r, m.val || {});
    const actor = me(); const pic = r.id ? userById(r.picId) : actor;
    const service = serviceById(v.serviceId);
    const field = (name, label, extra = '') => input(m, name, label, v[name], extra);
    const body = '<p class="small muted" style="margin:0">' + (r.id ? 'Status menjadi Client otomatis setelah deal Won. Pindahkan penanganan melalui Pindah PIC / divisi.' : 'Setiap orang dapat menerima Lead langsung. PIC awal otomatis akun pengisi; divisi awal mengikuti divisi akunmu. CS juga dapat menerima lead umum.') + '</p>' +
      '<div class="form-grid">' +
      field('name', 'Nama *', ' required autofocus') +
      '<div class="fld"><span>Status</span><div>' + catChip(r.cat || 'Lead') + '</div><small>Client setelah closing / Won.</small></div>' +
      '<label class="fld"><span>Layanan</span><select class="inp" name="serviceId" data-ch="record-service">' + opt('', v.serviceId, 'Belum ditentukan') + options(Store.db.services || [], v.serviceId) + '</select>' + fieldErr(m, 'serviceId') + '<small>' + (service ? 'Divisi layanan yang disarankan: ' + esc(service.dept) + '. Serah terima tetap melalui PIC / divisi.' : 'Layanan dapat ditentukan setelah kebutuhan Lead diketahui.') + '</small></label>' +
      '<label class="fld"><span>Segmen</span><select class="inp" name="segment">' + opt('', v.segment, 'Pilih segmen') + optList('segments').map(s => opt(s, v.segment)).join('') + '</select></label>' +
      '<div class="fld"><span>PIC' + (r.id ? '' : ' awal (otomatis)') + '</span><input class="inp" value="' + esc((pic || {}).name || '-') + '" disabled></div>' +
      '<div class="fld"><span>Divisi pemilik</span><input class="inp" value="' + esc(r.id ? r.dept : actor.dept) + '" disabled></div>' +
      '<label class="fld"><span>Sumber</span><select class="inp" name="source">' + optList('sources').map(s => opt(s, v.source)).join('') + '</select></label>' +
      field('city', 'Kota') + field('contact', 'Nama kontak utama') + field('title', 'Jabatan kontak') + field('cEmail', 'Email kontak', ' type="email"') + field('phone', 'Telepon kontak', ' inputmode="tel"') +
      (v.serviceId === 'kol' && canSeeRate() ? field('rate', 'Rate card KOL', ' inputmode="numeric"') : '') +
      '<label class="fld" style="grid-column:1/-1"><span>Catatan</span><textarea class="inp" name="note">' + esc(v.note || '') + '</textarea></label></div>' +
      (m.dupes && m.dupes.length ? '<div class="small" role="alert" style="padding:14px;border-radius:16px;background:var(--ysoft)"><strong>Ada data yang mirip.</strong> ' + m.dupes.map(x => x.vis ? esc((recById(x.id) || {}).name || '') + ' (' + esc(x.why.join(', ')) + ')' : 'Data serupa ada pada divisi lain; detailnya tidak ditampilkan.').join(' · ') + '<br>Periksa dahulu. Simpan sekali lagi hanya jika memang data yang berbeda.</div>' : '');
    return modalShell(r.id ? 'Edit data' : 'Lead baru', 'Client & Partner', body, ft(), true, 'record');
  };
  Changes['record-service'] = el => { App.modal.val = fd(el.closest('form')); App.modal.still = true; render(); };
  Changes.cpservice = el => { App.ui.cp.service = el.value; App.ui.cp.page = 0; render(); };
  Changes['deal-record'] = el => { const r = recById(el.value); App.modal.val = Object.assign(fd(el.closest('form')), { ownerId: r ? r.picId : '', lineId: r ? lineForDivision(r.dept).id : '' }); App.modal.still = true; render(); };
  const resetFilters = Actions.cpreset;
  Actions.cpreset = () => { App.ui.cp.service = 'all'; resetFilters(); };

  Modals.pic = m => {
    const r = recById(m.id); const v = m.val || {};
    const svc = serviceById(r.serviceId);
    const dept = v.dept || (svc && svc.dept) || r.dept;
    const candidates = divisionUsers(dept);
    return modalShell('Pindah PIC / divisi', esc(r.name),
      '<p class="small muted" style="margin:0">Penanganan sekarang: ' + esc(r.dept) + ' · ' + esc(userName(r.picId)) + '. Siapa pun yang berwenang atas data dapat menyerahkannya ke divisi lain, termasuk ke atau dari CS.</p><div class="form-grid">' +
      '<label class="fld"><span>Divisi tujuan *</span><select class="inp" name="dept" data-ch="handoff-dept">' + Store.db.departments.map(d => opt(d.name, dept)).join('') + '</select></label>' +
      '<label class="fld"><span>PIC penerima *</span><select class="inp" name="picId">' + opt('', v.picId, 'Pilih penerima') + candidates.map(u => opt(u.id, v.picId, u.name)).join('') + '</select>' + fieldErr(m, 'picId') + (!candidates.length ? '<small>Belum ada pengguna aktif di divisi ini. Tambahkan pengguna terlebih dahulu.</small>' : '') + '</label>' +
      input(m, 'reason', 'Alasan serah terima *', v.reason, ' required') + '</div><p class="small muted" style="margin:0">Data, kontak, interaksi, dan deal mengikuti divisi penerima. Divisi asal tidak lagi dapat membuka data tersebut, kecuali Executive.</p>', ft('Serahkan'), false, 'pic');
  };
  Changes['handoff-dept'] = el => { App.modal.val = Object.assign(fd(el.closest('form')), { picId: '' }); App.modal.still = true; render(); };
  Forms.pic = o => {
    const m = App.modal; const r = recById(m.id); const target = userById(o.picId);
    if (!r || !canMovePic(r)) { denied(); return; }
    m.val = o;
    if (reqErr(m, o, ['picId','reason'], { picId: 'Pilih PIC penerima.', reason: 'Isi alasan serah terima.' })) return;
    if (!target || !target.active || target.role === 'Viewer' || target.dept !== o.dept) { m.err = { picId: 'Pilih pengguna aktif dari divisi tujuan.' }; m.still = true; render(); return; }
    transferRecord(r, target, o.reason);
    App.modal = null; if (!canSeeRec(r)) App.drawer = null;
    commit(); toast('Penanganan diserahkan', r.name + ' → ' + target.dept + ' · ' + target.name, 'info');
  };
  // Lifecycle cannot be bypassed through the former manual conversion action.
  Actions.convert = () => toast('Menunggu closing / Won', 'Lead menjadi Client otomatis setelah deal Won.', 'info');

  AdminTabs.services = adm => '<div class="sec-h"><div><span class="lbl">Layanan dan penanggung jawab</span><h2 class="disp h2">Layanan terpisah dari status.</h2></div>' + (adm ? '<button class="btn btn-sm" data-a="workflow-new-service">Layanan baru</button>' : '') + '</div>' +
    '<p class="small muted" style="margin:0">Status relasi hanya Lead atau Client. Pemetaan layanan adalah saran divisi penerima, bukan jalur wajib: setiap divisi tetap bisa menerima Lead langsung dan melakukan serah terima.</p><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Layanan</th><th>Divisi layanan</th><th>Aksi</th></tr></thead><tbody>' +
    (Store.db.services || []).map(s => '<tr><td><strong>' + esc(s.name) + '</strong></td><td>' + (adm ? '<select class="inp" data-ch="workflow-service-dept" data-v="' + esc(s.id) + '" aria-label="Divisi layanan ' + esc(s.name) + '">' + Store.db.departments.map(d => opt(d.name, s.dept)).join('') + '</select>' : esc(s.dept)) + '</td><td>' + (adm ? '<button type="button" class="btn btn-s btn-sm" style="color:var(--bad)" data-a="workflow-delete-service" data-v="' + s.id + '" aria-label="Hapus layanan ' + esc(s.name) + '">Hapus</button>' : '') + '</td></tr>').join('') + '</tbody></table></div>';
  Changes['workflow-service-dept'] = el => { if (!canAdmin()) return; const s = serviceById(el.dataset.v); if (!s || !Store.db.departments.some(d => d.name === el.value)) return; s.dept = el.value; audit('Ubah', 'Layanan', s.name + ' → ' + s.dept); commit(); };
  Actions['workflow-new-service'] = () => { if (!canAdmin()) return; App.modal = { kind: 'workflowservice' }; render(); };
  Modals.workflowservice = m => modalShell('Layanan baru', 'Layanan & Divisi', '<div class="form-grid">' + input(m, 'name', 'Nama layanan *', (m.val || {}).name, ' required') + '<label class="fld"><span>Divisi layanan</span><select class="inp" name="dept">' + Store.db.departments.map(d => opt(d.name, (m.val || {}).dept)).join('') + '</select></label></div>', ft(), false, 'workflowservice');
  Forms.workflowservice = o => { if (!canAdmin()) return; const m = App.modal; m.val = o; if (!o.name || (Store.db.services || []).some(s => s.name.toLowerCase() === o.name.toLowerCase())) { m.err = { name: 'Isi nama layanan yang belum ada.' }; render(); return; } Store.db.services.push({ id: uid('service'), name: o.name, dept: o.dept }); App.modal = null; commit(); };

  // Local-only onboarding: private names/emails never enter public source or network requests.
  const adminPage = Pages.akses;
  Pages.akses = () => {
    let html = adminPage();
    if (canAdmin() && localInternal() && App.ui.ak.tab === 'users') html += '<section class="card pad" style="margin-top:16px"><strong>Daftar akun internal</strong><p class="small muted">Muat berkas daftar tim ke browser lokal ini. Berkas tidak dikirim ke server atau repositori dan tidak mengubah akun demo publik.</p><label class="btn btn-s" style="cursor:pointer">Muat daftar tim internal<input type="file" accept="application/json,.json" data-ch="workflow-team-file" style="position:absolute;opacity:0;width:1px;height:1px" aria-label="Pilih daftar tim internal"></label></section>';
    return html;
  };
  Changes['workflow-team-file'] = async el => {
    if (!canAdmin() || !localInternal() || !el.files || !el.files[0]) return;
    const actorId = me().id;
    const file = el.files[0];
    if (file.size > 100000) { toast('Berkas terlalu besar', 'Gunakan berkas JSON daftar tim.', 'bad'); return; }
    try {
      const data = JSON.parse(await file.text()); const list = data.users;
      if (!Array.isArray(list) || !list.length || list.length > 100) throw new Error('Daftar pengguna tidak valid.');
      const emails = new Set();
      const normalized = list.map(u => {
        const name = String(u.name || '').trim(); const email = String(u.email || '').trim().toLowerCase();
        if (!name || !emailOk(email) || !Store.db.departments.some(d => d.name === u.dept) || !ROLES.includes(u.role) || u.role === 'Super Admin') throw new Error('Periksa nama, email, divisi, dan role. Super Admin tidak dapat dimuat melalui berkas.');
        const aliases = (Array.isArray(u.aliases) ? u.aliases : []).map(x => String(x).trim().toLowerCase());
        [email, ...aliases].forEach(e => { if (!emailOk(e) || emails.has(e)) throw new Error('Email atau alias tidak valid / ganda.'); emails.add(e); });
        return { name, email, aliases, dept: u.dept, role: u.role, active: true };
      });
      if (!me() || me().id !== actorId || !canAdmin() || !localInternal()) return;
      App.modal = { kind: 'workflowteam', users: normalized }; render();
    } catch (e) { toast('Daftar belum dimuat', e.message || 'Periksa format JSON.', 'bad'); }
  };
  Modals.workflowteam = m => modalShell('Muat akun tim internal', 'Hanya browser lokal', '<p class="small muted">' + m.users.length + ' akun akan ditambahkan atau diperbarui di browser ini. Ini belum membuat akun SSO / server.</p><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Nama</th><th>Email</th><th>Divisi</th></tr></thead><tbody>' + m.users.map(u => '<tr><td>' + esc(u.name) + '</td><td>' + esc(u.email) + '</td><td>' + esc(u.dept) + '</td></tr>').join('') + '</tbody></table></div>', ft('Muat ke browser lokal'), true, 'workflowteam');
  Forms.workflowteam = () => {
    if (!canAdmin() || !localInternal() || !App.modal.users) return;
    const incoming = App.modal.users;
    incoming.forEach(x => {
      let u = Store.db.users.find(y => y.email.toLowerCase() === x.email);
      if (u && u.role === 'Super Admin') return;
      if (u) Object.assign(u, x); else Store.db.users.push({ id: uid('team'), ...x });
    });
    App.modal = null; commit(); toast('Akun internal dimuat', incoming.length + ' akun tersedia hanya di browser lokal ini.', 'info');
  };
  const emailLogin = Forms.loginemail;
  Forms.loginemail = o => { const value = String(o.email || '').toLowerCase(); const u = Store.db.users.find(x => (x.aliases || []).includes(value)); emailLogin(u ? { ...o, email: u.email } : o); };

  // Guard routes and handlers as well as visible buttons.
  const recordReadActions = ['openrec','dupeopen'];
  const recordWriteActions = ['editrec','delrec','restorerec','movepic','mergerec','addcontact','editcontact','cprimary','cremove'];
  recordReadActions.concat(recordWriteActions).forEach(key => { const original = Actions[key]; if (!original) return; Actions[key] = (el, e) => { const r = recById(el.dataset.v); if (!r || !(recordReadActions.includes(key) ? canSeeRec(r) : ['delrec','restorerec','mergerec'].includes(key) ? canDelRec(r) : canEditRec(r))) { denied(); return; } original(el, e); }; });
  ['opendeal','reopen','dsset'].forEach(key => { const original = Actions[key]; Actions[key] = (el, e) => { const d = findDeal(el.dataset.v); if (!d || !(key === 'opendeal' ? canSeeDeal(d) : canEditDeal(d))) { denied(); return; } original(el, e); }; });
  ['newint','newdeal'].forEach(key => { const original = Actions[key]; Actions[key] = (el, e) => { if (!can(key === 'newint' ? 'interaksi' : 'deal', 'C') || (el.dataset.v && !canSeeRec(recById(el.dataset.v)))) { denied(); return; } original(el, e); }; });
  ['fudone','cancelint'].forEach(key => { const original = Actions[key]; Actions[key] = (el, e) => { const i = Store.db.interactions.find(x => x.id === el.dataset.v); if (!i || !canSeeRec(recById(i.recordId)) || !can('interaksi', 'C') || (key === 'cancelint' && !canCancelInt(i))) { denied(); return; } original(el, e); }; });
  const navline = Actions.navline;
  Actions.navline = el => { if (!visibleLines().some(l => l.id === el.dataset.v)) { denied(); return; } navline(el); };
  const plline = Actions.plline;
  Actions.plline = el => { if (!visibleLines().some(l => l.id === el.dataset.v)) return; plline(el); };
  ['addstage','editstage','delstage','stageleft','stageright'].forEach(key => { const original = Actions[key]; if (!original) return; Actions[key] = (el, e) => { if (!canManageLine(lineById(App.ui.pl.line))) { denied(); return; } original(el, e); }; });
  const notifyOpen = Actions.notifopen;
  Actions.notifopen = el => { const n = (Store.db.notifs || []).find(x => x.id === el.dataset.v); if (!n || n.userId !== me().id || !workflowNotificationVisible(n)) { denied(); return; } notifyOpen(el); };
  ['archive','contact','merge','cancelint'].forEach(key => { const original = Forms[key]; Forms[key] = o => { const m = App.modal; const i = key === 'cancelint' ? Store.db.interactions.find(x => x.id === m.id) : null; const id = key === 'contact' ? m.recId : key === 'merge' ? m.keepId : i ? i.recordId : m.id; const r = recById(id); if (!r || !(key === 'cancelint' ? can('interaksi', 'C') && canSeeRec(r) && canCancelInt(i) : ['archive','merge'].includes(key) ? canDelRec(r) : canEditRec(r)) || (key === 'merge' && !canSeeRec(recById(o.srcId)))) { denied(); return; } original(o); }; });
  ['user','dept','override','optadd'].forEach(key => { const original = Forms[key]; if (original) Forms[key] = o => { if (!canAdmin()) { denied(); return; } original(o); }; });
  ['acc','udept','urole'].forEach(key => { const original = Changes[key]; Changes[key] = el => { if (!canAdmin()) { denied(); return; } original(el); }; });
  const switchAccount = Actions.switch;
  Actions.switch = el => { App.drawer = null; App.modal = null; App.pal = null; Object.assign(App.ui.cp, { cat: 'all', dept: 'all', service: 'all', q: '', arch: false }); switchAccount(el); };
  const stageForm = Forms.stage;
  Forms.stage = o => { if (!canManageLine(lineById(App.ui.pl.line))) { denied(); return; } stageForm(o); };
  const lineForm = Forms.line;
  Forms.line = o => { if (!canConfigPipeline()) { denied(); return; } const before = new Set(Store.db.lines.map(l => l.id)); lineForm(o); Store.db.lines.filter(l => !before.has(l.id)).forEach(l => { l.dept = me().dept; }); Store.save(); render(); };
})();
