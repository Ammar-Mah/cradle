<?php
/**
 * Daily retention run: deletes entries (and their files) older than each form's retention days.
 *
 * Bounded: at most RUN_LIMIT entries per run in batches; a large backlog is finished over the
 * following days. A form with retention 0 keeps its entries.
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Retention {

	const HOOK      = 'cradle_forms_retention';
	const BATCH     = 200;
	const RUN_LIMIT = 5000;

	public static function hooks() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * @return array{deleted:int,forms:int} Report.
	 */
	public static function run(): array {
		$report = array(
			'deleted' => 0,
			'forms'   => 0,
		);
		if ( ! Schema::exists() ) {
			return $report;
		}
		$lock = null;
		if ( function_exists( 'cradle' ) && cradle() ) {
			$lock = cradle()->locks()->acquire( 'forms:retention', 300 );
			if ( ! $lock ) {
				return $report;
			}
		}
		try {
			$forms = get_posts(
				array(
					'post_type'        => Post_Type::NAME,
					'post_status'      => 'any',
					'numberposts'      => 500,
					'fields'           => 'ids',
					'suppress_filters' => true,
					'no_found_rows'    => true,
				)
			);
			foreach ( $forms as $form_id ) {
				$days = (int) Form_Settings::get( (int) $form_id )['retentionDays'];
				if ( $days <= 0 ) {
					continue;
				}
				++$report['forms'];
				$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
				while ( $report['deleted'] < self::RUN_LIMIT ) {
					$ids = Entries::ids_older_than( (int) $form_id, $cutoff, self::BATCH );
					if ( ! $ids ) {
						break;
					}
					$report['deleted'] += Entries::delete( $ids );
				}
			}
		} finally {
			if ( $lock ) {
				cradle()->locks()->release( 'forms:retention', $lock );
			}
		}
		if ( $report['deleted'] > 0 ) {
			cradle_log_activity( Module::ID, 'retention', sprintf( 'Retention deleted %d old entries.', $report['deleted'] ), $report );
		}
		return $report;
	}
}
