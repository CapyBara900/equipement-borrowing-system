const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const root=path.join(__dirname,'..'),elements=new Map(),storage=new Map();let storageFails=false;
function element(id){if(!elements.has(id)){let value='';const classes=new Set();elements.set(id,{id,get value(){return value;},set value(v){value=String(v);},innerHTML:'',textContent:'',hidden:true,disabled:false,min:'',max:'',listeners:{},classList:{add:v=>classes.add(v),remove:v=>classes.delete(v),contains:v=>classes.has(v),toggle(v,on){if(on)classes.add(v);else classes.delete(v);}},addEventListener(t,fn){this.listeners[t]=fn;},setAttribute(){},removeAttribute(){},focus(){},scrollIntoView(){}});}return elements.get(id);}
const context=vm.createContext({document:{getElementById:element,querySelector:element,querySelectorAll:()=>[]},window:{addEventListener(){}},localStorage:{getItem:key=>storage.get(key),removeItem:key=>storage.delete(key),setItem(key,value){if(storageFails)throw Error('blocked');storage.set(key,value);}},bootstrap:{Modal:{getOrCreateInstance:node=>({show(){node.hidden=false;},hide(){if(node.id==='modifyModal')context.discardEditor({preventDefault(){}});node.hidden=true;}})}},crypto:{randomUUID:()=> '12345678-1234-1234-1234-123456789abc'},console,Date,Map,Set});
const run=code=>vm.runInContext(code,context);
run(fs.readFileSync(path.join(root,'assets/js/app.js'),'utf8')+'\nCURRENT_USER={user_id:1,role:"customer"};toast=()=>{};');
context.item={equipment_id:10,equipment_name:'Camera',status:'available',available_quantity:3,borrowing_time_limit_days:5};context.bounds={min_date:'2026-10-08',max_date:'2026-10-15'};context.detail={quantity:1,borrow_date:'2026-10-08',expected_return_date:'2026-10-08'};
(async()=>{
assert.deepEqual(Object.keys(run('BorrowingDetails.validate(detail,item,bounds)')),[],'Same-day borrowing matches direct requests');
for(const value of [0,-1,1.5,'',true,4]){context.value=value;assert.ok(run('BorrowingDetails.validate({...detail,quantity:value},item,bounds).quantity'));}
for(const value of ['','2026-02-30','2026-10-07','2026-10-16']){context.value=value;assert.ok(run('BorrowingDetails.validate({...detail,borrow_date:value},item,bounds).borrow_date'));}
for(const value of ['','2026-10-07','2026-10-14']){context.value=value;assert.ok(run('BorrowingDetails.validate({...detail,expected_return_date:value},item,bounds).expected_return_date'));}
await assert.rejects(run('BorrowingCart.add(item)'),/quantity/);
await run('BorrowingCart.add(item,detail,bounds);');await run('BorrowingCart.add(item,detail,bounds)');
assert.equal(run('BorrowingCart.read().length'),1);assert.equal(run('BorrowingCart.read()[0].quantity'),2);
await run('BorrowingCart.add(item,{...detail,borrow_date:"2026-10-09",expected_return_date:"2026-10-10"},bounds)');
assert.equal(run('BorrowingCart.read().length'),2,'Different dates must be separate entries');
assert.equal(run('BorrowingCart.read()[0].borrow_date'),'2026-10-08');
await assert.rejects(run('BorrowingCart.add(item,{...detail,quantity:2},bounds)'),/Combined/);
run('CURRENT_USER.user_id=2');assert.equal(run('BorrowingCart.read().length'),0);run('CURRENT_USER.user_id=1');
context.original=run('BorrowingCart.read()[0]');await assert.rejects(run('BorrowingCart.update(original.cart_item_id,{...detail,borrow_date:"2026-10-09",expected_return_date:"2026-10-10"})'),/already/);
storageFails=true;run('CURRENT_USER.user_id=3');await run('BorrowingCart.add(item,detail,bounds)');assert.equal(run('BorrowingCart.read()[0].quantity'),1);storageFails=false;run('CURRENT_USER.user_id=1');
let serverRows=[{...context.item,...context.detail,quantity:2,cart_item_id:101},{...context.item,...context.detail,borrow_date:'2026-10-09',expected_return_date:'2026-10-10',quantity:2,cart_item_id:102}];const adds=[],updates=[],direct=[];
context.Api={cartCapabilities:async()=>({data:{cart_available:true,checkout_available:true}}),getCart:async()=>({data:serverRows.map(r=>({...r}))}),getPickupWindow:async()=>({data:context.bounds}),getEquipment:async()=>({data:context.item}),addCartItem:async body=>{adds.push(body);return {data:serverRows};},updateCartItem:async(id,details)=>{updates.push({id,...details});serverRows=serverRows.map(row=>String(row.cart_item_id)===String(id)?{...row,...details}:row);return {data:serverRows};},removeCartItem:async id=>{serverRows=serverRows.filter(row=>String(row.cart_item_id)!==String(id));return {data:serverRows};},createRequest:async payload=>direct.push(payload)};
await run('BorrowingCart.initialize()');
const equipment=fs.readFileSync(path.join(root,'assets/js/equipment.js'),'utf8');run(equipment.slice(equipment.indexOf('const borrowModal ='),equipment.indexOf('/* ---------- Admin:'))+'\nasync function loadEquipment(){}');
await run('openBorrow(10,"Camera",3,5,"cart")');assert.equal(adds.length,0,'Opening modal must not submit');assert.equal(element('borrowModalTitle').textContent,'Add equipment to cart');assert.equal(element('borrowDate').min,'2026-10-08');assert.equal(element('borrowDate').max,'2026-10-15');
for(const [field,value] of [['borrowQuantity',''],['borrowQuantity','0'],['borrowQuantity','4'],['borrowDate',''],['borrowDate','2026-10-07'],['borrowDate','2026-10-16'],['expectedReturnDate',''],['expectedReturnDate','2026-10-07'],['expectedReturnDate','2026-10-14']]){await run('openBorrow(10,"Camera",3,5,"cart")');element(field).value=value;await element('borrowForm').listeners.submit({preventDefault(){}});assert.equal(adds.length,0,'Invalid '+field+' submitted');}
await run('openBorrow(10,"Camera",3,5,"cart")');element('borrowQuantity').value=2;element('borrowDate').value='2026-10-09';element('expectedReturnDate').value='2026-10-10';await element('borrowForm').listeners.submit({preventDefault(){}});assert.deepEqual(JSON.parse(JSON.stringify(adds[0])),{equipment_id:10,quantity:2,borrow_date:'2026-10-09',expected_return_date:'2026-10-10'});assert.equal(direct.length,0,'Adding cannot create borrowing requests');
await run('openBorrow(10,"Camera",3,5)');await element('borrowForm').listeners.submit({preventDefault(){}});assert.equal(direct.length,1,'Direct request remains functional');
const source=fs.readFileSync(path.join(root,'assets/js/cart.js'),'utf8');run(source.slice(0,source.indexOf('(async function initCart()')));await run('refreshCart()');run('selected=new Set(["101","102"]);checkoutAvailable=true');assert.match(run('selectionError()'),/combined/);assert.equal(run('chosen().length'),2,'Selection retains two entries of same equipment');
run('selected=new Set(["101"]);');assert.equal(run('validSelection()'),true);
assert.match(element('cartItems').innerHTML,/data-modify="101"[^>]*>Edit dates/);
assert.match(element('cartItems').innerHTML,/data-modify="102"[^>]*>Edit dates/);
assert.doesNotMatch(element('cartItems').innerHTML,/name="(borrow_date|expected_return_date)"/,'Date inputs remain in the editor');
await run('openModifier("101")');
assert.match(element('modifyBody').innerHTML,/name="quantity"[^>]*value="2"/);
assert.match(element('modifyBody').innerHTML,/name="borrow_date"[^>]*value="2026-10-08"/);
assert.match(element('modifyBody').innerHTML,/name="expected_return_date"[^>]*value="2026-10-08"/);
assert.match(element('modifyBody').innerHTML,/Save Changes/);assert.match(element('modifyBody').innerHTML,/>Cancel</);
assert.equal(element('checkoutBtn').disabled,true,'Open editor must block checkout');
const fields={quantity:element('editQ'),borrow_date:element('editP'),expected_return_date:element('editR')};
const form={dataset:{cartEdit:'101'},querySelector(selector){const match=selector.match(/name="([^"]+)"/);return match?fields[match[1]]:element(selector);},closest(){return this;}};context.form=form;
fields.quantity.value='1';fields.borrow_date.value='2026-10-10';fields.expected_return_date.value='2026-10-11';
const initial=JSON.stringify(serverRows);run('validateEditor(form)');assert.match(run('selectionError()'),/Save/);
run('discardEditor({preventDefault(){}})');assert.equal(JSON.stringify(serverRows),initial,'Cancel must not apply edits');assert.equal(updates.length,0);assert.equal(run('drafts.size'),0);assert.equal(run('activeEditor'),null);assert.equal(element('checkoutBtn').disabled,false);
await run('openModifier("101")');assert.match(element('modifyBody').innerHTML,/name="quantity"[^>]*value="2"/,'Reopen after Cancel must use saved values');
await run('saveEntry({target:form,preventDefault(){}})');assert.equal(updates[0].id,'101');assert.equal(updates[0].borrow_date,'2026-10-10');assert.equal(run('cartRows[1].borrow_date'),'2026-10-09','Editing one entry cannot change another');assert.equal(run('drafts.size'),0);assert.equal(run('activeEditor'),null);assert.equal(run('selected.has("101")'),true,'Save preserves selected entry');assert.match(element('selectionSummary').innerHTML,/2026-10-10/);
await run('openModifier("101")');assert.match(element('modifyBody').innerHTML,/name="quantity"[^>]*value="1"/);assert.match(element('modifyBody').innerHTML,/name="borrow_date"[^>]*value="2026-10-10"/);
for(const [field,value] of [['quantity','0'],['quantity','1.5'],['quantity','4'],['borrow_date','2026-10-07'],['borrow_date','2026-10-16'],['expected_return_date','2026-10-09'],['expected_return_date','2026-10-16']]){
  fields.quantity.value='1';fields.borrow_date.value='2026-10-10';fields.expected_return_date.value='2026-10-11';fields[field].value=value;
  await run('saveEntry({target:form,preventDefault(){}})');assert.equal(updates.length,1,'Invalid modal edit cannot save');
}
fields.quantity.value='2';fields.borrow_date.value='2026-10-10';fields.expected_return_date.value='2026-10-11';context.item.available_quantity=1;
await run('saveEntry({target:form,preventDefault(){}})');assert.equal(updates.length,1,'Save must recheck changed stock');assert.match(element('[data-error="quantity"]').textContent,/stock/);context.item.available_quantity=3;
fields.quantity.value='1';const updateApi=context.Api.updateCartItem;context.Api.updateCartItem=async()=>{throw Error('Another entry already uses those dates.');};
await run('saveEntry({target:form,preventDefault(){}})');assert.match(element('[data-general-error]').textContent,/already uses/);assert.equal(run('activeEditor.key'),'101','Failed save keeps editor open');assert.equal(serverRows[0].quantity,1);context.Api.updateCartItem=updateApi;
run('discardEditor({preventDefault(){}})');assert.equal(run('drafts.size'),0,'Cancel after failed save clears only the current draft');
// Select a second entry and ensure opening it uses that entry's independent values.
await run('openModifier("102")');assert.match(element('modifyBody').innerHTML,/name="quantity"[^>]*value="2"/);assert.match(element('modifyBody').innerHTML,/name="borrow_date"[^>]*value="2026-10-09"/);run('discardEditor({preventDefault(){}})');
run('checkoutAttempt={key:"pending"}');await run('openModifier("101")');assert.equal(run('activeEditor'),null,'Unconfirmed checkout must block edits');run('checkoutAttempt=null');
context.rows=run('cartRows.map(row=>({...row}))');serverRows[0].borrow_date='2026-10-11';await assert.rejects(run('preflight([rows[0]])'),/changed/);serverRows[0].borrow_date='2026-10-10';
context.item.available_quantity=0;await assert.rejects(run('preflight([rows[0]])'),/stock/);context.item.available_quantity=3;
context.payload={items:[{cart_item_id:101,equipment_id:10,requested_quantity:1,borrow_date:'2026-10-10',expected_return_date:'2026-10-11'}]};const sent=[];
context.Api.checkoutCart=async body=>{sent.push(body);const error=new Error('Network lost');error.status=0;throw error;};const before=JSON.stringify(serverRows);await assert.rejects(run('CartCheckoutGateway.submit(payload)'),/Network lost/);assert.equal(JSON.stringify(serverRows),before);assert.ok(storage.has('equipment-desk:checkout:v1:1'));
context.Api.checkoutCart=async()=>{const error=new Error('Session expired');error.status=401;throw error;};await assert.rejects(run('CartCheckoutGateway.submit(payload)'),/Session expired/);assert.ok(run('checkoutAttempt'));
await assert.rejects(run('CartCheckoutGateway.submit({items:[]})'),/unconfirmed/);
context.Api.checkoutCart=async body=>{sent.push(body);return {success:true,confirmed_equipment_ids:[10]};};context.result=await run('CartCheckoutGateway.submit(payload)');assert.throws(()=>run('confirmCheckout(result,payload.items)'),/not fully confirmed/);assert.ok(run('checkoutAttempt'),'Equipment-only acknowledgment cannot clear dated cart');
context.Api.checkoutCart=async body=>{sent.push(body);serverRows=serverRows.filter(row=>row.cart_item_id!==101);return {success:true,confirmed_cart_item_ids:[101]};};context.result=await run('CartCheckoutGateway.submit(payload)');await run('finishSubmission(result,payload.items)');assert.equal(sent[0].idempotency_key,sent.at(-1).idempotency_key);assert.equal(run('checkoutAttempt'),null);assert.equal(run('cartRows.length'),1);assert.equal(run('cartRows[0].cart_item_id'),102,'Unselected same-equipment entry retained');
await run('BorrowingCart.remove(102)');assert.equal(run('BorrowingCart.read().length'),0);
const previous=[{...context.item,...context.detail,quantity:2}];storage.set('equipment-desk:cart:v1:1',JSON.stringify(previous));context.Api.importCart=async()=>{throw Error('Stock changed');};const preserved=storage.get('equipment-desk:cart:v1:1');await assert.rejects(run('BorrowingCart.importPrevious()'),/Stock changed/);assert.equal(storage.get('equipment-desk:cart:v1:1'),preserved);context.Api.importCart=async()=>({data:[]});await assert.rejects(run('BorrowingCart.importPrevious()'),/not confirmed/);assert.equal(storage.get('equipment-desk:cart:v1:1'),preserved);
context.Api.importCart=async()=>{storage.set('equipment-desk:cart:v1:1',JSON.stringify([{...previous[0],quantity:3},{...previous[0],borrow_date:'2026-10-09'}]));return {data:[{...previous[0],cart_item_id:103}]};};await run('BorrowingCart.importPrevious()');assert.equal(run('BorrowingCart.previous()[0].quantity'),1);assert.equal(run('BorrowingCart.previous().length'),2,'Concurrent browser additions preserved');
// Previous browser entries also hide their fields until Modify, while import drafts survive Cancel.
await run('refreshCart()');assert.match(element('previousCartItems').innerHTML,/data-modify=/);assert.doesNotMatch(element('previousCartItems').innerHTML,/name="quantity"/);
context.previousEditorKey=run('previousKey(previousRows[0])');form.dataset.cartEdit=context.previousEditorKey;
await run('openModifier(previousEditorKey)');fields.quantity.value='1';fields.borrow_date.value='2026-10-10';fields.expected_return_date.value='2026-10-11';
await run('saveEntry({target:form,preventDefault(){}})');assert.equal(run('effective(previousRows[0],true).quantity'),1);assert.equal(run('activeEditor'),null);const persistedBrowser=storage.get('equipment-desk:cart:v1:1');
await run('openModifier(previousEditorKey)');fields.quantity.value='2';run('validateEditor(form)');run('discardEditor({preventDefault(){}})');assert.equal(run('effective(previousRows[0],true).quantity'),1,'Cancel restores previously staged import details');assert.equal(storage.get('equipment-desk:cart:v1:1'),persistedBrowser,'Cancel cannot mutate original browser cart');
// An old checkbox can briefly remain in the DOM after another session removes an entry.
context.document.querySelectorAll=selector=>selector==='[data-select]'?[{dataset:{select:'deleted-entry'},disabled:false}]:[];run('updateSummary()');
// Redesigned cart: inline quantities must persist before checkout and summary counts follow selection.
context.item.available_quantity=3;context.item.borrowing_time_limit_days=7;
serverRows=[{...context.item,cart_item_id:201,quantity:1,borrow_date:'2026-10-08',expected_return_date:'2026-10-10'},
  {...context.item,cart_item_id:202,quantity:1,borrow_date:'2026-10-09',expected_return_date:'2026-10-10'},
  {...context.item,cart_item_id:203,quantity:1,borrow_date:null,expected_return_date:null}];
await run('refreshCart()');run('selected.clear();updateSummary()');
assert.equal(element('checkoutBtn').disabled,true,'Zero selected entries must disable checkout');
assert.equal(element('totalItems').textContent,0);assert.equal(element('totalQuantity').textContent,0);
run('selected=new Set(cartRows.filter(eligible).map(rowKey));renderCart()');
assert.equal(run('selected.size'),2,'Select all includes only eligible entries');assert.equal(element('selectAll').checked,true);
assert.equal(element('totalItems').textContent,2);assert.equal(element('totalQuantity').textContent,2);assert.equal(element('checkoutBtn').disabled,false);
assert.match(element('cartItems').innerHTML,/data-quantity="201"/);assert.match(element('cartItems').innerHTML,/Available: 3/);
assert.equal(element('pickupDate').min,'2026-10-08');assert.equal(element('pickupDate').max,'2026-10-15');
const inline=element('inlineQuantity');inline.dataset={quantity:'201'};inline.value='2';context.inline=inline;
run('stageQuantity(inline)');assert.equal(element('totalQuantity').textContent,3,'Typed quantity immediately updates the summary');
assert.equal(element('checkoutBtn').disabled,true,'Unsaved inline changes block checkout');
await run('saveQuantity("201",inline.value)');assert.equal(serverRows[0].quantity,2);assert.equal(serverRows[0].borrow_date,'2026-10-08');
assert.equal(element('checkoutBtn').disabled,false,'Successful quantity save restores checkout');
inline.value='0';run('stageQuantity(inline)');const beforeInvalid=updates.length;
await run('saveQuantity("201",inline.value)');assert.equal(updates.length,beforeInvalid,'Invalid quantities cannot save');assert.equal(element('checkoutBtn').disabled,true);
inline.value='2';run('stageQuantity(inline)');assert.equal(element('checkoutBtn').disabled,false);
await run('saveQuantity("201","3")');assert.equal(element('checkoutBtn').disabled,true,'Combined stock across dated entries still applies');
run('selected.delete("202");updateSummary()');assert.equal(element('checkoutBtn').disabled,false);assert.equal(element('selectAll').indeterminate,true);
await run('saveQuantity("201","1")');run('selected.add("202");updateSummary()');
// Failed saves restore the persisted quantity without enabling unsafe drafts.
context.Api.updateCartItem=async()=>{throw Error('Save failed');};await run('saveQuantity("201","2")');assert.equal(run('cartRows[0].quantity'),1);assert.equal(run('drafts.has("201")'),false);assert.match(element('cartMessage').textContent,/Save failed/);context.Api.updateCartItem=updateApi;
// A newly chosen pickup date must be applied or discarded before checkout.
element('pickupDate').value='2026-10-11';run('pickupDirty=true;updateSummary()');assert.equal(element('checkoutBtn').disabled,true);assert.match(run('selectionError()'),/pickup date/);run('pickupDirty=false;updateSummary()');assert.equal(element('checkoutBtn').disabled,false);
// Applying one pickup date keeps each independent borrowing duration.
element('pickupDate').value='2026-10-11';await run('applySelectedPickup()');
assert.equal(serverRows[0].borrow_date,'2026-10-11');assert.equal(serverRows[0].expected_return_date,'2026-10-13');
assert.equal(serverRows[1].borrow_date,'2026-10-11');assert.equal(serverRows[1].expected_return_date,'2026-10-12');
assert.equal(serverRows[2].borrow_date,null,'Unselected entries keep their dates');assert.equal(run('selected.size'),2);
// A date collision must be detected before saving any entry.
serverRows[1].expected_return_date='2026-10-13';await run('refreshCart()');element('pickupDate').value='2026-10-12';const beforeCollision=updates.length;
await run('applySelectedPickup()');assert.equal(updates.length,beforeCollision);assert.match(element('cartMessage').textContent,/duplicate/);
// Batch deletions are serialized; errors preserve the remaining entries.
const removeApi=context.Api.removeCartItem;context.Api.removeCartItem=async id=>{if(String(id)==='202')throw Error('Delete failed');return removeApi(id);};
await run('removeEntries(["201","202"])');assert.equal(serverRows.length,2);assert.equal(serverRows[0].cart_item_id,202);assert.match(element('cartMessage').textContent,/Delete failed/);
context.Api.removeCartItem=removeApi;await run('removeEntries(["202","203"])');
assert.equal(run('cartRows.length'),0);assert.equal(element('totalQuantity').textContent,0);assert.equal(element('checkoutBtn').disabled,true);
assert.match(element('cartItems').innerHTML,/Your cart is currently empty/);assert.match(element('cartItems').innerHTML,/href="equipment.php"/);
console.log('PASS: inline quantities, selected totals, batch pickup dates, date collisions, partial batch deletion, empty state, required shared modal details, quantity/date limits, separate dated entries, customer isolation, direct request compatibility, Modify modal saving/cancellation, aggregate stock/preflight, preserved failed carts, durable retries, entry-specific acknowledgments, and browser import safety.');
})().catch(error=>{console.error(error);process.exitCode=1;});
