<?php
namespace BDS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Login_Mask {

	const SLUG = 'mellon';

	private static $wp_login_php = false;
	private static $login_alias  = false;

	public static function init() {
		add_action( 'plugins_loaded', [ __CLASS__, 'plugins_loaded' ], 9999 );
		add_action( 'setup_theme', [ __CLASS__, 'setup_theme' ], 1 );
		add_action( 'wp_loaded', [ __CLASS__, 'wp_loaded' ] );

		add_filter( 'site_url', [ __CLASS__, 'filter_wp_login_php' ], 10, 4 );
		add_filter( 'network_site_url', [ __CLASS__, 'filter_wp_login_php' ], 10, 3 );
		add_filter( 'wp_redirect', [ __CLASS__, 'filter_wp_login_php_redirect' ], 10, 2 );
		add_filter( 'login_url', [ __CLASS__, 'filter_login_url' ], 10, 3 );

		add_action( 'login_enqueue_scripts', [ __CLASS__, 'login_styles' ] );
		add_action( 'login_enqueue_scripts', [ __CLASS__, 'custom_login_logo' ] );

		add_filter( 'login_headerurl', [ __CLASS__, 'login_header_url' ] );
		add_filter( 'login_headertext', [ __CLASS__, 'login_header_text' ] );

		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
	}

	public static function add_rewrite() {
		// Leer für Kompatibilität mit deinem Activation Hook.
	}

	public static function plugins_loaded() {
		global $pagenow;

		$request_uri = rawurldecode( $_SERVER['REQUEST_URI'] ?? '' );
		$request     = parse_url( $request_uri );
		$path        = isset( $request['path'] ) ? untrailingslashit( $request['path'] ) : '';

		$masked_path = untrailingslashit( home_url( self::SLUG, 'relative' ) );
		$login_path  = untrailingslashit( home_url( 'login', 'relative' ) );

		// /login oder /login/ als Alias merken
		if ( $path === $login_path ) {
			self::$login_alias = true;
			return;
		}

		// direkter Aufruf von wp-login.php merken
		if ( strpos( $request_uri, 'wp-login.php' ) !== false && ! is_admin() ) {
			self::$wp_login_php = true;
			return;
		}

		// /mellon/ intern wie wp-login.php behandeln
		if ( $path === $masked_path ) {
			$_SERVER['SCRIPT_NAME'] = '/' . self::SLUG;
			$pagenow = 'wp-login.php';
		}
	}

	public static function setup_theme() {
		global $pagenow;

		if ( ! is_user_logged_in() && 'customize.php' === $pagenow ) {
			wp_die( 'Disabled', 403 );
		}
	}

	public static function wp_loaded() {
		global $pagenow;

		// /login -> /mellon/
		if ( self::$login_alias && self::is_safe_redirect_request() ) {
			wp_safe_redirect( self::current_masked_url() );
			exit;
		}

		// /wp-login.php -> /mellon/
		if ( self::$wp_login_php && self::is_safe_redirect_request() ) {
			wp_safe_redirect( self::current_masked_url() );
			exit;
		}

		if ( is_admin() && ! is_user_logged_in() ) {
			wp_safe_redirect( self::login_url() );
			exit;
		}

		if ( 'wp-login.php' === $pagenow ) {
			global $error, $user_login;

			if ( ! isset( $error ) ) {
				$error = '';
			}

			if ( ! isset( $user_login ) ) {
				$user_login = '';
			}

			require_once ABSPATH . 'wp-login.php';
			exit;
		}
	}

	private static function is_safe_redirect_request() {
		$method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
		return in_array( $method, [ 'GET', 'HEAD' ], true );
	}

	private static function current_masked_url() {
		$url = self::login_url();

		if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
			$url .= '?' . ltrim( (string) $_SERVER['QUERY_STRING'], '?' );
		}

		return $url;
	}

	public static function filter_login_url( $login_url, $redirect, $force_reauth ) {
		$url = self::login_url();

		if ( ! empty( $redirect ) ) {
			$url = add_query_arg( 'redirect_to', $redirect, $url );
		}

		if ( $force_reauth ) {
			$url = add_query_arg( 'reauth', '1', $url );
		}

		return $url;
	}

	public static function filter_wp_login_php( $url ) {
		if ( strpos( $url, 'wp-login.php' ) !== false ) {
			$args = explode( '?', $url, 2 );

			if ( isset( $args[1] ) ) {
				parse_str( $args[1], $params );
				$url = add_query_arg( $params, self::login_url() );
			} else {
				$url = self::login_url();
			}
		}

		return $url;
	}

	public static function filter_wp_login_php_redirect( $location ) {
		if ( strpos( $location, 'wp-login.php' ) !== false ) {
			$parts = explode( '?', $location, 2 );

			$location = self::login_url();

			if ( isset( $parts[1] ) && $parts[1] !== '' ) {
				$location .= '?' . $parts[1];
			}
		}

		return $location;
	}

	private static function login_url() {
		$url = home_url( '/' );

		if ( get_option( 'permalink_structure' ) ) {
			return trailingslashit( $url . self::SLUG );
		}

		return $url . '?' . self::SLUG;
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

	public static function login_header_url() {
		return home_url( '/' );
	}

	public static function login_header_text() {
		return get_bloginfo( 'name' );
	}

	public static function custom_login_logo() {
		if ( ! defined( 'BDS_URL' ) ) {
			return;
		}

		$logo = BDS_URL . 'assets/img/berendsohn-logo.png';
		?>
		<style id="bds-custom-login-logo">
			body.login div#login h1 a,
			body.login .wp-login-logo a {
				background-image: url('<?php echo esc_url( $logo ); ?>') !important;
				background-size: contain !important;
				background-position: center center !important;
				background-repeat: no-repeat !important;
				width: 320px !important;
				height: 120px !important;
				display: block !important;
			}
		</style>
		<?php
	}
}
