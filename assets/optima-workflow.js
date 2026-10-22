/* Lead lifecycle and division ownership. Demo rules; production enforcement needs a server. */
'use strict';
const WORKFLOW_VERSION = 1;
const DEFAULT_SERVICES = [
  { id: 'kol', name: 'KOL', dept: 'Digital Campaign' },
  { id: 'community', name: 'Community', dept: 'Digital Campaign' },
  { id: 'media', name: 'Media', dept: 'Creative & Media' },
  { id: 'digital-campaign', name: 'Digital Campaign', dept: 'Digital Campaign' },
  { id: 'campusnet', name: 'CampusNet', dept: 'CampusNet' },
  { id: 'dietpartner', name: 'DietPartner', dept: 'DietPartner' }
];
const serviceById = id => (Store.db.services || []).find(s => s.id === id);
const serviceName = id => (serviceById(id) || {}).name || 'Belum ditentukan';
const isExecutive = (u = me()) => !!u && (u.role === 'Super Admin' || u.dept === 'Executive');
const hasDivisionScope = (r, u = me()) => !!r && !!u && u.active && (isExecutive(u) || r.dept === u.dept);
const canSeeDeal = d => !!d && can('deal', 'R') && canSeeRec(recById(d.recordId));
const visibleLines = () => (Store.db.lines || []).filter(l => !l.legacy && (isExecutive() || l.dept === (me() || {}).dept));
const canManageLine = line => !!line && canConfigPipeline() && (isExecutive() || line.dept === me().dept);
const divisionUsers = dept => Store.db.users.filter(u => u.active && u.role !== 'Viewer' && u.dept === dept);
function workflowNotificationVisible(n) {
  const [kind, id] = (n.link || '').split(':');
  if (kind === 'rec') return canSeeRec(recById(id));
  if (kind === 'deal') return canSeeDeal(Store.db.deals.find(d => d.id === id));
  return true;
}

