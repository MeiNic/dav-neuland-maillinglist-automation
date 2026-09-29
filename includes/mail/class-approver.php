<?php
/**
 * Validates a notification's confirm URL against a strict allowlist and,
 * if it passes, sends the approval GET (PLAN.md §5b steps 4d and 4i).
 *
 * The allowlist runs before any outbound request — an unrecognised host,
 * scheme, path, or query string never gets fetched. `parse_url()` (not
 * string matching) is what makes the `user@host` trick
 * (`https://ml.kundenserver.de@evil.example/`) safe: PHP resolves the
 * host there to `evil.example`, so the host check alone rejects it.
 *
 * The success marker and the "already confirmed"/"invalid" ambiguity
 * come from the live investigation in issue #8 — see Dav_Mlm_Approve_Result.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Approver {

	private const SUCCESS_MARKER = 'Der Vorgang war erfolgreich.';

	private const PATH_PATTERN = '#^/MailingList/([^/]+)/Mail/Confirm$#i';

	private string $confirm_host;

	public function __construct( string $confirm_host ) {
		$this->confirm_host = strtolower( trim( $confirm_host ) );
	}

	public function approve( string $confirm_url, string $list_address ): Dav_Mlm_Approve_Result {
		if ( ! $this->is_allowlisted( $confirm_url, $list_address ) ) {
			return Dav_Mlm_Approve_Result::failure( Dav_Mlm_Approve_Result::SUSPICIOUS_URL, 'Confirm URL failed the allowlist.' );
		}

		$response = wp_safe_remote_get(
			$confirm_url,
			array(
				'redirection' => 0,
				'timeout'     => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return Dav_Mlm_Approve_Result::failure( Dav_Mlm_Approve_Result::NETWORK_ERROR, $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = (string) wp_remote_retrieve_body( $response );

		if ( 200 === $status_code && false !== strpos( $body, self::SUCCESS_MARKER ) ) {
			return Dav_Mlm_Approve_Result::success();
		}

		$detail = 'HTTP ' . $status_code;
		if ( $status_code >= 300 && $status_code < 400 ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( is_string( $location ) && '' !== $location ) {
				$detail .= ', not followed, Location: ' . $location;
			}
		}

		return Dav_Mlm_Approve_Result::failure( Dav_Mlm_Approve_Result::NOT_CONFIRMED, $detail );
	}

	/**
	 * Scheme `https`, host == the configured confirm host, path ==
	 * `/MailingList/<list-address>/Mail/Confirm` (the list-address segment
	 * compared case-insensitively after rawurldecode, so both the literal
	 * `@` and `%40` forms are accepted), query has exactly `lang` and `id`.
	 */
	public function is_allowlisted( string $confirm_url, string $list_address ): bool {
		$parts = parse_url( $confirm_url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'], $parts['path'] ) ) {
			return false;
		}

		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}

		if ( strtolower( $parts['host'] ) !== $this->confirm_host ) {
			return false;
		}

		if ( 1 !== preg_match( self::PATH_PATTERN, $parts['path'], $matches ) ) {
			return false;
		}

		if ( strtolower( rawurldecode( $matches[1] ) ) !== strtolower( $list_address ) ) {
			return false;
		}

		parse_str( $parts['query'] ?? '', $query );
		$keys = array_keys( $query );
		sort( $keys );

		return array( 'id', 'lang' ) === $keys;
	}
}
