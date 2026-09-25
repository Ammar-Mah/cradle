<?php
/**
 * Cradle Blocks module: descriptor, boot, lifecycle.
 *
 * @package Cradle\Blocks
 */

namespace Cradle\Blocks;

defined( 'ABSPATH' ) || exit;

final class Module {

	const ID = 'blocks';

	/** Option recording the package data version (2 = conversion screen and its capability removed). */
	const SCHEMA_OPTION = 'cradle_blocks_schema';

	/** @var Gutenberg_Adapter|null */
	private static $adapter = null;

	/**
	 * @param \Cradle\Core\Module_Registry $registry Registry.
	 */
	public static function register( $registry ) {
		$registry->register(
			array(
				'id'               => self::ID,
				'name'             => 'Cradle Blocks',
				'description'      => 'Generic components (feature card, post cards, hero slider) and the Gutenberg adapter.',
				'version'          => CRADLE_BLOCKS_VERSION,
				'coreApiRange'     => '^1.0',
				'pluginFile'       => CRADLE_BLOCKS_FILE,
				'dependencies'     => array(),
				'settingsSections' => array(),
				'capabilities'     => array(),
				'schemaVersion'    => 2,
				'dataOwners'       => array(
					'options' => array( self::SCHEMA_OPTION ),
					'blocks'  => array( 'cradle/feature-card', 'cradle/post-cards', 'cradle/hero-slider' ),
				),
				'boot'             => array( __CLASS__, 'boot' ),
			)
		);
	}

	/**
	 * @param \Cradle\Core\Plugin $core Core.
	 */
	public static function boot( $core ) {
		if ( version_compare( $core->api_version(), '1.0.0', '<' ) ) {
			return;
		}
		self::$adapter = new Gutenberg_Adapter( $core->components() );
		$core->components()->register_adapter( self::$adapter );
		$core->components()->register_adapter( new Shortcode_Adapter( $core->components() ) );

		$core->components()->register_from_file(
			CRADLE_BLOCKS_DIR . 'components/feature-card/component.json',
			array(
				'render'   => array( Feature_Card::class, 'render' ),
				'blockDir' => CRADLE_BLOCKS_DIR . 'blocks/feature-card',
			)
		);
		$core->components()->register_from_file(
			CRADLE_BLOCKS_DIR . 'components/post-cards/component.json',
			array(
				'render'   => array( Post_Cards::class, 'render' ),
				'prepare'  => array( Post_Cards::class, 'prepare' ),
				'blockDir' => CRADLE_BLOCKS_DIR . 'blocks/post-cards',
			)
		);
		\Cradle\Core\Assets::register_script( Hero_Slider::VIEW_HANDLE, CRADLE_BLOCKS_FILE, 'blocks/hero-slider/view.js', array(), CRADLE_BLOCKS_VERSION );
		$core->components()->register_from_file(
			CRADLE_BLOCKS_DIR . 'components/hero-slider/component.json',
			array(
				'render'   => array( Hero_Slider::class, 'render' ),
				'prepare'  => array( Hero_Slider::class, 'prepare' ),
				'blockDir' => CRADLE_BLOCKS_DIR . 'blocks/hero-slider',
			)
		);

		add_filter( 'block_categories_all', array( __CLASS__, 'block_category' ) );

		if ( is_admin() && (int) get_option( self::SCHEMA_OPTION, 1 ) < 2 ) {
			self::upgrade();
		}
	}

	/**
	 * v2: the Block conversion screen was removed; drop the capability it used.
	 */
	private static function upgrade() {
		if ( class_exists( 'Cradle\\Core\\Capabilities' ) ) {
			\Cradle\Core\Capabilities::revoke_everywhere( array( 'cradle_convert_components' ) );
		}
		update_option( self::SCHEMA_OPTION, 2, false );
	}

	public static function adapter() {
		return self::$adapter;
	}

	public static function block_category( array $categories ): array {
		foreach ( $categories as $category ) {
			if ( 'cradle' === $category['slug'] ) {
				return $categories;
			}
		}
		$categories[] = array(
			'slug'  => 'cradle',
			'title' => __( 'Cradle', 'cradle-blocks' ),
			'icon'  => null,
		);
		return $categories;
	}

	/**
	 * @param bool $network_wide Network activation flag.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			wp_die( esc_html__( 'Cradle Blocks does not support network activation yet.', 'cradle-blocks' ), '', array( 'back_link' => true ) );
		}
		if ( false === get_option( self::SCHEMA_OPTION ) ) {
			add_option( self::SCHEMA_OPTION, 2, '', false );
		}
	}
}
