(() => {
  'use strict';

  const body = document.body;
  const sidebar = document.querySelector('#admin-sidebar');
  const toggle = document.querySelector('[data-sidebar-toggle]');
  const backdrop = document.querySelector('[data-sidebar-backdrop]');
  const collapse = document.querySelector('[data-sidebar-collapse]');
  const search = document.querySelector('[data-admin-search]');

  const setSidebarOpen = (open) => {
    body.classList.toggle('is-sidebar-open', open);
    if (toggle) toggle.setAttribute('aria-expanded', String(open));
  };

  toggle?.addEventListener('click', () => setSidebarOpen(!body.classList.contains('is-sidebar-open')));
  backdrop?.addEventListener('click', () => setSidebarOpen(false));
  sidebar?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => setSidebarOpen(false)));

  if (collapse) {
    let collapsed = false;
    try {
      collapsed = window.localStorage.getItem('reklamova-sidebar-collapsed') === '1';
    } catch (_) {
      collapsed = false;
    }
    body.classList.toggle('is-sidebar-collapsed', collapsed);
    collapse.setAttribute('aria-pressed', String(collapsed));

    collapse.addEventListener('click', () => {
      collapsed = !body.classList.contains('is-sidebar-collapsed');
      body.classList.toggle('is-sidebar-collapsed', collapsed);
      collapse.setAttribute('aria-pressed', String(collapsed));
      try {
        window.localStorage.setItem('reklamova-sidebar-collapsed', collapsed ? '1' : '0');
      } catch (_) {
        // The preference is optional; the interface still works without storage.
      }
    });
  }

  const closeAccountMenus = (except = null) => {
    document.querySelectorAll('.account-menu[open]').forEach((menu) => {
      if (menu !== except) menu.removeAttribute('open');
    });
  };

  document.addEventListener('click', (event) => {
    const account = event.target.closest('.account-menu');
    if (!account) closeAccountMenus();
  });

  if (search) {
    const input = search.querySelector('input[type="search"]');
    const results = search.querySelector('[data-admin-search-results]');
    const navItems = Array.from(document.querySelectorAll('[data-admin-nav]')).map((link) => ({
      href: link.getAttribute('href') || '/admin',
      label: link.dataset.searchLabel || link.textContent.trim(),
      group: link.previousElementSibling?.classList.contains('nav-section')
        ? link.previousElementSibling.textContent.trim()
        : '',
    }));

    const normalized = (value) => String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLocaleLowerCase('pl');

    const hideResults = () => {
      if (!results) return;
      results.hidden = true;
      results.innerHTML = '';
    };

    const showResults = () => {
      if (!input || !results) return;
      const query = normalized(input.value.trim());
      if (!query) {
        hideResults();
        return;
      }

      const matches = navItems.filter((item) => normalized(`${item.label} ${item.group}`).includes(query)).slice(0, 7);
      results.innerHTML = '';
      if (!matches.length) {
        const empty = document.createElement('p');
        empty.className = 'admin-search-results__empty';
        empty.textContent = 'Nie znaleziono pasującej sekcji.';
        results.append(empty);
      } else {
        matches.forEach((item, index) => {
          const link = document.createElement('a');
          link.href = item.href;
          link.dataset.searchResult = String(index);
          const label = document.createElement('b');
          label.textContent = item.label;
          const meta = document.createElement('small');
          meta.textContent = item.group || 'Panel CMS';
          link.append(label, meta);
          results.append(link);
        });
      }
      results.hidden = false;
    };

    input?.addEventListener('input', showResults);
    input?.addEventListener('focus', showResults);
    input?.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        hideResults();
        input.blur();
      }
      if (event.key === 'ArrowDown') {
        const first = results?.querySelector('a');
        if (first) {
          event.preventDefault();
          first.focus();
        }
      }
    });

    results?.addEventListener('keydown', (event) => {
      const links = Array.from(results.querySelectorAll('a'));
      const current = links.indexOf(document.activeElement);
      if (event.key === 'ArrowDown' && links[current + 1]) {
        event.preventDefault();
        links[current + 1].focus();
      }
      if (event.key === 'ArrowUp') {
        event.preventDefault();
        if (links[current - 1]) links[current - 1].focus();
        else input?.focus();
      }
      if (event.key === 'Escape') {
        hideResults();
        input?.focus();
      }
    });

    search.addEventListener('submit', (event) => {
      const first = results?.querySelector('a');
      if (first) {
        event.preventDefault();
        window.location.assign(first.href);
      }
    });

    document.addEventListener('click', (event) => {
      if (!event.target.closest('[data-admin-search]')) hideResults();
    });

    document.addEventListener('keydown', (event) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLocaleLowerCase() === 'k') {
        event.preventDefault();
        input?.focus();
        input?.select();
      }
    });
  }

  window.matchMedia('(min-width: 901px)').addEventListener('change', (event) => {
    if (event.matches) setSidebarOpen(false);
  });
})();
