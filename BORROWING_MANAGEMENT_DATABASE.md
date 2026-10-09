# Borrowing Management database migration

The additive migration has been applied to the local MariaDB 10.4.32 database. The existing API now reports `audit_available`, `pickup_available`, and `management_available` as true. This phase changes database migration files, database documentation, and test fixtures only; frontend and backend application files were verified unchanged.

## Existing structure and references

`borrowing_checkouts` already represents a checkout transaction and its idempotency ledger. `borrowing_requests` already represents each equipment item. The migration reuses both tables, their primary IDs, and the existing dated-item unique key. It does not create a second transaction table or combine unrelated legacy requests.

The existing API's unique transaction references remain `Checkout #<checkout_id>` for grouped items and `Request #<request_id>` for standalone records. Primary keys enforce unique numbers; the different prefixes prevent collisions between those namespaces. Items in one checkout intentionally share the checkout reference. No new required reference field is introduced that the existing checkout API could leave blank.

Each item retains its own customer, equipment, quantity, pickup date, return date, and status. A new composite foreign key `(checkout_id,user_id)` requires a grouped item's customer to match its checkout customer. Nullable `checkout_id` preserves standalone records. Legacy nullable dates remain nullable; supplied dates must have Return By on or after Pick Up On. Same-day returns remain valid.

## Status and quantity integrity

The inspected request enum already contains all six stored statuses: `pending`, `approved`, `borrowed`, `returned`, `rejected`, and `cancelled`. Its existing enum positions and default `pending` are preserved. Apply the existing cart and pickup migrations first if upgrading an older installation without these statuses.

The migration retains the enforced quantity checks: total stock is at least one, available stock is between zero and total stock, and requested/returned quantities are positive. Existing pickup evidence/date checks and the unique return-per-request key remain intact. All borrowing, stock, audit, return, and notification tables use InnoDB.

The reservation calculation remains in the existing backend. Pending requests already reserve stock immediately. Approval and pickup retain that reservation; rejection, pending cancellation, and a good return release it once. Damaged/missing/under-repair returns retain the held quantity according to the existing rules. There are no new stock triggers, recalculations, deductions, or restorations in this migration. Preflight checks that active pending/approved/borrowed quantities plus available stock do not exceed total stock. Cross-table reservation accounting and administrator authorization continue to be enforced by the existing transactional APIs.

## Administrator action history

The only new table is `borrowing_status_history`:

| Field | Meaning |
| --- | --- |
| `history_id` | Auto-increment primary key and stable ordering for equal timestamps |
| `request_id` | Affected item; its checkout is available through the existing request relationship |
| `from_status` / `to_status` | Previous and new borrowing status |
| `changed_by_user_id` | Actual administrator performing the action, or customer performing an allowed self cancellation |
| `changed_at` | Non-null DATETIME with seconds; the API writes it in the configured application timezone |

Both request and actor foreign keys use RESTRICT. The unique `(request_id,to_status)` key prevents duplicate audit events in a lifecycle that cannot revisit a status. The audit CHECK allows only pending to approved/rejected/cancelled, approved to borrowed, and borrowed to returned. Chronological item-history and actor/time indexes support retrieval. A `(status,request_date,request_id)` request index supports management status lists.

The existing backend writes the status, pickup/return evidence, stock changes, audit event, and notification in one transaction, with row locks. It requires complete audit storage before administrator mutations, as requested. Database structure supports that requirement; application authorization is unchanged.

Historical approvals/rejections/cancellations that never recorded an actor or time are preserved without invented audit events. The new table starts empty and records subsequent real transitions. Previously known pickup and return evidence remains in its existing columns/tables.

## History-preserving relationships

Customer, equipment, checkout, return, and condition-report relationships now use ON DELETE/UPDATE RESTRICT where deletion previously cascaded or erased actor/group evidence. Existing nullable actor fields remain nullable; known actors can no longer be cleared automatically by deleting their accounts. Deleting an entity referenced by borrowing history is rejected by the database. Unsaved cart entries and notifications retain their existing relationships.

