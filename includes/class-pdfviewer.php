<?php
if (!defined('ABSPATH')) exit;

class CO360_Suite_PDFViewer {
    public function shortcode_pdf_viewer($atts=[]){
        $a = shortcode_atts([
            'url' => '',
            'post_id' => 0,
            'type' => 'slidekit', // slidekit | highlights
            'scale'=> 1.5,
        ], $atts, 'co360_pdf_viewer');

        if (!$a['url']) return '<p>Falta la URL del PDF.</p>';
        $post_id = absint($a['post_id']) ?: get_the_ID();
        $type = ($a['type']==='highlights') ? 'highlights' : 'slidekit';
        $uid  = uniqid('co360_', false);

        // Usamos PDF.js o iframe nativo: aquí dejamos <iframe> por simplicidad y compatibilidad
        ob_start(); ?>
        <div class="co360-pdfwrap">
            <iframe src="<?php echo esc_url($a['url']); ?>" loading="lazy"></iframe>
        </div>
        <?php
        return ob_get_clean();
    }
}
