<?php
/**
 * Core boot sequence and service locator.
 *
 * Startup (documented in docs/architecture/core-api.md):
 * 1. Add-ons attach `cradle/register_modules` callbacks while their bootstrap file loads.
 * 2. `plugins_loaded` priority 5: core builds the registry and fires `cradle/register_modules`.
 * 3. The registry validates descriptors, API ranges, dependencies and cycles.
 * 4. Core services become available and `cradle/ready` fires with ($core, CRADLE_API_VERSION).
 * 5. `init` priority 0: core registers its capabilities, then calls each
 *    ready module's `boot` callback in dependency order (translations are available from here),
 *    then fires `cradle/booted`. Modules register post types, blocks and sections inside boot.
 *
 * @package Cradle
 */

namespace Cradle\Core;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	const BOOT_PRIORITY = 5;

	/** @var Plugin|null */
	private static $instance = null;

	private Module_Registry $modules;
	private Settings $settings;
	private Capabilities $capabilities;
	private Components $components;
	private Jobs $jobs;
	private Locks $locks;
	private Activity $activity;
	private Storage $storage;
	private Inventory $inventory;
	private Assets $assets;

	public static function instance() {
		return self::$instance;
	}

	public static function boot() {
		if ( self::$instance ) {
			return;
		}
		$core           = new self();
		self::$instance = $core;
		$core->start();
	}

	private function start() {
		$this->capabilities = new Capabilities();
		$this->settings     = new Settings( $this->capabilities );
		$this->components   = new Components();
		$this->jobs         = new Jobs();
		$this->locks        = new Locks();
		$this->activity     = new Activity();
		$this->storage      = new Storage();
		$this->assets       = new Assets();
		$this->modules      = new Module_Registry();
		$this->inventory    = new Inventory( $this->modules, $this->components );

		/**
		 * Fires once so active add-ons can register trusted module descriptors.
		 *
		 * @param Module_Registry $registry Registry accepting descriptors.
		 */
		do_action( 'cradle/register_modules', $this->modules );

		$this->modules->resolve( CRADLE_API_VERSION );

		Schema::hooks();
		add_action( 'init', array( $this, 'init' ), 0 );
		$this->settings->hooks();
		$this->capabilities->hooks();
		$this->components->hooks();
		$this->activity->hooks();
		$this->jobs->hooks();
		Health::hooks( $this );

		if ( is_admin() ) {
			( new Admin\Admin( $this ) )->hooks();
		}

		/**
		 * Core services are available. Add-ons must check the API version.
		 *
		 * @param Plugin $core        Core instance.
		 * @param string $api_version Semantic core API version.
		 */
		do_action( 'cradle/ready', $this, CRADLE_API_VERSION );
	}

	/**
	 * Registers translated capability definitions and boots ready modules (init priority 0).
	 */
	public function init() {
		$this->capabilities->register_core();
		foreach ( $this->modules->ready_descriptors() as $descriptor ) {
			foreach ( $descriptor['capabilities'] as $cap => $definition ) {
				$this->capabilities->register( $cap, $definition, $descriptor['id'] );
			}
		}

		$this->modules->boot_ready_modules( $this );

		/**
		 * All ready modules have booted.
		 *
		 * @param Plugin $core Core instance.
		 */
		do_action( 'cradle/booted', $this );
	}

	public function modules(): Module_Registry {
		return $this->modules;
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function capabilities(): Capabilities {
		return $this->capabilities;
	}

	public function components(): Components {
		return $this->components;
	}

	public function jobs(): Jobs {
		return $this->jobs;
	}

	public function locks(): Locks {
		return $this->locks;
	}

	public function activity(): Activity {
		return $this->activity;
	}

	public function storage(): Storage {
		return $this->storage;
	}

	public function inventory(): Inventory {
		return $this->inventory;
	}

	public function assets(): Assets {
		return $this->assets;
	}

	public function api_version(): string {
		return CRADLE_API_VERSION;
	}
}
