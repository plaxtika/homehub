export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export const $ = (sel, root = document) => root.querySelector(sel);

let toastTimer;
export function toast(msg, isError = false) {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.classList.toggle('error', isError);
  el.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.hidden = true; }, isError ? 6000 : 2500);
}

const fmt = new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
export const fmtDate = (sec) => (sec ? fmt.format(new Date(sec * 1000)) : '');

/** Sekunden → Wert für <input type="datetime-local"> in Ortszeit. */
export function toLocalInput(sec) {
  const d = new Date(sec * 1000);
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** Button während einer Aktion sperren. */
export async function busy(btn, fn) {
  const label = btn.textContent;
  btn.classList.add('busy');
  btn.disabled = true;
  try { return await fn(); } finally {
    btn.classList.remove('busy');
    btn.disabled = false;
    btn.textContent = label;
  }
}

/** Zeichen, wie Leser sie sehen: @[Firma] zählt als „Firma“. */
export const plain = (s) => String(s || '').replace(/@\[([^\]\n]+)\]/g, '$1');
export const charCount = (s) => [...plain(s)].length;

/** Endgültiger Beitragstext wie auf dem Server: Text + Hashtag-Zeile. */
export function compose(text, tags = []) {
  const t = String(text || '').trimEnd();
  if (!tags.length) return t;
  const line = tags.map((h) => `#${h}`).join(' ');
  return t ? `${t}\n\n${line}` : line;
}

export const cleanTag = (t) => String(t || '').trim().replace(/^#+/, '').replace(/[^\p{L}\p{N}_]/gu, '');
