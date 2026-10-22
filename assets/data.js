/* OPTIMA CRM · data contoh, penyimpanan lokal, dan hak akses */
'use strict';
const DB_KEY = 'optima_crm_client_preview_v1';
const DEPTS = ['Executive', 'Commercial', 'Customer Service', 'Digital Campaign', 'Creative & Media', 'CampusNet', 'DietPartner', 'Business Operation'];
const ROLES = ['Super Admin', 'Kepala Department', 'Koordinator', 'Staf', 'Viewer'];
const CATS = ['Lead', 'Client', 'Community', 'KOL/KOC', 'Media'];
const CAT_KEY = { 'Lead': 'lead', 'Client': 'client', 'Community': 'community', 'KOL/KOC': 'kol', 'Media': 'media' };
const ENTS = [['lead', 'Lead'], ['client', 'Client'], ['community', 'Community'], ['kol', 'KOL/KOC'], ['media', 'Media'], ['interaksi', 'Interaksi'], ['deal', 'Deal']];
const DEFAULT_OPTS = {
  segments: ['Korporat', 'Pendidikan', 'Kesehatan', 'Komunitas', 'UMKM', 'F&B', 'Retail', 'Media lokal', 'Radio', 'Online', 'Lifestyle', 'Kuliner', 'Travel'],
  sources: ['Referensi', 'Website', 'Event', 'Media sosial', 'Kunjungan', 'Lainnya'],
  intTypes: ['Telepon', 'Email', 'WhatsApp', 'Meeting', 'Kunjungan', 'Lainnya'],
  lostReasons: ['Harga di atas anggaran', 'Pilih vendor lain', 'Kebutuhan berubah', 'Ga ada respons', 'Waktu ga cocok', 'Lainnya']
};
const OPT_LABEL = { segments: 'Segmen', sources: 'Sumber data', intTypes: 'Jenis interaksi', lostReasons: 'Alasan deal Lost' };
const optList = (k) => ((Store.db && Store.db.options && Store.db.options[k]) || DEFAULT_OPTS[k]).slice();
const DEAL_STATUS = ['Open', 'Won', 'Lost', 'On Hold'];
const LV = { N: 0, R: 1, C: 2, M: 3 };

const pad2 = (n) => String(n).padStart(2, '0');
const isoDate = (d) => d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
const today = () => isoDate(new Date());
const addDays = (n, base) => { const d = base ? new Date(base + 'T00:00:00') : new Date(); d.setDate(d.getDate() + n); return isoDate(d); };
const monthsAgo = (m, day) => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - m); d.setDate(Math.min(day || 15, 28)); return isoDate(d); };
const uid = (p) => p + '-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);

