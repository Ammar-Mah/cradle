# Cradle Content — code-managed definitions

Another Cradle plugin (a framework add-on) can ship definitions in version control. They use exactly the
export schema (see `data-model.md`) and are passed through the same validator. Websites do not use this:
they have no site plugin, define their types under Content types and keep the exported baseline in the
child theme's `content/content-types.json`.

```php
add_filter(
	'cradle_content/code_definitions',
	static function ( array $definitions ): array {
		$definitions['post_type']['service'] = array(
			'singular'     => 'Service',
			'plural'       => 'Services',
			'icon'         => 'hammer',
			'has_archive'  => true,
			'rewrite_slug' => 'services',
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
			'owner'        => 'cradle-site', // Shown in admin.
		);
		$definitions['field_group']['service_details'] = array(
			'title'   => 'Service details',
			'object'  => 'post',
			'targets' => array( 'service' ),
			'fields'  => array(
				array( 'key' => 'price_from', 'label' => 'Price from', 'type' => 'number', 'min' => 0, 'exposure' => 'public' ),
			),
		);
		return $definitions;
	}
);
```

Add the filter at plugin load time (it is read during `init` priority 0).

Rules:

- One owner per key. A code definition is always authoritative for its key; admin shows it read-only with its owner.
- "Fork to database" copies it: with the **same key** the copy is stored but shadowed (inactive) until the code definition is
  removed, at which point the database copy takes over without downtime; with a **new key** the copy is independent
  (URL slug, archive slug, REST base and custom capability names are reset).
- Imports cannot overwrite code-managed keys ("blocked"); saving a new database definition with a code-managed key is refused.
- Renaming a code key is done in code. Posts are not migrated automatically in that case — rename via a database fork first,
  or move posts with a migration.
- Invalid code definitions are listed on the relevant tab ("Code definitions rejected by validation") and not registered.
- Custom capabilities of code definitions are granted on the next admin request (recorded like database ones).

Post types registered directly with `register_post_type()` by other plugins are treated as owned by other code:
Cradle Content never registers or overwrites them and lists them read-only on the Post types tab.
