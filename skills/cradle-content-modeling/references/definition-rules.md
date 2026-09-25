# Definition rules and registration patterns

## Keys

| Entity | Constraint | Notes |
| --- | --- | --- |
| Post type | `^[a-z0-9_-]{1,20}$` | Stored in `post_type`; renaming requires a data migration. |
| Taxonomy | `^[a-z0-9_-]{1,32}$` | Stored in `term_taxonomy.taxonomy`. |
| Meta key | Prefix per owner (`acme_`, `_acme_`); `_cradle_` reserved for Cradle internals | Leading `_` hides it from the classic custom-fields box. |
| REST base | Plural, lowercase | Must not collide with core routes. |

Reserved or unsafe post-type/taxonomy keys include WordPress core types (`post`, `page`, `attachment`,
`revision`, `nav_menu_item`, `custom_css`, `customize_changeset`, `oembed_cache`, `user_request`,
`wp_block`, `wp_template`, `wp_template_part`, `wp_global_styles`, `wp_navigation`, `wp_font_family`,
`wp_font_face`), core taxonomies (`category`, `post_tag`, `nav_menu`, `link_category`, `post_format`,
`wp_theme`, `wp_template_part_area`, `wp_pattern_category`), WooCommerce keys (`product`,
`product_variation`, `shop_order`, `shop_coupon`, `product_cat`, `product_tag`, `pa_*`), and public query
variables such as `author`, `year`, `name`, `order`, `s`, `page`, `type`, `term`, `action`, `attachment`,
`feed`. Check the running site: `get_post_types()`, `get_taxonomies()`, and `$wp->public_query_vars`.

## Post type in code (framework add-on packages only — websites use Cradle Content definitions)

```php
add_action( 'init', array( __CLASS__, 'register_types' ), 5 );

public static function register_types() {
	register_post_type( 'portfolio', array(
		'labels'          => array(
			'name'          => __( 'Portfolio', 'acme-site' ),
			'singular_name' => __( 'Portfolio item', 'acme-site' ),
			'add_new_item'  => __( 'Add portfolio item', 'acme-site' ),
			'edit_item'     => __( 'Edit portfolio item', 'acme-site' ),
			'menu_name'     => __( 'Portfolio', 'acme-site' ),
		),
		'public'          => true,
		'show_in_rest'    => true,           // Required for the block editor.
		'rest_base'       => 'portfolio',
		'has_archive'     => 'work',
		'rewrite'         => array( 'slug' => 'work', 'with_front' => false ),
		'menu_icon'       => 'dashicons-portfolio',
		'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ),
		'capability_type' => 'post',         // Or array( 'portfolio_item', 'portfolio_items' ) + map_meta_cap.
		'map_meta_cap'    => true,
		'template'        => array( array( 'core/paragraph', array( 'placeholder' => __( 'Project summary…', 'acme-site' ) ) ) ),
	) );

	register_taxonomy( 'portfolio_type', array( 'portfolio' ), array(
		'labels'            => array( 'name' => __( 'Project types', 'acme-site' ), 'singular_name' => __( 'Project type', 'acme-site' ) ),
		'hierarchical'      => true,
		'show_in_rest'      => true,
		'show_admin_column' => true,
		'rewrite'           => array( 'slug' => 'work-type' ),
	) );
}
```

`custom-fields` support is needed for meta to appear in REST for the post type when `show_in_rest` is set
on the meta.

## Meta

```php
register_post_meta( 'portfolio', 'acme_client_url', array(
	'type'              => 'string',
	'single'            => true,
	'default'           => '',
	'show_in_rest'      => array( 'schema' => array( 'type' => 'string', 'format' => 'uri', 'maxLength' => 2000 ) ),
	'sanitize_callback' => static fn( $value ) => esc_url_raw( (string) $value, array( 'http', 'https' ) ),
	'auth_callback'     => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
) );

register_post_meta( 'portfolio', 'acme_related_services', array(
	'type'              => 'array',
	'single'            => true,
	'default'           => array(),
	'show_in_rest'      => array( 'schema' => array( 'type' => 'array', 'maxItems' => 12, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ) ),
	'sanitize_callback' => static fn( $ids ) => array_slice( array_values( array_filter( array_map( 'absint', (array) $ids ), static fn( $id ) => 'service' === get_post_type( $id ) ) ), 0, 12 ),
	'auth_callback'     => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
) );
```

- Validate by type and allowed entity type, reject invalid values before normalizing.
- Private data: `show_in_rest => false` and a leading `_`.
- Relationship queries use `meta_query` on IDs or a taxonomy; bound them (`posts_per_page`, `no_found_rows`).

## Editor field UI (plain JS)

Use a `wp.editPost.PluginDocumentSettingPanel` (or `wp.editor` equivalent in the running version) with
`wp.coreData.useEntityProp( 'postType', postType, 'meta' )`; declare the `wp-*` dependencies; no JSX.

## Navigation entities

Menus are `wp_navigation` posts referenced by core Navigation blocks (`"ref": <id>`). Create them with
`wp_insert_post( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_content' => serialize_blocks( $links ) ) )`
only within the task's authorization, tag with `_cradle_source_key`, and record the ID.

## Migrations for key changes

1. Preview: count affected posts/terms/meta rows per key (`$wpdb->prepare` counts or `WP_Query` with
   `fields => 'ids'`), list templates and blocks that reference the old key.
2. Confirm with the administrator; create a pre-change checkpoint (Cradle Migration pre-change backup when
   available).
3. Migrate in bounded, idempotent batches (Cradle Jobs `register_type()`), using `wp_update_post`/
   `set_post_type()`/term APIs so hooks and caches run.
4. Register the new key, keep the old key readable until migration completes, flush rewrites once.
5. Record in `docs/decisions.md` and `docs/content-model.md`.

## Cradle Content ownership (northstar §12.4)

- DB-managed definitions: editable in wp-admin, exported/imported as JSON.
- Code-managed definitions (`cradle_content/code_definitions`): supplied by framework packages; shown read-only or forkable in admin. Websites use DB definitions with the JSON baseline in the child theme.
- Disabling a definition never deletes posts; the UI explains reactivation or conversion.
