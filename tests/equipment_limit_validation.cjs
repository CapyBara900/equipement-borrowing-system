const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.join(__dirname, '..');
const elements = new Map();
function element(id) {
  if (!elements.has(id)) {
    const classes = new Set();
    let value = '';
    elements.set(id, {
      id, type: id === 'editBorrowingLimit' ? 'number' : 'text',
      get value() { return value; }, set value(v) { value = String(v); },
      textContent: '', disabled: false, listeners: {},
      classList: { add: v => classes.add(v), remove: v => classes.delete(v), contains: v => classes.has(v) },
      setAttribute() {}, removeAttribute() {}, focus() {},
      addEventListener(event, callback) { this.listeners[event] = callback; },
    });
  }
  return elements.get(id);
}
let stored = { equipment_id: 42, equipment_name: 'Test equipment', total_quantity: 3, available_quantity: 2, borrowing_time_limit_days: 7 };
let submitted = [];
element('editReleaseQuantity').value = '0';
const context = vm.createContext({
  document: { getElementById: element, querySelector: selector => element(selector) },
  bootstrap: { Modal: { getOrCreateInstance: () => ({ show() {}, hide() {} }) } },
  Api: {
    getEquipment: async () => ({ data: { ...stored } }),
    updateEquipment: async payload => { submitted.push(payload); stored = { ...stored, ...payload }; },
  },
  CategoryStore: { refresh: async () => {} },
  toast() {}, Number, String, Date,
});
const app = fs.readFileSync(path.join(root, 'assets/js/app.js'), 'utf8');
vm.runInContext(app.slice(app.indexOf('function showError('), app.indexOf('/* ---------- Small formatters')), context);
const equipment = fs.readFileSync(path.join(root, 'assets/js/equipment.js'), 'utf8');
const editor = equipment.slice(equipment.indexOf('const editModal ='), equipment.indexOf('async function removeEquipment('));
vm.runInContext(editor + '\nasync function loadEquipment() {}', context);
(async () => {
  for (const role of ['admin', 'staff']) {
    context.user = { role };
    await context.openEditor(42);
    const field = element('editBorrowingLimit');
    for (let days = 1; days <= 3650; days++) {
      field.value = days;
      field.listeners.input();
      assert.equal(element('editSubmit').disabled, false, role + ': valid ' + days);
    }
    for (const invalid of ['', '0', '-1', '1.5', '1.0', '3650.1', '3651', '1e2', 'abc']) {
      field.value = invalid;
      field.listeners.blur();
      field.listeners.input();
      assert.equal(field.classList.contains('invalid'), true, role + ': reject ' + invalid);
      assert.equal(element('editSubmit').disabled, true);
      const before = submitted.length;
      await element('editForm').listeners.submit({ preventDefault() {} });
      assert.equal(submitted.length, before, 'Invalid value must not submit');
    }
    for (const valid of ['1', '2', '5', '10', '3650', '0005']) {
      field.value = valid;
      field.listeners.input();
      assert.equal(field.classList.contains('invalid'), false, 'Corrected value clears error');
      assert.equal(element('editSubmit').disabled, false);
      await element('editForm').listeners.submit({ preventDefault() {} });
      assert.equal(submitted.at(-1).borrowing_time_limit_days, Number(valid));
      assert.equal(typeof submitted.at(-1).borrowing_time_limit_days, 'number');
      await context.openEditor(42);
      assert.equal(field.value, String(Number(valid)), 'Reopened editor displays saved limit');
      assert.equal(stored.available_quantity, 2);
      assert.equal(stored.total_quantity, '3');
    }
  }
  console.log('PASS: all 1–3650 values, invalid inputs, error clearing, numeric submission, and reopening for admin/staff.');
})().catch(error => { console.error(error); process.exitCode = 1; });
