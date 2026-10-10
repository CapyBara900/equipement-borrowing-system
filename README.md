# Equipment Borrowing and Management System — Phase 2 (Database + Backend)

Plain PHP (PDO) backend on **MySQL**, built for XAMPP + phpMyAdmin.

## 1. Set up the database
1. Start Apache and MySQL in the XAMPP Control Panel.
2. Open http://localhost/phpmyadmin, create a new database, e.g.
   `equipment_borrowing_system`.
3. For a **fresh database only**, select it, open the **SQL** tab, paste the entire contents of
   `database/schema.sql`, and run it. This creates all 8 tables (`roles`,
   `users`, `categories`, `equipment`, `borrowing_requests`, `returns`,
   `equipment_condition_reports`, `notifications`) plus seed data.
   If upgrading an existing database, run `database/migration_email_validation.sql`
   to expand the email column to 254 characters and apply the case-insensitive
   collation used for normalized email uniqueness.
   Run `database/migration_equipment_quantities.sql` as well when upgrading an
   existing database; it adds total/available inventory and request/return
   quantities. The migration also repairs available stock from active pending,
   approved, and borrowed requests, rather than treating the legacy equipment status as
   a quantity.

   Run `database/migration_borrowing_time_limit.sql` once for existing databases
   before using the updated app. Existing equipment defaults to a 7-day limit.
   Admins can set 1–3650 days when adding or editing equipment.
   The latest return date is pickup date plus that many calendar days (inclusive);
   same-day returns are allowed. Changes apply to new requests; existing requests
   keep their agreed dates.

## 2. Configure the PHP app
1. Copy `.env.example` to `.env` in the project root.
2. The defaults match a fresh XAMPP install (`root` user, no password). Only
   change `DB_NAME` if you named the database something else.

Pickup dates default to today and allow today through seven calendar days in
advance, inclusive. Set `APP_TIMEZONE` in `.env` to an IANA timezone (default:
`Asia/Manila`). The customer calendar receives its bounds from the server, and
the request API enforces the same local-date window before reserving stock.
Return dates still use the selected pickup date plus the equipment limit.

## Borrowing cart database upgrade

After configuring .env and applying the existing quantity/time-limit migrations, run:

```text
C:\xampp\php\php.exe database\migrate_borrowing_cart.php --dry-run
C:\xampp\php\php.exe database\migrate_borrowing_cart.php
C:\xampp\php\php.exe database\migrate_cart_item_dates.php --dry-run
C:\xampp\php\php.exe database\migrate_cart_item_dates.php
```

These additive, resumable migrations enable persistent customer carts, durable
idempotency, and grouped request IDs while retaining existing request rows and
inventory. They add two InnoDB tables and constraints/indexes, and record
cancellation as a status so history is retained. They never reset existing data.
The second migration adds independent per-entry quantities/dates and permits
separate dated entries for the same equipment. Existing undated cart entries
remain as drafts requiring dates before checkout. Fresh installations also run both migrations after schema.sql. Do not rerun
schema.sql on an existing database. See [BORROWING_CART.md](BORROWING_CART.md)
for the schema/API contract and MySQL, concurrency, and real HTTP tests.


## My Borrowings database upgrade

After the borrowing cart migrations, run:

```text
C:/xampp/php/php.exe database/migrate_my_borrowings.php --dry-run
C:/xampp/php/php.exe database/migrate_my_borrowings.php
```

This additive migration enables a distinct Borrowed status with actual pickup
attribution, integrity checks, and a customer/status history index. It preserves
all historical statuses, quantities, dates, and checkout groups. The CLI runner
preflights the schema/data, supports safe reruns, and verifies original-record
fingerprints. Fresh installations also run it after the two cart migrations;
never rerun schema.sql against an existing database. See
[MY_BORROWINGS_DATABASE.md](MY_BORROWINGS_DATABASE.md) for the schema assessment,
changes, and verification, and [MY_BORROWINGS_BACKEND.md](MY_BORROWINGS_BACKEND.md)
for the API contract.

## 3. Create the first admin account
```
C:\xampp\php\php.exe database\create_admin.php "Your Name" admin@ebs.com "SomeStrongPassword123"
```
(Mac/Linux: `/Applications/XAMPP/bin/php database/create_admin.php ...`)

## 4. Run it
Put the `ebs` folder inside `htdocs`, start Apache, then hit
`http://localhost/ebs/api/auth/login.php` (POST) etc.

