# Borrowing Management backend

Administrators and staff reuse the same borrowing APIs and status updater. The staff authorization fix leaves the existing frontend layout and database schema unchanged. Tests use isolated SQLite fixtures or temporary MySQL rows that are removed afterward.

## Current installation and audit requirement

The configured MariaDB database supports Borrowed, `picked_up_at`, `picked_up_by_staff_id`, and the `borrowing_status_history` table. The existing `returns` table stores the processing actor, returned quantity, and return date. Status history stores the authenticated actor and exact transition time, including approval and rejection. No migration is needed for the staff authorization fix.

Complete audit storage is required for both administrator and staff actions. Installations missing the table below return **503 `BORROWING_AUDIT_SCHEMA_REQUIRED`** without changing requests, stock, returns, condition reports, or notifications. Reads, direct customer requests, cart checkout, and pending-only customer self cancellation remain available. GET capabilities reports readiness and `management_roles: ["admin", "staff"]`.

Do not run the old pickup migration expecting it to create this table: its prepared files do not include it. No audit history is fabricated or backfilled for historical requests.

## Required Part 3 table

Part 3 must add `borrowing_status_history` without rewriting existing requests or quantities:

| Field | Required storage |
| --- | --- |
| `history_id` | Auto-increment integer primary key |
| `request_id` | Non-null integer matching `borrowing_requests.request_id`; foreign key with deletion restriction |
| `from_status` | Non-null borrowing status before the action |
| `to_status` | Non-null borrowing status after the action |
| `changed_by_user_id` | Non-null integer matching `users.user_id`; foreign key with deletion restriction |
| `changed_at` | Non-null DATETIME including seconds, in `APP_TIMEZONE`; supplied by the server |

Statuses use lowercase `pending`, `approved`, `borrowed`, `returned`, `rejected`, and `cancelled`. Require a unique key on `(request_id,to_status)` and an index on `(request_id,changed_at,history_id)`. Index the actor foreign key. All tables participating in a transition, including history and notifications, must use InnoDB. The readiness check verifies the required columns, time precision, and transactional engines; the Part 3 migration must supply the primary key, unique key, and restrictive foreign keys.

Keep the existing pickup column names: `picked_up_by_staff_id` stores the authenticated administrator or staff member's user ID. The exact return transition time is in the history table; retain the existing DATE-valued `returns.actual_return_date`.

## Routes and compatibility

Reuse `api/requests/index.php` and `api/returns/index.php`; no new lifecycle endpoint is required.

| Operation | Contract |
| --- | --- |
| GET requests | Existing flat, paginated borrowing history; customers are scoped to their own account |
| GET requests `?status=borrowed` | Exact item-level status filter; empty or `all` returns complete history |
| GET requests `?view=transactions` | All roles receive one transaction per request with one current `status`; customers are scoped to their own records |
| GET requests `?view=transactions&id=123` | One authorized request transaction with its checkout reference and saved history |
| GET requests `?checkout_id=45` | Authorized checkout members; works with either flat or grouped view |
| GET requests `?capabilities=1` | Statuses, allowed transitions, management roles, storage readiness, and missing requirements |
| POST requests | Existing customer submission and immediate quantity reservation |
| PUT requests `{request_id,status:"approved"}` or `{request_id,action:"approve"}` | Pending → Approved |
| PUT requests `{request_id,status:"rejected"}` or `action:"reject"` | Pending → Rejected |
| PUT requests `{request_id,status:"borrowed"}` or `action:"pickup"` | Approved → Borrowed, recording actual release |
| POST returns `{request_id,condition_status,remarks}` | Borrowed → Returned; existing return response includes `return_id` |
| PUT requests `{request_id,status:"returned",condition_status,remarks}` or `action:"return"` | Same return handler and checks |
| DELETE requests `?id=123` | Pending → Cancelled; administrator with audit storage, or the owning customer |
| PUT requests `{request_id,status:"cancelled"}` or `action:"cancel"` | Administrator cancellation with the same pending-only rule |

Approval, rejection, pickup, and return require an administrator or staff role in both the session and the current database record. `BORROWING_MANAGEMENT_ROLES` is shared by both endpoints, capabilities, and the status updater. Cancellation retains its existing permissions: administrators can cancel pending requests; customers can cancel only their own pending requests; staff cannot cancel. The actor row is locked during the mutation, so stale sessions cannot bypass revocation to an unauthorized role. Action and status must agree when both are supplied. Client actor IDs, quantities, pickup timestamps, and return timestamps never override stored data or the authenticated actor.

