const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const sidebarSource = fs.readFileSync(path.join(__dirname, '../assets/js/sidebar.js'), 'utf8');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
function harness(role, page) {
  const nodes = new Map();
  const node = selector => {
    if (!nodes.has(selector)) nodes.set(selector, {innerHTML: '', dataset: {}, parentElement: {innerHTML: ''}, classList: {add() {}}, addEventListener() {}, setAttribute() {}, querySelectorAll: () => []});
    return nodes.get(selector);
  };
  const calls = [];
  const context = vm.createContext({
    location: {pathname: '/' + page},
    window: {addEventListener() {}},
    document: {querySelector: node, querySelectorAll: () => [], getElementById: node},
    Api: {me: async () => ({data: {role, name: 'Fixture', user_id: 1}}),
      listNotifications: async () => {calls.push('notifications'); return {data: []};},
      dashboard: async () => {calls.push('dashboard'); return {data: {role, today: '2026-10-10', timezone: 'Asia/Manila', summary: {}, borrowings: [], pagination: {page: 1, pages: 1, total: 0}, monthly: [], most_requested: [], recent_activity: []}};}},
    console,
  });
  vm.runInContext(sidebarSource, context);
  vm.runInContext(source, context);
  vm.runInContext('BorrowingCart.initialize = async () => {}; BorrowingCart.read = () => [];', context);
  return {context, node, calls, run: code => vm.runInContext(code, context)};
}
const navLinks = markup => [...markup.matchAll(/<a class="nav-item[^"\n]*" href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/g)];
const staffDestinations = ['dashboard.html', 'requests.html'];
(async () => {
  for (const page of ['equipment.php', 'equipment.html', 'admin.html', 'cart.html', 'notifications.html']) {
    const staff = harness('staff', page);
    await assert.rejects(staff.run('requireSession()'), /role not permitted/);
    assert.match(staff.node('.main').innerHTML, /isn't available/);
    const nav = staff.node('.rail').innerHTML;
    assert.deepEqual(navLinks(nav).map(match => match[1]), staffDestinations);
    assert.doesNotMatch(nav, /href="(?:equipment|admin|cart|notifications)/);
    assert.deepEqual(staff.calls, [], 'Staff shell must not call customer notifications');
  }
  for (const page of ['dashboard.html', 'requests.html', 'profile.php']) {
    const staff = harness('staff', page);
    assert.equal((await staff.run('requireSession()')).role, 'staff');
    assert.equal(staff.run("NAV_LINKS.filter(link => link.roles.includes('staff')).length"), 2);
    for (const selector of ['.rail', '.rail-canvas .offcanvas-body']) {
      const markup = staff.node(selector).innerHTML;
      const links = navLinks(markup);
      assert.deepEqual(links.map(match => match[1]), staffDestinations, page + ': ' + selector);
      for (const link of links) assert.equal((link[2].match(/<svg\b/g) || []).length, 1, 'Each navigation item must have exactly one icon');
      const current = links.filter(link => /class="nav-item active"/.test(link[0]));
      assert.equal(current.length, page === 'profile.php' ? 0 : 1);
      if (current.length) {assert.equal(current[0][1], page); assert.match(current[0][0], /aria-current="page"/);}
      assert.match(markup, /Fixture/);
      assert.match(markup, /data-sign-out/);
      assert.match(markup, /Sign out/);
      const account = markup.match(/<a class="sidebar-profile-link account-link" href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/);
      assert.ok(account, page + ': ' + selector + ' must contain a clickable account');
      assert.match(account[2], /<span class="sidebar-user-name(?: who)?">Fixture<\/span>/);
      assert.match(account[2], /<span class="sidebar-user-role">Staff<\/span>/);
      const destination = harness('staff', account[1]);
      assert.equal((await destination.run("requireSession(['admin', 'staff', 'customer'])")).role, 'staff',
        page + ': ' + selector + ' account destination must allow Staff');
    }
    assert.equal(staff.node('.rail').innerHTML, staff.node('.rail-canvas .offcanvas-body').innerHTML);
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
    const active = navLinks(nav).filter(link => /class="nav-item active"/.test(link[0]));
    assert.equal(active.length, 1);
    assert.equal(active[0][1], 'equipment.html', 'The PHP catalog must activate the public equipment URL');
    assert.match(active[0][0], /aria-current="page"/);
    if (role === 'admin') assert.match(nav, /People &amp; Categories/);
    else {assert.match(nav, /Borrowing Cart/); assert.match(nav, /My Borrowings/); assert.match(nav, /Notifications/);}
  }
  const roleDestinations = {
    admin: ['dashboard.html', 'equipment.html', 'requests.html', 'admin.html'],
    staff: staffDestinations,
    customer: ['dashboard.html', 'equipment.html', 'cart.html', 'my-borrowings.html', 'notifications.html'],
  };
  for (const [role, destinations] of Object.entries(roleDestinations)) {
    for (const page of destinations) {
      const client = harness(role, page);
      const markup = client.run('EquipmentSidebar.markup({role: ' + JSON.stringify(role) + ', name: "Fixture"}, {page: ' + JSON.stringify(page) + ', cartCount: 7, unreadCount: 2})');
      const links = navLinks(markup);
      assert.deepEqual(links.map(link => link[1]), destinations, role + ': ' + page);
      const active = links.filter(link => /class="nav-item active"/.test(link[0]));
      assert.equal(active.length, 1, role + ': ' + page + ' must have one current navigation item');
      assert.equal(active[0][1], page);
      assert.match(active[0][0], /aria-current="page"/);
      for (const link of links) assert.equal((link[2].match(/<svg\b/g) || []).length, 1);
      if (role === 'customer') {
        assert.match(markup, /data-cart-count[^>]*aria-live="polite"[^>]*>7<\/span>/);
        assert.match(markup, /data-sidebar-unread[^>]*aria-label="2 unread notifications"/);
        assert.doesNotMatch(markup, /data-sidebar-unread[^>]* hidden/);
        const noUnread = client.run('EquipmentSidebar.markup({role: "customer", name: "Fixture"}, {unreadCount: 0})');
        assert.match(noUnread, /data-sidebar-unread[^>]* hidden/);
      }
    }
  }
  for (const file of ['dashboard.html', 'admin.html', 'cart.html', 'equipment.php', 'profile.php']) {
    const html = fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
    assert.ok(html.includes('src="assets/js/sidebar.js?v=sidebar-v3"'), file + ' must load the shared sidebar renderer');
    assert.ok(html.includes('href="assets/css/sidebar.css?v=sidebar-v3"'), file + ' must load the shared sidebar styles');
    assert.ok(html.includes('src="assets/js/app.js?v=sidebar-v1"'), file + ' must refresh shared navigation');
    assert.ok(html.indexOf('src="assets/js/sidebar.js') < html.indexOf('src="assets/js/app.js'), file + ' must load the sidebar before app.js');
  }
  for (const file of ['requests.html', 'my-borrowings.html', 'notifications.html']) {
    const html = fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
    assert.ok(html.includes('src="assets/js/sidebar.js?v=sidebar-v3"'), file + ' must load the shared sidebar renderer');
    assert.ok(html.includes('href="assets/css/sidebar.css?v=sidebar-v3"'), file + ' must load the shared sidebar styles');
  }
  console.log('PASS: desktop/mobile staff menus use the shared two-item navigation with one icon per item and correct current links; protected routes stay blocked; accounts remain accessible; catalog aliases activate Equipment; all signed-in views load shared sidebar assets.');
})().catch(error => {console.error(error); process.exitCode = 1;});
