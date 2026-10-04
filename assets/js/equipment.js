/* Equipment catalog: search, filter, sort, paginate, request, and (for
   admins) full CRUD. Everything refreshes through fetch — no page reloads. */

const PAGE_SIZE = 10;
let state = { page: 1, sortDir: 'ASC' };
let categories = [];
let user = null;

(async function () {
  user = await requireSession();

  if (user.role === 'admin') {
    const addBtn = document.getElementById('addEquipmentBtn');
    addBtn.hidden = false;
    addBtn.addEventListener('click', () => openEditor(null));
  }

  await loadCategories();
  wireControls();
  await loadEquipment();
})();

async function loadCategories() {
  try {
    const { data } = await Api.listCategories({ limit: 50 });
    categories = data;
    const options = data.map(c =>
      `<option value="${esc(c.category_id)}">${esc(c.category_name)}</option>`).join('');
    document.getElementById('categoryFilter').insertAdjacentHTML('beforeend', options);
    document.getElementById('editCategory').insertAdjacentHTML('beforeend', options);
  } catch (err) {
    toast(err.message, 'bad');
  }
}

function wireControls() {
  const reload = () => { state.page = 1; loadEquipment(); };

  document.getElementById('searchInput').addEventListener('input', debounce(reload, 300));
  document.getElementById('categoryFilter').addEventListener('change', reload);
  document.getElementById('statusFilter').addEventListener('change', reload);
  document.getElementById('sortBy').addEventListener('change', reload);

  document.getElementById('sortDirBtn').addEventListener('click', (e) => {
    state.sortDir = state.sortDir === 'ASC' ? 'DESC' : 'ASC';
    e.currentTarget.textContent = state.sortDir === 'ASC' ? 'A–Z' : 'Z–A';
    reload();
  });

  document.getElementById('prevPage').addEventListener('click', () => {
    if (state.page > 1) { state.page--; loadEquipment(); }
  });
  document.getElementById('nextPage').addEventListener('click', () => {
    state.page++; loadEquipment();
  });
}

async function loadEquipment() {
  const host = document.getElementById('equipmentList');
  host.innerHTML = '<div class="empty">Loading equipment…</div>';

  const query = {
    search: document.getElementById('searchInput').value.trim(),
    category_id: document.getElementById('categoryFilter').value,
    status: document.getElementById('statusFilter').value,
    sort_by: document.getElementById('sortBy').value,
    sort_dir: state.sortDir,
    page: state.page,
    limit: PAGE_SIZE,
  };

  let rows;
  try {
    ({ data: rows } = await Api.listEquipment(query));
  } catch (err) {
    host.innerHTML = emptyState("Couldn't load equipment", err.message);
    return;
  }

  const meta = document.getElementById('resultMeta');
  const filtered = query.search || query.category_id || query.status;

  if (!rows.length) {
    host.innerHTML = state.page > 1
      ? emptyState('No more items', 'Go back to the previous page.')
      : emptyState('Nothing matches those filters', 'Try a different search term or clear the filters.');
    meta.textContent = '';
  } else {
    meta.textContent = filtered
      ? `${rows.length} match${rows.length === 1 ? '' : 'es'} on this page`
      : `Showing ${rows.length} item${rows.length === 1 ? '' : 's'}`;
    host.innerHTML = rows.map(rowMarkup).join('');
    bindRowActions();
  }

  const pager = document.getElementById('pager');
  pager.hidden = (state.page === 1 && rows.length < PAGE_SIZE);
  document.getElementById('pageLabel').textContent = `Page ${state.page}`;
  document.getElementById('prevPage').disabled = state.page === 1;
  document.getElementById('nextPage').disabled = rows.length < PAGE_SIZE;
}

