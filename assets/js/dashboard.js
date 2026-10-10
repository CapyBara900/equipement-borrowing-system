/* Role-adaptive overview. Saved request status and server calendar dates are authoritative. */
const dashboardState = {user: null, data: null, filter: 'active', page: 1, version: 0};
const dashboardLabels = {active: 'Due back & pickups', all: 'All borrowings', pending: 'Awaiting approval', approved: 'Awaiting pickup', borrowed: 'In your hands', returned: 'Returned', rejected: 'Declined', cancelled: 'Cancelled', due_today: 'Due today', overdue: 'Overdue'};
const dashboardIcons = {
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  box: '<path d="m3 7 9-4 9 4v10l-9 4-9-4V7Zm0 0 9 4 9-4M12 11v10M7 5l9 4"/>',
  check: '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
  decline: '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>',
  chart: '<path d="M4 3v17h17M8 16v-5m5 5V6m5 10v-8"/>',
  arrow: '<path d="M5 12h14m-5-5 5 5-5 5"/>'
};
function dashboardIcon(name) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + dashboardIcons[name] + '</svg>'; }
function dashboardCalendarDay(value) {
  const date = String(value || '').slice(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return null;
  const [year, month, day] = date.split('-').map(Number);
  const time = Date.UTC(year, month - 1, day);
  return new Date(time).toISOString().slice(0, 10) === date ? time : null;
}
function dashboardDate(value) {
  const time = dashboardCalendarDay(value);
  return time === null ? 'Not scheduled' : new Intl.DateTimeFormat('en', {month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC'}).format(new Date(time));
}
function dashboardDue(row, today) {
  if (row.status !== 'borrowed') return null;
  const due = dashboardCalendarDay(row.expected_return_date), now = dashboardCalendarDay(today);
  if (due === null || now === null) return {label: 'Return date unavailable', tone: 'neutral'};
  const days = Math.round((due - now) / 86400000);
  return days < 0 ? {label: 'Overdue by ' + -days + (-days === 1 ? ' day' : ' days'), tone: 'red'}
    : days === 0 ? {label: 'Due today', tone: 'amber'}
    : {label: 'Due in ' + days + (days === 1 ? ' day' : ' days'), tone: days <= 2 ? 'amber' : 'green'};
}
function dashboardStatus(status) {
  const tone = {pending: 'amber', approved: 'blue', borrowed: 'blue', returned: 'green', rejected: 'red', cancelled: 'neutral'}[status] || 'neutral';
  return '<span class="overview-badge overview-tone-' + tone + '">' + esc(status === 'borrowed' && dashboardState.user?.role !== 'customer' ? 'On loan' : dashboardLabels[status] || status || 'Status unavailable') + '</span>';
}
function dashboardMetrics(role, summary) {
  const n = key => Number(summary[key] || 0);
  return role === 'customer' ? [
    {label: 'Awaiting approval', value: n('pending'), filter: 'pending', tone: 'amber', icon: 'clock', note: 'Requests being reviewed'},
    {label: 'In your hands', value: n('borrowed'), filter: 'borrowed', tone: 'blue', icon: 'box', note: 'Equipment picked up'},
    {label: 'Returned', value: n('completed'), filter: 'returned', tone: 'green', icon: 'check', note: 'Completed borrowings'},
    {label: 'Declined', value: n('rejected'), filter: 'rejected', tone: 'red', icon: 'decline', note: 'Requests not approved'}
  ] : [
    {label: 'Pending approvals', value: n('pending'), filter: 'pending', tone: 'amber', icon: 'clock', note: 'Requests to review'},
    {label: 'Due today', value: n('due_today'), filter: 'due_today', tone: 'red', icon: 'box', note: 'Borrowings due for return'},
    {label: 'Equipment utilization', value: n('utilization') + '%', filter: 'borrowed', tone: 'blue', icon: 'chart', note: n('borrowed_units') + ' of ' + n('total_units') + ' units on loan'},
    {label: 'Returned', value: n('completed'), filter: 'returned', tone: 'green', icon: 'check', note: 'Completed borrowings'}
  ];
}
function dashboardEmpty(title, message, action = '') {
  return '<div class="overview-empty"><span class="overview-empty-art">' + dashboardIcon('box') + '</span><h3>' + esc(title) + '</h3><p>' + esc(message) + '</p>' + action + '</div>';
}
function dashboardEmptyList() {
  const customer = dashboardState.user.role === 'customer', filter = dashboardState.filter;
  const title = filter === 'active' ? 'Nothing due back right now' : filter === 'all' ? 'No borrowings yet' : 'No ' + dashboardLabels[filter].toLowerCase() + ' borrowings';
  const message = filter === 'active' ? customer ? 'Your equipment and pickup dates will appear here after a request is approved.' : 'Approved pickups and equipment on loan will appear here.'
    : filter === 'pending' ? 'There are no requests waiting for approval.' : 'Try another status or explore your borrowing history.';
  const action = customer && filter === 'active' ? '<a class="overview-button overview-button-primary" href="equipment.php">Browse equipment ' + dashboardIcon('arrow') + '</a>'
    : filter !== 'active' ? '<button class="overview-button" type="button" data-dashboard-filter="active">Show active borrowings</button>'
    : '<a class="overview-button" href="requests.html">Open borrowing management</a>';
  return dashboardEmpty(title, message, action);
}
function dashboardRow(row) {
  const desk = dashboardState.user.role !== 'customer', due = dashboardDue(row, dashboardState.data.today);
  const id = esc(String(row.request_id));
  return '<article class="overview-borrowing" aria-label="Request #' + id + '"><div class="overview-row-icon">' + dashboardIcon('box') + '</div><div class="overview-row-main">' +
    '<div class="overview-row-heading"><h3>' + esc(row.equipment_name) + '</h3>' + dashboardStatus(row.status) + '</div>' +
    '<p class="overview-meta">Request #' + id + ' · ' + esc(row.requested_quantity) + (Number(row.requested_quantity) === 1 ? ' unit' : ' units') + (desk ? ' · ' + esc(row.user_name) : '') + '</p>' +
    '<div class="overview-dates"><span>' + (row.status === 'approved' ? 'Pickup: ' + dashboardDate(row.borrow_date) : row.status === 'returned' ? 'Returned: ' + dashboardDate(row.actual_return_date) : 'Return by: ' + dashboardDate(row.expected_return_date)) + '</span>' +
    (due ? '<span class="overview-badge overview-tone-' + due.tone + '">' + esc(due.label) + '</span>' : '') + '</div></div>' +
    '<div class="overview-row-actions"><button type="button" class="overview-button overview-button-small" data-dashboard-details="' + id + '">View details</button>' +
    (desk && ['pending','approved','borrowed'].includes(row.status) ? '<a class="overview-link" href="requests.html?request=' + encodeURIComponent(row.request_id) + '">' + ({pending: 'Review request', approved: 'Manage pickup', borrowed: 'Record return'}[row.status]) + ' ' + dashboardIcon('arrow') + '</a>' : '') + '</div></article>';
}
function dashboardFeed(rows) {
  if (!rows.length) return '<p class="overview-feed-empty">No recent activity yet. New requests and recorded returns will appear here.</p>';
  return '<ol class="overview-feed">' + rows.map(row => '<li><span class="overview-feed-dot overview-tone-' + (row.type === 'return' ? 'green' : 'blue') + '">' + dashboardIcon(row.type === 'return' ? 'check' : 'clock') + '</span><div><p><strong>' + (row.type === 'return' ? 'Equipment returned' : 'New borrowing request') + '</strong></p><p>' + esc(row.equipment_name) + (dashboardState.user.role !== 'customer' ? ' · ' + esc(row.user_name) : '') + '</p><span class="overview-meta">' + dashboardDate(row.event_date) + ' · Request #' + esc(row.ref_id) + '</span><a class="overview-link overview-feed-link" href="requests.html?request=' + encodeURIComponent(row.ref_id) + '">View borrowing</a></div></li>').join('') + '</ol>';
}
function dashboardReports(data) {
  // CSS bars keep these reports available without an external chart dependency.
  const month = String(data.today || '').slice(0, 7), counts = new Map((data.monthly || []).map(row => [row.month, Number(row.total)]));
  const base = dashboardCalendarDay(month + '-01'), months = [];
  if (base !== null) for (let offset = 5; offset >= 0; offset--) {
    const date = new Date(base); date.setUTCMonth(date.getUTCMonth() - offset);
    const key = date.toISOString().slice(0,7);
    months.push({label: new Intl.DateTimeFormat('en', {month: 'short', timeZone: 'UTC'}).format(date), total: counts.get(key) || 0});
  }
  const max = Math.max(1, ...months.map(row => row.total));
  return '<section class="panel overview-reports"><header class="overview-panel-head"><div><h2>Requests per month</h2><p>Last six months · ' + Number(data.summary.total_requests || 0) + ' requests in total</p></div></header><div class="overview-chart" role="list" aria-label="Monthly request totals">' + months.map(row => '<div class="overview-chart-column" role="listitem" aria-label="' + row.label + ': ' + row.total + ' requests"><span>' + row.total + '</span><div class="overview-chart-track"><div style="height:' + Math.round(100 * row.total/max) + '%"></div></div><span>' + row.label + '</span></div>').join('') + '</div></section>';
}
function renderDashboard() {
  const data = dashboardState.data, role = dashboardState.user.role, summary = data.summary, pagination = data.pagination || {total: 0, page: 1, pages: 1};
  const customer = role === 'customer';
  const cards = dashboardMetrics(role, summary).map(card => '<button type="button" class="overview-kpi overview-kpi-' + card.tone + '" data-dashboard-filter="' + card.filter + '" aria-pressed="' + (dashboardState.filter === card.filter) + '" aria-controls="overviewList"><span class="overview-kpi-top"><span class="overview-kpi-label">' + card.label + '</span><span class="overview-kpi-icon">' + dashboardIcon(card.icon) + '</span></span><strong class="overview-kpi-value">' + card.value + '</strong><span class="overview-kpi-note">' + card.note + '</span><span class="overview-kpi-bottom">View borrowings ' + dashboardIcon('arrow') + '</span></button>').join('');
  const filters = Object.entries(dashboardLabels).map(([key,label]) => '<option value="' + key + '"' + (key === dashboardState.filter ? ' selected' : '') + '>' + (key === 'borrowed' && !customer ? 'On loan' : label) + '</option>').join('');
  document.getElementById('dashContent').innerHTML = '<div class="overview-kpis" aria-label="Borrowing summary">' + cards + '</div>' +
    '<div class="overview-insights"><span>' + dashboardIcon('clock') + ' ' + (customer ? summary.next_due_date ? 'Next return: ' + dashboardDate(summary.next_due_date) : 'No upcoming return scheduled' : 'Today: ' + dashboardDate(data.today)) + '</span>' +
    '<button type="button" class="overview-insight" data-dashboard-filter="approved">' + Number(summary.approved || 0) + ' awaiting pickup</button><button type="button" class="overview-insight' + (Number(summary.overdue) ? ' overview-insight-alert' : '') + '" data-dashboard-filter="overdue">' + Number(summary.overdue || 0) + ' overdue</button></div>' +
    '<div class="overview-grid"><div class="overview-main-column"><section class="panel overview-list-panel" aria-labelledby="overviewListTitle"><header class="overview-panel-head"><div><h2 id="overviewListTitle">' + esc(dashboardState.filter === 'borrowed' && !customer ? 'On loan' : dashboardLabels[dashboardState.filter]) + '</h2><p>' + pagination.total + (pagination.total === 1 ? ' borrowing' : ' borrowings') + ' · ' + (customer ? 'Your requests' : 'All customers') + '</p></div><a class="overview-link" href="requests.html">' + (customer ? 'My borrowings' : 'Manage borrowings') + ' ' + dashboardIcon('arrow') + '</a></header>' +
    '<div class="overview-toolbar"><label for="overviewFilter">Show</label><select id="overviewFilter">' + filters + '</select><button class="overview-button overview-button-small" type="button" data-dashboard-filter="active">Reset</button></div>' +
    '<div id="overviewList" tabindex="-1">' + ((data.borrowings || []).length ? data.borrowings.map(dashboardRow).join('') : dashboardEmptyList()) + '</div>' +
    (pagination.pages > 1 ? '<nav class="overview-pagination" aria-label="Borrowings pagination"><button class="overview-button overview-button-small" data-dashboard-page="' + (pagination.page-1) + '"' + (pagination.page <= 1 ? ' disabled' : '') + '>Previous</button><span>Page ' + pagination.page + ' of ' + pagination.pages + '</span><button class="overview-button overview-button-small" data-dashboard-page="' + (pagination.page+1) + '"' + (pagination.page >= pagination.pages ? ' disabled' : '') + '>Next</button></nav>' : '') + '</section>' +
    (!customer ? dashboardReports(data) : '') + '</div><aside class="overview-side-column" aria-label="Activity and reminders"><section class="panel"><header class="overview-panel-head"><div><h2>Recent activity</h2><p>Latest requests and returns</p></div></header>' + dashboardFeed(data.recent_activity || []) + '</section>' +
    '<section class="overview-reminder"><span class="overview-reminder-icon">' + dashboardIcon(customer ? 'box' : 'check') + '</span><h2>' + (customer ? 'Plan your next project' : 'Keep the desk moving') + '</h2><p>' + (customer ? 'Browse available equipment and choose your pickup and return dates.' : 'Review pending requests, confirm pickups, and record equipment returns from Borrowing Management.') + '</p><a class="overview-button' + (customer ? ' overview-button-primary' : '') + '" href="' + (customer ? 'equipment.php' : 'requests.html') + '">' + (customer ? 'Browse equipment' : 'Open borrowing management') + ' ' + dashboardIcon('arrow') + '</a></section></aside></div>';
}
function dashboardDetails(id) {
  const row = (dashboardState.data.borrowings || []).find(item => String(item.request_id) === String(id));
  if (!row) return;
  document.getElementById('dashboardDetailsTitle').textContent = 'Request #' + row.request_id;
  const fields = [['Quantity', row.requested_quantity + ' units'], ['Pickup date', dashboardDate(row.borrow_date)], ['Return by', dashboardDate(row.expected_return_date)], ['Created', dashboardDate(row.request_date)]];
  if (dashboardState.user.role !== 'customer') fields.unshift(['Borrower', row.user_name]);
  if (row.status === 'returned') fields.push(['Returned on', dashboardDate(row.actual_return_date)]);
  document.getElementById('dashboardDetailsBody').innerHTML = '<h3>' + esc(row.equipment_name) + '</h3>' + dashboardStatus(row.status) + '<dl class="overview-detail-fields">' + fields.map(([key,value]) => '<div><dt>' + esc(key) + '</dt><dd>' + esc(value) + '</dd></div>').join('') + '</dl><a class="overview-button overview-button-primary" href="requests.html?request=' + encodeURIComponent(row.request_id) + '">' + (dashboardState.user.role === 'customer' ? 'Open borrowing' : 'Manage this borrowing') + ' ' + dashboardIcon('arrow') + '</a>';
  document.getElementById('dashboardDetails').showModal();
}
async function loadDashboard(focusSelector = '') {
  const version = ++dashboardState.version, host = document.getElementById('dashContent'), status = document.getElementById('dashboardStatus');
  host.setAttribute('aria-busy', 'true');
  status.textContent = 'Loading overview…'; status.className = 'overview-feedback';
  document.getElementById('refreshDashboard').disabled = true;
  host.querySelectorAll('[data-dashboard-details], [data-dashboard-page]').forEach(button => {button.disabled = true;});
  try {
    const response = await Api.dashboard({filter: dashboardState.filter, page: dashboardState.page, limit: 8});
    if (version !== dashboardState.version) return;
    const data = response.data;
    if (!data || !data.summary || !Array.isArray(data.borrowings)) throw new Error('The server returned an unreadable overview.');
    dashboardState.data = data;
    dashboardState.user.role = data.role || dashboardState.user.role;
    dashboardState.page = data.pagination.page;
    renderDashboard();
    document.getElementById('subhead').textContent = dashboardState.user.role === 'customer' ? 'Your equipment, upcoming returns, and recent requests at a glance.' : 'Monitor approvals, returns, and equipment in circulation.';
    status.textContent = 'Showing ' + data.borrowings.length + ' of ' + data.pagination.total + ' borrowings. Dates follow ' + data.timezone + '.';
    if (focusSelector) document.querySelector(focusSelector)?.focus({preventScroll: true});
  } catch (error) {
    if (version !== dashboardState.version) return;
    status.textContent = error.message || 'Could not load your overview.'; status.className = 'overview-feedback overview-feedback-error';
    const retry = dashboardEmpty('Overview unavailable', 'Try again to get the latest saved borrowing information.', '<button class="overview-button" type="button" data-dashboard-retry>Try again</button>');
    const list = document.getElementById('overviewList');
    if (dashboardState.data && list) list.innerHTML = retry; else host.innerHTML = retry;
  } finally {
    if (version === dashboardState.version) {host.setAttribute('aria-busy', 'false'); document.getElementById('refreshDashboard').disabled = false;}
  }
}
(async function () {
  dashboardState.user = await requireSession();
  document.getElementById('greeting').textContent = 'Hello, ' + dashboardState.user.name.trim().split(/\s+/)[0];
  const host = document.getElementById('dashContent');
  host.addEventListener('click', event => {
    const button = event.target.closest('[data-dashboard-filter], [data-dashboard-page], [data-dashboard-details], [data-dashboard-retry]');
    if (!button || button.disabled) return;
    if (button.dataset.dashboardDetails) dashboardDetails(button.dataset.dashboardDetails);
    else {
      if (button.dataset.dashboardFilter) {dashboardState.filter = button.dataset.dashboardFilter; dashboardState.page = 1;}
      else if (button.dataset.dashboardPage) dashboardState.page = Number(button.dataset.dashboardPage);
      loadDashboard(button.dataset.dashboardFilter ? '[data-dashboard-filter="' + button.dataset.dashboardFilter + '"]' : '#overviewList');
    }
  });
  host.addEventListener('change', event => {
    if (event.target.id !== 'overviewFilter') return;
    dashboardState.filter = event.target.value; dashboardState.page = 1; loadDashboard('#overviewFilter');
  });
  document.getElementById('refreshDashboard').addEventListener('click', () => loadDashboard('#refreshDashboard'));
  await loadDashboard();
})();
