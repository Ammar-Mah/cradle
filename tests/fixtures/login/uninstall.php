<?php
/**
 * Separate-process fixture that runs cradle-login/uninstall.php exactly as WordPress
 * would (WP_UNINSTALL_PLUGIN defined, plugin code not required).
 *
 * Shared state is protected: the purge policy is applied in memory only (option_ filter),
 * and writes to the shared user_roles and cradle_granted_capabilities options are
 * intercepted (pre_update_option_ filters return the old value) while the attempted values
 * are captured for the report. Only Cradle Login's own options and transients are really
 * deleted in purge mode; the calling test recreates the default option afterwards.
 *
 * Usage: php uninstall.php <retain|purge>
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$cradle_login_mode = $argv[1] ?? 'retain';

define( 'WP_UNINSTALL_PLUGIN', 'cradle-login/cradle-login.php' );

$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['SERVER_PORT']     = 80;
$_SERVER['REQUEST_URI']     = '/cradle/wp/wp-admin/plugins.php';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

require dirname( __DIR__, 3 ) . '/wp/wp-load.php';

global $wpdb;
$cradle_login_attempts = array();

add_filter(
	'option_cradle_uninstall_policy',
	static function ( $value ) use ( $cradle_login_mode ) {
		$value          = is_array( $value ) ? $value : array();
		$value['login'] = 'purge' === $cradle_login_mode ? 'purge' : 'retain';
		return $value;
	}
);
foreach ( array( $wpdb->prefix . 'user_roles', 'cradle_granted_capabilities' ) as $cradle_login_shared ) {
	add_filter(
		'pre_update_option_' . $cradle_login_shared,
		static function ( $value, $old ) use ( &$cradle_login_attempts, $cradle_login_shared ) {
			$cradle_login_attempts[ $cradle_login_shared ] = $value;
			return $old;
		},
		PHP_INT_MAX,
		2
	);
}

set_transient( 'cradle_login_probe_uninstallfixture', array( 'slug' => 'x', 'hash' => 'y' ), 300 );

$cradle_login_count = static function ( string $like ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
};
$cradle_login_before = array(
	'settings'   => $cradle_login_count( 'cradle_login_settings' ),
	'transients' => $cradle_login_count( $wpdb->esc_like( '_transient_cradle_login_' ) . '%' ),
);

include WP_PLUGIN_DIR . '/cradle-login/uninstall.php';

$cradle_login_roles   = $cradle_login_attempts[ $wpdb->prefix . 'user_roles' ] ?? null;
$cradle_login_granted = $cradle_login_attempts['cradle_granted_capabilities'] ?? null;
$cradle_login_out     = array(
	'mode'                      => $cradle_login_mode,
	'before'                    => $cradle_login_before,
	'settings_after'            => $cradle_login_count( 'cradle_login_settings' ),
	'health_after'              => $cradle_login_count( 'cradle_login_health' ),
	'transients_after'          => $cradle_login_count( $wpdb->esc_like( '_transient_cradle_login_' ) . '%' ),
	'roles_write_attempted'     => is_array( $cradle_login_roles ),
	'admin_cap_in_attempt'      => is_array( $cradle_login_roles ) ? ! empty( $cradle_login_roles['administrator']['capabilities']['cradle_manage_login'] ) : null,
	'granted_write_attempted'   => is_array( $cradle_login_granted ),
	'granted_entry_in_attempt'  => is_array( $cradle_login_granted ) ? isset( $cradle_login_granted['cradle_manage_login'] ) : null,
);
delete_transient( 'cradle_login_probe_uninstallfixture' );
echo 'CRADLE_LOGIN_JSON:' . wp_json_encode( $cradle_login_out ) . "\n";
