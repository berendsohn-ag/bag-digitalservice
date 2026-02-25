<?php
namespace BDS;

if ( ! defined( 'ABSPATH' ) ) exit;

class Admin_UI {
    const OPT_HIDE_POSTS_MENU    = 'bds_hide_posts_menu_global';
    const OPT_DISABLE_GUTENBERG  = 'bds_disable_gutenberg_global';
    const OPT_HIDE_COMMENTS_ALL  = 'bds_hide_comments_global';

    public static function init() {
        // Settings UI
        add_action( 'admin_menu', [ __CLASS__, 'add_settings_page' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );

        // Admin CSS
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin' ] );

        // Menüs/Editor/Kommentare (maximal robust: spätes Hooking)
        add_action( 'admin_menu', [ __CLASS__, 'maybe_hide_posts_menu' ], 9999 );
        add_action( 'admin_menu', [ __CLASS__, 'maybe_hide_comments_menu' ], 9999 );
        add_action( 'admin_init', [ __CLASS__, 'maybe_hide_posts_menu' ], 9999 ); // doppelt hält besser
        add_action( 'admin_init', [ __CLASS__, 'maybe_hide_comments_menu' ], 9999 );

        // Gutenberg deaktivieren (Filter immer registrieren, aber conditional return)
        add_filter( 'use_block_editor_for_post_type', [ __CLASS__, 'filter_disable_gutenberg_post_type' ], 100, 2 );
        add_filter( 'use_block_editor_for_post', [ __CLASS__, 'filter_disable_gutenberg_post' ], 100, 2 );
        add_filter( 'gutenberg_can_edit_post_type', [ __CLASS__, 'filter_disable_gutenberg_post_type' ], 100, 2 );

        // Kommentare komplett deaktivieren (Hooks immer registrieren, aber conditional return)
        self::wire_comment_disablers();
    }

    /* -------------------- Helpers -------------------- */

    /** Robust: Option lesen mit Default=1, auch wenn Option nie gespeichert wurde */
    private static function opt_on( string $key, int $default = 1 ) : bool {
        $v = get_option( $key, $default );
        // WP kann booleans/strings zurückgeben -> sauber normalisieren
        return ! empty( $v ) && (string)$v !== '0';
    }

    /* -------------------- Admin CSS -------------------- */

    public static function enqueue_admin() {
        wp_enqueue_style( 'bds-admin', BDS_URL . 'assets/css/admin.css', [], BDS_VERSION );
    }

    /* -------------------- Posts Menu -------------------- */

    public static function maybe_hide_posts_menu() {
        if ( ! is_admin() ) return;
        if ( ! self::opt_on( self::OPT_HIDE_POSTS_MENU, 1 ) ) return;

        // Beiträge entfernen
        remove_menu_page( 'edit.php' );

        // Optional: Untermenüs ausblenden
        // remove_submenu_page( 'edit.php', 'edit-tags.php?taxonomy=category' );
        // remove_submenu_page( 'edit.php', 'edit-tags.php?taxonomy=post_tag' );
    }

    /* -------------------- Comments Menu -------------------- */

    public static function maybe_hide_comments_menu() {
        if ( ! is_admin() ) return;
        if ( ! self::should_hide_comments() ) return;

        remove_menu_page( 'edit-comments.php' );
    }

    private static function should_hide_comments() : bool {
        // Default: wenn Posts verborgen, dann Kommentare auch
        return self::opt_on( self::OPT_HIDE_POSTS_MENU, 1 ) || self::opt_on( self::OPT_HIDE_COMMENTS_ALL, 1 );
    }

    /* -------------------- Gutenberg Disable -------------------- */

    public static function filter_disable_gutenberg_post_type( $can_edit, $post_type ) {
        if ( self::opt_on( self::OPT_DISABLE_GUTENBERG, 1 ) ) {
            return false;
        }
        return $can_edit;
    }

    public static function filter_disable_gutenberg_post( $can_edit, $post ) {
        if ( self::opt_on( self::OPT_DISABLE_GUTENBERG, 1 ) ) {
            return false;
        }
        return $can_edit;
    }

    /* -------------------- Comment Disablers (always wired, conditional) -------------------- */

    private static function wire_comment_disablers() {
        // Admin bar bubble
        add_action( 'wp_before_admin_bar_render', function () {
            if ( ! self::should_hide_comments() ) return;
            global $wp_admin_bar;
            if ( is_object( $wp_admin_bar ) ) {
                $wp_admin_bar->remove_menu( 'comments' );
            }
        }, 0 );

        // Block edit-comments.php
        add_action( 'admin_init', function () {
            if ( ! self::should_hide_comments() ) return;

            $pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
            if ( $pagenow === 'edit-comments.php' ) {
                wp_safe_redirect( admin_url() );
                exit;
            }

            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
            if ( $screen && isset( $screen->id ) && $screen->id === 'edit-comments' ) {
                wp_safe_redirect( admin_url() );
                exit;
            }
        }, 1 );

        // Close comments/pings
        add_filter( 'comments_open', function ( $open ) {
            return self::should_hide_comments() ? false : $open;
        }, 9999 );

        add_filter( 'pings_open', function ( $open ) {
            return self::should_hide_comments() ? false : $open;
        }, 9999 );

        // Empty comments array in frontend
        add_filter( 'comments_array', function ( $comments ) {
            return self::should_hide_comments() ? [] : $comments;
        }, 9999 );

        // Remove comment support from all post types
        add_action( 'admin_init', function () {
            if ( ! self::should_hide_comments() ) return;

            foreach ( get_post_types() as $type ) {
                if ( post_type_supports( $type, 'comments' ) ) {
                    remove_post_type_support( $type, 'comments' );
                    remove_post_type_support( $type, 'trackbacks' );
                }
            }
        }, 20 );

        // Dashboard widget
        add_action( 'wp_dashboard_setup', function () {
            if ( ! self::should_hide_comments() ) return;
            remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
        }, 20 );
    }

    /* -------------------- Settings UI -------------------- */

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
        // Defaults = 1 (checked). IMPORTANT: Checkbox needs hidden input in field render.
        register_setting( 'bds_admin_ui', self::OPT_HIDE_POSTS_MENU, [
            'type'              => 'boolean',
            'sanitize_callback' => [ __CLASS__, 'sanitize_checkbox' ],
            'default'           => 1,
        ] );

        register_setting( 'bds_admin_ui', self::OPT_DISABLE_GUTENBERG, [
            'type'              => 'boolean',
            'sanitize_callback' => [ __CLASS__, 'sanitize_checkbox' ],
            'default'           => 1,
        ] );

        register_setting( 'bds_admin_ui', self::OPT_HIDE_COMMENTS_ALL, [
            'type'              => 'boolean',
            'sanitize_callback' => [ __CLASS__, 'sanitize_checkbox' ],
            'default'           => 1,
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
            [ __CLASS__, 'field_hide_posts' ],
            'bds-admin-ui',
            'bds_admin_ui_section'
        );

        add_settings_field(
            self::OPT_HIDE_COMMENTS_ALL,
            __( 'Kommentare global deaktivieren (optional, unabhängig)', 'berendsohn-digitalservice' ),
            [ __CLASS__, 'field_hide_comments' ],
            'bds-admin-ui',
            'bds_admin_ui_section'
        );

        add_settings_field(
            self::OPT_DISABLE_GUTENBERG,
            __( 'Block-Editor (Gutenberg) global deaktivieren', 'berendsohn-digitalservice' ),
            [ __CLASS__, 'field_disable_gutenberg' ],
            'bds-admin-ui',
            'bds_admin_ui_section'
        );
    }

    public static function field_hide_posts() {
        $v = self::opt_on( self::OPT_HIDE_POSTS_MENU, 1 );
        echo '<input type="hidden" name="' . esc_attr( self::OPT_HIDE_POSTS_MENU ) . '" value="0" />';
        echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_HIDE_POSTS_MENU ) . '" value="1" ' . checked( $v, true, false ) . ' /> ' .
            esc_html__( 'Entfernt den Menüpunkt „Beiträge“ für alle Benutzer. (Kommentare werden automatisch ebenfalls ausgeblendet/deaktiviert.)', 'berendsohn-digitalservice' ) .
            '</label>';
    }

