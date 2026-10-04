/* Notification list with mark-as-read. */

(async function () {
  const user = await requireSession();
  if (user.role === 'admin' || user.role === 'staff') {
    location.replace('dashboard.html');
    return;
  }
  document.getElementById('markAllBtn').addEventListener('click', markAll);
  await load();
})();

async function load() {
  const host = document.getElementById('noteList');
  try {
    const { data } = await Api.listNotifications();
    if (!data.length) {
      host.innerHTML = emptyState('No notifications', "You'll hear from us when a request changes status.");
      document.getElementById('markAllBtn').hidden = true;
      return;
    }

    const unread = data.filter(n => Number(n.is_read) === 0).length;
    document.getElementById('markAllBtn').hidden = unread === 0;

    host.innerHTML = data.map(n => {
      const isUnread = Number(n.is_read) === 0;
      return `
        <div class="note-row ${isUnread ? 'unread' : ''}">
          <div class="grow" style="flex:1 1 auto">
            <div>${esc(n.message)}${isUnread ? '<span class="unread-dot"></span>' : ''}</div>
            <div class="meta" style="font-size:.82rem;color:var(--slate)">${fmtDate(n.created_at)}</div>
          </div>
          ${isUnread
          ? `<button class="btn btn-sm btn-outline-secondary"
                       data-read="${esc(n.notification_id)}">Mark read</button>`
          : ''}
        </div>`;
    }).join('');

    document.querySelectorAll('[data-read]').forEach(btn => {
      btn.addEventListener('click', () => markOne(btn.dataset.read));
    });
  } catch (err) {
    host.innerHTML = emptyState("Couldn't load notifications", err.message);
  }
}

async function markOne(id) {
  try {
    await Api.markNotification({ notification_id: id });
    await load();
    await renderShell(CURRENT_USER);   // refresh the nav badge
  } catch (err) {
    toast(err.message, 'bad');
  }
}

async function markAll() {
  try {
    await Api.markNotification({ mark_all: true });
    toast('All caught up.');
    await load();
    await renderShell(CURRENT_USER);
  } catch (err) {
    toast(err.message, 'bad');
  }
}
