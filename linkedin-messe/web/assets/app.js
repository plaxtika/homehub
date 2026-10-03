import { api, setCsrf } from './api.js';
import * as store from './store.js';
import { normalize, cropImage } from './images.js';
import { renderPreview, linkify } from './preview.js';
import { esc, $, toast, fmtDate, toLocalInput, busy, charCount, compose, cleanTag } from './util.js';

const view = $('#view');
const state = { me: null, templates: [], partners: [], limits: { chars: 3000, images: 20 } };
const objectUrls = new Map();

// ---------- Start ----------

async function init() {
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
  window.addEventListener('online', onNet);
  window.addEventListener('offline', onNet);
  window.addEventListener('hashchange', route);
  onNet();

  try {
    state.me = await api('me');
    localStorage.setItem('me', JSON.stringify(state.me));
  } catch {
    state.me = JSON.parse(localStorage.getItem('me') || 'null'); // offline: letzter bekannter Stand
  }
  if (!state.me?.loggedIn) return renderLogin();

  setCsrf(state.me.csrf);
  state.limits = state.me.limits || state.limits;
  $('#nav').hidden = false;
  renderBanner();
  await loadTemplates();
  store.syncAll().catch(() => {});
  route();
}

function onNet() {
  $('#net').hidden = navigator.onLine;
  if (navigator.onLine) store.scheduleSync(100);
}

function renderBanner() {
  const b = $('#banner');
  const left = state.me.tokenExpires - Date.now() / 1000;
  if (state.me.dryRun) {
    b.innerHTML = 'Testmodus (dry_run): es wird nichts wirklich gepostet.';
    b.hidden = false;
  } else if (left < 7 * 86400) {
    b.innerHTML = left <= 0
      ? 'LinkedIn-Login abgelaufen. <a href="oauth.php">Jetzt neu anmelden</a>'
      : `LinkedIn-Login läuft in ${Math.ceil(left / 86400)} Tag(en) ab – geplante Beiträge danach schlagen fehl. <a href="oauth.php">Jetzt erneuern</a>`;
    b.hidden = false;
  }
}

function renderLogin() {
  const params = new URLSearchParams(location.search);
  const err = params.get('login_error');
  const pending = state.me?.pendingSub;
  view.innerHTML = `
    <div class="login">
      <h1>Messe-Poster</h1>
      <p class="muted">Beiträge mit Fotos vorbereiten, von Claude texten lassen und auf LinkedIn posten.</p>
      ${err ? `<p class="err">${esc(err)}</p>` : ''}
      ${pending ? `<div class="card" style="text-align:left"><b>Ersteinrichtung:</b> Trage diese LinkedIn-ID in <code>config.php</code> bei <code>allowed_sub</code> ein und melde dich erneut an:<p><code>${esc(pending)}</code></p></div>` : ''}
      <a class="btn primary" href="oauth.php">Mit LinkedIn anmelden</a>
      ${navigator.onLine ? '' : '<p class="muted">Du bist offline – Anmeldung braucht Internet.</p>'}
    </div>`;
}

async function loadTemplates() {
  try {
    [state.templates, state.partners] = await Promise.all([api('templates'), api('partners')]);
    localStorage.setItem('templates', JSON.stringify(state.templates));
    localStorage.setItem('partners', JSON.stringify(state.partners));
  } catch {
    state.templates = JSON.parse(localStorage.getItem('templates') || '[]');
    state.partners = JSON.parse(localStorage.getItem('partners') || '[]');
  }
}

function route() {
  const [, page, id] = location.hash.split('/');
  if (page === 'edit') return renderEditor(id);
  if (page === 'preview') return renderPreviewPage(id);
  if (page === 'templates') return renderTemplates();
  return renderList();
}

const imgSrc = async (img) => {
  const blob = !img.uploaded || !img.url ? await store.getBlob(img.id) : null;
  if (blob) {
    const key = `${img.id}:${img.ver || 0}`;
    if (!objectUrls.has(key)) objectUrls.set(key, URL.createObjectURL(blob));
    return objectUrls.get(key);
  }
  return img.url ? `${img.url}&v=${img.ver || 0}` : '';
};

// ---------- Liste ----------

const STATUS = { draft: 'Entwurf', scheduled: 'Geplant', publishing: 'Wird gepostet …', published: 'Veröffentlicht', failed: 'Fehler' };

