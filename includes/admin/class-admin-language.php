<?php
/**
 * English/German switch for the admin page. Chosen per user on the page
 * itself (user meta), defaulting to German when the user's WordPress
 * locale is German. A plain lookup table instead of gettext .po/.mo
 * files: two languages, one page, and the toggle shouldn't depend on
 * which language packs the site has installed.
 *
 * English strings are the keys; anything missing from GERMAN is shown in
 * English (AdminLanguageTest checks every t() literal has an entry).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dav_Mlm_Admin_Language {

	public const EN = 'en';
	public const DE = 'de';

	/** Each language's name in itself, as shown on the toggle. */
	public const LANGUAGES = array(
		self::EN => 'English',
		self::DE => 'Deutsch',
	);

	public const USER_META = 'dav_mlm_admin_language';

	public const GERMAN = array(
		// Admin page.
		'Mailinglist Moderation'                         => 'Mailinglisten-Moderation',
		'Language'                                       => 'Sprache',
		'You are not allowed to change the mailing list configuration.' => 'Sie dürfen die Mailinglisten-Konfiguration nicht ändern.',
		'You are not allowed to access this page.'       => 'Sie dürfen diese Seite nicht aufrufen.',
		'Settings saved.'                                => 'Einstellungen gespeichert.',
		'The cron runner cannot run until it is set. See PLAN.md §7 for all DAV_MLM_* constants.' => 'Bis sie gesetzt ist, kann der Cron-Runner nicht laufen. Alle DAV_MLM_*-Konstanten stehen in PLAN.md §7.',
		'Add list'                                       => 'Liste hinzufügen',
		'No lists configured yet. Notifications for unconfigured lists are never approved or rejected automatically — they go to manual review.' => 'Noch keine Listen konfiguriert. Benachrichtigungen für nicht konfigurierte Listen werden nie automatisch freigegeben oder abgelehnt — sie landen in der manuellen Prüfung.',
		'List address'                                   => 'Listenadresse',
		'Active'                                         => 'Aktiv',
		'Sender regex'                                   => 'Absender-Regex',
		'DKIM'                                           => 'DKIM',
		'On rejection'                                   => 'Bei Ablehnung',
		'Reply-To'                                       => 'Reply-To',
		'Actions'                                        => 'Aktionen',
		'Yes'                                            => 'Ja',
		'No (manual review)'                             => 'Nein (manuelle Prüfung)',
		'Required'                                       => 'Erforderlich',
		'Off'                                            => 'Aus',
		'Email the sender'                               => 'E-Mail an Absender',
		'Silent'                                         => 'Still',
		'Edit'                                           => 'Bearbeiten',
		'Test regex'                                     => 'Regex testen',
		'Delete the configuration for %s?'               => 'Konfiguration für %s löschen?',
		'Delete'                                         => 'Löschen',
		'Add mailing list'                               => 'Mailingliste hinzufügen',
		'Edit mailing list: %s'                          => 'Mailingliste bearbeiten: %s',
		'Moderate this list automatically'               => 'Diese Liste automatisch moderieren',
		'Inactive lists keep their configuration, but every notification for them goes to manual review.' => 'Inaktive Listen behalten ihre Konfiguration, aber jede Benachrichtigung für sie landet in der manuellen Prüfung.',
		'The mailing list\'s own address, e.g. test.mailingliste@dav-neuland.de. Stored lowercased.' => 'Die Adresse der Mailingliste selbst, z. B. test.mailingliste@dav-neuland.de. Wird kleingeschrieben gespeichert.',
		'Allowed senders (regex)'                        => 'Erlaubte Absender (Regex)',
		'PHP PCRE pattern including delimiters, matched against the original sender\'s bare address (e.g. max@example.org). Example: /@dav-neuland\.de\z/i. Anchor the end with \z or add the D modifier instead of using $ — $ also matches before a trailing newline. Use the "Test regex" helper on the overview page to try it out.' => 'PHP-PCRE-Muster inklusive Begrenzern, geprüft gegen die reine Adresse des ursprünglichen Absenders (z. B. max@example.org). Beispiel: /@dav-neuland\.de\z/i. Das Ende mit \z verankern oder den Modifikator D ergänzen, statt $ zu verwenden — $ passt auch vor einem abschließenden Zeilenumbruch. Mit „Regex testen“ auf der Übersichtsseite lässt sich das Muster ausprobieren.',
		'DKIM check'                                     => 'DKIM-Prüfung',
		'Required — the sender address must be DKIM-verified, otherwise manual review' => 'Erforderlich — die Absenderadresse muss per DKIM bestätigt sein, sonst manuelle Prüfung',
		'Off — trust the From: address as-is'            => 'Aus — der From:-Adresse ungeprüft vertrauen',
		'Only switch this off for lists whose members use providers that don\'t sign with DKIM: without it, anyone can forge an allowed From: address.' => 'Nur für Listen abschalten, deren Mitglieder Anbieter ohne DKIM-Signatur nutzen: Ohne die Prüfung kann jeder eine erlaubte From:-Adresse fälschen.',
		'When a sender is not allowed'                   => 'Wenn ein Absender nicht berechtigt ist',
		'Reject and email the sender'                    => 'Ablehnen und Absender per E-Mail benachrichtigen',
		'Reject silently (no email)'                     => 'Still ablehnen (keine E-Mail)',
		'Either way the post is not approved and the notification is filed under Rejected.' => 'In beiden Fällen wird der Beitrag nicht freigegeben und die Benachrichtigung unter Rejected abgelegt.',
		'Placeholders: %s (the original sender, the list address, the subject of the rejected post).' => 'Platzhalter: %s (der ursprüngliche Absender, die Listenadresse, der Betreff des abgelehnten Beitrags).',
		'Rejection subject'                              => 'Betreff der Ablehnung',
		'Rejection text'                                 => 'Text der Ablehnung',
		'Sent as plain text.'                            => 'Wird als reiner Text versendet.',
		'Reply-To (optional)'                            => 'Reply-To (optional)',
		'A person who reads replies to rejection mails. Without it, replies go to the unattended sending mailbox.' => 'Eine Person, die Antworten auf Ablehnungsmails liest. Ohne Angabe landen Antworten im unbeaufsichtigten Absender-Postfach.',
		'Save changes'                                   => 'Änderungen speichern',
		'← Back to all lists'                            => '← Zurück zu allen Listen',
		'Check whether a sender address would be allowed by a regex.' => 'Prüfen, ob eine Absenderadresse von einem Regex erlaubt würde.',
		'Regex'                                          => 'Regex',
		'Sender address'                                 => 'Absenderadresse',
		'Test'                                           => 'Testen',
		'Invalid regex: %s'                              => 'Ungültiger Regex: %s',
		'Match — a post from %s would be approved (if it also passes the DKIM check).' => 'Treffer — ein Beitrag von %s würde freigegeben (sofern er auch die DKIM-Prüfung besteht).',
		'No match — a post from %s would be rejected.'   => 'Kein Treffer — ein Beitrag von %s würde abgelehnt.',
		'The regex failed while matching (%s) — such a post would go to manual review.' => 'Der Regex ist beim Prüfen fehlgeschlagen (%s) — ein solcher Beitrag käme in die manuelle Prüfung.',

		// Status panel (Dav_Mlm_Status_Panel).
		'The last %1$d runs failed (last successful run: %2$s). Moderation is not working; check the errors below and the log.' => 'Die letzten %1$d Läufe sind fehlgeschlagen (letzter erfolgreicher Lauf: %2$s). Die Moderation funktioniert nicht; bitte die Fehler unten und das Log prüfen.',
		'Status'                                         => 'Status',
		'Last run'                                       => 'Letzter Lauf',
		'Last successful run'                            => 'Letzter erfolgreicher Lauf',
		'Consecutive failed runs'                        => 'Fehlgeschlagene Läufe in Folge',
		'Last run counts'                                => 'Zahlen des letzten Laufs',
		'No run recorded yet.'                           => 'Noch kein Lauf verzeichnet.',
		'Processed'                                      => 'Verarbeitet',
		'Approved'                                       => 'Freigegeben',
		'Rejected'                                       => 'Abgelehnt',
		'Manual review'                                  => 'Manuelle Prüfung',
		'Suspicious'                                     => 'Verdächtig',
		'Unrecognized'                                   => 'Nicht erkannt',
		'Info mails'                                     => 'Info-Mails',
		'Errored'                                        => 'Fehlerhaft',
		'Skipped'                                        => 'Übersprungen',
		'Recent errors and warnings'                     => 'Letzte Fehler und Warnungen',
		'None.'                                          => 'Keine.',
		'Time'                                           => 'Zeit',
		'Level'                                          => 'Art',
		'Message'                                        => 'Meldung',
		'Warning'                                        => 'Warnung',
		'Error'                                          => 'Fehler',
		'never'                                          => 'noch nie',
		'%d min ago'                                     => 'vor %d Min.',
		'%d h ago'                                       => 'vor %d Std.',
		'%d days ago'                                    => 'vor %d Tagen',

		// Validation messages (Dav_Mlm_List_Sanitizer).
		'Invalid submission.'                            => 'Ungültige Übermittlung.',
		'Unknown operation.'                             => 'Unbekannte Aktion.',
		'Every stored list needs an id.'                 => 'Jede gespeicherte Liste braucht eine ID.',
		'This list no longer exists — it may have been deleted in the meantime.' => 'Diese Liste existiert nicht mehr — vielleicht wurde sie zwischenzeitlich gelöscht.',
		'New list'                                       => 'Neue Liste',
		'%s: the list address is not a valid email address.' => '%s: Die Listenadresse ist keine gültige E-Mail-Adresse.',
		'%s: another list already uses this address.'    => '%s: Eine andere Liste verwendet diese Adresse bereits.',
		'%s: the sender regex is empty.'                 => '%s: Der Absender-Regex ist leer.',
		'%s: the sender regex is invalid (%s).'          => '%s: Der Absender-Regex ist ungültig (%s).',
		'%s: unknown DKIM policy.'                       => '%s: Unbekannte DKIM-Richtlinie.',
		'%s: unknown rejection mode.'                    => '%s: Unbekannter Ablehnungsmodus.',
		'%s: the rejection subject/body is not valid UTF-8.' => '%s: Betreff/Text der Ablehnung ist kein gültiges UTF-8.',
		'%s: rejection by email needs a subject and a body.' => '%s: Für die Ablehnung per E-Mail sind Betreff und Text nötig.',
		'%s: unknown placeholder(s) %s will be sent as-is. Available: %s.' => '%s: Unbekannte Platzhalter %s werden unverändert versendet. Verfügbar: %s.',
		'%s: the Reply-To address is not a valid email address.' => '%s: Die Reply-To-Adresse ist keine gültige E-Mail-Adresse.',
	);

	private string $code;

	public function __construct( string $code = self::EN ) {
		$this->code = isset( self::LANGUAGES[ $code ] ) ? $code : self::EN;
	}

	/**
	 * Only callable once WordPress has determined the current user —
	 * not while the plugin file itself is being loaded.
	 */
	public static function for_current_user(): self {
		$chosen = get_user_meta( get_current_user_id(), self::USER_META, true );
		if ( is_string( $chosen ) && isset( self::LANGUAGES[ $chosen ] ) ) {
			return new self( $chosen );
		}

		return new self( str_starts_with( get_user_locale(), 'de' ) ? self::DE : self::EN );
	}

	public static function save_for_current_user( string $code ): void {
		if ( isset( self::LANGUAGES[ $code ] ) ) {
			update_user_meta( get_current_user_id(), self::USER_META, $code );
		}
	}

	public function code(): string {
		return $this->code;
	}

	public function t( string $text ): string {
		return self::DE === $this->code ? ( self::GERMAN[ $text ] ?? $text ) : $text;
	}
}
