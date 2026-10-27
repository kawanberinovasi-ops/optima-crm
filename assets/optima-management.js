/* Recoverable deletion with permission and dependency checks at confirmation time. */
(() => {
  'use strict';
  const clone = value => JSON.parse(JSON.stringify(value));
  const tables = { record: 'records', line: 'lines', service: 'services', dept: 'departments', user: 'users', override: 'overrides' };
  const labels = { record: 'Client & Partner', line: 'Lini bisnis', service: 'Layanan', stage: 'Stage', dept: 'Department', user: 'Pengguna', override: 'Pengecualian' };
  const denied = message => toast('Belum bisa dihapus', message || 'Akun ini tidak punya hak untuk tindakan tersebut.', 'bad');
  const trash = () => Store.db.trash || (Store.db.trash = []);
  const admin = () => !!me() && me().active && canAdmin();
  const actualUser = id => Store.db.users.find(u => u.id === id);

  function plan(kind, id) {
    const line = kind === 'stage' ? Store.db.lines.find(l => l.stages.some(s => s.id === id)) : null;
    const item = kind === 'stage' ? line && line.stages.find(s => s.id === id) : tables[kind] && Store.db[tables[kind]].find(x => x.id === id);
    if (!item || !me() || !me().active) return null;
    const allowed = kind === 'record' ? canDelRec(item) : kind === 'line' ? canManageLine(item) : kind === 'stage' ? canManageLine(line) : admin();
    const p = { item, line, allowed, label: item.name || (kind === 'override' ? userName(item.userId) + ' · ' + (KEY_LABEL[item.key] || item.key) : labels[kind]), dept: kind === 'record' || kind === 'line' || kind === 'service' || kind === 'user' ? item.dept : kind === 'stage' ? line.dept : kind === 'dept' ? item.name : '', block: '' };
    if (!allowed) return p;
    if (kind === 'line' && Store.db.deals.some(d => d.lineId === id)) p.block = 'Masih ada deal di lini bisnis ini. Pindahkan deal ke lini bisnis lain dahulu.';
    if (kind === 'stage') {
      if (isClosingStage(line, id)) p.block = 'Stage Closing tetap diperlukan untuk proses Won dan tidak dapat dihapus.';
      else if (line.stages.length <= 2) p.block = 'Pipeline harus memiliki setidaknya satu stage awal dan Closing.';
      else if (Store.db.deals.some(d => d.lineId === line.id && d.stageId === id)) p.block = 'Masih ada deal di stage ini. Pindahkan deal ke stage lain dahulu.';
    }
    if (kind === 'service' && (Store.db.records.some(r => r.serviceId === id) || Store.db.deals.some(d => d.serviceId === id || d.service === item.name))) p.block = 'Layanan masih dipakai oleh relasi atau deal. Ganti layanan pada data terkait dahulu.';
    if (kind === 'dept') {
      const lines = Store.db.lines.filter(l => l.dept === item.name);
      if (Store.db.users.some(u => u.dept === item.name) || Store.db.records.some(r => r.dept === item.name) || Store.db.services.some(s => s.dept === item.name) || Store.db.deals.some(d => lines.some(l => l.id === d.lineId))) p.block = 'Department masih dipakai oleh pengguna, relasi, layanan, atau deal. Pindahkan penugasannya dahulu. Pipeline kosong akan ikut masuk Data terhapus.';
    }
    if (kind === 'user') {
      if (id === me().id) p.block = 'Akun yang sedang dipakai tidak dapat dihapus.';
      else if (item.active && item.role === 'Super Admin' && Store.db.users.filter(u => u.active && u.role === 'Super Admin').length <= 1) p.block = 'Setidaknya satu Super Admin aktif harus tetap tersedia.';
      else if (Store.db.records.some(r => r.picId === id) || Store.db.deals.some(d => d.ownerId === id && ['Open', 'On Hold'].includes(d.status))) p.block = 'Pengguna masih menjadi PIC relasi atau deal aktif. Serahkan penugasannya ke pengguna lain dahulu.';
    }
    return p;
  }

  function performDelete(kind, id, actor) {
    const p = plan(kind, id);
    if (!p || !p.allowed || me().id !== actor) return denied();
    if (p.block) return denied(p.block);
    const data = { item: clone(p.item) };
    if (kind === 'record') {
      data.interactions = clone(Store.db.interactions.filter(i => i.recordId === id));
      data.deals = clone(Store.db.deals.filter(d => d.recordId === id));
      Store.db.interactions = Store.db.interactions.filter(i => i.recordId !== id);
      Store.db.deals = Store.db.deals.filter(d => d.recordId !== id);
      if (App.drawer && App.drawer.id === id) App.drawer = null;
    }
    if (kind === 'stage') {
      data.lineId = p.line.id; data.index = p.line.stages.findIndex(s => s.id === id);
      p.line.stages = p.line.stages.filter(s => s.id !== id);
    } else {
      if (kind === 'dept') {
        data.access = clone(Store.db.access[p.item.name] || {});
        data.lines = clone(Store.db.lines.filter(l => l.dept === p.item.name));
        Store.db.lines = Store.db.lines.filter(l => l.dept !== p.item.name);
        delete Store.db.access[p.item.name];
      }
      if (kind === 'user') {
        data.overrides = clone(Store.db.overrides.filter(o => o.userId === id));
        Store.db.overrides = Store.db.overrides.filter(o => o.userId !== id);
      }
      Store.db[tables[kind]] = Store.db[tables[kind]].filter(x => x.id !== id);
    }
    trash().unshift({ id: uid('trash'), kind, label: p.label, dept: p.dept, by: me().id, at: new Date().toISOString(), data });
    if (!visibleLines().some(l => l.id === App.ui.pl.line)) App.ui.pl.line = (visibleLines()[0] || {}).id || '';
    if (!visibleLines().some(l => l.id === App.ui.db.fline)) App.ui.db.fline = '';
    audit('Hapus', labels[kind], p.label + ' dipindahkan ke Data terhapus');
    App.modal = null; Store.save(); render();
    toast('Dihapus', p.label + ' dapat dipulihkan melalui Data terhapus.', 'info');
  }

  function requestDelete(kind, id) {
    const p = plan(kind, id);
    if (!p || !p.allowed) return denied();
    if (p.block) {
      App.modal = { kind: 'confirm', title: 'Belum bisa dihapus', text: esc(p.block), ok: 'Mengerti', fn: () => {} }; render(); return;
    }
    const actor = me().id;
    const related = kind === 'record' ? ' Beserta ' + Store.db.interactions.filter(i => i.recordId === id).length + ' interaksi dan ' + Store.db.deals.filter(d => d.recordId === id).length + ' deal terkait.' : kind === 'dept' ? ' Pipeline kosong department ini ikut dipindahkan.' : kind === 'user' ? ' Akun tidak bisa dipakai masuk. Nama di riwayat lama tetap tersimpan.' : '';
    App.modal = { kind: 'confirm', title: 'Hapus ' + esc(p.label) + '?', sub: labels[kind], text: 'Data akan dipindahkan ke Data terhapus dan bisa dipulihkan.' + related, ok: 'Hapus', danger: true, fn: () => performDelete(kind, id, actor) }; render();
  }

  const canRestore = entry => {
    if (!entry || !me() || !me().active) return false;
    if (admin()) return true;
    if (entry.kind === 'record') return canDelRec(entry.data.item);
    if (entry.kind === 'line') return canManageLine(entry.data.item);
    if (entry.kind === 'stage') return canManageLine(lineById(entry.data.lineId));
    return admin();
  };
  function restoreBlock(entry) {
    const { kind, data } = entry, item = data.item;
    if (kind === 'stage') {
      const line = lineById(data.lineId);
      if (!line) return 'Pulihkan lini bisnis stage ini dahulu.';
      if (line.stages.some(s => s.id === item.id || s.name.toLowerCase() === item.name.toLowerCase())) return 'Stage dengan ID atau nama yang sama sudah tersedia.';
      return '';
    }
    const list = Store.db[tables[kind]];
    if (list.some(x => x.id === item.id)) return 'Data dengan ID yang sama sudah tersedia.';
    if (['line', 'service', 'dept'].includes(kind) && list.some(x => x.name.toLowerCase() === item.name.toLowerCase() && (kind !== 'line' || x.dept === item.dept))) return 'Nama yang sama sudah dipakai. Ubah data yang sudah tersedia dahulu.';
    if (['record', 'line', 'service', 'user'].includes(kind) && !Store.db.departments.some(d => d.name === item.dept)) return 'Pulihkan department data ini dahulu.';
    if (kind === 'record') {
      if (Store.db.records.some(r => r.code === item.code)) return 'Kode relasi sudah dipakai data lain.';
      if (!actualUser(item.picId)) return 'Pulihkan pengguna PIC relasi ini dahulu.';
      if (item.serviceId && !serviceById(item.serviceId)) return 'Pulihkan layanan relasi ini dahulu.';
      if (data.deals.some(d => !lineById(d.lineId) || !lineById(d.lineId).stages.some(s => s.id === d.stageId) || !actualUser(d.ownerId))) return 'Pulihkan lini bisnis, stage, atau pengguna deal terkait dahulu.';
      if (data.deals.some(d => Store.db.deals.some(x => x.id === d.id || (d.code && x.code === d.code))) || data.interactions.some(i => Store.db.interactions.some(x => x.id === i.id || (i.code && x.code === i.code)))) return 'Kode deal atau interaksi terkait sudah dipakai data lain.';
    }
    if (kind === 'dept' && data.lines.some(l => Store.db.lines.some(x => x.id === l.id))) return 'Ada lini bisnis dengan ID yang sama.';
    if (kind === 'user') {
      const emails = [item.email, ...(item.aliases || [])].map(e => e.toLowerCase());
      if (Store.db.users.some(u => [u.email, ...(u.aliases || [])].some(e => emails.includes(e.toLowerCase())))) return 'Email pengguna sudah dipakai akun lain.';
    }
    if (kind === 'override' && (!actualUser(item.userId) || Store.db.overrides.some(o => o.userId === item.userId && o.key === item.key))) return 'Pengguna belum tersedia atau pengecualian untuk bagian yang sama sudah ada.';
    return '';
  }
  function performRestore(id, actor) {
    const entry = trash().find(t => t.id === id);
    if (!canRestore(entry) || me().id !== actor) return denied('Akun ini tidak punya hak memulihkan data.');
    const block = restoreBlock(entry);
    if (block) return denied(block);
    const { kind, data } = entry, item = clone(data.item);
    if (kind === 'stage') {
      const line = lineById(data.lineId); line.stages.splice(Math.min(data.index, line.stages.length - 1), 0, item);
    } else {
      if (kind === 'user') item.active = false;
      Store.db[tables[kind]].push(item);
      if (kind === 'record') { Store.db.interactions.push(...clone(data.interactions)); Store.db.deals.push(...clone(data.deals)); }
      if (kind === 'dept') { Store.db.access[item.name] = clone(data.access); Store.db.lines.push(...clone(data.lines)); }
      if (kind === 'user') Store.db.overrides.push(...clone(data.overrides));
    }
    Store.db.trash = trash().filter(t => t.id !== id);
    audit('Pulihkan', labels[kind], entry.label + ' dipulihkan dari Data terhapus');
    App.modal = null; Store.save(); render();
    toast('Dipulihkan', entry.label + (kind === 'user' ? '. Akun dipulihkan dalam keadaan nonaktif.' : ' kembali tersedia.'));
  }

  Object.entries({ removerec: 'record', delline: 'line', delstage: 'stage', deldept: 'dept', deluser: 'user', ovdel: 'override', 'workflow-delete-service': 'service' }).forEach(([action, kind]) => { Actions[action] = el => requestDelete(kind, el.dataset.v); });
  Actions.opentrash = () => { App.pop = null; App.modal = { kind: 'trash' }; render(); };
  Actions.restoretrash = el => {
    const entry = trash().find(t => t.id === el.dataset.v);
    if (!canRestore(entry)) return denied('Akun ini tidak punya hak memulihkan data.');
    const block = restoreBlock(entry);
    if (block) return denied(block);
    const actor = me().id;
    App.modal = { kind: 'confirm', title: 'Pulihkan ' + esc(entry.label) + '?', sub: 'Data terhapus', text: entry.kind === 'user' ? 'Pengguna akan kembali dalam keadaan nonaktif. Admin dapat mengaktifkannya setelah diperiksa.' : 'Data dan hubungan yang tersimpan akan dikembalikan.', ok: 'Pulihkan', fn: () => performRestore(entry.id, actor) }; render();
  };
  Modals.trash = () => {
    const entries = trash().filter(canRestore);
    const body = '<p class="small muted">Data di sini masih bisa dipulihkan sesuai hak akses. Tidak ada penghapusan permanen otomatis.</p>' + (entries.length ? '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Data</th><th>Bagian</th><th>Dihapus</th><th>Aksi</th></tr></thead><tbody>' + entries.map(t => '<tr><td><strong>' + esc(t.label) + '</strong></td><td>' + labels[t.kind] + '</td><td class="small">' + fmtTime(t.at) + '<br>' + esc(userName(t.by)) + '</td><td><button type="button" class="btn btn-s btn-sm" data-a="restoretrash" data-v="' + t.id + '">Pulihkan</button></td></tr>').join('') + '</tbody></table></div>' : empty('Belum ada data terhapus.', 'Data yang boleh kamu pulihkan akan muncul di sini.'));
    return modalShell('Data terhapus', 'Pemulihan data', body, '<button type="button" class="btn btn-s" data-a="close">Tutup</button>', true);
  };
  const switchAccount = Actions.switch;
  Actions.switch = el => { const user = actualUser(el.dataset.v); if (!user || !user.active) return; switchAccount(el); };
})();
