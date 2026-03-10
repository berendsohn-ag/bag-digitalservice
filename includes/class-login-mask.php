<?php
namespace BDS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Login_Mask {

	const SLUG = 'mellon';

	/**
	 * Merker, ob ein direkter wp-login.php Aufruf erkannt wurde.
	 *
	 * @var bool
	 */
	private static $wp_login_php = false;

	public static function init() {
		add_action( 'plugins_loaded', [ __CLASS__, 'plugins_loaded' ], 9999 );
		add_action( 'setup_theme', [ __CLASS__, 'setup_theme' ], 1 );
		add_action( 'wp_loaded', [ __CLASS__, 'wp_loaded' ] );

		add_filter( 'site_url', [ __CLASS__, 'filter_site_url' ], 10, 4 );
		add_filter( 'network_site_url', [ __CLASS__, 'filter_network_site_url' ], 10, 3 );
		add_filter( 'wp_redirect', [ __CLASS__, 'filter_wp_redirect' ], 10, 2 );
		add_filter( 'login_url', [ __CLASS__, 'filter_login_url' ], 10, 3 );

		add_action( 'login_enqueue_scripts', [ __CLASS__, 'login_styles' ] );

		// Verhindert Standard-Weiterleitungen wie /wp-admin -> /wp-login.php
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
	}

	/**
	 * Nur für Kompatibilität mit deinem Main-Plugin.
	 * Hier absichtlich leer, damit Activation Hook nicht bricht.
	 */
	public static function add_rewrite() {
		// Kein WP-Rewrite nötig für diesen Ansatz.
	}

	/**
	 * Frühes Request-Umschreiben im Stil von WPS Hide Login.
	 */
	public static function plugins_loaded() {
		global $pagenow;

		if ( self::is_bypass_context() ) {
			return;
		}

		$request_uri = rawurldecode( $_SERVER['REQUEST_URI'] ?? '' );
		$request     = parse_url( $request_uri );
		$path        = isset( $request['path'] ) ? untrailingslashit( $request['path'] ) : '';

		$login_path_relative = home_url( self::new_login_slug(), 'relative' );
		$wp_login_relative   = site_url( 'wp-login.php', 'relative' );

		// Direkter Aufruf von wp-login.php von außen -> als "versteckt" markieren.
		if (
			strpos( $request_uri, 'wp-login.php' ) !== false
			|| $path === untrailingslashit( $wp_login_relative )
		) {
			if ( ! is_admin() ) {
				self::$wp_login_php = true;

				$_SERVER['REQUEST_URI'] = self::user_trailingslashit( '/' . str_repeat( '-/', 10 ) );
				$pagenow               = 'index.php';

				return;
			}
		}

		// Unser versteckter Login /mellon/
		if ( $path === untrailingslashit( $login_path_relative ) ) {
			$_SERVER['SCRIPT_NAME'] = '/' . ltrim( self::new_login_slug(), '/' );
			$pagenow                = 'wp-login.php';
		}
	}

	/**
	 * Verhindert Zugriff auf Customizer für nicht eingeloggte Nutzer.
	 */
	public static function setup_theme() {
		global $pagenow;

		if ( ! is_user_logged_in() && 'customize.php' === $pagenow ) {
			wp_die( esc_html__( 'This has been disabled.', 'berendsohn-digitalservice' ), 403 );
		}
	}

	/**
	 * Hier wird am Ende sauber entschieden:
	 * - wp-admin für Gäste umleiten
	 * - /mellon/ -> echten Core-Login laden
	 * - direkte wp-login.php Aufrufe verstecken
	 */
	public static function wp_loaded() {
		global $pagenow;

		if ( self::is_bypass_context() ) {
			return;
		}

		$request_uri = rawurldecode( $_SERVER['REQUEST_URI'] ?? '' );
		$request     = parse_url( $request_uri );
		$path        = isset( $request['path'] ) ? $request['path'] : '';

		// postpass nie kaputtmachen
		if (
			isset( $_GET['action'] ) &&
			'postpass' === $_GET['action'] &&
			isset( $_POST['post_password'] )
		) {
			return;
		}

		// Gäste nicht ins Backend lassen
		if (
			is_admin()
			&& ! is_user_logged_in()
			&& ! defined( 'WP_CLI' )
			&& ! defined( 'DOING_AJAX' )
			&& ! defined( 'DOING_CRON' )
			&& $pagenow !== 'admin-post.php'
			&& $path !== '/wp-admin/options.php'
		) {
			wp_safe_redirect( self::new_login_url() );
			exit;
		}

		// /wp-admin/options.php Spezialfall
		if ( ! is_user_logged_in() && $path === '/wp-admin/options.php' ) {
			wp_safe_redirect( self::new_login_url() );
			exit;
		}

		// Direkter wp-login.php Aufruf -> versteckte URL benutzen
		if ( self::$wp_login_php ) {
			self::wp_template_loader();
			exit;
		}

		// Jetzt auf /mellon/ wirklich den Core-Login ausführen
		if ( 'wp-login.php' === $pagenow ) {
			// Bereits eingeloggt und kein spezieller Action-Request
			if ( is_user_logged_in() && ! isset( $_REQUEST['action'] ) ) {
				$redirect_to = admin_url();

				if ( isset( $_REQUEST['redirect_to'] ) && $_REQUEST['redirect_to'] !== '' ) {
					$redirect_to = wp_unslash( $_REQUEST['redirect_to'] );
				}

				wp_safe_redirect( $redirect_to );
				exit;
			}

			require_once ABSPATH . 'wp-login.php';
			exit;
		}
	}

