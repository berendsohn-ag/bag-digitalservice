<?php
namespace BDS;

if ( ! defined( 'ABSPATH' ) ) exit;

class Login_Mask {

    // Öffentlicher Login-Endpunkt (ohne Secret-Key)
    const SLUG = 'mellon'; // /mellon/

    public static function init() {
        add_action( 'init', [ __CLASS__, 'add_rewrite' ] );
        // früh einhaken, damit Theme/SEO-Redirects nicht dazwischen funken
        add_action( 'template_redirect', [ __CLASS__, 'router' ], 1 );
        add_filter( 'login_url', [ __CLASS__, 'filter_login_url' ], 10, 3 );
        add_action( 'login_enqueue_scripts', [ __CLASS__, 'login_styles' ] );
    }

    /** /mellon/ als virtuelle Login-URL registrieren */
    public static function add_rewrite() {
        add_rewrite_tag( '%bds_login%', '1' );
        add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?bds_login=1', 'top' );
    }

    /** Zentraler Router: entscheidet über Rendern/Redirects */
    public static function router() {
        // Notfall-Bypass: /?bds_noredirect=1
        if ( isset( $_GET['bds_noredirect'] ) ) return;

        // Technische Kontexte nie umleiten
        if ( self::is_bypass_context() ) return;

        // 1) Maske aufgerufen? -> wp-login.php direkt rendern (kein Redirect!)
        if ( self::is_mask_request() ) {
            self::render_wp_login();
            exit;
        }

        // 2) Direkter Aufruf von wp-login.php?
        if ( self::is_wp_login_request() ) {
            // Zulässige Aktionen (Logout/Reset etc.) lassen wir zu – aber über die Maske
            // Wir erhalten die Query-Parameter und leiten EINMAL auf /mellon/ um.
            $redirect_to = isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : self::current_redirect_target();
            $extra = self::current_login_query_args(); // action, checkemail, key, login, ...
            $url = self::masked_login_url( $redirect_to, $extra );
            wp_safe_redirect( $url );
            exit;
        }

        // 3) Adminbereich ohne Login -> zur Maske (mit redirect_to auf wp-admin)
        if ( self::is_wp_admin_request() && ! is_user_logged_in() ) {
            wp_safe_redirect( self::masked_login_url( admin_url() ) );
            exit;
        }

        // sonst nichts tun
    }

    /** Alle generierten Login-Links auf /mellon/ biegen */
    public static function filter_login_url( $login_url, $redirect, $force_reauth ) {
        return self::masked_login_url( $redirect );
    }

    /** ===== Helper ===== */

    /** Erkennen, ob /mellon/ angefragt ist */
    private static function is_mask_request(): bool {
        if ( get_query_var( 'bds_login' ) ) return true;
        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        $slug = '/' . trim( self::SLUG, '/' ) . '/';
        return stripos( $uri, $slug ) !== false;
    }

    /** Erkennen, ob wp-login.php angefragt ist */
    private static function is_wp_login_request(): bool {
        $pagenow = $GLOBALS['pagenow'] ?? '';
        if ( $pagenow === 'wp-login.php' ) return true;
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return stripos( $uri, 'wp-login.php' ) !== false;
    }

    /** Erkennen, ob Adminbereich angefragt ist */
    private static function is_wp_admin_request(): bool {
        // AJAX im Admin nicht blocken
        if ( is_admin() && !( defined('DOING_AJAX') && DOING_AJAX ) ) return true;
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return stripos( $uri, 'wp-admin' ) !== false;
    }

    /** Technische Kontexte, die nie umgeleitet werden dürfen */
    private static function is_bypass_context(): bool {
        if ( ( defined('DOING_AJAX') && DOING_AJAX ) ||
             ( defined('REST_REQUEST') && REST_REQUEST ) ||
             ( defined('WP_CLI') && WP_CLI ) ||
             ( defined('DOING_CRON') && DOING_CRON ) ) {
            return true;
        }
        // XML-RPC nicht stören
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ( stripos( $uri, 'xmlrpc.php' ) !== false ) return true;

        return false;
    }

    /** wp-login.php direkt einbinden (Maske rendert Form ohne Redirect) */
    private static function render_wp_login() {
        // Der Core baut das hidden redirect_to Feld selbst aus $_REQUEST
        require_once ABSPATH . 'wp-login.php';
    }

    /** Login-URL zur Maske bauen; redirect_to nur einmal anhängen + Extra-Args erhalten */
    private static function masked_login_url( $redirect_to = '', array $extra_args = [] ) {
        $url = home_url( '/' . trim( self::SLUG, '/' ) . '/' );

        // vorhandene Query der aktuellen Anfrage prüfen, um Doppelungen zu vermeiden
        $current_redirect = isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : '';

        if ( $redirect_to && $redirect_to !== $current_redirect ) {
            $url = add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $url );
        }

        // weitere erlaubte Parameter (action, checkemail, key, login, user_login …) sauber übernehmen
        foreach ( $extra_args as $k => $v ) {
            if ( $v === '' || $v === null ) continue;
            // redirect_to wurde oben behandelt
            if ( strtolower( $k ) === 'redirect_to' ) continue;
            $url = add_query_arg( $k, rawurlencode( $v ), $url );
        }

        return $url;
    }

    /** gewünschtes Ziel bestimmen, falls nichts explizit übergeben wurde */
    private static function current_redirect_target() {
        if ( isset( $_GET['redirect_to'] ) && $_GET['redirect_to'] !== '' ) {
            return wp_unslash( $_GET['redirect_to'] );
        }
        return home_url( '/' );
    }

    /** Erlaubte/übliche Login-Query-Parameter einsammeln, um sie zur Maske mitzunehmen */
    private static function current_login_query_args(): array {
        $allowed = [
            'action', 'checkemail', 'reauth',
            'key', 'login', 'user_login', 'email',
            // 'redirect_to' lassen wir hier absichtlich weg – das wird separat behandelt
        ];
        $out = [];
        foreach ( $allowed as $k ) {
            if ( isset( $_GET[ $k ] ) ) {
                $out[ $k ] = wp_unslash( $_GET[ $k ] );
            }
        }
        return $out;
    }

    /** Optionales Styling für die Loginseite */
    public static function login_styles() {
        wp_enqueue_style( 'bds-login', BDS_URL . 'assets/css/login.css', [], BDS_VERSION );
    }
}
