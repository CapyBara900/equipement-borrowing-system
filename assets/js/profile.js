/* Account details come exclusively from the authenticated /auth/me endpoint. */
(function () {
  const back = document.getElementById('profileBack');
  // Direct visits fall back to Overview. Only return through this app's history.
  try {
    const previous = new URL(document.referrer);
    const directory = location.pathname.slice(0, location.pathname.lastIndexOf('/') + 1);
    if (previous.origin === location.origin
        && previous.pathname.startsWith(directory)
        && previous.pathname !== location.pathname
        && history.length > 1) {
      back.href = previous.href;
      back.addEventListener('click', event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        history.back();
      });
    }
  } catch (_) { /* No valid referrer; use the Overview link. */ }

  const status = document.getElementById('profileStatus');
  const retry = document.getElementById('profileRetry');
  const details = document.getElementById('profileDetails');
  const profileFields = document.getElementById('profileFields');
  const passwordFields = document.getElementById('passwordFields');
  const profileButton = document.getElementById('updateProfileBtn');
  const passwordButton = document.getElementById('changePasswordBtn');
  let initialProfile = null;
  let ready = false;
  let busy = false;

  const profileRules = {
    profileName: [{
      test: value => /^[\p{L}\p{M}\p{N} ._'\-]{3,100}$/u.test(value)
        && /[\p{L}\p{N}]/u.test(value) && !value.includes('  '),
      message: 'Use 3–100 characters: letters, numbers, single spaces, periods, underscores, apostrophes, or hyphens.',
    }],
    profileEmail: [Rules.required('Email address'), Rules.emailMaxLength(), Rules.emailStrict()],
  };
  const passwordRules = {
    currentPassword: [Rules.required('Current password'), Rules.maxLength(200, 'Current password')],
    newPassword: [Rules.passwordMinLength(), {
      test: value => new TextEncoder().encode(value).length <= 72 && !value.includes('\0'),
      message: 'New password must be 72 bytes or fewer and contain no null characters.',
    }, Rules.passwordNoSpaces(), Rules.passwordUppercase(), Rules.passwordLowercase(),
    Rules.passwordNumber(), Rules.passwordSpecial(), {
      test: value => value !== document.getElementById('currentPassword').value,
      message: 'Choose a password different from your current password.',
    }, {
      test: value => {
        if (!initialProfile) return false;
        const name = initialProfile.name.toLowerCase();
        const email = initialProfile.email.toLowerCase();
        const personalValues = [email, email.split('@')[0],
          name.replace(/[^\p{L}\p{N}]+/gu, ''), ...name.split(/[^\p{L}\p{N}]+/u)];
        return !personalValues.filter(part => part.length >= 3)
          .some(part => value.toLowerCase().includes(part));
      },
      message: 'Password must not contain your name, username, or email address.',
    }],
    confirmPassword: [Rules.required('Password confirmation'), Rules.matches('newPassword', 'New passwords')],
  };
  // Showing a password changes its input type to text; never trim its value.
  Object.keys(passwordRules).forEach(id => {
    passwordRules[id] = passwordRules[id].map(rule => ({
      ...rule, test: (_, input) => rule.test(input.value, input),
    }));
  });

  const passwordToggles = [...document.querySelectorAll('[data-password-toggle]')];

  function setPasswordVisibility(button, visible) {
    const input = document.getElementById(button.getAttribute('aria-controls'));
    input.type = visible ? 'text' : 'password';
    const label = (visible ? 'Hide ' : 'Show ') + {
      currentPassword: 'current password', newPassword: 'new password',
      confirmPassword: 'password confirmation',
    }[input.id];
    button.setAttribute('aria-label', label);
    button.setAttribute('title', label);
    button.setAttribute('aria-pressed', String(visible));
    button.querySelector('.password-eye').hidden = visible;
    button.querySelector('.password-eye-slash').hidden = !visible;
  }

  passwordToggles.forEach(button => {
    button.addEventListener('click', () => {
      const input = document.getElementById(button.getAttribute('aria-controls'));
      setPasswordVisibility(button, input.type === 'password');
      syncButtonStates();
    });
  });

  function updatePasswordStrength() {
    const input = document.getElementById('newPassword');
    const value = input.value;
    const meter = document.getElementById('passwordStrength');
    const text = document.getElementById('passwordStrengthText');
    let level = 'empty';
    let score = 0;
    if (value) {
      // A local guidance meter; submission still uses the complete validation rules.
      const variety = [value.length >= 12, /[A-Z]/.test(value), /[a-z]/.test(value),
        /[0-9]/.test(value), /[!@#$%^&*]/.test(value)].filter(Boolean).length;
      const valid = passwordRules.newPassword.every(rule => rule.test(value, input));
      score = valid && value.length >= 16 ? 3 : variety >= 3 ? 2 : 1;
      level = ['empty', 'weak', 'medium', 'strong'][score];
    }
    const label = ['Not entered', 'Weak', 'Medium', 'Strong'][score];
    meter.className = 'profile-strength is-' + level;
    meter.setAttribute('aria-valuenow', String(score));
    meter.setAttribute('aria-valuetext', score ? label : 'No password entered');
    if (text.textContent !== label) text.textContent = label;
  }

  liveValidate(profileRules);
  liveValidate(passwordRules);

  function profileHasChanges() {
    return initialProfile !== null && (
      document.getElementById('profileName').value.trim() !== initialProfile.name
      || document.getElementById('profileEmail').value.trim() !== initialProfile.email
    );
  }

  function syncButtonStates() {
    const unavailable = !ready || busy;
    profileButton.disabled = unavailable || !profileHasChanges();
    passwordButton.disabled = unavailable || !formIsValid(passwordRules);
    updatePasswordStrength();
  }

  // Both typing and committed changes (including autofill) update the buttons.
  [...Object.keys(profileRules), ...Object.keys(passwordRules)].forEach(id => {
    const input = document.getElementById(id);
    input.addEventListener('input', syncButtonStates);
    input.addEventListener('change', syncButtonStates);
  });

  function setBusy(value) {
    busy = value;
    profileFields.disabled = !ready || busy;
    passwordFields.disabled = !ready || busy;
    syncButtonStates();
  }

  function fillProfile(user) {
    // A successful save becomes the new baseline for subsequent edits.
    initialProfile = { name: user.name.trim(), email: user.email.trim() };
    document.getElementById('profileName').value = user.name;
    document.getElementById('profileEmail').value = user.email;
    const words = user.name.trim().split(/\s+/u).filter(Boolean);
    const initials = words.length > 1
      ? Array.from(words[0])[0] + Array.from(words[words.length - 1])[0]
      : Array.from(words[0] || '?').slice(0, 2).join('');
    document.getElementById('profileInitials').textContent = initials.toLocaleUpperCase();
    document.getElementById('profileAvatar').setAttribute('aria-label', 'Initials avatar for ' + user.name);
    document.getElementById('profileDisplayName').textContent = user.name;
    document.getElementById('profileDisplayEmail').textContent = user.email;
    document.getElementById('profileRole').textContent = user.role.charAt(0).toUpperCase() + user.role.slice(1);
  }

  function feedback(id, message, success) {
    const box = document.getElementById(id);
    box.className = 'alert ' + (success ? 'alert-success' : 'alert-danger');
    box.textContent = message;
    box.hidden = false;
  }

  async function loadProfile() {
    ready = false;
    setBusy(false);
    status.hidden = false;
    status.textContent = 'Loading your account information…';
    retry.hidden = true;
    details.hidden = true;
    try {
      const user = await requireSession(['admin', 'staff', 'customer'], { redirectOnError: false });
      fillProfile(user);
      ready = true;
      setBusy(false);
      status.hidden = true;
      details.hidden = false;
    } catch (error) {
      status.textContent = 'Could not load your account information. Please try again.';
      retry.hidden = false;
    }
  }

  async function submit(event, action) {
    event.preventDefault();
    if (!ready || busy) return;
    const isProfile = action === 'profile';
    // Also guard Enter-key submissions of an unchanged profile.
    if (isProfile && !profileHasChanges()) return;
    const feedbackId = isProfile ? 'profileFeedback' : 'passwordFeedback';
    document.getElementById(feedbackId).hidden = true;
    if (!validate(isProfile ? profileRules : passwordRules)) return;
    const button = document.getElementById(isProfile ? 'updateProfileBtn' : 'changePasswordBtn');
    const label = button.textContent;
    const body = isProfile ? {
      action, csrf_token: document.getElementById('profileCsrf').value,
      name: document.getElementById('profileName').value.trim(),
      email: document.getElementById('profileEmail').value.trim(),
    } : {
      action, csrf_token: document.getElementById('passwordCsrf').value,
      current_password: document.getElementById('currentPassword').value,
      new_password: document.getElementById('newPassword').value,
      confirm_new_password: document.getElementById('confirmPassword').value,
    };
    let focusField = null;
    setBusy(true);
    button.textContent = isProfile ? 'Updating…' : 'Changing…';
    try {
      const result = await Api.updateProfile(body);
      CURRENT_USER = result.data;
      if (isProfile) fillProfile(result.data);
      else {
        document.getElementById('passwordForm').reset();
        passwordToggles.forEach(button => setPasswordVisibility(button, false));
        Object.keys(passwordRules).forEach(id => clearError(document.getElementById(id)));
      }
      feedback(feedbackId, result.message, true);
      // Refresh the name in both menus immediately, without reloading the page.
      await renderShell(CURRENT_USER);
    } catch (error) {
      feedback(feedbackId, error.message, false);
      const field = {
        NAME_TAKEN: 'profileName', NAME_INVALID: 'profileName',
        EMAIL_TAKEN: 'profileEmail', EMAIL_INVALID: 'profileEmail',
        CURRENT_PASSWORD_INVALID: 'currentPassword', PASSWORD_INVALID: 'newPassword',
        PASSWORD_MISMATCH: 'confirmPassword',
      }[error.code];
      if (field) {
        showError(document.getElementById(field), error.message);
        focusField = document.getElementById(field);
      }
      if (error.status === 401) location.href = 'index.html';
    } finally {
      button.textContent = label;
      setBusy(false);
      if (focusField) focusField.focus();
    }
  }

  document.getElementById('profileForm').addEventListener('submit', event => submit(event, 'profile'));
  document.getElementById('passwordForm').addEventListener('submit', event => submit(event, 'password'));
  retry.addEventListener('click', loadProfile);
  loadProfile();
})();
