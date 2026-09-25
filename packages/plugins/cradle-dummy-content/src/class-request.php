<?php
/**
 * Validates a generation request (form or PHP API) for the current user and
 * normalizes it into a batch config. Every field is type/range/allowlist
 * checked before it is normalized; invalid fields produce field-level errors.
 *
 * @package Cradle\Dummy_Content
 */

namespace Cradle\Dummy_Content;

defined( 'ABSPATH' ) || exit;

final class Request {

	/** Post types never generated (WooCommerce needs a dedicated, effect-suppressing mode). */
	const EXCLUDED_TYPES = array( 'attachment', 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_subscription', Batches::POST_TYPE );

	const STATUSES      = array( 'draft', 'pending', 'private', 'publish' );
	const TITLE_PATTERN = array( 'headline', 'question', 'numbered', 'custom' );
	const BODY_PATTERN  = array( 'article', 'paragraphs', 'short' );
	const META_TYPES    = array( 'word', 'sentence', 'number', 'date', 'boolean' );
	const IMAGE_MODES   = array( 'none', 'library', 'placeholder' );
	const RESERVED_META = array( 'footnotes' );
	const MAX_META_ROWS = 5;
	const KEY_PATTERN   = '/^[A-Za-z][A-Za-z0-9_\-]{0,63}$/';

	/**
	 * Post types offered for generation.
	 *
	 * @return array<string,\WP_Post_Type>
	 */
	public static function post_types(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
			if ( in_array( $name, self::EXCLUDED_TYPES, true ) || ! $object->show_ui ) {
				continue;
			}
			$out[ $name ] = $object;
		}
		/**
		 * Filters post types offered for generation. Excluded types (attachments,
		 * WooCommerce products/orders, batch records) are removed afterwards.
		 *
		 * @param array<string,\WP_Post_Type> $out Post type objects keyed by name.
		 */
		$out = (array) apply_filters( 'cradle_dummy_content/post_types', $out );
		foreach ( array_keys( $out ) as $name ) {
			if ( in_array( $name, self::EXCLUDED_TYPES, true ) || ! post_type_exists( $name ) ) {
				unset( $out[ $name ] );
			}
		}
		return $out;
	}

