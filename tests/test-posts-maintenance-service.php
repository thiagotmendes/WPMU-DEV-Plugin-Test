<?php

use WPMUDEV\PluginTest\Posts_Maintenance_Service;

class Test_Posts_Maintenance_Service extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$service = Posts_Maintenance_Service::instance();
		$service->clear_job();
		delete_option( Posts_Maintenance_Service::OPTION_LAST_SUMMARY );
	}

	protected function tearDown(): void {
		Posts_Maintenance_Service::instance()->clear_job();
		parent::tearDown();
	}

	public function test_run_sync_scan_updates_meta() {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
			)
		);

		$service = Posts_Maintenance_Service::instance();
		$result  = $service->start_scan(
			array(
				'post_types' => array( 'post' ),
				'batch_size' => 20,
			),
			'test',
			false
		);

		$this->assertNotWPError( $result );
		$this->assertEquals( 1, $result['processed'] );

		$meta = get_post_meta( $post_id, Posts_Maintenance_Service::META_KEY, true );
		$this->assertNotEmpty( $meta );

		$summary = $service->get_last_summary();
		$this->assertEquals( 1, $summary['processed'] );
	}

	public function test_scan_respects_post_type_filters() {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$page_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);

		$service = Posts_Maintenance_Service::instance();
		$result  = $service->start_scan(
			array(
				'post_types' => array( 'page' ),
				'batch_size' => 25,
			),
			'test',
			false
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['processed'] );
		$this->assertNotEmpty( get_post_meta( $page_id, Posts_Maintenance_Service::META_KEY, true ) );
		$this->assertEmpty( get_post_meta( $post_id, Posts_Maintenance_Service::META_KEY, true ) );
	}

	public function test_scan_returns_error_when_no_valid_post_types() {
		$filter = static function () {
			return array();
		};

		add_filter( 'wpmudev_posts_maintenance_post_types', $filter );

		$service = Posts_Maintenance_Service::instance();
		$result  = $service->start_scan(
			array(
				'post_types' => array( 'does-not-exist' ),
			),
			'test',
			false
		);

		remove_filter( 'wpmudev_posts_maintenance_post_types', $filter );

		$this->assertWPError( $result );
		$this->assertSame( 'wpmudev_posts_scan_no_types', $result->get_error_code() );
	}

	public function test_async_scan_blocks_concurrent_jobs() {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
			)
		);

		$service = Posts_Maintenance_Service::instance();
		$first   = $service->start_scan(
			array(
				'post_types' => array( 'post' ),
			),
			'admin',
			true
		);

		$this->assertNotWPError( $first );
		$this->assertEquals( 'queued', $first['status'] );

		$second = $service->start_scan(
			array(
				'post_types' => array( 'post' ),
			),
			'admin',
			true
		);

		$this->assertWPError( $second );
		$this->assertSame( 'wpmudev_posts_scan_running', $second->get_error_code() );
	}

	public function test_scan_without_posts_records_summary() {
		register_post_type(
			'book',
			array(
				'label'        => 'Book',
				'public'       => true,
				'show_in_rest' => true,
			)
		);

		$service = Posts_Maintenance_Service::instance();
		$result  = $service->start_scan(
			array(
				'post_types' => array( 'book' ),
			),
			'cron',
			false
		);

		unregister_post_type( 'book' );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'completed', $result['status'] );
		$this->assertEquals( 0, $result['processed'] );

		$summary = $service->get_last_summary();
		$this->assertEquals( array( 'book' ), $summary['post_types'] );
		$this->assertEquals( 0, $summary['processed'] );
	}

	public function test_batch_size_is_sanitized() {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
			)
		);

		$service = Posts_Maintenance_Service::instance();

		$small = $service->start_scan(
			array(
				'post_types' => array( 'post' ),
				'batch_size' => 1,
			),
			'test',
			false
		);

		$this->assertEquals( 10, $small['batch_size'] );

		$large = $service->start_scan(
			array(
				'post_types' => array( 'post' ),
				'batch_size' => 999,
			),
			'test',
			false
		);

		$this->assertEquals( 200, $large['batch_size'] );
	}
}