function seed() {
  const users = [
    { id: 'u1', name: 'Admin Uji', email: 'admin@contoh.test', dept: 'Executive', role: 'Super Admin', active: true, note: 'Akun dev Campus Innovate' },
    { id: 'u2', name: 'Rina Pratama', email: 'rina@contoh.test', dept: 'Commercial', role: 'Kepala Department', active: true },
    { id: 'u3', name: 'Ayu Safitri', email: 'ayu@contoh.test', dept: 'Commercial', role: 'Koordinator', active: true },
    { id: 'u4', name: 'Dimas Aksara', email: 'dimas@contoh.test', dept: 'Commercial', role: 'Staf', active: true },
    { id: 'u5', name: 'Dewi Wulandari', email: 'dewi@contoh.test', dept: 'Digital Campaign', role: 'Staf', active: true },
    { id: 'u6', name: 'Bayu Nugroho', email: 'bayu@contoh.test', dept: 'Customer Service', role: 'Staf', active: true },
    { id: 'u7', name: 'Sari Melati', email: 'sari@contoh.test', dept: 'Business Operation', role: 'Viewer', active: true }
  ];
  const departments = DEPTS.map((name, i) => ({ id: 'd' + (i + 1), name }));
  const g = { Executive: 'MMMMMMM|Ya|Ya', Commercial: 'MMRRRCM|Ya*|R*', 'Customer Service': 'RRRRRMR|Tidak|Tidak', 'Digital Campaign': 'CRRMRCN|Tidak|M', 'Creative & Media': 'RRRRMCN|Tidak|Tidak', CampusNet: 'CRMRCCN|Tidak|Tidak', DietPartner: 'CRMRCCN|Tidak|Tidak', 'Business Operation': 'RRRRRRR|Lihat*|Tidak' };
  const access = {};
  Object.keys(g).forEach(d => { const [m, nilai, rate] = g[d].split('|'); const o = { nilai, rate }; ENTS.forEach(([k], i) => { o[k] = m[i]; }); access[d] = o; });
  const mk = (id, name, stages) => ({ id, name, stages: stages.map((s, i) => ({ id: id + 's' + (i + 1), name: s })) });
  const lines = [
    mk('um', 'Umum', ['Lead', 'Qualified', 'Meeting', 'Proposal', 'Negotiation', 'Closing'])
  ];
  const R = [
    ['PT Sinar Kreasi Nusantara', 'Client', 'Korporat', 'u2', 'Commercial', 'Referensi', 'Andi Wirawan', 'Head of Marketing', 'Bandung'],
    ['Komunitas Lari Pagi Bandung', 'Community', 'Komunitas', 'u4', 'Commercial', 'Event', 'Fajar Ramadhan', 'Ketua', 'Bandung'],
    ['CV Arunika Media', 'Media', 'Media lokal', 'u3', 'Commercial', 'Media sosial', 'Laras Ayu', 'Owner', 'Jakarta'],
    ['Yayasan Tunas Cerdas', 'Client', 'Pendidikan', 'u3', 'Commercial', 'Kunjungan', 'Hendra Wijaya', 'Sekretaris', 'Cimahi'],
    ['PT Lintas Data Mandiri', 'Lead', 'Korporat', 'u2', 'Commercial', 'Website', 'Maya Kartika', 'IT Manager', 'Jakarta'],
    ['Klinik Sehat Sentosa', 'Client', 'Kesehatan', 'u4', 'Commercial', 'Referensi', 'dr. Putra', 'Direktur', 'Bandung'],
    ['Pasar Kreatif Cimahi', 'Community', 'UMKM', 'u4', 'Commercial', 'Event', 'Nina Sari', 'Koordinator', 'Cimahi'],
    ['Dimas Kreatif Studio', 'KOL/KOC', 'Lifestyle', 'u5', 'Digital Campaign', 'Media sosial', 'Dimas K.', 'Kreator', 'Bandung'],
    ['Kopi Senja Group', 'Lead', 'F&B', 'u3', 'Commercial', 'Website', 'Rizky Pratama', 'Brand Manager', 'Bandung'],
    ['Universitas Cakrawala', 'Lead', 'Pendidikan', 'u2', 'Commercial', 'Referensi', 'Dr. Lestari', 'Wakil Rektor', 'Bandung'],
    ['Nadia Rahma', 'KOL/KOC', 'Kuliner', 'u5', 'Digital Campaign', 'Media sosial', 'Nadia Rahma', 'Kreator', 'Jakarta'],
    ['Radio Suara Kota', 'Media', 'Radio', 'u3', 'Commercial', 'Kunjungan', 'Bagus H.', 'Program Director', 'Bandung'],
    ['PT Hijau Lestari Agro', 'Client', 'Korporat', 'u2', 'Commercial', 'Event', 'Sinta Dewi', 'HR Manager', 'Subang'],
    ['Komunitas Sepeda Kota', 'Community', 'Komunitas', 'u4', 'Commercial', 'Media sosial', 'Yoga P.', 'Ketua', 'Bandung'],
    ['Toko Buku Pelita', 'Lead', 'Retail', 'u4', 'Commercial', 'Kunjungan', 'Ibu Wati', 'Pemilik', 'Garut'],
    ['Bimbel Juara', 'Client', 'Pendidikan', 'u3', 'Commercial', 'Referensi', 'Agus S.', 'Direktur', 'Bandung'],
    ['Portal Berita Priangan', 'Media', 'Online', 'u5', 'Digital Campaign', 'Website', 'Reza F.', 'Redaktur', 'Tasikmalaya'],
    ['Raka Adventure', 'KOL/KOC', 'Travel', 'u5', 'Digital Campaign', 'Media sosial', 'Raka', 'Kreator', 'Bandung'],
    ['Sinar Kreasi Nusantara', 'Lead', 'Korporat', 'u4', 'Commercial', 'Event', 'Andi Wirawan', 'Marketing', 'Bandung']
  ];
  const records = R.map((r, i) => ({
    id: 'r' + (i + 1), code: 'CP-' + String(i + 1).padStart(4, '0'), name: r[0], cat: r[1], segment: r[2], picId: r[3], dept: r[4], source: r[5],
    contact: r[6], title: r[7], cEmail: r[6].toLowerCase().replace(/[^a-z]+/g, '.').replace(/^\.|\.$/g, '') + '@contoh.test', phone: '0812-0000-' + String(1000 + i * 37).slice(-4), city: r[8],
    rate: r[1] === 'KOL/KOC' ? (3 + i % 4) * 1500000 : '', note: '', createdAt: addDays(-120 + i * 5), updatedAt: addDays(-30 + i), archived: false
  }));
  records[18].cEmail = records[0].cEmail; records[18].note = 'Contoh data ganda buat dicoba fitur gabung data';
  records.forEach((r, i) => { r.contacts = [{ id: 'c' + (i + 1), name: r.contact, title: r.title, email: r.cEmail, phone: r.phone }]; });
  records[0].contacts.push({ id: 'c100', name: 'Lina Marlina', title: 'Procurement', email: 'lina.marlina@contoh.test', phone: '0812-0000-2201' });
  const I = [
    ['r1', 'Meeting', 'Presentasi proposal Website & CRM ke tim marketing', 'Kirim revisi proposal', -4, 'u2', -12],
    ['r2', 'WhatsApp', 'Bahas ide fun run bareng sponsor', 'Konfirmasi jadwal meeting kolaborasi', -3, 'u4', -9],
    ['r3', 'Telepon', 'Tanya paket konten Q4', 'Follow up penawaran paket konten', 0, 'u3', -5],
    ['r4', 'Kunjungan', 'Survey lokasi seminar', 'Telepon PIC buat brief kegiatan', 0, 'u3', -6],
    ['r8', 'WhatsApp', 'Minta rate card terbaru', 'Kirim brief campaign', 0, 'u5', -2],
    ['r5', 'Email', 'Kirim company profile', 'Meeting discovery kebutuhan ERP', 2, 'u2', -3],
    ['r9', 'Telepon', 'Perkenalan layanan event', 'Kirim contoh portofolio', 5, 'u3', -1],
    ['r6', 'Meeting', 'Serah terima aplikasi booking', '', null, 'u4', -20],
    ['r10', 'Email', 'Undangan diskusi digitalisasi kampus', 'Jadwalkan presentasi', 7, 'u2', -2],
    ['r13', 'Meeting', 'Gathering karyawan, bahas konsep', 'Kirim proposal gathering', -1, 'u2', -7],
    ['r16', 'WhatsApp', 'Tanya promo semester', 'Kirim penawaran video promosi', 3, 'u3', -4],
    ['r12', 'Kunjungan', 'Kenalan dengan tim program', '', null, 'u3', -15],
    ['r7', 'Telepon', 'Bazar akhir tahun', 'Kirim rundown bazar', 1, 'u4', -3],
    ['r11', 'WhatsApp', 'Diskusi konten kuliner', 'Kirim brief konten', 4, 'u5', -1]
  ];
  const interactions = I.map((x, i) => ({ id: 'i' + (i + 1), code: 'INT-' + String(i + 1).padStart(4, '0'), recordId: x[0], type: x[1], result: x[2], next: x[3], fuDate: x[4] === null ? '' : addDays(x[4]), done: false, cancelled: false, by: x[5], date: addDays(x[6]) }));
  const D = [
    ['Website & CRM', 'r1', 'um', 'ums5', 'Open', 120, '', 'u2', 20],
    ['ERP gudang', 'r5', 'um', 'ums3', 'Open', 210, '', 'u2', 45],
    ['Portal siswa', 'r16', 'um', 'ums2', 'Open', 48, '', 'u3', 30],
    ['Digitalisasi kampus', 'r10', 'um', 'ums1', 'Open', 180, '', 'u2', 60],
    ['Landing page kopi', 'r9', 'um', 'ums1', 'Open', 18, '', 'u3', 40],
    ['Aplikasi booking', 'r6', 'um', 'ums6', 'Won', 36, 36, 'u4', -10],
    ['Lari Pagi Bandung', 'r2', 'um', 'ums2', 'Open', 85, '', 'u4', 12],
    ['Gathering karyawan', 'r13', 'um', 'ums4', 'Open', 42, '', 'u2', 25],
    ['Seminar pendidikan', 'r4', 'um', 'ums5', 'Open', 28, '', 'u3', 10],
    ['Bazar akhir tahun', 'r7', 'um', 'ums1', 'Open', 19, '', 'u4', 50],
    ['Video promosi semester', 'r16', 'um', 'ums4', 'Open', 24, '', 'u3', 18],
    ['Paket konten Q4', 'r3', 'um', 'ums3', 'Open', 32, '', 'u3', 15],
    ['Jingle radio', 'r12', 'um', 'ums4', 'On Hold', 15, '', 'u3', 30]
  ];
  const deals = D.map((x, i) => ({ id: 'dl' + (i + 1), code: 'DL-' + String(i + 1).padStart(4, '0'), title: x[0], recordId: x[1], lineId: x[2], stageId: x[3], status: x[4], offer: x[5] * 1e6, value: x[6] === '' ? '' : x[6] * 1e6, ownerId: x[7], target: addDays(x[8]), closedAt: x[4] === 'Won' ? addDays(x[8]) : '', reason: x[4] === 'On Hold' ? 'Menunggu anggaran tahun depan' : '', reviewDate: x[4] === 'On Hold' ? addDays(21) : '', lostReason: '', service: x[0], createdAt: addDays(-40 + i) }));
  // riwayat closing 10 bulan terakhir buat grafik
  const hist = [[9, 'um', 22, 'u2'], [8, 'um', 31, 'u4'], [7, 'um', 18, 'u3'], [6, 'um', 40, 'u2'], [5, 'um', 36, 'u3'], [4, 'um', 28, 'u3'], [3, 'um', 45, 'u2'], [2, 'um', 38, 'u4'], [1, 'um', 41, 'u3'], [0, 'um', 15, 'u4'], [5, 'um', 0, 'u2'], [2, 'um', 0, 'u3']];
  const recIds = ['r13', 'r2', 'r3', 'r1', 'r4', 'r12', 'r16', 'r7', 'r6', 'r14', 'r15', 'r11'];
  hist.forEach((h, i) => {
    const line = lines.find(l => l.id === h[1]);
    const won = h[2] > 0;
    const d = monthsAgo(h[0], 8 + i);
    deals.push({ id: 'dh' + i, code: 'DL-H' + String(i + 1).padStart(3, '0'), title: won ? ['Event tahunan', 'Fun run sponsor', 'Konten promosi', 'Sistem absensi', 'Seminar', 'Iklan radio', 'Website sekolah', 'Bazar', 'Dashboard klinik', 'Gowes bareng'][i % 10] : 'Proposal lama', recordId: recIds[i], lineId: h[1], stageId: won ? line.stages[line.stages.length - 1].id : line.stages[3].id, status: won ? 'Won' : 'Lost', offer: (h[2] || 20) * 1e6, value: won ? h[2] * 1e6 : '', ownerId: h[3], target: d, closedAt: d, reason: won ? '' : 'Penawaran kompetitor lebih murah', lostReason: won ? '' : (i % 2 ? 'Pilih vendor lain' : 'Harga di atas anggaran'), reviewDate: '', service: '', createdAt: monthsAgo(h[0] + 1, 5) });
  });
  deals.forEach(dl => {
    const line = lines[0]; const st = line.stages.find(s => s.id === dl.stageId);
    dl.history = [{ at: dl.createdAt + 'T09:00:00', by: dl.ownerId, from: '', to: dl.status === 'Won' ? line.stages[3].name : st.name, status: 'Open', note: 'Deal dibuat' }];
    if (dl.status === 'Won') dl.history.push({ at: dl.closedAt + 'T15:00:00', by: dl.ownerId, from: line.stages[4].name, to: 'Closing', status: 'Won', note: 'Closing' });
    if (dl.status === 'Lost') dl.history.push({ at: dl.closedAt + 'T15:00:00', by: dl.ownerId, from: st.name, to: st.name, status: 'Lost', note: dl.lostReason });
    if (dl.status === 'On Hold') dl.history.push({ at: addDays(-3) + 'T10:00:00', by: dl.ownerId, from: st.name, to: st.name, status: 'On Hold', note: dl.reason });
  });
  const audit = [
    { id: 'a1', at: new Date(Date.now() - 864e5 * 2).toISOString(), by: 'u1', action: 'Import', entity: 'Client & Partner', detail: '18 data contoh dimuat' },
    { id: 'a2', at: new Date(Date.now() - 864e5).toISOString(), by: 'u2', action: 'Ubah', entity: 'Deal', detail: 'Website & CRM pindah ke Negotiation' }
  ];
  const news = ['Template data klien udah bisa diisi', 'Mode uji: semua data di sini contoh dan cuma tersimpan di browser ini', 'Stage tiap lini bisnis bisa ditambah dari halaman Pipeline', 'Akses & Audit ada di menu profil'];
  return { v: 3, users, departments, access, lines, records, interactions, deals, audit, news, options: JSON.parse(JSON.stringify(DEFAULT_OPTS)), overrides: [], views: [], imports: [], notifs: [], changes: [], session: null, sessionStart: 0, lastActive: 0, seq: { r: records.length, i: interactions.length, d: deals.length } };
}

