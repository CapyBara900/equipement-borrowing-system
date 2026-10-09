# My Borrowings database upgrade — Prompt 3

## Applied and verified

The configured MariaDB 10.4.32 database now supports the Prompt 2 pickup API. No new tables were needed. All 55 original rows across the 10 existing tables were fingerprinted before migration using every original column, then verified unchanged after migration and regression testing. Existing request IDs, customer/equipment links, quantities, dates, statuses, checkout relationships, carts, notifications, and equipment stock were preserved. Existing requests were not converted to Borrowed; their new pickup fields remain NULL.

## Reused storage

| Requirement | Existing storage |
| --- | --- |
| Unique item request | borrowing_requests.request_id primary key |
| Customer/equipment | borrowing_requests.user_id and equipment_id with existing foreign keys |
| Quantity | requested_quantity, unsigned with the existing positive quantity CHECK |
| Pick Up On / Return By | borrow_date / expected_return_date; retained nullable for historical records |
| Creation date | request_date timestamp |
| Checkout grouping | nullable checkout_id FK to borrowing_checkouts |
| Repeated equipment | Separate request_id values; checkout/equipment/pickup/return uniqueness prevents duplicate entries within the same checkout while preserving different dated borrowings |
| Actual return date | returns.actual_return_date with one return record per request |
| Availability | Existing equipment.total_quantity and available_quantity; no recalculation |

## Schema changes

| Change | Purpose |
| --- | --- |
| Append borrowed to borrowing_requests.status | Store a real pickup separately from approval. Existing enum values retain their positions and the pending default. All is a query filter, not a stored status. |
| picked_up_at DATETIME NULL DEFAULT NULL | Actual staff-confirmed pickup time; historical evidence is not guessed. Matches the backend's APP_TIMEZONE calendar time. |
| picked_up_by_staff_id INT NULL DEFAULT NULL | Staff user who recorded pickup. |
| idx_requests_pickup_staff and fk_request_pickup_staff | Link pickup attribution to an existing user. ON DELETE/UPDATE RESTRICT keeps recorded attribution intact. A referenced staff account cannot be deleted while those records exist. |
| chk_request_pickup_evidence | Pickup time and actor must appear together. Borrowed requires both; evidence is retained only for Borrowed or Returned. Returned rows without evidence remain valid for legacy returns. |
| chk_request_pickup_dates | Recorded pickup must fall between the agreed pickup and return dates; records with no pickup evidence retain their historical dates. |
| idx_requests_user_status_date(user_id,status,request_date) | Customer/status filtering with date sorting. InnoDB includes request_id in secondary indexes, providing the deterministic ID tie breaker. |

The existing idx_requests_user_date(user_id,request_date) already supports unfiltered customer history and date/ID sorting. It was reused; no redundant (user_id,request_date,request_id) index was added. Existing primary keys, quantity checks, checkout uniqueness, customer/equipment foreign keys, and return constraints remain intact. EXPLAIN verified that the new status index supports the actual customer query without a filesort.

No approval/rejection/cancellation timestamps were added: the existing backend does not record these events separately. The request timestamp, pickup timestamp, and existing actual return date cover the events that are currently recorded.

## Upgrade commands

From the project directory, after the base and dated cart migrations:

```text
C:/xampp/php/php.exe database/migrate_my_borrowings.php --dry-run
C:/xampp/php/php.exe database/migrate_my_borrowings.php
```

The CLI runner is the recommended path. It preflights supported engine/version, enum/column/index definitions, existing relationships, positive quantities, checkout ownership, and active reservation consistency; it refuses incompatible data rather than rewriting it. It shares the borrowing migration advisory lock, verifies original-column row fingerprints, and validates completed steps on reruns. Dry run executes no DDL or data writes. The SQL file lists the additive changes for review and a one-time compatible-schema import; direct SQL import does not provide the runner's preflight/rerun protections.

MySQL DDL commits implicitly. A stopped migration can be resumed after its cause is resolved; it does not roll back completed DDL or automatically restore/rewrite application data. If application activity changes row fingerprints during the migration, investigate that activity. Schedule upgrades when borrowing activity is quiet because ALTER TABLE can wait for metadata locks.

Fresh databases use the updated schema.sql followed by the base cart, dated cart, and My Borrowings runners. Never rerun schema.sql on an existing database: it is a fresh-install reset/seed file. Old cart migration SQL now retains the Borrowed enum value, and reservation preflights count Pending, Approved, and Borrowed. The original quantity migration remains a one-time legacy conversion and must not be rerun to recalculate existing inventory.

## Backend/frontend consistency

GET api/requests/index.php?capabilities=1 now reports pickup_available=true. Staff/admin explicitly record physical pickup with PUT {request_id,action:"pickup"}; the backend atomically writes Borrowed and the two audit fields without another stock deduction. Pending already reserves quantity; approval leaves that reservation in place. Returns retain pickup evidence and restore good units once; damaged/missing/under-repair units remain held. Pending-only rejection and cancellation retain their existing restoration rules.

The Prompt 1 customer page uses the same request IDs, checkout IDs, item statuses, quantities, and dates supplied by Prompt 2. Borrowed records appear only after an actual pickup action. No frontend design or unrelated application functionality changed in Prompt 3. Adding a staff pickup control remains a separate frontend task; the existing authenticated pickup API is operational.

## Verification

- New migration apply, dry run, and rerun: complete; all original rows preserved.
- php tests/my_borrowings_mysql.php: real MySQL constraints, pickup attribution, stock protections, lifecycle, history index, and old/new migration reruns while a Borrowed record exists.
- node tests/my_borrowings_concurrency.cjs: independent MySQL connections serialize duplicate pickups and returns; one event/notification and one stock restoration.
- php tests/borrowing_cart_http.php: actual Apache sessions and JSON, Borrowed filtering, staff pickup, and retained audit data after return, plus existing cart workflows.
- php tests/my_borrowings_backend.php: customer isolation, complete groups, filters, pagination, and safe errors.
- Existing borrowing_cart_mysql.php, borrowing_lifecycle.php, and borrowing_cart_concurrency.cjs: unchanged checkout/reservation/restoration behavior.
- node tests/my_borrowings_validation.cjs: existing frontend contract remains compatible.

Test fixtures are uniquely identified and removed afterward. The fingerprint comparison excludes newly added columns when comparing original data, so NULL pickup fields do not mask changes to old columns.
