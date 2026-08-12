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
})();
