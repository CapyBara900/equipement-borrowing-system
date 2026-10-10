# Equipment catalog and management UI

The complete markup is in equipment.php, scoped styles are in assets/css/catalog.css, and the vanilla JavaScript is in assets/js/equipment.js. The existing dark sidebar and light dashboard theme are retained.

## Layout and filters

Customers start in Grid view; Admins start in List view. The selected view is remembered per account and role in localStorage. Grid/list switching renders the same loaded page without another API call. Buttons expose aria-pressed and work with the keyboard.

The search/filter card sticks beneath the mobile navigation or near the top of the desktop page. Search waits 250 milliseconds after typing; category, status, and sort changes load immediately. Older responses are ignored, including while another search is waiting to run. Filters reset pagination to page 1. Clear filters, loading indicators, empty states, and retry controls are included.

The equipment API now returns total, pages, and has_more alongside its existing data/page/limit fields. Pages contain 12 items, sorting uses an equipment ID tie-breaker, and available_quantity is an allowed sort field. Table headers stick within the table's scroll region. On narrow screens, horizontal scrolling stays inside that region.

## Stock and actions

Stock badges and accessible progress bars show ready-to-borrow and total inventory quantities. Green means available. Amber means low stock (20% or fewer, with at least a one-unit threshold when inventory has multiple units) or an Admin-visible reservation. Red means unavailable; Admins also see a separate Maintenance condition badge when applicable. The existing quantity-based catalog availability remains authoritative.

Both Add to Cart and Quick Request remain visible and disabled for out-of-stock items. Clicking an enabled action fetches current stock and borrowing limits before opening the existing dated borrowing form. Cart additions still do not reserve stock. The backend's quantity, pickup window, and return-limit validation remain in place.

Admin rows/cards use labeled icon buttons for QR labels, editing, and deletion, with native title tooltips. The category-management panel stays hidden until Manage categories is opened; closing it removes the unused card wrapper. The existing category editor, stock release controls, equipment editor, and deletion safeguards are preserved.

## Quick view and history

Clicking an item title, thumbnail, card, or table row opens a native dialog. It fetches current specifications, description, category, serial number, equipment reference, added date, stock, and borrowing terms. A history failure can be retried without hiding the specifications.

GET api/equipment/history.php?equipment_id=ID returns the latest 10 borrowing records and, for Admins, the latest 10 condition records. It verifies the current database role and uses prepared statements. Customers receive only their own borrowing records and no condition notes or other borrower identities. Incoming user_id/role parameters cannot change that scope. Staff and guests cannot access it. History links open the matching authorized request in My Borrowings or Borrowing Management.

No database migration is needed. This uses the existing equipment, borrowing_requests, returns, and equipment_condition_reports tables.

## JavaScript integration examples

```js
// Use the renderer and same loaded results to switch layouts.
setCatalogView('grid'); // or 'list'

// Search/filter requests use the shared AJAX helper.
const response = await Api.listEquipment({
  search: 'camera', category_id: '', status: 'available',
  sort_by: 'available_quantity', sort_dir: 'DESC', page: 1, limit: 12
});
// response.data contains items; response.total and response.pages drive pagination.

// Fetch fresh specifications and role-scoped history in the quick-view dialog.
await openEquipmentQuickView(1);
```

## Validation

Run node tests/catalog_ui_validation.cjs and C:\xampp\php\php.exe tests/catalog_http.php. The PHP tests clone tables into a temporary database, exercise catalog queries and private history over HTTP, then remove the fixtures, sessions, and database. Existing cart, pickup-date, equipment-limit, category, and role-access regression checks also pass. Browser previews use representative API fixtures and do not change application accounts or stock.