## Folder structure
```
ebs/
├── config/database.php            PDO MySQL connection (reads .env)
├── includes/
│   ├── bootstrap.php              headers, session, CORS — included by every endpoint
│   ├── response.php                sendJson() / getJsonBody() / cleanText() helpers
│   ├── auth_middleware.php         requireLogin() / requireRole() guards
│   └── notifications.php           notifyUser() helper
├── api/
│   ├── auth/register.php|login.php|logout.php|me.php
│   ├── roles/index.php             GET       admin — list roles (for dropdowns)
│   ├── users/index.php             GET/POST/PUT/DELETE  admin — accounts + roles
│   ├── categories/index.php        GET/POST/PUT/DELETE  equipment categories
│   ├── equipment/index.php         GET/POST/PUT/DELETE  equipment catalog
│   ├── requests/index.php          GET/POST/PUT/DELETE  borrowing requests + approval
│   ├── returns/index.php           GET/POST             processing returned equipment
│   ├── condition-reports/index.php GET/POST             equipment condition history
│   ├── notifications/index.php     GET/PUT              view / mark-as-read
│   └── dashboard/index.php         GET                  summary counts, chart data, recent activity
└── database/
    ├── schema.sql                  table definitions + seed data
    └── create_admin.php            one-time CLI script to create an admin
```

## Database design (10 tables after the borrowing cart migration)

| Table | Primary Key | Foreign Keys | Key Fields |
|---|---|---|---|
| roles | role_id | — | role_name |
| users | user_id | role_id → roles.role_id | name, email, password_hash, created_at |
| categories | category_id | — | category_name, description |
| equipment | equipment_id | category_id → categories.category_id | equipment_name, description, serial_number, status |
| borrowing_requests | request_id | user_id → users, equipment_id → equipment, nullable checkout_id → borrowing_checkouts, nullable picked_up_by_staff_id → users | request_date, borrow_date, expected_return_date, requested_quantity, status, picked_up_at |
| borrowing_cart_items | cart_item_id | user_id → users, equipment_id → equipment | quantity, borrow_date, expected_return_date, created_at, updated_at; unique customer/equipment/dates |
| borrowing_checkouts | checkout_id | user_id → users | idempotency_key, payload_hash, response_json, borrow_date, expected_return_date, created_at |
| returns | return_id | request_id → borrowing_requests, processed_by_staff_id → users | actual_return_date, remarks |
| equipment_condition_reports | report_id | equipment_id → equipment, reported_by_user_id → users | condition_status, notes, logged_at |
| notifications | notification_id | user_id → users | message, is_read, created_at |

### Relationships
- roles (1) — (many) users
- categories (1) — (many) equipment
- users (1) — (many) borrowing_requests
- equipment (1) — (many) borrowing_requests
- borrowing_requests (1) — (0 or 1) returns
- equipment (1) — (many) equipment_condition_reports
- users (1) — (many) notifications

## Security notes for the report
- Every query uses PDO **prepared statements** (protects against SQL injection).
- `cleanText()` strips tags from free-text input before it's stored (basic stored-XSS protection).
- Passwords are hashed with `password_hash()` (bcrypt) and checked with `password_verify()`.
- Session ID is regenerated on login (`session_regenerate_id()`) to prevent session fixation.
- `requireLogin()` / `requireRole()` enforce authentication and role-based authorization on
  every protected endpoint.

## What's left for later phases
- Phase 3: build the actual frontend (HTML/CSS/JS or Bootstrap) that calls these
  endpoints with `fetch()`, plus client-side form validation.
- Phase 4: run through the security checklist (SQLi/XSS/auth/authorization tests) and UI edge-case testing.
  The frontend and backend both validate important fields; backend validation remains the final authority.
- Don't forget the **AI Usage Log** deliverable — log this session's prompts/outputs now
  rather than reconstructing it later.

---

# Phase 3 — Frontend + API

The frontend is plain HTML/CSS/JS (Bootstrap 5 for the responsive grid and
modals) that talks to the Phase 2 API entirely through `fetch()`. No page
reloads a second time to get data.

## Pages
| File | Who | What it does |
|---|---|---|
| index.html / register.php | anyone | Sign in and create accounts, with dynamic validation, password strength/match feedback, and loading states |
| dashboard.html | all roles | Staff/admin get desk-wide reporting and a chart; customers get their own loans and due dates |
| equipment.php | admin, customer | Catalog with search, category/status filters, sorting, pagination; request to borrow; admin CRUD; QR labels |
| requests.html | all roles | Customer My Borrowings history with status tabs; staff/admin manage approvals, pickups, returns with condition/remarks, and returned transaction details |
| notifications.html | all roles | Status updates, mark as read |
| admin.html | admin | Manage accounts/roles and equipment categories |
| profile.php | all roles | Edit your own Full Name / Username and email, change your password, and view your role |

## Where each Phase 3 requirement is demonstrated
1. **Responsive interface** — side rail collapses to a top bar + offcanvas menu
   under 992px; equipment rows restack under 576px. Bootstrap grid throughout.
2. **JavaScript functionality** — modals, dynamic list rendering, live nav
   badge, tabs, confirmation prompts, toasts, sort-direction toggle,
   debounced search input.
3. **API integration** — every screen is populated from the project's own REST
   API. A third-party API (api.qrserver.com) is also consumed in
   `fetchQrCode()` (assets/js/api.js): equipment.php fetches a QR label for
   an item's serial number as a blob and renders it.
4. **AJAX / fetch** — `assets/js/api.js` wraps all calls; approving a request,
   checking in equipment, filtering, and paging all update in place.
