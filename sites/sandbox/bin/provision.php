<?php
/**
 * Provisions the Harbor & Pine demo into a WordPress install (CLI only; demo tooling, not a plugin).
 *
 * Usage:
 *   php sites/sandbox/bin/provision.php --path=<wordpress root> [--dry-run] [--set-identity] [--replace=key1,key2] [--no-reading]
 *
 * Steps (runs as the first administrator, every step idempotent):
 * 1. Content model: imports the child theme's content/content-types.json into Cradle Content (Projects post type,
 *    Project types taxonomy, "Project facts" field group). Existing identical definitions are skipped;
 *    a definition an administrator changed is never overwritten.
 * 2. One-time conversion of projects created by the retired site plugin (`cradle_project` posts and
 *    `project_*` meta) to the Cradle Content model (`project`, `_cradle_cf_*`), keeping IDs, terms and images.
 * 3. Content: media, terms, pages, projects, posts and navigation from provisioning/definitions.php.
 *    Records are matched by source key; records an editor changed are reported as conflicts, not overwritten.
 *
 * @package Cradle Sandbox
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$opts = getopt( '', array( 'path:', 'dry-run', 'set-identity', 'replace::', 'no-reading' ) );
$path = rtrim( (string) ( $opts['path'] ?? '' ), '/\\' );
if ( '' === $path || ! is_file( $path . '/wp-load.php' ) ) {
	fwrite( STDERR, "--path must point to a WordPress root.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
define( 'WP_ADMIN', true );
require $path . '/wp-load.php';

$root = dirname( __DIR__ ) . '/provisioning/';
require_once $root . 'class-provisioner.php';

if ( ! function_exists( 'cradle' ) || ! cradle() || ! cradle()->modules()->is_ready( 'content' ) || ! class_exists( 'Cradle\\Content\\Transfer' ) ) {
	fwrite( STDERR, "Cradle core and Cradle Content must be active: the demo's Projects are a Cradle Content post type.\n" );
	exit( 1 );
}

$admins = get_users(
	array(
		'role'    => 'administrator',
		'orderby' => 'ID',
		'number'  => 1,
		'fields'  => 'ID',
	)
);
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
$dry_run = isset( $opts['dry-run'] );

// 1. Content model.
$payload = Cradle\Content\Transfer::decode( (string) file_get_contents( dirname( __DIR__ ) . '/themes/cradle-site/content/content-types.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$preview = is_wp_error( $payload ) ? $payload : Cradle\Content\Transfer::preview( $payload );
if ( is_wp_error( $preview ) ) {
	fwrite( STDERR, 'content-types.json: ' . $preview->get_error_message() . "\n" );
	exit( 1 );
}
foreach ( $preview['items'] as $item ) {
	if ( in_array( $item['status'], array( 'blocked', 'invalid' ), true ) ) {
		fwrite( STDERR, sprintf( "content model %s:%s is %s: %s\n", $item['kind'], $item['key'], $item['status'], implode( ' ', $item['errors'] ) ) );
		exit( 1 );
	}
	if ( 'update' === $item['status'] ) {
		printf( "content model %s:%s differs from the site (changed by an administrator); left unchanged\n", $item['kind'], $item['key'] );
	}
}
if ( ! $dry_run && $preview['counts']['new'] > 0 ) {
	$applied = Cradle\Content\Transfer::apply( $preview, array() );
	if ( $applied['failed'] ) {
		fwrite( STDERR, 'content model: ' . wp_json_encode( $applied['failed'] ) . "\n" );
		exit( 1 );
	}
	printf( "content model: created %s\n", implode( ', ', $applied['created'] ) );
	// New types register on the next request's `init`: continue in a fresh process.
	$args = array_map( 'escapeshellarg', array_slice( $argv, 1 ) );
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . implode( ' ', $args ), $code );
	exit( (int) $code );
} else {
	printf( "content model: %d new, %d identical\n", $preview['counts']['new'], $preview['counts']['identical'] );
}

// 2. Convert projects left by the retired site plugin.
global $wpdb;
$legacy = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'cradle_project' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
if ( $legacy && post_type_exists( 'project' ) && ! $dry_run ) {
	$fields = array(
		'project_client'   => '_cradle_cf_client',
		'project_location' => '_cradle_cf_location',
		'project_year'     => '_cradle_cf_year',
	);
	foreach ( $legacy as $post_id ) {
		$wpdb->update( $wpdb->posts, array( 'post_type' => 'project' ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $post_id );
		foreach ( $fields as $old => $new ) {
			$value = get_post_meta( $post_id, $old, true );
			if ( '' !== $value && '' === (string) get_post_meta( $post_id, $new, true ) ) {
				update_post_meta( $post_id, $new, $value );
			}
			delete_post_meta( $post_id, $old );
		}
	}
	$registry = get_option( 'cradle_site_provisioning', array() );
	foreach ( is_array( $registry ) ? $registry : array() as $key => $record ) {
		if ( 'cradle_project' === ( $record['type'] ?? '' ) ) {
			$registry[ $key ]['type'] = 'project';
		}
	}
	update_option( 'cradle_site_provisioning', $registry, false );
	printf( "converted %d project(s) from the retired site plugin\n", count( $legacy ) );
}

// 3. Content.
$definitions = require $root . 'definitions.php';
$provisioner = new Cradle\Sandbox\Provisioner(
	array(
		'dry_run'      => $dry_run,
		'set_identity' => isset( $opts['set-identity'] ),
		'set_reading'  => ! isset( $opts['no-reading'] ),
		'replace'      => array_filter( explode( ',', (string) ( $opts['replace'] ?? '' ) ) ),
	)
);
$report = $provisioner->run( $definitions );

foreach ( $report as $bucket => $items ) {
	printf( "%-10s %d%s\n", $bucket, count( $items ), $items && 'unchanged' !== $bucket ? ': ' . implode( ', ', $items ) : '' );
}

// 4. German and Arabic for the child theme's own strings, added to Cradle Translations' built-in
// example languages (only entries without a translation or draft are filled).
if ( ! $dry_run && class_exists( 'Cradle\\Translations\\Examples' ) && cradle()->modules()->is_ready( 'translations' ) ) {
	Cradle\Translations\Examples::maybe_install();
	Cradle\Translations\Service::refresh_sources( true ); // Makes sure the theme's strings are in the catalogs.
	foreach ( Cradle\Translations\Examples::LOCALES as $locale ) {
		$data = json_decode( (string) file_get_contents( $root . 'translations/' . $locale . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$rows = array();
		foreach ( (array) ( $data['entries'] ?? array() ) as $row ) {
			$rows[] = array(
				'domain'      => (string) $row['domain'],
				'msgid'       => (string) $row['msgid'],
				'context'     => isset( $row['context'] ) ? (string) $row['context'] : null,
				'msgidPlural' => isset( $row['msgidPlural'] ) ? (string) $row['msgidPlural'] : null,
				'forms'       => array_map( 'strval', (array) $row['forms'] ),
			);
		}
		$filled = Cradle\Translations\Examples::fill( $locale, $rows, 'site_translations' );
		printf( "translations %s: %s\n", $locale, is_wp_error( $filled ) ? $filled->get_error_message() : sprintf( '%d filled, %d kept, %d invalid', $filled['filled'], $filled['kept'], count( $filled['invalid'] ) ) );
	}
}
exit( $report['errors'] ? 1 : 0 );
