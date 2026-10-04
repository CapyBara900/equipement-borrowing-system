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
if (toggleLoginPassword) {
  toggleLoginPassword.addEventListener('click', () => {
    const input = document.getElementById('loginPassword');
    const visible = input.type === 'text';
    input.type = visible ? 'password' : 'text';
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
  regName: [Rules.required('Full name'), Rules.maxLength(100, 'Full name')],
  regEmail: [Rules.required('Email'), Rules.email()],
  regPassword: [Rules.required('Password'), Rules.minLength(8, 'Password')],
  regConfirm: [Rules.required('Confirm password'), Rules.matches('regPassword', 'Passwords')],
};
liveValidate(registerRules, 'registerBtn');

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
    // Sign straight in so they land on the dashboard, not back at a form.
    await Api.login({
      email: document.getElementById('regEmail').value.trim(),
      password: document.getElementById('regPassword').value,
    });
    location.href = 'dashboard.html';
  } catch (err) {
    toast(err.message, 'bad');
    btn.disabled = false;
    btn.textContent = 'Create account';
  }
});
