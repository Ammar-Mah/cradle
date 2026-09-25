<?php
/**
 * Trusted-administrator raw CSS: storage, bounded revision history, restore,
 * reset and output gating.
 *
 * Access: cradle_manage_branding + manage_options + unfiltered_html. When the
 * environment removes unfiltered_html (DISALLOW_UNFILTERED_HTML) or the
 * `cradle_admin/raw_css_available` filter returns false, raw CSS is disabled:
 * it cannot be edited and stored CSS is not printed. Structured controls remain.
 *
 * @package Cradle\Admin
 */

namespace Cradle\Admin;

defined( 'ABSPATH' ) || exit;

final class Custom_Css {

	const OPTION           = 'cradle_admin_custom_css';
	const REVISIONS_OPTION = 'cradle_admin_custom_css_revisions';
	const MAX_REVISIONS    = 10;

	public static function empty_state(): array {
		return array(
			'css'       => '',
			'revision'  => 0,
			'modified'  => 0,
			'author'    => 0,
			'signature' => '',
		);
	}

	/**
	 * Whether this environment permits raw CSS at all.
	 */
	public static function environment_allows(): bool {
		if ( defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML ) {
			return false;
		}
		/**
		 * Filters whether the raw CSS feature is available. Can only disable it.
		 *
		 * @param bool $available True by default.
		 */
		return false !== apply_filters( 'cradle_admin/raw_css_available', true );
	}

	/**
	 * Trusted-administrator check for editing raw CSS.
	 *
	 * @param int|null $user_id User (defaults to current).
	 */
	public static function user_can_edit( ?int $user_id = null ): bool {
		if ( ! self::environment_allows() ) {
			return false;
		}
		$user_id = null === $user_id ? get_current_user_id() : $user_id;
		if ( ! $user_id ) {
			return false;
		}
		return user_can( $user_id, Module::CAP ) && user_can( $user_id, 'manage_options' ) && user_can( $user_id, 'unfiltered_html' );
	}

	public static function current(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$state  = array_merge( self::empty_state(), array_intersect_key( $stored, self::empty_state() ) );
		if ( ! is_string( $state['css'] ) ) {
			$state['css'] = '';
		}
		$state['revision'] = (int) $state['revision'];
		$state['modified'] = (int) $state['modified'];
		$state['author']   = (int) $state['author'];
		return $state;
	}

