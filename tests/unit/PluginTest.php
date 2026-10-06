<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TutorLMS_MCP\Plugin;

final class PluginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_singleton_returns_same_instance(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_site_info_returns_expected_environment_data(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );
		Functions\when( 'get_site_url' )->justReturn( 'https://example.test' );

		$result = Plugin::instance()->site_info();

		$this->assertSame( '6.9', $result['wordpress_version'] );
		$this->assertSame( 'https://example.test', $result['site_url'] );
		$this->assertSame( 'courses', $result['course_post_type'] );
		$this->assertSame( 'tutorlms', $result['mcp_namespace'] );
	}

	public function test_can_read_progress_allows_user_to_read_own_progress(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 10 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertTrue(
			Plugin::instance()->can_read_progress(
				array(
					'user_id'   => 10,
					'course_id' => 99,
				)
			)
		);
	}
}
