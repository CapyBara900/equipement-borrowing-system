/* ==========================================================================
   app.js — shared helpers loaded on every signed-in page.
   ========================================================================== */

let CURRENT_USER = null;

/* ---------- XSS-safe rendering -------------------------------------------
   Everything from the database goes through this before being put into the
   DOM. The backend strips tags on the way in; this escapes on the way out.
   -------------------------------------------------------------------------- */
function esc(value) {
  if (value === null || value === undefined) return '';
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

/* ---------- Toasts -------------------------------------------------------- */

function toast(message, kind = 'ok') {
  let zone = document.getElementById('toastZone');
  if (!zone) {
    zone = document.createElement('div');
    zone.id = 'toastZone';
    document.body.appendChild(zone);
  }
  const el = document.createElement('div');
  el.className = 'toast-msg' + (kind === 'bad' ? ' bad' : '');
  el.setAttribute('role', 'status');
  el.textContent = message;
  zone.appendChild(el);
  setTimeout(() => el.remove(), 4000);
}

/* ---------- Navigation ---------------------------------------------------- */

// Authentication forms also load app.js; signed-in pages load sidebar.js first.
const NAV_LINKS = globalThis.EquipmentSidebar?.links || [];

function navMarkup(user, unreadCount) {
  return EquipmentSidebar.markup(user, {
    cartCount: user.role === 'customer' ? BorrowingCart.read().length : 0,
    unreadCount,
  });
}

async function renderShell(user) {
  let unread = 0;
  if (user.role === 'customer') {
    try {
      const res = await Api.listNotifications({ unread_only: '1' });
      unread = res.data.length;
    } catch (e) { /* notifications are non-critical chrome */ }
  }

  const markup = navMarkup(user, unread);
  for (const container of [document.querySelector('.rail'), document.querySelector('.rail-canvas .offcanvas-body')]) {
    if (!container) continue;
    container.classList.add('sidebar');
    container.innerHTML = markup;
    EquipmentSidebar.bindSignOut(container, () => Api.logout());
  }
}

/* ---------- Auth guard ----------------------------------------------------
   Every protected page calls this first. If there's no valid session the
   browser goes back to the sign-in screen. The backend enforces this too —
   this is just so people don't stare at an empty page.
   -------------------------------------------------------------------------- */
async function requireSession(allowedRoles = null, { redirectOnError = true } = {}) {
  try {
    const res = await Api.me();
    CURRENT_USER = res.data;
  } catch (err) {
    if (redirectOnError || err.status === 401) location.href = 'index.html';
    throw err;
  }
  const staffPageAllowed = CURRENT_USER.role !== 'staff'
    || ['dashboard.html', 'requests.html', 'profile.php'].includes(location.pathname.split('/').pop());
  if (!staffPageAllowed || (allowedRoles && !allowedRoles.includes(CURRENT_USER.role))) {
    document.querySelector('.main').innerHTML =
      `<div class="empty"><strong>This page isn't available for your account</strong>
       Ask an administrator if you think you should have access.</div>`;
    await renderShell(CURRENT_USER);
    throw new Error('role not permitted');
  }
  if (CURRENT_USER.role === 'customer') {
    try { await BorrowingCart.initialize(); }
    catch (error) { toast('Could not load your saved cart. ' + error.message, 'bad'); }
  }
  await renderShell(CURRENT_USER);
  return CURRENT_USER;
}

/* ---------- Form validation ----------------------------------------------
   Rules are declared per field; showError/clearError drive the inline
   messages. Runs on submit and on blur.
   -------------------------------------------------------------------------- */

function showError(input, message) {
  input.classList.add('invalid');
  input.setAttribute('aria-invalid', 'true');
  const box = document.querySelector(`[data-error-for="${input.id}"]`);
  if (box) { box.textContent = message; box.classList.add('show'); box.setAttribute('role', 'alert'); box.setAttribute('aria-live', 'polite'); }
}

function clearError(input) {
  input.classList.remove('invalid');
  input.removeAttribute('aria-invalid');
  const box = document.querySelector(`[data-error-for="${input.id}"]`);
  if (box) { box.textContent = ''; box.classList.remove('show'); box.removeAttribute('role'); box.removeAttribute('aria-live'); }
}

/**
 * rules: { fieldId: [ {test: fn, message: str}, ... ] }
 * Returns true when every field passes.
 */
function validate(rules) {
  let ok = true;
  let firstBad = null;

  Object.entries(rules).forEach(([id, checks]) => {
    const input = document.getElementById(id);
    if (!input) return;
    clearError(input);
    const value = validationValue(input);

    for (const check of checks) {
      if (!check.test(value, input)) {
        showError(input, check.message);
        ok = false;
        if (!firstBad) firstBad = input;
        break;
      }
    }
  });

  if (firstBad) firstBad.focus();
  return ok;
}

const Rules = {
  required: (label) => ({
    test: v => v.length > 0,
    message: `${label} is required.`,
  }),
  email: () => ({
    test: v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v),
    message: 'Enter a valid email address, like name@school.edu.',
  }),
  minLength: (n, label) => ({
    test: v => v.length >= n,
    message: `${label} must be at least ${n} characters.`,
  }),
  maxLength: (n, label) => ({
    test: v => v.length <= n,
    message: `${label} must be ${n} characters or fewer.`,
  }),
  passwordMinLength: () => ({
    test: v => v.length >= 12,
    message: 'Password must be at least 12 characters.',
  }),
  passwordNoSpaces: () => ({
    test: v => !/\s/.test(v),
    message: 'Password must not contain spaces.',
  }),
  passwordUppercase: () => ({
    test: v => /[A-Z]/.test(v),
    message: 'Password must contain at least one uppercase letter.',
  }),
  passwordLowercase: () => ({
    test: v => /[a-z]/.test(v),
    message: 'Password must contain at least one lowercase letter.',
  }),
  passwordNumber: () => ({
    test: v => /[0-9]/.test(v),
    message: 'Password must contain at least one number.',
  }),
  passwordSpecial: () => ({
    test: v => /[!@#$%^&*]/.test(v),
    message: 'Password must contain at least one special character (!, @, #, $, %, ^, &, or *).',
  }),
  passwordPersonalInfo: (nameId, emailId) => ({
    test: v => {
      const password = v.toLowerCase();
      const name = document.getElementById(nameId).value.toLowerCase();
      const email = document.getElementById(emailId).value.trim().toLowerCase();
      const localPart = email.split('@')[0];
      const nameParts = name.split(/[^a-z0-9]+/).filter(part => part.length >= 3);
      const nameWithoutSeparators = name.replace(/[^a-z0-9]+/g, '');
      return ![email, localPart, nameWithoutSeparators, ...nameParts]
        .filter(value => value.length >= 3)
        .some(value => password.includes(value));
    },
    message: 'Password must not contain your name, username, or email address.',
  }),
  matches: (otherId, label) => ({
    test: v => v === document.getElementById(otherId).value,
    message: `${label} do not match.`,
  }),
  notPastDate: (label) => ({
    test: v => {
      const today = new Date(); today.setHours(0, 0, 0, 0);
      return new Date(v) >= today;
    },
    message: `${label} cannot be in the past.`,
  }),
  onOrAfterField: (otherId, label) => ({
  test: v => new Date(v) >= new Date(document.getElementById(otherId).value),
  message: `${label} must be the same as or after the pick up date.`,
  }),

  // --- Registration-specific rules ---

  /**
   * Full name: 5–100 chars; letters (including accented), spaces, hyphens,
   * apostrophes, and periods only; no consecutive spaces; no repeated specials.
   */
  fullName: () => ({
    test: v => {
      if (v.length < 5 || v.length > 100) return false;
      if (!/^[A-Za-z\u00C0-\u00D6\u00D8-\u00F6\u00F8-\u00FF '\-.]+$/.test(v)) return false;
      if (/  /.test(v)) return false;          // consecutive spaces
      if (/--|''|\.\./.test(v)) return false;  // repeated specials
      return true;
    },
    message: "Full name must be 5\u2013100 characters and may only contain letters, spaces, hyphens (-), apostrophes ('), and periods (.). No consecutive spaces or repeated special characters.",
  }),

  /** Email must not contain spaces. */
  emailNoSpaces: () => ({
    test: v => !/\s/.test(v),
    message: 'Email address must not contain spaces.',
  }),

  /** Strict email format: exactly one @, non-empty local and domain parts,
   *  domain extension of at least 2 characters. */
  emailStrict: () => ({
    test: v => {
      const atCount = (v.match(/@/g) || []).length;
      if (atCount !== 1) return false;
      const [local, domain] = v.split('@');
      if (!local || !domain || local.startsWith('.') || local.endsWith('.') || local.includes('..')) return false;
      if (!/^[A-Za-z0-9._+-]+$/.test(local)) return false;
      if (domain.startsWith('.') || domain.endsWith('.') || domain.startsWith('-') || domain.endsWith('-') || domain.includes('..')) return false;
      const labels = domain.split('.');
      return labels.length >= 2
        && labels.every(label => /^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/.test(label))
        && /^[A-Za-z]{2,63}$/.test(labels[labels.length - 1]);
    },
    message: 'Enter a valid email address, like name@domain.com.',
  }),

  /** RFC 5321 maximum email length. */
  emailMaxLength: () => ({
    test: v => v.length <= 254,
    message: 'Email address must be 254 characters or fewer.',
  }),
  emailExactlyOneAt: () => ({
    test: v => (v.match(/@/g) || []).length === 1,
    message: 'Email address must contain exactly one @ symbol.',
  }),
  emailParts: () => ({
    test: v => {
      const [local, domain] = v.split('@');
      return Boolean(local && domain);
    },
    message: 'Email address must include a username before @ and a domain after it.',
  }),
};

/** Re-validate a single field when the person leaves it. */
function liveValidate(rules, buttonId = null) {
  const button = buttonId ? document.getElementById(buttonId) : null;

  const syncButton = () => {
    if (!button) return;
    button.disabled = !formIsValid(rules);
  };

  Object.keys(rules).forEach(id => {
    const input = document.getElementById(id);
    if (!input) return;

    const checkField = (showMessage) => {
      const value = validationValue(input);
      if (showMessage) clearError(input);
      for (const check of rules[id]) {
        if (!check.test(value, input)) {
          if (showMessage) showError(input, check.message);
          return false;
        }
      }
      if (showMessage) clearError(input);
      return true;
    };

    input.addEventListener('blur', () => checkField(true));
    input.addEventListener('input', () => {
      // Once a user starts correcting a field, update its error immediately.
      if (input.classList.contains('invalid')) checkField(true);
      syncButton();
    });
    input.addEventListener('change', () => {
      checkField(input.classList.contains('invalid'));
      syncButton();
    });
  });

  syncButton();
  return syncButton;
}

function formIsValid(rules) {
  return Object.entries(rules).every(([id, checks]) => {
    const input = document.getElementById(id);
    if (!input) return true;
    const value = validationValue(input);
    return checks.every(check => check.test(value, input));
  });
}

function validationValue(input) {
  return input.type === 'password' ? input.value : input.value.trim();
}

/* ---------- Small formatters ---------------------------------------------- */

function pill(status) {
  const label = String(status || '').replace(/_/g, ' ');
  return `<span class="pill p-${esc(status)}">${esc(label)}</span>`;
}

function fmtDate(value) {
  if (!value) return '—';
  const d = new Date(value.replace(' ', 'T'));
  if (isNaN(d)) return esc(value);
  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function emptyState(title, hint) {
  return `<div class="empty"><strong>${esc(title)}</strong>${esc(hint)}</div>`;
}

/** Debounce so typing in a search box doesn't fire a request per keystroke. */
function debounce(fn, wait = 300) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), wait); };
}

