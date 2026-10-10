<?php
require_once __DIR__ . '/includes/page_auth.php';
requirePageRole(['admin', 'customer']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Equipment — Equipment Desk</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/styles.css?v=account-profile-v1" rel="stylesheet">
<link href="assets/css/catalog.css?v=catalog-ui-v1" rel="stylesheet">
<link href="assets/css/sidebar.css?v=sidebar-v3" rel="stylesheet">
</head>
<body class="catalog-page">

<header class="topbar">
  <span class="brand">Equipment Desk</span>
  <button class="btn btn-sm btn-outline-light" type="button"
          data-sidebar-open="#mobileNav" aria-label="Open menu">Menu</button>
</header>

<div class="rail-canvas sidebar-drawer" tabindex="-1" id="mobileNav" role="dialog" aria-modal="true" aria-label="Main navigation" hidden>
  <div class="offcanvas-body sidebar"></div>
</div>

<div class="app-shell">
  <aside class="rail sidebar" aria-label="Main navigation"></aside>

  <main class="main">
    <div class="page-head catalog-page-head">
      <div>
        <span class="catalog-eyebrow">EQUIPMENT DESK</span>
        <h1 id="catalogTitle">Equipment catalog</h1>
        <p id="catalogSubtitle">Find the right equipment for your next project.</p>
      </div>
      <div class="catalog-admin-toolbar" aria-label="Equipment management actions">
        <button class="btn btn-outline-secondary btn-sm" id="manageCategoriesBtn" aria-controls="categoryManagement" aria-expanded="false" hidden>Manage categories</button>
        <button class="btn btn-outline-primary btn-sm" id="addCategoryBtn" hidden>Add Category</button>
        <button class="btn btn-primary btn-sm" id="addEquipmentBtn" hidden>Add equipment</button>
      </div>
    </div>

    <details class="panel catalog-categories" id="categoryManagement" hidden>
      <summary class="panel-head"><h2 class="d-inline">Equipment categories</h2></summary>
      <p class="form-text px-3">Edit names and descriptions here. Reassign equipment before deleting its category.</p>
      <div class="panel-body" id="categoryList" aria-live="polite"></div>
    </details>

    <section class="panel catalog-controls" aria-label="Search and filter equipment">
      <div class="catalog-search-row">
        <div class="catalog-search-field"><label for="searchInput">Search equipment</label><input type="search" id="searchInput" class="form-control" placeholder="Name, description, or serial number" autocomplete="off" aria-controls="equipmentList"></div>
        <div class="catalog-view-switch" role="group" aria-label="Equipment layout">
          <button type="button" id="gridViewBtn" class="catalog-view-button" data-catalog-view="grid" aria-pressed="true" aria-controls="equipmentList" title="Grid cards"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Grid</button>
          <button type="button" id="listViewBtn" class="catalog-view-button" data-catalog-view="list" aria-pressed="false" aria-controls="equipmentList" title="Data table list"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M9 5h12M9 12h12M9 19h12M3 5h2M3 12h2M3 19h2"/></svg>List</button>
        </div>
      </div>
      <div class="catalog-filter-row">
        <div><label for="categoryFilter">Category</label><select class="form-select" id="categoryFilter"><option value="">All categories</option></select></div>
        <div><label for="statusFilter">Status</label><select class="form-select" id="statusFilter"><option value="">Any availability</option><option value="available">Available</option><option value="unavailable">Unavailable</option><option value="pending">Reserved / pending</option><option value="borrowed">On loan</option><option value="maintenance">Maintenance</option></select></div>
        <div><label for="sortBy">Sort by</label><div class="catalog-sort-control"><select class="form-select" id="sortBy"><option value="equipment_name">Name</option><option value="created_at">Date added</option><option value="available_quantity">Available stock</option><option value="status">Status</option></select><button class="catalog-icon-button" type="button" id="sortDirBtn" title="Toggle sort direction" aria-label="Sort ascending; switch to descending">A–Z</button></div></div>
        <button class="catalog-clear" type="button" id="clearCatalogFilters">Clear filters</button>
      </div>
    </section>

    <div class="catalog-results-heading"><p id="resultMeta" role="status" aria-live="polite" aria-atomic="true">Loading equipment…</p><span>Click an item for a quick view</span></div>
    <div id="equipmentList" aria-busy="true"><div class="empty">Loading equipment…</div></div>

    <nav class="catalog-pagination" aria-label="Equipment pages" id="pager" hidden>
      <button class="btn btn-outline-secondary btn-sm" id="prevPage">Previous</button>
      <span style="font-size:.88rem;color:var(--slate)" id="pageLabel"></span>
      <button class="btn btn-outline-secondary btn-sm" id="nextPage">Next</button>
    </nav>
  </main>
</div>

<!-- Borrow request modal -->
<div class="modal fade" id="borrowModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="borrowModalTitle">Request equipment</h2>
        <button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="borrowForm" novalidate>
        <div class="modal-body">
          <p class="mb-3" id="borrowItemName" style="font-weight:600"></p>
          <input type="hidden" id="borrowEquipmentId">
          <p class="form-text" id="borrowModeHint"></p>
          <div class="mb-3">
            <label class="form-label" for="borrowQuantity">Quantity</label>
            <input class="form-control" type="number" id="borrowQuantity" min="1" step="1" required>
            <div class="form-text" id="borrowAvailability"></div>
            <div class="field-error" data-error-for="borrowQuantity"></div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="borrowDate">Pick up on</label>
            <input class="form-control" type="date" id="borrowDate" required>
            <div class="field-error" data-error-for="borrowDate"></div>
          </div>
          <div class="mb-1">
            <label class="form-label" for="expectedReturnDate">Return by</label>
            <input class="form-control" type="date" id="expectedReturnDate" required>
            <div class="field-error" data-error-for="expectedReturnDate"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit" id="borrowSubmit" disabled>Send request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Equipment editor (admin) -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="editTitle">Add equipment</h2>
        <button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="editForm" novalidate>
        <div class="modal-body">
          <input type="hidden" id="editId">
          <div class="mb-3">
            <label class="form-label" for="editBorrowingLimit">Borrowing time limit (days)</label>
            <input class="form-control" type="number" id="editBorrowingLimit" min="1" max="3650" step="1" required>
            <div class="field-error" data-error-for="editBorrowingLimit"></div>
            <label class="form-label mt-3" for="editTotalQuantity">Total quantity</label>
            <input class="form-control" type="number" id="editTotalQuantity" min="1" step="1" required>
            <div class="field-error" data-error-for="editTotalQuantity"></div>
            <div id="releaseStockControl" hidden>
              <label class="form-label mt-3" for="editReleaseQuantity">Held units cleared for reuse</label>
              <input class="form-control" type="number" id="editReleaseQuantity" min="0" step="1" value="0">
              <div class="form-text" id="releaseStockHint">Clear only repaired or recovered units. Active borrowings stay reserved.</div>
              <div class="field-error" data-error-for="editReleaseQuantity"></div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="editName">Name</label>
            <input class="form-control" type="text" id="editName" required>
            <div class="field-error" data-error-for="editName"></div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="editSerial">Serial number</label>
            <input class="form-control" type="text" id="editSerial" placeholder="Optional">
            <div class="field-error" data-error-for="editSerial"></div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="editCategory">Category</label>
            <select class="form-select" id="editCategory"><option value="">Uncategorised</option></select>
          </div>
          <div class="mb-3">
            <label class="form-label" for="editStatus">Status</label>
            <select class="form-select" id="editStatus">
              <option value="available">Available</option>
              <option value="pending">Pending</option>
              <option value="borrowed">Borrowed</option>
              <option value="maintenance">Out for repair</option>
            </select>
          </div>
          <div class="mb-1">
            <label class="form-label" for="editDescription">Description</label>
            <textarea class="form-control" id="editDescription" rows="2"></textarea>
            <div class="field-error" data-error-for="editDescription"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit" id="editSubmit" disabled>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- QR label modal (external API) -->
<div class="modal fade" id="qrModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5">Scan label</h2>
        <button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body qr-box" id="qrBody">Generating…</div>
    </div>
  </div>
</div>

<dialog id="equipmentQuickView" class="catalog-dialog" aria-labelledby="quickViewTitle">
  <header class="catalog-dialog-header"><h2 id="quickViewTitle">Equipment details</h2><form method="dialog"><button type="submit" class="catalog-dialog-close" aria-label="Close equipment details">×</button></form></header>
  <div id="quickViewBody" aria-busy="false"></div>
</dialog>

<div id="toastZone"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/api.js?v=catalog-ui-v1"></script>
<script src="assets/js/sidebar.js?v=sidebar-v3"></script>
<script src="assets/js/app.js?v=sidebar-v1"></script>
<script src="assets/js/categories.js?v=category-management-v1"></script>
<script src="assets/js/equipment.js?v=catalog-ui-v1"></script>
</body>
</html>
