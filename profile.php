<?php
require_once __DIR__ . '/includes/page_auth.php';
requirePageRole(['admin', 'staff', 'customer']);
if (empty($_SESSION['profile_csrf_token'])) {
    $_SESSION['profile_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = htmlspecialchars($_SESSION['profile_csrf_token'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Account Profile — Equipment Desk</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/styles.css?v=account-profile-ui-v1" rel="stylesheet">
<link href="assets/css/sidebar.css?v=sidebar-v3" rel="stylesheet">
</head>
<body>
<header class="topbar">
  <span class="brand">Equipment Desk</span>
  <button class="btn btn-sm btn-outline-light" type="button" data-sidebar-open="#mobileNav" aria-label="Open menu">Menu</button>
</header>
<div class="rail-canvas sidebar-drawer" tabindex="-1" id="mobileNav" role="dialog" aria-modal="true" aria-label="Main navigation" hidden>
  <div class="offcanvas-body sidebar"></div>
</div>
<div class="app-shell">
  <aside class="rail sidebar" aria-label="Main navigation"></aside>
  <main class="main">
    <div class="page-head">
      <div><h1>Account Profile</h1><p>Manage your account information and password.</p></div>
      <a class="btn btn-outline-secondary btn-sm" href="dashboard.html" id="profileBack">Back</a>
    </div>
    <p id="profileStatus" role="status" aria-live="polite">Loading your account information…</p>
    <button class="btn btn-outline-secondary btn-sm" type="button" id="profileRetry" hidden>Try again</button>
    <div class="profile-settings" id="profileDetails" hidden>
      <section class="panel profile-card" aria-labelledby="profileHeading">
        <div class="panel-head"><div><h2 id="profileHeading">Profile Information</h2><p class="profile-hint">Your account, kept up to date.</p></div></div>
        <div class="panel-body">
          <div class="profile-identity">
            <div class="profile-avatar" id="profileAvatar" role="img" aria-label="Your initials avatar">
              <span id="profileInitials" aria-hidden="true">—</span>
              <span class="profile-avatar-badge" title="Initials avatar" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" focusable="false"><circle cx="12" cy="8" r="3"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/></svg>
              </span>
            </div>
            <div class="profile-identity-text">
              <h3 id="profileDisplayName"></h3>
              <p id="profileDisplayEmail"></p>
              <dl class="profile-account-role"><dt>User Role</dt><dd id="profileRole"></dd></dl>
            </div>
          </div>
          <div id="profileFeedback" class="alert" role="status" aria-live="polite" hidden></div>
          <form id="profileForm" method="post" action="update_profile.php" novalidate>
            <input type="hidden" name="action" value="profile">
            <input type="hidden" name="csrf_token" id="profileCsrf" value="<?= $csrfToken ?>">
            <fieldset id="profileFields" disabled>
              <div class="mb-3">
                <label class="form-label" for="profileName">Full Name / Username</label>
                <input class="form-control" id="profileName" name="name" placeholder="e.g. Dana Santos" autocomplete="name" minlength="3" maxlength="100" aria-describedby="profileNameHint profileNameError" required>
                <div class="form-text" id="profileNameHint">3–100 characters. This name must be unique to your account.</div>
                <div class="field-error" id="profileNameError" data-error-for="profileName"></div>
              </div>
              <div class="mb-4">
                <label class="form-label" for="profileEmail">Email Address</label>
                <input class="form-control" type="email" id="profileEmail" name="email" placeholder="e.g. dana@example.com" autocomplete="email" maxlength="254" aria-describedby="profileEmailHint profileEmailError" required>
                <div class="form-text" id="profileEmailHint">Use the email address you sign in with.</div>
                <div class="field-error" id="profileEmailError" data-error-for="profileEmail"></div>
              </div>
              <div class="profile-actions"><p>Save your changes when you’re ready.</p><button class="btn btn-primary" type="submit" id="updateProfileBtn" disabled>Update Profile</button></div>
            </fieldset>
          </form>
        </div>
      </section>
      <section class="panel profile-card" aria-labelledby="securityHeading">
        <div class="panel-head"><div><h2 id="securityHeading">Security Settings</h2><p class="profile-hint">Keep your account protected.</p></div></div>
        <div class="panel-body">
          <div class="profile-security-intro">
            <span class="profile-security-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/></svg></span>
            <div><strong>A password only you know</strong><p>Confirm your current password to make a change.</p></div>
          </div>
          <div id="passwordFeedback" class="alert" role="status" aria-live="polite" hidden></div>
          <form id="passwordForm" method="post" action="update_profile.php" novalidate>
            <input type="hidden" name="action" value="password">
            <input type="hidden" name="csrf_token" id="passwordCsrf" value="<?= $csrfToken ?>">
            <fieldset id="passwordFields" disabled>
              <div class="mb-3">
                <label class="form-label" for="currentPassword">Current Password</label>
                <div class="profile-password-field">
                  <input class="form-control" data-password-input type="password" id="currentPassword" name="current_password" placeholder="Enter your current password" autocomplete="current-password" maxlength="200" aria-describedby="currentPasswordError" required>
                  <button class="profile-password-toggle" type="button" id="currentPasswordToggle" data-password-toggle="currentPassword" aria-controls="currentPassword" aria-label="Show current password" aria-pressed="false" title="Show current password">
                    <svg class="password-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="password-eye-slash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" hidden><path d="m3 3 18 18M10.6 5.1 12 5c6.5 0 10 7 10 7a20 20 0 0 1-3.1 3.8M6.2 6.2A21 21 0 0 0 2 12s3.5 7 10 7a12 12 0 0 0 5.8-1.5M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                  </button>
                </div>
                <div class="field-error" id="currentPasswordError" data-error-for="currentPassword"></div>
              </div>
              <div class="mb-3">
                <label class="form-label" for="newPassword">New Password</label>
                <div class="profile-password-field">
                  <input class="form-control" data-password-input type="password" id="newPassword" name="new_password" placeholder="Create a new password" autocomplete="new-password" minlength="12" maxlength="72" aria-describedby="passwordHint passwordStrengthText newPasswordError" required>
                  <button class="profile-password-toggle" type="button" id="newPasswordToggle" data-password-toggle="newPassword" aria-controls="newPassword" aria-label="Show new password" aria-pressed="false" title="Show new password">
                    <svg class="password-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="password-eye-slash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" hidden><path d="m3 3 18 18M10.6 5.1 12 5c6.5 0 10 7 10 7a20 20 0 0 1-3.1 3.8M6.2 6.2A21 21 0 0 0 2 12s3.5 7 10 7a12 12 0 0 0 5.8-1.5M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                  </button>
                </div>
                <div class="profile-strength is-empty" id="passwordStrength" role="meter" aria-labelledby="passwordStrengthLabel" aria-valuemin="0" aria-valuemax="3" aria-valuenow="0" aria-valuetext="No password entered">
                  <div class="profile-strength-heading"><span id="passwordStrengthLabel">Password strength</span><span id="passwordStrengthText" role="status" aria-live="polite">Not entered</span></div>
                  <div class="profile-strength-track" aria-hidden="true"><span class="profile-strength-fill"></span></div>
                </div>
                <div class="form-text" id="passwordHint">12–72 bytes with uppercase, lowercase, a number and a symbol (! @ # $ % ^ &amp; *). Avoid spaces and personal information.</div>
                <div class="field-error" id="newPasswordError" data-error-for="newPassword"></div>
              </div>
              <div class="mb-4">
                <label class="form-label" for="confirmPassword">Confirm New Password</label>
                <div class="profile-password-field">
                  <input class="form-control" data-password-input type="password" id="confirmPassword" name="confirm_new_password" placeholder="Re-enter your new password" autocomplete="new-password" minlength="12" maxlength="72" aria-describedby="confirmPasswordError" required>
                  <button class="profile-password-toggle" type="button" id="confirmPasswordToggle" data-password-toggle="confirmPassword" aria-controls="confirmPassword" aria-label="Show password confirmation" aria-pressed="false" title="Show password confirmation">
                    <svg class="password-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="password-eye-slash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" hidden><path d="m3 3 18 18M10.6 5.1 12 5c6.5 0 10 7 10 7a20 20 0 0 1-3.1 3.8M6.2 6.2A21 21 0 0 0 2 12s3.5 7 10 7a12 12 0 0 0 5.8-1.5M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                  </button>
                </div>
                <div class="field-error" id="confirmPasswordError" data-error-for="confirmPassword"></div>
              </div>
              <div class="profile-actions"><p>Choose a unique password for this account.</p><button class="btn btn-primary" type="submit" id="changePasswordBtn" disabled>Change Password</button></div>
            </fieldset>
          </form>
        </div>
      </section>
    </div>
    <noscript><p>Please enable JavaScript to update your account information.</p></noscript>
  </main>
</div>
<div id="toastZone"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/api.js?v=account-profile-edit-v1"></script>
<script src="assets/js/sidebar.js?v=sidebar-v3"></script>
<script src="assets/js/app.js?v=sidebar-v1"></script>
<script src="assets/js/profile.js?v=account-profile-ui-v1"></script>
</body>
</html>
