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

  const contentFieldNames = new Set(['content', 'description', 'excerpt', 'summary', 'text', 'answer', 'bio', 'quote', 'challenge', 'solution', 'result', 'full_description']);
  document.querySelectorAll('textarea[name]').forEach((textarea) => {
    const fieldName = textarea.name.match(/(?:^|\[)([a-z_]+)\]?$/i)?.[1] || textarea.name;
    if (!contentFieldNames.has(fieldName) || textarea.classList.contains('code-area') || textarea.closest('[data-text-link-editor]')) return;
    const wrapper = document.createElement('span');
    wrapper.className = 'text-link-editor';
    wrapper.dataset.textLinkEditor = '';
    const toolbar = document.createElement('span');
    toolbar.className = 'text-link-editor__toolbar';
    toolbar.innerHTML = '<button type="button" class="button secondary" data-text-link-open aria-haspopup="dialog" title="Dodaj lub edytuj link">🔗 Link</button>';
    textarea.before(wrapper);
    wrapper.append(toolbar, textarea);
    textarea.dataset.textLinkInput = '';
  });

  document.querySelectorAll('[data-text-link-editor]').forEach((editor, editorIndex) => {
    const textarea = editor.querySelector('[data-text-link-input]');
    const openButton = editor.querySelector('[data-text-link-open]');
    if (!textarea || !openButton) return;

    const dialog = document.createElement('dialog');
    dialog.className = 'text-link-dialog';
    const titleId = `text-link-dialog-title-${editorIndex}`;
    dialog.setAttribute('aria-labelledby', titleId);
    dialog.innerHTML = '<form method="dialog" class="text-link-dialog__card">'
      + `<div class="text-link-dialog__head"><div><span class="eyebrow">Odnośnik w opisie</span><h2 id="${titleId}">Dodaj link</h2></div><button type="button" class="text-link-dialog__close" data-link-cancel aria-label="Zamknij">×</button></div>`
      + '<label>Tekst linku<input name="label" required autocomplete="off"></label>'
      + '<label>Adres URL<input name="url" type="text" inputmode="url" required placeholder="https://… lub /uploads/…" autocomplete="url"></label>'
      + '<label class="text-link-dialog__check"><input name="new_tab" type="checkbox"> Otwórz link w nowej karcie</label>'
      + '<p class="text-link-dialog__error" data-link-error role="alert" hidden></p>'
      + '<div class="actions"><button type="button" class="button secondary" data-link-cancel>Anuluj</button><button type="submit">Wstaw link</button></div>'
      + '</form>';
    document.body.append(dialog);

    const form = dialog.querySelector('form');
    const labelInput = form.elements.label;
    const urlInput = form.elements.url;
    const newTabInput = form.elements.new_tab;
    const error = dialog.querySelector('[data-link-error]');
    let selectionStart = 0;
    let selectionEnd = 0;

    const close = () => dialog.close();
    dialog.querySelectorAll('[data-link-cancel]').forEach((button) => button.addEventListener('click', close));
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) close();
    });

    openButton.addEventListener('click', () => {
      selectionStart = textarea.selectionStart;
      selectionEnd = textarea.selectionEnd;
      const selected = textarea.value.slice(selectionStart, selectionEnd);
      const existing = selected.match(/^\[([^\]\r\n]+)\]\(([^)\s]+)\)(\{new-tab\})?$/u);
      labelInput.value = existing ? existing[1] : selected;
      urlInput.value = existing ? existing[2] : '';
      newTabInput.checked = Boolean(existing?.[3]);
      error.hidden = true;
      dialog.showModal();
      (labelInput.value ? urlInput : labelInput).focus();
    });

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const label = labelInput.value.trim();
      const url = urlInput.value.trim();
      const isLocal = url.startsWith('/') && !url.startsWith('//');
      let isWeb = false;
      try {
        const parsed = new URL(url);
        isWeb = parsed.protocol === 'http:' || parsed.protocol === 'https:';
      } catch (_) {
        isWeb = false;
      }
      if (!label || (!isLocal && !isWeb)) {
        error.textContent = 'Wpisz tekst linku oraz poprawny adres https://… lub ścieżkę zaczynającą się od /.';
        error.hidden = false;
        return;
      }
      const markup = `[${label.replace(/[\[\]]/g, '')}](${url})${newTabInput.checked ? '{new-tab}' : ''}`;
      textarea.setRangeText(markup, selectionStart, selectionEnd, 'end');
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
      close();
      textarea.focus();
    });
  });
})();
