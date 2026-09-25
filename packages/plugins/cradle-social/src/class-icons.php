<?php
/**
 * Small reviewed inline SVG icon set.
 *
 * These are ORIGINAL, deliberately generic monochrome stroke glyphs written for
 * Cradle: neutral letter-marks (f, x, in, b, m, r, p) and generic pictograms
 * (camera, play button, speech bubble, paper plane, envelope, chain link,
 * share nodes, globe). They are not copies of any provider's logo or brand
 * artwork and are not taken from a third-party icon library.
 *
 * The markup is static PHP data (no user input, no uploads, no remote files).
 * Only <path>, <circle> and <rect> with geometry attributes are used; the
 * outer <svg> is decorative (aria-hidden, focusable=false) because every link
 * carries a text label. Color follows `currentColor`.
 *
 * @package Cradle\Social
 */

namespace Cradle\Social;

defined( 'ABSPATH' ) || exit;

final class Icons {

	/** Inner SVG elements on a 24×24 grid, stroke-based. */
	const GLYPHS = array(
		// Letter-mark "f".
		'facebook'  => '<path d="M15 4.5h-1.5A3.5 3.5 0 0 0 10 8v12"/><path d="M7 11h7"/>',
		// Letter-mark "x" (two crossing strokes).
		'x'         => '<path d="M6 5l12 14"/><path d="M18 5L6 19"/>',
		// Letter-mark "in".
		'linkedin'  => '<path d="M6 10v9"/><path d="M6 6v.01"/><path d="M10.5 19v-9"/><path d="M10.5 14a3.5 3.5 0 0 1 7 0v5"/>',
		// Letter-mark "b".
		'bluesky'   => '<path d="M8 4v16"/><circle cx="12.5" cy="15.5" r="4.5"/>',
		// Letter-mark "m".
		'mastodon'  => '<path d="M5 18v-8"/><path d="M5 12a3 3 0 0 1 6 0v6"/><path d="M11 12a3 3 0 0 1 6 0v6"/>',
		// Generic camera.
		'instagram' => '<path d="M4 8h3.5L9 5.5h6L16.5 8H20v11H4z"/><circle cx="12" cy="13.5" r="3.5"/>',
		// Generic play symbol in a circle.
		'youtube'   => '<circle cx="12" cy="12" r="9"/><path d="M10 8.5v7l6-3.5z"/>',
		// Generic speech bubble.
		'whatsapp'  => '<path d="M5.5 16.5A8 8 0 1 1 8 19l-4 1z"/>',
		// Generic paper plane ("send").
		'telegram'  => '<path d="M20.5 4L3.5 11l6 2.5 2.5 6.5 3-5 5.5-11z"/><path d="M9.5 13.5l5-4"/>',
		// Letter-mark "r".
		'reddit'    => '<path d="M8 19v-9"/><path d="M8 13.5A3.5 3.5 0 0 1 11.5 10H14"/>',
		// Letter-mark "p".
		'pinterest' => '<path d="M8 20V8"/><circle cx="12" cy="12" r="4"/>',
		// Generic envelope.
		'email'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5l8.5 6.5 8.5-6.5"/>',
		// Generic chain link (copy link).
		'link'      => '<path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1.5 1.5"/><path d="M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1.5-1.5"/>',
		// Generic share nodes (Web Share).
		'share'     => '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="M8.2 10.8l7.6-4.4"/><path d="M8.2 13.2l7.6 4.4"/>',
		// Generic globe (fallback for trusted filtered providers without a bundled icon).
		'generic'   => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/>',
	);

	public static function has( string $key ): bool {
		return isset( self::GLYPHS[ $key ] );
	}

	/**
	 * Decorative inline SVG for an icon key (falls back to the generic globe).
	 */
	public static function svg( string $key ): string {
		$inner = self::GLYPHS[ $key ] ?? self::GLYPHS['generic'];
		return '<svg class="cradle-social__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
	}

	/**
	 * Allowed SVG vocabulary, used by tests and reviewers to prove the set stays inert.
	 */
	public static function allowed_html(): array {
		$geometry = array(
			'd'  => true,
			'cx' => true,
			'cy' => true,
			'r'  => true,
			'x'  => true,
			'y'  => true,
		);
		return array(
			'svg'    => array(
				'class'           => true,
				'xmlns'           => true,
				'viewbox'         => true,
				'width'           => true,
				'height'          => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'aria-hidden'     => true,
				'focusable'       => true,
			),
			'path'   => array( 'd' => true ),
			'circle' => array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			),
			'rect'   => $geometry + array(
				'width'  => true,
				'height' => true,
				'rx'     => true,
			),
		);
	}
}