Flat rows preserve their original fields and additionally expose customer email, equipment description/serial/category, pickup actor name, return quantity/date/remarks/actor, `status_history`, and `status_history_available`. Grouped rows include customer details and all individual quantities, dates, statuses, and histories. No passwords, checkout payload hashes, or idempotency ledger data are exposed. Customer ownership applies to every detail and grouped query; unknown and foreign request IDs both return 404.

Borrowing Management and My Borrowings use `view=transactions`. Transaction IDs are `request:<request_id>`; checkout IDs remain references, since each equipment request is updated independently. `status` and the single-entry compatibility `statuses` array both come from `borrowing_requests.status`, never from audit history. Status/date filters and totals reuse the request query, preventing checkout siblings with other current statuses from appearing in a tab. Cards show one current status badge; after an action, the frontend re-reads authoritative transactions and recalculates tab placement and the displayed transaction count. My Borrowings also checks for Admin/Staff updates every 15 seconds while visible and on focus/visibility changes. No schema change or history backfill is needed.

`pickup_available` includes audit readiness; `pickup_storage_available` describes only the prepared pickup fields. `management_available` requires pickup storage and audit readiness. Older approved records remain approved until a physical pickup is recorded; elapsed dates or equipment status never imply Borrowed. Legacy Approved → Returned processing is now rejected. Existing records are preserved; expired historical approvals require an explicit later business-rule decision, rather than an invented pickup or automatic stock release.

## Quantities and concurrency

Pending creation already reserves units through `createBorrowingRequests`. That implementation is unchanged. Approval and pickup neither reserve again nor alter available quantities. Pending rejection and cancellation release the original reserved quantity once, using the existing `LEAST(total_quantity,available_quantity+requested_quantity)` calculation. Good returns release the stored requested quantity once; damaged, missing, and under-repair returns keep those units unavailable under the existing condition rules. Partial returns are not introduced.

The shared handler locks the actor, request, and equipment rows and atomically writes status, pickup/return evidence, condition reports, audit, stock restoration, and notification. Any audit or notification failure rolls everything back. Repeated or competing actions encounter the saved status and return 409; the existing unique return request key provides an additional duplicate guard. Deadlocks and lock timeouts return `RETRYABLE_CONFLICT`, instructing the caller to read the saved state before retrying. Other database failures return safe JSON without SQL details.

Request DELETE is cancellation rather than physical deletion. Customer and equipment deletion APIs reject deletion when borrowing history exists, preserving terminal records despite legacy cascade foreign keys. Actor accounts referenced by status history are also protected.

## Verification

- `php tests/borrowing_management_backend.php`: isolated future audit schema; lifecycle, live-role checks, malformed input, date bounds, stock rules, audit/notification failure rollback, history grouping, and deletion protection. SQLite validates transactions and behavior, not InnoDB lock semantics.
- `php tests/borrowing_management_mysql.php`: actual configured schema, temporary rows only; verifies the audit gate while storage is missing, and runs the full lifecycle when Part 3 is ready.
- Original `my_borrowings_pickup.php` and `borrowing_lifecycle.php` test commands now call these updated fixture and MySQL suites.
- `php tests/my_borrowings_backend.php`: real MySQL customer isolation, filters, grouped administrator reads, details, and safe errors.
- `php tests/borrowing_cart_mysql.php` and `php tests/borrowing_cart_http.php`: dated cart edits, checkout/replay, ownership, stock, customer cancellation, administrator permissions, and audit gates. HTTP runs the audited lifecycle when storage is ready.
- `node tests/borrowing_cart_concurrency.cjs`: real independent-connection checkout and reservation races.
- `node tests/my_borrowings_concurrency.cjs`: independent administrator connections race approval, pickup, and return; verifies one audit/notification per transition and one restoration. Reports SKIP until Part 3 audit storage exists.
- `node tests/borrowing_management_validation.cjs` and `node tests/borrowing_management_validation.cjs staff`: existing layout, all four actions, status tabs, messages, authoritative refresh, date gates, and duplicate protection for both management roles.
- `php tests/borrowing_cart_http.php`: actual Apache sessions exercise administrator and staff lifecycle actions, rejection, transition/cancellation guards, and staff audit visibility in customer/admin/staff history.
- `node tests/my_borrowings_concurrency.cjs staff`: staff/admin connections race approval, pickup, and return using the shared updater; verifies one audit/notification per transition and one restoration.

Use `C:/xampp/php/php.exe` when PHP is not on PATH. The configured database supports full administrator/staff lifecycle and concurrency verification. Do not reapply migrations as part of an authorization fix.
