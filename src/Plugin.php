<?php

namespace Userfy\User;

use Userfy\User\Auth;
use Userfy\User\Settings;

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 */
class Plugin {

	/**
	 * The unique identifier of this plugin.
	 */
	const PLUGIN_NAME = 'uf-user';

	/**
	 * The current version of the plugin.
	 */
	const VERSION = '1.0.0';

	/**
	 * Run the plugin.
	 */
	public function run() {
		$this->load_dependencies();
	}

	/**
	 * Load the required dependencies for this plugin.
	 */
	private function load_dependencies() {
		// Class responsible for the admin settings page
		$settings = new Settings( self::PLUGIN_NAME, self::VERSION );
		add_action( 'admin_menu', [ $settings, 'add_options_page' ] );
		add_action( 'admin_init', [ $settings, 'register_settings' ] );

		// Class responsible for authentication logic
		$auth = new Auth( self::PLUGIN_NAME, self::VERSION );
		// The login_init hook is now handled inside Auth's constructor.
		add_action( 'init', [ $auth, 'handle_clerk_authentication' ] );
	}
}
