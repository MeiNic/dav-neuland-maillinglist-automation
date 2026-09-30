<?php
/**
 * Scrubs the one thing that must never appear verbatim in a log line: the
 * IONOS confirm-link token (PLAN.md §8 — "mask the confirm id token").
 * Callers are still responsible for not logging message bodies etc.; this
 * class is a mechanical, defense-in-depth safety net, not a policy engine.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Log_Masker {

	private const MASK = '***';

	/**
	 * A context key is sensitive when one of its words (split on `_`, `-`,
	 * `.`, ...) is listed here — so `smtp_pass` and `confirm_token` are,
	 * but `passed_path` or `bypass` are not. Deliberately narrow — e.g. a
	 * bare "id" is NOT listed, because list ids and Message-IDs are meant
	 * to be logged (PLAN.md line 229) and "id" alone can't tell those apart
	 * from a confirm token.
	 */
	private const SENSITIVE_KEY_WORDS = array( 'token', 'pass', 'password', 'passwd', 'secret' );

	/**
	 * Whole keys that are sensitive although none of their words is.
	 */
	private const SENSITIVE_KEYS = array( 'confirm_id' );

	/**
	 * Masks a `?id=...` / `&id=...` (or `token=...`) query parameter
	 * wherever it appears in a string — this is how the confirm URL's
	 * token shows up (PLAN.md §4), and is the only shape the token
	 * appears in that a free-text message could plausibly contain.
	 */
	public function mask_message( string $message ): string {
		$masked = preg_replace( '/([?&](?:id|token)=)[^&\s]+/i', '$1' . self::MASK, $message );

		return null !== $masked ? $masked : $message;
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	public function mask_context( array $context ): array {
		$masked = array();

		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && $this->is_sensitive_key( $key ) ) {
				$masked[ $key ] = self::MASK;
				continue;
			}

			if ( is_array( $value ) ) {
				$masked[ $key ] = $this->mask_context( $value );
				continue;
			}

			$masked[ $key ] = is_string( $value ) ? $this->mask_message( $value ) : $value;
		}

		return $masked;
	}

	private function is_sensitive_key( string $key ): bool {
		$key = strtolower( $key );

		if ( in_array( $key, self::SENSITIVE_KEYS, true ) ) {
			return true;
		}

		$words = preg_split( '/[^a-z0-9]+/', $key, -1, PREG_SPLIT_NO_EMPTY );

		return array() !== array_intersect( false !== $words ? $words : array(), self::SENSITIVE_KEY_WORDS );
	}
}
