<?php
/**
 * Plugin Name: TutorLMS MCP
 * Plugin URI: https://github.com/wachiravit-thitagran/TutorLMS-MCP
 * Description: Exposes Tutor LMS functionality through the WordPress Abilities API for MCP Adapter.
 * Version: 0.1.0
 * Author: Wachiravit Thitagran
 * License: GPL-2.0-or-later
 * Requires at least: 6.9
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TUTORLMS_MCP_VERSION', '0.1.0' );
define( 'TUTORLMS_MCP_FILE', __FILE__ );
define( 'TUTORLMS_MCP_DIR', plugin_dir_path( __FILE__ ) );

require_once TUTORLMS_MCP_DIR . 'includes/class-tutorlms-mcp.php';

add_action(
	'plugins_loaded',
	static function () {
		\TutorLMS_MCP\Plugin::instance()->boot();
	}
);
