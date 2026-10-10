/* Shared borrowing history: customers see their own transactions; desk roles manage all customers. */
const PAGE_SIZE = 10;
const HISTORY_BATCH_SIZE = 50;
const BORROWING_STATUSES = ['', 'pending', 'approved', 'borrowed', 'returned', 'rejected', 'cancelled'];
let page = 1, user = null, activeStatus = '', borrowingRows = null, transactions = [];
let loadVersion = 0, detailKey = null;
let pendingHistoryLoads = 0, customerRefreshTimer = null;
const cancelling = new Set();
const processing = new Map();
let deskCapabilities = null, pickupWindow = null, returnRecords = null, returnHistoryError = '', returnHistoryLoading = false;
let detailVersion = 0, returnRequestId = null;
const requestElement = id => document.getElementById(id);
const isDeskUser = () => user.role === 'admin' || user.role === 'staff';
const ownedRequest = row => String(row.user_id) === String(user.user_id);
const transactionReference = transaction => 'Request #' + transaction.items[0].request_id + (transaction.checkoutId != null ? ' (Checkout #' + transaction.checkoutId + ')' : '');

(async function initRequests() {
  user = await requireSession();
  const desk = isDeskUser();
  requestElement('pageTitle').textContent = desk ? 'Borrowing Management' : 'My Borrowings';
  document.title = (desk ? 'Borrowing Management' : 'My Borrowings') + ' — Equipment Desk';
  requestElement('pageSub').textContent = desk ? 'Manage customer requests, equipment pickups, and returns.' : 'Track your equipment, pickup dates, and borrowing history.';
  requestElement('borrowingTabs').hidden = false;
  requestElement('borrowingToolbar').hidden = false;
  requestElement('tab-all').textContent = desk ? 'All Requests' : 'All';
  requestElement('requestList').setAttribute('role', 'tabpanel');
  requestElement('requestList').setAttribute('aria-labelledby', 'tab-all');
  requestElement('requestList').tabIndex = 0;
  wireFilters();
  if (desk) wireReturnForm();
  await load();
  openDashboardBorrowing();
  if (!desk) wireCustomerRefresh();
})();

// Dashboard links select only a transaction already returned by the authorized history API.
function openDashboardBorrowing() {
  if (!window.location?.search) return;
  const id = new URLSearchParams(window.location.search).get('request');
  if (!id || !/^\d+$/.test(id) || borrowingRows === null) return;
  const index = transactions.findIndex(transaction => String(transaction.items[0].request_id) === id);
  if (index < 0) return;
  page = Math.floor(index / PAGE_SIZE) + 1;
  renderBorrowings();
  showBorrowingDetails(transactions[index].key);
}

function wireCustomerRefresh() {
  const refresh = () => load(true, true);
  const start = () => { if (customerRefreshTimer === null) customerRefreshTimer = setInterval(refresh, 15000); };
  window.addEventListener('focus', refresh);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  window.addEventListener('pagehide', () => { clearInterval(customerRefreshTimer); customerRefreshTimer = null; });
  window.addEventListener('pageshow', () => { start(); refresh(); });
  start();
}

function setBorrowingStatus(status, focus = false) {
  if (!BORROWING_STATUSES.includes(status)) return;
  activeStatus = status;
  document.querySelectorAll('[data-status]').forEach(button => {
    const active = button.dataset.status === status;
    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', String(active));
    button.tabIndex = active ? 0 : -1;
    if (active) {
      requestElement('requestList').setAttribute('aria-labelledby', button.id);
      if (focus) { button.focus(); button.scrollIntoView({block: 'nearest', inline: 'nearest'}); }
    }
  });
  page = 1;
  load();
}

