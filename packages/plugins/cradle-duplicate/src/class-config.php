<?php
/**
 * Settings section `duplicate` (option `cradle_duplicate_settings`) and typed readers.
 *
 * @package Cradle\Duplicate
 */

namespace Cradle\Duplicate;

defined( 'ABSPATH' ) || exit;

final class Config {

	const SECTION = 'duplicate';

	/** Maximum number of extra allowlisted private meta keys. */
	const MAX_EXTRA_KEYS = 50;

	/**
	 * @param \Cradle\Core\Plugin $core Core.
	 */
	public static function register( $core ) {
		$core->settings()->register_section(
			array(
				'id'            => self::SECTION,
				'owner'         => Module::ID,
				'title'         => __( 'Duplicate', 'cradle-duplicate' ),
				'description'   => __( 'Duplication creates a new draft owned by the policy below. Authorization is always checked per item: the user must be able to read and edit the source and create items of its type.', 'cradle-duplicate' ),
				'capability'    => Lifecycle::CAPABILITY,
				'schemaVersion' => 1,
				'page'          => true,
				'menu_title'    => __( 'Duplicate', 'cradle-duplicate' ),
				'autoload'      => true,
				'lifecycle'     => array( 'uninstall' => 'retain' ),
				'fields'        => array(
					'post_types'      => array(
						'type'     => 'object',
						'default'  => array( 'post', 'page' ),
						'label'    => __( 'Supported post types', 'cradle-duplicate' ),
						'help'     => __( 'WooCommerce products and orders, attachments, revisions, navigation, templates and synced patterns are never offered: they have their own duplication rules or would clone shared resources.', 'cradle-duplicate' ),
						'validate' => array( __CLASS__, 'validate_post_types' ),
						'render'   => array( __CLASS__, 'render_post_types' ),
					),
					'title_suffix'    => array(
						'type'      => 'string',
						'default'   => __( '(Copy)', 'cradle-duplicate' ),
						'maxLength' => 60,
						'label'     => __( 'Title suffix', 'cradle-duplicate' ),
						'help'      => __( 'Appended to the copied title so the draft is easy to tell apart. Leave empty to keep the title unchanged. Developers can change it with the cradle_duplicate/title filter.', 'cradle-duplicate' ),
					),
					'author_policy'   => array(
						'type'    => 'enum',
						'enum'    => array(
							'current'  => __( 'The person who duplicates becomes the author (default)', 'cradle-duplicate' ),
							'original' => __( 'Keep the original author when the person duplicating may edit others’ items; otherwise use the person duplicating', 'cradle-duplicate' ),
						),
						'default' => 'current',
						'control' => 'radio',
						'label'   => __( 'Author of the new draft', 'cradle-duplicate' ),
						'help'    => __( 'Filterable with cradle_duplicate/author. A filtered author is accepted only when that user exists and the person duplicating may assign items to others.', 'cradle-duplicate' ),
					),
					'extra_meta_keys' => array(
						'type'     => 'object',
						'default'  => array(),
						'label'    => __( 'Extra private metadata to copy', 'cradle-duplicate' ),
						'help'     => __( 'One key per line; a trailing * matches a prefix (for example _myplugin_layout_*). Private (underscore) keys are skipped unless listed here or added with the cradle_duplicate/meta_allowlist filter. Locks, revision bookkeeping, Cradle ownership markers, secrets and order/payment keys are always excluded.', 'cradle-duplicate' ),
						'validate' => array( __CLASS__, 'validate_meta_keys' ),
						'render'   => array( __CLASS__, 'render_meta_keys' ),
					),
				),
			)
		);
	}

	/**
	 * @param mixed $value Raw value: list of post type names (form) or array (import).
	 * @return array|\WP_Error
	 */
	public static function validate_post_types( $value ) {
		if ( '' === $value || null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a list of post types.', 'cradle-duplicate' ) );
		}
		$eligible = array_keys( Policy::eligible_post_types() );
		$out      = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || ! in_array( $item, $eligible, true ) ) {
				return new \WP_Error( 'enum', __( 'Contains a post type that cannot be duplicated.', 'cradle-duplicate' ) );
			}
			$out[] = $item;
		}
		return array_values( array_unique( $out ) );
	}

	public static function render_post_types( string $name, string $id, $value, array $field, string $describedby ) {
		$value = is_array( $value ) ? $value : array();
		echo '<fieldset aria-labelledby="' . esc_attr( $id ) . '-label"' . ( $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="" />';
		foreach ( Policy::eligible_post_types() as $type => $object ) {
			printf(
				'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s <code>%2$s</code></label><br />',
				esc_attr( $name ),
				esc_attr( $type ),
				checked( in_array( $type, $value, true ), true, false ),
				esc_html( $object->labels->name )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * @param mixed $value Textarea string (form) or list (import).
	 * @return array|\WP_Error
	 */
	public static function validate_meta_keys( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\R/', $value );
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'type', __( 'Must be a list of metadata keys.', 'cradle-duplicate' ) );
		}
		$out = array();
		foreach ( $value as $line ) {
			if ( ! is_string( $line ) ) {
				return new \WP_Error( 'type', __( 'Must be a list of metadata keys.', 'cradle-duplicate' ) );
			}
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( ! preg_match( '/^[A-Za-z0-9_\-.:]{1,190}\*?$/', $line ) || '*' === $line ) {
				/* translators: %s: rejected metadata key */
				return new \WP_Error( 'pattern', sprintf( __( '“%s” is not a valid metadata key or prefix.', 'cradle-duplicate' ), mb_substr( $line, 0, 60 ) ) );
			}
			$out[] = $line;
		}
		$out = array_values( array_unique( $out ) );
		if ( count( $out ) > self::MAX_EXTRA_KEYS ) {
			/* translators: %d: maximum number of keys */
			return new \WP_Error( 'length', sprintf( __( 'List at most %d keys.', 'cradle-duplicate' ), self::MAX_EXTRA_KEYS ) );
		}
		return $out;
	}

	public static function render_meta_keys( string $name, string $id, $value, array $field, string $describedby ) {
		printf(
			'<textarea id="%1$s" name="%2$s" rows="5" class="large-text code"%3$s>%4$s</textarea>',
			esc_attr( $id ),
			esc_attr( $name ),
			$describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : '',
			esc_textarea( implode( "\n", is_array( $value ) ? $value : array() ) )
		);
	}

	/**
	 * @return array Section values merged over defaults (never writes).
	 */
	public static function values(): array {
		$defaults = array(
			'post_types'      => array( 'post', 'page' ),
			'title_suffix'    => '',
			'author_policy'   => 'current',
			'extra_meta_keys' => array(),
		);
		if ( ! function_exists( 'cradle' ) || ! cradle() || ! cradle()->settings()->section( self::SECTION ) ) {
			return $defaults;
		}
		return array_merge( $defaults, cradle()->settings()->values( self::SECTION ) );
	}

	public static function get( string $key ) {
		return self::values()[ $key ] ?? null;
	}
}
