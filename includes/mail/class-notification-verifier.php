<?php
/**
 * Checks that a "Freigabe" notification really comes from IONOS before
 * anything is done with it (PLAN.md §5b step 4b) — otherwise a forged
 * notification could make the plugin send rejection mails to arbitrary
 * addresses. All of these must hold:
 *
 *  - Only the topmost Authentication-Results header counts: each hop
 *    prepends its own, so lower ones may have been supplied by the sender.
 *  - Its authserv-id is the trusted one (DAV_MLM_TRUSTED_AUTHSERV).
 *  - It reports `dkim=pass` whose `header.i`/`header.d` are in oneandone.com.
 *  - The outer From: is exactly one address, equal to DAV_MLM_NOTIFY_FROM.
 *
 * Runs on the raw source, before the message parser: a forged message
 * goes to `Suspicious`, while a genuine one the parser can't handle goes
 * to `Manual`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DavMlm\Vendor\ZBateson\MailMimeParser\Header\AddressHeader;
use DavMlm\Vendor\ZBateson\MailMimeParser\IMessage;
use DavMlm\Vendor\ZBateson\MailMimeParser\MailMimeParser;

final class Dav_Mlm_Notification_Verifier {

	private const SIGNING_DOMAIN = 'oneandone.com';

	private string $notify_from;
	private string $trusted_authserv;
	private MailMimeParser $mime_parser;

	public function __construct( string $notify_from, string $trusted_authserv, ?MailMimeParser $mime_parser = null ) {
		$this->notify_from      = strtolower( trim( $notify_from ) );
		$this->trusted_authserv = strtolower( trim( $trusted_authserv ) );
		$this->mime_parser      = $mime_parser ?? new MailMimeParser();
	}

	public function verify( string $raw ): Dav_Mlm_Verification_Result {
		try {
			$message = $this->mime_parser->parse( $raw, true );

			$authentication_results = $message->getHeader( 'Authentication-Results' );
			if ( null === $authentication_results ) {
				return Dav_Mlm_Verification_Result::failure( Dav_Mlm_Verification_Result::NO_AUTHENTICATION_RESULTS, 'No Authentication-Results header.' );
			}

			$result = $this->check_authentication_results( $authentication_results->getRawValue() );
			if ( ! $result->is_genuine() ) {
				return $result;
			}

			return $this->check_from( $message );
		} catch ( Throwable $error ) {
			return Dav_Mlm_Verification_Result::failure( Dav_Mlm_Verification_Result::UNPARSEABLE, get_class( $error ) . ': ' . $error->getMessage() );
		}
	}

	/**
	 * @param string $header_value The topmost header's raw value (RFC 8601:
	 *                             `authserv-id [version] ; resinfo ; resinfo ...`).
	 */
	private function check_authentication_results( string $header_value ): Dav_Mlm_Verification_Result {
		$segments = explode( ';', $this->without_comments( $header_value ) );

		$authserv_tokens = preg_split( '/\s+/', trim( (string) array_shift( $segments ) ), -1, PREG_SPLIT_NO_EMPTY );
		$authserv_id     = strtolower( (string) ( $authserv_tokens[0] ?? '' ) );

		if ( $authserv_id !== $this->trusted_authserv ) {
			return Dav_Mlm_Verification_Result::failure(
				Dav_Mlm_Verification_Result::UNTRUSTED_AUTHSERV,
				sprintf( 'Topmost Authentication-Results is from "%s", not "%s".', $authserv_id, $this->trusted_authserv )
			);
		}

		foreach ( $segments as $segment ) {
			if ( $this->is_trusted_dkim_pass( $segment ) ) {
				return Dav_Mlm_Verification_Result::genuine();
			}
		}

		return Dav_Mlm_Verification_Result::failure(
			Dav_Mlm_Verification_Result::NO_TRUSTED_DKIM_PASS,
			'Topmost Authentication-Results has no dkim=pass for ' . self::SIGNING_DOMAIN . '.'
		);
	}

	/**
	 * One `method=result property=value ...` entry. Every identity it names
	 * (`header.i`, `header.d`) must be in oneandone.com, and it must name
	 * at least one.
	 */
	private function is_trusted_dkim_pass( string $segment ): bool {
		$tokens = preg_split( '/\s+/', trim( $segment ), -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $tokens ) || 'dkim=pass' !== strtolower( $tokens[0] ) ) {
			return false;
		}

		$domains = array();
		foreach ( array_slice( $tokens, 1 ) as $token ) {
			if ( 1 !== preg_match( '/^header\.(i|d)=(.*)$/i', $token, $matches ) ) {
				continue;
			}

			$value = strtolower( trim( $matches[2], '"' ) );
			if ( 'i' === strtolower( $matches[1] ) && false !== strrpos( $value, '@' ) ) {
				$value = substr( $value, (int) strrpos( $value, '@' ) + 1 ); // header.i is an address; only its domain counts.
			}
			$domains[] = $value;
		}

		if ( empty( $domains ) ) {
			return false;
		}

		foreach ( $domains as $domain ) {
			if ( self::SIGNING_DOMAIN !== $domain && ! str_ends_with( $domain, '.' . self::SIGNING_DOMAIN ) ) {
				return false;
			}
		}

		return true;
	}

	private function check_from( IMessage $message ): Dav_Mlm_Verification_Result {
		$wrong = static fn ( string $detail ) => Dav_Mlm_Verification_Result::failure( Dav_Mlm_Verification_Result::WRONG_FROM, $detail );

		if ( count( $message->getAllHeadersByName( 'From' ) ) > 1 ) {
			return $wrong( 'More than one From header.' );
		}

		$header = $message->getHeader( 'From' );
		if ( ! $header instanceof AddressHeader || count( $header->getGroups() ) > 0 || 1 !== count( $header->getAddresses() ) ) {
			return $wrong( 'The From header is not exactly one plain address.' );
		}

		$from = strtolower( trim( (string) $header->getAddresses()[0]->getEmail() ) );
		if ( $from !== $this->notify_from ) {
			return $wrong( sprintf( 'From is "%s", not "%s".', $from, $this->notify_from ) );
		}

		return Dav_Mlm_Verification_Result::genuine();
	}

	/**
	 * Drops RFC 5322 comments (which may nest), e.g. `(x=y; foo)`, so a
	 * comment can neither hide nor fake a result.
	 */
	private function without_comments( string $value ): string {
		do {
			$value = (string) preg_replace( '/\([^()]*\)/', ' ', $value, -1, $count );
		} while ( $count > 0 );

		return $value;
	}
}
