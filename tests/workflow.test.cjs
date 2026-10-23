const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

// Run real app handlers with a minimal DOM, without a browser or external services.
function app() {
  const nodes = new Map();
  const node = () => ({ innerHTML: '', textContent: '', dataset: {}, style: {}, classList: { add() {}, remove() {} }, setAttribute() {}, removeAttribute() {}, querySelector() { return null; }, querySelectorAll() { return []; }, appendChild() {}, remove() {}, focus() {} });
  const storage = new Map();
  const document = { activeElement: null, documentElement: node(), body: node(), addEventListener() {}, querySelector() { return null; }, querySelectorAll() { return []; }, createElement: node, getElementById(id) { if (!nodes.has(id)) nodes.set(id, node()); return nodes.get(id); } };
  const context = vm.createContext({ console, document, location: { hash: '#/login', hostname: '127.0.0.1' }, history: { replaceState() {} }, window: { addEventListener() {}, scrollTo() {} }, localStorage: { getItem: k => storage.get(k) || null, setItem: (k, v) => storage.set(k, v) }, setTimeout() { return 0; }, clearTimeout() {}, setInterval() { return 0; }, matchMedia: () => ({ matches: true }), URLSearchParams, Date, Math, Intl, performance: { now: () => 0 }, requestAnimationFrame() {}, FormData: class {} });
  const root = path.join(__dirname, '..', 'assets');
  for (const file of ['data.js','optima-workflow.js','optima-preview-config.js','ui.js','pages.js','actions.js','features.js','optima-profile.js','optima-workflow-ui.js','optima-preview.js']) vm.runInContext(fs.readFileSync(path.join(root, file), 'utf8'), context, { filename: file });
  vm.runInContext("render = () => {}; toast = () => {};", context);
  return code => vm.runInContext(code, context);
}

