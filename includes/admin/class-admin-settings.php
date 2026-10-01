<?php
/**
 * Settings → Mailinglist Moderation: add / edit / delete the per-list
 * configuration (PLAN.md §5a) stored in option `dav_mlm_lists`.
 *
 * Every save and delete is a POST to options.php for the registered
 * setting, so WordPress itself enforces the nonce (`dav_mlm-options`) and
 * the `manage_options` capability before our sanitize callback runs; the
 * callback checks the capability again for form submissions, and
 * render_page() checks it before showing anything. The sanitize callback
 * merges the one submitted list into the stored configuration
 * (Dav_Mlm_List_Sanitizer) — on any validation error the stored value is
 * left unchanged and the submitted form is stashed briefly per user, so
 * the edit form comes back pre-filled instead of losing what was typed.
 *
 * All strings rendered from stored or request data go through esc_html /
 * esc_attr / esc_textarea / esc_url. UI text is English or German,
 * switchable per user on the page (Dav_Mlm_Admin_Language).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Admin_Settings {

	private const PAGE_SLUG    = 'dav-mlm';
	private const OPTION_GROUP = 'dav_mlm';
	private const FORM_STASH   = 'dav_mlm_form_';

	private const LANGUAGE_ARG = 'dav_mlm_language';

	private Dav_Mlm_List_Repository $repository;
	private ?Dav_Mlm_List_Sanitizer $sanitizer;
	private ?Dav_Mlm_Admin_Language $language;

	/**
	 * The sanitizer and language default lazily: this object is built
	 * while the plugin file loads, before WordPress knows the current
	 * user whose language they depend on.
	 */
	public function __construct(
		?Dav_Mlm_List_Repository $repository = null,
		?Dav_Mlm_List_Sanitizer $sanitizer = null,
		?Dav_Mlm_Admin_Language $language = null
	) {
		$this->repository = $repository ?? new Dav_Mlm_List_Repository();
		$this->sanitizer  = $sanitizer;
		$this->language   = $language;
	}

	/**
	 * Creates the option up front with autoload off (PLAN.md §8). It also
	 * means the first save is a plain update: when update_option() finds
	 * no row it falls through to add_option(), which runs the sanitize
	 * callback a second time and would report every warning twice.
	 */
	public static function activate(): void {
		add_option( Dav_Mlm_List_Repository::OPTION, array(), '', false );
	}

	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Dav_Mlm_List_Repository::OPTION,
			array(
				'type'              => 'array',
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize_option' ),
			)
		);
	}

	public function register_menu(): void {
		$hook = add_options_page(
			$this->t( 'Mailinglist Moderation' ),
			$this->t( 'Mailinglist Moderation' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_language_switch' ) );
		}
	}

	/**
	 * Runs before any page output (`load-` hook), so it can redirect back
	 * to the same view without the switch parameters. WordPress has
	 * already checked the page's capability by then; the nonce stops a
	 * link elsewhere from flipping someone's language.
	 */
	public function handle_language_switch(): void {
		$code = $this->query_arg( self::LANGUAGE_ARG );
		if ( '' === $code ) {
			return;
		}

		check_admin_referer( self::LANGUAGE_ARG );
		Dav_Mlm_Admin_Language::save_for_current_user( $code );

		wp_safe_redirect( remove_query_arg( array( self::LANGUAGE_ARG, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function sanitize_option( mixed $input ): array {
		$current = $this->repository->all();
		$op      = is_array( $input ) ? ( $input['op'] ?? null ) : null;

		if ( null !== $op && ! current_user_can( 'manage_options' ) ) {
			add_settings_error( Dav_Mlm_List_Repository::OPTION, 'dav_mlm_forbidden', $this->t( 'You are not allowed to change the mailing list configuration.' ) );

			return $current;
		}

		$result = $this->sanitizer()->apply( $input, $current );

		foreach ( $result->errors() as $i => $message ) {
			add_settings_error( Dav_Mlm_List_Repository::OPTION, 'dav_mlm_error_' . $i, $message, 'error' );
		}

		if ( ! $result->has_errors() && array() !== $result->warnings() ) {
			// WordPress only adds its own "Settings saved." when there are no messages at all.
			add_settings_error( Dav_Mlm_List_Repository::OPTION, 'dav_mlm_saved', $this->t( 'Settings saved.' ), 'success' );
		}

		foreach ( $result->warnings() as $i => $message ) {
			add_settings_error( Dav_Mlm_List_Repository::OPTION, 'dav_mlm_warning_' . $i, $message, 'warning' );
		}

		if ( $result->has_errors() && 'save' === $op && is_array( $input['list'] ?? null ) ) {
			set_transient( self::FORM_STASH . get_current_user_id(), $input['list'], 5 * MINUTE_IN_SECONDS );
		}

		return $result->lists();
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( $this->t( 'You are not allowed to access this page.' ) ) );
		}

		echo '<div class="wrap">';

		$this->render_language_switch();

		$this->render_config_notice();

		$stashed = $this->take_stashed_form();
		$action  = $this->query_arg( 'action' );
		$list    = 'edit' === $action ? $this->repository->find( $this->query_arg( 'id' ) ) : null;

		if ( null !== $stashed ) {
			$this->render_form( array_merge( $this->new_list_defaults(), $stashed ) );
		} elseif ( 'new' === $action ) {
			$this->render_form( $this->new_list_defaults() );
		} elseif ( null !== $list ) {
			$this->render_form( $list );
		} else {
			$this->render_overview();
			$this->render_regex_tester();
		}

		echo '</div>';
	}

	/**
	 * Lists can be configured without the wp-config.php constants, but no
	 * run can happen until they're there — say so where it'll be seen.
	 */
	private function render_config_notice(): void {
		try {
			new Dav_Mlm_Config();
		} catch ( Dav_Mlm_Config_Exception $e ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html( $e->getMessage() ),
				esc_html( $this->t( 'The cron runner cannot run until it is set. See PLAN.md §7 for all DAV_MLM_* constants.' ) )
			);
		}
	}

	private function render_overview(): void {
		$lists = $this->repository->all();
		usort( $lists, static fn ( array $a, array $b ): int => strcmp( $a['list_address'], $b['list_address'] ) );

		printf(
			'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end">',
			esc_html( $this->t( 'Mailinglist Moderation' ) ),
			esc_url( add_query_arg( 'action', 'new', $this->page_url() ) ),
			esc_html( $this->t( 'Add list' ) )
		);

		if ( array() === $lists ) {
			printf( '<p>%s</p>', esc_html( $this->t( 'No lists configured yet. Notifications for unconfigured lists are never approved or rejected automatically — they go to manual review.' ) ) );

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		$headings = array(
			$this->t( 'List address' ),
			$this->t( 'Active' ),
			$this->t( 'Sender regex' ),
			$this->t( 'DKIM' ),
			$this->t( 'On rejection' ),
			$this->t( 'Reply-To' ),
			$this->t( 'Actions' ),
		);
		foreach ( $headings as $heading ) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}
		echo '</tr></thead><tbody>';

		foreach ( $lists as $list ) {
			echo '<tr>';
			printf( '<td><strong>%s</strong></td>', esc_html( $list['list_address'] ) );
			printf( '<td>%s</td>', esc_html( $list['active'] ? $this->t( 'Yes' ) : $this->t( 'No (manual review)' ) ) );
			printf( '<td><code>%s</code></td>', esc_html( $list['regex'] ) );
			printf( '<td>%s</td>', esc_html( 'require' === $list['dkim_policy'] ? $this->t( 'Required' ) : $this->t( 'Off' ) ) );
			printf( '<td>%s</td>', esc_html( 'email' === $list['reject_mode'] ? $this->t( 'Email the sender' ) : $this->t( 'Silent' ) ) );
			printf( '<td>%s</td>', esc_html( $list['reply_to'] ?? '—' ) );

			echo '<td>';
			printf(
				'<a href="%s">%s</a> | <a href="%s">%s</a> | ',
				esc_url( add_query_arg( array( 'action' => 'edit', 'id' => $list['id'] ), $this->page_url() ) ),
				esc_html( $this->t( 'Edit' ) ),
				esc_url( add_query_arg( 'test_regex', rawurlencode( $list['regex'] ), $this->page_url() ) . '#dav-mlm-regex-test' ),
				esc_html( $this->t( 'Test regex' ) )
			);
			echo '<form method="post" action="options.php" style="display:inline">';
			$this->render_options_form_fields();
			echo '<input type="hidden" name="dav_mlm_lists[op]" value="delete" />';
			printf( '<input type="hidden" name="dav_mlm_lists[id]" value="%s" />', esc_attr( $list['id'] ) );
			printf(
				'<button type="submit" class="button-link button-link-delete" onclick="return confirm(\'%s\');">%s</button>',
				esc_attr( esc_js( sprintf( $this->t( 'Delete the configuration for %s?' ), $list['list_address'] ) ) ),
				esc_html( $this->t( 'Delete' ) )
			);
			echo '</form></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array<string, mixed> $list
	 */
	private function render_form( array $list ): void {
		$is_new = '' === (string) $list['id'];

		printf(
			'<h1>%s</h1>',
			esc_html( $is_new ? $this->t( 'Add mailing list' ) : sprintf( $this->t( 'Edit mailing list: %s' ), $list['list_address'] ) )
		);

		echo '<form method="post" action="options.php">';
		$this->render_options_form_fields();
		echo '<input type="hidden" name="dav_mlm_lists[op]" value="save" />';
		printf( '<input type="hidden" name="dav_mlm_lists[list][id]" value="%s" />', esc_attr( (string) $list['id'] ) );

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->render_row(
			'active',
			$this->t( 'Active' ),
			sprintf(
				'<label><input type="checkbox" id="dav-mlm-active" name="dav_mlm_lists[list][active]" value="1"%s /> %s</label>',
				checked( ! empty( $list['active'] ), true, false ),
				esc_html( $this->t( 'Moderate this list automatically' ) )
			),
			$this->t( 'Inactive lists keep their configuration, but every notification for them goes to manual review.' )
		);

		$this->render_row(
			'list_address',
			$this->t( 'List address' ),
			sprintf(
				'<input type="text" id="dav-mlm-list_address" name="dav_mlm_lists[list][list_address]" value="%s" class="regular-text" required />',
				esc_attr( (string) $list['list_address'] )
			),
			$this->t( 'The mailing list\'s own address, e.g. test.mailingliste@dav-neuland.de. Stored lowercased.' )
		);

		$this->render_row(
			'regex',
			$this->t( 'Allowed senders (regex)' ),
			sprintf(
				'<input type="text" id="dav-mlm-regex" name="dav_mlm_lists[list][regex]" value="%s" class="large-text code" required />',
				esc_attr( (string) $list['regex'] )
			),
			$this->t( 'PHP PCRE pattern including delimiters, matched against the original sender\'s bare address (e.g. max@example.org). Example: /@dav-neuland\.de\z/i. Anchor the end with \z or add the D modifier instead of using $ — $ also matches before a trailing newline. Use the "Test regex" helper on the overview page to try it out.' )
		);

		$this->render_row(
			'dkim_policy',
			$this->t( 'DKIM check' ),
			$this->select(
				'dkim_policy',
				(string) $list['dkim_policy'],
				array(
					'require' => $this->t( 'Required — the sender address must be DKIM-verified, otherwise manual review' ),
					'off'     => $this->t( 'Off — trust the From: address as-is' ),
				)
			),
			$this->t( 'Only switch this off for lists whose members use providers that don\'t sign with DKIM: without it, anyone can forge an allowed From: address.' )
		);

		$this->render_row(
			'reject_mode',
			$this->t( 'When a sender is not allowed' ),
			$this->select(
				'reject_mode',
				(string) $list['reject_mode'],
				array(
					'email'  => $this->t( 'Reject and email the sender' ),
					'silent' => $this->t( 'Reject silently (no email)' ),
				)
			),
			$this->t( 'Either way the post is not approved and the notification is filed under Rejected.' )
		);

		$placeholders = sprintf( $this->t( 'Placeholders: %s (the original sender, the list address, the subject of the rejected post).' ), implode( ', ', Dav_Mlm_Rejector::PLACEHOLDERS ) );

		$this->render_row(
			'reject_subject',
			$this->t( 'Rejection subject' ),
			sprintf(
				'<input type="text" id="dav-mlm-reject_subject" name="dav_mlm_lists[list][reject_subject]" value="%s" class="large-text" />',
				esc_attr( (string) $list['reject_subject'] )
			),
			$placeholders
		);

		$this->render_row(
			'reject_body',
			$this->t( 'Rejection text' ),
			sprintf(
				'<textarea id="dav-mlm-reject_body" name="dav_mlm_lists[list][reject_body]" rows="10" class="large-text">%s</textarea>',
				esc_textarea( (string) $list['reject_body'] )
			),
			$placeholders . ' ' . $this->t( 'Sent as plain text.' )
		);

		$this->render_row(
			'reply_to',
			$this->t( 'Reply-To (optional)' ),
			sprintf(
				'<input type="text" id="dav-mlm-reply_to" name="dav_mlm_lists[list][reply_to]" value="%s" class="regular-text" />',
				esc_attr( (string) ( $list['reply_to'] ?? '' ) )
			),
			$this->t( 'A person who reads replies to rejection mails. Without it, replies go to the unattended sending mailbox.' )
		);

		echo '</tbody></table>';

		submit_button( $is_new ? $this->t( 'Add list' ) : $this->t( 'Save changes' ) );

		printf( '<p><a href="%s">%s</a></p>', esc_url( $this->page_url() ), esc_html( $this->t( '← Back to all lists' ) ) );

		echo '</form>';
	}

	/**
	 * A GET form that only reports match / no match — it changes nothing,
	 * so no nonce. Runs preg_match() exactly the way the runner will.
	 */
	private function render_regex_tester(): void {
		$regex   = $this->query_arg( 'test_regex' );
		$address = $this->query_arg( 'test_address' );

		printf( '<h2 id="dav-mlm-regex-test">%s</h2>', esc_html( $this->t( 'Test regex' ) ) );
		printf( '<p>%s</p>', esc_html( $this->t( 'Check whether a sender address would be allowed by a regex.' ) ) );

		echo '<form method="get" action="options-general.php#dav-mlm-regex-test">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::PAGE_SLUG ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_row(
			'test_regex',
			$this->t( 'Regex' ),
			sprintf( '<input type="text" id="dav-mlm-test_regex" name="test_regex" value="%s" class="large-text code" />', esc_attr( $regex ) ),
			''
		);
		$this->render_row(
			'test_address',
			$this->t( 'Sender address' ),
			sprintf( '<input type="text" id="dav-mlm-test_address" name="test_address" value="%s" class="regular-text" />', esc_attr( $address ) ),
			''
		);
		echo '</tbody></table>';
		submit_button( $this->t( 'Test' ), 'secondary', '', false );
		echo '</form>';

		if ( '' === $regex || '' === $address ) {
			return;
		}

		$error = Dav_Mlm_List_Sanitizer::regex_error( $regex );
		if ( null !== $error ) {
			$this->render_inline_notice( 'error', sprintf( $this->t( 'Invalid regex: %s' ), $error ) );

			return;
		}

		$match = preg_match( $regex, $address );
		if ( 1 === $match ) {
			$this->render_inline_notice( 'success', sprintf( $this->t( 'Match — a post from %s would be approved (if it also passes the DKIM check).' ), $address ) );
		} elseif ( 0 === $match ) {
			$this->render_inline_notice( 'warning', sprintf( $this->t( 'No match — a post from %s would be rejected.' ), $address ) );
		} else {
			$this->render_inline_notice( 'error', sprintf( $this->t( 'The regex failed while matching (%s) — such a post would go to manual review.' ), preg_last_error_msg() ) );
		}
	}

	/**
	 * Links to the other language; the current one is shown in bold.
	 */
	private function render_language_switch(): void {
		$links = array();
		foreach ( Dav_Mlm_Admin_Language::LANGUAGES as $code => $name ) {
			$links[] = $code === $this->language()->code()
				? sprintf( '<strong>%s</strong>', esc_html( $name ) )
				: sprintf( '<a href="%s">%s</a>', esc_url( wp_nonce_url( add_query_arg( self::LANGUAGE_ARG, $code ), self::LANGUAGE_ARG ) ), esc_html( $name ) );
		}

		printf(
			'<p style="float:right">%s: %s</p>',
			esc_html( $this->t( 'Language' ) ),
			implode( ' | ', $links ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		);
	}

	/**
	 * @param string $control Already-escaped HTML for the input.
	 */
	private function render_row( string $field, string $label, string $control, string $help ): void {
		printf(
			'<tr><th scope="row"><label for="dav-mlm-%s">%s</label></th><td>%s%s</td></tr>',
			esc_attr( $field ),
			esc_html( $label ),
			$control, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
			'' === $help ? '' : sprintf( '<p class="description">%s</p>', esc_html( $help ) )
		);
	}

	/**
	 * @param array<string, string> $options value => label
	 */
	private function select( string $field, string $selected, array $options ): string {
		$html = sprintf( '<select id="dav-mlm-%1$s" name="dav_mlm_lists[list][%1$s]">', esc_attr( $field ) );
		foreach ( $options as $value => $label ) {
			$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $label ) );
		}

		return $html . '</select>';
	}

	private function render_inline_notice( string $type, string $message ): void {
		printf( '<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	/**
	 * The hidden fields options.php expects (what settings_fields() would
	 * print), with the referer pointing at the overview: after a
	 * successful save that's where the user should land, and after a
	 * failed one render_page() shows the stashed form there anyway.
	 * Printed per form rather than via settings_fields() so the several
	 * delete forms on the overview don't repeat the same element ids.
	 */
	private function render_options_form_fields(): void {
		printf( '<input type="hidden" name="option_page" value="%s" />', esc_attr( self::OPTION_GROUP ) );
		echo '<input type="hidden" name="action" value="update" />';
		printf( '<input type="hidden" name="_wpnonce" value="%s" />', esc_attr( wp_create_nonce( self::OPTION_GROUP . '-options' ) ) );
		printf( '<input type="hidden" name="_wp_http_referer" value="%s" />', esc_attr( $this->page_url() ) );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function take_stashed_form(): ?array {
		$key     = self::FORM_STASH . get_current_user_id();
		$stashed = get_transient( $key );
		if ( ! is_array( $stashed ) ) {
			return null;
		}

		delete_transient( $key );

		// An unchecked checkbox isn't submitted at all; without this the
		// new-list default (active) would win in render_page()'s merge.
		$stashed['active'] = ! empty( $stashed['active'] );

		return $stashed;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function new_list_defaults(): array {
		return array(
			'id'             => '',
			'list_address'   => '',
			'regex'          => '',
			'dkim_policy'    => 'require',
			'reject_mode'    => 'email',
			'reject_subject' => 'Ihre Nachricht an {{list}} konnte nicht zugestellt werden',
			'reject_body'    => "Hallo,\n\nIhre Nachricht \"{{original_subject}}\" an {{list}} wurde nicht freigegeben, da {{sender}} nicht berechtigt ist, an diese Liste zu schreiben.\n\nDies ist eine automatisch erzeugte Nachricht.",
			'reply_to'       => null,
			'active'         => true,
		);
	}

	private function language(): Dav_Mlm_Admin_Language {
		return $this->language ??= Dav_Mlm_Admin_Language::for_current_user();
	}

	private function sanitizer(): Dav_Mlm_List_Sanitizer {
		return $this->sanitizer ??= new Dav_Mlm_List_Sanitizer( null, $this->language() );
	}

	private function t( string $text ): string {
		return $this->language()->t( $text );
	}

	private function query_arg( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/test parameters.
		$value = $_GET[ $name ] ?? '';

		return is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
	}

	private function page_url(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}
}
