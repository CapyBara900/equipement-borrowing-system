# Standardized Equipment Desk sidebar

All signed-in views use one [stylesheet](assets/css/sidebar.css) and one [renderer](assets/js/sidebar.js). The stylesheet is the complete, reusable CSS snippet; load it after page-specific styles. It defines `.sidebar`, `.nav-item`, `.nav-item.active`, `.sidebar-brand`, and `.user-profile-footer`, including responsive and focus states.

```html
<link href="assets/css/sidebar.css?v=sidebar-v3" rel="stylesheet">
<aside class="sidebar" id="sidebar" aria-label="Main navigation"></aside>
<script src="assets/js/sidebar.js?v=sidebar-v3"></script>
<script>
  EquipmentSidebar.mount(document.getElementById('sidebar'), currentUser, {
    page: 'dashboard.html',
    standalone: true,
    cartCount: 0,
    unreadCount: 0
  });
</script>
```

`currentUser` must come from the authenticated session, for example `{ name: 'Darrelle', role: 'admin' }`. Omit `page` to derive the active item from the current URL. Roles are `admin`, `staff`, and `customer`. The renderer filters the menu and adds exactly one `.active` link with `aria-current="page"` for each menu page. Cart counts and unread indicators come from the existing APIs.

The HTML files below are complete sidebar snippets with SVGs, the blue cube brand, the profile footer, sign out, and the correct active item. Copy their markup into a page in the project root; they are code examples rather than standalone application pages. Names and counts are examples. On the notifications examples, the dot represents one unread notification; add `hidden` when the unread count is zero.

| Page | Admin HTML | Staff HTML | Customer HTML |
|---|---|---|---|
| dashboard.html | [Overview](docs/sidebar-snippets/admin-dashboard.html) | [Overview](docs/sidebar-snippets/staff-dashboard.html) | [Overview](docs/sidebar-snippets/customer-dashboard.html) |
| equipment.html | [Equipment](docs/sidebar-snippets/admin-equipment.html) | — | [Equipment](docs/sidebar-snippets/customer-equipment.html) |
| requests.html | [Borrowing Management](docs/sidebar-snippets/admin-requests.html) | [Borrowing Management](docs/sidebar-snippets/staff-requests.html) | Customers use My Borrowings |
| admin.html | [People & Categories](docs/sidebar-snippets/admin-admin.html) | — | — |
| my-borrowings.html | — | — | [My Borrowings](docs/sidebar-snippets/customer-my-borrowings.html) |
| notifications.html | — | — | [Notifications](docs/sidebar-snippets/customer-notifications.html) |
| cart.html | — | — | [Borrowing Cart](docs/sidebar-snippets/customer-cart.html) |

Production integration:
- Dashboard, Admin, Equipment, Cart, and Profile render the sidebar through `renderShell()` in `assets/js/app.js`.
- Borrowing Management, My Borrowings, and Notifications use the same renderer with their existing standalone controllers.
- Desktop sidebars and mobile menus share identical inner markup. The shared mobile drawer works without a Bootstrap download, traps keyboard focus, closes with Escape, and restores focus to Menu. The standalone views retain their existing mobile controls.
- The sidebar stays 260px wide, including between 992px and 1150px. Navigation backgrounds, typography, icons, footer, and spacing are defined in `sidebar.css`; the previous competing sidebar rules were removed.
- `equipment.html` remains the existing redirect to the protected `equipment.php` view. Both URLs resolve to the Equipment active item.
- Staff navigation contains only Overview and Borrowing Management. Equipment is hidden from Staff on desktop and mobile, and the existing server permissions continue to deny access.
- The existing Profile page uses the same sidebar. Its profile link receives `aria-current="page"`, while none of the primary menu items is marked active.

Verification: the existing Staff access, Account Profile, Admin, Dashboard, and Catalog UI validations pass. Browser checks used preview data or mocked sessions without modifying database records.

Final regression results: 10 frontend validations pass, including Auth and Cart. Two older Borrowing Management/My Borrowings validations stop at an existing tab-count assertion: requests.html already had 11 tab-role strings before this change, while those checks expect 7. Their preceding runtime/navigation assertions pass.
