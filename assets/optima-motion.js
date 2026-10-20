/* Visual enhancement only: reveal once, animate on interaction, respect reduced motion. */
(() => {
  'use strict';
  const root = document.getElementById('app');
  if (!root) return;
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const surfaces = '.card,.hero,.col,.lrow,.tl-i';
  const known = new WeakSet();
  const observer = 'IntersectionObserver' in window ? new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-visible');
      observer.unobserve(entry.target);
    });
  }, { threshold: 0.025, rootMargin: '0px 0px 12px 0px' }) : null;
  function prepare(node) {
    if (!(node instanceof Element)) return;
    const items = [...(node.matches(surfaces) ? [node] : []), ...node.querySelectorAll(surfaces)];
    items.forEach((el, i) => {
      if (known.has(el)) return;
      known.add(el);
      el.style.setProperty('--surface-delay', Math.min(i % 4 * 45, 135) + 'ms');
      el.classList.add('motion-surface');
      if (reduced.matches || !observer) el.classList.add('is-visible');
      else observer.observe(el);
    });
  }
  let pendingMenu = false;
  function alignActiveMenu() {
    if (pendingMenu) return;
    pendingMenu = true;
    requestAnimationFrame(() => {
      pendingMenu = false;
      const menu = root.querySelector('.menu');
      const active = menu && menu.querySelector('[aria-current="page"]');
      if (!active || menu.scrollWidth <= menu.clientWidth) return;
      const x = active.getBoundingClientRect().left - menu.getBoundingClientRect().left + menu.scrollLeft;
      menu.scrollLeft = Math.max(0, x - (menu.clientWidth - active.clientWidth) / 2);
    });
  }
  prepare(root);
  alignActiveMenu();
  new MutationObserver(records => {
    let added = false;
    records.forEach(record => record.addedNodes.forEach(node => {
      if (node.nodeType !== 1) return;
      prepare(node);
      added = true;
    }));
    if (added) alignActiveMenu();
  }).observe(root, { childList: true, subtree: true });
  reduced.addEventListener('change', () => {
    if (reduced.matches) root.querySelectorAll('.motion-surface').forEach(el => el.classList.add('is-visible'));
  });
  addEventListener('resize', alignActiveMenu, { passive: true });
})();
