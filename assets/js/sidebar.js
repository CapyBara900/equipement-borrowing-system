/* Shared Equipment Desk sidebar. All roles and pages use this markup. */
(function (global) {
  'use strict';

  const links = [
    { href: 'dashboard.html', label: 'Overview', icon: 'grid', roles: ['admin', 'staff', 'customer'] },
    { href: 'equipment.html', label: 'Equipment', icon: 'equipment', roles: ['admin', 'customer'] },
    { href: 'cart.html', label: 'Borrowing Cart', icon: 'cart', roles: ['customer'], cart: true },
    { href: 'requests.html', label: 'Borrowing Management', icon: 'clipboard', roles: ['admin', 'staff'] },
    { href: 'my-borrowings.html', label: 'My Borrowings', icon: 'clipboard', roles: ['customer'] },
    { href: 'notifications.html', label: 'Notifications', icon: 'bell', roles: ['customer'], badge: true },
    { href: 'admin.html', label: 'People & Categories', icon: 'people', roles: ['admin'] },
  ];
  const paths = {
    box: '<path d="m12 3 9 5v8l-9 5-9-5V8l9-5Z"/><path d="m3 8 9 5 9-5M12 13v8M7.5 5.5l9 5"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    equipment: '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
    cart: '<path d="M2 3h3l3 12h10l3-9H6"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/>',
    clipboard: '<rect x="5" y="5" width="14" height="16" rx="2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h6"/>',
    bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
    people: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    close: '<path d="m6 6 12 12M6 18 18 6"/>',
    logout: '<path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4M14 8l4 4-4 4M8 12h13"/>',
  };
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  }[char]));
  const icon = name => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + paths[name] + '</svg>';
  const count = value => Math.max(0, Math.floor(Number(value) || 0));
  function currentPage(value) {
    const page = String(value || global.location?.pathname || 'dashboard.html').split('/').pop().split(/[?#]/)[0];
    return page === 'equipment.php' ? 'equipment.html' : (page || 'dashboard.html');
  }
  function roleLabel(user) {
    const role = String(user.role || '');
    return role.charAt(0).toUpperCase() + role.slice(1);
  }
  function initial(user) {
    return Array.from(String(user.name || '').trim())[0]?.toUpperCase() || '?';
  }
  function navMarkup(user, options = {}) {
    const here = currentPage(options.page);
    return links.filter(link => link.roles.includes(user.role)).map(link => {
      const active = link.href === here;
      let badge = '';
      if (link.cart) {
        badge = '<span class="sidebar-count" data-cart-count' + (options.standalone ? ' id="cartCount"' : '') +
          ' aria-live="polite" aria-label="' + count(options.cartCount) + ' items in borrowing cart">' + count(options.cartCount) + '</span>';
      } else if (link.badge) {
        const unread = count(options.unreadCount);
        badge = '<span class="sidebar-unread-dot" data-sidebar-unread' + (options.standalone ? ' id="sidebarUnread"' : '') +
          ' role="img" aria-label="' + unread + ' unread notifications"' + (unread ? '' : ' hidden') + '></span>';
      }
      return '<a class="nav-item' + (active ? ' active' : '') + '" href="' + link.href + '"' +
        (active ? ' aria-current="page"' : '') + '>' + icon(link.icon) +
        '<span class="sidebar-nav-text">' + escape(link.label) + '</span>' + badge + '</a>';
    }).join('\n');
  }
  function markup(user, options = {}) {
    const here = currentPage(options.page);
    const id = name => options.standalone ? ' id="' + name + '"' : '';
    return `
<button class="sidebar-close" type="button" aria-label="Close navigation"${options.standalone ? ' id="menuClose"' : ' data-sidebar-close'}>${icon('close')}</button>
<a class="sidebar-brand" href="dashboard.html" aria-label="Equipment Desk overview">
  <span class="sidebar-brand-mark">${icon('box')}</span>
  <span class="sidebar-brand-copy"><span class="sidebar-brand-title">Equipment Desk</span><span class="sidebar-brand-subtitle">BORROWING &amp; RETURNS</span></span>
</a>
<p class="sidebar-section-label">WORKSPACE</p>
<nav class="sidebar-nav" aria-label="Primary">${navMarkup(user, options)}</nav>
<div class="sidebar-bottom">
  <div class="user-profile-footer">
    <a class="sidebar-profile-link account-link" href="profile.php" title="View your account profile"${id('accountLink')}${here === 'profile.php' ? ' aria-current="page"' : ''}>
      <span class="sidebar-avatar"${id('accountAvatar')} aria-hidden="true">${escape(initial(user))}</span>
      <span class="sidebar-user-details"><span class="sidebar-user-name"${id('accountName')}>${escape(user.name)}</span><span class="sidebar-user-role"${id('accountRole')}>${escape(roleLabel(user))}${options.preview ? ' · Sample account' : ''}</span></span>
    </a>
    <button class="sidebar-sign-out" type="button" data-sign-out aria-label="Sign out" title="Sign out">${icon('logout')}<span class="sidebar-sr-only">Sign out</span></button>
  </div>
</div>`;
  }
  function bindSignOut(container, logout) {
    container.querySelectorAll('[data-sign-out]').forEach(button => {
      if (button.dataset.signOutBound) return;
      button.dataset.signOutBound = 'true';
      button.addEventListener('click', async () => {
        button.disabled = true;
        try {
          if (container.dataset.sidebarPreview !== 'true') {
            if (logout) await logout();
            else {
              const response = await fetch('api/auth/logout.php', { method: 'POST', credentials: 'same-origin' });
              if (!response.ok) throw new Error('Sign out failed.');
            }
          }
          global.location.href = 'index.html';
        } catch (error) {
          button.disabled = false;
          if (typeof global.toast === 'function') global.toast('Could not sign out. Please try again.', 'bad');
          else global.alert('Could not sign out. Please try again.');
        }
      });
    });
  }
  function mount(container, user, options = {}) {
    container.classList.add('sidebar');
    container.dataset.sidebarPreview = String(Boolean(options.preview));
    container.innerHTML = markup(user, options);
    bindSignOut(container, options.logout);
  }
  // Update only changing content so menu-close listeners and keyboard focus survive.
  function updateUser(container, user, options = {}) {
    container.querySelector('.sidebar-nav').innerHTML = navMarkup(user, options);
    container.querySelector('.sidebar-user-name').textContent = user.name;
    container.querySelector('.sidebar-user-role').textContent = roleLabel(user);
    container.querySelector('.sidebar-avatar').textContent = initial(user);
    container.dataset.sidebarPreview = String(Boolean(options.preview));
  }
  function updateUnread(container, value) {
    const unread = count(value);
    container.querySelectorAll('[data-sidebar-unread]').forEach(dot => {
      dot.hidden = unread === 0;
      dot.setAttribute('aria-label', unread + ' unread notifications');
    });
  }
  function initMobileDrawer() {
    const trigger = document.querySelector('[data-sidebar-open]');
    if (!trigger?.dataset.sidebarOpen) return;
    const drawer = document.querySelector(trigger.dataset.sidebarOpen);
    if (!drawer || trigger.dataset.sidebarBound) return;
    trigger.dataset.sidebarBound = 'true';
    trigger.setAttribute('aria-controls', drawer.id);
    trigger.setAttribute('aria-expanded', 'false');
    const backdrop = document.createElement('button');
    backdrop.type = 'button';
    backdrop.className = 'sidebar-drawer-backdrop';
    backdrop.tabIndex = -1;
    backdrop.setAttribute('aria-label', 'Close navigation');
    backdrop.hidden = true;
    document.body.appendChild(backdrop);
    let opened = false;
    let previousOverflow = '';
    const background = [document.querySelector('.app-shell'), document.querySelector('.topbar')].filter(Boolean);
    const previousInert = new Map();
    function setOpen(open) {
      if (opened === open) return;
      opened = open;
      drawer.hidden = !open;
      drawer.classList.toggle('show', open);
      backdrop.hidden = !open;
      trigger.setAttribute('aria-expanded', String(open));
      if (open) {
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        background.forEach(element => { previousInert.set(element, element.inert); element.inert = true; });
        drawer.querySelector('.sidebar-close')?.focus();
      } else {
        document.body.style.overflow = previousOverflow;
        background.forEach(element => { element.inert = previousInert.get(element); });
        if (trigger.getClientRects().length) trigger.focus();
      }
    }
    trigger.addEventListener('click', () => setOpen(true));
    backdrop.addEventListener('click', () => setOpen(false));
    drawer.addEventListener('click', event => {
      if (event.target.closest('[data-sidebar-close]')) setOpen(false);
    });
    document.addEventListener('keydown', event => {
      if (!opened) return;
      if (event.key === 'Escape') { event.preventDefault(); setOpen(false); }
      if (event.key === 'Tab') {
        const controls = [...drawer.querySelectorAll('a[href], button:not(:disabled)')].filter(element => element.getClientRects().length);
        const first = controls[0], last = controls[controls.length - 1];
        if (!first) { event.preventDefault(); return; }
        if (event.shiftKey && (document.activeElement === first || !drawer.contains(document.activeElement))) {
          event.preventDefault(); last.focus();
        } else if (!event.shiftKey && (document.activeElement === last || !drawer.contains(document.activeElement))) {
          event.preventDefault(); first.focus();
        }
      }
    });
    global.matchMedia('(min-width: 992px)').addEventListener('change', event => {
      if (event.matches && opened) setOpen(false);
    });
  }
  global.EquipmentSidebar = Object.freeze({ links, markup, navMarkup, mount, updateUser, updateUnread, bindSignOut });
  if (typeof document !== 'undefined') initMobileDrawer();
})(globalThis);
