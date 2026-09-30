<?php
/**
 * Everything the runner needs from one IONOS "Freigabe" notification
 * (PLAN.md §4 / §5b step 4c), as produced by Dav_Mlm_Message_Parser.
 *
 * `nested_raw` is the original post exactly as MailMimeParser hands it
 * out (the DKIM verifier needs the bytes); every other `nested_*` field
 * was read from that message. Header fields the post doesn't have are null.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Parsed_Notification {

	public function __construct(
		public readonly string $list_address,
		public readonly string $confirm_url,
		public readonly ?string $absender,
		public readonly string $nested_raw,
		public readonly string $nested_from,
		public readonly ?string $nested_subject,
		public readonly ?string $nested_return_path,
		public readonly ?string $nested_auto_submitted,
		public readonly ?string $nested_precedence,
		public readonly ?string $nested_list_id,
		public readonly ?string $nested_list_unsubscribe
	) {}
}
