/* Equipment catalog: saved stock, AJAX filters, and role-specific actions. */
const PAGE_SIZE = 12;
let state = {page: 1, sortDir: 'ASC', view: 'grid', loading: false, loadVersion: 0, total: 0, pages: 1, error: null};
let categories = [], equipmentReady = false, user = null, cartCatalog = [];
let quickViewVersion = 0, quickViewId = null, quickViewItem = null;
const catalogActionBusy = new Set();
const catalogIcons = {
  box: '<path d="m3 7 9-4 9 4v10l-9 4-9-4V7Zm0 0 9 4 9-4M12 11v10M7 5l9 4"/>',
  cart: '<path d="M3 3h2l3 12h11l2-8H6M9 21h.01M18 21h.01M11 10h6m-3-3v6"/>',
  eye: '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
  edit: '<path d="m15 4 5 5M4 20l5-1L21 7a2 2 0 0 0-5-5L4 14v6Z"/>',
  trash: '<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/>',
  qr: '<path d="M3 3h6v6H3zm12 0h6v6h-6zM3 15h6v6H3zM15 15h3v3h3v3h-6zm6-3h-3M12 3v9H3m9 3v6"/>'
};
function catalogIcon(name) {return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + catalogIcons[name] + '</svg>';}
function catalogStock(item) {
  const total = Math.max(0, Number(item.total_quantity) || 0), available = Math.max(0, Number(item.available_quantity) || 0);
  const low = available > 0 && total > 1 && available <= Math.max(1, Math.floor(total * .2));
  const reserved = available === 0 && user?.role === 'admin' && item.equipment_status === 'pending';
  return {total, available, percent: total ? Math.min(100, Math.round(100 * available / total)) : 0,
    tone: reserved || low ? 'amber' : available > 0 ? 'green' : 'red', label: reserved ? 'Reserved' : low ? 'Low stock' : available > 0 ? 'Available' : 'Unavailable'};
}
function catalogCanBorrow(item) {return user?.role === 'customer' && item.status === 'available' && Number(item.available_quantity) > 0;}
function catalogStockMarkup(item) {
  const stock = catalogStock(item);
  return '<div class="catalog-stock"><div class="catalog-stock-heading"><span class="catalog-badge catalog-tone-' + stock.tone + '">' + esc(stock.label) + '</span><span>' + stock.available + ' ready to borrow</span></div><div class="catalog-stock-track" role="progressbar" aria-label="Available stock" aria-valuemin="0" aria-valuemax="' + Math.max(1,stock.total) + '" aria-valuenow="' + Math.min(stock.total,stock.available) + '" aria-valuetext="' + stock.available + ' available out of ' + stock.total + ' total units"><span class="catalog-fill-' + stock.tone + '" style="width:' + stock.percent + '%"></span></div><span class="catalog-stock-caption">' + stock.total + (stock.total === 1 ? ' unit in inventory' : ' units in inventory') + '</span></div>';
}
function catalogCondition(item) {
  if (user?.role !== 'admin') return '';
  const status = item.equipment_status;
  if (!status || status === 'available') return '';
  return '<span class="catalog-badge catalog-tone-' + (status === 'maintenance' ? 'red' : 'amber') + '">' + esc({maintenance:'Maintenance',pending:'Pending reservation',borrowed:'On loan'}[status] || status) + '</span>';
}
function catalogActions(item) {
  const id = esc(String(item.equipment_id)), name = esc(item.equipment_name);
  if (user.role === 'customer') {
    const disabled = catalogCanBorrow(item) ? '' : ' disabled';
    return '<div class="catalog-customer-actions"><button type="button" class="catalog-button catalog-button-primary" data-cart-add="' + id + '"' + disabled + '>' + catalogIcon('cart') + 'Add to Cart</button><button type="button" class="catalog-button" data-borrow="' + id + '"' + disabled + '>Quick Request</button></div>';
  }
  if (user.role !== 'admin') return '';
  return '<div class="catalog-admin-actions" role="group" aria-label="Actions for ' + name + '"><button type="button" class="catalog-icon-button" data-qr="' + esc(item.serial_number || '') + '" data-name="' + name + '" title="' + (item.serial_number ? 'Generate QR label' : 'Assign a serial number to create a label') + '" aria-label="Generate label for ' + name + '"' + (!item.serial_number ? ' disabled' : '') + '>' + catalogIcon('qr') + '</button><button type="button" class="catalog-icon-button" data-edit="' + id + '" title="Edit equipment" aria-label="Edit ' + name + '">' + catalogIcon('edit') + '</button><button type="button" class="catalog-icon-button catalog-icon-danger" data-delete="' + id + '" data-name="' + name + '" title="Delete equipment" aria-label="Delete ' + name + '">' + catalogIcon('trash') + '</button></div>';
}
function rowMarkup(item) {
  const id = esc(String(item.equipment_id));
  return '<article class="catalog-card" data-catalog-item="' + id + '"><button class="catalog-thumbnail" type="button" data-quick-view="' + id + '" tabindex="-1" aria-label="Quick view ' + esc(item.equipment_name) + '"><span>' + catalogIcon('box') + '</span><span class="catalog-thumbnail-category">' + esc(item.category_name || 'Equipment') + '</span><span class="catalog-preview-hint">' + catalogIcon('eye') + 'Quick view</span></button><div class="catalog-card-body"><div class="catalog-card-title"><button type="button" class="catalog-title-button" data-quick-view="' + id + '">' + esc(item.equipment_name) + '</button>' + catalogCondition(item) + '</div><p class="catalog-description">' + esc(item.description || 'View item details and borrowing terms.') + '</p>' + catalogStockMarkup(item) + '<p class="catalog-terms">Borrow for up to ' + esc(item.borrowing_time_limit_days) + ' days</p>' + catalogActions(item) + '</div></article>';
}
function catalogTableRow(item) {
  const id = esc(String(item.equipment_id));
  return '<tr data-catalog-item="' + id + '"><td><div class="catalog-table-item"><span class="catalog-table-icon">' + catalogIcon('box') + '</span><div><button class="catalog-title-button" type="button" data-quick-view="' + id + '">' + esc(item.equipment_name) + '</button><p class="catalog-table-serial">' + esc(item.serial_number || 'No serial assigned') + '</p>' + catalogCondition(item) + '</div></div></td><td>' + esc(item.category_name || 'Uncategorised') + '</td><td>' + catalogStockMarkup(item) + '</td><td>' + esc(item.borrowing_time_limit_days) + ' days</td><td>' + catalogActions(item) + '</td></tr>';
}
function renderCatalog() {
  const host = document.getElementById('equipmentList');
  if (!cartCatalog.length) {
    const filtered = document.getElementById('searchInput').value.trim() || document.getElementById('categoryFilter').value || document.getElementById('statusFilter').value;
    host.innerHTML = '<div class="catalog-empty"><span>' + catalogIcon('box') + '</span><h2>' + (filtered ? 'No equipment matches' : 'The catalog is waiting for its first item') + '</h2><p>' + (filtered ? 'Try another search or clear your filters to see more equipment.' : user.role === 'admin' ? 'Add equipment and organize it into categories to get started.' : 'New equipment will appear here when it is added to the catalog.') + '</p>' + (filtered ? '<button type="button" class="catalog-button" data-catalog-clear>Clear filters</button>' : user.role === 'admin' ? '<button type="button" class="catalog-button catalog-button-primary" data-catalog-create>Add equipment</button>' : '') + '</div>';
    return;
  }
  host.innerHTML = state.view === 'grid' ? '<div class="catalog-grid">' + cartCatalog.map(rowMarkup).join('') + '</div>'
    : '<div class="panel catalog-table-wrap" tabindex="0" role="region" aria-label="Equipment list; scroll horizontally for more columns"><table class="catalog-table"><caption class="catalog-sr-only">Equipment availability, borrowing terms, and actions</caption><thead><tr><th scope="col">Equipment</th><th scope="col">Category</th><th scope="col">Stock</th><th scope="col">Borrowing limit</th><th scope="col">Actions</th></tr></thead><tbody>' + cartCatalog.map(catalogTableRow).join('') + '</tbody></table></div>';
}
function syncCatalogView() {
  for (const [id,view] of [['gridViewBtn','grid'],['listViewBtn','list']]) document.getElementById(id).setAttribute('aria-pressed',String(state.view === view));
}
function setCatalogView(view) {
  if (!['grid','list'].includes(view)) return;
  state.view = view; syncCatalogView();
  try {localStorage.setItem('equipment-catalog-view:' + user.user_id + ':' + user.role, view);} catch (_) {}
  if (!state.loading && !state.error) renderCatalog();
}
function clearCatalogFilters() {
  ['searchInput','categoryFilter','statusFilter'].forEach(id=>{document.getElementById(id).value='';});
  state.page=1; loadEquipment();
}
function syncSortDirection() {
  const sort = document.getElementById('sortBy').value, ascending = state.sortDir === 'ASC';
  const button = document.getElementById('sortDirBtn');
  button.textContent = sort === 'equipment_name' ? ascending ? 'A–Z' : 'Z–A' : ascending ? '↑' : '↓';
  button.setAttribute('aria-label', 'Sort ' + (ascending ? 'ascending; switch to descending' : 'descending; switch to ascending'));
}
function initializeCatalogCategories() {
  const panel = document.getElementById('categoryManagement'), button = document.getElementById('manageCategoriesBtn');
  panel.hidden = true;
  if (user.role !== 'admin') return;
  const sync = () => {panel.hidden = !panel.open; button.setAttribute('aria-expanded', String(panel.open));};
  button.addEventListener('click', () => {sync(); if (panel.open) panel.scrollIntoView({block:'nearest'});});
  panel.addEventListener('toggle', sync);
}
(async function () {
  user = await requireSession(['admin','customer']);
  document.getElementById('catalogTitle').textContent = user.role === 'admin' ? 'Equipment management' : 'Equipment catalog';
  document.getElementById('catalogSubtitle').textContent = user.role === 'admin' ? 'Manage your inventory, availability, and borrowing terms.' : 'Browse stock, choose your dates, and request what you need.';
  state.view = user.role === 'admin' ? 'list' : 'grid';
  try {const saved=localStorage.getItem('equipment-catalog-view:' + user.user_id + ':' + user.role); if (['grid','list'].includes(saved)) state.view=saved;} catch (_) {}
  syncCatalogView();
  if (user.role === 'customer') for (const status of ['pending','borrowed','maintenance']) {
    const option=document.querySelector('#statusFilter option[value="' + status + '"]'); if (option) option.hidden=true;
  }
  if (user.role === 'admin') {
    const button=document.getElementById('addEquipmentBtn'); button.hidden=false; button.addEventListener('click',()=>openEditor(null));
  }
  CategoryManager.init(user); initializeCatalogCategories();
  await CategoryStore.start(updateCategoryOptions);
  wireControls(); await loadEquipment(); equipmentReady=true;
})();