async function renderList() {
  if (navigator.onLine) {
    try { await store.pullServer(await api('posts')); } catch (e) { if (e.status === 401) return location.reload(); }
  }
  const posts = (await store.allPosts()).sort((a, b) => sortKey(b) - sortKey(a));
  const groups = [
    ['Geplant', posts.filter((p) => ['scheduled', 'publishing'].includes(p.status))],
    ['Entwürfe', posts.filter((p) => ['draft', 'failed'].includes(p.status))],
    ['Veröffentlicht', posts.filter((p) => p.status === 'published')],
  ];
  const items = await Promise.all(posts.map(async (p) => [p.id, p.images[0] ? await imgSrc(p.images[0]) : '']));
  const thumbs = Object.fromEntries(items);

  view.innerHTML = (posts.length ? groups.filter(([, list]) => list.length).map(([title, list]) => `
    <div class="section-title">${title}</div>
    ${list.map((p) => `
      <a class="card post-item" href="#/${p.status === 'published' || p.status === 'scheduled' ? 'preview' : 'edit'}/${p.id}">
        ${thumbs[p.id] ? `<img src="${esc(thumbs[p.id])}" alt="">` : '<div class="ph"></div>'}
        <div class="grow">
          <div class="t">${esc(firstLine(p) || 'Ohne Text')}</div>
          <div class="small muted">
            <span class="badge ${p.status}">${STATUS[p.status] || p.status}</span>
            ${store.isDirty(p) ? '<span class="badge local">nicht synchron</span>' : ''}
            ${p.status === 'scheduled' ? fmtDate(p.scheduled_at) : p.status === 'published' ? fmtDate(p.published_at) : ''}
            · ${p.images.length} Bild(er)
          </div>
        </div>
      </a>`).join('')}`).join('')
    : '<p class="muted center" style="margin-top:40px">Noch keine Beiträge.<br>Tippe auf „Neuer Beitrag“.</p>')
    + '<div style="height:80px"></div><button class="btn primary fab" id="new">＋ Neuer Beitrag</button>';

  $('#new').onclick = async () => {
    const p = store.newPost();
    p.template_id = state.templates[0]?.id ?? null;
    await store.savePost(p);
    location.hash = `#/edit/${p.id}`;
  };
}

const sortKey = (p) => p.scheduled_at || p.published_at || p.updated_at / 1000 || 0;
const firstLine = (p) => (p.text || p.title_points || '').split('\n').find((l) => l.trim()) || '';

// ---------- Editor ----------

