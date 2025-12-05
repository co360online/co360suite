<?php
if (!defined('ABSPATH')) exit;

class CO360_Suite_Analytics {
    const TABLE = 'co360_events';
    const REST  = 'co360/v1';
    const CAP_VIEW_USERS = 'co360_view_users';
    const VER = CO360_SUITE_VER;
    const EXCLUDED_OPT = 'co360_excluded_users'; // <-- AÑADIR


    public function __construct(){

        register_activation_hook(dirname(__DIR__).'/co360-suite.php', [$this,'activate']);
        add_action('init',               [$this,'register_assets']);
        add_action('wp_enqueue_scripts', [$this,'enqueue_public']);
        add_action('rest_api_init',      [$this,'register_rest']);
        add_action('admin_menu',         [$this,'admin_menu']);
        add_action('admin_post_co360_export_csv', [$this,'handle_export_csv']);
        add_action('wp_ajax_co360_user_suggest',      [$this,'ajax_user_suggest']);
        add_action('wp_ajax_nopriv_co360_user_suggest', [$this,'ajax_user_suggest']); // si quieres permitir a no logueados, quítalo si no
        
        add_action('admin_menu',         [$this,'admin_tools_menu']); // submenú Herramientas
        add_action('admin_post_co360_wipe_stats',        [$this,'handle_wipe_all_stats']);
        add_action('admin_post_co360_delete_user_stats', [$this,'handle_delete_user_stats']);
        add_action('admin_post_co360_export_selected',   [$this,'handle_export_selected']);
        add_action('admin_post_co360_update_exclusions', [$this,'handle_update_exclusions']);
        add_action('admin_post_co360_remove_exclusions', [$this,'handle_remove_exclusions']);
        add_action('admin_post_co360_delete_anon_stats', [$this,'handle_delete_anon_stats']);

        // Shortcodes de analytics + controles
        add_shortcode('co360_user_analytics',   [$this,'sc_user_analytics']);
        add_shortcode('co360_global_analytics', [$this,'sc_global_analytics']);
        add_shortcode('co360_user_insights',    [$this,'sc_user_insights']);
        add_shortcode('co360_user_export',      [$this,'sc_user_export']);
        add_shortcode('co360_pdf_viewer',       [$this,'sc_pdf_viewer']);
        add_shortcode('co360_ppt_download',     [$this,'sc_ppt_download']);

        // Hook que dispara el switcher
        add_action('co360_switcher_track_view', [$this,'hook_switcher_track'], 10, 1);

        // ✅ Añadir capabilities cada vez que cargue WordPress
        add_action('init', [$this, 'ensure_caps']);
    }
    
    public function admin_tools_menu(){
        add_submenu_page(
            'co360-analytics',
            'Herramientas de estadísticas',
            'Herramientas',
            'manage_options',
            'co360-analytics-tools',
            [$this,'admin_tools_page']
        );
    }

    public function admin_tools_page(){
        if ( ! current_user_can('manage_options') ) {
            wp_die('No tienes permisos suficiente.');
        }

        // Filtros por fecha (GET)
        $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        $date_to   = isset($_GET['date_to'])   ? sanitize_text_field(wp_unslash($_GET['date_to']))   : '';

        // Listado de usuarios con contador en rango
        $rows = $this->get_user_counts($date_from, $date_to); // user_id, c

        // Endpoints de acciones
        $action_export = esc_url(admin_url('admin-post.php?action=co360_export_selected'));
        $action_delete = esc_url(admin_url('admin-post.php?action=co360_delete_user_stats'));
        $action_wipe   = esc_url(admin_url('admin-post.php?action=co360_wipe_stats'));

        // Datos para exclusiones
        $excluded   = $this->get_excluded_user_ids();
        global $wpdb; $table = $wpdb->prefix . self::TABLE;
        $counts = [];
        if (!empty($excluded)){
            $ph = implode(',', array_fill(0,count($excluded),'%d'));
            $rowsCnt = $wpdb->get_results( $wpdb->prepare("SELECT user_id, COUNT(*) c FROM {$table} WHERE user_id IN ($ph) GROUP BY user_id", $excluded) );
            foreach((array)$rowsCnt as $r){ $counts[(int)$r->user_id] = (int)$r->c; }
        }

        // REST helper para el buscador de usuarios
        $rest_users_endpoint = rest_url(self::REST.'/users');
        $rest_nonce = wp_create_nonce('wp_rest');
        ?>
        <div class="wrap">
          <h1>Herramientas de estadísticas</h1>

          <!-- Filtro por fechas -->
          <form method="get" style="margin: 12px 0 16px; display:flex; gap:8px; align-items:flex-end;">
            <input type="hidden" name="page" value="co360-analytics-tools">
            <div>
              <label for="co360_from"><strong>Desde</strong></label><br>
              <input type="date" id="co360_from" name="date_from" value="<?php echo esc_attr($date_from); ?>">
            </div>
            <div>
              <label for="co360_to"><strong>Hasta</strong></label><br>
              <input type="date" id="co360_to" name="date_to" value="<?php echo esc_attr($date_to); ?>">
            </div>
            <div>
              <button class="button button-primary">Aplicar</button>
            </div>
          </form>

          <!-- Tabla de usuarios + export/borrado -->
          <form id="co360UsersForm" method="post">
            <?php wp_nonce_field('co360_export_selected', '_wpnonce_export'); ?>
            <?php wp_nonce_field('co360_delete_user_stats', '_wpnonce_delete'); ?>
            <input type="hidden" name="date_from" value="<?php echo esc_attr($date_from); ?>">
            <input type="hidden" name="date_to"   value="<?php echo esc_attr($date_to); ?>">

            <div class="co360-table-wrap">
              <table class="widefat striped">
                <thead>
                  <tr>
                    <th style="width:32px"><input type="checkbox" id="co360CheckAll"></th>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th style="width:120px; text-align:right;">Registros</th>
                  </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                  <tr><td colspan="4">No hay datos para el rango seleccionado.</td></tr>
                <?php else: foreach ($rows as $r):
                    $u = get_user_by('id', (int)$r->user_id);
                    if (!$u) continue;
                    $edit_link = get_edit_user_link($u->ID);
                ?>
                  <tr>
                    <td><input type="checkbox" name="user_ids[]" value="<?php echo (int)$u->ID; ?>"></td>
                    <td><a href="<?php echo esc_url($edit_link); ?>"><?php echo esc_html($u->display_name); ?></a></td>
                    <td><?php echo esc_html($u->user_email); ?></td>
                    <td style="text-align:right;"><?php echo (int)$r->c; ?></td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>

            <div style="display:flex; gap:8px; margin-top:12px; align-items:center; justify-content:flex-end;">
              <button class="button" formaction="<?php echo $action_export; ?>" formmethod="post">Exportar CSV seleccionados</button>
              <button class="button button-secondary" formaction="<?php echo $action_delete; ?>" formmethod="post" onclick="return confirm('¿Borrar estadísticas de los usuarios seleccionados en el rango de fechas? Esta acción no se puede deshacer.')">Borrar seleccionados</button>
            </div>
          </form>

          <hr style="margin:18px 0">

          <!-- Wipe total (con rango opcional) -->
          <form method="post" action="<?php echo $action_wipe; ?>" onsubmit="return confirm('Vas a borrar TODAS las estadísticas dentro del rango indicado.\n¿Continuar?')">
            <?php wp_nonce_field('co360_wipe_stats'); ?>
            <input type="hidden" name="date_from" value="<?php echo esc_attr($date_from); ?>">
            <input type="hidden" name="date_to"   value="<?php echo esc_attr($date_to); ?>">
            <p><strong>Restablecer estadísticas</strong> (aplica el rango de fechas si lo has puesto arriba).</p>
            <button class="button button-link-delete">Borrar TODO el historial en el rango</button>
          </form>
          
          <hr style="margin:18px 0">

          <form method="post"
                action="<?php echo esc_url( admin_url('admin-post.php?action=co360_delete_anon_stats') ); ?>"
                onsubmit="return confirm('Esto borrará los eventos anónimos (user_id NULL) dentro del rango indicado.\n¿Continuar?');">
            <?php wp_nonce_field('co360_delete_anon_stats'); ?>
            <!-- Reutilizamos el rango seleccionado arriba -->
            <input type="hidden" name="date_from" value="<?php echo esc_attr($date_from); ?>">
            <input type="hidden" name="date_to"   value="<?php echo esc_attr($date_to); ?>">

            <p><strong>Borrar eventos anónimos</strong> (user_id NULL). Respeta el rango de fechas si lo has indicado arriba.</p>
            <button class="button">Borrar anónimos en el rango</button>
          </form>

          <hr style="margin:18px 0">

          <!-- EXCLUSIONES: ahora aquí, dentro de Herramientas -->
          <h2>Excluir usuarios de cómputos globales</h2>
          <p>Los usuarios en esta lista <strong>no</strong> contarán en totales, gráficas y leaderboards globales.</p>

          <h3>Añadir usuarios</h3>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return co360AddExcludedSubmit();">
            <?php wp_nonce_field('co360_update_exclusions'); ?>
            <input type="hidden" name="action" value="co360_update_exclusions">
            <div style="max-width:520px">
              <input id="co360ExclPicker" type="text" class="regular-text" placeholder="Buscar usuario por nombre o email…" autocomplete="off" style="width:100%">
              <div id="co360ExclDD" style="border:1px solid #ddd;display:none;max-height:220px;overflow:auto;background:#fff"></div>
            </div>
            <div id="co360ExclChosen" style="margin:8px 0;display:flex;flex-wrap:wrap;gap:6px"></div>
            <button type="submit" class="button button-primary">Añadir a excluidos</button>
          </form>

          <hr>

          <h3>Usuarios excluidos actuales</h3>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('co360_remove_exclusions'); ?>
            <input type="hidden" name="action" value="co360_remove_exclusions">
            <table class="widefat striped">
              <thead><tr><th style="width:40px"></th><th>Usuario</th><th>Email</th><th style="width:160px">Eventos</th></tr></thead>
              <tbody>
              <?php if(empty($excluded)): ?>
                <tr><td colspan="4">No hay usuarios excluidos.</td></tr>
              <?php else: foreach($excluded as $uid):
                  $u = get_user_by('id',$uid); if(!$u) continue; ?>
                <tr>
                  <td><input type="checkbox" name="remove_ids[]" value="<?php echo (int)$uid; ?>"></td>
                  <td><?php echo esc_html($u->display_name); ?></td>
                  <td><?php echo esc_html($u->user_email); ?></td>
                  <td><?php echo (int)($counts[$uid] ?? 0); ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
            <p style="margin-top:8px">
              <button type="submit" class="button">Quitar seleccionados</button>
            </p>
          </form>
        </div>

        <script>
        (function(){
          // Check-all para la tabla de usuarios
          const all = document.getElementById('co360CheckAll');
          if (all){
            all.addEventListener('change', function(){
              document.querySelectorAll('#co360UsersForm input[type="checkbox"][name="user_ids[]"]').forEach(cb=>cb.checked = all.checked);
            });
          }

          // Buscador de usuarios para exclusiones
          const input = document.getElementById('co360ExclPicker');
          const dd    = document.getElementById('co360ExclDD');
          const chosen= document.getElementById('co360ExclChosen');
          let timer=null, picked=[];

          function renderChosen(){
            chosen.innerHTML = picked.map(u =>
              `<span style="display:inline-flex;align-items:center;gap:6px;padding:4px 8px;background:#f1f5f9;border-radius:999px">
                 ${u.display} &lt;${u.email}&gt;
                 <button type="button" aria-label="Quitar" onclick="this.parentNode.remove();">×</button>
                 <input type="hidden" name="user_ids[]" value="${u.id}">
               </span>`
            ).join('');
          }

          function showDD(items){
            dd.innerHTML='';
            if(!items || !items.length){ dd.style.display='none'; return; }
            items.forEach(u=>{
              const row=document.createElement('div');
              row.textContent = `${u.display} <${u.email}>`;
              row.style.padding='8px'; row.style.cursor='pointer';
              row.addEventListener('click', ()=>{
                picked.push(u); renderChosen(); dd.style.display='none'; input.value='';
              });
              dd.appendChild(row);
            });
            dd.style.display='block';
          }

          input.addEventListener('input', function(){
            const q=this.value.trim();
            clearTimeout(timer);
            if(q.length<2){ dd.style.display='none'; return; }
            dd.innerHTML='<div style="padding:8px;color:#666">Buscando…</div>'; dd.style.display='block';
            timer=setTimeout(()=>{
              fetch('<?php echo esc_js($rest_users_endpoint); ?>?q='+encodeURIComponent(q), {
                headers:{'X-WP-Nonce':'<?php echo esc_js($rest_nonce); ?>'},
                credentials:'same-origin'
              }).then(r=>r.json()).then(showDD).catch(()=>{ dd.style.display='none'; });
            },200);
          });

          window.co360AddExcludedSubmit = function(){
            if (!chosen.querySelector('input[name="user_ids[]"]')) {
              alert('Selecciona al menos un usuario');
              return false;
            }
            return true;
          };
        })();
        </script>
        <?php
    }

