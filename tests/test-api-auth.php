<?php

class TestAPIAuth extends WP_Test_REST_TestCase {

	private $admin_id;

	protected function setUp(): void {
		parent::setUp();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function test_get_auth_url() {
		$request  = new WP_REST_Request( 'POST', '/wpmudev/v1/drive/auth' );
		$response = rest_get_server()->dispatch( $request );
		$error    = $response->as_error();

		$this->assertWPError( $error );
		$this->assertSame( 'wpmudev_drive_missing_credentials', $error->get_error_code() );
	}
}
