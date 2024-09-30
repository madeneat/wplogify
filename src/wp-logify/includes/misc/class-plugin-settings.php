<?php
/**
 * Contains the Plugin_Settings class.
 *
 * @package WP_Logify
 */

namespace WP_Logify;

/**
 * Class WP_Logify\Plugin_Settings
 *
 * This class encapsulates properties and methods relating to plugin settings.
 */
class Plugin_Settings {

	// =============================================================================================
	// Default values for settings.

	/**
	 * The default value for the 'delete on uninstall' setting.
	 *
	 * @var bool
	 */
	private const DEFAULT_DELETE_ON_UNINSTALL = false;

	/**
	 * The default value for the 'roles with access' setting.
	 *
	 * @var array
	 */
	private const DEFAULT_ROLES_WITH_ACCESS = array( 'administrator' );

	/**
	 * The default value for the 'users with access' setting.
	 *
	 * @var array
	 */
	private const DEFAULT_USERS_WITH_ACCESS = array();

	/**
	 * The default value for the 'show in admin bar' setting.
	 *
	 * @var bool
	 */
	private const DEFAULT_SHOW_IN_ADMIN_BAR = true;

	/**
	 * The default value for the 'keep period quantity' setting.
	 *
	 * @var int
	 */
	private const DEFAULT_KEEP_PERIOD_QUANTITY = 12;

	/**
	 * The default value for the 'keep period units' setting.
	 *
	 * @var string
	 */
	private const DEFAULT_KEEP_PERIOD_UNITS = 'month';

	/**
	 * Get the names of all current roles.
	 * These will be the default value for the 'roles to track' setting.
	 *
	 * @return array
	 */
	private static function get_roles() {
		return array_keys( wp_roles()->roles );
	}

	// ---------------------------------------------------------------------------------------------
	// Functions associated with action hooks.

