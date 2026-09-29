<?php
/**
 * Exact header lookup for security-relevant headers. MailMimeParser's own
 * getHeader()/getAllHeadersByName() match on a normalized name that drops
 * every non-alphanumeric character, so `Authentication_Results` or
 * `Fr_om` would be served as `Authentication-Results` / `From` whenever
 * no exact header exists. Mail servers don't do that, so trusting the
 * library's leniency could let a sender supply a header the real
 * infrastructure never treated as one.
 *
 * Here a header matches only if its name equals the requested one,
 * case-insensitively (RFC 5322), and results keep message order, i.e.
 * the topmost header comes first.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DavMlm\Vendor\ZBateson\MailMimeParser\Header\IHeader;
use DavMlm\Vendor\ZBateson\MailMimeParser\Message\IMimePart;

final class Dav_Mlm_Header_Lookup {

	/**
	 * @return list<IHeader>
	 */
	public static function all( IMimePart $part, string $name ): array {
		$matches = array();
		foreach ( $part->getAllHeaders() as $header ) {
			if ( 0 === strcasecmp( $header->getName(), $name ) ) {
				$matches[] = $header;
			}
		}

		return $matches;
	}

	/**
	 * The topmost header with this name, or null.
	 */
	public static function first( IMimePart $part, string $name ): ?IHeader {
		return self::all( $part, $name )[0] ?? null;
	}

	/**
	 * Trimmed decoded value of the topmost header with this name; null if
	 * there is none or it is empty.
	 */
	public static function first_value( IMimePart $part, string $name ): ?string {
		$header = self::first( $part, $name );
		$value  = null === $header ? '' : trim( $header->getValue() ?? '' );

		return '' === $value ? null : $value;
	}
}
