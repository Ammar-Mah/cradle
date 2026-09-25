# Cradle core API (v1.0.0)

Status: implemented in `packages/plugins/cradle` (core 0.1.0, API `1.0.0`).
This is the contract every Cradle add-on builds against. Changes that break it
require a new major API version and a migration note.

## 1. Boot sequence

| Step | When | What happens |
| --- | --- | --- |
| 1 | Add-on bootstrap file is included | Add-on defines constants, registers its autoloader, activation/deactivation hooks, and `add_action( 'cradle/register_modules', ... )`. It must not call core APIs yet. |
| 2 | `plugins_loaded` priority **5** | Core constructs its services and fires `cradle/register_modules` with the `Cradle\Core\Module_Registry`. |
| 3 | same | Registry validates descriptors; rejects duplicates, invalid contracts, incompatible `coreApiRange`, missing/failed dependencies and cycles. |
| 4 | same | Core fires `cradle/ready( Plugin $core, string $api_version )`. Services are callable; translations are **not** yet available. |
| 5 | `init` priority **0** | Core registers its capabilities and settings sections, registers module capabilities, then calls each **ready** module's `boot( Plugin $core, array $descriptor )` in dependency order, then fires `cradle/booted( Plugin $core )`. |

Rules:

- Descriptors must not call translation functions (`__()` etc.) — they are built before `init`. Use plain English `name`/labels there; register translated UI inside `boot`.
- Register post types, taxonomies, blocks, settings sections, components and job types **inside `boot`** (we are inside `init` priority 0, so `init` hooks at priority ≥ 1 you add there still run).
- A module that is not `ready` is never booted. Its plugin stays active and its data is untouched.
- Exception for request-level behavior that must exist before `init` (translation filters, request
  routing such as a custom login URL): attach it on `cradle/ready` after checking
  `$core->modules()->is_ready( '<id>' )`, and do not call translation functions there. Everything
  else (post types, blocks, settings sections, UI) still belongs in `boot`.
- Add-ons must work (no fatal, useful admin notice) when core is missing. See §9.

## 2. Module descriptor

```php
add_action( 'cradle/register_modules', function ( $registry ) {
	$registry->register( array(
		'id'               => 'events',               // ^[a-z][a-z0-9-]{1,40}$, stable forever
		'name'             => 'Cradle Events',        // plain string, no __()
		'description'      => 'Events and an upcoming-events block.',
		'version'          => CRADLE_EVENTS_VERSION,  // semver
		'coreApiRange'     => '^1.0',                 // "^x.y", "~x.y", ">=1.0 <2.0", exact
		'pluginFile'       => CRADLE_EVENTS_FILE,     // must be inside WP_PLUGIN_DIR
		'dependencies'     => array( 'blocks' => '^0.1' ), // or array( 'blocks' )
		'settingsSections' => array( 'events' ),
		'capabilities'     => array(
			'cradle_manage_events' => array( 'label' => 'Manage events', 'roles' => array( 'administrator', 'editor' ) ),
		),
		'schemaVersion'    => 1,
		'dataOwners'       => array(
			'options'    => array( 'cradle_events_settings' ),
			'postTypes'  => array( 'cradle_event' ),
			'postMeta'   => array( '_cradle_event_*' ),      // trailing * = prefix match
			'blocks'     => array( 'cradle/upcoming-events' ),
			// also: taxonomies, termMeta, userMeta, tables (without $wpdb->prefix), cron, uploads, transients, files
		),
		'boot'             => array( 'Cradle\\Events\\Module', 'boot' ),
		'export'           => array( 'Cradle\\Events\\Module', 'export' ),   // optional: fn(): array
		'import'           => array( 'Cradle\\Events\\Module', 'import' ),   // optional: fn(array $data): true|WP_Error
		'cleanup'          => array( 'Cradle\\Events\\Module', 'cleanup' ),  // optional: fn(array $scope): array report
		'adminPage'        => 'cradle-events',                             // optional admin page slug
	) );
} );
```

Unknown keys are rejected. Handlers must be callables defined in trusted PHP.
States reported: `installed` (inactive), `active`, `compatible`, `ready`, `disabled`, `failed` (+ problems).
Core also reads the plugin header `Cradle Module: <id>` to list installed-but-inactive add-ons —
**every add-on bootstrap must include that header.**

