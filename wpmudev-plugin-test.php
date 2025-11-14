<?php
/**
 * Plugin Name:       WPMU DEV Plugin Test - Forminator Developer Position
 * Description:       A plugin focused on testing coding skills.
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Version:           0.1.0
 * Author:            PLEASE ADD YOU FULL NAME HERE
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpmudev-plugin-test
 *
 * @package           create-block
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Support for site-level autoloading while keeping Composer loader scoped to this plugin.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	$wpmudev_plugin_test_loader = require __DIR__ . '/vendor/autoload.php';

	if ( $wpmudev_plugin_test_loader instanceof \Composer\Autoload\ClassLoader ) {
		// Re-register without prepending so other plugins' versions keep priority.
		$wpmudev_plugin_test_loader->unregister();
		$wpmudev_plugin_test_loader->register( false );
	}
}

// Ensure internal classes added after the original classmap are available even if composer dump-autoload hasn't been rerun.
if ( ! class_exists( 'WPMUDEV\\PluginTest\\Drive_Service' ) && file_exists( __DIR__ . '/core/class-drive-service.php' ) ) {
	require_once __DIR__ . '/core/class-drive-service.php';
}

if ( ! class_exists( 'WPMUDEV\\PluginTest\\Posts_Maintenance_Service' ) && file_exists( __DIR__ . '/core/class-posts-maintenance-service.php' ) ) {
	require_once __DIR__ . '/core/class-posts-maintenance-service.php';
}

if ( ! class_exists( 'WPMUDEV\\PluginTest\\App\\Admin_Pages\\Posts_Maintenance' ) && file_exists( __DIR__ . '/app/admin-pages/class-posts-maintenance.php' ) ) {
	require_once __DIR__ . '/app/admin-pages/class-posts-maintenance.php';
}


// Plugin version.
if ( ! defined( 'WPMUDEV_PLUGINTEST_VERSION' ) ) {
	define( 'WPMUDEV_PLUGINTEST_VERSION', '1.0.0' );
}

// Define WPMUDEV_PLUGINTEST_PLUGIN_FILE.
if ( ! defined( 'WPMUDEV_PLUGINTEST_PLUGIN_FILE' ) ) {
	define( 'WPMUDEV_PLUGINTEST_PLUGIN_FILE', __FILE__ );
}

// Plugin directory.
if ( ! defined( 'WPMUDEV_PLUGINTEST_DIR' ) ) {
	define( 'WPMUDEV_PLUGINTEST_DIR', plugin_dir_path( __FILE__ ) );
}

// Plugin url.
if ( ! defined( 'WPMUDEV_PLUGINTEST_URL' ) ) {
	define( 'WPMUDEV_PLUGINTEST_URL', plugin_dir_url( __FILE__ ) );
}

// Assets url.
if ( ! defined( 'WPMUDEV_PLUGINTEST_ASSETS_URL' ) ) {
	define( 'WPMUDEV_PLUGINTEST_ASSETS_URL', WPMUDEV_PLUGINTEST_URL . '/assets' );
}

// Shared UI Version.
if ( ! defined( 'WPMUDEV_PLUGINTEST_SUI_VERSION' ) ) {
	define( 'WPMUDEV_PLUGINTEST_SUI_VERSION', '2.12.23' );
}


/**
 * WPMUDEV_PluginTest class.
 */
class WPMUDEV_PluginTest {

	/**
	 * Holds the class instance.
	 *
	 * @var WPMUDEV_PluginTest $instance
	 */
	private static $instance = null;

	/**
	 * Return an instance of the class
	 *
	 * Return an instance of the WPMUDEV_PluginTest Class.
	 *
	 * @return WPMUDEV_PluginTest class instance.
	 * @since 1.0.0
	 *
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Class initializer.
	 */
	public function load() {
		load_plugin_textdomain(
			'wpmudev-plugin-test',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);

		WPMUDEV\PluginTest\Loader::instance();
	}
}

// Init the plugin and load the plugin instance for the first time.
add_action(
	'init',
	function () {
		WPMUDEV_PluginTest::get_instance()->load();
	},
	9
);
