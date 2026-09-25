<?php
/**
 * Module descriptor, boot and the `login` settings section.
 *
 * @package Cradle\Login
 */

namespace Cradle\Login;

defined( 'ABSPATH' ) || exit;

final class Module {

	const ID = 'login';

	const CAP = 'cradle_manage_login';

	const SECTION = 'login';

	const MIN_API = '1.0.0';

	/**
	 * `cradle/register_modules`: registers the trusted descriptor (plain strings only).
	 *
	 * @param \Cradle\Core\Module_Registry $registry Registry.
	 */
	public static function register( $registry ) {
		$registry->register(
			array(
				'id'               => self::ID,
				'name'             => 'Cradle Login',
				'description'      => 'Optional custom login route served through native WordPress authentication, with health check and server-side recovery.',
				'version'          => CRADLE_LOGIN_VERSION,
				'coreApiRange'     => '^1.0',
				'pluginFile'       => CRADLE_LOGIN_FILE,
				'settingsSections' => array( self::SECTION ),
				'capabilities'     => array(
					self::CAP => array(
						'label'       => 'Manage login routing',
						'description' => 'Enable, change or disable the custom login route and run its health check.',
						'roles'       => array( 'administrator' ),
					),
				),
				'schemaVersion'    => 1,
				'dataOwners'       => array(
					'options' => array( Config::OPTION, Config::HEALTH_OPTION, '_transient_cradle_login_*', '_transient_timeout_cradle_login_*' ),
				),
				'boot'             => array( __CLASS__, 'boot' ),
				'adminPage'        => 'cradle-login',
			)
		);
	}

	/**
	 * `cradle/ready` (plugins_loaded priority 5): starts routing when the module is ready.
	 *
	 * @param \Cradle\Core\Plugin $core        Core.
	 * @param string              $api_version Core API version.
	 */
	public static function ready( $core, $api_version = '' ) {
		if ( ! is_object( $core ) || ! is_string( $api_version ) || ! version_compare( $api_version, self::MIN_API, '>=' ) ) {
			return;
		}
		if ( ! $core->modules()->is_ready( self::ID ) ) {
			return;
		}
		Router::instance()->start();
	}

	/**
	 * Module boot (init priority 0): settings section, change logging and admin screens.
	 *
	 * @param \Cradle\Core\Plugin $core       Core.
	 * @param array               $descriptor Normalized descriptor.
	 */
	public static function boot( $core, array $descriptor = array() ) {
		unset( $descriptor );
		if ( ! version_compare( $core->api_version(), self::MIN_API, '>=' ) ) {
			return;
		}
		load_plugin_textdomain( 'cradle-login', false, dirname( plugin_basename( CRADLE_LOGIN_FILE ) ) . '/languages' );
		self::register_settings( $core );
		add_action( 'update_option_' . Config::OPTION, array( __CLASS__, 'log_changes' ), 10, 2 );
		add_action( 'add_option_' . Config::OPTION, array( __CLASS__, 'log_added' ), 10, 2 );
		if ( is_admin() ) {
			( new Admin\Admin_Page( $core ) )->hooks();
		}
	}

