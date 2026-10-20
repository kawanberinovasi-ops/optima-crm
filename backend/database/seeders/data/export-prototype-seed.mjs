// Ekspor data contoh prototipe (assets/data.js, fungsi seed()) ke sample.json untuk SampleDataSeeder.
// Tanggal disimpan relatif terhadap hari ini supaya data selalu "segar" saat di-seed ulang.
// Jalankan dari folder backend:  node database/seeders/data/export-prototype-seed.mjs
import { readFileSync, writeFileSync } from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const here = fileURLToPath(new URL('.', import.meta.url));
const src = readFileSync(new URL('../../../../assets/data.js', import.meta.url), 'utf8');
const ctx = { console, Date, Math, JSON, String, Number, Object, Array, localStorage: undefined };
vm.createContext(ctx);
vm.runInContext(src + '\n;this.__seed = seed();', ctx);
const db = ctx.__seed;

const today = new Date(); today.setHours(0, 0, 0, 0);
const off = (iso) => (iso ? Math.round((new Date(iso.slice(0, 10) + 'T00:00:00') - today) / 864e5) : null);
const userEmail = Object.fromEntries(db.users.map((u) => [u.id, u.email]));
const recCode = Object.fromEntries(db.records.map((r) => [r.id, r.code]));
const stageName = Object.fromEntries(db.lines.flatMap((l) => l.stages.map((s) => [s.id, s.name])));
const lineName = Object.fromEntries(db.lines.map((l) => [l.id, l.name]));

// Deal riwayat closing (id 'dh*') dibuat dengan monthsAgo(bulan, tanggal): simpan bentuk itu, bukan selisih hari,
// supaya grafik 12 bulan tetap jatuh di bulan yang sama.
const histSpec = {};
const m = src.match(/const hist = (\[[\s\S]*?\]);/);
JSON.parse(m[1].replace(/'/g, '"')).forEach((h, i) => { histSpec['dh' + i] = { months_ago: h[0], day: Math.min(8 + i, 28) }; });

const out = {
  generated_from: 'assets/data.js seed()',
  departments: db.departments.map((d) => d.name),
  access: db.access,
  options: db.options,
  users: db.users.map((u) => ({ name: u.name, email: u.email, department: u.dept, role: u.role, active: u.active })),
  pipelines: db.lines.map((l) => ({ name: l.name, stages: l.stages.map((s) => s.name) })),
  client_partners: db.records.map((r) => ({
    code: r.code, name: r.name, category: r.cat, segment: r.segment || null, pic: userEmail[r.picId], department: r.dept,
    source: r.source || null, city: r.city || null, note: r.note || null,
    rate_card: r.rate ? { rate: r.rate } : null,
    created_days: off(r.createdAt), updated_days: off(r.updatedAt),
    contacts: r.contacts.map((c, i) => ({ name: c.name, title: c.title || null, email: c.email || null, phone: c.phone || null, is_primary: i === 0 }))
  })),
  interactions: db.interactions.map((x) => ({
    code: x.code, client_partner: recCode[x.recordId], user: userEmail[x.by], type: x.type, result: x.result,
    next_action: x.next || null, occurred_days: off(x.date), followup_days: x.fuDate ? off(x.fuDate) : null
  })),
  deals: db.deals.map((d) => ({
    code: d.code, title: d.title, service: d.service || null, client_partner: recCode[d.recordId], pipeline: lineName[d.lineId],
    stage: stageName[d.stageId], status: d.status, owner: userEmail[d.ownerId],
    offer_value: d.offer === '' ? null : d.offer, deal_value: d.value === '' ? null : d.value,
    lost_reason: d.lostReason || null, hold_reason: d.status === 'On Hold' ? (d.reason || null) : null,
    ...(histSpec[d.id]
      ? { history_month: histSpec[d.id] }
      : { created_days: off(d.createdAt), target_days: off(d.target), closed_days: off(d.closedAt), review_days: off(d.reviewDate) })
  })),
  news: db.news
};
writeFileSync(here + 'sample.json', JSON.stringify(out, null, 2) + '\n');
console.log(`sample.json: ${out.users.length} pengguna, ${out.client_partners.length} client & partner, ${out.interactions.length} interaksi, ${out.deals.length} deal`);
