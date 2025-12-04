// assets/js/switcher.js
(function () {
  'use strict';

  // Utilidades
  const qs  = (sel, ctx=document) => ctx.querySelector(sel);
  const qsa = (sel, ctx=document) => Array.prototype.slice.call(ctx.querySelectorAll(sel));

  // Config global (inyectada desde PHP en CO360_SWITCHER)
  const GLOBAL_CFG = (typeof window !== 'undefined' && window.CO360_SWITCHER) ? window.CO360_SWITCHER : null;

  // ID único
  function uid() {
    return 'co360sw_' + Math.random().toString(36).slice(2, 9);
  }

  // Crea el iframe PDF ocupando todo el ancho
  function createPdfIframe(url) {
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
    const vw = Math.min(window.innerWidth || 0, document.documentElement.clientWidth || 0);

    // Ajusta a tu gusto:
    const ZOOM_MOBILE = 150;          // % para móviles
    const ZOOM_TABLET = 125;          // % para tablets pequeñas
    const ZOOM_DESK   = 'page-width'; // escritorio “ajustar al ancho”

    // Helper: añade/mergea parámetros hash
    function withHash(u, paramsObj) {
      const parts = String(u).split('#');
      const base  = parts[0];
      const qs    = new URLSearchParams(parts[1] || '');
      Object.keys(paramsObj || {}).forEach(k => {
        const v = paramsObj[k];
        if (v == null || v === '') qs.delete(k); else qs.set(k, String(v));
      });
      const hash = qs.toString();
      return hash ? (base + '#' + hash) : base;
    }

    // Escoge zoom según ancho
    let zoomVal = ZOOM_DESK;
    if (vw <= 640)      zoomVal = ZOOM_MOBILE;
    else if (vw <= 900) zoomVal = ZOOM_TABLET;

    // Safari iOS ignora #zoom en PDFs embebidos -> usar PDF.js si está disponible
    if (isIOS && window.CO360_SWITCHER && window.CO360_SWITCHER.pdfjs_viewer) {
      const pdfjs = window.CO360_SWITCHER.pdfjs_viewer.replace(/[\?#]$/, '');
      const finalUrl = pdfjs + '?file=' + encodeURIComponent(url) + '#zoom=' + encodeURIComponent(zoomVal) + '&pagemode=none';
      const ifr = document.createElement('iframe');
      ifr.src = finalUrl;
      ifr.title = 'Visor de documento';
      ifr.loading = 'lazy';
      ifr.referrerPolicy = 'no-referrer';
      ifr.style.border = '0';
      ifr.style.width  = '100%';
      ifr.style.height = (vw <= 768 ? '75vh' : '85vh');
      return ifr;
    }

    // Resto de navegadores: viewer nativo con hash
    const finalUrl = withHash(url, { view: 'FitH', zoom: zoomVal, pagemode: 'none' });
    const ifr = document.createElement('iframe');
    ifr.src = finalUrl;
    ifr.title = 'Visor de documento';
    ifr.loading = 'lazy';
    ifr.referrerPolicy = 'no-referrer';
    ifr.style.border = '0';
    ifr.style.width  = '100%';
    ifr.style.height = (vw <= 768 ? '75vh' : '85vh');
    return ifr;
  }

  // Track (sendBeacon -> fetch keepalive)
  function trackEvent(cfg, payload) {
    const ajaxUrl = (cfg && cfg.ajax_url) ? cfg.ajax_url : (window.ajaxurl || '/wp-admin/admin-ajax.php');
    const nonce   = (cfg && cfg.nonce)    ? cfg.nonce    : '';

    // Try sendBeacon
    try {
      if (navigator.sendBeacon) {
        const be = new URLSearchParams();
        be.set('action', 'co360_switcher_track');
        be.set('nonce',  nonce);
        be.set('type',   payload.type || '');
        be.set('url',    payload.url || '');
        be.set('index',  String(payload.index != null ? payload.index : ''));
        be.set('switcher_id', payload.switcher_id || '');
        const blob = new Blob([be.toString()], { type: 'application/x-www-form-urlencoded;charset=UTF-8' });
        navigator.sendBeacon(ajaxUrl, blob);
        return;
      }
    } catch (_) {}

    // Fallback fetch
    try {
      const fd = new FormData();
      fd.append('action', 'co360_switcher_track');
      fd.append('nonce',  nonce);
      fd.append('type',   payload.type || '');
      fd.append('url',    payload.url || '');
      fd.append('index',  String(payload.index != null ? payload.index : ''));
      fd.append('switcher_id', payload.switcher_id || '');
      fetch(ajaxUrl, { method:'POST', body: fd, credentials:'same-origin', keepalive:true }).catch(()=>{});
    } catch (_) {}
  }

  // Mostrar/ocultar botón móvil "Abrir PDF"
  function setMobileOpenButton(root, pdfOriginalUrl) {
    const mobWrap = qs('.co360-mobile-open', root);
    const mobBtn  = mobWrap ? qs('.co360-open-mobile-btn', mobWrap) : null;
    const isMobile = window.matchMedia('(max-width: 768px)').matches;

    if (!mobWrap || !mobBtn) return;

    if (isMobile && pdfOriginalUrl) {
      mobBtn.setAttribute('href', pdfOriginalUrl);
      mobBtn.setAttribute('target', '_blank');
      mobBtn.setAttribute('rel', 'noopener');
      mobWrap.hidden = false;
    } else {
      mobWrap.hidden = true;
      mobBtn.removeAttribute('href');
    }
  }

  // Init de un switcher
  function initSwitcher(root) {
    if (!root || root.__co360_inited) return;
    root.__co360_inited = true;

    // Fallbacks por si la global no existe
    const localCfg = {
      ajax_url: (GLOBAL_CFG && GLOBAL_CFG.ajax_url) ? GLOBAL_CFG.ajax_url : (window.ajaxurl || '/wp-admin/admin-ajax.php'),
      nonce   : (GLOBAL_CFG && GLOBAL_CFG.nonce)    ? GLOBAL_CFG.nonce    : (root.getAttribute('data-co360-nonce') || '')
    };

    // ID único para correlación
    const switcherId = root.getAttribute('id') || uid();
    root.setAttribute('id', switcherId);

    const btns   = qsa('.co360-switch-btn', root);
    const viewer = qs('.co360-viewer', root);
    const holder = qs('.co360-holder', root) || viewer;
    const empty  = qs('.co360-empty',  root);
    const ph     = qs('.co360-placeholder', root);

    // Refuerzos anti-theme para ancho completo
    if (viewer) {
      viewer.style.width    = '100%';
      viewer.style.maxWidth = 'none';
      viewer.style.margin   = '0';
      viewer.style.padding  = viewer.style.padding || '0';
    }
    if (holder) {
      holder.style.width    = '100%';
      holder.style.maxWidth = 'none';
      holder.style.margin   = '0';
    }

    function setActiveBtn(btn) {
      btns.forEach(b => b.classList.remove('is-active', 'active'));
      if (btn) btn.classList.add('is-active', 'active');
    }

    function showPlaceholder() {
      if (holder) { holder.hidden = true; holder.innerHTML = ''; }
      if (ph)     { ph.style.display = ''; }
      if (empty)  { empty.textContent = ''; empty.style.display = 'none'; }
      setMobileOpenButton(root, null); // oculta botón móvil
    }

    function showError(msg) {
      if (holder) { holder.hidden = true; holder.innerHTML = ''; }
      if (ph)     { ph.style.display = 'none'; }
      if (empty)  { empty.textContent = msg || 'No se pudo cargar el documento.'; empty.style.display = ''; }
      setMobileOpenButton(root, null);
    }

function showPdf(url, originalPdf) {
      if (!holder) return;

      const isMobile = window.matchMedia('(max-width: 768px)').matches;

      // 📱 En móvil: NO embebemos visor, sólo botón "Abrir a pantalla completa"
      if (isMobile) {
        // ocultamos contenedor de iframe
        holder.innerHTML = '';
        holder.hidden = true;

        // ocultamos placeholder y mensajes
        if (ph)    ph.style.display = 'none';
        if (empty) { empty.textContent = ''; empty.style.display = 'none'; }

        // botón móvil apunta al PDF/HTML original
        setMobileOpenButton(root, originalPdf || url || null);
        return;
      }

      // 🖥️ Escritorio: comportamiento normal con visor embebido
      try {
        const ifr = createPdfIframe(url);
        holder.innerHTML = '';
        holder.appendChild(ifr);
        holder.hidden = false;

        if (ph)    ph.style.display = 'none';
        if (empty) { empty.textContent = ''; empty.style.display = 'none'; }

        // dejamos preparado el botón móvil por si el usuario rota la pantalla / cambia tamaño
        setMobileOpenButton(root, originalPdf || null);
      } catch (_) {
        showError('No se pudo inicializar el visor.');
      }
    }



    // Click handlers
    btns.forEach(btn => {
      const type  = btn.getAttribute('data-co360-type');
      const index = parseInt(btn.getAttribute('data-index') || '0', 10);

      if (type === 'ppt') {
        // Es <a>: NO prevenir -> iOS descarga
        btn.addEventListener('click', function () {
          const href = btn.getAttribute('href') || '';
          setActiveBtn(btn);
          setMobileOpenButton(root, null); // no mostrar en PPT
          trackEvent(localCfg, { type:'ppt', url: href, index, switcher_id: switcherId });
        }, { passive: true });

      } else {
        // Slidekit / Highlights
        btn.addEventListener('click', function (ev) {
          ev.preventDefault();
          const viewerUrl = btn.getAttribute('data-url') || '';
          const pdfOrig   = btn.getAttribute('data-pdf') || ''; // URL original del PDF
          if (!viewerUrl) { showError('URL no válida.'); return; }
          setActiveBtn(btn);
          showPdf(viewerUrl, pdfOrig);
          trackEvent(localCfg, { type, url: pdfOrig || viewerUrl, index, switcher_id: switcherId });
        });
      }
    });

    // Si cambia el tamaño de pantalla, refrescamos visibilidad del botón móvil
    window.addEventListener('resize', function () {
      const active = qs('.co360-switch-btn.is-active, .co360-switch-btn.active', root);
      if (active && active.getAttribute('data-co360-type') !== 'ppt') {
        // re-evalúa según ancho actual
        setMobileOpenButton(root, active.getAttribute('data-pdf') || '');
      } else {
        setMobileOpenButton(root, null);
      }
    }, { passive: true });

    // Estado inicial
    showPlaceholder();
  }

  // Auto init
  function boot() {
    qsa('.co360-switch').forEach(initSwitcher);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
