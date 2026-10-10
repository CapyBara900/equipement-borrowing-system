/* Admin account management. Tabs and toolbar bindings are embedded in admin.html. */
let me = null;
let users = [];
let usersLoaded = false;
let usersLoading = true;
let usersLoadError = null;
let usersCsrfToken = '';
let syncUserButton = null;
let syncResetButton = null;
let menuUserId = null;
let menuTrigger = null;
let roleUserId = null;
let resetUserId = null;
let confirmation = null;
const accountBusy = { user: false, role: false, reset: false, confirm: false };
const modalReturnFocus = new Map();
const roleNames = { admin: 'Administrator', staff: 'Staff', customer: 'Customer' };
const roleDescriptions = {
  admin: 'Administrators can manage equipment, accounts, categories, and borrowing.',
  staff: 'Staff can process borrowing requests, pickups, and returns.',
  customer: 'Customers can browse equipment and manage their own borrowing.',
};
const accountModal = id => bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
const findUser = id => users.find(user => String(user.user_id) === String(id));
const isOwnAccount = user => me && String(user.user_id) === String(me.user_id);
function isAccountLocked(user) {
  return Boolean(user.locked_until && new Date(user.locked_until.replace(' ', 'T')) > new Date());
}
function accountInitials(name) {
  const words = String(name || '').trim().split(/\s+/).filter(Boolean);
  return words.length ? (Array.from(words[0])[0] + (words.length > 1 ? Array.from(words[words.length - 1])[0] : '')).toUpperCase() : '?';
}
function accountIcon(name) {
  return '<svg class="icon" aria-hidden="true"><use href="#icon-' + name + '"/></svg>';
}
function decorateAdminShell() {
  const icons = { 'dashboard.html': 'grid', 'equipment.html': 'box', 'requests.html': 'clipboard', 'admin.html': 'users' };
  document.querySelectorAll('.rail a.nav-item, .rail-canvas a.nav-item').forEach(link => {
    const href = link.getAttribute('href');
    if (icons[href] && !link.querySelector('svg')) link.insertAdjacentHTML('afterbegin', accountIcon(icons[href]));
    if (href === 'admin.html') {
      link.setAttribute('aria-current', 'page');
      const label = link.querySelector('span');
      if (label) label.textContent = 'People & Categories';
    }
  });
}
async function initializeAdmin() {
  try {
    me = await requireSession(['admin'], { redirectOnError: false });
    decorateAdminShell();
    wireAccountForms();
    wireAccountActions();
    CategoryManager.init(me);
    document.getElementById('addUserBtn').disabled = false;
    document.getElementById('addCategoryBtn').disabled = false;
    CategoryStore.subscribe(rows => {
      document.getElementById('categoryCount').textContent = rows.length;
    });
    await Promise.all([loadUsers(), CategoryStore.start()]);
    const categories = document.getElementById('categoryList');
    if (categories.classList.contains('is-loading')) {
      categories.classList.remove('is-loading');
      categories.setAttribute('aria-busy', 'false');
      categories.innerHTML = emptyState('Could not load categories', 'Check your connection and try again.') + '<button class="btn btn-outline-secondary" type="button" id="retryCategories">Try again</button>';
      document.getElementById('retryCategories').addEventListener('click', async () => {
        try { await CategoryStore.refresh(); } catch (error) { toast(error.message, 'bad'); }
      });
    }
  } catch (error) {
    // The session helper renders role denial and redirects unauthenticated visitors.
    if (typeof CURRENT_USER !== 'undefined' && CURRENT_USER && CURRENT_USER.role !== 'admin') return;
    usersLoading = false;
    usersLoadError = error;
    renderUsers();
    const retry = document.getElementById('retryUsers');
    if (retry) retry.addEventListener('click', () => initializeAdmin(), { once: true });
    const categories = document.getElementById('categoryList');
    if (categories) {
      categories.setAttribute('aria-busy', 'false');
      categories.innerHTML = emptyState('Connection unavailable', 'Accounts and categories require an administrator session.');
    }
  }
}
async function loadUsers() {
  usersLoading = true;
  usersLoadError = null;
  renderUsers();
  try {
    const result = await Api.listUsers();
    users = result.data;
    usersCsrfToken = result.csrf_token || '';
    usersLoaded = true;
  } catch (error) {
    usersLoadError = error;
  } finally {
    usersLoading = false;
    renderUsers();
  }
}
function renderUsers() {
  closeUserMenu();
  const body = document.getElementById('userRows');
  if (!body) return;
  const resultLabel = document.getElementById('userResults');
  body.setAttribute('aria-busy', String(usersLoading));
  if (usersLoading) {
    body.innerHTML = '<tr><td colspan="5">' + emptyState('Loading accounts…', 'Fetching the latest account information.') + '</td></tr>';
    resultLabel.textContent = 'Loading accounts…';
    return;
  }
  if (usersLoadError) {
    body.innerHTML = '<tr><td colspan="5">' + emptyState('Could not load accounts', usersLoadError.message) + '<div class="text-center pb-3"><button class="btn btn-outline-secondary" type="button" id="retryUsers">Try again</button></div></td></tr>';
    resultLabel.textContent = 'Accounts unavailable. Try again.';
    if (me) document.getElementById('retryUsers').addEventListener('click', loadUsers);
    return;
  }
  if (!usersLoaded) return;
  const query = document.getElementById('userSearch').value.trim().toLocaleLowerCase();
  const role = document.getElementById('roleFilter').value;
  const filtered = users.filter(user => (!role || user.role === role) && (!query || String(user.name).toLocaleLowerCase().includes(query) || String(user.email).toLocaleLowerCase().includes(query)));
  document.getElementById('peopleCount').textContent = users.length;
  resultLabel.textContent = 'Showing ' + filtered.length + ' of ' + users.length + ' account' + (users.length === 1 ? '' : 's');
  if (!filtered.length) {
    const title = users.length ? 'No matching accounts' : 'No accounts yet';
    const hint = users.length ? 'Try another name, email address, or role.' : 'Create a staff or administrator account to get started.';
    body.innerHTML = '<tr><td colspan="5">' + emptyState(title, hint) + '</td></tr>';
    return;
  }
  body.innerHTML = filtered.map(user => {
    const own = isOwnAccount(user);
    const locked = isAccountLocked(user);
    const inactive = locked || user.is_active === false || user.is_active === 0 || user.status === 'inactive';
    const roleKey = Object.prototype.hasOwnProperty.call(roleNames, user.role) ? user.role : 'customer';
    const avatarColors = ['', 'avatar-purple', 'avatar-teal', 'avatar-amber'];
    const colorIndex = Array.from(String(user.user_id)).reduce((sum, char) => sum + char.charCodeAt(0), 0) % avatarColors.length;
    return '<tr>' +
      '<td><div class="user-identity"><span class="user-avatar ' + avatarColors[colorIndex] + '" aria-hidden="true">' + esc(accountInitials(user.name)) + '</span><div><div class="user-name">' + esc(user.name) + (own ? '<span class="self-label">(you)</span>' : '') + '</div><div class="user-email">' + esc(user.email) + '</div></div></div></td>' +
      '<td><span class="role-badge role-' + roleKey + '">' + esc(roleNames[roleKey]) + '</span></td>' +
      '<td class="joined-date">' + fmtDate(user.created_at) + '</td>' +
      '<td><span class="status-badge status-' + (inactive ? 'inactive' : 'active') + '"' + (locked ? ' title="Temporarily locked after unsuccessful sign-in attempts"' : '') + '>' + (inactive ? 'Inactive' : 'Active') + '</span></td>' +
      '<td>' + (own ? '<span class="own-account">Your account</span>' : '<button class="account-menu-toggle" type="button" data-user-menu="' + esc(user.user_id) + '" aria-label="Actions for ' + esc(user.name) + '" aria-haspopup="menu" aria-controls="accountActionMenu" aria-expanded="false">' + accountIcon('more') + '</button>') + '</td></tr>';
  }).join('');
}
function closeUserMenu(restoreFocus = false) {
  const menu = document.getElementById('accountActionMenu');
  if (menu) menu.hidden = true;
  if (menuTrigger) {
    menuTrigger.setAttribute('aria-expanded', 'false');
    if (restoreFocus) menuTrigger.focus();
  }
  menuTrigger = null;
  menuUserId = null;
}
function openUserMenu(button) {
  const user = findUser(button.dataset.userMenu);
  if (!user || isOwnAccount(user)) return;
  if (menuTrigger === button) { closeUserMenu(true); return; }
  closeUserMenu();
  const menu = document.getElementById('accountActionMenu');
  menuUserId = user.user_id;
  menuTrigger = button;
  menu.querySelector('[data-account-action="unlock"]').hidden = !isAccountLocked(user);
  menu.setAttribute('aria-label', 'Actions for ' + user.name);
  button.setAttribute('aria-expanded', 'true');
  menu.hidden = false;
  const rect = button.getBoundingClientRect();
  const width = menu.offsetWidth || 208;
  const height = menu.offsetHeight || 172;
  menu.style.left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8)) + 'px';
  menu.style.top = Math.max(8, rect.bottom + height + 8 > window.innerHeight ? rect.top - height - 6 : rect.bottom + 6) + 'px';
  menu.querySelector('[data-account-action="edit"]').focus();
}
function wireAccountActions() {
  const rows = document.getElementById('userRows');
  rows.addEventListener('click', event => {
    const button = event.target.closest('[data-user-menu]');
    if (button) openUserMenu(button);
  });
  rows.addEventListener('keydown', event => {
    const button = event.target.closest('[data-user-menu]');
    if (button && event.key === 'ArrowDown') { event.preventDefault(); openUserMenu(button); }
  });
  const menu = document.getElementById('accountActionMenu');
  menu.addEventListener('click', event => {
    const button = event.target.closest('[data-account-action]');
    const user = findUser(menuUserId);
    if (!button || !user || isOwnAccount(user)) return;
    const action = button.dataset.accountAction;
    closeUserMenu(true);
    if (action === 'edit') openRoleModal(user);
    if (action === 'reset') openResetModal(user);
    if (action === 'remove' || action === 'unlock') openAccountConfirmation(user, action);
  });
  menu.addEventListener('keydown', event => {
    const items = Array.from(menu.querySelectorAll('[data-account-action]')).filter(item => !item.hidden);
    const current = items.indexOf(document.activeElement);
    let next;
    if (event.key === 'ArrowDown') next = (current + 1) % items.length;
    if (event.key === 'ArrowUp') next = (current - 1 + items.length) % items.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = items.length - 1;
    if (next !== undefined) { event.preventDefault(); items[next].focus(); }
    if (event.key === 'Escape') { event.preventDefault(); closeUserMenu(true); }
    if (event.key === 'Tab') closeUserMenu(true);
  });
  document.addEventListener('click', event => {
    if (!menu.hidden && !menu.contains(event.target) && !event.target.closest('[data-user-menu]')) closeUserMenu();
  });
  window.addEventListener('resize', () => closeUserMenu());
  document.addEventListener('scroll', event => { if (!menu.contains(event.target)) closeUserMenu(); }, true);
  document.getElementById('accountConfirmBtn').addEventListener('click', confirmAccountAction);
}
function setFormBusy(formId, busy) {
  document.getElementById(formId).querySelectorAll('input, select, textarea, button').forEach(input => { input.disabled = busy; });
}
function passwordRules(nameId, emailId, label) {
  return [Rules.required(label), Rules.passwordMinLength(), { test: (_value, input) => !/\s/.test(input.value), message: 'Password must not contain spaces.' }, Rules.passwordUppercase(), Rules.passwordLowercase(), Rules.passwordNumber(), Rules.passwordSpecial(), Rules.passwordPersonalInfo(nameId, emailId), {
    test: value => !value.includes('\0') && new TextEncoder().encode(value).length <= 72,
    message: 'Password must be 72 bytes or fewer and cannot contain null characters.',
  }];
}
const userRules = {
  userName: [Rules.required('Full name'), Rules.maxLength(100, 'Full name')],
  userEmail: [Rules.required('Email'), Rules.emailNoSpaces(), Rules.emailMaxLength(), Rules.emailStrict()],
  userPassword: passwordRules('userName', 'userEmail', 'Temporary password'),
};
const resetRules = {
  resetPassword: passwordRules('resetAccountName', 'resetAccountEmail', 'New password'),
  resetConfirmPassword: [Rules.required('Password confirmation'), Rules.matches('resetPassword', 'Passwords')],
};
function resetPasswordVisibility(ids) {
  ids.forEach(id => {
    document.getElementById(id).type = 'password';
    const toggle = document.querySelector('[data-password-toggle="' + id + '"]');
    if (toggle) { toggle.setAttribute('aria-pressed', 'false'); toggle.setAttribute('aria-label', 'Show password'); }
  });
}
function openUserModal() {
  if (!me || accountBusy.user) return;
  document.getElementById('userForm').reset();
  Object.keys(userRules).forEach(id => clearError(document.getElementById(id)));
  document.getElementById('userRole').value = 'staff';
  resetPasswordVisibility(['userPassword']);
  syncUserButton();
  accountModal('userModal').show();
}
function openRoleModal(user) {
  if (isOwnAccount(user) || accountBusy.role) return;
  roleUserId = user.user_id;
  document.getElementById('roleAccountLabel').textContent = user.name + ' · ' + user.email;
  document.getElementById('editUserRole').value = user.role;
  updateRoleDescription();
  accountModal('roleModal').show();
}
function updateRoleDescription() {
  document.getElementById('roleDescription').textContent = roleDescriptions[document.getElementById('editUserRole').value];
}
function openResetModal(user) {
  if (isOwnAccount(user) || accountBusy.reset) return;
  resetUserId = user.user_id;
  document.getElementById('resetForm').reset();
  document.getElementById('resetAccountName').value = user.name;
  document.getElementById('resetAccountEmail').value = user.email;
  document.getElementById('resetAccountLabel').textContent = user.name + ' · ' + user.email;
  Object.keys(resetRules).forEach(id => clearError(document.getElementById(id)));
  resetPasswordVisibility(['resetPassword', 'resetConfirmPassword']);
  syncResetButton();
  accountModal('resetPasswordModal').show();
}
function openAccountConfirmation(user, action) {
  if (isOwnAccount(user) || accountBusy.confirm) return;
  confirmation = { user_id: user.user_id, action };
  const removing = action === 'remove';
  document.getElementById('accountConfirmTitle').textContent = removing ? 'Remove account?' : 'Restore account access?';
  document.getElementById('accountConfirmMessage').textContent = removing
    ? 'Remove ' + user.name + ' (' + user.email + ')? This cannot be undone. Accounts referenced by borrowing history or other records cannot be removed.'
    : 'Restore sign-in access for ' + user.name + '? This clears their temporary lock after unsuccessful sign-in attempts.';
  const button = document.getElementById('accountConfirmBtn');
  button.classList.toggle('btn-danger', removing);
  button.classList.toggle('btn-primary', !removing);
  button.textContent = removing ? 'Remove account' : 'Restore access';
  accountModal('accountConfirmModal').show();
}
async function confirmAccountAction() {
  if (!confirmation || accountBusy.confirm) return;
  const action = confirmation;
  const user = findUser(action.user_id);
  if (!user || isOwnAccount(user)) return;
  const button = document.getElementById('accountConfirmBtn');
  accountBusy.confirm = true;
  button.disabled = true;
  button.textContent = action.action === 'remove' ? 'Removing…' : 'Restoring…';
  try {
    if (action.action === 'remove') await Api.deleteUser(action.user_id);
    else await Api.unlockUser(action.user_id);
    accountBusy.confirm = false;
    accountModal('accountConfirmModal').hide();
    toast(action.action === 'remove' ? 'Account removed.' : 'Account access restored.');
    confirmation = null;
    await loadUsers();
  } catch (error) { toast(error.message, 'bad'); }
  finally {
    accountBusy.confirm = false;
    button.disabled = false;
    button.textContent = action.action === 'remove' ? 'Remove account' : 'Restore access';
  }
}
function wireAccountForms() {
  syncUserButton = liveValidate(userRules, 'userSubmit');
  syncResetButton = liveValidate(resetRules, 'resetSubmit');
  document.getElementById('editUserRole').addEventListener('change', updateRoleDescription);
  document.querySelectorAll('[data-password-toggle]').forEach(button => {
    button.addEventListener('click', () => {
      const input = document.getElementById(button.dataset.passwordToggle);
      const visible = input.type === 'password';
      input.type = visible ? 'text' : 'password';
      button.setAttribute('aria-pressed', String(visible));
      button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
    });
  });
  const dialogs = [['userModal', 'user', 'userName'], ['roleModal', 'role', 'editUserRole'], ['resetPasswordModal', 'reset', 'resetPassword'], ['accountConfirmModal', 'confirm', 'accountConfirmBtn'], ['categoryModal', 'category', 'categoryName']];
  dialogs.forEach(([id, kind, focusId]) => {
    document.getElementById(id).addEventListener('show.bs.modal', () => {
      const element = document.activeElement;
      modalReturnFocus.set(id, { element, userId: element?.dataset?.userMenu, categoryId: element?.dataset?.editCategory });
    });
    document.getElementById(id).addEventListener('hidden.bs.modal', () => {
      const previous = modalReturnFocus.get(id);
      const replacement = previous?.userId ? Array.from(document.querySelectorAll('[data-user-menu]')).find(button => String(button.dataset.userMenu) === String(previous.userId))
        : previous?.categoryId ? Array.from(document.querySelectorAll('[data-edit-category]')).find(button => String(button.dataset.editCategory) === String(previous.categoryId)) : null;
      const fallbackId = id === 'userModal' ? 'addUserBtn' : id === 'categoryModal' ? 'addCategoryBtn' : 'userSearch';
      const target = previous?.element?.isConnected ? previous.element : replacement || document.getElementById(fallbackId);
      target?.focus();
    });
    document.getElementById(id).addEventListener('shown.bs.modal', () => document.getElementById(focusId).focus());
    document.getElementById(id).addEventListener('hide.bs.modal', event => { if (accountBusy[kind]) event.preventDefault(); });
  });
  document.getElementById('userModal').addEventListener('hidden.bs.modal', () => { document.getElementById('userPassword').value = ''; });
  document.getElementById('resetPasswordModal').addEventListener('hidden.bs.modal', () => {
    document.getElementById('resetPassword').value = '';
    document.getElementById('resetConfirmPassword').value = '';
  });
  document.getElementById('userForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (accountBusy.user || !validate(userRules)) return;
    const role = document.getElementById('userRole').value;
    if (!['admin', 'staff'].includes(role)) return;
    const button = document.getElementById('userSubmit');
    accountBusy.user = true;
    setFormBusy('userForm', true);
    button.textContent = 'Creating…';
    try {
      await Api.createUser({ name: document.getElementById('userName').value.trim(), email: document.getElementById('userEmail').value.trim(), password: document.getElementById('userPassword').value, role });
      accountBusy.user = false;
      accountModal('userModal').hide();
      toast('Account created.');
      await loadUsers();
    } catch (error) { toast(error.message, 'bad'); }
    finally { accountBusy.user = false; setFormBusy('userForm', false); button.textContent = 'Create account'; syncUserButton(); }
  });
  document.getElementById('roleForm').addEventListener('submit', async event => {
    event.preventDefault();
    const user = findUser(roleUserId);
    const role = document.getElementById('editUserRole').value;
    if (accountBusy.role || !user || isOwnAccount(user) || !Object.prototype.hasOwnProperty.call(roleNames, role)) return;
    const button = document.getElementById('roleSubmit');
    accountBusy.role = true; setFormBusy('roleForm', true); button.textContent = 'Saving…';
    try {
      await Api.setUserRole({ user_id: user.user_id, role });
      accountBusy.role = false; accountModal('roleModal').hide(); toast('Role updated.');
      await loadUsers();
    } catch (error) { toast(error.message, 'bad'); }
    finally { accountBusy.role = false; setFormBusy('roleForm', false); button.textContent = 'Save role'; }
  });
  document.getElementById('resetForm').addEventListener('submit', async event => {
    event.preventDefault();
    const user = findUser(resetUserId);
    if (accountBusy.reset || !user || isOwnAccount(user) || !validate(resetRules)) return;
    const button = document.getElementById('resetSubmit');
    accountBusy.reset = true; setFormBusy('resetForm', true); button.textContent = 'Resetting…';
    try {
      await Api.resetUserPassword(user.user_id, document.getElementById('resetPassword').value, usersCsrfToken);
      accountBusy.reset = false; accountModal('resetPasswordModal').hide(); toast('Password reset. Account access restored.');
      await loadUsers();
    } catch (error) { toast(error.message, 'bad'); showError(document.getElementById('resetPassword'), error.message); }
    finally { accountBusy.reset = false; setFormBusy('resetForm', false); button.textContent = 'Reset password'; syncResetButton(); }
  });
}
initializeAdmin();