function wireFilters() {
  const reload = () => { page = 1; load(); };
  ['dateFrom', 'dateTo'].forEach(id => requestElement(id).addEventListener('change', reload));
  requestElement('clearFilters').addEventListener('click', () => {
    ['dateFrom', 'dateTo'].forEach(id => { requestElement(id).value = ''; });
    setBorrowingStatus('');
  });
  requestElement('prevPage').addEventListener('click', () => { if (page > 1) { page--; load(); } });
  requestElement('nextPage').addEventListener('click', () => { page++; load(); });
  requestElement('refreshBorrowings').addEventListener('click', () => load(true));
  requestElement('borrowingTabs').addEventListener('click', event => {
    const button = event.target.closest('[data-status]');
    if (button) setBorrowingStatus(button.dataset.status);
  });
  requestElement('borrowingTabs').addEventListener('keydown', event => {
    const button = event.target.closest('[data-status]');
    if (!button || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const index = BORROWING_STATUSES.indexOf(button.dataset.status);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? BORROWING_STATUSES.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + BORROWING_STATUSES.length) % BORROWING_STATUSES.length;
    setBorrowingStatus(BORROWING_STATUSES[next], true);
  });
  const actions = event => {
    const button = event.target.closest('button');
    if (!button || button.disabled) return;
    if (button.dataset.details) showBorrowingDetails(button.dataset.details);
    else if (button.dataset.cancel) cancel(button.dataset.cancel, button);
    else if (button.dataset.approve) decide(button.dataset.approve, 'approved');
    else if (button.dataset.reject) decide(button.dataset.reject, 'rejected');
    else if (button.dataset.pickup) decide(button.dataset.pickup, 'borrowed');
    else if (button.dataset.return) openBorrowingReturn(button.dataset.return);
    else if (button.dataset.historyRetry !== undefined) loadReturnDetails();
    else if (button.dataset.retry !== undefined) load(true);
  };
  requestElement('requestList').addEventListener('click', actions);
  requestElement('borrowingDetailsBody').addEventListener('click', actions);
  requestElement('borrowingDetailsModal').addEventListener('hidden.bs.modal', () => { detailKey = null; detailVersion++; returnHistoryLoading = false; });
}

async function load(refresh = false, background = false) {
  if (background && (document.hidden || pendingHistoryLoads || cancelling.size || processing.size)) return false;
  background = background && borrowingRows !== null;
  const version = ++loadVersion;
  const host = requestElement('requestList');
  if (refresh || borrowingRows === null) {
    pendingHistoryLoads++;
    if (!background) {
      borrowingRows = null;
      transactions = [];
      returnRecords = null;
      detailVersion++;
      returnHistoryLoading = false;
      if (detailKey) requestElement('borrowingDetailsBody').innerHTML = emptyState('Refreshing details…', 'Getting the latest saved transaction.');
      host.setAttribute('aria-busy', 'true');
      host.innerHTML = emptyState(isDeskUser() ? 'Loading borrowing transactions…' : 'Loading your borrowings…', 'Getting saved transactions.');
      requestElement('pager').hidden = true;
      requestElement('borrowingCount').textContent = 'Loading borrowing history…';
      requestElement('refreshBorrowings').disabled = true;
    }
    try {
      if (isDeskUser()) {
        deskCapabilities = null;
        pickupWindow = null;
        const results = await Promise.allSettled([Api.borrowingCapabilities(), Api.getPickupWindow()]);
        if (version !== loadVersion) return;
        if (results[0].status === 'fulfilled') deskCapabilities = results[0].value.data;
        if (results[1].status === 'fulfilled') pickupWindow = results[1].value.data;
      }
      // Every role uses the backend's request-level identity and saved current status.
      const rows = [];
      for (let apiPage = 1; ; apiPage++) {
        const response = await Api.listRequests({page: apiPage, limit: HISTORY_BATCH_SIZE, view: 'transactions'});
        if (version !== loadVersion) return;
        if (!Array.isArray(response.data)) throw new Error('The server returned an unreadable borrowing history.');
        for (const transaction of response.data) {
          if (transaction.items?.length !== 1 || transaction.transaction_id !== 'request:' + transaction.items[0].request_id || transaction.status !== transaction.items[0].status) {
            throw new Error('The server returned inconsistent borrowing statuses. Please refresh to try again.');
          }
          if (isDeskUser() || ownedRequest(transaction.items[0])) rows.push(transaction.items[0]);
        }
        if (response.has_more === false || (response.has_more !== true && response.data.length < HISTORY_BATCH_SIZE)) break;
        if (!response.data.length) throw new Error('The server returned incomplete borrowing history. Please try again.');
      }
      if (background && JSON.stringify(rows) === JSON.stringify(borrowingRows)) return true;
      if (background) { returnRecords = null; detailVersion++; returnHistoryLoading = false; }
      borrowingRows = rows;
      transactions = groupBorrowings(rows);
    } catch (error) {
      if (version !== loadVersion) return;
      if (background) return false;
      borrowingRows = null;
      transactions = [];
      host.innerHTML = emptyState(isDeskUser() ? "Couldn't load borrowing transactions" : "Couldn't load your borrowings", error.message) + '<div class="text-center mt-3"><button class="btn btn-outline-secondary" type="button" data-retry>Try again</button></div>';
      requestElement('borrowingCount').textContent = 'Borrowing history is unavailable.';
      if (detailKey) requestElement('borrowingDetailsBody').innerHTML = emptyState('Details are unavailable', 'Close this window and refresh your borrowing history.');
      return false;
    } finally {
      pendingHistoryLoads--;
      if (!background && version === loadVersion) { host.setAttribute('aria-busy', 'false'); requestElement('refreshBorrowings').disabled = false; }
    }
  }
  if (version !== loadVersion) return;
  renderBorrowings();
  if (detailKey) renderBorrowingDetails();
  if (detailKey && isDeskUser()) loadReturnDetails();
  return true;
}

