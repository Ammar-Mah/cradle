<?php
/**
 * Render service for cradle/social-links (render contract social-links-v1):
 * links to the organization's own profiles configured under Cradle → Social.
 *
 * Data: Config::enabled_profiles() (administrator-approved, re-validated on
 * read). The component's `services` property can only select and reorder
 * those configured profiles; it can never introduce a new destination URL.
 *
 * @package Cradle\Social
 */

namespace Cradle\Social;

defined( 'ABSPATH' ) || exit;

final class Social_Links {

	/**
	 * @param array $p         Normalized properties.
	 * @param array $context   Plain render context.
	 * @param array $component Component descriptor.
	 */
	public static function render( array $p, array $context = array(), array $component = array() ): string {
		$profiles = array();
		foreach ( Config::enabled_profiles() as $row ) {
			$profiles[ $row['service'] ] = $row;
		}
		$ids = self::order( array_keys( $profiles ), (array) ( $p['services'] ?? array() ) );
		if ( ! $ids ) {
			return '';
		}
		$o     = Style::options( $p, 'profiles' );
		$items = array();
		foreach ( $ids as $id ) {
			$provider = Providers::get( $id );
			$label    = Providers::label( $id );
			$items[]  = array(
				'id'      => $id,
				'icon'    => $provider['icon'],
				'href'    => $profiles[ $id ]['url'],
				'rel'     => 'me',
				'new_tab' => $o['new_tab'],
				'visible' => $label,
				/* translators: %s: service name, for example LinkedIn */
				'name'    => 'email' === $id ? __( 'Email us', 'cradle-social' ) : sprintf( __( 'Follow us on %s', 'cradle-social' ), $label ),
			);
		}
		return Markup::render( 'profiles', $items, $o, $context );
	}

	/**
	 * Applies a per-instance subset/order override to the available IDs.
	 *
	 * @param string[] $available Available IDs in site order.
	 * @param array    $override  Requested IDs (empty = site order).
	 * @return string[]
	 */
	public static function order( array $available, array $override ): array {
		$override = array_values( array_unique( array_filter( $override, 'is_string' ) ) );
		if ( ! $override ) {
			return $available;
		}
		return array_values( array_intersect( $override, $available ) );
	}
}