    private function get_user_counts($from='',$to=''){
        global $wpdb; $table = $wpdb->prefix . self::TABLE;
        list($ds,$pp) = $this->build_date_sql($from,$to);
        $sql = "SELECT user_id, COUNT(*) c
                FROM {$table}
                WHERE user_id IS NOT NULL {$ds}
                GROUP BY user_id
                ORDER BY c DESC";
        return $pp ? $wpdb->get_results($wpdb->prepare($sql, $pp)) : $wpdb->get_results($sql);
    }

    private function ensure_caps() {
        // Añade aquí todos los roles que deben poder ver insights de usuarios
        $roles = [ 'administrator', 'editor', 'delegado', 'inspira', 'alter' ];

        foreach ( $roles as $role_slug ) {
            if ( $role = get_role( $role_slug ) ) {
                $role->add_cap( self::CAP_VIEW_USERS, true );
            }
        }
    }
    
    private function get_excluded_user_ids(){
        $ids = get_option(self::EXCLUDED_OPT, []);
        $ids = is_array($ids) ? array_values(array_filter(array_map('absint',$ids))) : [];
        return $ids;
    }
    private function save_excluded_user_ids(array $ids){
        $ids = array_values(array_unique(array_filter(array_map('absint',$ids))));
        update_option(self::EXCLUDED_OPT, $ids, false);
    }

    /** Devuelve [sql, params] para excluir user_id en consultas globales */
    private function build_excluded_sql(){
        $ids = $this->get_excluded_user_ids();
        if (empty($ids)) return ['', []];
        $ph = implode(',', array_fill(0, count($ids), '%d'));
        // Permitimos NULL (eventos sin user_id) pero excluimos los listados.
        $sql = " AND (e.user_id IS NULL OR e.user_id NOT IN ($ph))";
        return [$sql, $ids];
    }

    public function ajax_user_suggest(){
        // Usa el mismo nonce que ya generas para REST (wp_rest). Si prefieres, crea uno propio.
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            wp_send_json_error(['error'=>'bad_nonce'], 403);
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error(['error'=>'auth_required'], 401);
        }

        $q = isset($_POST['q']) ? sanitize_text_field( wp_unslash($_POST['q']) ) : '';
        if ( strlen($q) < 2 ) {
            wp_send_json_success([]); // vacío si menos de 2 chars
        }

        $args = [
            'number'         => 10,
            'search'         => '*' . $q . '*',
            'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
            'fields'         => [ 'ID', 'display_name', 'user_email' ],
        ];
        $uq = new WP_User_Query( $args );
        $users = [];
        foreach ( (array) $uq->get_results() as $u ) {
            $users[] = [
                'id'      => (int) $u->ID,
                'display' => (string) $u->display_name,
                'email'   => (string) $u->user_email,
            ];
        }
        wp_send_json_success($users);
    }

    /* ========== Activación / DB ========== */
    public function activate(){
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            post_id BIGINT UNSIGNED NULL,
            action VARCHAR(32) NOT NULL,
            source VARCHAR(32) NULL,
            meta LONGTEXT NULL,
            ip VARBINARY(16) NULL,
            session_id VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_action (post_id, action),
            KEY user_action (user_id, action),
            KEY created_at (created_at),
            KEY session_id (session_id)
        ) {$charset};";
        dbDelta($sql);
        foreach(['administrator','editor','delegado'] as $role){
            if ($r = get_role($role)){ $r->add_cap(self::CAP_VIEW_USERS, true); }
        }
    }

