<?php
/**
 * WordPress privacy tools (Tools → Export / Erase Personal Data).
 *
 * Entries are matched ONLY through the email field marked "This is the submitter's own email
 * address" (stored lowercased in `submitter_email` at submission time). Any other email field
 * (for example "a colleague's email") never makes an entry someone's personal data export.
 * This fixes the Complaint Management System finding where any email answer matched.
 * Erase deletes the matching entries completely (values, consent records and files).
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	const ID       = 'cradle-forms';
	const PER_PAGE = 20;

	public static function hooks() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Form entries', 'cradle-forms' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Form entries', 'cradle-forms' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	public static function policy_text() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'Cradle Forms',
				'<p>' . esc_html__( 'When you submit a form, the values you enter, any files you upload, the date, the page language and, for consent checkboxes, the exact consent text you agreed to are stored. Your IP address is not stored; a keyed hash of your network is kept to limit abuse.', 'cradle-forms' ) . '</p>'
			);
		}
	}

	/**
	 * @param string $email Requester email.
	 * @param int    $page  1-based page.
	 */
	public static function export( $email, $page = 1 ): array {
		$page = max( 1, (int) $page );
		$ids  = Schema::exists() ? Entries::ids_for_email( (string) $email, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) : array();
		$data = array();
		foreach ( $ids as $id ) {
			$entry = Entries::get( $id );
			if ( ! $entry ) {
				continue;
			}
			$items = array(
				array(
					'name'  => __( 'Form', 'cradle-forms' ),
					'value' => $entry['form_title'],
				),
				array(
					'name'  => __( 'Submitted', 'cradle-forms' ),
					'value' => get_date_from_gmt( $entry['created_at'], 'Y-m-d H:i' ),
				),
				array(
					'name'  => __( 'Language', 'cradle-forms' ),
					'value' => $entry['language'],
				),
			);
			foreach ( Entries::values( $id ) as $row ) {
				$items[] = array(
					'name'  => $row['label'],
					'value' => Field_Types::display( $row['type'], $row['value'] ),
				);
			}
			foreach ( $entry['consent'] as $record ) {
				$items[] = array(
					'name'  => __( 'Consent', 'cradle-forms' ),
					/* translators: 1: consent text, 2: date and time */
					'value' => sprintf( __( '%1$s (given %2$s)', 'cradle-forms' ), (string) ( $record['plain'] ?? '' ), (string) ( $record['given_at'] ?? '' ) ),
				);
			}
			$data[] = array(
				'group_id'    => self::ID,
				'group_label' => __( 'Form entries', 'cradle-forms' ),
				'item_id'     => 'cradle-form-entry-' . $id,
				'data'        => $items,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $ids ) < self::PER_PAGE,
		);
	}

	/**
	 * @param string $email Requester email.
	 * @param int    $page  1-based page (every call deletes the first batch, so the page is ignored).
	 */
	public static function erase( $email, $page = 1 ): array {
		$ids     = Schema::exists() ? Entries::ids_for_email( (string) $email, self::PER_PAGE ) : array();
		$removed = $ids ? Entries::delete( $ids ) : 0;
		if ( $removed ) {
			cradle_log_activity( Module::ID, 'privacy_erase', sprintf( 'Privacy eraser deleted %d entries.', $removed ) );
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < self::PER_PAGE,
		);
	}
}