function rowMarkup(item) {
  const isCustomer = user.role === 'customer';
  // Customers receive a server-computed 'status' field:
  //   'available'   → item is free to request
  //   'pending'     → this customer's OWN request is pending
  //   'unavailable' → another customer's request is pending/approved
  //   'borrowed'    → this customer's approved request (item with them)
  //   'maintenance' → out for repair
  const canBorrow = item.status === 'available' && isCustomer;
  const isAdmin = user.role === 'admin';
  const isDesk = user.role === 'admin' || user.role === 'staff';

  // Admin/staff: show who submitted the pending request (from pending_user_name).
  // This helps staff act without leaving the equipment page to find the request.
  const pendingRequesterBadge = isDesk && item.status === 'pending' && item.pending_user_name
    ? `<span class="badge bg-warning text-dark ms-2" title="Request #${esc(item.pending_request_id)}">
         Requested by ${esc(item.pending_user_name)}
       </span>`
    : '';

  return `
    <article class="item-row s-${esc(item.status)}">
      <div class="grow">
        <h3>${esc(item.equipment_name)}</h3>
        <div class="meta">
          ${pill(item.status)}
          ${pendingRequesterBadge}
          ${item.category_name ? ' · ' + esc(item.category_name) : ''}
          ${item.serial_number ? ' · <span class="serial">' + esc(item.serial_number) + '</span>' : ''}
        </div>
        ${item.description ? `<div class="meta mt-1">${esc(item.description)}</div>` : ''}
      </div>
      <div class="actions">
        ${isCustomer
          ? (canBorrow
              ? `<button class="btn btn-sm btn-primary" data-borrow="${esc(item.equipment_id)}"
                             data-name="${esc(item.equipment_name)}">Request</button>`
              : (item.status === 'pending'
                  ? `<button class="btn btn-sm btn-outline-secondary" disabled>Pending Request</button>`
                  : `<button class="btn btn-sm btn-outline-secondary" disabled>Unavailable</button>`))
          : ''
        }
        ${isDesk && item.serial_number
      ? `<button class="btn btn-sm btn-outline-secondary" data-qr="${esc(item.serial_number)}"
                     data-name="${esc(item.equipment_name)}">Label</button>` : ''}
        ${isAdmin
      ? `<button class="btn btn-sm btn-outline-secondary" data-edit="${esc(item.equipment_id)}">Edit</button>
             <button class="btn btn-sm btn-outline-danger" data-delete="${esc(item.equipment_id)}"
                     data-name="${esc(item.equipment_name)}">Delete</button>` : ''}
      </div>
    </article>`;
}

function bindRowActions() {
  document.querySelectorAll('[data-borrow]').forEach(btn => {
    btn.addEventListener('click', () => openBorrow(btn.dataset.borrow, btn.dataset.name));
  });
  document.querySelectorAll('[data-qr]').forEach(btn => {
    btn.addEventListener('click', () => showQr(btn.dataset.qr, btn.dataset.name));
  });
  document.querySelectorAll('[data-edit]').forEach(btn => {
    btn.addEventListener('click', () => openEditor(btn.dataset.edit));
  });
  document.querySelectorAll('[data-delete]').forEach(btn => {
    btn.addEventListener('click', () => removeEquipment(btn.dataset.delete, btn.dataset.name));
  });
}

/* ---------- Borrow ---------- */

const borrowModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('borrowModal'));

const borrowRules = {
  borrowDate: [
    Rules.required('Pick up date'),
    Rules.notPastDate('Pick up date')
  ],
  expectedReturnDate: [
    Rules.required('Return date'),
    Rules.onOrAfterField('borrowDate', 'Return date')
  ],
};
const syncBorrowButton = liveValidate(borrowRules, 'borrowSubmit');

function openBorrow(equipmentId, name) {
  document.getElementById('borrowEquipmentId').value = equipmentId;
  document.getElementById('borrowItemName').textContent = name;

  const today = new Date().toISOString().split('T')[0];
  const inAWeek = new Date(Date.now() + 7 * 86400000).toISOString().split('T')[0];
  const borrowDate = document.getElementById('borrowDate');
  const returnDate = document.getElementById('expectedReturnDate');

  borrowDate.min = today;
  returnDate.min = today;
  borrowDate.value = today;
  returnDate.value = inAWeek;
  returnDate.min = today;
  clearError(borrowDate);
  clearError(returnDate);
  syncBorrowButton();

  borrowModal().show();
}

