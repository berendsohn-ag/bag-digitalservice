<?php
namespace BDS;

if ( ! defined( 'ABSPATH' ) ) exit;

class Shortcodes {

    /**
     * TXT-Ordner im Plugin-Root (laut deinem Screenshot)
     * /wp-content/plugins/dein-plugin/texts/
     */
    const TEXT_DIR_REL = 'texts/';

    /**
     * Optionaler Prefix für Shortcodes
     * '' => [datenschutz]
     * 'bds_' => [bds_datenschutz]
     */
    const SHORTCODE_PREFIX = '';

    /**
     * Cache Key
     */
    const CACHE_KEY = 'bds_shortcode_map';

    /**
     * Optional: Dateien ausschließen (nur Dateiname)
     */
    private static $exclude_files = [
        // 'README.txt',
    ];

    /**
     * Optional: Allowlist (leer = alle)
     */
    private static $allow_files = [
        // 'datenschutz.txt',
    ];

    public static function init() {

        // 1) Shortcodes registrieren
        $map = self::shortcode_map_auto_with_fallback();

        foreach ( $map as $shortcode => $filename ) {
            add_shortcode( $shortcode, function( $atts = [] ) use ( $filename ) {
                // WICHTIG: wir übergeben nur den Dateinamen (wie früher),
                // weil Helpers::read_text() sehr wahrscheinlich selbst "texts/" davor setzt.
                return Helpers::read_text( $filename );
            } );
        }

        // 2) Public CSS
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_public' ] );

        // 3) Cache invalidieren bei TXT Upload/Delete/Edit (Mediathek)
        add_action( 'add_attachment',    [ __CLASS__, 'maybe_flush_on_attachment_change' ] );
        add_action( 'delete_attachment', [ __CLASS__, 'maybe_flush_on_attachment_change' ] );
        add_action( 'edit_attachment',   [ __CLASS__, 'maybe_flush_on_attachment_change' ] );

        // 4) AJAX Liste für Editor-Picker
        add_action( 'wp_ajax_bds_shortcodes_list', [ __CLASS__, 'ajax_shortcodes_list' ] );

        // 5) JS für YOOtheme/TinyMCE Button laden (Admin + Builder-Frame)
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_editor_picker_script' ] );
        add_action( 'wp_enqueue_scripts',    [ __CLASS__, 'enqueue_editor_picker_script_frontend' ], 20 );
    }

    public static function enqueue_public() {
        wp_enqueue_style( 'bds-public', BDS_URL . 'assets/css/public.css', [], BDS_VERSION );
    }

    /**
     * Für JS: Liste der vorhandenen Shortcodes
     */
    public static function get_available_shortcodes() {
        $map = self::shortcode_map_auto_with_fallback();
        return array_keys( $map );
    }

