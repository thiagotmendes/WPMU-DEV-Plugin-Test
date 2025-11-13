<?php
/**
 * REST endpoints for Posts Maintenance.
 *
 * @package WPMUDEV_PluginTest
 */

namespace WPMUDEV\PluginTest\Endpoints\V1;

defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;
use WPMUDEV\PluginTest\Posts_Maintenance_Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Provides routes to kick off scans and fetch their status.
 */
class Posts_Maintenance extends Base {

	/**
	 * @var Posts_Maintenance_Service
	 */
	private $service;

	/**
	 * Boot endpoints.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->service = Posts_Maintenance_Service::instance();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'wpmudev/v1',
			'/posts-maintenance/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_scan' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			'wpmudev/v1',
			'/posts-maintenance/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Permission callback (manage_options).
	 *
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to manage posts maintenance.', 'wpmudev-plugin-test' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Starts a scan job.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_scan( WP_REST_Request $request ) {
		$params     = $request->get_json_params();
		$post_types = $params['post_types'] ?? array();
		$batch      = isset( $params['batch_size'] ) ? absint( $params['batch_size'] ) : Posts_Maintenance_Service::DEFAULT_BATCH_SIZE;

		$job = $this->service->start_scan(
			array(
				'post_types' => $post_types,
				'batch_size' => $batch,
			),
			'admin',
			true
		);

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return new WP_REST_Response( $job );
	}

	/**
	 * Returns current status payload.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		return new WP_REST_Response(
			$this->service->get_status()
		);
	}
}
