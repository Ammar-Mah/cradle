<?php
/**
 * Site-wide Cradle Forms settings (section `forms`, option `cradle_forms_settings`).
 *
 * Per-form settings live in the form post (`_cradle_form_settings`); this section only holds the
 * limits a file field can narrow but never widen, and the fallback notification recipient.
 * Rendered on Cradle → Forms → Settings (no separate page).
 *
 * @package Cradle\Forms
 */

namespace Cradle\Forms;

defined( 'ABSPATH' ) || exit;

final class Settings_Section {

	const ID     = 'forms';
	const OPTION = 'cradle_forms_settings';

	const DEFAULT_EXTENSIONS = 'pdf,doc,docx,odt,rtf,txt,jpg,jpeg,png';

	/** Extensions that can never be allowed, whatever the configuration (executables, scripts, markup). */
	const BLOCKED_EXTENSIONS = array( 'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'pgif', 'shtml', 'cgi', 'pl', 'py', 'pyc', 'rb', 'sh', 'bash', 'zsh', 'asp', 'aspx', 'ascx', 'ashx', 'asmx', 'cer', 'jsp', 'jspx', 'cfm', 'cfc', 'exe', 'com', 'bat', 'cmd', 'msi', 'msp', 'dll', 'so', 'jar', 'war', 'vbs', 'vbe', 'ws', 'wsf', 'wsh', 'scr', 'hta', 'ps1', 'psm1', 'js', 'mjs', 'cjs', 'html', 'htm', 'xhtml', 'shtm', 'svg', 'svgz', 'xml', 'xsl', 'swf', 'htaccess', 'htpasswd', 'ini', 'user', 'lnk', 'reg', 'inc' );

	/**
	 * @param \Cradle\Core\Plugin $core Core.
	 */
	public static function register( $core ) {
		$server_mb = self::server_limit_mb();
		$core->settings()->register_section(
			array(
				'id'            => self::ID,
				'owner'         => Module::ID,
				'title'         => __( 'Form settings', 'cradle-forms' ),
				'description'   => __( 'Limits for every form on this site. A file field can narrow these limits but never widen them.', 'cradle-forms' ),
				'capability'    => Caps::MANAGE,
				'schemaVersion' => 1,
				'page'          => false,
				'autoload'      => true,
				'lifecycle'     => array( 'uninstall' => 'retain' ),
				'fields'        => array(
					'allowed_extensions' => array(
						'type'      => 'string',
						'default'   => self::DEFAULT_EXTENSIONS,
						'maxLength' => 300,
						'pattern'   => '/^\s*[A-Za-z0-9]{1,10}(\s*,\s*[A-Za-z0-9]{1,10})*\s*$/',
						'pattern_message' => __( 'Use file extensions separated by commas, for example pdf,docx,jpg.', 'cradle-forms' ),
						'label'     => __( 'Allowed file types', 'cradle-forms' ),
						'help'      => __( 'Comma-separated file extensions that file fields may accept, for example pdf,docx,jpg. Scripts, programs, HTML and SVG are always refused.', 'cradle-forms' ),
					),
					'max_upload_mb'      => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 1024,
						'label'   => __( 'Maximum file size (MB)', 'cradle-forms' ),
						/* translators: %d: upload limit of the web server in megabytes. */
						'help'    => sprintf( __( 'Largest file a visitor can upload. This server accepts at most %d MB per request, which always applies as well.', 'cradle-forms' ), $server_mb ),
					),
					'fallback_recipient' => array(
						'type'    => 'email',
						'default' => '',
						'label'   => __( 'Fallback recipient', 'cradle-forms' ),
						'help'    => __( 'Receives notifications whose recipients and routing rules give no address. Empty: the site administration email address.', 'cradle-forms' ),
					),
				),
				'validate'      => array( __CLASS__, 'validate' ),
			)
		);
	}

	/**
	 * Cross-field validation: refuses blocked extensions in the allowlist.
	 */
	public static function validate( array $values, array $current ): array {
		$errors = array();
		$list   = self::parse_extensions( (string) ( $values['allowed_extensions'] ?? '' ) );
		$bad    = array_intersect( $list, self::BLOCKED_EXTENSIONS );
		if ( $bad ) {
			/* translators: %s: comma-separated list of refused extensions. */
			$errors['allowed_extensions'] = sprintf( __( 'These file types can never be accepted: %s.', 'cradle-forms' ), implode( ', ', $bad ) );
		}
		return $errors;
	}

	public static function get( string $field ) {
		return function_exists( 'cradle_get_setting' ) ? cradle_get_setting( self::ID, $field ) : null;
	}

	/**
	 * @return string[] Lowercase extensions from a comma-separated list (blocked ones removed).
	 */
	public static function parse_extensions( string $list ): array {
		$out = array();
		foreach ( explode( ',', strtolower( $list ) ) as $ext ) {
			$ext = trim( $ext, " \t\n\r\0\x0B." );
			if ( preg_match( '/^[a-z0-9]{1,10}$/', $ext ) ) {
				$out[ $ext ] = $ext;
			}
		}
		return array_values( $out );
	}

	/**
	 * @return string[] Site-wide allowed extensions (never blocked ones).
	 */
	public static function allowed_extensions(): array {
		$value = self::get( 'allowed_extensions' );
		$list  = self::parse_extensions( is_string( $value ) ? $value : self::DEFAULT_EXTENSIONS );
		return array_values( array_diff( $list, self::BLOCKED_EXTENSIONS ) );
	}

	public static function server_limit_bytes(): int {
		return max( 1, (int) wp_max_upload_size() );
	}

	public static function server_limit_mb(): int {
		return max( 1, (int) floor( self::server_limit_bytes() / MB_IN_BYTES ) );
	}

	/**
	 * Largest accepted file in bytes: the setting, never above the server limit.
	 */
	public static function max_bytes(): int {
		$mb = (int) ( self::get( 'max_upload_mb' ) ?? 10 );
		return max( 1, min( max( 1, $mb ) * MB_IN_BYTES, self::server_limit_bytes() ) );
	}

	public static function fallback_recipient(): string {
		$email = (string) ( self::get( 'fallback_recipient' ) ?? '' );
		if ( '' === $email || ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}
		return is_email( $email ) ? $email : '';
	}
}