	/**
	 * Alle WordPress-generierten wp-login.php URLs nach /mellon/ umbiegen.
	 */
	public static function filter_site_url( $url, $path, $scheme, $blog_id ) {
		return self::filter_wp_login_php( $url, $scheme );
	}

	public static function filter_network_site_url( $url, $path, $scheme ) {
		return self::filter_wp_login_php( $url, $scheme );
	}

	public static function filter_wp_redirect( $location, $status ) {
		return self::filter_wp_login_php( $location );
	}

	public static function filter_login_url( $login_url, $redirect, $force_reauth ) {
		$url = self::new_login_url();

		if ( ! empty( $redirect ) ) {
			$url = add_query_arg( 'redirect_to', $redirect, $url );
		}

		if ( $force_reauth ) {
			$url = add_query_arg( 'reauth', '1', $url );
		}

		return $url;
	}

	private static function filter_wp_login_php( $url, $scheme = null ) {
		global $pagenow;

		// Post-Passwort-Handling unangetastet lassen
		if ( strpos( $url, 'wp-login.php?action=postpass' ) !== false ) {
			return $url;
		}

		if ( strpos( $url, 'wp-login.php' ) !== false ) {
			if ( is_ssl() ) {
				$scheme = 'https';
			}

			$parts = explode( '?', $url, 2 );

			if ( isset( $parts[1] ) ) {
				parse_str( $parts[1], $args );

				if ( isset( $args['login'] ) ) {
					$args['login'] = rawurlencode( $args['login'] );
				}

				$url = add_query_arg( $args, self::new_login_url( $scheme ) );
			} else {
				$url = self::new_login_url( $scheme );
			}
		}

		return $url;
	}

	private static function new_login_slug() {
		return trim( self::SLUG, '/' );
	}

	private static function new_login_url( $scheme = null ) {
		$url = home_url( '/', $scheme );

		if ( get_option( 'permalink_structure' ) ) {
			return self::user_trailingslashit( $url . self::new_login_slug() );
		}

		return $url . '?' . self::new_login_slug();
	}

	private static function use_trailing_slashes() {
		$structure = (string) get_option( 'permalink_structure' );
		return '/' === substr( $structure, -1, 1 );
	}

	private static function user_trailingslashit( $string ) {
		return self::use_trailing_slashes() ? trailingslashit( $string ) : untrailingslashit( $string );
	}

	private static function wp_template_loader() {
		global $pagenow;

		$pagenow = 'index.php';

		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', true );
		}

		wp();
		require_once ABSPATH . WPINC . '/template-loader.php';
		exit;
	}

	private static function is_bypass_context(): bool {
		if (
			( defined( 'DOING_AJAX' ) && DOING_AJAX ) ||
			( defined( 'REST_REQUEST' ) && REST_REQUEST ) ||
			( defined( 'WP_CLI' ) && WP_CLI ) ||
			( defined( 'DOING_CRON' ) && DOING_CRON )
		) {
			return true;
		}

		$uri = $_SERVER['REQUEST_URI'] ?? '';

		if ( stripos( $uri, 'xmlrpc.php' ) !== false ) {
			return true;
		}

		return false;
	}

	public static function login_styles() {
		if ( defined( 'BDS_URL' ) && defined( 'BDS_VERSION' ) ) {
			wp_enqueue_style(
				'bds-login',
				BDS_URL . 'assets/css/login.css',
				[],
				BDS_VERSION
			);
		}
	}
}
