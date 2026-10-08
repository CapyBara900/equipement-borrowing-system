/* ==========================================================================
   api.js — every call to the backend goes through here.
   Uses fetch() so pages update without a full reload (AJAX requirement).
   ========================================================================== */

const API_BASE = 'api';

class ApiError extends Error {
  constructor(message, status, code = null) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

async function request(path, { method = 'GET', body = null, query = null } = {}) {
  let url = `${API_BASE}/${path}`;

  if (query) {
    const params = new URLSearchParams();
    Object.entries(query).forEach(([k, v]) => {
      if (v !== null && v !== undefined && v !== '') params.append(k, v);
    });
    const qs = params.toString();
    if (qs) url += `?${qs}`;
  }

  const options = {
    method,
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',   // send the PHP session cookie
  };
  if (body) options.body = JSON.stringify(body);

  let res;
  try {
    res = await fetch(url, options);
  } catch (networkErr) {
    throw new ApiError('Cannot reach the server. Is Apache running?', 0);
  }

  let payload = {};
  try {
    payload = await res.json();
  } catch (parseErr) {
    throw new ApiError(`Server returned an unreadable response (HTTP ${res.status}).`, res.status);
  }

  if (!res.ok || payload.success === false) {
    throw new ApiError(payload.message || `Request failed (HTTP ${res.status}).`, res.status, payload.code);
  }
  return payload;
}

const Api = {
  // --- auth ---
  register: (data) => request('auth/register.php', { method: 'POST', body: data }),
  login: (data) => request('auth/login.php', { method: 'POST', body: data }),
  logout: () => request('auth/logout.php', { method: 'POST' }),
  me: () => request('auth/me.php'),

  // --- catalog ---
  listEquipment: (q) => request('equipment/index.php', { query: q }),
  getEquipment: (id) => request('equipment/index.php', { query: { id } }),
  createEquipment: (d) => request('equipment/index.php', { method: 'POST', body: d }),
  updateEquipment: (d) => request('equipment/index.php', { method: 'PUT', body: d }),
  deleteEquipment: (id) => request('equipment/index.php', { method: 'DELETE', query: { id } }),

  listCategories: (q) => request('categories/index.php', { query: q }),
  createCategory: (d) => request('categories/index.php', { method: 'POST', body: d }),
  updateCategory: (d) => request('categories/index.php', { method: 'PUT', body: d }),
  deleteCategory: (id) => request('categories/index.php', { method: 'DELETE', query: { id } }),

  // --- customer cart ---
  cartCapabilities: () => request('cart/index.php', { query: { capabilities: 1 } }),
  getCart: () => request('cart/index.php'),
  addCartItem: (body) => request('cart/index.php', { method: 'POST', body }),
  updateCartItem: (cart_item_id, details) => request('cart/index.php', { method: 'PUT', body: { cart_item_id, ...details } }),
  removeCartItem: (cart_item_id) => request('cart/index.php', { method: 'DELETE', body: { cart_item_id } }),
  importCart: (items) => request('cart/import.php', { method: 'POST', body: { items } }),
  checkoutCart: (body) => request('cart/checkout.php', { method: 'POST', body }),

  // --- borrowing ---
  getPickupWindow: () => request('borrowing-settings/index.php'),
  listRequests: (q) => request('requests/index.php', { query: q }),
  createRequest: (d) => request('requests/index.php', { method: 'POST', body: d }),
  decideRequest: (d) => request('requests/index.php', { method: 'PUT', body: d }),
  cancelRequest: (id) => request('requests/index.php', { method: 'DELETE', query: { id } }),

  listReturns: () => request('returns/index.php'),
  recordReturn: (d) => request('returns/index.php', { method: 'POST', body: d }),

  listConditionReports: (q) => request('condition-reports/index.php', { query: q }),
  createConditionReport: (d) => request('condition-reports/index.php', { method: 'POST', body: d }),

  // --- people & alerts ---
  listUsers: () => request('users/index.php'),
  createUser: (d) => request('users/index.php', { method: 'POST', body: d }),
  setUserRole: (d) => request('users/index.php', { method: 'PUT', body: d }),
  unlockUser: (id) => request('users/index.php', { method: 'PUT', body: { user_id: id, action: 'unlock' } }),
  deleteUser: (id) => request('users/index.php', { method: 'DELETE', query: { id } }),

  listNotifications: (q) => request('notifications/index.php', { query: q }),
  markNotification: (d) => request('notifications/index.php', { method: 'PUT', body: d }),

  dashboard: () => request('dashboard/index.php'),
};

/* --------------------------------------------------------------------------
   External API: QR Server (api.qrserver.com).
   Staff can print a QR label for an item so it can be scanned at the desk.
   Fetched as a blob and turned into an object URL — a real request/receive/use
   cycle against a third-party API, not just an <img src>.
   -------------------------------------------------------------------------- */
async function fetchQrCode(text) {
  const endpoint = 'https://api.qrserver.com/v1/create-qr-code/'
    + `?size=360x360&margin=10&data=${encodeURIComponent(text)}`;
  const res = await fetch(endpoint);
  if (!res.ok) throw new ApiError('The QR code service is unavailable right now.', res.status);
  const blob = await res.blob();
  return URL.createObjectURL(blob);
}
