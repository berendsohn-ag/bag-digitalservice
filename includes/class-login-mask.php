<?php
namespace BDS;

if ( ! defined( 'ABSPATH' ) ) exit;

class Login_Mask {

	const SLUG = 'mellon';

	public static function init() {

		add_action( 'init', [ __CLASS__, 'add_rewrite' ] );
		add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );

		add_action( 'template_redirect', [ __CLASS__, 'router' ], 1 );

		// wp-login.php abfangen
		add_action( 'login_init', [ __CLASS__, 'redirect_wp_login' ] );

		// alle Login Links ersetzen
		add_filter( 'login_url', [ __CLASS__, 'filter_login_url' ], 10, 3 );

		// Logout redirect
		add_action( 'wp_logout', [ __CLASS__, 'logout_redirect' ] );

		// CSS laden
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_mask_assets' ] );

	}

	/**
	 * Rewrite Regel für /mellon/
	 */
	public static function add_rewrite() {

		add_rewrite_rule(
			'^' . preg_quote( self::SLUG, '/' ) . '/?$',
			'index.php?bds_login=1',
			'top'
		);

	}

	public static function add_query_vars( $vars ) {

		$vars[] = 'bds_login';
		return $vars;

	}

	/**
	 * Router
	 */
	public static function router() {

		if ( self::is_bypass_context() ) return;

		if ( self::is_mask_request() ) {

			self::handle_mask_request();
			exit;

		}

		if ( self::is_wp_admin_request() && ! is_user_logged_in() ) {

			wp_safe_redirect( self::masked_login_url( admin_url() ) );
			exit;

		}

	}

	/**
	 * wp-login.php redirect
	 */
	public static function redirect_wp_login() {

		if ( self::is_bypass_context() ) return;

		$action = isset( $_REQUEST['action'] )
			? sanitize_key( wp_unslash( $_REQUEST['action'] ) )
			: 'login';

		// Logout NICHT blockieren
		if ( $action === 'logout' ) return;

		$allowed_core_actions = [
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

		$redirect_to = isset( $_REQUEST['redirect_to'] )
			? wp_unslash( $_REQUEST['redirect_to'] )
			: admin_url();

		wp_safe_redirect( self::masked_login_url( $redirect_to ) );
		exit;

	}

	/**
	 * Login URLs ersetzen
	 */
	public static function filter_login_url( $login_url, $redirect, $force_reauth ) {

		return self::masked_login_url( $redirect );

	}

	/**
	 * Nach Logout auf /mellon/
	 */
	public static function logout_redirect() {

		wp_safe_redirect( home_url( '/' . self::SLUG . '/' ) );
		exit;

	}

	/**
	 * Login CSS
	 */
	public static function maybe_enqueue_mask_assets() {

		if ( ! self::is_mask_request() ) return;

		if ( defined( 'BDS_URL' ) ) {

			wp_enqueue_style(
				'bds-login',
				BDS_URL . 'assets/css/login.css',
				[],
				BDS_VERSION
			);

		}

	}

	/**
	 * Login Handler
	 */
	private static function handle_mask_request() {

		nocache_headers();

		if ( is_user_logged_in() ) {

			$target = isset( $_REQUEST['redirect_to'] )
				? wp_unslash( $_REQUEST['redirect_to'] )
				: admin_url();

			wp_safe_redirect( $target );
			exit;

		}

		$error = '';
		$user_login = '';

		if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {

			if (
				! isset( $_POST['bds_login_nonce'] ) ||
				! wp_verify_nonce(
					sanitize_text_field( wp_unslash( $_POST['bds_login_nonce'] ) ),
					'bds_login_action'
				)
			) {

				$error = __( 'Security check failed.', 'bds' );

			} else {

				$user_login = sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) );
				$password   = wp_unslash( $_POST['pwd'] ?? '' );
				$remember   = ! empty( $_POST['rememberme'] );

				$redirect_to = isset( $_POST['redirect_to'] )
					? wp_unslash( $_POST['redirect_to'] )
					: admin_url();

				$creds = [
					'user_login' => $user_login,
					'user_password' => $password,
					'remember' => $remember,
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

		self::render_form( $user_login, $error );

	}

	/**
	 * Login HTML
	 */
	private static function render_form( $user_login, $error ) {

		$redirect_to = isset( $_REQUEST['redirect_to'] )
			? wp_unslash( $_REQUEST['redirect_to'] )
			: admin_url();

		$lost_password = wp_lostpassword_url();

		status_header( 200 );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>

			<meta charset="<?php bloginfo('charset'); ?>">
			<meta name="viewport" content="width=device-width,initial-scale=1">

			<title><?php bloginfo('name'); ?> – Login</title>

			<?php
			wp_admin_css('login', true);
			wp_print_styles();
			wp_print_head_scripts();
			?>

		</head>

		<body class="login wp-core-ui">

		<div id="login">

			<?php if ( $error ) : ?>

				<div id="login_error"><?php echo wp_kses_post( $error ); ?></div>

			<?php endif; ?>

			<form method="post">

				<p>
					<label>Benutzername oder E-Mail</label>
					<input type="text" name="log" value="<?php echo esc_attr( $user_login ); ?>" class="input">
				</p>

				<p>
					<label>Passwort</label>
					<input type="password" name="pwd" class="input">
				</p>

				<p class="forgetmenot">

					<label>
						<input type="checkbox" name="rememberme">
						Angemeldet bleiben
					</label>

				</p>

				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">

				<?php wp_nonce_field( 'bds_login_action', 'bds_login_nonce' ); ?>

				<p class="submit">

					<input type="submit" class="button button-primary button-large" value="Anmelden">

				</p>

			</form>

			<p id="nav">
				<a href="<?php echo esc_url( $lost_password ); ?>">Passwort vergessen?</a>
			</p>

		</div>

		<?php wp_print_footer_scripts(); ?>

		</body>
		</html>
		<?php

	}

	/**
	 * Login URL Builder
	 */
	private static function masked_login_url( $redirect_to = '' ) {

		$url = home_url( '/' . self::SLUG . '/' );

		if ( $redirect_to ) {

			$url = add_query_arg( 'redirect_to', $redirect_to, $url );

		}

		return $url;

	}

	private static function is_mask_request(): bool {

		return (bool) get_query_var( 'bds_login' );

	}

	private static function is_wp_admin_request(): bool {

		if ( is_admin() && !( defined('DOING_AJAX') && DOING_AJAX ) ) return true;

		$uri = $_SERVER['REQUEST_URI'] ?? '';

		return stripos( $uri, 'wp-admin' ) !== false;

	}

	private static function is_bypass_context(): bool {

		if (
			( defined('DOING_AJAX') && DOING_AJAX ) ||
			( defined('REST_REQUEST') && REST_REQUEST ) ||
			( defined('WP_CLI') && WP_CLI ) ||
			( defined('DOING_CRON') && DOING_CRON )
		) return true;

		$uri = $_SERVER['REQUEST_URI'] ?? '';

		if ( stripos( $uri, 'xmlrpc.php' ) !== false ) return true;

		return false;

	}

}
