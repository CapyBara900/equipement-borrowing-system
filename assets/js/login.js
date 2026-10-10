/* Shared sign-in / registration UI. Backend validation remains authoritative. */
(function () {
  const byId = id => document.getElementById(id);
  const signInPane = byId('signInPane');
  const registerPane = byId('registerPane');
  const signInForm = byId('signInForm');
  const registerForm = byId('registerForm');
  const switches = [byId('toRegister'), byId('toSignIn')];
  const passwordToggles = [...document.querySelectorAll('[data-auth-password-toggle]')];
  const hideTimers = new Map();
  let busy = null;
  let unavailableEmail = null;
  let unavailableName = null;
  let emailCheckSequence = 0;

  const loginRules = {
    loginEmail: [Rules.required('Email address'), Rules.emailMaxLength(), Rules.email()],
    loginPassword: [Rules.required('Password'), Rules.maxLength(200, 'Password')],
  };
  const registerRules = {
    regName: [Rules.required('Full name'), Rules.fullName()],
    regEmail: [Rules.required('Email address'), Rules.emailMaxLength(), Rules.emailStrict()],
    regPassword: [Rules.required('Password'), Rules.passwordMinLength(), {
      test: value => new TextEncoder().encode(value).length <= 72 && !value.includes('\0'),
      message: 'Password must be 72 bytes or fewer and contain no null characters.',
    }, Rules.passwordNoSpaces(), Rules.passwordUppercase(), Rules.passwordLowercase(),
    Rules.passwordNumber(), Rules.passwordSpecial(), Rules.passwordPersonalInfo('regName', 'regEmail')],
    regConfirm: [Rules.required('Password confirmation'), Rules.matches('regPassword', 'Passwords')],
  };
  // Passwords remain exact strings even when their input type becomes text.
  for (const [rules, ids] of [[loginRules, ['loginPassword']], [registerRules, ['regPassword', 'regConfirm']]]) {
    ids.forEach(id => {
      rules[id] = rules[id].map(rule => ({...rule, test: (_, input) => rule.test(input.value, input)}));
    });
  }
  liveValidate(loginRules);
  liveValidate(registerRules);

  const normalizedEmail = () => byId('regEmail').value.trim().toLowerCase();

  function feedback(id, message, success = false) {
    const box = byId(id);
    box.className = 'auth-feedback' + (success ? ' is-success' : '');
    box.textContent = message;
    box.hidden = false;
  }

  function updateStrength() {
    const input = byId('regPassword');
    const value = input.value;
    const checks = {
      regRuleLength: value.length >= 12,
      regRuleCase: /[A-Z]/.test(value) && /[a-z]/.test(value),
      regRuleNumber: /[0-9]/.test(value),
      regRuleSymbol: /[!@#$%^&*]/.test(value),
    };
    Object.entries(checks).forEach(([id, met]) => {
      const item = byId(id);
      item.classList.toggle('is-met', met);
      item.setAttribute('aria-label', item.textContent + (met ? ': met' : ': not met'));
    });
    let score = 0;
    if (value) {
      const variety = [value.length >= 12, /[A-Z]/.test(value), /[a-z]/.test(value),
        /[0-9]/.test(value), /[!@#$%^&*]/.test(value)].filter(Boolean).length;
      const valid = registerRules.regPassword.every(rule => rule.test(value, input));
      score = valid && value.length >= 16 ? 3 : variety >= 3 ? 2 : 1;
    }
    const label = ['Not entered', 'Weak', 'Medium', 'Strong'][score];
    byId('regStrength').className = 'auth-strength is-' + ['empty', 'weak', 'medium', 'strong'][score];
    byId('regStrengthMeter').setAttribute('aria-valuenow', String(score));
    byId('regStrengthMeter').setAttribute('aria-valuetext', score ? label : 'No password entered');
    if (byId('regStrengthText').textContent !== label) byId('regStrengthText').textContent = label;
  }

  function refreshConfirmation() {
    const input = byId('regConfirm');
    const filled = input.value !== '';
    const matches = filled && input.value === byId('regPassword').value;
    input.classList.toggle('auth-matched', matches);
    byId('regConfirmStatus').hidden = !matches;
    byId('regConfirmStatus').textContent = matches ? 'Passwords match.' : '';
    if (filled && !matches) showError(input, 'Passwords do not match.');
    else clearError(input);
  }

  function signInHasValues() {
    return byId('loginEmail').value.trim() !== ''
      && byId('loginPassword').value.trim() !== '';
  }

  function syncUI() {
    // Trim for button eligibility without changing the password sent to the API.
    byId('btn-sign-in').disabled = busy !== null || !signInHasValues();
    byId('registerBtn').disabled = busy !== null
      || !formIsValid(registerRules) || unavailableEmail === normalizedEmail()
      || unavailableName === byId('regName').value.trim();
    updateStrength();
  }

  function setBusy(action) {
    busy = action;
    byId('signInFields').disabled = action !== null;
    byId('registerFields').disabled = action !== null;
    for (const [kind, buttonId, spinnerId, labelId, idle, loading] of [
      ['login', 'btn-sign-in', 'signInSpinner', 'signInLabel', 'Sign in', 'Signing in…'],
      ['register', 'registerBtn', 'registerSpinner', 'registerLabel', 'Create account', 'Creating account…'],
    ]) {
      const loadingNow = action === kind;
      byId(buttonId).setAttribute('aria-busy', String(loadingNow));
      byId(spinnerId).hidden = !loadingNow;
      byId(labelId).textContent = loadingNow ? loading : idle;
    }
    switches.forEach(link => link.setAttribute('aria-disabled', String(action !== null)));
    syncUI();
  }

  function setPasswordVisibility(button, visible) {
    const input = byId(button.getAttribute('aria-controls'));
    clearTimeout(hideTimers.get(input.id));
    input.type = visible ? 'text' : 'password';
    const label = (visible ? 'Hide ' : 'Show ') + (input.id === 'regConfirm' ? 'password confirmation' : 'password');
    button.setAttribute('aria-label', label);
    button.setAttribute('aria-pressed', String(visible));
    button.setAttribute('title', label);
    button.querySelector('.password-eye').hidden = visible;
    button.querySelector('.password-eye-slash').hidden = !visible;
    if (visible) hideTimers.set(input.id, setTimeout(() => setPasswordVisibility(button, false), 8000));
  }

  passwordToggles.forEach(button => {
    const input = byId(button.getAttribute('aria-controls'));
    setPasswordVisibility(button, false);
    button.addEventListener('click', () => {
      if (busy !== null) return;
      setPasswordVisibility(button, input.type === 'password');
      syncUI();
    });
    input.addEventListener('input', () => {
      if (input.type === 'text') setPasswordVisibility(button, true);
    });
  });

  function showPane(registering, focus = false) {
    signInPane.hidden = registering;
    registerPane.hidden = !registering;
    document.title = (registering ? 'Create account' : 'Sign in') + ' — Equipment Desk';
    passwordToggles.forEach(button => setPasswordVisibility(button, false));
    byId('signInFeedback').hidden = true;
    byId('registerFeedback').hidden = true;
    syncUI();
    if (focus) {
      byId(registering ? 'regName' : 'loginEmail').focus({preventScroll: true});
      const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      byId(registering ? 'registerPane' : 'signInPane').scrollIntoView({
        behavior: reducedMotion ? 'auto' : 'smooth', block: 'start',
      });
    }
  }

  switches.forEach(link => link.addEventListener('click', event => {
    if (busy !== null) { event.preventDefault(); return; }
    if (location.hash === link.getAttribute('href')) {
      event.preventDefault();
      showPane(link.id === 'toRegister', true);
    }
  }));
  window.addEventListener('hashchange', () => {
    if (busy === null) showPane(location.hash === '#register', true);
  });

  [...Object.keys(loginRules), ...Object.keys(registerRules)].forEach(id => {
    const input = byId(id);
    const changed = () => {
      if (id === 'regEmail') {
        ++emailCheckSequence; // Ignore availability results for earlier input values.
        if (unavailableEmail !== normalizedEmail()) unavailableEmail = null;
        else showError(input, 'This email address is already registered.');
      }
      if (id === 'regName' && unavailableName !== input.value.trim()) unavailableName = null;
      if (id.startsWith('reg')) refreshConfirmation();
      syncUI();
    };
    input.addEventListener('input', changed);
    input.addEventListener('change', changed);
  });

  byId('regEmail').addEventListener('blur', async () => {
    const input = byId('regEmail');
    const email = normalizedEmail();
    if (busy !== null || !registerRules.regEmail.every(rule => rule.test(email, input))) return;
    const sequence = ++emailCheckSequence;
    try {
      await Api.register({__check_email_only: true, email});
      if (sequence === emailCheckSequence && normalizedEmail() === email) {
        unavailableEmail = null;
        clearError(input);
      }
    } catch (error) {
      if (sequence === emailCheckSequence && normalizedEmail() === email && error.status === 409) {
        unavailableEmail = email;
        showError(input, 'This email address is already registered.');
      }
      // A failed availability lookup does not replace authoritative submit validation.
    } finally {
      if (sequence === emailCheckSequence) syncUI();
    }
  });

  signInForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy !== null || !signInHasValues()) return;
    byId('signInFeedback').hidden = true;
    if (!validate(loginRules)) { syncUI(); return; }
    setBusy('login');
    let signedIn = false;
    try {
      await Api.login({email: byId('loginEmail').value.trim(), password: byId('loginPassword').value});
      signedIn = true;
      location.href = 'dashboard.html';
    } catch (error) {
      feedback('signInFeedback', error.message);
    } finally {
      if (!signedIn) setBusy(null);
    }
  });

  registerForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy !== null) return;
    byId('registerFeedback').hidden = true;
    if (!validate(registerRules)) { refreshConfirmation(); syncUI(); return; }
    if (unavailableEmail === normalizedEmail()
        || unavailableName === byId('regName').value.trim()) return;
    const body = {name: byId('regName').value.trim(), email: byId('regEmail').value.trim(), password: byId('regPassword').value};
    setBusy('register');
    let focusField = null;
    try {
      await Api.register(body);
      ++emailCheckSequence;
      unavailableEmail = null;
      unavailableName = null;
      registerForm.reset();
      signInForm.reset();
      [...Object.keys(registerRules), ...Object.keys(loginRules)].forEach(id => clearError(byId(id)));
      refreshConfirmation();
      history.replaceState(null, '', '#signin');
      showPane(false);
      feedback('signInFeedback', 'Account created successfully. Please sign in.', true);
      focusField = byId('loginEmail');
    } catch (error) {
      feedback('registerFeedback', error.message);
      if (error.code === 'EMAIL_TAKEN' || (error.status === 409 && /email/i.test(error.message))) {
        unavailableEmail = body.email.toLowerCase();
        showError(byId('regEmail'), error.message);
        focusField = byId('regEmail');
      } else if (error.code === 'NAME_TAKEN') {
        unavailableName = body.name;
        showError(byId('regName'), error.message);
        focusField = byId('regName');
      }
    } finally {
      setBusy(null);
      if (focusField) focusField.focus();
    }
  });

  window.addEventListener('pageshow', event => {
    if (event.persisted) {
      signInForm.reset();
      Object.keys(loginRules).forEach(id => clearError(byId(id)));
      passwordToggles.forEach(button => setPasswordVisibility(button, false));
      setBusy(null);
    }
    syncUI(); // Includes browser-restored/autofilled values.
  });
  setBusy(null);
  showPane(location.hash === '#register');
  (async () => {
    try { await Api.me(); location.href = 'dashboard.html'; }
    catch (_) { /* No active session; keep the authentication form available. */ }
  })();
})();
