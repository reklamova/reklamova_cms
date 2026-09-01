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

  const textareas = Array.from(new Set([
    ...document.querySelectorAll('textarea[data-content-editor]'),
    ...document.querySelectorAll('[data-text-link-editor] textarea[data-text-link-input]'),
  ])).filter((textarea) => !textarea.classList.contains('code-area'));

  if (textareas.length) {
    const dialog = document.createElement('dialog');
    dialog.className = 'text-link-dialog';
    dialog.id = 'content-link-dialog';
    dialog.setAttribute('aria-labelledby', 'content-link-dialog-title');
    dialog.innerHTML = '<form method="dialog" class="text-link-dialog__card">'
      + '<div class="text-link-dialog__head"><div><span class="eyebrow">Odnośnik w treści</span><h2 id="content-link-dialog-title">Dodaj link</h2></div><button type="button" class="text-link-dialog__close" data-link-cancel aria-label="Zamknij">×</button></div>'
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
    let activeTextarea = null;
    let selectionStart = 0;
    let selectionEnd = 0;

    const findSelectedLink = (value, start, end) => {
      const pattern = /\[([^\]\r\n]+)\]\(([^()\s]+)\)(\{new-tab\})?/gu;
      let match;
      while ((match = pattern.exec(value)) !== null) {
        const matchEnd = match.index + match[0].length;
        if ((start === end && start >= match.index && start <= matchEnd) || (start === match.index && end === matchEnd)) {
          return { match, start: match.index, end: matchEnd };
        }
      }
      return null;
    };

    const close = () => {
      if (dialog.open) dialog.close();
    };
    dialog.querySelectorAll('[data-link-cancel]').forEach((button) => button.addEventListener('click', close));
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) close();
    });
    dialog.addEventListener('close', () => activeTextarea?.focus());

    textareas.forEach((textarea) => {
      let editor = textarea.closest('[data-text-link-editor]');
      let openButton = editor?.querySelector('[data-text-link-open]');
      if (!editor) {
        editor = document.createElement('span');
        editor.className = 'text-link-editor';
        editor.dataset.textLinkEditor = '';
        const toolbar = document.createElement('span');
        toolbar.className = 'text-link-editor__toolbar';
        openButton = document.createElement('button');
        openButton.type = 'button';
        openButton.className = 'button secondary';
        openButton.dataset.textLinkOpen = '';
        openButton.textContent = '🔗 Link';
        openButton.title = 'Dodaj lub edytuj link';
        openButton.setAttribute('aria-label', 'Dodaj lub edytuj link');
        openButton.setAttribute('aria-haspopup', 'dialog');
        toolbar.append(openButton);
        textarea.before(editor);
        editor.append(toolbar, textarea);
      }
      if (!openButton) return;
      textarea.dataset.textLinkInput = '';
      openButton.setAttribute('aria-controls', dialog.id);

      openButton.addEventListener('click', () => {
        activeTextarea = textarea;
        selectionStart = textarea.selectionStart;
        selectionEnd = textarea.selectionEnd;
        const existing = findSelectedLink(textarea.value, selectionStart, selectionEnd);
        if (existing) {
          selectionStart = existing.start;
          selectionEnd = existing.end;
        }
        const selected = textarea.value.slice(selectionStart, selectionEnd);
        labelInput.value = existing ? existing.match[1] : selected;
        urlInput.value = existing ? existing.match[2] : '';
        newTabInput.checked = Boolean(existing?.match[3]);
        error.hidden = true;
        dialog.showModal();
        (labelInput.value ? urlInput : labelInput).focus();
      });
    });

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!activeTextarea) return;
      const label = labelInput.value.trim().replace(/[\[\]\r\n]/g, '');
      const url = urlInput.value.trim();
      const isLocal = /^\/(?![\/\\])[^\x00-\x20\\]*$/u.test(url);
      let isWeb = false;
      try {
        const parsed = new URL(url);
        isWeb = (parsed.protocol === 'http:' || parsed.protocol === 'https:') && !/[\s()]/u.test(url);
      } catch (_) {
        isWeb = false;
      }
      if (!label || (!isLocal && !isWeb)) {
        error.textContent = 'Wpisz tekst linku oraz poprawny adres https://… lub ścieżkę zaczynającą się od /.';
        error.hidden = false;
        return;
      }
      const markup = `[${label}](${url})${newTabInput.checked ? '{new-tab}' : ''}`;
      activeTextarea.setRangeText(markup, selectionStart, selectionEnd, 'end');
      activeTextarea.dispatchEvent(new Event('input', { bubbles: true }));
      close();
    });
  }
})();
