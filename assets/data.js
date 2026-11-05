/* OPTIMA CRM · data contoh, penyimpanan lokal, dan hak akses */
'use strict';
// Dengan backend (window.OPTIMA_API), cache browser dipisah dari data contoh mode lokal.
// v4: status relasi (Lead/Client) dipisah dari layanan, akses per divisi. Data contoh lama di browser direset sekali.
const DB_KEY = window.OPTIMA_API ? 'optima_crm_backend_v5' : 'optima_crm_local_v7';
// Commercial dilebur ke Customer Service (keputusan 22 Okt 2026, mengikuti pratinjau klien).
const DEPTS = ['Executive', 'Customer Service', 'Digital Campaign', 'Creative & Media', 'CampusNet', 'DietPartner', 'Business Operation'];
const SEED_DEPTS = ['Executive', 'Commercial'].concat(DEPTS.slice(1));
const ROLES = ['Super Admin', 'Kepala Department', 'Koordinator', 'Staf', 'Viewer'];
// Status relasi. Data baru selalu Lead; jadi Client hanya lewat deal Won.
const CATS = ['Lead', 'Client', 'Partner'];
const CAT_KEY = { 'Lead': 'lead', 'Client': 'client', 'Partner': 'client' };
/** Menu Client (Lead dan Client) dan menu Partner dipisah (revisi klien 5 Nov 2026). */
const cpScopeCats = () => (typeof App !== 'undefined' && App.route === 'partner') ? ['Partner'] : ['Lead', 'Client'];
// Layanan bukan status. Pemetaan ke divisi bisa diubah admin (KOL dan Community: Digital Campaign, sementara).
const DEFAULT_SERVICES = [['KOL', 'Digital Campaign'], ['Community', 'Digital Campaign'], ['Media', 'Creative & Media'], ['Digital Campaign', 'Digital Campaign'], ['CampusNet', 'CampusNet'], ['DietPartner', 'DietPartner']];
const serviceList = () => ((Store.db && Store.db.services) || DEFAULT_SERVICES.map(([name, dept], i) => ({ id: 's' + (i + 1), name, dept, active: true }))).filter(x => x.active !== false);
const ENTS = [['lead', 'Lead'], ['client', 'Client'], ['interaksi', 'Interaksi'], ['deal', 'Deal']];
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

/** Hak akses awal per divisi: Executive dan Customer Service mengelola, divisi lain menambah dan mengubah datanya sendiri. */
function defaultDivisionAccess(dept) {
  const manage = dept === 'Executive' || dept === 'Customer Service';
  const lvl = manage ? 'M' : 'C';
  return { lead: lvl, client: lvl, interaksi: lvl, deal: lvl, nilai: dept === 'Executive' ? 'Ya' : 'Ya*', rate: dept === 'Digital Campaign' || dept === 'Executive' ? 'M' : 'Tidak' };
}
const PIPELINE_STAGES = ['Lead', 'Qualified', 'Meeting', 'Proposal', 'Negotiation', 'Closing'];
const CAMPAIGN_STAGES = ['Brief', 'Konsep', 'Produksi', 'Tayang', 'Report', 'Closing'];
/**
 * Struktur divisi untuk data contoh (sama dengan pratinjau klien di GitHub Pages):
 * Commercial jadi Customer Service, satu pipeline per divisi, layanan diturunkan dari data, relasi berlayanan
 * dipegang divisi layanannya, deal pindah ke pipeline divisi relasinya, dan relasi dengan deal Won jadi Client.
 * Bedanya: PIC selalu orang di divisi pemilik (di pratinjau, beberapa data CS masih ber-PIC orang divisi lain).
 */
