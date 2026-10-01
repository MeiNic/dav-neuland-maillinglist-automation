<?php
/**
 * Validation behind the `dav_mlm_lists` sanitize callback (PLAN.md §5a,
 * §8), kept free of settings-API plumbing so it's testable on its own.
 *
 * WordPress runs the sanitize callback on *every* write of the option,
 * so apply() accepts two input shapes:
 * - a form submission from the admin page: `op` = `save` (one list,
 *   `id` empty for a new one) or `delete` (by `id`), merged into the
 *   current configuration;
 * - the full list array, e.g. WordPress's second pass through the
 *   callback when update_option() falls through to add_option() —
 *   every row is re-validated, which is a no-op for already-clean rows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_List_Sanitizer {

	public const DKIM_POLICIES = array( 'require', 'off' );
	public const REJECT_MODES  = array( 'email', 'silent' );

	/** @var callable(): string */
	private $id_generator;

	private Dav_Mlm_Admin_Language $language;

	/**
	 * @param (callable(): string)|null    $id_generator Defaults to wp_generate_uuid4().
	 * @param Dav_Mlm_Admin_Language|null $language     For the error/warning messages; defaults to English.
	 */
	public function __construct( ?callable $id_generator = null, ?Dav_Mlm_Admin_Language $language = null ) {
		$this->id_generator = $id_generator ?? 'wp_generate_uuid4';
		$this->language     = $language ?? new Dav_Mlm_Admin_Language();
	}

	/**
	 * @param list<array<string, mixed>> $current
	 */
	public function apply( mixed $input, array $current ): Dav_Mlm_List_Sanitize_Result {
		if ( ! is_array( $input ) ) {
			return new Dav_Mlm_List_Sanitize_Result( $current, array( $this->t( 'Invalid submission.' ) ) );
		}

		if ( ! isset( $input['op'] ) ) {
			return $this->sanitize_all( $input, $current );
		}

		switch ( $input['op'] ) {
			case 'save':
				return $this->save( is_array( $input['list'] ?? null ) ? $input['list'] : array(), $current );
			case 'delete':
				return $this->delete( (string) ( $input['id'] ?? '' ), $current );
			default:
				return new Dav_Mlm_List_Sanitize_Result( $current, array( $this->t( 'Unknown operation.' ) ) );
		}
	}

	private function sanitize_all( array $input, array $current ): Dav_Mlm_List_Sanitize_Result {
		$lists    = array();
		$errors   = array();
		$warnings = array();

		foreach ( $input as $raw ) {
			if ( ! is_array( $raw ) || '' === (string) ( $raw['id'] ?? '' ) ) {
				$errors[] = $this->t( 'Every stored list needs an id.' );
				continue;
			}

			[ $list, $row_errors, $row_warnings ] = $this->sanitize_list( $raw, (string) $raw['id'], $lists );

			$lists[]  = $list;
			$errors   = array_merge( $errors, $row_errors );
			$warnings = array_merge( $warnings, $row_warnings );
		}

		return $this->result( $lists, $errors, $warnings, $current );
	}

	private function save( array $raw, array $current ): Dav_Mlm_List_Sanitize_Result {
		$id = trim( (string) ( $raw['id'] ?? '' ) );

		if ( '' !== $id && null === $this->index_of( $id, $current ) ) {
			return new Dav_Mlm_List_Sanitize_Result( $current, array( $this->t( 'This list no longer exists — it may have been deleted in the meantime.' ) ) );
		}

		$is_new = '' === $id;
		if ( $is_new ) {
			$id = ( $this->id_generator )();
		}

		$others = array_values( array_filter( $current, static fn ( array $list ): bool => $list['id'] !== $id ) );

		[ $list, $errors, $warnings ] = $this->sanitize_list( $raw, $id, $others );

		$lists = $current;
		if ( $is_new ) {
			$lists[] = $list;
		} else {
			$lists[ $this->index_of( $id, $current ) ] = $list;
		}

		return $this->result( $lists, $errors, $warnings, $current );
	}

	private function delete( string $id, array $current ): Dav_Mlm_List_Sanitize_Result {
		$index = $this->index_of( $id, $current );
		if ( null === $index ) {
			return new Dav_Mlm_List_Sanitize_Result( $current, array( $this->t( 'This list no longer exists — it may have been deleted in the meantime.' ) ) );
		}

		$lists = $current;
		unset( $lists[ $index ] );

		return new Dav_Mlm_List_Sanitize_Result( array_values( $lists ) );
	}

	/**
	 * @param list<array<string, mixed>> $others Lists the address must not duplicate.
	 * @return array{0: array<string, mixed>, 1: list<string>, 2: list<string>}
	 */
	private function sanitize_list( array $raw, string $id, array $others ): array {
		$errors   = array();
		$warnings = array();

		$list_address = strtolower( trim( (string) ( $raw['list_address'] ?? '' ) ) );
		$label        = '' === $list_address ? $this->t( 'New list' ) : $list_address;

		if ( false === is_email( $list_address ) ) {
			$errors[] = sprintf( $this->t( '%s: the list address is not a valid email address.' ), $label );
		} elseif ( in_array( $list_address, array_column( $others, 'list_address' ), true ) ) {
			$errors[] = sprintf( $this->t( '%s: another list already uses this address.' ), $label );
		}

		$regex       = trim( (string) ( $raw['regex'] ?? '' ) );
		$regex_error = '' === $regex ? null : self::regex_error( $regex );
		if ( '' === $regex ) {
			$errors[] = sprintf( $this->t( '%s: the sender regex is empty.' ), $label );
		} elseif ( null !== $regex_error ) {
			$errors[] = sprintf( $this->t( '%s: the sender regex is invalid (%s).' ), $label, $regex_error );
		}

		$dkim_policy = (string) ( $raw['dkim_policy'] ?? 'require' );
		if ( ! in_array( $dkim_policy, self::DKIM_POLICIES, true ) ) {
			$errors[] = sprintf( $this->t( '%s: unknown DKIM policy.' ), $label );
		}

		$reject_mode = (string) ( $raw['reject_mode'] ?? 'email' );
		if ( ! in_array( $reject_mode, self::REJECT_MODES, true ) ) {
			$errors[] = sprintf( $this->t( '%s: unknown rejection mode.' ), $label );
		}

		$reject_subject = $this->sanitize_single_line( (string) ( $raw['reject_subject'] ?? '' ) );
		$reject_body    = $this->sanitize_multi_line( (string) ( $raw['reject_body'] ?? '' ) );
		if ( ! mb_check_encoding( $reject_subject, 'UTF-8' ) || ! mb_check_encoding( $reject_body, 'UTF-8' ) ) {
			$errors[] = sprintf( $this->t( '%s: the rejection subject/body is not valid UTF-8.' ), $label );
		}
		if ( 'email' === $reject_mode && ( '' === $reject_subject || '' === trim( $reject_body ) ) ) {
			$errors[] = sprintf( $this->t( '%s: rejection by email needs a subject and a body.' ), $label );
		}

		$unknown = array_diff( $this->placeholders_in( $reject_subject . "\n" . $reject_body ), Dav_Mlm_Rejector::PLACEHOLDERS );
		if ( array() !== $unknown ) {
			$warnings[] = sprintf(
				$this->t( '%s: unknown placeholder(s) %s will be sent as-is. Available: %s.' ),
				$label,
				implode( ', ', $unknown ),
				implode( ', ', Dav_Mlm_Rejector::PLACEHOLDERS )
			);
		}

		$reply_to = trim( (string) ( $raw['reply_to'] ?? '' ) );
		if ( '' !== $reply_to && false === is_email( $reply_to ) ) {
			$errors[] = sprintf( $this->t( '%s: the Reply-To address is not a valid email address.' ), $label );
		}

		$list = array(
			'id'             => $id,
			'list_address'   => $list_address,
			'regex'          => $regex,
			'dkim_policy'    => $dkim_policy,
			'reject_mode'    => $reject_mode,
			'reject_subject' => $reject_subject,
			'reject_body'    => $reject_body,
			'reply_to'       => '' === $reply_to ? null : $reply_to,
			'active'         => ! empty( $raw['active'] ),
		);

		return array( $list, $errors, $warnings );
	}

	/**
	 * Null if the pattern compiles, else PCRE's compile error. Also used
	 * by the admin page's "test regex" helper.
	 */
	public static function regex_error( string $regex ): ?string {
		error_clear_last();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- compile probe; the warning is read back via error_get_last().
		if ( false === @preg_match( $regex, '' ) ) {
			$error = error_get_last();

			return null !== $error ? preg_replace( '/^preg_match\(\): /', '', $error['message'] ) : preg_last_error_msg();
		}

		return null;
	}

	/**
	 * The subject becomes a mail header: no line breaks or other control
	 * characters (tabs become spaces).
	 */
	private function sanitize_single_line( string $value ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value ) );
	}

	/**
	 * Plain-text mail body: normalised to \n line endings, other control
	 * characters except tabs removed. No HTML stripping — the body is sent
	 * as text/plain and escaped on output, and stripping would eat things
	 * like `<vorstand@dav-neuland.de>`.
	 */
	private function sanitize_multi_line( string $value ): string {
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );

		return rtrim( (string) preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '', $value ) );
	}

	/**
	 * @return list<string>
	 */
	private function placeholders_in( string $text ): array {
		preg_match_all( '/\{\{[^{}]*\}\}/', $text, $matches );

		return array_values( array_unique( $matches[0] ) );
	}

	private function t( string $text ): string {
		return $this->language->t( $text );
	}

	private function index_of( string $id, array $lists ): ?int {
		foreach ( $lists as $index => $list ) {
			if ( ( $list['id'] ?? null ) === $id ) {
				return $index;
			}
		}

		return null;
	}

	private function result( array $lists, array $errors, array $warnings, array $current ): Dav_Mlm_List_Sanitize_Result {
		if ( array() !== $errors ) {
			return new Dav_Mlm_List_Sanitize_Result( $current, $errors, $warnings );
		}

		return new Dav_Mlm_List_Sanitize_Result( $lists, array(), $warnings );
	}
}
