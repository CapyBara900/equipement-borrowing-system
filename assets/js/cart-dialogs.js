/* Keep cart dialogs and mobile navigation usable if the Bootstrap CDN is offline. */
const CartDialogs=(()=>{
  if(window.bootstrap)return {modal:element=>bootstrap.Modal.getOrCreateInstance(element)};
  document.body.classList.add('cart-without-bootstrap');
  let active=null,returnFocus=null,previousOverflow='';
  const focusable=element=>[...element.querySelectorAll('a[href],button:not(:disabled),input:not(:disabled),[tabindex="0"]')].filter(node=>node.getClientRects().length);
  function hide(element){
    const event=new Event('hide.bs.modal',{cancelable:true});element.dispatchEvent(event);if(event.defaultPrevented)return;
    element.classList.remove('show');element.setAttribute('aria-hidden','true');element.removeAttribute('aria-modal');
    document.querySelector('.cart-dialog-backdrop')?.remove();document.body.style.overflow=previousOverflow;
    active=null;element.dispatchEvent(new Event('hidden.bs.modal'));returnFocus?.focus();
  }
  function show(element){
    if(active)return;returnFocus=document.activeElement;previousOverflow=document.body.style.overflow;active=element;
    const backdrop=document.createElement('div');backdrop.className='cart-dialog-backdrop';backdrop.addEventListener('click',()=>hide(element));document.body.appendChild(backdrop);
    element.classList.add('show');element.removeAttribute('aria-hidden');element.setAttribute('role','dialog');element.setAttribute('aria-modal','true');document.body.style.overflow='hidden';
    focusable(element)[0]?.focus();element.dispatchEvent(new Event('shown.bs.modal'));
  }
  document.addEventListener('click',event=>{
    const dismiss=event.target.closest('[data-bs-dismiss]');if(dismiss&&active){hide(active);return;}
    const menu=event.target.closest('[data-bs-toggle="offcanvas"]');if(menu){const element=document.querySelector(menu.dataset.bsTarget);if(element)show(element);}
    if(active&&event.target===active&&active.classList.contains('modal'))hide(active);
  });
  document.addEventListener('keydown',event=>{
    if(!active)return;if(event.key==='Escape'){event.preventDefault();hide(active);return;}
    if(event.key==='Tab'){const items=focusable(active),first=items[0],last=items[items.length-1];if(!first){event.preventDefault();return;}
      if(event.shiftKey&&(document.activeElement===first||!active.contains(document.activeElement))){event.preventDefault();last.focus();}
      else if(!event.shiftKey&&(document.activeElement===last||!active.contains(document.activeElement))){event.preventDefault();first.focus();}
    }
  });
  return {modal:element=>({show:()=>show(element),hide:()=>hide(element)})};
})();
