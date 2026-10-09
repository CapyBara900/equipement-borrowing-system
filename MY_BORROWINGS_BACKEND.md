# My Borrowings backend — Prompt 2

Prompt 3 has now applied and verified the pickup schema. See [MY_BORROWINGS_DATABASE.md](MY_BORROWINGS_DATABASE.md). The original handoff below records the pre-migration requirements; pickup_available is now true on this installation.

The existing Requests endpoint supports My Borrowings. This phase changes PHP and regression tests only; it applies no database migrations or existing-record conversions.

## API and compatibility

All routes below use api/requests/index.php and require an authenticated session. The server derives the owner from the session. A supplied user_id is ignored for authorization. Customers receive only their own records; staff/admin retain their existing flat request queue and decisions.

| Request | Response |
| --- | --- |
| GET | Existing flat data array, now with normalized integer IDs/quantities and transaction references; page, limit, total, has_more, record_type |
| GET ?status=pending | Same flat structure filtered by the actual stored status |
| GET ?status=all | Complete history across all statuses, with pagination |
| GET ?id=123 | One owned request object; foreign and missing request IDs both return 404 |
| GET ?checkout_id=45 | Owned checkout members in the existing paginated flat format |
| GET ?view=transactions | One transaction per owned request, with its single current status and checkout reference |
| GET ?view=transactions&status=returned | Only owned requests currently Returned; other checkout members are excluded |
| GET ?view=transactions&id=123 | One owned request transaction; foreign and missing IDs return 404 |
| GET ?view=transactions&checkout_id=45 | Independently managed owned requests from that checkout, with pagination |
| GET ?capabilities=1 | Accepted status values, pickup_available, and missing_requirements |
| PUT {request_id, status: approved/rejected} | Existing staff/admin pending-request decision |
| PUT {request_id, action: pickup} | Explicit staff/admin physical handover; returns 503 PICKUP_SCHEMA_REQUIRED until Prompt 3 provides storage |
| PUT {request_id, status: borrowed} | Equivalent explicit pickup action, with the same validations and audit fields |
| DELETE ?id=123 | Existing owner/desk pending-only cancellation |

Status values are pending, approved, borrowed, returned, rejected, cancelled. Empty or all means no status filter. Invalid statuses, arrays, IDs, page values, limits above 50, invalid dates, reversed date ranges, and conflicting id/checkout_id selectors return 400. page starts at 1; limit defaults to 20 and accepts 1–50. Dates accept YYYY-MM-DD or YYYY-MM-DD HH:MM:SS; a date-only date_to includes the whole day. Flat rows sort by request_date DESC, request_id DESC; grouped pages sort by newest request timestamp with request ID as the tie breaker.

My Borrowings now reads `view=transactions` through the existing endpoint. It shows one card and one current status per request while retaining checkout references, dates, quantities, and cancellation controls. All and the six status tabs use the same saved `borrowing_requests.status` as backend filtering. The displayed count is the number of matching requests.

The visible customer page checks for Admin/Staff changes every 15 seconds and when focus or visibility returns. Quiet reads preserve the current tab, date filters, and open details; unchanged responses leave the rendered view intact. Hidden pages and overlapping reads do not poll. Temporary background failures retain the last successful view and retry on the next check. Cancellation refreshes the saved status immediately. Leaving the page stops the timer, and browser back/forward cache restoration resumes it.

Each item contains request_id, user_id, user_name, equipment_id, equipment_name, requested_quantity, borrow_date, expected_return_date, status, request_date, checkout_id, transaction_id, transaction_reference, picked_up_at, and picked_up_by_staff_id. Unavailable pickup evidence is null. Transaction IDs use checkout:ID for known checkouts and request:ID for independent requests; repeated equipment does not imply a relationship.

Transaction objects contain transaction_id (`request:ID`), transaction_reference, checkout_id, request_date, status, a single-entry compatibility statuses array, and one items entry. Each item's saved status_history remains separate from its current status. Checkout siblings are never hydrated into another request's status tab. All quantities and dates are preserved. No idempotency keys, payload hashes, or private checkout ledger fields are exposed.

## Status and inventory behavior

Before Prompt 3, the configured schema supported pending, approved, rejected, returned, cancelled and had no physical pickup event or borrowed enum value. Prompt 3 has added the pickup storage without converting historical statuses. History never maps approved to borrowed using equipment status, elapsed dates, or approval alone.

Pickup requires an approved request with no prior pickup, an actual staff action, and today (APP_TIMEZONE) between the agreed pickup and return dates inclusive. It atomically writes borrowed, the pickup time, and the staff actor, and sends a notification. A failure rolls back the event and status. Repeated or invalid transitions return 409. Pending reservations already deduct stock, so approval and pickup never deduct again.

Borrowed quantities are now included in active reservation accounting, preventing condition-clearance actions from releasing a physical loan. Returns accept borrowed as well as the existing approved path. The approved path remains for legacy staff workflows and historical rows without reliable pickup evidence; it does not relabel history as Borrowed. Good returns restore stock once; damaged/missing/under-repair returns preserve held units. Rejection and cancellation remain pending-only and restore once. Reading history, details, capabilities, and status filters never changes inventory.

## Prompt 3 database handoff — applied in the next phase

Implement a separate additive, resumable migration. Preserve all existing request statuses, quantities, reservations, checkout IDs, and histories.

1. Extend borrowing_requests.status to include borrowed while retaining pending, approved, rejected, returned, cancelled and the pending default.
2. Add borrowing_requests.picked_up_at as nullable DATETIME, default NULL. The backend writes local APP_TIMEZONE calendar time consistently with its pickup validation; document this convention.
3. Add borrowing_requests.picked_up_by_staff_id as nullable INT matching users.user_id, default NULL, with an index and FK to users. Prefer deletion restriction for staff actors so recorded pickup attribution is preserved.
4. Keep existing rows' pickup fields NULL. Do not automatically backfill pickups or convert approved requests into borrowed; old approval is insufficient evidence of physical handover.
5. Retain existing checkout grouping and per-item date uniqueness. Consider a history index (user_id, request_date, request_id) and, based on query plans, (user_id, status, request_date, request_id).
6. Update old migration readiness checks and inventory preflights to include borrowed active reservations and preserve the extended enum on reruns. The old migration files are intentionally unchanged during Prompt 2.

After migration, GET capabilities should report pickup_available=true. The explicit pickup API can then be integrated into staff controls in a later frontend step. Full MySQL pickup concurrency/foreign-key validation must run against the migrated schema in Prompt 3. SQLite verifies the new transition logic in isolated memory and does not prove MySQL locking.

## Verification

Run PHP syntax checks plus:

- php tests/my_borrowings_backend.php — rollback-only real MySQL history, ownership, grouping, input validation, and schema-gate checks.
- php tests/my_borrowings_pickup.php — isolated future schema pickup, atomic failure, inventory safety, and good/damaged returns.
- php tests/borrowing_lifecycle.php
- php tests/borrowing_cart_mysql.php
- php tests/borrowing_cart_http.php — real Apache sessions/JSON, grouping and filters, with temporary fixtures cleaned afterward.
- node tests/my_borrowings_validation.cjs — existing frontend contract.

Use C:/xampp/php/php.exe when PHP is not on PATH. No configured database DDL is executed by the new tests.
