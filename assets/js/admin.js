/* Admin-only: manage accounts and equipment categories. */

let me = null;
let syncUserButton = null;
let syncCategoryButton = null;

(async function () {
  me = await requireSession(['admin']);

  document.getElementById('addUserBtn').addEventListener('click', openUserModal);
  document.getElementById('addCategoryBtn').addEventListener('click', () => openCategoryModal(null));

  wireUserForm();
  wireCategoryForm();

  await Promise.all([loadUsers(), loadCategories()]);
})();

/* ---------- People ---------- */

async function loadUsers() {
  const body = document.getElementById('userRows');
  try {
    const { data } = await Api.listUsers();
    body.innerHTML = data.map(u => {
      const isMe = String(u.user_id) === String(me.user_id);
      return `
        <tr>
          <td>${esc(u.name)}${isMe ? ' <span class="meta">(you)</span>' : ''}</td>
          <td>${esc(u.email)}</td>
          <td>
            <select class="form-select form-select-sm" data-role-for="${esc(u.user_id)}"
                    ${isMe ? 'disabled title="You cannot change your own role"' : ''}>
              <option value="admin"    ${u.role === 'admin' ? 'selected' : ''}>Administrator</option>
              <option value="staff"    ${u.role === 'staff' ? 'selected' : ''}>Staff</option>
              <option value="customer" ${u.role === 'customer' ? 'selected' : ''}>Customer</option>
            </select>
          </td>
          <td>${fmtDate(u.created_at)}</td>
          <td>
            ${u.locked_until && new Date(u.locked_until.replace(' ', 'T')) > new Date()
              ? '<span class="badge text-bg-warning">Locked</span>'
              : '<span class="badge text-bg-success">Active</span>'}
          </td>
          <td class="text-end">
            ${!isMe && u.locked_until && new Date(u.locked_until.replace(' ', 'T')) > new Date()
              ? `<button class="btn btn-sm btn-outline-success me-1" data-unlock-user="${esc(u.user_id)}" data-name="${esc(u.name)}">Restore access</button>`
              : ''}
            ${isMe ? '' : `<button class="btn btn-sm btn-outline-danger"
                                   data-remove-user="${esc(u.user_id)}"
                                   data-name="${esc(u.name)}">Remove</button>`}
          </td>
        </tr>`;
    }).join('');

    document.querySelectorAll('[data-role-for]').forEach(sel => {
      sel.addEventListener('change', () => changeRole(sel.dataset.roleFor, sel.value));
    });
    document.querySelectorAll('[data-unlock-user]').forEach(btn => {
      btn.addEventListener('click', () => unlockUser(btn.dataset.unlockUser, btn.dataset.name));
    });
    document.querySelectorAll('[data-remove-user]').forEach(btn => {
      btn.addEventListener('click', () => removeUser(btn.dataset.removeUser, btn.dataset.name));
    });
  } catch (err) {
    body.innerHTML = `<tr><td colspan="6">${emptyState("Couldn't load accounts", err.message)}</td></tr>`;
  }
}


async function unlockUser(userId, name) {
  if (!confirm(`Restore login access for ${name}?`)) return;
  try {
    await Api.unlockUser(userId);
    toast('Account access restored.');
    await loadUsers();
  } catch (err) {
    toast(err.message, 'bad');
  }
}

async function changeRole(userId, role) {
  try {
    await Api.setUserRole({ user_id: userId, role });
    toast('Role updated.');
  } catch (err) {
    toast(err.message, 'bad');
    await loadUsers();
  }
}

async function removeUser(userId, name) {
  if (!confirm(`Remove ${name}? Their borrowing history goes too.`)) return;
  try {
    await Api.deleteUser(userId);
    toast('Account removed.');
    await loadUsers();
  } catch (err) {
    toast(err.message, 'bad');
  }
}

const userModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('userModal'));

const userRules = {
  userName: [Rules.required('Full name'), Rules.maxLength(100, 'Full name')],
  userEmail: [Rules.required('Email'), Rules.emailNoSpaces(), Rules.emailMaxLength(), Rules.emailStrict()],
  userPassword: [
    Rules.required('Temporary password'),
    Rules.passwordMinLength(),
    Rules.passwordNoSpaces(),
    Rules.passwordUppercase(),
    Rules.passwordLowercase(),
    Rules.passwordNumber(),
    Rules.passwordSpecial(),
    Rules.passwordPersonalInfo('userName', 'userEmail'),
  ],
};

