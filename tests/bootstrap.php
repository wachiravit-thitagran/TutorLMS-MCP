<?php

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 0;
		public $post_type = 'post';
		public $post_status = 'publish';
		public $post_author = 0;
		public $post_content = '';
		public $post_excerpt = '';
		public $post_parent = 0;
		public $menu_order = 0;

		public function __construct( array $data = array() ) {
			foreach ( $data as $key => $value ) {
				$this->{$key} = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public $posts = array();
		public $found_posts = 0;
		public $max_num_pages = 0;

		public function __construct( $args = array() ) {
			$GLOBALS['tutorlms_mcp_last_query_args'] = $args;
			$this->posts         = $GLOBALS['tutorlms_mcp_query_posts'] ?? array();
			$this->found_posts   = $GLOBALS['tutorlms_mcp_found_posts'] ?? count( $this->posts );
			$this->max_num_pages = $GLOBALS['tutorlms_mcp_max_pages'] ?? ( $this->found_posts ? 1 : 0 );
		}
	}
}

if ( ! function_exists( 'tutor_utils' ) ) {
	function tutor_utils() {
		return $GLOBALS['tutorlms_mcp_tutor_utils'] ?? null;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-tutorlms-mcp.php';