public function register_assets(){
    $base = CO360_SUITE_URL . 'assets/';
    // CSS
    wp_register_style('co360-analytics', $base . 'css/switcher.css', [], self::VER);

    // Chart.js + wrapper
    wp_register_script('co360-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
    wp_register_script('co360-charts',  $base . 'js/charts.js', ['co360-chartjs'], self::VER, true);

    // Tracker
    wp_register_script('co360-tracker', $base . 'js/tracker.js', [], self::VER, true);
    // Configuración global de export (REST /export)
    $co360_export_cfg = [
        'rest'  => rest_url(self::REST.'/export'),
        'nonce' => wp_create_nonce('wp_rest'),
    ];
    wp_add_inline_script(
        'co360-charts',
        'window.CO360_EXPORT = ' . wp_json_encode($co360_export_cfg) . ';',
        'before'
    );

    // Helper JS global de exportación (window.CO360Export)
    wp_add_inline_script(
        'co360-charts',
        '(function(){'
        . 'if(window.CO360_EXPORT_HELPER)return;'
        . 'window.CO360_EXPORT_HELPER=true;'
        . 'window.CO360Export=window.CO360Export||function(opts){'
            . 'try{'
                . 'opts=opts||{};'
                . 'var cfg=window.CO360_EXPORT||{};'
                . 'var url=cfg.rest;'
                . 'var nonce=cfg.nonce||"";'
                . 'if(!url){alert("Export no disponible.");return;}'
                . 'var p=new URLSearchParams();'
                . 'p.set("scope",opts.scope||"global");'
                . 'var fmt=(opts.format||"csv").toLowerCase();'
                . 'if(fmt!=="xls")fmt="csv";'
                . 'p.set("format",fmt);'
                . 'if(opts.user_id)p.set("user_id",opts.user_id);'
                . 'if(opts.date_from)p.set("date_from",opts.date_from);'
                . 'if(opts.date_to)p.set("date_to",opts.date_to);'
                . 'fetch(url,{'
                    . 'method:"POST",'
                    . 'credentials:"same-origin",'
                    . 'headers:{"X-WP-Nonce":nonce,"Content-Type":"application/x-www-form-urlencoded"},'
                    . 'body:p.toString()'
                . '}).then(function(r){return r.blob();}).then(function(b){'
                    . 'var a=document.createElement("a");'
                    . 'a.href=URL.createObjectURL(b);'
                    . 'a.download="alteragora_export."+(fmt==="xls"?"xls":"csv");'
                    . 'document.body.appendChild(a);'
                    . 'a.click();'
                    . 'a.remove();'
                    . 'setTimeout(function(){URL.revokeObjectURL(a.href);},2000);'
                . '});'
            . '}catch(e){alert("No se pudo exportar");}'
        . '};'
        . '})();',
        'after'
    );


    // DataTables + Buttons (para tablas de analytics)
    wp_register_style(
        'co360-datatables',
        'https://cdn.datatables.net/2.1.8/css/dataTables.dataTables.min.css',
        [],
        '2.1.8'
    );
    wp_register_style(
        'co360-datatables-buttons',
        'https://cdn.datatables.net/buttons/3.1.2/css/buttons.dataTables.min.css',
        ['co360-datatables'],
        '3.1.2'
    );

    wp_register_script(
        'co360-datatables',
        'https://cdn.datatables.net/2.1.8/js/dataTables.min.js',
        ['jquery'],
        '2.1.8',
        true
    );
    wp_register_script(
        'co360-datatables-buttons',
        'https://cdn.datatables.net/buttons/3.1.2/js/dataTables.buttons.min.js',
        ['co360-datatables'],
        '3.1.2',
        true
    );
    wp_register_script(
        'co360-jszip',
        'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
        [],
        '3.10.1',
        true
    );
    wp_register_script(
        'co360-datatables-buttons-html5',
        'https://cdn.datatables.net/buttons/3.1.2/js/buttons.html5.min.js',
        ['co360-datatables-buttons','co360-jszip'],
        '3.1.2',
        true
    );
    wp_register_script(
        'co360-datatables-buttons-colvis',
        'https://cdn.datatables.net/buttons/3.1.2/js/buttons.colVis.min.js',
        ['co360-datatables-buttons'],
        '3.1.2',
        true
    );

    // PDF.js (por si usas visor PDF.js)
    wp_register_script('pdfjs-core',   'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', [], null, true);
    wp_register_script('pdfjs-worker', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js', [], null, true);
}

    /**
     * Encola DataTables + Buttons para las tablas de analytics.
     */
    private function enqueue_datatables(){
        wp_enqueue_style('co360-datatables');
        wp_enqueue_style('co360-datatables-buttons');

        wp_enqueue_script('co360-datatables');
        wp_enqueue_script('co360-datatables-buttons');
        wp_enqueue_script('co360-jszip');
        wp_enqueue_script('co360-datatables-buttons-html5');
        wp_enqueue_script('co360-datatables-buttons-colvis');
    }


    public function enqueue_public(){
        if (is_user_logged_in()){
            wp_enqueue_script('co360-tracker');
            wp_localize_script('co360-tracker','CO360Analytics',[
                'restUrl' => esc_url_raw(rest_url(self::REST.'/track')),
                'nonce'   => wp_create_nonce('wp_rest'),
                'session' => $this->get_session_id(),
            ]);
        }
    }

    private function get_session_id(){
        if (empty($_SESSION['co360_sid'])) $_SESSION['co360_sid'] = wp_generate_uuid4();
        return $_SESSION['co360_sid'];
    }

    /* ========== REST ========== */
    public function register_rest(){
        register_rest_route(self::REST, '/track', [
            'methods'=>'POST',
            'callback'=>[$this,'rest_track'],
            'permission_callback'=>'is_user_logged_in'
        ]);
        register_rest_route(self::REST, '/users', [
            'methods'  => 'GET',
            'callback' => [$this,'rest_users'],
            'permission_callback' => 'is_user_logged_in', // <- antes: [$this,'can_view_users']
        ]);
        register_rest_route(self::REST, '/user-stats', [
            'methods'=>'GET',
            'callback'=>[$this,'rest_user_stats'],
            'permission_callback'=>[$this,'can_view_users'],
        ]);
        register_rest_route(self::REST, '/global-stats', [
            'methods'=>'GET',
            'callback'=>[$this,'rest_global_stats'],
            'permission_callback'=>[$this,'can_view_global'],
        ]);

        // Export al vuelo (CSV/XLS)
        register_rest_route(self::REST, '/export', [
            'methods'  => 'POST',
            'callback' => [$this,'rest_export'],
            'permission_callback' => [$this,'perm_export'],
        ]);
        
        register_rest_route(self::REST, '/taxonomies', [
            'methods'  => 'GET',
            'callback' => [$this,'rest_taxonomies'],
            'permission_callback' => function(){ return is_user_logged_in(); }
        ]);
        register_rest_route(self::REST, '/leaderboards', [
            'methods'  => 'GET',
            'callback' => [$this,'rest_leaderboards'],
            'permission_callback' => [$this,'can_view_global'],
        ]);
    }

    public function rest_taxonomies( WP_REST_Request $req ) {
        $tx = get_taxonomy('category');
        if ( ! $tx ) {
            return rest_ensure_response( [] );
        }
        $terms = get_terms([
            'taxonomy'   => 'category',
            'hide_empty' => false,
            'fields'     => 'all',
            'number'     => 1000,
        ]);
        return rest_ensure_response([
            [
                'taxonomy' => 'category',
                'label'    => $tx->labels->name ?: 'Categorías',
                'terms'    => array_map(function($t){
                    return ['id'=>(int)$t->term_id, 'name'=>$t->name, 'slug'=>$t->slug];
                }, is_array($terms) ? $terms : []),
            ]
        ]);
    }

    public function rest_leaderboards( WP_REST_Request $req ) {
        $from = sanitize_text_field( (string) $req->get_param('date_from') );
        $to   = sanitize_text_field( (string) $req->get_param('date_to') );
        $tax  = sanitize_key( (string) $req->get_param('tax') );
        $terms= sanitize_text_field( (string) $req->get_param('term_ids') );
        $data = $this->get_leaderboards( $from, $to, $tax, $terms );
        return rest_ensure_response( $data );
    }

    public function can_view_users(){ return current_user_can(self::CAP_VIEW_USERS) || current_user_can('list_users') || current_user_can('manage_options'); }
    public function can_view_global(){ return current_user_can(self::CAP_VIEW_USERS) || current_user_can('manage_options'); }

    public function rest_track(WP_REST_Request $req){
        $params = wp_unslash($req->get_json_params());
        $action = isset($params['action']) ? sanitize_key($params['action']) : '';
        if (!$action) return new WP_REST_Response(['ok'=>false,'error'=>'missing_action'],400);
        $post_id = isset($params['post_id']) ? absint($params['post_id']) : 0;
        $source  = isset($params['source']) ? sanitize_key($params['source']) : '';
        $meta    = isset($params['meta']) && is_array($params['meta']) ? wp_json_encode($this->sanitize_deep($params['meta'])) : null;

        global $wpdb; $table = $wpdb->prefix . self::TABLE;
        $ip = $this->inet_pton($_SERVER['REMOTE_ADDR'] ?? '');
        $wpdb->insert($table, [
            'user_id'=>get_current_user_id(),
            'post_id'=>$post_id ?: null,
            'action'=>$action,
            'source'=>$source ?: null,
            'meta'=>$meta,
            'ip'=>$ip,
            'session_id'=>$this->get_session_id(),
            'created_at'=>current_time('mysql')
        ], ['%d','%d','%s','%s','%s','%s','%s','%s']);
        if ($wpdb->last_error) return new WP_REST_Response(['ok'=>false,'error'=>'db_insert_failed'],500);
        return ['ok'=>true,'id'=>$wpdb->insert_id];
    }

    public function rest_users( WP_REST_Request $req ) {
        try {
            $q = sanitize_text_field( (string) $req->get_param('q') );
            if ( strlen($q) < 2 ) {
                return rest_ensure_response( [] );
            }

            $args = [
                'number'         => 10,
                'search'         => '*' . $q . '*',
                'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
                'fields'         => [ 'ID', 'display_name', 'user_email' ],
            ];

            $uq = new WP_User_Query( $args );
            $users = [];
            foreach ( (array) $uq->get_results() as $u ) {
                $users[] = [
                    'id'      => (int) $u->ID,
                    'display' => (string) $u->display_name,
                    'email'   => (string) $u->user_email,
                ];
            }
            return rest_ensure_response( $users );
        } catch ( \Throwable $e ) {
            error_log( '[CO360] rest_users error: ' . $e->getMessage() );
            return new WP_REST_Response( [ 'error' => 'server_error' ], 500 );
        }
    }

    public function rest_user_stats( WP_REST_Request $req ) {
        $user_id = absint( $req->get_param('user_id') );
        $from    = sanitize_text_field( (string) $req->get_param('date_from') );
        $to      = sanitize_text_field( (string) $req->get_param('date_to') );
        if ( ! $user_id ) {
            return new WP_REST_Response( [ 'error' => 'missing_user_id' ], 400 );
        }

        $totals = $this->get_user_totals( $user_id, $from, $to );
        $rows   = $this->get_user_per_post( $user_id, $from, $to );

        $per_post = array_map( function( $r ) {
            return [
                'post_id'        => (int) $r->post_id,
                'title'          => get_the_title( $r->post_id ),
                'permalink'      => get_permalink( $r->post_id ),
                'view_slidekit'  => (int) $r->view_slidekit,
                'view_highlights'=> (int) $r->view_highlights,
                'download_ppt'   => (int) $r->download_ppt,
            ];
        }, $rows );

        return rest_ensure_response( [ 'totals' => $totals, 'per_post' => $per_post ] );
    }

    public function rest_global_stats( WP_REST_Request $req ) {
        $from  = sanitize_text_field( (string) $req->get_param('date_from') );
        $to    = sanitize_text_field( (string) $req->get_param('date_to') );
        $tax   = sanitize_key( (string) $req->get_param('tax') );
        $terms = sanitize_text_field( (string) $req->get_param('term_ids') );

        $totals = $this->get_global_totals( $from, $to, $tax, $terms );
        $rows   = $this->get_global_per_post( $from, $to, $tax, $terms );

        $per_post = array_map( function( $r ) {
            return [
                'post_id'        => (int) $r->post_id,
                'title'          => get_the_title( $r->post_id ),
                'permalink'      => get_permalink( $r->post_id ),
                'view_slidekit'  => (int) $r->view_slidekit,
                'view_highlights'=> (int) $r->view_highlights,
                'download_ppt'   => (int) $r->download_ppt,
            ];
        }, $rows );

        return rest_ensure_response( [ 'totals' => $totals, 'per_post' => $per_post ] );
    }

    /* ====== Export REST ====== */
    public function perm_export( WP_REST_Request $req ){
        $scope = sanitize_text_field( (string) $req->get_param('scope') );
        if ($scope === 'global') {
            return $this->can_view_global();
        }
        if ($scope === 'user') {
            // admins/editors/delegado pueden exportar cualquier user
            if ($this->can_view_users()) return true;
            // el propio usuario puede exportar "su" actividad
            $uid = absint($req->get_param('user_id'));
            return ( is_user_logged_in() && get_current_user_id() === $uid && $uid>0 );
        }
        if ($scope === 'me') {
            return is_user_logged_in();
        }
        return false;
    }

    public function rest_export( WP_REST_Request $req ){
        $scope = sanitize_text_field( (string) $req->get_param('scope') );
        $fmt   = strtolower( sanitize_text_field( (string) ($req->get_param('format') ?: 'csv') ) );
        $from  = sanitize_text_field( (string) $req->get_param('date_from') );
        $to    = sanitize_text_field( (string) $req->get_param('date_to') );

        // NUEVO: filtros por taxonomía (solo aplicados en scope=global)
        $tax   = sanitize_key( (string) $req->get_param('tax') );
        $terms = sanitize_text_field( (string) $req->get_param('term_ids') );

        $now   = date('Ymd_His');
        $filename = "alteragora_export_{$scope}_{$now}.csv";

        if ($scope === 'global') {
            // aplica rango + taxonomía si vienen
            $rows = $this->get_global_per_post($from, $to, $tax, $terms);
            $tot  = $this->get_global_totals($from, $to, $tax, $terms);

            $payload = [
                ['Post ID','Título','Slide Kit','Highlights','PPT'],
            ];
            foreach($rows as $r){
                $payload[] = [
                    (int)$r->post_id,
                    (string)get_the_title($r->post_id),
                    (int)$r->view_slidekit,
                    (int)$r->view_highlights,
                    (int)$r->download_ppt,
                ];
            }

        } elseif ($scope==='user' || $scope==='me') {
            // (para user/me mantenemos la lógica existente sin filtros por taxonomía)
            $uid = ($scope==='me') ? get_current_user_id() : absint($req->get_param('user_id'));
            if (!$uid) return new WP_REST_Response(['error'=>'missing_user_id'],400);
            $rows = $this->get_user_per_post($uid,$from,$to);
            $tot  = $this->get_user_totals($uid,$from,$to);
            $payload = [
                ['Post ID','Título','Slide Kit','Highlights','PPT'],
            ];
            foreach($rows as $r){
                $payload[] = [
                    (int)$r->post_id,
                    (string)get_the_title($r->post_id),
                    (int)$r->view_slidekit,
                    (int)$r->view_highlights,
                    (int)$r->download_ppt,
                ];
            }
        } else {
            return new WP_REST_Response(['error'=>'bad_scope'],400);
        }

        // Siempre CSV (Excel lo abrirá correctamente)
        $fh = fopen('php://temp','w+');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM UTF-8
        foreach($payload as $row){ fputcsv($fh, $row, ';'); }
        rewind($fh);
        $content = stream_get_contents($fh);
        fclose($fh);
        $content = preg_replace("/(?<!\r)\n/", "\r\n", $content);
        $ctype   = 'text/csv; charset=UTF-8';

        nocache_headers();
        header('Content-Type: '.$ctype);
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.strlen($content));
        echo $content;
        exit;
    }

    /* ========== Hooks desde switcher ========== */
    public function hook_switcher_track($data){
        if (!is_array($data)) return;
        $type = isset($data['type']) ? sanitize_key($data['type']) : 'slidekit';
        $action = ($type==='highlights') ? 'view_highlights' : (($type==='ppt') ? 'download_ppt' : 'view_slidekit');

        // DEDUP servidor: PPT últimos 60s por sesión+post
        if ($action==='download_ppt'){
            global $wpdb; $table=$wpdb->prefix.self::TABLE;
            $post_id = 0;
            if (!empty($data['referer'])) $post_id = url_to_postid(esc_url_raw($data['referer']));
            if (!$post_id && function_exists('get_the_ID')) $post_id = (int) get_the_ID();
            if (empty($_SESSION['co360_sid'])) $_SESSION['co360_sid'] = wp_generate_uuid4();
            $sid = $_SESSION['co360_sid'];
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE action='download_ppt' AND session_id=%s
                   AND ( ( %d = 0 AND post_id IS NULL ) OR post_id=%d )
                   AND created_at >= (NOW() - INTERVAL 60 SECOND)
                 ORDER BY id DESC LIMIT 1",
                 $sid, $post_id, $post_id
            ));
            if ($exists) return;
        }

        global $wpdb; $table = $wpdb->prefix . self::TABLE;
        $ip = $this->inet_pton($_SERVER['REMOTE_ADDR'] ?? '');
        $meta = wp_json_encode([
            'switcher_id' => sanitize_text_field($data['switcher_id'] ?? ''),
            'index'       => intval($data['index'] ?? 0),
            'url'         => esc_url_raw($data['url'] ?? ''),
            'type'        => $type,
            'src'         => sanitize_key($data['source'] ?? ''),
        ]);
        $post_id = 0;
        if (!empty($data['referer'])) $post_id = url_to_postid(esc_url_raw($data['referer']));
        if (!$post_id && function_exists('get_the_ID')) $post_id = (int) get_the_ID();

        $wpdb->insert($table, [
            'user_id'=>get_current_user_id(),
            'post_id'=>$post_id ?: null,
            'action'=>$action,
            'source'=>'switcher',
            'meta'=>$meta,
            'ip'=>$ip,
            'session_id'=>$this->get_session_id(),
            'created_at'=>current_time('mysql')
        ], ['%d','%d','%s','%s','%s','%s','%s','%s']);
    }

    /* ========== Shortcodes de control ========== */
    public function sc_ppt_download($atts=[]){
        $a = shortcode_atts(['url'=>'','label'=>'Descargar PPT','post_id'=>0,'class'=>'button button-primary','target'=>'_blank'], $atts);
        if (!$a['url']) return '';
        $post_id = absint($a['post_id']) ?: get_the_ID();
        if (is_user_logged_in()) wp_enqueue_script('co360-tracker');
        return sprintf(
            '<a href="%s" class="%s" data-co360-action="download_ppt" data-co360-post="%d" target="%s" rel="noopener">%s</a>',
            esc_url($a['url']), esc_attr($a['class']), $post_id, esc_attr($a['target']), esc_html($a['label'])
        );
    }

    public function sc_pdf_viewer($atts=[]){
        // derivamos al viewer de la otra clase (reutilizamos su método)
        return $GLOBALS['co360_suite_pdfviewer']->shortcode_pdf_viewer($atts);
    }

    /* ========== Paneles rápidos ========== */
     public function sc_user_analytics(){
     if ( ! is_user_logged_in() ) {
         return '<p>Debes iniciar sesión.</p>';
     }

     // Estilos / scripts base
     wp_enqueue_style('co360-analytics');

     // DataTables + Buttons (para la tabla "Mi actividad")
     $this->enqueue_datatables();

     // Chart.js
     wp_enqueue_script('co360-chartjs');
     wp_enqueue_script('co360-charts');

     // Datos del usuario actual
     $uid   = get_current_user_id();
     $tot   = $this->get_user_totals($uid);
     $rows  = $this->get_user_per_post($uid);

     $labels = array_map(function($r){ return get_the_title($r->post_id); }, $rows);
     $ds1    = array_map(function($r){ return (int)$r->view_slidekit; }, $rows);
     $ds2    = array_map(function($r){ return (int)$r->view_highlights; }, $rows);
     $ds3    = array_map(function($r){ return (int)$r->download_ppt; }, $rows);

          ob_start(); ?>
     <div class="co360-ua">
       <h3 style="display:flex;align-items:center;gap:.75rem;justify-content:space-between;">
         <span>Mi actividad</span>
       </h3>

       <div class="co360-cards">
         <div class="co360-card"><strong>Total Visualizaciones Slide Kit</strong><span><?php echo (int)($tot['view_slidekit'] ?? 0); ?></span></div>
         <div class="co360-card"><strong>Total Visualizaciones Highlights</strong><span><?php echo (int)($tot['view_highlights'] ?? 0); ?></span></div>
         <div class="co360-card"><strong>Total Descargas PPT</strong><span><?php echo (int)($tot['download_ppt'] ?? 0); ?></span></div>
       </div>

       <div class="co360-chartbox" style="height:420px">
         <canvas id="co360UserBar"></canvas>
       </div>

       <div class="co360-table-wrap">
         <table id="co360UserTable" class="co360-table display">
           <thead>
             <tr>
               <th>Título</th>
               <th>Total Visualizaciones Slide Kit</th>
               <th>Total Visualizaciones Highlights</th>
               <th>Total Descargas PPT</th>
             </tr>
           </thead>
           <tbody>
           <?php foreach ($rows as $r): ?>
             <tr>
               <td><a href="<?php echo esc_url( get_permalink($r->post_id) ); ?>"><?php echo esc_html( get_the_title($r->post_id) ); ?></a></td>
               <td><?php echo (int) $r->view_slidekit; ?></td>
               <td><?php echo (int) $r->view_highlights; ?></td>
               <td><?php echo (int) $r->download_ppt; ?></td>
             </tr>
           <?php endforeach; ?>
           </tbody>
         </table>
       </div>
     </div>
     <script>
     (function(){
       // Datos para Chart.js
       window.CO360_CHART_DATA = window.CO360_CHART_DATA || {};
       window.CO360_CHART_DATA.user = {
         labels: <?php echo wp_json_encode($labels); ?>,
         datasets: [
           { label: 'Slide Kit',   data: <?php echo wp_json_encode($ds1); ?> },
           { label: 'Highlights',  data: <?php echo wp_json_encode($ds2); ?> },
           { label: 'PPT',         data: <?php echo wp_json_encode($ds3); ?> }
         ]
       };

       // Render del gráfico si ya está listo Chart.js
       if (window.CO360RenderChart && window.CO360_CHART_DATA.user) {
         window.CO360RenderChart('co360UserBar', window.CO360_CHART_DATA.user);
       } else if (window.Chart) {
         var ctx = document.getElementById('co360UserBar');
         if (ctx) {
           new Chart(ctx.getContext('2d'), {
             type: 'bar',
             data: window.CO360_CHART_DATA.user,
             options: {
               responsive: true,
               maintainAspectRatio: false,
               plugins: { legend: { position: 'top' } },
               scales: { y: { beginAtZero: true } }
             }
           });
         }
       }

       // Export fallback vía REST
       // Inicializar DataTable en la tabla "Mi actividad"
if (window.jQuery) {
  jQuery(function($){
    var $tbl = $('#co360UserTable');
    if (!$tbl.length || !$.fn.DataTable) return;

    if ($.fn.DataTable.isDataTable($tbl)) {
      $tbl.DataTable().destroy();
    }

    $tbl.DataTable({
      dom: 'Blfrtip',
      colReorder: false,
      ordering: true,
      pageLength: 25,
      lengthMenu: [[10,25,50,100,-1],[10,25,50,100,"Todos"]],
      language: {
        url: 'https://cdn.datatables.net/plug-ins/2.1.8/i18n/es-ES.json'
      },
      buttons: [
          {
            extend: 'csvHtml5',
            title: 'AlterAgora Mi Panel',
          exportOptions: { columns: ':visible' }
        },
          {
            extend: 'excelHtml5',
            title: 'AlterAgora Mi Panel',
          exportOptions: { columns: ':visible' }
        },
        {
          extend: 'colvis',
          text: 'Columnas'
        }
      ]
    });
  });
}

     })();
     </script>
     <?php
     return ob_get_clean();
 }


    public function sc_global_analytics($atts=[]){
        $a = shortcode_atts(['role'=>'administrator,editor,delegado'], $atts);
        $roles = array_filter(array_map('trim', explode(',', $a['role'])));

        // 1) Si el usuario actual no tiene ninguno de estos roles, no ve nada
        if ( ! $this->current_user_has_any_role($roles) ) {
            return '';
        }

        // 2) Asegurar que estos roles tienen la capability para ver globales
        foreach ( $roles as $role_slug ) {
            if ( $role = get_role( $role_slug ) ) {
                $role->add_cap( self::CAP_VIEW_USERS, true );
            }
        }

        // Estilos/gráficos propios
        wp_enqueue_style('co360-analytics');
        wp_enqueue_script('co360-chartjs');
        wp_enqueue_script('co360-charts');

        // DataTables + Buttons (exportación en el navegador)
        $this->enqueue_datatables();

        // Endpoints / nonce para filtros y leaderboards
        $restNonce   = wp_create_nonce('wp_rest');
        $restTax     = esc_url_raw( rest_url(self::REST.'/taxonomies') );
        $restGlobal  = esc_url_raw( rest_url(self::REST.'/global-stats') );
        $restLeaders = esc_url_raw( rest_url(self::REST.'/leaderboards') );

        // Datos iniciales (sin filtros)
        $tot  = $this->get_global_totals();
        $rows = $this->get_global_per_post();

        $labels = array_map(function($r){ return get_the_title($r->post_id); }, $rows);
        $ds1    = array_map(function($r){ return (int)$r->view_slidekit; }, $rows);
        $ds2    = array_map(function($r){ return (int)$r->view_highlights; }, $rows);
        $ds3    = array_map(function($r){ return (int)$r->download_ppt; }, $rows);

        ob_start(); ?>
        <div class="co360-ga">
          <h3 style="display:flex;align-items:center;gap:.75rem;justify-content:space-between;">
            <span>Panel general</span>
          </h3>

          <!-- Controles: Fecha + Categorías (checkboxes) -->
          <div class="co360-controls" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start;margin-top:.5rem">
            <div>
              <label for="co360FromGlobal">Desde</label>
              <input type="date" id="co360FromGlobal">
            </div>
            <div>
              <label for="co360ToGlobal">Hasta</label>
              <input type="date" id="co360ToGlobal">
            </div>
            <div style="min-width:260px">
              <label style="display:block;margin-bottom:.25rem"><strong>Categorías</strong></label>
              <div id="co360Cats" style="border:1px solid #ddd;padding:.5rem;border-radius:4px;max-height:220px;overflow:auto">
                <div style="color:#666;font-size:12px">Cargando categorías…</div>
              </div>
            </div>
            <div style="align-self:flex-end">
              <button id="co360ApplyTax" class="button">Aplicar filtros</button>
            </div>
          </div>

          <div class="co360-cards" id="co360Totals" style="margin-top:.5rem">
            <div class="co360-card"><strong>Total Visualizaciones Slide Kit</strong><span><?php echo (int)($tot['view_slidekit']??0); ?></span></div>
            <div class="co360-card"><strong>Total Visualizaciones Highlights</strong><span><?php echo (int)($tot['view_highlights']??0); ?></span></div>
            <div class="co360-card"><strong>Total Descargas PPT</strong><span><?php echo (int)($tot['download_ppt']??0); ?></span></div>
          </div>

          <div class="co360-chartbox" style="height:420px">
            <canvas id="co360GlobalBar"></canvas>
          </div>

          <div class="co360-table-wrap">
            <table class="co360-table" id="co360GlobalTable">
              <thead>
                <tr>
                  <th>Título</th>
                  <th class="co360-num-col">Total Visualizaciones Slide Kit</th>
                  <th class="co360-num-col">Total Visualizaciones Highlights</th>
                  <th class="co360-num-col">Total Descargas PPT</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><a href="<?php echo esc_url(get_permalink($r->post_id)); ?>" target="_blank"><?php echo esc_html(get_the_title($r->post_id)); ?></a></td>
                  <td><?php echo (int)$r->view_slidekit; ?></td>
                  <td><?php echo (int)$r->view_highlights; ?></td>
                  <td><?php echo (int)$r->download_ppt; ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Leaderboards -->
          <div id="co360Leaderboards" style="margin-top:16px"></div>
        </div>

        <script>
        // Datos iniciales para la gráfica
        window.CO360_CHART_DATA = window.CO360_CHART_DATA || {};
        window.CO360_CHART_DATA.global = {
          labels: <?php echo wp_json_encode($labels); ?>,
          datasets: [
            { label: 'Slide Kit',   data: <?php echo wp_json_encode($ds1); ?> },
            { label: 'Highlights',  data: <?php echo wp_json_encode($ds2); ?> },
            { label: 'PPT',         data: <?php echo wp_json_encode($ds3); ?> }
          ]
        };
        if (window.CO360RenderChart) {
          window.CO360RenderChart('co360GlobalBar', window.CO360_CHART_DATA.global);
        }

        // Config REST global
        window.CO360AnalyticsGlobal = Object.assign(window.CO360AnalyticsGlobal||{}, {
          restTax:     "<?php echo esc_js($restTax); ?>",
          restGlobal:  "<?php echo esc_js($restGlobal); ?>",
          restLeaders: "<?php echo esc_js($restLeaders); ?>",
          nonce:       "<?php echo esc_js($restNonce); ?>"
        });

        (function(){
          const rest    = window.CO360AnalyticsGlobal || {};
          const catsBox = document.getElementById('co360Cats');
          const btnApply= document.getElementById('co360ApplyTax');
          const fromEl  = document.getElementById('co360FromGlobal');
          const toEl    = document.getElementById('co360ToGlobal');
          const btnCSV  = document.getElementById('co360ExportCSV');
          const btnXLS  = document.getElementById('co360ExportXLS');

          // Pinta lista de categorías como checkboxes
          if (rest.restTax) {
            fetch(rest.restTax, {
              headers:{'X-WP-Nonce':rest.nonce||''},
              credentials:'same-origin'
            })
            .then(r=>r.json())
            .then(list=>{
              const cat = (list||[]).find(t => t.taxonomy==='category');
              if (!catsBox) return;
              if (!cat || !cat.terms || !cat.terms.length) {
                catsBox.innerHTML = '<div style="color:#666">No hay categorías.</div>';
                return;
              }
              catsBox.innerHTML = cat.terms.map(t =>
                `<label style="display:block;margin:2px 0">
                   <input type="checkbox" value="${t.id}"> ${t.name}
                 </label>`
              ).join('');
            })
            .catch(()=>{ if(catsBox){ catsBox.innerHTML='<div style="color:#666">No se pudieron cargar las categorías.</div>'; } });
          }

          function selectedCatIds(){
            if (!catsBox) return '';
            return Array.from(catsBox.querySelectorAll('input[type="checkbox"]:checked'))
                        .map(i=>i.value)
                        .join(',');
          }

          // Carga inicial de leaderboards (sin filtros)
          if (rest.restLeaders) {
            fetch(rest.restLeaders, {
              headers:{'X-WP-Nonce':rest.nonce||''},
              credentials:'same-origin'
            })
            .then(r=>r.json())
            .then(renderLeaderboards)
            .catch(()=>{});
          }

          // Render de leaderboards
          function renderLeaderboards(data){
            const box = document.getElementById('co360Leaderboards');
            if (!box) return;

            const asNumber = v => (typeof v==='number') ? v : parseFloat(v||0);
            const safe     = s => (s==null?'':String(s));

            function card({title, rows, kind}){
              const items = (rows||[]).slice(0,10).map((r, i) => {
                if (kind === 'user') {
                  return `
                    <li class="co360-lb-item">
                      <span class="co360-lb-rank">${i+1}</span>
                      <div class="co360-lb-title">
                        ${safe(r.display)}
                        <div class="co360-lb-sub">${safe(r.email||'')}</div>
                      </div>
                      <span class="co360-lb-value">${asNumber(r.count)}</span>
                    </li>`;
                }
                if (kind === 'ratio') {
                  return `
                    <li class="co360-lb-item">
                      <span class="co360-lb-rank">${i+1}</span>
                      <div class="co360-lb-title">
                        <a href="${safe(r.permalink)}" target="_blank" rel="noopener">${safe(r.title)}</a>
                        <div class="co360-lb-sub">PPT ${asNumber(r.ppt)} · SK ${asNumber(r.sk)}</div>
                      </div>
                      <span class="co360-lb-value">${(asNumber(r.ratio)||0).toFixed(2)}</span>
                    </li>`;
                }
                // posts (slidekit/highlights/ppt)
                return `
                  <li class="co360-lb-item">
                    <span class="co360-lb-rank">${i+1}</span>
                    <div class="co360-lb-title">
                      <a href="${safe(r.permalink)}" target="_blank" rel="noopener">${safe(r.title)}</a>
                    </div>
                    <span class="co360-lb-value">${asNumber(r.count)}</span>
                  </li>`;
              }).join('');

              return `
                <div class="co360-lb-card">
                  <h4>${title}</h4>
                  ${items ? `<ol class="co360-lb-list">${items}</ol>` : `<div class="co360-lb-empty">Sin datos</div>`}
                </div>`;
            }

            box.innerHTML = `
              <div class="co360-lb">
                ${card({ title:'Top usuarios (total eventos)',  rows:data.top_users_total, kind:'user' })}
                ${card({ title:'Top usuarios (descargas PPT)', rows:data.top_users_ppt,   kind:'user' })}
                ${card({ title:'Top Slidekit (visualizaciones)',        rows:data.top_slidekit,     kind:'post' })}
                ${card({ title:'Top Highlights (visualizaciones)',      rows:data.top_highlights,   kind:'post' })}
                ${card({ title:'Top PPT (descargas)',          rows:data.top_ppt,          kind:'post' })}
                ${card({ title:'Ratio PPT / Slidekit',         rows:data.ratio_ppt_per_sk, kind:'ratio' })}
              </div>`;
          }

          // Aplicar filtros (fecha + categorías)
          if (btnApply && rest.restGlobal) {
            btnApply.addEventListener('click', function(e){
              e.preventDefault();

              const tax   = 'category';
              const terms = selectedCatIds();
              const df    = (fromEl && fromEl.value) ? fromEl.value : '';
              const dt    = (toEl   && toEl.value)   ? toEl.value   : '';

              // Refrescar totales + gráfico + tabla
              const p1 = new URLSearchParams();
              if (df)    p1.set('date_from', df);
              if (dt)    p1.set('date_to',   dt);
              if (terms) { p1.set('tax', tax); p1.set('term_ids', terms); }

              fetch(rest.restGlobal + (p1.toString() ? ('?'+p1.toString()) : ''), {
                headers:{'X-WP-Nonce':rest.nonce||''},
                credentials:'same-origin'
              })
              .then(r=>r.json())
              .then(payload=>{
                const tot = payload.totals||{};
                const cards = document.getElementById('co360Totals');
                if (cards) {
                  cards.innerHTML = `
                    <div class="co360-card"><strong>Total Slide Kit</strong><span>${(tot.view_slidekit||0)}</span></div>
                    <div class="co360-card"><strong>Total Highlights</strong><span>${(tot.view_highlights||0)}</span></div>
                    <div class="co360-card"><strong>Total PPT</strong><span>${(tot.download_ppt||0)}</span></div>`;
                }
                const rows = payload.per_post||[];
                window.CO360_CHART_DATA = window.CO360_CHART_DATA||{};
                window.CO360_CHART_DATA.global = {
                  labels: rows.map(r=>r.title),
                  datasets: [
                    {label:'Slide Kit',  data: rows.map(r=>r.view_slidekit||0)},
                    {label:'Highlights', data: rows.map(r=>r.view_highlights||0)},
                    {label:'PPT',        data: rows.map(r=>r.download_ppt||0)}
                  ]
                };
                if (window.CO360RenderChart) {
                  window.CO360RenderChart('co360GlobalBar', window.CO360_CHART_DATA.global);
                }

                const tbody = document.querySelector('#co360GlobalTable tbody');
                if (tbody) {
                  tbody.innerHTML = rows.map(r=>`<tr>
                      <td><a href="${r.permalink}" target="_blank" rel="noopener">${r.title}</a></td>
                      <td>${r.view_slidekit||0}</td>
                      <td>${r.view_highlights||0}</td>
                      <td>${r.download_ppt||0}</td>
                  </tr>`).join('');
                }

                // 👉 Actualizar DataTables con las nuevas filas
                if (typeof window.co360RefreshGlobalDataTable === 'function') {
                  window.co360RefreshGlobalDataTable();
                }
              });

              // Leaderboards con mismos filtros
              const p2 = new URLSearchParams();
              if (df)    p2.set('date_from', df);
              if (dt)    p2.set('date_to',   dt);
              if (terms) { p2.set('tax', tax); p2.set('term_ids', terms); }

              fetch(rest.restLeaders + (p2.toString()?('?'+p2.toString()):''), {
                headers:{'X-WP-Nonce':rest.nonce||''},
                credentials:'same-origin'
              })
              .then(r=>r.json())
              .then(renderLeaderboards)
              .catch(()=>{});
            });
          }

          // === DataTables + export (CSV / Excel) ===
          jQuery(function($){
            var $tbl = $('#co360GlobalTable');
            if (!$tbl.length || !$.fn.DataTable) return;

            var dt = $tbl.DataTable({
              dom: 'Blfrtip',
              colReorder: false,
              ordering: true,
              pageLength: 25,
              lengthMenu: [[10,25,50,100,-1],[10,25,50,100,"Todos"]],
              language: {
                url: 'https://cdn.datatables.net/plug-ins/2.1.8/i18n/es-ES.json'
              },
              buttons: [
                  {
                    extend: 'csvHtml5',
                    title: 'AlterAgora Estadísticas Generales',
                  exportOptions: { columns: ':visible' }
                },
                  {
                    extend: 'excelHtml5',
                    title: 'AlterAgora Estadísticas Generales',
                  exportOptions: { columns: ':visible' }
                },
                {
                  extend: 'colvis',
                  text: 'Columnas'
                }
              ]
            });

            // Guardamos global para refrescar tras filtros
            window.CO360GlobalDT = dt;

            // Reutilizar los botones superiores
            if (btnCSV) {
              btnCSV.addEventListener('click', function(ev){
                ev.preventDefault();
                dt.button('.buttons-csv').trigger();
              });
            }
            if (btnXLS) {
              btnXLS.addEventListener('click', function(ev){
                ev.preventDefault();
                dt.button('.buttons-excel').trigger();
              });
            }
          });

          // Helper global para refrescar DataTables después de cambiar el tbody
          window.co360RefreshGlobalDataTable = function(){
            if (!window.CO360GlobalDT || !jQuery.fn.DataTable) return;
            var dt = window.CO360GlobalDT;
            var $ = jQuery;
            var $rows = $('#co360GlobalTable tbody tr');
            dt.clear();
            // Añadimos las filas actuales del DOM como nuevas filas de DataTables
            $rows.each(function(){
              dt.row.add(this);
            });
            dt.draw();
          };

        })();
        </script>
        <?php

        return ob_get_clean();
    }


    public function sc_user_export( $atts = [] ) {
        if ( ! $this->can_view_users() ) {
            return '<p>No tienes permisos para ver este informe.</p>';
        }

        $a = shortcode_atts(
            [
                'title'     => 'Exportar analítica completa de usuarios',
                'date_from' => '',
                'date_to'   => '',
            ],
            $atts
        );

        wp_enqueue_style( 'co360-analytics' );
        $this->enqueue_datatables();

        $stats = $this->get_all_user_stats( $a['date_from'], $a['date_to'] );

        $all_posts = [];
        foreach ( $stats as $row ) {
            foreach ( $row['per_post'] as $p ) {
                if ( empty( $all_posts[ $p['post_id'] ] ) ) {
                    $all_posts[ $p['post_id'] ] = $p['title'];
                }
            }
        }

        if ( ! empty( $all_posts ) ) {
            uasort(
                $all_posts,
                function( $a, $b ) {
                    return strcasecmp( $a, $b );
                }
            );
        }

        $meta_labels = [
            'user_especialidad' => 'Especialidad',
            'user_centro'       => 'Centro',
            'user_poblacion'    => 'Población',
            'user_provincia'    => 'Provincia',
            'user_nif'          => 'NIF',
            'user_cp'           => 'CP',
        ];

        ob_start(); ?>
        <div class="co360-ga">
          <h3><?php echo esc_html( $a['title'] ); ?></h3>

          <div class="co360-table-wrap co360-table-wrap--scroll">
            <div class="co360-table-scroll">
            <table id="co360UserExportTable" class="co360-table display">
              <thead>
                <tr>
                  <th class="co360-col-expand no-export"></th>
                  <th>Nombre</th>
                  <th>Apellidos</th>
                  <th>Email</th>
                  <th>Registrado</th>
                  <?php foreach ( $meta_labels as $label ) : ?>
                    <th><?php echo esc_html( $label ); ?></th>
                  <?php endforeach; ?>
                  <th>Total Visualizaciones Slide Kit</th>
                  <th>Total Visualizaciones Highlights</th>
                  <th>Total Descargas PPT</th>
                  <th class="co360-export-only">Presentación</th>
                  <th class="co360-export-only">Slide Kit (detalle)</th>
                  <th class="co360-export-only">Highlights (detalle)</th>
                  <th class="co360-export-only">PPT (detalle)</th>
                  <th class="co360-export-only">Total presentación</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ( $stats as $row ) :
                    $per_post_map    = [];
                    $per_post_sorted = [];
                    foreach ( $row['per_post'] as $p ) {
                        $per_post_map[ $p['post_id'] ] = $p;
                    }

                    foreach ( $all_posts as $post_id => $title ) {
                        if ( isset( $per_post_map[ $post_id ] ) ) {
                            $p     = $per_post_map[ $post_id ];
                            $total = (int) $p['view_slidekit'] + (int) $p['view_highlights'] + (int) $p['download_ppt'];
                            $per_post_sorted[] = [
                                'title'      => $title,
                                'slidekit'   => (int) $p['view_slidekit'],
                                'highlights' => (int) $p['view_highlights'],
                                'ppt'        => (int) $p['download_ppt'],
                                'total'      => $total,
                            ];
                        }
                    }

                    $detail_export_lines = [];
                    foreach ( $per_post_sorted as $p ) {
                        $detail_export_lines[] = sprintf(
                            '%s — SK: %d | HL: %d | PPT: %d | Total: %d',
                            $p['title'],
                            $p['slidekit'],
                            $p['highlights'],
                            $p['ppt'],
                            $p['total']
                        );
                    }

                    $detail_json   = wp_json_encode( $per_post_sorted );
                    $detail_export = implode( "\n", $detail_export_lines );
                ?>
                <tr>
                  <td class="dt-control" data-per-post="<?php echo esc_attr( $detail_json ); ?>" data-export="<?php echo esc_attr( $detail_export ?: '—' ); ?>" aria-label="Mostrar detalle"></td>
                  <td><?php echo esc_html( $row['first_name'] ); ?></td>
                  <td><?php echo esc_html( $row['last_name'] ); ?></td>
                  <td><?php echo esc_html( $row['email'] ); ?></td>
                  <td><?php echo esc_html( $row['registered'] ); ?></td>
                  <?php foreach ( array_keys( $meta_labels ) as $meta_key ) : ?>
                    <td><?php echo esc_html( $row['meta'][ $meta_key ] ); ?></td>
                  <?php endforeach; ?>
                  <td class="co360-num"><?php echo (int) $row['totals']['view_slidekit']; ?></td>
                  <td class="co360-num"><?php echo (int) $row['totals']['view_highlights']; ?></td>
                  <td class="co360-num"><?php echo (int) $row['totals']['download_ppt']; ?></td>
                  <td class="co360-export-only co360-export-presentacion">—</td>
                  <td class="co360-export-only co360-export-sk">—</td>
                  <td class="co360-export-only co360-export-hl">—</td>
                  <td class="co360-export-only co360-export-ppt">—</td>
                  <td class="co360-export-only co360-export-total">—</td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
          </div>
        </div>
        <script>
        jQuery(function($){
          var exportChildRowNumbers = [];

          function buildChildRowsForExport(data){
            exportChildRowNumbers = [];

            try {
              var rowNodes = table.rows({ search: 'applied', order: 'applied' }).nodes().toArray();
              var headers  = data.header || [];
              var cols     = headers.length;
              var body     = [];

              var titleIdx = headers.indexOf('Presentación');
              var skIdx    = headers.indexOf('Slide Kit (detalle)');
              var hlIdx    = headers.indexOf('Highlights (detalle)');
              var pptIdx   = headers.indexOf('PPT (detalle)');
              var totIdx   = headers.indexOf('Total presentación');

              if (titleIdx === -1 || skIdx === -1 || hlIdx === -1 || pptIdx === -1 || totIdx === -1) {
                titleIdx = cols - 5;
                skIdx    = cols - 4;
                hlIdx    = cols - 3;
                pptIdx   = cols - 2;
                totIdx   = cols - 1;
              }

              data.body.forEach(function(row, idx){
                body.push(row);

                var node    = rowNodes[idx];
                var control = node ? $(node).find('td.dt-control') : null;
                var perPost = control ? control.data('per-post') : [];

                if (typeof perPost === 'string') {
                  try { perPost = JSON.parse(perPost); } catch (e) { perPost = []; }
                }

                if (!perPost || !perPost.length) return;

                perPost.forEach(function(p){
                  var child = new Array(cols).fill('');
                  child[titleIdx] = p.title || '';
                  child[skIdx]    = p.slidekit || 0;
                  child[hlIdx]    = p.highlights || 0;
                  child[pptIdx]   = p.ppt || 0;
                  child[totIdx]   = p.total || 0;

                  body.push(child);
                  exportChildRowNumbers.push(2 + body.length - 1);
                });
              });

              data.body = body;
            } catch (err) {
              console.error('No se pudieron preparar las filas hijas para exportar', err);
            }
          }

          function applyOutlineToSheet(xlsx){
            try {
              if (!exportChildRowNumbers.length || !xlsx || !xlsx.xl || !xlsx.xl.worksheets || !xlsx.xl.worksheets['sheet1.xml']) return;

              var sheet  = xlsx.xl.worksheets['sheet1.xml'];
              var $sheet = $(sheet);

              var sheetPr = $sheet.find('sheetPr');
              if (!sheetPr.length) {
                $sheet.prepend('<sheetPr><outlinePr summaryBelow="1" summaryRight="1"/></sheetPr>');
              } else if (!sheetPr.find('outlinePr').length) {
                sheetPr.append('<outlinePr summaryBelow="1" summaryRight="1"/>');
              }

              exportChildRowNumbers.forEach(function(r){
                $sheet.find('row[r="' + r + '"]').attr('outlineLevel', '1');
              });

              var serializer = new XMLSerializer();
              xlsx.xl.worksheets['sheet1.xml'] = serializer.serializeToString($sheet[0]);
            } catch (err) {
              console.error('No se pudo aplicar el outline al Excel exportado', err);
            }
          }

          var table = $('#co360UserExportTable').DataTable({
            dom: 'Blfrtip',
            colReorder: false,
            ordering: true,
            scrollX: true,
            pageLength: 25,
            lengthMenu: [[10,25,50,100,-1],[10,25,50,100,'Todos']],
            order: [[1, 'asc']],
            columnDefs: [
              { targets: 0, className: 'dt-control', orderable: false, data: null, defaultContent: '' },
              { targets: 'co360-export-only', visible: false, searchable: false }
            ],
            language: {
              url: 'https://cdn.datatables.net/plug-ins/2.1.8/i18n/es-ES.json'
            },
            buttons: [
                {
                  extend: 'csvHtml5',
                  title: 'AlterAgora Analítica Usuarios',
                exportOptions: {
                  columns: ':visible:not(.no-export), .co360-export-only',
                  format: {
                      body: function ( data, row, col ) {
                        var text = typeof data === 'string' ? data.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g,'').trim() : data;
                      var cell = table.cell(row, col).node();
                      var extra = $(cell).data('export');
                      if (extra) { text = extra; }
                      return text;
                    }
                  },
                  customizeData: function(data){
                    buildChildRowsForExport(data);
                  }
                }
              },
                {
                  extend: 'excelHtml5',
                  title: 'AlterAgora Analítica Usuarios',
                exportOptions: {
                  columns: ':visible:not(.no-export), .co360-export-only',
                  format: {
                      body: function ( data, row, col ) {
                        var text = typeof data === 'string' ? data.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g,'').trim() : data;
                      var cell = table.cell(row, col).node();
                      var extra = $(cell).data('export');
                      if (extra) { text = extra; }
                      return text;
                    }
                  },
                  customizeData: function(data){
                    buildChildRowsForExport(data);
                  }
                },
                customize: function(xlsx){
                  applyOutlineToSheet(xlsx);
                }
              },
              {
                extend: 'colvis',
                text: 'Columnas'
              }
            ]
          });

          function renderChild(perPost){
            var items = perPost || [];
            if (typeof items === 'string') {
              try { items = JSON.parse(items); } catch (e) { items = []; }
            }

            if (!items.length) {
              return '<div class="co360-child-empty">Sin presentaciones registradas.</div>';
            }

            var rows = items.map(function(p){
              return '<tr>' +
                       '<td>' + (p.title || '') + '</td>' +
                       '<td class="co360-num">' + (p.slidekit || 0) + '</td>' +
                       '<td class="co360-num">' + (p.highlights || 0) + '</td>' +
                       '<td class="co360-num">' + (p.ppt || 0) + '</td>' +
                       '<td class="co360-num co360-num--total">' + (p.total || 0) + '</td>' +
                     '</tr>';
            }).join('');

            return '<div class="co360-child-wrap">' +
                     '<table class="co360-child-table">' +
                       '<thead><tr><th>Presentación</th><th>Slide Kit</th><th>Highlights</th><th>PPT</th><th>Total</th></tr></thead>' +
                       '<tbody>' + rows + '</tbody>' +
                     '</table>' +
                   '</div>';
          }

          $('#co360UserExportTable tbody').on('click', 'td.dt-control', function(){
            var tr  = $(this).closest('tr');
            var row = table.row(tr);
            if (row.child.isShown()) {
              row.child.hide();
              tr.removeClass('shown');
            } else {
              var perPost = $(this).data('per-post');
              row.child(renderChild(perPost)).show();
              tr.addClass('shown');
            }
          });
        });
        </script>
        <?php
        return ob_get_clean();
    }


    public function sc_user_insights($atts=[]){
    $a = shortcode_atts([
        'role'        => 'administrator,editor,delegado',
        'placeholder' => 'Buscar usuario por nombre o email…'
    ], $atts);

    $roles = array_filter(array_map('trim', explode(',', $a['role'])));

    // 1) Si el usuario actual no tiene ninguno de estos roles, no mostramos nada
    if ( ! $this->current_user_has_any_role($roles) ) {
        return '';
    }

    // 2) Asegurar que TODOS los roles usados en el shortcode tengan la capability CAP_VIEW_USERS
    foreach ( $roles as $role_slug ) {
        if ( $role = get_role( $role_slug ) ) {
            $role->add_cap( self::CAP_VIEW_USERS, true );
        }
    }

    wp_enqueue_style('co360-analytics');
    $this->enqueue_datatables();
    wp_enqueue_script('co360-chartjs');
    wp_enqueue_script('co360-charts');

    // Endpoints y nonce (incluye REST + AJAX fallback)
    $restUsers     = esc_url_raw( rest_url(self::REST.'/users') );
    $restUserStats = esc_url_raw( rest_url(self::REST.'/user-stats') );
    $restNonce     = wp_create_nonce('wp_rest');
    $ajaxUrl       = admin_url('admin-ajax.php');

    ob_start(); ?>
    <script>
    window.CO360AnalyticsGlobal = Object.assign(window.CO360AnalyticsGlobal||{}, {
      restUsers: "<?php echo esc_js($restUsers); ?>",
      restUserStats: "<?php echo esc_js($restUserStats); ?>",
      nonce: "<?php echo esc_js($restNonce); ?>",
      ajax: "<?php echo esc_js($ajaxUrl); ?>"
    });
    </script>

    <div class="co360-ga">
      <h3>Análisis de usuarios</h3>

      <div class="co360-controls" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
        <div>
          <label class="co360-label">Usuario</label>
          <div style="position:relative;min-width:320px">
            <input id="co360UserPicker" class="regular-text" placeholder="<?php echo esc_attr($a['placeholder']); ?>" autocomplete="off" style="width:100%">
            <div id="co360UserDropdown" class="co360-dropdown" style="position:absolute;left:0;right:0;top:100%;background:#fff;border:1px solid #ddd;display:none;z-index:9;max-height:240px;overflow:auto"></div>
          </div>
        </div>
        <div>
          <label for="co360From">Desde</label>
          <input type="date" id="co360From">
        </div>
        <div>
          <label for="co360To">Hasta</label>
          <input type="date" id="co360To">
        </div>
        <div>
          <button id="co360Apply" class="button button-primary">Aplicar</button>
        </div>
      </div>



      <div id="co360UserDetail" style="margin-top:12px"></div>
    </div>

    <script>
    (function(){
      const input  = document.getElementById('co360UserPicker');
      const dd     = document.getElementById('co360UserDropdown');
      const out    = document.getElementById('co360UserDetail');
      const fromEl = document.getElementById('co360From');
      const toEl   = document.getElementById('co360To');
      const apply  = document.getElementById('co360Apply');

      const grp    = document.getElementById('co360ExportUserGrp');
      const btnCSV = document.getElementById('co360ExportUserCSV');
      const btnXLS = document.getElementById('co360ExportUserXLS');

      let picked=null, timer=null;

      function showDD(items){
        dd.innerHTML='';
        if(!items || !items.length){ dd.innerHTML='<div style="padding:8px;color:#666">Sin resultados</div>'; }
        else {
          items.forEach(u=>{
            const row=document.createElement('div');
            row.textContent = u.display + ' <' + u.email + '>';
            row.style.padding='8px'; row.style.cursor='pointer';
            row.addEventListener('click', ()=>{
              picked=u; dd.style.display='none'; input.value=u.display;
              fetchStats();
            });
            dd.appendChild(row);
          });
        }
        dd.style.display='block';
      }

      function fetchUsers(q){
        if(!q || q.length<2){ dd.style.display='none'; return; }
        dd.innerHTML='<div style="padding:8px;color:#666">Cargando…</div>';
        dd.style.display='block';

        // 1) REST (requiere nonce y estar logueado)
        fetch(CO360AnalyticsGlobal.restUsers + '?q='+encodeURIComponent(q), {
          headers:{'X-WP-Nonce':CO360AnalyticsGlobal.nonce},
          credentials:'same-origin'
        })
        .then(async r=>{
          if(!r.ok){ const t=await r.text(); throw new Error('REST '+r.status+': '+t.slice(0,120)); }
          return r.json();
        })
        .then(showDD)
        .catch(()=>{
          // 2) Fallback admin-ajax
          const fd = new FormData();
          fd.append('action','co360_user_suggest');
          fd.append('nonce', CO360AnalyticsGlobal.nonce);
          fd.append('q', q);
          fetch(CO360AnalyticsGlobal.ajax, { method:'POST', credentials:'same-origin', body: fd })
          .then(async r=>{ if(!r.ok){ const t=await r.text(); throw new Error('AJAX '+r.status+': '+t.slice(0,120)); } return r.json(); })
          .then(res=>{ if(res && res.success){ showDD(res.data||[]); } else { dd.innerHTML='<div style="padding:8px;color:#b00">Error en búsqueda.</div>'; } })
          .catch(err=>{ dd.innerHTML='<div style="padding:8px;color:#b00">Error: '+(err.message||'')+'</div>'; });
        });
      }

      function fetchStats(){
        if(!picked) return;
        if (grp) grp.style.display = 'none';
        out.innerHTML='<p>Cargando…</p>';
        const url = CO360AnalyticsGlobal.restUserStats
                  + `?user_id=${encodeURIComponent(picked.id)}`
                  + `&date_from=${encodeURIComponent(fromEl.value||'')}`
                  + `&date_to=${encodeURIComponent(toEl.value||'')}`;
        fetch(url, {headers:{'X-WP-Nonce':CO360AnalyticsGlobal.nonce}, credentials:'same-origin'})
          .then(r=>r.json()).then(renderStats).catch(()=>{ out.innerHTML='<p>Error</p>'; });
      }

      function renderStats(payload){
        out.innerHTML = '';

        // Tarjetas
        const cards = document.createElement('div'); cards.className='co360-cards';
        cards.innerHTML = `
          <div class="co360-card"><strong>Total Visualizaciones Slide Kit</strong><span>${(payload.totals.view_slidekit||0)}</span></div>
          <div class="co360-card"><strong>Total Visualizaciones Highlights</strong><span>${(payload.totals.view_highlights||0)}</span></div>
          <div class="co360-card"><strong>Total Descargas PPT</strong><span>${(payload.totals.download_ppt||0)}</span></div>`;
        out.appendChild(cards);

        // Gráfica (altura 420px)
        const rows   = payload.per_post || [];
        const labels = rows.map(r => r.title);
        const dsSK   = rows.map(r => parseInt(r.view_slidekit||0,10));
        const dsHL   = rows.map(r => parseInt(r.view_highlights||0,10));
        const dsPPT  = rows.map(r => parseInt(r.download_ppt||0,10));

        const chartBox = document.createElement('div');
        chartBox.className = 'co360-chartbox';
        chartBox.style.height = '420px';
        chartBox.style.position = 'relative';
        chartBox.style.marginTop = '16px';

        const canvas = document.createElement('canvas');
        canvas.id = 'co360UserPickedBar';
        chartBox.appendChild(canvas);
        out.appendChild(chartBox);

        if (window.Chart) {
          if (window.__co360UserPickedChart && typeof window.__co360UserPickedChart.destroy === 'function'){
            window.__co360UserPickedChart.destroy();
          }
          window.__co360UserPickedChart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
              labels,
              datasets: [
                { label: 'Slide Kit',  data: dsSK },
                { label: 'Highlights', data: dsHL },
                { label: 'PPT',        data: dsPPT }
              ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } }, scales: { y: { beginAtZero: true } } }
          });
        } else {
          const warn = document.createElement('p'); warn.style.color = '#b00'; warn.textContent = 'No se pudo cargar Chart.js';
          out.appendChild(warn);
        }



        // Tabla
        const wrap = document.createElement('div'); wrap.className = 'co360-table-wrap';