	/**
	 * Initializes the class by adding WordPress actions.
	 */
	public static function init() {
		// Register the settings.
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Registers the settings for the WP Logify plugin.
	 */
	public static function register_settings() {
		register_setting(
			'wp_logify_settings_group',
			'wp_logify_delete_on_uninstall',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => self::DEFAULT_DELETE_ON_UNINSTALL,
			)
		);
		register_setting(
			'wp_logify_settings_group',
			'wp_logify_roles_to_track',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_roles' ),
				'default'           => self::get_roles(),
			)
		);
		register_setting(
			'wp_logify_settings_group',
			'wp_logify_roles_with_access',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_roles' ),
				'default'           => self::DEFAULT_ROLES_WITH_ACCESS,
			)
		);
		register_setting(
			'wp_logify_settings_group',
			'wp_logify_users_with_access',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_users' ),
				'default'           => self::DEFAULT_USERS_WITH_ACCESS,
			)
		);
		register_setting(
			'wp_logify_settings_group',
			'wp_logify_show_in_admin_bar',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => self::DEFAULT_SHOW_IN_ADMIN_BAR,
			)
		);
		register_setting(
			'wp_logify_settings_group',
			'wp_logify_keep_period_quantity',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => self::DEFAULT_KEEP_PERIOD_QUANTITY,
			)
		);
		register_setting(
			'wp_logify_settings_group',
			'wp_logify_keep_period_units',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => self::DEFAULT_KEEP_PERIOD_UNITS,
			)
		);
	}

	/**
	 * Deletes all settings options for the WP Logify plugin.
	 */
	public static function delete_all() {
		// Options that are registered settings.
		delete_option( 'wp_logify_api_key' );
		delete_option( 'wp_logify_delete_on_uninstall' );
		delete_option( 'wp_logify_roles_to_track' );
		delete_option( 'wp_logify_roles_with_access' );
		delete_option( 'wp_logify_users_with_access' );
		delete_option( 'wp_logify_keep_forever' );
		delete_option( 'wp_logify_keep_period_quantity' );
		delete_option( 'wp_logify_keep_period_units' );
		delete_option( 'wp_logify_wp_cron_tracking' );

		// Additional options used by the plugin that aren't settings.
		delete_option( 'wp_logify_known_taxonomies' );
		delete_option( 'wp_logify_object_subtypes_set' );
	}

	/**
	 * Displays the settings page for the WP Logify plugin.
	 */
	public static function display_settings_page() {
		if ( ! Access_Control::can_access_settings_page() ) {
			// Disallow access.
			wp_die( __( 'Sorry, you are not allowed to access this page.', 'wp-logify' ), 403 );
		}

		// Include the settings-page.php template file to render the settings page.
		include WP_LOGIFY_PLUGIN_DIR . 'templates/settings-page.php';
	}

	// ---------------------------------------------------------------------------------------------
	// Sanitize functions.

	/**
	 * Sanitizes the given array of roles by filtering out any invalid roles.
	 *
	 * @param ?array $roles The array of roles to be sanitized.
	 * @return array The sanitized array of roles.
	 */
	public static function sanitize_roles( ?array $roles ): array {
		// Handle null or empty array.
		if ( empty( $roles ) ) {
			return array();
		}

		$valid_roles = self::get_roles();
		return array_intersect( $roles, $valid_roles );
	}

	/**
	 * Sanitizes the given array of user IDs by filtering out any invalid user IDs.
	 *
	 * @param ?array $user_ids The array of user IDs to be sanitized.
	 * @return array The sanitized array of user IDs.
	 */
	public static function sanitize_users( ?array $user_ids ) {
		// Handle null or empty array.
		if ( empty( $user_ids ) ) {
			return array();
		}

		// Make sure the array of user IDs only contains integers.
		$user_ids = array_map( 'intval', $user_ids );

		// Get the IDs of all users, and make sure the array only contains integers, too.
		$valid_user_ids = get_users( array( 'fields' => 'ID' ) );
		$valid_user_ids = array_map( 'intval', $valid_user_ids );

		// Return the intersection of the two arrays.
		return array_intersect( $user_ids, $valid_user_ids );
	}

	// ---------------------------------------------------------------------------------------------
	// Functions to get the current values of settings.

	/**
	 * Retrieves the value of the 'delete on uninstall' setting.
	 *
	 * @return bool The delete on uninstall setting.
	 */
	public static function get_delete_on_uninstall(): bool {
		return get_option( 'wp_logify_delete_on_uninstall', self::DEFAULT_DELETE_ON_UNINSTALL );
	}

	/**
	 * Retrieves the value of the 'roles to track' setting.
	 *
	 * @return array The roles to track.
	 */
	public static function get_roles_to_track(): array {
		return get_option( 'wp_logify_roles_to_track', self::get_roles() );
	}

	/**
	 * Retrieves the value of the 'roles with access' setting.
	 *
	 * @return array The roles with access to the log page.
	 */
	public static function get_roles_with_access(): array {
		return get_option( 'wp_logify_roles_with_access', self::DEFAULT_ROLES_WITH_ACCESS );
	}

	/**
	 * Retrieves the value of the 'users with access' setting.
	 *
	 * @return array The users with access to the log page.
	 */
	public static function get_users_with_access(): array {
		return get_option( 'wp_logify_users_with_access', self::DEFAULT_USERS_WITH_ACCESS );
	}

	/**
	 * Retrieves the value of the 'show in admin bar' setting.
	 *
	 * @return array If the WP Logify submenu should be shown in the admin bar.
	 */
	public static function get_show_in_admin_bar(): bool {
		return get_option( 'wp_logify_show_in_admin_bar', self::DEFAULT_SHOW_IN_ADMIN_BAR );
	}

	/**
	 * Retrieves the value of the 'keep period quantity' setting.
	 *
	 * @return int The keep period quantity setting.
	 */
	public static function get_keep_period_quantity(): int {
		return get_option( 'wp_logify_keep_period_quantity', self::DEFAULT_KEEP_PERIOD_QUANTITY );
	}

	/**
	 * Retrieves the keep period units setting for the WP Logify plugin.
	 *
	 * @return string The keep period units setting.
	 */
	public static function get_keep_period_units(): string {
		return get_option( 'wp_logify_keep_period_units', self::DEFAULT_KEEP_PERIOD_UNITS );
	}
}