async function updateCategoryOptions(data, previous) {
  categories = data;
  const options = data.map(category => '<option value="' + esc(category.category_id) + '">' + esc(category.category_name) + '</option>').join('');
  for (const [id,label] of [['categoryFilter','All categories'],['editCategory','Uncategorised']]) {
    const select=document.getElementById(id), selected=select.value;
    select.innerHTML='<option value="">' + label + '</option>' + options;
    select.value=data.some(category=>String(category.category_id)===selected) ? selected : '';
  }
  const names=rows=>JSON.stringify(rows.map(row=>[row.category_id,row.category_name]));
  if (equipmentReady && names(data)!==names(previous)) {state.page=1; await loadEquipment();}
}

function wireControls() {
  const reload=()=>{state.page=1;loadEquipment();};
  document.getElementById('searchInput').addEventListener('input',()=>{
    // Invalidate any older response immediately, including during the debounce window.
    state.loadVersion++; delayedCatalogSearch();
  });
  const delayedCatalogSearch=debounce(reload,250);
  ['categoryFilter','statusFilter'].forEach(id=>document.getElementById(id).addEventListener('change',reload));
  document.getElementById('sortBy').addEventListener('change',()=>{state.sortDir=document.getElementById('sortBy').value==='created_at'?'DESC':'ASC';syncSortDirection();reload();});
  document.getElementById('sortDirBtn').addEventListener('click',()=>{state.sortDir=state.sortDir==='ASC'?'DESC':'ASC';syncSortDirection();reload();});
  document.getElementById('clearCatalogFilters').addEventListener('click',clearCatalogFilters);
  for(const [id,view] of [['gridViewBtn','grid'],['listViewBtn','list']])document.getElementById(id).addEventListener('click',()=>setCatalogView(view));
  document.getElementById('prevPage').addEventListener('click',()=>{if(!state.loading && state.page>1){state.page--;loadEquipment();}});
  document.getElementById('nextPage').addEventListener('click',()=>{if(!state.loading && state.page<state.pages){state.page++;loadEquipment();}});
  document.getElementById('equipmentList').addEventListener('click',catalogClick);
  document.getElementById('quickViewBody').addEventListener('click',catalogClick);
  document.getElementById('equipmentQuickView').addEventListener('close',()=>{quickViewVersion++;quickViewId=null;quickViewItem=null;});
}