	private static function register_settings( $core ) {
		$core->settings()->register_section(
			array(
				'id'            => self::SECTION,
				'owner'         => self::ID,
				'title'         => __( 'Login routing', 'cradle-login' ),
				'description'   => __( 'Serve the native WordPress login page at a custom path. This reduces automated requests to the default login URL; it does not replace updates, strong passwords, two-factor authentication, HTTPS or rate limiting.', 'cradle-login' ),
				'capability'    => self::CAP,
				'schemaVersion' => 1,
				'page'          => false,
				'autoload'      => true,
				'lifecycle'     => array( 'uninstall' => 'retain' ),
				'fields'        => array(
					'enabled'          => array(
						'type'           => 'boolean',
						'default'        => false,
						'label'          => __( 'Custom login route', 'cradle-login' ),
						'checkbox_label' => __( 'Serve the login page at the custom route', 'cradle-login' ),
						'help'           => __( 'Off by default. Before enabling (or moving) the route, the site requests it from itself and refuses the change unless the login form is served there. Your current session stays signed in.', 'cradle-login' ),
					),
					'slug'             => array(
						'type'            => 'string',
						'default'         => '',
						'maxLength'       => 64,
						'pattern'         => Config::SLUG_PATTERN,
						'pattern_message' => __( 'Use 3–64 lowercase letters, digits and hyphens, starting and ending with a letter or digit.', 'cradle-login' ),
						'placeholder'     => 'team-access',
						'label'           => __( 'Route slug', 'cradle-login' ),
						/* translators: %s: example login URL */
						'help'            => sprintf( __( 'The login page is served at %s. Slugs used by pages, posts, WordPress routes, the REST API, feeds, sitemaps, query variables, files or WooCommerce endpoints are rejected.', 'cradle-login' ), Config::route_url( '<slug>' ) ),
						'exposure'        => array(
							'export'      => true,
							'diagnostics' => false,
						),
					),
					'blocked_response' => array(
						'type'    => 'enum',
						'enum'    => array(
							'not_found'       => __( 'Generic 404 page (theme template)', 'cradle-login' ),
							'plain_not_found' => __( 'Minimal 404 response without the theme', 'cradle-login' ),
							'redirect_home'   => __( 'Redirect to the home page', 'cradle-login' ),
						),
						'default' => 'not_found',
						'control' => 'radio',
						'label'   => __( 'Signed-out requests to wp-login.php and /wp-admin/', 'cradle-login' ),
						'help'    => __( 'Applies only while the route is active. Signed-in users, admin-ajax.php, admin-post.php, the REST API, XML-RPC, cron and WP-CLI are never affected. Valid password-reset, privacy-confirmation and recovery-mode links are forwarded to the route.', 'cradle-login' ),
					),
					'recovery_note'    => array(
						'type'      => 'string',
						'control'   => 'textarea',
						'rows'      => 3,
						'maxLength' => 500,
						'default'   => '',
						'sensitive' => true,
						'label'     => __( 'Administrator recovery note', 'cradle-login' ),
						'help'      => __( 'Optional. Shown only on this screen to users who can manage login routing, for example where the route is recorded in your team runbook. Never store passwords here.', 'cradle-login' ),
					),
				),
				'validate'      => array( __CLASS__, 'validate_section' ),
			)
		);
	}