function defaultDivisionAccess(dept) {
  const manage = dept === 'Executive' || dept === 'Customer Service';
  return { lead: manage ? 'M' : 'C', client: manage ? 'M' : 'C', interaksi: manage ? 'M' : 'C', deal: manage ? 'M' : 'C', nilai: dept === 'Executive' ? 'Ya' : 'Ya*', rate: dept === 'Digital Campaign' || dept === 'Executive' ? 'M' : 'Tidak' };
}
function lineForDivision(dept) {
  let line = Store.db.lines.find(l => !l.legacy && l.dept === dept);
  if (!line) {
    const id = 'division-' + dept.toLowerCase().replace(/[^a-z0-9]+/g, '-');
    line = { id, name: dept, dept, stages: ['Lead','Qualified','Meeting','Proposal','Negotiation','Closing'].map((name, i) => ({ id: id + '-s' + i, name })) };
    Store.db.lines.push(line);
  }
  return line;
}
function inferLegacyService(r, deals) {
  const old = { 'KOL/KOC': 'kol', KOL: 'kol', Community: 'community', Media: 'media', 'Digital Campaign': 'digital-campaign' }[r.cat];
  if (old) return old;
  const text = deals.filter(d => d.recordId === r.id).map(d => d.service + ' ' + d.title).join(' ').toLowerCase();
  if (/website|erp|aplikasi|portal siswa|sistem|digitalisasi|dashboard/.test(text)) return 'campusnet';
  if (/konten|video|jingle|iklan radio/.test(text)) return 'media';
  if (/event|gathering|seminar|bazar|fun run|gowes|lari pagi/.test(text)) return 'community';
  if (r.dept === 'DietPartner') return 'dietpartner';
  if (r.dept === 'Digital Campaign') return 'digital-campaign';
  return '';
}
function migrateWorkflow(db) {
  if (db.workflowVersion >= WORKFLOW_VERSION) return false;
  db.services = db.services || DEFAULT_SERVICES.map(s => ({ ...s }));
  db.departments = db.departments || [];
  const commercial = db.departments.find(d => d.name === 'Commercial');
  if (commercial) { commercial.previousName = 'Commercial'; commercial.name = 'Customer Service'; }
  db.departments = db.departments.filter((d, i, all) => all.findIndex(x => x.name === d.name) === i);
  DEPTS.forEach((name, i) => { if (!db.departments.some(d => d.name === name)) db.departments.push({ id: 'workflow-dept-' + i, name }); });
  db.users.forEach(u => {
    if (u.dept === 'Commercial') { u.previousDept = u.dept; u.dept = 'Customer Service'; }
    if (u.email === 'ayu@contoh.test' && u.dept === 'Customer Service') u.dept = 'Creative & Media';
    if (u.email === 'dimas@contoh.test' && u.dept === 'Customer Service') u.dept = 'CampusNet';
  });
  if (!db.users.some(u => u.dept === 'DietPartner' && u.active)) db.users.push({ id: 'demo-dietpartner', name: 'Tim DietPartner', email: 'dietpartner@contoh.test', dept: 'DietPartner', role: 'Kepala Department', active: true });
  if (!db.users.some(u => u.dept === 'Digital Campaign' && u.role === 'Kepala Department')) db.users.push({ id: 'demo-digital-lead', name: 'Kepala Digital Campaign', email: 'kepala.digital@contoh.test', dept: 'Digital Campaign', role: 'Kepala Department', active: true });
  db.access = db.access || {};
  db.departments.forEach(d => { db.access[d.name] = defaultDivisionAccess(d.name); });
  db.lines.forEach(l => { if (!l.dept) l.legacy = true; });
  db.departments.forEach(d => lineForDivision(d.name));
  db.records.forEach(r => {
    const previousCategory = r.cat;
    r.serviceId = r.serviceId || inferLegacyService(r, db.deals);
    if (r.dept === 'Commercial') { r.previousDept = r.dept; r.dept = 'Customer Service'; }
    const svc = serviceById(r.serviceId);
    if (!['Lead','Client'].includes(r.cat)) { r.legacyCategory = previousCategory; r.cat = db.deals.some(d => d.recordId === r.id && d.status === 'Won') ? 'Client' : 'Lead'; }
    if (svc && /^r\d+$/.test(r.id) && r.contacts && r.contacts.some(c => /@contoh\.test$/.test(c.email || ''))) {
      r.dept = svc.dept;
      const pic = userById(r.picId);
      if (!pic || pic.dept !== r.dept) {
        const next = divisionUsers(r.dept)[0];
        if (next) { r.previousPicId = r.picId; r.picId = next.id; }
      }
    }
    r.service = serviceName(r.serviceId);
    r.handoffs = r.handoffs || [];
  });
  db.deals.forEach(d => {
    const r = db.records.find(x => x.id === d.recordId);
    if (!r) return;
    const oldLine = db.lines.find(l => l.id === d.lineId);
    const index = Math.max(0, (oldLine && oldLine.stages || []).findIndex(s => s.id === d.stageId));
    const line = lineForDivision(r.dept);
    if (!oldLine || oldLine.legacy) {
      d.previousLineId = d.lineId; d.lineId = line.id;
      d.stageId = d.status === 'Won' ? line.stages[line.stages.length - 1].id : line.stages[Math.min(index, line.stages.length - 2)].id;
    }
    if (/^(dl\d+|dh\d+)$/.test(d.id)) d.ownerId = r.picId;
    d.serviceId = d.serviceId || r.serviceId;
    if (!d.service || d.service === 'Belum ditentukan') d.service = serviceName(d.serviceId);
    if (d.status === 'Won' && r.cat === 'Lead') { r.cat = 'Client'; r.convertedAt = r.convertedAt || d.closedAt || today(); }
  });
  (db.views || []).forEach(v => { if (v.filter && !['all','Lead','Client'].includes(v.filter.cat)) v.filter.cat = 'all'; });
  db.workflowVersion = WORKFLOW_VERSION;
  return true;
}
function promoteWonClient(deal) {
  if (!deal || deal.status !== 'Won') return;
  const r = recById(deal.recordId);
  if (!r || r.cat === 'Client') return;
  trackChange('record', r.id, 'Status', r.cat, 'Client', 'Deal ' + deal.code + ' Won');
  r.cat = 'Client'; r.convertedAt = deal.closedAt || today(); r.convertedBy = me().id;
  r.convertedDealId = deal.id; r.updatedAt = today();
  audit('Ubah', 'Lead & Client', r.name + ' otomatis menjadi Client setelah ' + deal.title + ' Won');
}
function transferRecord(r, targetUser, reason) {
  const from = { dept: r.dept, picId: r.picId };
  const line = lineForDivision(targetUser.dept);
  trackChange('record', r.id, 'PIC', (userById(r.picId) || {}).name || '', targetUser.name, reason);
  trackChange('record', r.id, 'Divisi', r.dept, targetUser.dept, reason);
  r.dept = targetUser.dept; r.picId = targetUser.id; r.updatedAt = today();
  r.handoffs = r.handoffs || [];
  r.handoffs.push({ at: new Date().toISOString(), by: me().id, fromDept: from.dept, fromPicId: from.picId, toDept: r.dept, toPicId: r.picId, reason });
  Store.db.deals.filter(d => d.recordId === r.id).forEach(d => {
    const old = stageOf(d); const oldLine = lineById(d.lineId);
    const i = oldLine ? oldLine.stages.findIndex(s => s.id === d.stageId) : 0;
    d.lineId = line.id;
    const match = line.stages.find(s => old && s.name === old.name);
    d.stageId = d.status === 'Won' ? line.stages[line.stages.length - 1].id : (match || line.stages[Math.max(0, Math.min(i, line.stages.length - 2))]).id;
    if (d.status === 'Open' || d.status === 'On Hold') d.ownerId = targetUser.id;
    dealLog(d, old ? old.name : '', stageOf(d).name, d.status, 'Serah terima ' + from.dept + ' ke ' + r.dept + ': ' + reason);
  });
  audit('Serah terima', 'Lead & Client', r.name + ': ' + from.dept + ' ke ' + r.dept + ', PIC ' + targetUser.name + ': ' + reason);
  notify(targetUser.id, me().name + ' menyerahkan ' + r.name + ' ke kamu', 'rec:' + r.id);
}
const loadBeforeWorkflow = Store.load.bind(Store);
Store.load = function () { loadBeforeWorkflow(); if (migrateWorkflow(this.db)) this.save(); };
