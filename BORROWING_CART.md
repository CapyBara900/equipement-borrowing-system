# Borrowing cart — Parts 1–3

## Deployment status

Part 3 has been applied to the configured MariaDB database. Persistent customer carts and atomic cart checkout are operational through the running Apache APIs. Every row in the eight pre-existing tables was fingerprinted before migration and verified unchanged afterward; existing requests retain their IDs and have a NULL checkout_id. The migration does not recalculate or reset inventory or backfill guessed request groups.

Use these commands for another existing installation, after configuring .env and applying the earlier quantity/time-limit migrations:

```text
php database/migrate_borrowing_cart.php --dry-run
php database/migrate_borrowing_cart.php
php database/migrate_cart_item_dates.php --dry-run
php database/migrate_cart_item_dates.php
```

The CLI runner applies database/migration_borrowing_cart.sql, checks InnoDB/version requirements and existing quantity/reservation consistency, serializes migration runs with a database advisory lock, and skips completed column/index/constraint/enum additions on reruns. It refuses to silently overwrite an incompatible pre-existing cart table. No existing rows are deleted or rewritten. MySQL DDL commits implicitly; if a step fails, correct its cause and rerun the additive migration. The SQL file can be imported once directly, but the CLI runner is the recommended resumable path. Never rerun database/schema.sql against an existing database: that file is the original reset/seed script for a fresh installation.

Supported engines: MariaDB 10.2.1+ or MySQL 8.0.16+ with enforced CHECK constraints. The live installation uses MariaDB 10.4.32. Application PDO connections preserve the server's SQL modes and add STRICT_TRANS_TABLES so negative/overflowing unsigned quantities fail rather than being silently clamped. This does not modify global server settings.

The dated-entry migration is also applied to this installation. All original columns and row counts across the ten pre-existing tables were fingerprinted before and after this upgrade and its tests, and verified unchanged. It gives each cart entry a stable ID and its own dates, replaces equipment-only uniqueness with equipment/date uniqueness, and allows same-day returns consistently with direct requests. It leaves existing cart dates NULL as preserved drafts requiring the customer to choose dates before checkout. Both CLI migrations can be rerun without resetting data.

## Database model

| Table/change | Purpose and integrity rules |
| --- | --- |
| borrowing_cart_items | cart_item_id primary key; user_id, equipment_id, quantity, borrow_date, expected_return_date, created_at, updated_at; unique customer/equipment/pickup/return combination; quantity 1–2147483647; FKs to users/equipment with existing-style deletion cascades; InnoDB |
| borrowing_checkouts | Reuses the Part 2 durable idempotency ledger as the grouped request header: checkout_id, user_id, key, payload hash, nullable JSON success response, pickup/return dates, created_at; unique (user_id, idempotency_key); ASCII binary keys; return on or after pickup; response JSON validity CHECK; owner FK; InnoDB |
| borrowing_requests.checkout_id | Nullable FK to the checkout header, with unique (checkout_id, equipment_id, borrow_date, expected_return_date); each existing request row remains the equipment/quantity/status record. Legacy single-item requests have NULL group IDs and remain supported. |
| borrowing_requests.status | Adds cancelled to the existing pending/approved/rejected/returned enum. Cancellation updates status instead of deleting history. |
| Additional constraints/indexes | Positive request/return quantities; total stock >= 1 and 0 <= available <= total; indexes for cart equipment, owner/submission time, request owner/date, and equipment/active status. |

A multi-item cart submission creates one checkout header and one ordinary borrowing_requests row per selected cart entry, all in one transaction. The header records the earliest pickup, latest return, and submission date; each equipment row retains its agreed dates, requested quantity, submission timestamp, and status. Group IDs are returned by checkout and shown in My Borrowings and the staff queue. GET requests can filter checkout_id while preserving customer ownership restrictions.

Approval, rejection, cancellation, and returns remain per equipment item. This permits a group to have mixed item statuses; no second mutable group-status counter is introduced. Its current status is determined by the member rows. A cancelled member stays visible with cancelled status and can be filtered in My Borrowings. Existing accounts, equipment, requests, returns, condition reports, and notifications were preserved.

## API contract

All cart endpoints require an authenticated customer. Owner IDs come only from the PHP session; client user IDs cannot access another customer's cart. Staff/admin use the existing request/equipment/return APIs rather than customer cart endpoints.

| Endpoint | Input | Result |
| --- | --- | --- |
| GET api/cart/index.php?capabilities=1 | None | cart_available, checkout_available, missing_requirements, message |
| GET api/cart/index.php | None | Current customer's cart_item_id, equipment, integer quantity, independent borrowing dates, and timestamps |
| POST api/cart/index.php | equipment_id, quantity, borrow_date, expected_return_date | Increment matching equipment/date entry or create a separate dated entry; return customer cart |
| PUT api/cart/index.php | cart_item_id, quantity, borrow_date, expected_return_date | Save one owned entry; reject a date collision with another entry |
| DELETE api/cart/index.php | JSON cart_item_id | Remove only the customer's entry |
| POST api/cart/import.php | items: equipment_id, quantity, borrow_date, expected_return_date | Atomic explicit browser-cart import; merge each entry using the larger of server/browser quantities |
| POST api/cart/checkout.php | items: cart_item_id, equipment_id, requested_quantity, borrow_date, expected_return_date; idempotency_key | Atomic grouped result with confirmed_cart_item_ids, confirmed_equipment_ids, data.checkout_id, and data.requests |
| GET api/requests/index.php?checkout_id=... | Optional existing filters | Group member rows scoped to the authenticated customer, or staff/admin queue |
| PUT api/equipment/index.php | Existing edit fields; optional release_quantity | Staff/admin clear only condition-held units, with a condition-history entry. Active reservations cannot be cleared. |