function groupBorrowings(rows) {
  const groups = new Map(), seen = new Set();
  for (const row of rows) {
    if ((!isDeskUser() && !ownedRequest(row)) || seen.has(String(row.request_id))) continue;
    seen.add(String(row.request_id));
    const key = 'request:' + row.request_id;
    if (!groups.has(key)) groups.set(key, {key, checkoutId: row.checkout_id ?? null, status: row.status, items: []});
    groups.get(key).items.push(row);
  }
  return [...groups.values()];
}

function filteredBorrowings() {
  const from = requestElement('dateFrom').value, to = requestElement('dateTo').value;
  return transactions.filter(transaction => {
    if (activeStatus && transaction.status !== activeStatus) return false;
    return transaction.items.some(row => {
      const created = String(row.request_date || '').slice(0, 10);
      return (!from || created >= from) && (!to || (created && created <= to));
    });
  });
}

function updatePager(hasNext, totalPages = null) {
  requestElement('pager').hidden = totalPages !== null ? totalPages <= 1 : page === 1 && !hasNext;
  requestElement('pageLabel').textContent = 'Page ' + page + (totalPages !== null ? ' of ' + totalPages : '');
  requestElement('prevPage').disabled = page === 1;
  requestElement('nextPage').disabled = !hasNext;
}

function renderBorrowings() {
  const from = requestElement('dateFrom').value, to = requestElement('dateTo').value;
  if (from && to && from > to) {
    requestElement('requestList').innerHTML = emptyState('Check your date range', 'Created from must be on or before Created to.');
    requestElement('borrowingCount').textContent = 'Choose a valid date range.';
    requestElement('pager').hidden = true;
    return;
  }
  const filtered = filteredBorrowings();
  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  page = Math.min(page, totalPages);
  const visible = filtered.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);
  const label = activeStatus ? activeStatus.charAt(0).toUpperCase() + activeStatus.slice(1) : '';
  requestElement('borrowingCount').textContent = filtered.length + (label ? ' ' + label.toLowerCase() : '') + (filtered.length === 1 ? ' transaction' : ' transactions');
  requestElement('requestList').innerHTML = visible.length ? visible.map(transactionMarkup).join('') : emptyState(
    label ? 'No ' + label.toLowerCase() + ' transactions' : 'No borrowings found',
    from || to ? 'Try a different date range or clear your filters.' : activeStatus ? 'Transactions will appear here when equipment has this status.' : isDeskUser() ? 'Customer borrowing transactions will appear here once requests are submitted.' : 'Your borrowing history appears here after you request equipment from the Equipment page.');
  updatePager(page < totalPages, totalPages);
}

