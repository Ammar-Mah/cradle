<?php
/**
 * Submission pipeline shared by the no-JavaScript handler (admin-post) and the REST route.
 *
 * Order: form available → rate limit → honeypot and signed minimum-time token → every field and
 * every upload validated (all errors collected) → `cradle_forms/validate` → store the entry,
 * values, consent records and files → notifications → `cradle_forms/entry_saved`.
 * Nonce and origin checks happen in the calling handler before this runs.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Submission {

	const HONEYPOT = 'cradle_website';
	const TOKEN    = '_cf_ts';

	public static function consent_hash( string $label_html ): string {
		return hash( 'sha256', $label_html );
	}

	private static function result( bool $ok, int $status, string $code, string $message, array $extra = array() ): array {
		return array_merge(
			array(
				'ok'           => $ok,
				'status'       => $status,
				'code'         => $code,
				'message'      => $message,
				'field_errors' => array(),
				'form_errors'  => array(),
				'values'       => array(),
				'entry_id'     => 0,
				'confirmation' => '',
				'redirect'     => '',
				'form_id'      => 0,
				'instance'     => 1,
			),
			$extra
		);
	}

	public static function rejected_message(): string {
		return __( 'Your submission could not be accepted. Please wait a few seconds and try again.', 'cradle-forms' );
	}

	/**
	 * Processes a submission.
	 *
	 * @param int    $form_id Form post ID.
	 * @param array  $params  Unslashed body parameters.
	 * @param array  $files   Uploaded files ($_FILES shape).
	 * @param string $channel `post` (no JavaScript) or `rest`.
	 */
	public static function process( int $form_id, array $params, array $files, string $channel = 'post' ): array {
		$token    = Security::read_token( is_string( $params[ self::TOKEN ] ?? null ) ? $params[ self::TOKEN ] : '' );
		$instance = $token ? $token['instance'] : 1;
		$base     = array(
			'form_id'  => $form_id,
			'instance' => $instance,
		);
		$switched = $token ? Language::switch_to( $token['locale'] ) : false;
		try {
			$form   = Post_Type::published( $form_id );
			$fields = $form ? Form_Schema::fields( $form_id ) : array();
			if ( ! $form || ! $fields ) {
				return self::result( false, 404, 'cradle_forms_unavailable', __( 'This form is not available.', 'cradle-forms' ), $base );
			}
			$settings = Form_Settings::get( $form_id );
			$client   = Security::client_hash();
			if ( ! Rate_Limit::consume( $form_id, $client, (int) $settings['rateLimit'] ) ) {
				return self::result( false, 429, 'cradle_forms_rate_limited', __( 'Too many submissions came from your network. Please try again later.', 'cradle-forms' ), $base );
			}

			// Honeypot and minimum fill time: reject without saying which check failed (the slot stays used).
			$honeypot = $params[ self::HONEYPOT ] ?? '';
			$age      = $token ? time() - $token['time'] : -1;
			if ( ! is_string( $honeypot ) || '' !== trim( $honeypot ) || ! $token || $token['form'] !== $form_id || $age < (int) $settings['minSeconds'] || $age > Security::TOKEN_MAX_AGE ) {
				return self::result( false, 400, 'cradle_forms_rejected', self::rejected_message(), $base );
			}

			$checked = self::check_fields( $fields, $params, $files );
			$errors  = $checked['field_errors'];

			/**
			 * Filters field errors after the built-in validation.
			 *
			 * @param array<string,string> $errors Field name => message. Keys that are not field names become form errors.
			 * @param array                $values Validated values (field name => value; files are not included).
			 * @param \WP_Post             $form   Form post.
			 * @param array                $fields Schema fields.
			 */
			$filtered    = apply_filters( 'cradle_forms/validate', $errors, $checked['values'], $form, $fields );
			$errors      = array();
			$form_errors = $checked['form_errors'];
			foreach ( is_array( $filtered ) ? $filtered : array() as $name => $message ) {
				if ( ! is_scalar( $message ) || '' === (string) $message ) {
					continue;
				}
				if ( is_string( $name ) && Form_Schema::field( $fields, $name ) ) {
					$errors[ $name ] = (string) $message;
				} else {
					$form_errors[] = (string) $message;
				}
			}

			if ( $errors || $form_errors ) {
				Rate_Limit::release( $form_id, $client, (int) $settings['rateLimit'] );
				return self::result(
					false,
					422,
					'cradle_forms_invalid',
					__( 'Please correct the errors below.', 'cradle-forms' ),
					array_merge(
						$base,
						array(
							'field_errors' => $errors,
							'form_errors'  => array_values( array_unique( $form_errors ) ),
							'values'       => $checked['display'],
						)
					)
				);
			}

			$language = $token['language'];
			$locale   = $token['locale'];
			$stored   = self::store( $form, $fields, $settings, $checked, $language, $locale, (int) $token['source'] );
			if ( is_wp_error( $stored ) ) {
				Rate_Limit::release( $form_id, $client, (int) $settings['rateLimit'] );
				return self::result( false, 500, $stored->get_error_code(), $stored->get_error_message(), $base );
			}

			$confirmation = '' !== trim( wp_strip_all_tags( $settings['confirmationMessage'] ) )
				? wp_kses( $settings['confirmationMessage'], Form_Settings::message_tags() )
				: '<p>' . esc_html__( 'Thank you. Your submission has been received.', 'cradle-forms' ) . '</p>';
			$redirect     = '';
			if ( 'page' === $settings['confirmationType'] && $settings['redirectPage'] ) {
				$page_id = self::translated_page( (int) $settings['redirectPage'], $language );
				if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
					$redirect = (string) get_permalink( $page_id );
				}
			}
			$message = wp_strip_all_tags( $confirmation );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}

		Notifications::send( $form, $fields, $settings, $stored, $locale );

		/**
		 * Fires after a successful submission (stored or not; `$entry['id']` is 0 when the form
		 * does not store entries). Use it for integrations such as newsletter providers.
		 *
		 * @param array    $entry Entry: id, form_id, form_title, language, locale, created_at, source_id, source_title, values (name => value), fields, files, consent.
		 * @param \WP_Post $form  Form post.
		 */
		do_action( 'cradle_forms/entry_saved', $stored, $form );

		return self::result(
			true,
			200,
			'cradle_forms_sent',
			$message,
			array_merge(
				$base,
				array(
					'entry_id'     => (int) $stored['id'],
					'confirmation' => $confirmation,
					'redirect'     => $redirect,
				)
			)
		);
	}

	private static function translated_page( int $page_id, string $language ): int {
		if ( '' !== $language && Language::languages_active() && function_exists( 'cradle_get_translation' ) ) {
			$translation = cradle_get_translation( $page_id, $language );
			$translation = $translation instanceof \WP_Post ? $translation->ID : ( is_numeric( $translation ) ? (int) $translation : 0 );
			if ( $translation > 0 ) {
				return $translation;
			}
		}
		return $page_id;
	}

	/**
	 * Validates every field and upload; collects all errors.
	 *
	 * @return array{values:array,display:array,field_errors:array,form_errors:array,uploads:array}
	 */
	private static function check_fields( array $fields, array $params, array $files ): array {
		$raw      = is_array( $params['cradle_fields'] ?? null ) ? $params['cradle_fields'] : array();
		$hashes   = is_array( $params['cradle_consent'] ?? null ) ? $params['cradle_consent'] : array();
		$uploads  = Uploads::normalize( $files['cradle_files'] ?? null );
		$out      = array(
			'values'       => array(),
			'display'      => array(),
			'field_errors' => array(),
			'form_errors'  => array(),
			'uploads'      => array(),
		);
		$storage  = null;

		foreach ( $fields as $field ) {
			$name = $field['name'];
			switch ( $field['type'] ) {
				case 'hidden':
					$value                   = '' !== $field['from_query'] && isset( $raw[ $name ] ) ? Field_Types::plain( $raw[ $name ], 200 ) : $field['default'];
					$out['values'][ $name ]  = $value;
					$out['display'][ $name ] = $value;
					break;

				case 'file':
					$list = $uploads[ $name ] ?? array();
					if ( ! $list ) {
						if ( ! empty( $field['required'] ) ) {
							$out['field_errors'][ $name ] = Field_Types::required_message();
						}
						break;
					}
					if ( count( $list ) > $field['max_files'] ) {
						/* translators: %d: maximum number of files */
						$out['field_errors'][ $name ] = sprintf( _n( 'You can upload at most %d file.', 'You can upload at most %d files.', (int) $field['max_files'], 'cradle-forms' ), (int) $field['max_files'] );
						break;
					}
					foreach ( $list as $file ) {
						$error = Uploads::validate( $file, $field );
						if ( null !== $error ) {
							$out['field_errors'][ $name ] = $error;
							break 2;
						}
					}
					if ( null === $storage ) {
						$storage = Uploads::storage_protected();
					}
					if ( ! $storage ) {
						$out['form_errors'][] = __( 'File uploads are not available on this site right now. Please try again later or contact us another way.', 'cradle-forms' );
						break;
					}
					$out['uploads'][ $name ] = $list;
					break;

				default:
					$checked = Field_Types::check( $field, $raw[ $name ] ?? null );
					if ( 'consent' === $field['type'] && '1' === $checked['value'] ) {
						$shown  = is_string( $hashes[ $name ] ?? null ) ? $hashes[ $name ] : '';
						$actual = self::consent_hash( wp_kses( $field['label'], Field_Types::consent_tags() ) );
						if ( ! hash_equals( $actual, $shown ) ) {
							$checked = array(
								'value' => '',
								'error' => __( 'This text changed after the page was loaded. Please read it again and confirm.', 'cradle-forms' ),
							);
						}
					}
					$out['values'][ $name ]  = $checked['value'];
					$out['display'][ $name ] = $checked['value'];
					if ( null !== $checked['error'] ) {
						$out['field_errors'][ $name ] = $checked['error'];
					}
			}
		}
		return $out;
	}

	/**
	 * Stores the entry (when the form stores entries) and builds the entry array for
	 * notifications and the `cradle_forms/entry_saved` action.
	 *
	 * @return array|\WP_Error
	 */
	private static function store( \WP_Post $form, array $fields, array $settings, array $checked, string $language, string $locale, int $source = 0 ) {
		$now       = gmdate( 'Y-m-d H:i:s' );
		$consent   = array();
		$rows      = array();
		$submitter = Form_Schema::submitter_email_field( $fields );
		foreach ( $fields as $field ) {
			if ( 'file' === $field['type'] ) {
				continue;
			}
			$value  = $checked['values'][ $field['name'] ] ?? '';
			$label  = 'consent' === $field['type'] ? (string) $field['label_text'] : $field['label'];
			$rows[] = array(
				'field_name' => $field['name'],
				'label'      => $label,
				'type'       => $field['type'],
				'value'      => Field_Types::to_storage( $value ),
			);
			if ( 'consent' === $field['type'] && '1' === $value ) {
				$shown     = wp_kses( $field['label'], Field_Types::consent_tags() );
				$consent[] = array(
					'field'    => $field['name'],
					'text'     => $shown,
					'plain'    => trim( wp_strip_all_tags( $shown ) ),
					'hash'     => self::consent_hash( $shown ),
					'language' => $language,
					'locale'   => $locale,
					'given_at' => gmdate( 'c', strtotime( $now . ' UTC' ) ),
				);
			}
		}
		$entry = array(
			'id'              => 0,
			'form_id'         => (int) $form->ID,
			'form_title'      => get_the_title( $form ),
			'language'        => $language,
			'locale'          => $locale,
			'created_at'      => $now,
			'ip_hash'         => Security::client_hash(),
			'ua_hash'         => Security::user_agent_hash(),
			'submitter_email' => $submitter ? (string) ( $checked['values'][ $submitter['name'] ] ?? '' ) : '',
			'source_id'       => 0,
			'source_title'    => '',
			'consent'         => $consent,
			'values'          => $checked['values'],
			'fields'          => $fields,
			'files'           => array(),
		);
		$entry['form_title'] = html_entity_decode( (string) $entry['form_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Source page (signed into the render token): ID and a title snapshot, kept when the page is renamed.
		$source_post = $source > 0 ? get_post( $source ) : null;
		if ( $source_post ) {
			$entry['source_id']    = (int) $source_post->ID;
			$entry['source_title'] = mb_substr( trim( wp_strip_all_tags( html_entity_decode( (string) $source_post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ), 0, 255 );
		}

		if ( ! $settings['storeEntries'] && ! Form_Schema::forces_storage( $fields ) ) {
			return $entry;
		}
		$id = Entries::insert( $entry, $rows );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$entry['id'] = $id;
		foreach ( $checked['uploads'] as $name => $list ) {
			$field = Form_Schema::field( $fields, (string) $name );
			$names = array();
			foreach ( $list as $file ) {
				$row = Uploads::store( $file, $field, $id );
				if ( is_wp_error( $row ) ) {
					Entries::delete( array( $id ) );
					return $row;
				}
				$entry['files'][] = $row;
				$names[]          = $row['original_name'];
			}
			Entries::add_value(
				$id,
				array(
					'field_name' => $field['name'],
					'label'      => $field['label'],
					'type'       => 'file',
					'value'      => Field_Types::to_storage( $names ),
				)
			);
			$entry['values'][ $field['name'] ] = $names;
		}
		return $entry;
	}
}