Checkout example (dates must satisfy the current server window):

```json
{
  "items": [{
    "cart_item_id": 101,
    "equipment_id": 10,
    "requested_quantity": 2,
    "borrow_date": "2026-10-08",
    "expected_return_date": "2026-10-10"
  }],
  "idempotency_key": "12345678123412341234123456789abc"
}
```

Pickup uses APP_TIMEZONE (default Asia/Manila): today through today + 7 calendar days, inclusive. Every cart entry requires a return date on or after its pickup date, within that equipment's borrowing_time_limit_days, matching direct borrowing requests. Checkout/import allow 1–100 dated cart entries. Duplicate submitted cart IDs and nonpositive/fractional/boolean/overflowing quantities are rejected.

Errors contain success: false and a clear message, plus a business code when applicable. HTTP 400 covers invalid quantities/dates, 401/403 authentication/roles, 404 missing equipment, 409 stock/cart/key conflicts, 503 incomplete schema, and 500 unconfirmed/database failures. Schema readiness checks the grouping fields, composite unique keys, cancellation status, InnoDB, and required foreign keys; the frontend never bypasses it.

## Customer form and independent entries

Add to Cart opens the same quantity and date modal used by direct borrowing requests. All fields are required. Native calendar bounds and inline messages enforce pickup today through seven days ahead and return pickup through the equipment limit. The Add to Cart action saves details without submitting requests or reserving stock.

The redesigned cart shows an equipment icon, muted availability and borrowing-limit badges, inline quantity controls, saved pickup/return dates, an Edit dates button, and an accessible trash button for each entry. Inline quantity changes update the selected summary immediately and persist through the existing PUT cart API before checkout can continue. The Edit dates modal remains available for quantity and date corrections. The modal loads that entry's saved details and current availability/date bounds. Save Changes validates and persists its details by cart_item_id; Cancel, the close button, Escape, and backdrop dismissal discard unsaved edits. Stock and pickup bounds are checked again before saving, and the existing API repeats validation. Saving preserves selected entries and updates the checkout summary. Different dates for the same equipment create separate entries; adding matching dates increments only that entry. Editing dates to collide with another entry produces a clear conflict instead of overwriting or combining them. An open Modify modal blocks checkout and other cart mutations until the customer saves or cancels. Failed saves keep the modal open with an error. Previous browser-cart entries use the same modal to stage details for their explicit import; cancelling restores any previously saved import draft. Old undated server/browser entries remain visible until dates are completed.

Checkout reviews each saved entry independently. It rechecks current cart dates, date bounds, equipment limits, availability, and combined quantities for repeated equipment. Reservations are immediate for every selected entry, so combined quantities across different dates cannot exceed currently available stock. The server repeats these checks transactionally.

The cart uses one dismissible informational banner, a selected-item summary with Total Items (dated entries) and Total Quantity (units), and a pickup date selector bounded by the server’s current seven-day pickup window. Apply to selected moves each selected entry’s pickup and return dates together to preserve its borrowing duration. A changed pickup selector blocks checkout until the customer applies it or chooses Keep existing dates. Existing or newly duplicated equipment/date combinations are detected before any writes. Per-entry date edits remain independent. Quantity saves and batch date application recheck stock and date bounds; checkout continues to enforce aggregate stock for repeated equipment.

Select all available equipment selects only eligible entries. Delete selected removes just the selected entry IDs, serializing existing DELETE requests. Batch operations report partial success and preserve remaining entries if an API call fails; they are not atomic batch endpoints. The checkout button starts disabled, with 50% opacity and a not-allowed cursor, and stays disabled for zero selected entries/units, invalid drafts, stock conflicts, pending saves, or an uncertain earlier checkout. An empty cart shows a shopping bag illustration and a Browse Equipment link.

The page’s additional styles are scoped in assets/css/cart.css. assets/js/cart-dialogs.js provides keyboard-accessible dialogs and mobile navigation if the Bootstrap CDN cannot load. The in-app browser was unavailable during redesign verification; a headless local Chrome browser verified desktop/mobile layouts and interactions with intercepted fixture APIs, without changing live carts or inventory. Node frontend checks cover inline persistence, live totals, quantity failures, pickup-duration preservation, date collisions, partial batch deletion, and the empty state in addition to the existing safe-checkout tests.

## Inventory and transaction rules

includes/borrowing_submission.php remains the shared inventory implementation for single-item and cart submission. It locks equipment in ascending ID order with SELECT ... FOR UPDATE, rechecks current availability and borrowing limits, performs a guarded stock UPDATE, and inserts pending request rows. Cart additions/updates/imports validate stock without reserving it.

