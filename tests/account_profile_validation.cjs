const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const sidebarSource = fs.readFileSync(path.join(__dirname, '../assets/js/sidebar.js'), 'utf8');
const app = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
const profile = fs.readFileSync(path.join(__dirname, '../assets/js/profile.js'), 'utf8');

function harness(role, referrer = '', failure = null, historyLength = 2) {
  const nodes = new Map();
  const node = id => {
    if (!nodes.has(id)) nodes.set(id, {innerHTML: '', dataset: {}, textContent: '', hidden: false,
      id, attributes: {}, value: '', type: id.toLowerCase().includes('password') ? 'password' : 'text', classList: {add() {}, remove() {}, contains() {return false;}}, setAttribute(name, value) {this.attributes[name] = value;}, getAttribute(name) {return this.attributes[name] ?? null;}, removeAttribute(name) {delete this.attributes[name];}, querySelector(selector) {return node(id + selector);}, querySelectorAll() {return [];}, focus() {}, reset() {}, listeners: {}, addEventListener(type, fn) {const previous = this.listeners[type]; this.listeners[type] = previous ? event => {previous(event); return fn(event);} : fn;}});
    return nodes.get(id);
  };
  node('profileBack').href = 'dashboard.html';
  const toggles = ['currentPassword', 'newPassword', 'confirmPassword'].map(id => {
    const button = node(id + 'Toggle');
    button.setAttribute('aria-controls', id);
    button.setAttribute('aria-pressed', 'false');
    button.querySelector('.password-eye-slash').hidden = true;
    return button;
  });
  const user = {user_id: 42, role, name: '<img src=x onerror=alert(1)>', email: 'my-account@example.invalid'};
  let backCalls = 0;
  const context = vm.createContext({
    URL, console, TextEncoder,
    location: {pathname: '/equipement-borrowing-system/profile.php', origin: 'http://localhost'},
    history: {length: historyLength, back() {backCalls++;}},
    window: {addEventListener() {}},
    document: {referrer, querySelector: node, querySelectorAll: selector => selector === '[data-password-toggle]' ? toggles : [], getElementById: node},
    Api: {me: async () => {if (failure) throw failure; return {data: user};},
      listNotifications: async () => ({data: []})},
  });
  vm.runInContext(sidebarSource, context);
  vm.runInContext(app, context);
  vm.runInContext('BorrowingCart.initialize = async () => {}; BorrowingCart.read = () => [];', context);
  vm.runInContext(profile.replace('  loadProfile();', '  globalThis.profileReady = loadProfile();'), context);
  return {context, node, user, backCalls: () => backCalls};
}