const Store = {
  db: null,
  mem: null,
  load() {
    try { const raw = localStorage.getItem(DB_KEY); if (raw) { this.db = JSON.parse(raw); return; } } catch (e) { /* storage tidak tersedia */ }
    this.db = this.mem || seed();
  },
  save() { this.mem = this.db; try { localStorage.setItem(DB_KEY, JSON.stringify(this.db)); } catch (e) { /* abaikan */ } },
  reset() { const s = this.db && this.db.session; this.db = seed(); this.db.session = s; this.save(); }
};

const me = () => Store.db.users.find(u => u.id === Store.db.session) || null;
const userById = (id) => Store.db.users.find(u => u.id === id);
const recById = (id) => Store.db.records.find(r => r.id === id);
const lineById = (id) => Store.db.lines.find(l => l.id === id);
const stageOf = (deal) => { const l = lineById(deal.lineId); return l && l.stages.find(s => s.id === deal.stageId); };
const isClosingStage = (line, stageId) => line.stages.length && line.stages[line.stages.length - 1].id === stageId;

const overrideOf = (u, k) => (Store.db.overrides || []).find(o => o.userId === u.id && o.key === k);
function perm(ent, u) {
  u = u || me(); if (!u) return 'N';
  if (u.role === 'Super Admin') return 'M';
  const ov = overrideOf(u, ent); if (ov) return ov.value;
  const a = Store.db.access[u.dept]; let p = (a && a[ent]) || 'N';
  if (u.role === 'Viewer' && p !== 'N') p = 'R';
  return p;
}
const can = (ent, lvl) => LV[perm(ent)] >= LV[lvl];
const catEnt = (cat) => CAT_KEY[cat] || 'lead';
const canSeeRec = (r) => can(catEnt(r.cat), 'R');
const canEditRec = (r) => { const p = perm(catEnt(r.cat)); return p === 'M' || (p === 'C' && r.picId === me().id); };
const canDelRec = (r) => perm(catEnt(r.cat)) === 'M';
const canCreateAny = () => CATS.some(c => can(catEnt(c), 'C'));
const isLead = (u) => ['Kepala Department', 'Koordinator'].includes(u.role);
function canSeeValue(deal) {
  const u = me(); if (u.role === 'Super Admin') return true;
  if (deal && deal.ownerId === u.id) return true;
  const ov = overrideOf(u, 'nilai'); if (ov) return ov.value === 'Ya';
  const v = (Store.db.access[u.dept] || {}).nilai || 'Tidak';
  if (v === 'Ya') return true;
  if (v === 'Ya*' || v === 'Lihat*') return isLead(u);
  return false;
}
function canSeeRate() {
  const u = me(); if (u.role === 'Super Admin') return true;
  const ov = overrideOf(u, 'rate'); if (ov) return ov.value === 'Ya';
  const v = (Store.db.access[u.dept] || {}).rate || 'Tidak';
  return v === 'M' || (v === 'R*' && isLead(u));
}
const canEditDeal = (d) => { const p = perm('deal'); return p === 'M' || (p === 'C' && d.ownerId === me().id); };
const canConfigPipeline = () => ['Super Admin', 'Kepala Department'].includes(me().role);
const canAdmin = () => me().role === 'Super Admin';
const canReopen = (d) => { const u = me(); if (d.status === 'Won') return ['Super Admin', 'Kepala Department'].includes(u.role); return canEditDeal(d) && (d.ownerId === u.id || isLead(u) || u.role === 'Super Admin'); };
const canMovePic = (r) => canEditRec(r) && (perm(catEnt(r.cat)) === 'M' || isLead(me()));
const liveRecs = () => Store.db.records.filter(r => !r.archived);
const liveInts = () => Store.db.interactions.filter(i => !i.cancelled);
function trackChange(kind, id, field, before, after, note) {
  if (String(before == null ? '' : before) === String(after == null ? '' : after)) return;
  const u = me(); Store.db.changes = Store.db.changes || [];
  Store.db.changes.unshift({ id: uid('ch'), at: new Date().toISOString(), by: u ? u.id : '-', kind, refId: id, field, before: before == null ? '' : String(before), after: after == null ? '' : String(after), note: note || '' });
  if (Store.db.changes.length > 2000) Store.db.changes.length = 2000;
}
function notify(userId, text, link) {
  if (!userId || (me() && userId === me().id)) return;
  Store.db.notifs = Store.db.notifs || [];
  Store.db.notifs.unshift({ id: uid('n'), userId, at: new Date().toISOString(), text, link: link || '', read: false });
}
function dealLog(d, from, to, status, note) {
  d.history = d.history || [];
  d.history.push({ at: new Date().toISOString(), by: me() ? me().id : '-', from: from || '', to: to || '', status, note: note || '' });
}
const canViewAdmin = () => ['Super Admin', 'Kepala Department'].includes(me().role);

function audit(action, entity, detail) {
  const u = me();
  Store.db.audit.unshift({ id: uid('a'), at: new Date().toISOString(), by: u ? u.id : '-', action, entity, detail });
  if (Store.db.audit.length > 800) Store.db.audit.length = 800;
}
function nextCode(kind) {
  const s = Store.db.seq; s[kind] = (s[kind] || 0) + 1;
  return ({ r: 'CP-', i: 'INT-', d: 'DL-' })[kind] + String(s[kind]).padStart(4, '0');
}
