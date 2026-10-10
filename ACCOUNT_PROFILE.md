# Account profile editing

The complete implementation is in:

- `profile.php`: session-protected page, Profile Information and Security Settings cards, per-session CSRF tokens, accessible labels and alerts.
- `assets/js/profile.js`: validation, AJAX submissions, pending states, success/error feedback, sidebar updates, and password-field clearing after success.
- `assets/js/api.js`: `Api.updateProfile(data)` sends JSON with the same-origin PHP session cookie.
- `update_profile.php`: public compatibility route forwarding to `api/auth/update_profile.php`.
- `api/auth/update_profile.php`: authentication, CSRF, prepared queries, duplicate checks, password verification/hashing, transactions, and JSON responses.
- `includes/account_profile.php`: name validation and shared duplicate detection, also used by registration and admin account creation.

## Database structure and upgrade

This project uses `users.user_id`, `users.name`, `users.email`, and `users.password_hash`.
The existing `name` column is the editable Full Name / Username; there is no separate username column. As requested, both name and email are unique across accounts. The unique indexes use `utf8mb4_unicode_ci`, so comparisons ignore case and accents. Roles and account ownership cannot be changed through the profile form.

Fresh installs use the updated `database/schema.sql`. Existing installs use:

```powershell
C:/xampp/php/php.exe database/migrate_account_profile.php --dry-run
C:/xampp/php/php.exe database/migrate_account_profile.php
```

The runner checks for duplicates before changing the schema and can be rerun. It preserves IDs, borrowing records, existing passwords, and account data. If duplicate values exist, it reports their user IDs and exits before schema changes. Resolve those values, then rerun the commands. Do not rerun the fresh-install schema on an existing database.

The SQL alternative is `database/migration_account_profile.sql`. Its initial SELECT queries identify duplicates; resolve all returned groups before running ALTER TABLE. The raw SQL ALTER is intended for one-time use; the CLI runner supports repeat execution.

The current local database preflight found duplicate names at user IDs **2, 3, 5, 7, 138**. The migration has not been applied to that database. Until the unique constraint is installed, API duplicate prechecks work but simultaneous writes cannot be fully protected by a name index.

## Profile UI

The two cards stretch to equal heights on desktop and stack on smaller screens, with action buttons aligned at the bottom. The initials avatar uses the saved name (first/last initials, or the first two characters of a single name) and updates after a successful profile save. Its small badge identifies an initials avatar; it is a static placeholder rather than a photo-upload control.

All password fields have keyboard-accessible Show/Hide buttons with descriptive labels, aria-controls, and aria-pressed states. Toggling visibility preserves the exact password and its validation rules. Successful password changes clear the fields, restore hidden passwords, and reset the strength meter.

The local strength guide uses red for Weak, yellow for Medium, and green for Strong, with text labels and accessible meter values. Strong requires the new-password rules to pass and at least 16 characters; the strength guide does not replace form/backend validation. Scoped CSS adds full-width fields, rounded borders, blue focus rings, muted hints, and disabled action buttons at 50% opacity with gray backgrounds and a not-allowed cursor.

## Form button states

Both submit buttons are disabled in the initial HTML. Update Profile enables when the trimmed name or email differs from the last loaded/saved values, and disables if both are reverted. A successful profile save records the returned values as the new baseline; failed saves retain the previous baseline for retries. Field validation still runs before submission.

Change Password enables only when every password field passes the existing validation rules, including confirmation matching, a different new password, and exclusion of saved name/email information. Clearing a field or editing either password to break the match disables the button immediately. Both buttons remain disabled while loading or submitting, and password success clears its fields and disables its button.

## API contract

POST either `update_profile.php` or `api/auth/update_profile.php`, using JSON and the authenticated session cookie. Obtain the CSRF token from the signed-in profile page's hidden field. GET and other methods return 405; unauthenticated POST returns 401; invalid CSRF returns 403; validation failures return 400; duplicates return 409.

Profile body:

```json
{
  "action": "profile",
  "csrf_token": "<token from profile.php>",
  "name": "Juan Dela Cruz",
  "email": "juan@example.com"
}
```

Name is trimmed, requires 3–100 Unicode characters and at least one letter or number, and permits letters, combining marks, numbers, single spaces, periods, underscores, apostrophes, and hyphens. Email is trimmed, lowercased, limited to 254 characters, and validated using the existing app rules. Unchanged own values are permitted, subject to the unique-name requirement.

Password body:

```json
{
  "action": "password",
  "csrf_token": "<token from profile.php>",
  "current_password": "<current password>",
  "new_password": "<new password>",
  "confirm_new_password": "<same new password>"
}
```

Current password is checked with `password_verify()`. The new password must match its confirmation and satisfy the existing policy: at least 12 characters, uppercase and lowercase letters, a number, a special character from ! @ # $ % ^ & *, no whitespace, and no name/email information. It must differ from the current password. New passwords are limited to 72 bytes to avoid bcrypt truncation and reject null bytes; passwords are never trimmed. The backend hashes with `password_hash($newPassword, PASSWORD_DEFAULT)`.

Successful response:

```json
{
  "success": true,
  "message": "Profile updated successfully.",
  "data": {"user_id": 42, "name": "Juan Dela Cruz", "email": "juan@example.com", "role": "customer"}
}
```

Password success uses the message `Password changed successfully.`. No response contains a password or hash. Errors include `success: false`, `message`, and, for field-specific errors, a `code`. Session details refresh after success. The user remains signed in; this implementation does not invalidate their other existing sessions.

## Prepared UPDATE queries

The account ID comes exclusively from the authenticated session. Each request changes only its selected category:

```php
$stmt = $db->prepare('UPDATE users SET name = :name, email = :email WHERE user_id = :user_id');
$stmt->execute(['name' => $name, 'email' => $email, 'user_id' => $userId]);

$stmt = $db->prepare('UPDATE users SET password_hash = :password_hash WHERE user_id = :user_id');
$stmt->execute([
    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
    'user_id' => $userId,
]);
```

Both operations run inside a transaction after locking the current account row with SELECT FOR UPDATE. Unique indexes catch conflicts occurring after the duplicate precheck. Password verification uses the locked row's latest hash.

## Verification

```powershell
node tests/account_profile_validation.cjs
C:/xampp/php/php.exe tests/account_profile_http.php
C:/xampp/php/php.exe tests/account_profile_updates.php
```

The first test checks navigation and AJAX behavior, success/failure messages, input preservation, double-submit prevention, and clearing passwords after success. The second checks the existing live profile/session behavior using temporary accounts. The update test creates a temporary isolated MySQL database and PHP HTTP server, checks migration dry-run/rerun, all three roles, duplicates and constraints, CSRF, malformed input, account isolation, password failures/success, old/new logins, Unicode usernames, and deleted accounts. It removes the test database and temporary sessions on completion. The database user needs CREATE/DROP DATABASE permission for this isolated test.

PHP/JavaScript syntax checks and all three test scripts passed. Headless Edge checks confirmed live avatar initials, equal desktop card heights, mobile stacking without horizontal overflow, visibility-toggle accessibility states, strength levels, and validation while passwords are visible. Full visual verification was limited because this environment blocks the existing external Bootstrap/font CDN resources.
