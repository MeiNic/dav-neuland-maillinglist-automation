<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wp-stubs.php';

use PHPUnit\Framework\TestCase;

final class ListRepositoryTest extends TestCase {

	protected function setUp(): void {
		dav_mlm_test_reset_wp_state();
	}

	public function test_all_is_empty_when_the_option_is_unset_or_not_an_array(): void {
		self::assertSame( array(), ( new Dav_Mlm_List_Repository() )->all() );

		dav_mlm_test_set_option( 'dav_mlm_lists', 'garbage' );
		self::assertSame( array(), ( new Dav_Mlm_List_Repository() )->all() );
	}

	public function test_find_by_id(): void {
		dav_mlm_test_set_option( 'dav_mlm_lists', array( $this->list( 'a', 'a@dav-neuland.de', true ), $this->list( 'b', 'b@dav-neuland.de', true ) ) );
		$repository = new Dav_Mlm_List_Repository();

		self::assertSame( 'b@dav-neuland.de', $repository->find( 'b' )['list_address'] );
		self::assertNull( $repository->find( 'c' ) );
	}

	public function test_find_by_address_ignores_case_and_whitespace_and_includes_inactive_lists(): void {
		dav_mlm_test_set_option( 'dav_mlm_lists', array( $this->list( 'a', 'test.mailingliste@dav-neuland.de', false ) ) );
		$repository = new Dav_Mlm_List_Repository();

		self::assertSame( 'a', $repository->find_by_address( ' Test.Mailingliste@DAV-Neuland.de ' )['id'] );
		self::assertNull( $repository->find_by_address( 'other@dav-neuland.de' ) );
	}

	public function test_active_addresses_skips_inactive_lists(): void {
		dav_mlm_test_set_option( 'dav_mlm_lists', array( $this->list( 'a', 'a@dav-neuland.de', true ), $this->list( 'b', 'b@dav-neuland.de', false ) ) );

		self::assertSame( array( 'a@dav-neuland.de' ), ( new Dav_Mlm_List_Repository() )->active_addresses() );
	}

	private function list( string $id, string $address, bool $active ): array {
		return array(
			'id'             => $id,
			'list_address'   => $address,
			'regex'          => '/@dav-neuland\.de\z/i',
			'dkim_policy'    => 'require',
			'reject_mode'    => 'silent',
			'reject_subject' => '',
			'reject_body'    => '',
			'reply_to'       => null,
			'active'         => $active,
		);
	}
}
