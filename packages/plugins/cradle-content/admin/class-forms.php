<?php
/**
 * Server-rendered, accessible edit forms for post type, taxonomy and field group definitions.
 * Works without JavaScript; assets/js/admin.js only adds row management and conditional settings.
 *
 * @package Cradle\Content
 */

namespace Cradle\Content\Admin;

use Cradle\Content\Definitions;
use Cradle\Content\Field_Types;
use Cradle\Content\Meta;
use Cradle\Content\Validator;

defined( 'ABSPATH' ) || exit;

final class Forms {

	/** @var array<string,string> Field errors of the form being rendered. */
	private static $errors = array();

	/** @var bool Whether the form is read-only (code definition). */
	private static $readonly = false;

	public static function render( string $kind, string $key ) {
		$defs = Definitions::instance();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen parameter.
		$source   = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		$def      = null;
		$is_code  = false;
		$original = '';
		if ( '' !== $key ) {
			$code = $defs->code( $kind )[ $key ] ?? null;
			$db   = $defs->db( $kind )[ $key ] ?? null;
			if ( $db && ( 'code' !== $source || ! $code ) ) {
				$def      = $db;
				$original = $key;
			} elseif ( $code ) {
				$def     = $code;
				$is_code = true;
			}
			if ( ! $def ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'This definition does not exist.', 'cradle-content' ) . '</p></div>';
				return;
			}
		} else {
			$def = Validator::defaults( $kind );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- prefill only (re-creating a definition for stored content).
			$prefill = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';
			if ( '' !== $prefill ) {
				$def['key'] = $prefill;
			}
		}
		self::$readonly = $is_code;
		self::$errors   = array();
		$warnings       = array();
		$state          = $is_code ? null : Admin::take_form_state( $kind, $original );
		if ( $state ) {
			$def            = array_merge( $def, (array) $state['input'] );
			self::$errors   = (array) $state['errors'];
			$warnings       = (array) $state['warnings'];
		}

