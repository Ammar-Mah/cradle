<?php
/**
 * Registry of labels that Cradle configuration defines as plain strings (module names and
 * descriptions from descriptors, module capability labels, and labels other packages register).
 *
 * Registered labels are added to catalogs as source "registered" when sources are refreshed.
 * Code renders them through the dependency-free filter
 *     apply_filters( 'cradle/translate_label', $text, $context, $domain )
 * which resolves override → native translation → original (see Runtime::translate_label()).
 *
 * Other packages register labels with:
 *     add_action( 'cradle/translations/register_labels', function ( $labels ) {
 *         $labels->add( 'cradle-events', 'Event', 'post type label', 'post-type/cradle_event' );
 *     } );
 *
 * @package Cradle\Translations
 */

namespace Cradle\Translations;

defined( 'ABSPATH' ) || exit;

final class Labels {

	const MAX_LABELS = 2000;

	/** @var array<string,array{domain:string,msgid:string,context:?string,origin:string}> */
	private $labels = array();

	/**
	 * Registers a label. Invalid input is ignored (labels are display data, not code).
	 */
	public function add( string $domain, string $text, string $context = '', string $origin = '' ): bool {
		if ( count( $this->labels ) >= self::MAX_LABELS || ! Config::valid_domain( $domain ) || true !== Validator::check_text( $text, 'label', false ) || true !== Validator::check_text( $context, 'context' ) ) {
			return false;
		}
		$context = '' === $context ? null : $context;
		$origin  = preg_match( '/^[a-z0-9_\/-]{1,100}$/', $origin ) ? $origin : 'label';
		$this->labels[ Catalog::identity( $domain, $text, $context, null ) ] = array(
			'domain'  => $domain,
			'msgid'   => $text,
			'context' => $context,
			'origin'  => $origin,
		);
		return true;
	}

	public function all(): array {
		return array_values( $this->labels );
	}

	/**
	 * Collects labels from active module descriptors plus registered labels.
	 */
	public static function collect(): array {
		$labels = new self();
		$core   = function_exists( 'cradle' ) ? cradle() : null;
		if ( $core ) {
			foreach ( $core->modules()->all() as $id => $descriptor ) {
				$labels->add( 'cradle', (string) $descriptor['name'], 'module name', 'module/' . $id );
				if ( '' !== $descriptor['description'] ) {
					$labels->add( 'cradle', (string) $descriptor['description'], 'module description', 'module/' . $id );
				}
				foreach ( $descriptor['capabilities'] as $cap => $definition ) {
					if ( isset( $definition['label'] ) && is_string( $definition['label'] ) ) {
						$labels->add( 'cradle', $definition['label'], 'capability label', 'capability/' . $cap );
					}
				}
			}
		}
		/**
		 * Lets Cradle packages register configuration labels for translation.
		 *
		 * @param Labels $labels Registry; call $labels->add( $domain, $text, $context, $origin ).
		 */
		do_action( 'cradle/translations/register_labels', $labels );
		return $labels->all();
	}
}
