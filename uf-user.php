<?php
/**
 * Plugin Name:       UF_User
 * Plugin URI:        https://userfy.com.au/
 * Description:       Overrides the standard WordPress login to use Clerk.com SSO for user validation.
 * Version:           1.0.0
 * Author:            Ben Mitchell
 * Author URI:        https://userfy.com.au/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       uf-user
 * Namespace:         Userfy\User
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Check for Composer's autoloader
if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'UF_User Error: Please run "composer install" in the plugin directory.', 'uf-user' );
		echo '</p></div>';
	});
	return;
}
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Load environment variables from .env file if it exists.
 * This allows for secure handling of API keys.
 */
if ( file_exists( __DIR__ . '/.env' ) ) {
	$dotenv = Dotenv\Dotenv::createImmutable( __DIR__ );
	$dotenv->load();
}
/**
 * Begins execution of the plugin.
 */
function run_uf_user_plugin() {
	$plugin = new \Userfy\User\Plugin();
	$plugin->run();
}
run_uf_user_plugin();
