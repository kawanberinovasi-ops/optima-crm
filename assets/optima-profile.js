/* Self-service profiles. Identity and access fields are never accepted from the form. */
(() => {
  'use strict';
  const draft = () => App.modal && App.modal.kind === 'profile' && me() && App.modal.userId === me().id ? App.modal : null;
  const field = (m, key, label, extra) => '<label class="fld"><span>' + label + '</span><input class="inp" id="profile-' + key + '" name="' + key + '" data-in="profilefield" value="' + esc(m.val[key]) + '"' + (extra || '') + inv(m, key) + ' aria-describedby="profile-' + key + '-error">' + (m.err && m.err[key] ? '<span class="err" id="profile-' + key + '-error">' + esc(m.err[key]) + '</span>' : '') + '</label>';
  const photo = value => profilePhotoSource(value);
  function refresh(m) { if (draft() !== m) return; m.still = true; render(); }
  Actions.editprofile = () => {
    const u = me(); if (!u || !u.active) return;
    App.pop = null;
    App.modal = { kind: 'profile', userId: u.id, val: { name: u.name, phone: (u.profile || {}).phone || '', bio: (u.profile || {}).bio || '', photo: photo((u.profile || {}).photo) }, err: {} };
    render();
  };
  Modals.profile = m => {
    const u = me(); if (!u || m.userId !== u.id) return '';
    const image = photo(m.val.photo);
    const body = '<div class="profile-photo-row"><span class="profile-avatar" aria-label="Foto profil"><span class="profile-avatar-initials">' + esc(initials(m.val.name || u.name)) + '</span>' + (image ? '<img src="' + image + '" alt="">' : '') + '</span><div class="profile-photo-controls"><strong>Foto profil</strong><div class="profile-photo-actions"><label class="btn btn-s btn-sm profile-upload">' + ic('up', 'ic-s') + 'Ganti foto<input type="file" name="photoFile" accept="image/jpeg,image/png,image/webp" data-ch="profilephoto" aria-label="Pilih foto profil"' + (m.loading ? ' disabled' : '') + '></label>' + (image ? '<button type="button" class="btn-ghost" data-a="profileremove"' + (m.loading ? ' disabled' : '') + '>Hapus foto</button>' : '') + '</div><small class="muted">JPG, PNG, atau WebP · maks. 5 MB</small>' + (m.loading ? '<span role="status" class="small muted">Menyiapkan foto…</span>' : '') + (m.err.photo ? '<span role="alert" class="err">' + esc(m.err.photo) + '</span>' : '') + '</div></div>' +
      '<div class="form-grid">' + field(m, 'name', 'Nama lengkap *', ' autocomplete="name" maxlength="80" required') + field(m, 'phone', 'Telepon / WhatsApp', ' type="tel" autocomplete="tel" maxlength="40" placeholder="Contoh: +62 812 3456 7890"') +
      '<label class="fld profile-full"><span>Bio singkat</span><textarea class="inp" id="profile-bio" name="bio" data-in="profilefield" rows="3" maxlength="300" placeholder="Ceritakan singkat tentang kamu."' + inv(m, 'bio') + ' aria-describedby="profile-bio-count">' + esc(m.val.bio) + '</textarea><small id="profile-bio-count">' + m.val.bio.length + '/300 karakter</small>' + fieldErr(m, 'bio') + '</label></div>' +
      '<div class="profile-account"><div class="profile-account-title">' + ic('lock', 'ic-s') + '<span>Data akun · dikelola admin</span></div><dl><div><dt>Email kantor</dt><dd>' + esc(u.email) + '</dd></div><div><dt>Department</dt><dd>' + esc(u.dept) + '</dd></div><div><dt>Role</dt><dd>' + esc(u.role) + '</dd></div></dl></div>' +
      (m.err.storage ? '<p class="err" role="alert">' + esc(m.err.storage) + '</p>' : '');
    return modalShell('Edit Profil', 'Profil saya', body, '<button type="button" class="btn btn-s" data-a="close">Batal</button><button type="submit" class="btn"' + (m.loading ? ' disabled' : '') + '>' + ic('check', 'ic-s') + 'Simpan profil</button>', false, 'profile');
  };
  Inputs.profilefield = (value, el) => {
    const m = draft(); if (!m || !['name', 'phone', 'bio'].includes(el.name)) return;
    m.val[el.name] = value;
    if (el.name === 'bio') { const count = document.getElementById('profile-bio-count'); if (count) count.textContent = value.length + '/300 karakter'; }
    if (el.name === 'name') { const preview = document.querySelector('.profile-avatar-initials'); if (preview) preview.textContent = initials(value); }
  };
  Actions.profileremove = () => { const m = draft(); if (!m || m.loading) return; m.val.photo = ''; delete m.err.photo; refresh(m); };
  Changes.profilephoto = async el => {
    const m = draft(), file = el.files && el.files[0]; if (!m || !file || m.loading) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { m.err.photo = 'Pilih foto JPG, PNG, atau WebP.'; refresh(m); return; }
    if (file.size > 5 * 1024 * 1024) { m.err.photo = 'Ukuran foto maksimal 5 MB.'; refresh(m); return; }
    m.loading = true; delete m.err.photo; refresh(m);
    const url = URL.createObjectURL(file);
    try {
      const img = await new Promise((resolve, reject) => { const image = new Image(); image.onload = () => resolve(image); image.onerror = () => reject(new Error('decode')); image.src = url; });
      if (!img.naturalWidth || !img.naturalHeight || img.naturalWidth * img.naturalHeight > 40000000) throw new Error('size');
      const canvas = document.createElement('canvas'); canvas.width = canvas.height = 256;
      const ctx = canvas.getContext('2d'); if (!ctx) throw new Error('canvas');
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, 256, 256);
      const size = Math.min(img.naturalWidth, img.naturalHeight);
      ctx.drawImage(img, (img.naturalWidth - size) / 2, (img.naturalHeight - size) / 2, size, size, 0, 0, 256, 256);
      const source = photo(canvas.toDataURL('image/jpeg', .86)); if (!source) throw new Error('encode');
      if (draft() !== m) return;
      m.val.photo = source;
    } catch (e) { if (draft() === m) m.err.photo = 'Foto belum bisa dibaca. Coba file JPG atau PNG lain.'; }
    finally { URL.revokeObjectURL(url); m.loading = false; if (draft() === m) refresh(m); }
  };
  Forms.profile = o => {
    const m = draft(), u = me(); if (!m || !u || !u.active || m.loading) return;
    const val = { name: String(o.name || '').trim(), phone: String(o.phone || '').trim(), bio: String(o.bio || '').trim(), photo: photo(m.val.photo) };
    const err = {};
    if (val.name.length < 2 || val.name.length > 80) err.name = 'Isi nama lengkap, 2–80 karakter.';
    const digits = val.phone.replace(/\D/g, '');
    if (val.phone && (!/^[+0-9() .-]+$/.test(val.phone) || digits.length < 7 || digits.length > 15 || val.phone.length > 40)) err.phone = 'Isi nomor telepon yang valid, 7–15 angka.';
    if (val.bio.length > 300) err.bio = 'Bio maksimal 300 karakter.';
    m.val = val; m.err = err;
    if (Object.keys(err).length) { refresh(m); return; }
    const previous = u.profile || {};
    const changed = [u.name !== val.name ? 'nama' : '', (previous.phone || '') !== val.phone ? 'telepon' : '', (previous.bio || '') !== val.bio ? 'bio' : '', photo(previous.photo) !== val.photo ? 'foto' : ''].filter(Boolean);
    if (!changed.length) { closeModal(); return; }
    const updated = Object.assign({}, u, { name: val.name, profile: Object.assign({}, previous, { phone: val.phone, bio: val.bio, photo: val.photo }), profileUpdatedAt: new Date().toISOString() });
    const entry = { id: uid('a'), at: updated.profileUpdatedAt, by: u.id, action: 'Ubah', entity: 'Profil', detail: 'Memperbarui profil sendiri: ' + changed.join(', ') + '.' };
    const next = Object.assign({}, Store.db, { users: Store.db.users.map(x => x.id === u.id ? updated : x), audit: [entry, ...Store.db.audit].slice(0, 800) });
    try { localStorage.setItem(DB_KEY, JSON.stringify(next)); }
    catch (e) { m.err.storage = 'Profil belum tersimpan. Penyimpanan browser tidak tersedia atau penuh. Coba tanpa foto, lalu simpan lagi.'; refresh(m); return; }
    Store.db = next; Store.mem = next; App.modal = null; render(); toast('Profil tersimpan', 'Perubahan profil kamu sudah diperbarui.', 'info');
  };
})();
