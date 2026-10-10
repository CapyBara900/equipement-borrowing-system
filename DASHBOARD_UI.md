# Role-adaptive overview

The implementation is in dashboard.html, assets/css/dashboard.css, and assets/js/dashboard.js. It keeps the existing dark navigation and light dashboard shell. The JavaScript uses the signed-in role returned by the server, rather than a client-selected role.

Customers see Awaiting approval, In your hands, Returned, and Declined. Staff and Admins see Pending approvals, Due today, Equipment utilization, and Returned. Utilization is the number of equipment units currently borrowed divided by total inventory units; approved reservations are excluded.

Each metric filters the borrowing list. The dropdown also offers all statuses, active borrowings, overdue, and due today. Active borrowings include approved pickups and borrowed equipment. Only borrowed equipment receives relative return-date badges. Date arithmetic uses calendar dates from the server's APP_TIMEZONE (Asia/Manila by default), avoiding browser timezone shifts.

The left column contains structured borrowing rows, eight per page. The right column contains recent request creation and recorded return events, plus a role-specific quick link. Staff/Admin monthly reports use CSS bars and do not depend on Chart.js. Empty states include a browse-equipment or borrowing-management action, and failed requests include a retry button.

View details opens a native accessible dialog. Desk rows also link to the matching request's approval, pickup, or return controls on requests.html?request=ID. The destination opens only a request already present in the authorized history response. Customer details link to the same request in My Borrowings. The existing system has no extension-request endpoint, so no unsupported extension action is displayed.

## Data endpoint

GET api/dashboard/index.php accepts filter, page, and limit (1–20; default 8). Supported filters: active, all, pending, approved, borrowed, returned, rejected, cancelled, due_today, overdue. It returns summary, borrowings, pagination, recent_activity, today, timezone, and role. Desk responses additionally supply inventory utilization and monthly reports. The existing most_requested report field is retained.

Customer scope is enforced in every database query using the authenticated account ID. Incoming user_id and role parameters cannot change that scope. The endpoint verifies the current database role on every request and uses prepared statements for query values. Counts cover all records rather than only the visible page. No database migration is required for this dashboard update.

## Dynamic JavaScript example

The actual renderer obtains live data; this illustrates the filter flow:

```js
const response = await Api.dashboard({filter: 'pending', page: 1, limit: 8});
// response.data.role is verified server-side.
const cards = dashboardMetrics(response.data.role, response.data.summary);
// Render each card as a button with data-dashboard-filter and aria-pressed.
// A click updates dashboardState.filter, resets page to 1, and calls loadDashboard().
```

## Verification

Run node tests/dashboard_validation.cjs and C:\xampp\php\php.exe tests/dashboard_http.php. The HTTP check clones tables into a temporary database, tests all roles and customer isolation, pagination above 50 requests, input rejection, dates, and utilization, then removes the temporary database and sessions. Existing borrowing-management and staff-navigation regression checks also pass. Desktop and mobile browser previews use representative API fixtures rather than altering accounts or requests.