const table = document.createElement('table');
table.className = 'co360-table';
table.id = 'co360UserInsightsTable';        table.innerHTML = '<thead><tr><th>Título</th><th>Total Visualizaciones Slide Kit</th><th>Total Visualizaciones Highlights</th><th>Total Descargas PPT</th></tr></thead><tbody>'
          + rows.map(r=>`<tr><td><a href="${r.permalink}" target="_blank">${r.title}</a></td><td>${r.view_slidekit||0}</td><td>${r.view_highlights||0}</td><td>${r.download_ppt||0}</td></tr>`).join('')
          + '</tbody>';
        wrap.appendChild(table);
        out.appendChild(wrap);
        
       // --- Activar DataTables cuando la tabla ya está en el DOM ---
if (window.jQuery && jQuery.fn.DataTable) {
  var $t = jQuery('#co360UserInsightsTable');

  // Si ya había una tabla anterior, destruir DataTable anterior
  if ($t.hasClass('dataTable')) {
    $t.DataTable().destroy();
  }

  $t.DataTable({
    dom: 'Blfrtip',
    colReorder: false,
    ordering: true,
    pageLength: 25,
    lengthMenu: [[10,25,50,100,-1],[10,25,50,100,"Todos"]],
    language: {
      url: 'https://cdn.datatables.net/plug-ins/2.1.8/i18n/es-ES.json'
    },
    buttons: [
        {
          extend: 'csvHtml5',
          title: 'AlterAgora Estadísticas Usuario',
        exportOptions: { columns: ':visible' }
      },
        {
          extend: 'excelHtml5',
          title: 'AlterAgora Estadísticas Usuario',
        exportOptions: { columns: ':visible' }
      },
      {
        extend: 'colvis',
        text: 'Columnas'
      }
    ]
  });
}


        // Botonera superior (si la quieres usar)
        if (grp && picked && window.CO360Export) {
          grp.style.display = 'flex';
          const base = {
            scope: 'user',
            user_id: picked.id,
            date_from: (fromEl && fromEl.value) ? fromEl.value : '',
            date_to: (toEl && toEl.value) ? toEl.value : ''
          };
          btnCSV.onclick = function(){ CO360Export(Object.assign({}, base, {format:'csv'})); };
          btnXLS.onclick = function(){ CO360Export(Object.assign({}, base, {format:'xls'})); };
        }
      }

      input.addEventListener('input', function(){
        const q=this.value.trim();
        clearTimeout(timer);
        timer=setTimeout(()=>fetchUsers(q), 200);
      });
      document.addEventListener('click', (e)=>{ if(!dd.contains(e.target) && e.target!==input) dd.style.display='none'; });
      apply.addEventListener('click', (e)=>{ e.preventDefault(); fetchStats(); });
    })();
    </script>
    <?php
    return ob_get_clean();
}



    /* ========== Admin / Export (crudo) ========== */
    public function admin_menu(){
        add_menu_page('CO360 Analytics','CO360 Analytics','manage_options','co360-analytics',[$this,'admin_page'],'dashicons-chart-bar',65);
    }
    public function admin_page(){ ?>
        <div class="wrap">
            <h1>CO360 Analytics</h1>
            <p>Shortcodes:</p>
            <ul>
                <li><code>[co360_switcher ...]</code> — selector de PDFs/PPT con tracking.</li>
                <li><code>[co360_pdf_viewer url="..." type="slidekit|highlights"]</code></li>
                <li><code>[co360_ppt_download url="..." label="Descargar PPT"]</code></li>
                <li><code>[co360_user_analytics]</code>, <code>[co360_global_analytics]</code></li>
                <li><code>[co360_user_export]</code> — tabla exportable con toda la analítica por usuario.</li>
            </ul>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="co360_export_csv">
                <input type="hidden" name="scope" value="raw">
                <?php wp_nonce_field('co360_export_global'); ?>
                <button class="button button-primary">Exportar eventos crudos (CSV)</button>
            </form>
        </div>
    
    



    
    
    <?php }

    public function handle_export_csv(){
        $scope = sanitize_text_field($_POST['scope'] ?? '');
        check_admin_referer('co360_export_global');
        if ($scope==='raw') $this->stream_csv_raw();
        exit;
    }
    private function stream_csv_raw(){
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="alteragora_raw_'.date('Ymd_His').'.csv"');
        $out = fopen('php://output','w');
        fputcsv($out, ['Fecha','User ID','Post ID','Acción','Source','IP','Session']);
        global $wpdb; $table=$wpdb->prefix.self::TABLE;
        $rows = $wpdb->get_results("SELECT created_at,user_id,post_id,action,source,INET6_NTOA(ip) ip,session_id FROM {$table} ORDER BY created_at DESC LIMIT 20000");
        foreach($rows as $r){ fputcsv($out,[$r->created_at,$r->user_id,$r->post_id,$r->action,$r->source,$r->ip,$r->session_id]); }
        fclose($out); exit;
    }
    
    public function handle_delete_anon_stats(){
    if ( ! current_user_can('manage_options') ) wp_die('No autorizado');
    check_admin_referer('co360_delete_anon_stats');

    $from = isset($_POST['date_from']) ? sanitize_text_field( wp_unslash($_POST['date_from']) ) : '';
    $to   = isset($_POST['date_to'])   ? sanitize_text_field( wp_unslash($_POST['date_to']) )   : '';

    list($ds, $pp) = $this->build_date_sql($from, $to);

    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;

    if ($ds) {
        // Dentro de rango
        $wpdb->query( $wpdb->prepare("DELETE FROM {$table} WHERE user_id IS NULL {$ds}", $pp) );
    } else {
        // Sin rango -> borra todos los anónimos
        $wpdb->query( "DELETE FROM {$table} WHERE user_id IS NULL" );
    }

    $msg = rawurlencode('Anónimos borrados correctamente.');
    wp_safe_redirect( add_query_arg(['page'=>'co360-analytics-tools','co360_msg'=>$msg], admin_url('admin.php')) );
    exit;
}

    
    public function handle_export_selected(){
    if ( ! current_user_can('manage_options') ) wp_die('No autorizado');
    check_admin_referer('co360_export_selected','_wpnonce_export');

    $ids = isset($_POST['user_ids']) ? array_filter(array_map('absint',(array)$_POST['user_ids'])) : [];
    $from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
    $to   = isset($_POST['date_to'])   ? sanitize_text_field(wp_unslash($_POST['date_to']))   : '';

    if (empty($ids)) wp_die('Selecciona al menos un usuario.');

    global $wpdb; $table = $wpdb->prefix . self::TABLE;

    list($ds,$pp) = $this->build_date_sql($from,$to);
    $place = implode(',', array_fill(0, count($ids), '%d'));

    $sql = "SELECT created_at,user_id,post_id,action,source,INET6_NTOA(ip) ip,session_id
            FROM {$table}
            WHERE user_id IN ($place) {$ds}
            ORDER BY user_id, created_at DESC";

    $params = array_merge($ids, $pp);
    $rows = $wpdb->get_results( $wpdb->prepare($sql, $params) );

    // Mapa de usuarios para display/email
    $users = [];
    foreach ($ids as $uid){
        $u = get_user_by('id', $uid);
        if ($u) $users[$uid] = [$u->display_name, $u->user_email];
    }

    // CSV Excel-friendly (UTF-8 BOM, separador coma)
    $filename = 'co360_selected_'.date('Ymd_His').'.csv';
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');

    $out = fopen('php://output','w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM
    fputcsv($out, ['Fecha','User ID','Nombre','Email','Post ID','Título','Acción','Source','IP','Session']);

    foreach ((array)$rows as $r){
        $title = $r->post_id ? get_the_title((int)$r->post_id) : '';
        $disp  = isset($users[$r->user_id]) ? $users[$r->user_id][0] : '';
        $mail  = isset($users[$r->user_id]) ? $users[$r->user_id][1] : '';
        fputcsv($out, [
            $r->created_at,
            (int)$r->user_id,
            $disp,
            $mail,
            (int)$r->post_id,
            $title,
            $r->action,
            $r->source,
            (string)$r->ip,
            $r->session_id
        ]);
    }
    fclose($out);
    exit;
}

public function handle_delete_user_stats(){
    if ( ! current_user_can('manage_options') ) wp_die('No autorizado.');
    check_admin_referer('co360_delete_user_stats', '_wpnonce_delete');

    // Usuarios seleccionados
    $user_ids = isset($_POST['user_ids']) ? (array) $_POST['user_ids'] : [];
    $user_ids = array_values( array_filter( array_map('absint', $user_ids) ) );

    if ( empty($user_ids) ){
        $err = rawurlencode('No se han seleccionado usuarios.');
        wp_safe_redirect( add_query_arg(['page'=>'co360-analytics-tools','co360_err'=>$err], admin_url('admin.php')) );
        exit;
    }

    // Rango opcional
    $from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
    $to   = isset($_POST['date_to'])   ? sanitize_text_field(wp_unslash($_POST['date_to']))   : '';
    list($ds,$pp) = $this->build_date_sql($from,$to); // $ds empieza con ' AND ...' o vacío

    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;

    // DELETE WHERE user_id IN (...) [AND rango]
    $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
    $sql = "DELETE FROM {$table} WHERE user_id IN ($placeholders)".$ds;
    $params = array_merge($user_ids, $pp);

    $wpdb->query( $pp ? $wpdb->prepare($sql, $params) : $wpdb->prepare($sql, $user_ids) );

    $msg = rawurlencode( $ds ? 'Estadísticas eliminadas para los usuarios seleccionados en el rango indicado.' 
                             : 'Estadísticas eliminadas para los usuarios seleccionados.' );
    wp_safe_redirect( add_query_arg(['page'=>'co360-analytics-tools','co360_msg'=>$msg], admin_url('admin.php')) );
    exit;
}


public function handle_wipe_all_stats(){
    if ( ! current_user_can('manage_options') ) wp_die('No autorizado');
    check_admin_referer('co360_wipe_stats');

    $from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
    $to   = isset($_POST['date_to'])   ? sanitize_text_field(wp_unslash($_POST['date_to']))   : '';

    global $wpdb; 
    $table = $wpdb->prefix . self::TABLE;

    // Usa el helper de rango para construir fecha + params
    list($ds,$pp) = $this->build_date_sql($from,$to);

    if ($ds){
        // Wipe dentro del rango
        $wpdb->query( $wpdb->prepare("DELETE FROM {$table} WHERE 1=1 {$ds}", $pp) );
    } else {
        // Sin rango -> wipe total
        $wpdb->query("TRUNCATE TABLE {$table}");
    }

    wp_safe_redirect(
        admin_url(
            'admin.php?page=co360-analytics-tools&wiped=1'
            .'&date_from='.urlencode($from)
            .'&date_to='.urlencode($to)
        )
    );
    exit;
}


    
    /* ========== Helpers de TAX filtros (JOIN dinámico) ========== */
private function build_tax_filter_sql( $tax = '', $term_ids_csv = '' ) {
    $tax = sanitize_key( $tax );
    $term_ids = array_filter( array_map( 'absint', explode( ',', (string)$term_ids_csv ) ) );
    if ( ! $tax || empty( $term_ids ) ) {
        return [ '', '', [] ]; // join_sql, where_sql, params
    }
    global $wpdb;
    $join = " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
              INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id ";
    $where = " AND tt.taxonomy = %s AND tt.term_id IN (" . implode( ',', array_fill( 0, count( $term_ids ), '%d' ) ) . ") ";
    $params = array_merge( [ $tax ], $term_ids );
    return [ $join, $where, $params ];
}

/* ========== Leaderboards ========== */
private function get_leaderboards( $from = '', $to = '', $tax = '', $term_ids_csv = '' ) {
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;

    // Rango fechas
    list( $date_sql, $date_params ) = $this->build_date_sql( $from, $to );

    // Filtro tax
    list( $join_tax, $where_tax, $tax_params ) = $this->build_tax_filter_sql( $tax, $term_ids_csv );

    // ❌ OJO: aquí NO aplicamos exclusiones para el panel global

    // Top por acción (posts)
    $mkTopSql = function( $action ) use ( $wpdb, $table, $date_sql, $date_params, $join_tax, $where_tax, $tax_params ) {
        $sql = "
            SELECT e.post_id, COUNT(*) c
            FROM {$table} e
            INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id
            {$join_tax}
            WHERE e.action = %s
              AND e.post_id IS NOT NULL
              {$date_sql} {$where_tax}
            GROUP BY e.post_id
            ORDER BY c DESC
            LIMIT 10";

        $params = array_merge( [ $action ], $date_params, $tax_params );
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    };

    $top_slidekit   = $mkTopSql('view_slidekit');
    $top_highlights = $mkTopSql('view_highlights');
    $top_ppt        = $mkTopSql('download_ppt');

    // Ratio PPT / Slidekit por post
    $sqlRatio = "
        SELECT 
            e.post_id,
            SUM(CASE WHEN e.action='download_ppt'  THEN 1 ELSE 0 END) AS ppt,
            SUM(CASE WHEN e.action='view_slidekit' THEN 1 ELSE 0 END) AS sk,
            (CAST(SUM(CASE WHEN e.action='download_ppt'  THEN 1 ELSE 0 END) AS DECIMAL(18,6))
             / NULLIF(CAST(SUM(CASE WHEN e.action='view_slidekit' THEN 1 ELSE 0 END) AS DECIMAL(18,6)), 0)
            ) AS ratio
        FROM {$table} e
        INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id
        {$join_tax}
        WHERE e.post_id IS NOT NULL {$date_sql} {$where_tax}
        GROUP BY e.post_id
        HAVING sk > 0
        ORDER BY ratio DESC
        LIMIT 10
    ";

    $params_ratio = array_merge( $date_params, $tax_params );
    $ratio_rows = ! empty( $params_ratio )
        ? $wpdb->get_results( $wpdb->prepare( $sqlRatio, $params_ratio ) )
        : $wpdb->get_results( $sqlRatio );

    // Top usuarios por total y por PPT
    $sqlTU = "
      SELECT e.user_id, COUNT(*) c
      FROM {$table} e
      INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id
      {$join_tax}
      WHERE e.user_id IS NOT NULL {$date_sql} {$where_tax}
      GROUP BY e.user_id
      ORDER BY c DESC
      LIMIT 10";
    $top_users_total = $wpdb->get_results(
        $wpdb->prepare( $sqlTU, array_merge( $date_params, $tax_params ) )
    );

    $sqlUP = "
      SELECT e.user_id, COUNT(*) c
      FROM {$table} e
      INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id
      {$join_tax}
      WHERE e.user_id IS NOT NULL
        AND e.action='download_ppt'
        {$date_sql} {$where_tax}
      GROUP BY e.user_id
      ORDER BY c DESC
      LIMIT 10";
    $top_users_ppt = $wpdb->get_results(
        $wpdb->prepare( $sqlUP, array_merge( $date_params, $tax_params ) )
    );

    // Empaquetado con títulos/permalinks/display
    $fmtPostList = function( $rows ) {
        $o = [];
        foreach ( (array) $rows as $r ) {
            $o[] = [
                'post_id'   => (int) $r->post_id,
                'title'     => get_the_title( $r->post_id ),
                'permalink' => get_permalink( $r->post_id ),
                'count'     => (int) ( $r->c ?? 0 ),
                'ppt'       => isset($r->ppt) ? (int)$r->ppt : null,
                'sk'        => isset($r->sk)  ? (int)$r->sk  : null,
                'ratio'     => ( isset($r->ppt) && isset($r->sk) && $r->sk>0 )
                                ? round($r->ppt / $r->sk, 3)
                                : null,
            ];
        }
        return $o;
    };

    $fmtUserList = function( $rows ) {
        $o = [];
        foreach ( (array) $rows as $r ) {
            $u = get_user_by( 'id', (int) $r->user_id );
            if ( ! $u ) continue;
            $o[] = [
                'user_id' => (int) $u->ID,
                'display' => $u->display_name,
                'email'   => $u->user_email,
                'count'   => (int) $r->c,
            ];
        }
        return $o;
    };

    return [
        'ratio_ppt_per_sk' => $fmtPostList( $ratio_rows ),
        'top_slidekit'     => $fmtPostList( $top_slidekit ),
        'top_highlights'   => $fmtPostList( $top_highlights ),
        'top_ppt'          => $fmtPostList( $top_ppt ),
        'top_users_total'  => $fmtUserList( $top_users_total ),
        'top_users_ppt'    => $fmtUserList( $top_users_ppt ),
    ];
}


    
    
    

    /* ========== Data Layer ========== */
    
    /* ========== Exclusion Handlers ========== */

public function handle_update_exclusions(){
    if ( ! current_user_can('manage_options') ) wp_die('No autorizado.');
    check_admin_referer('co360_update_exclusions');

    $ids = isset($_POST['user_ids']) ? (array) $_POST['user_ids'] : [];
    $ids = array_values( array_filter( array_map('absint', $ids) ) );

    $current = get_option('co360_excluded_users', []);
    $current = is_array($current) ? $current : [];
    $new = array_values( array_unique( array_merge($current, $ids) ) );

    update_option('co360_excluded_users', $new, false);

    wp_safe_redirect( add_query_arg(['page'=>'co360-analytics-tools','co360_msg'=>rawurlencode('Lista actualizada.')], admin_url('admin.php')) );
    exit;
}



public function handle_remove_exclusions(){
    if ( ! current_user_can('manage_options') ) wp_die('No autorizado.');
    check_admin_referer('co360_remove_exclusions');

    $rm = isset($_POST['remove_ids']) ? (array) $_POST['remove_ids'] : [];
    $rm = array_values( array_filter( array_map('absint', $rm) ) );

    $current = get_option('co360_excluded_users', []);
    $current = is_array($current) ? $current : [];

    if (!empty($rm)){
        $left = array_values( array_diff($current, $rm) );
        update_option('co360_excluded_users', $left, false);
    }

    wp_safe_redirect( add_query_arg(['page'=>'co360-analytics-tools','co360_msg'=>rawurlencode('Usuarios quitados de la exclusión.')], admin_url('admin.php')) );
    exit;
}




    private function build_date_sql($from='',$to=''){ $c=[];$p=[]; if($from){$c[]='created_at >= %s';$p[]=$from.' 00:00:00';} if($to){$c[]='created_at <= %s';$p[]=$to.' 23:59:59';} return [$c?(' AND '.implode(' AND ',$c)):'',$p]; }
    private function get_user_totals($uid,$f='',$t=''){ global $wpdb;$table=$wpdb->prefix.self::TABLE; list($ds,$pp)=$this->build_date_sql($f,$t); array_unshift($pp,$uid); $rows=$wpdb->get_results($wpdb->prepare("SELECT action,COUNT(*) c FROM {$table} WHERE user_id=%d {$ds} GROUP BY action",$pp)); $o=[]; foreach($rows as $r){$o[$r->action]=(int)$r->c;} return $o; }
    private function get_user_per_post($uid,$f='',$t=''){ global $wpdb;$table=$wpdb->prefix.self::TABLE; list($ds,$pp)=$this->build_date_sql($f,$t); array_unshift($pp,$uid); $sql=$wpdb->prepare("SELECT post_id,
        SUM(CASE WHEN action='view_slidekit' THEN 1 ELSE 0 END) AS view_slidekit,
        SUM(CASE WHEN action='view_highlights' THEN 1 ELSE 0 END) AS view_highlights,
        SUM(CASE WHEN action='download_ppt' THEN 1 ELSE 0 END) AS download_ppt
        FROM {$table} WHERE user_id=%d AND post_id IS NOT NULL {$ds}
        GROUP BY post_id ORDER BY MAX(created_at) DESC LIMIT 200",$pp);
        return $wpdb->get_results($sql);
    }

    private function get_all_user_stats( $from = '', $to = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        list( $date_sql, $date_params ) = $this->build_date_sql( $from, $to );

        $total_sql = "SELECT user_id,
                SUM(CASE WHEN action='view_slidekit' THEN 1 ELSE 0 END) AS view_slidekit,
                SUM(CASE WHEN action='view_highlights' THEN 1 ELSE 0 END) AS view_highlights,
                SUM(CASE WHEN action='download_ppt' THEN 1 ELSE 0 END) AS download_ppt
            FROM {$table}
            WHERE user_id IS NOT NULL {$date_sql}
            GROUP BY user_id";

        $totals_rows = ! empty( $date_params )
            ? $wpdb->get_results( $wpdb->prepare( $total_sql, $date_params ) )
            : $wpdb->get_results( $total_sql );

        $per_post_sql = "SELECT user_id, post_id,
                SUM(CASE WHEN action='view_slidekit' THEN 1 ELSE 0 END) AS view_slidekit,
                SUM(CASE WHEN action='view_highlights' THEN 1 ELSE 0 END) AS view_highlights,
                SUM(CASE WHEN action='download_ppt' THEN 1 ELSE 0 END) AS download_ppt
            FROM {$table}
            WHERE user_id IS NOT NULL AND post_id IS NOT NULL {$date_sql}
            GROUP BY user_id, post_id";

        $per_post_rows = ! empty( $date_params )
            ? $wpdb->get_results( $wpdb->prepare( $per_post_sql, $date_params ) )
            : $wpdb->get_results( $per_post_sql );

        $totals = [];
        foreach ( (array) $totals_rows as $r ) {
            $uid            = (int) $r->user_id;
            $totals[ $uid ] = [
                'view_slidekit'  => (int) $r->view_slidekit,
                'view_highlights'=> (int) $r->view_highlights,
                'download_ppt'   => (int) $r->download_ppt,
            ];
        }

        $per_post = [];
        foreach ( (array) $per_post_rows as $r ) {
            $uid = (int) $r->user_id;
            $per_post[ $uid ][] = [
                'post_id'        => (int) $r->post_id,
                'title'          => get_the_title( $r->post_id ),
                'view_slidekit'  => (int) $r->view_slidekit,
                'view_highlights'=> (int) $r->view_highlights,
                'download_ppt'   => (int) $r->download_ppt,
            ];
        }

        $user_ids = array_unique( array_merge( array_keys( $totals ), array_keys( $per_post ) ) );
        $meta_keys = [ 'user_especialidad', 'user_centro', 'user_poblacion', 'user_provincia', 'user_nif', 'user_cp' ];

        $out = [];
        foreach ( $user_ids as $uid ) {
            $u = get_user_by( 'id', $uid );
            if ( ! $u ) {
                continue;
            }

            $meta = [];
            foreach ( $meta_keys as $mk ) {
                $meta[ $mk ] = get_user_meta( $uid, $mk, true );
            }

            $out[] = [
                'user_id'    => $uid,
                'first_name' => get_user_meta( $uid, 'first_name', true ),
                'last_name'  => get_user_meta( $uid, 'last_name', true ),
                'email'      => $u->user_email,
                'registered' => mysql2date( 'd/m/Y', $u->user_registered ),
                'meta'       => $meta,
                'totals'     => [
                    'view_slidekit'   => (int) ( $totals[ $uid ]['view_slidekit'] ?? 0 ),
                    'view_highlights' => (int) ( $totals[ $uid ]['view_highlights'] ?? 0 ),
                    'download_ppt'    => (int) ( $totals[ $uid ]['download_ppt'] ?? 0 ),
                ],
                'per_post'   => $per_post[ $uid ] ?? [],
            ];
        }

        return $out;
    }

private function get_global_totals( $from = '', $to = '', $tax = '', $term_ids = '' ) {
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;

    // Rango de fechas
    list( $date_sql, $date_params ) = $this->build_date_sql( $from, $to );

    // Filtro por taxonomía (categorías, etc.)
    list( $join_tax, $where_tax, $tax_params ) = $this->build_tax_filter_sql( $tax, $term_ids );

    // ⚠️ Aquí NO usamos exclusiones: los totales globales del shortcode deben contar todo
    if ( empty( $join_tax ) ) {
        // Sin taxonomía: cuenta todos los eventos (con rango de fechas si lo hay)
        $sql = "SELECT action, COUNT(*) c
                FROM {$table} e
                WHERE 1=1 {$date_sql}
                GROUP BY action";

        $params = $date_params;
    } else {
        // Con taxonomía: solo eventos vinculados a posts en esas categorías
        $sql = "SELECT e.action, COUNT(*) c
                FROM {$table} e
                INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id
                {$join_tax}
                WHERE 1=1 {$date_sql} {$where_tax}
                GROUP BY e.action";

        $params = array_merge( $date_params, $tax_params );
    }

    $rows = ! empty( $params )
        ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) )
        : $wpdb->get_results( $sql );

    $out = [];
    foreach ( (array) $rows as $r ) {
        $out[ $r->action ] = (int) $r->c;
    }

    return $out;
}






