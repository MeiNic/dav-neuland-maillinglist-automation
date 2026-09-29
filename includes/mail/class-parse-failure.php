<?php
/**
 * Internal control flow of Dav_Mlm_Message_Parser: a step that can't
 * continue throws this with one of Dav_Mlm_Parse_Result's failure
 * constants; parse() turns it into a failed result.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Parse_Failure extends RuntimeException {

	private string $reason;

	public function __construct( string $reason, string $detail = '' ) {
		parent::__construct( $detail );
		$this->reason = $reason;
	}

	public function reason(): string {
		return $this->reason;
	}
}
