<?php

namespace TutorLMS_MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'admin_notices', array( $this, 'dependency_notices' ) );

		if ( ! $this->requirements_met() ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	private function requirements_met(): bool {
		return function_exists( 'wp_register_ability' )
			&& function_exists( 'wp_register_ability_category' )
			&& ( function_exists( 'tutor' ) || function_exists( 'tutor_lms' ) || defined( 'TUTOR_VERSION' ) );
	}

	public function dependency_notices(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			echo '<div class="notice notice-error"><p><strong>TutorLMS MCP:</strong> WordPress Abilities API is unavailable. WordPress 6.9+ is required.</p></div>';
		}

		if ( ! ( function_exists( 'tutor' ) || function_exists( 'tutor_lms' ) || defined( 'TUTOR_VERSION' ) ) ) {
			echo '<div class="notice notice-error"><p><strong>TutorLMS MCP:</strong> Tutor LMS must be installed and active.</p></div>';
		}
	}

	public function register_category(): void {
		wp_register_ability_category(
			'tutorlms',
			array(
				'label'       => 'Tutor LMS',
				'description' => 'Tutor LMS management and reporting abilities.',
			)
		);
	}

	public function register_abilities(): void {
		$this->register_site_info();
		$this->register_list_courses();
		$this->register_get_course();
		$this->register_course_structure();
		$this->register_student_progress();
	}

	private function common_meta(): array {
		return array(
			'mcp' => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'      => true,
				'destructive'   => false,
				'idempotent'    => true,
				'openWorldHint' => false,
			),
		);
	}

	private function can_read(): bool {
		return is_user_logged_in() && current_user_can( 'read' );
	}

	private function register_site_info(): void {
		wp_register_ability(
			'tutorlms/site-info',
			array(
				'label'               => 'Tutor LMS Site Info',
				'description'         => 'Returns Tutor LMS and environment information for capability discovery.',
				'category'            => 'tutorlms',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback'    => array( $this, 'site_info' ),
				'permission_callback' => array( $this, 'can_read_ability' ),
				'meta'                => $this->common_meta(),
			)
		);
	}

	public function can_read_ability(): bool {
		return $this->can_read();
	}

	public function site_info(): array {
		return array(
			'tutor_lms_version' => defined( 'TUTOR_VERSION' ) ? TUTOR_VERSION : null,
			'wordpress_version' => get_bloginfo( 'version' ),
			'site_url'          => get_site_url(),
			'course_post_type'  => 'courses',
			'mcp_namespace'     => 'tutorlms',
		);
	}

	private function register_list_courses(): void {
		wp_register_ability(
			'tutorlms/list-courses',
			array(
				'label'       => 'List Tutor LMS Courses',
				'description' => 'Lists Tutor LMS courses with pagination and optional search.',
				'category'    => 'tutorlms',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'search' => array(
							'type' => 'string',
						),
						'status' => array(
							'type'    => 'string',
							'default' => 'publish',
						),
					),
				),
				'execute_callback'    => array( $this, 'list_courses' ),
				'permission_callback' => array( $this, 'can_read_ability' ),
				'meta'                => $this->common_meta(),
			)
		);
	}

	public function list_courses( array $input ): array {
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
		$status   = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'publish';
		$search   = isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '';

		if ( 'any' === $status && ! current_user_can( 'edit_posts' ) ) {
			$status = 'publish';
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'courses',
				'post_status'    => $status,
				'posts_per_page' => $per_page,
				'paged'          => $page,
				's'              => $search,
			)
		);

		$courses = array_map(
			static function ( \WP_Post $post ): array {
				return array(
					'id'        => $post->ID,
					'title'     => get_the_title( $post ),
					'status'    => $post->post_status,
					'author_id' => (int) $post->post_author,
					'url'       => get_permalink( $post ),
				);
			},
			$query->posts
		);

		return array(
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'courses'     => $courses,
		);
	}

	private function register_get_course(): void {
		wp_register_ability(
			'tutorlms/get-course',
			array(
				'label'       => 'Get Tutor LMS Course',
				'description' => 'Returns a Tutor LMS course by ID.',
				'category'    => 'tutorlms',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'course_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required' => array( 'course_id' ),
				),
				'execute_callback'    => array( $this, 'get_course' ),
				'permission_callback' => array( $this, 'can_read_ability' ),
				'meta'                => $this->common_meta(),
			)
		);
	}

	public function get_course( array $input ) {
		$course_id = (int) $input['course_id'];
		$post      = get_post( $course_id );

		if ( ! $post || 'courses' !== $post->post_type ) {
			return new \WP_Error( 'tutorlms_mcp_course_not_found', 'Tutor LMS course not found.' );
		}

		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $course_id ) ) {
			return new \WP_Error( 'tutorlms_mcp_forbidden', 'You do not have permission to read this course.' );
		}

		$thumbnail = get_the_post_thumbnail_url( $post, 'full' );

		return array(
			'id'        => $post->ID,
			'title'     => get_the_title( $post ),
			'content'   => apply_filters( 'the_content', $post->post_content ),
			'excerpt'   => $post->post_excerpt,
			'status'    => $post->post_status,
			'author_id' => (int) $post->post_author,
			'url'       => get_permalink( $post ),
			'thumbnail' => $thumbnail ? $thumbnail : null,
		);
	}

	private function register_course_structure(): void {
		wp_register_ability(
			'tutorlms/get-course-structure',
			array(
				'label'       => 'Get Tutor LMS Course Structure',
				'description' => 'Returns topics and child content for a Tutor LMS course.',
				'category'    => 'tutorlms',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'course_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required' => array( 'course_id' ),
				),
				'execute_callback'    => array( $this, 'get_course_structure' ),
				'permission_callback' => array( $this, 'can_read_ability' ),
				'meta'                => $this->common_meta(),
			)
		);
	}

	public function get_course_structure( array $input ) {
		$course_id = (int) $input['course_id'];
		$course    = get_post( $course_id );

		if ( ! $course || 'courses' !== $course->post_type ) {
			return new \WP_Error( 'tutorlms_mcp_course_not_found', 'Tutor LMS course not found.' );
		}

		$topics = get_posts(
			array(
				'post_type'      => 'topics',
				'post_parent'    => $course_id,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
			)
		);

		$structure = array();

		foreach ( $topics as $topic ) {
			$children = get_posts(
				array(
					'post_type'      => array( 'lesson', 'tutor_quiz', 'tutor_assignments' ),
					'post_parent'    => $topic->ID,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'orderby'        => array(
						'menu_order' => 'ASC',
						'ID'         => 'ASC',
					),
				)
			);

			$structure[] = array(
				'id'       => $topic->ID,
				'title'    => get_the_title( $topic ),
				'children' => array_map(
					static function ( \WP_Post $child ): array {
						return array(
							'id'        => $child->ID,
							'title'     => get_the_title( $child ),
							'post_type' => $child->post_type,
							'status'    => $child->post_status,
						);
					},
					$children
				),
			);
		}

		return array(
			'course_id' => $course_id,
			'title'     => get_the_title( $course ),
			'topics'    => $structure,
		);
	}

	private function register_student_progress(): void {
		wp_register_ability(
			'tutorlms/get-student-progress',
			array(
				'label'       => 'Get Tutor LMS Student Progress',
				'description' => 'Returns course progress for a student when Tutor LMS progress helpers are available.',
				'category'    => 'tutorlms',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'course_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'user_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required' => array( 'course_id', 'user_id' ),
				),
				'execute_callback'    => array( $this, 'get_student_progress' ),
				'permission_callback' => array( $this, 'can_read_progress' ),
				'meta'                => $this->common_meta(),
			)
		);
	}

	public function can_read_progress( array $input ): bool {
		$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;

		return get_current_user_id() === $user_id
			|| current_user_can( 'edit_users' )
			|| current_user_can( 'manage_options' );
	}

	public function get_student_progress( array $input ): array {
		$course_id = (int) $input['course_id'];
		$user_id   = (int) $input['user_id'];

		$progress = null;

		if ( function_exists( 'tutor_utils' ) ) {
			$utils = tutor_utils();

			if ( is_object( $utils ) && method_exists( $utils, 'get_course_completed_percent' ) ) {
				$progress = $utils->get_course_completed_percent( $course_id, $user_id );
			}
		}

		return array(
			'course_id'        => $course_id,
			'user_id'          => $user_id,
			'progress_percent' => null !== $progress ? (float) $progress : null,
			'available'        => null !== $progress,
		);
	}
}
