const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
function harness(role, page) {
  const nodes = new Map();
  const node = selector => {
    if (!nodes.has(selector)) nodes.set(selector, {innerHTML: '', parentElement: {innerHTML: ''}, addEventListener() {}});
    return nodes.get(selector);
  };
  const calls = [];
  const context = vm.createContext({
    location: {pathname: '/' + page},
    window: {addEventListener() {}},
    document: {querySelector: node, querySelectorAll: () => [], getElementById: node},
    Api: {me: async () => ({data: {role, name: 'Fixture', user_id: 1}}),
      listNotifications: async () => {calls.push('notifications'); return {data: []};},
      dashboard: async () => {calls.push('dashboard'); return {data: {summary: {}, monthly: [], most_requested: [], recent_activity: []}};}},
    console,
  });
  vm.runInContext(source, context);
  vm.runInContext('BorrowingCart.initialize = async () => {}; BorrowingCart.read = () => [];', context);
  return {context, node, calls, run: code => vm.runInContext(code, context)};
}
(async () => {
  for (const page of ['equipment.php', 'equipment.html', 'admin.html', 'cart.html', 'notifications.html']) {
    const staff = harness('staff', page);
    await assert.rejects(staff.run('requireSession()'), /role not permitted/);
    assert.match(staff.node('.main').innerHTML, /isn't available/);
    const nav = staff.node('.rail').innerHTML;
    assert.match(nav, /Overview/);
    assert.match(nav, /Borrowing Management/);
    assert.doesNotMatch(nav, /href="(?:equipment|admin|cart|notifications)/);
    assert.deepEqual(staff.calls, [], 'Staff shell must not call customer notifications');
  }
  for (const page of ['dashboard.html', 'requests.html']) {
    const staff = harness('staff', page);
    assert.equal((await staff.run('requireSession()')).role, 'staff');
    assert.equal(staff.run("NAV_LINKS.filter(link => link.roles.includes('staff')).length"), 2);
    for (const selector of ['.rail', '.rail-canvas .offcanvas-body']) {
      const markup = staff.node(selector).innerHTML;
      const links = [...markup.matchAll(/class="nav-item[^"\n]*" href="([^"]+)"/g)].map(match => match[1]);
      assert.deepEqual(links, ['dashboard.html', 'requests.html'], page + ': ' + selector);
      assert.doesNotMatch(markup, /href="equipment\.(?:html|php)"|<span>Equipment<\/span>/);
      assert.match(markup, /Fixture/);
      assert.match(markup, /Sign out/);
    }
    if (page === 'dashboard.html') {
      const dashboard = fs.readFileSync(path.join(__dirname, '../assets/js/dashboard.js'), 'utf8');
      staff.run(dashboard.replace('(async function ()', 'const overviewReady = (async function ()'));
      await staff.run('overviewReady');
      assert.match(staff.node('dashContent').innerHTML, /Requests per month/);
      assert.doesNotMatch(staff.node('dashContent').innerHTML, /href="equipment\.(?:html|php)"/);
      assert.deepEqual(staff.calls, ['dashboard']);
    }
  }
  for (const role of ['admin', 'customer']) {
    const client = harness(role, 'equipment.php');
    assert.equal((await client.run("requireSession(['admin', 'customer'])")).role, role);
    const nav = client.node('.rail').innerHTML;
    assert.match(nav, /href="equipment.php"/);
    assert.match(nav, /nav-item active/);
    if (role === 'admin') assert.match(nav, /People &amp; categories/);
    else {assert.match(nav, /Borrowing Cart/); assert.match(nav, /My Borrowings/); assert.match(nav, /Notifications/);}
  }
  for (const file of ['index.html', 'dashboard.html', 'requests.html', 'equipment.php', 'admin.html', 'cart.html', 'notifications.html']) {
    const html = fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
    assert.match(html, /src="assets\/js\/app\.js\?v=staff-navigation-v3"/, file + ' must load the updated navigation script');
  }
  console.log('PASS: desktop/mobile staff menus contain only Overview and Borrowing Management, overview has no equipment links, navigation cache versions are updated, forbidden routes stay blocked, and admin/customer equipment navigation works.');
})().catch(error => {console.error(error); process.exitCode = 1;});
