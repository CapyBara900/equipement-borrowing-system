/* Borrowing requests. Customers see their own; staff and admins see the whole
   queue and can approve or reject. The backend scopes this too — the UI just
   matches what the person is allowed to do. */

const PAGE_SIZE = 10;
let page = 1;
let user = null;

(async function () {
  user = await requireSession();

  const isDesk = user.role === 'admin' || user.role === 'staff';
  document.getElementById('pageTitle').textContent = isDesk ? 'Request queue' : 'My requests';
  document.getElementById('pageSub').textContent = isDesk
    ? 'Approve or decline what people have asked to borrow.'
    : 'Everything you have asked to borrow, and where it stands.';

  wireFilters();
  await load();
})();

function wireFilters() {
  const reload = () => { page = 1; load(); };
  document.getElementById('statusFilter').addEventListener('change', reload);
  document.getElementById('dateFrom').addEventListener('change', reload);
  document.getElementById('dateTo').addEventListener('change', reload);

  document.getElementById('clearFilters').addEventListener('click', () => {
    document.getElementById('statusFilter').value = '';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    reload();
  });

  document.getElementById('prevPage').addEventListener('click', () => {
    if (page > 1) { page--; load(); }
  });
  document.getElementById('nextPage').addEventListener('click', () => { page++; load(); });
}

async function load() {
  const host = document.getElementById('requestList');
  host.innerHTML = '<div class="empty">Loading requests…</div>';

  const dateTo = document.getElementById('dateTo').value;
  const query = {
    status: document.getElementById('statusFilter').value,
    date_from: document.getElementById('dateFrom').value,
    // request_date is a timestamp, so push "to" to the end of that day
    date_to: dateTo ? `${dateTo} 23:59:59` : '',
    page,
    limit: PAGE_SIZE,
  };

  let rows;
  try {
    ({ data: rows } = await Api.listRequests(query));
  } catch (err) {
    host.innerHTML = emptyState("Couldn't load requests", err.message);
    return;
  }

  if (!rows.length) {
    host.innerHTML = page > 1
      ? emptyState('No more requests', 'Go back to the previous page.')
      : emptyState('Nothing here yet',
        user.role === 'customer'
          ? 'Browse the equipment catalog to make your first request.'
          : 'No requests match these filters.');
  } else {
    host.innerHTML = rows.map(rowMarkup).join('');
    bindActions();
  }

  const pager = document.getElementById('pager');
  pager.hidden = (page === 1 && rows.length < PAGE_SIZE);
  document.getElementById('pageLabel').textContent = `Page ${page}`;
  document.getElementById('prevPage').disabled = page === 1;
  document.getElementById('nextPage').disabled = rows.length < PAGE_SIZE;
}

function rowMarkup(r) {
  const isDesk = user.role === 'admin' || user.role === 'staff';
  const isOwner = String(r.user_id) === String(user.user_id);

  const stripe = {
    pending: 's-borrowed',
    approved: 's-available',
    rejected: 's-maintenance',
    returned: '',
  }[r.status] || '';

  let actions = '';
  if (r.status === 'pending' && isDesk) {
    actions = `
      <button class="btn btn-sm btn-primary" data-approve="${esc(r.request_id)}">Approve</button>
      <button class="btn btn-sm btn-outline-danger" data-reject="${esc(r.request_id)}">Decline</button>`;
  } else if (r.status === 'pending' && isOwner) {
    actions = `<button class="btn btn-sm btn-outline-secondary" data-cancel="${esc(r.request_id)}">Cancel</button>`;
  }

  return `
    <article class="item-row ${stripe}">
      <div class="grow">
        <h3>${esc(r.equipment_name)}</h3>
        <div class="meta">
          ${pill(r.status)}
          · <span class="refid">#${esc(r.request_id)}</span>
          ${r.checkout_id ? ' · Cart request #' + esc(r.checkout_id) : ''}
          ${isDesk ? ' · ' + esc(r.user_name) : ''}
        </div>
        <div class="meta mt-1">Quantity: ${esc(r.requested_quantity)}</div>
        <div class="meta mt-1">
          Requested ${fmtDate(r.request_date)}
          · pick up ${fmtDate(r.borrow_date)}
          · due ${fmtDate(r.expected_return_date)}
        </div>
      </div>
      <div class="actions">${actions}</div>
    </article>`;
}

function bindActions() {
  document.querySelectorAll('[data-approve]').forEach(b =>
    b.addEventListener('click', () => decide(b.dataset.approve, 'approved', b)));
  document.querySelectorAll('[data-reject]').forEach(b =>
    b.addEventListener('click', () => decide(b.dataset.reject, 'rejected', b)));
  document.querySelectorAll('[data-cancel]').forEach(b =>
    b.addEventListener('click', () => cancel(b.dataset.cancel)));
}

async function decide(requestId, status, btn) {
  const action = status === 'approved' ? 'approve' : 'decline';
  if (!confirm(`Are you sure you want to ${action} this request?`)) return;
  btn.disabled = true;
  btn.textContent = status === 'approved' ? 'Approving…' : 'Declining…';
  try {
    await Api.decideRequest({ request_id: requestId, status });
    toast(status === 'approved'
      ? 'Approved. The item is now marked as borrowed.'
      : 'Declined. The requester has been notified.');
    await load();
  } catch (err) {
    toast(err.message, 'bad');
    await load();
  }
}

async function cancel(requestId) {
  if (!confirm('Cancel this request?')) return;
  try {
    await Api.cancelRequest(requestId);
    toast('Request cancelled.');
    await load();
  } catch (err) {
    toast(err.message, 'bad');
  }
}
