<?php
if (!defined('ABSPATH')) exit;

class CO360_Suite_Switcher {

    const VER = CO360_SUITE_VER;

    public function __construct() {

        // Shortcode
        add_shortcode('co360_switcher', [$this, 'shortcode']);

        // Scripts / estilos
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX tracking
        add_action('wp_ajax_co360_switcher_track',        [$this, 'ajax_track']);
        add_action('wp_ajax_nopriv_co360_switcher_track', [$this, 'ajax_track']);
    }

    /**
     * Registrar scripts + variable global
     */
    public function enqueue_assets() {

        // JS principal del switcher
        wp_enqueue_script(
            'co360-switcher',
            CO360_SUITE_URL . 'assets/js/switcher.js',
            [],
            self::VER,
            true
        );

        // Variable global segura (ajax + nonce)
        wp_localize_script('co360-switcher', 'CO360_SWITCHER', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('co360_switcher_nonce'),
        ]);

        // CSS si existe
        $css = CO360_SUITE_PATH . 'assets/css/switcher.css';
        if (file_exists($css)) {
            wp_enqueue_style(
                'co360-switcher-css',
                CO360_SUITE_URL . 'assets/css/switcher.css',
                [],
                self::VER
            );
        }
    }

    /**
     * AJAX tracking (envío “fire-and-forget” desde JS)
     */
    public function ajax_track() {
        check_ajax_referer('co360_switcher_nonce', 'nonce');

        $type    = sanitize_key($_POST['type'] ?? '');
        $url     = esc_url_raw($_POST['url'] ?? '');
        $index   = intval($_POST['index'] ?? 0);
        $swid    = sanitize_text_field($_POST['switcher_id'] ?? '');

        // Opcional: resolver post_id si hay referer (lo recoge la clase analytics)
        do_action('co360_switcher_track_view', [
            'type'        => $type,
            'url'         => $url,
            'index'       => $index,
            'switcher_id' => $swid,
            'referer'     => wp_get_referer(),
        ]);

        wp_send_json_success(['ok' => true]);
    }

    /**
     * SHORTCODE
     *
     * [co360_switcher
     *   labels="Slidekit|Highlights|Descargar PPT"
     *   align="center"
     *   active="-1"
     *   pdf_viewer="auto|native|pdfjs"
     *   url1="..." type1="slidekit"
     *   url2="..." type2="highlights"
     *   url3="..." type3="ppt"]
     */
    public function shortcode($atts = []) {

        $a = shortcode_atts([
            'labels'     => '',
            'align'      => 'center',
            'active'     => -1,
            'pdf_viewer' => 'auto', // auto | native | pdfjs

            // Botones dinámicos (máx 3)
            'url1' => '',
            'type1'=> '',
            'url2' => '',
            'type2'=> '',
            'url3' => '',
            'type3'=> '',
        ], $atts);

        // Normalizar modo del visor PDF
        $viewer_mode = in_array($a['pdf_viewer'], ['auto','native','pdfjs'], true)
            ? $a['pdf_viewer']
            : 'auto';

        // Labels
        $labels = array_map('trim', explode('|', (string)$a['labels']));

        // ---------- Preparar viewer PDF.js si existe ----------
        $viewer_fs  = CO360_SUITE_PATH . 'assets/pdfjs/web/viewer.html';
        $viewer_url = CO360_SUITE_URL  . 'assets/pdfjs/web/viewer.html';
        $has_viewer = file_exists($viewer_fs);

        // Helper: construir URL del viewer con ?file=<PDF> y desactivar "Abrir"
        $to_viewer = function($pdf_url) use ($viewer_url) {
            $base = add_query_arg('file', rawurlencode($pdf_url), $viewer_url);
            $hash = '#disableOpenFile=true&zoom=page-width&pagemode=none';
            return $base . $hash;
        };

        // ---------- Construir items ----------
        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $url  = isset($a["url{$i}"])  ? trim((string)$a["url{$i}"])  : '';
            $type = isset($a["type{$i}"]) ? sanitize_key($a["type{$i}"]) : '';

            if (!$url || !$type) continue;

            $is_pdf_kind = in_array($type, ['slidekit','highlights'], true);

            // Detectar si la URL es HTML (para HTML5 incrustado)
            $is_html = false;
            if ($is_pdf_kind) {
                $is_html = (bool) preg_match('/\.html?(?:$|\?)/i', $url);
            }

            // URL final que se cargará en el iframe
            $final_url = $url;

            // Solo aplicamos lógica de visor para PDFs reales (no HTML)
            if ($is_pdf_kind && ! $is_html) {

                if ($viewer_mode === 'native') {
                    // Visor nativo del navegador
                    $final_url = $url;

                } elseif ($viewer_mode === 'pdfjs') {
                    // Forzar PDF.js si existe; si no, nativo
                    $final_url = $has_viewer ? $to_viewer($url) : $url;

                } else { // auto
                    // Si hay PDF.js, úsalo; si no, visor nativo
                    $final_url = $has_viewer ? $to_viewer($url) : $url;
                }
            }

            // Subformato para el texto pequeño del botón
            $format = '';
            if ($is_pdf_kind) {
                $format = $is_html ? 'HTML5' : 'PDF';
            } elseif ($type === 'ppt') {
                $format = 'PPTX';
            } else {
                $path   = parse_url($url, PHP_URL_PATH) ?: '';
                $ext    = strtoupper(pathinfo($path, PATHINFO_EXTENSION));
                $format = $ext ?: '';
            }

            $items[] = [
                'index'  => $i - 1,
                'label'  => $labels[$i-1] ?? ucfirst($type),
                'url'    => $final_url,  // lo que cargará el iframe
                'type'   => $type,
                'orig'   => $url,        // URL original (para botón móvil / abrir directo)
                'format' => $format,
                'is_html'=> $is_html,
            ];
        }

        if (empty($items)) {
            return '<div>No hay contenidos configurados.</div>';
        }

        // Nonce para fallback en JS
        $nonce = wp_create_nonce('co360_switcher_nonce');

        // Alineación (se deja en el contenedor de botones)
        $align = in_array($a['align'], ['left','center','right'], true) ? $a['align'] : 'center';

        ob_start();
        ?>