async function renderEditor(id) {
  let post = await store.getPost(id);
  if (!post && navigator.onLine) {
    try { await store.pullServer(await api('posts')); post = await store.getPost(id); } catch { /* unten behandelt */ }
  }
  if (!post) { view.innerHTML = '<p>Beitrag nicht gefunden. <a href="#/">Zur Übersicht</a></p>'; return; }
  if (['published', 'publishing'].includes(post.status)) { location.hash = `#/preview/${id}`; return; }

  const tplOptions = state.templates.map((t) => `<option value="${t.id}" ${t.id === post.template_id ? 'selected' : ''}>${esc(t.name)}${t.provisional ? ' (vorläufig)' : ''}</option>`).join('');

  view.innerHTML = `
    ${post.status === 'scheduled' ? `<div class="card small">Geplant für <b>${fmtDate(post.scheduled_at)}</b>. Änderungen werden übernommen.</div>` : ''}
    ${post.status === 'failed' ? `<div class="card small err">Letzter Versuch fehlgeschlagen: ${esc(post.error || '')}</div>` : ''}
    <section class="card">
      <h2><span class="step">1</span> Bilder <span class="muted small" id="imgCount"></span></h2>
      <div class="thumbs" id="thumbs"></div>
      <p class="hint">Antippen = zuschneiden/drehen · gedrückt halten und ziehen = Reihenfolge</p>
      <input type="file" id="file" accept="image/*" multiple hidden>
    </section>

    <section class="card">
      <h2><span class="step">2</span> Stichpunkte</h2>
      <label for="tpl">Vorlage</label>
      <select id="tpl">${tplOptions}</select>
      <label>Perspektive</label>
      <div class="seg light" id="voice">
        <button type="button" data-voice="ich" class="${post.voice !== 'wir' ? 'on' : ''}">Ich</button>
        <button type="button" data-voice="wir" class="${post.voice === 'wir' ? 'on' : ''}">Wir / unser</button>
      </div>
      <label for="tp">Stichpunkte Überschrift</label>
      <textarea id="tp" rows="2" placeholder="z. B. Tag 1 light+building, Halle 11">${esc(post.title_points)}</textarea>
      <label for="bp">Stichpunkte Text</label>
      <textarea id="bp" rows="5" placeholder="- Neuheit X von Hersteller Y gesehen&#10;- tolles Gespräch mit Kunde Z über …&#10;- Stand B12, morgen ab 9 Uhr">${esc(post.body_points)}</textarea>
      <label class="check"><input type="checkbox" id="withImg" checked> Bilder an Claude mitschicken (bessere Einordnung)</label>
      <button class="btn primary block" id="gen" style="margin-top:12px">✨ Text erstellen</button>
    </section>

    <section class="card">
      <h2><span class="step">3</span> Text</h2>
      <textarea id="text" class="full" placeholder="Hier erscheint der generierte Text – frei bearbeitbar.">${esc(post.text)}</textarea>
      <div class="mentions">
        <span class="small muted">Verknüpfen:</span>
        ${state.partners.map((p) => `<button type="button" class="chip" data-mention="${esc(p.name)}" title="${p.org_id ? 'wird auf LinkedIn verlinkt' : 'ohne LinkedIn-ID – erscheint als Text'}">${p.kind === 'person' ? '👤' : '🏢'} ${esc(p.name)}${p.org_id ? '' : ' ⚠︎'}</button>`).join('')}
        <a class="chip" href="#/templates">＋</a>
      </div>
      <label>Hashtags</label>
      <div class="tags" id="tags"></div>
      <div class="row" style="margin-top:6px">
        <input type="text" id="tagIn" class="grow" placeholder="#Hashtag ergänzen" autocapitalize="off" autocomplete="off" style="flex:1 1 160px">
        <button class="btn" id="tagAdd" type="button">＋</button>
      </div>
      <div class="tags sugg" id="sugg"></div>
      <div class="counter" id="cnt"></div>
      <div class="row" style="margin-top:8px">
        <input type="text" id="instr" class="grow" placeholder="Nachbessern: z. B. kürzer, mehr Fachbezug …" style="flex:1 1 200px">
        <button class="btn" id="refine">Überarbeiten</button>
      </div>
    </section>

    <div class="row" style="margin-bottom:24px">
      <button class="btn danger" id="del">Löschen</button>
      <span class="grow"></span>
      <a class="btn primary" href="#/preview/${id}">Vorschau →</a>
    </div>`;

  const update = async (fn) => {
    post = (await store.getPost(id)) || post;
    fn(post);
    await store.savePost(post);
  };

  // --- Bilder ---
  const thumbs = $('#thumbs');
  const drawThumbs = async () => {
    const srcs = await Promise.all(post.images.map(imgSrc));
    thumbs.innerHTML = post.images.map((img, i) => `
      <div class="thumb" data-id="${img.id}">
        <img src="${esc(srcs[i])}" alt="">
        <span class="n">${i + 1}</span>
        ${img.uploaded ? '' : '<span class="pending" title="noch nicht hochgeladen"></span>'}
        <div class="acts"><button type="button" data-del="${img.id}" aria-label="Bild entfernen">✕</button></div>
      </div>`).join('')
      + (post.images.length < state.limits.images ? '<button type="button" class="add-tile" id="add"><span><b>＋</b>Foto</span></button>' : '');
    $('#imgCount').textContent = post.images.length ? `(${post.images.length}/${state.limits.images})` : '';
    $('#add')?.addEventListener('click', () => $('#file').click());
  };
  await drawThumbs();

  window.Sortable.create(thumbs, {
    animation: 150, draggable: '.thumb', delay: 200, delayOnTouchOnly: true, ghostClass: 'ghost',
    onEnd: async () => {
      const order = [...thumbs.querySelectorAll('.thumb')].map((t) => t.dataset.id);
      await update((p) => { p.images.sort((a, b) => order.indexOf(a.id) - order.indexOf(b.id)); });
      await drawThumbs();
    },
  });

  thumbs.addEventListener('click', async (e) => {
    const del = e.target.closest('[data-del]');
    if (del) {
      if (!confirm('Bild entfernen?')) return;
      await update((p) => { p.images = p.images.filter((i) => i.id !== del.dataset.del); });
      await store.deleteBlob(del.dataset.del);
      return drawThumbs();
    }
    const tile = e.target.closest('.thumb');
    if (!tile) return;
    const img = post.images.find((i) => i.id === tile.dataset.id);
    const src = await imgSrc(img);
    if (!src) return toast('Bild ist offline nicht verfügbar.', true);
    const res = await cropImage(src);
    if (!res) return;
    await store.putBlob(img.id, res.blob);
    await update((p) => {
      const t = p.images.find((i) => i.id === img.id);
      Object.assign(t, { uploaded: false, ver: (t.ver || 0) + 1, width: res.width, height: res.height });
    });
    await drawThumbs();
  });

  $('#file').addEventListener('change', async (e) => {
    const files = [...e.target.files].slice(0, state.limits.images - post.images.length);
    e.target.value = '';
    if (!files.length) return;
    toast(`${files.length} Bild(er) werden aufbereitet …`);
    for (const f of files) {
      try {
        const { blob, width, height } = await normalize(f);
        const imgId = crypto.randomUUID();
        await store.putBlob(imgId, blob);
        await update((p) => p.images.push({ id: imgId, alt: '', uploaded: false, ver: 0, width, height }));
      } catch (err) {
        toast(`${f.name}: ${err.message}`, true);
      }
    }
    await drawThumbs();
  });

  // --- Texte ---
  const bind = (sel, key) => {
    let t;
    $(sel).addEventListener('input', (e) => {
      clearTimeout(t);
      t = setTimeout(() => update((p) => { p[key] = e.target.value; }), 400);
    });
  };
  bind('#tp', 'title_points');
  bind('#bp', 'body_points');
  bind('#text', 'text');
  $('#tpl').addEventListener('change', (e) => update((p) => { p.template_id = Number(e.target.value); }));
  $('#voice').addEventListener('click', (e) => {
    const b = e.target.closest('[data-voice]');
    if (!b) return;
    $('#voice').querySelectorAll('button').forEach((x) => x.classList.toggle('on', x === b));
    update((p) => { p.voice = b.dataset.voice; });
  });
  view.querySelector('.mentions').addEventListener('click', (e) => {
    const b = e.target.closest('[data-mention]');
    if (!b) return;
    const ta = $('#text');
    const ins = `@[${b.dataset.mention}]`;
    const pos = ta.selectionStart ?? ta.value.length;
    const before = ta.value.slice(0, pos);
    const sep = before && !/\s$/.test(before) ? ' ' : '';
    ta.value = before + sep + ins + ta.value.slice(ta.selectionEnd ?? pos);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = pos + sep.length + ins.length;
    ta.dispatchEvent(new Event('input'));
  });

  const cnt = $('#cnt');
  const count = () => {
    const n = charCount(compose($('#text').value, post.hashtags || []));
    cnt.textContent = `${n} / ${state.limits.chars} Zeichen`;
    cnt.classList.toggle('over', n > state.limits.chars);
  };
  $('#text').addEventListener('input', count);

  // --- Hashtags ---
  const drawTags = () => {
    const tags = post.hashtags || [];
    const lower = new Set(tags.map((t) => t.toLowerCase()));
    const sugg = [...(post.suggestions || []), ...(state.me.defaultHashtags || [])]
      .filter((t, i, arr) => !lower.has(t.toLowerCase()) && arr.findIndex((x) => x.toLowerCase() === t.toLowerCase()) === i);
    $('#tags').innerHTML = tags.length
      ? tags.map((t) => `<button type="button" class="chip on" data-rm="${esc(t)}">#${esc(t)} ✕</button>`).join('')
      : '<span class="small muted">Noch keine – werden beim Text erstellen vorgeschlagen.</span>';
    $('#sugg').innerHTML = sugg.length ? `<span class="small muted">Vorschläge:</span> ${sugg.map((t) => `<button type="button" class="chip" data-add="${esc(t)}">＋ #${esc(t)}</button>`).join('')}` : '';
    count();
  };
  const setTags = async (fn) => {
    await update((p) => { p.hashtags = fn([...(p.hashtags || [])]); });
    drawTags();
  };
  const addTag = (raw) => {
    const tags = String(raw).split(/[\s,]+/).map(cleanTag).filter(Boolean);
    if (!tags.length) return;
    setTags((list) => {
      for (const t of tags) if (!list.some((x) => x.toLowerCase() === t.toLowerCase())) list.push(t);
      return list.slice(0, 30);
    });
  };
  $('#tags').addEventListener('click', (e) => {
    const b = e.target.closest('[data-rm]');
    if (b) setTags((list) => list.filter((t) => t !== b.dataset.rm));
  });
  $('#sugg').addEventListener('click', (e) => {
    const b = e.target.closest('[data-add]');
    if (b) addTag(b.dataset.add);
  });
  $('#tagAdd').onclick = () => { addTag($('#tagIn').value); $('#tagIn').value = ''; };
  $('#tagIn').addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); $('#tagAdd').click(); }
  });
  drawTags();

  const generate = async (instruction = '') => {
    if (!navigator.onLine) return toast('Text erstellen braucht Internet.', true);
    await update((p) => {
      p.title_points = $('#tp').value; p.body_points = $('#bp').value;
      p.text = $('#text').value; p.template_id = Number($('#tpl').value) || null;
    });
    await store.syncPost(id);
    const r = await api('generate', {
      method: 'POST', id,
      body: { template_id: Number($('#tpl').value), voice: $('#voice .on')?.dataset.voice || 'ich', with_images: $('#withImg').checked, current_text: instruction ? compose($('#text').value, post.hashtags || []) : '', instruction },
    });
    const text = `${r.hook.trim()}\n\n${r.body.trim()}`;
    $('#text').value = text;
    // Beim Überarbeiten bleiben die selbst gewählten Hashtags erhalten; Claudes Tags landen dann bei den Vorschlägen.
    await update((p) => {
      p.text = text;
      if (!instruction || !(p.hashtags || []).length) p.hashtags = r.hashtags;
      p.suggestions = [...(instruction ? r.hashtags : []), ...(r.hashtag_suggestions || [])];
    });
    drawTags();
    $('#text').scrollIntoView({ behavior: 'smooth', block: 'center' });
  };
  $('#gen').onclick = (e) => busy(e.currentTarget, async () => {
    if (!$('#tp').value.trim() && !$('#bp').value.trim()) return toast('Bitte zuerst Stichpunkte eingeben.', true);
    if ($('#text').value.trim() && !confirm('Vorhandenen Text ersetzen?')) return;
    e.currentTarget.textContent = 'Claude schreibt …';
    await generate().catch((err) => toast(err.message, true));
  });
  $('#refine').onclick = (e) => busy(e.currentTarget, async () => {
    const instr = $('#instr').value.trim();
    if (!instr) return toast('Bitte angeben, was geändert werden soll.', true);
    if (!$('#text').value.trim()) return toast('Erst einen Text erstellen.', true);
    e.currentTarget.textContent = '…';
    await generate(instr).then(() => { $('#instr').value = ''; }).catch((err) => toast(err.message, true));
  });

  $('#del').onclick = async () => {
    if (!confirm('Entwurf endgültig löschen?')) return;
    try {
      if (post.onServer || navigator.onLine) await api('post', { method: 'DELETE', id }).catch((e) => { if (e.status !== 0) throw e; });
      await store.removeLocal(id);
      location.hash = '#/';
    } catch (err) { toast(err.message, true); }
  };
}

