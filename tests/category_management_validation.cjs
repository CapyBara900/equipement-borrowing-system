const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.join(__dirname, '..');
const shared = fs.readFileSync(path.join(root, 'assets/js/categories.js'), 'utf8');
const app = fs.readFileSync(path.join(root, 'assets/js/app.js'), 'utf8');
const equipment = fs.readFileSync(path.join(root, 'assets/js/equipment.js'), 'utf8');
let rows = [{ category_id: 1, category_name: 'Audio Visual', description: '', equipment_count: 0 }];
let nextId = 2, writes = [], loads = 0;
const streams = [], channels = [];
const normalize = value => value.replace(/[\s\p{Z}]+/gu, ' ').trim();
const copy = () => JSON.parse(JSON.stringify(rows));
function harness(role) {
  const elements = new Map(), timers = [], notifications = [];
  function element(id) {
    if (!elements.has(id)) {
      let value = '', html = ''; const classes = new Set();
      const node = {
        id, hidden: true, disabled: false, type: 'text', listeners: {}, textContent: '', open: false,
        get value() { return value; }, set value(v) { value = String(v); },
        get innerHTML() { return html; }, set innerHTML(v) { html = v; },
        classList: { add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c) },
        addEventListener(event, fn) { this.listeners[event] = fn; },
        setAttribute() {}, removeAttribute() {}, focus() {}, scrollIntoView() {},
      };
      elements.set(id, node);
    }
    return elements.get(id);
  }
  class EventSource {
    constructor(url) { this.url = url; this.listeners = {}; streams.push(this); }
    addEventListener(name, listener) { this.listeners[name] = listener; }
    close() { this.closed = true; }
  }
  class BroadcastChannel {
    constructor() { channels.push(this); }
    postMessage(value) { channels.filter(channel => channel !== this).forEach(channel => channel.onmessage?.({ data: value })); }
    close() { this.closed = true; }
  }
  const ctx = vm.createContext({
    document: { hidden: false, getElementById: element, querySelector: element, addEventListener() {} },
    window: { addEventListener() {} }, localStorage: { setItem() {} },
    bootstrap: { Modal: { getOrCreateInstance: () => ({ show() { element('categoryModal').open = true; }, hide() { element('categoryModal').open = false; } }) } },
    confirm: () => true, EventSource, BroadcastChannel,
    setInterval(fn) { timers.push(fn); return timers.length; }, clearInterval() {},
    toast(message, type) { notifications.push({ message, type }); },
    esc: value => String(value).replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch])),
    emptyState: title => title,
    Api: {
      async listCategories(query) {
        if (query.id) return { data: copy().find(row => String(row.category_id) === String(query.id)) };
        assert.equal(query.all, 1); return { data: copy() };
      },
      async createCategory(payload) { return save(payload); },
      async updateCategory(payload) { return save(payload); },
      async deleteCategory(id) { writes.push({ delete: id }); rows = rows.filter(row => String(row.category_id) !== String(id)); },
    },
    console,
  });
  function save(payload) {
    const duplicate = rows.find(row => String(row.category_id) !== String(payload.category_id) && row.category_name.toLowerCase() === normalize(payload.category_name).toLowerCase());
    if (duplicate) { const error = new Error('A category with this name already exists.'); error.code = 'CATEGORY_DUPLICATE'; error.status = 409; throw error; }
    writes.push(payload);
    if (payload.category_id) rows = rows.map(row => String(row.category_id) === String(payload.category_id) ? { ...row, ...payload } : row);
    else rows.push({ ...payload, category_id: nextId++, equipment_count: 0 });
    return { success: true };
  }
  vm.runInContext(app.slice(app.indexOf('function showError('), app.indexOf('/* ---------- Small formatters')), ctx);
  vm.runInContext(shared, ctx);
  vm.runInContext('let categories = []; let equipmentReady = true; async function loadEquipment() { reloadCount++; }', ctx);
  ctx.reloadCount = 0;
  vm.runInContext(equipment.slice(equipment.indexOf('async function updateCategoryOptions('), equipment.indexOf('function wireControls(')), ctx);
  vm.runInContext('CategoryManager.init(' + JSON.stringify({ role }) + ')', ctx);
  return { ctx, element, timers, notifications, execute: code => vm.runInContext(code, ctx) };
}
const flush = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  const staff = harness('staff'), admin = harness('admin'), customer = harness('customer');
  for (const client of [staff, admin, customer]) await client.execute('CategoryStore.start(updateCategoryOptions)');
  assert.equal(staff.element('addCategoryBtn').hidden, false);
  assert.equal(admin.element('addCategoryBtn').hidden, false);
  assert.equal(customer.element('addCategoryBtn').hidden, true);
  assert.equal(customer.element('categoryList').listeners.click, undefined);
  assert.match(customer.element('categoryFilter').innerHTML, /Audio Visual/);
  assert.equal(streams.length, 3);
  const submit = client => client.element('categoryForm').listeners.submit({ preventDefault() {} });
  for (const client of [admin, staff]) {
    client.element('addCategoryBtn').listeners.click();
    assert.equal(client.element('categorySubmit').disabled, true);
    for (const name of ['', ' ', '---', '<b>Tools</b>', 'Tools\0', 'x'.repeat(101)]) {
      client.element('categoryName').value = name;
      client.element('categoryName').listeners.input();
      assert.equal(client.element('categorySubmit').disabled, true);
      const before = writes.length; await submit(client); assert.equal(writes.length, before);
    }
    client.element('categoryName').value = 'Audio Visual';
    await submit(client);
    assert.equal(client.element('categoryModal').open, true);
    assert.match(client.element('categoryName').classList.contains('invalid').toString(), /true/);
    assert.match(client.notifications.at(-1).message, /already exists/);
    client.element('categoryName').value = roleName(client) + '  Field\t  Tools  ';
    client.element('categoryName').listeners.input();
    assert.equal(client.element('categoryName').classList.contains('invalid'), false);
    await submit(client); await flush();
    assert.equal(client.element('categoryModal').open, false);
    assert.match(customer.element('categoryFilter').innerHTML, new RegExp(roleName(client) + ' Field Tools'));
    assert.match(client.element('editCategory').innerHTML, new RegExp(roleName(client) + ' Field Tools'));
  }
  function roleName(client) { return client === admin ? 'Admin' : 'Staff'; }
  for (const client of [staff, admin, customer]) client.element('categoryFilter').value = '1';
  staff.element('editCategory').value = '1';
  // Open the real edit handler and rename. The chosen category ID stays selected everywhere.
  staff.element('categoryList').listeners.click({ target: { closest: selector => selector === '[data-edit-category]' ? { dataset: { editCategory: '1' } } : null } });
  await flush();
  assert.equal(staff.element('categoryName').value, 'Audio Visual');
  staff.element('categoryName').value = 'Audio & Visual'; await submit(staff); await flush();
  for (const client of [staff, admin, customer]) {
    assert.equal(client.element('categoryFilter').value, '1');
    assert.match(client.element('categoryFilter').innerHTML, /Audio &amp; Visual/);
  }
  assert.equal(staff.element('editCategory').value, '1');
  const beforeReload = customer.ctx.reloadCount;
  rows[0].equipment_count = 1;
  for (const stream of streams) stream.listeners.categories({ data: JSON.stringify(copy()) });
  await flush();
  assert.equal(customer.ctx.reloadCount, beforeReload, 'Count-only events must not interrupt equipment rows');
  const deleteButton = { dataset: { deleteCategory: '1' }, disabled: false };
  const deleteClick = () => staff.element('categoryList').listeners.click({ target: { closest: selector => selector === '[data-delete-category]' ? deleteButton : null } });
  const beforeDelete = writes.length; deleteClick(); await flush();
  assert.equal(writes.length, beforeDelete);
  assert.match(staff.notifications.at(-1).message, /Reassign/);
  rows[0].equipment_count = 0;
  for (const stream of streams) stream.listeners.categories({ data: JSON.stringify(copy()) });
  await flush(); deleteClick(); await flush();
  for (const client of [staff, admin, customer]) {
    assert.equal(client.element('categoryFilter').value, '');
    assert.doesNotMatch(client.element('categoryFilter').innerHTML, /Audio &amp; Visual/);
  }
  // Simulate another browser/session publishing a server event with a new category.
  rows.push({ category_id: 99, category_name: 'Remote Cameras', description: '', equipment_count: 0 });
  for (const stream of streams) stream.listeners.categories({ data: JSON.stringify(copy()) });
  await flush();
  for (const client of [staff, admin, customer]) assert.match(client.element('categoryFilter').innerHTML, /Remote Cameras/);
  await staff.execute('CategoryStore.refresh()');
  const occurrences = staff.element('categoryFilter').innerHTML.match(/Remote Cameras/g);
  assert.equal(occurrences.length, 1, 'Refresh must replace options instead of appending duplicates');
  rows.push({ category_id: 100, category_name: 'Polling Tools', description: '', equipment_count: 0 });
  await customer.timers[0](); await flush();
  assert.match(customer.element('categoryFilter').innerHTML, /Polling Tools/);
  assert.ok(loads === 0);
  console.log('PASS: admin/staff controls, customer read-only view, validation/error recovery, create/rename/delete, assignment guards, preserved selections, instant cross-tab updates, remote events, polling fallback, and no duplicate dropdown options.');
})().catch(error => { console.error(error); process.exitCode = 1; });
