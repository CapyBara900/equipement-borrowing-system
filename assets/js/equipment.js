/* Equipment catalog, borrowing, and admin equipment/category management.
   Everything refreshes through fetch — no page reloads. */

const PAGE_SIZE = 10;
let state = { page: 1, sortDir: 'ASC' };
let categories = [];
let equipmentReady = false;
let user = null;
let cartCatalog = [];

(async function () {
  user = await requireSession(['admin', 'customer']);

  if (user.role === 'customer') {
    ['pending', 'borrowed', 'maintenance'].forEach(status => {
      const option = document.querySelector(`#statusFilter option[value="${status}"]`);
      if (option) option.hidden = true;
    });
  }

  if (user.role === 'admin') {
    const addBtn = document.getElementById('addEquipmentBtn');
    addBtn.hidden = false;
    addBtn.addEventListener('click', () => openEditor(null));
  }

  CategoryManager.init(user);
  await CategoryStore.start(updateCategoryOptions);
  wireControls();
  await loadEquipment();
  equipmentReady = true;
})();

async function updateCategoryOptions(data, previous) {
  categories = data;
  const options = data.map(category =>
    `<option value="${esc(category.category_id)}">${esc(category.category_name)}</option>`).join('');
  for (const [id, label] of [['categoryFilter', 'All categories'], ['editCategory', 'Uncategorised']]) {
    const select = document.getElementById(id);
    const selected = select.value;
    select.innerHTML = `<option value="">${label}</option>` + options;
    select.value = data.some(category => String(category.category_id) === selected) ? selected : '';
  }
  const names = rows => JSON.stringify(rows.map(row => [row.category_id, row.category_name]));
  if (equipmentReady && names(data) !== names(previous)) await loadEquipment();
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

  cartCatalog = rows;
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
  // Catalog status is universal: it depends only on available quantity.
  const canBorrow = item.status === 'available' && isCustomer;
  const isAdmin = user.role === 'admin';
  const isDesk = user.role === 'admin';

  // Admins can still see the latest pending requester as a convenience.
  const pendingRequesterBadge = isDesk && item.equipment_status === 'pending' && item.pending_user_name
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
          ${isDesk && item.equipment_status && item.equipment_status !== item.status
            ? ' · condition: ' + esc(item.equipment_status) : ''}
        </div>
        <div class="meta mt-1">Available: ${esc(item.available_quantity)} of ${esc(item.total_quantity)} · Borrowing time limit: ${esc(item.borrowing_time_limit_days)} days</div>
        ${item.description ? `<div class="meta mt-1">${esc(item.description)}</div>` : ''}
      </div>
      <div class="actions">
        ${isCustomer
          ? (canBorrow
              ? `<button class="btn btn-sm btn-primary" data-cart-add="${esc(item.equipment_id)}">Add to Cart</button>
                 <button class="btn btn-sm btn-outline-secondary" data-borrow="${esc(item.equipment_id)}"
                             data-name="${esc(item.equipment_name)}" data-limit="${esc(item.borrowing_time_limit_days)}" data-available="${esc(item.available_quantity)}">Request</button>`
              : `<button class="btn btn-sm btn-outline-secondary" disabled>Unavailable</button>`)
          : ''
        }
        ${isDesk && item.serial_number
      ? `<button class="btn btn-sm btn-outline-secondary" data-qr="${esc(item.serial_number)}"
                     data-name="${esc(item.equipment_name)}">Label</button>` : ''}
        ${isDesk
      ? `<button class="btn btn-sm btn-outline-secondary" data-edit="${esc(item.equipment_id)}">Edit</button>
             ${isAdmin ? `<button class="btn btn-sm btn-outline-danger" data-delete="${esc(item.equipment_id)}"
                     data-name="${esc(item.equipment_name)}">Delete</button>` : ''}` : ''}
      </div>
    </article>`;
}

function bindRowActions() {
  document.querySelectorAll('[data-cart-add]').forEach(btn => {
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        const item=cartCatalog.find(item=>String(item.equipment_id)===btn.dataset.cartAdd);
        await BorrowingCart.initialize();
        if(item)await openBorrow(item.equipment_id,item.equipment_name,item.available_quantity,item.borrowing_time_limit_days,'cart');
      }
      catch (error) { toast(error.message, 'bad'); }
      finally { btn.disabled = false; }
    });
  });
  document.querySelectorAll('[data-borrow]').forEach(btn => {
    btn.addEventListener('click',     () => openBorrow(btn.dataset.borrow, btn.dataset.name, btn.dataset.available, btn.dataset.limit));
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

let borrowingLimitDays = 7;
let pickupWindow = null;
let borrowMode = 'request';
let borrowSubmitting = false;

const borrowRules = {
  borrowDate: [
    Rules.required('Pick up date'),
    { test: v => !!pickupWindow && BorrowingDetails.dateValid(v) && v >= pickupWindow.min_date && v <= pickupWindow.max_date,
      message: 'Pick up date must be between today and 7 calendar days in advance.' }
  ],
  expectedReturnDate: [
    Rules.required('Return date'),
    { test: v => BorrowingDetails.dateValid(v), message: 'Return By must be a valid calendar date.' },
    Rules.onOrAfterField('borrowDate', 'Return date'),
    { test: v => !!document.getElementById('expectedReturnDate').max && v <= document.getElementById('expectedReturnDate').max,
      message: 'Return date exceeds the borrowing time limit. Choose a date within the equipment\'s borrowing time limit.' }
  ],
  borrowQuantity: [{
    test: v => /^[1-9]\d*$/.test(v) && Number(v) <= Number(document.getElementById('borrowQuantity').max || 0),
    message: 'Enter a positive whole number that does not exceed the available quantity.',
  }],
};
const syncBorrowButton = liveValidate(borrowRules, 'borrowSubmit');

async function openBorrow(equipmentId, name, available, limitDays, mode = 'request') {
  if(borrowSubmitting)return;
  borrowMode=mode;
  document.getElementById('borrowModalTitle').textContent=mode==='cart'?'Add equipment to cart':'Request equipment';
  document.getElementById('borrowSubmit').textContent=mode==='cart'?'Add to Cart':'Send request';
  document.getElementById('borrowModeHint').textContent=mode==='cart'?'Choose quantity and borrowing dates. Adding to cart does not submit a request or reserve stock.':'';
  document.getElementById('borrowEquipmentId').value = equipmentId;
  document.getElementById('borrowItemName').textContent = name;
  const quantity = document.getElementById('borrowQuantity');
  quantity.value = '1';
  quantity.max = available;
  document.getElementById('borrowAvailability').textContent = `Available: ${available}`;

  borrowingLimitDays = Number(limitDays);
  let window;
  try {
    window = await refreshPickupBounds();
  } catch (err) {
    toast(err.message, 'bad');
    return;
  }
  const today = window.min_date;
  const borrowDate = document.getElementById('borrowDate');
  const returnDate = document.getElementById('expectedReturnDate');

  borrowDate.min = today;
  returnDate.min = today;
  borrowDate.value = today;
  returnDate.value = '';
  updateReturnBounds();
  returnDate.value = returnDate.max;
  returnDate.min = today;
  clearError(borrowDate);
  clearError(returnDate);
  clearError(quantity);
  syncBorrowButton();

  borrowModal().show();
}

async function refreshPickupBounds() {
  const { data } = await Api.getPickupWindow();
  pickupWindow = data;
  const pickup = document.getElementById('borrowDate');
  pickup.min = data.min_date;
  pickup.max = data.max_date;
  return data;
}

function calendarDateAfter(value, days) {
  const date = new Date(value + 'T00:00:00Z');
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

function updateReturnBounds() {
  const pickup = document.getElementById('borrowDate').value;
  const returnDate = document.getElementById('expectedReturnDate');
  returnDate.min = pickup || pickupWindow?.min_date || '';
  returnDate.max = '';
  if (BorrowingDetails.dateValid(pickup) && Number.isInteger(borrowingLimitDays) && borrowingLimitDays > 0) {
    returnDate.max = calendarDateAfter(pickup, borrowingLimitDays);
  }
}

document.getElementById('borrowDate').addEventListener('input', () => {
  updateReturnBounds();
  syncBorrowButton();
  if (document.getElementById('expectedReturnDate').value) validate(borrowRules);
});

document.getElementById('borrowForm').addEventListener('submit', async e => {
  e.preventDefault();if(borrowSubmitting)return;
  const btn=document.getElementById('borrowSubmit');const mode=borrowMode;
  if(!validate(borrowRules))return;
  borrowSubmitting=true;btn.disabled=true;btn.textContent=mode==='cart'?'Adding…':'Sending…';
  try {
    const id=document.getElementById('borrowEquipmentId').value;
    await refreshPickupBounds();
    // Cart additions check fresh stock; the direct API continues enforcing its own stock check.
    let item=null;
    if(mode==='cart') {
      const response=await Api.getEquipment(id);item=response.data;
      document.getElementById('borrowQuantity').max=item.available_quantity;
      document.getElementById('borrowAvailability').textContent='Available: '+item.available_quantity;
      borrowingLimitDays=Number(item.borrowing_time_limit_days);
      if(item.status!=='available')throw new Error('This equipment is currently unavailable.');
    }
    updateReturnBounds();if(!validate(borrowRules))return;
    const details={quantity:Number(document.getElementById('borrowQuantity').value),borrow_date:document.getElementById('borrowDate').value,expected_return_date:document.getElementById('expectedReturnDate').value};
    if(mode==='cart') {await BorrowingCart.add(item,details,pickupWindow);toast('Added to your borrowing cart.');}
    else {await Api.createRequest({equipment_id:id,requested_quantity:details.quantity,borrow_date:details.borrow_date,expected_return_date:details.expected_return_date});toast('Request sent. Staff will review it shortly.');await loadEquipment();}
    borrowModal().hide();
  } catch(error) {toast(error.message,'bad');}
  finally {borrowSubmitting=false;btn.textContent=mode==='cart'?'Add to Cart':'Send request';syncBorrowButton();}
});

/* ---------- Admin: add / edit / delete ---------- */

const editModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal'));

const editRules = {
  editReleaseQuantity: [{
    test: v => /^[0-9]+$/.test(v) && Number.isSafeInteger(Number(v)) && Number(v) <= Number(document.getElementById('editReleaseQuantity').max || 0),
    message: 'Enter a whole number from zero to the held quantity.',
  }],
  editBorrowingLimit: [{
    test: value => {
      const text = String(value ?? '').trim();
      const days = Number(text);
      return /^[0-9]+$/.test(text) && Number.isInteger(days) && days >= 1 && days <= 3650;
    },
    message: 'Borrowing time limit must be a whole number between 1 and 3650 days.',
  }],
  editTotalQuantity: [{
    test: v => /^[1-9]\d*$/.test(v),
    message: 'Total quantity must be a positive whole number.',
  }],
  editName: [Rules.required('Name'), Rules.maxLength(150, 'Name')],
  editSerial: [Rules.maxLength(100, 'Serial number')],
  editDescription: [Rules.maxLength(500, 'Description')],
};
const syncEditButton = liveValidate(editRules, 'editSubmit');

async function openEditor(equipmentId) {
  if (user?.role !== 'admin') return;
  try { await CategoryStore.refresh(); } catch (error) { toast(error.message, 'bad'); return; }
  document.getElementById('editTitle').textContent = equipmentId ? 'Edit equipment' : 'Add equipment';
  document.getElementById('editId').value = equipmentId || '';

  ['editName', 'editSerial', 'editDescription', 'editTotalQuantity', 'editBorrowingLimit'].forEach(id => {
    const el = document.getElementById(id);
    el.value = '';
    clearError(el);
  });
  document.getElementById('editCategory').value = '';
  document.getElementById('editStatus').value = 'available';
  document.getElementById('editReleaseQuantity').value = '0';
  document.getElementById('editReleaseQuantity').max = '0';
  document.getElementById('releaseStockControl').hidden = true;
  document.getElementById('editTotalQuantity').value = '1';
  document.getElementById('editBorrowingLimit').value = '7';

  if (equipmentId) {
    try {
      const { data } = await Api.getEquipment(equipmentId);
      document.getElementById('editName').value = data.equipment_name || '';
      document.getElementById('editSerial').value = data.serial_number || '';
      document.getElementById('editDescription').value = data.description || '';
      document.getElementById('editCategory').value = data.category_id || '';
      document.getElementById('editStatus').value = data.equipment_status || data.status || 'available';
      document.getElementById('editReleaseQuantity').max = String(data.held_quantity || 0);
      document.getElementById('releaseStockControl').hidden = !(Number(data.held_quantity) > 0);
      document.getElementById('releaseStockHint').textContent = (data.held_quantity || 0) + ' held units. Clear only repaired or recovered units and choose Available status.';
      document.getElementById('editTotalQuantity').value = data.total_quantity || 1;
      document.getElementById('editBorrowingLimit').value = data.borrowing_time_limit_days;
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
  if (user?.role !== 'admin') return;
  if (!validate(editRules)) return;

  const id = document.getElementById('editId').value;
  const payload = {
    equipment_name: document.getElementById('editName').value.trim(),
    serial_number: document.getElementById('editSerial').value.trim(),
    description: document.getElementById('editDescription').value.trim(),
    category_id: document.getElementById('editCategory').value || null,
    status: document.getElementById('editStatus').value,
    total_quantity: document.getElementById('editTotalQuantity').value,
    release_quantity: Number(document.getElementById('editReleaseQuantity').value),
    borrowing_time_limit_days: Number(document.getElementById('editBorrowingLimit').value),
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
  if (user?.role !== 'admin') return;
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
  if (user?.role !== 'admin') return;
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