async function loadEquipment() {
  const host=document.getElementById('equipmentList'), version=++state.loadVersion;
  state.loading=true;state.error=null;host.setAttribute('aria-busy','true');host.innerHTML='<div class="catalog-loading" role="status">Loading equipment…</div>';
  document.getElementById('resultMeta').textContent='Updating equipment…';
  document.getElementById('prevPage').disabled=true;document.getElementById('nextPage').disabled=true;
  const query={search:document.getElementById('searchInput').value.trim(),category_id:document.getElementById('categoryFilter').value,status:document.getElementById('statusFilter').value,sort_by:document.getElementById('sortBy').value,sort_dir:state.sortDir,page:state.page,limit:PAGE_SIZE};
  try {
    const response=await Api.listEquipment(query);
    if(version!==state.loadVersion)return;
    if(!Array.isArray(response.data))throw new Error('The server returned an unreadable catalog.');
    cartCatalog=response.data;state.page=Number(response.page || state.page);state.total=Number(response.total ?? cartCatalog.length);state.pages=Number(response.pages || 1);
    renderCatalog();
    document.getElementById('resultMeta').textContent=state.total ? 'Showing ' + ((state.page-1)*PAGE_SIZE+1) + '–' + Math.min((state.page-1)*PAGE_SIZE+cartCatalog.length,state.total) + ' of ' + state.total + ' equipment items' : 'No equipment found';
    document.getElementById('pager').hidden=state.pages<=1;
    document.getElementById('pageLabel').textContent='Page ' + state.page + ' of ' + state.pages;
    document.getElementById('prevPage').disabled=state.page<=1;document.getElementById('nextPage').disabled=state.page>=state.pages;
  } catch(error) {
    if(version!==state.loadVersion)return;
    state.error=error.message;cartCatalog=[];document.getElementById('pager').hidden=true;
    host.innerHTML=emptyState('Could not load equipment',error.message)+'<div class="catalog-retry"><button class="catalog-button" type="button" data-catalog-retry>Try again</button></div>';
    document.getElementById('resultMeta').textContent='Equipment is currently unavailable. Please retry.';
  } finally {if(version===state.loadVersion){state.loading=false;host.setAttribute('aria-busy','false');}}
}