	/**
	 * Cross-field validation: slug collisions, enable preconditions and the pre-enable
	 * health check. Returned errors keep the previous value of the named field.
	 *
	 * @param array $values  Field-validated submitted values merged over current.
	 * @param array $current Current stored values.
	 * @return array<string,string> Field => message.
	 */
	public static function validate_section( array $values, array $current ): array {
		$errors  = array();
		$slug    = is_string( $values['slug'] ?? null ) ? $values['slug'] : '';
		$old     = is_string( $current['slug'] ?? null ) ? $current['slug'] : '';
		$enabled = true === ( $values['enabled'] ?? false );
		$was     = true === ( $current['enabled'] ?? false );

		if ( $slug !== $old && '' !== $slug ) {
			$check = Slug_Policy::check( $slug );
			if ( is_wp_error( $check ) ) {
				$errors['slug'] = $check->get_error_message();
				$slug           = $old;
			}
		}
		if ( '' === $slug && '' !== $old && $enabled ) {
			$errors['slug'] = __( 'Turn the custom route off before clearing its slug.', 'cradle-login' );
			$slug           = $old;
		}
		if ( ! $enabled ) {
			return $errors;
		}

		$turning_on = ! $was;
		$moving     = $was && $slug !== $old;
		if ( ! $turning_on && ! $moving ) {
			return $errors;
		}
		$field = $turning_on ? 'enabled' : 'slug';

		// On a form submission, a slug rejected by field validation silently keeps the old
		// value; never enable the old slug in that case.
		$submitted = self::submitted_slug();
		if ( $turning_on && null !== $submitted && $submitted !== $slug && ! isset( $errors['slug'] ) ) {
			$errors['enabled'] = __( 'Fix the route slug before enabling the custom route.', 'cradle-login' );
			return $errors;
		}

		if ( Config::disabled_by_constant() ) {
			/* translators: %s: constant name */
			$errors[ $field ] = sprintf( __( 'Routing is switched off by %s in the server configuration. Remove it before enabling or moving the route.', 'cradle-login' ), Config::CONSTANT );
			return $errors;
		}
		if ( is_multisite() ) {
			$errors[ $field ] = __( 'Custom login routing is not supported on multisite networks.', 'cradle-login' );
			return $errors;
		}
		if ( Config::disabled_by_filter() ) {
			$errors[ $field ] = __( 'Routing is switched off by code (the cradle_login_disable_routing filter).', 'cradle-login' );
			return $errors;
		}
		if ( '' === $slug ) {
			$errors['enabled'] = __( 'Enter a route slug before enabling the custom route.', 'cradle-login' );
			return $errors;
		}
		if ( $turning_on && isset( $errors['slug'] ) ) {
			$errors['enabled'] = __( 'Fix the route slug before enabling the custom route.', 'cradle-login' );
			return $errors;
		}
		if ( $turning_on && $slug === $old ) {
			// Content or routes may have appeared since the slug was saved.
			$check = Slug_Policy::check( $slug );
			if ( is_wp_error( $check ) ) {
				$errors['enabled'] = $check->get_error_message();
				return $errors;
			}
		}

		$result = Health_Check::probe( $slug );
		Health_Check::record( $result, $turning_on ? 'enable' : 'change' );
		if ( ! $result['ok'] ) {
			$errors[ $field ] = $turning_on
				/* translators: %s: health check failure */
				? sprintf( __( 'The custom route was not enabled: %s', 'cradle-login' ), $result['message'] )
				/* translators: %s: health check failure */
				: sprintf( __( 'The route was not moved: %s', 'cradle-login' ), $result['message'] );
		}
		return $errors;
	}

	/**
	 * Slug typed into this section's options.php form (normalized like the field
	 * validator does), or null when this is not such a submission.
	 */
	private static function submitted_slug(): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- options.php verified the group nonce before sanitizing.
		if ( ! isset( $_POST['option_page'], $_POST[ Config::OPTION ] ) || 'cradle_login' !== $_POST['option_page'] || ! is_array( $_POST[ Config::OPTION ] ) ) {
			return null;
		}
		$raw = $_POST[ Config::OPTION ]['slug'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized below, compared only.
		// phpcs:enable
		return is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : null;
	}

	/**
	 * Records meaningful routing changes (never the slug itself).
	 *
	 * @param mixed $old   Previous option value.
	 * @param mixed $value New option value.
	 */
	public static function log_changes( $old, $value ) {
		$old   = is_array( $old ) ? $old : array();
		$value = is_array( $value ) ? $value : array();
		$was   = true === ( $old['enabled'] ?? false );
		$is    = true === ( $value['enabled'] ?? false );
		if ( ! $was && $is ) {
			cradle_log_activity( self::ID, 'route_enabled', 'Custom login route enabled.' );
		} elseif ( $was && ! $is ) {
			cradle_log_activity( self::ID, 'route_disabled', 'Custom login route disabled; wp-login.php is served normally.' );
		} elseif ( $is && ( $old['slug'] ?? '' ) !== ( $value['slug'] ?? '' ) ) {
			cradle_log_activity( self::ID, 'route_changed', 'Custom login route moved to a new slug.' );
		}
		if ( ( $old['blocked_response'] ?? 'not_found' ) !== ( $value['blocked_response'] ?? 'not_found' ) ) {
			cradle_log_activity( self::ID, 'response_changed', 'Response for signed-out native login requests changed.', array( 'response' => (string) ( $value['blocked_response'] ?? '' ) ) );
		}
	}

	/**
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 */
	public static function log_added( $option, $value ) {
		unset( $option );
		self::log_changes( array(), $value );
	}
}
