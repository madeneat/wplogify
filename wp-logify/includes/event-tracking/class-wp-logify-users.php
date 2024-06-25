<?php
/**
 * Class WP_Logify_Basic
 *
 * This class provides basic tracking functionalities for WordPress.
 * It tracks changes to posts and user logins.
 */
class WP_Logify_Users {

	/**
	 * Initializes the class by adding WordPress actions.
	 */
	public static function init() {
		add_action( 'wp_login', array( __CLASS__, 'track_login' ), 10, 2 );
		add_action( 'wp_logout', array( __CLASS__, 'track_logout' ), 10, 1 );
		add_action( 'wp_ajax_track_user_activity', array( __CLASS__, 'track_user_activity' ), 10, 0 );
		add_action( 'user_register', array( __CLASS__, 'track_user_registration' ), 10, 2 );
		add_action( 'delete_user', array( __CLASS__, 'track_user_deletion' ), 10, 3 );
		add_action( 'profile_update', array( __CLASS__, 'track_user_update' ), 10, 3 );
		add_action( 'update_user_meta', array( __CLASS__, 'track_user_meta_update' ), 10, 4 );
	}

	// #region Tracking functions

	/**
	 * Track user login.
	 *
	 * @param string  $user_login The username of the user that logged in.
	 * @param WP_User $user The WP_User object of the user that logged in.
	 */
	public static function track_login( string $user_login, WP_User $user ) {
		WP_Logify_Logger::log_event( 'User Login', 'user', $user->ID, self::get_user_name( $user ) );
	}

	/**
	 * Track user logout.
	 *
	 * @param int $user_id The ID of the user that logged out.
	 */
	public static function track_logout( int $user_id ) {
		WP_Logify_Logger::log_event( 'User Logout', 'user', $user_id, self::get_user_name( $user_id ) );
	}

	/**
	 * Track user registration.
	 *
	 * @param int   $user_id  The ID of the user that was registered.
	 * @param array $userdata The data for the user that was registered.
	 */
	public static function track_user_registration( int $user_id, array $userdata ) {
		// Get the user's details.
		$details = self::get_user_details( $user_id );

		WP_Logify_Logger::log_event( 'User Registered', 'user', $user_id, self::get_user_name( $user_id ), $details );
	}

	/**
	 * Track user deletion.
	 *
	 * @param int     $user_id  The ID of the user that was deleted.
	 * @param ?int    $reassign The ID of the user that the data was reassigned to.
	 * @param WP_User $user     The WP_User object of the user that was deleted.
	 */
	public static function track_user_deletion( int $user_id, ?int $reassign, WP_User $user ) {
		// Get the user's details.
		$details = self::get_user_details( $user );

		// If the user is being reassigned, log that information.
		if ( $reassign ) {
			$details = array( 'Data reassigned to' => self::get_user_profile_link( $reassign ) );
		}

		WP_Logify_Logger::log_event( 'User Deleted', 'user', $user_id, self::get_user_name( $user ), $details );
	}

	/**
	 * Track user update.
	 *
	 * @param int     $user_id       The ID of the user that was updated.
	 * @param WP_User $old_user_data The WP_User object of the user before the update.
	 * @param array   $userdata      The data for the user after the update.
	 */
	public static function track_user_update( int $user_id, WP_User $old_user_data, array $userdata ) {
		// debug_log( 'user_id', $user_id );
		// debug_log( 'old_user_data', $old_user_data );
		// debug_log( 'userdata', $userdata );

		// Record changes.
		global $user_changes;
		if ( ! isset( $user_changes ) ) {
			$user_changes = array();
		}

		// Compare values.
		foreach ( $old_user_data->data as $key => $value ) {
			// debug_log( 'old user data', $key, $value );

			$old_value = (string) $value;
			$new_value = (string) $userdata[ $key ];

			if ( $old_value !== $new_value ) {
				// debug_log( 'record change', $old_value, $new_value );
				$user_changes[ $key ] = array( $old_value, $new_value );
			}
		}

		if ( ! empty( $user_changes ) ) {
			WP_Logify_Logger::log_event( 'User Updated', 'user', $user_id, self::get_user_name( $user_id ), null, $user_changes );
		}
	}

