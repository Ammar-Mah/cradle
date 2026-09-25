<?php
/**
 * Public procedural API of the Cradle core plugin.
 *
 * Add-ons must guard calls with function_exists() or run them only after
 * the `cradle/ready` action, because core can be missing at runtime.
 *
 * @package Cradle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps Cradle\Core\Some_Class to src/class-some-class.php (and
 * Cradle\Core\Admin\Some_Class to admin/class-some-class.php).
 * Only these two known directories are consulted.
 *
 * @param string $class_name Requested class.
 */
function cradle_core_autoload( $class_name ) {
	$prefixes = array(
		'Cradle\\Core\\Admin\\' => CRADLE_DIR . 'admin/',
		'Cradle\\Core\\'        => CRADLE_DIR . 'src/',
	);
	foreach ( $prefixes as $prefix => $dir ) {
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			continue;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $relative ) ) {
			return;
		}
		$file = $dir . 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
		return;
	}
}

/**
 * Returns the booted core instance, or null before `plugins_loaded` completes.
 *
 * @return Cradle\Core\Plugin|null
 */
function cradle() {
	return Cradle\Core\Plugin::instance();
}

/**
 * Reads one field of a registered settings section, falling back to its default.
 * Reading never writes.
 *
 * @param string $section Section ID.
 * @param string $field   Field key.
 * @return mixed|null Null when the section or field is not registered.
 */
function cradle_get_setting( $section, $field ) {
	$core = cradle();
	return $core ? $core->settings()->get( $section, $field ) : null;
}

/**
 * Renders a registered component through its builder-neutral render service.
 *
 * @param string $component_id Component ID, for example `cradle/feature-card`.
 * @param array  $props        Raw properties; validated against the component schema.
 * @param array  $context      Optional context: adapter, post_id, inner_html.
 * @return string Escaped HTML, or an empty string when unavailable.
 */
function cradle_render_component( $component_id, array $props = array(), array $context = array() ) {
	$core = cradle();
	if ( ! $core ) {
		return '';
	}
	$context += array( 'adapter' => 'php' );
	return $core->components()->render( $component_id, $props, $context );
}

/**
 * Records a redacted activity entry for a privileged Cradle operation.
 *
 * @param string $module  Module ID.
 * @param string $action  Short machine action name.
 * @param string $message Human summary without secrets.
 * @param array  $context Small structured context; sensitive keys are redacted.
 */
function cradle_log_activity( $module, $action, $message, array $context = array() ) {
	$core = cradle();
	if ( $core ) {
		$core->activity()->record( $module, $action, $message, $context );
	}
}
