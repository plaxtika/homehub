// Dünner Wrapper um api.php mit CSRF-Token und verständlichen Fehlern.
let csrf = '';
export const setCsrf = (t) => { csrf = t || ''; };

export class ApiError extends Error {
  constructor(message, status, data) { super(message); this.status = status; this.data = data; }
}

export async function api(action, { method = 'GET', id, body, form, timeout = 120000 } = {}) {
  const url = `api.php?a=${encodeURIComponent(action)}${id ? `&id=${encodeURIComponent(id)}` : ''}`;
  const headers = { 'X-CSRF-Token': csrf };
  let payload;
  if (form) payload = form;
  else if (body !== undefined) { payload = JSON.stringify(body); headers['Content-Type'] = 'application/json'; }
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), timeout);
  let res;
  try {
    res = await fetch(url, { method, headers, body: payload, credentials: 'same-origin', signal: ctrl.signal });
  } catch (e) {
    throw new ApiError(navigator.onLine ? 'Keine Verbindung zum Server.' : 'Du bist offline.', 0);
  } finally {
    clearTimeout(timer);
  }
  let data = null;
  try { data = await res.json(); } catch { /* leer */ }
  if (!res.ok) throw new ApiError(data?.error || `Serverfehler (${res.status})`, res.status, data);
  return data;
}