	/**
	 * @return array<int,array{revision:int,css:string,saved:int,author:int,bytes:int}>
	 */
	public static function revisions(): array {
		$list = get_option( self::REVISIONS_OPTION, array() );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $item ) {
			if ( is_array( $item ) && isset( $item['revision'], $item['css'] ) && is_string( $item['css'] ) ) {
				$out[] = array(
					'revision' => (int) $item['revision'],
					'css'      => $item['css'],
					'saved'    => (int) ( $item['saved'] ?? 0 ),
					'author'   => (int) ( $item['author'] ?? 0 ),
					'bytes'    => strlen( $item['css'] ),
				);
			}
		}
		return $out;
	}

	private static function signature( string $css ): string {
		return hash_hmac( 'sha256', Css_Policy::VERSION . '|' . $css, wp_salt( 'auth' ) );
	}

	/**
	 * Saves new CSS after validation and a stale-edit check.
	 *
	 * @param mixed $css           Submitted CSS.
	 * @param int   $base_revision Revision the editor started from.
	 * @param int   $user_id       Acting user.
	 * @return array|\WP_Error New state, or an error whose messages list every problem.
	 */
	public static function save( $css, int $base_revision, int $user_id ) {
		if ( ! self::user_can_edit( $user_id ) ) {
			return new \WP_Error( 'cradle_admin_css_forbidden', __( 'Raw CSS requires a trusted administrator (manage_options and unfiltered_html) on an environment that allows it.', 'cradle-admin' ) );
		}
		if ( is_string( $css ) ) {
			$css = str_replace( "\r\n", "\n", $css );
		}
		$problems = Css_Policy::validate( $css );
		if ( $problems ) {
			$error = new \WP_Error();
			foreach ( $problems as $problem ) {
				$error->add( 'cradle_admin_css_rejected', $problem );
			}
			return $error;
		}
		$current = self::current();
		if ( $base_revision !== $current['revision'] ) {
			return new \WP_Error( 'cradle_admin_css_stale', __( 'The custom CSS changed after you opened this page. Nothing was saved; your draft is shown below so you can compare and save again.', 'cradle-admin' ) );
		}
		if ( trim( $css ) === trim( $current['css'] ) ) {
			return $current;
		}
		return self::commit( trim( $css ), $user_id, 'custom_css_saved' );
	}

	/**
	 * Restores a stored revision (re-validated against the current policy).
	 *
	 * @return array|\WP_Error
	 */
	public static function restore( int $revision, int $user_id ) {
		if ( ! self::user_can_edit( $user_id ) ) {
			return new \WP_Error( 'cradle_admin_css_forbidden', __( 'Raw CSS requires a trusted administrator (manage_options and unfiltered_html) on an environment that allows it.', 'cradle-admin' ) );
		}
		foreach ( self::revisions() as $item ) {
			if ( $item['revision'] === $revision ) {
				$problems = Css_Policy::validate( $item['css'] );
				if ( $problems ) {
					return new \WP_Error( 'cradle_admin_css_rejected', __( 'This revision does not meet the current CSS policy and cannot be restored:', 'cradle-admin' ) . ' ' . implode( ' ', $problems ) );
				}
				if ( $item['css'] === self::current()['css'] ) {
					return self::current();
				}
				return self::commit( $item['css'], $user_id, 'custom_css_restored', $revision );
			}
		}
		return new \WP_Error( 'cradle_admin_css_missing', __( 'That revision no longer exists.', 'cradle-admin' ) );
	}

	/**
	 * Clears the active CSS. The previous CSS stays in the revision history.
	 *
	 * @return array|\WP_Error
	 */
	public static function reset( int $user_id ) {
		if ( ! self::user_can_edit( $user_id ) ) {
			return new \WP_Error( 'cradle_admin_css_forbidden', __( 'Raw CSS requires a trusted administrator (manage_options and unfiltered_html) on an environment that allows it.', 'cradle-admin' ) );
		}
		if ( '' === self::current()['css'] ) {
			return self::current();
		}
		return self::commit( '', $user_id, 'custom_css_reset' );
	}

	/**
	 * Writes the new active CSS and pushes the previous one onto the bounded history.
	 */
	private static function commit( string $css, int $user_id, string $action, int $from = 0 ): array {
		$current = self::current();
		if ( '' !== $current['css'] ) {
			$history = self::revisions();
			array_unshift(
				$history,
				array(
					'revision' => $current['revision'],
					'css'      => $current['css'],
					'saved'    => $current['modified'] ? $current['modified'] : time(),
					'author'   => $current['author'],
				)
			);
			$history = array_slice( $history, 0, self::MAX_REVISIONS );
			$stored  = array();
			foreach ( $history as $item ) {
				$stored[] = array(
					'revision' => $item['revision'],
					'css'      => $item['css'],
					'saved'    => $item['saved'],
					'author'   => $item['author'],
				);
			}
			update_option( self::REVISIONS_OPTION, $stored, false );
		}
		$state = array(
			'css'       => $css,
			'revision'  => $current['revision'] + 1,
			'modified'  => time(),
			'author'    => $user_id,
			'signature' => '' === $css ? '' : self::signature( $css ),
		);
		update_option( self::OPTION, $state, false );

		$messages = array(
			'custom_css_saved'    => 'Custom admin CSS saved (%1$d bytes, revision %2$d).',
			'custom_css_restored' => 'Custom admin CSS restored from revision %3$d (%1$d bytes, now revision %2$d).',
			'custom_css_reset'    => 'Custom admin CSS reset (revision %2$d); the previous CSS stays in the revision history.',
		);
		if ( function_exists( 'cradle_log_activity' ) ) {
			cradle_log_activity( Module::ID, $action, sprintf( $messages[ $action ], strlen( $css ), $state['revision'], $from ), array( 'revision' => $state['revision'] ) );
		}
		return $state;
	}

	/**
	 * CSS that may be printed now, or ''. Stored data is not trusted blindly: a
	 * valid HMAC from a policy-checked save is required, otherwise the CSS is
	 * validated again (and skipped when it fails).
	 */
	public static function printable(): string {
		if ( Module::safe_mode() || ! self::environment_allows() ) {
			return '';
		}
		$state = self::current();
		if ( '' === $state['css'] ) {
			return '';
		}
		if ( is_string( $state['signature'] ) && '' !== $state['signature'] && hash_equals( self::signature( $state['css'] ), $state['signature'] ) ) {
			return $state['css'];
		}
		return Css_Policy::validate( $state['css'] ) ? '' : $state['css'];
	}

	/*
	 * Short-lived per-user draft of a rejected submission, so nothing typed is lost.
	 */

	public static function draft_key( int $user_id ): string {
		return 'cradle_admin_css_draft_' . $user_id;
	}

	public static function store_draft( int $user_id, string $css, array $errors ) {
		if ( strlen( $css ) > Css_Policy::MAX_BYTES * 2 ) {
			$css = substr( $css, 0, Css_Policy::MAX_BYTES * 2 );
		}
		set_transient(
			self::draft_key( $user_id ),
			array(
				'css'    => $css,
				'errors' => array_slice( array_map( 'strval', $errors ), 0, 30 ),
			),
			10 * MINUTE_IN_SECONDS
		);
	}

	public static function take_draft( int $user_id ): ?array {
		$draft = get_transient( self::draft_key( $user_id ) );
		if ( false === $draft ) {
			return null;
		}
		delete_transient( self::draft_key( $user_id ) );
		return is_array( $draft ) && isset( $draft['css'] ) && is_string( $draft['css'] ) ? $draft : null;
	}
}
