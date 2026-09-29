<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wp-stubs.php';

use PHPUnit\Framework\TestCase;

final class DoneListTest extends TestCase {

	protected function setUp(): void {
		dav_mlm_test_reset_wp_state();
	}

	public function test_an_unknown_message_is_not_done(): void {
		self::assertFalse( ( new Dav_Mlm_Done_List() )->is_done( '<unknown@example.com>' ) );
		self::assertNull( ( new Dav_Mlm_Done_List() )->get( '<unknown@example.com>' ) );
	}

	public function test_record_then_get_round_trips_action_and_time(): void {
		$list = new Dav_Mlm_Done_List();
		$time = new DateTimeImmutable( '2026-09-29 12:00:00' );

		$list->record( '<a@example.com>', 'approved', $time );

		self::assertTrue( $list->is_done( '<a@example.com>' ) );
		self::assertSame(
			array(
				'action' => 'approved',
				'time'   => $time->format( DateTimeInterface::ATOM ),
			),
			$list->get( '<a@example.com>' )
		);
	}

	public function test_re_recording_the_same_message_overwrites_its_entry(): void {
		$list = new Dav_Mlm_Done_List();

		$list->record( '<a@example.com>', 'approved', new DateTimeImmutable( '2026-09-29 12:00:00' ) );
		$list->record( '<a@example.com>', 'rejected', new DateTimeImmutable( '2026-09-29 12:05:00' ) );

		self::assertSame( 'rejected', $list->get( '<a@example.com>' )['action'] );
	}

	public function test_list_is_trimmed_to_max_entries_dropping_the_oldest_first(): void {
		$list = new Dav_Mlm_Done_List();

		for ( $i = 0; $i < Dav_Mlm_Done_List::MAX_ENTRIES + 10; $i++ ) {
			$list->record( "<msg-{$i}@example.com>", 'approved' );
		}

		self::assertFalse( $list->is_done( '<msg-0@example.com>' ) );
		self::assertFalse( $list->is_done( '<msg-9@example.com>' ) );
		self::assertTrue( $list->is_done( '<msg-10@example.com>' ) );
		self::assertTrue( $list->is_done( '<msg-' . ( Dav_Mlm_Done_List::MAX_ENTRIES + 9 ) . '@example.com>' ) );
	}
}