<div class="co360-switch" data-co360-nonce="<?php echo esc_attr($nonce); ?>">

  <div class="co360-switch-buttons" style="text-align:<?php echo esc_attr($align); ?>">

    <?php foreach ($items as $it): ?>
      <?php if ($it['type'] === 'ppt'): ?>
        <!-- PPT → usar <a>, no button (mejor en iOS para descargar) -->
        <a href="<?php echo esc_url($it['url']); ?>"
           class="co360-switch-btn"
           data-co360-type="ppt"
           data-index="<?php echo (int)$it['index']; ?>"
           target="_blank" rel="noopener">
          <div class="co360-title"><?php echo esc_html($it['label']); ?></div>
          <div class="co360-sub"><?php echo esc_html($it['format'] ?: 'PPTX'); ?></div>
        </a>
      <?php else: ?>
        <!-- Slidekit / Highlights → botón que carga iframe (PDF o HTML) -->
        <button type="button"
                class="co360-switch-btn"
                data-co360-type="<?php echo esc_attr($it['type']); ?>"
                data-index="<?php echo (int)$it['index']; ?>"
                data-url="<?php echo esc_url($it['url']); ?>"
                data-pdf="<?php echo esc_url($it['orig']); ?>">
          <div class="co360-title"><?php echo esc_html($it['label']); ?></div>
          <div class="co360-sub"><?php echo esc_html($it['format'] ?: 'PDF'); ?></div>
        </button>
      <?php endif; ?>
    <?php endforeach; ?>

  </div>

  <div class="co360-viewer">
      <div class="co360-placeholder">
        <div class="co360-placeholder-inner">
          <strong>Selecciona una opción</strong><br>
          El visor se cargará al hacer clic.
        </div>
      </div>

      <div class="co360-holder" hidden></div>

      <!-- Botón solo móvil para abrir el recurso original en pestaña nueva (PDF o HTML) -->
      <div class="co360-mobile-open" hidden>
        <a class="co360-open-mobile-btn button" target="_blank" rel="noopener">
          Abrir a pantalla completa
        </a>
      </div>

      <!-- Para mensajes de error / estados especiales -->
      <div class="co360-empty" hidden></div>
  </div>

</div>
        <?php
        return ob_get_clean();
    }
}
