const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/dashboard.js'), 'utf8');
const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function fixture(role = 'customer') {
  return {role, today: '2026-10-10', timezone: 'Asia/Manila', summary: {pending: 65, approved: 2, borrowed: 3, completed: 9, rejected: 1, overdue: 1, due_today: 1, next_due_date: '2026-10-16', total_units: 100, borrowed_units: 20, utilization: 20},
    pagination: {page: 1, pages: 9, total: 65}, monthly: [{month: '2026-10', total: 65}], recent_activity: [],
    borrowings: [{request_id: 1, user_id: 1, equipment_name: '<img src=x onerror=alert(1)>', user_name: 'Fixture Person', status: 'borrowed', requested_quantity: 2, expected_return_date: '2026-10-08', borrow_date: '2026-10-01', request_date: '2026-09-30'}]};
}
function harness(role) {
  const nodes = new Map(), calls = [];
  const node = id => {
    if (!nodes.has(id)) nodes.set(id, {innerHTML: '', textContent: '', disabled: false, attributes: {}, listeners: {}, setAttribute(k,v) {this.attributes[k]=v;}, addEventListener(k,v) {this.listeners[k]=v;}, querySelectorAll: () => [], focus() {}, showModal() {this.open=true;}});
    return nodes.get(id);
  };
  const context = vm.createContext({console, Date, Intl, Map, esc: escape,
    document: {getElementById: node, querySelector: node},
    requireSession: async () => ({name: 'Fixture Person', role, user_id: 1}),
    Api: {dashboard: async query => {calls.push(query); return {data: fixture(role)};}}});
  vm.runInContext(source.replace('(async function ()', 'const overviewReady = (async function ()'), context);
  return {context, node, calls, run: code => vm.runInContext(code, context)};
}
(async () => {
  for (const role of ['customer','staff','admin']) {
    const h = harness(role); await h.run('overviewReady');
    const html = h.node('dashContent').innerHTML;
    assert.match(html, /overview-grid/); assert.match(html, /Recent activity/); assert.match(html, /Overdue by 2 days/);
    assert.doesNotMatch(html, /<img src=x/); assert.match(html, /&lt;img/);
    assert.equal(h.node('dashContent').attributes['aria-busy'], 'false');
    assert.match(html, /data-dashboard-details="1"/);
    if (role === 'customer') {assert.match(html, /Awaiting approval/); assert.match(html, /Browse equipment/); assert.doesNotMatch(html, /Requests per month/);}
    else {assert.match(html, /Equipment utilization/); assert.match(html, /20%/); assert.match(html, /Requests per month/); assert.match(html, /Record return/); assert.doesNotMatch(html, /href="equipment/);}
    assert.equal(h.calls[0].filter, 'active');
    h.run("dashboardDetails('1')"); assert.equal(h.node('dashboardDetails').open, true); assert.match(h.node('dashboardDetailsBody').innerHTML, /requests.html\?request=1/);
    assert.equal(h.run("dashboardDue({status: 'approved', expected_return_date:'2026-10-01'}, '2026-10-10')"), null, 'Approved pickups must not become overdue loans.');
    assert.equal(h.run("dashboardDue({status: 'borrowed', expected_return_date:'2026-10-16'}, '2026-10-10').label"), 'Due in 6 days');
    assert.equal(h.run("dashboardDue({status: 'borrowed', expected_return_date:'2026-10-10'}, '2026-10-10').label"), 'Due today');
    assert.equal(h.run("dashboardDue({status: 'borrowed', expected_return_date:'2026-10-09'}, '2026-10-10').label"), 'Overdue by 1 day');
    assert.equal(h.run("dashboardCalendarDay('2026-02-30')"), null);
    h.node('dashContent').listeners.click({target: {closest: () => ({dataset: {dashboardFilter: 'pending'}})}});
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(h.calls.at(-1).filter, 'pending'); assert.match(h.node('dashContent').innerHTML, /data-dashboard-filter="pending" aria-pressed="true"/);
    h.node('dashContent').listeners.change({target: {id: 'overviewFilter', value:'returned'}});
    await new Promise(resolve => setImmediate(resolve)); assert.equal(h.calls.at(-1).filter, 'returned');
    h.run("dashboardState.data.borrowings = []; dashboardState.filter = 'active'; renderDashboard()");
    assert.match(h.node('dashContent').innerHTML, /Nothing due back right now/);
  }
  const h = harness('customer'); await h.run('overviewReady');
  const resolvers = []; h.context.Api.dashboard = () => new Promise(resolve => resolvers.push(resolve));
  const first = h.run("dashboardState.filter = 'pending'; loadDashboard()");
  const second = h.run("dashboardState.filter = 'returned'; loadDashboard()");
  resolvers[1]({data: {...fixture(), borrowings: [], pagination:{page:1,pages:1,total:0}}}); await second;
  const latest = h.node('dashContent').innerHTML;
  resolvers[0]({data: fixture()}); await first; assert.equal(h.node('dashContent').innerHTML, latest, 'A late previous filter response must not replace the latest result.');
  h.context.Api.dashboard = async () => {throw new Error('Connection interrupted');};
  await h.run('loadDashboard()'); assert.equal(h.node('dashboardStatus').textContent, 'Connection interrupted'); assert.match(h.node('overviewList').innerHTML, /data-dashboard-retry/); assert.equal(h.node('refreshDashboard').disabled, false);
  h.context.Api.dashboard = async () => ({data: fixture()}); await h.run('loadDashboard()'); assert.match(h.node('dashContent').innerHTML, /overview-borrowing/);
  const requestsSource = fs.readFileSync(path.join(__dirname, '../assets/js/requests.js'), 'utf8');
  const deepLinkSource = requestsSource.slice(requestsSource.indexOf('function openDashboardBorrowing()'), requestsSource.indexOf('function wireCustomerRefresh()'));
  const linked = vm.createContext({URLSearchParams, window: {location: {search: '?request=23'}}, borrowingRows: [], transactions: Array.from({length:25}, (_,i)=>({key:'request:'+(i+1),items:[{request_id:i+1}]})), PAGE_SIZE:10, page:1, rendered:0, opened:null});
  linked.renderBorrowings = () => {linked.rendered++;}; linked.showBorrowingDetails = key => {linked.opened=key;};
  vm.runInContext(deepLinkSource, linked); vm.runInContext('openDashboardBorrowing()', linked);
  assert.equal(linked.page,3); assert.equal(linked.opened,'request:23');
  linked.opened=null; linked.window.location.search='?request=999'; vm.runInContext('openDashboardBorrowing()', linked);
  assert.equal(linked.opened,null,'A request absent from authorized history must not open.');
  linked.window.location.search='?request=23%22%3E'; vm.runInContext('openDashboardBorrowing()', linked); assert.equal(linked.opened,null);
  console.log('PASS: all role layouts, status filters, server totals, relative calendar dates, approved/pickup distinction, escaped content, details links, empty states, stale-response protection, failure and retry.');
})().catch(error => {console.error(error); process.exitCode=1;});
