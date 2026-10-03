// Nachbau der LinkedIn-Feed-Karte, damit man vor dem Posten sieht, wie der Beitrag wirkt.
import { esc } from './util.js';

const FOLD_CHARS = 210;
const FOLD_LINES = 3;

export function linkify(text) {
  return esc(text)
    .replace(/@\[([^\]\n]+)\]/g, '<span class="tag">$1</span>')
    .replace(/(https?:\/\/[^\s<]+)/g, '<span class="tag">$1</span>')
    .replace(/(^|\s)#([\p{L}\p{N}_]+)/gu, '$1<span class="tag">#$2</span>');
}

/** Kürzt wie LinkedIn: nach ~3 Zeilen bzw. ~210 Zeichen kommt „…mehr“. */
function fold(text) {
  const lines = text.split('\n');
  let cut = text.length;
  if (lines.length > FOLD_LINES) cut = lines.slice(0, FOLD_LINES).join('\n').length;
  cut = Math.min(cut, FOLD_CHARS);
  if (cut >= text.length) return null;
  const space = text.lastIndexOf(' ', cut);
  let out = text.slice(0, space > cut - 30 ? space : cut);
  const open = out.lastIndexOf('@[');
  if (open > out.lastIndexOf(']')) out = out.slice(0, open); // keine halbe Erwähnung
  return out.trimEnd();
}

function mediaGrid(urls) {
  if (!urls.length) return '';
  const n = Math.min(urls.length, 5);
  const shown = urls.slice(0, n);
  const extra = urls.length - n;
  return `<div class="li-media n${n}">${shown.map((u, i) => `
    <div class="cell"><img src="${esc(u)}" alt="">${i === n - 1 && extra > 0 ? `<div class="more">+${extra}</div>` : ''}</div>`).join('')}
  </div>`;
}

export function renderPreview(el, { text, imageUrls, name, picture, when }) {
  const short = fold(text);
  const initials = (name || '?').replace(/[^\p{L}\s]/gu, '').split(/\s+/).filter(Boolean).map((w) => w[0]).slice(0, 2).join('').toUpperCase() || '?';
  el.innerHTML = `
    <article class="li">
      <div class="li-head">
        ${picture ? `<img class="li-ava" src="${esc(picture)}" alt="">` : `<div class="li-ava">${esc(initials)}</div>`}
        <div><div class="li-name">${esc(name || 'Du')}</div><div class="li-sub">${esc(when)} • 🌐</div></div>
      </div>
      <div class="li-text">${short === null ? linkify(text) : `${linkify(short)}… <button class="li-more" type="button">mehr</button>`}</div>
      ${mediaGrid(imageUrls)}
      <div class="li-bar"><span>👍 Gefällt mir</span><span>💬 Kommentieren</span><span>🔁 Reposten</span><span>➤ Senden</span></div>
    </article>`;
  el.querySelector('.li-more')?.addEventListener('click', () => {
    el.querySelector('.li-text').innerHTML = linkify(text);
  });
}