5. **Form validation** — `validate()` / `Rules` in `assets/js/app.js`. Covers
   required fields, email format, strong password requirements, password confirmation, and
   date logic (no past pick-up date; return on or after pick-up). Errors
   appear inline per field and also fire on blur.
6. **Search / filter** — equipment.php (keyword + category + status + sort +
   pagination) and requests.html (status + date range).

## Security carried into the frontend
- `esc()` escapes every database value before it reaches the DOM, so a stored
  `<script>` tag renders as text (the backend also strips tags on input).
- `requireSession()` redirects signed-out visitors, and hides controls the
  person's role can't use — but the backend still enforces permissions, so
  hiding a button is convenience, not the security boundary.

## Note on the QR feature
`api.qrserver.com` is called from the browser, so it needs internet access on
the machine running the demo. If it's unavailable the modal shows an error and
nothing else breaks.

## Equipment category management

Admins can use **Add Category** and **Manage categories** on the Equipment
page. The admin People & categories tab uses the same editor. Customers can view
and filter categories but cannot manage them. Names are trimmed, repeated
whitespace is collapsed, and the database enforces case-insensitive uniqueness.
Names require at least one letter or number, allow up to 100 Unicode characters,
and reject markup/control characters. Descriptions allow up to 500 characters.

Upgrade existing databases from the project directory (do not rerun schema.sql):

```text
C:\xampp\php\php.exe database\migrate_category_management.php --dry-run
C:\xampp\php\php.exe database\migrate_category_management.php
```

The migration preserves category IDs and equipment assignments, normalizes
existing names, sets the category-name unique index to a case-insensitive
collation, and changes the equipment foreign key to ON DELETE RESTRICT. It
preflights invalid/colliding names before changing data; resolve any reported
legacy names first. Run during a maintenance window for the schema alterations.
It is safe to rerun. Fresh installations already include these constraints.

Category GET supports all=1 for complete dropdowns (including more than 50
categories; staff are denied category reads and event streams). POST/PUT/DELETE require admin; duplicate names and assigned
category deletions return 409 with CATEGORY_DUPLICATE or CATEGORY_IN_USE.
Invalid input returns 400; missing categories return 404. Equipment saves also
validate the chosen category. Assigned categories cannot be deleted: reassign
equipment to another category first.

The shared catalog updates dropdowns and listings after each save without a
page reload. Same-browser tabs receive change broadcasts; other active sessions
receive server-sent snapshots, normally within one second. A five-second poll
is used as fallback when event streams are unavailable, and returning to a tab
refreshes its catalog. Streaming releases the PHP session lock before waiting.

Verification:

```text
C:\xampp\php\php.exe tests\category_management_api.php
node tests/category_management_validation.cjs
```

## Borrowing Management database upgrade

The additive [Borrowing Management migration](BORROWING_MANAGEMENT_DATABASE.md)
adds complete action history, preserves checkout ownership and borrowing records,
and keeps the existing stock reservations. Use `database/migrate_borrowing_management.php`
with `--dry-run` to inspect the plan, then run it without that flag. Existing databases
must use the migration runners rather than rerunning `database/schema.sql`.

Staff access is limited to Overview and Borrowing Management. Equipment is served by `equipment.php` after a server-side session/role check; `equipment.html` redirects old bookmarks to that protected route. Staff receive HTTP 403 from equipment APIs (including reads), category APIs/event streams, and standalone condition-report APIs. Only admins manage equipment and categories; admins and staff retain borrowing approvals, pickups, returns, and return condition recording through the borrowing APIs. No database migration is required.

## Account profile

The account name and role in the desktop and mobile menus open `profile.php`
for customers, admins, and staff. The page uses the existing styles and Back
returns to the previous app page, with Overview as a fallback for direct visits.
`api/auth/me.php` reads the current account from `users` joined to `roles`, using
only the authenticated session user ID; URL parameters cannot select another
account. It returns no password or account-management fields and rejects missing
or deleted accounts. Account details and session name/email/role refresh from
the database on each page load. Profile Information and Security Settings forms
now submit through AJAX with CSRF protection and prepared updates. Existing
databases need the unique-name migration; see [ACCOUNT_PROFILE.md](ACCOUNT_PROFILE.md)
for the complete implementation, SQL, API contract, duplicate preflight, and upgrade steps.

Verification: `node tests/account_profile_validation.cjs` and
`C:/xampp/php/php.exe tests/account_profile_http.php` (with Apache/MySQL running),
and `C:/xampp/php/php.exe tests/account_profile_updates.php` for isolated update tests.

## Authentication UI

The split-screen sign-in and Create Account views share `index.html`;
`register.php` opens the registration view directly. See [AUTH_UI.md](AUTH_UI.md)
for the HTML/CSS/vanilla JavaScript implementation, dynamic button rules,
server feedback, visibility toggles, and validation checks.

See [DASHBOARD_UI.md](DASHBOARD_UI.md) for the role-adaptive overview, status filters, due-date badges, and dashboard validation checks.

See [CATALOG_UI.md](CATALOG_UI.md) for the equipment grid/list layouts, AJAX filters, stock indicators, and role-scoped quick-view history.
