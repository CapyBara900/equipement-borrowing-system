/* Overview screen. Staff and admins get the desk-wide report; customers get
   their own borrowing status, since the reporting endpoint is staff-only. */

(async function () {
  const user = await requireSession();
  document.getElementById('greeting').textContent = `Hello, ${user.name.split(' ')[0]}`;

  if (user.role === 'customer') {
    await renderCustomerView();
  } else {
    await renderDeskView();
  }
})();

/* ---------- Staff / admin ---------- */

async function renderDeskView() {
  document.getElementById('subhead').textContent = 'Everything moving through the equipment desk.';
  const host = document.getElementById('dashContent');

  let data;
  try {
    ({ data } = await Api.dashboard());
  } catch (err) {
    host.innerHTML = emptyState("Couldn't load the overview", err.message);
    return;
  }

  const s = data.summary || {};
  const pending = Number(s.pending || 0);

  host.innerHTML = `
    <div class="row g-3 mb-4">
      <div class="col-6 col-lg-3">
        <div class="stat stat--blue">
          ${statIcon('clipboard')}
          <div class="stat-body"><div class="n">${Number(s.total_requests || 0)}</div><div class="k">Total requests</div></div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="stat stat--amber ${pending > 0 ? 'attention' : ''}">
          ${statIcon('clock')}
          <div class="stat-body">
            <div class="n">${pending}</div>
            <div class="k">${pending > 0 ? 'Waiting on you' : 'Pending'}</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="stat stat--green">
          ${statIcon('boxOut')}
          <div class="stat-body"><div class="n">${Number(s.approved || 0)}</div><div class="k">Out on loan</div></div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="stat stat--blue">
          ${statIcon('check')}
          <div class="stat-body"><div class="n">${Number(s.completed || 0)}</div><div class="k">Returned</div></div>
        </div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-12 col-xl-7">
        <div class="panel h-100">
          <div class="panel-head"><h2>Requests per month</h2></div>
          <div class="panel-body"><canvas id="monthlyChart" height="150"></canvas></div>
        </div>
      </div>
      <div class="col-12 col-xl-5">
        <div class="panel h-100">
          <div class="panel-head"><h2>Most requested</h2></div>
          <div class="panel-body" id="mostRequested"></div>
        </div>
      </div>
      <div class="col-12">
        <div class="panel">
          <div class="panel-head"><h2>Recent activity</h2></div>
          <div class="table-responsive">
            <table class="table">
              <thead><tr><th>What</th><th>Item</th><th>Person</th><th>Status</th><th>When</th></tr></thead>
              <tbody id="activityRows"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>`;

  drawMonthlyChart(data.monthly || []);
  renderMostRequested(data.most_requested || []);
  renderActivity(data.recent_activity || []);
}

/* Small inline icon set for the stat cards. Purely decorative — the
   number and label next to each icon always carry the actual meaning,
   so this is safe to hide from assistive tech. */
const STAT_ICONS = {
  clipboard: '<path d="M9 4h6a1 1 0 011 1v1H8V5a1 1 0 011-1z"/><rect x="5" y="5" width="14" height="16" rx="1.5"/><path d="M9 11h6M9 15h4"/>',
  clock: '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
  boxOut: '<path d="M4 8l8-4 8 4-8 4-8-4z"/><path d="M4 8v8l8 4 8-4V8"/><path d="M12 12v8"/>',
  check: '<circle cx="12" cy="12" r="8"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
  xCircle: '<circle cx="12" cy="12" r="8"/><path d="M9.5 9.5l5 5M14.5 9.5l-5 5"/>',
};

function statIcon(name) {
  const paths = STAT_ICONS[name] || '';
  return `<div class="stat-icon" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
         stroke-linecap="round" stroke-linejoin="round">${paths}</svg>
  </div>`;
}

