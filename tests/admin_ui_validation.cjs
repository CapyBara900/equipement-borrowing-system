const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.join(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'admin.html'), 'utf8');
const controller = fs.readFileSync(path.join(root, 'assets/js/admin.js'), 'utf8');
const api = fs.readFileSync(path.join(root, 'assets/js/api.js'), 'utf8');
const categoryController = fs.readFileSync(path.join(root, 'assets/js/categories.js'), 'utf8');
const app = fs.readFileSync(path.join(root, 'assets/js/app.js'), 'utf8');
const inlineScripts = [...html.matchAll(/<script\b(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)].map(match => match[1]);
const escape = value => String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
const sampleUsers = [
  {user_id: 1, name: 'Darrelle Soriano', email: 'darrelle@example.test', role: 'admin', created_at: '2026-01-03 10:00:00', locked_until: null},
  {user_id: 2, name: 'Mina Alvarez', email: 'help.desk@example.test', role: 'staff', created_at: '2026-02-14 09:30:00', locked_until: null},
  {user_id: 3, name: 'Luis Cruz', email: 'luis@example.test', role: 'customer', created_at: '2026-03-20 14:00:00', locked_until: '2099-01-01 00:00:00'},
  {user_id: 4, name: '<img src=x onerror=alert(1)>', email: 'unsafe&test@example.test', role: 'customer', created_at: '2026-04-20 14:00:00', locked_until: null},
];
const flush = async () => { for (let iteration = 0; iteration < 3; iteration++) await new Promise(resolve => setImmediate(resolve)); };
function harness({deferFirstLoad = false, withCategories = false} = {}) {
  const nodes = [], elements = new Map(), notifications = [], requests = [], writes = [], documentListeners = {};
  let users = structuredClone(sampleUsers), loadError = null, writeError = null, resolveFirstLoad;
  let categories = [{category_id: 10, category_name: 'Audio & Visual', description: 'Cameras <and> projectors.', equipment_count: 5}, {category_id: 11, category_name: 'Computing', description: '', equipment_count: 0}];
  const categorySubscribers = [], confirmations = []; let confirmResponse = false;
  const categoryStore = {get rows() {return categories;}, subscribe(callback) {categorySubscribers.push(callback);}, async start() {categorySubscribers.forEach(callback => callback(structuredClone(categories)));}, async changed() {categorySubscribers.forEach(callback => callback(structuredClone(categories)));}};
  let firstLoad = true;
  const firstLoadGate = deferFirstLoad ? new Promise(resolve => { resolveFirstLoad = resolve; }) : null;
  function createNode(tagName, attributes = {}, parent = null) {
    let markup = '';
    const attrs = {...attributes}, classes = new Set((attrs.class || '').split(/\s+/).filter(Boolean));
    const listeners = {}, dataset = {};
    Object.entries(attrs).filter(([name]) => name.startsWith('data-')).forEach(([name, value]) => { dataset[name.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())] = value; });
    const node = {
      id: attrs.id || '', tagName: tagName.toUpperCase(), attributes: attrs, dataset, listeners, style: {}, parentElement: parent, children: [], defaultValue: attrs.value || '',
      value: attrs.value || '', type: attrs.type || 'text', textContent: '', get innerHTML() {return markup;}, set innerHTML(value) {markup = String(value); this.children = []; parseMarkup(markup, this);}, hidden: 'hidden' in attrs, disabled: 'disabled' in attrs, open: false,
      get isConnected() {let candidate = node; while (candidate.parentElement) {const parent = candidate.parentElement; if (!parent.children.includes(candidate)) return false; candidate = parent;} return candidate.tagName === 'HTML';},
      classList: {add(...names) {names.forEach(name => classes.add(name));}, remove(...names) {names.forEach(name => classes.delete(name));}, contains: name => classes.has(name), toggle(name, force) {const active = force === undefined ? !classes.has(name) : Boolean(force); active ? classes.add(name) : classes.delete(name); return active;}},
      setAttribute(name, value) {attrs[name] = String(value); if (name === 'hidden') this.hidden = true;},
      getAttribute(name) {return attrs[name] ?? null;},
      removeAttribute(name) {delete attrs[name]; if (name === 'hidden') this.hidden = false;},
      addEventListener(type, listener) {const previous = listeners[type]; listeners[type] = previous ? event => { const first = previous(event); const second = listener(event); return Promise.all([first, second]); } : listener;},
      focus() {document.activeElement = node;}, reset() {this.querySelectorAll('input, select, textarea').forEach(input => {input.value = input.defaultValue;});}, scrollIntoView() {},
      contains(other) {for (let candidate = other; candidate; candidate = candidate.parentElement) if (candidate === node) return true; return false;}, matches(selector) {return matches(node, selector);}, closest(selector) {return matches(node, selector) ? node : null;},
      querySelector(selector) {return this.querySelectorAll(selector)[0] || null;}, querySelectorAll(selector) {return nodes.filter(candidate => candidate !== node && node.contains(candidate) && matches(candidate, selector));},
      getBoundingClientRect() {return {left: 100, right: 160, top: 100, bottom: 130, width: 60, height: 30};},
    };
    nodes.push(node); parent?.children.push(node); if (node.id) elements.set(node.id, node); return node;
  }
  function matches(node, selector) {
    if (selector.includes(',')) return selector.split(',').some(part => matches(node, part.trim()));
    const attrSelectors = [...selector.matchAll(/\[([^\s=\]]+)(?:\s*=\s*["']?([^"'\]]*)["']?)?\]/g)];
    if (!attrSelectors.every(([, name, value]) => name in node.attributes && (value === undefined || node.attributes[name] === value))) return false;
    const basic = selector.replace(/\[[^\]]*\]/g, '').trim();
    if (!basic) return true;
    if (basic[0] === '#') return node.id === basic.slice(1);
    if (basic[0] === '.') return basic.slice(1).split('.').every(name => node.classList.contains(name));
    return node.tagName.toLowerCase() === basic.toLowerCase();
  }
  function parseMarkup(source, parent = null) {
    const stack = parent ? [parent] : [];
    for (const [full, tagName, rawAttributes] of source.matchAll(/<\/?([a-z][\w:-]*)\b([^<>]*?)>/gi)) {
      if (full.startsWith('</')) {const index = stack.findLastIndex(candidate => candidate.tagName.toLowerCase() === tagName.toLowerCase()); if (index >= 0) stack.length = index; continue;}
      const attributes = {};
      for (const [, name, double, single, plain] of rawAttributes.matchAll(/([^\s=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g)) attributes[name] = double ?? single ?? plain ?? '';
      const node = createNode(tagName, attributes, stack.at(-1) || null);
      if (!/^(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)$/i.test(tagName) && !full.endsWith('/>')) stack.push(node);
    }
  }
  parseMarkup(html);
  for (const [, id, contents] of html.matchAll(/<select\b[^>]*id="([^"]+)"[^>]*>([\s\S]*?)<\/select>/gi)) {
    const options = [...contents.matchAll(/<option\b([^>]*)value="([^"]*)"([^>]*)>/gi)];
    const selected = options.find(option => /\bselected\b/.test(option[1] + option[3])) || options[0];
    if (selected) {elements.get(id).value = selected[2]; elements.get(id).defaultValue = selected[2];}
  }
  const document = {
    activeElement: null, hidden: false, body: {insertAdjacentHTML() {}},
    getElementById: id => elements.get(id)?.isConnected ? elements.get(id) : null,
    querySelector: selector => nodes.find(node => node.isConnected && matches(node, selector)) || null,
    querySelectorAll: selector => nodes.filter(node => node.isConnected && matches(node, selector)),
    addEventListener(type, listener) {documentListeners[type] = listener;},
  };
  const modal = node => ({
    show() {node.listeners['show.bs.modal']?.({target: node}); node.open = true; node.listeners['shown.bs.modal']?.({target: node});},
    hide() {let prevented = false; node.listeners['hide.bs.modal']?.({target: node, preventDefault() {prevented = true;}}); if (!prevented) {node.open = false; setImmediate(() => node.listeners['hidden.bs.modal']?.({target: node}));}},
  });
  const context = vm.createContext({
    document, console, URLSearchParams, Date, Intl, Promise, Math, TextEncoder,
    window: {innerWidth: 1280, innerHeight: 800, scrollX: 0, scrollY: 0, addEventListener() {}},
    bootstrap: {Modal: {getOrCreateInstance: modal}},
    requireSession: async roles => {assert.deepEqual(Array.from(roles), ['admin']); return {user_id: 1, name: 'Darrelle Soriano', email: 'darrelle@example.test', role: 'admin'};},
    CategoryManager: {init(user) {assert.equal(user.role, 'admin'); elements.get('categoryList').classList.remove('is-loading');}}, CategoryStore: categoryStore,
    confirm(message) {confirmations.push(message); return confirmResponse;},
    toast: (message, type) => notifications.push({message, type}), esc: escape,
    emptyState: (title, detail = '') => '<div>' + escape(title) + '<p>' + escape(detail) + '</p></div>',
    fmtDate: value => value ? String(value).slice(0, 10) : '—',
    setTimeout, clearTimeout, requestAnimationFrame: callback => callback(),
    async fetch(url, options) {
      const method = options.method || 'GET', body = options.body ? JSON.parse(options.body) : null;
      requests.push({url, method, body});
      if (url.includes('categories/')) {
        if (method === 'GET') {const categoryId = new URL(url, 'http://localhost/').searchParams.get('id'); const data = categoryId ? categories.find(category => String(category.category_id) === String(categoryId)) : categories; return {ok: true, status: 200, json: async () => ({success: true, data: structuredClone(data)})};}
        writes.push({url, method, body});
        if (method === 'DELETE') {const id = new URL(url, 'http://localhost/').searchParams.get('id'); categories = categories.filter(category => String(category.category_id) !== String(id));}
        if (method === 'POST') categories.push({...body, category_id: 12, equipment_count: 0});
        if (method === 'PUT') categories = categories.map(category => String(category.category_id) === String(body.category_id) ? {...category, ...body} : category);
        return {ok: true, status: 200, json: async () => ({success: true})};
      }
      if (method === 'GET') {
        if (firstLoad && firstLoadGate) {firstLoad = false; await firstLoadGate;}
        if (loadError) return {ok: false, status: 500, json: async () => ({success: false, message: loadError.message})};
        return {ok: true, status: 200, json: async () => ({success: true, data: structuredClone(users), csrf_token: 'test-csrf-token'})};
      }
      writes.push({url, method, body});
      if (writeError) return {ok: false, status: 400, json: async () => ({success: false, message: writeError.message})};
      if (method === 'POST') users.push({...body, user_id: Math.max(...users.map(user => user.user_id), 0) + 1, created_at: '2026-10-10 08:00:00', locked_until: null});
      if (method === 'PUT' && body.role) users = users.map(user => String(user.user_id) === String(body.user_id) ? {...user, role: body.role} : user);
      if (method === 'PUT' && body.action === 'unlock') users = users.map(user => String(user.user_id) === String(body.user_id) ? {...user, locked_until: null} : user);
      if (method === 'DELETE') {const id = new URL(url, 'http://localhost/').searchParams.get('id'); users = users.filter(user => String(user.user_id) !== String(id));}
      return {ok: true, status: 200, json: async () => ({success: true})};
    },
  });
  vm.runInContext(api, context);
  vm.runInContext(app.slice(app.indexOf('function showError('), app.indexOf('/* ---------- Small formatters')), context);
  if (withCategories) vm.runInContext(categoryController.slice(categoryController.indexOf('const CategoryManager =')), context);
  vm.runInContext(controller, context);
  for (const script of inlineScripts) vm.runInContext(script, context);
  documentListeners.DOMContentLoaded?.();
  return {
    context, document, nodes, element: id => {const found = elements.get(id); assert.ok(found, 'Expected #' + id + ' in admin.html'); return found;}, notifications, requests, writes,
    execute: source => vm.runInContext(source, context),
    dispatch: async (id, type, event = {}) => {const node = elements.get(id); assert.ok(node?.listeners[type], '#' + id + ' must listen for ' + type); if (type === 'click' && node.tagName === 'BUTTON') node.focus(); await node.listeners[type]({target: node, preventDefault() {}, ...event}); await flush();},
    releaseLoad() {resolveFirstLoad?.();}, setUsers(value) {users = structuredClone(value);}, setLoadError(error) {loadError = error;}, setWriteError(error) {writeError = error;},
    createNode, confirmations, setConfirmResponse(value) {confirmResponse = value;},
    setCategories(value) {categories = structuredClone(value); categorySubscribers.forEach(callback => callback(structuredClone(categories)));},
  };
}

