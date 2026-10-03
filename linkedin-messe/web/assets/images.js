// Bildaufbereitung im Browser: verkleinern, zuschneiden, drehen.
const MAX_EDGE = 2048;
const QUALITY = 0.85;

const canvasToBlob = (canvas) => new Promise((resolve, reject) =>
  canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('Bild konnte nicht erzeugt werden.'))), 'image/jpeg', QUALITY));

/** Foto aus Kamera/Galerie: EXIF-Drehung anwenden, auf max. 2048 px verkleinern, als JPEG. */
export async function normalize(file) {
  let source;
  try {
    source = await createImageBitmap(file, { imageOrientation: 'from-image' });
  } catch {
    source = await loadImage(URL.createObjectURL(file));
  }
  const scale = Math.min(1, MAX_EDGE / Math.max(source.width, source.height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(source.width * scale);
  canvas.height = Math.round(source.height * scale);
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
  source.close?.();
  return { blob: await canvasToBlob(canvas), width: canvas.width, height: canvas.height };
}

function loadImage(src) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error('Bild konnte nicht gelesen werden.'));
    img.src = src;
  });
}

/** Öffnet den Zuschneide-Dialog. Liefert {blob,width,height} oder null bei Abbruch. */
export function cropImage(src) {
  const dialog = document.getElementById('cropDialog');
  const img = document.getElementById('cropImg');
  return new Promise((resolve) => {
    let cropper;
    let ready = false;
    const aspects = dialog.querySelector('#aspects');
    const cleanup = (result) => {
      dialog.removeEventListener('click', onClick);
      dialog.removeEventListener('cancel', onCancel);
      cropper?.destroy();
      dialog.close();
      resolve(result);
    };
    const onCancel = (e) => { e.preventDefault(); cleanup(null); };
    const onClick = async (e) => {
      const b = e.target.closest('button');
      if (!b) return;
      if (b.id === 'cropCancel') return cleanup(null);
      if (!ready) return;
      if (b.dataset.aspect) {
        aspects.querySelectorAll('button').forEach((x) => x.classList.toggle('on', x === b));
        cropper.setAspectRatio(Number(b.dataset.aspect));
      } else if (b.dataset.rotate) {
        cropper.rotate(Number(b.dataset.rotate));
        fitAfterRotate(cropper);
      } else if (b.id === 'cropReset') {
        cropper.reset();
      } else if (b.id === 'cropOk') {
        b.classList.add('busy');
        try {
          // Nach Layout-Sprüngen (Adressleiste, Drehen des Handys) kann der Rahmen leer sein → ganzes Bild nehmen.
          const d = cropper.getData();
          if (d.width < 1 || d.height < 1) {
            cropper.setAspectRatio(NaN);
            cropper.setCropBoxData(cropper.getCanvasData());
          }
          const canvas = cropper.getCroppedCanvas({
            maxWidth: MAX_EDGE, maxHeight: MAX_EDGE, fillColor: '#fff',
            imageSmoothingEnabled: true, imageSmoothingQuality: 'high',
          });
          const blob = await canvasToBlob(canvas);
          cleanup({ blob, width: canvas.width, height: canvas.height });
        } catch (err) {
          alert(`Zuschneiden fehlgeschlagen: ${err.message}`);
        } finally {
          b.classList.remove('busy');
        }
      }
    };
    aspects.querySelectorAll('button').forEach((x) => x.classList.toggle('on', x.dataset.aspect === 'NaN'));
    dialog.addEventListener('click', onClick);
    dialog.addEventListener('cancel', onCancel);
    img.onload = () => {
      cropper = new window.Cropper(img, {
        viewMode: 1, autoCropArea: 1, background: false, responsive: false,
        checkOrientation: false, dragMode: 'move', toggleDragModeOnDblclick: false,
        ready() { ready = true; },
      });
    };
    img.src = src;
    dialog.showModal();
  });
}

// Nach dem Drehen das Bild wieder in den sichtbaren Bereich einpassen und den Rahmen maximieren.
function fitAfterRotate(cropper) {
  const c = cropper.getContainerData();
  const img = cropper.getImageData();
  const rotated = Math.abs(img.rotate % 180) === 90;
  const w = rotated ? img.naturalHeight : img.naturalWidth;
  const h = rotated ? img.naturalWidth : img.naturalHeight;
  const ratio = Math.min(c.width / w, c.height / h);
  cropper.zoomTo(ratio);
  cropper.moveTo((c.width - img.naturalWidth * ratio) / 2, (c.height - img.naturalHeight * ratio) / 2);
  const canvas = cropper.getCanvasData();
  cropper.setCropBoxData({ left: canvas.left, top: canvas.top, width: canvas.width, height: canvas.height });
}