(async () => {
  for (const role of ['customer', 'admin', 'staff']) {
    const h = harness(role, 'http://localhost/equipement-borrowing-system/requests.html?status=pending#history');
    await h.context.profileReady;
    assert.equal(h.node('profileDetails').hidden, false);
    assert.equal(h.node('profileStatus').hidden, true);
    assert.equal(h.node('updateProfileBtn').disabled, true, 'Unchanged profile must start disabled');
    assert.equal(h.node('changePasswordBtn').disabled, true, 'Empty passwords must start disabled');
    assert.equal(h.node('profileName').value, h.user.name);
    assert.equal(h.node('profileEmail').value, h.user.email);
    const label = role.charAt(0).toUpperCase() + role.slice(1);
    assert.equal(h.node('profileRole').textContent, label);
    for (const selector of ['.rail', '.rail-canvas .offcanvas-body']) {
      const markup = h.node(selector).innerHTML;
      assert.match(markup, /class="sidebar-profile-link account-link" href="profile.php"/);
      assert.match(markup, /aria-current="page"/);
      assert.match(markup, /&lt;img src=x onerror=alert\(1\)&gt;/);
      assert.doesNotMatch(markup, /<img/);
      assert.ok(markup.includes('<span class="sidebar-user-role">' + label + '</span>'));
      assert.match(markup, /class="sidebar-user-name(?: who)?"/);
      assert.match(markup, /data-sign-out/);
      assert.match(markup, /<svg\b/);
      assert.match(markup, /Sign out/);
    }
    assert.equal(h.node('.rail').innerHTML, h.node('.rail-canvas .offcanvas-body').innerHTML);
    let prevented = false;
    h.node('profileBack').listeners.click({button: 0, preventDefault() {prevented = true;}});
    assert.equal(h.backCalls(), 1);
    assert.equal(prevented, true);
    assert.equal(h.node('profileBack').href, 'http://localhost/equipement-borrowing-system/requests.html?status=pending#history');
    h.node('profileBack').listeners.click({button: 0, ctrlKey: true, preventDefault() {throw new Error('Modified click was intercepted');}});
    assert.equal(h.backCalls(), 1);
  }
  for (const referrer of ['', 'https://external.example/profile', 'http://localhost/other-app/home', 'http://localhost/equipement-borrowing-system/profile.php']) {
    const h = harness('staff', referrer);
    await h.context.profileReady;
    assert.equal(h.node('profileBack').href, 'dashboard.html');
    assert.equal(h.node('profileBack').listeners.click, undefined);
  }
  const direct = harness('admin', 'http://localhost/equipement-borrowing-system/requests.html', null, 1);
  await direct.context.profileReady;
  assert.equal(direct.node('profileBack').href, 'dashboard.html');

  const failed = harness('staff', '', Object.assign(new Error('Database unavailable'), {status: 500}));
  await failed.context.profileReady;
  assert.equal(failed.context.location.href, undefined);
  assert.equal(failed.node('profileDetails').hidden, true);
  assert.equal(failed.node('profileRetry').hidden, false);
  failed.context.Api.me = async () => ({data: failed.user});
  await failed.node('profileRetry').listeners.click();
  assert.equal(failed.node('profileDetails').hidden, false);
  assert.equal(failed.node('profileRetry').hidden, true);

  const guest = harness('staff', '', Object.assign(new Error('Not logged in'), {status: 401}));
  await guest.context.profileReady;
  assert.equal(guest.context.location.href, 'index.html');
  assert.equal(guest.node('profileDetails').hidden, true);
  const editor = harness('customer');
  await editor.context.profileReady;
  assert.equal(editor.node('updateProfileBtn').disabled, true);
  assert.equal(editor.node('changePasswordBtn').disabled, true);
  assert.equal(editor.node('passwordStrengthText').textContent, 'Not entered');
  assert.equal(editor.node('passwordStrength').getAttribute('aria-valuenow'), '0');
  editor.context.Api.updateProfile = async () => {throw new Error('Unchanged profile submitted');};
  await editor.node('profileForm').listeners.submit({preventDefault() {}});
  const edit = (id, value, event = 'input') => {
    editor.node(id).value = value;
    editor.node(id).listeners[event]();
  };
  edit('profileName', 'Changed_User');
  assert.equal(editor.node('updateProfileBtn').disabled, false);
  edit('profileName', editor.user.name);
  assert.equal(editor.node('updateProfileBtn').disabled, true, 'Reverting the name must disable Update');
  edit('profileEmail', 'changed@example.invalid', 'change');
  assert.equal(editor.node('updateProfileBtn').disabled, false, 'Email-only edit must enable Update');
  edit('profileName', 'Changed_User');
  edit('profileName', editor.user.name);
  assert.equal(editor.node('updateProfileBtn').disabled, false, 'Changed email still needs saving');
  edit('profileEmail', editor.user.email);
  assert.equal(editor.node('updateProfileBtn').disabled, true, 'Reverting both fields must disable Update');
  edit('profileName', '  ' + editor.user.name + '  ');
  assert.equal(editor.node('updateProfileBtn').disabled, true, 'Whitespace-only edits are normalized');
  const saved = {...editor.user, name: 'Updated_User', email: 'updated@example.invalid'};
  editor.node('profileName').value = '  Updated_User  ';
  editor.node('profileEmail').value = '  updated@example.invalid  ';
  let calls = 0;
  let resolveSave;
  editor.context.Api.updateProfile = body => {
    calls++;
    assert.equal(body.action, 'profile');
    assert.equal(body.name, saved.name);
    assert.equal(body.email, saved.email);
    return new Promise(resolve => { resolveSave = resolve; });
  };
  const submitEvent = {preventDefault() {}};
  const pending = editor.node('profileForm').listeners.submit(submitEvent);
  assert.equal(editor.node('profileFields').disabled, true);
  assert.equal(editor.node('passwordFields').disabled, true);
  assert.equal(editor.node('updateProfileBtn').disabled, true);
  assert.equal(editor.node('changePasswordBtn').disabled, true);
  editor.node('profileName').listeners.input();
  assert.equal(editor.node('updateProfileBtn').disabled, true, 'Input events must not enable buttons during a save');
  await editor.node('profileForm').listeners.submit(submitEvent);
  assert.equal(calls, 1, 'Double submit was not blocked');
  resolveSave({data: saved, message: 'Profile updated successfully.'});
  await pending;
  assert.equal(editor.node('profileFields').disabled, false);
  assert.equal(editor.node('profileFeedback').className, 'alert alert-success');
  assert.equal(editor.node('profileName').value, saved.name);
  assert.equal(editor.node('profileInitials').textContent, 'UP');
  assert.equal(editor.node('profileDisplayName').textContent, saved.name);
  assert.equal(editor.node('profileDisplayEmail').textContent, saved.email);
  assert.equal(editor.node('updateProfileBtn').disabled, true, 'Successful save must reset the baseline');
  edit('profileName', 'Another_User');
  assert.equal(editor.node('updateProfileBtn').disabled, false);
  edit('profileName', saved.name);
  assert.equal(editor.node('updateProfileBtn').disabled, true, 'Reverting to the last saved name must disable Update');
  assert.ok(editor.node('.rail').innerHTML.includes(saved.name));
  assert.ok(editor.node('.rail-canvas .offcanvas-body').innerHTML.includes(saved.name));
  assert.equal(editor.context.location.href, undefined, 'AJAX reloaded the page');
  edit('profileName', 'Unsaved_User');
  editor.context.Api.updateProfile = async () => {throw Object.assign(new Error('Email already used'), {status: 409, code: 'EMAIL_TAKEN'});};
  await editor.node('profileForm').listeners.submit(submitEvent);
  assert.equal(editor.node('profileFeedback').className, 'alert alert-danger');
  assert.equal(editor.node('profileFeedback').textContent, 'Email already used');
  assert.equal(editor.node('updateProfileBtn').disabled, false, 'Failed save must allow retry');
  assert.equal(editor.node('profileEmail').value, saved.email, 'Failed save erased input');
  edit('currentPassword', 'OriginalSecret123!');
  edit('newPassword', 'DifferentSecret456!');
  assert.equal(editor.node('changePasswordBtn').disabled, true, 'Missing confirmation must disable Change Password');
  edit('confirmPassword', 'DifferentSecret456!');
  assert.equal(editor.node('changePasswordBtn').disabled, false, 'Valid matching passwords must enable Change Password');
  edit('currentPassword', '');
  assert.equal(editor.node('changePasswordBtn').disabled, true);
  edit('currentPassword', 'OriginalSecret123!', 'change');
  assert.equal(editor.node('changePasswordBtn').disabled, false);
  edit('confirmPassword', 'DoesNotMatch456!');
  assert.equal(editor.node('changePasswordBtn').disabled, true);
  edit('confirmPassword', 'DifferentSecret456!');
  edit('newPassword', 'ChangedSecret789!');
  assert.equal(editor.node('changePasswordBtn').disabled, true, 'Changing New Password must recheck confirmation');
  for (const invalid of ['', 'short', 'weakpassword123!', 'WeakPasswordWithoutNumber!',
    'WeakPassword123', 'Weak Password123!', 'OriginalSecret123!', 'Updated_User123!', 'x'.repeat(73)]) {
    edit('newPassword', invalid);
    edit('confirmPassword', invalid);
    assert.equal(editor.node('changePasswordBtn').disabled, true, 'Invalid new password enabled the button: ' + invalid);
  }
  edit('newPassword', 'abc');
  assert.equal(editor.node('passwordStrengthText').textContent, 'Weak');
  assert.equal(editor.node('passwordStrength').getAttribute('aria-valuenow'), '1');
  edit('newPassword', 'HelloWorld12');
  assert.equal(editor.node('passwordStrengthText').textContent, 'Medium');
  for (const id of ['currentPassword', 'newPassword', 'confirmPassword']) {
    const input = editor.node(id);
    const value = input.value;
    const toggle = editor.node(id + 'Toggle');
    toggle.listeners.click();
    assert.equal(input.type, 'text');
    assert.equal(input.value, value, 'Visibility toggle altered a password');
    assert.equal(toggle.getAttribute('aria-pressed'), 'true');
    assert.match(toggle.getAttribute('aria-label'), /^Hide /);
    assert.equal(toggle.querySelector('.password-eye').hidden, true);
    assert.equal(toggle.querySelector('.password-eye-slash').hidden, false);
    toggle.listeners.click();
    assert.equal(input.type, 'password');
    assert.equal(toggle.getAttribute('aria-pressed'), 'false');
    assert.match(toggle.getAttribute('aria-label'), /^Show /);
  }
  edit('newPassword', ' DifferentSecret456! ');
  edit('confirmPassword', ' DifferentSecret456! ');
  editor.node('newPasswordToggle').listeners.click();
  editor.node('confirmPasswordToggle').listeners.click();
  assert.equal(editor.node('changePasswordBtn').disabled, true, 'Showing passwords must not trim away invalid whitespace');
  edit('newPassword', 'DifferentSecret456!');
  edit('confirmPassword', 'DifferentSecret456!');
  assert.equal(editor.node('changePasswordBtn').disabled, false);
  assert.equal(editor.node('passwordStrengthText').textContent, 'Strong');
  assert.equal(editor.node('passwordStrength').getAttribute('aria-valuenow'), '3');

  editor.node('passwordForm').reset = () => {
    for (const id of ['currentPassword', 'newPassword', 'confirmPassword']) editor.node(id).value = '';
  };
  editor.context.Api.updateProfile = async body => {
    assert.equal(body.action, 'password');
    assert.equal(body.current_password, 'OriginalSecret123!');
    assert.equal(body.new_password, body.confirm_new_password);
    return {data: saved, message: 'Password changed successfully.'};
  };
  await editor.node('passwordForm').listeners.submit(submitEvent);
  assert.equal(editor.node('passwordFeedback').className, 'alert alert-success');
  assert.equal(editor.node('changePasswordBtn').disabled, true, 'Successful password change must disable button after reset');
  assert.equal(editor.node('passwordStrengthText').textContent, 'Not entered');
  for (const id of ['currentPassword', 'newPassword', 'confirmPassword']) {
    assert.equal(editor.node(id).type, 'password');
    assert.equal(editor.node(id + 'Toggle').getAttribute('aria-pressed'), 'false');
  }
  const avatar = harness('staff');
  avatar.user.name = 'Dana Santos';
  await avatar.context.profileReady;
  assert.equal(avatar.node('profileInitials').textContent, 'DS');
  assert.equal(avatar.node('profileAvatar').getAttribute('aria-label'), 'Initials avatar for Dana Santos');
  for (const id of ['currentPassword', 'newPassword', 'confirmPassword']) assert.equal(editor.node(id).value, '');

  console.log('PASS: initials avatar, accessible password toggles, strength levels, untrimmed visible passwords, dynamic profile/password button states, reverted edits, saved baselines, password validity, AJAX updates, double-submit guard, success/error feedback, both menus refreshed, password fields cleared, all roles, both account menus, safe profile rendering, history/fallback/modified Back clicks, server-error retry, and guest redirect.');
})().catch(error => {console.error(error); process.exitCode = 1;});
