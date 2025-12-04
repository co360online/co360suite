// assets/pdfjs/web/viewer.js
(function () {
  'use strict';

  // ---- Helpers ----
  const $ = (id) => document.getElementById(id);
  const viewerEl   = $('viewer');
  const canvasEl   = $('pdfCanvas');
  const ctx        = canvasEl.getContext('2d');

  const btnOpen    = $('btnOpen');
  const btnZoomIn  = $('btnZoomIn');
  const btnZoomOut = $('btnZoomOut');
  const btnPrev    = $('btnPrev');
  const btnNext    = $('btnNext');
  const zoomInfo   = $('zoomInfo');
  const pageInfo   = $('pageInfo');
  const vTitle     = $('vTitle');

  const urlParams  = new URLSearchParams(location.search);
  const hashParams = new URLSearchParams((location.hash || '').replace(/^#/, ''));

  // Recoge parámetros
  const fileParam  = urlParams.get('file') || '';
  const fileUrl    = fileParam ? decodeURIComponent(fileParam) : '';
  const disableOpenFile =
    hashParams.get('disableOpenFile') === 'true' || urlParams.get('disableOpenFile') === '1';

  // Zoom inicial: "page-width" o número (por ejemplo 125 = 125%)
  let initialZoom  = (hashParams.get('zoom') || '').toLowerCase();
  let pdfDoc = null;
  let pageNum = 1;
  let scale = 1; // se ajustará tras cargar la primera página
  let rendering = false;
  let pendingRender = false;

  // ---- UI estado ----
  if (disableOpenFile && btnOpen) {
    btnOpen.style.display = 'none';
  }

  if (btnOpen) {
    btnOpen.addEventListener('click', function () {
      if (fileUrl) {
        // Abre el PDF actual en nueva pestaña
        window.open(fileUrl, '_blank', 'noopener');
      } else {
        // (Opcional) Si no hay ?file= podrías abrir un selector local:
        // alert('No hay un PDF cargado');
      }
    });
  }

  function setTitleFromURL(u) {
    try {
      const urlObj = new URL(u, location.href);
      let name = (urlObj.pathname.split('/').pop() || '').trim();
      if (!name) name = 'Documento';
      vTitle.textContent = decodeURIComponent(name);
    } catch {
      vTitle.textContent = 'Documento';
    }
  }

  // ---- Render ----
  function updateZoomLabel() {
    zoomInfo.textContent = Math.round(scale * 100) + '%';
  }
  function updatePageLabel() {
    if (!pdfDoc) { pageInfo.textContent = '—'; return; }
    pageInfo.textContent = pageNum + ' / ' + pdfDoc.numPages;
  }

  function fitToWidth(page) {
    // Ajusta 'scale' para que la página encaje al ancho del contenedor
    const containerWidth = viewerEl.clientWidth || window.innerWidth;
    const unscaled = page.getViewport({ scale: 1 });
    const ratio = containerWidth / unscaled.width;

    // Ajuste por pixel ratio
    const dpr = window.devicePixelRatio || 1;
    scale = ratio; // dejamos el dpr para el canvas backing store

    // Aplicamos límites razonables
    if (scale < 0.25) scale = 0.25;
    if (scale > 5)    scale = 5;
  }

  function renderPage() {
    if (rendering) { pendingRender = true; return; }
    rendering = true;

    pdfDoc.getPage(pageNum).then(function (page) {
      // Si el zoom inicial es "page-width" o aún no se ha fijado, ajústalo
      if (initialZoom === 'page-width' || !zoomInfo.textContent) {
        fitToWidth(page);
      } else if (!isNaN(parseFloat(initialZoom))) {
        scale = Math.max(0.1, Math.min(10, parseFloat(initialZoom) / 100));
      }

      const viewport = page.getViewport({ scale });
      const dpr = window.devicePixelRatio || 1;

      // Ajusta tamaño del canvas (backing store en alta resolución)
      canvasEl.width  = Math.floor(viewport.width  * dpr);
      canvasEl.height = Math.floor(viewport.height * dpr);
      canvasEl.style.width  = Math.floor(viewport.width) + 'px';
      canvasEl.style.height = Math.floor(viewport.height) + 'px';

      // Escala el contexto para dpr
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.imageSmoothingEnabled = true;

      const renderContext = {
        canvasContext: ctx,
        viewport,
      };

      const task = page.render(renderContext);
      task.promise.then(function () {
        rendering = false;
        updateZoomLabel();
        updatePageLabel();
        // Una vez renderizada la primera vez, no vuelvas a forzar initialZoom
        initialZoom = ''; 
        if (pendingRender) {
          pendingRender = false;
          renderPage();
        }
      });
    });
  }

  function queueRender() {
    if (rendering) { pendingRender = true; }
    else { renderPage(); }
  }

  function loadDocument(url) {
    if (!window.pdfjsLib) {
      alert('No se pudo cargar PDF.js');
      return;
    }
    setTitleFromURL(url);
    pdfjsLib.getDocument(url).promise.then(function (doc) {
      pdfDoc = doc;
      pageNum = 1;
      renderPage();
    }).catch(function (err) {
      console.error(err);
      alert('No se pudo abrir el PDF.');
    });
  }

  // ---- Controles ----
  if (btnZoomIn) {
    btnZoomIn.addEventListener('click', function () {
      scale = Math.min(scale * 1.1, 10);
      initialZoom = ''; // usuario ha tocado el zoom
      queueRender();
    });
  }
  if (btnZoomOut) {
    btnZoomOut.addEventListener('click', function () {
      scale = Math.max(scale / 1.1, 0.1);
      initialZoom = '';
      queueRender();
    });
  }
  if (btnPrev) {
    btnPrev.addEventListener('click', function () {
      if (!pdfDoc) return;
      if (pageNum <= 1) return;
      pageNum--;
      queueRender();
    });
  }
  if (btnNext) {
    btnNext.addEventListener('click', function () {
      if (!pdfDoc) return;
      if (pageNum >= pdfDoc.numPages) return;
      pageNum++;
      queueRender();
    });
  }

  // Redimensiona: ajusta a ancho si no hay zoom “manual”
  let resizeTimer = null;
  window.addEventListener('resize', function () {
    if (!pdfDoc) return;
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      if (zoomInfo.textContent && initialZoom !== '') {
        // Si el usuario no cambió el zoom manualmente, refit
        initialZoom = 'page-width';
      }
      queueRender();
    }, 120);
  }, { passive: true });

  // ---- Boot ----
  if (fileUrl) {
    // zoom hash: "page-width" o número
    const zoomHash = (hashParams.get('zoom') || '').trim().toLowerCase();
    if (zoomHash) initialZoom = zoomHash;
    else initialZoom = 'page-width';
    loadDocument(fileUrl);
  } else {
    vTitle.textContent = 'Documento';
    // Deja el canvas vacío; podrías mostrar un mensaje si quieres.
  }
})();