// ---------- Vorschau & Veröffentlichen ----------

async function renderPreviewPage(id) {
  let post = await store.getPost(id);
  if (!post) { location.hash = '#/'; return; }
  const urls = await Promise.all(post.images.map(imgSrc));
  const now = Math.floor(Date.now() / 1000);
  const defaultAt = post.scheduled_at || (Math.ceil((now + 3600) / 900) * 900);
  const full = compose(post.text, post.hashtags || []);
  const n = charCount(full);
  const editable = !['published', 'publishing'].includes(post.status);

  view.innerHTML = `
    <div id="pv"></div>
    <section class="card">
      <h2>Volltext <span class="muted small">${n} / ${state.limits.chars} Zeichen</span></h2>
      <pre class="fulltext">${linkify(full) || '<span class="muted">Kein Text</span>'}</pre>
      ${unlinked(post.text).length ? `<p class="hint">⚠︎ Ohne LinkedIn-ID, erscheint als normaler Text: ${esc(unlinked(post.text).join(', '))} – <a href="#/templates">ID ergänzen</a></p>` : ''}
    </section>
    <section class="card" id="actions"></section>`;
  renderPreview($('#pv'), {
    text: full, imageUrls: urls, name: state.me.name, picture: state.me.picture,
    when: post.status === 'published' ? fmtDate(post.published_at) : post.status === 'scheduled' ? `geplant ${fmtDate(post.scheduled_at)}` : 'Jetzt',
  });

  const actions = $('#actions');
  if (post.status === 'published') {
    actions.innerHTML = `
      <p class="ok"><b>Veröffentlicht</b> am ${fmtDate(post.published_at)}</p>
      ${post.warning ? `<p class="hint">⚠︎ ${esc(post.warning)}</p>` : ''}
      <div class="row">
        ${post.url ? `<a class="btn primary" href="${esc(post.url)}" target="_blank" rel="noopener">Auf LinkedIn öffnen</a>` : ''}
        <button class="btn danger" id="unpub">Von LinkedIn löschen</button>
      </div>`;
    $('#unpub').onclick = (e) => busy(e.currentTarget, async () => {
      if (!confirm('Beitrag auf LinkedIn wirklich löschen? Er wird hier wieder zum Entwurf.')) return;
      await act(id, 'unpublish');
    });
    return;
  }
  if (post.status === 'publishing') {
    actions.innerHTML = '<p>Wird gerade veröffentlicht … <a href="">aktualisieren</a></p>';
    return;
  }

  actions.innerHTML = `
    ${post.status === 'failed' ? `<p class="err">Fehler: ${esc(post.error || '')}</p>` : ''}
    ${post.status === 'scheduled' ? `<p>⏰ Geplant für <b>${fmtDate(post.scheduled_at)}</b>${post.attempts ? ` · Versuch ${post.attempts + 1}` : ''}</p>` : ''}
    <label for="vis">Sichtbarkeit</label>
    <select id="vis">
      <option value="PUBLIC" ${post.visibility === 'PUBLIC' ? 'selected' : ''}>Öffentlich</option>
      <option value="CONNECTIONS" ${post.visibility === 'CONNECTIONS' ? 'selected' : ''}>Nur Kontakte (gut zum Testen)</option>
    </select>
    <button class="btn primary block" id="pubNow" style="margin-top:14px">Jetzt veröffentlichen</button>
    <label for="at">Oder zeitversetzt posten</label>
    <div class="row">
      <input type="datetime-local" id="at" value="${toLocalInput(defaultAt)}" style="flex:1 1 200px">
      <button class="btn" id="sched">${post.status === 'scheduled' ? 'Zeit ändern' : 'Einplanen'}</button>
    </div>
    <p class="hint">Geplante Beiträge postet der Server – das Handy muss dafür nicht online sein. Genauigkeit ca. ±5 Minuten.</p>
    <div class="row" style="margin-top:14px">
      ${editable ? `<a class="btn" href="#/edit/${id}">← Bearbeiten</a>` : ''}
      ${post.status === 'scheduled' ? '<button class="btn" id="unsched">Planung aufheben</button>' : ''}
    </div>`;

  $('#vis').onchange = async (e) => {
    post = await store.getPost(id);
    post.visibility = e.target.value;
    await store.savePost(post);
  };
  const precheck = () => {
    if (!navigator.onLine) throw new Error('Dafür brauchst du Internet.');
    if (!full.trim() && !post.images.length) throw new Error('Beitrag ist leer.');
    if (n > state.limits.chars) throw new Error(`Text ist zu lang (${n}/${state.limits.chars}).`);
  };
  $('#pubNow').onclick = (e) => busy(e.currentTarget, async () => {
    try { precheck(); } catch (err) { return toast(err.message, true); }
    if (!confirm(`Jetzt ${post.visibility === 'PUBLIC' ? 'öffentlich' : 'für Kontakte'} auf LinkedIn veröffentlichen?`)) return;
    e.currentTarget.textContent = 'Wird hochgeladen …';
    await act(id, 'publish');
  });
  $('#sched').onclick = (e) => busy(e.currentTarget, async () => {
    try { precheck(); } catch (err) { return toast(err.message, true); }
    const at = Math.floor(new Date($('#at').value).getTime() / 1000);
    if (!at || at < Date.now() / 1000) return toast('Bitte einen Zeitpunkt in der Zukunft wählen.', true);
    await act(id, 'schedule', { at });
  });
  $('#unsched')?.addEventListener('click', (e) => busy(e.currentTarget, () => act(id, 'unschedule')));
}

