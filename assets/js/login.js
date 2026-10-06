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
liveValidate(registerRules, 'registerBtn');

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

    btn.disabled = false;
    btn.textContent = 'Create account';
    
  } catch (err) {
    toast(err.message, 'bad');
    btn.disabled = false;
    btn.textContent = 'Create account';
  }
});
