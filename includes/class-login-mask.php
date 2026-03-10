<?php
namespace BDS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Login_Mask {

	const SLUG = 'mellon';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'add_rewrite' ] );
		add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );

		// Eigene Login-Maske unter /mellon/
		add_action( 'template_redirect', [ __CLASS__, 'router' ], 1 );

		// Direkten Aufruf von wp-login.php abfangen
		add_action( 'login_init', [ __CLASS__, 'redirect_wp_login' ] );

		// Alle generierten Login-Links auf /mellon/ biegen
		add_filter( 'login_url', [ __CLASS__, 'filter_login_url' ], 10, 3 );

		// Logout-Link ebenfalls sauber umbiegen
		add_filter( 'logout_url', [ __CLASS__, 'filter_logout_url' ], 10, 2 );

		// Optional: Styles für die Login-Maske
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_mask_assets' ] );
	}

	/**
	 * Rewrite-Regel für /mellon/
	 */
	public static function add_rewrite() {
		add_rewrite_rule(
			'^' . preg_quote( trim( self::SLUG, '/' ), '/' ) . '/?$',
			'index.php?bds_login=1',
			'top'
		);
	}

	public static function add_query_vars( $vars ) {
		$vars[] = 'bds_login';
		return $vars;
	}

	/**
	 * Haupt-Router für Frontend-Anfragen
	 */
	public static function router() {
		if ( self::is_bypass_context() ) {
			return;
		}

		// /mellon/ rendern
		if ( self::is_mask_request() ) {
			self::handle_mask_request();
			exit;
		}

		// /wp-admin/ ohne Login => auf /mellon/
		if ( self::is_wp_admin_request() && ! is_user_logged_in() ) {
			wp_safe_redirect( self::masked_login_url( admin_url() ) );
			exit;
		}
	}

	/**
	 * Direkte Aufrufe von wp-login.php behandeln
	 */
	public static function redirect_wp_login() {
		if ( self::is_bypass_context() ) {
			return;
		}

		$action = isset( $_REQUEST['action'] )
			? sanitize_key( wp_unslash( $_REQUEST['action'] ) )
			: 'login';

		/**
		 * Diese Core-Aktionen lassen wir normal über wp-login.php laufen,
		 * damit Reset, Lost Password usw. stabil bleiben.
		 */
		$allowed_core_actions = [
			'logout',
			'lostpassword',
			'retrievepassword',
			'rp',
			'resetpass',
			'checkemail',
			'confirmaction',
			'postpass',
			'register',
		];

		if ( in_array( $action, $allowed_core_actions, true ) ) {
			// Logout sauber zurückleiten
			if ( 'logout' === $action ) {
				$target = self::masked_login_url();
				wp_safe_redirect( $target );
				exit;
			}

			return;
		}

		$redirect_to = isset( $_REQUEST['redirect_to'] ) && '' !== $_REQUEST['redirect_to']
			? wp_unslash( $_REQUEST['redirect_to'] )
			: self::current_redirect_target();

		$extra_args = self::current_login_query_args();

		wp_safe_redirect( self::masked_login_url( $redirect_to, $extra_args ) );
		exit;
	}

	/**
	 * Login-URLs im System auf /mellon/ umbiegen
	 */
	public static function filter_login_url( $login_url, $redirect, $force_reauth ) {
		$extra = [];

		if ( $force_reauth ) {
			$extra['reauth'] = '1';
		}

		return self::masked_login_url( $redirect, $extra );
	}

	/**
	 * Logout-URLs optional anpassen
	 */
	public static function filter_logout_url( $logout_url, $redirect ) {
		$redirect = $redirect ? $redirect : self::masked_login_url();
		return add_query_arg(
			[
				'action'      => 'logout',
				'redirect_to' => $redirect,
			],
			wp_login_url()
		);
	}

	/**
	 * Assets nur auf /mellon/ laden
	 */
	public static function maybe_enqueue_mask_assets() {
		if ( ! self::is_mask_request() ) {
			return;
		}

		if ( defined( 'BDS_URL' ) && defined( 'BDS_VERSION' ) ) {
			wp_enqueue_style(
				'bds-login',
				BDS_URL . 'assets/css/login.css',
				[],
				BDS_VERSION
			);
		}
	}

	/**
	 * Login-Maske rendern oder POST verarbeiten
	 */
	private static function handle_mask_request() {
		nocache_headers();

		// Bereits eingeloggt? Direkt weiter
		if ( is_user_logged_in() ) {
			$target = isset( $_REQUEST['redirect_to'] ) && '' !== $_REQUEST['redirect_to']
				? wp_unslash( $_REQUEST['redirect_to'] )
				: admin_url();

			wp_safe_redirect( $target );
			exit;
		}

		$error      = '';
		$user_login = '';

		if ( 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$posted_action = isset( $_POST['bds_action'] )
				? sanitize_key( wp_unslash( $_POST['bds_action'] ) )
				: 'login';

			if ( 'login' === $posted_action ) {
				if (
					! isset( $_POST['bds_login_nonce'] ) ||
					! wp_verify_nonce(
						sanitize_text_field( wp_unslash( $_POST['bds_login_nonce'] ) ),
						'bds_login_action'
					)
				) {
					$error = __( 'Sicherheitsprüfung fehlgeschlagen.', 'bds' );
				} else {
					$user_login  = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
					$password    = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';
					$remember    = ! empty( $_POST['rememberme'] );
					$redirect_to = isset( $_POST['redirect_to'] ) && '' !== $_POST['redirect_to']
						? wp_unslash( $_POST['redirect_to'] )
						: admin_url();

					$creds = [
						'user_login'    => $user_login,
						'user_password' => $password,
						'remember'      => $remember,
					];

					$user = wp_signon( $creds, is_ssl() );

					if ( is_wp_error( $user ) ) {
						$error = $user->get_error_message();
					} else {
						wp_safe_redirect( $redirect_to );
						exit;
					}
				}
			}
		}

		self::render_form( $user_login, $error );
	}

	/**
	 * HTML-Ausgabe der Login-Seite
	 */
	private static function render_form( $user_login = '', $error = '' ) {
		$redirect_to = isset( $_REQUEST['redirect_to'] ) && '' !== $_REQUEST['redirect_to']
			? wp_unslash( $_REQUEST['redirect_to'] )
			: admin_url();

		$lost_password_url = wp_lostpassword_url( self::masked_login_url( $redirect_to ) );

		status_header( 200 );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( get_bloginfo( 'name' ) . ' – Login' ); ?></title>
			<?php
			// WordPress-Login-Styles als Basis
			wp_admin_css( 'login', true );
			do_action( 'login_enqueue_scripts' );
			wp_print_styles();
			wp_print_head_scripts();
			do_action( 'login_head' );
			?>
		</head>
		<body class="login login-action-login wp-core-ui">
			<div id="login">
				<h1 class="screen-reader-text"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>

				<?php if ( ! empty( $error ) ) : ?>
					<div id="login_error">
						<?php echo wp_kses_post( $error ); ?>
					</div>
				<?php endif; ?>

				<form
					name="loginform"
					id="loginform"
					action="<?php echo esc_url( home_url( '/' . trim( self::SLUG, '/' ) . '/' ) ); ?>"
					method="post"
				>
					<p>
						<label for="user_login"><?php esc_html_e( 'Benutzername oder E-Mail-Adresse' ); ?></label>
						<input
							type="text"
							name="log"
							id="user_login"
							class="input"
							value="<?php echo esc_attr( $user_login ); ?>"
							size="20"
							autocapitalize="off"
							autocomplete="username"
							required
						>
					</p>

					<p>
						<label for="user_pass"><?php esc_html_e( 'Passwort' ); ?></label>
						<input
							type="password"
							name="pwd"
							id="user_pass"
							class="input"
							value=""
							size="20"
							autocomplete="current-password"
							required
						>
					</p>

					<p class="forgetmenot">
						<label for="rememberme">
							<input name="rememberme" type="checkbox" id="rememberme" value="forever">
							<?php esc_html_e( 'Angemeldet bleiben' ); ?>
						</label>
					</p>

					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
					<input type="hidden" name="bds_action" value="login">
					<?php wp_nonce_field( 'bds_login_action', 'bds_login_nonce' ); ?>

					<p class="submit">
						<input
							type="submit"
							name="wp-submit"
							id="wp-submit"
							class="button button-primary button-large"
							value="<?php esc_attr_e( 'Anmelden' ); ?>"
						>
					</p>
				</form>

				<p id="nav">
					<a href="<?php echo esc_url( $lost_password_url ); ?>">
						<?php esc_html_e( 'Passwort vergessen?' ); ?>
					</a>
				</p>

				<p id="backtoblog">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>">
						<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
					</a>
				</p>
			</div>

			<?php
			wp_print_footer_scripts();
			do_action( 'login_footer' );
			?>
		</body>
		</html>
		<?php
	}

	/**
	 * URL zu /mellon/ erzeugen
	 */
	private static function masked_login_url( $redirect_to = '', array $extra_args = [] ) {
		$url = home_url( '/' . trim( self::SLUG, '/' ) . '/' );

		if ( ! empty( $redirect_to ) ) {
			$extra_args['redirect_to'] = $redirect_to;
		}

		if ( ! empty( $extra_args ) ) {
			$url = add_query_arg( $extra_args, $url );
		}

		return $url;
	}

	/**
	 * Ziel nach Login bestimmen
	 */
	private static function current_redirect_target() {
		if ( isset( $_REQUEST['redirect_to'] ) && '' !== $_REQUEST['redirect_to'] ) {
			return wp_unslash( $_REQUEST['redirect_to'] );
		}

		return admin_url();
	}

	/**
	 * Erlaubte Query-Args aus wp-login.php übernehmen
	 */
	private static function current_login_query_args(): array {
		$allowed = [
			'action',
			'checkemail',
			'reauth',
			'interim-login',
		];

		$out = [];

		foreach ( $allowed as $key ) {
			if ( isset( $_REQUEST[ $key ] ) ) {
				$out[ $key ] = wp_unslash( $_REQUEST[ $key ] );
			}
		}

		return $out;
	}

	/**
	 * Ist die /mellon/ URL aktiv?
	 */
	private static function is_mask_request(): bool {
		return (bool) get_query_var( 'bds_login' );
	}

	/**
	 * Ist /wp-admin/ angefragt?
	 */
	private static function is_wp_admin_request(): bool {
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return true;
		}

		$uri = $_SERVER['REQUEST_URI'] ?? '';
		return false !== stripos( $uri, 'wp-admin' );
	}

	/**
	 * Technische Kontexte nie umleiten
	 */
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

		if ( false !== stripos( $uri, 'xmlrpc.php' ) ) {
			return true;
		}

		return false;
	}
}