function borrowingStatusMarkup(status) {
  const label = status ? String(status).replace(/_/g, ' ') : 'Status unavailable';
  return '<span class="pill p-' + esc(status || 'unknown') + '">' + esc(label) + '</span>';
}

function transactionStatuses(transaction) {
  return borrowingStatusMarkup(transaction.status);
}

function borrowingItemMarkup(row, details = false, showStatus = true) {
  const canCancel = !isDeskUser() && row.status === 'pending' && ownedRequest(row);
  return '<section class="borrowing-item" aria-label="' + esc(row.equipment_name || 'Equipment #' + row.equipment_id) + '">' +
    '<div class="borrowing-item-head"><h3>' + esc(row.equipment_name || 'Equipment #' + row.equipment_id) + '</h3>' + (showStatus ? borrowingStatusMarkup(row.status) : '') + '</div>' +
    '<dl class="borrowing-fields"><div><dt>Quantity requested</dt><dd>' + esc(row.requested_quantity ?? '—') + '</dd></div><div><dt>Pick Up On</dt><dd>' + fmtDate(row.borrow_date) + '</dd></div><div><dt>Return By</dt><dd>' + fmtDate(row.expected_return_date) + '</dd></div>' +
    (details ? '<div><dt>Request reference</dt><dd class="refid">#' + esc(row.request_id) + '</dd></div><div><dt>Equipment ID</dt><dd class="refid">#' + esc(row.equipment_id) + '</dd></div><div><dt>Created on</dt><dd>' + fmtDate(row.request_date) + '</dd></div>' : '') + '</dl>' +
    (details ? borrowingHistoryMarkup(row) : '') +
    (isDeskUser() ? deskActionsMarkup(row) : canCancel ? '<div class="borrowing-item-actions"><button class="btn btn-sm btn-outline-danger" type="button" data-cancel="' + esc(row.request_id) + '" ' + (cancelling.has(String(row.request_id)) ? 'disabled' : '') + ' aria-label="Cancel request #' + esc(row.request_id) + ' for ' + esc(row.equipment_name) + '">Cancel request</button></div>' : '') + '</section>';
}

function transactionMarkup(transaction) {
  return '<article class="panel borrowing-card"><header class="borrowing-card-head"><div><h2 class="refid">' + esc(transactionReference(transaction)) + '</h2>' + (isDeskUser() ? '<p class="borrowing-customer">' + esc(transaction.items[0].user_name || 'Customer #' + transaction.items[0].user_id) + '</p>' : '') + '<p class="borrowing-meta">Created ' + fmtDate(transaction.items[0].request_date) + '</p></div><div class="borrowing-statuses" aria-label="Current borrowing statuses">' + transactionStatuses(transaction) + '</div></header>' +
    transaction.items.map(row => borrowingItemMarkup(row, false, false)).join('') +
    '<footer class="borrowing-card-foot"><span class="borrowing-meta">' + transaction.items.length + (transaction.items.length === 1 ? ' entry' : ' entries') + '</span><button class="btn btn-sm btn-outline-primary" type="button" data-details="' + esc(transaction.key) + '" aria-label="View details for ' + esc(transactionReference(transaction)) + '">View Details</button></footer></article>';
}

function renderBorrowingDetails() {
  const transaction = transactions.find(entry => entry.key === detailKey);
  requestElement('borrowingDetailsTitle').textContent = transaction ? transactionReference(transaction) : 'Borrowing details';
  requestElement('borrowingDetailsBody').innerHTML = transaction ? '<dl class="borrowing-fields"><div><dt>Requested by</dt><dd>' + esc(transaction.items[0].user_name || (isDeskUser() ? 'Customer #' + transaction.items[0].user_id : user.name)) + '</dd></div><div><dt>Transaction reference</dt><dd class="refid">' + esc(transactionReference(transaction)) + '</dd></div><div><dt>Customer ID</dt><dd class="refid">#' + esc(transaction.items[0].user_id) + '</dd></div></dl>' + transaction.items.map(row => borrowingItemMarkup(row, true)).join('') : emptyState('Transaction is unavailable', 'Close this window and refresh your borrowing history.');
}

