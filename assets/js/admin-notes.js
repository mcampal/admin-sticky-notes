/**
 * WP Admin Sticky Notes — frontend logic.
 *
 * Relies on `wasnData` localized by wp_localize_script:
 *   { restUrl, nonce, currentUrl, screenId, canManage }
 *
 * Position semantics
 * ------------------
 * Notes have two positioning modes, distinguished by whether `selector` is set:
 *
 *   Anchor mode  (selector is non-empty):
 *     pos_x / pos_y are the click offset from the anchor element's top-left
 *     corner (viewport-relative at capture time). On render they are converted
 *     to absolute document coordinates: anchorRect + scroll + offset.
 *     The note is rendered as `position: absolute` so it scrolls with the page.
 *
 *   Absolute mode (selector is empty, e.g. after drag-to-reposition):
 *     pos_x / pos_y are absolute document coordinates (scroll already baked in).
 *     The note is rendered as `position: absolute`.
 *
 *   Fallback (no selector, pos_x === 0 && pos_y === 0):
 *     Rendered as `position: fixed` at the bottom-right corner.
 */
(function () {
  'use strict';

  if (typeof wasnData === 'undefined') return;

  const { restUrl, nonce, currentUrl, screenId, canManage } = wasnData;

  // -------------------------------------------------------------------------
  // State
  // -------------------------------------------------------------------------
  let placementMode = false;
  let notes = [];
  let editingId = null;       // null = creating, number = editing
  let keydownHandler = null;  // holds the Escape-key listener while modal is open
  let fallbackCount = 0;      // tracks how many fallback-positioned notes are rendered

  // -------------------------------------------------------------------------
  // API helpers
  // -------------------------------------------------------------------------
  async function apiFetch(path, options = {}) {
    // Destructure headers separately so callers can add headers without
    // accidentally overwriting the required Content-Type and nonce headers.
    const { headers: extraHeaders = {}, ...restOptions } = options;
    const res = await fetch(restUrl + path, {
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce,
        ...extraHeaders,
      },
      ...restOptions,
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.message || 'Request failed');
    }
    return res.json();
  }

  async function fetchNotes() {
    const params = new URLSearchParams();
    if (screenId)    params.set('screen_id', screenId);
    if (currentUrl)  params.set('page_url',  currentUrl);
    return apiFetch('/notes?' + params.toString());
  }

  async function createNote(data) {
    return apiFetch('/notes', { method: 'POST', body: JSON.stringify(data) });
  }

  async function updateNote(id, data) {
    return apiFetch('/notes/' + id, { method: 'PUT', body: JSON.stringify(data) });
  }

  async function deleteNote(id) {
    return apiFetch('/notes/' + id, { method: 'DELETE' });
  }

  async function dismissNote(id) {
    return apiFetch('/notes/' + id + '/dismiss', { method: 'POST' });
  }

  // -------------------------------------------------------------------------
  // DOM helper
  // -------------------------------------------------------------------------
  function el(tag, props = {}, ...children) {
    const node = document.createElement(tag);
    for (const [key, val] of Object.entries(props)) {
      if (key.startsWith('data-')) {
        node.setAttribute(key, val);
      } else {
        node[key] = val;
      }
    }
    children.flat().forEach((c) =>
      node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c)
    );
    return node;
  }

  // -------------------------------------------------------------------------
  // Floating "Add Note" button
  // -------------------------------------------------------------------------
  function createAddButton() {
    const btn = el('button', {
      id: 'wasn-add-btn',
      title: 'Add sticky note',
      className: 'wasn-add-btn',
    }, '➕ Add Note');

    btn.addEventListener('click', () => {
      if (placementMode) {
        exitPlacementMode();
      } else {
        enterPlacementMode();
      }
    });

    document.body.appendChild(btn);
  }

  // -------------------------------------------------------------------------
  // Placement mode
  // -------------------------------------------------------------------------
  function enterPlacementMode() {
    placementMode = true;
    document.body.classList.add('wasn-placement-mode');
    document.getElementById('wasn-add-btn').textContent = '✖ Cancel';
    document.addEventListener('click', onPlacementClick, true);
    showToast('Click anywhere on the page to place a note.');
  }

  function exitPlacementMode() {
    placementMode = false;
    document.body.classList.remove('wasn-placement-mode');
    document.getElementById('wasn-add-btn').textContent = '➕ Add Note';
    document.removeEventListener('click', onPlacementClick, true);
  }

  function onPlacementClick(e) {
    // Ignore clicks on our own UI.
    if (e.target.closest('#wasn-add-btn, #wasn-modal-overlay, .wasn-note')) return;

    e.preventDefault();
    e.stopPropagation();

    exitPlacementMode();

    const selector = buildSelector(e.target);
    const rect = e.target.getBoundingClientRect();
    // Store offset relative to the anchor element's top-left corner.
    const posX = e.clientX - rect.left;
    const posY = e.clientY - rect.top;

    openModal(null, { selector, posX, posY, anchorEl: e.target });
  }

  /**
   * Build a reasonably unique CSS selector for the clicked element.
   * Walks up to 3 levels, stopping early if an ID is found.
   *
   * Parameter renamed from `el` to avoid shadowing the el() helper above.
   */
  function buildSelector(targetEl) {
    const parts = [];
    let node = targetEl;

    for (let i = 0; i < 3 && node && node !== document.body; i++) {
      if (node.id) {
        parts.unshift('#' + CSS.escape(node.id));
        break;
      }
      let selector = node.tagName.toLowerCase();
      if (node.className && typeof node.className === 'string') {
        const classes = Array.from(node.classList)
          .filter((c) => !c.startsWith('wasn-'))
          .slice(0, 2)
          .map((c) => '.' + CSS.escape(c))
          .join('');
        selector += classes;
      }
      parts.unshift(selector);
      node = node.parentElement;
    }

    return parts.join(' > ');
  }

  // -------------------------------------------------------------------------
  // Modal (create / edit)
  // -------------------------------------------------------------------------
  function openModal(noteId, placement = {}) {
    editingId = noteId;

    const existing = noteId ? notes.find((n) => n.id === noteId) : null;
    const colors = ['yellow', 'red', 'green', 'blue'];

    const overlay = el('div', { id: 'wasn-modal-overlay', className: 'wasn-modal-overlay' });
    const modal   = el('div', { className: 'wasn-modal' });
    const title   = el('h3', {}, noteId ? 'Edit Note' : 'New Sticky Note');

    // Textarea
    const textarea = el('textarea', {
      className: 'wasn-modal-textarea',
      placeholder: 'Write your note here…',
      rows: 4,
    });
    if (existing) textarea.value = existing.content;

    // Color picker
    const colorRow = el('div', { className: 'wasn-color-row' });
    colors.forEach((color) => {
      const swatch = el('button', {
        className: 'wasn-color-swatch wasn-color-' + color,
        title: color,
        type: 'button',
      });
      swatch.dataset.color = color;
      if ((existing ? existing.color : 'yellow') === color) {
        swatch.classList.add('active');
      }
      swatch.addEventListener('click', () => {
        colorRow.querySelectorAll('.wasn-color-swatch').forEach((s) => s.classList.remove('active'));
        swatch.classList.add('active');
      });
      colorRow.appendChild(swatch);
    });

    // Buttons
    const saveBtn   = el('button', { className: 'button button-primary', type: 'button' }, 'Save');
    const cancelBtn = el('button', { className: 'button',                type: 'button' }, 'Cancel');

    saveBtn.addEventListener('click', async () => {
      const content = textarea.value.trim();
      if (!content) { textarea.focus(); return; }

      const activeColor = colorRow.querySelector('.wasn-color-swatch.active');
      const color = activeColor ? activeColor.dataset.color : 'yellow';

      saveBtn.disabled    = true;
      saveBtn.textContent = 'Saving…';

      try {
        let saved;
        if (editingId) {
          saved = await updateNote(editingId, { content, color });
          notes = notes.map((n) => (n.id === saved.id ? saved : n));
          // Re-render the updated note.
          const existingEl = document.querySelector('.wasn-note[data-id="' + saved.id + '"]');
          if (existingEl) existingEl.remove();
          renderNote(saved);
        } else {
          const noteData = {
            content,
            color,
            screen_id: screenId,
            page_url:  currentUrl,
            selector:  placement.selector || '',
            pos_x:     placement.posX || 0,
            pos_y:     placement.posY || 0,
          };
          saved = await createNote(noteData);
          notes.push(saved);
          renderNote(saved, placement.anchorEl);
        }
        closeModal();
      } catch (err) {
        saveBtn.disabled    = false;
        saveBtn.textContent = 'Save';
        showToast('Error: ' + err.message, 'error');
      }
    });

    cancelBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });

    // Escape key closes the modal.
    keydownHandler = (e) => { if (e.key === 'Escape') closeModal(); };
    document.addEventListener('keydown', keydownHandler);

    modal.appendChild(title);
    modal.appendChild(textarea);
    modal.appendChild(colorRow);
    const btnRow = el('div', { className: 'wasn-modal-buttons' });
    btnRow.appendChild(saveBtn);
    btnRow.appendChild(cancelBtn);
    modal.appendChild(btnRow);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    textarea.focus();
  }

  function closeModal() {
    const overlay = document.getElementById('wasn-modal-overlay');
    if (overlay) overlay.remove();
    if (keydownHandler) {
      document.removeEventListener('keydown', keydownHandler);
      keydownHandler = null;
    }
    editingId = null;
  }

  // -------------------------------------------------------------------------
  // Render a single note on the page
  // -------------------------------------------------------------------------
  function renderNote(note, anchorEl) {
    if (note.dismissed) return;

    // Resolve anchor element from stored selector when not passed directly.
    if (!anchorEl && note.selector) {
      try {
        anchorEl = document.querySelector(note.selector);
      } catch (_) {
        anchorEl = null;
      }
    }

    const noteEl = el('div', { className: 'wasn-note wasn-color-' + note.color });
    noteEl.dataset.id = String(note.id);

    // Position the note.
    if (anchorEl) {
      // Anchor mode: convert viewport rect + current scroll + stored offset
      // to absolute document coordinates. `position: absolute` then scrolls
      // with the document (the note stays pinned to its place on the page).
      const rect    = anchorEl.getBoundingClientRect();
      const scrollX = window.scrollX || window.pageXOffset;
      const scrollY = window.scrollY || window.pageYOffset;
      noteEl.style.position = 'absolute';
      noteEl.style.left     = Math.round(rect.left + scrollX + (note.pos_x || 0)) + 'px';
      noteEl.style.top      = Math.round(rect.top  + scrollY + (note.pos_y || 0)) + 'px';
    } else if (note.pos_x !== 0 || note.pos_y !== 0) {
      // Absolute mode: user dragged the note to a custom position.
      noteEl.style.position = 'absolute';
      noteEl.style.left     = (note.pos_x || 0) + 'px';
      noteEl.style.top      = (note.pos_y || 0) + 'px';
    } else {
      // Fallback: fixed to the bottom-right corner.
      // Cascade each subsequent fallback note diagonally so they don't overlap.
      const offset = fallbackCount * 24;
      noteEl.style.position = 'fixed';
      noteEl.style.bottom   = (80 + offset) + 'px';
      noteEl.style.right    = (20 + offset) + 'px';
      fallbackCount++;
    }

    // Content
    const contentEl = el('div', { className: 'wasn-note-content' }, note.content);

    // Meta (author + date)
    const date    = note.date ? new Date(note.date).toLocaleDateString() : '';
    const metaEl  = el('div', { className: 'wasn-note-meta' },
      (note.author_name || '') + (date ? ' · ' + date : '')
    );

    // Action buttons
    const actions = el('div', { className: 'wasn-note-actions' });

    const dismissBtn = el('button', { className: 'wasn-btn-dismiss', title: 'Dismiss (hide for me)' }, '✕');
    dismissBtn.addEventListener('click', async () => {
      try {
        await dismissNote(note.id);
        noteEl.remove();
        notes = notes.filter((n) => n.id !== note.id);
      } catch (err) {
        showToast('Could not dismiss note: ' + err.message, 'error');
      }
    });
    actions.appendChild(dismissBtn);

    if (canManage) {
      const editBtn = el('button', { className: 'wasn-btn-edit', title: 'Edit note' }, '✎');
      editBtn.addEventListener('click', () => openModal(note.id));
      actions.appendChild(editBtn);

      const deleteBtn = el('button', { className: 'wasn-btn-delete', title: 'Delete note' }, '🗑');
      deleteBtn.addEventListener('click', async () => {
        if (!confirm('Delete this note?')) return;
        try {
          await deleteNote(note.id);
          noteEl.remove();
          notes = notes.filter((n) => n.id !== note.id);
        } catch (err) {
          showToast('Could not delete note: ' + err.message, 'error');
        }
      });
      actions.appendChild(deleteBtn);
    }

    noteEl.appendChild(actions);
    noteEl.appendChild(contentEl);
    noteEl.appendChild(metaEl);

    makeDraggable(noteEl, note.id);

    document.body.appendChild(noteEl);
  }

  // -------------------------------------------------------------------------
  // Drag-to-reposition (persists new position to the server)
  // -------------------------------------------------------------------------
  function makeDraggable(noteEl, noteId) {
    let startX, startY, startLeft, startTop;

    noteEl.addEventListener('mousedown', (e) => {
      if (e.target.tagName === 'BUTTON') return;
      e.preventDefault();

      startX    = e.clientX;
      startY    = e.clientY;
      startLeft = parseInt(noteEl.style.left,   10) || 0;
      startTop  = parseInt(noteEl.style.top,    10) || 0;

      // Switch to absolute positioning if the note is currently fixed.
      if (noteEl.style.position === 'fixed') {
        const rect = noteEl.getBoundingClientRect();
        const scrollX = window.scrollX || window.pageXOffset;
        const scrollY = window.scrollY || window.pageYOffset;
        startLeft = rect.left + scrollX;
        startTop  = rect.top  + scrollY;
        noteEl.style.position = 'absolute';
        noteEl.style.left     = startLeft + 'px';
        noteEl.style.top      = startTop  + 'px';
        noteEl.style.bottom   = '';
        noteEl.style.right    = '';
      }

      function onMove(ev) {
        noteEl.style.left = startLeft + (ev.clientX - startX) + 'px';
        noteEl.style.top  = startTop  + (ev.clientY - startY) + 'px';
      }

      function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup',   onUp);

        const newLeft = parseInt(noteEl.style.left, 10) || 0;
        const newTop  = parseInt(noteEl.style.top,  10) || 0;

        // Persist absolute position; clear selector so the note is no longer
        // re-anchored to the DOM element on next page load.
        updateNote(noteId, { pos_x: newLeft, pos_y: newTop, selector: '' })
          .catch(() => {}); // fire-and-forget — position is already correct visually

        notes = notes.map((n) =>
          n.id === noteId ? { ...n, pos_x: newLeft, pos_y: newTop, selector: '' } : n
        );
      }

      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup',   onUp);
    });
  }

  // -------------------------------------------------------------------------
  // Management page — wire up the delete buttons in the notes table.
  // The table is rendered by WASN_Notes_CPT::render_admin_page(); this script
  // is already enqueued there so no extra enqueue is needed.
  // -------------------------------------------------------------------------
  /**
   * Wire up delete buttons on the Settings → Sticky Notes management table.
   * Returns true when we're on that page so boot() can skip fetching/rendering
   * floating notes (unnecessary REST call, and notes floating over the table
   * would be confusing).
   */
  function initManagementPage() {
    const deleteButtons = document.querySelectorAll('.wasn-delete-note');
    if (!deleteButtons.length) return false;

    deleteButtons.forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Delete this note?')) return;
        const id = btn.dataset.id;
        try {
          await deleteNote(id);
          const row = document.getElementById('wasn-note-row-' + id);
          if (row) row.remove();
        } catch (err) {
          showToast('Error: ' + err.message, 'error');
        }
      });
    });

    return true;
  }

  // -------------------------------------------------------------------------
  // Toast notifications
  // -------------------------------------------------------------------------
  function showToast(message, type = 'info') {
    const existing = document.getElementById('wasn-toast');
    if (existing) existing.remove();

    const toast = el('div', { id: 'wasn-toast', className: 'wasn-toast wasn-toast-' + type }, message);
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3500);
  }

  // -------------------------------------------------------------------------
  // Boot
  // -------------------------------------------------------------------------
  async function boot() {
    const isManagementPage = initManagementPage();

    if (canManage) createAddButton();

    // Skip fetching and rendering on the management page — the note list is
    // already rendered server-side there, and floating notes over the table
    // would be confusing.
    if (isManagementPage) return;

    try {
      notes = await fetchNotes();
      notes.forEach((note) => renderNote(note));
    } catch (err) {
      console.warn('[WASN] Could not load notes:', err.message);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