function divisionWorkflow(db) {
  db.services = DEFAULT_SERVICES.map(([name, dept], i) => ({ id: 's' + (i + 1), name, dept, active: true }));
  const svcDept = (name) => (db.services.find(x => x.name === name) || {}).dept;
  db.departments = DEPTS.map((name, i) => ({ id: 'd' + (i + 1), name, seesAll: name === 'Executive' }));
  db.users.forEach(u => {
    if (u.dept === 'Commercial') u.dept = 'Customer Service';
    if (u.email === 'ayu@contoh.test') u.dept = 'Creative & Media';
    if (u.email === 'dimas@contoh.test') u.dept = 'CampusNet';
  });
  db.users.push({ id: 'u8', name: 'Tim DietPartner', email: 'dietpartner@contoh.test', dept: 'DietPartner', role: 'Kepala Department', active: true });
  db.users.push({ id: 'u9', name: 'Kepala Digital Campaign', email: 'kepala.digital@contoh.test', dept: 'Digital Campaign', role: 'Kepala Department', active: true });
  db.access = {}; DEPTS.forEach(d => { db.access[d] = defaultDivisionAccess(d); });
  // Revisi klien 5 Nov 2026: setiap divisi punya pipeline sendiri. Digital Campaign memakai pipeline Campaign (stage kampanye).
  const old = db.lines[0];
  const mkLine = (id, name, depts, stages) => ({ id, name, depts, stages: stages.map((n, i) => ({ id: id + '-s' + i, name: n })) });
  db.lines = DEPTS.map(dept => dept === 'Digital Campaign'
    ? mkLine('campaign', 'Campaign', [dept], CAMPAIGN_STAGES)
    : mkLine('line-' + dept.toLowerCase().replace(/[^a-z0-9]+/g, '-'), dept, [dept], PIPELINE_STAGES));
  db.lineUsers = [];
  const lineOf = (dept) => db.lines.find(l => l.depts.includes(dept));
  const firstOf = (dept) => db.users.find(u => u.active && u.role !== 'Viewer' && u.dept === dept);
  const infer = (r) => {
    if (r.service) return r.service;
    const text = db.deals.filter(d => d.recordId === r.id).map(d => d.service + ' ' + d.title).join(' ').toLowerCase();
    if (/website|erp|aplikasi|portal siswa|sistem|digitalisasi|dashboard/.test(text)) return 'CampusNet';
    if (/konten|video|jingle|iklan radio/.test(text)) return 'Media';
    if (/event|gathering|seminar|bazar|fun run|gowes|lari pagi/.test(text)) return 'Community';
    return '';
  };
  db.records.forEach(r => {
    r.service = infer(r);
    if (r.dept === 'Commercial') r.dept = 'Customer Service';
    if (r.service) r.dept = svcDept(r.service);
    const pic = db.users.find(u => u.id === r.picId);
    if (!pic || pic.dept !== r.dept) { const next = firstOf(r.dept); if (next) r.picId = next.id; }
    r.handovers = [];
  });
  db.deals.forEach(d => {
    const r = db.records.find(x => x.id === d.recordId); if (!r) return;
    const index = Math.max(0, old.stages.findIndex(s => s.id === d.stageId));
    const line = lineOf(r.dept);
    d.lineId = line.id;
    d.stageId = d.status === 'Won' ? line.stages[line.stages.length - 1].id : line.stages[Math.min(index, line.stages.length - 2)].id;
    d.ownerId = r.picId;
    (d.history || []).forEach(h => { if (h.by && !db.users.some(u => u.id === h.by && u.dept === r.dept)) h.by = r.picId; });
    if (d.status === 'Won' && r.cat === 'Lead') { r.cat = 'Client'; r.convertedAt = d.closedAt; }
  });
  db.records.forEach(r => { const w = db.deals.filter(d => d.recordId === r.id && d.status === 'Won').map(d => d.closedAt).sort()[0]; if (w) r.convertedAt = w; });
  // Contoh Partner (revisi klien): vendor dan media partner, tanpa tahap Lead.
  [['Sound System Harmoni', 'Vendor', 'u6', 'Customer Service', 'Hendro S.', 'Pemilik', 'Bandung'], ['Majalah Kampus Kita', 'Media partner', 'u5', 'Digital Campaign', 'Tia Lestari', 'Pemimpin Redaksi', 'Bandung']]
    .forEach(([name, segment, picId, dept, contact, title, city], i) => {
      const n = db.records.length + 1; const id = 'r' + n; const cEmail = contact.toLowerCase().replace(/[^a-z]+/g, '.').replace(/^\.|\.$/g, '') + '@contoh.test';
      db.records.push({ id, code: 'CP-' + String(n).padStart(4, '0'), name, cat: 'Partner', service: '', createdBy: picId, segment, picId, dept, source: 'Referensi', contact, title, cEmail, phone: '0812-0000-' + String(3100 + i * 7), city, rate: '', note: '', createdAt: addDays(-40 + i * 9), updatedAt: addDays(-5 - i), archived: false, handovers: [], contacts: [{ id: 'cp' + n, name: contact, title, email: cEmail, phone: '0812-0000-' + String(3100 + i * 7) }] });
    });
  db.seq.r = db.records.length;
  return db;
}

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
  const departments = SEED_DEPTS.map((name, i) => ({ id: 'd' + (i + 1), name, seesAll: name === 'Executive' }));
  const g = { Executive: 'MMMM|Ya|Ya', Commercial: 'MMCM|Ya*|R*', 'Customer Service': 'CRMR|Tidak|Tidak', 'Digital Campaign': 'CRCN|Tidak|M', 'Creative & Media': 'CRCN|Tidak|Tidak', CampusNet: 'CRCN|Tidak|Tidak', DietPartner: 'CRCN|Tidak|Tidak', 'Business Operation': 'RRRR|Lihat*|Tidak' };
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
  // Kolom kedua data contoh lama berisi kategori; KOL/KOC, Community, dan Media sekarang layanan dengan status Lead.
  const LEGACY = { 'KOL/KOC': 'KOL', Community: 'Community', Media: 'Media' };
  const records = R.map((r, i) => ({
    id: 'r' + (i + 1), code: 'CP-' + String(i + 1).padStart(4, '0'), name: r[0], cat: LEGACY[r[1]] ? 'Lead' : r[1], service: LEGACY[r[1]] || '', createdBy: r[3], segment: r[2], picId: r[3], dept: r[4], source: r[5],
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
  // kolom: judul, data, lini, stage, status, penawaran (jt), nilai (jt), PIC, target (hari), dibuat (hari)
  const D = [
    ['Website & CRM', 'r1', 'um', 'ums5', 'Open', 120, '', 'u2', 20, -25],
    ['ERP gudang', 'r5', 'um', 'ums3', 'Open', 210, '', 'u2', 45, -75],
    ['Portal siswa', 'r16', 'um', 'ums2', 'Open', 48, '', 'u3', 30, -20],
    ['Digitalisasi kampus', 'r10', 'um', 'ums1', 'Open', 180, '', 'u2', 60, -110],
    ['Landing page kopi', 'r9', 'um', 'ums1', 'Open', 18, '', 'u3', 40, -8],
    ['Aplikasi booking', 'r6', 'um', 'ums6', 'Won', 36, 36, 'u4', -10, -60],
    ['Lari Pagi Bandung', 'r2', 'um', 'ums2', 'Open', 85, '', 'u4', 12, -14],
    ['Gathering karyawan', 'r13', 'um', 'ums4', 'Open', 42, '', 'u2', 25, -40],
    ['Seminar pendidikan', 'r4', 'um', 'ums5', 'Open', 28, '', 'u3', 10, -3],
    ['Bazar akhir tahun', 'r7', 'um', 'ums1', 'Open', 19, '', 'u4', 50, -95],
    ['Video promosi semester', 'r16', 'um', 'ums4', 'Open', 24, '', 'u3', 18, -5],
    ['Paket konten Q4', 'r3', 'um', 'ums3', 'Open', 32, '', 'u3', 15, -30],
    ['Jingle radio', 'r12', 'um', 'ums4', 'On Hold', 15, '', 'u3', 30, -50]
  ];
  const deals = D.map((x, i) => ({ id: 'dl' + (i + 1), code: 'DL-' + String(i + 1).padStart(4, '0'), title: x[0], recordId: x[1], lineId: x[2], stageId: x[3], status: x[4], offer: x[5] * 1e6, value: x[6] === '' ? '' : x[6] * 1e6, ownerId: x[7], target: addDays(x[8]), closedAt: x[4] === 'Won' ? addDays(x[8]) : '', reason: x[4] === 'On Hold' ? 'Menunggu anggaran tahun depan' : '', reviewDate: x[4] === 'On Hold' ? addDays(21) : '', lostReason: '', service: x[0], createdAt: addDays(x[9]) }));
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
  const db = { v: 4, users, departments, access, lines, records, interactions, deals, audit, news, options: JSON.parse(JSON.stringify(DEFAULT_OPTS)), overrides: [], views: [], imports: [], notifs: [], changes: [], session: null, sessionStart: 0, lastActive: 0, seq: { r: records.length, i: interactions.length, d: deals.length } };
  return divisionWorkflow(db);
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
const userById = (id) => Store.db.users.find(u => u.id === id) || (Store.db.deletedUsers || []).find(u => u.id === id);
const recById = (id) => Store.db.records.find(r => r.id === id);
const lineById = (id) => Store.db.lines.find(l => l.id === id);
const stageOf = (deal) => { const l = lineById(deal.lineId); return l && l.stages.find(s => s.id === deal.stageId); };
const isClosingStage = (line, stageId) => line.stages.length && line.stages[line.stages.length - 1].id === stageId;

const overrideOf = (u, k) => (Store.db.overrides || []).find(o => o.userId === u.id && o.key === k);
function perm(ent, u) {
  u = u || me(); if (!u) return 'N';
  if (u.role === 'Super Admin') return 'M';
  // Department ber-level kelola (Executive) mengelola data yang dipegangnya; Viewer-nya tetap hanya melihat.
  if ((Store.db.departments.find(d => d.name === u.dept) || {}).seesAll) return u.role === 'Viewer' ? 'R' : 'M';
  const ov = overrideOf(u, ent); if (ov) return u.role === 'Viewer' && ov.value !== 'N' ? 'R' : ov.value;
  const a = Store.db.access[u.dept]; let p = (a && a[ent]) || 'N';
  if (u.role === 'Viewer' && p !== 'N') p = 'R';
  return p;
}
const can = (ent, lvl) => LV[perm(ent)] >= LV[lvl];
const catEnt = (cat) => CAT_KEY[cat] || 'lead';
/** Revisi klien 5 Nov 2026: hanya Super Admin yang melihat seluruh data Client & Partner. */
const seesAll = (u) => !!u && u.role === 'Super Admin';
/** Data yang PIC-nya pengguna ini (Super Admin: semua). Di mode backend server sudah menyaring; ini untuk tampilan dan mode lokal. */
const canSeeRec = (r) => !r.hidden && can(catEnt(r.cat), 'R') && (seesAll(me()) || r.picId === me().id);
/** Mengubah data yang boleh dilihat, dengan level C atau M. */
const canEditRec = (r) => canSeeRec(r) && LV[perm(catEnt(r.cat))] >= LV.C;
const canDelRec = (r) => canSeeRec(r) && perm(catEnt(r.cat)) === 'M';
/** Data baru selalu Lead, jadi yang menentukan adalah hak input Lead. */
const canCreateAny = () => can('lead', 'C');
const isLead = (u) => ['Kepala Department', 'Koordinator'].includes(u.role);
function canSeeValue(deal) {
  const u = me(); if (!u) return false; if (deal && !canSeeDeal(deal)) return false; if (seesAll(u)) return true;
  if (deal && deal.ownerId === u.id) return true;
  const ov = overrideOf(u, 'nilai'); if (ov) return ov.value === 'Ya';
  const v = (Store.db.access[u.dept] || {}).nilai || 'Tidak';
  if (v === 'Ya') return true;
  if (v === 'Ya*' || v === 'Lihat*') return isLead(u);
  return false;
}
function canSeeRate() {
  const u = me(); if (!u) return false; if (seesAll(u)) return true;
  const ov = overrideOf(u, 'rate'); if (ov) return ov.value === 'Ya';
  const v = (Store.db.access[u.dept] || {}).rate || 'Tidak';
  return v === 'M' || (v === 'R*' && isLead(u));
}
/** Deal terlihat kalau boleh melihat deal dan relasinya ada di cakupan divisi. */
const canSeeDeal = (d) => !!d && can('deal', 'R') && (() => { const r = recById(d.recordId); return !r || canSeeRec(r); })();
const canEditDeal = (d) => canSeeDeal(d) && can('deal', 'C');
/** Membuat dan mengatur pipeline serta stage: hanya Super Admin. */
const canConfigPipeline = () => !!me() && me().role === 'Super Admin';
const canAdmin = () => me().role === 'Super Admin';
const canReopen = (d) => { if (!canEditDeal(d)) return false; const u = me(); if (d.status === 'Won') return ['Super Admin', 'Kepala Department'].includes(u.role); return canEditDeal(d) && (d.ownerId === u.id || isLead(u) || u.role === 'Super Admin'); };
/** Pindah PIC / divisi ke arah mana pun: siapa pun yang boleh mengubah data itu. */
const canHandover = (r) => canEditRec(r);
const canMovePic = canHandover;
/** Deal mengikuti divisi pemilik relasinya. */
const scopedDeals = () => Store.db.deals.filter(d => !me() || canSeeDeal(d));
/**
 * Pipeline yang dipakai pengguna: pipeline divisinya, ditambah atau dikurangi pengecualian per pengguna dari Super Admin.
 * Super Admin memakai semua pipeline.
 */
function linesFor(u) {
  if (!u) return [];
  const all = (Store.db.lines || []).filter(l => !l.archived);
  if (u.role === 'Super Admin') return all;
  const ov = (Store.db.lineUsers || []).filter(x => x.userId === u.id);
  return all.filter(l => { const o = ov.find(x => x.lineId === l.id); return o ? o.allowed : (l.depts || []).includes(u.dept); });
}
const visibleLines = () => linesFor(me());
const canManageLine = (line) => !!line && canConfigPipeline();
/** Pipeline bawaan untuk deal milik PIC ini. */
const lineForUser = (u, wanted) => { const ls = linesFor(u); return ls.find(l => l.id === wanted) || ls[0] || (Store.db.lines || []).find(l => !l.archived); };
const divisionUsers = (dept) => Store.db.users.filter(u => u.active && u.role !== 'Viewer' && u.dept === dept);
const liveRecs = () => Store.db.records.filter(r => !r.archived);
const liveInts = () => Store.db.interactions.filter(i => { if (i.cancelled) return false; const r = recById(i.recordId); return !r || !me() || canSeeRec(r); });
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
