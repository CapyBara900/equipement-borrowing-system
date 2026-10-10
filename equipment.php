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
<link href="assets/css/styles.css" rel="stylesheet">
</head>
<body>

<header class="topbar">
  <span class="brand">Equipment Desk</span>
  <button class="btn btn-sm btn-outline-light" type="button"
          data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-label="Open menu">Menu</button>
</header>

<div class="offcanvas offcanvas-start rail-canvas" tabindex="-1" id="mobileNav">
  <div class="offcanvas-body p-0"></div>
</div>

<div class="app-shell">
  <aside class="rail"></aside>

  <main class="main">
    <div class="page-head">
      <div>
        <h1>Equipment</h1>
        <p>Find something and request it. The stripe on each row shows whether it's free.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-outline-secondary btn-sm" id="manageCategoriesBtn" hidden>Manage categories</button>
        <button class="btn btn-outline-primary btn-sm" id="addCategoryBtn" hidden>Add Category</button>
        <button class="btn btn-primary btn-sm" id="addEquipmentBtn" hidden>Add equipment</button>
      </div>
    </div>

    <details class="panel mb-3" id="categoryManagement" hidden>
      <summary class="panel-head"><h2 class="d-inline">Equipment categories</h2></summary>
      <p class="form-text px-3">Edit names and descriptions here. Reassign equipment before deleting its category.</p>
      <div class="panel-body" id="categoryList" aria-live="polite"></div>
    </details>

    <!-- Search / filter / sort -->
    <div class="panel mb-3">
      <div class="panel-body">
        <div class="row g-2">
          <div class="col-12 col-lg-4">
            <label class="form-label" for="searchInput">Search</label>
            <input class="form-control" type="search" id="searchInput"
                   placeholder="Name, description, or serial number">
          </div>
          <div class="col-6 col-lg-3">
            <label class="form-label" for="categoryFilter">Category</label>
            <select class="form-select" id="categoryFilter"><option value="">All categories</option></select>
          </div>
          <div class="col-6 col-lg-2">
            <label class="form-label" for="statusFilter">Status</label>
            <select class="form-select" id="statusFilter">
              <option value="">Any</option>
              <option value="available">Available</option>
              <option value="unavailable">Unavailable</option>
              <option value="pending">Pending</option>
              <option value="borrowed">Borrowed</option>
              <option value="maintenance">Repair</option>
            </select>
          </div>
          <div class="col-8 col-lg-2">
            <label class="form-label" for="sortBy">Sort by</label>
            <select class="form-select" id="sortBy">
              <option value="equipment_name">Name</option>
              <option value="status">Status</option>
              <option value="created_at">Newest</option>
            </select>
          </div>
          <div class="col-4 col-lg-1 d-flex align-items-end">
            <button class="btn btn-outline-secondary w-100" id="sortDirBtn"
                    title="Toggle sort direction" aria-label="Toggle sort direction">A–Z</button>
          </div>
        </div>
      </div>
    </div>

    <div id="resultMeta" class="mb-2" style="font-size:.85rem;color:var(--slate)"></div>
    <div id="equipmentList"><div class="empty">Loading equipment…</div></div>

    <nav class="d-flex justify-content-between align-items-center mt-3" id="pager" hidden>
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

<div id="toastZone"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/api.js?v=dated-cart-v2"></script>
<script src="assets/js/app.js?v=staff-navigation-v3"></script>
<script src="assets/js/categories.js?v=category-management-v1"></script>
<script src="assets/js/equipment.js?v=category-management-v1"></script>
</body>
</html>