(async () => {
  assert.match(html, /<style>[\s\S]*#0f172a[\s\S]*<\/style>/i, 'Admin page must include its design styles');
  assert.match(html, /Manage system accounts, user permissions, and equipment categorization\./);
  const userRoleOptions = html.match(/<select\b[^>]*id="userRole"[^>]*>([\s\S]*?)<\/select>/i)?.[1];
  assert.ok(userRoleOptions, 'Account creation role select must exist');
  assert.match(userRoleOptions, /value="staff"/); assert.match(userRoleOptions, /value="admin"/);
  assert.doesNotMatch(userRoleOptions, /value="customer"/, 'Add Staff or Admin must only create privileged accounts');
  const h = harness({deferFirstLoad: true});
  await flush();
  assert.match(h.element('userRows').innerHTML, /loading|Loading/);
  assert.equal(h.element('peopleTab').hidden, false);
  assert.equal(h.element('categoryTab').hidden, true);
  assert.equal(h.element('peopleTabButton').getAttribute('aria-selected'), 'true');
  assert.equal(h.element('categoryTabButton').getAttribute('aria-selected'), 'false');
  await h.dispatch('categoryTabButton', 'click');
  assert.equal(h.element('peopleTab').hidden, true); assert.equal(h.element('categoryTab').hidden, false);
  assert.equal(h.element('categoryTabButton').getAttribute('aria-selected'), 'true');
  await h.dispatch('categoryTabButton', 'keydown', {key: 'ArrowLeft'});
  assert.equal(h.element('peopleTab').hidden, false); assert.equal(h.document.activeElement, h.element('peopleTabButton'));
  await h.dispatch('peopleTabButton', 'keydown', {key: 'End'});
  assert.equal(h.element('categoryTab').hidden, false);
  await h.dispatch('categoryTabButton', 'keydown', {key: 'Home'});
  assert.equal(h.element('peopleTab').hidden, false);
  h.releaseLoad(); await flush();
  const initialRows = h.element('userRows').innerHTML;
  assert.match(initialRows, /Darrelle Soriano/); assert.match(initialRows, />\s*DS\s*</);
  assert.match(initialRows, /Administrator/); assert.match(initialRows, /Staff/); assert.match(initialRows, /Customer/);
  assert.match(initialRows, /Inactive/); assert.match(initialRows, /Active/);
  assert.match(initialRows, /&lt;img src=x onerror=alert\(1\)&gt;/);
  assert.match(initialRows, /unsafe&amp;test@example\.test/);
  assert.doesNotMatch(initialRows, /<img\b/);
  h.element('userSearch').value = '  MINA  '; await h.dispatch('userSearch', 'input');
  assert.match(h.element('userRows').innerHTML, /Mina Alvarez/); assert.doesNotMatch(h.element('userRows').innerHTML, /Luis Cruz/);
  h.element('userSearch').value = 'HELP.DESK'; await h.dispatch('userSearch', 'input');
  assert.match(h.element('userRows').innerHTML, /Mina Alvarez/);
  h.element('roleFilter').value = 'customer'; await h.dispatch('roleFilter', 'change');
  assert.match(h.element('userRows').innerHTML, /No .*accounts|No .*people|No .*users|No matches/i);
  h.element('roleFilter').value = 'staff'; await h.dispatch('roleFilter', 'change');
  assert.match(h.element('userRows').innerHTML, /Mina Alvarez/);
  h.element('userSearch').value = ''; h.element('roleFilter').value = ''; await h.dispatch('userSearch', 'input');

  const keyboardTrigger = h.document.querySelector('[data-user-menu="2"]');
  await h.dispatch('userRows', 'keydown', {key: 'ArrowDown', target: keyboardTrigger});
  assert.equal(h.element('accountActionMenu').hidden, false); assert.equal(keyboardTrigger.getAttribute('aria-expanded'), 'true');
  await h.dispatch('accountActionMenu', 'keydown', {key: 'ArrowDown'});
  assert.equal(h.document.activeElement.dataset.accountAction, 'reset');
  await h.dispatch('accountActionMenu', 'keydown', {key: 'Escape'});
  assert.equal(h.element('accountActionMenu').hidden, true); assert.equal(h.document.activeElement, keyboardTrigger);
  await h.dispatch('userRows', 'keydown', {key: 'ArrowDown', target: keyboardTrigger});
  await h.dispatch('accountActionMenu', 'keydown', {key: 'Tab'});
  assert.equal(h.element('accountActionMenu').hidden, true); assert.equal(h.document.activeElement, keyboardTrigger, 'Tab must return to the row trigger before native focus progression');

  async function chooseAction(userId, action) {
    const trigger = h.document.querySelector('[data-user-menu="' + userId + '"]') || h.createNode('button', {'data-user-menu': String(userId)});
    trigger.focus();
    await h.dispatch('userRows', 'click', {target: {closest: selector => selector === '[data-user-menu]' ? trigger : null}});
    const actionButton = h.element('accountActionMenu').querySelector('[data-account-action="' + action + '"]');
    await h.dispatch('accountActionMenu', 'click', {target: {closest: selector => selector === '[data-account-action]' ? actionButton : null}});
  }
  async function enter(id, value) {h.element(id).value = value; if (h.element(id).listeners.input) await h.dispatch(id, 'input');}
  await chooseAction(2, 'edit');
  assert.equal(h.element('roleModal').open, true); assert.equal(h.element('editUserRole').value, 'staff');
  assert.match(h.element('roleAccountLabel').textContent, /Mina Alvarez/);
  h.element('editUserRole').value = 'admin'; await h.dispatch('roleForm', 'submit');
  assert.equal(h.element('roleModal').open, false); assert.equal(h.document.activeElement, h.document.querySelector('[data-user-menu="2"]'), 'Role dialog must restore focus to its current row action');
  assert.equal(h.writes.at(-1).method, 'PUT'); assert.deepEqual({...h.writes.at(-1).body, user_id: String(h.writes.at(-1).body.user_id)}, {user_id: '2', role: 'admin'});
  await chooseAction(2, 'edit'); h.element('editUserRole').value = 'staff'; h.setWriteError(new Error('Role update failed.'));
  await h.dispatch('roleForm', 'submit');
  assert.equal(h.element('roleModal').open, true); assert.match(h.notifications.at(-1).message, /Role update failed/);
  h.setWriteError(null); await h.dispatch('roleForm', 'submit'); assert.equal(h.element('roleModal').open, false);

  await chooseAction(2, 'reset');
  assert.equal(h.element('resetPasswordModal').open, true); assert.equal(h.element('resetAccountName').value, 'Mina Alvarez');
  assert.equal(h.element('resetAccountEmail').value, 'help.desk@example.test');
  let before = h.writes.length;
  await enter('resetPassword', 'short'); await enter('resetConfirmPassword', 'short'); await h.dispatch('resetForm', 'submit');
  assert.equal(h.writes.length, before); assert.equal(h.element('resetPasswordModal').open, true);
  await enter('resetPassword', 'Orbit!Kite7392'); await enter('resetConfirmPassword', 'Different!7392'); await h.dispatch('resetForm', 'submit');
  assert.equal(h.writes.length, before, 'Mismatching reset passwords must be blocked');
  await enter('resetConfirmPassword', 'Orbit!Kite7392');
  h.setWriteError(new Error('Password reset failed.')); await h.dispatch('resetForm', 'submit');
  assert.equal(h.element('resetPasswordModal').open, true); assert.match(h.notifications.at(-1).message, /Password reset failed/);
  h.setWriteError(null); await h.dispatch('resetForm', 'submit');
  assert.equal(h.element('resetPasswordModal').open, false);
  assert.equal(h.writes.at(-1).method, 'PUT');
  assert.deepEqual({...h.writes.at(-1).body, user_id: String(h.writes.at(-1).body.user_id)}, {user_id: '2', action: 'reset_password', password: 'Orbit!Kite7392', csrf_token: 'test-csrf-token'});

  await h.dispatch('addUserBtn', 'click');
  assert.equal(h.element('userModal').open, true); assert.equal(h.element('userRole').value, 'staff'); assert.equal(h.element('userSubmit').disabled, true);
  before = h.writes.length; await h.dispatch('userForm', 'submit'); assert.equal(h.writes.length, before);
  assert.equal(h.element('userName').getAttribute('aria-invalid'), 'true');
  await enter('userName', '  Aria Reyes  '); await enter('userEmail', 'aria@example.test');
  h.document.querySelector('[data-password-toggle="userPassword"]').listeners.click({});
  await enter('userPassword', ' Orbit!Kite7392 ');
  assert.equal(h.element('userPassword').type, 'text'); assert.equal(h.element('userSubmit').disabled, true, 'Visible passwords must preserve raw spaces during validation');
  await h.dispatch('userForm', 'submit'); assert.equal(h.writes.length, before);
  await enter('userPassword', 'Orbit!Kite7392');
  assert.equal(h.element('userSubmit').disabled, false);
  h.element('userRole').value = 'customer'; await h.dispatch('userForm', 'submit');
  assert.equal(h.writes.length, before, 'Forged customer role must not bypass Add Staff or Admin');
  h.element('userRole').value = 'staff'; await h.dispatch('userForm', 'submit');
  assert.equal(h.element('userModal').open, false); assert.equal(h.writes.at(-1).method, 'POST');
  assert.deepEqual(h.writes.at(-1).body, {name: 'Aria Reyes', email: 'aria@example.test', password: 'Orbit!Kite7392', role: 'staff'});
  assert.match(h.element('userRows').innerHTML, /Aria Reyes/);
  await h.dispatch('addUserBtn', 'click'); await enter('userName', 'Noah Santos'); await enter('userEmail', 'noah@example.test'); await enter('userPassword', 'Orbit!Kite7392');
  h.element('userRole').value = 'admin'; h.setWriteError(new Error('Account creation failed.')); await h.dispatch('userForm', 'submit');
  assert.equal(h.element('userModal').open, true); assert.equal(h.element('userSubmit').disabled, false);
  h.setWriteError(null); await h.dispatch('userForm', 'submit');
  assert.equal(h.element('userModal').open, false); assert.equal(h.writes.at(-1).body.role, 'admin');

  before = h.writes.length; await chooseAction(3, 'unlock');
  assert.equal(h.element('accountConfirmModal').open, true); assert.equal(h.writes.length, before);
  await h.dispatch('accountConfirmBtn', 'click');
  assert.equal(h.writes.at(-1).body.action, 'unlock'); assert.equal(String(h.writes.at(-1).body.user_id), '3');

  before = h.writes.length; await chooseAction(3, 'remove');
  assert.equal(h.element('accountConfirmModal').open, true); assert.equal(h.writes.length, before, 'Removal requires the confirmation dialog');
  await h.dispatch('accountConfirmBtn', 'click');
  assert.equal(h.element('accountConfirmModal').open, false); assert.equal(h.writes.at(-1).method, 'DELETE');
  assert.match(h.writes.at(-1).url, /id=3/); assert.doesNotMatch(h.element('userRows').innerHTML, /Luis Cruz/);
  assert.equal(h.document.activeElement, h.element('userSearch'), 'Removed row must restore focus to search');
  before = h.writes.length;
  await chooseAction(1, 'edit'); await chooseAction(1, 'remove');
  assert.equal(h.writes.length, before, 'Own administrator account must be protected');
  assert.equal(h.element('roleModal').open, false); assert.equal(h.element('accountConfirmModal').open, false);

  h.setLoadError(new Error('Database unavailable.')); await h.execute('loadUsers()');
  assert.match(h.element('userRows').innerHTML, /Could not load accounts|Couldn.t load accounts|Unable to load accounts/i);
  assert.match(h.element('userRows').innerHTML, /Database unavailable/);
  h.setLoadError(null); h.setUsers([]); await h.dispatch('retryUsers', 'click');
  assert.match(h.element('userRows').innerHTML, /No .*accounts|No .*people|No .*users/i);
  h.setUsers(sampleUsers); await h.execute('loadUsers()'); assert.match(h.element('userRows').innerHTML, /Darrelle Soriano/);

  const categories = harness({withCategories: true}); await flush();
  const cards = categories.element('categoryList').innerHTML;
  assert.match(cards, /category-card/); assert.match(cards, /<svg/); assert.match(cards, /Audio &amp; Visual/);
  assert.match(cards, /Cameras &lt;and&gt; projectors\./); assert.match(cards, /5 Assigned Items/);
  assert.match(cards, /Edit Details/); assert.match(cards, /Delete Category/);
  await categories.dispatch('addCategoryBtn', 'click');
  assert.equal(categories.element('categoryModal').open, true); assert.equal(categories.element('categorySubmit').disabled, true);
  categories.element('categoryName').value = 'Field Tools'; await categories.dispatch('categoryName', 'input');
  await categories.dispatch('categoryForm', 'submit');
  assert.equal(categories.element('categoryModal').open, false); assert.equal(categories.writes.at(-1).method, 'POST');
  assert.deepEqual(categories.writes.at(-1).body, {category_name: 'Field Tools', description: ''});
  assert.equal(categories.document.activeElement, categories.element('addCategoryBtn'), 'Add Category must restore focus to its trigger');
  const categoryEdit = categories.document.querySelector('[data-edit-category="10"]'); categoryEdit.focus();
  await categories.dispatch('categoryList', 'click', {target: categoryEdit});
  assert.equal(categories.element('categoryModal').open, true); assert.equal(categories.element('categoryName').value, 'Audio & Visual');
  categories.element('categoryName').value = 'Audio & Visual Studio'; await categories.dispatch('categoryName', 'input'); await categories.dispatch('categoryForm', 'submit');
  assert.equal(categories.writes.at(-1).method, 'PUT'); assert.equal(String(categories.writes.at(-1).body.category_id), '10');
  assert.equal(categories.document.activeElement, categories.document.querySelector('[data-edit-category="10"]'), 'Edited category must restore focus to its replacement card button');
  const categoryRows = structuredClone(categories.execute('CategoryStore.rows'));
  const missingCategory = categories.document.querySelector('[data-edit-category="11"]'); missingCategory.focus();
  await categories.dispatch('categoryList', 'click', {target: missingCategory});
  categories.setCategories(categoryRows.filter(category => String(category.category_id) !== '11'));
  categories.execute('accountModal("categoryModal").hide()'); await flush();
  assert.equal(categories.document.activeElement, categories.element('addCategoryBtn'), 'A removed category trigger must fall back to Add Category');
  categories.setCategories(categoryRows);
  const deleteButton = categories.createNode('button', {'data-delete-category': '10'});
  before = categories.writes.length;
  await categories.dispatch('categoryList', 'click', {target: {closest: selector => selector === '[data-delete-category]' ? deleteButton : null}});
  assert.equal(categories.writes.length, before); assert.equal(categories.confirmations.length, 0);
  assert.match(categories.notifications.at(-1).message, /Reassign/);
  deleteButton.dataset.deleteCategory = '11';
  await categories.dispatch('categoryList', 'click', {target: {closest: selector => selector === '[data-delete-category]' ? deleteButton : null}});
  assert.equal(categories.writes.length, before); assert.match(categories.confirmations.at(-1), /Computing/);
  categories.setConfirmResponse(true);
  await categories.dispatch('categoryList', 'click', {target: {closest: selector => selector === '[data-delete-category]' ? deleteButton : null}});
  assert.equal(categories.writes.at(-1).method, 'DELETE'); assert.doesNotMatch(categories.element('categoryList').innerHTML, />Computing</);
  console.log('PASS: People and Categories tabs with keyboard navigation, loading/error/empty states, escaped identities, combined search and role filtering, role/password/account modals with API payloads, own-account protection, category cards and assignment-safe deletion.');
})().catch(error => {console.error(error); process.exitCode = 1;});
