<?php
namespace BDS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Login_Mask {

	const SLUG                = 'mellon';
	const RATE_LIMIT_MAX      = 5;   // max. Fehlversuche
	const RATE_LIMIT_WINDOW   = 900; // 15 Minuten
	const RATE_LIMIT_LOCKOUT  = 1800; // 30 Minuten Sperre

	public static function init() {
		add_action( 'init', [ __CLASS__, 'add_rewrite' ] );
		add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );

		add_action( 'template_redirect', [ __CLASS__, 'router' ], 1 );
		add_action( 'login_init', [ __CLASS__, 'redirect_wp_login' ] );

		add_filter( 'login_url', [ __CLASS__, 'filter_login_url' ], 10, 3 );
		add_filter( 'logout_url', [ __CLASS__, 'filter_logout_url' ], 10, 2 );

		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_mask_assets' ] );

		// Deprecated-Output auf eigener Maske verhindern
		add_action( 'init', [ __CLASS__, 'remove_deprecated_emoji_style_hooks' ], 20 );
	}

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

	public static function router() {
		if ( self::is_bypass_context() ) {
			return;
		}

		if ( self::is_mask_request() ) {
			self::handle_mask_request();
			exit;
		}

		if ( self::is_wp_admin_request() && ! is_user_logged_in() ) {
			wp_safe_redirect( self::masked_login_url( admin_url() ) );
			exit;
		}
	}

	public static function redirect_wp_login() {
		if ( self::is_bypass_context() ) {
			return;
		}

		$action = isset( $_REQUEST['action'] )
			? sanitize_key( wp_unslash( $_REQUEST['action'] ) )
			: 'login';

		// Core-Aktionen, die stabil weiter über wp-login.php laufen sollen
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
			return;
		}

		$redirect_to = isset( $_REQUEST['redirect_to'] ) && $_REQUEST['redirect_to'] !== ''
			? wp_unslash( $_REQUEST['redirect_to'] )
			: self::current_redirect_target();

		$extra = self::current_login_query_args();

		wp_safe_redirect( self::masked_login_url( $redirect_to, $extra ) );
		exit;
	}

	public static function filter_login_url( $login_url, $redirect, $force_reauth ) {
		$extra = [];

		if ( $force_reauth ) {
			$extra['reauth'] = '1';
		}

		return self::masked_login_url( $redirect, $extra );
	}

	public static function filter_logout_url( $logout_url, $redirect ) {
		$target = $redirect ? $redirect : self::masked_login_url();

		return add_query_arg(
			[
				'action'      => 'logout',
				'redirect_to' => $target,
			],
			site_url( 'wp-login.php', 'login' )
		);
	}

	public static function maybe_enqueue_mask_assets() {
		if ( ! self::is_mask_request() ) {
			return;
		}

		// Basis-Styles für WP-Login-Look
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'buttons' );
		wp_enqueue_style( 'forms' );
		wp_enqueue_style( 'login' );

		if ( defined( 'BDS_URL' ) && defined( 'BDS_VERSION' ) ) {
			wp_enqueue_style(
				'bds-login',
				BDS_URL . 'assets/css/login.css',
				[ 'login' ],
				BDS_VERSION
			);
		}
	}

	public static function remove_deprecated_emoji_style_hooks() {
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
	}

	private static function handle_mask_request() {
		nocache_headers();

		if ( is_user_logged_in() ) {
			$target = isset( $_REQUEST['redirect_to'] ) && $_REQUEST['redirect_to'] !== ''
				? wp_unslash( $_REQUEST['redirect_to'] )
				: admin_url();

			wp_safe_redirect( $target );
			exit;
		}

		$error      = '';
		$user_login = '';

		if ( 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$user_login = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';

			if ( self::is_rate_limited() ) {
				$error = __( 'Zu viele Anmeldeversuche. Bitte versuche es später erneut.', 'berendsohn-digitalservice' );
			} elseif ( self::is_honeypot_triggered() ) {
				self::register_failed_attempt();
				$error = self::generic_login_error();
			} elseif (
				! isset( $_POST['bds_login_nonce'] ) ||
				! wp_verify_nonce(
					sanitize_text_field( wp_unslash( $_POST['bds_login_nonce'] ) ),
					'bds_login_action'
				)
			) {
				self::register_failed_attempt();
				$error = __( 'Sicherheitsprüfung fehlgeschlagen.', 'berendsohn-digitalservice' );
			} else {
				$password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';
				$remember = ! empty( $_POST['rememberme'] );

				$redirect_to = isset( $_POST['redirect_to'] ) && $_POST['redirect_to'] !== ''
					? wp_unslash( $_POST['redirect_to'] )
					: admin_url();

				$creds = [
					'user_login'    => $user_login,
					'user_password' => $password,
					'remember'      => $remember,
				];

				$user = wp_signon( $creds, is_ssl() );

				if ( is_wp_error( $user ) ) {
					self::register_failed_attempt();
					$error = self::generic_login_error();
				} else {
					self::clear_failed_attempts();
					wp_safe_redirect( $redirect_to );
					exit;
				}
			}
		}

		self::render_form( $user_login, $error );
	}

	private static function render_form( $user_login = '', $error = '' ) {
		$redirect_to = isset( $_REQUEST['redirect_to'] ) && $_REQUEST['redirect_to'] !== ''
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
			<meta name="robots" content="noindex,nofollow">
			<title><?php echo esc_html( get_bloginfo( 'name' ) . ' – Login' ); ?></title>
			<?php
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
						<?php echo esc_html( $error ); ?>
					</div>
				<?php endif; ?>

				<form name="loginform" id="loginform" action="<?php echo esc_url( self::masked_login_url() ); ?>" method="post" novalidate="novalidate">
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

					<!-- Honeypot -->
					<p style="position:absolute;left:-9999px;top:-9999px;opacity:0;pointer-events:none;" aria-hidden="true">
						<label for="company_website">Website</label>
						<input type="text" name="company_website" id="company_website" value="" tabindex="-1" autocomplete="off">
					</p>

					<p class="forgetmenot">
						<label for="rememberme">
							<input name="rememberme" type="checkbox" id="rememberme" value="forever">
							<?php esc_html_e( 'Angemeldet bleiben' ); ?>
						</label>
					</p>

					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
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

	private static function current_redirect_target() {
		if ( isset( $_REQUEST['redirect_to'] ) && $_REQUEST['redirect_to'] !== '' ) {
			return wp_unslash( $_REQUEST['redirect_to'] );
		}

		return admin_url();
	}

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

	private static function generic_login_error(): string {
		return __( 'Die Anmeldedaten sind ungültig.', 'berendsohn-digitalservice' );
	}

	private static function is_honeypot_triggered(): bool {
		return ! empty( $_POST['company_website'] );
	}

	private static function is_rate_limited(): bool {
		$lock_until = (int) get_transient( self::lockout_key() );

		if ( $lock_until && time() < $lock_until ) {
			return true;
		}

		if ( $lock_until && time() >= $lock_until ) {
			delete_transient( self::lockout_key() );
		}

		return false;
	}

	private static function register_failed_attempt() {
		$key      = self::attempt_key();
		$attempts = (int) get_transient( $key );
		$attempts++;

		set_transient( $key, $attempts, self::RATE_LIMIT_WINDOW );

		if ( $attempts >= self::RATE_LIMIT_MAX ) {
			set_transient( self::lockout_key(), time() + self::RATE_LIMIT_LOCKOUT, self::RATE_LIMIT_LOCKOUT );
		}
	}

	private static function clear_failed_attempts() {
		delete_transient( self::attempt_key() );
		delete_transient( self::lockout_key() );
	}

	private static function attempt_key(): string {
		return 'bds_login_attempts_' . md5( self::client_fingerprint() );
	}

	private static function lockout_key(): string {
		return 'bds_login_lockout_' . md5( self::client_fingerprint() );
	}

	private static function client_fingerprint(): string {
		$ip = self::client_ip();
		return $ip ? $ip : 'unknown';
	}

	private static function client_ip(): string {
		$keys = [
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		];

		foreach ( $keys as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$value = wp_unslash( $_SERVER[ $key ] );

			if ( 'HTTP_X_FORWARDED_FOR' === $key ) {
				$parts = explode( ',', $value );
				$value = trim( $parts[0] );
			}

			$ip = sanitize_text_field( $value );

			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return '';
	}

	private static function is_mask_request(): bool {
		return (bool) get_query_var( 'bds_login' );
	}

	private static function is_wp_admin_request(): bool {
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return true;
		}

		$uri = $_SERVER['REQUEST_URI'] ?? '';
		return false !== stripos( $uri, 'wp-admin' );
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

		if ( false !== stripos( $uri, 'xmlrpc.php' ) ) {
			return true;
		}

		return false;
	}
}