    /**
     * AJAX: Liefert Shortcode-Liste
     */
    public static function ajax_shortcodes_list() {

        check_ajax_referer( 'bds_shortcodes_list', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        $list = self::get_available_shortcodes();
        if ( ! is_array( $list ) ) {
            $list = [];
        }

        sort( $list );

        wp_send_json_success( [
            'shortcodes' => $list,
        ] );
    }

    /**
     * Script im wp-admin laden
     * Erwartet: assets/js/yoo-shortcode-button.js
     */
    public static function enqueue_editor_picker_script() {

        if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) {
            return;
        }

        wp_enqueue_script(
            'bds-yoo-shortcode-button',
            BDS_URL . 'assets/js/yoo-shortcode-button.js',
            [ 'jquery' ],
            BDS_VERSION,
            true
        );

        wp_localize_script( 'bds-yoo-shortcode-button', 'BDSShortcodes', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'bds_shortcodes_list' ),
        ] );
    }

    /**
     * Script im Frontend laden (YOOtheme Builder läuft oft im Frontend-Frame)
     */
    public static function enqueue_editor_picker_script_frontend() {

        if ( ! is_user_logged_in() ) {
            return;
        }

        if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) {
            return;
        }

        wp_enqueue_script(
            'bds-yoo-shortcode-button',
            BDS_URL . 'assets/js/yoo-shortcode-button.js',
            [ 'jquery' ],
            BDS_VERSION,
            true
        );

        wp_localize_script( 'bds-yoo-shortcode-button', 'BDSShortcodes', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'bds_shortcodes_list' ),
        ] );
    }

    /**
     * Cache löschen
     */
    private static function flush_shortcode_cache() {
        delete_transient( self::CACHE_KEY );
    }

    /**
     * Cache flush, wenn eine TXT als Attachment geändert wird
     */
    public static function maybe_flush_on_attachment_change( $attachment_id ) {

        $file = get_attached_file( $attachment_id );
        if ( ! $file ) {
            return;
        }

        if ( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) !== 'txt' ) {
            return;
        }

        // Optional: nur flushen, wenn es im Plugin-Texts-Ordner liegt
        $dir_fragment = '/' . trim( str_replace( '\\', '/', self::TEXT_DIR_REL ), '/' ) . '/';
        $file_norm    = str_replace( '\\', '/', $file );

        if ( strpos( $file_norm, $dir_fragment ) === false ) {
            // Wenn du jede TXT überall zulassen willst: diese Prüfung entfernen.
            return;
        }

        self::flush_shortcode_cache();
    }

    /**
     * Auto-Map mit Cache + Fallback auf alte, feste Map (damit nie wieder alles "weg" ist)
     */
    private static function shortcode_map_auto_with_fallback() {

        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $map = self::shortcode_map_auto();

        // Wenn Scan nichts findet -> Fallback auf alte harte Map
        if ( empty( $map ) ) {
            $map = self::shortcode_map_fallback_static();
        }

        ksort( $map );

        set_transient( self::CACHE_KEY, $map, 12 * HOUR_IN_SECONDS );

        return $map;
    }

    /**
     * Scan texts/*.txt
     * Ergebnis: [ 'datenschutz' => 'datenschutz.txt', ... ]
     */
    private static function shortcode_map_auto() {

        $base_dir = trailingslashit( BDS_PATH ) . self::TEXT_DIR_REL;

        if ( ! is_dir( $base_dir ) ) {
            return [];
        }

        $files = glob( $base_dir . '*.txt' );
        if ( ! $files ) {
            return [];
        }

        $map = [];

        foreach ( $files as $abs_path ) {
            $filename = basename( $abs_path ); // z.B. datenschutz-googlemap.txt

            if ( in_array( $filename, self::$exclude_files, true ) ) {
                continue;
            }

            if ( ! empty( self::$allow_files ) && ! in_array( $filename, self::$allow_files, true ) ) {
                continue;
            }

            $name = preg_replace( '/\.txt$/i', '', $filename );

            $slug = strtolower( $name );
            $slug = preg_replace( '/[^a-z0-9_-]/', '-', $slug );
            $slug = preg_replace( '/-+/', '-', $slug );
            $slug = trim( $slug, '-' );

            if ( $slug === '' ) {
                continue;
            }

            $shortcode = self::SHORTCODE_PREFIX . $slug;

            // WICHTIG: Wert bleibt nur der Dateiname (wie früher)
            $map[ $shortcode ] = $filename;
        }

        return $map;
    }

    /**
     * Deine alte Map als Notfall-Fallback (falls Scan mal nix findet)
     */
    private static function shortcode_map_fallback_static() {
        return [
            // Impressum
            'impressum'                                              => 'impressum.txt',
            'impressum-englisch'                                     => 'impressum-englisch.txt',

            // Datenschutz
            'datenschutz'                                            => 'datenschutz.txt',
            'datenschutz-youtube'                                    => 'datenschutz-youtube.txt',
            'datenschutz-googlemap-youtube'                          => 'datenschutz-googlemap-youtube.txt',
            'datenschutz-googlemap'                                  => 'datenschutz-googlemap.txt',
            'datenschutz-googlemap-bewerbung'                        => 'datenschutz-googlemap-bewerbung.txt',
            'datenschutz-googlemap-googletagmanager'                 => 'datenschutz-googlemap-googletagmanager.txt',
            'datenschutz-ohne-formular-googlemap'                    => 'datenschutz-ohne-formular-googlemap.txt',
            'datenschutz-ohne-formular-wpstatistics'                 => 'datenschutz-ohne-formular-wpstatistics.txt',
            'datenschutz-ohne-formular'                              => 'datenschutz-ohne-formular.txt',
            'datenschutz-wpstatistics-googlemap-youtube'             => 'datenschutz-wpstatistics-googlemap-youtube.txt',
            'datenschutz-wpstatistics-googlemap'                     => 'datenschutz-wpstatistics-googlemap.txt',
            'datenschutz-googlemap-englisch'                         => 'datenschutz-googlemap-englisch.txt',
            'datenschutz-wpstatistics-googlemap-englisch'            => 'datenschutz-wpstatistics-googlemap-englisch.txt',
            'datenschutz-wpstatistics-youtube'                       => 'datenschutz-wpstatistics-youtube.txt',
            'datenschutz-wpstatistics'                               => 'datenschutz-wpstatistics.txt',

            // Datenschutz Borlabs / Google / Ads / Analytics
            'datenschutz-googlemap-borlabs'                          => 'datenschutz-googlemap-borlabs.txt',
            'datenschutz-borlabs-googletagmanager'                   => 'datenschutz-borlabs-googletagmanager.txt',
            'datenschutz-googlemap-borlabs-googletagmanager-googleads-googleanalytics'
                                                                      => 'datenschutz-googlemap-borlabs-googletagmanager-googleads-googleanalytics.txt',
            'datenschutz-borlabs-googletagmanager-googleanalytics-kunde'
                                                                      => 'datenschutz-borlabs-googletagmanager-googleanalytics-kunde.txt',
            'datenschutz-borlabs-googletagmanager-ohne-formular'     => 'datenschutz-borlabs-googletagmanager-ohne-formular.txt',
            'datenschutz-borlabs-googletagmanager-ohne-formular-englisch'
                                                                      => 'datenschutz-borlabs-googletagmanager-ohne-formular-englisch.txt',
            'datenschutz-googlemap-borlabs-googletagmanager-googleads'
                                                                      => 'datenschutz-googlemap-borlabs-googletagmanager-googleads.txt',
            'datenschutz-borlabs-googletagmanager-googleads'         => 'datenschutz-borlabs-googletagmanager-googleads.txt',
            'datenschutz-googlemap-borlabs-googletagmanager-googleads_wpstatistics'
                                                                      => 'datenschutz-googlemap-borlabs-googletagmanager-googleads-wpstatistics.txt',
            'datenschutz-borlabs-googletagmanager-googleads-wpstatistics'
                                                                      => 'datenschutz-borlabs-googletagmanager-googleads-wpstatistics.txt',
            'datenschutz-googlemap-borlabs-googleads'                => 'datenschutz-googlemap-borlabs-googleads.txt',
            'datenschutz-googlemap-borlabs-googletagmanager'         => 'datenschutz-googlemap-borlabs-googletagmanager.txt',
            'datenschutz-googlemap-facebook-borlabs'                 => 'datenschutz-googlemap-facebook-borlabs.txt',

            // Messenger & Chat
            'datenschutz-whatsapp'                                   => 'datenschutz-whatsapp.txt',
            'datenschutz-chatbot'                                    => 'datenschutz-chatbot.txt',
            'datenschutz-chatbot-googlemap'                          => 'datenschutz-chatbot-googlemap.txt',

            // Sonstiges
            'datenschutz-borlabs-planer-niels-loeser'                => 'datenschutz-borlabs-planer-niels-loeser.txt',
            'datenschutz-googlemap-umzugsrechner'                    => 'datenschutz-googlemap-umzugsrechner.txt',
            'datenschutz-groeger'                                    => 'datenschutz-groeger.txt',
        ];
    }
}
