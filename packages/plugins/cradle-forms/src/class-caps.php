<?php
/**
 * Capability names. All four default to administrators only.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Caps {

	/** Create, edit and delete forms (the cradle_form post type) and the module settings. */
	const MANAGE = 'cradle_manage_forms';

	/** Read entries, consent records and download uploaded files; mark entries read/new/spam. */
	const VIEW = 'cradle_view_form_entries';

	/** Export entries as CSV. */
	const EXPORT = 'cradle_export_form_entries';

	/** Delete entries (and their files). */
	const DELETE = 'cradle_delete_form_entries';

	public static function all(): array {
		return array( self::MANAGE, self::VIEW, self::EXPORT, self::DELETE );
	}

	/**
	 * Plain-English definitions for the module descriptor (no translation calls before init).
	 */
	public static function definitions(): array {
		return array(
			self::MANAGE => array(
				'label'       => 'Manage forms',
				'description' => 'Create, edit and delete forms, their notifications and spam settings, and the Cradle Forms settings.',
				'roles'       => array( 'administrator' ),
			),
			self::VIEW   => array(
				'label'       => 'View form entries',
				'description' => 'Read submitted entries and consent records, download uploaded files, and mark entries as read, new or spam.',
				'roles'       => array( 'administrator' ),
			),
			self::EXPORT => array(
				'label'       => 'Export form entries',
				'description' => 'Download entries of a form as a CSV file.',
				'roles'       => array( 'administrator' ),
			),
			self::DELETE => array(
				'label'       => 'Delete form entries',
				'description' => 'Permanently delete entries and their uploaded files.',
				'roles'       => array( 'administrator' ),
			),
		);
	}

	/**
	 * Whether the current user may open the Cradle → Forms screen at all.
	 */
	public static function can_open(): bool {
		return current_user_can( self::MANAGE ) || current_user_can( self::VIEW );
	}
}