function drawMonthlyChart(rows) {
  const canvas = document.getElementById('monthlyChart');
  if (!canvas) return;

  if (!rows.length) {
    canvas.parentElement.innerHTML = emptyState('No requests yet', 'The chart fills in once requests start coming through.');
    return;
  }

  new Chart(canvas, {
    type: 'bar',
    data: {
      labels: rows.map(r => {
        const [y, m] = r.month.split('-');
        return new Date(y, m - 1).toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
      }),
      datasets: [{
        label: 'Requests',
        data: rows.map(r => Number(r.total)),
        backgroundColor: '#26344a',
        borderRadius: 3,
        maxBarThickness: 46,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#eef1f6' } },
        x: { grid: { display: false } },
      },
    },
  });
}

function renderMostRequested(rows) {
  const host = document.getElementById('mostRequested');
  if (!rows.length) {
    host.innerHTML = emptyState('Nothing borrowed yet', 'Popular items will show up here.');
    return;
  }
  const top = Number(rows[0].request_count) || 1;
  host.innerHTML = rows.map(r => {
    const n = Number(r.request_count);
    const width = Math.round((n / top) * 100);
    return `
      <div class="mb-2">
        <div class="d-flex justify-content-between" style="font-size:.88rem">
          <span>${esc(r.equipment_name)}</span><span class="mono">${n}</span>
        </div>
        <div style="background:#eef1f6;border-radius:2px;height:6px">
          <div style="background:#5b6b83;height:6px;border-radius:2px;width:${width}%"></div>
        </div>
      </div>`;
  }).join('');
}

function renderActivity(rows) {
  const body = document.getElementById('activityRows');
  if (!rows.length) {
    body.innerHTML = `<tr><td colspan="5">${emptyState('No activity yet', 'Requests and returns will appear here.')}</td></tr>`;
    return;
  }
  body.innerHTML = rows.map(r => `
    <tr>
      <td>${r.type === 'return' ? 'Return' : 'Request'} <span class="refid">#${esc(r.ref_id)}</span></td>
      <td>${esc(r.equipment_name)}</td>
      <td>${esc(r.user_name)}</td>
      <td>${pill(r.status)}</td>
      <td>${fmtDate(r.event_date)}</td>
    </tr>`).join('');
}

/* ---------- Customer ---------- */

async function renderCustomerView() {
  document.getElementById('subhead').textContent = 'Your borrowing activity.';
  const host = document.getElementById('dashContent');

  let requests = [];
  try {
    ({ data: requests } = await Api.listRequests({ limit: 50 }));
  } catch (err) {
    host.innerHTML = emptyState("Couldn't load your borrowings", err.message);
    return;
  }

  const count = (status) => requests.filter(r => r.status === status).length;
  const openItems = requests.filter(r => r.status === 'approved');

  host.innerHTML = `
    <div class="row g-3 mb-4">
      <div class="col-6 col-lg-3">
        <div class="stat stat--amber ${count('pending') > 0 ? 'attention' : ''}">
          ${statIcon('clock')}
          <div class="stat-body"><div class="n">${count('pending')}</div><div class="k">Awaiting approval</div></div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="stat stat--green">
          ${statIcon('boxOut')}
          <div class="stat-body"><div class="n">${count('approved')}</div><div class="k">In your hands</div></div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="stat stat--blue">
          ${statIcon('check')}
          <div class="stat-body"><div class="n">${count('returned')}</div><div class="k">Returned</div></div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="stat stat--red">
          ${statIcon('xCircle')}
          <div class="stat-body"><div class="n">${count('rejected')}</div><div class="k">Declined</div></div>
        </div>
      </div>
    </div>

    <div class="panel mb-3">
      <div class="panel-head">
        <h2>Due back</h2>
        <a class="btn btn-sm btn-outline-secondary" href="equipment.html">Browse equipment</a>
      </div>
      <div class="panel-body" id="dueBack"></div>
    </div>`;

  const due = document.getElementById('dueBack');
  if (!openItems.length) {
    due.innerHTML = emptyState("You don't have anything out", 'Browse the catalog to request equipment.');
    return;
  }

  const today = new Date(); today.setHours(0, 0, 0, 0);
  due.innerHTML = openItems.map(r => {
    const dueDate = new Date(r.expected_return_date);
    const overdue = dueDate < today;
    return `
      <div class="item-row ${overdue ? 's-maintenance' : 's-borrowed'}">
        <div class="grow">
          <h3>${esc(r.equipment_name)}</h3>
          <div class="meta">
            Request <span class="refid">#${esc(r.request_id)}</span> ·
            due ${fmtDate(r.expected_return_date)}
            ${overdue ? '· <strong style="color:#b02a37">overdue</strong>' : ''}
          </div>
        </div>
      </div>`;
  }).join('');
}
