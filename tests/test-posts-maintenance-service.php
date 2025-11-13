<?php

use WPMUDEV\PluginTest\Posts_Maintenance_Service;

class Test_Posts_Maintenance_Service extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$service = Posts_Maintenance_Service::instance();
		$service->clear_job();
		delete_option( Posts_Maintenance_Service::OPTION_LAST_SUMMARY );
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
}
