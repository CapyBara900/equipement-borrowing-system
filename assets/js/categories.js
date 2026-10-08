/* Shared category catalog and editor for admin/staff; customers subscribe read-only. */
const CategoryStore = (() => {
  let rows = [], signature = '', started = false, stream = null, channel = null, pending = null;
  let generation = 0;
  const listeners = new Set();
  async function accept(data) {
    const next = JSON.stringify(data);
    if (next === signature) return;
    const previous = rows;
    rows = data; signature = next; generation++;
    await Promise.all([...listeners].map(listener => listener(rows, previous)));
  }
  async function refresh() {
    if (pending) await pending;
    const before = generation;
    const task = (async () => {
      const { data } = await Api.listCategories({ all: 1 });
      // A newer stream snapshot must not be replaced by an older in-flight GET.
      if (before === generation) await accept(data);
    })();
    pending = task;
    try { await task; } finally { if (pending === task) pending = null; }
  }
  const receive = () => refresh().catch(() => {});
  async function start(listener) {
    if (listener) listeners.add(listener);
    if (started) { if (listener) await listener(rows, []); return; }
    started = true;
    try { await refresh(); } catch (error) { toast(error.message, 'bad'); }
    if (typeof BroadcastChannel !== 'undefined') {
      channel = new BroadcastChannel('equipment-categories');
      channel.onmessage = receive;
    }
    window.addEventListener('storage', event => { if (event.key === 'equipment-categories-changed') receive(); });
    window.addEventListener('focus', receive);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) receive(); });
    if (typeof EventSource !== 'undefined') {
      stream = new EventSource('api/categories/events.php');
      stream.addEventListener('categories', event => {
        try { accept(JSON.parse(event.data)).catch(() => {}); } catch (_) { receive(); }
      });
      stream.addEventListener('unavailable', receive);
    }
    // Fallback for proxies that buffer/disable event streams and browsers without EventSource.
    const poll = setInterval(() => { if (!document.hidden) receive(); }, 5000);
    window.addEventListener('pagehide', () => {
      clearInterval(poll); stream?.close(); channel?.close();
    }, { once: true });
    window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
  }
  async function changed() {
    channel?.postMessage('changed');
    try { localStorage.setItem('equipment-categories-changed', String(Date.now()) + Math.random()); } catch (_) {}
    await refresh();
  }
  return { start, refresh, changed, subscribe: listener => listeners.add(listener), get rows() { return rows; } };
})();

