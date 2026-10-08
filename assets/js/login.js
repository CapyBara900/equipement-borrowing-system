/* Sign-in / registration screen. */

const signInPane = document.getElementById('signInPane');
const registerPane = document.getElementById('registerPane');

// If a session is already active, skip straight through.
(async () => {
  try {
    await Api.me();
    location.href = 'dashboard.html';
  } catch (e) { /* not signed in — stay here */ }
})();

document.getElementById('toRegister').addEventListener('click', (e) => {
  e.preventDefault();
  signInPane.hidden = true;
  registerPane.hidden = false;
  document.getElementById('regName').focus();
});

document.getElementById('toSignIn').addEventListener('click', (e) => {
  e.preventDefault();
  registerPane.hidden = true;
  signInPane.hidden = false;
  document.getElementById('loginEmail').focus();
});


const toggleLoginPassword = document.getElementById('toggleLoginPassword');
const loginPasswordInput = document.getElementById('loginPassword');

if (toggleLoginPassword && loginPasswordInput) {
  // Sync the toggle button's disabled state with whether the field has a value.
  const syncToggle = () => {
    toggleLoginPassword.disabled = loginPasswordInput.value.length === 0;
  };

  // Set initial state (field is empty on page load).
  syncToggle();

  // Update on every keystroke.
  loginPasswordInput.addEventListener('input', syncToggle);

  toggleLoginPassword.addEventListener('click', () => {
    const visible = loginPasswordInput.type === 'text';
    loginPasswordInput.type = visible ? 'password' : 'text';
    toggleLoginPassword.textContent = visible ? 'Show' : 'Hide';
    toggleLoginPassword.setAttribute('aria-label', visible ? 'Show password' : 'Hide password');
  });
}

/* ---------- Show / hide password (eye icon) ---------- */

const EYE_ICON = '<svg class="pw-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
const EYE_OFF_ICON = '<svg class="pw-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20C5 20 1 12 1 12a18.45 18.45 0 0 1 5.06-5.94"></path><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"></path><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

// How long a revealed password stays visible before it hides itself again.
const PASSWORD_AUTO_HIDE_MS = 8000; // 8 seconds
const autoHideTimers = {};

/** Put a password field back to hidden and reset its eye button. */
function hidePassword(inputId, buttonId) {
  const input = document.getElementById(inputId);
  const btn = document.getElementById(buttonId);
  if (!input || !btn) return;
  clearTimeout(autoHideTimers[inputId]);
  input.type = 'password';
  btn.innerHTML = EYE_ICON;
  btn.setAttribute('aria-pressed', 'false');
  btn.setAttribute('aria-label', 'Show password');
  btn.setAttribute('title', 'Show password');
}

/** Hidden by default; each click on the eye flips visible <-> hidden. */
function setupPasswordToggle(inputId, buttonId) {
  const input = document.getElementById(inputId);
  const btn = document.getElementById(buttonId);
  if (!input || !btn) return;
  hidePassword(inputId, buttonId);
  const startAutoHide = () => {
    clearTimeout(autoHideTimers[inputId]);
    autoHideTimers[inputId] = setTimeout(() => hidePassword(inputId, buttonId), PASSWORD_AUTO_HIDE_MS);
  };

  btn.addEventListener('click', () => {
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.innerHTML = show ? EYE_OFF_ICON : EYE_ICON;
    btn.setAttribute('aria-pressed', String(show));
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    btn.setAttribute('title', show ? 'Hide password' : 'Show password');
    if (show) startAutoHide(); else clearTimeout(autoHideTimers[inputId]);
    input.focus();
  });

  // Typing while it's visible restarts the countdown so it doesn't hide mid-entry.
  input.addEventListener('input', () => {
    if (input.type === 'text') startAutoHide();
  });
}

setupPasswordToggle('regPassword', 'toggleRegPassword');
setupPasswordToggle('regConfirm', 'toggleRegConfirm');

/** Empty the sign-in form (used after account creation and on back/forward restore). */
function resetSignInForm() {
  const email = document.getElementById('loginEmail');
  const pass = document.getElementById('loginPassword');
  email.value = '';
  pass.value = '';
  clearError(email);
  clearError(pass);
  // Reset the sign-in "Show" toggle back to hidden.
  pass.type = 'password';
  if (toggleLoginPassword) {
    toggleLoginPassword.textContent = 'Show';
    toggleLoginPassword.setAttribute('aria-label', 'Show password');
  }
  // Re-sync the toggle's disabled state and the Sign in button.
  pass.dispatchEvent(new Event('input'));
  email.dispatchEvent(new Event('input'));
}