/** Erwähnte Firmen, für die keine Organisations-ID hinterlegt ist. */
function unlinked(text) {
  const ok = new Set(state.partners.filter((p) => p.org_id).map((p) => p.name));
  return [...new Set([...String(text || '').matchAll(/@\[([^\]\n]+)\]/g)].map((m) => m[1]))].filter((n) => !ok.has(n));
}

/** Aktion auf dem Server ausführen – vorher alle lokalen Änderungen hochladen. */
async function act(id, action, body = {}) {
  try {
    await store.syncPost(id);
    const local = await store.getPost(id);
    if (store.isDirty(local)) throw new Error('Änderungen konnten nicht synchronisiert werden – bitte erneut versuchen.');
    const server = await api(action, { method: 'POST', id, body });
    store.mergeServer(local, server);
    await store.putRaw(local);
    const msg = { publish: 'Veröffentlicht 🎉', schedule: `Eingeplant für ${fmtDate(server.scheduled_at)}`, unschedule: 'Planung aufgehoben', unpublish: 'Auf LinkedIn gelöscht' }[action];
    toast(msg);
  } catch (err) {
    toast(err.message, true);
    try { const p = await store.getPost(id); store.mergeServer(p, await api('post', { id })); await store.putRaw(p); } catch { /* egal */ }
  }
  renderPreviewPage(id);
}

