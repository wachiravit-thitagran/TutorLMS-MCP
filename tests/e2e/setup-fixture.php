<?php
/**
 * Build deterministic Tutor LMS data for MCP end-to-end tests.
 *
 * Executed inside wp-env with:
 * wp eval-file tests/e2e/setup-fixture.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function tutorlms_mcp_e2e_user( string $login, string $email ): int {
	$user = get_user_by( 'login', $login );
	if ( $user ) {
		return (int) $user->ID;
	}

	$user_id = wp_create_user( $login, wp_generate_password( 32, true, true ), $email );
	if ( is_wp_error( $user_id ) ) {
		throw new RuntimeException( $user_id->get_error_message() );
	}

	$user = new WP_User( $user_id );
	$user->set_role( 'subscriber' );

	return (int) $user_id;
}

function tutorlms_mcp_e2e_app_password( int $user_id, string $name ): string {
	if ( ! class_exists( 'WP_Application_Passwords' ) ) {
		throw new RuntimeException( 'Application Passwords API is unavailable.' );
	}

	WP_Application_Passwords::delete_all_application_passwords( $user_id );

	$created = WP_Application_Passwords::create_new_application_password(
		$user_id,
		array( 'name' => $name )
	);

	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( $created->get_error_message() );
	}

	return (string) $created[0];
}

$admin = get_user_by( 'login', 'admin' );
if ( ! $admin ) {
	throw new RuntimeException( 'wp-env admin user was not found.' );
}

$student_a_id = tutorlms_mcp_e2e_user( 'mcp_student_a', 'mcp-student-a@example.test' );
$student_b_id = tutorlms_mcp_e2e_user( 'mcp_student_b', 'mcp-student-b@example.test' );

$course_id = wp_insert_post(
	array(
		'post_type'    => 'courses',
		'post_status'  => 'publish',
		'post_title'   => 'MCP E2E Published Course',
		'post_content' => '<p>MCP E2E course content.</p>',
		'post_author'  => (int) $admin->ID,
	),
	true
);
if ( is_wp_error( $course_id ) ) {
	throw new RuntimeException( $course_id->get_error_message() );
}

$draft_course_id = wp_insert_post(
	array(
		'post_type'    => 'courses',
		'post_status'  => 'draft',
		'post_title'   => 'MCP E2E Draft Course',
		'post_content' => '<p>This draft must not leak to learner MCP sessions.</p>',
		'post_author'  => (int) $admin->ID,
	),
	true
);
if ( is_wp_error( $draft_course_id ) ) {
	throw new RuntimeException( $draft_course_id->get_error_message() );
}

$topic_id = wp_insert_post(
	array(
		'post_type'   => 'topics',
		'post_status' => 'publish',
		'post_title'  => 'MCP E2E Topic',
		'post_parent' => (int) $course_id,
		'menu_order'  => 1,
	),
	true
);
if ( is_wp_error( $topic_id ) ) {
	throw new RuntimeException( $topic_id->get_error_message() );
}

$lesson_id = wp_insert_post(
	array(
		'post_type'   => 'lesson',
		'post_status' => 'publish',
		'post_title'  => 'MCP E2E Lesson',
		'post_parent' => (int) $topic_id,
		'menu_order'  => 1,
	),
	true
);
if ( is_wp_error( $lesson_id ) ) {
	throw new RuntimeException( $lesson_id->get_error_message() );
}

if ( ! function_exists( 'tutor_utils' ) || ! is_object( tutor_utils() ) || ! method_exists( tutor_utils(), 'do_enroll' ) ) {
	throw new RuntimeException( 'Tutor LMS enrollment API is unavailable.' );
}

$enrolled = tutor_utils()->do_enroll( (int) $course_id, 0, $student_a_id );
if ( false === $enrolled ) {
	throw new RuntimeException( 'Could not enroll MCP E2E student.' );
}

$fixture = array(
	'admin' => array(
		'id'       => (int) $admin->ID,
		'login'    => $admin->user_login,
		'app_pass' => tutorlms_mcp_e2e_app_password( (int) $admin->ID, 'TutorLMS MCP E2E Admin' ),
	),
	'student_a' => array(
		'id'       => $student_a_id,
		'login'    => 'mcp_student_a',
		'app_pass' => tutorlms_mcp_e2e_app_password( $student_a_id, 'TutorLMS MCP E2E Student A' ),
	),
	'student_b' => array(
		'id'       => $student_b_id,
		'login'    => 'mcp_student_b',
		'app_pass' => tutorlms_mcp_e2e_app_password( $student_b_id, 'TutorLMS MCP E2E Student B' ),
	),
	'course_id'       => (int) $course_id,
	'draft_course_id' => (int) $draft_course_id,
	'topic_id'        => (int) $topic_id,
	'lesson_id'       => (int) $lesson_id,
);

$file = WP_CONTENT_DIR . '/mcp-e2e-fixture.json';
if ( false === file_put_contents( $file, wp_json_encode( $fixture ) ) ) {
	throw new RuntimeException( 'Could not write MCP E2E fixture file.' );
}

echo "TutorLMS MCP E2E fixture created.\n";
