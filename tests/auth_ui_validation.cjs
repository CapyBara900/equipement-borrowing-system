const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const app = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
const auth = fs.readFileSync(path.join(__dirname, '../assets/js/login.js'), 'utf8');
function harness(hash = '') {
  const nodes = new Map();
  function node(id) {
    if (!nodes.has(id)) {
      const classes = new Set();
      nodes.set(id, {id, value: '', type: /Password|Confirm/.test(id) ? 'password' : 'text', hidden: false,
        textContent: '', attributes: {}, listeners: {}, focused: false,
        classList: {add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c),
          toggle(c, force) {const enabled = force ?? !classes.has(c); if (enabled) classes.add(c); else classes.delete(c);}},
        setAttribute(k, v) {this.attributes[k] = String(v);}, getAttribute(k) {return this.attributes[k] ?? null;},
        removeAttribute(k) {delete this.attributes[k];}, querySelector(s) {return node(id + s);},
        addEventListener(type, fn) {(this.listeners[type] ??= []).push(fn);},
        async dispatch(type, event = {}) {return Promise.all((this.listeners[type] || []).map(fn => fn(event)));},
        focus() {this.focused = true;}, scrollIntoView(options) {this.scrollOptions = options;}, reset() {},
      });
    }
    return nodes.get(id);
  }
  const toggles = [['loginPassword', 'toggleLoginPassword'], ['regPassword', 'toggleRegPassword'], ['regConfirm', 'toggleRegConfirm']]
    .map(([id, buttonId]) => {node(buttonId).setAttribute('aria-controls', id); return node(buttonId);});
  node('toRegister').setAttribute('href', '#register'); node('toSignIn').setAttribute('href', '#signin');
  node('signInForm').reset = () => {for (const id of ['loginEmail', 'loginPassword']) node(id).value = '';};
  node('registerForm').reset = () => {for (const id of ['regName', 'regEmail', 'regPassword', 'regConfirm']) node(id).value = '';};
  const windows = {};
  const timers = new Map(); let timerId = 0;
  const location = {hash};
  const api = {me: async () => {throw new Error('Guest');}, register: async () => ({success: true}), login: async () => ({success: true})};
  const context = vm.createContext({console, TextEncoder, location, Api: api,
    setTimeout(fn) {timers.set(++timerId, fn); return timerId;}, clearTimeout(id) {timers.delete(id);},
    history: {replaceState(_, __, hash) {location.hash = hash;}},
    window: {matchMedia() {return {matches: false};}, addEventListener(type, fn) {(windows[type] ??= []).push(fn);}},
    document: {title: '', getElementById: node, querySelector: node,
      querySelectorAll: s => s === '[data-auth-password-toggle]' ? toggles : []},
  });
  vm.runInContext(app, context); vm.runInContext(auth, context);
  const edit = async (id, value, type = 'input') => {node(id).value = value; await node(id).dispatch(type);};
  const submit = id => node(id).dispatch('submit', {preventDefault() {}});
  const tick = async () => {await Promise.resolve(); await Promise.resolve();};
  return {node, api, context, edit, submit, timers, location, tick,
    async windowEvent(type, event = {}) {return Promise.all((windows[type] || []).map(fn => fn(event)));}};
}
function deferred() {let resolve, reject; const promise = new Promise((res, rej) => {resolve = res; reject = rej;}); return {promise, resolve, reject};}
async function fillRegistration(h) {
  await h.edit('regName', 'Dana Santos'); await h.edit('regEmail', 'dana.student@example.invalid');
  await h.edit('regPassword', 'DifferentSecret456!'); await h.edit('regConfirm', 'DifferentSecret456!');
}
(async () => {
  const h = harness(); await h.tick();
  assert.equal(h.node('btn-sign-in').disabled, true); assert.equal(h.node('registerBtn').disabled, true);
  await h.edit('loginEmail', 'not-an-email'); await h.edit('loginPassword', 'anything');
  assert.equal(h.node('btn-sign-in').disabled, false, 'Sign-in enables for non-empty values');
  let loginCalls = 0; h.api.login = async () => {loginCalls++;};
  await h.submit('signInForm'); assert.equal(loginCalls, 0, 'Malformed email must fail submit validation');
  await h.edit('loginEmail', 'user@example.invalid'); await h.edit('loginPassword', '');
  assert.equal(h.node('btn-sign-in').disabled, true);
  await h.edit('loginPassword', '   ');
  assert.equal(h.node('btn-sign-in').disabled, true, 'Whitespace-only passwords must keep Sign In disabled');
  await h.submit('signInForm'); assert.equal(loginCalls, 0);
  await h.edit('loginPassword', 'ValidPassword');
  await h.edit('loginEmail', '   ');
  assert.equal(h.node('btn-sign-in').disabled, true, 'Whitespace-only email must keep Sign In disabled');
  await h.edit('loginEmail', '');
  assert.equal(h.node('btn-sign-in').disabled, true, 'Clearing email must disable Sign In');
  await h.edit('loginEmail', ' user@example.invalid ');
  await h.edit('loginPassword', 'PasswordWithSpaces ');
  const loginPending = deferred(); h.api.login = async body => {loginCalls++; assert.equal(body.password, 'PasswordWithSpaces '); return loginPending.promise;};
  await h.node('toggleLoginPassword').dispatch('click');
  assert.equal(h.node('loginPassword').type, 'text'); assert.equal(h.node('toggleLoginPassword').getAttribute('aria-pressed'), 'true');
  const loginSubmission = h.submit('signInForm'); await h.tick();
  assert.equal(h.node('btn-sign-in').disabled, true); assert.equal(h.node('signInSpinner').hidden, false);
  assert.equal(h.node('btn-sign-in').getAttribute('aria-busy'), 'true'); assert.equal(h.node('signInFields').disabled, true);
  await h.node('loginEmail').dispatch('input'); await h.submit('signInForm'); assert.equal(loginCalls, 1);
  loginPending.reject(new Error('Invalid email or password.')); await loginSubmission;
  assert.equal(h.node('signInFeedback').textContent, 'Invalid email or password.');
  assert.equal(h.node('signInFeedback').hidden, false); assert.equal(h.node('signInSpinner').hidden, true);
  assert.equal(h.node('btn-sign-in').disabled, false); assert.equal(h.node('loginPassword').value, 'PasswordWithSpaces ');
  await h.windowEvent('pageshow', {persisted: true}); assert.equal(h.node('loginPassword').value, '');
  assert.equal(h.node('btn-sign-in').disabled, true); assert.equal(h.node('loginPassword').type, 'password');

  const r = harness('#register'); await r.tick();
  assert.equal(r.node('registerPane').hidden, false); assert.equal(r.node('signInPane').hidden, true);
  assert.match(r.context.document.title, /Create account/);
  r.location.hash = '#signin'; await r.windowEvent('hashchange');
  assert.equal(r.node('signInPane').hidden, false);
  assert.equal(r.node('registerPane').hidden, true);
  assert.equal(r.node('loginEmail').focused, true);
  assert.equal(r.node('signInPane').scrollOptions.behavior, 'smooth');
  r.location.hash = '#register'; await r.windowEvent('hashchange');
  await fillRegistration(r); assert.equal(r.node('registerBtn').disabled, false);
  assert.equal(r.node('regStrengthText').textContent, 'Strong'); assert.equal(r.node('regConfirmStatus').textContent, 'Passwords match.');
  assert.equal(r.node('regConfirm').classList.contains('auth-matched'), true);
  await r.edit('regConfirm', 'Different'); assert.equal(r.node('registerBtn').disabled, true);
  assert.equal(r.node('[data-error-for="regConfirm"]').textContent, 'Passwords do not match.');
  await r.edit('regConfirm', ''); assert.equal(r.node('regConfirmStatus').hidden, true);
  for (const invalid of ['', 'weak', 'lowercaseonly123!', 'NoNumbersHere!', 'NoSymbol123456', 'Contains Spaces123!', 'DanaPassword123!', 'x'.repeat(73)]) {
    await r.edit('regPassword', invalid); await r.edit('regConfirm', invalid); assert.equal(r.node('registerBtn').disabled, true, invalid);
  }
  await r.edit('regPassword', 'abc'); assert.equal(r.node('regStrengthText').textContent, 'Weak');
  await r.edit('regPassword', 'HelloWorld12'); assert.equal(r.node('regStrengthText').textContent, 'Medium');
  await fillRegistration(r); await r.edit('regEmail', 'invalid'); assert.equal(r.node('registerBtn').disabled, true);
  await r.edit('regEmail', 'dana.student@example.invalid'); await r.edit('regName', 'Different Secret');
  assert.equal(r.node('registerBtn').disabled, true, 'Changing personal information must recheck password');
  await r.edit('regName', 'Dana Santos'); assert.equal(r.node('registerBtn').disabled, false);
  await r.node('toggleRegPassword').dispatch('click'); await r.node('toggleRegConfirm').dispatch('click');
  assert.equal(r.node('regPassword').type, 'text'); assert.equal(r.node('regConfirm').type, 'text');
  await r.edit('regPassword', ' DifferentSecret456! '); await r.edit('regConfirm', ' DifferentSecret456! ');
  assert.equal(r.node('registerBtn').disabled, true, 'Shown passwords must retain whitespace validation');
  let registerCalls = 0; r.api.register = async () => {registerCalls++;};
  await r.submit('registerForm'); assert.equal(registerCalls, 0);
  await fillRegistration(r);
  const probe = deferred(); r.api.register = async body => {assert.equal(body.__check_email_only, true); return probe.promise;};
  const oldProbe = r.node('regEmail').dispatch('blur'); await r.tick();
  assert.equal(r.node('registerBtn').disabled, false, 'Availability lookup must allow a valid form to submit');
  await r.edit('regEmail', 'new-address@example.invalid');
  probe.reject(Object.assign(new Error('Email already registered'), {status: 409})); await oldProbe;
  assert.equal(r.node('registerBtn').disabled, false, 'Stale email probe must not block edited email');
  r.api.register = async () => {throw Object.assign(new Error('Email already registered'), {status: 409});};
  await r.node('regEmail').dispatch('blur'); assert.equal(r.node('registerBtn').disabled, true);
  await r.node('regName').dispatch('input'); assert.equal(r.node('registerBtn').disabled, true, 'Known duplicate email must remain disabled');
  await r.edit('regEmail', 'available@example.invalid'); assert.equal(r.node('registerBtn').disabled, false);
  const registrationPending = deferred(); r.api.register = async () => {registerCalls++; return registrationPending.promise;};
  const registration = r.submit('registerForm'); await r.tick();
  assert.equal(r.node('registerSpinner').hidden, false); assert.equal(r.node('registerBtn').getAttribute('aria-busy'), 'true');
  await r.node('regName').dispatch('input'); await r.submit('registerForm'); assert.equal(registerCalls, 1);
  registrationPending.reject(new Error('Server unavailable.')); await registration;
  assert.equal(r.node('registerFeedback').textContent, 'Server unavailable.');
  assert.equal(r.node('registerBtn').disabled, false); assert.equal(r.node('registerSpinner').hidden, true);
  assert.equal(r.node('regPassword').value, 'DifferentSecret456!');
  r.api.register = async () => {throw Object.assign(new Error('Email address is already registered.'), {status: 409, code: 'EMAIL_TAKEN'});};
  await r.submit('registerForm'); assert.equal(r.node('registerBtn').disabled, true);
  assert.equal(r.node('registerFeedback').hidden, false);
  await r.edit('regEmail', 'another-address@example.invalid');
  r.api.register = async () => ({success: true}); await r.submit('registerForm');
  assert.equal(r.node('registerPane').hidden, true); assert.equal(r.node('signInPane').hidden, false);
  assert.equal(r.node('signInFeedback').className, 'auth-feedback is-success');
  assert.equal(r.node('registerBtn').disabled, true); assert.equal(r.node('btn-sign-in').disabled, true);
  for (const id of ['regName', 'regEmail', 'regPassword', 'regConfirm', 'loginEmail', 'loginPassword']) assert.equal(r.node(id).value, '');
  assert.equal(r.node('regPassword').type, 'password'); assert.equal(r.node('regStrengthText').textContent, 'Not entered');
  assert.equal(r.node('regConfirmStatus').hidden, true);
  const signedIn = harness(); await signedIn.edit('loginEmail', 'user@example.invalid'); await signedIn.edit('loginPassword', 'ExactSecret123!');
  await signedIn.submit('signInForm'); assert.equal(signedIn.location.href, 'dashboard.html');
  assert.equal(signedIn.node('btn-sign-in').disabled, true, 'Keep button blocked through successful navigation');
  console.log('PASS: auth button states, email/name/password validation, exact visible passwords, mismatch and strength feedback, loading guards, server alerts, stale email probes, registration reset, direct registration view, and sign-in navigation.');
})().catch(error => {console.error(error); process.exitCode = 1;});
