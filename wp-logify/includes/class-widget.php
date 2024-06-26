<?php
/**
 * Contains the Widget class.
 *
 * @package WP_Logify
 */

namespace WP_Logify;

use WP_User;

/**
 * Contains methods relating to the dashboard widget.
 */
class Widget {

	/**
	 * Initializes the class.
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_dashboard_widget' ) );
	}

	/**
	 * Adds the dashboard widget.
	 */
	public static function add_dashboard_widget() {
		// Check current user has access.
		if ( ! Users::current_user_has_role( Settings::get_view_roles() ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wp_logify_dashboard_widget',
			'WP Logify - Recent Site Activity',
			array( __CLASS__, 'display_dashboard_widget' )
		);
	}

	/**
	 * Displays the dashboard widget.
	 */
	public static function display_dashboard_widget() {
		include plugin_dir_path( __FILE__ ) . '../templates/dashboard-widget.php';
	}
}
