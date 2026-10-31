<?php
/**
 * Polylang-shaped fixture data for tests/integration/languages.php, inserted with plain SQL the way
 * Polylang stores it (Polylang itself is never installed in the sandbox):
 * - taxonomy `language`: term slug = language code, term_group = order, description = serialized
 *   { locale, rtl, flag_code }; entries are related to their language term;
 * - taxonomy `term_language`: `pll_<code>` terms; terms are related through object_id = term ID;
 * - taxonomies `post_translations` / `term_translations`: description = serialized { code: ID };
 * - option `polylang`.
 * Every row inserted here is recorded and removed by cl_pll_cleanup().
 */

$GLOBALS['cl_pll_rows'] = array(
	'tt'    => array(),
	'terms' => array(),
);

function cl_pll_term( string $taxonomy, string $name, string $slug, string $description = '', int $group = 0 ): int {
	global $wpdb;
	$wpdb->insert(
		$wpdb->terms,
		array(
			'name'       => $name,
			'slug'       => $slug,
			'term_group' => $group,
		)
	);
	$term_id = (int) $wpdb->insert_id;
	$wpdb->insert(
		$wpdb->term_taxonomy,
		array(
			'term_id'     => $term_id,
			'taxonomy'    => $taxonomy,
			'description' => $description,
			'parent'      => 0,
			'count'       => 0,
		)
	);
	$tt_id                             = (int) $wpdb->insert_id;
	$GLOBALS['cl_pll_rows']['terms'][] = $term_id;
	$GLOBALS['cl_pll_rows']['tt'][]    = $tt_id;
	return $tt_id;
}

function cl_pll_relate( int $object_id, int $tt_id ) {
	global $wpdb;
	$wpdb->insert(
		$wpdb->term_relationships,
		array(
			'object_id'        => $object_id,
			'term_taxonomy_id' => $tt_id,
			'term_order'       => 0,
		)
	);
}

/**
 * Builds a two-language Polylang site over existing entries and terms.
 *
 * @param array $posts { en: [ids], ar: [ids], groups: [ [en => id, ar => id] ] }
 * @param array $terms { en: [ids], ar: [ids], groups: [ [en => id, ar => id] ] }
 */
function cl_pll_install( array $posts, array $terms ) {
	// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Polylang's storage format.
	$en  = cl_pll_term( 'language', 'English', 'en', serialize( array( 'locale' => 'en_US', 'rtl' => 0, 'flag_code' => 'us' ) ), 0 );
	$ar  = cl_pll_term( 'language', 'Arabic', 'ar', serialize( array( 'locale' => 'ar', 'rtl' => 1, 'flag_code' => 'arab' ) ), 1 );
	$ten = cl_pll_term( 'term_language', 'English', 'pll_en' );
	$tar = cl_pll_term( 'term_language', 'Arabic', 'pll_ar' );
	foreach ( $posts['en'] as $id ) {
		cl_pll_relate( $id, $en );
	}
	foreach ( $posts['ar'] as $id ) {
		cl_pll_relate( $id, $ar );
	}
	foreach ( $posts['groups'] as $i => $group ) {
		$tt = cl_pll_term( 'post_translations', 'pll_test_post_' . $i, 'pll_test_post_' . $i . '_' . wp_generate_password( 6, false, false ), serialize( $group ) );
		foreach ( $group as $id ) {
			cl_pll_relate( $id, $tt );
		}
	}
	foreach ( $terms['en'] as $id ) {
		cl_pll_relate( $id, $ten );
	}
	foreach ( $terms['ar'] as $id ) {
		cl_pll_relate( $id, $tar );
	}
	foreach ( $terms['groups'] as $i => $group ) {
		$tt = cl_pll_term( 'term_translations', 'pll_test_term_' . $i, 'pll_test_term_' . $i . '_' . wp_generate_password( 6, false, false ), serialize( $group ) );
		foreach ( $group as $id ) {
			cl_pll_relate( $id, $tt );
		}
	}
	// phpcs:enable
	update_option(
		'polylang',
		array(
			'default_lang'  => 'en',
			'hide_default'  => 1,
			'force_lang'    => 1,
			'rewrite'       => 1,
			'post_types'    => array(),
			'taxonomies'    => array(),
			'media_support' => 0,
			'nav_menus'     => array(),
			'version'       => '3.6',
		),
		false
	);
}

function cl_pll_cleanup() {
	global $wpdb;
	foreach ( $GLOBALS['cl_pll_rows']['tt'] as $tt ) {
		$wpdb->delete( $wpdb->term_relationships, array( 'term_taxonomy_id' => $tt ) );
		$wpdb->delete( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => $tt ) );
	}
	foreach ( $GLOBALS['cl_pll_rows']['terms'] as $term_id ) {
		$wpdb->delete( $wpdb->terms, array( 'term_id' => $term_id ) );
	}
	$GLOBALS['cl_pll_rows'] = array(
		'tt'    => array(),
		'terms' => array(),
	);
	delete_option( 'polylang' );
	wp_cache_flush();
}
