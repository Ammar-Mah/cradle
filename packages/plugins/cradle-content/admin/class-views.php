<?php
/**
 * Read-only admin screens: definition lists, rename/delete/fork previews, export/import, migrations.
 *
 * @package Cradle\Content
 */

namespace Cradle\Content\Admin;

use Cradle\Content\Definitions;
use Cradle\Content\Field_Types;
use Cradle\Content\Migrations;
use Cradle\Content\Module;
use Cradle\Content\Registry;
use Cradle\Content\Settings_Section;
use Cradle\Content\Transfer;
use Cradle\Content\Validator;

defined( 'ABSPATH' ) || exit;

final class Views {

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private static function badge( string $kind, string $text ): string {
		return '<span class="cradle-badge cradle-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Post counts for many types in one grouped query.
	 *
	 * @param string[] $types Keys.
	 * @return array<string,int>
	 */
	private static function post_totals( array $types ): array {
		global $wpdb;
		$types = array_values( array_filter( $types, static fn( $t ) => is_string( $t ) && preg_match( '/^[a-z0-9_-]{1,32}$/', $t ) ) );
		if ( ! $types ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_type, COUNT(*) AS c FROM {$wpdb->posts} WHERE post_type IN ({$in}) AND post_status NOT IN ('auto-draft') GROUP BY post_type", ...$types ), ARRAY_A );
		return array_column( $rows ?: array(), 'c', 'post_type' );
	}

	/**
	 * @param string[] $taxonomies Keys.
	 * @return array<string,int>
	 */
	private static function term_totals( array $taxonomies ): array {
		global $wpdb;
		$taxonomies = array_values( array_filter( $taxonomies, static fn( $t ) => is_string( $t ) && preg_match( '/^[a-z0-9_-]{1,32}$/', $t ) ) );
		if ( ! $taxonomies ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT taxonomy, COUNT(*) AS c FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ({$in}) GROUP BY taxonomy", ...$taxonomies ), ARRAY_A );
		return array_column( $rows ?: array(), 'c', 'taxonomy' );
	}

	private static function inline_post( string $action, string $kind, string $key, string $label, array $fields = array(), string $class = 'button-link', string $confirm = '' ) {
		printf( '<form method="post" action="%1$s" class="cradle-inline-form"%2$s>', esc_url( admin_url( 'admin-post.php' ) ), $confirm ? ' data-cradle-confirm="' . esc_attr( $confirm ) . '"' : '' );
		echo '<input type="hidden" name="action" value="' . esc_attr( 'cradle_content_' . $action ) . '" />';
		echo '<input type="hidden" name="kind" value="' . esc_attr( $kind ) . '" />';
		echo '<input type="hidden" name="key" value="' . esc_attr( $key ) . '" />';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		wp_nonce_field( 'cradle_content_' . $action . '_' . $kind );
		printf( '<button type="submit" class="%1$s">%2$s</button></form>', esc_attr( $class ), esc_html( $label ) );
	}

	/* ---------------------------------------------------------------------
	 * Lists
	 * ------------------------------------------------------------------- */

	public static function index( string $kind ) {
		$defs      = Definitions::instance();
		$registry  = Registry::instance();
		$conflicts = $registry->conflicts();
		$rows      = array();
		foreach ( $defs->code( $kind ) as $key => $def ) {
			$rows[ $key ]['code'] = $def;
		}
		foreach ( $defs->db( $kind ) as $key => $def ) {
			$rows[ $key ]['db'] = $def;
		}
		ksort( $rows );
		$counts = 'post_type' === $kind ? self::post_totals( array_keys( $rows ) ) : ( 'taxonomy' === $kind ? self::term_totals( array_keys( $rows ) ) : array() );

		$intro = array(
			'post_type'   => __( 'Post types hold your structured content (services, projects, people…). They are registered by this plugin, so they survive theme changes. Disabling a post type hides it but never deletes its posts.', 'cradle-content' ),
			'taxonomy'    => __( 'Taxonomies classify content. Disabling a taxonomy keeps its terms and assignments in the database.', 'cradle-content' ),
			'field_group' => __( 'Field groups add validated fields to post types or taxonomies. Values are stored as native metadata (keys starting with _cradle_cf_) and edited in the block editor sidebar or classic forms.', 'cradle-content' ),
		);
		$add = array(
			'post_type'   => __( 'Add post type', 'cradle-content' ),
			'taxonomy'    => __( 'Add taxonomy', 'cradle-content' ),
			'field_group' => __( 'Add field group', 'cradle-content' ),
		);
		echo '<p>' . esc_html( $intro[ $kind ] ) . '</p>';
		printf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
			esc_url(
				Admin::url(
					array(
						'tab'    => $kind,
						'action' => 'new',
					)
				)
			),
			esc_html( $add[ $kind ] )
		);

		$retained = array();
		?>
		<table class="widefat striped cradle-table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Key', 'cradle-content' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Name', 'cradle-content' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Owner', 'cradle-content' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'cradle-content' ); ?></th>
				<th scope="col"><?php echo 'field_group' === $kind ? esc_html__( 'Attached to', 'cradle-content' ) : esc_html__( 'Stored content', 'cradle-content' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'cradle-content' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No definitions yet.', 'cradle-content' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $rows as $key => $sources ) :
				foreach ( array( 'code', 'db' ) as $source ) :
					if ( empty( $sources[ $source ] ) ) {
						continue;
					}
					$def      = $sources[ $source ];
					$shadowed = 'db' === $source && ! empty( $sources['code'] );
					$label    = 'field_group' === $kind ? $def['title'] : $def['plural'];
					$count    = (int) ( $counts[ $key ] ?? 0 );
					if ( ! $shadowed && empty( $def['enabled'] ) && $count > 0 ) {
						$retained[ $key ] = $count;
					}
					?>
					<tr>
						<td><code><?php echo esc_html( $key ); ?></code></td>
						<td><strong><?php echo esc_html( $label ); ?></strong>
							<?php if ( 'post_type' === $kind && ! empty( $def['icon'] ) ) : ?>
								<span class="dashicons dashicons-<?php echo esc_attr( $def['icon'] ); ?>" aria-hidden="true"></span>
							<?php endif; ?>
						</td>
						<td>
						<?php
						if ( 'code' === $source ) {
							/* translators: %s: owner name */
							echo self::badge( 'warn', sprintf( __( 'Code: %s (read-only)', 'cradle-content' ), $def['owner'] ?? 'code' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} elseif ( $shadowed ) {
							echo self::badge( 'warn', __( 'Database copy — inactive while the code definition exists', 'cradle-content' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							echo esc_html__( 'Database', 'cradle-content' );
							if ( ! empty( $def['forked_from'] ) ) {
								echo '<br /><small>' . esc_html__( 'Forked from code', 'cradle-content' ) . '</small>';
							}
						}
						?>
						</td>
						<td>
						<?php
						if ( $shadowed ) {
							echo '—';
						} elseif ( empty( $def['enabled'] ) ) {
							echo self::badge( 'bad', __( 'Disabled', 'cradle-content' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} elseif ( isset( $conflicts[ $kind . ':' . $key ] ) ) {
							echo self::badge( 'bad', $conflicts[ $kind . ':' . $key ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							echo self::badge( 'ok', __( 'Enabled', 'cradle-content' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
						</td>
						<td>
						<?php
						if ( 'field_group' === $kind ) {
							$fields = count( Field_Types::compile( $def ) );
							/* translators: 1: number of fields, 2: object type, 3: targets */
							echo esc_html( sprintf( _n( '%1$d field on %2$s: %3$s', '%1$d fields on %2$s: %3$s', $fields, 'cradle-content' ), $fields, 'term' === $def['object'] ? __( 'terms', 'cradle-content' ) : __( 'posts', 'cradle-content' ), implode( ', ', (array) $def['targets'] ) ) );
						} elseif ( ! $shadowed ) {
							/* translators: %d: count */
							echo esc_html( sprintf( 'post_type' === $kind ? _n( '%d post', '%d posts', $count, 'cradle-content' ) : _n( '%d term', '%d terms', $count, 'cradle-content' ), $count ) );
						}
						?>
						</td>
						<td class="cradle-content-actions">
						<?php
						$edit_label = 'code' === $source ? __( 'View', 'cradle-content' ) : __( 'Edit', 'cradle-content' );
						printf(
							'<a href="%1$s">%2$s</a>',
							esc_url(
								Admin::url(
									array(
										'tab'    => $kind,
										'action' => 'edit',
										'key'    => $key,
										'source' => $source,
									)
								)
							),
							esc_html( $edit_label )
						);
						if ( 'db' === $source ) {
							echo ' | ';
							self::inline_post( 'toggle', $kind, $key, empty( $def['enabled'] ) ? __( 'Enable', 'cradle-content' ) : __( 'Disable', 'cradle-content' ), array( 'enabled' => empty( $def['enabled'] ) ? '1' : '0' ) );
							if ( 'field_group' !== $kind && ! $shadowed ) {
								printf(
									' | <a href="%1$s">%2$s</a>',
									esc_url(
										Admin::url(
											array(
												'tab'    => $kind,
												'action' => 'rename',
												'key'    => $key,
											)
										)
									),
									esc_html__( 'Rename key', 'cradle-content' )
								);
							}
							printf(
								' | <a href="%1$s" class="cradle-content-danger">%2$s</a>',
								esc_url(
									Admin::url(
										array(
											'tab'    => $kind,
											'action' => 'delete',
											'key'    => $key,
										)
									)
								),
								esc_html__( 'Delete…', 'cradle-content' )
							);
						} else {
							printf(
								' | <a href="%1$s">%2$s</a>',
								esc_url(
									Admin::url(
										array(
											'tab'    => $kind,
											'action' => 'fork',
											'key'    => $key,
										)
									)
								),
								esc_html__( 'Fork to database', 'cradle-content' )
							);
						}
						if ( 'post_type' === $kind && ! $shadowed && $registry->is_registered( 'post_type', $key ) && ! empty( $def['show_ui'] ) ) {
							printf( ' | <a href="%1$s">%2$s</a>', esc_url( admin_url( 'edit.php?post_type=' . rawurlencode( $key ) ) ), esc_html__( 'Manage posts', 'cradle-content' ) );
						}
						if ( 'taxonomy' === $kind && ! $shadowed && $registry->is_registered( 'taxonomy', $key ) && ! empty( $def['show_ui'] ) ) {
							printf( ' | <a href="%1$s">%2$s</a>', esc_url( admin_url( 'edit-tags.php?taxonomy=' . rawurlencode( $key ) ) ), esc_html__( 'Manage terms', 'cradle-content' ) );
						}
						?>
						</td>
					</tr>
					<?php
				endforeach;
			endforeach;
			?>
			</tbody>
		</table>
		<?php
		if ( $retained && Settings_Section::get( 'show_retained_notice', true ) ) {
			echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Disabled definitions still hold content:', 'cradle-content' ) . '</strong> ';
			$parts = array();
			foreach ( $retained as $key => $count ) {
				$parts[] = sprintf( '%1$s (%2$d)', $key, $count );
			}
			echo esc_html( implode( ', ', $parts ) ) . '</p><p>' . esc_html__( 'Nothing was deleted. The content is hidden from the site, REST API and admin screens until you enable the definition again. To move it elsewhere, use Delete… → Convert to another post type.', 'cradle-content' ) . '</p></div>';
		}

		$errors = array_filter( $defs->code_errors(), static fn( $id ) => str_starts_with( $id, $kind . ':' ), ARRAY_FILTER_USE_KEY );
		if ( $errors ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Code definitions rejected by validation (not registered):', 'cradle-content' ) . '</strong></p><ul class="ul-disc">';
			foreach ( $errors as $id => $messages ) {
				$flat = array();
				foreach ( $messages as $field => $message ) {
					$flat[] = $field . ': ' . $message;
				}
				echo '<li><code>' . esc_html( $id ) . '</code> — ' . esc_html( implode( ' ', $flat ) ) . '</li>';
			}
			echo '</ul></div>';
		}
		if ( 'post_type' === $kind || 'taxonomy' === $kind ) {
			self::foreign( $kind );
		}
		if ( 'post_type' === $kind ) {
			self::orphans( array_keys( $rows ) );
		}
	}

	/**
	 * Read-only list of custom post types or taxonomies registered by other plugins or the theme,
	 * so the screen shows every content type an editor meets in the admin menu.
	 */
	private static function foreign( string $kind ) {
		$registry = Registry::instance();
		$objects  = array();
		foreach ( $registry->foreign_keys( $kind ) as $key ) {
			$object = 'post_type' === $kind ? get_post_type_object( $key ) : get_taxonomy( $key );
			if ( $object && empty( $object->_builtin ) && ( $object->public || $object->show_ui ) ) {
				$objects[ $key ] = $object;
			}
		}
		if ( ! $objects ) {
			return;
		}
		ksort( $objects );
		$counts = 'post_type' === $kind ? self::post_totals( array_keys( $objects ) ) : self::term_totals( array_keys( $objects ) );
		echo '<h2>' . esc_html( 'post_type' === $kind ? __( 'Post types registered by other plugins or the theme', 'cradle-content' ) : __( 'Taxonomies registered by other plugins or the theme', 'cradle-content' ) ) . '</h2>';
		echo '<p>' . esc_html__( 'Shown for reference. Their code defines them, so they are edited there, not here, and their keys and URL slugs cannot be reused.', 'cradle-content' ) . '</p>';
		echo '<table class="widefat striped cradle-table"><thead><tr><th scope="col">' . esc_html__( 'Name', 'cradle-content' ) . '</th><th scope="col">' . esc_html__( 'Key', 'cradle-content' ) . '</th><th scope="col">' . esc_html( 'post_type' === $kind ? __( 'Posts', 'cradle-content' ) : __( 'Terms', 'cradle-content' ) ) . '</th><th scope="col">' . esc_html__( 'Used with', 'cradle-content' ) . '</th></tr></thead><tbody>';
		foreach ( $objects as $key => $object ) {
			$with = 'post_type' === $kind ? get_object_taxonomies( $key ) : (array) $object->object_type;
			echo '<tr><td>' . esc_html( $object->labels->name ) . '</td><td><code>' . esc_html( $key ) . '</code></td><td>' . esc_html( (string) ( $counts[ $key ] ?? 0 ) ) . '</td><td>' . esc_html( $with ? implode( ', ', $with ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Lists stored posts whose post type is not registered by anyone, and explains how to reach them.
	 */
	private static function orphans( array $defined ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin-only grouped count on the indexed post_type column.
		$rows    = $wpdb->get_results( "SELECT post_type, COUNT(*) AS c FROM {$wpdb->posts} WHERE post_status <> 'auto-draft' GROUP BY post_type LIMIT 200", ARRAY_A );
		$orphans = array();
		foreach ( $rows ?: array() as $row ) {
			if ( ! post_type_exists( $row['post_type'] ) && ! in_array( $row['post_type'], $defined, true ) ) {
				$orphans[ $row['post_type'] ] = (int) $row['c'];
			}
		}
		if ( ! $orphans ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Stored posts without a registered type', 'cradle-content' ) . '</h2>';
		echo '<p>' . esc_html__( 'These posts are in the database but no active plugin registers their type (for example after a definition was deleted, or when another plugin is inactive). Re-create a definition with the same key to make them accessible again.', 'cradle-content' ) . '</p>';
		echo '<table class="widefat striped cradle-table"><thead><tr><th scope="col">' . esc_html__( 'Key', 'cradle-content' ) . '</th><th scope="col">' . esc_html__( 'Posts', 'cradle-content' ) . '</th><th scope="col">' . esc_html__( 'Action', 'cradle-content' ) . '</th></tr></thead><tbody>';
		foreach ( $orphans as $key => $count ) {
			echo '<tr><td><code>' . esc_html( $key ) . '</code></td><td>' . esc_html( (string) $count ) . '</td><td>';
			if ( preg_match( Validator::POST_TYPE_PATTERN, $key ) && ! in_array( $key, Validator::THIRD_PARTY_KEYS, true ) ) {
				printf(
					'<a href="%1$s">%2$s</a>',
					esc_url(
						Admin::url(
							array(
								'tab'    => 'post_type',
								'action' => 'new',
								'key'    => $key,
							)
						)
					),
					esc_html__( 'Create a definition for this key', 'cradle-content' )
				);
			} else {
				esc_html_e( 'Owned by another plugin; reactivate it.', 'cradle-content' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------
	 * Rename preview
	 * ------------------------------------------------------------------- */

	public static function rename( string $kind, string $key ) {
		$defs = Definitions::instance();
		$def  = $defs->db( $kind )[ $key ] ?? null;
		if ( 'field_group' === $kind || ! $def || $defs->is_code_owned( $kind, $key ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Only database post types and taxonomies can be renamed.', 'cradle-content' ) . '</p></div>';
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only preview.
		$new_key   = isset( $_GET['new_key'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['new_key'] ) ) ) : '';
		$keep_urls = ! isset( $_GET['new_key'] ) || isset( $_GET['keep_urls'] );
		// phpcs:enable
		/* translators: %s: key */
		echo '<h2>' . esc_html( sprintf( __( 'Rename key "%s"', 'cradle-content' ), $key ) ) . '</h2>';
		echo '<p>' . esc_html__( 'Changing a label is a normal edit. Changing the internal key moves every stored record to the new key: posts or terms, navigation menu items, and references in other database definitions. Review the preview before confirming.', 'cradle-content' ) . '</p>';
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( $kind ); ?>" />
			<input type="hidden" name="action" value="rename" />
			<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>" />
			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row"><label for="cradle-content-new-key"><?php esc_html_e( 'New key', 'cradle-content' ); ?></label></th>
					<td><input type="text" id="cradle-content-new-key" name="new_key" value="<?php echo esc_attr( $new_key ); ?>" class="regular-text code" maxlength="<?php echo 'post_type' === $kind ? 20 : 32; ?>" required /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'URLs', 'cradle-content' ); ?></th>
					<td><label><input type="checkbox" name="keep_urls" value="1" <?php checked( $keep_urls ); ?> /> <?php esc_html_e( 'Keep current URLs and REST base (pin them to the old key when they were derived from it)', 'cradle-content' ); ?></label></td>
				</tr>
			</tbody></table>
			<?php submit_button( __( 'Preview migration', 'cradle-content' ), 'secondary', '', false ); ?>
		</form>
		<?php
		if ( '' === $new_key ) {
			return;
		}
		$input        = Definitions::clean( $def );
		$input['key'] = $new_key;
		$check        = Validator::validate( $kind, $input, $defs->context( $kind, $key ) );
		if ( isset( $check['errors']['key'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $check['errors']['key'] ) . '</p></div>';
			return;
		}
		$preview = Migrations::rename_preview( $kind, $key, $new_key );
		echo '<h3>' . esc_html__( 'Affected records', 'cradle-content' ) . '</h3><table class="widefat striped cradle-table"><tbody>';
		foreach ( $preview['records'] as $status => $count ) {
			/* translators: %s: post status or "terms" */
			echo '<tr><th scope="row">' . esc_html( 'post_type' === $kind ? sprintf( __( 'Posts (%s)', 'cradle-content' ), $status ) : __( 'Terms', 'cradle-content' ) ) . '</th><td>' . esc_html( (string) $count ) . '</td></tr>';
		}
		if ( ! $preview['records'] ) {
			echo '<tr><th scope="row">' . esc_html__( 'Stored records', 'cradle-content' ) . '</th><td>0</td></tr>';
		}
		if ( 'taxonomy' === $kind ) {
			echo '<tr><th scope="row">' . esc_html__( 'Term assignments (unchanged, follow their terms)', 'cradle-content' ) . '</th><td>' . esc_html( (string) $preview['relationships'] ) . '</td></tr>';
		}
		echo '<tr><th scope="row">' . esc_html__( 'Navigation menu items', 'cradle-content' ) . '</th><td>' . esc_html( (string) $preview['menu_items'] ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Database definitions updated automatically', 'cradle-content' ) . '</th><td>' . esc_html( $preview['references']['db'] ? implode( ', ', $preview['references']['db'] ) : '—' ) . '</td></tr>';
		echo '</tbody></table>';
		if ( $preview['references']['code'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Code definitions reference the old key and must be updated in the code that supplies them:', 'cradle-content' ) . ' ' . esc_html( implode( ', ', $preview['references']['code'] ) ) . '</p></div>';
		}
		if ( $preview['existing'] > 0 ) {
			/* translators: %d: count */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( '%d stored records already use the new key; they will be merged with the migrated ones.', 'cradle-content' ), $preview['existing'] ) ) . '</p></div>';
		}
		if ( ! $keep_urls && ! empty( $def['rewrite'] ) && '' === $def['rewrite_slug'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Public URLs derived from the key will change and old links will return 404.', 'cradle-content' ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cradle_content_rename" />
			<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>" />
			<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>" />
			<input type="hidden" name="new_key" value="<?php echo esc_attr( $new_key ); ?>" />
			<input type="hidden" name="keep_urls" value="<?php echo $keep_urls ? '1' : '0'; ?>" />
			<?php wp_nonce_field( 'cradle_content_rename_' . $kind ); ?>
			<?php
			/* translators: 1: record count, 2: new key */
			submit_button( sprintf( __( 'Rename and migrate %1$d records to "%2$s"', 'cradle-content' ), $preview['total'] + $preview['menu_items'], $new_key ), 'primary', 'submit', false );
			?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Delete preview
	 * ------------------------------------------------------------------- */

	public static function delete( string $kind, string $key ) {
		$defs = Definitions::instance();
		$def  = $defs->db( $kind )[ $key ] ?? null;
		if ( ! $def ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Only database definitions can be deleted here. Code definitions are removed from the code that supplies them.', 'cradle-content' ) . '</p></div>';
			return;
		}
		$shadowed = $defs->is_code_owned( $kind, $key );
		/* translators: %s: key */
		echo '<h2>' . esc_html( sprintf( __( 'Delete definition "%s"', 'cradle-content' ), $key ) ) . '</h2>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cradle_content_delete" />
			<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>" />
			<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>" />
			<?php wp_nonce_field( 'cradle_content_delete_' . $kind ); ?>
			<?php
			if ( $shadowed ) {
				echo '<p>' . esc_html__( 'This database copy is inactive because a code definition owns the key. Deleting it changes nothing on the site.', 'cradle-content' ) . '</p>';
				echo '<input type="hidden" name="content" value="keep" />';
			} elseif ( 'post_type' === $kind ) {
				$counts = Migrations::post_counts( $key );
				$total  = array_sum( $counts );
				echo '<p>' . esc_html(
					sprintf(
						/* translators: 1: count, 2: breakdown */
						__( 'Stored posts of this type: %1$d (%2$s).', 'cradle-content' ),
						$total,
						$counts ? implode( ', ', array_map( static fn( $s, $c ) => $s . ': ' . $c, array_keys( $counts ), $counts ) ) : '—'
					)
				) . '</p>';
				?>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'What happens to the posts', 'cradle-content' ); ?></legend>
					<p><label><input type="radio" name="content" value="keep" checked /> <?php esc_html_e( 'Keep the posts in the database (recommended). They become unreachable until a definition with this key exists again — re-create it or import an export.', 'cradle-content' ); ?></label></p>
					<p><label><input type="radio" name="content" value="convert" /> <?php esc_html_e( 'Convert the posts to another post type:', 'cradle-content' ); ?></label>
						<label for="cradle-content-convert" class="screen-reader-text"><?php esc_html_e( 'Target post type', 'cradle-content' ); ?></label>
						<select id="cradle-content-convert" name="convert_to">
							<?php
							foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $name => $object ) {
								if ( $name === $key || 'attachment' === $name || in_array( $name, Validator::INTERNAL_POST_TYPES, true ) ) {
									continue;
								}
								printf( '<option value="%1$s">%2$s (%1$s)</option>', esc_attr( $name ), esc_html( $object->labels->singular_name ) );
							}
							?>
						</select>
					</p>
					<p class="description"><?php esc_html_e( 'Conversion keeps IDs, content, authors, dates, field values and term assignments. Slugs that already exist in the target type may produce duplicate URLs; review them afterwards.', 'cradle-content' ); ?></p>
				</fieldset>
				<?php
			} elseif ( 'taxonomy' === $kind ) {
				/* translators: 1: terms, 2: assignments */
				echo '<p>' . esc_html( sprintf( __( 'Stored terms: %1$d. Term assignments: %2$d.', 'cradle-content' ), Migrations::term_count( $key ), Migrations::relationship_count( $key ) ) ) . '</p>';
				echo '<p>' . esc_html__( 'Terms and assignments stay in the database and come back when a taxonomy with this key exists again. To remove them for good, delete the terms on the taxonomy screen before deleting the definition.', 'cradle-content' ) . '</p>';
				echo '<input type="hidden" name="content" value="keep" />';
			} else {
				$total = 0;
				echo '<table class="widefat striped cradle-table"><thead><tr><th scope="col">' . esc_html__( 'Field', 'cradle-content' ) . '</th><th scope="col">' . esc_html__( 'Stored values', 'cradle-content' ) . '</th></tr></thead><tbody>';
				foreach ( Field_Types::compile( $def ) as $field ) {
					$count  = Migrations::meta_count( $def['object'], $field['meta_key'], (array) $def['targets'] );
					$total += $count;
					echo '<tr><td>' . esc_html( $field['label'] ) . ' <code>' . esc_html( $field['meta_key'] ) . '</code></td><td>' . esc_html( (string) $count ) . '</td></tr>';
				}
				echo '</tbody></table>';
				?>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'What happens to stored values', 'cradle-content' ); ?></legend>
					<p><label><input type="radio" name="content" value="keep" checked /> <?php esc_html_e( 'Keep stored values (they return if a field with the same key is defined again).', 'cradle-content' ); ?></label></p>
					<?php /* translators: %d: count */ ?>
					<p><label><input type="radio" name="content" value="delete" /> <?php echo esc_html( sprintf( __( 'Delete all %d stored values permanently (in batches).', 'cradle-content' ), $total ) ); ?></label></p>
				</fieldset>
				<?php
			}
			?>
			<p><label><input type="checkbox" name="confirm" value="1" required /> <?php esc_html_e( 'I reviewed the counts above and want to delete this definition.', 'cradle-content' ); ?></label></p>
			<?php submit_button( __( 'Delete definition', 'cradle-content' ), 'delete', 'submit', false ); ?>
			<a class="button" href="<?php echo esc_url( Admin::url( array( 'tab' => $kind ) ) ); ?>"><?php esc_html_e( 'Cancel', 'cradle-content' ); ?></a>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Fork
	 * ------------------------------------------------------------------- */

	public static function fork( string $kind, string $key ) {
		if ( ! Definitions::instance()->is_code_owned( $kind, $key ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Only code-managed definitions can be forked.', 'cradle-content' ) . '</p></div>';
			return;
		}
		/* translators: %s: key */
		echo '<h2>' . esc_html( sprintf( __( 'Fork code definition "%s"', 'cradle-content' ), $key ) ) . '</h2>';
		echo '<p>' . esc_html__( 'Forking copies the code definition into the database so it can be edited here. Each key has one owner: with the same key the copy stays inactive until the code definition is removed from the code that supplies it; with a new key the copy is an independent definition.', 'cradle-content' ) . '</p>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cradle_content_fork" />
			<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>" />
			<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>" />
			<?php wp_nonce_field( 'cradle_content_fork_' . $kind ); ?>
			<p><label for="cradle-content-fork-key"><?php esc_html_e( 'Key of the database copy', 'cradle-content' ); ?></label><br />
			<input type="text" id="cradle-content-fork-key" name="new_key" value="<?php echo esc_attr( $key ); ?>" class="regular-text code" /></p>
			<?php submit_button( __( 'Create database copy', 'cradle-content' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Field storage confirmation
	 * ------------------------------------------------------------------- */

	/**
	 * Renamed or removed top-level fields of a stored group that hold data.
	 *
	 * @param string $original Group key.
	 * @param array  $input    Normalized form input.
	 * @return array{object:string,targets:string[],changes:array}
	 */
	public static function field_storage_impact( string $original, array $input ): array {
		$old = Definitions::instance()->db( 'field_group' )[ $original ] ?? null;
		$out = array(
			'object'  => $old ? ( 'term' === $old['object'] ? 'term' : 'post' ) : 'post',
			'targets' => $old ? array_values( (array) $old['targets'] ) : array(),
			'changes' => array(),
		);
		if ( ! $old ) {
			return $out;
		}
		$new_by_original = array();
		foreach ( (array) ( $input['fields'] ?? array() ) as $row ) {
			if ( is_array( $row ) && is_string( $row['original_key'] ?? null ) && '' !== $row['original_key'] && '' === (string) ( $row['parent'] ?? '' ) ) {
				$new_by_original[ $row['original_key'] ] = is_string( $row['key'] ?? null ) ? strtolower( trim( $row['key'] ) ) : '';
			}
		}
		foreach ( Field_Types::compile( $old ) as $key => $field ) {
			$count = Migrations::meta_count( $out['object'], $field['meta_key'], $out['targets'] );
			if ( ! $count ) {
				continue;
			}
			if ( ! array_key_exists( $key, $new_by_original ) ) {
				$out['changes'][] = array(
					'type'  => 'remove',
					'from'  => $key,
					'label' => $field['label'],
					'count' => $count,
				);
			} elseif ( $new_by_original[ $key ] !== $key && preg_match( Validator::FIELD_PATTERN, $new_by_original[ $key ] ) ) {
				$out['changes'][] = array(
					'type'  => 'rename',
					'from'  => $key,
					'to'    => $new_by_original[ $key ],
					'label' => $field['label'],
					'count' => $count,
				);
			}
		}
		return $out;
	}

	public static function confirm_fields() {
		$pending = get_transient( 'cradle_content_pending_' . get_current_user_id() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token compared below; the confirm form posts with a nonce.
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		if ( ! is_array( $pending ) || ! hash_equals( (string) $pending['token'], $token ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'This confirmation expired. Save the field group again.', 'cradle-content' ) . '</p></div>';
			return;
		}
		/* translators: %s: group key */
		echo '<h2>' . esc_html( sprintf( __( 'Confirm field storage changes for "%s"', 'cradle-content' ), $pending['original'] ) ) . '</h2>';
		echo '<p>' . esc_html__( 'Some fields you renamed or removed already hold stored values. Choose what happens to them. Nothing has been saved yet.', 'cradle-content' ) . '</p>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cradle_content_confirm_fields" />
			<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( 'cradle_content_confirm_fields' ); ?>
			<table class="widefat striped cradle-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Field', 'cradle-content' ); ?></th><th scope="col"><?php esc_html_e( 'Change', 'cradle-content' ); ?></th><th scope="col"><?php esc_html_e( 'Stored values', 'cradle-content' ); ?></th><th scope="col"><?php esc_html_e( 'Stored data', 'cradle-content' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $pending['impact']['changes'] as $change ) : ?>
					<tr>
						<td><?php echo esc_html( $change['label'] ); ?> <code><?php echo esc_html( $change['from'] ); ?></code></td>
						<td><?php echo 'rename' === $change['type'] ? esc_html( sprintf( /* translators: %s: new key */ __( 'Renamed to "%s"', 'cradle-content' ), $change['to'] ) ) : esc_html__( 'Removed', 'cradle-content' ); ?></td>
						<td><?php echo esc_html( (string) $change['count'] ); ?></td>
						<td>
							<label class="screen-reader-text" for="cradle-content-storage-<?php echo esc_attr( $change['from'] ); ?>"><?php esc_html_e( 'Stored data', 'cradle-content' ); ?></label>
							<select id="cradle-content-storage-<?php echo esc_attr( $change['from'] ); ?>" name="storage[<?php echo esc_attr( $change['from'] ); ?>]">
								<?php if ( 'rename' === $change['type'] ) : ?>
									<option value="migrate" selected><?php esc_html_e( 'Move values to the new key', 'cradle-content' ); ?></option>
									<option value="keep"><?php esc_html_e( 'Leave values under the old key', 'cradle-content' ); ?></option>
								<?php else : ?>
									<option value="keep" selected><?php esc_html_e( 'Keep values (restorable)', 'cradle-content' ); ?></option>
									<option value="delete"><?php esc_html_e( 'Delete values permanently', 'cradle-content' ); ?></option>
								<?php endif; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Save field group', 'cradle-content' ), 'primary', 'submit', false ); ?>
			<a class="button" href="<?php echo esc_url( Admin::url( array( 'tab' => 'field_group' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'cradle-content' ); ?></a>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Export / import
	 * ------------------------------------------------------------------- */

	public static function tools() {
		$defs = Definitions::instance();
		echo '<h2>' . esc_html__( 'Export definitions', 'cradle-content' ) . '</h2>';
		echo '<p>' . esc_html__( 'Downloads a JSON file with the selected definitions (no posts, terms or field values). Import it on another site to deploy the same content model.', 'cradle-content' ) . '</p>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cradle_content_export" />
			<?php wp_nonce_field( 'cradle_content_export' ); ?>
			<fieldset class="cradle-content-export-list">
				<legend class="screen-reader-text"><?php esc_html_e( 'Definitions to export', 'cradle-content' ); ?></legend>
				<?php
				$any = false;
				foreach ( Definitions::KINDS as $kind ) {
					$all = $defs->db( $kind );
					foreach ( $defs->code( $kind ) as $key => $def ) {
						$all[ $key ] = $def;
					}
					foreach ( $all as $key => $def ) {
						$any = true;
						printf(
							'<label><input type="checkbox" name="selection[]" value="%1$s" checked /> %2$s <code>%3$s</code>%4$s</label><br />',
							esc_attr( $kind . ':' . $key ),
							esc_html( Definitions::kind_label( $kind ) ),
							esc_html( $key ),
							$defs->is_code_owned( $kind, $key ) ? ' <em>' . esc_html__( '(code)', 'cradle-content' ) . '</em>' : ''
						);
					}
				}
				if ( ! $any ) {
					echo '<p>' . esc_html__( 'There are no definitions yet.', 'cradle-content' ) . '</p>';
				}
				?>
			</fieldset>
			<?php submit_button( __( 'Download export', 'cradle-content' ), 'secondary', 'submit', false, $any ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Import definitions', 'cradle-content' ); ?></h2>
		<p><?php esc_html_e( 'Importing shows a preview first. Definitions that are not in the file are never changed; definitions that differ are only overwritten when you tick them.', 'cradle-content' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="cradle_content_import" />
			<?php wp_nonce_field( 'cradle_content_import' ); ?>
			<p><label for="cradle-content-import-file"><?php esc_html_e( 'Export file (JSON, up to 1 MB)', 'cradle-content' ); ?></label><br />
			<input type="file" id="cradle-content-import-file" name="cradle_content_file" accept="application/json,.json" /></p>
			<p><label for="cradle-content-import-json"><?php esc_html_e( 'Or paste the JSON', 'cradle-content' ); ?></label><br />
			<textarea id="cradle-content-import-json" name="json" rows="6" class="large-text code"></textarea></p>
			<?php submit_button( __( 'Preview import', 'cradle-content' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
		self::import_preview();
	}

	private static function import_preview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; token compared with the stored preview.
		$token  = isset( $_GET['import'] ) ? sanitize_text_field( wp_unslash( $_GET['import'] ) ) : '';
		$stored = get_transient( 'cradle_content_import_' . get_current_user_id() );
		if ( '' === $token || ! is_array( $stored ) || ! hash_equals( (string) $stored['token'], $token ) ) {
			return;
		}
		$labels = array(
			'new'       => __( 'New — will be created', 'cradle-content' ),
			'identical' => __( 'Identical — skipped', 'cradle-content' ),
			'update'    => __( 'Differs from this site', 'cradle-content' ),
			'blocked'   => __( 'Blocked', 'cradle-content' ),
			'invalid'   => __( 'Invalid', 'cradle-content' ),
		);
		$badges = array(
			'new'       => 'ok',
			'identical' => 'ok',
			'update'    => 'warn',
			'blocked'   => 'bad',
			'invalid'   => 'bad',
		);
		echo '<h2 id="cradle-content-import-preview">' . esc_html__( 'Import preview', 'cradle-content' ) . '</h2>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cradle_content_import_apply" />
			<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( 'cradle_content_import_apply' ); ?>
			<table class="widefat striped cradle-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Definition', 'cradle-content' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'cradle-content' ); ?></th><th scope="col"><?php esc_html_e( 'Details', 'cradle-content' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $stored['preview']['items'] as $item ) : ?>
					<tr>
						<td><?php echo esc_html( Definitions::kind_label( $item['kind'] ) ); ?> <code><?php echo esc_html( $item['key'] ); ?></code></td>
						<td><?php echo self::badge( $badges[ $item['status'] ], $labels[ $item['status'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td>
						<?php
						if ( 'update' === $item['status'] ) {
							printf( '<label><input type="checkbox" name="overwrite[]" value="%1$s" /> %2$s</label>', esc_attr( $item['kind'] . ':' . $item['key'] ), esc_html__( 'Overwrite the definition on this site', 'cradle-content' ) );
						}
						foreach ( array_merge( $item['errors'], $item['warnings'] ) as $message ) {
							echo '<br /><small>' . esc_html( $message ) . '</small>';
						}
						?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Import', 'cradle-content' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Migrations
	 * ------------------------------------------------------------------- */

	public static function migrations() {
		echo '<h2>' . esc_html__( 'Migrations', 'cradle-content' ) . '</h2>';
		echo '<p>' . esc_html__( 'Key renames, conversions and field storage changes move stored records in bounded batches. Unfinished migrations continue here (while this screen is open) and in the background through WP-Cron.', 'cradle-content' ) . '</p>';
		$jobs = cradle()->jobs()->recent( Module::ID, 20 );
		if ( ! $jobs ) {
			echo '<p>' . esc_html__( 'No migrations yet.', 'cradle-content' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped cradle-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Migration', 'cradle-content' ); ?></th><th scope="col"><?php esc_html_e( 'Started (UTC)', 'cradle-content' ); ?></th><th scope="col"><?php esc_html_e( 'Progress', 'cradle-content' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $jobs as $job ) : ?>
				<tr>
					<td><?php echo esc_html( (string) ( $job['payload']['label'] ?? $job['type'] ) ); ?></td>
					<td><?php echo esc_html( $job['created_at'] ); ?></td>
					<td>
					<?php if ( in_array( $job['state'], \Cradle\Core\Jobs::TERMINAL_STATES, true ) || ! cradle()->jobs()->user_can_access( $job ) ) : ?>
						<?php echo self::badge( 'completed' === $job['state'] ? 'ok' : 'bad', $job['state'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo esc_html( sprintf( ' %1$d / %2$d', $job['progress_done'], $job['progress_total'] ) ); ?>
						<?php if ( $job['error_message'] ) : ?>
							<br /><small><?php echo esc_html( $job['error_message'] ); ?></small>
						<?php endif; ?>
					<?php else : ?>
						<div data-cradle-job="<?php echo esc_attr( $job['uuid'] ); ?>">
							<progress class="cradle-progress" max="<?php echo esc_attr( (string) max( 1, $job['progress_total'] ) ); ?>" value="<?php echo esc_attr( (string) $job['progress_done'] ); ?>"></progress>
							<p aria-live="polite"><?php echo esc_html( $job['state'] . ( $job['phase'] ? ' — ' . $job['phase'] : '' ) ); ?></p>
						</div>
					<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