Replacement foreign keys use `_preserve` names so MariaDB can drop the old key and add the new key in one ALTER without a constraint-name collision or an interval without foreign key protection. The older cart migration now recognizes the checkout relationship by its columns when rerun. The pickup migration checks pickup storage independently of audit readiness, so the migration dependency order remains valid.

## Safe application and recovery

For an existing installation, use the CLI runners from the project root. **Do not run `database/schema.sql` or the legacy quantity initialization SQL against an existing database.**

Prerequisites, if not already applied:

```text
C:\xampp\php\php.exe database\migrate_borrowing_cart.php
C:\xampp\php\php.exe database\migrate_cart_item_dates.php
C:\xampp\php\php.exe database\migrate_my_borrowings.php
```

Inspect, review the plan, and apply:

```text
C:\xampp\php\php.exe database\inspect_borrowing_management.php
C:\xampp\php\php.exe database\migrate_borrowing_management.php --dry-run
C:\xampp\php\php.exe database\migrate_borrowing_management.php
```

The runner shares the advisory migration lock with the existing cart/pickup migrations. It validates engines, enforced CHECKs/FKs, identifiers, compatible named objects, dates, relationships, quantities, ownership, and current reservations before any DDL. An incompatible existing audit table is rejected rather than silently accepted or replaced. It never repairs inconsistent records automatically.

Before the first required DDL step it creates a private SQL recovery export in the invoking user's temporary directory, outside the served project, and prints its path. The file contains account/application data: keep it private. Import a recovery export into a **separate empty database** for inspection/recovery; it is not an automatic rollback script for the live database.

MySQL/MariaDB DDL commits implicitly. Completed steps are therefore resumable, rather than pretending the entire migration can roll back. Rerunning validates and skips compatible completed steps and does not produce another backup when nothing needs changing. A failure prints the server error and completed steps. Keep the recovery export and resolve the reported condition before resuming.

Run during a maintenance window with application writes paused for a stable fingerprint comparison. The advisory lock serializes migrations, not application traffic. ALTER statements preserve existing rows; before/after SHA-256 fingerprints additionally detect concurrent data changes instead of masking them.

## Verified locally

At application, all 61 original rows across 10 tables had matching fingerprints, including eight borrowing items, one checkout, three cart entries, and all 12 equipment records and stock quantities. The audit table was initially empty. An additional non-fixture customer request arrived after migration; that record and its current stock reservation were retained. Test fixtures are explicitly cleaned in dependency order or rolled back, so they do not rely on the retired cascading delete rules.

The initial recovery export, created before any DDL was applied, is:

```text
C:\Users\yuris\AppData\Local\Temp\ebs-before-borrowing-management-20261009-013307-1f78d078.sql
```

Validation passed:

```text
C:\xampp\php\php.exe tests\borrowing_management_database.php
C:\xampp\php\php.exe tests\borrowing_management_mysql.php
C:\xampp\php\php.exe tests\borrowing_cart_mysql.php
C:\xampp\php\php.exe tests\borrowing_cart_http.php
C:\xampp\php\php.exe tests\my_borrowings_backend.php
node tests\my_borrowings_concurrency.cjs
node tests\borrowing_cart_concurrency.cjs
```

The database test covers incompatible-object rejection, migration reruns, checkout/customer ownership, independent quantities/dates, reference grouping, stock/date/status bounds, restrictive relationships, accurate audit actors/times, allowed audit transitions, duplicate audits, and duplicate returns. Its fixtures roll back completely. Existing integration tests cover the authenticated admin lifecycle, customer checkout/history/cancellation, API status updates, idempotent checkout, rollback, and stock accounting. Independent connection races verify one audit/notification per transition, one restoration per return, and no checkout overselling. Earlier cart, dated cart, and pickup migration dry runs also pass against the upgraded schema.