// ---------- Vorlagen ----------

async function renderTemplates() {
  await loadTemplates();
  view.innerHTML = `
    <p class="muted small">Die Vorlagen steuern den Stil. Füge 3–5 echte Beispielposts ein (z. B. von der Sonepar-Deutschland-Seite kopiert) – daran orientiert sich Claude am stärksten.</p>
    ${state.templates.map((t) => tplCard(t)).join('')}
    <button class="btn block" id="newTpl">＋ Neue Vorlage</button>

    <div class="section-title">Standard-Hashtags (immer als Vorschlag)</div>
    <section class="card">
      <input type="text" id="defTags" value="${esc((state.me.defaultHashtags || []).map((t) => `#${t}`).join(' '))}" placeholder="#Sonepar #Elektrobranche">
      <div class="row" style="margin-top:8px"><span class="grow"></span><button class="btn primary" id="saveDefTags" type="button">Speichern</button></div>
    </section>

    <div class="section-title">Firmen &amp; Personen zum Verknüpfen (@-Erwähnung)</div>
    <p class="muted small">Für eine Verknüpfung braucht LinkedIn die interne ID. So findest du sie am PC: Seite im Browser öffnen → Rechtsklick „Seitenquelltext anzeigen“ → Strg+F.<br>
      🏢 Firma: nach <code>urn:li:fsd_company:</code> suchen, die Zahl dahinter eintragen.<br>
      👤 Person: nach <code>urn:li:fsd_profile:</code> suchen, die Zeichenfolge dahinter (beginnt meist mit <code>ACoAA</code>) eintragen. <b>Experimentell:</b> Lehnt LinkedIn die Personen-Verknüpfung ab, wird der Name automatisch als normaler Text gepostet.<br>
      Ohne ID erscheint der Name als normaler Text.</p>
    ${state.partners.map((p) => partnerCard(p)).join('')}
    ${partnerCard({ id: 'new', name: '', org_id: '', page_url: '', kind: 'org' })}
    <div style="height:24px"></div>`;
  $('#saveDefTags').onclick = (e) => busy(e.currentTarget, async () => {
    const tags = $('#defTags').value.split(/[\s,]+/).map(cleanTag).filter(Boolean);
    try {
      await api('default_hashtags', { method: 'PUT', body: { tags } });
      state.me.defaultHashtags = tags;
      localStorage.setItem('me', JSON.stringify(state.me));
      toast('Gespeichert');
    } catch (err) { toast(err.message, true); }
  });
  view.querySelectorAll('[data-partner]').forEach((card) => {
    const pid = card.dataset.partner;
    card.querySelector('.saveP').onclick = (e) => busy(e.currentTarget, async () => {
      try {
        await api('partner', { method: 'PUT', id: pid, body: {
          name: card.querySelector('.pname').value, org_id: card.querySelector('.porg').value, page_url: card.querySelector('.purl').value,
          kind: card.querySelector('.pkind').value,
        } });
        toast('Gespeichert');
        renderTemplates();
      } catch (err) { toast(err.message, true); }
    });
    card.querySelector('.delP')?.addEventListener('click', async () => {
      if (!confirm('Firma entfernen?')) return;
      try { await api('partner', { method: 'DELETE', id: pid }); renderTemplates(); } catch (err) { toast(err.message, true); }
    });
  });

  view.querySelectorAll('[data-tpl]').forEach((card) => {
    const tid = card.dataset.tpl;
    card.querySelector('.addEx').onclick = () => {
      card.querySelector('.examples').insertAdjacentHTML('beforeend', exampleField(''));
    };
    card.querySelector('.saveTpl').onclick = (e) => busy(e.currentTarget, async () => {
      try {
        await api('template', {
          method: 'PUT', id: tid,
          body: {
            name: card.querySelector('.tname').value,
            style_prompt: card.querySelector('.tstyle').value,
            examples: [...card.querySelectorAll('.tex')].map((x) => x.value),
            provisional: card.querySelector('.tprov').checked,
          },
        });
        toast('Vorlage gespeichert');
        renderTemplates();
      } catch (err) { toast(err.message, true); }
    });
    card.querySelector('.delTpl').onclick = async () => {
      if (!confirm('Vorlage löschen?')) return;
      try { await api('template', { method: 'DELETE', id: tid }); renderTemplates(); } catch (err) { toast(err.message, true); }
    };
  });
  $('#newTpl').onclick = async () => {
    try {
      await api('template', { method: 'PUT', id: 'new', body: { name: 'Neue Vorlage', style_prompt: '', examples: [] } });
      renderTemplates();
    } catch (err) { toast(err.message, true); }
  };
}

function partnerCard(p) {
  return `
    <section class="card" data-partner="${p.id}">
      <div class="row">
        <select class="pkind" style="flex:0 0 120px">
          <option value="org" ${p.kind !== 'person' ? 'selected' : ''}>🏢 Firma</option>
          <option value="person" ${p.kind === 'person' ? 'selected' : ''}>👤 Person</option>
        </select>
        <input type="text" class="pname grow" placeholder="Name wie auf LinkedIn" value="${esc(p.name)}" style="flex:2 1 180px">
      </div>
      <div class="row" style="margin-top:8px">
        <input type="text" class="porg" placeholder="LinkedIn-ID" value="${esc(p.org_id)}" autocapitalize="off" style="flex:1 1 120px">
        <input type="text" class="purl" placeholder="Link zur Seite/zum Profil (optional)" value="${esc(p.page_url)}" style="flex:2 1 180px">
      </div>
      <div class="row" style="margin-top:8px">
        ${p.id === 'new' ? '<span class="grow"></span><button class="btn saveP" type="button">＋ Hinzufügen</button>' : `
          <span class="small ${p.org_id ? 'ok' : 'muted'}">${p.org_id ? '✓ wird verlinkt' : '⚠︎ ohne ID nur Text'}</span>
          ${p.page_url ? `<a class="small" href="${esc(p.page_url)}" target="_blank" rel="noopener">Seite öffnen</a>` : ''}
          <span class="grow"></span>
          <button class="btn danger delP" type="button">Entfernen</button>
          <button class="btn primary saveP" type="button">Speichern</button>`}
      </div>
    </section>`;
}

const exampleField = (v) => `<textarea class="tex" rows="4" placeholder="Beispielpost hier einfügen …">${esc(v)}</textarea>`;

function tplCard(t) {
  return `
    <section class="card" data-tpl="${t.id}">
      <label>Name</label>
      <input type="text" class="tname" value="${esc(t.name)}">
      <label>Stilvorgabe</label>
      <textarea class="tstyle" rows="4">${esc(t.style_prompt)}</textarea>
      <label>Beispielposts (${t.examples.length})</label>
      <div class="examples" style="display:grid;gap:8px">${t.examples.map(exampleField).join('')}</div>
      <button class="btn ghost addEx" type="button" style="margin-top:8px">＋ Beispiel hinzufügen</button>
      <label class="check"><input type="checkbox" class="tprov" ${t.provisional ? 'checked' : ''}> vorläufig (noch ohne echte Beispiele)</label>
      <div class="row" style="margin-top:12px">
        <button class="btn danger delTpl" type="button">Löschen</button>
        <span class="grow"></span>
        <button class="btn primary saveTpl" type="button">Speichern</button>
      </div>
    </section>`;
}

store.onSynced(() => { if (!location.hash || location.hash === '#/') renderList(); });
init();
