<?php
/**
 * Turns the raw source of an IONOS "Freigabe" notification into a
 * Dav_Mlm_Parsed_Notification (PLAN.md §4 and §5b step 4c), using
 * ZBateson MailMimeParser (the namespace-prefixed copy from
 * vendor-prefixed/, see composer.json's "extra.strauss").
 *
 * Parsing never decides anything about the post: every problem comes
 * back as a typed failure (Dav_Mlm_Parse_Result) for the runner to file
 * under `Manual`, and an unexpected error inside the library is a
 * failure too, so one malformed message can't abort the run.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DavMlm\Vendor\ZBateson\MailMimeParser\Header\AddressHeader;
use DavMlm\Vendor\ZBateson\MailMimeParser\IMessage;
use DavMlm\Vendor\ZBateson\MailMimeParser\MailMimeParser;
use DavMlm\Vendor\ZBateson\MailMimeParser\Message\PartFilter;

final class Dav_Mlm_Message_Parser {

	private MailMimeParser $mime_parser;

	public function __construct( ?MailMimeParser $mime_parser = null ) {
		$this->mime_parser = $mime_parser ?? new MailMimeParser();
	}

	public function parse( string $raw ): Dav_Mlm_Parse_Result {
		try {
			return Dav_Mlm_Parse_Result::success( $this->parse_or_throw( $raw ) );
		} catch ( Dav_Mlm_Parse_Failure $failure ) {
			return Dav_Mlm_Parse_Result::failure( $failure->reason(), $failure->getMessage() );
		} catch ( Throwable $error ) {
			return Dav_Mlm_Parse_Result::failure( Dav_Mlm_Parse_Result::UNPARSEABLE, get_class( $error ) . ': ' . $error->getMessage() );
		}
	}

	/**
	 * @throws Dav_Mlm_Parse_Failure
	 */
	private function parse_or_throw( string $raw ): Dav_Mlm_Parsed_Notification {
		$message = $this->mime_parser->parse( $raw, true );

		$list_address = $this->list_address( $message );
		$text         = $message->getTextContent();
		$confirm_url  = $this->confirm_url( $text );
		$absender     = $this->absender( $text );

		$nested_part = $message->getChildParts( PartFilter::fromContentType( 'message/rfc822' ) )[0] ?? null;
		if ( null === $nested_part ) {
			throw new Dav_Mlm_Parse_Failure( Dav_Mlm_Parse_Result::NO_NESTED_MESSAGE, 'No message/rfc822 part.' );
		}

		$nested_raw = $nested_part->getContentStream()->getContents();
		$nested     = $this->mime_parser->parse( $nested_raw, true );

		return new Dav_Mlm_Parsed_Notification(
			$list_address,
			$confirm_url,
			$absender,
			$nested_raw,
			$this->single_from_address( $nested ),
			$this->header_value( $nested, 'Subject' ),
			$this->address_value( $this->header_value( $nested, 'Return-Path' ) ),
			$this->header_value( $nested, 'Auto-Submitted' ),
			$this->header_value( $nested, 'Precedence' ),
			$this->header_value( $nested, 'List-Id' )
		);
	}

	/**
	 * @throws Dav_Mlm_Parse_Failure
	 */
	private function list_address( IMessage $message ): string {
		// The library already unfolds the (two-line) subject header.
		$subject = (string) $message->getHeaderValue( 'Subject' );

		if ( 1 !== preg_match( '/\[([^\]]+)\]\s*$/', $subject, $matches ) ) {
			throw new Dav_Mlm_Parse_Failure( Dav_Mlm_Parse_Result::NO_LIST_ADDRESS, 'Subject has no trailing [list address].' );
		}

		return strtolower( trim( $matches[1] ) );
	}

	/**
	 * The text/plain part arrives quoted-printable with the URL soft-wrapped
	 * across lines; MailMimeParser has decoded it by now, so the URL is one
	 * piece. Its `id` token stays percent-encoded — decoding is not ours to do.
	 *
	 * @throws Dav_Mlm_Parse_Failure
	 */
	private function confirm_url( ?string $text ): string {
		if ( null === $text ) {
			throw new Dav_Mlm_Parse_Failure( Dav_Mlm_Parse_Result::NO_CONFIRM_URL, 'No text/plain part.' );
		}

		if ( false !== preg_match_all( '#https?://[^\s<>"\']+#i', $text, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				if ( false !== stripos( $url, '/Mail/Confirm' ) ) {
					return $url;
				}
			}
		}

		throw new Dav_Mlm_Parse_Failure( Dav_Mlm_Parse_Result::NO_CONFIRM_URL, 'No confirm URL in the text/plain part.' );
	}

	private function absender( ?string $text ): ?string {
		if ( null === $text || 1 !== preg_match( '/^[ \t]*Absender:[ \t]*(\S[^\r\n]*?)[ \t]*\r?$/m', $text, $matches ) ) {
			return null;
		}

		return $this->address_value( $matches[1] );
	}

	/**
	 * @throws Dav_Mlm_Parse_Failure
	 */
	private function single_from_address( IMessage $nested ): string {
		if ( count( $nested->getAllHeadersByName( 'From' ) ) > 1 ) {
			throw new Dav_Mlm_Parse_Failure( Dav_Mlm_Parse_Result::MULTIPLE_FROM, 'The post has more than one From header.' );
		}

		$header = $nested->getHeader( 'From' );
		if ( ! $header instanceof AddressHeader ) {
			throw new Dav_Mlm_Parse_Failure( Dav_Mlm_Parse_Result::NO_FROM, 'The post has no From header.' );
		}

		// Group members also show up in getAddresses(), so check groups first.
		if ( count( $header->getGroups() ) > 0 ) {
			throw new Dav_Mlm_Parse_Failure( Dav_Mlm_Parse_Result::GROUP_FROM, 'The post\'s From header uses group syntax.' );
		}

		$addresses = $header->getAddresses();
		if ( 0 === count( $addresses ) ) {
			throw new Dav_Mlm_Parse_Failure( Dav_Mlm_Parse_Result::NO_FROM, 'The post\'s From header has no address.' );
		}
		if ( count( $addresses ) > 1 ) {
			throw new Dav_Mlm_Parse_Failure( Dav_Mlm_Parse_Result::MULTIPLE_FROM, 'The post\'s From header has more than one address.' );
		}

		return $this->validated_address( (string) $addresses[0]->getEmail() );
	}

	/**
	 * Only plain ASCII addresses are accepted: an IDN (raw UTF-8 or
	 * punycode) can't be safely compared with the Absender line or matched
	 * against an admin's regex, so a human decides.
	 *
	 * @throws Dav_Mlm_Parse_Failure
	 */
	private function validated_address( string $email ): string {
		$email = trim( $email );

		if ( 1 === preg_match( '/[^\x20-\x7E]/', $email ) ) {
			throw new Dav_Mlm_Parse_Failure( Dav_Mlm_Parse_Result::IDN_FROM, 'The From address contains non-ASCII characters.' );
		}
		if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			throw new Dav_Mlm_Parse_Failure( Dav_Mlm_Parse_Result::INVALID_FROM, 'The From address is not a valid address: ' . $email );
		}
		if ( 1 === preg_match( '/@(?:[^@]*\.)?xn--[^@]*$/i', $email ) ) {
			throw new Dav_Mlm_Parse_Failure( Dav_Mlm_Parse_Result::IDN_FROM, 'The From address has a punycode (IDN) domain: ' . $email );
		}

		return $email;
	}

	private function header_value( IMessage $message, string $name ): ?string {
		$value = $message->getHeaderValue( $name );

		return null === $value || '' === trim( $value ) ? null : trim( $value );
	}

	/**
	 * `<a@b.c>` → `a@b.c` (Return-Path and Absender may carry angle brackets).
	 */
	private function address_value( ?string $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$value = trim( $value, " \t<>" );

		return '' === $value ? null : $value;
	}
}
