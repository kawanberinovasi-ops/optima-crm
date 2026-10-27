/* All existing modules enabled. Storage remains independent from the Downloads copy. */
const CLIENT_PREVIEW = Object.freeze({
  scope: ['S-01','S-02','S-03','S-04','S-05','S-06','S-07','S-08','S-09'],
  showLogin: true,
  routes: ['mywork','dashboard','cp','pipeline','akses'],
  authRoutes: ['login','expired'],
  defaultRoute: 'cp',
  adminTabs: ['users','dept','services','access','override','options','changes','audit']
});
function ensurePreviewAccount() {
  if (CLIENT_PREVIEW.showLogin) return;
  const current = me();
  if (current && current.active) return;
  const account = Store.db.users.find(u => u.active && u.role === 'Super Admin') || Store.db.users.find(u => u.active);
  if (!account) return;
  Store.db.session = account.id;
  Store.db.sessionStart = Date.now(); Store.db.lastActive = Date.now();
  Store.save();
}
const loadPreviewStore = Store.load.bind(Store);
Store.load = function () {
  loadPreviewStore();
  if (CLIENT_PREVIEW.showLogin) {
    // Replace the previous automatic demo session once, without changing any user or CRM data.
    try {
      const key = 'optima_client_s01_s05_login';
      if (!localStorage.getItem(key)) {
        Store.db.session = null; Store.db.sessionStart = 0; Store.db.lastActive = 0;
        Store.save(); localStorage.setItem(key,'enabled');
      }
    } catch (e) { Store.db.session = null; }
  }
  ensurePreviewAccount();
};
