<?php
/**
 * Optional integration with Cradle Languages (module `languages`), detected at runtime.
 *
 * Uses only its documented functions `cradle_current_language()`, `cradle_default_language()` and
 * `cradle_get_translation()`.
 * Without it, the language of an entry is the WordPress locale of the request and the selected
 * form is rendered as is.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Language {

	public static function languages_active(): bool {
		return function_exists( 'cradle_current_language' ) && function_exists( 'cradle' ) && cradle() && cradle()->modules()->is_ready( 'languages' );
	}

	/**
	 * Language code of the current request: the Cradle Languages slug when active, else the locale.
	 */
	public static function current(): string {
		if ( self::languages_active() ) {
			$code = self::code_of( cradle_current_language() );
			if ( '' !== $code ) {
				return $code;
			}
		}
		return self::clean( determine_locale() );
	}

	public static function locale(): string {
		return self::clean( determine_locale() );
	}

	/**
	 * Accepts the shapes a language may be returned in (slug string, array or object with `slug`).
	 *
	 * @param mixed $language Language value.
	 */
	public static function code_of( $language ): string {
		if ( is_string( $language ) ) {
			return self::clean( $language );
		}
		if ( is_array( $language ) ) {
			return self::clean( (string) ( $language['slug'] ?? $language['code'] ?? '' ) );
		}
		if ( is_object( $language ) ) {
			return self::clean( (string) ( $language->slug ?? $language->code ?? '' ) );
		}
		return '';
	}

	private static function clean( string $value ): string {
		return preg_match( '/^[A-Za-z0-9_-]{1,20}$/', $value ) ? $value : '';
	}

	/**
	 * The published translation of a form in the current language, or the form itself.
	 */
	public static function translated_form( int $form_id ): int {
		if ( ! $form_id || ! self::languages_active() || ! function_exists( 'cradle_get_translation' ) ) {
			return $form_id;
		}
		$language = cradle_current_language();
		$code     = self::code_of( $language );
		if ( '' === $code ) {
			return $form_id;
		}
		$translation = cradle_get_translation( $form_id, $code );
		if ( $translation instanceof \WP_Post ) {
			$translation = $translation->ID;
		}
		$translation = is_numeric( $translation ) ? (int) $translation : 0;
		return $translation > 0 && Post_Type::published( $translation ) ? $translation : $form_id;
	}

	/**
	 * The published default-language version of a form (a form chosen by its address name is
	 * treated like the original form ID: translated_form() then picks the current language), or
	 * the form itself.
	 */
	public static function default_form( int $form_id ): int {
		if ( ! $form_id || ! self::languages_active() || ! function_exists( 'cradle_default_language' ) || ! function_exists( 'cradle_get_translation' ) ) {
			return $form_id;
		}
		$code = self::code_of( cradle_default_language() );
		if ( '' === $code ) {
			return $form_id;
		}
		$original = cradle_get_translation( $form_id, $code );
		if ( $original instanceof \WP_Post ) {
			$original = $original->ID;
		}
		$original = is_numeric( $original ) ? (int) $original : 0;
		return $original > 0 && Post_Type::published( $original ) ? $original : $form_id;
	}

	/**
	 * Switches to the locale recorded in the form token when WordPress knows it.
	 *
	 * @return bool Whether a switch happened (restore with restore_previous_locale()).
	 */
	public static function switch_to( string $locale ): bool {
		if ( '' === $locale || determine_locale() === $locale ) {
			return false;
		}
		return (bool) switch_to_locale( $locale );
	}
}