    public static function field_hide_comments() {
        $v = self::opt_on( self::OPT_HIDE_COMMENTS_ALL, 1 );
        echo '<input type="hidden" name="' . esc_attr( self::OPT_HIDE_COMMENTS_ALL ) . '" value="0" />';
        echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_HIDE_COMMENTS_ALL ) . '" value="1" ' . checked( $v, true, false ) . ' /> ' .
            esc_html__( 'Versteckt/Deaktiviert Kommentare und Kommentar-Menüs systemweit – unabhängig von „Beiträge ausblenden“.', 'berendsohn-digitalservice' ) .
            '</label>';
    }

    public static function field_disable_gutenberg() {
        $v = self::opt_on( self::OPT_DISABLE_GUTENBERG, 1 );
        echo '<input type="hidden" name="' . esc_attr( self::OPT_DISABLE_GUTENBERG ) . '" value="0" />';
        echo '<label><input type="checkbox" name="' . esc_attr( self::OPT_DISABLE_GUTENBERG ) . '" value="1" ' . checked( $v, true, false ) . ' /> ' .
            esc_html__( 'Erzwingt den klassischen Editor für alle Beitragstypen und alle Benutzer.', 'berendsohn-digitalservice' ) .
            '</label>';
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return; ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'BDS Admin-Oberfläche', 'berendsohn-digitalservice' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields( 'bds_admin_ui' );
                    do_settings_sections( 'bds-admin-ui' );
                    submit_button();
                ?>
            </form>
        </div>
    <?php }

    public static function sanitize_checkbox( $value ) {
        return ! empty( $value ) ? 1 : 0;
    }
}

// Bootstrap
Admin_UI::init();