const CategoryManager = (() => {
  let busy = false, syncButton = null, initialized = false;
  const modal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('categoryModal'));
  const normalize = value => value.replace(/[\s\p{Z}]+/gu, ' ').trim();
  const rules = {
    categoryName: [{
      test: value => {
        const name = normalize(value);
        return name.length > 0 && [...name].length <= 100 && /[\p{L}\p{N}]/u.test(name) && !/[<>\p{C}]/u.test(name);
      },
      message: 'Enter a name of 1–100 characters containing a letter or number, without markup or control characters.',
    }],
    categoryDescription: [{ test: value => [...value].length <= 500, message: 'Description must be 500 characters or fewer.' }],
  };
  function render(rows) {
    const host = document.getElementById('categoryList');
    if (!host) return;
    host.innerHTML = rows.length ? rows.map(category => `
      <article class="item-row">
        <div class="grow">
          <h3>${esc(category.category_name)}</h3>
          <div class="meta">${esc(category.description || 'No description')}</div>
          <div class="meta mt-1">${esc(category.equipment_count)} equipment item(s)</div>
        </div>
        <div class="actions">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-edit-category="${esc(category.category_id)}">Edit</button>
          <button type="button" class="btn btn-sm btn-outline-danger" data-delete-category="${esc(category.category_id)}">Delete</button>
        </div>
      </article>`).join('') : emptyState('No categories yet', 'Add one so equipment can be grouped and filtered.');
  }
  async function open(id = null) {
    if (busy) return;
    let existing = null;
    if (id) {
      try { ({ data: existing } = await Api.listCategories({ id })); }
      catch (error) { toast(error.message, 'bad'); return; }
    }
    document.getElementById('categoryTitle').textContent = existing ? 'Edit category' : 'Add Category';
    document.getElementById('categoryId').value = existing?.category_id || '';
    for (const [field, value] of [['categoryName', existing?.category_name || ''], ['categoryDescription', existing?.description || '']]) {
      const input = document.getElementById(field); input.value = value; clearError(input);
    }
    syncButton(); modal().show();
  }
  async function remove(id, button) {
    if (busy) return;
    const category = CategoryStore.rows.find(row => String(row.category_id) === String(id));
    if (!category) return;
    if (Number(category.equipment_count) > 0) {
      toast('This category is assigned to equipment. Reassign all equipment to another category before deleting it.', 'bad'); return;
    }
    if (!confirm(`Delete "${category.category_name}"?`)) return;
    busy = true; button.disabled = true;
    try {
      await Api.deleteCategory(id); toast('Category deleted.');
      await CategoryStore.changed();
    } catch (error) { toast(error.message, 'bad'); }
    finally { busy = false; button.disabled = false; }
  }
  function init(user) {
    if (initialized || !['admin', 'staff'].includes(user.role)) return;
    initialized = true;
    if (!document.getElementById('categoryModal')) document.body.insertAdjacentHTML('beforeend', `
<!-- Category modal -->
<div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryTitle">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="categoryTitle">Add category</h2>
        <button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="categoryForm" novalidate>
        <div class="modal-body">
          <input type="hidden" id="categoryId">
          <p class="form-text">Names are unique regardless of capitalization or extra spaces.</p>
          <div class="mb-3">
            <label class="form-label" for="categoryName">Name</label>
            <input class="form-control" type="text" id="categoryName" maxlength="100" required>
            <div class="field-error" data-error-for="categoryName"></div>
          </div>
          <div class="mb-1">
            <label class="form-label" for="categoryDescription">Description</label>
            <textarea class="form-control" id="categoryDescription" rows="2" maxlength="500"></textarea>
            <div class="field-error" data-error-for="categoryDescription"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit" id="categorySubmit" disabled>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>
`);
    const add = document.getElementById('addCategoryBtn');
    add.hidden = false; add.addEventListener('click', () => open());
    const manage = document.getElementById('manageCategoriesBtn');
    if (manage) {
      manage.hidden = false;
      manage.addEventListener('click', () => {
        const panel = document.getElementById('categoryManagement'); panel.open = !panel.open;
        if (panel.open) { panel.scrollIntoView({ block: 'nearest' }); CategoryStore.refresh().catch(error => toast(error.message, 'bad')); }
      });
      document.getElementById('categoryManagement').hidden = false;
    }
    CategoryStore.subscribe(render);
    document.getElementById('categoryList').addEventListener('click', event => {
      const edit = event.target.closest('[data-edit-category]');
      const del = event.target.closest('[data-delete-category]');
      if (edit) open(edit.dataset.editCategory);
      if (del) remove(del.dataset.deleteCategory, del);
    });
    syncButton = liveValidate(rules, 'categorySubmit');
    document.getElementById('categoryModal').addEventListener('shown.bs.modal', () => document.getElementById('categoryName').focus());
    document.getElementById('categoryModal').addEventListener('hide.bs.modal', event => { if (busy) event.preventDefault(); });
    document.getElementById('categoryForm').addEventListener('submit', async event => {
      event.preventDefault(); if (busy || !validate(rules)) return;
      const id = document.getElementById('categoryId').value;
      const payload = { category_name: normalize(document.getElementById('categoryName').value), description: document.getElementById('categoryDescription').value.trim() };
      const button = document.getElementById('categorySubmit');
      const inputs = ['categoryName', 'categoryDescription'].map(field => document.getElementById(field));
      busy = true; button.disabled = true; button.textContent = 'Saving…'; inputs.forEach(input => input.disabled = true);
      try {
        if (id) await Api.updateCategory({ ...payload, category_id: id });
        else await Api.createCategory(payload);
        busy = false; modal().hide(); toast(id ? 'Category updated.' : 'Category added.');
        await CategoryStore.changed();
      } catch (error) {
        toast(error.message, 'bad');
        if (error.code === 'CATEGORY_DUPLICATE' || error.status === 400) showError(inputs[0], error.message);
      } finally {
        busy = false; inputs.forEach(input => input.disabled = false); button.textContent = 'Save'; syncButton();
      }
    });
  }
  return { init };
})();