function openUserModal() {
  ['userName', 'userEmail', 'userPassword'].forEach(id => {
    const el = document.getElementById(id);
    el.value = '';
    clearError(el);
  });
  document.getElementById('userRole').value = 'staff';
  if (syncUserButton) syncUserButton();
  userModal().show();
}

function wireUserForm() {
  syncUserButton = liveValidate(userRules, 'userSubmit');
  document.getElementById('userForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!validate(userRules)) return;

    const btn = document.getElementById('userSubmit');
    btn.disabled = true;
    btn.textContent = 'Creating…';

    try {
      await Api.createUser({
        name: document.getElementById('userName').value.trim(),
        email: document.getElementById('userEmail').value.trim(),
        password: document.getElementById('userPassword').value,
        role: document.getElementById('userRole').value,
      });
      userModal().hide();
      toast('Account created.');
      await loadUsers();
    } catch (err) {
      toast(err.message, 'bad');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Create account';
    }
  });
}

/* ---------- Categories ---------- */

async function loadCategories() {
  const host = document.getElementById('categoryList');
  try {
    const { data } = await Api.listCategories({ limit: 50 });
    if (!data.length) {
      host.innerHTML = emptyState('No categories yet', 'Add one so equipment can be grouped and filtered.');
      return;
    }
    host.innerHTML = data.map(c => `
      <article class="item-row">
        <div class="grow">
          <h3>${esc(c.category_name)}</h3>
          <div class="meta">${esc(c.description || 'No description')}</div>
        </div>
        <div class="actions">
          <button class="btn btn-sm btn-outline-secondary"
                  data-edit-category="${esc(c.category_id)}"
                  data-name="${esc(c.category_name)}"
                  data-description="${esc(c.description || '')}">Edit</button>
          <button class="btn btn-sm btn-outline-danger"
                  data-delete-category="${esc(c.category_id)}"
                  data-name="${esc(c.category_name)}">Delete</button>
        </div>
      </article>`).join('');

    document.querySelectorAll('[data-edit-category]').forEach(btn => {
      btn.addEventListener('click', () => openCategoryModal({
        id: btn.dataset.editCategory,
        name: btn.dataset.name,
        description: btn.dataset.description,
      }));
    });
    document.querySelectorAll('[data-delete-category]').forEach(btn => {
      btn.addEventListener('click', () => removeCategory(btn.dataset.deleteCategory, btn.dataset.name));
    });
  } catch (err) {
    host.innerHTML = emptyState("Couldn't load categories", err.message);
  }
}

const categoryModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('categoryModal'));

const categoryRules = {
  categoryName: [Rules.required('Name'), Rules.maxLength(100, 'Name')],
  categoryDescription: [Rules.maxLength(500, 'Description')],
};

function openCategoryModal(existing) {
  document.getElementById('categoryTitle').textContent = existing ? 'Edit category' : 'Add category';
  document.getElementById('categoryId').value = existing ? existing.id : '';
  const nameEl = document.getElementById('categoryName');
  const descEl = document.getElementById('categoryDescription');
  nameEl.value = existing ? existing.name : '';
  descEl.value = existing ? existing.description : '';
  clearError(nameEl);
  clearError(descEl);
  if (syncCategoryButton) syncCategoryButton();
  categoryModal().show();
}

function wireCategoryForm() {
  syncCategoryButton = liveValidate(categoryRules, 'categorySubmit');
  document.getElementById('categoryForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!validate(categoryRules)) return;

    const id = document.getElementById('categoryId').value;
    const payload = {
      category_name: document.getElementById('categoryName').value.trim(),
      description: document.getElementById('categoryDescription').value.trim(),
    };

    const btn = document.getElementById('categorySubmit');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    try {
      if (id) {
        await Api.updateCategory({ ...payload, category_id: id });
        toast('Category updated.');
      } else {
        await Api.createCategory(payload);
        toast('Category added.');
      }
      categoryModal().hide();
      await loadCategories();
    } catch (err) {
      toast(err.message, 'bad');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Save';
    }
  });
}

async function removeCategory(id, name) {
  if (!confirm(`Delete "${name}"? Equipment in it becomes uncategorised.`)) return;
  try {
    await Api.deleteCategory(id);
    toast('Category deleted.');
    await loadCategories();
  } catch (err) {
    toast(err.message, 'bad');
  }
}
