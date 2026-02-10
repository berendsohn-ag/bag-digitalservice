<?php
namespace BDS;

if ( ! defined( 'ABSPATH' ) ) exit;

class Admin_UI {
    const OPT_HIDE_POSTS_MENU    = 'bds_hide_posts_menu_global';
    const OPT_DISABLE_GUTENBERG  = 'bds_disable_gutenberg_global';
    const OPT_HIDE_COMMENTS_ALL  = 'bds_hide_comments_global'; // optionaler, separater Schalter

    public static function init() {
        // Einstellungen UI
        add_action( 'admin_menu', [ __CLASS__, 'add_settings_page' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );

        // Admin-Styles
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin' ] );

        // Beiträge-Menü ausblenden
        add_action( 'admin_menu', [ __CLASS__, 'maybe_hide_posts_menu' ], 99 );

        // Block-Editor global deaktivieren (falls aktiviert)
        if ( get_option( self::OPT_DISABLE_GUTENBERG, 0 ) ) {
            add_filter( 'use_block_editor_for_post_type', '__return_false', 100 );
            add_filter( 'use_block_editor_for_post', '__return_false', 100 );
        }

        // Kommentare automatisch verstecken, wenn Beiträge ausgeblendet,
        // ODER wenn eigener Schalter aktiv ist.
        if ( self::should_hide_comments() ) {
            self::wire_comment_disablers();
        }
    }

    /** CSS im Backend */
    public static function enqueue_admin() {
        wp_enqueue_style( 'bds-admin', BDS_URL . 'assets/css/admin.css', [], BDS_VERSION );
    }

    /** „Beiträge“-Menü (edit.php) für ALLE Nutzer ausblenden, falls aktiviert */
    public static function maybe_hide_posts_menu() {
        if ( get_option( self::OPT_HIDE_POSTS_MENU, 0 ) ) {
            remove_menu_page( 'edit.php' ); // Beiträge
            // Optional auch Untermenüs (Kategorien/Tags) ausblenden:
            // remove_submenu_page( 'edit.php', 'edit-tags.php?taxonomy=category' );
            // remove_submenu_page( 'edit.php', 'edit-tags.php?taxonomy=post_tag' );
        }
    }

    /** Sollen Kommentare versteckt/deaktiviert werden? */
    private static function should_hide_comments() : bool {
        return (bool) get_option( self::OPT_HIDE_POSTS_MENU, 0 ) || (bool) get_option( self::OPT_HIDE_COMMENTS_ALL, 0 );
    }

    /** Alle Hooks/Filter, die Kommentare im kompletten System deaktivieren/verstecken */
    private static function wire_comment_disablers() {
        // 1) Kommentare in Admin-Menü entfernen
        add_action( 'admin_menu', function () {
            remove_menu_page( 'edit-comments.php' );
        }, 99 );

        // 2) Admin Bar: Kommentarblase entfernen
        add_action( 'wp_before_admin_bar_render', function () {
            global $wp_admin_bar;
            if ( is_object( $wp_admin_bar ) ) {
                $wp_admin_bar->remove_menu( 'comments' );
            }
        }, 0 );

        // 3) Aufruf von wp-admin/edit-comments.php blocken/umleiten
        add_action( 'admin_init', function () {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ( is_admin() ) {
                // harte Variante: wenn direkt comments.php aufgerufen wird → Dashboard
                $pagenow = isset($GLOBALS['pagenow']) ? $GLOBALS['pagenow'] : '';
                if ( $pagenow === 'edit-comments.php' ) {
                    wp_safe_redirect( admin_url() );
                    exit;
                }
                // weich: falls ein Screen-Objekt vorhanden ist und „comments“ entspricht → umleiten
                if ( $screen && isset($screen->id) && $screen->id === 'edit-comments' ) {
                    wp_safe_redirect( admin_url() );
                    exit;
                }
            }
        } );

        // 4) Kommentare/Trackbacks systemweit schließen
        add_filter( 'comments_open', '__return_false', 9999 );
        add_filter( 'pings_open', '__return_false', 9999 );

        // 5) Existierende Kommentarlisten im Frontend leeren
        add_filter( 'comments_array', function( $comments ) {
            return [];
        }, 9999 );

        // 6) Unterstützung „comments“ für alle Post Types entfernen
        add_action( 'admin_init', function () {
            foreach ( get_post_types() as $type ) {
                // Nur entfernen, wenn der Typ Kommentare unterstützt
                if ( post_type_supports( $type, 'comments' ) ) {
                    remove_post_type_support( $type, 'comments' );
                    remove_post_type_support( $type, 'trackbacks' );
                }
            }
        }, 20 );

        // 7) Dashboard-Widget "Aktuelle Kommentare" entfernen
        add_action( 'wp_dashboard_setup', function () {
            remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
        }, 20 );

        // 8) Einstellungsseiten-Links „Diskussion“ optional ausblenden (rein kosmetisch)
        add_action( 'admin_menu', function () {
            // NICHT die ganze Settings-Seite killen (options-general.php), nur falls gewünscht:
            // remove_submenu_page( 'options-general.php', 'options-discussion.php' );
        }, 99 );
    }

    /* ---------- Einstellungen (UI) ---------- */

    public static function add_settings_page() {
        add_options_page(
            __( 'BDS Admin-Oberfläche', 'berendsohn-digitalservice' ),
            __( 'BDS Admin-Oberfläche', 'berendsohn-digitalservice' ),
            'manage_options',
            'bds-admin-ui',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function register_settings() {
        register_setting( 'bds_admin_ui', self::OPT_HIDE_POSTS_MENU, [
            'type' => 'boolean',
            'sanitize_callback' => [ __CLASS__, 'sanitize_checkbox' ],
            'default' => 0,
        ] );

        register_setting( 'bds_admin_ui', self::OPT_DISABLE_GUTENBERG, [
            'type' => 'boolean',
            'sanitize_callback' => [ __CLASS__, 'sanitize_checkbox' ],
            'default' => 0,
        ] );

        // optionaler, separater Schalter für Kommentare
        register_setting( 'bds_admin_ui', self::OPT_HIDE_COMMENTS_ALL, [
            'type' => 'boolean',
            'sanitize_callback' => [ __CLASS__, 'sanitize_checkbox' ],
            'default' => 0,
        ] );

        add_settings_section(
            'bds_admin_ui_section',
            __( 'Globale Schalter', 'berendsohn-digitalservice' ),
            function () {
                echo '<p>' . esc_html__( 'Diese Optionen gelten global für die gesamte Website und alle Benutzer.', 'berendsohn-digitalservice' ) . '</p>';
            },
            'bds-admin-ui'
        );

        add_settings_field(
            self::OPT_HIDE_POSTS_MENU,
            __( '„Beiträge“-Menü global ausblenden', 'berendsohn-digitalservice' ),
            function () {
                $v = (bool) get_option( self::OPT_HIDE_POSTS_MENU, 0 );
                echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_HIDE_POSTS_MENU ) . '" value="1" ' . checked( $v, true, false ) . ' /> ' .
                     esc_html__( 'Entfernt den Menüpunkt „Beiträge“ für alle Benutzer. (Kommentare werden automatisch ebenfalls ausgeblendet/deaktiviert.)', 'berendsohn-digitalservice' ) . '</label>';
            },
            'bds-admin-ui',
            'bds_admin_ui_section'
        );

        add_settings_field(
            self::OPT_HIDE_COMMENTS_ALL,
            __( 'Kommentare global deaktivieren (optional, unabhängig)', 'berendsohn-digitalservice' ),
            function () {
                $v = (bool) get_option( self::OPT_HIDE_COMMENTS_ALL, 0 );
                echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_HIDE_COMMENTS_ALL ) . '" value="1" ' . checked( $v, true, false ) . ' /> ' .
                     esc_html__( 'Versteckt/Deaktiviert Kommentare und Kommentar-Menüs systemweit – unabhängig von „Beiträge ausblenden“.', 'berendsohn-digitalservice' ) . '</label>';
            },
            'bds-admin-ui',
            'bds_admin_ui_section'
        );

        add_settings_field(
            self::OPT_DISABLE_GUTENBERG,
            __( 'Block-Editor (Gutenberg) global deaktivieren', 'berendsohn-digitalservice' ),
            function () {
                $v = (bool) get_option( self::OPT_DISABLE_GUTENBERG, 0 );
                echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_DISABLE_GUTENBERG ) . '" value="1" ' . checked( $v, true, false ) . ' /> ' .
                     esc_html__( 'Erzwingt den klassischen Editor für alle Beitragstypen und alle Benutzer.', 'berendsohn-digitalservice' ) . '</label>';
            },
            'bds-admin-ui',
            'bds_admin_ui_section'
        );
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return; ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'BDS Admin-Oberfläche', 'berendsohn-digitalservice' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'bds_admin_ui' );
                      do_settings_sections( 'bds-admin-ui' );
                      submit_button(); ?>
            </form>
        </div>
    <?php }

    public static function sanitize_checkbox( $value ) {
        return ! empty( $value ) ? 1 : 0;
    }
}

// Bootstrap
Admin_UI::init();
