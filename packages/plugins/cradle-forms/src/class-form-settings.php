<?php
/**
 * Per-form settings stored in post meta `_cradle_form_settings` (one object, edited in the
 * block editor's "Form settings" and "Notifications" panels through the REST meta field).
 *
 * Every write goes through sanitize(): unknown keys are dropped, values are bounded and typed.
 * Field names in notifications (reply-to, routing) are checked against the schema when used,
 * because the schema can change after the settings were saved.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Form_Settings {

	const META              = '_cradle_form_settings';
	const MAX_NOTIFICATIONS = 10;
	const MAX_ROUTES        = 20;

	public static function defaults(): array {
		return array(
			'submitLabel'         => '',
			'confirmationType'    => 'message',
			'confirmationMessage' => '',
			'redirectPage'        => 0,
			'storeEntries'        => true,
			'retentionDays'       => 0,
			'minSeconds'          => 3,
			'rateLimit'           => 10,
			'notifications'       => array( self::notification_defaults() ),
		);
	}

	public static function notification_defaults(): array {
		return array(
			'enabled'         => true,
			'to'              => '',
			'routes'          => array(),
			'subject'         => '',
			'replyToField'    => '',
			'includeValues'   => true,
			'copyToSubmitter' => false,
		);
	}

	public static function register_meta() {
		register_post_meta(
			Post_Type::NAME,
			self::META,
			array(
				'single'            => true,
				'type'              => 'object',
				'default'           => self::defaults(),
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', (int) $post_id );
				},
				'show_in_rest'      => array( 'schema' => self::rest_schema() ),
			)
		);
	}

	private static function rest_schema(): array {
		$string = array( 'type' => 'string' );
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'submitLabel'         => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'confirmationType'    => array(
					'type' => 'string',
					'enum' => array( 'message', 'page' ),
				),
				'confirmationMessage' => array(
					'type'      => 'string',
					'maxLength' => 5000,
				),
				'redirectPage'        => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'storeEntries'        => array( 'type' => 'boolean' ),
				'retentionDays'       => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 3650,
				),
				'minSeconds'          => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 600,
				),
				'rateLimit'           => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 1000,
				),
				'notifications'       => array(
					'type'     => 'array',
					'maxItems' => self::MAX_NOTIFICATIONS,
					'items'    => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'enabled'         => array( 'type' => 'boolean' ),
							'to'              => array(
								'type'      => 'string',
								'maxLength' => 1000,
							),
							'routes'          => array(
								'type'     => 'array',
								'maxItems' => self::MAX_ROUTES,
								'items'    => array(
									'type'                 => 'object',
									'additionalProperties' => false,
									'properties'           => array(
										'field' => $string,
										'value' => $string,
										'to'    => $string,
									),
								),
							),
							'subject'         => array(
								'type'      => 'string',
								'maxLength' => 200,
							),
							'replyToField'    => $string,
							'includeValues'   => array( 'type' => 'boolean' ),
							'copyToSubmitter' => array( 'type' => 'boolean' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Allowed HTML in the confirmation message.
	 */
	public static function message_tags(): array {
		return array(
			'p'      => array(),
			'br'     => array(),
			'strong' => array(),
			'em'     => array(),
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
		);
	}

	/**
	 * @param mixed $value Raw value.
	 */
	public static function sanitize( $value ): array {
		$value = is_array( $value ) ? $value : ( is_object( $value ) ? (array) $value : array() );
		$d     = self::defaults();
		$out   = array(
			'submitLabel'         => Field_Types::plain( $value['submitLabel'] ?? '', 100 ),
			'confirmationType'    => in_array( $value['confirmationType'] ?? '', array( 'message', 'page' ), true ) ? $value['confirmationType'] : 'message',
			'confirmationMessage' => mb_substr( wp_kses( is_scalar( $value['confirmationMessage'] ?? null ) ? (string) $value['confirmationMessage'] : '', self::message_tags() ), 0, 5000 ),
			'redirectPage'        => max( 0, (int) ( $value['redirectPage'] ?? 0 ) ),
			'storeEntries'        => array_key_exists( 'storeEntries', $value ) ? (bool) $value['storeEntries'] : $d['storeEntries'],
			'retentionDays'       => max( 0, min( 3650, (int) ( $value['retentionDays'] ?? 0 ) ) ),
			'minSeconds'          => array_key_exists( 'minSeconds', $value ) ? max( 0, min( 600, (int) $value['minSeconds'] ) ) : $d['minSeconds'],
			'rateLimit'           => array_key_exists( 'rateLimit', $value ) ? max( 0, min( 1000, (int) $value['rateLimit'] ) ) : $d['rateLimit'],
			'notifications'       => array(),
		);
		if ( $out['redirectPage'] && 'page' !== get_post_type( $out['redirectPage'] ) ) {
			$out['redirectPage'] = 0;
		}
		$list = array_key_exists( 'notifications', $value ) && is_array( $value['notifications'] ) ? $value['notifications'] : $d['notifications'];
		foreach ( array_slice( array_values( $list ), 0, self::MAX_NOTIFICATIONS ) as $row ) {
			$row = is_array( $row ) ? $row : ( is_object( $row ) ? (array) $row : array() );
			$n   = array(
				'enabled'         => ! array_key_exists( 'enabled', $row ) || (bool) $row['enabled'],
				'to'              => implode( ', ', self::emails( $row['to'] ?? '' ) ),
				'routes'          => array(),
				'subject'         => Field_Types::plain( $row['subject'] ?? '', 200 ),
				'replyToField'    => self::field_name( $row['replyToField'] ?? '' ),
				'includeValues'   => ! array_key_exists( 'includeValues', $row ) || (bool) $row['includeValues'],
				'copyToSubmitter' => ! empty( $row['copyToSubmitter'] ),
			);
			foreach ( array_slice( array_values( is_array( $row['routes'] ?? null ) ? $row['routes'] : array() ), 0, self::MAX_ROUTES ) as $route ) {
				$route = is_array( $route ) ? $route : ( is_object( $route ) ? (array) $route : array() );
				$field = self::field_name( $route['field'] ?? '' );
				$to    = self::emails( $route['to'] ?? '' );
				if ( '' === $field || ! $to ) {
					continue;
				}
				$n['routes'][] = array(
					'field' => $field,
					'value' => Field_Types::plain( $route['value'] ?? '', 100 ),
					'to'    => implode( ', ', $to ),
				);
			}
			$out['notifications'][] = $n;
		}
		return $out;
	}

	private static function field_name( $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return preg_match( Field_Types::NAME_PATTERN, $value ) ? $value : '';
	}

	/**
	 * @return string[] Valid addresses from a comma/semicolon/whitespace separated list (max 20).
	 */
	public static function emails( $value ): array {
		if ( ! is_string( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', $value ) as $email ) {
			$email = sanitize_email( $email );
			if ( '' !== $email && is_email( $email ) ) {
				$out[ strtolower( $email ) ] = $email;
			}
		}
		return array_slice( array_values( $out ), 0, 20 );
	}

	public static function get( int $form_id ): array {
		$stored = get_post_meta( $form_id, self::META, true );
		return is_array( $stored ) && $stored ? self::sanitize( $stored ) : self::defaults();
	}
}
