// Lokale Ablage (IndexedDB) für Entwürfe + Bild-Blobs und Abgleich mit dem Server.
// Bearbeitet wird immer lokal; sync() schiebt Änderungen hoch, sobald Netz da ist.
import { api } from './api.js';

const DB_NAME = 'lmesse';
let dbPromise;

function db() {
  dbPromise ??= new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => {
      req.result.createObjectStore('posts', { keyPath: 'id' });
      req.result.createObjectStore('blobs');
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
  return dbPromise;
}

async function tx(store, mode, fn) {
  const d = await db();
  return new Promise((resolve, reject) => {
    const t = d.transaction(store, mode);
    const result = fn(t.objectStore(store));
    t.oncomplete = () => resolve(result instanceof IDBRequest ? result.result : result);
    t.onerror = () => reject(t.error);
  });
}

export const getPost = (id) => tx('posts', 'readonly', (s) => s.get(id));
export const allPosts = () => tx('posts', 'readonly', (s) => s.getAll());
export const putBlob = (id, blob) => tx('blobs', 'readwrite', (s) => s.put(blob, id));
export const getBlob = (id) => tx('blobs', 'readonly', (s) => s.get(id));
export const deleteBlob = (id) => tx('blobs', 'readwrite', (s) => s.delete(id));

const SERVER_FIELDS = ['status', 'scheduled_at', 'published_at', 'linkedin_urn', 'url', 'error', 'attempts'];

export function newPost() {
  return {
    id: crypto.randomUUID(), template_id: null, title_points: '', body_points: '', text: '',
    visibility: 'PUBLIC', voice: 'ich', hashtags: [], suggestions: [], images: [], status: 'draft', scheduled_at: null, published_at: null,
    url: null, error: null, rev: 0, syncedRev: -1, onServer: false, updated_at: Date.now(),
  };
}

/** Lokal speichern und Synchronisation anstoßen. */
export async function savePost(post) {
  post.rev = (post.rev || 0) + 1;
  post.updated_at = Date.now();
  await tx('posts', 'readwrite', (s) => s.put(post));
  scheduleSync();
  return post;
}

/** Serverstand übernehmen, ohne den Beitrag als lokal geändert zu markieren. */
export const putRaw = (post) => tx('posts', 'readwrite', (s) => s.put(post));

export async function removeLocal(id) {
  const p = await getPost(id);
  for (const img of p?.images || []) await deleteBlob(img.id);
  await tx('posts', 'readwrite', (s) => s.delete(id));
}

export const isDirty = (p) => p.rev !== p.syncedRev;

let syncTimer;
let running = null;
const listeners = new Set();
export const onSynced = (fn) => listeners.add(fn);

export function scheduleSync(delay = 800) {
  clearTimeout(syncTimer);
  syncTimer = setTimeout(() => syncAll().catch(() => {}), delay);
}

export async function syncAll() {
  if (!navigator.onLine) return;
  if (running) return running;
  running = (async () => {
    for (const p of await allPosts()) {
      if (isDirty(p) && !['published', 'publishing'].includes(p.status)) await syncPost(p.id).catch(() => {});
    }
  })().finally(() => { running = null; });
  return running;
}

/** Einen Beitrag vollständig hochladen (Felder, neue/geänderte Bilder, Reihenfolge). */
export async function syncPost(id) {
  const p = await getPost(id);
  if (!p) return null;
  const rev = p.rev;
  const fields = (imgs) => ({
    template_id: p.template_id, title_points: p.title_points, body_points: p.body_points,
    text: p.text, visibility: p.visibility, voice: p.voice || 'ich', hashtags: p.hashtags || [], images: imgs.map((i) => ({ id: i.id, alt: i.alt || '' })),
  });

  await api('post', { method: 'PUT', id, body: fields(p.images.filter((i) => i.uploaded)) });
  let uploadedAny = false;
  const uploaded = new Map(); // Bild-ID → hochgeladene Version
  for (const [idx, img] of p.images.entries()) {
    if (img.uploaded) continue;
    const blob = await getBlob(img.id);
    if (!blob) continue;
    const form = new FormData();
    form.append('file', blob, `${img.id}.jpg`);
    form.append('image_id', img.id);
    form.append('position', String(idx));
    await api('image', { method: 'POST', id, form });
    img.uploaded = true;
    uploaded.set(img.id, img.ver || 0);
    uploadedAny = true;
  }
  const server = await api('post', { method: 'PUT', id, body: fields(p.images) });

  // Zwischenzeitliche lokale Änderungen nicht überschreiben – nur Upload-Status übernehmen.
  const latest = await getPost(id);
  if (!latest) return null;
  // Nur als hochgeladen markieren, wenn das Bild seitdem nicht erneut zugeschnitten wurde.
  latest.images.forEach((i) => { if (uploaded.has(i.id) && uploaded.get(i.id) === (i.ver || 0)) i.uploaded = true; });
  if (latest.rev === rev) latest.syncedRev = rev;
  mergeServer(latest, server);
  latest.onServer = true;
  await tx('posts', 'readwrite', (s) => s.put(latest));
  if (uploadedAny || latest.rev === rev) listeners.forEach((fn) => fn(latest));
  return latest;
}

export function mergeServer(local, server) {
  for (const k of SERVER_FIELDS) local[k] = server[k] ?? null;
  const byId = Object.fromEntries((server.images || []).map((i) => [i.id, i]));
  local.images.forEach((i) => { if (byId[i.id]) i.url = byId[i.id].url; });
  return local;
}

/** Serverliste übernehmen: nicht lokal geänderte Beiträge kommen vom Server. */
export async function pullServer(serverPosts) {
  const locals = Object.fromEntries((await allPosts()).map((p) => [p.id, p]));
  const seen = new Set();
  for (const sp of serverPosts) {
    seen.add(sp.id);
    const lp = locals[sp.id];
    if (lp && isDirty(lp)) {
      mergeServer(lp, sp);
      await tx('posts', 'readwrite', (s) => s.put(lp));
      continue;
    }
    const merged = {
      ...(lp || newPost()), ...sp, id: sp.id,
      images: sp.images.map((i) => ({ id: i.id, alt: i.alt, url: i.url, uploaded: true, width: i.width, height: i.height })),
      onServer: true,
    };
    merged.syncedRev = merged.rev;
    await tx('posts', 'readwrite', (s) => s.put(merged));
  }
  // Auf dem Server gelöschte (und lokal unveränderte) Beiträge auch lokal entfernen.
  for (const lp of Object.values(locals)) {
    if (!seen.has(lp.id) && lp.onServer && !isDirty(lp)) await removeLocal(lp.id);
  }
}
