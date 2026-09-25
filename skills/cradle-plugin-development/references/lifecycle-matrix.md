# Lifecycle matrix

Source: northstar §22.3, `docs/architecture/core-api.md` §1, §5, §7, §10.

| Event | Must | Must not | How to verify |
| --- | --- | --- | --- |
| Include (bootstrap) | Define constants, autoloader, activation/deactivation hooks, `cradle/register_modules` callback, core-missing guard. | Call core services, register content, query the DB, read files recursively. | Load any page with the add-on active and core absent (disposable target): notice only, no fatal. |
| Module discovery (`plugins_loaded` p5) | Register a valid descriptor. | Run migrations, long work, translations (`__()`), output. | Cradle → Overview shows `ready` or `failed` with reasons. |
| `boot` (`init` p0) | Attach hooks; register post types, taxonomies, blocks, settings sections, components, job types. | Grant capabilities per request, flush rewrites, heavy queries. | Module booted once per request (`did_action( 'cradle/booted' )`). |
| Activation | `Lifecycle::guard_multisite( $network_wide, 'Cradle X' )` when core is loaded (inline check otherwise); create defaults/schema idempotently (`dbDelta`); grant caps (`Capabilities::grant`) under policy; flush rewrites if routes added. | Generate content, pages, menus or demo data; overwrite existing settings; network-activate. | Activate twice → same options/tables, no duplicates. |
| Deactivation | Unschedule own cron (`wp_clear_scheduled_hook`), release own locks, flush rewrites if routes were added, remove temporary runtime behavior. | Delete options, posts, meta, tables, files, capabilities. | Deactivate → data still present; reactivate → works. |
| Update | Compare stored `schemaVersion`/installed version; run versioned, idempotent migrations on admin/CLI requests or as a job; keep old data readable. | Reset user configuration; run irreversible schema changes on a frontend request. | Seed old-version fixture, update, run migration twice, compare. |
| Uninstall (`uninstall.php`) | Run without core; `defined( 'WP_UNINSTALL_PLUGIN' ) || exit;`; retain by default; purge only when `cradle_uninstall_policy[<id>] === 'purge'`; purge only `dataOwners` using WordPress deletion APIs; revoke only own caps. | Treat deactivation as uninstall; delete shared media or other modules' data; follow paths from options without containment checks. | Disposable target: retain case keeps data discoverable in inventory; purge case removes exactly the declared owners. |
| Theme switch | Nothing (plugins are theme-independent). | Delete or hide content. | Content still in wp-admin after switching (disposable target). |

## Idempotent migration sketch

```php
public static function maybe_upgrade() {
	$stored = (int) get_option( 'cradle_faq_schema', 0 );
	if ( $stored >= self::SCHEMA ) {
		return;
	}
	if ( ! ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) {
		return; // Never on a frontend request.
	}
	$lock = cradle()->locks()->acquire( 'faq:migrate', 120 );
	if ( ! $lock ) {
		return;
	}
	if ( $stored < 2 ) {
		// Additive, repeatable step: copy old option shape into the new one if not already present.
	}
	update_option( 'cradle_faq_schema', self::SCHEMA, false );
	cradle()->locks()->release( 'faq:migrate', $lock );
}
```

Prefer additive changes and staged removal. A code rollback does not reverse a data migration; record
recovery steps for incompatible changes in the package `docs/`.

## Uninstall sketch

```php
<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$policy = get_option( 'cradle_uninstall_policy', array() );
if ( ! is_array( $policy ) || 'purge' !== ( $policy['faq'] ?? 'retain' ) ) {
	return; // Retain by default.
}
delete_option( 'cradle_faq_settings' );
foreach ( get_posts( array( 'post_type' => 'cradle_faq', 'post_status' => 'any', 'numberposts' => 200, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true ); // Bounded batches; re-run safe.
}
```