/* Temporary customer-scoped cart. No request or stock mutations. */
// Calendar arithmetic is shared by the direct form and dated cart editors.
const BorrowingDetails = {
  dateAfter(value,days) { const date=new Date(value+'T00:00:00Z');date.setUTCDate(date.getUTCDate()+days);return date.toISOString().slice(0,10); },
  dateValid(value) { return typeof value==='string' && /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(value) && Number.isFinite(Date.parse(value+'T00:00:00Z')) && this.dateAfter(value,0)===value; },
  validate(details,item,window) {
    const errors={};const q=Number(details.quantity);const pickup=details.borrow_date;const returned=details.expected_return_date;
    if (!/^[1-9][0-9]*$/.test(String(details.quantity)) || !Number.isSafeInteger(q) || q>2147483647) errors.quantity='Enter a whole quantity of at least 1.';
    else if (q>Number(item.available_quantity) || item.status!=='available') errors.quantity='Requested quantity exceeds the currently available stock.';
    if (!this.dateValid(pickup)) errors.borrow_date='Pick Up On is required and must be a valid date.';
    else if (!window || pickup<window.min_date || pickup>window.max_date) errors.borrow_date='Pick Up On must be today through 7 calendar days in advance.';
    if (!this.dateValid(returned)) errors.expected_return_date='Return By is required and must be a valid date.';
    else if (this.dateValid(pickup)) {
      if (returned<pickup) errors.expected_return_date='Return By cannot be earlier than Pick Up On.';
      else if (!Number.isInteger(Number(item.borrowing_time_limit_days)) || Number(item.borrowing_time_limit_days)<1 || returned>this.dateAfter(pickup,Number(item.borrowing_time_limit_days))) errors.expected_return_date='Return By exceeds this equipment’s borrowing time limit.';
    }
    return errors;
  },
};

