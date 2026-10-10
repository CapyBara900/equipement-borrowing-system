/* Every saved entry owns its quantity and dates; inline edits persist before checkout. */
let cartRows=[],previousRows=[],selected=new Set(),verified=new Set(),pickupBounds=null;
let refreshing=false,submitting=false,cartBusy=false,checkoutAvailable=false,checkoutAttempt=null;
const drafts=new Map();
let activeEditor=null,editorOpening=false,editorReturnKey=null,pickupDirty=false;
const cartIcons={"bag":"<path d=\"M6 7h12l2 14H4L6 7Z\"/><path d=\"M9 7V5a3 3 0 0 1 6 0v2\"/>","box":"<path d=\"m12 3 9 5-9 5-9-5 9-5Z\"/><path d=\"M3 8v9l9 5 9-5V8M12 13v9M7.5 5.5l9 5\"/>","trash":"<path d=\"M3 6h18M9 6V4h6v2M5 6l1 15h12l1-15M10 10v7M14 10v7\"/>","arrow":"<path d=\"M5 12h14m-6-6 6 6-6 6\"/>","info":"<circle cx=\"12\" cy=\"12\" r=\"9\"/><path d=\"M12 11v6M12 7h.01\"/>","refresh":"<path d=\"M20 7v5h-5M4 17v-5h5\"/><path d=\"M6 6a8 8 0 0 1 13 3M18 18a8 8 0 0 1-13-3\"/>","calendar":"<rect x=\"3\" y=\"5\" width=\"18\" height=\"16\" rx=\"2\"/><path d=\"M7 3v4M17 3v4M3 11h18M7 15h3M14 15h3\"/>"};
const cartIcon=name=>'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+cartIcons[name]+'</svg>';
const inlineEditable=row=>stockVerified(row)&&!Object.keys(BorrowingDetails.validate({...details(row),quantity:1},row,pickupBounds)).length;
const displayDate=value=>BorrowingDetails.dateValid(value)?new Intl.DateTimeFormat('en-PH',{month:'short',day:'numeric',year:'numeric',timeZone:'UTC'}).format(new Date(value+'T00:00:00Z')):'Choose date';
const modifyModal=()=>typeof CartDialogs!=='undefined'?CartDialogs.modal(byId('modifyModal')):bootstrap.Modal.getOrCreateInstance(byId('modifyModal'));
const byId=id=>document.getElementById(id);
const rowKey=row=>BorrowingCart.entryKey(row);
const previousKey=row=>'previous:'+rowKey(row);
const details=row=>({quantity:row.quantity,borrow_date:row.borrow_date||'',expected_return_date:row.expected_return_date||''});
const effective=(row,previous=false)=>drafts.get(previous?previousKey(row):rowKey(row))?.details||details(row);
const errorsFor=(row,previous=false)=>BorrowingDetails.validate(effective(row,previous),row,pickupBounds);
const stockVerified=row=>verified.has(String(row.equipment_id));
const eligible=row=>stockVerified(row)&&!Object.keys(BorrowingDetails.validate(details(row),row,pickupBounds)).length;
const chosen=()=>cartRows.filter(row=>selected.has(rowKey(row)));
function selectionError(rows=chosen()){
  if(!rows.length)return 'Select at least one cart entry.';
  if(pickupDirty&&byId('pickupDate').value&&rows.some(row=>row.borrow_date!==byId('pickupDate').value))return 'Apply your pickup date or keep the existing dates before checkout.';
  const totals=new Map();
  for(const row of rows){
    if(drafts.has(rowKey(row)))return 'Save your quantity and date changes before checkout.';
    if(!eligible(row))return 'Selected entries need valid quantities, current dates, and verified availability.';
    const id=String(row.equipment_id);const total=(totals.get(id)||0)+Number(row.quantity);totals.set(id,total);
    if(total>Number(row.available_quantity))return 'The combined selected quantity for '+row.equipment_name+' exceeds available stock, including entries with different dates.';
  }
  return '';
}
const validSelection=()=>!selectionError();
function message(text,error=false){byId('cartMessage').textContent=text;byId('cartBanner').hidden=false;byId('cartBanner').classList.toggle('cart-error',error);}
function dateBounds(row,value){return {min:BorrowingDetails.dateValid(value)?value:(pickupBounds?.min_date||''),max:BorrowingDetails.dateValid(value)&&Number(row.borrowing_time_limit_days)>0?BorrowingDetails.dateAfter(value,Number(row.borrowing_time_limit_days)):''};}
function editorMarkup(row,previous=false){
  const key=previous?previousKey(row):rowKey(row),value=effective(row,previous),bounds=dateBounds(row,value.borrow_date);
  const field=(name,label,type,min,max)=>'<div><label class="form-label" for="modify-'+name+'">'+label+'</label><input class="form-control" id="modify-'+name+'" name="'+name+'" type="'+type+'" value="'+esc(value[name])+'" min="'+esc(min)+'" max="'+esc(max)+'" '+(type==='number'?'step="1"':'')+' required aria-describedby="modify-error-'+name+'"><div class="field-error" id="modify-error-'+name+'" data-error="'+name+'" role="alert"></div></div>';
  return '<form data-cart-edit="'+esc(key)+'" novalidate><fieldset class="border-0 p-0 m-0"><div class="modal-body">'+
    '<p class="form-text">Available: '+esc(row.available_quantity||0)+' · Borrowing limit: '+esc(row.borrowing_time_limit_days||'?')+' days. Cart entries do not reserve stock.</p>'+
    '<div class="row g-3"><div class="col-12">'+field('quantity','Quantity','number',1,Number(row.available_quantity)||0)+'</div>'+
    '<div class="col-12 col-sm-6">'+field('borrow_date','Pick Up On','date',pickupBounds?.min_date||'',pickupBounds?.max_date||'')+'</div>'+
    '<div class="col-12 col-sm-6">'+field('expected_return_date','Return By','date',bounds.min,bounds.max)+'</div></div>'+
    '<div class="field-error mt-3" data-general-error role="alert"></div>'+
    (previous?'<p class="form-text mt-3 mb-0">Save these details, then import your previous browser cart.</p>':'')+
    '</div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit" id="modifySave">Save Changes</button></div></fieldset></form>';
}
async function openModifier(key){
  if(editorOpening||activeEditor||refreshing||submitting||cartBusy||checkoutAttempt)return;
  editorOpening=true;
  try{
    // Refresh both persisted values and the current availability/date bounds before editing.
    await refreshCart();
    const {row,previous}=resolveEditor(key);
    if(!row){message('This cart entry is no longer available. Refresh your cart.',true);return;}
    activeEditor={key,previous,originalDraft:drafts.get(key),saved:false};
    editorReturnKey=key;
    byId('modifyItemName').textContent=row.equipment_name||'Equipment';
    byId('modifyBody').innerHTML=editorMarkup(row,previous);
    modifyModal().show();updateSummary();
  }finally{editorOpening=false;updateSummary();}
}
function discardEditor(event){
  if(cartBusy||refreshing||submitting){event.preventDefault();return;}
  if(!activeEditor)return;
  const {key,previous,originalDraft,saved}=activeEditor;
  if(!saved){
    if(previous&&originalDraft)drafts.set(key,originalDraft);
    else drafts.delete(key);
  }
  activeEditor=null;updateSummary();
}
function renderCart(){
  selected=new Set([...selected].filter(key=>cartRows.some(row=>rowKey(row)===key)));
  byId('cartItems').setAttribute('aria-busy',String(refreshing||cartBusy));
  byId('cartEntryCount').textContent=cartRows.length;
  byId('cartItems').innerHTML=cartRows.length?cartRows.map(row=>{
    const key=rowKey(row),canSelect=eligible(row),value=effective(row),editable=inlineEditable(row);
    const warning=!stockVerified(row)?'Availability could not be verified. Refresh before checkout.':Number(row.available_quantity)<1||row.status!=='available'?'Currently unavailable. Refresh availability or remove this entry.':!canSelect?'Correct the quantity or use Edit dates to complete this entry before checkout.':'';
    return '<article class="item-row cart-row'+(selected.has(key)?' is-selected':'')+'" data-cart-row="'+esc(key)+'">'+
      '<input class="form-check-input" type="checkbox" data-select="'+esc(key)+'" aria-label="Select '+esc(row.equipment_name)+' on '+esc(row.borrow_date||'date to be chosen')+'" '+(selected.has(key)?'checked':'')+' '+(!canSelect&&!selected.has(key)?'disabled':'')+'>'+
      '<span class="cart-equipment-icon">'+cartIcon('box')+'</span><div class="grow"><h3>'+esc(row.equipment_name||'Equipment #'+row.equipment_id)+'</h3>'+
      '<div class="cart-badges"><span class="cart-badge">Available: '+esc(row.available_quantity||0)+'</span><span class="cart-badge">Borrowing Limit: '+esc(row.borrowing_time_limit_days||'?')+' days</span></div>'+
      '<div class="cart-dates"><span>Pickup <strong><time datetime="'+esc(row.borrow_date||'')+'">'+esc(displayDate(row.borrow_date))+'</time></strong></span><span>Return by <strong><time datetime="'+esc(row.expected_return_date||'')+'">'+esc(displayDate(row.expected_return_date))+'</time></strong></span></div>'+
      '<button class="cart-date-edit" type="button" data-modify="'+esc(key)+'">Edit dates</button>'+
      (warning?'<p class="cart-warning" role="alert">'+esc(warning)+'</p>':'')+'<p class="cart-warning" id="quantity-error-'+esc(key)+'" data-quantity-error="'+esc(key)+'" role="status" hidden></p></div>'+
      '<div class="cart-item-tools"><div><label class="cart-quantity-label" for="quantity-'+esc(key)+'">Quantity</label><div class="cart-stepper">'+
      '<button type="button" data-quantity-step="-1" data-entry="'+esc(key)+'" aria-label="Decrease quantity for '+esc(row.equipment_name)+'" '+(!editable||Number(value.quantity)<=1?'disabled':'')+'>−</button>'+
      '<input type="number" id="quantity-'+esc(key)+'" data-quantity="'+esc(key)+'" value="'+esc(value.quantity)+'" min="1" max="'+esc(row.available_quantity||0)+'" step="1" inputmode="numeric" aria-label="Quantity for '+esc(row.equipment_name)+' on '+esc(row.borrow_date||'date to be chosen')+'" aria-describedby="quantity-error-'+esc(key)+'" '+(!editable?'disabled':'')+'>'+
      '<button type="button" data-quantity-step="1" data-entry="'+esc(key)+'" aria-label="Increase quantity for '+esc(row.equipment_name)+'" '+(!editable||Number(value.quantity)>=Number(row.available_quantity)?'disabled':'')+'>+</button></div></div>'+
      '<button class="cart-icon-button cart-remove" type="button" data-remove="'+esc(key)+'" title="Remove '+esc(row.equipment_name)+'" aria-label="Remove '+esc(row.equipment_name)+' on '+esc(row.borrow_date||'date to be chosen')+'">'+cartIcon('trash')+'</button></div></article>';
  }).join(''):'<div class="cart-empty"><span class="cart-empty-icon">'+cartIcon('bag')+'</span><h3>Your cart is currently empty</h3><p>Find the right equipment for your next project. Add a few items and we’ll help you plan your pickup.</p><a class="btn btn-primary" href="equipment.php">Browse Equipment'+cartIcon('arrow')+'</a></div>';
  byId('previousCartOffer').hidden=!BorrowingCart.isServer()||!previousRows.length;
  byId('previousCartHint').textContent='Your previous browser cart is preserved. Choose quantity and dates for each entry before importing.';
  byId('previousCartItems').innerHTML=previousRows.map(row=>'<article class="item-row"><div class="grow"><h3>'+esc(row.equipment_name||'Equipment #'+row.equipment_id)+'</h3><div class="meta"><strong>Selected quantity: '+esc(effective(row,true).quantity)+'</strong> · Available: '+esc(row.available_quantity||0)+' · Limit: '+esc(row.borrowing_time_limit_days||'?')+' days</div></div><div class="actions"><button class="btn btn-sm btn-outline-primary" type="button" data-modify="'+esc(previousKey(row))+'">Modify</button></div></article>').join('');
  updateSummary();
}
function updateSummary(){
  const rows=chosen(),error=selectionError(rows),count=rows.reduce((sum,row)=>{const quantity=Number(effective(row).quantity);return sum+(Number.isSafeInteger(quantity)&&quantity>0?quantity:0);},0);
  const summary=rows.map(row=>'<div class="cart-summary-item"><span>'+esc(row.equipment_name)+'<small>Pickup <time datetime="'+esc(row.borrow_date||'')+'">'+esc(displayDate(row.borrow_date))+'</time><br>Return by <time datetime="'+esc(row.expected_return_date||'')+'">'+esc(displayDate(row.expected_return_date))+'</time></small></span><strong>× '+esc(effective(row).quantity||'—')+'</strong></div>').join('');
  byId('selectionSummary').innerHTML=(summary||'<p class="cart-summary-empty">Select equipment to build your borrowing request.</p>')+(rows.length&&error?'<p class="cart-warning" role="alert">'+esc(error)+'</p>':'');
  byId('checkoutItems').innerHTML=summary;
  byId('totalItems').textContent=rows.length;byId('totalQuantity').textContent=count;
  byId('cartSelectedCount').textContent=rows.length+' selected';
  const mutating=refreshing||submitting||cartBusy;const busy=mutating||!!activeEditor||editorOpening;const locked=busy||!!checkoutAttempt;const available=cartRows.filter(eligible);
  byId('selectAll').disabled=locked||!available.length;byId('selectAll').checked=available.length>0&&available.every(row=>selected.has(rowKey(row)));byId('selectAll').indeterminate=rows.length>0&&!byId('selectAll').checked;
  byId('checkoutBtn').disabled=locked||!!error||count<1;
  byId('checkoutHint').textContent=checkoutAttempt?'Retry your previous submission to confirm its result.':busy?'Please wait until your cart changes are saved.':!rows.length?'Select at least one available item to continue.':error||'Review your selection before submitting your request.';
  byId('submitCart').disabled=locked||!!error||!checkoutAvailable;
  byId('deleteSelected').disabled=locked||!rows.length;
  byId('deleteSelected').setAttribute('aria-label','Delete '+rows.length+' selected cart '+(rows.length===1?'entry':'entries'));
  byId('importPreviousCart').disabled=locked;
  byId('retryCart').hidden=!checkoutAvailable||!checkoutAttempt;byId('retryCart').disabled=busy;
  const pickup=byId('pickupDate'),dates=new Set(rows.map(row=>row.borrow_date));
  pickup.disabled=locked||!rows.length||!pickupBounds;pickup.min=pickupBounds?.min_date||'';pickup.max=pickupBounds?.max_date||'';
  if(!pickupDirty)pickup.value=dates.size===1?(rows[0].borrow_date||''):'';
  const validPickup=BorrowingDetails.dateValid(pickup.value)&&!!pickupBounds&&pickup.value>=pickupBounds.min_date&&pickup.value<=pickupBounds.max_date;
  byId('applyPickupDate').disabled=locked||!rows.length||!validPickup||rows.every(row=>row.borrow_date===pickup.value)||rows.some(row=>drafts.has(rowKey(row))||!eligible(row));
  byId('resetPickupDate').hidden=!pickupDirty;byId('resetPickupDate').disabled=locked;
  byId('pickupDateHint').textContent=!rows.length?'Select equipment to choose a pickup date.':pickupDirty&&!validPickup?'Choose a pickup date today through 7 days ahead.':dates.size>1&&!pickupDirty?'Your selection has different pickup dates. Apply one date to all, or keep each entry’s dates.':'Apply to selected keeps each entry’s borrowing duration. Pickup is available today through 7 days ahead.';
  document.querySelectorAll('[data-cart-edit] fieldset').forEach(fieldset=>{fieldset.disabled=mutating||!!checkoutAttempt;});
  document.querySelectorAll('[data-modify], [data-remove]').forEach(button=>{button.disabled=locked;});
  document.querySelectorAll('[data-select]').forEach(input=>{const row=cartRows.find(row=>rowKey(row)===input.dataset.select);input.disabled=locked||!row||(!eligible(row)&&!selected.has(input.dataset.select));input.closest?.('[data-cart-row]')?.classList.toggle('is-selected',selected.has(input.dataset.select));});
  document.querySelectorAll('[data-quantity]').forEach(input=>{const row=cartRows.find(row=>rowKey(row)===input.dataset.quantity);input.disabled=locked||!row||!inlineEditable(row);});
  document.querySelectorAll('[data-quantity-step]').forEach(button=>{const row=cartRows.find(row=>rowKey(row)===button.dataset.entry),value=row?Number(effective(row).quantity):0;button.disabled=locked||!row||!inlineEditable(row)||(Number(button.dataset.quantityStep)<0?value<=1:value>=Number(row.available_quantity));});
  byId('refreshCart').disabled=busy;
  byId('cartItems').setAttribute('aria-busy',String(mutating));
  if((!rows.length||rows.some(row=>drafts.has(rowKey(row))))&&!checkoutAttempt)byId('checkout').hidden=true;
}
async function refreshCart(){
  if(refreshing||submitting||cartBusy||activeEditor)return;
  refreshing=true;updateSummary();byId('refreshCart').disabled=true;verified.clear();
  try{
    cartRows=await BorrowingCart.reload();previousRows=BorrowingCart.isServer()?BorrowingCart.previous():[];
    const ids=[...new Set([...cartRows,...previousRows].map(row=>String(row.equipment_id)))];
    const [windowResult,...stockResults]=await Promise.allSettled([Api.getPickupWindow(),...ids.map(id=>Api.getEquipment(id))]);
    if(windowResult.status==='fulfilled'&&BorrowingDetails.dateValid(windowResult.value.data.min_date)&&windowResult.value.data.max_date===BorrowingDetails.dateAfter(windowResult.value.data.min_date,7))pickupBounds=windowResult.value.data;else pickupBounds=null;
    const stock=new Map();stockResults.forEach((result,index)=>{if(result.status==='fulfilled'){stock.set(ids[index],result.value.data);verified.add(ids[index]);}});
    const refresh=row=>({...row,...(stock.get(String(row.equipment_id))||{status:'unavailable',available_quantity:0}),quantity:row.quantity,borrow_date:row.borrow_date,expected_return_date:row.expected_return_date,cart_item_id:row.cart_item_id});
    cartRows=cartRows.map(refresh);previousRows=previousRows.map(refresh);BorrowingCart.write(cartRows);
    message(pickupBounds&&stock.size===ids.length?'Choose your equipment and pickup dates. Stock is reserved only when you submit your request.':'Some availability or date information could not be verified. Refresh before checkout.',!pickupBounds||stock.size!==ids.length);
  }catch(error){pickupBounds=null;message(error.message,true);}
  finally{refreshing=false;byId('refreshCart').disabled=false;drafts.forEach((_,key)=>{if(!key.startsWith('previous:'))drafts.delete(key);});pickupDirty=false;renderCart();}
}
function stageQuantity(input){
  const key=input.dataset.quantity,row=cartRows.find(row=>rowKey(row)===key);if(!row)return;
  const value={...details(row),quantity:input.value},errors=BorrowingDetails.validate(value,row,pickupBounds);
  if(input.value===String(row.quantity))drafts.delete(key);else drafts.set(key,{details:value,errors});
  const box=byId('quantity-error-'+key);box.textContent=errors.quantity||'';box.hidden=!errors.quantity;
  input.setAttribute('aria-invalid',String(!!errors.quantity));updateSummary();
}
async function saveQuantity(key,quantity){
  if(refreshing||submitting||cartBusy||activeEditor||editorOpening||checkoutAttempt)return;
  const row=cartRows.find(row=>rowKey(row)===key);if(!row||!inlineEditable(row))return;
  const value={...details(row),quantity},errors=BorrowingDetails.validate(value,row,pickupBounds);
  if(Object.keys(errors).length){message(Object.values(errors)[0],true);return;}
  if(Number(quantity)===Number(row.quantity)){drafts.delete(key);updateSummary();return;}
  cartBusy=true;updateSummary();
  try{
    const [stock,window]=await Promise.all([Api.getEquipment(row.equipment_id),Api.getPickupWindow()]);
    Object.assign(row,stock.data);pickupBounds=window.data;verified.add(String(row.equipment_id));
    const currentErrors=BorrowingDetails.validate(value,row,pickupBounds);
    if(Object.keys(currentErrors).length)throw new Error(Object.values(currentErrors)[0]);
    await BorrowingCart.update(key,{...value,quantity:Number(quantity)});cartRows=BorrowingCart.read();
    message('Quantity updated for '+row.equipment_name+'.');
  }catch(error){message(error.message,true);}
  finally{drafts.delete(key);cartBusy=false;renderCart();document.querySelector('[data-quantity="'+key+'"]')?.focus();}
}
async function removeEntries(keys){
  if(refreshing||submitting||cartBusy||activeEditor||editorOpening||checkoutAttempt||!keys.length)return;
  cartBusy=true;updateSummary();let removed=0;
  try{
    // The existing adapter writes the returned cart after each request; serialize deletions.
    for(const key of keys){await BorrowingCart.remove(key);selected.delete(key);drafts.delete(key);removed++;}
    message(removed+' cart '+(removed===1?'entry removed.':'entries removed.'));
  }catch(error){
    message((removed?removed+' entries removed. ':'')+error.message+' Refresh to confirm the remaining cart.',true);
    try{await BorrowingCart.reload();}catch(_){}
  }finally{cartRows=BorrowingCart.read();cartBusy=false;pickupDirty=false;renderCart();(cartRows.length?byId('selectAll'):document.querySelector('.cart-empty a'))?.focus();}
}
async function applySelectedPickup(){
  if(refreshing||submitting||cartBusy||activeEditor||editorOpening||checkoutAttempt)return;
  const rows=chosen(),pickup=byId('pickupDate').value;if(!rows.length||rows.some(row=>drafts.has(rowKey(row))))return;
  if(rows.some(row=>!eligible(row))){message('Correct each selected entry’s quantity and dates before applying a pickup date.',true);return;}
  if(!BorrowingDetails.dateValid(pickup)||!pickupBounds||pickup<pickupBounds.min_date||pickup>pickupBounds.max_date){message('Choose a pickup date today through 7 days ahead.',true);return;}
  const changes=rows.filter(row=>row.borrow_date!==pickup).map(row=>{
    const duration=(Date.parse(row.expected_return_date+'T00:00:00Z')-Date.parse(row.borrow_date+'T00:00:00Z'))/86400000;
    return {row,key:rowKey(row),value:{quantity:row.quantity,borrow_date:pickup,expected_return_date:BorrowingDetails.dateAfter(pickup,duration)}};
  });
  // Refuse date collisions before any writes; entries keep their independent IDs.
  for(const change of changes){
    const signature=BorrowingCart.signature({...change.row,...change.value});
    if(cartRows.some(row=>rowKey(row)!==change.key&&BorrowingCart.signature(row)===signature)||changes.some(other=>other.key!==change.key&&BorrowingCart.signature({...other.row,...other.value})===signature)){
      message('This pickup date would duplicate another entry for '+change.row.equipment_name+'. Edit that entry’s dates individually.',true);return;
    }
  }
  if(!changes.length)return;
  cartBusy=true;updateSummary();let saved=0;
  try{
    const ids=[...new Set(changes.map(change=>String(change.row.equipment_id)))];
    const [window,...stocks]=await Promise.all([Api.getPickupWindow(),...ids.map(id=>Api.getEquipment(id))]);pickupBounds=window.data;
    const currentStock=new Map(stocks.map((result,index)=>[ids[index],result.data]));
    for(const change of changes){const errors=BorrowingDetails.validate(change.value,currentStock.get(String(change.row.equipment_id)),pickupBounds);if(Object.keys(errors).length)throw new Error(change.row.equipment_name+': '+Object.values(errors)[0]);}
    for(const change of changes){await BorrowingCart.update(change.key,change.value);saved++;}
    message('Pickup date updated for '+saved+' selected '+(saved===1?'entry.':'entries.')+' Each borrowing duration has been preserved.');
  }catch(error){
    message((saved?saved+' pickup dates saved. ':'')+error.message+' Review the remaining entries before retrying.',true);
    try{await BorrowingCart.reload();}catch(_){}
  }finally{cartRows=BorrowingCart.read();cartBusy=false;pickupDirty=false;renderCart();byId('pickupDate').focus();}
}
function resolveEditor(key){if(key.startsWith('previous:'))return {row:previousRows.find(row=>previousKey(row)===key),previous:true};return {row:cartRows.find(row=>rowKey(row)===key),previous:false};}
function readEditor(form){return {quantity:form.querySelector('[name="quantity"]').value,borrow_date:form.querySelector('[name="borrow_date"]').value,expected_return_date:form.querySelector('[name="expected_return_date"]').value};}
function validateEditor(form){
  const key=form.dataset.cartEdit,{row}=resolveEditor(key);if(!row)return null;const value=readEditor(form),errors=BorrowingDetails.validate(value,row,pickupBounds);
  if(!stockVerified(row))errors.quantity='Refresh to verify this equipment’s availability.';
  drafts.set(key,{details:value,errors});
  for(const name of ['quantity','borrow_date','expected_return_date']){const box=form.querySelector('[data-error="'+name+'"]');box.textContent=errors[name]||'';box.classList.toggle('show',!!errors[name]);form.querySelector('[name="'+name+'"]').setAttribute('aria-invalid',String(!!errors[name]));}
  const bounds=dateBounds(row,value.borrow_date),returned=form.querySelector('[name="expected_return_date"]');returned.min=bounds.min;returned.max=bounds.max;form.querySelector('[name="quantity"]').max=Number(row.available_quantity)||0;form.querySelector('[name="borrow_date"]').min=pickupBounds?.min_date||'';form.querySelector('[name="borrow_date"]').max=pickupBounds?.max_date||'';const general=form.querySelector('[data-general-error]');general.textContent='';general.classList.remove('show');updateSummary();return {row,value,errors};
}
async function saveEntry(event){
  const form=event.target.closest('[data-cart-edit]');if(!form)return;event.preventDefault();
  if(refreshing||submitting||cartBusy||checkoutAttempt||!activeEditor||form.dataset.cartEdit!==activeEditor.key)return;
  const key=activeEditor.key,previous=activeEditor.previous;
  const validated=validateEditor(form);
  if(!validated){form.querySelector('[data-general-error]').textContent='This entry was removed. Cancel and refresh your cart.';form.querySelector('[data-general-error]').classList.add('show');return;}
  if(Object.keys(validated.errors).length){message('Correct the highlighted quantity and date fields.',true);form.querySelector('[name="'+Object.keys(validated.errors)[0]+'"]').focus();return;}
  cartBusy=true;updateSummary();byId('modifySave').textContent='Saving…';
  try{
    // Recheck stock, equipment limits, and local calendar bounds immediately before saving.
    const [stock,window]=await Promise.all([Api.getEquipment(validated.row.equipment_id),Api.getPickupWindow()]);
    Object.assign(validated.row,stock.data);pickupBounds=window.data;verified.add(String(validated.row.equipment_id));
    const checked=validateEditor(form);
    if(Object.keys(checked.errors).length){message('Availability or borrowing dates changed. Correct the highlighted fields.',true);return;}
    const value={...checked.value,quantity:Number(checked.value.quantity)};
    if(previous)drafts.set(key,{details:value,errors:{}});
    else{
      await BorrowingCart.update(rowKey(checked.row),value);
      drafts.delete(key);cartRows=BorrowingCart.read();
      // Local browser entries derive their key from dates; preserve selection when that key changes.
      if(selected.has(key)){selected.delete(key);selected.add(rowKey({...checked.row,...value}));}
    }
    activeEditor.saved=true;cartBusy=false;modifyModal().hide();
    message(previous?'Borrowing details saved for import.':'Borrowing details updated.');
    toast(previous?'Borrowing details saved for import.':'Borrowing details updated.');renderCart();
  }catch(error){
    const box=form.querySelector('[data-general-error]');box.textContent=error.message;box.classList.add('show');message(error.message,true);toast(error.message,'bad');
  }finally{cartBusy=false;byId('modifySave').textContent='Save Changes';updateSummary();}
}
const attemptKey=()=> 'equipment-desk:checkout:v1:'+CURRENT_USER.user_id;
function persistAttempt(){if(checkoutAttempt)localStorage.setItem(attemptKey(),JSON.stringify(checkoutAttempt));else localStorage.removeItem(attemptKey());}
function confirmCheckout(result,rows){
  const dated=rows.every(row=>row.cart_item_id!=null);const acknowledgments=dated?result?.confirmed_cart_item_ids:result?.confirmed_equipment_ids;const field=dated?'cart_item_id':'equipment_id';
  if(result?.success!==true||!Array.isArray(acknowledgments)||!rows.every(row=>acknowledgments.map(String).includes(String(row[field]))))throw new Error('Submission was not fully confirmed. Your cart has been kept.');
  checkoutAttempt=null;try{persistAttempt();}catch(_){}updateSummary();
}
const CartCheckoutGateway={async submit(payload){
  if(!checkoutAvailable||!BorrowingCart.isServer())throw new Error('Server checkout is unavailable. Your cart is preserved.');const fingerprint=JSON.stringify(payload);
  if(checkoutAttempt&&checkoutAttempt.fingerprint!==fingerprint)throw new Error('Retry the previous unconfirmed submission before starting another checkout.');
  if(!checkoutAttempt){checkoutAttempt={fingerprint,payload,key:crypto.randomUUID().replaceAll('-','')};try{persistAttempt();}catch(_){checkoutAttempt=null;throw new Error('Writable browser storage is required for safe retries. No request was sent.');}}
  try{return await Api.checkoutCart({...payload,idempotency_key:checkoutAttempt.key});}
  catch(error){if([400,404].includes(error.status)||(error.status===409&&['INSUFFICIENT_STOCK','CART_CHANGED'].includes(error.code))){checkoutAttempt=null;try{persistAttempt();}catch(_){}}throw error;}
}};
async function preflight(rows){
  const ids=[...new Set(rows.map(row=>String(row.equipment_id)))];const [windowResult,cartResult,...equipment]=await Promise.all([Api.getPickupWindow(),Api.getCart(),...ids.map(id=>Api.getEquipment(id))]);pickupBounds=windowResult.data;
  const fresh=new Map(equipment.map((result,index)=>[ids[index],result.data]));const totals=new Map();
  for(const row of rows){
    const saved=cartResult.data.find(item=>String(item.cart_item_id)===String(row.cart_item_id));
    if(!saved||saved.quantity<row.quantity||saved.borrow_date!==row.borrow_date||saved.expected_return_date!==row.expected_return_date)throw new Error('Selected quantities or dates changed. Refresh your cart.');
    const stock=fresh.get(String(row.equipment_id));const errors=BorrowingDetails.validate(details(row),stock,pickupBounds);if(Object.keys(errors).length)throw new Error(row.equipment_name+': '+Object.values(errors)[0]);
    const id=String(row.equipment_id),total=(totals.get(id)||0)+Number(row.quantity);totals.set(id,total);if(total>Number(stock.available_quantity))throw new Error('Combined selected quantity exceeds available stock for '+row.equipment_name+'.');
  }
}
async function finishSubmission(result,rows){
  confirmCheckout(result,rows);selected.clear();rows.forEach(row=>drafts.delete(String(row.cart_item_id)));
  try{cartRows=await BorrowingCart.reload();}catch(_){message('Requests submitted. Refresh to reload your remaining cart.',true);renderCart();return;}
  renderCart();message('Borrowing requests submitted. Review their quantities, dates, and statuses in My Borrowings.');byId('checkoutStatus').textContent='Borrowing requests submitted for staff approval.';
}
async function submitCart(event){
  event.preventDefault();if(submitting||refreshing||cartBusy||activeEditor||editorOpening||checkoutAttempt)return;const error=selectionError();if(error){message(error,true);return;}
  const rows=chosen().map(row=>({...row}));const payload={items:rows.map(row=>({cart_item_id:row.cart_item_id,equipment_id:row.equipment_id,requested_quantity:row.quantity,borrow_date:row.borrow_date,expected_return_date:row.expected_return_date}))};
  submitting=true;updateSummary();byId('submitCart').textContent='Submitting…';
  try{await preflight(rows);const result=await CartCheckoutGateway.submit(payload);await finishSubmission(result,payload.items);}
  catch(error){byId('checkoutStatus').textContent=error.message;message(error.message,true);}
  finally{submitting=false;byId('submitCart').textContent='Submit Borrowing Request';updateSummary();}
}
async function retryCheckout(){
  if(!checkoutAttempt||submitting||refreshing||cartBusy||activeEditor||editorOpening)return;const payload=checkoutAttempt.payload;submitting=true;updateSummary();
  try{const result=await CartCheckoutGateway.submit(payload);await finishSubmission(result,payload.items);}
  catch(error){byId('checkoutStatus').textContent=error.message;message(error.message,true);}
  finally{submitting=false;updateSummary();}
}
(async function initCart(){
  await requireSession(['customer']);
  try{const capabilities=await BorrowingCart.initialize();checkoutAvailable=capabilities.checkout_available;byId('cartAvailability').textContent=capabilities.message||'Server checkout is currently unavailable. Your cart is preserved.';byId('cartAvailability').hidden=!!capabilities.checkout_available;try{checkoutAttempt=JSON.parse(localStorage.getItem(attemptKey())||'null');}catch(_){}if(checkoutAttempt){byId('checkout').hidden=false;byId('checkoutStatus').textContent='Retry your previous submission to confirm its result.';}}
  catch(error){byId('cartAvailability').textContent=error.message;byId('cartAvailability').hidden=false;message(error.message,true);}
  const inputChanged=event=>{if(refreshing||submitting||cartBusy||checkoutAttempt)return;const form=event.target.closest('[data-cart-edit]');if(form)validateEditor(form);};
  byId('modifyBody').addEventListener('input',inputChanged);byId('modifyBody').addEventListener('change',inputChanged);byId('modifyBody').addEventListener('submit',saveEntry);
  byId('modifyModal').addEventListener('hide.bs.modal',discardEditor);
  byId('modifyModal').addEventListener('hidden.bs.modal',()=>{byId('modifyBody').innerHTML='';const button=document.querySelector('[data-modify="'+editorReturnKey+'"]');(button||byId('refreshCart')).focus();editorReturnKey=null;});
  byId('modifyModal').addEventListener('shown.bs.modal',()=>{document.querySelector('#modifyModal [name="quantity"]')?.focus();});
  const modifyClicked=event=>{const button=event.target.closest('[data-modify]');if(button)openModifier(button.dataset.modify);};
  byId('cartItems').addEventListener('click',modifyClicked);byId('previousCartItems').addEventListener('click',modifyClicked);
  byId('cartItems').addEventListener('input',event=>{if(refreshing||submitting||cartBusy||activeEditor||editorOpening||checkoutAttempt)return;if(event.target.dataset.quantity)stageQuantity(event.target);});
  byId('cartItems').addEventListener('change',event=>{
    if(refreshing||submitting||cartBusy||activeEditor||editorOpening||checkoutAttempt)return;
    if(event.target.dataset.quantity){stageQuantity(event.target);saveQuantity(event.target.dataset.quantity,event.target.value);}
    if(event.target.dataset.select){const key=event.target.dataset.select,row=cartRows.find(row=>rowKey(row)===key);if(event.target.checked&&row&&eligible(row))selected.add(key);else selected.delete(key);pickupDirty=false;updateSummary();}
  });
  byId('cartItems').addEventListener('click',event=>{
    const remove=event.target.closest('[data-remove]');if(remove){removeEntries([remove.dataset.remove]);return;}
    const step=event.target.closest('[data-quantity-step]');if(!step||step.disabled)return;
    const row=cartRows.find(row=>rowKey(row)===step.dataset.entry);if(!row)return;
    const quantity=Number(effective(row).quantity)+Number(step.dataset.quantityStep);saveQuantity(rowKey(row),String(quantity));
  });
  byId('deleteSelected').addEventListener('click',()=>removeEntries(chosen().map(rowKey)));
  byId('dismissCartBanner').addEventListener('click',()=>{byId('cartBanner').hidden=true;});
  byId('pickupDate').addEventListener('input',()=>{pickupDirty=true;updateSummary();});
  byId('pickupDate').addEventListener('change',()=>{pickupDirty=true;updateSummary();});
  byId('applyPickupDate').addEventListener('click',applySelectedPickup);
  byId('resetPickupDate').addEventListener('click',()=>{if(refreshing||submitting||cartBusy||activeEditor||editorOpening||checkoutAttempt)return;pickupDirty=false;updateSummary();});
  byId('selectAll').addEventListener('change',event=>{if(refreshing||submitting||cartBusy||activeEditor||editorOpening||checkoutAttempt)return;selected=event.target.checked?new Set(cartRows.filter(eligible).map(rowKey)):new Set();pickupDirty=false;renderCart();});
  byId('checkoutBtn').addEventListener('click',async()=>{
    if(refreshing||submitting||cartBusy||activeEditor||editorOpening||checkoutAttempt||!validSelection())return;
    await refreshCart();if(!validSelection())return;byId('checkout').hidden=false;byId('checkoutStatus').textContent='Review the saved quantity and dates for every selected entry. Stock is reserved only after submission.';byId('checkoutTitle').focus();
  });
  byId('checkoutForm').addEventListener('submit',submitCart);byId('retryCart').addEventListener('click',retryCheckout);byId('refreshCart').addEventListener('click',refreshCart);
  byId('importPreviousCart').addEventListener('click',async()=>{
    if(refreshing||submitting||cartBusy||checkoutAttempt)return;
    const rows=previousRows.map(row=>({...row,...effective(row,true)}));if(rows.some(row=>!stockVerified(row)||Object.keys(BorrowingDetails.validate(details(row),row,pickupBounds)).length)){message('Choose valid quantity and borrowing dates for every browser entry before importing.',true);return;}
    cartBusy=true;updateSummary();let error=null;
    try{await BorrowingCart.importPrevious(rows);previousRows.forEach(row=>drafts.delete(previousKey(row)));}catch(e){error=e;}
    finally{cartBusy=false;updateSummary();}await refreshCart();message(error?error.message:'Previous cart imported with its chosen dates.',!!error);
  });
  window.addEventListener('storage',event=>{if(event.key==='equipment-desk:cart:v1:'+CURRENT_USER.user_id)refreshCart();});
  await refreshCart();
})();
