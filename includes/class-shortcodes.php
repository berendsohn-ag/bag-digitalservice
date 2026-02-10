<?php
namespace BDS;

if ( ! defined( 'ABSPATH' ) ) exit;

class Shortcodes {

    /**
     * TXT-Ordner im Plugin-Root: /texts/
     */
    const TEXT_DIR_REL = 'texts/';

    /**
     * Optionaler Prefix für alle Shortcodes
     */
    const SHORTCODE_PREFIX = ''; // z.B. 'bds_'

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

        // 1) Shortcodes registrieren (OHNE CACHE)
        $map = self::shortcode_map_auto_no_cache();

        foreach ( $map as $shortcode => $filename ) {
            add_shortcode( $shortcode, function( $atts = [] ) use ( $filename ) {
                // Wichtig: nur Dateiname übergeben (Helpers hängt texts/ selbst an)
                return Helpers::read_text( $filename );
            } );
        }

        // 2) Public CSS
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_public' ] );

        // 3) AJAX Liste für Editor-Picker
        add_action( 'wp_ajax_bds_shortcodes_list', [ __CLASS__, 'ajax_shortcodes_list' ] );

        // 4) JS für Button laden (Admin + Block-Editor + Builder-Frame)
        add_action( 'admin_enqueue_scripts',        [ __CLASS__, 'enqueue_editor_picker_script' ] );
        add_action( 'enqueue_block_editor_assets',  [ __CLASS__, 'enqueue_editor_picker_script' ] ); // ✅ wichtig für Gutenberg
        add_action( 'wp_enqueue_scripts',           [ __CLASS__, 'enqueue_editor_picker_script_frontend' ], 20 );
    }

    public static function enqueue_public() {
        wp_enqueue_style( 'bds-public', BDS_URL . 'assets/css/public.css', [], BDS_VERSION );
    }

    /**
     * Für JS: Liste der vorhandenen Shortcodes (OHNE CACHE)
     */
    public static function get_available_shortcodes() {
        $map = self::shortcode_map_auto_no_cache();
        return array_keys( $map );
    }

    /**
     * AJAX: Liefert Shortcode-Liste
     *
     * ✅ Fix: Nonce nur prüfen, wenn er wirklich mitgeschickt wurde.
     * (Builder/Frames verlieren manchmal wp_localize_script → nonce ist leer → sonst tot)
     */
    public static function ajax_shortcodes_list() {

        // Rechte zuerst
        if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        // Nonce optional (nur prüfen wenn vorhanden)
        if ( isset($_POST['nonce']) && $_POST['nonce'] !== '' ) {
            check_ajax_referer( 'bds_shortcodes_list', 'nonce' );
        }

        $list = self::get_available_shortcodes();
        sort( $list );

        wp_send_json_success( [
            'shortcodes' => $list,
        ] );
    }

    /**
     * Script im wp-admin + Block-Editor laden
     * Erwartet: assets/js/yoo-shortcode-button.js
     */
    public static function enqueue_editor_picker_script() {

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
     * AUTO-SCAN OHNE CACHE (scandir ist robuster als glob)
     *
     * Ergebnis: [ 'datenschutz' => 'datenschutz.txt', ... ]
     */
    private static function shortcode_map_auto_no_cache() {

        $base_dir = trailingslashit( BDS_PATH ) . self::TEXT_DIR_REL;

        if ( ! is_dir( $base_dir ) || ! is_readable( $base_dir ) ) {
            return [];
        }

        $entries = scandir( $base_dir );
        if ( ! is_array( $entries ) ) {
            return [];
        }

        $map = [];

        foreach ( $entries as $entry ) {

            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }

            // nur *.txt
            if ( ! preg_match( '/\.txt$/i', $entry ) ) {
                continue;
            }

            // Exclude
            if ( in_array( $entry, self::$exclude_files, true ) ) {
                continue;
            }

            // Allowlist (wenn gefüllt)
            if ( ! empty( self::$allow_files ) && ! in_array( $entry, self::$allow_files, true ) ) {
                continue;
            }

            // slug aus Dateiname (ohne .txt)
            $name = preg_replace( '/\.txt$/i', '', $entry );

            $slug = strtolower( $name );
            $slug = preg_replace( '/[^a-z0-9_-]/', '-', $slug );
            $slug = preg_replace( '/-+/', '-', $slug );
            $slug = trim( $slug, '-' );

            if ( $slug === '' ) {
                continue;
            }

            $shortcode = self::SHORTCODE_PREFIX . $slug;

            // wichtig: nur Dateiname
            $map[ $shortcode ] = $entry;
        }

        ksort( $map );

        return $map;
    }
}
