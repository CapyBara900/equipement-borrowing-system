# Equipment Borrowing and Management System — Phase 2 (Database + Backend)

Plain PHP (PDO) backend on **MySQL**, built for XAMPP + phpMyAdmin.

## 1. Set up the database
1. Start Apache and MySQL in the XAMPP Control Panel.
2. Open http://localhost/phpmyadmin, create a new database, e.g.
   `equipment_borrowing_system`.
3. Select it, open the **SQL** tab, paste the entire contents of
   `database/schema.sql`, and run it. This creates all 8 tables (`roles`,
   `users`, `categories`, `equipment`, `borrowing_requests`, `returns`,
   `equipment_condition_reports`, `notifications`) plus seed data.

## 2. Configure the PHP app
1. Copy `.env.example` to `.env` in the project root.
2. The defaults match a fresh XAMPP install (`root` user, no password). Only
   change `DB_NAME` if you named the database something else.

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

## Database design (matches the Phase 1 ERD, expanded to 8 tables)

| Table | Primary Key | Foreign Keys | Key Fields |
|---|---|---|---|
| roles | role_id | — | role_name |
| users | user_id | role_id → roles.role_id | name, email, password_hash, created_at |
| categories | category_id | — | category_name, description |
| equipment | equipment_id | category_id → categories.category_id | equipment_name, description, serial_number, status |
| borrowing_requests | request_id | user_id → users, equipment_id → equipment | request_date, borrow_date, expected_return_date, status |
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
| index.html | anyone | Sign in and register, with inline validation |
| dashboard.html | all roles | Staff/admin get desk-wide reporting and a chart; customers get their own loans and due dates |
| equipment.html | all roles | Catalog with search, category/status filters, sorting, pagination; request to borrow; admin CRUD; QR labels |
| requests.html | all roles | Customers see their own requests; staff/admin approve or decline |
| returns.html | staff, admin | Check items in, record condition, view return history |
| notifications.html | all roles | Status updates, mark as read |
| admin.html | admin | Manage accounts/roles and equipment categories |

## Where each Phase 3 requirement is demonstrated
1. **Responsive interface** — side rail collapses to a top bar + offcanvas menu
   under 992px; equipment rows restack under 576px. Bootstrap grid throughout.
2. **JavaScript functionality** — modals, dynamic list rendering, live nav
   badge, tabs, confirmation prompts, toasts, sort-direction toggle,
   debounced search input.
3. **API integration** — every screen is populated from the project's own REST
   API. A third-party API (api.qrserver.com) is also consumed in
   `fetchQrCode()` (assets/js/api.js): equipment.html fetches a QR label for
   an item's serial number as a blob and renders it.
4. **AJAX / fetch** — `assets/js/api.js` wraps all calls; approving a request,
   checking in equipment, filtering, and paging all update in place.
5. **Form validation** — `validate()` / `Rules` in `assets/js/app.js`. Covers
   required fields, email format, min/max length, password confirmation, and
   date logic (no past pick-up date; return must come after pick-up). Errors
   appear inline per field and also fire on blur.
6. **Search / filter** — equipment.html (keyword + category + status + sort +
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
