<?php
/**
 * Minimal WordPress function stubs for the handful of tests that exercise
 * a WP-dependent default or the option/transient-backed state classes.
 * Not a WordPress test suite — just enough in-memory storage to keep
 * those specific behaviours testable without one. Real expiry of
 * transients is WP's own mechanism, not ours, so it's not modelled here;
 * these stubs only need to round-trip values within a single test.
 */

declare(strict_types=1);

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['dav_mlm_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['dav_mlm_test_options'][ $name ]                = $value;
		$GLOBALS['dav_mlm_test_update_option_autoload'][ $name ] = $autoload;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		unset( $GLOBALS['dav_mlm_test_options'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['dav_mlm_test_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		$GLOBALS['dav_mlm_test_transients'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['dav_mlm_test_transients'][ $key ] );

		return true;
	}
}

function dav_mlm_test_set_option( string $name, $value ): void {
	$GLOBALS['dav_mlm_test_options'][ $name ] = $value;
}

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {

		public function __construct( private string $code = '', private string $message = '' ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * @return string|false The address on success (matching real WP's
	 *                       is_email()), or false.
	 */
	function is_email( string $email ) {
		$valid = filter_var( $email, FILTER_VALIDATE_EMAIL );

		return false === $valid ? false : $email;
	}
}

/**
 * Stands in for the handful of PHPMailer properties/methods the
 * `phpmailer_init` hook touches (PLAN.md §5b step 4j). Not a real
 * PHPMailer — WordPress core normally supplies that class, which isn't
 * loaded in this test environment.
 */
if ( ! class_exists( 'Dav_Mlm_Test_Fake_Phpmailer' ) ) {
	final class Dav_Mlm_Test_Fake_Phpmailer {
		public bool $smtp_enabled = false;
		public $Host;
		public $Port;
		public $SMTPAuth;
		public $Username;
		public $Password;
		public $SMTPSecure;

		public function isSMTP(): void {
			$this->smtp_enabled = true;
		}
	}
}

/**
 * Actions are kept with their priority, like WordPress: remove_action()
 * only removes a callback registered at the same priority, and
 * do_action() runs lower priorities first.
 */
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['dav_mlm_test_actions'][ $hook ][] = array(
			'callback' => $callback,
			'priority' => $priority,
		);

		return true;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( string $hook, callable $callback, int $priority = 10 ): bool {
		foreach ( $GLOBALS['dav_mlm_test_actions'][ $hook ] ?? array() as $i => $registered ) {
			if ( $registered['callback'] === $callback && $registered['priority'] === $priority ) {
				unset( $GLOBALS['dav_mlm_test_actions'][ $hook ][ $i ] );
			}
		}

		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$registered = $GLOBALS['dav_mlm_test_actions'][ $hook ] ?? array();
		usort( $registered, static fn ( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );

		foreach ( $registered as $action ) {
			$action['callback']( ...$args );
		}
	}
}

/**
 * Stands in for wp_mail(): records the call (including the fake
 * PHPMailer instance passed through `phpmailer_init`, so tests can assert
 * on what the SMTP hook configured, and the priorities `phpmailer_init`
 * callbacks were registered at) and returns the next queued result
 * (dav_mlm_test_queue_wp_mail_results()), else whatever
 * dav_mlm_test_set_wp_mail_result() was told to, defaulting to true. On
 * false it fires `wp_mail_failed` with a WP_Error, as WordPress does.
 */
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, string $subject, string $message, $headers = '', $attachments = array() ): bool {
		$mailer = new Dav_Mlm_Test_Fake_Phpmailer();
		do_action( 'phpmailer_init', $mailer );

		$GLOBALS['dav_mlm_test_wp_mail_calls'][] = array(
			'to'                        => $to,
			'subject'                   => $subject,
			'message'                   => $message,
			'headers'                   => $headers,
			'mailer'                    => $mailer,
			'phpmailer_init_priorities' => array_column( $GLOBALS['dav_mlm_test_actions']['phpmailer_init'] ?? array(), 'priority' ),
		);

		$result = array() !== ( $GLOBALS['dav_mlm_test_wp_mail_results'] ?? array() )
			? array_shift( $GLOBALS['dav_mlm_test_wp_mail_results'] )
			: ( $GLOBALS['dav_mlm_test_wp_mail_result'] ?? true );

		if ( ! $result ) {
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP Error: Could not authenticate.' ) );
		}

		return $result;
	}
}

function dav_mlm_test_set_wp_mail_result( bool $result ): void {
	$GLOBALS['dav_mlm_test_wp_mail_result'] = $result;
}

/**
 * Results for the next wp_mail() calls, in order; once used up, the
 * dav_mlm_test_set_wp_mail_result() value applies again.
 */
function dav_mlm_test_queue_wp_mail_results( bool ...$results ): void {
	$GLOBALS['dav_mlm_test_wp_mail_results'] = $results;
}

/**
 * Stands in for wp_safe_remote_get(): returns whatever
 * dav_mlm_test_set_http_response() was told to return, or a WP_Error if
 * no test in the current test method set one (so a forgotten stub fails
 * loudly instead of silently "succeeding").
 */
if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( string $url, array $args = array() ) {
		$GLOBALS['dav_mlm_test_http_requests'][] = array(
			'url'  => $url,
			'args' => $args,
		);

		return $GLOBALS['dav_mlm_test_http_response'] ?? new WP_Error( 'test_no_response', 'No dav_mlm_test_set_http_response() call for this test.' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'] ?? '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return $response['body'] ?? '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, string $header ) {
		return $response['headers'][ strtolower( $header ) ] ?? '';
	}
}

/**
 * @param WP_Error|array{response?: array{code: int}, body?: string, headers?: array<string, string>} $response
 */
function dav_mlm_test_set_http_response( $response ): void {
	$GLOBALS['dav_mlm_test_http_response'] = $response;
}

/**
 * Resets the in-memory options/transients stores. Call from setUp() in
 * any test that uses these stubs, since $GLOBALS otherwise leaks state
 * between test methods in the same process.
 */
function dav_mlm_test_reset_wp_state(): void {
	$GLOBALS['dav_mlm_test_options']                = array();
	$GLOBALS['dav_mlm_test_transients']             = array();
	$GLOBALS['dav_mlm_test_update_option_autoload'] = array();
	$GLOBALS['dav_mlm_test_http_response']          = null;
	$GLOBALS['dav_mlm_test_http_requests']          = array();
	$GLOBALS['dav_mlm_test_actions']                = array();
	$GLOBALS['dav_mlm_test_wp_mail_calls']          = array();
	$GLOBALS['dav_mlm_test_wp_mail_result']         = null;
	$GLOBALS['dav_mlm_test_wp_mail_results']        = array();
}