private function get_global_per_post( $from = '', $to = '', $tax = '', $term_ids = '' ) {
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;

    // Rango de fechas
    list( $date_sql, $date_params ) = $this->build_date_sql( $from, $to );

    // Filtro por taxonomía (si se usa)
    list( $join_tax, $where_tax, $tax_params ) = $this->build_tax_filter_sql( $tax, $term_ids );

    // ❌ IMPORTANTE: aquí NO aplicamos exclusiones para el panel global del frontend

    $sql = "SELECT e.post_id,
                SUM(CASE WHEN e.action='view_slidekit'   THEN 1 ELSE 0 END) AS view_slidekit,
                SUM(CASE WHEN e.action='view_highlights' THEN 1 ELSE 0 END) AS view_highlights,
                SUM(CASE WHEN e.action='download_ppt'    THEN 1 ELSE 0 END) AS download_ppt,
                (  SUM(CASE WHEN e.action='view_slidekit'   THEN 1 ELSE 0 END)
                 + SUM(CASE WHEN e.action='view_highlights' THEN 1 ELSE 0 END)
                 + SUM(CASE WHEN e.action='download_ppt'    THEN 1 ELSE 0 END)
                ) AS total_interactions
            FROM {$table} e
            INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id
            {$join_tax}
            WHERE e.post_id IS NOT NULL {$date_sql} {$where_tax}
            GROUP BY e.post_id
            ORDER BY total_interactions DESC
            LIMIT 500";

    $params = array_merge( $date_params, $tax_params );

    $rows = ! empty( $params )
        ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) )
        : $wpdb->get_results( $sql );

    return $rows;
}




    private function current_user_has_any_role($roles){ $u=wp_get_current_user(); if(!$u||empty($u->roles))return false; $roles=array_filter(array_map('trim',(array)$roles)); return (bool) array_intersect($roles,(array)$u->roles); }
    private function sanitize_deep($a){ foreach($a as $k=>$v){ $a[$k]=is_array($v)?$this->sanitize_deep($v):(is_string($v)?sanitize_text_field($v):$v);} return $a; }
    private function inet_pton($ip){ return function_exists('inet_pton')?(@inet_pton($ip)?:null):null; }
}