function showBorrowingDetails(key) {
  if (!transactions.some(transaction => transaction.key === key)) return;
  detailKey = key;
  renderBorrowingDetails();
  bootstrap.Modal.getOrCreateInstance(requestElement('borrowingDetailsModal')).show();
  if (isDeskUser()) loadReturnDetails();
}

function pickupUnavailableReason(row) {
  if (deskCapabilities?.pickup_available !== true) return 'Pickup tracking is unavailable. Refresh to check again.';
  if (row.picked_up_at) return 'A pickup is already recorded. Refresh this transaction.';
  if (!pickupWindow?.min_date) return 'Could not verify the pickup date. Refresh to try again.';
  if (!row.borrow_date || !row.expected_return_date) return 'Pickup and return dates are required.';
  if (pickupWindow.min_date < row.borrow_date) return 'Pickup is available from ' + fmtDate(row.borrow_date) + '.';
  if (pickupWindow.min_date > row.expected_return_date) return 'The return date has passed; pickup is unavailable.';
  return '';
}

function deskActionsMarkup(row) {
  const id = String(row.request_id), busy = processing.get(id);
  const button = (action, label, style = 'primary', unavailable = false) => '<button class="btn btn-sm btn-' + style + '" type="button" data-' + action + '="' + esc(id) + '" ' + (busy || unavailable ? 'disabled' : '') + ' aria-label="' + esc(label + ' for request #' + id + ', ' + row.equipment_name) + '">' + esc(busy?.action === action ? busy.label : label) + '</button>';
  let actions = '', hint = '';
  if (row.status === 'pending') {
    actions = button('approve', 'Approve') + button('reject', 'Reject', 'outline-danger');
  } else if (row.status === 'approved') {
    hint = pickupUnavailableReason(row);
    actions = button('pickup', 'Mark as Borrowed', 'primary', Boolean(hint));
    // DELETE currently permits pending requests only. Approved cancellation is unsupported.
  } else if (row.status === 'borrowed') {
    actions = button('return', 'Mark as Returned');
  }
  return actions ? '<div class="borrowing-item-actions">' + actions + '</div>' + (hint ? '<p class="borrowing-action-hint">' + esc(hint) + '</p>' : '') : '';
}

function historyTime(value) {
  // API timestamps use the application's local calendar; retain that recorded time.
  return value ? esc(String(value).replace('T', ' ')) : 'Time not recorded';
}

function borrowingHistoryMarkup(row) {
  const returned = returnRecords?.find(entry => String(entry.request_id) === String(row.request_id));
  let history = row.request_date ? '<li><strong>Requested</strong><span>' + historyTime(row.request_date) + '</span></li>' : '';
  if (row.picked_up_at) history += '<li><strong>Picked up / Borrowed</strong><span>' + historyTime(row.picked_up_at) + (row.picked_up_by_staff_id ? ' · Staff #' + esc(row.picked_up_by_staff_id) : '') + '</span></li>';
  if (returned) history += '<li><strong>Returned</strong><span>' + historyTime(returned.actual_return_date) + ' · ' + esc(returned.processed_by_name || 'Staff #' + returned.processed_by_staff_id) + '</span>' + (returned.remarks ? '<span>Remarks: ' + esc(returned.remarks) + '</span>' : '') + '</li>';
  history += '<li><strong>Current status</strong><span>' + borrowingStatusMarkup(row.status) + '</span></li>';
  const returnNotice = row.status === 'returned' && isDeskUser() && !returned
    ? '<p class="borrowing-meta">' + esc(returnHistoryLoading ? 'Loading return history…' : returnHistoryError || 'No return event is available for this item.') + '</p>' + (returnHistoryError ? '<button class="btn btn-sm btn-outline-secondary" type="button" data-history-retry>Retry return history</button>' : '') : '';
  return '<div class="borrowing-history"><h4>Status history</h4><ol>' + history + '</ol><p class="borrowing-meta">Only saved events are shown. Approval, rejection, and cancellation times are not supplied by the current API.</p>' + returnNotice + '</div>';
}

