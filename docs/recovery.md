# Recovery guide

Nothing here requires editing the database. Keep a current backup before any change.

## Locked out after changing the login URL

1. Add `define( 'CRADLE_DISABLE_CUSTOM_LOGIN', true );` to `wp-config.php` (server access required).
2. Log in at the normal `wp-login.php`, fix or disable the route under Cradle → Login, then remove the constant.
3. Alternative: rename `wp-content/plugins/cradle-login` (disables only that add-on; settings are kept).

## Admin appearance unusable

Add `define( 'CRADLE_ADMIN_SAFE_MODE', true );` to `wp-config.php`, reset the offending setting under
Cradle → Admin, then remove the constant. Renaming `wp-content/plugins/cradle-admin` also restores native admin.

## A Cradle module shows "failed"

Cradle → Overview and Site Health list the reason (missing dependency, incompatible core API, boot error).
The failing module is simply not booted; its data is untouched. Install/activate the dependency or the
compatible version and reload.

## Core plugin missing or broken

Add-ons detect the missing core, show a notice to administrators and pause; content and data remain.
Reinstall the core ZIP (same or newer version). Dynamic component blocks (for example post lists) render
nothing while their plugin is inactive; blocks with saved fallback markup keep rendering it.

## Interrupted migration restore

Follow `packages/plugins/cradle-migration/docs/recovery-guide.md` (also shipped inside the plugin).

## Rolling back a package update

Reinstall the previous ZIP. A code rollback does not reverse data migrations; check the package's
changelog for migration notes before rolling back across schema versions.

## Removing Cradle

Find stored component usage first (`$core->components()->find_usages( $id )`; dynamic blocks such as the
Hero slider (Cradle Blocks) and social blocks render nothing once their plugin is gone). Export what you need, then choose
for each module in the “When deleted” column of Cradle → Overview whether deleting the plugin keeps its data (the
default) or removes it, before deleting plugins. Developers can produce static HTML for a stored instance
with `Gutenberg_Adapter::export_or_convert_instance()` (API only, see packages/plugins/cradle-blocks/docs/blocks.md).
