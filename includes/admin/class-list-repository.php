<?php
/**
 * Read access to the per-list configuration (option `dav_mlm_lists`,
 * PLAN.md §7) for the admin page and the cron runner. Deliberately
 * read-only: writes go through the settings API (Dav_Mlm_Admin_Settings)
 * so every change passes the registered sanitize callback.
 *
 * Addresses are compared lowercased: the sanitizer stores them that way,
 * and the parser hands over whatever case IONOS used in the notification.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_List_Repository {

	public const OPTION = 'dav_mlm_lists';

	private Dav_Mlm_Option_Store $options;

	public function __construct( ?Dav_Mlm_Option_Store $options = null ) {
		$this->options = $options ?? new Dav_Mlm_Option_Store();
	}

	/**
	 * @return list<array{
	 *     id: string,
	 *     list_address: string,
	 *     regex: string,
	 *     dkim_policy: string,
	 *     reject_mode: string,
	 *     reject_subject: string,
	 *     reject_body: string,
	 *     reply_to: ?string,
	 *     active: bool
	 * }>
	 */
	public function all(): array {
		$lists = $this->options->get( self::OPTION, array() );

		return is_array( $lists ) ? array_values( array_filter( $lists, 'is_array' ) ) : array();
	}

	public function find( string $id ): ?array {
		foreach ( $this->all() as $list ) {
			if ( $list['id'] === $id ) {
				return $list;
			}
		}

		return null;
	}

	/**
	 * Active or not — the caller decides what an inactive list means
	 * (PLAN.md §8: never auto-approved or auto-rejected).
	 */
	public function find_by_address( string $address ): ?array {
		$address = strtolower( trim( $address ) );

		foreach ( $this->all() as $list ) {
			if ( $list['list_address'] === $address ) {
				return $list;
			}
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	public function active_addresses(): array {
		$addresses = array();
		foreach ( $this->all() as $list ) {
			if ( $list['active'] ) {
				$addresses[] = $list['list_address'];
			}
		}

		return $addresses;
	}
}
