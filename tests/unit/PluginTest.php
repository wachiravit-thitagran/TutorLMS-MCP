<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TutorLMS_MCP\Plugin;

final class PluginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		unset(
			$GLOBALS['tutorlms_mcp_registered_abilities'],
			$GLOBALS['tutorlms_mcp_registered_category'],
			$GLOBALS['tutorlms_mcp_last_query_args'],
			$GLOBALS['tutorlms_mcp_query_posts'],
			$GLOBALS['tutorlms_mcp_found_posts'],
			$GLOBALS['tutorlms_mcp_max_pages'],
			$GLOBALS['tutorlms_mcp_tutor_utils']
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function capture_registrations(): void {
		$GLOBALS['tutorlms_mcp_registered_abilities'] = array();

		Functions\when( 'wp_register_ability_category' )->alias(
			static function ( $name, $args ) {
				$GLOBALS['tutorlms_mcp_registered_category'] = array(
					'name' => $name,
					'args' => $args,
				);
				return true;
			}
		);

		Functions\when( 'wp_register_ability' )->alias(
			static function ( $name, $args ) {
				$GLOBALS['tutorlms_mcp_registered_abilities'][ $name ] = $args;
				return true;
			}
		);
	}

	private function stub_course_output_functions(): void {
		Functions\when( 'get_the_title' )->alias(
			static function ( $post ) {
				$id = is_object( $post ) ? $post->ID : (int) $post;
				return 'Course ' . $id;
			}
		);
		Functions\when( 'get_permalink' )->alias(
			static function ( $post ) {
				$id = is_object( $post ) ? $post->ID : (int) $post;
				return 'https://example.test/course/' . $id;
			}
		);
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

	public function test_register_category_uses_tutorlms_namespace(): void {
		$this->capture_registrations();

		Plugin::instance()->register_category();

		$this->assertSame( 'tutorlms', $GLOBALS['tutorlms_mcp_registered_category']['name'] );
		$this->assertSame( 'Tutor LMS', $GLOBALS['tutorlms_mcp_registered_category']['args']['label'] );
	}

	public function test_registers_all_initial_abilities_with_mcp_public_metadata(): void {
		$this->capture_registrations();

		Plugin::instance()->register_abilities();

		$abilities = $GLOBALS['tutorlms_mcp_registered_abilities'];
		$this->assertSame(
			array(
				'tutorlms/site-info',
				'tutorlms/list-courses',
				'tutorlms/get-course',
				'tutorlms/get-course-structure',
				'tutorlms/get-student-progress',
			),
			array_keys( $abilities )
		);

		foreach ( $abilities as $args ) {
			$this->assertTrue( $args['meta']['mcp']['public'] );
			$this->assertSame( 'tool', $args['meta']['mcp']['type'] );
			$this->assertTrue( $args['meta']['annotations']['readonly'] );
			$this->assertFalse( $args['meta']['annotations']['destructive'] );
			$this->assertTrue( $args['meta']['annotations']['idempotent'] );
			$this->assertFalse( $args['meta']['annotations']['openWorldHint'] );
			$this->assertIsCallable( $args['execute_callback'] );
			$this->assertIsCallable( $args['permission_callback'] );
		}
	}

	public function test_registered_input_schemas_enforce_course_and_user_ids(): void {
		$this->capture_registrations();
		Plugin::instance()->register_abilities();

		$abilities = $GLOBALS['tutorlms_mcp_registered_abilities'];

		$this->assertSame( 1, $abilities['tutorlms/get-course']['input_schema']['properties']['course_id']['minimum'] );
		$this->assertSame( array( 'course_id' ), $abilities['tutorlms/get-course']['input_schema']['required'] );
		$this->assertSame( 100, $abilities['tutorlms/list-courses']['input_schema']['properties']['per_page']['maximum'] );
		$this->assertSame(
			array( 'course_id', 'user_id' ),
			$abilities['tutorlms/get-student-progress']['input_schema']['required']
		);
	}

	public function test_can_read_ability_requires_login_and_read_capability(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertTrue( Plugin::instance()->can_read_ability() );
	}

	public function test_can_read_ability_rejects_guest(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertFalse( Plugin::instance()->can_read_ability() );
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

	public function test_can_read_progress_rejects_other_student(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 10 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse(
			Plugin::instance()->can_read_progress(
				array(
					'user_id'   => 11,
					'course_id' => 99,
				)
			)
		);
	}

	public function test_can_read_progress_allows_user_manager(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 10 );
		Functions\when( 'current_user_can' )->alias(
			static function ( $capability ) {
				return 'edit_users' === $capability;
			}
		);

		$this->assertTrue(
			Plugin::instance()->can_read_progress(
				array(
					'user_id'   => 11,
					'course_id' => 99,
				)
			)
		);
	}

	public function test_list_courses_applies_defaults_and_returns_pagination(): void {
		$this->stub_course_output_functions();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( false );

		$GLOBALS['tutorlms_mcp_query_posts'] = array(
			new WP_Post( array( 'ID' => 7, 'post_type' => 'courses', 'post_author' => 2 ) ),
		);
		$GLOBALS['tutorlms_mcp_found_posts'] = 41;
		$GLOBALS['tutorlms_mcp_max_pages']  = 3;

		$result = Plugin::instance()->list_courses( array() );

		$this->assertSame( 1, $result['page'] );
		$this->assertSame( 20, $result['per_page'] );
		$this->assertSame( 41, $result['total'] );
		$this->assertSame( 3, $result['total_pages'] );
		$this->assertSame( 'publish', $GLOBALS['tutorlms_mcp_last_query_args']['post_status'] );
		$this->assertSame( 7, $result['courses'][0]['id'] );
	}

	public function test_list_courses_clamps_pagination_limits(): void {
		$this->stub_course_output_functions();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = Plugin::instance()->list_courses(
			array(
				'page'     => -5,
				'per_page' => 5000,
			)
		);

		$this->assertSame( 1, $result['page'] );
		$this->assertSame( 100, $result['per_page'] );
		$this->assertSame( 1, $GLOBALS['tutorlms_mcp_last_query_args']['paged'] );
		$this->assertSame( 100, $GLOBALS['tutorlms_mcp_last_query_args']['posts_per_page'] );
	}

	public function test_list_courses_forces_publish_when_non_editor_requests_any(): void {
		$this->stub_course_output_functions();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( false );

		Plugin::instance()->list_courses( array( 'status' => 'any' ) );

		$this->assertSame( 'publish', $GLOBALS['tutorlms_mcp_last_query_args']['post_status'] );
	}

	public function test_list_courses_allows_editor_to_request_any_status(): void {
		$this->stub_course_output_functions();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );

		Plugin::instance()->list_courses( array( 'status' => 'any' ) );

		$this->assertSame( 'any', $GLOBALS['tutorlms_mcp_last_query_args']['post_status'] );
	}

	public function test_list_courses_forces_publish_when_non_editor_requests_draft(): void {
		$this->stub_course_output_functions();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( false );

		Plugin::instance()->list_courses( array( 'status' => 'draft' ) );

		$this->assertSame( 'publish', $GLOBALS['tutorlms_mcp_last_query_args']['post_status'] );
	}

	public function test_get_course_rejects_missing_or_wrong_post_type(): void {
		Functions\when( 'get_post' )->justReturn(
			new WP_Post(
				array(
					'ID'        => 9,
					'post_type' => 'post',
				)
			)
		);

		$result = Plugin::instance()->get_course( array( 'course_id' => 9 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'tutorlms_mcp_course_not_found', $result->get_error_code() );
	}

	public function test_get_course_blocks_unpublished_course_without_edit_permission(): void {
		Functions\when( 'get_post' )->justReturn(
			new WP_Post(
				array(
					'ID'          => 9,
					'post_type'   => 'courses',
					'post_status' => 'draft',
				)
			)
		);
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = Plugin::instance()->get_course( array( 'course_id' => 9 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'tutorlms_mcp_forbidden', $result->get_error_code() );
	}

	public function test_get_course_returns_normalized_course_data(): void {
		$post = new WP_Post(
			array(
				'ID'           => 9,
				'post_type'    => 'courses',
				'post_status'  => 'publish',
				'post_author'  => '4',
				'post_content' => '<p>บทเรียน</p>',
				'post_excerpt' => 'ย่อ',
			)
		);

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
		Functions\when( 'get_the_title' )->justReturn( 'หลักสูตรทดสอบ' );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				return 'the_content' === $tag ? $value : $value;
			}
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/course/9' );

		$result = Plugin::instance()->get_course( array( 'course_id' => 9 ) );

		$this->assertSame( 9, $result['id'] );
		$this->assertSame( 4, $result['author_id'] );
		$this->assertSame( 'หลักสูตรทดสอบ', $result['title'] );
		$this->assertSame( '<p>บทเรียน</p>', $result['content'] );
		$this->assertNull( $result['thumbnail'] );
	}

	public function test_get_course_structure_rejects_unknown_course(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$result = Plugin::instance()->get_course_structure( array( 'course_id' => 404 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'tutorlms_mcp_course_not_found', $result->get_error_code() );
	}

	public function test_get_course_structure_blocks_unpublished_course_without_edit_permission(): void {
		Functions\when( 'get_post' )->justReturn(
			new WP_Post(
				array(
					'ID'          => 19,
					'post_type'   => 'courses',
					'post_status' => 'draft',
				)
			)
		);
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = Plugin::instance()->get_course_structure( array( 'course_id' => 19 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'tutorlms_mcp_forbidden', $result->get_error_code() );
	}

	public function test_get_course_structure_returns_ordered_topics_and_children(): void {
		$course = new WP_Post( array( 'ID' => 10, 'post_type' => 'courses' ) );
		$topic  = new WP_Post( array( 'ID' => 20, 'post_type' => 'topics', 'post_parent' => 10 ) );
		$lesson = new WP_Post( array( 'ID' => 30, 'post_type' => 'lesson', 'post_parent' => 20, 'post_status' => 'publish' ) );
		$quiz   = new WP_Post( array( 'ID' => 31, 'post_type' => 'tutor_quiz', 'post_parent' => 20, 'post_status' => 'publish' ) );

		Functions\when( 'get_post' )->justReturn( $course );
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) use ( $topic, $lesson, $quiz ) {
				return 'topics' === $args['post_type'] ? array( $topic ) : array( $lesson, $quiz );
			}
		);
		Functions\when( 'get_the_title' )->alias(
			static function ( $post ) {
				return 'Item ' . $post->ID;
			}
		);

		$result = Plugin::instance()->get_course_structure( array( 'course_id' => 10 ) );

		$this->assertSame( 10, $result['course_id'] );
		$this->assertCount( 1, $result['topics'] );
		$this->assertSame( 20, $result['topics'][0]['id'] );
		$this->assertSame( 'lesson', $result['topics'][0]['children'][0]['post_type'] );
		$this->assertSame( 'tutor_quiz', $result['topics'][0]['children'][1]['post_type'] );
	}

	public function test_student_progress_reports_unavailable_without_compatible_tutor_utils(): void {
		$GLOBALS['tutorlms_mcp_tutor_utils'] = null;

		$result = Plugin::instance()->get_student_progress(
			array(
				'course_id' => 5,
				'user_id'   => 6,
			)
		);

		$this->assertFalse( $result['available'] );
		$this->assertNull( $result['progress_percent'] );
	}

	public function test_student_progress_preserves_zero_percent_as_available(): void {
		$GLOBALS['tutorlms_mcp_tutor_utils'] = new class {
			public function get_course_completed_percent( $course_id, $user_id ) {
				return 0;
			}
		};

		$result = Plugin::instance()->get_student_progress(
			array(
				'course_id' => 5,
				'user_id'   => 6,
			)
		);

		$this->assertTrue( $result['available'] );
		$this->assertSame( 0.0, $result['progress_percent'] );
	}

	public function test_student_progress_casts_numeric_progress_to_float(): void {
		$GLOBALS['tutorlms_mcp_tutor_utils'] = new class {
			public function get_course_completed_percent( $course_id, $user_id ) {
				return '37.5';
			}
		};

		$result = Plugin::instance()->get_student_progress(
			array(
				'course_id' => 5,
				'user_id'   => 6,
			)
		);

		$this->assertTrue( $result['available'] );
		$this->assertSame( 37.5, $result['progress_percent'] );
	}
}