## 3. Service access

```php
$core = cradle();                     // Cradle\Core\Plugin|null
$core->modules();                     // Module_Registry: get($id), is_ready($id), state($id), all(), report()
$core->settings();                    // Settings
$core->capabilities();                // Capabilities
$core->components();                  // Components
$core->jobs();                        // Jobs
$core->locks();                       // Locks
$core->activity();                    // Activity
$core->storage();                     // Storage
$core->inventory();                   // Inventory
cradle_get_setting( 'events', 'show_past' );
cradle_render_component( 'cradle/feature-card', $props, $context );
cradle_log_activity( 'events', 'event_saved', 'Event 12 saved.', array( 'post' => 12 ) );
```

## 4. Settings sections

Register inside `boot`:

```php
$core->settings()->register_section( array(
	'id'            => 'events',                     // option defaults to cradle_events_settings
	'owner'         => 'events',
	'title'         => __( 'Event settings', 'cradle-events' ),
	'description'   => __( '...', 'cradle-events' ),
	'capability'    => 'cradle_manage_events',       // enforced by options.php via option_page_capability_*
	'schemaVersion' => 1,
	'page'          => true,                         // core adds a <menu_title> page rendering the form
	'menu_title'    => __( 'Settings', 'cradle-events' ),
	'menu_parent'   => 'edit.php?post_type=cradle_event', // optional; default 'cradle' (the Cradle menu)
	'autoload'      => true,
	'lifecycle'     => array( 'uninstall' => 'retain' ),
	'fields'        => array(
		'autoplay' => array( 'type' => 'boolean', 'default' => false, 'label' => __( 'Autoplay', 'cradle-events' ), 'help' => '...' ),
		'interval' => array( 'type' => 'integer', 'default' => 6000, 'minimum' => 3000, 'maximum' => 20000, 'step' => 500, 'label' => '...' ),
		'accent'   => array( 'type' => 'color', 'default' => '#1d4ed8', 'label' => '...' ),
		'height'   => array( 'type' => 'dimension', 'default' => array( 'value' => 70, 'unit' => 'vh' ), 'units' => array( 'px', 'vh', 'rem' ), 'minimum' => 0, 'maximum' => 2000, 'label' => '...' ),
		'logo'     => array( 'type' => 'attachment', 'default' => 0, 'mime' => array( 'image/' ), 'label' => '...' ),
		'mode'     => array( 'type' => 'enum', 'enum' => array( 'fade' => __( 'Fade', 'cradle-events' ), 'slide' => __( 'Slide', 'cradle-events' ) ), 'default' => 'fade', 'control' => 'radio' ),
		'services' => array( 'type' => 'multi_enum', 'enum' => array( 'a', 'b' ), 'default' => array() ),
		'site'     => array( 'type' => 'url', 'default' => '', 'protocols' => array( 'https' ) ),
		'email'    => array( 'type' => 'email', 'default' => '' ),
		'text'     => array( 'type' => 'string', 'maxLength' => 200, 'control' => 'textarea', 'pattern' => '/^.*$/u' ),
		'rows'     => array( 'type' => 'object', 'default' => array(), 'validate' => fn( $value, $all ) => /* array|WP_Error */, 'render' => fn( $name, $id, $value, $field, $describedby ) => /* echo control */ ),
	),
	'validate'      => fn( array $values, array $current ) => array( 'field' => 'message' ), // cross-field, optional
	'migrate'       => fn( array $stored, int $from, int $to ) => array( /* new values */ ), // optional
) );
```

Behavior:

- One registered option per section (`register_setting`, `show_in_rest` false). Unknown keys are dropped; stored shape is the declared fields plus `_revision` and `_schema`.
- Invalid fields keep their previous value and show a field-specific error; other valid fields save.
- Stale edits are rejected using `_revision` (whole submission refused, nothing lost silently).
- Reset-to-defaults, JSON export (non-sensitive fields: `'sensitive' => true` or `exposure.export = false` excludes) and JSON import are built into every rendered section.
- Reading (`values()`, `get()`) never writes. Programmatic writes: `$core->settings()->update( 'events', array(...) )` (validates, checks capability unless `false` is passed as 3rd arg).
- Custom admin pages can embed a section with `$core->settings()->render_form( 'events' )`.
- Helpers: `Settings::validate_field( $field, $value )`, `Settings::css_dimension( $value )`, `Settings::read_uploaded_json( $field, $max_bytes )`.