async function loadReturnDetails() {
  const transaction = transactions.find(entry => entry.key === detailKey);
  if (!transaction?.items.some(row => row.status === 'returned') || returnRecords !== null || returnHistoryLoading) return;
  const version = ++detailVersion;
  returnHistoryLoading = true;
  returnHistoryError = '';
  renderBorrowingDetails();
  try {
    const response = await Api.listReturns();
    if (version !== detailVersion) return;
    if (!Array.isArray(response.data)) throw new Error('The server returned unreadable return history.');
    returnRecords = response.data;
  } catch (error) {
    if (version !== detailVersion) return;
    returnHistoryError = 'Could not load return history. ' + error.message;
  } finally {
    if (version === detailVersion) {
      returnHistoryLoading = false;
      if (detailKey) renderBorrowingDetails();
    }
  }
}

function borrowingFeedback(message, kind = 'ok') {
  const feedback = requestElement('borrowingFeedback');
  feedback.hidden = false;
  feedback.textContent = message;
  feedback.classList.toggle('cart-error', kind === 'bad');
  feedback.setAttribute('role', kind === 'bad' ? 'alert' : 'status');
  toast(message, kind);
}

function savedRequest(id) {
  return (borrowingRows || []).find(row => String(row.request_id) === String(id));
}

function renderSavedBorrowings() {
  if (borrowingRows === null) return;
  renderBorrowings();
  if (detailKey) renderBorrowingDetails();
}

async function performDeskAction(requestId, action, label, operation, successMessage) {
  const id = String(requestId);
  if (!isDeskUser() || processing.has(id)) return false;
  processing.set(id, {action, label});
  renderSavedBorrowings();
  try {
    await operation();
    borrowingFeedback(successMessage);
    // Re-read authoritative records; never assign a new status or change stock locally.
    const refreshed = await load(true);
    if (refreshed === false) borrowingFeedback(successMessage + ' Could not refresh the list. Use Refresh to load the saved status.', 'bad');
    return true;
  } catch (error) {
    borrowingFeedback(error.message, 'bad');
    // A conflict or lost response can follow a committed operation. Read before another attempt.
    await load(true);
    return false;
  } finally {
    processing.delete(id);
    renderSavedBorrowings();
  }
}

async function decide(requestId, status) {
  const row = savedRequest(requestId);
  if (!isDeskUser() || !row || processing.has(String(requestId))) return;
  if (status === 'borrowed') {
    if (row.status !== 'approved' || pickupUnavailableReason(row)) return;
    if (!confirm('Confirm physical pickup of ' + row.requested_quantity + ' × ' + row.equipment_name + ' by ' + row.user_name + '?')) return;
    return performDeskAction(requestId, 'pickup', 'Recording pickup…', () => Api.pickupRequest(requestId), 'Pickup recorded. Equipment marked as Borrowed.');
  }
  if (row.status !== 'pending' || !['approved', 'rejected'].includes(status)) return;
  const approving = status === 'approved';
  if (!confirm((approving ? 'Approve' : 'Reject') + ' request #' + row.request_id + ' for ' + row.equipment_name + '?')) return;
  return performDeskAction(requestId, approving ? 'approve' : 'reject', approving ? 'Approving…' : 'Rejecting…', () => Api.decideRequest({request_id: requestId, status}), approving ? 'Request approved.' : 'Request rejected.');
}

