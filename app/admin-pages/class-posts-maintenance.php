<?php
/**
 * Posts Maintenance admin page.
 *
 * @package WPMUDEV_PluginTest
 */

namespace WPMUDEV\PluginTest\App\Admin_Pages;

use WPMUDEV\PluginTest\Base;
use WPMUDEV\PluginTest\Posts_Maintenance_Service;

defined( 'WPINC' ) || die;

/**
 * Admin UI for Posts Maintenance.
 */
class Posts_Maintenance extends Base {

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	private $page_slug = 'wpmudev_plugintest_posts_maintenance';

	/**
	 * Hook suffix returned by add_menu_page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * React mount point ID.
	 *
	 * @var string
	 */
	private $dom_id = 'wpmudev_posts_maintenance_root';

	/**
	 * @var Posts_Maintenance_Service
	 */
	private $service;

	/**
	 * @var array
	 */
	private $script_data = array();

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->service = Posts_Maintenance_Service::instance();

		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register menu page.
	 *
	 * @return void
	 */
	public function register_admin_page(): void {
		$this->hook_suffix = add_menu_page(
			__( 'Posts Maintenance', 'wpmudev-plugin-test' ),
			__( 'Posts Maintenance', 'wpmudev-plugin-test' ),
			'manage_options',
			$this->page_slug,
			array( $this, 'render_page' ),
			'dashicons-clipboard',
			8
		);

		add_action( 'load-' . $this->hook_suffix, array( $this, 'prepare_script_data' ) );
	}

	/**
	 * Prepare localized data for the JS app.
	 *
	 * @return void
	 */
	public function prepare_script_data(): void {
		$post_types = $this->service->get_supported_post_types();

		$choices = array();
		foreach ( $post_types as $slug => $label ) {
			$choices[] = array(
				'value' => $slug,
				'label' => $label,
			);
		}

		$this->script_data = array(
			'domId'            => $this->dom_id,
			'nonce'            => wp_create_nonce( 'wp_rest' ),
			'endpoints'        => array(
				'status' => 'wpmudev/v1/posts-maintenance/status',
				'run'    => 'wpmudev/v1/posts-maintenance/run',
			),
			'postTypes'        => $choices,
			'defaultPostTypes' => $this->service->get_default_post_types(),
			'defaultBatch'     => Posts_Maintenance_Service::DEFAULT_BATCH_SIZE,
			'jobStatus'        => $this->service->get_status(),
			'strings'          => array(
				'pageTitle'       => __( 'Posts Maintenance', 'wpmudev-plugin-test' ),
				'pageDescription' => __( 'Scan public posts and pages in the background to update their maintenance timestamp.', 'wpmudev-plugin-test' ),
			),
		);
	}

	/**
	 * Enqueue assets for the page.
	 *
	 * @param string $hook Hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		$asset_path = WPMUDEV_PLUGINTEST_DIR . 'assets/js/posts-maintenance.min.asset.php';
		$deps       = array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' );
		$version    = WPMUDEV_PLUGINTEST_VERSION;

		if ( file_exists( $asset_path ) ) {
			$asset   = include $asset_path;
			$deps    = isset( $asset['dependencies'] ) ? $asset['dependencies'] : $deps;
			$version = isset( $asset['version'] ) ? $asset['version'] : $version;
		}

		wp_register_script(
			'wpmudev_posts_maintenance',
			WPMUDEV_PLUGINTEST_ASSETS_URL . '/js/posts-maintenance.min.js',
			$deps,
			$version,
			true
		);

		if ( empty( $this->script_data ) ) {
			$this->prepare_script_data();
		}

		wp_localize_script( 'wpmudev_posts_maintenance', 'wpmudevPostsMaintenance', $this->script_data );
		wp_enqueue_script( 'wpmudev_posts_maintenance' );
		wp_enqueue_style(
			'wpmudev_posts_maintenance',
			WPMUDEV_PLUGINTEST_ASSETS_URL . '/css/posts-maintenance.min.css',
			array(),
			$version
		);
	}

	/**
	 * Render page container.
	 *
	 * @return void
	 */
	public function render_page(): void {
		echo '<div class="wrap wpmudev-posts-maintenance"><div id="' . esc_attr( $this->dom_id ) . '"></div></div>';
	}
}