Cart operations and checkout lock the customer's users row to serialize operations across sessions. Checkout compares the stored normalized payload hash before checking present stock, dates, or cart contents. The header/idempotency record, every item request, every stock reservation, selected-cart quantity removal, and success response commit together. Failure rolls all of them back. Unselected cart entries and quantities added beyond those submitted remain. Stale date snapshots or a selection greater than the stored cart are rejected. Removal and acknowledgments use cart IDs, preserving unselected entries even when they use the same equipment.

Successful idempotency records remain permanently replayable, including after approval, rejection, return, cancellation, or logout. Retry with the same key and normalized payload returns the original acknowledgment; changing quantities/dates with that key returns IDEMPOTENCY_CONFLICT. Original acknowledgment status can be pending after a later status change; My Borrowings provides the current status. No cleanup or endpoint expires successful keys.

Reservations deduct stock while approval is pending. Approval does not deduct twice. Rejection and cancellation restore once. Good returns restore; damaged/missing/under-repair returns remain unavailable. Repeated status transitions cannot restore twice. Stock uses the existing quantity-based model, not raw equipment.status as a second stock counter:

```text
unavailable units = total_quantity - available_quantity
condition-held units = unavailable units - pending/approved requested quantities
```

Changing total stock now preserves the unavailable count: new available = new total - unavailable. Merely changing status to available never releases held units. The staff/admin editor shows Held units cleared for reuse; a positive release_quantity requires Available status and cannot exceed the condition-held count. Clearance is transactional and logged in equipment_condition_reports. Increasing total stock adds new units while preserving old reservations/holds. The migration leaves historical quantities unchanged and refuses inconsistent active reservations rather than guessing repairs.

## Persistence, imports, and uncertain responses

The server cart is keyed by customer ID and survives page reloads, navigation, new sessions, logout, and login. Customer pages initialize the server adapter before rendering cart badges. The database is authoritative; browser equipment snapshots are display information.

Part 1 localStorage entries are kept until the customer explicitly chooses Import previous browser cart. Imports merge using the larger quantity instead of summing a repeated import, require chosen dates, validate all entries, and roll back the entire import if any equipment/quantity is invalid or unavailable. Failed/unconfirmed imports leave the browser copy intact. After complete acknowledgment, only imported local quantities are removed; additions made in another tab during the request remain. No automatic import overwrites an existing server cart.

Modify uses the existing PUT cart API and dated cart columns; no additional schema migration is needed. Changes to server cart entries survive refresh and logout/login. Before a new checkout, the frontend refreshes pickup bounds and equipment availability. The server validates again inside the transaction. The frontend saves the random submission key and original payload in account-scoped browser storage before sending. Network/server/unacknowledged responses retain the attempt; Retry previous submission sends the original key/payload without a stock preflight that would incorrectly reject units already reserved by the first attempt. Other checkouts remain blocked until the uncertain attempt is resolved. Complete acknowledgments clear the attempt and reload the server cart without deducting submitted quantities again. Authentication/schema errors retain an earlier uncertain key. Browser storage must be writable to send a new safe checkout.

## Verification

All commands passed on the configured database and running Apache. Tests create uniquely identified fixture rows and remove only those fixtures afterward.

```text
php tests/borrowing_cart_mysql.php
node tests/borrowing_cart_concurrency.cjs
php tests/borrowing_cart_http.php
php tests/borrowing_cart_backend.php
node tests/borrowing_cart_validation.cjs
node tests/borrowing_concurrency.cjs
php tests/borrowing_lifecycle.php
php tests/pickup_date_api.php
php tests/equipment_limit_api.php
node tests/pickup_date_validation.cjs
node tests/equipment_limit_validation.cjs
```

Use C:/xampp/php/php.exe if PHP is not on PATH. Node concurrency tests honor PHP_BINARY. The HTTP test defaults to http://localhost/equipement-borrowing-system and accepts EBS_TEST_BASE_URL for another installation; Apache must be running.

Coverage includes real Apache authentication/cookies and logout/login persistence, separate database connections, customer isolation, cart/import uniqueness and rollback, header/item foreign keys, independent entry/group dates and My Borrowings, strict numeric bounds, valid response JSON, permanent duplicate prevention, reservation while pending, per-item staff workflows, cancellation history, good/damaged return restoration, preserved stock restoration rules. A PDO-injected failure on the second item proves rollback of actual MySQL header, item, inventory, and cart writes. Concurrent MySQL workers prove that same-key requests create exactly one group and competing customers cannot reserve more stock than exists.

SQLite remains an additional isolated validation/failure fixture; it is not used to claim MySQL locking coverage. Frontend tests cover required shared modal details, direct-request compatibility, separate dated entries, Modify modal saving/cancellation, independent edits, stock/date preflight, stable retry keys, cart-ID acknowledgments, and safe browser imports. PHP/JavaScript syntax checks and migration reruns passed. The in-app browser was unavailable, so visual browser QA was not performed; the full workflow was verified through real HTTP endpoints.
