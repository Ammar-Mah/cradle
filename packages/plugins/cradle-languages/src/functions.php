<?php
/**
 * Public procedural API of Cradle Languages (thin wrappers over Cradle\Languages\Languages and
 * Cradle\Languages\Content). Every function returns an empty value while the module is not ready
 * (core or the blocks module missing), so theme code can call them unguarded.
 *
 * @package Cradle\Languages
 */

defined( 'ABSPATH' ) || exit;

/** Whether Cradle Languages is running for this request. */
function cradle_languages_ready(): bool {
	return class_exists( '\\Cradle\\Languages\\Module' ) && \Cradle\Languages\Module::started();
}

/**
 * Configured languages in order (slug, locale, name, native, dir, default).
 *
 * @return array<int,array>
 */
function cradle_languages(): array {
	return cradle_languages_ready() ? array_values( \Cradle\Languages\Languages::all() ) : array();
}

/** Language of the current request (frontend) or the default language elsewhere. */
function cradle_current_language(): ?array {
	return cradle_languages_ready() ? \Cradle\Languages\Languages::current() : null;
}

function cradle_default_language(): ?array {
	return cradle_languages_ready() ? \Cradle\Languages\Languages::default_language() : null;
}

/**
 * Language of an entry or term (null for shared types and taxonomies).
 *
 * @param WP_Post|WP_Term|int $object Entry, term, or entry ID.
 */
function cradle_language_of( $object ): ?array {
	if ( ! cradle_languages_ready() ) {
		return null;
	}
	return $object instanceof WP_Term ? \Cradle\Languages\Content::term_language( $object ) : \Cradle\Languages\Content::post_language( $object );
}

/** ID of the entry's translation in $lang (0 when there is none). */
function cradle_get_translation( int $post_id, string $lang ): int {
	return cradle_languages_ready() ? \Cradle\Languages\Content::translation( $post_id, $lang ) : 0;
}

/**
 * Translations of an entry including itself.
 *
 * @return array<string,int> Language slug => entry ID.
 */
function cradle_get_translations( int $post_id ): array {
	return cradle_languages_ready() ? \Cradle\Languages\Content::translations( $post_id ) : array();
}

/** ID of the term's translation in $lang (0 when there is none or the taxonomy is shared). */
function cradle_get_term_translation( int $term_id, string $lang ): int {
	return cradle_languages_ready() ? \Cradle\Languages\Content::term_translation( $term_id, $lang ) : 0;
}

/** Home URL of a language ('' when unknown). */
function cradle_language_home_url( string $lang ): string {
	if ( ! cradle_languages_ready() ) {
		return '';
	}
	$row = \Cradle\Languages\Languages::get( $lang );
	return $row ? \Cradle\Languages\Links::language_home( $row ) : '';
}

/**
 * Language switcher items for the current request.
 *
 * @param array $context { hideMissing?: bool, hideCurrent?: bool }.
 */
function cradle_language_switcher_items( array $context = array() ): array {
	return cradle_languages_ready() ? \Cradle\Languages\Switcher::items( $context ) : array();
}

/**
 * Sets the language of an entry (no capability check: callers check edit_post).
 *
 * @return true|WP_Error
 */
function cradle_set_language( int $post_id, string $lang ) {
	if ( ! cradle_languages_ready() ) {
		return new WP_Error( 'cradle_languages_inactive', 'Cradle Languages is not running.' );
	}
	return \Cradle\Languages\Content::set_language( $post_id, $lang );
}

/**
 * Links entries as translations of each other (no capability check: callers check edit_post on each).
 *
 * @param array<string,int> $lang_to_id Language slug => entry ID.
 * @return string|WP_Error Translation group UUID.
 */
function cradle_link_translations( array $lang_to_id ) {
	if ( ! cradle_languages_ready() ) {
		return new WP_Error( 'cradle_languages_inactive', 'Cradle Languages is not running.' );
	}
	return \Cradle\Languages\Content::link_translations( $lang_to_id );
}