test('new Lead uses the actual creator as PIC and division, despite forged form fields', () => {
  const run = app();
  run("Store.db.session='u5'; App.modal={kind:'record'}; Forms.record({name:'Demo direct lead',cat:'Client',picId:'u1',dept:'Executive',serviceId:'kol'});");
  assert.equal(run('Store.db.records.at(-1).cat'), 'Lead');
  assert.equal(run('Store.db.records.at(-1).picId'), 'u5');
  assert.equal(run('Store.db.records.at(-1).createdBy'), 'u5');
  assert.equal(run('Store.db.records.at(-1).dept'), 'Digital Campaign');
});
test('all divisions, including CS, can directly receive Leads without a required CS gate', () => {
  const run = app();
  for (const [id, name] of Object.entries({u2:'Aurora Logistics',u3:'Bengkel Roda',u4:'Pustaka Ilmu',u5:'Kedai Bahagia',u6:'Rumah Kayu', 'demo-dietpartner':'Klinik Nutrisi'})) {
    run(`Store.db.session='${id}'; App.modal={kind:'record'}; Forms.record({name:'${name}'});`);
    assert.equal(run('Store.db.records.at(-1).dept'), run('me().dept'));
    assert.equal(run('Store.db.records.at(-1).picId'), id);
  }
});
test('Lead becomes Client on Won, and remains a Client after reopening a deal', () => {
  const run = app();
  run("Store.db.session='u5'; App.modal={kind:'record'}; Forms.record({name:'Demo won journey',serviceId:'kol'}); const rec=Store.db.records.at(-1); App.modal={kind:'deal'}; Forms.deal({title:'KOL activation',recordId:rec.id,ownerId:'u5',offer:'1000000'}); const dl=Store.db.deals.at(-1); App.modal={kind:'closing',dealId:dl.id}; Forms.closing({value:'1000000',closedAt:today()});");
  assert.equal(run('rec.cat'), 'Client');
  assert.equal(run('rec.convertedDealId'), run('dl.id'));
  run("Store.db.session='demo-digital-lead'; App.modal={kind:'dstatus',dealId:dl.id,st:'reopen'}; Forms.dstatus({toStage:lineById(dl.lineId).stages[0].id,reason:'Revisi'});");
  assert.equal(run('rec.cat'), 'Client');
});
test('Lost, On Hold, manual conversion, and editing category cannot promote a Lead', () => {
  const run = app();
  run("Store.db.session='u5'; App.modal={kind:'record'}; Forms.record({name:'Demo no win'}); const r=Store.db.records.at(-1); App.modal={kind:'record',rec:r}; Forms.record({name:r.name,cat:'Client',dept:'Executive',picId:'u1'}); Actions.convert({dataset:{v:r.id}});");
  assert.equal(run('r.cat'), 'Lead');
  assert.equal(run('r.dept'), 'Digital Campaign');
  run("App.modal={kind:'deal'}; Forms.deal({title:'Pending',recordId:r.id,ownerId:'u5'}); const d=Store.db.deals.at(-1); App.modal={kind:'dstatus',dealId:d.id,st:'Lost'}; Forms.dstatus({lostReason:'Pilih vendor lain'});");
  assert.equal(run('r.cat'), 'Lead');
});
test('division isolation applies to records, pipeline totals, deep actions, and search', () => {
  const run = app();
  run("Store.db.session='u5'; const other=Store.db.records.find(r=>r.dept==='Creative & Media'); const otherDeal=Store.db.deals.find(d=>d.recordId===other.id);");
  assert.equal(run('canSeeRec(other)'), false);
  assert.equal(run('canSeeDeal(otherDeal)'), false);
  assert.equal(run('canEditRec(other)'), false);
  run('App.modal=null; Actions.opendeal({dataset:{v:otherDeal.id}});');
  assert.equal(run('App.modal'), null);
  run("App.pal={q:otherDeal.title};");
  run('Palette();');
  assert.equal(run('App.pal.res.some(x=>x.v===otherDeal.id)'), false);
  assert.equal(run('Pages.pipeline().includes(otherDeal.title)'), false);
  run("Store.db.session='u1';");
  assert.equal(run('canSeeRec(other)'), true);
  assert.equal(run('canSeeDeal(otherDeal)'), true);
});
test('handoff can go from any division to CS, then to another division with all related work', () => {
  const run = app();
  run("Store.db.session='u5'; App.modal={kind:'record'}; Forms.record({name:'Demo multidirectional',serviceId:'kol'}); const r=Store.db.records.at(-1); App.modal={kind:'deal'}; Forms.deal({title:'Activation',recordId:r.id,ownerId:'u5'}); const d=Store.db.deals.at(-1); App.modal={kind:'pic',id:r.id}; Forms.pic({dept:'Customer Service',picId:'u6',reason:'Perlu discovery'});");
  assert.equal(run('r.picId'), 'u6');
  assert.equal(run('canSeeRec(r)'), false);
  assert.equal(run('lineById(d.lineId).dept'), 'Customer Service');
  run("Store.db.session='u6'; App.modal={kind:'pic',id:r.id}; Forms.pic({dept:'CampusNet',picId:'u4',reason:'Kebutuhan sistem'});");
  assert.equal(run('r.dept'), 'CampusNet');
  assert.equal(run('d.ownerId'), 'u4');
  assert.equal(run('r.createdBy'), 'u5');
  assert.equal(run('r.handoffs.length'), 2);
  assert.equal(run('canSeeRec(r)'), false);
  run("Store.db.session='u4';");
  assert.equal(run('canSeeRec(r)'), true);
  assert.equal(run('canSeeDeal(d)'), true);
});
test('legacy migration preserves record IDs and history while separating service from status', () => {
  const run = app();
  assert.equal(run('Store.db.records.length'), 19);
  assert.equal(run('Store.db.records.every(r=>["Lead","Client"].includes(r.cat))'), true);
  assert.equal(run("Store.db.records.some(r=>r.serviceId==='community')"), true);
  assert.equal(run("serviceById('kol').dept"), 'Digital Campaign');
  assert.equal(run("serviceById('media').dept"), 'Creative & Media');
  assert.equal(run('Store.db.deals.every(d=>!!stageOf(d))'), true);
  assert.equal(run('migrateWorkflow(Store.db)'), false);
});
test('Viewer cannot gain write permissions through an override', () => {
  const run = app();
  run("Store.db.session='u7'; Store.db.overrides.push({userId:'u7',key:'lead',value:'M'});");
  assert.equal(run("perm('lead')"), 'R');
  assert.equal(run('canCreateAny()'), false);
});
test('forged cross-division deals and interactions cannot change another division', () => {
  const run = app();
  run("Store.db.session='u5'; const r=Store.db.records.find(r=>r.dept==='CampusNet'); const before=Store.db.deals.length; App.modal={kind:'deal'}; Forms.deal({title:'Unauthorized',recordId:r.id,ownerId:'u5'});");
  assert.equal(run('Store.db.deals.length===before'), true);
  run("const n=Store.db.interactions.length; App.modal={kind:'int'}; Forms.int({recordId:r.id,result:'Unauthorized'});");
  assert.equal(run('Store.db.interactions.length===n'), true);
});
test('real user data is absent from public files and onboarding is only exposed on localhost', () => {
  const run = app();
  run("Store.db.session='u1'; App.ui.ak.tab='users';");
  assert.equal(run("Pages.akses().includes('Muat daftar tim internal')"), true);
  run("location.hostname='kawanberinovasi-ops.github.io';");
  assert.equal(run("Pages.akses().includes('Muat daftar tim internal')"), false);
  assert.equal(run('Store.db.users.every(u=>u.email.endsWith("@contoh.test"))'), true);
});
