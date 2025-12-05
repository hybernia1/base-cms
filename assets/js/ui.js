(() => {
  const qs = (sel, root = document) => root.querySelector(sel);
  const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const getTarget = (el) => {
    const selector = el.dataset.bsTarget || el.getAttribute('href');
    if (!selector) return null;
    try { return document.querySelector(selector); } catch (err) { return null; }
  };

  const initCollapses = () => {
    qsa('[data-bs-toggle="collapse"]').forEach((trigger) => {
      const target = getTarget(trigger);
      if (!target) return;
      const icon = trigger.querySelector('.menu-chevron');
      const applyState = () => {
        const open = target.classList.contains('show');
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (icon) icon.classList.toggle('rotate', open);
      };
      trigger.addEventListener('click', (event) => {
        event.preventDefault();
        target.classList.toggle('show');
        applyState();
      });
      applyState();
    });
  };

  const closeAllDropdowns = (except) => {
    qsa('[data-bs-toggle="dropdown"]').forEach((toggle) => {
      if (toggle === except) return;
      const menu = toggle.nextElementSibling?.classList.contains('dropdown-menu')
        ? toggle.nextElementSibling
        : getTarget(toggle);
      if (menu) {
        menu.classList.remove('show');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  };

  const initDropdowns = () => {
    qsa('[data-bs-toggle="dropdown"]').forEach((toggle) => {
      const menu = toggle.nextElementSibling?.classList.contains('dropdown-menu')
        ? toggle.nextElementSibling
        : getTarget(toggle);
      if (!menu) return;
      toggle.addEventListener('click', (event) => {
        event.preventDefault();
        const open = menu.classList.toggle('show');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
          closeAllDropdowns(toggle);
        }
      });
    });
    document.addEventListener('click', (event) => {
      if (event.target.closest('[data-bs-toggle="dropdown"], .dropdown-menu')) return;
      closeAllDropdowns();
    });
  };

  const initNavbarToggles = () => {
    qsa('.navbar-toggler').forEach((btn) => {
      const target = getTarget(btn);
      if (!target) return;
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        target.classList.toggle('show');
        btn.setAttribute('aria-expanded', target.classList.contains('show'));
      });
    });
  };

  const initOffcanvas = () => {
    const makeBackdrop = () => {
      const el = document.createElement('div');
      el.className = 'offcanvas-backdrop';
      document.body.appendChild(el);
      return el;
    };

    qsa('[data-bs-toggle="offcanvas"]').forEach((btn) => {
      const target = getTarget(btn);
      if (!target) return;
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        const open = target.classList.toggle('show');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        let backdrop = qs('.offcanvas-backdrop');
        if (open) {
          backdrop = backdrop || makeBackdrop();
          backdrop.addEventListener('click', () => target.classList.remove('show'), { once: true });
        } else if (backdrop) {
          backdrop.remove();
        }
      });
    });
  };

  const initModals = () => {
    const showModal = (modal) => {
      modal.classList.add('show');
      modal.removeAttribute('aria-hidden');
      document.body.classList.add('overflow-hidden');
    };
    const hideModal = (modal) => {
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('overflow-hidden');
    };

    qsa('[data-bs-toggle="modal"]').forEach((btn) => {
      const target = getTarget(btn);
      if (!target) return;
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        showModal(target);
      });
    });

    qsa('[data-bs-dismiss="modal"]', document).forEach((btn) => {
      const modal = btn.closest('.modal');
      if (!modal) return;
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        hideModal(modal);
      });
    });

    qsa('.modal').forEach((modal) => {
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          hideModal(modal);
        }
      });
    });
  };

  const initTabs = () => {
    qsa('[data-bs-toggle="tab"]').forEach((btn) => {
      const target = getTarget(btn);
      if (!target) return;
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        const list = btn.closest('[role="tablist"]');
        if (list) {
          qsa('[data-bs-toggle="tab"]', list).forEach((item) => item.classList.remove('active'));
        }
        qsa('.tab-pane', target.parentElement).forEach((pane) => pane.classList.remove('active', 'show'));
        btn.classList.add('active');
        target.classList.add('active', 'show');
      });
    });
  };

  const init = () => {
    initCollapses();
    initDropdowns();
    initNavbarToggles();
    initOffcanvas();
    initModals();
    initTabs();
  };

  document.addEventListener('DOMContentLoaded', init);
})();
