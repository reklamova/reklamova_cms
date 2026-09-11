(() => {
  'use strict';

  const itemSelector = '[data-gallery-item]';

  const filenameFromPath = (path) => {
    try {
      const pathname = new URL(path, window.location.origin).pathname;
      return decodeURIComponent(pathname.split('/').filter(Boolean).pop() || 'Zdjęcie');
    } catch (_) {
      return 'Zdjęcie';
    }
  };

  const createButton = (action, label, text) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'gallery-card__action';
    button.dataset.galleryAction = action;
    button.setAttribute('aria-label', label);
    button.title = label;
    button.textContent = text;
    return button;
  };

  const createItem = (path, filename) => {
    const item = document.createElement('article');
    item.className = 'gallery-card';
    item.dataset.galleryItem = '';
    item.dataset.path = path;
    item.draggable = true;

    const preview = document.createElement('div');
    preview.className = 'gallery-card__preview';
    const image = document.createElement('img');
    image.src = path;
    image.alt = '';
    image.loading = 'lazy';
    image.decoding = 'async';
    image.addEventListener('error', () => item.classList.add('is-image-error'));
    const primary = document.createElement('span');
    primary.className = 'gallery-card__primary';
    primary.dataset.galleryPrimary = '';
    primary.textContent = 'Zdjęcie główne';
    const handle = document.createElement('span');
    handle.className = 'gallery-card__handle';
    handle.setAttribute('aria-hidden', 'true');
    handle.textContent = '⠿';
    preview.append(image, primary, handle);

    const meta = document.createElement('div');
    meta.className = 'gallery-card__meta';
    const name = document.createElement('strong');
    name.textContent = filename || filenameFromPath(path);
    name.title = name.textContent;
    const position = document.createElement('span');
    position.dataset.galleryPosition = '';
    meta.append(name, position);

    const actions = document.createElement('div');
    actions.className = 'gallery-card__actions';
    actions.append(
      createButton('left', 'Przesuń zdjęcie w lewo', '←'),
      createButton('right', 'Przesuń zdjęcie w prawo', '→'),
      createButton('remove', 'Usuń zdjęcie z galerii', 'Usuń')
    );

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'gallery[]';
    input.value = path;
    item.append(preview, meta, actions, input);
    return item;
  };

  const enhanceGallery = (manager) => {
    const list = manager.querySelector('[data-gallery-list]');
    const dropzone = manager.querySelector('[data-gallery-dropzone]');
    const fileInput = manager.querySelector('[data-gallery-file-input]');
    const browseButton = manager.querySelector('[data-gallery-browse]');
    const status = manager.querySelector('[data-gallery-status]');
    const count = manager.querySelector('[data-gallery-count]');
    const empty = manager.querySelector('[data-gallery-empty]');
    const library = manager.querySelector('[data-gallery-library]');
    const addLibrary = manager.querySelector('[data-gallery-add-library]');
    const uploadUrl = manager.dataset.uploadUrl || '';
    const form = manager.closest('form');
    let draggedItem = null;
    let dragDepth = 0;

    if (!list || !dropzone || !fileInput || !browseButton || !form) {
      return;
    }

    const items = () => Array.from(list.querySelectorAll(itemSelector));
    const pathExists = (path) => items().some((item) => item.dataset.path === path);
    const setStatus = (message, type = '') => {
      if (!status) return;
      status.textContent = message;
      status.className = 'gallery-manager__status' + (type ? ` is-${type}` : '');
    };
    const refresh = () => {
      const current = items();
      current.forEach((item, index) => {
        item.classList.toggle('is-primary', index === 0);
        const primary = item.querySelector('[data-gallery-primary]');
        if (primary) primary.hidden = index !== 0;
        const position = item.querySelector('[data-gallery-position]');
        if (position) position.textContent = index === 0 ? 'Pierwsze w kolejności' : `Pozycja ${index + 1}`;
        const left = item.querySelector('[data-gallery-action="left"]');
        const right = item.querySelector('[data-gallery-action="right"]');
        if (left) left.disabled = index === 0;
        if (right) right.disabled = index === current.length - 1;
      });
      if (count) count.textContent = String(current.length);
      if (empty) empty.hidden = current.length > 0;
    };

    const addItem = (path, filename) => {
      const normalized = String(path || '').trim();
      if (!normalized || pathExists(normalized)) return false;
      list.append(createItem(normalized, filename));
      refresh();
      return true;
    };

    const upload = async (selectedFiles) => {
      const files = Array.from(selectedFiles || []).filter((file) => file && file.type.startsWith('image/'));
      if (!files.length) {
        setStatus('Wybierz pliki graficzne JPG, PNG, WebP, GIF lub AVIF.', 'error');
        return;
      }
      if (files.length > 20) {
        setStatus('Jednorazowo możesz przesłać maksymalnie 20 zdjęć.', 'error');
        return;
      }

      const csrf = form.querySelector('input[name="_csrf"]');
      const payload = new FormData();
      payload.append('_csrf', csrf ? csrf.value : '');
      files.forEach((file) => payload.append('uploads[]', file, file.name));
      manager.classList.add('is-uploading');
      dropzone.setAttribute('aria-busy', 'true');
      setStatus(`Przesyłanie ${files.length} ${files.length === 1 ? 'zdjęcia' : 'zdjęć'}…`, 'progress');

      try {
        const response = await fetch(uploadUrl, {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: payload,
          credentials: 'same-origin'
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.ok) {
          throw new Error(result.message || 'Serwer nie przyjął zdjęć.');
        }
        let added = 0;
        (result.items || []).forEach((item) => {
          if (addItem(item.path, item.filename)) added += 1;
        });
        const summary = `Dodano ${added} ${added === 1 ? 'zdjęcie' : 'zdjęcia'}. Zapisz produkt, aby utrwalić galerię.`;
        setStatus(result.errors && result.errors.length ? `${summary} ${result.message}` : summary, result.errors && result.errors.length ? 'error' : 'success');
      } catch (error) {
        setStatus(error instanceof Error ? error.message : 'Przesyłanie zdjęć nie powiodło się.', 'error');
      } finally {
        manager.classList.remove('is-uploading');
        dropzone.removeAttribute('aria-busy');
        fileInput.value = '';
      }
    };

    browseButton.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => upload(fileInput.files));
    dropzone.addEventListener('click', (event) => {
      if (event.target === dropzone || !event.target.closest('button')) fileInput.click();
    });
    dropzone.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        fileInput.click();
      }
    });
    dropzone.addEventListener('dragenter', (event) => {
      event.preventDefault();
      dragDepth += 1;
      dropzone.classList.add('is-dragover');
    });
    dropzone.addEventListener('dragover', (event) => event.preventDefault());
    dropzone.addEventListener('dragleave', () => {
      dragDepth = Math.max(0, dragDepth - 1);
      if (dragDepth === 0) dropzone.classList.remove('is-dragover');
    });
    dropzone.addEventListener('drop', (event) => {
      event.preventDefault();
      dragDepth = 0;
      dropzone.classList.remove('is-dragover');
      upload(event.dataTransfer ? event.dataTransfer.files : []);
    });

    if (addLibrary && library) {
      addLibrary.addEventListener('click', () => {
        const option = library.selectedOptions[0];
        if (!option || !library.value) {
          setStatus('Najpierw wybierz zdjęcie z biblioteki.', 'error');
          return;
        }
        if (addItem(library.value, option.textContent.trim())) {
          setStatus('Dodano zdjęcie z biblioteki. Zapisz produkt, aby utrwalić zmianę.', 'success');
        } else {
          setStatus('To zdjęcie jest już w galerii.', 'error');
        }
      });
    }

    list.addEventListener('click', (event) => {
      const button = event.target.closest('[data-gallery-action]');
      const item = event.target.closest(itemSelector);
      if (!button || !item) return;
      const action = button.dataset.galleryAction;
      if (action === 'remove') {
        item.remove();
        setStatus('Zdjęcie usunięto z galerii. Zapisz produkt, aby utrwalić zmianę.', 'success');
      } else if (action === 'left' && item.previousElementSibling) {
        list.insertBefore(item, item.previousElementSibling);
      } else if (action === 'right' && item.nextElementSibling) {
        list.insertBefore(item.nextElementSibling, item);
      }
      refresh();
    });

    list.addEventListener('dragstart', (event) => {
      draggedItem = event.target.closest(itemSelector);
      if (!draggedItem) return;
      draggedItem.classList.add('is-dragging');
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', draggedItem.dataset.path || 'gallery-item');
      }
    });
    list.addEventListener('dragover', (event) => {
      if (!draggedItem) return;
      event.preventDefault();
      const target = event.target.closest(itemSelector);
      if (!target || target === draggedItem) return;
      const box = target.getBoundingClientRect();
      const before = event.clientX < box.left + box.width / 2;
      list.insertBefore(draggedItem, before ? target : target.nextElementSibling);
    });
    list.addEventListener('drop', (event) => {
      if (!draggedItem) return;
      event.preventDefault();
      refresh();
    });
    list.addEventListener('dragend', () => {
      if (draggedItem) draggedItem.classList.remove('is-dragging');
      draggedItem = null;
      refresh();
    });

    items().forEach((item) => {
      const image = item.querySelector('img');
      if (image) image.addEventListener('error', () => item.classList.add('is-image-error'));
    });
    refresh();
    manager.classList.add('is-ready');
  };

  document.querySelectorAll('[data-gallery-manager]').forEach(enhanceGallery);

  document.querySelectorAll('[data-document-manager]').forEach((manager) => {
    const form = manager.closest('form');
    const input = manager.querySelector('[data-document-file-input]');
    const browse = manager.querySelector('[data-document-browse]');
    const dropzone = manager.querySelector('[data-document-dropzone]');
    const list = manager.querySelector('[data-document-list]');
    const status = manager.querySelector('[data-document-status]');
    if (!form || !input || !browse || !dropzone || !list) return;
    const setStatus = (message, type = '') => {
      status.textContent = message;
      status.className = 'gallery-manager__status' + (type ? ` is-${type}` : '');
    };
    const addItem = (item) => {
      if (!item.path || list.querySelector(`input[value="${CSS.escape(item.path)}"]`)) return;
      const row = document.createElement('li');
      row.dataset.documentItem = '';
      const badge = document.createElement('span'); badge.textContent = 'PDF'; badge.setAttribute('aria-hidden', 'true');
      const link = document.createElement('a'); link.href = item.path; link.target = '_blank'; link.rel = 'noopener'; link.textContent = item.filename || 'dokument.pdf';
      const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button secondary'; remove.dataset.documentRemove = ''; remove.textContent = 'Usuń';
      const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'documents[]'; hidden.value = item.path;
      row.append(badge, link, remove, hidden); list.append(row);
    };
    const upload = async (filesLike) => {
      const files = Array.from(filesLike || []).filter(file => file.type === 'application/pdf' || /\.pdf$/i.test(file.name));
      if (!files.length) { setStatus('Wybierz plik PDF.', 'error'); return; }
      const payload = new FormData();
      payload.append('_csrf', form.querySelector('input[name="_csrf"]')?.value || '');
      files.forEach(file => payload.append('uploads[]', file, file.name));
      setStatus(`Przesyłanie ${files.length} plików PDF…`, 'progress');
      try {
        const response = await fetch(manager.dataset.uploadUrl, {method:'POST', headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}, body:payload, credentials:'same-origin'});
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.ok) throw new Error(result.message || 'Serwer nie przyjął PDF.');
        (result.items || []).forEach(addItem);
        setStatus('PDF dodane. Zapisz produkt, aby utrwalić dokumenty.', result.errors?.length ? 'error' : 'success');
      } catch (error) { setStatus(error instanceof Error ? error.message : 'Przesyłanie PDF nie powiodło się.', 'error'); }
      finally { input.value = ''; }
    };
    browse.addEventListener('click', () => input.click());
    input.addEventListener('change', () => upload(input.files));
    dropzone.addEventListener('dragover', event => { event.preventDefault(); dropzone.classList.add('is-dragover'); });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('is-dragover'));
    dropzone.addEventListener('drop', event => { event.preventDefault(); dropzone.classList.remove('is-dragover'); upload(event.dataTransfer.files); });
    list.addEventListener('click', event => { const button = event.target.closest('[data-document-remove]'); if (button) button.closest('[data-document-item]')?.remove(); });
  });
})();