const BorrowingCart = (() => {
  const key=()=> 'equipment-desk:cart:v1:'+CURRENT_USER.user_id;
  const signature=row=>[Number(row.equipment_id),row.borrow_date||'',row.expected_return_date||''].join('|');
  const entryKey=row=>String(row.cart_item_id || ('local-'+signature(row)));
  let serverRows=null,initialization=null;const fallback=new Map(),memoryOnly=new Set();
  function previous() {
    if (!CURRENT_USER || CURRENT_USER.role!=='customer') return [];
    try {
      const rows=memoryOnly.has(key())?(fallback.get(key())||[]):JSON.parse(localStorage.getItem(key())||'[]');if(!Array.isArray(rows))return [];
      const seen=new Set();return rows.filter(row=>row&&/^[1-9][0-9]*$/.test(String(row.equipment_id))&&Number.isSafeInteger(row.quantity)&&row.quantity>0&&!seen.has(signature(row))&&seen.add(signature(row))).map(row=>({...row,cart_item_id:entryKey(row)}));
    } catch (_) { return fallback.get(key())||[]; }
  }
  function read(){return serverRows===null?previous():serverRows.map(row=>({...row}));}
  function updateBadges(){document.querySelectorAll('[data-cart-count]').forEach(el=>{el.textContent=read().length;el.setAttribute('aria-label',read().length+' items in borrowing cart');});}
  function write(rows){if(serverRows!==null)serverRows=rows;else{fallback.set(key(),rows);try{localStorage.setItem(key(),JSON.stringify(rows));}catch(_){memoryOnly.add(key());toast('Browser storage is unavailable. Your cart lasts on this page.','bad');}}updateBadges();}
  async function initialize(){if(!initialization)initialization=(async()=>{const {data}=await Api.cartCapabilities();if(data.cart_available){const result=await Api.getCart();serverRows=result.data;updateBadges();}return data;})().catch(error=>{initialization=null;throw error;});return initialization;}
  async function reload(){if(serverRows!==null){const {data}=await Api.getCart();write(data);}return read();}
  async function add(item,details,window){
    const errors=BorrowingDetails.validate(details||{},item,window);if(Object.keys(errors).length)throw new Error(Object.values(errors)[0]);
    const body={equipment_id:item.equipment_id,quantity:Number(details.quantity),borrow_date:details.borrow_date,expected_return_date:details.expected_return_date};
    if(serverRows!==null){const {data}=await Api.addCartItem(body);write(data);}
    else {const rows=read();const existing=rows.find(row=>signature(row)===signature(body));const quantity=(existing?.quantity||0)+body.quantity;if(quantity>Number(item.available_quantity))throw new Error('Combined quantity for these dates exceeds available stock.');if(existing)Object.assign(existing,body,{quantity});else rows.push({...item,...body,cart_item_id:entryKey(body)});write(rows);}
    return true;
  }
  async function update(id,details){if(serverRows!==null){const {data}=await Api.updateCartItem(id,details);write(data);}else{const rows=read();const row=rows.find(r=>entryKey(r)===String(id));if(!row)throw new Error('Cart entry not found.');const changed={...row,...details};if(rows.some(r=>entryKey(r)!==String(id)&&signature(r)===signature(changed)))throw new Error('Another entry already uses those dates.');Object.assign(row,details);write(rows);}}
  async function remove(id){if(serverRows!==null){const {data}=await Api.removeCartItem(id);write(data);}else write(read().filter(row=>entryKey(row)!==String(id)));}
  async function importPrevious(edited){
    if(serverRows===null)throw new Error('The server cart is unavailable.');const original=previous();if(!original.length)return;
    const rows=edited||original;const {data}=await Api.importCart(rows.map(row=>({equipment_id:row.equipment_id,quantity:Number(row.quantity),borrow_date:row.borrow_date,expected_return_date:row.expected_return_date})));
    if(!Array.isArray(data)||!rows.every(row=>data.some(saved=>signature(saved)===signature(row)&&saved.quantity>=Number(row.quantity))))throw new Error('Import was not confirmed. Your browser cart is preserved.');write(data);
    const sent=new Map(original.map(row=>[entryKey(row),row.quantity]));const remaining=previous().flatMap(row=>{const quantity=row.quantity-(sent.get(entryKey(row))||0);return quantity>0?[{...row,quantity}]:[];});localStorage.setItem(key(),JSON.stringify(remaining));fallback.set(key(),remaining);memoryOnly.delete(key());
  }
  window.addEventListener('storage',updateBadges);
  return {read,write,add,update,remove,initialize,reload,previous,importPrevious,entryKey,signature,updateBadges,isServer:()=>serverRows!==null};
})();
