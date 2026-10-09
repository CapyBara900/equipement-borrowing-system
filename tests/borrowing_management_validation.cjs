/* Isolated frontend fixtures only: no HTTP requests or database mutations. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.join(__dirname, '..');
const deskRole = process.argv[2] || 'admin';
assert.ok(['admin', 'staff'].includes(deskRole), 'Choose an authorized desk role');
const elements = new Map();
function element(id) {
  if (!elements.has(id)) {
    const classes = new Set();
    elements.set(id, {
      id, value: '', innerHTML: '', textContent: '', hidden: false, disabled: false,
      attrs: {}, listeners: {}, dataset: {}, tabIndex: 0,
      classList: {
        toggle(name, on) { if (on) classes.add(name); else classes.delete(name); },
        add(name) { classes.add(name); }, remove(name) { classes.delete(name); },
        contains(name) { return classes.has(name); },
      },
      setAttribute(key, value) { this.attrs[key] = value; },
      addEventListener(name, callback) { this.listeners[name] = callback; },
      querySelectorAll() { return []; },
      focus() {}, scrollIntoView() {},
    });
  }
  return elements.get(id);
}
const tabs = ['', 'pending', 'approved', 'borrowed', 'returned', 'rejected', 'cancelled'].map(status => {
  const tab = element('tab-' + (status || 'all')); tab.dataset.status = status; return tab;
});
const fixture = (id, fields = {}) => ({request_id:id, user_id:20, user_name:'Alex <Customer>', equipment_id:10,
  equipment_name:'Camera <kit>', checkout_id:null, requested_quantity:2, request_date:'2026-10-09 10:30:00',
  borrow_date:'2026-10-09', expected_return_date:'2026-10-12', status:'pending', ...fields});
let rows = [fixture(1,{checkout_id:77}), fixture(2,{status:'approved',user_id:21,user_name:'Jamie'}),
  fixture(3,{status:'borrowed',picked_up_at:'2026-10-09 11:00:00',picked_up_by_staff_id:9}),
  fixture(4,{status:'returned'}),fixture(5,{status:'rejected'}),fixture(6,{status:'cancelled'}),
  ...Array.from({length:45},(_,index)=>fixture(index+10,{status:'rejected'})),
  fixture(7,{checkout_id:77,status:'returned',requested_quantity:1,borrow_date:'2026-10-10'})];
let calls = [], confirmations = true, toasts = [], failAction = false;
let returns = [{request_id:4, actual_return_date:'2026-10-09 12:30:00', processed_by_name:'Desk staff',remarks:'Lens <checked>'}];
const context = vm.createContext({
  console, Date, Map, Set, window:{addEventListener(){}}, location:{pathname:'/requests.html'},
  document:{getElementById:element, querySelectorAll:selector=>selector==='[data-status]'?tabs:[],title:''},
  confirm:()=>confirmations,
  bootstrap:{Modal:{getOrCreateInstance:el=>({
    show(){el.classList.add('show');}, hide(){el.classList.remove('show');el.listeners['hidden.bs.modal']?.();},
  })}},
  Api:{
    async borrowingCapabilities(){return {data:{pickup_available:true}};},
    async getPickupWindow(){return {data:{min_date:'2026-10-09',timezone:'Asia/Manila'}};},
    async listRequests(query){calls.push(['list',query]);assert.equal(query.view,'transactions');return {data:rows.slice((query.page-1)*query.limit,query.page*query.limit).map(row=>({transaction_id:'request:'+row.request_id,status:row.status,items:[row]})),has_more:query.page*query.limit<rows.length};},
    async decideRequest(body){calls.push(['decide',body]);if(failAction)throw Error('Decision rejected by server');rows=rows.map(row=>String(row.request_id)===String(body.request_id)?{...row,status:body.status}:row);return {success:true};},
    async pickupRequest(id){calls.push(['pickup',id]);if(failAction)throw Error('Pickup rejected by server');rows=rows.map(row=>String(row.request_id)===String(id)?{...row,status:'borrowed',picked_up_at:'2026-10-09 13:00:00',picked_up_by_staff_id:9}:row);return {success:true};},
    async recordReturn(body){calls.push(['return',body]);if(failAction)throw Error('Return rejected by server');rows=rows.map(row=>String(row.request_id)===String(body.request_id)?{...row,status:'returned'}:row);return {success:true};},
    async listReturns(){calls.push(['returns']);return {data:returns};},
    async cancelRequest(){throw Error('Admin cancellation must not be called');},
  },
});
const run = code => vm.runInContext(code,context);
run(fs.readFileSync(path.join(root,'assets/js/app.js'),'utf8'));
context.toast=(message,kind)=>toasts.push({message,kind});
context.requireSession=async()=>({user_id:9,role:deskRole,name:'Desk user'});
context.clearError=()=>{};
context.validate=rules=>rules.returnRemarks.every(rule=>rule.test(element('returnRemarks').value));
const source=fs.readFileSync(path.join(root,'assets/js/requests.js'),'utf8');
run(source.replace('(async function initRequests()', 'const requestsReady = (async function initRequests()'));
(async()=>{
  await run('requestsReady');
  assert.equal(element('pageTitle').textContent,'Borrowing Management');
  assert.equal(context.document.title,'Borrowing Management — Equipment Desk');
  assert.equal(element('tab-all').textContent,'All Requests');
  assert.equal(element('borrowingTabs').hidden,false);
  assert.equal(element('borrowingToolbar').hidden,false);
  assert.equal(run('transactions.length'),52);
  assert.equal(run('transactions.filter(t=>t.checkoutId===77).length'),2,'Independent requests keep their checkout reference across API pages');
  assert.equal(run('transactions.every(t=>t.items.length===1 && t.status===t.items[0].status)'),true);
  assert.equal(run('borrowingRows.length'),52,'Administrators see all customer accounts');
  assert.match(element('requestList').innerHTML,/Alex &lt;Customer&gt;|Jamie/);
  assert.match(element('requestList').innerHTML,/Checkout #77|Quantity requested|Pick Up On|Return By|View Details/);
  assert.equal(element('requestList').attrs['aria-busy'],'false');
  for(const status of ['pending','approved','borrowed','returned','rejected','cancelled']){
    run(`setBorrowingStatus('${status}')`);
    assert.equal(run(`filteredBorrowings().every(t=>t.status==='${status}' && t.items.every(r=>r.status==='${status}'))`),true);
    assert.equal(run('filteredBorrowings().length'),rows.filter(row=>row.status===status).length);
    assert.equal(element('borrowingCount').textContent,rows.filter(row=>row.status===status).length+' '+status+' '+(rows.filter(row=>row.status===status).length===1?'transaction':'transactions'));
    assert.doesNotMatch(element('requestList').innerHTML,new RegExp('p-('+['pending','approved','borrowed','returned','rejected','cancelled'].filter(other=>other!==status).join('|')+')'));
    assert.equal(element('requestList').attrs['aria-labelledby'],'tab-'+status);
  }
  run('setBorrowingStatus("returned")');
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:7")'),true);
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:1")'),false,'Returned tab excludes a pending sibling from the same checkout');
  assert.doesNotMatch(element('requestList').innerHTML,/p-pending/);
  for(const row of rows){
    context.checkedId=row.request_id;
    const card=run('transactionMarkup(transactions.find(t=>t.key==="request:"+checkedId))');
    assert.equal((card.match(/class="pill /g)||[]).length,1,'Every card has one current status badge');
  }
  run('setBorrowingStatus("")');
  assert.equal(run('filteredBorrowings().length'),52,'All Requests includes every independent request exactly once');
  assert.equal(run('new Set(transactions.map(t=>t.key)).size'),52);
  for(const status of ['returned','rejected','cancelled']){
    context.closedRow=fixture(90,{status});
    assert.doesNotMatch(run('borrowingItemMarkup(closedRow)'),/data-approve|data-reject|data-pickup|data-return|data-cancel/);
  }
  assert.match(run('borrowingItemMarkup(savedRequest(1))'),/data-approve="1"|data-reject="1"/);
  assert.match(run('borrowingItemMarkup(savedRequest(2))'),/Mark as Borrowed/);
  assert.doesNotMatch(run('borrowingItemMarkup(savedRequest(2))'),/data-cancel|data-return|data-approve/);
  assert.match(run('borrowingItemMarkup(savedRequest(3))'),/Mark as Returned/);
  context.futureRow=fixture(80,{status:'approved',borrow_date:'2026-10-11'});
  assert.match(run('deskActionsMarkup(futureRow)'),/disabled|Pickup is available from/);
  context.expiredRow=fixture(81,{status:'approved',expected_return_date:'2026-10-08'});
  assert.match(run('pickupUnavailableReason(expiredRow)'),/return date has passed/);
  run('deskCapabilities={pickup_available:false}');
  assert.match(run('deskActionsMarkup(savedRequest(2))'),/disabled|Pickup tracking is unavailable/);
  const pickupCount=calls.filter(c=>c[0]==='pickup').length;
  await run('decide(2,"borrowed")');
  assert.equal(calls.filter(c=>c[0]==='pickup').length,pickupCount);
  run('deskCapabilities={pickup_available:true}');
  confirmations=false; await run('decide(1,"approved")'); confirmations=true;
  assert.equal(calls.filter(c=>c[0]==='decide').length,0);
  failAction=true; await run('decide(1,"approved")'); failAction=false;
  assert.equal(run('savedRequest(1).status'),'pending','Failed API action preserves stored status');
  assert.match(element('borrowingFeedback').textContent,/Decision rejected by server/);
  assert.equal(element('borrowingFeedback').attrs.role,'alert');
  assert.equal(toasts.at(-1).kind,'bad');
  run('setBorrowingStatus("pending")');
  await run('decide(1,"approved")');
  assert.equal(run('savedRequest(1).status'),'approved');
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:1")'),false,'Approval removes the request from Pending without manual refresh');
  assert.equal(element('borrowingCount').textContent,'0 pending transactions');
  run('setBorrowingStatus("approved")');
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:1")'),true);
  assert.equal(element('borrowingFeedback').textContent,'Request approved.');
  assert.equal(calls.at(-1)[0],'list','Success fetches authoritative history');
  await run('decide(2,"borrowed")');
  assert.equal(run('savedRequest(2).status'),'borrowed');
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:2")'),false,'Pickup removes the request from Approved');
  assert.equal(element('borrowingCount').textContent,'1 approved transaction');
  run('setBorrowingStatus("borrowed")');
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:2")'),true);
  const invalidCount=calls.length;
  await run('decide(2,"rejected")'); await run('cancel(2)');
  assert.equal(calls.length,invalidCount,'Invalid transitions never call the API');
  run('openBorrowingReturn(3)');
  assert.equal(element('borrowingReturnModal').classList.contains('show'),true);
  assert.match(element('returnItemName').textContent,/2 units|Request #3/);
  element('returnRemarks').value='x'.repeat(501);
  await element('returnForm').listeners.submit({preventDefault(){}});
  assert.equal(calls.filter(c=>c[0]==='return').length,0,'Keep return remark validation');
  element('conditionStatus').value='damaged'; element('returnRemarks').value='Cracked lens';
  failAction=true; await element('returnForm').listeners.submit({preventDefault(){}}); failAction=false;
  assert.equal(run('savedRequest(3).status'),'borrowed');
  assert.equal(element('returnError').hidden,false);
  assert.match(element('returnError').textContent,/Return rejected by server/);
  assert.equal(element('returnSubmit').disabled,false);
  await element('returnForm').listeners.submit({preventDefault(){}});
  assert.equal(run('savedRequest(3).status'),'returned');
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:3")'),false,'Return removes the request from Borrowed');
  assert.equal(element('borrowingCount').textContent,'1 borrowed transaction');
  run('setBorrowingStatus("returned")');
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:3")'),true);
  const returnCall=calls.filter(c=>c[0]==='return').at(-1)[1];
  assert.equal(returnCall.condition_status,'damaged'); assert.equal(returnCall.remarks,'Cracked lens');
  assert.equal(element('borrowingReturnModal').classList.contains('show'),false);
  run('showBorrowingDetails("request:4")');
  await new Promise(resolve=>setImmediate(resolve));
  assert.match(element('borrowingDetailsBody').innerHTML,/Status history|Requested by|2026-10-09 12:30:00|Desk staff|Lens &lt;checked&gt;/);
  assert.match(element('borrowingDetailsBody').innerHTML,/Approval, rejection, and cancellation times are not supplied/);
  const returnHistoryApi=context.Api.listReturns;
  run('returnRecords=null');
  context.Api.listReturns=async()=>{throw Error('Return history unavailable');};
  await run('loadReturnDetails()');
  assert.match(element('borrowingDetailsBody').innerHTML,/Return history unavailable|Retry return history/);
  assert.match(element('borrowingDetailsBody').innerHTML,/Current status|p-returned/,'History failure preserves the complete borrowing information');
  context.Api.listReturns=returnHistoryApi;
  await run('loadReturnDetails()');
  assert.match(element('borrowingDetailsBody').innerHTML,/Desk staff/);
  run('showBorrowingDetails("request:2")');
  assert.match(element('borrowingDetailsBody').innerHTML,/Picked up \/ Borrowed|2026-10-09 13:00:00|Staff #9/);
  const listApi=context.Api.listRequests;
  context.Api.listRequests=async()=>{throw Error('Connection lost');};
  await run('load(true)');
  assert.match(element('requestList').innerHTML,/Connection lost|Try again/);
  assert.equal(run('borrowingRows'),null); assert.equal(element('pager').hidden,true);
  assert.equal(element('refreshBorrowings').disabled,false);
  context.Api.listRequests=listApi; await run('load(true)');
  run('setBorrowingStatus("pending")');
  assert.match(element('requestList').innerHTML,/No pending transactions/);
  run('setBorrowingStatus("")');
  element('dateFrom').value='2026-10-10'; element('dateTo').value='2026-10-09'; await run('load()');
  assert.match(element('requestList').innerHTML,/Check your date range/);
  element('clearFilters').listeners.click();
  assert.equal(element('dateFrom').value,''); assert.equal(element('dateTo').value,'');
  let release;
  rows.push(fixture(99)); await run('load(true)');
  const originalDecide=context.Api.decideRequest;
  context.Api.decideRequest=body=>{calls.push(['held-decision',body]);return new Promise(resolve=>release=resolve);};
  const pendingAction=run('decide(99,"approved")');
  assert.match(run('deskActionsMarkup(savedRequest(99))'),/Approving…|disabled/);
  await run('decide(99,"rejected")');
  assert.equal(calls.filter(c=>c[0]==='held-decision').length,1,'Block duplicate and competing per-item actions');
  release({success:true}); await pendingAction;
  assert.equal(run('savedRequest(99).status'),'pending','A successful response cannot fabricate a status absent from the next GET');
  context.Api.decideRequest=originalDecide;
  const capabilitiesApi=context.Api.borrowingCapabilities;
  context.Api.borrowingCapabilities=async()=>{throw Error('Capabilities unavailable');};
  await run('load(true)');
  assert.equal(run('borrowingRows !== null'),true,'Metadata failure does not hide borrowing history');
  assert.match(run('deskActionsMarkup(savedRequest(1))'),/disabled|Pickup tracking is unavailable/);
  context.Api.borrowingCapabilities=capabilitiesApi;
  await run('load(true)');
  const previousDecide=context.Api.decideRequest;
  context.Api.decideRequest=async body=>{await previousDecide(body);context.Api.listRequests=async()=>{throw Error('Refresh failed after save');};};
  await run('decide(99,"approved")');
  assert.equal(rows.find(row=>row.request_id===99).status,'approved','Fixture server committed the decision');
  assert.equal(run('borrowingRows'),null,'A failed refresh must not show a locally assigned status');
  assert.match(element('borrowingFeedback').textContent,/Request approved.*Could not refresh the list/);
  context.Api.decideRequest=previousDecide; context.Api.listRequests=listApi;
  await run('load(true)');
  rows.push(fixture(100,{checkout_id:77}));
  await run('load(true)');
  run('setBorrowingStatus("pending")');
  await run('decide(100,"rejected")');
  assert.equal(run('filteredBorrowings().length'),0,'Rejection leaves Pending immediately');
  assert.equal(element('borrowingCount').textContent,'0 pending transactions');
  run('setBorrowingStatus("rejected")');
  assert.equal(run('filteredBorrowings().some(t=>t.key==="request:100")'),true);
  assert.equal(run('transactions.filter(t=>t.items.some(r=>r.request_id===100)).length'),1,'Status changes do not duplicate transactions');
  const consistentApi=context.Api.listRequests;
  context.Api.listRequests=async()=>({data:[{transaction_id:'checkout:77',status:'pending',items:[fixture(101),fixture(102,{status:'returned'})]}]});
  await run('load(true)');
  assert.match(element('requestList').innerHTML,/inconsistent borrowing statuses/,'Reject old grouped API responses instead of showing misleading current statuses');
  context.Api.listRequests=consistentApi;
  await run('load(true)');
  const html=fs.readFileSync(path.join(root,'requests.html'),'utf8');
  assert.equal((html.match(/role="tab"/g)||[]).length,7);
  assert.match(html,/borrowingReturnModal|maxlength="500"/);
  for(const [,id] of source.matchAll(/requestElement\('([^']+)'\)/g)) assert.ok(html.includes(`id="${id}"`),'Missing DOM element: '+id);
  console.log('PASS: ' + deskRole + ' request-level transactions, all six exact status tabs, one current badge, accurate counts, automatic status movement, checkout references, pagination, valid actions, history, error/retry, and duplicate protection.');
})().catch(error=>{console.error(error);process.exitCode=1;});
