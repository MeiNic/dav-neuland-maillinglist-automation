<?php
/**
 * Outcome of Dav_Mlm_List_Sanitizer::apply(). On any error, lists() is
 * the unchanged current configuration — a rejected edit never partially
 * lands. Warnings don't block the save (e.g. an unknown `{{placeholder}}`
 * that would be sent verbatim).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_List_Sanitize_Result {

	private array $lists;
	private array $errors;
	private array $warnings;

	/**
	 * @param list<array<string, mixed>> $lists
	 * @param list<string>               $errors
	 * @param list<string>               $warnings
	 */
	public function __construct( array $lists, array $errors = array(), array $warnings = array() ) {
		$this->lists    = $lists;
		$this->errors   = $errors;
		$this->warnings = $warnings;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function lists(): array {
		return $this->lists;
	}

	public function has_errors(): bool {
		return array() !== $this->errors;
	}

	/**
	 * @return list<string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * @return list<string>
	 */
	public function warnings(): array {
		return $this->warnings;
	}
}
