<?php
/**
 * Notification emails with routing rules ("if field X equals V, send to E").
 *
 * Plain-text bodies; file contents are never attached (the email links to the entry in the
 * admin). Header injection: addresses are validated with is_email(), the subject is stripped of
 * line breaks, and headers are passed to wp_mail() as a list built only from validated values.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Notifications {

	private static function line( string $value, int $max = 200 ): string {
		return mb_substr( trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $value ) ), 0, $max );
	}

	private static function value_text( array $entry, array $field ): string {
		$value = $entry['values'][ $field['name'] ] ?? '';
		if ( 'file' === $field['type'] ) {
			return implode( ', ', array_map( 'strval', (array) $value ) );
		}
		return Field_Types::display( $field['type'], Field_Types::to_storage( $value ), $field );
	}

	/**
	 * Recipients of one notification: static addresses plus every matching routing rule,
	 * falling back to the site-wide fallback recipient.
	 *
	 * @return string[]
	 */
	public static function recipients( array $notification, array $fields, array $entry ): array {
		$to = Form_Settings::emails( $notification['to'] );
		foreach ( $notification['routes'] as $route ) {
			$field = Form_Schema::field( $fields, $route['field'] );
			if ( ! $field || 'file' === $field['type'] ) {
				continue;
			}
			$value = $entry['values'][ $route['field'] ] ?? '';
			$match = is_array( $value ) ? in_array( $route['value'], array_map( 'strval', $value ), true ) : (string) $value === $route['value'];
			if ( $match ) {
				$to = array_merge( $to, Form_Settings::emails( $route['to'] ) );
			}
		}
		$unique = array();
		foreach ( $to as $email ) {
			$unique[ strtolower( $email ) ] = $email;
		}
		if ( ! $unique ) {
			$fallback = Settings_Section::fallback_recipient();
			if ( '' !== $fallback ) {
				$unique[ strtolower( $fallback ) ] = $fallback;
			}
		}
		return array_values( $unique );
	}

	private static function subject( string $template, \WP_Post $form, array $fields, array $entry ): string {
		$title = (string) $entry['form_title'];
		/* translators: %s: form title */
		$subject      = '' !== $template ? $template : sprintf( __( 'New entry: %s', 'cradle-forms' ), $title );
		$replacements = array(
			'{form}'   => $title,
			'{site}'   => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'{source}' => self::line( (string) ( $entry['source_title'] ?? '' ), 100 ), // A field named "source" takes precedence.
		);
		foreach ( $fields as $field ) {
			if ( ! in_array( $field['type'], array( 'file', 'textarea', 'consent' ), true ) ) {
				$replacements[ '{' . $field['name'] . '}' ] = self::line( self::value_text( $entry, $field ), 100 );
			}
		}
		return self::line( strtr( $subject, $replacements ), 250 );
	}

	private static function body( array $fields, array $entry, bool $include_values, bool $admin ): string {
		$lines = array();
		if ( $include_values ) {
			foreach ( $fields as $field ) {
				$label = 'consent' === $field['type'] ? (string) $field['label_text'] : $field['label'];
				$value = self::value_text( $entry, $field );
				if ( '' === $value ) {
					$value = '—';
				}
				$lines[] = self::line( $label, 200 ) . ':';
				$lines[] = '  ' . str_replace( "\n", "\n  ", str_replace( "\r", '', $value ) );
				$lines[] = '';
			}
		}
		if ( $admin && ! empty( $entry['source_id'] ) ) {
			$title = '' !== (string) $entry['source_title'] ? (string) $entry['source_title'] : '#' . (int) $entry['source_id'];
			$url   = 'publish' === get_post_status( (int) $entry['source_id'] ) ? (string) get_permalink( (int) $entry['source_id'] ) : '';
			$lines[] = '' !== $url
				/* translators: 1: title of the page the form was sent from, 2: its address */
				? sprintf( __( 'Sent from: %1$s (%2$s)', 'cradle-forms' ), self::line( $title ), $url )
				/* translators: %s: title of the page the form was sent from */
				: sprintf( __( 'Sent from: %s', 'cradle-forms' ), self::line( $title ) );
		}
		if ( $admin && $entry['files'] ) {
			/* translators: %d: number of files */
			$lines[] = sprintf( _n( '%d file was uploaded. Download it from the entry in the admin area.', '%d files were uploaded. Download them from the entry in the admin area.', count( $entry['files'] ), 'cradle-forms' ), count( $entry['files'] ) );
		}
		if ( $admin && $entry['id'] ) {
			/* translators: %s: admin URL of the entry */
			$lines[] = sprintf( __( 'View the entry: %s', 'cradle-forms' ), Entries::admin_url( (int) $entry['id'] ) );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Sends every enabled notification of a form for one submission.
	 */
	public static function send( \WP_Post $form, array $fields, array $settings, array $entry, string $locale ) {
		foreach ( $settings['notifications'] as $notification ) {
			if ( empty( $notification['enabled'] ) ) {
				continue;
			}
			$to = self::recipients( $notification, $fields, $entry );
			if ( $to ) {
				$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
				$reply   = Form_Schema::field( $fields, $notification['replyToField'] );
				$address = $reply && 'email' === $reply['type'] ? (string) ( $entry['values'][ $reply['name'] ] ?? '' ) : '';
				if ( '' !== $address && is_email( $address ) ) {
					$headers[] = 'Reply-To: ' . str_replace( array( "\r", "\n" ), '', $address );
				}
				$body  = sprintf(
					/* translators: 1: form title, 2: site name */
					__( 'A new entry was submitted with the form “%1$s” on %2$s.', 'cradle-forms' ),
					$entry['form_title'],
					wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
				) . "\n\n" . self::body( $fields, $entry, ! empty( $notification['includeValues'] ), true );
				self::mail(
					array(
						'to'      => $to,
						'subject' => self::subject( (string) $notification['subject'], $form, $fields, $entry ),
						'message' => $body,
						'headers' => $headers,
					),
					array(
						'kind'         => 'notification',
						'form'         => $form,
						'entry'        => $entry,
						'notification' => $notification,
					)
				);
			}

			if ( ! empty( $notification['copyToSubmitter'] ) ) {
				$field   = Form_Schema::submitter_email_field( $fields );
				$address = $field ? (string) ( $entry['values'][ $field['name'] ] ?? '' ) : '';
				if ( '' === $address || ! is_email( $address ) ) {
					continue;
				}
				$switched = Language::switch_to( $locale );
				$message  = __( 'Thank you. This is a copy of what you sent us.', 'cradle-forms' ) . "\n\n" . self::body( $fields, $entry, ! empty( $notification['includeValues'] ), false );
				/* translators: %s: form title */
				$subject = self::line( sprintf( __( 'Copy of your submission: %s', 'cradle-forms' ), $entry['form_title'] ), 250 );
				if ( $switched ) {
					restore_previous_locale();
				}
				self::mail(
					array(
						'to'      => array( str_replace( array( "\r", "\n" ), '', $address ) ),
						'subject' => $subject,
						'message' => $message,
						'headers' => array( 'Content-Type: text/plain; charset=UTF-8' ),
					),
					array(
						'kind'         => 'copy',
						'form'         => $form,
						'entry'        => $entry,
						'notification' => $notification,
					)
				);
			}
		}
	}

	private static function mail( array $args, array $context ) {
		/**
		 * Filters a notification email before it is sent. Return an empty `to` to skip it.
		 *
		 * @param array $args    to (string[]), subject, message, headers (string[]).
		 * @param array $context kind (`notification` or `copy`), form (WP_Post), entry, notification (settings row).
		 */
		$args = apply_filters( 'cradle_forms/notification_args', $args, $context );
		if ( ! is_array( $args ) || empty( $args['to'] ) ) {
			return;
		}
		$to = array_values( array_filter( (array) $args['to'], 'is_email' ) );
		if ( ! $to ) {
			return;
		}
		$headers = array_values(
			array_filter(
				array_map(
					static function ( $header ) {
						return is_string( $header ) ? str_replace( array( "\r", "\n" ), '', $header ) : '';
					},
					(array) ( $args['headers'] ?? array() )
				)
			)
		);
		wp_mail( $to, self::line( (string) ( $args['subject'] ?? '' ), 250 ), (string) ( $args['message'] ?? '' ), $headers );
	}
}