		$titles = array(
			'post_type'   => '' === $original && ! $is_code ? __( 'Add post type', 'cradle-content' ) : __( 'Post type', 'cradle-content' ),
			'taxonomy'    => '' === $original && ! $is_code ? __( 'Add taxonomy', 'cradle-content' ) : __( 'Taxonomy', 'cradle-content' ),
			'field_group' => '' === $original && ! $is_code ? __( 'Add field group', 'cradle-content' ) : __( 'Field group', 'cradle-content' ),
		);
		echo '<h2>' . esc_html( $titles[ $kind ] . ( '' !== $key ? ' — ' . $key : '' ) ) . '</h2>';
		if ( $is_code ) {
			/* translators: %s: owner */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'This definition is managed in code by %s and is read-only here. Fork it to create an editable database copy.', 'cradle-content' ), $def['owner'] ?? 'code' ) ) . '</p></div>';
		}
		if ( self::$errors ) {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html__( 'Please correct the following:', 'cradle-content' ) . '</p><ul class="ul-disc">';
			foreach ( self::$errors as $field => $message ) {
				echo '<li><a href="#' . esc_attr( self::id( $field ) ) . '">' . esc_html( $field ) . '</a>: ' . esc_html( $message ) . '</li>';
			}
			echo '</ul></div>';
		}
		foreach ( $warnings as $warning ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $warning ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cradle-content-form" novalidate>
			<input type="hidden" name="action" value="cradle_content_save" />
			<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>" />
			<input type="hidden" name="original" value="<?php echo esc_attr( $original ); ?>" />
			<input type="hidden" name="revision" value="<?php echo esc_attr( '' !== $original ? (string) (int) ( $defs->db( $kind )[ $original ]['revision'] ?? 0 ) : '' ); ?>" />
			<?php wp_nonce_field( 'cradle_content_save_' . $kind ); ?>
			<fieldset class="cradle-content-form__body"<?php disabled( $is_code ); ?>>
				<legend class="screen-reader-text"><?php echo esc_html( $titles[ $kind ] ); ?></legend>
				<?php
				switch ( $kind ) {
					case 'post_type':
						self::post_type( $def, $original );
						break;
					case 'taxonomy':
						self::taxonomy( $def, $original );
						break;
					case 'field_group':
						self::field_group( $def );
						break;
				}
				?>
			</fieldset>
			<?php if ( ! $is_code ) : ?>
				<p class="submit">
					<?php submit_button( __( 'Save definition', 'cradle-content' ), 'primary', 'submit', false ); ?>
					<a class="button" href="<?php echo esc_url( Admin::url( array( 'tab' => $kind ) ) ); ?>"><?php esc_html_e( 'Cancel', 'cradle-content' ); ?></a>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Primitive controls (all names are def[...])
	 * ------------------------------------------------------------------- */

	private static function id( string $path ): string {
		return 'cradle-content-' . trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $path ) ), '-' );
	}

	private static function name( string $path ): string {
		$parts = explode( '.', $path );
		return 'def[' . implode( '][', $parts ) . ']';
	}

	private static function truthy( $value ): bool {
		return true === Validator::read_bool( $value );
	}

	/**
	 * aria-describedby/aria-invalid attributes and the help/error markup for a control.
	 *
	 * @return array{0:string,1:string} Attributes, trailing markup.
	 */
	private static function describe( string $path, string $help ): array {
		$id    = self::id( $path );
		$error = self::$errors[ $path ] ?? '';
		$ids   = trim( ( $help ? $id . '-help' : '' ) . ' ' . ( $error ? $id . '-error' : '' ) );
		$attrs = ( $ids ? ' aria-describedby="' . esc_attr( $ids ) . '"' : '' ) . ( $error ? ' aria-invalid="true"' : '' );
		$after = ( $help ? '<p class="description" id="' . esc_attr( $id . '-help' ) . '">' . esc_html( $help ) . '</p>' : '' )
			. ( $error ? '<p class="cradle-field-error" id="' . esc_attr( $id . '-error' ) . '">' . esc_html( $error ) . '</p>' : '' );
		return array( $attrs, $after );
	}

	private static function text( string $path, $value, string $label, string $help = '', array $opts = array() ) {
		list( $attrs, $after ) = self::describe( $path, $help );
		$type                  = $opts['type'] ?? 'text';
		printf(
			'<tr class="%1$s"><th scope="row"><label for="%2$s">%3$s</label></th><td><input type="%4$s" id="%2$s" name="%5$s" value="%6$s" class="%7$s"%8$s%9$s%10$s%11$s />%12$s%13$s</td></tr>',
			esc_attr( ( self::$errors[ $path ] ?? '' ) ? 'cradle-field--error' : '' ),
			esc_attr( self::id( $path ) ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( self::name( $path ) ),
			esc_attr( is_scalar( $value ) ? (string) $value : '' ),
			esc_attr( $opts['class'] ?? 'regular-text' ),
			isset( $opts['maxlength'] ) ? ' maxlength="' . esc_attr( (string) $opts['maxlength'] ) . '"' : '',
			! empty( $opts['readonly'] ) ? ' readonly' : '',
			isset( $opts['min'] ) ? ' min="' . esc_attr( (string) $opts['min'] ) . '"' : '',
			$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$after, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			wp_kses_post( $opts['extra'] ?? '' )
		);
	}

	private static function textarea( string $path, $value, string $label, string $help = '', int $rows = 3 ) {
		list( $attrs, $after ) = self::describe( $path, $help );
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><textarea id="%1$s" name="%3$s" rows="%4$d" class="large-text"%5$s>%6$s</textarea>%7$s</td></tr>',
			esc_attr( self::id( $path ) ),
			esc_html( $label ),
			esc_attr( self::name( $path ) ),
			(int) $rows,
			$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_textarea( is_scalar( $value ) ? (string) $value : '' ),
			$after // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * One row holding several on/off switches.
	 *
	 * @param array<string,array{0:string,1:mixed}> $switches path => [label, value].
	 */
	private static function switches( string $label, array $switches, string $help = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html( $label ) . '</legend>';
		foreach ( $switches as $path => $switch ) {
			printf(
				'<input type="hidden" name="%1$s" value="0" /><label for="%2$s"><input type="checkbox" id="%2$s" name="%1$s" value="1" %3$s /> %4$s</label><br />',
				esc_attr( self::name( $path ) ),
				esc_attr( self::id( $path ) ),
				checked( self::truthy( $switch[1] ), true, false ),
				esc_html( $switch[0] )
			);
			if ( isset( self::$errors[ $path ] ) ) {
				echo '<p class="cradle-field-error">' . esc_html( self::$errors[ $path ] ) . '</p>';
			}
		}
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</fieldset></td></tr>';
	}

	/**
	 * @param array<string,string> $options value => label.
	 */
	private static function select( string $path, $value, string $label, array $options, string $help = '', array $opts = array() ) {
		list( $attrs, $after ) = self::describe( $path, $help );
		printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><select id="%1$s" name="%3$s"%4$s%5$s>', esc_attr( self::id( $path ) ), esc_html( $label ), esc_attr( self::name( $path ) ), $attrs, isset( $opts['data'] ) ? ' ' . $opts['data'] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( $options as $option => $option_label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( (string) $option ), selected( (string) $value, (string) $option, false ), esc_html( $option_label ) );
		}
		echo '</select>' . ( $opts['after'] ?? '' ) . $after . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Multiple choice list; a hidden empty entry lets "none selected" be submitted.
	 *
	 * @param array<string,string> $options value => label.
	 */
	private static function checkboxes( string $path, $values, string $label, array $options, string $help = '', string $row_attrs = '' ) {
		$values = is_array( $values ) ? array_map( 'strval', $values ) : array();
		echo '<tr' . $row_attrs . '><th scope="row">' . esc_html( $label ) . '</th><td><fieldset id="' . esc_attr( self::id( $path ) ) . '"><legend class="screen-reader-text">' . esc_html( $label ) . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<input type="hidden" name="' . esc_attr( self::name( $path ) ) . '[]" value="" />';
		if ( ! $options ) {
			echo '<em>' . esc_html__( 'Nothing available yet.', 'cradle-content' ) . '</em>';
		}
		foreach ( $options as $option => $option_label ) {
			printf(
				'<label class="cradle-content-choice"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( self::name( $path ) ),
				esc_attr( (string) $option ),
				checked( in_array( (string) $option, $values, true ), true, false ),
				esc_html( $option_label )
			);
		}
		if ( isset( self::$errors[ $path ] ) ) {
			echo '<p class="cradle-field-error">' . esc_html( self::$errors[ $path ] ) . '</p>';
		}
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</fieldset></td></tr>';
	}

	private static function section( string $title, string $intro = '' ) {
		echo '<h3 class="cradle-content-section">' . esc_html( $title ) . '</h3>';
		if ( $intro ) {
			echo '<p class="description">' . esc_html( $intro ) . '</p>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Choice lists
	 * ------------------------------------------------------------------- */

	private static function post_type_choices(): array {
		$defs = Definitions::instance();
		$out  = array();
		foreach ( $defs->effective( 'post_type' ) as $key => $def ) {
			$out[ $key ] = $def['plural'] . ' (' . $key . ')';
		}
		foreach ( get_post_types( array(), 'objects' ) as $name => $object ) {
			if ( ! isset( $out[ $name ] ) && ! in_array( $name, Validator::INTERNAL_POST_TYPES, true ) && ( $object->show_ui || 'attachment' === $name ) ) {
				$out[ $name ] = $object->labels->name . ' (' . $name . ')';
			}
		}
		return $out;
	}

	private static function taxonomy_choices(): array {
		$defs = Definitions::instance();
		$out  = array();
		foreach ( $defs->effective( 'taxonomy' ) as $key => $def ) {
			$out[ $key ] = $def['plural'] . ' (' . $key . ')';
		}
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $name => $object ) {
			if ( ! isset( $out[ $name ] ) && ! in_array( $name, Validator::INTERNAL_TAXONOMIES, true ) ) {
				$out[ $name ] = $object->labels->name . ' (' . $name . ')';
			}
		}
		return $out;
	}

	private static function role_choices(): array {
		$out = array();
		foreach ( wp_roles()->roles as $name => $role ) {
			$out[ $name ] = translate_user_role( $role['name'] );
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Post types
	 * ------------------------------------------------------------------- */

	private static function post_type( array $d, string $original ) {
		self::section( __( 'Identity', 'cradle-content' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		$rename = '';
		if ( '' !== $original ) {
			$rename = ' <a href="' . esc_url(
				Admin::url(
					array(
						'tab'    => 'post_type',
						'action' => 'rename',
						'key'    => $original,
					)
				)
			) . '">' . esc_html__( 'Rename key…', 'cradle-content' ) . '</a>';
		}
		self::text(
			'key',
			$d['key'],
			__( 'Key', 'cradle-content' ),
			__( 'Stable internal identifier: 1–20 lowercase letters, numbers, dashes or underscores. Labels can change freely; changing the key later moves stored posts through a previewed migration.', 'cradle-content' ),
			array(
				'class'     => 'regular-text code',
				'maxlength' => 20,
				'readonly'  => '' !== $original,
				'extra'     => $rename,
			)
		);
		self::text( 'singular', $d['singular'], __( 'Singular label', 'cradle-content' ), '', array( 'maxlength' => 60 ) );
		self::text( 'plural', $d['plural'], __( 'Plural label', 'cradle-content' ), '', array( 'maxlength' => 60 ) );
		self::text( 'menu_name', $d['menu_name'], __( 'Admin menu label', 'cradle-content' ), __( 'Optional. Defaults to the plural label.', 'cradle-content' ), array( 'maxlength' => 60 ) );
		self::textarea( 'description', $d['description'], __( 'Description', 'cradle-content' ) );
		$icons = array();
		foreach ( Validator::DASHICONS as $icon ) {
			$icons[ $icon ] = $icon;
		}
		self::select( 'icon', $d['icon'], __( 'Menu icon', 'cradle-content' ), $icons, '', array( 'after' => ' <span class="dashicons dashicons-' . esc_attr( (string) $d['icon'] ) . ' cradle-content-icon-preview" aria-hidden="true"></span>', 'data' => 'data-cradle-icon' ) );
		self::text( 'menu_position', $d['menu_position'], __( 'Menu position', 'cradle-content' ), __( '0 uses the default position (below Comments). 5 = below Posts, 20 = below Pages.', 'cradle-content' ), array( 'type' => 'number', 'class' => 'small-text', 'min' => 0 ) );
		self::switches( __( 'Status', 'cradle-content' ), array( 'enabled' => array( __( 'Enabled (registered on the site)', 'cradle-content' ), $d['enabled'] ) ), __( 'Disabling hides the type and its posts everywhere but deletes nothing; enable it again to restore access.', 'cradle-content' ) );
		echo '</tbody></table>';

		self::section( __( 'Visibility and URLs', 'cradle-content' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::switches(
			__( 'Visibility', 'cradle-content' ),
			array(
				'public'              => array( __( 'Public', 'cradle-content' ), $d['public'] ),
				'publicly_queryable'  => array( __( 'Publicly queryable (front-end single and archive URLs)', 'cradle-content' ), $d['publicly_queryable'] ),
				'exclude_from_search' => array( __( 'Exclude from front-end search results', 'cradle-content' ), $d['exclude_from_search'] ),
				'show_ui'             => array( __( 'Show admin screens', 'cradle-content' ), $d['show_ui'] ),
				'show_in_menu'        => array( __( 'Show in the admin menu', 'cradle-content' ), $d['show_in_menu'] ),
				'show_in_admin_bar'   => array( __( 'Show in the admin bar "New" menu', 'cradle-content' ), $d['show_in_admin_bar'] ),
				'show_in_nav_menus'   => array( __( 'Available in navigation menus', 'cradle-content' ), $d['show_in_nav_menus'] ),
				'show_in_rest'        => array( __( 'Available in the REST API and the block editor', 'cradle-content' ), $d['show_in_rest'] ),
			)
		);
		self::text( 'rest_base', $d['rest_base'], __( 'REST base', 'cradle-content' ), __( 'Optional. Defaults to the key.', 'cradle-content' ), array( 'class' => 'regular-text code' ) );
		self::switches(
			__( 'Structure', 'cradle-content' ),
			array(
				'hierarchical' => array( __( 'Hierarchical (posts can have parents, like pages)', 'cradle-content' ), $d['hierarchical'] ),
				'has_archive'  => array( __( 'Has an archive page', 'cradle-content' ), $d['has_archive'] ),
				'rewrite'      => array( __( 'Pretty permalinks', 'cradle-content' ), $d['rewrite'] ),
				'with_front'   => array( __( 'Prefix URLs with the permalink front base', 'cradle-content' ), $d['with_front'] ),
				'query_var'    => array( __( 'Allow ?key=slug query URLs', 'cradle-content' ), $d['query_var'] ),
			)
		);
		self::text( 'rewrite_slug', $d['rewrite_slug'], __( 'URL slug', 'cradle-content' ), __( 'Optional. Defaults to the key. Lowercase letters, numbers, dashes; up to three segments separated by "/".', 'cradle-content' ), array( 'class' => 'regular-text code' ) );
		self::text( 'archive_slug', $d['archive_slug'], __( 'Archive slug', 'cradle-content' ), __( 'Optional. Defaults to the URL slug.', 'cradle-content' ), array( 'class' => 'regular-text code' ) );
		echo '</tbody></table>';

		self::section( __( 'Editing', 'cradle-content' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		$supports = array(
			'title'           => __( 'Title', 'cradle-content' ),
			'editor'          => __( 'Content editor', 'cradle-content' ),
			'excerpt'         => __( 'Excerpt', 'cradle-content' ),
			'thumbnail'       => __( 'Featured image', 'cradle-content' ),
			'author'          => __( 'Author', 'cradle-content' ),
			'revisions'       => __( 'Revisions', 'cradle-content' ),
			'comments'        => __( 'Comments', 'cradle-content' ),
			'custom-fields'   => __( 'Custom fields (added automatically when REST-exposed fields target this type)', 'cradle-content' ),
			'page-attributes' => __( 'Page attributes (parent, order)', 'cradle-content' ),
		);
		self::checkboxes( 'supports', $d['supports'], __( 'Supported features', 'cradle-content' ), $supports );
		self::checkboxes( 'taxonomies', $d['taxonomies'], __( 'Taxonomies', 'cradle-content' ), self::taxonomy_choices() );
		self::template_rows( (array) $d['template'] );
		self::select(
			'template_lock',
			$d['template_lock'],
			__( 'Template lock', 'cradle-content' ),
			array(
				''            => __( 'None — editors may change the starting blocks', 'cradle-content' ),
				'insert'      => __( 'Insert — blocks can be edited and moved, not added or removed', 'cradle-content' ),
				'contentOnly' => __( 'Content only — only text and media can be edited', 'cradle-content' ),
				'all'         => __( 'All — structure fully locked', 'cradle-content' ),
			),
			__( 'Applies only when a template is defined.', 'cradle-content' )
		);
		echo '</tbody></table>';

		self::section( __( 'Permissions', 'cradle-content' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::select(
			'capability_model',
			$d['capability_model'],
			__( 'Capability model', 'cradle-content' ),
			array(
				'post'   => __( 'Same as posts (authors, editors and administrators as usual)', 'cradle-content' ),
				'page'   => __( 'Same as pages (editors and administrators)', 'cradle-content' ),
				'custom' => __( 'Custom capabilities (only roles granted below, plus administrators)', 'cradle-content' ),
			),
			__( 'Object checks (map_meta_cap) always apply: users edit only what their role allows.', 'cradle-content' ),
			array( 'data' => 'data-cradle-capmodel' )
		);
		echo '</tbody><tbody data-cradle-custom-caps>';
		self::text( 'capability_singular', $d['capability_singular'], __( 'Capability name (singular)', 'cradle-content' ), __( 'For example "book" produces edit_book, read_book, delete_book.', 'cradle-content' ), array( 'class' => 'regular-text code' ) );
		self::text( 'capability_plural', $d['capability_plural'], __( 'Capability name (plural)', 'cradle-content' ), __( 'For example "books" produces edit_books, publish_books, delete_others_books.', 'cradle-content' ), array( 'class' => 'regular-text code' ) );
		self::checkboxes( 'capability_roles', $d['capability_roles'], __( 'Grant to roles', 'cradle-content' ), Validator::editorial_roles(), __( 'Administrators always receive the capabilities. Nothing is granted to other roles unless ticked.', 'cradle-content' ) );
		echo '</tbody></table>';

		self::section( __( 'Admin list', 'cradle-content' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::select(
			'order_by',
			$d['order_by'],
			__( 'Default ordering', 'cradle-content' ),
			array(
				''           => __( 'WordPress default (newest first)', 'cradle-content' ),
				'date'       => __( 'Publish date', 'cradle-content' ),
				'title'      => __( 'Title', 'cradle-content' ),
				'modified'   => __( 'Last modified', 'cradle-content' ),
				'menu_order' => __( 'Menu order', 'cradle-content' ),
			),
			__( 'Used by the admin list and the archive page when no explicit order is requested.', 'cradle-content' )
		);
		self::select(
			'order',
			$d['order'],
			__( 'Direction', 'cradle-content' ),
			array(
				'DESC' => __( 'Descending', 'cradle-content' ),
				'ASC'  => __( 'Ascending', 'cradle-content' ),
			)
		);
		$columns = array(
			'thumbnail'  => __( 'Featured image', 'cradle-content' ),
			'author'     => __( 'Author', 'cradle-content' ),
			'modified'   => __( 'Last modified (sortable)', 'cradle-content' ),
			'menu_order' => __( 'Menu order (sortable)', 'cradle-content' ),
			'excerpt'    => __( 'Excerpt', 'cradle-content' ),
		);
		if ( '' !== $original ) {
			foreach ( Meta::instance()->fields_for( 'post', $original ) as $field ) {
				/* translators: %s: field label */
				$columns[ 'field:' . $field['key'] ] = sprintf( __( 'Field: %s', 'cradle-content' ), $field['label'] );
			}
		}
		self::checkboxes( 'admin_columns', $d['admin_columns'], __( 'Extra list columns', 'cradle-content' ), $columns, __( 'Taxonomy columns are controlled by each taxonomy\'s "Show admin column" setting.', 'cradle-content' ) );
		echo '</tbody></table>';
	}

	private static function template_rows( array $rows ) {
		$blocks = array(
			''               => __( '— remove —', 'cradle-content' ),
			'core/paragraph' => __( 'Paragraph', 'cradle-content' ),
			'core/heading'   => __( 'Heading', 'cradle-content' ),
			'core/image'     => __( 'Image', 'cradle-content' ),
			'core/list'      => __( 'List', 'cradle-content' ),
			'core/quote'     => __( 'Quote', 'cradle-content' ),
			'core/gallery'   => __( 'Gallery', 'cradle-content' ),
			'core/separator' => __( 'Separator', 'cradle-content' ),
			'core/buttons'   => __( 'Button', 'cradle-content' ),
			'core/table'     => __( 'Table', 'cradle-content' ),
		);
		echo '<tr><th scope="row">' . esc_html__( 'Starting block template', 'cradle-content' ) . '</th><td>';
		echo '<p class="description">' . esc_html__( 'Blocks inserted into every new post of this type, with optional placeholder text. Leave empty for a blank editor.', 'cradle-content' ) . '</p>';
		if ( isset( self::$errors['template'] ) ) {
			echo '<p class="cradle-field-error">' . esc_html( self::$errors['template'] ) . '</p>';
		}
		echo '<div class="cradle-content-repeater" data-cradle-repeater="template"><ol class="cradle-content-rows" data-cradle-rows>';
		$index = 0;
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && '' !== ( $row['block'] ?? '' ) ) {
				self::template_row( (string) $index++, $row, $blocks );
			}
		}
		self::template_row( (string) $index, array(), $blocks ); // Blank row for use without JavaScript.
		echo '</ol><template data-cradle-template>';
		self::template_row( '__i__', array(), $blocks );
		echo '</template><button type="button" class="button" data-cradle-add>' . esc_html__( 'Add block', 'cradle-content' ) . '</button></div>';
		echo '</td></tr>';
	}

	private static function template_row( string $i, array $row, array $blocks ) {
		$base = 'def[template][' . $i . ']';
		$id   = 'cradle-content-template-' . $i;
		echo '<li class="cradle-content-row" data-cradle-row>';
		printf( '<label for="%1$s-block">%2$s</label> <select id="%1$s-block" name="%3$s[block]">', esc_attr( $id ), esc_html__( 'Block', 'cradle-content' ), esc_attr( $base ) );
		foreach ( $blocks as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( (string) ( $row['block'] ?? '' ), $value, false ), esc_html( $label ) );
		}
		echo '</select> ';
		printf( '<label for="%1$s-placeholder">%2$s</label> <input type="text" id="%1$s-placeholder" name="%3$s[placeholder]" value="%4$s" maxlength="200" /> ', esc_attr( $id ), esc_html__( 'Placeholder', 'cradle-content' ), esc_attr( $base ), esc_attr( (string) ( $row['placeholder'] ?? '' ) ) );
		printf( '<label for="%1$s-level">%2$s</label> <select id="%1$s-level" name="%3$s[level]">', esc_attr( $id ), esc_html__( 'Heading level', 'cradle-content' ), esc_attr( $base ) );
		foreach ( range( 2, 6 ) as $level ) {
			printf( '<option value="%1$d" %2$s>H%1$d</option>', (int) $level, selected( (int) ( $row['level'] ?? 2 ), $level, false ) );
		}
		echo '</select> ';
		self::row_buttons();
		echo '</li>';
	}

	private static function row_buttons() {
		echo '<span class="cradle-content-row-tools">';
		echo '<button type="button" class="button-link" data-cradle-up>' . esc_html__( 'Move up', 'cradle-content' ) . '</button> ';
		echo '<button type="button" class="button-link" data-cradle-down>' . esc_html__( 'Move down', 'cradle-content' ) . '</button> ';
		echo '<button type="button" class="button-link cradle-content-danger" data-cradle-remove>' . esc_html__( 'Remove', 'cradle-content' ) . '</button>';
		echo '</span>';
	}

	/* ---------------------------------------------------------------------
	 * Taxonomies
	 * ------------------------------------------------------------------- */

	private static function taxonomy( array $d, string $original ) {
		self::section( __( 'Identity', 'cradle-content' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		$rename = '' !== $original ? ' <a href="' . esc_url(
			Admin::url(
				array(
					'tab'    => 'taxonomy',
					'action' => 'rename',
					'key'    => $original,
				)
			)
		) . '">' . esc_html__( 'Rename key…', 'cradle-content' ) . '</a>' : '';
		self::text(
			'key',
			$d['key'],
			__( 'Key', 'cradle-content' ),
			__( 'Stable internal identifier: 1–32 lowercase letters, numbers, dashes or underscores.', 'cradle-content' ),
			array(
				'class'     => 'regular-text code',
				'maxlength' => 32,
				'readonly'  => '' !== $original,
				'extra'     => $rename,
			)
		);
		self::text( 'singular', $d['singular'], __( 'Singular label', 'cradle-content' ), '', array( 'maxlength' => 60 ) );
		self::text( 'plural', $d['plural'], __( 'Plural label', 'cradle-content' ), '', array( 'maxlength' => 60 ) );
		self::text( 'menu_name', $d['menu_name'], __( 'Admin menu label', 'cradle-content' ), __( 'Optional. Defaults to the plural label.', 'cradle-content' ), array( 'maxlength' => 60 ) );
		self::textarea( 'description', $d['description'], __( 'Description', 'cradle-content' ) );
		self::switches( __( 'Status', 'cradle-content' ), array( 'enabled' => array( __( 'Enabled (registered on the site)', 'cradle-content' ), $d['enabled'] ) ), __( 'Disabling keeps every term and assignment in the database.', 'cradle-content' ) );
		echo '</tbody></table>';

		self::section( __( 'Structure', 'cradle-content' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::switches(
			__( 'Structure', 'cradle-content' ),
			array(
				'hierarchical' => array( __( 'Hierarchical (like categories)', 'cradle-content' ), $d['hierarchical'] ),
				'sort'         => array( __( 'Remember the order in which terms are added to posts', 'cradle-content' ), $d['sort'] ),
			)
		);
		self::checkboxes( 'object_types', $d['object_types'], __( 'Attached to post types', 'cradle-content' ), self::post_type_choices() );
		echo '</tbody></table>';

		self::section( __( 'Visibility and URLs', 'cradle-content' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::switches(
			__( 'Visibility', 'cradle-content' ),
			array(
				'public'             => array( __( 'Public', 'cradle-content' ), $d['public'] ),
				'publicly_queryable' => array( __( 'Publicly queryable (term archive URLs)', 'cradle-content' ), $d['publicly_queryable'] ),
				'show_ui'            => array( __( 'Show admin screens', 'cradle-content' ), $d['show_ui'] ),
				'show_in_menu'       => array( __( 'Show in the admin menu', 'cradle-content' ), $d['show_in_menu'] ),
				'show_in_nav_menus'  => array( __( 'Available in navigation menus', 'cradle-content' ), $d['show_in_nav_menus'] ),
				'show_in_rest'       => array( __( 'Available in the REST API and the block editor', 'cradle-content' ), $d['show_in_rest'] ),
				'show_tagcloud'      => array( __( 'Available to the tag cloud', 'cradle-content' ), $d['show_tagcloud'] ),
				'show_in_quick_edit' => array( __( 'Show in quick edit', 'cradle-content' ), $d['show_in_quick_edit'] ),
				'show_admin_column'  => array( __( 'Show a column on attached post type lists', 'cradle-content' ), $d['show_admin_column'] ),
				'query_var'          => array( __( 'Allow ?key=term query URLs', 'cradle-content' ), $d['query_var'] ),
			)
		);
		self::text( 'rest_base', $d['rest_base'], __( 'REST base', 'cradle-content' ), __( 'Optional. Defaults to the key.', 'cradle-content' ), array( 'class' => 'regular-text code' ) );
		self::switches(
			__( 'Permalinks', 'cradle-content' ),
			array(
				'rewrite'              => array( __( 'Pretty permalinks', 'cradle-content' ), $d['rewrite'] ),
				'with_front'           => array( __( 'Prefix URLs with the permalink front base', 'cradle-content' ), $d['with_front'] ),
				'rewrite_hierarchical' => array( __( 'Hierarchical URLs (parent/child)', 'cradle-content' ), $d['rewrite_hierarchical'] ),
			)
		);
		self::text( 'rewrite_slug', $d['rewrite_slug'], __( 'URL slug', 'cradle-content' ), __( 'Optional. Defaults to the key.', 'cradle-content' ), array( 'class' => 'regular-text code' ) );
		echo '</tbody></table>';

		self::section( __( 'Permissions', 'cradle-content' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::select(
			'capability_model',
			$d['capability_model'],
			__( 'Capability model', 'cradle-content' ),
			array(
				'default' => __( 'Default (manage_categories to manage; edit_posts to assign)', 'cradle-content' ),
				'custom'  => __( 'Custom capabilities', 'cradle-content' ),
			),
			'',
			array( 'data' => 'data-cradle-capmodel' )
		);
		echo '</tbody><tbody data-cradle-custom-caps>';
		self::text( 'capability_base', $d['capability_base'], __( 'Capability base', 'cradle-content' ), __( 'For example "genres" produces manage_genres, edit_genres, delete_genres and assign_genres.', 'cradle-content' ), array( 'class' => 'regular-text code' ) );
		self::checkboxes( 'capability_roles', $d['capability_roles'], __( 'Grant to roles', 'cradle-content' ), Validator::editorial_roles(), __( 'Administrators always receive the capabilities.', 'cradle-content' ) );
		echo '</tbody></table>';

		self::section( __( 'Default term', 'cradle-content' ), __( 'Optional. WordPress creates this term and assigns it when a post is saved without a term of this taxonomy.', 'cradle-content' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text( 'default_term_name', $d['default_term_name'], __( 'Name', 'cradle-content' ), '', array( 'maxlength' => 100 ) );
		self::text( 'default_term_slug', $d['default_term_slug'], __( 'Slug', 'cradle-content' ), '', array( 'class' => 'regular-text code' ) );
		self::textarea( 'default_term_description', $d['default_term_description'], __( 'Description', 'cradle-content' ), '', 2 );
		echo '</tbody></table>';

		if ( '' !== $original ) {
			$groups = Meta::instance()->groups_for( 'term', $original );
			self::section( __( 'Term fields', 'cradle-content' ) );
			echo '<p>';
			if ( $groups ) {
				/* translators: %s: comma-separated field group keys */
				echo esc_html( sprintf( __( 'Term metadata comes from field groups: %s.', 'cradle-content' ), implode( ', ', array_keys( $groups ) ) ) ) . ' ';
			}
			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url(
					Admin::url(
						array(
							'tab'    => 'field_group',
							'action' => 'new',
						)
					)
				),
				esc_html__( 'Add a field group for this taxonomy', 'cradle-content' )
			);
			echo '</p>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Field groups
	 * ------------------------------------------------------------------- */

	private static function field_group( array $d ) {
		self::section( __( 'Group', 'cradle-content' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text( 'key', $d['key'], __( 'Key', 'cradle-content' ), __( '1–40 lowercase letters, numbers or underscores.', 'cradle-content' ), array( 'class' => 'regular-text code', 'maxlength' => 40 ) );
		self::text( 'title', $d['title'], __( 'Title', 'cradle-content' ), __( 'Shown as the panel title in the editor.', 'cradle-content' ), array( 'maxlength' => 100 ) );
		self::textarea( 'description', $d['description'], __( 'Description', 'cradle-content' ), '', 2 );
		self::switches( __( 'Status', 'cradle-content' ), array( 'enabled' => array( __( 'Enabled', 'cradle-content' ), $d['enabled'] ) ), __( 'Disabling hides the fields; stored values are kept.', 'cradle-content' ) );
		self::select(
			'object',
			$d['object'],
			__( 'Attach to', 'cradle-content' ),
			array(
				'post' => __( 'Posts of post types', 'cradle-content' ),
				'term' => __( 'Terms of taxonomies', 'cradle-content' ),
			),
			'',
			array( 'data' => 'data-cradle-object' )
		);
		$targets = (array) ( $d['targets'] ?? array() );
		$post    = isset( $d['post_targets'] ) ? (array) $d['post_targets'] : ( 'post' === $d['object'] ? $targets : array() );
		$term    = isset( $d['term_targets'] ) ? (array) $d['term_targets'] : ( 'term' === $d['object'] ? $targets : array() );
		if ( isset( self::$errors['targets'] ) ) {
			self::$errors['post_targets'] = self::$errors['targets'];
			self::$errors['term_targets'] = self::$errors['targets'];
		}
		self::checkboxes( 'post_targets', $post, __( 'Post types', 'cradle-content' ), self::post_type_choices(), '', ' data-cradle-object-for="post"' );
		self::checkboxes( 'term_targets', $term, __( 'Taxonomies', 'cradle-content' ), self::taxonomy_choices(), '', ' data-cradle-object-for="term"' );
		self::select(
			'position',
			$d['position'],
			__( 'Classic meta box position', 'cradle-content' ),
			array(
				'normal' => __( 'Below the content', 'cradle-content' ),
				'side'   => __( 'Sidebar', 'cradle-content' ),
			),
			__( 'Fields not exposed to the REST API, and every field of post types without the block editor, are edited in a classic meta box.', 'cradle-content' )
		);
		echo '</tbody></table>';

		self::section( __( 'Fields', 'cradle-content' ), __( 'Each field is stored as its own native meta key (_cradle_cf_<key>). "Public" fields appear in public REST responses and can be shown with the "Cradle field" block binding; "Editor only" fields are editable in the block editor but hidden from public output; "Admin forms only" fields are not in the REST API at all.', 'cradle-content' ) );
		if ( isset( self::$errors['fields'] ) ) {
			echo '<p class="cradle-field-error">' . esc_html( self::$errors['fields'] ) . '</p>';
		}
		echo '<div class="cradle-content-repeater" data-cradle-repeater="fields"><ol class="cradle-content-rows cradle-content-fields" data-cradle-rows>';
		$index = 0;
		foreach ( (array) $d['fields'] as $field ) {
			if ( is_array( $field ) && ( '' !== (string) ( $field['key'] ?? '' ) || '' !== (string) ( $field['label'] ?? '' ) ) ) {
				self::field_row( (string) $index, $field, $index );
				++$index;
			}
		}
		self::field_row( (string) $index, array(), $index ); // Blank row for use without JavaScript.
		echo '</ol><template data-cradle-template>';
		self::field_row( '__i__', array(), -1 );
		echo '</template><button type="button" class="button" data-cradle-add>' . esc_html__( 'Add field', 'cradle-content' ) . '</button></div>';
	}

	/**
	 * One field definition row.
	 *
	 * @param string $i     Row index used in names.
	 * @param array  $f     Field (normalized definition or raw form input).
	 * @param int    $index Row position used for error lookup (-1 for the template).
	 */
	private static function field_row( string $i, array $f, int $index ) {
		$base   = 'def[fields][' . $i . ']';
		$id     = 'cradle-content-field-' . $i;
		$type   = (string) ( $f['type'] ?? 'text' );
		$errors = array();
		foreach ( self::$errors as $path => $message ) {
			if ( $index >= 0 && str_starts_with( $path, 'fields.' . $index . '.' ) ) {
				$errors[ substr( $path, strlen( 'fields.' . $index . '.' ) ) ] = $message;
			}
		}
		$err = static function ( string $prop ) use ( $errors, $id ) {
			return isset( $errors[ $prop ] ) ? '<span class="cradle-field-error" id="' . esc_attr( $id . '-' . $prop . '-error' ) . '">' . esc_html( $errors[ $prop ] ) . '</span>' : '';
		};
		$aria = static function ( string $prop ) use ( $errors, $id ) {
			return isset( $errors[ $prop ] ) ? ' aria-invalid="true" aria-describedby="' . esc_attr( $id . '-' . $prop . '-error' ) . '"' : '';
		};
		$input = static function ( string $prop, string $label, $value, string $input_type = 'text', string $extra = '' ) use ( $base, $id, $err, $aria ) {
			printf(
				'<p class="cradle-content-prop cradle-content-prop--%1$s"><label for="%2$s">%3$s</label><input type="%4$s" id="%2$s" name="%5$s" value="%6$s"%7$s%8$s />%9$s</p>',
				esc_attr( $prop ),
				esc_attr( $id . '-' . $prop ),
				esc_html( $label ),
				esc_attr( $input_type ),
				esc_attr( $base . '[' . $prop . ']' ),
				esc_attr( is_scalar( $value ) ? (string) $value : '' ),
				$extra, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attributes.
				$aria( $prop ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$err( $prop ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		};
		$check = static function ( string $prop, string $label, $value ) use ( $base, $id ) {
			printf(
				'<p class="cradle-content-prop cradle-content-prop--%1$s"><input type="hidden" name="%2$s" value="0" /><label for="%3$s"><input type="checkbox" id="%3$s" name="%2$s" value="1" %4$s /> %5$s</label></p>',
				esc_attr( $prop ),
				esc_attr( $base . '[' . $prop . ']' ),
				esc_attr( $id . '-' . $prop ),
				checked( true === Validator::read_bool( $value ?? false ), true, false ),
				esc_html( $label )
			);
		};
		$choices = static function ( string $prop, string $label, array $options, $values, string $types ) use ( $base, $id, $err ) {
			$values = array_map( 'strval', is_array( $values ) ? $values : array() );
			printf( '<fieldset class="cradle-content-prop cradle-content-prop--%1$s" data-types="%2$s" id="%3$s"><legend>%4$s</legend><input type="hidden" name="%5$s[]" value="" />', esc_attr( $prop ), esc_attr( $types ), esc_attr( $id . '-' . $prop ), esc_html( $label ), esc_attr( $base . '[' . $prop . ']' ) );
			foreach ( $options as $value => $option_label ) {
				printf( '<label class="cradle-content-choice"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>', esc_attr( $base . '[' . $prop . ']' ), esc_attr( (string) $value ), checked( in_array( (string) $value, $values, true ), true, false ), esc_html( $option_label ) );
			}
			echo $err( $prop ) . '</fieldset>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		};

		$targets = (array) ( $f['targets'] ?? array() );
		$options = $f['options'] ?? '';
		if ( is_array( $options ) ) {
			$lines = array();
			foreach ( $options as $option ) {
				if ( is_array( $option ) ) {
					$lines[] = $option['value'] . ( ( $option['label'] ?? '' ) !== $option['value'] ? '|' . $option['label'] : '' );
				}
			}
			$options = implode( "\n", $lines );
		}
		$legend = '' !== (string) ( $f['label'] ?? '' ) ? $f['label'] . ' (' . ( $f['key'] ?? '' ) . ')' : __( 'New field', 'cradle-content' );

		echo '<li class="cradle-content-row cradle-content-field" data-cradle-row><fieldset>';
		echo '<legend>' . esc_html( $legend ) . '</legend>';
		echo '<input type="hidden" name="' . esc_attr( $base . '[original_key]' ) . '" value="' . esc_attr( (string) ( $f['original_key'] ?? ( $f['key'] ?? '' ) ) ) . '" />';
		echo '<div class="cradle-content-field__grid">';
		$input( 'key', __( 'Key', 'cradle-content' ), $f['key'] ?? '', 'text', ' class="code" maxlength="40"' );
		$input( 'label', __( 'Label', 'cradle-content' ), $f['label'] ?? '', 'text', ' maxlength="100"' );
		printf( '<p class="cradle-content-prop"><label for="%1$s">%2$s</label><select id="%1$s" name="%3$s" data-cradle-type%4$s>', esc_attr( $id . '-type' ), esc_html__( 'Type', 'cradle-content' ), esc_attr( $base . '[type]' ), $aria( 'type' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( Field_Types::labels() as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $type, $value, false ), esc_html( $label ) );
		}
		echo '</select>' . $err( 'type' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<p class="cradle-content-prop"><label for="%1$s">%2$s</label><select id="%1$s" name="%3$s">', esc_attr( $id . '-exposure' ), esc_html__( 'Exposure', 'cradle-content' ), esc_attr( $base . '[exposure]' ) );
		foreach (
			array(
				'editor' => __( 'Editor only', 'cradle-content' ),
				'public' => __( 'Public', 'cradle-content' ),
				'none'   => __( 'Admin forms only', 'cradle-content' ),
			) as $value => $label
		) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( (string) ( $f['exposure'] ?? 'editor' ), $value, false ), esc_html( $label ) );
		}
		echo '</select></p>';
		$input( 'parent', __( 'Parent group key', 'cradle-content' ), $f['parent'] ?? '', 'text', ' class="code" maxlength="40"' );
		$input( 'help', __( 'Help text', 'cradle-content' ), $f['help'] ?? '', 'text', ' maxlength="300"' );
		echo '<div data-types="text textarea number boolean select date url email">';
		$input( 'default', __( 'Default value', 'cradle-content' ), is_bool( $f['default'] ?? null ) ? ( $f['default'] ? '1' : '0' ) : ( $f['default'] ?? '' ) );
		echo '</div>';
		$check( 'required', __( 'Required', 'cradle-content' ), $f['required'] ?? false );

		echo '<div data-types="text textarea rich_text">';
		$input( 'max_length', __( 'Maximum length', 'cradle-content' ), $f['max_length'] ?? '', 'number', ' min="1" class="small-text"' );
		echo '</div><div data-types="number">';
		$input( 'min', __( 'Minimum', 'cradle-content' ), $f['min'] ?? '', 'number', ' step="any" class="small-text"' );
		$input( 'max', __( 'Maximum', 'cradle-content' ), $f['max'] ?? '', 'number', ' step="any" class="small-text"' );
		$input( 'step', __( 'Step', 'cradle-content' ), $f['step'] ?? '', 'number', ' step="any" class="small-text"' );
		$check( 'integer', __( 'Whole numbers only', 'cradle-content' ), $f['integer'] ?? false );
		echo '</div>';
		printf(
			'<p class="cradle-content-prop cradle-content-prop--options" data-types="select multiselect"><label for="%1$s">%2$s</label><textarea id="%1$s" name="%3$s" rows="4" class="code"%4$s>%5$s</textarea><span class="description">%6$s</span>%7$s</p>',
			esc_attr( $id . '-options' ),
			esc_html__( 'Choices', 'cradle-content' ),
			esc_attr( $base . '[options]' ),
			$aria( 'options' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_textarea( (string) $options ),
			esc_html__( 'One per line: value|Label (value: letters, numbers, dot, dash, underscore).', 'cradle-content' ),
			$err( 'options' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		$choices(
			'mime',
			__( 'Allowed media types', 'cradle-content' ),
			array(
				'image/'          => __( 'Images', 'cradle-content' ),
				'video/'          => __( 'Video', 'cradle-content' ),
				'audio/'          => __( 'Audio', 'cradle-content' ),
				'application/pdf' => __( 'PDF', 'cradle-content' ),
				'text/'           => __( 'Text files', 'cradle-content' ),
			),
			$f['mime'] ?? array(),
			'attachment'
		);
		$choices( 'post_targets', __( 'Allowed post types', 'cradle-content' ), self::post_type_choices(), $f['post_targets'] ?? ( 'post_ref' === $type ? $targets : array() ), 'post_ref' );
		$choices( 'term_targets', __( 'Allowed taxonomies', 'cradle-content' ), self::taxonomy_choices(), $f['term_targets'] ?? ( 'term_ref' === $type ? $targets : array() ), 'term_ref' );
		$choices( 'role_targets', __( 'Allowed roles (none ticked = any role)', 'cradle-content' ), self::role_choices(), $f['role_targets'] ?? ( 'user_ref' === $type ? $targets : array() ), 'user_ref' );
		if ( isset( $errors['targets'] ) ) {
			echo '<p class="cradle-field-error">' . esc_html( $errors['targets'] ) . '</p>';
		}
		echo '<div data-types="post_ref user_ref term_ref">';
		$check( 'multiple', __( 'Allow several references', 'cradle-content' ), $f['multiple'] ?? false );
		echo '</div><div data-types="multiselect post_ref user_ref term_ref">';
		$input( 'max_items', __( 'Maximum selections', 'cradle-content' ), $f['max_items'] ?? '', 'number', ' min="1" max="50" class="small-text"' );
		echo '</div><div data-types="group">';
		$input( 'max_rows', __( 'Maximum rows', 'cradle-content' ), $f['max_rows'] ?? '', 'number', ' min="1" max="50" class="small-text"' );
		echo '<p class="description">' . esc_html__( 'Add sub-fields as separate fields whose "Parent group key" is this field\'s key. Sub-fields hold single values.', 'cradle-content' ) . '</p>';
		echo '</div>';
		echo '</div>';
		self::row_buttons();
		echo '</fieldset></li>';
	}
}
