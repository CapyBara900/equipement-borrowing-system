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
      id, type: 'date', min: '', max: '', disabled: false, textContent: '', listeners: {},
      get value() { return value; }, set value(v) { value = String(v); },
      classList: { add: v => classes.add(v), remove: v => classes.delete(v), contains: v => classes.has(v) },
      setAttribute() {}, removeAttribute() {}, focus() {},
      addEventListener(event, callback) { this.listeners[event] = callback; },
    });
  }
  return elements.get(id);
}
let window = { min_date: '2026-10-08', max_date: '2026-10-15', timezone: 'Asia/Manila' };
const submitted = [];
const context = vm.createContext({
  document: { getElementById: element, querySelector: selector => element(selector) },
  bootstrap: { Modal: { getOrCreateInstance: () => ({ show() {}, hide() {} }) } },
  Api: { getPickupWindow: async () => ({ data: { ...window } }), createRequest: async payload => submitted.push(payload) },
  toast() {}, Number, String, Date,
});
const app = fs.readFileSync(path.join(root, 'assets/js/app.js'), 'utf8');
vm.runInContext(app.slice(app.indexOf('function showError('), app.indexOf('/* ---------- Small formatters')), context);
const source = fs.readFileSync(path.join(root, 'assets/js/equipment.js'), 'utf8');
vm.runInContext(source.slice(source.indexOf('const borrowModal ='), source.indexOf('/* ---------- Admin:')) + '\nasync function loadEquipment() {}', context);
(async () => {
  const pickup = element('borrowDate');
  const returned = element('expectedReturnDate');
  for (const browserTimezone of ['UTC', 'America/Los_Angeles', 'Asia/Tokyo']) {
    process.env.TZ = browserTimezone;
    await context.openBorrow(42, 'Test equipment', 10, 5);
    assert.equal(pickup.value, '2026-10-08');
    assert.equal(pickup.min, '2026-10-08');
    assert.equal(pickup.max, '2026-10-15');
    assert.equal(pickup.disabled, false);
    assert.equal(returned.max, '2026-10-13');
    for (let offset = -1; offset <= 8; offset++) {
      pickup.value = context.calendarDateAfter('2026-10-08', offset);
      returned.value = context.calendarDateAfter(pickup.value, 5);
      pickup.listeners.input();
      const before = submitted.length;
      await element('borrowForm').listeners.submit({ preventDefault() {} });
      const valid = offset >= 0 && offset <= 7;
      assert.equal(submitted.length - before, valid ? 1 : 0);
      assert.equal(pickup.classList.contains('invalid'), !valid);
    }
    assert.equal(context.calendarDateAfter('2026-12-30', 7), '2027-01-06');
    assert.equal(context.calendarDateAfter('2028-02-25', 7), '2028-03-03');
  }
  await context.openBorrow(42, 'Test equipment', 10, 2);
  assert.equal(returned.max, '2026-10-10', 'Equipment-specific return limit is applied');
  window = { ...window, min_date: '2026-10-09', max_date: '2026-10-16' };
  const before = submitted.length;
  await element('borrowForm').listeners.submit({ preventDefault() {} });
  assert.equal(submitted.length, before, 'Overnight stale date rejected after server refresh');
  assert.equal(pickup.min, '2026-10-09');
  assert.equal(pickup.classList.contains('invalid'), true);
  assert.equal(element('borrowSubmit').disabled, true);
  console.log('PASS: pickup default, native min/max, all inclusive boundaries, error clearing, timezone independence, overnight refresh, and equipment return limits.');
})().catch(error => { console.error(error); process.exitCode = 1; });