async function cancel(requestId, button) {
  const id = String(requestId);
  if (cancelling.has(id)) return;
  if (isDeskUser() || !(borrowingRows || []).some(row => String(row.request_id) === id && ownedRequest(row) && row.status === 'pending')) return;
  if (!confirm('Cancel this request?')) return;
  cancelling.add(id);
  if (button) { button.disabled = true; button.textContent = 'Cancelling…'; }
  try {
    await Api.cancelRequest(requestId);
    toast('Request cancelled.');
    await load(true);
  } catch (error) {
    toast(error.message, 'bad');
  } finally {
    cancelling.delete(id);
    if (button) { button.disabled = false; button.textContent = isDeskUser() ? 'Cancel' : 'Cancel request'; }
    if (!isDeskUser() && borrowingRows !== null) { renderBorrowings(); if (detailKey) renderBorrowingDetails(); }
  }
}

function openBorrowingReturn(requestId) {
  const row = savedRequest(requestId);
  if (!isDeskUser() || !row || row.status !== 'borrowed' || processing.has(String(requestId))) return;
  const detailsModal = requestElement('borrowingDetailsModal');
  if (detailsModal.classList.contains('show')) {
    detailsModal.addEventListener('hidden.bs.modal', () => openBorrowingReturn(requestId), {once: true});
    bootstrap.Modal.getOrCreateInstance(detailsModal).hide();
    return;
  }
  returnRequestId = String(requestId);
  requestElement('returnItemName').textContent = row.equipment_name + ' · ' + row.requested_quantity + ' units · ' + row.user_name + ' · Request #' + row.request_id;
  requestElement('conditionStatus').value = 'good';
  requestElement('returnRemarks').value = '';
  requestElement('returnError').hidden = true;
  clearError(requestElement('returnRemarks'));
  updateBorrowingConditionHint();
  bootstrap.Modal.getOrCreateInstance(requestElement('borrowingReturnModal')).show();
}

function updateBorrowingConditionHint() {
  requestElement('conditionHint').textContent = requestElement('conditionStatus').value === 'good'
    ? 'The item goes straight back to available.' : 'The item will be held out of circulation until someone clears it.';
}

function wireReturnForm() {
  requestElement('conditionStatus').addEventListener('change', updateBorrowingConditionHint);
  requestElement('returnForm').addEventListener('submit', async event => {
    event.preventDefault();
    const id = returnRequestId, row = savedRequest(id);
    if (!isDeskUser() || processing.has(id)) return;
    if (!row || row.status !== 'borrowed') {
      requestElement('returnError').textContent = 'This transaction is no longer available to return. Close this window and refresh.';
      requestElement('returnError').hidden = false;
      return;
    }
    const rules = {returnRemarks: [Rules.maxLength(500, 'Remarks')]};
    if (!validate(rules)) return;
    const condition = requestElement('conditionStatus').value;
    if (!['good', 'damaged', 'missing', 'under_repair'].includes(condition)) return;
    if (!confirm('Mark this equipment as Returned with the selected condition?')) return;
    const remarks = requestElement('returnRemarks').value.trim();
    requestElement('returnError').hidden = true;
    requestElement('returnSubmit').disabled = true;
    requestElement('returnSubmit').textContent = 'Recording return…';
    requestElement('conditionStatus').disabled = true;
    requestElement('returnRemarks').disabled = true;
    requestElement('borrowingReturnModal').querySelectorAll('[data-bs-dismiss]').forEach(button => { button.disabled = true; });
    try {
      await performDeskAction(id, 'return', 'Recording return…', async () => {
        await Api.recordReturn({request_id: id, condition_status: condition, remarks});
        bootstrap.Modal.getOrCreateInstance(requestElement('borrowingReturnModal')).hide();
      }, 'Return recorded. Equipment marked as Returned.');
      if (!requestElement('borrowingReturnModal').classList.contains('show')) return;
      requestElement('returnError').textContent = requestElement('borrowingFeedback').textContent;
      requestElement('returnError').hidden = false;
    } finally {
      requestElement('returnSubmit').disabled = false;
      requestElement('returnSubmit').textContent = 'Mark as Returned';
      requestElement('conditionStatus').disabled = false;
      requestElement('returnRemarks').disabled = false;
      requestElement('borrowingReturnModal').querySelectorAll('[data-bs-dismiss]').forEach(button => { button.disabled = false; });
    }
  });
}