document.getElementById('borrowDate').addEventListener('change', () => {
  const borrowDate = document.getElementById('borrowDate').value;
  const returnDate = document.getElementById('expectedReturnDate');

  // Return date can be the same day as the pick up date.
  returnDate.min = borrowDate || new Date().toISOString().split('T')[0];

  // Only clear the return date if it is BEFORE the pick up date.
  if (returnDate.value && borrowDate && returnDate.value < borrowDate) {
    returnDate.value = '';
  }

  syncBorrowButton();
});

document.getElementById('borrowForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!validate(borrowRules)) return;

  const btn = document.getElementById('borrowSubmit');
  btn.disabled = true;
  btn.textContent = 'Sending…';

  try {
    await Api.createRequest({
      equipment_id: document.getElementById('borrowEquipmentId').value,
      borrow_date: document.getElementById('borrowDate').value,
      expected_return_date: document.getElementById('expectedReturnDate').value,
    });
    borrowModal().hide();
    toast('Request sent. Staff will review it shortly.');
    await loadEquipment();
  } catch (err) {
    toast(err.message, 'bad');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Send request';
  }
});

/* ---------- Admin: add / edit / delete ---------- */

const editModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal'));

const editRules = {
  editName: [Rules.required('Name'), Rules.maxLength(150, 'Name')],
  editSerial: [Rules.maxLength(100, 'Serial number')],
  editDescription: [Rules.maxLength(500, 'Description')],
};
const syncEditButton = liveValidate(editRules, 'editSubmit');

async function openEditor(equipmentId) {
  document.getElementById('editTitle').textContent = equipmentId ? 'Edit equipment' : 'Add equipment';
  document.getElementById('editId').value = equipmentId || '';

  ['editName', 'editSerial', 'editDescription'].forEach(id => {
    const el = document.getElementById(id);
    el.value = '';
    clearError(el);
  });
  document.getElementById('editCategory').value = '';
  document.getElementById('editStatus').value = 'available';

  if (equipmentId) {
    try {
      const { data } = await Api.getEquipment(equipmentId);
      document.getElementById('editName').value = data.equipment_name || '';
      document.getElementById('editSerial').value = data.serial_number || '';
      document.getElementById('editDescription').value = data.description || '';
      document.getElementById('editCategory').value = data.category_id || '';
      document.getElementById('editStatus').value = data.status || 'available';
    } catch (err) {
      toast(err.message, 'bad');
      return;
    }
  }
  syncEditButton();
  editModal().show();
}

document.getElementById('editForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!validate(editRules)) return;

  const id = document.getElementById('editId').value;
  const payload = {
    equipment_name: document.getElementById('editName').value.trim(),
    serial_number: document.getElementById('editSerial').value.trim(),
    description: document.getElementById('editDescription').value.trim(),
    category_id: document.getElementById('editCategory').value || null,
    status: document.getElementById('editStatus').value,
  };

  const btn = document.getElementById('editSubmit');
  btn.disabled = true;
  btn.textContent = 'Saving…';

  try {
    if (id) {
      await Api.updateEquipment({ ...payload, equipment_id: id });
      toast('Equipment updated.');
    } else {
      await Api.createEquipment(payload);
      toast('Equipment added.');
    }
    editModal().hide();
    await loadEquipment();
  } catch (err) {
    toast(err.message, 'bad');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Save';
  }
});

async function removeEquipment(id, name) {
  if (!confirm(`Delete "${name}"? Its borrowing history goes too. This can't be undone.`)) return;
  try {
    await Api.deleteEquipment(id);
    toast('Equipment deleted.');
    await loadEquipment();
  } catch (err) {
    toast(err.message, 'bad');
  }
}

/* ---------- External API: QR label ---------- */

async function showQr(serial, name) {
  const body = document.getElementById('qrBody');
  body.innerHTML = 'Generating…';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('qrModal')).show();

  try {
    const src = await fetchQrCode(serial);
    body.innerHTML = `
      <img src="${src}" alt="QR code for ${esc(name)}">
      <div class="serial mt-2">${esc(serial)}</div>
      <div class="meta mt-1" style="font-size:.82rem;color:#5b6b83">Print and stick this on the case.</div>`;
  } catch (err) {
    body.innerHTML = `<div class="empty"><strong>Couldn't generate the label</strong>${esc(err.message)}</div>`;
  }
}
