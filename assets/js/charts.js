(function(){

  // === Export handler (CSV/XLS vía REST) ===
  function attachExportHandler(){
    // Necesitamos REST y nonce
    if (!window.CO360_EXPORT || !window.CO360_EXPORT.rest || !window.CO360_EXPORT.nonce) return;

    document.addEventListener('click', function(e){
      const btn = e.target.closest('[data-co360-export]');
      if (!btn) return;

      e.preventDefault();

      const scope  = (btn.getAttribute('data-scope')  || 'global').toLowerCase(); // 'global' | 'me' | 'user'
      const format = (btn.getAttribute('data-format') || 'csv').toLowerCase();    // 'csv' | 'xls'

      // Fechas: leemos del propio botón si las trae, o buscamos inputs próximos
      let date_from = btn.getAttribute('data-from') || '';
      let date_to   = btn.getAttribute('data-to')   || '';

      if (!date_from || !date_to) {
        const wrap = btn.closest('.co360-ga, .co360-ua') || document;
        const f = wrap.querySelector('#co360From');
        const t = wrap.querySelector('#co360To');
        if (f) date_from = f.value || '';
        if (t) date_to   = t.value || '';
      }

      const body = {
        scope: scope,
        format: format,
        date_from: date_from,
        date_to: date_to
      };

      if (scope === 'user') {
        const uid = parseInt(btn.getAttribute('data-user') || '0', 10);
        if (!uid) {
          alert('Selecciona un usuario antes de exportar.');
          return;
        }
        body.user_id = uid;
      }

      fetch(window.CO360_EXPORT.rest, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.CO360_EXPORT.nonce },
        body: JSON.stringify(body)
      })
      .then(async r => {
        const blob = await r.blob();
        const disp = r.headers.get('Content-Disposition') || '';
        let filename = 'co360_export.' + (format === 'xls' ? 'xls' : 'csv');
        const m = /filename="?([^"]+)"?/i.exec(disp);
        if (m && m[1]) filename = m[1];

        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(()=>URL.revokeObjectURL(url), 1500);
      })
      .catch(() => alert('No se pudo exportar. Inténtalo de nuevo.'));
    });
  }

  // Si no hay Chart o aún no se ha definido CO360_CHART_DATA,
  // al menos montamos el export y salimos.
  if (!window.Chart || !window.CO360_CHART_DATA) {
    attachExportHandler();
    return;
  }

  const renderChart = (id, data) => {
    const canvas = document.getElementById(id);
    if (!canvas) return;

    // Si no hay datos (labels vacíos), destruimos el gráfico existente y limpiamos el canvas
    if (!data || !data.labels || !data.labels.length) {
      if (canvas.__co360Chart && typeof canvas.__co360Chart.destroy === 'function') {
        canvas.__co360Chart.destroy();
        canvas.__co360Chart = null;
      }
      const ctx = canvas.getContext && canvas.getContext('2d');
      if (ctx) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
      }
      return;
    }

    // Destruye si ya había un gráfico en ese canvas (evita duplicados)
    if (canvas.__co360Chart && typeof canvas.__co360Chart.destroy === 'function') {
      canvas.__co360Chart.destroy();
    }

    canvas.__co360Chart = new Chart(canvas.getContext('2d'), {
      type: 'bar',
      data,
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true } }
      }
    });
  };

  // Exponemos el render para que el shortcode pueda repintar al filtrar
  window.CO360RenderChart = renderChart;

  // Render inicial si hay datos precargados
  if (window.CO360_CHART_DATA.user)   renderChart('co360UserBar',   window.CO360_CHART_DATA.user);
  if (window.CO360_CHART_DATA.global) renderChart('co360GlobalBar', window.CO360_CHART_DATA.global);

  // Montamos export
  attachExportHandler();

})();