	/**
	 * Taxonomies whose terms can be assigned/generated for a type.
	 *
	 * @return array<string,\WP_Taxonomy>
	 */
	public static function taxonomies( string $post_type ): array {
		$out = array();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $name => $taxonomy ) {
			if ( 'post_format' === $name || ! $taxonomy->show_ui ) {
				continue;
			}
			$out[ $name ] = $taxonomy;
		}
		return $out;
	}

	public static function is_production(): bool {
		/**
		 * Whether the production guard applies (default: environment type is "production").
		 *
		 * @param bool $is_production Default decision.
		 */
		return (bool) apply_filters( 'cradle_dummy_content/is_production', 'production' === wp_get_environment_type() );
	}

	public static function production_phrase(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	public static function gd_available(): bool {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagepng' );
	}

	/**
	 * @param array $raw Unslashed input.
	 * @return array{config:?array,errors:array<string,string>}
	 */
	public static function validate( array $raw ): array {
		$errors = array();
		$config = array( 'version' => 1 );
		$scalar = static function ( $key, $default = '' ) use ( $raw ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				return $default;
			}
			return is_scalar( $raw[ $key ] ) ? trim( (string) $raw[ $key ] ) : null;
		};
		$list   = static function ( $key ) use ( $raw ) {
			if ( ! isset( $raw[ $key ] ) || '' === $raw[ $key ] ) {
				return array();
			}
			return is_array( $raw[ $key ] ) ? $raw[ $key ] : null;
		};

		if ( ! current_user_can( Lifecycle::CAP_GENERATE ) ) {
			return array(
				'config' => null,
				'errors' => array( 'form' => __( 'You are not allowed to generate dummy content.', 'cradle-dummy-content' ) ),
			);
		}

		// Batch name and note.
		$name = $scalar( 'batch_name' );
		if ( null === $name || ! mb_check_encoding( (string) $name, 'UTF-8' ) ) {
			$errors['batch_name'] = __( 'Enter a batch name.', 'cradle-dummy-content' );
		} else {
			$name = sanitize_text_field( $name );
			if ( '' === $name || mb_strlen( $name ) > 80 ) {
				$errors['batch_name'] = __( 'Enter a batch name of up to 80 characters.', 'cradle-dummy-content' );
			}
			$config['batch_name'] = $name;
		}
		$note = $scalar( 'note' );
		if ( null === $note || ! mb_check_encoding( (string) $note, 'UTF-8' ) || mb_strlen( (string) $note ) > 500 ) {
			$errors['note'] = __( 'The note must be text of up to 500 characters.', 'cradle-dummy-content' );
		} else {
			$config['note'] = sanitize_textarea_field( $note );
		}

		// Post type and capability to create it.
		$types = self::post_types();
		$type  = $scalar( 'post_type', 'post' );
		if ( null === $type || ! isset( $types[ $type ] ) ) {
			$errors['post_type'] = __( 'Choose a supported post type.', 'cradle-dummy-content' );
			return array(
				'config' => null,
				'errors' => $errors,
			);
		}
		$pto = $types[ $type ];
		if ( ! current_user_can( $pto->cap->create_posts ) ) {
			$errors['post_type'] = __( 'You cannot create items of this type.', 'cradle-dummy-content' );
		}
		$config['post_type'] = $type;

		// Count with the configured upper bound.
		$max   = min( Config::ABSOLUTE_MAX_ITEMS, (int) Config::get( 'max_items_per_batch' ) );
		$count = $scalar( 'count', '5' );
		if ( null === $count || ! preg_match( '/^\d{1,4}$/', $count ) || (int) $count < 1 || (int) $count > $max ) {
			/* translators: %d: maximum items */
			$errors['count'] = sprintf( __( 'Enter a whole number from 1 to %d.', 'cradle-dummy-content' ), $max );
		} else {
			$config['count'] = (int) $count;
		}

		// Status.
		$status = $scalar( 'status', (string) Config::get( 'default_status' ) );
		if ( null === $status || ! in_array( $status, self::STATUSES, true ) ) {
			$errors['status'] = __( 'Choose a status.', 'cradle-dummy-content' );
		} elseif ( in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( $pto->cap->publish_posts ) ) {
			$errors['status'] = __( 'You cannot publish items of this type.', 'cradle-dummy-content' );
		} else {
			$config['status'] = $status;
		}

		// Authors: existing users only; never creates users.
		$authors = $list( 'authors' );
		if ( null === $authors || count( $authors ) > 20 ) {
			$errors['authors'] = __( 'Choose up to 20 existing authors.', 'cradle-dummy-content' );
		} else {
			$ids = array();
			foreach ( $authors as $author ) {
				if ( ! is_scalar( $author ) || ! preg_match( '/^\d+$/', (string) $author ) ) {
					$errors['authors'] = __( 'Choose existing authors.', 'cradle-dummy-content' );
					break;
				}
				$ids[] = (int) $author;
			}
			$ids = $ids ? array_values( array_unique( $ids ) ) : array( get_current_user_id() );
			foreach ( $ids as $author ) {
				if ( ! get_userdata( $author ) || ! user_can( $author, $pto->cap->edit_posts ) ) {
					$errors['authors'] = __( 'Every author must be an existing user who can write this type.', 'cradle-dummy-content' );
				} elseif ( get_current_user_id() !== $author && ! current_user_can( $pto->cap->edit_others_posts ) ) {
					$errors['authors'] = __( 'You cannot assign items to other authors.', 'cradle-dummy-content' );
				}
			}
			$config['authors'] = $ids;
		}

		// Title and body patterns.
		$title_pattern = $scalar( 'title_pattern', 'headline' );
		if ( null === $title_pattern || ! in_array( $title_pattern, self::TITLE_PATTERN, true ) ) {
			$errors['title_pattern'] = __( 'Choose a title pattern.', 'cradle-dummy-content' );
		} else {
			$config['title_pattern'] = $title_pattern;
		}
		$template                 = $scalar( 'title_template' );
		$config['title_template'] = '';
		if ( 'custom' === $title_pattern ) {
			$check = self::validate_template( $template );
			if ( is_wp_error( $check ) ) {
				$errors['title_template'] = $check->get_error_message();
			} else {
				$config['title_template'] = $check;
			}
		}
		$body = $scalar( 'body_pattern', 'article' );
		if ( null === $body || ! in_array( $body, self::BODY_PATTERN, true ) ) {
			$errors['body_pattern'] = __( 'Choose a body pattern.', 'cradle-dummy-content' );
		} else {
			$config['body_pattern'] = $body;
		}
		$vocabulary = $scalar( 'vocabulary', 'english' );
		if ( null === $vocabulary || ! in_array( $vocabulary, Vocabulary::NAMES, true ) ) {
			$errors['vocabulary'] = __( 'Choose a vocabulary.', 'cradle-dummy-content' );
		} else {
			$config['vocabulary'] = $vocabulary;
		}
		$pmin = $scalar( 'paragraphs_min', '2' );
		$pmax = $scalar( 'paragraphs_max', '4' );
		if ( null === $pmin || null === $pmax || ! preg_match( '/^\d{1,2}$/', $pmin ) || ! preg_match( '/^\d{1,2}$/', $pmax ) || (int) $pmin < 1 || (int) $pmax > 20 || (int) $pmin > (int) $pmax ) {
			$errors['paragraphs'] = __( 'Paragraphs: minimum and maximum between 1 and 20, minimum not above maximum.', 'cradle-dummy-content' );
		} else {
			$config['paragraphs'] = array( (int) $pmin, (int) $pmax );
		}
		$config['excerpt'] = in_array( $scalar( 'excerpt', '0' ), array( '1', 'on', 'true' ), true );

		// Date range (site-local dates, not in the future).
		$from  = $scalar( 'date_from' );
		$to    = $scalar( 'date_to' );
		$today = current_time( 'Y-m-d' );
		$valid = static function ( $date ) use ( $today ) {
			if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				return false;
			}
			return $date >= '1970-01-02' && $date <= $today;
		};
		$config['dates'] = array();
		if ( null === $from || null === $to ) {
			$errors['dates'] = __( 'Dates must be in YYYY-MM-DD format.', 'cradle-dummy-content' );
		} elseif ( '' !== $from || '' !== $to ) {
			$from = '' === $from ? $to : $from;
			$to   = '' === $to ? $from : $to;
			if ( ! $valid( $from ) || ! $valid( $to ) ) {
				$errors['dates'] = __( 'Dates must be valid, in YYYY-MM-DD format and not in the future.', 'cradle-dummy-content' );
			} elseif ( $from > $to ) {
				$errors['dates'] = __( 'The start date must not be after the end date.', 'cradle-dummy-content' );
			} else {
				$config['dates'] = array( $from, $to );
			}
		}

		// Terms: existing terms (referenced only) and generated terms (tracked).
		$config['taxonomies'] = array();
		$tax_input            = $list( 'tax' );
		$allowed_tax          = self::taxonomies( $type );
		$max_terms            = (int) Config::get( 'max_generated_terms' );
		if ( null === $tax_input ) {
			$errors['tax'] = __( 'Invalid term settings.', 'cradle-dummy-content' );
		} else {
			foreach ( $tax_input as $taxonomy => $rules ) {
				if ( ! is_string( $taxonomy ) || ! isset( $allowed_tax[ $taxonomy ] ) || ! is_array( $rules ) ) {
					$errors['tax'] = __( 'A selected taxonomy does not belong to this post type.', 'cradle-dummy-content' );
					continue;
				}
				$tax      = $allowed_tax[ $taxonomy ];
				$existing = isset( $rules['existing'] ) && '' !== $rules['existing'] ? $rules['existing'] : array();
				$generate = isset( $rules['generate'] ) && is_scalar( $rules['generate'] ) ? trim( (string) $rules['generate'] ) : '0';
				$per_item = isset( $rules['per_item'] ) && is_scalar( $rules['per_item'] ) ? trim( (string) $rules['per_item'] ) : '0';
				if ( ! is_array( $existing ) || count( $existing ) > 50 || ! preg_match( '/^\d{1,2}$/', '' === $generate ? '0' : $generate ) || ! preg_match( '/^\d$/', '' === $per_item ? '0' : $per_item ) ) {
					$errors[ 'tax_' . $taxonomy ] = __( 'Invalid term settings.', 'cradle-dummy-content' );
					continue;
				}
				$generate = (int) $generate;
				$per_item = (int) $per_item;
				$term_ids = array();
				foreach ( $existing as $term_id ) {
					$term = is_scalar( $term_id ) && preg_match( '/^\d+$/', (string) $term_id ) ? get_term( (int) $term_id, $taxonomy ) : null;
					if ( ! $term instanceof \WP_Term ) {
						$errors[ 'tax_' . $taxonomy ] = __( 'A selected term does not exist.', 'cradle-dummy-content' );
						continue 2;
					}
					$term_ids[] = (int) $term->term_id;
				}
				if ( $generate > $max_terms ) {
					/* translators: %d: maximum */
					$errors[ 'tax_' . $taxonomy ] = sprintf( __( 'Generate at most %d new terms.', 'cradle-dummy-content' ), $max_terms );
					continue;
				}
				if ( $per_item > 5 ) {
					$errors[ 'tax_' . $taxonomy ] = __( 'Assign at most 5 terms per item.', 'cradle-dummy-content' );
					continue;
				}
				if ( ! $term_ids && ! $generate ) {
					continue;
				}
				if ( ! current_user_can( $tax->cap->assign_terms ) || ( $generate && ! current_user_can( $tax->cap->edit_terms ) ) ) {
					$errors[ 'tax_' . $taxonomy ] = __( 'You cannot assign or create terms in this taxonomy.', 'cradle-dummy-content' );
					continue;
				}
				$config['taxonomies'][ $taxonomy ] = array(
					'existing' => array_values( array_unique( $term_ids ) ),
					'generate' => $generate,
					'per_item' => max( 1, $per_item ),
				);
			}
		}

		// Metadata rows (public keys only; protected and reserved keys refused).
		$config['meta'] = array();
		$meta_rows      = $list( 'meta' );
		if ( null === $meta_rows || count( $meta_rows ) > self::MAX_META_ROWS ) {
			$errors['meta'] = __( 'Invalid metadata rows.', 'cradle-dummy-content' );
		} else {
			foreach ( $meta_rows as $row ) {
				if ( ! is_array( $row ) ) {
					$errors['meta'] = __( 'Invalid metadata rows.', 'cradle-dummy-content' );
					break;
				}
				$key       = isset( $row['key'] ) && is_scalar( $row['key'] ) ? trim( (string) $row['key'] ) : '';
				$meta_type = isset( $row['type'] ) && is_scalar( $row['type'] ) ? (string) $row['type'] : 'word';
				if ( '' === $key ) {
					continue;
				}
				$key_check = self::validate_meta_key( $key );
				if ( is_wp_error( $key_check ) ) {
					$errors['meta'] = $key_check->get_error_message();
					break;
				}
				if ( ! in_array( $meta_type, self::META_TYPES, true ) ) {
					$errors['meta'] = __( 'Choose a value type for each metadata key.', 'cradle-dummy-content' );
					break;
				}
				$config['meta'][ $key ] = $meta_type;
			}
		}

		// Featured images: existing library images (referenced, never deleted) or local placeholders (owned).
		$mode = $scalar( 'image_mode', 'none' );
		if ( null === $mode || ! in_array( $mode, self::IMAGE_MODES, true ) ) {
			$errors['images'] = __( 'Choose a featured image source.', 'cradle-dummy-content' );
			$mode             = 'none';
		}
		$config['images'] = array(
			'mode'    => $mode,
			'library' => array(),
			'count'   => 0,
		);
		if ( 'none' !== $mode && ! post_type_supports( $type, 'thumbnail' ) ) {
			$errors['images'] = __( 'This post type does not support featured images.', 'cradle-dummy-content' );
		} elseif ( 'library' === $mode ) {
			$library = $list( 'image_library' );
			if ( null === $library || ! $library || count( $library ) > 50 ) {
				$errors['images'] = __( 'Select between 1 and 50 images from the Media Library.', 'cradle-dummy-content' );
			} else {
				foreach ( $library as $attachment_id ) {
					$attachment_id = is_scalar( $attachment_id ) && preg_match( '/^\d+$/', (string) $attachment_id ) ? (int) $attachment_id : 0;
					if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) || ! current_user_can( 'read_post', $attachment_id ) ) {
						$errors['images'] = __( 'Every selected item must be an existing image you can access.', 'cradle-dummy-content' );
						break;
					}
					$config['images']['library'][] = $attachment_id;
				}
				$config['images']['library'] = array_values( array_unique( $config['images']['library'] ) );
			}
		} elseif ( 'placeholder' === $mode ) {
			$max_images = (int) Config::get( 'max_placeholder_images' );
			$images     = $scalar( 'image_count', '3' );
			if ( ! self::gd_available() ) {
				$errors['images'] = __( 'Placeholder images need the PHP GD extension.', 'cradle-dummy-content' );
			} elseif ( ! current_user_can( 'upload_files' ) ) {
				$errors['images'] = __( 'You cannot add media files.', 'cradle-dummy-content' );
			} elseif ( null === $images || ! preg_match( '/^\d{1,2}$/', $images ) || (int) $images < 1 || (int) $images > $max_images ) {
				/* translators: %d: maximum */
				$errors['images'] = sprintf( __( 'Create between 1 and %d placeholder images.', 'cradle-dummy-content' ), $max_images );
			} else {
				$config['images']['count'] = (int) $images;
			}
		}

		// Optional relationships.
		$parent       = in_array( $scalar( 'rel_parent', '0' ), array( '1', 'on', 'true' ), true );
		$related      = $scalar( 'rel_meta_key' );
		$relationship = array(
			'parent'   => false,
			'meta_key' => '',
		);
		if ( $parent ) {
			if ( ! is_post_type_hierarchical( $type ) ) {
				$errors['relationships'] = __( 'Nesting is only available for hierarchical types such as pages.', 'cradle-dummy-content' );
			} else {
				$relationship['parent'] = true;
			}
		}
		if ( null === $related ) {
			$errors['relationships'] = __( 'Invalid related-items key.', 'cradle-dummy-content' );
		} elseif ( '' !== $related ) {
			$key_check = self::validate_meta_key( $related );
			if ( is_wp_error( $key_check ) ) {
				$errors['relationships'] = $key_check->get_error_message();
			} elseif ( isset( $config['meta'][ $related ] ) ) {
				$errors['relationships'] = __( 'The related-items key is already used by a metadata row.', 'cradle-dummy-content' );
			} else {
				$relationship['meta_key'] = $related;
			}
		}
		$config['relationships'] = $relationship;

		// Seed.
		$seed = $scalar( 'seed' );
		if ( null === $seed || ( '' !== $seed && ! preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $seed ) ) ) {
			$errors['seed'] = __( 'The seed may contain letters, digits, - and _ (up to 64 characters).', 'cradle-dummy-content' );
		} else {
			$config['seed'] = '' === $seed ? strtolower( wp_generate_password( 10, false, false ) ) : $seed;
		}

		// Production guard: an explicit, deliberate confirmation with visible scope.
		$config['production'] = self::is_production();
		if ( $config['production'] ) {
			$phrase = $scalar( 'confirm_production' );
			if ( ! is_string( $phrase ) || ! hash_equals( self::production_phrase(), $phrase ) ) {
				$errors['confirm_production'] = __( 'This is a production site. Type the site host exactly to confirm generation.', 'cradle-dummy-content' );
			}
		}

		return array(
			'config' => $errors ? null : $config,
			'errors' => $errors,
		);
	}

	/**
	 * @return string|\WP_Error Sanitized template.
	 */
	public static function validate_template( $template ) {
		if ( ! is_string( $template ) || '' === trim( $template ) || mb_strlen( $template ) > 120 || ! mb_check_encoding( $template, 'UTF-8' ) ) {
			return new \WP_Error( 'template', __( 'Enter a custom title template of up to 120 characters.', 'cradle-dummy-content' ) );
		}
		$clean = sanitize_text_field( $template );
		if ( preg_match_all( '/\{([^{}]*)\}/', $clean, $matches ) ) {
			foreach ( $matches[1] as $token ) {
				if ( ! in_array( $token, Vocabulary::CUSTOM_TOKENS, true ) ) {
					/* translators: %s: token */
					return new \WP_Error( 'template', sprintf( __( 'Unknown token {%s}. Use {n}, {adjective}, {noun}, {verb}, {word}, {batch} or {type}.', 'cradle-dummy-content' ), $token ) );
				}
			}
		}
		if ( substr_count( $clean, '{' ) !== substr_count( $clean, '}' ) || preg_match( '/\{[^}]*\{/', $clean ) ) {
			return new \WP_Error( 'template', __( 'The title template has unbalanced braces.', 'cradle-dummy-content' ) );
		}
		return $clean;
	}

	/**
	 * Public, non-reserved metadata keys only: generation can never write protected or ownership keys.
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_meta_key( string $key ) {
		if ( ! preg_match( self::KEY_PATTERN, $key ) || in_array( $key, self::RESERVED_META, true ) || is_protected_meta( $key, 'post' ) ) {
			return new \WP_Error( 'meta_key', __( 'Metadata keys must start with a letter, use letters, digits, - or _, and must not be protected or reserved.', 'cradle-dummy-content' ) );
		}
		return true;
	}
}
