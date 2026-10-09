const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.join(__dirname, '..');
const elements = new Map();
const windowEvents = new Map(), documentEvents = new Map(), timers = new Map();
let timerSequence = 0;
function element(id) {
  if (!elements.has(id)) elements.set(id, {
    id, value: '', innerHTML: '', textContent: '', hidden: false, disabled: false,
    attrs: {}, listeners: {}, dataset: {}, tabIndex: 0,
    classList: {toggle() {}, add() {}, remove() {}},
    setAttribute(key, value) { this.attrs[key] = value; },
    addEventListener(name, callback) { this.listeners[name] = callback; },
    focus() { this.focused = true; }, scrollIntoView() {},
  });
  return elements.get(id);
}
const tabs = ['', 'pending', 'approved', 'borrowed', 'returned', 'rejected', 'cancelled'].map(status => {
  const tab = element('tab-' + (status || 'all')); tab.dataset.status = status; return tab;
});
let backendRows = [], apiCalls = [], cancelCalls = [], showCount = 0, confirmation = true;
const transactionFor = row => ({transaction_id:'request:'+row.request_id,status:row.status,statuses:[row.status],items:[row]});
const context = vm.createContext({
  console, Date, Map, Set,
  window: {addEventListener(name,handler) {windowEvents.set(name,handler);}}, location: {pathname: "/requests.html"},
  setInterval(fn,ms) {const id=++timerSequence;timers.set(id,{fn,ms});return id;},
  clearInterval(id) {timers.delete(id);},
  localStorage: {getItem() {return null;}},
  document: {getElementById: element, querySelectorAll: selector => selector === '[data-status]' ? tabs : [], title: '', hidden:false, addEventListener(name,handler){documentEvents.set(name,handler);}},
  confirm: () => confirmation,
  bootstrap: {Modal: {getOrCreateInstance: () => ({show() {showCount++;}})}},
  Api: {
    async listRequests(query) { assert.equal(query.view,'transactions');apiCalls.push(query); return {data: backendRows.slice((query.page - 1) * query.limit, query.page * query.limit).map(transactionFor)}; },
    async cancelRequest(id) { cancelCalls.push(String(id)); backendRows = backendRows.map(row => String(row.request_id) === String(id) ? {...row, status: 'cancelled'} : row); },
  },
});
const run = code => vm.runInContext(code, context);
const shared = fs.readFileSync(path.join(root, 'assets/js/app.js'), 'utf8');
run(shared + '\nCURRENT_USER={user_id:1,role:"customer"};toast=()=>{};');
const source = fs.readFileSync(path.join(root, 'assets/js/requests.js'), 'utf8');
run(source.slice(0, source.indexOf('(async function initRequests()')) + source.slice(source.indexOf('function wireCustomerRefresh(')));
run('user={user_id:1,role:"customer",name:"Customer"};wireFilters();');
const fixture = (id, fields = {}) => ({request_id:id, user_id:1, user_name:'Customer', equipment_id:10, equipment_name:'Camera <kit>', checkout_id:null, requested_quantity:2, request_date:'2026-10-09 10:00:00', borrow_date:'2026-10-10', expected_return_date:'2026-10-12', status:'pending', ...fields});
backendRows = [fixture(1, {checkout_id:77}), fixture(2, {status:'approved'}), ...Array.from({length:48}, (_, i) => fixture(i + 10, {status:'rejected'})), fixture(60,{status:'borrowed'}), fixture(3, {checkout_id:77, status:'returned', requested_quantity:1, borrow_date:'2026-10-11'}), fixture(4, {status:'cancelled'}), fixture(5, {user_id:99, equipment_name:'Other customer'})];
(async () => {
  await run('load()');
  assert.equal(apiCalls.length, 2, 'History must load beyond the first 50 API records');
  assert.equal(run('borrowingRows.length'), 53, 'Customer data must exclude other owners');
  assert.equal(run('transactions.length'), 53, 'Every customer request has its own transaction');
  assert.equal(run('transactions.filter(t=>t.checkoutId===77).length'), 2, 'Checkout references survive independent status grouping across API pages');
  assert.equal(run('transactions.every(t=>t.items.length===1 && t.status===t.items[0].status)'),true);
  context.repeatedRows = [fixture(91, {checkout_id:101}), fixture(92, {checkout_id:102}), fixture(93), fixture(94), fixture(91, {checkout_id:101})];
  assert.equal(run('groupBorrowings(repeatedRows).length'), 4, 'Same equipment from separate checkouts and requests stays separate; duplicate API rows are ignored');
  assert.equal(element('requestList').attrs['aria-busy'], 'false');
  assert.match(element('requestList').innerHTML, /Checkout #77/);
  assert.match(element('requestList').innerHTML, /Camera &lt;kit&gt;/);
  assert.doesNotMatch(element('requestList').innerHTML, /Other customer|<img/);
  assert.equal(element('pageLabel').textContent, 'Page 1 of 6');
  assert.equal(element('nextPage').disabled, false);
  assert.match(element('requestList').innerHTML, /Quantity requested|Pick Up On|Return By|View Details/);
  element('borrowingTabs').listeners.keydown({key:'End', preventDefault(){}, target:{closest:()=>tabs[0]}});
  assert.equal(run('activeStatus'), 'cancelled', 'Keyboard navigation selects the final tab');
  assert.equal(tabs[6].focused, true);
  element('clearFilters').listeners.click();
  assert.equal(run('activeStatus'), '', 'Clear filters restores All');
  for(const status of ['pending','approved','borrowed','returned','rejected','cancelled']) {
    run(`setBorrowingStatus('${status}')`);
    const expected=backendRows.filter(row=>row.user_id===1 && row.status===status).length;
    assert.equal(run('filteredBorrowings().length'),expected);
    assert.equal(run(`filteredBorrowings().every(t=>t.status==='${status}')`),true);
    assert.equal(element('borrowingCount').textContent,expected+' '+status+' '+(expected===1?'transaction':'transactions'));
    assert.doesNotMatch(element('requestList').innerHTML,new RegExp('p-('+['pending','approved','borrowed','returned','rejected','cancelled'].filter(other=>other!==status).join('|')+')'));
  }
  run('setBorrowingStatus("")');
  assert.equal(run('filteredBorrowings().length'),53);
  assert.equal(run('new Set(transactions.map(t=>t.key)).size'),53);
  for(const row of backendRows.filter(row=>row.user_id===1)) {
    context.checkedId=row.request_id;
    assert.equal((run('transactionMarkup(transactions.find(t=>t.key==="request:"+checkedId))').match(/class="pill /g)||[]).length,1,'Each customer card has one current status badge');
  }
  const calls = apiCalls.length;
  run('setBorrowingStatus("approved")');
  assert.equal(apiCalls.length, calls, 'Tab changes use cached history without additional API requests');
  assert.equal(run('filteredBorrowings().length'), 1);
  assert.equal(tabs[2].attrs['aria-selected'], 'true');
  assert.equal(element('requestList').attrs['aria-labelledby'], 'tab-approved');
  run('setBorrowingStatus("borrowed")');
  assert.equal(run('filteredBorrowings().length'), 1, 'Only the actually Borrowed request is included');
  assert.equal(run('filteredBorrowings()[0].key'),'request:60');
  run('setBorrowingStatus("returned")');
  assert.equal(run('filteredBorrowings()[0].items.length'), 1, 'Returned tab excludes pending checkout siblings');
  assert.doesNotMatch(element('requestList').innerHTML, /p-pending/);
  assert.match(element('requestList').innerHTML, /p-returned/);
  assert.doesNotMatch(element('requestList').innerHTML, /data-cancel="3"/);
  run('showBorrowingDetails("request:1")');
  assert.equal(showCount, 1);
  assert.match(element('borrowingDetailsBody').innerHTML, /Request reference|Equipment ID|Created on/);
  assert.match(element('borrowingDetailsBody').innerHTML, /#1|Checkout #77/);
  assert.doesNotMatch(element('borrowingDetailsBody').innerHTML,/Request reference<\/dt><dd class="refid">#3/);
  assert.doesNotMatch(element('borrowingDetailsBody').innerHTML, /<img/);
  await run('cancel("2")');
  assert.equal(cancelCalls.length, 0, 'Approved records cannot be cancelled');
  confirmation = false; await run('cancel("1")'); confirmation = true;
  assert.equal(cancelCalls.length, 0, 'Declining confirmation preserves the request');
  const cancelApi = context.Api.cancelRequest;
  context.Api.cancelRequest = async () => { throw Error('Cancellation failed'); };
  await run('cancel("1")');
  assert.equal(run('borrowingRows.find(r=>r.request_id===1).status'), 'pending', 'Failed cancellation cannot change status');
  context.Api.cancelRequest = cancelApi;
  run('setBorrowingStatus("pending")');
  await run('cancel("1")');
  assert.deepEqual(cancelCalls, ['1']);
  assert.equal(run('borrowingRows.find(r=>r.request_id===1).status'), 'cancelled', 'Cancellation reloads the backend status');
  assert.equal(run('filteredBorrowings().length'),0,'Cancellation removes the transaction from Pending immediately');
  assert.equal(element('borrowingCount').textContent,'0 pending transactions');
  run('setBorrowingStatus("cancelled")');
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:1")'),true);
  assert.equal(element('borrowingCount').textContent,'2 cancelled transactions');
  assert.match(element('borrowingDetailsBody').innerHTML, /p-cancelled/);
  assert.doesNotMatch(element('borrowingDetailsBody').innerHTML, /data-cancel="1"/);
  run('setBorrowingStatus("")'); element('dateFrom').value = '2026-10-10'; await run('load()');
  assert.equal(run('filteredBorrowings().length'), 0, 'Creation date filters remain available');
  element('dateFrom').value = ''; element('dateTo').value = '2026-10-09'; await run('load()');
  assert.equal(run('filteredBorrowings().length'), 53, 'Date-to includes the entire day');
  element('dateTo').value = '';
  const listApi = context.Api.listRequests;
  context.Api.listRequests = async () => { throw Error('Connection lost'); };
  await run('load(true)');
  assert.match(element('requestList').innerHTML, /Connection lost|Try again/);
  assert.equal(element('pager').hidden, true);
  assert.equal(element('refreshBorrowings').disabled, false);
  assert.equal(run('borrowingRows'), null, 'Retry cannot fall back to stale history');
  context.Api.listRequests = listApi;
  await run('load(true)');
  assert.equal(run('transactions.length'), 53);
  // Admin and staff changes are observed through quiet background reads, preserving selection and open details.
  backendRows.push(fixture(70,{checkout_id:77,status_history:[]}));
  await run('load(true)');
  run('wireCustomerRefresh();setBorrowingStatus("pending");showBorrowingDetails("request:70")');
  assert.equal(timers.size,1);
  const poll=[...timers.values()][0];assert.equal(poll.ms,15000);
  backendRows=backendRows.map(row=>row.request_id===70?{...row,status:'approved',status_history:[{from_status:'pending',to_status:'approved',changed_by_user_id:9}]}:row);
  await poll.fn();
  assert.equal(run('activeStatus'),'pending');
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:70")'),false,'Admin approval automatically removes the request from Pending');
  assert.equal(element('borrowingCount').textContent,'0 pending transactions');
  assert.match(element('borrowingDetailsBody').innerHTML,/p-approved/);
  assert.equal(run('savedRequest(70).status_history.length'),1);
  run('setBorrowingStatus("approved")');
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:70")'),true);
  backendRows=backendRows.map(row=>row.request_id===70?{...row,status:'borrowed',status_history:[...row.status_history,{from_status:'approved',to_status:'borrowed',changed_by_user_id:10}]}:row);
  await windowEvents.get('focus')();
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:70")'),false,'Staff pickup removes the request from Approved when the page regains focus');
  run('setBorrowingStatus("borrowed")');
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:70")'),true);
  context.document.hidden=true;
  const hiddenCalls=apiCalls.length;await poll.fn();assert.equal(apiCalls.length,hiddenCalls,'Hidden pages do not poll');
  context.document.hidden=false;
  backendRows=backendRows.map(row=>row.request_id===70?{...row,status:'returned',status_history:[...row.status_history,{from_status:'borrowed',to_status:'returned',changed_by_user_id:10}]}:row);
  documentEvents.get('visibilitychange')();
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:70")'),false,'Staff return updates the customer tab after visibility resumes');
  run('setBorrowingStatus("returned")');
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:70")'),true);
  assert.equal(run('savedRequest(70).status_history.length'),3,'Automatic updates preserve complete history');
  const stableMarkup=element('requestList').innerHTML;
  context.Api.listRequests=async()=>{throw Error('Temporary connection loss');};
  await poll.fn();
  assert.equal(element('requestList').innerHTML,stableMarkup,'Background failures preserve the last saved view and retry later');
  context.Api.listRequests=listApi;
  let backgroundRelease;
  context.Api.listRequests=()=>new Promise(resolve=>{backgroundRelease=resolve;});
  const backgroundLoad=run('load(true,true)');
  assert.equal(element('requestList').innerHTML,stableMarkup,'Background reads do not clear the existing view');
  assert.equal(await run('load(true,true)'),false,'Do not overlap background requests');
  backgroundRelease({data:[transactionFor(fixture(70,{status:'returned'}))]});await backgroundLoad;
  context.Api.listRequests=listApi;
  await poll.fn();
  assert.equal(run('new Set(transactions.map(t=>t.key)).size'),54,'Status refresh never duplicates customer transactions');
  windowEvents.get('pagehide')();assert.equal(timers.size,0,'Leaving the page stops background checks');
  windowEvents.get('pageshow')();await new Promise(resolve=>setImmediate(resolve));assert.equal(timers.size,1,'Returning from browser cache resumes status checks');
  windowEvents.get('pagehide')();
  let release;
  context.Api.listRequests = () => new Promise(resolve => { release = resolve; });
  const oldLoad = run('load(true)');
  assert.equal(run('borrowingRows'), null, 'Refresh invalidates cached rows so tab changes cannot retain stale loading state');
  context.Api.listRequests = async () => ({data: [transactionFor(fixture(80, {status:'borrowed'}))]});
  await run('load(true)'); release({data: [transactionFor(fixture(81))]}); await oldLoad;
  assert.equal(run('borrowingRows[0].request_id'), 80, 'Older responses cannot overwrite refreshed history');
  run('setBorrowingStatus("borrowed")');
  assert.equal(run('filteredBorrowings().length'), 1, 'Actual backend Borrowed status is displayed when supplied');
  const customerNav = run('navMarkup(user,0)');
  assert.match(customerNav, /My Borrowings/); assert.doesNotMatch(customerNav, />Requests</);
  run('user={user_id:7,role:"staff"};');
  assert.match(run('navMarkup(user,0)'), />Borrowing Management</);
  context.Api.borrowingCapabilities=async()=>({data:{pickup_available:true}});
  context.Api.getPickupWindow=async()=>({data:{min_date:'2026-10-10'}});
  context.Api.listRequests=async query=>{apiCalls.push(query);return {data:[{transaction_id:'request:99',status:'pending',items:[fixture(99)]}]};};
  await run('load(true)');
  run('setBorrowingStatus("pending")');
  assert.equal(run('activeStatus'),'pending');
  assert.match(element('requestList').innerHTML,/data-approve="99"|data-reject="99"/,'Staff queue actions must remain available');
  const html=fs.readFileSync(path.join(root,'requests.html'),'utf8');
  assert.equal((html.match(/role="tab"/g)||[]).length,7);
  assert.doesNotMatch(html,/<img|borrowForm/);
  console.log('PASS: customer request transactions, all six status tabs, one current badge, ownership, accurate counts, automatic admin/staff updates, retained history, cancellation, quiet refresh/retry, stale-response protection, and staff queue.');
})().catch(error=>{console.error(error);process.exitCode=1;});