(() => {
  'use strict';

  const imageFieldNames = new Set(['image', 'featured_image', 'cover_image', 'og_image', 'hero_image', 'photo', 'media_url', 'block_media_url']);
  const candidates = Array.from(document.querySelectorAll('input[name], select[name]')).filter((field) => {
    if (field.matches('[type="hidden"], [type="file"]') || field.closest('[data-gallery-manager]')) return false;
    const normalized = field.name.replace(/\[\]$/, '');
    const leaf = normalized.match(/(?:^|\[)([a-z_]+)\]?$/i)?.[1] || normalized;
    return imageFieldNames.has(leaf) || /^block_gallery_media_\d+$/.test(normalized);
  });
  if (!candidates.length) return;

  const dialog = document.createElement('dialog');
  dialog.className = 'media-picker-dialog';
  dialog.setAttribute('aria-labelledby', 'media-picker-title');
  dialog.innerHTML = '<div class="media-picker-dialog__card">'
    + '<header class="media-picker-dialog__head"><div><span class="eyebrow">Biblioteka mediów</span><h2 id="media-picker-title">Wybierz obraz</h2><p>Wgraj nowy plik albo wybierz istniejący obraz.</p></div><button type="button" data-media-picker-close aria-label="Zamknij">×</button></header>'
    + '<div class="media-picker-dialog__tools"><label class="media-picker-search"><span class="sr-only">Szukaj obrazu</span><input type="search" placeholder="Szukaj po nazwie…" data-media-picker-search></label><button type="button" class="button" data-media-picker-upload>Wgraj nowy obraz</button><input type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/avif" hidden data-media-picker-file></div>'
    + '<div class="media-picker-dropzone" data-media-picker-dropzone tabindex="0" role="button"><strong>Przeciągnij obraz tutaj</strong><span>lub kliknij, aby wybrać plik · maks. 12 MB</span></div>'
    + '<p class="media-picker-status" data-media-picker-status role="status" aria-live="polite"></p>'
    + '<div class="media-picker-grid" data-media-picker-grid></div>'
    + '</div>';
  document.body.append(dialog);

  const grid = dialog.querySelector('[data-media-picker-grid]');
  const search = dialog.querySelector('[data-media-picker-search]');
  const uploadButton = dialog.querySelector('[data-media-picker-upload]');
  const fileInput = dialog.querySelector('[data-media-picker-file]');
  const dropzone = dialog.querySelector('[data-media-picker-dropzone]');
  const status = dialog.querySelector('[data-media-picker-status]');
  let activeField = null;
  let activeWidget = null;
  let searchTimer = null;

  const valueOf = (field) => String(field.value || '').trim();
  const filenameFromItem = (item) => item.filename || filenameFromPath(item.path || '');
  const setStatus = (message, type = '') => {
    status.textContent = message;
    status.className = 'media-picker-status' + (type ? ` is-${type}` : '');
  };
  const updateWidget = (widget, path) => {
    const preview = widget.querySelector('[data-media-field-preview]');
    const empty = widget.querySelector('[data-media-field-empty]');
    const remove = widget.querySelector('[data-media-field-remove]');
    preview.src = path || '';
    preview.hidden = !path;
    empty.hidden = Boolean(path);
    remove.hidden = !path;
  };
  const setFieldValue = (field, path, label = '') => {
    if (field instanceof HTMLSelectElement && path && !Array.from(field.options).some((option) => option.value === path)) {
      field.add(new Option(label || filenameFromPath(path), path));
    }
    field.value = path;
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
    if (activeWidget) updateWidget(activeWidget, path);
  };
  const choose = (item) => {
    if (!activeField || !item.path) return;
    setFieldValue(activeField, item.path, filenameFromItem(item));
    if (dialog.open) dialog.close();
    activeWidget?.querySelector('[data-media-field-open]')?.focus();
  };
  const renderItems = (items) => {
    grid.innerHTML = '';
    if (!items.length) {
      grid.innerHTML = '<div class="media-picker-empty"><b>Brak obrazów</b><span>Wgraj pierwszy obraz albo zmień wyszukiwanie.</span></div>';
      return;
    }
    items.forEach((item) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'media-picker-card';
      button.title = filenameFromItem(item);
      const image = document.createElement('img');
      image.src = item.path;
      image.alt = '';
      image.loading = 'lazy';
      const name = document.createElement('span');
      name.textContent = filenameFromItem(item);
      button.append(image, name);
      button.addEventListener('click', () => choose(item));
      grid.append(button);
    });
  };
  const loadItems = async (query = '') => {
    setStatus('Wczytywanie obrazów…', 'progress');
    try {
      const response = await fetch(`/admin/media/picker?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result.ok) throw new Error(result.message || 'Nie udało się wczytać biblioteki.');
      renderItems(result.items || []);
      setStatus(`${(result.items || []).length} obrazów`, 'success');
    } catch (error) {
      renderItems([]);
      setStatus(error instanceof Error ? error.message : 'Nie udało się wczytać biblioteki.', 'error');
    }
  };
  const csrfToken = () => activeField?.closest('form')?.querySelector('input[name="_csrf"]')?.value || document.querySelector('input[name="_csrf"]')?.value || '';
  const upload = async (file) => {
    if (!file) return;
    const payload = new FormData();
    payload.append('_csrf', csrfToken());
    payload.append('upload', file, file.name);
    setStatus(`Przesyłanie „${file.name}”…`, 'progress');
    dialog.classList.add('is-uploading');
    try {
      const response = await fetch('/admin/media/upload', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: payload, credentials: 'same-origin' });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result.ok) throw new Error(result.message || 'Nie udało się przesłać obrazu.');
      choose(result.item);
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Nie udało się przesłać obrazu.', 'error');
    } finally {
      dialog.classList.remove('is-uploading');
      fileInput.value = '';
    }
  };

  dialog.querySelector('[data-media-picker-close]').addEventListener('click', () => { if (dialog.open) dialog.close(); });
  dialog.addEventListener('click', (event) => { if (event.target === dialog && dialog.open) dialog.close(); });
  uploadButton.addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', () => upload(fileInput.files?.[0]));
  dropzone.addEventListener('click', () => fileInput.click());
  dropzone.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); fileInput.click(); } });
  ['dragenter', 'dragover'].forEach((name) => dropzone.addEventListener(name, (event) => { event.preventDefault(); dropzone.classList.add('is-dragover'); }));
  ['dragleave', 'drop'].forEach((name) => dropzone.addEventListener(name, () => dropzone.classList.remove('is-dragover')));
  dropzone.addEventListener('drop', (event) => { event.preventDefault(); upload(event.dataTransfer?.files?.[0]); });
  search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadItems(search.value.trim()), 250); });

  candidates.forEach((field) => {
    const widget = document.createElement('span');
    widget.className = 'media-field';
    widget.dataset.mediaField = '';
    widget.innerHTML = '<span class="media-field__preview"><img data-media-field-preview alt="" hidden><span data-media-field-empty>Brak wybranego obrazu</span></span><span class="media-field__actions"><button type="button" class="button secondary" data-media-field-open>Wybierz lub wgraj</button><button type="button" class="button secondary" data-media-field-remove>Usuń</button></span>';
    field.before(widget);
    widget.append(field);
    field.classList.add('media-field__source');
    updateWidget(widget, valueOf(field));
    widget.querySelector('[data-media-field-open]').addEventListener('click', () => {
      activeField = field;
      activeWidget = widget;
      search.value = '';
      dialog.showModal();
      loadItems();
      search.focus();
    });
    widget.querySelector('[data-media-field-remove]').addEventListener('click', () => {
      activeField = field;
      activeWidget = widget;
      setFieldValue(field, '');
    });
  });
})();
