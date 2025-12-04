<?php
/**
 * Plugin Name: CO360 Suite (Switcher + Analytics)
 * Description: Selector AJAX stateless (PDF/PPT) con tracking integrado + paneles de analítica y shortcodes.
 * Version: 2.1.0
 * Author: Comunicacion Online 360
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

define('CO360_SUITE_PATH', plugin_dir_path(__FILE__));
define('CO360_SUITE_URL',  plugin_dir_url(__FILE__));
define('CO360_SUITE_VER',  '2.1.0');

require_once CO360_SUITE_PATH . 'includes/class-analytics.php';
require_once CO360_SUITE_PATH . 'includes/class-pdfviewer.php';
require_once CO360_SUITE_PATH . 'includes/class-switcher.php';

add_action('plugins_loaded', function(){
    // Analytics primero (tabla + endpoints + hooks)
    $GLOBALS['co360_suite_analytics'] = new CO360_Suite_Analytics();
    // PDF viewer shortcodes standalone
    $GLOBALS['co360_suite_pdfviewer'] = new CO360_Suite_PDFViewer();
    // Switcher (usa hook de analytics)
    $GLOBALS['co360_suite_switcher']  = new CO360_Suite_Switcher();
});

// Carga assets comunes si hiciera falta en el futuro
