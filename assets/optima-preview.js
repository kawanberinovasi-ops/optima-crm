/* Keep every module visible; enable only the S-01–S-06 presentation scope. */
(() => {
  'use strict';
  const inertRoutes = new Set(['mywork','dashboard']);
  const inertActions = new Set([
    'resetdata','simexpire','exportaudit','newoption','editoption','deloption',
    'import','export','pickfile','doimport','undoimport'
  ]);
  if (!CLIENT_PREVIEW.showLogin) inertActions.add('logout');
  const noAction = () => {};
  function makeInert(el) {
    el.classList.add('preview-inert'); el.setAttribute('aria-disabled','true');
    if (el.tagName === 'INPUT' || el.tagName === 'SELECT') el.disabled = true;
    if (el.tagName === 'A') { el.removeAttribute('href'); el.setAttribute('role','link'); el.tabIndex = -1; }
  }
  function decorate(scope) {
    // Keep the complete source and module navigation, while presenting only S-01–S-06 content.
    scope.querySelectorAll('[aria-label="Import dan export"],details.hist,[data-a="undoimport"]').forEach(el => el.classList.add('preview-outside-scope'));
    scope.querySelectorAll('[data-a]').forEach(el => {
      const action = el.dataset.a;
      if (inertActions.has(action) || (action === 'nav' && inertRoutes.has(el.dataset.v)) ||
          (action === 'aktab' && !CLIENT_PREVIEW.adminTabs.includes(el.dataset.v))) makeInert(el);
    });
    scope.querySelectorAll('[data-ch="importfile"],[data-drop],details.hist summary').forEach(makeInert);
    scope.querySelectorAll('details.hist[open]').forEach(el => el.removeAttribute('open'));
    scope.querySelectorAll('a[href]').forEach(el => {
      const href = el.getAttribute('href');
      if (href && (href.startsWith('#/mywork') || href.startsWith('#/dashboard') || /\.(xlsx?|csv)(?:$|[?#])/.test(href))) makeInert(el);
    });
  }
  function setRoute(route) {
    App.route = route;
    if (location.hash.split('?')[0] !== '#/' + route) history.replaceState(null,'','#/' + route);
  }
  const renderPreview = render;
  render = function () {
    ensurePreviewAccount();
    const requested = location.hash.replace(/^#\/?/,'').split('?')[0];
    if (CLIENT_PREVIEW.showLogin && !me()) {
      setRoute(App.expired ? 'expired' : 'login');
    } else if (!CLIENT_PREVIEW.routes.includes(requested) || !CLIENT_PREVIEW.routes.includes(App.route) || (App.route === 'akses' && !canViewAdmin())) {
      App.expired = null; setRoute(CLIENT_PREVIEW.defaultRoute);
    }
    if (App.route === 'akses' && !CLIENT_PREVIEW.adminTabs.includes(App.ui.ak.tab)) App.ui.ak.tab = 'users';
    renderPreview();
    decorate(document.getElementById('app')); decorate(document.getElementById('ov'));
  };
  const ignoreActivation = e => {
    if (!e.target.closest('.preview-inert')) return;
    e.preventDefault(); e.stopImmediatePropagation();
  };
  document.addEventListener('click', ignoreActivation, true);
  document.addEventListener('auxclick', ignoreActivation, true);
  ['dragover','drop'].forEach(name => document.addEventListener(name, e => {
    if (!e.target.closest('[data-drop]')) return;
    e.preventDefault(); e.stopImmediatePropagation();
  }, true));
  for (const action of inertActions) { if (Actions[action]) Actions[action] = noAction; }
  if (Changes.importfile) Changes.importfile = noAction;
  const nav = Actions.nav;
  Actions.nav = el => { if (inertRoutes.has(el.dataset.v)) return; nav(el); };
  const tab = Actions.aktab;
  Actions.aktab = el => { if (!CLIENT_PREVIEW.adminTabs.includes(el.dataset.v)) return; tab(el); };
  render();
})();
