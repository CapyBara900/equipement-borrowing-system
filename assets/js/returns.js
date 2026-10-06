/* Check equipment back in. Staff and admins only. */

(async function () {
  await requireSession(['admin', 'staff']);
  wireForm();
  await Promise.all([loadOutOnLoan(), loadHistory()]);
})();

async function loadOutOnLoan() {
  const host = document.getElementById('outOnLoan');
  try {
    const { data } = await Api.listRequests({ status: 'approved', limit: 50 });
    if (!data.length) {
      host.innerHTML = emptyState('Nothing is out right now', 'Approved loans show up here ready to check in.');
      return;
    }

    const today = new Date(); today.setHours(0, 0, 0, 0);
    host.innerHTML = data.map(r => {
      const overdue = new Date(r.expected_return_date) < today;
      return `
        <article class="item-row ${overdue ? 's-maintenance' : 's-borrowed'}">
          <div class="grow">
            <h3>${esc(r.equipment_name)}</h3>
            <div class="meta">
              <span class="refid">#${esc(r.request_id)}</span> · ${esc(r.user_name)}
              · due ${fmtDate(r.expected_return_date)}
              · quantity ${esc(r.requested_quantity)}
              ${overdue ? ' · <strong style="color:var(--fault)">overdue</strong>' : ''}
            </div>
          </div>
          <div class="actions">
            <button class="btn btn-sm btn-primary"
                    data-checkin="${esc(r.request_id)}"
                    data-name="${esc(r.equipment_name)}">Check in</button>
          </div>
        </article>`;
    }).join('');

    document.querySelectorAll('[data-checkin]').forEach(btn => {
      btn.addEventListener('click', () => openCheckIn(btn.dataset.checkin, btn.dataset.name));
    });
  } catch (err) {
    host.innerHTML = emptyState("Couldn't load current loans", err.message);
  }
}

async function loadHistory() {
  const body = document.getElementById('returnHistory');
  try {
    const { data } = await Api.listReturns();
    if (!data.length) {
      body.innerHTML = `<tr><td colspan="5">${emptyState('No returns yet', 'Checked-in items will be listed here.')}</td></tr>`;
      return;
    }
    body.innerHTML = data.map(r => `
      <tr>
        <td>${esc(r.equipment_name)} <span class="refid">#${esc(r.request_id)}</span></td>
        <td>${esc(r.user_name)}</td>
        <td>${fmtDate(r.actual_return_date)}</td>
        <td>${esc(r.processed_by_name || '—')}</td>
        <td>${esc(r.remarks || '—')}</td>
      </tr>`).join('');
  } catch (err) {
    body.innerHTML = `<tr><td colspan="5">${emptyState("Couldn't load history", err.message)}</td></tr>`;
  }
}

/* ---------- Check-in ---------- */

const returnModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('returnModal'));

const returnRules = {
  returnRemarks: [Rules.maxLength(500, 'Remarks')],
};

function openCheckIn(requestId, name) {
  document.getElementById('returnRequestId').value = requestId;
  document.getElementById('returnItemName').textContent = name;
  document.getElementById('conditionStatus').value = 'good';
  document.getElementById('returnRemarks').value = '';
  clearError(document.getElementById('returnRemarks'));
  updateConditionHint();
  returnModal().show();
}

function updateConditionHint() {
  const value = document.getElementById('conditionStatus').value;
  document.getElementById('conditionHint').textContent = value === 'good'
    ? 'The item goes straight back to available.'
    : 'The item will be held out of circulation until someone clears it.';
}

function wireForm() {
  liveValidate(returnRules);
  document.getElementById('conditionStatus').addEventListener('change', updateConditionHint);

  document.getElementById('returnForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!validate(returnRules)) return;

    const btn = document.getElementById('returnSubmit');
    if (!confirm('Check in this equipment with the selected condition?')) return;
    btn.disabled = true;
    btn.textContent = 'Checking in…';

    try {
      await Api.recordReturn({
        request_id: document.getElementById('returnRequestId').value,
        condition_status: document.getElementById('conditionStatus').value,
        remarks: document.getElementById('returnRemarks').value.trim(),
      });
      returnModal().hide();
      toast('Checked in. The borrower has been notified.');
      await Promise.all([loadOutOnLoan(), loadHistory()]);
    } catch (err) {
      toast(err.message, 'bad');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Check in';
    }
  });
}
