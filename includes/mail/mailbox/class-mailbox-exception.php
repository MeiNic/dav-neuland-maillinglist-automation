<?php
/**
 * Any failure talking to the mail server (connect, search, fetch, move).
 * Always treated as transient by the runner (PLAN.md §5b step 4k): the
 * message stays in INBOX and the attempt counter is incremented.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Mailbox_Exception extends RuntimeException {
}