## 5. Capabilities

Declare in the descriptor (`capabilities`). Core grants each capability once to its default roles
(`administrator` unless `roles` is given) on the first admin/CLI request after the module becomes ready,
and remembers the grant so an administrator's later removal is respected.
Activation hooks may also call `Cradle\Core\Capabilities::grant( $cap, $roles, $owner )` directly when core is loaded; it records the grant so later administrator removals are respected.
Uninstall purge: `Cradle\Core\Capabilities::revoke_everywhere( $caps )` (or equivalent code in uninstall.php, which runs without core).
Never grant high-impact capabilities to editors/shop managers by default. Use object capabilities (`edit_post`, `read_post`) for per-entity checks.

## 6. Components (builder-neutral)

```php
$core->components()->register_from_file( __DIR__ . '/components/feature-card/component.json', array(
	'render'   => array( Feature_Card::class, 'render' ),  // fn( array $props, array $context, array $component ): string (escaped HTML)
	'prepare'  => null,                                   // optional fn( array $props, array $context ): array
	'blockDir' => __DIR__ . '/blocks/feature-card',        // consumed by the Gutenberg adapter (cradle-blocks)
) );
```

Descriptor format (`schemaVersion` 1) — see `docs/architecture/components.md` for the full schema.
`render()` receives normalized properties (types coerced, unknown keys dropped, entity references
validated — deleted attachments/posts become 0) and a plain context array:
`adapter` (`gutenberg` | `shortcode` | `php`), `post_id`, `inner_html` (rendered slot/InnerBlocks HTML),
`instance_id` (unique per page), `entity_key` (optional, for cycle protection).
Render functions must not require `WP_Block`. Depth is limited to 8 nested renders.
Child override: `<child>/cradle/components/<slug>/render.php` with header `Render contract: <renderContract>`;
a mismatched contract is ignored (the default renderer is used) and reported in the status report (Cradle → Overview → Generate status report).

Editor adapters implement `Cradle\Core\Editor_Adapter` and call `$core->components()->register_adapter( $adapter )`.

## 7. Jobs and locks

```php
$core->jobs()->register_type( 'migration/export', array(
	'capability'    => 'cradle_export_site',
	'step'          => fn( array $job, Cradle\Core\Jobs $jobs ) => array( 'progress_done' => 10, 'cursor_data' => array(...), 'state' => 'running' ) /* or WP_Error */,
	'max_retries'   => 3,
	'lease_seconds' => 60,
	'time_budget'   => 10,   // seconds of steps per runner call
) );
$job = $core->jobs()->create( 'migration/export', $payload, array( 'total' => 100 ) ); // checks capability
$core->jobs()->run( $job['uuid'] );     // claims atomically, runs bounded steps, commits with fencing
```

- A step returns the columns to commit (`state`, `phase`, `cursor_data`, `completed_units`, `progress_*`, `bytes_*`, `payload`, `manifest_digest`) or a `WP_Error` (retried up to `max_retries`; `WP_Error` with data `'fatal'` fails immediately).
- Commits are rejected once the lease was taken over (fencing). Steps must be idempotent.
- Browser runner: `admin-ajax.php?action=cradle_job_step|cradle_job_status|cradle_job_cancel` with nonce `cradle_jobs` (localized as `cradleAdmin.jobNonce` on screens that enqueue `cradle-admin`); object-level access = job capability + owner (or `manage_options`).
  Markup `<div data-cradle-job="UUID"><progress class="cradle-progress"></progress><p aria-live="polite"></p></div>` is driven automatically by `cradle-admin` JS, which dispatches `cradle:job` events.
- Cancellation fires `do_action( 'cradle/job_cancelled', $job )` after the fencing counter advances (persist reports/release resources there).
- Locks: `$handle = $core->locks()->acquire( 'migration:import', 120 )` → `null` when held; `renew()`, `holds()`, `release()`; name locks with your module prefix.
- Assets: `Cradle\Core\Assets::register_script( $handle, $file, $rel, $deps, $version, $in_footer = true, $strategy = 'defer' )` — pass `''` for a blocking script that other blocking scripts depend on.

