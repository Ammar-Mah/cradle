<?php
/**
 * Local placeholder images drawn with PHP GD (no downloads, no third-party media).
 *
 * @package Cradle\Dummy_Content
 */

namespace Cradle\Dummy_Content;

defined( 'ABSPATH' ) || exit;

final class Images {

	const WIDTH  = 960;
	const HEIGHT = 540;

	const PALETTE = array(
		array( 29, 78, 216 ),
		array( 5, 150, 105 ),
		array( 217, 119, 6 ),
		array( 190, 24, 93 ),
		array( 79, 70, 229 ),
		array( 15, 118, 110 ),
		array( 71, 85, 105 ),
		array( 180, 83, 9 ),
	);

	/**
	 * Creates one owned placeholder attachment for a batch.
	 *
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function create( array $batch, int $k, int $author ) {
		if ( ! Request::gd_available() ) {
			return new \WP_Error( 'cradle_dummy_gd', 'PHP GD is not available.', 'fatal' );
		}
		$config = $batch['config'];
		$r      = new Random( (string) $config['seed'], 'image:' . $k );
		$img    = imagecreatetruecolor( self::WIDTH, self::HEIGHT );
		$base   = $r->pick( self::PALETTE );
		$accent = $r->pick( self::PALETTE );
		imagefill( $img, 0, 0, imagecolorallocate( $img, $base[0], $base[1], $base[2] ) );
		$light = imagecolorallocatealpha( $img, 255, 255, 255, 90 );
		$tone  = imagecolorallocatealpha( $img, $accent[0], $accent[1], $accent[2], 40 );
		for ( $i = 0; $i < 6; $i++ ) {
			$size = $r->int( 80, 360 );
			imagefilledellipse( $img, $r->int( 0, self::WIDTH ), $r->int( 0, self::HEIGHT ), $size, $size, 0 === $i % 2 ? $light : $tone );
		}
		$white = imagecolorallocate( $img, 255, 255, 255 );
		// Drawn into the pixels with GD's built-in font, which only has Latin-1 glyphs: not translated.
		$label = 'Placeholder ' . ( $k + 1 );
		imagestring( $img, 5, 24, self::HEIGHT - 40, $label, $white );
		ob_start();
		imagepng( $img );
		$bytes = (string) ob_get_clean();
		imagedestroy( $img );

		$filename = 'cradle-dummy-' . substr( $batch['uuid'], 0, 8 ) . '-' . ( $k + 1 ) . '.png';
		$upload   = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'cradle_dummy_upload', (string) $upload['error'] );
		}
		$id = wp_insert_attachment(
			wp_slash(
				array(
					'post_mime_type' => 'image/png',
					'post_title'     => $label,
					'post_status'    => 'inherit',
					'post_author'    => $author,
					'meta_input'     => Ownership::markers( $batch['uuid'], 'image:' . $k, (int) $batch['owner'] ),
				)
			),
			$upload['file'],
			0,
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $upload['file'] );
			return $id;
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		update_post_meta( $id, '_wp_attachment_image_alt', $label );
		Ownership::store_hash( (int) $id );
		return (int) $id;
	}
}
