# Authentication UI

Both authentication forms share `index.html` and `assets/js/login.js`. The `register.php` entry route redirects to `index.html#register`; the Create one / Back to Login links switch the shared views, and browser hash navigation selects the corresponding form. The registration footer has a Back to Login arrow link. It selects #signin, focuses the email field, and scrolls the form into view, respecting reduced-motion preferences. This keeps layout and validation consistent across both entry points.

## Files

- `index.html`: split-screen branding, labelled forms, alert slots, eye/eye-slash controls, registration strength/checklist/match indicators, disabled initial buttons, and spinner markup.
- `assets/css/styles.css`: the scoped Authentication section styles the white rounded card, dark branding panel, input borders and focus rings, password icons, alerts, buttons, strength levels, and mobile stacking. The auth layout and fields work without Bootstrap utility classes.
- `assets/js/login.js`: vanilla JavaScript using the existing `Rules`, `validate`, and `Api` helpers. AJAX calls still use `api/auth/login.php` and `api/auth/register.php`.
- `register.php`: shared registration entry redirect; the registration API remains `api/auth/register.php`.

## Button and validation behavior

Sign In (btn-sign-in) starts disabled and enables when both trimmed email and trimmed password are non-empty. Whitespace-only values keep it disabled. Email format and length are validated on submission. Passwords are never trimmed, including when visible.

Create Account starts disabled and enables only when all fields satisfy the existing registration rules: full name, valid email, password of at least 12 characters with uppercase, lowercase, a number, and a symbol from ! @ # $ % ^ & *, no spaces or personal name/email parts, and an exact matching confirmation. New passwords are capped at 72 bytes to avoid bcrypt truncation. Known duplicate email/name values block submission until edited; the server remains authoritative for uniqueness.

Email availability is checked on blur. Outdated results are ignored after input changes. These background lookups allow an otherwise valid form to submit; the creation endpoint performs its own uniqueness check. Password/name/email changes immediately recompute registration eligibility, strength, and confirmation feedback.

## Feedback and visibility

The three password fields use matching embedded icons and keyboard-accessible Show/Hide buttons with aria-controls, aria-pressed, descriptive labels, and visible focus outlines. Revealed passwords auto-hide after eight seconds, preserving the previous registration behavior. Typing restarts the timer; switching forms hides all passwords. Native additional password-reveal icons are suppressed.

The password strength guide shows a colored bar and text: red Weak, yellow Medium, green Strong. Strong requires all new-password rules and at least 16 characters. The guide is separate from minimum registration eligibility. A checklist shows the length, case, number, and symbol requirements. Confirmation shows a green border and Passwords match status when matching, or a red border and mismatch error when different.

Server errors appear persistently above the relevant form. Both forms lock during submission, and the active button shows a spinner, loading text, and aria-busy. A shared busy guard also prevents Enter-key and scripted duplicate submissions. Failed requests preserve values for correction and retry. Successful sign-in navigates to Overview. Successful account creation returns to an empty sign-in form with a success alert and clears registration values, toggles, strength, and match status.

## Verification

```powershell
node tests/auth_ui_validation.cjs
node tests/account_profile_validation.cjs
node tests/staff_access_validation.cjs
C:/xampp/php/php.exe -l register.php
```

The auth regression test verifies button gating, exact visible password validation, strength/mismatch status, pending-submit protection, failure recovery, duplicate feedback, stale availability results, resets, and navigation. Headless Edge checks verified the desktop split layout, one password toggle per field, loading/error feedback, registration success/reset using mocked API responses, and mobile layout at 390px without horizontal overflow. The direct register.php HTTP route returns 302 to index.html#register. No real account was created by these browser tests.
