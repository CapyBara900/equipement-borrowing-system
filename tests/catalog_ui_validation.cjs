const assert=require('node:assert/strict'), fs=require('node:fs'), path=require('node:path'), vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../assets/js/equipment.js'),'utf8');
const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fixtures=[{equipment_id:1,equipment_name:'Camera <kit>',description:'Portable camera',category_name:'Audio/Visual',serial_number:'CAM-001',status:'available',equipment_status:'available',available_quantity:18,total_quantity:20,borrowing_time_limit_days:7},
{equipment_id:2,equipment_name:'Laptop',status:'unavailable',equipment_status:'maintenance',available_quantity:0,total_quantity:4,borrowing_time_limit_days:5},
{equipment_id:3,equipment_name:'Tripod',status:'available',available_quantity:1,total_quantity:8,borrowing_time_limit_days:3}];
function harness(role){
 const nodes=new Map(), storage=new Map(), calls=[], notices=[];
 const node=id=>{if(!nodes.has(id))nodes.set(id,{id,value:'',innerHTML:'',textContent:'',hidden:false,disabled:false,open:false,attributes:{},listeners:{},setAttribute(k,v){this.attributes[k]=v;},addEventListener(k,fn){(this.listeners[k]??=[]).push(fn);},emit(k,e={}){for(const fn of this.listeners[k]||[])fn(e);},scrollIntoView(){},showModal(){this.open=true;},close(){if(this.open){this.open=false;this.emit('close');}}});return nodes.get(id);};
 node('sortBy').value='equipment_name';
 const context=vm.createContext({console,Map,Set,Date,Number,String,setTimeout,clearTimeout,
  document:{getElementById:node,querySelector:node},localStorage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v)},
  requireSession:async()=>({role,user_id:1,name:'Fixture Person'}),
  CategoryManager:{init(u){if(u.role==='admin')node('manageCategoriesBtn').addEventListener('click',()=>{node('categoryManagement').open=!node('categoryManagement').open;});}},
  CategoryStore:{start:async fn=>fn([{category_id:1,category_name:'Audio/Visual'}],[]),refresh:async()=>{}},
  BorrowingCart:{initialize:async()=>{}}, BorrowingDetails:{dateValid:v=>/^\d{4}-\d{2}-\d{2}$/.test(v)},
  Rules:{required:()=>({}),maxLength:()=>({}),onOrAfterField:()=>({})},liveValidate:()=>()=>{},validate:()=>true,clearError(){},
  bootstrap:{Modal:{getOrCreateInstance:()=>({show(){},hide(){}})}},esc,fmtDate:esc,emptyState:(title,message)=>'<div>'+esc(title)+' '+esc(message)+'</div>',toast:(message)=>notices.push(message),
  debounce:(fn,ms)=>{let timer;return ()=>{clearTimeout(timer);timer=setTimeout(fn,ms);};},
  Api:{listEquipment:async q=>{calls.push(q);return {data:fixtures,page:q.page,total:3,pages:1};},getEquipment:async id=>({data:fixtures.find(row=>String(row.equipment_id)===String(id))}),getEquipmentHistory:async()=>({data:{borrowings:[],conditions:[]}}),getPickupWindow:async()=>({data:{min_date:'2026-10-10',max_date:'2026-10-17'}})}
 });
 vm.runInContext(source.replace('(async function ()','const catalogReady = (async function ()'),context);
 return {context,node,storage,calls,notices,run:code=>vm.runInContext(code,context)};
}
(async()=>{
 for(const role of ['customer','admin']){
  const h=harness(role);await h.run('catalogReady');
  let html=h.node('equipmentList').innerHTML;
  assert.equal(h.run('state.view'),role==='admin'?'list':'grid');
  assert.match(html,/Camera &lt;kit&gt;/);assert.doesNotMatch(html,/<kit>/);assert.match(html,/Low stock/);assert.match(html,/role="progressbar"/);
  assert.equal(h.node('categoryManagement').hidden,true,'Do not show an unused category card wrapper.');
  assert.equal(h.node('equipmentList').attributes['aria-busy'],'false');
  if(role==='customer'){assert.match(html,/Add to Cart/);assert.match(html,/Quick Request/);assert.match(html,/data-cart-add="2" disabled/);assert.match(html,/data-borrow="2" disabled/);assert.doesNotMatch(html,/data-edit=|data-delete=|data-qr=/);}
  else {assert.match(html,/catalog-table/);assert.match(html,/Maintenance/);assert.match(html,/aria-label="Edit Camera &lt;kit&gt;"/);assert.doesNotMatch(html,/data-cart-add=|data-borrow=/);h.node('manageCategoriesBtn').emit('click');assert.equal(h.node('categoryManagement').hidden,false);assert.equal(h.node('manageCategoriesBtn').attributes['aria-expanded'],'true');h.node('manageCategoriesBtn').emit('click');assert.equal(h.node('categoryManagement').hidden,true);}
  h.node('searchInput').value=' Camera ';h.node('searchInput').emit('input');await new Promise(resolve=>setTimeout(resolve,280));assert.equal(h.calls.at(-1).search,'Camera');assert.equal(h.calls.at(-1).page,1);
  h.node('categoryFilter').value='1';h.node('categoryFilter').emit('change');await new Promise(resolve=>setImmediate(resolve));assert.equal(h.calls.at(-1).category_id,'1');
  h.node('statusFilter').value='available';h.node('statusFilter').emit('change');await new Promise(resolve=>setImmediate(resolve));assert.equal(h.calls.at(-1).status,'available');
  h.node('sortBy').value='created_at';h.node('sortBy').emit('change');await new Promise(resolve=>setImmediate(resolve));assert.equal(h.calls.at(-1).sort_dir,'DESC');assert.equal(h.node('sortDirBtn').textContent,'↓');
  h.node('sortBy').value='equipment_name';h.node('sortBy').emit('change');await new Promise(resolve=>setImmediate(resolve));h.node('searchInput').value='';h.node('categoryFilter').value='';h.node('statusFilter').value='';
  const before=h.calls.length;h.run("setCatalogView('list')");assert.match(h.node('equipmentList').innerHTML,/catalog-table/);h.run("setCatalogView('grid')");assert.match(h.node('equipmentList').innerHTML,/catalog-grid/);assert.equal(h.calls.length,before,'Switching layout must retain the same loaded results without extra requests.');
  assert.equal(h.storage.get('equipment-catalog-view:1:'+role),'grid');h.run("setCatalogView('invalid')");assert.equal(h.run('state.view'),'grid');
  await h.run("openEquipmentQuickView('1')");assert.equal(h.node('equipmentQuickView').open,true);assert.match(h.node('quickViewBody').innerHTML,/CAM-001|Borrowing terms|Audio/);assert.equal(h.node('quickHistory').attributes['aria-busy'],'false');
  if(role==='customer')assert.doesNotMatch(h.node('quickHistory').innerHTML,/Condition records/);else assert.match(h.node('quickHistory').innerHTML,/Condition records/);
  h.context.Api.getEquipmentHistory=async()=>{throw Error('History connection lost');};await h.run("loadQuickViewHistory('1',quickViewVersion)");assert.match(h.node('quickHistory').innerHTML,/Retry history/);assert.match(h.node('quickViewBody').innerHTML,/Borrowing terms/,'History failure must not hide specifications.');
  h.context.Api.listEquipment=async()=>{throw Error('Connection lost');};await h.run('loadEquipment()');assert.match(h.node('equipmentList').innerHTML,/data-catalog-retry/);h.run("setCatalogView('list')");assert.match(h.node('equipmentList').innerHTML,/data-catalog-retry/);
 }
 const h=harness('customer');await h.run('catalogReady');
 const resolves=[];h.context.Api.listEquipment=()=>new Promise(resolve=>resolves.push(resolve));
 const old=h.run('loadEquipment()'),fresh=h.run('loadEquipment()');resolves[1]({data:[fixtures[2]],total:1,page:1,pages:1});await fresh;const latest=h.node('equipmentList').innerHTML;resolves[0]({data:[fixtures[0]],total:1,page:1,pages:1});await old;assert.equal(h.node('equipmentList').innerHTML,latest);
 const detailResolves=[];h.context.Api.getEquipment=id=>new Promise(resolve=>detailResolves.push({id,resolve}));
 const oldDetail=h.run("openEquipmentQuickView('1')"),freshDetail=h.run("openEquipmentQuickView('3')");detailResolves[1].resolve({data:fixtures[2]});await freshDetail;detailResolves[0].resolve({data:fixtures[0]});await oldDetail;assert.equal(h.node('quickViewTitle').textContent,'Tripod');
 const opened=[];h.context.openBorrow=async(...args)=>opened.push(args);h.context.Api.getEquipment=async()=>({data:fixtures[2]});
 await h.run("catalogBorrowAction('3','request',{disabled:false})");assert.equal(opened.at(-1).at(-1),'request');
 h.context.Api.getEquipment=async()=>({data:fixtures[1]});h.context.Api.listEquipment=async()=>({data:fixtures,total:3,page:1,pages:1});const count=opened.length;await h.run("catalogBorrowAction('2','cart',{disabled:false})");assert.equal(opened.length,count,'Fresh out-of-stock items must not open a borrowing form.');assert.match(h.notices.at(-1),/out of stock/);
 h.context.Api.listEquipment=async()=>({data:[],total:0,page:1,pages:1});h.node('searchInput').value='missing';await h.run('loadEquipment()');assert.match(h.node('equipmentList').innerHTML,/No equipment matches|Clear filters/);
 console.log('PASS: customer/admin layouts, persisted view switching, stock badges and disabled actions, category panel, escaped content, fresh details, private history UI, history retries, stale search/detail responses, stock rechecks, and empty/error states.');
})().catch(error=>{console.error(error);process.exitCode=1;});