Tables: `{prefix}cradle_jobs`, `{prefix}cradle_locks`, `{prefix}cradle_activity` (see data-model.md).

## 8. Storage, activity, inventory

- `$core->storage()->module_dir( 'migration', 'archives' )` → absolute private directory (outside web root when `CRADLE_PRIVATE_DIR` is defined; otherwise under uploads with deny rules). Call `$core->storage()->is_protected()` before storing secrets/archives; it runs a real HTTP probe (cached 1 day).
- `Cradle\Core\Storage::path_within( $path, $root )` — canonical containment including symlinked parents.
- `cradle_log_activity( $module, $action, $message, $context )` — context keys matching secrets/emails are redacted.
- `$core->inventory()->scan( $module )` — counts of declared owned data; `count_block_usage( 'cradle/x' )`. There is no admin screen for it; use it in tooling, tests and removal procedures.
- Activity retention is fixed at 90 days / newest 5000 rows (daily prune). Developers may change it with the filters `cradle/activity_retention_days` and `cradle/activity_max_rows`; there is no settings screen.

## 9. Add-on bootstrap template (runtime guards)

```php
<?php
/**
 * Plugin Name:       Cradle Events
 * Description:       ...
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Requires Plugins:  cradle
 * Author:            Cradle
 * License:           GPL-2.0-or-later
 * Text Domain:       cradle-events
 * Domain Path:       /languages
 * Update URI:        false
 * Cradle Module:     events
 */
defined( 'ABSPATH' ) || exit;

define( 'CRADLE_EVENTS_VERSION', '0.1.0' );
define( 'CRADLE_EVENTS_FILE', __FILE__ );
define( 'CRADLE_EVENTS_DIR', __DIR__ . '/' );

spl_autoload_register( static function ( $class ) {   // explicit namespace → src/ or admin/ only
	$map = array( 'Cradle\\Events\\Admin\\' => CRADLE_EVENTS_DIR . 'admin/', 'Cradle\\Events\\' => CRADLE_EVENTS_DIR . 'src/' );
	foreach ( $map as $prefix => $dir ) {
		if ( 0 === strpos( $class, $prefix ) ) {
			$rel = substr( $class, strlen( $prefix ) );
			if ( preg_match( '/^[A-Za-z0-9_]+$/', $rel ) ) {
				$file = $dir . 'class-' . strtolower( str_replace( '_', '-', $rel ) ) . '.php';
				if ( is_file( $file ) ) { require_once $file; }
			}
			return;
		}
	}
} );

register_activation_hook( __FILE__, array( 'Cradle\\Events\\Lifecycle', 'activate' ) );     // guards multisite, grants caps, creates defaults
register_deactivation_hook( __FILE__, array( 'Cradle\\Events\\Lifecycle', 'deactivate' ) ); // unschedule, release locks, flush rewrites; keep data

add_action( 'cradle/register_modules', array( 'Cradle\\Events\\Module', 'register' ) );

// Runtime guard: core can disappear (FTP delete, failed update, CLI).
add_action( 'plugins_loaded', static function () {
	if ( ! function_exists( 'cradle' ) || ! cradle() ) {
		add_action( 'admin_notices', static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Cradle Events needs the Cradle core plugin. Its features are paused; no data was changed.', 'cradle-events' ) . '</p></div>';
			}
		} );
	}
}, 20 );
```

In `Module::boot()` also check `version_compare( $core->api_version(), '1.0.0', '>=' )` (redundant with the range, but explicit).
Activation hooks must call `Cradle\Core\Lifecycle::guard_multisite( $network_wide, 'Cradle Events' )` when core is loaded,
or perform the same check inline when it is not.

## 10. Uninstall

`uninstall.php` runs **without** the plugin or core loaded. Retain by default. Purge only when
`get_option( 'cradle_uninstall_policy' )[ '<module-id>' ] === 'purge'` (set in the “When deleted” column of Cradle → Overview; `delete_plugins` required).
Purge only data listed in `dataOwners`, using WordPress deletion APIs for posts/terms/attachments.