async function catalogClick(event) {
  const action=event.target.closest('button');
  if(action) {
    if(action.disabled)return;
    const data=action.dataset;
    if(data.quickView)await openEquipmentQuickView(data.quickView);
    else if(data.quickRetry)await openEquipmentQuickView(data.quickRetry);
    else if(data.historyRetry)await loadQuickViewHistory(data.historyRetry,quickViewVersion);
    else if(data.cartAdd || data.borrow)await catalogBorrowAction(data.cartAdd || data.borrow,data.cartAdd?'cart':'request',action);
    else if(data.edit){document.getElementById('equipmentQuickView').close();await openEditor(data.edit);}
    else if(data.delete){document.getElementById('equipmentQuickView').close();await removeEquipment(data.delete,data.name);}
    else if(data.qr){document.getElementById('equipmentQuickView').close();await showQr(data.qr,data.name);}
    else if(data.catalogClear!==undefined)clearCatalogFilters();
    else if(data.catalogCreate!==undefined)await openEditor(null);
    else if(data.catalogRetry!==undefined)await loadEquipment();
    return;
  }
  if(event.target.closest('a, input, select'))return;
  const item=event.target.closest('[data-catalog-item]');
  if(item)await openEquipmentQuickView(item.dataset.catalogItem);
}
async function catalogBorrowAction(id,mode,button) {
  if(user.role!=='customer' || catalogActionBusy.has(String(id)))return;
  catalogActionBusy.add(String(id));button.disabled=true;
  try {
    const {data:item}=await Api.getEquipment(id);
    if(!catalogCanBorrow(item)){document.getElementById('equipmentQuickView').close();await loadEquipment();throw new Error('This equipment is out of stock. Choose another item.');}
    document.getElementById('equipmentQuickView').close();
    if(mode==='cart')await BorrowingCart.initialize();
    await openBorrow(item.equipment_id,item.equipment_name,item.available_quantity,item.borrowing_time_limit_days,mode);
  } catch(error){toast(error.message,'bad');}
  finally {catalogActionBusy.delete(String(id));button.disabled=false;}
}
function catalogDate(value) {return value ? fmtDate(value) : 'Not recorded';}
async function openEquipmentQuickView(id) {
  const dialog=document.getElementById('equipmentQuickView'),body=document.getElementById('quickViewBody'),version=++quickViewVersion;
  quickViewId=String(id);quickViewItem=null;
  document.getElementById('quickViewTitle').textContent='Equipment details';body.setAttribute('aria-busy','true');
  body.innerHTML='<div class="catalog-loading" role="status">Loading specifications and stock…</div>';
  if(!dialog.open)dialog.showModal();
  try {
    const {data:item}=await Api.getEquipment(id);
    if(version!==quickViewVersion || !dialog.open)return;
    quickViewItem=item;document.getElementById('quickViewTitle').textContent=item.equipment_name;
    const fields=[['Serial number',item.serial_number || 'Not assigned'],['Category',item.category_name || 'Uncategorised'],['Equipment reference','#'+item.equipment_id],['Date added',catalogDate(item.created_at)]];
    body.innerHTML='<div class="catalog-detail-intro"><span class="catalog-detail-art">' + catalogIcon('box') + '</span><div><p>' + esc(item.description || 'No additional specifications have been provided.') + '</p>' + catalogCondition(item) + '</div></div>' + catalogStockMarkup(item) + '<dl class="catalog-detail-fields">' + fields.map(([label,value])=>'<div><dt>' + esc(label) + '</dt><dd>' + esc(value) + '</dd></div>').join('') + '</dl><section class="catalog-borrowing-terms"><h3>Borrowing terms</h3><p>Borrow for up to <strong>' + esc(item.borrowing_time_limit_days) + ' days</strong> from pickup. Choose a pickup date from today through the next 7 days. Requests require approval; adding to cart does not reserve stock.</p></section>' + catalogActions(item) + '<section class="catalog-history-section" aria-labelledby="quickHistoryTitle"><h3 id="quickHistoryTitle">' + (user.role==='admin'?'Item history':'Your borrowing history') + '</h3><div id="quickHistory" aria-live="polite">Loading history…</div></section>';
    await loadQuickViewHistory(id,version);
  } catch(error) {
    if(version!==quickViewVersion)return;
    body.innerHTML=emptyState('Details unavailable',error.message)+'<div class="catalog-retry"><button type="button" class="catalog-button" data-quick-retry="'+esc(id)+'">Try again</button></div>';
  } finally {if(version===quickViewVersion)body.setAttribute('aria-busy','false');}
}
async function loadQuickViewHistory(id,version) {
  const host=document.getElementById('quickHistory');
  host.textContent='Loading history…';host.setAttribute('aria-busy','true');
  try {
    const {data}=await Api.getEquipmentHistory(id);
    if(version!==quickViewVersion || String(id)!==quickViewId)return;
    const statusLabels={pending:'Awaiting approval',approved:'Awaiting pickup',borrowed:'Borrowed',returned:'Returned',rejected:'Declined',cancelled:'Cancelled'};
    host.innerHTML=(data.borrowings.length?'<p class="catalog-history-note">Latest ' + data.borrowings.length + (data.borrowings.length===1?' borrowing record':' borrowing records') + '</p><ol class="catalog-history">' + data.borrowings.map(row=>'<li><div><strong>Request #' + esc(row.request_id) + '</strong><span class="catalog-badge catalog-tone-' + ({pending:'amber',approved:'amber',borrowed:'amber',returned:'green',rejected:'red',cancelled:'neutral'}[row.status] || 'neutral') + '">' + esc(statusLabels[row.status] || row.status) + '</span></div><p>' + (user.role==='admin'?esc(row.user_name)+' · ':'') + esc(row.requested_quantity) + (Number(row.requested_quantity)===1?' unit':' units') + ' · Created ' + catalogDate(row.request_date) + '</p><p>Return by ' + catalogDate(row.expected_return_date) + (row.actual_return_date?' · Returned ' + catalogDate(row.actual_return_date):'') + '</p><a href="requests.html?request=' + encodeURIComponent(row.request_id) + '">View borrowing</a></li>').join('')+'</ol>':'<p class="catalog-history-note">' + (user.role==='admin'?'No borrowing history for this equipment yet.':'You have not requested this equipment yet.') + '</p>') +
      (user.role==='admin'?'<h4>Condition records</h4>'+(data.conditions.length?'<ol class="catalog-history">'+data.conditions.map(row=>'<li><strong>' + esc(String(row.condition_status).replace(/_/g,' ')) + '</strong><p>' + catalogDate(row.logged_at) + (row.reported_by_name?' · '+esc(row.reported_by_name):'') + '</p><p>'+esc(row.notes || 'No additional notes.')+'</p></li>').join('')+'</ol>':'<p class="catalog-history-note">No condition records have been logged.</p>'):'');
  } catch(error){if(version===quickViewVersion)host.innerHTML='<p class="catalog-history-error">'+esc(error.message)+'</p><button class="catalog-button" type="button" data-history-retry="'+esc(id)+'">Retry history</button>';}
  finally {if(version===quickViewVersion)host.setAttribute('aria-busy','false');}
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
  if (!confirm(`Delete "${name}"? Equipment with borrowing history cannot be deleted. This can't be undone.`)) return;
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