window.addEventListener('pageshow', (e) => {
  if (e.persisted) resetSignInForm();
});

/* ---------- Sign in ---------- */

const loginRules = {
  loginEmail: [Rules.required('Email'), Rules.email()],
  loginPassword: [Rules.required('Password')],
};
liveValidate(loginRules, 'signInBtn');

document.getElementById('signInForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!validate(loginRules)) return;

  const btn = document.getElementById('signInBtn');
  btn.disabled = true;
  btn.textContent = 'Signing in…';

  try {
    await Api.login({
      email: document.getElementById('loginEmail').value.trim(),
      password: document.getElementById('loginPassword').value,
    });
    location.href = 'dashboard.html';
  } catch (err) {
    toast(err.message, 'bad');
    btn.disabled = false;
    btn.textContent = 'Sign in';
  }
});

/* ---------- Register ---------- */

const registerRules = {
  regName: [
    Rules.required('Full name'),
    Rules.fullName(),
  ],
  regEmail: [
    Rules.required('Email'),
    Rules.emailNoSpaces(),
    Rules.emailMaxLength(),
    Rules.emailExactlyOneAt(),
    Rules.emailParts(),
    Rules.emailStrict(),
  ],
  regPassword: [
    Rules.required('Password'),
    Rules.passwordMinLength(),
    Rules.passwordNoSpaces(),
    Rules.passwordUppercase(),
    Rules.passwordLowercase(),
    Rules.passwordNumber(),
    Rules.passwordSpecial(),
    Rules.passwordPersonalInfo('regName', 'regEmail'),
  ],
  regConfirm: [Rules.required('Confirm password'), Rules.matches('regPassword', 'Passwords')],
};
const syncRegisterBtn = liveValidate(registerRules, 'registerBtn');

// Show "Passwords do not match." live, whichever of the two fields is being edited.
const regPasswordInput = document.getElementById('regPassword');
const regConfirmInput = document.getElementById('regConfirm');
function refreshConfirmMatch() {
  if (regConfirmInput.value === '') return; // "required" handles empty on blur/submit
  if (regPasswordInput.value !== regConfirmInput.value) {
    showError(regConfirmInput, 'Passwords do not match.');
  } else {
    clearError(regConfirmInput);
  }
  syncRegisterBtn();
}
regPasswordInput.addEventListener('input', refreshConfirmMatch);
regConfirmInput.addEventListener('input', refreshConfirmMatch);

// Async duplicate-email check on blur (gives immediate feedback before submit).
document.getElementById('regEmail').addEventListener('blur', async () => {
  const input = document.getElementById('regEmail');
  const val = input.value.trim().toLowerCase();
  if (!val) return; // required rule already handles empty
  // Only run if the field passes all synchronous rules first.
  const syncOk = registerRules.regEmail.every(r => r.test(val, input));
  if (!syncOk) return;
  try {
    await Api.register({ __check_email_only: true, email: val });
  } catch (err) {
    if (err.status === 409) {
      showError(input, 'This email address is already associated with an account.');
      document.getElementById('registerBtn').disabled = true;
    }
  }
});



document.getElementById('registerForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!validate(registerRules)) return;

  const btn = document.getElementById('registerBtn');
  btn.disabled = true;
  btn.textContent = 'Creating…';

  try {
    await Api.register({
      name: document.getElementById('regName').value.trim(),
      email: document.getElementById('regEmail').value.trim(),
      password: document.getElementById('regPassword').value,
    });
    toast('Account created successfully. Please sign in.', 'ok');

    // Return to the login form instead of automatically signing in.
    registerPane.hidden = true;
    signInPane.hidden = false;

    // Clear registration fields.
    document.getElementById('regName').value = '';
    document.getElementById('regEmail').value = '';
    document.getElementById('regPassword').value = '';
    document.getElementById('regConfirm').value = '';
    hidePassword('regPassword', 'toggleRegPassword');
    hidePassword('regConfirm', 'toggleRegConfirm');

    // Sign-in email and password must be empty when the user lands back here.
    resetSignInForm();

    btn.textContent = 'Create account';
    syncRegisterBtn(); // form is empty now, so the button goes back to disabled
    
  } catch (err) {
    toast(err.message, 'bad');
    btn.disabled = false;
    btn.textContent = 'Create account';
  }
});