	/**
	 * Track user activity.
	 *
	 * This function is called via AJAX to track user activity.
	 */
	public static function track_user_activity() {
		check_ajax_referer( 'wp_logify_activity_nonce', 'nonce' );

		global $wpdb;
		$user_id    = get_current_user_id();
		$table_name = WP_Logify_Logger::get_table_name();
		$event_type = 'User Session';

		// Get the current datetime.
		$now           = WP_Logify_DateTime::current_datetime();
		$formatted_now = WP_Logify_DateTime::format_datetime_mysql( $now );

		// Check if this is a continuing session.
		$continuing      = false;
		$sql             = $wpdb->prepare(
			'SELECT ID, details FROM %i WHERE user_id = %d AND event_type = %s ORDER BY date_time DESC',
			$table_name,
			$user_id,
			$event_type
		);
		$existing_record = $wpdb->get_row( $sql );
		if ( $existing_record && $existing_record->details !== null ) {

			// Extract the current session end datetime from the event details.
			$details                = json_decode( $existing_record->details, true );
			$session_start_datetime = WP_Logify_DateTime::create_datetime( $details['Session start'] );
			$session_end_datetime   = WP_Logify_DateTime::create_datetime( $details['Session end'] );

			// If the current value for session end time is less than 5 minutes (300 seconds) before
			// now, we'll say the session is continuing, and update the session end time to now.
			$seconds_diff = $now->getTimestamp() - $session_end_datetime->getTimestamp();
			if ( $seconds_diff <= 300 ) {
				$continuing = true;

				// Update the session end time and duration.
				$details['Session end']      = $formatted_now;
				$details['Session duration'] = WP_Logify_DateTime::get_duration_string( $session_start_datetime, $now );

				// Update the record.
				$wpdb->update(
					$table_name,
					array( 'details' => wp_json_encode( $details ) ),
					array( 'ID' => $existing_record->ID ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		// If we're not continuing an existing session, record the start of a new one.
		if ( ! $continuing ) {
			$details = array(
				'Session start'    => $formatted_now,
				'Session end'      => $formatted_now,
				'Session duration' => '0 minutes',
			);
			WP_Logify_Logger::log_event( $event_type, 'user', $user_id, self::get_user_name( $user_id ), $details );
		}

		wp_send_json_success();
	}

	/**
	 * Track user meta update.
	 *
	 * @param int    $meta_id   The ID of the meta data.
	 * @param int    $object_id The ID of the user.
	 * @param string $meta_key  The key of the meta data.
	 * @param mixed  $meta_value The new value of the meta data.
	 */
	public static function track_user_meta_update( int $meta_id, int $object_id, string $meta_key, mixed $meta_value ) {
		// Get the current value.
		$current_value = get_user_meta( $object_id, $meta_key, true );

		// Get the old and new values as strings for comparison and display.
		$old_value = self::value_to_string( $current_value );
		$new_value = self::value_to_string( $meta_value );

		// Track the change, if any.
		if ( $old_value !== $new_value ) {
			global $user_changes;
			if ( ! isset( $user_changes ) ) {
				$user_changes = array();
			}
			$user_changes[ $meta_key ] = array( $old_value, $new_value );
		}
	}

	// #endregion Tracking functions

	/**
	 * Convert a value to a string for comparison and display.
	 *
	 * @param mixed $value The value to convert.
	 * @return string The value as a string.
	 */
	private static function value_to_string( mixed $value ) {
		return is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
	}

	/**
	 * Get the details of a user to show in the log.
	 *
	 * @param int|WP_User $user The user ID or object.
	 * @return array The details of the user.
	 */
	private static function get_user_details( int|WP_User $user ): array {
		// Load the user if necessary.
		if ( is_int( $user ) ) {
			$user_id = $user;
			$user    = get_userdata( $user );
		} else {
			$user_id = $user->ID;
		}

		// Create the details array.
		$details = array(
			'User ID' => $user_id,
			'Profile' => self::get_user_profile_link( $user ),
			'Login'   => $user->user_login,
			'Email'   => $user->user_email,
			'Roles'   => is_array( $user->roles ) ? implode( ', ', $user->roles ) : $user->roles,
		);

		// Add the datetime the user was registered, if set.
		if ( $user->user_registered ) {
			$user_registered_datetime_utc  = WP_Logify_DateTime::create_datetime( $user->user_registered, 'UTC' );
			$user_registered_datetime_site = $user_registered_datetime_utc->setTimezone( wp_timezone() );
			$details['Registered']         = WP_Logify_DateTime::format_datetime_site( $user_registered_datetime_site );
		}

		return $details;
	}

	/**
	 * Retrieves a username for a given user.
	 *
	 * First preference is the display_name, second preference is the user_login, third preference
	 * is the user_nicename.
	 *
	 * @param int|WP_User $user The ID of the user, the user object, or a row from the users table.
	 * @return string The username if found, otherwise 'Unknown'.
	 */
	public static function get_user_name( int|object $user ) {
		// Load the user if necessary.
		if ( is_int( $user ) ) {
			$user = get_userdata( $user );
		}

		// First preference is the display name, which is their full name.
		if ( ! empty( $user->display_name ) ) {
			return $user->display_name;
		}

		// If that fails, use their login name.
		if ( ! empty( $user->user_login ) ) {
			return $user->user_login;
		}

		// If that fails, use the nice name.
		if ( ! empty( $user->user_nicename ) ) {
			return $user->user_nicename;
		}

		return 'Unknown';
	}

	/**
	 * Retrieves a link to the user's profile.
	 *
	 * @param int|object $user The ID of the user, the user object, or a row from the users table.
	 * @return ?string The link to the user's profile or null if the user wasn't found.
	 */
	public static function get_user_profile_link( int|object $user ): ?string {
		// Check for a valid parameter.
		if ( empty( $user ) ) {
			return 'Unknown';
		}

		// Load the user if necessary.
		if ( is_int( $user ) ) {
			$user = get_userdata( $user );
			if ( ! $user ) {
				return 'Unknown';
			}
		}

		// Construct the link.
		$user_profile_url  = site_url( "/?author={$user->ID}" );
		$user_display_name = self::get_user_name( $user );
		return "<a href='$user_profile_url' class='wp-logify-user-link'>$user_display_name</a>";
	}

	/**
	 * Retrieves the IP address of the user.
	 *
	 * @return ?string The IP address of the user or null if not found.
	 */
	public static function get_user_ip(): ?string {
		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			// Check for shared internet/ISP IP.
			$ip = wp_unslash( $_SERVER['HTTP_CLIENT_IP'] );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			// Check for IPs passing through proxies.
			// The value might be a comma-separated list of addresses.
			$ip = explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )[0];
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			// The remote address (the actual IP).
			$ip = wp_unslash( $_SERVER['REMOTE_ADDR'] );
		} else {
			$ip = null;
		}

		// Trim any whitespace.
		return $ip === null ? null : trim( $ip );
	}

	/**
	 * Retrieves the location of the user based on their IP address.
	 *
	 * @param string $ip The IP address of the user.
	 * @return ?string The location of the user or null if not found.
	 */
	public static function get_user_location( string $ip ): ?string {
		// Use a geolocation API to get location info from the IP address.
		$response = wp_remote_get( "http://ip-api.com/json/$ip" );
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Construct the location string.
		if ( $data['status'] === 'success' ) {
			$location = array(
				$data['city'],
				$data['regionName'],
				$data['country'],
			);
			return implode( ', ', array_filter( $location ) );
		}

		// Return null if the location could not be determined.
		return null;
	}

	/**
	 * Retrieves the user agent string from the server variables.
	 *
	 * @return ?string The user agent string or null if not found.
	 */
	public static function get_user_agent(): ?string {
		return isset( $_SERVER['HTTP_USER_AGENT'] )
			? trim( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: null;
	}

	/**
	 * Retrieves the last login datetime of a user.
	 *
	 * @param WP_User|int $user The user object or ID.
	 * @return ?DateTime The last login datetime of the user or null if not found.
	 */
	public static function get_last_login_datetime( WP_User|int $user ): ?DateTime {
		global $wpdb;

		// Load the user if necessary.
		if ( is_int( $user ) ) {
			$user = get_userdata( $user );
		}

		// Get the last login datetime from the wp_logify_events table.
		$table_name       = WP_Logify_Logger::get_table_name();
		$sql              = $wpdb->prepare(
			"SELECT * FROM %i WHERE user_id = %d AND event_type = 'User Login' ORDER BY date_time DESC LIMIT 1",
			$table_name,
			$user->ID
		);
		$last_login_event = $wpdb->get_row( $sql );
		return $last_login_event === null ? null : WP_Logify_DateTime::create_datetime( $last_login_event->date_time );
	}

	/**
	 * Retrieves the last active datetime of a user.
	 *
	 * @param WP_User|int $user The user object or ID.
	 * @return ?DateTime The last active datetime of the user or null if not found.
	 */
	public static function get_last_active_datetime( WP_User|int $user ): ?DateTime {
		global $wpdb;

		// Load the user if necessary.
		if ( is_int( $user ) ) {
			$user = get_userdata( $user );
		}

		// Get the most recent session end datetime from the wp_logify_events table.
		$table_name         = WP_Logify_Logger::get_table_name();
		$sql                = $wpdb->prepare(
			"SELECT * FROM %i WHERE user_id = %d AND event_type = 'User Session' ORDER BY date_time DESC LIMIT 1",
			$table_name,
			$user->ID
		);
		$last_session_event = $wpdb->get_row( $sql );
		if ( $last_session_event !== null && $last_session_event->details !== null ) {
			$details = json_decode( $last_session_event->details, true );
			return WP_Logify_DateTime::create_datetime( $details['Session end'] );
		}

		return null;
	}
}
